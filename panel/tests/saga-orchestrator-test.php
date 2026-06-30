#!/usr/bin/env php
<?php
/**
 * Saga Orchestrator :: Unit Tests
 *
 * Drives the SagaOrchestrator with FAKE step classes that record every
 * call. This isolates the orchestrator's state machine from real
 * infrastructure so every walk path is exercised cheaply and
 * deterministically. No SFTP, no MySQL, no OLS, no filesystem writes
 * beyond the harness log file.
 *
 * Coverage:
 *   - happy path: every step succeeds; SagaResult is SUCCEEDED;
 *     compensate is never called.
 *   - check() satisfied: a step's check() returns true; execute() is
 *     not called; outcome is SKIPPED.
 *   - failure rolls back SAFE_ROLLBACK steps in reverse order.
 *   - DEGRADE_ONLY barrier halts the rollback chain.
 *   - failing step that IS DEGRADE_ONLY leaves prior steps alone.
 *   - check() and execute() and compensate() that THROW don't crash
 *     the orchestrator.
 *   - verify() failure flips a succeeded execute() into a failure.
 *   - deadline exceeded between steps aborts the saga.
 *   - SagaOrchestrator is single-use.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/saga-orchestrator-test.php --verbose
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
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemorySagaEventSink;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemoryStepStateStore;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOrchestrator;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOutcome;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaResult;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaStepRecord;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepInterface;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

// ───────────────────────────────────────────────────────────────────
// A configurable fake step: scriptable check/execute/compensate/verify.
// Records every call into a shared journal so tests can assert call
// order + counts WITHOUT relying on the orchestrator's event sink.
// ───────────────────────────────────────────────────────────────────

final class FakeStep implements StepInterface
{
    /**
     * @var array<string,mixed>
     *
     * Shape (all keys optional):
     *   check:         bool|\Closure(SiteContext, StepState): bool
     *   check_throw:   string  -- if set, check() throws this message
     *   execute:       'success'|'failure'|'timeout'|'partial'|'retry_later'
     *   execute_throw: string  -- if set, execute() throws this message
     *   compensate:    'success'|'failure'
     *   compensate_throw: string -- if set, compensate() throws
     *   verify:        'success'|'failure'  -- defaults to mirroring check
     *   error_msg:     string
     */
    public array $script;

    /** @var array<string,int> Per-method call counter for assertions. */
    public array $calls = [
        'check' => 0,
        'execute' => 0,
        'compensate' => 0,
        'verify' => 0,
    ];

    /** @var list<string> Chronological journal of method calls across all FakeSteps. */
    public static array $journal = [];

    public static function resetJournal(): void
    {
        self::$journal = [];
    }

    public function __construct(
        private readonly string $name,
        private readonly CompensationPolicy $policy = CompensationPolicy::SAFE_ROLLBACK,
        array $script = []
    ) {
        $this->script = $script;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return $this->policy;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $this->calls['check']++;
        self::$journal[] = $this->name . ':check';

        if (isset($this->script['check_throw'])) {
            throw new \RuntimeException((string) $this->script['check_throw']);
        }

        $c = $this->script['check'] ?? false;
        if ($c instanceof \Closure) {
            return (bool) $c($ctx, $state);
        }
        return (bool) $c;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $this->calls['execute']++;
        self::$journal[] = $this->name . ':execute';

        if (isset($this->script['execute_throw'])) {
            throw new \RuntimeException((string) $this->script['execute_throw']);
        }

        $mode = $this->script['execute'] ?? 'success';
        $error = (string) ($this->script['error_msg'] ?? "{$this->name} planned failure");
        $newState = $state->mergeData(['executed' => true]);

        return match ($mode) {
            'success' => StepResult::success($newState),
            'failure' => StepResult::failure($newState, $error),
            'timeout' => StepResult::timeout($newState, 'fake timeout'),
            'partial' => StepResult::partial($newState),
            'retry_later' => StepResult::retryLater($newState, 'fake retry', 1000),
            default => throw new \LogicException("unknown execute mode '{$mode}'"),
        };
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $this->calls['compensate']++;
        self::$journal[] = $this->name . ':compensate';

        if (isset($this->script['compensate_throw'])) {
            throw new \RuntimeException((string) $this->script['compensate_throw']);
        }

        $newState = $state->mergeData(['compensated' => true]);
        $mode = $this->script['compensate'] ?? 'success';
        return match ($mode) {
            'success' => StepResult::success($newState),
            'failure' => StepResult::failure($newState, 'compensate failed'),
            default => throw new \LogicException("unknown compensate mode '{$mode}'"),
        };
    }

    public function verify(SiteContext $ctx, StepState $state): StepResult
    {
        $this->calls['verify']++;
        self::$journal[] = $this->name . ':verify';

        $mode = $this->script['verify'] ?? 'success';
        return match ($mode) {
            'success' => StepResult::success($state),
            'failure' => StepResult::failure($state, 'verify failed'),
            default => throw new \LogicException("unknown verify mode '{$mode}'"),
        };
    }
}

// ───────────────────────────────────────────────────────────────────
// Test helpers
// ───────────────────────────────────────────────────────────────────

function buildContext(?float $deadlineUnixMicro = null): SiteContext
{
    $db = PanelDatabase::fromDefaultConfigFiles();
    $masker = new SecretMasker();
    $audit = new AuditLogger($db, $masker);
    $vault = new SecretVault($db, StepTestContext::ensureTestMasterKey());
    $caps = new ServerCapabilities();

    return new SiteContext(
        siteRow: ['id' => 999001, 'domain' => 'flowone_test_orchestrator.local', 'state' => null],
        jobId: 999001,
        requestId: 'req-orch-' . substr(bin2hex(random_bytes(4)), 0, 8),
        actor: ActorContext::cli('orchestrator-test'),
        audit: $audit,
        vault: $vault,
        capabilities: $caps,
        database: $db,
        payload: [],
        dryRun: false,
        deadlineUnixMicro: $deadlineUnixMicro,
        adapters: null,
    );
}

function buildOrchestrator(): array
{
    $store = new InMemoryStepStateStore();
    $sink = new InMemorySagaEventSink();
    return [
        'store' => $store,
        'sink' => $sink,
        'orchestrator' => new SagaOrchestrator($store, $sink),
    ];
}

function findRecord(SagaResult $r, string $stepName, string $direction): ?SagaStepRecord
{
    foreach ($r->stepRecords as $rec) {
        if ($rec->stepName === $stepName && $rec->direction === $direction) {
            return $rec;
        }
    }
    return null;
}

// ───────────────────────────────────────────────────────────────────
// Tests
// ───────────────────────────────────────────────────────────────────

$harness = new TestHarness('SagaOrchestrator', $opts);

// ── happy path ────────────────────────────────────────────────
$harness->test('happy', 'all-success saga reports SUCCEEDED with no compensation',
    function () {
        FakeStep::resetJournal();
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $b = new FakeStep('b', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $c = new FakeStep('c', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);

        $seq = new SagaSequence('happy', [$a, $b, $c]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->outcome->value];
        }
        // Every step ran exactly once forward; nobody compensated.
        foreach ([$a, $b, $c] as $s) {
            if ($s->calls['execute'] !== 1) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "{$s->name()} execute count = {$s->calls['execute']}"];
            }
            if ($s->calls['compensate'] !== 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "{$s->name()} compensate count = {$s->calls['compensate']}"];
            }
        }
        // Three forward records, zero backward.
        $fwd = array_filter($result->stepRecords,
            fn($r) => $r->direction === SagaStepRecord::DIRECTION_FORWARD);
        $bwd = array_filter($result->stepRecords,
            fn($r) => $r->direction === SagaStepRecord::DIRECTION_BACKWARD);
        if (count($fwd) !== 3 || count($bwd) !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 3 forward / 0 backward, got '
                    . count($fwd) . '/' . count($bwd)];
        }
    });

$harness->test('happy', 'check()=true skips execute and records SKIPPED',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, [
            'check' => true,
        ]);
        $b = new FakeStep('b', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);

        $seq = new SagaSequence('skip', [$a, $b]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->outcome->value];
        }
        if ($a->calls['execute'] !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'a.execute should NOT have been called'];
        }
        $rec = findRecord($result, 'a', SagaStepRecord::DIRECTION_FORWARD);
        if ($rec === null || !$rec->wasCheckSatisfied) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'a should be recorded as wasCheckSatisfied=true'];
        }
        if ($rec->outcome->value !== 'skipped') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SKIPPED outcome'];
        }
    });

// ── rollback ──────────────────────────────────────────────────
$harness->test('rollback', 'failure rolls back SAFE_ROLLBACK steps in reverse',
    function () {
        FakeStep::resetJournal();
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $b = new FakeStep('b', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $c = new FakeStep('c', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'failure']);

        $seq = new SagaSequence('rollback', [$a, $b, $c]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED, got ' . $result->outcome->value];
        }
        // Compensation order: c, b, a (failing step first).
        $compensateOrder = array_values(array_filter(
            FakeStep::$journal,
            fn($e) => str_ends_with($e, ':compensate'),
        ));
        $expected = ['c:compensate', 'b:compensate', 'a:compensate'];
        if ($compensateOrder !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'compensate order: ' . implode(',', $compensateOrder)];
        }
        if ($result->failureStepName !== 'c') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "failureStepName = '{$result->failureStepName}'"];
        }
    });

$harness->test('rollback', 'verify() failure triggers compensation',
    function () {
        FakeStep::resetJournal();
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $b = new FakeStep('b', CompensationPolicy::SAFE_ROLLBACK, [
            'execute' => 'success', 'verify' => 'failure',
        ]);

        $seq = new SagaSequence('verify-fail', [$a, $b]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED (verify), got ' . $result->outcome->value];
        }
        if ($a->calls['compensate'] !== 1 || $b->calls['compensate'] !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "a.compensate={$a->calls['compensate']} b.compensate={$b->calls['compensate']}"];
        }
    });

// ── degrade ───────────────────────────────────────────────────
$harness->test('degrade', 'DEGRADE_ONLY barrier halts rollback partway',
    function () {
        FakeStep::resetJournal();
        // a, b: SAFE_ROLLBACK; c: DEGRADE_ONLY; d: SAFE_ROLLBACK fails.
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $b = new FakeStep('b', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $c = new FakeStep('c', CompensationPolicy::DEGRADE_ONLY, ['execute' => 'success']);
        $d = new FakeStep('d', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'failure']);

        $seq = new SagaSequence('barrier', [$a, $b, $c, $d]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::DEGRADED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected DEGRADED, got ' . $result->outcome->value];
        }
        if ($d->calls['compensate'] !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'd should be compensated (it failed; SAFE_ROLLBACK)'];
        }
        if ($c->calls['compensate'] !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'c is the barrier; must NOT be compensated'];
        }
        // a and b are BEHIND the barrier; they're preserved.
        if ($a->calls['compensate'] !== 0 || $b->calls['compensate'] !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "a/b should be preserved (a={$a->calls['compensate']}, b={$b->calls['compensate']})"];
        }
    });

$harness->test('degrade', 'failing step that IS DEGRADE_ONLY preserves all prior steps',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $b = new FakeStep('b', CompensationPolicy::DEGRADE_ONLY, ['execute' => 'failure']);

        $seq = new SagaSequence('fail-is-degrade', [$a, $b]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::DEGRADED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected DEGRADED, got ' . $result->outcome->value];
        }
        // Failing step is DEGRADE_ONLY -> NOT compensated; prior step
        // also preserved.
        if ($a->calls['compensate'] !== 0 || $b->calls['compensate'] !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "no compensation expected (a={$a->calls['compensate']}, b={$b->calls['compensate']})"];
        }
    });

// ── resilience ────────────────────────────────────────────────
$harness->test('resilience', 'check() throw is treated as "not satisfied", saga proceeds',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, [
            'check_throw' => 'boom in check',
            'execute' => 'success',
        ]);

        $seq = new SagaSequence('check-throw', [$a]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->outcome->value];
        }
        if ($a->calls['execute'] !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute should still have been called after check threw'];
        }
    });

$harness->test('resilience', 'execute() throw is captured as failure (no crash)',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, [
            'execute_throw' => 'segfault simulator',
        ]);

        $seq = new SagaSequence('execute-throw', [$a]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED, got ' . $result->outcome->value];
        }
        if ($result->failureError === null
            || !str_contains((string) $result->failureError, 'segfault simulator')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'failure error should contain the thrown message: '
                    . ($result->failureError ?? 'NULL')];
        }
    });

$harness->test('resilience', 'compensate() throw still produces a clean record',
    function () {
        FakeStep::resetJournal();
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, [
            'execute' => 'success',
            'compensate_throw' => 'rmdir refused',
        ]);
        $b = new FakeStep('b', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'failure']);

        $seq = new SagaSequence('comp-throw', [$a, $b]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED, got ' . $result->outcome->value];
        }
        // a's compensate() throws; we want a backward record reflecting
        // failure but the orchestrator must NOT have crashed.
        $rec = findRecord($result, 'a', SagaStepRecord::DIRECTION_BACKWARD);
        if ($rec === null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected backward record for a'];
        }
        if (!str_contains((string) $rec->error, 'rmdir refused')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected compensate error to surface, got: '" . ($rec->error ?? 'NULL') . "'"];
        }
    });

// ── deadline ──────────────────────────────────────────────────
$harness->test('deadline', 'deadline exceeded between steps aborts the saga',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $b = new FakeStep('b', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);

        $seq = new SagaSequence('deadline', [$a, $b]);
        $bundle = buildOrchestrator();
        $past = microtime(true) - 1.0;
        $result = $bundle['orchestrator']->run($seq, buildContext($past));

        if ($result->outcome !== SagaOutcome::ABORTED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected ABORTED, got ' . $result->outcome->value];
        }
        // a was not even started.
        if ($a->calls['execute'] !== 0 || $a->calls['check'] !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "no step should have run, but a.execute={$a->calls['execute']}, a.check={$a->calls['check']}"];
        }
    });

// ── single-use ────────────────────────────────────────────────
$harness->test('single_use', 'reusing the orchestrator throws',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $seq = new SagaSequence('reuse', [$a]);
        $bundle = buildOrchestrator();
        $bundle['orchestrator']->run($seq, buildContext());

        try {
            $bundle['orchestrator']->run($seq, buildContext());
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected LogicException on second run'];
        } catch (\LogicException) {
            // ok
        }
    });

// ── PARTIAL / RETRY_LATER stop the forward walk in 5a ─────────
$harness->test('unsupported_outcomes', 'PARTIAL is treated as terminal failure in 5a',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'partial']);

        $seq = new SagaSequence('partial', [$a]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED, got ' . $result->outcome->value];
        }
    });

$harness->test('unsupported_outcomes', 'RETRY_LATER is treated as terminal failure in 5a',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'retry_later']);

        $seq = new SagaSequence('retry', [$a]);
        $bundle = buildOrchestrator();
        $result = $bundle['orchestrator']->run($seq, buildContext());

        if ($result->outcome !== SagaOutcome::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED, got ' . $result->outcome->value];
        }
    });

// ── state store + event sink semantics ────────────────────────
$harness->test('semantics', 'state is persisted to the store after each step',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);
        $b = new FakeStep('b', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);

        $seq = new SagaSequence('persist', [$a, $b]);
        $bundle = buildOrchestrator();
        $bundle['orchestrator']->run($seq, buildContext());

        $storeAll = $bundle['store']->all();
        if (!isset($storeAll['a']) || !isset($storeAll['b'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected both step states in store'];
        }
        if (!$storeAll['a']->isComplete() || !$storeAll['b']->isComplete()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected completedAt to be set on both'];
        }
        if (($storeAll['a']->data['executed'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected a.data.executed=true (returned by execute)'];
        }
    });

$harness->test('semantics', 'event sink captures saga-level + per-step events in order',
    function () {
        $a = new FakeStep('a', CompensationPolicy::SAFE_ROLLBACK, ['execute' => 'success']);

        $seq = new SagaSequence('events', [$a]);
        $bundle = buildOrchestrator();
        $bundle['orchestrator']->run($seq, buildContext());

        $rows = $bundle['sink']->drain();
        if (count($rows) === 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'no events captured'];
        }
        $firstStep = $rows[0]['step_name'];
        $lastStep = $rows[count($rows) - 1]['step_name'];
        // The first event must be a saga-level "starting" banner.
        if ($firstStep !== InMemorySagaEventSink::SAGA_STEP_NAME) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "first event step_name = '{$firstStep}'"];
        }
        if ($lastStep !== InMemorySagaEventSink::SAGA_STEP_NAME) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "last event step_name = '{$lastStep}'"];
        }
    });

exit($harness->run());
