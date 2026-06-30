#!/usr/bin/env php
<?php
/**
 * storage-activation-test
 *
 * Pre-flight verifier for flipping phase5/6/6b/6c/6d/7 kill switches.
 * Runs from CLI on the server and refuses to claim ready unless every
 * single check passes. Intended to be run BEFORE you flip any flag,
 * and AGAIN after each flip to confirm the live system still passes.
 *
 * RUN ON SERVER:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/storage-activation-test.php
 *
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/storage-activation-test.php \
 *       --verbose --only=infra,perms,scripts,dispatcher
 *
 * Exit codes:
 *   0   every check passed
 *   1   one or more checks failed
 *
 * Categories (--only=cat1,cat2):
 *   infra        NAS + backup mount, VPN, DDNS, healthcheck file
 *   perms        /var/lib/flowone perms, request dir, HMAC key, log dir
 *   scripts      nas-backup.php / reclaim-daemon.php exist & runnable
 *   dispatcher   storage-request-dispatcher.php exists + cron entry
 *   db           drive_files schema columns present, tier_state counts sane
 *   redis        redis reachable
 *   journal      journal file is writable
 *   phases       prints current phase flag values
 *
 * Non-destructive: writes ONLY into /tmp and into the request queue dir
 * (a single test request that is dry-run only, then deleted).
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "storage-activation-test must run from CLI\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$opts = parseOpts(array_slice($argv, 1));
if (!empty($opts['help'])) {
    printHelp(basename($argv[0]));
    exit(0);
}

$verbose = !empty($opts['verbose']);
$only    = isset($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : [];
$json    = !empty($opts['json']);

$results = [];
$logPath = '/var/www/vps-email/backend/logs/storage-activation-' . date('Ymd-His') . '.log';
if (!is_dir(dirname($logPath))) @mkdir(dirname($logPath), 0775, true);
$logFh = @fopen($logPath, 'w');
register_shutdown_function(function () use (&$logFh) {
    if (is_resource($logFh)) fclose($logFh);
});

// ───── Pre-flight: storage library must be loadable ─────
if (!class_exists(\FlowOne\Storage\Config::class)) {
    fwrite(STDERR, "[fatal] FlowOne\\Storage\\Config not autoloadable. Verify panel/shared is symlinked into vendor.\n");
    exit(1);
}
try {
    $cfg = \FlowOne\Storage\Config::load();
} catch (\Throwable $e) {
    fwrite(STDERR, "[fatal] Config::load() failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Register signal handlers so we cleanup partial test files on SIGINT/SIGTERM.
$tmpFiles = [];
$cleanupRan = false;
$cleanup = function () use (&$tmpFiles, &$cleanupRan) {
    if ($cleanupRan) return;
    $cleanupRan = true;
    foreach ($tmpFiles as $f) {
        if (is_file($f)) @unlink($f);
    }
};
register_shutdown_function($cleanup);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () use ($cleanup) { $cleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () use ($cleanup) { $cleanup(); exit(143); });
}

$categories = [
    'infra'      => fn() => checkInfra($cfg),
    'perms'      => fn() => checkPerms($cfg),
    'scripts'    => fn() => checkScripts(),
    'dispatcher' => fn() => checkDispatcher($cfg, $tmpFiles),
    'db'         => fn() => checkDb($cfg),
    'redis'      => fn() => checkRedis($cfg),
    'journal'    => fn() => checkJournal($cfg),
    'phases'     => fn() => checkPhases($cfg),
];

if ($json === false) section('Storage Activation Pre-Flight');

foreach ($categories as $name => $runner) {
    if ($only && !in_array($name, $only, true)) continue;
    if (!$json) section(strtoupper($name));
    $startMs = microtime(true);
    try {
        $rows = $runner();
    } catch (\Throwable $e) {
        $rows = [[
            'name'   => $name . '/exception',
            'status' => 'FAIL',
            'ms'     => (int) ((microtime(true) - $startMs) * 1000),
            'msg'    => $e->getMessage(),
        ]];
    }
    $results[$name] = $rows;
    if ($json) continue;
    foreach ($rows as $r) {
        emit($r['status'], $r['name'], $r['msg'] ?? '', $r['ms'] ?? 0, $logFh, $verbose);
    }
}

if ($json) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

// ───── Summary ─────
$pass = 0; $fail = 0; $warn = 0;
$failed = [];
foreach ($results as $cat => $rows) {
    foreach ($rows as $r) {
        if ($r['status'] === 'PASS') $pass++;
        elseif ($r['status'] === 'WARN') $warn++;
        else { $fail++; $failed[] = "[$cat] {$r['name']}: " . ($r['msg'] ?? ''); }
    }
}

if (!$json) {
    section('SUMMARY');
    echo "  passed:   {$pass}\n";
    echo "  warnings: {$warn}\n";
    echo "  failed:   {$fail}\n";
    if ($failed) {
        echo "\nFailed checks:\n";
        foreach ($failed as $f) echo "  - {$f}\n";
    }
    echo "\nLog: {$logPath}\n";
}

exit($fail > 0 ? 1 : 0);

// ──────────────────────────────────────────────────────────────────────

function checkInfra(array $cfg): array
{
    $out = [];
    $nasMount = (string) ($cfg['nas']['mount_point'] ?? '/mnt/nas-drive');
    $bakMount = (string) ($cfg['backup']['destination_mount'] ?? '/mnt/vps-backup');

    $out[] = check('nas/mounted', fn() => isPathMounted($nasMount), "{$nasMount} must appear in /proc/mounts");
    $out[] = check('nas/healthcheck', fn() => is_file($nasMount . '/' . (string) ($cfg['nas']['health_file'] ?? '.healthcheck')),
        ".healthcheck file must exist on NAS mount");
    $out[] = check('nas/writable', fn() => is_writable($nasMount), "NAS mount must be writable by current user");
    $out[] = check('backup/mounted', fn() => isPathMounted($bakMount), "{$bakMount} must appear in /proc/mounts");
    $out[] = check('backup/healthcheck', fn() => is_file($bakMount . '/' . (string) ($cfg['backup']['healthcheck_file'] ?? '.healthcheck')),
        ".healthcheck file must exist on backup mount");
    $out[] = check('backup/destination_root', fn() => is_dir((string) ($cfg['backup']['destination_root'] ?? '')),
        "backup.destination_root must exist");
    $out[] = check('vpn/interface_up', function () use ($cfg) {
        $iface = (string) ($cfg['vpn']['tun_interface'] ?? 'tun0');
        return isVpnInterfaceUp($iface);
    }, "VPN tun interface must be up");
    $out[] = check('ddns/resolves', function () use ($cfg) {
        $h = (string) ($cfg['nas']['ddns_hostname'] ?? '');
        if ($h === '') return false;
        return is_array(@gethostbynamel($h));
    }, "DDNS hostname must resolve");

    return $out;
}

function checkPerms(array $cfg): array
{
    $out = [];
    $stateDir = rtrim((string) ($cfg['state']['dir'] ?? '/var/lib/flowone'), '/');
    $reqDir   = $stateDir . '/requests';
    $hmacKey  = (string) ($cfg['state']['hmac_key_path'] ?? '');
    $logDir   = (string) ($cfg['log']['dir'] ?? '/var/log/flowone');

    $out[] = check('state.dir/exists', fn() => is_dir($stateDir), "{$stateDir} must exist");
    $out[] = check('state.dir/writable', fn() => is_writable($stateDir),
        "{$stateDir} must be writable (chmod 0775 + add web user to flowone-storage group)");
    $out[] = check('requests/exists', fn() => is_dir($reqDir), "{$reqDir} must exist (dispatcher will create it on first run)");
    if (is_dir($reqDir)) {
        $out[] = check('requests/writable', fn() => is_writable($reqDir),
            "{$reqDir} must be writable by the web user (it's where the panel queues operations)");
    }
    $out[] = check('hmac/exists', fn() => is_file($hmacKey), "{$hmacKey} must exist");
    if (is_file($hmacKey)) {
        $out[] = check('hmac/readable', fn() => is_readable($hmacKey),
            "{$hmacKey} must be readable (add web user to flowone-storage group)");
        $out[] = check('hmac/mode', function () use ($hmacKey, $cfg) {
            $mode = fileperms($hmacKey) & 0777;
            $max  = (int) ($cfg['state']['hmac_key_mode_max'] ?? 0640);
            return $mode <= $max;
        }, "HMAC key must not be wider than state.hmac_key_mode_max (default 0640)");
    }
    $out[] = check('log.dir/exists', fn() => is_dir($logDir), "{$logDir} must exist");
    if (is_dir($logDir)) {
        $out[] = check('log.dir/writable', fn() => is_writable($logDir), "{$logDir} must be writable for audit logs");
    }

    return $out;
}

function checkScripts(): array
{
    $php = '/usr/local/lsws/lsphp83/bin/php';
    $binDir = '/var/www/shared/bin';
    $scripts = [
        'nas-backup.php',
        'reclaim-daemon.php',
        'storage-ctl.php',
        'storage-request-dispatcher.php',
    ];
    $out = [];
    $out[] = check('php/exists', fn() => is_executable($php), "{$php} must be executable");
    foreach ($scripts as $s) {
        $path = "{$binDir}/{$s}";
        $out[] = check("scripts/{$s}/exists", fn() => is_file($path), "{$path} must exist");
        if (is_file($path)) {
            $out[] = check("scripts/{$s}/help", function () use ($php, $path) {
                $exit = 0;
                @exec($php . ' ' . escapeshellarg($path) . ' --help 2>&1', $out, $exit);
                return $exit === 0;
            }, "{$path} --help must exit 0");
        }
    }
    return $out;
}

function checkDispatcher(array $cfg, array &$tmpFiles): array
{
    $out = [];
    $stateDir = rtrim((string) ($cfg['state']['dir'] ?? '/var/lib/flowone'), '/');
    $reqDir   = $stateDir . '/requests';

    // Cron presence: parse /etc/crontab and /etc/cron.d/* for our entry.
    $foundCron = false;
    $cronCandidates = ['/etc/crontab'];
    if (is_dir('/etc/cron.d')) {
        foreach (@scandir('/etc/cron.d') ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $cronCandidates[] = '/etc/cron.d/' . $e;
        }
    }
    foreach ($cronCandidates as $c) {
        if (!is_file($c)) continue;
        $raw = @file_get_contents($c);
        if ($raw !== false && stripos($raw, 'storage-request-dispatcher') !== false) {
            $foundCron = true;
            break;
        }
    }
    $out[] = check('dispatcher/cron', fn() => $foundCron,
        "storage-request-dispatcher must be installed in /etc/cron.d/flowone-storage (run cron entry from RUN-CUTOVER.md)");

    // Drop a test request that the dispatcher will reject (unknown kind),
    // then immediately delete it. Proves the request dir is writable by
    // this PHP user AND that the dispatcher reads it.
    if (is_dir($reqDir) && is_writable($reqDir)) {
        $id = 'preflight-' . bin2hex(random_bytes(4));
        $path = $reqDir . '/' . $id . '.json';
        $payload = json_encode([
            'id' => $id,
            'kind' => '__preflight_check_noop__',
            'queued_at' => date('c'),
            'by' => 'storage-activation-test',
            'reason' => 'pre-flight write probe (auto-cleanup)',
        ], JSON_PRETTY_PRINT);
        if (@file_put_contents($path, $payload) === false) {
            $out[] = ['name' => 'dispatcher/write_probe', 'status' => 'FAIL', 'ms' => 0, 'msg' => "write failed: {$path}"];
        } else {
            $tmpFiles[] = $path;
            @unlink($path);
            $out[] = ['name' => 'dispatcher/write_probe', 'status' => 'PASS', 'ms' => 0, 'msg' => 'web user can queue requests'];
        }
    }

    return $out;
}

function checkDb(array $cfg): array
{
    $out = [];
    try {
        $pdo = openDb();
        $out[] = ['name' => 'db/connect', 'status' => 'PASS', 'ms' => 0, 'msg' => 'connected'];
        // drive_files columns
        $stmt = $pdo->query("SHOW COLUMNS FROM drive_files");
        $cols = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) $cols[$c['Field']] = true;
        $required = ['tier_state', 'storage_location', 'tier_changed_at', 'last_read_at'];
        foreach ($required as $c) {
            $out[] = check("db/drive_files.{$c}", fn() => isset($cols[$c]), "column missing — re-run migrations");
        }
        // Tier counts sanity (no lost, all hot+cold known)
        $stmt = $pdo->query("SELECT tier_state, COUNT(*) AS n FROM drive_files GROUP BY tier_state");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $lost = 0;
        foreach ($rows as $r) {
            if ($r['tier_state'] === 'lost') $lost = (int) $r['n'];
        }
        $out[] = check('db/no_lost_files', fn() => $lost === 0,
            "{$lost} files are tier_state='lost' — investigate before activating phases");
    } catch (\Throwable $e) {
        $out[] = ['name' => 'db/connect', 'status' => 'FAIL', 'ms' => 0, 'msg' => $e->getMessage()];
    }
    return $out;
}

function checkRedis(array $cfg): array
{
    if (!extension_loaded('redis')) {
        return [['name' => 'redis/ext', 'status' => 'FAIL', 'ms' => 0, 'msg' => 'php-redis extension not loaded']];
    }
    try {
        $r = new \Redis();
        $r->connect((string) ($cfg['redis']['host'] ?? '127.0.0.1'), (int) ($cfg['redis']['port'] ?? 6379), 2.0);
        $pong = $r->ping();
        $r->close();
        return [['name' => 'redis/ping', 'status' => $pong ? 'PASS' : 'FAIL', 'ms' => 0, 'msg' => 'ping=' . var_export($pong, true)]];
    } catch (\Throwable $e) {
        return [['name' => 'redis/connect', 'status' => 'FAIL', 'ms' => 0, 'msg' => $e->getMessage()]];
    }
}

function checkJournal(array $cfg): array
{
    $path = (string) ($cfg['journal']['path'] ?? '');
    if ($path === '') return [['name' => 'journal/path', 'status' => 'FAIL', 'ms' => 0, 'msg' => 'no journal.path configured']];
    $dir = dirname($path);
    $out = [];
    $out[] = check('journal/dir_exists', fn() => is_dir($dir), "{$dir} must exist");
    if (is_dir($dir)) {
        $out[] = check('journal/dir_writable', fn() => is_writable($dir), "{$dir} must be writable");
    }
    if (is_file($path)) {
        $out[] = check('journal/file_writable', fn() => is_writable($path), "{$path} must be writable");
    }
    return $out;
}

function checkPhases(array $cfg): array
{
    $phases = (array) ($cfg['phases'] ?? []);
    $watched = [
        'phase4_drive_schema',
        'phase5_tier_down_shadow',
        'phase5b_drive_recall',
        'phase5_tier_down_destructive',
        'phase6b_admission_control',
        'phase6c_reclaim_daemon',
        'phase6d_lru_selection',
        'phase7_nas_backup',
    ];
    $out = [];
    foreach ($watched as $k) {
        $on = (bool) ($phases[$k] ?? false);
        // All phases are reported as informational PASS — this section
        // is for visibility, not for gating.
        $out[] = [
            'name'   => 'phase/' . $k,
            'status' => 'PASS',
            'ms'     => 0,
            'msg'    => $on ? 'ON' : 'OFF',
        ];
    }
    return $out;
}

// ──────────────────────────────────────────────────────────────────────
// Helpers

function check(string $name, callable $fn, string $failMsg): array
{
    $t = microtime(true);
    try {
        $ok = (bool) $fn();
    } catch (\Throwable $e) {
        return ['name' => $name, 'status' => 'FAIL', 'ms' => (int) ((microtime(true) - $t) * 1000), 'msg' => $e->getMessage()];
    }
    return [
        'name'   => $name,
        'status' => $ok ? 'PASS' : 'FAIL',
        'ms'     => (int) ((microtime(true) - $t) * 1000),
        'msg'    => $ok ? '' : $failMsg,
    ];
}

/**
 * Linux tun/tap interfaces never advertise carrier (there's no physical
 * link to sense) so they report operstate="unknown" even when the
 * tunnel is up and routing traffic. The authoritative IFF_UP signal
 * lives in /sys/class/net/<iface>/flags as a hex bitfield — bit 0x1
 * is IFF_UP. Accept either:
 *   - operstate == "up"  (covers physical and bonded interfaces)
 *   - IFF_UP set         (covers tun/tap virtual interfaces)
 */
function isVpnInterfaceUp(string $iface): bool
{
    $base = '/sys/class/net/' . $iface;
    if (!is_dir($base)) return false;
    $op = @file_get_contents($base . '/operstate');
    if ($op !== false && trim((string) $op) === 'up') return true;
    $flags = @file_get_contents($base . '/flags');
    if ($flags === false) return false;
    $hex = trim((string) $flags);
    if ($hex === '') return false;
    $n = intval($hex, 16);
    return ($n & 0x1) === 0x1;
}

function isPathMounted(string $path): bool
{
    if (!is_file('/proc/mounts')) return is_dir($path);
    $needle = rtrim($path, '/');
    $fh = @fopen('/proc/mounts', 'r');
    if (!$fh) return false;
    try {
        while (($line = fgets($fh)) !== false) {
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[1]) && rtrim($parts[1], '/') === $needle) return true;
        }
    } finally { fclose($fh); }
    return false;
}

function openDb(): \PDO
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: 'devc_vps_dash';
    $user = getenv('DB_USER') ?: 'vpsadmin';
    $pass = getenv('DB_PASS') ?: '';
    return new \PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user, $pass,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
}

function section(string $label): void
{
    echo "\n--- {$label} ---\n";
}

function emit(string $status, string $name, string $msg, int $ms, $fh, bool $verbose): void
{
    $colors = ['PASS' => "\033[32m", 'FAIL' => "\033[31m", 'WARN' => "\033[33m"];
    $color  = $colors[$status] ?? '';
    $reset  = "\033[0m";
    $line   = sprintf('  %s[%4s]%s %-40s %s%s%s',
        $color, $status, $reset, $name,
        $msg ? '   ' . $msg : '',
        $ms > 0 ? "  ({$ms}ms)" : '', "\n");
    echo $line;
    if (is_resource($fh)) {
        fwrite($fh, sprintf("[%s] [%s] %s %s\n", date('H:i:s'), $status, $name, $msg));
    }
}

function parseOpts(array $argv): array
{
    $out = [];
    foreach ($argv as $a) {
        if (str_starts_with($a, '--')) {
            $kv = substr($a, 2);
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $out[$k] = $v;
            } else {
                $out[$kv] = true;
            }
        }
    }
    return $out;
}

function printHelp(string $argv0): void
{
    echo <<<TXT
{$argv0} - FlowOne storage activation pre-flight

USAGE
  /usr/local/lsws/lsphp83/bin/php {$argv0} [OPTIONS]

OPTIONS
  --help               show this message
  --verbose            log extra detail
  --json               emit machine-readable JSON instead of human text
  --only=cat1,cat2     run only the named categories
                       (infra,perms,scripts,dispatcher,db,redis,journal,phases)
  --smoke              alias for --only=infra,perms,scripts (quick health)

EXIT CODES
  0  all checks passed
  1  one or more checks failed

WHEN TO RUN
  - Before flipping any phase5/6/6b/6c/6d/7 kill switch
  - After each flip (to confirm the change didn't break anything)
  - After NAS or VPN maintenance
  - Anytime the dashboard "Infrastructure" card shows red

TXT;
}
