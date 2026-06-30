<?php
/**
 * VPS Admin API Entry Point
 */

declare(strict_types=1);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

// Load configuration
$config = require __DIR__ . '/../config.php';

// Load local config if exists
if (file_exists(__DIR__ . '/../config.local.php')) {
    $localConfig = require __DIR__ . '/../config.local.php';
    $config = array_replace_recursive($config, $localConfig);
}

// --- CORS (origin-validated) ---
$allowedOrigins = $config['cors']['allowed_origins'] ?? [];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif (empty($allowedOrigins) || in_array('*', $allowedOrigins, true)) {
    // Fallback for development only – should never be '*' in production
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Api-Key');
header('Content-Type: application/json');

// --- Security headers ---
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

use VpsAdmin\Api\Core\Router;
use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;
use VpsAdmin\Api\Core\Container;

// Initialize debug logging gate
debug_log_init(!empty($config['app']['debug']));

try {
    // Initialize container
    $container = new Container($config);

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
    error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // Send error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error',
    ]);
}

