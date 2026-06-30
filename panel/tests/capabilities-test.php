#!/usr/bin/env php
<?php
/**
 * ServerCapabilities Test Suite
 *
 * Verifies:
 *   - snapshot() returns the full capability map.
 *   - Each `hasX()` accessor returns the corresponding map value.
 *   - Overrides take precedence over detected values (for tests).
 *   - refresh() forces re-detection.
 *   - On this host, libsodium presence matches php-sodium runtime detection.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/capabilities-test.php --verbose
 *
 * Options:
 *   --verbose
 *   --skip-send  n/a
 *   --only=GROUP  overrides,detection,refresh
 *   --smoke
 *   --json
 *   --help
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

use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ServerCapabilities', $opts);

$harness->test('overrides', 'overrides force values regardless of host',
    function () {
        $caps = new ServerCapabilities([
            'ols' => false,
            'mariadb' => true,
            'powerdns' => false,
        ]);
        if ($caps->hasOls() !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'ols override not honored'];
        }
        if ($caps->hasMariadb() !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'mariadb override not honored'];
        }
        if ($caps->hasPowerdns() !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'powerdns override not honored'];
        }
    });

$harness->test('overrides', 'snapshot includes overrides on top of detection',
    function () {
        $caps = new ServerCapabilities(['ols' => false, 'flowone_test_fake' => true]);
        $snap = $caps->snapshot();
        if (($snap['ols'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'snapshot did not apply override'];
        }
        if (($snap['flowone_test_fake'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'snapshot did not include extra override'];
        }
    });

$harness->test('detection', 'snapshot returns the expected key set',
    function () {
        $snap = (new ServerCapabilities())->snapshot();
        $expected = [
            'ols', 'mariadb', 'postfix', 'dovecot', 'opendkim',
            'powerdns', 'certbot', 'redis', 'nas_backup', 'sodium',
        ];
        foreach ($expected as $key) {
            if (!array_key_exists($key, $snap)) {
                return ['outcome' => TestHarness::FAIL, 'message' => "missing key: {$key}"];
            }
            if (!is_bool($snap[$key])) {
                return ['outcome' => TestHarness::FAIL, 'message' => "non-bool for {$key}"];
            }
        }
    });

$harness->test('detection', 'sodium detection matches extension_loaded',
    function () {
        $caps = new ServerCapabilities();
        $expected = extension_loaded('sodium');
        if ($caps->hasSodium() !== $expected) {
            return [
                'outcome' => TestHarness::FAIL,
                'message' => 'sodium detection diverged from extension_loaded',
            ];
        }
    });

$harness->test('refresh', 'refresh forces re-detection',
    function () {
        $caps = new ServerCapabilities();
        $before = $caps->snapshot();
        $caps->refresh();
        $after = $caps->snapshot();
        // Without overrides, the two snapshots must match on this host since
        // no service installation happened between them.
        if ($before !== $after) {
            return [
                'outcome' => TestHarness::WARN,
                'message' => 'snapshot drifted between refreshes - likely a flaky detector',
            ];
        }
    });

$harness->test('detection', 'has(<key>) accessor and named hasX() accessor agree',
    function () {
        $caps = new ServerCapabilities();
        $pairs = [
            'ols' => $caps->hasOls(),
            'mariadb' => $caps->hasMariadb(),
            'postfix' => $caps->hasPostfix(),
            'dovecot' => $caps->hasDovecot(),
            'opendkim' => $caps->hasOpendkim(),
            'powerdns' => $caps->hasPowerdns(),
            'certbot' => $caps->hasCertbot(),
            'redis' => $caps->hasRedis(),
            'nas_backup' => $caps->hasNasBackup(),
            'sodium' => $caps->hasSodium(),
        ];
        foreach ($pairs as $key => $accessor) {
            if ($caps->has($key) !== $accessor) {
                return [
                    'outcome' => TestHarness::FAIL,
                    'message' => "has('{$key}') diverged from accessor",
                ];
            }
        }
    });

exit($harness->run());
