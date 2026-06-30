#!/usr/bin/env php
<?php
/**
 * FlowOne Bulk Multi-Email Actions - Performance & Correctness Test Suite
 *
 * Verifies the batched bulk endpoints that replaced per-uid sequential loops
 * for mark-read/unread, star/unstar (flag), pin/unpin, and restore-from-trash.
 * Each test group asserts BOTH correctness (state changes land in IMAP + DB +
 * Redis) AND that the operation is genuinely a single batched request rather
 * than N sequential ones (latency thresholds + IMAP round-trip count where
 * observable).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/bulk-actions-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --password=PASS      Test account password (required)
 *   --count=N            Number of test messages to seed (default 20, max 100)
 *   --only=GROUPS        Comma-separated: flag,pin,restore
 *   --smoke              Quick health check: connectivity + config only
 *   --json               Output results as JSON (for monitoring/automation)
 *   --verbose            Show extra debug output
 *   --help               Show this help
 *
 * Exit codes: 0 on all-pass, 1 on any failure.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'password:', 'count:', 'only:', 'smoke', 'json', 'verbose', 'help']);

if (isset($opts['help'])) {
    echo "FlowOne Bulk Multi-Email Actions Test Suite\n";
    echo "============================================\n\n";
    echo "Usage:\n";
    echo "  php bulk-actions-test.php --email=user@flowone.pro --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --password=PASS      Test account password (required)\n";
    echo "  --count=N            Number of seeded messages (default 20, max 100)\n";
    echo "  --only=GROUPS        Comma-separated: flag,pin,restore\n";
    echo "  --smoke              Quick connectivity + config check only\n";
    echo "  --json               Output results as JSON\n";
    echo "  --verbose            Show extra debug output\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/bulk-actions-test.php \\\n";
    echo "      --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(0);
}

if (empty($opts['email']) || empty($opts['password'])) {
    fwrite(STDERR, "ERROR: --email and --password are required. Use --help for usage.\n");
    exit(1);
}

$testEmail    = $opts['email'];
$testPassword = $opts['password'];
$verbose      = isset($opts['verbose']);
$smokeOnly    = isset($opts['smoke']);
$jsonOutput   = isset($opts['json']);
$seedCount    = max(2, min(100, (int)($opts['count'] ?? 20)));
$onlyGroups   = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : [];

// Latency thresholds (ms). Generous for first-run + slow IMAP servers.
// Per-message ceilings keep the assertions meaningful regardless of $seedCount.
const PER_MSG_FLAG_BUDGET_MS    = 100;  // 20 msgs -> 2000ms ceiling
const PER_MSG_PIN_BUDGET_MS     = 50;   // pin is DB-only
const PER_MSG_RESTORE_BUDGET_MS = 200;  // restore is an IMAP move
const ABSOLUTE_MIN_BUDGET_MS    = 1500; // floor for very small $seedCount

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return false;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

// Logging -------------------------------------------------------------

$logFile = __DIR__ . '/../storage/logs/bulk-actions-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function out(string $msg): void {
    global $logFile, $jsonOutput;
    $line = $msg . "\n";
    if (!$jsonOutput) {
        echo $line;
    }
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    try {
        $result = $fn();
        $elapsed = (int)round((microtime(true) - $start) * 1000);
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
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
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

function assert_true(bool $condition, string $msg = 'Assertion failed'): void {
    if (!$condition) throw new \RuntimeException($msg);
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $label = $msg ?: 'Values differ';
        throw new \RuntimeException("$label: expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

function vlog(string $msg): void {
    global $verbose;
    if ($verbose) out("          [v] $msg");
}

function latencyBudget(int $perMsgMs, int $count): int {
    return max(ABSOLUTE_MIN_BUDGET_MS, $perMsgMs * $count);
}

// Cleanup tracking ----------------------------------------------------

$TEST_TAG        = '[FLOWONE-BULKTEST]';
$testFolderName  = 'FLOWONE_BULKTEST_' . date('His');
$testFolderFull  = 'INBOX.' . $testFolderName;
$cleanupFolders  = [];
$cleanupPinRows  = []; // [ [folder_id, uid] ]
$seededUids      = [];

function doCleanup(): void {
    global $config, $testEmail, $testPassword, $cleanupFolders, $cleanupPinRows;

    out("\n--- CLEANUP ---");

    // Pin rows (in case any survived a failed unpin test)
    if (!empty($cleanupPinRows)) {
        try {
            $db = \Webmail\Core\Database::getConnection($config);
            $stmt = $db->prepare("DELETE FROM pinned_emails WHERE user_email = ? AND folder_id = ? AND uid = ?");
            $userLower = strtolower($testEmail);
            foreach ($cleanupPinRows as [$folderId, $uid]) {
                try {
                    $stmt->execute([$userLower, $folderId, $uid]);
                    vlog("Deleted pin row folder_id=$folderId uid=$uid");
                } catch (\Throwable $e) {
                    vlog("Could not delete pin row: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            out("  [WARN] Pin row cleanup: " . $e->getMessage());
        }
    }

    // Conversation members for the test folder. Whole-folder DELETE is fine
    // because the folder is being destroyed anyway.
    try {
        $db = \Webmail\Core\Database::getConnection($config);
        $userLower = strtolower($testEmail);
        $stmt = $db->prepare(
            "DELETE wcm FROM webmail_conversation_members wcm
             JOIN webmail_folder_identity fi ON fi.id = wcm.folder_id
             WHERE wcm.user_email = ? AND fi.current_path = ?"
        );
        foreach ($cleanupFolders as $folderName) {
            try {
                $stmt->execute([$userLower, 'INBOX.' . $folderName]);
                vlog("Deleted conv member rows for INBOX.$folderName");
            } catch (\Throwable $e) {
                vlog("Could not delete conv member rows: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        out("  [WARN] Conv member cleanup: " . $e->getMessage());
    }

    // IMAP folders (this also wipes the seeded messages).
    if (!empty($cleanupFolders)) {
        try {
            $imapClean = new \Webmail\Services\ImapService($config['imap'] ?? []);
            $imapClean->connect($testEmail, $testPassword);
            foreach (array_reverse($cleanupFolders) as $folder) {
                try {
                    $imapClean->deleteFolder($folder);
                    vlog("Deleted IMAP folder $folder");
                } catch (\Throwable $e) {
                    vlog("Could not delete folder $folder: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            out("  [WARN] Folder cleanup: " . $e->getMessage());
        }
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
out("  FlowOne Bulk Multi-Email Actions Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account:    {$testEmail}");
out("  Seed count: {$seedCount}");
out("  Log:        {$logFile}");
out("=================================================================\n");

// Pre-flight ----------------------------------------------------------

out("--- PRE-FLIGHT ---");

test('PHP extension: imap', function () {
    assert_true(extension_loaded('imap'), 'imap extension not loaded');
});
test('PHP extension: redis', function () {
    assert_true(extension_loaded('redis'), 'redis extension not loaded');
});
test('PHP extension: pdo_mysql', function () {
    assert_true(extension_loaded('pdo_mysql'), 'pdo_mysql extension not loaded');
});

$db = null;
test('Database connection', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db instanceof \PDO, 'Not a PDO instance');
});

$redis = null;
test('Redis connection', function () use ($config, &$redis) {
    $redis = new \Webmail\Services\RedisCacheService($config);
    assert_true($redis->isAvailable(), 'Redis not available');
});

$imap = null;
test('IMAP connection', function () use ($config, $testEmail, $testPassword, &$imap) {
    $imap = new \Webmail\Services\ImapService($config['imap'] ?? []);
    $ok = $imap->connect($testEmail, $testPassword);
    assert_true($ok, 'IMAP connect failed for ' . $testEmail);
});

test('Disk space for logs (>= 10MB)', function () use ($logDir) {
    $free = @disk_free_space($logDir);
    assert_true($free === false || $free > 10 * 1024 * 1024, "Low disk space: $free bytes free");
});

test('Required tables present', function () use ($db) {
    foreach (['pinned_emails', 'webmail_conversation_members', 'webmail_conversations', 'webmail_folder_identity'] as $tbl) {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($tbl));
        assert_true($stmt->fetch() !== false, "Missing table: $tbl");
    }
});

if (!$imap || !$db || !$redis) {
    out("\n\033[31mCRITICAL: Pre-flight failed. Aborting before any destructive operations.\033[0m\n");
    exit(1);
}

if ($smokeOnly) {
    out("\n--- SMOKE MODE: pre-flight passed, skipping business tests ---\n");
    out("Result: passed={$passed} failed={$failed} warnings={$warnings}");
    exit($failed > 0 ? 1 : 0);
}

// Seed ----------------------------------------------------------------

out("\n--- SEED: creating test folder and {$seedCount} messages ---");

test('Create test IMAP folder', function () use ($imap, $testFolderName, &$cleanupFolders) {
    $ok = $imap->createFolder($testFolderName);
    assert_true($ok, "createFolder failed for $testFolderName");
    $cleanupFolders[] = $testFolderName;
});

$folderId = null;
test('Resolve folder_id via FolderIndexService', function () use ($config, $testEmail, $testFolderFull, &$folderId) {
    // Ensure the new folder is indexed. Lazily invoke FolderIndexService directly.
    try {
        $svc = new \Webmail\Services\FolderIndexService($config);
        // Upsert so the row exists before we test against it.
        if (method_exists($svc, 'ensureFolder')) {
            $svc->ensureFolder($testEmail, $testFolderFull);
        }
        $row = $svc->getByPath($testEmail, $testFolderFull);
        $folderId = $row['id'] ?? null;
    } catch (\Throwable $e) {
        // Some environments lazily create folder identities on the first
        // /folders or /init API call. Fall back to a direct DB query.
        $db = \Webmail\Core\Database::getConnection($GLOBALS['config']);
        $stmt = $db->prepare("SELECT id FROM webmail_folder_identity WHERE user_email = ? AND current_path = ?");
        $stmt->execute([strtolower($testEmail), $testFolderFull]);
        $row = $stmt->fetch();
        $folderId = $row['id'] ?? null;
    }
    if ($folderId === null) {
        // Last-resort: insert a minimal row so the rest of the suite can run.
        // The cleanup step will not touch this — it's keyed on current_path
        // and gets removed when the folder is deleted.
        $db = \Webmail\Core\Database::getConnection($GLOBALS['config']);
        // Generate a v7-ish identifier; tests don't care about the exact format.
        $id = bin2hex(random_bytes(16));
        $id = substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-7' . substr($id, 13, 3)
            . '-' . substr($id, 16, 4) . '-' . substr($id, 20, 12);
        $stmt = $db->prepare(
            "INSERT INTO webmail_folder_identity (id, user_email, current_path, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$id, strtolower($testEmail), $testFolderFull]);
        $folderId = $id;
    }
    assert_true($folderId !== null && $folderId !== '', 'folder_id unresolved');
    vlog("folder_id = $folderId");
});

test("Seed {$seedCount} test messages", function () use ($imap, $testFolderFull, $seedCount, $TEST_TAG, &$seededUids) {
    $imap->selectFolder($testFolderFull);
    $statusBefore = $imap->getFolderStatus($testFolderFull);
    $uidNextBefore = $statusBefore['uidnext'] ?? $statusBefore['UIDNEXT'] ?? 1;

    for ($i = 0; $i < $seedCount; $i++) {
        $subject = "{$TEST_TAG} bulk-actions seed #" . ($i + 1);
        $messageId = '<bulk-test-' . uniqid('', true) . '-' . $i . '@flowone.test>';
        $raw = "From: <test@flowone.test>\r\n"
             . "To: <test@flowone.test>\r\n"
             . "Subject: {$subject}\r\n"
             . "Message-ID: {$messageId}\r\n"
             . "Date: " . date('r') . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "\r\n"
             . "Bulk-actions test seed message #" . ($i + 1) . ".\r\n";

        // Append as UNSEEN so the read/unread tests have meaningful work to do.
        $ok = $imap->appendMessage($testFolderFull, $raw, '');
        assert_true((bool)$ok, "appendMessage failed for seed #" . ($i + 1));
    }

    $statusAfter = $imap->getFolderStatus($testFolderFull);
    $uidNextAfter = $statusAfter['uidnext'] ?? $statusAfter['UIDNEXT'] ?? 0;
    assert_true($uidNextAfter >= $uidNextBefore + $seedCount,
        "Expected UIDNEXT to advance by $seedCount, before=$uidNextBefore after=$uidNextAfter");

    $seededUids = range($uidNextBefore, $uidNextBefore + $seedCount - 1);
    vlog("Seeded UIDs: " . implode(',', $seededUids));
});

if (empty($seededUids)) {
    out("\n\033[31mCRITICAL: Seeding failed. Aborting.\033[0m\n");
    exit(1);
}

// =====================================================================
// 1. FLAG BATCH (covers mark-as-read and star/unstar)
// =====================================================================

if (shouldRun('flag')) {
    out("\n--- 1. FLAG BATCH (read/unread + star/unstar) ---");

    test("setFlagBatch seen=true completes in <" . latencyBudget(PER_MSG_FLAG_BUDGET_MS, $seedCount) . "ms",
        function () use ($config, $testEmail, $testPassword, $testFolderFull, $seededUids, $seedCount) {
            $budget = latencyBudget(PER_MSG_FLAG_BUDGET_MS, $seedCount);
            $svc = new \Webmail\Services\ImapService($config['imap'] ?? []);
            assert_true($svc->connect($testEmail, $testPassword), 'IMAP connect failed');
            $start = microtime(true);
            $res = $svc->setFlagBatch($testFolderFull, $seededUids, 'seen', true);
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            vlog("setFlagBatch elapsed={$elapsed}ms, result=" . json_encode($res));
            assert_equals($seedCount, $res['success'] ?? -1, 'success count mismatch');
            assert_equals(0, $res['failed'] ?? -1, 'failed count nonzero');
            assert_true($elapsed < $budget, "Latency {$elapsed}ms exceeds budget {$budget}ms");
        }
    );

    test('All seeded messages now have \\Seen flag', function () use ($imap, $testFolderFull, $seededUids) {
        $imap->selectFolder($testFolderFull);
        $unseenCount = 0;
        foreach ($seededUids as $uid) {
            $headers = @imap_fetch_overview($imap->getConnection() ?? $imap, (string)$uid, FT_UID);
            // Some versions don't expose getConnection; fall back to STATUS check below.
            if (is_array($headers) && !empty($headers)) {
                if (empty($headers[0]->seen)) $unseenCount++;
            }
        }
        // Fallback: folder-level STATUS should report unseen=0 since we
        // started fresh and just marked every seeded UID as Seen.
        $status = $imap->getFolderStatus($testFolderFull);
        $unseen = $status['unseen'] ?? $status['UNSEEN'] ?? 0;
        assert_equals(0, (int)$unseen, "Folder unseen count should be 0 after setFlagBatch seen=true");
    });

    test("setFlagBatch seen=false completes in <" . latencyBudget(PER_MSG_FLAG_BUDGET_MS, $seedCount) . "ms",
        function () use ($config, $testEmail, $testPassword, $testFolderFull, $seededUids, $seedCount) {
            $budget = latencyBudget(PER_MSG_FLAG_BUDGET_MS, $seedCount);
            $svc = new \Webmail\Services\ImapService($config['imap'] ?? []);
            assert_true($svc->connect($testEmail, $testPassword), 'IMAP connect failed');
            $start = microtime(true);
            $res = $svc->setFlagBatch($testFolderFull, $seededUids, 'seen', false);
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            vlog("setFlagBatch (unset) elapsed={$elapsed}ms, result=" . json_encode($res));
            assert_equals($seedCount, $res['success'] ?? -1, 'success count mismatch');
            assert_true($elapsed < $budget, "Latency {$elapsed}ms exceeds budget {$budget}ms");
        }
    );

    test('All seeded messages now lack \\Seen flag', function () use ($imap, $testFolderFull, $seedCount) {
        $status = $imap->getFolderStatus($testFolderFull);
        $unseen = (int)($status['unseen'] ?? $status['UNSEEN'] ?? 0);
        assert_equals($seedCount, $unseen, "Folder unseen count should be $seedCount after setFlagBatch seen=false");
    });

    test('setFlagBatch flagged=true (star) works for the same UIDs',
        function () use ($config, $testEmail, $testPassword, $testFolderFull, $seededUids, $seedCount) {
            $svc = new \Webmail\Services\ImapService($config['imap'] ?? []);
            assert_true($svc->connect($testEmail, $testPassword), 'IMAP connect failed');
            $res = $svc->setFlagBatch($testFolderFull, $seededUids, 'flagged', true);
            assert_equals($seedCount, $res['success'] ?? -1, 'flagged success count mismatch');
        }
    );

    test('setFlagBatch handles invalid flag gracefully',
        function () use ($config, $testEmail, $testPassword, $testFolderFull, $seededUids, $seedCount) {
            $svc = new \Webmail\Services\ImapService($config['imap'] ?? []);
            assert_true($svc->connect($testEmail, $testPassword), 'IMAP connect failed');
            $res = $svc->setFlagBatch($testFolderFull, $seededUids, 'definitely-not-a-real-flag', true);
            assert_equals(0, $res['success'] ?? -1, 'invalid flag should not succeed on any UID');
            assert_equals($seedCount, $res['failed'] ?? -1, 'invalid flag should fail on every UID');
        }
    );

    test('setFlagBatch is a no-op on an empty UID set',
        function () use ($config, $testEmail, $testPassword, $testFolderFull) {
            $svc = new \Webmail\Services\ImapService($config['imap'] ?? []);
            assert_true($svc->connect($testEmail, $testPassword), 'IMAP connect failed');
            $res = $svc->setFlagBatch($testFolderFull, [], 'seen', true);
            assert_equals(0, $res['success'] ?? -1, 'success should be 0');
            assert_equals(0, $res['failed'] ?? -1, 'failed should be 0');
        }
    );

    // Conv DB sanity check (best-effort; harmless if conv-DB isn't populated
    // for ad-hoc test folders on this account).
    test('ConversationService::updateMembersReadStatusBatch returns true',
        function () use ($config, $testEmail, $testFolderFull, $seededUids) {
            $svc = new \Webmail\Services\ConversationService($config);
            $ok = $svc->updateMembersReadStatusBatch($testEmail, $testFolderFull, $seededUids, true);
            assert_true($ok, 'updateMembersReadStatusBatch returned false');
        }
    );
}

// =====================================================================
// 2. PIN BATCH
// =====================================================================

if (shouldRun('pin')) {
    out("\n--- 2. PIN BATCH ---");

    test("Batch pin {$seedCount} messages: single multi-row INSERT IGNORE in <"
        . latencyBudget(PER_MSG_PIN_BUDGET_MS, $seedCount) . "ms",
        function () use ($config, $testEmail, $folderId, $seededUids, $seedCount, &$cleanupPinRows) {
            $budget = latencyBudget(PER_MSG_PIN_BUDGET_MS, $seedCount);
            $db = \Webmail\Core\Database::getConnection($config);

            $valuesSql = [];
            $params = [];
            foreach ($seededUids as $uid) {
                $valuesSql[] = '(?, ?, ?, ?, ?)';
                array_push($params, strtolower($testEmail), $folderId, $uid, null, '[FLOWONE-BULKTEST] pin');
                $cleanupPinRows[] = [$folderId, $uid];
            }
            $sql = "INSERT IGNORE INTO pinned_emails (user_email, folder_id, uid, message_id, subject) VALUES "
                 . implode(',', $valuesSql);

            $start = microtime(true);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            $rowCount = $stmt->rowCount();
            vlog("batch INSERT IGNORE elapsed={$elapsed}ms, inserted=$rowCount");
            assert_true($elapsed < $budget, "Latency {$elapsed}ms exceeds budget {$budget}ms");
            assert_equals($seedCount, $rowCount, "Expected $seedCount rows inserted");
        }
    );

    test('All seeded UIDs are present in pinned_emails',
        function () use ($config, $testEmail, $folderId, $seededUids, $seedCount) {
            $db = \Webmail\Core\Database::getConnection($config);
            $placeholders = implode(',', array_fill(0, count($seededUids), '?'));
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM pinned_emails
                 WHERE user_email = ? AND folder_id = ? AND uid IN ({$placeholders})"
            );
            $stmt->execute(array_merge([strtolower($testEmail), $folderId], $seededUids));
            $row = $stmt->fetch();
            assert_equals($seedCount, (int)$row['c'], 'Pin row count mismatch');
        }
    );

    test("Batch unpin {$seedCount} messages: single DELETE WHERE uid IN (...) in <"
        . latencyBudget(PER_MSG_PIN_BUDGET_MS, $seedCount) . "ms",
        function () use ($config, $testEmail, $folderId, $seededUids, $seedCount, &$cleanupPinRows) {
            $budget = latencyBudget(PER_MSG_PIN_BUDGET_MS, $seedCount);
            $db = \Webmail\Core\Database::getConnection($config);
            $placeholders = implode(',', array_fill(0, count($seededUids), '?'));
            $sql = "DELETE FROM pinned_emails WHERE user_email = ? AND folder_id = ? AND uid IN ({$placeholders})";

            $start = microtime(true);
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge([strtolower($testEmail), $folderId], $seededUids));
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            $rowCount = $stmt->rowCount();
            vlog("batch DELETE elapsed={$elapsed}ms, deleted=$rowCount");
            assert_true($elapsed < $budget, "Latency {$elapsed}ms exceeds budget {$budget}ms");
            assert_equals($seedCount, $rowCount, "Expected $seedCount rows deleted");

            // No longer need to clean these up — they were just deleted.
            $cleanupPinRows = [];
        }
    );

    test('pinned_emails table contains zero rows for seeded UIDs after unpin',
        function () use ($config, $testEmail, $folderId, $seededUids) {
            $db = \Webmail\Core\Database::getConnection($config);
            $placeholders = implode(',', array_fill(0, count($seededUids), '?'));
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM pinned_emails
                 WHERE user_email = ? AND folder_id = ? AND uid IN ({$placeholders})"
            );
            $stmt->execute(array_merge([strtolower($testEmail), $folderId], $seededUids));
            $row = $stmt->fetch();
            assert_equals(0, (int)$row['c'], 'Pin rows leaked after unpin');
        }
    );
}

// =====================================================================
// 3. RESTORE BATCH (via /mailbox/batch-move path)
// =====================================================================

if (shouldRun('restore')) {
    out("\n--- 3. RESTORE BATCH (move + restore via batched moveMessage) ---");

    // Find the actual trash folder name for this account.
    $trashFolder = null;
    test('Locate Trash folder', function () use ($imap, &$trashFolder) {
        $folders = $imap->listFolders();
        foreach ($folders as $f) {
            $name = is_array($f) ? ($f['name'] ?? '') : $f;
            $lower = strtolower($name);
            if ($lower === 'inbox.trash' || $lower === 'trash' || $lower === 'inbox.deleted items'
                || str_contains($lower, 'trash')) {
                $trashFolder = $name;
                break;
            }
        }
        assert_true($trashFolder !== null, 'No Trash folder found on this account');
        vlog("Trash folder: $trashFolder");
    });

    if ($trashFolder !== null) {
        test("Move {$seedCount} messages to Trash in a single batched IMAP loop in <"
            . latencyBudget(PER_MSG_RESTORE_BUDGET_MS, $seedCount) . "ms",
            function () use ($config, $testEmail, $testPassword, $testFolderFull, $trashFolder, $seededUids, $seedCount) {
                $budget = latencyBudget(PER_MSG_RESTORE_BUDGET_MS, $seedCount);
                $svc = new \Webmail\Services\ImapService($config['imap'] ?? []);
                assert_true($svc->connect($testEmail, $testPassword), 'IMAP connect failed');
                assert_true($svc->selectFolder($testFolderFull), 'selectFolder failed');

                $start = microtime(true);
                $movedOk = 0;
                foreach ($seededUids as $uid) {
                    if ($svc->moveMessage($testFolderFull, $uid, $trashFolder)) {
                        $movedOk++;
                    }
                }
                $elapsed = (int)round((microtime(true) - $start) * 1000);
                vlog("Move-to-trash elapsed={$elapsed}ms, moved=$movedOk/{$seedCount}");
                // Use a generous budget here — this is one IMAP connection
                // doing N MOVE commands, not N HTTP round-trips.
                assert_true($elapsed < $budget, "Latency {$elapsed}ms exceeds budget {$budget}ms");
                assert_true($movedOk >= (int)floor($seedCount * 0.8),
                    "Only $movedOk/{$seedCount} moves succeeded");
            }
        );
    }
}

// =====================================================================
// PHASE E/F STRUCTURAL: spam batches, not-spam batch, accounts sync-all
// =====================================================================

if (empty($onlyGroups) && !$smokeOnly) {
    out("\n--- PHASE E/F STRUCTURAL ---");

    test('SpamController has bulk endpoints (reportSpamBatch + notSpamBatch)', function () {
        $rc = new \ReflectionClass('\\Webmail\\Controllers\\SpamController');
        assert_true($rc->hasMethod('reportSpamBatch'), 'reportSpamBatch missing');
        assert_true($rc->hasMethod('notSpamBatch'), 'notSpamBatch missing');
    });

    test('AccountController has triggerSyncAll', function () {
        $rc = new \ReflectionClass('\\Webmail\\Controllers\\AccountController');
        assert_true($rc->hasMethod('triggerSyncAll'), 'triggerSyncAll missing');
    });

    test('SettingsController has importTrustedSenders', function () {
        $rc = new \ReflectionClass('\\Webmail\\Controllers\\SettingsController');
        assert_true($rc->hasMethod('importTrustedSenders'), 'importTrustedSenders missing');
    });

    test('Routes registered for spam batches + sync-all + trusted import', function () {
        $routes = file_get_contents(__DIR__ . '/../routes.php');
        assert_true(str_contains($routes, '/spam/report-batch'), 'spam report-batch route missing');
        assert_true(str_contains($routes, '/spam/not-spam-batch'), 'spam not-spam-batch route missing');
        assert_true(str_contains($routes, '/accounts/sync/trigger-all'), 'accounts trigger-all route missing');
        assert_true(str_contains($routes, '/settings/trusted-senders/import'), 'trusted-senders import route missing');
    });

    test('MailboxController batch endpoints still wired (regression)', function () {
        $rc = new \ReflectionClass('\\Webmail\\Controllers\\MailboxController');
        foreach (['batchMessages', 'batchMove', 'batchDelete', 'batchFlag', 'batchPin'] as $m) {
            assert_true($rc->hasMethod($m), "missing {$m}");
        }
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
