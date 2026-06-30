<?php
/**
 * DEVCON Fleet Manager API Entry Point
 */

declare(strict_types=1);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Agent-Token');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

// Load configuration
$config = require __DIR__ . '/../config.php';

// Load local config if exists
if (file_exists(__DIR__ . '/../config.local.php')) {
    $localConfig = require __DIR__ . '/../config.local.php';
    $config = array_replace_recursive($config, $localConfig);
}

use FleetManager\Api\Core\Router;
use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Core\Container;
use FleetManager\Api\Services\MigrationService;

try {
    // Initialize container
    $container = new Container($config);

    // Run pending migrations automatically
    $migrationsPath = __DIR__ . '/../../database/migrations';
    if (is_dir($migrationsPath)) {
        $migrationService = new MigrationService($container->getDatabase(), $migrationsPath);
        $migrationService->runPendingMigrations();
    }

    // Parse request
    $request = Request::fromGlobals();

    // Initialize router
    $router = new Router($container);

    // Define routes
    require __DIR__ . '/../routes.php';

    // Handle request
    $response = $router->dispatch($request);

    // Send response
    $response->send();

} catch (\Throwable $e) {
    // Log error
    error_log('Fleet API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // Send error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error',
    ]);
}

