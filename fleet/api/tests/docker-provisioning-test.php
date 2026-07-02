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
if ($opts['help']) { echo "Usage: php docker-provisioning-test.php [--verbose] [--json] [--only=commands,ssl,login,credentials,livekit,parse,health,steps]\n"; exit(0); }

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
    // Private key: root:nogroup 640 so non-root containers read it via group;
    // public key world-readable.
    assertContains($c, 'chown 0:65534 /dst/jwt-private.pem', 'private key ownership');
    assertContains($c, 'chmod 640 /dst/jwt-private.pem', 'private key perms');
    assertContains($c, 'chmod 644 /dst/jwt-public.pem', 'public key perms');
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

// --- ssl domain resolution filter (one missing A record must not fail the cert) ---
section('ssl');
test('ssl', 'resolveHostsCmd loops the domains via getent and prints "domain ip"', function () {
    $c = D::resolveHostsCmd(['panel.acme.com', 'email.acme.com']);
    assertContains($c, 'getent ahostsv4', 'uses getent (always present)');
    assertContains($c, 'panel.acme.com', 'domain 1');
    assertContains($c, 'email.acme.com', 'domain 2');
    assertContains($c, 'none', 'empty-resolution sentinel');
});
test('ssl', 'resolveHostsCmd with no domains is a no-op', function () {
    assertTrue(D::resolveHostsCmd([]) === 'true', 'empty list => true');
});
test('ssl', 'parseResolvableHosts keeps ONLY domains pointing at the box IP', function () {
    $raw = "devcon3.hu none\npanel.devcon3.hu 85.155.242.130\nemail.devcon3.hu 85.155.242.130\nmail.devcon3.hu none";
    $ok = D::parseResolvableHosts($raw, '85.155.242.130');
    assertTrue(in_array('panel.devcon3.hu', $ok, true), 'panel kept');
    assertTrue(in_array('email.devcon3.hu', $ok, true), 'email kept');
    assertTrue(!in_array('devcon3.hu', $ok, true), 'apex (no A) dropped');
    assertTrue(!in_array('mail.devcon3.hu', $ok, true), 'mail (no A) dropped');
    assertTrue(count($ok) === 2, 'exactly the two resolving hosts');
});
test('ssl', 'parseResolvableHosts drops domains pointing at a DIFFERENT ip', function () {
    $raw = "a.acme.com 1.2.3.4\nb.acme.com 85.155.242.130";
    $ok = D::parseResolvableHosts($raw, '85.155.242.130');
    assertTrue($ok === ['b.acme.com'], 'only the matching host survives');
});
test('ssl', 'parseResolvableHosts with empty target ip keeps nothing', function () {
    $raw = "a.acme.com 1.2.3.4";
    assertTrue(D::parseResolvableHosts($raw, '') === [], 'no ip => no domains');
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

// --- credentials (Server Credentials panel parity for Docker boxes) ---
section('credentials');
$credVars = [
    'ADMIN_EMAIL' => 'admin@acme.com', 'ADMIN_PASS' => 'PanelPass123',
    'DB_ROOT_PASS' => 'rootpw', 'PANEL_DB_NAME' => 'devc_vps_dash', 'PANEL_DB_USER' => 'vpsadmin', 'PANEL_DB_PASS' => 'panelpw',
    'MAIL_DB_NAME' => 'mailserver', 'MAIL_DB_USER' => 'mailuser', 'MAIL_DB_PASS' => 'mailpw',
    'REDIS_PASS' => 'redispw', 'MEILI_MASTER_KEY' => 'meilikey',
    'EMAIL_API_KEY' => 'apikey', 'JWT_SECRET' => 'jwtsec', 'ENCRYPTION_KEY' => 'enckey',
    'MAIL_DOMAIN' => 'acme.com', 'PANEL_DOMAIN' => 'panel.acme.com', 'SERVER_IP' => '85.155.242.130',
];
/** @return array{0:string,1:string,2:string,3:string,4:bool}|null */
function findCred(array $rows, string $key): ?array {
    foreach ($rows as $r) { if (($r[1] ?? '') === $key) return $r; }
    return null;
}
test('credentials', 'includes panel admin login (email/user/pass) with pass marked secret', function () use ($credVars) {
    $rows = D::buildCredentialRows($credVars);
    assertTrue(findCred($rows, 'ADMIN_EMAIL')[3] === 'admin@acme.com', 'admin email');
    assertTrue(findCred($rows, 'ADMIN_USER')[3] === 'pxradmin', 'admin user defaults to pxradmin');
    $pass = findCred($rows, 'ADMIN_PASS');
    assertTrue($pass[3] === 'PanelPass123' && $pass[4] === true, 'admin pass present + secret');
});
test('credentials', 'includes all DB passwords (root/panel/mail) marked secret', function () use ($credVars) {
    $rows = D::buildCredentialRows($credVars);
    foreach (['DB_ROOT_PASS' => 'rootpw', 'PANEL_DB_PASS' => 'panelpw', 'MAIL_DB_PASS' => 'mailpw'] as $k => $v) {
        $r = findCred($rows, $k);
        assertTrue($r !== null && $r[3] === $v && $r[4] === true, "{$k} present + secret");
    }
});
test('credentials', 'includes redis/meili/api/jwt/encryption secrets', function () use ($credVars) {
    $rows = D::buildCredentialRows($credVars);
    foreach (['REDIS_PASS', 'MEILI_MASTER_KEY', 'EMAIL_API_KEY', 'JWT_SECRET', 'ENCRYPTION_KEY'] as $k) {
        $r = findCred($rows, $k);
        assertTrue($r !== null && $r[3] !== '' && $r[4] === true, "{$k} present + secret");
    }
});
test('credentials', 'derives the mailbox login (robert@domain <- ADMIN_PASS)', function () use ($credVars) {
    $rows = D::buildCredentialRows($credVars);
    assertTrue(findCred($rows, 'MAIL_ADMIN_EMAIL')[3] === 'robert@acme.com', 'mailbox email');
    $mp = findCred($rows, 'MAIL_ADMIN_PASS');
    assertTrue($mp[3] === 'PanelPass123' && $mp[4] === true, 'mailbox pass <- ADMIN_PASS + secret');
});
test('credentials', 'computes SPF/DMARC/MX from PANEL_DOMAIN base + SERVER_IP', function () use ($credVars) {
    $rows = D::buildCredentialRows($credVars);
    assertContains(findCred($rows, 'SPF_RECORD')[3], 'ip4:85.155.242.130', 'spf carries the box ip');
    assertContains(findCred($rows, 'MX_RECORD')[3], '10 acme.com', 'mx points at base domain');
    assertTrue(findCred($rows, 'DMARC_NAME')[3] === '_dmarc.acme.com', 'dmarc record name');
});
test('credentials', 'every row uses an allowed category (matches getCredentials ordering)', function () use ($credVars) {
    $allowed = ['panel', 'ssh', 'database', 'mail', 'services', 'agent', 'secrets', 'dns'];
    foreach (D::buildCredentialRows($credVars) as $r) {
        assertTrue(in_array($r[0], $allowed, true), "unexpected category: {$r[0]}");
    }
});
test('credentials', 'unset optional vars yield empty values (writer skips them)', function () {
    $rows = D::buildCredentialRows(['MAIL_DOMAIN' => 'acme.com']);
    assertTrue(findCred($rows, 'DKIM_DNS_RECORD')[3] === '', 'no DKIM => empty value');
    assertTrue(findCred($rows, 'REDIS_PASS')[3] === '', 'no redis pass => empty value');
    // Mailbox still resolves even with no ADMIN_PASS (auto-generated), so it is never empty.
    assertTrue(findCred($rows, 'MAIL_ADMIN_PASS')[3] !== '', 'mailbox pass auto-generated');
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
test('parse', 'full-stack ps (incl. host-net mail) yields every container for the status panel', function () {
    // Mirrors what GET /servers/{id}/docker-status feeds the dashboard: all 7
    // containers, mail included (host network mode still shows in compose ps).
    $rows = [];
    foreach (['mariadb', 'redis', 'meilisearch', 'web', 'collab', 'mailsync', 'mail'] as $svc) {
        $rows[] = ['Service' => $svc, 'State' => 'running', 'Health' => 'healthy'];
    }
    $states = D::parsePsJson(json_encode($rows));
    assertTrue(count($states) === 7, 'all 7 containers surfaced');
    assertTrue(isset($states['mail']), 'mail container included for the panel');
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
test('health', 'extra host-net mail container does not break the managed-stack verdict', function () {
    // The status endpoint reports mail too, but isStackHealthy() only gates on
    // the managed bridge-net SERVICES; a present-and-healthy mail must not flip it.
    $s = allHealthy(); $s['mail'] = ['state' => 'running', 'health' => 'healthy'];
    assertTrue(D::isStackHealthy($s), 'mail present + healthy stays healthy');
});

// --- steps (deployment_steps timeline plans — every deploy type must have one) ---
section('steps');
test('steps', 'PROVISION_STEPS carries the full 11-phase provision plan', function () {
    $keys = array_keys(D::PROVISION_STEPS);
    assertTrue(count($keys) === 11, 'expected 11 provision steps, got ' . count($keys));
    assertTrue($keys[0] === 'connect', 'starts with connect');
    assertTrue(end($keys) === 'harden', 'ends with harden');
});
test('steps', 'updateStepPlan: fixed prep phases then one step per service, in order', function () {
    $plan = D::updateStepPlan(['web', 'mail']);
    $keys = array_keys($plan);
    assertTrue($keys === ['connect', 'ship_files', 'render_env', 'registry_login', 'update_web', 'update_mail'],
        'unexpected plan order: ' . implode(',', $keys));
    assertTrue($plan['update_web'] === 'Update web (pull + recreate)', 'service step name');
});
test('steps', 'updateStepPlan for a single service yields 5 steps', function () {
    assertTrue(count(D::updateStepPlan(['collab'])) === 5, '4 prep + 1 service');
});
test('steps', 'updateService seeds + advances the step timeline (no more 0/0 steps)', function () {
    $src = file_get_contents(__DIR__ . '/../src/Services/DockerProvisioningService.php');
    // seedSteps(updateStepPlan(...)) must be called inside updateService()
    $body = substr($src, strpos($src, 'public function updateService'));
    $body = substr($body, 0, strpos($body, 'private function warmSchema'));
    assertTrue(strpos($body, 'seedSteps(self::updateStepPlan($services))') !== false, 'updateService must seed its step plan');
    assertTrue(strpos($body, "step('connect')") !== false, 'connect step advanced');
    assertTrue(strpos($body, "step('update_' . \$service)") !== false, 'per-service step advanced');
    assertTrue(strpos($body, 'failCurrentStep') !== false, 'failure marks the running step failed');
});
test('steps', 'panel update: ProvisioningService exposes PANEL_UPDATE_STEPS + phase hook', function () {
    $src = file_get_contents(__DIR__ . '/../src/Services/ProvisioningService.php');
    assertTrue(strpos($src, 'const PANEL_UPDATE_STEPS') !== false, 'PANEL_UPDATE_STEPS plan exists');
    assertTrue(strpos($src, 'public function updatePanel(int $serverId, ?callable $onPhase = null)') !== false,
        'updatePanel accepts the phase hook');
    foreach (['connect', 'shared_lib', 'deploy_panel', 'restart_agent'] as $phase) {
        assertTrue(strpos($src, "\$phase('{$phase}')") !== false, "phase '{$phase}' fired in updatePanel");
    }
});
test('steps', 'cli/update-panel.php seeds deployment_steps from PANEL_UPDATE_STEPS', function () {
    $src = file_get_contents(__DIR__ . '/../cli/update-panel.php');
    assertTrue(strpos($src, 'PANEL_UPDATE_STEPS') !== false, 'CLI reads the shared plan');
    assertTrue(strpos($src, 'INSERT IGNORE INTO deployment_steps') !== false, 'CLI seeds step rows');
    assertTrue(strpos($src, 'steps_total') !== false, 'CLI sets steps_total');
    assertTrue(strpos($src, '$svc->updatePanel($serverId, $step)') !== false, 'CLI passes the step cursor as the phase hook');
});

$total = $results['passed'] + $results['failed'] + $results['warned'];
if ($opts['json']) {
    echo json_encode(['total' => $total, 'passed' => $results['passed'], 'failed' => $results['failed'], 'tests' => $results['tests']], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\nSummary: total={$total} passed={$results['passed']} failed={$results['failed']}\n";
    echo "Log: {$logFile}\n";
}
exit($results['failed'] > 0 ? 1 : 0);
