#!/usr/bin/env php
<?php
/**
 * Drive Cleanup Cron
 * 
 * Deletes expired email attachment files from Drive storage.
 * Files marked as is_email_attachment=1 with an expired share_expires
 * are physically removed and their DB records deleted.
 * 
 * Run every hour:
 *   0 * * * * flock -n /tmp/cleanup-drive.lock php /var/www/vps-email/backend/cron/cleanup-drive.php
 * 
 * Options:
 *   --verbose   Show detailed output
 *   --help      Show help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\DriveService;

$options = getopt('', ['verbose', 'help']);

if (isset($options['help'])) {
    echo "Drive Cleanup - Removes expired email attachment files\n\n";
    echo "Usage: php cleanup-drive.php [--verbose] [--help]\n\n";
    echo "Cron: 0 * * * * flock -n /tmp/cleanup-drive.lock php /var/www/vps-email/backend/cron/cleanup-drive.php\n\n";
    exit(0);
}

$verbose = isset($options['verbose']);

$configFile = __DIR__ . '/../src/config.php';
if (!file_exists($configFile)) {
    error_log("[Drive Cleanup] Config file not found: {$configFile}");
    exit(1);
}

$config = require $configFile;

try {
    $driveService = new DriveService($config);
    $deletedCount = $driveService->cleanupExpiredEmailAttachments();

    if ($verbose || $deletedCount > 0) {
        $msg = "[Drive Cleanup] Cleaned up {$deletedCount} expired email attachment(s)";
        echo $msg . "\n";
        error_log($msg);
    }
} catch (\Throwable $e) {
    error_log("[Drive Cleanup] Error: " . $e->getMessage());
    exit(1);
}

