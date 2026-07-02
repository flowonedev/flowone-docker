<?php
/**
 * vpsadmin-worker-daemon
 *
 * Long-running process that drives the site_jobs queue.
 *
 * Loops on JobWorker::tickOnce(), claims one job at a time with a
 * lease, runs the saga end-to-end, persists the outcome. Designed to
 * run under systemd (Type=simple, Restart=on-failure).
 *
 * Usage:
 *   worker-daemon.php                              run forever (systemd)
 *   worker-daemon.php --once                       process a single job then exit
 *   worker-daemon.php --max-jobs=N                 exit after N jobs (for warm-start tests)
 *   worker-daemon.php --jobs-per-worker=N          rotate the worker after N jobs (default 100)
 *   worker-daemon.php --poll-ms=N                  idle backoff in milliseconds (default 1000)
 *   worker-daemon.php --worker-id=NAME             override the worker id (default: hostname+pid)
 *   worker-daemon.php --pause-file=PATH            override the operator pause file
 *   worker-daemon.php --step-isolation             run each step in a forked child (needs pcntl)
 *   worker-daemon.php --help                       print this and exit
 *
 * Env vars:
 *   FLOWONE_STEP_ISOLATION=1                       same as --step-isolation
 *
 * Operator controls (no restart needed):
 *   Pause:   touch /var/lib/flowone/worker.paused
 *   Resume:  rm    /var/lib/flowone/worker.paused
 *   Stats:   kill -USR1 <pid>     (writes one-line stats to stderr / journal)
 *   Stop:    systemctl stop vpsadmin-worker      (graceful, finishes current job)
 *
 * Exit codes:
 *   0  - clean shutdown (SIGTERM / SIGINT / --once / --max-jobs reached)
 *   2  - exceeded rapid-restart ceiling (something is crashing on every boot)
 *   1  - unexpected throwable during bootstrap (DI wiring failed)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "worker-daemon must run from CLI\n");
    exit(1);
}

// Autoload — same prefix as agent.php so we share the class tree.
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
use VpsAdmin\Agent\Provisioner\Orchestrator\ProvisioningSagaRunner;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobWorker;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\WorkerDaemon;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\WorkerSupervisor;
use VpsAdmin\Agent\Provisioner\Orchestrator\StepProcessIsolator;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaRegistry;
use VpsAdmin\Agent\Provisioner\Support\LegacyCacheInvalidator;
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Agent\Provisioner\Support\SiteRowBackfiller;

$opts = getopt('', [
    'once',
    'max-jobs:',
    'jobs-per-worker:',
    'poll-ms:',
    'worker-id:',
    'pause-file:',
    'step-isolation',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2200));
    exit(0);
}

// --- CLI parsing ---
$once = isset($opts['once']);
$maxJobs = isset($opts['max-jobs']) ? (int) $opts['max-jobs'] : 0;
if ($once) {
    $maxJobs = 1;
}
$jobsPerWorker = isset($opts['jobs-per-worker'])
    ? max(1, (int) $opts['jobs-per-worker'])
    : WorkerSupervisor::DEFAULT_JOBS_PER_WORKER;
$pollMs = isset($opts['poll-ms'])
    ? max(0, (int) $opts['poll-ms'])
    : WorkerDaemon::DEFAULT_POLL_INTERVAL_MS;
$workerId = isset($opts['worker-id'])
    ? (string) $opts['worker-id']
    : sprintf('worker-%s-%d', gethostname() ?: 'unknown', getmypid());
$pauseFile = isset($opts['pause-file'])
    ? (string) $opts['pause-file']
    : WorkerDaemon::DEFAULT_PAUSE_FILE;

// --- Bootstrap ---
try {
    $db = PanelDatabase::fromDefaultConfigFiles();
    // Force-touch the connection so a misconfigured DB fails LOUDLY at
    // boot rather than on the first claim.
    $db->pdo()->query('SELECT 1');

    $masker = new SecretMasker();
    $audit = new AuditLogger($db, $masker);
    $vault = new SecretVault($db);
    $capabilities = new ServerCapabilities();

    // Pull DNS/server bindings from the agent config so the saga's
    // DnsZoneCreateStep can stamp A records with the right IP and the
    // configured authoritative nameservers. config.local.php overrides
    // win so a staging box doesn't accidentally publish prod IPs.
    $agentConfigPath = __DIR__ . '/config.php';
    $agentLocalPath = __DIR__ . '/config.local.php';
    $agentConfig = file_exists($agentConfigPath) ? (array) require $agentConfigPath : [];
    if (file_exists($agentLocalPath)) {
        $agentConfig = array_replace_recursive($agentConfig, (array) require $agentLocalPath);
    }
    $serverIp = (string) ($agentConfig['server']['ip'] ?? '');
    // Config file wins; fallback derives ns1/ns2.<this box's base domain>
    // (never a hardcoded operator domain) — see NsDefaults.
    $nsConfig = \VpsAdmin\Agent\Lib\NsDefaults::load();

    // InstallAppStep needs the agent config to spawn a WordPressInstaller
    // when it actually runs. Passing the merged $agentConfig keeps the
    // installer using config.php's paths['ols_bin'] / paths['backups']
    // which matches what AppAction does for /api/apps installs.
    $nsEnabled = !empty($nsConfig['enabled']);
    $registry = new SagaRegistry(
        vhostTemplate: new \VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate(),
        serverIp: $serverIp,
        ns1: $nsEnabled ? (string) $nsConfig['ns1'] : '',
        ns2: $nsEnabled ? (string) $nsConfig['ns2'] : '',
        wordPressInstaller: null,
        appInstallerConfig: $agentConfig,
    );
    $stateMachine = new SiteStateMachine($db, $audit);
    // Backfill keeps the legacy SitesView columns (home_dir,
    // document_root, sftp_user, db_name, php_version, dns_enabled) in
    // sync with the saga's StepState map. Cache invalidator busts the
    // legacy v1 Redis keys so a saga-driven mutation is visible in the
    // legacy UI immediately rather than after the 60s TTL.
    $backfiller = new SiteRowBackfiller($db);
    $legacyCache = LegacyCacheInvalidator::fromDefaultConfigFiles();

    // Step subprocess isolation:
    //   Default off (in-process steps). When enabled via --step-isolation
    //   or the FLOWONE_STEP_ISOLATION env var, each step's execute() and
    //   compensate() runs in a forked child so a PHP fatal in the step
    //   crashes the child rather than the worker. Requires pcntl. See
    //   StepProcessIsolator for the trade-offs.
    $isolationEnabled = isset($opts['step-isolation'])
        || filter_var((string) (getenv('FLOWONE_STEP_ISOLATION') ?: ''), FILTER_VALIDATE_BOOLEAN);
    $isolator = new StepProcessIsolator(enabled: $isolationEnabled);
    if ($isolationEnabled && !$isolator->isEnabled()) {
        fwrite(STDERR, "[worker-daemon] step isolation requested but pcntl unavailable; running in-process\n");
    } elseif ($isolationEnabled) {
        fwrite(STDERR, "[worker-daemon] step subprocess isolation ENABLED\n");
    }

    $sagaRunner = new ProvisioningSagaRunner(
        $stateMachine,
        $audit,
        $backfiller,
        $legacyCache,
        $isolator,
    );

    // ── Adapters bundle ───────────────────────────────────────
    // The worker runs real saga steps that touch the filesystem,
    // OLS config, MariaDB, /etc/passwd, and NAS. Without these we
    // would fail at the first step that calls $ctx->requireAdapters().
    //
    // allowedRoots is the FilesystemAdapter's destructive-op
    // safelist - any rmtree / chown -R / writeAtomic outside these
    // prefixes throws. The list mirrors the systemd unit's
    // ReadWritePaths so PHP and the kernel both refuse the same
    // operations.
    $runner = new ProcessCommandRunner();
    $fs = new FilesystemAdapter($runner, [
        '/home',
        '/usr/local/lsws/conf/vhosts',
        '/var/www/vps-admin/storage/snapshots',
        '/var/www/vps-admin/storage/archives',
        '/var/lib/flowone',
        // MailTeardownStep prunes DKIM keys + SigningTable/KeyTable
        // lines on site delete. Mirrored in vpsadmin-worker.service's
        // ReadWritePaths - keep both lists in sync.
        '/etc/opendkim',
    ]);
    $ols = new OlsAdapter($runner, $fs);
    // MySQL admin credentials are deliberately resolved separately from
    // the panel DB credentials: the panel user (vpsadmin@localhost) is
    // narrowly scoped to devc_vps_dash.* and CANNOT run CREATE DATABASE
    // / CREATE USER / GRANT, which the saga's DB steps need. The
    // resolver checks (1) `database_admin` in config.local.php, then
    // (2) /root/.my.cnf, then (3) falls back to the panel user with a
    // loud stderr warning. See Support/MysqlAdminCredentials for the
    // full rationale.
    $mysqlCredentials = MysqlAdminCredentials::providerFromDefaultConfigFiles();
    $mysql = new MysqlAdapter($runner, $mysqlCredentials);
    $sftp = new SftpAdapter($runner);
    $nas = new NasAdapter($runner);
    // SslAdapter wraps `certbot` for HTTP-01 webroot issuance + revoke.
    // SSL_ISSUE / SSL_REVOKE steps look it up off the adapters bundle;
    // when null they degrade to a skip-with-warning rather than fail.
    // OlsRestartCoordinator stays null here for the same reason it has
    // since wave 1: SslIssueStep + OlsRestartStep both fall back to
    // direct OlsAdapter::restart() when the coordinator is missing.
    $ssl = new \VpsAdmin\Agent\Provisioner\Adapters\SslAdapter($runner);
    $adapters = new Adapters(
        runner: $runner,
        fs: $fs,
        ols: $ols,
        mysql: $mysql,
        sftp: $sftp,
        nas: $nas,
        ssl: $ssl,
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[worker-daemon] bootstrap failed: '
        . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// --- Worker factory ---
// Re-invoked by the supervisor each time it rotates. Capturing the
// shared services by reference is intentional: the connection and
// capabilities cache are warm across rotations; only the worker's
// own instance state is fresh.
$workerFactory = static function () use (
    $db, $masker, $vault, $audit, $capabilities,
    $registry, $sagaRunner, $workerId, $adapters
): JobWorker {
    return new JobWorker(
        database: $db,
        masker: $masker,
        vault: $vault,
        audit: $audit,
        capabilities: $capabilities,
        registry: $registry,
        runner: $sagaRunner,
        workerId: $workerId,
        adapters: $adapters,
    );
};

// --- One-shot / bounded run path ---
if ($maxJobs > 0) {
    fwrite(STDERR, "[worker-daemon] one-shot mode: max-jobs={$maxJobs}\n");
    $worker = $workerFactory();
    $daemon = new WorkerDaemon(
        worker: $worker,
        pollIntervalMs: $pollMs,
        pauseFile: $pauseFile,
        installSignalHandlers: true,
    );
    $daemon->runUntil(
        static fn(WorkerDaemon $d) => $d->stats()->jobsTouched() >= $maxJobs
    );
    fwrite(STDERR, '[worker-daemon] final stats: ' . $daemon->stats()->toLine() . PHP_EOL);
    exit(0);
}

// --- Production: supervised forever-loop ---
fwrite(STDERR, "[worker-daemon] starting; worker-id={$workerId} poll-ms={$pollMs} "
    . "jobs-per-worker={$jobsPerWorker} pause-file={$pauseFile}\n");

$supervisor = new WorkerSupervisor(
    workerFactory: $workerFactory,
    jobsPerWorker: $jobsPerWorker,
    pollIntervalMs: $pollMs,
    pauseFile: $pauseFile,
    installSignalHandlers: true,
);

$exitCode = $supervisor->run();
fwrite(STDERR, "[worker-daemon] supervisor exited with code {$exitCode} "
    . "(total_restarts={$supervisor->totalRestarts()})\n");
exit($exitCode);
