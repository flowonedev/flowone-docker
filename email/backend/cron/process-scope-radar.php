#!/usr/bin/env php
<?php
/**
 * Scope Radar Processor
 * 
 * Background worker that checks boards for scope creep and sends notifications.
 * 
 * Run via cron once daily:
 *   0 9 * * * php /var/www/vps-email/backend/cron/process-scope-radar.php --verbose >> /var/log/scope-radar.log 2>&1
 * 
 * Options:
 *   --verbose     Show detailed progress
 *   --dry-run     Show what would be flagged without sending notifications
 *   --help        Show this help message
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$verbose = in_array('--verbose', $argv);
$dryRun = in_array('--dry-run', $argv);
$help = in_array('--help', $argv);

if ($help) {
    echo <<<HELP
Scope Radar Processor

Checks all user boards for scope creep signals (time exceeded, activity spikes,
overdue tasks) and creates notifications for boards that need attention.

Options:
  --verbose     Show detailed progress
  --dry-run     Show what would be flagged without sending notifications
  --help        Show this help message

Cron setup (daily at 9 AM):
  0 9 * * * php /var/www/vps-email/backend/cron/process-scope-radar.php --verbose >> /var/log/scope-radar.log 2>&1

HELP;
    exit(0);
}

require_once __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$startTime = microtime(true);

function logMsg(string $message, bool $verbose): void
{
    if ($verbose) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
}

logMsg('=== Scope Radar Check Started ===', $verbose);

if ($dryRun) {
    logMsg('DRY RUN MODE - no notifications will be sent', $verbose);
}

$addonService = new \Webmail\Services\AddonService($config);

if (!$addonService->isKanbanBoardsEnabled()) {
    logMsg('Kanban Boards addon is disabled - skipping', $verbose);
    exit(0);
}

if (!$addonService->isBoardProEnabled()) {
    logMsg('Board Pro addon is disabled - skipping', $verbose);
    exit(0);
}

$totalAlerts = 0;

try {
    $db = \Webmail\Core\Database::getConnection($config);
    $radarService = new \Webmail\Addons\BoardPro\Services\ScopeRadarService($config);
    $trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    // Get all users who own boards
    $stmt = $db->query("SELECT DISTINCT owner_email FROM webmail_boards WHERE owner_email IS NOT NULL");
    $users = $stmt->fetchAll(\PDO::FETCH_COLUMN);

    logMsg("Found " . count($users) . " users with boards", $verbose);

    foreach ($users as $userEmail) {
        logMsg("Checking boards for: $userEmail", $verbose);

        $alerts = $radarService->checkAllBoardsForUser($userEmail);

        if (empty($alerts)) {
            logMsg("  No scope creep detected", $verbose);
            continue;
        }

        foreach ($alerts as $alert) {
            $totalAlerts++;
            $boardName = $alert['board_name'];
            $severity = $alert['severity'];
            $flagged = $alert['flagged_cards'];
            $total = $alert['total_cards'];

            logMsg("  ALERT [{$severity}] Board '{$boardName}': {$flagged}/{$total} cards flagged, activity at {$alert['activity_spike_pct']}%", $verbose);

            if (!$dryRun) {
                $title = "Scope Creep Detected: {$boardName}";
                $message = "{$flagged} of {$total} cards flagged. Board activity at {$alert['activity_spike_pct']}% of baseline.";
                $notifData = [
                    'board_id' => $alert['board_id'],
                    'board_name' => $boardName,
                    'severity' => $severity,
                    'flagged_cards' => $flagged,
                    'total_cards' => $total,
                    'activity_spike_pct' => $alert['activity_spike_pct'],
                ];

                $notifId = $trackingService->createNotification(
                    $userEmail,
                    'scope_creep',
                    $title,
                    $message,
                    $notifData
                );

                if ($notifId) {
                    // Push real-time notification via Redis
                    try {
                        if (extension_loaded('redis')) {
                            $redis = new \Redis();
                            $host = $config['redis']['host'] ?? '127.0.0.1';
                            $port = $config['redis']['port'] ?? 6379;
                            $redis->connect($host, $port, 2.0);
                            $password = $config['redis']['password'] ?? null;
                            if ($password) $redis->auth($password);
                            $prefix = $config['redis']['prefix'] ?? 'webmail:';

                            $redis->publish($prefix . 'mailbox:' . $userEmail, json_encode([
                                'type' => 'NOTIFICATION_CREATED',
                                'payload' => [
                                    'id' => $notifId,
                                    'type' => 'scope_creep',
                                    'title' => $title,
                                    'message' => $message,
                                    'data' => $notifData,
                                    'is_read' => false,
                                    'created_at' => date('c'),
                                ],
                                'timestamp' => round(microtime(true) * 1000),
                            ]));
                            $redis->close();
                        }
                    } catch (\Throwable $e) {
                        logMsg("  Redis push error: " . $e->getMessage(), $verbose);
                    }

                    logMsg("  Notification #{$notifId} created", $verbose);
                }
            }
        }
    }
} catch (\Throwable $e) {
    logMsg("ERROR: " . $e->getMessage(), true);
    exit(1);
}

$elapsed = round(microtime(true) - $startTime, 2);
logMsg("=== Scope Radar Check Complete: {$totalAlerts} alerts in {$elapsed}s ===", $verbose);
