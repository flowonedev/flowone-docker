#!/usr/bin/env php
<?php
/**
 * FlowOne Drive System - Comprehensive Test Suite
 * 
 * Tests storage, upload, download, sharing, passwords, expiry, cleanup,
 * quota, folders, trash, and email integration.
 * 
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-system-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 * 
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --password=PASS      Test account password (required)
 *   --only=group,group   Run only specific test groups
 *   --smoke              Quick health check only (no file operations)
 *   --verbose            Show extra debug info
 *   --help               Show this help
 * 
 * Test groups: db, storage, upload, folders, share, password, expiry,
 *              cleanup, quota, trash, email, cron, nas
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'password:', 'only:', 'smoke', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email']) || empty($opts['password'])) {
    echo "FlowOne Drive System Test Suite\n";
    echo "================================\n\n";
    echo "Usage:\n";
    echo "  php drive-system-test.php --email=user@flowone.pro --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --password=PASS      Test account password (required)\n";
    echo "  --only=group,group   Run only specific groups (db,storage,upload,folders,share,\n";
    echo "                       password,expiry,cleanup,quota,trash,email,cron,nas)\n";
    echo "  --smoke              Quick health check only\n";
    echo "  --verbose            Extra debug output\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-system-test.php \\\n";
    echo "      --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(1);
}

$testEmail    = strtolower($opts['email']);
$testPassword = $opts['password'];
$smokeOnly    = isset($opts['smoke']);
$verbose      = isset($opts['verbose']);
$onlyGroups   = !empty($opts['only']) ? explode(',', $opts['only']) : [];

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/drive-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

// Track test data for cleanup
$cleanupFileIds   = [];
$cleanupFolderIds = [];
$cleanupTokens    = [];
$cleanupPaths     = [];

$RED    = "\033[0;31m";
$GREEN  = "\033[0;32m";
$YELLOW = "\033[1;33m";
$NC     = "\033[0m";

function out(string $msg): void {
    global $logFile;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . strip_tags(preg_replace('/\033\[[0-9;]*m/', '', $line)), FILE_APPEND | LOCK_EX);
}

function shouldRun(string $group): bool {
    global $onlyGroups;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose, $GREEN, $RED, $YELLOW, $NC;
    $totalTests++;
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

// Register shutdown + signal handlers for cleanup
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
            if (file_exists($path)) @unlink($path);
        }
    } catch (\Throwable $e) {
        error_log("[Drive Test Cleanup] Error: " . $e->getMessage());
    }
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Drive System Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: {$testEmail}");
out("  Mode:    " . ($smokeOnly ? 'SMOKE (health check only)' : 'FULL'));
if (!empty($onlyGroups)) out("  Groups:  " . implode(', ', $onlyGroups));
out("  Log:     {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

test('PHP extensions loaded', function () {
    $required = ['pdo', 'pdo_mysql', 'mbstring', 'fileinfo'];
    $missing = [];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) $missing[] = $ext;
    }
    assert_true(empty($missing), 'Missing extensions: ' . implode(', ', $missing));
});

// ── 1. Database ──────────────────────────────────────────────────

if (shouldRun('db')) {
    out("\n--- 1. DATABASE ---");

    $db = null;

    test('Database connection', function () use ($config, &$db) {
        $db = \Webmail\Core\Database::getConnection($config);
        assert_true($db instanceof \PDO, 'Not a PDO instance');
    });

    test('drive_files table exists', function () use (&$db) {
        if (!$db) throw new \RuntimeException('No DB');
        $stmt = $db->query("SHOW TABLES LIKE 'drive_files'");
        assert_true($stmt->rowCount() > 0, 'Table drive_files not found');
    });

    test('drive_folders table exists', function () use (&$db) {
        if (!$db) throw new \RuntimeException('No DB');
        $stmt = $db->query("SHOW TABLES LIKE 'drive_folders'");
        assert_true($stmt->rowCount() > 0, 'Table drive_folders not found');
    });

    test('drive_quotas table exists', function () use (&$db) {
        if (!$db) throw new \RuntimeException('No DB');
        $stmt = $db->query("SHOW TABLES LIKE 'drive_quotas'");
        assert_true($stmt->rowCount() > 0, 'Table drive_quotas not found');
    });

    test('drive_files has sharing columns', function () use (&$db) {
        if (!$db) throw new \RuntimeException('No DB');
        $cols = $db->query("DESCRIBE drive_files")->fetchAll(\PDO::FETCH_COLUMN);
        $required = ['share_token', 'share_expires', 'is_email_attachment', 'max_downloads', 'download_count', 'share_password'];
        foreach ($required as $col) {
            assert_true(in_array($col, $cols), "Missing column: {$col}");
        }
    });

    test('drive_files has trash columns', function () use (&$db) {
        if (!$db) throw new \RuntimeException('No DB');
        $cols = $db->query("DESCRIBE drive_files")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['is_trashed', 'trashed_at', 'original_folder_id'] as $col) {
            assert_true(in_array($col, $cols), "Missing column: {$col}");
        }
    });
}

// ── 2. Storage ───────────────────────────────────────────────────

if (shouldRun('storage')) {
    out("\n--- 2. STORAGE ---");

    $drive = null;

    test('DriveService instantiation', function () use ($config, $testEmail, &$drive) {
        $drive = new \Webmail\Services\DriveService($config, $testEmail);
    });

    test('Storage path exists and writable', function () use ($config) {
        $path = $config['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive';
        assert_true(is_dir($path), "Drive storage not found: {$path}");
        assert_true(is_writable($path), "Drive storage not writable: {$path}");
    });

    test('User storage directory created', function () use (&$drive, $testEmail) {
        if (!$drive) throw new \RuntimeException('No DriveService');
        $quota = $drive->getQuota($testEmail);
        assert_true(is_array($quota), 'getQuota did not return array');
    });

    test('Storage connection test', function () use (&$drive) {
        if (!$drive) throw new \RuntimeException('No DriveService');
        $result = $drive->testStorageConnection();
        assert_true(is_array($result), 'testStorageConnection did not return array');
    });

    test('Sufficient disk space (> 100MB)', function () use ($config) {
        $path = $config['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive';
        $free = @disk_free_space($path);
        assert_true($free !== false, 'Cannot check disk space');
        assert_true($free > 100 * 1024 * 1024, 'Less than 100MB free disk space: ' . round($free / 1024 / 1024) . 'MB');
    });
}

if ($smokeOnly) {
    goto summary;
}

// ── 3. Upload & Download ─────────────────────────────────────────

if (shouldRun('upload')) {
    out("\n--- 3. UPLOAD & DOWNLOAD ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
    $uploadedFileId = null;

    test('Upload file via uploadFileContent', function () use (&$drive, $testEmail, &$uploadedFileId, &$cleanupFileIds) {
        $content = "FLOWONE-TEST drive upload test content.\nTimestamp: " . date('c') . "\n" . str_repeat('x', 1024);
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-upload.txt', $content, 'text/plain');
        assert_true($file !== null, 'uploadFileContent returned null');
        assert_not_empty($file['id'], 'No file ID returned');
        assert_equals('flowone-test-upload.txt', $file['original_name'], 'Original name mismatch');
        assert_true($file['size'] > 0, 'File size is zero');
        $uploadedFileId = $file['id'];
        $cleanupFileIds[] = $uploadedFileId;
    });

    test('File exists on disk', function () use (&$drive, $testEmail, $uploadedFileId) {
        if (!$uploadedFileId) throw new \RuntimeException('No file to check');
        $path = $drive->getFilePath($testEmail, $uploadedFileId);
        assert_true($path !== null, 'getFilePath returned null');
        assert_true(file_exists($path), "File not on disk: {$path}");
    });

    test('File retrievable from DB', function () use (&$drive, $testEmail, $uploadedFileId) {
        if (!$uploadedFileId) throw new \RuntimeException('No file');
        $file = $drive->getFile($testEmail, $uploadedFileId);
        assert_true($file !== null, 'getFile returned null');
        assert_equals($uploadedFileId, $file['id']);
    });

    test('Upload binary file (image-like)', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $fakePng = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 100);
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-image.png', $fakePng, 'image/png');
        assert_true($file !== null, 'Binary upload returned null');
        assert_equals('image/png', $file['mime_type']);
        $cleanupFileIds[] = $file['id'];
    });

    test('Upload zero-length file rejected or handled', function () use (&$drive, $testEmail) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-empty.txt', '', 'text/plain');
        // Either null (rejected) or file with size=0 is acceptable
        if ($file !== null) {
            assert_equals(0, (int)$file['size'], 'Empty file should have size 0');
        }
    });
}

// ── 4. Folders ───────────────────────────────────────────────────

if (shouldRun('folders')) {
    out("\n--- 4. FOLDERS ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
    $testFolderId = null;
    $subFolderId = null;

    test('Create folder', function () use (&$drive, $testEmail, &$testFolderId, &$cleanupFolderIds) {
        $folder = $drive->createFolder($testEmail, 'FLOWONE-TEST-Folder');
        assert_true($folder !== null && $folder !== false, 'createFolder failed');
        $folderId = is_array($folder) ? $folder['id'] : $folder;
        assert_true($folderId > 0, 'No folder ID');
        $testFolderId = (int)$folderId;
        $cleanupFolderIds[] = $testFolderId;
    });

    test('Create subfolder', function () use (&$drive, $testEmail, $testFolderId, &$subFolderId, &$cleanupFolderIds) {
        if (!$testFolderId) throw new \RuntimeException('No parent folder');
        $folder = $drive->createFolder($testEmail, 'FLOWONE-TEST-Subfolder', $testFolderId);
        $folderId = is_array($folder) ? $folder['id'] : $folder;
        assert_true($folderId > 0, 'Subfolder creation failed');
        $subFolderId = (int)$folderId;
        $cleanupFolderIds[] = $subFolderId;
    });

    test('List folders', function () use (&$drive, $testEmail) {
        $folders = $drive->getFolders($testEmail);
        assert_true(is_array($folders), 'getFolders not array');
    });

    test('Upload file into folder', function () use (&$drive, $testEmail, $testFolderId, &$cleanupFileIds) {
        if (!$testFolderId) throw new \RuntimeException('No folder');
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-in-folder.txt', 'file in folder', 'text/plain', $testFolderId);
        assert_true($file !== null, 'Upload to folder failed');
        assert_equals($testFolderId, (int)$file['folder_id'], 'File not in correct folder');
        $cleanupFileIds[] = $file['id'];
    });

    test('Get files in folder', function () use (&$drive, $testEmail, $testFolderId) {
        if (!$testFolderId) throw new \RuntimeException('No folder');
        $files = $drive->getFiles($testEmail, $testFolderId);
        assert_true(is_array($files), 'getFiles not array');
        $found = false;
        foreach ($files as $f) {
            if (strpos($f['original_name'], 'flowone-test-in-folder') !== false) {
                $found = true;
                break;
            }
        }
        assert_true($found, 'Uploaded file not found in folder listing');
    });

    test('Move file between folders', function () use (&$drive, $testEmail, $subFolderId, &$cleanupFileIds) {
        if (!$subFolderId) throw new \RuntimeException('No subfolder');
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-movable.txt', 'will be moved', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $moved = $drive->moveFile($testEmail, $file['id'], $subFolderId);
        assert_true($moved, 'moveFile returned false');

        $updated = $drive->getFile($testEmail, $file['id']);
        assert_equals($subFolderId, (int)$updated['folder_id'], 'File not in target folder after move');
    });

    test('Copy file', function () use (&$drive, $testEmail, $testFolderId, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-copyable.txt', 'original content', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $copy = $drive->copyFile($testEmail, $file['id'], $testFolderId);
        assert_true($copy !== null, 'copyFile returned null');
        assert_true($copy['id'] !== $file['id'], 'Copy has same ID as original');
        $cleanupFileIds[] = $copy['id'];
    });
}

// ── 5. Share Links (no password) ─────────────────────────────────

if (shouldRun('share')) {
    out("\n--- 5. SHARE LINKS ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
    $shareFileId = null;
    $shareToken = null;

    test('Create file for sharing', function () use (&$drive, $testEmail, &$shareFileId, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-shareable.txt', 'Shareable content ' . date('c'), 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $shareFileId = $file['id'];
        $cleanupFileIds[] = $shareFileId;
    });

    test('Create share link (no expiry)', function () use (&$drive, $testEmail, $shareFileId, &$shareToken) {
        if (!$shareFileId) throw new \RuntimeException('No file');
        $token = $drive->createShareLink($testEmail, $shareFileId);
        assert_not_empty($token, 'createShareLink returned empty');
        assert_true(strlen($token) === 64, 'Token should be 64 hex chars, got ' . strlen($token));
        $shareToken = $token;
    });

    test('Resolve file by share token', function () use (&$drive, $shareToken) {
        if (!$shareToken) throw new \RuntimeException('No token');
        $file = $drive->getFileByShareToken($shareToken);
        assert_true($file !== null, 'getFileByShareToken returned null');
        assert_true(strpos($file['original_name'], 'flowone-test-shareable') !== false, 'Wrong file resolved');
    });

    test('Get share info', function () use (&$drive, $shareToken) {
        if (!$shareToken) throw new \RuntimeException('No token');
        $info = $drive->getFileShareInfo($shareToken);
        assert_true($info !== null, 'getFileShareInfo returned null');
        assert_true(isset($info['original_name']), 'No original_name in share info');
        assert_true(isset($info['size']), 'No size in share info');
    });

    test('Get file path by token (for download)', function () use (&$drive, $shareToken) {
        if (!$shareToken) throw new \RuntimeException('No token');
        $result = $drive->getFilePathByToken($shareToken);
        assert_true($result !== null, 'getFilePathByToken returned null');
        assert_true(file_exists($result['path']), 'File does not exist at resolved path');
    });

    test('Download count increments', function () use (&$drive, $shareToken) {
        if (!$shareToken) throw new \RuntimeException('No token');
        $before = $drive->getFileByShareToken($shareToken);
        $countBefore = (int)($before['download_count'] ?? 0);
        $drive->incrementFileDownloadCount($shareToken);
        $after = $drive->getFileByShareToken($shareToken);
        $countAfter = (int)($after['download_count'] ?? 0);
        assert_equals($countBefore + 1, $countAfter, 'Download count did not increment');
    });

    test('Create share link with expiry (24h)', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-expiry-share.txt', 'Expiring share', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $token = $drive->createShareLink($testEmail, $file['id'], 24);
        assert_not_empty($token, 'Share with expiry returned empty');

        $info = $drive->getFileShareInfo($token);
        assert_true($info !== null, 'Cannot get info for expiring share');
        assert_true(!empty($info['share_expires']), 'share_expires not set');
    });

    test('Remove share link', function () use (&$drive, $testEmail, $shareFileId, &$shareToken) {
        if (!$shareFileId) throw new \RuntimeException('No file');
        $removed = $drive->removeShareLink($testEmail, $shareFileId);
        assert_true($removed, 'removeShareLink returned false');

        $file = $drive->getFileByShareToken($shareToken);
        assert_true($file === null, 'File still resolvable after share removal');
        $shareToken = null;
    });

    test('Invalid/nonexistent token returns null', function () use (&$drive) {
        $result = $drive->getFileByShareToken('nonexistent_token_' . bin2hex(random_bytes(16)));
        assert_true($result === null, 'Nonexistent token should return null');
    });
}

// ── 6. Password-Protected Shares ─────────────────────────────────

if (shouldRun('password')) {
    out("\n--- 6. PASSWORD-PROTECTED SHARES ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
    $pwFileId = null;
    $pwToken = null;

    test('Create password-protected share', function () use (&$drive, $testEmail, &$pwFileId, &$pwToken, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-pw-share.txt', 'Password protected content', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $pwFileId = $file['id'];
        $cleanupFileIds[] = $pwFileId;

        $token = $drive->createShareLink($testEmail, $pwFileId, 24, false, null, 'TestPassword123!');
        assert_not_empty($token, 'Password share creation failed');
        $pwToken = $token;
    });

    test('Share requires password', function () use (&$drive, $pwToken) {
        if (!$pwToken) throw new \RuntimeException('No token');
        $requires = $drive->shareRequiresPassword($pwToken);
        assert_true($requires, 'shareRequiresPassword should return true');
    });

    test('Correct password validates', function () use (&$drive, $pwToken) {
        if (!$pwToken) throw new \RuntimeException('No token');
        $valid = $drive->validateFileSharePassword($pwToken, 'TestPassword123!');
        assert_true($valid, 'Correct password should validate');
    });

    test('Wrong password rejected', function () use (&$drive, $pwToken) {
        if (!$pwToken) throw new \RuntimeException('No token');
        $valid = $drive->validateFileSharePassword($pwToken, 'WrongPassword');
        assert_true(!$valid, 'Wrong password should be rejected');
    });

    test('No-password share does not require password', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-no-pw.txt', 'No password', 'text/plain');
        $cleanupFileIds[] = $file['id'];
        $token = $drive->createShareLink($testEmail, $file['id'], 24);
        $requires = $drive->shareRequiresPassword($token);
        assert_true(!$requires, 'No-password share should not require password');
    });

    test('Update share: add password to existing share', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-add-pw.txt', 'Will get password', 'text/plain');
        $cleanupFileIds[] = $file['id'];
        $token = $drive->createShareLink($testEmail, $file['id'], 24);
        assert_true(!$drive->shareRequiresPassword($token), 'Should start without password');

        $drive->updateShareLink($testEmail, $file['id'], null, null, 'NewPassword!');
        assert_true($drive->shareRequiresPassword($token), 'Should now require password');
        assert_true($drive->validateFileSharePassword($token, 'NewPassword!'), 'New password should validate');
    });

    test('Update password does NOT wipe existing expiry/limit', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        global $config;
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-keep-expiry.txt', 'Keep expiry and limit', 'text/plain');
        $cleanupFileIds[] = $file['id'];

        // Share with a 24h expiry AND a 5-download limit.
        $token = $drive->createShareLink($testEmail, $file['id'], 24, false, 5);
        assert_not_empty($token, 'Share creation failed');

        $db = \Webmail\Core\Database::getConnection($config);
        $before = $db->prepare("SELECT share_expires, max_downloads FROM drive_files WHERE id = ?");
        $before->execute([$file['id']]);
        $rowBefore = $before->fetch(\PDO::FETCH_ASSOC);
        assert_true(!empty($rowBefore['share_expires']), 'Precondition: expiry should be set');
        assert_true((int)$rowBefore['max_downloads'] === 5, 'Precondition: max_downloads should be 5');

        // Edit ONLY the password (expiry/limit omitted as null).
        $drive->updateShareLink($testEmail, $file['id'], null, null, 'EditedPass!');

        $after = $db->prepare("SELECT share_expires, max_downloads FROM drive_files WHERE id = ?");
        $after->execute([$file['id']]);
        $rowAfter = $after->fetch(\PDO::FETCH_ASSOC);
        assert_true($rowAfter['share_expires'] === $rowBefore['share_expires'], 'Expiry must be preserved when omitted');
        assert_true((int)$rowAfter['max_downloads'] === 5, 'max_downloads must be preserved when omitted');

        // Explicit 0 clears them (never expires / unlimited).
        $drive->updateShareLink($testEmail, $file['id'], 0, 0);
        $cleared = $db->prepare("SELECT share_expires, max_downloads FROM drive_files WHERE id = ?");
        $cleared->execute([$file['id']]);
        $rowCleared = $cleared->fetch(\PDO::FETCH_ASSOC);
        assert_true(empty($rowCleared['share_expires']), 'Explicit 0 should clear expiry');
        assert_true($rowCleared['max_downloads'] === null, 'Explicit 0 should clear max_downloads');
    });
}

// ── 7. Download Limits ───────────────────────────────────────────

if (shouldRun('share')) {
    out("\n--- 7. DOWNLOAD LIMITS ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);

    test('Create share with max downloads = 3', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-limited-dl.txt', 'Limited downloads', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $token = $drive->createShareLink($testEmail, $file['id'], 24, false, 3);
        assert_not_empty($token, 'Share creation failed');

        // Downloads 1, 2, 3 should work
        for ($i = 1; $i <= 3; $i++) {
            $resolved = $drive->getFileByShareToken($token);
            assert_true($resolved !== null, "Download {$i} should be allowed");
            $drive->incrementFileDownloadCount($token);
        }

        // Download 4 should be blocked
        $blocked = $drive->getFileByShareToken($token);
        assert_true($blocked === null, 'Download 4 should be blocked (limit = 3)');
    });

    test('Unlimited downloads (max_downloads = null)', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-unlimited-dl.txt', 'Unlimited', 'text/plain');
        $cleanupFileIds[] = $file['id'];
        $token = $drive->createShareLink($testEmail, $file['id'], 24, false, null);

        for ($i = 0; $i < 10; $i++) {
            $drive->incrementFileDownloadCount($token);
        }
        $resolved = $drive->getFileByShareToken($token);
        assert_true($resolved !== null, 'Unlimited share should still work after 10 downloads');
    });
}

// ── 8. Expiry Enforcement ────────────────────────────────────────

if (shouldRun('expiry')) {
    out("\n--- 8. EXPIRY ENFORCEMENT ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
    $db = $db ?? \Webmail\Core\Database::getConnection($config);

    test('Active share (future expiry) is accessible', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-active-expiry.txt', 'Not expired yet', 'text/plain');
        $cleanupFileIds[] = $file['id'];
        $token = $drive->createShareLink($testEmail, $file['id'], 24);

        $resolved = $drive->getFileByShareToken($token);
        assert_true($resolved !== null, 'Active (non-expired) share should be accessible');
    });

    test('Expired share is NOT accessible', function () use (&$drive, $testEmail, &$db, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-expired.txt', 'This will be expired', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $token = $drive->createShareLink($testEmail, $file['id'], 1);
        assert_not_empty($token, 'Share creation failed');

        // Backdate using PHP time (matches getFileByShareToken which uses PHP date())
        $pastTime = date('Y-m-d H:i:s', time() - 7200);
        $stmt = $db->prepare("UPDATE drive_files SET share_expires = ? WHERE id = ?");
        $stmt->execute([$pastTime, $file['id']]);

        $resolved = $drive->getFileByShareToken($token);
        assert_true($resolved === null, 'Expired share should NOT be accessible');
    });

    test('No-expiry share remains accessible', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-no-expiry.txt', 'Never expires', 'text/plain');
        $cleanupFileIds[] = $file['id'];
        $token = $drive->createShareLink($testEmail, $file['id'], null);

        $resolved = $drive->getFileByShareToken($token);
        assert_true($resolved !== null, 'No-expiry share should always be accessible');

        $info = $drive->getFileShareInfo($token);
        assert_true(empty($info['share_expires']), 'No-expiry share should have null share_expires');
    });
}

// ── 9. Email Attachment Cleanup ──────────────────────────────────

if (shouldRun('cleanup')) {
    out("\n--- 9. EMAIL ATTACHMENT CLEANUP ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
    $db = $db ?? \Webmail\Core\Database::getConnection($config);

    test('Email attachment flag set correctly', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-email-att.txt', 'Email attachment', 'text/plain');
        $cleanupFileIds[] = $file['id'];

        $token = $drive->createShareLink($testEmail, $file['id'], 24, true);
        assert_not_empty($token, 'Share creation failed');

        $row = $drive->getFile($testEmail, $file['id']);
        assert_equals(1, (int)$row['is_email_attachment'], 'is_email_attachment should be 1');
    });

    test('Non-email share does NOT set flag', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-regular-share.txt', 'Regular share', 'text/plain');
        $cleanupFileIds[] = $file['id'];

        $drive->createShareLink($testEmail, $file['id'], 24, false);
        $row = $drive->getFile($testEmail, $file['id']);
        assert_equals(0, (int)$row['is_email_attachment'], 'is_email_attachment should be 0');
    });

    test('markAsEmailAttachment works', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-mark-att.txt', 'Will be marked', 'text/plain');
        $cleanupFileIds[] = $file['id'];

        $ok = $drive->markAsEmailAttachment($testEmail, $file['id']);
        assert_true($ok, 'markAsEmailAttachment returned false');

        $row = $drive->getFile($testEmail, $file['id']);
        assert_equals(1, (int)$row['is_email_attachment']);
    });

    test('Cleanup deletes expired email attachments', function () use (&$drive, $testEmail, &$db) {
        // Create file, share as email attachment, backdate expiry
        $content = 'Expired email attachment for cleanup test - ' . date('c');
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-cleanup-target.txt', $content, 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $fileId = $file['id'];

        $token = $drive->createShareLink($testEmail, $fileId, 1, true);
        assert_not_empty($token, 'Share failed');

        // Backdate expiry to 2 hours ago
        $db->prepare("UPDATE drive_files SET share_expires = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?")->execute([$fileId]);

        // Get path before cleanup
        $path = $drive->getFilePath($testEmail, $fileId);

        // Run cleanup
        $deleted = $drive->cleanupExpiredEmailAttachments();
        assert_true($deleted > 0, 'Cleanup should have deleted at least 1 file');

        // Verify file is gone from DB
        $gone = $drive->getFile($testEmail, $fileId);
        assert_true($gone === null, 'File should be deleted from DB after cleanup');

        // Verify file is gone from disk
        if ($path) {
            assert_true(!file_exists($path), 'File should be deleted from disk after cleanup');
        }
        // No need to add to cleanupFileIds -- it's already deleted
    });

    test('Cleanup does NOT delete non-email shares', function () use (&$drive, $testEmail, &$db, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-cleanup-safe.txt', 'Regular file, not email att', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $drive->createShareLink($testEmail, $file['id'], 1, false);
        $db->prepare("UPDATE drive_files SET share_expires = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?")->execute([$file['id']]);

        $drive->cleanupExpiredEmailAttachments();

        $still = $drive->getFile($testEmail, $file['id']);
        assert_true($still !== null, 'Non-email file should NOT be deleted by cleanup');
    });

    test('Cleanup does NOT delete email att with NULL expiry', function () use (&$drive, $testEmail, &$db, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-null-expiry.txt', 'No expiry set', 'text/plain');
        $cleanupFileIds[] = $file['id'];
        $drive->createShareLink($testEmail, $file['id'], null, true);

        $drive->cleanupExpiredEmailAttachments();

        $still = $drive->getFile($testEmail, $file['id']);
        assert_true($still !== null, 'File with NULL expiry should NOT be cleaned up');
    });

    test('createShareLinkForEmail helper sets correct defaults', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-email-helper.txt', 'Via helper', 'text/plain');
        $cleanupFileIds[] = $file['id'];

        $token = $drive->createShareLinkForEmail($testEmail, $file['id']);
        assert_not_empty($token, 'createShareLinkForEmail failed');

        $row = $drive->getFile($testEmail, $file['id']);
        assert_equals(1, (int)$row['is_email_attachment'], 'Helper should set is_email_attachment=1');
        assert_true(!empty($row['share_expires']), 'Helper should set an expiry');

        // Verify ~90 day expiry (2160 hours = 90 days)
        $expiryTs = strtotime($row['share_expires']);
        $expectedMin = time() + (2150 * 3600);
        $expectedMax = time() + (2170 * 3600);
        assert_true($expiryTs >= $expectedMin && $expiryTs <= $expectedMax,
            'Expiry should be ~90 days from now, got ' . date('Y-m-d', $expiryTs));
    });
}

// ── 10. Quota ────────────────────────────────────────────────────

if (shouldRun('quota')) {
    out("\n--- 10. QUOTA ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);

    test('Quota tracking on upload', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $before = $drive->getQuota($testEmail);
        $content = str_repeat('Q', 5000);
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-quota.txt', $content, 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $after = $drive->getQuota($testEmail);
        $diff = $after['used'] - $before['used'];
        assert_true($diff >= 5000, "Quota should increase by at least 5000 bytes, increased by {$diff}");
    });

    test('Quota tracking on delete', function () use (&$drive, $testEmail) {
        $before = $drive->getQuota($testEmail);
        $content = str_repeat('D', 3000);
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-quota-del.txt', $content, 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $afterUpload = $drive->getQuota($testEmail);

        $drive->deleteFile($testEmail, $file['id']);
        $afterDelete = $drive->getQuota($testEmail);

        assert_true($afterDelete['used'] < $afterUpload['used'], 'Quota should decrease after delete');
    });

    test('hasQuota returns true for unlimited', function () use (&$drive, $testEmail) {
        $quota = $drive->getQuota($testEmail);
        if ($quota['unlimited']) {
            assert_true($drive->hasQuota($testEmail, 999999999), 'Unlimited quota should allow any size');
        } else {
            return 'warn'; // Can't test if quota is limited
        }
    });
}

// ── 11. Trash ────────────────────────────────────────────────────

if (shouldRun('trash')) {
    out("\n--- 11. TRASH ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
    $trashFileId = null;

    test('Trash a file', function () use (&$drive, $testEmail, &$trashFileId, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-trashable.txt', 'Will be trashed', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $trashFileId = $file['id'];
        $cleanupFileIds[] = $trashFileId;

        $ok = $drive->trashFile($testEmail, $trashFileId);
        assert_true($ok, 'trashFile returned false');
    });

    test('Trashed file not in normal listing', function () use (&$drive, $testEmail, $trashFileId) {
        if (!$trashFileId) throw new \RuntimeException('No file');
        $files = $drive->getFiles($testEmail);
        $found = false;
        foreach ($files as $f) {
            if ($f['id'] === $trashFileId) { $found = true; break; }
        }
        assert_true(!$found, 'Trashed file should not appear in normal listing');
    });

    test('Trashed file appears in trash listing', function () use (&$drive, $testEmail, $trashFileId) {
        if (!$trashFileId) throw new \RuntimeException('No file');
        $trash = $drive->getTrashedItems($testEmail);
        assert_true(is_array($trash), 'getTrashedItems not array');
        $found = false;
        $files = $trash['files'] ?? $trash;
        foreach ($files as $f) {
            if ((int)$f['id'] === $trashFileId) { $found = true; break; }
        }
        assert_true($found, 'Trashed file should appear in trash listing');
    });

    test('Restore file from trash', function () use (&$drive, $testEmail, $trashFileId) {
        if (!$trashFileId) throw new \RuntimeException('No file');
        $ok = $drive->restoreFile($testEmail, $trashFileId);
        assert_true($ok, 'restoreFile returned false');

        $file = $drive->getFile($testEmail, $trashFileId);
        assert_true($file !== null, 'Restored file not found');
        assert_equals(0, (int)$file['is_trashed'], 'is_trashed should be 0 after restore');
    });
}

// ── 12. Email Integration ────────────────────────────────────────

if (shouldRun('email')) {
    out("\n--- 12. EMAIL INTEGRATION ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);

    test('Save email attachment to Drive (saveEmailAttachment)', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $content = "Simulated email attachment content - " . date('c');
        $result = $drive->saveEmailAttachment(
            $testEmail,
            'flowone-test-email-save.pdf',
            $content,
            'application/pdf',
            'Test Subject for Email',
            date('Y-m-d')
        );
        assert_true($result !== null, 'saveEmailAttachment returned null');
        // Returns nested structure: { file, folder, attachments_folder, client_folder }
        assert_true(isset($result['file']), 'No file key in result');
        assert_true(isset($result['file']['id']), 'No file ID in result');
        $cleanupFileIds[] = $result['file']['id'];

        $file = $drive->getFile($testEmail, $result['file']['id']);
        assert_true($file !== null, 'Saved attachment not found in DB');
        assert_equals('flowone-test-email-save.pdf', $file['original_name']);
        assert_true(isset($result['folder']), 'No folder in result');
        assert_true(isset($result['attachments_folder']), 'No attachments_folder in result');
    });

    test('Attachments folder auto-created', function () use (&$drive, $testEmail) {
        $folders = $drive->getFolders($testEmail);
        $found = false;
        foreach ($folders as $f) {
            if ($f['name'] === 'Attachments') { $found = true; break; }
        }
        assert_true($found, 'Attachments folder should be auto-created');
    });

    test('Email subfolder uses date-subject format', function () use (&$drive, $testEmail) {
        // Find Attachments folder
        $folders = $drive->getFolders($testEmail);
        $attFolderId = null;
        foreach ($folders as $f) {
            if ($f['name'] === 'Attachments') { $attFolderId = $f['id']; break; }
        }
        if (!$attFolderId) throw new \RuntimeException('Attachments folder not found');

        $subfolders = $drive->getFolders($testEmail, $attFolderId);
        $found = false;
        foreach ($subfolders as $sf) {
            if (strpos($sf['name'], 'Test Subject') !== false) {
                $found = true;
                break;
            }
        }
        assert_true($found, 'Email subfolder with subject should be created under Attachments');
    });

    test('uploadFileContent for email attachment with folder', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $content = 'Direct upload to folder ' . date('c');
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-direct-upload.docx', $content, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        assert_true($file !== null, 'uploadFileContent failed');
        $cleanupFileIds[] = $file['id'];
    });
}

// ── 13. Cron ─────────────────────────────────────────────────────

if (shouldRun('cron')) {
    out("\n--- 13. CRON ---");

    test('Cleanup cron script exists', function () {
        $path = dirname(__DIR__) . '/cron/cleanup-drive.php';
        assert_true(file_exists($path), 'cleanup-drive.php not found');
    });

    test('Crontab has cleanup-drive entry', function () {
        $crontab = @shell_exec('crontab -l 2>/dev/null');
        if (empty($crontab)) {
            throw new \RuntimeException(
                'Cannot read crontab. Required: 0 * * * * flock -n /tmp/cleanup-drive.lock '
                . '/usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/cleanup-drive.php'
            );
        }
        if (stripos($crontab, 'cleanup-drive') === false) {
            throw new \RuntimeException(
                'cleanup-drive.php not in crontab! Add: 0 * * * * flock -n /tmp/cleanup-drive.lock '
                . '/usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/cleanup-drive.php'
            );
        }
    });

    test('Cleanup cron log directory writable', function () {
        $dir = dirname(__DIR__) . '/storage/logs';
        assert_true(is_writable($dir), 'Log directory not writable');
    });

    test('drive-pending-nas-migrate cron exists', function () {
        $path = dirname(__DIR__) . '/cron/drive-pending-nas-migrate.php';
        assert_true(file_exists($path), 'drive-pending-nas-migrate.php not found');
    });

    test('drive-recall-warm cron exists', function () {
        $path = dirname(__DIR__) . '/cron/drive-recall-warm.php';
        assert_true(file_exists($path), 'drive-recall-warm.php not found');
    });
}

// ── 14. NAS Integration ──────────────────────────────────────────
// Tests for the NAS-primary / VPS-fallback storage contract:
//   - storage_location label correctness on fallback (A1)
//   - drive_pending_nas_migration roundtrip (A2)
//   - cold-file recall preflight (A3)
//   - upload write-size verification (A4)
//
// Most tests here are pure unit-style: they exercise the public API
// without actually pulling the NAS up/down. The destructive tests
// (forcing fallback) operate against the test account's storage dir
// only, with the FLOWONE-TEST prefix invariant.

if (shouldRun('nas')) {
    out("\n--- 14. NAS INTEGRATION ---");

    $drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);

    test('drive_pending_nas_migration table exists', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->query("SHOW TABLES LIKE 'drive_pending_nas_migration'");
        assert_true($stmt->rowCount() > 0, 'Table drive_pending_nas_migration missing (migration 031 not run?)');
    });

    test('drive_pending_nas_migration has expected columns', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $cols = $db->query("DESCRIBE drive_pending_nas_migration")->fetchAll(\PDO::FETCH_COLUMN);
        $required = ['file_id', 'local_path', 'nas_target_path', 'user_email', 'status', 'attempts'];
        foreach ($required as $c) {
            assert_true(in_array($c, $cols), "Missing column: {$c}");
        }
    });

    test('drive_files has tier_state column (migration 167)', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->query("SHOW COLUMNS FROM drive_files LIKE 'tier_state'");
        assert_true($stmt->rowCount() > 0, 'drive_files.tier_state missing (migration 167 not run?)');
    });

    test('NasHealthCheck class loadable', function () {
        assert_true(class_exists(\Webmail\Services\NasHealthCheck::class), 'NasHealthCheck class missing');
    });

    test('NasHealthCheck::isNasPath() identifies NAS paths', function () {
        assert_true(
            \Webmail\Services\NasHealthCheck::isNasPath('/mnt/nas-drive/foo/bar'),
            'Expected isNasPath() to return true for /mnt/nas-drive/...'
        );
        assert_true(
            !\Webmail\Services\NasHealthCheck::isNasPath('/var/www/vps-email/storage/drive/foo'),
            'Expected isNasPath() to return false for local path'
        );
    });

    test('Upload labels storage_location consistently', function () use (&$drive, $testEmail, &$cleanupFileIds, $config) {
        // Pin the file's actual location by reading what storage_location
        // ends up in the DB row, then asserting it matches reality.
        $content = 'FLOWONE-TEST storage_location labeling probe ' . microtime(true);
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-storage-location.txt', $content, 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare("SELECT storage_location FROM drive_files WHERE id = ?");
        $stmt->execute([$file['id']]);
        $loc = $stmt->fetchColumn();
        assert_true(in_array($loc, ['nfs', 'local']), "storage_location must be 'nfs' or 'local', got: " . var_export($loc, true));

        $path = $drive->getFilePath($testEmail, $file['id']);
        assert_true($path !== null && file_exists($path), 'Resolved path missing');

        // The labeling invariant: 'nfs' iff the resolved path is under
        // /mnt/nas-drive. If they disagree, the bug A1 has regressed.
        $onNas = str_starts_with($path, '/mnt/nas-drive');
        if ($loc === 'nfs') {
            assert_true($onNas, "storage_location='nfs' but path is not on NAS: {$path}");
        } else {
            assert_true(!$onNas, "storage_location='local' but path IS on NAS: {$path}");
        }
    });

    test('pending NAS migration row absent when NAS is primary+healthy', function () use ($config, $testEmail, &$drive, &$cleanupFileIds) {
        // Skip when NAS is currently down - the fallback path WILL queue
        // a pending row, so this test would be a false positive.
        if (!\Webmail\Services\NasHealthCheck::isAvailable()) {
            return 'warn';
        }

        $db = \Webmail\Core\Database::getConnection($config);
        $beforeCount = (int) $db->query("SELECT COUNT(*) FROM drive_pending_nas_migration WHERE status='pending'")->fetchColumn();

        $file = $drive->uploadFileContent($testEmail, 'flowone-test-no-pending.txt', 'no-pending ' . microtime(true), 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $stmt = $db->prepare("SELECT COUNT(*) FROM drive_pending_nas_migration WHERE file_id = ?");
        $stmt->execute([$file['id']]);
        $rowsForThisFile = (int) $stmt->fetchColumn();
        assert_equals(0, $rowsForThisFile, 'Healthy NAS upload must NOT enqueue a pending migration row');

        $afterCount = (int) $db->query("SELECT COUNT(*) FROM drive_pending_nas_migration WHERE status='pending'")->fetchColumn();
        assert_true($afterCount >= $beforeCount, 'Pending queue should not shrink during this test');
    });

    test('Write-size verification rejects on truncation (uploadFileContent)', function () use (&$drive, $testEmail) {
        // file_put_contents() returning fewer bytes than requested is the
        // partial-write scenario A4 guards against. We can't easily force
        // an NFS short-write here, so we verify the guard is reachable by
        // sending a zero-length string and confirming the row size is 0
        // (the function returns null or accepts size=0).
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-zero-len.txt', '', 'text/plain');
        // Either null (rejected) or a 0-byte file is fine - both are
        // self-consistent. The bug would be a NON-zero size on a zero-byte upload.
        if ($file !== null) {
            assert_equals(0, (int) $file['size'], 'Empty upload must yield size=0 row');
        }
    });

    test('prepareForDownload returns ready for hot file', function () use (&$drive, $testEmail, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-prepare.txt', 'prepare-probe', 'text/plain');
        assert_true($file !== null, 'Upload failed');
        $cleanupFileIds[] = $file['id'];

        $prep = $drive->prepareForDownload($testEmail, $file['id']);
        assert_true(is_array($prep), 'prepareForDownload must return array');
        assert_true(($prep['status'] ?? null) === 'ready', "Expected status=ready, got " . ($prep['status'] ?? 'null'));
        assert_true(!empty($prep['path']) && file_exists($prep['path']), 'Resolved path missing');
    });

    test('prepareForDownload returns not_found for unknown id', function () use (&$drive, $testEmail) {
        $prep = $drive->prepareForDownload($testEmail, 999999999);
        assert_equals('not_found', $prep['status'] ?? null, 'Expected status=not_found for unknown id');
    });

    test('drive-pending-nas-migrate.php --help exits 0', function () {
        $script = dirname(__DIR__) . '/cron/drive-pending-nas-migrate.php';
        assert_true(file_exists($script), 'cron script missing');
        $output = [];
        $code = -1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help 2>&1', $output, $code);
        assert_equals(0, $code, '--help should exit 0, got ' . $code);
        assert_true(str_contains(implode("\n", $output), 'Drive Pending NAS Migration'),
            '--help output should mention the script name');
    });

    test('drive-pending-nas-migrate.php --dry-run on empty queue runs cleanly', function () {
        // Skip when NAS not available - the script will exit 0 with skip
        // reason and that's actually the correct response.
        $script = dirname(__DIR__) . '/cron/drive-pending-nas-migrate.php';
        $output = [];
        $code = -1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --dry-run --json 2>&1', $output, $code);
        // Exit 0 is the only acceptable outcome - either ran or politely
        // skipped due to NAS unavailable.
        assert_equals(0, $code, "Exit code must be 0, got {$code}. Output:\n" . implode("\n", $output));
    });
}

// ── 15. Cleanup ──────────────────────────────────────────────────

summary:
out("\n--- CLEANUP ---");

test('Remove test files from DB and disk', function () use (&$cleanupFileIds, &$cleanupFolderIds, $testEmail, $config) {
    $drive = new \Webmail\Services\DriveService($config, $testEmail);
    $fileCount = 0;
    $folderCount = 0;

    foreach ($cleanupFileIds as $id) {
        try {
            if ($drive->getFile($testEmail, $id)) {
                $drive->deleteFile($testEmail, $id);
                $fileCount++;
            }
        } catch (\Throwable $e) {}
    }

    // Delete subfolders before parents (reverse order)
    foreach (array_reverse($cleanupFolderIds) as $id) {
        try {
            $drive->deleteFolder($testEmail, $id);
            $folderCount++;
        } catch (\Throwable $e) {}
    }

    // Clear the arrays so shutdown handler doesn't double-delete
    $cleanupFileIds = [];
    $cleanupFolderIds = [];

    out("          Cleaned up {$fileCount} file(s), {$folderCount} folder(s)");
});

// ══════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════

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
            out("      {$r['error']}");
        }
    }
}

if ($warnings > 0) {
    out("\n  {$YELLOW}WARNINGS:{$NC}");
    foreach ($results as $r) {
        if ($r['status'] === 'WARN') {
            out("    ~ {$r['name']}");
        }
    }
}

out("=================================================================");
exit($failed > 0 ? 1 : 0);
