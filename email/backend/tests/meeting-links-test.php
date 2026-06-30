#!/usr/bin/env php
<?php
/**
 * Unified public meeting links — server-side checks (config, TTL math, sanitizers, DB schema hints).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/meeting-links-test.php --verbose
 *
 * Options:
 *   --help              Usage
 *   --verbose           Stack traces / extra output
 *   --skip-send         Skip DB writes / external LiveKit calls (default safe path)
 *   --smoke             Quick extension + config only
 *   --json              JSON summary on stdout
 *   --only=group1,...   config,utils,guest,db,rate,api,wiring,chaos
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$longopts = ['help', 'verbose', 'skip-send', 'smoke', 'json', 'only:', 'email::', 'password::'];
$opts = getopt('', $longopts) ?: [];

if (isset($opts['help'])) {
    echo "meeting-links-test.php [--verbose] [--skip-send] [--smoke] [--json] [--only=config,utils,guest,db,rate,api,wiring,chaos]\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$skipSend = isset($opts['skip-send']);
$smoke = isset($opts['smoke']);
$jsonOut = isset($opts['json']);
$only = null;
if (!empty($opts['only'])) {
    $only = array_map('trim', explode(',', (string)$opts['only']));
}

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/meeting-links-test-' . gmdate('Ymd-His') . '.log';

$passed = 0;
$failed = 0;
$warnings = 0;
$failMsgs = [];

function want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

function log_line(string $f, string $m): void
{
    $line = '[' . gmdate('H:i:s') . '] ' . $m . "\n";
    @file_put_contents($f, $line, FILE_APPEND);
    echo $m . "\n";
}

function run_test(string $logFile, string $name, callable $fn, bool $verbose): string
{
    global $passed, $failed, $warnings, $failMsgs;
    $t0 = microtime(true);
    try {
        $r = $fn();
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($r === 'warn') {
            $warnings++;
            log_line($logFile, "[WARN] {$name} ({$ms}ms)");
            return 'WARN';
        }
        $passed++;
        log_line($logFile, "[PASS] {$name} ({$ms}ms)");
        return 'PASS';
    } catch (\Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $failed++;
        $msg = $e->getMessage();
        $failMsgs[] = "{$name}: {$msg}";
        log_line($logFile, "[FAIL] {$name} ({$ms}ms) {$msg}");
        if ($verbose) {
            log_line($logFile, $e->getTraceAsString());
        }
        return 'FAIL';
    }
}

// --- Pre-flight ---
foreach (['pdo', 'json', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Pre-flight FAIL: missing extension {$ext}\n");
        exit(1);
    }
}

if ($smoke) {
    log_line($logFile, '[SMOKE] extensions OK, app.env=' . ($config['app']['env'] ?? 'unset'));
    exit(0);
}

if (want($only, 'config')) {
    run_test($logFile, 'config app.env present', function () use ($config) {
        if (empty($config['app']['env'])) {
            return 'warn';
        }
        return true;
    }, $verbose);
}

if (want($only, 'utils')) {
    run_test($logFile, 'NameSanitizer strips tags', function () {
        $s = \Webmail\Utils\NameSanitizer::sanitize('<b>Ann</b>');
        if (str_contains($s, '<')) {
            throw new \RuntimeException('HTML not stripped');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'TokenRedactor redacts guest path', function () {
        $t = \Webmail\Utils\TokenRedactor::redactUrl('https://x/guest/call/deadbeef0123456789abcdef0123456789abcdef0123456789abcdef/join');
        if (str_contains($t, 'deadbeef')) {
            throw new \RuntimeException('token not redacted');
        }
        return true;
    }, $verbose);
}

if (want($only, 'guest')) {
    if (!extension_loaded('pdo_mysql')) {
        $warnings++;
        log_line($logFile, '[WARN] pdo_mysql not loaded — skipping GuestCallService TTL test');
    } else {
        run_test($logFile, 'GuestCallService TTL math', function () use ($config) {
            $g = new \Webmail\Services\GuestCallService($config);
            $ttl = $g->computeCalendarMeetingTtlSeconds(gmdate('Y-m-d H:i:s', time() + 7200));
            if ($ttl < 3600) {
                throw new \RuntimeException('TTL unexpectedly small: ' . $ttl);
            }
            $exp = $g->calendarGuestExpiryUtcMysql(gmdate('Y-m-d H:i:s', time() + 7200));
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $exp)) {
                throw new \RuntimeException('Bad expiry: ' . $exp);
            }
            return true;
        }, $verbose);
    }
}

if (want($only, 'db')) {
    if (!extension_loaded('pdo_mysql')) {
        $warnings++;
        log_line($logFile, '[WARN] pdo_mysql not loaded — skipping DB connectivity test');
    } else {
        run_test($logFile, 'DB connectivity', function () use ($config) {
            $db = \Webmail\Core\Database::getConnection($config);
            $db->query('SELECT 1');
            return true;
        }, $verbose);
    }
}

if (want($only, 'rate')) {
    run_test($logFile, 'RateLimiter shape', function () use ($config) {
        $r = new \Webmail\Services\RateLimiter($config);
        $a = $r->allow('flowone-test-rl-' . bin2hex(random_bytes(4)), 5, 60);
        if (!isset($a['allowed'], $a['retry_after'], $a['current'])) {
            throw new \RuntimeException('unexpected allow() shape');
        }
        return true;
    }, $verbose);
}

if (want($only, 'api')) {
    run_test($logFile, 'GuestCallController has new public methods', function () {
        $required = [
            'getInfo','join','getAdmissionStatus','approveAdmission','denyAdmission',
            'listAdmissionLobby','kickParticipant','listAttendees','saveTranscript',
            'revokeRoom',
        ];
        $ref = new \ReflectionClass(\Webmail\Controllers\GuestCallController::class);
        foreach ($required as $m) {
            if (!$ref->hasMethod($m) || !$ref->getMethod($m)->isPublic()) {
                throw new \RuntimeException('Missing/non-public method: ' . $m);
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'GuestCallService has admission/kick/lifecycle methods', function () {
        $required = [
            'validateAndJoin','getTokenInfo','revokeByToken','revokeRoomEntirely',
            'revokeRoomByAdminToken',
            'revokeTokensForCalendarEvent','extendTokensTtlForCalendarEvent',
            'approveAdmission','denyAdmission','listAdmissionLobby',
            'kickParticipantByIdentity','listRoomAttendees',
            'ensureCalendarMeetingAndGetUrls',
        ];
        $ref = new \ReflectionClass(\Webmail\Services\GuestCallService::class);
        foreach ($required as $m) {
            if (!$ref->hasMethod($m) || !$ref->getMethod($m)->isPublic()) {
                throw new \RuntimeException('Missing/non-public method: ' . $m);
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'routes.php registers /guest/call/{token}/kick + admission + attendees + revoke-room', function () {
        $routes = (string)file_get_contents(__DIR__ . '/../routes.php');
        $needles = [
            "/guest/call/{token}/kick",
            "/guest/call/{token}/revoke-room",
            "/guest/call/{token}/attendees",
            "/guest/call/lobby",
            "/guest/call/admission/{id}/approve",
            "/guest/call/admission/{id}/deny",
            "/guest/call/{token}/admission/{id}",
            "/guest/call/{token}/info",
        ];
        foreach ($needles as $n) {
            if (!str_contains($routes, $n)) {
                throw new \RuntimeException('route missing: ' . $n);
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'CalendarController exposes addMeetingToEvent (upgrade-existing-event endpoint)', function () {
        $ref = new \ReflectionClass(\Webmail\Addons\Calendar\Controllers\CalendarController::class);
        if (!$ref->hasMethod('addMeetingToEvent')) {
            throw new \RuntimeException('CalendarController::addMeetingToEvent missing');
        }
        $m = $ref->getMethod('addMeetingToEvent');
        if (!$m->isPublic()) {
            throw new \RuntimeException('CalendarController::addMeetingToEvent must be public');
        }
        $params = $m->getParameters();
        if (count($params) !== 1) {
            throw new \RuntimeException('addMeetingToEvent should take exactly 1 parameter (Request)');
        }
        $type = $params[0]->getType();
        if (!$type || (string)$type !== 'Webmail\\Core\\Request') {
            throw new \RuntimeException('addMeetingToEvent parameter must be Webmail\\Core\\Request');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'addMeetingToEvent body reuses ensureCalendarMeetingAndGetUrls (idempotent mint)', function () {
        $ref = new \ReflectionClass(\Webmail\Addons\Calendar\Controllers\CalendarController::class);
        $src = (string)file_get_contents($ref->getFileName());
        $start = strpos($src, 'function addMeetingToEvent');
        if ($start === false) {
            throw new \RuntimeException('addMeetingToEvent not found in source');
        }
        // Bound the search at the next "    public function" declaration so we
        // don't accidentally match strings inside neighbouring methods.
        $end = strpos($src, "\n    public function ", $start + 30);
        $body = substr($src, $start, ($end ?: strlen($src)) - $start);
        $needles = [
            'getEventWithAccess',           // permission gate
            'ensureCalendarMeetingAndGetUrls', // idempotent token mint
            "'is_meeting' => true",         // event is flagged as meeting
            'broadcastCalendarUpdate',      // realtime sync to other tabs
        ];
        foreach ($needles as $n) {
            if (!str_contains($body, $n)) {
                throw new \RuntimeException('addMeetingToEvent missing required piece: ' . $n);
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'routes.php registers POST /events/{id}/add-meeting', function () {
        $routes = (string)file_get_contents(__DIR__ . '/../routes.php');
        if (!preg_match("#post\(['\"]/events/\{id\}/add-meeting['\"]\s*,\s*fn\(Request[^)]+\)\s*=>\s*\\\$calendar->addMeetingToEvent#", $routes)) {
            throw new \RuntimeException('POST /events/{id}/add-meeting not registered or bound to wrong handler');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'routes.php registers GET /events/{id}/meeting → getEventMeetingLinks', function () {
        $routes = (string)file_get_contents(__DIR__ . '/../routes.php');
        if (!preg_match("#get\(['\"]/events/\{id\}/meeting['\"]\s*,\s*fn\(Request[^)]+\)\s*=>\s*\\\$calendar->getEventMeetingLinks#", $routes)) {
            throw new \RuntimeException('GET /events/{id}/meeting not registered or bound to wrong handler');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'CalendarController exposes getEventMeetingLinks (read-only host-link fetch)', function () {
        $ref = new \ReflectionClass(\Webmail\Addons\Calendar\Controllers\CalendarController::class);
        if (!$ref->hasMethod('getEventMeetingLinks') || !$ref->getMethod('getEventMeetingLinks')->isPublic()) {
            throw new \RuntimeException('CalendarController::getEventMeetingLinks missing or non-public');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'addMeetingToEvent supports force-recreate (revoke + re-mint + re-apply settings)', function () {
        $ref = new \ReflectionClass(\Webmail\Addons\Calendar\Controllers\CalendarController::class);
        $src = (string)file_get_contents($ref->getFileName());
        $start = strpos($src, 'function addMeetingToEvent');
        $end = strpos($src, "\n    public function ", $start + 30);
        $body = substr($src, $start, ($end ?: strlen($src)) - $start);
        foreach (["input('force'", 'revokeTokensForCalendarEvent', 'setSettings('] as $needle) {
            if (!str_contains($body, $needle)) {
                throw new \RuntimeException('addMeetingToEvent force-recreate path missing: ' . $needle);
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'MeetingRoomService::setSettings upserts room flags (not INSERT IGNORE)', function () {
        $ref = new \ReflectionClass(\Webmail\Services\MeetingRoomService::class);
        if (!$ref->hasMethod('setSettings') || !$ref->getMethod('setSettings')->isPublic()) {
            throw new \RuntimeException('MeetingRoomService::setSettings missing or non-public');
        }
        $src = (string)file_get_contents($ref->getFileName());
        $start = strpos($src, 'function setSettings');
        $end = strpos($src, "\n    public function ", $start + 30);
        $body = substr($src, $start, ($end ?: strlen($src)) - $start);
        if (!str_contains($body, 'ON DUPLICATE KEY UPDATE')) {
            throw new \RuntimeException('setSettings must upsert (ON DUPLICATE KEY UPDATE) so flags can change');
        }
        return true;
    }, $verbose);

    run_test($logFile, 'revokeRoomByAdminToken rejects guest tokens (auth check)', function () {
        $ref = new \ReflectionClass(\Webmail\Services\GuestCallService::class);
        $src = (string)file_get_contents($ref->getFileName());
        // Verify the method body enforces role==='admin'. Without this check, any
        // guest could revoke the entire room for everyone else.
        $start = strpos($src, 'function revokeRoomByAdminToken');
        if ($start === false) {
            throw new \RuntimeException('revokeRoomByAdminToken not found');
        }
        $end = strpos($src, "\n    }", $start);
        $body = substr($src, $start, ($end ?: $start + 2000) - $start);
        if (!preg_match("/role['\"\\s].*?['\"]admin['\"]/", $body)) {
            throw new \RuntimeException('revokeRoomByAdminToken missing admin role check');
        }
        if (!str_contains($body, "'unauthorized'")) {
            throw new \RuntimeException('revokeRoomByAdminToken should return unauthorized flag for non-admin tokens');
        }
        return true;
    }, $verbose);
}

if (want($only, 'wiring')) {
    run_test($logFile, 'MeetingSettingsToggles.vue shared component exists', function () {
        $frontendRoot = realpath(__DIR__ . '/../../frontend/src');
        if ($frontendRoot === false) {
            return 'warn'; // running on a server-only deploy without the frontend tree co-located
        }
        $componentPath = $frontendRoot . '/components/call/MeetingSettingsToggles.vue';
        if (!is_file($componentPath)) {
            throw new \RuntimeException('Missing component: ' . $componentPath);
        }
        $contents = (string)file_get_contents($componentPath);
        foreach (['update:waitingRoom', 'update:participantsHidden'] as $needle) {
            if (!str_contains($contents, $needle)) {
                throw new \RuntimeException('Component missing emit: ' . $needle);
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'MeetingSettingsToggles wired into Calendar/Chat/CRM call sites', function () {
        $frontendRoot = realpath(__DIR__ . '/../../frontend/src');
        if ($frontendRoot === false) {
            return 'warn';
        }
        $callSites = [
            $frontendRoot . '/addons/calendar/views/CalendarView.vue',
            $frontendRoot . '/addons/chat/components/NewConversationModal.vue',
            $frontendRoot . '/addons/crm-pro/components/CrmPortalCallButton.vue',
        ];
        foreach ($callSites as $path) {
            if (!is_file($path)) {
                throw new \RuntimeException('Missing call site: ' . $path);
            }
            $contents = (string)file_get_contents($path);
            if (!str_contains($contents, 'MeetingSettingsToggles')) {
                throw new \RuntimeException('Call site does not import/use MeetingSettingsToggles: ' . basename($path));
            }
        }
        return true;
    }, $verbose);
}

if (want($only, 'chaos')) {
    run_test($logFile, 'Phase C2 chaos suite scaffolding present', function () {
        $root = __DIR__ . '/livekit-chaos';
        $required = [
            $root . '/package.json',
            $root . '/playwright.config.js',
            $root . '/run.js',
            $root . '/README.md',
            $root . '/.env.example',
            $root . '/lib/api.js',
            $root . '/lib/fixtures.js',
            $root . '/lib/livekit.js',
        ];
        foreach ($required as $p) {
            if (!is_file($p)) {
                throw new \RuntimeException('missing chaos suite file: ' . $p);
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'Phase C2 scenarios cover all 10 plan items', function () {
        $expected = [
            'reconnect_wifi_switch',
            'reconnect_long_pause',
            'mobile_background',
            'dup_tab_warn',
            'kick_disconnects',
            'revoke_disconnects_all',
            'waiting_room_flow',
            'waiting_room_data_channel',
            'workshop_mode_visibility',
            'workshop_mode_publish_blocked',
        ];
        $dir = __DIR__ . '/livekit-chaos/scenarios';
        if (!is_dir($dir)) {
            throw new \RuntimeException('scenarios directory missing: ' . $dir);
        }
        foreach ($expected as $name) {
            $path = $dir . '/' . $name . '.spec.js';
            if (!is_file($path)) {
                throw new \RuntimeException('missing scenario: ' . $name);
            }
            $contents = (string)file_get_contents($path);
            if (stripos($contents, 'test(') === false || stripos($contents, "fixtures") === false) {
                throw new \RuntimeException('scenario lacks fixture-driven test() call: ' . $name);
            }
        }
        return true;
    }, $verbose);

    run_test($logFile, 'PHP wrapper gates on RUN_MEETING_LIVEKIT_CHAOS', function () {
        $wrapper = __DIR__ . '/meeting-livekit-chaos-test.php';
        if (!is_file($wrapper)) {
            throw new \RuntimeException('missing wrapper: ' . $wrapper);
        }
        $contents = (string)file_get_contents($wrapper);
        foreach (['RUN_MEETING_LIVEKIT_CHAOS', 'livekit-chaos/run.js', '--skip-send', '--smoke', '--json', '--only', '--base-url'] as $needle) {
            if (!str_contains($contents, $needle)) {
                throw new \RuntimeException('wrapper missing: ' . $needle);
            }
        }
        return true;
    }, $verbose);
}

if ($jsonOut) {
    echo json_encode([
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'failures' => $failMsgs,
        'log' => $logFile,
    ], JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "Summary: PASS={$passed} FAIL={$failed} WARN={$warnings}\n";
    if ($failMsgs) {
        echo implode("\n", $failMsgs) . "\n";
    }
    echo "Log: {$logFile}\n";
}

exit($failed > 0 ? 1 : 0);
