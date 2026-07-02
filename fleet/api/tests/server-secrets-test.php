#!/usr/bin/env php
<?php
/**
 * ServerSecretGenerator Test — Phase D (native->docker Fleet refactor).
 *
 * Validates the non-regenerable crypto minting for a Docker-deployed server:
 *   - hex keys (IMAP/AI/SSO) shape + uniqueness
 *   - RS256 JWT key pair validity (real sign/verify round-trip)
 *   - ensureDockerSecrets() fills only what's missing and reports it
 *   - EncryptionService encrypt->decrypt is lossless for a PEM (persistence path)
 *   - generated secrets feed ComposeEnvRenderer with zero validation problems
 *     (i.e. a FRESH box now renders a valid .env)
 *
 * Uses only openssl + an in-memory Container (encryption.key), so it runs with a
 * plain PHP CLI -- no Fleet DB, no bootstrap. Read-only; nothing to clean up.
 *
 *   php fleet/api/tests/server-secrets-test.php --verbose
 * Flags: --help --verbose --json --only=hex,jwt,ensure,crypto,integration
 *
 * NOTE: on Linux (the server/CI target) openssl_pkey_new() works out of the box.
 * On a Windows dev box PHP may not find openssl.cnf; set OPENSSL_CONF to the
 * bundled config first, e.g.:
 *   $env:OPENSSL_CONF = "<php>\extras\ssl\openssl.cnf"   # PowerShell
 */

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$src = __DIR__ . '/../src';
require_once $src . '/Core/Container.php';
require_once $src . '/Services/EncryptionService.php';
require_once $src . '/Services/ServerSecretGenerator.php';
require_once $src . '/Services/ComposeEnvRenderer.php';

use FleetManager\Api\Core\Container;
use FleetManager\Api\Services\EncryptionService;
use FleetManager\Api\Services\ServerSecretGenerator as Gen;
use FleetManager\Api\Services\ComposeEnvRenderer;

$opts = ['help' => false, 'verbose' => false, 'json' => false, 'only' => []];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') $opts['help'] = true;
    elseif ($arg === '--verbose') $opts['verbose'] = true;
    elseif ($arg === '--json') $opts['json'] = true;
    elseif (str_starts_with($arg, '--only=')) $opts['only'] = array_filter(explode(',', substr($arg, 7)));
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(1); }
}
if ($opts['help']) { echo "Usage: php server-secrets-test.php [--verbose] [--json] [--only=hex,jwt,ensure,crypto,integration]\n"; exit(0); }

const C_GREEN = "\033[32m"; const C_RED = "\033[31m"; const C_RESET = "\033[0m";
$logDir = __DIR__ . '/../storage/logs';
@mkdir($logDir, 0775, true);
if (!is_dir($logDir) || !is_writable($logDir)) $logDir = sys_get_temp_dir();
$logFile = $logDir . '/server-secrets-test-' . date('Ymd-His') . '.log';
$results = ['passed' => 0, 'failed' => 0];

function logLine(string $s): void { global $logFile; @file_put_contents($logFile, $s . "\n", FILE_APPEND); }
function section(string $n): void { global $opts; if (!$opts['json']) echo "\n--- {$n} ---\n"; }
function shouldRun(string $g): bool { global $opts; return empty($opts['only']) || in_array($g, $opts['only'], true); }
function test(string $group, string $name, callable $fn): void {
    global $opts, $results;
    if (!shouldRun($group)) return;
    $t0 = microtime(true);
    try {
        $fn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $results['passed']++;
        if (!$opts['json']) echo "  " . C_GREEN . "[PASS]" . C_RESET . "  {$name} ({$ms}ms)\n";
        logLine("[PASS] {$name}");
    } catch (\Throwable $e) {
        $results['failed']++;
        if (!$opts['json']) echo "  " . C_RED . "[FAIL]" . C_RESET . "  {$name}\n         " . $e->getMessage() . "\n";
        logLine("[FAIL] {$name}: " . $e->getMessage());
    }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEq($exp, $act, string $m): void { if ($exp !== $act) throw new \RuntimeException($m . " (expected " . var_export($exp, true) . ", got " . var_export($act, true) . ")"); }

echo $opts['json'] ? '' : "=== ServerSecretGenerator test — " . date('Y-m-d H:i:s') . " ===\n";

// --- hex ---
section('hex');
test('hex', 'hexKey is 64 hex chars and unique', function () {
    $a = Gen::hexKey(); $b = Gen::hexKey();
    assertTrue(preg_match('/^[0-9a-f]{64}$/', $a) === 1, 'not 64 hex: ' . $a);
    assertTrue($a !== $b, 'two calls must differ');
});

// --- jwt ---
section('jwt');
test('jwt', 'jwtKeyPair produces valid PEMs', function () {
    $pair = Gen::jwtKeyPair();
    assertTrue(strpos($pair['private'], 'PRIVATE KEY') !== false, 'private PEM');
    assertTrue(strpos($pair['public'], 'PUBLIC KEY') !== false, 'public PEM');
    assertTrue(openssl_pkey_get_private($pair['private']) !== false, 'private loads');
    assertTrue(openssl_pkey_get_public($pair['public']) !== false, 'public loads');
});
test('jwt', 'RS256 sign with private verifies with public', function () {
    $pair = Gen::jwtKeyPair();
    $data = 'flowone.jwt.payload';
    $sig = '';
    assertTrue(openssl_sign($data, $sig, $pair['private'], OPENSSL_ALGO_SHA256) === true, 'sign failed');
    assertEq(1, openssl_verify($data, $sig, $pair['public'], OPENSSL_ALGO_SHA256), 'verify should pass');
    // A tampered payload must NOT verify.
    assertEq(0, openssl_verify('tampered', $sig, $pair['public'], OPENSSL_ALGO_SHA256), 'tamper must fail');
});

// --- ensure ---
section('ensure');
test('ensure', 'empty vars generate all four secrets', function () {
    $r = Gen::ensureDockerSecrets([]);
    foreach (['IMAP_ENCRYPTION_KEY', 'AI_ENCRYPTION_KEY', 'SSO_SERVER_KEY', 'JWT_PRIVATE_KEY_PEM', 'JWT_PUBLIC_KEY_PEM'] as $k) {
        assertTrue(!empty($r['vars'][$k]), "missing {$k}");
    }
    foreach (['IMAP_ENCRYPTION_KEY', 'AI_ENCRYPTION_KEY', 'SSO_SERVER_KEY', 'JWT_KEY_PAIR'] as $g) {
        assertTrue(in_array($g, $r['generated'], true), "should report {$g} generated");
    }
});
test('ensure', 'existing secrets are preserved, not regenerated', function () {
    $existing = [
        'IMAP_ENCRYPTION_KEY' => str_repeat('a', 64),
        'AI_ENCRYPTION_KEY' => str_repeat('b', 64),
        'SSO_SERVER_KEY' => str_repeat('c', 64),
        'JWT_PRIVATE_KEY_PEM' => "-----BEGIN PRIVATE KEY-----\nX\n-----END PRIVATE KEY-----\n",
        'JWT_PUBLIC_KEY_PEM' => "-----BEGIN PUBLIC KEY-----\nY\n-----END PUBLIC KEY-----\n",
    ];
    $r = Gen::ensureDockerSecrets($existing);
    assertTrue($r['generated'] === [], 'nothing should be generated: ' . implode(',', $r['generated']));
    assertEq($existing['IMAP_ENCRYPTION_KEY'], $r['vars']['IMAP_ENCRYPTION_KEY'], 'IMAP preserved');
    assertEq($existing['JWT_PRIVATE_KEY_PEM'], $r['vars']['JWT_PRIVATE_KEY_PEM'], 'JWT preserved');
});
test('ensure', 'AI key reuses ENCRYPTION_KEY when present', function () {
    $r = Gen::ensureDockerSecrets(['ENCRYPTION_KEY' => str_repeat('e', 64)]);
    assertEq(str_repeat('e', 64), $r['vars']['AI_ENCRYPTION_KEY'], 'AI should reuse ENCRYPTION_KEY');
});

// --- crypto (persistence round-trip) ---
section('crypto');
test('crypto', 'EncryptionService encrypt->decrypt is lossless for a PEM', function () {
    $container = new Container(['encryption' => ['key' => base64_encode(random_bytes(32))]]);
    $enc = new EncryptionService($container);
    $pair = Gen::jwtKeyPair();
    $cipher = $enc->encrypt($pair['private']);
    assertTrue($cipher !== $pair['private'], 'ciphertext must differ from plaintext');
    assertEq($pair['private'], $enc->decrypt($cipher), 'decrypt must restore the exact PEM (newlines included)');
});

// --- stable-keys (source-level: EMAIL_API_KEY must persist per server) ---
section('stable-keys');
test('crypto', 'TemplateService reuses persisted EMAIL_API_KEY (no per-call random)', function () {
    $src = file_get_contents(__DIR__ . '/../src/Services/TemplateService.php');
    assertTrue(strpos($src, "email_api_key_encrypted") !== false,
        'TemplateService must read/persist email_api_key_encrypted');
    // The generate path must guard random generation behind the persisted column —
    // an unconditional bin2hex assignment regenerates the key on every call and
    // desyncs the email .env (PANEL_API_KEY) from the panel install (--email-api-key).
    assertTrue(
        preg_match("/email_api_key_encrypted.*EMAIL_API_KEY/s", $src) === 1,
        'EMAIL_API_KEY generation must check email_api_key_encrypted first'
    );
    assertTrue(strpos($src, "COALESCE(email_api_key_encrypted, ?)") !== false,
        'persistDockerSecrets must COALESCE-persist email_api_key_encrypted');
});

// --- integration ---
section('integration');
test('integration', 'generated secrets let a fresh box render a valid .env', function () {
    // Minimal server vars a fresh box would have BEFORE crypto is minted.
    $base = [
        'EMAIL_DOMAIN' => 'email.fresh.example',
        'PANEL_DOMAIN' => 'panel.fresh.example',
        'MAIL_DOMAIN' => 'fresh.example',
        'ADMIN_EMAIL' => 'admin@fresh.example',
        'EMAIL_DB_NAME' => 'devc_vps_dash',
        'EMAIL_DB_USER' => 'vpsadmin',
        'EMAIL_DB_PASS' => 'FreshDbPass123',
        'DB_ROOT_PASS' => 'FreshRootPass9',
        'MAIL_DB_NAME' => 'mailserver',
        'MAIL_DB_USER' => 'mailuser',
        'MAIL_DB_PASS' => 'FreshMailPass',
        'REDIS_PASS' => 'FreshRedisPass',
        'MEILI_MASTER_KEY' => str_repeat('m', 32),
        'EMAIL_API_KEY' => str_repeat('k', 64),
        // NOTE: no IMAP_ENCRYPTION_KEY yet -> renderer would fail without ensureDockerSecrets.
    ];
    // Without crypto, the renderer must refuse (proves the guard is real).
    $renderer = new ComposeEnvRenderer();
    $problemsBefore = $renderer->validate($base);
    assertTrue(count($problemsBefore) > 0, 'fresh box without crypto should have problems');

    // After minting, it must validate + render clean.
    $vars = Gen::ensureDockerSecrets($base)['vars'];
    $problemsAfter = $renderer->validate($vars);
    assertTrue($problemsAfter === [], 'post-mint problems: ' . implode('; ', $problemsAfter));
    $body = $renderer->render($vars);
    assertTrue(strpos($body, 'IMAP_ENCRYPTION_KEY=' . $vars['IMAP_ENCRYPTION_KEY']) !== false, 'IMAP key in .env');
});

$total = $results['passed'] + $results['failed'];
if ($opts['json']) {
    echo json_encode(['total' => $total, 'passed' => $results['passed'], 'failed' => $results['failed']], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\nSummary: total={$total} passed={$results['passed']} failed={$results['failed']}\n";
    echo "Log: {$logFile}\n";
}
exit($results['failed'] > 0 ? 1 : 0);
