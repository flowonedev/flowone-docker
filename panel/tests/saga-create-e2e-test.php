#!/usr/bin/env php
<?php
/**
 * Saga Orchestrator :: End-to-End (real OLS adapter, sandboxed)
 *
 * Runs the SagaOrchestrator against REAL Step 4a classes that drive
 * REAL adapters. We confine the blast radius to the StepTestContext
 * sandbox - no live OLS, no real SFTP, no real DB.
 *
 * Coverage:
 *   - 2-step OLS-only saga (VhostConfigWrite -> OlsMainConfigInsert)
 *     succeeds; files are written into the sandbox.
 *   - Idempotent re-run: running the same saga twice produces the same
 *     terminal state, with every step SKIPPED on the second run.
 *   - Failure injection: when a poisoned step is appended at the end,
 *     compensation walks back and the OLS files are restored to their
 *     pre-saga state.
 *   - DEGRADE_ONLY barrier: a degrading poison step preserves prior
 *     SAFE_ROLLBACK side effects (vhost.conf stays on disk; main config
 *     keeps the virtualhost block).
 *
 * What's NOT covered here (covered by the existing step suites):
 *   - SFTP groupadd / useradd (needs root)
 *   - MySQL CREATE DATABASE etc. (needs admin grants)
 *   - OlsRestartStep / lswsctrl restart (gated by --with-restart on the
 *     existing provisioning-steps-ols-test; calling lswsctrl here would
 *     restart the live OLS on the test server, so it's deliberately
 *     omitted from the orchestrator e2e saga)
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/saga-create-e2e-test.php --verbose
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
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemorySagaEventSink;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemoryStepStateStore;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOrchestrator;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOutcome;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsMainConfigInsertStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpGroupCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpUserCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\VhostConfigWriteStep;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepInterface;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SagaCreateE2E', $opts);

// Track sandboxes so we can tear them down even on SIGINT.
$activeSandboxes = [];
$harness->onCleanup(function () use (&$activeSandboxes) {
    foreach ($activeSandboxes as $bundle) {
        StepTestContext::teardown($bundle);
    }
});

// ───────────────────────────────────────────────────────────────────
// Helpers
// ───────────────────────────────────────────────────────────────────

/**
 * Build a fresh sandbox + the SiteContext that the steps will run in.
 * Stuffs site_user / php_lsapi / etc. into siteRow so VhostConfigWriteStep
 * has the variables it needs.
 */
function buildBundle(array &$activeSandboxes): array
{
    $domain = 'flowone_test_orch_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.local';
    $user = 'site_' . str_replace('.', '_', $domain);
    if (strlen($user) > 31) {
        $user = substr($user, 0, 25) . substr(hash('sha1', $user), 0, 6);
    }
    $bundle = StepTestContext::build([
        'domain' => $domain,
        'site_row_overrides' => [
            'sftp_user' => $user,
            'sftp_group' => $user,
            'php_lsapi' => 'lsphp83',
            'admin_email' => 'admin@example.com',
            'home_dir' => '/home/' . $user,
        ],
    ]);
    $activeSandboxes[] = $bundle;
    return $bundle;
}

function makeOlsSubsetSaga(): SagaSequence
{
    // We deliberately omit OlsRestartStep here: in no-coordinator mode
    // it would call lswsctrl on the live OLS install. The existing
    // provisioning-steps-ols-test exercises that path under
    // --with-restart.
    return new SagaSequence('ols-subset-create', [
        new VhostConfigWriteStep(new VhostConfigTemplate()),
        new OlsMainConfigInsertStep(),
    ]);
}

/**
 * A poison step that always fails on execute(). Used to trigger
 * compensation across the real OLS steps without needing actual
 * environment failures.
 */
final class PoisonStep implements StepInterface
{
    public function name(): string
    {
        return 'poison_step';
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        return false;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::failure($state, 'poison step always fails (compensation trigger)');
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success($state);
    }

    public function verify(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success($state);
    }
}

function vhostPath(array $bundle): string
{
    $domain = $bundle['ctx']->domain();
    return $bundle['ols_config_root'] . '/vhosts/' . $domain . '/vhost.conf';
}

// ───────────────────────────────────────────────────────────────────
// preflight
// ───────────────────────────────────────────────────────────────────

$harness->test('preflight', 'sandbox can be created and OLS subset saga can be constructed',
    function () use (&$activeSandboxes) {
        $bundle = buildBundle($activeSandboxes);
        if (!is_dir($bundle['ols_config_root'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'sandbox ols_config_root missing'];
        }
        $seq = makeOlsSubsetSaga();
        if ($seq->count() !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'subset saga should have 2 steps, got ' . $seq->count()];
        }
    });

// ───────────────────────────────────────────────────────────────────
// happy path
// ───────────────────────────────────────────────────────────────────

$harness->test('happy', 'orchestrator drives the 2-step OLS subset saga to SUCCEEDED',
    function () use (&$activeSandboxes) {
        $bundle = buildBundle($activeSandboxes);
        $store = new InMemoryStepStateStore();
        $sink = new InMemorySagaEventSink();
        $orch = new SagaOrchestrator($store, $sink);

        $result = $orch->run(makeOlsSubsetSaga(), $bundle['ctx']);

        if ($result->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->outcome->value
                    . ' (' . ($result->failureError ?? '') . ')'];
        }
        // vhost.conf must exist
        if (!file_exists(vhostPath($bundle))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost.conf was not written at ' . vhostPath($bundle)];
        }
        // httpd_config.conf must contain a virtualhost block for this domain
        $main = file_get_contents($bundle['main_config_path']);
        if (!str_contains($main, 'virtualhost ' . $bundle['ctx']->domain())) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'main config missing virtualhost block'];
        }
    });

$harness->test('happy', 'idempotent re-run reuses store and skips both steps via check()',
    function () use (&$activeSandboxes) {
        $bundle = buildBundle($activeSandboxes);

        // SHARED store across both runs - this mirrors production where
        // the state store is persistent. Some steps (e.g.
        // VhostConfigWriteStep) cache a content hash in StepState and
        // only consider themselves "already done" when that hash
        // matches the on-disk file. A re-run with a fresh store would
        // (correctly) re-execute the step.
        $store = new InMemoryStepStateStore();

        $sink1 = new InMemorySagaEventSink();
        $orch1 = new SagaOrchestrator($store, $sink1);
        $r1 = $orch1->run(makeOlsSubsetSaga(), $bundle['ctx']);
        if ($r1->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first run failed: ' . $r1->outcome->value];
        }

        // Second run with the SAME store. Every step's check() should
        // now report "already satisfied".
        $sink2 = new InMemorySagaEventSink();
        $orch2 = new SagaOrchestrator($store, $sink2);
        $r2 = $orch2->run(makeOlsSubsetSaga(), $bundle['ctx']);

        if ($r2->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second run not SUCCEEDED: ' . $r2->outcome->value];
        }

        foreach ($r2->stepRecords as $rec) {
            if (!$rec->wasCheckSatisfied) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "step '{$rec->stepName}' was NOT skipped on second run "
                        . "(state.data keys: " . implode(',', array_keys($rec->finalState->data)) . ")"];
            }
            if ($rec->outcome->value !== 'skipped') {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "step '{$rec->stepName}' outcome was '{$rec->outcome->value}', expected 'skipped'"];
            }
        }
    });

// ───────────────────────────────────────────────────────────────────
// rollback path (poison step at the end)
// ───────────────────────────────────────────────────────────────────

$harness->test('rollback', 'poison step triggers compensation; vhost + main config restored',
    function () use (&$activeSandboxes) {
        $bundle = buildBundle($activeSandboxes);
        $store = new InMemoryStepStateStore();
        $sink = new InMemorySagaEventSink();
        $orch = new SagaOrchestrator($store, $sink);

        $vhostBefore = file_exists(vhostPath($bundle));

        $seq = new SagaSequence('ols-subset-with-poison', [
            new VhostConfigWriteStep(new VhostConfigTemplate()),
            new OlsMainConfigInsertStep(),
            new PoisonStep(),
        ]);

        $result = $orch->run($seq, $bundle['ctx']);

        if ($result->outcome !== SagaOutcome::FAILED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILED, got ' . $result->outcome->value];
        }
        if ($result->failureStepName !== 'poison_step') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "failureStepName = '{$result->failureStepName}'"];
        }
        // vhost.conf should be cleaned up
        if (!$vhostBefore && file_exists(vhostPath($bundle))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost.conf was not cleaned up after rollback'];
        }
        // The virtualhost block + listener maps for this domain must
        // be GONE from the main config (the writer normalizes
        // whitespace so byte-equality vs pre-saga is too strict).
        $domain = $bundle['ctx']->domain();
        $mainAfter = (string) file_get_contents($bundle['main_config_path']);
        if (preg_match('/virtualhost\s+' . preg_quote($domain, '/') . '\s*\{/i', $mainAfter)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "vhost block for {$domain} still present after rollback"];
        }
        if (preg_match('/map\s+' . preg_quote($domain, '/') . '\s+/', $mainAfter)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "listener map for {$domain} still present after rollback"];
        }
    });

// ───────────────────────────────────────────────────────────────────
// skip_sftp end-to-end
//
// Operator unchecked "Create SFTP user" in CreateSiteV2Modal. The
// saga must:
//   - record SKIPPED for SftpGroupCreateStep + SftpUserCreateStep
//     (check() short-circuits via the payload guard)
//   - still succeed for VhostConfigWriteStep, using the
//     www-data:www-data fallback for site_user/site_group instead
//     of throwing "cannot determine site_user"
// ───────────────────────────────────────────────────────────────────

$harness->test('skip_sftp', 'orchestrator skips SFTP steps and Vhost falls back to www-data',
    function () use (&$activeSandboxes) {
        $domain = 'flowone_test_skip_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.local';
        // No sftp_user / sftp_group in siteRow on purpose - this is
        // exactly the shape ProvisioningAction passes when the modal
        // sets skip_sftp=true.
        $bundle = StepTestContext::build([
            'domain' => $domain,
            'payload' => ['skip_sftp' => true],
            'site_row_overrides' => [
                'php_lsapi' => 'lsphp83',
                'admin_email' => 'admin@example.com',
                'home_dir' => '/home/' . $domain,
            ],
        ]);
        $activeSandboxes[] = $bundle;

        $store = new InMemoryStepStateStore();
        $sink = new InMemorySagaEventSink();
        $orch = new SagaOrchestrator($store, $sink);

        $seq = new SagaSequence('skip-sftp-subset', [
            new SftpGroupCreateStep(),
            new SftpUserCreateStep(),
            new VhostConfigWriteStep(new VhostConfigTemplate()),
        ]);

        $result = $orch->run($seq, $bundle['ctx']);

        if ($result->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->outcome->value
                    . ' (' . ($result->failureError ?? '') . ')'
                    . ' on step ' . ($result->failureStepName ?? '?')];
        }

        $outcomes = [];
        foreach ($result->stepRecords as $rec) {
            $outcomes[$rec->stepName] = $rec->outcome->value;
        }

        if (($outcomes['sftp_group_create'] ?? null) !== 'skipped') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'sftp_group_create should be SKIPPED, got '
                    . ($outcomes['sftp_group_create'] ?? '<missing>')];
        }
        if (($outcomes['sftp_user_create'] ?? null) !== 'skipped') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'sftp_user_create should be SKIPPED, got '
                    . ($outcomes['sftp_user_create'] ?? '<missing>')];
        }
        // Vhost outcome is "success" (StepOutcome::SUCCESS->value).
        if (($outcomes['vhost_config_write'] ?? null) !== 'success') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost_config_write should be SUCCESS, got '
                    . ($outcomes['vhost_config_write'] ?? '<missing>')];
        }

        // Verify the rendered vhost.conf actually used the fallback
        // owner - this proves the resolveOwnerSpec/collectTemplateVars
        // guard wired through end-to-end.
        $content = (string) @file_get_contents(vhostPath($bundle));
        if ($content === '') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost.conf not written under skip_sftp'];
        }
        if (!str_contains($content, 'www-data')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost.conf does not reference www-data fallback owner'];
        }
    });

$harness->test('rollback', 'no rollback when only SAFE_ROLLBACK steps complete + a DEGRADE_ONLY at end fails',
    function () use (&$activeSandboxes) {
        // Synthesize a DEGRADE_ONLY poison step to prove the
        // orchestrator does NOT walk back SAFE_ROLLBACK predecessors
        // once the failing step is DEGRADE_ONLY.
        $bundle = buildBundle($activeSandboxes);
        $store = new InMemoryStepStateStore();
        $sink = new InMemorySagaEventSink();
        $orch = new SagaOrchestrator($store, $sink);

        $degradePoison = new class () implements StepInterface {
            public function name(): string
            {
                return 'degrade_poison';
            }
            public function compensationPolicy(): CompensationPolicy
            {
                return CompensationPolicy::DEGRADE_ONLY;
            }
            public function schemaVersion(): int
            {
                return 1;
            }
            public function check(SiteContext $ctx, StepState $state): bool
            {
                return false;
            }
            public function execute(SiteContext $ctx, StepState $state): StepResult
            {
                return StepResult::failure($state, 'simulated degrade-only failure');
            }
            public function compensate(SiteContext $ctx, StepState $state): StepResult
            {
                throw new \LogicException('DEGRADE_ONLY step should NEVER be compensated');
            }
            public function verify(SiteContext $ctx, StepState $state): StepResult
            {
                return StepResult::success($state);
            }
        };

        $seq = new SagaSequence('ols-with-degrade-failure', [
            new VhostConfigWriteStep(new VhostConfigTemplate()),
            new OlsMainConfigInsertStep(),
            $degradePoison,
        ]);

        $result = $orch->run($seq, $bundle['ctx']);

        if ($result->outcome !== SagaOutcome::DEGRADED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected DEGRADED, got ' . $result->outcome->value];
        }
        // vhost.conf must be PRESERVED (not compensated)
        if (!file_exists(vhostPath($bundle))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost.conf was compensated; site is degraded and should be preserved'];
        }
        // main config must STILL contain the inserted block
        $main = file_get_contents($bundle['main_config_path']);
        if (!str_contains($main, 'virtualhost ' . $bundle['ctx']->domain())) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'main config block was rolled back; should be preserved on DEGRADED'];
        }
    });

exit($harness->run());
