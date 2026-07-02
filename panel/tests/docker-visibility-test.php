#!/usr/bin/env php
<?php
/**
 * Docker Visibility + Mail Pod Bridge Test Suite
 *
 * Verifies the panel's Docker details page data source (DockerInspector)
 * and the mail-pod bridging that makes Mail/Postfix/Dovecot status reflect
 * the Docker layout instead of a false native "Stopped":
 *   - DockerInspector: overview shape, containers/images/volumes/networks
 *     parse, disk usage, compose stack grouping, volume in-use flags.
 *   - MailPodBridge: pod detection, supervised program status, batched
 *     postconf reads, in-pod file reads.
 *   - Actions: mail.status / postfix.status / dovecot.status report
 *     running=true + runtime=docker on a Docker box; config writes are
 *     refused with an actionable message; permission checks stop flagging
 *     container-managed files as missing.
 *   - Wiring: routes registered, controller methods exist, DockerPanel.vue
 *     imported by OverviewView (never-leave-orphans).
 *
 * Read-only suite: no test data is created, nothing external is sent.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/docker-visibility-test.php --verbose
 *
 * Options:
 *   --verbose     extra debug output
 *   --skip-send   n/a (suite is read-only)
 *   --only=GROUP  inspector,mailpod,actions,permissions,wiring
 *   --smoke       pre-flight only
 *   --json        machine-readable output
 *   --help        this text
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1700));
    exit(0);
}

$agentRoot = require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Lib\DockerInspector;
use VpsAdmin\Agent\Lib\MailPodBridge;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('DockerVisibility', $opts);

// Minimal execCommand clone (BaseAction::execCommand without the class).
$execCommand = function (string $command, array $args = [], int $timeout = 0): array {
    $escaped = array_map('escapeshellarg', $args);
    $full = $command . ' ' . implode(' ', $escaped) . ' 2>&1';
    exec($full, $output, $code);
    return [
        'success' => $code === 0,
        'output' => implode("\n", $output),
        'code' => $code,
    ];
};

$dockerBin = trim((string) shell_exec('command -v docker 2>/dev/null'));
$dockerRunning = $dockerBin !== ''
    && trim((string) shell_exec("{$dockerBin} info --format '{{.ServerVersion}}' 2>/dev/null")) !== '';
$stackRunning = $dockerRunning
    && str_contains((string) shell_exec("{$dockerBin} ps --format '{{.Names}}' 2>/dev/null"), 'flowone-');
$podRunning = $dockerRunning
    && str_contains((string) shell_exec("{$dockerBin} ps --format '{{.Names}}' 2>/dev/null"), 'flowone-mail-1');

$inspector = $dockerBin !== '' ? new DockerInspector($execCommand, $dockerBin) : null;
$pod = new MailPodBridge($execCommand);

// Instantiate an agent action without its full DI wiring: only execCommand
// and success()/error() are exercised by the bridged code paths.
function makeAction(string $class): object
{
    $ref = new ReflectionClass($class);
    return $ref->newInstanceWithoutConstructor();
}

function callAction(object $action, string $method, array $params = [])
{
    $m = new ReflectionMethod($action, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($action, [$params, 'flowone-test']);
}

// --- 1. PREFLIGHT ---

$harness->test('preflight', 'PHP >= 8.1 with exec()', function () {
    if (PHP_VERSION_ID < 80100) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'PHP ' . PHP_VERSION . ' too old'];
    }
    if (!function_exists('exec')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'exec disabled'];
    }
});

$harness->test('preflight', 'agent classes load (DockerInspector, MailPodBridge)', function () {
    foreach ([DockerInspector::class, MailPodBridge::class] as $class) {
        if (!class_exists($class)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "autoload failed for {$class}"];
        }
    }
});

$harness->test('preflight', 'docker engine reachable', function () use ($dockerBin, $dockerRunning) {
    if ($dockerBin === '') {
        return ['outcome' => TestHarness::WARN, 'message' => 'docker not installed - inspector groups limited'];
    }
    if (!$dockerRunning) {
        return ['outcome' => TestHarness::WARN, 'message' => 'docker daemon not running'];
    }
});

// --- 2. INSPECTOR ---

$harness->test('inspector', 'overview() returns all seven sections', function () use ($inspector, $dockerRunning) {
    if (!$inspector || !$dockerRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'docker unavailable'];
    }
    $o = $inspector->overview();
    foreach (['info', 'containers', 'images', 'volumes', 'networks', 'disk_usage', 'stacks'] as $key) {
        if (!array_key_exists($key, $o)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "missing section {$key}: " . json_encode(array_keys($o))];
        }
    }
});

$harness->test('inspector', 'engine info carries version + storage driver', function () use ($inspector, $dockerRunning) {
    if (!$inspector || !$dockerRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'docker unavailable'];
    }
    $info = $inspector->engineInfo();
    if (empty($info['available']) || empty($info['server_version']) || empty($info['storage_driver'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => json_encode($info)];
    }
});

$harness->test('inspector', 'containers parse with compose labels', function () use ($inspector, $stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running'];
    }
    $containers = $inspector->containers();
    $flowone = array_filter($containers, fn ($c) => ($c['compose_project'] ?? '') === 'flowone');
    if ($flowone === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'no container carries compose_project=flowone'];
    }
    $c = array_values($flowone)[0];
    foreach (['id', 'name', 'image', 'state', 'status', 'compose_service'] as $key) {
        if (empty($c[$key])) {
            return ['outcome' => TestHarness::FAIL, 'message' => "field {$key} empty: " . json_encode($c)];
        }
    }
});

$harness->test('inspector', 'images parse and flag in-use images', function () use ($inspector, $stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running'];
    }
    $containers = $inspector->containers();
    $images = $inspector->images($containers);
    if ($images === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'no images parsed'];
    }
    $used = array_filter($images, fn ($i) => $i['used_by'] > 0);
    if ($used === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'stack is running but no image marked used'];
    }
});

$harness->test('inspector', 'volumes parse with in-use flags', function () use ($inspector, $stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running'];
    }
    $containers = $inspector->containers();
    $volumes = $inspector->volumes($inspector->volumeNamesInUse($containers));
    if ($volumes === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'no volumes parsed'];
    }
    $inUse = array_filter($volumes, fn ($v) => $v['in_use']);
    if ($inUse === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'stack running but no volume marked in-use'];
    }
});

$harness->test('inspector', 'networks + disk usage parse', function () use ($inspector, $dockerRunning) {
    if (!$inspector || !$dockerRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'docker unavailable'];
    }
    if ($inspector->networks() === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'no networks parsed (bridge/host/none always exist)'];
    }
    if ($inspector->diskUsage() === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'docker system df parsed empty'];
    }
});

$harness->test('inspector', 'compose stacks group the flowone project', function () use ($inspector, $stackRunning) {
    if (!$stackRunning) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'flowone stack not running'];
    }
    $stacks = $inspector->composeStacks($inspector->containers());
    $flowone = array_filter($stacks, fn ($s) => $s['project'] === 'flowone');
    if ($flowone === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'flowone stack not grouped: ' . json_encode($stacks)];
    }
    $s = array_values($flowone)[0];
    if ($s['total'] < 1 || $s['services'] === []) {
        return ['outcome' => TestHarness::FAIL, 'message' => json_encode($s)];
    }
});

// --- 3. MAIL POD BRIDGE ---

$harness->test('mailpod', 'active() matches box layout', function () use ($pod, $podRunning) {
    $nativeUnit = trim((string) shell_exec('systemctl show -p LoadState --value postfix 2>/dev/null'));
    $expected = ($nativeUnit === 'not-found') && $podRunning;
    if ($pod->active() !== $expected) {
        return ['outcome' => TestHarness::FAIL,
            'message' => sprintf('active()=%s expected=%s (unit=%s pod=%s)',
                var_export($pod->active(), true), var_export($expected, true), $nativeUnit, var_export($podRunning, true))];
    }
});

$harness->test('mailpod', 'postfix + dovecot RUNNING via supervisorctl', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    foreach (['postfix', 'dovecot'] as $prog) {
        if (!$pod->programRunning($prog)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$prog} not RUNNING in pod"];
        }
    }
});

$harness->test('mailpod', 'keyValues() batches postconf reads', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $vals = $pod->keyValues('postconf', ['myhostname', 'inet_interfaces']);
    if (empty($vals['myhostname']) || !isset($vals['inet_interfaces'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => json_encode($vals)];
    }
});

$harness->test('mailpod', 'readFile() sees main.cf inside the pod', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $content = $pod->readFile('/etc/postfix/main.cf');
    if ($content === null || !str_contains($content, 'myhostname')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'main.cf unreadable or unexpected content'];
    }
    if (!$pod->fileExists('/etc/dovecot/dovecot.conf')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'dovecot.conf not found in pod'];
    }
});

// --- 4. BRIDGED ACTIONS ---

$harness->test('actions', 'mail.status reports docker runtime + running programs', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $res = callAction(makeAction(\VpsAdmin\Agent\Actions\MailAction::class), 'actionStatus');
    $d = $res['data'] ?? [];
    if (($d['runtime'] ?? '') !== 'docker' || empty($d['postfix']['running']) || empty($d['dovecot']['running'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => json_encode($d)];
    }
    if (empty($d['hostname'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'hostname empty: ' . json_encode($d)];
    }
});

$harness->test('actions', 'postfix.status + settings bridge through the pod', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $action = makeAction(\VpsAdmin\Agent\Actions\PostfixAction::class);
    $status = callAction($action, 'actionStatus')['data'] ?? [];
    if (empty($status['running']) || ($status['runtime'] ?? '') !== 'docker' || empty($status['version'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'status: ' . json_encode($status)];
    }
    $settings = callAction($action, 'actionSettings')['data'] ?? [];
    if (empty($settings['settings']['myhostname']) || empty($settings['read_only'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'settings: ' . json_encode(array_keys($settings['settings'] ?? []))];
    }
});

$harness->test('actions', 'dovecot.status + settings bridge through the pod', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $action = makeAction(\VpsAdmin\Agent\Actions\DovecotAction::class);
    $status = callAction($action, 'actionStatus')['data'] ?? [];
    if (empty($status['running']) || ($status['runtime'] ?? '') !== 'docker') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'status: ' . json_encode($status)];
    }
    $settings = callAction($action, 'actionSettings')['data'] ?? [];
    if (empty($settings['settings']['protocols']) || empty($settings['read_only'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'settings: ' . json_encode(array_keys($settings['settings'] ?? []))];
    }
});

$harness->test('actions', 'config writes are refused with actionable message', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $cases = [
        [\VpsAdmin\Agent\Actions\PostfixAction::class, 'actionUpdateSettings', ['settings' => ['myhostname' => 'x']]],
        [\VpsAdmin\Agent\Actions\PostfixAction::class, 'actionSaveRawConfig', ['content' => '# flowone_test']],
        [\VpsAdmin\Agent\Actions\DovecotAction::class, 'actionUpdateSettings', ['settings' => ['protocols' => 'imap']]],
        [\VpsAdmin\Agent\Actions\DovecotAction::class, 'actionSaveRawConfig', ['content' => '# flowone_test']],
    ];
    foreach ($cases as [$class, $method, $params]) {
        $res = callAction(makeAction($class), $method, $params);
        if (!empty($res['success']) || !str_contains($res['error'] ?? '', 'container')) {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$class}::{$method}: " . json_encode($res)];
        }
    }
});

$harness->test('actions', 'postfix raw config readable (read-only) from pod', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $res = callAction(makeAction(\VpsAdmin\Agent\Actions\PostfixAction::class), 'actionRawConfig', ['file' => '/etc/postfix/main.cf']);
    $d = $res['data'] ?? [];
    if (empty($d['exists']) || empty($d['read_only']) || !str_contains($d['content'] ?? '', 'myhostname')) {
        return ['outcome' => TestHarness::FAIL, 'message' => json_encode(array_diff_key($d, ['content' => 1]))];
    }
});

// --- 5. PERMISSION CHECKS ---

$harness->test('permissions', 'postfix/dovecot permission checks pod-aware', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $action = makeAction(\VpsAdmin\Agent\Actions\SystemAction::class);
    foreach (['postfix', 'dovecot'] as $svc) {
        $res = callAction($action, 'actionCheckPermissions', ['service' => $svc]);
        $d = $res['data'] ?? [];
        if (($d['runtime'] ?? '') !== 'docker') {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$svc} not marked docker: " . json_encode($d)];
        }
        if (empty($d['ok'])) {
            $bad = array_filter($d['configs'] ?? [], fn ($c) => !$c['ok']);
            return ['outcome' => TestHarness::FAIL, 'message' => "{$svc} still failing: " . json_encode(array_column($bad, 'path'))];
        }
    }
});

$harness->test('permissions', 'fixPermissions refused for pod-managed services', function () use ($pod) {
    if (!$pod->active()) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'mail pod not active on this box'];
    }
    $res = callAction(makeAction(\VpsAdmin\Agent\Actions\SystemAction::class), 'actionFixPermissions', ['service' => 'postfix']);
    if (!empty($res['success']) || !str_contains($res['error'] ?? '', 'container')) {
        return ['outcome' => TestHarness::FAIL, 'message' => json_encode($res)];
    }
});

// --- 6. WIRING (static, works on dev + server) ---

$harness->test('wiring', 'docker overview/volumes/networks routes registered', function () use ($agentRoot) {
    $routes = dirname($agentRoot) . '/api/routes.php';
    if (!file_exists($routes)) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'routes.php not found next to agent'];
    }
    $src = (string) file_get_contents($routes);
    foreach (['/api/docker/overview', '/api/docker/volumes', '/api/docker/networks'] as $route) {
        if (!str_contains($src, "'{$route}'")) {
            return ['outcome' => TestHarness::FAIL, 'message' => "route {$route} not registered"];
        }
    }
});

$harness->test('wiring', 'DockerController exposes overview/volumes/networks', function () use ($agentRoot) {
    $controller = dirname($agentRoot) . '/api/src/Controllers/DockerController.php';
    if (!file_exists($controller)) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'DockerController not found next to agent'];
    }
    $src = (string) file_get_contents($controller);
    foreach (['function overview', 'function volumes', 'function networks'] as $needle) {
        if (!str_contains($src, $needle)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "{$needle} missing"];
        }
    }
});

$harness->test('wiring', 'DockerAction registers the new methods', function () {
    $action = makeAction(\VpsAdmin\Agent\Actions\DockerAction::class);
    $methods = $action->getMethods();
    foreach (['overview', 'volumes', 'networks'] as $m) {
        if (!in_array($m, $methods, true)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "docker.{$m} not registered"];
        }
    }
});

$harness->test('wiring', 'DockerPanel.vue exists and is mounted by OverviewView', function () use ($agentRoot) {
    // Frontend source is only present in the repo (dev box), not on servers.
    $dash = dirname($agentRoot) . '/dashboard/src';
    if (!is_dir($dash)) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'dashboard source not on this box (built dist only)'];
    }
    if (!file_exists($dash . '/components/DockerPanel.vue')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'DockerPanel.vue missing'];
    }
    $overviewSrc = (string) file_get_contents($dash . '/views/OverviewView.vue');
    if (!str_contains($overviewSrc, "import DockerPanel from '@/components/DockerPanel.vue'")
        || !str_contains($overviewSrc, '<DockerPanel />')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'DockerPanel not imported/rendered by OverviewView'];
    }
});

exit($harness->run());
