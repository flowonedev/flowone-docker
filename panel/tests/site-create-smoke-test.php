#!/usr/bin/env php
<?php
/**
 * V2 Site Creation :: end-to-end smoke test
 *
 * Required by .cursor/rules/server-side-testing.mdc and by the
 * "Consolidate Site Creation on V2" plan (Phase 0). This is the
 * minimum thing that must pass before we can claim "V2 reliably
 * creates sites":
 *
 *   1. POST-equivalent: call ProvisioningAction::enqueueCreate
 *      (the same code path the API controller uses) and assert a
 *      site_jobs row in QUEUED state + a sites row in 'provisioning'.
 *   2. Worker tick: run JobWorker::tickOnce() against a scripted
 *      sequence that emulates the real CREATE saga's contract
 *      (multiple steps + DEGRADE_ONLY SSL barrier) without touching
 *      OLS / DNS / SSL / SFTP / MySQL DDL. The point of this test
 *      is the QUEUE -> WORKER -> SAGA -> STATE_MACHINE plumbing,
 *      not the production step implementations (those have their
 *      own suites).
 *   3. Terminal state: assert sites.actual_state == 'active' and
 *      site_jobs.status == 'succeeded' and finished_at populated.
 *   4. Event tail: assert site_job_events received the lifecycle
 *      events that JobProgressModal polls.
 *   5. Reconciler smoke: bootstrap the reconciler the same way the
 *      systemd unit does and run one scan; assert it returns
 *      without error.
 *
 * Why this exists separately from saga-create-e2e-test.php:
 *   - saga-create-e2e is a sandbox test of the orchestrator + a few
 *     real step classes against a fake OLS tree.
 *   - This smoke test is the wire-level "did we plug everything
 *     together" check: HTTP -> action -> queue -> worker -> saga
 *     -> state machine -> events table -> reconciler entry point.
 *     It is what we run after a deploy to know V2 is live.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/site-create-smoke-test.php --verbose
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/site-create-smoke-test.php --smoke
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/site-create-smoke-test.php --json
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'skip-send', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2400));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Actions\ProvisioningAction;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Orchestrator\ProvisioningSagaRunner;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobWorker;
use VpsAdmin\Agent\Provisioner\Reconciler\DriftAssessor;
use VpsAdmin\Agent\Provisioner\Reconciler\ReconcilerService;
use VpsAdmin\Agent\Provisioner\Reconciler\SiteHealthProbe;
use VpsAdmin\Agent\Provisioner\Reconciler\SiteProberInterface;
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

$harness = new TestHarness('SiteCreateSmoke', $opts);

// ── shared state + cleanup ────────────────────────────────────
/** @var ?PanelDatabase */
$db = null;
/** @var ?\PDO */
$pdo = null;
/** @var ?ProvisioningAction */
$action = null;

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
        @$pdo->prepare("DELETE FROM site_job_events WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_step_executions WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_audit_log WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_jobs WHERE id IN ({$in})")->execute($testJobIds);
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

function smokeTestDomain(): string
{
    // .test TLD is RFC 6761 reserved; prefix makes leftover rows
    // obviously test data so cleanup is unambiguous even on crash.
    return 'flowone-test-smoke-' . bin2hex(random_bytes(3)) . '.test';
}

/**
 * In-process step that emulates one real CREATE step's contract
 * (check / execute / verify / compensate) without touching any
 * adapter. The worker / orchestrator path is the same whether the
 * step talks to OLS or just returns success.
 */
final class SmokeFakeStep implements StepInterface
{
    public function __construct(
        private readonly string $stepName,
        private readonly string $mode = 'success',
        private readonly CompensationPolicy $policy = CompensationPolicy::SAFE_ROLLBACK,
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
            default => throw new \LogicException("unknown mode {$this->mode}"),
        };
    }
    public function compensate(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
    public function verify(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
}

/**
 * A registry that returns a 4-step CREATE saga ending in a
 * DEGRADE_ONLY barrier (matching SslIssueStep's contract). This
 * way we exercise the same control flow real production sagas
 * take without touching the real steps.
 */
final class SmokeFakeRegistry extends SagaRegistry
{
    public function __construct(private readonly SagaSequence $createSeq)
    {
        parent::__construct();
    }
    public function createSequence(): SagaSequence
    {
        return $this->createSeq;
    }
}

// ──────────────────────────────────────────────────────────────
// preflight
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'PanelDatabase reachable + V2 tables present',
    function () use (&$db, &$pdo, &$action) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');

        foreach (['sites', 'site_jobs', 'site_audit_log',
                  'site_job_events', 'site_step_executions'] as $t) {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
            if ($stmt->rowCount() === 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing table {$t} - run migrations"];
            }
        }

        // Wire ProvisioningAction the same way agent.php does.
        $tmp = sys_get_temp_dir();
        $config = [
            'logging' => [
                'file' => $tmp . '/flowone_test_smoke.log',
                'level' => 'warning',
            ],
            'backup' => ['max_age_days' => 7, 'max_count' => 10],
            'paths' => [
                'base' => $tmp,
                'backups' => $tmp . '/flowone_test_smoke_backups',
            ],
        ];
        $action = new ProvisioningAction(
            $config,
            new BackupManager($config),
            new DiffGenerator(),
            new Logger($config),
        );
        if ($action->getNamespace() !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'ProvisioningAction wired with wrong namespace'];
        }
    });

$harness->test('preflight', 'systemd worker + reconciler units shipped under panel/agent/systemd/',
    function () {
        // The plan adds vpsadmin-reconciler.{service,timer}. We don't
        // require them to be INSTALLED here (the test runs against
        // a dev box) but the files MUST exist next to the agent so
        // copy-panel.sh can ship them. The agent dir layout matches
        // the deployed dir so this same check passes on the server.
        $agentDir = realpath(__DIR__ . '/../agent') ?: (__DIR__ . '/../agent');
        $systemdDir = $agentDir . '/systemd';
        $missing = [];
        foreach ([
            'vpsadmin-worker.service',
            'vpsadmin-lease-sweeper.service',
            'vpsadmin-lease-sweeper.timer',
            'vpsadmin-reconciler.service',
            'vpsadmin-reconciler.timer',
        ] as $unit) {
            if (!is_file($systemdDir . '/' . $unit)) {
                $missing[] = $unit;
            }
        }
        if ($missing) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'missing systemd units: ' . implode(', ', $missing)];
        }
    });

// ──────────────────────────────────────────────────────────────
// enqueue: API surface inserts a queued job + provisioning site row
// ──────────────────────────────────────────────────────────────

$harness->test('enqueue', 'enqueueCreate writes site_jobs(queued) + sites(provisioning) + audit',
    function () use (&$action, &$pdo, &$testJobIds, &$testDomains) {
        $domain = smokeTestDomain();
        $testDomains[] = $domain;

        $res = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['php_version' => 'lsphp83'],
            'actor_username' => 'flowone_test_smoke',
            'actor_user_id' => 999,
            'source_ip' => '127.0.0.1',
            'request_id' => 'smoke-' . bin2hex(random_bytes(3)),
        ], 'flowone_test_smoke');

        if (!($res['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'enqueueCreate failed: ' . ($res['error'] ?? '?')];
        }
        $job = $res['data']['job'] ?? null;
        $site = $res['data']['site'] ?? null;
        if (!$job || !$site) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'response missing job/site'];
        }
        $testJobIds[] = (int) $job['id'];

        if (($job['status'] ?? '') !== JobStatus::QUEUED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'job not queued: ' . ($job['status'] ?? 'null')];
        }
        if (($site['actual_state'] ?? '') !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'site not in provisioning: ' . ($site['actual_state'] ?? 'null')];
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE site_domain = :d AND action IN ('state_transition','job_enqueued')"
        );
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() < 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected state+enqueue audit rows'];
        }
    });

// ──────────────────────────────────────────────────────────────
// worker happy path: queued -> running -> succeeded -> active
// ──────────────────────────────────────────────────────────────

$harness->test('worker', 'JobWorker drives a queued CREATE to SUCCEEDED + sites=active',
    function () use (&$db, &$pdo, &$action, &$testJobIds, &$testDomains) {
        $domain = smokeTestDomain();
        $testDomains[] = $domain;

        // 1) Enqueue via the real action.
        $r = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['php_version' => 'lsphp83'],
            'actor_user_id' => 1,
            'request_id' => 'smoke-w-' . bin2hex(random_bytes(3)),
        ], 'flowone_test_smoke');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'enqueueCreate failed: ' . ($r['error'] ?? '?')];
        }
        $jobId = (int) $r['data']['job']['id'];
        $testJobIds[] = $jobId;

        // 2) Build a worker with a fake 4-step CREATE saga that
        //    mirrors the real shape (SAFE_ROLLBACK steps + a final
        //    DEGRADE_ONLY barrier that matches SslIssueStep).
        $masker = new SecretMasker();
        $audit = new AuditLogger($db, $masker);
        $machine = new SiteStateMachine($db, $audit);
        $runner = new ProvisioningSagaRunner($machine, $audit);
        $vault = new SecretVault($db, StepTestContext::ensureTestMasterKey());
        $caps = new ServerCapabilities();
        $registry = new SmokeFakeRegistry(new SagaSequence('smoke-create', [
            new SmokeFakeStep('sftp_group'),
            new SmokeFakeStep('vhost_write'),
            new SmokeFakeStep('ols_restart'),
            new SmokeFakeStep('ssl_issue', 'success', CompensationPolicy::DEGRADE_ONLY),
        ]));
        $worker = new JobWorker(
            database: $db, masker: $masker, vault: $vault,
            audit: $audit, capabilities: $caps,
            registry: $registry, runner: $runner,
            workerId: 'flowone_test_smoke_worker', adapters: null,
        );

        // 3) Tick. The fake steps return success in-process so the
        //    whole saga finishes within a single tick.
        $tick = $worker->tickOnce();
        if (!$tick->claimed) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'worker did not claim the queued job'];
        }
        if (!$tick->processed) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'worker claimed but did not process'];
        }

        // 4) Assert terminal state on the DB.
        $stmt = $pdo->prepare('SELECT status, finished_at FROM site_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $jrow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$jrow) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'job row vanished after tick'];
        }
        if ((string) $jrow['status'] !== JobStatus::SUCCEEDED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected succeeded, got ' . $jrow['status']];
        }
        if (empty($jrow['finished_at'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'finished_at not populated on terminal job'];
        }

        $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        $state = (string) $stmt->fetchColumn();
        if ($state !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected sites.actual_state=active, got ' . $state];
        }
    });

// ──────────────────────────────────────────────────────────────
// events tail: JobProgressModal polls site_job_events; this is
// the contract that keeps the live progress UI working.
// ──────────────────────────────────────────────────────────────

$harness->test('events', 'site_job_events received saga lifecycle rows for the smoke job',
    function () use (&$pdo, &$testJobIds) {
        if (!$testJobIds) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'no job ids tracked (prior test must have failed)'];
        }
        $lastJobId = end($testJobIds);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM site_job_events WHERE job_id = :id'
        );
        $stmt->execute(['id' => $lastJobId]);
        $n = (int) $stmt->fetchColumn();
        if ($n < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected >=1 events on job {$lastJobId}, got {$n}"];
        }
    });

// ──────────────────────────────────────────────────────────────
// reconciler smoke: matches what the systemd timer runs every 5min.
// We don't drive any real drift here - the goal is to confirm the
// service wires up against the live DB and a scan completes
// without throwing. The reconciler-test.php suite covers behaviour.
// ──────────────────────────────────────────────────────────────

$harness->test('reconciler', 'ReconcilerService scans without error against live DB',
    function () use (&$db) {
        $masker = new SecretMasker();
        $audit = new AuditLogger($db, $masker);
        $machine = new SiteStateMachine($db, $audit);

        // Stub prober + dispatcher so the smoke test never enqueues
        // a real RECONCILE job. We only need to know the service
        // initialises + runs scan() against the real DB without
        // throwing.
        $prober = new class implements SiteProberInterface {
            public function probe(array $siteRow): SiteHealthProbe
            {
                return new SiteHealthProbe(
                    domain: (string) ($siteRow['domain'] ?? ''),
                    vhostConfigPresent: true,
                    homeDirPresent: true,
                    documentRootPresent: true,
                    databasePresent: null,
                    databaseUserPresent: null,
                    sftpUserPresent: null,
                    sftpGroupPresent: null,
                    errors: [],
                    probedAtUnix: microtime(true),
                );
            }
        };
        $assessor = new DriftAssessor();
        $dispatcher = new class($db, $masker, $audit)
            extends \VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobDispatcher {
            public function enqueue(
                string $siteDomain,
                \VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobType $type,
                array $payload,
                ActorContext $actor,
                ?string $requestId = null,
                ?int $parentJobId = null,
                int $priority = 50,
                \VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobPriorityClass $priorityClass = \VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobPriorityClass::OPERATOR,
                int $maxAttempts = 3,
                bool $dryRun = false
            ): \VpsAdmin\Agent\Provisioner\Orchestrator\Queue\SiteJob {
                throw new \RuntimeException('smoke: enqueue blocked');
            }
        };

        $service = new ReconcilerService(
            database: $db,
            dispatcher: $dispatcher,
            stateMachine: $machine,
            audit: $audit,
            prober: $prober,
            assessor: $assessor,
            batchSize: 25,
        );

        try {
            $result = $service->scan(ActorContext::reconciler('smoke-' . bin2hex(random_bytes(3))));
        } catch (\Throwable $e) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'reconciler scan threw: ' . $e->getMessage()];
        }
        if ($result->durationMs() < 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'reconciler returned invalid duration'];
        }
    });

// ──────────────────────────────────────────────────────────────
exit($harness->run());
