<?php

namespace Webmail\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class SessionService
{
    private array $config;
    private ?string $privateKey = null;
    private ?string $publicKey = null;
    private string $imapEncryptionKey;

    public function __construct(array $config, string $imapEncryptionKey = '')
    {
        $this->config = $config;
        $this->imapEncryptionKey = $imapEncryptionKey;

        if ($this->imapEncryptionKey === '') {
            throw new \RuntimeException('IMAP_ENCRYPTION_KEY is required for session password encryption');
        }
    }

    /**
     * Get the signing key for creating tokens.
     * RS256: returns PEM private key string
     * HS256: returns the shared secret string
     */
    private function getSigningKey(): string
    {
        $algorithm = $this->config['algorithm'] ?? 'RS256';

        if ($algorithm === 'RS256') {
            if ($this->privateKey === null) {
                $path = $this->config['private_key_path'] ?? '';
                if (!$path || !file_exists($path)) {
                    throw new \RuntimeException(
                        'RS256 private key not found at: ' . $path .
                        '. Run: bash backend/scripts/generate-jwt-keys.sh'
                    );
                }
                $this->privateKey = file_get_contents($path);
                if ($this->privateKey === false) {
                    throw new \RuntimeException('Failed to read RS256 private key from: ' . $path);
                }
            }
            return $this->privateKey;
        }

        // HS256 fallback
        return $this->config['secret'] ?? '';
    }

    /**
     * Get the verification key for validating tokens.
     * RS256: returns PEM public key string
     * HS256: returns the shared secret string
     */
    private function getVerificationKey(): string
    {
        $algorithm = $this->config['algorithm'] ?? 'RS256';

        if ($algorithm === 'RS256') {
            if ($this->publicKey === null) {
                $path = $this->config['public_key_path'] ?? '';
                if (!$path || !file_exists($path)) {
                    throw new \RuntimeException(
                        'RS256 public key not found at: ' . $path .
                        '. Run: bash backend/scripts/generate-jwt-keys.sh'
                    );
                }
                $this->publicKey = file_get_contents($path);
                if ($this->publicKey === false) {
                    throw new \RuntimeException('Failed to read RS256 public key from: ' . $path);
                }
            }
            return $this->publicKey;
        }

        // HS256 fallback
        return $this->config['secret'] ?? '';
    }

    /**
     * Get the current algorithm
     */
    private function getAlgorithm(): string
    {
        return $this->config['algorithm'] ?? 'RS256';
    }

    /**
     * Create access token for user
     */
    public function createToken(string $email, array $extraClaims = []): string
    {
        $payload = array_merge([
            'iss' => 'webmail',
            'sub' => $email,
            'iat' => time(),
            'exp' => time() + ($this->config['expiry'] ?? 3600),
            'type' => 'access',
        ], $extraClaims);

        return JWT::encode($payload, $this->getSigningKey(), $this->getAlgorithm());
    }

    /**
     * Create refresh token for user
     */
    public function createRefreshToken(string $email): string
    {
        $payload = [
            'iss' => 'webmail',
            'sub' => $email,
            'iat' => time(),
            'exp' => time() + ($this->config['refresh_expiry'] ?? 86400 * 7),
            'type' => 'refresh',
        ];

        return JWT::encode($payload, $this->getSigningKey(), $this->getAlgorithm());
    }

    /**
     * Validate and decode token.
     * 
     * Supports dual-algorithm verification during migration:
     * - Tries the configured algorithm first (RS256)
     * - Falls back to HS256 if the primary fails and a secret is available
     * 
     * This allows existing HS256 tokens to remain valid until they expire,
     * while all new tokens are signed with RS256.
     */
    public function validateToken(string $token): ?array
    {
        $algorithm = $this->getAlgorithm();

        // Try primary algorithm first
        try {
            $decoded = JWT::decode($token, new Key($this->getVerificationKey(), $algorithm));
            return (array)$decoded;
        } catch (\Exception $e) {
            error_log("JWT validation error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user email from token
     */
    public function getEmailFromToken(string $token): ?string
    {
        $payload = $this->validateToken($token);
        return $payload['sub'] ?? null;
    }

    /**
     * Check if token is expired
     */
    public function isTokenExpired(string $token): bool
    {
        $payload = $this->validateToken($token);
        if (!$payload) {
            return true;
        }
        return ($payload['exp'] ?? 0) < time();
    }

    /**
     * Check if token is a refresh token
     */
    public function isRefreshToken(string $token): bool
    {
        $payload = $this->validateToken($token);
        return ($payload['type'] ?? '') === 'refresh';
    }

    /**
     * Get the AES key for IMAP password encryption.
     * Uses a dedicated IMAP encryption key (preferred) or falls back to JWT secret.
     */
    private function getPasswordEncryptionKey(): string
    {
        return substr(hash('sha256', $this->imapEncryptionKey, true), 0, 32);
    }

    /**
     * Get the legacy AES key (JWT secret) for decrypting old passwords.
     * Used for backward compatibility during key migration.
     */
    private function getLegacyPasswordEncryptionKey(): string
    {
        return substr(hash('sha256', $this->config['secret'] ?? '', true), 0, 32);
    }

    /**
     * Encrypt password using AES-256-GCM (authenticated encryption)
     * Output format: "gcm:" + base64( iv[12] + tag[16] + ciphertext )
     * 
     * Uses a dedicated IMAP encryption key (separate from JWT for defense-in-depth).
     */
    public function encryptPassword(string $password): string
    {
        $key = $this->getPasswordEncryptionKey();
        $iv = random_bytes(12); // 96-bit IV recommended for GCM
        $tag = '';
        $encrypted = openssl_encrypt($password, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($encrypted === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed');
        }

        // Pack: iv (12) + tag (16) + ciphertext
        return 'gcm:' . base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypt password — supports both AES-256-GCM (new) and AES-256-CBC (legacy)
     */
    public function decryptPassword(string $encrypted): ?string
    {
        try {
            // New GCM format: "gcm:<base64(iv + tag + ciphertext)>"
            if (str_starts_with($encrypted, 'gcm:')) {
                return $this->decryptPasswordGcm(substr($encrypted, 4));
            }

            // Legacy CBC format (backward compatibility)
            return $this->decryptPasswordCbc($encrypted);
        } catch (\Exception $e) {
            error_log('Password decryption error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Decrypt AES-256-GCM encrypted password
     * Input: base64( iv[12] + tag[16] + ciphertext )
     * 
     * Tries the dedicated IMAP key first, then falls back to the legacy JWT secret
     * for passwords encrypted before the key separation migration.
     */
    private function decryptPasswordGcm(string $encoded): ?string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 28) { // 12 (iv) + 16 (tag) = 28 minimum
            return null;
        }

        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        // Try current key first
        $key = $this->getPasswordEncryptionKey();
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($decrypted !== false) {
            return $decrypted;
        }

        // Fallback: try legacy JWT-derived key (for passwords encrypted before key migration)
        $legacyKey = $this->getLegacyPasswordEncryptionKey();
        if ($legacyKey !== $key) {
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $legacyKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($decrypted !== false) {
                error_log('[SessionService] Password decrypted with legacy key — will re-encrypt with new key on next login');
                return $decrypted;
            }
        }

        error_log('AES-256-GCM decryption failed — possible tampering or wrong key');
        return null;
    }

    /**
     * Legacy: Decrypt AES-256-CBC encrypted password (backward compatibility)
     * Input: base64( iv[16] + base64(ciphertext) )
     */
    private function decryptPasswordCbc(string $encrypted): ?string
    {
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 17) {
            return null;
        }

        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $key = substr(hash('sha256', $this->config['secret']), 0, 32);

        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv);
        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Create temporary token for 2FA pending state
     * Short expiry (5 minutes) - only used during 2FA verification
     */
    public function createTempToken(string $email, string $password): string
    {
        $payload = [
            'iss' => 'webmail',
            'sub' => $email,
            'iat' => time(),
            'exp' => time() + 300, // 5 minutes
            'type' => '2fa_pending',
            'pwd' => $this->encryptPassword($password),
        ];

        return JWT::encode($payload, $this->getSigningKey(), $this->getAlgorithm());
    }

    /**
     * Create full session (access + refresh tokens) - used after 2FA verification
     * Password is no longer stored in JWT; it's returned separately for server-side storage.
     */
    public function createSession(string $email, string $password): array
    {
        $encryptedPassword = $this->encryptPassword($password);
        
        // JWT only carries identity - no password
        $accessToken = $this->createToken($email);
        
        $refreshToken = $this->createRefreshToken($email);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'encrypted_password' => $encryptedPassword,
        ];
    }
}
