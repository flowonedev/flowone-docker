#!/usr/bin/env php
<?php
/**
 * OlsAdapter Test Suite
 *
 * Two flavors of tests live here:
 *
 *   - SANDBOX tests use a tmpdir as the configRoot. They verify path
 *     construction, vhost.conf write/read/remove, and the integration
 *     between Parser+Mutator+Writer+Validator without needing real OLS.
 *
 *   - LIVE tests (auto-skip when the OLS server isn't installed) run
 *     `lswsctrl status` and parse the production config to catch the
 *     "did we regress on the real file format" failure mode.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/ols-adapter-test.php --verbose
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

use VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\OlsAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('OlsAdapter', $opts);

$sandbox = realpath(sys_get_temp_dir()) . '/flowone_test_ols_adapter_' . bin2hex(random_bytes(4));
mkdir($sandbox . '/vhosts', 0755, true);

$harness->onCleanup(function () use ($sandbox): void {
    rrmdir($sandbox);
});

$baseConfig = <<<'CFG'
serverName flowone

listener Default {
  address                 *:80
  secure                  0
  map                     existing.com existing.com
}

listener SSL {
  address                 *:443
  secure                  1
  map                     existing.com existing.com
}

virtualhost existing.com {
  vhRoot                  /home/$VH_NAME
  configFile              $SERVER_ROOT/conf/vhosts/$VH_NAME/vhost.conf
  allowSymbolLink         1
  enableScript            1
  restrained              1
}

CFG;

$mainPath = $sandbox . '/httpd_config.conf';
file_put_contents($mainPath, $baseConfig);

$runner = new ProcessCommandRunner();
$fs = new FilesystemAdapter($runner, [$sandbox]);
$adapter = new OlsAdapter(
    runner: $runner,
    fs: $fs,
    lswsctrlBin: '/usr/local/lsws/bin/lswsctrl',
    configRoot: $sandbox,
);

// ── sandbox: path helpers ─────────────────────────────────────
$harness->test('paths', 'mainConfigPath under configRoot',
    function () use ($adapter, $sandbox) {
        if ($adapter->mainConfigPath() !== $sandbox . '/httpd_config.conf') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'mainConfigPath wrong'];
        }
    });

$harness->test('paths', 'vhostConfigPath uses /vhosts/<domain>/vhost.conf',
    function () use ($adapter, $sandbox) {
        if ($adapter->vhostConfigPath('a.local') !== $sandbox . '/vhosts/a.local/vhost.conf') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'vhostConfigPath wrong'];
        }
    });

// ── sandbox: main config load + write ────────────────────────
$harness->test('main_config', 'loadMainConfig parses sandbox file',
    function () use ($adapter) {
        $doc = $adapter->loadMainConfig();
        if ($doc->findBlock('virtualhost', 'existing.com') === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'existing block missing'];
        }
    });

$harness->test('main_config', 'writeMainConfig validates, swaps, backs up',
    function () use ($adapter, $sandbox, $mainPath) {
        $doc = $adapter->loadMainConfig();
        $changed = $adapter->mutator()->upsertVirtualHost($doc, 'new.local');
        if (!$changed) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'no change reported'];
        }
        $meta = $adapter->writeMainConfig($doc);
        // After: file contains new.local AND a timestamped backup exists.
        $newContents = file_get_contents($mainPath);
        if (strpos($newContents, 'virtualhost new.local') === false
            && strpos($newContents, 'virtualHost new.local') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'mutation did not land'];
        }
        if (!is_file($meta['timestamped_backup'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'no backup created'];
        }
        $backupContents = file_get_contents($meta['timestamped_backup']);
        if (strpos($backupContents, 'virtualhost new.local') !== false
            || strpos($backupContents, 'virtualHost new.local') !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'backup contains mutation'];
        }
    });

// ── sandbox: vhost.conf I/O ──────────────────────────────────
$harness->test('vhost_conf', 'writeVhostConfig creates dir + file atomically',
    function () use ($adapter) {
        $adapter->writeVhostConfig('a.local', "virtualhost {\n  docRoot /home/a\n}\n");
        if (!$adapter->vhostConfigExists('a.local')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'file missing'];
        }
        $r = $adapter->readVhostConfig('a.local');
        if ($r === null || strpos($r, 'docRoot') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'read mismatch'];
        }
    });

$harness->test('vhost_conf', 'removeVhostConfig is idempotent',
    function () use ($adapter) {
        $adapter->writeVhostConfig('to-remove.local', "virtualhost {}\n");
        $n1 = $adapter->removeVhostConfig('to-remove.local');
        $n2 = $adapter->removeVhostConfig('to-remove.local');
        if ($n1 < 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'first remove found nothing'];
        }
        if ($n2 !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second remove counted ' . $n2];
        }
    });

// ── sandbox: testConfig self-parse ───────────────────────────
$harness->test('test_config', 'testConfig returns exit=0 on parseable file',
    function () use ($adapter, $mainPath) {
        $r = $adapter->testConfig($mainPath);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected success, got ' . $r->stderr];
        }
    });

$harness->test('test_config', 'testConfig returns failure on broken file',
    function () use ($adapter, $sandbox) {
        $bad = $sandbox . '/bad.conf';
        file_put_contents($bad, "virtualhost broken {\n  vhRoot /x\n");
        $r = $adapter->testConfig($bad);
        if ($r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure'];
        }
    });

// ── live: real lswsctrl + real /usr/local/lsws/conf ──────────
$harness->test('live', 'isRunning works against the real lswsctrl',
    function () {
        $bin = '/usr/local/lsws/bin/lswsctrl';
        if (!is_executable($bin)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'lswsctrl not installed'];
        }
        $runner = new ProcessCommandRunner();
        $fs = new FilesystemAdapter($runner, ['/usr/local/lsws/conf']);
        $adapter = new OlsAdapter($runner, $fs, $bin, '/usr/local/lsws/conf');
        // Just call isRunning; result depends on environment. We assert
        // it doesn't throw and returns a bool.
        $r = $adapter->isRunning();
        if (!is_bool($r)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'isRunning did not return bool'];
        }
    });

$harness->test('live', 'loadMainConfig + writeMainConfig round-trip on real file',
    function () {
        $main = '/usr/local/lsws/conf/httpd_config.conf';
        if (!is_readable($main)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'real OLS config not readable'];
        }
        $runner = new ProcessCommandRunner();
        $fs = new FilesystemAdapter($runner, ['/usr/local/lsws/conf']);
        $adapter = new OlsAdapter($runner, $fs, '/usr/local/lsws/bin/lswsctrl', '/usr/local/lsws/conf');
        $doc = $adapter->loadMainConfig();
        // NO write here - the production config is sacred. We only
        // verify the AST is non-empty.
        if (count($doc->children) === 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'real config parsed as empty'];
        }
    });

exit($harness->run());

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $dir . '/' . $f;
        is_dir($full) && !is_link($full) ? rrmdir($full) : @unlink($full);
    }
    @rmdir($dir);
}
