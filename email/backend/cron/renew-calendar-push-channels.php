<?php
/**
 * Phase 3.6 — Calendar push channel renewal
 *
 * Google Calendar push channels expire after at most 7 days (we always
 * request 24h to keep blast radius small). This cron walks the
 * calendar_push_channels table every hour, finds rows expiring within
 * the next 6 hours, and renews them: stop-then-watch so the new channel
 * is in place before the old one dies.
 *
 * Without this cron, push notifications silently stop after one day and
 * the user is stuck with whatever the 5-minute polling cron gives them.
 *
 * Crontab line:
 *   0 star/1 star star star /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/renew-calendar-push-channels.php >> \
 *     /var/www/vps-email/backend/storage/logs/calendar-channels-cron.log 2>&1
 *
 * Flags:
 *   --help         Show this banner
 *   --verbose      Per-row log lines
 *   --dry-run      Report rows that would be renewed; do nothing
 *   --window=HRS   Renew channels expiring within N hours (default 6)
 *
 * Exit codes:
 *   0  success (per-row failures are tolerated)
 *   1  setup error
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Addons\Calendar\Services\GoogleCalendarService;

$opts = getopt('', ['help', 'verbose', 'dry-run', 'window::']);

if (isset($opts['help'])) {
    echo "renew-calendar-push-channels.php — refresh Google Calendar push channels\n";
    echo "  --verbose     per-row log lines\n";
    echo "  --dry-run     no API calls, report only\n";
    echo "  --window=HRS  renewal lookahead in hours (default 6)\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);
$windowHours = max(1, (int)($opts['window'] ?? 6));

foreach (['openssl', 'curl', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[renew-channels] missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

if (empty($config['google_oauth']['client_id'])) {
    echo "google_oauth not configured; nothing to do\n";
    exit(0);
}

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[renew-channels] DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

// If the table doesn't exist (migration 171 hasn't run yet), just exit clean.
try {
    $exists = (bool)$db->query("SHOW TABLES LIKE 'calendar_push_channels'")->fetchColumn();
} catch (\Throwable $e) {
    fwrite(STDERR, "[renew-channels] SHOW TABLES failed: " . $e->getMessage() . "\n");
    exit(1);
}
if (!$exists) {
    echo "calendar_push_channels not present; nothing to renew\n";
    exit(0);
}

try {
    $gcal = new GoogleCalendarService($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[renew-channels] GoogleCalendarService init failed: " . $e->getMessage() . "\n");
    exit(1);
}

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'calendar-channels-' . date('Ymd') . '.log';
$log = function (string $msg) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

$channels = $gcal->listChannelsExpiringWithin($windowHours);
$total = count($channels);
$renewed = 0;
$failed = 0;

$log("renew-channels start window={$windowHours}h candidates={$total} dryRun=" . ($dryRun ? '1' : '0'));

foreach ($channels as $row) {
    $channelRowId = (int)$row['id'];
    $syncStateId = (int)$row['calendar_sync_state_id'];

    if ($dryRun) {
        $renewed++;
        $log("dry-run renew channel_row_id={$channelRowId} syncState={$syncStateId} expires={$row['expires_at']}");
        continue;
    }

    try {
        // Stop the old channel, then watch the calendar again (which inserts
        // a fresh row keyed on a new channel_id and bumps the unique key on
        // calendar_push_channels.channel_id).
        $gcal->unwatchCalendar($channelRowId);
        $ok = $gcal->watchCalendar($syncStateId);
        if ($ok) {
            $renewed++;
            if ($verbose) {
                $log("renewed syncState={$syncStateId}");
            }
        } else {
            $failed++;
            $log("FAILED renew syncState={$syncStateId}");
        }
    } catch (\Throwable $e) {
        $failed++;
        $log("FAILED renew syncState={$syncStateId}: " . $e->getMessage());
    }
}

$log("renew-channels done total={$total} renewed={$renewed} failed={$failed}");
exit(0);
