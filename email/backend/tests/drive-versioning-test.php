#!/usr/bin/env php
<?php
/**
 * FlowOne Drive Versioning - End-to-End Acceptance Suite
 *
 * Exercises the full version lifecycle through the live
 * DriveService/DriveVersioningService pair:
 *
 *   upload -> edit (version created) -> list -> download version bytes ->
 *   copy-on-restore -> delete version, plus version numbering integrity,
 *   quota invariants (versions count, deletes refund, file delete refunds
 *   everything), pin/label metadata, the smart-thinning retention engine
 *   (pure algorithm + end-to-end prune with backdated rows), the desktop
 *   pre-overwrite snapshot endpoint logic, and usage/cleanup accounting.
 *
 * Server run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-versioning-test.php \
 *     --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL    Test account (required)
 *   --password=PASS  Accepted for flag-parity; Drive ops auth via service layer
 *   --only=g1,g2     lifecycle,numbering,quota,pin-label,retention,snapshot,usage-cleanup
 *   --skip-send      No-op here (kept for flag-parity); Drive ops are local
 *   --smoke          Connectivity + schema health check only
 *   --verbose        Show file:line on failure
 *   --json           Emit a single JSON summary
 *   --help           Show this help
 *
 * Safety: every artifact uses the "flowone-test-" / "FLOWONE-TEST" prefix
 * and is removed on exit (shutdown + SIGINT/SIGTERM). Quota is charged and
 * refunded symmetrically so used_bytes ends where it started. Idempotent.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

// ── CLI args ─────────────────────────────────────────────────────

$opts = getopt('', ['email:', 'password:', 'only:', 'skip-send', 'smoke', 'verbose', 'json', 'help']);

if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Drive Versioning - E2E Acceptance Suite\n";
    echo "===============================================\n\n";
    echo "Usage:\n";
    echo "  php drive-versioning-test.php --email=USER [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL    Test account (required)\n";
    echo "  --password=PASS  Accepted for flag-parity\n";
    echo "  --only=g1,g2     lifecycle,numbering,quota,pin-label,retention,snapshot,usage-cleanup\n";
    echo "  --smoke          Connectivity + schema health check only\n";
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

$logFile = __DIR__ . '/../storage/logs/drive-versioning-test-' . date('Ymd-His') . '.log';
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

// ── Helpers ──────────────────────────────────────────────────────

/** Write content to a temp file and register it for cleanup. */
function tmpContentFile(string $content): string {
    global $cleanupTmpFiles;
    $path = sys_get_temp_dir() . '/flowone_test_ver_' . bin2hex(random_bytes(8)) . '.txt';
    file_put_contents($path, $content);
    $cleanupTmpFiles[] = $path;
    return $path;
}

/** History rows only (drops the is_current pseudo-row). */
function historyRows(array $versions): array {
    return array_values(array_filter($versions, fn($v) => empty($v['is_current']) && !empty($v['id'])));
}

function currentUsed(\Webmail\Services\DriveService $drive, string $email): int {
    return (int)$drive->getQuota($email)['used'];
}

/** Make a synthetic version row with real bytes, charged to quota. */
function insertSyntheticVersion(
    \Webmail\Services\DriveService $drive,
    string $email,
    int $fileId,
    int $number,
    string $createdAt,
    int $pinned = 0
): int {
    $content = "FLOWONE-TEST synthetic v{$number}\n";
    $filename = 'flowone_test_synth_' . bin2hex(random_bytes(8)) . '.txt';
    $path = $drive->getUserPath($email) . '/' . $filename;
    file_put_contents($path, $content);
    $size = strlen($content);

    $drive->getDb()->prepare('
        INSERT INTO drive_file_versions
            (file_id, version_number, filename, size, storage_location, mime_type, modified_by, is_pinned, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$fileId, $number, $filename, $size, 'local', 'text/plain', $email, $pinned, $createdAt]);

    // Rows created out-of-band must be charged so later refunds net to zero.
    $drive->updateUsedSpace($email, $size);

    return (int)$drive->getDb()->lastInsertId();
}

// ── Cleanup (shutdown + signal safe) ─────────────────────────────

function doCleanup(): void {
    global $cleanupFileIds, $cleanupTmpFiles, $testEmail, $config;
    try {
        if (!empty($cleanupFileIds)) {
            $drive = new \Webmail\Services\DriveService($config, $testEmail);
            foreach ($cleanupFileIds as $id) {
                // deleteFile removes version history (bytes + quota) too.
                try { $drive->deleteFile($testEmail, $id); } catch (\Throwable $e) {}
            }
        }
    } catch (\Throwable $e) {
        error_log('[drive-versioning cleanup] ' . $e->getMessage());
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
out("  FlowOne Drive Versioning - E2E Acceptance Suite");
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

test('Migration 190 schema present (columns + unique key)', function () use (&$drive) {
    $db = $drive->getDb();
    $cols = $db->query('SHOW COLUMNS FROM drive_file_versions')->fetchAll(\PDO::FETCH_COLUMN);
    foreach (['storage_location', 'mime_type', 'checksum', 'label', 'is_pinned'] as $col) {
        assert_true(in_array($col, $cols, true), "drive_file_versions missing column: {$col}");
    }
    $idx = $db->query("SHOW INDEX FROM drive_file_versions WHERE Key_name = 'uq_file_version'")->fetchAll();
    assert_not_empty($idx, 'unique key uq_file_version missing (run migration 190)');

    $pmCols = $db->query('SHOW COLUMNS FROM drive_pending_nas_migration')->fetchAll(\PDO::FETCH_COLUMN);
    assert_true(in_array('version_id', $pmCols, true), 'drive_pending_nas_migration missing version_id column');
});

if ($smokeOnly) {
    goto summary;
}

$drive = $drive ?? new \Webmail\Services\DriveService($config, $testEmail);
$versioning = $drive->versioning();

// ── 1. LIFECYCLE ─────────────────────────────────────────────────

$contentV1 = "FLOWONE-TEST versioning v1 " . date('c') . "\n" . str_repeat("alpha\n", 40);
$contentV2 = "FLOWONE-TEST versioning v2 " . date('c') . "\n" . str_repeat("bravo\n", 55);
$contentV3 = "FLOWONE-TEST versioning v3 " . date('c') . "\n" . str_repeat("charlie\n", 70);
$lifeFileId = null;

if (shouldRun('lifecycle')) {
    out("\n--- 1. LIFECYCLE (upload -> edit -> list -> read -> restore -> delete) ---");

    test('Upload base file', function () use (&$drive, $testEmail, &$lifeFileId, $contentV1, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-versioning.txt', $contentV1, 'text/plain', null);
        assert_true($file !== null, 'uploadFileContent returned null');
        $lifeFileId = (int)$file['id'];
        $cleanupFileIds[] = $lifeFileId;
        assert_equals(1, (int)($file['current_version'] ?? 1), 'fresh upload should be version 1');
    });

    test('Content edit archives the old content as a version', function () use (&$drive, $testEmail, &$lifeFileId, $contentV2, &$versioning) {
        assert_true($lifeFileId !== null, 'no file');
        $updated = $drive->updateFileContent($testEmail, $lifeFileId, tmpContentFile($contentV2), true);
        assert_true($updated !== null, 'updateFileContent returned null');
        assert_equals(2, (int)$updated['current_version'], 'current_version should bump to 2');

        $history = historyRows($versioning->getFileVersions($testEmail, $lifeFileId));
        assert_equals(1, count($history), 'expected exactly 1 history row');
        assert_equals(1, (int)$history[0]['version_number'], 'archived row should be version 1');
    });

    test('Second edit -> two history rows, newest first, current pseudo-row on top', function () use (&$drive, $testEmail, &$lifeFileId, $contentV3, &$versioning) {
        $updated = $drive->updateFileContent($testEmail, $lifeFileId, tmpContentFile($contentV3), true);
        assert_true($updated !== null, 'updateFileContent returned null');

        $versions = $versioning->getFileVersions($testEmail, $lifeFileId);
        assert_true(!empty($versions[0]['is_current']), 'first row must be the current pseudo-row');
        $history = historyRows($versions);
        assert_equals(2, count($history), 'expected 2 history rows');
        assert_true((int)$history[0]['version_number'] > (int)$history[1]['version_number'], 'history not newest-first');
    });

    test('Version rows carry their own storage_location and mime_type', function () use ($testEmail, &$lifeFileId, &$versioning) {
        $history = historyRows($versioning->getFileVersions($testEmail, $lifeFileId));
        foreach ($history as $v) {
            assert_not_empty($v['storage_location'], "version {$v['version_number']} missing storage_location");
            assert_not_empty($v['mime_type'], "version {$v['version_number']} missing mime_type");
        }
    });

    test('Oldest version bytes readable and md5-identical to original upload', function () use ($testEmail, &$lifeFileId, &$versioning, $contentV1) {
        $history = historyRows($versioning->getFileVersions($testEmail, $lifeFileId));
        $oldest = end($history);
        $info = $versioning->getVersionFilePath($testEmail, $lifeFileId, (int)$oldest['id']);
        assert_true($info !== null && !empty($info['path']), 'getVersionFilePath returned no path');
        assert_true(is_file($info['path']), 'version bytes missing on disk');
        assert_equals(md5($contentV1), md5(file_get_contents($info['path'])), 'version content corrupted');
    });

    test('Copy-on-restore: content restored, history preserved and grown', function () use (&$drive, $testEmail, &$lifeFileId, &$versioning, $contentV1) {
        $before = historyRows($versioning->getFileVersions($testEmail, $lifeFileId));
        $oldest = end($before);

        $ok = $versioning->restoreVersion($testEmail, $lifeFileId, (int)$oldest['id']);
        assert_true($ok, 'restoreVersion returned false');

        $livePath = $drive->getFilePath($testEmail, $lifeFileId);
        assert_true($livePath && is_file($livePath), 'live file unreadable after restore');
        assert_equals(md5($contentV1), md5(file_get_contents($livePath)), 'restored content mismatch');

        $after = historyRows($versioning->getFileVersions($testEmail, $lifeFileId));
        assert_equals(count($before) + 1, count($after), 'restore must archive outgoing content (history +1)');
        $ids = array_map(fn($v) => (int)$v['id'], $after);
        assert_true(in_array((int)$oldest['id'], $ids, true), 'restored version row must STAY in history');
    });

    test('Delete one version removes row and bytes', function () use ($testEmail, &$lifeFileId, &$versioning) {
        $history = historyRows($versioning->getFileVersions($testEmail, $lifeFileId));
        $victim = end($history);
        $info = $versioning->getVersionFilePath($testEmail, $lifeFileId, (int)$victim['id']);

        $ok = $versioning->deleteVersion($testEmail, $lifeFileId, (int)$victim['id']);
        assert_true($ok, 'deleteVersion returned false');

        $remaining = array_map(fn($v) => (int)$v['id'], historyRows($versioning->getFileVersions($testEmail, $lifeFileId)));
        assert_true(!in_array((int)$victim['id'], $remaining, true), 'version row still listed');
        if ($info && !empty($info['path'])) {
            assert_true(!is_file($info['path']), 'version bytes still on disk after delete');
        }
    });
}

// ── 2. NUMBERING ─────────────────────────────────────────────────

if (shouldRun('numbering')) {
    out("\n--- 2. NUMBERING (no duplicates after delete-then-edit) ---");

    $numFileId = null;

    test('Setup: file with two versions, then delete the newest version', function () use (&$drive, $testEmail, &$numFileId, &$versioning, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-numbering.txt', "FLOWONE-TEST num v1\n", 'text/plain', null);
        assert_true($file !== null, 'upload failed');
        $numFileId = (int)$file['id'];
        $cleanupFileIds[] = $numFileId;

        $drive->updateFileContent($testEmail, $numFileId, tmpContentFile("FLOWONE-TEST num v2\n"), true);
        $drive->updateFileContent($testEmail, $numFileId, tmpContentFile("FLOWONE-TEST num v3\n"), true);

        $history = historyRows($versioning->getFileVersions($testEmail, $numFileId));
        assert_equals(2, count($history), 'expected 2 history rows');
        // Delete the NEWEST history row - the legacy MAX()+1 logic collides here.
        assert_true($versioning->deleteVersion($testEmail, $numFileId, (int)$history[0]['id']), 'delete failed');
    });

    test('Next edit produces no duplicate version numbers', function () use (&$drive, $testEmail, &$numFileId, &$versioning) {
        $updated = $drive->updateFileContent($testEmail, $numFileId, tmpContentFile("FLOWONE-TEST num v4\n"), true);
        assert_true($updated !== null, 'updateFileContent returned null');

        $history = historyRows($versioning->getFileVersions($testEmail, $numFileId));
        $numbers = array_map(fn($v) => (int)$v['version_number'], $history);
        assert_equals(count($numbers), count(array_unique($numbers)), 'duplicate version numbers: ' . implode(',', $numbers));

        $file = $drive->getFile($testEmail, $numFileId);
        assert_true((int)$file['current_version'] > max($numbers), 'current_version must exceed all archived numbers');
    });

    test('DB unique key rejects duplicate (file_id, version_number)', function () use (&$drive, $testEmail, &$numFileId, &$versioning) {
        $history = historyRows($versioning->getFileVersions($testEmail, $numFileId));
        assert_not_empty($history, 'no history to collide with');
        $existing = $history[0];

        try {
            // Populate every NOT NULL column so the only possible failure
            // is the (file_id, version_number) unique key.
            $drive->getDb()->prepare('
                INSERT INTO drive_file_versions (file_id, version_number, filename, size, modified_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ')->execute([$numFileId, (int)$existing['version_number'], 'flowone_test_dupe.txt', 1, $testEmail]);
            // If the insert somehow succeeded, remove it and fail.
            $drive->getDb()->prepare('DELETE FROM drive_file_versions WHERE file_id = ? AND filename = ?')
                ->execute([$numFileId, 'flowone_test_dupe.txt']);
            throw new \RuntimeException('duplicate (file_id, version_number) was accepted - unique key not enforced');
        } catch (\PDOException $e) {
            assert_true(str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062'),
                'unexpected PDO error: ' . $e->getMessage());
        }
    });
}

// ── 3. QUOTA ─────────────────────────────────────────────────────

if (shouldRun('quota')) {
    out("\n--- 3. QUOTA (versions count; every delete path refunds) ---");

    $qFileId = null;
    $qBaseline = null;
    $qContentA = "FLOWONE-TEST quota A " . str_repeat('x', 512) . "\n";
    $qContentB = "FLOWONE-TEST quota B " . str_repeat('y', 1024) . "\n";

    test('Upload charges exactly the file size', function () use (&$drive, $testEmail, &$qFileId, &$qBaseline, $qContentA, &$cleanupFileIds) {
        $qBaseline = currentUsed($drive, $testEmail);
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-quota.txt', $qContentA, 'text/plain', null);
        assert_true($file !== null, 'upload failed');
        $qFileId = (int)$file['id'];
        $cleanupFileIds[] = $qFileId;
        assert_equals($qBaseline + strlen($qContentA), currentUsed($drive, $testEmail), 'upload quota charge wrong');
    });

    test('Versioned edit charges the FULL new size (old bytes stay)', function () use (&$drive, $testEmail, &$qFileId, &$qBaseline, $qContentA, $qContentB) {
        $before = currentUsed($drive, $testEmail);
        $updated = $drive->updateFileContent($testEmail, $qFileId, tmpContentFile($qContentB), true);
        assert_true($updated !== null, 'updateFileContent failed');
        assert_equals($before + strlen($qContentB), currentUsed($drive, $testEmail),
            'versioned edit must charge the full new size, not the delta');
    });

    test('Deleting a version refunds its exact size', function () use (&$drive, $testEmail, &$qFileId, &$versioning) {
        $history = historyRows($versioning->getFileVersions($testEmail, $qFileId));
        assert_not_empty($history, 'no history row');
        $victim = $history[0];

        $before = currentUsed($drive, $testEmail);
        assert_true($versioning->deleteVersion($testEmail, $qFileId, (int)$victim['id']), 'deleteVersion failed');
        assert_equals($before - (int)$victim['size'], currentUsed($drive, $testEmail), 'version delete refund wrong');
    });

    test('Deleting the file refunds everything (back to baseline)', function () use (&$drive, $testEmail, &$qFileId, &$qBaseline, &$cleanupFileIds) {
        // Re-create a version first so the delete has history to clean up.
        $drive->updateFileContent($testEmail, $qFileId, tmpContentFile("FLOWONE-TEST quota C\n"), true);

        assert_true($drive->deleteFile($testEmail, $qFileId), 'deleteFile failed');
        $cleanupFileIds = array_values(array_diff($cleanupFileIds, [$qFileId]));

        assert_equals($qBaseline, currentUsed($drive, $testEmail),
            'used_bytes did not return to baseline - version quota leaked on file delete');
        $qFileId = null;
    });
}

// ── 4. PIN / LABEL ───────────────────────────────────────────────

if (shouldRun('pin-label')) {
    out("\n--- 4. PIN / LABEL ---");

    $pFileId = null;

    test('Setup: file with two versions', function () use (&$drive, $testEmail, &$pFileId, &$versioning, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-pin.txt', "FLOWONE-TEST pin v1\n", 'text/plain', null);
        assert_true($file !== null, 'upload failed');
        $pFileId = (int)$file['id'];
        $cleanupFileIds[] = $pFileId;
        $drive->updateFileContent($testEmail, $pFileId, tmpContentFile("FLOWONE-TEST pin v2\n"), true);
        $drive->updateFileContent($testEmail, $pFileId, tmpContentFile("FLOWONE-TEST pin v3\n"), true);
        assert_equals(2, count(historyRows($versioning->getFileVersions($testEmail, $pFileId))), 'expected 2 history rows');
    });

    test('Pin and unpin round-trip', function () use ($testEmail, &$pFileId, &$versioning) {
        $history = historyRows($versioning->getFileVersions($testEmail, $pFileId));
        $vid = (int)$history[0]['id'];

        assert_true($versioning->setVersionPinned($testEmail, $pFileId, $vid, true), 'pin failed');
        $row = historyRows($versioning->getFileVersions($testEmail, $pFileId))[0];
        assert_equals(1, (int)$row['is_pinned'], 'is_pinned not set');

        assert_true($versioning->setVersionPinned($testEmail, $pFileId, $vid, false), 'unpin failed');
        $row = historyRows($versioning->getFileVersions($testEmail, $pFileId))[0];
        assert_equals(0, (int)$row['is_pinned'], 'is_pinned not cleared');
    });

    test('Label set, trim, and clear', function () use ($testEmail, &$pFileId, &$versioning) {
        $history = historyRows($versioning->getFileVersions($testEmail, $pFileId));
        $vid = (int)$history[0]['id'];

        assert_true($versioning->setVersionLabel($testEmail, $pFileId, $vid, '  Final draft  '), 'label set failed');
        $row = historyRows($versioning->getFileVersions($testEmail, $pFileId))[0];
        assert_equals('Final draft', $row['label'], 'label not trimmed/stored');

        assert_true($versioning->setVersionLabel($testEmail, $pFileId, $vid, '   '), 'label clear failed');
        $row = historyRows($versioning->getFileVersions($testEmail, $pFileId))[0];
        assert_true($row['label'] === null || $row['label'] === '', 'blank label should clear to null');
    });

    test('Cleanup skips pinned versions; explicit delete still works on them', function () use (&$drive, $testEmail, &$pFileId, &$versioning) {
        $history = historyRows($versioning->getFileVersions($testEmail, $pFileId));
        assert_equals(2, count($history), 'expected 2 history rows');
        $pinnedId = (int)$history[0]['id'];
        $versioning->setVersionPinned($testEmail, $pFileId, $pinnedId, true);

        $result = $versioning->cleanupFileVersions($testEmail, $pFileId);
        assert_equals(1, (int)$result['deleted'], 'cleanup should delete exactly the 1 unpinned version');

        $left = historyRows($versioning->getFileVersions($testEmail, $pFileId));
        assert_equals(1, count($left), 'pinned version should survive cleanup');
        assert_equals($pinnedId, (int)$left[0]['id'], 'wrong survivor');

        // Explicit delete bypasses pin protection.
        assert_true($versioning->deleteVersion($testEmail, $pFileId, $pinnedId), 'explicit delete of pinned version failed');
        assert_equals(0, count(historyRows($versioning->getFileVersions($testEmail, $pFileId))), 'pinned version not deleted explicitly');
    });
}

// ── 5. RETENTION (smart thinning) ────────────────────────────────

if (shouldRun('retention')) {
    out("\n--- 5. RETENTION (smart thinning) ---");

    // Anchor "now" at noon so hour offsets never straddle a calendar-day
    // boundary, keeping these pure-algorithm tests deterministic.
    $nowTs = strtotime(date('Y-m-d') . ' 12:00:00');

    $mkRowAt = function (int $id, int $number, string $createdAt, int $pinned = 0) {
        return [
            'id' => $id,
            'version_number' => $number,
            'size' => 10,
            'is_pinned' => $pinned,
            'created_at' => $createdAt,
        ];
    };

    test('All versions younger than 24h are kept', function () use (&$versioning, $mkRowAt, $nowTs) {
        $versions = [];
        for ($i = 1; $i <= 10; $i++) {
            $versions[] = $mkRowAt($i, $i, date('Y-m-d H:i:s', $nowTs - $i * 3600)); // 1h..10h old
        }
        assert_equals(0, count($versioning->selectPrunableVersions($versions, $nowTs)), 'fresh versions must never be pruned');
    });

    test('Within 30 days: one survivor per calendar day (newest)', function () use (&$versioning, $mkRowAt, $nowTs) {
        // 5 versions spread across one calendar day, 3 days ago.
        $day = date('Y-m-d', $nowTs - 3 * 86400);
        $versions = [];
        foreach ([1 => '08:00', 2 => '09:30', 3 => '11:00', 4 => '13:00', 5 => '15:45'] as $id => $time) {
            $versions[] = $mkRowAt($id, $id, "{$day} {$time}:00");
        }
        $prunable = $versioning->selectPrunableVersions($versions, $nowTs);
        assert_equals(4, count($prunable), 'expected 4 of 5 same-day versions pruned');
        $prunedIds = array_map(fn($v) => $v['id'], $prunable);
        assert_true(!in_array(5, $prunedIds, true), 'the NEWEST of the day must survive');
    });

    test('Beyond 30 days: one survivor per ISO week', function () use (&$versioning, $mkRowAt, $nowTs) {
        // Mon/Tue/Wed of one ISO week, ~6-7 weeks back (safely past the
        // 30-day daily window regardless of which weekday today is).
        $monday = strtotime('monday this week', $nowTs - 45 * 86400);
        $versions = [
            $mkRowAt(1, 1, date('Y-m-d', $monday) . ' 10:00:00'),
            $mkRowAt(2, 2, date('Y-m-d', $monday + 86400) . ' 10:00:00'),
            $mkRowAt(3, 3, date('Y-m-d', $monday + 2 * 86400) . ' 10:00:00'),
        ];
        $prunable = $versioning->selectPrunableVersions($versions, $nowTs);
        assert_equals(2, count($prunable), 'expected 2 of 3 same-week versions pruned');
        $prunedIds = array_map(fn($v) => $v['id'], $prunable);
        assert_true(!in_array(3, $prunedIds, true), 'the NEWEST of the week must survive');
    });

    test('Pinned versions are never auto-pruned', function () use (&$versioning, $mkRowAt, $nowTs) {
        $day = date('Y-m-d', $nowTs - 3 * 86400);
        $versions = [
            $mkRowAt(1, 1, "{$day} 08:00:00", 1), // pinned, oldest
            $mkRowAt(2, 2, "{$day} 10:00:00"),
            $mkRowAt(3, 3, "{$day} 12:00:00"),
        ];
        $prunable = $versioning->selectPrunableVersions($versions, $nowTs);
        $prunedIds = array_map(fn($v) => $v['id'], $prunable);
        assert_true(!in_array(1, $prunedIds, true), 'pinned version must not be pruned');
        assert_equals(1, count($prunable), 'exactly one unpinned same-day duplicate should go');
    });

    test('Hard cap evicts oldest unpinned beyond max_versions_per_file', function () use (&$versioning, $mkRowAt, $nowTs) {
        $cap = \Webmail\Services\DriveVersioningService::DEFAULT_MAX_VERSIONS;
        $versions = [];
        $n = $cap + 10;
        for ($i = 1; $i <= $n; $i++) {
            // All within the keep-all window (minutes old) so only the cap applies.
            $versions[] = $mkRowAt($i, $i, date('Y-m-d H:i:s', $nowTs - $i * 60));
        }
        $prunable = $versioning->selectPrunableVersions($versions, $nowTs);
        assert_equals(10, count($prunable), "cap {$cap}: expected 10 evictions for {$n} versions");
        // ids ascend with age here, so the eviction set must be the 10 oldest.
        $prunedIds = array_map(fn($v) => $v['id'], $prunable);
        sort($prunedIds);
        assert_equals(range($cap + 1, $n), $prunedIds, 'cap must evict the oldest versions');
    });

    test('End-to-end pruneFileVersions: backdated rows thinned, quota refunded', function () use (&$drive, $testEmail, &$versioning, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-retention.txt', "FLOWONE-TEST retention\n", 'text/plain', null);
        assert_true($file !== null, 'upload failed');
        $fileId = (int)$file['id'];
        $cleanupFileIds[] = $fileId;

        // 4 versions on the same day 5 days ago + 1 pinned among them.
        $day = date('Y-m-d', time() - 5 * 86400);
        insertSyntheticVersion($drive, $testEmail, $fileId, 1, "{$day} 08:00:00");
        insertSyntheticVersion($drive, $testEmail, $fileId, 2, "{$day} 10:00:00", 1); // pinned
        insertSyntheticVersion($drive, $testEmail, $fileId, 3, "{$day} 12:00:00");
        insertSyntheticVersion($drive, $testEmail, $fileId, 4, "{$day} 14:00:00");

        $before = currentUsed($drive, $testEmail);
        $result = $versioning->pruneFileVersions($testEmail, $fileId);

        // Survivors: the pinned row + the newest of the day (v4). Pruned: v1, v3.
        assert_equals(2, (int)$result['deleted'], 'expected 2 rows pruned');
        assert_equals($before - (int)$result['freed_bytes'], currentUsed($drive, $testEmail), 'prune did not refund quota');

        $left = historyRows($versioning->getFileVersions($testEmail, $fileId));
        $numbers = array_map(fn($v) => (int)$v['version_number'], $left);
        sort($numbers);
        assert_equals([2, 4], $numbers, 'wrong survivors: ' . implode(',', $numbers));
    });
}

// ── 6. SNAPSHOT (desktop pre-overwrite endpoint logic) ───────────

if (shouldRun('snapshot')) {
    out("\n--- 6. SNAPSHOT (versions/snapshot before in-place overwrite) ---");

    $sFileId = null;
    $sContent = "FLOWONE-TEST snapshot original\n" . str_repeat("delta\n", 30);

    test('Snapshot archives current content and bumps current_version', function () use (&$drive, $testEmail, &$sFileId, &$versioning, $sContent, &$cleanupFileIds) {
        $file = $drive->uploadFileContent($testEmail, 'flowone-test-snapshot.txt', $sContent, 'text/plain', null);
        assert_true($file !== null, 'upload failed');
        $sFileId = (int)$file['id'];
        $cleanupFileIds[] = $sFileId;

        $before = currentUsed($drive, $testEmail);
        $archive = $versioning->snapshotCurrentVersion($testEmail, $sFileId);
        assert_true($archive !== null, 'snapshotCurrentVersion returned null');
        assert_equals(1, (int)$archive['version_number'], 'snapshot should archive as version 1');

        $file = $drive->getFile($testEmail, $sFileId);
        assert_equals(2, (int)$file['current_version'], 'current_version should bump to 2');

        // Server-managed file: the archive is a pointer move, no quota change.
        assert_equals($before, currentUsed($drive, $testEmail), 'server-managed snapshot must not charge quota');
    });

    test('Snapshot version bytes readable and identical to pre-snapshot content', function () use ($testEmail, &$sFileId, &$versioning, $sContent) {
        $history = historyRows($versioning->getFileVersions($testEmail, $sFileId));
        assert_equals(1, count($history), 'expected 1 history row after snapshot');
        $info = $versioning->getVersionFilePath($testEmail, $sFileId, (int)$history[0]['id']);
        assert_true($info !== null && is_file($info['path']), 'snapshot version bytes unreadable');
        assert_equals(md5($sContent), md5(file_get_contents($info['path'])), 'snapshot content mismatch');
    });

    test('Unversioned content write must not destroy snapshot bytes', function () use (&$drive, $testEmail, &$sFileId, &$versioning, $sContent) {
        // The createVersion=false path unlinks the old filename - which the
        // snapshot row adopted. The guard must keep those bytes alive.
        $updated = $drive->updateFileContent($testEmail, $sFileId, tmpContentFile("FLOWONE-TEST snapshot overwrite\n"), false);
        assert_true($updated !== null, 'updateFileContent failed');

        $history = historyRows($versioning->getFileVersions($testEmail, $sFileId));
        assert_equals(1, count($history), 'snapshot row vanished');
        $info = $versioning->getVersionFilePath($testEmail, $sFileId, (int)$history[0]['id']);
        assert_true($info !== null && is_file($info['path']), 'snapshot bytes were unlinked by the unversioned write');
        assert_equals(md5($sContent), md5(file_get_contents($info['path'])), 'snapshot bytes corrupted');
    });
}

// ── 7. USAGE / CLEANUP ───────────────────────────────────────────

if (shouldRun('usage-cleanup')) {
    out("\n--- 7. USAGE / CLEANUP ---");

    $uFileId = null;

    test('getVersionsUsage reflects new version history', function () use (&$drive, $testEmail, &$uFileId, &$versioning, &$cleanupFileIds) {
        $usageBefore = $versioning->getVersionsUsage($testEmail);

        $file = $drive->uploadFileContent($testEmail, 'flowone-test-usage.txt', "FLOWONE-TEST usage v1\n", 'text/plain', null);
        assert_true($file !== null, 'upload failed');
        $uFileId = (int)$file['id'];
        $cleanupFileIds[] = $uFileId;
        $drive->updateFileContent($testEmail, $uFileId, tmpContentFile("FLOWONE-TEST usage v2\n"), true);

        $usage = $versioning->getVersionsUsage($testEmail);
        assert_equals($usageBefore['version_count'] + 1, $usage['version_count'], 'version_count did not grow by 1');
        assert_true($usage['version_bytes'] > $usageBefore['version_bytes'], 'version_bytes did not grow');

        $topIds = array_map(fn($f) => (int)$f['file_id'], $usage['top_files']);
        assert_true(in_array($uFileId, $topIds, true) || count($usage['top_files']) === 10,
            'test file missing from top_files (and list not full)');
    });

    test('cleanupFileVersions removes history and refunds quota', function () use (&$drive, $testEmail, &$uFileId, &$versioning) {
        $history = historyRows($versioning->getFileVersions($testEmail, $uFileId));
        $expectBytes = array_sum(array_map(fn($v) => (int)$v['size'], $history));

        $before = currentUsed($drive, $testEmail);
        $result = $versioning->cleanupFileVersions($testEmail, $uFileId);

        assert_equals(count($history), (int)$result['deleted'], 'cleanup deleted wrong count');
        assert_equals($expectBytes, (int)$result['freed_bytes'], 'cleanup freed_bytes mismatch');
        assert_equals($before - $expectBytes, currentUsed($drive, $testEmail), 'cleanup did not refund quota');
        assert_equals(0, count(historyRows($versioning->getFileVersions($testEmail, $uFileId))), 'history not empty after cleanup');
    });
}

// ── Cleanup + summary ────────────────────────────────────────────

doCleanup();

summary:

out("\n=================================================================");
out("  SUMMARY");
out("=================================================================");
out("  Total:    {$totalTests}");
out("  {$GREEN}Passed:   {$passed}{$NC}");
out("  {$RED}Failed:   {$failed}{$NC}");
out("  {$YELLOW}Warnings: {$warnings}{$NC}");

if ($failed > 0) {
    out("\n  Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    {$RED}- {$r['name']}: " . ($r['error'] ?? '?') . "{$NC}");
        }
    }
}

out("\n  Log: {$logFile}");

if ($jsonMode) {
    echo json_encode([
        'suite' => 'drive-versioning',
        'account' => $testEmail,
        'timestamp' => date('c'),
        'total' => $totalTests,
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'results' => $results,
        'log' => $logFile,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

exit($failed > 0 ? 1 : 0);
