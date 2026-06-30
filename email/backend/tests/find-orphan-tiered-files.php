#!/usr/bin/env php
<?php
/**
 * find-orphan-tiered-files - diagnostic for non-hot rows in drive_files.
 *
 * READ-ONLY by default. Walks every row where tier_state != 'hot' and
 * classifies it by which physical copy of the bytes actually exists:
 *
 *   RECOVERABLE_HOT     VPS copy present -> row can be flipped to 'hot' safely
 *   NAS_ONLY            VPS copy missing, NAS copy present (needs NAS mounted)
 *   ORPHAN_NO_BYTES     Neither copy present (data loss; needs investigation)
 *   TRASHED             Row is_trashed=1; bytes may already be gone, irrelevant
 *   NAS_UNAVAILABLE     NAS mount missing so cold path cannot be probed at all
 *
 * For each row the script prints id, original_name, size, tier_state,
 * tier_changed_at, the VPS path it checked, the NAS path it checked,
 * and the disposition.
 *
 * Two optional write modes (off by default, gated behind explicit flag
 * + --yes confirmation + audit row insertion into drive_tier_transitions):
 *
 *   --rehot-recoverable    For every RECOVERABLE_HOT row: set tier_state='hot'
 *                          (only when VPS bytes are confirmed present).
 *   --mark-lost            For every ORPHAN_NO_BYTES row that is NOT
 *                          is_trashed: set tier_state='lost' so the user-facing
 *                          UI shows the lost-bytes icon instead of pretending
 *                          a download will work.
 *
 * Safety:
 *   - Default mode is read-only; no rows touched unless --rehot-recoverable
 *     or --mark-lost is passed AND --yes is also passed.
 *   - Every classification + every write is logged to a timestamped log
 *     in storage/logs/.
 *   - Cleanup runs on SIGINT/SIGTERM (no destructive ops in progress).
 *   - Idempotent: re-running after --rehot-recoverable will report 0 such
 *     rows because their tier_state is now 'hot' (excluded by WHERE).
 *
 * CLI:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/find-orphan-tiered-files.php
 *
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/find-orphan-tiered-files.php \
 *     --verbose --json
 *
 *   # repair (requires --yes):
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/find-orphan-tiered-files.php \
 *     --rehot-recoverable --yes
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "find-orphan-tiered-files must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;

const SCRIPT_NAME    = 'find-orphan-tiered-files';
const HARD_TIMEOUT_S = 60;
const NAS_MOUNT      = '/mnt/nas-drive';

// --- Options ---------------------------------------------------------
$opts = parseOpts($argv);
if (!empty($opts['help'])) { printHelp(); exit(0); }

$startedAt = microtime(true);
set_time_limit(HARD_TIMEOUT_S + 10);

// --- Logging ---------------------------------------------------------
$logDir  = __DIR__ . '/../../storage/logs';
@mkdir($logDir, 0755, true);
$logFile = $logDir . '/' . SCRIPT_NAME . '-' . date('Ymd-His') . '.log';
$log = function (string $level, string $msg) use ($logFile): void {
    $line = sprintf("[%s] [%-4s] %s\n", date('H:i:s'), $level, $msg);
    @file_put_contents($logFile, $line, FILE_APPEND);
};

// --- Cleanup / signal handlers ---------------------------------------
$cleanup = function () use ($log, $opts): void {
    if (empty($opts['json'])) {
        echo color("\n[cleanup] script finished\n", 'dim');
    }
    $log('INFO', 'script finished');
};
register_shutdown_function($cleanup);
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $sigHandler = function (int $s) use ($log): void {
        fwrite(STDERR, "\n[signal] {$s} - aborting (no destructive ops in flight)\n");
        $log('WARN', "signal {$s} received, aborting");
        exit(130);
    };
    pcntl_signal(SIGINT,  $sigHandler);
    pcntl_signal(SIGTERM, $sigHandler);
}

// --- Pre-flight ------------------------------------------------------
section('PRE-FLIGHT', $opts);
$config = require __DIR__ . '/../src/config.php';

try {
    $pdo = Database::getConnection($config);
    line('+', 'database connection ok', $opts);
    $log('PASS', 'database connection');
} catch (\Throwable $e) {
    line('x', 'database connection failed: ' . $e->getMessage(), $opts);
    $log('FAIL', 'database connection: ' . $e->getMessage());
    exit(2);
}

$haveTierState = columnExists($pdo, 'drive_files', 'tier_state');
if (!$haveTierState) {
    line('x', 'drive_files.tier_state column missing - run migration 167 first', $opts);
    $log('FAIL', 'tier_state column missing');
    exit(2);
}
line('+', 'drive_files.tier_state present', $opts);

$vpsRoot = (string) ($config['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive');
$vpsRoot = rtrim($vpsRoot, '/');
if (!is_dir($vpsRoot)) {
    line('!', "VPS drive root not a directory: {$vpsRoot} (continuing anyway, every row will report missing)", $opts);
    $log('WARN', "VPS root missing: {$vpsRoot}");
} else {
    line('+', "VPS drive root: {$vpsRoot}", $opts);
}

$nasMounted = isNasMounted(NAS_MOUNT);
if ($nasMounted) {
    line('+', 'NAS mount detected at ' . NAS_MOUNT, $opts);
    $log('PASS', 'NAS mount detected');
} else {
    line('!', 'NAS NOT mounted at ' . NAS_MOUNT . ' - cold-path checks will report NAS_UNAVAILABLE', $opts);
    $log('WARN', 'NAS not mounted');
}

$wantRehot      = !empty($opts['rehot-recoverable']);
$wantMarkLost   = !empty($opts['mark-lost']);
$wantWrite      = $wantRehot || $wantMarkLost;
if ($wantWrite && empty($opts['yes'])) {
    line('x', 'Write mode requested but --yes not provided. Refusing to mutate rows.', $opts);
    exit(2);
}
if ($wantWrite) {
    line('!', 'WRITE MODE ENABLED: rehot=' . ($wantRehot ? 'yes' : 'no')
            . ', mark-lost=' . ($wantMarkLost ? 'yes' : 'no'), $opts);
    $log('WARN', 'write mode enabled');
}

// --- Query non-hot rows ----------------------------------------------
section('SCAN', $opts);
$stmt = $pdo->prepare(<<<SQL
    SELECT id, user_email, folder_id, filename, original_name, size, mime_type,
           is_trashed, trashed_at, storage_location, tier_state,
           tier_changed_at, tier_changed_by, tier_recall_attempts,
           created_at, updated_at
    FROM drive_files
    WHERE tier_state != 'hot'
    ORDER BY tier_state, id
SQL);
$stmt->execute();
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($rows)) {
    line('+', 'No non-hot rows found. Database is clean.', $opts);
    $log('INFO', 'no non-hot rows');
    if (!empty($opts['json'])) {
        echo json_encode(['ok' => true, 'rows' => [], 'summary' => ['total' => 0]],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }
    exit(0);
}

line('+', count($rows) . ' non-hot row(s) found', $opts);
$log('INFO', count($rows) . ' rows');

// --- Smoke mode: count by tier_state, skip per-row checks ------------
if (!empty($opts['smoke'])) {
    $counts = [];
    foreach ($rows as $r) {
        $counts[$r['tier_state']] = ($counts[$r['tier_state']] ?? 0) + 1;
    }
    if (!empty($opts['json'])) {
        echo json_encode(['ok' => true, 'mode' => 'smoke', 'counts' => $counts],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    } else {
        section('SMOKE COUNTS BY TIER_STATE', $opts);
        foreach ($counts as $k => $v) line('.', sprintf('%-12s %d', $k, $v), $opts);
    }
    exit(0);
}

// --- Classify each row -----------------------------------------------
$classified = [];
$summary = [
    'total'             => count($rows),
    'recoverable_hot'   => 0,
    'nas_only'          => 0,
    'orphan_no_bytes'   => 0,
    'trashed'           => 0,
    'nas_unavailable'   => 0,
];

foreach ($rows as $r) {
    $userHash = md5(strtolower((string) $r['user_email']));
    $vpsPath  = $vpsRoot . '/' . $userHash . '/' . $r['filename'];
    $nasPath  = NAS_MOUNT . '/' . $userHash . '/' . $r['filename'];

    $vpsExists = @is_file($vpsPath);
    $vpsSize   = $vpsExists ? @filesize($vpsPath) : null;

    if ($nasMounted) {
        $nasExists = @is_file($nasPath);
        $nasSize   = $nasExists ? @filesize($nasPath) : null;
    } else {
        $nasExists = null;
        $nasSize   = null;
    }

    $disposition = classify((bool) $r['is_trashed'], $vpsExists, $nasExists, $nasMounted);
    $summary[strtolower($disposition)]++;

    $classified[] = [
        'id'             => (int) $r['id'],
        'user_email'     => (string) $r['user_email'],
        'original_name'  => (string) $r['original_name'],
        'size'           => (int) $r['size'],
        'tier_state'     => (string) $r['tier_state'],
        'tier_changed_at'=> $r['tier_changed_at'],
        'tier_changed_by'=> $r['tier_changed_by'],
        'is_trashed'     => (bool) $r['is_trashed'],
        'storage_location' => $r['storage_location'],
        'vps_path'       => $vpsPath,
        'vps_exists'     => $vpsExists,
        'vps_size'       => $vpsSize,
        'nas_path'       => $nasPath,
        'nas_exists'     => $nasExists,
        'nas_size'       => $nasSize,
        'disposition'    => $disposition,
        'size_match'     => $vpsExists && $vpsSize !== null
                                ? ((int) $vpsSize === (int) $r['size']) : null,
    ];
}

// --- Render ----------------------------------------------------------
if (!empty($opts['json'])) {
    echo json_encode([
        'ok'         => true,
        'summary'    => $summary,
        'rows'       => $classified,
        'nas_mounted'=> $nasMounted,
        'log_file'   => $logFile,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} else {
    section('CLASSIFICATION', $opts);
    foreach ($classified as $row) {
        printRow($row, $opts);
        $log('INFO', sprintf("id=%d disposition=%s vps=%s nas=%s name=%s",
            $row['id'],
            $row['disposition'],
            $row['vps_exists'] ? 'yes' : 'no',
            $row['nas_exists'] === null ? 'unknown' : ($row['nas_exists'] ? 'yes' : 'no'),
            $row['original_name']
        ));
    }
    section('SUMMARY', $opts);
    foreach ($summary as $k => $v) {
        line('.', sprintf('%-22s %d', $k, $v), $opts);
    }
    line('.', 'log: ' . $logFile, $opts);
}

// --- Optional write ops ----------------------------------------------
if (!$wantWrite) {
    exit(0);
}

section('WRITE OPS', $opts);

$rehotCount = 0;
$lostCount  = 0;

try {
    $pdo->beginTransaction();

    if ($wantRehot) {
        $upd = $pdo->prepare(<<<SQL
            UPDATE drive_files
            SET tier_state = 'hot',
                tier_changed_at = CURRENT_TIMESTAMP,
                tier_changed_by = :actor,
                storage_location = 'local'
            WHERE id = :id AND tier_state != 'hot'
SQL);
        $audit = $pdo->prepare(<<<SQL
            INSERT INTO drive_tier_transitions
                (file_id, from_state, to_state, actor, reason, bytes)
            VALUES
                (:fid, :from, 'hot', :actor, :reason, :bytes)
SQL);
        foreach ($classified as $row) {
            if ($row['disposition'] !== 'RECOVERABLE_HOT') continue;
            $upd->execute([
                'id'    => $row['id'],
                'actor' => SCRIPT_NAME,
            ]);
            $audit->execute([
                'fid'    => $row['id'],
                'from'   => $row['tier_state'],
                'actor'  => SCRIPT_NAME,
                'reason' => 'diagnostic-found-vps-bytes-still-present',
                'bytes'  => $row['size'],
            ]);
            $rehotCount++;
            $log('WRITE', "rehotted id={$row['id']} (was {$row['tier_state']})");
            line('+', "rehotted id={$row['id']} name=" . $row['original_name'], $opts);
        }
    }

    if ($wantMarkLost) {
        $upd = $pdo->prepare(<<<SQL
            UPDATE drive_files
            SET tier_state = 'lost',
                tier_changed_at = CURRENT_TIMESTAMP,
                tier_changed_by = :actor
            WHERE id = :id AND tier_state != 'lost'
SQL);
        $audit = $pdo->prepare(<<<SQL
            INSERT INTO drive_tier_transitions
                (file_id, from_state, to_state, actor, reason, bytes)
            VALUES
                (:fid, :from, 'lost', :actor, :reason, :bytes)
SQL);
        foreach ($classified as $row) {
            if ($row['disposition'] !== 'ORPHAN_NO_BYTES') continue;
            if ($row['is_trashed']) continue;
            $upd->execute([
                'id'    => $row['id'],
                'actor' => SCRIPT_NAME,
            ]);
            $audit->execute([
                'fid'    => $row['id'],
                'from'   => $row['tier_state'],
                'actor'  => SCRIPT_NAME,
                'reason' => 'diagnostic-no-bytes-found-anywhere',
                'bytes'  => $row['size'],
            ]);
            $lostCount++;
            $log('WRITE', "marked-lost id={$row['id']} (was {$row['tier_state']})");
            line('+', "marked-lost id={$row['id']} name=" . $row['original_name'], $opts);
        }
    }

    $pdo->commit();
    line('+', "Wrote {$rehotCount} rehot + {$lostCount} lost rows", $opts);
    $log('PASS', "Wrote {$rehotCount} rehot + {$lostCount} lost rows");
} catch (\Throwable $e) {
    $pdo->rollBack();
    line('x', 'write failed (rolled back): ' . $e->getMessage(), $opts);
    $log('FAIL', 'write failed: ' . $e->getMessage());
    exit(1);
}

exit(0);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function parseOpts(array $argv): array
{
    $opts = [
        'help'              => false,
        'verbose'           => false,
        'json'              => false,
        'smoke'             => false,
        'rehot-recoverable' => false,
        'mark-lost'         => false,
        'yes'               => false,
    ];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--help' || $a === '-h')   { $opts['help']    = true; continue; }
        if ($a === '--verbose')               { $opts['verbose'] = true; continue; }
        if ($a === '--json')                  { $opts['json']    = true; continue; }
        if ($a === '--smoke')                 { $opts['smoke']   = true; continue; }
        if ($a === '--rehot-recoverable')     { $opts['rehot-recoverable'] = true; continue; }
        if ($a === '--mark-lost')             { $opts['mark-lost']         = true; continue; }
        if ($a === '--yes')                   { $opts['yes']               = true; continue; }
        fwrite(STDERR, "unknown option: {$a}\n");
        exit(2);
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
find-orphan-tiered-files - diagnostic for non-hot drive_files rows

Scans every drive_files row where tier_state != 'hot' and reports
whether the bytes are still on the VPS (recoverable), only on the NAS,
or gone entirely. Default mode is read-only.

Usage:
  /usr/local/lsws/lsphp83/bin/php \\
    /var/www/vps-email/backend/tests/find-orphan-tiered-files.php [OPTIONS]

Read-only options:
  --verbose             extra debug output
  --json                emit machine-readable JSON instead of a human report
  --smoke               quickest mode: skip the per-row file checks, just
                        count rows by tier_state (useful for monitoring)

Write options (require --yes):
  --rehot-recoverable   for every RECOVERABLE_HOT row: set tier_state='hot'
                        and append a drive_tier_transitions audit row
  --mark-lost           for every ORPHAN_NO_BYTES row that is NOT trashed:
                        set tier_state='lost' so the UI shows the lost-bytes
                        marker instead of pretending a recall will work
  --yes                 required for any write to actually happen

Exit:
  0  success (or no rows to act on)
  1  write op failed (rolled back)
  2  pre-flight failure

TXT;
}

function columnExists(\PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function isNasMounted(string $mount): bool
{
    if (is_file('/proc/mounts')) {
        $lines = @file('/proc/mounts', FILE_IGNORE_NEW_LINES);
        if (is_array($lines)) {
            foreach ($lines as $l) {
                $parts = preg_split('/\s+/', $l);
                if (isset($parts[1]) && rtrim($parts[1], '/') === rtrim($mount, '/')) {
                    return true;
                }
            }
            return false;
        }
    }
    return is_dir($mount) && (count(@scandir($mount) ?: []) > 2);
}

function classify(bool $isTrashed, bool $vpsExists, ?bool $nasExists, bool $nasMounted): string
{
    if ($vpsExists)    return 'RECOVERABLE_HOT';
    if ($isTrashed)    return 'TRASHED';
    if (!$nasMounted)  return 'NAS_UNAVAILABLE';
    if ($nasExists)    return 'NAS_ONLY';
    return 'ORPHAN_NO_BYTES';
}

function section(string $title, array $opts): void
{
    if (!empty($opts['json'])) return;
    echo color("\n=== {$title} ===\n", 'bold');
}

function line(string $sym, string $msg, array $opts): void
{
    if (!empty($opts['json'])) return;
    $colour = match ($sym) {
        '+' => 'green',
        'x' => 'red',
        '!' => 'yellow',
        '.' => 'dim',
        default => 'reset',
    };
    echo '  ' . color($sym, $colour) . ' ' . $msg . "\n";
}

function printRow(array $r, array $opts): void
{
    $dispColour = match ($r['disposition']) {
        'RECOVERABLE_HOT' => 'green',
        'NAS_ONLY'        => 'yellow',
        'NAS_UNAVAILABLE' => 'yellow',
        'ORPHAN_NO_BYTES' => 'red',
        'TRASHED'         => 'dim',
        default           => 'reset',
    };
    echo "\n";
    echo '  ' . color('#' . $r['id'], 'bold')
       . ' ' . color('[' . $r['disposition'] . ']', $dispColour)
       . ' ' . $r['original_name']
       . color(' (' . fmtBytes($r['size']) . ')', 'dim')
       . "\n";
    echo "    user           {$r['user_email']}\n";
    echo "    tier_state     {$r['tier_state']}"
       . ($r['tier_changed_at'] ? " (since {$r['tier_changed_at']}, by " . ($r['tier_changed_by'] ?: '?') . ')' : '')
       . "\n";
    echo '    storage_loc    ' . ($r['storage_location'] ?? '?') . "\n";
    echo '    is_trashed     ' . ($r['is_trashed'] ? 'YES' : 'no') . "\n";
    echo "    vps_path       {$r['vps_path']}\n";
    echo '    vps_exists     ' . ($r['vps_exists']
                                    ? color('YES', 'green') . ' (' . fmtBytes((int) $r['vps_size']) . ')'
                                    : color('NO', 'red')) . "\n";
    if ($r['vps_exists'] && $r['size_match'] === false) {
        echo '    ' . color('!! size mismatch with db (' . fmtBytes($r['size']) . ')', 'yellow') . "\n";
    }
    echo "    nas_path       {$r['nas_path']}\n";
    echo '    nas_exists     ' . ($r['nas_exists'] === null
                                    ? color('UNKNOWN (NAS not mounted)', 'yellow')
                                    : ($r['nas_exists'] ? color('YES', 'green') . ' (' . fmtBytes((int) $r['nas_size']) . ')'
                                                        : color('NO', 'red'))) . "\n";
}

function fmtBytes(int $b): string
{
    if ($b < 1024)               return $b . ' B';
    if ($b < 1024 * 1024)        return sprintf('%.2f KiB', $b / 1024);
    if ($b < 1024 * 1024 * 1024) return sprintf('%.2f MiB', $b / 1024 / 1024);
    return sprintf('%.2f GiB', $b / 1024 / 1024 / 1024);
}

function color(string $s, string $name): string
{
    static $codes = [
        'reset' => '0', 'bold' => '1', 'dim' => '2',
        'red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34',
    ];
    if (!stream_isatty(STDOUT)) return $s;
    $code = $codes[$name] ?? '0';
    return "\033[{$code}m{$s}\033[0m";
}
