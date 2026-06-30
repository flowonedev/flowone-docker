#!/usr/bin/env php
<?php
/**
 * Provisioning Steps :: SUSPEND / RESUME / ARCHIVE / RESTORE (Step 4c)
 *
 * Per-step unit tests for the lifecycle saga families introduced in
 * Step 4c. Each step is exercised against the sandboxed SiteContext
 * so disk artifacts land under /tmp/flowone_step_test_* and never
 * touch live OLS / MariaDB.
 *
 * Test groups (matches --only=):
 *   - suspend            VhostSuspendStep
 *   - resume             VhostResumeStep
 *   - archive_promote    ArchivePromoteStep
 *   - restore_preflight  ArchiveRestorePreflightStep
 *   - home_hydrate       HomeDirHydrateStep
 *   - db_hydrate         DatabaseHydrateStep
 *
 * MySQL-touching tests (db_hydrate) SKIP cleanly when the test
 * context can't acquire destructive DDL credentials, so the suite
 * is always runnable.
 *
 * Cleanup is exhaustive: every sandbox is torn down and every
 * temp dir registered through $tracked['dirs'] is rm -rf'd on
 * normal exit or SIGINT.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-steps-suspend-test.php --verbose
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
use VpsAdmin\Agent\Provisioner\Step\Steps\Archive\ArchivePromoteStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Restore\ArchiveRestorePreflightStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Restore\DatabaseHydrateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Restore\HomeDirHydrateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Suspend\VhostResumeStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Suspend\VhostSuspendStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ProvisioningStepsSuspend', $opts);

$tracked = ['dirs' => [], 'sandboxes' => [], 'dbs' => []];

$harness->onCleanup(function () use (&$tracked): void {
    foreach ($tracked['dirs'] as $d) {
        if (is_dir($d) && str_starts_with($d, '/tmp/')) {
            @exec('rm -rf ' . escapeshellarg($d));
        }
    }
    foreach ($tracked['sandboxes'] as $b) {
        StepTestContext::teardown($b);
    }
    // Best-effort DB cleanup for db_hydrate tests.
    foreach ($tracked['dbs'] as $entry) {
        try {
            [$mysql, $name] = $entry;
            if ($mysql->databaseExists($name)) {
                $mysql->dropDatabase($name);
            }
        } catch (\Throwable) {
            // ignore
        }
    }
});

$bundle = function (array $opts = []) use (&$tracked): array {
    $b = StepTestContext::build($opts);
    $tracked['sandboxes'][] = $b;
    return $b;
};

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
// VhostSuspendStep
// ─────────────────────────────────────────────────────────────────

$seedVhost = function (array $b, string $body = "docRoot /var/www/example\n") {
    // Use the sandbox OLS adapter to write a baseline vhost.conf so
    // suspend/resume have something to swap with.
    $b['ols']->writeVhostConfig($b['ctx']->domain(), $body);
};

$harness->test('suspend', 'writes backup + suspended config and sets marker',
    function () use ($bundle, $ctxWith, $seedVhost) {
        $b = $bundle();
        $seedVhost($b, "docRoot /home/example/public_html\nphp_admin_value open_basedir /home/example\n");

        $ctx = $ctxWith($b, ['suspend_message' => 'Maintenance window']);
        $step = new VhostSuspendStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        $live = $b['ols']->vhostConfigPath($ctx->domain());
        $backup = $live . '.suspended-backup';
        if (!is_file($backup)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'backup file not written'];
        }
        $original = file_get_contents($backup);
        if (!is_string($original) || strpos($original, 'open_basedir') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'backup does not contain original content'];
        }
        $newLive = file_get_contents($live);
        if (!is_string($newLive) || strpos($newLive, 'flowone:suspended=true') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'suspended marker missing from live config'];
        }
        if (strpos($newLive, 'Maintenance window') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'suspend message not embedded in 503 body'];
        }
        if (($res->newState->data['suspended_marker'] ?? null) !== '# flowone:suspended=true') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'state did not record marker'];
        }
    });

$harness->test('suspend', 'check returns true after a successful run',
    function () use ($bundle, $ctxWith, $seedVhost) {
        $b = $bundle();
        $seedVhost($b);
        $ctx = $ctxWith($b);
        $step = new VhostSuspendStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after suspend'];
        }
    });

$harness->test('suspend', 'check is false on a non-suspended live config',
    function () use ($bundle, $ctxWith, $seedVhost) {
        $b = $bundle();
        $seedVhost($b);
        $ctx = $ctxWith($b);
        $step = new VhostSuspendStep();
        if ($step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false for fresh state'];
        }
    });

$harness->test('suspend', 'compensate restores backup and removes suspended live',
    function () use ($bundle, $ctxWith, $seedVhost) {
        $b = $bundle();
        $original = "docRoot /home/example\n# original marker\n";
        $seedVhost($b, $original);
        $ctx = $ctxWith($b);
        $step = new VhostSuspendStep();

        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        $comp = $step->compensate($ctx, $res->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed: ' . ($comp->error ?? '')];
        }
        $live = $b['ols']->vhostConfigPath($ctx->domain());
        $current = file_get_contents($live);
        if ($current !== $original) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'live config does not match original after compensate'];
        }
        if (file_exists($live . '.suspended-backup')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'backup file should be deleted'];
        }
    });

$harness->test('suspend', 'execute fails cleanly when vhost.conf is missing',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b);
        $step = new VhostSuspendStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if ($res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure when vhost.conf missing'];
        }
        if (!str_contains((string) $res->error, 'vhost.conf missing')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unexpected error message: ' . $res->error];
        }
    });

// ─────────────────────────────────────────────────────────────────
// VhostResumeStep
// ─────────────────────────────────────────────────────────────────

$harness->test('resume', 'restores backup -> live and deletes backup',
    function () use ($bundle, $ctxWith, $seedVhost) {
        $b = $bundle();
        $original = "docRoot /home/x\nphp_admin_value foo bar\n";
        $seedVhost($b, $original);
        $ctx = $ctxWith($b);

        // First suspend so the backup exists.
        $suspendStep = new VhostSuspendStep();
        $sRes = $suspendStep->execute($ctx, StepState::fresh($suspendStep->name()));
        if (!$sRes->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'precondition: suspend failed'];
        }

        $resumeStep = new VhostResumeStep();
        $rRes = $resumeStep->execute($ctx, StepState::fresh($resumeStep->name()));
        if (!$rRes->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'resume failed: ' . $rRes->error];
        }
        $live = $b['ols']->vhostConfigPath($ctx->domain());
        if (file_get_contents($live) !== $original) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'live config not restored to original bytes'];
        }
        if (file_exists($live . '.suspended-backup')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'backup file should be deleted'];
        }
    });

$harness->test('resume', 'check returns true after a successful run',
    function () use ($bundle, $ctxWith, $seedVhost) {
        $b = $bundle();
        $seedVhost($b);
        $ctx = $ctxWith($b);
        $suspendStep = new VhostSuspendStep();
        $sRes = $suspendStep->execute($ctx, StepState::fresh($suspendStep->name()));
        $resumeStep = new VhostResumeStep();
        $rRes = $resumeStep->execute($ctx, StepState::fresh($resumeStep->name()));
        if (!$rRes->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'resume failed'];
        }
        if (!$resumeStep->check($ctx, $rRes->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after resume'];
        }
    });

$harness->test('resume', 'fails when backup missing and live IS suspended',
    function () use ($bundle, $ctxWith, $seedVhost) {
        $b = $bundle();
        $seedVhost($b);
        $ctx = $ctxWith($b);
        // Manually flip live to "suspended"-looking without writing a backup.
        $live = $b['ols']->vhostConfigPath($ctx->domain());
        file_put_contents($live, "# flowone:suspended=true\nthis is a suspended config\n");

        $resumeStep = new VhostResumeStep();
        $rRes = $resumeStep->execute($ctx, StepState::fresh($resumeStep->name()));
        if ($rRes->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure with no backup + suspended live'];
        }
    });

$harness->test('resume', 'no-op success when backup missing + live healthy',
    function () use ($bundle, $ctxWith, $seedVhost) {
        $b = $bundle();
        $seedVhost($b, "docRoot /home/normal\n");
        $ctx = $ctxWith($b);
        $resumeStep = new VhostResumeStep();
        $rRes = $resumeStep->execute($ctx, StepState::fresh($resumeStep->name()));
        if (!$rRes->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected no-op success: ' . $rRes->error];
        }
        if (($rRes->newState->data['resumed_no_op'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected resumed_no_op=true in state'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// ArchivePromoteStep
// ─────────────────────────────────────────────────────────────────

$seedSnapshot = function (array $b, string $domain, int $jobId, string $snapshotRoot): string {
    $dir = $snapshotRoot . '/' . $domain . '/' . $jobId;
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/home.tar.gz', "fake tar\n");
    file_put_contents($dir . '/example.sql', "-- fake dump\nSELECT 1;\n");
    return $dir;
};

$harness->test('archive_promote', 'cp -a snapshot into archive_root with timestamped subdir',
    function () use ($bundle, $ctxWith, $seedSnapshot, &$tracked) {
        $b = $bundle();
        $snapshotRoot = $b['sandbox_root'] . '/snapshots';
        $archiveRoot = $b['sandbox_root'] . '/archives';
        $tracked['dirs'][] = $snapshotRoot;
        $tracked['dirs'][] = $archiveRoot;

        $domain = $b['ctx']->domain();
        $jobId = $b['ctx']->jobId;
        $seedSnapshot($b, $domain, $jobId, $snapshotRoot);

        $ctx = $ctxWith($b, [
            'snapshot_root' => $snapshotRoot,
            'archive_root' => $archiveRoot,
        ]);
        $step = new ArchivePromoteStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        $archivePath = $res->newState->data['archive_path'] ?? null;
        if (!is_string($archivePath) || !is_dir($archivePath)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'archive_path missing or not a dir'];
        }
        if (!is_file($archivePath . '/home.tar.gz')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'home.tar.gz not copied'];
        }
        if (!is_file($archivePath . '/example.sql')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'example.sql not copied'];
        }
    });

$harness->test('archive_promote', 'rejects skip_snapshot=true with no archive data',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b, [
            'skip_snapshot' => true,
            'snapshot_root' => $b['sandbox_root'] . '/snapshots',
            'archive_root' => $b['sandbox_root'] . '/archives',
        ]);
        $step = new ArchivePromoteStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if ($res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure with skip_snapshot=true'];
        }
    });

$harness->test('archive_promote', 'fails when snapshot dir missing',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b, [
            'snapshot_root' => $b['sandbox_root'] . '/snapshots-missing-' . bin2hex(random_bytes(2)),
            'archive_root' => $b['sandbox_root'] . '/archives',
        ]);
        $step = new ArchivePromoteStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if ($res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure with missing snapshot dir'];
        }
        if (!str_contains((string) $res->error, 'snapshot directory missing')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unexpected error: ' . $res->error];
        }
    });

$harness->test('archive_promote', 'check is true for a same-job promotion, false otherwise',
    function () use ($bundle, $ctxWith, $seedSnapshot, &$tracked) {
        $b = $bundle();
        $snapshotRoot = $b['sandbox_root'] . '/snapshots';
        $archiveRoot = $b['sandbox_root'] . '/archives';
        $tracked['dirs'][] = $snapshotRoot;
        $tracked['dirs'][] = $archiveRoot;
        $domain = $b['ctx']->domain();
        $jobId = $b['ctx']->jobId;
        $seedSnapshot($b, $domain, $jobId, $snapshotRoot);

        $ctx = $ctxWith($b, [
            'snapshot_root' => $snapshotRoot,
            'archive_root' => $archiveRoot,
        ]);
        $step = new ArchivePromoteStep();

        if ($step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be false on fresh state'];
        }
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after a promotion'];
        }
    });

$harness->test('archive_promote', 'compensate removes the archive copy',
    function () use ($bundle, $ctxWith, $seedSnapshot, &$tracked) {
        $b = $bundle();
        $snapshotRoot = $b['sandbox_root'] . '/snapshots';
        $archiveRoot = $b['sandbox_root'] . '/archives';
        $tracked['dirs'][] = $snapshotRoot;
        $tracked['dirs'][] = $archiveRoot;
        $domain = $b['ctx']->domain();
        $jobId = $b['ctx']->jobId;
        $seedSnapshot($b, $domain, $jobId, $snapshotRoot);

        $ctx = $ctxWith($b, [
            'snapshot_root' => $snapshotRoot,
            'archive_root' => $archiveRoot,
        ]);
        $step = new ArchivePromoteStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        $archiveDir = $res->newState->data['archive_path'] ?? '';
        $comp = $step->compensate($ctx, $res->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed: ' . ($comp->error ?? '')];
        }
        if (is_dir($archiveDir)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'archive dir should be gone after compensate'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// ArchiveRestorePreflightStep
// ─────────────────────────────────────────────────────────────────

$seedArchive = function (array $b, string $dbName = 'flowone_test_db'): string {
    $dir = $b['sandbox_root'] . '/archive-input-' . bin2hex(random_bytes(2));
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/home.tar.gz', "tar\n");
    file_put_contents($dir . '/' . $dbName . '.sql', "-- dump\n");
    return $dir;
};

$harness->test('restore_preflight', 'fails when archive_path missing in payload',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b);
        $step = new ArchiveRestorePreflightStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if ($res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure with no archive_path'];
        }
    });

$harness->test('restore_preflight', 'fails when archive directory missing on disk',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b, ['archive_path' => '/tmp/flowone_test_no_archive_' . bin2hex(random_bytes(2))]);
        $step = new ArchiveRestorePreflightStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if ($res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure with missing dir'];
        }
    });

$harness->test('restore_preflight', 'records db_dump_path + home_tar_path when both present',
    function () use ($bundle, $ctxWith, $seedArchive, &$tracked) {
        $b = $bundle();
        $dbName = 'flowone_test_pf_' . bin2hex(random_bytes(2));
        $dir = $seedArchive($b, $dbName);
        $tracked['dirs'][] = $dir;

        $ctx = $ctxWith($b, ['archive_path' => $dir, 'db_name' => $dbName]);
        $step = new ArchiveRestorePreflightStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['db_dump_path'] ?? null) !== $dir . '/' . $dbName . '.sql') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'db_dump_path not recorded'];
        }
        if (($res->newState->data['home_tar_path'] ?? null) !== $dir . '/home.tar.gz') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'home_tar_path not recorded'];
        }
    });

$harness->test('restore_preflight', 'tolerates site without db_name',
    function () use ($bundle, $ctxWith, &$tracked) {
        $b = $bundle();
        $dir = $b['sandbox_root'] . '/archive-no-db';
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/home.tar.gz', "tar\n");
        $tracked['dirs'][] = $dir;
        $ctx = $ctxWith($b, ['archive_path' => $dir]);
        $step = new ArchiveRestorePreflightStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['db_dump_path'] ?? null) !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected db_dump_path=null for site without db'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// HomeDirHydrateStep
// ─────────────────────────────────────────────────────────────────

$harness->test('home_hydrate', 'skip_home_hydrate=true short-circuits',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b, ['skip_home_hydrate' => true]);
        $step = new HomeDirHydrateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['skipped_by_operator'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected skipped_by_operator=true'];
        }
    });

$harness->test('home_hydrate', 'fails when home dir missing',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $home = '/tmp/flowone_test_no_home_' . bin2hex(random_bytes(2));
        $ctx = $ctxWith($b, ['archive_path' => '/tmp', 'home_dir' => $home]);
        $step = new HomeDirHydrateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if ($res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure with missing home'];
        }
    });

$harness->test('home_hydrate', 'extracts tar into home + writes sentinel',
    function () use ($bundle, $ctxWith, &$tracked) {
        $b = $bundle();
        // Build a real tar archive
        $home = '/tmp/flowone_test_hydrate_home_' . bin2hex(random_bytes(2));
        @mkdir($home, 0750, true);
        $tracked['dirs'][] = $home;
        file_put_contents($home . '/old_marker.txt', 'old');

        $archiveDir = '/tmp/flowone_test_hydrate_arc_' . bin2hex(random_bytes(2));
        @mkdir($archiveDir, 0700, true);
        $tracked['dirs'][] = $archiveDir;

        // Create a source tree with a recognizable marker file, then tar it.
        $srcDir = '/tmp/flowone_test_hydrate_src_' . bin2hex(random_bytes(2));
        @mkdir($srcDir, 0755, true);
        $tracked['dirs'][] = $srcDir;
        // The tar must contain a top-level entry matching basename($home),
        // since the step extracts with -C parent.
        $homeBase = basename($home);
        @mkdir($srcDir . '/' . $homeBase, 0755, true);
        file_put_contents($srcDir . '/' . $homeBase . '/restored_marker.txt', 'restored');

        $tarPath = $archiveDir . '/home.tar.gz';
        exec('tar -C ' . escapeshellarg($srcDir) . ' -czf ' . escapeshellarg($tarPath) . ' ' . escapeshellarg($homeBase) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'tar not available or failed'];
        }

        $ctx = $ctxWith($b, [
            'archive_path' => $archiveDir,
            'home_dir' => $home,
        ]);
        $step = new HomeDirHydrateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (!is_file($home . '/restored_marker.txt')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'restored marker not extracted'];
        }
        $sentinel = $res->newState->data['sentinel'] ?? '';
        if ($sentinel === '' || !is_file($sentinel)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'sentinel not written'];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after hydrate'];
        }
    });

// ─────────────────────────────────────────────────────────────────
// DatabaseHydrateStep
// ─────────────────────────────────────────────────────────────────

$harness->test('db_hydrate', 'skip_db_hydrate=true short-circuits',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b, ['skip_db_hydrate' => true]);
        $step = new DatabaseHydrateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        if (($res->newState->data['skipped_by_operator'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected skipped_by_operator=true'];
        }
    });

$harness->test('db_hydrate', 'no-op success when site has no db_name',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b);
        $step = new DatabaseHydrateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        if (($res->newState->data['no_db'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected no_db=true'];
        }
    });

$harness->test('db_hydrate', 'fails when dump file missing',
    function () use ($bundle, $ctxWith) {
        $b = $bundle();
        $ctx = $ctxWith($b, [
            'db_name' => 'flowone_test_missing',
            'archive_path' => '/tmp/flowone_test_no_arc_' . bin2hex(random_bytes(2)),
        ]);
        $step = new DatabaseHydrateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if ($res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure with missing dump'];
        }
    });

$harness->test('db_hydrate', 'real restore against an empty test DB',
    function () use ($bundle, $ctxWith, $mysqlCanDestructiveDDL, &$tracked) {
        $b = $bundle();
        if (!$mysqlCanDestructiveDDL($b)) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'no MySQL admin in test context; skipping real-restore path'];
        }
        $dbName = 'flowone_test_hydrate_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $b['mysql']->createDatabase($dbName);
        $tracked['dbs'][] = [$b['mysql'], $dbName];

        // Write a tiny SQL file with a marker table the assertion can
        // verify post-restore.
        $arc = '/tmp/flowone_test_hydrate_arc_' . bin2hex(random_bytes(2));
        @mkdir($arc, 0700, true);
        $tracked['dirs'][] = $arc;
        $dumpPath = $arc . '/' . $dbName . '.sql';
        file_put_contents($dumpPath, "CREATE TABLE marker (id INT PRIMARY KEY); INSERT INTO marker VALUES (42);\n");

        $ctx = $ctxWith($b, [
            'db_name' => $dbName,
            'archive_path' => $arc,
        ]);
        $step = new DatabaseHydrateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        // Verify the marker landed.
        try {
            $cfg = $b['ctx']->database->config();
            $pdo = new \PDO(
                "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$dbName};charset=utf8mb4",
                $cfg['user'], $cfg['password']
            );
            $val = $pdo->query('SELECT id FROM marker')->fetchColumn();
            if ((int) $val !== 42) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'marker row missing'];
            }
        } catch (\Throwable $e) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'verify connect failed: ' . $e->getMessage()];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after hydrate'];
        }
    });

exit($harness->run());
