<?php
/**
 * CLI runner for Docker Compose provisioning (Phase D, native->docker migration).
 *
 * The compose-native counterpart to cli/provision.php: instead of the ~30-step
 * native install, it renders the per-host .env, ships docker-compose.yml, pulls
 * the pre-built images and brings the stack up on the target via SSH.
 *
 * Deliberately a separate entry point so the Docker path runs IN PARALLEL with
 * (and never disturbs) the native provisioner during cutover. Validate on the
 * Phase E Linux staging box before wiring into the live dashboard.
 *
 * Usage:
 *   php provision-docker.php <server_id> [options]
 *   php provision-docker.php <server_id> --services=web,collab --tag=a1b2c3d
 *   php provision-docker.php <server_id> --update-service=web           (legacy alias)
 *
 * Options:
 *   --no-ssl                 render an HTTP-only .env (ENABLE_SSL=0)
 *   --registry=<ref>         image registry/namespace (default: config docker.registry or 'flowone')
 *   --tag=<tag>              image tag (default: config docker.tag or 'latest')
 *   --compose=<path>         explicit docker-compose.yml source on the Fleet host
 *   --skip-docker-install    assume Docker Engine + compose plugin already present
 *   --wait=<seconds>         health-wait timeout (default 180)
 *   --deployment=<id>        deployments row to stream progress/log into (dashboard)
 *   --services=<a,b,c>       Docker Update: pull+up ONLY these services (at --tag)
 *   --update-service=<svc>   legacy alias for --services with a single service
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

$serverId = (int) ($argv[1] ?? 0);
$options = [];
$updateServices = null; // null = full provision; array = Docker Update of those services

foreach (array_slice($argv, 2) as $arg) {
    if ($arg === '--no-ssl') $options['enable_ssl'] = false;
    elseif ($arg === '--skip-docker-install') $options['skip_docker_install'] = true;
    elseif (str_starts_with($arg, '--registry=')) $options['registry'] = substr($arg, 11);
    elseif (str_starts_with($arg, '--tag=')) $options['tag'] = substr($arg, 6);
    elseif (str_starts_with($arg, '--compose=')) $options['compose_source'] = substr($arg, 10);
    elseif (str_starts_with($arg, '--wait=')) $options['wait_timeout'] = (int) substr($arg, 7);
    elseif (str_starts_with($arg, '--deployment=')) $options['deployment_id'] = (int) substr($arg, 13);
    elseif (str_starts_with($arg, '--services=')) $updateServices = array_values(array_filter(array_map('trim', explode(',', substr($arg, 11)))));
    elseif (str_starts_with($arg, '--update-service=')) $updateServices = [trim(substr($arg, 17))];
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}

if (!$serverId) {
    die("Usage: php provision-docker.php <server_id> [--no-ssl] [--registry=..] [--tag=..] "
        . "[--compose=..] [--skip-docker-install] [--wait=secs] [--deployment=id] "
        . "[--services=a,b,c] [--update-service=svc]\n");
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

$migrationsPath = __DIR__ . '/../../database/migrations';
if (is_dir($migrationsPath)) {
    $migrationService = new \FleetManager\Api\Services\MigrationService($container->getDatabase(), $migrationsPath);
    $migResult = $migrationService->runPendingMigrations();
    if (!empty($migResult['applied'])) {
        echo "Migrations applied: " . implode(', ', $migResult['applied']) . "\n";
    }
}

try {
    /** @var DockerProvisioningService $svc */
    $svc = $container->get(DockerProvisioningService::class);

    if ($updateServices !== null) {
        echo "Updating services [" . implode(', ', $updateServices) . "] on server {$serverId}...\n";
        echo "PID: " . getmypid() . "\n";
        $result = $svc->updateService($serverId, $updateServices, $options);
    } else {
        echo "Docker-provisioning server {$serverId}...\n";
        echo "PID: " . getmypid() . "\n";
        $result = $svc->provisionDocker($serverId, $options);
    }

    if (!empty($result['success'])) {
        echo "Done.\n";
        exit(0);
    }
    echo "Failed: " . ($result['error'] ?? 'unhealthy stack') . "\n";
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[UNCAUGHT] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
