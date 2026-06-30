#!/usr/bin/env php
<?php
/**
 * NAS Setup Wizard Test Suite
 *
 * Covers the One-Click NAS Wizard backend chain:
 *   - registration: new agent methods (nas.preflight, nas.persist,
 *     vpn.ensure, vpn.update) are wired and backup flags are correct
 *   - fstab:        pure fstab rewrite logic (idempotency, boot-safety
 *     options, comment/CRLF handling) - never touches /etc/fstab
 *   - actions:      validation + error paths through the real action
 *     router, plus local-driver mount-dir creation in a sandbox
 *   - chain:        local-driver test/stats roundtrip in the sandbox
 *
 * Non-destructive: all filesystem work happens in a flowone_test_*
 * tmpdir; nas.persist is only exercised through its validation errors
 * so the real /etc/fstab is never read or written.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/nas-wizard-test.php --verbose
 *
 * Flags: --help --verbose --smoke --json --skip-send --only=group1,group2
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

use VpsAdmin\Agent\Actions\NASAction;
use VpsAdmin\Agent\Actions\VPNAction;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('NasWizard', $opts);

$sandbox = realpath(sys_get_temp_dir()) . '/flowone_test_naswizard_' . bin2hex(random_bytes(4));
mkdir($sandbox, 0755, true);
mkdir($sandbox . '/backups', 0755, true);

$harness->onCleanup(function () use ($sandbox): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($sandbox);
});

/** @var NASAction|null $nas */
$nas = null;
/** @var VPNAction|null $vpn */
$vpn = null;

// ── preflight: environment + wiring ──────────────────────────────────

$harness->test('preflight', 'required PHP extensions loaded', function () {
    foreach (['pcre', 'json'] as $ext) {
        if (!extension_loaded($ext)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "missing extension: {$ext}"];
        }
    }
});

$harness->test('preflight', 'sandbox tmpdir is writable', function () use ($sandbox) {
    $probe = $sandbox . '/flowone_test_probe';
    if (@file_put_contents($probe, 'x') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => "cannot write {$sandbox}"];
    }
    @unlink($probe);
});

$harness->test('preflight', 'agent action classes instantiate', function () use (&$nas, &$vpn, $sandbox) {
    $config = [
        'paths' => ['backups' => $sandbox . '/backups'],
        'logging' => ['file' => $sandbox . '/agent.log', 'level' => 'error'],
        'backup' => ['max_age_days' => 1, 'max_count' => 5],
    ];
    $logger = new Logger($config);
    $backup = new BackupManager($config);
    $diff = new DiffGenerator();
    $nas = new NASAction($config, $backup, $diff, $logger);
    $vpn = new VPNAction($config, $backup, $diff, $logger);
});

// ── registration: wizard methods are wired into the routers ──────────

$harness->test('registration', 'nas exposes preflight + persist + test + mount', function () use (&$nas) {
    $methods = $nas->getMethods();
    foreach (['preflight', 'persist', 'test', 'mount', 'unmount', 'stats', 'checkNfs'] as $m) {
        if (!in_array($m, $methods, true)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "nas.{$m} not registered"];
        }
    }
});

$harness->test('registration', 'vpn exposes ensure + update', function () use (&$vpn) {
    $methods = $vpn->getMethods();
    foreach (['ensure', 'update', 'create', 'delete', 'status'] as $m) {
        if (!in_array($m, $methods, true)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "vpn.{$m} not registered"];
        }
    }
});

$harness->test('registration', 'backup flags: nas.persist yes, nas.test no', function () use (&$nas) {
    if (!$nas->requiresBackup('persist')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'nas.persist must require backup (writes fstab)'];
    }
    if ($nas->requiresBackup('test')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'nas.test must not require backup'];
    }
});

$harness->test('registration', 'backup flags: vpn.update yes, vpn.ensure no', function () use (&$vpn) {
    if (!$vpn->requiresBackup('update')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'vpn.update must require backup (rewrites config)'];
    }
    if ($vpn->requiresBackup('ensure')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'vpn.ensure must not require backup'];
    }
});

$harness->test('registration', 'unknown method is rejected by router', function () use (&$nas) {
    $result = $nas->execute('flowoneTestNope', [], 'flowone_test');
    if ($result['success'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected error for unknown method'];
    }
});

// ── fstab: pure rewrite logic (never touches the real /etc/fstab) ────

$harness->test('fstab', 'normalize adds _netdev and nofail', function () {
    $out = NASAction::normalizeFstabOptions('rw,soft,timeo=10,retrans=3');
    foreach (['_netdev', 'nofail', 'rw', 'soft'] as $opt) {
        if (!in_array($opt, explode(',', $out), true)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "missing {$opt} in: {$out}"];
        }
    }
});

$harness->test('fstab', 'normalize does not duplicate existing options', function () {
    $out = NASAction::normalizeFstabOptions('rw,soft,_netdev,nofail');
    if (substr_count($out, '_netdev') !== 1 || substr_count($out, 'nofail') !== 1) {
        return ['outcome' => TestHarness::FAIL, 'message' => "duplicated options: {$out}"];
    }
});

$harness->test('fstab', 'appends entry when mount point absent', function () {
    $fstab = "/dev/sda1 / ext4 defaults 0 1\n";
    $line = "10.8.0.1:/vol /mnt/flowone_test nfs rw,soft,_netdev,nofail 0 0";
    $r = NASAction::rewriteFstab($fstab, $line, '/mnt/flowone_test');
    if ($r['replaced'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected replaced=false'];
    }
    if (strpos($r['content'], $line) === false || strpos($r['content'], '/dev/sda1') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'entry not appended or original lost'];
    }
});

$harness->test('fstab', 'replaces existing entry for same mount point', function () {
    $fstab = "/dev/sda1 / ext4 defaults 0 1\nold:/x /mnt/flowone_test nfs rw,hard 0 0\n";
    $line = "new:/y /mnt/flowone_test nfs rw,soft,_netdev,nofail 0 0";
    $r = NASAction::rewriteFstab($fstab, $line, '/mnt/flowone_test');
    if ($r['replaced'] !== true) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected replaced=true'];
    }
    if (strpos($r['content'], 'old:/x') !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'stale entry survived rewrite'];
    }
});

$harness->test('fstab', 'rewrite is idempotent across two runs', function () {
    $fstab = "/dev/sda1 / ext4 defaults 0 1\n";
    $line = "n:/p /mnt/flowone_test nfs rw,soft,_netdev,nofail 0 0";
    $once = NASAction::rewriteFstab($fstab, $line, '/mnt/flowone_test')['content'];
    $twice = NASAction::rewriteFstab($once, $line, '/mnt/flowone_test')['content'];
    if ($once !== $twice) {
        return ['outcome' => TestHarness::FAIL, 'message' => "not idempotent:\n--- once ---\n{$once}\n--- twice ---\n{$twice}"];
    }
});

$harness->test('fstab', 'commented lines are preserved untouched', function () {
    $fstab = "# old:/x /mnt/flowone_test nfs rw 0 0\n/dev/sda1 / ext4 defaults 0 1\n";
    $line = "n:/p /mnt/flowone_test nfs rw,soft,_netdev,nofail 0 0";
    $r = NASAction::rewriteFstab($fstab, $line, '/mnt/flowone_test');
    if (strpos($r['content'], '# old:/x') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'comment line was removed'];
    }
    if ($r['replaced'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'comment counted as active entry'];
    }
});

$harness->test('fstab', 'similar prefix mount points are not confused', function () {
    $fstab = "n:/a /mnt/nas nfs rw 0 0\n";
    $line = "n:/b /mnt/nas-drive nfs rw,soft,_netdev,nofail 0 0";
    $r = NASAction::rewriteFstab($fstab, $line, '/mnt/nas-drive');
    if (strpos($r['content'], 'n:/a /mnt/nas nfs') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => '/mnt/nas entry was wrongly replaced'];
    }
});

$harness->test('fstab', 'CRLF input is handled', function () {
    $fstab = "/dev/sda1 / ext4 defaults 0 1\r\nold:/x /mnt/flowone_test nfs rw 0 0\r\n";
    $line = "n:/p /mnt/flowone_test nfs rw,soft,_netdev,nofail 0 0";
    $r = NASAction::rewriteFstab($fstab, $line, '/mnt/flowone_test');
    if ($r['replaced'] !== true) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'CRLF entry not detected'];
    }
    if (strpos($r['content'], "\r") !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'CR characters leaked into output'];
    }
});

// ── actions: validation + safe behavioral paths ───────────────────────

$harness->test('actions', 'nas.test rejects empty mount point', function () use (&$nas) {
    $r = $nas->execute('test', ['driver' => 'local', 'mount_point' => ''], 'flowone_test');
    if ($r['success'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected error'];
    }
});

$harness->test('actions', 'nas.test passes on writable local dir', function () use (&$nas, $sandbox) {
    $r = $nas->execute('test', ['driver' => 'local', 'mount_point' => $sandbox], 'flowone_test');
    if ($r['success'] !== true) {
        return ['outcome' => TestHarness::FAIL, 'message' => $r['error'] ?? 'test failed'];
    }
    if (empty($r['data']['checks']['writable'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'writable check not set'];
    }
});

$harness->test('actions', 'nas.test creates missing mount dir (wizard behavior)', function () use (&$nas, $sandbox) {
    $dir = $sandbox . '/flowone_test_newdir';
    if (is_dir($dir)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'precondition: dir already exists'];
    }
    $r = $nas->execute('test', ['driver' => 'local', 'mount_point' => $dir], 'flowone_test');
    if ($r['success'] !== true || !is_dir($dir)) {
        return ['outcome' => TestHarness::FAIL, 'message' => $r['error'] ?? 'dir was not created'];
    }
});

$harness->test('actions', 'nas.persist rejects missing params', function () use (&$nas) {
    $r = $nas->execute('persist', ['mount_point' => '/mnt/flowone_test'], 'flowone_test');
    if ($r['success'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected error without server/path'];
    }
});

$harness->test('actions', 'nas.persist rejects whitespace injection', function () use (&$nas) {
    // Validation must fire before any fstab read/write.
    $r = $nas->execute('persist', [
        'nfs_server' => '10.8.0.1',
        'nfs_path' => '/vol/share',
        'mount_point' => '/mnt/bad path injected:/evil',
    ], 'flowone_test');
    if ($r['success'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'whitespace in mount_point must be rejected'];
    }
});

$harness->test('actions', 'nas.stats works on local sandbox', function () use (&$nas, $sandbox) {
    $r = $nas->execute('stats', ['mount_point' => $sandbox], 'flowone_test');
    if ($r['success'] !== true) {
        return ['outcome' => TestHarness::FAIL, 'message' => $r['error'] ?? 'stats failed'];
    }
    if (!isset($r['data']['bytes']['total'], $r['data']['human']['free'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'stats payload incomplete'];
    }
});

$harness->test('actions', 'vpn.ensure rejects empty name', function () use (&$vpn) {
    $r = $vpn->execute('ensure', [], 'flowone_test');
    if ($r['success'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected error'];
    }
});

$harness->test('actions', 'vpn.ensure errors on nonexistent config', function () use (&$vpn) {
    $r = $vpn->execute('ensure', ['name' => 'flowone_test_ghost'], 'flowone_test');
    if ($r['success'] !== false || stripos($r['error'] ?? '', 'not found') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected "config not found" error'];
    }
});

$harness->test('actions', 'vpn.update errors on nonexistent config', function () use (&$vpn) {
    $r = $vpn->execute('update', [
        'name' => 'flowone_test_ghost',
        'config_content' => "# [FLOWONE-TEST] dummy\nclient\n",
    ], 'flowone_test');
    if ($r['success'] !== false || stripos($r['error'] ?? '', 'not found') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected "config not found" error'];
    }
});

$harness->test('actions', 'vpn.update rejects empty config content', function () use (&$vpn) {
    $r = $vpn->execute('update', ['name' => 'flowone_test_ghost', 'config_content' => ''], 'flowone_test');
    if ($r['success'] !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected error for empty content'];
    }
});

// ── chain: local-driver wizard roundtrip in the sandbox ───────────────

$harness->test('chain', 'test -> stats roundtrip stays consistent', function () use (&$nas, $sandbox) {
    $dir = $sandbox . '/flowone_test_chain';
    $t = $nas->execute('test', ['driver' => 'local', 'mount_point' => $dir], 'flowone_test');
    if ($t['success'] !== true) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'test step failed: ' . ($t['error'] ?? '?')];
    }
    $s = $nas->execute('stats', ['mount_point' => $dir], 'flowone_test');
    if ($s['success'] !== true) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'stats step failed: ' . ($s['error'] ?? '?')];
    }
    // Write-test files must not linger (idempotency / no leftovers).
    $leftovers = glob($dir . '/.nas_test_*') ?: [];
    if (count($leftovers) > 0) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'write-test probe file left behind'];
    }
});

$harness->test('chain', 'nfs client tools present (informational)', function () use (&$nas) {
    $r = $nas->execute('checkNfs', [], 'flowone_test');
    if ($r['success'] !== true) {
        return ['outcome' => TestHarness::WARN, 'message' => 'NFS client missing - wizard preflight will auto-install on first run'];
    }
});

exit($harness->run());
