#!/usr/bin/env php
<?php
/**
 * Saga State Bridge :: ProvisioningSagaRunner Tests
 *
 * Verifies the bridge that wraps the SagaOrchestrator with
 * SiteStateMachine transitions.
 *
 * Coverage:
 *   - Outcome -> terminal state mapping is correct for every
 *     (direction, outcome) pair (pure unit, no DB needed).
 *   - Each SagaDirection's inFlightState() and legalSourceStates() are
 *     internally consistent with the SiteStateMachine TRANSITIONS map.
 *   - CREATE happy path: 'absent' -> 'provisioning' -> 'active'.
 *   - CREATE rollback path: ends in 'failed' after compensation.
 *   - CREATE degraded path: ends in 'degraded' with site preserved.
 *   - CREATE resume path: site already in 'provisioning' is NOT
 *     double-entered; the runner only does the terminal transition.
 *   - DELETE happy path: 'active' -> 'deleting' -> 'absent'.
 *   - DELETE failure: maps to 'degraded' (state machine forbids
 *     'deleting -> failed').
 *   - ABORTED: bridge leaves the site in-flight + writes an audit row.
 *   - Illegal source state for the direction throws
 *     InvalidStateTransition.
 *
 * All DB tests use the `flowone_test_` domain prefix and clean up
 * sites + site_audit_log rows in onCleanup() so they're idempotent.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/saga-state-bridge-test.php --verbose
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
use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemorySagaEventSink;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemoryStepStateStore;
use VpsAdmin\Agent\Provisioner\Orchestrator\ProvisioningSagaRunner;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaDirection;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOutcome;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepInterface;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SagaStateBridge', $opts);

// ── shared state + cleanup ────────────────────────────────────
$db = null;
$pdo = null;
$audit = null;
$machine = null;
$runner = null;
$vault = null;
$caps = null;
$actor = ActorContext::cli('saga-bridge-test', 'flowone_test_user');
$testDomains = [];

$harness->onCleanup(function () use (&$pdo, &$testDomains): void {
    if (!$pdo || !$testDomains) {
        return;
    }
    $in = implode(',', array_fill(0, count($testDomains), '?'));
    @$pdo->prepare("DELETE FROM site_audit_log WHERE site_domain IN ({$in})")->execute($testDomains);
    @$pdo->prepare("DELETE FROM sites WHERE domain IN ({$in})")->execute($testDomains);
});

// ──────────────────────────────────────────────────────────────
// Fake step for FSM tests. Same shape as in saga-orchestrator-test;
// kept local here so this file is self-contained.
// ──────────────────────────────────────────────────────────────
final class BridgeFakeStep implements StepInterface
{
    public function __construct(
        private readonly string $stepName,
        private readonly CompensationPolicy $policy = CompensationPolicy::SAFE_ROLLBACK,
        private readonly string $executeMode = 'success'
    ) {
    }

    public function name(): string
    {
        return $this->stepName;
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
        return false;
    }
    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        return match ($this->executeMode) {
            'success' => StepResult::success($state),
            'failure' => StepResult::failure($state, "{$this->stepName} planned failure"),
            'timeout' => StepResult::timeout($state, 'planned timeout'),
            default => throw new \LogicException("unknown mode {$this->executeMode}"),
        };
    }
    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success($state);
    }
    public function verify(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success($state);
    }
}

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

/**
 * Insert a sites row in the given actual_state and return the row id.
 * The cleanup callback removes the row at the end of the suite.
 */
function seedSiteRow(\PDO $pdo, string $domain, string $actualState, array &$testDomains): int
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
    return (int) $pdo->lastInsertId();
}

function fetchActualState(\PDO $pdo, int $siteId): string
{
    $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE id = :id');
    $stmt->execute(['id' => $siteId]);
    return (string) $stmt->fetchColumn();
}

function buildSiteContext(
    int $siteId,
    string $domain,
    string $actualState,
    PanelDatabase $db,
    AuditLogger $audit,
    SecretVault $vault,
    ServerCapabilities $caps,
    ActorContext $actor,
    ?float $deadlineUnixMicro = null
): SiteContext {
    return new SiteContext(
        siteRow: [
            'id' => $siteId,
            'domain' => $domain,
            'actual_state' => $actualState,
            'desired_state' => 'active',
            'state' => null,
        ],
        jobId: $siteId,
        requestId: 'req-bridge-' . substr(bin2hex(random_bytes(4)), 0, 8),
        actor: $actor,
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

// ──────────────────────────────────────────────────────────────
// preflight: shared infra setup
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'panel DB + state machine boot',
    function () use (&$db, &$pdo, &$audit, &$machine, &$runner, &$vault, &$caps) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        $masker = new SecretMasker();
        $audit = new AuditLogger($db, $masker);
        $machine = new SiteStateMachine($db, $audit);
        $runner = new ProvisioningSagaRunner($machine, $audit);
        $vault = new SecretVault($db, StepTestContext::ensureTestMasterKey());
        $caps = new ServerCapabilities();
    });

// ──────────────────────────────────────────────────────────────
// Pure-unit outcome mapping
// ──────────────────────────────────────────────────────────────

$harness->test('mapping', 'CREATE outcome -> terminal state mapping is correct',
    function () use (&$runner) {
        $cases = [
            [SagaOutcome::SUCCEEDED, 'active'],
            [SagaOutcome::FAILED, 'failed'],
            [SagaOutcome::DEGRADED, 'degraded'],
            [SagaOutcome::ABORTED, null],
        ];
        foreach ($cases as [$o, $expected]) {
            $got = $runner->terminalStateFor(SagaDirection::CREATE, $o);
            if ($got !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "CREATE/{$o->value}: expected " . var_export($expected, true)
                        . ', got ' . var_export($got, true)];
            }
        }
    });

$harness->test('mapping', 'DELETE outcome -> terminal state mapping is correct',
    function () use (&$runner) {
        $cases = [
            [SagaOutcome::SUCCEEDED, 'absent'],
            [SagaOutcome::FAILED, 'degraded'],
            [SagaOutcome::DEGRADED, 'degraded'],
            [SagaOutcome::ABORTED, null],
        ];
        foreach ($cases as [$o, $expected]) {
            $got = $runner->terminalStateFor(SagaDirection::DELETE, $o);
            if ($got !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "DELETE/{$o->value}: expected " . var_export($expected, true)
                        . ', got ' . var_export($got, true)];
            }
        }
    });

$harness->test('mapping', 'RESTORE outcome -> terminal state mapping is correct',
    function () use (&$runner) {
        $cases = [
            [SagaOutcome::SUCCEEDED, 'active'],
            [SagaOutcome::FAILED, 'failed'],
            [SagaOutcome::DEGRADED, 'failed'],
            [SagaOutcome::ABORTED, null],
        ];
        foreach ($cases as [$o, $expected]) {
            $got = $runner->terminalStateFor(SagaDirection::RESTORE, $o);
            if ($got !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "RESTORE/{$o->value}: expected " . var_export($expected, true)
                        . ', got ' . var_export($got, true)];
            }
        }
    });

$harness->test('mapping', 'every direction terminal state is a legal SiteStateMachine transition',
    function () use (&$machine, &$runner) {
        foreach (SagaDirection::cases() as $direction) {
            $inflight = $direction->inFlightState();
            foreach (SagaOutcome::cases() as $outcome) {
                $term = $runner->terminalStateFor($direction, $outcome);
                if ($term === null || $term === $inflight) {
                    continue; // no transition; nothing to check
                }
                if (!$machine->canTransition($inflight, $term)) {
                    return ['outcome' => TestHarness::FAIL,
                        'message' => "{$direction->value}/{$outcome->value}: "
                            . "transition {$inflight} -> {$term} is illegal in SiteStateMachine"];
                }
            }
        }
    });

$harness->test('mapping', 'every legal source state -> in-flight transition is allowed by the state machine',
    function () use (&$machine) {
        foreach (SagaDirection::cases() as $direction) {
            $inflight = $direction->inFlightState();
            foreach ($direction->legalSourceStates() as $src) {
                if ($src === $inflight) {
                    continue; // already-in-flight is handled outside the transition map
                }
                if (!$machine->canTransition($src, $inflight)) {
                    return ['outcome' => TestHarness::FAIL,
                        'message' => "{$direction->value}: declared legal source '{$src}' "
                            . "cannot transition to in-flight '{$inflight}' in SiteStateMachine"];
                }
            }
        }
    });

// ──────────────────────────────────────────────────────────────
// CREATE: happy path
// ──────────────────────────────────────────────────────────────

$harness->test('create', 'happy CREATE: absent -> provisioning -> active',
    function () use (&$pdo, &$db, &$audit, &$runner, &$vault, &$caps, &$actor, &$testDomains) {
        $domain = '[flowone_test_]bridge-' . bin2hex(random_bytes(3)) . '.local';
        $siteId = seedSiteRow($pdo, $domain, 'absent', $testDomains);

        $ctx = buildSiteContext($siteId, $domain, 'absent', $db, $audit, $vault, $caps, $actor);
        $seq = new SagaSequence('create-test', [
            new BridgeFakeStep('alpha', CompensationPolicy::SAFE_ROLLBACK, 'success'),
        ]);

        $result = $runner->run(
            SagaDirection::CREATE,
            $seq,
            $ctx,
            new InMemoryStepStateStore(),
            new InMemorySagaEventSink(),
        );

        if (!$result->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->saga->outcome->value];
        }
        if ($result->previousState !== 'absent'
            || $result->inFlightState !== 'provisioning'
            || $result->finalState !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "transitions: prev={$result->previousState} "
                    . "inflight={$result->inFlightState} final={$result->finalState}"];
        }
        if (!$result->enteredInFlight || !$result->exitedInFlight) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected both entry+exit transitions, got entered="
                    . (int) $result->enteredInFlight . " exited=" . (int) $result->exitedInFlight];
        }
        if (fetchActualState($pdo, $siteId) !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'DB actual_state not active after success'];
        }
    });

$harness->test('create', 'CREATE rollback: provisioning -> failed after compensated saga',
    function () use (&$pdo, &$db, &$audit, &$runner, &$vault, &$caps, &$actor, &$testDomains) {
        $domain = '[flowone_test_]bridge-' . bin2hex(random_bytes(3)) . '.local';
        $siteId = seedSiteRow($pdo, $domain, 'absent', $testDomains);

        $ctx = buildSiteContext($siteId, $domain, 'absent', $db, $audit, $vault, $caps, $actor);
        $seq = new SagaSequence('create-rollback', [
            new BridgeFakeStep('alpha', CompensationPolicy::SAFE_ROLLBACK, 'success'),
            new BridgeFakeStep('beta',  CompensationPolicy::SAFE_ROLLBACK, 'failure'),
        ]);

        $result = $runner->run(
            SagaDirection::CREATE,
            $seq,
            $ctx,
            new InMemoryStepStateStore(),
            new InMemorySagaEventSink(),
        );

        if (!$result->isFailure()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED, got ' . $result->saga->outcome->value];
        }
        if ($result->finalState !== 'failed') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected final=failed, got {$result->finalState}"];
        }
        if (fetchActualState($pdo, $siteId) !== 'failed') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'DB actual_state not failed after rollback'];
        }
    });

$harness->test('create', 'CREATE degraded: provisioning -> degraded preserves the site',
    function () use (&$pdo, &$db, &$audit, &$runner, &$vault, &$caps, &$actor, &$testDomains) {
        $domain = '[flowone_test_]bridge-' . bin2hex(random_bytes(3)) . '.local';
        $siteId = seedSiteRow($pdo, $domain, 'absent', $testDomains);

        $ctx = buildSiteContext($siteId, $domain, 'absent', $db, $audit, $vault, $caps, $actor);
        $seq = new SagaSequence('create-degrade', [
            new BridgeFakeStep('alpha', CompensationPolicy::SAFE_ROLLBACK, 'success'),
            // DEGRADE_ONLY step that fails -> saga ends DEGRADED.
            new BridgeFakeStep('beta',  CompensationPolicy::DEGRADE_ONLY, 'failure'),
        ]);

        $result = $runner->run(
            SagaDirection::CREATE,
            $seq,
            $ctx,
            new InMemoryStepStateStore(),
            new InMemorySagaEventSink(),
        );

        if (!$result->isDegraded()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected DEGRADED, got ' . $result->saga->outcome->value];
        }
        if ($result->finalState !== 'degraded') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected final=degraded, got {$result->finalState}"];
        }
        if (fetchActualState($pdo, $siteId) !== 'degraded') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'DB actual_state not degraded after degrade saga'];
        }
        if (!$result->requiresOperatorAttention()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'degraded outcome should requireOperatorAttention()'];
        }
    });

$harness->test('create', 'CREATE resume: site already in provisioning is not double-entered',
    function () use (&$pdo, &$db, &$audit, &$runner, &$vault, &$caps, &$actor, &$testDomains) {
        $domain = '[flowone_test_]bridge-' . bin2hex(random_bytes(3)) . '.local';
        $siteId = seedSiteRow($pdo, $domain, 'provisioning', $testDomains);

        $ctx = buildSiteContext($siteId, $domain, 'provisioning', $db, $audit, $vault, $caps, $actor);
        $seq = new SagaSequence('create-resume', [
            new BridgeFakeStep('alpha', CompensationPolicy::SAFE_ROLLBACK, 'success'),
        ]);

        $result = $runner->run(
            SagaDirection::CREATE,
            $seq,
            $ctx,
            new InMemoryStepStateStore(),
            new InMemorySagaEventSink(),
        );

        if ($result->enteredInFlight) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'should not re-enter in-flight; site was already provisioning'];
        }
        if (!$result->exitedInFlight) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'should still exit to terminal state'];
        }
        if ($result->finalState !== 'active' || fetchActualState($pdo, $siteId) !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected final=active, got runner={$result->finalState}, DB="
                    . fetchActualState($pdo, $siteId)];
        }
    });

// ──────────────────────────────────────────────────────────────
// DELETE
// ──────────────────────────────────────────────────────────────

$harness->test('delete', 'happy DELETE: active -> deleting -> absent',
    function () use (&$pdo, &$db, &$audit, &$runner, &$vault, &$caps, &$actor, &$testDomains) {
        $domain = '[flowone_test_]bridge-' . bin2hex(random_bytes(3)) . '.local';
        $siteId = seedSiteRow($pdo, $domain, 'active', $testDomains);

        $ctx = buildSiteContext($siteId, $domain, 'active', $db, $audit, $vault, $caps, $actor);
        $seq = new SagaSequence('delete-test', [
            new BridgeFakeStep('alpha', CompensationPolicy::SAFE_ROLLBACK, 'success'),
        ]);

        $result = $runner->run(
            SagaDirection::DELETE,
            $seq,
            $ctx,
            new InMemoryStepStateStore(),
            new InMemorySagaEventSink(),
        );

        if (!$result->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->saga->outcome->value];
        }
        if ($result->inFlightState !== 'deleting' || $result->finalState !== 'absent') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "transitions: inflight={$result->inFlightState} final={$result->finalState}"];
        }
        if (fetchActualState($pdo, $siteId) !== 'absent') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'DB actual_state not absent after delete'];
        }
    });

$harness->test('delete', 'DELETE failure: deleting -> degraded (state machine forbids deleting->failed)',
    function () use (&$pdo, &$db, &$audit, &$runner, &$vault, &$caps, &$actor, &$testDomains) {
        $domain = '[flowone_test_]bridge-' . bin2hex(random_bytes(3)) . '.local';
        $siteId = seedSiteRow($pdo, $domain, 'active', $testDomains);

        $ctx = buildSiteContext($siteId, $domain, 'active', $db, $audit, $vault, $caps, $actor);
        $seq = new SagaSequence('delete-fail', [
            new BridgeFakeStep('alpha', CompensationPolicy::SAFE_ROLLBACK, 'failure'),
        ]);

        $result = $runner->run(
            SagaDirection::DELETE,
            $seq,
            $ctx,
            new InMemoryStepStateStore(),
            new InMemorySagaEventSink(),
        );

        if (!$result->isFailure()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED, got ' . $result->saga->outcome->value];
        }
        if ($result->finalState !== 'degraded') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "DELETE/FAILED must map to degraded; got {$result->finalState}"];
        }
        if (fetchActualState($pdo, $siteId) !== 'degraded') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'DB actual_state not degraded after failed delete'];
        }
    });

// ──────────────────────────────────────────────────────────────
// ABORTED: deadline already elapsed -> no exit transition
// ──────────────────────────────────────────────────────────────

$harness->test('aborted', 'ABORTED leaves the site in-flight and audits the abort',
    function () use (&$pdo, &$db, &$audit, &$runner, &$vault, &$caps, &$actor, &$testDomains) {
        $domain = '[flowone_test_]bridge-' . bin2hex(random_bytes(3)) . '.local';
        $siteId = seedSiteRow($pdo, $domain, 'absent', $testDomains);

        // Already-elapsed deadline -> orchestrator returns ABORTED
        // before even starting the first step.
        $past = microtime(true) - 1.0;
        $ctx = buildSiteContext($siteId, $domain, 'absent', $db, $audit, $vault, $caps, $actor, $past);
        $seq = new SagaSequence('create-aborted', [
            new BridgeFakeStep('alpha', CompensationPolicy::SAFE_ROLLBACK, 'success'),
        ]);

        $result = $runner->run(
            SagaDirection::CREATE,
            $seq,
            $ctx,
            new InMemoryStepStateStore(),
            new InMemorySagaEventSink(),
        );

        if ($result->saga->outcome !== SagaOutcome::ABORTED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected ABORTED, got ' . $result->saga->outcome->value];
        }
        if ($result->finalState !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "ABORTED should leave site in-flight (provisioning); got {$result->finalState}"];
        }
        if ($result->exitedInFlight) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'ABORTED must not record an exit transition'];
        }
        if (fetchActualState($pdo, $siteId) !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'DB actual_state should still be provisioning after ABORTED'];
        }
        // Check we wrote an explicit audit row for the abort.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE site_domain = :d AND action = 'saga_aborted'"
        );
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'no saga_aborted audit row recorded'];
        }
    });

// ──────────────────────────────────────────────────────────────
// Illegal source state
// ──────────────────────────────────────────────────────────────

$harness->test('illegal_source', 'CREATE from active throws InvalidStateTransition',
    function () use (&$pdo, &$db, &$audit, &$runner, &$vault, &$caps, &$actor, &$testDomains) {
        $domain = '[flowone_test_]bridge-' . bin2hex(random_bytes(3)) . '.local';
        // 'active' is NOT in SagaDirection::CREATE legalSourceStates.
        $siteId = seedSiteRow($pdo, $domain, 'active', $testDomains);

        $ctx = buildSiteContext($siteId, $domain, 'active', $db, $audit, $vault, $caps, $actor);
        $seq = new SagaSequence('illegal-create', [
            new BridgeFakeStep('alpha', CompensationPolicy::SAFE_ROLLBACK, 'success'),
        ]);

        try {
            $runner->run(
                SagaDirection::CREATE,
                $seq,
                $ctx,
                new InMemoryStepStateStore(),
                new InMemorySagaEventSink(),
            );
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected InvalidStateTransition for CREATE from active'];
        } catch (InvalidStateTransition) {
            // ok
        }
        // Site stayed 'active' since we threw before the entry transition.
        if (fetchActualState($pdo, $siteId) !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'site state should be unchanged after illegal-source rejection'];
        }
    });

exit($harness->run());
