#!/usr/bin/env php
<?php
/**
 * FlowOne Drive Flows - End-to-End Acceptance Suite
 *
 * Walks the concrete Drive user journeys through the live DriveService:
 *
 *   create folder, upload into the folder, share the in-folder file
 *   (token resolve + token download + md5 content match), rename folder,
 *   move folder (normal move + the recursive "move parent under its own
 *   child must fail" guard), delete file, delete folder.
 *
 * Server run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-flows-test.php \
 *     --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL    Test account (required)
 *   --password=PASS  Account password (required)
 *   --only=g1,g2     create-folder,upload,share,rename-folder,move-folder,delete-file,delete-folder
 *   --skip-send      No-op here (kept for flag-parity); Drive ops are local
 *   --smoke          Connectivity + class-load health check only
 *   --verbose        Show stack traces / file:line on failure
 *   --json           Emit a single JSON summary
 *   --help           Show this help
 *
 * Safety: every artifact uses a "FLOWONE-TEST-" / "flowone-test-" prefix
 * and is removed on exit (shutdown + SIGINT/SIGTERM). Idempotent.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

// ── CLI args ─────────────────────────────────────────────────────

$opts = getopt('', ['email:', 'password:', 'only:', 'skip-send', 'smoke', 'verbose', 'json', 'help']);

if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Drive Flows - End-to-End Acceptance Suite\n";
    echo "=================================================\n\n";
    echo "Usage:\n";
    echo "  php drive-flows-test.php --email=USER --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL    Test account (required)\n";
    echo "  --password=PASS  Account password (required)\n";
    echo "  --only=g1,g2     create-folder,upload,share,rename-folder,move-folder,delete-file,delete-folder\n";
    echo "  --smoke          Connectivity + class-load health check only\n";
    echo "  --verbose        Show stack traces / file:line on failure\n";
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

$logFile = __DIR__ . '/../storage/logs/drive-flows-test-' . date('Ymd-His') . '.log';
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

// Artifacts to remove on exit.
$cleanupFileIds   = [];
$cleanupFolderIds = [];

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

// ── Cleanup (shutdown + signal safe) ─────────────────────────────

function doCleanup(): void {
    global $cleanupFileIds, $cleanupFolderIds, $testEmail, $config;
    if (empty($cleanupFileIds) && empty($cleanupFolderIds)) return;
    try {
        $drive = new \Webmail\Services\DriveService($config, $testEmail);
        foreach ($cleanupFileIds as $id) {
            try { $drive->deleteFile($testEmail, $id); } catch (\Throwable $e) {}
        }
        // Children before parents.
        foreach (array_reverse($cleanupFolderIds) as $id) {
            try { $drive->deleteFolder($testEmail, $id); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        error_log('[drive-flows cleanup] ' . $e->getMessage());
    }
    $cleanupFileIds = $cleanupFolderIds = [];
}
register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

/** Is folder $id present in the user's folder set (any level)? */
function folderExists(\Webmail\Services\DriveService $drive, string $email, int $id): bool {
    return $drive->getFolder($email, $id) !== null
        && empty($drive->getFolder($email, $id)['is_trashed']);
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Drive Flows - E2E Acceptance Suite");
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
test('DriveService instantiation + DB reachable', function () use ($config, $testEmail, &$drive) {
    $drive = new \Webmail\Services\DriveService($config, $testEmail);
    assert_true($drive instanceof \Webmail\Services\DriveService, 'DriveService not constructed');
    // getQuota touches the DB; confirms connectivity end-to-end.
    $quota = $drive->getQuota($testEmail);
    assert_true(is_array($quota), 'getQuota did not return array (DB unreachable?)');
});

test('Drive storage path writable', function () use ($config) {
    $path = $config['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive';
    assert_true(is_dir($path), "Drive storage not found: {$path}");
    assert_true(is_writable($path), "Drive storage not writable: {$path}");
});

if ($smokeOnly) {
    goto summary;
}

$drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);

// Shared journey state.
$rootId        = null;
$parentId      = null;
$childId       = null;
$fileId        = null;
$uploadContent = "FLOWONE-TEST drive flow " . date('c') . "\n" . str_repeat("payload-9\n", 60);
$shareToken    = null;

// ── 1. CREATE FOLDER ─────────────────────────────────────────────

if (shouldRun('create-folder')) {
    out("\n--- 1. CREATE FOLDER ---");

    test('Create root test folder', function () use (&$drive, $testEmail, &$rootId, &$cleanupFolderIds) {
        $folder = $drive->createFolder($testEmail, 'FLOWONE-TEST-Root');
        assert_true($folder !== null, 'createFolder returned null');
        assert_true((int)$folder['id'] > 0, 'no folder id');
        $rootId = (int)$folder['id'];
        $cleanupFolderIds[] = $rootId;
    });

    test('New folder appears in root listing', function () use (&$drive, $testEmail, &$rootId) {
        assert_true($rootId !== null, 'no root folder created');
        $names = array_map(fn($f) => $f['name'], $drive->getFolders($testEmail));
        assert_true(in_array('FLOWONE-TEST-Root', $names, true), 'created folder not listed at root');
    });
}

// ── 2. UPLOAD INTO FOLDER ────────────────────────────────────────

if (shouldRun('upload')) {
    out("\n--- 2. UPLOAD INTO FOLDER ---");

    test('Upload file into the folder', function () use (&$drive, $testEmail, &$rootId, &$fileId, $uploadContent, &$cleanupFileIds) {
        if ($rootId === null) { $rootId = (int)$drive->createFolder($testEmail, 'FLOWONE-TEST-Root')['id']; }
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-flow.txt', $uploadContent, 'text/plain', $rootId);
        assert_true($file !== null, 'uploadFileContent returned null');
        assert_equals($rootId, (int)$file['folder_id'], 'file not placed in target folder');
        assert_true((int)$file['size'] > 0, 'uploaded file size is 0');
        $fileId = (int)$file['id'];
        $cleanupFileIds[] = $fileId;
    });

    test('Uploaded file is listed inside the folder', function () use (&$drive, $testEmail, &$rootId, &$fileId) {
        assert_true($fileId !== null, 'no file uploaded');
        $files = $drive->getFiles($testEmail, $rootId);
        $found = false;
        foreach ($files as $f) {
            if ((int)$f['id'] === $fileId) { $found = true; break; }
        }
        assert_true($found, 'uploaded file not found in folder listing');
    });
}

// ── 3. SHARE FROM FOLDER (resolve + download + md5) ──────────────

if (shouldRun('share')) {
    out("\n--- 3. SHARE FROM FOLDER ---");

    test('Create share link for the in-folder file', function () use (&$drive, $testEmail, &$fileId, &$shareToken) {
        assert_true($fileId !== null, 'no file to share');
        $token = $drive->createShareLink($testEmail, $fileId);
        assert_not_empty($token, 'createShareLink returned empty');
        assert_equals(64, strlen($token), 'share token should be 64 hex chars');
        $shareToken = $token;
    });

    test('Token resolves to the file with share info', function () use (&$drive, &$shareToken) {
        assert_not_empty($shareToken, 'no token');
        $resolved = $drive->getFileByShareToken($shareToken);
        assert_true($resolved !== null, 'getFileByShareToken returned null');
        assert_true(strpos($resolved['original_name'], 'flowone-test-flow') !== false, 'wrong file resolved');

        $info = $drive->getFileShareInfo($shareToken);
        assert_true($info !== null, 'getFileShareInfo returned null');
        assert_true(isset($info['original_name'], $info['size']), 'share info missing fields');
    });

    test('Download via token returns identical content (md5)', function () use (&$drive, &$shareToken, $uploadContent) {
        assert_not_empty($shareToken, 'no token');
        $pathInfo = $drive->getFilePathByToken($shareToken);
        assert_true($pathInfo !== null && !empty($pathInfo['path']), 'getFilePathByToken returned no path');
        assert_true(file_exists($pathInfo['path']), 'token-resolved file missing on disk');
        assert_equals(md5($uploadContent), md5(file_get_contents($pathInfo['path'])), 'downloaded content md5 mismatch (sharing delivers wrong/corrupt bytes)');
    });

    test('Removing the share link revokes access', function () use (&$drive, $testEmail, &$fileId, &$shareToken) {
        assert_true($fileId !== null, 'no file');
        $removed = $drive->removeShareLink($testEmail, $fileId);
        assert_true($removed, 'removeShareLink returned false');
        assert_true($drive->getFileByShareToken($shareToken) === null, 'token still resolves after removal');
        $shareToken = null;
    });
}

// ── 4. RENAME FOLDER ─────────────────────────────────────────────

if (shouldRun('rename-folder')) {
    out("\n--- 4. RENAME FOLDER ---");

    test('Rename folder and verify new name', function () use (&$drive, $testEmail, &$rootId) {
        if ($rootId === null) { $rootId = (int)$drive->createFolder($testEmail, 'FLOWONE-TEST-Root')['id']; }
        $ok = $drive->renameFolder($testEmail, $rootId, 'FLOWONE-TEST-Renamed');
        assert_true($ok, 'renameFolder returned false');
        $folder = $drive->getFolder($testEmail, $rootId);
        assert_equals('FLOWONE-TEST-Renamed', $folder['name'] ?? '', 'folder name not updated');
        $names = array_map(fn($f) => $f['name'], $drive->getFolders($testEmail));
        assert_true(in_array('FLOWONE-TEST-Renamed', $names, true), 'renamed folder not in listing');
    });
}

// ── 5. MOVE FOLDER (+ recursive guard) ───────────────────────────

if (shouldRun('move-folder')) {
    out("\n--- 5. MOVE FOLDER ---");

    test('Build root -> parent -> child tree', function () use (&$drive, $testEmail, &$rootId, &$parentId, &$childId, &$cleanupFolderIds) {
        if ($rootId === null) {
            $rootId = (int)$drive->createFolder($testEmail, 'FLOWONE-TEST-Root')['id'];
            $cleanupFolderIds[] = $rootId;
        }
        $parent = $drive->createFolder($testEmail, 'FLOWONE-TEST-Parent', $rootId);
        assert_true($parent !== null, 'create parent failed');
        $parentId = (int)$parent['id'];
        $cleanupFolderIds[] = $parentId;

        $child = $drive->createFolder($testEmail, 'FLOWONE-TEST-Child', $parentId);
        assert_true($child !== null, 'create child failed');
        $childId = (int)$child['id'];
        $cleanupFolderIds[] = $childId;
    });

    test('Recursive move (parent under its own child) MUST fail', function () use (&$drive, $testEmail, &$parentId, &$childId, &$rootId) {
        assert_true($parentId !== null && $childId !== null, 'tree not built');
        // Moving the parent under its descendant child must be rejected.
        $bad = $drive->moveFolder($testEmail, $parentId, $childId);
        assert_true($bad === false, 'moveFolder allowed a cycle (parent under its own child)!');
        // Parent must remain under root, untouched.
        $parent = $drive->getFolder($testEmail, $parentId);
        assert_equals($rootId, (int)$parent['parent_id'], 'parent.parent_id changed after rejected move');
    });

    test('Normal move (child -> root) succeeds and re-parents', function () use (&$drive, $testEmail, &$childId, &$rootId) {
        assert_true($childId !== null, 'no child folder');
        $ok = $drive->moveFolder($testEmail, $childId, $rootId);
        assert_true($ok, 'moveFolder returned false for a valid move');
        $child = $drive->getFolder($testEmail, $childId);
        assert_equals($rootId, (int)$child['parent_id'], 'child not re-parented to root');
    });
}

// ── 6. DELETE FILE ───────────────────────────────────────────────

if (shouldRun('delete-file')) {
    out("\n--- 6. DELETE FILE ---");

    test('Delete file removes DB row and disk bytes', function () use (&$drive, $testEmail, &$rootId, &$fileId, &$cleanupFileIds) {
        // Ensure a file exists to delete (independent of the upload group).
        if ($fileId === null) {
            if ($rootId === null) { $rootId = (int)$drive->createFolder($testEmail, 'FLOWONE-TEST-Root')['id']; }
            $f = $drive->uploadFileContent($testEmail, 'flowone-test-todelete.txt', 'delete me', 'text/plain', $rootId);
            assert_true($f !== null, 'setup upload failed');
            $fileId = (int)$f['id'];
        }
        $path = $drive->getFilePath($testEmail, $fileId);

        $ok = $drive->deleteFile($testEmail, $fileId);
        assert_true($ok, 'deleteFile returned false');
        assert_true($drive->getFile($testEmail, $fileId) === null, 'file row still present after delete');
        if ($path) {
            assert_true(!file_exists($path), 'file bytes still on disk after delete');
        }
        $cleanupFileIds = array_values(array_diff($cleanupFileIds, [$fileId]));
        $fileId = null;
    });
}

// ── 7. DELETE FOLDER ─────────────────────────────────────────────

if (shouldRun('delete-folder')) {
    out("\n--- 7. DELETE FOLDER ---");

    test('Delete folders (child, parent, root) and confirm gone', function () use (&$drive, $testEmail, &$rootId, &$parentId, &$childId, &$cleanupFolderIds) {
        // Build a minimal tree if the move group did not run.
        if ($rootId === null) {
            $rootId = (int)$drive->createFolder($testEmail, 'FLOWONE-TEST-Root')['id'];
        }

        foreach ([$childId, $parentId, $rootId] as $fid) {
            if ($fid === null) continue;
            $res = $drive->deleteFolder($testEmail, $fid);
            assert_true($res !== false, "deleteFolder returned false for {$fid}");
            assert_true(!folderExists($drive, $testEmail, $fid), "folder {$fid} still present after delete");
        }

        // Nothing of ours should remain at root.
        $names = array_map(fn($f) => $f['name'], $drive->getFolders($testEmail));
        foreach (['FLOWONE-TEST-Root', 'FLOWONE-TEST-Renamed', 'FLOWONE-TEST-Parent', 'FLOWONE-TEST-Child'] as $n) {
            assert_true(!in_array($n, $names, true), "test folder '{$n}' still listed after delete");
        }
        $cleanupFolderIds = array_values(array_diff($cleanupFolderIds, [$childId, $parentId, $rootId]));
        $rootId = $parentId = $childId = null;
    });
}

// ── Summary ──────────────────────────────────────────────────────

summary:
out("\n--- CLEANUP ---");
test('Remove remaining Drive test artifacts', function () {
    doCleanup();
});

out("\n=================================================================");
if ($failed === 0) {
    out("  {$GREEN}ALL PASSED{$NC}: {$passed} passed, {$warnings} warnings / {$totalTests} total");
} else {
    out("  {$RED}RESULT{$NC}: {$passed} passed, {$failed} FAILED, {$warnings} warnings / {$totalTests} total");
}
out("  Log: {$logFile}");

if ($failed > 0) {
    out("\n  {$RED}FAILED TESTS:{$NC}");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    x {$r['name']}");
            out("      " . ($r['error'] ?? ''));
        }
    }
}

if ($jsonMode) {
    echo json_encode([
        'name'     => 'drive-flows',
        'total'    => $totalTests,
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'log'      => $logFile,
        'results'  => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

out("=================================================================");
exit($failed > 0 ? 1 : 0);
