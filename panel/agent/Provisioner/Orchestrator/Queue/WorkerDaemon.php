<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * The long-lived process that drives the queue.
 *
 * The daemon loops on JobWorker::tickOnce() forever. Each iteration:
 *
 *   1. Check `stopRequested` (set by signal handler or supervisor).
 *      Exit cleanly if asked.
 *   2. Check the pause file (operator-controlled). If present, sleep
 *      pollIntervalMs and continue without claiming.
 *   3. Call worker->tickOnce(). Record into WorkerStats.
 *   4. If the tick was idle, sleep pollIntervalMs. If it processed a
 *      job, loop immediately so backlogs drain fast.
 *
 * Why a separate class from JobWorker:
 *   - JobWorker is single-tick and trivially testable.
 *   - The daemon adds signal/lifecycle complexity that the worker
 *     should never have to know about. Keeping them separate means a
 *     test can drive 1000 ticks against a fake JobWorker without
 *     touching pcntl_signal.
 *
 * Signal contract:
 *   - SIGTERM / SIGINT: request stop. The current tick finishes
 *     (including the saga the worker is running) and then the daemon
 *     returns from run(). systemd waits TimeoutStopSec before
 *     SIGKILL — the unit file sets this to 90s to allow a long saga
 *     to land cleanly.
 *   - SIGHUP: reserved. Today the handler is a no-op log line; future
 *     work re-reads the pause file / log level without restart.
 *   - SIGUSR1: dump stats to stderr. Operator-friendly diagnostic.
 *
 * Pause file:
 *   - Touch /var/lib/flowone/worker.paused to suspend job claims
 *     without restarting the daemon. The worker's own ticks become
 *     no-ops; the supervisor and dead-lease sweeper still run.
 *   - Useful during DB maintenance windows.
 *
 * Test mode:
 *   - run() is the production entry point; for tests use runUntil()
 *     with a stop closure. The signal handler simply flips
 *     stopRequested, which any stop closure can also inspect.
 */
final class WorkerDaemon
{
    /** Default sleep between ticks when the queue is idle (ms). */
    public const DEFAULT_POLL_INTERVAL_MS = 1000;

    /** Default pause-file location. The systemd unit reads this dir RW. */
    public const DEFAULT_PAUSE_FILE = '/var/lib/flowone/worker.paused';

    private bool $stopRequested = false;
    private ?int $stopSignal = null;
    private bool $running = false;

    private readonly WorkerStats $stats;

    public function __construct(
        private readonly JobTicker $worker,
        private readonly int $pollIntervalMs = self::DEFAULT_POLL_INTERVAL_MS,
        private readonly string $pauseFile = self::DEFAULT_PAUSE_FILE,
        private readonly bool $installSignalHandlers = true
    ) {
        if ($pollIntervalMs < 0) {
            throw new \InvalidArgumentException('pollIntervalMs must be >= 0');
        }
        $this->stats = new WorkerStats();
    }

    /**
     * Run the loop forever (or until a SIGTERM/SIGINT is received).
     *
     * Returns the signal number that triggered the stop, or 0 if the
     * daemon exited cleanly via an internal requestStop().
     */
    public function run(): int
    {
        $this->runUntil(fn() => $this->stopRequested);
        return $this->stopSignal ?? 0;
    }

    /**
     * Run the loop until $shouldStop returns true. The closure is
     * called BETWEEN ticks (never mid-saga); receives the daemon as
     * its only argument so tests can interrogate stats.
     */
    public function runUntil(\Closure $shouldStop): void
    {
        if ($this->running) {
            throw new \LogicException('WorkerDaemon is already running');
        }
        $this->maybeInstallSignalHandlers();
        $this->running = true;

        try {
            while (!$shouldStop($this) && !$this->stopRequested) {
                if ($this->isPaused()) {
                    $this->sleepMs($this->pollIntervalMs);
                    continue;
                }

                $result = $this->safeTick();
                $this->stats->recordTick($result);

                if ($result->isIdle()) {
                    // Nothing to do; back off so we don't hammer the DB.
                    $this->sleepMs($this->pollIntervalMs);
                } else {
                    // Yield briefly so we don't monopolize the loop on
                    // a hot queue. 1ms is enough for the signal
                    // handler to fire if there's a pending one.
                    $this->sleepMs(1);
                }
            }
        } finally {
            $this->running = false;
        }
    }

    /**
     * Synchronously request the loop to stop. Called by the signal
     * handler and by the supervisor.
     */
    public function requestStop(?int $signal = null): void
    {
        $this->stopRequested = true;
        if ($signal !== null) {
            $this->stopSignal = $signal;
        }
    }

    public function isStopRequested(): bool
    {
        return $this->stopRequested;
    }

    public function stats(): WorkerStats
    {
        return $this->stats;
    }

    /**
     * Operator-visible pause check. Re-evaluated every tick; an
     * operator can pause/resume without restarting the daemon by
     * touching/removing the file.
     */
    public function isPaused(): bool
    {
        if ($this->pauseFile === '') {
            return false;
        }
        // clearstatcache so we don't see a stale "doesn't exist" after
        // an operator touches the file mid-loop.
        clearstatcache(true, $this->pauseFile);
        return file_exists($this->pauseFile);
    }

    /**
     * Tick the worker, swallowing exceptions so one bad job never
     * crashes the daemon. The exception is captured in stderr (which
     * systemd routes to journald); the supervisor reads stats to
     * decide if too many consecutive failures warrant a restart.
     */
    private function safeTick(): JobClaimResult
    {
        try {
            return $this->worker->tickOnce();
        } catch (\Throwable $e) {
            // The supervisor counts these too; we surface them on
            // stderr for journalctl visibility.
            fwrite(STDERR, '[worker-daemon] uncaught throwable: '
                . $e::class . ': ' . $e->getMessage() . PHP_EOL);
            // Treat as a rejected outcome so the supervisor's tick
            // count still advances; the job row is unaffected unless
            // the worker itself caught and persisted before throwing.
            return JobClaimResult::empty();
        }
    }

    private function sleepMs(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        // usleep takes microseconds; 1ms = 1000us.
        usleep($ms * 1000);
    }

    /**
     * Install async signal handlers exactly once. Idempotent so the
     * supervisor can call run() repeatedly without re-registering.
     *
     * Async signals (pcntl_async_signals(true)) require PHP 7.1+ and
     * deliver signals between opcodes rather than only on explicit
     * pcntl_signal_dispatch() calls. That removes a class of
     * "daemon ignores SIGTERM while inside a long syscall" bugs.
     */
    private function maybeInstallSignalHandlers(): void
    {
        if (!$this->installSignalHandlers) {
            return;
        }
        if (!function_exists('pcntl_async_signals')) {
            // No pcntl - daemon will still exit on a hard signal, just
            // less gracefully. Common in non-pcntl PHP builds.
            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signo): void {
            $this->requestStop($signo);
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        pcntl_signal(SIGHUP, function (int $signo): void {
            fwrite(STDERR, "[worker-daemon] SIGHUP received (reload not implemented yet)\n");
        });
        pcntl_signal(SIGUSR1, function (int $signo): void {
            fwrite(STDERR, '[worker-daemon] stats: ' . $this->stats->toLine() . PHP_EOL);
        });
    }
}
