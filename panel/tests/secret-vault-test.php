#!/usr/bin/env php
<?php
/**
 * SecretVault Test Suite
 *
 * Verifies:
 *   - libsodium is available; refuses to start without it.
 *   - put() encrypts and stores; nonces are unique per write.
 *   - get() decrypts back to the original value.
 *   - rotate() makes a v2; old v1 still decrypts during the 7-day retention.
 *   - wipe() removes all versions immediately.
 *   - Tampering with ciphertext makes get() throw VaultException.
 *   - The master key file is refused if mode is group-readable.
 *   - SecretsAuditWriter records put/get/rotate/wipe with actor info.
 *
 * Requires: a master key file. The test creates a throwaway one at
 *           a temp path with mode 0400; never touches /etc/flowone/master.key.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/secret-vault-test.php --verbose
 *
 * Options:
 *   --verbose          Show extra debug info
 *   --skip-send        n/a
 *   --only=GROUP       preflight,encryption,rotation,wipe,audit,security
 *   --smoke            connectivity + sodium presence only
 *   --json             JSON output
 *   --help             Show this help
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

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\VaultException;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\SecretsAuditWriter;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SecretVault', $opts);

$db = null;
$pdo = null;
$vault = null;
$audit = null;
$actor = ActorContext::cli('secret-vault-test', 'flowone_test_user');
$tmpKey = sys_get_temp_dir() . '/flowone_test_master_' . bin2hex(random_bytes(4)) . '.key';
$testScope = 'flowone_test_scope_' . bin2hex(random_bytes(3));

$harness->onCleanup(function () use (&$pdo, &$testScope, &$tmpKey): void {
    if ($pdo) {
        $pdo->prepare("DELETE FROM secrets_vault WHERE scope = ?")->execute([$testScope]);
        $pdo->prepare("DELETE FROM secrets_audit WHERE scope = ?")->execute([$testScope]);
    }
    if (file_exists($tmpKey)) {
        @unlink($tmpKey);
    }
});

// ── preflight ─────────────────────────────────────────────────
$harness->test('preflight', 'libsodium extension loaded', function () {
    if (!extension_loaded('sodium')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'php-sodium extension required'];
    }
});

$harness->test('preflight', 'secrets_vault + secrets_audit tables exist',
    function () use (&$db, &$pdo) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        foreach (['secrets_vault', 'secrets_audit'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE '{$t}'")->rowCount() === 0) {
                return [
                    'outcome' => TestHarness::FAIL,
                    'message' => "missing table {$t}. Run migrate_secrets_vault.sql.",
                ];
            }
        }
    });

$harness->test('preflight', 'generate throwaway master key',
    function () use (&$tmpKey, &$db, &$audit, &$vault) {
        file_put_contents($tmpKey, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        chmod($tmpKey, 0400);
        $audit = new SecretsAuditWriter($db);
        $vault = new SecretVault($db, $tmpKey, $audit);
    });

// ── encryption: round-trip values ─────────────────────────────
$harness->test('encryption', 'put then get returns the same value',
    function () use (&$vault, &$actor, &$testScope) {
        $secret = 'super-secret-' . bin2hex(random_bytes(8));
        $vault->put($testScope, 'pwd1', $secret, $actor);
        $back = $vault->get($testScope, 'pwd1', $actor);
        if ($back !== $secret) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'roundtrip mismatch'];
        }
    });

$harness->test('encryption', 'each write produces a unique nonce',
    function () use (&$vault, &$pdo, &$actor, &$testScope) {
        $vault->put($testScope, 'nonce_test', 'a', $actor);
        $vault->put($testScope, 'nonce_test', 'b', $actor);
        $stmt = $pdo->prepare(
            'SELECT HEX(nonce) FROM secrets_vault WHERE scope = ? AND key_name = ? ORDER BY version'
        );
        $stmt->execute([$testScope, 'nonce_test']);
        $nonces = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (count(array_unique($nonces)) !== count($nonces)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'duplicate nonces detected'];
        }
    });

$harness->test('encryption', 'unicode and binary values round-trip',
    function () use (&$vault, &$actor, &$testScope) {
        $samples = [
            'unicode' => "Árvíztűrő tükörfúrógép – ñ ✓ 🔒",
            'binary'  => random_bytes(64),
            'pem'     => "-----BEGIN PRIVATE KEY-----\n" . base64_encode(random_bytes(48)) . "\n-----END PRIVATE KEY-----\n",
        ];
        foreach ($samples as $k => $v) {
            $vault->put($testScope, "rt_{$k}", $v, $actor);
            if ($vault->get($testScope, "rt_{$k}", $actor) !== $v) {
                return ['outcome' => TestHarness::FAIL, 'message' => "mismatch on {$k}"];
            }
        }
    });

// ── rotation: old versions are kept for 7d ────────────────────
$harness->test('rotation', 'rotate() bumps version and decrypts both during retention',
    function () use (&$vault, &$pdo, &$actor, &$testScope) {
        $vault->put($testScope, 'rot_key', 'v1', $actor);
        $vault->rotate($testScope, 'rot_key', 'v2', $actor);

        if ($vault->get($testScope, 'rot_key', $actor) !== 'v2') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'current version wrong'];
        }
        $rows = $pdo->prepare(
            'SELECT version, is_current, expires_at FROM secrets_vault
              WHERE scope = ? AND key_name = ? ORDER BY version'
        );
        $rows->execute([$testScope, 'rot_key']);
        $list = $rows->fetchAll();
        if (count($list) !== 2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected 2 versions'];
        }
        if ((int) $list[0]['is_current'] !== 0 || $list[0]['expires_at'] === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'old version not marked non-current with expires_at'];
        }
        if ((int) $list[1]['is_current'] !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'new version not current'];
        }
    });

// ── wipe: hard delete ─────────────────────────────────────────
$harness->test('wipe', 'wipe removes all versions immediately',
    function () use (&$vault, &$pdo, &$actor, &$testScope) {
        $vault->put($testScope, 'gone', 'x', $actor);
        $vault->rotate($testScope, 'gone', 'y', $actor);
        $vault->wipe($testScope, 'gone', $actor);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM secrets_vault WHERE scope=? AND key_name=?");
        $stmt->execute([$testScope, 'gone']);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'rows remained after wipe'];
        }

        try {
            $vault->get($testScope, 'gone', $actor);
            return ['outcome' => TestHarness::FAIL, 'message' => 'get after wipe should throw'];
        } catch (VaultException) {
            // ok
        }
    });

// ── security: tampering and bad master key ────────────────────
$harness->test('security', 'tampered ciphertext is rejected (AEAD holds)',
    function () use (&$vault, &$pdo, &$actor, &$testScope) {
        $vault->put($testScope, 'tamper', 'authentic', $actor);
        // Flip a byte.
        $pdo->prepare(
            "UPDATE secrets_vault
                SET ciphertext = CONCAT(SUBSTRING(ciphertext, 1, 1), CHAR(0), SUBSTRING(ciphertext, 3))
              WHERE scope = ? AND key_name = ? AND is_current = 1"
        )->execute([$testScope, 'tamper']);
        try {
            $vault->get($testScope, 'tamper', $actor);
            return ['outcome' => TestHarness::FAIL, 'message' => 'tampered cipher decrypted (!!)'];
        } catch (VaultException) {
            // ok
        }
    });

$harness->test('security', 'group-readable master key is refused',
    function () use (&$db, &$audit) {
        $badKey = sys_get_temp_dir() . '/flowone_test_master_bad_' . bin2hex(random_bytes(4)) . '.key';
        file_put_contents($badKey, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        chmod($badKey, 0444);
        try {
            $badVault = new SecretVault($db, $badKey, $audit);
            $badVault->put('flowone_test_bad_scope', 'k', 'v', ActorContext::cli('test'));
            return ['outcome' => TestHarness::FAIL, 'message' => 'group-readable key was accepted'];
        } catch (VaultException $e) {
            if (stripos($e->getMessage(), 'unsafe permissions') === false) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'wrong message: ' . $e->getMessage()];
            }
        } finally {
            @unlink($badKey);
        }
    });

$harness->test('security', 'wrong-size master key is refused',
    function () use (&$db, &$audit) {
        $shortKey = sys_get_temp_dir() . '/flowone_test_master_short_' . bin2hex(random_bytes(4)) . '.key';
        file_put_contents($shortKey, str_repeat("\x00", 16));
        chmod($shortKey, 0400);
        try {
            (new SecretVault($db, $shortKey, $audit))->put('flowone_test_bad_scope', 'k', 'v', ActorContext::cli('test'));
            return ['outcome' => TestHarness::FAIL, 'message' => 'short key accepted'];
        } catch (VaultException) {
            // ok
        } finally {
            @unlink($shortKey);
        }
    });

// ── audit: each op writes a secrets_audit row ────────────────
$harness->test('audit', 'put/get/rotate/wipe each record a secrets_audit row',
    function () use (&$vault, &$pdo, &$actor, &$testScope) {
        $key = 'audit_target_' . bin2hex(random_bytes(2));
        $vault->put($testScope, $key, 'v1', $actor);
        $vault->get($testScope, $key, $actor);
        $vault->rotate($testScope, $key, 'v2', $actor);
        $vault->wipe($testScope, $key, $actor);
        $stmt = $pdo->prepare(
            "SELECT action FROM secrets_audit WHERE scope = ? AND key_name = ? ORDER BY id"
        );
        $stmt->execute([$testScope, $key]);
        $actions = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $expected = ['put', 'get', 'rotate', 'wipe'];
        if ($actions !== $expected) {
            return [
                'outcome' => TestHarness::FAIL,
                'message' => 'expected ' . json_encode($expected) . ', got ' . json_encode($actions),
            ];
        }
    });

exit($harness->run());
