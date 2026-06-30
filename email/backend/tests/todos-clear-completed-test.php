#!/usr/bin/env php
<?php
/**
 * FlowOne — Clear-Completed Todos Test.
 *
 * Covers the new bulk-delete path added alongside the redesigned Tasks panel:
 *
 *   - `TodoService::deleteAllCompleted` only removes rows where
 *     `completed = 1` and leaves incomplete rows untouched.
 *   - Subtodos of a deleted root are cascade-removed via the existing
 *     FK constraint, but subtodos of a still-incomplete root survive.
 *   - The returned `ids` array matches the root ids that were deleted, so
 *     downstream search-index cleanup has the right input.
 *   - The endpoint is idempotent: running it twice in a row is a no-op the
 *     second time and returns `deleted = 0`.
 *   - When no completed rows exist for the tenant, the method returns
 *     `deleted = 0` without error.
 *
 * Tenant: `flowone-test-clearcompleted@flowone.pro` (cannot collide with real
 * data). Cleanup runs via shutdown handler + try/finally + SIGINT trap so
 * even Ctrl-C leaves the DB clean.
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/todos-clear-completed-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output (stack traces, raw counts)
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight + tenant isolation only (no DB writes)
 *   --only=GROUP[,GROUP]   run only listed groups (preflight,seed,delete,idempotent,empty)
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
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2200));
    exit(0);
}

$jsonOut = isset($opts['json']);
$verbose = isset($opts['verbose']);
$smoke   = isset($opts['smoke']);
$only    = isset($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : [];

require_once __DIR__ . '/../cron/bootstrap.php';
$config = require __DIR__ . '/../src/config.php';

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/todos-clear-completed-test-' . date('Ymd-His') . '.log';

const TEST_EMAIL = 'flowone-test-clearcompleted@flowone.pro';

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

function tc_out(string $msg): void
{
    global $logFile, $jsonOut;
    if (!$jsonOut) echo $msg . "\n";
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function tc_should_run(string $group): bool
{
    global $only;
    return empty($only) || in_array($group, $only, true);
}

function tc_record(string $name, string $status, int $ms, ?string $error = null): void
{
    global $totalTests, $passed, $failed, $warnings, $results, $C, $jsonOut;
    $totalTests++;
    if ($status === 'PASS') $passed++;
    elseif ($status === 'WARN') $warnings++;
    else $failed++;
    $results[] = ['name' => $name, 'status' => $status, 'ms' => $ms, 'error' => $error];
    $col = $status === 'PASS' ? $C['green'] : ($status === 'WARN' ? $C['yellow'] : $C['red']);
    tc_out(sprintf('  [%s%-4s%s]  %s (%dms)', $col, $status, $C['reset'], $name, $ms));
    if ($error !== null) tc_out('          -> ' . $error);
}

function tc_test(string $name, callable $fn, int $timeoutSec = 15): void
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
        $fn();
        if (function_exists('pcntl_alarm')) pcntl_alarm(0);
        tc_record($name, 'PASS', (int) ((microtime(true) - $start) * 1000));
    } catch (\Throwable $e) {
        if (function_exists('pcntl_alarm')) pcntl_alarm(0);
        $msg = $e->getMessage();
        if ($verbose) $msg .= "\n          @ " . $e->getFile() . ':' . $e->getLine();
        tc_record($name, 'FAIL', (int) ((microtime(true) - $start) * 1000), $msg);
    }
}

function tc_assert(bool $cond, string $msg): void
{
    if (!$cond) throw new \RuntimeException($msg);
}

// --- Cleanup harness ---
$pdo = null;
$cleanupDone = false;

function tc_cleanup(): void
{
    global $pdo, $cleanupDone;
    if ($cleanupDone) return;
    $cleanupDone = true;
    if (!$pdo instanceof \PDO) return;
    try {
        $stmt = $pdo->prepare('DELETE FROM webmail_todos WHERE email = ?');
        $stmt->execute([TEST_EMAIL]);
        // Also wipe any test rows the bulk-index test may have left if it
        // failed partway through.
        $stmt2 = $pdo->prepare(
            "DELETE FROM universal_search_index
             WHERE user_email = ? AND source_type = 'todo'
             AND source_id IN ('900001','900002','900003','900099')"
        );
        $stmt2->execute([TEST_EMAIL]);
        tc_out('[cleanup] removed all rows for ' . TEST_EMAIL);
    } catch (\Throwable $e) {
        tc_out('[cleanup] FAILED: ' . $e->getMessage());
    }
}

register_shutdown_function('tc_cleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () { tc_cleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { tc_cleanup(); exit(143); });
}

// ============================================================
// PRE-FLIGHT
// ============================================================
tc_out($C['cyan'] . '--- 1. PRE-FLIGHT ---' . $C['reset']);

if (tc_should_run('preflight')) {
    tc_test('PDO extension loaded', function () {
        tc_assert(extension_loaded('pdo_mysql'), 'pdo_mysql not loaded');
    });

    tc_test('Database connection', function () use ($config) {
        global $pdo;
        $pdo = \Webmail\Core\Database::getConnection($config);
        tc_assert($pdo instanceof \PDO, 'getConnection did not return a PDO');
        $row = $pdo->query('SELECT 1 AS ok')->fetch();
        tc_assert((int) ($row['ok'] ?? 0) === 1, 'SELECT 1 failed');
    });

    tc_test('webmail_todos table exists', function () {
        global $pdo;
        $row = $pdo->query("SHOW TABLES LIKE 'webmail_todos'")->fetch();
        tc_assert(!empty($row), 'webmail_todos table missing');
    });

    tc_test('Tenant isolation: no pre-existing rows', function () {
        global $pdo;
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM webmail_todos WHERE email = ?');
        $stmt->execute([TEST_EMAIL]);
        $c = (int) $stmt->fetch()['c'];
        if ($c > 0) {
            // Defensive purge from a previous failed run.
            $del = $pdo->prepare('DELETE FROM webmail_todos WHERE email = ?');
            $del->execute([TEST_EMAIL]);
            tc_out('[preflight] purged ' . $c . ' stale test rows');
        }
    });
}

if ($smoke) {
    goto SUMMARY;
}

$service = new \Webmail\Addons\Tasks\Services\TodoService($config);

// ============================================================
// SEED — deterministic fixture
// ============================================================
tc_out("\n" . $C['cyan'] . '--- 2. SEED ---' . $C['reset']);

$rootA = null; // completed root with 2 subtodos
$rootB = null; // incomplete root with 1 subtodo
$rootC = null; // completed root, no subtodos
$rootD = null; // incomplete root, no subtodos

if (tc_should_run('seed')) {
    tc_test('Create completed root A + 2 subtodos', function () use ($service, &$rootA) {
        $rootA = $service->createTodo(TEST_EMAIL, [
            'title' => '[FLOWONE-TEST] root A (completed)',
            'priority' => 'high',
        ]);
        tc_assert(!empty($rootA['id']), 'rootA not created');
        $s1 = $service->createTodo(TEST_EMAIL, [
            'title' => '[FLOWONE-TEST] A-sub-1',
            'parent_id' => $rootA['id'],
        ]);
        $s2 = $service->createTodo(TEST_EMAIL, [
            'title' => '[FLOWONE-TEST] A-sub-2',
            'parent_id' => $rootA['id'],
        ]);
        tc_assert(!empty($s1['id']) && !empty($s2['id']), 'subtodos not created');
        $updated = $service->updateTodo(TEST_EMAIL, $rootA['id'], ['completed' => true]);
        tc_assert($updated['completed'] === true, 'rootA did not flip to completed');
    });

    tc_test('Create incomplete root B + 1 subtodo', function () use ($service, &$rootB) {
        $rootB = $service->createTodo(TEST_EMAIL, [
            'title' => '[FLOWONE-TEST] root B (incomplete)',
            'priority' => 'normal',
        ]);
        tc_assert(!empty($rootB['id']), 'rootB not created');
        $s = $service->createTodo(TEST_EMAIL, [
            'title' => '[FLOWONE-TEST] B-sub-1',
            'parent_id' => $rootB['id'],
        ]);
        tc_assert(!empty($s['id']), 'B subtodo not created');
    });

    tc_test('Create completed root C (no subs)', function () use ($service, &$rootC) {
        $rootC = $service->createTodo(TEST_EMAIL, ['title' => '[FLOWONE-TEST] root C']);
        $service->updateTodo(TEST_EMAIL, $rootC['id'], ['completed' => true]);
        tc_assert(!empty($rootC['id']), 'rootC not created');
    });

    tc_test('Create incomplete root D (no subs)', function () use ($service, &$rootD) {
        $rootD = $service->createTodo(TEST_EMAIL, ['title' => '[FLOWONE-TEST] root D']);
        tc_assert(!empty($rootD['id']), 'rootD not created');
    });

    tc_test('Seed sanity: 4 roots + 3 subtodos exist', function () {
        global $pdo;
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM webmail_todos WHERE email = ?');
        $stmt->execute([TEST_EMAIL]);
        $total = (int) $stmt->fetch()['c'];
        tc_assert($total === 7, "expected 7 rows, got $total");
    });
}

// ============================================================
// DELETE — first call, mixed fixture
// ============================================================
tc_out("\n" . $C['cyan'] . '--- 3. DELETE ALL COMPLETED ---' . $C['reset']);

if (tc_should_run('delete')) {
    tc_test('deleteAllCompleted returns expected count', function () use ($service, $rootA, $rootC) {
        $result = $service->deleteAllCompleted(TEST_EMAIL);
        // 1 root A + 2 of A's subtodos + 1 root C = 4 rows expected
        tc_assert($result['deleted'] === 4, "expected 4 deleted, got {$result['deleted']}");
        tc_assert(in_array($rootA['id'], $result['ids'], true), 'rootA not reported as deleted');
        tc_assert(in_array($rootC['id'], $result['ids'], true), 'rootC not reported as deleted');
    });

    tc_test('Incomplete root B + its subtodo survive', function () use ($rootB) {
        global $pdo;
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM webmail_todos WHERE email = ? AND (id = ? OR parent_id = ?)');
        $stmt->execute([TEST_EMAIL, $rootB['id'], $rootB['id']]);
        $c = (int) $stmt->fetch()['c'];
        tc_assert($c === 2, "expected rootB + 1 sub (2 rows), got $c");
    });

    tc_test('Incomplete root D survives', function () use ($rootD) {
        global $pdo;
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM webmail_todos WHERE email = ? AND id = ?');
        $stmt->execute([TEST_EMAIL, $rootD['id']]);
        tc_assert((int) $stmt->fetch()['c'] === 1, 'rootD got deleted');
    });

    tc_test('Completed rows are gone', function () {
        global $pdo;
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM webmail_todos WHERE email = ? AND completed = 1');
        $stmt->execute([TEST_EMAIL]);
        tc_assert((int) $stmt->fetch()['c'] === 0, 'completed rows still present');
    });

    tc_test("A's orphaned subtodos cascade-deleted", function () use ($rootA) {
        global $pdo;
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM webmail_todos WHERE email = ? AND parent_id = ?');
        $stmt->execute([TEST_EMAIL, $rootA['id']]);
        tc_assert((int) $stmt->fetch()['c'] === 0, "rootA's subtodos not cascade-deleted");
    });
}

// ============================================================
// IDEMPOTENT — second call should be a no-op
// ============================================================
tc_out("\n" . $C['cyan'] . '--- 4. IDEMPOTENCY ---' . $C['reset']);

if (tc_should_run('idempotent')) {
    tc_test('deleteAllCompleted again returns deleted = 0', function () use ($service) {
        $result = $service->deleteAllCompleted(TEST_EMAIL);
        tc_assert($result['deleted'] === 0, "expected 0, got {$result['deleted']}");
        tc_assert(is_array($result['ids']) && count($result['ids']) === 0, 'ids should be empty');
    });
}

// ============================================================
// EDGE: orphaned subtodos (parent deleted long ago) + mixed states
// ============================================================
tc_out("\n" . $C['cyan'] . '--- 4b. ORPHANS + MIXED STATES ---' . $C['reset']);

if (tc_should_run('orphans')) {
    tc_test('Completed root pulls in INCOMPLETE subtodos too', function () use ($service) {
        global $pdo;
        // Build a root with mixed children — one completed, one not — and
        // confirm both are deleted when we clear the completed root.
        $root = $service->createTodo(TEST_EMAIL, ['title' => '[FLOWONE-TEST] mixed-root']);
        $done = $service->createTodo(TEST_EMAIL, [
            'title' => '[FLOWONE-TEST] done sub',
            'parent_id' => $root['id'],
        ]);
        $open = $service->createTodo(TEST_EMAIL, [
            'title' => '[FLOWONE-TEST] open sub',
            'parent_id' => $root['id'],
        ]);
        $service->updateTodo(TEST_EMAIL, $done['id'], ['completed' => true]);
        $service->updateTodo(TEST_EMAIL, $root['id'], ['completed' => true]);

        $before = $pdo->prepare('SELECT COUNT(*) AS c FROM webmail_todos WHERE email = ? AND (id = ? OR parent_id = ?)');
        $before->execute([TEST_EMAIL, $root['id'], $root['id']]);
        tc_assert((int) $before->fetch()['c'] === 3, 'seed sanity failed');

        $result = $service->deleteAllCompleted(TEST_EMAIL);
        tc_assert($result['deleted'] === 3, "expected 3 deleted, got {$result['deleted']}");

        $after = $pdo->prepare('SELECT COUNT(*) AS c FROM webmail_todos WHERE email = ? AND (id = ? OR parent_id = ?)');
        $after->execute([TEST_EMAIL, $root['id'], $root['id']]);
        tc_assert((int) $after->fetch()['c'] === 0, 'open subtodo of completed root was not deleted');
    });

    tc_test('Orphan subtodo (parent gone) is left alone unless itself completed', function () use ($service) {
        global $pdo;
        // Simulate the legacy bug state: a subtodo whose parent already
        // disappeared. If the orphan is not completed, deleteAllCompleted
        // must not touch it. If the orphan IS completed, it must be removed.
        $stmt = $pdo->prepare(
            "INSERT INTO webmail_todos (email, parent_id, title, completed)
             VALUES (?, 999999, '[FLOWONE-TEST] orphan-open', 0),
                    (?, 999999, '[FLOWONE-TEST] orphan-done', 1)"
        );
        $stmt->execute([TEST_EMAIL, TEST_EMAIL]);

        $result = $service->deleteAllCompleted(TEST_EMAIL);
        tc_assert($result['deleted'] === 1, "expected 1 (the completed orphan), got {$result['deleted']}");

        $check = $pdo->prepare(
            "SELECT title, completed FROM webmail_todos
             WHERE email = ? AND parent_id = 999999"
        );
        $check->execute([TEST_EMAIL]);
        $remaining = $check->fetchAll();
        tc_assert(count($remaining) === 1, 'expected 1 surviving orphan');
        tc_assert($remaining[0]['title'] === '[FLOWONE-TEST] orphan-open', 'wrong row survived');

        // Tidy: kill the surviving orphan so later tests start clean.
        $pdo->prepare('DELETE FROM webmail_todos WHERE email = ? AND parent_id = 999999')
            ->execute([TEST_EMAIL]);
    });
}

// ============================================================
// EMPTY TENANT — never had completed todos
// ============================================================
tc_out("\n" . $C['cyan'] . '--- 5. EMPTY TENANT ---' . $C['reset']);

if (tc_should_run('empty')) {
    $emptyTenant = 'flowone-test-clearcompleted-empty@flowone.pro';

    tc_test('Empty-tenant deleteAllCompleted is a no-op', function () use ($service, $emptyTenant) {
        $result = $service->deleteAllCompleted($emptyTenant);
        tc_assert($result['deleted'] === 0, "expected 0, got {$result['deleted']}");
    });
}

// ============================================================
// BULK INDEX REMOVAL — single MySQL DELETE
// ============================================================
tc_out("\n" . $C['cyan'] . '--- 6. BULK INDEX REMOVAL ---' . $C['reset']);

if (tc_should_run('bulk-index')) {
    tc_test('removeManyFromIndex deletes only requested ids in one query', function () use ($config) {
        global $pdo;
        $indexer = new \Webmail\Services\SearchIndexerService($config);

        $email = TEST_EMAIL;
        $rows = [
            ['todo', '900001', 'keep-prefix one'],
            ['todo', '900002', 'keep-prefix two'],
            ['todo', '900003', 'keep-prefix three'],
            ['todo', '900099', 'survivor'],
        ];
        $ins = $pdo->prepare(
            'INSERT INTO universal_search_index (user_email, source_type, source_id, title, content_text)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $ins->execute([$email, $r[0], $r[1], $r[2], $r[2]]);
        }

        $deleted = $indexer->removeManyFromIndex($email, 'todo', ['900001', '900002', '900003']);
        tc_assert($deleted === 3, "expected 3 deleted, got $deleted");

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS c FROM universal_search_index
             WHERE user_email = ? AND source_type = 'todo' AND source_id IN ('900001','900002','900003','900099')"
        );
        $stmt->execute([$email]);
        $remaining = (int)$stmt->fetch()['c'];
        tc_assert($remaining === 1, "expected 1 surviving row (900099), got $remaining");

        $cleanup = $pdo->prepare(
            "DELETE FROM universal_search_index WHERE user_email = ? AND source_type = 'todo' AND source_id = '900099'"
        );
        $cleanup->execute([$email]);
    });

    tc_test('removeManyFromIndex with empty ids is a no-op', function () use ($config) {
        $indexer = new \Webmail\Services\SearchIndexerService($config);
        $deleted = $indexer->removeManyFromIndex(TEST_EMAIL, 'todo', []);
        tc_assert($deleted === 0, "expected 0 for empty input, got $deleted");
    });
}

// ============================================================
// SUMMARY
// ============================================================
SUMMARY:
tc_out("\n" . $C['cyan'] . '--- SUMMARY ---' . $C['reset']);
tc_out(sprintf('Total: %d  |  %sPASS: %d%s  %sWARN: %d%s  %sFAIL: %d%s',
    $totalTests,
    $C['green'], $passed, $C['reset'],
    $C['yellow'], $warnings, $C['reset'],
    $C['red'], $failed, $C['reset']
));

if ($failed > 0) {
    tc_out("\nFailed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            tc_out('  - ' . $r['name'] . ': ' . ($r['error'] ?? 'no message'));
        }
    }
}

tc_out("\nLog: $logFile");

if ($jsonOut) {
    echo json_encode([
        'total' => $totalTests,
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

tc_cleanup();
exit($failed > 0 ? 1 : 0);
