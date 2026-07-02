#!/usr/bin/env php
<?php
/**
 * Host Policy (Nameservers + NAS opt-in) Test
 *
 * Validates the per-server provisioning policy pipeline on the Fleet server:
 *   - migration 032 (servers.ns1_domain / ns2_domain columns)
 *   - ServerController accepts + persists the new fields (whitelist check)
 *   - TemplateService populates NS1_DOMAIN / NS2_DOMAIN / NAS_* variables
 *   - ProvisioningService generates the correct .dns_ns_config.json payload
 *     and /etc/flowone/storage.local.php content (via source-level contracts)
 *   - apply-settings route is registered
 *   - DNS zone seeding skips NS records when NS1_DOMAIN is empty
 *
 * Run on the Fleet Panel server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-fleet/api/tests/host-policy-test.php --verbose
 *
 * Flags:
 *   --help        Show usage
 *   --verbose     Extra debug output
 *   --smoke       Pre-flight checks only (connectivity + config)
 *   --skip-db     Skip tests that write to the database
 *   --skip-send   Alias of --skip-db (no external/destructive operations here)
 *   --only=a,b    Run only specific groups (preflight,columns,template,policy,routes)
 *   --json        Output results as JSON
 *
 * All test data uses the flowone_test_ prefix and is removed on exit
 * (including on failure or Ctrl+C). Never touches real servers.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

// ----------------------------------------------------------------- arguments

$opts = [
    'help' => false, 'verbose' => false, 'smoke' => false,
    'skip-db' => false, 'json' => false, 'only' => [],
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') $opts['help'] = true;
    elseif ($arg === '--verbose') $opts['verbose'] = true;
    elseif ($arg === '--smoke') $opts['smoke'] = true;
    elseif ($arg === '--skip-db' || $arg === '--skip-send') $opts['skip-db'] = true;
    elseif ($arg === '--json') $opts['json'] = true;
    elseif (str_starts_with($arg, '--only=')) $opts['only'] = array_filter(explode(',', substr($arg, 7)));
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}

if ($opts['help']) {
    echo "Usage: php host-policy-test.php [--verbose] [--smoke] [--skip-db] [--only=group1,group2] [--json]\n";
    echo "Groups: preflight, columns, template, policy, routes\n";
    exit(0);
}

// -------------------------------------------------------------- mini runner

const C_GREEN = "\033[32m"; const C_RED = "\033[31m"; const C_YELLOW = "\033[33m"; const C_RESET = "\033[0m";

$apiRoot = dirname(__DIR__);
$logDir = $apiRoot . '/storage/logs';
@mkdir($logDir, 0775, true);
if (!is_dir($logDir)) $logDir = sys_get_temp_dir();
$logFile = $logDir . '/host-policy-test-' . date('Ymd-His') . '.log';

$results = ['passed' => 0, 'failed' => 0, 'warned' => 0, 'tests' => []];
$cleanups = [];

function logLine(string $line): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('H:i:s') . "] {$line}\n", FILE_APPEND);
}

function runTest(string $name, callable $fn): void {
    global $results, $opts;
    $start = microtime(true);
    set_time_limit(30);
    try {
        $outcome = $fn(); // null/true = pass, ['warn' => msg] = warn
        $ms = (int)round((microtime(true) - $start) * 1000);
        if (is_array($outcome) && isset($outcome['warn'])) {
            $results['warned']++;
            $results['tests'][] = ['name' => $name, 'status' => 'warn', 'ms' => $ms, 'message' => $outcome['warn']];
            if (!$opts['json']) echo C_YELLOW . "[WARN]" . C_RESET . " {$name} ({$ms}ms) -> {$outcome['warn']}\n";
            logLine("[WARN] {$name} ({$ms}ms) {$outcome['warn']}");
        } else {
            $results['passed']++;
            $results['tests'][] = ['name' => $name, 'status' => 'pass', 'ms' => $ms];
            if (!$opts['json']) echo C_GREEN . "[PASS]" . C_RESET . " {$name} ({$ms}ms)\n";
            logLine("[PASS] {$name} ({$ms}ms)");
        }
    } catch (\Throwable $e) {
        $ms = (int)round((microtime(true) - $start) * 1000);
        $results['failed']++;
        $results['tests'][] = ['name' => $name, 'status' => 'fail', 'ms' => $ms, 'message' => $e->getMessage()];
        if (!$opts['json']) {
            echo C_RED . "[FAIL]" . C_RESET . " {$name} ({$ms}ms)\n   -> " . $e->getMessage() . "\n";
            if ($opts['verbose']) echo "   at " . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        logLine("[FAIL] {$name} ({$ms}ms) " . $e->getMessage());
    }
}

function expect(bool $cond, string $message): void {
    if (!$cond) throw new \RuntimeException($message);
}

function section(string $title): void {
    global $opts;
    if (!$opts['json']) echo "\n--- {$title} ---\n";
    logLine("--- {$title} ---");
}

function shouldRun(string $group): bool {
    global $opts;
    return empty($opts['only']) || in_array($group, $opts['only'], true);
}

function runCleanups(): void {
    global $cleanups;
    foreach (array_reverse($cleanups) as $fn) {
        try { $fn(); } catch (\Throwable $e) { logLine('[CLEANUP-ERROR] ' . $e->getMessage()); }
    }
    $cleanups = [];
}
register_shutdown_function('runCleanups');
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $sig) {
        pcntl_signal($sig, function () { runCleanups(); exit(1); });
    }
}

function finish(): void {
    global $results, $opts, $logFile;
    runCleanups();
    if ($opts['json']) {
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "\n=== SUMMARY: {$results['passed']} passed, {$results['failed']} failed, {$results['warned']} warnings ===\n";
        foreach ($results['tests'] as $t) {
            if ($t['status'] === 'fail') echo C_RED . "  FAILED: {$t['name']} -> {$t['message']}" . C_RESET . "\n";
        }
        echo "log: {$logFile}\n";
    }
    exit($results['failed'] > 0 ? 1 : 0);
}

if (!$opts['json']) {
    echo "=== host-policy — " . gmdate('Y-m-d H:i:s') . " UTC ===\n";
    echo "verbose={$opts['verbose']} smoke={$opts['smoke']} skip-db={$opts['skip-db']} log={$logFile}\n";
}

// ----------------------------------------------------------------- preflight

$config = null;
$db = null;

if (shouldRun('preflight')) {
    section('1. PREFLIGHT');

    runTest('php extensions loaded (pdo_mysql, json, openssl)', function () {
        foreach (['pdo_mysql', 'json', 'openssl'] as $ext) {
            expect(extension_loaded($ext), "Missing PHP extension: {$ext}");
        }
    });

    runTest('config + composer autoload', function () use ($apiRoot, &$config) {
        expect(file_exists($apiRoot . '/vendor/autoload.php'), 'vendor/autoload.php missing');
        require_once $apiRoot . '/vendor/autoload.php';
        $config = require $apiRoot . '/config.php';
        if (file_exists($apiRoot . '/config.local.php')) {
            $config = array_replace_recursive($config, require $apiRoot . '/config.local.php');
        }
        expect(!empty($config['database']['name']), 'database.name not configured');
        expect(!empty($config['database']['password']), 'database.password not configured (config.local.php)');
    });

    runTest('database reachable', function () use (&$config, &$db) {
        expect($config !== null, 'config did not load');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['database']['host'], $config['database']['port'],
            $config['database']['name'], $config['database']['charset']);
        $db = new \PDO($dsn, $config['database']['user'], $config['database']['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $db->query('SELECT 1');
    });
}

if ($opts['smoke']) {
    finish();
}

// ----------------------------------------------------- columns (migration 032)

if (shouldRun('columns')) {
    section('2. COLUMNS (migration 032)');

    runTest('servers.ns1_domain / ns2_domain columns exist', function () use (&$db) {
        expect($db !== null, 'no database connection');
        foreach (['ns1_domain', 'ns2_domain'] as $col) {
            $stmt = $db->query("SHOW COLUMNS FROM servers LIKE '{$col}'");
            expect($stmt->fetch() !== false, "servers.{$col} missing — run migration 032");
        }
    });

    runTest('servers NAS columns exist (nas_enabled/nas_ip/nas_path/nas_mount)', function () use (&$db) {
        expect($db !== null, 'no database connection');
        foreach (['nas_enabled', 'nas_ip', 'nas_path', 'nas_mount', 'vpn_enabled'] as $col) {
            $stmt = $db->query("SHOW COLUMNS FROM servers LIKE '{$col}'");
            expect($stmt->fetch() !== false, "servers.{$col} missing");
        }
    });

    if (!$opts['skip-db']) {
        runTest('round-trip: insert + read NS/NAS fields on a temp server row', function () use (&$db, &$cleanups) {
            expect($db !== null, 'no database connection');
            $name = 'flowone_test_policy_' . bin2hex(random_bytes(4));
            $db->prepare("INSERT INTO servers (name, ip_address, panel_domain, email_domain, status,
                                               ns1_domain, ns2_domain, nas_enabled, nas_ip, nas_mount)
                          VALUES (?, '203.0.113.250', ?, ?, 'pending', 'ns1.client.test', 'ns2.client.test', 1, '10.8.0.99', '/mnt/test-nas')")
               ->execute([$name, "panel.{$name}.test", "email.{$name}.test"]);
            $id = (int)$db->lastInsertId();
            $cleanups[] = function () use ($db, $id) {
                $db->prepare("DELETE FROM servers WHERE id = ? AND name LIKE 'flowone_test_%'")->execute([$id]);
            };
            $row = $db->query("SELECT * FROM servers WHERE id = {$id}")->fetch();
            expect($row['ns1_domain'] === 'ns1.client.test', 'ns1_domain did not round-trip');
            expect($row['ns2_domain'] === 'ns2.client.test', 'ns2_domain did not round-trip');
            expect((int)$row['nas_enabled'] === 1, 'nas_enabled did not round-trip');
        });
    }
}

// -------------------------------------------------- template variable mapping

if (shouldRun('template')) {
    section('3. TEMPLATE VARIABLES');

    runTest('TemplateService maps server row -> NS1_DOMAIN/NS2_DOMAIN', function () use ($apiRoot) {
        $src = file_get_contents($apiRoot . '/src/Services/TemplateService.php');
        expect(str_contains($src, "\$server['ns1_domain']"), 'TemplateService does not read ns1_domain');
        expect(str_contains($src, "\$server['ns2_domain']"), 'TemplateService does not read ns2_domain');
        expect(str_contains($src, "['NS1_DOMAIN']"), 'TemplateService does not set NS1_DOMAIN');
    });

    runTest('ServerController whitelists ns1_domain/ns2_domain on update', function () use ($apiRoot) {
        $src = file_get_contents($apiRoot . '/src/Controllers/ServerController.php');
        expect(str_contains($src, "'ns1_domain'") && str_contains($src, "'ns2_domain'"),
            'update() field whitelist lacks ns1_domain/ns2_domain');
    });
}

// -------------------------------------------------------------- policy files

if (shouldRun('policy')) {
    section('4. POLICY FILE GENERATION');

    runTest('ProvisioningService has deployHostPolicyFiles + applyHostPolicy', function () use ($apiRoot) {
        $src = file_get_contents($apiRoot . '/src/Services/ProvisioningService.php');
        expect(str_contains($src, 'function deployHostPolicyFiles'), 'deployHostPolicyFiles missing');
        expect(str_contains($src, 'function applyHostPolicy'), 'applyHostPolicy missing');
        expect(str_contains($src, '.dns_ns_config.json'), 'NS config file path not referenced');
        expect(str_contains($src, '/etc/flowone/storage.local.php'), 'storage.local.php path not referenced');
    });

    runTest('NS config JSON contract: enabled=false when NS1 empty', function () {
        // Mirrors the exact payload deployHostPolicyFiles builds.
        $build = function (string $ns1, string $ns2): array {
            return ['enabled' => $ns1 !== '', 'ns1' => $ns1, 'ns2' => $ns2];
        };
        $off = $build('', '');
        expect($off['enabled'] === false, 'empty NS1 must disable NS publishing');
        $on = $build('ns1.client.test', 'ns2.client.test');
        expect($on['enabled'] === true && $on['ns1'] === 'ns1.client.test', 'NS1 set must enable publishing');
        // The JSON must decode back losslessly (panel's NsDefaults::load merges it).
        $decoded = json_decode(json_encode($on, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), true);
        expect($decoded === $on, 'JSON round-trip mismatch');
    });

    runTest('storage.local.php contract: disabled payload parses + disables NAS', function () {
        $php = "<?php\nreturn [\n    'nas' => ['enabled' => false],\n];\n";
        $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_');
        file_put_contents($tmp, $php);
        $parsed = require $tmp;
        @unlink($tmp);
        expect(is_array($parsed) && $parsed['nas']['enabled'] === false, 'disabled NAS payload invalid');
    });

    runTest('storage.local.php contract: enabled payload carries client NAS, blank DDNS', function () {
        $esc = fn (string $v) => str_replace(["\\", "'"], ["\\\\", "\\'"], $v);
        $ip = '10.8.0.99'; $mount = '/mnt/test-nas';
        $php = "<?php\nreturn [\n    'nas' => [\n        'enabled'       => true,\n"
             . "        'lan_ip'        => '{$esc($ip)}',\n"
             . "        'mount_point'   => '{$esc($mount)}',\n"
             . "        'ddns_hostname' => '',\n    ],\n];\n";
        $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_');
        file_put_contents($tmp, $php);
        $parsed = require $tmp;
        @unlink($tmp);
        expect($parsed['nas']['enabled'] === true, 'enabled flag lost');
        expect($parsed['nas']['lan_ip'] === $ip, 'lan_ip lost');
        expect($parsed['nas']['ddns_hostname'] === '', 'operator DDNS must be blanked on client boxes');
    });

    runTest('DNS seeding skips NS records when NS1_DOMAIN empty', function () use ($apiRoot) {
        $src = file_get_contents($apiRoot . '/src/Services/ProvisioningService.php');
        // The seeding block must gate NS inserts on a non-empty ns1.
        expect((bool)preg_match('/if\s*\(\!empty\(\$ns1\)\)/', $src),
            'seedDnsRecords no longer gates NS records on NS1_DOMAIN');
    });

    runTest('ModSecurity baseline is DetectionOnly and never overwrites', function () use ($apiRoot) {
        $src = file_get_contents($apiRoot . '/src/Services/ProvisioningService.php');
        expect(str_contains($src, 'function deployModsecBaseline'), 'deployModsecBaseline missing');
        expect(str_contains($src, 'SecRuleEngine DetectionOnly'), 'baseline is not DetectionOnly');
        expect(str_contains($src, 'test -f /usr/local/lsws/conf/modsec.conf'),
            'baseline must check for an existing modsec.conf before writing');
    });
}

// -------------------------------------------------------------------- routes

if (shouldRun('routes')) {
    section('5. ROUTES');

    runTest('apply-settings route registered', function () use ($apiRoot) {
        $src = file_get_contents($apiRoot . '/routes.php');
        expect(str_contains($src, "/api/servers/{id}/apply-settings"), 'apply-settings route missing');
        expect(str_contains($src, "'applySettings'"), 'applySettings handler not wired');
    });

    runTest('ServerController::applySettings exists', function () use ($apiRoot) {
        $src = file_get_contents($apiRoot . '/src/Controllers/ServerController.php');
        expect(str_contains($src, 'function applySettings'), 'applySettings method missing');
        expect(str_contains($src, 'applyHostPolicy'), 'applySettings does not call applyHostPolicy');
    });
}

finish();
