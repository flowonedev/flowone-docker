#!/usr/bin/env php
<?php
/**
 * flowone-reclaim-daemon
 *
 * Phase 6c — Long-running reclaim daemon. Watches StorageBudget and
 * proactively triggers tier-down + sweep when watermark crosses
 * WM_HIGH, instead of waiting for the hourly cron.
 *
 * Runs alongside the cron (drive-tier-down.php); both share the same
 * per-file MountLock so they cannot race on the same row.
 *
 * Refuses to start when phase6c_reclaim_daemon=false. Safe to deploy
 * the systemd unit before flipping the kill switch — the daemon will
 * publish a single "kill switch off" state and exit 0.
 *
 * Usage:
 *   reclaim-daemon.php                run forever (systemd Type=simple)
 *   reclaim-daemon.php --once         run a single tick + reclaim cycle, exit
 *   reclaim-daemon.php --dry-run      run forever, but never actually
 *                                     tier down (cycle is no-op)
 *   reclaim-daemon.php --help         usage
 *
 * Operator controls (read live from filesystem on each tick):
 *   Pause:  touch /var/lib/flowone/reclaim.paused
 *   Resume: rm    /var/lib/flowone/reclaim.paused
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "reclaim-daemon must run from CLI\n");
    exit(1);
}

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'FlowOne\\Storage\\')) {
        return;
    }
    $relative = substr($class, strlen('FlowOne\\Storage\\'));
    $path = __DIR__ . '/../src/Storage/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use FlowOne\Storage\Config;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\Invariants;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\ReclaimDaemon;

const RECLAIM_TENANT = 'email-drive';

$opts = parseOpts($argv);
if (!empty($opts['help'])) {
    printHelp();
    exit(0);
}

$config = Config::load();

// Pre-flight: kill switch must be on to enter the loop. (The daemon
// itself will also handle this gracefully, but we surface it loudly
// here so systemd journalctl shows the reason clearly.)
$killSwitchOn = (bool) ($config['phases']['phase6c_reclaim_daemon'] ?? false);
if (!$killSwitchOn && empty($opts['force'])) {
    fwrite(STDERR, "[reclaim-daemon] phase6c_reclaim_daemon=false — refusing to loop. "
                 . "Either flip the flag or pass --force (for one-shot testing only).\n");
    exit(0);
}

// HMAC key + journal.
$signer = HmacSigner::fromKeyFile(
    (string) $config['state']['hmac_key_path'],
    (int)    $config['state']['hmac_key_mode_max']
);
$journal = new OperationJournal(
    (string) $config['journal']['path'],
    $signer,
    0
);
$invariants = new Invariants($journal, strict: (bool) ($config['strict_invariants'] ?? false));

// Pre-flight: DB connection. The daemon needs a PDO for
// findTierDownCandidates() and the logical layer of StorageBudget.
//
// The shared library doesn't dictate where the email backend's
// DB config lives — we look it up via the same path the email
// cron uses.
$pdo = openPdo();

// Pre-flight: VPS drive base path. Picked from the email config so we
// don't have to duplicate the constant.
$vpsBase = resolveVpsBase();

// Destructive sweep is gated by its own flag, same as the cron.
$destructive = (bool) ($config['phases']['phase5_tier_down_destructive'] ?? false);

$daemon = ReclaimDaemon::build(
    pdo:                $pdo,
    journal:            $journal,
    invariants:         $invariants,
    signer:             $signer,
    config:             $config,
    tenant:             RECLAIM_TENANT,
    vpsBase:            $vpsBase,
    destructiveEnabled: $destructive,
);

// Signal handling: SIGTERM / SIGINT request a graceful stop. SIGHUP
// is reserved for future config-reload work; for now it just logs.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = function (int $signo) use ($daemon) {
        $daemon->requestStop($signo);
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT,  $handler);
}

// PID file (best-effort). Allows storage-ctl to report whether the
// daemon process is actually running, distinct from "kill switch
// is on but systemd hasn't started us yet".
$pidPath = rtrim((string) $config['state']['dir'], '/') . '/'
         . (string) ($config['tier']['reclaim']['pid_file'] ?? 'reclaim-daemon.pid');
@file_put_contents($pidPath, (string) getmypid());
register_shutdown_function(function () use ($pidPath) {
    if (is_file($pidPath)) @unlink($pidPath);
});

if (!empty($opts['once'])) {
    // One tick + one cycle (if shouldReclaim fires) then exit. Useful
    // for smoke-testing in CI / locally without keeping a process up.
    $daemon->requestStop();
}

exit($daemon->run());

// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = ['help' => false, 'once' => false, 'dry_run' => false, 'force' => false];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--help' || $a === '-h') { $opts['help'] = true; continue; }
        if ($a === '--once')                { $opts['once'] = true; continue; }
        if ($a === '--dry-run')             { $opts['dry_run'] = true; continue; }
        if ($a === '--force')               { $opts['force'] = true; continue; }
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
flowone-reclaim-daemon (Phase 6c)

Usage:
  reclaim-daemon.php                run forever (systemd Type=simple)
  reclaim-daemon.php --once         run a single tick + reclaim cycle, exit
  reclaim-daemon.php --dry-run      run forever but never actually tier down
  reclaim-daemon.php --force        bypass phase6c kill-switch refusal (one-shot only)
  reclaim-daemon.php --help         this message

Operator pause flag: touch /var/lib/flowone/reclaim.paused

TXT;
}

/**
 * Open a PDO to the email backend's MySQL database. Walks the same
 * config path the existing email crons use so behaviour is consistent.
 *
 * The email config reads credentials via getenv(), so we must load
 * /var/www/vps-email/backend/.env into the process environment BEFORE
 * we require config.php. systemd doesn't pass shell env vars to
 * Type=simple units, and we don't want to duplicate every env var in
 * the unit file.
 *
 * The config exposes credentials under db.name / db.pass (NOT
 * db.database / db.password); we honour both spellings so a future
 * config refactor doesn't silently break the daemon.
 */
function openPdo(): \PDO
{
    $emailConfigPath = '/var/www/vps-email/backend/src/config.php';
    if (!is_file($emailConfigPath)) {
        fwrite(STDERR, "[reclaim-daemon] cannot find email config at {$emailConfigPath}\n");
        exit(2);
    }
    loadDotEnv('/var/www/vps-email/backend/.env');

    /** @var array $appConfig */
    $appConfig = require $emailConfigPath;
    $db   = $appConfig['db'] ?? [];
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int)    ($db['port'] ?? 3306);
    $name = (string) ($db['name']     ?? $db['database'] ?? '');
    $user = (string) ($db['user']     ?? '');
    $pass = (string) ($db['pass']     ?? $db['password'] ?? '');
    if ($name === '' || $user === '') {
        fwrite(STDERR, "[reclaim-daemon] email config missing db.name / db.user "
                     . "(loaded={$emailConfigPath}, host={$host} port={$port})\n");
        exit(2);
    }
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    try {
        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_PERSISTENT => false,
        ]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[reclaim-daemon] db connect failed: {$e->getMessage()}\n");
        exit(2);
    }
}

function resolveVpsBase(): string
{
    $emailConfigPath = '/var/www/vps-email/backend/src/config.php';
    if (is_file($emailConfigPath)) {
        loadDotEnv('/var/www/vps-email/backend/.env');
        $appConfig = require $emailConfigPath;
        $base = (string) ($appConfig['drive']['storage_path'] ?? '');
        if ($base !== '') {
            return rtrim($base, '/');
        }
    }
    return '/var/www/vps-email/storage/drive';
}

/**
 * Minimal .env loader. Mirrors the logic in the email cron
 * bootstrap (email/backend/cron/bootstrap.php) so the daemon
 * sees the same env vars as cron-driven workers.
 *
 * Idempotent: existing env vars are not overwritten, so anything
 * already set by systemd EnvironmentFile= wins.
 */
function loadDotEnv(string $envFile): void
{
    static $loaded = [];
    if (isset($loaded[$envFile])) return;
    $loaded[$envFile] = true;

    if (!is_file($envFile) || !is_readable($envFile)) return;
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((strlen($value) > 1) && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}
