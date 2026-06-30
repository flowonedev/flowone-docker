#!/usr/bin/env php
<?php
/**
 * Drive Tier Recall End-to-End Test (Phase 5b).
 *
 * Walks a synthetic drive_files row through the full lifecycle that
 * Phase 5a + Phase 5b enable in production:
 *
 *   hot (VPS-only)
 *      -> tier-down worker copies bytes to NAS, transitions to cold
 *      -> simulate: delete VPS copy (destructive mode behaviour)
 *      -> DriveService::getFilePath() called for the now-cold row
 *      -> TierRecallService kicks in, copies bytes back to VPS, hot again
 *      -> DriveService returns the freshly-warm VPS path
 *
 * Safety guards (server-side-testing.mdc):
 *   - CLI-only; refuses non-CLI invocation
 *   - All rows tagged with [FLOWONE-TEST]@flowone.pro
 *   - Filename prefix flowone_test_recall_
 *   - Tests run against the chaos-test synthetic tenant subtree
 *     (NEVER touches the real email-drive tenant in production)
 *   - register_shutdown_function cleans up DB rows + filesystem
 *     artefacts even on signal / uncaught exception
 *   - 60s wall-clock cap
 *
 * CLI:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-tier-recall-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-tier-recall-test must run from CLI\n");
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
use FlowOne\Storage\TierRecallService;
use FlowOne\Storage\Breakers\RecoveryBreaker;

const TEST_TENANT     = 'chaos-test';
const TEST_USER_EMAIL = '[FLOWONE-TEST]@flowone.pro';
const TEST_PREFIX     = 'flowone_test_recall_';
const HARD_TIMEOUT    = 60;

$opts = parseOpts($argv);
if (!empty($opts['help'])) { printHelp(); exit(0); }

$startedAt = microtime(true);
set_time_limit(HARD_TIMEOUT + 10);

// ─── Pre-flight ───────────────────────────────────────────────────────
echo "=== PRE-FLIGHT ===\n";
$ok = true;
$storageConfig = StorageConfig::load();
if (!isset($storageConfig['tenants'][TEST_TENANT]) || empty($storageConfig['tenants'][TEST_TENANT]['is_synthetic'])) {
    echo "  x chaos-test tenant must be synthetic in config\n"; exit(2);
}
echo "  + chaos-test tenant configured + synthetic\n";

foreach (['pdo', 'pdo_mysql', 'hash'] as $ext) {
    $loaded = extension_loaded($ext);
    echo "  " . ($loaded ? '+' : 'x') . " ext: {$ext}\n";
    if (!$loaded) $ok = false;
}
try {
    $appConfig = require __DIR__ . '/../src/config.php';
    $pdo = Database::getConnection($appConfig);
    echo "  + db connected\n";
} catch (\Throwable $e) {
    echo "  x db: {$e->getMessage()}\n"; exit(2);
}
if (!$ok) exit(2);

// Enable chaos so TenantResolver can resolve the chaos-test root.
$chaosFlag = rtrim((string) $storageConfig['state']['dir'], '/') . '/' . (string) $storageConfig['state']['chaos_flag'];
$chaosFlagPreexisted = is_file($chaosFlag);
if (!$chaosFlagPreexisted) {
    @file_put_contents($chaosFlag, "enabled by drive-tier-recall-test\n");
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
    $guard->assertSafePath($tenantRoot . '/.flowone_test_recall_check');
    echo "  + chaos guard accepts chaos-test tenant root\n";
} catch (\Throwable $e) {
    echo "  x chaos guard: {$e->getMessage()}\n";
    cleanup($chaosFlag, $chaosFlagPreexisted, $tenantRoot, []);
    exit(2);
}

$invariants = new Invariants($journal, strict: false);
$mover  = new TierBytesMover($resolver, $invariants, $journal);
$tier   = new TierStateService($pdo, 'drive_files', 'drive_tier_transitions', $journal);

// Build the recall service against the chaos-test tenant (not
// email-drive — we don't want to touch the real tenant subtree).
// We construct it manually rather than via build() so we can pin
// it to the synthetic tenant.
$health  = \FlowOne\Storage\StorageHealth::fromConfig(null);
$breaker = RecoveryBreaker::fromConfig($storageConfig);
$vpsTestDir = sys_get_temp_dir() . '/flowone-recall-vps-' . bin2hex(random_bytes(4));
@mkdir($vpsTestDir, 0755, true);

$recall = new TierRecallService(
    pdo: $pdo,
    tierService: $tier,
    mover: $mover,
    health: $health,
    breaker: $breaker,
    resolver: $resolver,
    tenant: TEST_TENANT,
    vpsBasePath: $vpsTestDir,
    lockDir: rtrim((string) $storageConfig['state']['dir'], '/'),
    journal: $journal,
    lockWaitSec: 5,
);

// ─── Cleanup wiring ──────────────────────────────────────────────────
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

// ─── Tests ───────────────────────────────────────────────────────────
echo "\n=== TESTS ===\n\n--- recall via service ---\n";

runTest($results, 'cold file recall via TierRecallService roundtrip', function () use ($pdo, $mover, $tier, $recall, $tenantRoot, $vpsTestDir, &$createdFileIds) {
    $userHash = md5(strtolower(TEST_USER_EMAIL));
    $filename = TEST_PREFIX . bin2hex(random_bytes(4)) . '.bin';
    $payload = random_bytes(2048);
    $checksum = md5($payload);

    // Stage: bytes on NAS at the chaos-test tenant + DB row says cold.
    $nasRel = TEST_TENANT . "/{$userHash}/{$filename}";
    $nasAbs = rtrim($tenantRoot, '/') . "/{$userHash}/{$filename}";
    @mkdir(dirname($nasAbs), 0755, true);
    file_put_contents($nasAbs, $payload);

    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by,
                                   checksum, nas_relative_path, created_at, updated_at)
         VALUES (:ue, :fn, :on, :sz, 'application/octet-stream',
                 'nas', 'cold', NOW(), 'recall-test', :cs, :nrp, NOW(), NOW())"
    );
    $stmt->execute([
        ':ue' => TEST_USER_EMAIL, ':fn' => $filename, ':on' => $filename,
        ':sz' => 2048, ':cs' => $checksum, ':nrp' => $nasRel,
    ]);
    $fileId = (int) $pdo->lastInsertId();
    $createdFileIds[] = $fileId;

    // Confirm starting state.
    assertEqual(TierState::COLD, $tier->getState($fileId));

    // The recall.
    $vpsPath = $recall->recallCold($fileId);
    assertTrue(is_file($vpsPath), "vps path should exist after recall: {$vpsPath}");
    assertEqual($payload, file_get_contents($vpsPath));
    assertEqual(TierState::HOT, $tier->getState($fileId));

    $rec = $tier->getRecord($fileId);
    assertEqual(1, (int) $rec['tier_recall_attempts'], 'recall_attempts should be 1');
});

runTest($results, 'second recall on already-hot row is no-op', function () use ($pdo, $tier, $recall, $tenantRoot, $vpsTestDir, &$createdFileIds) {
    $userHash = md5(strtolower(TEST_USER_EMAIL));
    $filename = TEST_PREFIX . bin2hex(random_bytes(4)) . '.bin';
    $payload = random_bytes(512);
    $checksum = md5($payload);
    $nasRel = TEST_TENANT . "/{$userHash}/{$filename}";
    $nasAbs = rtrim($tenantRoot, '/') . "/{$userHash}/{$filename}";
    @mkdir(dirname($nasAbs), 0755, true);
    file_put_contents($nasAbs, $payload);

    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by,
                                   checksum, nas_relative_path, created_at, updated_at)
         VALUES (:ue, :fn, :on, :sz, 'application/octet-stream',
                 'nas', 'cold', NOW(), 'recall-test', :cs, :nrp, NOW(), NOW())"
    );
    $stmt->execute([
        ':ue' => TEST_USER_EMAIL, ':fn' => $filename, ':on' => $filename,
        ':sz' => 512, ':cs' => $checksum, ':nrp' => $nasRel,
    ]);
    $fileId = (int) $pdo->lastInsertId();
    $createdFileIds[] = $fileId;

    $recall->recallCold($fileId); // first
    $attemptsAfter1 = (int) $tier->getRecord($fileId)['tier_recall_attempts'];
    assertEqual(TierState::HOT, $tier->getState($fileId));

    $recall->recallCold($fileId); // second — should be no-op
    $attemptsAfter2 = (int) $tier->getRecord($fileId)['tier_recall_attempts'];
    assertEqual($attemptsAfter1, $attemptsAfter2, 'no-op recall must NOT bump recall_attempts');
});

runTest($results, 'recall with missing NAS bytes throws + rolls back to cold', function () use ($pdo, $tier, $recall, $tenantRoot, &$createdFileIds) {
    $userHash = md5(strtolower(TEST_USER_EMAIL));
    $filename = TEST_PREFIX . bin2hex(random_bytes(4)) . '.bin';
    $checksum = md5('any-bytes');
    $nasRel = TEST_TENANT . "/{$userHash}/{$filename}";
    // DELIBERATELY do not create NAS file — simulate corruption.

    $stmt = $pdo->prepare(
        "INSERT INTO drive_files (user_email, filename, original_name, size, mime_type,
                                   storage_location, tier_state, tier_changed_at, tier_changed_by,
                                   checksum, nas_relative_path, created_at, updated_at)
         VALUES (:ue, :fn, :on, 9, 'application/octet-stream',
                 'nas', 'cold', NOW(), 'recall-test', :cs, :nrp, NOW(), NOW())"
    );
    $stmt->execute([
        ':ue' => TEST_USER_EMAIL, ':fn' => $filename, ':on' => $filename,
        ':cs' => $checksum, ':nrp' => $nasRel,
    ]);
    $fileId = (int) $pdo->lastInsertId();
    $createdFileIds[] = $fileId;

    try {
        $recall->recallCold($fileId);
        assertTrue(false, 'should throw');
    } catch (\RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'recall failed'), 'expected recall failure msg');
    }
    // Must roll back to cold so a retry can pick it up.
    assertEqual(TierState::COLD, $tier->getState($fileId));
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
drive-tier-recall-test - end-to-end test of Phase 5b sync cold recall

Usage:
  drive-tier-recall-test.php [--verbose]

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
    // Scrub the chaos-test tenant subtree of our test artefacts.
    if (is_dir($tenantRoot)) {
        rmTestArtefacts($tenantRoot);
    }
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
            // Only remove the dir if it's now empty AND looks like a per-user hash dir.
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
