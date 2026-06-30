#!/usr/bin/env php
<?php
/**
 * Board Pro Automation Processor
 * 
 * Background worker that evaluates board automation rules (overdue, idle, etc.)
 * 
 * Run via cron every 5 minutes (use a star-slash-5 step in the minute field):
 *   STAR/5 STAR STAR STAR STAR  php /var/www/vps-email/backend/cron/process-boardpro-automation.php --verbose >> /var/log/boardpro-automation.log 2>&1
 * 
 * Options:
 *   --verbose     Show detailed progress
 *   --dry-run     Show what would be processed without executing
 *   --help        Show this help message
 */

// Ensure CLI execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Parse CLI arguments
$verbose = in_array('--verbose', $argv);
$dryRun = in_array('--dry-run', $argv);
$help = in_array('--help', $argv);

if ($help) {
    echo <<<HELP
Board Pro Automation Processor

Usage: php process-boardpro-automation.php [OPTIONS]

Options:
  --verbose     Show detailed progress output
  --dry-run     Show what would be processed without executing actions
  --help        Show this help message

Cron setup (every 5 minutes):
  */5 * * * * php /var/www/vps-email/backend/cron/process-boardpro-automation.php --verbose >> /var/log/boardpro-automation.log 2>&1

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

logMsg('=== Board Pro Automation Run Started ===', $verbose);

if ($dryRun) {
    logMsg('DRY RUN MODE - no actions will be executed', $verbose);
}

// Check if both addons are enabled
$addonService = new \Webmail\Services\AddonService($config);

if (!$addonService->isKanbanBoardsEnabled()) {
    logMsg('Kanban Boards addon is disabled - skipping', $verbose);
    exit(0);
}

if (!$addonService->isBoardProEnabled()) {
    logMsg('Board Pro addon is disabled - skipping', $verbose);
    exit(0);
}

$totalActions = 0;

try {
    // =========================================================================
    // Phase 1: Evaluate time-based triggers (overdue, idle)
    // =========================================================================
    logMsg('Phase 1: Evaluating time-based automation triggers...', $verbose);

    $automationService = new \Webmail\Addons\BoardPro\Services\BoardProAutomationService($config);

    if (!$dryRun) {
        $results = $automationService->evaluateTimeTriggers();
        $totalActions = count($results);
        foreach ($results as $result) {
            $action = $result['action'] ?? 'unknown';
            $success = $result['success'] ?? false;
            $status = $success ? 'OK' : 'FAIL';
            logMsg("  [{$status}] Action: {$action}", $verbose);
        }
    } else {
        logMsg('  (skipped in dry-run mode)', $verbose);
    }

    logMsg("Phase 1 complete: {$totalActions} actions executed", $verbose);

    // =========================================================================
    // Phase 2: Sync email reply statuses
    // =========================================================================
    logMsg('Phase 2: Syncing email reply statuses...', $verbose);

    // This phase would check linked emails and update reply_status
    // based on whether the user has sent a reply in the same thread.
    // For now, this is a placeholder for future IMAP checking.
    logMsg('Phase 2: Email reply sync (placeholder - requires IMAP access per user)', $verbose);

} catch (\Throwable $e) {
    $errorMsg = "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    logMsg($errorMsg, true); // Always log errors
    error_log("process-boardpro-automation.php: " . $e->getMessage());
}

$elapsed = round((microtime(true) - $startTime) * 1000);
logMsg("=== Board Pro Automation Run Complete: {$totalActions} actions ({$elapsed}ms) ===", $verbose);

