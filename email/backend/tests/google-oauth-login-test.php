#!/usr/bin/env php
<?php
/**
 * FlowOne Google OAuth Login - Merged Flow Test Suite
 *
 * Validates the merged single-consent login flow:
 *   - login_scopes contains mail.google.com (so IMAP works after Sign in with Google)
 *   - login_scopes does NOT contain unused contacts.readonly
 *   - authorization URL includes access_type=offline + prompt=consent
 *   - storeTokensForLogin persists the real refresh_token (was hardcoded '' before)
 *   - re-consent without a new refresh_token PRESERVES the existing stored one
 *   - re-consent with a new refresh_token OVERWRITES the stored one
 *   - encrypt/decrypt round-trips cleanly via OAuthCryptor
 *   - XOAUTH2 string is the correct base64-encoded IMAP auth blob
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/google-oauth-login-test.php --verbose
 *
 * Options:
 *   --only=GROUPS        Comma-separated: config,url,store,preserve,replace,crypto,xoauth2
 *   --smoke              Minimal smoke check (config + url + crypto only, no DB writes)
 *   --verbose            Show extra debug info (stack traces, scope strings, encrypted blobs)
 *   --json               Output results as JSON
 *   --help               Show this help
 *
 * Safety:
 *   All test rows use email prefix "flowone_test_oauth_" and are cleaned up at the
 *   end (or via shutdown / SIGINT / SIGTERM handler if interrupted). Idempotent.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Services\GoogleOAuthService;
use Webmail\Services\OAuthCryptor;
use Webmail\Core\Database;

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['only::', 'smoke', 'verbose', 'json', 'help']);
if (isset($opts['help'])) {
    echo <<<HELP
FlowOne Google OAuth Login - Merged Flow Test Suite
====================================================

Usage:
  php google-oauth-login-test.php [options]

Options:
  --only=GROUPS    Comma-separated: config,url,store,preserve,replace,crypto,xoauth2
  --smoke          Minimal smoke check (no DB writes)
  --verbose        Show extra debug info
  --json           Output results as JSON
  --help           Show this help

Example:
  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/google-oauth-login-test.php --verbose

Test data is namespaced under "flowone_test_oauth_*" and cleaned up automatically.

HELP;
    exit(0);
}

$verbose    = isset($opts['verbose']);
$smokeOnly  = isset($opts['smoke']);
$jsonOutput = isset($opts['json']);
$onlyGroups = isset($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : [];

// ── Logging ──────────────────────────────────────────────────────

$logDir  = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/google-oauth-login-test-' . date('Ymd-His') . '.log';

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function out(string $msg, bool $toStdout = true): void {
    global $logFile, $jsonOutput;
    $line = $msg . "\n";
    if ($toStdout && !$jsonOutput) echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . preg_replace('/\033\[[0-9;]*m/', '', $line), FILE_APPEND | LOCK_EX);
}

function should_run(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return in_array($group, ['config', 'url', 'crypto'], true);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

function section(string $title): void {
    out("");
    out("\033[1;36m--- {$title} ---\033[0m");
}

function test(string $name, callable $fn, int $timeoutSec = 30): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    $prevLimit = (int) ini_get('max_execution_time');
    @set_time_limit($timeoutSec);
    try {
        $result = $fn();
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  \033[32m[PASS]\033[0m {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m {$name} ({$elapsed}ms)");
        out("         -> " . $e->getMessage());
        if ($verbose) {
            out("         -> " . $e->getFile() . ':' . $e->getLine());
            out("         -> " . str_replace("\n", "\n         -> ", $e->getTraceAsString()));
        }
        $results[] = [
            'name'  => $name,
            'status'=> 'FAIL',
            'ms'    => $elapsed,
            'error' => $e->getMessage(),
        ];
    } finally {
        @set_time_limit($prevLimit ?: 0);
    }
}

// ── Cleanup (always runs) ────────────────────────────────────────

$testEmailPrefix = 'flowone_test_oauth_';

function cleanup(array $config, string $prefix): void {
    try {
        $db = Database::getConnection($config);
        $stmt = $db->prepare('DELETE FROM webmail_oauth_tokens WHERE primary_email LIKE ? OR oauth_email LIKE ?');
        $stmt->execute([$prefix . '%', $prefix . '%']);
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            out("\033[2m  Cleanup: removed {$deleted} test row(s) matching {$prefix}*\033[0m");
        }
    } catch (\Throwable $e) {
        out("\033[31m  Cleanup error: " . $e->getMessage() . "\033[0m");
    }
}

register_shutdown_function(function () use ($config, $testEmailPrefix) {
    cleanup($config, $testEmailPrefix);
});

if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { out("\n  Interrupted - cleaning up..."); exit(130); });
    pcntl_signal(SIGTERM, function () { out("\n  Terminated - cleaning up..."); exit(143); });
}

// ── Pre-flight ───────────────────────────────────────────────────

out("\033[1;35m===========================================\033[0m");
out("\033[1;35m FlowOne Google OAuth Login Test Suite     \033[0m");
out("\033[1;35m===========================================\033[0m");
out("Log file: {$logFile}");
if ($smokeOnly) out("Mode: \033[1;33mSMOKE\033[0m (config + url + crypto only)");
if (!empty($onlyGroups)) out("Groups: " . implode(',', $onlyGroups));

section('0. PRE-FLIGHT');

test('PHP extension: openssl loaded', function () {
    if (!extension_loaded('openssl')) throw new \RuntimeException('openssl extension missing');
    return true;
});

test('PHP extension: pdo_mysql loaded', function () {
    if (!extension_loaded('pdo_mysql')) throw new \RuntimeException('pdo_mysql extension missing');
    return true;
});

test('Config: google_oauth section present', function () use ($config) {
    if (empty($config['google_oauth'])) throw new \RuntimeException('config.google_oauth missing');
    if (!is_array($config['google_oauth']['login_scopes'] ?? null)) {
        throw new \RuntimeException('config.google_oauth.login_scopes missing or not an array');
    }
    return true;
});

test('Database: connection reachable', function () use ($config) {
    $db = Database::getConnection($config);
    $stmt = $db->query('SELECT 1');
    if (!$stmt || (int) $stmt->fetchColumn() !== 1) throw new \RuntimeException('SELECT 1 failed');
    return true;
});

test('GoogleOAuthService: constructs without error', function () use ($config) {
    new GoogleOAuthService($config);
    return true;
});

test('Storage: logs dir writable', function () use ($logDir) {
    if (!is_writable($logDir)) throw new \RuntimeException("logs dir not writable: {$logDir}");
    return true;
});

// ── 1. Config integrity ─────────────────────────────────────────

if (should_run('config')) {
    section('1. CONFIG INTEGRITY');

    test('login_scopes includes openid/email/profile', function () use ($config) {
        $s = $config['google_oauth']['login_scopes'];
        foreach (['openid', 'email', 'profile'] as $required) {
            if (!in_array($required, $s, true)) throw new \RuntimeException("missing scope: {$required}");
        }
        return true;
    });

    test('login_scopes includes mail.google.com (merged flow)', function () use ($config) {
        $s = $config['google_oauth']['login_scopes'];
        if (!in_array('https://mail.google.com/', $s, true)) {
            throw new \RuntimeException('login_scopes is missing mail.google.com - silent re-consent popup will return');
        }
        return true;
    });

    test('scopes does NOT include unused contacts.readonly', function () use ($config) {
        $s = $config['google_oauth']['scopes'];
        if (in_array('https://www.googleapis.com/auth/contacts.readonly', $s, true)) {
            throw new \RuntimeException('contacts.readonly is still requested - reduces consent screen clarity');
        }
        return true;
    });

    test('calendar scopes remain commented out (no silent broadening)', function () use ($config) {
        $s = $config['google_oauth']['scopes'];
        foreach (['https://www.googleapis.com/auth/calendar', 'https://www.googleapis.com/auth/calendar.events'] as $cal) {
            if (in_array($cal, $s, true)) {
                throw new \RuntimeException("calendar scope leaked into 'scopes': {$cal}");
            }
        }
        return true;
    });
}

// ── 2. Authorization URL ────────────────────────────────────────

if (should_run('url')) {
    section('2. AUTHORIZATION URL BUILDER');

    test('URL contains mail.google.com when loginOnly=true', function () use ($config) {
        $svc = new GoogleOAuthService($config);
        $url = $svc->getAuthorizationUrl('test-state', null, true);
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        $scope = $q['scope'] ?? '';
        if (strpos($scope, 'https://mail.google.com/') === false) {
            throw new \RuntimeException("scope missing mail.google.com: {$scope}");
        }
        return true;
    });

    test('URL contains access_type=offline (needed for refresh_token)', function () use ($config) {
        $svc = new GoogleOAuthService($config);
        $url = $svc->getAuthorizationUrl('test-state', null, true);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        if (($q['access_type'] ?? '') !== 'offline') {
            throw new \RuntimeException("access_type is not 'offline': " . ($q['access_type'] ?? 'missing'));
        }
        return true;
    });

    test('URL contains prompt=consent (forces refresh_token issuance)', function () use ($config) {
        $svc = new GoogleOAuthService($config);
        $url = $svc->getAuthorizationUrl('test-state', null, true);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        if (($q['prompt'] ?? '') !== 'consent') {
            throw new \RuntimeException("prompt is not 'consent': " . ($q['prompt'] ?? 'missing'));
        }
        return true;
    });

    test('URL does NOT contain contacts.readonly', function () use ($config) {
        $svc = new GoogleOAuthService($config);
        $url = $svc->getAuthorizationUrl('test-state', null, false);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        if (strpos($q['scope'] ?? '', 'contacts.readonly') !== false) {
            throw new \RuntimeException('scope still includes contacts.readonly');
        }
        return true;
    });

    test('URL is well-formed (accounts.google.com host)', function () use ($config) {
        $svc = new GoogleOAuthService($config);
        $url = $svc->getAuthorizationUrl('test-state', null, true);
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== 'accounts.google.com') {
            throw new \RuntimeException("unexpected host: {$host}");
        }
        return true;
    });
}

// ── Helper: simulate a token response from Google ──────────────

function fakeTokenResponse(bool $withRefresh = true): array {
    $base = [
        'access_token' => 'ya29.fake_access_' . bin2hex(random_bytes(8)),
        'expires_in'   => 3599,
        'token_type'   => 'Bearer',
        'scope'        => 'openid email profile https://mail.google.com/',
    ];
    if ($withRefresh) {
        $base['refresh_token'] = '1//fake_refresh_' . bin2hex(random_bytes(16));
    }
    return $base;
}

function fakeUserInfo(string $email, string $name = 'FlowOne Test User'): array {
    return ['email' => $email, 'name' => $name];
}

function fetchTokenRow(array $config, string $primaryEmail, string $oauthEmail): ?array {
    $db = Database::getConnection($config);
    $stmt = $db->prepare('
        SELECT access_token_encrypted, refresh_token_encrypted, token_expires_at, health, health_reason
        FROM webmail_oauth_tokens
        WHERE primary_email = ? AND oauth_email = ? AND provider = "google"
    ');
    $stmt->execute([strtolower($primaryEmail), strtolower($oauthEmail)]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ── 3. storeTokensForLogin (INSERT with refresh_token) ─────────

if (should_run('store')) {
    section('3. storeTokensForLogin - INSERT path');

    $email = $testEmailPrefix . 'insert@flowone.pro';

    test('INSERT: refresh_token is persisted (was hardcoded empty before fix)', function () use ($config, $email) {
        $svc = new GoogleOAuthService($config);
        $tokens = fakeTokenResponse(true);
        $ok = $svc->storeTokensForLogin($email, $tokens, fakeUserInfo($email));
        if (!$ok) throw new \RuntimeException('storeTokensForLogin returned false');

        $row = fetchTokenRow($config, $email, $email);
        if (!$row) throw new \RuntimeException('row not found after insert');
        if (empty($row['refresh_token_encrypted'])) {
            throw new \RuntimeException('refresh_token_encrypted is EMPTY - the bug is back');
        }
        if (!str_starts_with($row['refresh_token_encrypted'], 'v') && !str_starts_with($row['refresh_token_encrypted'], 'e')) {
            // OAuthCryptor envelopes typically start with version prefix; just sanity-check it isn't plain text
            if ($row['refresh_token_encrypted'] === $tokens['refresh_token']) {
                throw new \RuntimeException('refresh token stored unencrypted');
            }
        }
        return true;
    });

    test('INSERT: access_token_encrypted is non-empty and not plain text', function () use ($config, $email) {
        $row = fetchTokenRow($config, $email, $email);
        if (!$row || empty($row['access_token_encrypted'])) throw new \RuntimeException('access token not stored');
        if (strpos($row['access_token_encrypted'], 'ya29.') === 0) {
            throw new \RuntimeException('access token stored unencrypted (starts with ya29.)');
        }
        return true;
    });

    test('INSERT: token_expires_at is ~1 hour in the future', function () use ($config, $email) {
        $row = fetchTokenRow($config, $email, $email);
        $expires = strtotime($row['token_expires_at']);
        $delta = $expires - time();
        if ($delta < 3500 || $delta > 3700) {
            throw new \RuntimeException("expires_at delta unexpected: {$delta}s");
        }
        return true;
    });

    test('INSERT: getValidAccessToken returns the same access token (no refresh needed)', function () use ($config, $email) {
        $svc = new GoogleOAuthService($config);
        $token = $svc->getValidAccessToken($email, $email);
        if (!$token) throw new \RuntimeException('getValidAccessToken returned null: ' . ($svc->getLastFailureReason() ?? 'unknown'));
        if (strpos($token, 'ya29.fake_access_') !== 0) {
            throw new \RuntimeException("unexpected token value: " . substr($token, 0, 40));
        }
        return true;
    });
}

// ── 4. Re-consent preserves refresh_token when Google omits it ─

if (should_run('preserve')) {
    section('4. RE-CONSENT - preserve existing refresh_token');

    $email = $testEmailPrefix . 'preserve@flowone.pro';

    test('SETUP: initial login stores refresh_token', function () use ($config, $email) {
        $svc = new GoogleOAuthService($config);
        $ok = $svc->storeTokensForLogin($email, fakeTokenResponse(true), fakeUserInfo($email));
        if (!$ok) throw new \RuntimeException('initial store failed');
        $row = fetchTokenRow($config, $email, $email);
        if (empty($row['refresh_token_encrypted'])) throw new \RuntimeException('setup: refresh token not stored');
        return true;
    });

    test('PRESERVE: re-consent without refresh_token keeps the stored one', function () use ($config, $email) {
        $before = fetchTokenRow($config, $email, $email)['refresh_token_encrypted'];

        $svc = new GoogleOAuthService($config);
        $ok = $svc->storeTokensForLogin($email, fakeTokenResponse(false), fakeUserInfo($email));
        if (!$ok) throw new \RuntimeException('re-consent store failed');

        $after = fetchTokenRow($config, $email, $email)['refresh_token_encrypted'];
        if (empty($after)) throw new \RuntimeException('refresh token was cleared - COALESCE is broken');
        if ($before !== $after) {
            throw new \RuntimeException('refresh token was overwritten when Google omitted it');
        }
        return true;
    });
}

// ── 5. Re-consent replaces refresh_token when Google issues a new one

if (should_run('replace')) {
    section('5. RE-CONSENT - replace refresh_token on new issue');

    $email = $testEmailPrefix . 'replace@flowone.pro';

    test('SETUP: initial login stores refresh_token A', function () use ($config, $email) {
        $svc = new GoogleOAuthService($config);
        $ok = $svc->storeTokensForLogin($email, fakeTokenResponse(true), fakeUserInfo($email));
        if (!$ok) throw new \RuntimeException('initial store failed');
        return true;
    });

    test('REPLACE: re-consent with new refresh_token overwrites the stored one', function () use ($config, $email) {
        $before = fetchTokenRow($config, $email, $email)['refresh_token_encrypted'];

        $svc = new GoogleOAuthService($config);
        $newTokens = fakeTokenResponse(true);
        $ok = $svc->storeTokensForLogin($email, $newTokens, fakeUserInfo($email));
        if (!$ok) throw new \RuntimeException('re-consent store failed');

        $after = fetchTokenRow($config, $email, $email)['refresh_token_encrypted'];
        if ($after === $before) {
            throw new \RuntimeException('refresh token was NOT updated despite Google issuing a new one');
        }
        if (empty($after)) {
            throw new \RuntimeException('refresh token is empty after replace');
        }
        return true;
    });
}

// ── 6. OAuthCryptor encrypt/decrypt round-trip ─────────────────

if (should_run('crypto')) {
    section('6. OAUTH CRYPTOR ROUND-TRIP');

    test('OAuthCryptor: encrypt then decrypt yields original plaintext', function () use ($config) {
        $cryptor = new OAuthCryptor($config);
        $plain = '1//fake_refresh_' . bin2hex(random_bytes(16));
        $enc = $cryptor->encrypt($plain);
        $dec = $cryptor->decrypt($enc);
        if ($dec !== $plain) {
            throw new \RuntimeException("decrypt mismatch: expected={$plain} got=" . ($dec ?? 'null'));
        }
        return true;
    });

    test('OAuthCryptor: encrypt produces non-empty output different from plaintext', function () use ($config) {
        $cryptor = new OAuthCryptor($config);
        $plain = 'some_access_token_value_12345';
        $enc = $cryptor->encrypt($plain);
        if (empty($enc) || $enc === $plain) {
            throw new \RuntimeException('encryption did not transform input');
        }
        return true;
    });
}

// ── 7. XOAUTH2 string format ────────────────────────────────────

if (should_run('xoauth2')) {
    section('7. XOAUTH2 IMAP STRING');

    test('getXOAuth2String produces RFC 7628-compliant base64 blob', function () use ($config) {
        $svc = new GoogleOAuthService($config);
        $email = 'user@example.com';
        $token = 'ya29.access_token';
        $b64 = $svc->getXOAuth2String($email, $token);
        $decoded = base64_decode($b64, true);
        if ($decoded === false) throw new \RuntimeException('not valid base64');
        $expected = "user={$email}\x01auth=Bearer {$token}\x01\x01";
        if ($decoded !== $expected) {
            throw new \RuntimeException('XOAUTH2 format mismatch');
        }
        return true;
    });
}

// ── Summary ─────────────────────────────────────────────────────

section('SUMMARY');
out("Total:    {$totalTests}");
out("\033[32mPassed:   {$passed}\033[0m");
if ($warnings > 0) out("\033[33mWarnings: {$warnings}\033[0m");
if ($failed > 0)   out("\033[31mFailed:   {$failed}\033[0m");

if ($failed > 0) {
    out("");
    out("\033[1;31mFailures:\033[0m");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("  - {$r['name']}");
            if (!empty($r['error'])) out("      {$r['error']}");
        }
    }
}

if ($jsonOutput) {
    echo json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'results'  => $results,
        'log_file' => $logFile,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
