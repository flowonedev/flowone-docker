#!/usr/bin/env php
<?php
/**
 * FlowOne Drive ↔ Email-source linking test
 *
 * Verifies that attachments saved to Drive from an email message are
 * tagged with their IMAP source (folder + uid + part) and that the
 * email view's status lookup endpoint returns them. Also exercises the
 * Share-link path the new "Saved to Drive" UI uses.
 *
 * Non-destructive: every row, file, and folder created by this test is
 * tracked and removed in the shutdown handler, even on failure or
 * Ctrl+C. All test artefacts are namespaced with the [FLOWONE-TEST]
 * prefix so they cannot be confused with real user data.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-email-source-test.php \
 *     --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL     Test account email (required)
 *   --password=PASS   Test account password (required, currently unused
 *                     -- accepted for parity with the rest of the test
 *                     suite and for future IMAP roundtrip checks)
 *   --only=group,...  Run only specific groups (schema, save, status,
 *                                                upload, fallback, share)
 *   --smoke           Connectivity / schema check only
 *   --skip-send       (no-op for this test, no external sends)
 *   --json            Output a JSON summary at the end
 *   --verbose         Extra debug output
 *   --help            Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', [
    'email:', 'password:', 'only:', 'smoke', 'skip-send', 'json', 'verbose', 'help',
]);

if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Drive ↔ Email-source linking test\n";
    echo "==========================================\n\n";
    echo "Usage:\n";
    echo "  php drive-email-source-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL       Test account email (required)\n";
    echo "  --password=PASS     (accepted for parity, currently unused)\n";
    echo "  --only=group,...    Subset of: schema,save,status,upload,fallback,share\n";
    echo "  --smoke             Connectivity / schema check only\n";
    echo "  --skip-send         No-op (kept for suite consistency)\n";
    echo "  --json              JSON summary\n";
    echo "  --verbose           Extra debug output\n";
    echo "  --help              Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php \\\n";
    echo "    /var/www/vps-email/backend/tests/drive-email-source-test.php \\\n";
    echo "    --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(1);
}

$testEmail   = strtolower($opts['email']);
$smokeOnly   = isset($opts['smoke']);
$verbose     = isset($opts['verbose']);
$jsonOut     = isset($opts['json']);
$onlyGroups  = !empty($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : [];

// Per-test timeout (seconds) — guards against runaway DB calls.
$TEST_TIMEOUT = 30;

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/drive-email-source-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

// Track test artefacts for cleanup. All rows/files inserted by this
// script land in these arrays and are removed by doCleanup() on exit.
$cleanupFileIds   = [];
$cleanupFolderIds = [];
$cleanupPaths     = [];

$RED    = "\033[0;31m";
$GREEN  = "\033[0;32m";
$YELLOW = "\033[1;33m";
$NC     = "\033[0m";

function out(string $msg): void {
    global $logFile;
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
    return in_array($group, $onlyGroups, true);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose,
           $GREEN, $RED, $YELLOW, $NC, $TEST_TIMEOUT;

    $totalTests++;
    $start = microtime(true);

    // Per-test wall-clock guard so a hanging DB call cannot stall the
    // whole suite. We set the time limit before the closure runs and
    // restore it after.
    $previousLimit = (int) ini_get('max_execution_time');
    @set_time_limit($TEST_TIMEOUT);

    try {
        $result  = $fn();
        $elapsed = (int) round((microtime(true) - $start) * 1000);
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
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        $failed++;
        out("  {$RED}[FAIL]{$NC}  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = [
            'name' => $name, 'status' => 'FAIL',
            'ms' => $elapsed, 'error' => $e->getMessage(),
        ];
    } finally {
        @set_time_limit($previousLimit > 0 ? $previousLimit : 0);
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
        $detail = $msg ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
        throw new \RuntimeException($detail);
    }
}

// ── Cleanup ──────────────────────────────────────────────────────

function doCleanup(): void {
    global $cleanupFileIds, $cleanupFolderIds, $cleanupPaths, $testEmail, $config;
    if (empty($cleanupFileIds) && empty($cleanupFolderIds) && empty($cleanupPaths)) return;

    try {
        $drive = new \Webmail\Services\DriveService($config, $testEmail);
        foreach ($cleanupFileIds as $id) {
            try { $drive->deleteFile($testEmail, $id); } catch (\Throwable $e) {}
        }
        foreach ($cleanupFolderIds as $id) {
            try { $drive->deleteFolder($testEmail, $id); } catch (\Throwable $e) {}
        }
        foreach ($cleanupPaths as $path) {
            if (is_string($path) && file_exists($path)) @unlink($path);
        }
    } catch (\Throwable $e) {
        error_log('[drive-email-source-test cleanup] ' . $e->getMessage());
    }
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ── Header ───────────────────────────────────────────────────────

out("=================================================================");
out("  FlowOne Drive ↔ Email-source linking test");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: {$testEmail}");
out("  Mode:    " . ($smokeOnly ? 'SMOKE (schema/connectivity only)' : 'FULL'));
if (!empty($onlyGroups)) out('  Groups:  ' . implode(', ', $onlyGroups));
out("  Log:     {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out('--- PRE-FLIGHT ---');

test('PHP extensions loaded', function () {
    $required = ['pdo', 'pdo_mysql', 'mbstring', 'fileinfo'];
    $missing  = [];
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

test('DriveService instantiates', function () use ($config, $testEmail) {
    $drive = new \Webmail\Services\DriveService($config, $testEmail);
    assert_true($drive instanceof \Webmail\Services\DriveService, 'Wrong type');
});

// ── 1. Schema (migration 169 columns) ────────────────────────────

if (shouldRun('schema')) {
    out("\n--- 1. SCHEMA (migration 169) ---");

    test('drive_files.source_email_folder column exists', function () use (&$db) {
        if (!$db) throw new \RuntimeException('No DB');
        $stmt = $db->query("SHOW COLUMNS FROM drive_files LIKE 'source_email_folder'");
        assert_true($stmt->rowCount() > 0, 'Column missing — migration 169 has not run');
    });

    test('drive_files.source_email_uid column exists', function () use (&$db) {
        $stmt = $db->query("SHOW COLUMNS FROM drive_files LIKE 'source_email_uid'");
        assert_true($stmt->rowCount() > 0, 'Column missing — migration 169 has not run');
    });

    test('drive_files.source_email_part column exists', function () use (&$db) {
        $stmt = $db->query("SHOW COLUMNS FROM drive_files LIKE 'source_email_part'");
        assert_true($stmt->rowCount() > 0, 'Column missing — migration 169 has not run');
    });

    test('idx_drive_files_email_source index exists', function () use (&$db) {
        $stmt = $db->query("SHOW INDEX FROM drive_files WHERE Key_name = 'idx_drive_files_email_source'");
        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) return 'warn'; // Soft warn: lookup still works without it.
        assert_true($rowCount >= 3, 'Composite index should cover 3 columns');
    });
}

if ($smokeOnly) {
    goto SUMMARY;
}

// ── 2. saveEmailAttachment → tagged in drive_files ───────────────

$drive = new \Webmail\Services\DriveService($config, $testEmail);

// Synthetic IMAP coordinates. Uses test prefix so any leak is
// recognisable, and a UID well above any real INBOX UID range.
$srcFolder = 'INBOX';
$srcUid    = 990000000 + random_int(1, 999999);
$srcPart   = '2.1';

if (shouldRun('save')) {
    out("\n--- 2. SAVE EMAIL ATTACHMENT (tags source) ---");

    $savedFileId = null;
    $savedFolderId = null;

    test('saveEmailAttachment persists source IMAP coordinates',
        function () use ($drive, $testEmail, $srcFolder, $srcUid, $srcPart, &$savedFileId, &$savedFolderId, &$cleanupFileIds, &$cleanupFolderIds) {
            $filename = '[FLOWONE-TEST] drive-email-source.txt';
            $content  = '[FLOWONE-TEST] body for drive↔email linking ' . bin2hex(random_bytes(8));
            $result = $drive->saveEmailAttachment(
                $testEmail,
                $filename,
                $content,
                'text/plain',
                '[FLOWONE-TEST] Source linking',
                date('Y-m-d'),
                null,
                $srcFolder,
                $srcUid,
                $srcPart
            );
            assert_true(is_array($result), 'saveEmailAttachment returned non-array');
            assert_not_empty($result['file'] ?? null, 'No file in result');
            $savedFileId   = (int) $result['file']['id'];
            $savedFolderId = (int) ($result['folder']['id'] ?? 0);
            $cleanupFileIds[] = $savedFileId;
            if ($savedFolderId) $cleanupFolderIds[] = $savedFolderId;
        }
    );

    test('saved row has source_email_folder/uid/part columns set',
        function () use (&$db, $testEmail, &$savedFileId, $srcFolder, $srcUid, $srcPart) {
            if (!$savedFileId) throw new \RuntimeException('No saved file id from previous test');
            $stmt = $db->prepare(
                'SELECT source_email_folder, source_email_uid, source_email_part
                   FROM drive_files
                  WHERE user_email = ? AND id = ?'
            );
            $stmt->execute([$testEmail, $savedFileId]);
            $row = $stmt->fetch();
            assert_true(is_array($row), 'Row not found');
            assert_equals($srcFolder, $row['source_email_folder'], 'source_email_folder mismatch');
            assert_equals($srcUid, (int) $row['source_email_uid'], 'source_email_uid mismatch');
            assert_equals($srcPart, $row['source_email_part'], 'source_email_part mismatch');
        }
    );
}

// ── 3. getEmailAttachmentSavedFiles lookup ───────────────────────

if (shouldRun('status')) {
    out("\n--- 3. STATUS LOOKUP ---");

    test('getEmailAttachmentSavedFiles returns the saved file',
        function () use ($drive, $testEmail, $srcFolder, $srcUid, $srcPart) {
            $files = $drive->getEmailAttachmentSavedFiles($testEmail, $srcFolder, $srcUid);
            assert_true(is_array($files), 'Not an array');
            assert_true(count($files) >= 1, 'Expected at least one saved file');
            $matched = null;
            foreach ($files as $f) {
                if (($f['part'] ?? null) === $srcPart) { $matched = $f; break; }
            }
            assert_not_empty($matched, 'No row matched by source_email_part');
            assert_not_empty($matched['id'], 'Result missing file id');
            assert_not_empty($matched['filename'], 'Result missing filename');
        }
    );

    test('lookup with wrong uid returns empty', function () use ($drive, $testEmail, $srcFolder) {
        $files = $drive->getEmailAttachmentSavedFiles($testEmail, $srcFolder, 1);
        assert_true(is_array($files), 'Not an array');
        assert_equals(0, count($files), 'Expected zero results for unrelated uid');
    });

    test('lookup with empty folder returns empty', function () use ($drive, $testEmail, $srcUid) {
        $files = $drive->getEmailAttachmentSavedFiles($testEmail, '', $srcUid);
        assert_equals(0, count($files), 'Expected zero results for empty folder');
    });
}

// ── 4. uploadFileContent path also tags source ───────────────────

if (shouldRun('upload')) {
    out("\n--- 4. uploadFileContent (SaveToDriveModal path) ---");

    $uploadFileId = null;

    test('uploadFileContent persists source IMAP coordinates',
        function () use ($drive, $testEmail, &$uploadFileId, &$cleanupFileIds) {
            $folder = 'INBOX.[FLOWONE-TEST]';
            $uid    = 990000000 + random_int(1, 999999);
            $part   = '3';
            $name   = '[FLOWONE-TEST] modal-path.txt';
            $content = '[FLOWONE-TEST] modal path body ' . bin2hex(random_bytes(6));

            $file = $drive->uploadFileContent(
                $testEmail,
                $name,
                $content,
                'text/plain',
                null,
                $folder,
                $uid,
                $part
            );
            assert_true(is_array($file), 'uploadFileContent returned non-array');
            $uploadFileId = (int) $file['id'];
            $cleanupFileIds[] = $uploadFileId;

            // Round-trip via the lookup to confirm tagging.
            $files = $drive->getEmailAttachmentSavedFiles($testEmail, $folder, $uid);
            $matched = null;
            foreach ($files as $f) {
                if (($f['part'] ?? null) === $part) { $matched = $f; break; }
            }
            assert_not_empty($matched, 'uploadFileContent did not tag source row');
            assert_equals($uploadFileId, (int) $matched['id'], 'Lookup returned wrong file id');
        }
    );

    test('uploadFileContent without source still works (back-compat)',
        function () use ($drive, $testEmail, &$cleanupFileIds) {
            $name    = '[FLOWONE-TEST] no-source.txt';
            $content = '[FLOWONE-TEST] no source body';
            $file = $drive->uploadFileContent($testEmail, $name, $content, 'text/plain');
            assert_true(is_array($file), 'uploadFileContent returned non-array');
            $cleanupFileIds[] = (int) $file['id'];
        }
    );
}

// ── 4b. Filename+size fallback (legacy data resolution) ──────────

if (shouldRun('fallback')) {
    out("\n--- 4b. FALLBACK MATCH (legacy / untagged rows) ---");

    test('resolveSavedFilesForEmailMessage matches legacy file by filename+size and backfills source columns',
        function () use ($drive, $testEmail, &$db, &$cleanupFileIds, &$cleanupFolderIds) {
            // Seed a row that mimics a pre-migration save: file lives
            // inside an "Attachments" folder lineage but has NULL
            // source_email_* columns. Reuses the canonical root
            // "Attachments" folder so we don't fight UNIQUE constraints
            // or pollute the user's tree with test-named copies.
            $atts = $drive->getOrCreateAttachmentsFolder($testEmail);
            assert_not_empty($atts, 'Failed to get/create root Attachments folder');

            $sub = $drive->createFolder($testEmail, '[FLOWONE-TEST] 2026-01-01 Subject ' . bin2hex(random_bytes(3)), (int) $atts['id']);
            assert_not_empty($sub, 'Failed to create subject folder');
            $cleanupFolderIds[] = (int) $sub['id'];

            $name = '[FLOWONE-TEST] legacy-fallback-' . bin2hex(random_bytes(3)) . '.txt';
            $body = '[FLOWONE-TEST] legacy fallback body ' . bin2hex(random_bytes(8));

            $file = $drive->uploadFileContent($testEmail, $name, $body, 'text/plain', (int) $sub['id']);
            assert_true(is_array($file), 'uploadFileContent failed');
            $cleanupFileIds[] = (int) $file['id'];

            // Confirm the seed row has NULL source columns (it would,
            // since uploadFileContent was called without source args).
            $check = $db->prepare('SELECT source_email_uid FROM drive_files WHERE id = ?');
            $check->execute([(int) $file['id']]);
            $sourceUidNow = $check->fetchColumn();
            assert_true($sourceUidNow === null || $sourceUidNow === false || (int) $sourceUidNow === 0,
                'Seed row should not have a source_email_uid yet, got: ' . var_export($sourceUidNow, true));

            // Now ask the resolver for the IMAP coordinates the user
            // would *like* this file to be linked to. Filename+size
            // match should fire and the row should be backfilled.
            $folder = 'INBOX';
            $uid    = 990000000 + random_int(1, 999999);
            $part   = '4';
            $resolved = $drive->resolveSavedFilesForEmailMessage($testEmail, $folder, $uid, [
                ['part' => $part, 'filename' => $name, 'size' => strlen($body)],
            ]);
            assert_true(is_array($resolved), 'Not an array');
            $matched = null;
            foreach ($resolved as $r) {
                if (($r['part'] ?? null) === $part) { $matched = $r; break; }
            }
            assert_not_empty($matched, 'Fallback match did not return the file');
            assert_true(!empty($matched['fallback']), 'Result should be flagged fallback=true');
            assert_equals((int) $file['id'], (int) $matched['id'], 'Wrong file id matched');

            // Self-heal: the row should now have source columns set.
            $check2 = $db->prepare('SELECT source_email_folder, source_email_uid, source_email_part FROM drive_files WHERE id = ?');
            $check2->execute([(int) $file['id']]);
            $row = $check2->fetch();
            assert_equals($folder, $row['source_email_folder'], 'Backfill missed source_email_folder');
            assert_equals($uid, (int) $row['source_email_uid'], 'Backfill missed source_email_uid');
            assert_equals($part, $row['source_email_part'], 'Backfill missed source_email_part');
        }
    );

    test('resolveSavedFilesForEmailMessage does not match a random file with same size by name when ambiguous',
        function () use ($drive, $testEmail, &$cleanupFileIds) {
            // Create two unrelated files with identical name+size at the
            // Drive root (no Attachments lineage). The resolver should
            // refuse to pick one, since picking arbitrarily would risk
            // false positives.
            $name = '[FLOWONE-TEST] ambiguous-' . bin2hex(random_bytes(3)) . '.txt';
            $body = '[FLOWONE-TEST] ambiguous body ' . bin2hex(random_bytes(8));
            $a = $drive->uploadFileContent($testEmail, $name, $body, 'text/plain');
            $b = $drive->uploadFileContent($testEmail, $name, $body, 'text/plain');
            assert_true(is_array($a) && is_array($b), 'Seed uploads failed');
            $cleanupFileIds[] = (int) $a['id'];
            $cleanupFileIds[] = (int) $b['id'];

            $resolved = $drive->resolveSavedFilesForEmailMessage(
                $testEmail,
                'INBOX',
                990000000 + random_int(1, 999999),
                [['part' => '5', 'filename' => $name, 'size' => strlen($body)]]
            );
            $matched = null;
            foreach ($resolved as $r) {
                if (($r['part'] ?? null) === '5') { $matched = $r; break; }
            }
            assert_true($matched === null, 'Resolver picked a match despite ambiguity');
        }
    );
}

// ── 5. Share link round-trip on a saved-from-email file ──────────

if (shouldRun('share')) {
    out("\n--- 5. SHARE LINK ---");

    test('createShareLink returns a token for an email-tagged file',
        function () use ($drive, $testEmail, $srcFolder, $srcUid) {
            $files = $drive->getEmailAttachmentSavedFiles($testEmail, $srcFolder, $srcUid);
            assert_true(count($files) >= 1, 'No saved file to share — earlier tests may have failed');
            $fileId = (int) $files[0]['id'];

            $token = $drive->createShareLink($testEmail, $fileId, 24, false, null, null);
            assert_not_empty($token, 'createShareLink returned no token');
            assert_true(is_string($token) && strlen($token) >= 16, 'Share token looks malformed');

            // Lookup again and confirm the token is now in the row.
            $refreshed = $drive->getEmailAttachmentSavedFiles($testEmail, $srcFolder, $srcUid);
            $tokenInRow = null;
            foreach ($refreshed as $f) {
                if ((int) $f['id'] === $fileId) { $tokenInRow = $f['share_token'] ?? null; break; }
            }
            assert_equals($token, $tokenInRow, 'Status lookup did not surface share_token');
        }
    );
}

// ── Summary ──────────────────────────────────────────────────────

SUMMARY:

out("\n=================================================================");
out("  Summary");
out("=================================================================");
out("  Total:    {$totalTests}");
out("  Passed:   {$passed}");
out("  Warnings: {$warnings}");
out("  Failed:   {$failed}");
out("  Log:      {$logFile}");

if ($failed > 0) {
    out("\n  Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out('    - ' . $r['name'] . ' :: ' . ($r['error'] ?? ''));
        }
    }
}

if ($jsonOut) {
    echo "\n" . json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'warnings' => $warnings,
        'failed'   => $failed,
        'log'      => $logFile,
        'results'  => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
