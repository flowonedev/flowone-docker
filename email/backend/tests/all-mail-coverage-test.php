#!/usr/bin/env php
<?php
/**
 * FlowOne All Mail Coverage Test
 *
 * Walks every eligible IMAP folder for the supplied account and verifies
 * that the new tiered fetch ladder (full-range -> binary split -> 50-msg
 * chunks -> per-UID FT_UID) returns at least one parseable UID for every
 * folder that imap_num_msg reports as non-empty. This is the safety net
 * for the WhiteRabbit bug.
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/all-mail-coverage-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 *
 * Flags:
 *   --email=EMAIL          required
 *   --password=PASS        required (omitted when --skip-imap)
 *   --verbose              extra debug output (stack traces, full meta)
 *   --smoke                health check only (DB + Redis + IMAP connect),
 *                          skip per-folder fetch
 *   --json                 emit results as JSON to stdout (for monitoring)
 *   --only=group1,group2   run only these test categories (comma-separated)
 *   --skip-imap            preflight + invariant checks only, no IMAP fetch
 *   --per-folder-timeout=N seconds per folder before WARN (default 30)
 *   --help                 show this message
 *
 * Exit code: 0 on all PASS, 1 on any FAIL.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', [
    'email:',
    'password:',
    'verbose',
    'smoke',
    'json',
    'only:',
    'skip-imap',
    'per-folder-timeout:',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1700));
    exit(0);
}

$jsonOut = isset($opts['json']);
$verbose = isset($opts['verbose']);
$smoke = isset($opts['smoke']);
$skipImap = isset($opts['skip-imap']);
$perFolderTimeout = (int) ($opts['per-folder-timeout'] ?? 30);
$only = isset($opts['only'])
    ? array_map('trim', explode(',', (string) $opts['only']))
    : [];
$testEmail = $opts['email'] ?? null;
$testPassword = $opts['password'] ?? null;

if (!$testEmail || (!$skipImap && !$smoke && !$testPassword)) {
    fwrite(STDERR, "Missing --email or --password. Use --help for usage.\n");
    exit(2);
}

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/all-mail-coverage-' . date('Ymd-His') . '.log';

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

function out(string $msg): void
{
    global $logFile, $jsonOut;
    if (!$jsonOut) {
        echo $msg . "\n";
    }
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function should_run(string $group): bool
{
    global $only;
    return empty($only) || in_array($group, $only, true);
}

function record(string $name, string $status, int $ms, ?string $error = null, array $meta = []): void
{
    global $totalTests, $passed, $failed, $warnings, $results;
    $totalTests++;
    if ($status === 'PASS') {
        $passed++;
    } elseif ($status === 'WARN') {
        $warnings++;
    } else {
        $failed++;
    }
    $results[] = [
        'name' => $name,
        'status' => $status,
        'ms' => $ms,
        'error' => $error,
        'meta' => $meta,
    ];

    $line = sprintf('  [%-4s]  %s (%dms)', $status, $name, $ms);
    out($line);
    if ($error !== null) {
        out('          -> ' . $error);
    }
}

function test_with_timeout(string $name, callable $fn, int $timeoutSec): void
{
    $start = microtime(true);
    if (function_exists('pcntl_alarm')) {
        pcntl_signal(SIGALRM, function () {
            throw new \RuntimeException('test exceeded timeout');
        });
        pcntl_alarm($timeoutSec);
    }
    try {
        $result = $fn();
        $ms = (int) round((microtime(true) - $start) * 1000);
        if (is_array($result) && ($result['status'] ?? null) === 'WARN') {
            record($name, 'WARN', $ms, $result['msg'] ?? null, $result['meta'] ?? []);
        } else {
            $meta = is_array($result) ? ($result['meta'] ?? []) : [];
            record($name, 'PASS', $ms, null, $meta);
        }
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $start) * 1000);
        record($name, 'FAIL', $ms, $e->getMessage());
        global $verbose;
        if ($verbose) {
            out('          at ' . $e->getFile() . ':' . $e->getLine());
        }
    } finally {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }
}

// =================================================================
// Header
// =================================================================
out('=================================================================');
out('  FlowOne All Mail Coverage Test');
out('  ' . date('Y-m-d H:i:s T'));
out('  Account:    ' . $testEmail);
out('  Mode:       ' . ($smoke ? 'SMOKE' : ($skipImap ? 'NO-IMAP' : 'FULL')));
out('  Timeout:    ' . $perFolderTimeout . 's per folder');
out('  Categories: ' . (empty($only) ? 'all' : implode(',', $only)));
out('  Log:        ' . $logFile);
out('=================================================================');

// =================================================================
// 1. PREFLIGHT
// =================================================================
if (should_run('preflight')) {
    out("\n--- 1. PREFLIGHT ---");

    test_with_timeout('PHP imap extension loaded', function () {
        if (!extension_loaded('imap')) {
            throw new \RuntimeException('imap extension not loaded');
        }
        return ['meta' => ['version' => phpversion('imap')]];
    }, 5);

    test_with_timeout('PHP redis extension loaded', function () {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('redis extension not loaded');
        }
        return ['meta' => ['version' => phpversion('redis')]];
    }, 5);

    test_with_timeout('PHP openssl + mbstring loaded', function () {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('openssl missing');
        }
        if (!extension_loaded('mbstring')) {
            throw new \RuntimeException('mbstring missing');
        }
        return null;
    }, 5);

    test_with_timeout('Database connection', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $row = $db->query('SELECT 1 AS ok')->fetch();
        if (!$row || (int) $row['ok'] !== 1) {
            throw new \RuntimeException('SELECT 1 failed');
        }
        return null;
    }, 10);

    test_with_timeout('Redis ping', function () use ($config) {
        $redis = new \Redis();
        $host = $config['redis']['host'] ?? '127.0.0.1';
        $port = $config['redis']['port'] ?? 6379;
        $ok = @$redis->connect($host, $port, 2.0);
        if (!$ok) {
            throw new \RuntimeException("Cannot connect to Redis at {$host}:{$port}");
        }
        $pass = $config['redis']['password'] ?? null;
        if ($pass) {
            $redis->auth($pass);
        }
        $pong = $redis->ping();
        if ($pong !== true && $pong !== '+PONG') {
            throw new \RuntimeException('Redis PING failed');
        }
        $redis->close();
        return null;
    }, 10);

    test_with_timeout('storage/logs writable', function () use ($logDir) {
        if (!is_writable($logDir)) {
            throw new \RuntimeException("not writable: {$logDir}");
        }
        $disk = @disk_free_space($logDir);
        if ($disk !== false && $disk < 50 * 1024 * 1024) {
            return ['status' => 'WARN', 'msg' => 'less than 50MB free in storage/logs'];
        }
        return null;
    }, 5);
}

// =================================================================
// 2. CIRCUIT BREAKER + STATE MACHINE wiring
// =================================================================
if (should_run('breaker')) {
    out("\n--- 2. CIRCUIT BREAKER + STATE MACHINE ---");

    test_with_timeout('CircuitBreaker classes load', function () {
        if (!class_exists(\Webmail\Services\CircuitBreaker::class)) {
            throw new \RuntimeException('CircuitBreaker missing');
        }
        if (!class_exists(\Webmail\Services\FolderStateMachine::class)) {
            throw new \RuntimeException('FolderStateMachine missing');
        }
        if (!class_exists(\Webmail\Services\CorrelationId::class)) {
            throw new \RuntimeException('CorrelationId missing');
        }
        if (!class_exists(\Webmail\Services\StructuredLog::class)) {
            throw new \RuntimeException('StructuredLog missing');
        }
        return null;
    }, 5);

    test_with_timeout('CorrelationId generates ULID', function () {
        $a = \Webmail\Services\CorrelationId::generate();
        $b = \Webmail\Services\CorrelationId::generate();
        if (!str_starts_with($a, 'req_') || strlen($a) !== 30) {
            throw new \RuntimeException('Bad format: ' . $a);
        }
        if ($a === $b) {
            throw new \RuntimeException('Two generations collided');
        }
        return ['meta' => ['sample' => $a]];
    }, 5);

    test_with_timeout('StructuredLog::line includes request_id', function () {
        \Webmail\Services\CorrelationId::reset();
        $line = \Webmail\Services\StructuredLog::line('test_event', ['folder_path' => 'X']);
        if (!str_contains($line, '"request_id"')) {
            throw new \RuntimeException('request_id missing from log line');
        }
        if (!str_starts_with($line, '[ALLMAIL] ')) {
            throw new \RuntimeException('channel prefix missing');
        }
        return null;
    }, 5);

    test_with_timeout('CircuitBreaker trips after 5 failures', function () use ($config) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        if (!$redis->isAvailable()) {
            return ['status' => 'WARN', 'msg' => 'Redis unavailable; breaker test skipped'];
        }
        $breaker = new \Webmail\Services\CircuitBreaker($redis);
        $key = 'flowone_test_account_' . bin2hex(random_bytes(3));
        $folder = 'flowone_test_folder';
        try {
            for ($i = 1; $i <= 5; $i++) {
                $res = $breaker->recordFailure($key, $folder);
                if ($i < 5 && $res['state'] !== \Webmail\Services\CircuitBreaker::STATE_CLOSED) {
                    throw new \RuntimeException("breaker tripped at i={$i}");
                }
            }
            $final = $breaker->inspect($key, $folder);
            if ($final['state'] !== \Webmail\Services\CircuitBreaker::STATE_OPEN) {
                throw new \RuntimeException('breaker did not trip after 5 failures');
            }
            // Jitter sanity: cooldown should be within +/-10% of 900s.
            $cd = $final['cooldown_seconds'];
            if ($cd < 810 || $cd > 990) {
                throw new \RuntimeException("cooldown out of range: {$cd}");
            }
            return ['meta' => ['cooldown_seconds' => $cd]];
        } finally {
            $breaker->recordSuccess($key, $folder);
        }
    }, 10);

    test_with_timeout('FolderStateMachine accepts only valid transitions', function () use ($config) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        $sm = new \Webmail\Services\FolderStateMachine($redis);
        $key = 'flowone_test_account_' . bin2hex(random_bytes(3));
        $folder = 'flowone_test_folder';
        try {
            $sm->forceSet($key, $folder, \Webmail\Services\FolderStateMachine::HEALTHY);
            $ok = $sm->transition($key, $folder, \Webmail\Services\FolderStateMachine::DEGRADED);
            if (!$ok) {
                throw new \RuntimeException('healthy->degraded should be allowed');
            }
            // ignored -> degraded is illegal
            $sm->forceSet($key, $folder, \Webmail\Services\FolderStateMachine::IGNORED);
            $bad = $sm->transition($key, $folder, \Webmail\Services\FolderStateMachine::DEGRADED);
            if ($bad) {
                throw new \RuntimeException('ignored->degraded should be rejected');
            }
            return null;
        } finally {
            $sm->clear($key, $folder);
        }
    }, 10);
}

// =================================================================
// 3. IMAP CONNECTION + LIST
// =================================================================
$imap = null;
$folders = [];

if (!$skipImap && should_run('imap-connect')) {
    out("\n--- 3. IMAP CONNECTION ---");

    test_with_timeout('IMAP connect', function () use ($config, $testEmail, $testPassword, &$imap) {
        $imap = new \Webmail\Services\ImapService($config['imap'] ?? []);
        if (!$imap->connect($testEmail, $testPassword)) {
            throw new \RuntimeException(
                'IMAP connect failed: ' . ($imap->getLastError() ?? 'unknown')
            );
        }
        return null;
    }, 30);

    test_with_timeout('IMAP listFolders returns at least INBOX', function () use (&$imap, &$folders) {
        if (!$imap) {
            throw new \RuntimeException('No IMAP connection');
        }
        $folders = $imap->listFolders();
        if (!is_array($folders) || empty($folders)) {
            throw new \RuntimeException('listFolders empty');
        }
        $names = array_map(fn($f) => $f['name'] ?? '', $folders);
        if (!in_array('INBOX', $names, true)) {
            throw new \RuntimeException('INBOX missing from folder list');
        }
        return ['meta' => ['count' => count($folders)]];
    }, 30);
}

// =================================================================
// 4. PER-FOLDER COVERAGE (the safety net for WhiteRabbit)
// =================================================================
if (!$skipImap && !$smoke && should_run('coverage') && $imap) {
    out("\n--- 4. PER-FOLDER COVERAGE ---");

    $eligibleFolders = array_filter(
        $folders,
        fn($f) => !in_array($f['type'] ?? '', ['drafts', 'trash', 'spam', 'sent'], true)
    );

    foreach ($eligibleFolders as $f) {
        $folderName = $f['name'] ?? '';
        $expected = (int) ($f['total'] ?? 0);
        if ($folderName === '') {
            continue;
        }

        $name = "Coverage: {$folderName}";
        test_with_timeout($name, function () use (&$imap, $folderName, $expected) {
            $entries = $imap->getUidsWithTimestamps($folderName);
            $meta = $imap->getLastScanMeta();
            $retrieved = count($entries);

            if ($expected === 0 && $retrieved === 0) {
                return ['meta' => ['retrieved' => 0, 'total' => 0]];
            }

            // FAIL: folder claims to have messages but we got none.
            if ($expected > 0 && $retrieved === 0) {
                throw new \RuntimeException(
                    "expected={$expected}, retrieved=0, fallback_stage="
                    . ($meta['fallback_stage'] ?? '?')
                    . ', reason=' . ($meta['failure_reason'] ?? 'unknown')
                );
            }

            // WARN: partial coverage (degraded but not zero).
            if ($expected > 0 && $retrieved < $expected) {
                return [
                    'status' => 'WARN',
                    'msg' => "retrieved={$retrieved}/{$expected}, fallback_stage="
                        . ($meta['fallback_stage'] ?? '?'),
                    'meta' => $meta,
                ];
            }

            return ['meta' => ['retrieved' => $retrieved, 'total' => $expected, 'stage' => $meta['fallback_stage'] ?? null]];
        }, $perFolderTimeout);
    }

    // Invariant assertion: count(folders_listed) == count(folders represented
    // in coverage results). We tracked one record() per eligible folder.
    test_with_timeout('Invariant: every listed eligible folder has a coverage record', function () use ($eligibleFolders, $results) {
        $coverageResults = array_filter(
            $results,
            fn($r) => str_starts_with($r['name'], 'Coverage: ')
        );
        if (count($coverageResults) !== count($eligibleFolders)) {
            throw new \RuntimeException(
                'expected ' . count($eligibleFolders)
                . ' coverage rows, got ' . count($coverageResults)
            );
        }
        return null;
    }, 5);
}

// =================================================================
// Summary
// =================================================================
out("\n=================================================================");
if ($failed === 0) {
    out("  ALL PASSED: {$passed} passed, {$warnings} warnings / {$totalTests} total");
} else {
    out("  RESULT: {$passed} passed, {$failed} FAILED, {$warnings} warnings / {$totalTests} total");
}
out('  Log: ' . $logFile);

if ($failed > 0) {
    out("\n  FAILED TESTS:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out('    x ' . $r['name']);
            out('      ' . $r['error']);
        }
    }
}

if ($warnings > 0) {
    out("\n  WARNINGS:");
    foreach ($results as $r) {
        if ($r['status'] === 'WARN') {
            out('    ~ ' . $r['name']);
            if (!empty($r['error'])) {
                out('      ' . $r['error']);
            }
        }
    }
}

out('=================================================================');

if ($jsonOut) {
    fwrite(STDOUT, json_encode([
        'account' => $testEmail,
        'totals' => [
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
            'total' => $totalTests,
        ],
        'results' => $results,
        'log' => $logFile,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fwrite(STDOUT, "\n");
}

exit($failed > 0 ? 1 : 0);
