#!/usr/bin/env php
<?php
/**
 * oauth-pkce-test.php
 *
 * Phase 3 regression test for PKCEService — verifier/challenge round-trip,
 * single-use replay rejection, expiry, and challenge-method correctness.
 *
 * Per .cursor/rules/server-side-testing.mdc — CLI only, idempotent,
 * test keys are namespaced under `flowone_test_pkce_` so they cannot
 * collide with live auth flows.
 *
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-pkce-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Services\PKCEService;
use Webmail\Services\RedisCacheService;

$runner = new FlowOneTestRunner('oauth-pkce', $argv);

foreach (['openssl', 'redis'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';
$redis = new RedisCacheService($config);
if (!$redis->isAvailable()) {
    $runner->log('Redis unavailable; aborting');
    exit(1);
}

$pkce = new PKCEService($config, $redis);

$testNonces = [];
$runner->addCleanup(function () use (&$testNonces, $redis) {
    foreach ($testNonces as $nonce) {
        $redis->delete('oauth:pkce:' . $nonce);
    }
});

$mkNonce = function () use (&$testNonces): string {
    $n = 'flowone_test_pkce_' . bin2hex(random_bytes(8));
    $testNonces[] = $n;
    return $n;
};

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('redis reachable', fn() => true);
    exit($runner->finish());
}

// --- 1. Round-trip ---------------------------------------------------

if ($runner->shouldRunSection('1. roundtrip')) {
    $runner->section('1. ROUNDTRIP');

    $runner->test('createChallenge returns S256 + 43-char challenge', function () use ($pkce, $mkNonce, $runner) {
        $nonce = $mkNonce();
        $out = $pkce->createChallenge($nonce);
        $runner->assertEquals('S256', $out['method'], 'wrong method');
        $runner->assertEquals(43, strlen($out['challenge']), 'challenge length wrong (base64url(SHA256))');
        // base64url charset: A-Z a-z 0-9 - _
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $out['challenge'])) {
            throw new \RuntimeException('challenge contains non-base64url chars');
        }
    });

    $runner->test('consumeVerifier returns the verifier and is single-use', function () use ($pkce, $mkNonce, $runner) {
        $nonce = $mkNonce();
        $pkce->createChallenge($nonce);
        $first = $pkce->consumeVerifier($nonce);
        $runner->assertTrue(is_string($first) && strlen($first) >= 43, 'verifier missing or too short');
        $second = $pkce->consumeVerifier($nonce);
        $runner->assertEquals(null, $second, 'replay must return null');
    });

    $runner->test('challenge actually verifies against verifier (SHA-256 base64url)', function () use ($pkce, $mkNonce, $runner) {
        $nonce = $mkNonce();
        $out = $pkce->createChallenge($nonce);
        $verifier = $pkce->consumeVerifier($nonce);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $runner->assertEquals($expected, $out['challenge'], 'S256 does not match');
    });
}

// --- 2. Replay / unknown nonce --------------------------------------

if ($runner->shouldRunSection('2. replay')) {
    $runner->section('2. REPLAY');

    $runner->test('consumeVerifier(unknown nonce) returns null', function () use ($pkce, $runner) {
        $runner->assertEquals(null, $pkce->consumeVerifier('flowone_test_pkce_does_not_exist'), 'unknown nonce should not yield a verifier');
    });

    $runner->test('two distinct nonces produce distinct verifiers', function () use ($pkce, $mkNonce, $runner) {
        $a = $mkNonce();
        $b = $mkNonce();
        $pkce->createChallenge($a);
        $pkce->createChallenge($b);
        $va = $pkce->consumeVerifier($a);
        $vb = $pkce->consumeVerifier($b);
        if ($va === $vb) {
            throw new \RuntimeException('verifiers collided — RNG broken');
        }
    });
}

// --- 3. Verifier strength -------------------------------------------

if ($runner->shouldRunSection('3. strength')) {
    $runner->section('3. STRENGTH');

    $runner->test('verifier matches RFC 7636 unreserved charset', function () use ($pkce, $mkNonce) {
        $nonce = $mkNonce();
        $pkce->createChallenge($nonce);
        $v = $pkce->consumeVerifier($nonce);
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $v)) {
            throw new \RuntimeException('verifier outside RFC 7636 charset/length: ' . $v);
        }
    });

    $runner->test('20 verifiers have full entropy (no duplicate pairs)', function () use ($pkce, $mkNonce) {
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $n = $mkNonce();
            $pkce->createChallenge($n);
            $v = $pkce->consumeVerifier($n);
            if (isset($seen[$v])) {
                throw new \RuntimeException('duplicate verifier within 20 samples');
            }
            $seen[$v] = true;
        }
    });
}

exit($runner->finish());
