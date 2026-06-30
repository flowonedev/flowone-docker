#!/usr/bin/env php
<?php
/**
 * NasAdapter Test Suite
 *
 * Validates the probe hierarchy:
 *   - BASIC:        is_dir
 *   - HEALTHFILE:   .healthcheck present
 *   - WRITABLE:     write/read/delete a probe file
 *
 * Uses a sandbox tmpdir for unit-style coverage of the probe logic and
 * also runs against the real /mnt/nas-drive when available.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/nas-adapter-test.php --verbose
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

use VpsAdmin\Agent\Provisioner\Adapters\NasAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('NasAdapter', $opts);

$sandbox = realpath(sys_get_temp_dir()) . '/flowone_test_nas_' . bin2hex(random_bytes(4));
mkdir($sandbox, 0755, true);

$harness->onCleanup(function () use ($sandbox): void {
    foreach (glob($sandbox . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($sandbox);
});

$runner = new ProcessCommandRunner();

// ── BASIC: directory exists ──────────────────────────────────
$harness->test('basic', 'returns true for existing dir',
    function () use ($runner, $sandbox) {
        $a = new NasAdapter($runner, $sandbox, useStatMountCheck: false);
        if (!$a->isAvailable(NasAdapter::PROBE_DEPTH_BASIC)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected true'];
        }
    });

$harness->test('basic', 'returns false for non-existent dir',
    function () use ($runner) {
        $a = new NasAdapter($runner, '/this/does/not/exist/' . bin2hex(random_bytes(4)), useStatMountCheck: false);
        if ($a->isAvailable(NasAdapter::PROBE_DEPTH_BASIC)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected false'];
        }
    });

// ── HEALTHFILE: needs .healthcheck ───────────────────────────
$harness->test('healthfile', 'returns false when .healthcheck missing',
    function () use ($runner, $sandbox) {
        $a = new NasAdapter($runner, $sandbox, useStatMountCheck: false);
        if ($a->isAvailable(NasAdapter::PROBE_DEPTH_HEALTHFILE)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected false'];
        }
    });

$harness->test('healthfile', 'returns true when .healthcheck present',
    function () use ($runner, $sandbox) {
        file_put_contents($sandbox . '/.healthcheck', '1');
        $a = new NasAdapter($runner, $sandbox, useStatMountCheck: false);
        try {
            if (!$a->isAvailable(NasAdapter::PROBE_DEPTH_HEALTHFILE)) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'expected true'];
            }
        } finally {
            @unlink($sandbox . '/.healthcheck');
        }
    });

// ── WRITABLE: round-trip ─────────────────────────────────────
$harness->test('writable', 'WRITABLE depth round-trips a probe file',
    function () use ($runner, $sandbox) {
        file_put_contents($sandbox . '/.healthcheck', '1');
        $a = new NasAdapter($runner, $sandbox, useStatMountCheck: false);
        try {
            if (!$a->isAvailable(NasAdapter::PROBE_DEPTH_WRITABLE)) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'expected true'];
            }
            // The probe file must NOT persist after the call.
            foreach (glob($sandbox . '/.flowone-probe-*') as $leak) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'probe file leaked: ' . $leak];
            }
        } finally {
            @unlink($sandbox . '/.healthcheck');
        }
    });

// ── diagnose() ───────────────────────────────────────────────
$harness->test('diagnose', 'returns a complete snapshot',
    function () use ($runner, $sandbox) {
        $a = new NasAdapter($runner, $sandbox, useStatMountCheck: false);
        $d = $a->diagnose();
        $expectedKeys = ['mountPoint', 'exists', 'mounted', 'healthFile', 'writable', 'probeMs'];
        foreach ($expectedKeys as $k) {
            if (!array_key_exists($k, $d)) {
                return ['outcome' => TestHarness::FAIL, 'message' => "missing key: {$k}"];
            }
        }
        if ($d['mountPoint'] !== $sandbox) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'mountPoint wrong'];
        }
        if (!$d['exists']) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'exists should be true'];
        }
    });

// ── real NAS mount (auto-skips when not present) ─────────────
$harness->test('live', 'real /mnt/nas-drive diagnose() returns sensible shape',
    function () use ($runner) {
        if (!is_dir(NasAdapter::DEFAULT_MOUNT)) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'real NAS mount not present'];
        }
        $a = new NasAdapter($runner);
        $d = $a->diagnose();
        if ($d['mountPoint'] !== NasAdapter::DEFAULT_MOUNT) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'mountPoint wrong'];
        }
        if (!is_int($d['probeMs']) || $d['probeMs'] < 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'probeMs malformed'];
        }
        // Don't fail the suite on actual NAS state - that's an
        // operational signal, not a unit-test concern. Just log it.
    });

exit($harness->run());
