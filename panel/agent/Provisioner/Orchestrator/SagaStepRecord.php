<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * One row of the saga's per-step audit trail.
 *
 * The orchestrator produces one SagaStepRecord per step regardless of
 * whether the step ran (executed), was already satisfied (skipped by
 * check), or was rolled back (compensated). The record captures:
 *
 *   - direction:        FORWARD (execute) | BACKWARD (compensate)
 *   - outcome:          the StepOutcome from the step's last call
 *   - was_check_satisfied: did check() return true so execute() was
 *                          skipped? Useful for differentiating "idempotent
 *                          no-op" from "executed and succeeded".
 *   - was_compensated:  did compensate() run on this step in the same saga?
 *   - error:            error message if the step failed
 *   - events:           every StepEvent the step emitted in BOTH directions,
 *                       in chronological order
 *   - final_state:      the StepState as recorded after the step's last
 *                       call (forward or compensate, whichever came last)
 *   - attempts:         number of execute() calls (>=0)
 *   - elapsed_ms:       cumulative wall time spent on this step
 *
 * Immutable. The orchestrator constructs a fresh one via with*() each
 * time it advances the step's lifecycle.
 */
final class SagaStepRecord
{
    public const DIRECTION_FORWARD = 'forward';
    public const DIRECTION_BACKWARD = 'backward';

    /**
     * @param list<StepEvent> $events
     */
    public function __construct(
        public readonly string $stepName,
        public readonly string $direction,
        public readonly StepOutcome $outcome,
        public readonly bool $wasCheckSatisfied,
        public readonly bool $wasCompensated,
        public readonly ?string $error,
        public readonly array $events,
        public readonly StepState $finalState,
        public readonly int $attempts,
        public readonly int $elapsedMs
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->outcome === StepOutcome::SUCCESS;
    }

    public function isFailure(): bool
    {
        return $this->outcome === StepOutcome::FAILURE
            || $this->outcome === StepOutcome::TIMEOUT;
    }

    public function withEvents(array $events): self
    {
        return new self(
            $this->stepName,
            $this->direction,
            $this->outcome,
            $this->wasCheckSatisfied,
            $this->wasCompensated,
            $this->error,
            $events,
            $this->finalState,
            $this->attempts,
            $this->elapsedMs,
        );
    }

    /**
     * Lossy summary suitable for human display / log lines.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->stepName,
            'direction' => $this->direction,
            'outcome' => $this->outcome->value,
            'check_satisfied' => $this->wasCheckSatisfied,
            'compensated' => $this->wasCompensated,
            'attempts' => $this->attempts,
            'elapsed_ms' => $this->elapsedMs,
            'error' => $this->error,
            'event_count' => count($this->events),
        ];
    }
}
