#!/usr/bin/env php
<?php
/**
 * StuckSiteSweeper Test Suite
 *
 * Verifies that wedged sites (in-flight actual_state with no live
 * `site_jobs` row) are recovered to their canonical "stuck" landing
 * state.
 *
 * Coverage
 * --------
 *   - finds rows in {provisioning, deleting, restoring} older than grace
 *   - SKIPs rows in those states with a queued/running job
 *   - SKIPs rows newer than grace
 *   - lands `provisioning` -> `degraded`
 *   - lands `deleting`     -> `degraded`
 *   - lands `restoring`    -> `failed`
 *   - dry-run reports what WOULD happen without writing
 *   - audit log gets the recovery row
 *   - `pending_dns` is intentionally NOT swept
 *   - orphaned-create rows (absent + latest job is a dead CREATE)
 *     land in `failed`; tombstones / queued creates / jobless rows
 *     are left alone
 *
 * All test data uses a `[flowone_test_]` domain prefix and is removed
 * by the cleanup hook even on SIGINT.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/stuck-site-sweeper-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Reconciler\StuckSiteSweeper;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('StuckSiteSweeper', $opts);

// Shared test fixtures
$db = null;
$pdo = null;
$audit = null;
$stateMachine = null;
$sweeper = null;

/** @var list<int> */
$testSiteIds = [];
/** @var list<string> */
$testDomains = [];

$harness->onCleanup(function () use (&$pdo, &$testDomains) {
    if (!$pdo) {
        return;
    }
    // Belt-and-braces cleanup. We intentionally do NOT key on
    // $testSiteIds: site_audit_log only has `site_domain`, not
    // `site_id`. Domain-based delete is sufficient AND idempotent
    // across multiple runs (every test row carries the
    // `[flowone_test_]ss-` prefix).
    //
    // We also do a prefix-based safety net delete to scoop up rows
    // a previous crashed test run left behind. The prefix is one
    // that cannot appear in a real domain (literal `[`/`]`).
    try {
        if ($testDomains) {
            $in = implode(',', array_fill(0, count($testDomains), '?'));
            $pdo->prepare("DELETE FROM site_audit_log WHERE site_domain IN ({$in})")
                ->execute($testDomains);
            $pdo->prepare("DELETE FROM site_jobs WHERE site_domain IN ({$in})")
                ->execute($testDomains);
            $pdo->prepare("DELETE FROM sites WHERE domain IN ({$in})")
                ->execute($testDomains);
        }
        // Mop up anything from a prior crashed run.
        $pdo->exec("DELETE FROM site_audit_log WHERE site_domain LIKE '[flowone_test_]ss-%'");
        $pdo->exec("DELETE FROM site_jobs      WHERE site_domain LIKE '[flowone_test_]ss-%'");
        $pdo->exec("DELETE FROM sites          WHERE domain      LIKE '[flowone_test_]ss-%'");
    } catch (\Throwable $e) {
        // Surface the error to stderr so a future cleanup failure
        // can't silently leak fixtures into the operator's site list
        // again. We do NOT rethrow because cleanup runs from a
        // shutdown context and a throw here would mask the real
        // test result.
        @fwrite(STDERR, "[stuck-site-sweeper-test] CLEANUP ERROR: "
            . $e->getMessage() . "\n");
    }
});

function ssTestDomain(): string
{
    return '[flowone_test_]ss-' . bin2hex(random_bytes(3)) . '.local';
}

/**
 * Insert a sites row directly with a chosen state and updated_at offset
 * so we can drive sweeper scenarios deterministically.
 */
function insertWedgedSite(\PDO $pdo, string $domain, string $state, int $secondsAgo): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO sites
            (domain, desired_state, actual_state, config, created_at, updated_at)
          VALUES
            (:domain, 'active', :state, '{}', DATE_SUB(NOW(), INTERVAL :s SECOND),
             DATE_SUB(NOW(), INTERVAL :s2 SECOND))"
    );
    $stmt->execute([
        'domain' => $domain,
        'state' => $state,
        's' => $secondsAgo,
        's2' => $secondsAgo,
    ]);
    return (int) $pdo->lastInsertId();
}

function insertActiveJob(\PDO $pdo, string $domain, string $status): int
{
    return insertJob($pdo, $domain, 'delete', $status);
}

function insertJob(\PDO $pdo, string $domain, string $type, string $status): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO site_jobs
            (site_domain, type, status, priority, priority_class,
             payload, schema_version, attempts, max_attempts, dry_run,
             actor, enqueued_at)
          VALUES
            (:domain, :type, :status, 50, 'operator',
             '{}', 1, 0, 3, 0, 'test', NOW(3))"
    );
    $stmt->execute(['domain' => $domain, 'type' => $type, 'status' => $status]);
    return (int) $pdo->lastInsertId();
}

// ─────────────────────────────────────────────────────────────────
// preflight
// ─────────────────────────────────────────────────────────────────

$harness->test('preflight', 'PanelDatabase + sites/site_jobs/audit schemas',
    function () use (&$db, &$pdo, &$audit, &$stateMachine, &$sweeper) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        foreach (['sites', 'site_jobs', 'site_audit_log'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->rowCount() === 0) {
                return ['outcome' => TestHarness::FAIL,
                        'message' => "table {$t} missing"];
            }
        }
        $audit = new AuditLogger($db, new SecretMasker());
        $stateMachine = new SiteStateMachine($db, $audit);
        $sweeper = new StuckSiteSweeper(
            database: $db,
            stateMachine: $stateMachine,
            audit: $audit,
            graceSeconds: 60,
            actor: ActorContext::system('stuck-site-sweeper-test'),
        );
    });

// ─────────────────────────────────────────────────────────────────
// landing maps
// ─────────────────────────────────────────────────────────────────

$harness->test('landing', 'wedged provisioning -> degraded',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'provisioning', secondsAgo: 600);
        $testSiteIds[] = $id;

        $r = $sweeper->sweep(limit: 100);
        $hit = false;
        foreach ($r->recoveries as $rec) {
            if ($rec['site_id'] === $id) {
                $hit = true;
                if ($rec['from'] !== 'provisioning' || $rec['to'] !== 'degraded') {
                    return ['outcome' => TestHarness::FAIL,
                            'message' => "wrong landing: {$rec['from']} -> {$rec['to']}"];
                }
            }
        }
        if (!$hit) {
            return ['outcome' => TestHarness::FAIL,
                    'message' => 'site was not picked up by the sweeper'];
        }
        // Verify the row is now in 'degraded' on disk.
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'degraded'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL, 'message' => "state on disk = {$state}"];
    });

$harness->test('landing', 'wedged deleting -> degraded (the test3 case)',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'deleting', secondsAgo: 600);
        $testSiteIds[] = $id;

        $r = $sweeper->sweep(limit: 100);
        foreach ($r->recoveries as $rec) {
            if ($rec['site_id'] === $id) {
                if ($rec['to'] !== 'degraded') {
                    return ['outcome' => TestHarness::FAIL,
                            'message' => "wrong landing: {$rec['from']} -> {$rec['to']}"];
                }
                $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
                return $state === 'degraded'
                    ? ['outcome' => TestHarness::PASS]
                    : ['outcome' => TestHarness::FAIL, 'message' => "state on disk = {$state}"];
            }
        }
        return ['outcome' => TestHarness::FAIL,
                'message' => 'site was not picked up by the sweeper'];
    });

$harness->test('landing', 'wedged restoring -> failed',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'restoring', secondsAgo: 600);
        $testSiteIds[] = $id;

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'failed'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL, 'message' => "state on disk = {$state}"];
    });

// ─────────────────────────────────────────────────────────────────
// negative space: rows we must NOT touch
// ─────────────────────────────────────────────────────────────────

$harness->test('skip', 'fresh row (within grace) is left alone',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'deleting', secondsAgo: 5);
        $testSiteIds[] = $id;

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'deleting'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "fresh row was wrongly transitioned to {$state}"];
    });

$harness->test('skip', 'wedged row WITH a queued job is left alone',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'deleting', secondsAgo: 600);
        $testSiteIds[] = $id;
        insertActiveJob($pdo, $domain, 'queued');

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'deleting'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "row with queued job was wrongly transitioned to {$state}"];
    });

$harness->test('skip', 'wedged row WITH a running job is left alone',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'provisioning', secondsAgo: 600);
        $testSiteIds[] = $id;
        insertActiveJob($pdo, $domain, 'running');

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'provisioning'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "row with running job was wrongly transitioned to {$state}"];
    });

$harness->test('skip', 'pending_dns is NOT a sweep candidate',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        // pending_dns is allowed to sit indefinitely waiting for DNS;
        // the sweeper deliberately leaves it alone even with no job.
        $id = insertWedgedSite($pdo, $domain, 'pending_dns', secondsAgo: 7200);
        $testSiteIds[] = $id;

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'pending_dns'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "pending_dns was wrongly transitioned to {$state}"];
    });

$harness->test('skip', 'active site is NOT a sweep candidate',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'active', secondsAgo: 7200);
        $testSiteIds[] = $id;

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'active'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "active was wrongly transitioned to {$state}"];
    });

// ─────────────────────────────────────────────────────────────────
// orphaned-create rows (absent + latest job is a dead CREATE)
// ─────────────────────────────────────────────────────────────────

$harness->test('orphan', 'absent row whose latest job is a dead CREATE lands in failed',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'absent', secondsAgo: 600);
        $testSiteIds[] = $id;
        insertJob($pdo, $domain, 'create', 'failed');

        $r = $sweeper->sweep(limit: 100);
        $hit = false;
        foreach ($r->recoveries as $rec) {
            if ($rec['site_id'] === $id) {
                $hit = true;
                if ($rec['from'] !== 'absent' || $rec['to'] !== 'failed') {
                    return ['outcome' => TestHarness::FAIL,
                            'message' => "wrong landing: {$rec['from']} -> {$rec['to']}"];
                }
            }
        }
        if (!$hit) {
            return ['outcome' => TestHarness::FAIL,
                    'message' => 'orphaned-create row was not picked up'];
        }
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'failed'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL, 'message' => "state on disk = {$state}"];
    });

$harness->test('orphan', 'legit tombstone (latest job is a DELETE) is left alone',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'absent', secondsAgo: 600);
        $testSiteIds[] = $id;
        // History: a CREATE long ago, then the DELETE that tombstoned it.
        insertJob($pdo, $domain, 'create', 'succeeded');
        insertJob($pdo, $domain, 'delete', 'succeeded');

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'absent'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "tombstone was wrongly transitioned to {$state}"];
    });

$harness->test('orphan', 'absent row with a QUEUED create job is left alone',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'absent', secondsAgo: 600);
        $testSiteIds[] = $id;
        insertJob($pdo, $domain, 'create', 'queued');

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'absent'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "row with queued create was wrongly transitioned to {$state}"];
    });

$harness->test('orphan', 'absent row with NO jobs at all is left alone',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'absent', secondsAgo: 7200);
        $testSiteIds[] = $id;

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'absent'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "jobless absent row was wrongly transitioned to {$state}"];
    });

$harness->test('orphan', 'fresh orphaned-create row (within grace) is left alone',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'absent', secondsAgo: 5);
        $testSiteIds[] = $id;
        insertJob($pdo, $domain, 'create', 'failed');

        $sweeper->sweep(limit: 100);
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'absent'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "fresh orphan was wrongly transitioned to {$state}"];
    });

// ─────────────────────────────────────────────────────────────────
// dry-run + audit
// ─────────────────────────────────────────────────────────────────

$harness->test('dry-run', 'dry-run reports candidates without writing',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'deleting', secondsAgo: 600);
        $testSiteIds[] = $id;

        $r = $sweeper->sweep(limit: 100, dryRun: true);
        // The candidate must appear in recoveries with dry_run=true.
        $hitDryRun = false;
        foreach ($r->recoveries as $rec) {
            if ($rec['site_id'] === $id && $rec['dry_run'] === true) {
                $hitDryRun = true;
            }
        }
        if (!$hitDryRun) {
            return ['outcome' => TestHarness::FAIL,
                    'message' => 'dry-run did not report the candidate'];
        }
        // Row state on disk MUST still be 'deleting'.
        $state = $pdo->query("SELECT actual_state FROM sites WHERE id = {$id}")->fetchColumn();
        return $state === 'deleting'
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "dry-run modified the row to {$state}"];
    });

$harness->test('audit', 'recovery writes a state_transition audit row',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domain = ssTestDomain();
        $testDomains[] = $domain;
        $id = insertWedgedSite($pdo, $domain, 'deleting', secondsAgo: 600);
        $testSiteIds[] = $id;

        $sweeper->sweep(limit: 100);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE site_domain = :d
                AND action = 'state_transition'"
        );
        $stmt->execute(['d' => $domain]);
        $n = (int) $stmt->fetchColumn();
        return $n >= 1
            ? ['outcome' => TestHarness::PASS]
            : ['outcome' => TestHarness::FAIL,
               'message' => "expected >=1 audit row, got {$n}"];
    });

// ─────────────────────────────────────────────────────────────────
// listCandidates (read-only)
// ─────────────────────────────────────────────────────────────────

$harness->test('list', 'listCandidates returns the same rows sweep would',
    function () use (&$pdo, &$sweeper, &$testSiteIds, &$testDomains): array {
        $domainStuck = ssTestDomain();
        $domainHasJob = ssTestDomain();
        $testDomains[] = $domainStuck;
        $testDomains[] = $domainHasJob;
        $idStuck = insertWedgedSite($pdo, $domainStuck, 'deleting', secondsAgo: 600);
        $idHasJob = insertWedgedSite($pdo, $domainHasJob, 'deleting', secondsAgo: 600);
        $testSiteIds[] = $idStuck;
        $testSiteIds[] = $idHasJob;
        insertActiveJob($pdo, $domainHasJob, 'queued');

        $rows = $sweeper->listCandidates(limit: 100);
        $foundStuck = false;
        $foundHasJob = false;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $idStuck) {
                $foundStuck = true;
            }
            if ((int) $r['id'] === $idHasJob) {
                $foundHasJob = true;
            }
        }
        if (!$foundStuck) {
            return ['outcome' => TestHarness::FAIL,
                    'message' => 'wedged site missing from list'];
        }
        if ($foundHasJob) {
            return ['outcome' => TestHarness::FAIL,
                    'message' => 'wedged-but-has-job site wrongly listed'];
        }
        return ['outcome' => TestHarness::PASS];
    });

// ─────────────────────────────────────────────────────────────────

exit($harness->run());
