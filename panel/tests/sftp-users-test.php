#!/usr/bin/env php
<?php
/**
 * SFTP Users :: Restricted chroot-jailed accounts (end-to-end)
 *
 * Exercises the additional-SFTP-user feature's agent helpers
 * (VpsAdmin\Agent\Sftp\{JailManager, SftpAccountManager,
 * SshdSftpConfigurator}) against disposable scratch state. No
 * production site, DB, or the live sshd config is touched.
 *
 * Two tiers:
 *   - validate  : pure, side-effect-free checks (path canonicalization +
 *                 min depth + symlink escape, generated-username contract,
 *                 operator-supplied username format + reserved-name rules,
 *                 SSH key validation, fstab marker-block round-trip, and
 *                 the sshd Match block parsed via `sshd -t -f` against a
 *                 throwaway config). Always runs.
 *   - roundtrip : the real OS chain (useradd, mount --bind, ACL, key file,
 *                 password lock, remount, repair, teardown). Requires root
 *                 + the acl/ssh tooling and is skipped under --skip-send
 *                 or when prerequisites are missing.
 *
 * Everything created uses a `flowone_test_` prefix and is removed on
 * success, failure, or signal. Shared infra (the flowone_sftp group and
 * the production sshd drop-in) is intentionally NOT created or removed by
 * this test.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/sftp-users-test.php --verbose
 *
 * Flags:
 *   --help       -- this header
 *   --verbose    -- include stack traces on failures
 *   --skip-send  -- skip the destructive roundtrip (validate-only)
 *   --smoke      -- run pre-flight only
 *   --only=g     -- run only group g (preflight always runs)
 *   --json       -- machine-readable summary
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1700));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Sftp\JailManager;
use VpsAdmin\Agent\Sftp\SftpAccountManager;
use VpsAdmin\Agent\Sftp\SshdSftpConfigurator;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SFTP Users', $opts);
$skipSend = isset($opts['skip-send']);

// ─── shared state ───────────────────────────────────────────────
$rand = substr(bin2hex(random_bytes(4)), 0, 8);
$runner = new ProcessCommandRunner();
$accounts = new SftpAccountManager($runner);

$tmp = sys_get_temp_dir();
$validateBase = $tmp . '/flowone_test_sftp_validate_' . $rand;
$tmpFstab = $tmp . '/flowone_test_sftp_fstab_' . $rand;
$tmpJailBase = $tmp . '/flowone_test_sftp_jails_' . $rand;
$jail = new JailManager($runner, 1, $tmpFstab, $tmpJailBase);

// Roundtrip scratch (filled in during preflight).
$user = 'flowone_test_' . $rand;          // matches ^[a-z][a-z0-9_-]{0,30}$, <= 31 chars
$label = 'uploads';
$scratchHome = '/home/flowone_test_' . $rand;
$target = $scratchHome . '/public_html/data/uploads';
$env = ['root' => false, 'tools' => false, 'canRoundtrip' => false];

$rrmdir = static function (string $dir) use (&$rrmdir): void {
    if (!is_dir($dir)) { @unlink($dir); return; }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $e) {
        $p = $dir . '/' . $e;
        is_dir($p) && !is_link($p) ? $rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
};

$expectThrow = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable) { return true; }
};

$assert = static function (bool $cond, string $msg): void {
    if (!$cond) { throw new \RuntimeException($msg); }
};

// Cleanup: tear down anything we created. Never touches shared infra.
$harness->onCleanup(function () use ($runner, $jail, $accounts, $user, $target, &$scratchHome, $tmpJailBase, $validateBase, $tmpFstab, $rrmdir, $env): void {
    if ($env['canRoundtrip']) {
        try {
            $mp = $jail->mountPointFor($user, 'uploads');
            $jail->unmount($mp);
        } catch (\Throwable) {}
        try { $jail->removeFstabBlock($user); } catch (\Throwable) {}
        try { if (is_dir($target)) { $jail->removeAcl($target, $user); } } catch (\Throwable) {}
        try { $accounts->removeKeyFile($user); } catch (\Throwable) {}
        try { if ($accounts->userExists($user)) { $accounts->deleteAccount($user); } } catch (\Throwable) {}
    }
    $rrmdir($tmpJailBase);
    $rrmdir($validateBase);
    $rrmdir($scratchHome);
    @unlink($tmpFstab);
});

// ─── preflight ──────────────────────────────────────────────────

$harness->test('preflight', 'CLI + PHP 8.x', function () {
    return ['outcome' => version_compare(PHP_VERSION, '8.0', '>=') ? TestHarness::PASS : TestHarness::FAIL,
            'message' => PHP_VERSION];
});

$harness->test('preflight', 'Required binaries present', function () use ($runner, &$env) {
    $bins = ['useradd', 'userdel', 'usermod', 'gpasswd', 'passwd', 'mount', 'umount', 'mountpoint', 'setfacl', 'getfacl', 'id'];
    $missing = [];
    foreach ($bins as $b) {
        if (!$runner->run('command', ['-v', $b], null, 5)->isSuccess()
            && !$runner->run('/usr/bin/which', [$b], null, 5)->isSuccess()) {
            $missing[] = $b;
        }
    }
    $env['tools'] = $missing === [];
    return ['outcome' => $env['tools'] ? TestHarness::PASS : TestHarness::WARN,
            'message' => $env['tools'] ? 'all present' : 'missing: ' . implode(',', $missing)];
});

$harness->test('preflight', 'Running as root', function () use (&$env) {
    $env['root'] = function_exists('posix_getuid') && posix_getuid() === 0;
    return ['outcome' => $env['root'] ? TestHarness::PASS : TestHarness::WARN,
            'message' => $env['root'] ? 'root' : 'not root - roundtrip will skip'];
});

$harness->test('preflight', 'Build validate scratch tree', function () use ($validateBase, $assert) {
    foreach (['', '/public_html', '/public_html/data', '/public_html/data/uploads', '/single'] as $sub) {
        $p = $validateBase . $sub;
        if (!is_dir($p) && !@mkdir($p, 0755, true)) {
            throw new \RuntimeException("mkdir failed: {$p}");
        }
    }
    @symlink('/etc', $validateBase . '/escape');
    $assert(is_dir($validateBase . '/public_html/data/uploads'), 'scratch tree missing');
    return ['message' => $validateBase];
});

$harness->test('preflight', 'Roundtrip prerequisites', function () use (&$env, $skipSend) {
    $env['canRoundtrip'] = $env['root'] && $env['tools'] && !$skipSend;
    $why = $skipSend ? '--skip-send' : (!$env['root'] ? 'not root' : (!$env['tools'] ? 'missing tools' : 'ready'));
    return ['outcome' => TestHarness::PASS, 'message' => $env['canRoundtrip'] ? 'ready' : "skipping roundtrip ({$why})"];
});

// ─── validate (no side effects) ─────────────────────────────────

$harness->test('validate', 'Reject home root as target', function () use ($jail, $validateBase, $expectThrow, $assert) {
    $assert($expectThrow(fn() => $jail->validateTarget($validateBase, $validateBase)), 'home root was not rejected');
    return [];
});

$harness->test('validate', 'Reject public_html root as target', function () use ($jail, $validateBase, $expectThrow, $assert) {
    $assert($expectThrow(fn() => $jail->validateTarget($validateBase, $validateBase . '/public_html')), 'public_html root was not rejected');
    return [];
});

$harness->test('validate', 'Reject nonexistent target', function () use ($jail, $validateBase, $expectThrow, $assert) {
    $assert($expectThrow(fn() => $jail->validateTarget($validateBase, $validateBase . '/nope')), 'nonexistent target was not rejected');
    return [];
});

$harness->test('validate', 'Reject symlink escape outside home', function () use ($jail, $validateBase, $expectThrow, $assert) {
    if (!is_link($validateBase . '/escape')) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'symlink not creatable here'];
    }
    $assert($expectThrow(fn() => $jail->validateTarget($validateBase, $validateBase . '/escape')), 'symlink escape was not rejected');
    return [];
});

$harness->test('validate', 'Accept deep subfolder', function () use ($jail, $validateBase, $assert) {
    $canon = $jail->validateTarget($validateBase, $validateBase . '/public_html/data/uploads');
    $assert(str_starts_with($canon . '/', $validateBase . '/'), 'canonical path escaped home');
    return ['message' => $canon];
});

$harness->test('validate', 'Min-depth enforcement (depth 2)', function () use ($runner, $tmpFstab, $tmpJailBase, $validateBase, $expectThrow, $assert) {
    $deep = new JailManager($runner, 2, $tmpFstab, $tmpJailBase);
    $assert($expectThrow(fn() => $deep->validateTarget($validateBase, $validateBase . '/single')), 'too-shallow target accepted at minDepth=2');
    $ok = $deep->validateTarget($validateBase, $validateBase . '/public_html/data');
    $assert(is_string($ok), 'depth-2 target rejected at minDepth=2');
    return [];
});

$harness->test('validate', 'Generated username contract (<=31, regex-safe)', function () use ($accounts, $assert) {
    foreach ([1, 42, 1234567890] as $siteId) {
        $name = substr('sftp_' . $siteId . '_' . substr(bin2hex(random_bytes(4)), 0, 6), 0, 31);
        $assert(strlen($name) <= 31, "name too long: {$name}");
        $assert(preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $name) === 1, "name failed regex: {$name}");
        // userExists must accept the name (return bool) and not throw on the safe-name guard.
        $accounts->userExists($name);
    }
    return [];
});

$harness->test('validate', 'Operator username: format + reserved-name rules', function () use ($assert) {
    $fmt = new \ReflectionMethod(\VpsAdmin\Agent\Actions\SftpUserAction::class, 'validateUsernameFormat');
    $fmt->setAccessible(true);
    $reserved = new \ReflectionMethod(\VpsAdmin\Agent\Actions\SftpUserAction::class, 'isReservedUsername');
    $reserved->setAccessible(true);

    // Accepts valid names, trimming + lowercasing.
    $assert($fmt->invoke(null, '  PrintShop ') === 'printshop', 'did not trim+lowercase a valid name');
    $assert($fmt->invoke(null, 'print_shop-1') === 'print_shop-1', 'rejected a valid name with _ and -');

    // Rejects bad formats (too short, leading digit/underscore, spaces,
    // illegal chars, too long, non-ascii).
    foreach (['ab', 'a', '1printshop', '_printshop', 'print shop', 'print$', str_repeat('a', 33), 'über'] as $bad) {
        $threw = false;
        try { $fmt->invoke(null, $bad); } catch (\Throwable) { $threw = true; }
        $assert($threw, "bad username accepted: '{$bad}'");
    }

    // Reserved/system names are refused even though they pass the format.
    foreach (['root', 'www-data', 'admin', 'mysql', 'vmail', 'sshd', 'systemd-resolve'] as $r) {
        $assert($reserved->invoke(null, $r) === true, "reserved name not flagged: {$r}");
    }
    $assert($reserved->invoke(null, 'printshop') === false, 'normal name wrongly flagged reserved');
    return [];
});

$harness->test('validate', 'SSH public-key validation', function () use ($assert) {
    $m = new \ReflectionMethod(SftpAccountManager::class, 'looksLikePublicKey');
    $m->setAccessible(true);
    $valid = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIabc123def456 test@flowone';
    $assert($m->invoke(null, $valid) === true, 'valid ed25519 key rejected');
    $assert($m->invoke(null, 'not a key') === false, 'garbage accepted as key');
    $assert($m->invoke(null, "ssh-rsa AAAA\nmalicious") === false, 'newline injection accepted');
    return [];
});

$harness->test('validate', 'fstab marker block add/remove round-trip', function () use ($jail, $user, $tmpFstab, $assert) {
    @file_put_contents($tmpFstab, "# existing fstab line\n/dev/x /mnt ext4 defaults 0 0\n");
    $jail->addFstabBlock($user, '/home/flowone_test/public_html/data', '/srv/sftp-jails/x/uploads');
    $body = file_get_contents($tmpFstab);
    $assert($jail->fstabHasBlock($user), 'block not detected after add');
    $assert(str_contains($body, 'bind,nofail'), 'bind,nofail option missing');
    $assert(str_contains($body, '# existing fstab line'), 'pre-existing fstab content clobbered');
    $jail->removeFstabBlock($user);
    $assert(!$jail->fstabHasBlock($user), 'block not removed');
    $assert(str_contains(file_get_contents($tmpFstab), '# existing fstab line'), 'removal clobbered other content');
    @unlink($tmpFstab);
    return [];
});

$harness->test('validate', 'sshd Match block parses (sshd -t -f)', function () use ($runner, $validateBase, $assert) {
    $sshd = '/usr/sbin/sshd';
    if (!is_file($sshd) || !is_file('/etc/ssh/sshd_config')) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'sshd or sshd_config not present'];
    }
    if (!(function_exists('posix_getuid') && posix_getuid() === 0)) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'needs root to read host keys'];
    }
    $confDir = $validateBase . '/sshdtest';
    @mkdir($confDir, 0755, true);
    $cfg = new SshdSftpConfigurator($runner);
    file_put_contents($confDir . '/flowone-sftp.conf', $cfg->desiredConfig());
    $main = file_get_contents('/etc/ssh/sshd_config');
    $tmpMain = $confDir . '/sshd_config';
    file_put_contents($tmpMain, "Include {$confDir}/*.conf\n" . $main);
    $r = $runner->run($sshd, ['-t', '-f', $tmpMain], null, 15);
    $assert($r->isSuccess(), 'sshd -t -f rejected our Match block: ' . trim($r->stderr));
    return [];
});

// ─── roundtrip (destructive; root + tooling required) ───────────

$guard = static function (array $env): ?array {
    if (!$env['canRoundtrip']) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'roundtrip prerequisites not met'];
    }
    return null;
};

$harness->test('roundtrip', 'Provision scratch home + ensure infra', function () use ($guard, $env, $accounts, $target, $assert) {
    if ($s = $guard($env)) { return $s; }
    if (!is_dir($target) && !@mkdir($target, 0755, true)) {
        throw new \RuntimeException("mkdir scratch target failed: {$target}");
    }
    $accounts->ensureGroup();
    $accounts->ensureKeyDir();
    $assert(is_dir(SftpAccountManager::KEY_DIR), 'key dir not created');
    return [];
});

$harness->test('roundtrip', 'Create jail (bind mount + default ACL + fstab)', function () use ($guard, $env, $jail, $user, $target, $assert) {
    if ($s = $guard($env)) { return $s; }
    $canon = $jail->validateTarget(dirname($target, 3), $target); // homeRoot = /home/<scratch>
    $res = $jail->ensureJail($user, $canon, 'uploads');
    $assert($jail->isMounted($res['mount_point']), 'mount point not mounted');
    $assert($jail->fstabHasBlock($user), 'fstab block missing');
    return ['message' => $res['mount_point']];
});

$harness->test('roundtrip', 'Default ACL present on target (getfacl)', function () use ($guard, $env, $runner, $user, $target, $assert) {
    if ($s = $guard($env)) { return $s; }
    $r = $runner->run('getfacl', ['-p', $target], null, 10);
    $assert($r->isSuccess(), 'getfacl failed');
    $assert(str_contains($r->stdout, "default:user:{$user}:"), 'default ACL for user not set');
    return [];
});

$harness->test('roundtrip', 'Create account + lock password (key-only)', function () use ($guard, $env, $accounts, $jail, $user, $assert) {
    if ($s = $guard($env)) { return $s; }
    $accounts->createAccount($user, $jail->jailRootFor($user), SftpAccountManager::GROUP);
    $assert($accounts->userExists($user), 'user not created');
    $assert($accounts->inGroup($user, SftpAccountManager::GROUP), 'user not in flowone_sftp group');
    $accounts->lockPassword($user);
    $assert($accounts->isPasswordLocked($user) === true, 'password not locked for key-only user');
    return [];
});

$harness->test('roundtrip', 'Write + read keys, file perms 0600 root', function () use ($guard, $env, $accounts, $user, $assert) {
    if ($s = $guard($env)) { return $s; }
    $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIflowonetestkey0000000000000000000000 flowone-test';
    $accounts->writeKeys($user, [$key]);
    $path = $accounts->keyFilePath($user);
    clearstatcache();
    $assert((fileperms($path) & 0777) === 0600, 'key file not 0600');
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($path))['name'] ?? '') : 'root';
    $assert($owner === 'root', 'key file not owned by root');
    $assert($accounts->readKeys($user) === [$key], 'key round-trip mismatch');
    return [];
});

$harness->test('roundtrip', 'Remount survives unmount (reboot proxy)', function () use ($guard, $env, $jail, $user, $target, $assert) {
    if ($s = $guard($env)) { return $s; }
    $mp = $jail->mountPointFor($user, 'uploads');
    $jail->unmount($mp);
    $assert(!$jail->isMounted($mp), 'still mounted after unmount');
    $jail->ensureJail($user, $jail->validateTarget(dirname($target, 3), $target), 'uploads');
    $assert($jail->isMounted($mp), 'remount failed');
    return [];
});

$harness->test('roundtrip', 'Repair restores removed fstab block', function () use ($guard, $env, $jail, $user, $target, $assert) {
    if ($s = $guard($env)) { return $s; }
    $jail->removeFstabBlock($user);
    $assert(!$jail->fstabHasBlock($user), 'fstab block not removed for test');
    $fixes = $jail->repair($user, $jail->validateTarget(dirname($target, 3), $target), 'uploads');
    $assert($jail->fstabHasBlock($user), 'repair did not restore fstab block');
    return ['message' => implode(',', $fixes)];
});

$harness->test('roundtrip', 'Repair refuses missing target', function () use ($guard, $env, $jail, $user, $target, $expectThrow, $assert) {
    if ($s = $guard($env)) { return $s; }
    $aside = $target . '.aside';
    $assert(@rename($target, $aside), 'could not move target aside');
    $threw = $expectThrow(fn() => $jail->repair($user, $target, 'uploads'));
    @rename($aside, $target);
    $assert($threw, 'repair fabricated a missing target');
    return [];
});

$harness->test('roundtrip', 'Teardown unmounts, clears fstab + ACL, userdel', function () use ($guard, $env, $runner, $jail, $accounts, $user, $target, $assert) {
    if ($s = $guard($env)) { return $s; }
    $mp = $jail->mountPointFor($user, 'uploads');
    $jail->teardown($user, $target, $mp);
    $assert(!$jail->isMounted($mp), 'still mounted after teardown');
    $assert(!$jail->fstabHasBlock($user), 'fstab block remained after teardown');
    $assert(!is_dir($jail->jailRootFor($user)), 'jail root not removed');
    $facl = $runner->run('getfacl', ['-p', $target], null, 10);
    $assert(!str_contains($facl->stdout, "user:{$user}:"), 'ACL entry for user remained');
    $accounts->removeKeyFile($user);
    $accounts->deleteAccount($user);
    $assert(!$accounts->userExists($user), 'user not deleted');
    return [];
});

exit($harness->run());
