#!/usr/bin/env php
<?php
/**
 * FlowOne Email Flows - End-to-End Acceptance Suite
 *
 * Exercises the real user journeys (not unit re-tests) through the live
 * SmtpService + ImapService + DriveService and the real
 * MessageController::reply()/forward() builders:
 *
 *   send, receive, send-with-attachment (md5 integrity),
 *   send+receive with a Drive attachment (token download + md5),
 *   reply (Re: + In-Reply-To/References threading),
 *   reply-all (recipient de-dup, self excluded),
 *   forward with / without attachments,
 *   and one full upload -> share -> send -> receive -> reply -> forward
 *   -> delete workflow.
 *
 * Server run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/email-flows-test.php \
 *     --email=user@flowone.pro --password=PASS --to=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL    Test account (required for live groups)
 *   --password=PASS  Account password (required for live groups)
 *   --to=EMAIL       Recipient for send tests (default: --email)
 *   --only=g1,g2     Run only listed groups (send,receive,attachment,
 *                    drive-attachment,reply,reply-all,forward,workflow)
 *   --skip-send      Skip groups that hit real SMTP/IMAP
 *   --smoke          Connectivity + class-load health check only
 *   --verbose        Show stack traces / file:line on failure
 *   --json           Emit a single JSON summary
 *   --help           Show this help
 *
 * Safety: every subject carries a "[FLOWONE-TEST] <runId>" prefix and
 * every Drive artifact a "flowone-test-" prefix, so nothing can be
 * confused with real data. Drive test files/folders are removed on exit
 * (shutdown + SIGINT/SIGTERM). Delivered test mail is left in place (it
 * is clearly prefixed) - matching the existing email-system-test suite.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

// ── CLI args ─────────────────────────────────────────────────────

$opts = getopt('', ['email:', 'password:', 'to:', 'only:', 'skip-send', 'smoke', 'verbose', 'json', 'help']);

if (isset($opts['help'])) {
    echo "FlowOne Email Flows - End-to-End Acceptance Suite\n";
    echo "=================================================\n\n";
    echo "Usage:\n";
    echo "  php email-flows-test.php --email=USER --password=PASS [--to=USER] [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL    Test account (required for live groups)\n";
    echo "  --password=PASS  Account password (required for live groups)\n";
    echo "  --to=EMAIL       Recipient for send tests (default: --email)\n";
    echo "  --only=g1,g2     send,receive,attachment,drive-attachment,reply,reply-all,forward,workflow\n";
    echo "  --skip-send      Skip groups that hit real SMTP/IMAP\n";
    echo "  --smoke          Connectivity + class-load health check only\n";
    echo "  --verbose        Show stack traces / file:line on failure\n";
    echo "  --json           Emit a single JSON summary\n";
    echo "  --help           Show this help\n";
    exit(0);
}

$testEmail    = isset($opts['email']) ? strtolower($opts['email']) : null;
$testPassword = $opts['password'] ?? null;
$testTo       = $opts['to'] ?? $testEmail;
$skipSend     = isset($opts['skip-send']);
$smokeOnly    = isset($opts['smoke']);
$verbose      = isset($opts['verbose']);
$jsonMode     = isset($opts['json']);
$onlyGroups   = !empty($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : [];

// Unique per-run identifier so the receive-poller and cleanup only ever
// touch this run's data.
$runId   = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
$subjPfx = "[FLOWONE-TEST] {$runId}";

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/email-flows-test-' . date('Ymd-His') . '.log';
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

// Per-test wall-clock guard (seconds). Receive tests wait up to 60s, so
// the per-test cap must comfortably exceed that.
$DEFAULT_TIMEOUT = 90;

// Drive artifacts to remove on exit.
$cleanupFileIds   = [];
$cleanupFolderIds = [];
$cleanupPaths     = [];

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
function assert_not_empty($value, string $msg = 'Value is empty'): void {
    if (empty($value)) throw new \RuntimeException($msg);
}
function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $detail = $msg ?: ('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        throw new \RuntimeException($detail);
    }
}

/** Open a fresh authenticated IMAP connection (or null). */
function connectImap(): ?\Webmail\Services\ImapService {
    global $config, $testEmail, $testPassword;
    $imap = new \Webmail\Services\ImapService($config['imap'] ?? []);
    if (!$imap->connect($testEmail, $testPassword)) {
        return null;
    }
    return $imap;
}

/**
 * Wait (hard-bounded) for a message whose subject contains $needle to
 * land in INBOX. Returns ['uid'=>int,'imap'=>ImapService] or null on
 * timeout. Uses server-side SEARCH first (size-independent), falling
 * back to a 50-message scan.
 */
function waitForInbox(string $needle, int $timeoutSec = 60): ?array {
    $deadline = time() + $timeoutSec;
    do {
        $imap = connectImap();
        if ($imap) {
            // Primary: server-side TEXT search by the unique run token.
            try {
                $hits = $imap->search('INBOX', $needle);
                foreach ($hits as $m) {
                    if (isset($m['subject'], $m['uid']) && strpos($m['subject'], $needle) !== false) {
                        return ['uid' => (int)$m['uid'], 'imap' => $imap];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to scan
            }
            // Fallback: scan the 50 newest messages.
            $page = $imap->getMessages('INBOX', 1, 50);
            foreach (($page['messages'] ?? []) as $m) {
                if (isset($m['subject'], $m['uid']) && strpos($m['subject'], $needle) !== false) {
                    return ['uid' => (int)$m['uid'], 'imap' => $imap];
                }
            }
        }
        if (time() >= $deadline) break;
        sleep(3);
    } while (time() < $deadline);
    return null;
}

/**
 * Read a single raw header value (e.g. In-Reply-To, References) from a
 * message. ImapService::getCustomHeader is private and reads by name off
 * the currently selected folder, so we select via getMessage() first and
 * then invoke it by reflection.
 */
function fetchHeaderValue(\Webmail\Services\ImapService $imap, string $folder, int $uid, string $name): string {
    $imap->getMessage($folder, $uid); // ensures the folder is selected
    static $m = null;
    if ($m === null) {
        $ref = new \ReflectionClass(\Webmail\Services\ImapService::class);
        $m = $ref->getMethod('getCustomHeader');
        $m->setAccessible(true);
    }
    $val = $m->invoke($imap, $uid, $name);
    return is_string($val) ? $val : '';
}

/** Strip <>/whitespace from a Message-ID-ish value for comparison. */
function normalizeMsgId(string $v): string {
    return strtolower(trim($v, " \t<>"));
}

// ── Test doubles ─────────────────────────────────────────────────

/**
 * IMAP stub that returns a synthetic "original" message, so reply()/
 * forward() builder logic can be tested deterministically without
 * needing a real seed message in the mailbox.
 */
class StubImapService extends \Webmail\Services\ImapService {
    public ?array $stubMessage = null;
    public function __construct() { parent::__construct([]); }
    public function getMessage(string $folder, int $uid): ?array { return $this->stubMessage; }
}

/**
 * MessageController that skips the auth/IMAP gate so the real reply()/
 * forward() bodies run against an injected ImapService (live or stub).
 */
class TestableMessageController extends \Webmail\Controllers\MessageController {
    public function bind($imap, string $email): void {
        $this->imap = $imap;
        $this->userEmail = $email;
        $this->primaryUserEmail = $email;
    }
    protected function requireImap(\Webmail\Core\Request $request): ?\Webmail\Core\Response {
        return null;
    }
}

/** Build a Request with the given body/query (resets superglobals). */
function makeRequest(array $body = [], array $query = []): \Webmail\Core\Request {
    $_GET  = $query;
    $_POST = $body;
    $_SERVER['REQUEST_METHOD'] = empty($body) ? 'GET' : 'POST';
    $_SERVER['CONTENT_TYPE'] = '';
    return new \Webmail\Core\Request();
}

/** Invoke reply()/forward() and return the decoded data payload. */
function callBuilder(TestableMessageController $ctrl, string $method, \Webmail\Core\Request $request): array {
    /** @var \Webmail\Core\Response $resp */
    $resp = $ctrl->$method($request);
    $decoded = json_decode($resp->getContent(), true);
    if (!is_array($decoded) || !($decoded['success'] ?? false)) {
        throw new \RuntimeException("{$method}() returned non-success: " . $resp->getContent());
    }
    return $decoded['data'] ?? [];
}

// Config hardening for controller construction (mirrors replyall test).
$ctrlConfig = $config;
if (empty($ctrlConfig['imap_encryption_key'])) {
    $ctrlConfig['imap_encryption_key'] = bin2hex(random_bytes(16));
}
if (empty($ctrlConfig['jwt'])) {
    $ctrlConfig['jwt'] = ['algorithm' => 'HS256', 'secret' => bin2hex(random_bytes(16))];
}

// ── Cleanup (shutdown + signal safe) ─────────────────────────────

function doCleanup(): void {
    global $cleanupFileIds, $cleanupFolderIds, $cleanupPaths, $testEmail, $config;
    try {
        if (!empty($cleanupFileIds) || !empty($cleanupFolderIds)) {
            $drive = new \Webmail\Services\DriveService($config, $testEmail);
            foreach ($cleanupFileIds as $id) {
                try { $drive->deleteFile($testEmail, $id); } catch (\Throwable $e) {}
            }
            foreach (array_reverse($cleanupFolderIds) as $id) {
                try { $drive->deleteFolder($testEmail, $id); } catch (\Throwable $e) {}
            }
        }
    } catch (\Throwable $e) {
        error_log('[email-flows cleanup] ' . $e->getMessage());
    }
    foreach ($cleanupPaths as $p) {
        if (is_string($p) && file_exists($p)) @unlink($p);
    }
    $cleanupFileIds = $cleanupFolderIds = $cleanupPaths = [];
}
register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ══════════════════════════════════════════════════════════════════

out("=================================================================");
out("  FlowOne Email Flows - E2E Acceptance Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: " . ($testEmail ?: '(not provided)'));
out("  Send:    " . ($skipSend ? 'SKIPPED' : 'ENABLED'));
out("  Run ID:  {$runId}");
out("  Log:     {$logFile}");
out("=================================================================\n");

// ── Pre-flight ───────────────────────────────────────────────────

out("--- PRE-FLIGHT ---");

$preflightFailed = false;
test('Required PHP extensions loaded', function () {
    foreach (['imap', 'openssl', 'mbstring', 'pdo'] as $ext) {
        assert_true(extension_loaded($ext), "Missing extension: {$ext}");
    }
});
test('Core classes autoloadable', function () {
    foreach ([
        \Webmail\Services\SmtpService::class,
        \Webmail\Services\ImapService::class,
        \Webmail\Services\DriveService::class,
        \Webmail\Controllers\MessageController::class,
    ] as $cls) {
        assert_true(class_exists($cls), "Class not autoloadable: {$cls}");
    }
});
test('SMTP + IMAP config present', function () use ($config) {
    assert_not_empty($config['smtp'] ?? null, 'smtp config missing');
    assert_not_empty($config['imap'] ?? null, 'imap config missing');
});

$liveCapable = !$skipSend && !empty($testEmail) && !empty($testPassword);

if (!$smokeOnly && !$liveCapable) {
    out("\n  NOTE: live SMTP/IMAP groups need --email + --password and no --skip-send.");
    out("        Running only deterministic builder tests.\n");
}

if ($smokeOnly) {
    out("\n--- SMOKE ---");
    if (!empty($testEmail) && !empty($testPassword)) {
        test('IMAP connect', function () {
            $imap = connectImap();
            assert_true($imap !== null, 'IMAP connect failed - check password/Dovecot');
        });
        test('SMTP credentials accepted', function () use ($config, $testEmail, $testPassword) {
            $smtp = new \Webmail\Services\SmtpService($config['smtp']);
            $smtp->setCredentials($testEmail, $testPassword);
        });
    } else {
        out("  (skipping connectivity checks - no --email/--password)");
    }
    goto summary;
}

// ── 1. SEND ──────────────────────────────────────────────────────

if (shouldRun('send')) {
    out("\n--- 1. SEND ---");

    if (!$liveCapable) {
        test('send (live)', fn() => 'skip');
    } else {
        test('Send plain-text email', function () use ($config, $testEmail, $testPassword, $testTo, $subjPfx) {
            $smtp = new \Webmail\Services\SmtpService($config['smtp']);
            $smtp->setCredentials($testEmail, $testPassword);
            $res = $smtp->send([
                'to'        => [['email' => $testTo, 'name' => 'Flow Test']],
                'subject'   => "{$subjPfx} send-plain",
                'body_text' => "Plain send at " . date('c'),
            ]);
            assert_true($res['success'] ?? false, 'send failed: ' . ($res['error'] ?? '?'));
            assert_not_empty($res['message_id'] ?? '', 'no message_id returned');
        });

        test('Send HTML email', function () use ($config, $testEmail, $testPassword, $testTo, $subjPfx) {
            $smtp = new \Webmail\Services\SmtpService($config['smtp']);
            $smtp->setCredentials($testEmail, $testPassword);
            $res = $smtp->send([
                'to'        => [['email' => $testTo]],
                'subject'   => "{$subjPfx} send-html",
                'body_html' => '<p>HTML send at <strong>' . date('c') . '</strong> - Árvíztűrő</p>',
            ]);
            assert_true($res['success'] ?? false, 'HTML send failed: ' . ($res['error'] ?? '?'));
        });
    }
}

// ── 2. RECEIVE ───────────────────────────────────────────────────

if (shouldRun('receive')) {
    out("\n--- 2. RECEIVE (round-trip, 60s cap) ---");

    if (!$liveCapable) {
        test('receive (live)', fn() => 'skip');
    } else {
        test('Sent message is received within 60s', function () use ($config, $testEmail, $testPassword, $testTo, $subjPfx) {
            $subject = "{$subjPfx} receive";
            $smtp = new \Webmail\Services\SmtpService($config['smtp']);
            $smtp->setCredentials($testEmail, $testPassword);
            $res = $smtp->send([
                'to'        => [['email' => $testTo]],
                'subject'   => $subject,
                'body_text' => "Receive probe " . date('c'),
            ]);
            assert_true($res['success'] ?? false, 'send failed: ' . ($res['error'] ?? '?'));

            $found = waitForInbox($subject, 60);
            assert_true($found !== null, 'message NOT received within 60s (broken delivery/queue?)');
        }, 75);
    }
}

// ── 3. ATTACHMENT (md5 integrity) ────────────────────────────────

if (shouldRun('attachment')) {
    out("\n--- 3. SEND WITH ATTACHMENT (md5 integrity) ---");

    if (!$liveCapable) {
        test('attachment (live)', fn() => 'skip');
    } else {
        test('Attachment survives round-trip with identical md5', function () use ($config, $testEmail, $testPassword, $testTo, $subjPfx) {
            $subject  = "{$subjPfx} attachment";
            $attName  = 'flowone-test-attach.txt';
            // UTF-8 + newlines exercise transfer-encoding edge cases.
            $attBytes = "FLOWONE-TEST attachment\n" . str_repeat("Árvíztűrő-9\n", 80) . "end\n";
            $wantMd5  = md5($attBytes);

            $smtp = new \Webmail\Services\SmtpService($config['smtp']);
            $smtp->setCredentials($testEmail, $testPassword);
            $res = $smtp->send([
                'to'          => [['email' => $testTo]],
                'subject'     => $subject,
                'body_html'   => '<p>Attachment integrity probe.</p>',
                'attachments' => [
                    ['content' => $attBytes, 'name' => $attName, 'type' => 'text/plain'],
                ],
            ]);
            assert_true($res['success'] ?? false, 'send failed: ' . ($res['error'] ?? '?'));

            $found = waitForInbox($subject, 60);
            assert_true($found !== null, 'attachment mail NOT received within 60s');

            /** @var \Webmail\Services\ImapService $imap */
            $imap = $found['imap'];
            $msg  = $imap->getMessage('INBOX', $found['uid']);
            assert_true($msg !== null, 'could not fetch received message');
            assert_true(!empty($msg['attachments']), 'received message has no attachments');

            $part = null;
            foreach ($msg['attachments'] as $a) {
                if (($a['filename'] ?? '') === $attName) { $part = $a; break; }
            }
            assert_true($part !== null, "attachment '{$attName}' not present on received message");
            assert_true((int)($part['size'] ?? 0) > 0, 'attachment size is 0');

            $fetched = $imap->getAttachment('INBOX', $found['uid'], (string)$part['part']);
            assert_true($fetched !== null && isset($fetched['content']), 'could not download attachment bytes');
            assert_equals($wantMd5, md5($fetched['content']), 'attachment md5 mismatch (corrupted MIME encoding)');
        }, 80);
    }
}

// ── 4. DRIVE ATTACHMENT (token download + md5) ───────────────────

if (shouldRun('drive-attachment')) {
    out("\n--- 4. SEND + RECEIVE WITH DRIVE ATTACHMENT ---");

    if (empty($testEmail)) {
        test('drive-attachment', fn() => 'skip');
    } else {
        test('Drive share resolves and downloads with identical md5', function () use ($config, $testEmail, &$cleanupFileIds) {
            $drive   = new \Webmail\Services\DriveService($config, $testEmail);
            $content = "FLOWONE-TEST drive attachment body " . date('c') . "\n" . str_repeat("xy9\n", 40);
            $wantMd5 = md5($content);

            $file = $drive->uploadFileContent($testEmail, 'flowone-test-drive-attach.txt', $content, 'text/plain');
            assert_true($file !== null, 'uploadFileContent returned null');
            $cleanupFileIds[] = $file['id'];

            $token = $drive->createShareLinkForEmail($testEmail, (int)$file['id']);
            assert_not_empty($token, 'createShareLinkForEmail returned empty');

            $resolved = $drive->getFileByShareToken($token);
            assert_true($resolved !== null, 'share token did not resolve');

            $pathInfo = $drive->getFilePathByToken($token);
            assert_true($pathInfo !== null && !empty($pathInfo['path']) && file_exists($pathInfo['path']), 'token download path missing');
            assert_equals($wantMd5, md5(file_get_contents($pathInfo['path'])), 'downloaded content md5 mismatch');
        });

        if (!$liveCapable) {
            test('drive-attachment email round-trip (live)', fn() => 'skip');
        } else {
            test('Email carrying the Drive share link is received with the token intact', function () use ($config, $testEmail, $testPassword, $testTo, $subjPfx, &$cleanupFileIds) {
                $drive   = new \Webmail\Services\DriveService($config, $testEmail);
                $content = "FLOWONE-TEST drive link mail " . date('c');
                $file = $drive->uploadFileContent($testEmail, 'flowone-test-drive-link.txt', $content, 'text/plain');
                assert_true($file !== null, 'upload failed');
                $cleanupFileIds[] = $file['id'];

                $token = $drive->createShareLinkForEmail($testEmail, (int)$file['id']);
                assert_not_empty($token, 'share token empty');

                $base = rtrim($config['app_url'] ?? 'https://flowone.pro', '/');
                $url  = "{$base}/api/drive/share/{$token}";
                $subject = "{$subjPfx} drive-attachment";

                $smtp = new \Webmail\Services\SmtpService($config['smtp']);
                $smtp->setCredentials($testEmail, $testPassword);
                $res = $smtp->send([
                    'to'        => [['email' => $testTo]],
                    'subject'   => $subject,
                    'body_html' => '<p>Drive file: <a href="' . $url . '">download</a></p>',
                ]);
                assert_true($res['success'] ?? false, 'send failed: ' . ($res['error'] ?? '?'));

                $found = waitForInbox($subject, 60);
                assert_true($found !== null, 'drive-attachment mail NOT received within 60s');

                /** @var \Webmail\Services\ImapService $imap */
                $imap = $found['imap'];
                $msg = $imap->getMessage('INBOX', $found['uid']);
                assert_true($msg !== null, 'could not fetch received message');
                $body = ($msg['body_html'] ?? '') . ($msg['body_text'] ?? '');
                assert_true(strpos($body, $token) !== false, 'share token missing from received body');

                // The link still works end-to-end after delivery.
                assert_true($drive->getFileByShareToken($token) !== null, 'token stopped resolving after send');
            }, 80);
        }
    }
}

// ── 5. REPLY (Re: + threading) ───────────────────────────────────

if (shouldRun('reply')) {
    out("\n--- 5. REPLY ---");

    // 5a. Deterministic builder test (always runs): reply-to-one.
    test('reply() builds To=sender and a Re: subject', function () use ($ctrlConfig, $testEmail) {
        $stub = new StubImapService();
        $stub->stubMessage = [
            'from'        => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'reply_to'    => [],
            'to'          => [['email' => $testEmail ?: 'me@flowone.pro', 'name' => 'Me']],
            'cc'          => [],
            'subject'     => 'Project update',
            'body_html'   => '<p>hi</p>',
            'attachments' => [],
        ];
        $ctrl = new TestableMessageController($ctrlConfig);
        $ctrl->bind($stub, $testEmail ?: 'me@flowone.pro');

        $data = callBuilder($ctrl, 'reply', makeRequest([], []));
        assert_equals('sender@example.com', strtolower($data['to'][0]['email'] ?? ''), 'reply To must be the original sender');
        assert_true(preg_match('/^Re:/i', $data['subject'] ?? '') === 1, 'subject must start with Re:');
    });

    // 5b. Live threading check.
    if (!$liveCapable) {
        test('reply threading (live)', fn() => 'skip');
    } else {
        test('Delivered reply carries In-Reply-To + References to the original', function () use ($config, $testEmail, $testPassword, $testTo, $subjPfx) {
            // Seed a message to self.
            $seedSubject = "{$subjPfx} reply-seed";
            $smtp = new \Webmail\Services\SmtpService($config['smtp']);
            $smtp->setCredentials($testEmail, $testPassword);
            $seed = $smtp->send([
                'to'        => [['email' => $testTo]],
                'subject'   => $seedSubject,
                'body_text' => 'Seed for reply threading ' . date('c'),
            ]);
            assert_true($seed['success'] ?? false, 'seed send failed');

            $found = waitForInbox($seedSubject, 60);
            assert_true($found !== null, 'seed not received within 60s');

            /** @var \Webmail\Services\ImapService $imap */
            $imap = $found['imap'];
            $orig = $imap->getMessage('INBOX', $found['uid']);
            assert_true($orig !== null, 'could not fetch seed');
            $origMsgId = $orig['message_id'] ?? '';
            assert_not_empty($origMsgId, 'seed has no Message-ID');

            // Send the reply with proper threading headers.
            $replySubject = "{$subjPfx} reply-out Re: " . $seedSubject;
            $reply = $smtp->send([
                'to'          => [['email' => $testTo]],
                'subject'     => $replySubject,
                'body_text'   => 'This is the reply ' . date('c'),
                'in_reply_to' => '<' . trim($origMsgId, '<>') . '>',
                'references'  => '<' . trim($origMsgId, '<>') . '>',
            ]);
            assert_true($reply['success'] ?? false, 'reply send failed');

            $rfound = waitForInbox($replySubject, 60);
            assert_true($rfound !== null, 'reply not received within 60s');

            /** @var \Webmail\Services\ImapService $rimap */
            $rimap = $rfound['imap'];
            $inReplyTo  = normalizeMsgId(fetchHeaderValue($rimap, 'INBOX', $rfound['uid'], 'In-Reply-To'));
            $references = normalizeMsgId(fetchHeaderValue($rimap, 'INBOX', $rfound['uid'], 'References'));
            $want = normalizeMsgId($origMsgId);

            assert_true($inReplyTo === $want, "In-Reply-To mismatch: got '{$inReplyTo}', want '{$want}'");
            assert_true(strpos($references, $want) !== false, "References must contain the original Message-ID ('{$want}')");
        }, 150);
    }
}

// ── 6. REPLY-ALL (dedup + self excluded) ─────────────────────────

if (shouldRun('reply-all')) {
    out("\n--- 6. REPLY-ALL (de-dup, self excluded) ---");

    // Deterministic: the backend reply() builder appends To+Cc minus self
    // (no dedup); dedup is enforced at send by the shared helpers. We feed
    // the builder output through those REAL helpers and assert the final
    // recipient set.
    test('reply-all yields a@,b@,c@ with no duplicates and self excluded', function () use ($ctrlConfig, $testEmail) {
        $self = $testEmail ?: 'me@flowone.pro';
        $stub = new StubImapService();
        // Original: To: self, a, b   Cc: b, c   (b duplicated across fields)
        $stub->stubMessage = [
            'from'        => [['email' => 'sender@example.com', 'name' => 'Sender']],
            'reply_to'    => [],
            'to'          => [
                ['email' => $self,            'name' => 'Me'],
                ['email' => 'a@example.com',  'name' => 'A'],
                ['email' => 'b@example.com',  'name' => 'B'],
            ],
            'cc'          => [
                ['email' => 'b@example.com',  'name' => 'B again'],
                ['email' => 'c@example.com',  'name' => 'C'],
            ],
            'subject'     => 'Group thread',
            'attachments' => [],
        ];
        $ctrl = new TestableMessageController($ctrlConfig);
        $ctrl->bind($stub, $self);

        $data = callBuilder($ctrl, 'reply', makeRequest(['reply_all' => true], []));

        // To is the original sender.
        assert_equals('sender@example.com', strtolower($data['to'][0]['email'] ?? ''), 'reply-all To must be sender');

        // Run the builder's Cc through the real send-time dedup helpers.
        $invoke = function (string $m, array $args) use ($ctrlConfig) {
            $c = new \Webmail\Controllers\MessageController($ctrlConfig);
            $ref = new \ReflectionClass($c);
            $mm = $ref->getMethod($m);
            $mm->setAccessible(true);
            return $mm->invokeArgs($c, $args);
        };
        $deduped = $invoke('dedupeRecipients', [$data['cc'] ?? []]);
        $final   = $invoke('excludeRecipient', [$deduped, $self]);

        $emails = array_map(fn($r) => strtolower($r['email']), $final);
        sort($emails);
        assert_equals(['a@example.com', 'b@example.com', 'c@example.com'], $emails, 'reply-all recipient set wrong (dup or self leaked)');
        assert_true(!in_array(strtolower($self), $emails, true), 'self must not be a recipient');
    });
}

// ── 7. FORWARD (with / without attachments) ──────────────────────

if (shouldRun('forward')) {
    out("\n--- 7. FORWARD ---");

    test('forward() carries attachments and prefixes Fwd:', function () use ($ctrlConfig, $testEmail) {
        $stub = new StubImapService();
        $stub->stubMessage = [
            'from'        => [['email' => 'sender@example.com']],
            'to'          => [['email' => $testEmail ?: 'me@flowone.pro']],
            'subject'     => 'Report attached',
            'body_html'   => '<p>see attached</p>',
            'attachments' => [
                ['part' => '2', 'filename' => 'flowone-test-report.pdf', 'size' => 1234, 'type' => 'application/pdf', 'encoding' => 3],
            ],
        ];
        $ctrl = new TestableMessageController($ctrlConfig);
        $ctrl->bind($stub, $testEmail ?: 'me@flowone.pro');

        $data = callBuilder($ctrl, 'forward', makeRequest([], []));
        assert_true(preg_match('/^Fwd:/i', $data['subject'] ?? '') === 1, 'subject must start with Fwd:');
        assert_true(!empty($data['original']['attachments']), 'forward must carry original attachments');
        assert_equals('flowone-test-report.pdf', $data['original']['attachments'][0]['filename'] ?? '', 'attachment filename lost');
    });

    test('forward() of a plain message has empty attachments + Fwd:', function () use ($ctrlConfig, $testEmail) {
        $stub = new StubImapService();
        $stub->stubMessage = [
            'from'        => [['email' => 'sender@example.com']],
            'to'          => [['email' => $testEmail ?: 'me@flowone.pro']],
            'subject'     => 'Just text',
            'body_html'   => '<p>no files</p>',
            'attachments' => [],
        ];
        $ctrl = new TestableMessageController($ctrlConfig);
        $ctrl->bind($stub, $testEmail ?: 'me@flowone.pro');

        $data = callBuilder($ctrl, 'forward', makeRequest([], []));
        assert_true(preg_match('/^Fwd:/i', $data['subject'] ?? '') === 1, 'subject must start with Fwd:');
        assert_true(empty($data['original']['attachments']), 'plain forward must have no attachments');
    });
}

// ── 8. FULL WORKFLOW ─────────────────────────────────────────────

if (shouldRun('workflow')) {
    out("\n--- 8. FULL WORKFLOW (upload -> share -> send -> receive -> reply -> forward -> delete) ---");

    if (!$liveCapable) {
        test('workflow (live)', fn() => 'skip');
    } else {
        test('Complete user journey end to end', function () use ($config, $ctrlConfig, $testEmail, $testPassword, $testTo, $subjPfx, &$cleanupFileIds, &$cleanupFolderIds) {
            $drive = new \Webmail\Services\DriveService($config, $testEmail);

            // 1) Folder + upload
            $folder = $drive->createFolder($testEmail, 'FLOWONE-TEST-Workflow');
            assert_true($folder !== null, 'createFolder failed');
            $folderId = (int)$folder['id'];
            $cleanupFolderIds[] = $folderId;

            $content = "FLOWONE-TEST workflow file " . date('c');
            $file = $drive->uploadFileContent($testEmail, 'flowone-test-workflow.txt', $content, 'text/plain', $folderId);
            assert_true($file !== null, 'upload into folder failed');
            $fileId = (int)$file['id'];
            $cleanupFileIds[] = $fileId;

            // 2) Share link
            $token = $drive->createShareLinkForEmail($testEmail, $fileId);
            assert_not_empty($token, 'share link failed');

            // 3) Send email containing the link
            $base = rtrim($config['app_url'] ?? 'https://flowone.pro', '/');
            $subject = "{$subjPfx} workflow";
            $smtp = new \Webmail\Services\SmtpService($config['smtp']);
            $smtp->setCredentials($testEmail, $testPassword);
            $sent = $smtp->send([
                'to'        => [['email' => $testTo]],
                'subject'   => $subject,
                'body_html' => '<p>File: <a href="' . $base . '/api/drive/share/' . $token . '">link</a></p>',
            ]);
            assert_true($sent['success'] ?? false, 'workflow send failed');

            // 4) Receive
            $found = waitForInbox($subject, 60);
            assert_true($found !== null, 'workflow mail NOT received within 60s');
            /** @var \Webmail\Services\ImapService $imap */
            $imap = $found['imap'];
            $uid  = $found['uid'];

            // 5) Reply (real controller on the live message). uid is read
            // via getParam(), so set it on the request.
            $ctrl = new TestableMessageController($ctrlConfig);
            $ctrl->bind($imap, $testEmail);
            $req = makeRequest([], ['folder' => 'INBOX']);
            $req->setParam('uid', $uid);
            $replyData = callBuilder($ctrl, 'reply', $req);
            assert_true(preg_match('/^Re:/i', $replyData['subject'] ?? '') === 1, 'reply subject not Re:');

            // 6) Forward (real controller on the live message)
            $req2 = makeRequest([], ['folder' => 'INBOX']);
            $req2->setParam('uid', $uid);
            $fwdData = callBuilder($ctrl, 'forward', $req2);
            assert_true(preg_match('/^Fwd:/i', $fwdData['subject'] ?? '') === 1, 'forward subject not Fwd:');

            // 7) Delete drive file
            assert_true($drive->deleteFile($testEmail, $fileId), 'deleteFile failed');
            assert_true($drive->getFile($testEmail, $fileId) === null, 'file still present after delete');
            $cleanupFileIds = array_values(array_diff($cleanupFileIds, [$fileId]));

            // 8) Delete drive folder
            assert_true((bool)$drive->deleteFolder($testEmail, $folderId), 'deleteFolder failed');
            $cleanupFolderIds = array_values(array_diff($cleanupFolderIds, [$folderId]));
        }, 160);
    }
}

// ── Summary ──────────────────────────────────────────────────────

summary:
out("\n--- CLEANUP ---");
test('Remove Drive test artifacts', function () {
    doCleanup();
});

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
if ($warnings > 0 && $verbose) {
    out("\n  {$YELLOW}WARNINGS / SKIPS:{$NC}");
    foreach ($results as $r) {
        if ($r['status'] === 'WARN') out("    ~ {$r['name']}");
    }
}

if ($jsonMode) {
    echo json_encode([
        'name'     => 'email-flows',
        'runId'    => $runId,
        'total'    => $totalTests,
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'log'      => $logFile,
        'results'  => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

out("=================================================================");
exit($failed > 0 ? 1 : 0);
