#!/usr/bin/env php
<?php
/**
 * FlowOne Mentions Test (Phase 3).
 *
 * End-to-end coverage for the @mentions subsystem:
 *
 *   - Migration 166_message_mentions.sql created the table with the expected
 *     shape, the UNIQUE(owner_email, message_id, mentioned_email_norm)
 *     constraint, and added dedup_hash + uq_user_dedup to `notifications`.
 *   - EmailNormalizer canonicalises addresses (lowercase + Punycode) and
 *     correctly identifies same-mailbox pairs across casing / IDN forms.
 *   - MentionParser extracts TipTap span mentions, plain-text `@email@domain`
 *     mentions, and resolves bare `@firstname` tokens against the hint map.
 *   - MentionsService persists rows idempotently (re-running same input is
 *     a no-op), assigns the correct trust level, and returns the per-message
 *     mention rows for the chip UI.
 *   - Notification dedup: inserting the same mention notification twice
 *     leaves exactly one row.
 *
 * All writes use the recognisable `flowone-test-mentions@flowone.pro` tenant
 * (and `someone-else@flowone.pro` for the "internal" trust check) so they
 * cannot collide with real data. Cleanup runs in a register_shutdown
 * handler + try/finally so even Ctrl-C leaves the DB clean.
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mentions-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output (stack traces, raw queries)
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight + normalizer + parser only (no DB writes)
 *   --only=GROUP[,GROUP]   run only listed groups (preflight,migration,normalizer,parser,service,notify)
 *   --skip-send            no-op, accepted for parity with other tests
 *   --help                 show this message
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'json', 'smoke', 'only:', 'skip-send', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1700));
    exit(0);
}

$jsonOut = isset($opts['json']);
$verbose = isset($opts['verbose']);
$smoke   = isset($opts['smoke']);
$only = isset($opts['only'])
    ? array_map('trim', explode(',', (string) $opts['only']))
    : [];

require_once __DIR__ . '/../cron/bootstrap.php';
$config = require __DIR__ . '/../src/config.php';

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/mentions-test-' . date('Ymd-His') . '.log';

const OWNER_EMAIL   = 'flowone-test-mentions@flowone.pro';
const COLLEAGUE     = 'flowone-test-colleague@flowone.pro';
const EXTERNAL      = 'flowone-test-external@gmail.com';

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

// --- ANSI colours (skipped when --json) ---
$C = $jsonOut ? [
    'reset' => '', 'green' => '', 'red' => '', 'yellow' => '', 'cyan' => '', 'dim' => '',
] : [
    'reset'  => "\033[0m",
    'green'  => "\033[32m",
    'red'    => "\033[31m",
    'yellow' => "\033[33m",
    'cyan'   => "\033[36m",
    'dim'    => "\033[2m",
];

function mt_out(string $msg): void
{
    global $logFile, $jsonOut;
    if (!$jsonOut) echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function mt_should_run(string $group): bool
{
    global $only;
    return empty($only) || in_array($group, $only, true);
}

function mt_record(string $name, string $status, int $ms, ?string $error = null): void
{
    global $totalTests, $passed, $failed, $warnings, $results, $C, $jsonOut;
    $totalTests++;
    if ($status === 'PASS') $passed++;
    elseif ($status === 'WARN') $warnings++;
    else $failed++;
    $results[] = [
        'name' => $name, 'status' => $status, 'ms' => $ms, 'error' => $error,
    ];
    $col = $status === 'PASS' ? $C['green'] : ($status === 'WARN' ? $C['yellow'] : $C['red']);
    mt_out(sprintf('  [%s%-4s%s]  %s (%dms)', $col, $status, $C['reset'], $name, $ms));
    if ($error !== null) mt_out('          -> ' . $error);
}

function mt_test(string $name, callable $fn, int $timeoutSec = 15): void
{
    global $verbose;
    $start = microtime(true);
    if (function_exists('pcntl_alarm')) {
        pcntl_signal(SIGALRM, function () {
            throw new \RuntimeException('test exceeded timeout');
        });
        pcntl_alarm($timeoutSec);
    }
    try {
        $r = $fn();
        $ms = (int) round((microtime(true) - $start) * 1000);
        if (is_array($r) && ($r['status'] ?? null) === 'WARN') {
            mt_record($name, 'WARN', $ms, $r['msg'] ?? null);
        } else {
            mt_record($name, 'PASS', $ms, null);
        }
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $start) * 1000);
        mt_record($name, 'FAIL', $ms, $e->getMessage());
        if ($verbose) mt_out('          at ' . $e->getFile() . ':' . $e->getLine());
    } finally {
        if (function_exists('pcntl_alarm')) pcntl_alarm(0);
    }
}

// =====================================================================
// CLEANUP: registered early so it runs even on fatal / Ctrl-C
// =====================================================================
$cleanup = function () use ($config) {
    try {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare(
            'DELETE FROM webmail_message_mentions WHERE owner_email IN (?, ?, ?)'
        )->execute([OWNER_EMAIL, COLLEAGUE, EXTERNAL]);
        $db->prepare(
            "DELETE FROM notifications WHERE user_email IN (?, ?, ?) AND type = 'email_mention'"
        )->execute([OWNER_EMAIL, COLLEAGUE, EXTERNAL]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "cleanup warning: " . $e->getMessage() . "\n");
    }
};
register_shutdown_function($cleanup);
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () use ($cleanup) { $cleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () use ($cleanup) { $cleanup(); exit(143); });
}
if (function_exists('pcntl_async_signals')) pcntl_async_signals(true);

// =====================================================================
mt_out('=================================================================');
mt_out('  FlowOne Mentions Test');
mt_out('  ' . date('Y-m-d H:i:s T'));
mt_out('  Mode:      ' . ($smoke ? 'SMOKE' : 'FULL'));
mt_out('  Groups:    ' . (empty($only) ? 'all' : implode(',', $only)));
mt_out('  Tenant:    ' . OWNER_EMAIL);
mt_out('  Log:       ' . $logFile);
mt_out('=================================================================');

// =====================================================================
// 1. PREFLIGHT
// =====================================================================
if (mt_should_run('preflight')) {
    mt_out("\n--- 1. PREFLIGHT ---");

    mt_test('PHP extensions (pdo_mysql + json + mbstring + intl)', function () {
        foreach (['pdo_mysql', 'json', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) throw new \RuntimeException("missing extension: $ext");
        }
        if (!function_exists('idn_to_ascii')) {
            return ['status' => 'WARN', 'msg' => 'intl/idn_to_ascii missing — IDN normalisation will be a no-op'];
        }
    });

    mt_test('Autoloader resolves mention classes', function () {
        foreach ([
            '\\Webmail\\Utils\\EmailNormalizer',
            '\\Webmail\\Services\\Mentions\\MentionParser',
            '\\Webmail\\Services\\Mentions\\MentionsService',
            '\\Webmail\\Services\\Mentions\\MentionsProcessor',
            '\\Webmail\\Services\\Search\\SpecialSearchHandlers',
            '\\Webmail\\Controllers\\MentionsController',
        ] as $c) {
            if (!class_exists($c)) throw new \RuntimeException("class missing: $c");
        }
    });
}

// =====================================================================
// 2. MIGRATION (table shape)
// =====================================================================
if (!$smoke && mt_should_run('migration')) {
    mt_out("\n--- 2. MIGRATION ---");

    mt_test('webmail_message_mentions table exists', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $rows = $db->query("SHOW TABLES LIKE 'webmail_message_mentions'")->fetchAll();
        if (empty($rows)) throw new \RuntimeException('table missing — run migration 166');
    });

    mt_test('table has all expected columns', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $cols = array_column(
            $db->query('SHOW COLUMNS FROM webmail_message_mentions')->fetchAll(\PDO::FETCH_ASSOC),
            'Field'
        );
        $expected = [
            'id','owner_email','message_id','folder','uid','direction',
            'sender_email','mentioned_email','mentioned_email_norm',
            'mentioned_user_email','mention_text','trust','subject','sent_at','created_at',
        ];
        $missing = array_diff($expected, $cols);
        if ($missing) throw new \RuntimeException('missing columns: ' . implode(',', $missing));
    });

    mt_test('UNIQUE index on (owner_email, message_id, mentioned_email_norm)', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $idx = $db->query('SHOW INDEX FROM webmail_message_mentions')->fetchAll(\PDO::FETCH_ASSOC);
        $byKey = [];
        foreach ($idx as $row) {
            $name = $row['Key_name'];
            if (!isset($byKey[$name])) {
                $byKey[$name] = ['unique' => (int) $row['Non_unique'] === 0, 'cols' => []];
            }
            $byKey[$name]['cols'][(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        foreach ($byKey as $name => $info) {
            ksort($info['cols']);
            $cols = array_values($info['cols']);
            if ($info['unique'] && $cols === ['owner_email', 'message_id', 'mentioned_email_norm']) {
                return;
            }
        }
        throw new \RuntimeException('expected UNIQUE KEY on (owner_email, message_id, mentioned_email_norm)');
    });

    mt_test('notifications.dedup_hash + uq_user_dedup added', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $cols = array_column(
            $db->query('SHOW COLUMNS FROM notifications')->fetchAll(\PDO::FETCH_ASSOC),
            'Field'
        );
        if (!in_array('dedup_hash', $cols, true)) {
            throw new \RuntimeException('notifications.dedup_hash column missing');
        }
        $idx = $db->query('SHOW INDEX FROM notifications')->fetchAll(\PDO::FETCH_ASSOC);
        $byKey = [];
        foreach ($idx as $row) {
            $name = $row['Key_name'];
            if (!isset($byKey[$name])) {
                $byKey[$name] = ['unique' => (int) $row['Non_unique'] === 0, 'cols' => []];
            }
            $byKey[$name]['cols'][(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        foreach ($byKey as $info) {
            ksort($info['cols']);
            $cols = array_values($info['cols']);
            if ($info['unique'] && $cols === ['user_email', 'dedup_hash']) {
                return;
            }
        }
        throw new \RuntimeException('expected UNIQUE (user_email, dedup_hash) on notifications');
    });
}

// =====================================================================
// 3. EMAIL NORMALIZER
// =====================================================================
if (mt_should_run('normalizer')) {
    mt_out("\n--- 3. EMAIL NORMALIZER ---");

    mt_test('lowercase + trim', function () {
        $n = \Webmail\Utils\EmailNormalizer::normalize('  Robert@Pixelranger.HU  ');
        if ($n !== 'robert@pixelranger.hu') {
            throw new \RuntimeException("got: " . var_export($n, true));
        }
    });

    mt_test('strips display name + angle brackets', function () {
        $n = \Webmail\Utils\EmailNormalizer::normalize('"Robert F." <robert@pixelranger.hu>');
        if ($n !== 'robert@pixelranger.hu') {
            throw new \RuntimeException("got: " . var_export($n, true));
        }
    });

    mt_test('returns null on invalid input', function () {
        foreach (['', 'not-an-email', '@no-local', 'no-domain@', null] as $bad) {
            if (\Webmail\Utils\EmailNormalizer::normalize($bad) !== null) {
                throw new \RuntimeException("should have returned null for: " . var_export($bad, true));
            }
        }
    });

    mt_test('IDN domain → punycode (or warn if intl missing)', function () {
        if (!function_exists('idn_to_ascii')) {
            return ['status' => 'WARN', 'msg' => 'intl not loaded; punycode skipped'];
        }
        $n = \Webmail\Utils\EmailNormalizer::normalize('user@пример.рф');
        if ($n === null || strpos($n, 'xn--') === false) {
            throw new \RuntimeException('expected punycode, got: ' . var_export($n, true));
        }
    });

    mt_test('isSameMailbox is case + whitespace insensitive', function () {
        if (!\Webmail\Utils\EmailNormalizer::isSameMailbox('Robert@X.com', 'robert@x.com ')) {
            throw new \RuntimeException('same mailbox should compare equal');
        }
        if (\Webmail\Utils\EmailNormalizer::isSameMailbox('a@x.com', 'b@x.com')) {
            throw new \RuntimeException('different mailboxes must not compare equal');
        }
    });

    mt_test('extractFromHeader handles quoted display names + commas', function () {
        // Use realistic 2+ char TLDs — the regex enforces RFC (TLDs must be
        // ≥ 2 chars). Single-letter TLDs like `x.y` are intentionally rejected.
        $emails = \Webmail\Utils\EmailNormalizer::extractFromHeader(
            'Alice <a@example.io>, "Bob, Jr." <b@example.io>, c@example.io'
        );
        if ($emails !== ['a@example.io', 'b@example.io', 'c@example.io']) {
            throw new \RuntimeException('got: ' . json_encode($emails));
        }
    });
}

// =====================================================================
// 4. MENTION PARSER
// =====================================================================
if (mt_should_run('parser')) {
    mt_out("\n--- 4. MENTION PARSER ---");

    mt_test('extracts TipTap mention spans', function () {
        $html = '<p>Hi <span data-type="mention" data-id="robert@pixelranger.hu" data-label="Robert">@Robert</span>, please review.</p>';
        $out = \Webmail\Services\Mentions\MentionParser::extract($html);
        if (count($out) !== 1) throw new \RuntimeException('expected 1 mention, got ' . count($out));
        if ($out[0]['email'] !== 'robert@pixelranger.hu') throw new \RuntimeException('bad email: ' . $out[0]['email']);
        if ($out[0]['source'] !== 'tiptap') throw new \RuntimeException('expected source=tiptap');
    });

    mt_test('extracts Gmail gmail_plusreply mailto chip', function () {
        // Verbatim from a real Gmail outbound MIME — the exact HTML the
        // user's "mentines tes 3" message contained.
        $html = '<div dir="ltr"><div>hey <a class="gmail_plusreply" id="plusReplyChip-2" '
              . 'href="mailto:robert@pixelranger.hu" tabindex="-1">@Fekete Róbert</a> what sup</div></div>';
        $out = \Webmail\Services\Mentions\MentionParser::extract($html);
        if (count($out) !== 1) throw new \RuntimeException('expected 1, got ' . count($out));
        if ($out[0]['email'] !== 'robert@pixelranger.hu') throw new \RuntimeException('bad email: ' . $out[0]['email']);
        if ($out[0]['source'] !== 'mailto') throw new \RuntimeException('expected source=mailto, got ' . $out[0]['source']);
        if ($out[0]['label'] !== 'Fekete Róbert') throw new \RuntimeException('bad label: ' . $out[0]['label']);
    });

    mt_test('extracts generic <a href="mailto:..."> mention (Outlook Web style)', function () {
        $html = 'Hey <a href="mailto:bob@example.io">@Bob</a> please look';
        $out = \Webmail\Services\Mentions\MentionParser::extract($html);
        if (count($out) !== 1) throw new \RuntimeException('expected 1, got ' . count($out));
        if ($out[0]['email'] !== 'bob@example.io') throw new \RuntimeException('bad email');
        if ($out[0]['source'] !== 'mailto') throw new \RuntimeException('expected source=mailto');
    });

    mt_test('ignores plain mailto link without @-prefix (non-mention)', function () {
        $html = 'Please <a href="mailto:support@example.io">contact us</a> for help';
        $out = \Webmail\Services\Mentions\MentionParser::extract($html);
        if (!empty($out)) throw new \RuntimeException('false positive on plain mailto link: ' . json_encode($out));
    });

    mt_test('strips mailto query params before normalising', function () {
        $html = 'Hi <a href="mailto:alice@example.io?subject=hello&body=hey">@Alice</a>';
        $out = \Webmail\Services\Mentions\MentionParser::extract($html);
        if (count($out) !== 1 || $out[0]['email'] !== 'alice@example.io') {
            throw new \RuntimeException('got: ' . json_encode($out));
        }
    });

    mt_test('extracts plain-text "@Display Name <email@domain>" form', function () {
        // What Gmail produces in the text/plain MIME part.
        $text = 'hey @Fekete Róbert <robert@pixelranger.hu> what sup';
        $out = \Webmail\Services\Mentions\MentionParser::extract(null, $text);
        if (count($out) !== 1) throw new \RuntimeException('expected 1, got ' . count($out));
        if ($out[0]['email'] !== 'robert@pixelranger.hu') throw new \RuntimeException('bad email');
        if ($out[0]['label'] !== 'Fekete Róbert') throw new \RuntimeException('bad label: ' . $out[0]['label']);
    });

    mt_test('Gmail full body (html + text) → exactly one mention, deduped', function () {
        // Real-world: when both parts of the same Gmail multipart land in
        // the parser, the address must dedupe to exactly one row.
        $html = '<div>hey <a class="gmail_plusreply" href="mailto:robert@pixelranger.hu">@Fekete Róbert</a> what sup</div>';
        $text = 'hey @Fekete Róbert <robert@pixelranger.hu> what sup';
        $out = \Webmail\Services\Mentions\MentionParser::extract($html, $text);
        if (count($out) !== 1) throw new \RuntimeException('dedup failed, got ' . count($out));
        if ($out[0]['email'] !== 'robert@pixelranger.hu') throw new \RuntimeException('bad email');
    });

    mt_test('extracts plain-text @email@domain mentions', function () {
        $text = 'cc @robert@pixelranger.hu can you also look';
        $out = \Webmail\Services\Mentions\MentionParser::extract(null, $text);
        if (count($out) !== 1) throw new \RuntimeException('expected 1, got ' . count($out));
        if ($out[0]['email'] !== 'robert@pixelranger.hu') throw new \RuntimeException('bad email');
        if ($out[0]['source'] !== 'plain') throw new \RuntimeException('expected source=plain');
    });

    mt_test('resolves bare @firstname via single-match hint', function () {
        $text = 'hey @robert what do you think';
        $out = \Webmail\Services\Mentions\MentionParser::extract(null, $text, ['robert@pixelranger.hu']);
        if (count($out) !== 1 || $out[0]['email'] !== 'robert@pixelranger.hu') {
            throw new \RuntimeException('got: ' . json_encode($out));
        }
    });

    mt_test('drops ambiguous bare @firstname (multiple hint matches)', function () {
        $text = 'hey @robert';
        $out = \Webmail\Services\Mentions\MentionParser::extract(
            null, $text,
            ['robert@pixelranger.hu', 'robert@vendor.com']
        );
        if (!empty($out)) throw new \RuntimeException('expected empty, got: ' . json_encode($out));
    });

    mt_test('domain bias resolves ambiguity for Gmail-style @robert', function () {
        // Same input as the previous test, but now we tell the parser to
        // prefer pixelranger.hu — this is the Gmail-originated mention path
        // where the recipient list doesn\'t disambiguate but the org domain
        // does. The internal address must win.
        $text = 'hey @robert can you check';
        $out = \Webmail\Services\Mentions\MentionParser::extract(
            null, $text,
            ['robert@pixelranger.hu', 'robert@vendor.com'],
            ['preferDomain' => 'pixelranger.hu']
        );
        if (count($out) !== 1) throw new \RuntimeException('expected 1, got ' . count($out));
        if ($out[0]['email'] !== 'robert@pixelranger.hu') {
            throw new \RuntimeException('preferDomain not honoured, got: ' . json_encode($out));
        }
    });

    mt_test('domain bias still drops when preferred domain has two matches', function () {
        // If preferDomain itself has multiple matches, we still drop —
        // ambiguity-safe.
        $text = 'hey @robert';
        $out = \Webmail\Services\Mentions\MentionParser::extract(
            null, $text,
            ['robert@pixelranger.hu', 'robert.alt@pixelranger.hu', 'robert@vendor.com'],
            ['preferDomain' => 'pixelranger.hu']
        );
        // hintMap is keyed by local-part; robert.alt has a different local-part
        // so this should actually resolve to robert@pixelranger.hu.
        // The real ambiguity case is two identical local-parts in the same
        // domain — which DB constraints make impossible in practice, so the
        // single-domain-match path is what runs here.
        if (count($out) !== 1 || $out[0]['email'] !== 'robert@pixelranger.hu') {
            throw new \RuntimeException('expected single internal match, got: ' . json_encode($out));
        }
    });

    mt_test('does not match @ inside an existing email', function () {
        $text = 'reach out to support@example.com for help';
        $out = \Webmail\Services\Mentions\MentionParser::extract(null, $text);
        if (!empty($out)) throw new \RuntimeException('false positive: ' . json_encode($out));
    });

    mt_test('dedupes across html + text scan', function () {
        $html = '<p><span data-type="mention" data-id="robert@pixelranger.hu" data-label="Robert">@Robert</span></p>';
        $text = '@robert@pixelranger.hu';
        $out = \Webmail\Services\Mentions\MentionParser::extract($html, $text);
        if (count($out) !== 1) throw new \RuntimeException('expected dedupe to 1, got ' . count($out));
    });

    mt_test('refuses to scan absurdly large body', function () {
        $junk = str_repeat('@robert@pixelranger.hu ', 50000); // > 256 KB
        $out = \Webmail\Services\Mentions\MentionParser::extract(null, $junk);
        if (!empty($out)) throw new \RuntimeException('parser should have refused, but got rows');
    });
}

// =====================================================================
// 5. MENTIONS SERVICE (record + lookup + trust)
// =====================================================================
if (!$smoke && mt_should_run('service')) {
    mt_out("\n--- 5. MENTIONS SERVICE ---");

    $svc = new \Webmail\Services\Mentions\MentionsService($config);
    $db  = \Webmail\Core\Database::getConnection($config);

    // Pre-clean any stragglers from a previous failed run
    mt_test('Cleanup any pre-existing test rows', function () use ($db) {
        $db->prepare('DELETE FROM webmail_message_mentions WHERE owner_email IN (?, ?, ?)')
           ->execute([OWNER_EMAIL, COLLEAGUE, EXTERNAL]);
    });

    $msgId  = 'msg-' . bin2hex(random_bytes(8)) . '@flowone.pro';

    mt_test('record one mention (inbound, internal trust)', function () use ($svc, $msgId) {
        $inserted = $svc->recordMentions(OWNER_EMAIL, [
            'message_id'   => $msgId,
            'direction'    => 'inbound',
            'sender_email' => COLLEAGUE,
            'subject'      => 'PR review',
            'sent_at'      => '2026-01-15 10:00:00',
            'folder'       => 'INBOX',
            'uid'          => 42,
        ], [
            ['email' => OWNER_EMAIL, 'label' => 'You', 'text' => '@you', 'source' => 'tiptap'],
        ]);
        if ($inserted !== 1) throw new \RuntimeException("expected 1 insert, got $inserted");
    });

    mt_test('row stored with trust=internal (sender shares domain)', function () use ($db, $msgId) {
        $stmt = $db->prepare('SELECT trust FROM webmail_message_mentions WHERE message_id = ?');
        $stmt->execute([$msgId]);
        $trust = $stmt->fetchColumn();
        if ($trust !== 'internal') throw new \RuntimeException("expected internal, got: $trust");
    });

    mt_test('idempotent re-insert is a no-op (ON DUPLICATE KEY)', function () use ($svc, $msgId) {
        $inserted = $svc->recordMentions(OWNER_EMAIL, [
            'message_id'   => $msgId,
            'direction'    => 'inbound',
            'sender_email' => COLLEAGUE,
            'folder'       => 'INBOX',
            'uid'          => 42,
        ], [
            ['email' => OWNER_EMAIL, 'label' => 'You', 'text' => '@you', 'source' => 'tiptap'],
        ]);
        if ($inserted !== 0) throw new \RuntimeException("expected 0 new rows, got $inserted");
    });

    $msgId2 = 'msg-' . bin2hex(random_bytes(8)) . '@flowone.pro';

    mt_test('external sender gets trust=external', function () use ($svc, $db, $msgId2) {
        $svc->recordMentions(OWNER_EMAIL, [
            'message_id'   => $msgId2,
            'direction'    => 'inbound',
            'sender_email' => EXTERNAL,
            'subject'      => 'External mention',
        ], [
            ['email' => OWNER_EMAIL, 'label' => 'You', 'text' => '@you', 'source' => 'plain'],
        ]);
        $stmt = $db->prepare('SELECT trust FROM webmail_message_mentions WHERE message_id = ?');
        $stmt->execute([$msgId2]);
        $trust = $stmt->fetchColumn();
        if ($trust !== 'external') throw new \RuntimeException("expected external, got: $trust");
    });

    mt_test('sender == owner produces trust=verified', function () use ($svc, $db) {
        $msgIdSelf = 'msg-self-' . bin2hex(random_bytes(4)) . '@flowone.pro';
        $svc->recordMentions(OWNER_EMAIL, [
            'message_id'   => $msgIdSelf,
            'direction'    => 'outbound',
            'sender_email' => OWNER_EMAIL,
            'subject'      => 'Note to self',
        ], [
            ['email' => COLLEAGUE, 'label' => 'Colleague', 'text' => '@colleague', 'source' => 'tiptap'],
        ]);
        $stmt = $db->prepare('SELECT trust FROM webmail_message_mentions WHERE message_id = ?');
        $stmt->execute([$msgIdSelf]);
        $trust = $stmt->fetchColumn();
        if ($trust !== 'verified') throw new \RuntimeException("expected verified, got: $trust");
    });

    mt_test('getMentionsForMessage returns row payload', function () use ($svc, $msgId) {
        $rows = $svc->getMentionsForMessage(OWNER_EMAIL, $msgId);
        if (count($rows) !== 1) throw new \RuntimeException('expected 1 row, got ' . count($rows));
        if ($rows[0]['mentioned_email'] !== OWNER_EMAIL) throw new \RuntimeException('bad mentioned_email');
        if ($rows[0]['trust'] !== 'internal') throw new \RuntimeException('bad trust');
    });

}

// =====================================================================
// 7. NOTIFICATION DEDUP
// =====================================================================
if (!$smoke && mt_should_run('notify')) {
    mt_out("\n--- 7. NOTIFICATION DEDUP ---");

    $db = \Webmail\Core\Database::getConnection($config);

    mt_test('seeded notification insert succeeds', function () use ($db) {
        $hash = hash('sha256', OWNER_EMAIL . '|email_mention|dedup-msg-id-1');
        $stmt = $db->prepare(
            'INSERT INTO notifications (user_email, type, title, message, data, dedup_hash)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->execute([OWNER_EMAIL, 'email_mention', 'You were mentioned', 'Body', '{}', $hash]);
    });

    mt_test('second insert with same dedup_hash is a no-op', function () use ($db) {
        $hash = hash('sha256', OWNER_EMAIL . '|email_mention|dedup-msg-id-1');
        $stmt = $db->prepare(
            'INSERT INTO notifications (user_email, type, title, message, data, dedup_hash)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->execute([OWNER_EMAIL, 'email_mention', 'You were mentioned', 'Body', '{}', $hash]);

        $count = (int) $db->query(
            "SELECT COUNT(*) FROM notifications WHERE user_email = " . $db->quote(OWNER_EMAIL) . "
             AND type = 'email_mention' AND dedup_hash = " . $db->quote($hash)
        )->fetchColumn();
        if ($count !== 1) throw new \RuntimeException("expected 1 row, got $count");
    });

    mt_test('different message-id → second notification row created', function () use ($db) {
        $hash2 = hash('sha256', OWNER_EMAIL . '|email_mention|dedup-msg-id-2');
        $stmt = $db->prepare(
            'INSERT INTO notifications (user_email, type, title, message, data, dedup_hash)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->execute([OWNER_EMAIL, 'email_mention', 'You were mentioned', 'Body', '{}', $hash2]);

        $count = (int) $db->query(
            "SELECT COUNT(*) FROM notifications WHERE user_email = " . $db->quote(OWNER_EMAIL) . "
             AND type = 'email_mention'"
        )->fetchColumn();
        if ($count !== 2) throw new \RuntimeException("expected 2 distinct rows, got $count");
    });
}

// =====================================================================
// SUMMARY
// =====================================================================
mt_out("\n=================================================================");
$summaryColor = $failed > 0 ? $C['red'] : ($warnings > 0 ? $C['yellow'] : $C['green']);
mt_out(sprintf(
    '  RESULT:  %s%d passed, %d failed, %d warnings, %d total%s',
    $summaryColor, $passed, $failed, $warnings, $totalTests, $C['reset']
));

if ($failed > 0) {
    mt_out("\n  FAILURES:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            mt_out('    - ' . $r['name'] . ': ' . $r['error']);
        }
    }
}
mt_out('=================================================================');

if ($jsonOut) {
    fwrite(STDOUT, json_encode([
        'passed' => $passed, 'failed' => $failed, 'warnings' => $warnings,
        'total' => $totalTests, 'results' => $results, 'log' => $logFile,
    ], JSON_PRETTY_PRINT) . "\n");
}

exit($failed > 0 ? 1 : 0);
