<?php
/**
 * CLI: HARDEN an already-provisioned server — the security lockdown the native
 * full-provision does, applied to a box that was brought up via the Docker path
 * (which previously skipped it):
 *
 *   - fail2ban + firewalld installed
 *   - firewall configured (Docker profile: SSH + web + mail ports only)
 *   - fail2ban jails deployed (SSH brute-force protection, Fleet IP whitelisted)
 *   - SSH hardened: pxr user (key + passwordless sudo), port moved to 1985,
 *     root login denied, password auth denied
 *
 * SAFE: reuses ProvisioningService's 3-phase verify-before-commit — root@22 stays
 * open until pxr@1985 + key + sudo is proven from THIS host, so it can't lock you
 * out. Fleet's stored connection is re-homed to pxr@1985 on success. Idempotent.
 *
 * On a Docker host (--docker) it restarts Docker at the end so the container
 * published-port rules survive firewalld taking over iptables, then verifies web.
 *
 * Usage:
 *   php harden-server.php <server_id> [--docker]
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$serverId = (int) ($argv[1] ?? 0);
$dockerHost = in_array('--docker', $argv, true);
if (!$serverId) {
    die("Usage: php harden-server.php <server_id> [--docker]\n");
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
    echo "Hardening server {$serverId}" . ($dockerHost ? ' (Docker host)' : '') . "...\n";
    $res = $svc->hardenExistingServer($serverId, ['docker' => $dockerHost]);

    if (!empty($res['success'])) {
        $p = $res['hardened'] ?? null;
        if ($p) {
            echo "\nHardened profile: {$p['user']}@{$p['port']} (auth={$p['auth']})\n";
            echo "Fleet stored connection re-homed to this profile.\n";
        }
        if ($dockerHost) {
            echo 'Docker stack: ' . (($res['docker_ok'] ?? false) ? 'healthy' : 'NOT healthy — inspect the box') . "\n";
        }
        echo "Done.\n";
        exit(0);
    }
    echo "\nFailed: " . ($res['error'] ?? 'unknown error') . "\n";
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[UNCAUGHT] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
