<?php

declare(strict_types=1);

namespace VpsAdmin\Tests\Lib;

/**
 * Boot the agent's autoloader and the shared test harness so individual
 * test scripts only have to require this file.
 *
 * Production location of the agent is `/var/www/vps-admin/agent/`; in
 * local dev it's `panel/agent/` next to `panel/tests/`. We try both.
 */

require_once __DIR__ . '/TestHarness.php';

// Locate the agent root
$agentCandidates = [
    __DIR__ . '/../../agent',                 // local dev: panel/agent
    '/var/www/vps-admin/agent',                // production
];

$agentRoot = null;
foreach ($agentCandidates as $candidate) {
    if (is_dir($candidate)) {
        $agentRoot = realpath($candidate);
        break;
    }
}

if ($agentRoot === null) {
    fwrite(STDERR, "TEST BOOTSTRAP FAIL: agent root not found in: " . implode(', ', $agentCandidates) . "\n");
    exit(2);
}

spl_autoload_register(function (string $class) use ($agentRoot): void {
    $prefix = 'VpsAdmin\\Agent\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $agentRoot . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

return $agentRoot;
