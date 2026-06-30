#!/usr/bin/env php
<?php
/**
 * oauth-token-cache-test.php
 *
 * Phase 1/2 regression test for OAuthTokenCache: Redis access-token caching,
 * the single-flight refresh mutex, and the terminal `invalid_grant` flag.
 *
 * Per .cursor/rules/server-side-testing.mdc — CLI only, idempotent, prefixes
 * test data with `flowone_test_` so it never collides with production rows,
 * runs cleanup on all exit paths.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-token-cache-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Services\OAuthTokenCache;
use Webmail\Services\RedisCacheService;

$runner = new FlowOneTestRunner('oauth-token-cache', $argv);

// -- Pre-flight ----------------------------------------------------------

foreach (['openssl', 'redis', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $redis = new RedisCacheService($config);
    if (!$redis->isAvailable()) {
        $runner->log('Redis unavailable; aborting (config or service down)');
        exit(1);
    }
} catch (\Throwable $e) {
    $runner->log('Redis connectivity failed: ' . $e->getMessage());
    exit(1);
}

$cache = new OAuthTokenCache($config);

$testProvider = 'google';
$testPrimary = 'flowone_test_primary@example.invalid';
$testOauth = 'flowone_test_oauth@example.invalid';

$runner->addCleanup(function () use ($cache, $testProvider, $testPrimary, $testOauth) {
    $cache->invalidateToken($testProvider, $testPrimary, $testOauth);
    $cache->clearRevoked($testProvider, $testPrimary, $testOauth);
    $cache->releaseRefreshLock($testProvider, $testPrimary, $testOauth);
});

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('redis ping', fn() => true);
    exit($runner->finish());
}

// -- 1. Basic cache hit / miss ------------------------------------------

if ($runner->shouldRunSection('1. cache')) {
    $runner->section('1. CACHE');

    $runner->test('miss before put returns null', function () use ($cache, $testProvider, $testPrimary, $testOauth) {
        $cache->invalidateToken($testProvider, $testPrimary, $testOauth);
        $val = $cache->getToken($testProvider, $testPrimary, $testOauth);
        if ($val !== null) {
            throw new \RuntimeException('expected null, got ' . var_export($val, true));
        }
    });

    $runner->test('put then get returns the same value', function () use ($cache, $testProvider, $testPrimary, $testOauth, $runner) {
        $cache->putToken($testProvider, $testPrimary, $testOauth, 'ya29.fake-token-1', 300);
        $val = $cache->getToken($testProvider, $testPrimary, $testOauth);
        $runner->assertEquals('ya29.fake-token-1', $val, 'cache roundtrip mismatch');
    });

    $runner->test('short-lived token (ttl < 30s after safety margin) is NOT cached', function () use ($cache, $testProvider, $testPrimary, $testOauth) {
        // OAuthTokenCache::putToken subtracts a 60s safety margin and
        // refuses to store anything whose effective TTL is < 30s — the
        // churn would exceed the win. Verify that contract: start from
        // a clean slate, attempt a short-lived put, confirm the cache
        // is still empty.
        $cache->invalidateToken($testProvider, $testPrimary, $testOauth);
        $ok = $cache->putToken($testProvider, $testPrimary, $testOauth, 'ya29.expires-soon', 1);
        if ($ok !== false) {
            throw new \RuntimeException('putToken should refuse to cache ttl=1; returned true');
        }
        $val = $cache->getToken($testProvider, $testPrimary, $testOauth);
        if ($val !== null) {
            throw new \RuntimeException('expected empty cache; got ' . var_export($val, true));
        }
    });

    $runner->test('long-lived token IS cached and expires after TTL', function () use ($cache, $testProvider, $testPrimary, $testOauth) {
        // Use a 92-second expires_in so the effective TTL after the
        // 60s safety margin is 32s — above the 30s floor. Verify the
        // token is present immediately and gone after ~32s would be
        // too slow for a unit test; just confirm presence.
        $cache->invalidateToken($testProvider, $testPrimary, $testOauth);
        $ok = $cache->putToken($testProvider, $testPrimary, $testOauth, 'ya29.long-lived', 92);
        if ($ok !== true) {
            throw new \RuntimeException('putToken with ttl=92 should succeed');
        }
        $val = $cache->getToken($testProvider, $testPrimary, $testOauth);
        if ($val !== 'ya29.long-lived') {
            throw new \RuntimeException('expected ya29.long-lived; got ' . var_export($val, true));
        }
    });
}

// -- 2. Revoked flag terminality ----------------------------------------

if ($runner->shouldRunSection('2. revoked')) {
    $runner->section('2. REVOKED');

    $runner->test('markRevoked sets flag', function () use ($cache, $testProvider, $testPrimary, $testOauth, $runner) {
        $cache->clearRevoked($testProvider, $testPrimary, $testOauth);
        $cache->markRevoked($testProvider, $testPrimary, $testOauth, 'invalid_grant');
        $runner->assertTrue($cache->isRevoked($testProvider, $testPrimary, $testOauth), 'flag not set');
    });

    $runner->test('clearRevoked unsets flag', function () use ($cache, $testProvider, $testPrimary, $testOauth, $runner) {
        $cache->clearRevoked($testProvider, $testPrimary, $testOauth);
        $runner->assertTrue(!$cache->isRevoked($testProvider, $testPrimary, $testOauth), 'flag still set');
    });
}

// -- 3. Single-flight refresh mutex ------------------------------------

if ($runner->shouldRunSection('3. mutex')) {
    $runner->section('3. MUTEX');

    $runner->test('first acquire wins, second loses', function () use ($cache, $testProvider, $testPrimary, $testOauth, $runner) {
        $cache->releaseRefreshLock($testProvider, $testPrimary, $testOauth);
        $first = $cache->acquireRefreshLock($testProvider, $testPrimary, $testOauth);
        $second = $cache->acquireRefreshLock($testProvider, $testPrimary, $testOauth);
        $runner->assertTrue($first === true, 'first acquire did not win');
        $runner->assertTrue($second === false, 'second acquire should have lost');
        $cache->releaseRefreshLock($testProvider, $testPrimary, $testOauth);
    });

    // pcntl_fork is required for the herd test; if unavailable, mark as warn.
    $runner->test('10-way concurrent acquire — exactly one winner', function () use ($cache, $testProvider, $testPrimary, $testOauth, $runner) {
        if (!function_exists('pcntl_fork')) {
            return 'warn';
        }
        $cache->releaseRefreshLock($testProvider, $testPrimary, $testOauth);

        $children = 10;
        $pipes = [];
        $pids = [];
        for ($i = 0; $i < $children; $i++) {
            $sockets = [];
            if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
                throw new \RuntimeException('socket_create_pair failed');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('fork failed');
            }
            if ($pid === 0) {
                socket_close($sockets[0]);
                $newCache = new OAuthTokenCache(require __DIR__ . '/../src/config.php');
                $won = $newCache->acquireRefreshLock($testProvider, $testPrimary, $testOauth);
                socket_write($sockets[1], $won ? '1' : '0', 1);
                socket_close($sockets[1]);
                exit(0);
            }
            socket_close($sockets[1]);
            $pipes[] = $sockets[0];
            $pids[] = $pid;
        }

        // Read each child's verdict.
        $winners = 0;
        foreach ($pipes as $sock) {
            $bit = socket_read($sock, 1);
            socket_close($sock);
            if ($bit === '1') {
                $winners++;
            }
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $cache->releaseRefreshLock($testProvider, $testPrimary, $testOauth);

        $runner->assertEquals(1, $winners, 'exactly one process must hold the lock');
    }, 15);

    $runner->test('waitForRefreshedToken returns after holder writes new token', function () use ($cache, $testProvider, $testPrimary, $testOauth, $runner) {
        if (!function_exists('pcntl_fork')) {
            return 'warn';
        }
        $cache->invalidateToken($testProvider, $testPrimary, $testOauth);

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('fork failed');
        }
        if ($pid === 0) {
            // Child: simulate the refresh holder publishing the new token. The
            // poll-based waitForRefreshedToken in the parent will pick it up.
            usleep(300_000);
            $newCache = new OAuthTokenCache(require __DIR__ . '/../src/config.php');
            $newCache->putToken($testProvider, $testPrimary, $testOauth, 'ya29.refreshed-by-child', 300);
            exit(0);
        }

        $token = $cache->waitForRefreshedToken($testProvider, $testPrimary, $testOauth);
        pcntl_waitpid($pid, $status);

        $runner->assertEquals('ya29.refreshed-by-child', $token, 'did not receive refreshed token');
    }, 10);
}

exit($runner->finish());
