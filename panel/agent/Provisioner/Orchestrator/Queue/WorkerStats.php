<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * Observability counters for one WorkerDaemon instance.
 *
 * The daemon updates these as it loops; the supervisor reads them to
 * decide when to restart; the operator reads them via SIGUSR1 (the
 * daemon prints `toLine()` to stderr) or via the future status RPC.
 *
 * Mutable on purpose — every counter increment would otherwise need a
 * full DTO rebuild, which gets expensive in a hot loop. The counters
 * are never written from outside the daemon's own thread, so the
 * mutability is contained.
 *
 * Each field has a deliberate semantic:
 *
 *   - ticks_total: every tickOnce() call, regardless of outcome.
 *   - ticks_idle: tickOnce() returned isIdle (queue empty / nothing
 *     eligible). Used to size the idle backoff sleep.
 *   - jobs_processed: tickOnce() returned processed=true (saga ran).
 *   - jobs_rejected: claimed but rejected (cancelled, unsupported,
 *     missing site row). Bumps the same lane as processed for
 *     "did we touch a row this tick?".
 *   - jobs_failed: jobs that ended in a FAILED terminal status. A
 *     subset of jobs_processed + jobs_rejected.
 *   - last_*_unix: monotonic float wallclock timestamps for "when did
 *     this last happen". Useful for staleness checks.
 *
 * Reset semantics: the supervisor calls reset() between worker
 * instance restarts so the per-worker counts are clean. Lifetime
 * cumulative counts are tracked at the supervisor level
 * (WorkerSupervisorStats - not in 5c-3 scope; the supervisor just
 * exposes its own simple counter).
 */
final class WorkerStats
{
    public int $ticksTotal = 0;
    public int $ticksIdle = 0;
    public int $jobsProcessed = 0;
    public int $jobsRejected = 0;
    public int $jobsFailed = 0;

    public float $startedAtUnix;
    public ?float $lastTickAtUnix = null;
    public ?float $lastProcessedAtUnix = null;
    public ?float $lastIdleAtUnix = null;

    public function __construct()
    {
        $this->startedAtUnix = microtime(true);
    }

    public function recordTick(JobClaimResult $result): void
    {
        $now = microtime(true);
        $this->ticksTotal++;
        $this->lastTickAtUnix = $now;

        if ($result->isIdle()) {
            $this->ticksIdle++;
            $this->lastIdleAtUnix = $now;
            return;
        }

        $this->lastProcessedAtUnix = $now;
        if ($result->processed) {
            $this->jobsProcessed++;
        } else {
            $this->jobsRejected++;
        }
        if ($result->terminalStatus === JobStatus::FAILED->value) {
            $this->jobsFailed++;
        }
    }

    public function reset(): void
    {
        $this->ticksTotal = 0;
        $this->ticksIdle = 0;
        $this->jobsProcessed = 0;
        $this->jobsRejected = 0;
        $this->jobsFailed = 0;
        $this->startedAtUnix = microtime(true);
        $this->lastTickAtUnix = null;
        $this->lastProcessedAtUnix = null;
        $this->lastIdleAtUnix = null;
    }

    /**
     * Total jobs touched (processed + rejected). The supervisor uses
     * this for "should I rotate the worker?" decisions.
     */
    public function jobsTouched(): int
    {
        return $this->jobsProcessed + $this->jobsRejected;
    }

    public function uptimeSeconds(): int
    {
        return (int) max(0, microtime(true) - $this->startedAtUnix);
    }

    /**
     * Tight, human-readable one-line summary suitable for `journalctl`.
     */
    public function toLine(): string
    {
        return sprintf(
            'uptime=%ds ticks=%d idle=%d processed=%d rejected=%d failed=%d',
            $this->uptimeSeconds(),
            $this->ticksTotal,
            $this->ticksIdle,
            $this->jobsProcessed,
            $this->jobsRejected,
            $this->jobsFailed,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'uptime_s' => $this->uptimeSeconds(),
            'ticks_total' => $this->ticksTotal,
            'ticks_idle' => $this->ticksIdle,
            'jobs_processed' => $this->jobsProcessed,
            'jobs_rejected' => $this->jobsRejected,
            'jobs_failed' => $this->jobsFailed,
            'started_at_unix' => $this->startedAtUnix,
            'last_tick_at_unix' => $this->lastTickAtUnix,
            'last_processed_at_unix' => $this->lastProcessedAtUnix,
            'last_idle_at_unix' => $this->lastIdleAtUnix,
        ];
    }
}
