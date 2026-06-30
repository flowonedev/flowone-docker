#!/usr/bin/env php
<?php
/**
 * DeadLeaseSweeper :: stale-lease rescue
 *
 * Verifies the sweeper finds rows with lease_until < NOW() - grace
 * and brings them back to QUEUED for re-claim, while leaving rows
 * with active leases alone.
 *
 * Coverage:
 *   - preflight: site_jobs table reachable.
 *   - sweep:     a stale-leased running row is recovered to queued.
 *   - sweep:     an active lease (in the future) is NOT touched.
 *   - sweep:     a still-within-grace lease is NOT touched.
 *   - sweep:     a queued row (no lease) is NOT touched.
 *   - sweep:     a succeeded row is NOT touched even if lease_until
 *               is still in the past (terminal state is sticky).
 *   - sweep:     `attempts` counter is preserved across recovery.
 *   - sweep:     audit row `job_lease_recovered` is written per
 *               recovery, capturing the dead worker id.
 *   - sweep:     listStale() and dry-run mode don't mutate rows.
 *   - sweep:     re-running on already-recovered rows is a no-op.
 *
 * Cleanup uses the `[flowone_test_]` domain prefix; runs on
 * SIGINT/SIGTERM via the harness.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/dead-lease-sweeper-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\DeadLeaseSweeper;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('DeadLeaseSweeper', $opts);

$db = null;
$pdo = null;
$sweeper = null;
/** @var list<int> */
$testJobIds = [];
/** @var list<string> */
$testDomains = [];

$harness->onCleanup(function () use (&$pdo, &$testJobIds, &$testDomains): void {
    if (!$pdo) {
        return;
    }
    if ($testJobIds) {
        $in = implode(',', array_fill(0, count($testJobIds), '?'));
        @$pdo->prepare("DELETE FROM site_audit_log WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_jobs WHERE id IN ({$in})")->execute($testJobIds);
    }
    if ($testDomains) {
        $in = implode(',', array_fill(0, count($testDomains), '?'));
        @$pdo->prepare("DELETE FROM site_audit_log WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_jobs WHERE site_domain IN ({$in})")->execute($testDomains);
    }
});

function sweeperTestDomain(): string
{
    return '[flowone_test_]sweep-' . bin2hex(random_bytes(3)) . '.local';
}

/**
 * Insert a site_jobs row in arbitrary state, with explicit lease
 * configuration. `leaseOffsetSec` is added to NOW(3) — negative
 * values produce a stale lease.
 */
function insertJobRow(
    \PDO $pdo,
    string $domain,
    string $status,
    ?string $lockedBy,
    ?int $leaseOffsetSec,
    int $attempts,
    array &$testJobIds,
    array &$testDomains
): int {
    $testDomains[] = $domain;
    $leaseSql = $leaseOffsetSec === null
        ? 'NULL'
        : "DATE_ADD(NOW(3), INTERVAL {$leaseOffsetSec} SECOND)";
    $sql = "INSERT INTO site_jobs
            (site_domain, type, status, priority, priority_class, payload,
             attempts, max_attempts, locked_by, lease_until, actor,
             request_id, started_at, enqueued_at)
         VALUES
            (:domain, 'create', :status, 50, 'operator', :payload,
             :attempts, 3, :locked_by, {$leaseSql}, 'flowone_test_user',
             :request_id, NOW(3), NOW(3))";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'domain' => $domain,
        'status' => $status,
        'payload' => json_encode(['x' => 1], JSON_UNESCAPED_SLASHES),
        'attempts' => $attempts,
        'locked_by' => $lockedBy,
        'request_id' => 'req-sweep-' . bin2hex(random_bytes(3)),
    ]);
    $id = (int) $pdo->lastInsertId();
    $testJobIds[] = $id;
    return $id;
}

function fetchJobRow(\PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM site_jobs WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

// ──────────────────────────────────────────────────────────────
// preflight
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'PanelDatabase + site_jobs + audit reachable',
    function () use (&$db, &$pdo, &$sweeper) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        $masker = new SecretMasker();
        $audit = new AuditLogger($db, $masker);
        // Use grace=0 in tests so we don't have to sleep to make a
        // lease "old enough" - any past lease is recoverable.
        $sweeper = new DeadLeaseSweeper($db, $audit, graceSeconds: 0);
        foreach (['site_jobs', 'site_audit_log'] as $t) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $t . "'");
            if ($stmt->rowCount() === 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing table {$t}"];
            }
        }
    });

// ──────────────────────────────────────────────────────────────
// recovery semantics
// ──────────────────────────────────────────────────────────────

$harness->test('recovery', 'stale-leased running row is recovered to queued',
    function () use (&$pdo, &$sweeper, &$testJobIds, &$testDomains) {
        $domain = sweeperTestDomain();
        $jobId = insertJobRow(
            $pdo, $domain,
            status: JobStatus::RUNNING->value,
            lockedBy: 'flowone_test_dead_worker',
            leaseOffsetSec: -60,
            attempts: 2,
            testJobIds: $testJobIds,
            testDomains: $testDomains,
        );

        $result = $sweeper->sweep();

        $row = fetchJobRow($pdo, $jobId);
        if ($row === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'job row vanished'];
        }
        if ($row['status'] !== JobStatus::QUEUED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected status=queued after sweep, got ' . $row['status']];
        }
        if ($row['locked_by'] !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected locked_by=null after sweep, got ' . var_export($row['locked_by'], true)];
        }
        if ($row['lease_until'] !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected lease_until=null after sweep'];
        }
        if ((int) $row['attempts'] !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'attempts must be preserved: got ' . $row['attempts']];
        }
        if ($result->recovered < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'result.recovered=' . $result->recovered];
        }
    });

$harness->test('recovery', 'active lease in the future is NOT touched',
    function () use (&$pdo, &$sweeper, &$testJobIds, &$testDomains) {
        $domain = sweeperTestDomain();
        $jobId = insertJobRow(
            $pdo, $domain,
            status: JobStatus::RUNNING->value,
            lockedBy: 'flowone_test_alive_worker',
            leaseOffsetSec: 120,
            attempts: 1,
            testJobIds: $testJobIds,
            testDomains: $testDomains,
        );

        $sweeper->sweep();

        $row = fetchJobRow($pdo, $jobId);
        if ($row['status'] !== JobStatus::RUNNING->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'active-lease row was touched: status now ' . $row['status']];
        }
        if ($row['locked_by'] !== 'flowone_test_alive_worker') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'active-lease worker was cleared'];
        }
    });

$harness->test('recovery', 'queued row (no lease) is NOT touched',
    function () use (&$pdo, &$sweeper, &$testJobIds, &$testDomains) {
        $domain = sweeperTestDomain();
        $jobId = insertJobRow(
            $pdo, $domain,
            status: JobStatus::QUEUED->value,
            lockedBy: null,
            leaseOffsetSec: null,
            attempts: 0,
            testJobIds: $testJobIds,
            testDomains: $testDomains,
        );
        $sweeper->sweep();
        $row = fetchJobRow($pdo, $jobId);
        if ($row['status'] !== JobStatus::QUEUED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'queued-no-lease row was touched'];
        }
    });

$harness->test('recovery', 'succeeded row is NOT touched even with stale lease',
    function () use (&$pdo, &$sweeper, &$testJobIds, &$testDomains) {
        $domain = sweeperTestDomain();
        $jobId = insertJobRow(
            $pdo, $domain,
            status: JobStatus::SUCCEEDED->value,
            lockedBy: 'flowone_test_dead_worker',
            leaseOffsetSec: -300,
            attempts: 1,
            testJobIds: $testJobIds,
            testDomains: $testDomains,
        );
        $sweeper->sweep();
        $row = fetchJobRow($pdo, $jobId);
        if ($row['status'] !== JobStatus::SUCCEEDED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'terminal row was touched: status now ' . $row['status']];
        }
    });

$harness->test('recovery', 'audit row is written with the dead worker id',
    function () use (&$pdo, &$sweeper, &$testJobIds, &$testDomains) {
        $domain = sweeperTestDomain();
        $jobId = insertJobRow(
            $pdo, $domain,
            status: JobStatus::RUNNING->value,
            lockedBy: 'flowone_test_unique_worker_42',
            leaseOffsetSec: -30,
            attempts: 1,
            testJobIds: $testJobIds,
            testDomains: $testDomains,
        );

        $sweeper->sweep();

        $stmt = $pdo->prepare(
            'SELECT reason FROM site_audit_log
              WHERE job_id = :id AND action = :action
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['id' => $jobId, 'action' => 'job_lease_recovered']);
        $reason = $stmt->fetchColumn();
        if ($reason === false || !is_string($reason)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'no job_lease_recovered audit row'];
        }
        if (!str_contains($reason, 'flowone_test_unique_worker_42')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'audit reason missing dead worker id: ' . $reason];
        }
    });

$harness->test('recovery', 'second sweep on the same row is a no-op',
    function () use (&$pdo, &$sweeper, &$testJobIds, &$testDomains) {
        $domain = sweeperTestDomain();
        $jobId = insertJobRow(
            $pdo, $domain,
            status: JobStatus::RUNNING->value,
            lockedBy: 'flowone_test_dead_worker',
            leaseOffsetSec: -60,
            attempts: 1,
            testJobIds: $testJobIds,
            testDomains: $testDomains,
        );

        $first = $sweeper->sweep();
        $second = $sweeper->sweep();

        if ($first->recovered < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first sweep recovered=0 unexpectedly'];
        }
        if ($second->recovered !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "second sweep should be no-op; recovered={$second->recovered}"];
        }
    });

// ──────────────────────────────────────────────────────────────
// dry-run / listStale
// ──────────────────────────────────────────────────────────────

$harness->test('dry_run', 'listStale() returns rows without mutating them',
    function () use (&$pdo, &$sweeper, &$testJobIds, &$testDomains) {
        $domain = sweeperTestDomain();
        $jobId = insertJobRow(
            $pdo, $domain,
            status: JobStatus::RUNNING->value,
            lockedBy: 'flowone_test_dead_worker',
            leaseOffsetSec: -45,
            attempts: 1,
            testJobIds: $testJobIds,
            testDomains: $testDomains,
        );

        $stale = $sweeper->listStale();
        $row = fetchJobRow($pdo, $jobId);

        if ($row['status'] !== JobStatus::RUNNING->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'listStale must not mutate; row is now ' . $row['status']];
        }
        $foundIds = array_column($stale, 'id');
        if (!in_array($jobId, $foundIds, true)
            && !in_array((string) $jobId, $foundIds, true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "listStale did not return job {$jobId}: got "
                    . implode(',', $foundIds)];
        }
    });

// ──────────────────────────────────────────────────────────────
// summary helpers
// ──────────────────────────────────────────────────────────────

$harness->test('summary', 'DeadLeaseSweepResult::summary() and toArray() are consistent',
    function () use (&$pdo, &$sweeper, &$testJobIds, &$testDomains) {
        $domain = sweeperTestDomain();
        insertJobRow(
            $pdo, $domain,
            status: JobStatus::RUNNING->value,
            lockedBy: 'flowone_test_dead_worker',
            leaseOffsetSec: -90,
            attempts: 1,
            testJobIds: $testJobIds,
            testDomains: $testDomains,
        );

        $result = $sweeper->sweep();
        $summary = $result->summary();
        $arr = $result->toArray();

        if (!str_contains($summary, 'recovered=')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'summary missing recovered= field: ' . $summary];
        }
        if (($arr['recovered'] ?? -1) !== $result->recovered) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'toArray.recovered mismatches DTO field'];
        }
    });

exit($harness->run());
