<?php
/**
 * CLI Script for background provisioning
 *
 * Usage:
 *   php provision.php <server_id> [blueprint_id]
 *   php provision.php <server_id> [blueprint_id] --resume=<deployment_id> [--skip-failed]
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        fwrite(STDERR, "\n[FATAL] PHP Fatal Error:\n");
        fwrite(STDERR, "  Type: " . $error['type'] . "\n");
        fwrite(STDERR, "  Message: " . $error['message'] . "\n");
        fwrite(STDERR, "  File: " . $error['file'] . "\n");
        fwrite(STDERR, "  Line: " . $error['line'] . "\n");
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $types = [
        E_WARNING => 'WARNING',
        E_NOTICE => 'NOTICE',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
    ];
    $type = $types[$errno] ?? "ERROR({$errno})";
    fwrite(STDERR, "[PHP {$type}] {$errstr} in {$errfile}:{$errline}\n");
    return false;
});

// Parse positional and named arguments
$serverId = (int)($argv[1] ?? 0);
$blueprintId = (int)($argv[2] ?? 0);
$resumeDeploymentId = null;
$skipFailed = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--resume=')) {
        $resumeDeploymentId = (int)substr($arg, 9);
    }
    if ($arg === '--skip-failed') {
        $skipFailed = true;
    }
}

if (!$serverId) {
    die("Usage: php provision.php <server_id> [blueprint_id] [--resume=<deployment_id>] [--skip-failed]\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use FleetManager\Api\Core\Container;
use FleetManager\Api\Services\ProvisioningService;

$config = require __DIR__ . '/../config.php';
$localConfig = file_exists(__DIR__ . '/../config.local.php') 
    ? require __DIR__ . '/../config.local.php' 
    : [];
$config = array_replace_recursive($config, $localConfig);
$config['cli_verbose'] = true;

$container = new Container($config);

// Run pending migrations before provisioning
$migrationsPath = __DIR__ . '/../../database/migrations';
if (is_dir($migrationsPath)) {
    $migrationService = new \FleetManager\Api\Services\MigrationService($container->getDatabase(), $migrationsPath);
    $migResult = $migrationService->runPendingMigrations();
    if (!empty($migResult['applied'])) {
        echo "Migrations applied: " . implode(', ', $migResult['applied']) . "\n";
    }
}

try {
    $mode = $resumeDeploymentId ? "RESUMING deployment #{$resumeDeploymentId}" : "Starting provisioning";
    echo "{$mode} for server {$serverId}" . ($blueprintId ? " with blueprint {$blueprintId}" : "") . "\n";
    if ($skipFailed) echo "  --skip-failed: will skip the failed step\n";
    echo "PID: " . getmypid() . " | Memory: " . round(memory_get_usage(true) / 1048576, 1) . "MB\n";
    
    $provisioning = $container->get(ProvisioningService::class);
    $result = $provisioning->provision(
        $serverId,
        $blueprintId ?: null,
        $resumeDeploymentId,
        $skipFailed
    );
    
    echo "\nMemory peak: " . round(memory_get_peak_usage(true) / 1048576, 1) . "MB\n";
    
    if ($result['success']) {
        echo "Provisioning completed successfully!\n";
        exit(0);
    } else {
        echo "Provisioning failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[UNCAUGHT EXCEPTION] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    fwrite(STDERR, "Trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
