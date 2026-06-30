#!/usr/bin/env php
<?php
/**
 * Reply-All Recipients & Duplicate-Send Regression Suite
 *
 * Verifies the fix for the bug where:
 *   1. The IMAP list endpoint truncated `to` to a single recipient and
 *      omitted `cc` entirely, so cached messages lost their recipient
 *      lists and Reply-All built a Cc with the user's own address.
 *   2. MessageController::send looped one SMTP delivery per To/Cc/Bcc
 *      entry, with no dedup, so the same address could receive multiple
 *      physical copies.
 *
 * Server run:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/replyall-recipients-test.php \
 *     --email=user@flowone.pro --password=PASS --verbose
 *
 * Groups: envelope, list-vs-detail, send-dedup, mime-headers, reply-all-filter
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

// ── CLI args ─────────────────────────────────────────────────────

$opts = getopt('', [
    'email:', 'password:', 'to:',
    'only:', 'skip-send', 'smoke',
    'json', 'verbose', 'help',
]);

if (isset($opts['help'])) {
    echo <<<USAGE
Reply-All Recipients Regression Suite
=====================================

Usage:
  php replyall-recipients-test.php --email=USER --password=PASS [options]

Options:
  --email=EMAIL        Test account email (required for IMAP groups)
  --password=PASS      Test account password (required for IMAP groups)
  --to=EMAIL           Recipient for live send tests (default: --email)
  --only=g1,g2         Run only listed groups
                       (envelope, list-vs-detail, send-dedup, mime-headers,
                        reply-all-filter)
  --skip-send          Skip groups that hit real SMTP/IMAP (list-vs-detail)
  --smoke              Quick health check: config + DB + IMAP reachable only
  --json               Emit a single JSON summary instead of human output
  --verbose            Show stack traces and raw responses
  --help               Show this help

Groups:
  envelope         Pure unit test of ImapService::formatMessageOverview
                   with a synthetic overview row carrying 3 To + 2 Cc.
  list-vs-detail   Hits real IMAP: fetches a recent multi-recipient message
                   via both the list endpoint and the single-message endpoint
                   and asserts `to` lengths match.
  send-dedup       Pure unit test of MessageController::send helpers
                   (dedupeRecipients, excludeRecipient, buildHeaderRecipientLists).
  mime-headers     Dry-run of SmtpService individual delivery: asserts every
                   per-recipient copy carries the full visible To/Cc while the
                   SMTP envelope (RCPT TO) is a single address.
  reply-all-filter Simulates the JS setupReply filter to confirm the primary
                   login + linked addresses are removed.

USAGE;
    exit(0);
}

$testEmail    = $opts['email']    ?? null;
$testPassword = $opts['password'] ?? null;
$testTo       = $opts['to']       ?? $testEmail;
$verbose      = isset($opts['verbose']);
$jsonMode     = isset($opts['json']);
$skipSend     = isset($opts['skip-send']);
$smoke        = isset($opts['smoke']);
$only         = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : [];

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/replyall-recipients-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

// ANSI colors (terminal only - skip in --json mode)
$C_GREEN  = $jsonMode ? '' : "\033[32m";
$C_RED    = $jsonMode ? '' : "\033[31m";
$C_YELLOW = $jsonMode ? '' : "\033[33m";
$C_RESET  = $jsonMode ? '' : "\033[0m";
$C_DIM    = $jsonMode ? '' : "\033[2m";

function out(string $msg): void {
    global $logFile, $jsonMode;
    if (!$jsonMode) echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    global $C_GREEN, $C_RED, $C_YELLOW, $C_RESET;

    $totalTests++;
    $start = microtime(true);

    // Per-test timeout protection: 30 seconds
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(30);
    } else {
        @set_time_limit(30);
    }

    try {
        $result = $fn();
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  {$C_YELLOW}[WARN]{$C_RESET}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  {$C_GREEN}[PASS]{$C_RESET}  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        $failed++;
        out("  {$C_RED}[FAIL]{$C_RESET}  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = [
            'name'   => $name,
            'status' => 'FAIL',
            'ms'     => $elapsed,
            'error'  => $e->getMessage(),
        ];
    } finally {
        if (function_exists('pcntl_alarm')) pcntl_alarm(0);
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new \RuntimeException($msg);
}
function assert_eq($expected, $actual, string $label = ''): void {
    if ($expected !== $actual) {
        $e = is_scalar($expected) ? var_export($expected, true) : json_encode($expected);
        $a = is_scalar($actual)   ? var_export($actual,   true) : json_encode($actual);
        throw new \RuntimeException(($label ? "{$label}: " : '') . "expected {$e}, got {$a}");
    }
}

function shouldRun(string $group): bool {
    global $only;
    if (empty($only)) return true;
    return in_array($group, $only, true);
}

// ── Pre-flight ───────────────────────────────────────────────────

out("=================================================================");
out("  Reply-All Recipients Regression Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: " . ($testEmail ?: '(not provided)'));
out("  Log:     {$logFile}");
out("=================================================================");

$preflightFailed = false;
foreach (['mbstring', 'openssl', 'pdo'] as $ext) {
    if (!extension_loaded($ext)) {
        out("PRE-FLIGHT FAIL: required PHP extension '{$ext}' is not loaded");
        $preflightFailed = true;
    }
}
if (!is_dir($logDir) || !is_writable($logDir)) {
    out("PRE-FLIGHT FAIL: log directory {$logDir} is not writable");
    $preflightFailed = true;
}
if ($preflightFailed) {
    exit(2);
}

if ($smoke) {
    // Smoke mode: connectivity + class load only
    out("\n--- SMOKE ---");
    test('Bootstrap autoloader loaded ImapService', function () {
        assert_true(class_exists(\Webmail\Services\ImapService::class), 'ImapService not autoloadable');
    });
    test('Bootstrap autoloader loaded MessageController', function () {
        assert_true(class_exists(\Webmail\Controllers\MessageController::class), 'MessageController not autoloadable');
    });
    test('config.php returns required keys', function () use ($config) {
        assert_true(is_array($config), 'config is not an array');
        assert_true(isset($config['imap']) || isset($config['smtp']), 'no imap/smtp config keys');
    });
    finish();
}

// ── Group: envelope (pure unit test of formatMessageOverview) ────

if (shouldRun('envelope')) {
    out("\n--- 1. ENVELOPE PARSING (formatMessageOverview) ---");

    // formatMessageOverview is private. Use reflection to invoke it with a
    // synthetic overview row + raw headers, so we can verify the bug fix
    // in isolation without needing a real IMAP connection.
    $invokeOverview = function (object $msg, string $rawHeaders = ''): array {
        $svc = new \Webmail\Services\ImapService([]);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('formatMessageOverview');
        $m->setAccessible(true);
        return $m->invoke($svc, $msg, $rawHeaders);
    };

    test('to with 3 comma-separated recipients parses all 3', function () use ($invokeOverview) {
        $msg = (object)[
            'uid'        => 42,
            'msgno'      => 1,
            'message_id' => '<abc@flowone.pro>',
            'subject'    => '[FLOWONE-TEST] envelope multi-to',
            'from'       => 'Sender <sender@example.com>',
            'to'         => '"Robert" <a@x.com>, "Studio" <b@y.com>, c@z.com',
            'date'       => 'Mon, 11 May 2026 10:00:00 +0000',
            'size'       => 1234,
        ];
        $out = $invokeOverview($msg);
        assert_true(isset($out['to']) && is_array($out['to']), 'to is not an array');
        assert_eq(3, count($out['to']), 'to count');
        $emails = array_column($out['to'], 'email');
        assert_true(in_array('a@x.com', $emails, true), 'missing a@x.com');
        assert_true(in_array('b@y.com', $emails, true), 'missing b@y.com');
        assert_true(in_array('c@z.com', $emails, true), 'missing c@z.com');
    });

    test('cc is empty without raw headers (overview has no cc field)', function () use ($invokeOverview) {
        $msg = (object)[
            'uid'        => 43, 'msgno' => 2,
            'message_id' => '<43@flowone.pro>',
            'from'       => 'Sender <s@example.com>',
            'to'         => '<a@x.com>',
            'date'       => 'Mon, 11 May 2026 10:00:00 +0000',
        ];
        $out = $invokeOverview($msg);
        assert_true(array_key_exists('cc', $out), 'cc key missing');
        assert_eq([], $out['cc'], 'cc should be empty array');
    });

    test('cc is parsed from raw headers when provided', function () use ($invokeOverview) {
        $msg = (object)[
            'uid'        => 44, 'msgno' => 3,
            'message_id' => '<44@flowone.pro>',
            'from'       => 'Sender <s@example.com>',
            'to'         => '<a@x.com>, <b@y.com>',
            'date'       => 'Mon, 11 May 2026 10:00:00 +0000',
        ];
        $raw = implode("\r\n", [
            'From: Sender <s@example.com>',
            'To: <a@x.com>, <b@y.com>',
            'Cc: "Cee One" <c1@cc.com>, c2@cc.com',
            'Date: Mon, 11 May 2026 10:00:00 +0000',
            '',
        ]);
        $out = $invokeOverview($msg, $raw);
        assert_eq(2, count($out['to']), 'to count');
        assert_eq(2, count($out['cc']), 'cc count');
        assert_eq('c1@cc.com', $out['cc'][0]['email'], 'first cc email');
        assert_eq('c2@cc.com', $out['cc'][1]['email'], 'second cc email');
    });

    test('to falls back to raw headers when overview to is empty', function () use ($invokeOverview) {
        $msg = (object)[
            'uid'        => 45, 'msgno' => 4,
            'message_id' => '<45@flowone.pro>',
            'from'       => 'Sender <s@example.com>',
            'to'         => '', // empty in overview
            'date'       => 'Mon, 11 May 2026 10:00:00 +0000',
        ];
        $raw = implode("\r\n", [
            'From: Sender <s@example.com>',
            'To: header-to@x.com',
            '',
        ]);
        $out = $invokeOverview($msg, $raw);
        assert_eq(1, count($out['to']), 'to count');
        assert_eq('header-to@x.com', $out['to'][0]['email'], 'to email from headers');
    });

    test('quoted display name with comma stays in a single recipient', function () use ($invokeOverview) {
        $msg = (object)[
            'uid'        => 46, 'msgno' => 5,
            'message_id' => '<46@flowone.pro>',
            'from'       => 'Sender <s@example.com>',
            'to'         => '"Last, First" <quoted@x.com>, plain@y.com',
            'date'       => 'Mon, 11 May 2026 10:00:00 +0000',
        ];
        $out = $invokeOverview($msg);
        assert_eq(2, count($out['to']), 'to count (quoted comma must not split)');
        assert_eq('quoted@x.com', $out['to'][0]['email'], 'first email');
        assert_eq('plain@y.com', $out['to'][1]['email'], 'second email');
    });
}

// ── Group: list-vs-detail (real IMAP roundtrip) ──────────────────

if (shouldRun('list-vs-detail') && !$skipSend) {
    out("\n--- 2. LIST vs DETAIL (real IMAP) ---");

    if (empty($testEmail) || empty($testPassword)) {
        out("  [WARN]  list-vs-detail skipped: --email/--password not provided");
        $warnings++;
        $totalTests++;
        $results[] = ['name' => 'list-vs-detail prereq', 'status' => 'WARN'];
    } else {
        // Show what we're about to attempt so failures are easy to triage.
        $imapCfg = $config['imap'] ?? [];
        $host = $imapCfg['host'] ?? '(no host configured)';
        $port = $imapCfg['port'] ?? '(default)';
        $enc  = $imapCfg['encryption'] ?? '(default)';
        out("  attempting IMAP {$enc}://{$testEmail}@{$host}:{$port}");

        $imap = null;
        $imapReady = false;
        test('IMAP connect for list-vs-detail group', function () use ($config, $testEmail, $testPassword, &$imap, &$imapReady) {
            // Clear any leftover libc-imap error stack from previous tests so
            // the messages we collect below are from THIS attempt only.
            if (function_exists('imap_errors')) @imap_errors();
            if (function_exists('imap_alerts')) @imap_alerts();

            $imap = new \Webmail\Services\ImapService($config['imap'] ?? []);
            $ok = $imap->connect($testEmail, $testPassword);
            if (!$ok) {
                $errors = function_exists('imap_errors') ? (imap_errors() ?: []) : [];
                $alerts = function_exists('imap_alerts') ? (imap_alerts() ?: []) : [];
                $svcErr = method_exists($imap, 'getLastError') ? $imap->getLastError() : null;
                $detail = trim(implode(' | ', array_filter([
                    $svcErr ? "service: {$svcErr}" : null,
                    !empty($errors) ? 'imap_errors: ' . implode('; ', $errors) : null,
                    !empty($alerts) ? 'imap_alerts: ' . implode('; ', $alerts) : null,
                ])));
                if ($detail === '') $detail = '(no error detail surfaced - check /var/www/vps-email/backend/logs/php_errors.log)';
                throw new \RuntimeException("IMAP connect failed for {$testEmail}. {$detail}");
            }
            $imapReady = true;
        });

        test('Newest INBOX message: list.to length >= detail.to length', function () use (&$imap, &$imapReady) {
            // Don't cascade-fail when the connect test already failed -
            // skip with a warning instead of hammering a dead connection.
            if (!$imapReady || !$imap) {
                return 'warn';
            }
            $page = $imap->getMessages('INBOX', 1, 25);
            assert_true(!empty($page['messages']), 'INBOX appears empty - cannot test');

            // Find the first list message that has at least 2 recipients in either
            // To or Cc; if none, mark as warn (test environment-dependent).
            $multi = null;
            foreach ($page['messages'] as $m) {
                $toN = is_array($m['to'] ?? null) ? count($m['to']) : 0;
                $ccN = is_array($m['cc'] ?? null) ? count($m['cc']) : 0;
                if ($toN >= 2 || $ccN >= 2) {
                    $multi = $m;
                    break;
                }
            }
            if (!$multi) {
                return 'warn'; // no multi-recipient mail in this inbox
            }

            $listToCount = count($multi['to']);
            $listCcCount = is_array($multi['cc'] ?? null) ? count($multi['cc']) : 0;

            // Now fetch the single message and compare
            $detail = $imap->getMessage('INBOX', (int)$multi['uid']);
            assert_true(!empty($detail), 'Single-message fetch returned empty');

            $detailToCount = is_array($detail['to'] ?? null) ? count($detail['to']) : 0;
            $detailCcCount = is_array($detail['cc'] ?? null) ? count($detail['cc']) : 0;

            assert_true(
                $listToCount === $detailToCount,
                "to mismatch: list={$listToCount}, detail={$detailToCount} for UID {$multi['uid']}"
            );
            assert_true(
                $listCcCount === $detailCcCount,
                "cc mismatch: list={$listCcCount}, detail={$detailCcCount} for UID {$multi['uid']}"
            );
        });
    }
}

// ── Group: send-dedup (private helpers on MessageController) ─────

if (shouldRun('send-dedup')) {
    out("\n--- 3. SEND DEDUP (MessageController helpers) ---");

    // MessageController extends BaseController whose constructor builds a
    // SessionService that REQUIRES imap_encryption_key. In a dev/CI run that
    // env var may be missing, so we inject a throwaway key just for the test;
    // the helpers we exercise here do not touch sessions or crypto.
    $testConfig = $config;
    if (empty($testConfig['imap_encryption_key'])) {
        $testConfig['imap_encryption_key'] = bin2hex(random_bytes(16));
    }
    if (empty($testConfig['jwt'])) {
        $testConfig['jwt'] = ['algorithm' => 'HS256', 'secret' => bin2hex(random_bytes(16))];
    }

    $invokeHelper = function (string $method, array $args) use ($testConfig) {
        $ctrl = new \Webmail\Controllers\MessageController($testConfig);
        $ref = new \ReflectionClass($ctrl);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($ctrl, $args);
    };

    test('dedupeRecipients removes a duplicate appearing in To and Cc', function () use ($invokeHelper) {
        $merged = [
            ['email' => 'a@x.com', 'name' => 'A'],
            ['email' => 'B@x.com', 'name' => 'B'],
            ['email' => 'a@x.com', 'name' => 'A again from Cc'],
            ['email' => 'b@x.com', 'name' => 'b lowercased'],
        ];
        $out = $invokeHelper('dedupeRecipients', [$merged]);
        assert_eq(2, count($out), 'expected 2 unique');
        assert_eq('a@x.com', $out[0]['email'], 'first kept');
        assert_eq('B@x.com', $out[1]['email'], 'second kept (original casing preserved)');
    });

    test('dedupeRecipients tolerates raw string entries', function () use ($invokeHelper) {
        $out = $invokeHelper('dedupeRecipients', [['plain@x.com', 'plain@x.com', ['email' => 'plain@x.com']]]);
        assert_eq(1, count($out), 'all duplicates collapsed to 1');
    });

    test('excludeRecipient strips the sender even with different case', function () use ($invokeHelper) {
        $merged = [
            ['email' => 'me@flowone.pro', 'name' => 'Me'],
            ['email' => 'other@x.com', 'name' => 'Other'],
            ['email' => 'ME@FLOWONE.PRO', 'name' => 'Me Shouty'],
        ];
        $out = $invokeHelper('excludeRecipient', [$merged, 'Me@FlowOne.Pro']);
        assert_eq(1, count($out), 'only one survivor');
        assert_eq('other@x.com', $out[0]['email'], 'wrong survivor');
    });

    test('excludeRecipient is a no-op when sender is empty', function () use ($invokeHelper) {
        $merged = [['email' => 'a@x.com'], ['email' => 'b@x.com']];
        $out = $invokeHelper('excludeRecipient', [$merged, '']);
        assert_eq(2, count($out), 'should not strip anything');
    });

    test('dedupe + exclude together: To=[X], Cc=[X,Y], sender=Z => [X,Y]', function () use ($invokeHelper) {
        $merged = [
            ['email' => 'x@a.com', 'name' => 'X to'],
            ['email' => 'x@a.com', 'name' => 'X cc'],
            ['email' => 'y@a.com', 'name' => 'Y cc'],
        ];
        $deduped = $invokeHelper('dedupeRecipients', [$merged]);
        $final = $invokeHelper('excludeRecipient', [$deduped, 'z@a.com']);
        assert_eq(2, count($final), 'final count');
        assert_eq('x@a.com', $final[0]['email'], '');
        assert_eq('y@a.com', $final[1]['email'], '');
    });

    test('dedupe + exclude together: To=[X], Cc=[X], sender=X => []', function () use ($invokeHelper) {
        // The exact 6-email scenario boils down to one address appearing
        // in both To and Cc with the sender ALSO being that address.
        // After our fix the SMTP loop must receive zero entries here.
        $merged = [
            ['email' => 'me@me.com', 'name' => 'me to'],
            ['email' => 'me@me.com', 'name' => 'me cc'],
        ];
        $deduped = $invokeHelper('dedupeRecipients', [$merged]);
        $final = $invokeHelper('excludeRecipient', [$deduped, 'me@me.com']);
        assert_eq(0, count($final), 'all self entries must be gone');
    });

    test('buildHeaderRecipientLists removes To members from Cc (To wins)', function () use ($invokeHelper) {
        [$hTo, $hCc] = $invokeHelper('buildHeaderRecipientLists', [
            [['email' => 'john@x.com', 'name' => 'John']],
            [['email' => 'John@x.com', 'name' => 'John dup'], ['email' => 'jane@x.com', 'name' => 'Jane']],
        ]);
        assert_eq(1, count($hTo), 'To count');
        assert_eq('john@x.com', $hTo[0]['email'], 'To kept');
        $ccEmails = array_map(fn($r) => strtolower($r['email']), $hCc);
        assert_eq(['jane@x.com'], $ccEmails, 'john must be removed from Cc (To wins); jane stays');
    });

    test('buildHeaderRecipientLists dedupes within each list', function () use ($invokeHelper) {
        [$hTo, $hCc] = $invokeHelper('buildHeaderRecipientLists', [
            [['email' => 'a@x.com'], ['email' => 'A@x.com']],
            [['email' => 'b@y.com'], ['email' => 'b@y.com']],
        ]);
        assert_eq(1, count($hTo), 'To deduped to 1');
        assert_eq(1, count($hCc), 'Cc deduped to 1');
    });
}

// ── Group: mime-headers (SmtpService individual-delivery dry runs) ───

if (shouldRun('mime-headers')) {
    out("\n--- 5. MIME HEADERS vs ENVELOPE (SmtpService dry-run) ---");

    // Extract a single (unfolded) header value from a raw MIME message.
    $headerValue = function (string $raw, string $name): string {
        $head = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';
        $head = preg_replace("/\r?\n[ \t]+/", ' ', $head); // unfold continuations
        foreach (preg_split("/\r?\n/", $head) as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return '';
    };

    // Build a SmtpService with throwaway credentials. dry_run builds the MIME
    // and resolves the envelope but never opens a network connection, so the
    // host/auth settings are irrelevant.
    $makeSmtp = function () use ($config) {
        $smtp = new \Webmail\Services\SmtpService($config['smtp'] ?? []);
        $smtp->setCredentials('sender@flowone.pro', 'unused-in-dry-run');
        return $smtp;
    };

    test('SmtpService is autoloadable', function () {
        assert_true(class_exists(\Webmail\Services\SmtpService::class), 'SmtpService not autoloadable');
    });

    // Scenario: To: Alice / Cc: Bob, Charlie. Every per-recipient copy must
    // show the SAME visible To+Cc, while RCPT TO is just that one recipient.
    $headerTo = [['email' => 'alice@x.com', 'name' => 'Alice']];
    $headerCc = [
        ['email' => 'bob@y.com', 'name' => 'Bob'],
        ['email' => 'charlie@z.com', 'name' => 'Charlie'],
    ];

    foreach (['alice@x.com', 'bob@y.com', 'charlie@z.com'] as $rcpt) {
        test("Copy for {$rcpt}: visible To=Alice, Cc=Bob+Charlie, RCPT TO={$rcpt} only", function () use ($makeSmtp, $headerValue, $headerTo, $headerCc, $rcpt) {
            $smtp = $makeSmtp();
            $res = $smtp->send([
                'header_to'   => $headerTo,
                'header_cc'   => $headerCc,
                'envelope_to' => ['email' => $rcpt, 'name' => ''],
                'subject'     => '[FLOWONE-TEST] mime headers',
                'body_html'   => '<p>hi</p>',
                'message_id'  => '<' . bin2hex(random_bytes(8)) . '@flowone.pro>',
                'dry_run'     => true,
            ]);
            assert_true(!empty($res['success']), 'dry_run send failed: ' . ($res['error'] ?? '?'));

            $raw   = $res['raw_message'] ?? '';
            $toHdr = strtolower($headerValue($raw, 'To'));
            $ccHdr = strtolower($headerValue($raw, 'Cc'));

            // Header visibility: every recipient sees the full To + Cc.
            assert_true(str_contains($toHdr, 'alice@x.com'), "To header missing alice (got: {$toHdr})");
            assert_true(str_contains($ccHdr, 'bob@y.com'), "Cc header missing bob (got: {$ccHdr})");
            assert_true(str_contains($ccHdr, 'charlie@z.com'), "Cc header missing charlie (got: {$ccHdr})");

            // Delivery separation: RCPT TO contains ONLY this recipient.
            $envelope = array_map('strtolower', $res['envelope'] ?? []);
            assert_eq([$rcpt], $envelope, 'envelope must contain only the single recipient');
        });
    }

    test('Cross-list dedup applied upstream: To=john, Cc=jane => Cc shows only jane', function () use ($makeSmtp, $headerValue) {
        // MessageController::buildHeaderRecipientLists removes john from Cc
        // (To wins) before calling SmtpService; assert the resulting MIME.
        $smtp = $makeSmtp();
        $res = $smtp->send([
            'header_to'   => [['email' => 'john@x.com', 'name' => 'John']],
            'header_cc'   => [['email' => 'jane@x.com', 'name' => 'Jane']],
            'envelope_to' => 'jane@x.com',
            'subject'     => '[FLOWONE-TEST] dedup',
            'body_html'   => '<p>hi</p>',
            'dry_run'     => true,
        ]);
        $ccHdr = strtolower($headerValue($res['raw_message'] ?? '', 'Cc'));
        assert_true(str_contains($ccHdr, 'jane@x.com'), 'Cc must contain jane');
        assert_true(!str_contains($ccHdr, 'john@x.com'), 'Cc must NOT contain john (To wins)');
    });

    test('Empty To + populated Cc => visible "To: undisclosed-recipients:;" header', function () use ($makeSmtp, $headerValue) {
        $smtp = $makeSmtp();
        $res = $smtp->send([
            'header_to'   => [],
            'header_cc'   => [['email' => 'user1@x.com'], ['email' => 'user2@x.com']],
            'envelope_to' => 'user1@x.com',
            'subject'     => '[FLOWONE-TEST] cc only',
            'body_html'   => '<p>hi</p>',
            'dry_run'     => true,
        ]);
        $raw   = $res['raw_message'] ?? '';
        $toHdr = strtolower($headerValue($raw, 'To'));
        assert_true(str_contains($toHdr, 'undisclosed-recipients'), "expected undisclosed-recipients To header, got: {$toHdr}");
        $envelope = array_map('strtolower', $res['envelope'] ?? []);
        assert_eq(['user1@x.com'], $envelope, 'envelope must be the single recipient');
    });

    test('Legacy mode (no envelope_to) keeps To+Cc in the envelope', function () use ($makeSmtp, $headerValue) {
        // Backward-compat guard: without envelope_to, behaviour is unchanged -
        // to/cc drive both the headers and the SMTP envelope.
        $smtp = $makeSmtp();
        $res = $smtp->send([
            'to'        => [['email' => 'alice@x.com', 'name' => 'Alice']],
            'cc'        => [['email' => 'bob@y.com', 'name' => 'Bob']],
            'subject'   => '[FLOWONE-TEST] legacy',
            'body_html' => '<p>hi</p>',
            'dry_run'   => true,
        ]);
        $envelope = array_map('strtolower', $res['envelope'] ?? []);
        sort($envelope);
        assert_eq(['alice@x.com', 'bob@y.com'], $envelope, 'legacy envelope must include both To and Cc');
    });

    // importance: 'high' must emit the standard high-priority header set on
    // every delivered copy; a normal send must emit none of them.
    test("importance=high emits X-Priority/Importance/Priority/X-MSMail-Priority", function () use ($makeSmtp, $headerValue) {
        $smtp = $makeSmtp();
        $res = $smtp->send([
            'header_to'   => [['email' => 'alice@x.com', 'name' => 'Alice']],
            'envelope_to' => 'alice@x.com',
            'subject'     => '[FLOWONE-TEST] important',
            'body_html'   => '<p>hi</p>',
            'importance'  => 'high',
            'dry_run'     => true,
        ]);
        $raw = $res['raw_message'] ?? '';
        assert_eq('1',     trim($headerValue($raw, 'X-Priority')),        'X-Priority must be 1');
        assert_eq('High',  trim($headerValue($raw, 'Importance')),        'Importance must be High');
        assert_eq('urgent', strtolower(trim($headerValue($raw, 'Priority'))), 'Priority must be urgent');
        assert_eq('High',  trim($headerValue($raw, 'X-MSMail-Priority')), 'X-MSMail-Priority must be High');
    });

    test("normal send (no importance) emits no priority headers", function () use ($makeSmtp, $headerValue) {
        $smtp = $makeSmtp();
        $res = $smtp->send([
            'header_to'   => [['email' => 'alice@x.com', 'name' => 'Alice']],
            'envelope_to' => 'alice@x.com',
            'subject'     => '[FLOWONE-TEST] normal',
            'body_html'   => '<p>hi</p>',
            'dry_run'     => true,
        ]);
        $raw = $res['raw_message'] ?? '';
        assert_eq('', trim($headerValue($raw, 'Importance')),        'Importance must be absent on a normal send');
        assert_eq('', trim($headerValue($raw, 'X-MSMail-Priority')), 'X-MSMail-Priority must be absent on a normal send');
        // PHPMailer emits "X-Priority: 3 (Normal)" only when Priority is set;
        // since we never set it, the header must be absent entirely.
        assert_eq('', trim($headerValue($raw, 'X-Priority')),        'X-Priority must be absent on a normal send');
    });

    test("buildDraftMessage carries importance headers for the Sent/draft copy", function () use ($makeSmtp, $headerValue) {
        $smtp = $makeSmtp();
        $raw = $smtp->buildDraftMessage([
            'to'         => [['email' => 'alice@x.com', 'name' => 'Alice']],
            'subject'    => '[FLOWONE-TEST] sent-copy important',
            'body_html'  => '<p>hi</p>',
            'importance' => 'high',
        ]);
        assert_eq('1',    trim($headerValue($raw, 'X-Priority')), 'Sent copy X-Priority must be 1');
        assert_eq('High', trim($headerValue($raw, 'Importance')), 'Sent copy Importance must be High');
    });
}

// ── Group: reply-all-filter (mirrors stores/compose.js setupReply) ───

if (shouldRun('reply-all-filter')) {
    out("\n--- 4. REPLY-ALL FILTER (mirror of setupReply) ---");

    /**
     * Re-implementation of the JS filter in PHP so we can unit-test the rule
     * without spinning up a JS runtime. Must stay in sync with the JS code
     * at email/frontend/src/stores/compose.js and composeWindowService.js.
     *
     * The JS flow is:
     *   1. draft.to     = [reply_to[0]] (or [from[0]])
     *   2. fromAddress  = if the primary login is NOT among original To/Cc
     *                     recipients, pick the first non-primary linked
     *                     address that is; otherwise keep the current primary
     *                     (user is reading that inbox).
     *   3. draft.cc     = original.to ++ original.cc, with three filters:
     *                     - email != current From identity
     *                     - email not already in draft.to (sender)
     *                     - dedupe (first occurrence wins)
     *
     * Note: we deliberately do NOT filter every linked account or the
     * primary login - only the address the reply will be sent as. That
     * way the user's other identities still receive a copy, matching
     * Gmail/Outlook behavior.
     */
    $simulateReplyAll = function (array $original, array $sendAddresses, ?string $primaryFromAddrEmail) {
        // Step 1: draft.to from reply_to/from
        $replyTo = $original['reply_to'][0] ?? $original['from'][0] ?? null;
        $draftTo = $replyTo ? [$replyTo] : [];

        // Step 2: From auto-selection (mirrors JS logic exactly)
        $recipientEmails = [];
        foreach (array_merge($original['to'] ?? [], $original['cc'] ?? []) as $t) {
            if (!empty($t['email'])) {
                $recipientEmails[] = strtolower($t['email']);
            }
        }
        $primaryAddr = null;
        foreach ($sendAddresses as $a) {
            if (!empty($a['is_primary']) && !empty($a['email'])) {
                $primaryAddr = $a;
                break;
            }
        }
        $fromAddrEmail = $primaryFromAddrEmail;
        $primaryReceived = $primaryAddr
            && in_array(strtolower($primaryAddr['email']), $recipientEmails, true);
        if (!$primaryReceived) {
            foreach ($sendAddresses as $a) {
                if (!empty($a['is_primary'])) {
                    continue;
                }
                $ae = strtolower($a['email'] ?? '');
                if ($ae !== '' && in_array($ae, $recipientEmails, true)) {
                    $fromAddrEmail = $a['email'];
                    break;
                }
            }
        }

        // Step 3: Build CC
        $fromEmailLc = $fromAddrEmail ? strtolower($fromAddrEmail) : '';
        $toEmails = [];
        foreach ($draftTo as $r) {
            if (!empty($r['email'])) $toEmails[strtolower($r['email'])] = true;
        }

        $seen = [];
        $cc = [];
        foreach (array_merge($original['to'] ?? [], $original['cc'] ?? []) as $r) {
            $email = strtolower($r['email'] ?? '');
            if ($email === '') continue;
            if ($fromEmailLc !== '' && $email === $fromEmailLc) continue;
            if (isset($toEmails[$email])) continue;
            if (isset($seen[$email])) continue;
            $seen[$email] = true;
            $cc[] = $r;
        }

        return [
            'from' => $fromAddrEmail,
            'to'   => $draftTo,
            'cc'   => $cc,
        ];
    };

    test('Real screenshot scenario: multi-identity user replies-all and gets the OTHER identity in CC', function () use ($simulateReplyAll) {
        // Reproduces the bug from the user's screenshot:
        //   From: Robert Fekete <feketeroberto@gmail.com>
        //   To:   robert@pixelranger.hu, pixelrangerstudio@gmail.com,
        //         feketeroberto@gmail.com
        // User's primary login is robert@flowone.pro; both pixelranger
        // accounts are linked. Reply-all should pick one of the linked
        // accounts as From (the one in the original To) and put the OTHER
        // linked account in CC.
        $original = [
            'from'     => [['email' => 'feketeroberto@gmail.com', 'name' => 'Robert Fekete']],
            'reply_to' => [['email' => 'feketeroberto@gmail.com', 'name' => 'Robert Fekete']],
            'to' => [
                ['email' => 'robert@pixelranger.hu',         'name' => 'Fekete Roberto'],
                ['email' => 'pixelrangerstudio@gmail.com',   'name' => 'Pixel Ranger Studio'],
                ['email' => 'feketeroberto@gmail.com',       'name' => 'Robert Fekete'],
            ],
            'cc' => [],
        ];
        $sendAddresses = [
            ['email' => 'robert@flowone.pro',          'is_primary' => true],
            ['email' => 'pixelrangerstudio@gmail.com', 'is_primary' => false],
            ['email' => 'robert@pixelranger.hu',       'is_primary' => false],
        ];

        $out = $simulateReplyAll($original, $sendAddresses, 'robert@flowone.pro');

        // From should have auto-flipped to the FIRST non-primary linked
        // account that's in the original.to (= pixelrangerstudio@gmail.com).
        assert_eq('pixelrangerstudio@gmail.com', strtolower($out['from'] ?? ''), 'From should auto-select the linked account that received the mail');

        // To should be the original sender only.
        $toEmails = array_map(fn($r) => strtolower($r['email']), $out['to']);
        assert_eq(['feketeroberto@gmail.com'], $toEmails, 'To should be the original sender');

        // CC should contain robert@pixelranger.hu (the OTHER linked
        // identity). It must NOT contain the From, and must NOT contain
        // the sender (which is in To).
        $ccEmails = array_map(fn($r) => strtolower($r['email']), $out['cc']);
        assert_true(in_array('robert@pixelranger.hu', $ccEmails, true), 'other linked identity must remain in CC');
        assert_true(!in_array('pixelrangerstudio@gmail.com', $ccEmails, true), 'current From must NOT appear in CC');
        assert_true(!in_array('feketeroberto@gmail.com', $ccEmails, true), 'sender (already in To) must NOT duplicate into CC');
        assert_eq(1, count($ccEmails), 'CC should have exactly one recipient in this scenario');
    });

    test('Reply-All never duplicates an address that is already in To', function () use ($simulateReplyAll) {
        $original = [
            'from'     => [['email' => 'sender@x.com']],
            'reply_to' => [['email' => 'sender@x.com']],
            'to'       => [['email' => 'sender@x.com'], ['email' => 'other@y.com']],
            'cc'       => [['email' => 'other@y.com']],
        ];
        $out = $simulateReplyAll($original, [], null);
        $ccEmails = array_map(fn($r) => strtolower($r['email']), $out['cc']);
        assert_eq(['other@y.com'], $ccEmails, 'sender@x.com must not appear twice');
    });

    test('Reply-All deduplicates within Cc even without any sendAddresses', function () use ($simulateReplyAll) {
        $original = [
            'from'     => [['email' => 'sender@x.com']],
            'reply_to' => [['email' => 'sender@x.com']],
            'to'       => [['email' => 'a@x.com'], ['email' => 'a@x.com']],
            'cc'       => [['email' => 'a@x.com']],
        ];
        $out = $simulateReplyAll($original, [], null);
        assert_eq(1, count($out['cc']), 'duplicates within original to/cc must collapse');
        assert_eq('a@x.com', strtolower($out['cc'][0]['email']), '');
    });

    test('Current From identity is the only self-address filtered from CC', function () use ($simulateReplyAll) {
        // Even when several linked accounts appear in original.to, only
        // the active From identity is filtered. This is the inverse of
        // the earlier (too-aggressive) implementation.
        $original = [
            'from'     => [['email' => 'sender@x.com']],
            'reply_to' => [['email' => 'sender@x.com']],
            'to' => [
                ['email' => 'linkedA@me.com'],
                ['email' => 'linkedB@me.com'],
                ['email' => 'friend@y.com'],
            ],
            'cc' => [],
        ];
        $sendAddresses = [
            ['email' => 'primary@me.com',  'is_primary' => true],
            ['email' => 'linkedA@me.com',  'is_primary' => false],
            ['email' => 'linkedB@me.com',  'is_primary' => false],
        ];
        $out = $simulateReplyAll($original, $sendAddresses, 'primary@me.com');

        assert_eq('linkeda@me.com', strtolower($out['from'] ?? ''), 'First matching linked acct becomes From');
        $ccEmails = array_map(fn($r) => strtolower($r['email']), $out['cc']);
        // linkedA is the current From => filtered. linkedB stays.
        sort($ccEmails);
        assert_eq(['friend@y.com', 'linkedb@me.com'], $ccEmails, 'Only the current From should be filtered');
    });

    test('Email sent to BOTH primary and linked: From stays primary, linked goes to CC', function () use ($simulateReplyAll) {
        $original = [
            'from'     => [['email' => 'sender@x.com']],
            'reply_to' => [['email' => 'sender@x.com']],
            'to' => [
                ['email' => 'primary@me.com'],
                ['email' => 'linked@me.com'],
            ],
            'cc' => [],
        ];
        $sendAddresses = [
            ['email' => 'primary@me.com', 'is_primary' => true],
            ['email' => 'linked@me.com',  'is_primary' => false],
        ];
        $out = $simulateReplyAll($original, $sendAddresses, 'primary@me.com');

        assert_eq('primary@me.com', strtolower($out['from'] ?? ''), 'From stays primary when primary received the mail');
        $ccEmails = array_map(fn($r) => strtolower($r['email']), $out['cc']);
        assert_eq(['linked@me.com'], $ccEmails, 'Linked account must appear in CC, not primary');
    });

    test('User scenario: primary=robert@pixelranger.hu, linked=pixelrangerstudio - reply-all keeps primary as From', function () use ($simulateReplyAll) {
        $original = [
            'from'     => [['email' => 'feketeroberto@gmail.com']],
            'reply_to' => [['email' => 'feketeroberto@gmail.com']],
            'to' => [
                ['email' => 'robert@pixelranger.hu'],
                ['email' => 'pixelrangerstudio@gmail.com'],
            ],
            'cc' => [],
        ];
        $sendAddresses = [
            ['email' => 'robert@pixelranger.hu',       'is_primary' => true],
            ['email' => 'pixelrangerstudio@gmail.com', 'is_primary' => false],
        ];
        $out = $simulateReplyAll($original, $sendAddresses, 'robert@pixelranger.hu');

        assert_eq('robert@pixelranger.hu', strtolower($out['from'] ?? ''),
            'From must stay on the primary login - the inbox the user is reading');
        assert_eq(
            ['feketeroberto@gmail.com'],
            array_map(fn($r) => strtolower($r['email']), $out['to']),
            'To is the original sender'
        );
        assert_eq(
            ['pixelrangerstudio@gmail.com'],
            array_map(fn($r) => strtolower($r['email']), $out['cc']),
            'CC contains pixelrangerstudio (the other recipient), NOT primary'
        );
    });

    test('No linked account matches original.to => From stays primary and is filtered from CC', function () use ($simulateReplyAll) {
        $original = [
            'from'     => [['email' => 'sender@x.com']],
            'reply_to' => [['email' => 'sender@x.com']],
            'to' => [
                ['email' => 'primary@me.com'],
                ['email' => 'friend@y.com'],
            ],
            'cc' => [],
        ];
        $sendAddresses = [
            ['email' => 'primary@me.com', 'is_primary' => true],
            ['email' => 'linked@me.com',  'is_primary' => false],
        ];
        $out = $simulateReplyAll($original, $sendAddresses, 'primary@me.com');

        assert_eq('primary@me.com', strtolower($out['from'] ?? ''), 'From stays primary when no linked match');
        $ccEmails = array_map(fn($r) => strtolower($r['email']), $out['cc']);
        assert_eq(['friend@y.com'], $ccEmails, 'Primary (= current From) must be filtered, friend stays');
    });
}

// ── Summary / cleanup ────────────────────────────────────────────

function finish(): void {
    global $totalTests, $passed, $failed, $warnings, $results, $logFile, $jsonMode;
    global $C_GREEN, $C_RED, $C_YELLOW, $C_RESET, $C_DIM;

    if ($jsonMode) {
        echo json_encode([
            'total'    => $totalTests,
            'passed'   => $passed,
            'failed'   => $failed,
            'warnings' => $warnings,
            'log'      => $logFile,
            'results'  => $results,
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        out("\n=================================================================");
        out("  Summary");
        out("  Total:    {$totalTests}");
        out("  {$C_GREEN}Passed:   {$passed}{$C_RESET}");
        out("  {$C_RED}Failed:   {$failed}{$C_RESET}");
        out("  {$C_YELLOW}Warnings: {$warnings}{$C_RESET}");
        out("  Log:      {$logFile}");
        out("=================================================================");

        if ($failed > 0) {
            out("\nFailed tests:");
            foreach ($results as $r) {
                if (($r['status'] ?? '') === 'FAIL') {
                    out("  - {$r['name']}: " . ($r['error'] ?? '(no message)'));
                }
            }
        }
    }

    exit($failed > 0 ? 1 : 0);
}

finish();
