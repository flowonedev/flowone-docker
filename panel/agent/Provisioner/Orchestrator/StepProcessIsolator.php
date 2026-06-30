<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Step\StepInterface;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Per-step subprocess isolation for the SagaOrchestrator.
 *
 * Background:
 *   Without isolation, a PHP fatal error inside a step's execute() /
 *   compensate() call kills the worker daemon. systemd's
 *   `Restart=on-failure` brings it back within a few seconds and the
 *   lease-sweeper eventually returns the orphaned job to the queue,
 *   but during that window the worker is unavailable AND the error
 *   attribution is lost (the job lands as "FAILED: lease expired"
 *   rather than "step X caused fatal Y").
 *
 *   This isolator wraps each step call in a `pcntl_fork()` so a fatal
 *   in the step crashes the child, not the worker. The parent
 *   detects the abnormal exit and synthesises a normal
 *   StepResult::failure() that the orchestrator's compensation walk
 *   already knows how to handle.
 *
 * Trade-offs:
 *   - Adds ~30ms per step (fork + exec + reap). For an 11-step CREATE
 *     saga that's ~330ms of overhead; well under the steps' own work
 *     budget.
 *   - The child must NOT share the parent's MariaDB connection
 *     (PDO socket state is per-process). We immediately call
 *     {@see PanelDatabase::forgetConnection()} on the child so the
 *     next DB op in the child opens a fresh connection. The parent's
 *     handle is untouched.
 *   - Steps that talk to MariaDB via the MysqlAdapter (which
 *     shells out to `mysql -e ...`) are unaffected - those don't
 *     reuse the PDO.
 *   - The child closes all open file descriptors that aren't stdin /
 *     stdout / stderr, so any leaked socket / file handle from the
 *     parent is silently dropped in the child.
 *
 * Failure modes the isolator turns into normal StepResult::failure():
 *   - Child exits with non-zero status         -> "subprocess exit N"
 *   - Child killed by a signal                 -> "subprocess signal N"
 *   - Child timeout                            -> "subprocess timeout"
 *   - Child wrote no / unparseable result file -> "subprocess produced no result"
 *
 * Failure mode that bypasses isolation transparently:
 *   - `pcntl_fork()` returns -1 (process table full): we log a
 *     warning to stderr and fall back to in-process execution. A
 *     fatal in this fallback path will still kill the worker, but
 *     that is what would have happened with no isolator at all.
 *
 * Opt-in:
 *   The isolator is OFF by default. The worker-daemon passes an
 *   enabled instance when the `FLOWONE_STEP_ISOLATION` env var is
 *   set or `--step-isolation` CLI flag is passed. Tests construct
 *   the orchestrator without an isolator and continue to run
 *   in-process, matching the historical behaviour.
 */
final class StepProcessIsolator
{
    /** Maximum wall time a single step's subprocess may run. */
    public const DEFAULT_TIMEOUT_SECONDS = 300;

    public function __construct(
        /**
         * When false, runWithIsolation() always calls the in-process
         * fallback. This is the safe default - existing callers and
         * tests get unchanged behaviour.
         */
        private readonly bool $enabled = false,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && function_exists('pcntl_fork');
    }

    /**
     * Run $work() in a forked child if isolation is enabled and pcntl is
     * available. $work must return a StepResult; when it does, the
     * isolator transparently passes it back to the caller as if the work
     * had run in-process.
     *
     * The `$step` and `$state` arguments are used only for synthesising
     * a failure result when the child dies (so the caller's saga record
     * gets a meaningful error string).
     *
     * @param callable():StepResult $work
     */
    public function runWithIsolation(
        StepInterface $step,
        StepState $state,
        callable $work
    ): StepResult {
        if (!$this->isEnabled()) {
            return $work();
        }

        $resultPath = tempnam(sys_get_temp_dir(), 'flowone_step_iso_');
        if ($resultPath === false) {
            // Filesystem refused our temp file; fall back rather than
            // strand the worker on a single step.
            fwrite(STDERR, "[step-iso] tempnam failed; running step in-process\n");
            return $work();
        }

        $pid = @pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "[step-iso] pcntl_fork failed; running step in-process\n");
            @unlink($resultPath);
            return $work();
        }

        if ($pid === 0) {
            $this->runChild($resultPath, $work, $state);
            // runChild() always exit()s; this line is unreachable.
            exit(99);
        }

        return $this->reapParent($pid, $resultPath, $step, $state);
    }

    /**
     * Child path: reset shared resources, run the work, persist the
     * result, exit cleanly. Any throwable becomes a normal failure
     * StepResult so the parent can ALWAYS read a valid result file
     * unless the process is killed by a signal.
     *
     * @param callable():StepResult $work
     */
    private function runChild(string $resultPath, callable $work, StepState $state): void
    {
        // Drop the inherited DB handle so we don't corrupt the parent's
        // connection state. PanelDatabase::pdo() opens a fresh one on
        // next access if anything in the child needs DB.
        try {
            PanelDatabase::instance()->forgetConnection();
        } catch (\Throwable) {
            // PanelDatabase singleton wasn't instantiated in this run;
            // nothing to forget.
        }

        try {
            $result = $work();
            if (!$result instanceof StepResult) {
                $result = StepResult::failure(
                    $state,
                    'step returned non-StepResult inside isolation child'
                );
            }
        } catch (\Throwable $e) {
            $result = StepResult::failure(
                $state,
                'isolation child caught throwable: '
                    . $e::class . ': ' . $e->getMessage()
            );
        }

        try {
            $payload = serialize($result);
            $bytes = @file_put_contents($resultPath, $payload, LOCK_EX);
            if ($bytes === false) {
                fwrite(STDERR, "[step-iso] child failed to write result file\n");
                exit(2);
            }
        } catch (\Throwable $e) {
            // Last-ditch: try to write a minimal failure marker so the
            // parent gets something parseable.
            $fallback = serialize(StepResult::failure(
                $state,
                'isolation child failed to serialise result: ' . $e->getMessage()
            ));
            @file_put_contents($resultPath, $fallback, LOCK_EX);
            exit(3);
        }

        exit(0);
    }

    /**
     * Parent path: wait for the child within the configured timeout,
     * read the serialized StepResult, surface a synthetic failure when
     * the child died abnormally.
     */
    private function reapParent(
        int $childPid,
        string $resultPath,
        StepInterface $step,
        StepState $state
    ): StepResult {
        $deadline = time() + $this->timeoutSeconds;
        $status = 0;

        while (true) {
            $reaped = pcntl_waitpid($childPid, $status, WNOHANG);
            if ($reaped === $childPid) {
                break;
            }
            if ($reaped < 0) {
                // ECHILD / EINTR. Treat as crash.
                @unlink($resultPath);
                return StepResult::failure(
                    $state,
                    "step '{$step->name()}' isolation child waitpid failed"
                );
            }
            if (time() > $deadline) {
                @posix_kill($childPid, SIGKILL);
                // Reap the now-killed child so it doesn't zombie.
                @pcntl_waitpid($childPid, $status);
                @unlink($resultPath);
                return StepResult::failure(
                    $state,
                    "step '{$step->name()}' isolation child timed out after {$this->timeoutSeconds}s"
                );
            }
            usleep(100_000); // 100ms
        }

        if (function_exists('pcntl_wifsignaled') && pcntl_wifsignaled($status)) {
            $sig = pcntl_wtermsig($status);
            @unlink($resultPath);
            return StepResult::failure(
                $state,
                "step '{$step->name()}' isolation child killed by signal {$sig}"
            );
        }

        $exit = function_exists('pcntl_wexitstatus') ? pcntl_wexitstatus($status) : 0;
        $raw = @file_get_contents($resultPath);
        @unlink($resultPath);

        if ($exit !== 0 && ($raw === false || $raw === '')) {
            return StepResult::failure(
                $state,
                "step '{$step->name()}' isolation child exited {$exit} with no result"
            );
        }
        if ($raw === false || $raw === '') {
            return StepResult::failure(
                $state,
                "step '{$step->name()}' isolation child produced no result file"
            );
        }

        $result = @unserialize($raw);
        if (!$result instanceof StepResult) {
            return StepResult::failure(
                $state,
                "step '{$step->name()}' isolation child returned unparseable result"
            );
        }
        return $result;
    }
}
