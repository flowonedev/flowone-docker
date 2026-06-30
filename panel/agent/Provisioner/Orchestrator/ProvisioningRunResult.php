<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

/**
 * Return value of a ProvisioningSagaRunner run.
 *
 * Wraps the raw SagaResult with the actual_state transition story so
 * the caller (worker, HTTP controller, CLI tool) can:
 *   - render the user-facing outcome,
 *   - update sites.actual_state in their own bookkeeping if they
 *     cached a stale copy,
 *   - reason about whether the operator needs to look at this site.
 *
 * Immutable.
 */
final class ProvisioningRunResult
{
    public function __construct(
        public readonly SagaDirection $direction,
        public readonly SagaResult $saga,
        /** The actual_state the site was in before the bridge entered the saga. */
        public readonly string $previousState,
        /** The actual_state the bridge transitioned the site to during the in-flight window. */
        public readonly string $inFlightState,
        /**
         * The actual_state the site ends up in after this run.
         *
         * For SUCCEEDED/FAILED/DEGRADED outcomes this is the terminal
         * state the bridge wrote (active / failed / degraded / absent).
         *
         * For ABORTED this equals $inFlightState because the bridge
         * deliberately leaves a half-finished saga in its in-flight
         * state for the next worker to resume.
         */
        public readonly string $finalState,
        /** True when the bridge actually issued the entry transition (false if site was already in-flight). */
        public readonly bool $enteredInFlight,
        /** True when the bridge actually issued the terminal transition. */
        public readonly bool $exitedInFlight
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->saga->isSuccess();
    }

    public function isDegraded(): bool
    {
        return $this->saga->isDegraded();
    }

    public function isFailure(): bool
    {
        return $this->saga->isFailure();
    }

    public function requiresOperatorAttention(): bool
    {
        return $this->saga->outcome->requiresOperatorAttention()
            || $this->isDegraded();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'previous_state' => $this->previousState,
            'in_flight_state' => $this->inFlightState,
            'final_state' => $this->finalState,
            'entered_in_flight' => $this->enteredInFlight,
            'exited_in_flight' => $this->exitedInFlight,
            'saga' => $this->saga->toArray(),
        ];
    }
}
