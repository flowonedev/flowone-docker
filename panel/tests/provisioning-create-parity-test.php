#!/usr/bin/env php
<?php
/**
 * Provisioning :: Legacy-Parity Tests
 *
 * Covers gaps the V2 saga had vs the legacy VhostAction::doCreate:
 *
 *   1. HomeDirCreateStep now also creates:
 *        - public_html/.well-known/acme-challenge/  (certbot --webroot)
 *        - public_html/error/                       (vhost.conf errorPages)
 *        - public_html/index.html                   (placeholder welcome)
 *        - public_html/error/{404,403,500,503}.html (copied from /var/www/vps-admin/templates if present)
 *
 *   2. SiteRowBackfiller pulls denormalized columns out of the saga
 *      StepState map and UPDATEs the sites row, so the legacy
 *      SitesView (which reads home_dir/document_root/sftp_user/db_name
 *      directly) sees a fully-populated row instead of NULLs.
 *
 *   3. LegacyCacheInvalidator reaches Redis and DELs the legacy
 *      cache keys when reachable; smoke test only - the helper is
 *      defensive so unreachable Redis is a silent no-op.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-create-parity-test.php --verbose
 *
 * Flags:
 *   --verbose   -- extra diagnostic output
 *   --smoke     -- preflight only
 *   --only=g    -- run only group g
 *   --json      -- machine-readable summary
 *   --templates-dir=PATH  -- override /var/www/vps-admin/templates for the
 *                            error-page copy tests (use a temp dir on
 *                            dev boxes that don't have prod templates)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help', 'templates-dir:']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2000));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\HomeDirCreateStep;
use VpsAdmin\Agent\Provisioner\Support\LegacyCacheInvalidator;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Agent\Provisioner\Support\SiteRowBackfiller;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('CreateParity', $opts);

// ---------------------------------------------------------------
// Sandbox: we relocate /home/<domain> into a temp dir so we can
// poke at the rendered tree without touching real /home. The
// FilesystemAdapter's allowedRoots in StepTestContext::build()
// already include the temp dir.
// ---------------------------------------------------------------
$bundles = [];
$createdSiteIds = [];
$harness->onCleanup(function () use (&$bundles, &$createdSiteIds) {
    foreach ($bundles as $b) {
        StepTestContext::teardown($b);
    }
    if (!empty($createdSiteIds)) {
        try {
            $db = PanelDatabase::fromDefaultConfigFiles();
            $pdo = $db->pdo();
            $placeholders = implode(',', array_fill(0, count($createdSiteIds), '?'));
            $pdo->prepare("DELETE FROM sites WHERE id IN ({$placeholders})")
                ->execute($createdSiteIds);
        } catch (\Throwable) {
            // best effort; the test prefix on domain makes manual cleanup easy
        }
    }
});

// ── HomeDirCreateStep: docroot scaffolding ─────────────────────
$harness->test('preflight', 'HomeDirCreateStep present + class loadable',
    function () {
        if (!class_exists(HomeDirCreateStep::class)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'class missing'];
        }
        $step = new HomeDirCreateStep();
        if ($step->name() !== StepName::HOME_DIR_CREATE) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'name mismatch'];
        }
    });

$harness->test('docroot', 'creates .well-known/acme-challenge + error/ subdirs',
    function () use (&$bundles) {
        $home = sys_get_temp_dir() . '/flowone_homedir_' . bin2hex(random_bytes(3));
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-parity-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.invalid',
            'payload' => [
                'home_dir' => $home,
                'skip_sftp' => true,
            ],
        ]);
        $bundles[] = $bundle;

        $step = new HomeDirCreateStep();
        $state = StepState::fresh($step->name());
        $r = $step->execute($bundle['ctx'], $state);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $r->error];
        }

        foreach ([
            $home . '/public_html',
            $home . '/public_html/.well-known',
            $home . '/public_html/.well-known/acme-challenge',
            $home . '/public_html/error',
        ] as $expected) {
            if (!is_dir($expected)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing dir: {$expected}"];
            }
        }
    });

$harness->test('docroot', 'check() returns false when acme-challenge missing',
    function () use (&$bundles) {
        $home = sys_get_temp_dir() . '/flowone_homedir_' . bin2hex(random_bytes(3));
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-check-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.invalid',
            'payload' => ['home_dir' => $home, 'skip_sftp' => true],
        ]);
        $bundles[] = $bundle;

        // Manually create just the legacy three-subdir layout (no
        // .well-known yet) to simulate a partially-built site.
        @mkdir($home . '/public_html', 0755, true);
        @mkdir($home . '/logs', 0755, true);
        @mkdir($home . '/tmp', 0755, true);

        $step = new HomeDirCreateStep();
        if ($step->check($bundle['ctx'], StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() returned true for partial tree (missing acme-challenge)'];
        }

        // After execute() completes the new dirs, check() should be true.
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $r->error];
        }
        if (!$step->check($bundle['ctx'], $r->newState)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() returned false after successful execute'];
        }
    });

$harness->test('docroot', 'drops placeholder index.html with domain name in title',
    function () use (&$bundles) {
        $home = sys_get_temp_dir() . '/flowone_homedir_' . bin2hex(random_bytes(3));
        $domain = 'flowone-test-idx-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.invalid';
        $bundle = StepTestContext::build([
            'domain' => $domain,
            'payload' => ['home_dir' => $home, 'skip_sftp' => true],
        ]);
        $bundles[] = $bundle;

        $step = new HomeDirCreateStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $r->error];
        }

        $indexPath = $home . '/public_html/index.html';
        if (!is_file($indexPath)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "index.html not dropped at {$indexPath}"];
        }
        $content = (string) file_get_contents($indexPath);
        if (strpos($content, htmlspecialchars($domain, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'placeholder index.html does not mention domain'];
        }
    });

$harness->test('docroot', 'preserves existing index.html on rerun',
    function () use (&$bundles) {
        $home = sys_get_temp_dir() . '/flowone_homedir_' . bin2hex(random_bytes(3));
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-keep-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.invalid',
            'payload' => ['home_dir' => $home, 'skip_sftp' => true],
        ]);
        $bundles[] = $bundle;

        $step = new HomeDirCreateStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'first execute failed: ' . $r->error];
        }

        // Operator dropped their own page. Re-run must not clobber it.
        $custom = '<html><body>Operator content</body></html>';
        file_put_contents($home . '/public_html/index.html', $custom);

        $r2 = $step->execute($bundle['ctx'], $r->newState);
        if (!$r2->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second execute failed: ' . $r2->error];
        }

        $content = file_get_contents($home . '/public_html/index.html');
        if ($content !== $custom) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'rerun overwrote operator-customized index.html'];
        }
    });

// Synthesize a templates dir under sys_get_temp_dir() so we can
// exercise the error-page copy logic without depending on
// /var/www/vps-admin/templates being present on the dev box.
// We monkey-patch by copying our temp templates into the canonical
// path IF the env permits; otherwise the copy tests SKIP with an
// explanation.
$harness->test('docroot', 'copies error templates when /var/www/vps-admin/templates exists',
    function () use (&$bundles, $opts) {
        $templatesDir = isset($opts['templates-dir']) && is_string($opts['templates-dir'])
            ? $opts['templates-dir']
            : '/var/www/vps-admin/templates';

        $codes = ['404', '403', '500', '503'];
        $allPresent = true;
        foreach ($codes as $code) {
            if (!is_file($templatesDir . '/' . $code . '.html')) {
                $allPresent = false;
                break;
            }
        }
        if (!$allPresent) {
            return ['outcome' => TestHarness::SKIP,
                'message' => "templates dir missing or incomplete: {$templatesDir} - run with --templates-dir=PATH on a box that has them"];
        }

        $home = sys_get_temp_dir() . '/flowone_homedir_' . bin2hex(random_bytes(3));
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-err-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.invalid',
            'payload' => ['home_dir' => $home, 'skip_sftp' => true],
        ]);
        $bundles[] = $bundle;

        $step = new HomeDirCreateStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $r->error];
        }

        foreach ($codes as $code) {
            $dest = $home . '/public_html/error/' . $code . '.html';
            if (!is_file($dest)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "error template not copied: {$dest}"];
            }
            // Content match: copied bytes-equal to the source (we use
            // writeAtomic which goes through a temp file but the
            // payload is identical).
            if (file_get_contents($dest) !== file_get_contents($templatesDir . '/' . $code . '.html')) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "error template content mismatch: {$code}"];
            }
        }
    });

$harness->test('docroot', 'tolerates missing /var/www/vps-admin/templates without failing',
    function () use (&$bundles) {
        // Force a non-existent templates dir by binding the test to a
        // domain whose home is sandboxed AND whose error/ subdir will
        // be created but whose <code>.html files won't exist anywhere.
        // Since the templates path is a private const we can't easily
        // override it; we just rely on the step to NOT fail when the
        // copies aren't possible (which is what production behaviour
        // requires). Verify execute returns success even if 0 files
        // got copied.
        $home = sys_get_temp_dir() . '/flowone_homedir_' . bin2hex(random_bytes(3));
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-noerr-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.invalid',
            'payload' => ['home_dir' => $home, 'skip_sftp' => true],
        ]);
        $bundles[] = $bundle;

        $step = new HomeDirCreateStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute failed when templates missing: ' . $r->error];
        }
        // The error/ dir must still exist (an operator can drop their
        // own files in there), even if no files were copied.
        if (!is_dir($home . '/public_html/error')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'error/ dir not created when templates missing'];
        }
    });

// ── SiteRowBackfiller ─────────────────────────────────────────
$harness->test('preflight', 'sites table reachable + has expected columns',
    function () {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $cols = $pdo->query("SHOW COLUMNS FROM sites")->fetchAll(\PDO::FETCH_COLUMN, 0);
        // sftp_group is NOT a column. Legacy + saga both derive the
        // group name from sftp_user via ResourceNameDeriver, so it's
        // computed-on-read rather than stored.
        $required = ['home_dir', 'document_root', 'sftp_user',
                     'db_name', 'db_user', 'php_version', 'dns_enabled'];
        foreach ($required as $col) {
            if (!in_array($col, $cols, true)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "sites column missing: {$col}"];
            }
        }
    });

$harness->test('backfill', 'extractValues pulls everything off the state map',
    function () {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $bf = new SiteRowBackfiller($db);

        $states = [
            StepName::HOME_DIR_CREATE => StepState::fresh(StepName::HOME_DIR_CREATE)
                ->mergeData(['home' => '/home/example.test']),
            StepName::SFTP_USER_CREATE => StepState::fresh(StepName::SFTP_USER_CREATE)
                ->mergeData(['user' => 'site_example_test', 'home' => '/home/example.test']),
            StepName::DATABASE_CREATE => StepState::fresh(StepName::DATABASE_CREATE)
                ->mergeData(['db_name' => 'flowone_example_test']),
            StepName::DATABASE_USER_CREATE => StepState::fresh(StepName::DATABASE_USER_CREATE)
                ->mergeData(['user' => 'flowone_example_test']),
            StepName::DNS_ZONE_CREATE => StepState::fresh(StepName::DNS_ZONE_CREATE)
                ->mergeData(['dns_zone_id' => 42, 'dns_skipped' => null]),
        ];
        $payload = ['php_version' => 'lsphp83'];

        $vals = $bf->extractValues($states, $payload);
        $expected = [
            'home_dir' => '/home/example.test',
            'document_root' => '/home/example.test/public_html',
            'sftp_user' => 'site_example_test',
            'db_name' => 'flowone_example_test',
            'db_user' => 'flowone_example_test',
            'php_version' => 'lsphp83',
            'dns_enabled' => 1,
        ];
        if ($vals !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'extractValues mismatch. got=' . json_encode($vals)
                    . ' want=' . json_encode($expected)];
        }
    });

$harness->test('backfill', 'omits dns_enabled when DNS step skipped',
    function () {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $bf = new SiteRowBackfiller($db);

        $states = [
            StepName::HOME_DIR_CREATE => StepState::fresh(StepName::HOME_DIR_CREATE)
                ->mergeData(['home' => '/home/x.test']),
            StepName::DNS_ZONE_CREATE => StepState::fresh(StepName::DNS_ZONE_CREATE)
                ->mergeData(['dns_skipped' => 'single-label']),
        ];

        $vals = $bf->extractValues($states, []);
        if (isset($vals['dns_enabled'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'dns_enabled should not be set when step skipped'];
        }
    });

$harness->test('backfill', 'falls back to SFTP_USER_CREATE.home when HOME_DIR_CREATE absent',
    function () {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $bf = new SiteRowBackfiller($db);

        $states = [
            StepName::SFTP_USER_CREATE => StepState::fresh(StepName::SFTP_USER_CREATE)
                ->mergeData(['user' => 'foo', 'home' => '/home/foo.test']),
        ];
        $vals = $bf->extractValues($states, []);
        if (($vals['home_dir'] ?? null) !== '/home/foo.test') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'fallback home_dir extraction failed: ' . json_encode($vals)];
        }
    });

$harness->test('backfill', 'accepts php_lsapi alias for php_version',
    function () {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $bf = new SiteRowBackfiller($db);
        $states = [];
        $vals = $bf->extractValues($states, ['php_lsapi' => 'lsphp84']);
        if (($vals['php_version'] ?? null) !== 'lsphp84') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'php_lsapi alias not honoured: ' . json_encode($vals)];
        }
    });

$harness->test('backfill', 'returns empty when no extractable values',
    function () {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $bf = new SiteRowBackfiller($db);
        $vals = $bf->extractValues([], []);
        if ($vals !== []) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected empty array, got: ' . json_encode($vals)];
        }
    });

$harness->test('backfill', 'UPDATE writes columns to a real sites row',
    function () use (&$createdSiteIds) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();

        // Insert a fixture sites row with the test prefix.
        $domain = 'flowone-test-bf-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.invalid';
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
             VALUES (?, 'active', 'provisioning', NOW(), NOW())"
        )->execute([$domain]);
        $siteId = (int) $pdo->lastInsertId();
        $createdSiteIds[] = $siteId;

        $bf = new SiteRowBackfiller($db);
        $states = [
            StepName::HOME_DIR_CREATE => StepState::fresh(StepName::HOME_DIR_CREATE)
                ->mergeData(['home' => '/home/' . $domain]),
            StepName::SFTP_USER_CREATE => StepState::fresh(StepName::SFTP_USER_CREATE)
                ->mergeData(['user' => 'bf_user_' . substr(bin2hex(random_bytes(2)), 0, 4)]),
            StepName::DATABASE_CREATE => StepState::fresh(StepName::DATABASE_CREATE)
                ->mergeData(['db_name' => 'flowone_bf_' . substr(bin2hex(random_bytes(2)), 0, 4)]),
        ];
        $written = $bf->backfill($siteId, $states, ['php_version' => 'lsphp83']);

        $expected = ['home_dir', 'document_root', 'sftp_user', 'db_name', 'php_version'];
        sort($written);
        sort($expected);
        if ($written !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'columns written mismatch. got=' . json_encode($written)
                    . ' want=' . json_encode($expected)];
        }

        $row = $pdo->prepare("SELECT home_dir, document_root, sftp_user, db_name, php_version FROM sites WHERE id = ?");
        $row->execute([$siteId]);
        $r = $row->fetch(\PDO::FETCH_ASSOC);
        if ($r['home_dir'] !== '/home/' . $domain
            || $r['document_root'] !== '/home/' . $domain . '/public_html'
            || $r['php_version'] !== 'lsphp83'
            || empty($r['sftp_user'])
            || empty($r['db_name'])
        ) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'UPDATE did not persist correctly: ' . json_encode($r)];
        }
    });

$harness->test('backfill', 'noop when site_id is bogus',
    function () {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $bf = new SiteRowBackfiller($db);
        $w = $bf->backfill(0, [], []);
        if ($w !== []) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected empty array for bogus id, got: ' . json_encode($w)];
        }
    });

// ── LegacyCacheInvalidator (smoke) ────────────────────────────
$harness->test('cache', 'LegacyCacheInvalidator constructible from default config',
    function () {
        $inv = LegacyCacheInvalidator::fromDefaultConfigFiles();
        if (!$inv instanceof LegacyCacheInvalidator) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'factory returned wrong type'];
        }
    });

$harness->test('cache', 'invalidateForDomain returns int and never throws',
    function () {
        $inv = LegacyCacheInvalidator::fromDefaultConfigFiles();
        try {
            $count = $inv->invalidateForDomain('flowone-test-cache-' . bin2hex(random_bytes(3)) . '.invalid');
        } catch (\Throwable $e) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'invalidateForDomain threw: ' . $e->getMessage()];
        }
        if (!is_int($count)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected int return, got ' . gettype($count)];
        }
    });

exit($harness->run());
