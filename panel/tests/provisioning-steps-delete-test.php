#!/usr/bin/env php
<?php
/**
 * Provisioning Steps :: DELETE direction (Step 4b)
 *
 * Per-step unit tests for the DELETE saga: each step is exercised in
 * isolation against the sandboxed SiteContext so we can verify the
 * idempotence, error-paths, and snapshot guards that the e2e test
 * cannot easily target.
 *
 * Test groups (matches --only=):
 *   - snapshot            PreDeleteSnapshotStep (no DB / no home cases)
 *   - ols_main_remove     OlsMainConfigRemoveStep (sandbox httpd_config)
 *   - vhost_remove        VhostConfigRemoveStep (sandbox vhosts dir)
 *   - home_remove         HomeDirRemoveStep (/tmp sandbox)
 *   - db_user_drop        DatabaseUserDropStep (needs MySQL admin)
 *   - db_drop             DatabaseDropStep (needs MySQL admin)
 *   - sftp_user_remove    SftpUserRemoveStep (needs root)
 *   - sftp_group_remove   SftpGroupRemoveStep (needs root)
 *
 * Tests that need root/admin SKIP cleanly when those privileges are
 * unavailable so the file is always runnable.
 *
 * All test data uses the `flowone_test_` prefix and lands under
 * /tmp/flowone_step_test_*; the harness cleanup walks every artifact
 * registered through the $tracked array even on SIGINT.
 *
 * Run on server (as root for full coverage):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-steps-delete-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1800));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsMainConfigInsertStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpGroupCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpUserCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\DatabaseDropStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\DatabaseUserDropStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\HomeDirRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\OlsMainConfigRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\PreDeleteSnapshotStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\SftpGroupRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\SftpUserRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\VhostConfigRemoveStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ProvisioningStepsDelete', $opts);
$isRoot = (function_exists('posix_geteuid') && posix_geteuid() === 0);

$tracked = ['groups' => [], 'users' => [], 'dirs' => [], 'sandboxes' => [], 'dbs' => [], 'db_users' => []];
$harness->onCleanup(function () use (&$tracked) {
    // userdel before groupdel (kernel requirement). -f covers the
    // edge case where a forked test process is still holding the uid.
    foreach ($tracked['users'] as $u) {
        @exec('userdel -r -f ' . escapeshellarg($u) . ' 2>/dev/null');
        @exec('userdel -f ' . escapeshellarg($u) . ' 2>/dev/null');
    }
    foreach ($tracked['groups'] as $g) {
        @exec('groupdel ' . escapeshellarg($g) . ' 2>/dev/null');
    }
    foreach ($tracked['dirs'] as $d) {
        if (is_dir($d) && str_starts_with($d, '/tmp/')) {
            @exec('rm -rf ' . escapeshellarg($d));
        }
    }
    foreach ($tracked['sandboxes'] as $b) {
        StepTestContext::teardown($b);
    }
    // Drop any throwaway databases / db users a FAILED test may have
    // left behind. Successful tests already drop their own via the step
    // under test; this is the safety net so the suite never contributes
    // to the rogue-database problem it exists to prevent.
    if (!empty($tracked['dbs']) || !empty($tracked['db_users'])) {
        try {
            $cleanupMysql = new \VpsAdmin\Agent\Provisioner\Adapters\MysqlAdapter(
                runner: new \VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner(),
                credentialsProvider: \VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials::providerFromDefaultConfigFiles(),
            );
            foreach ($tracked['dbs'] as $db) {
                try {
                    if ($cleanupMysql->databaseExists($db)) {
                        $cleanupMysql->dropDatabase($db);
                    }
                } catch (\Throwable) {
                    // best-effort
                }
            }
            foreach ($tracked['db_users'] as $u) {
                try {
                    if ($cleanupMysql->userExists($u, 'localhost')) {
                        $cleanupMysql->dropUser($u, 'localhost');
                    }
                } catch (\Throwable) {
                    // best-effort
                }
            }
        } catch (\Throwable) {
            // No admin connection -> nothing we can clean; tracked DBs
            // were never created either (the tests SKIP without admin).
        }
    }
});

$bundle = function (array $opts = []) use (&$tracked): array {
    $b = StepTestContext::build($opts);
    $tracked['sandboxes'][] = $b;
    return $b;
};

/**
 * Build a SiteContext from an existing bundle, overriding payload and
 * (optionally) siteRow without re-creating the sandbox.
 */
$ctxWith = function (array $b, array $payload = [], array $rowOverrides = []): SiteContext {
    $row = array_merge($b['ctx']->siteRow, $rowOverrides);
    return new SiteContext(
        siteRow: $row,
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
};

/**
 * Detect whether the MySQL admin in the test context can run
 * destructive DDL. The cheapest probe is CREATE DATABASE on a
 * throwaway name; if it works we DROP it and report true.
 */
$mysqlCanDestructiveDDL = function (array $b): bool {
    $dbName = 'flowone_test_probe_' . substr(bin2hex(random_bytes(2)), 0, 4);
    try {
        if ($b['mysql']->databaseExists($dbName)) {
            $b['mysql']->dropDatabase($dbName);
        }
        $created = $b['mysql']->createDatabase($dbName);
        if ($created) {
            $b['mysql']->dropDatabase($dbName);
        }
        return true;
    } catch (\Throwable) {
        return false;
    }
};

// ─────────────────────────────────────────────────────────────────
// PreDeleteSnapshotStep
// ─────────────────────────────────────────────────────────────────

$harness->test('snapshot', 'skip_snapshot=true short-circuits to success',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $snapRoot = $b['sandbox_root'] . '/snapshots';
        $ctx = $ctxWith($b, [
            'skip_snapshot' => true,
            'snapshot_root' => $snapRoot,
        ]);
        $step = new PreDeleteSnapshotStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['skipped_by_operator'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected skipped_by_operator=true in state'];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after skip'];
        }
    });

$harness->test('snapshot', 'skip_db_snapshot + missing-home produces all-skipped success',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $snapRoot = $b['sandbox_root'] . '/snapshots';
        $domain = $b['ctx']->domain();
        $ctx = $ctxWith($b, [
            // skip_db_snapshot avoids needing MySQL admin creds in this
            // test - the equivalent "DB doesn't exist" path is exercised
            // by the e2e suite where MySQL is wired.
            'skip_db_snapshot' => true,
            'home_dir' => '/tmp/flowone_test_no_home_' . substr(bin2hex(random_bytes(2)), 0, 4),
            'snapshot_root' => $snapRoot,
        ]);

        $step = new PreDeleteSnapshotStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['db_skipped'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected db_skipped=true'];
        }
        if (($res->newState->data['home_skipped'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected home_skipped=true'];
        }
        $expectedDir = $snapRoot . '/' . $domain . '/' . $ctx->jobId;
        if (($res->newState->data['snapshot_dir'] ?? null) !== $expectedDir) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'snapshot_dir mismatch; got ' . var_export($res->newState->data['snapshot_dir'] ?? null, true)];
        }
        if (!is_dir($expectedDir)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'snapshot dir not created on disk'];
        }
    });

$harness->test('snapshot', 'check is true after a same-job snapshot run, false otherwise',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b, [
            'skip_db_snapshot' => true,
            'home_dir' => '/tmp/flowone_test_nope_' . substr(bin2hex(random_bytes(2)), 0, 4),
            'snapshot_root' => $b['sandbox_root'] . '/snapshots',
        ]);
        $step = new PreDeleteSnapshotStep();
        $state = StepState::fresh($step->name());
        if ($step->check($ctx, $state)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false before execute'];
        }
        $res = $step->execute($ctx, $state);
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after execute'];
        }
        // Mock a different job: same state, different jobId in ctx.
        $otherCtx = new SiteContext(
            siteRow: $ctx->siteRow, jobId: $ctx->jobId + 1, requestId: $ctx->requestId,
            actor: $ctx->actor, audit: $ctx->audit, vault: $ctx->vault,
            capabilities: $ctx->capabilities, database: $ctx->database,
            payload: $ctx->payload, dryRun: false, adapters: $ctx->adapters,
        );
        if ($step->check($otherCtx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false for a different job'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// OlsMainConfigRemoveStep
// ─────────────────────────────────────────────────────────────────

$harness->test('ols_main_remove', 'no-op success when block is already absent',
    function () use ($bundle) {
        $b = $bundle();
        $step = new OlsMainConfigRemoveStep();
        if (!$step->check($b['ctx'], StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true on clean config'];
        }
        $res = $step->execute($b['ctx'], StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['vhost_removed'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected vhost_removed=false on no-op'];
        }
    });

$harness->test('ols_main_remove', 'removes block + listener maps inserted by CREATE step',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b, ['aliases' => ['www.' . $b['ctx']->domain()]], [
            'sftp_user' => 'site_test', 'sftp_group' => 'site_test',
            'home_dir' => '/tmp/site_test', 'php_lsapi' => 'lsphp83',
            'admin_email' => 'admin@example.com',
        ]);
        // Insert first via the CREATE step so we have something to remove.
        $insert = new OlsMainConfigInsertStep();
        $r1 = $insert->execute($ctx, StepState::fresh($insert->name()));
        if (!$r1->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'INSERT step failed: ' . $r1->error];
        }

        $remove = new OlsMainConfigRemoveStep();
        if ($remove->check($ctx, StepState::fresh($remove->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false after insert'];
        }
        $r2 = $remove->execute($ctx, StepState::fresh($remove->name()));
        if (!$r2->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'REMOVE step failed: ' . $r2->error];
        }
        if (($r2->newState->data['vhost_removed'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected vhost_removed=true'];
        }
        // Re-running is a no-op success (idempotent).
        $r3 = $remove->execute($ctx, StepState::fresh($remove->name()));
        if (!$r3->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second execute failed: ' . $r3->error];
        }
        if (($r3->newState->data['vhost_removed'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected second execute to be no-op'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// VhostConfigRemoveStep
// ─────────────────────────────────────────────────────────────────

$harness->test('vhost_remove', 'no-op when vhost dir does not exist',
    function () use ($bundle) {
        $b = $bundle();
        $step = new VhostConfigRemoveStep();
        $state = StepState::fresh($step->name());
        if (!$step->check($b['ctx'], $state)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true on missing dir'];
        }
        $res = $step->execute($b['ctx'], $state);
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
    });

$harness->test('vhost_remove', 'deletes the per-domain vhost directory',
    function () use ($bundle) {
        $b = $bundle();
        $domain = $b['ctx']->domain();
        $path = $b['ols']->vhostConfigPath($domain);
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "# sandbox vhost.conf\n");
        if (!is_file($path)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'precondition failed: file not written'];
        }
        $step = new VhostConfigRemoveStep();
        if ($step->check($b['ctx'], StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false when dir exists'];
        }
        $res = $step->execute($b['ctx'], StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (is_dir(dirname($path))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhost dir still present'];
        }
        if (($res->newState->data['entries_removed'] ?? 0) < 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected entries_removed >= 1'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// HomeDirRemoveStep
// ─────────────────────────────────────────────────────────────────

$harness->test('home_remove', 'removes the home tree and reports entries removed',
    function () use ($bundle, $ctxWith, &$tracked) {
        $b = $bundle();
        $home = '/tmp/flowone_test_home_' . substr(bin2hex(random_bytes(2)), 0, 4);
        @mkdir($home . '/sub', 0755, true);
        $tracked['dirs'][] = $home;
        file_put_contents($home . '/file.txt', "x\n");
        file_put_contents($home . '/sub/file2.txt', "y\n");

        $ctx = $ctxWith($b, [], ['home_dir' => $home]);
        $step = new HomeDirRemoveStep();
        if ($step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false when dir exists'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (is_dir($home)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'home dir still present'];
        }
        if (($res->newState->data['entries_removed'] ?? 0) < 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected >= 3 entries removed; got ' . var_export($res->newState->data['entries_removed'] ?? null, true)];
        }
    });

$harness->test('home_remove', 'no-op success when dir is already gone',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $home = '/tmp/flowone_test_home_absent_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $ctx = $ctxWith($b, [], ['home_dir' => $home]);
        $step = new HomeDirRemoveStep();
        if (!$step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true when dir absent'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['removed'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected removed=false on no-op'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// SftpUserRemoveStep + SftpGroupRemoveStep
// ─────────────────────────────────────────────────────────────────

$harness->test('sftp_user_remove', 'deletes a user created by SftpUserCreateStep',
    function () use ($bundle, $ctxWith, $isRoot, &$tracked) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        $b = $bundle();
        $group = 'flowone_test_drg_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $user = 'flowone_test_dru_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $home = '/tmp/' . $user;
        $tracked['groups'][] = $group;
        $tracked['users'][] = $user;
        $tracked['dirs'][] = $home;

        $ctx = $ctxWith($b, [
            'sftp_group' => $group, 'sftp_user' => $user, 'home_dir' => $home,
        ]);
        (new SftpGroupCreateStep())->execute($ctx, StepState::fresh('sftp_group_create'));
        (new SftpUserCreateStep())->execute($ctx, StepState::fresh('sftp_user_create'));

        $step = new SftpUserRemoveStep();
        if ($step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false before delete'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after delete'];
        }
        // Second execute is no-op.
        $res2 = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res2->isSuccess() || ($res2->newState->data['removed'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second execute not idempotent no-op'];
        }
    });

$harness->test('sftp_group_remove', 'deletes a group created by SftpGroupCreateStep',
    function () use ($bundle, $ctxWith, $isRoot, &$tracked) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        $b = $bundle();
        $group = 'flowone_test_grg_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $tracked['groups'][] = $group;
        $ctx = $ctxWith($b, ['sftp_group' => $group]);
        (new SftpGroupCreateStep())->execute($ctx, StepState::fresh('sftp_group_create'));

        $step = new SftpGroupRemoveStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after delete'];
        }
    });

// Adopted sites whose CREATE saga state map is empty (because the
// site was inserted by the backfill, not provisioned) still need
// SftpGroupRemoveStep to know the right group name. The user-remove
// step caches the user's primary group into state.data, the worker
// persists it, and this step reads it from the state JSON map.
$harness->test('sftp_user_remove', 'caches primary group name into state.data before deletion',
    function () use ($bundle, $ctxWith, $isRoot, &$tracked) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        $b = $bundle();
        $group = 'flowone_test_pg_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $user = 'flowone_test_pu_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $home = '/tmp/' . $user;
        $tracked['groups'][] = $group;
        $tracked['users'][] = $user;
        $tracked['dirs'][] = $home;

        $ctx = $ctxWith($b, [
            'sftp_group' => $group, 'sftp_user' => $user, 'home_dir' => $home,
        ]);
        (new SftpGroupCreateStep())->execute($ctx, StepState::fresh('sftp_group_create'));
        (new SftpUserCreateStep())->execute($ctx, StepState::fresh('sftp_user_create'));

        $step = new SftpUserRemoveStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        $cached = $res->newState->data['primary_group'] ?? null;
        if ($cached !== $group) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected primary_group='{$group}' cached, got " . var_export($cached, true)];
        }
    });

// Real-world saga safety: refuse to groupdel www-data (or any group
// that other accounts still depend on). Without this guard the saga
// could silently break file ownership on every other site that
// shares the group.
$harness->test('sftp_group_remove', 'skips a shared system group (www-data) without failing',
    function () use ($bundle, $ctxWith, $isRoot) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        // We DON'T touch www-data; we just check the step refuses to
        // delete it. The shared-group guard short-circuits before any
        // mutating call.
        $b = $bundle();
        if (!$b['ctx']->requireAdapters()->sftp->groupExists('www-data')) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'www-data not present on this host'];
        }
        $ctx = $ctxWith($b, ['sftp_group' => 'www-data']);
        $step = new SftpGroupRemoveStep();
        // check() should return true (already satisfied): the group is
        // shared so there's nothing FOR THIS SITE to do.
        if (!$step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check should return true for shared group'];
        }
        // execute() should succeed with shared=true and removed=false.
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute should succeed for shared group; got error: ' . $res->error];
        }
        if (($res->newState->data['shared'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected shared=true on state'];
        }
        if (($res->newState->data['removed'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected removed=false on shared group'];
        }
        // www-data must still exist after the no-op.
        if (!$b['ctx']->requireAdapters()->sftp->groupExists('www-data')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'CRITICAL: www-data was deleted by the saga'];
        }
    });

// Same guard tested by reading the cached primary_group from the
// state JSON map (the realistic adopted-site path).
$harness->test('sftp_group_remove', 'reads primary_group from sftp_user_remove state when present',
    function () use ($bundle, $ctxWith, $isRoot, &$tracked) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        // Build a self-contained group that's not shared, with no
        // sftp_group in the payload — the only way to discover it is
        // through the state JSON map's sftp_user_remove entry.
        $b = $bundle();
        $group = 'flowone_test_sg_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $tracked['groups'][] = $group;
        $b['ctx']->requireAdapters()->sftp->createGroup($group);

        $stateMap = [
            'sftp_user_remove' => ['data' => ['primary_group' => $group]],
        ];
        $ctx = $ctxWith($b, [], ['state' => json_encode($stateMap)]);
        $step = new SftpGroupRemoveStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['group'] ?? null) !== $group) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected resolved group '{$group}', got "
                    . var_export($res->newState->data['group'] ?? null, true)];
        }
        if (($res->newState->data['removed'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected removed=true for orphan group'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// DatabaseUserDropStep + DatabaseDropStep (need MySQL admin)
// ─────────────────────────────────────────────────────────────────

// Adopted legacy sites with NO db_user anywhere on the row, payload,
// or saga state must NOT trigger a userExists() probe against
// mysql.user. Probing requires SELECT on mysql.user, which the panel
// DB user often lacks; more importantly, deriving a phantom username
// for a site that never had one is a footgun. The step must skip
// without touching MariaDB.
$harness->test('db_user_drop', 'skips when no db_user is known (adopted site)',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b);  // no payload, no row override -> nothing known
        $step = new DatabaseUserDropStep();
        if (!$step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check should return true (already satisfied) when no user known'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute should skip cleanly; got error: ' . $res->error];
        }
        if (($res->newState->data['removed'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected removed=false in state data'];
        }
    });

// When nothing on the row/payload/state records a db_name, the step
// now derives it from the domain (ResourceNameDeriver::dbName, the same
// name DatabaseCreateStep and PreDeleteSnapshotStep use) and drops it.
// The previous behaviour skipped the drop, which left a rogue database
// behind after every site delete. Two facets:
//   1. derived DB present -> it gets dropped.
//   2. derived DB absent  -> clean no-op success.
$harness->test('db_drop', 'derives the site DB name when none is recorded and drops it',
    function () use ($bundle, $ctxWith, $mysqlCanDestructiveDDL, &$tracked) {
        $b = $bundle();
        if (!$mysqlCanDestructiveDDL($b)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'MySQL admin not available'];
        }
        $domain = $b['ctx']->domain();
        $derived = \VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver::dbName($domain);
        $b['mysql']->createDatabase($derived);
        $tracked['dbs'][] = $derived;

        $ctx = $ctxWith($b);  // no payload, no row override -> nothing recorded
        $step = new DatabaseDropStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute should drop the derived DB; got error: ' . $res->error];
        }
        if ($b['mysql']->databaseExists($derived)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'derived database still exists after drop'];
        }
    });

$harness->test('db_drop', 'clean no-op when the derived DB is absent (adopted site)',
    function () use ($bundle, $ctxWith, $mysqlCanDestructiveDDL) {
        $b = $bundle();
        if (!$mysqlCanDestructiveDDL($b)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'MySQL admin not available'];
        }
        $ctx = $ctxWith($b);  // no payload, no row override; derived DB never created
        $step = new DatabaseDropStep();
        if (!$step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check should return true when the derived db is absent'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute should no-op cleanly; got error: ' . $res->error];
        }
    });

$harness->test('db_user_drop', 'no-op when user absent',
    function () use ($bundle, $ctxWith, $mysqlCanDestructiveDDL) {
        $b = $bundle();
        if (!$mysqlCanDestructiveDDL($b)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'MySQL admin not available'];
        }
        $ctx = $ctxWith($b, [
            'db_user' => 'fo_no_such_' . substr(bin2hex(random_bytes(2)), 0, 4),
        ]);
        $step = new DatabaseUserDropStep();
        if (!$step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true on absent user'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
    });

$harness->test('db_user_drop', 'drops an existing user',
    function () use ($bundle, $ctxWith, $mysqlCanDestructiveDDL, &$tracked) {
        $b = $bundle();
        if (!$mysqlCanDestructiveDDL($b)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'MySQL admin not available'];
        }
        $user = 'fo_test_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $b['mysql']->createUser($user, 'test-pw-' . bin2hex(random_bytes(8)), 'localhost');
        $tracked['db_users'][] = $user;

        $ctx = $ctxWith($b, ['db_user' => $user, 'db_host' => 'localhost']);
        $step = new DatabaseUserDropStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['removed'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected removed=true; data=' . json_encode($res->newState->data)];
        }
        if ($b['mysql']->userExists($user, 'localhost')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'user still exists after drop'];
        }
    });

$harness->test('db_drop', 'no-op when database is already absent',
    function () use ($bundle, $ctxWith, $mysqlCanDestructiveDDL) {
        $b = $bundle();
        if (!$mysqlCanDestructiveDDL($b)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'MySQL admin not available'];
        }
        $db = 'flowone_test_no_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $ctx = $ctxWith($b, ['db_name' => $db]);
        $step = new DatabaseDropStep();
        if (!$step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true on absent db'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['removed'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected removed=false on no-op'];
        }
    });

$harness->test('db_drop', 'drops an existing database',
    function () use ($bundle, $ctxWith, $mysqlCanDestructiveDDL, &$tracked) {
        $b = $bundle();
        if (!$mysqlCanDestructiveDDL($b)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'MySQL admin not available'];
        }
        $db = 'flowone_test_d_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $b['mysql']->createDatabase($db);
        $tracked['dbs'][] = $db;

        $ctx = $ctxWith($b, ['db_name' => $db]);
        $step = new DatabaseDropStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if ($b['mysql']->databaseExists($db)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'database still exists after drop'];
        }
        if (($res->newState->data['removed'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected removed=true'];
        }
    });

// database_links bookkeeping: the drop must also sweep the panel's
// database_links rows for the db/domain, even when the database
// itself is already gone (the bookkeeping-only leftover behind the
// June 2026 "deleted site still listed in Databases view" incident).
$harness->test('db_drop', 'sweeps database_links rows even when the DB is already absent',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $pdo = $b['ctx']->database->pdo();
        try {
            $pdo->query("SELECT 1 FROM database_links LIMIT 1");
        } catch (\Throwable) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'database_links table absent on this install'];
        }
        // No DDL needed, but check()/execute() probe databaseExists();
        // SKIP when even read access to MySQL is unavailable.
        try {
            $b['mysql']->databaseExists('information_schema');
        } catch (\Throwable) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'MySQL not reachable from test context'];
        }

        $db = 'flowone_test_lnk_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $domain = $b['ctx']->domain();
        $pdo->prepare(
            "INSERT INTO database_links (db_name, domain) VALUES (?, ?)"
        )->execute([$db, $domain]);

        try {
            $ctx = $ctxWith($b, ['db_name' => $db]);
            $step = new DatabaseDropStep();
            // The orphaned link row alone must make check() unsatisfied.
            if ($step->check($ctx, StepState::fresh($step->name()))) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'check() true while an orphaned database_links row exists'];
            }
            $res = $step->execute($ctx, StepState::fresh($step->name()));
            if (!$res->isSuccess()) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
            }
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM database_links WHERE db_name = ? OR domain = ?"
            );
            $stmt->execute([$db, $domain]);
            if ((int) $stmt->fetchColumn() !== 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'database_links row survived the drop step'];
            }
            if (!$step->check($ctx, StepState::fresh($step->name()))) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'check() still false after link sweep'];
            }
        } finally {
            try {
                $pdo->prepare("DELETE FROM database_links WHERE db_name = ?")->execute([$db]);
            } catch (\Throwable) {
            }
        }
    });

$harness->run();
