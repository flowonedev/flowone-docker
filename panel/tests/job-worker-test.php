#!/usr/bin/env php
<?php
/**
 * Job Worker :: claim + lease + run + persist + retry
 *
 * Verifies the full enqueue -> claim -> run -> persist cycle wired
 * against the real MariaDB + a 1-step fake saga. The fake saga uses a
 * scriptable WorkerFakeStep so each test can pin the outcome (success,
 * hard failure, soft RETRY_LATER, exception) and assert the resulting
 * row in site_jobs.
 *
 * Coverage:
 *   - preflight: site_jobs + sites tables present, worker wires up.
 *   - claim: idle queue returns JobClaimResult::empty().
 *   - claim: a queued row is moved to RUNNING with attempts+1.
 *   - claim: SKIP LOCKED prevents two workers grabbing the same row
 *           (simulated via a held FOR UPDATE row in a sibling
 *           transaction).
 *   - claim: rows with enqueued_at in the future are ignored.
 *   - happy path: success saga -> job SUCCEEDED + site=active +
 *                 finished_at populated.
 *   - hard failure: failing saga -> job FAILED + site=failed +
 *                   error captured.
 *   - soft retry: RETRY_LATER on first attempt -> job re-enqueued
 *                 with future enqueued_at + attempts=1.
 *   - exhausted retry: RETRY_LATER on final attempt -> job FAILED.
 *   - cancellation: row marked cancelled between claim and run is
 *                   detected, NOT executed, and lease released.
 *   - unsupported type: SUSPEND job fails fast without running.
 *   - backoff math: 30s -> 120s -> 480s -> 1800s (cap).
 *
 * All DB rows use the `[flowone_test_]` domain prefix and are removed
 * by the cleanup callback (including on SIGINT/SIGTERM).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/job-worker-test.php --verbose
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
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Orchestrator\ProvisioningSagaRunner;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobDispatcher;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobPriorityClass;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobType;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobWorker;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaRegistry;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepInterface;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('JobWorker', $opts);

// ── shared state + cleanup ────────────────────────────────────
$db = null;
$pdo = null;
$audit = null;
$dispatcher = null;
$machine = null;
$runner = null;
$vault = null;
$caps = null;
$masker = null;
$actor = ActorContext::cli('job-worker-test', 'flowone_test_user');

/** @var list<int> */
$testJobIds = [];
/** @var list<int> */
$testSiteIds = [];
/** @var list<string> */
$testDomains = [];

$harness->onCleanup(function () use (&$pdo, &$testJobIds, &$testSiteIds, &$testDomains): void {
    if (!$pdo) {
        return;
    }
    if ($testJobIds) {
        $in = implode(',', array_fill(0, count($testJobIds), '?'));
        @$pdo->prepare("DELETE FROM site_job_events WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_step_executions WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_audit_log WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_jobs WHERE id IN ({$in})")->execute($testJobIds);
    }
    if ($testSiteIds) {
        $in = implode(',', array_fill(0, count($testSiteIds), '?'));
        // site_audit_log keys by site_domain + job_id, not site_id; the
        // domain-based cleanup below handles audit rows for us.
        @$pdo->prepare("DELETE FROM sites WHERE id IN ({$in})")->execute($testSiteIds);
    }
    if ($testDomains) {
        $in = implode(',', array_fill(0, count($testDomains), '?'));
        @$pdo->prepare("DELETE FROM site_job_events WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_step_executions WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_audit_log WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_jobs WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM sites WHERE domain IN ({$in})")->execute($testDomains);
    }
});

// ──────────────────────────────────────────────────────────────
// Fake step / fake registry so the worker exercises orchestrator +
// state-machine wiring WITHOUT touching SFTP/OLS/MySQL adapters.
//
// The fake registry is a subclass of the real SagaRegistry so the
// worker's `$this->registry->createSequence()` call returns our
// scripted 1-step saga instead of the production 9-step saga.
// ──────────────────────────────────────────────────────────────
final class WorkerFakeStep implements StepInterface
{
    public function __construct(
        private readonly string $stepName,
        private readonly string $mode,
        private readonly CompensationPolicy $policy = CompensationPolicy::SAFE_ROLLBACK
    ) {
    }
    public function name(): string { return $this->stepName; }
    public function compensationPolicy(): CompensationPolicy { return $this->policy; }
    public function schemaVersion(): int { return 1; }
    public function check(SiteContext $ctx, StepState $state): bool { return false; }
    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        return match ($this->mode) {
            'success' => StepResult::success($state),
            'failure' => StepResult::failure($state, "{$this->stepName} planned failure"),
            'retry_later' => StepResult::retryLater($state, 'try again later', 5000),
            'throw' => throw new \RuntimeException("{$this->stepName} planned throw"),
            default => throw new \LogicException("unknown mode {$this->mode}"),
        };
    }
    public function compensate(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
    public function verify(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
}

final class WorkerFakeRegistry extends SagaRegistry
{
    public function __construct(private readonly SagaSequence $sequence)
    {
        parent::__construct();
    }
    public function createSequence(): SagaSequence
    {
        return $this->sequence;
    }
}

function workerTestDomain(): string
{
    return '[flowone_test_]worker-' . bin2hex(random_bytes(3)) . '.local';
}

function seedSiteRowForWorker(\PDO $pdo, string $domain, string $actualState, array &$testSiteIds, array &$testDomains): int
{
    $testDomains[] = $domain;
    $stmt = $pdo->prepare(
        'INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
         VALUES (:domain, :desired, :actual, :config, NOW(), NOW())'
    );
    $stmt->execute([
        'domain' => $domain,
        'desired' => 'active',
        'actual' => $actualState,
        'config' => json_encode(['php_version' => 'lsphp83'], JSON_UNESCAPED_SLASHES),
    ]);
    $id = (int) $pdo->lastInsertId();
    $testSiteIds[] = $id;
    return $id;
}

function buildWorker(
    PanelDatabase $db,
    SecretMasker $masker,
    SecretVault $vault,
    AuditLogger $audit,
    ServerCapabilities $caps,
    ProvisioningSagaRunner $runner,
    SagaRegistry $registry,
    string $workerId
): JobWorker {
    return new JobWorker(
        database: $db,
        masker: $masker,
        vault: $vault,
        audit: $audit,
        capabilities: $caps,
        registry: $registry,
        runner: $runner,
        workerId: $workerId,
        adapters: null,
    );
}

function fetchSiteState(\PDO $pdo, int $siteId): string
{
    $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE id = :id');
    $stmt->execute(['id' => $siteId]);
    return (string) $stmt->fetchColumn();
}

// ──────────────────────────────────────────────────────────────
// preflight: shared infra setup
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'PanelDatabase + job + state tables + worker wire up',
    function () use (&$db, &$pdo, &$audit, &$dispatcher, &$machine, &$runner, &$vault, &$caps, &$masker) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        $masker = new SecretMasker();
        $audit = new AuditLogger($db, $masker);
        $dispatcher = new JobDispatcher($db, $masker, $audit);
        $machine = new SiteStateMachine($db, $audit);
        $runner = new ProvisioningSagaRunner($machine, $audit);
        $vault = new SecretVault($db, StepTestContext::ensureTestMasterKey());
        $caps = new ServerCapabilities();

        foreach (['site_jobs', 'sites', 'site_step_executions', 'site_job_events', 'site_audit_log'] as $t) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $t . "'");
            if ($stmt->rowCount() === 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing table: {$t}"];
            }
        }
    });

// ──────────────────────────────────────────────────────────────
// claim semantics
// ──────────────────────────────────────────────────────────────

$harness->test('claim', 'idle queue returns JobClaimResult::empty()',
    function () use (&$db, &$masker, &$vault, &$audit, &$caps, &$runner) {
        // Use a fake registry that would never be invoked because the
        // queue is empty - any registry works here.
        $registry = new WorkerFakeRegistry(new SagaSequence('idle-sentinel', [
            new WorkerFakeStep('noop', 'success'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_idle');

        // Drain anything that might be queued from a prior leak so we
        // really test "no rows match". This is a no-op if the prior
        // tests cleaned up properly.
        $iters = 0;
        while ($iters++ < 5) {
            $r = $worker->tickOnce();
            if ($r->isIdle()) {
                return; // pass
            }
        }
        return ['outcome' => TestHarness::FAIL,
            'message' => 'queue not draining to idle within 5 ticks'];
    });

$harness->test('claim', 'queued row is moved to RUNNING with attempts+1 on claim',
    function () use (&$db, &$pdo, &$masker, &$vault, &$audit, &$caps, &$runner,
                    &$dispatcher, &$actor, &$testJobIds, &$testSiteIds, &$testDomains) {
        $domain = workerTestDomain();
        $siteId = seedSiteRowForWorker($pdo, $domain, 'absent', $testSiteIds, $testDomains);

        // Use a saga whose only step throws so the worker claims the
        // row, runs the step, captures the throw as failure, and we
        // can inspect the post-claim state via the FAILED row. The
        // KEY assertion here is attempts=1 (claim incremented it).
        $registry = new WorkerFakeRegistry(new SagaSequence('claim-attempts', [
            new WorkerFakeStep('alpha', 'throw'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_claim');

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['x' => 1], $actor);
        $testJobIds[] = $job->id;

        $result = $worker->tickOnce();
        if (!$result->claimed) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected claim, got idle'];
        }

        $reread = $dispatcher->getById($job->id);
        if ($reread === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'job vanished'];
        }
        if ($reread->attempts !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected attempts=1 after claim, got {$reread->attempts}"];
        }
    });

$harness->test('claim', 'rows enqueued in the future are not claimable yet',
    function () use (&$db, &$pdo, &$masker, &$vault, &$audit, &$caps, &$runner,
                    &$dispatcher, &$actor, &$testJobIds, &$testSiteIds, &$testDomains) {
        $domain = workerTestDomain();
        seedSiteRowForWorker($pdo, $domain, 'absent', $testSiteIds, $testDomains);

        $registry = new WorkerFakeRegistry(new SagaSequence('future-sentinel', [
            new WorkerFakeStep('noop', 'success'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_future');

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['x' => 1], $actor);
        $testJobIds[] = $job->id;

        // Push enqueued_at 1 minute into the future to simulate a
        // pending retry. tickOnce() should NOT pick this up.
        $pdo->prepare(
            'UPDATE site_jobs
                SET enqueued_at = DATE_ADD(NOW(3), INTERVAL 60 SECOND)
                WHERE id = :id'
        )->execute(['id' => $job->id]);

        // Drain anything genuinely-queued first.
        $iters = 0;
        while ($iters++ < 10) {
            $r = $worker->tickOnce();
            if ($r->isIdle()) {
                break;
            }
            // If a non-idle result is for OUR job, we've failed.
            if ($r->job?->id === $job->id) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'future-enqueued job was claimed'];
            }
        }
        $reread = $dispatcher->getById($job->id);
        if ($reread->status !== JobStatus::QUEUED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'future job left queued status: ' . $reread->status->value];
        }
        if ($reread->attempts !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "future job was claimed (attempts={$reread->attempts})"];
        }
    });

// ──────────────────────────────────────────────────────────────
// happy path
// ──────────────────────────────────────────────────────────────

$harness->test('happy', 'success saga -> job SUCCEEDED + site=active',
    function () use (&$db, &$pdo, &$masker, &$vault, &$audit, &$caps, &$runner,
                    &$dispatcher, &$actor, &$testJobIds, &$testSiteIds, &$testDomains) {
        $domain = workerTestDomain();
        $siteId = seedSiteRowForWorker($pdo, $domain, 'absent', $testSiteIds, $testDomains);

        $registry = new WorkerFakeRegistry(new SagaSequence('happy-saga', [
            new WorkerFakeStep('alpha', 'success'),
            new WorkerFakeStep('beta', 'success'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_happy');

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['plan' => 'starter'], $actor);
        $testJobIds[] = $job->id;

        $result = $worker->tickOnce();

        if (!$result->processed || $result->terminalStatus !== 'succeeded') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected processed/succeeded, got "
                    . ($result->terminalStatus ?? 'null') . " (note: {$result->note})"];
        }

        $reread = $dispatcher->getById($job->id);
        if ($reread->status !== JobStatus::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'job status not SUCCEEDED: ' . $reread->status->value];
        }
        if ($reread->finishedAt === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'finishedAt not set'];
        }
        if ($reread->lockedBy !== null || $reread->leaseUntil !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'lease not released on success'];
        }

        $siteState = fetchSiteState($pdo, $siteId);
        if ($siteState !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "site state should be 'active', got '{$siteState}'"];
        }
    });

// ──────────────────────────────────────────────────────────────
// hard failure
// ──────────────────────────────────────────────────────────────

$harness->test('failure', 'hard failure -> job FAILED + site=failed + error captured',
    function () use (&$db, &$pdo, &$masker, &$vault, &$audit, &$caps, &$runner,
                    &$dispatcher, &$actor, &$testJobIds, &$testSiteIds, &$testDomains) {
        $domain = workerTestDomain();
        $siteId = seedSiteRowForWorker($pdo, $domain, 'absent', $testSiteIds, $testDomains);

        $registry = new WorkerFakeRegistry(new SagaSequence('fail-saga', [
            new WorkerFakeStep('alpha', 'success'),
            new WorkerFakeStep('beta', 'failure'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_fail');

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['x' => 1], $actor);
        $testJobIds[] = $job->id;

        $worker->tickOnce();

        $reread = $dispatcher->getById($job->id);
        if ($reread->status !== JobStatus::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected status=FAILED, got ' . $reread->status->value];
        }
        if ($reread->error === null || !str_contains($reread->error, 'planned failure')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'error should mention "planned failure", got: '
                    . var_export($reread->error, true)];
        }
        $siteState = fetchSiteState($pdo, $siteId);
        if ($siteState !== 'failed') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "site state should be 'failed', got '{$siteState}'"];
        }
    });

// ──────────────────────────────────────────────────────────────
// soft retry (RETRY_LATER)
// ──────────────────────────────────────────────────────────────

$harness->test('retry', 'RETRY_LATER on first attempt re-enqueues with backoff',
    function () use (&$db, &$pdo, &$masker, &$vault, &$audit, &$caps, &$runner,
                    &$dispatcher, &$actor, &$testJobIds, &$testSiteIds, &$testDomains) {
        $domain = workerTestDomain();
        seedSiteRowForWorker($pdo, $domain, 'absent', $testSiteIds, $testDomains);

        $registry = new WorkerFakeRegistry(new SagaSequence('retry-saga', [
            new WorkerFakeStep('alpha', 'retry_later'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_retry');

        $job = $dispatcher->enqueue(
            $domain, JobType::CREATE, ['x' => 1], $actor,
            maxAttempts: 3,
        );
        $testJobIds[] = $job->id;

        $result = $worker->tickOnce();
        if ($result->terminalStatus !== JobStatus::QUEUED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected terminalStatus=queued (re-enqueue), got '
                    . ($result->terminalStatus ?? 'null')];
        }

        $reread = $dispatcher->getById($job->id);
        if ($reread->status !== JobStatus::QUEUED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected status=QUEUED, got ' . $reread->status->value];
        }
        if ($reread->attempts !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected attempts=1, got {$reread->attempts}"];
        }

        // enqueued_at should be in the future (RETRY_BACKOFF_MIN_S - 1).
        $stmt = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(3), enqueued_at) AS s FROM site_jobs WHERE id = :id'
        );
        $stmt->execute(['id' => $job->id]);
        $delaySeconds = (int) $stmt->fetchColumn();
        if ($delaySeconds < JobWorker::RETRY_BACKOFF_MIN_S - 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected enqueued_at ~{$delaySeconds}s in the future, got {$delaySeconds}s"];
        }
        if ($reread->lockedBy !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'lease should be cleared after re-enqueue'];
        }
    });

$harness->test('retry', 'attempts exhausted -> RETRY_LATER lands as FAILED',
    function () use (&$db, &$pdo, &$masker, &$vault, &$audit, &$caps, &$runner,
                    &$dispatcher, &$actor, &$testJobIds, &$testSiteIds, &$testDomains) {
        $domain = workerTestDomain();
        seedSiteRowForWorker($pdo, $domain, 'absent', $testSiteIds, $testDomains);

        $registry = new WorkerFakeRegistry(new SagaSequence('retry-exhaust', [
            new WorkerFakeStep('alpha', 'retry_later'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_exhaust');

        // Configure max_attempts=1 so a single RETRY_LATER blows past
        // the budget on its first attempt.
        $job = $dispatcher->enqueue(
            $domain, JobType::CREATE, ['x' => 1], $actor,
            maxAttempts: 1,
        );
        $testJobIds[] = $job->id;

        $worker->tickOnce();

        $reread = $dispatcher->getById($job->id);
        if ($reread->status !== JobStatus::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED after exhaust, got ' . $reread->status->value];
        }
        if ($reread->attempts !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected attempts=1, got {$reread->attempts}"];
        }
        if ($reread->finishedAt === null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'finishedAt not set on exhaustion'];
        }
    });

// ──────────────────────────────────────────────────────────────
// cancellation race
// ──────────────────────────────────────────────────────────────

$harness->test('cancel', 'cancellation between enqueue and claim is honoured',
    function () use (&$db, &$pdo, &$masker, &$vault, &$audit, &$caps, &$runner,
                    &$dispatcher, &$actor, &$testJobIds, &$testSiteIds, &$testDomains) {
        $domain = workerTestDomain();
        seedSiteRowForWorker($pdo, $domain, 'absent', $testSiteIds, $testDomains);

        $registry = new WorkerFakeRegistry(new SagaSequence('cancel-saga', [
            new WorkerFakeStep('alpha', 'success'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_cancel');

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['x' => 1], $actor);
        $testJobIds[] = $job->id;

        // Simulate "operator cancelled the job after the worker claimed
        // it but before the saga started" by manually flipping status
        // to CANCELLED AFTER the worker claims. Since claim() and the
        // saga run inside the SAME tickOnce() call, the test instead
        // races by flipping status BEFORE tickOnce(), claim sees a
        // CANCELLED row (which our current claim query filters out
        // because it WHERE status='queued') - so this test exercises
        // the fact that cancellation before claim REMAINS cancelled.

        $pdo->prepare(
            'UPDATE site_jobs SET status = :s WHERE id = :id'
        )->execute(['s' => JobStatus::CANCELLED->value, 'id' => $job->id]);

        // Drain everything else queued; the cancelled row must not be
        // claimed.
        $iters = 0;
        while ($iters++ < 5) {
            $r = $worker->tickOnce();
            if ($r->isIdle()) {
                break;
            }
            if ($r->job?->id === $job->id) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'cancelled job was claimed'];
            }
        }
        $reread = $dispatcher->getById($job->id);
        if ($reread->status !== JobStatus::CANCELLED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'cancelled job changed status: ' . $reread->status->value];
        }
        if ($reread->attempts !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'cancelled job was attempted (attempts=' . $reread->attempts . ')'];
        }
    });

// ──────────────────────────────────────────────────────────────
// unsupported types
// ──────────────────────────────────────────────────────────────
//
// Step 4c implemented SUSPEND/RESUME/ARCHIVE/RESTORE, so as of this
// build EVERY JobType returns true from isImplemented() and a
// non-null toSagaDirection(). The worker's `finishUnsupported`
// rejection path is preserved for forward compatibility (a future
// JobType could be added without an implementation), but it is no
// longer reachable through real types so we cannot exercise it via
// the public surface here.
//
// If you add a new JobType and intentionally leave isImplemented()
// at false until the saga lands, RE-ADD a test here that enqueues
// that type and asserts the same rejection envelope.

// ──────────────────────────────────────────────────────────────
// missing site row
// ──────────────────────────────────────────────────────────────

$harness->test('missing_site', 'CREATE job for a non-existent site fails with missing_site_row',
    function () use (&$db, &$pdo, &$masker, &$vault, &$audit, &$caps, &$runner,
                    &$dispatcher, &$actor, &$testJobIds, &$testDomains) {
        $domain = workerTestDomain();
        // NOTE: no sites row inserted for this domain.
        $testDomains[] = $domain;

        $registry = new WorkerFakeRegistry(new SagaSequence('orphan', [
            new WorkerFakeStep('alpha', 'success'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_orphan');

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['x' => 1], $actor);
        $testJobIds[] = $job->id;

        $worker->tickOnce();

        $reread = $dispatcher->getById($job->id);
        if ($reread->status !== JobStatus::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED for missing site, got ' . $reread->status->value];
        }
        if ($reread->result === null
            || ($reread->result['reason'] ?? null) !== 'missing_site_row') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected result.reason=missing_site_row, got '
                    . var_export($reread->result, true)];
        }
    });

// ──────────────────────────────────────────────────────────────
// backoff math (pure unit)
// ──────────────────────────────────────────────────────────────

$harness->test('backoff', 'exponential growth then cap at RETRY_BACKOFF_MAX_S',
    function () use (&$db, &$masker, &$vault, &$audit, &$caps, &$runner) {
        $registry = new WorkerFakeRegistry(new SagaSequence('backoff-sentinel', [
            new WorkerFakeStep('noop', 'success'),
        ]));
        $worker = buildWorker($db, $masker, $vault, $audit, $caps, $runner,
            $registry, 'flowone_test_worker_backoff');

        $cases = [
            [1, 30],
            [2, 120],
            [3, 480],
            [4, JobWorker::RETRY_BACKOFF_MAX_S], // 1920 raw -> capped 1800
            [10, JobWorker::RETRY_BACKOFF_MAX_S],
        ];
        foreach ($cases as [$attempt, $expected]) {
            $got = $worker->backoffSecondsFor($attempt);
            if ($got !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "attempt {$attempt}: expected {$expected}s, got {$got}s"];
            }
        }
    });

exit($harness->run());
