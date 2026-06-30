#!/usr/bin/env php
<?php
/**
 * Drive Version Retention Worker (smart thinning).
 *
 * Applies the version retention policy to every file that has history
 * rows. Policy (implemented in DriveVersioningService, pinned versions
 * are always exempt):
 *
 *   - keep ALL versions newer than 24h
 *   - 1-30 days old: keep the newest version of each calendar day
 *   - older than 30 days: keep the newest version of each ISO week
 *   - hard cap of 50 versions per file (oldest unpinned evicted first)
 *
 * Pruning deletes the physical bytes (tier-aware), refunds the user's
 * quota and removes the row. The same prune also runs opportunistically
 * after every version creation, so this cron is the backstop that
 * catches files which simply aged without new edits.
 *
 * Run:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/prune-drive-versions.php --help
 *
 * Crontab (daily, off-peak):
 *   40 3 * * * /usr/bin/flock -n /var/lock/flowone-prune-drive-versions.lock \
 *      /usr/local/lsws/lsphp83/bin/php \
 *      /var/www/vps-email/backend/cron/prune-drive-versions.php --apply \
 *      >> /var/log/flowone/prune-drive-versions.log 2>&1
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "prune-drive-versions must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Core\Database;
use Webmail\Services\DriveService;

$opts = [
    'help' => false,
    'apply' => false,
    'dry_run' => false,
    'verbose' => false,
    'json' => false,
    'limit' => 2000,
    'max_seconds' => 600,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') { $opts['help'] = true; continue; }
    if ($arg === '--apply') { $opts['apply'] = true; continue; }
    if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
    if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
    if ($arg === '--json') { $opts['json'] = true; continue; }
    if (str_starts_with($arg, '--limit=')) {
        $opts['limit'] = max(1, (int) substr($arg, strlen('--limit=')));
        continue;
    }
    if (str_starts_with($arg, '--max-seconds=')) {
        $opts['max_seconds'] = max(10, (int) substr($arg, strlen('--max-seconds=')));
        continue;
    }
}

if ($opts['help']) {
    echo <<<TXT
Drive Version Retention Worker (smart thinning)

Usage:
  prune-drive-versions.php --apply              prune versions
  prune-drive-versions.php --dry-run            report what would be pruned
  prune-drive-versions.php --limit=N            max files per pass (default 2000)
  prune-drive-versions.php --max-seconds=N      wall-clock cap (default 600)
  prune-drive-versions.php --verbose            per-file output
  prune-drive-versions.php --json               machine-readable summary

Keeps all versions <24h old, one per day for 30 days, one per week
beyond, capped at 50 per file. Pinned versions are never pruned.
Deletes bytes, refunds quota, removes rows.

TXT;
    exit(0);
}

// Safety: default to --dry-run on a TTY when neither flag is given.
if (!$opts['apply'] && !$opts['dry_run']) {
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        fwrite(STDERR, "[safety] no --apply or --dry-run; defaulting to --dry-run\n");
        $opts['dry_run'] = true;
    } else {
        fwrite(STDERR, "must specify --apply or --dry-run\n");
        exit(2);
    }
}

$config = require __DIR__ . '/../src/config.php';

$logFile = __DIR__ . '/../storage/logs/prune-drive-versions.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}
$logLine = function (string $msg) use ($logFile, $opts): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (!$opts['json']) echo $line;
};

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$startMs = (int) (microtime(true) * 1000);
$deadlineMs = $startMs + $opts['max_seconds'] * 1000;

$summary = [
    'mode' => $opts['dry_run'] ? 'dry-run' : 'apply',
    'files_checked' => 0,
    'files_pruned' => 0,
    'versions_deleted' => 0,
    'bytes_freed' => 0,
    'errors' => 0,
    'elapsed_ms' => 0,
];

// Files that have history rows, grouped per owner so one DriveService
// (whose storage base can depend on the user's domain) serves each user.
$stmt = $db->prepare(
    'SELECT f.user_email, f.id AS file_id, COUNT(v.id) AS version_count
       FROM drive_files f
       JOIN drive_file_versions v ON v.file_id = f.id
      GROUP BY f.user_email, f.id
      ORDER BY f.user_email ASC, version_count DESC
      LIMIT :lim'
);
$stmt->bindValue(':lim', $opts['limit'], \PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

/** @var DriveService|null $driveService */
$driveService = null;
$currentUser = null;

foreach ($rows as $row) {
    if ((int) (microtime(true) * 1000) >= $deadlineMs) {
        $logLine('[PRUNE-VERSIONS] --max-seconds reached, stopping');
        break;
    }

    $email = (string) $row['user_email'];
    $fileId = (int) $row['file_id'];
    $summary['files_checked']++;

    try {
        if ($driveService === null || $currentUser !== $email) {
            $driveService = new DriveService($config, $email);
            $currentUser = $email;
        }
        $versioning = $driveService->versioning();

        if ($opts['dry_run']) {
            $vs = $db->prepare('SELECT * FROM drive_file_versions WHERE file_id = ?');
            $vs->execute([$fileId]);
            $would = $versioning->selectPrunableVersions($vs->fetchAll(), time());
            if ($would) {
                $bytes = array_sum(array_map(fn($v) => (int) $v['size'], $would));
                $summary['files_pruned']++;
                $summary['versions_deleted'] += count($would);
                $summary['bytes_freed'] += $bytes;
                if ($opts['verbose']) {
                    $logLine("[PRUNE-VERSIONS] dry file={$fileId} user={$email} would_delete=" . count($would) . " bytes={$bytes}");
                }
            }
            continue;
        }

        $result = $versioning->pruneFileVersions($email, $fileId);
        if ($result['deleted'] > 0) {
            $summary['files_pruned']++;
            $summary['versions_deleted'] += $result['deleted'];
            $summary['bytes_freed'] += $result['freed_bytes'];
            if ($opts['verbose']) {
                $logLine("[PRUNE-VERSIONS] file={$fileId} user={$email} deleted={$result['deleted']} bytes={$result['freed_bytes']}");
            }
        }
    } catch (\Throwable $e) {
        $summary['errors']++;
        $logLine("[PRUNE-VERSIONS] error file={$fileId} user={$email}: " . $e->getMessage());
    }
}

$summary['elapsed_ms'] = (int) (microtime(true) * 1000) - $startMs;

$logLine(sprintf(
    '[PRUNE-VERSIONS] summary mode=%s files_checked=%d files_pruned=%d versions_deleted=%d bytes_freed=%d errors=%d elapsed_ms=%d',
    $summary['mode'],
    $summary['files_checked'],
    $summary['files_pruned'],
    $summary['versions_deleted'],
    $summary['bytes_freed'],
    $summary['errors'],
    $summary['elapsed_ms']
));

if ($opts['json']) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
}

exit($summary['errors'] > 0 ? 1 : 0);
