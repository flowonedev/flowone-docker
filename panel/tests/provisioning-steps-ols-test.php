#!/usr/bin/env php
<?php
/**
 * Provisioning Steps :: OLS Lifecycle Tests
 *
 * Exercises the full check/execute/check/compensate cycle for the
 * OLS-touching steps:
 *
 *   - VhostConfigWriteStep
 *   - OlsMainConfigInsertStep
 *   - OlsRestartStep (no-coordinator fallback path)
 *
 * All writes happen against a sandboxed ols/ tree under /tmp - the
 * live OpenLiteSpeed install is untouched. The OlsRestartStep test
 * does NOT actually issue lswsctrl unless --with-restart is passed.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-steps-ols-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help', 'with-restart']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsMainConfigInsertStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsRestartStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\VhostConfigWriteStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ProvisioningStepsOls', $opts);

// Track sandbox roots for cleanup.
$sandboxes = [];
$harness->onCleanup(function () use (&$sandboxes) {
    foreach ($sandboxes as $b) {
        StepTestContext::teardown($b);
    }
});

// ── VhostConfigWriteStep lifecycle ────────────────────────────
$harness->test('vhost_config', 'check is false on fresh sandbox',
    function () use (&$sandboxes) {
        $b = StepTestContext::build(['payload' => [
            'sftp_user' => 'site_test_user',
            'sftp_group' => 'site_test_group',
        ]]);
        $sandboxes[] = $b;
        $step = new VhostConfigWriteStep(new VhostConfigTemplate());
        $state = StepState::fresh($step->name());
        if ($step->check($b['ctx'], $state)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false initially'];
        }
    });

$harness->test('vhost_config', 'execute writes the vhost.conf and check becomes true',
    function () use (&$sandboxes) {
        $b = StepTestContext::build(['payload' => [
            'sftp_user' => 'site_test_user',
            'sftp_group' => 'site_test_group',
        ]]);
        $sandboxes[] = $b;
        $step = new VhostConfigWriteStep(new VhostConfigTemplate());
        $state = StepState::fresh($step->name());

        $res = $step->execute($b['ctx'], $state);
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        // File should exist.
        $domain = $b['ctx']->domain();
        $vhostPath = $b['ols_config_root'] . '/vhosts/' . $domain . '/vhost.conf';
        if (!is_file($vhostPath)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "vhost.conf not at {$vhostPath}"];
        }
        // Re-check with the new state.
        if (!$step->check($b['ctx'], $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check still false after execute'];
        }
    });

$harness->test('vhost_config', 'second execute is no-op (idempotent)',
    function () use (&$sandboxes) {
        $b = StepTestContext::build(['payload' => ['sftp_user' => 'site_test_user']]);
        $sandboxes[] = $b;
        $step = new VhostConfigWriteStep(new VhostConfigTemplate());
        $state = StepState::fresh($step->name());

        $first = $step->execute($b['ctx'], $state);
        if (!$first->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'first execute failed: ' . $first->error];
        }
        $domain = $b['ctx']->domain();
        $vhostPath = $b['ols_config_root'] . '/vhosts/' . $domain . '/vhost.conf';
        $hashBefore = hash_file('sha256', $vhostPath);

        $second = $step->execute($b['ctx'], $first->newState);
        if (!$second->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second execute failed: ' . $second->error];
        }
        $hashAfter = hash_file('sha256', $vhostPath);
        if ($hashBefore !== $hashAfter) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second execute changed file content'];
        }
    });

$harness->test('vhost_config', 'execute uses www-data:www-data fallback when skip_sftp is set',
    function () use (&$sandboxes) {
        // When the operator unchecks "Create SFTP user" in the
        // CreateSiteV2Modal, neither sftp_user nor sftp_group reach
        // the saga - VhostConfigWriteStep must NOT throw and the
        // emitted vhost.conf must contain the www-data fallback so
        // ownership matches HomeDirCreateStep's docroot chown.
        $b = StepTestContext::build(['payload' => ['skip_sftp' => true]]);
        $sandboxes[] = $b;
        $step = new VhostConfigWriteStep(new VhostConfigTemplate());
        $state = StepState::fresh($step->name());

        $res = $step->execute($b['ctx'], $state);
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute failed: ' . $res->error];
        }
        $domain = $b['ctx']->domain();
        $vhostPath = $b['ols_config_root'] . '/vhosts/' . $domain . '/vhost.conf';
        $content = (string) @file_get_contents($vhostPath);
        if ($content === '') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "vhost.conf not written at {$vhostPath}"];
        }
        // The template renders the user/group directly as bare tokens;
        // grep for www-data so we know the fallback path was taken.
        if (!str_contains($content, 'www-data')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost.conf does not reference www-data fallback owner'];
        }
    });

$harness->test('vhost_config', 'compensate removes the vhost.conf and parent dir',
    function () use (&$sandboxes) {
        $b = StepTestContext::build(['payload' => ['sftp_user' => 'site_test_user']]);
        $sandboxes[] = $b;
        $step = new VhostConfigWriteStep(new VhostConfigTemplate());
        $state = StepState::fresh($step->name());

        $exec = $step->execute($b['ctx'], $state);
        if (!$exec->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        $domain = $b['ctx']->domain();
        $vhostPath = $b['ols_config_root'] . '/vhosts/' . $domain . '/vhost.conf';

        $comp = $step->compensate($b['ctx'], $exec->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed'];
        }
        if (is_file($vhostPath)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhost.conf still present after compensate'];
        }
    });

// ── OlsMainConfigInsertStep lifecycle ─────────────────────────
$harness->test('main_config', 'check is false on a config with no vhost block',
    function () use (&$sandboxes) {
        $b = StepTestContext::build();
        $sandboxes[] = $b;
        $step = new OlsMainConfigInsertStep();
        $state = StepState::fresh($step->name());
        if ($step->check($b['ctx'], $state)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false initially'];
        }
    });

$harness->test('main_config', 'execute inserts vhost block + map lines',
    function () use (&$sandboxes) {
        $b = StepTestContext::build();
        $sandboxes[] = $b;
        $step = new OlsMainConfigInsertStep();
        $state = StepState::fresh($step->name());

        $res = $step->execute($b['ctx'], $state);
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        $domain = $b['ctx']->domain();
        $content = file_get_contents($b['main_config_path']);
        if (!preg_match('/virtualhost\s+' . preg_quote($domain, '/') . '\s*\{/i', $content)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "vhost block for {$domain} missing"];
        }
        // Default listener should now have a map line.
        if (!preg_match('/map\s+' . preg_quote($domain, '/') . '\s+/', $content)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'map line missing'];
        }
        // Verify check now returns true.
        if (!$step->check($b['ctx'], $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check false after execute'];
        }
    });

$harness->test('main_config', 'second execute is no-op (idempotent)',
    function () use (&$sandboxes) {
        $b = StepTestContext::build();
        $sandboxes[] = $b;
        $step = new OlsMainConfigInsertStep();

        $first = $step->execute($b['ctx'], StepState::fresh($step->name()));
        if (!$first->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'first failed'];
        }
        $hashBefore = hash_file('sha256', $b['main_config_path']);

        $second = $step->execute($b['ctx'], $first->newState);
        if (!$second->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second failed'];
        }
        $hashAfter = hash_file('sha256', $b['main_config_path']);
        if ($hashBefore !== $hashAfter) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second execute mutated main config'];
        }
    });

$harness->test('main_config', 'compensate removes the vhost block + maps',
    function () use (&$sandboxes) {
        $b = StepTestContext::build();
        $sandboxes[] = $b;
        $step = new OlsMainConfigInsertStep();

        $exec = $step->execute($b['ctx'], StepState::fresh($step->name()));
        if (!$exec->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        $domain = $b['ctx']->domain();
        // Confirm block is there.
        if (!str_contains((string) file_get_contents($b['main_config_path']), $domain)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'preflight: domain not in config'];
        }

        $comp = $step->compensate($b['ctx'], $exec->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed'];
        }
        $content = file_get_contents($b['main_config_path']);
        if (preg_match('/virtualhost\s+' . preg_quote($domain, '/') . '\s*\{/i', $content)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhost block still present after compensate'];
        }
        if (preg_match('/map\s+' . preg_quote($domain, '/') . '\s+/', $content)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'map line still present after compensate'];
        }
    });

// ── OlsRestartStep behavior (no coordinator) ──────────────────
$harness->test('restart', 'no-coordinator path emits warning + records outcome',
    function () use (&$sandboxes, $opts) {
        $b = StepTestContext::build();
        $sandboxes[] = $b;
        $step = new OlsRestartStep();
        $state = StepState::fresh($step->name());

        // check() should be false initially (no prior restart_outcome).
        if ($step->check($b['ctx'], $state)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false initially'];
        }

        if (!isset($opts['with-restart'])) {
            // Without --with-restart we skip the actual lswsctrl call.
            return ['outcome' => TestHarness::SKIP,
                'message' => 'pass --with-restart to actually invoke lswsctrl (will reload OLS)'];
        }
        $res = $step->execute($b['ctx'], $state);
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['restart_outcome'] ?? null) !== 'direct') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected outcome=direct in state'];
        }
        // Second check should be true (already restarted in this saga).
        if (!$step->check($b['ctx'], $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after execute'];
        }
    });

$harness->test('restart', 'compensate is a no-op success',
    function () use (&$sandboxes) {
        $b = StepTestContext::build();
        $sandboxes[] = $b;
        $step = new OlsRestartStep();
        $state = StepState::fresh($step->name())->mergeData(['restart_outcome' => 'restarted']);
        $r = $step->compensate($b['ctx'], $state);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate should succeed'];
        }
    });

// ── End-to-end ordering: vhost + main config + main config validates ─
$harness->test('e2e', 'write vhost then insert main config then re-check both',
    function () use (&$sandboxes) {
        $b = StepTestContext::build(['payload' => ['sftp_user' => 'site_test_user']]);
        $sandboxes[] = $b;

        $vhStep = new VhostConfigWriteStep(new VhostConfigTemplate());
        $mainStep = new OlsMainConfigInsertStep();

        $vhRes = $vhStep->execute($b['ctx'], StepState::fresh($vhStep->name()));
        if (!$vhRes->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhost write failed: ' . $vhRes->error];
        }
        $mainRes = $mainStep->execute($b['ctx'], StepState::fresh($mainStep->name()));
        if (!$mainRes->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'main insert failed: ' . $mainRes->error];
        }
        // Both check() should be true at the end.
        if (!$vhStep->check($b['ctx'], $vhRes->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhost check false'];
        }
        if (!$mainStep->check($b['ctx'], $mainRes->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'main check false'];
        }
        // And the main config should still self-parse cleanly.
        $doc = $b['ols']->loadMainConfig();
        if ($doc === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'main config no longer parseable'];
        }
    });

exit($harness->run());
