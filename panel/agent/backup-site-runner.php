#!/usr/bin/env php
<?php
/**
 * Background Site Backup Runner
 * 
 * This script is called by the agent to run site backups in the background.
 * It receives parameters via command line arguments and updates status files.
 * 
 * Usage: php backup-site-runner.php <json-encoded-params>
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    file_put_contents('/tmp/backup-runner-error.log', date('Y-m-d H:i:s') . " - Not running from CLI\n", FILE_APPEND);
    exit(1);
}

// Get parameters from command line
$params = [];
if (isset($argv[1])) {
    $params = json_decode($argv[1], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents('/tmp/backup-runner-error.log', date('Y-m-d H:i:s') . " - Invalid JSON: " . json_last_error_msg() . "\n", FILE_APPEND);
        exit(1);
    }
}

$domain = $params['domain'] ?? null;
$statusId = $params['status_id'] ?? null;
$actor = $params['actor'] ?? 'background';

if (!$domain || !$statusId) {
    file_put_contents('/tmp/backup-runner-error.log', date('Y-m-d H:i:s') . " - Missing required parameters\n", FILE_APPEND);
    exit(1);
}

// Helper function to update status file directly
function updateStatusFile(string $statusId, array $updates): void
{
    $statusDir = '/var/www/vps-admin/backups/.status';
    $statusFile = "{$statusDir}/{$statusId}.json";
    
    if (file_exists($statusFile)) {
        $status = json_decode(file_get_contents($statusFile), true) ?: [];
        $status = array_merge($status, $updates);
        $status['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
    }
}

// Mark that we've started
updateStatusFile($statusId, [
    'step' => 'initializing',
    'progress' => 1,
    'message' => 'Background process started...',
]);

// Allow unlimited execution time
set_time_limit(0);
ignore_user_abort(true);

// Load dependencies BEFORE the try block
require_once __DIR__ . '/Lib/Logger.php';
require_once __DIR__ . '/Lib/ActionInterface.php';
require_once __DIR__ . '/Lib/BackupManager.php';
require_once __DIR__ . '/Lib/DiffGenerator.php';
require_once __DIR__ . '/Lib/BaseAction.php';
require_once __DIR__ . '/Lib/BackupScheduleManager.php';
require_once __DIR__ . '/Lib/PanelDb.php';
require_once __DIR__ . '/Actions/BackupAction.php';

// Use statements must be at top level, not inside try block
use VpsAdmin\Agent\Actions\BackupAction;
use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;

try {
    // Load config
    $config = require __DIR__ . '/config.php';
    
    // Set up logger with proper config array
    $logConfig = [
        'logging' => [
            'file' => '/var/log/vpsadmin/backup-runner.log',
            'level' => 'info',
        ]
    ];
    
    // Ensure log directory exists
    $logDir = '/var/log/vpsadmin';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0750, true);
    }
    
    $logger = new Logger($logConfig);
    
    $logger->info("Background backup started", [
        'domain' => $domain,
        'status_id' => $statusId,
        'actor' => $actor,
    ]);
    
    updateStatusFile($statusId, [
        'step' => 'initializing',
        'progress' => 2,
        'message' => 'Loading backup system...',
    ]);
    
    // Create dependencies for BackupAction
    $backupManager = new BackupManager($config);
    $diffGenerator = new DiffGenerator();
    
    // Create the backup action
    $backup = new BackupAction($config, $backupManager, $diffGenerator, $logger);
    
    updateStatusFile($statusId, [
        'step' => 'initializing',
        'progress' => 5,
        'message' => 'Starting backup process...',
    ]);
    
    // Run the actual backup (non-async mode since we're already in background)
    // Pass the pre-created status_id so it doesn't create a new one
    $backupParams = [
        'domain' => $domain,
        'components' => $params['components'] ?? ['all'],
        'destination' => $params['destination'] ?? 'local',
        'async' => false,  // We're already in background, don't spawn another
        'status_id_override' => $statusId, // Use the pre-created status ID
    ];
    
    $result = $backup->execute('backupSite', $backupParams, $actor);
    
    $logger->info("Background backup completed", [
        'domain' => $domain,
        'success' => $result['success'],
        'message' => $result['message'] ?? ($result['error'] ?? 'No message'),
    ]);
    
    exit($result['success'] ? 0 : 1);
    
} catch (Throwable $e) {
    // Log to error file for debugging
    $errorMsg = sprintf(
        "[%s] ERROR: %s\nFile: %s:%d\nTrace:\n%s\n\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    file_put_contents('/tmp/backup-runner-error.log', $errorMsg, FILE_APPEND);
    
    // Update status file to mark as failed
    updateStatusFile($statusId, [
        'status' => 'failed',
        'completed_at' => date('Y-m-d H:i:s'),
        'result' => ['error' => $e->getMessage()],
        'message' => 'Backup failed: ' . $e->getMessage(),
    ]);
    
    exit(1);
}
