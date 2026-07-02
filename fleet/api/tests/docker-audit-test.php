#!/usr/bin/env php
<?php
/**
 * Docker-aware audit + update-workflow test suite.
 *
 * Verifies the Docker-era Fleet features end-to-end:
 *   - DockerAuditService (container/mail-pod/TCP-DB audit) unit + live checks
 *   - Docker Update now covers the mail pod (UPDATABLE_SERVICES)
 *   - app_update routing (panel/email/agent) is docker-aware
 *   - Refresh Fleet Manager plumbing (wrapper + sudoers + status endpoint)
 *   - Security-hardening endpoint plumbing (route + CLI --deployment support)
 *
 * Run ON the Fleet master:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-fleet/api/tests/docker-audit-test.php --smoke
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-fleet/api/tests/docker-audit-test.php --server=1 --verbose
 *
 * Flags:
 *   --help            usage
 *   --verbose         extra debug output
 *   --server=<id>     run LIVE audit checks against this (Docker) server over SSH
 *   --only=a,b        run only these groups: preflight,unit,routes,refresh,live
 *   --smoke           preflight + unit only (no SSH, no side effects)
 *   --json            machine-readable summary
 *   --skip-send       accepted for rule parity (this suite has no send step)
 *
 * Non-destructive: the ONLY write a live run performs is the LAST_AUDIT
 * credential row the real audit feature itself maintains (idempotent upsert).
 * Exit 0 = all pass, 1 = any failure.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$opts = [
    'help' => false, 'verbose' => false, 'json' => false, 'smoke' => false,
    'server' => 0, 'only' => [],
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') $opts['help'] = true;
    elseif ($arg === '--verbose') $opts['verbose'] = true;
    elseif ($arg === '--json') $opts['json'] = true;
    elseif ($arg === '--smoke') $opts['smoke'] = true;
    elseif ($arg === '--skip-send') { /* no external sends in this suite */ }
    elseif (str_starts_with($arg, '--server=')) $opts['server'] = (int) substr($arg, 9);
    elseif (str_starts_with($arg, '--only=')) $opts['only'] = array_filter(array_map('trim', explode(',', substr($arg, 7))));
    else { fwrite(STDERR, "Unknown arg: {$arg} (see --help)\n"); exit(2); }
}
if ($opts['help']) {
    echo substr(file_get_contents(__FILE__), 0, 2200) . "\n";
    exit(0);
}

$G = "\033[0;32m"; $R = "\033[0;31m"; $Y = "\033[1;33m"; $N = "\033[0m";
$apiPath = dirname(__DIR__);
$logDir = $apiPath . '/storage/logs';
@mkdir($logDir, 0755, true);
$logFile = $logDir . '/docker-audit-test-' . date('Ymd-His') . '.log';
$results = [];

$logLine = function (string $line) use ($logFile) {
    @file_put_contents($logFile, '[' . date('H:i:s') . "] {$line}\n", FILE_APPEND);
};

$test = function (string $group, string $name, callable $fn) use (&$results, $opts, $G, $R, $Y, $N, $logLine) {
    if (!empty($opts['only']) && !in_array($group, $opts['only'], true)) return;
    $t0 = microtime(true);
    try {
        $out = $fn(); // 'pass' | 'warn:<msg>' | throws on fail
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (is_string($out) && str_starts_with($out, 'warn:')) {
            $results[] = ['group' => $group, 'name' => $name, 'status' => 'warn', 'ms' => $ms, 'msg' => substr($out, 5)];
            echo "  {$Y}[WARN]{$N} {$name} ({$ms}ms) - " . substr($out, 5) . "\n";
            $logLine("[WARN] {$name} ({$ms}ms) " . substr($out, 5));
        } else {
            $results[] = ['group' => $group, 'name' => $name, 'status' => 'pass', 'ms' => $ms, 'msg' => ''];
            echo "  {$G}[PASS]{$N} {$name} ({$ms}ms)\n";
            $logLine("[PASS] {$name} ({$ms}ms)");
        }
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $results[] = ['group' => $group, 'name' => $name, 'status' => 'fail', 'ms' => $ms, 'msg' => $e->getMessage()];
        echo "  {$R}[FAIL]{$N} {$name} ({$ms}ms) - {$e->getMessage()}\n";
        $logLine("[FAIL] {$name} ({$ms}ms) {$e->getMessage()}");
        if ($opts['verbose']) echo "         at {$e->getFile()}:{$e->getLine()}\n";
    }
};

$assert = function (bool $cond, string $msg) { if (!$cond) throw new \RuntimeException($msg); };

// =============================================================================
echo "--- 1. PREFLIGHT ---\n";
// =============================================================================
$container = null;
$test('preflight', 'PHP >= 8.1', fn() => $assert(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION) ?? 'pass');
$test('preflight', 'Autoloader + config load', function () use ($apiPath, &$container, $assert) {
    $assert(file_exists($apiPath . '/vendor/autoload.php'), 'vendor/autoload.php missing (composer install)');
    require_once $apiPath . '/vendor/autoload.php';
    $config = require $apiPath . '/config.php';
    $local = file_exists($apiPath . '/config.local.php') ? require $apiPath . '/config.local.php' : [];
    $container = new \FleetManager\Api\Core\Container(array_replace_recursive($config, $local));
    return 'pass';
});
$test('preflight', 'Fleet DB reachable', function () use (&$container, $assert) {
    $assert($container !== null, 'container not built');
    $container->getDatabase()->query('SELECT 1');
    return 'pass';
});
$test('preflight', 'storage/logs writable', fn() => $assert(is_writable(dirname($logFile)), $logDir . ' not writable') ?? 'pass');

// =============================================================================
echo "--- 2. UNIT (pure, no SSH) ---\n";
// =============================================================================
use FleetManager\Api\Services\DockerProvisioningService as DPS;

$test('unit', 'DockerAuditService class exists + wires to container', function () use (&$container, $assert) {
    $svc = $container->get(\FleetManager\Api\Services\DockerAuditService::class);
    $assert($svc !== null, 'container could not build DockerAuditService');
    $assert(method_exists($svc, 'run') && method_exists($svc, 'fix'), 'run()/fix() missing');
    return 'pass';
});
$test('unit', 'AuditService delegates Docker boxes', function () use ($apiPath, $assert) {
    $src = file_get_contents($apiPath . '/src/Services/AuditService.php');
    $assert(substr_count($src, 'DockerAuditService::class') >= 2, 'run()/fix() delegation to DockerAuditService missing');
    return 'pass';
});
$test('unit', 'UPDATABLE_SERVICES includes the mail pod', function () use ($assert) {
    $assert(in_array('mail', DPS::UPDATABLE_SERVICES, true), 'mail not updatable');
    $assert(!in_array('mail', DPS::SERVICES, true), 'mail must stay OUT of health-gated SERVICES');
    $assert(DPS::APP_SERVICES === ['web', 'collab', 'mailsync', 'mail'], 'APP_SERVICES unexpected: ' . implode(',', DPS::APP_SERVICES));
    return 'pass';
});
$test('unit', 'parsePsJson: array + NDJSON + health', function () use ($assert) {
    $ndjson = '{"Service":"web","State":"running","Health":"healthy"}' . "\n"
            . '{"Service":"mail","State":"running","Health":""}';
    $s = DPS::parsePsJson($ndjson);
    $assert(($s['web']['health'] ?? '') === 'healthy', 'NDJSON health parse failed');
    $arr = '[{"Service":"redis","State":"exited","Health":""}]';
    $s2 = DPS::parsePsJson($arr);
    $assert(($s2['redis']['state'] ?? '') === 'exited', 'array parse failed');
    $assert(DPS::parsePsJson('') === [], 'empty input must parse to []');
    return 'pass';
});
$test('unit', 'compose base pins project + files', function () use ($assert) {
    $base = DPS::composeBase();
    $assert(str_contains($base, "-p 'flowone'") && str_contains($base, 'docker-compose.yml'), "unexpected: {$base}");
    return 'pass';
});
$test('unit', 'update-panel/harden CLIs accept --deployment', function () use ($apiPath, $assert) {
    foreach (['update-panel.php', 'harden-server.php'] as $cli) {
        $src = file_get_contents($apiPath . '/cli/' . $cli);
        $assert(str_contains($src, '--deployment='), "{$cli} missing --deployment support");
        $assert(str_contains($src, "'success'") && str_contains($src, "'failed'"), "{$cli} missing status bookkeeping");
    }
    return 'pass';
});

// =============================================================================
echo "--- 3. ROUTES ---\n";
// =============================================================================
$test('routes', 'harden + refresh routes registered', function () use ($apiPath, $assert) {
    $routes = file_get_contents($apiPath . '/routes.php');
    $assert(str_contains($routes, "/api/servers/{id}/harden"), 'harden route missing');
    $assert(str_contains($routes, "/api/system/refresh"), 'refresh route missing');
    $assert(str_contains($routes, "/api/system/refresh/status"), 'refresh status route missing');
    return 'pass';
});
$test('routes', 'controllers expose the handlers', function () use ($assert) {
    $assert(method_exists(\FleetManager\Api\Controllers\DeploymentController::class, 'harden'), 'DeploymentController::harden missing');
    $assert(method_exists(\FleetManager\Api\Controllers\SystemController::class, 'refresh'), 'SystemController::refresh missing');
    $assert(method_exists(\FleetManager\Api\Controllers\SystemController::class, 'refreshStatus'), 'SystemController::refreshStatus missing');
    return 'pass';
});

// =============================================================================
echo "--- 4. REFRESH PLUMBING (master only) ---\n";
// =============================================================================
if (!$opts['smoke']) {
    $test('refresh', 'master-update wrapper installed', function () {
        if (!file_exists('/usr/local/bin/flowone-master-update')) {
            return 'warn:wrapper not installed yet - run master-update.sh once as root to self-install';
        }
        return 'pass';
    });
    $test('refresh', 'sudoers entry valid', function () {
        if (!file_exists('/etc/sudoers.d/flowone-fleet-refresh')) {
            return 'warn:sudoers entry missing - dashboard refresh will 412 until master-update.sh runs once';
        }
        exec('visudo -cf /etc/sudoers.d/flowone-fleet-refresh 2>&1', $o, $rc);
        if ($rc !== 0) throw new \RuntimeException('sudoers entry INVALID: ' . implode(' ', $o));
        return 'pass';
    });
}

// =============================================================================
echo "--- 5. LIVE AUDIT (needs --server=<id>, SSH) ---\n";
// =============================================================================
if (!$opts['smoke'] && $opts['server'] > 0) {
    $serverId = $opts['server'];

    $test('live', "server {$serverId} exists + is a Docker box", function () use (&$container, $serverId, $assert) {
        $stmt = $container->getDatabase()->prepare('SELECT deployed_image_tag FROM servers WHERE id = ?');
        $stmt->execute([$serverId]);
        $row = $stmt->fetch();
        $assert((bool) $row, "server {$serverId} not found");
        $assert(!empty($row['deployed_image_tag']), 'not a Docker box (deployed_image_tag empty) - live docker audit does not apply');
        return 'pass';
    });

    $audit = null;
    $test('live', 'DockerAuditService::run completes', function () use (&$container, $serverId, &$audit, $assert, $opts) {
        set_time_limit(300);
        $svc = $container->get(\FleetManager\Api\Services\DockerAuditService::class);
        $res = $svc->run($serverId);
        $assert(!empty($res['success']), 'audit errored: ' . ($res['error'] ?? 'unknown'));
        $audit = $res['audit'];
        if ($opts['verbose']) {
            fwrite(STDERR, json_encode($audit, JSON_PRETTY_PRINT) . "\n");
        }
        return 'pass';
    });
    $test('live', 'audit shape + docker mode', function () use (&$audit, $assert) {
        $assert(is_array($audit), 'no audit payload');
        $assert(($audit['mode'] ?? '') === 'docker', 'mode != docker');
        foreach (['checks', 'passed', 'failed', 'warnings', 'total', 'overall', 'duration_ms'] as $k) {
            $assert(array_key_exists($k, $audit), "missing key {$k}");
        }
        $assert($audit['total'] === count($audit['checks']), 'total != check count');
        return 'pass';
    });
    $test('live', 'audit covers container + native + db categories', function () use (&$audit, $assert) {
        $cats = array_unique(array_column($audit['checks'], 'category'));
        foreach (['services', 'containers', 'database', 'ssl', 'http', 'firewall'] as $must) {
            $assert(in_array($must, $cats, true), "category '{$must}' missing (got: " . implode(',', $cats) . ')');
        }
        return 'pass';
    });
    $test('live', 'containers checked individually', function () use (&$audit, $assert) {
        $names = array_column(array_filter($audit['checks'], fn($c) => $c['category'] === 'containers'), 'name');
        foreach (['MariaDB (container)', 'Email App Web (container)', 'Mail Pod (container)'] as $must) {
            $assert(in_array($must, $names, true), "container check '{$must}' missing");
        }
        return 'pass';
    });
    $test('live', 'failed checks carry fix actions where fixable', function () use (&$audit) {
        $failed = array_filter($audit['checks'], fn($c) => $c['status'] === 'fail');
        $fixable = array_filter($failed, fn($c) => !empty($c['fix_action']));
        if (count($failed) > 0 && count($fixable) === 0) {
            return 'warn:' . count($failed) . ' failures but none fixable - check fix wiring';
        }
        return 'pass';
    });
    $test('live', 'LAST_AUDIT stored for the dashboard', function () use (&$container, $serverId, $assert) {
        $stmt = $container->getDatabase()->prepare(
            "SELECT updated_at FROM server_credentials WHERE server_id = ? AND credential_key = 'LAST_AUDIT'"
        );
        $stmt->execute([$serverId]);
        $row = $stmt->fetch();
        $assert((bool) $row, 'LAST_AUDIT row missing');
        $assert(strtotime((string) $row['updated_at']) > time() - 600, 'LAST_AUDIT not refreshed by this run');
        return 'pass';
    });
} elseif (!$opts['smoke']) {
    echo "  (skipped - pass --server=<id> to run live checks)\n";
}

// =============================================================================
// SUMMARY
// =============================================================================
$passed = count(array_filter($results, fn($r) => $r['status'] === 'pass'));
$failed = count(array_filter($results, fn($r) => $r['status'] === 'fail'));
$warned = count(array_filter($results, fn($r) => $r['status'] === 'warn'));

if ($opts['json']) {
    echo json_encode(['passed' => $passed, 'failed' => $failed, 'warnings' => $warned,
        'total' => count($results), 'log' => $logFile, 'results' => $results], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n=== SUMMARY: {$G}{$passed} passed{$N}, " . ($failed ? "{$R}{$failed} failed{$N}" : '0 failed')
        . ", " . ($warned ? "{$Y}{$warned} warning(s){$N}" : '0 warnings') . " (log: {$logFile}) ===\n";
    foreach ($results as $r) {
        if ($r['status'] === 'fail') echo "  {$R}FAILED{$N}: [{$r['group']}] {$r['name']} - {$r['msg']}\n";
    }
}

exit($failed > 0 ? 1 : 0);
