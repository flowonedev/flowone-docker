#!/usr/bin/env php
<?php
/**
 * HealthController Hybrid-Awareness Test Suite
 *
 * Verifies the panel System Health page understands the hybrid layout
 * (native OLS/PHP + Docker compose stack for mariadb/redis/mail/etc):
 *   - dockerStates() sees the flowone containers and mail-pod programs.
 *   - serviceState() falls back from systemd to docker and reports
 *     containerized services as running with a docker-flavored fix cmd.
 *   - checkServices() marks no containerized service as failed while its
 *     container is up.
 *   - checkSSL() never offers a certbot fix for a domain with no public
 *     A record (the fix would be guaranteed to fail at ACME validation).
 *   - fix() whitelist accepts the docker restart / supervisorctl commands
 *     the new checks emit.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/health-hybrid-test.php --verbose
 *
 * Options:
 *   --verbose
 *   --skip-send   n/a (suite is read-only, nothing external is sent)
 *   --only=GROUP  docker,services,ssl,whitelist
 *   --smoke
 *   --json
 *   --help
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1600));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Tests\Lib\TestHarness;

// The bootstrap wires the agent autoloader; the HealthController lives in
// the API tree, so register that namespace too (local dev + production).
$apiCandidates = [__DIR__ . '/../api/src', '/var/www/vps-admin/api/src'];
$apiRoot = null;
foreach ($apiCandidates as $candidate) {
    if (is_dir($candidate)) {
        $apiRoot = realpath($candidate);
        break;
    }
}
if ($apiRoot === null) {
    fwrite(STDERR, "TEST BOOTSTRAP FAIL: api src not found in: " . implode(', ', $apiCandidates) . "\n");
    exit(2);
}
spl_autoload_register(function (string $class) use ($apiRoot): void {
    $prefix = 'VpsAdmin\\Api\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = $apiRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

$harness = new TestHarness('HealthHybrid', $opts);

/**
 * HealthController needs a DI container in its constructor, but every
 * method under test only uses shell_exec + local state, so we bypass
 * the constructor and poke privates via reflection.
 */
function makeController(): object
{
    $ref = new ReflectionClass(\VpsAdmin\Api\Controllers\HealthController::class);
    return $ref->newInstanceWithoutConstructor();
}

function callPrivate(object $obj, string $method, array $args = [])
{
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
}

function readPrivate(object $obj, string $prop)
{
    $p = new ReflectionProperty($obj, $prop);
    $p->setAccessible(true);
    return $p->getValue($obj);
}

$dockerAvailable = trim((string) shell_exec('command -v docker 2>/dev/null')) !== '';
$stackRunning = $dockerAvailable
    && str_contains((string) shell_exec("docker ps --format '{{.Names}}' 2>/dev/null"), 'flowone-');

// --- 1. PREFLIGHT ---

$harness->test('preflight', 'PHP >= 8.1 with required functions', function () {
    if (PHP_VERSION_ID < 80100) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'PHP ' . PHP_VERSION . ' too old'];
    }
    if (!function_exists('shell_exec')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'shell_exec disabled'];
    }
});

$harness->test('preflight', 'HealthController class is loadable', function () {
    if (!class_exists(\VpsAdmin\Api\Controllers\HealthController::class)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'autoload failed for HealthController'];
    }
});

$harness->test('preflight', 'docker binary present', function () use ($dockerAvailable) {
    if (!$dockerAvailable) {
        return ['outcome' => TestHarness::WARN, 'message' => 'docker not installed - docker groups will be limited'];
    }
});

// --- 2. DOCKER STATE SNAPSHOT ---

$harness->test('docker', 'dockerStates() returns containers + programs maps', function () {
    $states = callPrivate(makeController(), 'dockerStates');
    if (!is_array($states) || !array_key_exists('containers', $states) || !array_key_exists('programs', $states)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'unexpected shape: ' . json_encode($states)];
    }
});

$harness->test('docker', 'flowone containers visible when stack runs', function () use ($stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running on this box'];
    }
    $states = callPrivate(makeController(), 'dockerStates');
    if (empty($states['containers']['flowone-mariadb-1'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'flowone-mariadb-1 not seen as up: ' . json_encode(array_keys($states['containers']))];
    }
});

$harness->test('docker', 'mail pod programs parsed from supervisorctl', function () use ($stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running on this box'];
    }
    $states = callPrivate(makeController(), 'dockerStates');
    if (empty($states['containers']['flowone-mail-1'])) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not running'];
    }
    foreach (['postfix', 'dovecot'] as $prog) {
        if (empty($states['programs'][$prog])) {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$prog} not RUNNING in mail pod: " . json_encode(array_keys($states['programs']))];
        }
    }
});

$harness->test('docker', 'dockerStates() is cached (single docker fork per request)', function () {
    $ctl = makeController();
    $first = callPrivate($ctl, 'dockerStates');
    $second = callPrivate($ctl, 'dockerStates');
    if ($first !== $second) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'second call returned different data'];
    }
    $cached = readPrivate($ctl, 'dockerStates');
    if ($cached === null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'cache property not populated'];
    }
});

// --- 3. SERVICE STATE RESOLUTION ---

$harness->test('services', 'containerized services resolve as running via docker', function () use ($stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running on this box'];
    }
    $ctl = makeController();
    foreach (['mariadb', 'redis-server', 'postfix', 'dovecot'] as $svc) {
        [$running, $detail] = callPrivate($ctl, 'serviceState', [$svc]);
        if (!$running) {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$svc} reported down: {$detail}"];
        }
    }
});

$harness->test('services', 'docker-backed fix commands target docker, not systemctl', function () use ($stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running on this box'];
    }
    $ctl = makeController();
    $state = callPrivate($ctl, 'dockerServiceState', ['mariadb']);
    if ($state === null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'mariadb has no docker mapping'];
    }
    if (!str_starts_with((string) $state[2], 'docker restart flowone-')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'unexpected fix cmd: ' . $state[2]];
    }
    $podState = callPrivate($ctl, 'dockerServiceState', ['dovecot']);
    if ($podState !== null && !str_contains((string) $podState[2], 'supervisorctl restart')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'unexpected mail pod fix cmd: ' . $podState[2]];
    }
});

$harness->test('services', 'checkServices() flags no running container as failed', function () use ($stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running on this box'];
    }
    $ctl = makeController();
    callPrivate($ctl, 'checkServices');
    $containerBacked = ['MariaDB', 'Redis', 'Postfix', 'Dovecot', 'OpenDKIM', 'OpenDMARC'];
    foreach (readPrivate($ctl, 'checks') as $check) {
        if (in_array($check['name'], $containerBacked, true) && $check['status'] === 'fail') {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$check['name']} failed: {$check['detail']}"];
        }
    }
});

$harness->test('services', 'unknown service falls through to systemctl fix', function () {
    $state = callPrivate(makeController(), 'serviceState', ['flowone-test-no-such-unit']);
    if ($state[0] !== false || $state[2] !== 'systemctl restart flowone-test-no-such-unit') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'fallback broken: ' . json_encode($state)];
    }
});

// --- 4. SSL DNS GATE ---

$harness->test('ssl', 'no certbot fix offered for unresolvable domains', function () {
    if (!file_exists('/var/www/vps-admin/api/config.local.php')) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'panel config.local.php not on this box'];
    }
    if (trim((string) shell_exec('command -v dig 2>/dev/null')) === '') {
        return ['outcome' => TestHarness::SKIP, 'message' => 'dig not installed, DNS gate inactive'];
    }
    $ctl = makeController();
    callPrivate($ctl, 'checkSSL');
    foreach (readPrivate($ctl, 'checks') as $check) {
        if ($check['category'] !== 'ssl' || empty($check['fix_command'])) {
            continue;
        }
        if (!preg_match('/-d\s+(\S+)/', $check['fix_command'], $m)) {
            continue;
        }
        $resolved = trim((string) shell_exec("dig +short {$m[1]} A @1.1.1.1 2>/dev/null | head -n1"));
        if ($resolved === '') {
            return ['outcome' => TestHarness::FAIL, 'message' => "certbot fix offered for unresolvable {$m[1]}"];
        }
    }
});

$harness->test('ssl', 'unresolvable apex reports actionable A-record hint', function () {
    if (!file_exists('/var/www/vps-admin/api/config.local.php')) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'panel config.local.php not on this box'];
    }
    if (trim((string) shell_exec('command -v dig 2>/dev/null')) === '') {
        return ['outcome' => TestHarness::SKIP, 'message' => 'dig not installed, DNS gate inactive'];
    }
    $conf = include '/var/www/vps-admin/api/config.local.php';
    $apex = preg_replace('/^panel\./', '', (string) ($conf['panel_domain'] ?? ''));
    if ($apex === '') {
        return ['outcome' => TestHarness::SKIP, 'message' => 'no panel_domain configured'];
    }
    $resolved = trim((string) shell_exec("dig +short {$apex} A @1.1.1.1 2>/dev/null | head -n1"));
    if ($resolved !== '') {
        return ['outcome' => TestHarness::SKIP, 'message' => "{$apex} resolves publicly, gate not exercised"];
    }
    $ctl = makeController();
    callPrivate($ctl, 'checkSSL');
    foreach (readPrivate($ctl, 'checks') as $check) {
        if ($check['category'] === 'ssl' && str_contains($check['name'], $apex)) {
            if ($check['status'] !== 'warning' || !str_contains($check['detail'], 'A record')) {
                return ['outcome' => TestHarness::FAIL, 'message' => "apex check wrong: " . json_encode($check)];
            }
            return null;
        }
    }
    return ['outcome' => TestHarness::FAIL, 'message' => "no ssl check emitted for apex {$apex}"];
});

// --- 5. FIX WHITELIST ---

$harness->test('whitelist', 'fix() whitelist accepts the docker fix commands', function () use ($apiRoot) {
    $src = (string) file_get_contents($apiRoot . '/Controllers/HealthController.php');
    foreach (["'docker restart flowone-'", "'docker exec flowone-mail-1 supervisorctl restart '"] as $needle) {
        if (!str_contains($src, $needle)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "whitelist missing {$needle}"];
        }
    }
});

exit($harness->run());
