#!/usr/bin/env php
<?php
/**
 * ComposeEnvRenderer Test — Phase D (native->docker Fleet refactor).
 *
 * Validates that ComposeEnvRenderer maps a Fleet server-variables array to a
 * correct per-host Docker Compose `.env`:
 *   - every required key present; hosts rewritten to compose service names
 *   - URLs derived from EMAIL_DOMAIN; secrets mapped to the right .env keys
 *   - no placeholder / example values leak through
 *   - loud failure on a missing non-regenerable secret and on the LiveKit
 *     ws_url landmine
 *
 * PURE test — the renderer has no DB/SSH/Container deps, so this runs anywhere
 * with a PHP CLI (no Fleet bootstrap, no database). Read-only; nothing to clean up.
 *
 *   php fleet/api/tests/compose-env-renderer-test.php --verbose
 *
 * Flags: --help --verbose --json --only=group1,group2
 * Groups: preflight, render, hosts, urls, secrets, ssl, mail, guards, format
 * Exit 0 = all pass, 1 = any failure.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../src/Services/ComposeEnvRenderer.php';

use FleetManager\Api\Services\ComposeEnvRenderer;

// ------------------------------------------------------------------ arguments
$opts = ['help' => false, 'verbose' => false, 'json' => false, 'only' => []];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') $opts['help'] = true;
    elseif ($arg === '--verbose') $opts['verbose'] = true;
    elseif ($arg === '--json') $opts['json'] = true;
    elseif (str_starts_with($arg, '--only=')) $opts['only'] = array_filter(explode(',', substr($arg, 7)));
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}
if ($opts['help']) {
    echo "Usage: php compose-env-renderer-test.php [--verbose] [--json] [--only=render,guards,...]\n";
    echo "Groups: preflight, render, hosts, urls, secrets, ssl, mail, guards, format\n";
    exit(0);
}

// ----------------------------------------------------------------- mini runner
const C_GREEN = "\033[32m"; const C_RED = "\033[31m"; const C_YELLOW = "\033[33m"; const C_RESET = "\033[0m";
$logDir = __DIR__ . '/../storage/logs';
@mkdir($logDir, 0775, true);
if (!is_dir($logDir) || !is_writable($logDir)) $logDir = sys_get_temp_dir();
$logFile = $logDir . '/compose-env-renderer-test-' . date('Ymd-His') . '.log';
$results = ['passed' => 0, 'failed' => 0, 'warned' => 0, 'tests' => []];

function logLine(string $s): void { global $logFile; @file_put_contents($logFile, $s . "\n", FILE_APPEND); }
function section(string $name): void { global $opts; if (!$opts['json']) echo "\n--- {$name} ---\n"; logLine("--- {$name} ---"); }
function shouldRun(string $group): bool { global $opts; return empty($opts['only']) || in_array($group, $opts['only'], true); }

function test(string $group, string $name, callable $fn): void {
    global $opts, $results;
    if (!shouldRun($group)) return;
    $t0 = microtime(true);
    try {
        $r = $fn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($r === 'warn') {
            $results['warned']++; $results['tests'][] = ['name' => $name, 'status' => 'warn'];
            if (!$opts['json']) echo "  " . C_YELLOW . "[WARN]" . C_RESET . "  {$name} ({$ms}ms)\n";
            logLine("[WARN] {$name} ({$ms}ms)");
        } else {
            $results['passed']++; $results['tests'][] = ['name' => $name, 'status' => 'pass'];
            if (!$opts['json']) echo "  " . C_GREEN . "[PASS]" . C_RESET . "  {$name} ({$ms}ms)\n";
            logLine("[PASS] {$name} ({$ms}ms)");
        }
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $results['failed']++; $results['tests'][] = ['name' => $name, 'status' => 'fail', 'error' => $e->getMessage()];
        if (!$opts['json']) echo "  " . C_RED . "[FAIL]" . C_RESET . "  {$name} ({$ms}ms)\n         " . $e->getMessage() . "\n";
        logLine("[FAIL] {$name} ({$ms}ms): " . $e->getMessage());
        if ($opts['verbose'] && !$opts['json']) echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function assertTrue($cond, string $msg): void { if (!$cond) throw new \RuntimeException($msg); }
function assertEq($exp, $act, string $msg): void {
    if ($exp !== $act) throw new \RuntimeException($msg . " (expected '" . var_export($exp, true) . "', got '" . var_export($act, true) . "')");
}

/** Parse a rendered .env into an assoc array; throws on malformed / duplicate lines. */
function parseEnv(string $body): array {
    $map = [];
    foreach (explode("\n", $body) as $line) {
        $line = rtrim($line, "\r");
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) {
            throw new \RuntimeException("malformed .env line: {$line}");
        }
        if (array_key_exists($m[1], $map)) throw new \RuntimeException("duplicate key: {$m[1]}");
        $map[$m[1]] = $m[2];
    }
    return $map;
}

/** Representative variables array, as TemplateService::generateServerVariables would produce. */
function sampleVars(array $overrides = []): array {
    $base = [
        'EMAIL_DOMAIN' => 'email.acme.com',
        'PANEL_DOMAIN' => 'panel.acme.com',
        'MAIL_DOMAIN' => 'acme.com',
        'ADMIN_EMAIL' => 'admin@acme.com',
        'EMAIL_DB_NAME' => 'devc_vps_dash',
        'EMAIL_DB_USER' => 'vpsadmin',
        'EMAIL_DB_PASS' => 'Sh4redDbPassAbc',
        'DB_ROOT_PASS' => 'R00tPassXyz9',
        'MAIL_DB_NAME' => 'mailserver',
        'MAIL_DB_USER' => 'mailuser',
        'MAIL_DB_PASS' => 'MailPass123',
        'REDIS_PASS' => 'RedisPass456',
        'MEILI_MASTER_KEY' => str_repeat('a', 32),
        'MEILI_SEARCH_KEY' => str_repeat('b', 32),
        'IMAP_ENCRYPTION_KEY' => str_repeat('c', 64),
        'AI_ENCRYPTION_KEY' => str_repeat('d', 64),
        'ENCRYPTION_KEY' => str_repeat('e', 64),
        'OAUTH_KEYS' => '',
        'OAUTH_CURRENT_VERSION' => '1',
        'SSO_SERVER_KEY' => 'ssoKey789',
        'EMAIL_API_KEY' => str_repeat('f', 64),
        'LIVEKIT_API_KEY' => 'APIabcdef1234',
        'LIVEKIT_API_SECRET' => 'livekitsecretvalue',
        'LIVEKIT_WS_URL' => 'wss://acme.com:7443',
        'STUN_URL' => 'stun:turn.acme.com:3478',
        'TURN_URL' => 'turn:turn.acme.com:3478',
        'TURN_SECRET' => 'turnSecretVal',
    ];
    return array_replace($base, $overrides);
}

// ================================================================== tests

echo $opts['json'] ? '' : "=== ComposeEnvRenderer test — " . date('Y-m-d H:i:s') . " ===\n";
logLine("=== ComposeEnvRenderer test — " . date('Y-m-d H:i:s') . " ===");

$renderer = new ComposeEnvRenderer();

// --- preflight ---
section('preflight');
test('preflight', 'ComposeEnvRenderer class exists', function () {
    assertTrue(class_exists(ComposeEnvRenderer::class), 'class not autoloaded');
    return true;
});
test('preflight', 'clean sample validates with zero problems', function () use ($renderer) {
    $p = $renderer->validate(sampleVars());
    assertTrue($p === [], 'unexpected problems: ' . implode('; ', $p));
    return true;
});

// --- render ---
section('render');
test('render', 'renders and parses into KEY=VALUE with no dupes', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars()));
    assertTrue(count($env) >= 45, 'expected >=45 keys, got ' . count($env));
    return true;
});
test('render', 'all canonical keys are present', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars()));
    $expected = ['EMAIL_DOMAIN','FRONTEND_URL','API_URL','APP_ENV','APP_DEBUG','ENABLE_SSL','SSL_CERT_FILE','SSL_KEY_FILE',
        'DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','MAIL_DB_HOST','MAIL_DB_NAME','MAIL_DB_USER','MAIL_DB_PASS',
        'MAIL_DOMAIN','SERVER_FQDN','SERVER_IP','ADMIN_EMAIL','MAIL_ENABLE_CLAMAV','MAIL_ENABLE_SPAMASSASSIN','MAIL_ENABLE_RSPAMD',
        'REDIS_HOST','REDIS_PORT','REDIS_PASSWORD','REDIS_DATABASE','MEILI_HOST','MEILI_MASTER_KEY','MEILI_SEARCH_KEY',
        'JWT_ALGORITHM','JWT_PRIVATE_KEY_PATH','JWT_PUBLIC_KEY_PATH','IMAP_ENCRYPTION_KEY','AI_ENCRYPTION_KEY',
        'OAUTH_KEYS','OAUTH_CURRENT_VERSION','SSO_SERVER_KEY','COLLAB_ADDR','MAILSYNC_ADDR','COLLAB_WS_URL',
        'STUN_URL','TURN_URL','TURN_SECRET','TURN_TTL','LIVEKIT_API_KEY','LIVEKIT_API_SECRET','LIVEKIT_WS_URL',
        'VAPID_PUBLIC_KEY','VAPID_PRIVATE_KEY','VAPID_SUBJECT','IMAP_HOST','IMAP_PORT','IMAP_TLS','IMAP_VERIFY_CERT',
        'FCM_ENABLED','APNS_VOIP_ENABLED','PANEL_API_URL','PANEL_API_KEY','REGISTRY','TAG','MYSQL_ROOT_PASSWORD'];
    $missing = array_values(array_diff($expected, array_keys($env)));
    assertTrue($missing === [], 'missing keys: ' . implode(', ', $missing));
    return true;
});
test('render', 'no placeholder/example values leak through', function () use ($renderer) {
    $body = $renderer->render(sampleVars());
    foreach (['change-me', 'example.com', 'never-rotate'] as $bad) {
        assertTrue(stripos($body, $bad) === false, "placeholder leaked: {$bad}");
    }
    return true;
});

// --- hosts (INJECT bucket: localhost -> service name) ---
section('hosts');
test('hosts', 'DB/Redis/Meili/Mail hosts are compose service names', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars()));
    assertEq('mariadb', $env['DB_HOST'], 'DB_HOST');
    assertEq('mariadb', $env['MAIL_DB_HOST'], 'MAIL_DB_HOST');
    assertEq('redis', $env['REDIS_HOST'], 'REDIS_HOST');
    assertEq('http://meilisearch:7700', $env['MEILI_HOST'], 'MEILI_HOST');
    assertEq('collab:1234', $env['COLLAB_ADDR'], 'COLLAB_ADDR');
    assertEq('mailsync:1235', $env['MAILSYNC_ADDR'], 'MAILSYNC_ADDR');
    assertTrue(strpos($env['DB_HOST'], '127.0.0.1') === false && $env['DB_HOST'] !== 'localhost', 'DB_HOST must not be localhost');
    return true;
});

// --- urls ---
section('urls');
test('urls', 'URLs derived from EMAIL_DOMAIN', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars()));
    assertEq('https://email.acme.com', $env['FRONTEND_URL'], 'FRONTEND_URL');
    assertEq('https://email.acme.com/api', $env['API_URL'], 'API_URL');
    assertEq('wss://email.acme.com/collab-ws', $env['COLLAB_WS_URL'], 'COLLAB_WS_URL');
    assertEq('https://panel.acme.com/api', $env['PANEL_API_URL'], 'PANEL_API_URL');
    assertEq('mailto:admin@acme.com', $env['VAPID_SUBJECT'], 'VAPID_SUBJECT');
    assertEq('acme.com', $env['IMAP_HOST'], 'IMAP_HOST should be the mail host');
    return true;
});

// --- secrets mapping ---
section('secrets');
test('secrets', 'secrets mapped to correct .env keys', function () use ($renderer) {
    $v = sampleVars();
    $env = parseEnv($renderer->render($v));
    assertEq($v['EMAIL_DB_PASS'], $env['DB_PASS'], 'DB_PASS <- EMAIL_DB_PASS');
    assertEq($v['DB_ROOT_PASS'], $env['MYSQL_ROOT_PASSWORD'], 'MYSQL_ROOT_PASSWORD <- DB_ROOT_PASS');
    assertEq($v['IMAP_ENCRYPTION_KEY'], $env['IMAP_ENCRYPTION_KEY'], 'IMAP_ENCRYPTION_KEY passthrough');
    assertEq($v['EMAIL_API_KEY'], $env['PANEL_API_KEY'], 'PANEL_API_KEY <- EMAIL_API_KEY');
    assertEq($v['REDIS_PASS'], $env['REDIS_PASSWORD'], 'REDIS_PASSWORD <- REDIS_PASS');
    assertEq($v['AI_ENCRYPTION_KEY'], $env['AI_ENCRYPTION_KEY'], 'AI_ENCRYPTION_KEY');
    return true;
});
test('secrets', 'AI key falls back to ENCRYPTION_KEY when AI_ENCRYPTION_KEY absent', function () use ($renderer) {
    $v = sampleVars(); unset($v['AI_ENCRYPTION_KEY']);
    $env = parseEnv($renderer->render($v));
    assertEq($v['ENCRYPTION_KEY'], $env['AI_ENCRYPTION_KEY'], 'AI_ENCRYPTION_KEY <- ENCRYPTION_KEY fallback');
    return true;
});

// --- ssl toggle ---
section('ssl');
test('ssl', 'enable_ssl=false yields http/ws and lenient cert verify', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars(), ['enable_ssl' => false]));
    assertEq('0', $env['ENABLE_SSL'], 'ENABLE_SSL');
    assertEq('http://email.acme.com', $env['FRONTEND_URL'], 'FRONTEND_URL scheme');
    assertEq('ws://email.acme.com/collab-ws', $env['COLLAB_WS_URL'], 'COLLAB_WS_URL scheme');
    assertEq('false', $env['IMAP_VERIFY_CERT'], 'IMAP_VERIFY_CERT');
    return true;
});
test('ssl', 'enable_ssl defaults to on', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars()));
    assertEq('1', $env['ENABLE_SSL'], 'ENABLE_SSL default');
    assertEq('true', $env['IMAP_VERIFY_CERT'], 'IMAP_VERIFY_CERT default');
    return true;
});
test('ssl', 'registry/tag overridable', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars(), ['registry' => 'reg.acme.com/flowone', 'tag' => 'v2']));
    assertEq('reg.acme.com/flowone', $env['REGISTRY'], 'REGISTRY');
    assertEq('v2', $env['TAG'], 'TAG');
    return true;
});

// --- mail pod identity + SSL cert file paths ---
section('mail');
test('mail', 'mail-pod identity keys emitted from vars', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars(['SERVER_IP' => '203.0.113.9'])));
    assertEq('acme.com', $env['MAIL_DOMAIN'], 'MAIL_DOMAIN');
    assertEq('acme.com', $env['SERVER_FQDN'], 'SERVER_FQDN falls back to mail domain');
    assertEq('203.0.113.9', $env['SERVER_IP'], 'SERVER_IP');
    assertEq('admin@acme.com', $env['ADMIN_EMAIL'], 'ADMIN_EMAIL');
    assertEq('1', $env['MAIL_ENABLE_CLAMAV'], 'clamav default on');
    assertEq('1', $env['MAIL_ENABLE_RSPAMD'], 'rspamd default on');
    return true;
});
test('mail', 'heavy mail services can be toggled off via vars', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars(['MAIL_ENABLE_CLAMAV' => '0', 'MAIL_ENABLE_SPAMASSASSIN' => '0'])));
    assertEq('0', $env['MAIL_ENABLE_CLAMAV'], 'clamav off');
    assertEq('0', $env['MAIL_ENABLE_SPAMASSASSIN'], 'spamassassin off');
    assertEq('1', $env['MAIL_ENABLE_RSPAMD'], 'rspamd still on');
    return true;
});
test('mail', 'SERVER_FQDN drives IMAP_HOST + one shared cert lineage', function () use ($renderer) {
    $env = parseEnv($renderer->render(sampleVars(['SERVER_FQDN' => 'vps.acme.com'])));
    assertEq('vps.acme.com', $env['SERVER_FQDN'], 'SERVER_FQDN passthrough');
    assertEq('vps.acme.com', $env['IMAP_HOST'], 'IMAP_HOST = cert-covered FQDN');
    assertEq('/etc/letsencrypt/live/vps.acme.com/fullchain.pem', $env['SSL_CERT_FILE'], 'cert file lineage');
    assertEq('/etc/letsencrypt/live/vps.acme.com/privkey.pem', $env['SSL_KEY_FILE'], 'key file lineage');
    return true;
});
test('mail', 'cert file lineage falls back to EMAIL_DOMAIN when no mail FQDN', function () use ($renderer) {
    $v = sampleVars(); unset($v['MAIL_DOMAIN'], $v['SERVER_FQDN']);
    $env = parseEnv($renderer->render($v));
    assertEq('/etc/letsencrypt/live/email.acme.com/fullchain.pem', $env['SSL_CERT_FILE'], 'cert lineage <- EMAIL_DOMAIN');
    return true;
});

// --- guards (fail loudly) ---
section('guards');
test('guards', 'missing IMAP_ENCRYPTION_KEY aborts render', function () use ($renderer) {
    $v = sampleVars(); unset($v['IMAP_ENCRYPTION_KEY']);
    $threw = false;
    try { $renderer->render($v); } catch (\RuntimeException $e) { $threw = strpos($e->getMessage(), 'IMAP_ENCRYPTION_KEY') !== false; }
    assertTrue($threw, 'expected render to throw on missing IMAP_ENCRYPTION_KEY');
    return true;
});
test('guards', 'missing DB root password aborts render', function () use ($renderer) {
    $v = sampleVars(); unset($v['DB_ROOT_PASS']);
    $threw = false;
    try { $renderer->render($v); } catch (\RuntimeException $e) { $threw = true; }
    assertTrue($threw, 'expected render to throw on missing DB_ROOT_PASS');
    return true;
});
test('guards', 'LiveKit key with empty ws_url aborts (landmine guard)', function () use ($renderer) {
    $v = sampleVars(['LIVEKIT_WS_URL' => '']);
    $threw = false;
    try { $renderer->render($v); } catch (\RuntimeException $e) { $threw = stripos($e->getMessage(), 'LIVEKIT_WS_URL') !== false; }
    assertTrue($threw, 'expected render to throw on LiveKit key + empty ws_url');
    return true;
});
test('guards', 'no LiveKit key + empty ws_url is fine', function () use ($renderer) {
    $v = sampleVars(['LIVEKIT_API_KEY' => '', 'LIVEKIT_API_SECRET' => '', 'LIVEKIT_WS_URL' => '']);
    $p = $renderer->validate($v);
    assertTrue($p === [], 'unexpected problems: ' . implode('; ', $p));
    return true;
});
test('guards', 'newline in a value is rejected', function () use ($renderer) {
    $v = sampleVars(['SSO_SERVER_KEY' => "line1\nline2"]);
    $p = $renderer->validate($v);
    assertTrue(count($p) > 0 && stripos(implode(' ', $p), 'newline') !== false, 'expected a newline problem');
    return true;
});

// --- format ---
section('format');
test('format', 'body has header comment and trailing newline', function () use ($renderer) {
    $body = $renderer->render(sampleVars());
    assertTrue(strpos($body, '# FlowOne per-server stack') === 0, 'missing header comment');
    assertTrue(substr($body, -1) === "\n", 'missing trailing newline');
    return true;
});

// ------------------------------------------------------------------ summary
$total = $results['passed'] + $results['failed'] + $results['warned'];
if ($opts['json']) {
    echo json_encode(['total' => $total, 'passed' => $results['passed'], 'failed' => $results['failed'],
        'warned' => $results['warned'], 'tests' => $results['tests']], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\nSummary: total={$total} passed={$results['passed']} failed={$results['failed']} warned={$results['warned']}\n";
    echo "Log: {$logFile}\n";
}
logLine("Summary: total={$total} passed={$results['passed']} failed={$results['failed']} warned={$results['warned']}");
exit($results['failed'] > 0 ? 1 : 0);
