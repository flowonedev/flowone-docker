#!/usr/bin/env php
<?php
/**
 * Mailbox Quota + 2FA Reset Test Suite
 *
 * Exercises the full chain for the per-mailbox admin features end-to-end
 * through the privileged agent (the single writer of mail_accounts /
 * webmail_* and the only caller of doveadm):
 *
 *   - mailacct.setQuotas : mailbox quota_mb (0 = unlimited) + drive
 *                          quota_bytes (-1 = unlimited), with strict range
 *                          validation and a warning-only doveadm recalc.
 *   - mailacct.reset2fa  : clears webmail_2fa secret/backup codes, revokes
 *                          trusted devices and signs out webmail sessions.
 *
 * All test data uses the `flowone_test_` prefix and is removed in cleanup
 * (which also runs on SIGINT/SIGTERM). No real mailbox is ever touched and
 * no credentials are required: the suite provisions its own throwaway
 * mail_accounts row.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/mail-account-quota-2fa-test.php --verbose
 *
 * Options:
 *   --verbose      extra debug output (raw agent responses, stack traces)
 *   --skip-send    no-op for this suite (no external sends; doveadm recalc is
 *                  internal to the agent and already failure-tolerant)
 *   --only=GROUP   preflight,quota,quota-validation,quota-maildir,2fa
 *   --smoke        pre-flight only (connectivity + config), no business logic
 *   --json         machine-readable results
 *   --domain=D     domain for the throwaway test account (default flowone-test.local)
 *   --help
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help', 'domain:']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1900));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

const GB = 1073741824;

$harness = new TestHarness('Mailbox Quota + 2FA Reset', $opts);

// ─── Shared state (wired up by the preflight group) ───
$pdo = null;
$agentSocket = null;
$agentToken = null;
$haveAgent = false;
$haveDrive = false;
$have2fa = false;

$domain = isset($opts['domain']) ? (string) $opts['domain'] : 'flowone-test.local';
$testEmail = 'flowone_test_' . bin2hex(random_bytes(4)) . '@' . $domain;

/**
 * Minimal Unix-socket client for the agent (mirrors AgentService::send).
 * Returns the decoded response array, or an error envelope on failure.
 */
$agentExecute = function (string $action, array $params) use (&$agentSocket, &$agentToken): array {
    if (!$agentSocket || !file_exists($agentSocket)) {
        return ['success' => false, 'error' => 'agent socket unavailable'];
    }
    $sock = @socket_create(AF_UNIX, SOCK_STREAM, 0);
    if ($sock === false) {
        return ['success' => false, 'error' => 'socket_create failed'];
    }
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 30, 'usec' => 0]);
    socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 30, 'usec' => 0]);
    if (!@socket_connect($sock, $agentSocket)) {
        socket_close($sock);
        return ['success' => false, 'error' => 'agent connect failed'];
    }
    $req = json_encode([
        'action' => $action,
        'params' => $params,
        'actor' => 'cli:quota-2fa-test',
        'token' => $agentToken,
    ]) . "\n\n";
    socket_write($sock, $req);
    $resp = '';
    while (true) {
        $chunk = @socket_read($sock, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $resp .= $chunk;
        if (strpos($resp, "\n") !== false) {
            break;
        }
    }
    socket_close($sock);
    $decoded = json_decode(trim($resp), true);
    return is_array($decoded) ? $decoded : ['success' => false, 'error' => 'invalid agent response: ' . $resp];
};

/** Load + merge the panel config (config.php + optional config.local.php). */
$loadPanelConfig = function (): array {
    $candidates = [
        ['/var/www/vps-admin/api/config.php', '/var/www/vps-admin/api/config.local.php'],
        [__DIR__ . '/../api/config.php', __DIR__ . '/../api/config.local.php'],
    ];
    foreach ($candidates as [$main, $local]) {
        if (file_exists($main)) {
            $cfg = require $main;
            if (file_exists($local)) {
                $cfg = array_replace_recursive($cfg, require $local);
            }
            return is_array($cfg) ? $cfg : [];
        }
    }
    return [];
};

$tableExists = function (\PDO $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
};

// ─── Cleanup: idempotent, runs even on signal ───
$harness->onCleanup(function () use (&$pdo, &$testEmail, &$have2fa) {
    if (!$pdo) {
        return;
    }
    $del = function (string $sql) use ($pdo, $testEmail) {
        try {
            $pdo->prepare($sql)->execute([$testEmail]);
        } catch (\Throwable $e) {
            // table may not exist on a panel-only box; ignore
        }
    };
    $del("DELETE FROM drive_quotas WHERE user_email = ?");
    $del("DELETE FROM webmail_2fa WHERE email = ?");
    $del("DELETE FROM webmail_2fa_trusted_devices WHERE email = ?");
    $del("DELETE FROM webmail_sessions WHERE email = ?");
    $del("DELETE FROM mail_accounts WHERE email = ?");
});

// ============================================================
// PREFLIGHT
// ============================================================

$harness->test('preflight', 'Required PHP extensions (pdo_mysql, sockets, json)', function () {
    $missing = [];
    foreach (['pdo_mysql', 'sockets', 'json'] as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    if ($missing) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'missing: ' . implode(', ', $missing)];
    }
});

$harness->test('preflight', 'Panel DB reachable + mail_accounts present (+ quota_mb column)',
    function () use (&$pdo) {
        $pdo = PanelDatabase::fromDefaultConfigFiles()->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'mail_accounts'")->rowCount() === 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'mail_accounts table missing'];
        }
        // Match the agent's read-path self-heal so the suite can seed/assert
        // quota_mb on an older DB. Idempotent, no-op when the column exists.
        $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS quota_mb INT DEFAULT 512 COMMENT '0 = unlimited'");
    });

$harness->test('preflight', 'Agent socket + token available',
    function () use (&$agentSocket, &$agentToken, &$haveAgent, $loadPanelConfig) {
        $cfg = $loadPanelConfig();
        $agentSocket = $cfg['agent']['socket'] ?? '/run/vps-admin/agent.sock';
        $tokenFile = $cfg['agent']['token_file'] ?? null;
        if ($tokenFile && file_exists($tokenFile)) {
            $agentToken = trim((string) file_get_contents($tokenFile));
        }
        if (!file_exists($agentSocket)) {
            return ['outcome' => TestHarness::WARN, 'message' => "agent socket not found ({$agentSocket}); quota/2fa groups will skip"];
        }
        $haveAgent = true;
    });

$harness->test('preflight', 'drive_quotas table present',
    function () use (&$pdo, &$haveDrive, $tableExists) {
        $haveDrive = $tableExists($pdo, 'drive_quotas');
        if (!$haveDrive) {
            return ['outcome' => TestHarness::WARN, 'message' => 'drive_quotas missing (email app not installed here); drive checks skipped'];
        }
    });

$harness->test('preflight', 'webmail 2FA tables present',
    function () use (&$pdo, &$have2fa, $tableExists) {
        $have2fa = $tableExists($pdo, 'webmail_2fa')
            && $tableExists($pdo, 'webmail_2fa_trusted_devices')
            && $tableExists($pdo, 'webmail_sessions');
        if (!$have2fa) {
            return ['outcome' => TestHarness::WARN, 'message' => 'webmail_2fa* tables missing; 2fa group skipped'];
        }
    });

$harness->test('preflight', 'Seed throwaway test mailbox',
    function () use (&$pdo, &$testEmail, $domain) {
        $stmt = $pdo->prepare("
            INSERT INTO mail_accounts (email, domain, username, password_hash, maildir_path, status, quota_mb)
            VALUES (?, ?, ?, ?, ?, 'active', 512)
        ");
        $local = explode('@', $testEmail)[0];
        $stmt->execute([$testEmail, $domain, $local, 'x', "/home/vmail/{$domain}/{$local}"]);
        $check = $pdo->prepare("SELECT id FROM mail_accounts WHERE email = ?");
        $check->execute([$testEmail]);
        if (!$check->fetch()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'failed to seed test account'];
        }
    });

// ============================================================
// QUOTA
// ============================================================

$readQuotaMb = function () use (&$pdo, &$testEmail): ?int {
    $stmt = $pdo->prepare("SELECT quota_mb FROM mail_accounts WHERE email = ?");
    $stmt->execute([$testEmail]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (int) $v;
};
$readDriveBytes = function () use (&$pdo, &$testEmail): ?int {
    $stmt = $pdo->prepare("SELECT quota_bytes FROM drive_quotas WHERE user_email = ?");
    $stmt->execute([$testEmail]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (int) $v;
};

$harness->test('quota', 'Set mailbox 2048 MB (+ drive 5 GB) via agent',
    function () use (&$haveAgent, &$haveDrive, $agentExecute, $readQuotaMb, $readDriveBytes, &$testEmail, $harness) {
        if (!$haveAgent) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'agent unavailable'];
        }
        $params = ['email' => $testEmail, 'quota_mb' => 2048];
        if ($haveDrive) {
            $params['drive_quota_bytes'] = 5 * GB;
        }
        $r = $agentExecute('mailacct.setQuotas', $params);
        if (empty($r['success'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'agent error: ' . ($r['error'] ?? '?')];
        }
        if ($readQuotaMb() !== 2048) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'quota_mb not persisted as 2048'];
        }
        if ($haveDrive && $readDriveBytes() !== 5 * GB) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'drive quota_bytes not persisted'];
        }
        // A doveadm recalc warning is expected for a never-logged-in mailbox
        // and must NOT fail the action.
        if (!empty($r['data']['warnings']) && $harness->isVerbose()) {
            return ['outcome' => TestHarness::PASS, 'message' => 'warning (tolerated): ' . implode(' ', $r['data']['warnings'])];
        }
    });

$harness->test('quota', 'Set mailbox unlimited (0) via agent',
    function () use (&$haveAgent, $agentExecute, $readQuotaMb, &$testEmail) {
        if (!$haveAgent) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'agent unavailable'];
        }
        $r = $agentExecute('mailacct.setQuotas', ['email' => $testEmail, 'quota_mb' => 0]);
        if (empty($r['success'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'agent error: ' . ($r['error'] ?? '?')];
        }
        if ($readQuotaMb() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'quota_mb not set to 0 (unlimited)'];
        }
    });

$harness->test('quota', 'Set drive unlimited (-1) via agent',
    function () use (&$haveAgent, &$haveDrive, $agentExecute, $readDriveBytes, &$testEmail) {
        if (!$haveAgent) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'agent unavailable'];
        }
        if (!$haveDrive) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'drive_quotas unavailable'];
        }
        $r = $agentExecute('mailacct.setQuotas', ['email' => $testEmail, 'drive_quota_bytes' => -1]);
        if (empty($r['success'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'agent error: ' . ($r['error'] ?? '?')];
        }
        if ($readDriveBytes() !== -1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'drive quota not set to -1 (unlimited)'];
        }
    });

// ============================================================
// QUOTA VALIDATION (rejections must not mutate the DB)
// ============================================================

$expectReject = function (array $params, string $why) use (&$haveAgent, $agentExecute, &$testEmail) {
    if (!$haveAgent) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'agent unavailable'];
    }
    $params['email'] = $params['email'] ?? $testEmail;
    $r = $agentExecute('mailacct.setQuotas', $params);
    if (!empty($r['success'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => "expected rejection ({$why}) but agent accepted it"];
    }
};

$harness->test('quota-validation', 'Reject mailbox below 100 MB',
    fn() => $expectReject(['quota_mb' => 50], 'mailbox < 100MB'));
$harness->test('quota-validation', 'Reject mailbox above 1 TB',
    fn() => $expectReject(['quota_mb' => 1048577], 'mailbox > 1TB'));
$harness->test('quota-validation', 'Reject drive below 100 MB',
    fn() => $expectReject(['drive_quota_bytes' => 1000], 'drive < 100MB'));
$harness->test('quota-validation', 'Reject negative drive that is not -1',
    fn() => $expectReject(['drive_quota_bytes' => -5], 'drive negative != -1'));
$harness->test('quota-validation', 'Reject empty payload',
    fn() => $expectReject([], 'no quota fields'));
$harness->test('quota-validation', 'Reject unknown account',
    fn() => $expectReject(['email' => 'flowone_test_nope_' . bin2hex(random_bytes(3)) . '@nope.local', 'quota_mb' => 1024], 'unknown account'));

$harness->test('quota-validation', 'Rejections left quota_mb unchanged (still 0)',
    function () use (&$haveAgent, $readQuotaMb) {
        if (!$haveAgent) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'agent unavailable'];
        }
        if ($readQuotaMb() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'invalid attempts mutated quota_mb'];
        }
    });

// ============================================================
// QUOTA MAILDIR REFRESH
//
// With the maildir quota backend the LIMIT is cached in maildirsize's first
// line; `doveadm quota recalc` only recomputes usage, so the agent must drop
// maildirsize for the new quota_rule to take effect. These tests cover the
// deterministic parsing the agent uses to locate maildirsize, plus the
// failure-tolerant contract when Dovecot can't resolve the mailbox.
// ============================================================

$maildirRoot = function (string $mailField): ?string {
    // Mirror MailAccountAdminAction::maildirRootFromMailField via reflection so
    // the test exercises the real code path without a live Dovecot.
    static $method = null;
    if ($method === null) {
        $ref = new \ReflectionMethod(
            \VpsAdmin\Agent\Actions\MailAccountAdminAction::class,
            'maildirRootFromMailField'
        );
        $ref->setAccessible(true);
        $method = $ref;
    }
    return $method->invoke(null, $mailField);
};

$harness->test('quota-maildir', 'Parse plain maildir mail field to root path',
    function () use ($maildirRoot) {
        $got = $maildirRoot('maildir:/home/vmail/pixelranger.hu/miklos');
        if ($got !== '/home/vmail/pixelranger.hu/miklos') {
            return ['outcome' => TestHarness::FAIL, 'message' => "got: " . var_export($got, true)];
        }
    });

$harness->test('quota-maildir', 'Strip :LAYOUT=fs and other option suffixes',
    function () use ($maildirRoot) {
        $got = $maildirRoot('maildir:/home/vmail/example.com/user:LAYOUT=fs:UTF-8');
        if ($got !== '/home/vmail/example.com/user') {
            return ['outcome' => TestHarness::FAIL, 'message' => "got: " . var_export($got, true)];
        }
    });

$harness->test('quota-maildir', 'Trim trailing slash on the maildir root',
    function () use ($maildirRoot) {
        $got = $maildirRoot('maildir:/home/vmail/example.com/user/');
        if ($got !== '/home/vmail/example.com/user') {
            return ['outcome' => TestHarness::FAIL, 'message' => "got: " . var_export($got, true)];
        }
    });

$harness->test('quota-maildir', 'Reject unusable mail field values (null)',
    function () use ($maildirRoot) {
        foreach (['', 'maildir:', 'maildir:relative/path', 'no-colon-here'] as $bad) {
            if ($maildirRoot($bad) !== null) {
                return ['outcome' => TestHarness::FAIL, 'message' => "expected null for: " . var_export($bad, true)];
            }
        }
    });

$harness->test('quota-maildir', 'Unlimited set on a non-Dovecot test mailbox stays tolerant',
    function () use (&$haveAgent, $agentExecute, $readQuotaMb, &$testEmail) {
        if (!$haveAgent) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'agent unavailable'];
        }
        // The throwaway account has no real Dovecot mailbox, so the maildir
        // refresh (doveadm lookup + recalc) cannot succeed. The DB write must
        // still succeed and any refresh problem must surface only as a warning.
        $r = $agentExecute('mailacct.setQuotas', ['email' => $testEmail, 'quota_mb' => 0]);
        if (empty($r['success'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'refresh failure must not fail the action: ' . ($r['error'] ?? '?')];
        }
        if ($readQuotaMb() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'quota_mb not persisted as 0 (unlimited)'];
        }
    });

// ============================================================
// 2FA RESET
// ============================================================

$harness->test('2fa', 'Seed 2FA + trusted device + session',
    function () use (&$pdo, &$haveAgent, &$have2fa, &$testEmail) {
        if (!$haveAgent || !$have2fa) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'agent or 2fa tables unavailable'];
        }
        $pdo->prepare("
            INSERT INTO webmail_2fa (email, secret, enabled, backup_codes)
            VALUES (?, 'FLOWONETESTSECRET', 1, '[\"hashcode\"]')
            ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 1, backup_codes = VALUES(backup_codes)
        ")->execute([$testEmail]);
        $pdo->prepare("
            INSERT INTO webmail_2fa_trusted_devices (email, device_token_hash, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
        ")->execute([$testEmail, hash('sha256', 'flowone_test_device')]);
        $pdo->prepare("
            INSERT INTO webmail_sessions (email, session_token_hash, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))
        ")->execute([$testEmail, hash('sha256', 'flowone_test_session')]);
    });

$harness->test('2fa', 'Reset 2FA via agent clears secret/backup codes',
    function () use (&$pdo, &$haveAgent, &$have2fa, $agentExecute, &$testEmail) {
        if (!$haveAgent || !$have2fa) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'agent or 2fa tables unavailable'];
        }
        $r = $agentExecute('mailacct.reset2fa', ['email' => $testEmail]);
        if (empty($r['success'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'agent error: ' . ($r['error'] ?? '?')];
        }
        $stmt = $pdo->prepare("SELECT enabled, secret, backup_codes FROM webmail_2fa WHERE email = ?");
        $stmt->execute([$testEmail]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'webmail_2fa row vanished'];
        }
        if ((int) $row['enabled'] !== 0 || $row['secret'] !== null || $row['backup_codes'] !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => '2FA not fully cleared'];
        }
    });

$harness->test('2fa', 'Reset revoked trusted devices + sessions',
    function () use (&$pdo, &$haveAgent, &$have2fa, &$testEmail) {
        if (!$haveAgent || !$have2fa) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'agent or 2fa tables unavailable'];
        }
        $d = $pdo->prepare("SELECT COUNT(*) FROM webmail_2fa_trusted_devices WHERE email = ?");
        $d->execute([$testEmail]);
        $s = $pdo->prepare("SELECT COUNT(*) FROM webmail_sessions WHERE email = ?");
        $s->execute([$testEmail]);
        if ((int) $d->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'trusted devices not revoked'];
        }
        if ((int) $s->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'sessions not revoked'];
        }
    });

exit($harness->run());
