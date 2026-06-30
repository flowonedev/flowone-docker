#!/usr/bin/env php
<?php
/**
 * SiteLock Test Suite
 *
 * Verifies:
 *   - acquire() succeeds when no lock exists, returns SiteLockHandle.
 *   - tryAcquire() returns null when a live holder already owns the lock.
 *   - acquire() throws LockAcquisitionFailed under contention.
 *   - heartbeat() extends the lease; throws if we lost ownership.
 *   - release() removes the row only if we own it.
 *   - sweepExpired() removes expired rows, leaves live ones alone.
 *   - Concurrent acquire (forked children) can never both win.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/site-lock-test.php --verbose
 *
 * Options:
 *   --verbose          Show extra debug info
 *   --skip-send        n/a
 *   --only=GROUP       preflight,basics,contention,heartbeat,expiry
 *   --smoke            connectivity + schema check only
 *   --json             JSON output
 *   --help             Show this help
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Exceptions\LockAcquisitionFailed;
use VpsAdmin\Agent\Provisioner\Services\SiteLock;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SiteLock', $opts);

$db = null;
$pdo = null;
$lock = null;
$testDomains = [];

$harness->onCleanup(function () use (&$pdo, &$testDomains): void {
    if (!$pdo || !$testDomains) {
        return;
    }
    $in = implode(',', array_fill(0, count($testDomains), '?'));
    $pdo->prepare("DELETE FROM site_locks WHERE domain IN ({$in})")->execute($testDomains);
});

// ── preflight ─────────────────────────────────────────────────
$harness->test('preflight', 'PanelDatabase + site_locks table', function () use (&$db, &$pdo, &$lock) {
    $db = PanelDatabase::fromDefaultConfigFiles();
    $pdo = $db->pdo();
    $check = $pdo->query("SHOW TABLES LIKE 'site_locks'");
    if ($check->rowCount() === 0) {
        return [
            'outcome' => TestHarness::FAIL,
            'message' => 'site_locks table missing. Run migrate_site_locks.sql.',
        ];
    }
    $lock = new SiteLock($db);
});

// ── basics ───────────────────────────────────────────────────
$harness->test('basics', 'acquire on fresh domain succeeds', function () use (&$lock, &$testDomains) {
    $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
    $testDomains[] = $domain;
    $handle = $lock->acquire($domain, 'worker-1', 'unit test');
    if ($handle->isReleased()) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'handle reports already released'];
    }
    $handle->release();
    if (!$handle->isReleased()) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'release() did not set released flag'];
    }
});

$harness->test('basics', 'inspect returns null when unlocked', function () use (&$lock, &$testDomains) {
    $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
    $testDomains[] = $domain;
    if ($lock->inspect($domain) !== null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected null for never-locked domain'];
    }
});

$harness->test('basics', 'inspect returns the holder for a live lock',
    function () use (&$lock, &$testDomains) {
        $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $handle = $lock->acquire($domain, 'worker-A', 'inspect test');
        try {
            $info = $lock->inspect($domain);
            if (!$info || $info['holder_id'] !== 'worker-A') {
                return ['outcome' => TestHarness::FAIL, 'message' => 'inspect returned wrong holder'];
            }
        } finally {
            $handle->release();
        }
    });

// ── contention ───────────────────────────────────────────────
$harness->test('contention', 'tryAcquire returns null when another holds it',
    function () use (&$lock, &$testDomains) {
        $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $h1 = $lock->acquire($domain, 'worker-A', 'first');
        try {
            $h2 = $lock->tryAcquire($domain, 'worker-B', 'second');
            if ($h2 !== null) {
                $h2->release();
                return ['outcome' => TestHarness::FAIL, 'message' => 'tryAcquire returned non-null while held'];
            }
        } finally {
            $h1->release();
        }
    });

$harness->test('contention', 'acquire throws LockAcquisitionFailed when another holds it',
    function () use (&$lock, &$testDomains) {
        $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $h1 = $lock->acquire($domain, 'worker-A', 'first');
        try {
            $lock->acquire($domain, 'worker-B', 'second');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected LockAcquisitionFailed'];
        } catch (LockAcquisitionFailed $e) {
            if ($e->heldBy !== 'worker-A') {
                return ['outcome' => TestHarness::FAIL, 'message' => 'wrong heldBy in exception: ' . $e->heldBy];
            }
        } finally {
            $h1->release();
        }
    });

$harness->test('contention', 'same holder can re-acquire (refresh)',
    function () use (&$lock, &$testDomains) {
        $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $h1 = $lock->acquire($domain, 'worker-A', 'first');
        $h2 = $lock->tryAcquire($domain, 'worker-A', 'refresh');
        if ($h2 === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'same holder should be able to refresh'];
        }
        $h1->release();
        $h2->release();
    });

// ── heartbeat ────────────────────────────────────────────────
$harness->test('heartbeat', 'heartbeat extends lease_until',
    function () use (&$lock, &$pdo, &$testDomains) {
        $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $handle = $lock->acquire($domain, 'worker-A', 'hb test', null, 5);
        $row = $pdo->prepare('SELECT lease_until FROM site_locks WHERE domain = ?');
        $row->execute([$domain]);
        $before = $row->fetchColumn();
        try {
            sleep(1);
            $handle->heartbeat(30);
            $row->execute([$domain]);
            $after = $row->fetchColumn();
            if (strcmp((string) $after, (string) $before) <= 0) {
                return [
                    'outcome' => TestHarness::FAIL,
                    'message' => "lease did not extend: before={$before}, after={$after}",
                ];
            }
        } finally {
            $handle->release();
        }
    });

$harness->test('heartbeat', 'heartbeat after foreign takeover throws',
    function () use (&$lock, &$pdo, &$testDomains) {
        $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $h1 = $lock->acquire($domain, 'worker-A', 'first', null, 60);
        // Forcibly hand the lock to worker-B in the DB
        $pdo->prepare("UPDATE site_locks SET holder_id='worker-B' WHERE domain=?")
            ->execute([$domain]);
        try {
            $h1->heartbeat();
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected LockAcquisitionFailed'];
        } catch (LockAcquisitionFailed) {
            // ok
        }
        $pdo->prepare("DELETE FROM site_locks WHERE domain=?")->execute([$domain]);
    });

// ── expiry ───────────────────────────────────────────────────
$harness->test('expiry', 'expired lock can be re-acquired by another holder',
    function () use (&$lock, &$pdo, &$testDomains) {
        $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        // Insert an already-expired lock directly.
        $pdo->prepare(
            "INSERT INTO site_locks (domain, holder_id, purpose, acquired_at, lease_until)
              VALUES (?, 'worker-dead', 'leftover', NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 30 MINUTE)"
        )->execute([$domain]);
        $handle = $lock->tryAcquire($domain, 'worker-fresh', 'after expiry');
        if ($handle === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'could not take over expired lock'];
        }
        $handle->release();
    });

$harness->test('expiry', 'sweepExpired removes only expired rows',
    function () use (&$lock, &$pdo, &$testDomains) {
        $expired = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $live    = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $expired;
        $testDomains[] = $live;
        $pdo->prepare(
            "INSERT INTO site_locks (domain, holder_id, purpose, acquired_at, lease_until)
              VALUES (?, 'expired', 'x', NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 30 MINUTE),
                     (?, 'live',    'y', NOW(),                  NOW() + INTERVAL 30 MINUTE)"
        )->execute([$expired, $live]);

        $removed = $lock->sweepExpired();
        if ($removed < 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected sweep to remove >=1 row'];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM site_locks WHERE domain IN (?, ?)");
        $stmt->execute([$expired, $live]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'live lock was also removed'];
        }
    });

// ── concurrency: fork two children and prove at most one wins ────
//
// fork() duplicates file descriptors, so parent and child share the same
// underlying MySQL socket. Two booby-traps to disarm:
//   1. If the child calls $db->forgetConnection() while the parent is
//      still using its PDO, PHP's GC runs the PDO destructor in the
//      child as soon as the property is nulled. The destructor sends
//      COM_QUIT down the SHARED socket, the server hangs up, and the
//      parent's next query fails with "MySQL has gone away".
//   2. If the child calls a normal exit() / posix_exit() that releases
//      script state, the destructor on the inherited PDO runs as part
//      of shutdown -> same poisoning of the parent's socket.
//
// Fixes:
//   - Child uses posix_kill(getpid(), SIGKILL) after writing its result
//     to the pipe. SIGKILL skips PHP shutdown handlers entirely.
//   - Parent does NOT trust its cached PDO after the fork loop and
//     calls $db->forgetConnection() so the next test gets a fresh
//     connection.
$harness->test('contention', 'forked concurrent acquire: exactly one wins',
    function () use (&$lock, &$db, &$pdo, &$testDomains) {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'pcntl/posix unavailable'];
        }
        $domain = '[flowone_test_]lock-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;

        $pipes = [];
        $pids = [];
        $childCount = 4;
        for ($i = 0; $i < $childCount; $i++) {
            $pipes[$i] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Child: build an independent PanelDatabase. We deliberately
                // do NOT touch $db (the parent's). PDO instances inherited
                // through fork share an underlying TCP socket; touching them
                // here would corrupt the parent's connection on child exit.
                $childDb = \VpsAdmin\Agent\Provisioner\Support\PanelDatabase::fromDefaultConfigFiles();
                $childLock = new SiteLock($childDb);
                fclose($pipes[$i][0]);
                $write = $pipes[$i][1];
                try {
                    $h = $childLock->acquire($domain, "worker-{$i}", 'race', null, 30);
                    fwrite($write, "WIN");
                    sleep(1); // hold long enough to lose the race for others
                    $h->release();
                } catch (LockAcquisitionFailed) {
                    fwrite($write, "LOSE");
                } catch (\Throwable $e) {
                    fwrite($write, "ERR:" . substr($e->getMessage(), 0, 80));
                }
                fflush($write);
                fclose($write);
                // SIGKILL ourselves so destructors never run. This is the
                // only safe way to exit a child without poisoning the
                // parent's inherited socket FDs.
                posix_kill(posix_getpid(), SIGKILL);
            }
            fclose($pipes[$i][1]);
            $pids[$i] = $pid;
        }

        $wins = 0;
        $errors = [];
        foreach ($pipes as $i => $pair) {
            $read = $pair[0];
            $msg = stream_get_contents($read);
            fclose($read);
            if ($msg === 'WIN') {
                $wins++;
            } elseif (str_starts_with((string) $msg, 'ERR:')) {
                $errors[] = "child {$i}: {$msg}";
            }
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Even with SIGKILL'd children, fork itself may have nudged the
        // socket state. Force the parent to reconnect on next use so
        // downstream tests in this suite are not blamed for our mess.
        // We also refresh $pdo here because tests captured it by reference
        // at preflight time and would otherwise keep the stale handle.
        $db->forgetConnection();
        $pdo = $db->pdo();

        if ($errors) {
            return ['outcome' => TestHarness::FAIL, 'message' => implode(' | ', $errors)];
        }
        if ($wins !== 1) {
            return [
                'outcome' => TestHarness::FAIL,
                'message' => "expected exactly 1 winner, got {$wins}",
            ];
        }
    });

exit($harness->run());
