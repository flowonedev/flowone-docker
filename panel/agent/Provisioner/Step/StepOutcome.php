<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step;

/**
 * Outcome of a single step execution attempt. Reported by StepResult.
 *
 * The orchestrator's policy:
 *   - SUCCESS:    record state, advance to next step
 *   - SKIPPED:    record state, advance to next step (check() returned "already done")
 *   - FAILURE:    invoke compensation or degrade per CompensationPolicy
 *   - TIMEOUT:    treat as FAILURE but with a specific failure_reason for retry policy
 *   - PARTIAL:    state was partially advanced; resume() must continue from where execute() left off
 *                  (e.g. a multi-domain DNS propagation step that finished 3 of 5 records)
 *   - RETRY_LATER: transient external failure (LE rate limit, NAS unreachable). Re-enqueue with backoff.
 */
enum StepOutcome: string
{
    case SUCCESS = 'success';
    case SKIPPED = 'skipped';
    case FAILURE = 'failure';
    case TIMEOUT = 'timeout';
    case PARTIAL = 'partial';
    case RETRY_LATER = 'retry_later';

    public function isTerminal(): bool
    {
        return $this === self::SUCCESS
            || $this === self::SKIPPED
            || $this === self::FAILURE;
    }

    public function isSuccessful(): bool
    {
        return $this === self::SUCCESS || $this === self::SKIPPED;
    }

    public function isFailure(): bool
    {
        return $this === self::FAILURE || $this === self::TIMEOUT;
    }
}
