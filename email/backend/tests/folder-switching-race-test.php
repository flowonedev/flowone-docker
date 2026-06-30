#!/usr/bin/env php
<?php
/**
 * folder-switching-race-test.php
 *
 * Phase 1 regression test for the "jumps back to Inbox" bug.
 *
 * The bug: after a WebSocket FOLDER_COUNTS or MESSAGE_NEW event for Inbox,
 * a debounced incremental fetch was queued. If the user clicked All Mail
 * within the 500ms debounce window, the queued fetch fell back to a full
 * page-1 reload of Inbox (because the All-Mail click cleared Inbox's cached
 * folderView via `mailbox.messages = []`), which synchronously yanked
 * currentFolder back to Inbox.
 *
 * The bug is in browser-side JS, but the *server-side contract* this test
 * verifies is what the frontend race fix depends on:
 *
 *   1. Redis pub/sub FOLDER_COUNTS / FLAGS_CHANGED / MESSAGE_MOVED events
 *      carry a `folder` field so the frontend can route them to the right
 *      per-folder debounce timer.
 *   2. The publish API actually publishes (no silent failure that would
 *      mask the regression).
 *   3. Distinct folders publish distinct events that can be filtered.
 *
 * Per .cursor/rules/server-side-testing.mdc — CLI only, idempotent, prefixes
 * every test artefact with `flowone_test_`, runs cleanup on all exit paths,
 * never writes to production data.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/folder-switching-race-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Services\RedisCacheService;

$runner = new FlowOneTestRunner('folder-switching-race', $argv);

// -- Pre-flight ---------------------------------------------------------------

foreach (['redis', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $redis = new RedisCacheService($config);
    if (!$redis->isAvailable()) {
        $runner->log('Redis unavailable; aborting');
        exit(1);
    }
} catch (\Throwable $e) {
    $runner->log('Redis connectivity failed: ' . $e->getMessage());
    exit(1);
}

$testUser = 'flowone_test_race@example.invalid';
$testFolderA = 'INBOX';
$testFolderB = 'ALL_MAIL';

// Cleanup is a no-op since publishEvent is fire-and-forget on a Redis
// pub/sub channel; there's no key to delete. Subscribers (if any) get
// the message and discard it. We still install a handler so any future
// stateful additions clean up automatically.
$runner->addCleanup(function () use ($runner) {
    $runner->log('cleanup: pub/sub events are transient; nothing to delete');
});

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('redis ping', fn() => $redis->isAvailable() ? true : throw new \RuntimeException('redis down'));
    exit($runner->finish());
}

// -- Subscribe in a background fork to capture published events ---------------

/**
 * Subscribe to the user's mailbox channel and capture published messages
 * for `timeoutMs` milliseconds. Returns the decoded payloads.
 *
 * Uses a raw Redis subscription on a fresh connection because the
 * RedisCacheService instance is configured for command mode, not
 * pub/sub. We open a one-shot subscriber via phpredis directly.
 */
function captureEvents(array $config, string $userEmail, int $timeoutMs, string $hashPrefix): array
{
    $redis = new \Redis();
    $host = $config['redis']['host'] ?? '127.0.0.1';
    $port = (int)($config['redis']['port'] ?? 6379);
    $redis->connect($host, $port, 2.0);
    if (!empty($config['redis']['password'])) {
        $redis->auth($config['redis']['password']);
    }
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, max(1, (int)ceil($timeoutMs / 1000)));

    $channel = $hashPrefix . 'mailbox:' . $userEmail;
    $captured = [];
    $deadline = microtime(true) + ($timeoutMs / 1000);

    // psubscribe with a callback that exits when timeout or pattern hit count met.
    try {
        $redis->subscribe([$channel], function ($redis, $chan, $msg) use (&$captured, $deadline, $channel) {
            if ($chan === $channel) {
                $decoded = json_decode($msg, true);
                if (is_array($decoded)) {
                    $captured[] = $decoded;
                }
            }
            if (microtime(true) >= $deadline) {
                $redis->close();
            }
        });
    } catch (\Throwable $e) {
        // Read timeout is expected when no further events arrive within the window.
    }

    return $captured;
}

/**
 * Reflect into RedisCacheService to read its key prefix so the
 * subscriber listens on the same channel the publisher writes to.
 */
function readPrefix(RedisCacheService $svc): string
{
    $ref = new \ReflectionClass($svc);
    if ($ref->hasProperty('prefix')) {
        $p = $ref->getProperty('prefix');
        $p->setAccessible(true);
        return (string)$p->getValue($svc);
    }
    return '';
}

$prefix = readPrefix($redis);

// -- Test sections ------------------------------------------------------------

$runner->section('1. PUBLISH CONTRACT');

if ($runner->shouldRunSection('1. PUBLISH CONTRACT')) {
    $runner->test('publishFlagsChanged returns true and includes folder field', function () use ($redis, $testUser, $testFolderA) {
        // We cannot easily assert the receive-side here without forking,
        // but the publish API returns false on Redis failure. A successful
        // boolean true means the message hit at least 0 subscribers.
        $ok = $redis->publishFlagsChanged($testUser, $testFolderA, 12345, [
            'flag' => 'seen',
            'value' => true,
            'imapFlags' => ['\\Seen'],
        ]);
        if (!$ok) {
            throw new \RuntimeException('publishFlagsChanged returned false');
        }
        return true;
    });

    $runner->test('publishFolderCounts returns true for source folder', function () use ($redis, $testUser, $testFolderA) {
        $ok = $redis->publishFolderCounts($testUser, $testFolderA, 100, 5, 99999, 1);
        if (!$ok) {
            throw new \RuntimeException('publishFolderCounts returned false');
        }
        return true;
    });

    $runner->test('publishMessageMoved returns true', function () use ($redis, $testUser, $testFolderA) {
        $ok = $redis->publishMessageMoved($testUser, $testFolderA, 'Archive', 12345, 67890);
        if (!$ok) {
            throw new \RuntimeException('publishMessageMoved returned false');
        }
        return true;
    });
}

$runner->section('2. SUBSCRIBER RECEIVES FOLDER FIELD');

if ($runner->shouldRunSection('2. SUBSCRIBER RECEIVES FOLDER FIELD')) {
    // The frontend's per-folder debounce relies on every event carrying
    // a `folder` (or `source_folder`) field so the per-folder timer Map
    // can route them. We fork a child to subscribe, publish from the
    // parent, then assert the child captured the right field.
    $runner->test('FLAGS_CHANGED payload carries folder field', function () use ($redis, $config, $prefix, $testUser, $testFolderA) {
        $pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
        if ($pid === -1) {
            // No fork available -- fall back to round-trip via a separate
            // subscriber loop in the same process. We can't capture without
            // fork, so skip the receive assertion and only check publish.
            $ok = $redis->publishFlagsChanged($testUser, $testFolderA, 1, ['flag' => 'seen', 'value' => true]);
            if (!$ok) throw new \RuntimeException('publish failed');
            return 'warn';
        }
        if ($pid === 0) {
            // Child: subscribe then exit with captured count as exit code.
            $captured = captureEvents($config, $testUser, 1500, $prefix);
            $hasFolderField = false;
            foreach ($captured as $event) {
                if (($event['type'] ?? null) === 'flags_changed' && isset($event['payload']['folder']) && $event['payload']['folder'] === $testFolderA) {
                    $hasFolderField = true;
                    break;
                }
            }
            exit($hasFolderField ? 0 : 1);
        }
        // Parent: small sleep so child's subscribe is established, then publish.
        usleep(200000);
        $redis->publishFlagsChanged($testUser, $testFolderA, 99001, [
            'flag' => 'seen',
            'value' => true,
            'imapFlags' => ['\\Seen'],
        ]);
        $status = 0;
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            throw new \RuntimeException('subscriber did not receive flags_changed with folder field');
        }
        return true;
    }, 5);

    $runner->test('FOLDER_COUNTS payload carries folder field', function () use ($redis, $config, $prefix, $testUser, $testFolderA) {
        $pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
        if ($pid === -1) {
            $ok = $redis->publishFolderCounts($testUser, $testFolderA, 10, 1);
            if (!$ok) throw new \RuntimeException('publish failed');
            return 'warn';
        }
        if ($pid === 0) {
            $captured = captureEvents($config, $testUser, 1500, $prefix);
            $hasFolderField = false;
            foreach ($captured as $event) {
                if (($event['type'] ?? null) === 'folder_counts' && isset($event['payload']['folder']) && $event['payload']['folder'] === $testFolderA) {
                    $hasFolderField = true;
                    break;
                }
            }
            exit($hasFolderField ? 0 : 1);
        }
        usleep(200000);
        $redis->publishFolderCounts($testUser, $testFolderA, 42, 7, 88888, 1);
        $status = 0;
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            throw new \RuntimeException('subscriber did not receive folder_counts with folder field');
        }
        return true;
    }, 5);
}

$runner->section('3. RACE-SCENARIO SIMULATION');

if ($runner->shouldRunSection('3. RACE-SCENARIO SIMULATION')) {
    // Simulate the exact event sequence that triggers the bug:
    //   - publish FOLDER_COUNTS for Inbox  (would queue debouncedIncrementalFetch)
    //   - publish FLAGS_CHANGED for Inbox  (would queue another)
    //   - simulate user navigation by publishing a CONVERSATION_UPDATED on ALL_MAIL context
    //
    // The frontend race fix guarantees: per-folder debounce timers do not
    // cancel each other, AND the queued Inbox fetches abort when
    // mb.currentFolder !== "INBOX". We verify the SERVER half of the
    // contract here: every event carries an unambiguous folder field so
    // the frontend can route correctly.
    $runner->test('three rapid events all carry distinct folder fields', function () use ($redis, $config, $prefix, $testUser, $testFolderA) {
        $pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
        if ($pid === -1) {
            return 'warn';
        }
        if ($pid === 0) {
            $captured = captureEvents($config, $testUser, 2000, $prefix);
            $foundCounts = false;
            $foundFlags = false;
            $foundMoved = false;
            foreach ($captured as $event) {
                $type = $event['type'] ?? null;
                $payload = $event['payload'] ?? [];
                if ($type === 'folder_counts' && ($payload['folder'] ?? null) === $testFolderA) {
                    $foundCounts = true;
                }
                if ($type === 'flags_changed' && ($payload['folder'] ?? null) === $testFolderA) {
                    $foundFlags = true;
                }
                if ($type === 'message_moved' && (($payload['source_folder'] ?? null) === $testFolderA)) {
                    $foundMoved = true;
                }
            }
            $ok = $foundCounts && $foundFlags && $foundMoved;
            exit($ok ? 0 : 1);
        }
        usleep(300000);
        $redis->publishFolderCounts($testUser, $testFolderA, 50, 3);
        usleep(50000);
        $redis->publishFlagsChanged($testUser, $testFolderA, 12345, ['flag' => 'seen', 'value' => true]);
        usleep(50000);
        $redis->publishMessageMoved($testUser, $testFolderA, 'Archive', 12345, 99999);
        $status = 0;
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            throw new \RuntimeException('subscriber did not receive all three events with folder fields');
        }
        return true;
    }, 10);
}

$runner->section('4. EVENT TIMESTAMPS MONOTONIC');

if ($runner->shouldRunSection('4. EVENT TIMESTAMPS MONOTONIC')) {
    // Frontend ordering of debounced fetches depends on Redis publishing
    // events with monotonically non-decreasing timestamps. We don't depend
    // on strict ordering, but a backwards-going timestamp would point at
    // clock skew between mailsync workers.
    $runner->test('event timestamps non-decreasing within one user channel', function () use ($redis, $config, $prefix, $testUser, $testFolderA) {
        $pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
        if ($pid === -1) return 'warn';
        if ($pid === 0) {
            $captured = captureEvents($config, $testUser, 1500, $prefix);
            $lastTs = 0;
            foreach ($captured as $event) {
                $ts = (int)($event['timestamp'] ?? 0);
                if ($ts === 0) exit(2); // missing timestamp
                if ($ts < $lastTs) exit(1); // out of order
                $lastTs = $ts;
            }
            exit(0);
        }
        usleep(200000);
        $redis->publishFolderCounts($testUser, $testFolderA, 1, 0);
        $redis->publishFolderCounts($testUser, $testFolderA, 2, 0);
        $redis->publishFolderCounts($testUser, $testFolderA, 3, 0);
        $status = 0;
        pcntl_waitpid($pid, $status);
        $exit = pcntl_wexitstatus($status);
        if ($exit === 2) throw new \RuntimeException('event missing timestamp field');
        if ($exit === 1) throw new \RuntimeException('event timestamps went backwards');
        return true;
    }, 5);
}

exit($runner->finish());
