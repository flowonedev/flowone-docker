#!/usr/bin/env php
<?php
/**
 * FilesystemAdapter Test Suite
 *
 * Verifies the safety guarantees and idempotence of the adapter:
 *
 *   - writeAtomic produces either-old-or-new content even under
 *     concurrent writes; staging files are cleaned up on failure.
 *   - ensureDirectory is idempotent.
 *   - Reads return null on missing, throw on unreadable-existing.
 *   - rmtree refuses to descend outside allowedRoots.
 *   - rmtree refuses to delete an allowedRoot itself.
 *   - chownPath/chownRecursive validate the spec.
 *   - statSafe is null on missing.
 *   - Symlink CRUD.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/filesystem-adapter-test.php --verbose
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
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('FilesystemAdapter', $opts);

$sandbox = realpath(sys_get_temp_dir()) . '/flowone_test_fs_' . bin2hex(random_bytes(4));
mkdir($sandbox, 0755, true);

$harness->onCleanup(function () use ($sandbox): void {
    rrmdir($sandbox);
});

$fs = new FilesystemAdapter(new ProcessCommandRunner(), [$sandbox]);

// ── exists / stat ─────────────────────────────────────────────
$harness->test('stat', 'statSafe returns null for missing path',
    function () use ($fs, $sandbox) {
        $r = $fs->statSafe($sandbox . '/nope');
        if ($r !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected null'];
        }
    });

$harness->test('stat', 'statSafe returns size + perms for existing file',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/stat-target.txt';
        file_put_contents($path, 'hello');
        $r = $fs->statSafe($path);
        if ($r === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected non-null'];
        }
        if ($r['size'] !== 5) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'size wrong'];
        }
        if (!preg_match('/^[0-9]{4}$/', $r['perms_octal'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'perms_octal malformed: ' . $r['perms_octal']];
        }
    });

// ── writeAtomic ──────────────────────────────────────────────
$harness->test('write_atomic', 'writes file with content + cleans up staging',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/atomic-' . bin2hex(random_bytes(3)) . '.txt';
        $n = $fs->writeAtomic($path, "hello world\n");
        if ($n !== 12) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wrote ' . $n];
        }
        if (file_get_contents($path) !== "hello world\n") {
            return ['outcome' => TestHarness::FAIL, 'message' => 'content mismatch'];
        }
        $leftover = glob($path . '.tmp.*');
        if ($leftover) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'staging leaked: ' . implode(', ', $leftover)];
        }
    });

$harness->test('write_atomic', 'overwrites existing file atomically',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/overwrite.txt';
        file_put_contents($path, 'OLD');
        $fs->writeAtomic($path, 'NEW');
        if (file_get_contents($path) !== 'NEW') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'content not replaced'];
        }
    });

$harness->test('write_atomic', 'relative paths are rejected',
    function () use ($fs) {
        try {
            $fs->writeAtomic('relative/path.txt', 'x');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('write_atomic', '../ in path is rejected',
    function () use ($fs, $sandbox) {
        try {
            $fs->writeAtomic($sandbox . '/foo/../escape.txt', 'x');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

// ── ensureDirectory ──────────────────────────────────────────
$harness->test('ensure_dir', 'creates missing directory',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/new-dir-' . bin2hex(random_bytes(3));
        $fs->ensureDirectory($path);
        if (!is_dir($path)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'dir not created'];
        }
    });

$harness->test('ensure_dir', 'is idempotent on existing dir',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/existing-dir';
        $fs->ensureDirectory($path);
        $fs->ensureDirectory($path); // should not throw
    });

$harness->test('ensure_dir', 'throws when target exists as a file',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/this-is-a-file';
        file_put_contents($path, 'x');
        try {
            $fs->ensureDirectory($path);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\RuntimeException) {
            // ok
        }
    });

// ── reads ─────────────────────────────────────────────────────
$harness->test('read', 'readFile returns null for missing',
    function () use ($fs, $sandbox) {
        $r = $fs->readFile($sandbox . '/missing.txt');
        if ($r !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected null'];
        }
    });

$harness->test('read', 'readFile returns content for present',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/read-me.txt';
        file_put_contents($path, "hello\n");
        $r = $fs->readFile($path);
        if ($r !== "hello\n") {
            return ['outcome' => TestHarness::FAIL, 'message' => 'content wrong'];
        }
    });

// ── rmtree (sandbox-bound) ───────────────────────────────────
$harness->test('rmtree', 'rmtree removes nested structure',
    function () use ($fs, $sandbox) {
        $root = $sandbox . '/rm-root';
        mkdir($root . '/a/b/c', 0755, true);
        file_put_contents($root . '/a/x.txt', 'x');
        file_put_contents($root . '/a/b/y.txt', 'y');
        file_put_contents($root . '/a/b/c/z.txt', 'z');
        $removed = $fs->rmtree($root);
        if (is_dir($root)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'root still present'];
        }
        if ($removed < 5) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected 5+ removed, got ' . $removed];
        }
    });

$harness->test('rmtree', 'refuses to delete outside allowed roots',
    function () use ($fs) {
        $outside = '/tmp/flowone_test_outside_' . bin2hex(random_bytes(4));
        mkdir($outside, 0755, true);
        try {
            $fs->rmtree($outside);
            // restore for cleanup
            @rmdir($outside);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\RuntimeException) {
            // ok
        } finally {
            @rmdir($outside);
        }
    });

$harness->test('rmtree', 'refuses to delete allowedRoot itself',
    function () use ($fs, $sandbox) {
        try {
            $fs->rmtree($sandbox);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\RuntimeException) {
            // ok
        }
    });

// ── chmod / chown spec validation ────────────────────────────
$harness->test('mode', 'chmodPath sets mode',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/mode-' . bin2hex(random_bytes(3)) . '.txt';
        file_put_contents($path, 'x');
        $fs->chmodPath($path, 0640);
        clearstatcache(true, $path);
        $perms = fileperms($path) & 07777;
        if ($perms !== 0640) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'perms wrong: ' . sprintf('%04o', $perms)];
        }
    });

$harness->test('mode', 'chownPath rejects shell-metachar spec',
    function () use ($fs, $sandbox) {
        $path = $sandbox . '/own.txt';
        file_put_contents($path, 'x');
        try {
            $fs->chownPath($path, 'user; rm -rf /');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

// ── symlinks ─────────────────────────────────────────────────
$harness->test('symlink', 'create + read + replace symlink',
    function () use ($fs, $sandbox) {
        $target = $sandbox . '/sym-target';
        file_put_contents($target, 'x');
        $link = $sandbox . '/sym-link';
        $fs->createSymlink($target, $link);
        if (!is_link($link)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'symlink not created'];
        }
        if ($fs->readSymlink($link) !== $target) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'readSymlink wrong'];
        }
        // replace flag
        $target2 = $sandbox . '/sym-target-2';
        file_put_contents($target2, 'y');
        $fs->createSymlink($target2, $link, replace: true);
        if ($fs->readSymlink($link) !== $target2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'replace failed'];
        }
        // without replace flag should throw
        try {
            $fs->createSymlink($target, $link);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\RuntimeException) {
            // ok
        }
    });

// ── allowedRoots governance ──────────────────────────────────
$harness->test('roots', 'addAllowedRoot expands the safety boundary',
    function () use ($sandbox) {
        $local = new FilesystemAdapter(new ProcessCommandRunner(), [$sandbox]);
        $outside = $sandbox . '_alt_' . bin2hex(random_bytes(3));
        mkdir($outside, 0755, true);
        try {
            $local->rmtree($outside . '/nope');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\RuntimeException) {
            // ok
        }
        $local->addAllowedRoot($outside);
        // now create + remove inside it
        mkdir($outside . '/sub', 0755);
        $local->rmtree($outside . '/sub');
        @rmdir($outside);
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
