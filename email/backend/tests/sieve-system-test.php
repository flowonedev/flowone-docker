#!/usr/bin/env php
<?php
/**
 * FlowOne Sieve System - Comprehensive Test Suite
 *
 * Tests ManageSieve connectivity, script generation, blocked/safe senders,
 * user-created filters, vacation auto-reply, compilation, and sync paths.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/sieve-system-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --password=PASS      Test account password (required for ManageSieve tests)
 *   --only=group,group   Run only specific test groups
 *   --smoke              Quick health check only (connectivity + config, no DB writes)
 *   --skip-send          Skip ManageSieve write operations (PUTSCRIPT, SETACTIVE)
 *   --verbose            Show extra debug info
 *   --json               Output results as JSON
 *   --help               Show this help
 *
 * Test groups: preflight, protocol, blocked, safe, filters, generation,
 *              vacation, sync, compile, cleanup
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'password:', 'only:', 'smoke', 'skip-send', 'verbose', 'json', 'help']);
if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Sieve System Test Suite\n";
    echo "================================\n\n";
    echo "Usage:\n";
    echo "  php sieve-system-test.php --email=user@flowone.pro --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --password=PASS      Test account password (required for ManageSieve tests)\n";
    echo "  --only=group,group   Run only specific groups (preflight,protocol,blocked,\n";
    echo "                       safe,filters,generation,vacation,sync,compile,cleanup)\n";
    echo "  --smoke              Quick health check only\n";
    echo "  --skip-send          Skip ManageSieve write operations\n";
    echo "  --verbose            Extra debug output\n";
    echo "  --json               Output results as JSON\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/sieve-system-test.php \\\n";
    echo "      --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(1);
}

$testEmail    = strtolower($opts['email']);
$testPassword = $opts['password'] ?? null;
$smokeOnly    = isset($opts['smoke']);
$skipSend     = isset($opts['skip-send']);
$verbose      = isset($opts['verbose']);
$jsonOutput   = isset($opts['json']);
$onlyGroups   = !empty($opts['only']) ? explode(',', $opts['only']) : [];

$TEST_TAG = '[FLOWONE-TEST]';
$runId    = 'sieve_test_' . date('His');

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/sieve-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

$cleanupFilterIds  = [];
$cleanupBlockedIds = [];
$cleanupSafeIds    = [];

$RED    = "\033[0;31m";
$GREEN  = "\033[0;32m";
$YELLOW = "\033[1;33m";
$CYAN   = "\033[0;36m";
$NC     = "\033[0m";

function out(string $msg): void {
    global $logFile, $jsonOutput;
    if ($jsonOutput) return;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . strip_tags(preg_replace('/\033\[[0-9;]*m/', '', $line)), FILE_APPEND | LOCK_EX);
}

function vlog(string $msg): void {
    global $verbose;
    if ($verbose) out("          {$msg}");
}

function shouldRun(string $group): bool {
    global $onlyGroups;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose, $GREEN, $RED, $YELLOW, $NC;
    $totalTests++;
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

function assert_contains(string $haystack, string $needle, string $msg = ''): void {
    if (strpos($haystack, $needle) === false) {
        $detail = $msg ?: "String does not contain '$needle'";
        throw new \RuntimeException($detail);
    }
}

function assert_not_contains(string $haystack, string $needle, string $msg = ''): void {
    if (strpos($haystack, $needle) !== false) {
        $detail = $msg ?: "String unexpectedly contains '$needle'";
        throw new \RuntimeException($detail);
    }
}

// ── Cleanup handler ─────────────────────────────────────────────

function doCleanup(): void {
    global $cleanupFilterIds, $cleanupBlockedIds, $cleanupSafeIds, $testEmail, $config;
    if (empty($cleanupFilterIds) && empty($cleanupBlockedIds) && empty($cleanupSafeIds)) return;

    try {
        $db = \Webmail\Core\Database::getConnection($config);

        foreach ($cleanupFilterIds as $id) {
            try {
                $stmt = $db->prepare('DELETE FROM webmail_filters WHERE id = ? AND email = ?');
                $stmt->execute([$id, strtolower($testEmail)]);
            } catch (\Throwable $e) {}
        }
        foreach ($cleanupBlockedIds as $id) {
            try {
                $stmt = $db->prepare('DELETE FROM webmail_blocked_senders WHERE id = ? AND user_email = ?');
                $stmt->execute([$id, strtolower($testEmail)]);
            } catch (\Throwable $e) {}
        }
        foreach ($cleanupSafeIds as $id) {
            try {
                $stmt = $db->prepare('DELETE FROM webmail_safe_senders WHERE id = ? AND user_email = ?');
                $stmt->execute([$id, strtolower($testEmail)]);
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        error_log("[Sieve Test Cleanup] Error: " . $e->getMessage());
    }
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Sieve System Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account:  {$testEmail}");
out("  Password: " . ($testPassword ? 'provided' : 'NOT provided (ManageSieve tests will be skipped)'));
out("  Mode:     " . ($smokeOnly ? 'SMOKE (health check only)' : 'FULL'));
if (!empty($onlyGroups)) out("  Groups:   " . implode(', ', $onlyGroups));
if ($skipSend) out("  Skip:     ManageSieve write operations");
out("  Log:      {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

test('PHP extensions loaded', function () {
    $required = ['pdo', 'pdo_mysql', 'mbstring'];
    $missing = [];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) $missing[] = $ext;
    }
    assert_true(empty($missing), 'Missing extensions: ' . implode(', ', $missing));
});

$db = null;

test('Database connection', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db instanceof \PDO, 'Not a PDO instance');
});

test('webmail_filters table exists', function () use (&$db) {
    if (!$db) throw new \RuntimeException('No DB');
    $stmt = $db->query("SHOW TABLES LIKE 'webmail_filters'");
    assert_true($stmt->rowCount() > 0, 'Table webmail_filters not found');
});

test('webmail_blocked_senders table exists', function () use (&$db) {
    if (!$db) throw new \RuntimeException('No DB');
    $stmt = $db->query("SHOW TABLES LIKE 'webmail_blocked_senders'");
    assert_true($stmt->rowCount() > 0, 'Table webmail_blocked_senders not found');
});

test('webmail_safe_senders table exists', function () use (&$db) {
    if (!$db) throw new \RuntimeException('No DB');
    $stmt = $db->query("SHOW TABLES LIKE 'webmail_safe_senders'");
    assert_true($stmt->rowCount() > 0, 'Table webmail_safe_senders not found');
});

test('webmail_spam_settings table exists', function () use (&$db) {
    if (!$db) throw new \RuntimeException('No DB');
    $stmt = $db->query("SHOW TABLES LIKE 'webmail_spam_settings'");
    assert_true($stmt->rowCount() > 0, 'Table webmail_spam_settings not found');
});

if ($smokeOnly) goto summary;

// ══════════════════════════════════════════════════════════════════
// 1. PREFLIGHT - ManageSieve + Dovecot config
// ══════════════════════════════════════════════════════════════════

if (shouldRun('preflight')) {
    out("\n--- 1. PREFLIGHT (SIEVE INFRASTRUCTURE) ---");

    $sieveHost = $config['imap']['sieve_host'] ?? 'localhost';
    $sievePort = $config['imap']['sieve_port'] ?? 4190;

    test('ManageSieve port reachable', function () use ($sieveHost, $sievePort) {
        $sock = @fsockopen($sieveHost, $sievePort, $errno, $errstr, 5);
        assert_true($sock !== false, "Cannot connect to {$sieveHost}:{$sievePort} - {$errstr} ({$errno})");
        fclose($sock);
    });

    test('sievec binary available', function () {
        $output = [];
        $rc = 0;
        exec('which sievec 2>/dev/null || sudo which sievec 2>/dev/null', $output, $rc);
        assert_true($rc === 0 || !empty($output), 'sievec binary not found in PATH');
        vlog('sievec at: ' . ($output[0] ?? 'unknown'));
    });

    test('Dovecot sieve plugin configured', function () {
        $confFile = '/etc/dovecot/conf.d/90-sieve.conf';
        if (!file_exists($confFile)) {
            $confFile = '/etc/dovecot/conf.d/90-plugin.conf';
        }
        if (!file_exists($confFile)) return 'warn';

        $content = file_get_contents($confFile);
        assert_contains($content, 'sieve', 'Sieve not mentioned in Dovecot plugin config');
    });

    test('ManageSieve daemon configured', function () {
        $confFile = '/etc/dovecot/conf.d/20-managesieve.conf';
        if (!file_exists($confFile)) return 'warn';

        $content = file_get_contents($confFile);
        assert_contains($content, 'managesieve', 'ManageSieve not configured');
    });

    test('Sieve global directories exist', function () {
        $dirs = ['/var/mail/sieve', '/var/mail/sieve/global'];
        $missing = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) $missing[] = $dir;
        }
        if (!empty($missing)) return 'warn';
    });
}

// ══════════════════════════════════════════════════════════════════
// 2. PROTOCOL - ManageSieve connect/auth/list
// ══════════════════════════════════════════════════════════════════

if (shouldRun('protocol')) {
    out("\n--- 2. MANAGESIEVE PROTOCOL ---");

    if (!$testPassword) {
        out("  {$YELLOW}[SKIP]{$NC}  No password provided, skipping ManageSieve protocol tests");
    } else {
        test('ManageSieve connect + authenticate', function () use ($config, $testEmail, $testPassword) {
            $sieve = new \Webmail\Services\SieveService($config['imap'] ?? []);
            $connected = $sieve->connect($testEmail, $testPassword);
            assert_true($connected, 'ManageSieve auth failed: ' . ($sieve->getLastError() ?? 'unknown'));
            $sieve->disconnect();
        });

        test('ManageSieve LISTSCRIPTS', function () use ($config, $testEmail, $testPassword) {
            $sieve = new \Webmail\Services\SieveService($config['imap'] ?? []);
            assert_true($sieve->connect($testEmail, $testPassword), 'Connect failed');

            $scripts = $sieve->listScripts();
            assert_true(is_array($scripts), 'LISTSCRIPTS did not return array');
            vlog('Found ' . count($scripts) . ' script(s)');
            foreach ($scripts as $s) {
                vlog('  - ' . $s['name'] . ($s['active'] ? ' (ACTIVE)' : ''));
            }
            $sieve->disconnect();
        });

        test('ManageSieve bad password rejected', function () use ($config, $testEmail) {
            $sieve = new \Webmail\Services\SieveService($config['imap'] ?? []);
            $connected = $sieve->connect($testEmail, 'definitely_wrong_password_' . mt_rand());
            assert_true(!$connected, 'ManageSieve accepted bad password - auth is broken');
            $sieve->disconnect();
        });
    }
}

// ══════════════════════════════════════════════════════════════════
// 3. BLOCKED SENDERS - DB + script generation roundtrip
// ══════════════════════════════════════════════════════════════════

if (shouldRun('blocked')) {
    out("\n--- 3. BLOCKED SENDERS ---");

    $spamService = new \Webmail\Services\SpamService($config);
    $sieveService = new \Webmail\Services\SieveService($config['imap'] ?? []);

    test('Block a test sender (email)', function () use ($spamService, $testEmail, $TEST_TAG, &$cleanupBlockedIds) {
        $blockedAddr = "spammer-{$GLOBALS['runId']}@flowone-test.invalid";
        $ok = $spamService->blockSender($testEmail, $blockedAddr, $TEST_TAG);
        assert_true($ok, 'blockSender returned false');

        $blocked = $spamService->getBlockedSenders($testEmail);
        $found = null;
        foreach ($blocked as $b) {
            if ($b['blocked_email'] === $blockedAddr) { $found = $b; break; }
        }
        assert_not_empty($found, 'Blocked sender not found in DB after insert');
        $cleanupBlockedIds[] = $found['id'];
        vlog("Blocked ID: {$found['id']}");
    });

    test('Block a test sender (domain)', function () use ($spamService, $testEmail, $TEST_TAG, &$cleanupBlockedIds) {
        $blockedAddr = "anyone-{$GLOBALS['runId']}@flowone-test-domain.invalid";
        $ok = $spamService->blockSender($testEmail, $blockedAddr, $TEST_TAG, true);
        assert_true($ok, 'blockSender (domain) returned false');

        $blocked = $spamService->getBlockedSenders($testEmail);
        $found = null;
        foreach ($blocked as $b) {
            if ($b['blocked_email'] === $blockedAddr) { $found = $b; break; }
        }
        assert_not_empty($found, 'Domain-blocked sender not found in DB');
        assert_not_empty($found['blocked_domain'], 'blocked_domain column should be set');
        $cleanupBlockedIds[] = $found['id'];
        vlog("Blocked domain: {$found['blocked_domain']}");
    });

    test('Blocked sender generates fileinto + stop in script', function () use ($sieveService, $spamService, $testEmail) {
        $blocked = $spamService->getBlockedSenders($testEmail);
        $script = $sieveService->generateFullScript([], null, $blocked, [], 'INBOX.Spam');
        assert_contains($script, 'fileinto "INBOX.Spam"', 'Missing fileinto for blocked sender');
        assert_contains($script, 'stop', 'Missing stop after blocked sender rule');
        assert_contains($script, 'BLOCKED SENDERS', 'Missing blocked senders section header');
        vlog("Script length: " . strlen($script) . " bytes");
    });

    test('Domain block uses address :domain test', function () use ($sieveService, $spamService, $testEmail) {
        $blocked = $spamService->getBlockedSenders($testEmail);
        $script = $sieveService->generateFullScript([], null, $blocked, [], 'INBOX.Spam');
        assert_contains($script, ':domain :is "from"', 'Domain block should use :domain match');
    });

    test('isSenderBlocked returns true for blocked email', function () use ($spamService, $testEmail) {
        $blockedAddr = "spammer-{$GLOBALS['runId']}@flowone-test.invalid";
        assert_true($spamService->isSenderBlocked($testEmail, $blockedAddr), 'isSenderBlocked should return true');
    });
}

// ══════════════════════════════════════════════════════════════════
// 4. SAFE SENDERS - whitelist + interaction with blocked
// ══════════════════════════════════════════════════════════════════

if (shouldRun('safe')) {
    out("\n--- 4. SAFE SENDERS ---");

    $spamService = $spamService ?? new \Webmail\Services\SpamService($config);
    $sieveService = $sieveService ?? new \Webmail\Services\SieveService($config['imap'] ?? []);

    test('Add a test safe sender', function () use ($spamService, $testEmail, &$cleanupSafeIds) {
        $safeAddr = "trusted-{$GLOBALS['runId']}@flowone-safe.invalid";
        $ok = $spamService->addSafeSender($testEmail, $safeAddr);
        assert_true($ok, 'addSafeSender returned false');

        $safe = $spamService->getSafeSenders($testEmail);
        $found = null;
        foreach ($safe as $s) {
            if ($s['safe_email'] === $safeAddr) { $found = $s; break; }
        }
        assert_not_empty($found, 'Safe sender not found in DB after insert');
        $cleanupSafeIds[] = $found['id'];
        vlog("Safe ID: {$found['id']}");
    });

    test('Add a test safe sender (trusted domain)', function () use ($spamService, $testEmail, &$cleanupSafeIds) {
        $safeAddr = "anyone-{$GLOBALS['runId']}@flowone-safe-domain.invalid";
        $ok = $spamService->addSafeSender($testEmail, $safeAddr, true);
        assert_true($ok, 'addSafeSender (domain) returned false');

        $safe = $spamService->getSafeSenders($testEmail);
        $found = null;
        foreach ($safe as $s) {
            if ($s['safe_email'] === $safeAddr) { $found = $s; break; }
        }
        assert_not_empty($found, 'Domain-trusted sender not found in DB');
        assert_not_empty($found['safe_domain'], 'safe_domain column should be set');
        $cleanupSafeIds[] = $found['id'];
    });

    test('Safe sender generates whitelist in script', function () use ($sieveService, $spamService, $testEmail) {
        $safe = $spamService->getSafeSenders($testEmail);
        $script = $sieveService->generateFullScript([], null, [], $safe);
        assert_contains($script, 'TRUSTED SENDERS', 'Missing trusted senders section header');
        assert_contains($script, 'address', 'Missing address test in whitelist');
    });

    test('Safe + blocked combo uses variables extension and is_safe flag', function () use ($sieveService, $spamService, $testEmail) {
        $blocked = $spamService->getBlockedSenders($testEmail);
        $safe = $spamService->getSafeSenders($testEmail);
        if (empty($blocked) || empty($safe)) return 'warn';

        $script = $sieveService->generateFullScript([], null, $blocked, $safe, 'INBOX.Spam');
        assert_contains($script, '"variables"', 'Missing variables extension when safe+blocked both present');
        assert_contains($script, 'set "is_safe" "no"', 'Missing is_safe flag initialization');
        assert_contains($script, 'set "is_safe" "yes"', 'Missing is_safe = yes for trusted sender');
        assert_contains($script, '${is_safe}', 'Missing is_safe check in blocked section');
        vlog("Script uses variables extension for safe/blocked interaction");
    });

    test('Trusted domain uses :domain match', function () use ($sieveService, $spamService, $testEmail) {
        $safe = $spamService->getSafeSenders($testEmail);
        $hasDomain = false;
        foreach ($safe as $s) {
            if (!empty($s['safe_domain'])) { $hasDomain = true; break; }
        }
        if (!$hasDomain) return 'warn';

        $script = $sieveService->generateFullScript([], null, [], $safe);
        assert_contains($script, ':domain :is "from"', 'Trusted domain should use :domain match');
    });
}

// ══════════════════════════════════════════════════════════════════
// 5. USER FILTERS - create in DB, verify script output
// ══════════════════════════════════════════════════════════════════

if (shouldRun('filters')) {
    out("\n--- 5. USER-CREATED FILTERS ---");

    $filterService = new \Webmail\Services\FilterService($config);
    $sieveService = $sieveService ?? new \Webmail\Services\SieveService($config['imap'] ?? []);

    test('Create move-to-folder filter', function () use ($filterService, $testEmail, $TEST_TAG, &$cleanupFilterIds) {
        $filter = $filterService->createFilter($testEmail, [
            'name' => "{$TEST_TAG} Move Newsletter",
            'enabled' => true,
            'priority' => 0,
            'conditions' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'from', 'operator' => 'contains', 'value' => 'newsletter-test-' . $GLOBALS['runId']],
                ],
            ],
            'actions' => [
                ['action' => 'move', 'value' => 'Newsletters'],
            ],
            'stop_processing' => true,
        ]);
        assert_not_empty($filter, 'createFilter returned null');
        assert_not_empty($filter['id'], 'Filter ID is empty');
        $cleanupFilterIds[] = $filter['id'];
        vlog("Filter ID: {$filter['id']}");
    });

    test('Create delete filter', function () use ($filterService, $testEmail, $TEST_TAG, &$cleanupFilterIds) {
        $filter = $filterService->createFilter($testEmail, [
            'name' => "{$TEST_TAG} Delete Junk",
            'enabled' => true,
            'conditions' => [
                'match' => 'any',
                'rules' => [
                    ['field' => 'subject', 'operator' => 'contains', 'value' => 'junk-test-' . $GLOBALS['runId']],
                ],
            ],
            'actions' => [
                ['action' => 'delete'],
            ],
        ]);
        assert_not_empty($filter, 'createFilter (delete) returned null');
        $cleanupFilterIds[] = $filter['id'];
    });

    test('Create star + mark-read filter', function () use ($filterService, $testEmail, $TEST_TAG, &$cleanupFilterIds) {
        $filter = $filterService->createFilter($testEmail, [
            'name' => "{$TEST_TAG} Star Important",
            'enabled' => true,
            'conditions' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'from', 'operator' => 'equals', 'value' => "boss-{$GLOBALS['runId']}@flowone-test.invalid"],
                ],
            ],
            'actions' => [
                ['action' => 'star'],
                ['action' => 'mark_read'],
            ],
        ]);
        assert_not_empty($filter, 'createFilter (star+read) returned null');
        $cleanupFilterIds[] = $filter['id'];
    });

    test('Create disabled filter (should not appear in script)', function () use ($filterService, $testEmail, $TEST_TAG, &$cleanupFilterIds) {
        $filter = $filterService->createFilter($testEmail, [
            'name' => "{$TEST_TAG} Disabled Filter",
            'enabled' => false,
            'conditions' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'subject', 'operator' => 'contains', 'value' => 'disabled-' . $GLOBALS['runId']],
                ],
            ],
            'actions' => [
                ['action' => 'move', 'value' => 'Archive'],
            ],
        ]);
        assert_not_empty($filter, 'createFilter (disabled) returned null');
        $cleanupFilterIds[] = $filter['id'];
    });

    test('Move filter generates fileinto with INBOX. prefix', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, 'fileinto "INBOX.Newsletters"', 'Move action should prepend INBOX. namespace');
    });

    test('Delete filter generates discard', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, 'discard', 'Delete action should map to discard');
    });

    test('Star action generates addflag Flagged', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, 'addflag "\\\\Flagged"', 'Star action should set \\Flagged');
        assert_contains($script, '"imap4flags"', 'imap4flags extension required for star/mark_read');
    });

    test('Mark-read generates addflag Seen', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, 'addflag "\\\\Seen"', 'mark_read should set \\Seen');
    });

    test('Contains operator uses :contains', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, ':contains', 'Contains operator should map to :contains');
    });

    test('Equals operator uses :is', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, ':is "From"', 'Equals operator should map to :is on header');
    });

    test('stop_processing generates stop statement', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, "    stop;\n}", 'stop_processing should add stop inside the if block');
    });

    test('Disabled filter not in generated script', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $script = $sieveService->generateFullScript($filters);
        assert_not_contains($script, 'disabled-' . $GLOBALS['runId'], 'Disabled filter should not appear in script');
    });

    test('anyof match type for "any" condition', function () use ($filterService, $sieveService, $testEmail) {
        $filters = $filterService->getFilters($testEmail);
        $hasAny = false;
        foreach ($filters as $f) {
            if (($f['conditions']['match'] ?? '') === 'any') { $hasAny = true; break; }
        }
        if (!$hasAny) return 'warn';

        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, 'anyof', '"any" match should produce anyof combiner');
    });
}

// ══════════════════════════════════════════════════════════════════
// 6. SCRIPT GENERATION - pure unit tests on generateFullScript
// ══════════════════════════════════════════════════════════════════

if (shouldRun('generation')) {
    out("\n--- 6. SCRIPT GENERATION (UNIT) ---");

    $sieveService = $sieveService ?? new \Webmail\Services\SieveService($config['imap'] ?? []);

    test('Empty script (no rules) has require and header', function () use ($sieveService) {
        $script = $sieveService->generateFullScript([]);
        assert_contains($script, 'require ["fileinto"]', 'Empty script should still require fileinto');
        assert_contains($script, 'Auto-generated script', 'Missing script header comment');
    });

    test('Body condition adds body extension', function () use ($sieveService) {
        $filters = [[
            'name' => 'body test',
            'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'body', 'operator' => 'contains', 'value' => 'test-keyword'],
            ]],
            'actions' => [['action' => 'move', 'value' => 'BodyMatch']],
            'stop_processing' => false,
        ]];
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, '"body"', 'Body condition should add body extension to require');
        assert_contains($script, 'body :text :contains "test-keyword"', 'Body condition syntax incorrect');
    });

    test('Regex condition adds regex extension', function () use ($sieveService) {
        $filters = [[
            'name' => 'regex test',
            'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'subject', 'operator' => 'matches_regex', 'value' => '^\\[URGENT\\]'],
            ]],
            'actions' => [['action' => 'star']],
            'stop_processing' => false,
        ]];
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, '"regex"', 'Regex condition should add regex extension');
        assert_contains($script, ':regex "Subject"', 'Regex syntax incorrect');
    });

    test('starts_with uses :matches with wildcard suffix', function () use ($sieveService) {
        $filters = [[
            'name' => 'startswith test',
            'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'subject', 'operator' => 'starts_with', 'value' => 'PREFIX'],
            ]],
            'actions' => [['action' => 'move', 'value' => 'Prefixed']],
            'stop_processing' => false,
        ]];
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, ':matches "Subject" "PREFIX*"', 'starts_with should use :matches with * suffix');
    });

    test('ends_with uses :matches with wildcard prefix', function () use ($sieveService) {
        $filters = [[
            'name' => 'endswith test',
            'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'subject', 'operator' => 'ends_with', 'value' => 'SUFFIX'],
            ]],
            'actions' => [['action' => 'move', 'value' => 'Suffixed']],
            'stop_processing' => false,
        ]];
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, ':matches "Subject" "*SUFFIX"', 'ends_with should use :matches with * prefix');
    });

    test('not_contains generates negation', function () use ($sieveService) {
        $filters = [[
            'name' => 'not_contains test',
            'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'from', 'operator' => 'not_contains', 'value' => 'trusted'],
            ]],
            'actions' => [['action' => 'move', 'value' => 'Untrusted']],
            'stop_processing' => false,
        ]];
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, 'not header :contains "From" "trusted"', 'not_contains should generate negated condition');
    });

    test('Groups format with multiple condition groups', function () use ($sieveService) {
        $filters = [[
            'name' => 'groups test',
            'enabled' => true,
            'conditions' => [
                'match' => 'any',
                'groups' => [
                    [
                        'match' => 'all',
                        'rules' => [
                            ['field' => 'from', 'operator' => 'contains', 'value' => 'group-a'],
                            ['field' => 'subject', 'operator' => 'contains', 'value' => 'important'],
                        ],
                    ],
                    [
                        'match' => 'all',
                        'rules' => [
                            ['field' => 'to', 'operator' => 'contains', 'value' => 'group-b'],
                        ],
                    ],
                ],
            ],
            'actions' => [['action' => 'star']],
            'stop_processing' => false,
        ]];
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, 'anyof', 'Groups with match=any should use anyof at top level');
        assert_contains($script, 'allof', 'Group with match=all should use allof for its rules');
    });

    test('Exceptions generate negated conditions', function () use ($sieveService) {
        $filters = [[
            'name' => 'exception test',
            'enabled' => true,
            'conditions' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'from', 'operator' => 'contains', 'value' => '@newsletter.com'],
                ],
                'exceptions' => [
                    'rules' => [
                        ['field' => 'from', 'operator' => 'contains', 'value' => 'important'],
                    ],
                ],
            ],
            'actions' => [['action' => 'move', 'value' => 'Newsletters']],
            'stop_processing' => false,
        ]];
        $script = $sieveService->generateFullScript($filters);
        assert_contains($script, 'allof', 'Exception should wrap in allof(main, not exception)');
        assert_contains($script, 'not header :contains "From" "important"', 'Exception should be negated');
    });

    test('Special characters escaped in sieve strings', function () use ($sieveService) {
        $filters = [[
            'name' => 'escape test',
            'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'He said "hello" and \\bye'],
            ]],
            'actions' => [['action' => 'move', 'value' => 'Escaped']],
            'stop_processing' => false,
        ]];
        $script = $sieveService->generateFullScript($filters);
        assert_not_contains($script, '"hello"', 'Quotes inside value should be escaped, not bare');
        assert_contains($script, '\\"hello\\"', 'Double quotes should be backslash-escaped');
    });

    test('Custom spam folder name used in blocked rules', function () use ($sieveService) {
        $blocked = [['blocked_email' => 'test@evil.com', 'blocked_domain' => null]];
        $script = $sieveService->generateFullScript([], null, $blocked, [], 'INBOX.Junk');
        assert_contains($script, 'fileinto "INBOX.Junk"', 'Should use custom spam folder name');
        assert_not_contains($script, 'INBOX.Spam', 'Should not use default INBOX.Spam when custom folder set');
    });
}

// ══════════════════════════════════════════════════════════════════
// 7. VACATION AUTO-REPLY
// ══════════════════════════════════════════════════════════════════

if (shouldRun('vacation')) {
    out("\n--- 7. VACATION AUTO-REPLY ---");

    $sieveService = $sieveService ?? new \Webmail\Services\SieveService($config['imap'] ?? []);

    test('Vacation generates vacation command', function () use ($sieveService) {
        $vacation = [
            'enabled' => true,
            'subject' => 'Out of Office',
            'message' => 'I am currently away. Will respond when I return.',
            'from' => 'test@flowone-test.invalid',
        ];
        $script = $sieveService->generateFullScript([], $vacation);
        assert_contains($script, '"vacation"', 'Vacation should add vacation extension to require');
        assert_contains($script, 'vacation :days 1', 'Vacation should set :days');
        assert_contains($script, ':subject "Out of Office"', 'Vacation should include subject');
        assert_contains($script, ':from "test@flowone-test.invalid"', 'Vacation should include from');
        assert_contains($script, 'I am currently away', 'Vacation should include message body');
    });

    test('Disabled vacation not in script', function () use ($sieveService) {
        $vacation = [
            'enabled' => false,
            'subject' => 'OOO',
            'message' => 'Should not appear',
            'from' => 'test@flowone-test.invalid',
        ];
        $script = $sieveService->generateFullScript([], $vacation);
        assert_not_contains($script, 'vacation', 'Disabled vacation should not generate vacation command');
    });

    test('Vacation with empty message not in script', function () use ($sieveService) {
        $vacation = [
            'enabled' => true,
            'subject' => 'OOO',
            'message' => '',
            'from' => 'test@flowone-test.invalid',
        ];
        $script = $sieveService->generateFullScript([], $vacation);
        assert_not_contains($script, 'vacation :days', 'Vacation with empty message should not generate command');
    });

    test('Vacation HTML stripped from message', function () use ($sieveService) {
        $vacation = [
            'enabled' => true,
            'subject' => 'Away',
            'message' => '<p>I am <strong>away</strong> until <em>Monday</em>.</p>',
            'from' => 'test@flowone-test.invalid',
        ];
        $script = $sieveService->generateFullScript([], $vacation);
        assert_not_contains($script, '<p>', 'HTML tags should be stripped from vacation message');
        assert_not_contains($script, '<strong>', 'HTML tags should be stripped');
        assert_contains($script, 'I am away until Monday.', 'Plain text content should remain after strip');
    });

    test('Full combo: filters + blocked + safe + vacation', function () use ($sieveService) {
        $filters = [[
            'name' => 'combo filter',
            'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
            ]],
            'actions' => [['action' => 'move', 'value' => 'Finance']],
            'stop_processing' => false,
        ]];
        $vacation = ['enabled' => true, 'subject' => 'OOO', 'message' => 'Away', 'from' => 'test@test.invalid'];
        $blocked = [['blocked_email' => 'bad@evil.com', 'blocked_domain' => null]];
        $safe = [['safe_email' => 'friend@good.com', 'safe_domain' => null]];

        $script = $sieveService->generateFullScript($filters, $vacation, $blocked, $safe, 'INBOX.Spam');

        assert_contains($script, 'TRUSTED SENDERS', 'Full combo should have trusted section');
        assert_contains($script, 'BLOCKED SENDERS', 'Full combo should have blocked section');
        assert_contains($script, 'Filter: combo filter', 'Full combo should have user filter');
        assert_contains($script, 'VACATION AUTO-REPLY', 'Full combo should have vacation');

        $trustedPos = strpos($script, 'TRUSTED SENDERS');
        $blockedPos = strpos($script, 'BLOCKED SENDERS');
        $filterPos  = strpos($script, 'Filter: combo filter');
        $vacaPos    = strpos($script, 'VACATION AUTO-REPLY');

        assert_true($trustedPos < $blockedPos, 'Trusted should come before blocked');
        assert_true($blockedPos < $filterPos, 'Blocked should come before user filters');
        assert_true($filterPos < $vacaPos, 'Filters should come before vacation');

        vlog("Script order verified: trusted -> blocked -> filters -> vacation");
    });
}

// ══════════════════════════════════════════════════════════════════
// 8. SYNC - ManageSieve roundtrip + disk write
// ══════════════════════════════════════════════════════════════════

if (shouldRun('sync')) {
    out("\n--- 8. SYNC PATHS ---");

    $syncService = new \Webmail\Services\SieveSyncService($config);

    if ($testPassword && !$skipSend) {
        test('ManageSieve sync roundtrip (put + activate + get + delete)', function () use ($config, $testEmail, $testPassword) {
            $sieve = new \Webmail\Services\SieveService($config['imap'] ?? []);
            assert_true($sieve->connect($testEmail, $testPassword), 'Connect failed');

            $testScript = "# [FLOWONE-TEST] Sieve roundtrip test\nrequire [\"fileinto\"];\n";
            $scriptName = \Webmail\Services\SieveService::SCRIPT_NAME;

            $putOk = $sieve->putScript($scriptName, $testScript);
            assert_true($putOk, 'PUTSCRIPT failed: ' . ($sieve->getLastError() ?? 'unknown'));

            $activateOk = $sieve->activateScript($scriptName);
            assert_true($activateOk, 'SETACTIVE failed');

            $scripts = $sieve->listScripts();
            $found = false;
            foreach ($scripts as $s) {
                if ($s['name'] === $scriptName && $s['active']) { $found = true; break; }
            }
            assert_true($found, 'Script not found as active after SETACTIVE');

            $content = $sieve->getScript($scriptName);
            assert_not_empty($content, 'GETSCRIPT returned empty');
            assert_contains($content, 'FLOWONE-TEST', 'Retrieved script does not match uploaded content');

            $sieve->deactivateScripts();
            $sieve->deleteScript($scriptName);

            $scriptsAfter = $sieve->listScripts();
            $stillThere = false;
            foreach ($scriptsAfter as $s) {
                if ($s['name'] === $scriptName) { $stillThere = true; break; }
            }
            assert_true(!$stillThere, 'Script still exists after DELETESCRIPT');

            $sieve->disconnect();
            vlog('Full ManageSieve roundtrip: put -> activate -> list -> get -> deactivate -> delete -> verify');
        });

        test('SieveSyncService.sync() via ManageSieve', function () use ($syncService, $testEmail, $testPassword, $config) {
            $result = $syncService->sync($testEmail, $testPassword);
            assert_true($result['success'], 'SieveSyncService sync failed: ' . ($result['error'] ?? 'unknown'));
            assert_equals('managesieve', $result['method'], 'Expected managesieve method');
            assert_not_empty($result['script'], 'Sync returned empty script');
            vlog('Script synced via ManageSieve, ' . strlen($result['script']) . ' bytes');

            $sieve = new \Webmail\Services\SieveService($config['imap'] ?? []);
            assert_true($sieve->connect($testEmail, $testPassword), 'Post-sync connect failed');
            $sieve->deactivateScripts();
            $sieve->deleteScript(\Webmail\Services\SieveService::SCRIPT_NAME);
            $sieve->disconnect();
        });
    } else {
        $reason = !$testPassword ? 'No password provided' : 'Skip-send mode';
        out("  {$YELLOW}[SKIP]{$NC}  ManageSieve sync tests ({$reason})");
    }

    test('SieveSyncService.generateScript() returns valid string', function () use ($syncService, $testEmail) {
        $script = $syncService->generateScript($testEmail);
        assert_true(is_string($script), 'generateScript should return a string');
        assert_contains($script, 'require', 'Generated script should have require statement');
        vlog('Generated script: ' . strlen($script) . ' bytes');
    });
}

// ══════════════════════════════════════════════════════════════════
// 9. COMPILE - sievec validation of generated scripts
// ══════════════════════════════════════════════════════════════════

if (shouldRun('compile')) {
    out("\n--- 9. SIEVE COMPILATION ---");

    $sieveService = $sieveService ?? new \Webmail\Services\SieveService($config['imap'] ?? []);

    $compileTmpDir = sys_get_temp_dir() . '/flowone-sieve-test-' . $GLOBALS['runId'];
    @mkdir($compileTmpDir, 0755, true);

    $compileScripts = [
        'empty' => $sieveService->generateFullScript([]),
        'blocked_only' => $sieveService->generateFullScript([], null, [
            ['blocked_email' => 'bad@evil.com', 'blocked_domain' => null],
        ], [], 'INBOX.Spam'),
        'safe_only' => $sieveService->generateFullScript([], null, [], [
            ['safe_email' => 'good@friend.com', 'safe_domain' => null],
        ]),
        'blocked_and_safe' => $sieveService->generateFullScript([], null, [
            ['blocked_email' => 'bad@evil.com', 'blocked_domain' => null],
            ['blocked_email' => 'any@evil-domain.com', 'blocked_domain' => 'evil-domain.com'],
        ], [
            ['safe_email' => 'good@friend.com', 'safe_domain' => null],
            ['safe_email' => 'any@trusted.com', 'safe_domain' => 'trusted.com'],
        ], 'INBOX.Spam'),
        'filter_move' => $sieveService->generateFullScript([[
            'name' => 'move test', 'enabled' => true, 'stop_processing' => false,
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'from', 'operator' => 'contains', 'value' => 'test']]],
            'actions' => [['action' => 'move', 'value' => 'Archive']],
        ]]),
        'filter_flags' => $sieveService->generateFullScript([[
            'name' => 'flags test', 'enabled' => true, 'stop_processing' => false,
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'subject', 'operator' => 'equals', 'value' => 'VIP']]],
            'actions' => [['action' => 'star'], ['action' => 'mark_read']],
        ]]),
        'vacation' => $sieveService->generateFullScript([], [
            'enabled' => true, 'subject' => 'Away', 'message' => 'I am out of office.', 'from' => 'test@test.com',
        ]),
        'full_combo' => $sieveService->generateFullScript(
            [[
                'name' => 'combo', 'enabled' => true, 'stop_processing' => true,
                'conditions' => ['match' => 'all', 'rules' => [['field' => 'from', 'operator' => 'contains', 'value' => 'news']]],
                'actions' => [['action' => 'move', 'value' => 'News'], ['action' => 'mark_read']],
            ]],
            ['enabled' => true, 'subject' => 'OOO', 'message' => 'Away', 'from' => 'me@test.com'],
            [['blocked_email' => 'spam@bad.com', 'blocked_domain' => null]],
            [['safe_email' => 'ok@good.com', 'safe_domain' => null]],
            'INBOX.Spam'
        ),
    ];

    foreach ($compileScripts as $label => $script) {
        test("sievec compiles: {$label}", function () use ($compileTmpDir, $label, $script) {
            $sieveFile = "{$compileTmpDir}/{$label}.sieve";
            file_put_contents($sieveFile, $script);

            $output = [];
            $rc = 0;
            exec("sievec " . escapeshellarg($sieveFile) . " 2>&1", $output, $rc);
            if ($rc !== 0) {
                exec("sudo sievec " . escapeshellarg($sieveFile) . " 2>&1", $output, $rc);
            }
            assert_true($rc === 0, "sievec failed (exit {$rc}): " . implode(' ', $output));

            $svbin = "{$compileTmpDir}/{$label}.svbin";
            assert_true(file_exists($svbin), 'Compiled .svbin file not created');
            vlog("Compiled OK: " . filesize($svbin) . " bytes");
        });
    }

    // Cleanup temp compile dir
    @array_map('unlink', glob("{$compileTmpDir}/*"));
    @rmdir($compileTmpDir);
}

// ══════════════════════════════════════════════════════════════════
// 10. CLEANUP
// ══════════════════════════════════════════════════════════════════

summary:
out("\n--- CLEANUP ---");

test('Remove test filters from DB', function () use (&$cleanupFilterIds, $testEmail, $config) {
    if (empty($cleanupFilterIds)) {
        vlog('No test filters to clean up');
        return;
    }
    $db = \Webmail\Core\Database::getConnection($config);
    $count = 0;
    foreach ($cleanupFilterIds as $id) {
        try {
            $stmt = $db->prepare('DELETE FROM webmail_filters WHERE id = ? AND email = ?');
            $stmt->execute([$id, strtolower($testEmail)]);
            $count += $stmt->rowCount();
        } catch (\Throwable $e) {}
    }
    $cleanupFilterIds = [];
    vlog("Removed {$count} test filter(s)");
});

test('Remove test blocked senders from DB', function () use (&$cleanupBlockedIds, $testEmail, $config) {
    if (empty($cleanupBlockedIds)) {
        vlog('No test blocked senders to clean up');
        return;
    }
    $db = \Webmail\Core\Database::getConnection($config);
    $count = 0;
    foreach ($cleanupBlockedIds as $id) {
        try {
            $stmt = $db->prepare('DELETE FROM webmail_blocked_senders WHERE id = ? AND user_email = ?');
            $stmt->execute([$id, strtolower($testEmail)]);
            $count += $stmt->rowCount();
        } catch (\Throwable $e) {}
    }
    $cleanupBlockedIds = [];
    vlog("Removed {$count} test blocked sender(s)");
});

test('Remove test safe senders from DB', function () use (&$cleanupSafeIds, $testEmail, $config) {
    if (empty($cleanupSafeIds)) {
        vlog('No test safe senders to clean up');
        return;
    }
    $db = \Webmail\Core\Database::getConnection($config);
    $count = 0;
    foreach ($cleanupSafeIds as $id) {
        try {
            $stmt = $db->prepare('DELETE FROM webmail_safe_senders WHERE id = ? AND user_email = ?');
            $stmt->execute([$id, strtolower($testEmail)]);
            $count += $stmt->rowCount();
        } catch (\Throwable $e) {}
    }
    $cleanupSafeIds = [];
    vlog("Removed {$count} test safe sender(s)");
});

// ══════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════

if ($jsonOutput) {
    echo json_encode([
        'total' => $totalTests,
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'results' => $results,
        'log' => $logFile,
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
            out("      {$r['error']}");
        }
    }
}

if ($warnings > 0) {
    out("\n  {$YELLOW}WARNINGS:{$NC}");
    foreach ($results as $r) {
        if ($r['status'] === 'WARN') {
            out("    ~ {$r['name']}");
        }
    }
}

out("=================================================================");
exit($failed > 0 ? 1 : 0);
