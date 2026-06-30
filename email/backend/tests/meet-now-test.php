#!/usr/bin/env php
<?php
/**
 * meet-now-test.php — Reproduces the POST /meetings flow on the server,
 * step-by-step, and logs the exact failure point. Used to diagnose
 * "Failed to create meeting event" 500 responses.
 *
 * Server run command (replace --email with the user that hits the bug):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/meet-now-test.php \
 *     --email=robert@pixelranger.hu --verbose
 *
 * Optional flags:
 *   --help              Usage
 *   --verbose           Stack traces / extra output
 *   --json              JSON summary on stdout
 *   --skip-send         Do not insert the test event (read-only probe)
 *   --smoke             Pre-flight + DB connectivity only
 *   --only=group1,...   pre,calendar,event,meeting,cleanup
 *
 * Always non-destructive: any rows it creates carry the FLOWONE-TEST prefix
 * and are removed in the cleanup phase (also runs on SIGINT/SIGTERM).
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$bootstrapPath = __DIR__ . '/../cron/bootstrap.php';
if (!is_file($bootstrapPath)) {
    fwrite(STDERR, "Missing bootstrap: $bootstrapPath\n");
    exit(1);
}
require_once $bootstrapPath;

$config = require __DIR__ . '/../src/config.php';

$longopts = ['help', 'verbose', 'json', 'skip-send', 'smoke', 'only:', 'email::'];
$opts = getopt('', $longopts) ?: [];

if (isset($opts['help'])) {
    echo <<<USAGE
meet-now-test.php — diagnose POST /meetings failures

  --email=USER@DOMAIN  Account whose calendar to probe (required for full run)
  --verbose            Print stack traces and SQL details
  --json               JSON summary on stdout
  --skip-send          Read-only: probe lookups but do not INSERT
  --smoke              Pre-flight + DB connectivity only
  --only=...           Comma list of: pre,calendar,event,meeting,cleanup
  --help               This message

USAGE;
    exit(0);
}

$verbose = isset($opts['verbose']);
$jsonOut = isset($opts['json']);
$skipSend = isset($opts['skip-send']);
$smoke = isset($opts['smoke']);
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string)$opts['only'])) : null;
$email = isset($opts['email']) ? strtolower(trim((string)$opts['email'])) : '';

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/meet-now-test-' . gmdate('Ymd-His') . '.log';

$results = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'fail_msgs' => []];
$created = ['event_ids' => [], 'calendar_ids' => [], 'conversation_ids' => []];

function log_line(string $logFile, string $msg): void
{
    $line = '[' . gmdate('H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $msg . "\n";
}

function want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

function run_test(string $logFile, string $name, callable $fn, bool $verbose, array &$results): string
{
    $t0 = microtime(true);
    try {
        $r = $fn();
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($r === 'warn') {
            $results['warnings']++;
            log_line($logFile, "[WARN] {$name} ({$ms}ms)");
            return 'WARN';
        }
        $results['passed']++;
        log_line($logFile, "[PASS] {$name} ({$ms}ms)");
        return 'PASS';
    } catch (\Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $results['failed']++;
        $msg = $e->getMessage();
        $results['fail_msgs'][] = "{$name}: {$msg}";
        log_line($logFile, "[FAIL] {$name} ({$ms}ms) {$msg}");
        if ($verbose) {
            log_line($logFile, '  at ' . $e->getFile() . ':' . $e->getLine());
            log_line($logFile, $e->getTraceAsString());
        }
        return 'FAIL';
    }
}

// ---------- Pre-flight ----------
foreach (['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Pre-flight FAIL: missing extension {$ext}\n");
        exit(1);
    }
}

// Cleanup handler: always remove anything we created
$cleanup = function () use (&$created, &$logFile, $config) {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try {
        $db = \Webmail\Core\Database::getConnection($config);
        if (!empty($created['event_ids'])) {
            $in = implode(',', array_map('intval', $created['event_ids']));
            $db->exec("DELETE FROM calendar_events WHERE id IN ($in)");
            log_line($logFile, "[CLEANUP] removed " . count($created['event_ids']) . " test event(s)");
        }
        if (!empty($created['calendar_ids'])) {
            $in = implode(',', array_map('intval', $created['calendar_ids']));
            $db->exec("DELETE FROM calendars WHERE id IN ($in) AND name LIKE 'FLOWONE-TEST%'");
            log_line($logFile, "[CLEANUP] removed " . count($created['calendar_ids']) . " test calendar(s)");
        }
        if (!empty($created['conversation_ids'])) {
            $in = implode(',', array_map('intval', $created['conversation_ids']));
            $db->exec("DELETE FROM chat_messages WHERE conversation_id IN ($in)");
            $db->exec("DELETE FROM chat_participants WHERE conversation_id IN ($in)");
            $db->exec("DELETE FROM chat_conversations WHERE id IN ($in) AND name LIKE 'Meeting: FLOWONE-TEST%'");
            log_line($logFile, "[CLEANUP] removed " . count($created['conversation_ids']) . " test conversation(s)");
        }
    } catch (\Throwable $e) {
        log_line($logFile, "[CLEANUP] error: " . $e->getMessage());
    }
};
register_shutdown_function($cleanup);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use ($cleanup) { $cleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () use ($cleanup) { $cleanup(); exit(143); });
}

if ($smoke) {
    run_test($logFile, 'DB reachable', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->query('SELECT 1');
        return true;
    }, $verbose, $results);
    log_line($logFile, "[SMOKE] OK");
    exit($results['failed'] > 0 ? 1 : 0);
}

if ($email === '') {
    fwrite(STDERR, "ERROR: --email=USER@DOMAIN is required for the full run.\n");
    fwrite(STDERR, "Run with --smoke for a connectivity-only check, or --help.\n");
    exit(2);
}

log_line($logFile, "=== meet-now-test for {$email} ===");
log_line($logFile, 'PHP ' . PHP_VERSION . ', sapi=' . php_sapi_name());
log_line($logFile, 'logfile: ' . $logFile);

// ---------- Stage: pre ----------
if (want($only, 'pre')) {
    run_test($logFile, 'DB reachable', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->query('SELECT 1');
        return true;
    }, $verbose, $results);

    run_test($logFile, 'calendar_events table exists', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $r = $db->query("SHOW TABLES LIKE 'calendar_events'");
        if (!$r || $r->rowCount() === 0) {
            throw new \RuntimeException('calendar_events table missing');
        }
        return true;
    }, $verbose, $results);

    run_test($logFile, 'calendars table exists', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $r = $db->query("SHOW TABLES LIKE 'calendars'");
        if (!$r || $r->rowCount() === 0) {
            throw new \RuntimeException('calendars table missing');
        }
        return true;
    }, $verbose, $results);

    run_test($logFile, 'meeting columns present (is_meeting, meeting_token)', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $missing = [];
        foreach (['is_meeting', 'meeting_token', 'meeting_conversation_id'] as $col) {
            $st = $db->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_events' AND COLUMN_NAME = ?
            ");
            $st->execute([$col]);
            if ((int)$st->fetchColumn() === 0) {
                $missing[] = $col;
            }
        }
        if ($missing) {
            throw new \RuntimeException('Missing columns: ' . implode(', ', $missing));
        }
        return true;
    }, $verbose, $results);

    run_test($logFile, 'sql_mode (warn if STRICT)', function () use ($config, $logFile) {
        $db = \Webmail\Core\Database::getConnection($config);
        $mode = (string)($db->query('SELECT @@sql_mode')->fetchColumn() ?: '');
        log_line($logFile, '  sql_mode=' . ($mode ?: '(empty)'));
        if (stripos($mode, 'STRICT') !== false) {
            return 'warn';
        }
        return true;
    }, $verbose, $results);
}

// ---------- Stage: calendar ----------
$calendarId = null;
if (want($only, 'calendar')) {
    run_test($logFile, 'getCalendars(email) returns ≥1 calendar', function () use ($config, $email, &$calendarId, $logFile, $verbose) {
        $svc = new \Webmail\Addons\Calendar\Services\CalendarService($config);
        $cals = $svc->getCalendars($email);
        if (empty($cals)) {
            throw new \RuntimeException('getCalendars returned empty (and could not auto-create)');
        }
        $defaults = array_filter($cals, fn($c) => !empty($c['is_default']));
        $calendarId = $defaults ? (int)reset($defaults)['id'] : (int)($cals[0]['id'] ?? 0);
        if ($calendarId <= 0) {
            throw new \RuntimeException('No usable calendar id from getCalendars; raw=' . json_encode($cals));
        }
        if ($verbose) {
            log_line($logFile, '  picked calendar id=' . $calendarId);
        }
        return true;
    }, $verbose, $results);

    run_test($logFile, 'getCalendar(email, id) round-trip matches', function () use ($config, $email, &$calendarId, $logFile) {
        if (!$calendarId) return 'warn';
        $svc = new \Webmail\Addons\Calendar\Services\CalendarService($config);
        $cal = $svc->getCalendar($email, $calendarId);
        if (!$cal) {
            // This is the silent-null path inside createEvent — the smoking gun
            // when "Failed to create meeting event" appears with no PHP log.
            $db = \Webmail\Core\Database::getConnection($config);
            $st = $db->prepare('SELECT id, user_email FROM calendars WHERE id = ?');
            $st->execute([$calendarId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            $owner = $row['user_email'] ?? '(no row)';
            throw new \RuntimeException(
                "getCalendar({$email}, {$calendarId}) returned null. Stored owner='{$owner}', " .
                'lookup_email=' . $email . ' (case-sensitive mismatch is the most likely cause)'
            );
        }
        return true;
    }, $verbose, $results);
}

// ---------- Stage: event (regular event INSERT, no meeting fields) ----------
if (want($only, 'event') && $calendarId) {
    run_test($logFile, 'createEvent (regular, no meeting fields) succeeds', function () use ($config, $email, $calendarId, $skipSend, &$created) {
        if ($skipSend) return 'warn';
        $svc = new \Webmail\Addons\Calendar\Services\CalendarService($config);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $end = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
        $event = $svc->createEvent($email, $calendarId, [
            'title' => 'FLOWONE-TEST regular event',
            'description' => 'auto-removed',
            'location' => null,
            'start_time' => $now,
            'end_time' => $end,
            'all_day' => false,
            'timezone' => 'UTC',
            'reminders' => [],
            'color' => null,
        ]);
        if (!$event) {
            throw new \RuntimeException('createEvent returned null for a plain event (no meeting fields)');
        }
        $created['event_ids'][] = (int)$event['id'];
        return true;
    }, $verbose, $results);
}

// ---------- Stage: meeting (full createMeeting payload) ----------
if (want($only, 'meeting') && $calendarId) {
    run_test($logFile, 'createEvent (meeting payload, ISO-8601 datetimes — chat "Meet Now" shape)', function () use ($config, $email, $calendarId, $skipSend, &$created) {
        if ($skipSend) return 'warn';
        $svc = new \Webmail\Addons\Calendar\Services\CalendarService($config);
        // Reproduce the exact JS toISOString() format that broke production:
        //   "2026-05-12T17:25:00.123Z" — has 'T', milliseconds, 'Z' suffix.
        // STRICT_TRANS_TABLES rejects this on DATETIME columns.
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $end = gmdate('Y-m-d\TH:i:s.000\Z', time() + 3600);
        $token = bin2hex(random_bytes(32));
        $event = $svc->createEvent($email, $calendarId, [
            'title' => 'FLOWONE-TEST meeting (ISO datetime)',
            'description' => null,
            'location' => null,
            'start_time' => $now,
            'end_time' => $end,
            'all_day' => false,
            'timezone' => 'UTC',
            'reminders' => [],
            'color' => null,
            'is_meeting' => true,
            'meeting_token' => $token,
            'meeting_conversation_id' => null,
        ]);
        if (!$event) {
            throw new \RuntimeException(
                'createEvent returned null for ISO-8601 datetime payload. '
                . 'Regression: CalendarService::normalizeDateTime() should convert ISO to MySQL DATETIME.'
            );
        }
        // Verify the stored value was normalized to MySQL DATETIME format
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$event['start_time'])) {
            throw new \RuntimeException(
                'Stored start_time is not MySQL DATETIME format: ' . var_export($event['start_time'], true)
            );
        }
        $created['event_ids'][] = (int)$event['id'];
        return true;
    }, $verbose, $results);

    run_test($logFile, 'createEvent (meeting payload, MySQL DATETIME — calendar view shape)', function () use ($config, $email, $calendarId, $skipSend, &$created) {
        if ($skipSend) return 'warn';
        $svc = new \Webmail\Addons\Calendar\Services\CalendarService($config);
        $now = gmdate('Y-m-d H:i:s');
        $end = gmdate('Y-m-d H:i:s', time() + 3600);
        $token = bin2hex(random_bytes(32));
        $event = $svc->createEvent($email, $calendarId, [
            'title' => 'FLOWONE-TEST meeting (MySQL datetime)',
            'description' => null,
            'location' => null,
            'start_time' => $now,
            'end_time' => $end,
            'all_day' => false,
            'timezone' => 'UTC',
            'reminders' => [],
            'color' => null,
            'is_meeting' => true,
            'meeting_token' => $token,
            'meeting_conversation_id' => null,
        ]);
        if (!$event) {
            throw new \RuntimeException('createEvent returned null with meeting payload — this matches the production 500');
        }
        $created['event_ids'][] = (int)$event['id'];
        return true;
    }, $verbose, $results);

    run_test($logFile, 'createMeetingConversation chat path (catches own errors)', function () use ($config, $email, &$created) {
        try {
            $chat = new \Webmail\Addons\Chat\Services\ChatService($config);
            $r = $chat->createMeetingConversation($email, 'FLOWONE-TEST conversation', []);
            if (!empty($r['conversation_id'])) {
                $created['conversation_ids'][] = (int)$r['conversation_id'];
            }
            return $r['success'] ? true : 'warn';
        } catch (\Throwable $e) {
            return 'warn';
        }
    }, $verbose, $results);
}

// ---------- Summary ----------
$summary = sprintf(
    "Passed: %d  Failed: %d  Warnings: %d",
    $results['passed'], $results['failed'], $results['warnings']
);
log_line($logFile, '');
log_line($logFile, '=== ' . $summary . ' ===');
if (!empty($results['fail_msgs'])) {
    log_line($logFile, 'Failures:');
    foreach ($results['fail_msgs'] as $m) {
        log_line($logFile, '  - ' . $m);
    }
}

if ($jsonOut) {
    echo "\n" . json_encode([
        'passed' => $results['passed'],
        'failed' => $results['failed'],
        'warnings' => $results['warnings'],
        'fail_msgs' => $results['fail_msgs'],
        'log_file' => $logFile,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($results['failed'] > 0 ? 1 : 0);
