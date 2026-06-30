#!/usr/bin/env php
<?php
/**
 * FlowOne Drive - View-Only Restrictions + Open History Test Suite
 *
 * Exercises the per-file no_download / no_print restriction flags (which apply
 * only to VIEW-access recipients) and the drive_file_access_log open history:
 *   - DriveService::getFileRestrictions / setFileRestrictions
 *   - DriveService::getEffectiveRole (owner / editor / viewer / anonymous)
 *   - DriveService::isViewerDownloadBlocked (viewers blocked, owner/editors not)
 *   - DriveService::logFileAccess / getFileAccessLog (who / when / how often)
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-restrictions-test.php \
 *       --email=admin@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required, must exist on this server)
 *   --only=group,group   Run only specific groups (schema,restrictions,enforcement,log,cleanup)
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
    echo "FlowOne Drive View-Only Restrictions + Open History Test Suite\n";
    echo "=============================================================\n\n";
    echo "Usage:\n";
    echo "  php drive-restrictions-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (must exist on this server)\n";
    echo "  --only=group,group   Run only specific groups (schema,restrictions,enforcement,log,cleanup)\n";
    echo "  --smoke              Quick health check (schema + connectivity)\n";
    echo "  --skip-send          (no-op, kept for parity)\n";
    echo "  --json               Output summary as JSON\n";
    echo "  --verbose            Extra debug output\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-restrictions-test.php \\\n";
    echo "      --email=admin@flowone.pro --verbose\n";
    exit(1);
}

$testEmail  = strtolower($opts['email']);
$smokeOnly  = isset($opts['smoke']);
$verbose    = isset($opts['verbose']);
$jsonOutput = isset($opts['json']);
$onlyGroups = !empty($opts['only']) ? explode(',', $opts['only']) : [];

$PER_TEST_TIMEOUT = 30;

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/drive-restrictions-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

// Test data, tracked for cleanup with a recognisable prefix.
$cleanupFileIds   = [];
$cleanupFolderIds = [];
$TEST_PREFIX      = 'flowone_test_restrict_';
$viewerEmail      = 'flowone_test_viewer_' . substr(md5((string)microtime(true)), 0, 8) . '@example.invalid';
$editorEmail      = 'flowone_test_editor_' . substr(md5((string)microtime(true) . 'e'), 0, 8) . '@example.invalid';

$RED    = "\033[0;31m";
$GREEN  = "\033[0;32m";
$YELLOW = "\033[1;33m";
$NC     = "\033[0m";

function out(string $msg): void {
    global $logFile, $jsonOutput;
    if ($jsonOutput) return;
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

// Cleanup -- always runs, even on crash. Access-log rows + collaborator rows
// cascade away when the file is deleted (FK ON DELETE CASCADE), but we also
// clean collaborator rows explicitly in case the file delete is skipped.
function doCleanup(): void {
    global $cleanupFileIds, $cleanupFolderIds, $testEmail, $config, $viewerEmail, $editorEmail;
    try {
        $drive = new \Webmail\Services\DriveService($config, $testEmail);
        $db = $drive->getDb();
        foreach ($cleanupFileIds as $id) {
            try {
                $db->prepare('DELETE FROM drive_file_collaborators WHERE file_id = ?')->execute([$id]);
                $db->prepare("DELETE FROM drive_file_access_log WHERE file_id = ?")->execute([$id]);
            } catch (\Throwable $e) {}
            try { $drive->deleteFile($testEmail, $id); } catch (\Throwable $e) {}
        }
        foreach (array_reverse($cleanupFolderIds) as $id) {
            try { $drive->deleteFolder($testEmail, $id); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        error_log('[Drive Restrictions Test Cleanup] ' . $e->getMessage());
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
out("  FlowOne Drive View-Only Restrictions + Open History Test Suite");
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

    test('drive_files.no_download column exists', function () use (&$db) {
        $row = $db->query("SHOW COLUMNS FROM drive_files LIKE 'no_download'")->fetch();
        assert_not_empty($row, 'drive_files.no_download missing (run migration 198)');
    });

    test('drive_files.no_print column exists', function () use (&$db) {
        $row = $db->query("SHOW COLUMNS FROM drive_files LIKE 'no_print'")->fetch();
        assert_not_empty($row, 'drive_files.no_print missing (run migration 198)');
    });

    test('drive_file_access_log table exists', function () use (&$db) {
        $row = $db->query("SHOW TABLES LIKE 'drive_file_access_log'")->fetch();
        assert_not_empty($row, 'drive_file_access_log missing (run migration 198)');
    });

    test('drive_file_access_log has file+user index', function () use (&$db) {
        $rows = $db->query("SHOW INDEX FROM drive_file_access_log WHERE Key_name = 'idx_dfal_file_user'")->fetchAll();
        assert_true(!empty($rows), 'idx_dfal_file_user missing -- migration 198 not applied');
    });
}

if ($smokeOnly) {
    goto summary;
}

$drive = new \Webmail\Services\DriveService($config, $testEmail);

// Seed: a folder + a file.
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
        '[FLOWONE-TEST] view-only restriction payload @ ' . date('c'),
        'text/plain',
        $seedFolderId
    );
    assert_true(is_array($file) && !empty($file['id']), 'uploadFileContent did not return id');
    $seedFileId = (int)$file['id'];
    $cleanupFileIds[] = $seedFileId;
});

// Seed: a viewer + an editor collaborator (direct rows so the test does not
// depend on the org colleague directory).
test('Seed: add viewer + editor collaborators', function () use (&$db, $seedFileId, $testEmail, $viewerEmail, $editorEmail) {
    $stmt = $db->prepare("INSERT INTO drive_file_collaborators (file_id, user_email, permission, invited_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([$seedFileId, $viewerEmail, 'viewer', $testEmail]);
    $stmt->execute([$seedFileId, $editorEmail, 'editor', $testEmail]);
    $row = $db->query("SELECT COUNT(*) AS c FROM drive_file_collaborators WHERE file_id = {$seedFileId}")->fetch();
    assert_equals(2, (int)$row['c'], 'Expected 2 collaborator rows');
});

// ── 2. Restrictions storage ──────────────────────────────────────

if (shouldRun('restrictions')) {
    out("\n--- 2. RESTRICTIONS STORAGE ---");

    test('getFileRestrictions defaults to false/false', function () use (&$drive, $seedFileId) {
        $r = $drive->getFileRestrictions($seedFileId);
        assert_not_empty($r !== null ? 'ok' : '', 'getFileRestrictions returned null');
        assert_equals(false, $r['no_download'], 'no_download should default false');
        assert_equals(false, $r['no_print'], 'no_print should default false');
    });

    test('setFileRestrictions(true,true) persists', function () use (&$drive, $testEmail, $seedFileId) {
        $ok = $drive->setFileRestrictions($testEmail, $seedFileId, true, true);
        assert_true($ok, 'setFileRestrictions returned false for owner');
        $r = $drive->getFileRestrictions($seedFileId);
        assert_equals(true, $r['no_download'], 'no_download not persisted');
        assert_equals(true, $r['no_print'], 'no_print not persisted');
    });

    test('setFileRestrictions rejects non-owner', function () use (&$drive, $seedFileId, $viewerEmail) {
        $ok = $drive->setFileRestrictions($viewerEmail, $seedFileId, false, false);
        assert_equals(false, $ok, 'Non-owner must not be able to change restrictions');
        // Confirm flags were not changed by the rejected call.
        $r = $drive->getFileRestrictions($seedFileId);
        assert_equals(true, $r['no_download'], 'Restrictions must remain unchanged after rejected set');
    });

    test('setFileRestrictions(false,false) resets', function () use (&$drive, $testEmail, $seedFileId) {
        $ok = $drive->setFileRestrictions($testEmail, $seedFileId, false, false);
        assert_true($ok, 'reset returned false');
        $r = $drive->getFileRestrictions($seedFileId);
        assert_equals(false, $r['no_download'], 'no_download not reset');
        assert_equals(false, $r['no_print'], 'no_print not reset');
    });
}

// ── 3. Enforcement (effective role + download block) ─────────────

if (shouldRun('enforcement')) {
    out("\n--- 3. ENFORCEMENT ---");

    test('effective role: owner', function () use (&$drive, $seedFileId, $testEmail) {
        assert_equals('owner', $drive->getEffectiveRole($seedFileId, $testEmail));
    });

    test('effective role: viewer', function () use (&$drive, $seedFileId, $viewerEmail) {
        assert_equals('viewer', $drive->getEffectiveRole($seedFileId, $viewerEmail));
    });

    test('effective role: editor', function () use (&$drive, $seedFileId, $editorEmail) {
        assert_equals('editor', $drive->getEffectiveRole($seedFileId, $editorEmail));
    });

    test('effective role: anonymous -> viewer', function () use (&$drive, $seedFileId) {
        assert_equals('viewer', $drive->getEffectiveRole($seedFileId, null));
    });

    test('effective role: unrelated user -> none', function () use (&$drive, $seedFileId) {
        assert_equals('none', $drive->getEffectiveRole($seedFileId, 'flowone_test_nobody@example.invalid'));
    });

    test('no_download blocks viewer but not owner/editor', function () use (&$drive, $testEmail, $seedFileId, $viewerEmail, $editorEmail) {
        $drive->setFileRestrictions($testEmail, $seedFileId, true, false);
        $file = $drive->getFile($testEmail, $seedFileId);
        assert_true(is_array($file), 'owner getFile failed');

        assert_equals(true,  $drive->isViewerDownloadBlocked($file, $viewerEmail), 'viewer should be blocked');
        assert_equals(true,  $drive->isViewerDownloadBlocked($file, null),         'anonymous should be blocked');
        assert_equals(false, $drive->isViewerDownloadBlocked($file, $editorEmail), 'editor must NOT be blocked');
        assert_equals(false, $drive->isViewerDownloadBlocked($file, $testEmail),   'owner must NOT be blocked');
    });

    test('no_download=false blocks nobody', function () use (&$drive, $testEmail, $seedFileId, $viewerEmail) {
        $drive->setFileRestrictions($testEmail, $seedFileId, false, false);
        $file = $drive->getFile($testEmail, $seedFileId);
        assert_equals(false, $drive->isViewerDownloadBlocked($file, $viewerEmail), 'viewer should NOT be blocked when no_download off');
        assert_equals(false, $drive->isViewerDownloadBlocked($file, null), 'anonymous should NOT be blocked when no_download off');
    });
}

// ── 4. Access log (who / when / how many times) ──────────────────

if (shouldRun('log')) {
    out("\n--- 4. ACCESS LOG ---");

    test('logFileAccess records opens', function () use (&$drive, $seedFileId, $viewerEmail, $editorEmail) {
        $drive->logFileAccess($seedFileId, $viewerEmail, 'open', '203.0.113.1', 'phpunit/agent');
        $drive->logFileAccess($seedFileId, $viewerEmail, 'open', '203.0.113.1', 'phpunit/agent');
        $drive->logFileAccess($seedFileId, $editorEmail, 'open', '203.0.113.2', 'phpunit/agent');
        // Non-'open' actions must NOT inflate the open history.
        $drive->logFileAccess($seedFileId, $viewerEmail, 'download_blocked', '203.0.113.1', 'phpunit/agent');
        return true;
    });

    test('getFileAccessLog aggregates per user (open only)', function () use (&$drive, $seedFileId, $viewerEmail, $editorEmail) {
        $entries = $drive->getFileAccessLog($seedFileId);
        assert_true(count($entries) >= 2, 'Expected at least 2 distinct viewers');

        $byEmail = [];
        foreach ($entries as $e) {
            $byEmail[$e['user_email']] = $e;
        }
        assert_true(isset($byEmail[$viewerEmail]), 'viewer entry missing');
        assert_true(isset($byEmail[$editorEmail]), 'editor entry missing');
        assert_equals(2, $byEmail[$viewerEmail]['open_count'], 'viewer open_count should be 2 (download_blocked excluded)');
        assert_equals(1, $byEmail[$editorEmail]['open_count'], 'editor open_count should be 1');
    });

    test('getFileAccessLog ordered by last_opened_at DESC', function () use (&$drive, $seedFileId, $viewerEmail) {
        // A fresh open for the viewer should float them to the top.
        sleep(1);
        $drive->logFileAccess($seedFileId, $viewerEmail, 'open', '203.0.113.1', 'phpunit/agent');
        $entries = $drive->getFileAccessLog($seedFileId);
        assert_true(!empty($entries), 'no entries');
        assert_equals($viewerEmail, $entries[0]['user_email'], 'most recent opener should be first');
    });

    test('access-log rows have first/last timestamps', function () use (&$drive, $seedFileId, $viewerEmail) {
        $entries = $drive->getFileAccessLog($seedFileId);
        $row = null;
        foreach ($entries as $e) { if ($e['user_email'] === $viewerEmail) { $row = $e; break; } }
        assert_not_empty($row, 'viewer row missing');
        assert_not_empty($row['first_opened_at'], 'first_opened_at empty');
        assert_not_empty($row['last_opened_at'], 'last_opened_at empty');
    });
}

// ── 5. Cleanup ───────────────────────────────────────────────────

summary:
out("\n--- CLEANUP ---");

test('Remove test data (files, folders, collaborators, log rows)', function () use (&$cleanupFileIds, &$cleanupFolderIds, $testEmail, $config) {
    $drive = new \Webmail\Services\DriveService($config, $testEmail);
    $db = $drive->getDb();
    $fc = 0; $folderC = 0;
    foreach ($cleanupFileIds as $id) {
        try { $db->prepare('DELETE FROM drive_file_collaborators WHERE file_id = ?')->execute([$id]); } catch (\Throwable $e) {}
        try { $db->prepare('DELETE FROM drive_file_access_log WHERE file_id = ?')->execute([$id]); } catch (\Throwable $e) {}
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
