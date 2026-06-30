#!/usr/bin/env php
<?php
/**
 * FlowOne Per-Domain App Routing Test.
 *
 * Verifies the server side of the "one app binary, many domains" routing:
 * the native apps (FlowOne Pro / Chat / Drive) derive their backend from the
 * user's email domain using the convention `email.<domain>` and then talk to
 * that server with credentialed CORS. This test asserts that a given
 * deployment accepts the native WebView origins, exposes the OAuth-availability
 * probe, advertises its own WebSocket origin in the CSP, and (optionally)
 * completes a real login roundtrip.
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   PHP CLI, curl extension, --base reachable
 *   derive      email.<domain> derivation matches the app convention
 *   discovery   GET /api/server-discovery routes domains correctly: a domain
 *               hosted here returns this server's origin; an unknown domain
 *               returns the email.<domain> convention; missing input -> 400
 *   cors        native origins (https://localhost, capacitor://localhost) are
 *               echoed with Access-Control-Allow-Credentials: true; a bogus
 *               cross-origin is NOT echoed
 *   health      public OAuth-availability probe returns JSON 200
 *   csp         Content-Security-Policy connect-src advertises this host's wss
 *   login       real POST /api/auth/login roundtrip (needs --email/--password;
 *               skipped by --skip-send; session is revoked in cleanup)
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/per-domain-routing-test.php \
 *       --base=https://email.example.com --verbose
 *
 *   # full login roundtrip:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/per-domain-routing-test.php \
 *       --email=USER@example.com --password=SECRET --verbose
 *
 * Flags:
 *   --base=URL              server base to test (default: derived from --email,
 *                           else https://flowone.pro)
 *   --email=ADDR            account email (also derives --base if not given)
 *   --password=PASS         account password (enables the login group)
 *   --domains=a.com,b.com   extra domains to assert derivation for (device matrix)
 *   --timeout=SECONDS       per-request timeout (default 30)
 *   --verbose               extra debug output (raw headers/bodies on failure)
 *   --json                  emit results as JSON to stdout
 *   --smoke                 preflight + health only (connectivity check)
 *   --only=GROUP[,GROUP]    run only listed groups
 *   --skip-send             skip the destructive login roundtrip group
 *   --help                  show this message
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', [
    'base:',
    'email:',
    'password:',
    'domains:',
    'timeout:',
    'verbose',
    'json',
    'smoke',
    'only:',
    'skip-send',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2700));
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

/**
 * Derive the backend base from an email using the deploy convention
 * `email.<domain>`. Mirrors serverRegistry.deriveBaseFromEmail() in the apps
 * and the Drive serverForEmail() helper.
 */
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

$extraDomains = isset($opts['domains'])
    ? array_filter(array_map('trim', explode(',', (string) $opts['domains'])))
    : [];

$backendRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$logDir = $backendRoot . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/per-domain-routing-' . date('Ymd-His') . '.log';

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

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

/**
 * Minimal curl wrapper. Returns [status, headers(assoc, lower-cased), body].
 * Header values are returned as the LAST seen value for a name.
 */
function httpRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array {
    $ch = curl_init();
    $reqHeaders = [];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "{$k}: {$v}";
    }
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
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
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

function runGroup(
    string $name, array $only, array $tests, string $logFile,
    bool $verbose, bool $jsonOut,
    string $c_green, string $c_red, string $c_yellow, string $c_dim, string $c_reset,
    int &$totalTests, int &$passed, int &$failed, int &$warnings, array &$results
): void {
    if (!empty($only) && !in_array($name, $only, true)) {
        return;
    }
    if (!$jsonOut) {
        echo "\n--- " . strtoupper($name) . " ---\n";
    }
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

        $tag = '[PASS]';
        $color = $c_green;
        if ($outcome['status'] === 'fail') {
            $tag = '[FAIL]'; $color = $c_red; $failed++;
        } elseif ($outcome['status'] === 'warn') {
            $tag = '[WARN]'; $color = $c_yellow; $warnings++;
        } else {
            $passed++;
        }

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
if (!$jsonOut) {
    echo "Target: {$c_dim}{$base}{$c_reset}\n";
}

// ----------- PREFLIGHT -----------
$preflightTests = [
    'PHP CLI available' => function() {
        return ['status' => 'pass', 'message' => 'PHP ' . PHP_VERSION];
    },
    'curl extension loaded' => function() {
        if (!function_exists('curl_init')) {
            return ['status' => 'fail', 'message' => 'php-curl is required for this test'];
        }
        return ['status' => 'pass'];
    },
    'base host resolves + TLS handshakes' => function() use ($base, $timeout) {
        try {
            [$status] = httpRequest('GET', $base . '/api/auth/google/enabled', [], null, $timeout);
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => $e->getMessage()];
        }
        if ($status === 0) {
            return ['status' => 'fail', 'message' => 'no HTTP status (host unreachable)'];
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

// ----------- DERIVE (pure logic, safe) -----------
$deriveTests = [
    'derivation: convention email.<domain>' => function() {
        $cases = [
            'robert@magyarszinhaz.hu' => 'https://email.magyarszinhaz.hu',
            'a.b+tag@Sub.Example.COM' => 'https://email.sub.example.com',
            'no-at-sign'              => '',
            ''                        => '',
        ];
        $bad = [];
        foreach ($cases as $in => $want) {
            $got = deriveBaseFromEmail((string) $in);
            if ($got !== $want) {
                $bad[] = "'{$in}' => '{$got}' (want '{$want}')";
            }
        }
        if (!empty($bad)) {
            return ['status' => 'fail', 'message' => implode('; ', $bad)];
        }
        return ['status' => 'pass', 'message' => count($cases) . ' cases'];
    },
    'derivation: device matrix domains' => function() use ($extraDomains) {
        if (empty($extraDomains)) {
            return ['status' => 'pass', 'message' => 'no --domains supplied (skipped)'];
        }
        $lines = [];
        foreach ($extraDomains as $d) {
            $lines[] = $d . ' -> ' . deriveBaseFromEmail("user@{$d}");
        }
        return ['status' => 'pass', 'message' => implode(', ', $lines)];
    },
];

if (!$smoke) {
    runGroup('derive', $only, $deriveTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- DISCOVERY (server-side domain -> backend routing) -----------
// The native apps ask GET /api/server-discovery?domain=X to learn which backend
// owns a domain. A server returns its own origin for domains it hosts (shared
// tenants) and the email.<domain> convention for anything else (dedicated).
$selfHost = strtolower((string) (parse_url($base, PHP_URL_HOST) ?: ''));
$selfDomain = preg_replace('/^(email|mail|webmail)\./', '', $selfHost);

$discoveryTests = [
    'discovery: missing domain -> 400' => function() use ($base, $timeout) {
        [$status] = httpRequest('GET', $base . '/api/server-discovery', [], null, $timeout);
        if ($status !== 400) {
            return ['status' => 'fail', 'message' => "HTTP {$status} (want 400)"];
        }
        return ['status' => 'pass', 'message' => 'rejected with 400'];
    },
    'discovery: hosted domain -> this server origin' => function() use ($base, $timeout, $selfDomain, $verbose) {
        if ($selfDomain === '') {
            return ['status' => 'warn', 'message' => 'could not derive self domain from --base'];
        }
        [$status, , $body] = httpRequest('GET',
            $base . '/api/server-discovery?domain=' . urlencode($selfDomain), [], null, $timeout);
        $d = json_decode($body, true);
        if ($status !== 200 || !is_array($d)) {
            return ['status' => 'fail', 'message' => "HTTP {$status}", 'detail' => $verbose ? substr($body, 0, 200) : null];
        }
        if (empty($d['hosted'])) {
            // Server may host only mailboxes under email.<domain>, not the apex.
            return ['status' => 'warn', 'message' => "hosted=false for {$selfDomain}; api_url=" . ($d['api_url'] ?? '')];
        }
        $apiUrl = (string) ($d['api_url'] ?? '');
        if (!preg_match('#^https?://#', $apiUrl)) {
            return ['status' => 'fail', 'message' => "hosted but api_url invalid: '{$apiUrl}'"];
        }
        return ['status' => 'pass', 'message' => "hosted -> {$apiUrl}"];
    },
    'discovery: unknown domain -> email.<domain> convention' => function() use ($base, $timeout) {
        $domain = 'flowone-test-' . substr(md5((string) mt_rand()), 0, 8) . '.invalid';
        [$status, , $body] = httpRequest('GET',
            $base . '/api/server-discovery?domain=' . urlencode($domain), [], null, $timeout);
        $d = json_decode($body, true);
        if ($status !== 200 || !is_array($d)) {
            return ['status' => 'fail', 'message' => "HTTP {$status}"];
        }
        if (!empty($d['hosted'])) {
            // Only happens on the DB fail-safe path (claims every domain).
            return ['status' => 'warn', 'message' => "server claims to host {$domain} (DB fail-safe?)"];
        }
        $want = 'https://email.' . $domain;
        if (($d['api_url'] ?? '') !== $want) {
            return ['status' => 'fail', 'message' => "api_url='" . ($d['api_url'] ?? '') . "' (want '{$want}')"];
        }
        return ['status' => 'pass', 'message' => "unknown -> {$want}"];
    },
];

if (!$smoke) {
    runGroup('discovery', $only, $discoveryTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- HEALTH -----------
$healthTests = [
    'public OAuth probe returns JSON 200' => function() use ($base, $timeout, $verbose) {
        [$status, $hdrs, $body] = httpRequest('GET', $base . '/api/auth/google/enabled', [], null, $timeout);
        if ($status !== 200) {
            return ['status' => 'fail', 'message' => "HTTP {$status}", 'detail' => $verbose ? substr($body, 0, 200) : null];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['status' => 'fail', 'message' => 'response is not JSON', 'detail' => substr($body, 0, 200)];
        }
        return ['status' => 'pass', 'message' => 'HTTP 200, JSON body'];
    },
];

runGroup('health', $only, $healthTests, $logFile, $verbose, $jsonOut,
    $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
    $totalTests, $passed, $failed, $warnings, $results);

// ----------- CORS -----------
$corsTests = [
    'native https://localhost origin echoed + credentials' => function() use ($base, $timeout) {
        $origin = 'https://localhost';
        [$status, $hdrs] = httpRequest('OPTIONS', $base . '/api/auth/login',
            ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'], null, $timeout);
        $acao = $hdrs['access-control-allow-origin'] ?? '';
        $acac = $hdrs['access-control-allow-credentials'] ?? '';
        if ($acao !== $origin) {
            return ['status' => 'fail', 'message' => "ACAO='{$acao}' (want '{$origin}'), HTTP {$status}"];
        }
        if (strtolower($acac) !== 'true') {
            return ['status' => 'fail', 'message' => "Allow-Credentials='{$acac}' (want 'true')"];
        }
        return ['status' => 'pass', 'message' => "echoed {$origin} + credentials"];
    },
    'native capacitor://localhost origin echoed' => function() use ($base, $timeout) {
        $origin = 'capacitor://localhost';
        [$status, $hdrs] = httpRequest('OPTIONS', $base . '/api/auth/login',
            ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'], null, $timeout);
        $acao = $hdrs['access-control-allow-origin'] ?? '';
        if ($acao !== $origin) {
            // Some deployments only enable https-scheme native shells; warn
            // rather than fail so iOS-https / Android-only builds still pass.
            return ['status' => 'warn', 'message' => "ACAO='{$acao}' (capacitor scheme not allowlisted), HTTP {$status}"];
        }
        return ['status' => 'pass', 'message' => "echoed {$origin}"];
    },
    'bogus cross-origin is NOT echoed' => function() use ($base, $timeout) {
        $origin = 'https://evil.example.net';
        [$status, $hdrs] = httpRequest('OPTIONS', $base . '/api/auth/login',
            ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'], null, $timeout);
        $acao = $hdrs['access-control-allow-origin'] ?? '';
        if ($acao === $origin) {
            return ['status' => 'fail', 'message' => "server echoed attacker origin {$origin}"];
        }
        return ['status' => 'pass', 'message' => "not echoed (ACAO='{$acao}')"];
    },
];

if (!$smoke) {
    runGroup('cors', $only, $corsTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- CSP -----------
$cspTests = [
    'connect-src advertises this host wss origin' => function() use ($base, $timeout) {
        [$status, $hdrs, $body] = httpRequest('GET', $base . '/api/auth/google/enabled', [], null, $timeout);
        $csp = $hdrs['content-security-policy'] ?? '';
        if ($csp === '') {
            return ['status' => 'warn', 'message' => 'no Content-Security-Policy header on /api responses'];
        }
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        $wssOrigin = 'wss://' . $host;
        if ($host !== '' && strpos($csp, $wssOrigin) === false && strpos($csp, "connect-src 'self'") === false) {
            return ['status' => 'fail', 'message' => "connect-src lacks {$wssOrigin} and 'self'"];
        }
        return ['status' => 'pass', 'message' => "connect-src OK for {$host}"];
    },
];

if (!$smoke) {
    runGroup('csp', $only, $cspTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- LOGIN (real roundtrip; needs creds, honors --skip-send) -----------
$createdSessionToken = null;
$loginTests = [
    'POST /api/auth/login roundtrip' => function() use ($base, $email, $password, $timeout, $verbose, &$createdSessionToken) {
        if ($email === '' || $password === '') {
            return ['status' => 'pass', 'message' => 'no --email/--password (skipped)'];
        }
        $payload = json_encode(['email' => $email, 'password' => $password]);
        [$status, $hdrs, $body] = httpRequest('POST', $base . '/api/auth/login',
            ['Origin' => 'https://localhost', 'Content-Type' => 'application/json'], $payload, $timeout);
        $decoded = json_decode($body, true);
        if ($status !== 200 || !is_array($decoded)) {
            return ['status' => 'fail', 'message' => "HTTP {$status}", 'detail' => $verbose ? substr($body, 0, 300) : null];
        }
        $data = $decoded['data'] ?? [];
        if (!empty($data['requires_2fa'])) {
            return ['status' => 'pass', 'message' => 'login OK (2FA required — token not issued)'];
        }
        if (empty($data['access_token'])) {
            $m = $decoded['message'] ?? 'no access_token in response';
            return ['status' => 'fail', 'message' => $m];
        }
        $createdSessionToken = $data['session_token'] ?? null;
        return ['status' => 'pass', 'message' => 'login OK, access_token issued'];
    },
];

if (!$smoke && !$skipSend) {
    runGroup('login', $only, $loginTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ---- cleanup: revoke any session we created ----
$cleanup = function() use (&$createdSessionToken, $base, $timeout, $logFile) {
    if (!$createdSessionToken) return;
    try {
        httpRequest('POST', $base . '/api/auth/logout',
            ['Origin' => 'https://localhost', 'X-Session-Token' => $createdSessionToken], '{}', $timeout);
        logLine('Cleanup: revoked test login session', $logFile);
    } catch (\Throwable $e) {
        logLine('Cleanup error: ' . $e->getMessage(), $logFile);
    }
    $createdSessionToken = null;
};
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
