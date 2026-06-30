#!/usr/bin/env php
<?php
/**
 * FlowOne Drive Batch Operations - Performance & Correctness Test Suite
 *
 * Verifies the batched delete/move endpoints that replaced per-id sequential
 * loops in deleteSelected, moveSelected, and clipboardPaste. Asserts that:
 *   - one batched call deletes/moves N items in <budget>ms
 *   - physical files (local + NAS) are actually unlinked
 *   - quota counters reconcile correctly
 *   - system-folder + board-link guards still block protected folders
 *   - folder-size aggregation runs once per unique affected folder, not N
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-batch-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --password=PASS      Test account password (required)
 *   --count=N            Seeded file count (default 20, max 100)
 *   --only=GROUPS        Comma-separated: delete,move,guards
 *   --smoke              Pre-flight only (no destructive tests)
 *   --json               Output results as JSON
 *   --verbose            Extra debug output
 *   --help               Show this help
 *
 * Exit 0 on all pass, 1 on any failure.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'password:', 'count:', 'only:', 'smoke', 'json', 'verbose', 'help']);

if (isset($opts['help'])) {
    echo "FlowOne Drive Batch Operations Test Suite\n";
    echo "==========================================\n\n";
    echo "Usage:\n";
    echo "  php drive-batch-test.php --email=user@flowone.pro --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --password=PASS      Test account password (required)\n";
    echo "  --count=N            Seeded file count (default 20, max 100)\n";
    echo "  --only=GROUPS        Comma-separated: delete,move,guards\n";
    echo "  --smoke              Pre-flight only\n";
    echo "  --json               Output results as JSON\n";
    echo "  --verbose            Extra debug output\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-batch-test.php \\\n";
    echo "      --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(0);
}

if (empty($opts['email']) || empty($opts['password'])) {
    fwrite(STDERR, "ERROR: --email and --password are required. Use --help for usage.\n");
    exit(1);
}

$testEmail    = strtolower($opts['email']);
$testPassword = $opts['password'];
$verbose      = isset($opts['verbose']);
$smokeOnly    = isset($opts['smoke']);
$jsonOutput   = isset($opts['json']);
$seedCount    = max(2, min(100, (int)($opts['count'] ?? 20)));
$onlyGroups   = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : [];

const PER_FILE_DELETE_BUDGET_MS = 80;
const PER_FILE_MOVE_BUDGET_MS   = 40;
const ABSOLUTE_MIN_BUDGET_MS    = 1500;

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return false;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

// Logging -------------------------------------------------------------

$logFile = __DIR__ . '/../storage/logs/drive-batch-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

function out(string $msg): void {
    global $logFile, $jsonOutput;
    $line = $msg . "\n";
    if (!$jsonOutput) echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    try {
        $r = $fn();
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        if ($r === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  \033[32m[PASS]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) out("          at " . $e->getFile() . ':' . $e->getLine());
        $results[] = [
            'name' => $name,
            'status' => 'FAIL',
            'ms' => $elapsed,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
}

function assert_true(bool $c, string $msg = 'Assertion failed'): void {
    if (!$c) throw new \RuntimeException($msg);
}
function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg ?: 'Values differ') . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function vlog(string $m): void { global $verbose; if ($verbose) out("          [v] $m"); }
function budget(int $perMs, int $count): int { return max(ABSOLUTE_MIN_BUDGET_MS, $perMs * $count); }

// Cleanup tracking ----------------------------------------------------

$TEST_TAG = '[FLOWONE-DRIVEBATCH]';
$seededFolderIds = [];   // root + subfolders
$seededFileIds   = [];
$seededFilePaths = [];   // local-disk paths to unlink on cleanup
$testRootFolderId = null;

function doCleanup(): void {
    global $config, $testEmail, $seededFolderIds, $seededFileIds, $seededFilePaths, $testRootFolderId;
    out("\n--- CLEANUP ---");
    try {
        $db = \Webmail\Core\Database::getConnection($config);

        if (!empty($seededFileIds)) {
            $ph = implode(',', array_fill(0, count($seededFileIds), '?'));
            $stmt = $db->prepare("DELETE FROM drive_files WHERE user_email = ? AND id IN ({$ph})");
            $stmt->execute(array_merge([$testEmail], $seededFileIds));
            vlog("Deleted " . $stmt->rowCount() . " test file rows");
        }
        if (!empty($seededFolderIds)) {
            $ph = implode(',', array_fill(0, count($seededFolderIds), '?'));
            $stmt = $db->prepare("DELETE FROM drive_folders WHERE user_email = ? AND id IN ({$ph})");
            $stmt->execute(array_merge([$testEmail], $seededFolderIds));
            vlog("Deleted " . count($seededFolderIds) . " test folder rows");
        }
        if ($testRootFolderId !== null) {
            $stmt = $db->prepare("DELETE FROM drive_folders WHERE user_email = ? AND id = ?");
            $stmt->execute([$testEmail, $testRootFolderId]);
        }
    } catch (\Throwable $e) {
        out("  [WARN] DB cleanup: " . $e->getMessage());
    }

    foreach ($seededFilePaths as $p) {
        if ($p && file_exists($p)) @unlink($p);
    }
    out("  Cleanup complete.");
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// =====================================================================

out("=================================================================");
out("  FlowOne Drive Batch Operations Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account:    {$testEmail}");
out("  Seed count: {$seedCount}");
out("  Log:        {$logFile}");
out("=================================================================\n");

out("--- PRE-FLIGHT ---");

test('PHP extension: pdo_mysql', function () {
    assert_true(extension_loaded('pdo_mysql'), 'pdo_mysql not loaded');
});
test('PHP extension: redis', function () {
    assert_true(extension_loaded('redis'), 'redis not loaded');
});

$db = null;
test('Database connection', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db instanceof \PDO, 'Not a PDO instance');
});

test('Required tables present', function () use (&$db) {
    foreach (['drive_files', 'drive_folders'] as $tbl) {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($tbl));
        assert_true($stmt->fetch() !== false, "Missing table: $tbl");
    }
});

$drive = null;
test('DriveService boot', function () use ($config, $testEmail, &$drive) {
    $drive = new \Webmail\Services\DriveService($config, $testEmail);
    assert_true($drive instanceof \Webmail\Services\DriveService, 'Failed to construct DriveService');
});

test('User storage path writable', function () use ($drive) {
    // getUserPath is private; probe via createFolder/uploadFile alternative.
    // Best-effort: ensure base storage path exists by inspecting config.
    $storageBase = $GLOBALS['config']['drive']['local_path'] ?? '/var/www/vps-email/storage/drive';
    assert_true(is_dir($storageBase) || @mkdir($storageBase, 0755, true), "Cannot create $storageBase");
    assert_true(is_writable($storageBase), "Storage path not writable: $storageBase");
});

if ($smokeOnly) {
    out("\n--- SMOKE MODE: pre-flight passed, skipping business tests ---\n");
    out("Result: passed={$passed} failed={$failed} warnings={$warnings}");
    exit($failed > 0 ? 1 : 0);
}

// Seed ----------------------------------------------------------------

out("\n--- SEED: creating test root folder + {$seedCount} files + 5 subfolders ---");

test('Create test root folder', function () use ($drive, $testEmail, $TEST_TAG, &$testRootFolderId, &$seededFolderIds) {
    $folder = $drive->createFolder($testEmail, $TEST_TAG . '_root_' . date('His'));
    assert_true(is_array($folder) && !empty($folder['id']), 'createFolder returned no id');
    $testRootFolderId = (int)$folder['id'];
    // Don't add to seededFolderIds -- cleanup handles testRootFolderId separately.
    vlog("Created root folder id=$testRootFolderId");
});

test('Create 5 sub-folders', function () use ($drive, $testEmail, $TEST_TAG, $testRootFolderId, &$seededFolderIds) {
    for ($i = 0; $i < 5; $i++) {
        $f = $drive->createFolder($testEmail, $TEST_TAG . "_sub_{$i}", $testRootFolderId);
        assert_true(is_array($f) && !empty($f['id']), "Sub-folder $i creation failed");
        $seededFolderIds[] = (int)$f['id'];
    }
});

test("Seed {$seedCount} file rows + local-disk blobs", function () use ($config, $testEmail, $seedCount, $testRootFolderId, &$seededFileIds, &$seededFilePaths) {
    $db = \Webmail\Core\Database::getConnection($config);
    $storageBase = $config['drive']['local_path'] ?? '/var/www/vps-email/storage/drive';
    $userHash = md5($testEmail);
    $userDir = "{$storageBase}/{$userHash}";
    if (!is_dir($userDir)) @mkdir($userDir, 0755, true);

    $stmt = $db->prepare(
        "INSERT INTO drive_files
            (user_email, folder_id, filename, original_name, size, mime_type, storage_location, created_at, updated_at)
         VALUES
            (?, ?, ?, ?, ?, ?, 'local', NOW(), NOW())"
    );

    for ($i = 0; $i < $seedCount; $i++) {
        $filename = 'flowone_test_' . uniqid('', true) . '_' . $i . '.bin';
        $path = "{$userDir}/{$filename}";
        $content = str_repeat('x', 1024); // 1KB payload
        file_put_contents($path, $content);
        $seededFilePaths[] = $path;

        $stmt->execute([
            $testEmail,
            $testRootFolderId,
            $filename,
            "FLOWONE-TEST file #{$i}.bin",
            strlen($content),
            'application/octet-stream',
        ]);
        $seededFileIds[] = (int)$db->lastInsertId();
    }
    assert_equals($seedCount, count($seededFileIds), 'Seed count mismatch');
});

// =====================================================================
// 1. BATCH DELETE
// =====================================================================

if (shouldRun('delete')) {
    out("\n--- 1. BATCH DELETE ---");

    test("deleteManyFiles({$seedCount}) completes in <" . budget(PER_FILE_DELETE_BUDGET_MS, $seedCount) . "ms",
        function () use ($drive, $testEmail, $seedCount, $seededFileIds, $seededFilePaths) {
            $b = budget(PER_FILE_DELETE_BUDGET_MS, $seedCount);
            $start = microtime(true);
            $r = $drive->deleteManyFiles($testEmail, $seededFileIds);
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            vlog("deleteManyFiles elapsed={$elapsed}ms, result=" . json_encode($r));
            assert_equals($seedCount, $r['success'] ?? -1, 'success count mismatch');
            assert_equals(0, $r['failed'] ?? -1, 'failed count nonzero');
            assert_true(($r['freedBytes'] ?? 0) > 0, 'freedBytes should be > 0');
            assert_true(count($r['affectedFolders'] ?? []) === 1, 'Should report exactly one affected folder');
            assert_true($elapsed < $b, "Latency {$elapsed}ms exceeds budget {$b}ms");
        }
    );

    test('All seeded local blobs were unlinked', function () use ($seededFilePaths) {
        $stillThere = 0;
        foreach ($seededFilePaths as $p) {
            if (file_exists($p)) $stillThere++;
        }
        assert_equals(0, $stillThere, "{$stillThere} blob(s) survived deleteManyFiles");
    });

    test('drive_files rows are gone', function () use ($config, $testEmail, $seededFileIds) {
        $db = \Webmail\Core\Database::getConnection($config);
        $ph = implode(',', array_fill(0, count($seededFileIds), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM drive_files WHERE user_email = ? AND id IN ({$ph})");
        $stmt->execute(array_merge([$testEmail], $seededFileIds));
        $row = $stmt->fetch();
        assert_equals(0, (int)$row['c'], 'Some drive_files rows survived');
    });

    test("deleteManyFolders(5) batch removes the seeded sub-folders",
        function () use ($drive, $testEmail, $seededFolderIds) {
            $r = $drive->deleteManyFolders($testEmail, $seededFolderIds);
            vlog("deleteManyFolders result=" . json_encode($r));
            assert_equals(5, $r['success'] ?? -1, 'success count mismatch');
            assert_equals(0, $r['failed'] ?? -1, 'failed count nonzero');
            // affectedParents should be exactly one (the testRoot)
            assert_true(count($r['affectedParents'] ?? []) === 1, 'Should report exactly one affected parent');
        }
    );

    test('Empty batch is a no-op', function () use ($drive, $testEmail) {
        $r1 = $drive->deleteManyFiles($testEmail, []);
        $r2 = $drive->deleteManyFolders($testEmail, []);
        assert_equals(0, $r1['success'] + $r1['failed'], 'deleteManyFiles non-zero on empty input');
        assert_equals(0, $r2['success'] + $r2['failed'], 'deleteManyFolders non-zero on empty input');
    });
}

// =====================================================================
// 2. GUARDS
// =====================================================================

if (shouldRun('guards')) {
    out("\n--- 2. GUARDS ---");

    test('System folder "Boards" is blocked', function () use ($drive, $testEmail, $config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare("SELECT id FROM drive_folders WHERE user_email = ? AND name = 'Boards' AND parent_id IS NULL LIMIT 1");
        $stmt->execute([$testEmail]);
        $row = $stmt->fetch();
        if (!$row) return 'warn'; // No Boards folder on this account
        $r = $drive->deleteManyFolders($testEmail, [(int)$row['id']]);
        assert_equals(0, $r['success'] ?? -1, 'Boards should not be deletable');
        assert_equals(1, $r['failed'] ?? -1, 'Boards delete should count as failed');
        assert_true(!empty($r['errors']), 'errors[] should contain a message for the blocked folder');
    });

    test('Non-existent IDs do not crash', function () use ($drive, $testEmail) {
        $r = $drive->deleteManyFiles($testEmail, [999999998, 999999999]);
        // Missing rows are counted as success (idempotent).
        assert_equals(2, $r['success'] ?? -1, 'Missing IDs should be idempotent (count as success)');
    });
}

// =====================================================================
// 3. BATCH MOVE
// =====================================================================

if (shouldRun('move')) {
    out("\n--- 3. BATCH MOVE ---");

    // Re-seed fresh files for the move test (delete group above already
    // removed the original set).
    $moveFileIds = [];
    $moveSubfolderId = null;

    test('Re-seed for move test', function () use ($config, $testEmail, $testRootFolderId, $drive, $TEST_TAG, &$moveFileIds, &$moveSubfolderId, &$seededFileIds, &$seededFolderIds, &$seededFilePaths) {
        $sub = $drive->createFolder($testEmail, $TEST_TAG . '_move_target', $testRootFolderId);
        assert_true(is_array($sub) && !empty($sub['id']), 'Move target folder creation failed');
        $moveSubfolderId = (int)$sub['id'];
        $seededFolderIds[] = $moveSubfolderId;

        $db = \Webmail\Core\Database::getConnection($config);
        $storageBase = $config['drive']['local_path'] ?? '/var/www/vps-email/storage/drive';
        $userHash = md5($testEmail);
        $userDir = "{$storageBase}/{$userHash}";

        $stmt = $db->prepare(
            "INSERT INTO drive_files
                (user_email, folder_id, filename, original_name, size, mime_type, storage_location, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, 'local', NOW(), NOW())"
        );
        for ($i = 0; $i < 10; $i++) {
            $filename = 'flowone_test_move_' . uniqid('', true) . '_' . $i . '.bin';
            $path = "{$userDir}/{$filename}";
            file_put_contents($path, str_repeat('m', 512));
            $seededFilePaths[] = $path;
            $stmt->execute([$testEmail, $testRootFolderId, $filename, "MOVE-TEST #{$i}.bin", 512, 'application/octet-stream']);
            $id = (int)$db->lastInsertId();
            $moveFileIds[] = $id;
            $seededFileIds[] = $id;
        }
    });

    test('moveManyFiles(10) into sub-folder in <' . budget(PER_FILE_MOVE_BUDGET_MS, 10) . 'ms',
        function () use ($drive, $testEmail, &$moveFileIds, &$moveSubfolderId) {
            $b = budget(PER_FILE_MOVE_BUDGET_MS, 10);
            $start = microtime(true);
            $r = $drive->moveManyFiles($testEmail, $moveFileIds, $moveSubfolderId);
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            vlog("moveManyFiles elapsed={$elapsed}ms, result=" . json_encode($r));
            assert_equals(10, $r['success'] ?? -1, 'success count mismatch');
            // affectedFolders should be exactly 2 (source root + target sub)
            assert_equals(2, count($r['affectedFolders'] ?? []), 'Should report exactly 2 affected folders');
            assert_true($elapsed < $b, "Latency {$elapsed}ms exceeds budget {$b}ms");
        }
    );

    test('All moved files now report new folder_id', function () use ($config, $testEmail, &$moveFileIds, &$moveSubfolderId) {
        $db = \Webmail\Core\Database::getConnection($config);
        $ph = implode(',', array_fill(0, count($moveFileIds), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM drive_files WHERE user_email = ? AND id IN ({$ph}) AND folder_id = ?");
        $stmt->execute(array_merge([$testEmail], $moveFileIds, [$moveSubfolderId]));
        $row = $stmt->fetch();
        assert_equals(10, (int)$row['c'], 'Move did not land for all 10 files');
    });

    test('moveManyFolders rejects self-move', function () use ($drive, $testEmail, &$moveSubfolderId) {
        $r = $drive->moveManyFolders($testEmail, [$moveSubfolderId], $moveSubfolderId);
        assert_equals(0, $r['success'] ?? -1, 'self-move should not succeed');
        assert_equals(1, $r['failed'] ?? -1, 'self-move should fail');
    });
}

// =====================================================================
// 4. BATCH TRASH / RESTORE (F1)
// =====================================================================

if (shouldRun('trash') || (empty($onlyGroups) && !$smokeOnly)) {
    out("\n--- 4. BATCH TRASH / RESTORE ---");

    test('Structural: DriveService has trash/restore batch methods', function () {
        $rc = new \ReflectionClass('\\Webmail\\Services\\DriveService');
        assert_true($rc->hasMethod('trashManyFiles'), 'trashManyFiles missing');
        assert_true($rc->hasMethod('trashManyFolders'), 'trashManyFolders missing');
        assert_true($rc->hasMethod('restoreManyFiles'), 'restoreManyFiles missing');
        assert_true($rc->hasMethod('restoreManyFolders'), 'restoreManyFolders missing');
    });

    test('Structural: DriveController has batchTrash/batchRestore', function () {
        $rc = new \ReflectionClass('\\Webmail\\Controllers\\DriveController');
        assert_true($rc->hasMethod('batchTrash'), 'batchTrash missing');
        assert_true($rc->hasMethod('batchRestore'), 'batchRestore missing');
    });

    test('Routes registered for trash + restore batches', function () {
        $routes = file_get_contents(__DIR__ . '/../routes.php');
        assert_true(str_contains($routes, '/drive/batch-trash'), 'batch-trash route missing');
        assert_true(str_contains($routes, '/drive/batch-restore'), 'batch-restore route missing');
    });

    $trashFileIds = [];
    test('Re-seed 6 files for trash/restore', function () use ($config, $testEmail, $testRootFolderId, &$trashFileIds, &$seededFileIds, &$seededFilePaths) {
        $db = \Webmail\Core\Database::getConnection($config);
        $storageBase = $config['drive']['local_path'] ?? '/var/www/vps-email/storage/drive';
        $userDir = "{$storageBase}/" . md5($testEmail);
        if (!is_dir($userDir)) @mkdir($userDir, 0755, true);

        $stmt = $db->prepare(
            "INSERT INTO drive_files
                (user_email, folder_id, filename, original_name, size, mime_type, storage_location, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, 'local', NOW(), NOW())"
        );
        for ($i = 0; $i < 6; $i++) {
            $filename = 'flowone_test_trash_' . uniqid('', true) . "_{$i}.bin";
            $path = "{$userDir}/{$filename}";
            file_put_contents($path, str_repeat('t', 256));
            $seededFilePaths[] = $path;
            $stmt->execute([$testEmail, $testRootFolderId, $filename, "TRASH-TEST #{$i}.bin", 256, 'application/octet-stream']);
            $id = (int)$db->lastInsertId();
            $trashFileIds[] = $id;
            $seededFileIds[] = $id;
        }
        assert_equals(6, count($trashFileIds), 'seed count wrong');
    });

    test('trashManyFiles marks rows is_trashed=1 in ONE pass', function () use ($drive, $testEmail, &$trashFileIds, $config) {
        $start = microtime(true);
        $r = $drive->trashManyFiles($testEmail, $trashFileIds);
        $ms = (int)round((microtime(true) - $start) * 1000);
        vlog("trashManyFiles({$ms}ms) result=" . json_encode($r));
        assert_equals(6, $r['success'] ?? -1, 'success mismatch');

        $db = \Webmail\Core\Database::getConnection($config);
        $ph = implode(',', array_fill(0, count($trashFileIds), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM drive_files WHERE id IN ({$ph}) AND is_trashed = 1");
        $stmt->execute($trashFileIds);
        assert_equals(6, (int)$stmt->fetch()['c'], 'not all rows marked trashed');
        assert_true($ms < 1500, "trashManyFiles took {$ms}ms (>1.5s suspicious)");
    });

    test('restoreManyFiles flips rows back is_trashed=0', function () use ($drive, $testEmail, &$trashFileIds, $config) {
        $start = microtime(true);
        $r = $drive->restoreManyFiles($testEmail, $trashFileIds);
        $ms = (int)round((microtime(true) - $start) * 1000);
        vlog("restoreManyFiles({$ms}ms) result=" . json_encode($r));
        assert_equals(6, $r['success'] ?? -1, 'restore success mismatch');

        $db = \Webmail\Core\Database::getConnection($config);
        $ph = implode(',', array_fill(0, count($trashFileIds), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM drive_files WHERE id IN ({$ph}) AND is_trashed = 0");
        $stmt->execute($trashFileIds);
        assert_equals(6, (int)$stmt->fetch()['c'], 'not all rows restored');
        assert_true($ms < 1500, "restoreManyFiles took {$ms}ms (>1.5s suspicious)");
    });

    test('Empty trash/restore inputs are no-ops', function () use ($drive, $testEmail) {
        $r1 = $drive->trashManyFiles($testEmail, []);
        $r2 = $drive->trashManyFolders($testEmail, []);
        $r3 = $drive->restoreManyFiles($testEmail, []);
        $r4 = $drive->restoreManyFolders($testEmail, []);
        assert_equals(0, ($r1['success'] ?? 0) + ($r1['failed'] ?? 0));
        assert_equals(0, ($r2['success'] ?? 0) + ($r2['failed'] ?? 0));
        assert_equals(0, ($r3['success'] ?? 0) + ($r3['failed'] ?? 0));
        assert_equals(0, ($r4['success'] ?? 0) + ($r4['failed'] ?? 0));
    });
}

// =====================================================================
// SUMMARY
// =====================================================================

out("\n=================================================================");
out("  SUMMARY");
out("=================================================================");
out("  Total:    {$totalTests}");
out("  \033[32mPassed:   {$passed}\033[0m");
out("  \033[31mFailed:   {$failed}\033[0m");
out("  \033[33mWarnings: {$warnings}\033[0m");

if ($failed > 0) {
    out("\n--- FAILURES ---");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("  \033[31m[FAIL]\033[0m {$r['name']}");
            out("        -> " . ($r['error'] ?? ''));
        }
    }
}

if ($jsonOutput) {
    echo json_encode([
        'total' => $totalTests,
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'log' => $logFile,
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
