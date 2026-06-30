<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * Wraps WorkerDaemon to bound the worker process's lifetime.
 *
 * Why: a long-lived PHP process accumulates memory across thousands
 * of jobs even with no leaks in user code — opcache, autoload caches,
 * PDO statement caches, anything that holds references on success.
 * The supervisor rebuilds the JobWorker (via a factory closure) after
 * N jobs so the daemon settles back to a known footprint regularly.
 *
 * The supervisor sits ABOVE WorkerDaemon and BELOW systemd:
 *
 *     systemd  ──>  WorkerSupervisor  ──>  WorkerDaemon  ──>  JobWorker
 *
 * The supervisor itself is single-threaded; it does not fork. The
 * "restart" is a soft restart: discard the worker instance, build a
 * new one via the factory, hand it to a fresh WorkerDaemon, run.
 * PHP's autoload cache and opcache stay warm (which is what we want)
 * but user-space references are released.
 *
 * Restart throttling:
 *   - A "rapid restart" is two restarts that occur within
 *     rapidRestartWindowSec of each other.
 *   - If we see more than maxRapidRestarts in a row, the supervisor
 *     exits non-zero so systemd's RestartSec kicks in and slows the
 *     thrash.
 *   - This catches "worker boot itself crashes" scenarios; once the
 *     daemon ran any jobs at all, the rapid counter resets.
 *
 * Signal forwarding:
 *   - Supervisor catches SIGTERM/SIGINT and asks the current daemon
 *     to requestStop(). The daemon's signal handler does the same;
 *     having both makes the test path work without pcntl.
 *
 * Test mode:
 *   - run() is the production entry. Tests use runUntil() with a
 *     stop closure. The factory closure can scripted to return a
 *     FakeJobWorker that mutates a shared counter so the test can
 *     verify "fresh worker built after N jobs".
 */
final class WorkerSupervisor
{
    /** Default jobs to process before rebuilding the worker. */
    public const DEFAULT_JOBS_PER_WORKER = 100;

    /** Max consecutive rapid restarts before the supervisor itself exits. */
    public const DEFAULT_MAX_RAPID_RESTARTS = 5;

    /** Threshold under which two consecutive restarts count as "rapid". */
    public const DEFAULT_RAPID_RESTART_WINDOW_SEC = 10;

    private bool $stopRequested = false;
    private int $totalRestarts = 0;
    private int $rapidRestartStreak = 0;
    private float $lastRestartAtUnix = 0.0;

    /**
     * @param \Closure(): JobTicker $workerFactory  Returns a fresh JobWorker
     *                                              (or any JobTicker) per
     *                                              invocation.
     */
    public function __construct(
        private readonly \Closure $workerFactory,
        private readonly int $jobsPerWorker = self::DEFAULT_JOBS_PER_WORKER,
        private readonly int $maxRapidRestarts = self::DEFAULT_MAX_RAPID_RESTARTS,
        private readonly int $rapidRestartWindowSec = self::DEFAULT_RAPID_RESTART_WINDOW_SEC,
        private readonly int $pollIntervalMs = WorkerDaemon::DEFAULT_POLL_INTERVAL_MS,
        private readonly string $pauseFile = WorkerDaemon::DEFAULT_PAUSE_FILE,
        private readonly bool $installSignalHandlers = true
    ) {
        if ($jobsPerWorker < 1) {
            throw new \InvalidArgumentException('jobsPerWorker must be >= 1');
        }
        if ($maxRapidRestarts < 1) {
            throw new \InvalidArgumentException('maxRapidRestarts must be >= 1');
        }
    }

    /**
     * Production entry point: loop until SIGTERM/SIGINT or until the
     * rapid-restart ceiling is exceeded.
     */
    public function run(): int
    {
        $this->maybeInstallSignalHandlers();
        return $this->runUntil(fn() => $this->stopRequested);
    }

    /**
     * Test / advanced entry: run until $shouldStop returns true. The
     * closure is checked BETWEEN worker rotations (not between
     * individual job ticks - that's the daemon's contract). Returns
     * an exit code (0 = clean, non-zero = ceiling exceeded).
     */
    public function runUntil(\Closure $shouldStop): int
    {
        while (!$shouldStop($this) && !$this->stopRequested) {
            $worker = ($this->workerFactory)();
            $daemon = new WorkerDaemon(
                worker: $worker,
                pollIntervalMs: $this->pollIntervalMs,
                pauseFile: $this->pauseFile,
                installSignalHandlers: false, // supervisor owns signals
            );

            $jobBudget = $this->jobsPerWorker;
            $daemon->runUntil(function (WorkerDaemon $d) use ($jobBudget, $shouldStop): bool {
                if ($this->stopRequested) {
                    return true;
                }
                if ($shouldStop($this)) {
                    return true;
                }
                return $d->stats()->jobsTouched() >= $jobBudget;
            });

            if ($this->stopRequested) {
                break;
            }
            // Outer stop closure asked us to halt between rotations.
            if ($shouldStop($this)) {
                break;
            }

            // The daemon returned because the job budget was reached
            // (not because of a stop). Rotate the worker.
            if (!$this->recordRestart()) {
                fwrite(STDERR, sprintf(
                    "[worker-supervisor] exceeded %d rapid restarts in %ds; bailing out\n",
                    $this->maxRapidRestarts,
                    $this->rapidRestartWindowSec,
                ));
                return 2;
            }
        }
        return 0;
    }

    public function requestStop(?int $signal = null): void
    {
        $this->stopRequested = true;
        if ($signal !== null) {
            fwrite(STDERR, "[worker-supervisor] signal {$signal} received; stopping\n");
        }
    }

    public function totalRestarts(): int
    {
        return $this->totalRestarts;
    }

    public function rapidRestartStreak(): int
    {
        return $this->rapidRestartStreak;
    }

    /**
     * Record a restart and update the rapid-streak counter. Returns
     * false when the ceiling has been exceeded (caller exits).
     */
    private function recordRestart(): bool
    {
        $now = microtime(true);
        if ($this->lastRestartAtUnix > 0.0
            && ($now - $this->lastRestartAtUnix) <= $this->rapidRestartWindowSec) {
            $this->rapidRestartStreak++;
        } else {
            $this->rapidRestartStreak = 1;
        }
        $this->lastRestartAtUnix = $now;
        $this->totalRestarts++;
        return $this->rapidRestartStreak <= $this->maxRapidRestarts;
    }

    private function maybeInstallSignalHandlers(): void
    {
        if (!$this->installSignalHandlers) {
            return;
        }
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signo): void {
            $this->requestStop($signo);
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
