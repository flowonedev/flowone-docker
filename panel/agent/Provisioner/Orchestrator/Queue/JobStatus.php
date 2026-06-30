<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * Job lifecycle status, mirroring the `site_jobs.status` ENUM.
 *
 * Transitions are linear forward in time. The dispatcher inserts as
 * QUEUED, the worker advances QUEUED -> RUNNING on claim, and on
 * completion moves to SUCCEEDED / FAILED. RETRY_LATER / PARTIAL outcomes
 * cycle the row back to QUEUED with a future enqueued_at (see
 * JobWorker::reEnqueueWithBackoff()).
 *
 * CANCELLED is operator-initiated and prevents the worker from
 * claiming the row; the worker re-checks status under the lease lock and
 * skips cancelled rows even if they were claimed mid-flight.
 *
 * This enum is the single source of truth for status strings - never
 * hardcode 'queued' anywhere; reference JobStatus::QUEUED->value so a
 * rename surfaces as a compile error.
 */
enum JobStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * Whether the job is in a state the worker can pick up.
     */
    public function isClaimable(): bool
    {
        return $this === self::QUEUED;
    }

    /**
     * Whether the job has reached a terminal state and no worker will
     * ever touch it again.
     */
    public function isTerminal(): bool
    {
        return $this === self::SUCCEEDED
            || $this === self::FAILED
            || $this === self::CANCELLED;
    }
}
