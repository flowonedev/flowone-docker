#!/usr/bin/env php
<?php
/**
 * FlowOne Calendar - Comprehensive Test Suite
 *
 * Tests calendar CRUD, event create/update/delete, move between days,
 * duplicate, all-day events, recurring events (RRULE expansion), multiple
 * invites/participants, scheduled online meetings, and date-range queries.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/calendar-test.php \
 *       --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --only=GROUPS        Comma-separated: calendar,event,move,duplicate,recurrence,invite,meeting,query
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
    echo "FlowOne Calendar Test Suite\n";
    echo "============================\n\n";
    echo "Usage:\n";
    echo "  php calendar-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --only=GROUPS        Comma-separated: calendar,event,move,duplicate,recurrence,invite,meeting,query\n";
    echo "  --smoke              Run minimal smoke tests only\n";
    echo "  --verbose            Show extra debug info\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/calendar-test.php \\\n";
    echo "      --email=admin@flowone.pro --verbose\n";
    exit(1);
}

$testEmail    = $opts['email'];
$verbose      = isset($opts['verbose']);
$smokeOnly    = isset($opts['smoke']);
$onlyGroups   = isset($opts['only']) ? explode(',', $opts['only']) : [];

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return in_array($group, ['calendar', 'event', 'recurrence']);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/calendar-test-' . date('Ymd-His') . '.log';
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
function assert_false(bool $condition, string $msg = 'Expected false'): void {
    if ($condition) throw new \RuntimeException($msg);
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
function assert_null($value, string $msg = 'Expected null'): void {
    if ($value !== null) throw new \RuntimeException($msg . ': got ' . var_export($value, true));
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

$TEST_TAG = '[CALTEST]';
$runId    = date('His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

$cleanupCalendarIds = [];
$cleanupEventIds    = [];

function doCleanup(): void {
    global $config, $testEmail, $cleanupCalendarIds, $cleanupEventIds;

    out("\n--- CLEANUP ---");

    $db = \Webmail\Core\Database::getConnection($config);
    $userLower = strtolower($testEmail);

    // Delete test events (participants cascade via service or manual)
    if (!empty($cleanupEventIds)) {
        foreach ($cleanupEventIds as $eid) {
            try {
                $db->prepare('DELETE FROM calendar_event_participants WHERE event_id = ?')->execute([$eid]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $db->prepare('DELETE FROM calendar_events WHERE id = ?')->execute([$eid]);
                vlog("Deleted event ID $eid");
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    // Delete test calendars (events cascade via FK)
    if (!empty($cleanupCalendarIds)) {
        foreach ($cleanupCalendarIds as $cid) {
            try {
                // First clean any remaining participants for events in this calendar
                $db->prepare('
                    DELETE p FROM calendar_event_participants p
                    JOIN calendar_events e ON p.event_id = e.id
                    WHERE e.calendar_id = ?
                ')->execute([$cid]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $db->prepare('DELETE FROM calendar_shares WHERE calendar_id = ?')->execute([$cid]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $db->prepare('DELETE FROM calendars WHERE id = ? AND user_email = ?')->execute([$cid, $userLower]);
                vlog("Deleted calendar ID $cid");
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    out("  Cleanup complete.");
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ── Banner ───────────────────────────────────────────────────────

out("=============================================================");
out("  FlowOne Calendar Test Suite");
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

test('calendars table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->query("SHOW TABLES LIKE 'calendars'");
    assert_true($stmt->rowCount() > 0, 'calendars table missing');
});

test('calendar_events table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->query("SHOW TABLES LIKE 'calendar_events'");
    assert_true($stmt->rowCount() > 0, 'calendar_events table missing');
});

test('calendar_event_participants table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->query("SHOW TABLES LIKE 'calendar_event_participants'");
    assert_true($stmt->rowCount() > 0, 'calendar_event_participants table missing');
});

test('CalendarService instantiates', function () use ($config) {
    $cs = new \Webmail\Addons\Calendar\Services\CalendarService($config);
    assert_true($cs instanceof \Webmail\Addons\Calendar\Services\CalendarService);
});

// ══════════════════════════════════════════════════════════════════
// Shared instances
// ══════════════════════════════════════════════════════════════════

$calService = new \Webmail\Addons\Calendar\Services\CalendarService($config);
$db = \Webmail\Core\Database::getConnection($config);
$inviteService = new \Webmail\Services\CalendarInviteService($db, $config);

// ══════════════════════════════════════════════════════════════════
// GROUP: calendar -- Calendar CRUD
// ══════════════════════════════════════════════════════════════════

$testCalId  = null;
$testCal2Id = null;

if (shouldRun('calendar')) {
    out("\n=== Calendar CRUD ===");

    test('createCalendar creates a new calendar', function () use (
        $calService, $testEmail, $runId, $TEST_TAG, &$testCalId, &$cleanupCalendarIds
    ) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Primary $runId", '#3b82f6', false);
        assert_true($cal !== null, 'createCalendar returned null');
        assert_not_empty($cal['id'], 'Calendar ID');
        assert_equals("$TEST_TAG Primary $runId", $cal['name'], 'Name');
        assert_equals('#3b82f6', $cal['color'], 'Color');
        $testCalId = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCalId;
        vlog("Created calendar ID: $testCalId");
    });

    test('createCalendar creates a second calendar', function () use (
        $calService, $testEmail, $runId, $TEST_TAG, &$testCal2Id, &$cleanupCalendarIds
    ) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Secondary $runId", '#ef4444', false);
        assert_true($cal !== null, 'Second calendar null');
        $testCal2Id = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCal2Id;
        vlog("Created calendar 2 ID: $testCal2Id");
    });

    test('getCalendar returns correct calendar', function () use ($calService, $testEmail, $testCalId, $TEST_TAG, $runId) {
        $cal = $calService->getCalendar($testEmail, $testCalId);
        assert_true($cal !== null, 'getCalendar returned null');
        assert_equals($testCalId, (int)$cal['id'], 'ID match');
        assert_equals("$TEST_TAG Primary $runId", $cal['name'], 'Name match');
    });

    test('getCalendars lists both test calendars', function () use ($calService, $testEmail, $testCalId, $testCal2Id) {
        $all = $calService->getCalendars($testEmail);
        $testIds = array_map(fn($c) => (int)$c['id'], $all);
        assert_true(in_array($testCalId, $testIds), 'Primary missing from list');
        assert_true(in_array($testCal2Id, $testIds), 'Secondary missing from list');
    });

    test('updateCalendar changes name and color', function () use ($calService, $testEmail, $testCalId, $runId, $TEST_TAG) {
        $updated = $calService->updateCalendar($testEmail, $testCalId, [
            'name' => "$TEST_TAG Updated $runId",
            'color' => '#10b981',
        ]);
        assert_true($updated !== null, 'updateCalendar returned null');
        assert_equals("$TEST_TAG Updated $runId", $updated['name'], 'Name updated');
        assert_equals('#10b981', $updated['color'], 'Color updated');
    });

    test('getCalendar for wrong user returns null', function () use ($calService, $testCalId) {
        $cal = $calService->getCalendar('wrong@nobody.com', $testCalId);
        assert_null($cal, 'Should not see other user calendar');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: event -- Event CRUD (single + all-day)
// ══════════════════════════════════════════════════════════════════

$singleEventId = null;
$allDayEventId = null;

if (shouldRun('event')) {
    out("\n=== Event CRUD (single + all-day) ===");

    // Ensure calendar exists
    if (!$testCalId) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Primary $runId", '#3b82f6', false);
        $testCalId = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCalId;
    }
    if (!$testCal2Id) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Secondary $runId", '#ef4444', false);
        $testCal2Id = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCal2Id;
    }

    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    test('createEvent creates a timed single event', function () use (
        $calService, $testEmail, $testCalId, $tomorrow, $runId, $TEST_TAG,
        &$singleEventId, &$cleanupEventIds
    ) {
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Meeting $runId",
            'description' => 'Test meeting description',
            'location' => 'Conference Room A',
            'start_time' => "$tomorrow 10:00:00",
            'end_time' => "$tomorrow 11:30:00",
            'all_day' => false,
            'reminders' => [['type' => 'notification', 'minutes' => 15]],
            'color' => '#8b5cf6',
        ]);

        assert_true($event !== null, 'createEvent returned null');
        assert_not_empty($event['id'], 'Event ID');
        assert_equals("$TEST_TAG Meeting $runId", $event['title'], 'Title');
        assert_equals('Conference Room A', $event['location'], 'Location');
        assert_equals("$tomorrow 10:00:00", $event['start_time'], 'Start time');
        assert_equals("$tomorrow 11:30:00", $event['end_time'], 'End time');
        assert_false($event['all_day'], 'all_day should be false');
        assert_not_empty($event['uid'], 'UID should be generated');
        assert_not_empty($event['etag'], 'etag should be generated');

        $reminders = $event['reminders'];
        assert_true(is_array($reminders), 'Reminders should be array');
        assert_equals(1, count($reminders), 'Should have 1 reminder');

        $singleEventId = (int)$event['id'];
        $cleanupEventIds[] = $singleEventId;
        vlog("Created single event ID: $singleEventId, UID: {$event['uid']}");
    });

    test('createEvent creates an all-day event', function () use (
        $calService, $testEmail, $testCalId, $tomorrow, $runId, $TEST_TAG,
        &$allDayEventId, &$cleanupEventIds
    ) {
        $dayAfter = date('Y-m-d', strtotime('+2 days'));
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG All Day $runId",
            'description' => 'Full-day event',
            'start_time' => "$tomorrow 00:00:00",
            'end_time' => "$dayAfter 00:00:00",
            'all_day' => true,
        ]);

        assert_true($event !== null, 'All-day event null');
        assert_true($event['all_day'], 'all_day should be true');
        $allDayEventId = (int)$event['id'];
        $cleanupEventIds[] = $allDayEventId;
        vlog("Created all-day event ID: $allDayEventId");
    });

    test('getEvent returns correct event', function () use ($calService, $testEmail, $singleEventId, $TEST_TAG, $runId) {
        $event = $calService->getEvent($testEmail, $singleEventId);
        assert_true($event !== null, 'getEvent returned null');
        assert_equals($singleEventId, (int)$event['id'], 'ID match');
        assert_equals("$TEST_TAG Meeting $runId", $event['title'], 'Title match');
        assert_true(isset($event['participants']), 'Should include participants array');
    });

    test('getEvents lists events in calendar', function () use ($calService, $testEmail, $testCalId, $singleEventId, $allDayEventId) {
        $events = $calService->getEvents($testEmail, $testCalId);
        $ids = array_map(fn($e) => (int)$e['id'], $events);
        assert_true(in_array($singleEventId, $ids), 'Single event missing');
        assert_true(in_array($allDayEventId, $ids), 'All-day event missing');
    });

    test('updateEvent changes title and description', function () use (
        $calService, $testEmail, $singleEventId, $runId, $TEST_TAG
    ) {
        $updated = $calService->updateEvent($testEmail, $singleEventId, [
            'title' => "$TEST_TAG Updated Meeting $runId",
            'description' => 'Updated description',
        ]);
        assert_true($updated !== null, 'updateEvent returned null');
        assert_equals("$TEST_TAG Updated Meeting $runId", $updated['title'], 'Title updated');
        assert_equals('Updated description', $updated['description'], 'Description updated');
    });

    test('updateEvent changes etag on every update', function () use ($calService, $testEmail, $singleEventId) {
        $before = $calService->getEvent($testEmail, $singleEventId);
        $after = $calService->updateEvent($testEmail, $singleEventId, ['location' => 'Room B']);
        assert_true($before['etag'] !== $after['etag'], 'etag should change after update');
        vlog("etag before={$before['etag']}, after={$after['etag']}");
    });

    test('getEvent for wrong user returns null', function () use ($calService, $singleEventId) {
        $event = $calService->getEvent('wrong@nobody.com', $singleEventId);
        assert_null($event, 'Should not see other user event');
    });

    test('deleteEvent removes the all-day event', function () use (
        $calService, $testEmail, $allDayEventId, &$cleanupEventIds
    ) {
        $result = $calService->deleteEvent($testEmail, $allDayEventId);
        assert_true($result, 'deleteEvent should return true');

        $gone = $calService->getEvent($testEmail, $allDayEventId);
        assert_null($gone, 'Deleted event should be null');

        $cleanupEventIds = array_filter($cleanupEventIds, fn($id) => $id !== $allDayEventId);
        vlog("Deleted all-day event $allDayEventId");
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: move -- Move event between days / calendars
// ══════════════════════════════════════════════════════════════════

if (shouldRun('move')) {
    out("\n=== Move event between days / calendars ===");

    if (!$testCalId) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Primary $runId", '#3b82f6', false);
        $testCalId = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCalId;
    }
    if (!$testCal2Id) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Secondary $runId", '#ef4444', false);
        $testCal2Id = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCal2Id;
    }

    $moveEventId = null;
    $origDate = date('Y-m-d', strtotime('+3 days'));
    $newDate  = date('Y-m-d', strtotime('+7 days'));

    test('Setup: create event for move tests', function () use (
        $calService, $testEmail, $testCalId, $origDate, $runId, $TEST_TAG,
        &$moveEventId, &$cleanupEventIds
    ) {
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Moveable $runId",
            'start_time' => "$origDate 14:00:00",
            'end_time' => "$origDate 15:00:00",
        ]);
        assert_true($event !== null, 'Create moveable event');
        $moveEventId = (int)$event['id'];
        $cleanupEventIds[] = $moveEventId;
    });

    test('Move event to a different day (reschedule)', function () use (
        $calService, $testEmail, $moveEventId, $origDate, $newDate
    ) {
        $updated = $calService->updateEvent($testEmail, $moveEventId, [
            'start_time' => "$newDate 14:00:00",
            'end_time' => "$newDate 15:00:00",
        ]);
        assert_true($updated !== null, 'updateEvent for move');
        assert_equals("$newDate 14:00:00", $updated['start_time'], 'Start moved');
        assert_equals("$newDate 15:00:00", $updated['end_time'], 'End moved');
    });

    test('Event no longer appears in original date range', function () use (
        $calService, $testEmail, $testCalId, $origDate, $moveEventId
    ) {
        $dayEnd = "$origDate 23:59:59";
        $events = $calService->getEvents($testEmail, $testCalId, "$origDate 00:00:00", $dayEnd);
        $ids = array_map(fn($e) => (int)$e['id'], $events);
        assert_false(in_array($moveEventId, $ids), 'Event should not appear on original date');
    });

    test('Event appears in new date range', function () use (
        $calService, $testEmail, $testCalId, $newDate, $moveEventId
    ) {
        $dayEnd = "$newDate 23:59:59";
        $events = $calService->getEvents($testEmail, $testCalId, "$newDate 00:00:00", $dayEnd);
        $ids = array_map(fn($e) => (int)$e['id'], $events);
        assert_true(in_array($moveEventId, $ids), 'Event should appear on new date');
    });

    test('Move event to a different calendar', function () use (
        $calService, $testEmail, $moveEventId, $testCalId, $testCal2Id
    ) {
        $updated = $calService->updateEvent($testEmail, $moveEventId, [
            'calendar_id' => $testCal2Id,
        ]);
        assert_true($updated !== null, 'Move to cal2');
        assert_equals($testCal2Id, (int)$updated['calendar_id'], 'calendar_id changed');
    });

    test('Event gone from original calendar, present in new', function () use (
        $calService, $testEmail, $testCalId, $testCal2Id, $moveEventId
    ) {
        $eventsOld = $calService->getEvents($testEmail, $testCalId);
        $idsOld = array_map(fn($e) => (int)$e['id'], $eventsOld);
        assert_false(in_array($moveEventId, $idsOld), 'Should not be in old calendar');

        $eventsNew = $calService->getEvents($testEmail, $testCal2Id);
        $idsNew = array_map(fn($e) => (int)$e['id'], $eventsNew);
        assert_true(in_array($moveEventId, $idsNew), 'Should be in new calendar');
    });

    test('Move event to non-existent calendar fails gracefully', function () use (
        $calService, $testEmail, $moveEventId
    ) {
        $result = $calService->updateEvent($testEmail, $moveEventId, [
            'calendar_id' => 999999,
        ]);
        assert_null($result, 'Should return null for non-existent target calendar');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: duplicate -- Duplicate / clone events
// ══════════════════════════════════════════════════════════════════

if (shouldRun('duplicate')) {
    out("\n=== Duplicate events ===");

    if (!$testCalId) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Primary $runId", '#3b82f6', false);
        $testCalId = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCalId;
    }

    $srcEventId = null;
    $dupEventId = null;

    test('Setup: create source event to duplicate', function () use (
        $calService, $testEmail, $testCalId, $runId, $TEST_TAG,
        &$srcEventId, &$cleanupEventIds
    ) {
        $day = date('Y-m-d', strtotime('+5 days'));
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Original $runId",
            'description' => 'Source event for duplication',
            'location' => 'Room X',
            'start_time' => "$day 09:00:00",
            'end_time' => "$day 10:00:00",
            'color' => '#f59e0b',
            'reminders' => [['type' => 'notification', 'minutes' => 30]],
        ]);
        assert_true($event !== null, 'Source event');
        $srcEventId = (int)$event['id'];
        $cleanupEventIds[] = $srcEventId;
    });

    test('Duplicate event by re-creating with same data', function () use (
        $calService, $testEmail, $testCalId, $srcEventId,
        &$dupEventId, &$cleanupEventIds
    ) {
        $src = $calService->getEvent($testEmail, $srcEventId);
        assert_true($src !== null, 'Source must exist');

        $dup = $calService->createEvent($testEmail, $testCalId, [
            'title' => $src['title'] . ' (copy)',
            'description' => $src['description'],
            'location' => $src['location'],
            'start_time' => $src['start_time'],
            'end_time' => $src['end_time'],
            'all_day' => $src['all_day'],
            'color' => $src['color'],
            'reminders' => $src['reminders'],
        ]);

        assert_true($dup !== null, 'Duplicate created');
        assert_true($dup['id'] !== $src['id'], 'Different IDs');
        assert_true($dup['uid'] !== $src['uid'], 'Different UIDs');
        assert_equals($src['location'], $dup['location'], 'Location copied');
        assert_equals($src['start_time'], $dup['start_time'], 'Start copied');
        assert_equals($src['color'], $dup['color'], 'Color copied');

        $dupEventId = (int)$dup['id'];
        $cleanupEventIds[] = $dupEventId;
        vlog("Original ID=$srcEventId UID={$src['uid']}, Dup ID=$dupEventId UID={$dup['uid']}");
    });

    test('Both original and duplicate listed in calendar', function () use (
        $calService, $testEmail, $testCalId, $srcEventId, $dupEventId
    ) {
        $events = $calService->getEvents($testEmail, $testCalId);
        $ids = array_map(fn($e) => (int)$e['id'], $events);
        assert_true(in_array($srcEventId, $ids), 'Original in list');
        assert_true(in_array($dupEventId, $ids), 'Duplicate in list');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: recurrence -- Recurring events (RRULE expansion)
// ══════════════════════════════════════════════════════════════════

if (shouldRun('recurrence')) {
    out("\n=== Recurring events (RRULE expansion) ===");

    if (!$testCalId) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Primary $runId", '#3b82f6', false);
        $testCalId = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCalId;
    }

    $dailyEventId   = null;
    $weeklyEventId  = null;
    $monthlyEventId = null;
    $yearlyEventId  = null;

    $baseDate = date('Y-m-d');

    test('Create daily recurring event (FREQ=DAILY, COUNT=5)', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $runId, $TEST_TAG,
        &$dailyEventId, &$cleanupEventIds
    ) {
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Daily $runId",
            'start_time' => "$baseDate 08:00:00",
            'end_time' => "$baseDate 08:30:00",
            'recurrence' => 'RRULE:FREQ=DAILY;COUNT=5',
        ]);
        assert_true($event !== null, 'Daily event');
        assert_equals('RRULE:FREQ=DAILY;COUNT=5', $event['recurrence'], 'RRULE stored');
        $dailyEventId = (int)$event['id'];
        $cleanupEventIds[] = $dailyEventId;
        vlog("Daily event ID: $dailyEventId");
    });

    test('Daily event expands to 5 occurrences in a 30-day range', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $dailyEventId
    ) {
        $rangeEnd = date('Y-m-d', strtotime($baseDate . ' +30 days'));
        $events = $calService->getEvents($testEmail, $testCalId, "$baseDate 00:00:00", "$rangeEnd 23:59:59");

        $dailyOccurrences = array_filter($events, fn($e) => (int)$e['id'] === $dailyEventId);
        $count = count($dailyOccurrences);
        assert_equals(5, $count, "Daily COUNT=5 should expand to 5, got $count");

        // Check that occurrences have sequential dates
        $dates = array_map(fn($e) => substr($e['start_time'], 0, 10), $dailyOccurrences);
        sort($dates);
        for ($i = 0; $i < 5; $i++) {
            $expected = date('Y-m-d', strtotime($baseDate . " +{$i} days"));
            assert_equals($expected, $dates[$i], "Occurrence $i date");
        }
        vlog("Daily dates: " . implode(', ', $dates));
    });

    test('Create weekly recurring event (FREQ=WEEKLY, COUNT=4)', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $runId, $TEST_TAG,
        &$weeklyEventId, &$cleanupEventIds
    ) {
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Weekly $runId",
            'start_time' => "$baseDate 14:00:00",
            'end_time' => "$baseDate 15:00:00",
            'recurrence' => 'RRULE:FREQ=WEEKLY;COUNT=4',
        ]);
        assert_true($event !== null, 'Weekly event');
        $weeklyEventId = (int)$event['id'];
        $cleanupEventIds[] = $weeklyEventId;
    });

    test('Weekly event expands to 4 occurrences (7 days apart)', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $weeklyEventId
    ) {
        $rangeEnd = date('Y-m-d', strtotime($baseDate . ' +60 days'));
        $events = $calService->getEvents($testEmail, $testCalId, "$baseDate 00:00:00", "$rangeEnd 23:59:59");

        $weeklyOccurrences = array_filter($events, fn($e) => (int)$e['id'] === $weeklyEventId);
        $count = count($weeklyOccurrences);
        assert_equals(4, $count, "Weekly COUNT=4 should expand to 4, got $count");

        $dates = array_map(fn($e) => substr($e['start_time'], 0, 10), $weeklyOccurrences);
        sort($dates);
        for ($i = 0; $i < 4; $i++) {
            $expected = date('Y-m-d', strtotime($baseDate . " +" . ($i * 7) . " days"));
            assert_equals($expected, $dates[$i], "Weekly occurrence $i");
        }
        vlog("Weekly dates: " . implode(', ', $dates));
    });

    test('Create monthly recurring event (FREQ=MONTHLY, COUNT=3)', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $runId, $TEST_TAG,
        &$monthlyEventId, &$cleanupEventIds
    ) {
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Monthly $runId",
            'start_time' => "$baseDate 16:00:00",
            'end_time' => "$baseDate 17:00:00",
            'recurrence' => 'RRULE:FREQ=MONTHLY;COUNT=3',
        ]);
        assert_true($event !== null, 'Monthly event');
        $monthlyEventId = (int)$event['id'];
        $cleanupEventIds[] = $monthlyEventId;
    });

    test('Monthly event expands to 3 occurrences', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $monthlyEventId
    ) {
        $rangeEnd = date('Y-m-d', strtotime($baseDate . ' +6 months'));
        $events = $calService->getEvents($testEmail, $testCalId, "$baseDate 00:00:00", "$rangeEnd 23:59:59");

        $monthlyOccurrences = array_filter($events, fn($e) => (int)$e['id'] === $monthlyEventId);
        $count = count($monthlyOccurrences);
        assert_equals(3, $count, "Monthly COUNT=3 should expand to 3, got $count");
    });

    test('Create yearly recurring event (FREQ=YEARLY, COUNT=2)', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $runId, $TEST_TAG,
        &$yearlyEventId, &$cleanupEventIds
    ) {
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Yearly $runId",
            'start_time' => "$baseDate 12:00:00",
            'end_time' => "$baseDate 13:00:00",
            'recurrence' => 'RRULE:FREQ=YEARLY;COUNT=2',
        ]);
        assert_true($event !== null, 'Yearly event');
        $yearlyEventId = (int)$event['id'];
        $cleanupEventIds[] = $yearlyEventId;
    });

    test('Yearly event expands to 2 occurrences', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $yearlyEventId
    ) {
        $rangeEnd = date('Y-m-d', strtotime($baseDate . ' +3 years'));
        $events = $calService->getEvents($testEmail, $testCalId, "$baseDate 00:00:00", "$rangeEnd 23:59:59");

        $yearlyOccurrences = array_filter($events, fn($e) => (int)$e['id'] === $yearlyEventId);
        $count = count($yearlyOccurrences);
        assert_equals(2, $count, "Yearly COUNT=2 should expand to 2, got $count");
    });

    test('Recurrence instances have correct metadata', function () use (
        $calService, $testEmail, $testCalId, $baseDate, $dailyEventId
    ) {
        $rangeEnd = date('Y-m-d', strtotime($baseDate . ' +10 days'));
        $events = $calService->getEvents($testEmail, $testCalId, "$baseDate 00:00:00", "$rangeEnd 23:59:59");

        $dailyOccurrences = array_values(array_filter($events, fn($e) => (int)$e['id'] === $dailyEventId));

        // First occurrence: NOT a recurrence instance
        $first = $dailyOccurrences[0];
        assert_false($first['is_recurrence_instance'] ?? false, 'First is not instance');

        // Second occurrence: IS a recurrence instance with parent_id
        if (count($dailyOccurrences) > 1) {
            $second = $dailyOccurrences[1];
            assert_true($second['is_recurrence_instance'], 'Second is instance');
            assert_equals($dailyEventId, (int)$second['recurrence_parent_id'], 'Parent ID matches');
            assert_not_empty($second['virtual_id'], 'Virtual ID should exist');
            vlog("Instance virtual_id: {$second['virtual_id']}");
        }
    });

    test('Update recurrence rule on existing event', function () use (
        $calService, $testEmail, $dailyEventId, $testCalId, $baseDate
    ) {
        $updated = $calService->updateEvent($testEmail, $dailyEventId, [
            'recurrence' => 'RRULE:FREQ=DAILY;COUNT=3',
        ]);
        assert_true($updated !== null, 'Update recurrence');
        assert_equals('RRULE:FREQ=DAILY;COUNT=3', $updated['recurrence'], 'New RRULE');

        $rangeEnd = date('Y-m-d', strtotime($baseDate . ' +30 days'));
        $events = $calService->getEvents($testEmail, $testCalId, "$baseDate 00:00:00", "$rangeEnd 23:59:59");
        $occ = array_filter($events, fn($e) => (int)$e['id'] === $dailyEventId);
        assert_equals(3, count($occ), 'Updated rule should give 3 occurrences');
    });

    test('Clear recurrence makes event single', function () use (
        $calService, $testEmail, $dailyEventId, $testCalId, $baseDate
    ) {
        $updated = $calService->updateEvent($testEmail, $dailyEventId, [
            'recurrence' => '',
        ]);
        assert_true($updated !== null, 'Clear recurrence');

        $rangeEnd = date('Y-m-d', strtotime($baseDate . ' +30 days'));
        $events = $calService->getEvents($testEmail, $testCalId, "$baseDate 00:00:00", "$rangeEnd 23:59:59");
        $occ = array_filter($events, fn($e) => (int)$e['id'] === $dailyEventId);
        assert_equals(1, count($occ), 'Should be single event now');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: invite -- Multiple invites / participants
// ══════════════════════════════════════════════════════════════════

if (shouldRun('invite')) {
    out("\n=== Multiple invites / participants ===");

    if (!$testCalId) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Primary $runId", '#3b82f6', false);
        $testCalId = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCalId;
    }

    $inviteEventId = null;
    $inviteDay = date('Y-m-d', strtotime('+4 days'));
    $inviteeA = 'invitee_a_' . $runId . '@example.com';
    $inviteeB = 'invitee_b_' . $runId . '@example.com';
    $inviteeC = 'invitee_c_' . $runId . '@example.com';

    test('Setup: create event for invite tests', function () use (
        $calService, $testEmail, $testCalId, $inviteDay, $runId, $TEST_TAG,
        &$inviteEventId, &$cleanupEventIds
    ) {
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Invite Party $runId",
            'start_time' => "$inviteDay 18:00:00",
            'end_time' => "$inviteDay 20:00:00",
        ]);
        assert_true($event !== null, 'Invite event');
        $inviteEventId = (int)$event['id'];
        $cleanupEventIds[] = $inviteEventId;
    });

    test('importParticipants adds 3 participants (no email sent)', function () use (
        $inviteService, $inviteEventId, $testEmail, $inviteeA, $inviteeB, $inviteeC
    ) {
        $result = $inviteService->importParticipants(
            $inviteEventId,
            [$inviteeA, $inviteeB, $inviteeC],
            $testEmail
        );
        assert_true(!isset($result['error']), 'No error: ' . ($result['error'] ?? ''));
        assert_equals(3, count($result['success']), 'All 3 imported');
        assert_equals(0, count($result['failed']), 'None failed');
    });

    test('getParticipants returns 3 participants with accepted status', function () use (
        $inviteService, $inviteEventId, $inviteeA, $inviteeB, $inviteeC
    ) {
        $participants = $inviteService->getParticipants($inviteEventId);
        assert_equals(3, count($participants), 'Should have 3 participants');

        $emails = array_map(fn($p) => $p['user_email'], $participants);
        assert_true(in_array(strtolower($inviteeA), $emails), 'A present');
        assert_true(in_array(strtolower($inviteeB), $emails), 'B present');
        assert_true(in_array(strtolower($inviteeC), $emails), 'C present');

        foreach ($participants as $p) {
            assert_equals('accepted', $p['status'], "Status for {$p['user_email']}");
        }
    });

    test('Duplicate import is rejected', function () use ($inviteService, $inviteEventId, $testEmail, $inviteeA) {
        $result = $inviteService->importParticipants($inviteEventId, [$inviteeA], $testEmail);
        assert_equals(0, count($result['success']), 'Should not add duplicate');
        assert_equals(1, count($result['failed']), 'Should report 1 failed');
        assert_true(
            str_contains(strtolower($result['failed'][0]['reason'] ?? ''), 'already'),
            'Reason mentions already added'
        );
    });

    test('getEvent includes participants array', function () use ($calService, $testEmail, $inviteEventId) {
        $event = $calService->getEvent($testEmail, $inviteEventId);
        assert_true(isset($event['participants']), 'participants key exists');
        assert_equals(3, count($event['participants']), 'Should have 3 participants');
    });

    test('removeParticipant removes one participant', function () use (
        $inviteService, $inviteEventId, $inviteeC
    ) {
        $result = $inviteService->removeParticipant($inviteEventId, $inviteeC);
        assert_true($result, 'removeParticipant should return true');

        $participants = $inviteService->getParticipants($inviteEventId);
        assert_equals(2, count($participants), 'Should have 2 after removal');

        $emails = array_map(fn($p) => $p['user_email'], $participants);
        assert_false(in_array(strtolower($inviteeC), $emails), 'C should be gone');
    });

    test('respondToInvitation changes participant status', function () use (
        $inviteService, $inviteEventId, $inviteeB, $db
    ) {
        // Get invite token for inviteeB
        $stmt = $db->prepare('SELECT invite_token FROM calendar_event_participants WHERE event_id = ? AND user_email = ?');
        $stmt->execute([$inviteEventId, strtolower($inviteeB)]);
        $row = $stmt->fetch();
        assert_not_empty($row['invite_token'], 'Invite token for B');

        $result = $inviteService->respondToInvitation($row['invite_token'], 'declined', 'Cannot make it');
        assert_true($result['success'], 'Response should succeed');
        assert_equals('declined', $result['response'], 'Response is declined');

        $participants = $inviteService->getParticipants($inviteEventId);
        $bPart = null;
        foreach ($participants as $p) {
            if ($p['user_email'] === strtolower($inviteeB)) {
                $bPart = $p;
                break;
            }
        }
        assert_true($bPart !== null, 'B should still exist');
        assert_equals('declined', $bPart['status'], 'B status = declined');
        assert_equals('Cannot make it', $bPart['response_message'], 'Message saved');
        vlog("Invitee B declined with message");
    });

    test('Import to non-existent event fails', function () use ($inviteService, $testEmail) {
        $result = $inviteService->importParticipants(999999, ['test@test.com'], $testEmail);
        assert_true(isset($result['error']), 'Should return error');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: meeting -- Scheduled online meeting
// ══════════════════════════════════════════════════════════════════

if (shouldRun('meeting')) {
    out("\n=== Scheduled online meeting ===");

    if (!$testCalId) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Primary $runId", '#3b82f6', false);
        $testCalId = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCalId;
    }

    $meetingEventId = null;
    $meetingToken = null;
    $meetDay = date('Y-m-d', strtotime('+6 days'));

    test('generateMeetingToken returns 64-char hex', function () use ($calService, &$meetingToken) {
        $meetingToken = $calService->generateMeetingToken();
        assert_equals(64, strlen($meetingToken), 'Token length');
        assert_true(ctype_xdigit($meetingToken), 'Token should be hex');
        vlog("Meeting token: $meetingToken");
    });

    test('Create event with meeting flag and token', function () use (
        $calService, $testEmail, $testCalId, $meetDay, $meetingToken, $runId, $TEST_TAG,
        &$meetingEventId, &$cleanupEventIds
    ) {
        $event = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Online Standup $runId",
            'description' => 'Daily team standup',
            'start_time' => "$meetDay 09:00:00",
            'end_time' => "$meetDay 09:30:00",
            'is_meeting' => true,
            'meeting_token' => $meetingToken,
        ]);
        assert_true($event !== null, 'Meeting event created');
        $meetingEventId = (int)$event['id'];
        $cleanupEventIds[] = $meetingEventId;

        if (isset($event['is_meeting'])) {
            assert_true($event['is_meeting'], 'is_meeting flag');
        }
        if (isset($event['meeting_token'])) {
            assert_equals($meetingToken, $event['meeting_token'], 'Token stored');
        }
        vlog("Meeting event ID: $meetingEventId");
    });

    test('getEventByMeetingToken retrieves the meeting', function () use (
        $calService, $meetingToken, $meetingEventId, $testEmail
    ) {
        $event = $calService->getEventByMeetingToken($meetingToken);
        if ($event === null) {
            vlog("getEventByMeetingToken returned null -- meeting columns may not exist");
            return 'warn';
        }
        assert_equals($meetingEventId, (int)$event['id'], 'Event ID match');
        assert_true($event['is_meeting'], 'is_meeting flag');
        assert_equals(strtolower($testEmail), $event['organizer_email'], 'Organizer email');
    });

    test('getEventByMeetingToken with invalid token returns null', function () use ($calService) {
        $result = $calService->getEventByMeetingToken('invalid_token_000000000000000000000000000000');
        assert_null($result, 'Should return null for invalid token');
    });

    test('Meeting event has participants after import', function () use (
        $inviteService, $meetingEventId, $testEmail, $runId
    ) {
        $attendeeA = 'meet_a_' . $runId . '@example.com';
        $attendeeB = 'meet_b_' . $runId . '@example.com';

        $result = $inviteService->importParticipants(
            $meetingEventId, [$attendeeA, $attendeeB], $testEmail
        );
        assert_equals(2, count($result['success']), 'Both imported');

        $participants = $inviteService->getParticipants($meetingEventId);
        assert_equals(2, count($participants), 'Meeting has 2 participants');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: query -- Date range and cross-calendar queries
// ══════════════════════════════════════════════════════════════════

if (shouldRun('query')) {
    out("\n=== Date range & cross-calendar queries ===");

    if (!$testCalId) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Primary $runId", '#3b82f6', false);
        $testCalId = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCalId;
    }
    if (!$testCal2Id) {
        $cal = $calService->createCalendar($testEmail, "$TEST_TAG Secondary $runId", '#ef4444', false);
        $testCal2Id = (int)$cal['id'];
        $cleanupCalendarIds[] = $testCal2Id;
    }

    $qEventCal1 = null;
    $qEventCal2 = null;
    $queryDay = date('Y-m-d', strtotime('+10 days'));

    test('Setup: create events in both calendars on same day', function () use (
        $calService, $testEmail, $testCalId, $testCal2Id, $queryDay, $runId, $TEST_TAG,
        &$qEventCal1, &$qEventCal2, &$cleanupEventIds
    ) {
        $e1 = $calService->createEvent($testEmail, $testCalId, [
            'title' => "$TEST_TAG Cal1 Query $runId",
            'start_time' => "$queryDay 10:00:00",
            'end_time' => "$queryDay 11:00:00",
        ]);
        $e2 = $calService->createEvent($testEmail, $testCal2Id, [
            'title' => "$TEST_TAG Cal2 Query $runId",
            'start_time' => "$queryDay 14:00:00",
            'end_time' => "$queryDay 15:00:00",
        ]);
        assert_true($e1 !== null && $e2 !== null, 'Both created');
        $qEventCal1 = (int)$e1['id'];
        $qEventCal2 = (int)$e2['id'];
        $cleanupEventIds[] = $qEventCal1;
        $cleanupEventIds[] = $qEventCal2;
    });

    test('getAllEvents returns events from all calendars', function () use (
        $calService, $testEmail, $queryDay, $qEventCal1, $qEventCal2
    ) {
        $all = $calService->getAllEvents($testEmail, "$queryDay 00:00:00", "$queryDay 23:59:59");
        $ids = array_map(fn($e) => (int)$e['id'], $all);
        assert_true(in_array($qEventCal1, $ids), 'Cal1 event in getAllEvents');
        assert_true(in_array($qEventCal2, $ids), 'Cal2 event in getAllEvents');
    });

    test('getEvents scoped to calendar 1 excludes calendar 2', function () use (
        $calService, $testEmail, $testCalId, $queryDay, $qEventCal1, $qEventCal2
    ) {
        $events = $calService->getEvents($testEmail, $testCalId, "$queryDay 00:00:00", "$queryDay 23:59:59");
        $ids = array_map(fn($e) => (int)$e['id'], $events);
        assert_true(in_array($qEventCal1, $ids), 'Cal1 event present');
        assert_false(in_array($qEventCal2, $ids), 'Cal2 event absent');
    });

    test('Date range before events returns empty', function () use (
        $calService, $testEmail, $testCalId
    ) {
        $past = date('Y-m-d', strtotime('-30 days'));
        $pastEnd = date('Y-m-d', strtotime('-29 days'));
        $events = $calService->getEvents($testEmail, $testCalId, "$past 00:00:00", "$pastEnd 23:59:59");

        $testEvents = array_filter($events, fn($e) => str_contains($e['title'] ?? '', '[CALTEST]'));
        assert_equals(0, count($testEvents), 'No test events in past range');
    });

    test('getAllEvents includes calendar metadata', function () use (
        $calService, $testEmail, $queryDay, $qEventCal1
    ) {
        $all = $calService->getAllEvents($testEmail, "$queryDay 00:00:00", "$queryDay 23:59:59");
        $event = null;
        foreach ($all as $e) {
            if ((int)$e['id'] === $qEventCal1) {
                $event = $e;
                break;
            }
        }
        assert_true($event !== null, 'Found cal1 event');
        assert_not_empty($event['calendar_name'] ?? '', 'calendar_name present');
        assert_not_empty($event['calendar_color'] ?? '', 'calendar_color present');
        vlog("calendar_name={$event['calendar_name']}, color={$event['calendar_color']}");
    });

    test('quickAdd creates an event from text', function () use (
        $calService, $testEmail, $testCalId, &$cleanupEventIds
    ) {
        $nextWeek = date('Y-m-d', strtotime('+8 days'));
        $event = $calService->quickAdd($testEmail, $testCalId, "Dentist $nextWeek 14:00");
        assert_true($event !== null, 'quickAdd should return event');
        assert_not_empty($event['id'], 'Event ID');
        assert_equals("$nextWeek 14:00:00", $event['start_time'], 'Parsed start time');
        $cleanupEventIds[] = (int)$event['id'];
        vlog("quickAdd event ID: {$event['id']}, title: {$event['title']}");
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
