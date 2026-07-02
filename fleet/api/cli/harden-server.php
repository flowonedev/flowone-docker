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
 *   php harden-server.php <server_id> [--docker] [--deployment=<id>]
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$serverId = (int) ($argv[1] ?? 0);
$dockerHost = in_array('--docker', $argv, true);
$deploymentId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--deployment=')) {
        $deploymentId = (int) substr($arg, strlen('--deployment='));
    }
}
if (!$serverId) {
    die("Usage: php harden-server.php <server_id> [--docker] [--deployment=<id>]\n");
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
$db = $container->getDatabase();

/** Best-effort deployment row bookkeeping (never fails the hardening itself). */
$mark = function (?string $status, ?int $progress, ?string $step, ?string $log = null) use ($db, $deploymentId): void {
    if (!$deploymentId) return;
    try {
        $sets = ['last_heartbeat = NOW()'];
        $params = [];
        if ($status !== null)   { $sets[] = 'status = ?';       $params[] = $status; }
        if ($progress !== null) { $sets[] = 'progress = ?';     $params[] = $progress; }
        if ($step !== null)     { $sets[] = 'current_step = ?'; $params[] = $step; }
        if ($log !== null)      { $sets[] = 'log = CONCAT(COALESCE(log, ""), ?)'; $params[] = $log; }
        if ($status === 'running') {
            $sets[] = 'started_at = COALESCE(started_at, NOW())';
            $sets[] = 'pid = ?';
            $params[] = getmypid();
        }
        if ($status === 'success' || $status === 'failed') {
            $sets[] = 'completed_at = NOW()';
        }
        $params[] = $deploymentId;
        $db->prepare('UPDATE deployments SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    } catch (\Throwable $e) {
        // best-effort
    }
};

try {
    /** @var ProvisioningService $svc */
    $svc = $container->get(ProvisioningService::class);
    echo "Hardening server {$serverId}" . ($dockerHost ? ' (Docker host)' : '') . "...\n";
    $mark('running', 10, 'Hardening (firewall + fail2ban + SSH lockdown)...');

    $res = $svc->hardenExistingServer($serverId, ['docker' => $dockerHost]);
    $logText = implode("\n", $res['log'] ?? []) . "\n";

    if (!empty($res['success'])) {
        $p = $res['hardened'] ?? null;
        if ($p) {
            echo "\nHardened profile: {$p['user']}@{$p['port']} (auth={$p['auth']})\n";
            echo "Fleet stored connection re-homed to this profile.\n";
        }
        if ($dockerHost) {
            echo 'Docker stack: ' . (($res['docker_ok'] ?? false) ? 'healthy' : 'NOT healthy — inspect the box') . "\n";
        }
        $step = $p ? "Hardened: {$p['user']}@{$p['port']}" : 'Hardening completed';
        $mark('success', 100, $step, $logText);
        echo "Done.\n";
        exit(0);
    }
    $mark('failed', null, 'Hardening failed: ' . substr((string) ($res['error'] ?? 'unknown'), 0, 120), $logText);
    echo "\nFailed: " . ($res['error'] ?? 'unknown error') . "\n";
    exit(1);
} catch (\Throwable $e) {
    $mark('failed', null, 'Error: ' . substr($e->getMessage(), 0, 120), $e->getMessage() . "\n");
    fwrite(STDERR, "\n[UNCAUGHT] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
