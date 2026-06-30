#!/usr/bin/env php
<?php
/**
 * guest-call-link-test.php
 *
 * Regression test for the chat "Call Link" flow (POST /chat/guest-call-link).
 * Verifies that:
 *   1. A standalone guest+admin token pair is created with the requested
 *      waiting-room / workshop-mode settings (defaults to waiting_room=true).
 *   2. getTokenInfo() returns the correct is_admin flag for each token in
 *      the pair — guests must NEVER see is_admin=true.
 *   3. validateAndJoin() routes non-admin guests to pending_admission when
 *      waiting room is on, and admins bypass it.
 *   4. The pair shares the same room_name (so admit/kick can target it).
 *   5. Workshop mode propagates to participants_hidden in the token info.
 *
 * Non-destructive: every token/room created here uses the `flowone_test_call_`
 * prefix and is cleaned up via runner cleanup + signal handler.
 *
 * Server run command:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/guest-call-link-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Services\GuestCallService;
use Webmail\Services\MeetingRoomService;
use Webmail\Core\Database;

$runner = new FlowOneTestRunner('guest-call-link', $argv);

foreach (['openssl', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    $runner->log('DB unreachable: ' . $e->getMessage());
    exit(1);
}

$svc = new GuestCallService($config);
$mrSvc = new MeetingRoomService($config);

$testEmail = 'flowone_test_call_' . bin2hex(random_bytes(4)) . '@example.invalid';
$createdTokens = [];
$createdRooms = [];

$runner->addCleanup(function () use (&$createdTokens, &$createdRooms, $db) {
    if (!empty($createdTokens)) {
        $in = implode(',', array_fill(0, count($createdTokens), '?'));
        foreach (['meeting_sessions', 'call_admission_requests', 'guest_call_tokens'] as $tbl) {
            try {
                $db->prepare("DELETE FROM {$tbl} WHERE token IN ($in)")->execute($createdTokens);
            } catch (\Throwable $e) {
                // some tables (meeting_sessions, call_admission_requests) may
                // not exist on older deployments; safe to ignore
            }
        }
    }
    if (!empty($createdRooms)) {
        $in = implode(',', array_fill(0, count($createdRooms), '?'));
        foreach (['meeting_rooms', 'call_admission_requests', 'meeting_sessions'] as $tbl) {
            try {
                $db->prepare("DELETE FROM {$tbl} WHERE room_name IN ($in)")->execute($createdRooms);
            } catch (\Throwable $e) {
                // ignore (optional tables)
            }
        }
    }
});

/**
 * Track every token a test creates so cleanup deletes them.
 */
$trackResult = function (array $pair) use (&$createdTokens, &$createdRooms) {
    foreach (['guest_link', 'admin_link'] as $k) {
        if (!empty($pair[$k])) {
            $parts = explode('/', rtrim((string) $pair[$k], '/'));
            $tok = end($parts);
            if ($tok) {
                $createdTokens[] = $tok;
            }
        }
    }
    if (!empty($pair['room_name'])) {
        $createdRooms[] = $pair['room_name'];
    }
};

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('db reachable', function () use ($db) {
        $r = $db->query('SELECT 1')->fetchColumn();
        if ((int) $r !== 1) {
            throw new \RuntimeException('SELECT 1 did not return 1');
        }
    });
    $runner->test('guest_call_tokens table present', function () use ($db) {
        $r = $db->query("SHOW TABLES LIKE 'guest_call_tokens'");
        if (!$r || $r->rowCount() === 0) {
            throw new \RuntimeException('guest_call_tokens table missing');
        }
    });
    exit($runner->finish());
}

// --- 1. CREATE PAIR ----------------------------------------------------

if ($runner->shouldRunSection('1. create')) {
    $runner->section('1. CREATE');

    $runner->test('createStandaloneGuestToken with waiting room ON', function () use ($svc, $testEmail, $trackResult, $runner) {
        $out = $svc->createStandaloneGuestToken($testEmail, 1, true, false);
        $trackResult($out);
        $runner->assertTrue(!empty($out['guest_link']), 'guest_link missing');
        $runner->assertTrue(!empty($out['admin_link']), 'admin_link missing');
        $runner->assertTrue(!empty($out['room_name']), 'room_name missing');
        $runner->assertTrue($out['guest_link'] !== $out['admin_link'], 'guest_link and admin_link must differ');
    });

    $runner->test('createStandaloneGuestToken with workshop mode ON', function () use ($svc, $testEmail, $trackResult, $runner) {
        $out = $svc->createStandaloneGuestToken($testEmail, 1, true, true);
        $trackResult($out);
        $runner->assertTrue(!empty($out['guest_link']), 'guest_link missing');
        $runner->assertTrue(!empty($out['admin_link']), 'admin_link missing');
    });
}

// --- 2. TOKEN INFO -----------------------------------------------------

if ($runner->shouldRunSection('2. info')) {
    $runner->section('2. INFO');

    $runner->test('guest token returns is_admin=false + correct flags', function () use ($svc, $testEmail, $trackResult, $runner) {
        $out = $svc->createStandaloneGuestToken($testEmail, 1, true, false);
        $trackResult($out);
        $guestParts = explode('/', rtrim($out['guest_link'], '/'));
        $guestToken = end($guestParts);
        $info = $svc->getTokenInfo($guestToken);
        $runner->assertTrue(is_array($info), 'getTokenInfo returned null');
        $runner->assertEquals(false, $info['is_admin'], 'guest token reported is_admin=true');
        $runner->assertEquals(true, $info['valid'], 'fresh guest token should be valid');
        $runner->assertEquals(true, $info['waiting_room_enabled'], 'waiting_room_enabled should reflect request');
        $runner->assertEquals(false, $info['participants_hidden'], 'participants_hidden should be false');
    });

    $runner->test('admin token returns is_admin=true', function () use ($svc, $testEmail, $trackResult, $runner) {
        $out = $svc->createStandaloneGuestToken($testEmail, 1, true, false);
        $trackResult($out);
        $adminParts = explode('/', rtrim($out['admin_link'], '/'));
        $adminToken = end($adminParts);
        $info = $svc->getTokenInfo($adminToken);
        $runner->assertTrue(is_array($info), 'getTokenInfo returned null');
        $runner->assertEquals(true, $info['is_admin'], 'admin token reported is_admin=false');
    });

    $runner->test('workshop-mode pair surfaces participants_hidden=true', function () use ($svc, $testEmail, $trackResult, $runner) {
        $out = $svc->createStandaloneGuestToken($testEmail, 1, true, true);
        $trackResult($out);
        $guestParts = explode('/', rtrim($out['guest_link'], '/'));
        $guestToken = end($guestParts);
        $info = $svc->getTokenInfo($guestToken);
        $runner->assertEquals(true, $info['participants_hidden'], 'participants_hidden should be true in workshop mode');
    });

    $runner->test('guest + admin share the same room_name', function () use ($svc, $testEmail, $trackResult, $runner) {
        $out = $svc->createStandaloneGuestToken($testEmail, 1, false, false);
        $trackResult($out);
        $guestParts = explode('/', rtrim($out['guest_link'], '/'));
        $adminParts = explode('/', rtrim($out['admin_link'], '/'));
        $guestInfo = $svc->getTokenInfo(end($guestParts));
        $adminInfo = $svc->getTokenInfo(end($adminParts));
        $runner->assertEquals($guestInfo['room_name'], $adminInfo['room_name'], 'guest and admin tokens should target same room');
    });
}

// --- 3. JOIN ROUTING ---------------------------------------------------

if ($runner->shouldRunSection('3. join') && !$runner->skipSend) {
    $runner->section('3. JOIN');

    $runner->test('guest with waiting_room ON gets pending_admission', function () use ($svc, $testEmail, $trackResult, $runner) {
        $out = $svc->createStandaloneGuestToken($testEmail, 1, true, false);
        $trackResult($out);
        $guestParts = explode('/', rtrim($out['guest_link'], '/'));
        $guestToken = end($guestParts);
        $result = $svc->validateAndJoin($guestToken, '[FLOWONE-TEST] Visitor', '127.0.0.1', 'test-runner');
        $runner->assertTrue(empty($result['error']), 'validateAndJoin returned error: ' . ($result['error'] ?? ''));
        $runner->assertEquals('pending_admission', $result['status'] ?? '', 'guest with waiting room ON must wait for admission');
        $runner->assertTrue(!empty($result['request_id']), 'request_id missing on pending_admission');
    });

    $runner->test('guest with waiting_room OFF joins immediately (no admission queue)', function () use ($svc, $testEmail, $trackResult, $runner, $config) {
        // Skip if LiveKit credentials are not configured — joining without
        // waiting room mints a LiveKit token and would fail without them.
        if (empty($config['livekit']['api_key'] ?? null) || empty($config['livekit']['api_secret'] ?? null)) {
            return 'skip';
        }
        $out = $svc->createStandaloneGuestToken($testEmail, 1, false, false);
        $trackResult($out);
        $guestParts = explode('/', rtrim($out['guest_link'], '/'));
        $guestToken = end($guestParts);
        $result = $svc->validateAndJoin($guestToken, '[FLOWONE-TEST] Visitor', '127.0.0.1', 'test-runner');
        $runner->assertTrue(empty($result['error']), 'validateAndJoin error: ' . ($result['error'] ?? ''));
        $runner->assertTrue(empty($result['status']) || $result['status'] !== 'pending_admission', 'should not be pending when waiting room is off');
        $runner->assertTrue(!empty($result['livekit_token']), 'expected livekit_token on direct join');
    });

    $runner->test('admin link bypasses waiting room', function () use ($svc, $testEmail, $trackResult, $runner, $config) {
        if (empty($config['livekit']['api_key'] ?? null) || empty($config['livekit']['api_secret'] ?? null)) {
            return 'skip';
        }
        $out = $svc->createStandaloneGuestToken($testEmail, 1, true, false);
        $trackResult($out);
        $adminParts = explode('/', rtrim($out['admin_link'], '/'));
        $adminToken = end($adminParts);
        $result = $svc->validateAndJoin($adminToken, '[FLOWONE-TEST] Host', '127.0.0.1', 'test-runner');
        $runner->assertTrue(empty($result['error']), 'validateAndJoin error: ' . ($result['error'] ?? ''));
        $runner->assertTrue(empty($result['status']) || $result['status'] !== 'pending_admission', 'admin should NOT be sent to waiting room');
        $runner->assertEquals(true, $result['is_admin'] ?? false, 'admin join result must flag is_admin');
        $runner->assertTrue(!empty($result['livekit_token']), 'admin should receive a livekit_token');
    });
}

// --- 4. ROOM SETTINGS PERSISTENCE -------------------------------------

if ($runner->shouldRunSection('4. settings')) {
    $runner->section('4. SETTINGS');

    $runner->test('meeting_rooms row reflects requested waiting/workshop flags', function () use ($svc, $mrSvc, $testEmail, $trackResult, $runner) {
        $out = $svc->createStandaloneGuestToken($testEmail, 1, true, true);
        $trackResult($out);
        $settings = $mrSvc->getSettings($out['room_name']);
        if ($settings === null) {
            return 'warn'; // meeting_rooms table not present on this deployment
        }
        $runner->assertEquals(1, (int) $settings['waiting_room_enabled'], 'waiting_room_enabled not persisted');
        $runner->assertEquals(1, (int) $settings['participants_hidden'], 'participants_hidden not persisted');
    });
}

exit($runner->finish());
