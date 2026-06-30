#!/usr/bin/env php
<?php
/**
 * FlowOne Smart Views Test.
 *
 * End-to-end coverage for the Smart Views subsystem introduced in Phase 2:
 *
 *   - Migration 165_smart_views.sql created the table with the expected
 *     columns and UNIQUE(email, position) index.
 *   - The search AST (Lexer + Parser + OperatorRegistry) round-trips through
 *     canonical query strings for every operator class (standard, special,
 *     reserved) and demotes unknowns to text terms.
 *   - SpecialSearchHandlers registry knows mentions + snoozed (Phase 3 stubs).
 *   - FiltersNormalizer drops unknown keys, coerces types, caps sizes.
 *   - SmartViewsService CRUD + reorder roundtrip works against a real DB
 *     row using a test-only email tenant (`flowone-test-smartviews@…`).
 *
 * All writes use the recognisable `flowone-test-smartviews@flowone.pro` tenant
 * so they cannot collide with real data. Cleanup runs in a register_shutdown
 * handler + try/finally so even Ctrl-C leaves the DB clean.
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/smart-views-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output (stack traces, raw queries)
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight + parser groups only (no DB writes)
 *   --only=GROUP[,GROUP]   run only listed groups (preflight,migration,parser,registry,normalizer,crud)
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
$logFile = $logDir . '/smart-views-test-' . date('Ymd-His') . '.log';

const TEST_EMAIL = 'flowone-test-smartviews@flowone.pro';

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

function sv_out(string $msg): void
{
    global $logFile, $jsonOut;
    if (!$jsonOut) echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function sv_should_run(string $group): bool
{
    global $only;
    return empty($only) || in_array($group, $only, true);
}

function sv_record(string $name, string $status, int $ms, ?string $error = null): void
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
    sv_out(sprintf('  [%s%-4s%s]  %s (%dms)', $col, $status, $C['reset'], $name, $ms));
    if ($error !== null) sv_out('          -> ' . $error);
}

function sv_test(string $name, callable $fn, int $timeoutSec = 15): void
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
            sv_record($name, 'WARN', $ms, $r['msg'] ?? null);
        } else {
            sv_record($name, 'PASS', $ms, null);
        }
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $start) * 1000);
        sv_record($name, 'FAIL', $ms, $e->getMessage());
        if ($verbose) sv_out('          at ' . $e->getFile() . ':' . $e->getLine());
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
        $db->prepare('DELETE FROM webmail_smart_views WHERE email = ?')
           ->execute([TEST_EMAIL]);
    } catch (\Throwable $e) {
        // Best-effort — log only, never fail cleanup.
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
sv_out('=================================================================');
sv_out('  FlowOne Smart Views Test');
sv_out('  ' . date('Y-m-d H:i:s T'));
sv_out('  Mode:      ' . ($smoke ? 'SMOKE' : 'FULL'));
sv_out('  Groups:    ' . (empty($only) ? 'all' : implode(',', $only)));
sv_out('  Tenant:    ' . TEST_EMAIL);
sv_out('  Log:       ' . $logFile);
sv_out('=================================================================');

// =====================================================================
// 1. PREFLIGHT
// =====================================================================
if (sv_should_run('preflight')) {
    sv_out("\n--- 1. PREFLIGHT ---");

    sv_test('PHP extensions (pdo_mysql + json + mbstring)', function () {
        foreach (['pdo_mysql', 'json', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) throw new \RuntimeException("missing extension: $ext");
        }
    });

    sv_test('Autoloader resolves AST + service classes', function () {
        foreach ([
            '\\Webmail\\Services\\Search\\OperatorRegistry',
            '\\Webmail\\Services\\Search\\Lexer',
            '\\Webmail\\Services\\Search\\Parser',
            '\\Webmail\\Services\\Search\\SpecialSearchHandlers',
            '\\Webmail\\Services\\Search\\Ast\\GroupNode',
            '\\Webmail\\Services\\Search\\Ast\\OperatorNode',
            '\\Webmail\\Services\\Search\\Ast\\TermNode',
            '\\Webmail\\Services\\SmartViews\\FiltersNormalizer',
            '\\Webmail\\Services\\SmartViewsService',
            '\\Webmail\\Controllers\\SmartViewsController',
        ] as $c) {
            if (!class_exists($c)) throw new \RuntimeException("class missing: $c");
        }
    });
}

// =====================================================================
// 2. MIGRATION (table shape)
// =====================================================================
if (!$smoke && sv_should_run('migration')) {
    sv_out("\n--- 2. MIGRATION ---");

    sv_test('webmail_smart_views table exists', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $rows = $db->query("SHOW TABLES LIKE 'webmail_smart_views'")->fetchAll();
        if (empty($rows)) throw new \RuntimeException('table missing — run migration 165');
    });

    sv_test('table has all expected columns', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $cols = array_column(
            $db->query('SHOW COLUMNS FROM webmail_smart_views')->fetchAll(\PDO::FETCH_ASSOC),
            'Field'
        );
        $expected = ['id','email','name','icon','color','query','filters_json','schema_version','scope','position','created_at','updated_at'];
        $missing = array_diff($expected, $cols);
        if ($missing) throw new \RuntimeException('missing columns: ' . implode(',', $missing));
    });

    sv_test('UNIQUE index on (email, position) present', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $rows = $db->query('SHOW INDEX FROM webmail_smart_views')->fetchAll(\PDO::FETCH_ASSOC);

        // Group rows by Key_name; record per-key uniqueness + ordered columns.
        // SHOW INDEX returns Non_unique as 0/1 — some PDO setups stringify it,
        // others don't, so cast to int.
        $byKey = [];
        foreach ($rows as $r) {
            $name = $r['Key_name'];
            if (!isset($byKey[$name])) {
                $byKey[$name] = ['unique' => (int) $r['Non_unique'] === 0, 'cols' => []];
            }
            $byKey[$name]['cols'][(int) $r['Seq_in_index']] = $r['Column_name'];
        }

        foreach ($byKey as $name => $info) {
            ksort($info['cols']);
            if ($info['unique'] && array_values($info['cols']) === ['email', 'position']) {
                return; // found it
            }
        }
        throw new \RuntimeException('expected UNIQUE KEY on (email, position) — none found');
    });
}

// =====================================================================
// 3. AST PARSER (lexer + parser + canonical round-trip)
// =====================================================================
if (sv_should_run('parser')) {
    sv_out("\n--- 3. AST PARSER ---");

    sv_test('Lexer tokenises a mixed query', function () {
        $tokens = (new \Webmail\Services\Search\Lexer('from:foo subject:"hello world" is:unread bare'))->tokenize();
        $types = array_map(fn($t) => $t->type, $tokens);
        $expected = ['operator','word','operator','quoted','operator','word','word'];
        if ($types !== $expected) {
            throw new \RuntimeException('token types mismatch: ' . implode(',', $types));
        }
    });

    sv_test('Parser canonical round-trip preserves operators', function () {
        $q = 'is:unread from:foo@bar.com has:attachment label:"my list"';
        $ast = \Webmail\Services\Search\Parser::parseString($q);
        $out = $ast->toQueryString();
        // Order should be preserved.
        if ($out !== 'is:unread from:foo@bar.com has:attachment label:"my list"') {
            throw new \RuntimeException("expected canonical query, got: $out");
        }
    });

    sv_test('Unknown operator demoted to text term', function () {
        $ast = \Webmail\Services\Search\Parser::parseString('foo:bar is:unread');
        $ops = $ast->collectOperators();
        if (count($ops) !== 1 || $ops[0]->operator !== 'is') {
            throw new \RuntimeException('expected exactly 1 operator (is), got ' . count($ops));
        }
        // The 'foo:bar' should survive as a text term in the canonical output.
        $out = $ast->toQueryString();
        if (!str_contains($out, 'foo:bar')) {
            throw new \RuntimeException("expected 'foo:bar' as text term, got: $out");
        }
    });

    sv_test('Invalid enum value demoted to text', function () {
        $ast = \Webmail\Services\Search\Parser::parseString('is:nonsense');
        if (count($ast->collectOperators()) !== 0) {
            throw new \RuntimeException('is:nonsense should NOT produce an OperatorNode');
        }
    });

    sv_test('OperatorRegistry classifies all expected operators', function () {
        $reg = \Webmail\Services\Search\OperatorRegistry::class;
        foreach (['from','to','subject','is','has','label','after','before'] as $op) {
            if ($reg::kindOf($op) !== $reg::KIND_STANDARD) {
                throw new \RuntimeException("$op should be STANDARD");
            }
        }
        foreach (['mentions','snoozed'] as $op) {
            if ($reg::kindOf($op) !== $reg::KIND_SPECIAL) {
                throw new \RuntimeException("$op should be SPECIAL");
            }
        }
        foreach (['crm','project','priority'] as $op) {
            if ($reg::kindOf($op) !== $reg::KIND_RESERVED) {
                throw new \RuntimeException("$op should be RESERVED");
            }
        }
    });

    sv_test('Reserved operators parse without error', function () {
        $ast = \Webmail\Services\Search\Parser::parseString('crm:client_123 project:flowone');
        $ops = $ast->collectOperators();
        if (count($ops) !== 2) throw new \RuntimeException('expected 2 reserved operators');
        foreach ($ops as $op) {
            if (!$op->isReserved()) throw new \RuntimeException("$op->operator should be reserved");
        }
    });

    sv_test('Quoted values with embedded escaped quotes', function () {
        $q = 'subject:"hello \\"world\\""';
        $ast = \Webmail\Services\Search\Parser::parseString($q);
        $ops = $ast->collectOperators();
        if (count($ops) !== 1 || $ops[0]->value !== 'hello "world"') {
            throw new \RuntimeException('escape handling broken; got value: ' . ($ops[0]->value ?? '(none)'));
        }
    });
}

// =====================================================================
// 4. SPECIAL SEARCH HANDLERS REGISTRY
// =====================================================================
if (sv_should_run('registry')) {
    sv_out("\n--- 4. SPECIAL SEARCH REGISTRY ---");

    sv_test('Default registry knows mentions + snoozed', function () use ($config) {
        $r = new \Webmail\Services\Search\SpecialSearchHandlers($config);
        if (!$r->has('mentions')) throw new \RuntimeException('mentions handler missing');
        if (!$r->has('snoozed'))  throw new \RuntimeException('snoozed handler missing');
    });

    sv_test('Stub handlers return empty UID set (phase-2 behaviour)', function () use ($config) {
        $r = new \Webmail\Services\Search\SpecialSearchHandlers($config);
        $node = new \Webmail\Services\Search\Ast\OperatorNode('mentions', 'me');
        $res = $r->resolve($node, TEST_EMAIL);
        if (!is_array($res) || !isset($res['uids']) || $res['uids'] !== []) {
            throw new \RuntimeException('stub should return empty uids array');
        }
    });

    sv_test('Custom handler registration overrides defaults', function () use ($config) {
        $r = new \Webmail\Services\Search\SpecialSearchHandlers($config);
        $r->register('mentions', fn($e, $v, $c) => ['uids' => [123, 456]]);
        $node = new \Webmail\Services\Search\Ast\OperatorNode('mentions', 'me');
        $res = $r->resolve($node, TEST_EMAIL);
        if ($res['uids'] !== [123, 456]) throw new \RuntimeException('override failed');
    });
}

// =====================================================================
// 5. FILTERS NORMALIZER
// =====================================================================
if (sv_should_run('normalizer')) {
    sv_out("\n--- 5. FILTERS NORMALIZER ---");

    $N = \Webmail\Services\SmartViews\FiltersNormalizer::class;

    sv_test('Empty input → empty filters + schema_version', function () use ($N) {
        $r = $N::normalize([]);
        if ($r['filters'] !== [] || $r['schema_version'] !== 1) throw new \RuntimeException('bad shape');
    });

    sv_test('Unknown keys are silently dropped', function () use ($N) {
        $r = $N::normalize(['from' => 'a@b.c', 'evilKey' => 'x', 'shellInject' => '`rm -rf`']);
        if (!isset($r['filters']['from']) || isset($r['filters']['evilKey']) || isset($r['filters']['shellInject'])) {
            throw new \RuntimeException('unknown keys not stripped');
        }
    });

    sv_test('Bool coercion: "true" / 1 / true', function () use ($N) {
        foreach ([true, 'true', '1', 1] as $v) {
            $r = $N::normalize(['isUnread' => $v]);
            if (($r['filters']['isUnread'] ?? null) !== true) throw new \RuntimeException('bool coercion failed for: ' . var_export($v, true));
        }
    });

    sv_test('Labels capped to 32 entries', function () use ($N) {
        $labels = array_map(fn($i) => 'L' . $i, range(1, 100));
        $r = $N::normalize(['labels' => $labels]);
        if (count($r['filters']['labels']) !== 32) throw new \RuntimeException('expected 32, got ' . count($r['filters']['labels']));
    });

    sv_test('String length capped at 256 chars', function () use ($N) {
        $r = $N::normalize(['subject' => str_repeat('a', 1000)]);
        if (mb_strlen($r['filters']['subject']) !== 256) throw new \RuntimeException('expected 256, got ' . mb_strlen($r['filters']['subject']));
    });

    sv_test('Accepts JSON string input', function () use ($N) {
        $r = $N::normalize('{"hasAttachment": true, "from": "x@y.z"}');
        if (($r['filters']['hasAttachment'] ?? null) !== true) throw new \RuntimeException('json decode failed');
    });

    sv_test('Throws on malformed JSON string', function () use ($N) {
        try {
            $N::normalize('{not valid json');
        } catch (\InvalidArgumentException $e) { return; }
        throw new \RuntimeException('should have thrown');
    });
}

// =====================================================================
// 6. CRUD + REORDER (writes to DB — gated by --smoke)
// =====================================================================
if (!$smoke && sv_should_run('crud')) {
    sv_out("\n--- 6. CRUD + REORDER ---");

    $svc = new \Webmail\Services\SmartViewsService($config);

    sv_test('Cleanup any pre-existing test rows', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare('DELETE FROM webmail_smart_views WHERE email = ?')->execute([TEST_EMAIL]);
    });

    $ids = [];

    sv_test('Create #1 (Unread)', function () use ($svc, &$ids) {
        $v = $svc->create(TEST_EMAIL, [
            'name' => '[FLOWONE-TEST] Unread',
            'icon' => 'mark_email_unread',
            'color' => 'primary',
            'query' => 'is:unread',
            'filters_json' => ['isUnread' => true],
            'scope' => 'all',
        ]);
        if (!$v || empty($v['id'])) throw new \RuntimeException('create returned null');
        if ($v['query'] !== 'is:unread') throw new \RuntimeException('query not canonicalised: ' . $v['query']);
        $ids[] = $v['id'];
    });

    sv_test('Create #2 (Attachments)', function () use ($svc, &$ids) {
        $v = $svc->create(TEST_EMAIL, [
            'name' => '[FLOWONE-TEST] Attachments',
            'icon' => 'attach_file',
            'color' => 'violet',
            'query' => 'has:attachment',
            'filters_json' => ['hasAttachment' => true],
            'scope' => 'all',
        ]);
        if (!$v) throw new \RuntimeException('create returned null');
        $ids[] = $v['id'];
    });

    sv_test('Create #3 (Boss with quoted label)', function () use ($svc, &$ids) {
        $v = $svc->create(TEST_EMAIL, [
            'name' => '[FLOWONE-TEST] Boss',
            'icon' => 'star',
            'color' => 'amber',
            'query' => 'from:boss@flowone.pro label:"high priority"',
            'filters_json' => ['from' => 'boss@flowone.pro', 'labels' => ['high priority']],
            'scope' => 'all',
        ]);
        if (!$v) throw new \RuntimeException('create returned null');
        if (!str_contains($v['query'], 'label:"high priority"')) {
            throw new \RuntimeException('quoted label not preserved: ' . $v['query']);
        }
        $ids[] = $v['id'];
    });

    sv_test('Create rejects empty name', function () use ($svc) {
        try {
            $svc->create(TEST_EMAIL, ['name' => '', 'query' => 'is:unread']);
        } catch (\InvalidArgumentException $e) { return; }
        throw new \RuntimeException('should have thrown');
    });

    sv_test('Create rejects empty query AND empty filters', function () use ($svc) {
        try {
            $svc->create(TEST_EMAIL, ['name' => 'bad', 'query' => '']);
        } catch (\InvalidArgumentException $e) { return; }
        throw new \RuntimeException('should have thrown');
    });

    sv_test('List returns 3 views in position order', function () use ($svc, &$ids) {
        $views = $svc->listForUser(TEST_EMAIL);
        if (count($views) !== 3) throw new \RuntimeException('expected 3, got ' . count($views));
        $positions = array_column($views, 'position');
        $sorted = $positions;
        sort($sorted);
        if ($positions !== $sorted) throw new \RuntimeException('not sorted by position');
    });

    sv_test('Update changes name + canonicalises new query', function () use ($svc, &$ids) {
        $updated = $svc->update(TEST_EMAIL, $ids[0], [
            'name' => '[FLOWONE-TEST] Updated',
            'query' => 'is:unread has:attachment foo:bar',  // foo:bar demoted
        ]);
        if (!$updated || $updated['name'] !== '[FLOWONE-TEST] Updated') throw new \RuntimeException('update failed');
        if (!str_contains($updated['query'], 'is:unread')) throw new \RuntimeException('canonical lost');
        if (!str_contains($updated['query'], 'foo:bar')) throw new \RuntimeException('unknown operator should survive as text');
    });

    sv_test('Update on non-existent id returns null', function () use ($svc) {
        $r = $svc->update(TEST_EMAIL, 999999999, ['name' => 'x', 'query' => 'is:unread']);
        if ($r !== null) throw new \RuntimeException('expected null for missing id');
    });

    sv_test('Reorder: reverse order, then forward order', function () use ($svc, &$ids) {
        $rev = array_reverse($ids);
        $svc->reorder(TEST_EMAIL, $rev);
        $afterRev = array_column($svc->listForUser(TEST_EMAIL), 'id');
        if ($afterRev !== $rev) throw new \RuntimeException('reverse reorder failed: ' . json_encode($afterRev));

        $svc->reorder(TEST_EMAIL, $ids);
        $afterFwd = array_column($svc->listForUser(TEST_EMAIL), 'id');
        if ($afterFwd !== $ids) throw new \RuntimeException('forward reorder failed: ' . json_encode($afterFwd));
    });

    sv_test('Reorder is idempotent (re-running same order is a no-op)', function () use ($svc, &$ids) {
        $svc->reorder(TEST_EMAIL, $ids);
        $svc->reorder(TEST_EMAIL, $ids);
        $svc->reorder(TEST_EMAIL, $ids);
        $after = array_column($svc->listForUser(TEST_EMAIL), 'id');
        if ($after !== $ids) throw new \RuntimeException('idempotency broken');
    });

    sv_test('Reorder ignores ids that belong to other users', function () use ($svc, &$ids) {
        // Insert 999999 (does NOT exist or belongs elsewhere) — should be filtered.
        $svc->reorder(TEST_EMAIL, array_merge([999999999], $ids));
        $after = array_column($svc->listForUser(TEST_EMAIL), 'id');
        if (in_array(999999999, $after, true)) throw new \RuntimeException('foreign id leaked');
    });

    sv_test('Delete removes a single view', function () use ($svc, &$ids) {
        $ok = $svc->delete(TEST_EMAIL, $ids[2]);
        if (!$ok) throw new \RuntimeException('delete returned false');
        $views = $svc->listForUser(TEST_EMAIL);
        if (count($views) !== 2) throw new \RuntimeException('expected 2 remaining, got ' . count($views));
    });

    sv_test('Delete on non-existent id returns false', function () use ($svc) {
        $ok = $svc->delete(TEST_EMAIL, 999999999);
        if ($ok) throw new \RuntimeException('expected false');
    });

    sv_test('filters_json roundtrips as decoded array on read', function () use ($svc, &$ids) {
        $v = $svc->get(TEST_EMAIL, $ids[1]);
        if (!is_array($v['filters_json']) || ($v['filters_json']['hasAttachment'] ?? null) !== true) {
            throw new \RuntimeException('filters_json not hydrated: ' . json_encode($v['filters_json']));
        }
    });
}

// =====================================================================
// SUMMARY
// =====================================================================
sv_out("\n=================================================================");
sv_out(sprintf(
    '  RESULT:  %s%d passed%s, %s%d failed%s, %s%d warnings%s, %d total',
    $C['green'], $passed, $C['reset'],
    $failed ? $C['red'] : $C['dim'], $failed, $C['reset'],
    $warnings ? $C['yellow'] : $C['dim'], $warnings, $C['reset'],
    $totalTests
));
if ($failed > 0) {
    sv_out("\n  FAILURES:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            sv_out(sprintf('    - %s: %s', $r['name'], $r['error'] ?? '(no message)'));
        }
    }
}
sv_out('=================================================================');

if ($jsonOut) {
    echo json_encode([
        'total' => $totalTests,
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
