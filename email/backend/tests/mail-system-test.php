#!/usr/bin/env php
<?php
/**
 * mail-system-test.php — end-to-end proof of the FlowOne Docker MAIL pod.
 *
 * Runs INSIDE the `web` container (it has lsphp83 + imap + pdo_mysql + openssl and
 * the cron bootstrap). It exercises the full chain the mail pod is responsible for:
 *
 *   DB seed  -> mail_domains + mail_accounts row (bcrypt hash)   [write]
 *   IMAP     -> Dovecot SQL passdb login (BLF-CRYPT), good + bad creds
 *   SMTP     -> submission on :587 STARTTLS + SASL AUTH, good + bad creds
 *   roundtrip-> SMTP submit a [FLOWONE-TEST] message to self, retrieve via IMAP
 *   DKIM     -> the delivered message carries a DKIM-Signature (OpenDKIM milter)
 *
 * Reaches the host-networked pod via IMAP_HOST/SMTP_HOST (host.docker.internal in
 * compose) and the shared MariaDB via the mailserver schema. Seeding needs write
 * access to that schema, so pass DB admin creds (mailuser is SELECT-only):
 *
 *   docker exec flowone-web-1 /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/mail-system-test.php \
 *     --mail-domain=stg.flowone.pro --db-admin-pass=<MYSQL_ROOT_PASSWORD> --verbose
 *
 *   # connectivity-only (no writes, no send):
 *   docker exec flowone-web-1 /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/mail-system-test.php --smoke --json
 *
 * Per .cursor/rules/server-side-testing.mdc: CLI only, --help/--verbose/--skip-send/
 * --only/--smoke/--json, pre-flight checks, timestamped log, per-test timeout,
 * signal-safe cleanup, recognizable [FLOWONE-TEST] / flowone_test prefix, idempotent,
 * exit 0/1.
 *
 * Extra flags (beyond the shared runner's):
 *   --mail-domain=<d>     domain to test under (default: $MAIL_DOMAIN env)
 *   --imap-host=<h>       default: config imap.host (IMAP_HOST env)
 *   --imap-port=<n>       default: 993
 *   --smtp-host=<h>       default: config smtp.host (SMTP_HOST env)
 *   --smtp-port=<n>       default: 587
 *   --mail-db-host=<h>    default: $MAIL_DB_HOST env or 'mariadb'
 *   --mail-db-port=<n>    default: 3306
 *   --mail-db-name=<n>    default: $MAIL_DB_NAME env or 'mailserver'
 *   --db-admin-user=<u>   privileged DB user for seeding (default: root)
 *   --db-admin-pass=<p>   its password (default: $MYSQL_ROOT_PASSWORD env)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';
require_once __DIR__ . '/lib/smtp-session.php';

$runner = new FlowOneTestRunner('mail-system', $argv);
$appConfig = require __DIR__ . '/../src/config.php';

// ---- resolve config: CLI flag > env > app config ---------------------------
$opt = function (string $name, ?string $default = null) use ($runner): ?string {
    $prefix = "--{$name}=";
    foreach ($runner->extra as $a) {
        if (str_starts_with($a, $prefix)) {
            return substr($a, strlen($prefix));
        }
    }
    return $default;
};

$mailDomain = (string) $opt('mail-domain', getenv('MAIL_DOMAIN') ?: '');
$imapHost   = (string) $opt('imap-host', (string) ($appConfig['imap']['host'] ?? 'localhost'));
$imapPort   = (int) $opt('imap-port', (string) ($appConfig['imap']['port'] ?? 993));
$smtpHost   = (string) $opt('smtp-host', (string) ($appConfig['smtp']['host'] ?? 'localhost'));
$smtpPort   = (int) $opt('smtp-port', (string) ($appConfig['smtp']['port'] ?? 587));
$dbHost     = (string) $opt('mail-db-host', getenv('MAIL_DB_HOST') ?: 'mariadb');
$dbPort     = (int) $opt('mail-db-port', getenv('MAIL_DB_PORT') ?: '3306');
$dbName     = (string) $opt('mail-db-name', getenv('MAIL_DB_NAME') ?: 'mailserver');
$dbAdminUser = (string) $opt('db-admin-user', 'root');
$dbAdminPass = (string) $opt('db-admin-pass', getenv('MYSQL_ROOT_PASSWORD') ?: '');

// Fixed test identity (idempotent across runs); the per-run token disambiguates
// the delivered message so retrieval can't match a stale copy.
$localPart = 'flowone_test';
$testEmail = $localPart . '@' . $mailDomain;
$testPass  = 'FlowOne-Test-' . bin2hex(random_bytes(6));
$token     = 'FLOWONE-TEST-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$subject   = '[FLOWONE-TEST] ' . $token;

$runner->log("target: domain={$mailDomain} imap={$imapHost}:{$imapPort} smtp={$smtpHost}:{$smtpPort} db={$dbHost}:{$dbPort}/{$dbName}");

$adminPdo = function () use ($dbHost, $dbPort, $dbName, $dbAdminUser, $dbAdminPass): PDO {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    return new PDO($dsn, $dbAdminUser, $dbAdminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
};

$imapPath = function (string $folder = 'INBOX') use ($imapHost, $imapPort): string {
    // novalidate-cert: dry-run pods serve a self-signed cert on :993.
    return sprintf('{%s:%d/imap/ssl/novalidate-cert}%s', $imapHost, $imapPort, $folder);
};

$tcpProbe = function (string $host, int $port, float $timeout = 5.0): void {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        throw new \RuntimeException("cannot reach {$host}:{$port} ({$errno} {$errstr})");
    }
    fclose($fp);
};

// --- 0. PREFLIGHT -----------------------------------------------------------

$runner->section('0. PREFLIGHT');

foreach (['imap', 'pdo_mysql', 'openssl'] as $ext) {
    $runner->test("php extension loaded: {$ext}", function () use ($ext) {
        if (!extension_loaded($ext)) {
            throw new \RuntimeException("missing extension {$ext}");
        }
        return true;
    });
}

// --- 1. CONNECTIVITY --------------------------------------------------------

$runner->section('1. CONNECTIVITY');

if ($runner->shouldRunSection('1. CONNECTIVITY')) {

    $runner->test('smtp submission port reachable (587)', function () use ($tcpProbe, $smtpHost, $smtpPort) {
        $tcpProbe($smtpHost, $smtpPort, 6.0);
        return true;
    });

    $runner->test('imaps port reachable (993)', function () use ($tcpProbe, $imapHost, $imapPort) {
        $tcpProbe($imapHost, $imapPort, 6.0);
        return true;
    });

    $runner->test('smtp inbound port reachable (25)', function () use ($tcpProbe, $smtpHost) {
        // Not fatal on a dry-run box (25 is often firewalled outbound), but the
        // pod should still be listening locally. Warn rather than fail.
        try {
            $tcpProbe($smtpHost, 25, 4.0);
            return true;
        } catch (\Throwable $e) {
            return 'warn';
        }
    });

    $runner->test('mailserver DB reachable (SELECT 1)', function () use ($adminPdo, $dbAdminPass) {
        if ($dbAdminPass === '') {
            return 'warn'; // no admin creds; DB write tests will be skipped
        }
        $pdo = $adminPdo();
        if ((string) $pdo->query('SELECT 1')->fetchColumn() !== '1') {
            throw new \RuntimeException('unexpected SELECT 1 result');
        }
        return true;
    });

    $runner->test('mail schema present (mail_domains + mail_accounts)', function () use ($adminPdo, $dbAdminPass, $dbName) {
        if ($dbAdminPass === '') {
            return 'warn';
        }
        $pdo = $adminPdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?)'
        );
        $stmt->execute([$dbName, 'mail_domains', 'mail_accounts']);
        if ((int) $stmt->fetchColumn() < 2) {
            throw new \RuntimeException('mailserver schema not initialized (mariadb-init did not run?)');
        }
        return true;
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

// Whether the run needs a seeded account (any of SEED/AUTH/ROUNDTRIP/DKIM).
$needAccount = $runner->shouldRunSection('2. SEED')
    || $runner->shouldRunSection('3. AUTH')
    || $runner->shouldRunSection('4. ROUNDTRIP')
    || $runner->shouldRunSection('5. DKIM');

// --- 2. SEED (writes; registers cleanups so data never lingers) -------------

$seeded = false;
$domainPreexisting = true;

$runner->section('2. SEED');

if ($needAccount && $runner->shouldRunSection('2. SEED')) {

    $runner->test('preconditions for seeding (domain + admin creds)', function () use ($mailDomain, $dbAdminPass) {
        if ($mailDomain === '') {
            throw new \RuntimeException('no --mail-domain (or $MAIL_DOMAIN) — cannot seed a test account');
        }
        if ($dbAdminPass === '') {
            throw new \RuntimeException('no --db-admin-pass (or $MYSQL_ROOT_PASSWORD) — mailuser is SELECT-only, cannot seed');
        }
        return true;
    });

    if ($mailDomain !== '' && $dbAdminPass !== '') {
        // Register cleanups BEFORE seeding so a crash mid-seed still tidies up.
        // LIFO order: expunge messages (account alive) THEN drop the DB rows.
        $runner->addCleanup(function () use ($adminPdo, $testEmail, $mailDomain, &$domainPreexisting) {
            try {
                $pdo = $adminPdo();
                $pdo->prepare('DELETE FROM mail_accounts WHERE email = ?')->execute([$testEmail]);
                if (!$domainPreexisting) {
                    $pdo->prepare('DELETE FROM mail_domains WHERE domain = ?')->execute([$mailDomain]);
                }
            } catch (\Throwable $e) {
                // surfaced by the runner's cleanup logger
            }
        });
        $runner->addCleanup(function () use ($imapPath, $testEmail, $testPass) {
            $mbox = @imap_open($imapPath('INBOX'), $testEmail, $testPass, CL_EXPUNGE, 1);
            if ($mbox !== false) {
                $ids = @imap_search($mbox, 'SUBJECT "FLOWONE-TEST"');
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        // imap_delete wants the message-number as a string sequence.
                        @imap_delete($mbox, (string) $id);
                    }
                }
                @imap_expunge($mbox);
                @imap_close($mbox);
            }
            @imap_errors();
            @imap_alerts();
        });

        $runner->test('seed mail_domains + mail_accounts (bcrypt)', function () use (
            $adminPdo, $mailDomain, $testEmail, $localPart, $testPass, &$seeded, &$domainPreexisting
        ) {
            $pdo = $adminPdo();

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM mail_domains WHERE domain = ?');
            $stmt->execute([$mailDomain]);
            $domainPreexisting = ((int) $stmt->fetchColumn()) > 0;

            $pdo->prepare(
                "INSERT INTO mail_domains (domain, status, max_accounts, max_quota_mb)
                 VALUES (?, 'active', 100, 5120)
                 ON DUPLICATE KEY UPDATE status = 'active'"
            )->execute([$mailDomain]);

            $hash = password_hash($testPass, PASSWORD_BCRYPT);
            $maildir = $mailDomain . '/' . $localPart . '/';
            $pdo->prepare(
                "INSERT INTO mail_accounts
                    (email, domain, username, password_hash, quota_mb, maildir_path, status, login_suspended)
                 VALUES (?, ?, ?, ?, 512, ?, 'active', 0)
                 ON DUPLICATE KEY UPDATE
                    password_hash = VALUES(password_hash), status = 'active', login_suspended = 0"
            )->execute([$testEmail, $mailDomain, $localPart, $hash, $maildir]);

            $seeded = true;
            return true;
        });
    }
}

// --- 3. AUTH (IMAP + SMTP, positive + negative) -----------------------------

$runner->section('3. AUTH');

if ($needAccount && $runner->shouldRunSection('3. AUTH')) {

    $runner->test('IMAP login accepts seeded creds (Dovecot SQL / BLF-CRYPT)', function () use ($seeded, $imapPath, $testEmail, $testPass) {
        if (!$seeded) {
            throw new \RuntimeException('account was not seeded (see SEED failures)');
        }
        $mbox = @imap_open($imapPath('INBOX'), $testEmail, $testPass, 0, 1);
        if ($mbox === false) {
            $err = imap_last_error();
            @imap_errors();
            throw new \RuntimeException('imap_open failed: ' . $err);
        }
        @imap_close($mbox);
        @imap_errors();
        return true;
    });

    $runner->test('IMAP login rejects a wrong password', function () use ($seeded, $imapPath, $testEmail) {
        if (!$seeded) {
            return 'skip';
        }
        $bad = 'wrong-' . bin2hex(random_bytes(6));
        $mbox = @imap_open($imapPath('INBOX'), $testEmail, $bad, 0, 1);
        @imap_errors();
        @imap_alerts();
        if ($mbox !== false) {
            @imap_close($mbox);
            throw new \RuntimeException('IMAP accepted a wrong password!');
        }
        return true;
    });

    $runner->test('SMTP AUTH accepts seeded creds (587 STARTTLS)', function () use ($seeded, $smtpHost, $smtpPort, $testEmail, $testPass, $runner) {
        if (!$seeded) {
            throw new \RuntimeException('account was not seeded (see SEED failures)');
        }
        $s = new SmtpSession($smtpHost, $smtpPort);
        try {
            $s->ehlo();
            $s->startTls();
            $s->ehlo();
            $code = $s->authLogin($testEmail, $testPass);
        } finally {
            if ($runner->verbose) {
                $runner->log('    smtp: ' . implode(' | ', $s->transcript));
            }
            $s->quit();
        }
        if ($code !== '235') {
            throw new \RuntimeException("AUTH returned {$code}, expected 235");
        }
        return true;
    });

    $runner->test('SMTP AUTH rejects a wrong password', function () use ($seeded, $smtpHost, $smtpPort, $testEmail) {
        if (!$seeded) {
            return 'skip';
        }
        $s = new SmtpSession($smtpHost, $smtpPort);
        try {
            $s->ehlo();
            $s->startTls();
            $s->ehlo();
            $code = $s->authLogin($testEmail, 'wrong-' . bin2hex(random_bytes(6)));
        } finally {
            $s->quit();
        }
        if ($code === '235') {
            throw new \RuntimeException('SMTP accepted a wrong password!');
        }
        return true;
    });
}

// --- 4. ROUNDTRIP (submit -> deliver -> retrieve) ---------------------------

$runner->section('4. ROUNDTRIP');

if ($needAccount && $runner->shouldRunSection('4. ROUNDTRIP')) {

    $runner->test('SMTP submit a [FLOWONE-TEST] message to self', function () use ($runner, $seeded, $smtpHost, $smtpPort, $testEmail, $testPass, $subject, $token, $mailDomain) {
        if ($runner->skipSend) {
            return 'skip';
        }
        if (!$seeded) {
            throw new \RuntimeException('account was not seeded (see SEED failures)');
        }
        $msgId = '<' . $token . '@' . $mailDomain . '>';
        $raw = "From: FlowOne Test <{$testEmail}>\r\n"
            . "To: <{$testEmail}>\r\n"
            . "Subject: {$subject}\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . "Message-ID: {$msgId}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . "Automated FlowOne mail-pod roundtrip probe.\r\n"
            . "Token: {$token}\r\n";

        $s = new SmtpSession($smtpHost, $smtpPort);
        try {
            $s->ehlo();
            $s->startTls();
            $s->ehlo();
            if ($s->authLogin($testEmail, $testPass) !== '235') {
                throw new \RuntimeException('AUTH failed before send');
            }
            $s->sendMessage($testEmail, $testEmail, $raw);
        } finally {
            if ($runner->verbose) {
                $runner->log('    smtp: ' . implode(' | ', $s->transcript));
            }
            $s->quit();
        }
        return true;
    });

    $runner->test('IMAP retrieves the delivered message (<=60s)', function () use ($runner, $seeded, $imapPath, $testEmail, $testPass, $token) {
        if ($runner->skipSend) {
            return 'skip';
        }
        if (!$seeded) {
            throw new \RuntimeException('account was not seeded (see SEED failures)');
        }
        $deadline = time() + 60;
        $found = false;
        do {
            $mbox = @imap_open($imapPath('INBOX'), $testEmail, $testPass, 0, 1);
            if ($mbox !== false) {
                $ids = @imap_search($mbox, 'SUBJECT "' . $token . '"');
                @imap_close($mbox);
                if (!empty($ids)) {
                    $found = true;
                    break;
                }
            }
            @imap_errors();
            if (time() < $deadline) {
                sleep(3);
            }
        } while (time() < $deadline);

        if (!$found) {
            throw new \RuntimeException('message not delivered/retrievable within 60s (LDA or virtual-mailbox map issue?)');
        }
        return true;
    }, 75);
}

// --- 5. DKIM (OpenDKIM milter signed the outbound copy) ---------------------

$runner->section('5. DKIM');

if ($needAccount && $runner->shouldRunSection('5. DKIM')) {

    $runner->test('delivered message carries a DKIM-Signature', function () use ($runner, $seeded, $imapPath, $testEmail, $testPass, $token, $mailDomain) {
        if ($runner->skipSend) {
            return 'skip';
        }
        if (!$seeded) {
            throw new \RuntimeException('account was not seeded (see SEED failures)');
        }
        $mbox = @imap_open($imapPath('INBOX'), $testEmail, $testPass, 0, 1);
        if ($mbox === false) {
            throw new \RuntimeException('imap_open failed: ' . imap_last_error());
        }
        $ids = @imap_search($mbox, 'SUBJECT "' . $token . '"');
        if (empty($ids)) {
            @imap_close($mbox);
            throw new \RuntimeException('test message not found for DKIM inspection');
        }
        $header = (string) @imap_fetchheader($mbox, $ids[0]);
        @imap_close($mbox);
        @imap_errors();

        if (stripos($header, 'DKIM-Signature:') === false) {
            throw new \RuntimeException('no DKIM-Signature header (OpenDKIM milter not signing?)');
        }
        // Signed, but by a different domain than expected -> warn (still a pass path).
        if (stripos($header, 'd=' . $mailDomain) === false) {
            return 'warn';
        }
        return true;
    });
}

exit($runner->finish());
