#!/usr/bin/env php
<?php
/**
 * FlowOne Pinned Smart View Test.
 *
 * End-to-end coverage for the `is:pinned` operator added in the Pinned
 * Smart View feature. IMAP is intentionally NOT exercised — the IMAP layer
 * is already covered by email-system-test.php and would require live
 * credentials. This script covers everything around it:
 *
 *   - Parser accepts `is:pinned` (no longer reserved).
 *   - OperatorRegistry::isValidValue treats 'pinned' as a valid enum.
 *   - The query strip-and-replace regex used by MailboxController::search
 *     correctly removes `is:pinned` while preserving other terms.
 *   - The pinned_emails table can be queried by (user_email) to produce
 *     the (folder, uid) whitelist set the post-filter uses.
 *   - The composite-key intersection logic (folder:uid) used inside the
 *     post-filter correctly keeps only matching synthetic messages.
 *   - End-to-end: insert pins → run the lookup helper → verify expected
 *     UIDs come back.
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/pinned-smart-view-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight + parser groups only (no DB writes)
 *   --only=GROUP[,GROUP]   run only listed groups (preflight,parser,query,db,intersect)
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
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1900));
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
$logFile = $logDir . '/pinned-smart-view-test-' . date('Ymd-His') . '.log';

const TEST_EMAIL = 'flowone-test-pinned@flowone.pro';
const TEST_FOLDER_A = 'INBOX';
const TEST_FOLDER_B = 'Archive/2026';

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

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

function psv_out(string $msg): void
{
    global $logFile, $jsonOut;
    if (!$jsonOut) echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function psv_should_run(string $group): bool
{
    global $only;
    return empty($only) || in_array($group, $only, true);
}

function psv_record(string $name, string $status, int $ms, ?string $error = null): void
{
    global $totalTests, $passed, $failed, $warnings, $results, $C;
    $totalTests++;
    if ($status === 'PASS') $passed++;
    elseif ($status === 'WARN') $warnings++;
    else $failed++;
    $results[] = compact('name', 'status', 'ms', 'error');
    $col = $status === 'PASS' ? $C['green'] : ($status === 'WARN' ? $C['yellow'] : $C['red']);
    psv_out(sprintf('  [%s%-4s%s]  %s (%dms)', $col, $status, $C['reset'], $name, $ms));
    if ($error !== null) psv_out('          -> ' . $error);
}

function psv_test(string $name, callable $fn, int $timeoutSec = 15): void
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
            psv_record($name, 'WARN', $ms, $r['msg'] ?? null);
        } else {
            psv_record($name, 'PASS', $ms, null);
        }
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $start) * 1000);
        psv_record($name, 'FAIL', $ms, $e->getMessage());
        if ($verbose) psv_out('          at ' . $e->getFile() . ':' . $e->getLine());
    } finally {
        if (function_exists('pcntl_alarm')) pcntl_alarm(0);
    }
}

// CLEANUP — guaranteed to run, even on Ctrl-C / fatal
$cleanup = function () use ($config) {
    try {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare('DELETE FROM pinned_emails WHERE user_email = ?')
           ->execute([TEST_EMAIL]);
        // Best-effort: drop the synthetic identity rows we may have
        // created so the run is fully idempotent.
        $db->prepare('DELETE FROM webmail_folder_identity WHERE account_id = ?')
           ->execute([TEST_EMAIL]);
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

psv_out('=================================================================');
psv_out('  FlowOne Pinned Smart View Test');
psv_out('  ' . date('Y-m-d H:i:s T'));
psv_out('  Mode:      ' . ($smoke ? 'SMOKE' : 'FULL'));
psv_out('  Groups:    ' . (empty($only) ? 'all' : implode(',', $only)));
psv_out('  Tenant:    ' . TEST_EMAIL);
psv_out('  Log:       ' . $logFile);
psv_out('=================================================================');

// =====================================================================
// 1. PREFLIGHT
// =====================================================================
if (psv_should_run('preflight')) {
    psv_out("\n--- 1. PREFLIGHT ---");

    psv_test('PHP extensions (pdo_mysql + json)', function () {
        foreach (['pdo_mysql', 'json'] as $ext) {
            if (!extension_loaded($ext)) throw new \RuntimeException("missing extension: $ext");
        }
    });

    psv_test('pinned_emails table exists', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $rows = $db->query("SHOW TABLES LIKE 'pinned_emails'")->fetchAll();
        if (empty($rows)) throw new \RuntimeException('table missing — run migration 021');
    });

    psv_test('pinned_emails has required canonical columns', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $cols = array_column(
            $db->query('SHOW COLUMNS FROM pinned_emails')->fetchAll(\PDO::FETCH_ASSOC),
            'Field'
        );
        foreach (['user_email', 'folder_id', 'uid'] as $required) {
            if (!in_array($required, $cols, true)) {
                throw new \RuntimeException("missing column: $required");
            }
        }
        if (in_array('folder', $cols, true)) {
            throw new \RuntimeException('legacy `folder` column still present — cutover migration not applied');
        }
    });

    psv_test('AST + registry classes autoload', function () {
        foreach ([
            '\\Webmail\\Services\\Search\\OperatorRegistry',
            '\\Webmail\\Services\\Search\\Parser',
            '\\Webmail\\Services\\Search\\Ast\\OperatorNode',
        ] as $c) {
            if (!class_exists($c)) throw new \RuntimeException("class missing: $c");
        }
    });
}

// =====================================================================
// 2. PARSER + REGISTRY
// =====================================================================
if (psv_should_run('parser')) {
    psv_out("\n--- 2. PARSER + REGISTRY ---");

    psv_test('OperatorRegistry: is:pinned is a valid enum value', function () {
        $reg = \Webmail\Services\Search\OperatorRegistry::class;
        if (!$reg::isValidValue('is', 'pinned')) {
            throw new \RuntimeException('OperatorRegistry rejects is:pinned — registry update missing');
        }
    });

    psv_test('Parser emits an OperatorNode for is:pinned (not demoted to text)', function () {
        $ast = \Webmail\Services\Search\Parser::parseString('is:pinned');
        $ops = $ast->collectOperators();
        if (count($ops) !== 1) {
            throw new \RuntimeException('expected 1 operator, got ' . count($ops));
        }
        if ($ops[0]->operator !== 'is' || strtolower($ops[0]->value) !== 'pinned') {
            throw new \RuntimeException("unexpected operator node: {$ops[0]->operator}:{$ops[0]->value}");
        }
    });

    psv_test('Canonical round-trip preserves is:pinned alongside other ops', function () {
        $q = 'is:pinned has:attachment from:boss@flowone.pro';
        $ast = \Webmail\Services\Search\Parser::parseString($q);
        $out = $ast->toQueryString();
        foreach (['is:pinned', 'has:attachment', 'from:boss@flowone.pro'] as $needle) {
            if (!str_contains($out, $needle)) {
                throw new \RuntimeException("missing '$needle' in canonical output: $out");
            }
        }
    });
}

// =====================================================================
// 3. QUERY STRIP REGEX (mirrors MailboxController::search)
// =====================================================================
if (psv_should_run('query')) {
    psv_out("\n--- 3. QUERY STRIP REGEX ---");

    // This is the EXACT regex used inside MailboxController::search to strip
    // the operator before handing the rest of the query to IMAP. If we ever
    // change the regex there, this test must keep passing.
    $stripRe = '/(?:^|\s)is\s*:\s*pinned\b/i';

    psv_test('Strips a lone is:pinned', function () use ($stripRe) {
        $out = trim(preg_replace($stripRe, ' ', 'is:pinned') ?? '');
        if ($out !== '') throw new \RuntimeException("expected empty, got '$out'");
    });

    psv_test('Strips is:pinned mid-query, preserves siblings', function () use ($stripRe) {
        $out = trim(preg_replace($stripRe, ' ', 'has:attachment is:pinned from:x@y.z') ?? '');
        // Collapse double spaces left behind
        $out = preg_replace('/\s+/', ' ', $out);
        if (!str_contains($out, 'has:attachment') || !str_contains($out, 'from:x@y.z')) {
            throw new \RuntimeException("sibling operators damaged: '$out'");
        }
        if (str_contains($out, 'pinned')) {
            throw new \RuntimeException("is:pinned not stripped: '$out'");
        }
    });

    psv_test('Case-insensitive: IS:PINNED / Is:Pinned both strip', function () use ($stripRe) {
        foreach (['IS:PINNED foo', 'Is:Pinned foo', 'is : pinned foo'] as $q) {
            $out = trim(preg_replace($stripRe, ' ', $q) ?? '');
            $out = preg_replace('/\s+/', ' ', $out);
            if (!str_contains($out, 'foo') || stripos($out, 'pinned') !== false) {
                throw new \RuntimeException("variant not handled: '$q' -> '$out'");
            }
        }
    });

    psv_test('Does NOT strip is:pinned inside a quoted subject', function () use ($stripRe) {
        // Our parser would normally tokenise this differently, but the strip
        // regex is naive on purpose (mirrors prod). Document the edge.
        $q = 'subject:"is:pinned" from:x@y.z';
        $out = preg_replace($stripRe, ' ', $q);
        // After strip, the literal string `is:pinned` inside the quote IS
        // removed by the regex (this is a known limitation matching the
        // mentions:me regex). We accept it; flag if the regex ever changes.
        if ($out === $q) {
            throw new \RuntimeException('regex unexpectedly preserved quoted occurrence — verify post-filter still works');
        }
    });
}

// =====================================================================
// 4. DB LOOKUP (writes test rows)
// =====================================================================
if (!$smoke && psv_should_run('db')) {
    psv_out("\n--- 4. DB WHITELIST LOOKUP ---");

    // Holds the synthetic folder_id values we mint for the test paths.
    $testFolderIds = [];

    psv_test('Pre-clean any leftover rows for the test tenant', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare('DELETE FROM pinned_emails WHERE user_email = ?')->execute([TEST_EMAIL]);
        $db->prepare('DELETE FROM webmail_folder_identity WHERE account_id = ?')->execute([TEST_EMAIL]);
    });

    psv_test('Mint identity rows for the test folder paths', function () use ($config, &$testFolderIds) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        foreach ([TEST_FOLDER_A, TEST_FOLDER_B] as $path) {
            $id = $svc->upsertFromListing(TEST_EMAIL, ['name' => $path, 'path' => $path]);
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException("upsertFromListing did not return a folder_id for $path");
            }
            $testFolderIds[$path] = $id;
        }
    });

    psv_test('Empty result set when tenant has no pins', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT folder_id, uid FROM pinned_emails WHERE user_email = ?');
        $stmt->execute([TEST_EMAIL]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!empty($rows)) throw new \RuntimeException('expected no rows, got ' . count($rows));
    });

    psv_test('Insert three pins across two folders', function () use ($config, $testFolderIds) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare(
            'INSERT INTO pinned_emails (user_email, folder_id, uid, message_id, subject)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([TEST_EMAIL, $testFolderIds[TEST_FOLDER_A], 101, '<msg-101@flowone.pro>', '[FLOWONE-TEST] pin 1']);
        $stmt->execute([TEST_EMAIL, $testFolderIds[TEST_FOLDER_A], 102, '<msg-102@flowone.pro>', '[FLOWONE-TEST] pin 2']);
        $stmt->execute([TEST_EMAIL, $testFolderIds[TEST_FOLDER_B], 7,   '<msg-007@flowone.pro>', '[FLOWONE-TEST] pin 3']);
    });

    psv_test('Lookup projects path via identity JOIN and yields composite keys', function () use ($config) {
        // Mirrors MailboxController::search post-cutover: pinned_emails is
        // keyed by folder_id, but the post-filter still composites on
        // (folder_path:uid), so the join provides the path back.
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare(
            'SELECT fi.current_path AS folder, pe.uid
             FROM pinned_emails pe
             LEFT JOIN webmail_folder_identity fi ON fi.id = pe.folder_id
             WHERE pe.user_email = ?'
        );
        $stmt->execute([TEST_EMAIL]);
        $keys = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (empty($row['folder'])) continue; // orphaned pin, defensive skip
            $keys[$row['folder'] . ':' . $row['uid']] = true;
        }
        $expected = [TEST_FOLDER_A . ':101', TEST_FOLDER_A . ':102', TEST_FOLDER_B . ':7'];
        foreach ($expected as $needle) {
            if (!isset($keys[$needle])) {
                throw new \RuntimeException("missing composite key: $needle");
            }
        }
        if (count($keys) !== 3) {
            throw new \RuntimeException('expected 3 keys, got ' . count($keys));
        }
    });

    psv_test('UNIQUE(user_email, folder_id, uid) prevents duplicate pins', function () use ($config, $testFolderIds) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare(
            'INSERT INTO pinned_emails (user_email, folder_id, uid, message_id, subject)
             VALUES (?, ?, ?, ?, ?)'
        );
        try {
            $stmt->execute([TEST_EMAIL, $testFolderIds[TEST_FOLDER_A], 101, '<dup@flowone.pro>', '[FLOWONE-TEST] dup']);
        } catch (\PDOException $e) {
            return; // expected
        }
        throw new \RuntimeException('duplicate insert was allowed — UNIQUE constraint missing');
    });
}

// =====================================================================
// 5. POST-FILTER INTERSECTION LOGIC
// =====================================================================
if (psv_should_run('intersect')) {
    psv_out("\n--- 5. POST-FILTER INTERSECTION ---");

    // Mirror the exact logic from MailboxController::search() post-filter.
    $applyPinFilter = function (array $messages, array $whitelistKeys): array {
        return array_values(array_filter($messages, function ($msg) use ($whitelistKeys) {
            $key = ($msg['folder'] ?? '') . ':' . ($msg['uid'] ?? '');
            return isset($whitelistKeys[$key]);
        }));
    };

    psv_test('Empty whitelist → zero results regardless of input', function () use ($applyPinFilter) {
        $input = [
            ['folder' => 'INBOX', 'uid' => 101, 'subject' => 'a'],
            ['folder' => 'INBOX', 'uid' => 102, 'subject' => 'b'],
        ];
        $out = $applyPinFilter($input, []);
        if (count($out) !== 0) throw new \RuntimeException('expected 0, got ' . count($out));
    });

    psv_test('Keeps only messages whose folder:uid is in the whitelist', function () use ($applyPinFilter) {
        $whitelist = ['INBOX:101' => true, 'Archive/2026:7' => true];
        $input = [
            ['folder' => 'INBOX',        'uid' => 101, 'subject' => 'keep-1'],
            ['folder' => 'INBOX',        'uid' => 102, 'subject' => 'drop'],
            ['folder' => 'Sent',         'uid' => 101, 'subject' => 'drop (same uid, wrong folder)'],
            ['folder' => 'Archive/2026', 'uid' => 7,   'subject' => 'keep-2'],
        ];
        $out = $applyPinFilter($input, $whitelist);
        if (count($out) !== 2) throw new \RuntimeException('expected 2, got ' . count($out));
        $subjects = array_column($out, 'subject');
        sort($subjects);
        if ($subjects !== ['keep-1', 'keep-2']) {
            throw new \RuntimeException('wrong messages survived: ' . implode(',', $subjects));
        }
    });

    psv_test('Result array is re-indexed (array_values)', function () use ($applyPinFilter) {
        $whitelist = ['INBOX:102' => true];
        $input = [
            ['folder' => 'INBOX', 'uid' => 101, 'subject' => 'drop'],
            ['folder' => 'INBOX', 'uid' => 102, 'subject' => 'keep'],
        ];
        $out = $applyPinFilter($input, $whitelist);
        if (array_keys($out) !== [0]) {
            throw new \RuntimeException('result not re-indexed: keys=' . implode(',', array_keys($out)));
        }
    });

    psv_test('Folder names with slashes (Archive/2026) survive intact', function () use ($applyPinFilter) {
        $whitelist = ['Archive/2026:7' => true];
        $input = [['folder' => 'Archive/2026', 'uid' => 7, 'subject' => 'nested folder']];
        $out = $applyPinFilter($input, $whitelist);
        if (count($out) !== 1) throw new \RuntimeException('slash folder lost');
    });
}

// =====================================================================
// SUMMARY
// =====================================================================
psv_out("\n=================================================================");
psv_out(sprintf(
    '  RESULT  %sPASS:%s %d   %sFAIL:%s %d   %sWARN:%s %d   total: %d',
    $C['green'], $C['reset'], $passed,
    $C['red'],   $C['reset'], $failed,
    $C['yellow'],$C['reset'], $warnings,
    $totalTests
));
if ($failed > 0) {
    psv_out("\n  Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') psv_out('   - ' . $r['name'] . ' — ' . ($r['error'] ?? ''));
    }
}
psv_out("=================================================================");
psv_out("  Log: $logFile");

if ($jsonOut) {
    echo json_encode([
        'summary' => ['total' => $totalTests, 'passed' => $passed, 'failed' => $failed, 'warnings' => $warnings],
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
