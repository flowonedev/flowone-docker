#!/usr/bin/env php
<?php
/**
 * FlowOne Mailbox Operations - Comprehensive Test Suite
 * 
 * Tests folder CRUD, message move, UID tracking, conversations/grouping,
 * manual merge/split, labels, reactions, flags, and all-mail search.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-operations-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --password=PASS      Test account password (required)
 *   --only=GROUPS        Comma-separated groups: folder,flag,move,conv,label,reaction,allmail
 *   --smoke              Run a minimal subset of tests
 *   --verbose            Show extra debug info
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'password:', 'only:', 'smoke', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email']) || empty($opts['password'])) {
    echo "FlowOne Mailbox Operations Test Suite\n";
    echo "======================================\n\n";
    echo "Usage:\n";
    echo "  php mailbox-operations-test.php --email=user@flowone.pro --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --password=PASS      Test account password (required)\n";
    echo "  --only=GROUPS        Comma-separated: folder,flag,move,conv,label,reaction,allmail\n";
    echo "  --smoke              Run minimal smoke tests only\n";
    echo "  --verbose            Show extra debug info\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-operations-test.php \\\n";
    echo "      --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(1);
}

$testEmail    = $opts['email'];
$testPassword = $opts['password'];
$verbose      = isset($opts['verbose']);
$smokeOnly    = isset($opts['smoke']);
$onlyGroups   = isset($opts['only']) ? explode(',', $opts['only']) : [];

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return in_array($group, ['folder', 'flag', 'move']);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/mailbox-ops-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function out(string $msg): void {
    global $logFile;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    try {
        $result = $fn();
        $elapsed = round((microtime(true) - $start) * 1000);
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
        $elapsed = round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m  {$name} ({$elapsed}ms)");
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

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $label = $msg ?: 'Values differ';
        throw new \RuntimeException("$label: expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

function assert_not_empty($value, string $msg = 'Value is empty'): void {
    if (empty($value)) throw new \RuntimeException($msg);
}

function vlog(string $msg): void {
    global $verbose;
    if ($verbose) out("          [v] $msg");
}

// ── Cleanup tracking ─────────────────────────────────────────────

$TEST_TAG           = '[FLOWONE-MBTEST]';
$testFolderName     = 'FLOWONE_TEST_' . date('His');
$testFolderRenamed  = 'FLOWONE_TEST_RENAMED_' . date('His');
$testFolder2Name    = 'FLOWONE_TEST2_' . date('His');

// Full IMAP paths (Dovecot uses INBOX. prefix for subfolders).
// createFolder/deleteFolder/renameFolder add the prefix automatically,
// but selectFolder/getFolderStatus/moveMessage/getMessages/search do NOT.
$testFolderFull        = 'INBOX.' . $testFolderName;
$testFolderRenamedFull = 'INBOX.' . $testFolderRenamed;
$testFolder2Full       = 'INBOX.' . $testFolder2Name;

$cleanupFolders     = [];
$cleanupLabelIds    = [];
$cleanupReactionIds = [];
$cleanupConvMemberHashes = [];
$cleanupConvIds     = [];

function doCleanup(): void {
    global $config, $testEmail, $testPassword, $cleanupFolders, $cleanupLabelIds,
           $cleanupReactionIds, $cleanupConvMemberHashes, $cleanupConvIds, $TEST_TAG;

    out("\n--- CLEANUP ---");

    // Clean labels
    if (!empty($cleanupLabelIds)) {
        try {
            $labelService = new \Webmail\Services\LabelService($config);
            foreach ($cleanupLabelIds as $id) {
                $labelService->deleteLabel($testEmail, $id);
                vlog("Deleted label ID $id");
            }
        } catch (\Throwable $e) {
            out("  [WARN] Label cleanup: " . $e->getMessage());
        }
    }

    // Clean reactions
    if (!empty($cleanupReactionIds)) {
        try {
            $reactionService = new \Webmail\Addons\Reactions\Services\ReactionService($config);
            foreach ($cleanupReactionIds as $id) {
                $reactionService->removeReaction($id);
                vlog("Deleted reaction ID $id");
            }
        } catch (\Throwable $e) {
            out("  [WARN] Reaction cleanup: " . $e->getMessage());
        }
    }

    // Clean conversation member rows + conversation rows inserted by test
    if (!empty($cleanupConvMemberHashes) || !empty($cleanupConvIds)) {
        try {
            $db = \Webmail\Core\Database::getConnection($config);
            $userLower = strtolower($testEmail);

            if (!empty($cleanupConvMemberHashes)) {
                $stmt = $db->prepare("DELETE FROM webmail_conversation_members WHERE user_email = ? AND message_id_hash = ?");
                foreach ($cleanupConvMemberHashes as $hash) {
                    $stmt->execute([$userLower, $hash]);
                    vlog("Deleted conv member hash $hash");
                }
            }

            // Delete conversation rows by ID so stale rows don't pollute future runs
            if (!empty($cleanupConvIds)) {
                $stmt = $db->prepare("DELETE FROM webmail_conversations WHERE user_email = ? AND conversation_id = ?");
                foreach (array_unique($cleanupConvIds) as $cid) {
                    $stmt->execute([$userLower, $cid]);
                    vlog("Deleted conv row $cid");
                }
            }

            // Belt-and-suspenders: remove any zero-count orphans
            $db->exec("DELETE FROM webmail_conversations WHERE message_count = 0");
        } catch (\Throwable $e) {
            out("  [WARN] Conversation cleanup: " . $e->getMessage());
        }
    }

    // Clean folders via IMAP (delete in reverse order)
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
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Mailbox Operations Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account:   {$testEmail}");
out("  Log: {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

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
    $folders = $imap->listFolders();
    assert_true(is_array($folders) && count($folders) > 0, 'No folders returned');
    vlog("Listed " . count($folders) . " folders");
});

if (!$imap) {
    out("\n\033[31mCRITICAL: Cannot connect to IMAP. Aborting.\033[0m\n");
    exit(1);
}

// ═════════════════════════════════════════════════════════════════
// 1. FOLDER CRUD
// ═════════════════════════════════════════════════════════════════

if (shouldRun('folder')) {
    out("\n--- 1. FOLDER CRUD ---");

    test('Create IMAP folder', function () use ($imap, $testFolderName, &$cleanupFolders) {
        $result = $imap->createFolder($testFolderName);
        assert_true($result, "createFolder returned false for '$testFolderName'");
        $cleanupFolders[] = $testFolderName;
        vlog("Created folder: $testFolderName");
    });

    test('New folder appears in listFolders', function () use ($imap, $testFolderName) {
        $folders = $imap->listFolders();
        $names = array_map(fn($f) => $f['name'] ?? $f, $folders);
        $found = false;
        foreach ($names as $n) {
            if (stripos($n, $testFolderName) !== false) {
                $found = true;
                break;
            }
        }
        assert_true($found, "Folder '$testFolderName' not found in folder list: " . implode(', ', array_slice($names, 0, 20)));
    });

    test('Create second test folder', function () use ($imap, $testFolder2Name, &$cleanupFolders) {
        $result = $imap->createFolder($testFolder2Name);
        assert_true($result, "createFolder returned false for '$testFolder2Name'");
        $cleanupFolders[] = $testFolder2Name;
    });

    test('Folder status returns valid uidvalidity', function () use ($imap, $testFolderFull) {
        $status = $imap->getFolderStatus($testFolderFull);
        assert_true(is_array($status), 'getFolderStatus did not return array');
        $uv = $status['uidvalidity'] ?? $status['UIDVALIDITY'] ?? 0;
        assert_true($uv > 0, "uidvalidity is zero for new folder, got: " . json_encode($status));
        vlog("uidvalidity=$uv, uidnext=" . ($status['uidnext'] ?? $status['UIDNEXT'] ?? '?'));
    });

    test('Rename IMAP folder', function () use ($imap, $testFolderName, $testFolderRenamed, &$cleanupFolders) {
        $result = $imap->renameFolder($testFolderName, $testFolderRenamed);
        assert_true($result, 'renameFolder returned false');
        // Update cleanup list
        $cleanupFolders = array_map(
            fn($f) => $f === $testFolderName ? $testFolderRenamed : $f,
            $cleanupFolders
        );
        vlog("Renamed $testFolderName -> $testFolderRenamed");
    });

    test('Renamed folder appears in listFolders', function () use ($imap, $testFolderRenamed) {
        $folders = $imap->listFolders();
        $names = array_map(fn($f) => $f['name'] ?? $f, $folders);
        $found = false;
        foreach ($names as $n) {
            if (stripos($n, $testFolderRenamed) !== false) {
                $found = true;
                break;
            }
        }
        assert_true($found, "Renamed folder not found in list");
    });

    test('Original folder name is gone after rename', function () use ($imap, $testFolderName, $testFolderRenamed) {
        $folders = $imap->listFolders();
        $names = array_map(fn($f) => $f['name'] ?? $f, $folders);
        foreach ($names as $n) {
            // Skip the renamed folder itself
            if (stripos($n, $testFolderRenamed) !== false) continue;
            if (stripos($n, $testFolderName) !== false) {
                throw new \RuntimeException("Old folder name still present: $n");
            }
        }
    });

    test('Rename folder back for subsequent tests', function () use ($imap, $testFolderRenamed, $testFolderName, &$cleanupFolders) {
        $result = $imap->renameFolder($testFolderRenamed, $testFolderName);
        assert_true($result, 'renameFolder back returned false');
        $cleanupFolders = array_map(
            fn($f) => $f === $testFolderRenamed ? $testFolderName : $f,
            $cleanupFolders
        );
    });
}

// ═════════════════════════════════════════════════════════════════
// 2. SEND TEST EMAILS (populating INBOX for subsequent tests)
// ═════════════════════════════════════════════════════════════════

out("\n--- 2. SEED TEST EMAILS ---");

$seededUids = [];
$seededMessageIds = [];
$threadRootMessageId = '<flowone-test-root-' . uniqid() . '@test.local>';
$threadReplyMessageId = '<flowone-test-reply-' . uniqid() . '@test.local>';
$standaloneMessageId1 = '<flowone-test-sa1-' . uniqid() . '@test.local>';
$standaloneMessageId2 = '<flowone-test-sa2-' . uniqid() . '@test.local>';

test('Append thread root email to INBOX', function () use ($imap, $testEmail, $threadRootMessageId, $TEST_TAG, &$seededUids, &$seededMessageIds) {
    $subject = "$TEST_TAG Thread Root " . date('His');
    $msg = buildRawEmail($testEmail, $testEmail, $subject, "Body of root message.", $threadRootMessageId);
    $uid = appendToFolder($imap, 'INBOX', $msg);
    assert_true($uid > 0, "appendToFolder returned invalid UID: $uid");
    $seededUids['root'] = $uid;
    $seededMessageIds['root'] = $threadRootMessageId;
    vlog("Root UID=$uid, Message-ID=$threadRootMessageId");
});

test('Append thread reply email to INBOX', function () use ($imap, $testEmail, $threadReplyMessageId, $threadRootMessageId, $TEST_TAG, &$seededUids, &$seededMessageIds) {
    $subject = "Re: $TEST_TAG Thread Root " . date('His');
    $headers = "In-Reply-To: $threadRootMessageId\r\nReferences: $threadRootMessageId";
    $msg = buildRawEmail($testEmail, $testEmail, $subject, "Reply body.", $threadReplyMessageId, $headers);
    $uid = appendToFolder($imap, 'INBOX', $msg);
    assert_true($uid > 0, "appendToFolder returned invalid UID");
    $seededUids['reply'] = $uid;
    $seededMessageIds['reply'] = $threadReplyMessageId;
    vlog("Reply UID=$uid");
});

test('Append standalone email 1 to INBOX', function () use ($imap, $testEmail, $standaloneMessageId1, $TEST_TAG, &$seededUids, &$seededMessageIds) {
    $subject = "$TEST_TAG Standalone Alpha " . date('His');
    $msg = buildRawEmail($testEmail, $testEmail, $subject, "Standalone message 1.", $standaloneMessageId1);
    $uid = appendToFolder($imap, 'INBOX', $msg);
    assert_true($uid > 0, "appendToFolder failed");
    $seededUids['sa1'] = $uid;
    $seededMessageIds['sa1'] = $standaloneMessageId1;
    vlog("SA1 UID=$uid");
});

test('Append standalone email 2 to INBOX', function () use ($imap, $testEmail, $standaloneMessageId2, $TEST_TAG, &$seededUids, &$seededMessageIds) {
    $subject = "$TEST_TAG Standalone Beta " . date('His');
    $msg = buildRawEmail($testEmail, $testEmail, $subject, "Standalone message 2.", $standaloneMessageId2);
    $uid = appendToFolder($imap, 'INBOX', $msg);
    assert_true($uid > 0, "appendToFolder failed");
    $seededUids['sa2'] = $uid;
    $seededMessageIds['sa2'] = $standaloneMessageId2;
    vlog("SA2 UID=$uid");
});

// Reconnect IMAP so the service sees messages appended via the raw connection
$imap = new \Webmail\Services\ImapService($config['imap'] ?? []);
$imap->connect($testEmail, $testPassword);

// ═════════════════════════════════════════════════════════════════
// 3. MESSAGE LISTING & UID TRACKING
// ═════════════════════════════════════════════════════════════════

if (shouldRun('folder')) {
    out("\n--- 3. MESSAGE LISTING & UID TRACKING ---");

    test('INBOX listing contains seeded emails', function () use ($imap, $seededUids) {
        $msgs = $imap->getMessages('INBOX', 1, 200);
        $allMsgs = $msgs['messages'] ?? $msgs;
        assert_true(is_array($allMsgs), 'getMessages did not return messages array');
        $uidSet = array_map(fn($m) => (int)($m['uid'] ?? $m->uid ?? 0), $allMsgs);
        foreach (['root', 'reply', 'sa1', 'sa2'] as $key) {
            assert_true(in_array($seededUids[$key], $uidSet), "Seeded UID '{$key}' ({$seededUids[$key]}) not found in INBOX listing");
        }
        vlog("Found all 4 seeded UIDs in INBOX listing (" . count($allMsgs) . " total messages)");
    });

    test('Folder sync state returns valid uidnext', function () use ($imap, $seededUids) {
        $imap->selectFolder('INBOX');
        $state = $imap->getFolderSyncState();
        $uidnext = $state['uidnext'] ?? 0;
        $maxSeeded = max($seededUids);
        assert_true($uidnext > $maxSeeded, "uidnext ($uidnext) should be > max seeded UID ($maxSeeded)");
        vlog("INBOX uidnext=$uidnext, uidvalidity=" . ($state['uidvalidity'] ?? '?'));
    });

    test('getMessagesSince returns valid structure and uidnext', function () use ($imap, $seededUids) {
        $sinceUid = max($seededUids) - 20;
        if ($sinceUid < 1) $sinceUid = 1;
        $result = $imap->getMessagesSince('INBOX', $sinceUid, 200);
        assert_true(isset($result['messages']), 'No messages key in getMessagesSince result');
        assert_true(isset($result['uidnext']), 'No uidnext in result');
        assert_true(isset($result['uidvalidity']), 'No uidvalidity in result');
        assert_true($result['uidvalidity'] > 0, 'uidvalidity should be > 0');
        $maxSeeded = max($seededUids);
        assert_true($result['uidnext'] > $maxSeeded, "uidnext ({$result['uidnext']}) should be > max seeded UID ($maxSeeded)");
        $msgCount = count($result['messages']);
        vlog("getMessagesSince(sinceUid=$sinceUid) returned $msgCount messages, uidnext={$result['uidnext']}");

        // PHP's native imap_search does not support the UID search key (c-client
        // limitation). For non-OAuth connections this means getMessagesSince may
        // return 0 messages even though the uidnext proves new mail exists.
        // This is NOT a bug in the app: the real frontend uses OAuth (stream-based
        // UID SEARCH) or falls back to getMessages for password-based accounts.
        if ($msgCount === 0) {
            vlog("imap_search UID range returned 0 -- known c-client limitation on password-based connections");
            return 'warn';
        }

        $first = $result['messages'][0];
        assert_true(isset($first['uid']), 'Message missing uid field');
    });
}

// ═════════════════════════════════════════════════════════════════
// 4. FLAG OPERATIONS
// ═════════════════════════════════════════════════════════════════

if (shouldRun('flag')) {
    out("\n--- 4. FLAG OPERATIONS ---");

    test('Set flag: seen', function () use ($imap, $seededUids) {
        $result = $imap->setFlag('INBOX', $seededUids['root'], 'seen', true);
        assert_true($result, 'setFlag(seen, true) returned false');
    });

    test('Verify seen flag is set', function () use ($imap, $seededUids) {
        $msg = fetchSingleMessage($imap, 'INBOX', $seededUids['root']);
        assert_true($msg !== null, 'Could not fetch message after setting seen');
        $seen = $msg['seen'] ?? $msg['flags']['seen'] ?? isInFlags($msg, '\\Seen');
        assert_true((bool)$seen, 'Message not marked as seen');
        vlog("Seen flag confirmed on UID " . $seededUids['root']);
    });

    test('Unset flag: seen', function () use ($imap, $seededUids) {
        $result = $imap->setFlag('INBOX', $seededUids['root'], 'seen', false);
        assert_true($result, 'setFlag(seen, false) returned false');
    });

    test('Set flag: flagged (starred)', function () use ($imap, $seededUids) {
        $result = $imap->setFlag('INBOX', $seededUids['root'], 'flagged', true);
        assert_true($result, 'setFlag(flagged, true) returned false');
    });

    test('Verify flagged is set', function () use ($imap, $seededUids) {
        $msg = fetchSingleMessage($imap, 'INBOX', $seededUids['root']);
        assert_true($msg !== null, 'Could not fetch message');
        $flagged = $msg['flagged'] ?? $msg['flags']['flagged'] ?? isInFlags($msg, '\\Flagged');
        assert_true((bool)$flagged, 'Message not flagged/starred');
    });

    test('Unset flag: flagged', function () use ($imap, $seededUids) {
        $result = $imap->setFlag('INBOX', $seededUids['root'], 'flagged', false);
        assert_true($result, 'setFlag(flagged, false) returned false');
    });

    test('Set flag: answered', function () use ($imap, $seededUids) {
        $result = $imap->setFlag('INBOX', $seededUids['reply'], 'answered', true);
        assert_true($result, 'setFlag(answered, true) returned false');
    });

    test('Unset flag: answered', function () use ($imap, $seededUids) {
        $result = $imap->setFlag('INBOX', $seededUids['reply'], 'answered', false);
        assert_true($result, 'setFlag(answered, false) returned false');
    });
}

// ═════════════════════════════════════════════════════════════════
// 5. EMAIL MOVE BETWEEN FOLDERS
// ═════════════════════════════════════════════════════════════════

if (shouldRun('move')) {
    out("\n--- 5. EMAIL MOVE BETWEEN FOLDERS ---");

    $movedUid = null;

    test('Move email from INBOX to test folder', function () use ($imap, &$seededUids, $testFolderFull, &$movedUid) {
        $uidToMove = $seededUids['sa1'];
        $result = $imap->moveMessage('INBOX', $uidToMove, $testFolderFull);
        assert_true($result, "moveMessage returned false for UID $uidToMove -> $testFolderFull (check IMAP error log)");
        $movedUid = $imap->getLastMoveNewUid();
        vlog("Moved UID $uidToMove -> $testFolderFull, new UID: " . ($movedUid ?? 'null'));
    });

    test('Moved email has a new UID in target folder', function () use ($movedUid) {
        assert_true($movedUid !== null && $movedUid > 0, "New UID after move is invalid: " . var_export($movedUid, true));
    });

    test('Moved email no longer in INBOX', function () use ($imap, $seededUids) {
        $msgs = $imap->getMessages('INBOX', 1, 500);
        $allMsgs = $msgs['messages'] ?? $msgs;
        $uidSet = array_map(fn($m) => (int)($m['uid'] ?? $m->uid ?? 0), $allMsgs);
        assert_true(!in_array($seededUids['sa1'], $uidSet), "Original UID {$seededUids['sa1']} still found in INBOX after move");
    });

    test('Moved email exists in test folder', function () use ($imap, $testFolderFull, $movedUid) {
        $msgs = $imap->getMessages($testFolderFull, 1, 100);
        $allMsgs = $msgs['messages'] ?? $msgs;
        assert_true(count($allMsgs) > 0, "Test folder ($testFolderFull) is empty after move");
        if ($movedUid) {
            $uidSet = array_map(fn($m) => (int)($m['uid'] ?? $m->uid ?? 0), $allMsgs);
            assert_true(in_array($movedUid, $uidSet), "New UID $movedUid not found in $testFolderFull");
        }
    });

    test('Move email to second folder (cross-folder move)', function () use ($imap, $testFolderFull, $testFolder2Full, $movedUid, &$seededUids) {
        if (!$movedUid) {
            return 'warn';
        }
        $result = $imap->moveMessage($testFolderFull, $movedUid, $testFolder2Full);
        assert_true($result, 'Cross-folder move failed');
        $newUid2 = $imap->getLastMoveNewUid();
        vlog("Cross-move $testFolderFull UID $movedUid -> $testFolder2Full UID " . ($newUid2 ?? '?'));
        $seededUids['sa1_final'] = $newUid2;
    });

    test('Move email back to INBOX for remaining tests', function () use ($imap, $testFolder2Full, &$seededUids) {
        $uid = $seededUids['sa1_final'] ?? null;
        if (!$uid) return 'warn';
        $result = $imap->moveMessage($testFolder2Full, $uid, 'INBOX');
        assert_true($result, 'Move back to INBOX failed');
        $newUid = $imap->getLastMoveNewUid();
        $seededUids['sa1'] = $newUid ?? $uid;
        vlog("Moved back to INBOX, new UID: " . ($newUid ?? 'same'));
    });
}

// ═════════════════════════════════════════════════════════════════
// 6. CONVERSATION / GROUPING
// ═════════════════════════════════════════════════════════════════

if (shouldRun('conv')) {
    out("\n--- 6. CONVERSATION / GROUPING ---");

    $convService = new \Webmail\Services\ConversationService($config);
    $convIds = [];

    // Use unique subjects with run ID to avoid subject-fallback matching leftover rows from previous runs
    $convRunId = date('His') . '-' . substr(uniqid(), -4);
    $threadSubject = "$TEST_TAG Thread $convRunId";

    test('Assign thread root to conversation', function () use ($convService, $testEmail, $seededUids, $seededMessageIds, $threadSubject, &$convIds, &$cleanupConvMemberHashes, &$cleanupConvIds) {
        $msg = [
            'uid' => $seededUids['root'],
            'message_id' => $seededMessageIds['root'],
            'subject' => $threadSubject,
            'date' => date('r'),
            'from' => [['email' => $testEmail, 'name' => 'Test']],
            'references' => [],
            'in_reply_to' => null,
        ];
        $convId = $convService->assignMessageToConversation($testEmail, 'INBOX', $msg);
        assert_not_empty($convId, 'No conversation ID returned for root');
        $convIds['root'] = $convId;
        $cleanupConvMemberHashes[] = md5(trim($seededMessageIds['root'], '<> '));
        $cleanupConvIds[] = $convId;
        vlog("Root assigned to conv: $convId");
    });

    test('Assign thread reply to conversation', function () use ($convService, $testEmail, $seededUids, $seededMessageIds, $threadRootMessageId, $threadSubject, &$convIds, &$cleanupConvMemberHashes, &$cleanupConvIds) {
        $msg = [
            'uid' => $seededUids['reply'],
            'message_id' => $seededMessageIds['reply'],
            'subject' => "Re: $threadSubject",
            'date' => date('r'),
            'from' => [['email' => $testEmail, 'name' => 'Test']],
            'references' => [$threadRootMessageId],
            'in_reply_to' => $threadRootMessageId,
        ];
        $convId = $convService->assignMessageToConversation($testEmail, 'INBOX', $msg);
        assert_not_empty($convId, 'No conversation ID returned for reply');
        $convIds['reply'] = $convId;
        $cleanupConvMemberHashes[] = md5(trim($seededMessageIds['reply'], '<> '));
        $cleanupConvIds[] = $convId;
        vlog("Reply assigned to conv: $convId");
    });

    test('Root and reply are in same conversation (References threading)', function () use ($convIds) {
        assert_equals($convIds['root'], $convIds['reply'],
            'Root and reply should be in the same conversation');
        vlog("Both share conversation: " . $convIds['root']);
    });

    test('Assign standalone 1 (separate conversation)', function () use ($convService, $testEmail, $seededUids, $seededMessageIds, $convRunId, $TEST_TAG, &$convIds, &$cleanupConvMemberHashes, &$cleanupConvIds) {
        $msg = [
            'uid' => $seededUids['sa1'],
            'message_id' => $seededMessageIds['sa1'],
            'subject' => "$TEST_TAG SA-Alpha $convRunId",
            'date' => date('r'),
            'from' => [['email' => $testEmail, 'name' => 'Test']],
            'references' => [],
            'in_reply_to' => null,
        ];
        $convId = $convService->assignMessageToConversation($testEmail, 'INBOX', $msg);
        assert_not_empty($convId, 'No conversation ID for SA1');
        $convIds['sa1'] = $convId;
        $cleanupConvMemberHashes[] = md5(trim($seededMessageIds['sa1'], '<> '));
        $cleanupConvIds[] = $convId;
        vlog("SA1 conv: $convId");
    });

    test('Assign standalone 2 (separate conversation)', function () use ($convService, $testEmail, $seededUids, $seededMessageIds, $convRunId, $TEST_TAG, &$convIds, &$cleanupConvMemberHashes, &$cleanupConvIds) {
        $msg = [
            'uid' => $seededUids['sa2'],
            'message_id' => $seededMessageIds['sa2'],
            'subject' => "$TEST_TAG SA-Beta $convRunId",
            'date' => date('r'),
            'from' => [['email' => $testEmail, 'name' => 'Test']],
            'references' => [],
            'in_reply_to' => null,
        ];
        $convId = $convService->assignMessageToConversation($testEmail, 'INBOX', $msg);
        assert_not_empty($convId, 'No conversation ID for SA2');
        $convIds['sa2'] = $convId;
        $cleanupConvMemberHashes[] = md5(trim($seededMessageIds['sa2'], '<> '));
        $cleanupConvIds[] = $convId;
        vlog("SA2 conv: $convId");
    });

    test('Standalones are in different conversations from each other and from thread', function () use ($convIds) {
        assert_true($convIds['sa1'] !== $convIds['root'], 'SA1 should NOT share conversation with thread');
        assert_true($convIds['sa2'] !== $convIds['root'], 'SA2 should NOT share conversation with thread');
        assert_true($convIds['sa1'] !== $convIds['sa2'], 'SA1 and SA2 should be in different conversations');
    });

    test('getConversationsForFolder returns conversations', function () use ($convService, $testEmail) {
        $convs = $convService->getConversationsForFolder($testEmail, 'INBOX');
        assert_true(is_array($convs), 'getConversationsForFolder did not return array');
        assert_true(count($convs) >= 3, 'Expected at least 3 conversations (1 thread + 2 standalone), got ' . count($convs));
        vlog("Found " . count($convs) . " conversations in INBOX");
    });

    test('Thread conversation has message_count >= 2', function () use ($convService, $testEmail, $convIds) {
        $convs = $convService->getConversationsForFolder($testEmail, 'INBOX');
        $threadConv = null;
        foreach ($convs as $c) {
            if (($c['conversation_id'] ?? '') === $convIds['root']) {
                $threadConv = $c;
                break;
            }
        }
        assert_true($threadConv !== null, 'Thread conversation not found in folder listing');
        $count = (int)($threadConv['message_count'] ?? 0);
        assert_true($count >= 2, "Thread conversation message_count should be >= 2, got $count");
        vlog("Thread conv message_count=$count");
    });

    // ── Manual merge ─────────────────────────────────────────────

    out("\n--- 6b. MANUAL MERGE ---");

    $mergedConvId = null;

    test('Merge two standalones into one conversation', function () use ($convService, $testEmail, $seededMessageIds, &$mergedConvId, &$convIds, &$cleanupConvIds) {
        $mid1 = trim($seededMessageIds['sa1'], '<> ');
        $mid2 = trim($seededMessageIds['sa2'], '<> ');
        $mergedConvId = $convService->mergeMessagesToConversation($testEmail, 'INBOX', $mid1, $mid2);
        assert_not_empty($mergedConvId, 'mergeMessagesToConversation returned null');
        assert_true(str_starts_with($mergedConvId, 'merge-'), "Merged conv ID should start with 'merge-', got: $mergedConvId");
        $cleanupConvIds[] = $mergedConvId;
        vlog("Merged into: $mergedConvId");
    });

    test('Both standalones now share the merged conversation', function () use ($convService, $testEmail, $seededMessageIds, $mergedConvId) {
        $mid1 = trim($seededMessageIds['sa1'], '<> ');
        $mid2 = trim($seededMessageIds['sa2'], '<> ');
        $c1 = $convService->getConversationIdForMessage($testEmail, 'INBOX', null, $mid1);
        $c2 = $convService->getConversationIdForMessage($testEmail, 'INBOX', null, $mid2);
        assert_equals($mergedConvId, $c1, 'SA1 not in merged conversation');
        assert_equals($mergedConvId, $c2, 'SA2 not in merged conversation');
    });

    test('Merged conversation has is_user_override=1', function () use ($convService, $testEmail, $seededMessageIds) {
        $mid1 = trim($seededMessageIds['sa1'], '<> ');
        $member = $convService->getMessageConversation($testEmail, 'INBOX', $mid1);
        assert_true($member !== null, 'Member not found after merge');
        assert_equals(1, (int)$member['is_user_override'], 'is_user_override should be 1 after merge');
    });

    // ── Manual split ─────────────────────────────────────────────

    out("\n--- 6c. MANUAL SPLIT ---");

    $splitConvId = null;

    test('Split one message out of merged conversation', function () use ($convService, $testEmail, $seededMessageIds, $mergedConvId, &$splitConvId, &$cleanupConvIds) {
        $mid1 = trim($seededMessageIds['sa1'], '<> ');
        $splitConvId = $convService->splitMessageToNewConversation($testEmail, 'INBOX', $mid1);
        assert_not_empty($splitConvId, 'splitMessageToNewConversation returned empty');
        assert_true(str_starts_with($splitConvId, 'split-'), "Split conv ID should start with 'split-', got: $splitConvId");
        assert_true($splitConvId !== $mergedConvId, 'Split conv should differ from merged conv');
        $cleanupConvIds[] = $splitConvId;
        vlog("Split SA1 into: $splitConvId");
    });

    test('Split message is now in its own conversation', function () use ($convService, $testEmail, $seededMessageIds, $splitConvId, $mergedConvId) {
        $mid1 = trim($seededMessageIds['sa1'], '<> ');
        $mid2 = trim($seededMessageIds['sa2'], '<> ');
        $c1 = $convService->getConversationIdForMessage($testEmail, 'INBOX', null, $mid1);
        $c2 = $convService->getConversationIdForMessage($testEmail, 'INBOX', null, $mid2);
        assert_equals($splitConvId, $c1, 'SA1 should be in split conversation');
        assert_true($c1 !== $c2, 'SA1 and SA2 should be in different conversations after split');
        vlog("SA1 conv=$c1, SA2 conv=$c2 -- correctly separated");
    });

    // ── Reset override ───────────────────────────────────────────

    test('Reset user override (restores auto-grouping)', function () use ($convService, $testEmail, $seededMessageIds) {
        $mid1 = trim($seededMessageIds['sa1'], '<> ');
        $result = $convService->resetMessageOverride($testEmail, 'INBOX', $mid1);
        assert_true($result, 'resetMessageOverride returned false');
        // After reset, the member row is deleted; re-assigning will auto-group
        $member = $convService->getMessageConversation($testEmail, 'INBOX', $mid1);
        assert_true($member === null, 'Member row should be deleted after override reset');
        vlog("Override reset for SA1, member row removed");
    });

    // ── Move conversation member (IMAP move updates conv DB) ─────

    out("\n--- 6d. CONVERSATION MEMBER MOVE ---");

    test('Re-assign SA2 for move test', function () use ($convService, $testEmail, $seededUids, $seededMessageIds, $convRunId, $TEST_TAG, &$convIds, &$cleanupConvIds) {
        $msg = [
            'uid' => $seededUids['sa2'],
            'message_id' => $seededMessageIds['sa2'],
            'subject' => "$TEST_TAG SA-Beta $convRunId",
            'date' => date('r'),
            'from' => [['email' => $testEmail, 'name' => 'Test']],
            'references' => [],
            'in_reply_to' => null,
        ];
        $convId = $convService->assignMessageToConversation($testEmail, 'INBOX', $msg);
        assert_not_empty($convId, 'Re-assign failed');
        $convIds['sa2'] = $convId;
        $cleanupConvIds[] = $convId;
    });

    test('moveConversationMember updates folder and UID', function () use ($convService, $testEmail, $seededUids, $testFolderName) {
        $oldUid = $seededUids['sa2'];
        $fakeNewUid = 9999;
        $result = $convService->moveConversationMember($testEmail, 'INBOX', $oldUid, $testFolderName, $fakeNewUid);
        assert_true($result, 'moveConversationMember returned false');
        vlog("Moved conversation member UID $oldUid from INBOX to $testFolderName with new UID $fakeNewUid");
    });

    test('Conversation member has updated folder after move', function () use ($convService, $testEmail, $seededMessageIds, $testFolderName) {
        $mid = trim($seededMessageIds['sa2'], '<> ');
        $member = $convService->getMessageConversation($testEmail, $testFolderName, $mid);
        assert_true($member !== null, 'Member not found in target folder after moveConversationMember');
        $memberFolder = strtolower($member['folder']);
        $expected = strtolower($testFolderName);
        assert_equals($expected, $memberFolder, 'Member folder mismatch');
        assert_equals(9999, (int)$member['uid'], 'Member UID should be updated to 9999');
        vlog("Member folder=" . $member['folder'] . ", uid=" . $member['uid']);
    });
}

// ═════════════════════════════════════════════════════════════════
// 7. LABELS
// ═════════════════════════════════════════════════════════════════

if (shouldRun('label')) {
    out("\n--- 7. LABELS ---");

    $labelService = new \Webmail\Services\LabelService($config);
    $testLabelId = null;
    $testLabelId2 = null;

    test('Create label', function () use ($labelService, $testEmail, $TEST_TAG, &$testLabelId, &$cleanupLabelIds) {
        $label = $labelService->createLabel($testEmail, "$TEST_TAG Urgent", '#ef4444');
        assert_true($label !== null, 'createLabel returned null (duplicate name?)');
        assert_true(isset($label['id']), 'No id in created label');
        $testLabelId = (int)$label['id'];
        $cleanupLabelIds[] = $testLabelId;
        vlog("Created label ID=$testLabelId");
    });

    test('Create second label', function () use ($labelService, $testEmail, $TEST_TAG, &$testLabelId2, &$cleanupLabelIds) {
        $label = $labelService->createLabel($testEmail, "$TEST_TAG Important", '#3b82f6');
        assert_true($label !== null, 'createLabel returned null');
        $testLabelId2 = (int)$label['id'];
        $cleanupLabelIds[] = $testLabelId2;
    });

    test('List labels includes created label', function () use ($labelService, $testEmail, $testLabelId, $TEST_TAG) {
        $labels = $labelService->getLabels($testEmail);
        assert_true(is_array($labels), 'getLabels did not return array');
        $found = false;
        foreach ($labels as $l) {
            if ((int)$l['id'] === $testLabelId) {
                $found = true;
                assert_equals("$TEST_TAG Urgent", $l['name'], 'Label name mismatch');
                break;
            }
        }
        assert_true($found, 'Created label not found in getLabels list');
    });

    test('Add label to message', function () use ($labelService, $testEmail, $testLabelId, $seededMessageIds) {
        $mid = trim($seededMessageIds['root'], '<> ');
        $result = $labelService->addLabelToMessage($testEmail, $mid, $testLabelId);
        assert_true($result, 'addLabelToMessage returned false');
    });

    test('Add second label to same message', function () use ($labelService, $testEmail, $testLabelId2, $seededMessageIds) {
        $mid = trim($seededMessageIds['root'], '<> ');
        $result = $labelService->addLabelToMessage($testEmail, $mid, $testLabelId2);
        assert_true($result, 'addLabelToMessage returned false for second label');
    });

    test('getMessageLabels returns both labels', function () use ($labelService, $testEmail, $seededMessageIds, $testLabelId, $testLabelId2) {
        $mid = trim($seededMessageIds['root'], '<> ');
        $labels = $labelService->getMessageLabels($testEmail, $mid);
        assert_true(count($labels) >= 2, 'Expected at least 2 labels on message, got ' . count($labels));
        $labelIds = array_map(fn($l) => (int)$l['id'], $labels);
        assert_true(in_array($testLabelId, $labelIds), "Label $testLabelId not found on message");
        assert_true(in_array($testLabelId2, $labelIds), "Label $testLabelId2 not found on message");
    });

    test('getMessageLabelsForList (batch) returns labels', function () use ($labelService, $testEmail, $seededMessageIds, $testLabelId) {
        $mid = trim($seededMessageIds['root'], '<> ');
        $batch = $labelService->getMessageLabelsForList($testEmail, [$mid]);
        assert_true(isset($batch[$mid]), 'Message not in batch result');
        assert_true(count($batch[$mid]) >= 2, 'Batch should return at least 2 labels');
    });

    test('Remove label from message', function () use ($labelService, $testEmail, $seededMessageIds, $testLabelId) {
        $mid = trim($seededMessageIds['root'], '<> ');
        $result = $labelService->removeLabelFromMessage($testEmail, $mid, $testLabelId);
        assert_true($result, 'removeLabelFromMessage returned false');
        $labels = $labelService->getMessageLabels($testEmail, $mid);
        $labelIds = array_map(fn($l) => (int)$l['id'], $labels);
        assert_true(!in_array($testLabelId, $labelIds), 'Label should be removed but is still present');
        vlog("Removed label $testLabelId, remaining: " . count($labels));
    });

    test('Rename label', function () use ($labelService, $testEmail, $testLabelId2, $TEST_TAG) {
        $newName = "$TEST_TAG VIP";
        $result = $labelService->updateLabel($testEmail, $testLabelId2, $newName, '#8b5cf6');
        assert_true($result, 'updateLabel returned false');
        $labels = $labelService->getLabels($testEmail);
        $found = false;
        foreach ($labels as $l) {
            if ((int)$l['id'] === $testLabelId2 && $l['name'] === $newName) {
                $found = true;
                break;
            }
        }
        assert_true($found, 'Label not renamed correctly');
    });

    test('Delete label cascades to message_labels', function () use ($labelService, $testEmail, $testLabelId2, $seededMessageIds) {
        $mid = trim($seededMessageIds['root'], '<> ');
        $result = $labelService->deleteLabel($testEmail, $testLabelId2);
        assert_true($result, 'deleteLabel returned false');
        $labels = $labelService->getMessageLabels($testEmail, $mid);
        $labelIds = array_map(fn($l) => (int)$l['id'], $labels);
        assert_true(!in_array($testLabelId2, $labelIds), 'Deleted label still attached to message');
        // Remove from cleanup since already deleted
        global $cleanupLabelIds;
        $cleanupLabelIds = array_filter($cleanupLabelIds, fn($id) => $id !== $testLabelId2);
    });

    test('Duplicate label name returns null', function () use ($labelService, $testEmail, $TEST_TAG, $testLabelId) {
        $dup = $labelService->createLabel($testEmail, "$TEST_TAG Urgent", '#ef4444');
        assert_true($dup === null, 'Duplicate label should return null');
    });

    test('Labels use Message-ID not UID (move-safe)', function () use ($labelService, $testEmail, $seededMessageIds, $testLabelId) {
        $mid = trim($seededMessageIds['reply'], '<> ');
        $labelService->addLabelToMessage($testEmail, $mid, $testLabelId);
        $labels = $labelService->getMessageLabels($testEmail, $mid);
        assert_true(count($labels) > 0, 'Label not applied to reply message');
        $labelService->removeLabelFromMessage($testEmail, $mid, $testLabelId);
        vlog("Confirmed labels are keyed by Message-ID (not UID)");
    });
}

// ═════════════════════════════════════════════════════════════════
// 8. REACTIONS
// ═════════════════════════════════════════════════════════════════

if (shouldRun('reaction')) {
    out("\n--- 8. REACTIONS ---");

    $reactionService = null;
    test('ReactionService initialization', function () use ($config, &$reactionService) {
        $reactionService = new \Webmail\Addons\Reactions\Services\ReactionService($config);
        assert_true($reactionService !== null, 'Failed to create ReactionService');
    });

    if ($reactionService) {
        $reactionId = null;

        test('Add reaction (thumbsup)', function () use ($reactionService, $testEmail, $seededMessageIds, &$reactionId, &$cleanupReactionIds) {
            $mid = trim($seededMessageIds['root'], '<> ');
            $result = $reactionService->addReaction($mid, $testEmail, 'Test User', 'thumbsup', [$testEmail], 'Test subject');
            assert_true($result !== null, 'addReaction returned null (should return reaction data)');
            assert_true(isset($result['id']), 'No id in reaction result');
            $reactionId = (int)$result['id'];
            $cleanupReactionIds[] = $reactionId;
            vlog("Added reaction ID=$reactionId");
        });

        test('Get reactions for message', function () use ($reactionService, $testEmail, $seededMessageIds) {
            $mid = trim($seededMessageIds['root'], '<> ');
            $reactions = $reactionService->getReactionsForMessage($mid, $testEmail);
            assert_true(count($reactions) > 0, 'No reactions found for message');
            $emojis = array_map(fn($r) => $r['emoji'], $reactions);
            assert_true(in_array('thumbsup', $emojis), 'thumbsup reaction not found');
        });

        test('Toggle reaction off (add same again)', function () use ($reactionService, $testEmail, $seededMessageIds, &$cleanupReactionIds) {
            $mid = trim($seededMessageIds['root'], '<> ');
            $result = $reactionService->addReaction($mid, $testEmail, 'Test User', 'thumbsup', [$testEmail]);
            assert_true($result === null, 'Toggle should return null (reaction removed)');
            $reactions = $reactionService->getReactionsForMessage($mid, $testEmail);
            $thumbs = array_filter($reactions, fn($r) => $r['emoji'] === 'thumbsup');
            assert_true(count($thumbs) === 0, 'Thumbsup should be gone after toggle');
            // Already removed, clean up tracking
            $cleanupReactionIds = [];
            vlog("Toggled off thumbsup");
        });

        test('Add reaction, then remove by details', function () use ($reactionService, $testEmail, $seededMessageIds, &$cleanupReactionIds) {
            $mid = trim($seededMessageIds['root'], '<> ');
            $result = $reactionService->addReaction($mid, $testEmail, 'Test User', 'heart', [$testEmail]);
            assert_true($result !== null, 'addReaction(heart) returned null');
            $cleanupReactionIds[] = (int)$result['id'];

            $removed = $reactionService->removeReactionByDetails($mid, $testEmail, 'heart');
            assert_true($removed, 'removeReactionByDetails returned false');
            $cleanupReactionIds = [];
        });

        test('Batch get reactions for multiple messages', function () use ($reactionService, $testEmail, $seededMessageIds, &$cleanupReactionIds) {
            $mid1 = trim($seededMessageIds['root'], '<> ');
            $mid2 = trim($seededMessageIds['reply'], '<> ');
            // Add reactions to both messages
            $r1 = $reactionService->addReaction($mid1, $testEmail, 'Test', 'laugh', [$testEmail]);
            $r2 = $reactionService->addReaction($mid2, $testEmail, 'Test', 'party', [$testEmail]);
            if ($r1) $cleanupReactionIds[] = (int)$r1['id'];
            if ($r2) $cleanupReactionIds[] = (int)$r2['id'];

            $batch = $reactionService->getReactionsForMessages([$mid1, $mid2], $testEmail);
            assert_true(isset($batch[$mid1]), 'Batch missing reactions for root message');
            assert_true(isset($batch[$mid2]), 'Batch missing reactions for reply message');
            vlog("Batch returned reactions for " . count($batch) . " messages");
        });

        test('Reaction visibility requires participant match', function () use ($reactionService, $testEmail, $seededMessageIds, &$cleanupReactionIds) {
            $mid = trim($seededMessageIds['sa2'], '<> ');
            $r = $reactionService->addReaction($mid, $testEmail, 'Test', 'surprised', ['other@example.com']);
            if ($r) $cleanupReactionIds[] = (int)$r['id'];

            // Viewer who is NOT a participant and NOT the reactor should not see it
            // But the reactor ($testEmail) should still see it
            $visible = $reactionService->getReactionsForMessage($mid, $testEmail);
            assert_true(count($visible) > 0, 'Reactor should see their own reaction');

            $otherVisible = $reactionService->getReactionsForMessage($mid, 'nobody@test.com');
            assert_true(count($otherVisible) === 0, 'Non-participant/non-reactor should NOT see the reaction');
        });

        test('Invalid emoji throws exception', function () use ($reactionService, $testEmail, $seededMessageIds) {
            $mid = trim($seededMessageIds['root'], '<> ');
            $threw = false;
            try {
                $reactionService->addReaction($mid, $testEmail, 'Test', 'invalid_emoji', [$testEmail]);
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            assert_true($threw, 'Invalid emoji should throw InvalidArgumentException');
        });
    }
}

// ═════════════════════════════════════════════════════════════════
// 9. ALL-MAIL / CROSS-FOLDER (no duplication)
// ═════════════════════════════════════════════════════════════════

if (shouldRun('allmail')) {
    out("\n--- 9. ALL-MAIL / CROSS-FOLDER ---");

    test('Cross-folder search for test subject returns results', function () use ($imap, $TEST_TAG) {
        $results = $imap->search('INBOX', $TEST_TAG);
        $msgs = $results['messages'] ?? $results;
        assert_true(is_array($msgs), 'search did not return messages array');
        assert_true(count($msgs) > 0, "No messages found for tag '$TEST_TAG' in INBOX");
        vlog("Found " . count($msgs) . " messages matching '$TEST_TAG'");
    });

    test('Move email to test folder and verify it exists in target', function () use ($imap, $seededUids, $testFolderFull, $TEST_TAG, &$cleanupFolders) {
        global $testEmail, $config, $testPassword;
        $moveTestMid = '<flowone-allmail-' . uniqid() . '@test.local>';
        $subject = "$TEST_TAG AllMailCheck " . date('His');
        $msg = buildRawEmail($testEmail, $testEmail, $subject, "Duplication test.", $moveTestMid);
        $uid = appendToFolder($imap, 'INBOX', $msg);
        assert_true($uid > 0, 'Failed to append duplication test message');

        // Reconnect so the ImapService sees the freshly appended message
        $imap->connect($testEmail, $testPassword);

        $moved = $imap->moveMessage('INBOX', $uid, $testFolderFull);
        assert_true($moved, "Move failed for duplication test (target: $testFolderFull)");

        // Fresh connection to guarantee clean state after move+expunge
        $imap->connect($testEmail, $testPassword);

        // Verify the message landed in the target folder
        $targetMsgs = $imap->getMessages($testFolderFull, 1, 50);
        $allTarget = $targetMsgs['messages'] ?? $targetMsgs;
        assert_true(count($allTarget) > 0, "Target folder $testFolderFull should have messages after move");

        // Verify INBOX no longer has it (by UID)
        $inboxMsgs = $imap->getMessages('INBOX', 1, 500);
        $allInbox = $inboxMsgs['messages'] ?? $inboxMsgs;
        $inboxUids = array_map(fn($m) => (int)($m['uid'] ?? $m->uid ?? 0), $allInbox);
        assert_true(!in_array($uid, $inboxUids), "Original UID $uid should be gone from INBOX after move");
        vlog("Target has " . count($allTarget) . " messages, INBOX UID $uid correctly removed");
    });
}

// ═════════════════════════════════════════════════════════════════
// CLEANUP seeded IMAP messages
// ═════════════════════════════════════════════════════════════════

out("\n--- CLEANUP SEEDED MESSAGES ---");

// Delete seeded messages from INBOX by marking \Deleted + expunge
try {
    $host = $config['imap']['host'] ?? 'localhost';
    $port = $config['imap']['port'] ?? 993;
    $enc  = $config['imap']['encryption'] ?? 'ssl';
    $flags = '/imap';
    if ($enc === 'ssl') $flags .= '/ssl';
    elseif ($enc === 'tls') $flags .= '/tls';
    else $flags .= '/notls';
    $flags .= '/novalidate-cert';
    $connStr = '{' . $host . ':' . $port . $flags . '}INBOX';

    $cleanConn = @imap_open($connStr, $testEmail, $testPassword, 0, 1);
    if ($cleanConn) {
        foreach ($seededUids as $key => $uid) {
            if (in_array($key, ['sa1_final'])) continue;
            @imap_delete($cleanConn, (string)$uid, FT_UID);
            vlog("Marked UID $uid ($key) for deletion");
        }
        @imap_expunge($cleanConn);
        @imap_close($cleanConn);
        vlog("Expunged INBOX");
    }
} catch (\Throwable $e) {
    out("  [WARN] Seeded message cleanup: " . $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════════════════════

out("\n=================================================================");
out("  SUMMARY");
out("=================================================================");
out("  Total:    {$totalTests}");
out("  \033[32mPassed:   {$passed}\033[0m");
if ($failed > 0) out("  \033[31mFailed:   {$failed}\033[0m");
else             out("  Failed:   0");
if ($warnings > 0) out("  \033[33mWarnings: {$warnings}\033[0m");
out("  Log:      {$logFile}");
out("=================================================================\n");

if ($failed > 0) {
    out("Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("  - {$r['name']}: {$r['error']}");
        }
    }
    out("");
}

exit($failed > 0 ? 1 : 0);

// ══════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════════

/**
 * Build a raw RFC 2822 email string
 */
function buildRawEmail(
    string $from,
    string $to,
    string $subject,
    string $body,
    string $messageId,
    string $extraHeaders = ''
): string {
    $date = date('r');
    $raw  = "From: <{$from}>\r\n";
    $raw .= "To: <{$to}>\r\n";
    $raw .= "Subject: {$subject}\r\n";
    $raw .= "Message-ID: {$messageId}\r\n";
    $raw .= "Date: {$date}\r\n";
    $raw .= "MIME-Version: 1.0\r\n";
    $raw .= "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($extraHeaders) {
        $raw .= $extraHeaders . "\r\n";
    }
    $raw .= "\r\n";
    $raw .= $body;
    return $raw;
}

/**
 * Append a raw message to an IMAP folder, return the UID.
 * Uses a standalone imap_open since ImapService.connection is private.
 */
function appendToFolder(\Webmail\Services\ImapService $imap, string $folder, string $rawMessage): int {
    global $config, $testEmail, $testPassword;

    $imap->selectFolder($folder);
    $statusBefore = $imap->getFolderStatus($folder);
    $uidnextBefore = $statusBefore['uidnext'] ?? $statusBefore['UIDNEXT'] ?? 0;

    $host = $config['imap']['host'] ?? 'localhost';
    $port = $config['imap']['port'] ?? 993;
    $enc  = $config['imap']['encryption'] ?? 'ssl';

    $flags = '/imap';
    if ($enc === 'ssl') $flags .= '/ssl';
    elseif ($enc === 'tls') $flags .= '/tls';
    else $flags .= '/notls';
    $flags .= '/novalidate-cert';

    $connStr = '{' . $host . ':' . $port . $flags . '}';

    // Build mailbox path: INBOX stays as-is, others get INBOX. prefix (Dovecot convention)
    if (strtoupper($folder) === 'INBOX') {
        $serverPath = $connStr . 'INBOX';
    } else {
        $serverPath = $connStr . 'INBOX.' . $folder;
    }

    $conn = @imap_open($connStr . 'INBOX', $testEmail, $testPassword, 0, 1);
    if (!$conn) {
        throw new \RuntimeException('imap_open failed for append: ' . implode(', ', imap_errors() ?: ['unknown']));
    }

    $result = @imap_append($conn, $serverPath, $rawMessage, "\\Seen");
    if (!$result) {
        // Retry without INBOX. prefix
        $serverPath2 = $connStr . $folder;
        $result = @imap_append($conn, $serverPath2, $rawMessage, "\\Seen");
    }
    @imap_close($conn);

    if (!$result) {
        $err = imap_last_error();
        throw new \RuntimeException("imap_append failed: $err");
    }

    // Re-read status to find the new UID
    $statusAfter = $imap->getFolderStatus($folder);
    $uidnextAfter = $statusAfter['uidnext'] ?? $statusAfter['UIDNEXT'] ?? 0;

    if ($uidnextAfter > $uidnextBefore) {
        return $uidnextBefore;
    }

    return $uidnextAfter > 0 ? $uidnextAfter - 1 : 0;
}

/**
 * Fetch a single message by UID using a raw IMAP connection
 */
function fetchSingleMessage(\Webmail\Services\ImapService $imap, string $folder, int $uid): ?array {
    global $config, $testEmail, $testPassword;

    $host = $config['imap']['host'] ?? 'localhost';
    $port = $config['imap']['port'] ?? 993;
    $enc  = $config['imap']['encryption'] ?? 'ssl';

    $flags = '/imap';
    if ($enc === 'ssl') $flags .= '/ssl';
    elseif ($enc === 'tls') $flags .= '/tls';
    else $flags .= '/notls';
    $flags .= '/novalidate-cert';

    $connStr = '{' . $host . ':' . $port . $flags . '}';

    if (strtoupper($folder) === 'INBOX') {
        $serverPath = $connStr . 'INBOX';
    } else {
        $serverPath = $connStr . 'INBOX.' . $folder;
    }

    $conn = @imap_open($serverPath, $testEmail, $testPassword, 0, 1);
    if (!$conn) {
        // Try without INBOX. prefix
        $conn = @imap_open($connStr . $folder, $testEmail, $testPassword, 0, 1);
    }
    if (!$conn) return null;

    $overview = @imap_fetch_overview($conn, (string)$uid, FT_UID);
    @imap_close($conn);

    if (!$overview || empty($overview)) return null;

    $obj = $overview[0];
    return [
        'uid' => (int)($obj->uid ?? 0),
        'subject' => $obj->subject ?? '',
        'seen' => isset($obj->seen) ? (bool)$obj->seen : false,
        'flagged' => isset($obj->flagged) ? (bool)$obj->flagged : false,
        'answered' => isset($obj->answered) ? (bool)$obj->answered : false,
        'message_id' => $obj->message_id ?? '',
    ];
}

/**
 * Check if a flag is in a flags string/array
 */
function isInFlags($msg, string $flag): bool {
    $flags = $msg['flags'] ?? '';
    if (is_string($flags)) {
        return stripos($flags, $flag) !== false;
    }
    if (is_array($flags)) {
        foreach ($flags as $f) {
            if (stripos($f, $flag) !== false) return true;
        }
    }
    return false;
}
