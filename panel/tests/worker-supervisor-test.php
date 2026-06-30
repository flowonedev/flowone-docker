#!/usr/bin/env php
<?php
/**
 * WorkerSupervisor :: factory rebuild + throttle
 *
 * Pure-unit tests against a recording ScriptedTicker factory. No DB,
 * no saga, no signal coupling unless explicitly tested.
 *
 * Coverage:
 *   - factory is invoked at least once on run.
 *   - after jobsPerWorker jobs touched, the factory is invoked again
 *     (fresh worker instance).
 *   - stop predicate breaks the supervisor between rotations cleanly.
 *   - rapid-restart streak counter increments + decrements correctly.
 *   - exceeding the rapid-restart ceiling returns exit code 2.
 *   - requestStop() halts the outer loop before the next rotation.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/worker-supervisor-test.php --verbose
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
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobTicker;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\WorkerSupervisor;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('WorkerSupervisor', $opts);

/**
 * Scripted JobTicker that returns N "processed" results then idle.
 * Tests use this to make the daemon's jobsTouched() climb predictably.
 */
final class CountingTicker implements JobTicker
{
    public int $callCount = 0;
    public function __construct(public readonly int $processedBudget = 100)
    {
    }
    public function tickOnce(): JobClaimResult
    {
        $this->callCount++;
        if ($this->callCount <= $this->processedBudget) {
            return new JobClaimResult(
                claimed: true,
                processed: true,
                job: null,
                terminalStatus: 'succeeded',
                note: 'counting',
                elapsedMs: 1,
            );
        }
        return JobClaimResult::empty();
    }
}

/**
 * Records how many times the factory was invoked. Each invocation
 * returns a fresh CountingTicker so the supervisor can drive it
 * through its jobsTouched() budget.
 */
function makeRecordingFactory(int $processedBudget, int &$factoryCalls): \Closure
{
    return function () use ($processedBudget, &$factoryCalls): CountingTicker {
        $factoryCalls++;
        return new CountingTicker($processedBudget);
    };
}

// ──────────────────────────────────────────────────────────────
// basics
// ──────────────────────────────────────────────────────────────

$harness->test('factory', 'factory invoked at least once on run',
    function () {
        $factoryCalls = 0;
        $factory = makeRecordingFactory(0, $factoryCalls); // ticker returns idle immediately

        $supervisor = new WorkerSupervisor(
            workerFactory: $factory,
            jobsPerWorker: 10,
            pollIntervalMs: 0,
            pauseFile: '',
            installSignalHandlers: false,
        );
        // The ticker returns idle immediately, so the daemon will NEVER
        // hit its job budget and recordRestart will NEVER fire. We have
        // to stop on factoryCalls instead — that flips the moment the
        // supervisor enters its outer loop body and invokes the factory.
        // MUST be a regular closure with use (&$factoryCalls) — PHP
        // arrow functions capture by value, which would freeze
        // factoryCalls at 0 and infinite-loop the daemon.
        $code = $supervisor->runUntil(function () use (&$factoryCalls): bool {
            return $factoryCalls >= 1;
        });
        if ($factoryCalls < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "factory never invoked"];
        }
        if ($code !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected exit code 0 on clean stop, got {$code}"];
        }
    });

$harness->test('factory', 'after jobsPerWorker jobs touched, factory is invoked again',
    function () {
        $factoryCalls = 0;
        // Each ticker can process 20 jobs - more than jobsPerWorker so
        // every rotation is driven by the budget, never by an idle queue.
        $factory = makeRecordingFactory(20, $factoryCalls);

        $jobsPerWorker = 5;
        $supervisor = new WorkerSupervisor(
            workerFactory: $factory,
            jobsPerWorker: $jobsPerWorker,
            pollIntervalMs: 0,
            pauseFile: '',
            installSignalHandlers: false,
        );
        // Stop after 3 restarts have been recorded. Both outer + inner
        // predicate calls see the same supervisor state, so this is
        // robust to the dual-evaluation.
        $supervisor->runUntil(fn() => $supervisor->totalRestarts() >= 3);

        if ($factoryCalls !== 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected exactly 3 factory calls; got {$factoryCalls}"];
        }
        if ($supervisor->totalRestarts() !== 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected exactly 3 restarts; got ' . $supervisor->totalRestarts()];
        }
    });

// ──────────────────────────────────────────────────────────────
// stop semantics
// ──────────────────────────────────────────────────────────────

$harness->test('stop', 'requestStop() halts the outer loop after current rotation',
    function () {
        $factoryCalls = 0;
        $factory = makeRecordingFactory(2, $factoryCalls);

        $supervisor = new WorkerSupervisor(
            workerFactory: $factory,
            jobsPerWorker: 1,
            pollIntervalMs: 0,
            pauseFile: '',
            installSignalHandlers: false,
        );

        // Trigger requestStop() as soon as the first restart is
        // recorded. After that, the supervisor's stopRequested flag
        // wins both at the outer loop AND inside the daemon's
        // predicate, so the loop never recurses for a 2nd factory call.
        $supervisor->runUntil(function () use ($supervisor): bool {
            if ($supervisor->totalRestarts() >= 1) {
                $supervisor->requestStop();
            }
            return false;
        });
        // Expected: factory invoked exactly once (the initial worker).
        // If requestStop didn't take effect, the loop would keep
        // rotating because jobsPerWorker=1 forces immediate rotation.
        if ($factoryCalls !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "stop did not halt; factory invoked {$factoryCalls}x (expected 1)"];
        }
    });

// ──────────────────────────────────────────────────────────────
// rapid restart ceiling
// ──────────────────────────────────────────────────────────────

$harness->test('throttle', 'exceeding rapid-restart ceiling returns exit code 2',
    function () {
        $factoryCalls = 0;
        // Each worker touches jobsPerWorker jobs immediately, forcing
        // a rotation per iter with effectively zero wallclock between
        // rotations.
        $factory = makeRecordingFactory(10, $factoryCalls);

        $supervisor = new WorkerSupervisor(
            workerFactory: $factory,
            jobsPerWorker: 1,
            maxRapidRestarts: 3,
            rapidRestartWindowSec: 5,
            pollIntervalMs: 0,
            pauseFile: '',
            installSignalHandlers: false,
        );

        $code = $supervisor->runUntil(fn() => false);

        if ($code !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected exit code 2, got {$code}"];
        }
        if ($supervisor->rapidRestartStreak() <= 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'rapid streak should have exceeded maxRapidRestarts; got '
                    . $supervisor->rapidRestartStreak()];
        }
    });

$harness->test('throttle', 'totalRestarts() counts every rotation',
    function () {
        $factoryCalls = 0;
        $factory = makeRecordingFactory(2, $factoryCalls);
        $supervisor = new WorkerSupervisor(
            workerFactory: $factory,
            jobsPerWorker: 1,
            maxRapidRestarts: 100, // large so we don't bail
            rapidRestartWindowSec: 1,
            pollIntervalMs: 0,
            pauseFile: '',
            installSignalHandlers: false,
        );

        $supervisor->runUntil(fn() => $supervisor->totalRestarts() >= 4);

        if ($supervisor->totalRestarts() !== 4) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected exactly 4 total restarts; got '
                    . $supervisor->totalRestarts()];
        }
        if ($factoryCalls !== 4) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 4 factory calls; got ' . $factoryCalls];
        }
    });

// ──────────────────────────────────────────────────────────────
// validation
// ──────────────────────────────────────────────────────────────

$harness->test('validation', 'jobsPerWorker < 1 throws',
    function () {
        try {
            new WorkerSupervisor(
                workerFactory: fn() => new CountingTicker(),
                jobsPerWorker: 0,
            );
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected InvalidArgumentException'];
        } catch (\InvalidArgumentException) {
            // expected
        }
    });

$harness->test('validation', 'maxRapidRestarts < 1 throws',
    function () {
        try {
            new WorkerSupervisor(
                workerFactory: fn() => new CountingTicker(),
                maxRapidRestarts: 0,
            );
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected InvalidArgumentException'];
        } catch (\InvalidArgumentException) {
            // expected
        }
    });

exit($harness->run());
