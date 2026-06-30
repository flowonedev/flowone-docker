#!/usr/bin/env php
<?php
/**
 * Fleet Updates Pipeline Test
 *
 * Validates the remote update system end-to-end on the Fleet Panel server:
 *   - migration 025 (server_updates table + update_packages task type)
 *   - TaskService::createUpdatePackagesTask contract (timeout, no retries)
 *   - heartbeat ingestion upsert (one row per server, counts, reboot flag)
 *   - API routes + controller endpoints
 *   - agent-side contracts (heartbeat payload, TaskAction handler, no-reboot policy)
 *   - apt/dnf output parsers
 *
 * Run on the Fleet Panel server:
 *   php /var/www/vps-fleet/api/tests/fleet-updates-test.php --verbose
 *
 * Flags:
 *   --help        Show usage
 *   --verbose     Extra debug output
 *   --smoke       Pre-flight checks only (connectivity + config)
 *   --skip-db     Skip tests that write to the database
 *   --only=a,b    Run only specific groups (preflight,tasks,ingestion,contract,parser)
 *   --json        Output results as JSON
 *
 * All test data uses the flowone_test_ prefix and is removed on exit
 * (including on failure or Ctrl+C). Never touches real servers or tasks.
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
    elseif ($arg === '--skip-db') $opts['skip-db'] = true;
    elseif ($arg === '--json') $opts['json'] = true;
    elseif (str_starts_with($arg, '--only=')) $opts['only'] = array_filter(explode(',', substr($arg, 7)));
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}

if ($opts['help']) {
    echo "Usage: php fleet-updates-test.php [--verbose] [--smoke] [--skip-db] [--only=group1,group2] [--json]\n";
    echo "Groups: preflight, tasks, ingestion, contract, parser\n";
    exit(0);
}

// -------------------------------------------------------------- mini runner

const C_GREEN = "\033[32m"; const C_RED = "\033[31m"; const C_YELLOW = "\033[33m"; const C_RESET = "\033[0m";

$apiRoot = dirname(__DIR__);
$logDir = $apiRoot . '/storage/logs';
@mkdir($logDir, 0775, true);
if (!is_dir($logDir)) $logDir = sys_get_temp_dir();
$logFile = $logDir . '/fleet-updates-test-' . date('Ymd-His') . '.log';

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

// Cleanup always runs: shutdown handler + signals
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

if (!$opts['json']) {
    echo "=== fleet-updates — " . gmdate('Y-m-d H:i:s') . " UTC ===\n";
    echo "verbose={$opts['verbose']} smoke={$opts['smoke']} skip-db={$opts['skip-db']} log={$logFile}\n";
}

// ----------------------------------------------------------------- preflight

$config = null;
$db = null;
$agentDir = realpath($apiRoot . '/../agent') ?: null;

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

    runTest('server_updates table exists (migration 025)', function () use (&$db) {
        expect($db !== null, 'no database connection');
        $stmt = $db->query("SHOW TABLES LIKE 'server_updates'");
        expect($stmt->fetch() !== false, 'server_updates table missing - run migration 025');
    });

    runTest('agent_tasks type ENUM includes update_packages', function () use (&$db) {
        expect($db !== null, 'no database connection');
        $stmt = $db->query("SHOW COLUMNS FROM agent_tasks LIKE 'type'");
        $col = $stmt->fetch();
        expect($col !== false && str_contains($col['Type'], 'update_packages'),
            'agent_tasks.type ENUM lacks update_packages - run migration 025');
    });
}

if ($opts['smoke']) {
    finish();
}

// ------------------------------------------------------- temp server fixture

$tempServerId = null;

function tempServer(\PDO $db, array &$cleanups): int {
    $token = 'flowone_test_' . bin2hex(random_bytes(16));
    $stmt = $db->prepare(
        "INSERT INTO servers (name, ip_address, panel_domain, email_domain, agent_token, status)
         VALUES (?, '127.0.0.1', 'flowone-test.invalid', 'flowone-test.invalid', ?, 'maintenance')"
    );
    $stmt->execute(['flowone_test_updates', $token]);
    $id = (int)$db->lastInsertId();
    $cleanups[] = function () use ($db, $id) {
        // FKs cascade: agent_tasks + server_updates rows die with the server
        $db->prepare("DELETE FROM servers WHERE id = ? AND name LIKE 'flowone_test_%'")->execute([$id]);
    };
    return $id;
}

// --------------------------------------------------------------------- tasks

if (shouldRun('tasks') && !$opts['skip-db']) {
    section('2. TASKS');

    runTest('createUpdatePackagesTask creates a safe task contract', function () use (&$db, &$config, &$cleanups, &$tempServerId) {
        expect($db !== null, 'no database connection');
        $tempServerId = tempServer($db, $cleanups);

        $container = new \FleetManager\Api\Core\Container($config);
        $taskService = new \FleetManager\Api\Services\TaskService($container);
        $task = $taskService->createUpdatePackagesTask($tempServerId, ['scope' => 'all']);

        expect($task['type'] === 'update_packages', "type is {$task['type']}, expected update_packages");
        expect((int)$task['timeout_seconds'] === 1800, 'timeout should be 1800s for long upgrades');
        expect((int)$task['max_retries'] === 0, 'updates must NOT auto-retry (max_retries must be 0)');
        expect($task['payload']['scope'] === 'all', 'payload scope lost');
    });

    runTest('getPendingTasks delivers update task to the agent and queues it', function () use (&$db, &$config, &$tempServerId) {
        expect($tempServerId !== null, 'temp server fixture missing');
        $container = new \FleetManager\Api\Core\Container($config);
        $taskService = new \FleetManager\Api\Services\TaskService($container);

        $pending = $taskService->getPendingTasks($tempServerId);
        $found = array_filter($pending, fn($t) => $t['type'] === 'update_packages');
        expect(count($found) === 1, 'update_packages task not delivered to agent');

        $stmt = $db->prepare("SELECT status FROM agent_tasks WHERE server_id = ?");
        $stmt->execute([$tempServerId]);
        expect($stmt->fetch()['status'] === 'queued', 'task not marked queued after delivery');
    });
}

// ----------------------------------------------------------------- ingestion

if (shouldRun('ingestion') && !$opts['skip-db']) {
    section('3. INGESTION');

    $upsert = function (\PDO $db, int $serverId, array $updates): void {
        // Mirrors AgentController::heartbeat()'s ingestion SQL
        $osPending = (int)($updates['os']['count'] ?? 0);
        $npmPending = 0;
        foreach ((array)($updates['npm'] ?? []) as $app) $npmPending += (int)($app['count'] ?? 0);
        $stmt = $db->prepare(
            "INSERT INTO server_updates (server_id, os_pending, npm_pending, reboot_required, payload, checked_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE os_pending = VALUES(os_pending), npm_pending = VALUES(npm_pending),
                reboot_required = VALUES(reboot_required), payload = VALUES(payload), checked_at = VALUES(checked_at)"
        );
        $stmt->execute([$serverId, $osPending, $npmPending,
            !empty($updates['os']['reboot_required']) ? 1 : 0, json_encode($updates)]);
    };

    runTest('heartbeat report upsert stores counts + payload', function () use (&$db, &$cleanups, &$tempServerId, $upsert) {
        expect($db !== null, 'no database connection');
        if ($tempServerId === null) $tempServerId = tempServer($db, $cleanups);

        $report = [
            'checked_at' => date('c'),
            'os' => ['manager' => 'apt', 'count' => 2, 'reboot_required' => true, 'packages' => [
                ['name' => 'openssl', 'current' => '3.0.1', 'available' => '3.0.2'],
                ['name' => 'mariadb-server', 'current' => '10.6.1', 'available' => '10.6.2'],
            ]],
            'npm' => [['dir' => '/tmp/flowone_test', 'service' => 'collab-server', 'count' => 1,
                'packages' => [['name' => 'yjs', 'current' => '13.6.0', 'wanted' => '13.6.2']]]],
        ];
        $upsert($db, $tempServerId, $report);

        $stmt = $db->prepare("SELECT * FROM server_updates WHERE server_id = ?");
        $stmt->execute([$tempServerId]);
        $row = $stmt->fetch();
        expect($row !== false, 'no row stored');
        expect((int)$row['os_pending'] === 2, 'os_pending wrong');
        expect((int)$row['npm_pending'] === 1, 'npm_pending wrong');
        expect((int)$row['reboot_required'] === 1, 'reboot_required flag lost');
        $decoded = json_decode($row['payload'], true);
        expect($decoded['os']['packages'][0]['name'] === 'openssl', 'payload JSON roundtrip broken');
    });

    runTest('re-reporting upserts a single row per server', function () use (&$db, &$tempServerId, $upsert) {
        expect($tempServerId !== null, 'temp server fixture missing');
        $upsert($db, $tempServerId, ['checked_at' => date('c'),
            'os' => ['manager' => 'apt', 'count' => 0, 'reboot_required' => false, 'packages' => []], 'npm' => []]);

        $stmt = $db->prepare("SELECT COUNT(*) AS n, MAX(os_pending) AS os FROM server_updates WHERE server_id = ?");
        $stmt->execute([$tempServerId]);
        $row = $stmt->fetch();
        expect((int)$row['n'] === 1, 'duplicate rows after re-report');
        expect((int)$row['os'] === 0, 'counts not refreshed on upsert');
    });
}

// ------------------------------------------------------------------ contract

if (shouldRun('contract')) {
    section('4. CONTRACT');

    runTest('routes expose updates endpoints', function () use ($apiRoot) {
        $routes = file_get_contents($apiRoot . '/routes.php');
        expect(str_contains($routes, "/api/servers/{id}/updates'"), 'GET updates route missing');
        expect(str_contains($routes, "/api/servers/{id}/updates/apply'"), 'POST updates/apply route missing');
    });

    runTest('ServerController implements getUpdates + applyUpdates', function () use ($apiRoot) {
        require_once $apiRoot . '/vendor/autoload.php';
        expect(method_exists(\FleetManager\Api\Controllers\ServerController::class, 'getUpdates'), 'getUpdates missing');
        expect(method_exists(\FleetManager\Api\Controllers\ServerController::class, 'applyUpdates'), 'applyUpdates missing');
        expect(defined(\FleetManager\Api\Services\TaskService::class . '::TYPE_UPDATE_PACKAGES'), 'TYPE_UPDATE_PACKAGES constant missing');
    });

    runTest('agent heartbeat sends updates + maps update_packages task', function () use ($agentDir) {
        if (!$agentDir || !file_exists($agentDir . '/heartbeat.php')) {
            return ['warn' => 'agent sources not found next to api/ - skipped'];
        }
        $src = file_get_contents($agentDir . '/heartbeat.php');
        expect(str_contains($src, "UpdateScanner"), 'heartbeat does not use UpdateScanner');
        expect(str_contains($src, "\$payload['updates']"), 'heartbeat payload lacks updates section');
        expect(str_contains($src, "'update_packages' => 'task.update_packages'"), 'task action map lacks update_packages');
        return null;
    });

    runTest('TaskAction handles update_packages and NEVER reboots', function () use ($agentDir) {
        if (!$agentDir || !file_exists($agentDir . '/Actions/TaskAction.php')) {
            return ['warn' => 'agent sources not found next to api/ - skipped'];
        }
        $src = file_get_contents($agentDir . '/Actions/TaskAction.php');
        expect(str_contains($src, "'update_packages' => \$this->updatePackages"), 'update_packages branch missing');
        // The no-reboot policy: no shell invocation of reboot/shutdown anywhere
        expect(!preg_match('/(exec|shell_exec|system|passthru|proc_open)\s*\(\s*["\'][^"\']*(reboot|shutdown|init 6)/i', $src),
            'TaskAction contains a reboot/shutdown invocation - policy violation!');
        expect(str_contains($src, 'reboot_performed'), 'result should carry explicit reboot_performed=false flag');
        return null;
    });
}

// -------------------------------------------------------------------- parser

if (shouldRun('parser')) {
    section('5. PARSER');

    runTest('UpdateScanner apt/dnf parsers behave', function () use ($agentDir) {
        if (!$agentDir || !file_exists($agentDir . '/Lib/UpdateScanner.php')) {
            return ['warn' => 'agent sources not found next to api/ - skipped'];
        }
        require_once $agentDir . '/Lib/UpdateScanner.php';
        $cls = \FleetManager\Agent\Lib\UpdateScanner::class;

        $apt = $cls::parseAptLine('nginx/jammy-updates 1.18.0-6ubuntu14.4 amd64 [upgradable from: 1.18.0-6ubuntu14.3]');
        expect($apt !== null && $apt['name'] === 'nginx', 'apt line not parsed');
        expect($apt['current'] === '1.18.0-6ubuntu14.3' && $apt['available'] === '1.18.0-6ubuntu14.4', 'apt versions wrong');
        expect($cls::parseAptLine('Listing... Done') === null, 'apt header line must be ignored');
        expect($cls::parseAptLine('') === null, 'empty line must be ignored');

        $dnf = $cls::parseDnfLine('kernel.x86_64    5.14.0-362.el9    baseos');
        expect($dnf !== null && $dnf['name'] === 'kernel' && $dnf['available'] === '5.14.0-362.el9', 'dnf line not parsed');
        expect($cls::parseDnfLine('Obsoleting Packages') === null, 'dnf header line must be ignored');
        return null;
    });
}

finish();

// ------------------------------------------------------------------- summary

function finish(): void {
    global $results, $opts, $logFile;
    runCleanups();

    if ($opts['json']) {
        echo json_encode([
            'total' => count($results['tests']),
            'passed' => $results['passed'],
            'failed' => $results['failed'],
            'warned' => $results['warned'],
            'tests' => $results['tests'],
            'log' => $logFile,
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        $total = count($results['tests']);
        echo "\nSummary: total={$total} passed={$results['passed']} failed={$results['failed']} warned={$results['warned']}\n";
        if ($results['failed'] > 0) {
            echo "Failures:\n";
            foreach ($results['tests'] as $t) {
                if ($t['status'] === 'fail') echo "  - {$t['name']}: {$t['message']}\n";
            }
        }
    }
    logLine("Summary: passed={$results['passed']} failed={$results['failed']} warned={$results['warned']}");
    exit($results['failed'] > 0 ? 1 : 0);
}
