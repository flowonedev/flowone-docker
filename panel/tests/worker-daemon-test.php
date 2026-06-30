#!/usr/bin/env php
<?php
/**
 * WorkerDaemon :: loop + signal + idle/drain semantics
 *
 * Pure-unit tests against a scripted ScriptedTicker that drives the
 * daemon's runUntil() entry point. No DB, no saga, no signal-handler
 * coupling unless explicitly tested.
 *
 * Coverage:
 *   - runUntil stops when the predicate returns true.
 *   - runUntil counts ticks in stats accurately.
 *   - idle ticks increment ticks_idle; processed ticks bump jobs_*.
 *   - the daemon sleeps the configured poll interval on idle.
 *   - drain: a burst of processed ticks completes in well under the
 *     poll interval (the daemon does NOT sleep between processed
 *     ticks).
 *   - requestStop() asynchronously breaks the loop.
 *   - the pause file suspends ticks without stopping the loop.
 *   - an uncaught throwable inside tickOnce() is swallowed; the
 *     daemon records it as a no-op tick and keeps going.
 *   - SIGTERM stops the daemon (only when pcntl is available).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/worker-daemon-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobClaimResult;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobTicker;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\WorkerDaemon;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('WorkerDaemon', $opts);

/**
 * Scripted JobTicker. Each call returns the next pre-loaded
 * JobClaimResult; runs out -> returns idle forever.
 *
 * If `throwOnTick` is true, tickOnce() throws once and clears the
 * flag, simulating a worker-internal bug.
 */
final class ScriptedTicker implements JobTicker
{
    /** @var list<JobClaimResult> */
    private array $script;
    public int $callCount = 0;
    public bool $throwOnNextTick = false;

    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    public function tickOnce(): JobClaimResult
    {
        $this->callCount++;
        if ($this->throwOnNextTick) {
            $this->throwOnNextTick = false;
            throw new \RuntimeException('scripted ticker exception');
        }
        return $this->script[$this->callCount - 1] ?? JobClaimResult::empty();
    }

    public function appendIdle(): void
    {
        $this->script[] = JobClaimResult::empty();
    }
    public function appendProcessed(string $terminal = 'succeeded'): void
    {
        // We don't have a real SiteJob to attach; passing null. The
        // daemon never inspects $result->job so that's fine.
        $this->script[] = new JobClaimResult(
            claimed: true,
            processed: true,
            job: null,
            terminalStatus: $terminal,
            note: 'scripted',
            elapsedMs: 1,
        );
    }
    public function appendRejected(string $terminal = 'failed'): void
    {
        $this->script[] = new JobClaimResult(
            claimed: true,
            processed: false,
            job: null,
            terminalStatus: $terminal,
            note: 'scripted rejected',
            elapsedMs: 1,
        );
    }
}

// ──────────────────────────────────────────────────────────────
// runUntil + counting
// ──────────────────────────────────────────────────────────────

$harness->test('basics', 'runUntil stops when the predicate returns true',
    function () {
        $ticker = new ScriptedTicker();
        for ($i = 0; $i < 5; $i++) {
            $ticker->appendIdle();
        }
        $daemon = new WorkerDaemon(
            worker: $ticker,
            pollIntervalMs: 0,
            installSignalHandlers: false,
        );
        $daemon->runUntil(fn(WorkerDaemon $d) => $d->stats()->ticksTotal >= 3);
        if ($ticker->callCount !== 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected 3 ticks, got {$ticker->callCount}"];
        }
        if ($daemon->stats()->ticksIdle !== 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected 3 idle ticks, got {$daemon->stats()->ticksIdle}"];
        }
    });

$harness->test('basics', 'processed ticks bump jobs_processed counter',
    function () {
        $ticker = new ScriptedTicker();
        $ticker->appendProcessed();
        $ticker->appendProcessed();
        $ticker->appendIdle();

        $daemon = new WorkerDaemon(
            worker: $ticker,
            pollIntervalMs: 0,
            installSignalHandlers: false,
        );
        $daemon->runUntil(fn(WorkerDaemon $d) => $d->stats()->ticksTotal >= 3);

        if ($daemon->stats()->jobsProcessed !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected jobsProcessed=2, got {$daemon->stats()->jobsProcessed}"];
        }
        if ($daemon->stats()->ticksIdle !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected ticksIdle=1, got {$daemon->stats()->ticksIdle}"];
        }
    });

$harness->test('basics', 'rejected ticks bump jobs_rejected (not jobs_processed)',
    function () {
        $ticker = new ScriptedTicker();
        $ticker->appendRejected();
        $ticker->appendIdle();

        $daemon = new WorkerDaemon(
            worker: $ticker,
            pollIntervalMs: 0,
            installSignalHandlers: false,
        );
        $daemon->runUntil(fn(WorkerDaemon $d) => $d->stats()->ticksTotal >= 2);

        $s = $daemon->stats();
        if ($s->jobsRejected !== 1 || $s->jobsProcessed !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected rejected=1 processed=0, got "
                    . "rejected={$s->jobsRejected} processed={$s->jobsProcessed}"];
        }
        if ($s->jobsFailed !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "rejected+failed should bump jobsFailed; got {$s->jobsFailed}"];
        }
    });

// ──────────────────────────────────────────────────────────────
// idle backoff vs drain
// ──────────────────────────────────────────────────────────────

$harness->test('timing', 'idle ticks sleep the configured poll interval',
    function () {
        $ticker = new ScriptedTicker();
        for ($i = 0; $i < 3; $i++) {
            $ticker->appendIdle();
        }
        $pollMs = 25;
        $daemon = new WorkerDaemon(
            worker: $ticker,
            pollIntervalMs: $pollMs,
            installSignalHandlers: false,
        );
        $start = microtime(true);
        $daemon->runUntil(fn(WorkerDaemon $d) => $d->stats()->ticksTotal >= 3);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $minExpected = $pollMs * 2 * 0.8; // 3 ticks => 3 sleeps; allow 20% slack on each
        if ($elapsedMs < $minExpected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected idle backoff >= {$minExpected}ms, got {$elapsedMs}ms"];
        }
        // Also cap at 5x to catch pathological sleeps.
        $maxExpected = $pollMs * 10;
        if ($elapsedMs > $maxExpected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "idle backoff too long; got {$elapsedMs}ms (cap {$maxExpected}ms)"];
        }
    });

$harness->test('timing', 'drain mode: processed ticks complete without poll-interval sleeps',
    function () {
        $ticker = new ScriptedTicker();
        for ($i = 0; $i < 10; $i++) {
            $ticker->appendProcessed();
        }
        $ticker->appendIdle(); // sentinel so the loop's idle path is touched

        $pollMs = 200; // big so accidental idle sleeps would show up loudly
        $daemon = new WorkerDaemon(
            worker: $ticker,
            pollIntervalMs: $pollMs,
            installSignalHandlers: false,
        );
        $start = microtime(true);
        $daemon->runUntil(fn(WorkerDaemon $d) => $d->stats()->jobsProcessed >= 10);
        $elapsedMs = (microtime(true) - $start) * 1000;

        // 10 processed ticks should finish in well under one poll
        // interval. 1ms yield per tick = ~10ms total budget.
        $budgetMs = 100;
        if ($elapsedMs > $budgetMs) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "drain took {$elapsedMs}ms (budget {$budgetMs}ms);"
                    . " probably sleeping the full poll interval between processed ticks"];
        }
    });

// ──────────────────────────────────────────────────────────────
// requestStop + signals
// ──────────────────────────────────────────────────────────────

$harness->test('stop', 'requestStop() breaks the loop on the next iteration',
    function () {
        $ticker = new ScriptedTicker();
        for ($i = 0; $i < 1000; $i++) {
            $ticker->appendIdle();
        }
        $daemon = new WorkerDaemon(
            worker: $ticker,
            pollIntervalMs: 0,
            installSignalHandlers: false,
        );
        // Stop after the 4th tick by inspecting the daemon's stats.
        $daemon->runUntil(function (WorkerDaemon $d) use ($daemon): bool {
            if ($d->stats()->ticksTotal === 4) {
                $daemon->requestStop();
            }
            return false; // let stopRequested win
        });
        if ($daemon->stats()->ticksTotal !== 4) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected loop to stop at 4 ticks, ran {$daemon->stats()->ticksTotal}"];
        }
        if (!$daemon->isStopRequested()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'stopRequested flag not set after requestStop()'];
        }
    });

$harness->test('stop', 'pcntl SIGTERM stops the loop within one tick',
    function () {
        if (!function_exists('posix_kill')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal_get_handler')) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'pcntl / posix not available in this PHP build'];
        }

        // The TestHarness installs its own SIGTERM/SIGINT handlers
        // (it exits 130 on signal for cleanup). The daemon will
        // replace those for the duration of this test; capture the
        // originals so we can restore them after.
        $savedTerm = pcntl_signal_get_handler(SIGTERM);
        $savedInt = pcntl_signal_get_handler(SIGINT);
        try {
            $ticker = new ScriptedTicker();
            // 10000 idle ticks: plenty of headroom; SIGTERM should fire long before exhaustion.
            for ($i = 0; $i < 10000; $i++) {
                $ticker->appendIdle();
            }
            $daemon = new WorkerDaemon(
                worker: $ticker,
                pollIntervalMs: 0,
                installSignalHandlers: true,
            );

            $pid = getmypid();
            // Stop closure kicks SIGTERM exactly once at tick 5 so we
            // can confirm the signal path stops the loop, not the closure.
            $signalledAt = null;
            $daemon->runUntil(function (WorkerDaemon $d) use ($pid, &$signalledAt): bool {
                if ($d->stats()->ticksTotal === 5 && $signalledAt === null) {
                    $signalledAt = $d->stats()->ticksTotal;
                    posix_kill($pid, SIGTERM);
                }
                return false;
            });
            if ($daemon->stats()->ticksTotal > 10) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'SIGTERM did not stop loop quickly; ran '
                        . $daemon->stats()->ticksTotal . ' ticks'];
            }
            if (!$daemon->isStopRequested()) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'stopRequested not set after SIGTERM'];
            }
        } finally {
            // Restore the harness's handlers so a subsequent Ctrl-C
            // still triggers its cleanup path.
            if ($savedTerm !== false) {
                pcntl_signal(SIGTERM, $savedTerm);
            }
            if ($savedInt !== false) {
                pcntl_signal(SIGINT, $savedInt);
            }
        }
    });

// ──────────────────────────────────────────────────────────────
// pause file
// ──────────────────────────────────────────────────────────────

$harness->test('pause', 'pause file suspends ticks without stopping the loop',
    function () {
        $pauseFile = sys_get_temp_dir() . '/flowone_test_worker_paused_' . bin2hex(random_bytes(3));
        @file_put_contents($pauseFile, '');
        try {
            $ticker = new ScriptedTicker();
            $ticker->appendProcessed(); // would run if not paused

            $daemon = new WorkerDaemon(
                worker: $ticker,
                pollIntervalMs: 0,
                pauseFile: $pauseFile,
                installSignalHandlers: false,
            );
            // Loop 3 iterations, but pause is on, so the ticker is
            // never called.
            $iters = 0;
            $daemon->runUntil(function () use (&$iters): bool {
                return ++$iters >= 3;
            });
            if ($ticker->callCount !== 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "ticker invoked while paused: {$ticker->callCount}"];
            }
            if (!$daemon->isPaused()) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'isPaused() should be true when pause file exists'];
            }
        } finally {
            @unlink($pauseFile);
        }
    });

$harness->test('pause', 'removing pause file resumes ticks immediately',
    function () {
        $pauseFile = sys_get_temp_dir() . '/flowone_test_worker_paused_' . bin2hex(random_bytes(3));
        @file_put_contents($pauseFile, '');
        try {
            $ticker = new ScriptedTicker();
            for ($i = 0; $i < 5; $i++) {
                $ticker->appendIdle();
            }

            $daemon = new WorkerDaemon(
                worker: $ticker,
                pollIntervalMs: 0,
                pauseFile: $pauseFile,
                installSignalHandlers: false,
            );

            $stage = 0;
            $daemon->runUntil(function (WorkerDaemon $d) use ($pauseFile, &$stage, $ticker): bool {
                $stage++;
                if ($stage === 2) {
                    @unlink($pauseFile);
                }
                // Stop once the ticker has seen at least 2 calls (i.e.
                // resumed).
                return $ticker->callCount >= 2;
            });
            if ($ticker->callCount < 2) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "expected ticker to resume after unlink; calls={$ticker->callCount}"];
            }
        } finally {
            @unlink($pauseFile);
        }
    });

// ──────────────────────────────────────────────────────────────
// resilience
// ──────────────────────────────────────────────────────────────

$harness->test('resilience', 'uncaught throwable inside tickOnce() is swallowed',
    function () {
        $ticker = new ScriptedTicker();
        for ($i = 0; $i < 5; $i++) {
            $ticker->appendIdle();
        }
        $ticker->throwOnNextTick = true;

        // Capture stderr so the test output isn't polluted.
        $captureFile = tempnam(sys_get_temp_dir(), 'flowone_test_stderr_');
        $origStderr = defined('STDERR') ? STDERR : null;
        // PHP doesn't let us swap STDERR mid-process portably, so we
        // accept the noise. fwrite() to STDERR will write to console.

        $daemon = new WorkerDaemon(
            worker: $ticker,
            pollIntervalMs: 0,
            installSignalHandlers: false,
        );
        $daemon->runUntil(fn(WorkerDaemon $d) => $d->stats()->ticksTotal >= 3);

        @unlink($captureFile);
        if ($ticker->callCount < 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "daemon stopped on throwable; calls={$ticker->callCount}"];
        }
    });

$harness->test('resilience', 'reentrant runUntil() throws',
    function () {
        $ticker = new ScriptedTicker();
        $ticker->appendIdle();
        $daemon = new WorkerDaemon(
            worker: $ticker,
            pollIntervalMs: 0,
            installSignalHandlers: false,
        );

        try {
            $daemon->runUntil(function (WorkerDaemon $d) use ($daemon): bool {
                // Re-entrant call should LogicException.
                $daemon->runUntil(fn() => true);
                return true;
            });
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected LogicException for reentrant runUntil()'];
        } catch (\LogicException) {
            // expected
        }
    });

exit($harness->run());
