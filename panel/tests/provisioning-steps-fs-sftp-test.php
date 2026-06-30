#!/usr/bin/env php
<?php
/**
 * Provisioning Steps :: Filesystem + SFTP Lifecycle
 *
 * Exercises the steps that need real OS-level operations:
 *
 *   - SftpGroupCreateStep  (groupadd / groupdel)
 *   - SftpUserCreateStep   (useradd / userdel)
 *   - HomeDirCreateStep    (mkdir / chown / rmtree)
 *
 * All test data uses the "flowone_test_" prefix so cleanup is safe and
 * grep-distinguishable from real sites.
 *
 * Requires root for the SFTP tests. If not root, those test groups
 * SKIP with a clear message. The HomeDir tests can still run as long
 * as the test user can mkdir under /tmp (always true).
 *
 * Run on server (as root):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-steps-fs-sftp-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\HomeDirCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpGroupCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpUserCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\SftpGroupRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\SftpUserRemoveStep;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ProvisioningStepsFsSftp', $opts);

$isRoot = (function_exists('posix_geteuid') && posix_geteuid() === 0);

// Track artifacts so cleanup runs even on SIGINT.
$created = ['groups' => [], 'users' => [], 'dirs' => [], 'sandboxes' => []];
$harness->onCleanup(function () use (&$created) {
    // Delete users first (which removes their home + group membership),
    // then groups, then dirs. Each step is best-effort.
    foreach ($created['users'] as $u) {
        @exec("userdel -r " . escapeshellarg($u) . " 2>/dev/null");
        @exec("userdel " . escapeshellarg($u) . " 2>/dev/null");
    }
    foreach ($created['groups'] as $g) {
        @exec("groupdel " . escapeshellarg($g) . " 2>/dev/null");
    }
    foreach ($created['dirs'] as $d) {
        if (is_dir($d) && str_starts_with($d, '/tmp/')) {
            @exec("rm -rf " . escapeshellarg($d));
        }
    }
    foreach ($created['sandboxes'] as $b) {
        StepTestContext::teardown($b);
    }
});

$bundle = function () use (&$created): array {
    $b = StepTestContext::build();
    $created['sandboxes'][] = $b;
    return $b;
};

// ── Pre-flight ────────────────────────────────────────────────
$harness->test('preflight', 'root available for SFTP tests',
    function () use ($isRoot) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::WARN,
                'message' => 'not running as root; SFTP tests will SKIP'];
        }
    });

// ── SftpGroupCreateStep lifecycle ─────────────────────────────
$harness->test('sftp_group', 'execute creates group; check flips to true',
    function () use ($bundle, $isRoot, &$created) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        $b = $bundle();
        $payloadGroup = 'flowone_test_g_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $created['groups'][] = $payloadGroup;
        $newSiteRow = array_merge($b['ctx']->siteRow, []);
        $ctx = StepTestContext::withUpdatedSiteRow($b['ctx'], $newSiteRow);
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $ctx->siteRow,
            jobId: $ctx->jobId,
            requestId: $ctx->requestId,
            actor: $ctx->actor,
            audit: $ctx->audit,
            vault: $ctx->vault,
            capabilities: $ctx->capabilities,
            database: $ctx->database,
            payload: ['sftp_group' => $payloadGroup],
            dryRun: false,
            adapters: $ctx->adapters,
        );

        $step = new SftpGroupCreateStep();
        $state = StepState::fresh($step->name());
        if ($step->check($ctx, $state)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'group already exists'];
        }
        $res = $step->execute($ctx, $state);
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (($res->newState->data['group'] ?? null) !== $payloadGroup) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'state.group not persisted'];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check should be true after create'];
        }
    });

$harness->test('sftp_group', 'second execute is no-op (groupadd already present)',
    function () use ($bundle, $isRoot, &$created) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        $b = $bundle();
        $payloadGroup = 'flowone_test_g_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $created['groups'][] = $payloadGroup;
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['sftp_group' => $payloadGroup], adapters: $b['ctx']->adapters,
        );
        $step = new SftpGroupCreateStep();
        $first = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$first->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'first failed'];
        }
        $second = $step->execute($ctx, $first->newState);
        if (!$second->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second failed'];
        }
    });

$harness->test('sftp_group', 'compensate removes the group',
    function () use ($bundle, $isRoot, &$created) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        $b = $bundle();
        $payloadGroup = 'flowone_test_g_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $created['groups'][] = $payloadGroup;
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['sftp_group' => $payloadGroup], adapters: $b['ctx']->adapters,
        );
        $step = new SftpGroupCreateStep();
        $exec = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$exec->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        $comp = $step->compensate($ctx, $exec->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed'];
        }
        if ($step->check($ctx, $exec->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'group still present after compensate'];
        }
    });

// ── SftpUserCreateStep lifecycle (needs group from prev step) ─
$harness->test('sftp_user', 'execute creates user with derived home',
    function () use ($bundle, $isRoot, &$created) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        $b = $bundle();
        $g = 'flowone_test_ug_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $u = 'flowone_test_u_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $home = '/tmp/' . $u;
        $created['groups'][] = $g;
        $created['users'][] = $u;
        $created['dirs'][] = $home;
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['sftp_group' => $g, 'sftp_user' => $u, 'home_dir' => $home],
            adapters: $b['ctx']->adapters,
        );
        // Need to create group first.
        (new SftpGroupCreateStep())->execute($ctx, StepState::fresh('sftp_group_create'));

        $step = new SftpUserCreateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check false after create'];
        }
        if (($res->newState->data['user'] ?? null) !== $u) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'state.user not set'];
        }
    });

$harness->test('sftp_user', 'compensate removes the user',
    function () use ($bundle, $isRoot, &$created) {
        if (!$isRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'requires root'];
        }
        $b = $bundle();
        $g = 'flowone_test_ug_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $u = 'flowone_test_u_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $home = '/tmp/' . $u;
        $created['groups'][] = $g;
        $created['users'][] = $u;
        $created['dirs'][] = $home;
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['sftp_group' => $g, 'sftp_user' => $u, 'home_dir' => $home],
            adapters: $b['ctx']->adapters,
        );
        (new SftpGroupCreateStep())->execute($ctx, StepState::fresh('sftp_group_create'));
        $step = new SftpUserCreateStep();
        $exec = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$exec->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed'];
        }
        $comp = $step->compensate($ctx, $exec->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed'];
        }
        if ($step->check($ctx, $exec->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'user still present after compensate'];
        }
    });

// ── HomeDirCreateStep lifecycle ───────────────────────────────
$harness->test('home_dir', 'creates the home tree with subdirs',
    function () use ($bundle, &$created) {
        $b = $bundle();
        // Use a /tmp-based home so we don't need real user/group on the system.
        // /tmp is already on the FilesystemAdapter's allowed-roots list via
        // StepTestContext::build(); do NOT add $home itself as a root or
        // rmtree() will refuse to delete it on compensate (the adapter
        // refuses to delete an allowed root, only paths UNDER it).
        $u = posix_getpwuid(posix_geteuid())['name'] ?? 'root';
        $g = posix_getgrgid(posix_getegid())['name'] ?? 'root';
        $home = '/tmp/flowone_test_home_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $created['dirs'][] = $home;

        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['sftp_user' => $u, 'sftp_group' => $g, 'home_dir' => $home],
            adapters: $b['ctx']->adapters,
        );
        $step = new HomeDirCreateStep();
        $res = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$res->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $res->error];
        }
        foreach (['', '/public_html', '/logs', '/tmp'] as $sub) {
            if (!is_dir($home . $sub)) {
                return ['outcome' => TestHarness::FAIL, 'message' => "missing subdir {$sub}"];
            }
        }
        if (!$step->check($ctx, $res->newState)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'check false after create'];
        }
    });

$harness->test('home_dir', 'compensate removes the tree when it was empty at create',
    function () use ($bundle, &$created) {
        $b = $bundle();
        $u = posix_getpwuid(posix_geteuid())['name'] ?? 'root';
        $g = posix_getgrgid(posix_getegid())['name'] ?? 'root';
        $home = '/tmp/flowone_test_home_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $created['dirs'][] = $home;

        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['sftp_user' => $u, 'sftp_group' => $g, 'home_dir' => $home],
            adapters: $b['ctx']->adapters,
        );
        $step = new HomeDirCreateStep();
        $exec = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$exec->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $exec->error];
        }
        $comp = $step->compensate($ctx, $exec->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed'];
        }
        if (is_dir($home)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'home tree still present after compensate'];
        }
    });

// ── skip_sftp short-circuit (no root required) ────────────────
// The payload flag set by CreateSiteV2Modal when the operator unchecks
// "Create SFTP user" - both SFTP steps must report "already satisfied"
// from check() without ever touching the OS, and HomeDir/Vhost must
// resolve owner to www-data:www-data instead of throwing.

$harness->test('skip_sftp', 'SftpGroupCreateStep::check returns true when skip_sftp is set',
    function () use ($bundle) {
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['skip_sftp' => true], adapters: $b['ctx']->adapters,
        );
        $step = new SftpGroupCreateStep();
        $state = StepState::fresh($step->name());
        if (!$step->check($ctx, $state)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check should return true when skip_sftp is set (so orchestrator records SKIPPED)'];
        }
    });

$harness->test('skip_sftp', 'SftpUserCreateStep::check returns true when skip_sftp is set',
    function () use ($bundle) {
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['skip_sftp' => true], adapters: $b['ctx']->adapters,
        );
        $step = new SftpUserCreateStep();
        $state = StepState::fresh($step->name());
        if (!$step->check($ctx, $state)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check should return true when skip_sftp is set'];
        }
    });

$harness->test('skip_sftp', 'SftpGroupCreateStep::check still runs groupExists when skip_sftp is absent',
    function () use ($bundle) {
        // Sanity: the skip-guard must not silently short-circuit
        // every call. With payload={} the check must reach the
        // adapter (which will return false for a non-existent group).
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            // SftpAdapter::NAME_REGEX caps at 31 chars; keep the
            // sentinel name short so the safe-name guard does not
            // throw before we reach the existence probe.
            payload: ['sftp_group' => 'flowone_no_g_' . substr(bin2hex(random_bytes(3)), 0, 6)],
            adapters: $b['ctx']->adapters,
        );
        $step = new SftpGroupCreateStep();
        if ($step->check($ctx, StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check unexpectedly returned true for a non-existent group'];
        }
    });

$harness->test('skip_sftp', 'HomeDirCreateStep resolveOwnerSpec falls back to www-data:www-data',
    function () use ($bundle) {
        // resolveOwnerSpec is private; reach in via reflection so we
        // can assert the fallback owner without needing root or the
        // www-data account to exist on the test box.
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['skip_sftp' => true],
            adapters: $b['ctx']->adapters,
        );
        $step = new HomeDirCreateStep();
        $ref = new \ReflectionClass($step);
        $method = $ref->getMethod('resolveOwnerSpec');
        $method->setAccessible(true);
        try {
            $owner = $method->invoke($step, $ctx);
        } catch (\Throwable $e) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'resolveOwnerSpec threw with skip_sftp=true: ' . $e->getMessage()];
        }
        if ($owner !== 'www-data:www-data') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected www-data:www-data, got '{$owner}'"];
        }
    });

$harness->test('skip_sftp',
    'HomeDirCreateStep derives owner via helper when no SFTP knobs are present',
    function () use ($bundle) {
        // Updated contract (Job #543 fix): when skip_sftp is FALSE and
        // no explicit user/group is supplied, resolveOwnerSpec falls
        // through to ResourceNameDeriver::sftpName() instead of
        // throwing. This is the right behavior because the derived
        // name is byte-identical to what SftpUserCreateStep produced
        // in the immediately-preceding step. The previous "throw on
        // missing" behavior was wrong: production never has those
        // hints set, since siteRow.state hydration isn't implemented
        // in the orchestrator yet.
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: [],
            adapters: $b['ctx']->adapters,
        );
        $step = new HomeDirCreateStep();
        $ref = new \ReflectionClass($step);
        $method = $ref->getMethod('resolveOwnerSpec');
        $method->setAccessible(true);
        try {
            $owner = $method->invoke($step, $ctx);
        } catch (\Throwable $e) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'resolveOwnerSpec threw without skip_sftp - it should derive: '
                    . $e->getMessage()];
        }
        $sftp = ResourceNameDeriver::sftpName($ctx->domain());
        $expected = $sftp . ':' . $sftp;
        if ($owner !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected '{$expected}', got '{$owner}'"];
        }
    });

$harness->test('home_dir', 'compensate REFUSES to delete pre-existing content',
    function () use ($bundle, &$created) {
        $b = $bundle();
        $u = posix_getpwuid(posix_geteuid())['name'] ?? 'root';
        $g = posix_getgrgid(posix_getegid())['name'] ?? 'root';
        $home = '/tmp/flowone_test_home_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $created['dirs'][] = $home;
        // Pre-populate the home dir.
        mkdir($home, 0755, true);
        file_put_contents($home . '/user_content.txt', 'precious data');

        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: $b['ctx']->siteRow, jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: ['sftp_user' => $u, 'sftp_group' => $g, 'home_dir' => $home],
            adapters: $b['ctx']->adapters,
        );
        $step = new HomeDirCreateStep();
        $exec = $step->execute($ctx, StepState::fresh($step->name()));
        if (!$exec->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $exec->error];
        }
        if (($exec->newState->data['was_empty_at_create'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'was_empty_at_create should be false (precious content present)'];
        }
        $comp = $step->compensate($ctx, $exec->newState);
        if (!$comp->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate should succeed (no-op)'];
        }
        if (!file_exists($home . '/user_content.txt')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'precious data WAS DELETED!'];
        }
    });

// ── ResourceNameDeriver (the single source of truth for SFTP names)
//
// These don't need root or sandboxes. The behaviour they verify is
// the algorithm previously inlined across 4 saga steps with
// "MUST match byte-for-byte" comments and no enforcement - which
// regressed in production (Job #526) when SftpUserCreateStep
// couldn't resolve the group SftpGroupCreateStep just made.

$harness->test('name_deriver', 'sftpName: short domain -> site_<sanitized> without hash',
    function () {
        $got = ResourceNameDeriver::sftpName('test2.com');
        if ($got !== 'site_test2_com') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected 'site_test2_com', got '{$got}'"];
        }
    });

$harness->test('name_deriver', 'sftpName: dotted and dashed forms collapse identically',
    function () {
        // The sanitiser maps both "." and "-" to "_", so these two
        // distinct domains share an sftp name. That's intentional;
        // operators who collocate these on one host must use the
        // explicit payload override (sftp_user / sftp_group) to keep
        // them apart.
        $a = ResourceNameDeriver::sftpName('foo.bar.com');
        $b = ResourceNameDeriver::sftpName('foo-bar-com');
        if ($a !== $b) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected dotted=dashed, got '{$a}' vs '{$b}'"];
        }
    });

$harness->test('name_deriver', 'sftpName: caps at 31 chars with stable 6-hex suffix',
    function () {
        $long = str_repeat('a', 80) . '.com';
        $got = ResourceNameDeriver::sftpName($long);
        if (strlen($got) > 31) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "length " . strlen($got) . " > 31 for '{$got}'"];
        }
        if (!str_starts_with($got, 'site_')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected 'site_' prefix, got '{$got}'"];
        }
        // Hash suffix must be stable across invocations.
        $again = ResourceNameDeriver::sftpName($long);
        if ($got !== $again) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "non-deterministic: '{$got}' != '{$again}'"];
        }
    });

$harness->test('name_deriver', 'sftpName: empty / weird domain throws',
    function () {
        $threw = false;
        try {
            ResourceNameDeriver::sftpName('...');
        } catch (\RuntimeException) {
            $threw = true;
        }
        if (!$threw) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'should have thrown on a domain that sanitises to empty'];
        }
    });

$harness->test('name_deriver', 'dbName uses 64-char limit with flowone_ prefix',
    function () {
        if (ResourceNameDeriver::dbName('foo.com') !== 'flowone_foo_com') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'short domain mapping wrong'];
        }
        $long = str_repeat('z', 100) . '.com';
        $got = ResourceNameDeriver::dbName($long);
        if (strlen($got) > 64) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "length " . strlen($got) . " > 64"];
        }
    });

$harness->test('name_deriver', 'dbUser uses 32-char limit with fo_ prefix',
    function () {
        if (ResourceNameDeriver::dbUser('foo.com') !== 'fo_foo_com') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'short domain mapping wrong'];
        }
        $long = str_repeat('y', 100) . '.com';
        $got = ResourceNameDeriver::dbUser($long);
        if (strlen($got) > 32) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "length " . strlen($got) . " > 32"];
        }
    });

// ── Cross-step regression for Job #526
//
// Production failed because SftpUserCreateStep::resolveGroupName()
// had no derive-from-domain fallback. SftpGroupCreateStep made the
// group via its own derivation, then SftpUserCreateStep tried to
// look it up via siteRow.state JSON (which isn't hydrated), and
// when that lookup missed it threw "cannot resolve primary group".
//
// We exercise the resolvers via reflection - they're private but
// they ARE the contract between the two steps. Asserting they
// produce the same name from the same domain (with no payload /
// row hints) prevents this regression class.

$harness->test('regression_job526',
    'SftpGroupCreateStep and SftpUserCreateStep resolve to the same name for same domain',
    function () use ($bundle) {
        $b = $bundle();

        // Build a clean SiteContext with NO explicit payload hints,
        // matching what CreateSiteV2Modal sends today.
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: array_merge($b['ctx']->siteRow, ['domain' => 'test2.com']),
            jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: [],
            adapters: $b['ctx']->adapters,
        );

        $groupStep = new SftpGroupCreateStep();
        $userStep = new SftpUserCreateStep();

        $rGroup = new \ReflectionClass($groupStep);
        $rUser = new \ReflectionClass($userStep);

        $mGroup = $rGroup->getMethod('resolveGroupName');
        $mGroup->setAccessible(true);
        $mUser = $rUser->getMethod('resolveGroupName');
        $mUser->setAccessible(true);

        $derivedByGroupStep = $mGroup->invoke($groupStep, $ctx, StepState::fresh($groupStep->name()));
        $derivedByUserStep = $mUser->invoke($userStep, $ctx);

        if ($derivedByGroupStep !== $derivedByUserStep) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "user-step and group-step disagree on the group name: "
                    . "group='{$derivedByGroupStep}' user='{$derivedByUserStep}'"];
        }
        if ($derivedByGroupStep !== 'site_test2_com') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected 'site_test2_com', got '{$derivedByGroupStep}'"];
        }
    });

$harness->test('regression_job526',
    'SftpGroupRemoveStep resolves to the same name as SftpGroupCreateStep made',
    function () use ($bundle) {
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: array_merge($b['ctx']->siteRow, ['domain' => 'test2.com']),
            jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: [],
            adapters: $b['ctx']->adapters,
        );
        // Both should converge through the helper.
        $createStep = new SftpGroupCreateStep();
        $removeStep = new SftpGroupRemoveStep();
        $created = (new \ReflectionClass($createStep))
            ->getMethod('resolveGroupName');
        $created->setAccessible(true);
        $removed = (new \ReflectionClass($removeStep))
            ->getMethod('resolveGroupName');
        $removed->setAccessible(true);
        $a = $created->invoke($createStep, $ctx, StepState::fresh($createStep->name()));
        $b2 = $removed->invoke($removeStep, $ctx, StepState::fresh($removeStep->name()));
        if ($a !== $b2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "DELETE saga will miss the group: create='{$a}' remove='{$b2}'"];
        }
    });

$harness->test('regression_job526',
    'SftpUserRemoveStep resolves to the same name as SftpUserCreateStep made',
    function () use ($bundle) {
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: array_merge($b['ctx']->siteRow, ['domain' => 'test2.com']),
            jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: [],
            adapters: $b['ctx']->adapters,
        );
        $createStep = new SftpUserCreateStep();
        $removeStep = new SftpUserRemoveStep();
        $createMethod = (new \ReflectionClass($createStep))->getMethod('resolveUserName');
        $createMethod->setAccessible(true);
        $removeMethod = (new \ReflectionClass($removeStep))->getMethod('resolveUserName');
        $removeMethod->setAccessible(true);
        $a = $createMethod->invoke($createStep, $ctx, StepState::fresh($createStep->name()));
        $b2 = $removeMethod->invoke($removeStep, $ctx, StepState::fresh($removeStep->name()));
        if ($a !== $b2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "DELETE saga will miss the user: create='{$a}' remove='{$b2}'"];
        }
    });

// ── Job #543: same anti-pattern in three more steps. Their
// `resolve…` helpers used to read from siteRow.state JSON and throw
// when it was empty - which it always is in production because the
// orchestrator never hydrates that field. The fix in each step is
// the same: fall through to ResourceNameDeriver so the resolved
// name matches what the upstream create step actually produced.

$harness->test('regression_job543',
    'HomeDirCreateStep resolveGroupName derives via helper when no hints present',
    function () use ($bundle) {
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: array_merge($b['ctx']->siteRow, ['domain' => 'test3.com']),
            jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: [],
            adapters: $b['ctx']->adapters,
        );
        $step = new HomeDirCreateStep();
        $m = (new \ReflectionClass($step))->getMethod('resolveGroupName');
        $m->setAccessible(true);
        $got = $m->invoke($step, $ctx);
        $expected = ResourceNameDeriver::sftpName('test3.com');
        if ($got !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected '{$expected}', got '{$got}'"];
        }
    });

$harness->test('regression_job543',
    'HomeDirCreateStep resolveOwnerSpec derives user:group via helper when no hints present',
    function () use ($bundle) {
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: array_merge($b['ctx']->siteRow, ['domain' => 'test3.com']),
            jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: [],
            adapters: $b['ctx']->adapters,
        );
        $step = new HomeDirCreateStep();
        $m = (new \ReflectionClass($step))->getMethod('resolveOwnerSpec');
        $m->setAccessible(true);
        $got = $m->invoke($step, $ctx);
        $sftp = ResourceNameDeriver::sftpName('test3.com');
        $expected = $sftp . ':' . $sftp;
        if ($got !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected '{$expected}', got '{$got}'"];
        }
    });

$harness->test('regression_job543',
    'VhostConfigWriteStep collectTemplateVars derives site_user/group via helper when no hints',
    function () use ($bundle) {
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: array_merge($b['ctx']->siteRow, ['domain' => 'test3.com']),
            jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: [],
            adapters: $b['ctx']->adapters,
        );
        $step = new \VpsAdmin\Agent\Provisioner\Step\Steps\Create\VhostConfigWriteStep(
            new \VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate()
        );
        $m = (new \ReflectionClass($step))->getMethod('collectTemplateVars');
        $m->setAccessible(true);
        $vars = $m->invoke($step, $ctx);
        $expected = ResourceNameDeriver::sftpName('test3.com');
        if (($vars['site_user'] ?? null) !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "site_user: expected '{$expected}', got '" . ($vars['site_user'] ?? 'NULL') . "'"];
        }
        if (($vars['site_group'] ?? null) !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "site_group: expected '{$expected}', got '" . ($vars['site_group'] ?? 'NULL') . "'"];
        }
    });

$harness->test('regression_job543',
    'DatabaseGrantStep resolveBundle derives db_name + user via helper when no hints',
    function () use ($bundle) {
        $b = $bundle();
        $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
            siteRow: array_merge($b['ctx']->siteRow, ['domain' => 'test3.com']),
            jobId: $b['ctx']->jobId, requestId: $b['ctx']->requestId,
            actor: $b['ctx']->actor, audit: $b['ctx']->audit, vault: $b['ctx']->vault,
            capabilities: $b['ctx']->capabilities, database: $b['ctx']->database,
            payload: [],
            adapters: $b['ctx']->adapters,
        );
        $step = new \VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseGrantStep();
        $m = (new \ReflectionClass($step))->getMethod('resolveBundle');
        $m->setAccessible(true);
        $bundle2 = $m->invoke($step, $ctx, StepState::fresh($step->name()));
        $expectedDb = ResourceNameDeriver::dbName('test3.com');
        $expectedUser = ResourceNameDeriver::dbUser('test3.com');
        if (($bundle2['db_name'] ?? null) !== $expectedDb) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "db_name: expected '{$expectedDb}', got '" . ($bundle2['db_name'] ?? 'NULL') . "'"];
        }
        if (($bundle2['user'] ?? null) !== $expectedUser) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "user: expected '{$expectedUser}', got '" . ($bundle2['user'] ?? 'NULL') . "'"];
        }
        if (($bundle2['host'] ?? null) !== 'localhost') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "host: expected 'localhost', got '" . ($bundle2['host'] ?? 'NULL') . "'"];
        }
    });

exit($harness->run());
