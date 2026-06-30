<?php
/**
 * Cron Bootstrap
 * 
 * Shared bootstrap for all cron scripts.
 * Loads .env file and autoloader so config.php can read environment variables.
 * 
 * Usage in cron scripts:
 *   require_once __DIR__ . '/bootstrap.php';
 */

// Load .env file (same logic as public/index.php)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Strip surrounding quotes
        if ((strlen($value) > 1) && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            $value = substr($value, 1, -1);
        }
        if (!getenv($key)) {
            putenv("$key=$value");
        }
    }
}

// Load autoloader
require_once __DIR__ . '/../vendor/autoload.php';

