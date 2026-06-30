#!/usr/bin/env php
<?php
/**
 * idempotency-test.php
 *
 * Phase 2 test: every outbox row carries a deterministic idempotency
 * key so a worker retry after a crash never double-applies an IMAP
 * operation. This test exercises the IdempotencyService key derivation
 * + the outbox UNIQUE constraint that turns duplicate enqueue calls
 * into no-ops.
 *
 * Worth keeping separate from outbox-pump-test because the failure mode
 * for idempotency drift is silent: nothing crashes, you just get two
 * IMAP writes when you expected one. The targeted tests here catch
 * subtle regressions (e.g. a future refactor accidentally including
 * microtime in the key).
 *
 * Per .cursor/rules/server-side-testing.mdc -- CLI only, idempotent,
 * test rows prefixed `flowone_test_`, cleanup on every exit path.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/idempotency-test.php --verbose
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

$runner = new FlowOneTestRunner('idempotency', $argv);

foreach (['pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';
$db = Database::getConnection($config);

try {
    $db->query('SELECT 1 FROM imap_outbox LIMIT 1');
} catch (\Throwable $e) {
    $runner->log('imap_outbox missing -- run migration 178 first');
    exit(1);
}

$idem = new IdempotencyService();
$svc = new OutboxService($config);
$svc->setDb($db);

$testUser = 'flowone_test_idem@example.invalid';
$testAccount = 'flowone_test_idem@example.invalid';

$wipe = function () use ($db, $testUser): int {
    $stmt = $db->prepare('DELETE FROM imap_outbox WHERE user_email = :u');
    $stmt->execute([':u' => $testUser]);
    return $stmt->rowCount();
};
$initial = $wipe();
$runner->log("pre-test cleanup removed {$initial} rows");
$runner->addCleanup(function () use ($wipe, $runner) {
    $n = $wipe();
    $runner->log("post-test cleanup removed {$n} rows");
});

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('idempotency key is 64 hex chars (sha256)', function () use ($idem, $testUser, $testAccount) {
        $key = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 1);
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) throw new \RuntimeException('bad key shape: ' . $key);
        return true;
    });
    exit($runner->finish());
}

// -- 1. KEY DETERMINISM -------------------------------------------------------

$runner->section('1. KEY DETERMINISM');

if ($runner->shouldRunSection('1. KEY DETERMINISM')) {
    $runner->test('same input -> same key (across instances)', function () use ($idem, $testUser, $testAccount) {
        $a = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 100, null, 'fixed-nonce');
        $b = (new IdempotencyService())->computeKey($testUser, $testAccount, 'set_flag', 'fid', 100, null, 'fixed-nonce');
        if ($a !== $b) throw new \RuntimeException('key not deterministic');
        return true;
    });

    $runner->test('case-insensitive user/account email', function () use ($idem) {
        $a = $idem->computeKey('Test@Example.com', 'Test@Example.com', 'set_flag', 'fid', 1, null, 'n');
        $b = $idem->computeKey('test@example.com', 'test@example.com', 'set_flag', 'fid', 1, null, 'n');
        if ($a !== $b) throw new \RuntimeException('email case must not change key');
        return true;
    });

    $runner->test('per-day default nonce: same day -> same key', function () use ($idem, $testUser, $testAccount) {
        $a = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 1);
        $b = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 1);
        if ($a !== $b) throw new \RuntimeException('per-day default should collapse same-day calls');
        return true;
    });

    $runner->test('explicit nonce diverges from default per-day nonce', function () use ($idem, $testUser, $testAccount) {
        $a = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 1);
        $b = $idem->computeKey($testUser, $testAccount, 'set_flag', 'fid', 1, null, 'override');
        if ($a === $b) throw new \RuntimeException('explicit nonce must diverge from default');
        return true;
    });

    $runner->test('generateNonce produces unique values', function () use ($idem) {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $n = $idem->generateNonce();
            if (isset($seen[$n])) throw new \RuntimeException('nonce collision in 50 generations');
            $seen[$n] = true;
            if (!preg_match('/^[a-f0-9]{32}$/', $n)) throw new \RuntimeException('bad nonce shape: ' . $n);
        }
        return true;
    });

    $runner->test('all key components contribute', function () use ($idem) {
        $base = $idem->computeKey('a@b', 'a@b', 'set_flag', 'fid', 1, 'tgt', 'n');
        $variants = [
            'user'        => $idem->computeKey('x@b', 'a@b', 'set_flag', 'fid', 1, 'tgt', 'n'),
            'account'     => $idem->computeKey('a@b', 'x@b', 'set_flag', 'fid', 1, 'tgt', 'n'),
            'op'          => $idem->computeKey('a@b', 'a@b', 'clear_flag', 'fid', 1, 'tgt', 'n'),
            'folder_id'   => $idem->computeKey('a@b', 'a@b', 'set_flag', 'fid2', 1, 'tgt', 'n'),
            'uid'         => $idem->computeKey('a@b', 'a@b', 'set_flag', 'fid', 2, 'tgt', 'n'),
            'target_folder_id' => $idem->computeKey('a@b', 'a@b', 'set_flag', 'fid', 1, 'tgt2', 'n'),
            'nonce'       => $idem->computeKey('a@b', 'a@b', 'set_flag', 'fid', 1, 'tgt', 'n2'),
        ];
        foreach ($variants as $changedField => $key) {
            if ($key === $base) {
                throw new \RuntimeException("changing {$changedField} did NOT change the key (data loss in derivation)");
            }
        }
        return true;
    });
}

// -- 2. OUTBOX DEDUPLICATION --------------------------------------------------

$runner->section('2. OUTBOX DEDUPLICATION');

if ($runner->shouldRunSection('2. OUTBOX DEDUPLICATION')) {
    $runner->test('two identical enqueues -> one row', function () use ($svc, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $params = [
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => 'dedup-folder',
            'uid' => 12345,
            'payload' => ['flag' => 'seen', 'value' => true],
            'nonce' => 'shared-nonce',
        ];
        $a = $svc->enqueue($params);
        $b = $svc->enqueue($params);
        $stmt = $db->prepare('SELECT COUNT(*) FROM imap_outbox WHERE user_email = :u');
        $stmt->execute([':u' => $testUser]);
        if ((int)$stmt->fetchColumn() !== 1) throw new \RuntimeException('expected exactly 1 row');
        if ($a['id'] !== $b['id']) throw new \RuntimeException('row id should be reused on dedup');
        if (!$a['inserted'] || $b['inserted']) throw new \RuntimeException('first call must be inserted, second must not');
        return true;
    });

    $runner->test('replaying a completed row does not double-enqueue', function () use ($svc, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $params = [
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'move',
            'folder_id' => 'replay-src',
            'target_folder_id' => 'replay-dst',
            'uid' => 22222,
            'payload' => ['source_path' => 'INBOX', 'target_path' => 'Trash'],
            'nonce' => 'replay-nonce',
        ];
        $a = $svc->enqueue($params);
        $svc->claim('w-replay', 5);
        $svc->complete($a['id'], 33333);

        // Worker finished. A retry (crashed process, frontend retry, etc.)
        // comes in with the same idempotency input -> should NOT create
        // a second row that would re-execute the move.
        $b = $svc->enqueue($params);
        $stmt = $db->prepare('SELECT COUNT(*) FROM imap_outbox WHERE user_email = :u');
        $stmt->execute([':u' => $testUser]);
        if ((int)$stmt->fetchColumn() !== 1) throw new \RuntimeException('expected exactly 1 row after replay');
        if ($a['id'] !== $b['id']) throw new \RuntimeException('replay returned a new row id');
        if ($b['inserted']) throw new \RuntimeException('replay must not be marked as inserted');
        return true;
    });

    $runner->test('different uids -> distinct rows (uid is part of the key)', function () use ($svc, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $base = [
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'op' => 'set_flag',
            'folder_id' => 'fan-folder',
            'payload' => ['flag' => 'seen', 'value' => true],
            'nonce' => 'fan-shared',
        ];
        $svc->enqueue($base + ['uid' => 1000]);
        $svc->enqueue($base + ['uid' => 1001]);
        $svc->enqueue($base + ['uid' => 1002]);

        $stmt = $db->prepare('SELECT COUNT(*) FROM imap_outbox WHERE user_email = :u');
        $stmt->execute([':u' => $testUser]);
        if ((int)$stmt->fetchColumn() !== 3) throw new \RuntimeException('expected 3 distinct rows');
        return true;
    });

    $runner->test('opposite ops on same uid -> distinct rows', function () use ($svc, $db, $testUser, $testAccount, $wipe) {
        $wipe();
        $base = [
            'user_email' => $testUser,
            'account_email' => $testAccount,
            'folder_id' => 'opposing',
            'uid' => 7000,
            'payload' => ['flag' => 'seen', 'value' => true],
            'nonce' => 'opposing-shared',
        ];
        $svc->enqueue($base + ['op' => 'set_flag']);
        $svc->enqueue($base + ['op' => 'clear_flag']);
        $stmt = $db->prepare('SELECT COUNT(*) FROM imap_outbox WHERE user_email = :u');
        $stmt->execute([':u' => $testUser]);
        if ((int)$stmt->fetchColumn() !== 2) throw new \RuntimeException('expected 2 rows (set + clear)');
        return true;
    });
}

exit($runner->finish());
