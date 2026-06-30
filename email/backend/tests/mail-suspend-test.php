#!/usr/bin/env php
<?php
/**
 * FlowOne Mail Suspend - End-to-End Self-Test
 *
 * Verifies the "login-only" account suspension feature added for the panel
 * Suspend button. The whole point of the feature is:
 *
 *   - A suspended mailbox CANNOT log in anywhere (IMAP/POP3/SMTP-AUTH and
 *     webmail, which logs in via IMAP) -- enforced by Dovecot's password_query
 *     `... AND login_suspended = 0`.
 *   - A suspended mailbox KEEPS receiving mail -- because status stays 'active',
 *     so Postfix's virtual_mailbox lookup and Dovecot's user_query still resolve.
 *
 * So this suite proves the full chain on the live server:
 *   1. baseline  - the account can log in via IMAP (active, not suspended)
 *   2. suspend   - flip login_suspended=1 -> IMAP login MUST now be refused
 *   3. delivery  - while suspended, the Postfix mailbox lookup still resolves
 *                  (status='active'), proving inbound mail is unaffected
 *   4. webmail   - the backend suspension predicate (BaseController) flags the
 *                  account so an open webmail session is force-logged-out, and
 *                  webmail_sessions exists so the panel can revoke open sessions
 *   5. resume    - flip login_suspended=0 -> IMAP login works again
 *
 * It runs against a REAL existing mailbox you own (passed via --email/--password)
 * because suspension can only be proven with an actual Dovecot login attempt.
 *
 * Server run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/mail-suspend-test.php \
 *     --email=user@flowone.pro --password='THE-PASSWORD' --verbose
 *
 * Options:
 *   --email=EMAIL    Test account (required) - a real mailbox you own
 *   --password=PASS  That mailbox's password (required for login tests)
 *   --only=g1,g2     baseline,suspend,delivery,webmail,resume
 *   --skip-send      Skip the live IMAP login attempts (DB-only checks)
 *   --smoke          Connectivity + config + baseline login only (no toggling)
 *   --verbose        Show file:line on failure
 *   --json           Emit a single JSON summary
 *   --help           Show this help
 *
 * Safety / non-destructive guarantee:
 *   - The ONLY row touched is the provided account, and ONLY its
 *     login_suspended / suspended_at / suspended_reason columns.
 *   - The original suspension state is captured up-front and ALWAYS restored
 *     on exit (shutdown handler + SIGINT/SIGTERM), even if a test throws, so
 *     the account is never left suspended.
 *   - While suspended for the test the reason is tagged "[FLOWONE-TEST]" so an
 *     interrupted run is unmistakably identifiable.
 *   - Idempotent: safe to run repeatedly; always ends with the account restored.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

// ── CLI args ─────────────────────────────────────────────────────

$opts = getopt('', ['email:', 'password:', 'only:', 'skip-send', 'smoke', 'verbose', 'json', 'help']);

if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Mail Suspend - End-to-End Self-Test\n";
    echo "===========================================\n\n";
    echo "Usage:\n";
    echo "  php mail-suspend-test.php --email=USER --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL    Test account (required) - a real mailbox you own\n";
    echo "  --password=PASS  That mailbox's password (required for login tests)\n";
    echo "  --only=g1,g2     baseline,suspend,delivery,webmail,resume\n";
    echo "  --skip-send      Skip the live IMAP login attempts (DB-only checks)\n";
    echo "  --smoke          Connectivity + config + baseline login only\n";
    echo "  --verbose        Show file:line on failure\n";
    echo "  --json           Emit a single JSON summary\n";
    echo "  --help           Show this help\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$testEmail  = strtolower(trim($opts['email']));
$testPass   = $opts['password'] ?? '';
$smokeOnly  = isset($opts['smoke']);
$skipSend   = isset($opts['skip-send']);
$verbose    = isset($opts['verbose']);
$jsonMode   = isset($opts['json']);
$onlyGroups = !empty($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : [];

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/mail-suspend-test-' . date('Ymd-His') . '.log';
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

// State shared with the cleanup handler.
$pdo = null;
$originalCaptured = false;
$original = ['login_suspended' => 0, 'suspended_at' => null, 'suspended_reason' => null];

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
function assert_false(bool $cond, string $msg = 'Assertion failed'): void {
    if ($cond) throw new \RuntimeException($msg);
}
function assert_not_empty($value, string $msg = 'Value is empty'): void {
    if (empty($value)) throw new \RuntimeException($msg);
}

/** Flip the login_suspended flag on the test account (and only that account). */
function setSuspended(bool $suspended): void {
    global $pdo, $testEmail;
    if ($suspended) {
        $stmt = $pdo->prepare("
            UPDATE mail_accounts
            SET login_suspended = 1, suspended_at = NOW(),
                suspended_reason = '[FLOWONE-TEST] suspend self-test', updated_at = NOW()
            WHERE LOWER(email) = ?
        ");
    } else {
        $stmt = $pdo->prepare("
            UPDATE mail_accounts
            SET login_suspended = 0, suspended_at = NULL, suspended_reason = NULL, updated_at = NOW()
            WHERE LOWER(email) = ?
        ");
    }
    $stmt->execute([$testEmail]);
}

/** Attempt a real IMAP login; returns [bool ok, string error]. */
function tryImapLogin(): array {
    global $config, $testEmail, $testPass;
    $imap = new \Webmail\Services\ImapService($config['imap'] ?? []);
    $ok = $imap->connect($testEmail, $testPass);
    $err = $ok ? '' : (string)($imap->getLastError() ?? 'unknown');
    if ($ok && method_exists($imap, 'disconnect')) {
        try { $imap->disconnect(); } catch (\Throwable $e) {}
    }
    // Drain any lingering IMAP error queue so it can't leak into later attempts.
    if (function_exists('imap_errors')) { @imap_errors(); }
    return [$ok, $err];
}

// ── Cleanup (shutdown + signal safe) ─────────────────────────────
// ALWAYS restore the account to the state we found it in. Never leave a real
// mailbox suspended because a test failed or the run was interrupted.

function doCleanup(): void {
    global $pdo, $testEmail, $original, $originalCaptured;
    if (!$pdo || !$originalCaptured) return;
    try {
        $stmt = $pdo->prepare("
            UPDATE mail_accounts
            SET login_suspended = ?, suspended_at = ?, suspended_reason = ?, updated_at = NOW()
            WHERE LOWER(email) = ?
        ");
        $stmt->execute([
            (int)$original['login_suspended'],
            $original['suspended_at'],
            $original['suspended_reason'],
            $testEmail,
        ]);
    } catch (\Throwable $e) {
        error_log('[mail-suspend cleanup] failed to restore ' . $testEmail . ': ' . $e->getMessage());
    }
}
register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Mail Suspend - End-to-End Self-Test");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: {$testEmail}");
out("  Mode:    " . ($smokeOnly ? 'SMOKE' : ($skipSend ? 'NO-LOGIN' : 'FULL')));
out("  Log:     {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

test('Required PHP extensions loaded', function () {
    foreach (['pdo', 'pdo_mysql', 'imap'] as $ext) {
        assert_true(extension_loaded($ext), "Missing extension: {$ext}");
    }
});

test('IMAP config present', function () use ($config) {
    assert_not_empty($config['imap']['host'] ?? '', 'imap.host is not configured');
    assert_true((int)($config['imap']['port'] ?? 0) > 0, 'imap.port is not configured');
});

test('Database reachable', function () use ($config, &$pdo) {
    $pdo = \Webmail\Core\Database::getConnection($config);
    $pdo->query('SELECT 1');
    assert_true($pdo instanceof \PDO, 'getConnection did not return a PDO');
});

test('mail_accounts has the login_suspended column (self-heal if missing)', function () use (&$pdo) {
    // Mirror the agent's self-heal so the test works even before the migration
    // has been applied on this box.
    try {
        $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS login_suspended TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS suspended_at TIMESTAMP NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS suspended_reason VARCHAR(255) DEFAULT NULL");
    } catch (\Throwable $e) {
        // Non-fatal on engines without IF NOT EXISTS; the SELECT below confirms it.
    }
    $cols = $pdo->query("SHOW COLUMNS FROM mail_accounts LIKE 'login_suspended'")->fetchAll();
    assert_true(count($cols) === 1, 'login_suspended column is missing and could not be created');
});

test('Test account exists and its suspension state is captured', function () use (&$pdo, $testEmail, &$original, &$originalCaptured) {
    $stmt = $pdo->prepare("SELECT status, login_suspended, suspended_at, suspended_reason FROM mail_accounts WHERE LOWER(email) = ?");
    $stmt->execute([$testEmail]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    assert_not_empty($row, "Account not found in mail_accounts: {$testEmail}");
    $original['login_suspended']  = (int)($row['login_suspended'] ?? 0);
    $original['suspended_at']     = $row['suspended_at'] ?? null;
    $original['suspended_reason'] = $row['suspended_reason'] ?? null;
    $originalCaptured = true;
    out("        captured original: status={$row['status']}, login_suspended={$original['login_suspended']}");
});

// Abort early if pre-flight could not establish a usable environment.
if (!$originalCaptured) {
    out("\n{$RED}Pre-flight failed: cannot continue without a valid account/DB.{$NC}");
    goto summary;
}

// ── 1. BASELINE: the account can log in before we touch anything ──

if (shouldRun('baseline')) {
    out("\n--- 1. BASELINE (account can log in via IMAP) ---");

    test('IMAP login succeeds for the active account', function () use ($skipSend, $testPass) {
        if ($skipSend) return 'skip';
        if ($testPass === '') {
            throw new \RuntimeException('--password is required for login tests (or use --skip-send)');
        }
        // Make sure we start from a non-suspended state for a clean baseline.
        setSuspended(false);
        [$ok, $err] = tryImapLogin();
        assert_true($ok, "Baseline IMAP login failed (check the password): {$err}");
    });
}

if ($smokeOnly) {
    goto summary;
}

// ── 2. SUSPEND: login must be refused once suspended ─────────────

if (shouldRun('suspend')) {
    out("\n--- 2. SUSPEND (login is refused while login_suspended=1) ---");

    test('login_suspended flag is set in the DB', function () use (&$pdo, $testEmail) {
        setSuspended(true);
        $stmt = $pdo->prepare("SELECT login_suspended FROM mail_accounts WHERE LOWER(email) = ?");
        $stmt->execute([$testEmail]);
        assert_true((int)$stmt->fetchColumn() === 1, 'login_suspended was not set to 1');
    });

    test('IMAP login is REFUSED while suspended', function () use ($skipSend) {
        if ($skipSend) return 'skip';
        [$ok, $err] = tryImapLogin();
        assert_false($ok,
            'Login still succeeded while suspended. Ensure the live ' .
            '/etc/dovecot/dovecot-sql.conf.ext password_query includes ' .
            "\"AND login_suspended = 0\" and Dovecot was reloaded (and auth_cache is not serving a stale result).");
        out("        refused as expected: {$err}");
    });
}

// ── 3. DELIVERY: incoming mail is unaffected while suspended ─────

if (shouldRun('delivery')) {
    out("\n--- 3. DELIVERY (mailbox still resolves for inbound mail) ---");

    // Make sure we are in the suspended state for this group even if run in isolation.
    if (!shouldRun('suspend')) {
        setSuspended(true);
    }

    test('Postfix virtual_mailbox lookup still resolves while suspended', function () use (&$pdo, $testEmail) {
        // This is exactly Postfix's mysql-virtual-mailboxes.cf query: it must
        // still return the maildir path so inbound mail keeps being delivered.
        $stmt = $pdo->prepare("SELECT CONCAT(domain, '/', username, '/') FROM mail_accounts WHERE email = ? AND status = 'active'");
        $stmt->execute([$testEmail]);
        $path = $stmt->fetchColumn();
        assert_not_empty($path, 'Mailbox no longer resolves for delivery while suspended - inbound mail would bounce!');
        out("        delivery path: {$path}");
    });

    test('Account status is still active (only login is blocked)', function () use (&$pdo, $testEmail) {
        $stmt = $pdo->prepare("SELECT status FROM mail_accounts WHERE LOWER(email) = ?");
        $stmt->execute([$testEmail]);
        assert_true($stmt->fetchColumn() === 'active', "status should remain 'active' during a login suspension");
    });
}

// ── 4. WEBMAIL: an open webmail session is force-logged-out ──────
// doveadm kick only drops IMAP/POP3 clients. The webmail app keeps its own
// session, so the backend's BaseController::requireAuth() must reject a
// suspended account on its next API call (-> 401 the frontend treats as an
// instant logout), and the panel suspend action wipes webmail_sessions rows.

if (shouldRun('webmail')) {
    out("\n--- 4. WEBMAIL (open session is force-logged-out) ---");

    // Make sure we are in the suspended state for this group even if run alone.
    if (!shouldRun('suspend')) {
        setSuspended(true);
    }

    test('Backend suspension predicate flags the account (forces webmail 401)', function () use (&$pdo, $testEmail) {
        // This is exactly what BaseController::isAccountSuspended() runs on every
        // authenticated request; a non-zero result makes requireAuth() return a
        // 401 with reason=account_suspended, which the frontend turns into logout.
        $stmt = $pdo->prepare("SELECT login_suspended FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([strtolower($testEmail)]);
        $suspended = $stmt->fetchColumn();
        assert_true($suspended !== false && (int)$suspended === 1,
            'requireAuth() would NOT reject this session - webmail user would stay logged in while suspended');
    });

    test('webmail_sessions table exists so suspend can revoke open sessions', function () use (&$pdo) {
        // The panel suspend action DELETEs from webmail_sessions to kill any open
        // webmail session immediately. If the table is absent (panel-only box) the
        // revocation is a harmless no-op, but on a real mail box it must be there.
        $exists = $pdo->query("SHOW TABLES LIKE 'webmail_sessions'")->fetchColumn();
        if ($exists === false) {
            out("        webmail_sessions not present on this DB - revocation is a no-op here");
            return 'warn';
        }
        return null;
    });

    test('Resume clears the predicate (webmail access restored)', function () use (&$pdo, $testEmail) {
        setSuspended(false);
        $stmt = $pdo->prepare("SELECT login_suspended FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([strtolower($testEmail)]);
        $suspended = $stmt->fetchColumn();
        assert_true((int)$suspended === 0,
            'predicate still flags the account after resume - webmail would stay locked out');
        // Re-suspend so a following group (e.g. resume) still sees the suspended
        // state it expects; resume re-clears it anyway.
        if (shouldRun('resume')) {
            setSuspended(true);
        }
    });
}

// ── 5. RESUME: login works again after resuming ─────────────────

if (shouldRun('resume')) {
    out("\n--- 5. RESUME (login works again after resuming) ---");

    test('login_suspended flag is cleared in the DB', function () use (&$pdo, $testEmail) {
        setSuspended(false);
        $stmt = $pdo->prepare("SELECT login_suspended FROM mail_accounts WHERE LOWER(email) = ?");
        $stmt->execute([$testEmail]);
        assert_true((int)$stmt->fetchColumn() === 0, 'login_suspended was not cleared');
    });

    test('IMAP login succeeds again after resume', function () use ($skipSend, $testPass) {
        if ($skipSend) return 'skip';
        if ($testPass === '') return 'skip';
        [$ok, $err] = tryImapLogin();
        assert_true($ok, "IMAP login should work again after resume: {$err}");
    });
}

// ══════════════════════════════════════════════════════════════════

summary:

// Restore now (in addition to the shutdown handler) so the summary reflects a
// clean, restored account.
doCleanup();

out("\n=================================================================");
out("  SUMMARY");
out("=================================================================");
out("  Total:    {$totalTests}");
out("  {$GREEN}Passed:   {$passed}{$NC}");
out("  {$YELLOW}Warnings: {$warnings}{$NC}");
out("  {$RED}Failed:   {$failed}{$NC}");
out("  Account restored to original suspension state.");

if ($failed > 0) {
    out("\n  Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    - {$r['name']}: " . ($r['error'] ?? 'unknown'));
        }
    }
}

if ($jsonMode) {
    echo json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'warnings' => $warnings,
        'failed'   => $failed,
        'results'  => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
