<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

/**
 * Terminal outcome of a saga run.
 *
 *   SUCCEEDED  - every step in the sequence ended in StepOutcome::SUCCESS
 *                (or was already satisfied by check() and got skipped).
 *
 *   FAILED     - a step failed AND the compensate chain ran cleanly all
 *                the way back, leaving the system in a clean pre-saga
 *                state.
 *
 *   DEGRADED   - a step failed AND the compensate chain hit a
 *                DEGRADE_ONLY step that we MUST NOT roll back (e.g.
 *                DatabaseCreateStep with potential user data). The site
 *                is parked: the operator must decide whether to retry,
 *                manually finish provisioning, or destructively delete.
 *
 *   ABORTED    - the saga was cancelled externally (deadline exceeded,
 *                operator kill, etc.) before reaching a terminal state.
 *                Different from FAILED because no failure event was
 *                emitted by a step - the orchestrator gave up by choice.
 *
 * IN_PROGRESS is NOT a terminal value; it's the implicit pre-terminal
 * state and is never returned as a SagaResult outcome.
 */
enum SagaOutcome: string
{
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case DEGRADED = 'degraded';
    case ABORTED = 'aborted';

    public function isTerminal(): bool
    {
        return true;
    }

    public function isClean(): bool
    {
        return $this === self::SUCCEEDED || $this === self::FAILED;
    }

    public function requiresOperatorAttention(): bool
    {
        return $this === self::DEGRADED || $this === self::ABORTED;
    }
}
