<?php
/**
 * vpsadmin-reconcile-sites
 *
 * One-shot cron entry that drives the {@see ReconcilerService}. Scans
 * eligible `sites` rows, probes their real-world artifacts, and enqueues
 * RECONCILE jobs for any drift it can safely auto-remediate.
 *
 * Designed to run from a systemd timer or cron every 5 minutes. Exits 0
 * regardless of how many sites were reconciled, so a tick that finds
 * nothing wrong does not page anyone.
 *
 * Usage:
 *   reconcile-sites.php                       run with default batch size
 *   reconcile-sites.php --batch=N             cap sites scanned this tick
 *   reconcile-sites.php --json                machine-readable output (for monitoring)
 *   reconcile-sites.php --dry-run             probe + assess but DO NOT enqueue
 *   reconcile-sites.php --help                print this and exit
 *
 * Exit codes:
 *   0  - tick completed (jobs enqueued count may be zero)
 *   1  - bootstrap failed (DB unreachable, adapters not wired, etc.)
 *
 * Cron entry (every 5 minutes, recommended):
 *   * /5 * * * *  root  /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/agent/reconcile-sites.php --json >> /var/log/flowone/reconciler.log 2>&1
 *
 * The reconciler is safe to run concurrently with itself thanks to:
 *   - JobDispatcher's dedup-by-domain check that refuses to enqueue a
 *     second job while a previous one is still queued/running,
 *   - SiteStateMachine's transactional state guard that rejects
 *     concurrent state transitions.
 *
 * That said, scheduling more than one tick per minute is wasteful: the
 * probes hit the filesystem, MariaDB, and /etc/passwd for every site.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "reconcile-sites must run from CLI\n");
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

use VpsAdmin\Agent\Provisioner\Adapters\Adapters;
use VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\MysqlAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\NasAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\OlsAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Provisioner\Adapters\SftpAdapter;
use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobDispatcher;
use VpsAdmin\Agent\Provisioner\Reconciler\DriftAssessor;
use VpsAdmin\Agent\Provisioner\Reconciler\ReconcilerService;
use VpsAdmin\Agent\Provisioner\Reconciler\SiteProber;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

$opts = getopt('', ['batch:', 'json', 'dry-run', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2200));
    exit(0);
}

$batch = isset($opts['batch']) ? max(1, (int) $opts['batch']) : 200;
$json = isset($opts['json']);
$dryRun = isset($opts['dry-run']);

// --- Bootstrap ---
try {
    $db = PanelDatabase::fromDefaultConfigFiles();
    $db->pdo()->query('SELECT 1');

    $masker = new SecretMasker();
    $audit = new AuditLogger($db, $masker);
    $dispatcher = new JobDispatcher($db, $masker, $audit);
    $stateMachine = new SiteStateMachine($db, $audit);

    // Adapters. The reconciler is read-only by design so the
    // FilesystemAdapter doesn't need a real allowedRoots list; the
    // existence probes never call destructive methods.
    $runner = new ProcessCommandRunner();
    $fs = new FilesystemAdapter($runner, ['/home', '/usr/local/lsws/conf/vhosts']);
    $ols = new OlsAdapter($runner, $fs);
    // The reconciler is read-only against MariaDB (it only calls
    // databaseExists / userExists / hasAllPrivilegesOn) so even a
    // narrowly-scoped account would technically work. We still go
    // through the admin resolver so a single source of truth governs
    // every CLI entrypoint. See Support/MysqlAdminCredentials.
    $mysqlCredentials = MysqlAdminCredentials::providerFromDefaultConfigFiles();
    $mysql = new MysqlAdapter($runner, $mysqlCredentials);
    $sftp = new SftpAdapter($runner);
    $nas = new NasAdapter($runner);
    $adapters = new Adapters($runner, $fs, $ols, $mysql, $sftp, $nas);

    $prober = new SiteProber($adapters);
    $assessor = new DriftAssessor();

    // Dry-run is handled inside ReconcilerService (it skips every write -
    // enqueue, state transition, and metric column write). We must NOT try
    // to subclass JobDispatcher here: it is `final`, so an anonymous
    // subclass fatals at declaration time and the whole --dry-run path dies
    // silently.
    $reconciler = new ReconcilerService(
        database: $db,
        dispatcher: $dispatcher,
        stateMachine: $stateMachine,
        audit: $audit,
        prober: $prober,
        assessor: $assessor,
        batchSize: $batch,
        dryRun: $dryRun,
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[reconciler] bootstrap failed: '
        . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// --- Run ---
$actor = ActorContext::reconciler('reconcile-' . bin2hex(random_bytes(4)));

try {
    $result = $reconciler->scan($actor);
} catch (\Throwable $e) {
    fwrite(STDERR, '[reconciler] scan threw: '
        . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    if (!$json) {
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}

if ($json) {
    echo json_encode($result->toSummary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    fwrite(STDOUT, sprintf(
        "[reconciler] scanned=%d healthy=%d reconciled=%d healed=%d degraded=%d skipped=%d (%dms)\n",
        $result->sitesScanned,
        $result->sitesHealthy,
        $result->sitesReconciled,
        $result->sitesHealed,
        $result->sitesDegraded,
        $result->sitesSkipped,
        $result->durationMs(),
    ));
    if ($result->enqueuedJobIds !== []) {
        fwrite(STDOUT, "  enqueued jobs: " . implode(',', $result->enqueuedJobIds) . "\n");
    }
    if ($result->skippedEnqueues !== []) {
        fwrite(STDOUT, "  skipped enqueues: " . count($result->skippedEnqueues) . "\n");
        foreach ($result->skippedEnqueues as $s) {
            fwrite(STDOUT, sprintf(
                "    %s: %s (missing: %s)\n",
                $s['domain'],
                $s['reason'],
                implode(',', $s['missing'] ?? []),
            ));
        }
    }
}

exit(0);
