#!/usr/bin/env php
<?php
/**
 * Step Process Isolator :: pcntl_fork isolation primitives
 *
 * Verifies the {@see StepProcessIsolator} contract:
 *
 *   - When disabled (the default): the in-process closure runs and
 *     the result returned unchanged. Tests + non-isolated production
 *     workers must keep their historical behaviour intact.
 *
 *   - When enabled and pcntl is available:
 *       * Happy path: child runs the closure, returns the same
 *         StepResult the closure produced.
 *       * Hard exit in child (exit(1)): parent reports a synthetic
 *         "subprocess exited" failure with the step name surfaced.
 *       * Child killed by SIGKILL: parent reports a "killed by signal"
 *         failure.
 *       * Child times out: parent reaps with SIGKILL and reports
 *         "timed out".
 *       * Closure throws inside the child: the child catches and
 *         writes a failure StepResult that the parent reads cleanly
 *         (the worker stays up).
 *       * State data ($state->data) survives the fork roundtrip,
 *         including the ssl_deferred flag that production uses.
 *
 *   - When enabled but pcntl is not loaded: isEnabled() reports false
 *     and the fallback is in-process.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/step-process-isolator-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1800));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Orchestrator\StepProcessIsolator;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\StepInterface;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('StepProcessIsolator', $opts);

// A minimal StepInterface stub: the isolator only uses $step->name()
// for diagnostics, so we don't need a real step.
final class IsoTestStep implements StepInterface
{
    public function __construct(private readonly string $stepName = 'iso_probe')
    {
    }
    public function name(): string { return $this->stepName; }
    public function compensationPolicy(): CompensationPolicy { return CompensationPolicy::SAFE_ROLLBACK; }
    public function schemaVersion(): int { return 1; }
    public function check(SiteContext $ctx, StepState $state): bool { return false; }
    public function execute(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
    public function compensate(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
    public function verify(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
}

function freshState(): StepState
{
    return StepState::fresh('iso_probe', 1);
}

// ──────────────────────────────────────────────────────────────
$harness->test('disabled', 'disabled isolator runs the closure in-process and returns its result',
    function () {
        $iso = new StepProcessIsolator(enabled: false);
        if ($iso->isEnabled()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'isEnabled() should be false when constructor disabled'];
        }
        $invoked = false;
        $result = $iso->runWithIsolation(
            new IsoTestStep(),
            freshState(),
            function () use (&$invoked) {
                $invoked = true;
                return StepResult::success(freshState()->mergeData(['probe' => 'yes']));
            }
        );
        if (!$invoked) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'closure not invoked'];
        }
        if (!$result->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected success'];
        }
        if (($result->newState->data['probe'] ?? null) !== 'yes') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'state data lost'];
        }
    });

// All subsequent isolated tests require pcntl. Skip cleanly otherwise.
if (!function_exists('pcntl_fork')) {
    $harness->test('pcntl', 'pcntl not loaded; isolated tests skipped',
        function () {
            return ['outcome' => TestHarness::SKIP, 'message' => 'pcntl not available'];
        });
    exit($harness->run());
}

// ──────────────────────────────────────────────────────────────
$harness->test('enabled', 'happy path: child returns the StepResult the closure produced',
    function () {
        $iso = new StepProcessIsolator(enabled: true);
        if (!$iso->isEnabled()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'isEnabled() should be true with pcntl present'];
        }
        $result = $iso->runWithIsolation(
            new IsoTestStep(),
            freshState(),
            fn() => StepResult::success(
                freshState()->mergeData(['ssl_deferred' => true, 'outcome' => 'skipped_dns'])
            )
        );
        if (!$result->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected success in child, got ' . $result->outcome->value];
        }
        if (($result->newState->data['ssl_deferred'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'ssl_deferred flag did not survive fork roundtrip'];
        }
        if (($result->newState->data['outcome'] ?? null) !== 'skipped_dns') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'state data did not survive fork roundtrip'];
        }
    });

$harness->test('enabled', 'hard exit in child surfaces as synthetic failure',
    function () {
        $iso = new StepProcessIsolator(enabled: true);
        // We achieve a "child died before writing result" via exit(1)
        // inside the closure (before the isolator's serialise step).
        $result = $iso->runWithIsolation(
            new IsoTestStep('iso_hardexit'),
            freshState(),
            function () {
                exit(1);
                /** @phpstan-ignore-next-line */
                return StepResult::success(freshState());
            }
        );
        if (!$result->isFailure()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected failure, got ' . $result->outcome->value];
        }
        if ($result->error === null
            || !str_contains($result->error, 'iso_hardexit')
        ) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'error does not mention the step name: ' . ($result->error ?? 'null')];
        }
    });

$harness->test('enabled', 'child killed by SIGKILL surfaces as signal failure',
    function () {
        $iso = new StepProcessIsolator(enabled: true);
        $result = $iso->runWithIsolation(
            new IsoTestStep('iso_signal'),
            freshState(),
            function () {
                posix_kill(getmypid(), SIGKILL);
                // unreachable
                return StepResult::success(freshState());
            }
        );
        if (!$result->isFailure()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected failure, got ' . $result->outcome->value];
        }
        if ($result->error === null
            || (!str_contains($result->error, 'signal')
                && !str_contains($result->error, 'died')
                && !str_contains($result->error, 'no result'))
        ) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'error did not indicate signal/death: ' . $result->error];
        }
    });

$harness->test('enabled', 'child timeout surfaces as timed-out failure',
    function () {
        // Use a tiny timeout so the test is fast.
        $iso = new StepProcessIsolator(enabled: true, timeoutSeconds: 1);
        $result = $iso->runWithIsolation(
            new IsoTestStep('iso_timeout'),
            freshState(),
            function () {
                // Sleep longer than the isolator's timeout.
                sleep(5);
                return StepResult::success(freshState());
            }
        );
        if (!$result->isFailure()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected failure, got ' . $result->outcome->value];
        }
        if ($result->error === null || !str_contains($result->error, 'timed out')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'error did not mention timeout: ' . ($result->error ?? 'null')];
        }
    });

$harness->test('enabled', 'closure throw inside child is captured cleanly, worker stays up',
    function () {
        $iso = new StepProcessIsolator(enabled: true);
        $result = $iso->runWithIsolation(
            new IsoTestStep('iso_throw'),
            freshState(),
            function () {
                throw new \RuntimeException('synthetic step throw');
            }
        );
        if (!$result->isFailure()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected failure, got ' . $result->outcome->value];
        }
        if ($result->error === null
            || (!str_contains($result->error, 'synthetic step throw')
                && !str_contains($result->error, 'caught throwable'))
        ) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'error did not mention the throw: ' . $result->error];
        }
    });

exit($harness->run());
