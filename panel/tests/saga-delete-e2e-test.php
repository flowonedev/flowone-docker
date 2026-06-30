#!/usr/bin/env php
<?php
/**
 * Saga Orchestrator :: DELETE direction end-to-end (Step 4b)
 *
 * Drives the orchestrator across a *subset* of the production DELETE
 * saga that does not require root or live MySQL/SFTP:
 *
 *   PreDeleteSnapshotStep
 *   MailTeardownStep        (vmail/dkim roots sandboxed via payload)
 *   OlsMainConfigRemoveStep
 *   VhostConfigRemoveStep
 *   HomeDirRemoveStep
 *
 * The omitted production steps (DatabaseDropStep, DatabaseUserDropStep,
 * SftpUserRemoveStep, SftpGroupRemoveStep, OlsRestartStep) are
 * exercised by provisioning-steps-delete-test.php in isolation. We do
 * NOT call lswsctrl here because it would reload the live OLS install
 * on the test server. MailTeardownStep's DB-row handling is covered by
 * mail-teardown-step-test.php; here it runs against sandboxed
 * filesystem artifacts only.
 *
 * Coverage:
 *   - happy path: pre-snapshot then teardown removes all sandboxed
 *     artifacts (vhost, home dir, maildir, dkim keys + table lines).
 *     Saga reports SUCCEEDED and a vmail tarball lands in the
 *     snapshot dir.
 *   - skip_snapshot waiver bypasses the snapshot step itself; the
 *     downstream destructive steps still run.
 *   - idempotent re-run: same saga twice produces SUCCEEDED with
 *     every step SKIPPED on the second run.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/saga-delete-e2e-test.php --verbose
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

use VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemorySagaEventSink;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemoryStepStateStore;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOrchestrator;
use VpsAdmin\Agent\Provisioner\Orchestrator\SagaOutcome;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsMainConfigInsertStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\VhostConfigWriteStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\HomeDirRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\MailTeardownStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\OlsMainConfigRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\PreDeleteSnapshotStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\VhostConfigRemoveStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SagaDeleteE2E', $opts);

$activeSandboxes = [];
$activeHomeDirs = [];
$harness->onCleanup(function () use (&$activeSandboxes, &$activeHomeDirs) {
    foreach ($activeHomeDirs as $home) {
        if (is_dir($home) && str_starts_with($home, '/tmp/')) {
            @exec('rm -rf ' . escapeshellarg($home));
        }
    }
    foreach ($activeSandboxes as $bundle) {
        StepTestContext::teardown($bundle);
    }
});

/**
 * Build a sandboxed delete-ready context:
 *   - a vhost.conf written by a real CREATE step
 *   - a vhost block + listener maps inserted into the sandbox main config
 *   - a /tmp home dir with a couple of files in it
 *
 * Returns the bundle plus the populated SiteContext with the right
 * payload for the delete saga.
 */
function buildDeleteBundle(array &$sandboxes, array &$homeDirs, array $payloadExtra = []): array
{
    $domain = 'flowone_test_del_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.local';
    $user = substr('site_' . str_replace('.', '_', $domain), 0, 31);
    $home = '/tmp/' . $user;

    $bundle = StepTestContext::build([
        'domain' => $domain,
        'site_row_overrides' => [
            'sftp_user' => $user,
            'sftp_group' => $user,
            'php_lsapi' => 'lsphp83',
            'admin_email' => 'admin@example.com',
            'home_dir' => $home,
        ],
    ]);
    $sandboxes[] = $bundle;
    $homeDirs[] = $home;

    // 1. Create the vhost.conf the way the create saga would
    (new VhostConfigWriteStep(new VhostConfigTemplate()))->execute(
        $bundle['ctx'],
        \VpsAdmin\Agent\Provisioner\Step\StepState::fresh('vhost_config_write')
    );
    // 2. Insert the main config block
    (new OlsMainConfigInsertStep())->execute(
        $bundle['ctx'],
        \VpsAdmin\Agent\Provisioner\Step\StepState::fresh('ols_main_config_insert')
    );
    // 3. Lay down a fake home dir tree
    @mkdir($home . '/public_html', 0755, true);
    @mkdir($home . '/logs', 0755, true);
    file_put_contents($home . '/public_html/index.html', "<h1>{$domain}</h1>\n");
    file_put_contents($home . '/logs/access.log', "127.0.0.1\n");

    // 3b. Seed sandboxed mail artifacts the way MailAction would:
    //     a non-empty maildir, a DKIM keypair dir and table lines
    //     (plus an unrelated domain that must survive teardown).
    $vmailRoot = $bundle['sandbox_root'] . '/vmail';
    $dkimRoot = $bundle['sandbox_root'] . '/opendkim';
    @mkdir($vmailRoot . '/' . $domain . '/info', 0755, true);
    @mkdir($dkimRoot . '/keys/' . $domain, 0700, true);
    file_put_contents($vmailRoot . '/' . $domain . '/info/dovecot.index', "fake-index\n");
    file_put_contents($dkimRoot . '/keys/' . $domain . '/default.private', "FAKE-KEY\n");
    file_put_contents(
        $dkimRoot . '/SigningTable',
        "*@unrelated.example.com default._domainkey.unrelated.example.com\n"
            . "*@{$domain} default._domainkey.{$domain}\n"
    );
    file_put_contents(
        $dkimRoot . '/KeyTable',
        "default._domainkey.unrelated.example.com unrelated.example.com:default:/x\n"
            . "default._domainkey.{$domain} {$domain}:default:{$dkimRoot}/keys/{$domain}/default.private\n"
    );

    // 4. Rebuild the context with the delete-saga payload, keeping
    //    the same sandbox/adapters but adding snapshot_root etc.
    $payload = array_merge([
        'snapshot_root' => $bundle['sandbox_root'] . '/snapshots',
        'skip_db_snapshot' => true,  // No MySQL admin in e2e test
        'home_dir' => $home,
        'vmail_root' => $vmailRoot,
        'dkim_root' => $dkimRoot,
    ], $payloadExtra);

    $delCtx = new SiteContext(
        siteRow: $bundle['ctx']->siteRow,
        jobId: $bundle['ctx']->jobId,
        requestId: $bundle['ctx']->requestId,
        actor: $bundle['ctx']->actor,
        audit: $bundle['ctx']->audit,
        vault: $bundle['ctx']->vault,
        capabilities: $bundle['ctx']->capabilities,
        database: $bundle['ctx']->database,
        payload: $payload,
        dryRun: false,
        adapters: $bundle['ctx']->adapters,
    );

    return [
        'bundle' => $bundle,
        'ctx' => $delCtx,
        'home' => $home,
        'vmail_root' => $vmailRoot,
        'dkim_root' => $dkimRoot,
    ];
}

function deleteSubsetSaga(): SagaSequence
{
    // Same relative order as the production delete saga:
    // snapshot -> mail teardown -> OLS/vhost/home teardown.
    return new SagaSequence('delete-subset', [
        new PreDeleteSnapshotStep(),
        new MailTeardownStep(),
        new OlsMainConfigRemoveStep(),
        new VhostConfigRemoveStep(),
        new HomeDirRemoveStep(),
    ]);
}

function vhostPath(array $bundle): string
{
    return $bundle['ols_config_root'] . '/vhosts/' . $bundle['ctx']->domain() . '/vhost.conf';
}

// ───────────────────────────────────────────────────────────────────
// preflight
// ───────────────────────────────────────────────────────────────────

$harness->test('preflight', 'pre-delete sandbox contains vhost.conf + home + main config block',
    function () use (&$activeSandboxes, &$activeHomeDirs) {
        $built = buildDeleteBundle($activeSandboxes, $activeHomeDirs);
        $b = $built['bundle'];
        if (!file_exists(vhostPath($b))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'precondition failed: vhost.conf missing at ' . vhostPath($b)];
        }
        if (!is_dir($built['home'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'precondition failed: home dir missing at ' . $built['home']];
        }
        $main = (string) file_get_contents($b['main_config_path']);
        if (!str_contains($main, 'virtualhost ' . $b['ctx']->domain())) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'precondition failed: main config missing vhost block'];
        }
    });

// ───────────────────────────────────────────────────────────────────
// happy path
// ───────────────────────────────────────────────────────────────────

$harness->test('happy', 'orchestrator drives the 4-step DELETE subset to SUCCEEDED',
    function () use (&$activeSandboxes, &$activeHomeDirs) {
        $built = buildDeleteBundle($activeSandboxes, $activeHomeDirs);
        $b = $built['bundle'];
        $store = new InMemoryStepStateStore();
        $sink = new InMemorySagaEventSink();
        $orch = new SagaOrchestrator($store, $sink);

        $result = $orch->run(deleteSubsetSaga(), $built['ctx']);

        if ($result->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED, got ' . $result->outcome->value
                    . ' (' . ($result->failureError ?? '') . ')'];
        }
        if (file_exists(vhostPath($b))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost.conf was not removed'];
        }
        if (is_dir($built['home'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'home dir was not removed'];
        }
        $main = (string) file_get_contents($b['main_config_path']);
        if (str_contains($main, 'virtualhost ' . $b['ctx']->domain())) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'main config still contains the vhost block'];
        }

        // Mail teardown assertions
        $domain = $b['ctx']->domain();
        if (is_dir($built['vmail_root'] . '/' . $domain)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vmail maildir was not removed'];
        }
        if (is_dir($built['dkim_root'] . '/keys/' . $domain)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'dkim key dir was not removed'];
        }
        $signing = (string) file_get_contents($built['dkim_root'] . '/SigningTable');
        if (str_contains($signing, $domain)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'SigningTable still mentions the domain'];
        }
        if (!str_contains($signing, 'unrelated.example.com')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unrelated domain was stripped from SigningTable'];
        }
        $vmailTar = $built['ctx']->payload['snapshot_root'] . '/'
            . $domain . '/' . $built['ctx']->jobId . '/vmail.tar.gz';
        if (!is_file($vmailTar)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "vmail tarball missing at {$vmailTar} (recovery artifact invariant)"];
        }
    });

// ───────────────────────────────────────────────────────────────────
// skip_snapshot waiver
// ───────────────────────────────────────────────────────────────────

$harness->test('waiver', 'skip_snapshot=true short-circuits PreDeleteSnapshotStep via check(); downstream still runs',
    function () use (&$activeSandboxes, &$activeHomeDirs) {
        // skip_mail_snapshot too: otherwise MailTeardownStep tars the
        // seeded maildir into the snapshot dir and the "no snapshot
        // dir under waiver" assertion below would trip on it.
        $built = buildDeleteBundle($activeSandboxes, $activeHomeDirs, [
            'skip_snapshot' => true,
            'skip_mail_snapshot' => true,
        ]);
        $b = $built['bundle'];
        $store = new InMemoryStepStateStore();
        $sink = new InMemorySagaEventSink();
        $orch = new SagaOrchestrator($store, $sink);
        $result = $orch->run(deleteSubsetSaga(), $built['ctx']);

        if ($result->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCEEDED with waiver; got ' . $result->outcome->value];
        }
        if (is_dir($built['home'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'home dir not removed under waiver'];
        }
        if (file_exists(vhostPath($b))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vhost.conf not removed under waiver'];
        }
        if (is_dir($built['vmail_root'] . '/' . $b['ctx']->domain())) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'maildir not removed under waiver'];
        }
        // PreDeleteSnapshotStep's check() returns true under the waiver
        // so the orchestrator records the step as SKIPPED (wasCheckSatisfied)
        // and never calls execute(). No snapshot dir should be created.
        $snapshotRec = null;
        foreach ($result->stepRecords as $rec) {
            if ($rec->stepName === 'pre_delete_snapshot') {
                $snapshotRec = $rec;
                break;
            }
        }
        if ($snapshotRec === null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'pre_delete_snapshot record missing from saga result'];
        }
        if (!$snapshotRec->wasCheckSatisfied) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'pre_delete_snapshot.wasCheckSatisfied=false; expected the waiver to short-circuit check()'];
        }
        if ($snapshotRec->outcome !== StepOutcome::SKIPPED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "pre_delete_snapshot outcome was '{$snapshotRec->outcome->value}'; expected SKIPPED"];
        }
        $expectedDir = $built['ctx']->payload['snapshot_root'] . '/'
            . $built['ctx']->domain() . '/' . $built['ctx']->jobId;
        if (is_dir($expectedDir)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "snapshot dir was created under waiver: {$expectedDir}"];
        }
    });

// ───────────────────────────────────────────────────────────────────
// idempotence
// ───────────────────────────────────────────────────────────────────

$harness->test('happy', 'idempotent re-run reuses store and skips every step on the second pass',
    function () use (&$activeSandboxes, &$activeHomeDirs) {
        $built = buildDeleteBundle($activeSandboxes, $activeHomeDirs);
        $store = new InMemoryStepStateStore();

        $orch1 = new SagaOrchestrator($store, new InMemorySagaEventSink());
        $r1 = $orch1->run(deleteSubsetSaga(), $built['ctx']);
        if ($r1->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first run failed: ' . $r1->outcome->value];
        }

        $orch2 = new SagaOrchestrator($store, new InMemorySagaEventSink());
        $r2 = $orch2->run(deleteSubsetSaga(), $built['ctx']);
        if ($r2->outcome !== SagaOutcome::SUCCEEDED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second run not SUCCEEDED: ' . $r2->outcome->value];
        }

        foreach ($r2->stepRecords as $rec) {
            if (!$rec->wasCheckSatisfied) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "step '{$rec->stepName}' was NOT skipped on second run"];
            }
        }
    });

$harness->run();
