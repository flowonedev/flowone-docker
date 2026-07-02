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
 *   php update-panel.php <server_id> [--deployment=<id>]
 *
 * Uploads packages/panel/panel-latest.tar.gz to the target and re-runs its
 * install.sh (idempotent). Docker boxes automatically get --db-host=127.0.0.1.
 * With --deployment the script streams status + the final log into that
 * deployments row so the dashboard shows the update like any other deploy.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$serverId = (int) ($argv[1] ?? 0);
$deploymentId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--deployment=')) {
        $deploymentId = (int) substr($arg, strlen('--deployment='));
    }
}
if (!$serverId) {
    die("Usage: php update-panel.php <server_id> [--deployment=<id>]\n");
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

/** Best-effort deployment row bookkeeping (never fails the update itself). */
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

/**
 * ProvisioningService log entries are ['time' => ..., 'message' => ...] arrays;
 * a bare implode() casts each to the literal string "Array" and the dashboard
 * log becomes useless. Flatten to "[time] message" lines.
 */
$flattenLog = fn(array $entries): string => implode("\n", array_map(
    fn($e) => is_array($e)
        ? '[' . ($e['time'] ?? '') . '] ' . ($e['message'] ?? '')
        : (string) $e,
    $entries
)) . "\n";

try {
    /** @var ProvisioningService $svc */
    $svc = $container->get(ProvisioningService::class);
    echo "Updating panel on server {$serverId}...\n";
    $mark('running', 10, 'Updating panel...');

    $result = $svc->updatePanel($serverId);
    $logText = $flattenLog($result['log'] ?? []);

    if (!empty($result['success'])) {
        $mark('success', 100, 'Panel update completed', $logText);
        echo "Done.\n";
        exit(0);
    }
    $mark('failed', null, 'Panel update failed: ' . substr((string) ($result['error'] ?? 'unknown'), 0, 120), $logText);
    echo "Failed: " . ($result['error'] ?? 'unknown') . "\n";
    exit(1);
} catch (\Throwable $e) {
    $mark('failed', null, 'Error: ' . substr($e->getMessage(), 0, 120), $e->getMessage() . "\n");
    fwrite(STDERR, "\n[UNCAUGHT] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
