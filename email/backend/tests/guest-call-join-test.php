#!/usr/bin/env php
<?php
/**
 * FlowOne Guest Call Join / Token Expiry Test.
 *
 * Regression suite for the 2026-06-11 join-404 bug: guest_call_tokens
 * expiry was written via PHP date() (server-local tz) for some token
 * types and UTC for others, while the join guard compared against
 * MySQL NOW() (session tz). On servers where PHP/MySQL tz != UTC this
 * made joins fail with 404 "This link is no longer valid" up to
 * tz-offset hours before the real expiry. Everything is now UTC:
 * gmdate() on write, UTC_TIMESTAMP() in SQL, DateTimeZone('UTC') in PHP.
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   extensions, autoloader, DB, table, NOW() vs UTC_TIMESTAMP drift
 *   guard       join SQL guard accepts near-expiry UTC tokens, rejects
 *               expired / revoked / maxed-out tokens
 *   storage     createStandaloneGuestToken writes UTC expiry; fresh token joins
 *   info        getTokenInfo + listUserLinks expiry flags (PHP-side UTC parse)
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/guest-call-join-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no business logic)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --skip-send            no-op here (suite sends nothing external)
 *   --timeout=N            per-test timeout in seconds (default 30)
 *   --help                 show this message
 *
 * All test rows use the flowone_test_ token prefix or the
 * flowone_test@flowone.pro creator address and are removed in cleanup
 * handlers that run even on failure or SIGINT. Idempotent.
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/lib/test-runner.php';

$runner = new FlowOneTestRunner('guest-call-join', $argv);

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
$config = null;
$db = null;

if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php pdo_mysql extension loaded', function () use ($runner) {
        $runner->assertTrue(extension_loaded('pdo_mysql'), 'pdo_mysql extension missing');
    });

    $runner->test('cron bootstrap + config load', function () use ($runner, &$config) {
        require_once __DIR__ . '/../cron/bootstrap.php';
        $config = require __DIR__ . '/../src/config.php';
        $runner->assertTrue(is_array($config), 'config.php did not return an array');
    });

    $runner->test('database reachable', function () use ($runner, &$config, &$db) {
        $runner->assertTrue(is_array($config), 'config not loaded');
        $db = \Webmail\Core\Database::getConnection($config);
        $runner->assertEquals('1', (string)$db->query('SELECT 1')->fetchColumn(), 'SELECT 1 failed');
    });

    $runner->test('guest_call_tokens table exists', function () use ($runner, &$db) {
        $count = $db->query("SHOW TABLES LIKE 'guest_call_tokens'")->rowCount();
        $runner->assertEquals(1, $count, 'guest_call_tokens table missing');
    });

    $runner->test('report MySQL NOW() vs UTC_TIMESTAMP() drift', function () use ($runner, &$db) {
        $row = $db->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS drift')->fetch(\PDO::FETCH_ASSOC);
        $drift = (int)($row['drift'] ?? 0);
        $runner->log('          MySQL session tz is UTC' . ($drift >= 0 ? '+' : '') . ($drift / 60) . 'h relative to UTC');
        if ($drift !== 0) {
            // Not a failure — the whole point of the UTC fix is that a
            // non-UTC session tz must no longer matter. Surface it anyway.
            return 'warn';
        }
    });

    $runner->test('php tz vs UTC drift noted', function () use ($runner) {
        $drift = (int)round((strtotime(date('Y-m-d H:i:s') . ' UTC') - time()) / 60);
        $runner->log('          PHP date.timezone is UTC' . ($drift >= 0 ? '+' : '') . ($drift / 60) . 'h relative to UTC (' . date_default_timezone_get() . ')');
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

// Lazy init for --only runs that skip preflight
if ($config === null) {
    require_once __DIR__ . '/../cron/bootstrap.php';
    $config = require __DIR__ . '/../src/config.php';
}
if ($db === null) {
    $db = \Webmail\Core\Database::getConnection($config);
}

$service = new \Webmail\Services\GuestCallService($config);

// ---------------------------------------------------------------------------
// Fixtures + signal-safe cleanup
// ---------------------------------------------------------------------------
$suffix = bin2hex(random_bytes(6));
$testCreator = 'flowone_test@flowone.pro';

$runner->addCleanup(function () use ($db, $testCreator) {
    $db->prepare("DELETE FROM guest_call_tokens WHERE token LIKE 'flowone_test_%' OR created_by = ?")
        ->execute([$testCreator]);
    // meeting_rooms rows are auto-created by createStandaloneGuestToken;
    // purge by creator so even rows from previously failed runs get removed.
    if ($db->query("SHOW TABLES LIKE 'meeting_rooms'")->rowCount() === 1) {
        $db->prepare('DELETE FROM meeting_rooms WHERE created_by = ?')->execute([$testCreator]);
    }
});

/**
 * Insert a guest_call_tokens row. $expiresAt MUST be a UTC datetime string
 * (that is the storage contract under test). Returns the row id.
 */
$insertToken = function (
    string $token,
    string $expiresAtUtc,
    string $status = 'active',
    int $maxUses = 0,
    int $useCount = 0
) use ($db, $testCreator, $suffix): int {
    $stmt = $db->prepare("
        INSERT INTO guest_call_tokens (token, room_name, created_by, expires_at, status, max_uses, use_count)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), status = VALUES(status),
                                max_uses = VALUES(max_uses), use_count = VALUES(use_count)
    ");
    $stmt->execute([$token, 'flowone_test_room_' . $suffix, $testCreator, $expiresAtUtc, $status, $maxUses, $useCount]);
    $sel = $db->prepare('SELECT id FROM guest_call_tokens WHERE token = ?');
    $sel->execute([$token]);
    return (int)$sel->fetchColumn();
};

/**
 * Run the exact join guard UPDATE used by GuestCallService::validateAndJoin
 * and return the affected row count (1 = join allowed, 0 = rejected).
 */
$runJoinGuard = function (int $id) use ($db): int {
    $upd = $db->prepare('
        UPDATE guest_call_tokens
        SET use_count = use_count + 1,
            used_at = COALESCE(used_at, UTC_TIMESTAMP()),
            guest_name = ?,
            last_used_ip = ?,
            last_used_user_agent = ?
        WHERE id = ? AND status = ? AND expires_at > UTC_TIMESTAMP()
          AND (max_uses = 0 OR use_count < max_uses)
    ');
    $upd->execute(['FlowOne Test Guest', '127.0.0.1', 'flowone_test_suite', $id, 'active']);
    return $upd->rowCount();
};

// ---------------------------------------------------------------------------
// 2. GUARD — join SQL guard semantics
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('guard')) {
    $runner->section('2. GUARD');

    $runner->test('token expiring in 30 min (UTC) passes join guard', function () use ($runner, $insertToken, $runJoinGuard, $suffix) {
        // Regression: with `expires_at > NOW()` this failed on any server
        // whose MySQL session tz is ahead of UTC (e.g. Europe/Budapest).
        $id = $insertToken('flowone_test_join30_' . $suffix, gmdate('Y-m-d H:i:s', time() + 1800));
        $runner->assertEquals(1, $runJoinGuard($id), 'near-expiry UTC token was rejected by the join guard');
    });

    $runner->test('token expiring in 23h59m (UTC) passes join guard', function () use ($runner, $insertToken, $runJoinGuard, $suffix) {
        $id = $insertToken('flowone_test_join24_' . $suffix, gmdate('Y-m-d H:i:s', time() + 86340));
        $runner->assertEquals(1, $runJoinGuard($id), 'fresh 24h token was rejected by the join guard');
    });

    $runner->test('token expired 30 min ago (UTC) is rejected', function () use ($runner, $insertToken, $runJoinGuard, $suffix) {
        $id = $insertToken('flowone_test_exp30_' . $suffix, gmdate('Y-m-d H:i:s', time() - 1800));
        $runner->assertEquals(0, $runJoinGuard($id), 'expired token slipped through the join guard');
    });

    $runner->test('revoked token is rejected', function () use ($runner, $insertToken, $runJoinGuard, $suffix) {
        $id = $insertToken('flowone_test_revoked_' . $suffix, gmdate('Y-m-d H:i:s', time() + 3600), 'revoked');
        $runner->assertEquals(0, $runJoinGuard($id), 'revoked token slipped through the join guard');
    });

    $runner->test('maxed-out token is rejected', function () use ($runner, $insertToken, $runJoinGuard, $suffix) {
        $id = $insertToken('flowone_test_maxed_' . $suffix, gmdate('Y-m-d H:i:s', time() + 3600), 'active', 2, 2);
        $runner->assertEquals(0, $runJoinGuard($id), 'maxed-out token slipped through the join guard');
    });

    $runner->test('guard consumes exactly one use per join', function () use ($runner, $insertToken, $runJoinGuard, $db, $suffix) {
        $id = $insertToken('flowone_test_uses_' . $suffix, gmdate('Y-m-d H:i:s', time() + 3600), 'active', 2, 0);
        $runner->assertEquals(1, $runJoinGuard($id), 'first join rejected');
        $runner->assertEquals(1, $runJoinGuard($id), 'second join rejected');
        $runner->assertEquals(0, $runJoinGuard($id), 'third join exceeded max_uses');
        $sel = $db->prepare('SELECT use_count FROM guest_call_tokens WHERE id = ?');
        $sel->execute([$id]);
        $runner->assertEquals(2, (int)$sel->fetchColumn(), 'use_count drifted');
    });
}

// ---------------------------------------------------------------------------
// 3. STORAGE — token creation writes UTC
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('storage')) {
    $runner->section('3. STORAGE');

    // Returns { guest_link, admin_link, room_name, expires_at } — the raw
    // tokens are the last path segment of each /guest/call/{token} link.
    $tokenFromLink = function (?string $link): string {
        $path = (string)parse_url((string)$link, PHP_URL_PATH);
        $seg = $path !== '' ? basename($path) : '';
        return preg_match('/^[a-f0-9]{32,128}$/i', $seg) ? $seg : '';
    };

    $standaloneTokens = ['guest' => '', 'admin' => ''];

    $runner->test('createStandaloneGuestToken stores UTC expiry (~ now+24h)', function () use ($runner, $service, $db, $testCreator, $tokenFromLink, &$standaloneTokens) {
        $standalone = $service->createStandaloneGuestToken($testCreator, 24, false, false);
        $standaloneTokens['guest'] = $tokenFromLink($standalone['guest_link'] ?? '');
        $standaloneTokens['admin'] = $tokenFromLink($standalone['admin_link'] ?? '');
        $runner->assertTrue($standaloneTokens['guest'] !== '', 'no guest_link token returned');
        $runner->assertTrue($standaloneTokens['admin'] !== '', 'no admin_link token returned');

        $sel = $db->prepare('SELECT expires_at FROM guest_call_tokens WHERE token = ?');
        $sel->execute([$standaloneTokens['guest']]);
        $expiresAt = (string)$sel->fetchColumn();
        $runner->assertTrue($expiresAt !== '', 'token row not found');

        // Parse as UTC (the storage contract) and require now+24h +/- 5 min.
        $exp = (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp();
        $delta = abs($exp - (time() + 24 * 3600));
        $runner->assertTrue(
            $delta < 300,
            "expires_at '$expiresAt' is " . round($delta / 60) . " min away from utc now+24h — written in a non-UTC tz?"
        );
    });

    $runner->test('fresh standalone guest + admin tokens pass join guard', function () use ($runner, $db, $runJoinGuard, &$standaloneTokens) {
        foreach (['guest', 'admin'] as $role) {
            $runner->assertTrue($standaloneTokens[$role] !== '', "$role token not captured (creation test failed?)");
            $sel = $db->prepare('SELECT id FROM guest_call_tokens WHERE token = ?');
            $sel->execute([$standaloneTokens[$role]]);
            $id = (int)$sel->fetchColumn();
            $runner->assertTrue($id > 0, "$role token row missing");
            $runner->assertEquals(1, $runJoinGuard($id), "fresh $role token rejected by join guard");
        }
    });
}

// ---------------------------------------------------------------------------
// 4. INFO — PHP-side expiry checks agree with SQL guard
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('info')) {
    $runner->section('4. INFO');

    $runner->test('getTokenInfo: near-expiry token reported active', function () use ($runner, $service, $insertToken, $suffix) {
        $token = 'flowone_test_info_ok_' . $suffix;
        $insertToken($token, gmdate('Y-m-d H:i:s', time() + 1800));
        $info = $service->getTokenInfo($token);
        $runner->assertTrue(is_array($info), 'getTokenInfo returned null');
        $runner->assertEquals(false, (bool)($info['expired'] ?? true), 'near-expiry token reported expired');
    });

    $runner->test('getTokenInfo: past-expiry token reported expired', function () use ($runner, $service, $insertToken, $suffix) {
        $token = 'flowone_test_info_exp_' . $suffix;
        $insertToken($token, gmdate('Y-m-d H:i:s', time() - 1800));
        $info = $service->getTokenInfo($token);
        $runner->assertTrue(is_array($info), 'getTokenInfo returned null');
        $runner->assertEquals(true, (bool)($info['expired'] ?? false), 'expired token reported active');
    });

    $runner->test('listUserLinks: expiry flags use UTC parse', function () use ($runner, $service, $db, $testCreator, $suffix) {
        $hasOwnerCols = $db->query("SHOW COLUMNS FROM guest_call_tokens LIKE 'owner_type'")->rowCount() === 1;
        if (!$hasOwnerCols) {
            return 'skip';
        }
        $mk = function (string $token, string $expiresAtUtc) use ($db, $testCreator, $suffix) {
            $db->prepare("
                INSERT INTO guest_call_tokens (token, room_name, created_by, expires_at, status, role, owner_type)
                VALUES (?, ?, ?, ?, 'active', 'guest', 'standalone')
                ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)
            ")->execute([$token, 'flowone_test_room_' . $suffix, $testCreator, $expiresAtUtc]);
        };
        $live = 'flowone_test_list_ok_' . $suffix;
        $dead = 'flowone_test_list_exp_' . $suffix;
        $mk($live, gmdate('Y-m-d H:i:s', time() + 1800));
        $mk($dead, gmdate('Y-m-d H:i:s', time() - 1800));

        $byToken = [];
        foreach ($service->listUserLinks($testCreator) as $row) {
            $byToken[$row['token']] = $row;
        }
        $runner->assertTrue(isset($byToken[$live], $byToken[$dead]), 'test links missing from listUserLinks');
        $runner->assertEquals(false, (bool)$byToken[$live]['expired'], 'live link flagged expired (tz parse bug)');
        $runner->assertEquals(true, (bool)$byToken[$dead]['expired'], 'dead link flagged active (tz parse bug)');
    });
}

exit($runner->finish());
