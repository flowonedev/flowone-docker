#!/usr/bin/env php
<?php
/**
 * Provisioning Steps :: MySQL Lifecycle
 *
 * Exercises the database-related steps:
 *
 *   - DatabaseCreateStep    (CREATE DATABASE / DROP via compensate is a no-op)
 *   - DatabaseUserCreateStep (CREATE USER + vault password)
 *   - DatabaseGrantStep      (GRANT / REVOKE via compensate)
 *
 * The default panel user (`vpsadmin`) is restricted for security and
 * does NOT hold CREATE DATABASE / CREATE USER privileges. Pass an
 * admin set via flags to actually run destructive tests; otherwise
 * the destructive groups SKIP gracefully (with non-destructive
 * validation tests still passing).
 *
 * Run on server with admin privs:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-steps-db-test.php \
 *     --admin-user=root --admin-pass=SECRET --admin-socket=/run/mysqld/mysqld.sock --verbose
 *
 * Run without admin privs (skips destructive tests):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-steps-db-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', [
    'verbose', 'skip-send', 'only:', 'smoke', 'json', 'help',
    'admin-user:', 'admin-pass:', 'admin-socket:',
]);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseGrantStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseUserCreateStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ProvisioningStepsDb', $opts);

$adminUser = isset($opts['admin-user']) && is_string($opts['admin-user']) ? $opts['admin-user'] : null;
$adminPass = isset($opts['admin-pass']) && is_string($opts['admin-pass']) ? $opts['admin-pass'] : null;
$adminSocket = isset($opts['admin-socket']) && is_string($opts['admin-socket']) ? $opts['admin-socket'] : null;

// Pre-flight: probe for admin privs.
$skipReason = null;
$buildBundleArgs = [];
if ($adminUser !== null && $adminPass !== null) {
    $buildBundleArgs['mysql'] = [
        'user' => $adminUser,
        'pass' => $adminPass,
        'socket' => $adminSocket ?? '/run/mysqld/mysqld.sock',
    ];
} else {
    $skipReason = 'no --admin-user/--admin-pass provided; using default panel creds (likely insufficient)';
}

$probe = StepTestContext::build($buildBundleArgs);
$created = ['dbs' => [], 'users' => [], 'bundles' => [$probe]];
$harness->onCleanup(function () use (&$created, $probe) {
    $mysql = $probe['mysql'];
    foreach ($created['users'] as $u) {
        try { $mysql->dropUser($u, 'localhost'); } catch (\Throwable) {}
    }
    foreach ($created['dbs'] as $d) {
        try { $mysql->dropDatabase($d); } catch (\Throwable) {}
    }
    foreach ($created['bundles'] as $b) {
        StepTestContext::teardown($b);
    }
});

// Probe by attempting a no-op create + drop of an obviously-test name.
$probeDb = 'flowone_test_probe_' . substr(bin2hex(random_bytes(2)), 0, 4);
try {
    $probe['mysql']->createDatabase($probeDb);
    $probe['mysql']->dropDatabase($probeDb);
    $haveAdmin = true;
} catch (\Throwable $e) {
    $haveAdmin = false;
    $skipReason = ($skipReason ?? '') . ' (' . substr($e->getMessage(), 0, 120) . ')';
}

$harness->test('preflight', 'admin privileges available',
    function () use ($haveAdmin, $skipReason) {
        if (!$haveAdmin) {
            return ['outcome' => TestHarness::WARN,
                'message' => "destructive tests will SKIP. " . trim((string) $skipReason)];
        }
    });

// ── DatabaseCreateStep lifecycle ──────────────────────────────
$harness->test('db_create', 'execute + check + compensate (no-op for DEGRADE_ONLY)',
    function () use ($probe, $haveAdmin, &$created) {
        if (!$haveAdmin) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'insufficient privileges'];
        }
        $dbName = 'flowone_test_db_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $created['dbs'][] = $dbName;
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $probe['ctx']->siteRow, jobId: $probe['ctx']->jobId, requestId: $probe['ctx']->requestId,
            actor: $probe['ctx']->actor, audit: $probe['ctx']->audit, vault: $probe['ctx']->vault,
            capabilities: $probe['ctx']->capabilities, database: $probe['ctx']->database,
            payload: ['db_name' => $dbName], adapters: $probe['ctx']->adapters,
        );

        $step = new DatabaseCreateStep();
        if ($step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'db already exists at start'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true post-create'];
        }
        // Compensate is no-op (DEGRADE_ONLY). DB should remain.
        $comp = $step->compensate($ctx, $res->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate should succeed'];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DB was dropped by compensate (must not!)'];
        }
    });

// ── DatabaseUserCreateStep lifecycle ──────────────────────────
$harness->test('db_user', 'execute creates user + stores password in vault',
    function () use ($probe, $haveAdmin, &$created) {
        if (!$haveAdmin) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'insufficient privileges'];
        }
        $user = 'fo_test_u_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $created['users'][] = $user;
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $probe['ctx']->siteRow, jobId: $probe['ctx']->jobId, requestId: $probe['ctx']->requestId,
            actor: $probe['ctx']->actor, audit: $probe['ctx']->audit, vault: $probe['ctx']->vault,
            capabilities: $probe['ctx']->capabilities, database: $probe['ctx']->database,
            payload: ['db_user' => $user], adapters: $probe['ctx']->adapters,
        );
        $step = new DatabaseUserCreateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['user'] ?? null) !== $user) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'state.user not set'];
        }
        if (($res->newState->data['vault_key_name'] ?? null) !== 'db_password') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vault_key_name should be db_password'];
        }
        // Vault should now have the password retrievable.
        $scope = (string) $res->newState->data['vault_scope'];
        $pw = $ctx->vault->get($scope, 'db_password', $ctx->actor);
        if (strlen($pw) < 20) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vault password suspiciously short'];
        }
        // Plaintext password must NOT appear in step state.
        $stateJson = json_encode($res->newState->data);
        if (str_contains((string) $stateJson, $pw)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'PLAINTEXT PASSWORD LEAKED IN STATE!'];
        }
    });

// ── DatabaseGrantStep lifecycle ───────────────────────────────
$harness->test('db_grant', 'GRANT + REVOKE round-trip',
    function () use ($probe, $haveAdmin, &$created) {
        if (!$haveAdmin) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'insufficient privileges'];
        }
        $dbName = 'flowone_test_db_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $user = 'fo_test_u_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $created['dbs'][] = $dbName;
        $created['users'][] = $user;

        // Build a single context for all 3 saga steps.
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $probe['ctx']->siteRow, jobId: $probe['ctx']->jobId, requestId: $probe['ctx']->requestId,
            actor: $probe['ctx']->actor, audit: $probe['ctx']->audit, vault: $probe['ctx']->vault,
            capabilities: $probe['ctx']->capabilities, database: $probe['ctx']->database,
            payload: ['db_name' => $dbName, 'db_user' => $user, 'db_host' => 'localhost'],
            adapters: $probe['ctx']->adapters,
        );

        // Setup: db + user via the prior steps.
        $dbRes = (new DatabaseCreateStep())->execute($ctx, StepState::fresh('database_create'));
        $userRes = (new DatabaseUserCreateStep())->execute($ctx, StepState::fresh('database_user_create'));
        if (!$dbRes->isSuccess() || !$userRes->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'pre-grant setup failed'];
        }

        // Now stitch state into siteRow so DatabaseGrantStep can find it.
        $stateMap = [
            'database_create' => ['data' => $dbRes->newState->data],
            'database_user_create' => ['data' => $userRes->newState->data],
        ];
        $stitchedRow = array_merge($ctx->siteRow, ['state' => json_encode($stateMap)]);
        $ctx2 = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $stitchedRow, jobId: $ctx->jobId, requestId: $ctx->requestId,
            actor: $ctx->actor, audit: $ctx->audit, vault: $ctx->vault,
            capabilities: $ctx->capabilities, database: $ctx->database,
            payload: $ctx->payload, adapters: $ctx->adapters,
        );

        $step = new DatabaseGrantStep();
        if ($step->check($ctx2, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'grant already in place'];
        }
        $res = $step->execute($ctx2, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (!$step->check($ctx2, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after grant'];
        }
        // Compensate (SAFE_ROLLBACK) revokes.
        $comp = $step->compensate($ctx2, $res->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed'];
        }
        if ($step->check($ctx2, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'grant still present after revoke'];
        }
    });

exit($harness->run());
