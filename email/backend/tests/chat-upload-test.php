#!/usr/bin/env php
<?php
/**
 * FlowOne Chat Upload - Diagnostic & Repro Suite
 *
 * Built to chase down "image upload spins forever then errors" on the iOS chat
 * app (and the same symptom in email/Drive). It localizes the most likely
 * SERVER-side causes of an attachment POST that works on PC but stalls/fails on
 * a phone, WITHOUT needing the browser:
 *
 *   1. Request-body limits (post_max_size / upload_max_filesize /
 *      LSAPI_MAX_REQ_BODY_SIZE / OpenLiteSpeed "Max Request Body Size"). A low
 *      value 413s the larger photos phones send BEFORE PHP runs.
 *   2. A WAF (ModSec / CPGuard) returning an HTML block page for the upload URL
 *      (the client maps a non-JSON/HTML response to "File too large").
 *   3. Storage stalls: the resolved attachments dir (NAS-first, local fallback
 *      via ChatService::getChatAttachmentsBaseDir) being unwritable, full, or a
 *      hung NAS mount that makes move_uploaded_file block.
 *
 * Because attachment intake uses is_uploaded_file()/move_uploaded_file(), the
 * actual intake can only be exercised over real HTTP. So this suite combines:
 *   - CLI checks of the env + the live storage backend (write/read/delete), and
 *   - an over-the-wire probe that POSTs a realistically-sized multipart body to
 *     the real endpoint and classifies the response (413 / WAF-HTML / timeout /
 *     401-JSON). The unauthenticated probe needs no secrets: a healthy pipeline
 *     returns 401 JSON (body reached PHP), a broken one returns 413/HTML/stall.
 *   - an OPTIONAL authenticated end-to-end upload when --token is supplied, with
 *     full cleanup of the bytes it writes.
 *
 * Server run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/chat-upload-test.php \
 *     --email=user@flowone.pro --verbose
 *
 * Reproduce the body-size limit at a chosen size (e.g. a 12 MB phone photo):
 *   php chat-upload-test.php --email=user@flowone.pro --size=12 --only=http
 *
 * Full authenticated roundtrip (bytes are deleted afterwards):
 *   php chat-upload-test.php --email=user@flowone.pro --token=SESSION_TOKEN \
 *     --base-url=https://flowone.pro
 *
 * Options:
 *   --email=EMAIL      Test account (required)
 *   --password=PASS    Accepted for flag-parity (chat ops auth via token/service)
 *   --token=TOKEN      Session/bearer token to run the authenticated upload
 *   --base-url=URL     Origin for the HTTP probe (default: FRONTEND_URL/flowone.pro)
 *   --conversation=ID  Conversation id for the authenticated upload (auto-resolved if omitted)
 *   --size=MB          Probe body size in MB (default 3)
 *   --only=g1,g2       env,storage,http
 *   --skip-send        Skip all over-the-wire POSTs (no HTTP probe/upload)
 *   --smoke            Connectivity + limits dump only (no storage write / HTTP)
 *   --verbose          Show file:line on failure
 *   --json             Emit a single JSON summary
 *   --help             Show this help
 *
 * Safety: every artifact uses the "flowone-test-" / "[FLOWONE-TEST]" prefix and
 * is removed on exit (shutdown + SIGINT/SIGTERM). The unauthenticated probe is
 * rejected with 401 and writes nothing. The authenticated upload's stored bytes
 * are unlinked in cleanup. Idempotent and non-destructive.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

// ── CLI args ─────────────────────────────────────────────────────

$opts = getopt('', [
    'email:', 'password:', 'token:', 'base-url:', 'conversation:', 'size:',
    'only:', 'skip-send', 'smoke', 'verbose', 'json', 'help',
]);

if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Chat Upload - Diagnostic & Repro Suite\n";
    echo "=============================================\n\n";
    echo "Usage:\n";
    echo "  php chat-upload-test.php --email=USER [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL      Test account (required)\n";
    echo "  --password=PASS    Accepted for flag-parity\n";
    echo "  --token=TOKEN      Session/bearer token for the authenticated upload\n";
    echo "  --base-url=URL     Origin for the HTTP probe (default: FRONTEND_URL/flowone.pro)\n";
    echo "  --conversation=ID  Conversation id for the authenticated upload (auto-resolved)\n";
    echo "  --size=MB          Probe body size in MB (default 3)\n";
    echo "  --only=g1,g2       env,storage,http\n";
    echo "  --skip-send        Skip all over-the-wire POSTs\n";
    echo "  --smoke            Connectivity + limits dump only\n";
    echo "  --verbose          Show file:line on failure\n";
    echo "  --json             Emit a single JSON summary\n";
    echo "  --help             Show this help\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$testEmail  = strtolower($opts['email']);
$token      = $opts['token'] ?? null;
$convArg    = isset($opts['conversation']) ? (int)$opts['conversation'] : null;
$probeMb    = isset($opts['size']) ? max(1, (int)$opts['size']) : 3;
$skipSend   = isset($opts['skip-send']);
$smokeOnly  = isset($opts['smoke']);
$verbose    = isset($opts['verbose']);
$jsonMode   = isset($opts['json']);
$onlyGroups = !empty($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : [];

$baseUrl = $opts['base-url']
    ?? (getenv('FRONTEND_URL') ?: ($config['frontend_url'] ?? ''))
    ?: 'https://flowone.pro';
$baseUrl = rtrim($baseUrl, '/');

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/chat-upload-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

$RED    = $jsonMode ? '' : "\033[0;31m";
$GREEN  = $jsonMode ? '' : "\033[0;32m";
$YELLOW = $jsonMode ? '' : "\033[1;33m";
$NC     = $jsonMode ? '' : "\033[0m";

$DEFAULT_TIMEOUT = 30;

$cleanupTmpFiles  = []; // local temp files this script created
$cleanupDiskPaths = []; // absolute paths written by the authenticated upload
$cleanupDirs      = []; // dirs to rmdir if empty

function out(string $msg): void {
    global $logFile, $jsonMode;
    if (!$jsonMode) echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . preg_replace('/\033\[[0-9;]*m/', '', $msg) . "\n", FILE_APPEND | LOCK_EX);
}

function shouldRun(string $group): bool {
    global $onlyGroups;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

function test(string $name, callable $fn, ?int $timeoutSec = null): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    global $GREEN, $RED, $YELLOW, $NC, $DEFAULT_TIMEOUT;

    $totalTests++;
    $timeout = $timeoutSec ?? $DEFAULT_TIMEOUT;
    $start = microtime(true);

    $alarmAvailable = function_exists('pcntl_alarm') && function_exists('pcntl_signal');
    if ($alarmAvailable) {
        pcntl_signal(SIGALRM, function () use ($name) {
            throw new \RuntimeException("test timed out: {$name}");
        });
        pcntl_alarm($timeout);
    } else {
        @set_time_limit($timeout);
    }

    try {
        $result = $fn();
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        if ($result === 'warn' || $result === 'skip') {
            $warnings++;
            out("  {$YELLOW}[WARN]{$NC}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  {$GREEN}[PASS]{$NC}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        $failed++;
        out("  {$RED}[FAIL]{$NC}  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    } finally {
        if ($alarmAvailable) pcntl_alarm(0);
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new \RuntimeException($msg);
}
function assert_not_empty($value, string $msg = 'Value is empty'): void {
    if (empty($value)) throw new \RuntimeException($msg);
}
function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $detail = $msg ?: ('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        throw new \RuntimeException($detail);
    }
}

/** Parse a PHP shorthand byte size (e.g. "256M", "2G", "8388608") to bytes. */
function bytesFromIni(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $unit = strtolower($val[strlen($val) - 1]);
    $num  = (int)$val;
    switch ($unit) {
        case 'g': return $num * 1024 * 1024 * 1024;
        case 'm': return $num * 1024 * 1024;
        case 'k': return $num * 1024;
        default:  return (int)$val;
    }
}

/** Build a temp file of N bytes (mostly a valid PNG header + padding). */
function makeProbeFile(int $bytes): string {
    global $cleanupTmpFiles;
    $path = sys_get_temp_dir() . '/flowone-test-chat-' . getmypid() . '-' . uniqid() . '.png';
    $fh = fopen($path, 'wb');
    // Minimal PNG signature so finfo/getimagesize see an image-ish file.
    fwrite($fh, "\x89PNG\r\n\x1a\n");
    $written = 8;
    $chunk = str_repeat("\x00", 65536);
    while ($written < $bytes) {
        $n = min(strlen($chunk), $bytes - $written);
        fwrite($fh, substr($chunk, 0, $n));
        $written += $n;
    }
    fclose($fh);
    $cleanupTmpFiles[] = $path;
    return $path;
}

/** Low-level multipart POST. Returns [httpCode, contentType, body, curlErr, totalSec]. */
function httpUpload(string $url, string $filePath, array $headers = [], int $timeout = 60): array {
    $ch = curl_init($url);
    $post = ['files[]' => new \CURLFile($filePath, 'image/png', 'flowone-test-upload.png')];
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false, // loopback / self-call tolerance
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $total = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, strtolower($ctype), (string)$body, $err, $total];
}

// ── Cleanup (shutdown + signal safe) ─────────────────────────────

function doCleanup(): void {
    global $cleanupTmpFiles, $cleanupDiskPaths, $cleanupDirs;
    foreach ($cleanupDiskPaths as $path) {
        if (is_file($path)) @unlink($path);
    }
    foreach ($cleanupDirs as $dir) {
        if (is_dir($dir)) @rmdir($dir); // only succeeds if empty — safe
    }
    foreach ($cleanupTmpFiles as $path) {
        if (is_file($path)) @unlink($path);
    }
    $cleanupTmpFiles = $cleanupDiskPaths = $cleanupDirs = [];
}
register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Chat Upload - Diagnostic & Repro Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account:  {$testEmail}");
out("  Base URL: {$baseUrl}");
out("  Probe:    {$probeMb} MB");
out("  Mode:     " . ($smokeOnly ? 'SMOKE' : 'FULL'));
out("  Log:      {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

test('Required PHP extensions loaded', function () {
    foreach (['pdo', 'pdo_mysql', 'mbstring', 'fileinfo', 'curl'] as $ext) {
        assert_true(extension_loaded($ext), "Missing extension: {$ext}");
    }
});

$chat = null;
$colleague = null;
test('ChatService + DB reachable + account exists', function () use ($config, $testEmail, &$chat, &$colleague) {
    $chat = new \Webmail\Addons\Chat\Services\ChatService($config);
    $colleague = $chat->getColleagueByEmail($testEmail);
    assert_not_empty($colleague, "No colleague row for {$testEmail} (is the account a chat user?)");
    assert_true(isset($colleague['id']), 'colleague row missing id');
});

// ── 1. ENV: request-body limits + disk (the #1 suspect) ──────────

if (shouldRun('env')) {
    out("\n--- 1. ENV (server request-body limits + disk) ---");

    $uploadMax = ini_get('upload_max_filesize');
    $postMax   = ini_get('post_max_size');
    $memLimit  = ini_get('memory_limit');
    $maxFiles  = ini_get('max_file_uploads');
    $maxInput  = ini_get('max_input_time');
    $lsapiBody = $_SERVER['LSAPI_MAX_REQ_BODY_SIZE'] ?? getenv('LSAPI_MAX_REQ_BODY_SIZE') ?: null;

    out("        upload_max_filesize     = " . var_export($uploadMax, true));
    out("        post_max_size           = " . var_export($postMax, true));
    out("        memory_limit            = " . var_export($memLimit, true));
    out("        max_file_uploads        = " . var_export($maxFiles, true));
    out("        max_input_time          = " . var_export($maxInput, true));
    out("        LSAPI_MAX_REQ_BODY_SIZE = " . var_export($lsapiBody, true));
    out("        NOTE: these are the CLI lsphp values. The web SAPI may differ —");
    out("        the over-the-wire probe (group 'http') tests the REAL limits.");

    test('post_max_size >= upload_max_filesize', function () use ($postMax, $uploadMax) {
        $p = bytesFromIni((string)$postMax);
        $u = bytesFromIni((string)$uploadMax);
        if ($p === 0) return 'warn'; // 0 = unlimited
        assert_true($p >= $u, "post_max_size ({$postMax}) < upload_max_filesize ({$uploadMax}) - large uploads silently dropped");
    });

    test('upload_max_filesize comfortably above a phone photo', function () use ($uploadMax) {
        $u = bytesFromIni((string)$uploadMax);
        // Modern iPhone HEIC/Live photos routinely hit 5-12 MB.
        if ($u > 0 && $u < 12 * 1024 * 1024) {
            out("        upload_max_filesize is {$uploadMax} - some phone photos exceed this.");
            return 'warn';
        }
    });

    test('LSAPI body limit not below PHP upload limit', function () use ($lsapiBody, $uploadMax) {
        if ($lsapiBody === null || $lsapiBody === false || $lsapiBody === '') {
            return 'warn'; // not exposed to CLI PHP; verify in LiteSpeed admin
        }
        $l = (int)$lsapiBody;
        $u = bytesFromIni((string)$uploadMax);
        if ($l === 0) return 'warn';
        assert_true($l >= $u, "LSAPI_MAX_REQ_BODY_SIZE ({$l}) < upload_max_filesize - LiteSpeed 413s before PHP runs");
    });

    test('Disk space on storage path', function () use ($config) {
        $path = $config['storage_path'] ?? '/var/www/vps-email/storage';
        if (!is_dir($path)) { out("        storage path missing: {$path}"); return 'warn'; }
        $free = @disk_free_space($path);
        if ($free === false) return 'warn';
        $freeH = round($free / 1048576, 1);
        out("        free on {$path}: {$freeH} MB");
        assert_true($free > 50 * 1024 * 1024, "Low disk on {$path}: {$freeH} MB free");
    });
}

if ($smokeOnly) {
    goto summary;
}

$chat = $chat ?? new \Webmail\Addons\Chat\Services\ChatService($config);

// ── 2. STORAGE: the resolved attachments backend is writable ─────

if (shouldRun('storage')) {
    out("\n--- 2. STORAGE (NAS-first resolved dir actually accepts bytes) ---");

    test('NAS health status', function () {
        $ok = \Webmail\Services\NasHealthCheck::isAvailable();
        out("        NasHealthCheck::isAvailable() = " . ($ok ? 'true' : 'false'));
        if (!$ok) {
            out("        NAS unavailable -> ChatService falls back to LOCAL storage.");
            return 'warn';
        }
    });

    $baseDir = null;
    test('Resolved chat attachments base dir is writable', function () use (&$chat, $testEmail, &$baseDir) {
        $baseDir = $chat->getChatAttachmentsBaseDir($testEmail);
        out("        base dir = {$baseDir}");
        assert_true(is_dir($baseDir), "Resolved base dir is not a directory: {$baseDir}");
        assert_true(is_writable($baseDir), "Resolved base dir is not writable: {$baseDir}");
    });

    test('Write + read + delete a file in chat_attachments (real backend)', function () use (&$baseDir, &$cleanupDiskPaths, &$cleanupDirs) {
        if (!$baseDir) throw new \RuntimeException('base dir not resolved (previous test failed)');
        $dir = $baseDir . '/chat_attachments/flowone-test-' . getmypid();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Could not create test dir: {$dir}");
        }
        $cleanupDirs[] = $dir;
        $cleanupDirs[] = $baseDir . '/chat_attachments'; // only rmdir'd if it ends up empty
        $file = $dir . '/flowone-test-upload.png';
        $cleanupDiskPaths[] = $file;

        $payload = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 4096);
        $bytes = @file_put_contents($file, $payload);
        assert_true($bytes === strlen($payload), "Write to backend failed (wrote " . var_export($bytes, true) . ")");

        $readBack = @file_get_contents($file);
        assert_true($readBack === $payload, 'Read-back mismatch — storage backend may be flaky/NAS-stalled');

        assert_true(@unlink($file), 'Could not delete the test file (permissions?)');
        // drop from cleanup list since already removed
        $cleanupDiskPaths = array_values(array_filter($cleanupDiskPaths, fn($p) => $p !== $file));
    }, 20);
}

// ── 3. HTTP: over-the-wire repro of the real upload pipeline ─────

if (shouldRun('http') && !$skipSend) {
    out("\n--- 3. HTTP (real endpoint: OLS + LSAPI + ModSec + PHP) ---");

    $endpoint = $baseUrl . '/api/chat/conversations/1/attachments';

    test("Unauthenticated {$probeMb} MB body probe classifies the pipeline", function () use ($endpoint, $probeMb) {
        $file = makeProbeFile($probeMb * 1024 * 1024);
        [$code, $ctype, $body, $err, $secs] = httpUpload($endpoint, $file, [], 60);
        out("        POST {$endpoint}");
        out("        -> HTTP {$code}, content-type='{$ctype}', {$secs}s" . ($err ? ", curlErr='{$err}'" : ''));

        if ($err !== '') {
            // Connection refused/timeout/DNS — stall is exactly the user's symptom.
            throw new \RuntimeException("Request did not complete ({$err}) - this is the stall the app sees. Check OLS up, NAS mount, processing time.");
        }
        if ($code === 413) {
            throw new \RuntimeException("Server returned 413 at {$probeMb} MB - request-body limit too low (OLS Max Request Body Size / LSAPI / post_max_size).");
        }
        if (strpos($ctype, 'text/html') !== false) {
            throw new \RuntimeException("Server returned an HTML page (likely ModSec/CPGuard WAF block) - the client maps this to 'File too large'.");
        }
        if ($code === 401 || $code === 403) {
            // Body reached PHP and auth correctly rejected it: transport is HEALTHY.
            out("        Body reached PHP and auth rejected it (expected without --token). Transport/limits OK at {$probeMb} MB.");
            return;
        }
        if ($code >= 500) {
            throw new \RuntimeException("Server error HTTP {$code} - check php_errors.log. Body: " . substr($body, 0, 300));
        }
        out("        Unexpected HTTP {$code} (no auth). Body: " . substr($body, 0, 200));
        return 'warn';
    }, 75);

    // OPTIONAL: full authenticated roundtrip when a token is provided.
    test('Authenticated end-to-end upload + cleanup', function () use (
        $chat, $colleague, $token, $convArg, $baseUrl, $testEmail, $probeMb, &$cleanupDiskPaths
    ) {
        if (!$token) {
            out("        no --token supplied - skipping the authenticated roundtrip.");
            return 'skip';
        }

        // Resolve a conversation the user participates in.
        $convId = $convArg;
        if (!$convId) {
            $db = $chat->getDb();
            $stmt = $db->prepare('SELECT conversation_id FROM chat_participants WHERE colleague_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY conversation_id DESC LIMIT 1');
            $stmt->execute([(int)$colleague['id']]);
            $convId = (int)($stmt->fetchColumn() ?: 0);
        }
        if (!$convId) {
            out("        could not resolve a conversation for {$testEmail} - pass --conversation=ID.");
            return 'warn';
        }

        $endpoint = $baseUrl . '/api/chat/conversations/' . $convId . '/attachments';
        $file = makeProbeFile(min($probeMb, 2) * 1024 * 1024); // keep the real write small
        $headers = [
            'Authorization: Bearer ' . $token,
            'X-Session-Token: ' . $token,
        ];
        [$code, $ctype, $body, $err, $secs] = httpUpload($endpoint, $file, $headers, 90);
        out("        POST {$endpoint} -> HTTP {$code}, {$secs}s" . ($err ? ", curlErr='{$err}'" : ''));

        if ($err !== '') throw new \RuntimeException("Upload stalled/failed: {$err}");
        if ($code === 413) throw new \RuntimeException('413 - body-size limit (see env/probe).');
        if (strpos($ctype, 'text/html') !== false) throw new \RuntimeException('HTML response - WAF block.');

        $data = json_decode($body, true);
        if ($code === 401 || $code === 403) {
            out("        token rejected (HTTP {$code}) - pass a fresh --token.");
            return 'warn';
        }
        assert_true($code === 200, "Expected 200, got {$code}: " . substr($body, 0, 300));
        assert_true(is_array($data) && !empty($data['success']), 'Response not success: ' . substr($body, 0, 300));
        $atts = $data['data']['attachments'] ?? $data['attachments'] ?? [];
        assert_true(!empty($atts), 'No attachments returned despite success');

        // Clean up the bytes we just wrote (this endpoint stores files but no DB row).
        $baseDir = $chat->getChatAttachmentsBaseDir($testEmail);
        foreach ($atts as $att) {
            foreach (['path', 'thumbnail'] as $k) {
                if (!empty($att[$k])) {
                    $abs = $baseDir . $att[$k];
                    if (is_file($abs)) { @unlink($abs); }
                }
            }
        }
        out("        uploaded " . count($atts) . " attachment(s) and removed the stored bytes.");
    }, 100);
}

// ══════════════════════════════════════════════════════════════════

summary:

out("\n=================================================================");
out("  SUMMARY");
out("=================================================================");
out("  Total:    {$totalTests}");
out("  {$GREEN}Passed:   {$passed}{$NC}");
out("  {$YELLOW}Warnings: {$warnings}{$NC}");
out("  {$RED}Failed:   {$failed}{$NC}");

if ($failed > 0) {
    out("\n  Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    - {$r['name']}: " . ($r['error'] ?? 'unknown'));
        }
    }
}

if ($jsonMode) {
    echo json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'warnings' => $warnings,
        'failed'   => $failed,
        'results'  => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
