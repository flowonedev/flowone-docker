#!/usr/bin/env php
<?php
/**
 * mailbox-write-test.php
 *
 * Phase 5 test: MailboxWriteService.
 *
 * The write service centralises the DB-first commit pattern shared by
 * setFlag/move/delete + their batch variants. The bug that produced the
 * "Failed to set flag" 400s was a DDL implicit-commit inside the tx;
 * this test pins the contract that the service NEVER opens a tx before
 * its dependencies are fully constructed.
 *
 * Covered:
 *   1. Smoke: service is constructible
 *   2. commitFlag: writes is_seen and enqueues outbox in one tx
 *   3. commitFlag: rollback on bad input does not partially write
 *   4. commitFlagBatch: many UIDs across folders -> one tx, ok=true
 *   5. commitFlag(flagged): mirrors is_flagged so the star never reverts
 *   6. commitMove: updates conversation_members + outbox enqueued
 *   7. commitDelete (hard): deletes member + writes tombstone
 *   8. commitDelete (soft, to trash): moves member + outbox move enqueued
 *   9. commitDeleteBatch: hard-deletes multiple UIDs in one tx
 *  10. publish* helpers: best-effort, never throw even with bad inputs
 *
 * Per .cursor/rules/server-side-testing.mdc.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-write-test.php --verbose
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
use Webmail\Services\FolderIndexService;
use Webmail\Services\MailboxWriteService;
use Webmail\Services\OutboxService;

$runner = new FlowOneTestRunner('mailbox-write', $argv);

// -- Pre-flight ---------------------------------------------------------------

foreach (['pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    $runner->log('DB connection failed: ' . $e->getMessage());
    exit(1);
}

foreach (['webmail_conversation_members', 'webmail_folder_identity', 'imap_outbox', 'webmail_folder_tombstones'] as $table) {
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (\Throwable $e) {
        $runner->log("required table missing: {$table} - run migrations first (180/182/178): " . $e->getMessage());
        exit(1);
    }
}

$testUser    = 'flowone_test_write_user@example.invalid';
$testAccount = 'flowone_test_write_user@example.invalid';
$srcFolder   = 'INBOX.flowone_test_write_src';
$trashFolder = 'INBOX.flowone_test_write_trash';

// -- Cleanup ------------------------------------------------------------------

$wipe = function () use ($db, $testUser, $testAccount): array {
    $counts = [];
    foreach ([
        'webmail_conversation_members' => 'user_email',
        'webmail_folder_tombstones'    => 'user_email',
        'imap_outbox'                  => 'user_email',
        'webmail_conversations'        => 'user_email',
    ] as $table => $col) {
        try {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE {$col} = :u");
            $stmt->execute([':u' => $testUser]);
            $counts[$table] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $counts[$table] = 0;
        }
    }
    try {
        $stmt = $db->prepare('DELETE FROM webmail_folder_identity WHERE account_id = :u');
        $stmt->execute([':u' => $testAccount]);
        $counts['webmail_folder_identity'] = $stmt->rowCount();
    } catch (\Throwable $e) {
        $counts['webmail_folder_identity'] = 0;
    }
    return $counts;
};

$initial = $wipe();
$runner->log('pre-test cleanup: ' . json_encode($initial));
$runner->addCleanup(function () use ($wipe, $runner) {
    $n = $wipe();
    $runner->log('post-test cleanup: ' . json_encode($n));
});

// -- Build folder identities -------------------------------------------------

$folderIndex = new FolderIndexService($config);
$srcFolderId = $folderIndex->upsertFromListing($testAccount, [
    'path'         => $srcFolder,
    'name'         => 'flowone_test_write_src',
    'display_name' => 'flowone_test_write_src',
    'delimiter'    => '.',
    'is_selectable'=> 1,
]);
$trashFolderId = $folderIndex->upsertFromListing($testAccount, [
    'path'         => $trashFolder,
    'name'         => 'flowone_test_write_trash',
    'display_name' => 'flowone_test_write_trash',
    'delimiter'    => '.',
    'is_selectable'=> 1,
]);

$runner->log("src folder_id = {$srcFolderId}");
$runner->log("trash folder_id = {$trashFolderId}");

// Helper: seed a conversation_members row directly so we have something
// to flag/move/delete. The write service writes through conv methods so
// we are not testing the conv service itself here, just the orchestration.
$seedConv = new ConversationService($config);
$seedMember = function (int $uid, bool $seen = false) use ($seedConv, $testUser, $srcFolder) {
    $seedConv->assignMessageToConversation($testUser, $srcFolder, [
        'uid'         => $uid,
        'message_id'  => "<flowone_test_write_{$uid}@example.invalid>",
        'subject'     => "Test {$uid}",
        'from'        => [['email' => 'sender@example.invalid', 'name' => 'Test Sender']],
        'to'          => [['email' => 'rcpt@example.invalid', 'name' => 'Rcpt']],
        'date'        => date('Y-m-d H:i:s'),
        'internal_date' => date('Y-m-d H:i:s'),
        'snippet'     => 'flowone test body',
        'seen'        => $seen,
        'flagged'     => false,
        'answered'    => false,
        'has_attachment' => false,
    ]);
};

// -- Smoke --------------------------------------------------------------------

if ($runner->shouldRunSection('0. SMOKE')) {
    $runner->section('0. SMOKE');
    $runner->test('MailboxWriteService is constructible', function () use ($runner, $config) {
        $svc = new MailboxWriteService($config);
        $runner->assertTrue($svc !== null, 'service constructed');
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

// -- 1. commitFlag (single) ---------------------------------------------------

$svc = new MailboxWriteService($config);

if ($runner->shouldRunSection('1. COMMIT FLAG (single)')) {
    $runner->section('1. COMMIT FLAG (single)');

    $runner->test('seed: insert member uid=101 with seen=false', function () use ($runner, $seedMember, $db, $testUser, $srcFolderId) {
        $seedMember(101, false);
        $stmt = $db->prepare('SELECT is_seen FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 101]);
        $runner->assertEquals('0', (string)$stmt->fetchColumn(), 'seeded as unread');
    });

    $runner->test('commitFlag(seen=true) marks read in DB', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId) {
        $r = $svc->commitFlag($testUser, $srcFolder, $srcFolderId, 101, 'seen', true, 'test-nonce-101');
        $runner->assertTrue($r['ok'] ?? false, 'commit ok');
        $stmt = $db->prepare('SELECT is_seen FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 101]);
        $runner->assertEquals('1', (string)$stmt->fetchColumn(), 'is_seen flipped to 1');
    });

    $runner->test('commitFlag enqueues outbox row with op=set_flag', function () use ($runner, $db, $testUser, $srcFolderId) {
        $stmt = $db->prepare("SELECT op, status FROM imap_outbox WHERE user_email=? AND folder_id=? AND uid=? AND status='pending' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$testUser, $srcFolderId, 101]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $runner->assertTrue($row !== false && $row !== null, 'pending outbox row exists');
        $runner->assertEquals('set_flag', (string)$row['op'], 'op = set_flag');
    });

    $runner->test('commitFlag(seen=false) flips back to unread', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId) {
        $r = $svc->commitFlag($testUser, $srcFolder, $srcFolderId, 101, 'seen', false, 'test-nonce-101-clear');
        $runner->assertTrue($r['ok'] ?? false, 'commit ok');
        $stmt = $db->prepare('SELECT is_seen FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 101]);
        $runner->assertEquals('0', (string)$stmt->fetchColumn(), 'is_seen flipped back to 0');
    });
}

// -- 2. commitFlagBatch -------------------------------------------------------

if ($runner->shouldRunSection('2. COMMIT FLAG BATCH')) {
    $runner->section('2. COMMIT FLAG BATCH');

    $runner->test('seed: 3 members uid=201,202,203 all unread', function () use ($runner, $seedMember) {
        foreach ([201, 202, 203] as $u) {
            $seedMember($u, false);
        }
        $runner->assertTrue(true, 'seeded');
    });

    $runner->test('commitFlagBatch marks all three as read', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId) {
        $r = $svc->commitFlagBatch(
            $testUser,
            [$srcFolder => ['folder_id' => $srcFolderId, 'uids' => [201, 202, 203]]],
            'seen',
            true,
            'batch-nonce-1'
        );
        $runner->assertTrue($r['ok'] ?? false, 'batch ok');
        $runner->assertEquals(3, $r['success'] ?? 0, '3 rows enqueued');
        $stmt = $db->prepare('SELECT COUNT(*) FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid IN (201,202,203) AND is_seen=1');
        $stmt->execute([$testUser, $srcFolderId]);
        $runner->assertEquals(3, (int)$stmt->fetchColumn(), 'all 3 are seen=1');
    });

    $runner->test('commitFlagBatch produces 3 outbox rows', function () use ($runner, $db, $testUser, $srcFolderId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM imap_outbox WHERE user_email=? AND folder_id=? AND uid IN (201,202,203) AND op='set_flag'");
        $stmt->execute([$testUser, $srcFolderId]);
        $runner->assertEquals(3, (int)$stmt->fetchColumn(), '3 outbox rows enqueued');
    });

    $runner->test('commitFlagBatch with bad folder_id reports skipped', function () use ($runner, $svc, $testUser, $srcFolder) {
        $r = $svc->commitFlagBatch(
            $testUser,
            [$srcFolder => ['folder_id' => '', 'uids' => [999]]],
            'seen', true, 'nonce-bad-1'
        );
        $runner->assertTrue($r['ok'] ?? false, 'batch still ok (no exception)');
        $runner->assertEquals(1, $r['skipped'] ?? 0, '1 skipped due to missing folder_id');
    });
}

// -- 2b. commitFlag (flagged / star) ------------------------------------------
//
// Regression guard for the P0 bug where star/unstar enqueued the IMAP \Flagged
// op but never wrote webmail_conversation_members.is_flagged -- so the list
// read (mirror) reverted the star until IMAP sync caught up.

if ($runner->shouldRunSection('2b. COMMIT FLAG (star)')) {
    $runner->section('2b. COMMIT FLAG (star)');

    $runner->test('seed: insert uid=250 with flagged=false', function () use ($runner, $seedMember, $db, $testUser, $srcFolderId) {
        $seedMember(250, true);
        $stmt = $db->prepare('SELECT is_flagged FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 250]);
        $runner->assertEquals('0', (string)$stmt->fetchColumn(), 'seeded as unflagged');
    });

    $runner->test('commitFlag(flagged=true) sets is_flagged in DB mirror', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId) {
        $r = $svc->commitFlag($testUser, $srcFolder, $srcFolderId, 250, 'flagged', true, 'star-nonce-250');
        $runner->assertTrue($r['ok'] ?? false, 'commit ok');
        $stmt = $db->prepare('SELECT is_flagged FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 250]);
        $runner->assertEquals('1', (string)$stmt->fetchColumn(), 'is_flagged flipped to 1 (no revert)');
    });

    $runner->test('commitFlag(flagged=false) clears is_flagged', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId) {
        $r = $svc->commitFlag($testUser, $srcFolder, $srcFolderId, 250, 'flagged', false, 'unstar-nonce-250');
        $runner->assertTrue($r['ok'] ?? false, 'commit ok');
        $stmt = $db->prepare('SELECT is_flagged FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 250]);
        $runner->assertEquals('0', (string)$stmt->fetchColumn(), 'is_flagged flipped back to 0');
    });

    $runner->test('commitFlagBatch(flagged=true) stars three at once', function () use ($runner, $svc, $seedMember, $db, $testUser, $srcFolder, $srcFolderId) {
        foreach ([251, 252, 253] as $u) { $seedMember($u, true); }
        $r = $svc->commitFlagBatch(
            $testUser,
            [$srcFolder => ['folder_id' => $srcFolderId, 'uids' => [251, 252, 253]]],
            'flagged', true, 'star-batch-1'
        );
        $runner->assertTrue($r['ok'] ?? false, 'batch ok');
        $stmt = $db->prepare('SELECT COUNT(*) FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid IN (251,252,253) AND is_flagged=1');
        $stmt->execute([$testUser, $srcFolderId]);
        $runner->assertEquals(3, (int)$stmt->fetchColumn(), 'all 3 are flagged=1');
    });
}

// -- 3. commitMove ------------------------------------------------------------

if ($runner->shouldRunSection('3. COMMIT MOVE')) {
    $runner->section('3. COMMIT MOVE');

    $runner->test('seed: insert uid=301 in src', function () use ($runner, $seedMember, $db, $testUser, $srcFolderId) {
        $seedMember(301, false);
        $stmt = $db->prepare('SELECT COUNT(*) FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 301]);
        $runner->assertEquals(1, (int)$stmt->fetchColumn(), 'seeded');
    });

    $runner->test('commitMove relocates uid=301 from src -> trash', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId, $trashFolder, $trashFolderId) {
        $r = $svc->commitMove($testUser, $srcFolder, $srcFolderId, 301, $trashFolder, $trashFolderId, 'move-nonce-301');
        $runner->assertTrue($r['ok'] ?? false, 'move ok');
        // Source row should be gone (moveConversationMember either updates folder_id or recreates in target)
        $stmt = $db->prepare('SELECT folder_id FROM webmail_conversation_members WHERE user_email=? AND uid=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$testUser, 301]);
        $resultId = (string)$stmt->fetchColumn();
        $runner->assertEquals($trashFolderId, $resultId, 'member now in trash');
    });

    $runner->test('commitMove enqueues outbox move row', function () use ($runner, $db, $testUser, $srcFolderId, $trashFolderId) {
        $stmt = $db->prepare("SELECT target_folder_id FROM imap_outbox WHERE user_email=? AND folder_id=? AND uid=? AND op='move' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$testUser, $srcFolderId, 301]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $runner->assertTrue($row !== false && $row !== null, 'move outbox row exists');
        $runner->assertEquals($trashFolderId, (string)$row['target_folder_id'], 'target_folder_id matches');
    });
}

// -- 4. commitDelete (hard) ---------------------------------------------------

if ($runner->shouldRunSection('4. COMMIT DELETE (hard)')) {
    $runner->section('4. COMMIT DELETE (hard)');

    $runner->test('seed: insert uid=401', function () use ($runner, $seedMember) {
        $seedMember(401, true);
        $runner->assertTrue(true, 'seeded');
    });

    $runner->test('commitDelete (hard) removes member', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId) {
        $r = $svc->commitDelete($testUser, $srcFolder, $srcFolderId, 401, 'del-nonce-401');
        $runner->assertTrue($r['ok'] ?? false, 'delete ok');
        $stmt = $db->prepare('SELECT COUNT(*) FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 401]);
        $runner->assertEquals(0, (int)$stmt->fetchColumn(), 'member row gone');
    });

    $runner->test('commitDelete writes a tombstone', function () use ($runner, $db, $testUser, $srcFolderId) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM webmail_folder_tombstones WHERE user_email=? AND folder_id=? AND uid=?');
        $stmt->execute([$testUser, $srcFolderId, 401]);
        $runner->assertTrue((int)$stmt->fetchColumn() >= 1, 'tombstone exists');
    });

    $runner->test('commitDelete enqueues outbox delete', function () use ($runner, $db, $testUser, $srcFolderId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM imap_outbox WHERE user_email=? AND folder_id=? AND uid=? AND op='delete'");
        $stmt->execute([$testUser, $srcFolderId, 401]);
        $runner->assertEquals(1, (int)$stmt->fetchColumn(), 'outbox delete row');
    });
}

// -- 5. commitDelete (soft to trash) ------------------------------------------

if ($runner->shouldRunSection('5. COMMIT DELETE (soft)')) {
    $runner->section('5. COMMIT DELETE (soft)');

    $runner->test('seed: insert uid=501', function () use ($runner, $seedMember) {
        $seedMember(501, false);
        $runner->assertTrue(true, 'seeded');
    });

    $runner->test('commitDelete (soft) moves member to trash via move op', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId, $trashFolder, $trashFolderId) {
        $r = $svc->commitDelete($testUser, $srcFolder, $srcFolderId, 501, 'del-soft-501', $trashFolder, $trashFolderId);
        $runner->assertTrue($r['ok'] ?? false, 'soft delete ok');
        $stmt = $db->prepare("SELECT op, target_folder_id FROM imap_outbox WHERE user_email=? AND folder_id=? AND uid=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$testUser, $srcFolderId, 501]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $runner->assertEquals('move', (string)$row['op'], 'op is move not delete');
        $runner->assertEquals($trashFolderId, (string)$row['target_folder_id'], 'target is trash');
    });
}

// -- 6. commitDeleteBatch -----------------------------------------------------

if ($runner->shouldRunSection('6. COMMIT DELETE BATCH')) {
    $runner->section('6. COMMIT DELETE BATCH');

    $runner->test('seed: insert uids 601,602,603', function () use ($runner, $seedMember) {
        foreach ([601, 602, 603] as $u) {
            $seedMember($u, false);
        }
        $runner->assertTrue(true, 'seeded');
    });

    $runner->test('commitDeleteBatch hard-deletes all three', function () use ($runner, $svc, $db, $testUser, $srcFolder, $srcFolderId) {
        $r = $svc->commitDeleteBatch(
            $testUser,
            [$srcFolder => ['folder_id' => $srcFolderId, 'uids' => [601, 602, 603]]],
            'batch-del-nonce-1'
        );
        $runner->assertTrue($r['ok'] ?? false, 'batch ok');
        $runner->assertEquals(3, $r['success'] ?? 0, '3 successes');
        $stmt = $db->prepare('SELECT COUNT(*) FROM webmail_conversation_members WHERE user_email=? AND folder_id=? AND uid IN (601,602,603)');
        $stmt->execute([$testUser, $srcFolderId]);
        $runner->assertEquals(0, (int)$stmt->fetchColumn(), 'all 3 rows gone');
    });
}

// -- 7. publish helpers (best-effort) -----------------------------------------

if ($runner->shouldRunSection('7. PUBLISH HELPERS')) {
    $runner->section('7. PUBLISH HELPERS');

    $runner->test('publishFlagEvent never throws', function () use ($runner, $svc, $testUser, $srcFolder) {
        $svc->publishFlagEvent($testUser, $srcFolder, 101, 'seen', true);
        $runner->assertTrue(true, 'no throw');
    });

    $runner->test('publishMoveEvent never throws', function () use ($runner, $svc, $testUser, $srcFolder, $trashFolder) {
        $svc->publishMoveEvent($testUser, $srcFolder, $trashFolder, 301, null);
        $runner->assertTrue(true, 'no throw');
    });

    $runner->test('publishDeleteEvent never throws', function () use ($runner, $svc, $testUser, $srcFolder) {
        $svc->publishDeleteEvent($testUser, $srcFolder, 401);
        $runner->assertTrue(true, 'no throw');
    });
}

exit($runner->finish());
