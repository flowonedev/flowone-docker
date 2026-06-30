#!/usr/bin/env php
<?php
/**
 * read-state-overlay-test.php
 *
 * Covers the read-side DB-as-truth overlay that reconciles the message
 * list's IMAP `\Seen` flag with our DB `is_seen` so an in-app read that
 * has not yet drained to IMAP does not re-appear as unread (which made
 * the client re-fire auto-mark-read and the row visibly flap).
 *
 * Two layers:
 *   1. MailboxController::reconcileSeen() -- the pure merge rule
 *      (IMAP vs DB vs pending-outbox). No I/O; full truth table.
 *   2. ConversationService::getReadStateMap() -- the batch DB projection
 *      that feeds the overlay. Exercised against a real (test-only)
 *      folder identity + conversation member rows.
 *
 * All test rows use the recognizable `flowone_test_` prefix and the
 * cleanup handler removes them on EVERY exit path (success, failure,
 * SIGINT, SIGTERM). The test never touches IMAP and never mutates
 * production data.
 *
 * Per .cursor/rules/server-side-testing.mdc.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/read-state-overlay-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Core\Database;
use Webmail\Controllers\MailboxController;
use Webmail\Services\ConversationService;
use Webmail\Services\FolderIndexService;

$runner = new FlowOneTestRunner('read-state-overlay', $argv);

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

foreach (['webmail_conversation_members', 'webmail_folder_identity'] as $table) {
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (\Throwable $e) {
        $runner->log("required table missing: {$table} -- run migrations first: " . $e->getMessage());
        exit(1);
    }
}

$testUser   = 'flowone_test_overlay@example.invalid';
$testFolder = 'INBOX.flowone_test_overlay';

// Idempotent cleanup: removes ALL test rows we may have created. Runs at
// start (so a prior crash never leaks into this run) and at end.
$wipe = function () use ($db, $testUser): array {
    $m = $db->prepare('DELETE FROM webmail_conversation_members WHERE user_email = :u');
    $m->execute([':u' => $testUser]);
    $members = $m->rowCount();

    $f = $db->prepare('DELETE FROM webmail_folder_identity WHERE account_id = :u');
    try {
        $f->execute([':u' => $testUser]);
        $folders = $f->rowCount();
    } catch (\Throwable $e) {
        // Some schemas key folder identity on a different column name; the
        // member wipe above is the one that matters for non-destructiveness.
        $folders = 0;
    }
    return ['members' => $members, 'folders' => $folders];
};
$initial = $wipe();
$runner->log("pre-test cleanup removed {$initial['members']} member rows, {$initial['folders']} folder rows");
$runner->addCleanup(function () use ($wipe, $runner) {
    $n = $wipe();
    $runner->log("post-test cleanup removed {$n['members']} member rows, {$n['folders']} folder rows");
});

// -- Smoke --------------------------------------------------------------------

if ($runner->shouldRunSection('0. SMOKE')) {
    $runner->section('0. SMOKE');
    $runner->test('reconcileSeen is callable', function () use ($runner) {
        $runner->assertTrue(
            method_exists(MailboxController::class, 'reconcileSeen'),
            'MailboxController::reconcileSeen missing'
        );
    });
    $runner->test('getReadStateMap is callable', function () use ($runner) {
        $runner->assertTrue(
            method_exists(ConversationService::class, 'getReadStateMap'),
            'ConversationService::getReadStateMap missing'
        );
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

// -- 1. reconcileSeen truth table --------------------------------------------
//
// reconcileSeen(imapSeen, dbSeen, pending):
//   pending=true  -> dbSeen wins (latest un-drained user intent, read OR unread)
//   pending=false -> imapSeen OR dbSeen (either source reporting read is enough)

if ($runner->shouldRunSection('1. RECONCILE')) {
    $runner->section('1. RECONCILE (pure merge rule)');

    $runner->test('not pending: both unread -> unread', function () use ($runner) {
        $runner->assertEquals(false, MailboxController::reconcileSeen(false, false, false));
    });
    $runner->test('not pending: IMAP read only (read elsewhere) -> read', function () use ($runner) {
        $runner->assertEquals(true, MailboxController::reconcileSeen(true, false, false));
    });
    $runner->test('not pending: DB read only (read in-app, not yet drained) -> read', function () use ($runner) {
        $runner->assertEquals(true, MailboxController::reconcileSeen(false, true, false));
    });
    $runner->test('not pending: both read -> read', function () use ($runner) {
        $runner->assertEquals(true, MailboxController::reconcileSeen(true, true, false));
    });

    $runner->test('pending: DB wins read over stale IMAP unread', function () use ($runner) {
        $runner->assertEquals(true, MailboxController::reconcileSeen(false, true, true));
    });
    $runner->test('pending: DB wins UNREAD over stale IMAP read (mark-unread)', function () use ($runner) {
        // The critical case the OR rule would get wrong: user marked unread
        // in-app, IMAP still has \Seen, op not yet drained -> must show unread.
        $runner->assertEquals(false, MailboxController::reconcileSeen(true, false, true));
    });
    $runner->test('pending: DB read with IMAP read stays read', function () use ($runner) {
        $runner->assertEquals(true, MailboxController::reconcileSeen(true, true, true));
    });
    $runner->test('pending: DB unread with IMAP unread stays unread', function () use ($runner) {
        $runner->assertEquals(false, MailboxController::reconcileSeen(false, false, true));
    });
}

// -- 2. getReadStateMap (DB projection) --------------------------------------

if ($runner->shouldRunSection('2. READSTATEMAP')) {
    $runner->section('2. READSTATEMAP (batch DB projection)');

    $conv = new ConversationService($config);

    // Create a throwaway folder identity so resolveFolderId() succeeds.
    $folderId = null;
    $runner->test('setup: create test folder identity', function () use ($runner, $config, $testUser, $testFolder, &$folderId) {
        $idx = new FolderIndexService($config);
        $folderId = $idx->upsertFromListing($testUser, [
            'path'         => $testFolder,
            'name'         => 'flowone_test_overlay',
            'display_name' => 'flowone_test_overlay',
            'delimiter'    => '.',
            'is_selectable'=> 1,
        ]);
        $runner->assertTrue(is_string($folderId) && $folderId !== '', 'no folder_id returned');
    });

    // Seed conversation members: uid 1001 read, uid 1002 unread.
    $runner->test('setup: seed member rows (1001 read, 1002 unread)', function () use ($runner, $db, $testUser, &$folderId) {
        $runner->assertTrue($folderId !== null, 'folderId not set from previous step');
        $ins = $db->prepare(
            "INSERT INTO webmail_conversation_members
                (user_email, conversation_id, message_id, message_id_hash, folder_id, uid, is_seen, message_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $ins->execute([$testUser, 'flowone_test_conv_a', '<flowone_test_1001@x>', md5('<flowone_test_1001@x>'), $folderId, 1001, 1]);
        $ins->execute([$testUser, 'flowone_test_conv_b', '<flowone_test_1002@x>', md5('<flowone_test_1002@x>'), $folderId, 1002, 0]);
        $runner->assertTrue(true);
    });

    $runner->test('maps read uid -> true, unread uid -> false', function () use ($runner, $conv, $testUser, $testFolder) {
        $map = $conv->getReadStateMap($testUser, $testFolder, [1001, 1002]);
        $runner->assertTrue(array_key_exists(1001, $map), '1001 absent from map');
        $runner->assertTrue(array_key_exists(1002, $map), '1002 absent from map');
        $runner->assertEquals(true, $map[1001]);
        $runner->assertEquals(false, $map[1002]);
    });

    $runner->test('omits UIDs with no member row (DB has no opinion)', function () use ($runner, $conv, $testUser, $testFolder) {
        $map = $conv->getReadStateMap($testUser, $testFolder, [1001, 9999]);
        $runner->assertTrue(array_key_exists(1001, $map), '1001 should be present');
        $runner->assertTrue(!array_key_exists(9999, $map), '9999 should be omitted, not defaulted');
    });

    $runner->test('empty uid list -> empty map', function () use ($runner, $conv, $testUser, $testFolder) {
        $runner->assertEquals([], $conv->getReadStateMap($testUser, $testFolder, []));
    });

    $runner->test('unknown folder -> empty map (no identity)', function () use ($runner, $conv, $testUser) {
        $runner->assertEquals([], $conv->getReadStateMap($testUser, 'INBOX.flowone_test_nonexistent', [1001]));
    });

    // -- 3. IMAP -> DB persistence (the missing-half write) -------------------
    //
    // persistImapFlagChanges() reduces to updateMembersReadStatusBatch(); we
    // verify that writer faithfully flips DB read state so a flag change
    // observed on IMAP (e.g. a read made on another device) is mirrored into
    // our DB and visible via getReadStateMap().

    $runner->test('IMAP->DB: flipping unread->read persists to DB', function () use ($runner, $conv, $testUser, $testFolder) {
        // uid 1002 was seeded unread; simulate IMAP reporting it \Seen.
        $ok = $conv->updateMembersReadStatusBatch($testUser, $testFolder, [1002], true);
        $runner->assertTrue($ok, 'batch write returned false');
        $map = $conv->getReadStateMap($testUser, $testFolder, [1002]);
        $runner->assertEquals(true, $map[1002] ?? null);
    });

    $runner->test('IMAP->DB: flipping read->unread persists to DB', function () use ($runner, $conv, $testUser, $testFolder) {
        // Simulate IMAP reporting the message back to unread (e.g. unread elsewhere).
        $ok = $conv->updateMembersReadStatusBatch($testUser, $testFolder, [1002], false);
        $runner->assertTrue($ok, 'batch write returned false');
        $map = $conv->getReadStateMap($testUser, $testFolder, [1002]);
        $runner->assertEquals(false, $map[1002] ?? null);
    });

    $runner->test('IMAP->DB: no-op for empty uid list', function () use ($runner, $conv, $testUser, $testFolder) {
        $runner->assertEquals(true, $conv->updateMembersReadStatusBatch($testUser, $testFolder, [], true));
    });
}

exit($runner->finish());
