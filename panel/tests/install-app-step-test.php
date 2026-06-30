#!/usr/bin/env php
<?php
/**
 * Provisioning Steps :: InstallAppStep (WordPress)
 *
 * Exercises the new InstallAppStep that wraps WordPressInstaller as the
 * optional final step in the create saga. The step is gated on
 * payload['install_app'] and is DEGRADE_ONLY (failure leaves the
 * otherwise-working empty site behind for retry).
 *
 * The real WordPressInstaller calls WP-CLI + sudo + mysql in production.
 * Tests must NOT touch any of that, so we inject a stub installer that
 * the step uses instead. The stub records the params it received and
 * returns whatever shape the test wants (success / failure).
 *
 * Coverage:
 *   - check() returns true when payload['install_app'] is absent (no-op)
 *   - execute() short-circuits with skipped=true when no install_app
 *   - execute() rejects unsupported app_slug values
 *   - execute() short-circuits when site_applications row already exists
 *     (idempotency for reconciler retries)
 *   - execute() invokes the installer with the expected params (domain,
 *     docroot, site_user, db_*, admin_*)
 *   - execute() vaults the admin password under
 *     site:<domain>/app_wordpress_admin_password
 *   - execute() upserts a site_applications row with the installer's
 *     reported version + admin_url
 *   - re-running execute() does NOT create a duplicate site_applications
 *     row (ON DUPLICATE KEY UPDATE works)
 *   - execute() returns FAILURE when installer reports success=false
 *   - compensate() returns SUCCESS + warning event (DEGRADE_ONLY)
 *
 * All site_applications rows created by this test use a recognisable
 * `flowone-test-*` domain. The cleanup callback nukes them after each
 * run regardless of outcome.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/install-app-step-test.php --verbose
 *
 * Flags:
 *   --help      -- this header
 *   --verbose   -- include stack traces on failures
 *   --smoke     -- run preflight only (skips installer body)
 *   --only=g    -- run only group g (preflight always runs)
 *   --json      -- machine-readable summary
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2200));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Installers\WordPressInstaller;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\App\InstallAppStep;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

/**
 * Subclass that overrides install() so no WP-CLI / sudo / mysql is
 * actually invoked. Records the last set of params it received so
 * tests can assert on them.
 */
final class StubWordPressInstaller extends WordPressInstaller
{
    public array $calls = [];
    public bool $returnSuccess = true;
    public string $stubVersion = '6.7.1';
    public string $stubError = 'simulated installer failure';

    public function __construct()
    {
        parent::__construct([], new class {
            public function warning(string $m): void {}
            public function info(string $m): void {}
            public function error(string $m): void {}
        });
    }

    public function install(array $params, string $actor): array
    {
        $this->calls[] = ['params' => $params, 'actor' => $actor];
        if (!$this->returnSuccess) {
            return ['success' => false, 'error' => $this->stubError];
        }
        return [
            'success' => true,
            'data' => [
                'domain' => $params['domain'],
                'app' => 'wordpress',
                'version' => $this->stubVersion,
                'install_path' => $params['document_root'],
                'admin_url' => "https://{$params['domain']}/wp-admin/",
                'admin_user' => $params['admin_user'],
                'database' => $params['db_name'],
            ],
            'message' => 'stub install success',
        ];
    }
}

$harness = new TestHarness('InstallAppStep', $opts);

$bundles = [];
$createdDomains = [];

$harness->onCleanup(function () use (&$bundles, &$createdDomains): void {
    try {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        foreach ($createdDomains as $domain) {
            try {
                $pdo->prepare('DELETE FROM site_applications WHERE domain = ?')->execute([$domain]);
            } catch (\Throwable) {
            }
            try {
                $pdo->prepare("DELETE FROM secrets_vault WHERE scope = ?")
                    ->execute(['site:' . $domain]);
            } catch (\Throwable) {
            }
        }
    } catch (\Throwable) {
    }
    foreach ($bundles as $b) {
        StepTestContext::teardown($b);
    }
});

// Build a fresh ctx + corresponding $createdDomains entry so cleanup
// catches every domain even when the test fails before recording.
$buildBundle = static function (array $opts = []) use (&$bundles, &$createdDomains): array {
    $domain = $opts['domain'] ?? ('flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid');
    $opts['domain'] = $domain;
    $bundle = StepTestContext::build($opts);
    $bundles[] = $bundle;
    $createdDomains[] = $domain;
    return $bundle;
};

// ─── preflight ──────────────────────────────────────────────────

$harness->test('preflight', 'panel db reachable + site_applications table present',
    function (): void {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        $stmt = $pdo->query("SHOW TABLES LIKE 'site_applications'");
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('site_applications table missing - run migrate_apps.sql first');
        }
        $stmt = $pdo->query("SHOW INDEX FROM site_applications WHERE Key_name = 'uniq_domain_app'");
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException(
                'uniq_domain_app index missing - run migrate_site_applications_unique.sql first');
        }
    });

$harness->test('preflight', 'StepName::INSTALL_APP is registered',
    function (): void {
        if (StepName::INSTALL_APP !== 'install_app') {
            throw new \RuntimeException('unexpected install_app step name: ' . StepName::INSTALL_APP);
        }
        if (!in_array('install_app', StepName::allCreateNames(), true)) {
            throw new \RuntimeException('install_app not in allCreateNames()');
        }
    });

// ─── skip semantics ─────────────────────────────────────────────

$harness->test('skip', 'check() returns true when payload has no install_app',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => []]);
        $step = new InstallAppStep(new StubWordPressInstaller(), []);
        $state = StepState::fresh($step->name());
        if (!$step->check($bundle['ctx'], $state)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() should return true when no install requested (treated as already-satisfied no-op)'];
        }
    });

$harness->test('skip', 'execute() short-circuits with skipped=true when no install_app',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => []]);
        $stub = new StubWordPressInstaller();
        $step = new InstallAppStep($stub, []);
        $state = StepState::fresh($step->name());
        $result = $step->execute($bundle['ctx'], $state);
        if ($result->outcome !== StepOutcome::SUCCESS) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCESS, got ' . $result->outcome->value];
        }
        if (($result->newState->data['skipped'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected state.skipped=true, got ' . json_encode($result->newState->data)];
        }
        if (count($stub->calls) !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'installer should NOT be invoked when no install requested'];
        }
    });

$harness->test('skip', 'rejects unsupported app_slug',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => ['install_app' => ['app_slug' => 'magento']]]);
        $stub = new StubWordPressInstaller();
        $step = new InstallAppStep($stub, []);
        $state = StepState::fresh($step->name());
        $result = $step->execute($bundle['ctx'], $state);
        if ($result->outcome !== StepOutcome::FAILURE) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILURE for unsupported app_slug, got ' . $result->outcome->value];
        }
        if (strpos((string) $result->error, 'magento') === false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected error to mention magento slug, got: ' . $result->error];
        }
        if (count($stub->calls) !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'installer should NOT be invoked for unsupported slug'];
        }
    });

// ─── happy path ────────────────────────────────────────────────

$harness->test('install', 'execute() invokes installer with expected params',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => ['install_app' => [
            'app_slug' => 'wordpress',
            'admin_user' => 'wpadmin',
            'admin_email' => 'me@example.test',
            'site_title' => 'Hello FlowOne',
        ]]]);
        $ctx = $bundle['ctx'];
        $domain = $ctx->domain();

        // Pre-seed the vault with a fake db_password so the step can
        // resolve it without DatabaseUserCreateStep having run.
        $ctx->vault->put('site:' . $domain, 'db_password', 'fake-db-pw-' . bin2hex(random_bytes(4)),
            $ctx->actor, 'test seed');

        $stub = new StubWordPressInstaller();
        $step = new InstallAppStep($stub, []);
        $state = StepState::fresh($step->name());
        $result = $step->execute($ctx, $state);

        if ($result->outcome !== StepOutcome::SUCCESS) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCESS, got ' . $result->outcome->value
                    . ' :: ' . ($result->error ?? '')];
        }
        if (count($stub->calls) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected installer to be invoked once, got ' . count($stub->calls)];
        }
        $params = $stub->calls[0]['params'];
        $checks = [
            'domain' => $domain,
            'document_root' => '/home/' . $domain . '/public_html',
            'site_user' => ResourceNameDeriver::sftpName($domain),
            'admin_user' => 'wpadmin',
            'admin_email' => 'me@example.test',
            'site_title' => 'Hello FlowOne',
            'db_name' => ResourceNameDeriver::dbName($domain),
            'db_user' => ResourceNameDeriver::dbUser($domain),
        ];
        foreach ($checks as $k => $expected) {
            if (($params[$k] ?? null) !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "installer param mismatch: {$k} expected '{$expected}', got '"
                        . ($params[$k] ?? 'NULL') . "'"];
            }
        }
        if (empty($params['admin_password'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'admin_password should be auto-generated when not supplied'];
        }
    });

$harness->test('install', 'execute() upserts site_applications row + vaults admin password',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => ['install_app' => [
            'app_slug' => 'wordpress',
            'admin_user' => 'wpadmin',
            'admin_password' => 'secret-explicit-pw',
        ]]]);
        $ctx = $bundle['ctx'];
        $domain = $ctx->domain();
        $ctx->vault->put('site:' . $domain, 'db_password', 'fake-db-pw', $ctx->actor, 'test seed');

        $stub = new StubWordPressInstaller();
        $stub->stubVersion = '6.7.1';
        $step = new InstallAppStep($stub, []);
        $state = StepState::fresh($step->name());
        $result = $step->execute($ctx, $state);

        if ($result->outcome !== StepOutcome::SUCCESS) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCESS, got ' . $result->outcome->value
                    . ' :: ' . ($result->error ?? '')];
        }

        $pdo = $ctx->database->pdo();
        $row = $pdo->prepare("SELECT * FROM site_applications WHERE domain = ?");
        $row->execute([$domain]);
        $rows = $row->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected exactly 1 site_applications row, got ' . count($rows)];
        }
        $r = $rows[0];
        if (($r['app_slug'] ?? null) !== 'wordpress' || ($r['app_version'] ?? null) !== '6.7.1') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unexpected app_slug/app_version: ' . json_encode($r)];
        }
        if (($r['admin_url'] ?? null) !== "https://{$domain}/wp-admin/") {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unexpected admin_url: ' . ($r['admin_url'] ?? 'NULL')];
        }

        // Admin password vaulted under the expected key.
        $vaulted = $ctx->vault->get('site:' . $domain, 'app_wordpress_admin_password', $ctx->actor);
        if ($vaulted !== 'secret-explicit-pw') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'admin password not vaulted correctly: got "' . $vaulted . '"'];
        }
    });

$harness->test('install', 'second execute() short-circuits with already_installed',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => ['install_app' => ['app_slug' => 'wordpress']]]);
        $ctx = $bundle['ctx'];
        $domain = $ctx->domain();
        $ctx->vault->put('site:' . $domain, 'db_password', 'fake', $ctx->actor, 'seed');

        $stub = new StubWordPressInstaller();
        $step = new InstallAppStep($stub, []);
        $first = $step->execute($ctx, StepState::fresh($step->name()));
        if ($first->outcome !== StepOutcome::SUCCESS) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first execute failed: ' . ($first->error ?? '?')];
        }

        $second = $step->execute($ctx, StepState::fresh($step->name()));
        if ($second->outcome !== StepOutcome::SUCCESS) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second execute should SUCCESS (already_installed), got '
                    . $second->outcome->value];
        }
        if (($second->newState->data['already_installed'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected already_installed=true on second run, got '
                    . json_encode($second->newState->data)];
        }
        if (count($stub->calls) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'installer should only be invoked once for idempotent re-run, got '
                    . count($stub->calls)];
        }
    });

$harness->test('install', 'upsert: re-running with the same site does NOT duplicate the row',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => ['install_app' => ['app_slug' => 'wordpress']]]);
        $ctx = $bundle['ctx'];
        $domain = $ctx->domain();
        $ctx->vault->put('site:' . $domain, 'db_password', 'fake', $ctx->actor, 'seed');

        $stub = new StubWordPressInstaller();
        $step = new InstallAppStep($stub, []);

        // First insert.
        $step->execute($ctx, StepState::fresh($step->name()));

        // Force the idempotency-check branch to be skipped so we
        // exercise the ON DUPLICATE KEY UPDATE path directly. The
        // simplest way is to delete the row, then re-run, then
        // re-insert via a manually-driven second insert. Easier: call
        // upsertAppRecord via reflection a second time.
        $ref = new ReflectionClass($step);
        $method = $ref->getMethod('upsertAppRecord');
        $method->setAccessible(true);
        $method->invoke($step, $ctx, $domain, 'wordpress', '6.8.0',
            '/home/' . $domain . '/public_html',
            "https://{$domain}/wp-admin/", 'newadmin',
            ResourceNameDeriver::dbName($domain));

        $pdo = $ctx->database->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM site_applications WHERE domain = ? AND app_slug = ?");
        $stmt->execute([$domain, 'wordpress']);
        $count = (int) $stmt->fetchColumn();
        if ($count !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected exactly 1 site_applications row after upsert, got ' . $count];
        }

        // Version + admin_user should reflect the second upsert.
        $stmt = $pdo->prepare("SELECT app_version, admin_user FROM site_applications WHERE domain = ?");
        $stmt->execute([$domain]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (($r['app_version'] ?? null) !== '6.8.0' || ($r['admin_user'] ?? null) !== 'newadmin') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'upsert did not update row: ' . json_encode($r)];
        }
    });

// ─── failure path ──────────────────────────────────────────────

$harness->test('failure', 'installer success=false returns FAILURE with no DB row',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => ['install_app' => ['app_slug' => 'wordpress']]]);
        $ctx = $bundle['ctx'];
        $domain = $ctx->domain();
        $ctx->vault->put('site:' . $domain, 'db_password', 'fake', $ctx->actor, 'seed');

        $stub = new StubWordPressInstaller();
        $stub->returnSuccess = false;
        $stub->stubError = 'wp core install failed: rate limited';
        $step = new InstallAppStep($stub, []);

        $result = $step->execute($ctx, StepState::fresh($step->name()));
        if ($result->outcome !== StepOutcome::FAILURE) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILURE, got ' . $result->outcome->value];
        }
        if (strpos((string) $result->error, 'rate limited') === false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected error to surface installer message, got: ' . $result->error];
        }

        // No site_applications row should have been created.
        $pdo = $ctx->database->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM site_applications WHERE domain = ?");
        $stmt->execute([$domain]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'failed install should NOT create a site_applications row'];
        }
    });

$harness->test('failure', 'missing db_password in vault returns FAILURE',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => ['install_app' => ['app_slug' => 'wordpress']]]);
        // Intentionally do NOT vault a db_password.
        $stub = new StubWordPressInstaller();
        $step = new InstallAppStep($stub, []);
        $result = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if ($result->outcome !== StepOutcome::FAILURE) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected FAILURE when db_password missing, got ' . $result->outcome->value];
        }
        if (strpos((string) $result->error, 'db_password') === false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'error should mention db_password, got: ' . $result->error];
        }
        if (count($stub->calls) !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'installer should NOT run if db_password lookup fails'];
        }
    });

// ─── compensate ────────────────────────────────────────────────

$harness->test('compensate', 'compensate() succeeds with a DEGRADE_ONLY warning event',
    function () use ($buildBundle) {
        $bundle = $buildBundle(['payload' => ['install_app' => ['app_slug' => 'wordpress']]]);
        $step = new InstallAppStep(new StubWordPressInstaller(), []);
        $state = StepState::fresh($step->name())->mergeData(['app_slug' => 'wordpress']);
        $result = $step->compensate($bundle['ctx'], $state);
        if ($result->outcome !== StepOutcome::SUCCESS) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SUCCESS, got ' . $result->outcome->value];
        }
        $sawDegrade = false;
        foreach ($result->events as $ev) {
            $msg = is_object($ev) && property_exists($ev, 'message') ? (string) $ev->message : '';
            if (str_contains($msg, 'DEGRADE_ONLY')) {
                $sawDegrade = true;
                break;
            }
        }
        if (!$sawDegrade) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected DEGRADE_ONLY mention in compensate events'];
        }
    });

exit($harness->run());
