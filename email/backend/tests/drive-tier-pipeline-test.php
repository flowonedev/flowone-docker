#!/usr/bin/env php
<?php
/**
 * Drive Tier Pipeline End-to-End Test (Phase 5a).
 *
 * Exercises the full byte-mover roundtrip against a synthetic tenant
 * subtree on the real NAS mount, then verifies the DB-side state
 * transitions stay coherent via TierStateService.
 *
 * Test plan:
 *   1. Boot a synthetic `[FLOWONE-TEST]@flowone.pro` drive row with
 *      bytes on the VPS-local "test storage" directory.
 *   2. Call TierBytesMover::tierDown against the chaos-test tenant.
 *   3. Verify the bytes landed on NAS with the correct checksum.
 *   4. Transition hot -> tiering -> cold via TierStateService.
 *   5. Recall: TierBytesMover::recall NAS -> VPS; verify checksum;
 *      transition cold -> recalling -> hot.
 *   6. Inject a checksum mismatch and confirm tierDown / recall both
 *      refuse with the destination removed.
 *   7. Clean up every test artifact even on signal / error.
 *
 * Safety guards (server-side-testing.mdc):
 *   - CLI-only
 *   - Refuses to run unless chaos-test tenant is synthetic in config
 *   - Enables chaos flag on entry, restores prior state on exit
 *   - Recognisable prefix: flowone_test_tier_pipeline_
 *   - user_email = '[FLOWONE-TEST]@flowone.pro' on all DB rows
 *   - Cleanup via register_shutdown_function + pcntl signal handlers
 *   - 60-second wall-clock cap on the whole suite
 *
 * CLI:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-tier-pipeline-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-tier-pipeline-test must run from CLI\n");
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
use FlowOne\Storage\TierState;
use FlowOne\Storage\TierStateService;

const TEST_TENANT = 'chaos-test';
const TEST_USER_EMAIL = '[FLOWONE-TEST]@flowone.pro';
const TEST_PREFIX = 'flowone_test_tier_pipeline_';
const HARD_TIMEOUT_SEC = 60;

$opts = parseOpts($argv);
if (!empty($opts['help'])) { printHelp(); exit(0); }

$startedAt = microtime(true);
set_time_limit(HARD_TIMEOUT_SEC + 10);

// ─── Pre-flight ───────────────────────────────────────────────────────
echo "=== PRE-FLIGHT ===\n";
$ok = true;

$storageConfig = StorageConfig::load();
if (!isset($storageConfig['tenants'][TEST_TENANT]) || empty($storageConfig['tenants'][TEST_TENANT]['is_synthetic'])) {
    echo "  x chaos-test tenant must be defined and is_synthetic=true\n";
    exit(2);
}
echo "  + chaos-test tenant configured + synthetic\n";

foreach (['pdo', 'pdo_mysql', 'hash'] as $ext) {
    if (!extension_loaded($ext)) {
        echo "  x ext: {$ext} missing\n"; $ok = false;
    } else echo "  + ext: {$ext}\n";
}

try {
    $appConfig = require __DIR__ . '/../src/config.php';
    $pdo = Database::getConnection($appConfig);
    echo "  + db connected\n";
} catch (\Throwable $e) {
    echo "  x db: " . $e->getMessage() . "\n"; $ok = false;
}
if (!$ok) exit(2);

// Force chaos enabled so TenantResolver can resolve the chaos-test root.
$chaosFlag = rtrim((string) $storageConfig['state']['dir'], '/') . '/' . (string) $storageConfig['state']['chaos_flag'];
$chaosFlagPreexisted = is_file($chaosFlag);
if (!$chaosFlagPreexisted) {
    @file_put_contents($chaosFlag, "enabled by drive-tier-pipeline-test\n");
}

$signer = HmacSigner::fromKeyFile(
    (string) $storageConfig['state']['hmac_key_path'],
    (int) $storageConfig['state']['hmac_key_mode_max']
);
$journal = new OperationJournal(
    (string) $storageConfig['journal']['path'],
    $signer,
    0
);
$resolver = TenantResolver::fromConfig($storageConfig);
$tenantRoot = $resolver->rootFor(TEST_TENANT);
@mkdir($tenantRoot, 0755, true);

// ChaosTargetGuard: hard refusal if anything looks wrong.
try {
    $guard = ChaosTargetGuard::fromConfig();
    $guard->assertEnabled();
    $guard->assertSafePath($tenantRoot . '/.flowone_test_guard_check');
    echo "  + chaos guard accepts chaos-test tenant root\n";
} catch (\Throwable $e) {
    echo "  x chaos guard: " . $e->getMessage() . "\n";
    cleanup($chaosFlag, $chaosFlagPreexisted, $tenantRoot, []);
    exit(2);
}

$mover = new TierBytesMover($resolver, new Invariants($journal, strict: false), $journal);
$tier  = new TierStateService($pdo, 'drive_files', 'drive_tier_transitions', $journal);

// Use a private VPS test directory so we never collide with real
// drive bytes. Cleaned up on exit.
$vpsTestDir = sys_get_temp_dir() . '/flowone-pipeline-vps-' . bin2hex(random_bytes(4));
@mkdir($vpsTestDir, 0755, true);

// ─── Cleanup wiring ──────────────────────────────────────────────────
$createdFileIds = [];
$register = function () use (
    $chaosFlag, $chaosFlagPreexisted, $tenantRoot, &$createdFileIds, $pdo, $vpsTestDir
) {
    return function () use (
        $chaosFlag, $chaosFlagPreexisted, $tenantRoot, &$createdFileIds, $pdo, $vpsTestDir
    ) {
        cleanup($chaosFlag, $chaosFlagPreexisted, $tenantRoot, $createdFileIds, $pdo, $vpsTestDir);
    };
};
register_shutdown_function($register());
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $cleanupFn = $register();
    $sigHandler = function (int $s) use ($cleanupFn) {
        fwrite(STDERR, "\n[signal] {$s} received — cleaning up\n");
        $cleanupFn();
        exit(130);
    };
    pcntl_signal(SIGINT,  $sigHandler);
    pcntl_signal(SIGTERM, $sigHandler);
}

$results = ['pass' => 0, 'fail' => 0, 'tests' => []];

// ─── Test groups ─────────────────────────────────────────────────────
echo "\n=== TESTS ===\n\n--- mover ---\n";

runTest($results, 'tierDown writes bytes + verifies checksum', function () use ($mover, $vpsTestDir, $tenantRoot) {
    $name = TEST_PREFIX . bin2hex(random_bytes(4));
    $vpsPath = $vpsTestDir . '/' . $name . '.bin';
    $payload = random_bytes(4096);
    file_put_contents($vpsPath, $payload);
    $expected = md5($payload);

    $out = $mover->tierDown($vpsPath, TEST_TENANT, $name, $expected);
    assertTrue($out['ok'], 'expected ok=true, got error=' . ($out['error'] ?? 'none'));
    assertEqual(4096, $out['bytes']);
    assertEqual($expected, $out['actual_checksum']);
    assertTrue(is_file($tenantRoot . '/' . $name), 'destination file should exist');
    assertEqual($payload, file_get_contents($tenantRoot . '/' . $name));
    @unlink($tenantRoot . '/' . $name);
    @unlink($vpsPath);
});

runTest($results, 'tierDown rejects checksum mismatch and removes destination', function () use ($mover, $vpsTestDir, $tenantRoot) {
    $name = TEST_PREFIX . bin2hex(random_bytes(4));
    $vpsPath = $vpsTestDir . '/' . $name . '.bin';
    file_put_contents($vpsPath, str_repeat('a', 100));
    $wrong = md5('something-else-entirely');

    $out = $mover->tierDown($vpsPath, TEST_TENANT, $name, $wrong);
    assertTrue(!$out['ok'], 'expected ok=false on checksum mismatch');
    assertTrue(str_contains((string) $out['error'], 'checksum mismatch'), 'error should mention mismatch');
    assertTrue(!is_file($tenantRoot . '/' . $name), 'destination must be removed after mismatch');
    @unlink($vpsPath);
});

runTest($results, 'tierDown refuses path traversal in relative', function () use ($mover, $vpsTestDir) {
    $vpsPath = $vpsTestDir . '/' . TEST_PREFIX . 'traversal.bin';
    file_put_contents($vpsPath, 'data');
    $out = $mover->tierDown($vpsPath, TEST_TENANT, '../../etc/passwd', md5('data'));
    assertTrue(!$out['ok'], 'expected ok=false on path traversal');
    assertTrue(
        str_contains((string) $out['error'], 'dot-segment') ||
        str_contains((string) $out['error'], 'exception'),
        'error should mention traversal/exception, got: ' . ($out['error'] ?? 'null')
    );
    @unlink($vpsPath);
});

runTest($results, 'recall copies bytes back + verifies checksum', function () use ($mover, $vpsTestDir, $tenantRoot) {
    $name = TEST_PREFIX . bin2hex(random_bytes(4));
    $payload = random_bytes(2048);
    file_put_contents($tenantRoot . '/' . $name, $payload);
    $expected = md5($payload);

    $vpsDst = $vpsTestDir . '/recalled-' . $name . '.bin';
    $out = $mover->recall(TEST_TENANT, $name, $vpsDst, $expected);
    assertTrue($out['ok'], 'expected ok=true on recall, got error=' . ($out['error'] ?? 'none'));
    assertEqual($payload, file_get_contents($vpsDst));
    @unlink($tenantRoot . '/' . $name);
    @unlink($vpsDst);
});

runTest($results, 'recall rejects checksum mismatch and removes destination', function () use ($mover, $vpsTestDir, $tenantRoot) {
    $name = TEST_PREFIX . bin2hex(random_bytes(4));
    file_put_contents($tenantRoot . '/' . $name, 'real-bytes');
    $wrongExpected = md5('different-bytes');

    $vpsDst = $vpsTestDir . '/bad-recall-' . $name . '.bin';
    $out = $mover->recall(TEST_TENANT, $name, $vpsDst, $wrongExpected);
    assertTrue(!$out['ok'], 'expected ok=false');
    assertTrue(!is_file($vpsDst), 'destination must be removed on checksum mismatch');
    @unlink($tenantRoot . '/' . $name);
});

runTest($results, 'recall returns ok=false when source missing', function () use ($mover, $vpsTestDir) {
    $vpsDst = $vpsTestDir . '/never-exists.bin';
    $out = $mover->recall(TEST_TENANT, TEST_PREFIX . 'absent-' . bin2hex(random_bytes(4)), $vpsDst, md5('x'));
    assertTrue(!$out['ok']);
    assertTrue(str_contains((string) $out['error'], 'missing'));
});

echo "\n--- pipeline ---\n";

runTest($results, 'full hot->cold->recalling->hot pipeline against synthetic tenant', function () use ($pdo, $mover, $tier, $vpsTestDir, $tenantRoot, &$createdFileIds) {
    // Create a synthetic drive row in 'hot' with real bytes on the
    // VPS test dir. We use the test tenant path for the NAS hop.
    $name = TEST_PREFIX . bin2hex(random_bytes(6));
    $payload = random_bytes(8192);
    $vpsPath = $vpsTestDir . '/' . $name . '.bin';
    file_put_contents($vpsPath, $payload);
    $checksum = md5($payload);

    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by,
                                   checksum, created_at, updated_at)
         VALUES (:ue, :fn, :on, :sz, 'application/octet-stream',
                 'local', 'hot', NOW(), 'pipeline-test', :cs, NOW(), NOW())"
    );
    $stmt->execute([':ue' => TEST_USER_EMAIL, ':fn' => $name . '.bin', ':on' => $name . '.bin', ':sz' => 8192, ':cs' => $checksum]);
    $fileId = (int) $pdo->lastInsertId();
    $createdFileIds[] = $fileId;

    // hot -> tiering
    assertTrue($tier->transitionTo($fileId, TierState::TIERING, 'pipeline-test'));
    assertEqual(TierState::TIERING, $tier->getState($fileId));

    // VPS -> NAS
    $out = $mover->tierDown($vpsPath, TEST_TENANT, $name, $checksum);
    assertTrue($out['ok'], 'tier-down should succeed; error=' . ($out['error'] ?? 'none'));
    assertTrue(is_file($tenantRoot . '/' . $name));

    // tiering -> cold
    assertTrue($tier->transitionTo($fileId, TierState::COLD, 'pipeline-test', 'tier-down committed', null, $out['bytes'], $out['duration_ms']));

    // cold -> recalling (bumps recall_attempts)
    assertTrue($tier->transitionTo($fileId, TierState::RECALLING, 'pipeline-test'));
    $rec = $tier->getRecord($fileId);
    assertEqual(1, (int) $rec['tier_recall_attempts']);

    // NAS -> VPS (different dest to avoid clobbering the source bytes)
    $recalledPath = $vpsTestDir . '/recalled-' . $name . '.bin';
    $back = $mover->recall(TEST_TENANT, $name, $recalledPath, $checksum);
    assertTrue($back['ok'], 'recall should succeed; error=' . ($back['error'] ?? 'none'));
    assertEqual($payload, file_get_contents($recalledPath));

    // recalling -> hot
    assertTrue($tier->transitionTo($fileId, TierState::HOT, 'pipeline-test', 'recall committed', null, $back['bytes'], $back['duration_ms']));
    assertEqual(TierState::HOT, $tier->getState($fileId));

    // Audit trail should have 4 entries newest-first.
    $trail = $tier->auditTrail($fileId, 10);
    assertEqual(4, count($trail));
    assertEqual(TierState::HOT,       $trail[0]['to_state']);
    assertEqual(TierState::RECALLING, $trail[1]['to_state']);
    assertEqual(TierState::COLD,      $trail[2]['to_state']);
    assertEqual(TierState::TIERING,   $trail[3]['to_state']);

    // Cleanup tenant + vps temp file (rows cleaned via cleanup()).
    @unlink($tenantRoot . '/' . $name);
    @unlink($vpsPath);
    @unlink($recalledPath);
});

runTest($results, 'rollback to hot when checksum mismatch during tier-down', function () use ($pdo, $mover, $tier, $vpsTestDir, &$createdFileIds) {
    $name = TEST_PREFIX . bin2hex(random_bytes(6));
    $payload = random_bytes(1024);
    $vpsPath = $vpsTestDir . '/' . $name . '.bin';
    file_put_contents($vpsPath, $payload);
    $realChecksum = md5($payload);

    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by,
                                   checksum, created_at, updated_at)
         VALUES (:ue, :fn, :on, :sz, 'application/octet-stream',
                 'local', 'hot', NOW(), 'pipeline-test', :cs, NOW(), NOW())"
    );
    // Deliberately store a WRONG checksum so the mover detects mismatch.
    $stmt->execute([':ue' => TEST_USER_EMAIL, ':fn' => $name . '.bin', ':on' => $name . '.bin', ':sz' => 1024, ':cs' => md5('wrong-expected')]);
    $fileId = (int) $pdo->lastInsertId();
    $createdFileIds[] = $fileId;

    $tier->transitionTo($fileId, TierState::TIERING, 'pipeline-test');
    $out = $mover->tierDown($vpsPath, TEST_TENANT, $name, md5('wrong-expected'));
    assertTrue(!$out['ok'], 'mover should reject mismatch');

    // Worker would rollback tiering -> hot. Simulate it.
    $tier->transitionTo($fileId, TierState::HOT, 'pipeline-test', 'rollback');
    assertEqual(TierState::HOT, $tier->getState($fileId));

    @unlink($vpsPath);
});

// ─── Summary ─────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
echo "Passed: {$results['pass']}\nFailed: {$results['fail']}\n";
echo "Elapsed: " . round((microtime(true) - $startedAt) * 1000) . "ms\n";
if (!empty($opts['verbose'])) {
    foreach ($results['tests'] as $t) {
        $icon = $t['ok'] ? '+' : 'x';
        echo "  {$icon} {$t['name']}" . ($t['ok'] ? '' : " — {$t['err']}") . "\n";
    }
}

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
drive-tier-pipeline-test - end-to-end test of Phase 5a tier byte mover

Usage:
  drive-tier-pipeline-test.php [--verbose]

Runs entirely against the chaos-test synthetic tenant subtree and a
private /tmp VPS dir. Cleans up all NAS, FS, and DB artefacts on exit.

TXT;
}

function runTest(array &$results, string $name, callable $fn): void
{
    $start = microtime(true);
    try {
        $fn();
        $elapsed = (int) ((microtime(true) - $start) * 1000);
        echo "  [PASS] {$name} ({$elapsed}ms)\n";
        $results['pass']++;
        $results['tests'][] = ['name' => $name, 'ok' => true, 'ms' => $elapsed];
    } catch (\Throwable $e) {
        $elapsed = (int) ((microtime(true) - $start) * 1000);
        echo "  [FAIL] {$name} ({$elapsed}ms): " . $e->getMessage() . "\n";
        $results['fail']++;
        $results['tests'][] = ['name' => $name, 'ok' => false, 'ms' => $elapsed, 'err' => $e->getMessage()];
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

function cleanup(
    string $chaosFlag,
    bool $chaosFlagPreexisted,
    string $tenantRoot,
    array $fileIds,
    ?\PDO $pdo = null,
    ?string $vpsTestDir = null
): void {
    // Tenant FS scrub.
    if (is_dir($tenantRoot)) {
        $dh = @opendir($tenantRoot);
        if ($dh) {
            while (($e = readdir($dh)) !== false) {
                if ($e === '.' || $e === '..') continue;
                $p = $tenantRoot . '/' . $e;
                if (is_file($p) && (
                    str_starts_with($e, TEST_PREFIX) || str_starts_with($e, '.flowone_')
                )) {
                    @unlink($p);
                }
            }
            closedir($dh);
        }
    }
    // VPS test dir scrub.
    if ($vpsTestDir !== null && is_dir($vpsTestDir)) {
        $dh = @opendir($vpsTestDir);
        if ($dh) {
            while (($e = readdir($dh)) !== false) {
                if ($e === '.' || $e === '..') continue;
                @unlink($vpsTestDir . '/' . $e);
            }
            closedir($dh);
        }
        @rmdir($vpsTestDir);
    }
    // DB rows + audit log.
    if ($pdo !== null && !empty($fileIds)) {
        try {
            $in = implode(',', array_fill(0, count($fileIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM drive_tier_transitions WHERE file_id IN ({$in})");
            $stmt->execute($fileIds);
            $stmt = $pdo->prepare("DELETE FROM drive_files WHERE id IN ({$in})");
            $stmt->execute($fileIds);
        } catch (\Throwable) { /* swallow */ }
    }
    // Restore chaos flag.
    if (!$chaosFlagPreexisted && is_file($chaosFlag)) {
        @unlink($chaosFlag);
    }
}
