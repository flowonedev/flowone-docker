<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Step\StepEvent;

/**
 * Terminal result of a saga run.
 *
 * Consumers use this to:
 *   - decide what to do next (UI: show success / show degraded warning
 *     / show retry button / surface the operator-attention banner)
 *   - persist a row in the site_jobs table with outcome + error
 *   - replay events to the UI/audit log
 *
 * The class is immutable; construct it once at the end of the saga run.
 */
final class SagaResult
{
    /**
     * @param list<SagaStepRecord> $stepRecords  one per step, in saga order
     * @param list<StepEvent>      $events       flat chronological feed across
     *                                           all steps + the orchestrator's
     *                                           own narrative events
     */
    public function __construct(
        public readonly string $sagaName,
        public readonly SagaOutcome $outcome,
        public readonly array $stepRecords,
        public readonly array $events,
        public readonly int $elapsedMs,
        public readonly ?string $failureStepName = null,
        public readonly ?string $failureError = null
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->outcome === SagaOutcome::SUCCEEDED;
    }

    public function isDegraded(): bool
    {
        return $this->outcome === SagaOutcome::DEGRADED;
    }

    public function isFailure(): bool
    {
        return $this->outcome === SagaOutcome::FAILED;
    }

    /**
     * Return the LATEST record for this step (BACKWARD overrides
     * FORWARD when both exist). The "latest" is what an operator
     * actually cares about for "did this step end up applied or rolled
     * back" - so this is what the UI typically renders.
     */
    public function findStep(string $stepName): ?SagaStepRecord
    {
        $latest = null;
        foreach ($this->stepRecords as $r) {
            if ($r->stepName === $stepName) {
                $latest = $r;
            }
        }
        return $latest;
    }

    /**
     * Return ALL records for this step, in chronological order. Useful
     * for assertions that want to verify "step was executed forward
     * THEN compensated".
     *
     * @return list<SagaStepRecord>
     */
    public function findStepHistory(string $stepName): array
    {
        return array_values(array_filter(
            $this->stepRecords,
            static fn(SagaStepRecord $r) => $r->stepName === $stepName,
        ));
    }

    /**
     * @return list<SagaStepRecord>
     */
    public function compensatedSteps(): array
    {
        return array_values(array_filter(
            $this->stepRecords,
            static fn(SagaStepRecord $r) => $r->wasCompensated,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'saga' => $this->sagaName,
            'outcome' => $this->outcome->value,
            'elapsed_ms' => $this->elapsedMs,
            'failure_step' => $this->failureStepName,
            'failure_error' => $this->failureError,
            'step_count' => count($this->stepRecords),
            'event_count' => count($this->events),
            'steps' => array_map(
                static fn(SagaStepRecord $r) => $r->toArray(),
                $this->stepRecords,
            ),
        ];
    }
}
