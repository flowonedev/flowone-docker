#!/usr/bin/env php
<?php
/**
 * Storage Budget End-to-End Test (Phase 6a).
 *
 * Exercises StorageBudget against the real VPS filesystem AND the
 * real production drive_files table — but without mutating either:
 *
 *   - df is called against the configured VPS mount point
 *   - SUM(size) is read from the real drive_files (READ-ONLY)
 *
 * No rows are created, modified, or deleted. The cleanup hook is
 * empty by design. This is a safe-to-run-anytime smoke test that
 * also serves as operator-visible numbers before flipping any
 * Phase 6 admission-control / reclaim-daemon flag.
 *
 * Assertions:
 *   - watermark resolves to one of the known levels
 *   - both layers populate (vps + drive)
 *   - cache hit returns identical fields
 *   - canAccept respects both layers
 *   - storage-ctl budget --json produces parseable output
 *
 * CLI:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/storage-budget-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "storage-budget-test must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\StorageBudget;
use FlowOne\Storage\StorageBudgetReport;

const HARD_TIMEOUT = 30;

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

$storageConfig = StorageConfig::load();
$budgetCfg = $storageConfig['tier']['budget'] ?? [];
echo "  + budget config loaded (quota=" . ($budgetCfg['drive_quota_bytes'] ?? 0) . " bytes)\n";
echo "  + vps mount = " . ($budgetCfg['vps_mount_point'] ?? '/') . "\n";

$results = ['pass' => 0, 'fail' => 0];

echo "\n=== TESTS ===\n\n--- snapshot integrity ---\n";

runTest($results, 'snapshot returns sensible OS-layer numbers', function () use ($pdo) {
    $svc = StorageBudget::build($pdo);
    $r = $svc->snapshot(bypassCache: true);
    assertTrue($r->vpsTotalBytes > 0, 'vpsTotalBytes must be positive');
    assertTrue($r->vpsFreeBytes >= 0);
    assertTrue($r->vpsUsedBytes >= 0);
    assertTrue($r->vpsUsedPct >= 0.0 && $r->vpsUsedPct <= 100.0);
    assertTrue(is_dir($r->vpsMountPoint), 'mount point must exist');
});

runTest($results, 'logical layer populates when PDO is wired', function () use ($pdo, $storageConfig) {
    $svc = StorageBudget::build($pdo);
    $r = $svc->snapshot(bypassCache: true);
    $quota = (int) ($storageConfig['tier']['budget']['drive_quota_bytes'] ?? 0);
    if ($quota > 0) {
        assertTrue($r->driveQuotaBytes !== null, 'logical layer must be present');
        assertTrue($r->driveUsedBytes !== null);
        assertTrue($r->driveUsedBytes >= 0);
        assertTrue($r->driveHotRows >= 0);
    } else {
        assertTrue($r->driveQuotaBytes === null, 'with quota=0 the logical layer must be null');
    }
});

runTest($results, 'watermark resolves to a known level', function () use ($pdo) {
    $svc = StorageBudget::build($pdo);
    $r = $svc->snapshot(bypassCache: true);
    $valid = [
        StorageBudgetReport::WM_CLEAR,
        StorageBudgetReport::WM_WARN,
        StorageBudgetReport::WM_HIGH,
        StorageBudgetReport::WM_CRITICAL,
    ];
    assertTrue(in_array($r->watermark, $valid, true), "unknown watermark: {$r->watermark}");
    assertTrue(count($r->reasons) >= 1, 'must always have at least one reason');
});

runTest($results, 'second snapshot within TTL returns cached report', function () use ($pdo) {
    $svc = StorageBudget::build($pdo);
    $r1 = $svc->snapshot();
    $r2 = $svc->snapshot();
    assertTrue($r2->fromCache, 'second call should hit cache');
    assertEqual($r1->vpsFreeBytes, $r2->vpsFreeBytes);
    assertEqual($r1->watermark,    $r2->watermark);
});

runTest($results, 'bypassCache forces fresh compute', function () use ($pdo) {
    $svc = StorageBudget::build($pdo);
    $r1 = $svc->snapshot();
    usleep(50000); // 50ms
    $r2 = $svc->snapshot(bypassCache: true);
    assertTrue(!$r2->fromCache, 'bypassCache must return fresh report');
});

runTest($results, 'canAccept(0) is always true on a healthy VPS', function () use ($pdo) {
    $svc = StorageBudget::build($pdo);
    $r = $svc->snapshot(bypassCache: true);
    if (!$r->isCritical()) {
        assertTrue($r->canAccept(0, minFreeBytes: 0), 'zero-byte upload should pass when not critical');
    } else {
        echo "    (skipped: budget is critical right now — check storage-ctl budget)\n";
    }
});

runTest($results, 'canAccept rejects an impossibly large upload', function () use ($pdo) {
    $svc = StorageBudget::build($pdo);
    $r = $svc->snapshot(bypassCache: true);
    assertFalse($r->canAccept(PHP_INT_MAX, minFreeBytes: 0), 'INT_MAX bytes can never fit');
});

runTest($results, 'storage-ctl budget --json produces parseable output', function () {
    $ctl = '/var/www/shared/bin/storage-ctl.php';
    if (!is_file($ctl)) {
        echo "    (skipped: storage-ctl not deployed at {$ctl})\n";
        return;
    }
    $cmd = '/usr/local/lsws/lsphp83/bin/php ' . escapeshellarg($ctl) . ' budget --json 2>&1';
    $out = shell_exec($cmd);
    assertTrue(is_string($out) && $out !== '', 'storage-ctl produced no output');
    $decoded = json_decode((string) $out, true);
    assertTrue(is_array($decoded), 'storage-ctl budget --json was not valid JSON: ' . substr((string) $out, 0, 200));
    assertTrue(isset($decoded['watermark']), 'JSON missing watermark');
    assertTrue(isset($decoded['vps']['free_bytes']), 'JSON missing vps.free_bytes');
});

// ─── Summary ─────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
echo "Passed: {$results['pass']}\nFailed: {$results['fail']}\n";
echo "Elapsed: " . round((microtime(true) - $startedAt) * 1000) . "ms\n";

// Convenience: print the live snapshot so the operator can eyeball it
// after running the test.
echo "\n=== LIVE SNAPSHOT ===\n";
$snap = StorageBudget::build($pdo)->snapshot(bypassCache: true);
echo "  watermark:  " . strtoupper($snap->watermark) . "\n";
echo "  vps:        " . formatBytes($snap->vpsUsedBytes) . " / " . formatBytes($snap->vpsTotalBytes)
    . sprintf(" (%.1f%% used, %s free)\n", $snap->vpsUsedPct, formatBytes($snap->vpsFreeBytes));
if ($snap->driveQuotaBytes !== null) {
    echo "  drive:      " . formatBytes((int) $snap->driveUsedBytes) . " / " . formatBytes($snap->driveQuotaBytes)
        . sprintf(" (%.1f%% used, %d hot/tiering rows)\n", (float) $snap->driveUsedPct, (int) $snap->driveHotRows);
}
foreach ($snap->reasons as $r) {
    echo "  reason:     {$r}\n";
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
storage-budget-test - end-to-end test of Phase 6a StorageBudget

Usage:
  storage-budget-test.php [--verbose]

READ-ONLY. Does not mutate the database or any files. Safe to run
on production at any time. Always prints the current live budget
snapshot at the end so operators can use it as a one-shot status
command.

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

function assertFalse($cond, string $msg = ''): void
{
    if ($cond) throw new \RuntimeException($msg ?: 'expected false, got true');
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg ? "{$msg}: " : '') .
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function formatBytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $exp = (int) floor(log($bytes, 1024));
    $exp = min($exp, count($units) - 1);
    return sprintf('%.2f %s', $bytes / (1024 ** $exp), $units[$exp]);
}
