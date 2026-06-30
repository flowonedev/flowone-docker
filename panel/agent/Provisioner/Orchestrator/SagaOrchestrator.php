<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepInterface;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * In-process saga orchestrator.
 *
 * This is the single piece that turns the StepInterface contract and a
 * SagaSequence into "create a site" / "delete a site" / "restore a site".
 * Without it, the step library is inert.
 *
 * The orchestrator's responsibilities:
 *
 *   1. Hydrate per-step state from the store (so a re-run can skip
 *      already-completed work via check()).
 *
 *   2. Walk the saga FORWARD:
 *        - call check() -> if true, skip execute, record SKIPPED;
 *        - else call execute(), save state, optionally verify();
 *        - on FAILURE, switch to the backward walk.
 *
 *   3. Walk BACKWARD on failure:
 *        - call compensate() on each compensable step,
 *          stopping at the first DEGRADE_ONLY barrier;
 *        - on barrier hit, the saga ends in DEGRADED.
 *
 *   4. Emit a narrative event stream (saga-level + per-step) into the
 *      injected SagaEventSink so a UI can stream progress live.
 *
 *   5. Honour the SiteContext deadline. Once the deadline is exceeded
 *      the saga returns ABORTED instead of starting another step.
 *
 *   6. Tolerate misbehaving steps. Throws from check(), execute(),
 *      compensate(), or verify() are caught and converted into failure
 *      records - one rogue step must never poison the worker.
 *
 * Non-goals for Step 5a (deferred to later layers):
 *
 *   - Subprocess isolation. Now OPT-IN via {@see StepProcessIsolator}
 *     passed into the constructor. When the isolator is enabled, each
 *     step's execute() / compensate() runs in a forked child so a PHP
 *     fatal in the step crashes the child rather than the worker. When
 *     null (default) the orchestrator runs in-process as before, which
 *     keeps the existing test scaffolding behaviour intact.
 *   - Persistent job queue (Step 5c). The orchestrator returns a
 *     SagaResult synchronously; there's no enqueue/resume.
 *   - RETRY_LATER / PARTIAL outcomes (Step 5c). These are treated as
 *     terminal failures in 5a because no scheduler exists to re-pick
 *     them up.
 *   - Site state machine transitions (Step 5b). The caller wires the
 *     SagaResult to sites.state transitions; the orchestrator doesn't
 *     write to the sites table.
 *
 * Reentrancy: a SagaOrchestrator instance is single-use. Each saga
 * gets a fresh orchestrator with a fresh state store and event sink.
 * Reusing one instance for two runs is undefined behaviour.
 */
final class SagaOrchestrator
{
    /** Maximum events the orchestrator will collect per saga before warning. */
    private const EVENT_BUFFER_WARN_AT = 5000;

    private bool $hasRun = false;

    public function __construct(
        private readonly StepStateStore $stateStore,
        private readonly SagaEventSink $eventSink,
        /**
         * Optional subprocess isolator. Default null = run steps
         * in-process (historical behaviour). When provided, each step's
         * execute() and compensate() call is wrapped in a forked child
         * so a PHP fatal in the step doesn't kill the worker.
         */
        private readonly ?StepProcessIsolator $isolator = null
    ) {
    }

    /**
     * Run a saga to completion (synchronous, in-process).
     *
     * Returns a SagaResult that the caller wires to:
     *   - the site state machine (Step 5b),
     *   - the job table (Step 5c),
     *   - the UI / audit log.
     */
    public function run(SagaSequence $sequence, SiteContext $ctx): SagaResult
    {
        if ($this->hasRun) {
            throw new \LogicException(
                'SagaOrchestrator is single-use; create a new instance per saga run'
            );
        }
        $this->hasRun = true;

        $startedAtMicro = microtime(true);

        $this->emitSagaEvent(StepEvent::info(
            "saga '{$sequence->name}' starting",
            [
                'saga' => $sequence->name,
                'step_count' => $sequence->count(),
                'domain' => $ctx->domain(),
                'job_id' => $ctx->jobId,
                'request_id' => $ctx->requestId,
                'dry_run' => $ctx->dryRun,
            ]
        ));

        /** @var list<SagaStepRecord> $records */
        $records = [];
        $failureIndex = null;
        $failureError = null;

        // ---------------- Forward walk ----------------
        for ($i = 0; $i < $sequence->count(); $i++) {
            $step = $sequence->get($i);

            if ($ctx->isDeadlineExceeded()) {
                $this->emitSagaEvent(StepEvent::error(
                    "saga aborted: deadline exceeded before '{$step->name()}'",
                    ['next_step' => $step->name(), 'index' => $i]
                ));
                return $this->finalize(
                    $sequence->name,
                    SagaOutcome::ABORTED,
                    $records,
                    $startedAtMicro,
                    null,
                    'deadline exceeded'
                );
            }

            $record = $this->runStepForward($step, $ctx);
            $records[] = $record;

            if (!$this->isForwardSuccess($record)) {
                $failureIndex = $i;
                $failureError = $record->error
                    ?? "step '{$step->name()}' failed with outcome '{$record->outcome->value}'";
                break;
            }
        }

        // ---------------- Happy path ----------------
        if ($failureIndex === null) {
            $this->emitSagaEvent(StepEvent::info(
                "saga '{$sequence->name}' succeeded",
                ['elapsed_ms' => $this->elapsedMs($startedAtMicro)]
            ));
            return $this->finalize(
                $sequence->name,
                SagaOutcome::SUCCEEDED,
                $records,
                $startedAtMicro,
                null,
                null
            );
        }

        // ---------------- Compensation walk ----------------
        $failedStep = $sequence->get($failureIndex);
        $this->emitSagaEvent(StepEvent::warning(
            "saga '{$sequence->name}' failed at '{$failedStep->name()}', starting compensation",
            [
                'failure_step' => $failedStep->name(),
                'failure_index' => $failureIndex,
                'failure_error' => $failureError,
            ]
        ));

        // Rule 1: if the FAILING step is DEGRADE_ONLY, we don't roll back
        // anything - the site is preserved as degraded for an operator
        // to triage.
        if ($failedStep->compensationPolicy() === CompensationPolicy::DEGRADE_ONLY) {
            $this->emitSagaEvent(StepEvent::warning(
                "saga DEGRADED: failing step '{$failedStep->name()}' has policy DEGRADE_ONLY; preserving partial site",
                ['failure_step' => $failedStep->name()]
            ));
            return $this->finalize(
                $sequence->name,
                SagaOutcome::DEGRADED,
                $records,
                $startedAtMicro,
                $failedStep->name(),
                $failureError
            );
        }

        $degraded = false;

        for ($j = $failureIndex; $j >= 0; $j--) {
            $step = $sequence->get($j);

            // Skip purely-pristine steps that were never executed in this
            // run AND have no persisted completedAt. The failing step is
            // always considered - it may have partial side effects we
            // need to clean up.
            if ($j !== $failureIndex && !$this->isStepCompleted($step)) {
                $this->emitSagaEvent(StepEvent::debug(
                    "skipping compensate for '{$step->name()}' (never completed)",
                    ['step' => $step->name()]
                ));
                continue;
            }

            $policy = $step->compensationPolicy();

            // Rule 2: walking backwards, the first DEGRADE_ONLY barrier
            // stops the compensation chain.
            if ($policy === CompensationPolicy::DEGRADE_ONLY) {
                $this->emitSagaEvent(StepEvent::warning(
                    "saga DEGRADED: '{$step->name()}' is a DEGRADE_ONLY barrier; halting rollback",
                    ['barrier_step' => $step->name(), 'index' => $j]
                ));
                $degraded = true;
                break;
            }

            // SAFE_ROLLBACK and PARTIAL both go through compensate().
            // PARTIAL steps inspect their own state and decide what to
            // actually undo.
            $records[] = $this->runStepBackward($step, $ctx);
        }

        $outcome = $degraded ? SagaOutcome::DEGRADED : SagaOutcome::FAILED;
        $this->emitSagaEvent(($outcome === SagaOutcome::DEGRADED
            ? StepEvent::warning("saga '{$sequence->name}' ended DEGRADED")
            : StepEvent::error("saga '{$sequence->name}' ended FAILED (compensation complete)")
        )->withMetadata([
            'failure_step' => $failedStep->name(),
            'elapsed_ms' => $this->elapsedMs($startedAtMicro),
        ]));

        return $this->finalize(
            $sequence->name,
            $outcome,
            $records,
            $startedAtMicro,
            $failedStep->name(),
            $failureError
        );
    }

    // -----------------------------------------------------------------
    // Per-step helpers
    // -----------------------------------------------------------------

    private function runStepForward(StepInterface $step, SiteContext $ctx): SagaStepRecord
    {
        $name = $step->name();
        $stepStartMicro = microtime(true);

        $this->emitStepEvent($name, StepEvent::info(
            "step '{$name}' starting (forward)",
            ['step' => $name, 'policy' => $step->compensationPolicy()->value]
        ));

        $state = $this->loadOrFresh($step);

        // ---- check() ----
        $alreadySatisfied = false;
        try {
            $alreadySatisfied = $step->check($ctx, $state);
        } catch (\Throwable $e) {
            $this->emitStepEvent($name, StepEvent::warning(
                "check() threw - proceeding to execute",
                ['step' => $name, 'error' => $e->getMessage()]
            ));
            $alreadySatisfied = false;
        }

        if ($alreadySatisfied) {
            $this->emitStepEvent($name, StepEvent::info(
                "step '{$name}' already satisfied; skipping execute",
                ['step' => $name]
            ));
            // Persist the state as-is so all() reflects the fact this
            // step "saw" itself satisfied this run.
            $this->stateStore->save($state, StepOutcome::SKIPPED, null);
            return new SagaStepRecord(
                stepName: $name,
                direction: SagaStepRecord::DIRECTION_FORWARD,
                outcome: StepOutcome::SKIPPED,
                wasCheckSatisfied: true,
                wasCompensated: false,
                error: null,
                events: [],
                finalState: $state,
                attempts: $state->attemptCount,
                elapsedMs: $this->elapsedMs($stepStartMicro),
            );
        }

        // ---- execute() ----
        $state = $state->withAttemptIncremented()->withStarted();

        $result = $this->runStepCallIsolated(
            $step,
            $state,
            fn() => $step->execute($ctx, $state),
            'execute',
        );

        $this->emitResultEvents($name, $result);

        // Stamp completedAt on SUCCESS / SKIPPED-from-execute.
        $finalState = $result->isSuccess()
            ? $result->newState->withCompleted()
            : $result->newState;
        $this->stateStore->save($finalState, $result->outcome, $result->error);

        // ---- verify() (only on success) ----
        $effectiveResult = $result;
        if ($result->isSuccess()) {
            try {
                $verify = $step->verify($ctx, $finalState);
            } catch (\Throwable $e) {
                $this->emitStepEvent($name, StepEvent::warning(
                    "verify() threw - treating as verification failure",
                    ['step' => $name, 'error' => $e->getMessage()]
                ));
                $verify = StepResult::failure($finalState, "verify threw: {$e->getMessage()}");
            }
            if ($verify->isFailure()) {
                $this->emitStepEvent($name, StepEvent::error(
                    "step '{$name}' execute() succeeded but verify() FAILED",
                    ['step' => $name, 'error' => $verify->error]
                ));
                $this->emitResultEvents($name, $verify);
                $effectiveResult = $verify;
                // Roll completedAt back so the orchestrator knows the
                // step is NOT actually done.
                $finalState = $verify->newState;
                $this->stateStore->save($finalState, $verify->outcome, $verify->error);
            } else {
                $this->emitStepEvent($name, StepEvent::info(
                    "step '{$name}' verified",
                    ['step' => $name]
                ));
            }
        }

        $this->emitStepEvent($name, StepEvent::info(
            "step '{$name}' finished (forward) with outcome '{$effectiveResult->outcome->value}'",
            ['step' => $name, 'outcome' => $effectiveResult->outcome->value]
        ));

        return new SagaStepRecord(
            stepName: $name,
            direction: SagaStepRecord::DIRECTION_FORWARD,
            outcome: $effectiveResult->outcome,
            wasCheckSatisfied: false,
            wasCompensated: false,
            error: $effectiveResult->error,
            events: $effectiveResult->events,
            finalState: $finalState,
            attempts: $finalState->attemptCount,
            elapsedMs: $this->elapsedMs($stepStartMicro),
        );
    }

    private function runStepBackward(StepInterface $step, SiteContext $ctx): SagaStepRecord
    {
        $name = $step->name();
        $stepStartMicro = microtime(true);

        $state = $this->loadOrFresh($step);

        $this->emitStepEvent($name, StepEvent::info(
            "step '{$name}' compensating (backward)",
            ['step' => $name, 'policy' => $step->compensationPolicy()->value]
        ));

        $result = $this->runStepCallIsolated(
            $step,
            $state,
            fn() => $step->compensate($ctx, $state),
            'compensate',
        );

        $this->emitResultEvents($name, $result);

        $finalState = $result->newState;
        $this->stateStore->save($finalState, $result->outcome, $result->error);

        $this->emitStepEvent($name, StepEvent::info(
            "step '{$name}' compensate finished with outcome '{$result->outcome->value}'",
            ['step' => $name, 'outcome' => $result->outcome->value]
        ));

        return new SagaStepRecord(
            stepName: $name,
            direction: SagaStepRecord::DIRECTION_BACKWARD,
            outcome: $result->outcome,
            wasCheckSatisfied: false,
            wasCompensated: true,
            error: $result->error,
            events: $result->events,
            finalState: $finalState,
            attempts: $finalState->attemptCount,
            elapsedMs: $this->elapsedMs($stepStartMicro),
        );
    }

    // -----------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------

    /**
     * Run one step's execute()/compensate() call. When the orchestrator
     * was constructed with a {@see StepProcessIsolator}, the call is
     * wrapped in a forked child so a PHP fatal in the step crashes the
     * child only. When no isolator is configured (the historical and
     * test-time path), $work() runs in-process and any throwable is
     * converted to a failure result here.
     *
     * The in-process branch must remain wrapped in try/catch because
     * tests rely on it (no fork) and production may also run without
     * isolation if pcntl is unavailable.
     *
     * @param callable():StepResult $work
     * @param string $phase  'execute' | 'compensate' - used only for the
     *                       error message when synthesising a failure.
     */
    private function runStepCallIsolated(
        StepInterface $step,
        StepState $state,
        callable $work,
        string $phase
    ): StepResult {
        $name = $step->name();
        $inProcess = function () use ($work, $state, $name, $phase): StepResult {
            try {
                return $work();
            } catch (\Throwable $e) {
                $this->emitStepEvent($name, StepEvent::error(
                    "{$phase}() threw uncaught exception",
                    ['step' => $name, 'error' => $e->getMessage(), 'class' => $e::class]
                ));
                return StepResult::failure(
                    $state,
                    "{$phase} uncaught exception: {$e->getMessage()}"
                );
            }
        };

        if ($this->isolator === null || !$this->isolator->isEnabled()) {
            return $inProcess();
        }

        $result = $this->isolator->runWithIsolation($step, $state, $inProcess);

        // A synthesised failure from the isolator (subprocess crashed,
        // timeout, etc.) carries an error string already. Mirror it
        // into the event stream so the operator sees the cause in the
        // job timeline, not just the saga summary.
        if ($result->isFailure() && $result->error !== null
            && str_contains($result->error, 'isolation child')
        ) {
            $this->emitStepEvent($name, StepEvent::error(
                "step '{$name}' subprocess died during {$phase}()",
                ['step' => $name, 'error' => $result->error, 'phase' => $phase]
            ));
        }

        return $result;
    }

    private function loadOrFresh(StepInterface $step): StepState
    {
        return $this->stateStore->load($step->name())
            ?? StepState::fresh($step->name(), $step->schemaVersion());
    }

    private function isStepCompleted(StepInterface $step): bool
    {
        $state = $this->stateStore->load($step->name());
        return $state !== null && $state->isComplete();
    }

    /**
     * "Forward success" includes both SUCCESS and SKIPPED. Anything else
     * (FAILURE, TIMEOUT, PARTIAL, RETRY_LATER) is a stop signal for the
     * forward walk in this layer.
     *
     * PARTIAL and RETRY_LATER will become legitimate non-failure
     * outcomes once Step 5c adds the persistent queue with backoff.
     */
    private function isForwardSuccess(SagaStepRecord $record): bool
    {
        return $record->outcome === StepOutcome::SUCCESS
            || $record->outcome === StepOutcome::SKIPPED;
    }

    /**
     * @param StepResult $result
     */
    private function emitResultEvents(string $stepName, StepResult $result): void
    {
        foreach ($result->events as $ev) {
            $this->emitStepEvent($stepName, $ev);
        }
    }

    private function emitStepEvent(string $stepName, StepEvent $event): void
    {
        $this->eventSink->emit($stepName, $event);
    }

    private function emitSagaEvent(StepEvent $event): void
    {
        $this->eventSink->emitSaga($event);
    }

    private function elapsedMs(float $sinceMicro): int
    {
        return (int) max(0, round((microtime(true) - $sinceMicro) * 1000));
    }

    /**
     * @param list<SagaStepRecord> $records
     */
    private function finalize(
        string $sagaName,
        SagaOutcome $outcome,
        array $records,
        float $startedAtMicro,
        ?string $failureStep,
        ?string $failureError
    ): SagaResult {
        $allEvents = array_map(
            static fn(array $row) => $row['event'],
            $this->eventSink->drain()
        );

        if (count($allEvents) > self::EVENT_BUFFER_WARN_AT) {
            // Defensive: a step that emits unbounded events is a bug.
            // We log it via the sink itself so it's persisted.
            $this->eventSink->emitSaga(StepEvent::warning(
                'event buffer exceeded soft limit',
                ['count' => count($allEvents), 'limit' => self::EVENT_BUFFER_WARN_AT]
            ));
        }

        return new SagaResult(
            sagaName: $sagaName,
            outcome: $outcome,
            stepRecords: $records,
            events: $allEvents,
            elapsedMs: $this->elapsedMs($startedAtMicro),
            failureStepName: $failureStep,
            failureError: $failureError,
        );
    }
}
