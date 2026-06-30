#!/usr/bin/env php
<?php
/**
 * Drive Pending NAS Migration Worker.
 *
 * Background: when a Drive upload happens while the NAS is unreachable,
 * DriveService writes the bytes to the VPS local fallback and inserts a
 * row into `drive_pending_nas_migration` (status='pending'). The file
 * works fine - it's served from local disk - but it's NOT on the NAS,
 * which breaks the "NAS-primary" promise and means the file is missing
 * from the off-site copy.
 *
 * This cron picks up pending rows when the NAS is healthy again and
 * moves them to their intended NAS location. Steps per row:
 *
 *   1. Mark row 'migrating' (so a second concurrent runner skips it).
 *   2. Copy local -> NAS target path.
 *   3. Verify size + crc32b checksum.
 *   4. UPDATE drive_files: storage_location='nfs', nas_relative_path set.
 *   5. Optionally unlink the local copy (off by default; keep as shadow).
 *   6. Mark row 'completed' with migrated_at=NOW().
 *
 * Failure handling per row:
 *   - Any error reverts the row to 'pending' with attempts++ and the
 *     error_message field populated. Rows past --max-attempts (default 5)
 *     are marked 'failed' and require operator intervention.
 *   - A failure on row N never blocks row N+1.
 *
 * Safety:
 *   - Refuses to run unless NasHealthCheck reports the NAS available.
 *   - Bounded by --batch (default 25) and --max-seconds (default 240).
 *   - Default mode on TTY is --dry-run. Cron must pass --apply.
 *   - Locked via flock from the crontab command (NOT in PHP - the cron
 *     wrapper is the source of truth for serialization).
 *
 * Crontab (runs every 5 minutes; flock prevents overlap):
 *   *\/5 * * * * /usr/bin/flock -n /var/lock/flowone-drive-pending-nas-migrate.lock \
 *      /usr/local/lsws/lsphp83/bin/php \
 *      /var/www/vps-email/backend/cron/drive-pending-nas-migrate.php --apply \
 *      >> /var/log/flowone/drive-pending-nas-migrate.log 2>&1
 *
 * CLI Flags:
 *   --apply              perform migrations
 *   --dry-run            list candidate rows without copying
 *   --batch=N            max rows per pass (default 25)
 *   --max-seconds=N      wall-clock cap (default 240)
 *   --max-attempts=N     give up on a row after N attempts (default 5)
 *   --keep-local         do not delete the local copy after migration
 *                        (default behavior - keeps shadow for safety)
 *   --delete-local       delete the local copy after verified NAS copy
 *   --verbose            extra debug output
 *   --json               machine-readable summary
 *   --help               this help
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-pending-nas-migrate must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Core\Database;
use Webmail\Services\NasHealthCheck;

$opts = parseOpts($argv);
if ($opts['help']) {
    printHelp();
    exit(0);
}

// Safety: default to --dry-run on TTY when neither flag is given.
if (!$opts['apply'] && !$opts['dry_run']) {
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        fwrite(STDERR, "[safety] no --apply or --dry-run; defaulting to --dry-run\n");
        $opts['dry_run'] = true;
    } else {
        fwrite(STDERR, "must specify --apply or --dry-run\n");
        exit(2);
    }
}

$appConfig = require __DIR__ . '/../src/config.php';
$pdo = Database::getConnection($appConfig);

// Surface the runtime knobs that helper functions (markFailed, finalizeRow)
// need but which can't be cleanly threaded through their signatures.
$GLOBALS['__pending_nas_migrate_max_attempts'] = $opts['max_attempts'];
$GLOBALS['__pending_nas_migrate_drive_base'] = rtrim((string) ($appConfig['drive']['storage_path'] ?? ''), '/');

// Preflight: NAS must be healthy. If not, exit cleanly so the next run
// retries - this is expected during outages and is not an error.
if (!NasHealthCheck::isAvailable()) {
    fwrite(STDERR, "[preflight] NAS reported unavailable; nothing to do this pass\n");
    if ($opts['json']) {
        echo json_encode(['status' => 'skipped', 'reason' => 'nas_unavailable']) . "\n";
    }
    exit(0);
}

// Preflight: the pending table must exist (migration 031 must have run).
try {
    $check = $pdo->query("SHOW TABLES LIKE 'drive_pending_nas_migration'");
    if ($check->fetch() === false) {
        fwrite(STDERR, "[preflight] drive_pending_nas_migration table missing; run migration 031 first\n");
        exit(3);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[preflight] schema check failed: " . $e->getMessage() . "\n");
    exit(3);
}

$startUnix = time();
$startMs = (int) (microtime(true) * 1000);
$deadlineMs = $startMs + $opts['max_seconds'] * 1000;

$summary = [
    'mode'           => $opts['dry_run'] ? 'dry-run' : 'apply',
    'candidates'     => 0,
    'attempted'      => 0,
    'migrated'       => 0,
    'failed'         => 0,
    'skipped_missing'=> 0,
    'skipped_exists' => 0,
    'gave_up'        => 0,
    'bytes_total'    => 0,
    'elapsed_ms'     => 0,
    'rows'           => [],
];

// Fetch candidate rows. We pull rows in status='pending' that have not
// blown past --max-attempts. ORDER BY id ASC so the oldest pending file
// gets the first attempt - simple FIFO is good enough; if a row genuinely
// can't migrate it gets retired to 'failed' and stops blocking the queue.
// version_id (migration 190) marks rows whose bytes were archived as a
// file VERSION after being queued; those stamp drive_file_versions
// instead of drive_files on completion. COALESCE keeps the query working
// on installs that have not run migration 190 yet.
$hasVersionId = false;
try {
    $hasVersionId = $pdo->query("SHOW COLUMNS FROM drive_pending_nas_migration LIKE 'version_id'")->fetch() !== false;
} catch (\Throwable $e) {
    // treat as absent
}
$GLOBALS['__pending_nas_migrate_has_version_id'] = $hasVersionId;
$versionIdSelect = $hasVersionId ? 'pm.version_id,' : 'NULL AS version_id,';

$stmt = $pdo->prepare(
    "SELECT pm.id, pm.file_id, {$versionIdSelect} pm.local_path, pm.nas_target_path, pm.user_email,
            pm.attempts, df.size AS expected_size
       FROM drive_pending_nas_migration pm
       LEFT JOIN drive_files df ON df.id = pm.file_id
      WHERE pm.status = 'pending'
        AND pm.attempts < :max_attempts
      ORDER BY pm.id ASC
      LIMIT :batch"
);
$stmt->bindValue(':max_attempts', $opts['max_attempts'], \PDO::PARAM_INT);
$stmt->bindValue(':batch', $opts['batch'], \PDO::PARAM_INT);
$stmt->execute();
$candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
$summary['candidates'] = count($candidates);

foreach ($candidates as $row) {
    if ((int) (microtime(true) * 1000) >= $deadlineMs) {
        fwrite(STDERR, "[deadline] --max-seconds reached, stopping\n");
        break;
    }

    $rowResult = migrateRow($pdo, $row, $opts);
    $summary['attempted']++;
    $summary['rows'][] = $rowResult;

    switch ($rowResult['outcome']) {
        case 'migrated':
            $summary['migrated']++;
            $summary['bytes_total'] += $rowResult['bytes'] ?? 0;
            break;
        case 'failed':
            $summary['failed']++;
            break;
        case 'gave_up':
            $summary['gave_up']++;
            break;
        case 'skipped_missing':
            $summary['skipped_missing']++;
            break;
        case 'skipped_exists':
            $summary['skipped_exists']++;
            break;
    }
}

$summary['elapsed_ms'] = (int) (microtime(true) * 1000) - $startMs;
$summary['started_at'] = date('c', $startUnix);

if ($opts['json']) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    renderSummary($summary, $opts);
}

// Exit 0 even when individual rows failed - a single bad row should not
// take the cron entry to a failed state. Operator visibility comes from
// the 'failed' column in summary + the row-level logs.
exit(0);

// ────────────────────────────────────────────────────────────────────────
// Row migration
// ────────────────────────────────────────────────────────────────────────

/**
 * Migrate a single pending row from local to NAS. All errors are caught
 * and surface as a structured row result; this function never throws.
 *
 * @return array{outcome: string, pm_id: int, file_id: int, bytes?: int, error?: string}
 */
function migrateRow(\PDO $pdo, array $row, array $opts): array
{
    $pmId      = (int) $row['id'];
    $fileId    = (int) $row['file_id'];
    $versionId = isset($row['version_id']) && $row['version_id'] !== null ? (int) $row['version_id'] : null;
    $local     = (string) $row['local_path'];
    $nas       = (string) $row['nas_target_path'];
    $email     = (string) $row['user_email'];
    $attempt   = ((int) $row['attempts']) + 1;

    // For version rows the parent file's size is meaningless (it reflects
    // the CURRENT content); compare against the version row instead.
    $expectedSize = $row['expected_size'] !== null ? (int) $row['expected_size'] : null;
    if ($versionId !== null) {
        $expectedSize = null;
        try {
            $vs = $pdo->prepare('SELECT size FROM drive_file_versions WHERE id = ?');
            $vs->execute([$versionId]);
            $vrow = $vs->fetch();
            if ($vrow !== false) {
                $expectedSize = (int) $vrow['size'];
            }
        } catch (\Throwable $e) {
            // fall through with null - size check is best-effort
        }
    }

    $base = ['pm_id' => $pmId, 'file_id' => $fileId];

    if ($opts['verbose']) {
        echo "[row {$pmId}] file_id={$fileId} attempt={$attempt} local={$local} -> nas={$nas}\n";
    }

    // Source must exist on local disk. If it does not, the row references
    // a vanished file - mark gave_up so a human can investigate.
    if (!is_file($local)) {
        if (!$opts['dry_run']) {
            markFailed($pdo, $pmId, $attempt, 'Local file missing: ' . $local);
        }
        return $base + ['outcome' => 'skipped_missing', 'error' => 'Local file missing'];
    }

    $localSize = filesize($local);
    if ($localSize === false || $localSize === 0) {
        if (!$opts['dry_run']) {
            markFailed($pdo, $pmId, $attempt, 'Local file empty or unreadable');
        }
        return $base + ['outcome' => 'failed', 'error' => 'Local file empty/unreadable'];
    }

    // If a file already exists at the NAS target with the same size, the
    // previous attempt probably succeeded but failed to mark the row.
    // Treat it as migrated rather than risk overwriting a good copy.
    if (is_file($nas)) {
        clearstatcache(true, $nas);
        $existingSize = filesize($nas);
        if ($existingSize !== false && $existingSize === $localSize) {
            if (!$opts['dry_run']) {
                finalizeRow($pdo, $pmId, $fileId, $email, $nas, $localSize, $opts, $local, $versionId);
            }
            return $base + ['outcome' => 'skipped_exists', 'bytes' => $localSize];
        }
        // Size mismatch -> previous copy was partial. Remove it before retrying.
        if (!$opts['dry_run']) {
            @unlink($nas);
        }
    }

    if ($opts['dry_run']) {
        return $base + ['outcome' => 'migrated', 'bytes' => $localSize, 'dry_run' => true];
    }

    // Mark in-flight so a parallel runner (e.g. an over-eager manual
    // invocation while cron is also active) skips this row.
    try {
        $pdo->prepare(
            "UPDATE drive_pending_nas_migration
                SET status='migrating', attempts=:attempts, last_attempt_at=NOW(),
                    error_message=NULL
              WHERE id=:id AND status='pending'"
        )->execute([':attempts' => $attempt, ':id' => $pmId]);
    } catch (\Throwable $e) {
        return $base + ['outcome' => 'failed', 'error' => 'mark migrating: ' . $e->getMessage()];
    }

    // Compute source checksum BEFORE copy so a partial NFS write is detected.
    $localSum = @hash_file('crc32b', $local);
    if ($localSum === false) {
        markFailed($pdo, $pmId, $attempt, 'Could not checksum local file');
        return $base + ['outcome' => 'failed', 'error' => 'checksum local failed'];
    }

    // Ensure the parent dir exists on NAS.
    $nasDir = dirname($nas);
    if (!is_dir($nasDir)) {
        if (!@mkdir($nasDir, 0755, true) && !is_dir($nasDir)) {
            markFailed($pdo, $pmId, $attempt, 'Could not create NAS dir: ' . $nasDir);
            return $base + ['outcome' => 'failed', 'error' => 'mkdir nas dir failed'];
        }
    }

    if (!@copy($local, $nas)) {
        $err = error_get_last()['message'] ?? 'unknown copy() failure';
        @unlink($nas);
        markFailed($pdo, $pmId, $attempt, 'copy() failed: ' . $err);
        return $base + ['outcome' => 'failed', 'error' => 'copy: ' . $err];
    }

    clearstatcache(true, $nas);
    $nasSize = @filesize($nas);
    if ($nasSize === false || $nasSize !== $localSize) {
        @unlink($nas);
        markFailed($pdo, $pmId, $attempt, "Size mismatch after copy (local={$localSize}, nas=" . var_export($nasSize, true) . ')');
        return $base + ['outcome' => 'failed', 'error' => 'size mismatch'];
    }

    $nasSum = @hash_file('crc32b', $nas);
    if ($nasSum === false || $nasSum !== $localSum) {
        @unlink($nas);
        markFailed($pdo, $pmId, $attempt, "Checksum mismatch after copy (local={$localSum}, nas=" . var_export($nasSum, true) . ')');
        return $base + ['outcome' => 'failed', 'error' => 'checksum mismatch'];
    }

    // Optional: if drive_files.size disagrees with what we just copied,
    // that's a sign the row was corrupted; refuse to mark migrated.
    if ($expectedSize !== null && $expectedSize !== $localSize) {
        @unlink($nas);
        markFailed($pdo, $pmId, $attempt, "drive_files.size ({$expectedSize}) != actual local size ({$localSize})");
        return $base + ['outcome' => 'failed', 'error' => 'size disagreement with drive_files'];
    }

    // Commit: update drive_files (or the version row), mark pending row
    // completed, optionally unlink local copy.
    try {
        finalizeRow($pdo, $pmId, $fileId, $email, $nas, $localSize, $opts, $local, $versionId);
    } catch (\Throwable $e) {
        markFailed($pdo, $pmId, $attempt, 'finalize: ' . $e->getMessage());
        return $base + ['outcome' => 'failed', 'error' => 'finalize: ' . $e->getMessage()];
    }

    return $base + ['outcome' => 'migrated', 'bytes' => $localSize];
}

/**
 * Commit a successful migration: flip drive_files (or, for re-pointed
 * version rows, drive_file_versions) to NAS, mark the pending row
 * completed, and optionally delete the local shadow.
 */
function finalizeRow(
    \PDO $pdo,
    int $pmId,
    int $fileId,
    string $email,
    string $nasTargetPath,
    int $bytes,
    array $opts,
    string $localPath,
    ?int $versionId = null
): void {
    $pdo->beginTransaction();
    try {
        if ($versionId !== null) {
            // Bytes belong to an archived version now - never touch the
            // parent file's tier (its current content lives elsewhere).
            $pdo->prepare(
                "UPDATE drive_file_versions
                    SET storage_location = 'nfs'
                  WHERE id = :id"
            )->execute([':id' => $versionId]);
        } else {
            // Compute the NAS-relative path. We strip the configured drive
            // base from the absolute NAS path so the column stays portable
            // across mount-point renames.
            $base = (string) ($GLOBALS['__pending_nas_migrate_drive_base'] ?? '');
            $relPath = ($base !== '' && str_starts_with($nasTargetPath, $base . '/'))
                ? substr($nasTargetPath, strlen($base) + 1)
                : $nasTargetPath;

            $pdo->prepare(
                "UPDATE drive_files
                    SET storage_location = 'nfs',
                        nas_relative_path = :rel,
                        updated_at = NOW()
                  WHERE id = :id"
            )->execute([':rel' => $relPath, ':id' => $fileId]);
        }

        $pdo->prepare(
            "UPDATE drive_pending_nas_migration
                SET status = 'completed',
                    migrated_at = NOW(),
                    error_message = NULL
              WHERE id = :id"
        )->execute([':id' => $pmId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // After commit, optionally delete the local shadow. We keep it by
    // default because the destructive sweep is a separate, deliberate
    // operation. If --delete-local was passed, remove it now.
    if ($opts['delete_local']) {
        @unlink($localPath);
    }
}

/**
 * Record a failed attempt. If we've hit --max-attempts, set status='failed'
 * so the row stops being picked up and a human can investigate.
 *
 * Takes maxAttempts from the runtime registry (set in main) rather than via
 * global so the function signature is honest about its dependencies.
 */
function markFailed(\PDO $pdo, int $pmId, int $attempts, string $message): void
{
    $maxAttempts = $GLOBALS['__pending_nas_migrate_max_attempts'] ?? 5;
    try {
        if ($attempts >= $maxAttempts) {
            $pdo->prepare(
                "UPDATE drive_pending_nas_migration
                    SET status='failed', attempts=:a, last_attempt_at=NOW(),
                        error_message=:m
                  WHERE id=:id"
            )->execute([':a' => $attempts, ':m' => substr($message, 0, 1024), ':id' => $pmId]);
        } else {
            $pdo->prepare(
                "UPDATE drive_pending_nas_migration
                    SET status='pending', attempts=:a, last_attempt_at=NOW(),
                        error_message=:m
                  WHERE id=:id"
            )->execute([':a' => $attempts, ':m' => substr($message, 0, 1024), ':id' => $pmId]);
        }
    } catch (\Throwable $e) {
        error_log('[drive-pending-nas-migrate] markFailed failed: ' . $e->getMessage());
    }
}

// ────────────────────────────────────────────────────────────────────────
// CLI plumbing
// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = [
        'help'         => false,
        'apply'        => false,
        'dry_run'      => false,
        'verbose'      => false,
        'json'         => false,
        'batch'        => 25,
        'max_seconds'  => 240,
        'max_attempts' => 5,
        'delete_local' => false,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $opts['help'] = true; continue; }
        if ($arg === '--apply') { $opts['apply'] = true; continue; }
        if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
        if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
        if ($arg === '--json') { $opts['json'] = true; continue; }
        if ($arg === '--keep-local') { $opts['delete_local'] = false; continue; }
        if ($arg === '--delete-local') { $opts['delete_local'] = true; continue; }
        if (str_starts_with($arg, '--batch=')) {
            $opts['batch'] = max(1, (int) substr($arg, strlen('--batch=')));
            continue;
        }
        if (str_starts_with($arg, '--max-seconds=')) {
            $opts['max_seconds'] = max(10, (int) substr($arg, strlen('--max-seconds=')));
            continue;
        }
        if (str_starts_with($arg, '--max-attempts=')) {
            $opts['max_attempts'] = max(1, (int) substr($arg, strlen('--max-attempts=')));
            continue;
        }
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
Drive Pending NAS Migration Worker

Usage:
  drive-pending-nas-migrate.php --apply              perform migrations
  drive-pending-nas-migrate.php --dry-run            list candidate rows
  drive-pending-nas-migrate.php --batch=N            rows per pass (default 25)
  drive-pending-nas-migrate.php --max-seconds=N      wall-clock cap (default 240)
  drive-pending-nas-migrate.php --max-attempts=N     give up after N tries (default 5)
  drive-pending-nas-migrate.php --keep-local         keep VPS shadow (default)
  drive-pending-nas-migrate.php --delete-local       delete VPS shadow after migration
  drive-pending-nas-migrate.php --verbose            extra debug output
  drive-pending-nas-migrate.php --json               machine-readable output

Picks up drive_pending_nas_migration rows (queued by DriveService when
the NAS was down during upload) and copies them to the NAS once the
mount is healthy again. Verified via crc32b + size before the row is
marked completed.

TXT;
}

function renderSummary(array $s, array $opts): void
{
    $mode = $s['mode'];
    echo "[DRIVE-PENDING-NAS-MIGRATE] mode={$mode} elapsed_ms={$s['elapsed_ms']}\n";
    echo "[DRIVE-PENDING-NAS-MIGRATE] candidates={$s['candidates']} attempted={$s['attempted']} "
        . "migrated={$s['migrated']} failed={$s['failed']} gave_up={$s['gave_up']} "
        . "skipped_missing={$s['skipped_missing']} skipped_exists={$s['skipped_exists']} "
        . "bytes={$s['bytes_total']}\n";
    if ($opts['verbose']) {
        foreach ($s['rows'] as $r) {
            $err = isset($r['error']) ? " error=\"{$r['error']}\"" : '';
            echo "  pm_id={$r['pm_id']} file_id={$r['file_id']} outcome={$r['outcome']}{$err}\n";
        }
    }
}
