#!/usr/bin/env php
<?php
/**
 * SftpAdapter Test Suite
 *
 * Tests Linux user / group management. Requires root (or a sudo-less
 * setuid useradd/groupadd, which is uncommon). If we're not root, the
 * suite SKIPs the destructive tests but still runs the validation
 * checks that don't touch /etc/passwd.
 *
 * All test artifacts use the `fwt_` prefix and an 8-byte random suffix
 * so an aborted run leaves recognizable orphans you can clean by hand
 * with `userdel` / `groupdel`.
 *
 * Run on server:
 *   sudo /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/sftp-adapter-test.php --verbose
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

use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Provisioner\Adapters\SftpAdapter;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SftpAdapter', $opts);

$adapter = new SftpAdapter(new ProcessCommandRunner());

$amRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : false;

$group = 'fwt_g' . substr(bin2hex(random_bytes(3)), 0, 5);
$user = 'fwt_u' . substr(bin2hex(random_bytes(3)), 0, 5);
$home = '/home/' . $user;

$harness->onCleanup(function () use ($adapter, $user, $group, $amRoot): void {
    if (!$amRoot) {
        return;
    }
    try { $adapter->deleteUser($user); } catch (\Throwable) {}
    try { $adapter->deleteGroup($group); } catch (\Throwable) {}
});

// ── name validation (always runs) ────────────────────────────
$harness->test('names', 'rejects user names with uppercase',
    function () use ($adapter) {
        try {
            $adapter->userExists('FooBar');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('names', 'rejects names with metachars',
    function () use ($adapter) {
        try {
            $adapter->userExists('foo; rm -rf');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('names', 'rejects names starting with digit',
    function () use ($adapter) {
        try {
            $adapter->userExists('1foo');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('names', 'rejects names longer than 31 chars',
    function () use ($adapter) {
        try {
            $adapter->userExists(str_repeat('a', 32));
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('shell', 'changeShell rejects unexpected shells',
    function () use ($adapter, $user) {
        try {
            // Hits validator before getent.
            $adapter->changeShell($user, '/bin/zsh');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

// ── group + user round-trip (needs root) ─────────────────────
$harness->test('crud', 'createGroup + createUser + inspectUser + delete',
    function () use ($adapter, $group, $user, $home, $amRoot) {
        if (!$amRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'not root, skipping destructive tests'];
        }
        if ($adapter->groupExists($group) || $adapter->userExists($user)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'pre-existing test artifacts; clean up first'];
        }

        $g1 = $adapter->createGroup($group);
        if (!$g1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'createGroup returned false'];
        }
        $g2 = $adapter->createGroup($group);
        if ($g2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'createGroup created twice'];
        }

        // Create the home dir so useradd -M doesn't surprise us later
        // (it tolerates absent home as long as we don't ask it to populate).
        $u1 = $adapter->createUser($user, $home, $group);
        if (!$u1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'createUser returned false'];
        }
        $u2 = $adapter->createUser($user, $home, $group);
        if ($u2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'createUser created twice'];
        }

        $info = $adapter->inspectUser($user);
        if ($info === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'inspectUser returned null'];
        }
        if ($info['home'] !== $home) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'home wrong: ' . $info['home']];
        }
        if ($info['shell'] !== '/bin/false') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'shell wrong: ' . $info['shell']];
        }

        // Password rotation
        $adapter->setPassword($user, 'flowone-test-' . bin2hex(random_bytes(4)));

        // Cleanup
        $d1 = $adapter->deleteUser($user);
        if (!$d1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'deleteUser returned false'];
        }
        $d2 = $adapter->deleteUser($user);
        if ($d2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'deleteUser deleted twice'];
        }

        $dg = $adapter->deleteGroup($group);
        if (!$dg) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'deleteGroup returned false'];
        }
    });

// ── primaryGroupName (needs root for the destructive setup) ──
$harness->test('primary-group', 'primaryGroupName returns the user\'s actual primary group',
    function () use ($adapter, $amRoot) {
        if (!$amRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'not root, skipping'];
        }
        $g = 'fwt_pg' . substr(bin2hex(random_bytes(3)), 0, 4);
        $u = 'fwt_pu' . substr(bin2hex(random_bytes(3)), 0, 4);
        $h = '/home/' . $u;
        try {
            $adapter->createGroup($g);
            $adapter->createUser($u, $h, $g);
            $resolved = $adapter->primaryGroupName($u);
            if ($resolved !== $g) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "expected primary group '{$g}', got '" . var_export($resolved, true) . "'"];
            }
        } finally {
            try { $adapter->deleteUser($u); } catch (\Throwable) {}
            try { $adapter->deleteGroup($g); } catch (\Throwable) {}
        }
    });

$harness->test('primary-group', 'primaryGroupName resolves shared-system users (www-data) when present',
    function () use ($adapter) {
        // www-data is the canonical Debian web user. If this host has
        // it (every OLS-on-Debian host does) the lookup should return
        // a non-empty group string. We don't pin the exact value
        // because some Debian builds put www-data under group
        // `www-data` and some under `nogroup`.
        if (!$adapter->userExists('www-data')) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'www-data not present on this host'];
        }
        $g = $adapter->primaryGroupName('www-data');
        if (!is_string($g) || $g === '') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected a non-empty primary group for www-data, got '
                    . var_export($g, true)];
        }
    });

$harness->test('primary-group', 'primaryGroupName returns null for missing users',
    function () use ($adapter) {
        $missing = 'fwt_no' . substr(bin2hex(random_bytes(3)), 0, 4);
        if ($adapter->userExists($missing)) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'random user happens to exist; skipping'];
        }
        $g = $adapter->primaryGroupName($missing);
        if ($g !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected null for missing user, got ' . var_export($g, true)];
        }
    });

// ── groupHasMembers (gating shared-group deletion) ───────────
$harness->test('group-members', 'groupHasMembers returns false for a freshly created empty group',
    function () use ($adapter, $amRoot) {
        if (!$amRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'not root, skipping'];
        }
        $g = 'fwt_em' . substr(bin2hex(random_bytes(3)), 0, 4);
        try {
            $adapter->createGroup($g);
            $has = $adapter->groupHasMembers($g);
            if ($has !== false) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "expected false for empty group '{$g}', got " . var_export($has, true)];
            }
        } finally {
            try { $adapter->deleteGroup($g); } catch (\Throwable) {}
        }
    });

$harness->test('group-members', 'groupHasMembers returns true while a user still has the group as primary',
    function () use ($adapter, $amRoot) {
        if (!$amRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'not root, skipping'];
        }
        $g = 'fwt_pr' . substr(bin2hex(random_bytes(3)), 0, 4);
        $u = 'fwt_pp' . substr(bin2hex(random_bytes(3)), 0, 4);
        $h = '/home/' . $u;
        try {
            $adapter->createGroup($g);
            $adapter->createUser($u, $h, $g);
            $has = $adapter->groupHasMembers($g);
            if ($has !== true) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "expected true (user has it as primary), got " . var_export($has, true)];
            }
        } finally {
            try { $adapter->deleteUser($u, force: true); } catch (\Throwable) {}
            try { $adapter->deleteGroup($g); } catch (\Throwable) {}
        }
    });

$harness->test('group-members', 'groupHasMembers reports true for shared system groups (www-data) when present',
    function () use ($adapter) {
        // Every OLS-on-Debian host has www-data with itself as primary
        // group, so groupHasMembers should always return true here.
        // The whole point of this test is to verify the saga refuses
        // to groupdel www-data on shared hosts.
        if (!$adapter->groupExists('www-data')) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'www-data group not present on this host'];
        }
        $has = $adapter->groupHasMembers('www-data');
        if ($has !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected www-data to report members, got ' . var_export($has, true)];
        }
    });

$harness->test('group-members', 'groupHasMembers returns false for a non-existent group',
    function () use ($adapter) {
        $missing = 'fwt_xg' . substr(bin2hex(random_bytes(3)), 0, 4);
        if ($adapter->groupExists($missing)) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'random group happens to exist; skipping'];
        }
        $has = $adapter->groupHasMembers($missing);
        if ($has !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected false for missing group, got ' . var_export($has, true)];
        }
    });

// ── deleteUser(force: true) escalation ───────────────────────
$harness->test('user-force', 'deleteUser(force: true) removes a user with no live processes',
    function () use ($adapter, $amRoot) {
        if (!$amRoot) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'not root, skipping'];
        }
        // No way to simulate "user has live processes" cleanly inside
        // a unit test (we'd need to fork + nohup something under a
        // throw-away uid). This test just verifies the force-path
        // round-trips correctly when there's nothing to force.
        $g = 'fwt_fg' . substr(bin2hex(random_bytes(3)), 0, 4);
        $u = 'fwt_fu' . substr(bin2hex(random_bytes(3)), 0, 4);
        $h = '/home/' . $u;
        try {
            $adapter->createGroup($g);
            $adapter->createUser($u, $h, $g);
            $deleted = $adapter->deleteUser($u, force: true);
            if ($deleted !== true) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'expected deleteUser(force) to return true, got ' . var_export($deleted, true)];
            }
            if ($adapter->userExists($u)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "user '{$u}' still present after deleteUser(force)"];
            }
        } finally {
            try { $adapter->deleteUser($u, force: true); } catch (\Throwable) {}
            try { $adapter->deleteGroup($g); } catch (\Throwable) {}
        }
    });

exit($harness->run());
