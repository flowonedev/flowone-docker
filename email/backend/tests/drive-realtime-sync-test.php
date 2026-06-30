#!/usr/bin/env php
<?php
/**
 * FlowOne Drive - Real-time Cross-Device Sync Test Suite
 *
 * Verifies the WebSocket push path that keeps a user's OTHER open devices/tabs
 * in sync when a file/folder is created/updated/deleted. The fix wires Drive
 * mutations into the existing Redis -> Node mailsync -> WebSocket pipeline:
 *
 *   DriveController::createSyncEvent()
 *     -> INSERT webmail_drive_sync_events   (unchanged, activity log)
 *     -> RedisCacheService::publishEvent()  (NEW: DRIVE_* on {prefix}mailbox:{email})
 *        -> Node mailsync relays to every connected client of that user
 *
 * This suite exercises both halves end-to-end against a live Redis:
 *   - RedisCacheService::publishEvent delivers a DRIVE_* message on the user's
 *     channel with the shape the Node server expects ({type, payload, ...}).
 *   - DriveController::createSyncEvent (the real chokepoint used by upload,
 *     uploadVersioned, uploadChunk, createFolder, deletes) actually publishes,
 *     and maps internal event types -> DRIVE_* correctly.
 *
 * A forked subscriber captures published messages; all DB rows use a synthetic
 * [FLOWONE-TEST] channel email so a real user's channel is never disturbed, and
 * every row is cleaned up afterwards.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-realtime-sync-test.php \
 *       --email=admin@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required, must exist on this server)
 *   --only=group,group   Run only specific groups (publish,wiring,cleanup)
 *   --smoke              Quick health check (Redis + DB connectivity, no business logic)
 *   --skip-send          Skip the live publish/subscribe roundtrips (connectivity only)
 *   --json               Output the summary as JSON
 *   --verbose            Show extra debug info (raw messages, stack traces)
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'only:', 'smoke', 'skip-send', 'json', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Drive Real-time Cross-Device Sync Test Suite\n";
    echo "====================================================\n\n";
    echo "Usage:\n";
    echo "  php drive-realtime-sync-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (must exist on this server)\n";
    echo "  --only=group,group   Run only specific groups (publish,wiring,cleanup)\n";
    echo "  --smoke              Quick health check (Redis + DB connectivity)\n";
    echo "  --skip-send          Skip live publish/subscribe roundtrips\n";
    echo "  --json               Output summary as JSON\n";
    echo "  --verbose            Extra debug output\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-realtime-sync-test.php \\\n";
    echo "      --email=admin@flowone.pro --verbose\n";
    exit(1);
}

$testEmail  = strtolower($opts['email']);
$smokeOnly  = isset($opts['smoke']);
$skipSend   = isset($opts['skip-send']);
$verbose    = isset($opts['verbose']);
$jsonOutput = isset($opts['json']);
$onlyGroups = !empty($opts['only']) ? explode(',', $opts['only']) : [];

$PER_TEST_TIMEOUT = 30;

// Synthetic channel email so we never publish to / pollute a real user's
// channel or sync-event log. The Redis channel name is an arbitrary string;
// publisher and subscriber only need to agree on it.
$RT_EMAIL = 'flowone_test_rt_' . substr(md5((string)microtime(true)), 0, 10) . '@example.invalid';

// Redis connection parameters + channel prefix (mirrors RedisCacheService).
$redisConf = $config['redis'] ?? [];
$redisPrefix = $redisConf['prefix'] ?? 'webmail:';
$rtChannel = $redisPrefix . 'mailbox:' . $RT_EMAIL;

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/drive-realtime-sync-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

$RED    = "\033[0;31m";
$GREEN  = "\033[0;32m";
$YELLOW = "\033[1;33m";
$NC     = "\033[0m";

function out(string $msg): void {
    global $logFile, $jsonOutput;
    if ($jsonOutput) return;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents(
        $logFile,
        date('[H:i:s] ') . preg_replace('/\033\[[0-9;]*m/', '', $line),
        FILE_APPEND | LOCK_EX
    );
}

function shouldRun(string $group): bool {
    global $onlyGroups;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results,
           $verbose, $GREEN, $RED, $YELLOW, $NC, $PER_TEST_TIMEOUT;
    $totalTests++;
    @set_time_limit($PER_TEST_TIMEOUT);
    $start = microtime(true);
    try {
        $result = $fn();
        $elapsed = round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  {$YELLOW}[WARN]{$NC}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  {$GREEN}[PASS]{$NC}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = round((microtime(true) - $start) * 1000);
        $failed++;
        out("  {$RED}[FAIL]{$NC}  {$name} ({$elapsed}ms)");
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

function assert_not_empty($value, string $msg = 'Value is empty'): void {
    if (empty($value)) throw new \RuntimeException($msg);
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $detail = $msg ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        throw new \RuntimeException($detail);
    }
}

/**
 * Hard-terminate a forked child process.
 *
 * A normal exit() would run PHP shutdown in the child: the inherited PDO's
 * destructor sends COM_QUIT, which closes the MySQL session the PARENT still
 * shares (the dreaded "MySQL server has gone away"), and the registered
 * doCleanup() shutdown handler would also run inside the child. SIGKILL skips
 * all of that - the OS just closes the child's dup'd FDs, leaving the parent's
 * connections fully intact.
 */
function rt_child_terminate(): void {
    if (function_exists('posix_kill') && function_exists('posix_getpid') && defined('SIGKILL')) {
        @posix_kill(posix_getpid(), SIGKILL);
    }
    exit(0);
}

/**
 * Open a fresh phpredis connection from config (used by both the forked
 * subscriber and the parent publisher; never shared across the fork).
 */
function rt_connect(array $conf): \Redis {
    $r = new \Redis();
    $host = $conf['host'] ?? '127.0.0.1';
    $port = $conf['port'] ?? 6379;
    $timeout = $conf['timeout'] ?? 2.0;
    if (!$r->connect($host, $port, $timeout)) {
        throw new \RuntimeException("Redis connect failed ({$host}:{$port})");
    }
    if (!empty($conf['password'])) {
        if (!$r->auth($conf['password'])) {
            throw new \RuntimeException('Redis auth failed');
        }
    }
    if (!empty($conf['database'])) {
        $r->select((int)$conf['database']);
    }
    return $r;
}

/**
 * Fork a subscriber on $channel, run $trigger in the parent to publish, and
 * return the first decoded message the subscriber captured (or null on timeout).
 * Bounded by $readTimeout so a missing message can never hang the suite.
 */
function rt_capture(string $channel, array $redisConf, callable $trigger, float $readTimeout = 4.0): ?array {
    if (!function_exists('pcntl_fork')) {
        throw new \RuntimeException('pcntl extension not available; cannot run subscriber');
    }

    $tmp = sys_get_temp_dir();
    $tag = bin2hex(random_bytes(6));
    $readyFile = $tmp . '/flowone_rt_ready_' . $tag;
    $outFile   = $tmp . '/flowone_rt_msg_'   . $tag;
    @unlink($readyFile);
    @unlink($outFile);

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new \RuntimeException('pcntl_fork failed');
    }

    if ($pid === 0) {
        // ---- CHILD: subscribe and capture the first message ----
        // Mark this process as a child so the shutdown cleanup never runs here.
        $GLOBALS['rt_is_child'] = true;
        try {
            $sub = rt_connect($redisConf);
            $sub->setOption(\Redis::OPT_READ_TIMEOUT, $readTimeout);
            @file_put_contents($readyFile, '1');
            $sub->subscribe([$channel], function ($redis, $chan, $msg) use ($outFile) {
                @file_put_contents($outFile, $msg);
                // First message captured; hard-exit the blocking subscribe loop
                // without touching inherited DB/Redis handles.
                rt_child_terminate();
            });
        } catch (\Throwable $e) {
            // Read timeout (no message) or connection error -> no output file.
        }
        rt_child_terminate();
    }

    // ---- PARENT: wait for child SUBSCRIBE, then publish ----
    $deadline = microtime(true) + 2.0;
    while (!file_exists($readyFile) && microtime(true) < $deadline) {
        usleep(20000); // 20ms
    }
    // Small extra delay so the SUBSCRIBE command is registered server-side
    // before we publish (pub/sub does not retain messages for late subscribers).
    usleep(300000); // 300ms

    try {
        $trigger();
    } catch (\Throwable $e) {
        // Reap child before bubbling up.
        $status = null;
        pcntl_waitpid($pid, $status);
        @unlink($readyFile);
        @unlink($outFile);
        throw $e;
    }

    $status = null;
    pcntl_waitpid($pid, $status); // child exits on capture or read timeout

    $raw = file_exists($outFile) ? file_get_contents($outFile) : null;
    @unlink($readyFile);
    @unlink($outFile);

    if ($raw === null || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

// Cleanup -- always runs, even on crash. Removes the synthetic channel's
// sync-event rows. Idempotent and scoped to the [FLOWONE-TEST] channel email.
function doCleanup(): void {
    global $config, $RT_EMAIL;
    // Never run cleanup inside a forked subscriber child.
    if (!empty($GLOBALS['rt_is_child'])) return;
    try {
        // getConnection() self-heals a dropped connection (it pings + reconnects),
        // so this works even if a fork disturbed the shared handle.
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare("DELETE FROM webmail_drive_sync_events WHERE user_email = ?")
           ->execute([strtolower($RT_EMAIL)]);
    } catch (\Throwable $e) {
        error_log('[Drive Realtime Test Cleanup] ' . $e->getMessage());
    }
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Drive Real-time Cross-Device Sync Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: {$testEmail}");
out("  Channel: {$rtChannel}");
out("  Mode:    " . ($smokeOnly ? 'SMOKE (connectivity)' : ($skipSend ? 'NO-SEND' : 'FULL')));
if (!empty($onlyGroups)) out("  Groups:  " . implode(', ', $onlyGroups));
out("  Log:     {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

test('PHP extensions loaded (redis, pdo, pcntl)', function () use ($skipSend) {
    $required = ['redis', 'pdo', 'pdo_mysql'];
    $missing = [];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) $missing[] = $ext;
    }
    assert_true(empty($missing), 'Missing extensions: ' . implode(', ', $missing));
    if (!extension_loaded('pcntl') && !$skipSend) {
        // pcntl is needed for the forked subscriber; without it the live
        // roundtrips degrade to connectivity-only (reported as WARN there).
        return 'warn';
    }
    return true;
});

$db = null;
test('Database connection', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db instanceof \PDO, 'Not a PDO instance');
});

test('Storage logs directory writable', function () {
    $dir = __DIR__ . '/../storage/logs';
    assert_true(is_dir($dir) && is_writable($dir), 'storage/logs not writable');
});

test('Redis available via RedisCacheService', function () use ($config) {
    $redis = new \Webmail\Services\RedisCacheService($config);
    assert_true($redis->isAvailable(), 'RedisCacheService reports Redis unavailable');
});

test('webmail_drive_sync_events table reachable', function () use (&$db) {
    // Table is created on demand by createSyncEvent(); tolerate absence here.
    $db->query("SHOW TABLES LIKE 'webmail_drive_sync_events'");
    return true;
});

if ($smokeOnly) {
    goto summary;
}

// ── 1. Publish path (RedisCacheService::publishEvent) ────────────

if (shouldRun('publish') && !$skipSend) {
    out("\n--- 1. PUBLISH PATH (RedisCacheService) ---");

    test('publishEvent delivers DRIVE_FILE_CREATED on user channel', function () use ($config, $rtChannel, $redisConf, $RT_EMAIL, $verbose) {
        if (!function_exists('pcntl_fork')) return 'warn';

        $payload = [
            'file_id'   => 999000001,
            'folder_id' => null,
            'file_name' => '[FLOWONE-TEST] realtime-publish.txt',
            'source'    => 'flowone_test',
        ];

        $msg = rt_capture($rtChannel, $redisConf, function () use ($config, $RT_EMAIL, $payload) {
            $redis = new \Webmail\Services\RedisCacheService($config);
            $redis->publishEvent(strtolower($RT_EMAIL), 'DRIVE_FILE_CREATED', $payload);
        });

        assert_not_empty($msg, 'No message received on channel within timeout');
        if ($verbose) out('          raw: ' . json_encode($msg));
        assert_equals('DRIVE_FILE_CREATED', $msg['type'] ?? null, 'Wrong event type');
        assert_true(isset($msg['payload']) && is_array($msg['payload']), 'Missing payload object');
        assert_equals('[FLOWONE-TEST] realtime-publish.txt', $msg['payload']['file_name'] ?? null, 'file_name not relayed');
        return true;
    });

    test('published message shape matches Node mailsync contract', function () use ($config, $rtChannel, $redisConf, $RT_EMAIL) {
        if (!function_exists('pcntl_fork')) return 'warn';

        $msg = rt_capture($rtChannel, $redisConf, function () use ($config, $RT_EMAIL) {
            $redis = new \Webmail\Services\RedisCacheService($config);
            $redis->publishEvent(strtolower($RT_EMAIL), 'DRIVE_FOLDER_CREATED', [
                'folder_id' => 999000002,
                'file_name' => '[FLOWONE-TEST] rt-folder',
                'source'    => 'flowone_test',
            ]);
        });

        assert_not_empty($msg, 'No message received');
        // Node getEntityTypeFromEvent() routes anything starting with DRIVE_ to
        // the 'drive' subscription, so the prefix is load-bearing.
        assert_true(str_starts_with((string)($msg['type'] ?? ''), 'DRIVE_'), 'type must start with DRIVE_');
        assert_true(array_key_exists('payload', $msg), 'message must carry a payload');
        assert_true(array_key_exists('timestamp', $msg), 'message must carry a timestamp');
        return true;
    });
} elseif (shouldRun('publish') && $skipSend) {
    out("\n--- 1. PUBLISH PATH (skipped: --skip-send) ---");
    test('Redis publisher connects (connectivity only)', function () use ($config) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        assert_true($redis->isAvailable(), 'Redis unavailable');
        return true;
    });
}

// ── 2. Controller wiring (DriveController::createSyncEvent) ───────

if (shouldRun('wiring') && !$skipSend) {
    out("\n--- 2. CONTROLLER WIRING (createSyncEvent -> publish) ---");

    test('createSyncEvent(file_created) publishes DRIVE_FILE_CREATED', function () use ($config, $rtChannel, $redisConf, $RT_EMAIL, $verbose) {
        if (!function_exists('pcntl_fork')) return 'warn';

        $msg = rt_capture($rtChannel, $redisConf, function () use ($config, $RT_EMAIL) {
            $controller = new \Webmail\Controllers\DriveController($config);
            $ref = new \ReflectionMethod($controller, 'createSyncEvent');
            $ref->setAccessible(true);
            $ok = $ref->invoke($controller, strtolower($RT_EMAIL), 'file_created', [
                'file_id'   => 999000010,
                'folder_id' => null,
                'file_name' => '[FLOWONE-TEST] wiring-file.txt',
                'new_version' => 1,
                'source'    => 'flowone_test',
            ]);
            if (!$ok) {
                throw new \RuntimeException('createSyncEvent returned false');
            }
        });

        assert_not_empty($msg, 'createSyncEvent did not publish to the user channel');
        if ($verbose) out('          raw: ' . json_encode($msg));
        assert_equals('DRIVE_FILE_CREATED', $msg['type'] ?? null, 'Internal type not mapped to DRIVE_FILE_CREATED');
        assert_equals('[FLOWONE-TEST] wiring-file.txt', $msg['payload']['file_name'] ?? null, 'file_name not in payload');
        return true;
    });

    test('createSyncEvent(folder_created) maps to DRIVE_FOLDER_CREATED', function () use ($config, $rtChannel, $redisConf, $RT_EMAIL) {
        if (!function_exists('pcntl_fork')) return 'warn';

        $msg = rt_capture($rtChannel, $redisConf, function () use ($config, $RT_EMAIL) {
            $controller = new \Webmail\Controllers\DriveController($config);
            $ref = new \ReflectionMethod($controller, 'createSyncEvent');
            $ref->setAccessible(true);
            $ref->invoke($controller, strtolower($RT_EMAIL), 'folder_created', [
                'folder_id' => 999000011,
                'file_name' => '[FLOWONE-TEST] wiring-folder',
                'source'    => 'flowone_test',
            ]);
        });

        assert_not_empty($msg, 'No folder event published');
        assert_equals('DRIVE_FOLDER_CREATED', $msg['type'] ?? null, 'folder_created not mapped to DRIVE_FOLDER_CREATED');
        return true;
    });

    test('createSyncEvent also writes the activity-log row', function () use ($config, $RT_EMAIL) {
        // Re-acquire via getConnection() (self-healing) rather than a handle
        // captured before the forks, which pcntl may have disturbed.
        $db = \Webmail\Core\Database::getConnection($config);
        // The two wiring tests above each inserted one row for this channel.
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM webmail_drive_sync_events WHERE user_email = ?");
        $stmt->execute([strtolower($RT_EMAIL)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        assert_true((int)$row['c'] >= 1, 'Expected at least one sync-event row for the test channel');
        return true;
    });
} elseif (shouldRun('wiring') && $skipSend) {
    out("\n--- 2. CONTROLLER WIRING (skipped: --skip-send) ---");
    test('DriveController instantiable in CLI', function () use ($config) {
        $controller = new \Webmail\Controllers\DriveController($config);
        assert_true($controller instanceof \Webmail\Controllers\DriveController);
        assert_true(method_exists($controller, 'getSyncEvents'), 'controller missing expected method');
        return true;
    });
}

// ── 3. Cleanup ───────────────────────────────────────────────────

summary:
out("\n--- CLEANUP ---");

test('Remove synthetic sync-event rows', function () use ($config, $RT_EMAIL) {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->prepare("DELETE FROM webmail_drive_sync_events WHERE user_email = ?");
    $stmt->execute([strtolower($RT_EMAIL)]);
    out("          Cleaned up " . $stmt->rowCount() . " row(s)");
    return true;
});

// ══════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════

if ($jsonOutput) {
    echo json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'results'  => $results,
        'log'      => $logFile,
    ], JSON_PRETTY_PRINT) . "\n";
    exit($failed > 0 ? 1 : 0);
}

out("\n=================================================================");
if ($failed === 0) {
    out("  {$GREEN}ALL PASSED{$NC}: {$passed} passed, {$warnings} warnings / {$totalTests} total");
} else {
    out("  {$RED}RESULT{$NC}: {$passed} passed, {$failed} FAILED, {$warnings} warnings / {$totalTests} total");
}
out("  Log: {$logFile}");

if ($failed > 0) {
    out("\n  {$RED}FAILED TESTS:{$NC}");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    x {$r['name']}");
            out("      " . ($r['error'] ?? ''));
        }
    }
}

if ($warnings > 0) {
    out("\n  {$YELLOW}WARNINGS:{$NC}");
    foreach ($results as $r) {
        if ($r['status'] === 'WARN') out("    ~ {$r['name']}");
    }
}

out("=================================================================");
exit($failed > 0 ? 1 : 0);
