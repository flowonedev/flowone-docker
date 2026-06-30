<?php
/**
 * CLI Script for server wipe
 * Usage: php wipe.php <server_id> [options_json]
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

// Get arguments
$serverId = (int)($argv[1] ?? 0);
$optionsJson = $argv[2] ?? '{}';
$options = json_decode($optionsJson, true) ?: [];

if (!$serverId) {
    die("Usage: php wipe.php <server_id> [options_json]\n");
}

// Bootstrap the application
require_once __DIR__ . '/../vendor/autoload.php';

use FleetManager\Api\Core\Container;
use FleetManager\Api\Services\ProvisioningService;

// Load configuration
$config = require __DIR__ . '/../config.php';
$localConfig = file_exists(__DIR__ . '/../config.local.php') 
    ? require __DIR__ . '/../config.local.php' 
    : [];
$config = array_replace_recursive($config, $localConfig);

// Create container
$container = new Container($config);

// Run wipe
try {
    echo "Starting server wipe for server {$serverId}\n";
    echo "Options: " . json_encode($options) . "\n";
    
    $provisioning = $container->get(ProvisioningService::class);
    $result = $provisioning->wipeServer($serverId, $options);
    
    if ($result['success']) {
        echo "Server wipe completed successfully\n";
        exit(0);
    } else {
        echo "Server wipe failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "Wipe error: " . $e->getMessage() . "\n";
    exit(1);
}

