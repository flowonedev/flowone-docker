<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * In-process implementation of StepStateStore. Used by:
 *   - The orchestrator unit tests (no DB dependency)
 *   - The single-shot end-to-end saga tests where we don't need crash
 *     recovery
 *   - Local dev runs where the operator drives the orchestrator from
 *     the CLI and doesn't care about persistence
 *
 * The DB-backed store lands in Step 5c.
 */
final class InMemoryStepStateStore implements StepStateStore
{
    /** @var array<string, StepState> */
    private array $records = [];

    public function load(string $stepName): ?StepState
    {
        return $this->records[$stepName] ?? null;
    }

    public function save(
        StepState $state,
        ?StepOutcome $lastOutcome = null,
        ?string $lastError = null
    ): void {
        $this->records[$state->stepName] = $state;
    }

    public function all(): array
    {
        return $this->records;
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
