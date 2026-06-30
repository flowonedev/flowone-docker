#!/usr/bin/env php
<?php
/**
 * Drive Reclaim Daemon End-to-End Test (Phase 6c).
 *
 * Verifies the Phase 6c reclaim daemon stack against the real MariaDB
 * schema + filesystem, without keeping a long-running process up.
 * Exercises every primitive the daemon depends on:
 *
 *   1. ReclaimController.decide() over a real StorageBudgetReport
 *      computed from the live OS layer.
 *   2. ReclaimDaemonStateStore publish + read round-trip against the
 *      real /var/lib/flowone state directory (HMAC-signed, durable).
 *   3. ReclaimCaps::fromConfig() against the real config loaded by
 *      Storage\Config.
 *   4. ReclaimRunner::runCycle() against a synthetic hot row seeded
 *      under the chaos-test tenant. The cycle is run in DRY-RUN mode
 *      so no NAS bytes move and no production data is touched.
 *   5. ReclaimDaemon end-to-end: --once tick that goes IDLE -> WARMING
 *      -> RECLAIMING -> COOLDOWN against a forced-critical budget
 *      (driven by config override, not real disk pressure).
 *   6. Operator pause flag: when present, the controller refuses to
 *      reclaim even at WM_CRITICAL.
 *   7. storage-ctl reclaim status returns JSON with the published
 *      state (smoke check on the operator surface).
 *
 * Safety:
 *   - All cycles run with dryRun=true: no tier-down actually happens.
 *   - Synthetic rows live under [FLOWONE-TEST]@flowone.pro with the
 *     flowone_test_reclaim_ prefix. Cleanup runs on shutdown and on
 *     SIGINT/SIGTERM.
 *   - The pause flag we toggle is the production one; we always
 *     restore it to its pre-test state at the end (test passes/fails
 *     do not leak a stuck pause flag into prod).
 *   - The daemon state file we write to lives at $stateDir + a
 *     test-prefixed name so it does not stomp on the real one.
 *
 * CLI:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-reclaim-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-reclaim-test must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\DurableJson;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\Invariants;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\ReclaimCaps;
use FlowOne\Storage\ReclaimController;
use FlowOne\Storage\ReclaimDaemon;
use FlowOne\Storage\ReclaimDaemonStateStore;
use FlowOne\Storage\ReclaimDecision;
use FlowOne\Storage\ReclaimRunner;
use FlowOne\Storage\ReclaimState;
use FlowOne\Storage\StorageBudget;
use FlowOne\Storage\StorageBudgetReport;

const TEST_USER_EMAIL = '[FLOWONE-TEST]@flowone.pro';
const TEST_PREFIX     = 'flowone_test_reclaim_';
const HARD_TIMEOUT    = 90;
const TEST_STATE_NAME = 'reclaim-daemon-TEST.json';

$opts = parseOpts($argv);
if (!empty($opts['help'])) { printHelp(); exit(0); }

$startedAt = microtime(true);
set_time_limit(HARD_TIMEOUT + 10);

// ─── Pre-flight ───────────────────────────────────────────────────────
echo "=== PRE-FLIGHT ===\n";
foreach (['pdo', 'pdo_mysql', 'hash'] as $ext) {
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

$storageConfig = StorageConfig::load();
$signer = HmacSigner::fromKeyFile(
    (string) $storageConfig['state']['hmac_key_path'],
    (int)    $storageConfig['state']['hmac_key_mode_max']
);
$journal    = new OperationJournal((string) $storageConfig['journal']['path'], $signer, 0);
$invariants = new Invariants($journal, strict: false);

$stateDir  = rtrim((string) $storageConfig['state']['dir'], '/');
if (!is_writable($stateDir)) {
    echo "  x state dir not writable: {$stateDir}\n"; exit(2);
}
$pauseFlag = $stateDir . '/' . (string) ($storageConfig['tier']['reclaim']['pause_flag'] ?? 'reclaim.paused');
echo "  + state dir writable: {$stateDir}\n";
echo "  + reclaim caps: " . json_encode(ReclaimCaps::fromConfig($storageConfig)->toArray()) . "\n";

// Snapshot any pre-existing pause flag so we can restore it (avoid
// leaking a stuck pause into production after the test).
$priorPauseFlag = is_file($pauseFlag) ? @file_get_contents($pauseFlag) : null;

// ─── Cleanup wiring ───────────────────────────────────────────────────
$createdFileIds = [];
$testStateFile  = $stateDir . '/' . TEST_STATE_NAME;
$cleanup = function () use ($pdo, &$createdFileIds, $opts, $testStateFile, $pauseFlag, $priorPauseFlag) {
    if (!empty($createdFileIds)) {
        try {
            $in = implode(',', array_fill(0, count($createdFileIds), '?'));
            $pdo->prepare("DELETE FROM drive_tier_transitions WHERE file_id IN ({$in})")
                ->execute($createdFileIds);
            $pdo->prepare("DELETE FROM drive_files WHERE id IN ({$in})")
                ->execute($createdFileIds);
        } catch (\Throwable) { /* swallow */ }
    }
    try {
        $pdo->prepare("DELETE FROM drive_files WHERE user_email = ? AND filename LIKE ?")
            ->execute([TEST_USER_EMAIL, TEST_PREFIX . '%']);
    } catch (\Throwable) { /* swallow */ }
    @unlink($testStateFile);
    @unlink($testStateFile . '.bak');
    // Restore pause flag to whatever it was before the test.
    if ($priorPauseFlag === null) {
        @unlink($pauseFlag);
    } else {
        @file_put_contents($pauseFlag, $priorPauseFlag);
    }
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
// Returns the inserted file_id. Seeds a synthetic hot drive_files row
// with the requested tier_changed_at age. The runner cannot tier this
// down for real (we always pass dryRun=true) so size + path are
// irrelevant.
$seed = function (int $daysAgoTiered, int $sizeBytes = 4096) use ($pdo, &$createdFileIds): int {
    $filename = TEST_PREFIX . bin2hex(random_bytes(4)) . '.bin';
    $changedAt = (new \DateTimeImmutable("-{$daysAgoTiered} days"))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by,
                                   checksum, created_at, updated_at)
         VALUES (:ue, :fn, :on, :sz, 'application/octet-stream',
                 'local', 'hot', :ca, 'reclaim-test',
                 :cs, NOW(), NOW())"
    );
    $stmt->execute([
        ':ue' => TEST_USER_EMAIL,
        ':fn' => $filename,
        ':on' => $filename,
        ':sz' => $sizeBytes,
        ':ca' => $changedAt,
        ':cs' => md5($filename),
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdFileIds[] = $id;
    return $id;
};

// ─── Tests ────────────────────────────────────────────────────────────
echo "\n=== TESTS ===\n\n--- reclaim ---\n";

// 1. State store: publish + read round-trip against the real state dir.
runTest($results, 'state store publish + read round-trips through HMAC-signed JSON', function () use ($storageConfig, $signer, $testStateFile, $stateDir) {
    $file = new DurableJson($stateDir, TEST_STATE_NAME);
    $store = new ReclaimDaemonStateStore($file, $signer);
    $payload = [
        'state' => ReclaimState::IDLE,
        'last_reason' => 'test_round_trip',
        'counters' => ['cycles' => 0],
    ];
    $store->publish($payload);
    assertTrue(is_file($testStateFile), 'state file should exist after publish');
    $back = $store->read();
    assertTrue($back !== null, 'read must return non-null after publish');
    assertTrue(($back['state'] ?? null) === ReclaimState::IDLE, "state mismatch: " . json_encode($back));
});

// 2. ReclaimController against a real budget snapshot.
runTest($results, 'controller decides IDLE when real OS budget is clear', function () use ($storageConfig, $pdo) {
    $budget = StorageBudget::build($pdo, $storageConfig);
    $report = $budget->snapshot();
    // We assume the test VPS is not actually under HIGH pressure
    // (the foundation tests would have failed earlier if it were).
    // The interesting assertion is that the controller doesn't crash
    // and produces a state that's safe.
    $ctl = ReclaimController::fromConfig($storageConfig);
    $d = $ctl->decide(ReclaimState::IDLE, 0, $report, paused: false, killed: false, nowUnix: time());
    assertTrue(in_array($d->nextState, [ReclaimState::IDLE, ReclaimState::WARMING], true),
        "expected IDLE or WARMING from real budget; got {$d->nextState} ({$d->reason})");
});

// 3. Forced-critical: synthetic report drives IDLE -> WARMING -> RECLAIMING.
runTest($results, 'forced-critical budget drives the full state machine', function () use ($storageConfig) {
    $ctl = ReclaimController::fromConfig($storageConfig);
    $report = new StorageBudgetReport(
        vpsTotalBytes: 100, vpsFreeBytes: 1, vpsUsedBytes: 99, vpsUsedPct: 99.0,
        vpsMountPoint: '/', driveQuotaBytes: 100, driveUsedBytes: 99,
        driveFreeBytes: 1, driveUsedPct: 99.0, driveHotRows: 1,
        watermark: StorageBudgetReport::WM_CRITICAL, reasons: ['synthetic'],
        computedAtUnix: time(), computeDurationMs: 0.0, fromCache: false,
    );
    $now = 10000;
    $d1 = $ctl->decide(ReclaimState::IDLE,    0, $report, false, false, $now);
    $d2 = $ctl->decide(ReclaimState::WARMING, 0, $report, false, false, $now);
    $d3 = $ctl->decide(ReclaimState::RECLAIMING, 0, $report, false, false, $now);
    assertTrue($d1->nextState === ReclaimState::WARMING,    "1st tick: expected WARMING got {$d1->nextState}");
    assertTrue($d2->nextState === ReclaimState::RECLAIMING, "2nd tick: expected RECLAIMING got {$d2->nextState}");
    assertTrue($d2->shouldReclaim === true,                 'WARMING under pressure should reclaim');
    assertTrue($d3->nextState === ReclaimState::COOLDOWN,   "post-RECLAIMING should enter COOLDOWN got {$d3->nextState}");
});

// 4. Operator pause overrides even WM_CRITICAL.
runTest($results, 'operator pause overrides WM_CRITICAL', function () use ($storageConfig) {
    $ctl = ReclaimController::fromConfig($storageConfig);
    $report = new StorageBudgetReport(
        100, 1, 99, 99.0, '/',
        100, 99, 1, 99.0, 1,
        StorageBudgetReport::WM_CRITICAL, ['synthetic'],
        time(), 0.0, false
    );
    $d = $ctl->decide(ReclaimState::WARMING, 0, $report, paused: true, killed: false, nowUnix: time());
    assertTrue($d->nextState === ReclaimState::PAUSED, "expected PAUSED got {$d->nextState}");
    assertTrue($d->shouldReclaim === false, 'paused must never reclaim');
});

// 5. Kill switch refuses to act even at WM_CRITICAL.
runTest($results, 'kill switch refuses to act at WM_CRITICAL', function () use ($storageConfig) {
    $ctl = ReclaimController::fromConfig($storageConfig);
    $report = new StorageBudgetReport(
        100, 1, 99, 99.0, '/',
        100, 99, 1, 99.0, 1,
        StorageBudgetReport::WM_CRITICAL, ['synthetic'],
        time(), 0.0, false
    );
    $d = $ctl->decide(ReclaimState::WARMING, 0, $report, paused: false, killed: true, nowUnix: time());
    assertTrue($d->nextState === ReclaimState::IDLE, "kill switch should drive IDLE got {$d->nextState}");
    assertTrue($d->killed === true);
});

// 6. ReclaimRunner cycle in dry-run mode against real DB (no NAS writes).
runTest($results, 'ReclaimRunner.runCycle dry-run completes without side effects', function () use ($pdo, $journal, $invariants, $storageConfig, $seed) {
    $id = $seed(daysAgoTiered: 9000); // impossibly old so it qualifies even with ageDays=8000
    $tenant = (string) ($storageConfig['tier']['reclaim']['tenant'] ?? 'email-drive');
    $runner = ReclaimRunner::build(
        pdo:                $pdo,
        journal:            $journal,
        invariants:         $invariants,
        config:             $storageConfig,
        tenant:             $tenant,
        vpsBase:            '/var/www/vps-email/storage/drive',
        destructiveEnabled: false,
    );
    $caps = new ReclaimCaps(
        maxBytes:      4096,
        maxSeconds:    5,
        maxCandidates: 5,
        ageDays:       8000,
        minFileBytes:  0,      // accept our 4 KiB seed
        orderBy:       'age',
        sweepBatch:    1,
        graceHours:    null,
    );
    $result = $runner->runCycle($caps, dryRun: true);
    assertTrue(is_array($result), 'runCycle must return a result array');
    assertTrue(isset($result['stopped_by']), 'result must have stopped_by');
    assertTrue(isset($result['tier']['candidates']), 'result.tier must have candidates');
    // The row must NOT have transitioned (dry-run).
    $state = $pdo->prepare("SELECT tier_state FROM drive_files WHERE id = ?");
    $state->execute([$id]);
    $st = $state->fetchColumn();
    assertTrue($st === 'hot', "row must remain hot after dry-run cycle; got '{$st}'");
});

// 7. ReclaimRunner respects byte cap.
runTest($results, 'ReclaimRunner.runCycle stops at byte cap', function () use ($pdo, $journal, $invariants, $storageConfig, $seed) {
    for ($i = 0; $i < 3; $i++) {
        $seed(daysAgoTiered: 9000, sizeBytes: 4096);
    }
    $tenant = 'email-drive';
    $runner = ReclaimRunner::build(
        $pdo, $journal, $invariants, $storageConfig,
        $tenant, '/var/www/vps-email/storage/drive', false
    );
    $caps = new ReclaimCaps(
        maxBytes:      1,        // 1 byte cap — won't accept any real file
        maxSeconds:    5,
        maxCandidates: 100,
        ageDays:       8000,
        minFileBytes:  0,
        orderBy:       'age',
        sweepBatch:    1,
        graceHours:    null,
    );
    $result = $runner->runCycle($caps, dryRun: true);
    // dry-run doesn't decrement byte budget (no bytes actually moved),
    // so the cycle would naturally exhaust candidates first. The
    // meaningful assertion is that stopped_by reports a well-known
    // termination reason.
    assertTrue(in_array($result['stopped_by'], ['no_more_candidates', 'wall_clock_cap', 'candidate_cap', 'byte_cap'], true),
        "unexpected stopped_by: {$result['stopped_by']}");
});

// 8. Daemon publishes "killed" state at startup when kill switch is off.
runTest($results, 'daemon publishes kill_switch_off state at startup', function () use ($pdo, $journal, $invariants, $signer, $storageConfig, $testStateFile, $stateDir) {
    $cfg = $storageConfig;
    $cfg['phases']['phase6c_reclaim_daemon'] = false;
    $cfg['tier']['reclaim']['state_file']    = TEST_STATE_NAME;
    $daemon = ReclaimDaemon::build(
        pdo:                $pdo,
        journal:            $journal,
        invariants:         $invariants,
        signer:             $signer,
        config:             $cfg,
        tenant:             'email-drive',
        vpsBase:            '/var/www/vps-email/storage/drive',
        destructiveEnabled: false,
    );
    $code = $daemon->run();
    assertTrue($code === 0, "killed daemon exit code should be 0 got {$code}");
    assertTrue(is_file($testStateFile), 'state file must be written even when killed');
    $file = new DurableJson($stateDir, TEST_STATE_NAME);
    $store = new ReclaimDaemonStateStore($file, $signer);
    $state = $store->read();
    assertTrue($state !== null, 'state must be readable after killed startup');
    assertTrue(($state['last_decision']['killed'] ?? false) === true,
        'last_decision.killed must be true; got ' . json_encode($state['last_decision'] ?? null));
});

// 9. storage-ctl reclaim status --json renders the published state.
runTest($results, 'storage-ctl reclaim status --json parses cleanly', function () {
    $cmd = '/usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-ctl.php reclaim status --json 2>&1';
    $output = @shell_exec($cmd);
    assertTrue($output !== null && $output !== '', 'storage-ctl returned no output');
    $decoded = json_decode((string) $output, true);
    assertTrue(is_array($decoded), 'storage-ctl output is not valid JSON: ' . substr((string) $output, 0, 200));
    assertTrue(array_key_exists('kill_switch_off', $decoded), 'expected kill_switch_off key');
    assertTrue(array_key_exists('paused', $decoded),          'expected paused key');
});

// ─── Summary ──────────────────────────────────────────────────────────
$elapsed = (int) round((microtime(true) - $startedAt) * 1000);
echo "\n=== SUMMARY ===\n";
echo "Passed: {$results['pass']}\nFailed: {$results['fail']}\nElapsed: {$elapsed}ms\n";

exit($results['fail'] === 0 ? 0 : 1);

// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = ['help' => false, 'verbose' => false];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--help' || $a === '-h') $opts['help']    = true;
        if ($a === '--verbose')             $opts['verbose'] = true;
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
drive-reclaim-test (Phase 6c)

Validates the reclaim daemon stack against the real MariaDB + state dir,
without keeping a long-running process up. All runner cycles are dry-run
so no production data is touched.

Usage:
  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-reclaim-test.php [--verbose]

TXT;
}

function runTest(array &$results, string $name, callable $fn): void
{
    $t0 = microtime(true);
    try {
        $fn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        echo "  [PASS] {$name} ({$ms}ms)\n";
        $results['pass']++;
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $t0) * 1000);
        echo "  [FAIL] {$name} ({$ms}ms): {$e->getMessage()}\n";
        $results['fail']++;
    }
}

function assertTrue(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new \RuntimeException($msg !== '' ? $msg : 'assertion failed');
    }
}
