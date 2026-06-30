#!/usr/bin/env php
<?php
/**
 * Saga Orchestrator :: SUSPEND / RESUME end-to-end (Step 4c)
 *
 * Drives the orchestrator across the SUSPEND saga (VhostSuspendStep)
 * and the RESUME saga (VhostResumeStep) against a sandboxed OLS
 * config tree. We deliberately omit OlsRestartStep from the test
 * sequences because the OLS restart coordinator's fallback path
 * shells out to lswsctrl which would touch the live server's OLS
 * install.
 *
 * Coverage:
 *   - SUSPEND happy path: orchestrator drives one-step saga to
 *     SUCCEEDED, vhost.conf rewritten + backup created.
 *   - RESUME happy path on a freshly-suspended sandbox: original
 *     vhost.conf restored byte-for-byte, backup removed.
 *   - Idempotent re-run of SUSPEND: second invocation reports the
 *     step SKIPPED (check() returned true) and leaves on-disk state
 *     unchanged.
 *   - Failure path: SUSPEND on a domain whose vhost.conf was never
 *     written results in FAILED outcome and no half-suspended state.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/saga-lifecycle-e2e-test.php --verbose
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
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Orchestrator\InMemorySagaEventSink;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemoryStepStateStore;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOrchestrator;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOutcome;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\Steps\Suspend\VhostResumeStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Suspend\VhostSuspendStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SagaLifecycleE2E', $opts);

$sandboxes = [];
$harness->onCleanup(function () use (&$sandboxes) {
    foreach ($sandboxes as $b) {
        StepTestContext::teardown($b);
    }
});

$buildBundle = function (array $payload = []) use (&$sandboxes): array {
    $domain = 'flowone_test_susp_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.local';
    $b = StepTestContext::build([
        'domain' => $domain,
        'site_row_overrides' => ['home_dir' => '/tmp/' . $domain],
    ]);
    $sandboxes[] = $b;

    // Seed a real vhost.conf so suspend has something to swap.
    $b['ols']->writeVhostConfig($domain,
        "docRoot /home/{$domain}/public_html\n"
        . "php_admin_value open_basedir /home/{$domain}\n"
    );

    $ctx = new SiteContext(
        siteRow: $b['ctx']->siteRow,
        jobId: $b['ctx']->jobId,
        requestId: $b['ctx']->requestId,
        actor: $b['ctx']->actor,
        audit: $b['ctx']->audit,
        vault: $b['ctx']->vault,
        capabilities: $b['ctx']->capabilities,
        database: $b['ctx']->database,
        payload: $payload,
        dryRun: false,
        adapters: $b['ctx']->adapters,
    );
    return ['bundle' => $b, 'ctx' => $ctx];
};

function suspendSaga(): SagaSequence
{
    return new SagaSequence('suspend-only', [new VhostSuspendStep()]);
}

function resumeSaga(): SagaSequence
{
    return new SagaSequence('resume-only', [new VhostResumeStep()]);
}

// ───────────────────────────────────────────────────────────────────
// SUSPEND
// ───────────────────────────────────────────────────────────────────

$harness->test('suspend', 'orchestrator drives SUSPEND to SUCCEEDED, backup created',
    function () use ($buildBundle) {
        $built = $buildBundle(['suspend_message' => 'down for maintenance']);
        $b = $built['bundle'];
        $domain = $built['ctx']->domain();
        $live = $b['ols']->vhostConfigPath($domain);
        $backup = $live . '.suspended-backup';

        $store = new InMemoryStepStateStore();
        $sink = new InMemorySagaEventSink();
        $orch = new SagaOrchestrator($store, $sink);
        $result = $orch->run(suspendSaga(), $built['ctx']);

        if ($result->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->outcome->value
                    . ' (' . ($result->failureError ?? '') . ')'];
        }
        if (!is_file($backup)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'backup file not created at ' . $backup];
        }
        $liveContent = file_get_contents($live);
        if (!is_string($liveContent) || !str_contains($liveContent, 'flowone:suspended=true')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'live config does not contain suspended marker'];
        }
    });

$harness->test('suspend', 'idempotent re-run reports SKIPPED with same on-disk state',
    function () use ($buildBundle) {
        $built = $buildBundle();
        $b = $built['bundle'];
        $live = $b['ols']->vhostConfigPath($built['ctx']->domain());
        $backup = $live . '.suspended-backup';

        $store = new InMemoryStepStateStore();
        $sink = new InMemorySagaEventSink();
        $orch = new SagaOrchestrator($store, $sink);

        // First run.
        $first = $orch->run(suspendSaga(), $built['ctx']);
        if ($first->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'first run not SUCCEEDED'];
        }
        $firstLiveBytes = (string) file_get_contents($live);
        $firstBackupBytes = (string) file_get_contents($backup);

        // Fresh orchestrator + fresh store so we exercise check() again.
        $store2 = new InMemoryStepStateStore();
        $sink2 = new InMemorySagaEventSink();
        $orch2 = new SagaOrchestrator($store2, $sink2);
        $second = $orch2->run(suspendSaga(), $built['ctx']);

        if ($second->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second run not SUCCEEDED: ' . ($second->failureError ?? '')];
        }
        $outcomes = [];
        $skipped = 0;
        foreach ($second->stepRecords as $stepRecord) {
            $outcomes[] = $stepRecord->stepName . '=' . $stepRecord->outcome->value;
            if ($stepRecord->outcome === StepOutcome::SKIPPED) {
                $skipped++;
            }
        }
        if ($skipped !== 1) {
            $liveNow = (string) file_get_contents($live);
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected the lone step to be SKIPPED on the re-run, got '
                    . $skipped
                    . '; step outcomes=[' . implode(',', $outcomes) . ']'
                    . '; backup_exists=' . (file_exists($backup) ? 'yes' : 'no')
                    . '; live_has_marker=' . (str_contains($liveNow, 'flowone:suspended=true') ? 'yes' : 'no')
                    . '; live_size=' . strlen($liveNow)];
        }
        // On-disk state must be identical to after the first run.
        if ((string) file_get_contents($live) !== $firstLiveBytes) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'live config changed on re-run'];
        }
        if ((string) file_get_contents($backup) !== $firstBackupBytes) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'backup content changed on re-run'];
        }
    });

// ───────────────────────────────────────────────────────────────────
// RESUME
// ───────────────────────────────────────────────────────────────────

$harness->test('resume', 'orchestrator drives RESUME after SUSPEND, restoring bytes',
    function () use ($buildBundle) {
        $built = $buildBundle();
        $b = $built['bundle'];
        $live = $b['ols']->vhostConfigPath($built['ctx']->domain());
        $originalBytes = (string) file_get_contents($live);

        // Phase 1: SUSPEND.
        $orch = new SagaOrchestrator(new InMemoryStepStateStore(), new InMemorySagaEventSink());
        $sRes = $orch->run(suspendSaga(), $built['ctx']);
        if ($sRes->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'pre: suspend did not SUCCEED'];
        }

        // Phase 2: RESUME via a separate orchestrator instance.
        $orch2 = new SagaOrchestrator(new InMemoryStepStateStore(), new InMemorySagaEventSink());
        $rRes = $orch2->run(resumeSaga(), $built['ctx']);
        if ($rRes->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'resume did not SUCCEED: ' . ($rRes->failureError ?? '')];
        }
        $restored = (string) file_get_contents($live);
        if ($restored !== $originalBytes) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'live config bytes do not match original'];
        }
        if (file_exists($live . '.suspended-backup')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'backup file should be deleted'];
        }
    });

// ───────────────────────────────────────────────────────────────────
// failure path
// ───────────────────────────────────────────────────────────────────

$harness->test('failure', 'SUSPEND on a domain without a vhost.conf yields FAILED',
    function () use (&$sandboxes) {
        $domain = 'flowone_test_no_vhost_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.local';
        $b = StepTestContext::build(['domain' => $domain]);
        $sandboxes[] = $b;
        // Deliberately DO NOT write a vhost.conf.

        $ctx = new SiteContext(
            siteRow: $b['ctx']->siteRow,
            jobId: $b['ctx']->jobId,
            requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor,
            audit: $b['ctx']->audit,
            vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities,
            database: $b['ctx']->database,
            payload: [],
            dryRun: false,
            adapters: $b['ctx']->adapters,
        );

        $orch = new SagaOrchestrator(new InMemoryStepStateStore(), new InMemorySagaEventSink());
        $result = $orch->run(suspendSaga(), $ctx);

        if ($result->outcome === SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected non-success outcome; got SUCCEEDED'];
        }
        // After a failure of the only step, there should be no
        // half-suspended state on disk (no backup written).
        $live = $b['ols']->vhostConfigPath($domain);
        if (file_exists($live . '.suspended-backup')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'partial backup left behind'];
        }
    });

exit($harness->run());
