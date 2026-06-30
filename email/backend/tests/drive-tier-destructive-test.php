#!/usr/bin/env php
<?php
/**
 * Drive Tier Destructive Sweep End-to-End Test (Phase 5c).
 *
 * Walks a synthetic drive_files row through the post-grace sweep
 * lifecycle that Phase 5c enables:
 *
 *   1. Seed a `cold` row with both VPS shadow + NAS canonical bytes,
 *      tier_changed_at backdated past the grace window
 *   2. Run TierDestructiveSweeper::sweep() in apply mode
 *   3. Verify: VPS file gone, NAS file intact, tier_state still cold
 *      (the sweep only removes shadows, never changes state)
 *
 * Negative paths verified:
 *   - Pre-grace row is left alone
 *   - NAS checksum drift -> skipped, VPS preserved (operator attention)
 *   - State drift (cold -> recalling under the lock) -> skipped
 *
 * Safety guards (server-side-testing.mdc):
 *   - CLI-only
 *   - All rows tagged with [FLOWONE-TEST]@flowone.pro + flowone_test_sweep_
 *   - chaos-test synthetic tenant only (never email-drive in prod)
 *   - register_shutdown_function cleans DB rows + FS artefacts
 *   - 90s wall-clock cap
 *
 * CLI:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-tier-destructive-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-tier-destructive-test must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\ChaosTargetGuard;
use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\Invariants;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\TenantResolver;
use FlowOne\Storage\TierBytesMover;
use FlowOne\Storage\TierDestructiveSweeper;
use FlowOne\Storage\TierState;
use FlowOne\Storage\TierStateService;

const TEST_TENANT     = 'chaos-test';
const TEST_USER_EMAIL = '[FLOWONE-TEST]@flowone.pro';
const TEST_PREFIX     = 'flowone_test_sweep_';
const HARD_TIMEOUT    = 90;

$opts = parseOpts($argv);
if (!empty($opts['help'])) { printHelp(); exit(0); }

$startedAt = microtime(true);
set_time_limit(HARD_TIMEOUT + 10);

// ─── Pre-flight ───────────────────────────────────────────────────────
echo "=== PRE-FLIGHT ===\n";
$storageConfig = StorageConfig::load();
if (!isset($storageConfig['tenants'][TEST_TENANT]) || empty($storageConfig['tenants'][TEST_TENANT]['is_synthetic'])) {
    echo "  x chaos-test tenant must be synthetic in config\n"; exit(2);
}
echo "  + chaos-test tenant configured + synthetic\n";

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

$chaosFlag = rtrim((string) $storageConfig['state']['dir'], '/') . '/' . (string) $storageConfig['state']['chaos_flag'];
$chaosFlagPreexisted = is_file($chaosFlag);
if (!$chaosFlagPreexisted) {
    @file_put_contents($chaosFlag, "enabled by drive-tier-destructive-test\n");
}

$signer = HmacSigner::fromKeyFile(
    (string) $storageConfig['state']['hmac_key_path'],
    (int) $storageConfig['state']['hmac_key_mode_max']
);
$journal = new OperationJournal((string) $storageConfig['journal']['path'], $signer, 0);
$resolver = TenantResolver::fromConfig($storageConfig);
$tenantRoot = $resolver->rootFor(TEST_TENANT);
@mkdir($tenantRoot, 0755, true);

try {
    $guard = ChaosTargetGuard::fromConfig();
    $guard->assertEnabled();
    $guard->assertSafePath($tenantRoot . '/.flowone_test_sweep_check');
    echo "  + chaos guard accepts chaos-test tenant root\n";
} catch (\Throwable $e) {
    echo "  x chaos guard: {$e->getMessage()}\n";
    cleanup($chaosFlag, $chaosFlagPreexisted, $tenantRoot, [], null, null);
    exit(2);
}

$invariants = new Invariants($journal, strict: false);
$mover  = new TierBytesMover($resolver, $invariants, $journal);
$tier   = new TierStateService($pdo, 'drive_files', 'drive_tier_transitions', $journal);

$vpsTestDir = sys_get_temp_dir() . '/flowone-sweep-vps-' . bin2hex(random_bytes(4));
@mkdir($vpsTestDir, 0755, true);

$sweeper = new TierDestructiveSweeper(
    pdo:         $pdo,
    tierService: $tier,
    mover:       $mover,
    resolver:    $resolver,
    tenant:      TEST_TENANT,
    vpsBasePath: $vpsTestDir,
    lockDir:     rtrim((string) $storageConfig['state']['dir'], '/'),
    journal:     $journal,
    tableName:   'drive_files',
    strict:      true,
    lockWaitSec: 2,
);

// ─── Cleanup wiring ───────────────────────────────────────────────────
$createdFileIds = [];
$cleanup = function () use ($chaosFlag, $chaosFlagPreexisted, $tenantRoot, &$createdFileIds, $pdo, $vpsTestDir, $opts) {
    cleanup($chaosFlag, $chaosFlagPreexisted, $tenantRoot, $createdFileIds, $pdo, $vpsTestDir);
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
$seed = function (string $filename, string $payload, int $hoursAgo, ?string $overrideState = null) use ($pdo, $tenantRoot, $vpsTestDir, &$createdFileIds): array {
    $hash = md5(strtolower(TEST_USER_EMAIL));
    $nasRel = TEST_TENANT . "/{$hash}/{$filename}";
    $nasAbs = rtrim($tenantRoot, '/') . "/{$hash}/{$filename}";
    $vpsAbs = $vpsTestDir . "/{$hash}/{$filename}";
    @mkdir(dirname($nasAbs), 0755, true);
    @mkdir(dirname($vpsAbs), 0755, true);
    file_put_contents($nasAbs, $payload);
    file_put_contents($vpsAbs, $payload);

    $changedAt = (new \DateTimeImmutable("-{$hoursAgo} hours"))->format('Y-m-d H:i:s');
    $state = $overrideState ?? 'cold';
    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by,
                                   checksum, nas_relative_path, created_at, updated_at)
         VALUES (:ue, :fn, :on, :sz, 'application/octet-stream',
                 'nas', :st, :ca, 'sweep-test', :cs, :nrp, NOW(), NOW())"
    );
    $stmt->execute([
        ':ue' => TEST_USER_EMAIL, ':fn' => $filename, ':on' => $filename,
        ':sz' => strlen($payload), ':st' => $state, ':ca' => $changedAt,
        ':cs' => md5($payload),    ':nrp' => $nasRel,
    ]);
    $id = (int) $pdo->lastInsertId();
    $createdFileIds[] = $id;
    return ['id' => $id, 'vps' => $vpsAbs, 'nas' => $nasAbs];
};

// ─── Tests ────────────────────────────────────────────────────────────
echo "\n=== TESTS ===\n\n--- destructive sweep ---\n";

runTest($results, 'post-grace cold row: VPS shadow unlinked, NAS canonical preserved, state stays cold', function () use ($sweeper, $tier, $seed) {
    $r = $seed(TEST_PREFIX . bin2hex(random_bytes(3)) . '.bin', random_bytes(2048), 48);
    assertTrue(is_file($r['vps']), 'VPS shadow pre-sweep');
    assertTrue(is_file($r['nas']), 'NAS canonical pre-sweep');

    $res = $sweeper->sweep(graceHours: 24, batch: 5, dryRun: false, maxSeconds: 10);
    assertTrue($res['swept'] >= 1, "expected swept>=1, got {$res['swept']}");
    assertTrue(!is_file($r['vps']), 'VPS shadow must be gone post-sweep');
    assertTrue(is_file($r['nas']),  'NAS canonical must survive sweep');
    assertEqual(TierState::COLD, $tier->getState($r['id']), 'state must remain cold');
});

runTest($results, 'pre-grace row left alone', function () use ($sweeper, $seed) {
    $r = $seed(TEST_PREFIX . bin2hex(random_bytes(3)) . '.bin', random_bytes(256), 2);
    $res = $sweeper->sweep(graceHours: 24, batch: 5, dryRun: false, maxSeconds: 10);
    // The sweep may pick up older rows too in the candidate set; we
    // only care that THIS one's VPS shadow survives.
    assertTrue(is_file($r['vps']), 'young row VPS shadow must survive');
});

runTest($results, 'dry-run reports would-unlink without touching FS', function () use ($sweeper, $seed) {
    $r = $seed(TEST_PREFIX . bin2hex(random_bytes(3)) . '.bin', random_bytes(512), 36);
    $res = $sweeper->sweep(graceHours: 24, batch: 5, dryRun: true, maxSeconds: 10);
    assertTrue($res['swept'] >= 1);
    assertTrue(is_file($r['vps']), 'dry-run must not touch VPS file');
});

runTest($results, 'NAS checksum drift -> skipped + VPS preserved', function () use ($sweeper, $tier, $seed) {
    $r = $seed(TEST_PREFIX . bin2hex(random_bytes(3)) . '.bin', random_bytes(256), 48);
    file_put_contents($r['nas'], 'rotted-' . bin2hex(random_bytes(8))); // mutate NAS
    $res = $sweeper->sweep(graceHours: 24, batch: 5, dryRun: false, maxSeconds: 10);
    assertTrue($res['skipped_checksum_drift'] >= 1);
    assertTrue(is_file($r['vps']), 'VPS must remain when NAS is suspect');
    assertEqual(TierState::COLD, $tier->getState($r['id']));
});

runTest($results, 'rows not in cold are filtered at query level (recall-safe)', function () use ($sweeper, $tier, $seed) {
    // Pre-conditions: row is cold + post-grace; would normally be swept.
    $r = $seed(TEST_PREFIX . bin2hex(random_bytes(3)) . '.bin', random_bytes(256), 48);
    // Move state out of cold BEFORE the sweep runs. The SQL candidate
    // filter must keep this row out of the candidate set entirely —
    // that's the primary guarantee a destructive unlink can never race
    // an in-flight recall. (A second line of defence — the under-lock
    // state re-read — covers the narrow race between candidate fetch
    // and lock acquisition, but is not exercised here.)
    $tier->transitionTo($r['id'], TierState::RECALLING, 'destructive-test');
    $res = $sweeper->sweep(graceHours: 24, batch: 5, dryRun: false, maxSeconds: 10);
    // Whatever the sweep did, it must not have unlinked our VPS file:
    assertTrue(is_file($r['vps']), 'VPS must survive while recall in progress');
    // Walk it back so cleanup leaves a clean state.
    $tier->transitionTo($r['id'], TierState::HOT, 'destructive-test');
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
drive-tier-destructive-test - end-to-end test of Phase 5c VPS-side sweep

Usage:
  drive-tier-destructive-test.php [--verbose]

Runs against the chaos-test synthetic tenant and a private /tmp VPS dir.
All DB rows / NAS bytes / VPS bytes cleaned up on exit (incl. signals).

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

function cleanup(string $chaosFlag, bool $chaosFlagPreexisted, string $tenantRoot, array $fileIds, ?\PDO $pdo = null, ?string $vpsTestDir = null): void
{
    if (is_dir($tenantRoot)) rmTestArtefacts($tenantRoot);
    if ($vpsTestDir !== null && is_dir($vpsTestDir)) {
        rmTestArtefacts($vpsTestDir);
        @rmdir($vpsTestDir);
    }
    if ($pdo !== null && !empty($fileIds)) {
        try {
            $in = implode(',', array_fill(0, count($fileIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM drive_tier_transitions WHERE file_id IN ({$in})");
            $stmt->execute($fileIds);
            $stmt = $pdo->prepare("DELETE FROM drive_files WHERE id IN ({$in})");
            $stmt->execute($fileIds);
        } catch (\Throwable) { /* swallow */ }
    }
    if (!$chaosFlagPreexisted && is_file($chaosFlag)) {
        @unlink($chaosFlag);
    }
}

function rmTestArtefacts(string $dir): void
{
    $dh = @opendir($dir);
    if ($dh === false) return;
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        if (is_dir($p)) {
            rmTestArtefacts($p);
            $remaining = scandir($p) ?: [];
            if (count($remaining) <= 2) {
                @rmdir($p);
            }
        } elseif (str_starts_with($e, TEST_PREFIX) || str_starts_with($e, '.flowone_')) {
            @unlink($p);
        }
    }
    closedir($dh);
}
