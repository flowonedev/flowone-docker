#!/usr/bin/env php
<?php
/**
 * Project Hub Inactivity Notifier
 *
 * Runs ProjectHubInactivityChecker once per day and notifies card creators
 * (falling back to assignees) about cards with no activity in the configured
 * window (default 90 days).
 *
 * Cron:
 *   30 7 * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/run-projecthub-inactivity.php --verbose
 *
 * Options:
 *   --threshold=N   Override threshold days (default 90)
 *   --dry-run       Run findInactiveCards only, do not notify
 *   --verbose       Extra stdout output
 *   --help          Usage
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

$opts = getopt('', ['threshold::', 'dry-run', 'verbose', 'help']) ?: [];
if (isset($opts['help'])) {
    echo "run-projecthub-inactivity.php [--threshold=N] [--dry-run] [--verbose]\n";
    exit(0);
}

$threshold = isset($opts['threshold']) ? max(1, (int) $opts['threshold']) : 90;
$dryRun = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);

$config = require __DIR__ . '/../src/config.php';

try {
    $checker = new \Webmail\Addons\ProjectHub\Services\ProjectHubInactivityChecker($config, $threshold);
    if ($dryRun) {
        $cards = $checker->findInactiveCards();
        if ($verbose) {
            fwrite(STDOUT, '[run-projecthub-inactivity] dry-run found=' . count($cards) . " threshold={$threshold}d\n");
        }
        exit(0);
    }
    $sent = $checker->runAndNotify();
    if ($verbose) {
        fwrite(STDOUT, '[run-projecthub-inactivity] notifications_sent=' . $sent . " threshold={$threshold}d\n");
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[run-projecthub-inactivity] FAIL: ' . $e->getMessage() . "\n");
    if ($verbose) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}
