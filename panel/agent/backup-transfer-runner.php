#!/usr/bin/env php
<?php
/**
 * Background NAS Transfer Runner
 *
 * Spawned by the agent (BackupAction::startBackgroundTransfer) so manual
 * "Send to NAS" copy/move operations run detached from the agent's
 * single-threaded socket loop. The agent returns a status_id immediately
 * and the panel polls /api/backups/status?status_id=... while this process
 * performs the checksum-verified upload.
 *
 * Usage: php backup-transfer-runner.php '<json-encoded-params>'
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    file_put_contents('/tmp/backup-runner-error.log', date('Y-m-d H:i:s') . " - transfer-runner: not running from CLI\n", FILE_APPEND);
    exit(1);
}

// Get parameters from command line
$params = [];
if (isset($argv[1])) {
    $params = json_decode($argv[1], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents('/tmp/backup-runner-error.log', date('Y-m-d H:i:s') . " - transfer-runner: invalid JSON: " . json_last_error_msg() . "\n", FILE_APPEND);
        exit(1);
    }
}

$backupId = $params['id'] ?? null;
$mode = $params['mode'] ?? 'copy';
$statusId = $params['status_id'] ?? null;
$actor = $params['actor'] ?? 'background';

if (!$backupId || !$statusId) {
    file_put_contents('/tmp/backup-runner-error.log', date('Y-m-d H:i:s') . " - transfer-runner: missing required parameters\n", FILE_APPEND);
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
    'progress' => 2,
    'message' => 'Background transfer process started...',
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

    $logConfig = [
        'logging' => [
            'file' => '/var/log/vpsadmin/backup-runner.log',
            'level' => 'info',
        ]
    ];

    $logDir = '/var/log/vpsadmin';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0750, true);
    }

    $logger = new Logger($logConfig);

    $logger->info("Background NAS transfer started", [
        'status_id' => $statusId,
        'mode' => $mode,
        'actor' => $actor,
    ]);

    $backupManager = new BackupManager($config);
    $diffGenerator = new DiffGenerator();
    $backup = new BackupAction($config, $backupManager, $diffGenerator, $logger);

    // Run the actual transfer synchronously (we're already detached).
    // status_id_override makes transferToNas update this status file
    // and complete the tracking when done.
    $result = $backup->execute('transferToNas', [
        'id' => $backupId,
        'mode' => $mode,
        'async' => false,
        'status_id_override' => $statusId,
    ], $actor);

    // Early validation failures return before tracking is touched - make
    // sure the panel never polls a "running" status forever.
    if (empty($result['success'])) {
        updateStatusFile($statusId, [
            'status' => 'failed',
            'completed_at' => date('Y-m-d H:i:s'),
            'result' => ['error' => $result['error'] ?? 'Transfer failed'],
            'message' => 'Transfer failed: ' . ($result['error'] ?? 'unknown error'),
        ]);
    }

    $logger->info("Background NAS transfer finished", [
        'status_id' => $statusId,
        'success' => !empty($result['success']),
        'message' => $result['message'] ?? ($result['error'] ?? 'No message'),
    ]);

    exit(!empty($result['success']) ? 0 : 1);

} catch (Throwable $e) {
    $errorMsg = sprintf(
        "[%s] TRANSFER-RUNNER ERROR: %s\nFile: %s:%d\nTrace:\n%s\n\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    file_put_contents('/tmp/backup-runner-error.log', $errorMsg, FILE_APPEND);

    updateStatusFile($statusId, [
        'status' => 'failed',
        'completed_at' => date('Y-m-d H:i:s'),
        'result' => ['error' => $e->getMessage()],
        'message' => 'Transfer failed: ' . $e->getMessage(),
    ]);

    exit(1);
}
