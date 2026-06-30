#!/usr/bin/env php
<?php
/**
 * Drive Admission Control End-to-End Test (Phase 6b).
 *
 * READ-ONLY against real production data. Exercises AdmissionController
 * with controlled overrides (synthesised budget instances) so we can
 * verify refusal paths without ever flipping the production kill
 * switch or filling the actual VPS.
 *
 * What this test proves:
 *   - Disabled controller is a no-op (kill switch off = current prod)
 *   - Enabled + healthy admits a normal upload
 *   - Enabled + over-quota refuses with StorageBudgetExceededException
 *   - Enabled + critical refuses even a zero-byte ping
 *   - HTTP 503 + Retry-After mapping is correct
 *   - Storage health unwritable forces refusal regardless of budget
 *
 * No DB rows are written. No files are uploaded. The DriveService
 * upload code path is NOT triggered (would risk mutating real data);
 * we test the AdmissionController + StorageBudget end-to-end instead.
 *
 * CLI:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/drive-admission-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-admission-test must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\AdmissionController;
use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\HealthState;
use FlowOne\Storage\HealthStatus;
use FlowOne\Storage\HealthStatusProvider;
use FlowOne\Storage\StorageBudget;
use FlowOne\Storage\StorageBudgetExceededException;
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
$prodFlag = (bool) ($storageConfig['phases']['phase6b_admission_control'] ?? false);
echo "  + phase6b_admission_control in config = " . ($prodFlag ? 'ON' : 'off') . "\n";

$results = ['pass' => 0, 'fail' => 0];

echo "\n=== TESTS ===\n\n--- admission control ---\n";

// Helper: build a budget pointed at a small synthetic VPS dir so we
// can manipulate "free space" numerically without touching real /.
$tmpRoot = sys_get_temp_dir() . '/flowone-admission-test-' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0755, true);
register_shutdown_function(function () use ($tmpRoot) { @rmdir($tmpRoot); });

$buildBudget = function (int $quota, int $minFree, int $critPct = 95) use ($tmpRoot, $pdo): StorageBudget {
    // Real PDO so we read the real drive_files SUM. With a small
    // quota we can force critical without inventing fake data.
    return new StorageBudget(
        vpsMountPoint:    $tmpRoot,
        driveQuotaBytes:  $quota,
        minFreeBytes:     $minFree,
        warnVpsPct:       99, highVpsPct: 99, criticalVpsPct: 99,
        warnDrivePct:     70, highDrivePct: 85, criticalDrivePct: $critPct,
        tableName:        'drive_files',
        cacheTtlSec:      30,
        pdo:              $pdo,
    );
};

runTest($results, 'disabled controller is a no-op', function () use ($buildBudget) {
    // Even a tiny quota that's already exceeded should be ignored when disabled.
    $budget = $buildBudget(quota: 1, minFree: 0);
    $ac = new AdmissionController(budget: $budget, enabled: false);
    $ac->admit(1_000_000_000); // should NOT throw
    $d = $ac->evaluate(1_000_000_000);
    assertTrue($d['accept'], 'disabled controller must accept everything');
    assertTrue(!$d['enabled']);
});

runTest($results, 'enabled + reasonable quota admits a small upload', function () use ($buildBudget) {
    // Huge quota relative to real usage -> always clear.
    $budget = $buildBudget(quota: 100 * 1024 ** 4, minFree: 0); // 100 TiB
    $ac = new AdmissionController(budget: $budget, enabled: true);
    $ac->admit(1024); // 1 KiB
    $d = $ac->evaluate(1024);
    assertTrue($d['accept']);
});

runTest($results, 'enabled + tiny quota force-critical refuses with HTTP 503 + Retry-After', function () use ($buildBudget) {
    // Real drive_files SUM > 1 byte → critical immediately.
    $budget = $buildBudget(quota: 1, minFree: 0);
    $ac = new AdmissionController(budget: $budget, enabled: true);
    try {
        $ac->admit(1);
        assertTrue(false, 'critical budget must refuse');
    } catch (StorageBudgetExceededException $e) {
        $resp = $e->toHttpResponse();
        assertEqual(503, $resp['status_code']);
        assertTrue((int) $resp['headers']['Retry-After'] >= 60);
        assertEqual('storage_budget_exceeded', $resp['body']['error']);
        assertTrue($e instanceof \RuntimeException, 'exception must extend RuntimeException');
    }
});

runTest($results, 'min_free_bytes floor refuses upload that would dip below it', function () use ($buildBudget) {
    // 0-byte tmpRoot -> disk_free_space() returns lots of free bytes;
    // set min_free huge so any non-zero request fails.
    $budget = $buildBudget(quota: 100 * 1024 ** 4, minFree: PHP_INT_MAX - 1);
    $ac = new AdmissionController(budget: $budget, enabled: true);
    try {
        $ac->admit(1);
        assertTrue(false, 'min_free floor must refuse');
    } catch (StorageBudgetExceededException $e) {
        $hasFreeReason = false;
        foreach ($e->reasons as $r) {
            if (str_contains($r, 'min_free_bytes') || str_contains($r, 'past safe limits')) {
                $hasFreeReason = true; break;
            }
        }
        assertTrue($hasFreeReason, 'reasons should mention min_free or safe limits');
    }
});

runTest($results, 'storage health OFFLINE refuses regardless of budget', function () use ($buildBudget) {
    $budget = $buildBudget(quota: 100 * 1024 ** 4, minFree: 0); // budget is clear
    $offlineHealth = new class implements HealthStatusProvider {
        public function getStatus(): HealthStatus {
            return new HealthStatus(
                status:          HealthState::OFFLINE,
                bootEpoch:       1, generation: 1,
                publishedAtUnix: time(),
                source:          'admission-test',
                isStale:         false,
            );
        }
    };
    $ac = new AdmissionController(budget: $budget, enabled: true, health: $offlineHealth);
    try {
        $ac->admit(1024);
        assertTrue(false, 'offline storage must refuse');
    } catch (StorageBudgetExceededException $e) {
        $hasHealthReason = false;
        foreach ($e->reasons as $r) {
            if (str_contains($r, 'storage_health')) { $hasHealthReason = true; break; }
        }
        assertTrue($hasHealthReason, 'reasons should mention storage_health');
        assertTrue($e->retryAfterSec >= AdmissionController::RETRY_AFTER_UNHEALTHY_SEC);
    }
});

runTest($results, 'evaluate() never throws — pure data shape', function () use ($buildBudget) {
    $budget = $buildBudget(quota: 1, minFree: 0); // forced critical
    $ac = new AdmissionController(budget: $budget, enabled: true);
    $d = $ac->evaluate(1);
    assertTrue(is_array($d));
    assertTrue(isset($d['accept'], $d['watermark'], $d['reasons'], $d['retry_after_sec'], $d['report'], $d['enabled']));
    assertTrue($d['accept'] === false);
    assertTrue($d['enabled'] === true);
});

runTest($results, 'AdmissionController::build respects production kill switch', function () use ($pdo, $storageConfig, $prodFlag) {
    $ac = AdmissionController::build($pdo, $storageConfig);
    assertEqual($prodFlag, $ac->isEnabled());
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
drive-admission-test - end-to-end test of Phase 6b admission control

Usage:
  drive-admission-test.php [--verbose]

READ-ONLY. Does not flip the production kill switch, does not upload
any files, does not mutate the database. Builds in-process Admission
Controller instances with synthetic budgets to exercise every refusal
path deterministically.

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
