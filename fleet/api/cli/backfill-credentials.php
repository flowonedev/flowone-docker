<?php
/**
 * CLI: backfill the "Server Credentials" panel for an already-provisioned Docker
 * server. Reads the box's generated secrets from the persisted
 * `servers.*_encrypted` columns and (re)writes the full inventory (panel admin,
 * DB passwords, redis/meili, API/JWT/encryption keys, mailbox login, SSH, DNS)
 * into `server_credentials`.
 *
 * Does NOT connect to the target, pull images, or restart anything — it only
 * touches the Fleet Manager DB. Safe and idempotent (unique key upsert).
 *
 * Use for boxes provisioned before the Docker path recorded the inventory;
 * fresh provisions/updates populate it automatically.
 *
 * Usage:
 *   php backfill-credentials.php <server_id>
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$serverId = (int) ($argv[1] ?? 0);
if (!$serverId) {
    die("Usage: php backfill-credentials.php <server_id>\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use FleetManager\Api\Core\Container;
use FleetManager\Api\Services\DockerProvisioningService;

$config = require __DIR__ . '/../config.php';
$localConfig = file_exists(__DIR__ . '/../config.local.php')
    ? require __DIR__ . '/../config.local.php'
    : [];
$config = array_replace_recursive($config, $localConfig);
$config['cli_verbose'] = true;

$container = new Container($config);

try {
    /** @var DockerProvisioningService $svc */
    $svc = $container->get(DockerProvisioningService::class);
    echo "Backfilling credentials for server {$serverId}...\n";
    $result = $svc->backfillCredentials($serverId);

    foreach ($result['log'] ?? [] as $line) {
        echo "  {$line}\n";
    }

    if (!empty($result['success'])) {
        echo "Done — Server Credentials panel populated.\n";
        exit(0);
    }
    echo "Failed: " . ($result['error'] ?? 'unknown error') . "\n";
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[UNCAUGHT] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
