#!/usr/bin/env php
<?php
/**
 * Drive LRU Tier-Down Selection End-to-End Test (Phase 6d).
 *
 * Verifies the Phase 6d additions against the real MariaDB schema:
 *
 *   1. Migration 168 ran (drive_files.last_read_at column exists)
 *   2. LastReadTouch::touch() against MariaDB:
 *      - hot row: writes last_read_at
 *      - cold row: leaves last_read_at NULL (WHERE filter rejects)
 *      - second touch within throttle: zero-row update (conditional WHERE)
 *      - second touch after throttle: row is rewritten
 *   3. TierStateService::findTierDownCandidates against MariaDB:
 *      - default 'age' ordering returns oldest tier_changed_at first
 *      - 'lru' ordering returns COALESCE(last_read_at, tier_changed_at) ASC
 *      - a recently-read hot row sinks to the back of the LRU queue
 *      - age_days filter still applies under 'lru'
 *
 * Side effects:
 *   - No NAS or VPS bytes written; this test only touches drive_files.
 *   - All seed rows are tagged [FLOWONE-TEST]@flowone.pro + flowone_test_lru_.
 *   - Cleanup runs in register_shutdown_function and on SIGINT/SIGTERM.
 *
 * Safety guards (server-side-testing.mdc):
 *   - CLI-only
 *   - Idempotent: safe to run repeatedly
 *   - Bounded wall-clock (HARD_TIMEOUT)
 *   - All test rows tagged with TEST_PREFIX for safe identification
 *
 * CLI:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-lru-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-lru-test must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\LastReadTouch;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\TierState;
use FlowOne\Storage\TierStateService;

const TEST_USER_EMAIL = '[FLOWONE-TEST]@flowone.pro';
const TEST_PREFIX     = 'flowone_test_lru_';
const HARD_TIMEOUT    = 60;

$opts = parseOpts($argv);
if (!empty($opts['help'])) { printHelp(); exit(0); }

$startedAt = microtime(true);
set_time_limit(HARD_TIMEOUT + 10);

// ─── Pre-flight ───────────────────────────────────────────────────────
echo "=== PRE-FLIGHT ===\n";
foreach (['pdo', 'pdo_mysql'] as $ext) {
    $loaded = extension_loaded($ext);
    echo "  " . ($loaded ? '+' : 'x') . " ext: {$ext}\n";
    if (!$loaded) exit(2);
}

try {
    $appConfig = require __DIR__ . '/../src/config.php';
    $pdo = Database::getConnection($appConfig);
    echo "  + db connected\n";
} catch (\Throwable $e) {
    echo "  x db: {$e->getMessage()}\n"; exit(2);
}

// Migration 168 must have run; the LRU code is graceful when the
// column is missing, but this E2E test exists to verify the real
// schema is in the expected shape.
try {
    $check = $pdo->query("SHOW COLUMNS FROM drive_files LIKE 'last_read_at'");
    $row = $check->fetch();
    if ($row === false) {
        echo "  x drive_files.last_read_at missing — run migration 168 first\n";
        exit(3);
    }
    echo "  + drive_files.last_read_at present (type=" . ($row['Type'] ?? '?') . ")\n";
} catch (\Throwable $e) {
    echo "  x schema check failed: {$e->getMessage()}\n"; exit(3);
}

$storageConfig = StorageConfig::load();
$signer = HmacSigner::fromKeyFile(
    (string) $storageConfig['state']['hmac_key_path'],
    (int) $storageConfig['state']['hmac_key_mode_max']
);
$journal = new OperationJournal((string) $storageConfig['journal']['path'], $signer, 0);

$tier  = new TierStateService($pdo, 'drive_files', 'drive_tier_transitions', $journal);
$touch = new LastReadTouch($pdo, 'drive_files', 60, $journal);

// ─── Cleanup wiring ───────────────────────────────────────────────────
$createdFileIds = [];
$cleanup = function () use ($pdo, &$createdFileIds, $opts) {
    if (!empty($createdFileIds)) {
        try {
            $in = implode(',', array_fill(0, count($createdFileIds), '?'));
            // Also remove any transitions our touch/transitions left.
            $stmt = $pdo->prepare("DELETE FROM drive_tier_transitions WHERE file_id IN ({$in})");
            $stmt->execute($createdFileIds);
            $stmt = $pdo->prepare("DELETE FROM drive_files WHERE id IN ({$in})");
            $stmt->execute($createdFileIds);
        } catch (\Throwable) { /* swallow */ }
    }
    // Sweep up any orphans from previous runs that crashed mid-test.
    try {
        $pdo->prepare("DELETE FROM drive_files WHERE user_email = ? AND filename LIKE ?")
            ->execute([TEST_USER_EMAIL, TEST_PREFIX . '%']);
    } catch (\Throwable) { /* swallow */ }
    if (!empty($opts['verbose'])) {
        fwrite(STDOUT, "[cleanup] complete\n");
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
    pcntl_signal(SIGINT,  $sigHandler);
    pcntl_signal(SIGTERM, $sigHandler);
}

$results = ['pass' => 0, 'fail' => 0];

// ─── Seed helper ──────────────────────────────────────────────────────
// Returns the inserted file_id. Seeds a synthetic drive_files row with
// the requested tier_state, days-since-tier-down, and last_read_at.
//
// Timestamps are pre-formatted in PHP rather than bound into INTERVAL
// expressions — some PDO/MariaDB combinations refuse to coerce bound
// integers inside `INTERVAL :n DAY`, and we hit that footgun once
// already in the destructive sweep test (see drive-tier-destructive-test.php).
$seed = function (string $tierState, int $daysAgoTiered, ?int $daysAgoRead = null) use ($pdo, &$createdFileIds): int {
    $filename   = TEST_PREFIX . bin2hex(random_bytes(4)) . '.bin';
    $changedAt  = (new \DateTimeImmutable("-{$daysAgoTiered} days"))->format('Y-m-d H:i:s');
    $lastReadAt = $daysAgoRead === null
        ? null
        : (new \DateTimeImmutable("-{$daysAgoRead} days"))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by,
                                   last_read_at, checksum, created_at, updated_at)
         VALUES (:ue, :fn, :on, 1024, 'application/octet-stream',
                 'local', :st, :ca, 'lru-test',
                 :lra, :cs, NOW(), NOW())"
    );
    $stmt->execute([
        ':ue'  => TEST_USER_EMAIL,
        ':fn'  => $filename,
        ':on'  => $filename,
        ':st'  => $tierState,
        ':ca'  => $changedAt,
        ':lra' => $lastReadAt,
        ':cs'  => md5($filename),
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdFileIds[] = $id;
    return $id;
};

$getLastRead = function (int $id) use ($pdo): ?string {
    $stmt = $pdo->prepare("SELECT last_read_at FROM drive_files WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? ($row['last_read_at'] ?? null) : null;
};

// ─── Tests ────────────────────────────────────────────────────────────
echo "\n=== TESTS ===\n\n--- LRU touch + selection ---\n";

runTest($results, 'LastReadTouch stamps last_read_at on a hot row', function () use ($seed, $touch, $getLastRead) {
    $id = $seed('hot', daysAgoTiered: 5);
    assertTrue($getLastRead($id) === null, 'precondition: last_read_at must start NULL');
    $touch->touch($id);
    assertTrue($getLastRead($id) !== null, 'last_read_at must be populated after touch');
});

runTest($results, 'LastReadTouch is a no-op on a cold row (WHERE filter)', function () use ($seed, $touch, $getLastRead) {
    $id = $seed('cold', daysAgoTiered: 5);
    $touch->touch($id);
    assertTrue($getLastRead($id) === null, 'cold rows must not be stamped');
});

runTest($results, 'LastReadTouch second call in same instance is memo-elided', function () use ($seed, $touch) {
    $id = $seed('hot', daysAgoTiered: 5);
    $first  = $touch->touch($id);
    $second = $touch->touch($id);
    assertTrue($first === true, 'first touch should attempt DB write');
    assertTrue($second === false, 'second touch must be memo-elided');
});

runTest($results, 'LastReadTouch under throttle window does not rewrite the row (DB-side)', function () use ($pdo, $seed, $touch, $getLastRead) {
    // Seed last_read_at to 5 seconds ago; throttle=60s means the
    // conditional WHERE should reject (not because the memo is hot,
    // but because the DB cutoff filters this row out).
    $id = $seed('hot', daysAgoTiered: 5, daysAgoRead: 0);
    // The seed sets it to "0 days ago" = today. Force a recent value
    // explicitly so the cutoff comparison is well-defined:
    $pdo->prepare("UPDATE drive_files SET last_read_at = DATE_SUB(NOW(), INTERVAL 5 SECOND) WHERE id = :id")
        ->execute([':id' => $id]);
    $before = $getLastRead($id);

    // Fresh instance so the process-local memo is empty for this id.
    $svc = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
    $svc->touch($id);

    $after = $getLastRead($id);
    assertEqual($before, $after, 'within-throttle touch must NOT rewrite the row (conditional WHERE)');
});

runTest($results, 'LastReadTouch beyond throttle window DOES rewrite the row', function () use ($pdo, $seed, $getLastRead) {
    $id = $seed('hot', daysAgoTiered: 5);
    // Set last_read_at to 120 seconds ago; cutoff at 60s -> accept.
    $pdo->prepare("UPDATE drive_files SET last_read_at = DATE_SUB(NOW(), INTERVAL 120 SECOND) WHERE id = :id")
        ->execute([':id' => $id]);
    $before = $getLastRead($id);

    $svc = new \FlowOne\Storage\LastReadTouch($pdo, 'drive_files', 60);
    $svc->touch($id);

    $after = $getLastRead($id);
    assertTrue($after !== null && $after !== $before, 'beyond-throttle touch must rewrite the row');
});

// ─── Isolation strategy for findTierDownCandidates tests ────────────
//
// findTierDownCandidates doesn't filter by user_email, so a query
// against a populated drive_files table will drown our test rows
// in real production rows under any realistic LIMIT. To isolate
// cleanly we seed at impossibly-old ages (~25 years) and query with
// a matching threshold (ANCIENT_THRESHOLD_DAYS) — well outside any
// plausible real `tier_changed_at` value. The candidate set then
// contains only our seeded rows, no matter how busy the table is.
//
// Seed→cutoff gap: we make each seed at LEAST 500 days older than
// the cutoff. That swallows any plausible PHP↔MariaDB timezone /
// DST skew while still leaving headroom above 1970-01-01 (the
// TIMESTAMP lower bound).
//
// Why this is safe to leave in the DB during the test window:
//   - tier_state='hot' rows with no on-disk bytes (we don't write any)
//     are picked up as `skipped_missing` by the real tier-down worker
//     if it happens to run mid-test, which is a benign no-op.
//   - Cleanup runs in register_shutdown_function and on SIGINT/SIGTERM,
//     so rows are gone within tens of milliseconds of test exit.
$ANCIENT_THRESHOLD_DAYS = 8000;            // ~21.9 years; older than any real tier_changed_at
$AGE_A = 9000; $AGE_B = 8600; $AGE_C = 9400; // all ≥ 600 days older than cutoff; C oldest, B newest of the three

// Diagnostic helper: when an assertion is about to fail, dump everything
// we know about the missing rows so the failure tells us why.
$diagnoseMissing = function (array $seeds, array $returnedIds, int $threshold) use ($pdo): string {
    $missing = array_values(array_diff($seeds, $returnedIds));
    if (empty($missing)) return '';
    $info = [];
    foreach ($missing as $fid) {
        $stmt = $pdo->prepare(
            "SELECT id, tier_state, tier_changed_at,
                    CAST(tier_changed_at < DATE_SUB(NOW(), INTERVAL ? DAY) AS UNSIGNED) AS qualifies,
                    CAST(NOW() AS CHAR) AS now_db
             FROM drive_files WHERE id = ?"
        );
        $stmt->execute([$threshold, $fid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $info[$fid] = $row ?: 'ROW NOT FOUND';
    }
    return "missing={" . implode(',', $missing) . "} info=" . json_encode($info)
         . " seeds=[" . implode(',', $seeds) . "]"
         . " returned_count=" . count($returnedIds);
};

runTest($results, 'findTierDownCandidates(age) returns rows in oldest-tier-changed order', function () use ($seed, $tier, $ANCIENT_THRESHOLD_DAYS, $AGE_A, $AGE_B, $AGE_C, $diagnoseMissing) {
    $a = $seed('hot', daysAgoTiered: $AGE_A);
    $b = $seed('hot', daysAgoTiered: $AGE_B);
    $c = $seed('hot', daysAgoTiered: $AGE_C);
    $rows = $tier->findTierDownCandidates(ageDays: $ANCIENT_THRESHOLD_DAYS, limit: 500, orderBy: 'age');
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $diag = $diagnoseMissing([$a, $b, $c], $ids, $ANCIENT_THRESHOLD_DAYS);
    assertTrue($diag === '', "candidate set incomplete; {$diag}");

    $positions = [
        $a => array_search($a, $ids, true),
        $b => array_search($b, $ids, true),
        $c => array_search($c, $ids, true),
    ];
    assertTrue($positions[$c] < $positions[$a], 'c (oldest) must come before a');
    assertTrue($positions[$a] < $positions[$b], 'a must come before b (newest of the three)');
});

runTest($results, 'findTierDownCandidates(lru) sinks recently-read rows', function () use ($seed, $tier, $ANCIENT_THRESHOLD_DAYS, $AGE_A, $AGE_B, $AGE_C, $diagnoseMissing) {
    // a: tier_changed=ancient, last_read=-1d   -> LRU key recent (sink to back)
    // b: tier_changed=ancient, last_read=NULL  -> LRU key = tier_changed (middle)
    // c: tier_changed=oldest, last_read=NULL   -> LRU key = oldest (front)
    $a = $seed('hot', daysAgoTiered: $AGE_A, daysAgoRead: 1);
    $b = $seed('hot', daysAgoTiered: $AGE_B);
    $c = $seed('hot', daysAgoTiered: $AGE_C);
    $rows = $tier->findTierDownCandidates(ageDays: $ANCIENT_THRESHOLD_DAYS, limit: 500, orderBy: 'lru');
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $diag = $diagnoseMissing([$a, $b, $c], $ids, $ANCIENT_THRESHOLD_DAYS);
    assertTrue($diag === '', "candidate set incomplete; {$diag}");

    $positions = [
        $a => array_search($a, $ids, true),
        $b => array_search($b, $ids, true),
        $c => array_search($c, $ids, true),
    ];
    assertTrue($positions[$c] < $positions[$b], 'c (oldest lru-key) must come before b');
    assertTrue($positions[$b] < $positions[$a], 'b (NULL lru-key falls back to ancient tier_changed) must come before a (recently-read)');
});

runTest($results, 'findTierDownCandidates respects age_days under lru ordering', function () use ($seed, $tier, $ANCIENT_THRESHOLD_DAYS) {
    // A row that's "young" relative to ANCIENT_THRESHOLD_DAYS but
    // still ancient by any real-world measure. With ageDays threshold
    // at 8000, a row tiered 7000 days ago is too young to qualify.
    $young = $seed('hot', daysAgoTiered: $ANCIENT_THRESHOLD_DAYS - 1000);
    $rows = $tier->findTierDownCandidates(ageDays: $ANCIENT_THRESHOLD_DAYS, limit: 500, orderBy: 'lru');
    $ids  = array_map(fn($r) => (int) $r['id'], $rows);
    assertTrue(!in_array($young, $ids, true), 'a row younger than ageDays threshold must NOT qualify');
});

runTest($results, 'findTierDownCandidates(lru) includes last_read_at in result rows', function () use ($seed, $tier, $ANCIENT_THRESHOLD_DAYS, $AGE_A) {
    $id = $seed('hot', daysAgoTiered: $AGE_A, daysAgoRead: 7);
    $rows = $tier->findTierDownCandidates(ageDays: $ANCIENT_THRESHOLD_DAYS, limit: 500, orderBy: 'lru');
    $hit  = null;
    foreach ($rows as $r) {
        if ((int) $r['id'] === $id) { $hit = $r; break; }
    }
    assertTrue($hit !== null, 'seeded row must appear');
    assertTrue(array_key_exists('last_read_at', $hit), 'LRU mode SELECTs last_read_at');
    assertTrue($hit['last_read_at'] !== null, 'last_read_at must be populated for our seeded row');
});

// ─── Summary ─────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
echo "Passed: {$results['pass']}\nFailed: {$results['fail']}\n";
echo "Elapsed: " . round((microtime(true) - $startedAt) * 1000) . "ms\n";

exit($results['fail'] > 0 ? 1 : 0);

// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = ['help' => false, 'verbose' => false];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--help' || $a === '-h') $opts['help'] = true;
        if ($a === '--verbose' || $a === '-v') $opts['verbose'] = true;
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
drive-lru-test - end-to-end test of Phase 6d LRU touch + selection

Usage:
  drive-lru-test.php [--verbose]

Touches drive_files only (no NAS or VPS bytes). All synthetic rows
are tagged [FLOWONE-TEST]@flowone.pro + flowone_test_lru_ prefix and
cleaned up on exit (including SIGINT/SIGTERM).

TXT;
}

function runTest(array &$results, string $name, callable $fn): void
{
    $start = microtime(true);
    try {
        $fn();
        $ms = (int) ((microtime(true) - $start) * 1000);
        echo "  [PASS] {$name} ({$ms}ms)\n";
        $results['pass']++;
    } catch (\Throwable $e) {
        $ms = (int) ((microtime(true) - $start) * 1000);
        echo "  [FAIL] {$name} ({$ms}ms): " . $e->getMessage() . "\n";
        $results['fail']++;
    }
}

function assertTrue($cond, string $msg = ''): void
{
    if (!$cond) throw new \RuntimeException($msg ?: 'assertion failed');
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg ? "{$msg}: " : '') .
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
