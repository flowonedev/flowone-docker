#!/usr/bin/env php
<?php
/**
 * FlowOne Device-Authorization ("scan to sign in") Test.
 *
 * Exercises the QR + approval login that backs the desktop apps:
 *
 *   device  -> POST /api/sso/device/start   (anonymous; returns request_id,
 *              poll_secret, 2-digit match number, verify_url)
 *   approver-> GET  /api/sso/device/info    (auth; returns 3 candidate numbers)
 *           -> POST /api/sso/device/approve (auth; tap the matching number)
 *   device  -> POST /api/sso/device/poll    (poll_secret; returns one-time code)
 *           -> POST /api/sso/exchange       (existing endpoint; returns tokens)
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   PHP CLI, curl extension, --base reachable
 *   public      start shape + match range; poll pending; wrong poll_secret ->
 *               401; unknown request -> 404; info/approve without auth -> 401
 *   roundtrip   real chain (needs --email/--password): login as approver ->
 *               start -> info (numbers include the match) -> approve wrong
 *               (mismatch) -> approve correct -> poll (code) -> exchange (tokens)
 *               -> replay poll (consumed). Sessions + seed revoked in cleanup.
 *   pending     auto-modal flow (needs creds): start targeted at the approver's
 *               email -> it surfaces via GET /sso/device/pending -> approve from
 *               there -> poll/exchange; a request targeted at another account is
 *               hidden from /pending and 404s on approve; 6th targeted start ->
 *               429 (rate limit). Test sessions revoked in cleanup.
 *   dismiss     cross-tab dismissal contract (needs creds): a request that is
 *               approved / denied / expired MUST drop out of /sso/device/pending
 *               so the approval modal auto-closes on every signed-in tab, not just
 *               the one that acted. (expired sub-test is DB-backed; warns w/o DB)
 *   attempts    wrong number DEVICE_MAX_ATTEMPTS times -> auto-denied
 *   expiry      DB-backed: force a request past its expiry -> poll 'expired',
 *               approve -> 410 (warns if no DB connection available)
 *   block       deny + block the originating IP for this account: blocked IP's
 *               next targeted start -> 403 DEVICE_BLOCKED; blocking another
 *               account's request -> 404. Block rows removed in cleanup.
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/sso-device-login-test.php \
 *       --email=USER@example.com --password=SECRET --verbose
 *
 *   # connectivity + anonymous endpoints only:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/sso-device-login-test.php \
 *       --base=https://flowone.pro --smoke
 *
 * Flags:
 *   --base=URL            server base to test (default: derived from --email,
 *                         else https://flowone.pro)
 *   --email=ADDR          approver email (also derives --base if not given)
 *   --password=PASS       approver password (enables roundtrip + attempts groups)
 *   --timeout=SECONDS     per-request timeout (default 30)
 *   --verbose             extra debug output (raw bodies on failure)
 *   --json                emit results as JSON to stdout
 *   --smoke               preflight + public only (no business logic)
 *   --only=GROUP[,GROUP]  run only listed groups
 *   --skip-send           skip groups that mint real sessions (roundtrip, attempts)
 *   --help                show this message
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', [
    'base:', 'email:', 'password:', 'timeout:',
    'verbose', 'json', 'smoke', 'only:', 'skip-send', 'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, <<<TXT
FlowOne Device-Authorization ("scan to sign in") Test

Usage:
  php sso-device-login-test.php [--base=URL | --email=ADDR --password=PASS] [flags]

Groups: preflight, public, roundtrip, pending, dismiss, attempts, expiry, block
  preflight  PHP CLI, curl extension, base reachable
  public     anonymous endpoints + auth gating (no creds needed)
  roundtrip  full start->info->approve->poll->exchange chain (needs creds)
  pending    auto-modal: targeted start surfaces via /sso/device/pending,
             approve from there, foreign target hidden + 404, rate limit (needs creds)
  dismiss    approved/denied/expired requests drop out of /sso/device/pending so
             the modal auto-closes on all tabs (needs creds; expiry needs DB)
  attempts   wrong-number cap auto-denies the request (needs creds)
  expiry     DB-backed expiry behaviour (warns if no DB)
  block      deny + block IP: blocked IP start -> 403, foreign block -> 404 (needs creds)

Flags:
  --base=URL            server base (default: derived from --email or flowone.pro)
  --email=ADDR          approver email (also derives --base)
  --password=PASS       approver password (enables roundtrip + attempts)
  --timeout=SECONDS     per-request timeout (default 30)
  --verbose             extra debug output on failure
  --json                emit results as JSON
  --smoke               preflight + public only
  --only=GROUP[,GROUP]  run only listed groups
  --skip-send           skip groups that mint real sessions
  --help                show this message

Exit code: 0 on all PASS/WARN, 1 on any FAIL.

TXT);
    exit(0);
}

$jsonOut  = isset($opts['json']);
$verbose  = isset($opts['verbose']);
$smoke    = isset($opts['smoke']);
$skipSend = isset($opts['skip-send']);
$only = isset($opts['only'])
    ? array_map('trim', explode(',', (string) $opts['only']))
    : [];
$timeout = isset($opts['timeout']) ? max(1, (int) $opts['timeout']) : 30;

$email    = isset($opts['email']) ? trim((string) $opts['email']) : '';
$password = isset($opts['password']) ? (string) $opts['password'] : '';

function deriveBaseFromEmail(string $addr): string {
    $at = strrpos($addr, '@');
    if ($at === false) return '';
    $domain = strtolower(trim(substr($addr, $at + 1)));
    return $domain === '' ? '' : "https://email.{$domain}";
}

$base = isset($opts['base']) ? rtrim((string) $opts['base'], '/') : '';
if ($base === '' && $email !== '') {
    $base = deriveBaseFromEmail($email);
}
if ($base === '') {
    $base = 'https://flowone.pro';
}

$backendRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$logDir = $backendRoot . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/sso-device-login-' . date('Ymd-His') . '.log';

$totalTests = 0; $passed = 0; $failed = 0; $warnings = 0; $results = [];

// ---- color helpers (skip when json) ----
$useColor = (!$jsonOut) && function_exists('posix_isatty') && @posix_isatty(STDOUT);
$c_green  = $useColor ? "\033[0;32m" : '';
$c_red    = $useColor ? "\033[0;31m" : '';
$c_yellow = $useColor ? "\033[0;33m" : '';
$c_dim    = $useColor ? "\033[2m"    : '';
$c_reset  = $useColor ? "\033[0m"    : '';

function logLine(string $line, string $logFile): void {
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Minimal curl wrapper. Returns [status, headers(lower-cased), body]. */
function httpRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array {
    $ch = curl_init();
    $reqHeaders = [];
    foreach ($headers as $k => $v) { $reqHeaders[] = "{$k}: {$v}"; }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException("curl error: {$err}");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawHeaders = substr((string) $raw, 0, $headerSize);
    $respBody   = substr((string) $raw, $headerSize);
    $parsed = [];
    foreach (preg_split('/\r\n|\n/', $rawHeaders) as $line) {
        if (strpos($line, ':') === false) continue;
        [$name, $value] = explode(':', $line, 2);
        $parsed[strtolower(trim($name))] = trim($value);
    }
    return [$status, $parsed, $respBody];
}

/** POST JSON helper returning [status, decodedArrayOrNull, rawBody]. */
function postJson(string $base, string $path, array $payload, array $headers, int $timeout): array {
    $headers['Content-Type'] = 'application/json';
    $headers['Origin'] = $headers['Origin'] ?? 'https://localhost';
    [$status, , $body] = httpRequest('POST', $base . $path, $headers, json_encode($payload), $timeout);
    $decoded = json_decode($body, true);
    return [$status, is_array($decoded) ? $decoded : null, $body];
}

function authHeaders(array $rt): array {
    return [
        'Authorization' => 'Bearer ' . ($rt['approver_access'] ?? ''),
        'X-Session-Token' => $rt['approver_session'] ?? '',
        'Origin' => 'https://localhost',
    ];
}

/** True if request_id currently appears in the approver's /sso/device/pending list. */
function inPending(string $base, int $timeout, array $rt, string $requestId): bool {
    [$ps, , $body] = httpRequest('GET', $base . '/api/sso/device/pending', authHeaders($rt), null, $timeout);
    if ($ps !== 200) return false;
    $pd = json_decode($body, true);
    foreach (($pd['data']['requests'] ?? []) as $r) {
        if (($r['request_id'] ?? '') === $requestId) return true;
    }
    return false;
}

function runGroup(
    string $name, array $only, array $tests, string $logFile,
    bool $verbose, bool $jsonOut,
    string $c_green, string $c_red, string $c_yellow, string $c_dim, string $c_reset,
    int &$totalTests, int &$passed, int &$failed, int &$warnings, array &$results
): void {
    if (!empty($only) && !in_array($name, $only, true)) { return; }
    if (!$jsonOut) { echo "\n--- " . strtoupper($name) . " ---\n"; }
    logLine("=== " . strtoupper($name) . " ===", $logFile);

    foreach ($tests as $testName => $fn) {
        $totalTests++;
        $start = microtime(true);
        $outcome = ['status' => 'fail', 'message' => '', 'detail' => null];
        try {
            $rv = $fn();
            if (is_array($rv) && isset($rv['status'])) {
                $outcome = array_merge($outcome, $rv);
            } elseif ($rv === true) {
                $outcome['status'] = 'pass';
            } elseif (is_string($rv) && $rv !== '') {
                $outcome['status'] = 'fail';
                $outcome['message'] = $rv;
            } else {
                $outcome['status'] = 'fail';
                $outcome['message'] = 'Test returned no status';
            }
        } catch (\Throwable $e) {
            $outcome['status'] = 'fail';
            $outcome['message'] = $e->getMessage();
            $outcome['detail'] = $e->getFile() . ':' . $e->getLine();
        }
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $tag = '[PASS]'; $color = $c_green;
        if ($outcome['status'] === 'fail') { $tag = '[FAIL]'; $color = $c_red; $failed++; }
        elseif ($outcome['status'] === 'warn') { $tag = '[WARN]'; $color = $c_yellow; $warnings++; }
        else { $passed++; }

        $msg = $outcome['message'] !== '' ? ' -- ' . $outcome['message'] : '';
        if (!$jsonOut) {
            echo sprintf("  %s%s%s %s%s (%dms)\n",
                $color, $tag, $c_reset, $testName, $c_dim . $msg . $c_reset, $elapsedMs);
            if ($verbose && !empty($outcome['detail'])) {
                echo "      " . $c_dim . $outcome['detail'] . $c_reset . "\n";
            }
        }
        logLine(sprintf("[%s] %s %s (%dms)%s", date('H:i:s'), $tag, $testName, $elapsedMs, $msg), $logFile);
        $results[] = [
            'group' => $name, 'name' => $testName,
            'status' => $outcome['status'], 'message' => $outcome['message'],
            'elapsed_ms' => $elapsedMs,
        ];
    }
}

logLine("Target base: {$base}", $logFile);
if (!$jsonOut) { echo "Target: {$c_dim}{$base}{$c_reset}\n"; }

// Optional DB connection (for the expiry group + cleanup of test rows).
$pdo = null;
try {
    $bootstrapPath = __DIR__ . '/../cron/bootstrap.php';
    if (is_file($bootstrapPath)) {
        require_once $bootstrapPath;
        $cfg = require __DIR__ . '/../src/config.php';
        if (is_array($cfg)) {
            $pdo = \Webmail\Core\Database::getConnection($cfg);
        }
    }
} catch (\Throwable $e) {
    $pdo = null;
}

// ----------- PREFLIGHT -----------
$preflightTests = [
    'PHP CLI available' => function() {
        return ['status' => 'pass', 'message' => 'PHP ' . PHP_VERSION];
    },
    'curl extension loaded' => function() {
        if (!function_exists('curl_init')) {
            return ['status' => 'fail', 'message' => 'php-curl is required'];
        }
        return ['status' => 'pass'];
    },
    'base reachable' => function() use ($base, $timeout) {
        try {
            [$status] = httpRequest('GET', $base . '/api/auth/google/enabled', [], null, $timeout);
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $e->getMessage()];
        }
        if ($status === 0) {
            return ['status' => 'fail', 'message' => 'host unreachable'];
        }
        return ['status' => 'pass', 'message' => "reachable (HTTP {$status})"];
    },
];

runGroup('preflight', $only, $preflightTests, $logFile, $verbose, $jsonOut,
    $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
    $totalTests, $passed, $failed, $warnings, $results);

if ($failed > 0) {
    fwrite(STDERR, "Preflight failed -- aborting.\n");
    if ($jsonOut) {
        echo json_encode(['total' => $totalTests, 'passed' => $passed, 'failed' => $failed,
            'warnings' => $warnings, 'log_file' => $logFile, 'results' => $results],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }
    exit(1);
}

// ----------- PUBLIC (anonymous endpoints + auth gating) -----------
$pub = ['request_id' => null, 'poll_secret' => null, 'match' => null];
$publicTests = [
    'start returns request_id, poll_secret, match, verify_url' => function() use ($base, $timeout, $verbose, &$pub) {
        [$status, $d, $raw] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] device'], [], $timeout);
        if ($status !== 200 || !$d || empty($d['data'])) {
            return ['status' => 'fail', 'message' => "HTTP {$status}", 'detail' => $verbose ? substr($raw, 0, 300) : null];
        }
        $data = $d['data'];
        foreach (['request_id', 'poll_secret', 'match_number', 'verify_url', 'expires_in'] as $k) {
            if (!array_key_exists($k, $data)) {
                return ['status' => 'fail', 'message' => "missing field {$k}"];
            }
        }
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string) $data['request_id'])) {
            return ['status' => 'fail', 'message' => 'request_id not a uuid'];
        }
        $m = (int) $data['match_number'];
        if ($m < 0 || $m > 99) {
            return ['status' => 'fail', 'message' => "match_number out of range: {$m}"];
        }
        if (strpos((string) $data['verify_url'], '/link-device?req=') === false) {
            return ['status' => 'fail', 'message' => "verify_url unexpected: " . $data['verify_url']];
        }
        $pub['request_id'] = $data['request_id'];
        $pub['poll_secret'] = $data['poll_secret'];
        $pub['match'] = $m;
        return ['status' => 'pass', 'message' => "request created, match=" . sprintf('%02d', $m)];
    },
    'poll (correct secret) -> pending' => function() use ($base, $timeout, &$pub) {
        if (!$pub['request_id']) return ['status' => 'fail', 'message' => 'no request from start'];
        [$status, $d] = postJson($base, '/api/sso/device/poll',
            ['request_id' => $pub['request_id'], 'poll_secret' => $pub['poll_secret']], [], $timeout);
        if ($status !== 200 || ($d['data']['status'] ?? '') !== 'pending') {
            return ['status' => 'fail', 'message' => "HTTP {$status}, status=" . ($d['data']['status'] ?? '?')];
        }
        return ['status' => 'pass', 'message' => 'pending'];
    },
    'poll (wrong secret) -> 401' => function() use ($base, $timeout, &$pub) {
        if (!$pub['request_id']) return ['status' => 'fail', 'message' => 'no request from start'];
        [$status, $d] = postJson($base, '/api/sso/device/poll',
            ['request_id' => $pub['request_id'], 'poll_secret' => 'wrong-secret-value'], [], $timeout);
        if ($status !== 401 || ($d['error'] ?? '') !== 'DEVICE_POLL_INVALID') {
            return ['status' => 'fail', 'message' => "HTTP {$status}, error=" . ($d['error'] ?? '?')];
        }
        return ['status' => 'pass', 'message' => 'rejected'];
    },
    'poll (unknown request) -> 404' => function() use ($base, $timeout) {
        $fake = sprintf('%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand() & 0xffff, mt_rand() & 0xffff, mt_rand() & 0xffff, mt_rand());
        [$status] = postJson($base, '/api/sso/device/poll',
            ['request_id' => $fake, 'poll_secret' => 'x'], [], $timeout);
        if ($status !== 404) {
            return ['status' => 'fail', 'message' => "HTTP {$status} (want 404)"];
        }
        return ['status' => 'pass', 'message' => 'not found'];
    },
    'info without auth -> 401' => function() use ($base, $timeout, &$pub) {
        $req = $pub['request_id'] ?? '00000000-0000-0000-0000-000000000000';
        [$status] = httpRequest('GET', $base . '/api/sso/device/info?req=' . urlencode($req), [], null, $timeout);
        if ($status !== 401) {
            return ['status' => 'fail', 'message' => "HTTP {$status} (want 401)"];
        }
        return ['status' => 'pass', 'message' => 'auth required'];
    },
    'approve without auth -> 401' => function() use ($base, $timeout, &$pub) {
        $req = $pub['request_id'] ?? '00000000-0000-0000-0000-000000000000';
        [$status] = postJson($base, '/api/sso/device/approve',
            ['request_id' => $req, 'number' => 1], [], $timeout);
        if ($status !== 401) {
            return ['status' => 'fail', 'message' => "HTTP {$status} (want 401)"];
        }
        return ['status' => 'pass', 'message' => 'auth required'];
    },
];

runGroup('public', $only, $publicTests, $logFile, $verbose, $jsonOut,
    $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
    $totalTests, $passed, $failed, $warnings, $results);

// Shared roundtrip context + a list of sessions/seeds to revoke in cleanup.
$rt = [
    'approver_access' => null, 'approver_session' => null,
    'request_id' => null, 'poll_secret' => null, 'match' => null, 'code' => null,
    'exchanged_session' => null, 'pending_session' => null,
    'blocked_ip' => null, 'blocked_email' => null,
];

/** Log in as the approver; fills $rt or returns an outcome string. Idempotent. */
$loginApprover = function() use ($base, $email, $password, $timeout, &$rt) {
    if (!empty($rt['approver_access'])) return true;
    [$status, $d] = postJson($base, '/api/auth/login',
        ['email' => $email, 'password' => $password], [], $timeout);
    $data = $d['data'] ?? [];
    if ($status !== 200 || empty($data['access_token'])) {
        if (!empty($data['requires_2fa'])) return '2FA required — cannot run automated approval';
        return 'login failed (HTTP ' . $status . ')';
    }
    $rt['approver_access'] = $data['access_token'];
    $rt['approver_session'] = $data['session_token'] ?? '';
    return true;
};

// Authenticate up-front so any credentialled group (pending, attempts, block)
// works even when isolated with --only=GROUP, not just as part of roundtrip.
if (!$smoke && !$skipSend && $email !== '' && $password !== '') {
    $loginApprover();
}

// ----------- ROUNDTRIP (real chain; needs creds) -----------
$roundtripTests = [
    'login as approver' => function() use ($email, $password, $loginApprover) {
        if ($email === '' || $password === '') {
            return ['status' => 'warn', 'message' => 'no --email/--password (skipped)'];
        }
        $rv = $loginApprover();
        return $rv === true ? ['status' => 'pass', 'message' => 'token issued'] : ['status' => 'warn', 'message' => $rv];
    },
    'device start' => function() use ($base, $timeout, &$rt) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        [$status, $d] = postJson($base, '/api/sso/device/start', ['device_label' => '[FLOWONE-TEST] roundtrip'], [], $timeout);
        if ($status !== 200 || empty($d['data']['request_id'])) {
            return ['status' => 'fail', 'message' => "HTTP {$status}"];
        }
        $rt['request_id'] = $d['data']['request_id'];
        $rt['poll_secret'] = $d['data']['poll_secret'];
        $rt['match'] = (int) $d['data']['match_number'];
        return ['status' => 'pass', 'message' => 'match=' . sprintf('%02d', $rt['match'])];
    },
    'info (auth) returns 3 numbers incl. the match' => function() use ($base, $timeout, &$rt) {
        if (!$rt['request_id']) return ['status' => 'warn', 'message' => 'no request (skipped)'];
        [$status, , $body] = httpRequest('GET', $base . '/api/sso/device/info?req=' . urlencode($rt['request_id']),
            authHeaders($rt), null, $timeout);
        $d = json_decode($body, true);
        $nums = $d['data']['numbers'] ?? null;
        if ($status !== 200 || !is_array($nums) || count($nums) !== 3) {
            return ['status' => 'fail', 'message' => "HTTP {$status}, numbers=" . json_encode($nums)];
        }
        if (!in_array($rt['match'], array_map('intval', $nums), true)) {
            return ['status' => 'fail', 'message' => 'match number absent from candidates'];
        }
        return ['status' => 'pass', 'message' => 'candidates: ' . implode(',', $nums)];
    },
    'approve wrong number -> mismatch' => function() use ($base, $timeout, &$rt) {
        if (!$rt['request_id']) return ['status' => 'warn', 'message' => 'no request (skipped)'];
        $wrong = ($rt['match'] + 1) % 100;
        [$status, $d] = postJson($base, '/api/sso/device/approve',
            ['request_id' => $rt['request_id'], 'number' => $wrong], authHeaders($rt), $timeout);
        if ($status !== 401 || ($d['error'] ?? '') !== 'DEVICE_NUMBER_MISMATCH') {
            return ['status' => 'fail', 'message' => "HTTP {$status}, error=" . ($d['error'] ?? '?')];
        }
        return ['status' => 'pass', 'message' => 'mismatch rejected, attempts_left=' . ($d['attempts_left'] ?? '?')];
    },
    'approve correct number -> approved' => function() use ($base, $timeout, &$rt) {
        if (!$rt['request_id']) return ['status' => 'warn', 'message' => 'no request (skipped)'];
        [$status, $d] = postJson($base, '/api/sso/device/approve',
            ['request_id' => $rt['request_id'], 'number' => $rt['match']], authHeaders($rt), $timeout);
        if ($status !== 200 || ($d['data']['status'] ?? '') !== 'approved') {
            return ['status' => 'fail', 'message' => "HTTP {$status}, status=" . ($d['data']['status'] ?? '?')];
        }
        return ['status' => 'pass', 'message' => 'approved'];
    },
    'poll -> approved + one-time code' => function() use ($base, $timeout, &$rt) {
        if (!$rt['request_id']) return ['status' => 'warn', 'message' => 'no request (skipped)'];
        [$status, $d] = postJson($base, '/api/sso/device/poll',
            ['request_id' => $rt['request_id'], 'poll_secret' => $rt['poll_secret']], [], $timeout);
        $code = $d['data']['code'] ?? '';
        if ($status !== 200 || ($d['data']['status'] ?? '') !== 'approved' || $code === '') {
            return ['status' => 'fail', 'message' => "HTTP {$status}, status=" . ($d['data']['status'] ?? '?')];
        }
        $rt['code'] = $code;
        return ['status' => 'pass', 'message' => 'code received'];
    },
    'exchange code -> tokens' => function() use ($base, $timeout, &$rt) {
        if (!$rt['code']) return ['status' => 'warn', 'message' => 'no code (skipped)'];
        [$status, $d] = postJson($base, '/api/sso/exchange', ['code' => $rt['code'], 'nonce' => ''], [], $timeout);
        $data = $d['data'] ?? [];
        if ($status !== 200 || empty($data['access_token'])) {
            return ['status' => 'fail', 'message' => "HTTP {$status}"];
        }
        $rt['exchanged_session'] = $data['session_token'] ?? null;
        return ['status' => 'pass', 'message' => 'tokens issued for ' . ($data['user']['email'] ?? '?')];
    },
    'replay poll -> consumed (no code)' => function() use ($base, $timeout, &$rt) {
        if (!$rt['request_id']) return ['status' => 'warn', 'message' => 'no request (skipped)'];
        [$status, $d] = postJson($base, '/api/sso/device/poll',
            ['request_id' => $rt['request_id'], 'poll_secret' => $rt['poll_secret']], [], $timeout);
        if ($status !== 200 || ($d['data']['status'] ?? '') !== 'consumed' || !empty($d['data']['code'])) {
            return ['status' => 'fail', 'message' => "HTTP {$status}, status=" . ($d['data']['status'] ?? '?')];
        }
        return ['status' => 'pass', 'message' => 'code not re-issued'];
    },
];

if (!$smoke && !$skipSend) {
    runGroup('roundtrip', $only, $roundtripTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- PENDING (auto-modal: targeted requests pushed to signed-in sessions) -----------
$pend = ['request_id' => null, 'poll_secret' => null, 'match' => null, 'code' => null, 'foreign_req' => null];
$pendingTests = [
    'start targeted at me + appears in /pending with the match' => function() use ($base, $timeout, $email, &$rt, &$pend) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] pending-me', 'email' => $email], [], $timeout);
        if ($s !== 200 || empty($d['data']['request_id'])) {
            return ['status' => 'fail', 'message' => "start HTTP {$s}"];
        }
        $pend['request_id'] = $d['data']['request_id'];
        $pend['poll_secret'] = $d['data']['poll_secret'];
        $pend['match'] = (int) $d['data']['match_number'];

        [$ps, , $body] = httpRequest('GET', $base . '/api/sso/device/pending', authHeaders($rt), null, $timeout);
        $pd = json_decode($body, true);
        $requests = $pd['data']['requests'] ?? null;
        if ($ps !== 200 || !is_array($requests)) {
            return ['status' => 'fail', 'message' => "pending HTTP {$ps}"];
        }
        $mine = null;
        foreach ($requests as $r) {
            if (($r['request_id'] ?? '') === $pend['request_id']) { $mine = $r; break; }
        }
        if (!$mine) return ['status' => 'fail', 'message' => 'targeted request absent from /pending'];
        $nums = array_map('intval', $mine['numbers'] ?? []);
        if (count($nums) !== 3 || !in_array($pend['match'], $nums, true)) {
            return ['status' => 'fail', 'message' => 'numbers wrong: ' . json_encode($nums)];
        }
        return ['status' => 'pass', 'message' => 'surfaced; candidates ' . implode(',', $nums)];
    },
    'approve from /pending -> poll code -> exchange tokens' => function() use ($base, $timeout, &$rt, &$pend) {
        if (!$pend['request_id']) return ['status' => 'warn', 'message' => 'no targeted request (skipped)'];
        [$as, $ad] = postJson($base, '/api/sso/device/approve',
            ['request_id' => $pend['request_id'], 'number' => $pend['match']], authHeaders($rt), $timeout);
        if ($as !== 200 || ($ad['data']['status'] ?? '') !== 'approved') {
            return ['status' => 'fail', 'message' => "approve HTTP {$as}, status=" . ($ad['data']['status'] ?? '?')];
        }
        [$pps, $ppd] = postJson($base, '/api/sso/device/poll',
            ['request_id' => $pend['request_id'], 'poll_secret' => $pend['poll_secret']], [], $timeout);
        $code = $ppd['data']['code'] ?? '';
        if ($pps !== 200 || $code === '') {
            return ['status' => 'fail', 'message' => "poll HTTP {$pps}"];
        }
        [$es, $ed] = postJson($base, '/api/sso/exchange', ['code' => $code, 'nonce' => ''], [], $timeout);
        if ($es !== 200 || empty($ed['data']['access_token'])) {
            return ['status' => 'fail', 'message' => "exchange HTTP {$es}"];
        }
        $rt['pending_session'] = $ed['data']['session_token'] ?? null;
        return ['status' => 'pass', 'message' => 'signed in ' . ($ed['data']['user']['email'] ?? '?')];
    },
    'request targeted at another account is hidden + un-approvable' => function() use ($base, $timeout, &$rt, &$pend) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] foreign', 'email' => 'flowone_test_foreign@example.com'], [], $timeout);
        $req = $d['data']['request_id'] ?? null;
        $match = (int) ($d['data']['match_number'] ?? -1);
        if ($s !== 200 || !$req) return ['status' => 'fail', 'message' => 'start failed'];
        $pend['foreign_req'] = $req;

        // It must NOT show up in the approver's pending list.
        [$ps, , $body] = httpRequest('GET', $base . '/api/sso/device/pending', authHeaders($rt), null, $timeout);
        $pd = json_decode($body, true);
        foreach (($pd['data']['requests'] ?? []) as $r) {
            if (($r['request_id'] ?? '') === $req) {
                return ['status' => 'fail', 'message' => 'foreign request leaked into /pending'];
            }
        }
        // And the wrong account must not be able to approve it (404, not even mismatch).
        [$as, $ad] = postJson($base, '/api/sso/device/approve',
            ['request_id' => $req, 'number' => $match], authHeaders($rt), $timeout);
        if ($as !== 404) {
            return ['status' => 'fail', 'message' => "approve HTTP {$as} (want 404)"];
        }
        return ['status' => 'pass', 'message' => 'hidden + rejected for non-target'];
    },
    'rate limit: 6th targeted start -> 429' => function() use ($base, $timeout) {
        $target = 'flowone_test_rate@example.com';
        $last = 0;
        for ($i = 0; $i < 6; $i++) {
            [$last] = postJson($base, '/api/sso/device/start',
                ['device_label' => '[FLOWONE-TEST] rate', 'email' => $target], [], $timeout);
        }
        if ($last !== 429) {
            return ['status' => 'fail', 'message' => "final start HTTP {$last} (want 429)"];
        }
        return ['status' => 'pass', 'message' => 'throttled after cap'];
    },
];

if (!$smoke && !$skipSend) {
    runGroup('pending', $only, $pendingTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- DISMISS (cross-tab: resolved requests leave /pending) -----------
// The approval modal is shown on every signed-in tab while a request is pending,
// and the frontend dismisses it on any tab once the request disappears from
// /sso/device/pending. These assert the backend half of that contract: a request
// that is approved, denied, or expired no longer surfaces in /pending.
$dismissTests = [
    'approved request drops out of /pending' => function() use ($base, $timeout, $email, &$rt) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] dismiss-approve', 'email' => $email], [], $timeout);
        $req = $d['data']['request_id'] ?? null;
        $match = (int) ($d['data']['match_number'] ?? -1);
        $secret = $d['data']['poll_secret'] ?? '';
        if ($s !== 200 || !$req) return ['status' => 'fail', 'message' => "start HTTP {$s}"];
        if (!inPending($base, $timeout, $rt, $req)) {
            return ['status' => 'fail', 'message' => 'request not surfaced before approve'];
        }
        [$as] = postJson($base, '/api/sso/device/approve',
            ['request_id' => $req, 'number' => $match], authHeaders($rt), $timeout);
        if ($as !== 200) return ['status' => 'fail', 'message' => "approve HTTP {$as}"];
        if (inPending($base, $timeout, $rt, $req)) {
            return ['status' => 'fail', 'message' => 'approved request still in /pending (other tabs would not dismiss)'];
        }
        // Consume the minted code so we don't leave a usable one-time code dangling.
        postJson($base, '/api/sso/device/poll', ['request_id' => $req, 'poll_secret' => $secret], [], $timeout);
        return ['status' => 'pass', 'message' => 'gone after approve'];
    },
    'denied request drops out of /pending' => function() use ($base, $timeout, $email, &$rt) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] dismiss-deny', 'email' => $email], [], $timeout);
        $req = $d['data']['request_id'] ?? null;
        if ($s !== 200 || !$req) return ['status' => 'fail', 'message' => "start HTTP {$s}"];
        if (!inPending($base, $timeout, $rt, $req)) {
            return ['status' => 'fail', 'message' => 'request not surfaced before deny'];
        }
        [$ds] = postJson($base, '/api/sso/device/deny', ['request_id' => $req], authHeaders($rt), $timeout);
        if ($ds !== 200) return ['status' => 'fail', 'message' => "deny HTTP {$ds}"];
        if (inPending($base, $timeout, $rt, $req)) {
            return ['status' => 'fail', 'message' => 'denied request still in /pending'];
        }
        return ['status' => 'pass', 'message' => 'gone after deny'];
    },
    'expired request drops out of /pending' => function() use ($base, $timeout, $email, $pdo, &$rt) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        if (!$pdo) return ['status' => 'warn', 'message' => 'no DB connection (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] dismiss-expire', 'email' => $email], [], $timeout);
        $req = $d['data']['request_id'] ?? null;
        if ($s !== 200 || !$req) return ['status' => 'fail', 'message' => "start HTTP {$s}"];
        if (!inPending($base, $timeout, $rt, $req)) {
            return ['status' => 'fail', 'message' => 'request not surfaced before expiry'];
        }
        $upd = $pdo->prepare("UPDATE sso_device_requests SET expires_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE request_id = ?");
        $upd->execute([$req]);
        if (inPending($base, $timeout, $rt, $req)) {
            return ['status' => 'fail', 'message' => 'expired request still in /pending'];
        }
        return ['status' => 'pass', 'message' => 'gone after expiry'];
    },
];

if (!$smoke && !$skipSend) {
    runGroup('dismiss', $only, $dismissTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- ATTEMPTS (wrong-number cap auto-denies) -----------
$attemptsTests = [
    'three wrong numbers auto-deny the request' => function() use ($base, $timeout, &$rt) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start', ['device_label' => '[FLOWONE-TEST] attempts'], [], $timeout);
        $req = $d['data']['request_id'] ?? null;
        $match = (int) ($d['data']['match_number'] ?? -1);
        $secret = $d['data']['poll_secret'] ?? '';
        if ($s !== 200 || !$req) return ['status' => 'fail', 'message' => 'start failed'];
        $wrong = ($match + 1) % 100;
        $lastError = '';
        for ($i = 0; $i < 3; $i++) {
            [$st, $dd] = postJson($base, '/api/sso/device/approve',
                ['request_id' => $req, 'number' => $wrong], authHeaders($rt), $timeout);
            $lastError = $dd['error'] ?? '';
        }
        // After the cap, the request should be denied.
        [$ps, $pd] = postJson($base, '/api/sso/device/poll',
            ['request_id' => $req, 'poll_secret' => $secret], [], $timeout);
        if (($pd['data']['status'] ?? '') !== 'denied') {
            return ['status' => 'fail', 'message' => 'final status=' . ($pd['data']['status'] ?? '?') . " lastErr={$lastError}"];
        }
        return ['status' => 'pass', 'message' => 'auto-denied after cap'];
    },
];

if (!$smoke && !$skipSend) {
    runGroup('attempts', $only, $attemptsTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- EXPIRY (DB-backed) -----------
$expiryTests = [
    'expired request -> poll expired + approve 410' => function() use ($base, $timeout, $pdo, &$rt) {
        if (!$pdo) return ['status' => 'warn', 'message' => 'no DB connection (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start', ['device_label' => '[FLOWONE-TEST] expiry'], [], $timeout);
        $req = $d['data']['request_id'] ?? null;
        $secret = $d['data']['poll_secret'] ?? '';
        if ($s !== 200 || !$req) return ['status' => 'fail', 'message' => 'start failed'];
        // Force it into the past.
        $upd = $pdo->prepare("UPDATE sso_device_requests SET expires_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE request_id = ?");
        $upd->execute([$req]);
        [$ps, $pd] = postJson($base, '/api/sso/device/poll',
            ['request_id' => $req, 'poll_secret' => $secret], [], $timeout);
        if (($pd['data']['status'] ?? '') !== 'expired') {
            return ['status' => 'fail', 'message' => 'poll status=' . ($pd['data']['status'] ?? '?')];
        }
        // Approve must also refuse (needs auth; warn if approver missing).
        if ($rt['approver_access']) {
            [$as, $ad] = postJson($base, '/api/sso/device/approve',
                ['request_id' => $req, 'number' => 0], authHeaders($rt), $timeout);
            if ($as !== 410) {
                return ['status' => 'fail', 'message' => "approve HTTP {$as} (want 410)"];
            }
        }
        return ['status' => 'pass', 'message' => 'expired enforced'];
    },
];

if (!$smoke) {
    runGroup('expiry', $only, $expiryTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- BLOCK (deny + per-account IP block; needs creds) -----------
// Runs last among credentialled groups: it blocks the test machine's IP for the
// approver's account, so no later group may do a targeted start for that email.
$blockTests = [
    'block a targeted request -> 200 + IP recorded' => function() use ($base, $timeout, $email, &$rt) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] block', 'email' => $email], [], $timeout);
        $req = $d['data']['request_id'] ?? null;
        if ($s !== 200 || !$req) return ['status' => 'fail', 'message' => "start HTTP {$s}"];
        [$bs, $bd] = postJson($base, '/api/sso/device/block',
            ['request_id' => $req], authHeaders($rt), $timeout);
        if ($bs !== 200 || ($bd['data']['status'] ?? '') !== 'blocked') {
            return ['status' => 'fail', 'message' => "block HTTP {$bs}, status=" . ($bd['data']['status'] ?? '?')];
        }
        $rt['blocked_ip'] = $bd['data']['ip_address'] ?? '';
        $rt['blocked_email'] = strtolower($email);
        return ['status' => 'pass', 'message' => 'blocked ip=' . ($rt['blocked_ip'] ?: '?')];
    },
    'blocked IP can no longer start for that account -> 403' => function() use ($base, $timeout, $email, &$rt) {
        if (empty($rt['blocked_ip'])) return ['status' => 'warn', 'message' => 'nothing blocked (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] block-retry', 'email' => $email], [], $timeout);
        if ($s !== 403 || ($d['error'] ?? '') !== 'DEVICE_BLOCKED') {
            return ['status' => 'fail', 'message' => "HTTP {$s}, error=" . ($d['error'] ?? '?')];
        }
        return ['status' => 'pass', 'message' => 'blocked at start'];
    },
    "block another account's request -> 404" => function() use ($base, $timeout, &$rt) {
        if (!$rt['approver_access']) return ['status' => 'warn', 'message' => 'no approver session (skipped)'];
        [$s, $d] = postJson($base, '/api/sso/device/start',
            ['device_label' => '[FLOWONE-TEST] block-foreign', 'email' => 'flowone_test_foreign2@example.com'], [], $timeout);
        $req = $d['data']['request_id'] ?? null;
        if ($s !== 200 || !$req) return ['status' => 'fail', 'message' => 'start failed'];
        [$bs] = postJson($base, '/api/sso/device/block',
            ['request_id' => $req], authHeaders($rt), $timeout);
        if ($bs !== 404) return ['status' => 'fail', 'message' => "block HTTP {$bs} (want 404)"];
        return ['status' => 'pass', 'message' => 'cannot block foreign target'];
    },
];

if (!$smoke && !$skipSend) {
    runGroup('block', $only, $blockTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ---- cleanup: revoke sessions/seed + delete test rows. Runs even on failure. ----
$cleanupDone = false;
$cleanup = function() use (&$cleanupDone, &$rt, $pdo, $base, $timeout, $logFile) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    try {
        if (!empty($rt['exchanged_session'])) {
            httpRequest('POST', $base . '/api/auth/logout',
                ['Origin' => 'https://localhost', 'X-Session-Token' => $rt['exchanged_session']], '{}', $timeout);
        }
        if (!empty($rt['pending_session'])) {
            httpRequest('POST', $base . '/api/auth/logout',
                ['Origin' => 'https://localhost', 'X-Session-Token' => $rt['pending_session']], '{}', $timeout);
        }
        if (!empty($rt['approver_access'])) {
            // Revoke any SSO seeds created for the approver during the roundtrip.
            httpRequest('POST', $base . '/api/sso/revoke-seed',
                ['Origin' => 'https://localhost', 'Authorization' => 'Bearer ' . $rt['approver_access'],
                 'X-Session-Token' => $rt['approver_session'] ?? ''], '{}', $timeout);
        }
        if (!empty($rt['approver_session'])) {
            httpRequest('POST', $base . '/api/auth/logout',
                ['Origin' => 'https://localhost', 'X-Session-Token' => $rt['approver_session']], '{}', $timeout);
        }
        if ($pdo) {
            $pdo->exec("DELETE FROM sso_device_requests WHERE device_label LIKE '[FLOWONE-TEST]%'");
            if (!empty($rt['blocked_ip']) && !empty($rt['blocked_email'])) {
                $del = $pdo->prepare("DELETE FROM sso_device_blocked_ips WHERE ip_address = ? AND target_email = ?");
                $del->execute([$rt['blocked_ip'], $rt['blocked_email']]);
            }
        }
        logLine('Cleanup: sessions/seed revoked, test rows removed', $logFile);
    } catch (\Throwable $e) {
        logLine('Cleanup error: ' . $e->getMessage(), $logFile);
    }
};
register_shutdown_function($cleanup);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function() use ($cleanup) { $cleanup(); exit(1); });
    pcntl_signal(SIGTERM, function() use ($cleanup) { $cleanup(); exit(1); });
}
$cleanup();

// ---- summary ----
if ($jsonOut) {
    echo json_encode([
        'total' => $totalTests, 'passed' => $passed, 'failed' => $failed,
        'warnings' => $warnings, 'base' => $base, 'log_file' => $logFile, 'results' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n==================== SUMMARY ====================\n";
    echo "  Target:   {$base}\n";
    echo "  Total:    {$totalTests}\n";
    echo "  Passed:   {$c_green}{$passed}{$c_reset}\n";
    echo "  Failed:   {$c_red}{$failed}{$c_reset}\n";
    echo "  Warnings: {$c_yellow}{$warnings}{$c_reset}\n";
    echo "  Log:      {$logFile}\n";
    if ($failed > 0) {
        echo "\n{$c_red}FAILED TESTS:{$c_reset}\n";
        foreach ($results as $r) {
            if ($r['status'] === 'fail') echo "  - [{$r['group']}] {$r['name']}: {$r['message']}\n";
        }
    }
    if ($warnings > 0) {
        echo "\n{$c_yellow}WARNINGS:{$c_reset}\n";
        foreach ($results as $r) {
            if ($r['status'] === 'warn') echo "  - [{$r['group']}] {$r['name']}: {$r['message']}\n";
        }
    }
    echo "\n";
}

logLine("Summary: passed={$passed} failed={$failed} warnings={$warnings} total={$totalTests}", $logFile);
exit($failed > 0 ? 1 : 0);
