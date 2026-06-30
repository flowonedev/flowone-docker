#!/usr/bin/env php
<?php
/**
 * db-first-write-test.php
 *
 * Phase 2 test: the DB-as-truth contract for the write controllers.
 *
 * We verify (without touching IMAP) that:
 *   1. ConversationService::updateMemberReadStatus mutates
 *      webmail_conversation_members.is_seen AND recomputes
 *      webmail_conversations.unread_count from authoritative SUM.
 *   2. The recompute is RESILIENT to repeated calls (no drift).
 *   3. Toggling read state back-and-forth ends at the correct count.
 *   4. updateMembersReadStatusBatch produces the same end-state as N
 *      individual updateMemberReadStatus calls.
 *
 * This is the central guarantee that lets the controllers return 200
 * before IMAP confirms: the DB row, including the cached unread_count,
 * is already correct the moment the HTTP response is written.
 *
 * Per .cursor/rules/server-side-testing.mdc -- CLI only, idempotent,
 * test rows prefixed `flowone_test_`, cleanup on every exit path.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/db-first-write-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Core\Database;
use Webmail\Services\ConversationService;

$runner = new FlowOneTestRunner('db-first-write', $argv);

foreach (['pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';
$db = Database::getConnection($config);

// Pre-flight: required tables
foreach (['webmail_conversations', 'webmail_conversation_members', 'webmail_folder_identity'] as $tbl) {
    try {
        $db->query("SELECT 1 FROM {$tbl} LIMIT 1");
    } catch (\Throwable $e) {
        $runner->log("missing table {$tbl}; aborting");
        exit(1);
    }
}

$testUser = 'flowone_test_dbwrite@example.invalid';
$testFolderName = 'flowone_test_inbox';
// Stable test folder identity (UUID-shaped char(36))
$testFolderId = '00000000-0000-0000-0000-deadbeefcafe';
$testConvId = 'flowone_test_conv_' . substr(bin2hex(random_bytes(6)), 0, 12);

// Setup fixture: a conversation with 3 members for our test user.
// All idempotent so a prior failed run leaves no garbage.
$setupFixture = function () use ($db, $testUser, $testFolderId, $testFolderName, $testConvId) {
    // Folder identity row -- the resolver looks this up to find folder_id.
    $db->prepare(
        "INSERT INTO webmail_folder_identity
            (id, user_email, account_email, identity_key, canonical_path, special_use, created_at, updated_at)
         VALUES (:id, :u, :u, :ik, :cp, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE canonical_path = VALUES(canonical_path)"
    )->execute([
        ':id' => $testFolderId,
        ':u' => $testUser,
        ':ik' => 'flowone_test_ik_' . substr(bin2hex(random_bytes(4)), 0, 8),
        ':cp' => $testFolderName,
    ]);

    // Conversation row
    $db->prepare(
        "INSERT INTO webmail_conversations
            (user_email, conversation_id, subject_normalized, unread_count,
             message_count, total_count, updated_at)
         VALUES (:u, :c, 'flowone_test_subject', 3, 3, 3, NOW())
         ON DUPLICATE KEY UPDATE unread_count = 3, message_count = 3"
    )->execute([':u' => $testUser, ':c' => $testConvId]);

    // 3 unread members in the conversation
    $del = $db->prepare(
        "DELETE FROM webmail_conversation_members
          WHERE user_email = :u AND conversation_id = :c"
    );
    $del->execute([':u' => $testUser, ':c' => $testConvId]);

    $ins = $db->prepare(
        "INSERT INTO webmail_conversation_members
            (user_email, conversation_id, folder_id, uid, is_seen,
             message_id, received_at, indexed_at)
         VALUES (:u, :c, :f, :uid, 0, :mid, NOW(), NOW())"
    );
    foreach ([8001, 8002, 8003] as $uid) {
        $ins->execute([
            ':u' => $testUser,
            ':c' => $testConvId,
            ':f' => $testFolderId,
            ':uid' => $uid,
            ':mid' => "flowone-test-msg-{$uid}",
        ]);
    }
};

$wipe = function () use ($db, $testUser, $testFolderId, $testConvId) {
    $db->prepare(
        "DELETE FROM webmail_conversation_members
          WHERE user_email = :u AND conversation_id = :c"
    )->execute([':u' => $testUser, ':c' => $testConvId]);
    $db->prepare(
        "DELETE FROM webmail_conversations
          WHERE user_email = :u AND conversation_id = :c"
    )->execute([':u' => $testUser, ':c' => $testConvId]);
    $db->prepare(
        "DELETE FROM webmail_folder_identity
          WHERE id = :id"
    )->execute([':id' => $testFolderId]);
};

$wipe();
$runner->addCleanup(function () use ($wipe, $runner) {
    $wipe();
    $runner->log('post-test cleanup: fixture removed');
});

$convService = new ConversationService($config);

// Inject our shared PDO so writes are visible to the test queries below.
$ref = new \ReflectionClass($convService);
if ($ref->hasProperty('db')) {
    $prop = $ref->getProperty('db');
    $prop->setAccessible(true);
    $prop->setValue($convService, $db);
}

$readUnreadCount = function () use ($db, $testUser, $testConvId): int {
    $stmt = $db->prepare(
        "SELECT unread_count FROM webmail_conversations
          WHERE user_email = :u AND conversation_id = :c"
    );
    $stmt->execute([':u' => $testUser, ':c' => $testConvId]);
    return (int)$stmt->fetchColumn();
};

$readMemberSeen = function (int $uid) use ($db, $testUser, $testConvId): int {
    $stmt = $db->prepare(
        "SELECT is_seen FROM webmail_conversation_members
          WHERE user_email = :u AND conversation_id = :c AND uid = :uid"
    );
    $stmt->execute([':u' => $testUser, ':c' => $testConvId, ':uid' => $uid]);
    return (int)$stmt->fetchColumn();
};

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('db reachable', fn() => $db->query('SELECT 1')->fetchColumn() === '1' || $db->query('SELECT 1')->fetchColumn() === 1);
    exit($runner->finish());
}

// -- 1. SINGLE FLAG WRITE -----------------------------------------------------

$runner->section('1. SINGLE FLAG WRITE -> RECOMPUTE');

if ($runner->shouldRunSection('1. SINGLE FLAG WRITE -> RECOMPUTE')) {
    $runner->test('mark one of 3 members read -> unread_count drops to 2', function () use ($convService, $setupFixture, $testUser, $testFolderName, $readUnreadCount, $readMemberSeen) {
        $setupFixture();
        if ($readUnreadCount() !== 3) throw new \RuntimeException('fixture sanity: unread_count must start at 3');
        $ok = $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, true);
        if (!$ok) throw new \RuntimeException('updateMemberReadStatus returned false');
        if ($readMemberSeen(8001) !== 1) throw new \RuntimeException('member is_seen not set');
        $count = $readUnreadCount();
        if ($count !== 2) throw new \RuntimeException("expected unread_count=2, got {$count}");
        return true;
    });

    $runner->test('mark all 3 read -> unread_count = 0', function () use ($convService, $setupFixture, $testUser, $testFolderName, $readUnreadCount) {
        $setupFixture();
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, true);
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8002, true);
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8003, true);
        $count = $readUnreadCount();
        if ($count !== 0) throw new \RuntimeException("expected unread_count=0, got {$count}");
        return true;
    });

    $runner->test('mark same member read twice -> count does NOT drift (recompute)', function () use ($convService, $setupFixture, $testUser, $testFolderName, $readUnreadCount) {
        $setupFixture();
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, true);
        $afterFirst = $readUnreadCount();
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, true);
        $afterSecond = $readUnreadCount();
        if ($afterFirst !== 2 || $afterSecond !== 2) {
            throw new \RuntimeException("recompute drifted: first={$afterFirst} second={$afterSecond}");
        }
        return true;
    });

    $runner->test('toggle read->unread->read ends in correct state', function () use ($convService, $setupFixture, $testUser, $testFolderName, $readUnreadCount, $readMemberSeen) {
        $setupFixture();
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, true);
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, false);
        if ($readMemberSeen(8001) !== 0) throw new \RuntimeException('member should be unread');
        if ($readUnreadCount() !== 3) throw new \RuntimeException('count should be 3 after toggle-back');

        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, true);
        if ($readMemberSeen(8001) !== 1) throw new \RuntimeException('member should be read');
        if ($readUnreadCount() !== 2) throw new \RuntimeException('count should be 2 after final mark-read');
        return true;
    });
}

// -- 2. BATCH WRITE -----------------------------------------------------------

$runner->section('2. BATCH WRITE -> RECOMPUTE');

if ($runner->shouldRunSection('2. BATCH WRITE -> RECOMPUTE')) {
    $runner->test('batch mark all read -> unread_count = 0', function () use ($convService, $setupFixture, $testUser, $testFolderName, $readUnreadCount) {
        $setupFixture();
        $ok = $convService->updateMembersReadStatusBatch($testUser, $testFolderName, [8001, 8002, 8003], true);
        if (!$ok) throw new \RuntimeException('batch update returned false');
        $count = $readUnreadCount();
        if ($count !== 0) throw new \RuntimeException("expected unread_count=0, got {$count}");
        return true;
    });

    $runner->test('batch is equivalent to N single updates', function () use ($convService, $setupFixture, $db, $testUser, $testFolderName, $testConvId, $readUnreadCount) {
        $setupFixture();
        $convService->updateMembersReadStatusBatch($testUser, $testFolderName, [8001, 8002], true);
        $batchEnd = $readUnreadCount();

        // Reset fixture, do the same via singles, compare.
        $setupFixture();
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, true);
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8002, true);
        $singleEnd = $readUnreadCount();

        if ($batchEnd !== $singleEnd) {
            throw new \RuntimeException("batch ({$batchEnd}) and singles ({$singleEnd}) diverge");
        }
        return true;
    });

    $runner->test('batch with already-read UIDs is a no-op (no drift)', function () use ($convService, $setupFixture, $testUser, $testFolderName, $readUnreadCount) {
        $setupFixture();
        $convService->updateMembersReadStatusBatch($testUser, $testFolderName, [8001], true);
        $afterFirst = $readUnreadCount();
        // Repeat same batch -- nothing actually changes, count must stay.
        $convService->updateMembersReadStatusBatch($testUser, $testFolderName, [8001], true);
        $afterSecond = $readUnreadCount();
        if ($afterFirst !== $afterSecond) {
            throw new \RuntimeException("batch idempotency broken: {$afterFirst} -> {$afterSecond}");
        }
        return true;
    });
}

// -- 3. PERFORMANCE -----------------------------------------------------------

$runner->section('3. PERFORMANCE: DB write returns fast');

if ($runner->shouldRunSection('3. PERFORMANCE: DB write returns fast')) {
    $runner->test('updateMemberReadStatus completes in <100ms (warm DB)', function () use ($convService, $setupFixture, $testUser, $testFolderName) {
        $setupFixture();
        // Warm the connection.
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, true);
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8001, false);

        $start = microtime(true);
        $convService->updateMemberReadStatus($testUser, $testFolderName, 8002, true);
        $elapsedMs = (microtime(true) - $start) * 1000;
        if ($elapsedMs > 100) {
            throw new \RuntimeException("DB write took {$elapsedMs}ms (>100ms budget). The DB-first contract requires the request path to be IMAP-latency-free.");
        }
        return true;
    });
}

exit($runner->finish());
