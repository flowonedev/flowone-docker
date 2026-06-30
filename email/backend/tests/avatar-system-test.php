#!/usr/bin/env php
<?php
/**
 * FlowOne Avatar System - Comprehensive Test Suite
 *
 * Diagnoses and verifies the colleague avatar upload pipeline
 * (POST /api/colleagues/me/avatar -> ColleagueController::uploadAvatar
 *  -> detectImageMime() -> resizeAvatar() -> storage/avatars -> DB).
 *
 * This was written to pin down a 500 Internal Server Error on avatar
 * upload. The most common cause is GD being compiled without WebP
 * support: calling imagecreatefromwebp()/imagewebp() when they are not
 * defined throws a fatal Error (the @ operator does NOT suppress it),
 * which surfaces as a generic 500. The suite reports exactly which GD
 * formats are available and exercises the real (now hardened) code path
 * via reflection so a regression is caught on the server.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/avatar-system-test.php \
 *       --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --only=GROUPS        Comma-separated: env,mime,resize,db
 *   --smoke              Quick health check (pre-flight + GD/format report only)
 *   --skip-send          No-op (no external/destructive operations exist here)
 *   --json               Output results as JSON (for automation/monitoring)
 *   --verbose            Show extra debug info (stack traces, raw values)
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'only:', 'smoke', 'skip-send', 'json', 'fix-perms', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Avatar System Test Suite\n";
    echo "=================================\n\n";
    echo "Usage:\n";
    echo "  php avatar-system-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --only=GROUPS        Comma-separated: env,mime,resize,db\n";
    echo "  --smoke              Quick health check (pre-flight + GD/format report only)\n";
    echo "  --skip-send          No-op (no external/destructive operations exist here)\n";
    echo "  --json               Output results as JSON\n";
    echo "  --fix-perms          If run as root, chown storage/avatars to the web user (nobody:nogroup)\n";
    echo "  --verbose            Show extra debug info\n";
    echo "  --help               Show this help\n\n";
    exit(1);
}

$testEmail  = strtolower($opts['email']);
$verbose    = isset($opts['verbose']);
$smokeOnly  = isset($opts['smoke']);
$jsonMode   = isset($opts['json']);
$fixPerms   = isset($opts['fix-perms']);
$onlyGroups = isset($opts['only']) ? explode(',', $opts['only']) : [];

// Web worker identity (matches SystemController expectations on OpenLiteSpeed/lsphp)
const WEB_USER  = 'nobody';
const WEB_GROUP = 'nogroup';

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return $group === 'env';
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/avatar-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function out(string $msg): void {
    global $logFile, $jsonMode;
    $line = $msg . "\n";
    if (!$jsonMode) echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    // Per-test timeout so one hanging call cannot block the suite
    @set_time_limit(30);
    $start = microtime(true);
    try {
        $result = $fn();
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  \033[32m[PASS]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new \RuntimeException($msg);
}
function assert_false(bool $cond, string $msg = 'Expected false'): void {
    if ($cond) throw new \RuntimeException($msg);
}
function assert_equals($exp, $act, string $msg = ''): void {
    if ($exp !== $act) {
        $label = $msg ?: 'Values differ';
        throw new \RuntimeException("$label: expected " . var_export($exp, true) . ", got " . var_export($act, true));
    }
}
function vlog(string $msg): void {
    global $verbose;
    if ($verbose) out("          [v] $msg");
}

// ── Cleanup tracking ─────────────────────────────────────────────

$TEST_PREFIX = 'flowone_test_';
$runId       = date('His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

$AVATAR_DIR  = realpath(__DIR__ . '/../storage') ? realpath(__DIR__ . '/../storage') . '/avatars'
                                                 : __DIR__ . '/../storage/avatars';

$tempFiles            = [];   // absolute paths to remove on cleanup
$originalAvatarPath   = false; // sentinel: false = nothing to restore
$testColleagueId      = null;

function doCleanup(): void {
    global $config, $testEmail, $tempFiles, $originalAvatarPath, $testColleagueId, $AVATAR_DIR, $TEST_PREFIX;

    out("\n--- CLEANUP ---");

    // Remove any temp files we created
    foreach ($tempFiles as $f) {
        if (is_string($f) && $f !== '' && file_exists($f)) {
            @unlink($f);
        }
    }

    // Belt-and-braces: remove any stray test-prefixed files in the avatar dir
    if (is_dir($AVATAR_DIR)) {
        foreach (glob($AVATAR_DIR . '/' . $TEST_PREFIX . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    // Restore the colleague's original avatar_path so we never corrupt real data
    if ($testColleagueId !== null && $originalAvatarPath !== false) {
        try {
            $db = \Webmail\Core\Database::getConnection($config);
            $stmt = $db->prepare('UPDATE organization_colleagues SET avatar_path = ? WHERE id = ?');
            $stmt->execute([$originalAvatarPath, $testColleagueId]);
            out("  Restored original avatar_path for colleague #{$testColleagueId}.");
        } catch (\Throwable $e) {
            out("  WARN: could not restore avatar_path: " . $e->getMessage());
        }
    }

    out("  Cleanup complete.");
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { exit(130); });
    pcntl_signal(SIGTERM, function () { exit(143); });
}

// ── Image synthesis helpers ──────────────────────────────────────

/**
 * Create a test image of the given mime at width x height using GD.
 * Returns the file path, or null if GD cannot encode that format.
 */
function makeTestImage(string $mime, int $w, int $h, string $path): ?string {
    if (!extension_loaded('gd')) return null;

    $img = @imagecreatetruecolor($w, $h);
    if (!$img) return null;
    // Fill with a recognizable colour + a diagonal so resampling has content
    $bg = imagecolorallocate($img, 40, 80, 160);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    $fg = imagecolorallocate($img, 240, 200, 60);
    imageline($img, 0, 0, $w, $h, $fg);

    $ok = false;
    switch ($mime) {
        case 'image/jpeg':
            $ok = function_exists('imagejpeg') && @imagejpeg($img, $path, 90);
            break;
        case 'image/png':
            $ok = function_exists('imagepng') && @imagepng($img, $path, 6);
            break;
        case 'image/gif':
            $ok = function_exists('imagegif') && @imagegif($img, $path);
            break;
        case 'image/webp':
            $ok = function_exists('imagewebp') && @imagewebp($img, $path, 90);
            break;
    }
    imagedestroy($img);

    return $ok ? $path : null;
}

function gdSupports(string $mime): bool {
    return match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
        'image/png'  => function_exists('imagecreatefrompng') && function_exists('imagepng'),
        'image/gif'  => function_exists('imagecreatefromgif') && function_exists('imagegif'),
        'image/webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
        default      => false,
    };
}

// ── Directory ownership / permission helpers ─────────────────────

/** Resolve owner name, group name and octal perms of a directory. */
function dirOwnership(string $dir): array {
    $stat  = @stat($dir);
    $owner = $group = null;
    if ($stat && function_exists('posix_getpwuid')) {
        $o = @posix_getpwuid($stat['uid']);
        $g = @posix_getgrgid($stat['gid']);
        $owner = $o['name'] ?? (string) $stat['uid'];
        $group = $g['name'] ?? (string) $stat['gid'];
    }
    return [
        'owner' => $owner,
        'group' => $group,
        'mode'  => $stat ? ($stat['mode'] & 0777) : null,
        'perms' => $stat ? sprintf('%04o', $stat['mode'] & 0777) : null,
    ];
}

/**
 * Would the web worker (WEB_USER:WEB_GROUP) be able to write to this dir?
 * Returns true/false, or null when ownership cannot be determined (no posix).
 * Note: this is what we must check instead of is_writable(), because a
 * root-run test sees every directory as writable and would miss the bug.
 */
function webWorkerCanWrite(array $info): ?bool {
    if ($info['owner'] === null || $info['mode'] === null) return null;
    $mode = $info['mode'];
    if ($info['owner'] === WEB_USER  && ($mode & 0200)) return true; // owner write
    if ($info['group'] === WEB_GROUP && ($mode & 0020)) return true; // group write
    if ($mode & 0002) return true;                                   // world write
    return false;
}

// ── Reflection handles for the (private) controller methods ──────

$controller   = null;
$refDetect    = null;
$refResize    = null;

function getController(): \Webmail\Addons\Team\Controllers\ColleagueController {
    global $controller, $config;
    if ($controller === null) {
        $controller = new \Webmail\Addons\Team\Controllers\ColleagueController($config);
    }
    return $controller;
}

function callDetectMime(string $path): ?string {
    global $refDetect;
    if ($refDetect === null) {
        $refDetect = new \ReflectionMethod(\Webmail\Addons\Team\Controllers\ColleagueController::class, 'detectImageMime');
        $refDetect->setAccessible(true);
    }
    return $refDetect->invoke(getController(), $path);
}

function callResize(string $src, string $dest, string $mime, int $max): bool {
    global $refResize;
    if ($refResize === null) {
        $refResize = new \ReflectionMethod(\Webmail\Addons\Team\Controllers\ColleagueController::class, 'resizeAvatar');
        $refResize->setAccessible(true);
    }
    return (bool) $refResize->invoke(getController(), $src, $dest, $mime, $max);
}

// ── Banner ───────────────────────────────────────────────────────

out("=============================================================");
out("  FlowOne Avatar System Test Suite");
out("  Account : $testEmail");
out("  Run ID  : $runId");
out("  Mode    : " . ($smokeOnly ? 'SMOKE' : (empty($onlyGroups) ? 'FULL' : 'Groups: ' . implode(', ', $onlyGroups))));
out("=============================================================\n");

// ══════════════════════════════════════════════════════════════════
// PRE-FLIGHT CHECKS (abort early if the environment is broken)
// ══════════════════════════════════════════════════════════════════

out("=== Pre-flight checks ===");

$preflightOk = true;

// DB connectivity (critical)
test('Database reachable', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $row = $db->query('SELECT 1 AS ok')->fetch();
    assert_equals(1, (int)$row['ok'], 'DB ping');
});
try {
    \Webmail\Core\Database::getConnection($config)->query('SELECT 1');
} catch (\Throwable $e) {
    $preflightOk = false;
}

// organization_colleagues.avatar_path column (critical)
test('organization_colleagues.avatar_path column exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $col = $db->query("SHOW COLUMNS FROM organization_colleagues LIKE 'avatar_path'")->fetch();
    assert_true(!empty($col), 'avatar_path column missing');
});

// GD presence (not fatal: the hardened code falls back to storing the original)
test('GD extension loaded', function () {
    if (!extension_loaded('gd')) {
        return 'warn';
    }
    return true;
});

// GD per-format support report (the heart of the WebP 500 bug)
foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $fmt) {
    test("GD supports $fmt", function () use ($fmt) {
        if (!extension_loaded('gd')) return 'warn';
        if (!gdSupports($fmt)) {
            // WebP missing is the prime 500 suspect; surface it loudly but as a
            // warning since the hardened controller now handles it gracefully.
            return 'warn';
        }
        return true;
    });
}

// fileinfo / MIME detection availability
test('MIME detection available (finfo or getimagesize)', function () {
    $ok = (class_exists('finfo') && defined('FILEINFO_MIME_TYPE'))
        || function_exists('getimagesize')
        || function_exists('mime_content_type');
    assert_true($ok, 'No MIME detection mechanism available');
    if (!class_exists('finfo')) return 'warn';
    return true;
});

// storage/avatars writable BY THE WEB WORKER (critical for real uploads).
// We check ownership, not just is_writable(), because a root-run test sees
// every dir as writable and would mask the real "nobody can't write" failure.
test('storage/avatars writable by web worker (' . WEB_USER . ':' . WEB_GROUP . ')', function () use ($AVATAR_DIR, $fixPerms) {
    if (!is_dir($AVATAR_DIR) && !@mkdir($AVATAR_DIR, 0755, true) && !is_dir($AVATAR_DIR)) {
        throw new \RuntimeException("Cannot create avatar dir: $AVATAR_DIR");
    }

    $info = dirOwnership($AVATAR_DIR);
    vlog("avatar dir owner={$info['owner']} group={$info['group']} perms={$info['perms']}");

    $fixCmd = "sudo chown -R " . WEB_USER . ":" . WEB_GROUP . " {$AVATAR_DIR} && sudo chmod 0755 {$AVATAR_DIR}";
    $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    $canWrite = webWorkerCanWrite($info);

    // Opt-in self-repair when explicitly requested and we have the privilege.
    if ($canWrite === false && $fixPerms && $isRoot && function_exists('chown')) {
        @chown($AVATAR_DIR, WEB_USER);
        @chgrp($AVATAR_DIR, WEB_GROUP);
        @chmod($AVATAR_DIR, 0755);
        $info = dirOwnership($AVATAR_DIR);
        $canWrite = webWorkerCanWrite($info);
        out("          [fix] chowned avatar dir -> {$info['owner']}:{$info['group']} {$info['perms']}");
    }

    if ($canWrite === null) {
        // No posix extension: fall back to a best-effort writability probe.
        assert_true(is_writable($AVATAR_DIR), "Avatar dir not writable: $AVATAR_DIR");
        vlog('posix unavailable; could not verify web-worker ownership');
        return 'warn';
    }

    if (!$canWrite) {
        throw new \RuntimeException(
            "Avatar dir not writable by " . WEB_USER . ":" . WEB_GROUP
            . " (current owner={$info['owner']}:{$info['group']} perms={$info['perms']}). "
            . "Fix on server: {$fixCmd}  (or re-run this test as root with --fix-perms)"
        );
    }
});

// Temp dir + disk space sanity
test('Temp dir writable with free space', function () {
    $tmp = sys_get_temp_dir();
    assert_true(is_dir($tmp) && is_writable($tmp), "Temp dir not writable: $tmp");
    $free = @disk_free_space($tmp);
    if ($free !== false && $free < 10 * 1024 * 1024) {
        throw new \RuntimeException('Less than 10 MB free in temp dir');
    }
});

// Controller instantiates (no auth header in CLI -> userEmail stays null, fine)
test('ColleagueController instantiates', function () use ($config) {
    $c = new \Webmail\Addons\Team\Controllers\ColleagueController($config);
    assert_true($c instanceof \Webmail\Addons\Team\Controllers\ColleagueController);
});

// Resolve the test colleague (needed by the db group; warn-only here)
$colleagueService = null;
test('Test colleague record resolvable', function () use ($config, $testEmail, &$colleagueService, &$testColleagueId, &$originalAvatarPath) {
    $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($config);
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->prepare('SELECT id, avatar_path FROM organization_colleagues WHERE email = ?');
    $stmt->execute([$testEmail]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        vlog("No colleague row for $testEmail yet (db roundtrip group will be skipped)");
        return 'warn';
    }
    $testColleagueId    = (int) $row['id'];
    $originalAvatarPath = $row['avatar_path']; // capture for restore in cleanup
    vlog("Colleague #{$testColleagueId}, original avatar_path=" . var_export($originalAvatarPath, true));
});

if (!$preflightOk) {
    out("\n\033[31mPre-flight failed (database unreachable). Aborting.\033[0m");
    out("=============================================================\n");
    exit(1);
}

// ══════════════════════════════════════════════════════════════════
// GROUP: mime -- MIME detection (detectImageMime)
// ══════════════════════════════════════════════════════════════════

if (shouldRun('mime')) {
    out("\n=== MIME detection ===");

    foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $fmt) {
        test("detectImageMime() identifies $fmt", function () use ($fmt, $runId, &$tempFiles) {
            if (!gdSupports($fmt)) {
                vlog("GD cannot synthesize $fmt on this build; skipping");
                return 'warn';
            }
            $ext  = explode('/', $fmt)[1];
            $path = sys_get_temp_dir() . "/avtest_{$runId}_mime.{$ext}";
            $tempFiles[] = $path;
            $made = makeTestImage($fmt, 64, 64, $path);
            assert_true($made !== null, "Failed to synthesize $fmt test image");

            $detected = callDetectMime($path);
            // GIF/JPEG/PNG/WEBP all report their canonical mime; jpeg may vary by build
            assert_true(is_string($detected) && $detected !== '', 'No MIME detected');
            assert_true(
                str_contains($detected, $ext) || $detected === $fmt
                    || ($fmt === 'image/jpeg' && $detected === 'image/jpeg'),
                "Expected $fmt, got $detected"
            );
        });
    }

    test('detectImageMime() rejects non-image content', function () use ($runId, &$tempFiles) {
        $path = sys_get_temp_dir() . "/avtest_{$runId}_notimage.txt";
        $tempFiles[] = $path;
        file_put_contents($path, "this is definitely not an image\n");
        $detected = callDetectMime($path);
        assert_false(
            in_array($detected, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true),
            "Plain text wrongly detected as image ($detected)"
        );
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: resize -- resizeAvatar() decode/resample/encode path
// ══════════════════════════════════════════════════════════════════

if (shouldRun('resize')) {
    out("\n=== resizeAvatar() ===");

    // Large image (>512) exercises the decode + resample + encode branch,
    // which is the path that previously threw on WebP-less GD builds.
    foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $fmt) {
        test("resizeAvatar() handles large $fmt without throwing", function () use ($fmt, $runId, $AVATAR_DIR, $TEST_PREFIX, &$tempFiles) {
            $ext = explode('/', $fmt)[1];

            // Source in temp
            $src = sys_get_temp_dir() . "/avtest_{$runId}_src.{$ext}";
            $tempFiles[] = $src;
            $made = makeTestImage($fmt, 800, 600, $src);
            if ($made === null) {
                vlog("GD cannot synthesize $fmt; cannot test resize for this format");
                return 'warn';
            }

            $dest = $AVATAR_DIR . '/' . $TEST_PREFIX . $runId . "_resize.{$ext}";
            $tempFiles[] = $dest;

            // This call must NEVER throw, even if GD lacks this encoder.
            $ok = callResize($src, $dest, $fmt, 512);

            if (!gdSupports($fmt)) {
                // Expected: graceful false (caller would then store the original)
                assert_false($ok, "resizeAvatar() should return false when GD lacks $fmt");
                return 'warn';
            }

            assert_true($ok, "resizeAvatar() returned false for supported $fmt");
            assert_true(file_exists($dest), 'Resized file not written');
            $info = @getimagesize($dest);
            assert_true(is_array($info), 'Resized output is not a valid image');
            assert_true($info[0] <= 512 && $info[1] <= 512, "Resized larger than 512: {$info[0]}x{$info[1]}");
        });
    }

    test('resizeAvatar() returns false (no throw) for unsupported mime', function () use ($runId, $AVATAR_DIR, $TEST_PREFIX, &$tempFiles) {
        $src = sys_get_temp_dir() . "/avtest_{$runId}_bmp_src.bin";
        $tempFiles[] = $src;
        file_put_contents($src, str_repeat("\x00", 256));
        $dest = $AVATAR_DIR . '/' . $TEST_PREFIX . $runId . '_unsupported.bin';
        $tempFiles[] = $dest;

        $ok = callResize($src, $dest, 'image/bmp', 512);
        assert_false($ok, 'Unsupported mime must yield false, not an exception');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: db -- avatar_path roundtrip via ColleagueService
// ══════════════════════════════════════════════════════════════════

if (shouldRun('db')) {
    out("\n=== avatar_path DB roundtrip ===");

    test('updateColleague sets and clears avatar_path (self)', function () use (
        $config, $testEmail, &$colleagueService, &$testColleagueId, $TEST_PREFIX, $runId
    ) {
        if ($testColleagueId === null) {
            vlog('No colleague id resolved; skipping DB roundtrip');
            return 'warn';
        }
        if ($colleagueService === null) {
            $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($config);
        }

        $db = \Webmail\Core\Database::getConnection($config);
        $testPath = 'avatars/' . $TEST_PREFIX . $runId . '.png';

        // SET (self-update path: actor == colleague email -> isSelf true)
        $res = $colleagueService->updateColleague($testEmail, $testColleagueId, ['avatar_path' => $testPath]);
        assert_true(!empty($res['success']), 'updateColleague(avatar_path) failed: ' . ($res['error'] ?? '?'));

        $stmt = $db->prepare('SELECT avatar_path FROM organization_colleagues WHERE id = ?');
        $stmt->execute([$testColleagueId]);
        assert_equals($testPath, $stmt->fetchColumn(), 'avatar_path not persisted');

        // CLEAR
        $res = $colleagueService->updateColleague($testEmail, $testColleagueId, ['avatar_path' => null]);
        assert_true(!empty($res['success']), 'updateColleague(null) failed: ' . ($res['error'] ?? '?'));

        $stmt->execute([$testColleagueId]);
        $after = $stmt->fetchColumn();
        assert_true($after === null || $after === '', 'avatar_path not cleared, got ' . var_export($after, true));
        // cleanup restores the real original value afterwards
    });
}

// ══════════════════════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════════════════════

$totalDuration = array_sum(array_column($results, 'ms'));

if ($jsonMode) {
    echo json_encode([
        'suite'    => 'avatar-system-test',
        'account'  => $testEmail,
        'run_id'   => $runId,
        'total'    => $totalTests,
        'passed'   => $passed,
        'warnings' => $warnings,
        'failed'   => $failed,
        'duration_ms' => $totalDuration,
        'log'      => $logFile,
        'results'  => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    out("\n=============================================================");
    out("  RESULTS");
    out("=============================================================");
    out("  Total:    $totalTests");
    out("  Passed:   \033[32m$passed\033[0m");
    if ($warnings > 0) out("  Warnings: \033[33m$warnings\033[0m");
    if ($failed > 0)   out("  Failed:   \033[31m$failed\033[0m");
    out("  Duration: {$totalDuration}ms");
    out("  Log:      $logFile");

    if ($warnings > 0) {
        out("\n  WARNINGS (non-fatal; usually missing GD format support):");
        foreach ($results as $r) {
            if ($r['status'] === 'WARN') out("    - {$r['name']}");
        }
    }
    if ($failed > 0) {
        out("\n  FAILURES:");
        foreach ($results as $r) {
            if ($r['status'] === 'FAIL') out("    - {$r['name']}: {$r['error']}");
        }
    }
    out("=============================================================\n");
}

exit($failed > 0 ? 1 : 0);
