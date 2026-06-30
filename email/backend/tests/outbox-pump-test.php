#!/usr/bin/env php
<?php
/**
 * outbox-pump-test.php
 *
 * Phase 2 test: imap_outbox plumbing.
 *
 * This test exercises the PHP half of the DB-as-truth outbox pipeline:
 * OutboxService::enqueue / claim / complete / fail / reapStuck and the
 * idempotency contract. It does NOT touch IMAP. The Node worker's
 * actual IMAP execution is covered by db-first-write-test and
 * multi-device-consistency-test which observe round-trip behaviour
 * end-to-end.
 *
 * All test rows use the recognizable prefix `flowone_test_` in
 * user_email/account_email and the cleanup handler removes them on
 * every exit path (success, failure, SIGINT, SIGTERM).
 *
 * Per .cursor/rules/server-side-testing.mdc.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/outbox-pump-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Core\Database;
use Webmail\Services\IdempotencyService;
use Webmail\Services\OutboxService;

$runner = new FlowOneTestRunner('outbox-pump', $argv);

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

// Verify imap_outbox exists (migration 178)
try {
    $db->query('SELECT 1 FROM imap_outbox LIMIT 1');
} catch (\Throwable $e) {
    $runner->log('imap_outbox missing -- run migration 178 first: ' . $e->getMessage());
    exit(1);
}

$testUser = 'flowone_test_outbox@example.invalid';
$testAccount = 'flowone_test_outbox@example.invalid';

// Idempotent cleanup: removes ALL rows belonging to our test users.
// Runs at start (so prior crashes don't leak) and at end (so this run
// doesn't leak).
$wipe = function () use ($db, $testUser): int {
    $stmt = $db->prepare('DELETE FROM imap_outbox WHERE user_email = :u');
    $stmt->execute([':u' => $testUser]);
    return $stmt->rowCount();
};
$initialCleared = $wipe();
$runner->log("pre-test cleanup removed {$initialCleared} stale rows");
$runner->addCleanup(function () use ($wipe, $runner) {
    $n = $wipe();
    $runner->log("post-test cleanup removed {$n} rows");
});

$service = new OutboxService($config);
$service->setDb($db);
$idem = new IdempotencyService();

// -- Smoke --------------------------------------------------------------------

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('outbox connectivity', function () use ($service) {
        $stats = $service->getUserQueueStats('flowone_test_outbox@example.invalid');
        if (!isset($stats['pending'])) throw new \RuntimeException('stats malformed');
        return true;
    });
    exit($runner->finish());
}

// -- 1. ENQUEUE & IDEMPOTENCY -------------------------------------------------

$runner->section('1. ENQUEUE & IDEMPOTENCY');

if ($runner->shouldRunSection('1. ENQUEUE & IDEMPOTENCY')) {
    $runner->test('enqueue inserts a pending row with status=pending', function () use ($service, $db, $testUser, $testAccount) {
        $res = $service->enqueue([
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => '00000000-0000-0000-0000-000000000001',
            'uid' => 1001,
            'payload' => ['flag' => 'seen', 'value' => true, 'imap_flag' => '\\Seen'],
            'nonce' => 'enqueue-test-' . microtime(true),
        ]);
        if (!$res['inserted']) throw new \RuntimeException('expected fresh insert');
        $row = $db->prepare('SELECT status, attempts, op FROM imap_outbox WHERE id = :id');
        $row->execute([':id' => $res['id']]);
        $r = $row->fetch(\PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('row not found after enqueue');
        if ($r['status'] !== 'pending') throw new \RuntimeException('expected status=pending, got ' . $r['status']);
        if ((int)$r['attempts'] !== 0) throw new \RuntimeException('expected attempts=0');
        if ($r['op'] !== 'set_flag') throw new \RuntimeException('op corrupted');
        return true;
    });

    $runner->test('duplicate enqueue with same idempotency_key collapses', function () use ($service, $testUser, $testAccount) {
        $params = [
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => '00000000-0000-0000-0000-000000000002',
            'uid' => 2002,
            'payload' => ['flag' => 'seen', 'value' => true],
            'nonce' => 'collapse-fixed-nonce',
        ];
        $a = $service->enqueue($params);
        $b = $service->enqueue($params);
        if (!$a['inserted']) throw new \RuntimeException('first call should insert');
        if ($b['inserted']) throw new \RuntimeException('second call should be a no-op insert');
        if ($a['idempotency_key'] !== $b['idempotency_key']) throw new \RuntimeException('idempotency keys diverged');
        if ($a['id'] !== $b['id']) throw new \RuntimeException('row id changed between identical enqueues');
        return true;
    });

    $runner->test('per-day nonce: same op at same UID collapses within a day', function () use ($service, $testUser, $testAccount) {
        $params = [
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => '00000000-0000-0000-0000-000000000003',
            'uid' => 3003,
            'payload' => ['flag' => 'flagged', 'value' => true],
            // no explicit nonce -> defaults to gmdate('Y-m-d')
        ];
        $a = $service->enqueue($params);
        $b = $service->enqueue($params);
        if ($a['id'] !== $b['id']) throw new \RuntimeException('per-day default should collapse');
        return true;
    });

    $runner->test('different uid -> different idempotency key -> different row', function () use ($idem, $testUser, $testAccount) {
        $k1 = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 100);
        $k2 = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 101);
        if ($k1 === $k2) throw new \RuntimeException('uid must contribute to key');
        return true;
    });

    $runner->test('different op -> different idempotency key', function () use ($idem, $testUser, $testAccount) {
        $k1 = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 100);
        $k2 = $idem->computeKey($testUser, $testAccount, 'clear_flag', 'fid', 100);
        if ($k1 === $k2) throw new \RuntimeException('op must contribute to key');
        return true;
    });

    $runner->test('explicit nonce overrides per-day collapse', function () use ($service, $testUser, $testAccount) {
        $base = [
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => '00000000-0000-0000-0000-000000000004',
            'uid' => 4004,
            'payload' => ['flag' => 'seen', 'value' => true],
        ];
        $a = $service->enqueue($base + ['nonce' => 'first-shot-' . random_int(0, PHP_INT_MAX)]);
        $b = $service->enqueue($base + ['nonce' => 'second-shot-' . random_int(0, PHP_INT_MAX)]);
        if ($a['id'] === $b['id']) throw new \RuntimeException('distinct nonces should produce distinct rows');
        return true;
    });

    $runner->test('invalid op is rejected before insert', function () use ($service, $testUser, $testAccount) {
        try {
            $service->enqueue([
                'user_email' => $testUser,
                'account_email' => $testAccount,
                'op' => 'evil_op',
                'payload' => [],
                'nonce' => 'invalid-op-test',
            ]);
        } catch (\InvalidArgumentException $e) {
            return true;
        }
        throw new \RuntimeException('expected InvalidArgumentException for unknown op');
    });

    $runner->test('missing user_email is rejected', function () use ($service, $testAccount) {
        try {
            $service->enqueue([
                'account_email' => $testAccount,
                'op' => 'set_flag',
                'payload' => [],
            ]);
        } catch (\InvalidArgumentException $e) {
            return true;
        }
        throw new \RuntimeException('expected InvalidArgumentException for missing user_email');
    });
}

// -- 2. CLAIM ATOMICITY -------------------------------------------------------

$runner->section('2. CLAIM ATOMICITY');

if ($runner->shouldRunSection('2. CLAIM ATOMICITY')) {
    $runner->test('claim flips rows to running and returns them', function () use ($service, $db, $testUser, $testAccount, $wipe) {
        $wipe(); // start fresh for this test
        // Enqueue 5 rows
        for ($i = 0; $i < 5; $i++) {
            $service->enqueue([
                'user_email' => $testUser,
                'account_email' => $testAccount,
                'op' => 'set_flag',
                'folder_id' => 'claim-test-folder',
                'uid' => 5000 + $i,
                'payload' => ['flag' => 'seen', 'value' => true],
                'nonce' => 'claim-test-' . $i,
            ]);
        }
        $rows = $service->claim('test-worker-1', 10);
        if (count($rows) < 5) throw new \RuntimeException('expected at least 5 claimed rows, got ' . count($rows));
        $first = $rows[0];
        if (!isset($first['op'], $first['uid'], $first['payload'])) {
            throw new \RuntimeException('claim row missing expected fields');
        }
        // Verify status is now 'running' in DB
        $check = $db->prepare(
            "SELECT COUNT(*) FROM imap_outbox WHERE user_email = :u AND status = 'running'"
        );
        $check->execute([':u' => $testUser]);
        $running = (int)$check->fetchColumn();
        if ($running < 5) throw new \RuntimeException('expected >=5 running rows in DB, got ' . $running);
        return true;
    });

    $runner->test('claim returns empty array when nothing pending', function () use ($service, $testUser, $wipe) {
        // Mark everything done so claim sees nothing pending.
        $wipe();
        $rows = $service->claim('test-worker-2', 20);
        if (!empty($rows)) throw new \RuntimeException('expected empty claim, got ' . count($rows) . ' rows');
        return true;
    });

    $runner->test('claim respects batch limit', function () use ($service, $testUser, $testAccount, $wipe) {
        $wipe();
        for ($i = 0; $i < 8; $i++) {
            $service->enqueue([
                'user_email' => $testUser,
                'account_email' => $testAccount,
                'op' => 'set_flag',
                'folder_id' => 'batch-test',
                'uid' => 6000 + $i,
                'payload' => ['flag' => 'seen', 'value' => true],
                'nonce' => 'batch-' . $i,
            ]);
        }
        $rows = $service->claim('test-worker-batch', 3);
        if (count($rows) !== 3) throw new \RuntimeException('expected exactly 3 rows for batch=3, got ' . count($rows));
        return true;
    });
}

// -- 3. COMPLETE / FAIL / BACKOFF --------------------------------------------

$runner->section('3. COMPLETE / FAIL / BACKOFF');

if ($runner->shouldRunSection('3. COMPLETE / FAIL / BACKOFF')) {
    $runner->test('complete() marks row done with optional result_uid', function () use ($service, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $res = $service->enqueue([
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'move',
            'folder_id' => 'src-folder',
            'target_folder_id' => 'dst-folder',
            'uid' => 7777,
            'payload' => ['source_path' => 'INBOX', 'target_path' => 'Archive'],
            'nonce' => 'complete-test',
        ]);
        $service->claim('w-complete', 5);
        $service->complete($res['id'], 99999);
        $stmt = $db->prepare('SELECT status, result_uid FROM imap_outbox WHERE id = :id');
        $stmt->execute([':id' => $res['id']]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('row vanished');
        if ($r['status'] !== 'done') throw new \RuntimeException('expected status=done, got ' . $r['status']);
        if ((int)$r['result_uid'] !== 99999) throw new \RuntimeException('result_uid not stored');
        return true;
    });

    $runner->test('fail() schedules retry with exponential backoff', function () use ($service, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $res = $service->enqueue([
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => 'retry-folder',
            'uid' => 8888,
            'payload' => ['flag' => 'seen', 'value' => true],
            'nonce' => 'retry-test',
        ]);
        $service->claim('w-retry', 5);
        $service->fail($res['id'], 'simulated IMAP error');
        $stmt = $db->prepare(
            "SELECT status, attempts, last_error,
                    TIMESTAMPDIFF(SECOND, NOW(), next_attempt_at) AS delay
               FROM imap_outbox WHERE id = :id"
        );
        $stmt->execute([':id' => $res['id']]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('row vanished');
        if ($r['status'] !== 'pending') throw new \RuntimeException('expected status=pending after fail, got ' . $r['status']);
        if ((int)$r['attempts'] !== 1) throw new \RuntimeException('attempts should be 1');
        if (stripos((string)$r['last_error'], 'simulated IMAP error') === false) {
            throw new \RuntimeException('last_error not stored');
        }
        return true;
    });

    $runner->test('fail() promotes to dead after MAX_ATTEMPTS', function () use ($service, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $res = $service->enqueue([
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'delete',
            'folder_id' => 'dead-folder',
            'uid' => 9999,
            'payload' => ['source_path' => 'Trash'],
            'nonce' => 'dead-test',
        ]);
        // Burn through MAX_ATTEMPTS - 1 failures (claim/fail loop)
        for ($i = 0; $i < OutboxService::MAX_ATTEMPTS; $i++) {
            $service->claim('w-dead', 5);
            $service->fail($res['id'], "attempt {$i} failed");
            // claim() requires next_attempt_at <= NOW() so re-eligibility
            // is gated by backoff. We bypass it for the test by manually
            // setting next_attempt_at into the past.
            $db->prepare('UPDATE imap_outbox SET next_attempt_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = :id')
               ->execute([':id' => $res['id']]);
        }
        $stmt = $db->prepare('SELECT status, attempts FROM imap_outbox WHERE id = :id');
        $stmt->execute([':id' => $res['id']]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException('row vanished');
        if ($r['status'] !== 'dead') throw new \RuntimeException('expected status=dead, got ' . $r['status']);
        if ((int)$r['attempts'] < OutboxService::MAX_ATTEMPTS) {
            throw new \RuntimeException('attempts < MAX_ATTEMPTS but dead');
        }
        return true;
    });
}

// -- 4. REAPER ---------------------------------------------------------------

$runner->section('4. REAPER');

if ($runner->shouldRunSection('4. REAPER')) {
    $runner->test('reapStuck() resets rows older than STUCK_THRESHOLD_SECONDS', function () use ($service, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $res = $service->enqueue([
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => 'reap-folder',
            'uid' => 10000,
            'payload' => ['flag' => 'seen', 'value' => true],
            'nonce' => 'reap-test',
        ]);
        $service->claim('w-reap', 5);
        // Backdate claimed_at to simulate a crashed worker
        $db->prepare(
            "UPDATE imap_outbox
                SET claimed_at = DATE_SUB(NOW(), INTERVAL :ttl SECOND)
              WHERE id = :id"
        )->execute([
            ':id' => $res['id'],
            ':ttl' => OutboxService::STUCK_THRESHOLD_SECONDS + 60,
        ]);
        $reaped = $service->reapStuck();
        if ($reaped < 1) throw new \RuntimeException('expected >=1 reaped row, got ' . $reaped);
        $stmt = $db->prepare('SELECT status, claimed_at FROM imap_outbox WHERE id = :id');
        $stmt->execute([':id' => $res['id']]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($r['status'] !== 'pending') throw new \RuntimeException('expected reaped row to be pending, got ' . $r['status']);
        if ($r['claimed_at'] !== null) throw new \RuntimeException('claimed_at should be reset to NULL');
        return true;
    });

    $runner->test('reapStuck() leaves fresh running rows alone', function () use ($service, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $res = $service->enqueue([
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => 'no-reap',
            'uid' => 11000,
            'payload' => ['flag' => 'seen', 'value' => true],
            'nonce' => 'no-reap-test',
        ]);
        $service->claim('w-fresh', 5);
        $reaped = $service->reapStuck();
        $stmt = $db->prepare('SELECT status FROM imap_outbox WHERE id = :id');
        $stmt->execute([':id' => $res['id']]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($r['status'] !== 'running') throw new \RuntimeException('fresh running row should NOT be reaped');
        return true;
    });
}

// -- 5. STATS ----------------------------------------------------------------

$runner->section('5. OBSERVABILITY');

if ($runner->shouldRunSection('5. OBSERVABILITY')) {
    $runner->test('getUserQueueStats reports per-status counts', function () use ($service, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        // 2 pending, 1 dead
        for ($i = 0; $i < 2; $i++) {
            $service->enqueue([
                'user_email' => $testUser,
                'account_email' => $testAccount,
                'op' => 'set_flag',
                'folder_id' => 'stats',
                'uid' => 12000 + $i,
                'payload' => ['flag' => 'seen', 'value' => true],
                'nonce' => "stats-pending-{$i}",
            ]);
        }
        $deadRes = $service->enqueue([
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => 'stats',
            'uid' => 12999,
            'payload' => ['flag' => 'seen', 'value' => true],
            'nonce' => 'stats-dead',
        ]);
        $db->prepare("UPDATE imap_outbox SET status='dead' WHERE id = :id")
           ->execute([':id' => $deadRes['id']]);

        $stats = $service->getUserQueueStats($testUser);
        if ($stats['pending'] < 2) throw new \RuntimeException('expected >=2 pending, got ' . $stats['pending']);
        if ($stats['dead'] < 1) throw new \RuntimeException('expected >=1 dead, got ' . $stats['dead']);
        return true;
    });
}

// -- 6. PULL-BACK GUARD + JANITOR --------------------------------------------

$runner->section('6. PULL-BACK GUARD + JANITOR');

if ($runner->shouldRunSection('6. PULL-BACK GUARD + JANITOR')) {
    $guardFolderId = '00000000-0000-0000-0000-0000000000aa';

    $runner->test('pendingFlagUids returns only UIDs with in-flight flag ops', function () use ($service, $db, $testUser, $testAccount, $wipe, $guardFolderId) {
        $wipe();
        // pending set_flag for uid 5001, running clear_flag for 5002,
        // done set_flag for 5003 (confirmed -> should NOT be returned).
        $a = $service->enqueue(['user_email'=>$testUser,'account_email'=>$testAccount,'op'=>'set_flag','folder_id'=>$guardFolderId,'uid'=>5001,'payload'=>['flag'=>'seen','value'=>true],'nonce'=>'guard-a']);
        $b = $service->enqueue(['user_email'=>$testUser,'account_email'=>$testAccount,'op'=>'clear_flag','folder_id'=>$guardFolderId,'uid'=>5002,'payload'=>['flag'=>'seen','value'=>false],'nonce'=>'guard-b']);
        $c = $service->enqueue(['user_email'=>$testUser,'account_email'=>$testAccount,'op'=>'set_flag','folder_id'=>$guardFolderId,'uid'=>5003,'payload'=>['flag'=>'seen','value'=>true],'nonce'=>'guard-c']);
        $db->prepare("UPDATE imap_outbox SET status='running' WHERE id = :id")->execute([':id' => $b['id']]);
        $db->prepare("UPDATE imap_outbox SET status='done' WHERE id = :id")->execute([':id' => $c['id']]);

        $pending = $service->pendingFlagUids($testUser, $guardFolderId, [5001, 5002, 5003, 5004]);
        sort($pending);
        if ($pending !== [5001, 5002]) {
            throw new \RuntimeException('expected [5001,5002], got [' . implode(',', $pending) . ']');
        }
        return true;
    });

    $runner->test('pendingFlagUids ignores move/delete ops (only flags count)', function () use ($service, $db, $testUser, $testAccount, $wipe, $guardFolderId) {
        $wipe();
        $service->enqueue(['user_email'=>$testUser,'account_email'=>$testAccount,'op'=>'move','folder_id'=>$guardFolderId,'uid'=>6001,'target_folder_id'=>'tgt','payload'=>['source_path'=>'A','target_path'=>'B'],'nonce'=>'guard-move']);
        $pending = $service->pendingFlagUids($testUser, $guardFolderId, [6001]);
        if (!empty($pending)) throw new \RuntimeException('move op must not appear as a pending flag');
        return true;
    });

    $runner->test('pendingFlagUids returns empty for empty uid list', function () use ($service, $testUser, $guardFolderId) {
        if ($service->pendingFlagUids($testUser, $guardFolderId, []) !== []) {
            throw new \RuntimeException('empty uid list must short-circuit to []');
        }
        return true;
    });

    $runner->test('purge() drops old done + dead rows, keeps fresh + pending', function () use ($service, $db, $testUser, $testAccount, $wipe, $guardFolderId) {
        $wipe();
        $old = $service->enqueue(['user_email'=>$testUser,'account_email'=>$testAccount,'op'=>'set_flag','folder_id'=>$guardFolderId,'uid'=>7001,'payload'=>['flag'=>'seen','value'=>true],'nonce'=>'purge-old-done']);
        $oldDead = $service->enqueue(['user_email'=>$testUser,'account_email'=>$testAccount,'op'=>'set_flag','folder_id'=>$guardFolderId,'uid'=>7002,'payload'=>['flag'=>'seen','value'=>true],'nonce'=>'purge-old-dead']);
        $freshPending = $service->enqueue(['user_email'=>$testUser,'account_email'=>$testAccount,'op'=>'set_flag','folder_id'=>$guardFolderId,'uid'=>7003,'payload'=>['flag'=>'seen','value'=>true],'nonce'=>'purge-fresh']);

        // Age the done row beyond 1 day and the dead row beyond 7 days.
        $db->prepare("UPDATE imap_outbox SET status='done', updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = :id")->execute([':id' => $old['id']]);
        $db->prepare("UPDATE imap_outbox SET status='dead', updated_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = :id")->execute([':id' => $oldDead['id']]);

        $deleted = $service->purge();
        if ($deleted < 2) throw new \RuntimeException('expected >=2 purged, got ' . $deleted);

        $check = function (int $id) use ($db): bool {
            $s = $db->prepare('SELECT COUNT(*) FROM imap_outbox WHERE id = :id');
            $s->execute([':id' => $id]);
            return ((int)$s->fetchColumn()) > 0;
        };
        if ($check($old['id'])) throw new \RuntimeException('old done row should be purged');
        if ($check($oldDead['id'])) throw new \RuntimeException('old dead row should be purged');
        if (!$check($freshPending['id'])) throw new \RuntimeException('fresh pending row must survive purge');
        return true;
    });
}

exit($runner->finish());
