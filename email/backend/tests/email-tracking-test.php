#!/usr/bin/env php
<?php
/**
 * FlowOne Email Tracking - Comprehensive Test Suite
 *
 * Tests the full tracking lifecycle: per-recipient token generation,
 * tracking pixel recording, open counting, sender-filtering, rate limiting,
 * link click tracking, notifications, and query/aggregation correctness.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-tracking-test.php \
 *       --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --only=GROUPS        Comma-separated groups: create,record,ratelimit,filter,link,notify,clear,query,locate
 *   --smoke              Run a minimal subset of tests
 *   --verbose            Show extra debug info
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'only:', 'smoke', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Email Tracking Test Suite\n";
    echo "==================================\n\n";
    echo "Usage:\n";
    echo "  php email-tracking-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --only=GROUPS        Comma-separated: create,record,ratelimit,filter,link,notify,clear,query,locate\n";
    echo "  --smoke              Run minimal smoke tests only\n";
    echo "  --verbose            Show extra debug info\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-tracking-test.php \\\n";
    echo "      --email=admin@flowone.pro --verbose\n";
    exit(1);
}

$testEmail    = $opts['email'];
$verbose      = isset($opts['verbose']);
$smokeOnly    = isset($opts['smoke']);
$onlyGroups   = isset($opts['only']) ? explode(',', $opts['only']) : [];

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return in_array($group, ['create', 'record', 'query']);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/email-tracking-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function out(string $msg): void {
    global $logFile;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    try {
        $result = $fn();
        $elapsed = round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  \033[32m[PASS]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

function assert_true(bool $condition, string $msg = 'Assertion failed'): void {
    if (!$condition) throw new \RuntimeException($msg);
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $label = $msg ?: 'Values differ';
        throw new \RuntimeException("$label: expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

function assert_not_empty($value, string $msg = 'Value is empty'): void {
    if (empty($value)) throw new \RuntimeException($msg);
}

function assert_greater_than(int $threshold, int $actual, string $msg = ''): void {
    if ($actual <= $threshold) {
        $label = $msg ?: 'Value not greater';
        throw new \RuntimeException("$label: expected > $threshold, got $actual");
    }
}

function vlog(string $msg): void {
    global $verbose;
    if ($verbose) out("          [v] $msg");
}

// ── Cleanup tracking ─────────────────────────────────────────────

$TEST_TAG   = '[FLOWONE-TRKTEST]';
$runId      = date('His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

$cleanupTrackingIds = [];
$cleanupNotificationIds = [];

function doCleanup(): void {
    global $config, $testEmail, $cleanupTrackingIds, $cleanupNotificationIds;

    out("\n--- CLEANUP ---");

    $db = \Webmail\Core\Database::getConnection($config);
    $userLower = strtolower($testEmail);

    // Cascade delete: email_tracking -> email_tracking_recipients, email_read_events, email_link_tracking -> email_click_events
    if (!empty($cleanupTrackingIds)) {
        foreach ($cleanupTrackingIds as $tid) {
            try {
                $db->prepare('DELETE FROM email_click_events WHERE link_token IN (SELECT link_token FROM email_link_tracking WHERE tracking_id = ?)')->execute([$tid]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $db->prepare('DELETE FROM email_link_tracking WHERE tracking_id = ?')->execute([$tid]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $db->prepare('DELETE FROM email_read_events WHERE tracking_id = ?')->execute([$tid]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $db->prepare('DELETE FROM email_tracking_recipients WHERE tracking_id = ?')->execute([$tid]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $db->prepare('DELETE FROM email_tracking WHERE tracking_id = ?')->execute([$tid]);
            } catch (\Throwable $e) { /* ignore */ }
            vlog("Deleted tracking data for $tid");
        }
    }

    // Clean test notifications
    if (!empty($cleanupNotificationIds)) {
        foreach ($cleanupNotificationIds as $nid) {
            try {
                $db->prepare('DELETE FROM notifications WHERE id = ?')->execute([$nid]);
                vlog("Deleted notification ID $nid");
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    // Belt-and-suspenders: also delete any notifications with our test tag in the title
    try {
        $db->prepare("DELETE FROM notifications WHERE user_email = ? AND title LIKE '%FLOWONE-TRKTEST%'")->execute([$userLower]);
    } catch (\Throwable $e) { /* ignore */ }

    out("  Cleanup complete.");
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ── Banner ───────────────────────────────────────────────────────

out("=============================================================");
out("  FlowOne Email Tracking Test Suite");
out("  Account : $testEmail");
out("  Run ID  : $runId");
out("  Mode    : " . ($smokeOnly ? 'SMOKE' : (empty($onlyGroups) ? 'FULL' : 'Groups: ' . implode(', ', $onlyGroups))));
out("=============================================================\n");

// ══════════════════════════════════════════════════════════════════
// PRE-FLIGHT CHECKS
// ══════════════════════════════════════════════════════════════════

out("=== Pre-flight checks ===");

test('Database connection', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $row = $db->query('SELECT 1 AS ok')->fetch();
    assert_equals(1, (int)$row['ok'], 'DB ping');
});

test('email_tracking table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->query("SHOW TABLES LIKE 'email_tracking'");
    assert_true($stmt->rowCount() > 0, 'email_tracking table missing');
});

test('email_tracking_recipients table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->query("SHOW TABLES LIKE 'email_tracking_recipients'");
    assert_true($stmt->rowCount() > 0, 'email_tracking_recipients table missing');
});

test('email_read_events table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->query("SHOW TABLES LIKE 'email_read_events'");
    assert_true($stmt->rowCount() > 0, 'email_read_events table missing');
});

test('email_link_tracking table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->query("SHOW TABLES LIKE 'email_link_tracking'");
    assert_true($stmt->rowCount() > 0, 'email_link_tracking table missing');
});

test('TrackingService instantiates', function () use ($config) {
    $ts = new \Webmail\Addons\EmailTracking\Services\TrackingService($config);
    assert_true($ts instanceof \Webmail\Addons\EmailTracking\Services\TrackingService, 'Constructor failed');
});

// ══════════════════════════════════════════════════════════════════
// GROUP: create -- Tracking creation & per-recipient tokens
// ══════════════════════════════════════════════════════════════════

if (shouldRun('create')) {
    out("\n=== Tracking creation & per-recipient tokens ===");

    $trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    // Simulated recipients
    $recipientA = 'alice_' . $runId . '@example.com';
    $recipientB = 'bob_' . $runId . '@example.com';
    $recipientC = 'charlie_' . $runId . '@example.com';
    $testSubject = "$TEST_TAG Multi-recipient test $runId";

    $trackingId = null;
    $recipientTokens = [];

    test('generateTrackingId returns 64-char hex string', function () use ($trackingService) {
        $id = $trackingService->generateTrackingId();
        assert_equals(64, strlen($id), 'tracking_id length');
        assert_true(ctype_xdigit($id), "tracking_id should be hex, got: $id");
    });

    test('createTracking with 3 recipients returns tokens', function () use (
        $trackingService, $testEmail, $testSubject, $recipientA, $recipientB, $recipientC,
        &$trackingId, &$recipientTokens, &$cleanupTrackingIds
    ) {
        // Set a known sender IP/UA for later filtering tests
        $_SERVER['REMOTE_ADDR'] = '10.99.99.1';
        $_SERVER['HTTP_USER_AGENT'] = 'FlowOneTestSuite/' . PHP_VERSION;
        unset($_SERVER['HTTP_REFERER']);

        $trackingId = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $trackingId;

        $recipientTokens = $trackingService->createTracking(
            $testEmail,
            $trackingId,
            $testSubject,
            [$recipientA, $recipientB, $recipientC]
        );

        assert_true(is_array($recipientTokens), 'createTracking should return array');
        assert_equals(3, count($recipientTokens), 'Should have 3 recipient tokens');
        vlog("trackingId = $trackingId");
    });

    test('Each recipient has a unique 32-char token', function () use ($recipientTokens, $recipientA, $recipientB, $recipientC) {
        $tokens = array_values($recipientTokens);
        assert_equals(3, count(array_unique($tokens)), 'All tokens should be unique');

        foreach ($tokens as $token) {
            assert_equals(32, strlen($token), 'Token length');
            assert_true(ctype_xdigit($token), "Token should be hex: $token");
        }

        assert_true(isset($recipientTokens[strtolower($recipientA)]), 'Token for Alice missing');
        assert_true(isset($recipientTokens[strtolower($recipientB)]), 'Token for Bob missing');
        assert_true(isset($recipientTokens[strtolower($recipientC)]), 'Token for Charlie missing');
    });

    test('email_tracking row created with correct data', function () use ($config, $trackingId, $testEmail, $testSubject) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT * FROM email_tracking WHERE tracking_id = ?');
        $stmt->execute([$trackingId]);
        $row = $stmt->fetch();

        assert_true($row !== false, 'Tracking row not found');
        assert_equals(strtolower($testEmail), $row['user_email'], 'user_email');
        assert_equals($testSubject, $row['subject'], 'subject');
        assert_equals('10.99.99.1', $row['sender_ip'], 'sender_ip');
        assert_not_empty($row['sender_ua_hash'], 'sender_ua_hash should be set');

        $recipients = json_decode($row['recipients'], true);
        assert_equals(3, count($recipients), 'recipients count');
        vlog("Stored sender_ip={$row['sender_ip']}, ua_hash={$row['sender_ua_hash']}");
    });

    test('email_tracking_recipients rows match', function () use ($config, $trackingId, $recipientTokens) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT recipient_email, recipient_token FROM email_tracking_recipients WHERE tracking_id = ?');
        $stmt->execute([$trackingId]);
        $rows = $stmt->fetchAll();

        assert_equals(3, count($rows), 'Should have 3 recipient rows');

        foreach ($rows as $row) {
            $expected = $recipientTokens[$row['recipient_email']] ?? null;
            assert_equals($expected, $row['recipient_token'], "Token mismatch for {$row['recipient_email']}");
        }
    });

    test('getSingleRecipientPixel generates correct HTML per recipient', function () use ($trackingService, $recipientTokens, $recipientA, $recipientB) {
        $baseUrl = 'https://mail.flowone.pro';
        $tokenA = $recipientTokens[strtolower($recipientA)];
        $tokenB = $recipientTokens[strtolower($recipientB)];

        $pixelA = $trackingService->getSingleRecipientPixel($tokenA, $baseUrl);
        $pixelB = $trackingService->getSingleRecipientPixel($tokenB, $baseUrl);

        assert_true(str_contains($pixelA, $tokenA), 'Pixel A should contain token A');
        assert_true(str_contains($pixelB, $tokenB), 'Pixel B should contain token B');
        assert_true($pixelA !== $pixelB, 'Each recipient should get a different pixel');
        assert_true(str_contains($pixelA, '/api/track/'), 'Pixel should use /api/track/ path');
        assert_true(str_contains($pixelA, '.gif'), 'Pixel URL should end with .gif');
        assert_true(str_contains($pixelA, 'width="1"'), 'Pixel should be 1x1');
        vlog("Pixel A: $pixelA");
    });

    test('getGenericTrackingPixel uses 64-char tracking_id', function () use ($trackingService, $trackingId) {
        $pixel = $trackingService->getGenericTrackingPixel($trackingId, 'https://mail.flowone.pro');
        assert_true(str_contains($pixel, $trackingId), 'Generic pixel should contain tracking_id');
        assert_true(str_contains($pixel, '/api/track/'), 'Generic pixel path');
    });

    test('getRecipientTokens returns stored tokens', function () use ($trackingService, $trackingId, $recipientTokens) {
        $fetched = $trackingService->getRecipientTokens($trackingId);
        assert_equals(count($recipientTokens), count($fetched), 'Token count');
        foreach ($recipientTokens as $email => $token) {
            assert_equals($token, $fetched[$email] ?? 'MISSING', "Token for $email");
        }
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: record -- Recording read events
// ══════════════════════════════════════════════════════════════════

if (shouldRun('record')) {
    out("\n=== Recording read events ===");

    // Ensure we have tracking data (re-create if running --only=record)
    if (empty($trackingId)) {
        $trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($config);
        $recipientA = 'alice_' . $runId . '@example.com';
        $recipientB = 'bob_' . $runId . '@example.com';
        $recipientC = 'charlie_' . $runId . '@example.com';
        $testSubject = "$TEST_TAG Multi-recipient test $runId";

        $_SERVER['REMOTE_ADDR'] = '10.99.99.1';
        $_SERVER['HTTP_USER_AGENT'] = 'FlowOneTestSuite/' . PHP_VERSION;
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $trackingId = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $trackingId;
        $recipientTokens = $trackingService->createTracking(
            $testEmail, $trackingId, $testSubject,
            [$recipientA, $recipientB, $recipientC]
        );
    }

    $trackingService = $trackingService ?? new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    test('recordReadEvent with recipient token records open for correct recipient', function () use (
        $trackingService, $recipientTokens, $recipientA, $trackingId, $config, &$cleanupNotificationIds
    ) {
        $tokenA = $recipientTokens[strtolower($recipientA)];

        // Simulate external reader with different IP
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 AliceMailClient';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $result = $trackingService->recordReadEvent($tokenA);
        assert_true($result, 'recordReadEvent should return true');

        // Verify in DB
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT * FROM email_read_events WHERE tracking_id = ? AND recipient_email = ?');
        $stmt->execute([$trackingId, strtolower($recipientA)]);
        $events = $stmt->fetchAll();

        assert_greater_than(0, count($events), 'Should have at least 1 read event for Alice');
        $event = $events[0];
        assert_equals('203.0.113.10', $event['ip_address'], 'IP address recorded');
        assert_true(str_contains($event['user_agent'], 'AliceMailClient'), 'User agent recorded');
        vlog("Read event ID: {$event['id']}, read_at: {$event['read_at']}");

        // Check notification was created
        $stmt = $db->prepare('SELECT * FROM notifications WHERE tracking_id = ? AND type = "read_receipt"');
        $stmt->execute([$trackingId]);
        $notif = $stmt->fetch();
        if ($notif) {
            $cleanupNotificationIds[] = $notif['id'];
            vlog("Notification created: ID {$notif['id']}");
        }
    });

    test('recordReadEvent for second recipient records separately', function () use (
        $trackingService, $recipientTokens, $recipientB, $trackingId, $config
    ) {
        $tokenB = $recipientTokens[strtolower($recipientB)];

        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 BobMailClient';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $result = $trackingService->recordReadEvent($tokenB);
        assert_true($result, 'recordReadEvent should return true for Bob');

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT * FROM email_read_events WHERE tracking_id = ? AND recipient_email = ?');
        $stmt->execute([$trackingId, strtolower($recipientB)]);
        $events = $stmt->fetchAll();

        assert_greater_than(0, count($events), 'Should have at least 1 event for Bob');
        assert_equals('198.51.100.20', $events[0]['ip_address'], 'Bob IP');
    });

    test('Third recipient (Charlie) has zero opens before we trigger', function () use ($config, $trackingId, $recipientC) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ? AND recipient_email = ?');
        $stmt->execute([$trackingId, strtolower($recipientC)]);
        $cnt = (int)$stmt->fetch()['cnt'];
        assert_equals(0, $cnt, 'Charlie should have 0 events');
    });

    test('Total read count is 2 (Alice + Bob, not Charlie)', function () use ($config, $trackingId) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ?');
        $stmt->execute([$trackingId]);
        $cnt = (int)$stmt->fetch()['cnt'];
        assert_equals(2, $cnt, 'Total events should be 2');
    });

    test('recordReadEvent with generic tracking_id (64-char) also works', function () use ($trackingService, $trackingId, $config) {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.50';
        $_SERVER['HTTP_USER_AGENT'] = 'GenericClient/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $result = $trackingService->recordReadEvent($trackingId);
        assert_true($result, 'recordReadEvent with tracking_id should return true');

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT * FROM email_read_events WHERE tracking_id = ? AND recipient_email IS NULL');
        $stmt->execute([$trackingId]);
        $events = $stmt->fetchAll();

        assert_greater_than(0, count($events), 'Should have event with null recipient_email');
        vlog("Generic read event recorded, IP: {$events[0]['ip_address']}");
    });

    test('Notification updated with multiple readers', function () use ($config, $trackingId, $testEmail) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT * FROM notifications WHERE tracking_id = ? AND type = "read_receipt" AND user_email = ?');
        $stmt->execute([$trackingId, strtolower($testEmail)]);
        $notif = $stmt->fetch();

        assert_true($notif !== false, 'Notification should exist');
        $readEvents = json_decode($notif['read_events'], true);
        assert_true(is_array($readEvents), 'read_events should be JSON array');
        assert_greater_than(1, count($readEvents), 'Should have multiple read events in notification');

        $data = json_decode($notif['data'], true);
        assert_true(isset($data['total_reads']), 'data should have total_reads');
        assert_greater_than(1, (int)$data['total_reads'], 'total_reads > 1');
        vlog("Notification total_reads={$data['total_reads']}, unique_readers={$data['unique_readers']}");
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: ratelimit -- Deduplication of rapid-fire opens
// ══════════════════════════════════════════════════════════════════

if (shouldRun('ratelimit')) {
    out("\n=== Rate limiting / deduplication ===");

    if (empty($trackingId)) {
        $trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($config);
        $recipientA = 'alice_' . $runId . '@example.com';
        $recipientB = 'bob_' . $runId . '@example.com';
        $recipientC = 'charlie_' . $runId . '@example.com';
        $testSubject = "$TEST_TAG Rate-limit test $runId";

        $_SERVER['REMOTE_ADDR'] = '10.99.99.1';
        $_SERVER['HTTP_USER_AGENT'] = 'FlowOneTestSuite/' . PHP_VERSION;
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $trackingId = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $trackingId;
        $recipientTokens = $trackingService->createTracking(
            $testEmail, $trackingId, $testSubject,
            [$recipientA, $recipientB, $recipientC]
        );
    }

    $trackingService = $trackingService ?? new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    test('Rapid-fire opens (same recipient, same IP, <30s) are deduplicated', function () use (
        $trackingService, $recipientTokens, $recipientC, $trackingId, $config
    ) {
        $tokenC = $recipientTokens[strtolower($recipientC)];

        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 CharlieClient';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        // First open -- should record
        $trackingService->recordReadEvent($tokenC);

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ? AND recipient_email = ?');
        $stmt->execute([$trackingId, strtolower($recipientC)]);
        $countAfterFirst = (int)$stmt->fetch()['cnt'];

        // Rapid-fire: 3 more opens immediately (same IP)
        $trackingService->recordReadEvent($tokenC);
        $trackingService->recordReadEvent($tokenC);
        $trackingService->recordReadEvent($tokenC);

        $stmt->execute([$trackingId, strtolower($recipientC)]);
        $countAfterRapid = (int)$stmt->fetch()['cnt'];

        assert_equals($countAfterFirst, $countAfterRapid,
            "Rapid-fire should be deduped: first=$countAfterFirst, after=$countAfterRapid");
        vlog("After first open: $countAfterFirst, after 3 rapid: $countAfterRapid (deduped)");
    });

    test('Open from different IP within 30s is NOT deduped', function () use (
        $trackingService, $recipientTokens, $recipientC, $trackingId, $config
    ) {
        $tokenC = $recipientTokens[strtolower($recipientC)];

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ? AND recipient_email = ?');
        $stmt->execute([$trackingId, strtolower($recipientC)]);
        $countBefore = (int)$stmt->fetch()['cnt'];

        // Different IP -> should NOT be deduped
        $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 CharlieClient';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $trackingService->recordReadEvent($tokenC);

        $stmt->execute([$trackingId, strtolower($recipientC)]);
        $countAfter = (int)$stmt->fetch()['cnt'];

        assert_equals($countBefore + 1, $countAfter,
            "Different IP should record: before=$countBefore, after=$countAfter");
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: filter -- Sender self-read filtering
// ══════════════════════════════════════════════════════════════════

if (shouldRun('filter')) {
    out("\n=== Sender self-read filtering ===");

    $trackingService = $trackingService ?? new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    // Create a fresh tracking record for filter tests with known sender IP/UA
    $filterTrackingId = null;
    $filterRecipient = 'filter_tester_' . $runId . '@example.com';
    $filterSubject = "$TEST_TAG Filter test $runId";
    $filterTokens = [];

    test('Setup: create tracking for filter tests', function () use (
        $trackingService, $testEmail, $filterSubject, $filterRecipient,
        &$filterTrackingId, &$filterTokens, &$cleanupTrackingIds
    ) {
        $_SERVER['REMOTE_ADDR'] = '10.50.50.1';
        $_SERVER['HTTP_USER_AGENT'] = 'SenderBrowser/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $filterTrackingId = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $filterTrackingId;
        $filterTokens = $trackingService->createTracking(
            $testEmail, $filterTrackingId, $filterSubject, [$filterRecipient]
        );
        assert_not_empty($filterTokens, 'Filter tokens should exist');
    });

    test('Open from sender IP is filtered (skipped)', function () use (
        $trackingService, $filterTrackingId, $filterTokens, $filterRecipient, $config
    ) {
        $token = $filterTokens[strtolower($filterRecipient)];

        // Use the SAME IP as the sender
        $_SERVER['REMOTE_ADDR'] = '10.50.50.1';
        $_SERVER['HTTP_USER_AGENT'] = 'DifferentBrowser/2.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $result = $trackingService->recordReadEvent($token);
        // Should return true (handled gracefully) but NOT insert
        assert_true($result, 'Should return true even when filtered');

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ?');
        $stmt->execute([$filterTrackingId]);
        $cnt = (int)$stmt->fetch()['cnt'];
        assert_equals(0, $cnt, 'No read events should be recorded for sender IP');
        vlog("Sender IP filter: 0 events recorded (correct)");
    });

    test('Open from different IP records successfully', function () use (
        $trackingService, $filterTrackingId, $filterTokens, $filterRecipient, $config
    ) {
        $token = $filterTokens[strtolower($filterRecipient)];

        $_SERVER['REMOTE_ADDR'] = '203.0.113.55';
        $_SERVER['HTTP_USER_AGENT'] = 'ExternalReader/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $result = $trackingService->recordReadEvent($token);
        assert_true($result, 'Should record');

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ?');
        $stmt->execute([$filterTrackingId]);
        $cnt = (int)$stmt->fetch()['cnt'];
        assert_equals(1, $cnt, 'Should have exactly 1 read event from external IP');
    });

    test('Open from same-domain referer is filtered', function () use (
        $trackingService, $filterTrackingId, $filterTokens, $filterRecipient, $config
    ) {
        $token = $filterTokens[strtolower($filterRecipient)];

        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
        $_SERVER['HTTP_USER_AGENT'] = 'YetAnotherBrowser/3.0';
        $_SERVER['HTTP_REFERER'] = 'https://mail.flowone.pro/inbox';
        $_SERVER['HTTP_HOST'] = 'mail.flowone.pro';

        $result = $trackingService->recordReadEvent($token);
        assert_true($result, 'Should return true when filtered by referer');

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ?');
        $stmt->execute([$filterTrackingId]);
        $cnt = (int)$stmt->fetch()['cnt'];
        // Should still be 1 (from the external IP test), not 2
        assert_equals(1, $cnt, 'Same-domain referer should be filtered');
        vlog("Same-domain referer filter working correctly");

        // Cleanup $_SERVER
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
    });

    test('Open where recipient = sender email is filtered', function () use (
        $trackingService, $testEmail, $config, &$cleanupTrackingIds
    ) {
        // Create a tracking where the sender is also a recipient
        $_SERVER['REMOTE_ADDR'] = '10.60.60.1';
        $_SERVER['HTTP_USER_AGENT'] = 'SelfSender/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $selfTrackingId = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $selfTrackingId;

        $selfTokens = $trackingService->createTracking(
            $testEmail, $selfTrackingId, "$TEST_TAG Self-send $runId", [$testEmail]
        );

        // Now "open" from a completely different IP (so IP filter won't trigger)
        $_SERVER['REMOTE_ADDR'] = '203.0.113.88';
        $_SERVER['HTTP_USER_AGENT'] = 'ExternalClient/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $token = $selfTokens[strtolower($testEmail)];
        $result = $trackingService->recordReadEvent($token);

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ?');
        $stmt->execute([$selfTrackingId]);
        $cnt = (int)$stmt->fetch()['cnt'];
        assert_equals(0, $cnt, 'Sender-as-recipient should be filtered');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: link -- Link rewriting & click tracking
// ══════════════════════════════════════════════════════════════════

if (shouldRun('link')) {
    out("\n=== Link rewriting & click tracking ===");

    $trackingService = $trackingService ?? new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    $linkTrackingId = null;
    $linkRecipient = 'linktest_' . $runId . '@example.com';
    $linkSubject = "$TEST_TAG Link test $runId";
    $linkTokens = [];
    $linkRecipientToken = null;

    test('Setup: create tracking for link tests', function () use (
        $trackingService, $testEmail, $linkSubject, $linkRecipient,
        &$linkTrackingId, &$linkTokens, &$linkRecipientToken, &$cleanupTrackingIds
    ) {
        $_SERVER['REMOTE_ADDR'] = '10.70.70.1';
        $_SERVER['HTTP_USER_AGENT'] = 'LinkTestSender/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $linkTrackingId = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $linkTrackingId;
        $linkTokens = $trackingService->createTracking(
            $testEmail, $linkTrackingId, $linkSubject, [$linkRecipient]
        );
        $linkRecipientToken = $linkTokens[strtolower($linkRecipient)];
        assert_not_empty($linkRecipientToken, 'Link recipient token');
    });

    $rewrittenHtml = '';

    test('rewriteLinks rewrites http links in HTML', function () use (
        $trackingService, $linkTrackingId, $linkRecipientToken, &$rewrittenHtml
    ) {
        $html = '<html><body>'
            . '<a href="https://example.com/page1">Link 1</a>'
            . '<a href="https://example.com/page2">Link 2</a>'
            . '<a href="mailto:test@test.com">Mail</a>'
            . '<a href="tel:+1234567890">Phone</a>'
            . '<a href="#section">Anchor</a>'
            . '</body></html>';

        $rewrittenHtml = $trackingService->rewriteLinks(
            $html, $linkTrackingId, $linkRecipientToken, 'https://mail.flowone.pro'
        );

        // http links should be rewritten
        assert_true(!str_contains($rewrittenHtml, 'href="https://example.com/page1"'),
            'page1 link should be rewritten');
        assert_true(!str_contains($rewrittenHtml, 'href="https://example.com/page2"'),
            'page2 link should be rewritten');
        assert_true(str_contains($rewrittenHtml, '/api/click/'), 'Should have click tracking URL');

        // mailto, tel, # should NOT be rewritten
        assert_true(str_contains($rewrittenHtml, 'href="mailto:test@test.com"'),
            'mailto should not be rewritten');
        assert_true(str_contains($rewrittenHtml, 'href="tel:+1234567890"'),
            'tel should not be rewritten');
        assert_true(str_contains($rewrittenHtml, 'href="#section"'),
            'anchor should not be rewritten');

        vlog("Rewritten HTML length: " . strlen($rewrittenHtml));
    });

    test('email_link_tracking rows created for rewritten links', function () use ($config, $linkTrackingId) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT * FROM email_link_tracking WHERE tracking_id = ? ORDER BY link_index');
        $stmt->execute([$linkTrackingId]);
        $links = $stmt->fetchAll();

        assert_equals(2, count($links), 'Should have 2 tracked links (page1 + page2)');
        assert_equals('https://example.com/page1', $links[0]['original_url'], 'First link URL');
        assert_equals('https://example.com/page2', $links[1]['original_url'], 'Second link URL');
        assert_equals(0, (int)$links[0]['link_index'], 'First link index');
        assert_equals(1, (int)$links[1]['link_index'], 'Second link index');
        vlog("Link tokens: {$links[0]['link_token']}, {$links[1]['link_token']}");
    });

    test('recordClickEvent records click and returns original URL', function () use (
        $trackingService, $config, $linkTrackingId, $linkRecipientToken, &$cleanupNotificationIds
    ) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT link_token FROM email_link_tracking WHERE tracking_id = ? ORDER BY link_index LIMIT 1');
        $stmt->execute([$linkTrackingId]);
        $linkToken = $stmt->fetch()['link_token'];

        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        $_SERVER['HTTP_USER_AGENT'] = 'ClickerBrowser/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $originalUrl = $trackingService->recordClickEvent($linkToken, $linkRecipientToken);
        assert_equals('https://example.com/page1', $originalUrl, 'Should return original URL');

        // Verify click event in DB
        $stmt = $db->prepare('SELECT * FROM email_click_events WHERE link_token = ?');
        $stmt->execute([$linkToken]);
        $clicks = $stmt->fetchAll();
        assert_greater_than(0, count($clicks), 'Should have at least 1 click');
        assert_equals('203.0.113.42', $clicks[0]['ip_address'], 'Click IP');

        // Check click notification
        $stmt = $db->prepare('SELECT * FROM notifications WHERE tracking_id = ? AND type = "link_click"');
        $stmt->execute([$linkTrackingId]);
        $notif = $stmt->fetch();
        if ($notif) {
            $cleanupNotificationIds[] = $notif['id'];
            vlog("Click notification: ID {$notif['id']}");
        }
    });

    test('Rapid-fire clicks (<5s, same link+recipient+IP) are deduped', function () use (
        $trackingService, $config, $linkTrackingId, $linkRecipientToken
    ) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT link_token FROM email_link_tracking WHERE tracking_id = ? ORDER BY link_index LIMIT 1');
        $stmt->execute([$linkTrackingId]);
        $linkToken = $stmt->fetch()['link_token'];

        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        $_SERVER['HTTP_USER_AGENT'] = 'ClickerBrowser/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_click_events WHERE link_token = ?');
        $stmt->execute([$linkToken]);
        $countBefore = (int)$stmt->fetch()['cnt'];

        // Rapid-fire clicks
        $trackingService->recordClickEvent($linkToken, $linkRecipientToken);
        $trackingService->recordClickEvent($linkToken, $linkRecipientToken);

        $stmt->execute([$linkToken]);
        $countAfter = (int)$stmt->fetch()['cnt'];

        assert_equals($countBefore, $countAfter,
            "Rapid-fire clicks should be deduped: before=$countBefore, after=$countAfter");
    });

    test('Click from different IP is NOT deduped', function () use (
        $trackingService, $config, $linkTrackingId, $linkRecipientToken
    ) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT link_token FROM email_link_tracking WHERE tracking_id = ? ORDER BY link_index LIMIT 1');
        $stmt->execute([$linkTrackingId]);
        $linkToken = $stmt->fetch()['link_token'];

        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_click_events WHERE link_token = ?');
        $stmt->execute([$linkToken]);
        $countBefore = (int)$stmt->fetch()['cnt'];

        $_SERVER['REMOTE_ADDR'] = '198.51.100.33';
        $_SERVER['HTTP_USER_AGENT'] = 'AnotherBrowser/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $trackingService->recordClickEvent($linkToken, $linkRecipientToken);

        $stmt->execute([$linkToken]);
        $countAfter = (int)$stmt->fetch()['cnt'];

        assert_equals($countBefore + 1, $countAfter, 'Different IP click should record');
    });

    test('getClickStats returns correct aggregate', function () use ($trackingService, $linkTrackingId) {
        $stats = $trackingService->getClickStats($linkTrackingId);
        assert_true(is_array($stats), 'Should return array');
        assert_equals(2, count($stats), 'Should have 2 links');

        $firstLink = $stats[0];
        assert_greater_than(0, (int)$firstLink['click_count'], 'First link should have clicks');
        assert_equals('https://example.com/page1', $firstLink['original_url'], 'URL match');
        vlog("Link 1 clicks: {$firstLink['click_count']}, unique clickers: {$firstLink['unique_clickers']}");
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: notify -- Notification accuracy
// ══════════════════════════════════════════════════════════════════

if (shouldRun('notify')) {
    out("\n=== Notification accuracy ===");

    $trackingService = $trackingService ?? new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    $notifyTrackingId = null;
    $notifyRecipientA = 'notifyA_' . $runId . '@example.com';
    $notifyRecipientB = 'notifyB_' . $runId . '@example.com';
    $notifySubject = "$TEST_TAG Notification test $runId";
    $notifyTokens = [];

    test('Setup: create tracking for notification tests', function () use (
        $trackingService, $testEmail, $notifySubject, $notifyRecipientA, $notifyRecipientB,
        &$notifyTrackingId, &$notifyTokens, &$cleanupTrackingIds
    ) {
        $_SERVER['REMOTE_ADDR'] = '10.80.80.1';
        $_SERVER['HTTP_USER_AGENT'] = 'NotifyTestSender/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $notifyTrackingId = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $notifyTrackingId;
        $notifyTokens = $trackingService->createTracking(
            $testEmail, $notifyTrackingId, $notifySubject,
            [$notifyRecipientA, $notifyRecipientB]
        );
        assert_equals(2, count($notifyTokens), 'Should have 2 tokens');
    });

    test('First open creates a notification with type=read_receipt', function () use (
        $trackingService, $notifyTokens, $notifyRecipientA, $notifyTrackingId, $testEmail, $config,
        &$cleanupNotificationIds
    ) {
        $token = $notifyTokens[strtolower($notifyRecipientA)];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.60';
        $_SERVER['HTTP_USER_AGENT'] = 'NotifyReaderA/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $trackingService->recordReadEvent($token);

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT * FROM notifications WHERE tracking_id = ? AND type = "read_receipt" AND user_email = ?');
        $stmt->execute([$notifyTrackingId, strtolower($testEmail)]);
        $notif = $stmt->fetch();

        assert_true($notif !== false, 'Notification should be created');
        $cleanupNotificationIds[] = $notif['id'];

        $readEvents = json_decode($notif['read_events'], true);
        assert_equals(1, count($readEvents), 'Should have 1 read event');
        assert_equals(strtolower($notifyRecipientA), $readEvents[0]['reader_email'], 'Reader email');

        $data = json_decode($notif['data'], true);
        assert_equals(1, (int)$data['total_reads'], 'total_reads = 1');
        assert_equals(1, (int)$data['unique_readers'], 'unique_readers = 1');
    });

    test('Second recipient open updates the same notification', function () use (
        $trackingService, $notifyTokens, $notifyRecipientB, $notifyTrackingId, $testEmail, $config
    ) {
        $token = $notifyTokens[strtolower($notifyRecipientB)];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.70';
        $_SERVER['HTTP_USER_AGENT'] = 'NotifyReaderB/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $trackingService->recordReadEvent($token);

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT * FROM notifications WHERE tracking_id = ? AND type = "read_receipt" AND user_email = ?');
        $stmt->execute([$notifyTrackingId, strtolower($testEmail)]);
        $notif = $stmt->fetch();

        $readEvents = json_decode($notif['read_events'], true);
        assert_equals(2, count($readEvents), 'Should have 2 read events now');

        $data = json_decode($notif['data'], true);
        assert_equals(2, (int)$data['total_reads'], 'total_reads = 2');
        assert_equals(2, (int)$data['unique_readers'], 'unique_readers = 2');

        // Last reader should be recipient B
        assert_equals(strtolower($notifyRecipientB), $data['last_reader_email'], 'last_reader_email');
        vlog("Notification updated: total={$data['total_reads']}, unique={$data['unique_readers']}");
    });

    test('Only one notification row exists per tracking_id (no duplicates)', function () use (
        $config, $notifyTrackingId, $testEmail
    ) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM notifications WHERE tracking_id = ? AND type = "read_receipt" AND user_email = ?');
        $stmt->execute([$notifyTrackingId, strtolower($testEmail)]);
        $cnt = (int)$stmt->fetch()['cnt'];
        assert_equals(1, $cnt, 'Should have exactly 1 notification row');
    });

    test('Redis publishes NOTIFICATION_CREATED (insert then update, same notification id)', function () use (
        $config, $testEmail, $trackingService, $runId, &$cleanupTrackingIds, &$cleanupNotificationIds
    ) {
        if (!extension_loaded('redis')) {
            return 'warn';
        }
        if (!function_exists('pcntl_fork') || PHP_OS_FAMILY === 'Windows') {
            return 'warn';
        }

        $prefix = $config['redis']['prefix'] ?? 'webmail:';
        $channel = $prefix . 'mailbox:' . strtolower($testEmail);
        $host = $config['redis']['host'] ?? '127.0.0.1';
        $port = (int)($config['redis']['port'] ?? 6379);
        $password = $config['redis']['password'] ?? null;

        $tmpFile = sys_get_temp_dir() . '/fo-tracking-redis-' . $runId . '-' . bin2hex(random_bytes(4)) . '.json';
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            return 'warn';
        }

        if ($pid === 0) {
            try {
                $sub = new \Redis();
                $sub->connect($host, $port, 5.0);
                if ($password) {
                    $sub->auth($password);
                }
                $received = [];
                $sub->subscribe([$channel], function ($redis, $ch, $msg) use (&$received, $tmpFile) {
                    $received[] = $msg;
                    @file_put_contents($tmpFile, json_encode($received), LOCK_EX);
                    if (count($received) >= 2) {
                        $redis->unsubscribe([$ch]);
                    }
                });
            } catch (\Throwable $e) {
                @file_put_contents($tmpFile, json_encode(['error' => $e->getMessage()]), LOCK_EX);
            }
            exit(0);
        }

        try {
            usleep(250000);

            $_SERVER['REMOTE_ADDR'] = '10.91.91.1';
            $_SERVER['HTTP_USER_AGENT'] = 'RedisPubTestSender/1.0';
            unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

            $pubTid = $trackingService->generateTrackingId();
            $cleanupTrackingIds[] = $pubTid;
            $rEmail = 'redispub_' . $runId . '@example.com';
            $tokens = $trackingService->createTracking(
                $testEmail,
                $pubTid,
                "$TEST_TAG Redis pub $runId",
                [$rEmail]
            );
            $token = $tokens[strtolower($rEmail)];

            $_SERVER['REMOTE_ADDR'] = '203.0.113.201';
            $_SERVER['HTTP_USER_AGENT'] = 'RedisPubReader/1.0';
            unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
            $trackingService->recordReadEvent($token);

            $_SERVER['REMOTE_ADDR'] = '198.51.100.201';
            $_SERVER['HTTP_USER_AGENT'] = 'RedisPubReader/1.0';
            unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
            $trackingService->recordReadEvent($token);

            $deadline = microtime(true) + 8;
            $rawList = [];
            while (microtime(true) < $deadline) {
                if (is_file($tmpFile)) {
                    $rawList = json_decode((string)file_get_contents($tmpFile), true) ?: [];
                    if (isset($rawList['error'])) {
                        throw new \RuntimeException('Subscriber error: ' . $rawList['error']);
                    }
                    if (count($rawList) >= 2) {
                        break;
                    }
                }
                usleep(100000);
            }

            if (count($rawList) < 2) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, SIGKILL);
                }
                throw new \RuntimeException('Expected 2 Redis messages on ' . $channel . ', got ' . count($rawList));
            }

            $msg0 = json_decode($rawList[0], true);
            $msg1 = json_decode($rawList[1], true);
            assert_equals('NOTIFICATION_CREATED', $msg0['type'] ?? '', 'msg0 type');
            $p0 = $msg0['payload'] ?? [];
            assert_equals('read_receipt', $p0['type'] ?? '', 'msg0 payload.type');
            assert_true(array_key_exists('is_update', $p0) && $p0['is_update'] === false, 'First publish is_update=false');
            assert_true(!empty($p0['last_read_at'] ?? null), 'msg0 payload.last_read_at present');
            assert_true(strtotime((string)($p0['last_read_at'] ?? '')) !== false, 'msg0 last_read_at parseable');

            assert_equals('NOTIFICATION_CREATED', $msg1['type'] ?? '', 'msg1 type');
            $p1 = $msg1['payload'] ?? [];
            assert_equals('read_receipt', $p1['type'] ?? '', 'msg1 payload.type');
            assert_true(array_key_exists('is_update', $p1) && $p1['is_update'] === true, 'Second publish is_update=true');
            assert_true(!empty($p1['last_read_at'] ?? null), 'msg1 payload.last_read_at present');
            assert_true(strtotime((string)($p1['last_read_at'] ?? '')) !== false, 'msg1 last_read_at parseable');
            assert_equals($p0['id'] ?? -1, $p1['id'] ?? -2, 'Same notification id for both messages');

            $db = \Webmail\Core\Database::getConnection($config);
            $stmt = $db->prepare('SELECT id FROM notifications WHERE tracking_id = ? AND type = "read_receipt"');
            $stmt->execute([$pubTid]);
            $row = $stmt->fetch();
            if ($row) {
                $cleanupNotificationIds[] = $row['id'];
            }
        } finally {
            @unlink($tmpFile);
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
            if (function_exists('pcntl_waitpid')) {
                pcntl_waitpid($pid, $status);
            }
        }
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: clear -- Scoped clearAllNotifications (email/campaigns/general/all)
// ══════════════════════════════════════════════════════════════════

if (shouldRun('clear')) {
    out("\n=== Scoped Clear All ===");

    $trackingService = $trackingService ?? new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    // Use a SYNTHETIC test user so clearAllNotifications cannot wipe the real account.
    // Service operates on user_email, and we feed it a unique address per run.
    $clearUser = 'clearscope_' . $runId . '@flowone-test.invalid';

    $insertNotif = function (string $type, string $title, ?int $campaignId) use ($config, $clearUser, $TEST_TAG): int {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('
            INSERT INTO notifications (user_email, type, title, message, data, campaign_id, is_read, pinned, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW())
        ');
        $stmt->execute([
            $clearUser,
            $type,
            $TEST_TAG . ' ' . $title,
            'test message',
            json_encode([]),
            $campaignId,
        ]);
        return (int)$db->lastInsertId();
    };

    $countByType = function () use ($config, $clearUser): array {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT type, campaign_id FROM notifications WHERE user_email = ?');
        $stmt->execute([$clearUser]);
        $email = 0; $campaign = 0; $general = 0;
        foreach ($stmt->fetchAll() as $row) {
            $t = $row['type'];
            $hasCampaign = !empty($row['campaign_id']);
            if (in_array($t, ['read_receipt', 'link_click'], true)) {
                if ($hasCampaign) $campaign++;
                else $email++;
            } else {
                $general++;
            }
        }
        return ['email' => $email, 'campaign' => $campaign, 'general' => $general];
    };

    // Always wipe the synthetic user's notifications at the end, even on failure
    $clearScopeCleanup = function () use ($config, $clearUser) {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare('DELETE FROM notifications WHERE user_email = ?')->execute([$clearUser]);
    };
    register_shutdown_function($clearScopeCleanup);

    test('Setup: seed 1 email + 1 campaign + 1 general notification', function () use ($insertNotif, $countByType) {
        $insertNotif('read_receipt', 'email row',     null);
        $insertNotif('read_receipt', 'campaign row', 99999);
        $insertNotif('board_invite', 'general row',   null);

        $c = $countByType();
        assert_equals(1, $c['email'],    'Should have 1 email notif');
        assert_equals(1, $c['campaign'], 'Should have 1 campaign notif');
        assert_equals(1, $c['general'],  'Should have 1 general notif');
    });

    test('Clear scope=email removes only email-tab notifications', function () use ($trackingService, $clearUser, $countByType) {
        $deleted = $trackingService->clearAllNotifications($clearUser, 'email');
        assert_equals(1, $deleted, 'Should delete exactly 1 row');
        $c = $countByType();
        assert_equals(0, $c['email'],    'No email notifs left');
        assert_equals(1, $c['campaign'], 'Campaign notif still present');
        assert_equals(1, $c['general'],  'General notif still present');
    });

    test('Clear scope=campaigns removes only campaign notifications', function () use ($trackingService, $clearUser, $countByType) {
        $deleted = $trackingService->clearAllNotifications($clearUser, 'campaigns');
        assert_equals(1, $deleted, 'Should delete exactly 1 row');
        $c = $countByType();
        assert_equals(0, $c['email'],    'Still 0 email notifs');
        assert_equals(0, $c['campaign'], 'No campaign notifs left');
        assert_equals(1, $c['general'],  'General notif still present');
    });

    test('Clear scope=general removes only general notifications', function () use ($trackingService, $clearUser, $countByType) {
        $deleted = $trackingService->clearAllNotifications($clearUser, 'general');
        assert_equals(1, $deleted, 'Should delete exactly 1 row');
        $c = $countByType();
        assert_equals(0, $c['email'],    'Still 0 email notifs');
        assert_equals(0, $c['campaign'], 'Still 0 campaign notifs');
        assert_equals(0, $c['general'],  'No general notifs left');
    });

    test('Clear with no scope (null) removes everything for the user', function () use (
        $trackingService, $insertNotif, $clearUser, $countByType
    ) {
        $insertNotif('read_receipt', 'reseed email',     null);
        $insertNotif('read_receipt', 'reseed campaign', 88888);
        $insertNotif('board_invite', 'reseed general',   null);

        $deleted = $trackingService->clearAllNotifications($clearUser, null);
        assert_equals(3, $deleted, 'Should delete all 3 reseeded rows');
        $c = $countByType();
        assert_equals(0, $c['email'] + $c['campaign'] + $c['general'], 'User has 0 notifications left');
    });

    test('Clear with explicit scope=all behaves like null', function () use (
        $trackingService, $insertNotif, $clearUser, $countByType
    ) {
        $insertNotif('read_receipt', 'reseed2 email',     null);
        $insertNotif('board_invite', 'reseed2 general',   null);

        $deleted = $trackingService->clearAllNotifications($clearUser, 'all');
        assert_equals(2, $deleted, 'Should delete both rows when scope=all');
        $c = $countByType();
        assert_equals(0, $c['email'] + $c['campaign'] + $c['general'], 'User has 0 notifications left');
    });

    // Explicit cleanup (shutdown handler is a safety net)
    $clearScopeCleanup();
}

// ══════════════════════════════════════════════════════════════════
// GROUP: query -- Querying / aggregation
// ══════════════════════════════════════════════════════════════════

if (shouldRun('query')) {
    out("\n=== Querying & aggregation ===");

    $trackingService = $trackingService ?? new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    // Create a fresh tracking with known data
    $queryTrackingId = null;
    $queryRecipientA = 'queryA_' . $runId . '@example.com';
    $queryRecipientB = 'queryB_' . $runId . '@example.com';
    $querySubject = "$TEST_TAG Query test $runId";
    $queryTokens = [];

    test('Setup: create tracking + 2 opens for query tests', function () use (
        $trackingService, $testEmail, $querySubject, $queryRecipientA, $queryRecipientB,
        &$queryTrackingId, &$queryTokens, &$cleanupTrackingIds, &$cleanupNotificationIds, $config
    ) {
        $_SERVER['REMOTE_ADDR'] = '10.90.90.1';
        $_SERVER['HTTP_USER_AGENT'] = 'QueryTestSender/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $queryTrackingId = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $queryTrackingId;
        $queryTokens = $trackingService->createTracking(
            $testEmail, $queryTrackingId, $querySubject,
            [$queryRecipientA, $queryRecipientB]
        );

        // Simulate opens
        $_SERVER['REMOTE_ADDR'] = '203.0.113.71';
        $_SERVER['HTTP_USER_AGENT'] = 'QueryReaderA/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
        $trackingService->recordReadEvent($queryTokens[strtolower($queryRecipientA)]);

        $_SERVER['REMOTE_ADDR'] = '198.51.100.81';
        $_SERVER['HTTP_USER_AGENT'] = 'QueryReaderB/1.0';
        $trackingService->recordReadEvent($queryTokens[strtolower($queryRecipientB)]);

        // Track notification for cleanup
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT id FROM notifications WHERE tracking_id = ? AND user_email = ?');
        $stmt->execute([$queryTrackingId, strtolower($testEmail)]);
        while ($row = $stmt->fetch()) {
            $cleanupNotificationIds[] = $row['id'];
        }
    });

    test('getTracking returns full details with read_count', function () use (
        $trackingService, $testEmail, $queryTrackingId, $querySubject
    ) {
        $tracking = $trackingService->getTracking($testEmail, $queryTrackingId);
        assert_true($tracking !== null, 'getTracking should return data');
        assert_equals($querySubject, $tracking['subject'], 'Subject match');
        assert_equals(2, (int)$tracking['read_count'], 'read_count should be 2');
        assert_not_empty($tracking['first_read_at'], 'first_read_at should be set');
        assert_true(is_array($tracking['read_events']), 'read_events should be array');
        assert_equals(2, count($tracking['read_events']), 'Should have 2 read event rows');
        assert_true(is_array($tracking['recipients']), 'recipients should be decoded array');
        assert_equals(2, count($tracking['recipients']), 'Should have 2 recipients');
        vlog("read_count={$tracking['read_count']}, first_read={$tracking['first_read_at']}");
    });

    test('getTrackedEmails includes our test email', function () use ($trackingService, $testEmail, $queryTrackingId) {
        $list = $trackingService->getTrackedEmails($testEmail, 100);
        assert_true(is_array($list), 'Should return array');

        $found = false;
        foreach ($list as $item) {
            if ($item['tracking_id'] === $queryTrackingId) {
                $found = true;
                assert_equals(2, (int)$item['read_count'], 'read_count in list');
                break;
            }
        }
        assert_true($found, "queryTrackingId should be in the list");
    });

    test('getTracking read_events contain correct IPs per recipient', function () use (
        $trackingService, $testEmail, $queryTrackingId, $queryRecipientA, $queryRecipientB
    ) {
        $tracking = $trackingService->getTracking($testEmail, $queryTrackingId);
        $events = $tracking['read_events'];

        $ips = array_column($events, 'ip_address');
        $emails = array_column($events, 'recipient_email');

        assert_true(in_array('203.0.113.71', $ips), 'Should have Alice IP');
        assert_true(in_array('198.51.100.81', $ips), 'Should have Bob IP');
        assert_true(in_array(strtolower($queryRecipientA), $emails), 'Should have Alice email');
        assert_true(in_array(strtolower($queryRecipientB), $emails), 'Should have Bob email');
    });

    test('getTracking for non-existent tracking_id returns null', function () use ($trackingService, $testEmail) {
        $result = $trackingService->getTracking($testEmail, 'nonexistent_tracking_id_000000000000000');
        assert_true($result === null, 'Should return null for invalid ID');
    });

    test('getTracking for wrong user returns null (ownership check)', function () use (
        $trackingService, $queryTrackingId
    ) {
        $result = $trackingService->getTracking('wrong_user@nowhere.com', $queryTrackingId);
        assert_true($result === null, 'Should return null for wrong user');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: multi -- Multiple separate emails, isolated tracking
// ══════════════════════════════════════════════════════════════════

if (shouldRun('multi')) {
    out("\n=== Multiple emails, isolated tracking ===");

    $trackingService = $trackingService ?? new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    $multiTrackingId1 = null;
    $multiTrackingId2 = null;
    $multiRecipient = 'multi_' . $runId . '@example.com';
    $multiTokens1 = [];
    $multiTokens2 = [];

    test('Setup: create 2 separate tracked emails to same recipient', function () use (
        $trackingService, $testEmail, $multiRecipient,
        &$multiTrackingId1, &$multiTrackingId2, &$multiTokens1, &$multiTokens2,
        &$cleanupTrackingIds, &$cleanupNotificationIds, $config, $runId, $TEST_TAG
    ) {
        $_SERVER['REMOTE_ADDR'] = '10.100.100.1';
        $_SERVER['HTTP_USER_AGENT'] = 'MultiTestSender/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $multiTrackingId1 = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $multiTrackingId1;
        $multiTokens1 = $trackingService->createTracking(
            $testEmail, $multiTrackingId1, "$TEST_TAG Email-1 $runId", [$multiRecipient]
        );

        $multiTrackingId2 = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $multiTrackingId2;
        $multiTokens2 = $trackingService->createTracking(
            $testEmail, $multiTrackingId2, "$TEST_TAG Email-2 $runId", [$multiRecipient]
        );

        assert_true($multiTrackingId1 !== $multiTrackingId2, 'Tracking IDs should differ');
        $t1 = $multiTokens1[strtolower($multiRecipient)];
        $t2 = $multiTokens2[strtolower($multiRecipient)];
        assert_true($t1 !== $t2, 'Same recipient gets different tokens per email');
        vlog("Email-1 token: $t1, Email-2 token: $t2");
    });

    test('Opening Email-1 does NOT affect Email-2 count', function () use (
        $trackingService, $multiTokens1, $multiRecipient, $multiTrackingId1, $multiTrackingId2, $config
    ) {
        $token1 = $multiTokens1[strtolower($multiRecipient)];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.91';
        $_SERVER['HTTP_USER_AGENT'] = 'MultiReader/1.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $trackingService->recordReadEvent($token1);

        $db = \Webmail\Core\Database::getConnection($config);

        // Email-1 should have 1 event
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ?');
        $stmt->execute([$multiTrackingId1]);
        $cnt1 = (int)$stmt->fetch()['cnt'];
        assert_equals(1, $cnt1, 'Email-1 should have 1 event');

        // Email-2 should have 0 events
        $stmt->execute([$multiTrackingId2]);
        $cnt2 = (int)$stmt->fetch()['cnt'];
        assert_equals(0, $cnt2, 'Email-2 should have 0 events');
    });

    test('Opening Email-2 records separately', function () use (
        $trackingService, $multiTokens2, $multiRecipient, $multiTrackingId1, $multiTrackingId2, $config,
        &$cleanupNotificationIds
    ) {
        $token2 = $multiTokens2[strtolower($multiRecipient)];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.92';
        $_SERVER['HTTP_USER_AGENT'] = 'MultiReader/2.0';
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);

        $trackingService->recordReadEvent($token2);

        $db = \Webmail\Core\Database::getConnection($config);

        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM email_read_events WHERE tracking_id = ?');
        $stmt->execute([$multiTrackingId1]);
        $cnt1 = (int)$stmt->fetch()['cnt'];
        assert_equals(1, $cnt1, 'Email-1 still has 1');

        $stmt->execute([$multiTrackingId2]);
        $cnt2 = (int)$stmt->fetch()['cnt'];
        assert_equals(1, $cnt2, 'Email-2 now has 1');

        // Cleanup notifications
        $stmt = $db->prepare('SELECT id FROM notifications WHERE tracking_id IN (?, ?)');
        $stmt->execute([$multiTrackingId1, $multiTrackingId2]);
        while ($row = $stmt->fetch()) {
            $cleanupNotificationIds[] = $row['id'];
        }
    });

    test('Each email has independent getTracking read_count', function () use (
        $trackingService, $testEmail, $multiTrackingId1, $multiTrackingId2
    ) {
        $t1 = $trackingService->getTracking($testEmail, $multiTrackingId1);
        $t2 = $trackingService->getTracking($testEmail, $multiTrackingId2);

        assert_equals(1, (int)$t1['read_count'], 'Email-1 read_count=1');
        assert_equals(1, (int)$t2['read_count'], 'Email-2 read_count=1');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: locate -- Resolve a tracking record to folder + uid
// (drives "open the email from a notification" regardless of age/folder)
// ══════════════════════════════════════════════════════════════════

if (shouldRun('locate')) {
    out("\n=== Locate email by tracking record ===");

    $trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($config);

    // Folder layout shared across locate tests: one Sent, plus non-Sent folders.
    $stubFolders = [
        ['name' => 'INBOX',   'is_selectable' => 1, 'special_use' => null,        'type' => 'inbox'],
        ['name' => 'Sent',    'is_selectable' => 1, 'special_use' => '\\Sent',    'type' => 'sent'],
        ['name' => 'Archive', 'is_selectable' => 1, 'special_use' => '\\Archive', 'type' => 'archive'],
    ];

    // Build a stand-in ImapService whose folder/search responses are scripted,
    // so locateEmail() can be exercised deterministically without a live mailbox.
    // Only the three methods locateEmail() calls are overridden.
    $makeImapStub = function (array $folders, array $headerHits, array $subjectHits) {
        return new class($folders, $headerHits, $subjectHits) extends \Webmail\Services\ImapService {
            public array $stubFolders;
            public array $stubHeaderHits;
            public array $stubSubjectHits;
            public function __construct(array $folders, array $headerHits, array $subjectHits) {
                $this->stubFolders = $folders;
                $this->stubHeaderHits = $headerHits;
                $this->stubSubjectHits = $subjectHits;
            }
            public function listFolders(): array { return $this->stubFolders; }
            public function searchHeader(string $folder, string $headerName, string $headerValue): array {
                return $this->stubHeaderHits[$folder] ?? [];
            }
            public function search(string $folder, string $query, array $filters = []): array {
                return $this->stubSubjectHits[$folder] ?? [];
            }
        };
    };

    test('email_tracking.message_id column exists', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->query("SHOW COLUMNS FROM email_tracking LIKE 'message_id'");
        assert_true($stmt->rowCount() > 0, 'message_id column missing - run migration 193');
    });

    test('locateEmail resolves Sent folder + uid via Message-ID', function () use (
        $trackingService, $testEmail, $TEST_TAG, $runId, $stubFolders, $makeImapStub, &$cleanupTrackingIds
    ) {
        $tid = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $tid;
        $msgId = 'locate-' . $runId . '@flowone.test';
        $subject = "$TEST_TAG Locate via MsgID $runId";

        // Store WITH angle brackets to prove createTracking normalizes them away.
        $trackingService->createTracking($testEmail, $tid, $subject, ['rcpt_' . $runId . '@example.com'], null, '<' . $msgId . '>');

        $imap = $makeImapStub(
            $stubFolders,
            ['Sent' => [['uid' => 4242, 'message_id' => $msgId]]],
            []
        );

        $located = $trackingService->locateEmail($testEmail, $tid, $imap);
        assert_not_empty($located, 'Should resolve a location');
        assert_equals('Sent', $located['folder'], 'Folder should be Sent');
        assert_equals(4242, $located['uid'], 'UID should match Message-ID hit');
    });

    test('createTracking stores message_id without angle brackets', function () use (
        $trackingService, $testEmail, $TEST_TAG, $runId, $config, &$cleanupTrackingIds
    ) {
        $tid = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $tid;
        $msgId = 'normalize-' . $runId . '@flowone.test';
        $trackingService->createTracking($testEmail, $tid, "$TEST_TAG Normalize $runId", ['rcptn_' . $runId . '@example.com'], null, '<' . $msgId . '>');

        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT message_id FROM email_tracking WHERE tracking_id = ?');
        $stmt->execute([$tid]);
        $stored = $stmt->fetch()['message_id'] ?? null;
        assert_equals($msgId, $stored, 'message_id should be stored without <>');
    });

    test('locateEmail falls back to subject search when Message-ID is null', function () use (
        $trackingService, $testEmail, $TEST_TAG, $runId, $stubFolders, $makeImapStub, &$cleanupTrackingIds
    ) {
        $tid = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $tid;
        $subject = "$TEST_TAG Locate via subject $runId";

        // Legacy row: no Message-ID stored (pre-migration send).
        $trackingService->createTracking($testEmail, $tid, $subject, ['rcpt2_' . $runId . '@example.com']);

        // No header hits anywhere; subject search finds it in Sent.
        $imap = $makeImapStub(
            $stubFolders,
            [],
            ['Sent' => [['uid' => 777, 'subject' => $subject]]]
        );

        $located = $trackingService->locateEmail($testEmail, $tid, $imap);
        assert_not_empty($located, 'Subject fallback should resolve a location');
        assert_equals('Sent', $located['folder'], 'Folder should be Sent');
        assert_equals(777, $located['uid'], 'UID should match subject hit');
    });

    test('locateEmail returns null when email is gone (drives HTTP 404)', function () use (
        $trackingService, $testEmail, $TEST_TAG, $runId, $stubFolders, $makeImapStub, &$cleanupTrackingIds
    ) {
        $tid = $trackingService->generateTrackingId();
        $cleanupTrackingIds[] = $tid;
        $msgId = 'gone-' . $runId . '@flowone.test';
        $subject = "$TEST_TAG Locate missing $runId";

        $trackingService->createTracking($testEmail, $tid, $subject, ['rcpt3_' . $runId . '@example.com'], null, $msgId);

        // Nothing matches in any folder, by Message-ID or subject -> the
        // controller turns this null into a 404 the frontend toasts on.
        $imap = $makeImapStub($stubFolders, [], []);

        $located = $trackingService->locateEmail($testEmail, $tid, $imap);
        assert_true($located === null, 'Should return null; controller maps this to HTTP 404');
    });

    test('locateEmail returns null for unknown tracking_id', function () use (
        $trackingService, $testEmail, $stubFolders, $makeImapStub
    ) {
        $imap = $makeImapStub($stubFolders, [], []);
        $located = $trackingService->locateEmail($testEmail, 'nonexistent_' . bin2hex(random_bytes(8)), $imap);
        assert_true($located === null, 'Unknown tracking_id should resolve to null');
    });
}

// ══════════════════════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════════════════════

out("\n=============================================================");
out("  RESULTS");
out("=============================================================");

$totalDuration = array_sum(array_column($results, 'ms'));

out("  Total:    $totalTests");
out("  Passed:   \033[32m$passed\033[0m");
if ($warnings > 0) out("  Warnings: \033[33m$warnings\033[0m");
if ($failed > 0)   out("  Failed:   \033[31m$failed\033[0m");
out("  Duration: {$totalDuration}ms");
out("  Log:      $logFile");

if ($failed > 0) {
    out("\n  FAILURES:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    - {$r['name']}: {$r['error']}");
        }
    }
}

out("=============================================================\n");

exit($failed > 0 ? 1 : 0);
