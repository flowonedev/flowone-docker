#!/usr/bin/env php
<?php
/**
 * docker-stack-functional-test.php — Layer 2 functional roundtrips (Phase C).
 *
 * Run INSIDE the `web` container. Where Layer 1 (docker-stack-smoke-test.php)
 * proves the wires are connected, this proves the wires actually carry traffic:
 * each backing service does a real write -> read -> delete roundtrip through
 * the app's own service classes / clients.
 *
 *   - JWT:   SessionService signs a token and verifies it (RS256 PEM pair or
 *            HS256 secret), and a tampered token is rejected. This is the core
 *            of login — if it works, the keypair baked/mounted into the image
 *            is valid.
 *   - Redis: RedisCacheService set/get/delete roundtrip.
 *   - Meili: index a [FLOWONE-TEST] document into a throwaway index, wait for
 *            the task, search it back, then drop the index.
 *   - Auth (optional, needs --email=/--password=): real login -> token ->
 *            /api/auth/me -> logout against the local OLS, exercising the full
 *            HTTP + IMAP path. Skipped when creds are absent or --skip-send.
 *
 * Per .cursor/rules/server-side-testing.mdc — CLI only, non-destructive,
 * [FLOWONE-TEST] / flowone_test_ data, cleanup on every exit path, per-test
 * timeouts, timestamped log, exit 0/1.
 *
 * Run:
 *   docker exec flowone-web-1 /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/docker-stack-functional-test.php --verbose
 *   # with a live login roundtrip:
 *   docker exec flowone-web-1 /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/docker-stack-functional-test.php \
 *     --email=user@domain --password=secret --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Services\SessionService;
use Webmail\Services\RedisCacheService;

$runner = new FlowOneTestRunner('docker-stack-functional', $argv);
$config = require __DIR__ . '/../src/config.php';

// Parse optional credentials from the passthrough args.
$cliEmail = null;
$cliPass = null;
foreach ($runner->extra as $arg) {
    if (str_starts_with($arg, '--email=')) $cliEmail = substr($arg, 8);
    elseif (str_starts_with($arg, '--password=')) $cliPass = substr($arg, 11);
}

/** Minimal Meilisearch HTTP helper (master-key auth). Returns [httpCode, decodedBody]. */
$meili = function (string $method, string $path, $payload = null) use ($config): array {
    $host = rtrim((string) ($config['meilisearch']['host'] ?? ''), '/');
    $key = (string) ($config['meilisearch']['master_key'] ?? '');
    $ch = curl_init($host . $path);
    $headers = ['Content-Type: application/json'];
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new \RuntimeException("meili {$method} {$path} failed: {$err}");
    return [$code, json_decode((string) $body, true)];
};

/** Poll a Meili task to a terminal state. */
$meiliAwaitTask = function (int $taskUid) use ($meili): string {
    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline) {
        [$code, $task] = $meili('GET', '/tasks/' . $taskUid);
        $status = (string) ($task['status'] ?? '');
        if (in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
            return $status;
        }
        usleep(150_000);
    }
    return 'timeout';
};

$testIndex = 'flowone_test_stack';

// Always try to drop the throwaway index, even on failure/interrupt.
$runner->addCleanup(function () use ($meili, $testIndex, $runner) {
    try {
        $meili('DELETE', '/indexes/' . $testIndex);
        $runner->log("post-test cleanup: dropped meili index {$testIndex}");
    } catch (\Throwable $e) {
        $runner->log('cleanup (meili) note: ' . $e->getMessage());
    }
});

// --- 1. JWT SIGN/VERIFY ------------------------------------------------------

$runner->section('1. JWT');

if ($runner->shouldRunSection('1. JWT')) {

    $runner->test('SessionService signs and verifies a token', function () use ($config) {
        $svc = new SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
        $email = 'flowone_test_jwt@example.invalid';
        $token = $svc->createToken($email, ['type' => 'access']);
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            throw new \RuntimeException('createToken did not return a JWT');
        }
        $payload = $svc->validateToken($token);
        if (!is_array($payload)) throw new \RuntimeException('validateToken returned null for a freshly signed token');
        if (($payload['sub'] ?? null) !== $email) throw new \RuntimeException('sub claim mismatch');
        if (($payload['type'] ?? null) !== 'access') throw new \RuntimeException('type claim mismatch');
        return true;
    });

    $runner->test('tampered token is rejected', function () use ($config) {
        $svc = new SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
        $token = $svc->createToken('flowone_test_jwt@example.invalid');
        // Flip a character in the signature segment.
        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);
        $tampered = implode('.', $parts);
        if ($svc->validateToken($tampered) !== null) {
            throw new \RuntimeException('validateToken accepted a tampered signature');
        }
        return true;
    });
}

// --- 2. REDIS ROUNDTRIP ------------------------------------------------------

$runner->section('2. REDIS');

if ($runner->shouldRunSection('2. REDIS')) {

    $runner->test('RedisCacheService set/get/delete roundtrip', function () use ($config) {
        $cache = new RedisCacheService($config);
        if (!$cache->isAvailable()) throw new \RuntimeException('redis not available to RedisCacheService');
        $key = 'flowone_test_stack_' . bin2hex(random_bytes(4));
        $value = ['marker' => '[FLOWONE-TEST]', 'ts' => time()];
        if (!$cache->set($key, $value, 60)) throw new \RuntimeException('set returned false');
        $got = $cache->get($key);
        if (!is_array($got) || ($got['marker'] ?? null) !== '[FLOWONE-TEST]') {
            throw new \RuntimeException('get did not return the stored value: ' . var_export($got, true));
        }
        if (!$cache->delete($key)) throw new \RuntimeException('delete returned false');
        if ($cache->get($key) !== null) throw new \RuntimeException('value still present after delete');
        return true;
    });
}

// --- 3. MEILISEARCH ROUNDTRIP ------------------------------------------------

$runner->section('3. MEILISEARCH');

if ($runner->shouldRunSection('3. MEILISEARCH')) {

    $runner->test('index a doc, await task, search it back', function () use ($meili, $meiliAwaitTask, $testIndex) {
        // Clean slate (ignore "index not found").
        try { $meili('DELETE', '/indexes/' . $testIndex); } catch (\Throwable $e) {}

        $docId = 'flowone_test_' . bin2hex(random_bytes(4));
        $marker = 'FLOWONETESTNEEDLE' . bin2hex(random_bytes(3));
        [$code, $resp] = $meili('POST', '/indexes/' . $testIndex . '/documents', [
            ['id' => $docId, 'title' => '[FLOWONE-TEST] ' . $marker],
        ]);
        if ($code >= 400) throw new \RuntimeException("add documents HTTP {$code}: " . json_encode($resp));
        $taskUid = $resp['taskUid'] ?? $resp['uid'] ?? null;
        if ($taskUid === null) throw new \RuntimeException('no taskUid from add documents');

        $status = $meiliAwaitTask((int) $taskUid);
        if ($status !== 'succeeded') throw new \RuntimeException("indexing task did not succeed: {$status}");

        [$scode, $sresp] = $meili('POST', '/indexes/' . $testIndex . '/search', ['q' => $marker]);
        if ($scode >= 400) throw new \RuntimeException("search HTTP {$scode}");
        $hits = $sresp['hits'] ?? [];
        $found = false;
        foreach ($hits as $h) {
            if (($h['id'] ?? null) === $docId) { $found = true; break; }
        }
        if (!$found) throw new \RuntimeException('indexed doc not found via search');
        return true;
    });
}

// --- 4. AUTH (optional, credentialed) ---------------------------------------

$runner->section('4. AUTH');

if ($runner->shouldRunSection('4. AUTH')) {

    $runner->test('login -> /me -> logout (real IMAP path)', function () use ($runner, $cliEmail, $cliPass) {
        if ($runner->skipSend) return 'skip';
        if (!$cliEmail || !$cliPass) return 'skip';

        $base = 'http://localhost/api';
        $http = function (string $method, string $path, ?array $json = null, ?string $bearer = null) use ($base) {
            $ch = curl_init($base . $path);
            $headers = ['Content-Type: application/json'];
            if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
            ]);
            if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, json_decode((string) $body, true)];
        };

        [$lc, $lr] = $http('POST', '/auth/login', ['email' => $cliEmail, 'password' => $cliPass]);
        if ($lc !== 200) throw new \RuntimeException("login HTTP {$lc}: " . json_encode($lr));
        $token = $lr['access_token'] ?? $lr['token'] ?? ($lr['data']['access_token'] ?? null);
        if (!$token) throw new \RuntimeException('login succeeded but no access token in response');

        [$mc, $mr] = $http('GET', '/auth/me', null, $token);
        if ($mc !== 200) throw new \RuntimeException("/auth/me HTTP {$mc}");

        // Best-effort logout so we don't leave a session behind.
        $http('POST', '/auth/logout', null, $token);
        return true;
    });
}

exit($runner->finish());
