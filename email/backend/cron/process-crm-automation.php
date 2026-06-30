#!/usr/bin/env php
<?php
/**
 * CRM Automation Processor
 * 
 * Background worker that evaluates automation rules and processes sequence steps.
 * 
 * Run via cron every 5 minutes:
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /var/www/vps-email/backend/cron/process-crm-automation.php --verbose >> /var/log/crm-automation.log 2>&1
 * 
 * Options:
 *   --verbose     Show detailed progress
 *   --dry-run     Show what would be processed without executing
 *   --help        Show this help message
 * 
 * The worker will:
 * 1. Load all active automation rules across all users
 * 2. Evaluate triggers (stale deals, overdue invoices, silent clients, low health)
 * 3. Execute matching actions (create reminders, send emails, move stages, etc.)
 * 4. Process due email sequence steps
 * 5. Log all executions
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
CRM Automation Processor

Usage: php process-crm-automation.php [OPTIONS]

Options:
  --verbose     Show detailed progress output
  --dry-run     Show what would be processed without executing actions
  --help        Show this help message

Cron setup (every 5 minutes):
  */5 * * * * php /var/www/vps-email/backend/cron/process-crm-automation.php --verbose >> /var/log/crm-automation.log 2>&1

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

logMsg('=== CRM Automation Run Started ===', $verbose);

if ($dryRun) {
    logMsg('DRY RUN MODE - no actions will be executed', $verbose);
}

$totalRuleActions = 0;
$totalSequenceSteps = 0;

try {
    // =========================================================================
    // Phase 1: Evaluate automation rules
    // =========================================================================
    logMsg('Phase 1: Evaluating automation rules...', $verbose);

    $automationService = new \Webmail\Addons\CrmPro\Services\CrmAutomationService($config);

    if (!$dryRun) {
        $ruleResults = $automationService->evaluateAllRules();
        foreach ($ruleResults as $userEmail => $count) {
            logMsg("  User {$userEmail}: {$count} action(s) executed", $verbose);
            $totalRuleActions += $count;
        }
    } else {
        logMsg('  (skipped in dry-run mode)', $verbose);
    }

    logMsg("Phase 1 complete: {$totalRuleActions} total rule actions", $verbose);

    // =========================================================================
    // Phase 2: Process due sequence steps
    // =========================================================================
    logMsg('Phase 2: Processing due sequence steps...', $verbose);

    $sequenceService = new \Webmail\Addons\CrmPro\Services\CrmSequenceService($config);

    if (!$dryRun) {
        $totalSequenceSteps = $sequenceService->processDueSteps();
    } else {
        logMsg('  (skipped in dry-run mode)', $verbose);
    }

    logMsg("Phase 2 complete: {$totalSequenceSteps} sequence step(s) processed", $verbose);

} catch (\Throwable $e) {
    $errorMsg = "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    logMsg($errorMsg, true); // Always log errors
    error_log("process-crm-automation.php: " . $e->getMessage());
}

$elapsed = round((microtime(true) - $startTime) * 1000);
logMsg("=== CRM Automation Run Complete: {$totalRuleActions} rule actions, {$totalSequenceSteps} sequence steps ({$elapsed}ms) ===", $verbose);

