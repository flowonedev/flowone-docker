#!/usr/bin/env php
<?php
/**
 * DockerProvisioningService Test — Phase D (native->docker Fleet refactor).
 *
 * Covers the PURE, side-effect-free surface of DockerProvisioningService: the
 * `docker compose` command builders, the docker-install command, volume seeding,
 * and the `docker compose ps` health parsing / stack-health decision. These are
 * the parts that decide correctness of the remote orchestration, so pinning them
 * with a test lets us trust the SSH driver (validated on the Phase E Linux box)
 * is issuing the right commands.
 *
 * PURE test — only static methods are exercised; the class is never instantiated,
 * so no Container/DB/SSH is required. Runs with a plain PHP CLI.
 *
 *   php fleet/api/tests/docker-provisioning-test.php --verbose
 *
 * Flags: --help --verbose --json --only=commands,parse,health
 * Exit 0 = all pass, 1 = any failure.
 */

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../src/Services/DockerProvisioningService.php';

use FleetManager\Api\Services\DockerProvisioningService as D;

$opts = ['help' => false, 'verbose' => false, 'json' => false, 'only' => []];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') $opts['help'] = true;
    elseif ($arg === '--verbose') $opts['verbose'] = true;
    elseif ($arg === '--json') $opts['json'] = true;
    elseif (str_starts_with($arg, '--only=')) $opts['only'] = array_filter(explode(',', substr($arg, 7)));
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}
if ($opts['help']) { echo "Usage: php docker-provisioning-test.php [--verbose] [--json] [--only=commands,login,livekit,parse,health]\n"; exit(0); }

const C_GREEN = "\033[32m"; const C_RED = "\033[31m"; const C_YELLOW = "\033[33m"; const C_RESET = "\033[0m";
$logDir = __DIR__ . '/../storage/logs';
@mkdir($logDir, 0775, true);
if (!is_dir($logDir) || !is_writable($logDir)) $logDir = sys_get_temp_dir();
$logFile = $logDir . '/docker-provisioning-test-' . date('Ymd-His') . '.log';
$results = ['passed' => 0, 'failed' => 0, 'warned' => 0, 'tests' => []];

function logLine(string $s): void { global $logFile; @file_put_contents($logFile, $s . "\n", FILE_APPEND); }
function section(string $n): void { global $opts; if (!$opts['json']) echo "\n--- {$n} ---\n"; logLine("--- {$n} ---"); }
function shouldRun(string $g): bool { global $opts; return empty($opts['only']) || in_array($g, $opts['only'], true); }
function test(string $group, string $name, callable $fn): void {
    global $opts, $results;
    if (!shouldRun($group)) return;
    $t0 = microtime(true);
    try {
        $fn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $results['passed']++; $results['tests'][] = ['name' => $name, 'status' => 'pass'];
        if (!$opts['json']) echo "  " . C_GREEN . "[PASS]" . C_RESET . "  {$name} ({$ms}ms)\n";
        logLine("[PASS] {$name}");
    } catch (\Throwable $e) {
        $results['failed']++; $results['tests'][] = ['name' => $name, 'status' => 'fail', 'error' => $e->getMessage()];
        if (!$opts['json']) echo "  " . C_RED . "[FAIL]" . C_RESET . "  {$name}\n         " . $e->getMessage() . "\n";
        logLine("[FAIL] {$name}: " . $e->getMessage());
    }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
// Quote-agnostic: escapeshellarg() uses single quotes on Linux (the target) but
// double quotes on Windows (dev). Strip both so assertions hold on either OS.
function unquote(string $s): string { return str_replace(['"', "'"], '', $s); }
function assertContains(string $hay, string $needle, string $m): void {
    if (strpos(unquote($hay), unquote($needle)) === false) throw new \RuntimeException($m . " — '{$needle}' not in: {$hay}");
}

echo $opts['json'] ? '' : "=== DockerProvisioningService test — " . date('Y-m-d H:i:s') . " ===\n";

// --- commands ---
section('commands');
test('commands', 'composeBase pins project + compose file + env file', function () {
    $b = D::composeBase();
    assertContains($b, 'docker compose', 'base');
    assertContains($b, "-p 'flowone'", 'project');
    assertContains($b, '/opt/flowone/docker-compose.yml', 'compose file');
    assertContains($b, '/opt/flowone/.env', 'env file');
});
test('commands', 'pullCmd whole-stack and single-service', function () {
    assertContains(D::pullCmd(), ' pull', 'pull');
    assertTrue(strpos(unquote(D::pullCmd()), 'web') === false, 'whole-stack pull must not name a service');
    assertContains(D::pullCmd('web'), 'pull web', 'single pull');
});
test('commands', 'upCmd whole-stack uses --remove-orphans; single uses --no-deps', function () {
    assertContains(D::upCmd(), 'up -d --remove-orphans', 'whole up');
    assertContains(D::upCmd('web'), 'up -d --no-deps ' . "'web'", 'single up');
    assertTrue(strpos(D::upCmd('web'), '--remove-orphans') === false, 'single-service up must not remove orphans');
});
test('commands', 'psJsonCmd requests JSON format', function () {
    assertContains(D::psJsonCmd(), 'ps --format json', 'ps json');
});
test('commands', 'ensureSchemaCmd execs ensure-schema.php in web via lsphp83', function () {
    $c = D::ensureSchemaCmd();
    assertContains($c, 'exec -T web', 'exec into web');
    assertContains($c, '/usr/local/lsws/lsphp83/bin/php', 'lsphp83 binary');
    assertContains($c, 'scripts/ensure-schema.php', 'ensure-schema script');
});
test('commands', 'dockerInstallCmd is idempotent + uses convenience script', function () {
    $c = D::dockerInstallCmd();
    assertContains($c, 'command -v docker', 'guard');
    assertContains($c, 'get.docker.com', 'convenience script');
    assertContains($c, 'docker compose version', 'compose plugin check');
});
test('commands', 'seedVolumeCmd mounts volume + source and fixes key perms', function () {
    $c = D::seedVolumeCmd(D::JWT_VOLUME, '/tmp/jwt');
    assertContains($c, 'docker run --rm', 'run');
    assertContains($c, "'flowone_jwt_keys':/dst", 'volume mount');
    assertContains($c, "'/tmp/jwt':/src:ro", 'source mount');
    assertContains($c, 'chmod 600', 'private key perms');
});
test('commands', 'restartCmd targets a single service on our project', function () {
    $c = D::restartCmd('mail');
    assertContains($c, 'restart', 'restart verb');
    assertContains($c, 'mail', 'service name');
    assertContains($c, "-p 'flowone'", 'project scope');
});
test('commands', 'certPresentCmd tests the LE fullchain for a lineage', function () {
    $c = D::certPresentCmd('vps.acme.com');
    assertContains($c, 'test -s', 'non-empty file test');
    assertContains($c, '/etc/letsencrypt/live/vps.acme.com/fullchain.pem', 'cert path');
});
test('commands', 'obtainCertsCmd passes email, cert-name and every domain', function () {
    $c = D::obtainCertsCmd('postmaster@acme.com', 'vps.acme.com', ['vps.acme.com', 'email.acme.com', 'panel.acme.com']);
    assertContains($c, '/opt/flowone/obtain-certs.sh', 'helper path');
    assertContains($c, '--email=postmaster@acme.com', 'email');
    assertContains($c, '--cert-name=vps.acme.com', 'cert lineage');
    assertContains($c, 'email.acme.com', 'san domain 1');
    assertContains($c, 'panel.acme.com', 'san domain 2');
});
test('commands', 'createMailboxCmd upserts with quota + stack dir', function () {
    // Alphanumeric password: Windows escapeshellarg() strips ! and % (dev-only
    // quirk); the Linux target quotes them fine. Keep the assertion OS-agnostic.
    $c = D::createMailboxCmd('robert@acme.com', 'S3cretPass', 2048);
    assertContains($c, '/opt/flowone/create-mail-account.sh', 'helper path');
    assertContains($c, '--email=robert@acme.com', 'email');
    assertContains($c, '--password=S3cretPass', 'password');
    assertContains($c, '--quota-mb=2048', 'quota');
    assertContains($c, '--stack-dir=/opt/flowone', 'stack dir');
});
test('commands', 'registryHost strips the namespace, keeps a bare host', function () {
    assertTrue(D::registryHost('ghcr.io/flowonedev') === 'ghcr.io', 'ghcr host');
    assertTrue(D::registryHost('reg.acme.com/team/ns') === 'reg.acme.com', 'nested ns host');
    assertTrue(D::registryHost('ghcr.io') === 'ghcr.io', 'bare host unchanged');
    assertTrue(D::registryHost('  ghcr.io/flowonedev  ') === 'ghcr.io', 'trims whitespace');
});
test('commands', 'dockerLoginCmd feeds the token via --password-stdin (never as an arg)', function () {
    $c = D::dockerLoginCmd('ghcr.io', 'flowone-bot', 'ghp_secrettoken');
    assertContains($c, 'docker login', 'login verb');
    assertContains($c, 'ghcr.io', 'host');
    assertContains($c, '-u ' . "'flowone-bot'", 'user flag');
    assertContains($c, '--password-stdin', 'stdin flag');
    // The token is piped in via printf, not passed as a `docker login` argument.
    assertTrue(strpos($c, '--password ') === false, 'must not use --password with an inline value');
    assertContains($c, 'printf', 'token piped via printf');
});

// --- default login resolution (parity with native resolveMailLogin) ---
section('login');
test('login', 'defaults to robert@<mail-domain> and uses ADMIN_PASS', function () {
    $l = D::resolveDefaultLogin(['MAIL_DOMAIN' => 'acme.com', 'ADMIN_PASS' => 'PanelPass123']);
    assertTrue($l['user'] === 'robert', 'default user robert');
    assertTrue($l['email'] === 'robert@acme.com', 'email');
    assertTrue($l['pass'] === 'PanelPass123', 'password <- ADMIN_PASS');
    assertTrue($l['generated'] === false, 'not generated when ADMIN_PASS present');
});
test('login', 'MAIL_LOGIN_USER/PASS override and local part is sanitised', function () {
    $l = D::resolveDefaultLogin(['MAIL_DOMAIN' => 'mail.acme.com', 'MAIL_LOGIN_USER' => 'Jó Bob!', 'MAIL_LOGIN_PASS' => 'x']);
    assertTrue($l['user'] === 'jbob', 'sanitised local part: ' . $l['user']);
    assertTrue($l['email'] === 'jbob@acme.com', 'mail. prefix stripped from domain: ' . $l['email']);
    assertTrue($l['pass'] === 'x', 'password <- MAIL_LOGIN_PASS');
});
test('login', 'password auto-generated when none supplied', function () {
    $l = D::resolveDefaultLogin(['MAIL_DOMAIN' => 'acme.com']);
    assertTrue($l['generated'] === true, 'generated flag set');
    assertTrue(strlen($l['pass']) >= 12, 'generated password has length');
});

// --- livekit (compose treats LiveKit as opt-in/external) ---
section('livekit');
test('livekit', 'key set + empty ws_url => LiveKit disabled (key/secret cleared)', function () {
    $r = D::normalizeLiveKit(['LIVEKIT_API_KEY' => 'APIabc', 'LIVEKIT_API_SECRET' => 'sek', 'LIVEKIT_WS_URL' => '']);
    assertTrue($r['disabled'] === true, 'should report disabled');
    assertTrue($r['vars']['LIVEKIT_API_KEY'] === '', 'api key cleared');
    assertTrue($r['vars']['LIVEKIT_API_SECRET'] === '', 'api secret cleared');
});
test('livekit', 'whitespace-only ws_url is treated as empty', function () {
    $r = D::normalizeLiveKit(['LIVEKIT_API_KEY' => 'APIabc', 'LIVEKIT_WS_URL' => '   ']);
    assertTrue($r['disabled'] === true, 'blank ws_url disables');
    assertTrue($r['vars']['LIVEKIT_API_KEY'] === '', 'api key cleared');
});
test('livekit', 'key + real ws_url => left untouched (LiveKit enabled)', function () {
    $r = D::normalizeLiveKit(['LIVEKIT_API_KEY' => 'APIabc', 'LIVEKIT_API_SECRET' => 'sek', 'LIVEKIT_WS_URL' => 'wss://acme.com:7443']);
    assertTrue($r['disabled'] === false, 'should not disable when ws_url present');
    assertTrue($r['vars']['LIVEKIT_API_KEY'] === 'APIabc', 'api key preserved');
    assertTrue($r['vars']['LIVEKIT_WS_URL'] === 'wss://acme.com:7443', 'ws_url preserved');
});
test('livekit', 'no key at all => nothing to disable', function () {
    $r = D::normalizeLiveKit(['LIVEKIT_WS_URL' => '']);
    assertTrue($r['disabled'] === false, 'no key means no change');
});

// --- parse ---
section('parse');
test('parse', 'parses a JSON array (newer compose)', function () {
    $raw = json_encode([
        ['Service' => 'web', 'State' => 'running', 'Health' => 'healthy'],
        ['Service' => 'redis', 'State' => 'running', 'Health' => ''],
    ]);
    $states = D::parsePsJson($raw);
    assertTrue(($states['web']['state'] ?? '') === 'running', 'web state');
    assertTrue(($states['web']['health'] ?? '') === 'healthy', 'web health');
    assertTrue(isset($states['redis']), 'redis present');
});
test('parse', 'parses newline-delimited JSON objects (older compose)', function () {
    $raw = "{\"Service\":\"web\",\"State\":\"running\",\"Health\":\"healthy\"}\n"
         . "{\"Service\":\"mariadb\",\"State\":\"running\",\"Health\":\"healthy\"}";
    $states = D::parsePsJson($raw);
    assertTrue(count($states) === 2, 'two services');
    assertTrue(($states['mariadb']['state'] ?? '') === 'running', 'mariadb');
});
test('parse', 'empty output yields empty map', function () {
    assertTrue(D::parsePsJson('') === [], 'empty');
    assertTrue(D::parsePsJson("  \n ") === [], 'whitespace');
});
test('parse', 'falls back to Name when Service key absent', function () {
    $raw = json_encode([['Name' => 'collab', 'State' => 'running']]);
    $states = D::parsePsJson($raw);
    assertTrue(isset($states['collab']), 'named by Name');
});

// --- health ---
section('health');
function allHealthy(): array {
    $s = [];
    foreach (D::SERVICES as $svc) $s[$svc] = ['state' => 'running', 'health' => 'healthy'];
    return $s;
}
test('health', 'all running+healthy => healthy', function () {
    assertTrue(D::isStackHealthy(allHealthy()), 'should be healthy');
});
test('health', 'a missing service => not healthy', function () {
    $s = allHealthy(); unset($s['web']);
    assertTrue(!D::isStackHealthy($s), 'missing web must fail');
});
test('health', 'a not-running service => not healthy', function () {
    $s = allHealthy(); $s['mailsync']['state'] = 'exited';
    assertTrue(!D::isStackHealthy($s), 'exited service must fail');
});
test('health', 'health=starting => not healthy', function () {
    $s = allHealthy(); $s['web']['health'] = 'starting';
    assertTrue(!D::isStackHealthy($s), 'starting must fail');
});
test('health', 'running with no healthcheck (empty health) => healthy', function () {
    $s = allHealthy(); $s['meilisearch']['health'] = '';
    assertTrue(D::isStackHealthy($s), 'no-healthcheck running service is ok');
});

$total = $results['passed'] + $results['failed'] + $results['warned'];
if ($opts['json']) {
    echo json_encode(['total' => $total, 'passed' => $results['passed'], 'failed' => $results['failed'], 'tests' => $results['tests']], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\nSummary: total={$total} passed={$results['passed']} failed={$results['failed']}\n";
    echo "Log: {$logFile}\n";
}
exit($results['failed'] > 0 ? 1 : 0);
