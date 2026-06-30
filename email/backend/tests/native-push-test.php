#!/usr/bin/env php
<?php
/**
 * FlowOne Native Push (FCM) Integration Test.
 *
 * Exercises the full native-push backend chain end-to-end:
 *   - native_push_tokens registration with device dedupe + token rotation
 *   - token reassignment (same token moving to another device/user)
 *   - derived Redis cache fcm_tokens:{email} stays in sync with MySQL
 *   - per-type notification preferences (MySQL row + notif_prefs:{email} map)
 *   - fcm_prune_queue drain (dead-token removal owned by PHP)
 *   - server-side calendar reminder resolution (timezone + recurring), the
 *     same CalendarService::getDueReminders() the cron uses
 *   - stale-token cleanup
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   extensions, autoloader, DB, Redis, tables
 *   tokens      register / rotate / dedupe / reassign / remove + Redis sync
 *   apps        both apps on one device -> distinct rows + app_id in cache
 *   prefs       defaults / update / Redis notif_prefs map
 *   prune       fcm_prune_queue drain removes dead tokens
 *   badge       setBadgeCount stores an INCR-compatible total in Redis
 *   calendar    getDueReminders for non-recurring + recurring occurrences
 *   stale       cleanupStaleNativeTokens removes unseen tokens
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/native-push-test.php --verbose
 *
 * Flags (handled by the shared runner):
 *   --help                 show usage
 *   --verbose              extra debug output
 *   --json                 emit results as JSON
 *   --smoke                preflight only (connectivity/config)
 *   --skip-send            skip the prune-queue drain (touches the shared queue)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --timeout=N            per-test timeout seconds (default 30)
 *
 * All rows use the flowone_test_push@flowone.pro user and flowone_test_ token
 * prefixes; cleanups run even on failure or SIGINT. Idempotent.
 *
 * Exit code: 0 on all PASS/WARN, 1 on any FAIL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Services\PushNotificationService;
use Webmail\Services\RedisCacheService;
use Webmail\Addons\Calendar\Services\CalendarService;

$runner = new FlowOneTestRunner('native-push', $argv);

// Recognizable, non-production test identifiers.
$TEST_EMAIL = 'flowone_test_push@flowone.pro';
$APP_MAIN = 'com.flowone.pro';
$APP_CHAT = 'com.flowone.chat';
$DEV_A = 'flowone_test_dev_a';
$DEV_B = 'flowone_test_dev_b';
$TOK_1 = 'flowone_test_tok_' . bin2hex(random_bytes(8));
$TOK_2 = 'flowone_test_tok_' . bin2hex(random_bytes(8));

// The Redis fcm_tokens cache stores [{token, app_id}] objects (with a legacy
// bare-string fallback). Flatten either shape to a list of token strings.
$cacheTokens = function ($entries): array {
    if (!is_array($entries)) {
        return [];
    }
    $out = [];
    foreach ($entries as $e) {
        if (is_string($e)) {
            $out[] = $e;
        } elseif (is_array($e) && isset($e['token'])) {
            $out[] = $e['token'];
        }
    }
    return $out;
};

$config = null;
$db = null;
$push = null;
$redis = null;
$prefix = 'webmail:';

// --- shared cleanup (runs even on failure / SIGINT) ---
$cleanup = function () use (&$db, &$redis, $TEST_EMAIL, $prefix) {
    try {
        if ($db) {
            $db->prepare("DELETE FROM native_push_tokens WHERE user_email = ? OR token LIKE 'flowone_test_%'")
               ->execute([$TEST_EMAIL]);
            $db->prepare("DELETE FROM notification_preferences WHERE user_email = ?")->execute([$TEST_EMAIL]);
            try { $db->prepare("DELETE FROM calendar_reminder_log WHERE user_email = ?")->execute([$TEST_EMAIL]); } catch (\Throwable $e) {}
            // Test calendars cascade-delete their events.
            $db->prepare("DELETE FROM calendars WHERE user_email = ? AND name LIKE '[FLOWONE-TEST]%'")->execute([$TEST_EMAIL]);
        }
        if ($redis && $redis->isAvailable()) {
            $redis->delete('fcm_tokens:' . strtolower($TEST_EMAIL));
            $redis->delete('notif_prefs:' . strtolower($TEST_EMAIL));
            $redis->delete('badge:' . strtolower($TEST_EMAIL));
        }
    } catch (\Throwable $e) {
        // best-effort
    }
};
$runner->addCleanup($cleanup);

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php extensions loaded (pdo_mysql, redis)', function () use ($runner) {
        foreach (['pdo_mysql', 'redis'] as $ext) {
            $runner->assertTrue(extension_loaded($ext), "{$ext} extension missing");
        }
    });

    $runner->test('cron bootstrap + config load', function () use ($runner, &$config) {
        require_once __DIR__ . '/../cron/bootstrap.php';
        $config = require __DIR__ . '/../src/config.php';
        $runner->assertTrue(is_array($config), 'config.php did not return an array');
    });

    $runner->test('database reachable', function () use ($runner, &$config, &$db) {
        $db = \Webmail\Core\Database::getConnection($config);
        $runner->assertEquals('1', (string)$db->query('SELECT 1')->fetchColumn(), 'SELECT 1 failed');
    });

    $runner->test('redis reachable', function () use ($runner, &$config, &$redis, &$prefix) {
        $redis = new RedisCacheService($config);
        $runner->assertTrue($redis->isAvailable(), 'Redis not available');
        $prefix = $config['redis']['prefix'] ?? 'webmail:';
    });

    $runner->test('push service constructs', function () use ($runner, &$config, &$push) {
        $push = new PushNotificationService($config);
        $runner->assertTrue($push instanceof PushNotificationService, 'service init failed');
    });

    // Run cleanup once up-front so a previous aborted run can't skew assertions.
    $runner->test('pre-clean test fixtures', function () use ($cleanup) {
        $cleanup();
    });
}

// Smoke mode stops after connectivity.
if ($runner->smoke) {
    exit($runner->finish());
}

$ensureDeps = function () use (&$push, &$db, &$redis) {
    if (!$push || !$db || !$redis) {
        throw new \RuntimeException('preflight did not complete (need DB+Redis+service)');
    }
};

// ---------------------------------------------------------------------------
// 2. TOKENS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('tokens')) {
    $runner->section('2. TOKENS');

    $runner->test('register native token (device A)', function () use ($runner, &$push, &$db, $ensureDeps, $TEST_EMAIL, $APP_MAIN, $DEV_A, $TOK_1) {
        $ensureDeps();
        $res = $push->registerNativeToken($TEST_EMAIL, 'ios', $APP_MAIN, $DEV_A, 'Test iPhone', $TOK_1);
        $runner->assertTrue($res['success'] ?? false, 'register failed');
        $runner->assertEquals(1, $push->getNativeTokenCount($TEST_EMAIL), 'expected exactly 1 token');
    });

    $runner->test('redis fcm_tokens cache populated (with app_id)', function () use ($runner, &$redis, $ensureDeps, $cacheTokens, $TEST_EMAIL, $APP_MAIN, $TOK_1) {
        $ensureDeps();
        $entries = $redis->get('fcm_tokens:' . strtolower($TEST_EMAIL));
        $runner->assertTrue(in_array($TOK_1, $cacheTokens($entries), true), 'token not in Redis cache');
        // The cache must carry the app_id so Node can route by app.
        $match = null;
        foreach ((array)$entries as $e) {
            if (is_array($e) && ($e['token'] ?? null) === $TOK_1) { $match = $e; break; }
        }
        $runner->assertTrue(is_array($match), 'cache entry is not an object (missing app_id shape)');
        $runner->assertEquals($APP_MAIN, $match['app_id'] ?? null, 'cache entry app_id mismatch');
    });

    $runner->test('token rotation in place (same device, new token)', function () use ($runner, &$push, &$redis, $ensureDeps, $cacheTokens, $TEST_EMAIL, $APP_MAIN, $DEV_A, $TOK_2) {
        $ensureDeps();
        $push->registerNativeToken($TEST_EMAIL, 'ios', $APP_MAIN, $DEV_A, 'Test iPhone', $TOK_2);
        $runner->assertEquals(1, $push->getNativeTokenCount($TEST_EMAIL), 'rotation should not add a row');
        $tokens = $cacheTokens($redis->get('fcm_tokens:' . strtolower($TEST_EMAIL)));
        $runner->assertTrue(in_array($TOK_2, $tokens, true), 'rotated token missing from Redis');
        $runner->assertTrue(!in_array($TOK_2 . 'x', $tokens, true), 'sanity');
    });

    $runner->test('second device adds a distinct row', function () use ($runner, &$push, $ensureDeps, $TEST_EMAIL, $APP_MAIN, $DEV_B, $TOK_1) {
        $ensureDeps();
        // TOK_1 is now free (rotated off device A); register it on device B.
        $push->registerNativeToken($TEST_EMAIL, 'android', $APP_MAIN, $DEV_B, 'Test Pixel', $TOK_1);
        $runner->assertEquals(2, $push->getNativeTokenCount($TEST_EMAIL), 'expected 2 device rows');
    });

    $runner->test('token reassignment removes prior holder', function () use ($runner, &$push, &$db, $ensureDeps, $TEST_EMAIL, $APP_MAIN, $DEV_A, $TOK_1) {
        $ensureDeps();
        // Move TOK_1 (currently on device B) onto device A. Device B's row for
        // that token must be dropped so a token never lives on two devices.
        $push->registerNativeToken($TEST_EMAIL, 'ios', $APP_MAIN, $DEV_A, 'Test iPhone', $TOK_1);
        $stmt = $db->prepare("SELECT COUNT(*) FROM native_push_tokens WHERE token = ?");
        $stmt->execute([$TOK_1]);
        $runner->assertEquals(1, (int)$stmt->fetchColumn(), 'token should exist on exactly one device');
    });

    $runner->test('remove native token by token', function () use ($runner, &$push, $ensureDeps, $TEST_EMAIL, $TOK_1) {
        $ensureDeps();
        $res = $push->removeNativeToken($TEST_EMAIL, $TOK_1, null, null);
        $runner->assertTrue($res['success'] ?? false, 'remove failed');
    });

    $runner->test('remove all + redis cache cleared', function () use ($runner, &$push, &$redis, $ensureDeps, $TEST_EMAIL, $DEV_B) {
        $ensureDeps();
        $push->removeNativeToken($TEST_EMAIL, null, $DEV_B, null);
        $runner->assertEquals(0, $push->getNativeTokenCount($TEST_EMAIL), 'expected 0 tokens');
        $tokens = $redis->get('fcm_tokens:' . strtolower($TEST_EMAIL));
        $runner->assertTrue(empty($tokens), 'Redis cache should be cleared when no tokens remain');
    });
}

// ---------------------------------------------------------------------------
// 2b. MULTI-APP (both apps on one phone -> distinct rows, app_id in cache)
//
// When a user installs BOTH the Pro and the Chat app on the same device, each
// app registers its own FCM token under its own app_id. The backend must keep
// them as separate rows and surface the app_id in the Redis cache so the Node
// fcmService can route chat/calls to the Chat app only.
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('apps')) {
    $runner->section('2b. MULTI-APP');

    $TOK_PRO = 'flowone_test_tok_pro_' . bin2hex(random_bytes(6));
    $TOK_CHAT = 'flowone_test_tok_chat_' . bin2hex(random_bytes(6));

    $runner->test('both apps on one device create two distinct rows', function () use ($runner, &$push, &$db, $ensureDeps, $TEST_EMAIL, $APP_MAIN, $APP_CHAT, $DEV_A, $TOK_PRO, $TOK_CHAT) {
        $ensureDeps();
        $push->registerNativeToken($TEST_EMAIL, 'ios', $APP_MAIN, $DEV_A, 'Test iPhone', $TOK_PRO);
        $push->registerNativeToken($TEST_EMAIL, 'ios', $APP_CHAT, $DEV_A, 'Test iPhone', $TOK_CHAT);
        $runner->assertEquals(2, $push->getNativeTokenCount($TEST_EMAIL), 'expected one row per app');

        $stmt = $db->prepare("SELECT app_id, token FROM native_push_tokens WHERE user_email = ? ORDER BY app_id");
        $stmt->execute([$TEST_EMAIL]);
        $byApp = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byApp[$row['app_id']] = $row['token'];
        }
        $runner->assertEquals($TOK_CHAT, $byApp[$APP_CHAT] ?? null, 'chat app token missing/mismatched');
        $runner->assertEquals($TOK_PRO, $byApp[$APP_MAIN] ?? null, 'pro app token missing/mismatched');
    });

    $runner->test('redis cache carries the app_id for each token', function () use ($runner, &$redis, $ensureDeps, $TEST_EMAIL, $APP_MAIN, $APP_CHAT, $TOK_PRO, $TOK_CHAT) {
        $ensureDeps();
        $entries = (array)$redis->get('fcm_tokens:' . strtolower($TEST_EMAIL));
        $appFor = [];
        foreach ($entries as $e) {
            if (is_array($e) && isset($e['token'])) {
                $appFor[$e['token']] = $e['app_id'] ?? null;
            }
        }
        $runner->assertEquals($APP_CHAT, $appFor[$TOK_CHAT] ?? null, 'chat token app_id missing from cache');
        $runner->assertEquals($APP_MAIN, $appFor[$TOK_PRO] ?? null, 'pro token app_id missing from cache');
    });

    $runner->test('cleanup multi-app rows', function () use ($runner, &$push, $ensureDeps, $TEST_EMAIL, $TOK_PRO, $TOK_CHAT) {
        $ensureDeps();
        $push->removeNativeToken($TEST_EMAIL, $TOK_PRO, null, null);
        $push->removeNativeToken($TEST_EMAIL, $TOK_CHAT, null, null);
        $runner->assertEquals(0, $push->getNativeTokenCount($TEST_EMAIL), 'expected 0 tokens after cleanup');
    });
}

// ---------------------------------------------------------------------------
// 3. PREFERENCES
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('prefs')) {
    $runner->section('3. PREFERENCES');

    $runner->test('defaults are all-on', function () use ($runner, &$push, $ensureDeps, $TEST_EMAIL) {
        $ensureDeps();
        $prefs = $push->getPreferences($TEST_EMAIL);
        foreach (['email', 'chat', 'calls', 'calendar', 'boards'] as $t) {
            $runner->assertTrue(($prefs[$t] ?? null) === true, "default for {$t} should be true");
        }
    });

    $runner->test('update persists + returns merged map', function () use ($runner, &$push, $ensureDeps, $TEST_EMAIL) {
        $ensureDeps();
        $res = $push->updatePreferences($TEST_EMAIL, ['chat' => false]);
        $runner->assertTrue($res['success'] ?? false, 'update failed');
        $runner->assertTrue(($res['preferences']['chat'] ?? null) === false, 'chat should be false');
        $runner->assertTrue(($res['preferences']['email'] ?? null) === true, 'email should stay true');
        $reread = $push->getPreferences($TEST_EMAIL);
        $runner->assertTrue($reread['chat'] === false, 'chat not persisted');
    });

    $runner->test('redis notif_prefs map mirrors MySQL', function () use ($runner, &$redis, $ensureDeps, $TEST_EMAIL) {
        $ensureDeps();
        $map = $redis->get('notif_prefs:' . strtolower($TEST_EMAIL));
        $runner->assertTrue(is_array($map), 'notif_prefs map missing');
        $runner->assertEquals(0, (int)($map['chat'] ?? -1), 'chat should be 0 in Redis map');
        $runner->assertEquals(1, (int)($map['email'] ?? -1), 'email should be 1 in Redis map');
    });
}

// ---------------------------------------------------------------------------
// 4. PRUNE QUEUE
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('prune')) {
    $runner->section('4. PRUNE QUEUE');

    $runner->test('drain removes a queued dead token', function () use ($runner, &$push, &$redis, $ensureDeps, $TEST_EMAIL, $APP_MAIN, $DEV_A) {
        $ensureDeps();
        if ($runner->skipSend) {
            $runner->log('          skipped (--skip-send): drain touches the shared queue');
            return 'warn';
        }
        $deadTok = 'flowone_test_tok_dead_' . bin2hex(random_bytes(6));
        $push->registerNativeToken($TEST_EMAIL, 'ios', $APP_MAIN, $DEV_A, 'Test iPhone', $deadTok);
        $runner->assertEquals(1, $push->getNativeTokenCount($TEST_EMAIL), 'setup: token should exist');

        $redis->listPush('fcm_prune_queue', json_encode(['email' => $TEST_EMAIL, 'token' => $deadTok, 'ts' => time()]));
        $processed = $push->drainFcmPruneQueue(1000);
        $runner->assertTrue($processed >= 1, 'drain processed nothing');
        $runner->assertEquals(0, $push->getNativeTokenCount($TEST_EMAIL), 'dead token not pruned');
    });
}

// ---------------------------------------------------------------------------
// 4b. BADGE COUNT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('badge')) {
    $runner->section('4b. BADGE COUNT');

    $runner->test('setBadgeCount stores integer in Redis', function () use ($runner, &$push, &$redis, $ensureDeps, $TEST_EMAIL) {
        $ensureDeps();
        $res = $push->setBadgeCount($TEST_EMAIL, 5);
        $runner->assertTrue($res['success'] ?? false, 'setBadgeCount failed');
        $val = $redis->get('badge:' . strtolower($TEST_EMAIL));
        $runner->assertEquals(5, (int)$val, 'stored badge value mismatch');
    });

    $runner->test('stored badge is INCR-compatible (Node bumps it on each push)', function () use ($runner, &$push, &$redis, $ensureDeps, $TEST_EMAIL) {
        $ensureDeps();
        $push->setBadgeCount($TEST_EMAIL, 5);
        // The Node mailsync server calls redis.incr() on every push; mirror that.
        $next = $redis->increment('badge:' . strtolower($TEST_EMAIL), 1);
        $runner->assertEquals(6, (int)$next, 'INCR on the stored badge should yield 6');
    });

    $runner->test('negative count clamps to 0', function () use ($runner, &$push, &$redis, $ensureDeps, $TEST_EMAIL) {
        $ensureDeps();
        $push->setBadgeCount($TEST_EMAIL, -3);
        $val = $redis->get('badge:' . strtolower($TEST_EMAIL));
        $runner->assertEquals(0, (int)$val, 'negative count should clamp to 0');
    });
}

// ---------------------------------------------------------------------------
// 5. CALENDAR REMINDERS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('calendar')) {
    $runner->section('5. CALENDAR REMINDERS');

    $calId = null;

    $runner->test('create test calendar', function () use ($runner, &$config, &$db, &$calId, $ensureDeps, $TEST_EMAIL) {
        $ensureDeps();
        new CalendarService($config); // ensures tables exist
        $stmt = $db->prepare("INSERT INTO calendars (user_email, name, color, timezone, is_default) VALUES (?, '[FLOWONE-TEST] Push', '#3b82f6', 'UTC', 0)");
        $stmt->execute([$TEST_EMAIL]);
        $calId = (int)$db->lastInsertId();
        $runner->assertTrue($calId > 0, 'calendar insert failed');
    });

    $runner->test('non-recurring reminder is due', function () use ($runner, &$config, &$db, &$calId, $ensureDeps) {
        $ensureDeps();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = $now->modify('+10 minutes')->format('Y-m-d H:i:s');
        $end = $now->modify('+40 minutes')->format('Y-m-d H:i:s');
        $reminders = json_encode([['minutes' => 10, 'method' => 'popup']]);

        $stmt = $db->prepare("INSERT INTO calendar_events (calendar_id, uid, title, start_time, end_time, all_day, timezone, recurrence, reminders, etag)
            VALUES (?, ?, '[FLOWONE-TEST] Reminder', ?, ?, 0, 'UTC', NULL, ?, ?)");
        $stmt->execute([$calId, 'flowone_test_evt_' . bin2hex(random_bytes(6)), $start, $end, $reminders, bin2hex(random_bytes(8))]);

        $cal = new CalendarService($config);
        $due = $cal->getDueReminders($now, 120, 1440);
        $found = false;
        foreach ($due as $r) {
            if ($r['title'] === '[FLOWONE-TEST] Reminder' && (int)$r['minutes'] === 10) { $found = true; break; }
        }
        $runner->assertTrue($found, 'expected the 10-min reminder to be due now');
    });

    $runner->test('recurring (daily) occurrence reminder is due', function () use ($runner, &$config, &$db, &$calId, $ensureDeps) {
        $ensureDeps();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // Event started 30 days ago at a wall-clock time so today's occurrence
        // is now+10min; reminder 10 min before => due now. Exercises fast-forward.
        $start = $now->modify('-30 days')->modify('+10 minutes')->format('Y-m-d H:i:s');
        $end = $now->modify('-30 days')->modify('+40 minutes')->format('Y-m-d H:i:s');
        $reminders = json_encode([['minutes' => 10, 'method' => 'popup']]);

        $stmt = $db->prepare("INSERT INTO calendar_events (calendar_id, uid, title, start_time, end_time, all_day, timezone, recurrence, reminders, etag)
            VALUES (?, ?, '[FLOWONE-TEST] Recurring', ?, ?, 0, 'UTC', 'FREQ=DAILY', ?, ?)");
        $stmt->execute([$calId, 'flowone_test_rec_' . bin2hex(random_bytes(6)), $start, $end, $reminders, bin2hex(random_bytes(8))]);

        $cal = new CalendarService($config);
        $due = $cal->getDueReminders($now, 120, 1440);
        $found = false;
        foreach ($due as $r) {
            if ($r['title'] === '[FLOWONE-TEST] Recurring' && (int)$r['minutes'] === 10) { $found = true; break; }
        }
        $runner->assertTrue($found, 'expected the recurring occurrence reminder to be due now');
    });

    $runner->test('far-future reminder is NOT due', function () use ($runner, &$config, &$db, &$calId, $ensureDeps) {
        $ensureDeps();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = $now->modify('+3 days')->format('Y-m-d H:i:s');
        $end = $now->modify('+3 days')->modify('+30 minutes')->format('Y-m-d H:i:s');
        $reminders = json_encode([['minutes' => 10, 'method' => 'popup']]);

        $stmt = $db->prepare("INSERT INTO calendar_events (calendar_id, uid, title, start_time, end_time, all_day, timezone, recurrence, reminders, etag)
            VALUES (?, ?, '[FLOWONE-TEST] Future', ?, ?, 0, 'UTC', NULL, ?, ?)");
        $stmt->execute([$calId, 'flowone_test_fut_' . bin2hex(random_bytes(6)), $start, $end, $reminders, bin2hex(random_bytes(8))]);

        $cal = new CalendarService($config);
        $due = $cal->getDueReminders($now, 120, 1440);
        foreach ($due as $r) {
            if ($r['title'] === '[FLOWONE-TEST] Future') {
                throw new \RuntimeException('far-future reminder should not be due');
            }
        }
    });
}

// ---------------------------------------------------------------------------
// 6. STALE CLEANUP
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('stale')) {
    $runner->section('6. STALE CLEANUP');

    $runner->test('cleanupStaleNativeTokens removes unseen tokens', function () use ($runner, &$push, &$db, $ensureDeps, $TEST_EMAIL, $APP_MAIN) {
        $ensureDeps();
        $staleTok = 'flowone_test_tok_stale_' . bin2hex(random_bytes(6));
        // Insert directly with an old last_seen_at (registerNativeToken always NOW()).
        $stmt = $db->prepare("INSERT INTO native_push_tokens (user_email, platform, app_id, device_id, device_name, token, last_seen_at)
            VALUES (?, 'ios', ?, 'flowone_test_dev_stale', 'Old Phone', ?, DATE_SUB(NOW(), INTERVAL 200 DAY))");
        $stmt->execute([$TEST_EMAIL, $APP_MAIN, $staleTok]);

        $before = $push->getNativeTokenCount($TEST_EMAIL);
        $runner->assertTrue($before >= 1, 'setup: stale token should exist');

        $removed = $push->cleanupStaleNativeTokens(75);
        $runner->assertTrue($removed >= 1, 'expected at least one stale token removed');

        $stmt = $db->prepare("SELECT COUNT(*) FROM native_push_tokens WHERE token = ?");
        $stmt->execute([$staleTok]);
        $runner->assertEquals(0, (int)$stmt->fetchColumn(), 'stale token still present');
    });
}

exit($runner->finish());
