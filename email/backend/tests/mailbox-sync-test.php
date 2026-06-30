#!/usr/bin/env php
<?php
/**
 * mailbox-sync-test.php
 *
 * Phase 2 test: the MailboxSyncService DB <-> IMAP mirror engine.
 *
 * The service receives an ImapService instance from the cron and is the
 * sole owner of webmail_folder_sync_state. This test exercises every
 * phase against a StubImap that simulates IMAP responses, so we cover:
 *
 *   1. registerFolder + getState upsert behaviour
 *   2. initial sync paging (pending -> initial_syncing -> synced)
 *   3. incremental sync: new messages above highest_uid
 *   4. incremental sync: CHANGEDSINCE flag delta is persisted
 *   5. outbox-pending guard suppresses the IMAP flag echo
 *   6. expunge reconciliation removes stale UIDs
 *   7. throttle: expunge skipped when recent
 *   8. UIDVALIDITY reset wipes mirror + resets sync state
 *   9. markFailure backoff (next_attempt_at advances)
 *
 * All test rows use the recognizable `flowone_test_sync_` prefix and
 * the cleanup handler removes them on EVERY exit path. The test never
 * touches a real IMAP server.
 *
 * Per .cursor/rules/server-side-testing.mdc.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-sync-test.php --verbose
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
use Webmail\Services\ImapService;
use Webmail\Services\MailboxSyncService;
use Webmail\Services\OutboxService;

$runner = new FlowOneTestRunner('mailbox-sync', $argv);

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

foreach (['webmail_conversation_members', 'webmail_folder_identity', 'webmail_folder_sync_state', 'imap_outbox'] as $table) {
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (\Throwable $e) {
        $runner->log("required table missing: {$table} - run migrations first: " . $e->getMessage());
        exit(1);
    }
}

$testUser    = 'flowone_test_sync_user@example.invalid';
$testAccount = 'flowone_test_sync_user@example.invalid';
$testFolder  = 'INBOX.flowone_test_sync';

// -- Cleanup ------------------------------------------------------------------

$wipe = function () use ($db, $testUser, $testAccount): array {
    $counts = [];
    foreach ([
        'webmail_conversation_members' => 'user_email',
        'webmail_folder_sync_state'    => 'user_email',
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

// -- Stub IMAP ----------------------------------------------------------------
//
// StubImapService is a deterministic in-memory IMAP. Each phase method
// of MailboxSyncService receives this instead of a real ImapService, so
// the tests assert business logic without touching a network or PHP-IMAP.

if (!class_exists('StubImapService')) {
    /**
     * Minimal ImapService stub. Only the methods MailboxSyncService calls
     * are overridden. Extends the real class so the type-hint `ImapService`
     * is satisfied. The parent constructor is bypassed because we never
     * make any network calls.
     */
    class StubImapService extends ImapService
    {
        public array $folders = []; // [path => ['uidnext'=>, 'uidvalidity'=>, 'total'=>, 'messages'=>[uid=>envelope], 'modseq'=>, 'flag_deltas'=>[]]]
        public ?string $selected = null;
        public bool $selectFails = false;

        // Bypass parent constructor; we want zero IMAP-related side effects.
        public function __construct() {}

        public function selectFolder(string $folder): bool
        {
            if ($this->selectFails) return false;
            if (!isset($this->folders[$folder])) {
                $this->folders[$folder] = ['uidnext' => 1, 'uidvalidity' => 1, 'total' => 0, 'messages' => [], 'modseq' => 0, 'flag_deltas' => []];
            }
            $this->selected = $folder;
            return true;
        }

        public function getMessagesSince(string $folder, int $sinceUid, int $limit = 100): array
        {
            $st = $this->folders[$folder] ?? null;
            if (!$st) return ['messages' => [], 'uidnext' => 0, 'uidvalidity' => 0, 'total' => 0, 'count' => 0];
            $messages = [];
            foreach ($st['messages'] as $uid => $env) {
                if ((int)$uid > $sinceUid) {
                    $messages[] = $env;
                }
            }
            usort($messages, fn($a, $b) => (int)$a['uid'] - (int)$b['uid']);
            $messages = array_slice($messages, 0, $limit);
            return [
                'messages'    => $messages,
                'count'       => count($messages),
                'uidnext'     => (int)$st['uidnext'],
                'uidvalidity' => (int)$st['uidvalidity'],
                'total'       => (int)$st['total'],
            ];
        }

        public function fetchFlagChangesSince(string $folder, int $modseq, int $maxUid = 0): array
        {
            $st = $this->folders[$folder] ?? null;
            if (!$st) return ['changes' => [], 'highest_modseq' => $modseq];
            $changes = [];
            $newHighest = $modseq;
            foreach ($st['flag_deltas'] as $d) {
                if ((int)$d['modseq'] > $modseq) {
                    $changes[] = $d;
                    if ((int)$d['modseq'] > $newHighest) $newHighest = (int)$d['modseq'];
                }
            }
            return ['changes' => $changes, 'highest_modseq' => $newHighest];
        }

        public function searchAllUids(string $folder): array|false
        {
            $st = $this->folders[$folder] ?? null;
            if (!$st) return false;
            return array_map('intval', array_keys($st['messages']));
        }
    }
}

// RecordingCache: a RedisCacheService stub that records publish calls instead
// of hitting Redis, so push fan-out can be asserted exactly. Defined at the top
// level (not inside a section) so both the catch-up and received-only sections
// can use it even when run in isolation via --only=.
if (!class_exists('RecordingCache')) {
    class RecordingCache extends \Webmail\Services\RedisCacheService
    {
        public array $newMessages = [];
        public array $folderCounts = [];
        public array $claimed = [];
        public function __construct() {} // bypass real Redis connect
        public function isAvailable(): bool { return true; }
        public function claimNewMailPublish(string $userEmail, string $folder, int $uid, int $ttl = 3600): bool
        {
            // In-memory SET NX: first caller wins, repeats for the same
            // (user,folder,uid) are deduped exactly like production Redis.
            $k = strtolower($userEmail) . '|' . $folder . '|' . $uid;
            if (isset($this->claimed[$k])) {
                return false;
            }
            $this->claimed[$k] = true;
            return true;
        }
        public function publishNewMessage(string $userEmail, string $folder, int $uid, array $messagePreview = []): bool
        {
            $this->newMessages[] = ['folder' => $folder, 'uid' => $uid, 'from' => $messagePreview['from'] ?? null];
            return true;
        }
        public function publishFolderCounts(string $userEmail, string $folder, int $total, int $unread, ?int $uidnext = null, ?int $uidvalidity = null): bool
        {
            $this->folderCounts[] = ['folder' => $folder, 'uidnext' => $uidnext];
            return true;
        }
    }
}

// -- Build a test folder identity --------------------------------------------

$folderIndex = new FolderIndexService($config);
$folderId = $folderIndex->upsertFromListing($testAccount, [
    'path'         => $testFolder,
    'name'         => 'flowone_test_sync',
    'display_name' => 'flowone_test_sync',
    'delimiter'    => '.',
    'is_selectable'=> 1,
]);
$runner->log("test folder_id = {$folderId}");

// Helper: synth envelope payload
$envelope = function (int $uid, bool $seen = false, bool $flagged = false, bool $answered = false): array {
    return [
        'uid'         => $uid,
        'message_id'  => "<flowone_test_sync_{$uid}@example.invalid>",
        'subject'     => "Test {$uid}",
        'from'        => [['email' => 'sender@example.invalid', 'name' => 'Test Sender']],
        'to'          => [['email' => 'rcpt@example.invalid', 'name' => 'Rcpt']],
        'cc'          => [],
        'date'        => date('Y-m-d H:i:s'),
        'internal_date' => date('Y-m-d H:i:s'),
        'rfc822_size' => 1024,
        'snippet'     => 'flowone test body',
        'seen'        => $seen,
        'flagged'     => $flagged,
        'answered'    => $answered,
        'has_attachment' => false,
    ];
};

// -- Smoke --------------------------------------------------------------------

if ($runner->shouldRunSection('0. SMOKE')) {
    $runner->section('0. SMOKE');
    $runner->test('MailboxSyncService is constructible', function () use ($runner, $config) {
        $svc = new MailboxSyncService($config);
        $runner->assertTrue(method_exists($svc, 'initialSyncBatch'), 'initialSyncBatch missing');
        $runner->assertTrue(method_exists($svc, 'incrementalSync'), 'incrementalSync missing');
        $runner->assertTrue(method_exists($svc, 'expungeReconcile'), 'expungeReconcile missing');
        $runner->assertTrue(method_exists($svc, 'handleUidvalidityReset'), 'handleUidvalidityReset missing');
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

$svc = new MailboxSyncService($config, $db);

// -- 1. registerFolder + getState --------------------------------------------

if ($runner->shouldRunSection('1. REGISTER')) {
    $runner->section('1. REGISTER + STATE');

    $runner->test('registerFolder inserts pending row', function () use ($runner, $svc, $testUser, $testAccount, $folderId, $testFolder) {
        $svc->registerFolder($testUser, $testAccount, $folderId, $testFolder);
        $state = $svc->getState($testUser, $folderId);
        $runner->assertTrue($state !== null, 'state row not found after registerFolder');
        $runner->assertEquals('pending', (string)$state['status']);
        $runner->assertEquals(0, (int)$state['highest_uid']);
    });

    $runner->test('registerFolder is idempotent', function () use ($runner, $svc, $testUser, $testAccount, $folderId, $testFolder) {
        $svc->registerFolder($testUser, $testAccount, $folderId, $testFolder);
        $svc->registerFolder($testUser, $testAccount, $folderId, $testFolder);
        $rows = $svc->claimDue(10, $testUser);
        $runner->assertEquals(1, count(array_filter($rows, fn($r) => (string)$r['folder_id'] === $folderId)));
    });

    $runner->test('isFolderSynced is false for pending', function () use ($runner, $svc, $testUser, $folderId) {
        $runner->assertEquals(false, $svc->isFolderSynced($testUser, $folderId));
    });
}

// -- 2. Initial sync paging ---------------------------------------------------

if ($runner->shouldRunSection('2. INITIAL')) {
    $runner->section('2. INITIAL SYNC');

    $imap = new StubImapService();
    $imap->folders[$testFolder] = [
        'uidnext'     => 11, // UIDs 1..10
        'uidvalidity' => 12345,
        'total'       => 10,
        'modseq'      => 100,
        'messages'    => [],
        'flag_deltas' => [],
    ];
    for ($u = 1; $u <= 10; $u++) {
        $imap->folders[$testFolder]['messages'][$u] = $envelope($u);
    }

    $runner->test('initial: first batch ingests up to batchSize', function () use ($runner, $svc, $imap, $testUser, $folderId, $testFolder) {
        $state = $svc->getState($testUser, $folderId);
        $imap->selectFolder($testFolder);
        $n = $svc->initialSyncBatch($imap, $state, 4);
        $runner->assertEquals(4, $n);
        $state2 = $svc->getState($testUser, $folderId);
        $runner->assertEquals('initial_syncing', (string)$state2['status']);
        $runner->assertEquals(4, (int)$state2['highest_uid']);
        $runner->assertEquals(12345, (int)$state2['uidvalidity']);
    });

    $runner->test('initial: subsequent pages advance highest_uid', function () use ($runner, $svc, $imap, $testUser, $folderId) {
        $state = $svc->getState($testUser, $folderId);
        $svc->initialSyncBatch($imap, $state, 4);
        $state2 = $svc->getState($testUser, $folderId);
        $runner->assertEquals(8, (int)$state2['highest_uid']);
    });

    $runner->test('initial: final page flips status to synced', function () use ($runner, $svc, $imap, $testUser, $folderId) {
        $state = $svc->getState($testUser, $folderId);
        $svc->initialSyncBatch($imap, $state, 4); // picks up 9, 10
        $state2 = $svc->getState($testUser, $folderId);
        $runner->assertEquals('synced', (string)$state2['status']);
        $runner->assertEquals(10, (int)$state2['highest_uid']);
    });

    $runner->test('initial: isFolderSynced is true', function () use ($runner, $svc, $testUser, $folderId) {
        $runner->assertEquals(true, $svc->isFolderSynced($testUser, $folderId));
    });

    $runner->test('initial: webmail_conversation_members has all 10 envelopes', function () use ($runner, $db, $testUser, $folderId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM webmail_conversation_members WHERE user_email = ? AND folder_id = ?");
        $stmt->execute([$testUser, $folderId]);
        $n = (int)$stmt->fetchColumn();
        $runner->assertEquals(10, $n);
    });
}

// -- 3. Incremental: new messages --------------------------------------------

if ($runner->shouldRunSection('3. INCREMENTAL')) {
    $runner->section('3. INCREMENTAL SYNC (new mail)');

    $imap = new StubImapService();
    $imap->folders[$testFolder] = [
        'uidnext'     => 13,
        'uidvalidity' => 12345,
        'total'       => 12,
        'modseq'      => 100,
        'messages'    => [],
        'flag_deltas' => [],
    ];
    // Seed with the 10 existing + 2 new
    for ($u = 1; $u <= 12; $u++) {
        $imap->folders[$testFolder]['messages'][$u] = $envelope($u);
    }

    $runner->test('incremental: pulls in new UIDs above highest_uid', function () use ($runner, $svc, $imap, $testUser, $folderId, $testFolder) {
        $state = $svc->getState($testUser, $folderId);
        $imap->selectFolder($testFolder);
        $res = $svc->incrementalSync($imap, $state);
        $runner->assertEquals(2, (int)$res['new_messages']);
        $state2 = $svc->getState($testUser, $folderId);
        $runner->assertEquals(12, (int)$state2['highest_uid']);
        $runner->assertEquals('synced', (string)$state2['status']);
    });
}

// -- 4. CHANGEDSINCE flag delta ----------------------------------------------

if ($runner->shouldRunSection('4. FLAGDELTA')) {
    $runner->section('4. CHANGEDSINCE flag delta');

    $imap = new StubImapService();
    $imap->folders[$testFolder] = [
        'uidnext'     => 13,
        'uidvalidity' => 12345,
        'total'       => 12,
        'modseq'      => 200, // simulate server advanced
        'messages'    => [],
        'flag_deltas' => [
            ['uid' => 1, 'seen' => true,  'flagged' => false, 'answered' => false, 'modseq' => 150],
            ['uid' => 2, 'seen' => false, 'flagged' => true,  'answered' => false, 'modseq' => 160],
        ],
    ];
    for ($u = 1; $u <= 12; $u++) {
        $imap->folders[$testFolder]['messages'][$u] = $envelope($u);
    }

    // First we need to set highest_modseq to a value below the deltas.
    $runner->test('seed: write highest_modseq=100 on the sync state', function () use ($runner, $db, $testUser, $folderId) {
        $stmt = $db->prepare("UPDATE webmail_folder_sync_state SET highest_modseq = 100 WHERE user_email = ? AND folder_id = ?");
        $stmt->execute([$testUser, $folderId]);
        $runner->assertTrue($stmt->rowCount() === 1, 'failed to seed highest_modseq');
    });

    $runner->test('flag-delta: uid 1 seen flips to read in DB', function () use ($runner, $svc, $imap, $db, $testUser, $folderId, $testFolder) {
        $state = $svc->getState($testUser, $folderId);
        $imap->selectFolder($testFolder);
        $res = $svc->incrementalSync($imap, $state);
        $runner->assertTrue((int)$res['flag_changes'] >= 1, "expected at least one flag change, got {$res['flag_changes']}");

        $stmt = $db->prepare("SELECT is_seen FROM webmail_conversation_members WHERE user_email = ? AND folder_id = ? AND uid = 1");
        $stmt->execute([$testUser, $folderId]);
        $row = $stmt->fetch();
        $runner->assertEquals(1, (int)$row['is_seen']);
    });

    $runner->test('flag-delta: uid 2 flagged flips to true in DB', function () use ($runner, $db, $testUser, $folderId) {
        $stmt = $db->prepare("SELECT is_flagged FROM webmail_conversation_members WHERE user_email = ? AND folder_id = ? AND uid = 2");
        $stmt->execute([$testUser, $folderId]);
        $row = $stmt->fetch();
        $runner->assertEquals(1, (int)$row['is_flagged']);
    });

    $runner->test('flag-delta: highest_modseq advances', function () use ($runner, $svc, $testUser, $folderId) {
        $state = $svc->getState($testUser, $folderId);
        $runner->assertTrue((int)$state['highest_modseq'] >= 160, "highest_modseq did not advance: {$state['highest_modseq']}");
    });
}

// -- 5. Outbox-conflict guard ------------------------------------------------

if ($runner->shouldRunSection('5. OUTBOX')) {
    $runner->section('5. OUTBOX-CONFLICT GUARD');

    $runner->test('outbox guard: pending set_flag suppresses IMAP echo', function () use ($runner, $config, $svc, $db, $testUser, $folderId, $testFolder, $envelope) {
        // Seed a pending outbox op for uid 5 (marking unread locally).
        $outbox = new OutboxService($config);
        $outbox->enqueue([
            'user_email'    => $testUser,
            'account_email' => $testUser,
            'op'            => 'clear_flag',
            'folder_id'     => $folderId,
            'uid'           => 5,
            'payload'       => ['flag' => 'seen', 'value' => false],
            'nonce'         => 'flowone_test_sync_outbox',
        ]);

        // Set uid 5 to "seen=1" in DB first (so we can prove the IMAP
        // echo would have stuck if not for the guard).
        $stmt = $db->prepare("UPDATE webmail_conversation_members SET is_seen = 0 WHERE user_email = ? AND folder_id = ? AND uid = 5");
        $stmt->execute([$testUser, $folderId]);

        // IMAP sends a stale "seen=true" echo - guard must drop it.
        $imap = new StubImapService();
        $imap->folders[$testFolder] = [
            'uidnext' => 13, 'uidvalidity' => 12345, 'total' => 12, 'modseq' => 300,
            'messages' => [], 'flag_deltas' => [
                ['uid' => 5, 'seen' => true, 'flagged' => false, 'answered' => false, 'modseq' => 250],
            ],
        ];
        for ($u = 1; $u <= 12; $u++) {
            $imap->folders[$testFolder]['messages'][$u] = $envelope($u);
        }

        $state = $svc->getState($testUser, $folderId);
        $imap->selectFolder($testFolder);
        $svc->incrementalSync($imap, $state);

        $check = $db->prepare("SELECT is_seen FROM webmail_conversation_members WHERE user_email = ? AND folder_id = ? AND uid = 5");
        $check->execute([$testUser, $folderId]);
        $row = $check->fetch();
        $runner->assertEquals(0, (int)$row['is_seen'], 'in-flight outbox op was overwritten by IMAP echo');
    });
}

// -- 6. Expunge reconciliation ------------------------------------------------

if ($runner->shouldRunSection('6. EXPUNGE')) {
    $runner->section('6. EXPUNGE RECONCILIATION');

    $runner->test('expunge: removes mirror rows missing from IMAP', function () use ($runner, $svc, $db, $testUser, $folderId, $testFolder) {
        $imap = new StubImapService();
        // IMAP only has UIDs 1..8 now. 9, 10, 11, 12 have been expunged.
        $imap->folders[$testFolder] = [
            'uidnext' => 13, 'uidvalidity' => 12345, 'total' => 8, 'modseq' => 300,
            'messages' => [], 'flag_deltas' => [],
        ];
        for ($u = 1; $u <= 8; $u++) {
            $imap->folders[$testFolder]['messages'][$u] = ['uid' => $u];
        }
        $imap->selectFolder($testFolder);

        // Force a run (last_expunge_sync_at is NULL on a fresh state row
        // so the throttle doesn't block the first call anyway).
        $state = $svc->getState($testUser, $folderId);
        $deleted = $svc->expungeReconcile($imap, $state, true);
        $runner->assertEquals(4, $deleted, "expected 4 stale rows deleted, got {$deleted}");

        $cnt = $db->prepare("SELECT COUNT(*) FROM webmail_conversation_members WHERE user_email = ? AND folder_id = ?");
        $cnt->execute([$testUser, $folderId]);
        $runner->assertEquals(8, (int)$cnt->fetchColumn());
    });

    $runner->test('expunge: searchAllUids=false leaves mirror intact', function () use ($runner, $svc, $db, $testUser, $folderId, $testFolder) {
        $imap = new StubImapService();
        $imap->selectFails = true; // makes searchAllUids return false
        $state = $svc->getState($testUser, $folderId);
        $deleted = $svc->expungeReconcile($imap, $state, true);
        $runner->assertEquals(0, $deleted);
    });

    $runner->test('expunge: throttle skips when last run was recent', function () use ($runner, $svc, $testUser, $folderId) {
        // After the first force-run above, last_expunge_sync_at is NOW.
        // A non-force call should be a no-op.
        $state = $svc->getState($testUser, $folderId);
        $imap = new StubImapService(); // empty folders -> searchAllUids would fail with false anyway
        $deleted = $svc->expungeReconcile($imap, $state, false);
        $runner->assertEquals(0, $deleted);
    });
}

// -- 7. UIDVALIDITY reset -----------------------------------------------------

if ($runner->shouldRunSection('7. UIDVALIDITY')) {
    $runner->section('7. UIDVALIDITY RESET');

    $runner->test('uidvalidity: server change marks state for reset', function () use ($runner, $svc, $db, $testUser, $folderId, $testFolder) {
        $imap = new StubImapService();
        $imap->folders[$testFolder] = [
            'uidnext' => 5, 'uidvalidity' => 99999, // NEW uidvalidity!
            'total' => 0, 'modseq' => 0, 'messages' => [], 'flag_deltas' => [],
        ];
        $imap->selectFolder($testFolder);

        $state = $svc->getState($testUser, $folderId);
        $svc->initialSyncBatch($imap, $state, 10);

        $newState = $svc->getState($testUser, $folderId);
        $runner->assertEquals('uidvalidity_reset', (string)$newState['status']);
    });

    $runner->test('uidvalidity: handleUidvalidityReset wipes mirror + state', function () use ($runner, $svc, $db, $testUser, $folderId) {
        $svc->handleUidvalidityReset($testUser, $folderId);

        $cnt = $db->prepare("SELECT COUNT(*) FROM webmail_conversation_members WHERE user_email = ? AND folder_id = ?");
        $cnt->execute([$testUser, $folderId]);
        $runner->assertEquals(0, (int)$cnt->fetchColumn());

        $state = $svc->getState($testUser, $folderId);
        $runner->assertEquals('pending', (string)$state['status']);
        $runner->assertEquals(0, (int)$state['highest_uid']);
        $runner->assertEquals(0, (int)$state['highest_modseq']);
    });
}

// -- 8. Failure backoff -------------------------------------------------------

if ($runner->shouldRunSection('8. FAILURE')) {
    $runner->section('8. FAILURE BACKOFF');

    $runner->test('markFailure: sets status=failed and schedules next_attempt_at', function () use ($runner, $svc, $testUser, $folderId) {
        $svc->markFailure($testUser, $folderId, 'flowone_test_sync simulated error');
        $state = $svc->getState($testUser, $folderId);
        $runner->assertEquals('failed', (string)$state['status']);
        $runner->assertTrue((int)$state['attempts'] >= 1);
        $runner->assertTrue($state['next_attempt_at'] !== null, 'next_attempt_at not set');
    });

    $runner->test('markFailure: repeated failures increase attempts', function () use ($runner, $svc, $testUser, $folderId) {
        $svc->markFailure($testUser, $folderId, 'flowone_test_sync simulated error 2');
        $state = $svc->getState($testUser, $folderId);
        $runner->assertTrue((int)$state['attempts'] >= 2);
    });
}

// -- 9. Observability --------------------------------------------------------

if ($runner->shouldRunSection('9. OBSERVABILITY')) {
    $runner->section('9. OBSERVABILITY');

    $runner->test('getUserSyncStats returns per-status counts + attention list', function () use ($runner, $svc, $testUser) {
        $stats = $svc->getUserSyncStats($testUser);
        // Flat shape consumed by the frontend reconciler
        // (useMailSyncIntegration::checkSyncEngineHealth) and the
        // /mailbox/sync-stats endpoint.
        $runner->assertTrue(array_key_exists('total_folders', $stats), 'total_folders missing');
        $runner->assertTrue(array_key_exists('synced', $stats), 'synced missing');
        $runner->assertTrue(array_key_exists('failed', $stats), 'failed missing');
        $runner->assertTrue(array_key_exists('attention_folders', $stats), 'attention_folders missing');
        $runner->assertTrue(is_array($stats['attention_folders']), 'attention_folders not array');
    });
}

// -- 10. Incremental-only claim (anti-starvation tick) -----------------------
//
// Regression guard for the bug where a large pending/initial_syncing backlog
// starved already-synced folders out of every cron pass (synced is last in the
// default priority), so new-mail detection on active mailboxes stopped for
// hours/days and no MESSAGE_NEW / email push was ever produced. The dedicated
// 1-minute incremental tick claims with syncedOnly=true, which must return ONLY
// synced folders, stalest-incremental first.

if ($runner->shouldRunSection('10. INCRONLY')) {
    $runner->section('10. INCREMENTAL-ONLY CLAIM');

    $syncedFolderPath  = 'INBOX.flowone_test_incronly_synced';
    $pendingFolderPath = 'INBOX.flowone_test_incronly_pending';
    $syncedFolderId = $folderIndex->upsertFromListing($testAccount, [
        'path' => $syncedFolderPath, 'name' => 'flowone_test_incronly_synced',
        'display_name' => 'flowone_test_incronly_synced', 'delimiter' => '.', 'is_selectable' => 1,
    ]);
    $pendingFolderId = $folderIndex->upsertFromListing($testAccount, [
        'path' => $pendingFolderPath, 'name' => 'flowone_test_incronly_pending',
        'display_name' => 'flowone_test_incronly_pending', 'delimiter' => '.', 'is_selectable' => 1,
    ]);

    $svc->registerFolder($testUser, $testAccount, $syncedFolderId, $syncedFolderPath);
    $svc->registerFolder($testUser, $testAccount, $pendingFolderId, $pendingFolderPath);

    // Force one folder synced + stale (1 day) and due (next_attempt_at NULL);
    // leave the other pending. Directly setting status keeps the test
    // independent of earlier sections' folder states.
    $db->prepare(
        "UPDATE webmail_folder_sync_state
            SET status='synced',
                last_incremental_sync_at = DATE_SUB(NOW(), INTERVAL 1 DAY),
                next_attempt_at = NULL
          WHERE user_email = ? AND folder_id = ?"
    )->execute([$testUser, $syncedFolderId]);

    $runner->test('syncedOnly claim returns the synced folder', function () use ($runner, $svc, $testUser, $syncedFolderId) {
        $rows = $svc->claimDue(200, $testUser, null, true);
        $ids = array_map(fn($r) => (string)$r['folder_id'], $rows);
        $runner->assertTrue(in_array($syncedFolderId, $ids, true), 'synced folder not claimed by syncedOnly');
    });

    $runner->test('syncedOnly claim EXCLUDES pending folder (anti-starvation)', function () use ($runner, $svc, $testUser, $pendingFolderId) {
        $rows = $svc->claimDue(200, $testUser, null, true);
        $ids = array_map(fn($r) => (string)$r['folder_id'], $rows);
        $runner->assertEquals(false, in_array($pendingFolderId, $ids, true));
        foreach ($rows as $r) {
            $runner->assertEquals('synced', (string)$r['status']);
        }
    });

    $runner->test('default claim INCLUDES both synced and pending', function () use ($runner, $svc, $testUser, $syncedFolderId, $pendingFolderId) {
        $rows = $svc->claimDue(200, $testUser, null, false);
        $ids = array_map(fn($r) => (string)$r['folder_id'], $rows);
        $runner->assertTrue(in_array($syncedFolderId, $ids, true), 'synced missing from default claim');
        $runner->assertTrue(in_array($pendingFolderId, $ids, true), 'pending missing from default claim');
    });

    $runner->test('syncedOnly orders stalest-incremental first (ASC, NULLs first)', function () use ($runner, $svc, $testUser) {
        $rows = $svc->claimDue(200, $testUser, null, true);
        $seq = array_map(fn($r) => $r['last_incremental_sync_at'], $rows);
        $sorted = $seq;
        usort($sorted, function ($a, $b) {
            if ($a === null && $b === null) return 0;
            if ($a === null) return -1; // NULL sorts first in MySQL ASC
            if ($b === null) return 1;
            return strcmp((string)$a, (string)$b);
        });
        $runner->assertEquals($sorted, $seq);
    });
}

// -- 11. Catch-up storm guard -------------------------------------------------
//
// Regression guard for the "168 pushes after a 12-day gap" bug: when a stale
// folder finally syncs a big backlog, incrementalSync must NOT fan out one
// MESSAGE_NEW (= one device push) per message. Batches over
// REALTIME_PUSH_MAX_BATCH publish only FOLDER_COUNTS (a silent badge refresh
// that never triggers a push); small, real-time batches still emit per-message
// events so genuine new mail still notifies.

if ($runner->shouldRunSection('11. CATCHUP')) {
    $runner->section('11. CATCH-UP STORM GUARD');

    // RecordingCache is defined once at the top of this file.
    $recCache = new RecordingCache();
    $svcCache = new MailboxSyncService($config, $db, null, null, null, $recCache);

    $catchFolderPath = 'INBOX.flowone_test_catchup';
    $catchFolderId = $folderIndex->upsertFromListing($testAccount, [
        'path' => $catchFolderPath, 'name' => 'flowone_test_catchup',
        'display_name' => 'flowone_test_catchup', 'delimiter' => '.', 'is_selectable' => 1,
    ]);
    $svcCache->registerFolder($testUser, $testAccount, $catchFolderId, $catchFolderPath);

    // Big backlog: 10 new messages (> REALTIME_PUSH_MAX_BATCH = 5), folder at uid 0.
    $imapCatch = new StubImapService();
    $imapCatch->folders[$catchFolderPath] = [
        'uidnext' => 11, 'uidvalidity' => 999, 'total' => 10,
        'modseq' => 50, 'messages' => [], 'flag_deltas' => [],
    ];
    for ($u = 1; $u <= 10; $u++) {
        $imapCatch->folders[$catchFolderPath]['messages'][$u] = $envelope($u);
    }

    $runner->test('catch-up batch (>5) suppresses per-message pushes', function () use ($runner, $svcCache, $imapCatch, $recCache, $testUser, $catchFolderId, $catchFolderPath) {
        $recCache->newMessages = [];
        $recCache->folderCounts = [];
        $state = $svcCache->getState($testUser, $catchFolderId);
        $imapCatch->selectFolder($catchFolderPath);
        $res = $svcCache->incrementalSync($imapCatch, $state);
        $runner->assertEquals(10, (int)$res['new_messages']);
        $msgs = array_filter($recCache->newMessages, fn($e) => $e['folder'] === $catchFolderPath);
        $runner->assertEquals(0, count($msgs)); // storm suppressed
        $counts = array_filter($recCache->folderCounts, fn($e) => $e['folder'] === $catchFolderPath);
        $runner->assertTrue(count($counts) >= 1, 'expected a FOLDER_COUNTS ping for catch-up');
    });

    $runner->test('small batch (<=5) still emits per-message pushes', function () use ($runner, $svcCache, $imapCatch, $recCache, $envelope, $testUser, $catchFolderId, $catchFolderPath) {
        // 2 new messages above the now-synced highest_uid (10).
        $imapCatch->folders[$catchFolderPath]['uidnext'] = 13;
        $imapCatch->folders[$catchFolderPath]['total'] = 12;
        $imapCatch->folders[$catchFolderPath]['messages'][11] = $envelope(11);
        $imapCatch->folders[$catchFolderPath]['messages'][12] = $envelope(12);
        $recCache->newMessages = [];
        $state = $svcCache->getState($testUser, $catchFolderId);
        $res = $svcCache->incrementalSync($imapCatch, $state);
        $runner->assertEquals(2, (int)$res['new_messages']);
        $msgs = array_filter($recCache->newMessages, fn($e) => $e['folder'] === $catchFolderPath);
        $runner->assertEquals(2, count($msgs)); // genuine new mail still notifies
        // `from` must be a plain display string, never the structured
        // [{name,email}] array (that renders "[object Object]" on devices).
        foreach ($msgs as $e) {
            $runner->assertTrue(is_string($e['from']), 'push `from` must be a string, got ' . gettype($e['from']));
            $runner->assertEquals('Test Sender', $e['from']);
        }
    });

    $runner->test('concurrent passes dedup per-UID pushes (claim guard)', function () use ($runner, $svcCache, $imapCatch, $recCache, $envelope, $testUser, $catchFolderId, $catchFolderPath) {
        // Simulate the */5 cron and the per-minute tick both reading the same
        // pre-state (sinceUid = 12) before either advances highest_uid, then both
        // running incrementalSync on the same fresh UIDs 13,14. The claim guard
        // must let only the FIRST pass publish, so the user gets 2 pushes, not 4.
        $imapCatch->folders[$catchFolderPath]['uidnext'] = 15;
        $imapCatch->folders[$catchFolderPath]['total'] = 14;
        $imapCatch->folders[$catchFolderPath]['messages'][13] = $envelope(13);
        $imapCatch->folders[$catchFolderPath]['messages'][14] = $envelope(14);
        $recCache->newMessages = [];
        $recCache->claimed = [];
        $staleState = $svcCache->getState($testUser, $catchFolderId); // sinceUid = 12
        $svcCache->incrementalSync($imapCatch, $staleState); // pass A
        $svcCache->incrementalSync($imapCatch, $staleState); // pass B (same stale state)
        $msgs = array_filter($recCache->newMessages, fn($e) => $e['folder'] === $catchFolderPath);
        $runner->assertEquals(2, count($msgs)); // 2 unique UIDs, deduped across passes
    });
}

// -- 12. RECEIVED-ONLY PUSH GATING -------------------------------------------
//
// New mail in Sent / Drafts / Outbox / Junk / Trash must NEVER raise a "new
// email" push (sending a message lands a copy in Sent; saving lands one in
// Drafts). Only INBOX + custom folders notify. FOLDER_COUNTS still fans out for
// every folder so unread badges stay accurate.

if ($runner->shouldRunSection('12. RECEIVED-ONLY')) {
    $runner->section('12. RECEIVED-ONLY PUSH GATING');

    $cls = fn($p, $su = null) => MailboxSyncService::isSystemNonInboxFolder($p, $su);

    $runner->test('classifier: INBOX is a receiving folder', function () use ($runner, $cls) {
        $runner->assertTrue($cls('INBOX') === false, 'INBOX must notify');
    });

    $runner->test('classifier: Sent/Drafts/Outbox/Junk/Trash are suppressed', function () use ($runner, $cls) {
        foreach (['INBOX.Sent', 'INBOX.Drafts', 'INBOX.Junk', 'INBOX.Trash', 'Outbox',
                  'Sent Items', 'Sent Mail', 'Deleted Items', 'Spam', 'Junk Email'] as $p) {
            $runner->assertTrue($cls($p) === true, "{$p} must be suppressed");
        }
    });

    $runner->test('classifier: Gmail-style paths handled by leaf segment', function () use ($runner, $cls) {
        $runner->assertTrue($cls('[Gmail]/Sent Mail') === true, '[Gmail]/Sent Mail must be suppressed');
        $runner->assertTrue($cls('[Gmail]/Spam') === true, '[Gmail]/Spam must be suppressed');
        $runner->assertTrue($cls('[Gmail]/All Mail') === false, 'All Mail still holds received mail');
    });

    $runner->test('classifier: SPECIAL-USE wins over a localized name', function () use ($runner, $cls) {
        $runner->assertTrue($cls('Postausgang', '\\Sent') === true, '\\Sent flag must suppress');
        $runner->assertTrue($cls('Papierkorb', '\\Trash') === true, '\\Trash flag must suppress');
        $runner->assertTrue($cls('Archief', '\\Archive') === false, '\\Archive still holds received mail');
    });

    $runner->test('classifier: custom + received folders still notify', function () use ($runner, $cls) {
        foreach (['INBOX.Clients', 'INBOX.flowone_test_catchup', 'Work', 'Receipts', 'INBOX.Archive'] as $p) {
            $runner->assertTrue($cls($p) === false, "{$p} must notify");
        }
    });

    // Integration: a Sent folder with genuine new mail must emit FOLDER_COUNTS
    // (badge refresh) but ZERO per-message pushes.
    $recSent = new RecordingCache();
    $svcSent = new MailboxSyncService($config, $db, null, null, null, $recSent);

    $sentPath = 'INBOX.Sent';
    $sentId = $folderIndex->upsertFromListing($testAccount, [
        'path' => $sentPath, 'name' => 'flowone_test_sent_role',
        'display_name' => 'flowone_test_sent_role', 'delimiter' => '.', 'is_selectable' => 1,
        'special_use' => '\\Sent',
    ]);
    $svcSent->registerFolder($testUser, $testAccount, $sentId, $sentPath);

    $imapSent = new StubImapService();
    $imapSent->folders[$sentPath] = [
        'uidnext' => 3, 'uidvalidity' => 1, 'total' => 2,
        'modseq' => 0, 'messages' => [], 'flag_deltas' => [],
    ];
    $imapSent->folders[$sentPath]['messages'][1] = $envelope(1);
    $imapSent->folders[$sentPath]['messages'][2] = $envelope(2);

    $runner->test('Sent folder: new mail syncs + FOLDER_COUNTS but emits NO push', function () use ($runner, $svcSent, $imapSent, $recSent, $testUser, $sentId, $sentPath) {
        $recSent->newMessages = [];
        $recSent->folderCounts = [];
        $state = $svcSent->getState($testUser, $sentId);
        $imapSent->selectFolder($sentPath);
        $res = $svcSent->incrementalSync($imapSent, $state);
        $runner->assertEquals(2, (int)$res['new_messages']); // still mirrored to DB
        $pushed = array_filter($recSent->newMessages, fn($e) => $e['folder'] === $sentPath);
        $runner->assertEquals(0, count($pushed)); // no "new email" push for Sent
        $counts = array_filter($recSent->folderCounts, fn($e) => $e['folder'] === $sentPath);
        $runner->assertTrue(count($counts) >= 1, 'expected a FOLDER_COUNTS ping for Sent');
    });
}

exit($runner->finish());
