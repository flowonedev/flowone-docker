#!/usr/bin/env php
<?php
/**
 * DockerHealth Test — Phase D (native->docker Fleet agent health).
 *
 * Covers the PURE parsing/mapping in fleet/agent/Lib/DockerHealth.php that turns
 * a `docker ps` compose-label query into the dashboard's server_health service
 * keys. The side-effecty collectors (exec docker) are validated on the Phase E
 * Linux box; here we pin the parse + remap logic that decides what the dashboard
 * shows for a Docker-managed server.
 *
 * The same remap is mirrored in SSHService::mapDockerAppServices() (Fleet side);
 * this test is the canonical coverage for that logic.
 *
 * PURE test — DockerHealth has no side effects on include. Runs with plain PHP.
 *   php fleet/agent/tests/docker-health-test.php --verbose
 * Flags: --help --verbose --json --only=parse,map,contract
 */

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../Lib/DockerHealth.php';

use FleetManager\Agent\Lib\DockerHealth as DH;

$opts = ['help' => false, 'verbose' => false, 'json' => false, 'only' => []];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') $opts['help'] = true;
    elseif ($arg === '--verbose') $opts['verbose'] = true;
    elseif ($arg === '--json') $opts['json'] = true;
    elseif (str_starts_with($arg, '--only=')) $opts['only'] = array_filter(explode(',', substr($arg, 7)));
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}
if ($opts['help']) { echo "Usage: php docker-health-test.php [--verbose] [--json] [--only=parse,map,contract]\n"; exit(0); }

const C_GREEN = "\033[32m"; const C_RED = "\033[31m"; const C_RESET = "\033[0m";
$logDir = __DIR__ . '/../../api/storage/logs';
@mkdir($logDir, 0775, true);
if (!is_dir($logDir) || !is_writable($logDir)) $logDir = sys_get_temp_dir();
$logFile = $logDir . '/docker-health-test-' . date('Ymd-His') . '.log';
$results = ['passed' => 0, 'failed' => 0];

function logLine(string $s): void { global $logFile; @file_put_contents($logFile, $s . "\n", FILE_APPEND); }
function section(string $n): void { global $opts; if (!$opts['json']) echo "\n--- {$n} ---\n"; }
function shouldRun(string $g): bool { global $opts; return empty($opts['only']) || in_array($g, $opts['only'], true); }
function test(string $group, string $name, callable $fn): void {
    global $opts, $results;
    if (!shouldRun($group)) return;
    try {
        $fn();
        $results['passed']++;
        if (!$opts['json']) echo "  " . C_GREEN . "[PASS]" . C_RESET . "  {$name}\n";
        logLine("[PASS] {$name}");
    } catch (\Throwable $e) {
        $results['failed']++;
        if (!$opts['json']) echo "  " . C_RED . "[FAIL]" . C_RESET . "  {$name}\n         " . $e->getMessage() . "\n";
        logLine("[FAIL] {$name}: " . $e->getMessage());
    }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEq($exp, $act, string $m): void { if ($exp !== $act) throw new \RuntimeException($m . " (expected " . var_export($exp, true) . ", got " . var_export($act, true) . ")"); }

echo $opts['json'] ? '' : "=== DockerHealth test — " . date('Y-m-d H:i:s') . " ===\n";

// --- parse ---
section('parse');
test('parse', 'parses service=state lines, lowercases state', function () {
    $states = DH::parsePsLabelOutput(['web=running', 'mariadb=Exited', 'redis=running']);
    assertEq('running', $states['web'], 'web');
    assertEq('exited', $states['mariadb'], 'mariadb lowercased');
    assertTrue(count($states) === 3, 'three services');
});
test('parse', 'ignores blank and malformed lines', function () {
    $states = DH::parsePsLabelOutput(['', '  ', 'garbage-no-equals', 'web=running']);
    assertTrue(count($states) === 1 && isset($states['web']), 'only the valid line survives');
});
test('parse', 'empty input yields empty map', function () {
    assertTrue(DH::parsePsLabelOutput([]) === [], 'empty');
});

// --- map ---
section('map');
test('map', 'running/non-running map to running/stopped under dashboard keys', function () {
    $health = DH::mapContainerStates(['web' => 'running', 'mariadb' => 'exited', 'redis' => 'running']);
    assertEq('running', $health['openlitespeed'], 'web -> openlitespeed running');
    assertEq('stopped', $health['mariadb'], 'exited -> stopped');
    assertEq('running', $health['redis'], 'redis running');
});
test('map', 'every non-running docker state becomes stopped', function () {
    foreach (['exited', 'created', 'paused', 'dead', 'restarting'] as $st) {
        $h = DH::mapContainerStates(['collab' => $st]);
        assertEq('stopped', $h['collab'], "state {$st} -> stopped");
    }
});
test('map', 'unknown compose services are ignored', function () {
    $h = DH::mapContainerStates(['some-sidecar' => 'running', 'web' => 'running']);
    assertTrue(!isset($h['some-sidecar']), 'sidecar not mapped');
    assertTrue(count($h) === 1 && isset($h['openlitespeed']), 'only known services mapped');
});
test('map', 'absent services are not emitted (systemd keeps them)', function () {
    $h = DH::mapContainerStates(['web' => 'running']);
    assertTrue(!isset($h['mariadb']), 'mariadb absent -> not emitted');
});

// --- contract ---
section('contract');
test('contract', 'app-tier service map covers the 6 bridge services', function () {
    $keys = array_keys(DH::SERVICE_TO_HEALTHKEY);
    foreach (['web', 'mariadb', 'redis', 'meilisearch', 'collab', 'mailsync'] as $svc) {
        assertTrue(in_array($svc, $keys, true), "map covers {$svc}");
    }
});
test('contract', 'PROJECT matches the compose project name', function () {
    assertEq('flowone', DH::PROJECT, 'project name');
});

$total = $results['passed'] + $results['failed'];
if ($opts['json']) {
    echo json_encode(['total' => $total, 'passed' => $results['passed'], 'failed' => $results['failed']], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\nSummary: total={$total} passed={$results['passed']} failed={$results['failed']}\n";
    echo "Log: {$logFile}\n";
}
exit($results['failed'] > 0 ? 1 : 0);
