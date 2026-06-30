#!/usr/bin/env php
<?php
/**
 * Per-user Sieve resync CLI
 *
 * Regenerates and writes one user's unified Sieve script from the current
 * data sources (filters + blocked/safe senders + vacation). Uses the
 * password-less disk write path (sudo-based), so it is safe to call from
 * cron or from an external trigger such as the DEVCON Panel agent after an
 * admin edits that user's allow/block lists.
 *
 * Usage:
 *   php /var/www/vps-email/backend/cron/sync-sieve-user.php --email=user@example.com
 *
 * Options:
 *   --email=<addr>   Required. The mailbox to resync.
 *   --json           Emit a JSON result line (for machine callers).
 *   --help           Show help.
 *
 * Exit codes: 0 = synced, 1 = usage/config error, 2 = sync failed.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\SieveSyncService;

$options = getopt('', ['email:', 'json', 'help']);

if (isset($options['help'])) {
    echo "Per-user Sieve resync\n\n";
    echo "Usage: php sync-sieve-user.php --email=user@example.com [--json]\n";
    exit(0);
}

$json = isset($options['json']);

$emit = function (bool $ok, string $message, array $extra = []) use ($json): void {
    if ($json) {
        echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra)) . "\n";
    } else {
        echo ($ok ? '[ok] ' : '[error] ') . $message . "\n";
    }
};

$email = strtolower(trim((string)($options['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $emit(false, 'A valid --email is required');
    exit(1);
}

$configFile = __DIR__ . '/../src/config.php';
if (!file_exists($configFile)) {
    $emit(false, "Config file not found: {$configFile}");
    exit(1);
}
$config = require $configFile;

try {
    $syncService = new SieveSyncService($config);
    $result = $syncService->syncViaDisk($email);

    if (!empty($result['success'])) {
        $emit(true, "Sieve resynced for {$email}", ['method' => $result['method'] ?? 'disk']);
        exit(0);
    }

    $emit(false, $result['error'] ?? 'Sieve sync failed');
    exit(2);
} catch (\Throwable $e) {
    $emit(false, 'Exception: ' . $e->getMessage());
    exit(2);
}
