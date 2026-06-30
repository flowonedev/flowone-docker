<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Services;

/**
 * Redacts secret-shaped values from anything we are about to persist or log.
 *
 * Two-layer defense:
 *   1. Key-name matching: keys whose name looks secret (e.g. "password",
 *      "secret", "token", "api_key") always have their value masked,
 *      regardless of the value shape.
 *   2. Value-shape matching: even if a key is innocuous, values that look
 *      like LE private keys, PEM blocks, or sufficiently long base64 / hex
 *      blobs get masked.
 *
 * Used by:
 *   - AuditLogger (before/after snapshots)
 *   - IncidentSnapshot (bundle metadata)
 *   - Step journal (input_snapshot, output_snapshot, stdout/stderr excerpts)
 *   - Structured logger (every log line goes through here)
 *
 * Deliberately conservative: it is far better to mask something that wasn't
 * secret than to leak a real one. False positives are acceptable; false
 * negatives are a security incident.
 */
final class SecretMasker
{
    /**
     * Key names that always mean "this is a secret value". Matched
     * case-insensitively and as substrings (e.g. "db_password" matches
     * the "password" rule).
     */
    private const SECRET_KEY_PATTERNS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'auth',
        'authorization',
        'private_key',
        'privatekey',
        'dkim_key',
        'session',
        'cookie',
        'credential',
        'csrf',
        'salt',
        'nonce_b64',
        'ciphertext',
    ];

    /**
     * Keys whose name contains one of these substrings are NEVER masked
     * even if SECRET_KEY_PATTERNS would otherwise match. Lets us keep
     * structural fields like "password_age_days" or "secret_count" visible.
     */
    private const EXEMPT_KEY_PATTERNS = [
        'password_age',
        'password_strength',
        'token_count',
        'session_count',
        'secret_count',
    ];

    private const MASK = '***REDACTED***';

    /**
     * Recursively mask an array. Returns a new array, does not mutate.
     */
    public function maskArray(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $stringKey = (string) $key;
            if ($this->isSecretKey($stringKey)) {
                $out[$key] = self::MASK;
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->maskArray($value);
            } elseif (is_string($value)) {
                $out[$key] = $this->maskValue($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Mask string content. Detects PEM blocks, JWT-shaped tokens, and
     * sufficiently-entropic blobs.
     */
    public function maskValue(string $value): string
    {
        // PEM-wrapped private keys, certificates, encrypted blocks. The label
        // between BEGIN/END can be one or more uppercase words (e.g.
        // "PRIVATE KEY", "RSA PRIVATE KEY", "OPENSSH PRIVATE KEY",
        // "ENCRYPTED PRIVATE KEY"). We require at least one letter so an
        // accidental string like "-----BEGIN -----" does not match.
        $value = preg_replace(
            '/-----BEGIN [A-Z][A-Z ]*-----.*?-----END [A-Z][A-Z ]*-----/s',
            self::MASK,
            $value
        ) ?? $value;

        // JWT-shaped tokens (three dot-separated base64url segments)
        $value = preg_replace(
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
            self::MASK,
            $value
        ) ?? $value;

        // Bearer/Basic auth headers
        $value = preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9+\/=_.-]{16,}/',
            '$1 ' . self::MASK,
            $value
        ) ?? $value;

        return $value;
    }

    public function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::EXEMPT_KEY_PATTERNS as $exempt) {
            if (strpos($lower, $exempt) !== false) {
                return false;
            }
        }

        foreach (self::SECRET_KEY_PATTERNS as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
