<?php
/**
 * FCM token maintenance cron
 *
 * Two jobs, both owned by PHP (MySQL is the source of truth for tokens):
 *
 *  1. Drain `fcm_prune_queue` (Redis list). The Node mailsync server RPUSHes a
 *     {email, token} entry whenever FCM reports a token as unregistered/invalid.
 *     This cron LPOPs each entry, deletes the matching native_push_tokens row,
 *     and rebuilds the fcm_tokens:{email} Redis cache. Node never edits tokens.
 *
 *  2. (--cleanup-stale) Delete tokens not seen for N days (default 75) so devices
 *     that stopped re-registering age out.
 *
 * Crontab lines:
 *   # Drain prune queue every minute
 *   * * * * * /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/drain-fcm-prune-queue.php >> \
 *     /var/www/vps-email/backend/storage/logs/fcm-prune-cron.log 2>&1
 *   # Prune stale tokens once a day at 03:10
 *   10 3 * * * /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/drain-fcm-prune-queue.php --cleanup-stale >> \
 *     /var/www/vps-email/backend/storage/logs/fcm-prune-cron.log 2>&1
 *
 * Flags:
 *   --help            Show this banner
 *   --verbose         Per-item log lines
 *   --cleanup-stale   Also delete tokens unseen for --stale-days days
 *   --stale-days=N    Stale window in days (default 75)
 *   --max=N           Max prune-queue items to process per run (default 1000)
 *
 * Exit codes:
 *   0  success
 *   1  setup error
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\PushNotificationService;

$opts = getopt('', ['help', 'verbose', 'cleanup-stale', 'stale-days::', 'max::']);

if (isset($opts['help'])) {
    echo "drain-fcm-prune-queue.php - prune dead FCM tokens + stale cleanup\n";
    echo "  --verbose         per-item log lines\n";
    echo "  --cleanup-stale   delete tokens unseen for --stale-days days\n";
    echo "  --stale-days=N    stale window (default 75)\n";
    echo "  --max=N           max prune-queue items per run (default 1000)\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$cleanupStale = isset($opts['cleanup-stale']);
$staleDays = max(1, (int)($opts['stale-days'] ?? 75));
$max = max(1, (int)($opts['max'] ?? 1000));

foreach (['pdo_mysql', 'redis'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[fcm-prune] missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'fcm-prune-' . date('Ymd') . '.log';
$log = function (string $msg) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

try {
    $push = new PushNotificationService($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[fcm-prune] init failed: " . $e->getMessage() . "\n");
    exit(1);
}

$pruned = $push->drainFcmPruneQueue($max);
if ($verbose || $pruned > 0) {
    $log("drained prune queue: {$pruned} dead token(s) removed");
}

if ($cleanupStale) {
    $removed = $push->cleanupStaleNativeTokens($staleDays);
    $log("stale cleanup (>{$staleDays}d): {$removed} token(s) removed");
}

exit(0);
