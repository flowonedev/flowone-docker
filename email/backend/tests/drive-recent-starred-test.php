#!/usr/bin/env php
<?php
/**
 * FlowOne Drive - Recent & Starred Test Suite
 *
 * Exercises the new is_starred + last_opened_at / last_accessed_at columns
 * and the supporting DriveService API surface
 * (toggleStarFile, toggleStarFolder, recordFileAccess, recordFolderAccess,
 *  getStarredItems, getRecentItems).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-recent-starred-test.php \
 *       --email=admin@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required, must exist on this server)
 *   --only=group,group   Run only specific groups (schema,starred,recent,permissions,cleanup)
 *   --smoke              Quick health check (schema + connectivity, no business logic)
 *   --skip-send          (no-op kept for parity with other test scripts)
 *   --json               Output the summary as JSON
 *   --verbose            Show extra debug info (stack traces, raw rows)
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'only:', 'smoke', 'skip-send', 'json', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Drive Recent + Starred Test Suite\n";
    echo "==========================================\n\n";
    echo "Usage:\n";
    echo "  php drive-recent-starred-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (must exist on this server)\n";
    echo "  --only=group,group   Run only specific groups (schema,starred,recent,permissions,cleanup)\n";
    echo "  --smoke              Quick health check (schema + connectivity)\n";
    echo "  --skip-send          (no-op, kept for parity)\n";
    echo "  --json               Output summary as JSON\n";
    echo "  --verbose            Extra debug output\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-recent-starred-test.php \\\n";
    echo "      --email=admin@flowone.pro --verbose\n";
    exit(1);
}

$testEmail  = strtolower($opts['email']);
$smokeOnly  = isset($opts['smoke']);
$verbose    = isset($opts['verbose']);
$jsonOutput = isset($opts['json']);
$onlyGroups = !empty($opts['only']) ? explode(',', $opts['only']) : [];

// Per-test timeout in seconds (used via set_time_limit per test).
$PER_TEST_TIMEOUT = 30;

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/drive-recent-starred-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

// Track test data for cleanup (use a recognisable prefix so we can't
// confuse this with real data even if cleanup fails).
$cleanupFileIds   = [];
$cleanupFolderIds = [];
$TEST_PREFIX      = 'flowone_test_recstar_';

$RED    = "\033[0;31m";
$GREEN  = "\033[0;32m";
$YELLOW = "\033[1;33m";
$NC     = "\033[0m";

function out(string $msg): void {
    global $logFile, $jsonOutput;
    if ($jsonOutput) return; // suppress interactive output in JSON mode
    $line = $msg . "\n";
    echo $line;
    @file_put_contents(
        $logFile,
        date('[H:i:s] ') . preg_replace('/\033\[[0-9;]*m/', '', $line),
        FILE_APPEND | LOCK_EX
    );
}

function shouldRun(string $group): bool {
    global $onlyGroups;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results,
           $verbose, $GREEN, $RED, $YELLOW, $NC, $PER_TEST_TIMEOUT;
    $totalTests++;
    @set_time_limit($PER_TEST_TIMEOUT);
    $start = microtime(true);
    try {
        $result = $fn();
        $elapsed = round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  {$YELLOW}[WARN]{$NC}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  {$GREEN}[PASS]{$NC}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = round((microtime(true) - $start) * 1000);
        $failed++;
        out("  {$RED}[FAIL]{$NC}  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

function assert_true(bool $condition, string $msg = 'Assertion failed'): void {
    if (!$condition) throw new \RuntimeException($msg);
}

function assert_not_empty($value, string $msg = 'Value is empty'): void {
    if (empty($value)) throw new \RuntimeException($msg);
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $detail = $msg ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        throw new \RuntimeException($detail);
    }
}

// Cleanup -- always runs, even on crash
function doCleanup(): void {
    global $cleanupFileIds, $cleanupFolderIds, $testEmail, $config;
    if (empty($cleanupFileIds) && empty($cleanupFolderIds)) return;
    try {
        $drive = new \Webmail\Services\DriveService($config, $testEmail);
        foreach ($cleanupFileIds as $id) {
            try { $drive->deleteFile($testEmail, $id); } catch (\Throwable $e) {}
        }
        foreach (array_reverse($cleanupFolderIds) as $id) {
            try { $drive->deleteFolder($testEmail, $id); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        error_log('[Drive Recent/Starred Test Cleanup] ' . $e->getMessage());
    }
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Drive Recent + Starred Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: {$testEmail}");
out("  Mode:    " . ($smokeOnly ? 'SMOKE (schema + connectivity)' : 'FULL'));
if (!empty($onlyGroups)) out("  Groups:  " . implode(', ', $onlyGroups));
out("  Log:     {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

test('PHP extensions loaded', function () {
    $required = ['pdo', 'pdo_mysql', 'mbstring'];
    $missing = [];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) $missing[] = $ext;
    }
    assert_true(empty($missing), 'Missing extensions: ' . implode(', ', $missing));
});

$db = null;

test('Database connection', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db instanceof \PDO, 'Not a PDO instance');
});

test('Storage logs directory writable', function () {
    $dir = __DIR__ . '/../storage/logs';
    assert_true(is_dir($dir) && is_writable($dir), 'storage/logs not writable');
});

test('DriveService instantiable for test account', function () use ($config, $testEmail) {
    $drive = new \Webmail\Services\DriveService($config, $testEmail);
    assert_true($drive instanceof \Webmail\Services\DriveService);
});

// ── 1. Schema ────────────────────────────────────────────────────

if (shouldRun('schema')) {
    out("\n--- 1. SCHEMA ---");

    test('drive_files.is_starred column exists', function () use (&$db) {
        $row = $db->query("SHOW COLUMNS FROM drive_files LIKE 'is_starred'")->fetch();
        assert_not_empty($row, 'drive_files.is_starred missing (run migration 162)');
    });

    test('drive_files.last_opened_at column exists', function () use (&$db) {
        $row = $db->query("SHOW COLUMNS FROM drive_files LIKE 'last_opened_at'")->fetch();
        assert_not_empty($row, 'drive_files.last_opened_at missing');
    });

    test('drive_folders.is_starred column exists', function () use (&$db) {
        $row = $db->query("SHOW COLUMNS FROM drive_folders LIKE 'is_starred'")->fetch();
        assert_not_empty($row, 'drive_folders.is_starred missing (run migration 162)');
    });

    test('drive_folders.last_accessed_at column exists', function () use (&$db) {
        $row = $db->query("SHOW COLUMNS FROM drive_folders LIKE 'last_accessed_at'")->fetch();
        assert_not_empty($row, 'drive_folders.last_accessed_at missing (run migration 162)');
    });

    test('drive_files starred index present', function () use (&$db) {
        $rows = $db->query("SHOW INDEX FROM drive_files WHERE Key_name = 'idx_files_starred'")->fetchAll();
        assert_true(!empty($rows), 'idx_files_starred missing -- migration 162 not applied');
    });

    test('drive_files recent index present', function () use (&$db) {
        $rows = $db->query("SHOW INDEX FROM drive_files WHERE Key_name = 'idx_files_recent'")->fetchAll();
        assert_true(!empty($rows), 'idx_files_recent missing');
    });

    test('drive_folders starred index present', function () use (&$db) {
        $rows = $db->query("SHOW INDEX FROM drive_folders WHERE Key_name = 'idx_folders_starred'")->fetchAll();
        assert_true(!empty($rows), 'idx_folders_starred missing');
    });

    test('drive_folders recent index present', function () use (&$db) {
        $rows = $db->query("SHOW INDEX FROM drive_folders WHERE Key_name = 'idx_folders_recent'")->fetchAll();
        assert_true(!empty($rows), 'idx_folders_recent missing');
    });
}

if ($smokeOnly) {
    goto summary;
}

$drive = new \Webmail\Services\DriveService($config, $testEmail);

// Seed: a folder + a file we can star/touch.
$seedFolderId = null;
$seedFileId   = null;

test('Seed: create a test folder', function () use (&$drive, $testEmail, $TEST_PREFIX, &$seedFolderId, &$cleanupFolderIds) {
    $folder = $drive->createFolder($testEmail, $TEST_PREFIX . 'folder-' . time(), null);
    assert_true(is_array($folder) && !empty($folder['id']), 'createFolder did not return id');
    $seedFolderId = (int)$folder['id'];
    $cleanupFolderIds[] = $seedFolderId;
});

test('Seed: upload a test file', function () use (&$drive, $testEmail, $TEST_PREFIX, &$seedFileId, &$cleanupFileIds, $seedFolderId) {
    $file = $drive->uploadFileContent(
        $testEmail,
        $TEST_PREFIX . 'file-' . time() . '.txt',
        'Recent/Starred test payload @ ' . date('c'),
        'text/plain',
        $seedFolderId
    );
    assert_true(is_array($file) && !empty($file['id']), 'uploadFileContent did not return id');
    $seedFileId = (int)$file['id'];
    $cleanupFileIds[] = $seedFileId;
});

// ── 2. Starred ───────────────────────────────────────────────────

if (shouldRun('starred')) {
    out("\n--- 2. STARRED ---");

    test('toggleStarFile flips file to starred', function () use (&$drive, $testEmail, $seedFileId) {
        $state = $drive->toggleStarFile($testEmail, $seedFileId);
        assert_equals(true, $state, 'Expected new state to be true');
    });

    test('Starred file appears in getStarredItems', function () use (&$drive, $testEmail, $seedFileId) {
        $items = $drive->getStarredItems($testEmail);
        assert_true(isset($items['files']), 'Missing files key');
        $found = false;
        foreach ($items['files'] as $f) {
            if ((int)$f['id'] === $seedFileId) { $found = true; break; }
        }
        assert_true($found, 'Starred file not found in getStarredItems');
    });

    test('toggleStarFile flips file back to unstarred', function () use (&$drive, $testEmail, $seedFileId) {
        $state = $drive->toggleStarFile($testEmail, $seedFileId);
        assert_equals(false, $state, 'Expected new state to be false');
    });

    test('Unstarred file no longer in getStarredItems', function () use (&$drive, $testEmail, $seedFileId) {
        $items = $drive->getStarredItems($testEmail);
        foreach (($items['files'] ?? []) as $f) {
            if ((int)$f['id'] === $seedFileId) {
                throw new \RuntimeException('Unstarred file still appears in starred list');
            }
        }
    });

    test('toggleStarFolder flips folder to starred', function () use (&$drive, $testEmail, $seedFolderId) {
        $state = $drive->toggleStarFolder($testEmail, $seedFolderId);
        assert_equals(true, $state, 'Expected new state to be true');
    });

    test('Starred folder appears in getStarredItems', function () use (&$drive, $testEmail, $seedFolderId) {
        $items = $drive->getStarredItems($testEmail);
        assert_true(isset($items['folders']), 'Missing folders key');
        $found = false;
        foreach ($items['folders'] as $f) {
            if ((int)$f['id'] === $seedFolderId) { $found = true; break; }
        }
        assert_true($found, 'Starred folder not found in getStarredItems');
    });

    test('toggleStarFolder flips folder back', function () use (&$drive, $testEmail, $seedFolderId) {
        $state = $drive->toggleStarFolder($testEmail, $seedFolderId);
        assert_equals(false, $state, 'Expected new state to be false');
    });
}

// ── 3. Recent ────────────────────────────────────────────────────

if (shouldRun('recent')) {
    out("\n--- 3. RECENT ---");

    test('recordFileAccess updates last_opened_at', function () use (&$drive, $testEmail, $seedFileId, &$db) {
        $ok = $drive->recordFileAccess($testEmail, $seedFileId);
        assert_true($ok, 'recordFileAccess returned false');

        $stmt = $db->prepare('SELECT last_opened_at FROM drive_files WHERE id = ?');
        $stmt->execute([$seedFileId]);
        $row = $stmt->fetch();
        assert_true(!empty($row['last_opened_at']), 'last_opened_at not set');
    });

    test('recordFolderAccess updates last_accessed_at', function () use (&$drive, $testEmail, $seedFolderId, &$db) {
        $ok = $drive->recordFolderAccess($testEmail, $seedFolderId);
        assert_true($ok, 'recordFolderAccess returned false');

        $stmt = $db->prepare('SELECT last_accessed_at FROM drive_folders WHERE id = ?');
        $stmt->execute([$seedFolderId]);
        $row = $stmt->fetch();
        assert_true(!empty($row['last_accessed_at']), 'last_accessed_at not set');
    });

    test('Recently-accessed file appears in getRecentItems', function () use (&$drive, $testEmail, $seedFileId) {
        $items = $drive->getRecentItems($testEmail, 50);
        assert_true(isset($items['files']), 'Missing files key');
        $found = false;
        foreach ($items['files'] as $f) {
            if ((int)$f['id'] === $seedFileId) { $found = true; break; }
        }
        assert_true($found, 'Recently-accessed file not in getRecentItems');
    });

    test('Recently-accessed folder appears in getRecentItems', function () use (&$drive, $testEmail, $seedFolderId) {
        $items = $drive->getRecentItems($testEmail, 50);
        assert_true(isset($items['folders']), 'Missing folders key');
        $found = false;
        foreach ($items['folders'] as $f) {
            if ((int)$f['id'] === $seedFolderId) { $found = true; break; }
        }
        assert_true($found, 'Recently-accessed folder not in getRecentItems');
    });

    test('Recent ordered by last_opened_at DESC (most recent first)', function () use (&$drive, $testEmail, &$cleanupFileIds, $TEST_PREFIX, $seedFolderId) {
        $a = $drive->uploadFileContent($testEmail, $TEST_PREFIX . 'order-a-' . time() . '.txt', 'A', 'text/plain', $seedFolderId);
        $b = $drive->uploadFileContent($testEmail, $TEST_PREFIX . 'order-b-' . time() . '.txt', 'B', 'text/plain', $seedFolderId);
        $cleanupFileIds[] = (int)$a['id'];
        $cleanupFileIds[] = (int)$b['id'];

        $drive->recordFileAccess($testEmail, (int)$a['id']);
        sleep(1);
        $drive->recordFileAccess($testEmail, (int)$b['id']);

        $items = $drive->getRecentItems($testEmail, 50);
        $posA = $posB = -1;
        foreach ($items['files'] as $i => $f) {
            if ((int)$f['id'] === (int)$a['id']) $posA = $i;
            if ((int)$f['id'] === (int)$b['id']) $posB = $i;
        }
        assert_true($posA >= 0 && $posB >= 0, 'One of the new files not in recent list');
        assert_true($posB < $posA, "Expected newer file B (pos {$posB}) to come before A (pos {$posA})");
    });

    test('Recent limit param respected', function () use (&$drive, $testEmail) {
        $items = $drive->getRecentItems($testEmail, 1);
        $total = count($items['files']) + count($items['folders']);
        assert_true($total <= 2, "Expected <= 2 results across files+folders, got {$total}");
    });
}

// ── 4. Permissions ───────────────────────────────────────────────

if (shouldRun('permissions')) {
    out("\n--- 4. PERMISSIONS ---");

    test('toggleStarFile returns null for unknown id', function () use (&$drive, $testEmail) {
        $res = $drive->toggleStarFile($testEmail, 999999999);
        assert_true($res === null, 'Expected null for non-existent file');
    });

    test('toggleStarFolder returns null for unknown id', function () use (&$drive, $testEmail) {
        $res = $drive->toggleStarFolder($testEmail, 999999999);
        assert_true($res === null, 'Expected null for non-existent folder');
    });

    test('toggleStarFile rejects other-user IDs', function () use (&$drive, &$db, $testEmail, &$cleanupFileIds) {
        // Insert a row owned by a fake user, then try to star it as $testEmail.
        $bogus = 'flowone_test_other_' . substr(md5((string)microtime(true)), 0, 8) . '@example.invalid';
        $stmt = $db->prepare("INSERT INTO drive_files (user_email, folder_id, filename, original_name, size, mime_type) VALUES (?, NULL, ?, ?, 0, 'text/plain')");
        $stmt->execute([$bogus, 'flowone_test_stub.txt', 'flowone_test_stub.txt']);
        $foreignId = (int)$db->lastInsertId();

        try {
            $res = $drive->toggleStarFile($testEmail, $foreignId);
            assert_true($res === null, 'Cross-user star should be rejected (returned null)');

            $row = $db->query("SELECT is_starred FROM drive_files WHERE id = {$foreignId}")->fetch();
            assert_equals(0, (int)$row['is_starred'], 'Cross-user star must not flip the flag');
        } finally {
            $db->prepare('DELETE FROM drive_files WHERE id = ?')->execute([$foreignId]);
        }
    });

    test('recordFileAccess on unknown id is harmless', function () use (&$drive, $testEmail) {
        $res = $drive->recordFileAccess($testEmail, 999999999);
        assert_equals(false, $res, 'Expected false for non-existent file');
    });
}

// ── 5. Cleanup ───────────────────────────────────────────────────

summary:
out("\n--- CLEANUP ---");

test('Remove test files and folders', function () use (&$cleanupFileIds, &$cleanupFolderIds, $testEmail, $config) {
    $drive = new \Webmail\Services\DriveService($config, $testEmail);
    $fc = 0; $folderC = 0;
    foreach ($cleanupFileIds as $id) {
        try { if ($drive->getFile($testEmail, $id)) { $drive->deleteFile($testEmail, $id); $fc++; } } catch (\Throwable $e) {}
    }
    foreach (array_reverse($cleanupFolderIds) as $id) {
        try { $drive->deleteFolder($testEmail, $id); $folderC++; } catch (\Throwable $e) {}
    }
    $cleanupFileIds = [];
    $cleanupFolderIds = [];
    out("          Cleaned up {$fc} file(s), {$folderC} folder(s)");
});

// ══════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════

if ($jsonOutput) {
    echo json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'results'  => $results,
        'log'      => $logFile,
    ], JSON_PRETTY_PRINT) . "\n";
    exit($failed > 0 ? 1 : 0);
}

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

if ($warnings > 0) {
    out("\n  {$YELLOW}WARNINGS:{$NC}");
    foreach ($results as $r) {
        if ($r['status'] === 'WARN') out("    ~ {$r['name']}");
    }
}

out("=================================================================");
exit($failed > 0 ? 1 : 0);
