<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step;

/**
 * Optional base class with sensible defaults for the StepInterface
 * methods that most steps don't need to customize.
 *
 * Steps are free to implement StepInterface directly when they have
 * stronger constraints; AbstractStep just eliminates 30 lines of
 * boilerplate per step for the common case.
 *
 * Concrete subclasses MUST override:
 *   - name()
 *   - compensationPolicy()
 *   - check()
 *   - execute()
 *
 * Subclasses MAY override:
 *   - schemaVersion()  (defaults to 1)
 *   - compensate()     (defaults to no-op for SAFE_ROLLBACK; throws for DEGRADE_ONLY)
 *   - verify()         (defaults to re-running check())
 */
abstract class AbstractStep implements StepInterface
{
    public function schemaVersion(): int
    {
        return 1;
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        if ($this->compensationPolicy() === CompensationPolicy::DEGRADE_ONLY) {
            throw new \LogicException(
                "Step '{$this->name()}' is DEGRADE_ONLY; compensate() must not be called"
            );
        }
        return StepResult::success(
            $state,
            [StepEvent::info("default compensate(): no-op for {$this->name()}")]
        );
    }

    public function verify(SiteContext $ctx, StepState $state): StepResult
    {
        try {
            $ok = $this->check($ctx, $state);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "verify(): check() threw " . $e::class . ": " . $e->getMessage()
            );
        }
        return $ok
            ? StepResult::success($state)
            : StepResult::failure($state, 'verify(): check() returned false after execute()');
    }
}
