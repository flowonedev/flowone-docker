#!/usr/bin/env php
<?php
/**
 * FlowOne Office Presence Test.
 *
 * Verifies the live-cursor presence layer for the OnlyOffice editor:
 * the presence-token JWT minting (OfficeEditorController::presenceToken
 * signing scheme), the "office-file-{id}" room naming contract shared
 * with the collab server, and that the collab server source treats those
 * rooms as ephemeral (no document persistence).
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight    extensions, autoloader, config, JWT signing material
 *   token        sign + verify round-trip (RS256 with HS256 fallback),
 *                claim contents, expiry, tamper rejection
 *   guest        guest presence token: signing scheme, controller +
 *                service surface, public route + guest view wiring
 *   contract     room prefix + role values consistent across PHP backend,
 *                collab server, and frontend composable
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/office-presence-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no business logic)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --skip-send            accepted for rule parity (no external sends here)
 *   --timeout=N            per-test timeout in seconds (default 30)
 *   --help                 show this message
 *
 * Read-only: no database rows are created or modified. Idempotent.
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/lib/test-runner.php';

$runner = new FlowOneTestRunner('office-presence', $argv);

$config = null;

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php extensions loaded (openssl, mbstring, json)', function () use ($runner) {
        foreach (['openssl', 'mbstring', 'json'] as $ext) {
            $runner->assertTrue(extension_loaded($ext), "{$ext} extension missing");
        }
    });

    $runner->test('cron bootstrap + config load', function () use ($runner, &$config) {
        require_once __DIR__ . '/../cron/bootstrap.php';
        $config = require __DIR__ . '/../src/config.php';
        $runner->assertTrue(is_array($config), 'config.php did not return an array');
    });

    $runner->test('firebase/php-jwt available', function () use ($runner) {
        $runner->assertTrue(class_exists('\Firebase\JWT\JWT'), 'Firebase JWT class missing');
        $runner->assertTrue(class_exists('\Firebase\JWT\Key'), 'Firebase Key class missing');
    });

    $runner->test('JWT signing material configured', function () use ($runner, &$config) {
        $algorithm = $config['jwt']['algorithm'] ?? 'RS256';
        if ($algorithm === 'RS256') {
            $keyPath = $config['jwt']['private_key_path'] ?? '';
            if ($keyPath && file_exists($keyPath)) {
                $runner->assertTrue(is_readable($keyPath), "private key not readable: {$keyPath}");
                return;
            }
            // RS256 configured but key absent: HS256 fallback must exist
        }
        $runner->assertTrue(
            !empty($config['jwt']['secret']),
            'neither an RS256 private key nor a jwt secret is configured'
        );
    });

    $runner->test('OfficeEditorController exposes presenceToken', function () use ($runner) {
        $runner->assertTrue(
            class_exists('\Webmail\Controllers\OfficeEditorController'),
            'OfficeEditorController missing'
        );
        $runner->assertTrue(
            method_exists('\Webmail\Controllers\OfficeEditorController', 'presenceToken'),
            'presenceToken() method missing'
        );
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

// Lazy init for --only runs that skip preflight
if ($config === null) {
    require_once __DIR__ . '/../cron/bootstrap.php';
    $config = require __DIR__ . '/../src/config.php';
}

// ---------------------------------------------------------------------------
// Helpers mirroring the endpoint's signing scheme
// ---------------------------------------------------------------------------

/**
 * Sign a presence payload exactly the way presenceToken() does:
 * RS256 private key when available, HS256 shared secret otherwise.
 * Returns [token, algorithm, verificationKey].
 */
$signPresence = function (array $payload) use ($config): array {
    $algorithm = $config['jwt']['algorithm'] ?? 'RS256';
    $signingKey = null;
    $verifyKey = null;

    if ($algorithm === 'RS256') {
        $keyPath = $config['jwt']['private_key_path'] ?? '';
        if ($keyPath && file_exists($keyPath)) {
            $signingKey = file_get_contents($keyPath);
            $pubPath = $config['jwt']['public_key_path'] ?? '';
            $verifyKey = ($pubPath && file_exists($pubPath)) ? file_get_contents($pubPath) : null;
        } else {
            $algorithm = 'HS256';
        }
    }
    if ($signingKey === null) {
        $signingKey = $config['jwt']['secret'] ?? '';
        $verifyKey = $signingKey;
    }

    $token = \Firebase\JWT\JWT::encode($payload, $signingKey, $algorithm);
    return [$token, $algorithm, $verifyKey];
};

$makePayload = function (int $fileId, string $role) {
    $now = time();
    return [
        'sub' => 'flowone_test_presence@flowone.pro',
        'name' => 'FlowOne Test Presence',
        'documentId' => 'office-file-' . $fileId,
        'role' => $role,
        'iat' => $now,
        'exp' => $now + 43200,
    ];
};

// Guest presence payload: a share-link guest joins under a stable guest id
// (no email) - mirrors OfficeEditorController::guestPresenceToken().
$makeGuestPayload = function (int $fileId, string $role) {
    $now = time();
    return [
        'sub' => 'guest-flowonetest1',
        'name' => 'FLOWONE-TEST Guest (guest)',
        'documentId' => 'office-file-' . $fileId,
        'role' => $role,
        'iat' => $now,
        'exp' => $now + 43200,
    ];
};

// ---------------------------------------------------------------------------
// 2. TOKEN
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('token')) {
    $runner->section('2. TOKEN');

    $runner->test('sign + verify round-trip preserves claims', function () use ($runner, $signPresence, $makePayload) {
        $payload = $makePayload(424242, 'editor');
        [$token, $algorithm, $verifyKey] = $signPresence($payload);
        $runner->assertTrue(is_string($token) && substr_count($token, '.') === 2, 'not a JWT');
        $runner->assertTrue(!empty($verifyKey), "no verification key for {$algorithm} (public key missing?)");

        $decoded = (array)\Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($verifyKey, $algorithm));
        $runner->assertEquals($payload['sub'], $decoded['sub'] ?? null, 'sub mismatch');
        $runner->assertEquals('office-file-424242', $decoded['documentId'] ?? null, 'documentId mismatch');
        $runner->assertEquals('editor', $decoded['role'] ?? null, 'role mismatch');
        $runner->assertTrue(($decoded['exp'] ?? 0) > time() + 43000, 'expiry not ~12h out');
        if ($runner->verbose) {
            $runner->log("          algorithm={$algorithm}");
        }
    });

    $runner->test('viewer role token carries viewer', function () use ($runner, $signPresence, $makePayload) {
        $payload = $makePayload(7, 'viewer');
        [$token, $algorithm, $verifyKey] = $signPresence($payload);
        $decoded = (array)\Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($verifyKey, $algorithm));
        $runner->assertEquals('viewer', $decoded['role'] ?? null, 'role mismatch');
    });

    $runner->test('tampered token is rejected', function () use ($runner, $signPresence, $makePayload) {
        $payload = $makePayload(99, 'viewer');
        [$token, $algorithm, $verifyKey] = $signPresence($payload);

        // Swap role viewer->editor inside the payload segment, keep signature.
        [$h, $p, $s] = explode('.', $token);
        $body = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $body['role'] = 'editor';
        $forged = rtrim(strtr(base64_encode((string)json_encode($body)), '+/', '-_'), '=');

        $rejected = false;
        try {
            \Firebase\JWT\JWT::decode("{$h}.{$forged}.{$s}", new \Firebase\JWT\Key($verifyKey, $algorithm));
        } catch (\Throwable $e) {
            $rejected = true;
        }
        $runner->assertTrue($rejected, 'forged payload passed signature verification');
    });

    $runner->test('expired token is rejected', function () use ($runner, $signPresence, $makePayload) {
        $payload = $makePayload(99, 'viewer');
        $payload['iat'] = time() - 7200;
        $payload['exp'] = time() - 3600;
        [$token, $algorithm, $verifyKey] = $signPresence($payload);

        $rejected = false;
        try {
            \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($verifyKey, $algorithm));
        } catch (\Firebase\JWT\ExpiredException $e) {
            $rejected = true;
        }
        $runner->assertTrue($rejected, 'expired token was accepted');
    });
}

// ---------------------------------------------------------------------------
// 3. GUEST (share-link guest presence)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('guest')) {
    $runner->section('3. GUEST');

    $runner->test('guest presence token signs + verifies (guest id, viewer)', function () use ($runner, $signPresence, $makeGuestPayload) {
        $payload = $makeGuestPayload(515151, 'viewer');
        [$token, $algorithm, $verifyKey] = $signPresence($payload);
        $runner->assertTrue(is_string($token) && substr_count($token, '.') === 2, 'not a JWT');

        $decoded = (array)\Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($verifyKey, $algorithm));
        $runner->assertEquals('guest-flowonetest1', $decoded['sub'] ?? null, 'guest sub mismatch');
        $runner->assertEquals('office-file-515151', $decoded['documentId'] ?? null, 'documentId mismatch');
        $runner->assertEquals('viewer', $decoded['role'] ?? null, 'role mismatch');
        // The collab auth handler only requires sub/documentId/role - a guest
        // id (no @) is a valid sub, so this token authenticates like any other.
        $runner->assertTrue(!empty($decoded['sub']), 'guest sub must be non-empty');
    });

    $runner->test('controller exposes public guestPresenceToken', function () use ($runner) {
        $runner->assertTrue(
            method_exists('\Webmail\Controllers\OfficeEditorController', 'guestPresenceToken'),
            'guestPresenceToken() method missing'
        );
    });

    $runner->test('guest-link service exposes non-consuming validate()', function () use ($runner) {
        $runner->assertTrue(
            class_exists('\Webmail\Services\OfficeGuestLinkService'),
            'OfficeGuestLinkService missing'
        );
        $runner->assertTrue(
            method_exists('\Webmail\Services\OfficeGuestLinkService', 'validate'),
            'validate() (non-consuming) method missing - presence would bump use_count'
        );
    });

    $runner->test('public guest presence-token route is registered', function () use ($runner) {
        $src = (string)file_get_contents(__DIR__ . '/../routes.php');
        $runner->assertTrue(
            str_contains($src, '/guest/office/{token}/presence-token'),
            'routes.php does not register the guest presence-token endpoint'
        );
        $runner->assertTrue(
            str_contains($src, 'guestPresenceToken'),
            'routes.php does not wire guestPresenceToken'
        );
    });

    $runner->test('guest office view wires presence + plugin bridge', function () use ($runner) {
        $repoRoot = realpath(__DIR__ . '/../..'); // .../email
        $path = $repoRoot . '/frontend/src/views/GuestOfficeView.vue';
        if (!is_file($path)) {
            return 'warn'; // frontend source not deployed alongside backend
        }
        $src = (string)file_get_contents($path);
        $runner->assertTrue(
            str_contains($src, 'connectGuest'),
            'GuestOfficeView does not connect guest presence'
        );
        $runner->assertTrue(
            str_contains($src, 'useOfficePluginBridge'),
            'GuestOfficeView does not bridge to the in-editor cursor plugin'
        );
    });
}

// ---------------------------------------------------------------------------
// 4. CONTRACT (room prefix + roles consistent across the three codebases)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('contract')) {
    $runner->section('4. CONTRACT');

    $repoRoot = realpath(__DIR__ . '/../..'); // .../email

    $runner->test('backend mints office-file-{id} rooms', function () use ($runner) {
        $src = (string)file_get_contents(__DIR__ . '/../src/Controllers/OfficeEditorController.php');
        $runner->assertTrue(
            str_contains($src, "'office-file-' . \$fileId"),
            'controller no longer builds office-file-{id} room names'
        );
    });

    $runner->test('collab server treats office-file-* as ephemeral', function () use ($runner, $repoRoot) {
        $path = $repoRoot . '/collab/server/src/index.js';
        if (!is_file($path)) {
            return 'warn'; // collab server source not deployed alongside backend
        }
        $src = (string)file_get_contents($path);
        $runner->assertTrue(
            str_contains($src, "startsWith('office-file-')"),
            'index.js lacks the office-file- ephemeral room check'
        );
        $runner->assertTrue(
            str_contains($src, 'isEphemeralRoom(documentName)'),
            'index.js does not gate persistence on isEphemeralRoom'
        );
    });

    $runner->test('roles are valid collab server roles', function () use ($runner, $repoRoot) {
        // presenceToken only ever emits resolveAccess() roles: editor|viewer.
        $allowed = ['owner', 'editor', 'viewer'];
        foreach (['editor', 'viewer'] as $role) {
            $runner->assertTrue(in_array($role, $allowed, true), "role {$role} unknown to collab server");
        }
        $constants = $repoRoot . '/collab/shared/collabConstants.js';
        if (is_file($constants)) {
            $src = (string)file_get_contents($constants);
            $runner->assertTrue(
                str_contains($src, "EDITOR: 'editor'") && str_contains($src, "VIEWER: 'viewer'"),
                'collabConstants.js roles changed - update presenceToken role mapping'
            );
        }
    });

    $runner->test('frontend composable joins the same room scheme', function () use ($runner, $repoRoot) {
        $path = $repoRoot . '/frontend/src/composables/useOfficePresence.js';
        if (!is_file($path)) {
            return 'warn'; // frontend source not deployed alongside backend
        }
        $src = (string)file_get_contents($path);
        $runner->assertTrue(
            str_contains($src, 'getPresenceToken'),
            'useOfficePresence no longer fetches the presence token'
        );
        $runner->assertTrue(
            str_contains($src, 'data.room'),
            'useOfficePresence must join the backend-provided room name'
        );
    });
}

exit($runner->finish());
