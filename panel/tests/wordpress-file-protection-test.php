#!/usr/bin/env php
<?php
/**
 * WordPress :: File Protection (Harden / Unlock roundtrip)
 *
 * Exercises WordPressAction::actionSecureFiles() and the new
 * actionUnsecureFiles() against a disposable scratch docroot. No
 * production site, database, WP-CLI or sudo is touched.
 *
 * We build a fake OLS vhost dir so getDocumentRoot() resolves to a temp
 * docroot under the system temp dir, drop a minimal wp-config.php (with
 * the "That's all, stop editing!" marker) plus a .htaccess and an empty
 * wp-content/uploads/, then assert the secure -> unsecure roundtrip:
 *
 *   - secure   : wp-config.php -> 0440, .htaccess -> 0444,
 *                DISALLOW_FILE_EDIT injected, uploads/.htaccess created
 *   - unsecure : wp-config.php -> 0644, .htaccess -> 0644,
 *                DISALLOW_FILE_EDIT removed, uploads/.htaccess left intact
 *   - isFileEditingDisabled() tracks the DISALLOW_FILE_EDIT state
 *   - idempotency: unsecure on an already-unlocked site is a clean no-op,
 *     and the pair can be cycled repeatedly with identical results
 *
 * Every artifact lives under a `flowone_test_` temp directory that the
 * cleanup callback nukes on success, failure or signal.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/wordpress-file-protection-test.php --verbose
 *
 * Flags:
 *   --help       -- this header
 *   --verbose    -- include stack traces on failures
 *   --skip-send  -- accepted for parity (no external sends in this suite)
 *   --smoke      -- run preflight only
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
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1900));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Actions\WordPressAction;
use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('WordPress File Protection', $opts);

// ─── scratch environment ────────────────────────────────────────

$root = sys_get_temp_dir() . '/flowone_test_wpfileprotect_' . bin2hex(random_bytes(4));
$domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
$vhostRoot = $root . '/vhosts';
$docRoot = $root . '/home/' . $domain . '/public_html';

$rrmdir = static function (string $dir) use (&$rrmdir): void {
    if (!is_dir($dir)) {
        @unlink($dir);
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        $path = $dir . '/' . $entry;
        @chmod($path, 0700);
        is_dir($path) ? $rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
};

$harness->onCleanup(function () use ($root, $rrmdir): void {
    $rrmdir($root);
});

$wpConfigTemplate = <<<'PHP'
<?php
define( 'DB_NAME', 'flowone_test_db' );
define( 'DB_USER', 'flowone_test_user' );
define( 'DB_PASSWORD', 'flowone_test_pw' );
define( 'DB_HOST', 'localhost' );
$table_prefix = 'wp_';

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP;

// Rebuild the scratch docroot from scratch so each group starts clean.
$seedDocRoot = static function () use ($docRoot, $vhostRoot, $domain, $wpConfigTemplate, $rrmdir): void {
    $rrmdir($docRoot);
    if (!mkdir($docRoot . '/wp-content/uploads', 0755, true) && !is_dir($docRoot . '/wp-content/uploads')) {
        throw new \RuntimeException("could not create scratch docroot: {$docRoot}");
    }
    file_put_contents($docRoot . '/wp-config.php', $wpConfigTemplate);
    chmod($docRoot . '/wp-config.php', 0644);
    file_put_contents($docRoot . '/.htaccess', "# BEGIN WordPress\n# END WordPress\n");
    chmod($docRoot . '/.htaccess', 0644);

    // Fake OLS vhost so getDocumentRoot() resolves to our scratch docroot.
    // No extUser line -> getSiteUser() returns null -> chown is skipped.
    $vhostDir = $vhostRoot . '/' . $domain;
    if (!is_dir($vhostDir) && !mkdir($vhostDir, 0755, true)) {
        throw new \RuntimeException("could not create fake vhost dir: {$vhostDir}");
    }
    file_put_contents($vhostDir . '/vhost.conf', "docRoot {$docRoot}\n");
};

// WordPressAction needs $this->config but not the backup/diff/logger
// collaborators for these methods, so build it without the constructor
// and inject only the config.
$action = (new ReflectionClass(WordPressAction::class))->newInstanceWithoutConstructor();
$cfgProp = new ReflectionProperty(BaseAction::class, 'config');
$cfgProp->setAccessible(true);
$cfgProp->setValue($action, ['paths' => ['ols_vhosts' => $vhostRoot]]);

$invoke = static function (string $method, array $params) use ($action) {
    $m = new ReflectionMethod(WordPressAction::class, $method);
    $m->setAccessible(true);
    return $m->invoke($action, $params, 'flowone-test');
};

$editorDisabled = static function () use ($action, $docRoot): bool {
    $m = new ReflectionMethod(WordPressAction::class, 'isFileEditingDisabled');
    $m->setAccessible(true);
    return (bool) $m->invoke($action, $docRoot);
};

$modeOf = static function (string $path): string {
    clearstatcache(true, $path);
    return substr(sprintf('%o', fileperms($path)), -4);
};

// Detect whether this filesystem honours full Unix permission bits.
// On the production Linux server it does, so we assert exact modes
// (0440/0444/0644). On dev filesystems that can't (NTFS reports only a
// read-only flag), we fall back to writability semantics so the suite
// still validates the harden/unlock behaviour rather than the OS.
if (!is_dir($root)) {
    @mkdir($root, 0755, true);
}
$chmodHonored = (function () use ($root, $modeOf): bool {
    $probe = $root . '/.chmod-probe';
    @file_put_contents($probe, 'x');
    @chmod($probe, 0640);
    $mode = is_file($probe) ? $modeOf($probe) : '';
    @chmod($probe, 0644);
    @unlink($probe);
    return $mode === '0640';
})();

// Assert a file's protection state. Returns an error string or null.
$assertMode = static function (string $path, string $expectedOctal) use ($modeOf, $chmodHonored): ?string {
    clearstatcache(true, $path);
    if ($chmodHonored) {
        $m = $modeOf($path);
        return $m === $expectedOctal ? null : "expected {$expectedOctal}, got {$m}";
    }
    // Fallback: owner-write bit of the expected mode vs actual writability.
    $shouldBeWritable = (octdec($expectedOctal) & 0o200) !== 0;
    $isWritable = is_writable($path);
    if ($shouldBeWritable !== $isWritable) {
        return 'expected ' . ($shouldBeWritable ? 'writable' : 'read-only')
            . ', got ' . ($isWritable ? 'writable' : 'read-only');
    }
    return null;
};

$secureFiles = static fn() => $invoke('actionSecureFiles', ['domain' => $domain]);
$unsecureFiles = static fn() => $invoke('actionUnsecureFiles', ['domain' => $domain]);

// ─── preflight ──────────────────────────────────────────────────

$harness->test('preflight', 'temp dir is usable; report chmod fidelity',
    function () use ($root, $chmodHonored) {
        if (!is_dir($root) && !mkdir($root, 0755, true)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "cannot create temp root: {$root}"];
        }
        if (!$chmodHonored) {
            return ['outcome' => TestHarness::WARN,
                'message' => 'filesystem does not honour full Unix modes; asserting writability only. Run on the Linux server for exact-mode coverage'];
        }
    });

$harness->test('preflight', 'unsecureFiles is a registered agent method',
    function () use ($action) {
        if (!in_array('unsecureFiles', $action->getMethods(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unsecureFiles missing from WordPressAction::getMethods()'];
        }
        if (!$action->requiresBackup('unsecureFiles')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unsecureFiles should require a backup (it edits wp-config.php)'];
        }
    });

$harness->test('preflight', 'getDocumentRoot resolves to the scratch docroot',
    function () use ($seedDocRoot, $invoke, $domain, $docRoot, $secureFiles) {
        $seedDocRoot();
        // secureFiles() short-circuits with "WordPress not found" unless
        // getDocumentRoot() + wp-config.php both resolve. A success proves
        // the fake vhost wiring is correct.
        $res = $secureFiles();
        if (($res['success'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'secureFiles could not resolve scratch docroot: ' . json_encode($res)];
        }
    });

// ─── harden ─────────────────────────────────────────────────────

$harness->test('harden', 'secureFiles locks wp-config, .htaccess and disables the editor',
    function () use ($seedDocRoot, $secureFiles, $assertMode, $editorDisabled, $docRoot) {
        $seedDocRoot();
        $res = $secureFiles();
        if (($res['success'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'secureFiles failed: ' . json_encode($res)];
        }
        if ($err = $assertMode($docRoot . '/wp-config.php', '0440')) {
            return ['outcome' => TestHarness::FAIL, 'message' => "wp-config.php {$err}"];
        }
        if ($err = $assertMode($docRoot . '/.htaccess', '0444')) {
            return ['outcome' => TestHarness::FAIL, 'message' => ".htaccess {$err}"];
        }
        if (!$editorDisabled()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DISALLOW_FILE_EDIT not detected after harden'];
        }
        if (strpos(file_get_contents($docRoot . '/wp-config.php'), "DISALLOW_FILE_EDIT") === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DISALLOW_FILE_EDIT define missing from wp-config.php'];
        }
        if (!file_exists($docRoot . '/wp-content/uploads/.htaccess')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'uploads/.htaccess not created by harden'];
        }
    });

// ─── unlock ─────────────────────────────────────────────────────

$harness->test('unlock', 'unsecureFiles unlocks files and removes DISALLOW_FILE_EDIT',
    function () use ($seedDocRoot, $secureFiles, $unsecureFiles, $assertMode, $editorDisabled, $docRoot) {
        $seedDocRoot();
        $secureFiles();              // start from a hardened state
        $res = $unsecureFiles();
        if (($res['success'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'unsecureFiles failed: ' . json_encode($res)];
        }
        if ($err = $assertMode($docRoot . '/wp-config.php', '0644')) {
            return ['outcome' => TestHarness::FAIL, 'message' => "wp-config.php {$err}"];
        }
        if ($err = $assertMode($docRoot . '/.htaccess', '0644')) {
            return ['outcome' => TestHarness::FAIL, 'message' => ".htaccess {$err}"];
        }
        if ($editorDisabled()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DISALLOW_FILE_EDIT still present after unlock'];
        }
        if (strpos(file_get_contents($docRoot . '/wp-config.php'), 'DISALLOW_FILE_EDIT') !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DISALLOW_FILE_EDIT define still in wp-config.php'];
        }
        // wp-config.php must still be a valid, loadable PHP file after the strip.
        $check = shell_exec('php -l ' . escapeshellarg($docRoot . '/wp-config.php') . ' 2>&1');
        if ($check !== null && stripos($check, 'No syntax errors') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wp-config.php no longer parses: ' . trim((string) $check)];
        }
        // Uploads protection is intentionally left in place.
        if (!file_exists($docRoot . '/wp-content/uploads/.htaccess')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'unlock should NOT remove uploads/.htaccess protection'];
        }
    });

$harness->test('unlock', 'unsecureFiles on an already-unlocked site is a clean no-op',
    function () use ($seedDocRoot, $unsecureFiles, $assertMode, $editorDisabled, $docRoot) {
        $seedDocRoot();              // fresh install: already 0644, no DISALLOW
        $res = $unsecureFiles();
        if (($res['success'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'unsecureFiles failed: ' . json_encode($res)];
        }
        if ($err = $assertMode($docRoot . '/wp-config.php', '0644')) {
            return ['outcome' => TestHarness::FAIL, 'message' => "wp-config.php {$err}"];
        }
        if ($editorDisabled()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'unexpected DISALLOW_FILE_EDIT on a fresh site'];
        }
    });

$harness->test('unlock', 'harden -> unlock cycle is repeatable and idempotent',
    function () use ($seedDocRoot, $secureFiles, $unsecureFiles, $assertMode, $editorDisabled, $docRoot) {
        for ($i = 0; $i < 3; $i++) {
            $seedDocRoot();
            $secureFiles();
            if (!$editorDisabled() || $assertMode($docRoot . '/wp-config.php', '0440')) {
                return ['outcome' => TestHarness::FAIL, 'message' => "harden state wrong on iteration {$i}"];
            }
            $unsecureFiles();
            $unsecureFiles();   // second call must be a safe no-op
            if ($editorDisabled() || $assertMode($docRoot . '/wp-config.php', '0644')) {
                return ['outcome' => TestHarness::FAIL, 'message' => "unlock state wrong on iteration {$i}"];
            }
        }
    });

$harness->test('unlock', 'unsecureFiles rejects a missing domain',
    function () use ($invoke) {
        $res = $invoke('actionUnsecureFiles', []);
        if (($res['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected failure when domain is absent'];
        }
    });

exit($harness->run());
