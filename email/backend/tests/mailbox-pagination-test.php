#!/usr/bin/env php
<?php
/**
 * FlowOne Mailbox Pagination - Test Suite
 *
 * Verifies the page-size-limit chain end-to-end, regression-protecting the
 * "Inbox short-fills to 10 messages" bug:
 *
 *   1. ImapService::getMessages() must always include the `limit` field on
 *      ALL return paths (success + every early-return). Without this, the
 *      frontend stored pagination.value.limit = undefined, the next fetch
 *      sent `?limit=NaN`, PHP coerced to 0, the backend clamped up to 10,
 *      and the page rendered short.
 *   2. PHP's int-cast of the string "NaN" is 0 (documents the trap that
 *      makes link 1 visible to the user).
 *   3. The controller clamp `max(10, min(100, $limit))` lifts a 0 to 10.
 *   4. For OAuth accounts, the parseFetchResponse CRLF fix lets the parser
 *      emit (close to) the full requested page; the historical bug emitted
 *      ~50% due to a +1 / +2 byte miscount in the literal-size guard.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-pagination-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --password=PASS      Test account password (required for non-OAuth path)
 *   --only=GROUPS        Comma-separated groups: limit,empty,invalid,oauth,php
 *   --smoke              Connectivity + limit-on-success only
 *   --json               Emit machine-readable JSON summary at the end
 *   --verbose            Show extra debug info
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Services\ImapService;
use Webmail\Core\Database;

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'password:', 'only:', 'smoke', 'json', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Mailbox Pagination Test Suite\n";
    echo "======================================\n\n";
    echo "Usage:\n";
    echo "  php mailbox-pagination-test.php --email=user@flowone.pro --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --password=PASS      Test account password (required for non-OAuth)\n";
    echo "  --only=GROUPS        Comma-separated: limit,empty,invalid,oauth,php\n";
    echo "  --smoke              Connectivity + limit-on-success only\n";
    echo "  --json               Emit machine-readable JSON summary at the end\n";
    echo "  --verbose            Show extra debug info\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-pagination-test.php \\\n";
    echo "      --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(1);
}

$testEmail    = $opts['email'];
$testPassword = $opts['password'] ?? '';
$verbose      = isset($opts['verbose']);
$smokeOnly    = isset($opts['smoke']);
$jsonOnly     = isset($opts['json']);
$onlyGroups   = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : [];

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return in_array($group, ['limit', 'php'], true);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/mailbox-pagination-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function out(string $msg): void {
    global $logFile, $jsonOnly;
    $line = $msg . "\n";
    if (!$jsonOnly) echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn, int $timeoutSeconds = 30): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    $prevTimeout = ini_get('max_execution_time');
    @set_time_limit($timeoutSeconds);
    try {
        $result = $fn();
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } elseif ($result === 'skip') {
            out("  \033[36m[SKIP]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'SKIP', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  \033[32m[PASS]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    } finally {
        @set_time_limit((int) $prevTimeout);
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

function assert_has_key(array $arr, string $key, string $msg = ''): void {
    if (!array_key_exists($key, $arr)) {
        $label = $msg ?: "Missing key '$key'";
        throw new \RuntimeException("$label. Keys present: " . implode(',', array_keys($arr)));
    }
}

function vlog(string $msg): void {
    global $verbose; if ($verbose) out("          [v] $msg");
}

// ── Cleanup tracking ─────────────────────────────────────────────

$cleanupFolders = [];

function doCleanup(): void {
    global $config, $testEmail, $testPassword, $cleanupFolders;
    if (empty($cleanupFolders)) return;
    out("\n--- CLEANUP ---");
    try {
        $imapClean = new ImapService($config['imap'] ?? []);
        if (!$imapClean->connect($testEmail, $testPassword)) {
            out("  [WARN] cleanup connect failed; some test folders may remain");
            return;
        }
        foreach ($cleanupFolders as $f) {
            try {
                $stripped = preg_replace('/^INBOX\./', '', $f);
                $imapClean->deleteFolder($stripped);
                out("  removed test folder: {$f}");
            } catch (\Throwable $e) {
                out("  [WARN] failed to remove {$f}: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        out("  [WARN] cleanup error: " . $e->getMessage());
    }
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ── Pre-flight ───────────────────────────────────────────────────

out("\n=== FlowOne Mailbox Pagination Test ===");
out("Account: {$testEmail}");
out("Log:     {$logFile}\n");

out("--- 0. PRE-FLIGHT ---");

$preflightOk = true;
test('PHP imap extension loaded', function () {
    if (!extension_loaded('imap')) throw new \RuntimeException('php-imap extension missing');
    return true;
});
test('Storage logs directory writable', function () use ($logDir) {
    if (!is_writable($logDir)) throw new \RuntimeException("not writable: {$logDir}");
    return true;
});
test('IMAP config present', function () use ($config) {
    if (empty($config['imap']['host'])) throw new \RuntimeException('imap.host missing in config');
    return true;
});

if ($failed > 0) {
    out("\n[ABORT] Pre-flight failed -- not running tests against a broken environment.\n");
    exit(1);
}

// ── PHP coercion sanity (group: php) ─────────────────────────────

if (shouldRun('php')) {
    out("\n--- 1. PHP COERCION SANITY ---");

    test('php: (int)"NaN" === 0 (documents the trap)', function () {
        $val = (int) 'NaN';
        assert_equals(0, $val, 'PHP int-cast of "NaN" is not 0');
        return true;
    });

    test('php: (int)"" === 0 (the same trap with empty input)', function () {
        $val = (int) '';
        assert_equals(0, $val);
        return true;
    });

    test('php: controller clamp lifts 0 to 10', function () {
        $limit = max(10, min(100, 0));
        assert_equals(10, $limit, 'controller clamp does not lift 0 to MIN');
        return true;
    });

    test('php: controller clamp keeps 25 / 50 / 100 unchanged', function () {
        assert_equals(25,  max(10, min(100, 25)));
        assert_equals(50,  max(10, min(100, 50)));
        assert_equals(100, max(10, min(100, 100)));
        return true;
    });

    test('php: controller clamp pulls 200 down to 100', function () {
        assert_equals(100, max(10, min(100, 200)));
        return true;
    });
}

// ── IMAP connection ──────────────────────────────────────────────

if (empty($testPassword)) {
    out("\n[INFO] No --password supplied; skipping IMAP-backed tests.");
    out("       Re-run with --password=... to exercise the full pagination chain.\n");
} else {
    out("\n--- 2. IMAP CONNECT ---");

    $imap = new ImapService($config['imap'] ?? []);

    test('IMAP connect', function () use (&$imap, $testEmail, $testPassword) {
        if (!$imap->connect($testEmail, $testPassword)) {
            throw new \RuntimeException('connect() returned false');
        }
        return true;
    }, 15);

    if ($failed > 0) {
        out("\n[ABORT] Cannot connect to IMAP -- skipping all message-list tests.");
    } else {
        // ── Limit round-trip on success path (group: limit) ──

        if (shouldRun('limit')) {
            out("\n--- 3. LIMIT ROUND-TRIP (success path) ---");

            foreach ([25, 50, 100] as $requestedLimit) {
                test("getMessages('INBOX', 1, {$requestedLimit}) includes 'limit' field = {$requestedLimit}", function () use (&$imap, $requestedLimit) {
                    $r = $imap->getMessages('INBOX', 1, $requestedLimit);
                    assert_true(is_array($r), 'getMessages did not return an array');
                    assert_has_key($r, 'limit', "result missing 'limit' field for limit={$requestedLimit}");
                    assert_equals($requestedLimit, (int) $r['limit'], 'limit field mismatch');
                    return true;
                }, 30);

                test("getMessages('INBOX', 1, {$requestedLimit}) returns at most {$requestedLimit} messages", function () use (&$imap, $requestedLimit) {
                    $r = $imap->getMessages('INBOX', 1, $requestedLimit);
                    $count = count($r['messages'] ?? []);
                    if ($count > $requestedLimit) {
                        throw new \RuntimeException("returned {$count} messages, more than the requested {$requestedLimit}");
                    }
                    vlog("returned {$count} messages for limit={$requestedLimit}");
                    return true;
                }, 30);
            }

            test("getMessages includes 'pages' consistent with limit", function () use (&$imap) {
                $r = $imap->getMessages('INBOX', 1, 50);
                assert_has_key($r, 'pages');
                assert_has_key($r, 'total');
                $expected = $r['total'] > 0 ? (int) ceil($r['total'] / 50) : 0;
                assert_equals($expected, (int) $r['pages'], 'pages computed from limit/total mismatch');
                return true;
            }, 30);
        }

        // ── Empty folder includes limit (group: empty) ──

        if (shouldRun('empty')) {
            out("\n--- 4. EMPTY-FOLDER EARLY-RETURN INCLUDES LIMIT ---");

            $emptyName     = 'FLOWONE_TEST_EMPTY_' . date('His') . '_' . mt_rand(100, 999);
            $emptyNameFull = 'INBOX.' . $emptyName;

            test("create empty test folder ({$emptyName})", function () use (&$imap, $emptyName, $emptyNameFull, &$cleanupFolders) {
                $ok = $imap->createFolder($emptyName);
                if (!$ok) throw new \RuntimeException("createFolder returned false for {$emptyName}");
                $cleanupFolders[] = $emptyNameFull;
                return true;
            }, 15);

            test("getMessages('{$emptyNameFull}', 1, 50) includes 'limit' even when total=0", function () use (&$imap, $emptyNameFull) {
                $r = $imap->getMessages($emptyNameFull, 1, 50);
                assert_has_key($r, 'limit', "early-return for empty folder still missing 'limit' (regression of the original bug)");
                assert_equals(50, (int) $r['limit']);
                assert_equals(0,  (int) ($r['total'] ?? -1));
                vlog('empty folder returned: ' . json_encode($r));
                return true;
            }, 15);
        }

        // ── Invalid folder includes limit (group: invalid) ──

        if (shouldRun('invalid')) {
            out("\n--- 5. INVALID-FOLDER EARLY-RETURN INCLUDES LIMIT ---");

            test("getMessages('FLOWONE_TEST_DOES_NOT_EXIST', 1, 50) includes 'limit' on selectFolder failure", function () use (&$imap) {
                $r = $imap->getMessages('FLOWONE_TEST_DOES_NOT_EXIST_' . mt_rand(1000, 9999), 1, 50);
                assert_has_key($r, 'limit', "early-return for invalid folder still missing 'limit' (regression of the original bug)");
                assert_equals(50, (int) $r['limit']);
                vlog('invalid folder returned: ' . json_encode($r));
                return true;
            }, 15);
        }

        // ── OAuth FETCH parser fully populates page (group: oauth) ──

        if (shouldRun('oauth')) {
            out("\n--- 6. OAUTH FETCH PARSER (CRLF fix) ---");

            // OAuth detection: a Google/Microsoft OAuth account has a row in
            // webmail_oauth_tokens keyed by either primary_email (the
            // dashboard login) or oauth_email (a linked external mailbox).
            // Either match means the live IMAP connection for $testEmail
            // would have used XOAUTH2, exercising parseFetchResponse.
            $isOAuthAccount = false;
            $oauthDetectionSkipReason = null;
            try {
                $pdo = Database::getConnection($config);
                $stmt = $pdo->prepare(
                    "SELECT 1 FROM webmail_oauth_tokens
                     WHERE LOWER(primary_email) = LOWER(?)
                        OR LOWER(oauth_email)   = LOWER(?)
                     LIMIT 1"
                );
                $stmt->execute([$testEmail, $testEmail]);
                $isOAuthAccount = (bool) $stmt->fetchColumn();
                vlog('OAuth detection: ' . ($isOAuthAccount ? 'YES' : 'NO') . ' (webmail_oauth_tokens lookup)');
            } catch (\Throwable $e) {
                $oauthDetectionSkipReason = $e->getMessage();
                vlog('OAuth detection failed (non-fatal): ' . $oauthDetectionSkipReason);
            }

            if (!$isOAuthAccount) {
                $skipLabel = $oauthDetectionSkipReason
                    ? 'oauth: skipped (detection unavailable: ' . $oauthDetectionSkipReason . ')'
                    : 'oauth: skipped (account is not OAuth)';
                test($skipLabel, function () { return 'skip'; });
            } else {
                test('oauth: getMessages(INBOX, 1, 50) returns ~full page (CRLF fix)', function () use (&$imap) {
                    $r = $imap->getMessages('INBOX', 1, 50);
                    $count = count($r['messages'] ?? []);
                    $total = (int) ($r['total'] ?? 0);
                    $expected = min(50, $total);
                    if ($expected === 0) return 'warn';
                    $ratio = $count / $expected;
                    vlog("oauth fetch: requested=50, returned={$count}, total={$total}, ratio=" . round($ratio, 2));
                    if ($ratio < 0.9) {
                        throw new \RuntimeException("oauth FETCH parser returned {$count}/{$expected} messages (ratio " . round($ratio, 2) . "); the CRLF +1/+2 bug or a regression is dropping messages");
                    }
                    return true;
                }, 60);
            }
        }
    }
}

// ── Summary ──────────────────────────────────────────────────────

out("\n--- SUMMARY ---");
out("Total:    {$totalTests}");
out("Passed:   \033[32m{$passed}\033[0m");
out("Failed:   \033[31m{$failed}\033[0m");
out("Warnings: \033[33m{$warnings}\033[0m");

if ($failed > 0) {
    out("\nFailed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("  - {$r['name']}: " . ($r['error'] ?? 'unknown error'));
        }
    }
}

if ($jsonOnly) {
    echo json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'results'  => $results,
        'log'      => $logFile,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
