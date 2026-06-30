#!/usr/bin/env php
<?php
/**
 * email-tracking-prefetch-test.php
 *
 * Verifies the "automated prefetch / proxy scan" guard added to
 * TrackingService::recordReadEvent(). Mail providers (Gmail's
 * GoogleImageProxy, Apple Mail Privacy Protection, Outlook) fetch the
 * tracking pixel automatically within seconds of delivery - BEFORE a human
 * opens the message. Those must NOT produce a read event, a read_receipt
 * notification, or (now that receipts push to mobile) a phantom push.
 *
 * Genuine opens arrive later and MUST still be recorded.
 *
 * Per .cursor/rules/server-side-testing.mdc - CLI only, idempotent, all data
 * uses the fake sender `flowone_test_sender@example.invalid` and an
 * `[FLOWONE-TEST]` subject so it can never collide with production rows, and
 * cleanup runs on every exit path (including SIGINT/SIGTERM).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-tracking-prefetch-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Core\Database;
use Webmail\Addons\EmailTracking\Services\TrackingService;

$runner = new FlowOneTestRunner('email-tracking-prefetch', $argv);

// -- Pre-flight ----------------------------------------------------------

if (!extension_loaded('pdo_mysql')) {
    $runner->log('missing PHP extension: pdo_mysql');
    exit(1);
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    $runner->log('DB connectivity failed: ' . $e->getMessage());
    exit(1);
}

// -- Synthetic fixtures (fake sender so no real device is ever pushed) ----

$SENDER  = 'flowone_test_sender@example.invalid';
$RCPT    = 'flowone_test_rcpt@example.invalid';
$SUBJECT = '[FLOWONE-TEST] prefetch guard';

/** @var string[] track every tracking_id we create so cleanup is exhaustive */
$trackingIds = [];

// TrackingService with the production default (15s) window.
$tracking = new TrackingService($config);

$cleanup = function () use ($db, $SENDER) {
    // Deleting the email_tracking rows cascades to email_read_events
    // (FK ON DELETE CASCADE). Keyed on the fake sender so a previous
    // dead run is also swept up - the address can never exist in prod.
    foreach (
        [
            ['email_tracking', 'user_email'],
            ['notifications',  'user_email'],
        ] as [$table, $col]
    ) {
        try {
            $db->prepare("DELETE FROM {$table} WHERE LOWER({$col}) = ?")->execute([strtolower($SENDER)]);
        } catch (\Throwable $e) {
            // table may not exist in a stripped env; ignore
        }
    }
};
$runner->addCleanup($cleanup);
// Start from a clean slate in case a previous run died mid-way.
$cleanup();

// -- Helpers -------------------------------------------------------------

$seedTracking = function (int $ageSeconds) use ($db, $SENDER, $RCPT, $SUBJECT, &$trackingIds): string {
    // 59-char id (NOT 32) so recordReadEvent treats it as a tracking_id.
    $tid = 'flowonetest' . bin2hex(random_bytes(24));
    $recipients = json_encode([['email' => $RCPT, 'name' => 'FlowOne Test']]);
    $age = max(0, $ageSeconds);
    $db->prepare(
        "INSERT INTO email_tracking (user_email, tracking_id, subject, recipients, sent_at)
         VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL {$age} SECOND))"
    )->execute([strtolower($SENDER), $tid, $SUBJECT, $recipients]);
    $trackingIds[] = $tid;
    return $tid;
};

$countReads = function (string $tid) use ($db): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM email_read_events WHERE tracking_id = ?');
    $stmt->execute([$tid]);
    return (int)$stmt->fetchColumn();
};

$countReceipts = function (string $tid) use ($db): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE tracking_id = ? AND type = 'read_receipt'");
    $stmt->execute([$tid]);
    return (int)$stmt->fetchColumn();
};

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('db reachable', fn() => true);
    $runner->test('email_tracking table present', function () use ($db, $runner) {
        $n = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'email_tracking' AND table_schema = DATABASE()")->fetchColumn();
        $runner->assertEquals(1, $n, 'email_tracking table must exist');
    });
    exit($runner->finish());
}

// -- 1. Prefetch suppression (default 15s window) ------------------------

if ($runner->shouldRunSection('1. prefetch')) {
    $runner->section('1. PREFETCH');

    $runner->test('instant open (age 0s) is treated as a proxy prefetch and NOT recorded', function () use ($seedTracking, $countReads, $tracking, $RCPT, $runner) {
        $tid = $seedTracking(0);
        $tracking->recordReadEvent($tid, $RCPT);
        $runner->assertEquals(0, $countReads($tid), 'instant open must not create a read event');
    });

    $runner->test('instant open does NOT create a read_receipt notification (no phantom push)', function () use ($seedTracking, $countReceipts, $tracking, $RCPT, $runner) {
        $tid = $seedTracking(2);
        $tracking->recordReadEvent($tid, $RCPT);
        $runner->assertEquals(0, $countReceipts($tid), 'instant open must not create a read_receipt notification');
    });

    $runner->test('open at the window edge (age 15s) is still suppressed', function () use ($seedTracking, $countReads, $tracking, $RCPT, $runner) {
        $tid = $seedTracking(15);
        $tracking->recordReadEvent($tid, $RCPT);
        $runner->assertEquals(0, $countReads($tid), '15s open (== window) must be suppressed');
    });

    $runner->test('genuine later open (age 60s) IS recorded as a real read', function () use ($seedTracking, $countReads, $tracking, $RCPT, $runner) {
        $tid = $seedTracking(60);
        $tracking->recordReadEvent($tid, $RCPT);
        $runner->assertEquals(1, $countReads($tid), '60s open must be recorded');
    });

    $runner->test('genuine later open creates exactly one read_receipt notification', function () use ($seedTracking, $countReceipts, $tracking, $RCPT, $runner) {
        $tid = $seedTracking(120);
        $tracking->recordReadEvent($tid, $RCPT);
        $runner->assertEquals(1, $countReceipts($tid), '120s open must create a read_receipt notification');
    });
}

// -- 2. Configurable window ----------------------------------------------

if ($runner->shouldRunSection('2. config')) {
    $runner->section('2. CONFIG');

    $runner->test('prefetch_window_seconds=0 disables the guard (instant open recorded)', function () use ($config, $seedTracking, $countReads, $RCPT, $runner) {
        $cfg = $config;
        $cfg['email_tracking']['prefetch_window_seconds'] = 0;
        $svc = new TrackingService($cfg);
        $tid = $seedTracking(0);
        $svc->recordReadEvent($tid, $RCPT);
        $runner->assertEquals(1, $countReads($tid), 'with window=0 an instant open must be recorded');
    });

    $runner->test('a wider window (120s) suppresses a 60s-old open', function () use ($config, $seedTracking, $countReads, $RCPT, $runner) {
        $cfg = $config;
        $cfg['email_tracking']['prefetch_window_seconds'] = 120;
        $svc = new TrackingService($cfg);
        $tid = $seedTracking(60);
        $svc->recordReadEvent($tid, $RCPT);
        $runner->assertEquals(0, $countReads($tid), 'with window=120 a 60s open must be suppressed');
    });
}

exit($runner->finish());
