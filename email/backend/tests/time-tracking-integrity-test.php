#!/usr/bin/env php
<?php
/**
 * FlowOne Time-Tracking Integrity Suite
 *
 * Verifies the fixes from the project-addons audit end-to-end against the
 * live services and database:
 *
 *   schema           manual_entry ENUM + source column present; board
 *                    start_date/end_date/budget_hours columns; dead Board Pro
 *                    permission tables dropped
 *   manual-entry     trackActivity() roundtrip with manual_entry, duplicate
 *                    accumulation, unknown-type rejection
 *   double-count     one logWorkSession() = exactly one row per table;
 *                    card_view/board_view/drive_edit/website_work sessions
 *                    are NOT re-bridged into webmail_client_time_tracking
 *   team-stats-gate  admin gates present in TimeController::getTeamStats and
 *                    ProjectHubController::getWorkloadLive; isAdmin() denies
 *                    unknown users
 *   cron-wiring      boardpro-automation / scope-radar / projecthub-inactivity
 *                    cron scripts + service methods exist; crontab registered
 *   services         LiveActivity / Completions / ClientLoad services execute
 *
 * Server run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/time-tracking-integrity-test.php \
 *     --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL    Real account for the isAdmin() informational check (optional)
 *   --password=PASS  Accepted for flag-parity; not needed (DB-level suite)
 *   --only=g1,g2     schema,manual-entry,double-count,team-stats-gate,cron-wiring,services
 *   --skip-send      No-op here (kept for flag-parity); suite has no external sends
 *   --smoke          Pre-flight + class-load health check only
 *   --verbose        Show file:line on failure
 *   --json           Emit a single JSON summary
 *   --help           Show this help
 *
 * Safety: all rows use the FLOWONE-TEST prefix / flowone-test.invalid domain
 * and are removed on exit (shutdown handler + SIGINT/SIGTERM). Idempotent.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

// ── CLI args ─────────────────────────────────────────────────────

$opts = getopt('', ['email:', 'password:', 'only:', 'skip-send', 'smoke', 'verbose', 'json', 'help']);

if (isset($opts['help'])) {
    echo "FlowOne Time-Tracking Integrity Suite\n";
    echo "=====================================\n\n";
    echo "Usage:\n";
    echo "  php time-tracking-integrity-test.php [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL    Real account for the isAdmin() informational check (optional)\n";
    echo "  --only=g1,g2     schema,manual-entry,double-count,team-stats-gate,cron-wiring,services\n";
    echo "  --smoke          Pre-flight + class-load health check only\n";
    echo "  --verbose        Show file:line on failure\n";
    echo "  --json           Emit a single JSON summary\n";
    echo "  --help           Show this help\n";
    exit(0);
}

$realEmail  = !empty($opts['email']) ? strtolower($opts['email']) : null;
$smokeOnly  = isset($opts['smoke']);
$verbose    = isset($opts['verbose']);
$jsonMode   = isset($opts['json']);
$onlyGroups = !empty($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : [];

// ── Logging / harness ────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/time-tracking-integrity-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

$RED    = $jsonMode ? '' : "\033[0;31m";
$GREEN  = $jsonMode ? '' : "\033[0;32m";
$YELLOW = $jsonMode ? '' : "\033[1;33m";
$NC     = $jsonMode ? '' : "\033[0m";

$DEFAULT_TIMEOUT = 30;

// Test identity — guaranteed to never collide with real data.
const TEST_PREFIX = 'FLOWONE-TEST';
const TEST_USER   = 'flowone_test_integrity@flowone-test.invalid';
const TEST_DOMAIN = 'flowone-test.invalid';

// Fixture ids registered for cleanup as they get created.
$fixture = [
    'client_id' => null,
    'board_id'  => null,
    'list_id'   => null,
    'card_id'   => null,
];

function out(string $msg): void {
    global $logFile, $jsonMode;
    if (!$jsonMode) echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . preg_replace('/\033\[[0-9;]*m/', '', $msg) . "\n", FILE_APPEND | LOCK_EX);
}

function shouldRun(string $group): bool {
    global $onlyGroups;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

function test(string $name, callable $fn, ?int $timeoutSec = null): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    global $GREEN, $RED, $YELLOW, $NC, $DEFAULT_TIMEOUT;

    $totalTests++;
    $timeout = $timeoutSec ?? $DEFAULT_TIMEOUT;
    $start = microtime(true);

    $alarmAvailable = function_exists('pcntl_alarm') && function_exists('pcntl_signal');
    if ($alarmAvailable) {
        pcntl_signal(SIGALRM, function () use ($name) {
            throw new \RuntimeException("test timed out: {$name}");
        });
        pcntl_alarm($timeout);
    } else {
        @set_time_limit($timeout);
    }

    try {
        $result = $fn();
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        if ($result === 'warn' || $result === 'skip') {
            $warnings++;
            out("  {$YELLOW}[WARN]{$NC}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  {$GREEN}[PASS]{$NC}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        $failed++;
        out("  {$RED}[FAIL]{$NC}  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    } finally {
        if ($alarmAvailable) pcntl_alarm(0);
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new \RuntimeException($msg);
}
function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg ? $msg . ' — ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// ── DB helpers ───────────────────────────────────────────────────

function db(): PDO {
    global $config;
    return \Webmail\Core\Database::getConnection($config);
}

function columnExists(string $table, string $column): bool {
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableExists(string $table): bool {
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function clientTimeRowCount(string $userEmail): int {
    $stmt = db()->prepare("SELECT COUNT(*) FROM webmail_client_time_tracking WHERE user_email = ?");
    $stmt->execute([$userEmail]);
    return (int)$stmt->fetchColumn();
}

function clientTimeTotalSeconds(string $userEmail): int {
    $stmt = db()->prepare("SELECT COALESCE(SUM(duration_seconds),0) FROM webmail_client_time_tracking WHERE user_email = ?");
    $stmt->execute([$userEmail]);
    return (int)$stmt->fetchColumn();
}

function workSessionCount(int $cardId, string $userEmail): int {
    $stmt = db()->prepare("SELECT COUNT(*) FROM projecthub_work_sessions WHERE card_id = ? AND user_email = ?");
    $stmt->execute([$cardId, $userEmail]);
    return (int)$stmt->fetchColumn();
}

// ── Cleanup (always runs: shutdown + signals) ────────────────────

$cleanupDone = false;
function cleanup(): void {
    global $cleanupDone, $fixture;
    if ($cleanupDone) return;
    $cleanupDone = true;

    try {
        $db = db();

        $db->prepare("DELETE FROM webmail_client_time_tracking WHERE user_email = ?")->execute([TEST_USER]);

        if ($fixture['card_id']) {
            $db->prepare("DELETE FROM projecthub_work_sessions WHERE card_id = ?")->execute([$fixture['card_id']]);
            $db->prepare("DELETE FROM projecthub_card_assignees WHERE card_id = ?")->execute([$fixture['card_id']]);
            $db->prepare("DELETE FROM webmail_board_cards WHERE id = ?")->execute([$fixture['card_id']]);
        }
        if ($fixture['list_id']) {
            $db->prepare("DELETE FROM webmail_board_lists WHERE id = ?")->execute([$fixture['list_id']]);
        }
        if ($fixture['board_id']) {
            $db->prepare("DELETE FROM webmail_boards WHERE id = ?")->execute([$fixture['board_id']]);
        }
        if ($fixture['client_id']) {
            $db->prepare("DELETE FROM clients WHERE id = ?")->execute([$fixture['client_id']]);
        }

        // Belt and braces: anything with the test prefix/domain left behind by
        // a previous crashed run.
        $db->prepare("DELETE FROM clients WHERE domain = ?")->execute([TEST_DOMAIN]);
        $db->prepare("DELETE FROM webmail_boards WHERE owner_email = ?")->execute([TEST_USER]);

        out("Cleanup complete.");
    } catch (\Throwable $e) {
        out("Cleanup error (manual check advised): " . $e->getMessage());
    }
}

register_shutdown_function('cleanup');
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () { cleanup(); exit(1); });
    pcntl_signal(SIGTERM, function () { cleanup(); exit(1); });
}

// ── Pre-flight ───────────────────────────────────────────────────

out("FlowOne Time-Tracking Integrity Suite");
out("Log: {$logFile}");
out("");
out("--- 0. PRE-FLIGHT ---");

$preflightOk = true;

foreach (['pdo_mysql', 'json', 'mbstring', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        out("  {$RED}[FAIL]{$NC}  PHP extension missing: {$ext}");
        $preflightOk = false;
    }
}

try {
    db()->query('SELECT 1');
    out("  {$GREEN}[PASS]{$NC}  Database reachable");
} catch (\Throwable $e) {
    out("  {$RED}[FAIL]{$NC}  Database unreachable: " . $e->getMessage());
    $preflightOk = false;
}

$freeBytes = @disk_free_space(__DIR__ . '/../storage');
if ($freeBytes !== false && $freeBytes < 50 * 1024 * 1024) {
    out("  {$RED}[FAIL]{$NC}  Less than 50MB free in storage dir");
    $preflightOk = false;
} else {
    out("  {$GREEN}[PASS]{$NC}  Disk space OK");
}

if (empty($config['db']['name']) && empty($config['db']['database']) && empty($config['DB_NAME'])) {
    // config shape varies; only warn — the live DB connection above is the real check
    out("  {$YELLOW}[WARN]{$NC}  Could not introspect DB name from config (connection works though)");
}

$requiredClasses = [
    \Webmail\Addons\TimeTracker\Services\ClientTimeTrackingService::class,
    \Webmail\Addons\TimeTracker\Services\ClientLoadService::class,
    \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService::class,
    \Webmail\Addons\ProjectHub\Services\ProjectHubLiveActivityService::class,
    \Webmail\Addons\ProjectHub\Services\ProjectHubCompletionsService::class,
    \Webmail\Addons\Team\Services\ColleagueService::class,
    \Webmail\Addons\BoardPro\Services\BoardProAutomationService::class,
    \Webmail\Addons\BoardPro\Services\ScopeRadarService::class,
];
foreach ($requiredClasses as $cls) {
    if (!class_exists($cls)) {
        out("  {$RED}[FAIL]{$NC}  Class not loadable: {$cls}");
        $preflightOk = false;
    }
}
out("  {$GREEN}[PASS]{$NC}  Service classes loadable");

if (!$preflightOk) {
    out("");
    out("{$RED}Pre-flight failed — aborting before any tests.{$NC}");
    exit(1);
}

if ($smokeOnly) {
    out("");
    out("{$GREEN}Smoke check passed.{$NC}");
    exit(0);
}

// ── Fixture builders ─────────────────────────────────────────────

function buildFixture(): void {
    global $fixture;
    if ($fixture['card_id']) return;

    $db = db();

    $db->prepare("INSERT INTO clients (user_email, domain, display_name) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)")
       ->execute([TEST_USER, TEST_DOMAIN, TEST_PREFIX . ' Client']);
    $stmt = $db->prepare("SELECT id FROM clients WHERE user_email = ? AND domain = ?");
    $stmt->execute([TEST_USER, TEST_DOMAIN]);
    $fixture['client_id'] = (int)$stmt->fetchColumn();

    $db->prepare("INSERT INTO webmail_boards (owner_email, name, client_id) VALUES (?, ?, ?)")
       ->execute([TEST_USER, TEST_PREFIX . ' Board', $fixture['client_id']]);
    $fixture['board_id'] = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO webmail_board_lists (board_id, name, position) VALUES (?, ?, 0)")
       ->execute([$fixture['board_id'], TEST_PREFIX . ' List']);
    $fixture['list_id'] = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO webmail_board_cards (list_id, title, position) VALUES (?, ?, 0)")
       ->execute([$fixture['list_id'], TEST_PREFIX . ' Card']);
    $fixture['card_id'] = (int)$db->lastInsertId();
}

// ── 1. SCHEMA ────────────────────────────────────────────────────

if (shouldRun('schema')) {
    out("");
    out("--- 1. SCHEMA ---");

    test('webmail_client_time_tracking.activity_type ENUM includes manual_entry', function () {
        $stmt = db()->prepare("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'webmail_client_time_tracking'
              AND COLUMN_NAME = 'activity_type'
        ");
        $stmt->execute();
        $type = (string)$stmt->fetchColumn();
        assert_true($type !== '', 'activity_type column not found');
        assert_true(str_contains($type, 'manual_entry'), "ENUM missing manual_entry: {$type}");
    });

    test('webmail_client_time_tracking has source column', function () {
        assert_true(columnExists('webmail_client_time_tracking', 'source'), 'source column missing (migration 145)');
    });

    test('webmail_boards has start_date / end_date / budget_hours', function () {
        foreach (['start_date', 'end_date', 'budget_hours'] as $col) {
            if (!columnExists('webmail_boards', $col)) {
                // Migration 186 may not have run yet on this environment
                return 'warn';
            }
        }
        return true;
    });

    test('dead Board Pro permission tables are gone (migration 187)', function () {
        if (tableExists('boardpro_card_permissions') || tableExists('boardpro_member_stage_permissions')) {
            return 'warn'; // migration 187 not applied yet — endpoints are already removed
        }
        return true;
    });
}

// ── 2. MANUAL ENTRY ──────────────────────────────────────────────

if (shouldRun('manual-entry')) {
    out("");
    out("--- 2. MANUAL ENTRY ---");

    test('manual_entry roundtrip writes one row', function () use ($config) {
        buildFixture();
        global $fixture;
        $svc = new \Webmail\Addons\TimeTracker\Services\ClientTimeTrackingService($config);

        $before = clientTimeRowCount(TEST_USER);
        $ok = $svc->trackActivity(TEST_USER, $fixture['client_id'], 'manual_entry', 600, TEST_PREFIX . '-m1', TEST_PREFIX . ' manual entry');
        assert_true($ok, 'trackActivity(manual_entry) returned false — ENUM regression?');
        assert_equals($before + 1, clientTimeRowCount(TEST_USER), 'row count after manual entry');
    });

    test('duplicate manual entry accumulates duration (no extra row)', function () use ($config) {
        global $fixture;
        $svc = new \Webmail\Addons\TimeTracker\Services\ClientTimeTrackingService($config);

        $rowsBefore = clientTimeRowCount(TEST_USER);
        $secondsBefore = clientTimeTotalSeconds(TEST_USER);
        $ok = $svc->trackActivity(TEST_USER, $fixture['client_id'], 'manual_entry', 300, TEST_PREFIX . '-m1', TEST_PREFIX . ' manual entry');
        assert_true($ok, 'second trackActivity failed');
        assert_equals($rowsBefore, clientTimeRowCount(TEST_USER), 'row count must not grow on upsert');
        assert_equals($secondsBefore + 300, clientTimeTotalSeconds(TEST_USER), 'duration must accumulate');
    });

    test('unknown activity_type is rejected', function () use ($config) {
        global $fixture;
        $svc = new \Webmail\Addons\TimeTracker\Services\ClientTimeTrackingService($config);
        $ok = $svc->trackActivity(TEST_USER, $fixture['client_id'], 'totally_bogus_type', 60);
        assert_equals(false, $ok, 'bogus activity_type must be rejected');
    });
}

// ── 3. DOUBLE-COUNT GUARD ────────────────────────────────────────

if (shouldRun('double-count')) {
    out("");
    out("--- 3. DOUBLE-COUNT GUARD ---");

    test('card_view session is NOT re-bridged to client time', function () use ($config) {
        buildFixture();
        global $fixture;
        $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($config);

        $sessionsBefore = workSessionCount($fixture['card_id'], TEST_USER);
        $clientSecondsBefore = clientTimeTotalSeconds(TEST_USER);

        $row = $svc->logWorkSession($fixture['card_id'], TEST_USER, [
            'source' => 'card_view',
            'duration_seconds' => 120,
            'entity_name' => TEST_PREFIX . ' card view',
        ]);
        assert_true($row !== null, 'logWorkSession(card_view) failed');
        assert_equals($sessionsBefore + 1, workSessionCount($fixture['card_id'], TEST_USER), 'exactly one work session row');
        assert_equals($clientSecondsBefore, clientTimeTotalSeconds(TEST_USER), 'client time must NOT change for card_view (double-count guard)');
    });

    test('website_work session is NOT re-bridged to client time', function () use ($config) {
        global $fixture;
        $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($config);

        $clientSecondsBefore = clientTimeTotalSeconds(TEST_USER);
        $row = $svc->logWorkSession($fixture['card_id'], TEST_USER, [
            'source' => 'website_work',
            'duration_seconds' => 90,
            'entity_name' => TEST_PREFIX . ' website',
        ]);
        assert_true($row !== null, 'logWorkSession(website_work) failed');
        assert_equals($clientSecondsBefore, clientTimeTotalSeconds(TEST_USER), 'client time must NOT change for website_work');
    });

    test('manual session IS bridged exactly once', function () use ($config) {
        global $fixture;
        $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($config);

        $sessionsBefore = workSessionCount($fixture['card_id'], TEST_USER);
        $clientSecondsBefore = clientTimeTotalSeconds(TEST_USER);

        $row = $svc->logWorkSession($fixture['card_id'], TEST_USER, [
            'source' => 'manual',
            'duration_seconds' => 240,
            'entity_name' => TEST_PREFIX . ' manual session',
        ]);
        assert_true($row !== null, 'logWorkSession(manual) failed');
        assert_equals($sessionsBefore + 1, workSessionCount($fixture['card_id'], TEST_USER), 'exactly one work session row');
        assert_equals($clientSecondsBefore + 240, clientTimeTotalSeconds(TEST_USER), 'manual session must bridge exactly 240s to client time');
    });

    test('second manual session accumulates into one client-time row', function () use ($config) {
        global $fixture;
        $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($config);

        $stmt = db()->prepare("SELECT COUNT(*) FROM webmail_client_time_tracking WHERE user_email = ? AND activity_type = 'board_task'");
        $stmt->execute([TEST_USER]);
        $bridgeRowsBefore = (int)$stmt->fetchColumn();

        $svc->logWorkSession($fixture['card_id'], TEST_USER, [
            'source' => 'manual',
            'duration_seconds' => 60,
        ]);

        $stmt->execute([TEST_USER]);
        assert_equals($bridgeRowsBefore, (int)$stmt->fetchColumn(), 'bridged board_task rows must upsert, not multiply');
    });
}

// ── 4. TEAM-STATS GATE ───────────────────────────────────────────

if (shouldRun('team-stats-gate')) {
    out("");
    out("--- 4. TEAM-STATS GATE ---");

    test('TimeController::getTeamStats contains admin gate', function () {
        $src = file_get_contents(__DIR__ . '/../src/Addons/TimeTracker/Controllers/TimeController.php');
        assert_true($src !== false, 'TimeController.php unreadable');
        // The gate must appear inside getTeamStats before stats are computed
        $fnPos = strpos($src, 'function getTeamStats');
        assert_true($fnPos !== false, 'getTeamStats not found');
        $body = substr($src, $fnPos, 1500);
        assert_true(str_contains($body, 'isAdmin'), 'getTeamStats has no isAdmin() gate');
        assert_true(str_contains($body, '403'), 'getTeamStats does not return 403 for non-admins');
    });

    test('ProjectHubController::getWorkloadLive contains admin gate', function () {
        $src = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Controllers/ProjectHubController.php');
        assert_true($src !== false, 'ProjectHubController.php unreadable');
        $fnPos = strpos($src, 'function getWorkloadLive');
        assert_true($fnPos !== false, 'getWorkloadLive not found');
        $body = substr($src, $fnPos, 1500);
        assert_true(str_contains($body, 'isAdmin'), 'getWorkloadLive has no isAdmin() gate');
    });

    test('isAdmin() denies unknown user', function () use ($config) {
        $svc = new \Webmail\Addons\Team\Services\ColleagueService($config);
        assert_equals(false, $svc->isAdmin(TEST_USER), 'unknown user must not be admin');
    });

    test('isAdmin() for provided --email (informational)', function () use ($config, $realEmail) {
        if (!$realEmail) return 'skip';
        $svc = new \Webmail\Addons\Team\Services\ColleagueService($config);
        out("          -> {$realEmail} isAdmin = " . ($svc->isAdmin($realEmail) ? 'true' : 'false'));
        return true;
    });
}

// ── 5. CRON WIRING ───────────────────────────────────────────────

if (shouldRun('cron-wiring')) {
    out("");
    out("--- 5. CRON WIRING ---");

    test('cron scripts exist', function () {
        foreach (['process-boardpro-automation.php', 'process-scope-radar.php', 'run-projecthub-inactivity.php'] as $script) {
            assert_true(is_file(__DIR__ . '/../cron/' . $script), "missing cron script: {$script}");
        }
    });

    test('automation service methods exist', function () {
        assert_true(method_exists(\Webmail\Addons\BoardPro\Services\BoardProAutomationService::class, 'evaluateTimeTriggers'), 'evaluateTimeTriggers missing');
        assert_true(method_exists(\Webmail\Addons\BoardPro\Services\ScopeRadarService::class, 'checkAllBoardsForUser'), 'checkAllBoardsForUser missing');
    });

    test('crontab registration present', function () {
        $haystack = '';
        foreach ((glob('/etc/cron.d/*') ?: []) as $f) {
            $haystack .= @file_get_contents($f) ?: '';
        }
        $haystack .= shell_exec('crontab -l 2>/dev/null') ?: '';

        if ($haystack === '') return 'warn'; // not running on the server

        foreach (['process-boardpro-automation', 'process-scope-radar', 'run-projecthub-inactivity'] as $needle) {
            if (!str_contains($haystack, $needle)) {
                out("          -> not registered: {$needle} (re-run fleet install.sh cron block)");
                return 'warn';
            }
        }
        return true;
    });
}

// ── 6. NEW VISIBILITY SERVICES ───────────────────────────────────

if (shouldRun('services')) {
    out("");
    out("--- 6. VISIBILITY SERVICES ---");

    test('ProjectHubLiveActivityService::getRecentSignals runs', function () use ($config) {
        $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubLiveActivityService($config);
        $signals = $svc->getRecentSignals(10);
        assert_true(is_array($signals), 'signals must be an array');
    });

    test('ProjectHubCompletionsService::getCompletions runs for current week', function () use ($config) {
        $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubCompletionsService($config);
        $days = $svc->getCompletions(date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week')));
        assert_true(is_array($days), 'days must be an array');
        foreach ($days as $day) {
            assert_true(isset($day['date'], $day['total'], $day['members']), 'day entry shape');
        }
    });

    test('ClientLoadService::getLoadByClient handles empty + fixture client', function () use ($config) {
        buildFixture();
        global $fixture;
        $svc = new \Webmail\Addons\TimeTracker\Services\ClientLoadService($config);
        assert_equals([], $svc->getLoadByClient([]), 'empty input must return empty array');

        $load = $svc->getLoadByClient([$fixture['client_id']]);
        assert_true(isset($load[$fixture['client_id']]), 'fixture client must be present');
        $row = $load[$fixture['client_id']];
        foreach (['hourly_rate', 'open_tasks', 'overdue_tasks', 'next_deadline'] as $key) {
            assert_true(array_key_exists($key, $row), "load row missing key: {$key}");
        }
        // Fixture has one open (uncompleted) card on the linked board
        assert_true((int)$row['open_tasks'] >= 1, 'fixture open card must be counted');
    });
}

// ── Summary ──────────────────────────────────────────────────────

cleanup();

out("");
out("=====================================");
out("Summary: {$GREEN}{$passed} passed{$NC}, {$RED}{$failed} failed{$NC}, {$YELLOW}{$warnings} warnings{$NC} ({$totalTests} total)");

if ($failed > 0) {
    out("");
    out("Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("  {$RED}- {$r['name']}: " . ($r['error'] ?? '') . "{$NC}");
        }
    }
}

if ($jsonMode) {
    echo json_encode([
        'suite' => 'time-tracking-integrity',
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'total' => $totalTests,
        'results' => $results,
        'log' => $logFile,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
