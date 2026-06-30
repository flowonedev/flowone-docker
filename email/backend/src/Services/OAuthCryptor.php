<?php

declare(strict_types=1);

namespace Webmail\Services;

use Webmail\Core\Database;

/**
 * OAuthCryptor
 *
 * Encrypts OAuth refresh/access tokens at rest using:
 * - AES-256-GCM with a versioned envelope format: v{N}:{base64(iv|ciphertext|tag)}
 * - AES-256-CBC legacy reader for rows previously encrypted by GoogleOAuthService /
 *   MicrosoftOAuthService (base64(iv|ciphertext), iv is 16 bytes).
 *
 * Wrong-key or tampered ciphertext returns null (never garbage).
 */
class OAuthCryptor
{
    /** @var array<int,string> version => derived 32-byte encryption key */
    private array $keys = [];
    private int $currentVersion = 1;
    private string $legacyImapKeySource = '';

    private const AAD = 'oauth_token_v1';
    private const GCM_IV_LEN = 12;
    private const GCM_TAG_LEN = 16;
    private const LEGACY_IV_LEN = 16;

    public function __construct(array $config)
    {
        $legacy = (string)($config['imap_encryption_key'] ?? '');
        $this->legacyImapKeySource = $legacy;

        $oauthEnc = $config['oauth_encryption'] ?? null;
        if (!is_array($oauthEnc) || $oauthEnc === []) {
            $oauthEnc = $this->loadOauthEncryptionFromEnv();
        }

        $keys = $oauthEnc['keys'] ?? [];
        $currentVersion = (int)($oauthEnc['current_version'] ?? 0);

        if (empty($keys)) {
            // Safe default to avoid bricking if OAUTH_KEYS hasn't been set yet.
            // Once you implement the full rotation procedure, prefer explicit OAUTH_KEYS.
            if ($legacy !== '') {
                $keys = [1 => $legacy];
                $currentVersion = 1;
            }
        }

        // Parse keys: version => keySource (string). Derive actual 32-byte key material.
        foreach ($keys as $ver => $keySource) {
            $verInt = (int)$ver;
            if ($verInt <= 0) {
                continue;
            }
            $keySource = (string)$keySource;
            if ($keySource === '') {
                continue;
            }
            $this->keys[$verInt] = hash('sha256', $keySource, true);
        }

        if (empty($this->keys)) {
            throw new \RuntimeException('OAuth encryption keys missing. Set IMAP_ENCRYPTION_KEY and/or oauth_encryption keys.');
        }

        if ($currentVersion > 0 && isset($this->keys[$currentVersion])) {
            $this->currentVersion = $currentVersion;
        } else {
            $this->currentVersion = max(array_keys($this->keys));
        }
    }

    /**
     * @return array{keys:array<int,string>,current_version:int}
     */
    private function loadOauthEncryptionFromEnv(): array
    {
        $raw = getenv('OAUTH_KEYS') ?: '';
        $current = (int)(getenv('OAUTH_CURRENT_VERSION') ?: 0);

        if ($raw === '') {
            return ['keys' => [], 'current_version' => $current];
        }

        // Expected format: v1:<keySource>,v2:<keySource>,...
        $pairs = array_filter(array_map('trim', explode(',', $raw)));
        $keys = [];
        foreach ($pairs as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }
            [$v, $keySource] = explode(':', $pair, 2);
            if (!preg_match('/^v(\d+)$/i', trim($v), $m)) {
                continue;
            }
            $ver = (int)$m[1];
            $keySource = trim($keySource);
            if ($ver > 0 && $keySource !== '') {
                $keys[$ver] = $keySource;
            }
        }

        return ['keys' => $keys, 'current_version' => $current];
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    /**
     * Encrypts using the current version.
     */
    public function encrypt(string $plaintext): string
    {
        return $this->encryptWithVersion($this->currentVersion, $plaintext);
    }

    public function encryptWithVersion(int $version, string $plaintext): string
    {
        if (!isset($this->keys[$version])) {
            throw new \RuntimeException("OAuth encryption key version not configured: {$version}");
        }

        $key = $this->keys[$version];
        $iv = random_bytes(self::GCM_IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD
        );

        if ($ciphertext === false || $tag === '' || strlen($tag) !== self::GCM_TAG_LEN) {
            throw new \RuntimeException('OAuth encryption failed');
        }

        $envelopeBody = base64_encode($iv . $ciphertext . $tag);
        return 'v' . $version . ':' . $envelopeBody;
    }

    /**
     * Decrypts:
     * - versioned GCM envelopes: v{N}:{base64(iv|ciphertext|tag)}
     * - legacy CBC: base64(iv(16)|ciphertext)
     *
     * Returns null on decrypt failure; returns '' for empty input (matches legacy behavior).
     */
    public function decrypt(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return '';
        }

        if (preg_match('/^v(\d+):(.*)$/', $encrypted, $m)) {
            $version = (int)$m[1];
            $b64 = $m[2];

            if ($version <= 0 || !isset($this->keys[$version])) {
                return null;
            }

            $raw = base64_decode($b64, true);
            if ($raw === false || strlen($raw) < (self::GCM_IV_LEN + self::GCM_TAG_LEN + 1)) {
                return null;
            }

            $iv = substr($raw, 0, self::GCM_IV_LEN);
            $tag = substr($raw, -self::GCM_TAG_LEN);
            $ciphertext = substr($raw, self::GCM_IV_LEN, -self::GCM_TAG_LEN);

            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $this->keys[$version],
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                self::AAD
            );

            return $plaintext === false ? null : $plaintext;
        }

        // Legacy CBC reader (base64(iv(16)|ciphertext))
        if ($this->legacyImapKeySource === '') {
            return null;
        }

        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < (self::LEGACY_IV_LEN + 1)) {
            return null;
        }

        $iv = substr($data, 0, self::LEGACY_IV_LEN);
        $ciphertext = substr($data, self::LEGACY_IV_LEN);

        $key = hash('sha256', $this->legacyImapKeySource, true);
        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Fail-fast boot canary.
     *
     * - Ensures the canary row exists
     * - Encrypts and decrypts a known plaintext
     * - Throws if the configured key cannot decrypt it
     */
    public static function canaryCheck(array $config): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $cryptor = new self($config);
        $db = Database::getConnection($config);

        // Keep canary self-contained: create the table if it doesn't exist yet.
        // (Health/quarantine fields are handled by migrations.)
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS webmail_canary (
                    id TINYINT PRIMARY KEY,
                    canary_encrypted TEXT NOT NULL,
                    encrypted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    rotated_at TIMESTAMP NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            // Continue; the subsequent SELECT/INSERT will throw with a clearer message.
        }

        $canaryId = 1;
        $plaintext = 'OAUTH_CANARY_V1';

        try {
            $stmt = $db->prepare('SELECT canary_encrypted FROM webmail_canary WHERE id = ? LIMIT 1');
            $stmt->execute([$canaryId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $enc = $cryptor->encrypt($plaintext);
                $ins = $db->prepare('INSERT INTO webmail_canary (id, canary_encrypted) VALUES (?, ?)');
                $ins->execute([$canaryId, $enc]);
                $row = ['canary_encrypted' => $enc];
            }

            $decrypted = $cryptor->decrypt($row['canary_encrypted'] ?? null);
            if ($decrypted === null || $decrypted !== $plaintext) {
                throw new \RuntimeException('OAuth encryption canary decrypt failed');
            }

            $checked = true;
        } catch (\Throwable $e) {
            throw new \RuntimeException('OAuth encryption canary error: ' . $e->getMessage(), 0, $e);
        }
    }
}

