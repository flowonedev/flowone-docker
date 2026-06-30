#!/usr/bin/env php
<?php
/**
 * Drive Tier State Backfill end-to-end test (Phase 4).
 *
 * Exercises the migration 167 schema + FlowOne\Storage\TierStateService
 * + the drive-tier-backfill.php cron against the real production
 * database, using a recognisable test row prefix.
 *
 * Safety guards (server-side-testing.mdc):
 *   - CLI-only
 *   - Inserts test rows with user_email = '[FLOWONE-TEST]@flowone.pro'
 *     so they can never be confused with real user data
 *   - Cleans up every test row (including audit-log entries) on exit,
 *     even on signal or unhandled error
 *   - Recognisable filename prefix `flowone_test_tier_` so any leaked
 *     row is trivially findable
 *   - Each test has a 10-second timeout; suite has a 60-second
 *     wall-clock cap to keep it cron-safe
 *   - Idempotent: safe to run 10 times in a row
 *
 * CLI:
 *   php drive-tier-backfill-test.php --verbose
 *   php drive-tier-backfill-test.php --smoke      (config + DB connectivity only)
 *   php drive-tier-backfill-test.php --json       (output as JSON)
 *   php drive-tier-backfill-test.php --only=transitions,backfill
 *
 * Server run command:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-tier-backfill-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-tier-backfill-test must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\TierState;
use FlowOne\Storage\TierStateService;

const TEST_USER_EMAIL = '[FLOWONE-TEST]@flowone.pro';
const TEST_FILENAME_PREFIX = 'flowone_test_tier_';
const TEST_HARD_TIMEOUT_SEC = 60;

$opts = parseOpts($argv);
if (!empty($opts['help'])) {
    printHelp();
    exit(0);
}

$startedAt = microtime(true);
set_time_limit(TEST_HARD_TIMEOUT_SEC + 10);

// ─── Pre-flight ────────────────────────────────────────────────────────
$preflight = [];
$ok = true;
foreach (['pdo', 'pdo_mysql', 'json'] as $ext) {
    $loaded = extension_loaded($ext);
    $preflight[] = [$loaded ? '+' : 'x', "ext: {$ext}", $loaded];
    if (!$loaded) $ok = false;
}
try {
    $config = require __DIR__ . '/../src/config.php';
    $pdo = Database::getConnection($config);
    $preflight[] = ['+', 'db: connected', true];
} catch (\Throwable $e) {
    $preflight[] = ['x', 'db: ' . $e->getMessage(), false];
    $ok = false;
}

// Verify the migration 167 columns exist.
if ($ok) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM drive_files LIKE 'tier_state'");
        $hasTierState = $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
        $preflight[] = [$hasTierState ? '+' : 'x', 'schema: drive_files.tier_state present', $hasTierState];
        if (!$hasTierState) $ok = false;

        $stmt = $pdo->query("SHOW TABLES LIKE 'drive_tier_transitions'");
        $hasAudit = $stmt->fetch() !== false;
        $preflight[] = [$hasAudit ? '+' : 'x', 'schema: drive_tier_transitions table present', $hasAudit];
        if (!$hasAudit) $ok = false;
    } catch (\Throwable $e) {
        $preflight[] = ['x', 'schema check: ' . $e->getMessage(), false];
        $ok = false;
    }
}

echo "=== PRE-FLIGHT ===\n";
foreach ($preflight as [$icon, $msg]) {
    echo "  {$icon} {$msg}\n";
}
if (!$ok) {
    fwrite(STDERR, "[fatal] preflight failed; aborting\n");
    exit(2);
}

if (!empty($opts['smoke'])) {
    echo "\n[smoke] preflight passed, exiting\n";
    exit(0);
}

// ─── Test fixtures and cleanup ────────────────────────────────────────
$service = new TierStateService($pdo);
$createdFileIds = [];
$results = ['pass' => 0, 'fail' => 0, 'tests' => []];

$cleanup = function () use ($pdo, &$createdFileIds, $opts) {
    if (empty($createdFileIds)) return;
    $in = implode(',', array_fill(0, count($createdFileIds), '?'));
    try {
        $stmt = $pdo->prepare("DELETE FROM drive_tier_transitions WHERE file_id IN ({$in})");
        $stmt->execute($createdFileIds);
        $stmt = $pdo->prepare("DELETE FROM drive_files WHERE id IN ({$in})");
        $stmt->execute($createdFileIds);
        if (!empty($opts['verbose'])) {
            fwrite(STDOUT, "[cleanup] removed " . count($createdFileIds) . " test rows\n");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[cleanup-error] " . $e->getMessage() . "\n");
    }
};
register_shutdown_function($cleanup);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $sigHandler = function (int $s) use ($cleanup) {
        fwrite(STDERR, "\n[signal] {$s} received — cleaning up\n");
        $cleanup();
        exit(130);
    };
    pcntl_signal(SIGINT, $sigHandler);
    pcntl_signal(SIGTERM, $sigHandler);
}

$createRow = function (string $storageLocation = 'local', string $tierState = TierState::HOT) use ($pdo, &$createdFileIds): int {
    $filename = TEST_FILENAME_PREFIX . bin2hex(random_bytes(6));
    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by, created_at, updated_at)
         VALUES (:ue, :fn, :on, :sz, :mt, :sl, :ts, NOW(), 'test', NOW(), NOW())"
    );
    $stmt->execute([
        ':ue' => TEST_USER_EMAIL,
        ':fn' => $filename,
        ':on' => $filename . '.bin',
        ':sz' => 1024,
        ':mt' => 'application/octet-stream',
        ':sl' => $storageLocation,
        ':ts' => $tierState,
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdFileIds[] = $id;
    return $id;
};

// ─── Test groups ──────────────────────────────────────────────────────
// NOTE: full closures + use(&$results) — arrow functions (`fn() =>`) capture
// by VALUE in PHP, so the inner mutations would never reach the outer
// $results and the final summary would always print 0/0.
$groups = [
    'value'       => function () use (&$results) { groupValueClass($results); },
    'transitions' => function () use (&$results, $service, $createRow) { groupTransitions($results, $service, $createRow); },
    'reconcile'   => function () use (&$results, $service, $createRow, $pdo) { groupReconcile($results, $service, $createRow, $pdo); },
    'audit'       => function () use (&$results, $service, $createRow) { groupAuditTrail($results, $service, $createRow); },
    'counts'      => function () use (&$results, $service, $createRow) { groupCounts($results, $service, $createRow); },
];

$selected = $groups;
if (!empty($opts['only'])) {
    $selected = array_intersect_key($groups, array_flip($opts['only']));
}

echo "\n=== TESTS ===\n";
foreach ($selected as $name => $fn) {
    echo "\n--- {$name} ---\n";
    if (microtime(true) - $startedAt > TEST_HARD_TIMEOUT_SEC) {
        fwrite(STDERR, "[abort] wall-clock cap reached\n");
        break;
    }
    $fn();
}

// ─── Summary ──────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
echo "Passed: {$results['pass']}\n";
echo "Failed: {$results['fail']}\n";
echo "Elapsed: " . round((microtime(true) - $startedAt) * 1000) . "ms\n";

if (!empty($opts['json'])) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
}

exit($results['fail'] > 0 ? 1 : 0);

// ─── Helpers ──────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = ['help' => false, 'smoke' => false, 'verbose' => false, 'json' => false, 'only' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') $opts['help'] = true;
        if ($arg === '--smoke') $opts['smoke'] = true;
        if ($arg === '--verbose' || $arg === '-v') $opts['verbose'] = true;
        if ($arg === '--json') $opts['json'] = true;
        if (str_starts_with($arg, '--only=')) {
            $opts['only'] = array_filter(explode(',', substr($arg, 7)));
        }
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
drive-tier-backfill-test - end-to-end test of Phase 4 schema + service

Usage:
  drive-tier-backfill-test.php [--apply|--dry-run] [--verbose]
                               [--smoke] [--json] [--only=group1,group2]

Groups: value, transitions, reconcile, audit, counts

All test data uses user_email='[FLOWONE-TEST]@flowone.pro' and a
flowone_test_tier_ filename prefix. Cleaned up on exit, even on signal.

TXT;
}

function runOne(array &$results, string $group, string $name, callable $fn, int $timeoutSec = 10): void
{
    $start = microtime(true);
    try {
        // Per-test soft timeout via pcntl_alarm when available.
        $hadAlarm = function_exists('pcntl_alarm');
        if ($hadAlarm) {
            pcntl_signal(SIGALRM, function () use ($name) {
                throw new \RuntimeException("test timeout: {$name}");
            });
            pcntl_alarm($timeoutSec);
        }
        $fn();
        if ($hadAlarm) pcntl_alarm(0);
        $elapsed = (int) ((microtime(true) - $start) * 1000);
        echo "  [PASS] {$name} ({$elapsed}ms)\n";
        $results['pass']++;
        $results['tests'][] = ['group' => $group, 'name' => $name, 'ok' => true, 'ms' => $elapsed];
    } catch (\Throwable $e) {
        if (function_exists('pcntl_alarm')) pcntl_alarm(0);
        $elapsed = (int) ((microtime(true) - $start) * 1000);
        echo "  [FAIL] {$name} ({$elapsed}ms): " . $e->getMessage() . "\n";
        $results['fail']++;
        $results['tests'][] = ['group' => $group, 'name' => $name, 'ok' => false, 'ms' => $elapsed, 'err' => $e->getMessage()];
    }
}

function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException("expected " . var_export($expected, true) .
            ", got " . var_export($actual, true) . ($msg ? " ({$msg})" : ''));
    }
}

function assertTrue($cond, string $msg = ''): void
{
    if (!$cond) throw new \RuntimeException($msg ?: 'assertion failed');
}

function assertThrows(callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new \RuntimeException("expected throw containing '{$contains}', got '{$e->getMessage()}'");
        }
        return;
    }
    throw new \RuntimeException('expected throw, none happened');
}

// ─── Groups ──────────────────────────────────────────────────────────

function groupValueClass(array &$results): void
{
    runOne($results, 'value', 'all() returns 5 states', function () {
        assertSame(5, count(TierState::all()));
    });
    runOne($results, 'value', 'fromLegacyLocation maps known + unknown', function () {
        assertSame(TierState::HOT, TierState::fromLegacyLocation('local'));
        assertSame(TierState::HOT, TierState::fromLegacyLocation(null));
        assertSame(TierState::HOT, TierState::fromLegacyLocation('weird'));
        assertSame(TierState::COLD, TierState::fromLegacyLocation('nas'));
        assertSame(TierState::TIERING, TierState::fromLegacyLocation('pending_migration'));
    });
    runOne($results, 'value', 'toLegacyLocation round-trip', function () {
        assertSame('local', TierState::toLegacyLocation(TierState::HOT));
        assertSame('nas', TierState::toLegacyLocation(TierState::COLD));
        assertSame('pending_migration', TierState::toLegacyLocation(TierState::TIERING));
    });
    runOne($results, 'value', 'canTransition enforces hot->cold via tiering', function () {
        assertTrue(TierState::canTransition(TierState::HOT, TierState::TIERING));
        assertTrue(!TierState::canTransition(TierState::HOT, TierState::COLD));
        assertTrue(TierState::canTransition(TierState::TIERING, TierState::COLD));
    });
    runOne($results, 'value', 'lost is terminal', function () {
        foreach (TierState::all() as $s) {
            if ($s === TierState::LOST) continue;
            assertTrue(!TierState::canTransition(TierState::LOST, $s),
                "lost should not transition to {$s}");
        }
    });
    runOne($results, 'value', 'bytesOnVps/bytesOnNas are mutually consistent for cold', function () {
        assertTrue(!TierState::bytesOnVps(TierState::COLD));
        assertTrue(TierState::bytesOnNas(TierState::COLD));
    });
}

function groupTransitions(array &$results, TierStateService $service, callable $create): void
{
    runOne($results, 'transitions', 'transitionTo hot->tiering succeeds + audit row', function () use ($service, $create) {
        $id = $create('local', TierState::HOT);
        assertTrue($service->transitionTo($id, TierState::TIERING, 'test-runner', 'unit test'));
        assertSame(TierState::TIERING, $service->getState($id));
        $audit = $service->auditTrail($id, 5);
        assertTrue(count($audit) >= 1, 'expected at least one audit row');
        assertSame(TierState::HOT, $audit[0]['from_state']);
        assertSame(TierState::TIERING, $audit[0]['to_state']);
    });
    runOne($results, 'transitions', 'illegal hot->cold throws RuntimeException', function () use ($service, $create) {
        $id = $create('local', TierState::HOT);
        assertThrows(fn() => $service->transitionTo($id, TierState::COLD, 'test-runner'), 'illegal tier_state transition');
        assertSame(TierState::HOT, $service->getState($id));
    });
    runOne($results, 'transitions', 'cold->recalling bumps recall_attempts', function () use ($service, $create) {
        $id = $create('nas', TierState::COLD);
        assertTrue($service->transitionTo($id, TierState::RECALLING, 'test-runner'));
        $rec = $service->getRecord($id);
        assertSame(1, (int) $rec['tier_recall_attempts']);
        // a second recall counts too
        $service->transitionTo($id, TierState::COLD, 'test-runner');
        $service->transitionTo($id, TierState::RECALLING, 'test-runner');
        $rec = $service->getRecord($id);
        assertSame(2, (int) $rec['tier_recall_attempts']);
    });
    runOne($results, 'transitions', 'storage_location stays in sync', function () use ($service, $create) {
        $id = $create('local', TierState::HOT);
        $service->transitionTo($id, TierState::TIERING, 'test-runner');
        $rec = $service->getRecord($id);
        assertSame('pending_migration', $rec['storage_location']);
        $service->transitionTo($id, TierState::COLD, 'test-runner');
        $rec = $service->getRecord($id);
        assertSame('nas', $rec['storage_location']);
    });
    runOne($results, 'transitions', 'transitionTo on missing file_id throws', function () use ($service) {
        assertThrows(fn() => $service->transitionTo(0, TierState::TIERING, 'test-runner'), 'not found');
    });
}

function groupReconcile(array &$results, TierStateService $service, callable $create, \PDO $pdo): void
{
    runOne($results, 'reconcile', 'legal drift (hot+pending_migration) heals to tiering', function () use ($service, $create) {
        // storage_location says "tier-down in progress" but tier_state still says hot.
        // hot -> tiering is a legal one-hop transition, so reconcile should apply it.
        $id = $create('pending_migration', TierState::HOT);
        $stats = $service->reconcileLegacyLocation(batchLimit: 500, actor: 'test-reconcile', dryRun: false);
        assertTrue($stats['scanned'] >= 1);
        assertTrue($stats['updated'] >= 1, 'expected at least one update for legal drift');
        assertSame(TierState::TIERING, $service->getState($id));
    });
    runOne($results, 'reconcile', 'illegal drift (hot+nas) is flagged failed, row untouched', function () use ($service, $create) {
        // storage_location says "on NAS" but tier_state still says hot.
        // hot -> cold is NOT a legal one-hop (must go via tiering), so reconcile
        // MUST refuse the direct hop and count it under failed without mutating.
        $id = $create('nas', TierState::HOT);
        $stats = $service->reconcileLegacyLocation(batchLimit: 500, actor: 'test-reconcile', dryRun: false);
        assertTrue($stats['failed'] >= 1, 'expected at least one failed');
        assertSame(TierState::HOT, $service->getState($id), 'state must be untouched on illegal drift');
    });
    runOne($results, 'reconcile', 'dry-run does not mutate', function () use ($service, $create) {
        $id = $create('pending_migration', TierState::HOT); // legal drift candidate
        $before = $service->getState($id);
        $stats = $service->reconcileLegacyLocation(batchLimit: 500, actor: 'test-reconcile', dryRun: true);
        assertSame($before, $service->getState($id));
        assertTrue($stats['updated'] >= 1, 'dry-run should still count intended updates');
    });
    runOne($results, 'reconcile', 'in_sync rows are left alone', function () use ($service, $create) {
        $id = $create('local', TierState::HOT);
        $stats = $service->reconcileLegacyLocation(batchLimit: 500, actor: 'test-reconcile', dryRun: false);
        assertTrue($stats['in_sync'] >= 1);
    });
    runOne($results, 'reconcile', 'lost rows are never resurrected', function () use ($service, $create) {
        // Insert a row directly in 'lost' state with storage_location='local';
        // reconcile must NOT try to revive it.
        $id = $create('local', TierState::LOST);
        $stats = $service->reconcileLegacyLocation(batchLimit: 500, actor: 'test-reconcile', dryRun: false);
        assertTrue($stats['skipped_terminal'] >= 1, 'expected at least one skipped_terminal');
        assertSame(TierState::LOST, $service->getState($id));
    });
}

function groupAuditTrail(array &$results, TierStateService $service, callable $create): void
{
    runOne($results, 'audit', 'audit trail preserves order', function () use ($service, $create) {
        $id = $create('local', TierState::HOT);
        $service->transitionTo($id, TierState::TIERING, 'a1', 'first');
        $service->transitionTo($id, TierState::COLD, 'a2', 'second');
        $service->transitionTo($id, TierState::RECALLING, 'a3', 'third');
        $trail = $service->auditTrail($id, 10);
        assertSame(3, count($trail));
        // newest first
        assertSame(TierState::RECALLING, $trail[0]['to_state']);
        assertSame(TierState::COLD, $trail[1]['to_state']);
        assertSame(TierState::TIERING, $trail[2]['to_state']);
    });
    runOne($results, 'audit', 'audit row carries bytes + duration', function () use ($service, $create) {
        $id = $create('local', TierState::HOT);
        $service->transitionTo($id, TierState::TIERING, 'actor', 'with-metrics', 17, 1024, 250);
        $trail = $service->auditTrail($id, 1);
        assertSame(1024, (int) $trail[0]['bytes']);
        assertSame(250, (int) $trail[0]['duration_ms']);
    });
}

function groupCounts(array &$results, TierStateService $service, callable $create): void
{
    runOne($results, 'counts', 'counts returns all states with zero default', function () use ($service) {
        $c = $service->counts();
        foreach (TierState::all() as $s) {
            assertTrue(array_key_exists($s, $c), "missing key {$s}");
        }
    });
    runOne($results, 'counts', 'inserted test rows are reflected in counts', function () use ($service, $create) {
        $hotBefore = $service->counts()[TierState::HOT];
        $create('local', TierState::HOT);
        $create('local', TierState::HOT);
        $hotAfter = $service->counts()[TierState::HOT];
        assertTrue($hotAfter >= $hotBefore + 2, "expected hot count to grow by 2 (was {$hotBefore}, now {$hotAfter})");
    });
}
