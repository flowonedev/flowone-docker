<?php
/**
 * CLI: (re)issue the Let's Encrypt SAN cert for a Docker-provisioned server and
 * enable HTTPS — for whatever public domains currently resolve to the box.
 *
 * Use this after adding/fixing DNS A records, when a provision left the box on
 * the OpenLiteSpeed self-signed cert because some hostname didn't resolve yet.
 * It does NOT re-pull images or churn the whole stack: it just runs certbot,
 * flips ENABLE_SSL on, and reloads web + mail.
 *
 * Usage:
 *   php renew-ssl.php <server_id> [--registry=..] [--tag=..] [--deployment=id]
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$serverId = (int) ($argv[1] ?? 0);
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--registry=')) $options['registry'] = substr($arg, 11);
    elseif (str_starts_with($arg, '--tag=')) $options['tag'] = substr($arg, 6);
    elseif (str_starts_with($arg, '--deployment=')) $options['deployment_id'] = (int) substr($arg, 13);
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}

if (!$serverId) {
    die("Usage: php renew-ssl.php <server_id> [--registry=..] [--tag=..] [--deployment=id]\n");
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
    echo "Renewing SSL for server {$serverId}...\n";
    echo "PID: " . getmypid() . "\n";
    $result = $svc->renewSsl($serverId, $options);

    if (!empty($result['success'])) {
        echo "Done — HTTPS active.\n";
        exit(0);
    }
    echo "SSL not enabled: " . ($result['error'] ?? 'no resolvable domains / issuance failed') . "\n";
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[UNCAUGHT] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
