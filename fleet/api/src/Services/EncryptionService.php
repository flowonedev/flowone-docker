<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Encryption service for sensitive data storage
 */
class EncryptionService
{
    private Container $container;
    private string $key;
    private string $method = 'aes-256-gcm';

    public function __construct(Container $container)
    {
        $this->container = $container;
        
        $keyBase64 = $container->getConfig('encryption.key');
        if (empty($keyBase64)) {
            throw new \RuntimeException('Encryption key not configured');
        }
        
        $this->key = base64_decode($keyBase64);
        if (strlen($this->key) !== 32) {
            throw new \RuntimeException('Encryption key must be 32 bytes');
        }
        
        $configMethod = $container->getConfig('encryption.method');
        if ($configMethod) {
            $this->method = $configMethod;
        }
    }

    /**
     * Encrypt a string
     */
    public function encrypt(string $plaintext): string
    {
        $ivLength = openssl_cipher_iv_length($this->method);
        $iv = random_bytes($ivLength);
        
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->method,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Combine IV + tag + ciphertext and base64 encode
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a string
     */
    public function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted);
        if ($data === false) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        $ivLength = openssl_cipher_iv_length($this->method);
        $tagLength = 16;

        if (strlen($data) < $ivLength + $tagLength) {
            throw new \RuntimeException('Encrypted data too short');
        }

        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->method,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // Include the current key fingerprint so a key MISMATCH (data encrypted with a
            // different ENCRYPTION_KEY - e.g. after a reinstall or config regeneration) is
            // immediately diagnosable instead of surfacing as a generic failure.
            throw new \RuntimeException(
                'Decryption failed - data may have been encrypted with a different ENCRYPTION_KEY '
                . '(current key fingerprint: ' . $this->keyFingerprint() . ')'
            );
        }

        return $plaintext;
    }

    /**
     * Short, non-sensitive fingerprint of the active encryption key. Lets operators
     * detect when stored ciphertext was produced with a different key without ever
     * exposing the key itself.
     */
    public function keyFingerprint(): string
    {
        return substr(hash('sha256', $this->key), 0, 12);
    }

    /**
     * Generate a random password
     */
    public function generatePassword(int $length = 32): string
    {
        // Alphanumeric + underscore/dash ONLY. No special chars that break
        // shell escaping, SQL quoting, PHP strings, or URL encoding.
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }

    /**
     * Generate a random token
     */
    public function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}

