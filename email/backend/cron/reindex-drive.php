#!/usr/bin/env php
<?php
/**
 * Drive search backfill — re-index existing Drive files into the universal
 * search index (Meilisearch + MySQL).
 *
 * Why this exists:
 *   The on-write indexing now keeps Drive content fresh going forward, but
 *   files saved/edited BEFORE that fix have stale (filename-only) index rows.
 *   `cron/index-meilisearch.php` only backfills EMAILS from IMAP and does NOT
 *   touch drive_files, so this wrapper drives the one mechanism that does:
 *   SearchIndexerService::reindexUserDriveFiles().
 *
 * Note: reindexUserDriveFiles() is DRIVE-ONLY — it re-extracts and re-indexes
 * every drive file for the user (with a per-file guard so one corrupt doc can
 * never abort the batch) and does NOT touch emails or re-fetch IMAP
 * attachments, so it is far faster than a full rebuild. It is idempotent.
 *
 * Run on server (CLI only):
 *   # one workspace
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/reindex-drive.php --email=user@flowone.pro --verbose
 *   # every workspace that has Drive files
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/reindex-drive.php --all
 *
 * Flags:
 *   --help          Show this banner
 *   --verbose       Per-user stat lines
 *   --email=USER    Rebuild a single user's index
 *   --all           Rebuild for every user that owns at least one drive file
 *   --dry-run       List the users that would be rebuilt; do not write
 *
 * Exit codes:
 *   0  success (per-user failures tolerated, reported in summary)
 *   1  setup error or at least one user failed
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\SearchIndexerService;

$opts = getopt('', ['help', 'verbose', 'email::', 'all', 'dry-run']);

if (isset($opts['help'])) {
    echo "reindex-drive.php — backfill universal_search_index + Meilisearch for Drive files\n";
    echo "  --email=USER    rebuild a single user's index\n";
    echo "  --all           rebuild for every user that owns drive files\n";
    echo "  --verbose       per-user stat lines\n";
    echo "  --dry-run       list target users; do not write\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);
$all = isset($opts['all']);
$onlyEmail = isset($opts['email']) ? strtolower(trim((string)$opts['email'])) : null;

if (!$all && ($onlyEmail === null || $onlyEmail === '')) {
    fwrite(STDERR, "[reindex-drive] specify --email=USER or --all (see --help)\n");
    exit(1);
}

foreach (['pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[reindex-drive] missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[reindex-drive] DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'reindex-drive-' . date('Ymd-His') . '.log';
$log = function (string $msg) use ($logFile): void {
    echo $msg . "\n";
    @file_put_contents($logFile, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
};

$log('=== reindex-drive — ' . date('Y-m-d H:i:s T') . ' ===');

// Resolve target users.
$targets = [];
if ($onlyEmail) {
    $targets = [$onlyEmail];
} else {
    $rows = $db->query("SELECT user_email, COUNT(*) AS n FROM drive_files WHERE is_trashed = 0 GROUP BY user_email ORDER BY user_email");
    foreach ($rows as $r) {
        $targets[] = strtolower((string)$r['user_email']);
        if ($verbose) {
            $log(sprintf('  target %s (%d drive files)', $r['user_email'], (int)$r['n']));
        }
    }
}

if (empty($targets)) {
    $log('No target users found. Nothing to do.');
    exit(0);
}

if ($dryRun) {
    $log(sprintf('DRY RUN: %d user(s) would be rebuilt. No writes performed.', count($targets)));
    exit(0);
}

try {
    $indexer = new SearchIndexerService($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[reindex-drive] SearchIndexerService init failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$indexer->isMeilisearchEnabled()) {
    $log('Note: Meilisearch is not enabled — MySQL-only index will be rebuilt.');
}

$ok = 0;
$failed = 0;
foreach ($targets as $email) {
    try {
        $stats = $indexer->reindexUserDriveFiles($email);
        $ok++;
        $log(sprintf(
            '[OK]   %s — indexed=%d, failed=%d, total=%d',
            $email,
            (int)($stats['drive_files'] ?? 0),
            (int)($stats['failed'] ?? 0),
            (int)($stats['total'] ?? 0)
        ));
        if ($verbose && (int)($stats['failed'] ?? 0) > 0) {
            $log(sprintf(
                '       %d file(s) failed — see "reindexUserDriveFiles" lines in php_errors.log for the file id + error',
                (int)$stats['failed']
            ));
        }
    } catch (\Throwable $e) {
        $failed++;
        $log(sprintf('[FAIL] %s — %s', $email, $e->getMessage()));
    }
}

$log(sprintf('Summary: users=%d ok=%d failed=%d', count($targets), $ok, $failed));

exit($failed > 0 ? 1 : 0);
