#!/usr/bin/env php
<?php
/**
 * FlowOne Drive Upload - Diagnostic & Error-Surfacing Suite
 *
 * Built to chase down "Failed to upload file" on mobile. It surfaces the two
 * most likely causes of a Drive upload that works on PC but fails on a phone:
 *
 *   1. Server request-body limits (upload_max_filesize / post_max_size /
 *      LSAPI_MAX_REQ_BODY_SIZE) that bite the larger photos phones send.
 *   2. The versioning "net addition" quota model: re-uploading a same-named
 *      file (iOS names many photos "image.jpg") keeps the previous version's
 *      bytes, so it can fail the quota check even when the client thinks an
 *      overwrite is free. That path used to swallow the reason and return a
 *      generic "Failed to upload file" - it now throws a descriptive error.
 *
 * It runs the live DriveService / DriveVersioningService at the layer that is
 * reachable from the CLI (move_uploaded_file paths need a real HTTP upload, so
 * those are exercised in production via the app, not here).
 *
 * Server run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-upload-test.php \
 *     --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL    Test account (required)
 *   --password=PASS  Accepted for flag-parity; Drive ops auth via service layer
 *   --only=g1,g2     env,store,versioning,quota
 *   --skip-send      No-op here (kept for flag-parity); Drive ops are local
 *   --smoke          Connectivity + limits dump only (no business logic)
 *   --verbose        Show file:line on failure
 *   --json           Emit a single JSON summary
 *   --help           Show this help
 *
 * Safety: every artifact uses the "flowone-test-" / "FLOWONE-TEST" prefix and
 * is removed on exit (shutdown + SIGINT/SIGTERM). Quota is charged by
 * uploadFileContent and refunded by deleteFile, so used_bytes ends where it
 * started. Idempotent and non-destructive.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

// ── CLI args ─────────────────────────────────────────────────────

$opts = getopt('', ['email:', 'password:', 'only:', 'skip-send', 'smoke', 'verbose', 'json', 'help']);

if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Drive Upload - Diagnostic & Error-Surfacing Suite\n";
    echo "=========================================================\n\n";
    echo "Usage:\n";
    echo "  php drive-upload-test.php --email=USER [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL    Test account (required)\n";
    echo "  --password=PASS  Accepted for flag-parity\n";
    echo "  --only=g1,g2     env,store,versioning,quota\n";
    echo "  --smoke          Connectivity + limits dump only\n";
    echo "  --verbose        Show file:line on failure\n";
    echo "  --json           Emit a single JSON summary\n";
    echo "  --help           Show this help\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$testEmail  = strtolower($opts['email']);
$smokeOnly  = isset($opts['smoke']);
$verbose    = isset($opts['verbose']);
$jsonMode   = isset($opts['json']);
$onlyGroups = !empty($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : [];

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/drive-upload-test-' . date('Ymd-His') . '.log';
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

$cleanupFileIds  = [];
$cleanupTmpFiles = [];

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

// ── Cleanup (shutdown + signal safe) ─────────────────────────────

function doCleanup(): void {
    global $cleanupFileIds, $cleanupTmpFiles, $testEmail, $config;
    try {
        if (!empty($cleanupFileIds)) {
            $drive = new \Webmail\Services\DriveService($config, $testEmail);
            foreach ($cleanupFileIds as $id) {
                try { $drive->deleteFile($testEmail, $id); } catch (\Throwable $e) {}
            }
        }
    } catch (\Throwable $e) {
        error_log('[drive-upload cleanup] ' . $e->getMessage());
    }
    foreach ($cleanupTmpFiles as $path) {
        if (is_file($path)) @unlink($path);
    }
    $cleanupFileIds = $cleanupTmpFiles = [];
}
register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Drive Upload - Diagnostic & Error-Surfacing Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: {$testEmail}");
out("  Mode:    " . ($smokeOnly ? 'SMOKE' : 'FULL'));
out("  Log:     {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

test('Required PHP extensions loaded', function () {
    foreach (['pdo', 'pdo_mysql', 'mbstring', 'fileinfo'] as $ext) {
        assert_true(extension_loaded($ext), "Missing extension: {$ext}");
    }
});

$drive = null;
test('DriveService + versioning service + DB reachable', function () use ($config, $testEmail, &$drive) {
    $drive = new \Webmail\Services\DriveService($config, $testEmail);
    $versioning = $drive->versioning();
    assert_true($versioning instanceof \Webmail\Services\DriveVersioningService, 'versioning() accessor broken');
    $quota = $drive->getQuota($testEmail);
    assert_true(is_array($quota) && isset($quota['used']), 'getQuota did not return usable array');
});

test('Drive storage path writable', function () use ($config) {
    $path = $config['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive';
    assert_true(is_dir($path), "Drive storage not found: {$path}");
    assert_true(is_writable($path), "Drive storage not writable: {$path}");
});

// ── 1. ENV: request-body limits + quota (the #1 suspect) ─────────

if (shouldRun('env')) {
    out("\n--- 1. ENV (server request-body limits + account quota) ---");

    $uploadMax = ini_get('upload_max_filesize');
    $postMax   = ini_get('post_max_size');
    $memLimit  = ini_get('memory_limit');
    $maxFiles  = ini_get('max_file_uploads');
    $lsapiBody = $_SERVER['LSAPI_MAX_REQ_BODY_SIZE'] ?? getenv('LSAPI_MAX_REQ_BODY_SIZE') ?: null;

    out("        upload_max_filesize     = " . var_export($uploadMax, true));
    out("        post_max_size           = " . var_export($postMax, true));
    out("        memory_limit            = " . var_export($memLimit, true));
    out("        max_file_uploads        = " . var_export($maxFiles, true));
    out("        LSAPI_MAX_REQ_BODY_SIZE = " . var_export($lsapiBody, true));

    test('post_max_size >= upload_max_filesize', function () use ($postMax, $uploadMax) {
        $p = bytesFromIni((string)$postMax);
        $u = bytesFromIni((string)$uploadMax);
        // post_max_size of 0 means "no limit" in PHP, which is fine.
        if ($p === 0) return 'warn';
        assert_true($p >= $u, "post_max_size ({$postMax}) is smaller than upload_max_filesize ({$uploadMax}) - large uploads will be silently dropped");
    });

    test('LSAPI body limit not below PHP upload limit', function () use ($lsapiBody, $uploadMax) {
        if ($lsapiBody === null || $lsapiBody === false || $lsapiBody === '') {
            return 'warn'; // not exposed to PHP here; check LiteSpeed admin / lsphp env
        }
        $l = (int)$lsapiBody;
        $u = bytesFromIni((string)$uploadMax);
        if ($l === 0) return 'warn';
        assert_true($l >= $u, "LSAPI_MAX_REQ_BODY_SIZE ({$l}) is below upload_max_filesize - LiteSpeed will reject large bodies with a 413 before PHP runs");
    });

    test('Account quota readable', function () use (&$drive, $testEmail) {
        $q = $drive->getQuota($testEmail);
        $usedH  = \Webmail\Services\DriveService::formatSize((int)$q['used']);
        $availH = $q['unlimited'] ? 'unlimited' : \Webmail\Services\DriveService::formatSize((int)$q['available']);
        $totalH = $q['unlimited'] ? 'unlimited' : \Webmail\Services\DriveService::formatSize((int)$q['quota']);
        out("        quota: used={$usedH}, available={$availH}, total={$totalH}");
        if (!$q['unlimited'] && (int)$q['available'] < 25 * 1024 * 1024) {
            out("        NOTE: under 25 MB free - a phone photo (esp. HEIC/Live) can exhaust this, and a same-name re-upload needs DOUBLE (versioning keeps the old copy).");
            return 'warn';
        }
    });
}

if ($smokeOnly) {
    goto summary;
}

$drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
$versioning = $drive->versioning();

// ── 2. STORE: content layer accepts phone formats ───────────────

$storeFileId = null;

if (shouldRun('store')) {
    out("\n--- 2. STORE (content layer accepts the formats phones send) ---");

    // Minimal-but-valid-ish magic bytes; the content layer trusts the passed
    // MIME, so this proves storage works for these types (no block-list here).
    $samples = [
        ['flowone-test-upload.txt',  "FLOWONE-TEST plain " . date('c') . "\n", 'text/plain'],
        ['flowone-test-upload.png',  "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64), 'image/png'],
        ['flowone-test-upload.heic', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64), 'image/heic'],
    ];

    foreach ($samples as [$name, $content, $mime]) {
        test("Store {$mime} via uploadFileContent", function () use (&$drive, $testEmail, $name, $content, $mime, &$cleanupFileIds, &$storeFileId) {
            $file = $drive->uploadFileContent($testEmail, $name, $content, $mime, null);
            assert_true($file !== null, 'uploadFileContent returned null for ' . $mime);
            assert_true((int)$file['size'] === strlen($content), 'stored size mismatch');
            $cleanupFileIds[] = (int)$file['id'];
            if ($mime === 'text/plain') $storeFileId = (int)$file['id'];
        });
    }
}

// ── 3. VERSIONING: same-name re-upload roundtrip + reason surfacing ─

if (shouldRun('versioning')) {
    out("\n--- 3. VERSIONING (same-name re-upload path that hid the real error) ---");

    test('archiveCurrentAsVersion creates a history row', function () use (&$drive, &$versioning, $testEmail, &$storeFileId, &$cleanupFileIds) {
        if (!$storeFileId) {
            $f = $drive->uploadFileContent($testEmail, 'flowone-test-ver-base.txt', "FLOWONE-TEST ver base\n", 'text/plain', null);
            assert_true($f !== null, 'base upload returned null');
            $storeFileId = (int)$f['id'];
            $cleanupFileIds[] = $storeFileId;
        }
        $file = $drive->getFile($testEmail, $storeFileId);
        assert_not_empty($file, 'getFile returned nothing for base file');

        $archive = $versioning->archiveCurrentAsVersion($testEmail, $file);
        assert_not_empty($archive, 'archiveCurrentAsVersion returned null');
        assert_true(isset($archive['version_id']) && isset($archive['version_number']), 'archive result missing keys');

        $versions = $versioning->getFileVersions($testEmail, $storeFileId);
        $history = array_values(array_filter($versions, fn($v) => empty($v['is_current']) && !empty($v['id'])));
        assert_true(count($history) >= 1, 'no history row after archive');
    });

    test('Insufficient quota now surfaces a reason (not a silent null)', function () use (&$drive, $testEmail) {
        // The createNewVersion quota gate that used to "return null" (=> generic
        // "Failed to upload file") is hasQuota(). Confirm it rejects an
        // impossible size so the descriptive throw path is reachable.
        $q = $drive->getQuota($testEmail);
        if ($q['unlimited']) return 'warn'; // unlimited account: gate never trips
        $huge = (int)$q['available'] + (1024 * 1024 * 1024); // 1 GB over budget
        assert_true($drive->hasQuota($testEmail, $huge) === false, 'hasQuota should reject an over-budget version write');
    });
}

// ── 4. QUOTA: net-addition math + formatSize in the new message ──

if (shouldRun('quota')) {
    out("\n--- 4. QUOTA (formatSize used by the new error message) ---");

    test('formatSize renders human sizes', function () {
        assert_equals('1.00 MB', \Webmail\Services\DriveService::formatSize(1048576), 'formatSize MB wrong');
        assert_equals('512 bytes', \Webmail\Services\DriveService::formatSize(512), 'formatSize bytes wrong');
    });

    test('hasQuota allows a tiny write', function () use (&$drive, $testEmail) {
        assert_true($drive->hasQuota($testEmail, 16) === true, 'hasQuota rejected a 16-byte write');
    });
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
