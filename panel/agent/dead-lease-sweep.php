<?php
/**
 * vpsadmin-dead-lease-sweep
 *
 * One-shot script that scans site_jobs for rows in `status=running`
 * whose lease has expired (i.e. the worker that claimed them died),
 * and moves those rows back to `status=queued` so a fresh worker can
 * re-claim them.
 *
 * Designed to run from a systemd timer (every minute) or cron. Exits
 * 0 on success regardless of whether any rows were recovered.
 *
 * Usage:
 *   dead-lease-sweep.php                      sweep with default grace (10s)
 *   dead-lease-sweep.php --grace=N            override grace seconds
 *   dead-lease-sweep.php --limit=N            cap rows scanned in one pass
 *   dead-lease-sweep.php --dry-run            list stale rows but don't recover them
 *   dead-lease-sweep.php --json               machine-readable output
 *   dead-lease-sweep.php --help               print this and exit
 *
 * Exit codes:
 *   0  - sweep completed (recovered count may be zero)
 *   1  - bootstrap failed (DB unreachable, etc.)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "dead-lease-sweep must run from CLI\n");
    exit(1);
}

spl_autoload_register(function (string $class): void {
    $prefix = 'VpsAdmin\\Agent\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\DeadLeaseSweeper;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

$opts = getopt('', ['grace:', 'limit:', 'dry-run', 'json', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1400));
    exit(0);
}

$grace = isset($opts['grace'])
    ? max(0, (int) $opts['grace'])
    : DeadLeaseSweeper::DEFAULT_GRACE_SECONDS;
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;
$dryRun = isset($opts['dry-run']);
$json = isset($opts['json']);

try {
    $db = PanelDatabase::fromDefaultConfigFiles();
    $db->pdo()->query('SELECT 1');
    $masker = new SecretMasker();
    $audit = new AuditLogger($db, $masker);
    $sweeper = new DeadLeaseSweeper($db, $audit, $grace);
} catch (\Throwable $e) {
    fwrite(STDERR, '[dead-lease-sweep] bootstrap failed: '
        . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($dryRun) {
    $stale = $sweeper->listStale($limit);
    if ($json) {
        echo json_encode([
            'mode' => 'dry-run',
            'stale_count' => count($stale),
            'rows' => $stale,
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        fwrite(STDOUT, "[dead-lease-sweep] dry-run: " . count($stale) . " stale row(s)\n");
        foreach ($stale as $row) {
            fwrite(STDOUT, sprintf(
                "  job=%d domain=%s worker=%s lease_until=%s attempts=%d\n",
                $row['id'], $row['site_domain'],
                $row['locked_by'] ?? '(null)',
                $row['lease_until'] ?? '(null)',
                $row['attempts'] ?? 0,
            ));
        }
    }
    exit(0);
}

$result = $sweeper->sweep($limit);

if ($json) {
    echo json_encode($result->toArray(), JSON_PRETTY_PRINT) . "\n";
} else {
    fwrite(STDOUT, "[dead-lease-sweep] " . $result->summary() . PHP_EOL);
    foreach ($result->recoveries as $r) {
        fwrite(STDOUT, sprintf(
            "  recovered: job=%d domain=%s dead-worker=%s attempts=%d\n",
            $r['job_id'], $r['site_domain'],
            $r['dead_worker'] ?? '(null)', $r['attempts'],
        ));
    }
}
exit(0);
