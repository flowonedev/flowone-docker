<?php
/**
 * Calendar reminder scheduler
 *
 * Fires calendar event reminders so they reach users even when the app/tab is
 * closed (the old client-side 30s timer only worked while a tab was open).
 *
 * Every minute it asks CalendarService for reminders due in the current window
 * (evaluated in each event's own timezone, DST-correct, recurring occurrences
 * expanded), dedupes per occurrence via calendar_reminder_log, and publishes a
 * CALENDAR_REMINDER event to the user's Redis channel. The mailsync server turns
 * that into a Web Push + native FCM notification (honoring notification prefs).
 *
 * Race protection: each reminder is INSERTed into calendar_reminder_log first;
 * a duplicate-key means another run already handled it (treated as success, no
 * second publish). A flock guards against overlapping runs.
 *
 * Crontab line:
 *   * * * * * /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/process-calendar-reminders.php >> \
 *     /var/www/vps-email/backend/storage/logs/calendar-reminders-cron.log 2>&1
 *
 * Flags:
 *   --help            Show this banner
 *   --verbose         Per-reminder log lines
 *   --dry-run         Compute due reminders but do not log or publish
 *   --window=SECONDS  Fire window in seconds (default 120)
 *
 * Exit codes:
 *   0  success
 *   1  setup error
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Addons\Calendar\Services\CalendarService;
use Webmail\Services\RedisCacheService;

$opts = getopt('', ['help', 'verbose', 'dry-run', 'window::']);

if (isset($opts['help'])) {
    echo "process-calendar-reminders.php - fire due calendar reminders\n";
    echo "  --verbose         per-reminder log lines\n";
    echo "  --dry-run         compute only, no log/publish\n";
    echo "  --window=SECONDS  fire window in seconds (default 120)\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);
$windowSeconds = max(30, (int)($opts['window'] ?? 120));

foreach (['pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[calendar-reminders] missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'calendar-reminders-' . date('Ymd') . '.log';
$log = function (string $msg) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

// Prevent overlapping runs (a slow run must not stack on the next minute's run).
$lockFile = $logDir . DIRECTORY_SEPARATOR . '.calendar-reminders.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another run is in progress; exit quietly.
    exit(0);
}

try {
    $cal = new CalendarService($config);
    $db = \Webmail\Core\Database::getConnection($config);
    $redis = new RedisCacheService($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[calendar-reminders] init failed: " . $e->getMessage() . "\n");
    exit(1);
}

$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

try {
    $due = $cal->getDueReminders($now, $windowSeconds, 1440);
} catch (\Throwable $e) {
    fwrite(STDERR, "[calendar-reminders] getDueReminders failed: " . $e->getMessage() . "\n");
    exit(1);
}

$fired = 0;
$skipped = 0;

$insert = $db->prepare("
    INSERT INTO calendar_reminder_log (event_id, user_email, occurrence_start, minutes)
    VALUES (?, ?, ?, ?)
");

foreach ($due as $r) {
    if ($dryRun) {
        $log("dry-run due event={$r['event_id']} user={$r['user_email']} occ={$r['occurrence_start']} min={$r['minutes']}");
        $fired++;
        continue;
    }

    // Dedupe + race protection: claim the reminder by inserting the log row
    // first. A duplicate-key (SQLSTATE 23000) means it was already sent.
    try {
        $insert->execute([
            $r['event_id'],
            $r['user_email'],
            $r['occurrence_start'],
            $r['minutes'],
        ]);
    } catch (\PDOException $e) {
        if (($e->getCode() === '23000') || str_contains($e->getMessage(), 'Duplicate')) {
            $skipped++;
            continue;
        }
        $log("ERROR logging reminder event={$r['event_id']}: " . $e->getMessage());
        continue;
    }

    $redis->publishEvent($r['user_email'], 'CALENDAR_REMINDER', [
        'event_id' => $r['event_id'],
        'title' => $r['title'],
        'occurrence_start' => $r['occurrence_start'],
        'minutes' => $r['minutes'],
        'all_day' => $r['all_day'],
    ]);

    $fired++;
    if ($verbose) {
        $log("fired event={$r['event_id']} user={$r['user_email']} occ={$r['occurrence_start']} min={$r['minutes']}");
    }
}

// Prune old dedupe rows (cheap, indexed; usually a no-op after the first run).
if (!$dryRun) {
    try {
        $db->exec("DELETE FROM calendar_reminder_log WHERE sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (\Throwable $e) {
        // Non-fatal.
    }
}

if ($verbose || $fired > 0) {
    $log("done due=" . count($due) . " fired={$fired} skipped={$skipped} window={$windowSeconds}s");
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);
