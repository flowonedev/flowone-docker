#!/usr/bin/env php
<?php
/**
 * FlowOne Chat Attachment Upload Test.
 *
 * Verifies the full chat attachment upload chain end-to-end, focused on the iOS
 * regression where native shells could not upload: CapacitorHttp strips the
 * binary part out of a multipart FormData in transit, so the native apps now
 * POST files as base64 JSON instead. This test asserts BOTH transports work:
 *
 *   - base64 JSON body         (native iOS/Android path -- the bug fix)
 *   - real multipart FormData  (web/desktop path -- must not regress)
 *
 * It also checks the cross-origin CORS preflight the native WebView relies on,
 * and the error paths (empty body, blocked file type).
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   PHP CLI, curl/fileinfo extensions, base reachable, DB connect,
 *               chat storage dir writable
 *   cors        OPTIONS to the attachments endpoint from https://localhost and
 *               capacitor://localhost is echoed with credentials; bogus origin
 *               is NOT echoed
 *   auth        real login roundtrip -> access_token + session_token, then pick
 *               a conversation the user participates in (needs --email/--password)
 *   base64      POST base64 JSON (raw + data-URI), asserts the attachment is
 *               stored on disk; empty body -> "No files uploaded"; .php blocked
 *   multipart   POST a real multipart upload (CURLFile) -> attachment stored
 *
 * Every uploaded test file is removed in cleanup (try/finally + signal handler),
 * and the login session is revoked. No existing data is modified.
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/chat-attachment-upload-test.php \
 *       --email=USER@flowone.pro --password=SECRET --verbose
 *
 * Flags:
 *   --base=URL              server base to test (default: https://flowone.pro)
 *   --email=ADDR            account email (login only; does NOT set the host)
 *   --password=PASS         account password (enables auth + upload groups)
 *   --conversation=ID       conversation id to upload into (default: newest one
 *                           the account participates in)
 *   --timeout=SECONDS       per-request timeout (default 30)
 *   --verbose               extra debug output (raw bodies on failure)
 *   --json                  emit results as JSON to stdout
 *   --smoke                 preflight + cors only (connectivity / config check)
 *   --only=GROUP[,GROUP]    run only listed groups
 *   --skip-send             skip the upload roundtrips (cors + preflight only)
 *   --help                  show this message
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', [
    'base:', 'email:', 'password:', 'conversation:', 'timeout:',
    'verbose', 'json', 'smoke', 'only:', 'skip-send', 'help',
]);

$USAGE = <<<TXT
FlowOne Chat Attachment Upload Test

Usage:
  php chat-attachment-upload-test.php --email=USER --password=PASS [flags]

Flags:
  --base=URL            server base (default: https://flowone.pro)
  --email=ADDR          account email (login only; does NOT set the host)
  --password=PASS       account password (enables auth + upload groups)
  --conversation=ID     conversation id to upload into (default: newest one joined)
  --timeout=SECONDS     per-request timeout (default 30)
  --verbose             extra debug output on failure
  --json                emit results as JSON
  --smoke               preflight + cors only
  --only=GROUP[,GROUP]  run only listed groups (preflight,cors,auth,base64,multipart)
  --skip-send           skip the upload roundtrips
  --help                show this message

Exit: 0 on all PASS/WARN, 1 on any FAIL.
TXT;

if (isset($opts['help'])) {
    fwrite(STDOUT, $USAGE . "\n");
    exit(0);
}

require_once __DIR__ . '/../cron/bootstrap.php';
$config = require __DIR__ . '/../src/config.php';

$jsonOut  = isset($opts['json']);
$verbose  = isset($opts['verbose']);
$smoke    = isset($opts['smoke']);
$skipSend = isset($opts['skip-send']);
$only = isset($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : [];
$timeout = isset($opts['timeout']) ? max(1, (int) $opts['timeout']) : 30;

$email    = isset($opts['email']) ? trim((string) $opts['email']) : '';
$password = isset($opts['password']) ? (string) $opts['password'] : '';
$convOverride = isset($opts['conversation']) ? (int) $opts['conversation'] : 0;

// The app/API is always served from flowone.pro, regardless of which mail
// domain the login account uses (accounts can be @flowone.pro, @pixelranger.hu,
// etc.). Never derive the API host from the email domain. Override with --base
// only if you are testing against a non-production host.
$base = isset($opts['base']) ? rtrim((string) $opts['base'], '/') : '';
if ($base === '') {
    $base = 'https://flowone.pro';
}

$backendRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$logDir = $backendRoot . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/chat-attachment-upload-' . date('Ymd-His') . '.log';

// 1x1 transparent PNG -- recognized as image/png by finfo, too small to thumbnail.
$PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$PNG_BYTES = base64_decode($PNG_B64, true);
$TEST_PREFIX = 'flowone_test_';

$totalTests = 0; $passed = 0; $failed = 0; $warnings = 0; $results = [];

// State shared across groups.
$accessToken = null;
$sessionToken = null;
$conversationId = 0;
$createdPaths = [];      // attachment 'path'/'thumbnail' values to remove in cleanup
$chatService = null;
$baseDir = null;

$useColor = (!$jsonOut) && function_exists('posix_isatty') && @posix_isatty(STDOUT);
$c_green  = $useColor ? "\033[0;32m" : '';
$c_red    = $useColor ? "\033[0;31m" : '';
$c_yellow = $useColor ? "\033[0;33m" : '';
$c_dim    = $useColor ? "\033[2m"    : '';
$c_reset  = $useColor ? "\033[0m"    : '';

function logLine(string $line, string $logFile): void {
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Minimal curl wrapper. Returns [status, headers(lower-cased assoc), body]. */
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
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    return curlExec($ch);
}

/** Multipart POST (real $_FILES on the server). $fields may contain CURLFile. */
function httpMultipart(string $url, array $headers, array $fields, int $timeout): array {
    $ch = curl_init();
    $reqHeaders = [];
    foreach ($headers as $k => $v) { $reqHeaders[] = "{$k}: {$v}"; }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields, // array => curl sets multipart/form-data + boundary
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    return curlExec($ch);
}

function curlExec($ch): array {
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

/** Authenticated JSON/multipart auth headers for the upload endpoint. */
function uploadHeaders(?string $accessToken, ?string $sessionToken, array $extra = []): array {
    $h = ['Origin' => 'https://localhost'];
    if ($accessToken)  $h['Authorization'] = 'Bearer ' . $accessToken;
    if ($sessionToken) $h['X-Session-Token'] = $sessionToken;
    return array_merge($h, $extra);
}

logLine("Target base: {$base}", $logFile);
if (!$jsonOut) { echo "Target: {$c_dim}{$base}{$c_reset}\n"; }

// ----------- PREFLIGHT -----------
$preflightTests = [
    'PHP CLI available' => function() {
        return ['status' => 'pass', 'message' => 'PHP ' . PHP_VERSION];
    },
    'curl + fileinfo extensions loaded' => function() {
        $missing = [];
        foreach (['curl', 'fileinfo', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) $missing[] = $ext;
        }
        if ($missing) return ['status' => 'fail', 'message' => 'missing: ' . implode(', ', $missing)];
        $gd = extension_loaded('gd');
        return ['status' => $gd ? 'pass' : 'warn',
                'message' => $gd ? 'curl, fileinfo, mbstring, gd' : 'gd not loaded (image thumbnails skipped)'];
    },
    'base host reachable + TLS' => function() use ($base, $timeout) {
        [$status] = httpRequest('GET', $base . '/api/auth/google/enabled', [], null, $timeout);
        if ($status === 0) return ['status' => 'fail', 'message' => 'host unreachable'];
        return ['status' => 'pass', 'message' => "reachable (HTTP {$status})"];
    },
    'DB connection + chat storage writable' => function() use ($config, $email, &$chatService, &$baseDir) {
        $chatService = new \Webmail\Addons\Chat\Services\ChatService($config);
        $baseDir = $chatService->getChatAttachmentsBaseDir($email ?: null);
        if (!is_string($baseDir) || $baseDir === '' || !is_dir($baseDir) || !is_writable($baseDir)) {
            return ['status' => 'fail', 'message' => "chat storage base not writable: " . var_export($baseDir, true)];
        }
        return ['status' => 'pass', 'message' => "storage: {$baseDir}"];
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

// ----------- CORS (preflight on the attachments endpoint) -----------
$corsPath = $base . '/api/chat/conversations/1/attachments';
$corsTests = [
    'OPTIONS https://localhost echoed + credentials' => function() use ($corsPath, $timeout) {
        $origin = 'https://localhost';
        [$status, $hdrs] = httpRequest('OPTIONS', $corsPath,
            ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST',
             'Access-Control-Request-Headers' => 'authorization,content-type,x-session-token'], null, $timeout);
        $acao = $hdrs['access-control-allow-origin'] ?? '';
        $acac = $hdrs['access-control-allow-credentials'] ?? '';
        if ($acao !== $origin) return ['status' => 'fail', 'message' => "ACAO='{$acao}' (want '{$origin}'), HTTP {$status}"];
        if (strtolower($acac) !== 'true') return ['status' => 'fail', 'message' => "Allow-Credentials='{$acac}' (want 'true')"];
        return ['status' => 'pass', 'message' => "echoed {$origin} + credentials"];
    },
    'OPTIONS capacitor://localhost echoed' => function() use ($corsPath, $timeout) {
        $origin = 'capacitor://localhost';
        [$status, $hdrs] = httpRequest('OPTIONS', $corsPath,
            ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'], null, $timeout);
        $acao = $hdrs['access-control-allow-origin'] ?? '';
        if ($acao !== $origin) {
            return ['status' => 'warn', 'message' => "ACAO='{$acao}' (capacitor scheme not allowlisted)"];
        }
        return ['status' => 'pass', 'message' => "echoed {$origin}"];
    },
    'Allow-Headers covers Authorization + X-Session-Token' => function() use ($corsPath, $timeout) {
        [, $hdrs] = httpRequest('OPTIONS', $corsPath,
            ['Origin' => 'https://localhost', 'Access-Control-Request-Method' => 'POST'], null, $timeout);
        $allow = strtolower($hdrs['access-control-allow-headers'] ?? '');
        foreach (['authorization', 'content-type', 'x-session-token'] as $needed) {
            if (strpos($allow, $needed) === false) {
                return ['status' => 'fail', 'message' => "Allow-Headers missing '{$needed}': '{$allow}'"];
            }
        }
        return ['status' => 'pass', 'message' => 'authorization, content-type, x-session-token allowed'];
    },
    'bogus cross-origin is NOT echoed' => function() use ($corsPath, $timeout) {
        $origin = 'https://evil.example.net';
        [, $hdrs] = httpRequest('OPTIONS', $corsPath,
            ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'], null, $timeout);
        $acao = $hdrs['access-control-allow-origin'] ?? '';
        if ($acao === $origin) return ['status' => 'fail', 'message' => "server echoed attacker origin {$origin}"];
        return ['status' => 'pass', 'message' => "not echoed (ACAO='{$acao}')"];
    },
];

runGroup('cors', $only, $corsTests, $logFile, $verbose, $jsonOut,
    $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
    $totalTests, $passed, $failed, $warnings, $results);

// ----------- AUTH (login + resolve a conversation; needs creds) -----------
$haveCreds = ($email !== '' && $password !== '');
$authTests = [
    'POST /api/auth/login -> tokens' => function() use ($base, $email, $password, $timeout, $verbose, &$accessToken, &$sessionToken) {
        $payload = json_encode(['email' => $email, 'password' => $password]);
        [$status, , $body] = httpRequest('POST', $base . '/api/auth/login',
            ['Origin' => 'https://localhost', 'Content-Type' => 'application/json'], $payload, $timeout);
        $d = json_decode($body, true);
        if ($status !== 200 || !is_array($d)) {
            return ['status' => 'fail', 'message' => "HTTP {$status}", 'detail' => $verbose ? substr($body, 0, 300) : null];
        }
        $data = $d['data'] ?? [];
        if (!empty($data['requires_2fa'])) return ['status' => 'fail', 'message' => '2FA required -- use an account without 2FA for this test'];
        if (empty($data['access_token'])) return ['status' => 'fail', 'message' => $d['message'] ?? 'no access_token'];
        $accessToken  = $data['access_token'];
        $sessionToken = $data['session_token'] ?? null;
        return ['status' => 'pass', 'message' => 'access_token + session_token issued'];
    },
    'resolve a conversation the user joined' => function() use ($config, $email, $convOverride, &$conversationId) {
        if ($convOverride > 0) { $conversationId = $convOverride; return ['status' => 'pass', 'message' => "using --conversation={$conversationId}"]; }
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('
            SELECT cp.conversation_id
            FROM chat_participants cp
            JOIN organization_colleagues oc ON cp.colleague_id = oc.id
            WHERE oc.email = ?
            ORDER BY cp.conversation_id DESC
            LIMIT 1');
        $stmt->execute([$email]);
        $cid = (int) ($stmt->fetchColumn() ?: 0);
        if ($cid <= 0) return ['status' => 'warn', 'message' => 'account is in no conversations -- pass --conversation=ID to test uploads'];
        $conversationId = $cid;
        return ['status' => 'pass', 'message' => "conversation #{$cid}"];
    },
];

if (!$smoke && !$skipSend && $haveCreds) {
    runGroup('auth', $only, $authTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
} elseif (!$jsonOut && !$smoke && !$skipSend && !$haveCreds) {
    echo "\n--- AUTH ---\n  {$c_yellow}[WARN]{$c_reset} skipped (no --email/--password; upload groups need login)\n";
}

$canUpload = (!$smoke && !$skipSend && $haveCreds && $accessToken && $conversationId > 0);

// ----------- BASE64 JSON upload (native path -- the fix) -----------
$base64Tests = [
    'base64 JSON upload stores attachment on disk' => function() use ($base, $conversationId, $accessToken, $sessionToken, $PNG_B64, $TEST_PREFIX, $baseDir, $timeout, $verbose, &$createdPaths) {
        $name = $TEST_PREFIX . bin2hex(random_bytes(4)) . '.png';
        $payload = json_encode(['files' => [['name' => $name, 'type' => 'image/png', 'data' => $PNG_B64]]]);
        [$status, , $body] = httpMultipartlessJson($base, $conversationId, $accessToken, $sessionToken, $payload, $timeout);
        $d = json_decode($body, true);
        if ($status !== 200 || empty($d['success'])) {
            return ['status' => 'fail', 'message' => "HTTP {$status}: " . ($d['error'] ?? $d['message'] ?? 'no success'),
                    'detail' => $verbose ? substr($body, 0, 300) : null];
        }
        $att = $d['data']['attachments'][0] ?? null;
        if (!$att) return ['status' => 'fail', 'message' => 'no attachment returned'];
        if (!empty($att['path'])) $createdPaths[] = $att['path'];
        if (!empty($att['thumbnail'])) $createdPaths[] = $att['thumbnail'];
        if (($att['category'] ?? '') !== 'image') return ['status' => 'fail', 'message' => "category='" . ($att['category'] ?? '') . "' (want image)"];
        $abs = rtrim((string) $baseDir, '/') . $att['path'];
        if (!is_file($abs)) return ['status' => 'fail', 'message' => "success reported but file missing: {$abs}"];
        return ['status' => 'pass', 'message' => "stored {$att['path']} (" . filesize($abs) . " bytes)"];
    },
    'base64 data-URI prefix is stripped + stored' => function() use ($base, $conversationId, $accessToken, $sessionToken, $PNG_B64, $TEST_PREFIX, $baseDir, $timeout, &$createdPaths) {
        $name = $TEST_PREFIX . bin2hex(random_bytes(4)) . '.png';
        $payload = json_encode(['files' => [['name' => $name, 'type' => 'image/png', 'data' => 'data:image/png;base64,' . $PNG_B64]]]);
        [$status, , $body] = httpMultipartlessJson($base, $conversationId, $accessToken, $sessionToken, $payload, $timeout);
        $d = json_decode($body, true);
        if ($status !== 200 || empty($d['success'])) return ['status' => 'fail', 'message' => "HTTP {$status}: " . ($d['error'] ?? 'no success')];
        $att = $d['data']['attachments'][0] ?? null;
        if (!$att) return ['status' => 'fail', 'message' => 'no attachment returned'];
        if (!empty($att['path'])) $createdPaths[] = $att['path'];
        if (!empty($att['thumbnail'])) $createdPaths[] = $att['thumbnail'];
        $abs = rtrim((string) $baseDir, '/') . $att['path'];
        return is_file($abs) ? ['status' => 'pass', 'message' => 'data-URI decoded + stored']
                             : ['status' => 'fail', 'message' => "file missing: {$abs}"];
    },
    'empty files array -> 400 No files uploaded' => function() use ($base, $conversationId, $accessToken, $sessionToken, $timeout) {
        [$status, , $body] = httpMultipartlessJson($base, $conversationId, $accessToken, $sessionToken, json_encode(['files' => []]), $timeout);
        $d = json_decode($body, true);
        if ($status !== 400) return ['status' => 'fail', 'message' => "HTTP {$status} (want 400)"];
        $err = strtolower((string) ($d['error'] ?? ''));
        if (strpos($err, 'no files') === false) return ['status' => 'fail', 'message' => "error='" . ($d['error'] ?? '') . "'"];
        return ['status' => 'pass', 'message' => 'rejected with "No files uploaded"'];
    },
    'blocked .php extension is rejected' => function() use ($base, $conversationId, $accessToken, $sessionToken, $TEST_PREFIX, $timeout) {
        $name = $TEST_PREFIX . bin2hex(random_bytes(4)) . '.php';
        $payload = json_encode(['files' => [['name' => $name, 'type' => 'text/plain', 'data' => base64_encode('<?php echo 1; ?>')]]]);
        [$status, , $body] = httpMultipartlessJson($base, $conversationId, $accessToken, $sessionToken, $payload, $timeout);
        $d = json_decode($body, true);
        if ($status !== 400 || !empty($d['success'])) return ['status' => 'fail', 'message' => "HTTP {$status}, success=" . var_export($d['success'] ?? null, true)];
        return ['status' => 'pass', 'message' => 'rejected: ' . ($d['error'] ?? '')];
    },
];

if ($canUpload) {
    runGroup('base64', $only, $base64Tests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ----------- MULTIPART upload (web/desktop path -- no regression) -----------
$multipartTests = [
    'multipart upload stores attachment on disk' => function() use ($base, $conversationId, $accessToken, $sessionToken, $PNG_BYTES, $TEST_PREFIX, $baseDir, $timeout, $verbose, &$createdPaths) {
        $name = $TEST_PREFIX . bin2hex(random_bytes(4)) . '.png';
        $tmp = tempnam(sys_get_temp_dir(), 'fotest_');
        file_put_contents($tmp, $PNG_BYTES);
        try {
            $cfile = new \CURLFile($tmp, 'image/png', $name);
            $url = $base . "/api/chat/conversations/{$conversationId}/attachments";
            [$status, , $body] = httpMultipart($url, uploadHeaders($accessToken, $sessionToken), ['files[]' => $cfile], $timeout);
        } finally {
            @unlink($tmp);
        }
        $d = json_decode($body, true);
        if ($status !== 200 || empty($d['success'])) {
            return ['status' => 'fail', 'message' => "HTTP {$status}: " . ($d['error'] ?? 'no success'),
                    'detail' => $verbose ? substr($body, 0, 300) : null];
        }
        $att = $d['data']['attachments'][0] ?? null;
        if (!$att) return ['status' => 'fail', 'message' => 'no attachment returned'];
        if (!empty($att['path'])) $createdPaths[] = $att['path'];
        if (!empty($att['thumbnail'])) $createdPaths[] = $att['thumbnail'];
        $abs = rtrim((string) $baseDir, '/') . $att['path'];
        return is_file($abs) ? ['status' => 'pass', 'message' => "stored {$att['path']}"]
                             : ['status' => 'fail', 'message' => "file missing: {$abs}"];
    },
];

if ($canUpload) {
    runGroup('multipart', $only, $multipartTests, $logFile, $verbose, $jsonOut,
        $c_green, $c_red, $c_yellow, $c_dim, $c_reset,
        $totalTests, $passed, $failed, $warnings, $results);
}

// ---- cleanup: remove every uploaded test file + revoke the login session ----
$cleanup = function() use (&$createdPaths, &$accessToken, &$sessionToken, $base, $baseDir, $timeout, $logFile) {
    foreach ($createdPaths as $rel) {
        if (!is_string($rel) || $rel === '' || $baseDir === null) continue;
        $abs = rtrim((string) $baseDir, '/') . $rel;
        if (is_file($abs)) { @unlink($abs); logLine("Cleanup: removed {$abs}", $logFile); }
    }
    $createdPaths = [];
    if ($sessionToken) {
        try {
            httpRequest('POST', $base . '/api/auth/logout',
                ['Origin' => 'https://localhost', 'X-Session-Token' => $sessionToken], '{}', $timeout);
            logLine('Cleanup: revoked test login session', $logFile);
        } catch (\Throwable $e) {
            logLine('Cleanup error: ' . $e->getMessage(), $logFile);
        }
        $sessionToken = null; $accessToken = null;
    }
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
        'warnings' => $warnings, 'base' => $base, 'conversation_id' => $conversationId,
        'log_file' => $logFile, 'results' => $results,
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

/** POST a base64 JSON body to the conversation attachments endpoint. */
function httpMultipartlessJson(string $base, int $conversationId, ?string $accessToken, ?string $sessionToken, string $payload, int $timeout): array {
    $url = $base . "/api/chat/conversations/{$conversationId}/attachments";
    return httpRequest('POST', $url, uploadHeaders($accessToken, $sessionToken, ['Content-Type' => 'application/json']), $payload, $timeout);
}
