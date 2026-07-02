<?php
/**
 * Day-2 CLI: redeploy ONLY the DevCon Panel to one server.
 *
 * The panel leg of the split update workflow:
 *   email app  -> cli/provision-docker.php <id> --services=web[,collab,...] --tag=<t>
 *   panel      -> cli/update-panel.php <id>            (this script)
 *   security   -> cli/harden-server.php <id> [--docker]
 *   everything -> cli/provision-docker.php <id>        (full provision)
 *
 * Rebuild the package first (master-update.sh does it from the repo checkout),
 * then run:
 *   php update-panel.php <server_id>
 *
 * Uploads packages/panel/panel-latest.tar.gz to the target and re-runs its
 * install.sh (idempotent). Docker boxes automatically get --db-host=127.0.0.1.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$serverId = (int) ($argv[1] ?? 0);
if (!$serverId) {
    die("Usage: php update-panel.php <server_id>\n");
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

try {
    /** @var ProvisioningService $svc */
    $svc = $container->get(ProvisioningService::class);
    echo "Updating panel on server {$serverId}...\n";
    $result = $svc->updatePanel($serverId);

    if (!empty($result['success'])) {
        echo "Done.\n";
        exit(0);
    }
    echo "Failed: " . ($result['error'] ?? 'unknown') . "\n";
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[UNCAUGHT] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
