#!/usr/bin/env php
<?php
/**
 * FlowOne Email System - Comprehensive Test Suite
 * 
 * Tests every layer: DB, Redis, IMAP, SMTP, attachments, scheduling, drive.
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-system-test.php \
 *       --email=user@flowone.pro --password=PASS
 * 
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --password=PASS      Test account password (required)
 *   --to=EMAIL           Recipient for send tests (defaults to same as --email)
 *   --skip-send          Skip actual SMTP send tests
 *   --verbose            Show extra debug info
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'password:', 'to:', 'skip-send', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email']) || empty($opts['password'])) {
    echo "FlowOne Email System Test Suite\n";
    echo "================================\n\n";
    echo "Usage:\n";
    echo "  php email-system-test.php --email=user@flowone.pro --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --password=PASS      Test account password (required)\n";
    echo "  --to=EMAIL           Recipient for send tests (defaults to --email)\n";
    echo "  --skip-send          Skip actual SMTP send tests\n";
    echo "  --verbose            Show extra debug info\n";
    echo "  --help               Show this help\n\n";
    echo "Example:\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-system-test.php \\\n";
    echo "      --email=admin@flowone.pro --password='secret' --verbose\n";
    exit(1);
}

$testEmail    = $opts['email'];
$testPassword = $opts['password'];
$testTo       = $opts['to'] ?? $testEmail;
$skipSend     = isset($opts['skip-send']);
$verbose      = isset($opts['verbose']);

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/email-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function out(string $msg): void {
    global $logFile;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    try {
        $result = $fn();
        $elapsed = round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  [WARN]  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  [PASS]  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = round((microtime(true) - $start) * 1000);
        $failed++;
        out("  [FAIL]  {$name} ({$elapsed}ms)");
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

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Email System Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account:   {$testEmail}");
out("  Recipient: {$testTo}");
out("  Send tests: " . ($skipSend ? 'SKIPPED' : 'ENABLED'));
out("  Log: {$logFile}");
out("=================================================================\n");

// ── 1. Database ──────────────────────────────────────────────────

out("--- 1. DATABASE ---");

$db = null;

test('Database connection', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db instanceof \PDO, 'Not a PDO instance');
    $row = $db->query("SELECT 1 AS ok")->fetch();
    assert_true($row['ok'] == 1, 'SELECT 1 failed');
});

test('scheduled_emails table exists', function () use (&$db) {
    if (!$db) throw new \RuntimeException('No DB connection');
    $stmt = $db->query("SHOW TABLES LIKE 'scheduled_emails'");
    assert_true($stmt->rowCount() > 0, 'Table scheduled_emails not found');
});

test('scheduled_emails table schema', function () use (&$db) {
    if (!$db) throw new \RuntimeException('No DB connection');
    $cols = $db->query("DESCRIBE scheduled_emails")->fetchAll(\PDO::FETCH_COLUMN);
    $required = ['id', 'schedule_id', 'user_email', 'email_payload', 'scheduled_at', 'status', 'schedule_kind'];
    foreach ($required as $col) {
        assert_true(in_array($col, $cols), "Missing column: {$col}");
    }
});

test('MySQL session timezone is UTC-settable', function () use ($config) {
    $db2 = \Webmail\Core\Database::getConnection($config);
    $db2->exec("SET time_zone = '+00:00'");
    $tz = $db2->query("SELECT @@session.time_zone AS tz")->fetch();
    assert_true($tz['tz'] === '+00:00', "Expected +00:00, got {$tz['tz']}");
});

// ── 2. Redis ─────────────────────────────────────────────────────

out("\n--- 2. REDIS ---");

test('Redis connection + PING', function () use ($config) {
    $redis = new \Redis();
    $host = $config['redis']['host'] ?? '127.0.0.1';
    $port = $config['redis']['port'] ?? 6379;
    $connected = @$redis->connect($host, $port, 2.0);
    assert_true($connected, "Cannot connect to Redis at {$host}:{$port}");
    $pass = $config['redis']['password'] ?? null;
    if ($pass) $redis->auth($pass);
    $pong = $redis->ping();
    assert_true($pong === true || $pong === '+PONG', 'Redis PING failed');
    $redis->close();
});

// ── 3. IMAP ──────────────────────────────────────────────────────

out("\n--- 3. IMAP ---");

$imap = null;

test('IMAP connection', function () use ($config, $testEmail, $testPassword, &$imap) {
    $imap = new \Webmail\Services\ImapService($config['imap'] ?? []);
    $ok = $imap->connect($testEmail, $testPassword);
    assert_true($ok, 'IMAP connect failed for ' . $testEmail . '. Check password and Dovecot.');
});

test('IMAP list folders', function () use (&$imap) {
    if (!$imap) throw new \RuntimeException('No IMAP connection');
    $folders = $imap->listFolders();
    assert_true(is_array($folders) && count($folders) > 0, 'No folders returned');
    $names = array_map(fn($f) => is_array($f) ? ($f['name'] ?? '') : (string)$f, $folders);
    assert_true(in_array('INBOX', $names), 'INBOX not found in folder list');
});

$sentFolder = 'Sent';

test('IMAP find Sent folder', function () use (&$imap, &$sentFolder) {
    if (!$imap) throw new \RuntimeException('No IMAP connection');
    $folders = $imap->listFolders();
    foreach ($folders as $f) {
        $type = is_array($f) ? ($f['type'] ?? '') : '';
        if ($type === 'sent') {
            $sentFolder = is_array($f) ? $f['name'] : (string)$f;
            return;
        }
        $name = is_array($f) ? ($f['name'] ?? '') : (string)$f;
        if (in_array(strtolower($name), ['sent', 'sent items', 'sent messages'])) {
            $sentFolder = $name;
            return;
        }
    }
    return 'warn';
});

test('IMAP fetch INBOX messages (page 1)', function () use (&$imap) {
    if (!$imap) throw new \RuntimeException('No IMAP connection');
    $result = $imap->getMessages('INBOX', 1, 10);
    assert_true(is_array($result), 'getMessages did not return array');
    assert_true(array_key_exists('messages', $result), 'No messages key in result');
});

test('IMAP getMessagesSince (incremental)', function () use (&$imap) {
    if (!$imap) throw new \RuntimeException('No IMAP connection');
    $result = $imap->getMessagesSince('INBOX', 1, 5);
    assert_true(is_array($result), 'getMessagesSince did not return array');
    assert_true(isset($result['uidnext']), 'Missing uidnext in response');
    assert_true(isset($result['uidvalidity']), 'Missing uidvalidity in response');
});

test('IMAP folder sync state (CONDSTORE)', function () use (&$imap) {
    if (!$imap) throw new \RuntimeException('No IMAP connection');
    $state = $imap->getFolderSyncState();
    assert_true(is_array($state), 'getFolderSyncState not array');
    assert_true(isset($state['uidvalidity']), 'No uidvalidity');
    assert_true(isset($state['uidnext']), 'No uidnext');
    assert_true($state['uidvalidity'] > 0, 'uidvalidity is zero');
});

// ── 4. SMTP ──────────────────────────────────────────────────────

out("\n--- 4. SMTP ---");

$smtp = null;

test('SmtpService instantiation + credentials', function () use ($config, $testEmail, $testPassword, &$smtp) {
    $smtp = new \Webmail\Services\SmtpService($config['smtp']);
    $smtp->setCredentials($testEmail, $testPassword);
});

if (!$skipSend) {
    $testSubject = '[FLOWONE-TEST] Email System Test - ' . date('Y-m-d H:i:s');

    test('Send plain text email', function () use (&$smtp, $testTo, $testSubject) {
        if (!$smtp) throw new \RuntimeException('No SMTP');
        $result = $smtp->send([
            'to'        => [['email' => $testTo, 'name' => 'Test Recipient']],
            'subject'   => $testSubject . ' (plain)',
            'body_text' => "Plain text test email from FlowOne test suite.\nTimestamp: " . date('c'),
        ]);
        assert_true($result['success'], 'SMTP send failed: ' . ($result['error'] ?? 'unknown'));
        assert_not_empty($result['message_id'], 'No Message-ID returned');
    });

    test('Send HTML email', function () use (&$smtp, $testTo, $testSubject) {
        if (!$smtp) throw new \RuntimeException('No SMTP');
        $html = '<html><body>'
              . '<h1>FlowOne Test</h1>'
              . '<p>HTML email test at <strong>' . date('c') . '</strong></p>'
              . '<p>Special chars: Arvizturotukorfurogep</p>'
              . '</body></html>';
        $result = $smtp->send([
            'to'        => [['email' => $testTo]],
            'subject'   => $testSubject . ' (HTML)',
            'body_html' => $html,
        ]);
        assert_true($result['success'], 'HTML send failed: ' . ($result['error'] ?? 'unknown'));
    });

    test('Send with file attachment', function () use (&$smtp, $testTo, $testSubject) {
        if (!$smtp) throw new \RuntimeException('No SMTP');
        $tmpFile = tempnam(sys_get_temp_dir(), 'flowone_test_');
        file_put_contents($tmpFile, "FlowOne test attachment content.\nGenerated: " . date('c'));
        try {
            $result = $smtp->send([
                'to'          => [['email' => $testTo]],
                'subject'     => $testSubject . ' (attachment)',
                'body_html'   => '<p>This email has a test attachment.</p>',
                'attachments' => [
                    ['path' => $tmpFile, 'name' => 'test-document.txt', 'type' => 'text/plain'],
                ],
            ]);
            assert_true($result['success'], 'Attachment send failed: ' . ($result['error'] ?? 'unknown'));
            assert_not_empty($result['raw_message'], 'No raw message for Sent folder');
        } finally {
            @unlink($tmpFile);
        }
    });

    test('Send with inline base64 attachment', function () use (&$smtp, $testTo, $testSubject) {
        if (!$smtp) throw new \RuntimeException('No SMTP');
        $content = "Inline content test - " . date('c');
        $result = $smtp->send([
            'to'          => [['email' => $testTo]],
            'subject'     => $testSubject . ' (inline-attach)',
            'body_html'   => '<p>Inline attachment test.</p>',
            'attachments' => [
                ['content' => $content, 'name' => 'inline-test.txt', 'type' => 'text/plain'],
            ],
        ]);
        assert_true($result['success'], 'Inline attachment send failed: ' . ($result['error'] ?? 'unknown'));
    });

    test('Send with reply headers (threading)', function () use (&$smtp, $testTo, $testSubject) {
        if (!$smtp) throw new \RuntimeException('No SMTP');
        $fakeParent = '<fake-parent-' . bin2hex(random_bytes(8)) . '@flowone.pro>';
        $result = $smtp->send([
            'to'          => [['email' => $testTo]],
            'subject'     => 'Re: ' . $testSubject,
            'body_html'   => '<p>Reply threading test.</p>',
            'in_reply_to' => $fakeParent,
            'references'  => $fakeParent,
        ]);
        assert_true($result['success'], 'Reply send failed: ' . ($result['error'] ?? 'unknown'));
    });

    test('Save to Sent folder via IMAP', function () use (&$smtp, &$imap, &$sentFolder, $testTo, $testSubject) {
        if (!$smtp || !$imap) throw new \RuntimeException('No SMTP or IMAP');
        $raw = $smtp->buildDraftMessage([
            'to'        => [['email' => $testTo]],
            'subject'   => $testSubject . ' (sent-folder-test)',
            'body_html' => '<p>This message tests saving to the Sent folder.</p>',
        ]);
        assert_not_empty($raw, 'buildDraftMessage returned empty');
        $saved = $imap->saveToSent($raw, $sentFolder);
        assert_true($saved, "imap_append to '{$sentFolder}' failed");
    });

    test('Multi-recipient unique Message-IDs (de-dup safe)', function () use (&$smtp, $testTo, $testSubject) {
        if (!$smtp) throw new \RuntimeException('No SMTP');
        $msgId1 = '<test-multi-1-' . bin2hex(random_bytes(8)) . '@flowone.pro>';
        $msgId2 = '<test-multi-2-' . bin2hex(random_bytes(8)) . '@flowone.pro>';
        $result1 = $smtp->send([
            'to'         => [['email' => $testTo]],
            'subject'    => $testSubject . ' (multi-1)',
            'body_html'  => '<p>Multi-recipient test: copy 1.</p>',
            'message_id' => $msgId1,
        ]);
        $result2 = $smtp->send([
            'to'         => [['email' => $testTo]],
            'subject'    => $testSubject . ' (multi-2)',
            'body_html'  => '<p>Multi-recipient test: copy 2.</p>',
            'message_id' => $msgId2,
        ]);
        assert_true($result1['success'] && $result2['success'], 'Multi-recipient send failed');
        assert_true($result1['message_id'] !== $result2['message_id'], 'Message-IDs must be unique per send');
    });
} else {
    out("  (SMTP send tests skipped with --skip-send)");
}

// ── 5. Attachments ───────────────────────────────────────────────

out("\n--- 5. ATTACHMENTS ---");

test('Temp upload directory exists and writable', function () use ($config) {
    $dir = $config['upload']['temp_dir'] ?? '/tmp/webmail_attachments';
    if (!is_dir($dir)) {
        assert_true(@mkdir($dir, 0755, true), "Cannot create temp dir: {$dir}");
    }
    assert_true(is_writable($dir), "Temp dir not writable: {$dir}");
});

test('Scheduled attachments directory writable', function () {
    $dir = dirname(__DIR__) . '/storage/scheduled-attachments';
    if (!is_dir($dir)) {
        assert_true(@mkdir($dir, 0755, true), "Cannot create scheduled-attachments dir");
    }
    assert_true(is_writable($dir), "scheduled-attachments dir not writable");
    $testFile = $dir . '/test_' . uniqid() . '.tmp';
    file_put_contents($testFile, 'test');
    assert_true(file_exists($testFile), 'Cannot write to scheduled-attachments');
    @unlink($testFile);
});

test('Max attachment size configured (>= 10MB)', function () use ($config) {
    $max = $config['upload']['max_size'] ?? 0;
    assert_true($max > 0, 'max_size not configured');
    assert_true($max >= 10 * 1024 * 1024, "max_size too small: " . number_format($max) . " bytes");
    out("          Configured max: " . round($max / 1024 / 1024) . " MB");
});

// ── 6. Scheduled Emails ─────────────────────────────────────────

out("\n--- 6. SCHEDULED EMAILS ---");

$scheduledService = null;

test('ScheduledEmailService instantiation', function () use ($config, &$scheduledService) {
    $scheduledService = new \Webmail\Services\ScheduledEmailService($config);
});

$testScheduleId = null;

test('Schedule email for future time', function () use (&$scheduledService, $testEmail, &$testScheduleId) {
    if (!$scheduledService) throw new \RuntimeException('No service');
    $futureTime = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $result = $scheduledService->schedule(
        $testEmail,
        [
            'to'                   => [['email' => 'test-scheduled@example.com']],
            'subject'              => '[FLOWONE-TEST] Scheduled Email Test',
            'body_html'            => '<p>Test scheduled email</p>',
            '_encrypted_password'  => 'test_encrypted_placeholder',
        ],
        $futureTime,
        'UTC',
        'scheduled_send'
    );
    assert_true($result['success'], 'Schedule failed: ' . ($result['error'] ?? 'unknown'));
    assert_not_empty($result['schedule_id'], 'No schedule_id returned');
    $testScheduleId = $result['schedule_id'];
});

test('Retrieve scheduled email by ID', function () use (&$scheduledService, $testEmail, $testScheduleId) {
    if (!$scheduledService || !$testScheduleId) throw new \RuntimeException('No service or ID');
    $row = $scheduledService->getScheduledById($testScheduleId, $testEmail);
    assert_true($row !== null, 'Scheduled email not found');
    assert_true($row['status'] === 'pending', "Expected pending, got {$row['status']}");
    assert_true(!empty($row['email_payload']), 'Payload is empty');
});

test('List scheduled emails for user', function () use (&$scheduledService, $testEmail) {
    if (!$scheduledService) throw new \RuntimeException('No service');
    $list = $scheduledService->getScheduled($testEmail);
    assert_true(is_array($list), 'getScheduled did not return array');
});

test('Cancel scheduled email', function () use (&$scheduledService, $testEmail, $testScheduleId) {
    if (!$scheduledService || !$testScheduleId) throw new \RuntimeException('No service or ID');
    $result = $scheduledService->cancel($testScheduleId, $testEmail);
    assert_true($result['success'], 'Cancel failed: ' . ($result['error'] ?? 'unknown'));
});

test('Undo-send schedule + cancel flow', function () use (&$scheduledService, $testEmail) {
    if (!$scheduledService) throw new \RuntimeException('No service');
    $undoTime = date('Y-m-d H:i:s', strtotime('+30 seconds'));
    $result = $scheduledService->schedule($testEmail, [
        'to'                  => [['email' => 'test-undo@example.com']],
        'subject'             => '[FLOWONE-TEST] Undo Send Test',
        'body_html'           => '<p>Undo send test</p>',
        '_encrypted_password' => 'test_encrypted_placeholder',
    ], $undoTime, 'UTC', 'undo_send');
    assert_true($result['success'], 'Undo-send schedule failed');

    $cancelResult = $scheduledService->cancel($result['schedule_id'], $testEmail, true);
    assert_true($cancelResult['success'], 'Undo-send cancel failed');
});

test('Future emails NOT returned by getDueEmails', function () use (&$scheduledService, $testEmail) {
    if (!$scheduledService) throw new \RuntimeException('No service');
    $futureTime = date('Y-m-d H:i:s', strtotime('+2 hours'));
    $res = $scheduledService->schedule($testEmail, [
        'to'                  => [['email' => 'future@example.com']],
        'subject'             => '[FLOWONE-TEST] Future Email - Not Due',
        'body_html'           => '<p>This should not be due yet</p>',
        '_encrypted_password' => 'placeholder',
    ], $futureTime, 'UTC', 'scheduled_send');
    assert_true($res['success'], 'Could not schedule future email');

    $due = $scheduledService->getDueEmails(100);
    $found = false;
    foreach ($due as $d) {
        if ($d['schedule_id'] === $res['schedule_id']) {
            $found = true;
            break;
        }
    }
    assert_true(!$found, 'Future email appeared in getDueEmails -- timezone or scheduling bug!');
});

test('Stuck email recovery runs cleanly', function () use (&$scheduledService) {
    if (!$scheduledService) throw new \RuntimeException('No service');
    $recovered = $scheduledService->recoverStuckEmails();
    assert_true($recovered >= 0, 'recoverStuckEmails returned negative');
    if ($recovered > 0) {
        out("          Recovered {$recovered} stuck email(s)");
    }
});

// ── 7. Cron Configuration ────────────────────────────────────────

out("\n--- 7. CRON ---");

test('Cron script exists', function () {
    $path = dirname(__DIR__) . '/cron/process-scheduled-emails.php';
    assert_true(file_exists($path), 'process-scheduled-emails.php not found');
});

test('Cron bootstrap loads', function () {
    $path = dirname(__DIR__) . '/cron/bootstrap.php';
    assert_true(file_exists($path), 'bootstrap.php not found');
});

test('Cron log directory writable', function () {
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    assert_true(is_writable($dir), 'Log directory not writable');
});

test('Crontab has process-scheduled-emails entry', function () {
    $crontab = @shell_exec('crontab -l 2>/dev/null');
    if (empty($crontab)) {
        throw new \RuntimeException(
            'Cannot read crontab or it is empty. Required entry: '
            . '* * * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/process-scheduled-emails.php'
        );
    }
    if (stripos($crontab, 'process-scheduled-emails') === false) {
        throw new \RuntimeException(
            'process-scheduled-emails.php not in crontab! Add: '
            . '* * * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/process-scheduled-emails.php'
        );
    }
});

// ── 8. Drive Integration ─────────────────────────────────────────

out("\n--- 8. DRIVE ---");

test('Drive storage path exists and writable', function () use ($config) {
    $path = $config['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive';
    assert_true(is_dir($path), "Drive storage not found: {$path}");
    assert_true(is_writable($path), "Drive storage not writable: {$path}");
});

// ── 9. Encryption ────────────────────────────────────────────────

out("\n--- 9. ENCRYPTION ---");

test('IMAP encryption key is set', function () use ($config) {
    $key = $config['imap_encryption_key'] ?? '';
    if (empty($key)) {
        throw new \RuntimeException(
            'IMAP_ENCRYPTION_KEY env var is empty. Scheduled emails will fail to decrypt credentials!'
        );
    }
});

test('SessionService encrypt/decrypt roundtrip', function () use ($config) {
    $svc = new \Webmail\Services\SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
    $original = 'test_password_' . bin2hex(random_bytes(8));
    $encrypted = $svc->encryptPassword($original);
    assert_not_empty($encrypted, 'Encryption returned empty');
    $decrypted = $svc->decryptPassword($encrypted);
    assert_true($decrypted === $original, "Roundtrip failed: expected '{$original}', got '{$decrypted}'");
});

// ── 10. MIME / SmtpService Internals ─────────────────────────────

out("\n--- 10. SMTP SERVICE INTERNALS ---");

test('buildDraftMessage produces valid MIME', function () use (&$smtp) {
    if (!$smtp) throw new \RuntimeException('No SMTP');
    $raw = $smtp->buildDraftMessage([
        'to'        => [['email' => 'test@example.com', 'name' => 'Test']],
        'subject'   => 'MIME Validation Test',
        'body_html' => '<p>Hello <strong>world</strong></p>',
    ]);
    assert_not_empty($raw, 'buildDraftMessage empty');
    assert_true(stripos($raw, 'Content-Type:') !== false, 'No Content-Type header');
    assert_true(stripos($raw, 'MIME Validation Test') !== false, 'Subject missing from MIME');
});

test('buildDraftMessage with attachment produces multipart', function () use (&$smtp) {
    if (!$smtp) throw new \RuntimeException('No SMTP');
    $raw = $smtp->buildDraftMessage([
        'to'          => [['email' => 'test@example.com']],
        'subject'     => 'Attachment MIME Test',
        'body_html'   => '<p>With attachment</p>',
        'attachments' => [
            ['content' => 'file content here', 'name' => 'test.txt', 'type' => 'text/plain'],
        ],
    ]);
    assert_true(stripos($raw, 'test.txt') !== false, 'Attachment filename not in MIME');
    assert_true(stripos($raw, 'multipart') !== false, 'Not a multipart message');
});

test('Hungarian character encoding (UTF-8)', function () use (&$smtp) {
    if (!$smtp) throw new \RuntimeException('No SMTP');
    $raw = $smtp->buildDraftMessage([
        'to'        => [['email' => 'test@example.com']],
        'subject'   => 'Magyar teszt',
        'body_html' => '<p>Arvizturotukorfurogep</p>',
    ]);
    assert_true(stripos($raw, 'UTF-8') !== false, 'UTF-8 charset not set');
});

// ── 11. End-to-End Scheduled Send Simulation ─────────────────────

out("\n--- 11. E2E SCHEDULED SEND SIMULATION ---");

if (!$skipSend) {
    test('Full cycle: schedule -> getDue -> decrypt -> send -> markSent', function () use ($config, $testEmail, $testPassword, $testTo) {
        $svc = new \Webmail\Services\ScheduledEmailService($config);
        $sessionSvc = new \Webmail\Services\SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');

        $encPassword = $sessionSvc->encryptPassword($testPassword);
        $pastTime = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $result = $svc->schedule($testEmail, [
            'to'                  => [['email' => $testTo, 'name' => 'E2E Test']],
            'subject'             => '[FLOWONE-E2E] Scheduled Send ' . date('H:i:s'),
            'body_html'           => '<p>This email was scheduled in the past to test immediate cron pickup.</p>',
            'body_text'           => 'Scheduled send E2E test.',
            '_encrypted_password' => $encPassword,
        ], $pastTime, 'UTC', 'scheduled_send');
        assert_true($result['success'], 'Schedule failed');

        $due = $svc->getDueEmails(50);
        $found = null;
        foreach ($due as $d) {
            if ($d['schedule_id'] === $result['schedule_id']) {
                $found = $d;
                break;
            }
        }
        assert_true($found !== null, 'Scheduled email not found in getDueEmails');

        assert_true($svc->markSending($found['id']), 'markSending failed (race condition?)');

        $payload = json_decode($found['email_payload'], true);
        $decPassword = $sessionSvc->decryptPassword($payload['_encrypted_password']);
        assert_true($decPassword === $testPassword, 'Credential roundtrip failed in scheduled payload');

        $smtp = new \Webmail\Services\SmtpService($config['smtp']);
        $smtp->setCredentials($testEmail, $decPassword);

        $sendResult = $smtp->send([
            'to'        => $payload['to'],
            'subject'   => $payload['subject'],
            'body_html' => $payload['body_html'],
            'body_text' => $payload['body_text'] ?? '',
        ]);
        assert_true($sendResult['success'], 'SMTP send failed: ' . ($sendResult['error'] ?? ''));

        $svc->markSent($found['id']);
        out("          Full E2E cycle completed successfully");
    });
} else {
    out("  (E2E scheduled send skipped with --skip-send)");
}

// ── 12. Cleanup Test Data ────────────────────────────────────────

out("\n--- 12. CLEANUP ---");

test('Remove test scheduled emails from DB', function () use (&$db, $testEmail) {
    if (!$db) throw new \RuntimeException('No DB');
    $stmt = $db->prepare(
        "DELETE FROM scheduled_emails WHERE user_email = ? AND (email_payload LIKE '%[FLOWONE-TEST]%' OR email_payload LIKE '%[FLOWONE-E2E]%')"
    );
    $stmt->execute([$testEmail]);
    $deleted = $stmt->rowCount();
    out("          Cleaned up {$deleted} test row(s)");
});

// ══════════════════════════════════════════════════════════════════
// 13. LINKED-ACCOUNT BATCH SYNC (F6)
// ══════════════════════════════════════════════════════════════════

out("\n--- 13. LINKED-ACCOUNT BATCH SYNC (F6) ---");

test('AccountController::triggerSyncAll exists', function () {
    $rc = new \ReflectionClass('\\Webmail\\Controllers\\AccountController');
    assert_true($rc->hasMethod('triggerSyncAll'), 'triggerSyncAll missing');
});

test('Route POST /accounts/sync/trigger-all registered', function () {
    $routes = file_get_contents(__DIR__ . '/../routes.php');
    assert_true(str_contains($routes, '/accounts/sync/trigger-all'), 'trigger-all route missing');
});

// ══════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════

out("\n=================================================================");
if ($failed === 0) {
    out("  ALL PASSED: {$passed} passed, {$warnings} warnings / {$totalTests} total");
} else {
    out("  RESULT: {$passed} passed, {$failed} FAILED, {$warnings} warnings / {$totalTests} total");
}
out("  Log: {$logFile}");

if ($failed > 0) {
    out("\n  FAILED TESTS:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    x {$r['name']}");
            out("      {$r['error']}");
        }
    }
}

if ($warnings > 0) {
    out("\n  WARNINGS:");
    foreach ($results as $r) {
        if ($r['status'] === 'WARN') {
            out("    ~ {$r['name']}");
        }
    }
}

out("=================================================================");
exit($failed > 0 ? 1 : 0);
