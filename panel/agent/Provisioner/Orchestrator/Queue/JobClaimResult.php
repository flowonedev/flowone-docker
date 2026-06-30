<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * What happened on one JobWorker::tickOnce() call.
 *
 * The worker daemon loops on tickOnce() and decides what to do based on
 * this DTO:
 *   - `claimed === false` and `job === null` -> queue is empty / no
 *     eligible row; the daemon sleeps for ::POLL_BACKOFF_MS and tries
 *     again.
 *   - `claimed === true` and `processed === true` -> a job was claimed
 *     and run to a terminal state (succeeded / failed / re-enqueued).
 *     The daemon immediately loops to claim the next one.
 *   - `claimed === true` and `processed === false` -> a job was claimed
 *     but rejected before any work happened (unsupported type, missing
 *     site row, cancelled mid-claim). The job is already marked failed
 *     in the DB; the daemon loops on.
 *
 * The DTO is immutable. Workers attach an optional `error` and `note`
 * for human-readable logging - the structured failure detail lives in
 * the job row itself and in site_job_events.
 */
final class JobClaimResult
{
    public function __construct(
        public readonly bool $claimed,
        public readonly bool $processed,
        public readonly ?SiteJob $job,
        public readonly ?string $terminalStatus,
        public readonly ?string $note = null,
        public readonly ?string $error = null,
        public readonly int $elapsedMs = 0
    ) {
    }

    /**
     * "Nothing claimable in the queue right now" - this is the most
     * common result on an idle system.
     */
    public static function empty(): self
    {
        return new self(
            claimed: false,
            processed: false,
            job: null,
            terminalStatus: null,
        );
    }

    /**
     * A job was claimed but immediately rejected (e.g. cancelled before
     * we started, or for an unsupported type). The row is already
     * marked terminal in the DB.
     */
    public static function rejected(SiteJob $job, string $terminalStatus, string $reason, int $elapsedMs): self
    {
        return new self(
            claimed: true,
            processed: false,
            job: $job,
            terminalStatus: $terminalStatus,
            note: 'rejected: ' . $reason,
            elapsedMs: $elapsedMs,
        );
    }

    /**
     * A job was claimed, the saga ran, and the row reached one of
     * SUCCEEDED / FAILED / QUEUED (re-enqueued for retry).
     */
    public static function processed(SiteJob $job, string $terminalStatus, ?string $note, int $elapsedMs): self
    {
        return new self(
            claimed: true,
            processed: true,
            job: $job,
            terminalStatus: $terminalStatus,
            note: $note,
            elapsedMs: $elapsedMs,
        );
    }

    public function isIdle(): bool
    {
        return !$this->claimed;
    }
}
