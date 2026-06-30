<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Saga;

use VpsAdmin\Agent\Provisioner\Step\StepInterface;

/**
 * Ordered, immutable list of steps the orchestrator will execute for a
 * given saga (create / delete / restore / archive).
 *
 * The orchestrator (Step 5) consumes this and:
 *   - On forward execution: runs check() on each step in order.
 *   - On failure: walks BACKWARDS through completed steps invoking
 *     compensate() for each SAFE_ROLLBACK one, stopping at the first
 *     DEGRADE_ONLY (which forces the site into degraded state instead).
 *
 * SagaSequence is intentionally a thin wrapper rather than a fluent
 * builder. The whole point is to keep the canonical sequence visible
 * at one site (SagaRegistry) so a code reviewer can grep for it.
 */
final class SagaSequence
{
    /** @param list<StepInterface> $steps */
    public function __construct(
        public readonly string $name,
        public readonly array $steps
    ) {
        $seen = [];
        foreach ($this->steps as $i => $step) {
            if (!$step instanceof StepInterface) {
                throw new \InvalidArgumentException(
                    "SagaSequence['{$name}'][{$i}] is not a StepInterface"
                );
            }
            $n = $step->name();
            if (isset($seen[$n])) {
                throw new \InvalidArgumentException(
                    "SagaSequence['{$name}'] has duplicate step '{$n}'"
                );
            }
            $seen[$n] = true;
        }
    }

    public function count(): int
    {
        return count($this->steps);
    }

    /**
     * @return list<string>
     */
    public function stepNames(): array
    {
        return array_map(static fn(StepInterface $s) => $s->name(), $this->steps);
    }

    public function get(int $index): StepInterface
    {
        if (!isset($this->steps[$index])) {
            throw new \OutOfBoundsException("No step at index {$index} in saga '{$this->name}'");
        }
        return $this->steps[$index];
    }

    public function findByName(string $name): ?StepInterface
    {
        foreach ($this->steps as $step) {
            if ($step->name() === $name) {
                return $step;
            }
        }
        return null;
    }
}
