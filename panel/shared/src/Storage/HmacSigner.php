<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * HMAC-SHA256 signer / verifier for state payloads.
 *
 * Closes the local-privilege-escalation attack vector: without HMAC, anyone
 * with write access to /var/lib/flowone or Redis could forge a "healthy"
 * status and trick consumers into trusting a missing or compromised NAS.
 *
 * Key handling:
 *   - Key file is /etc/flowone/state.key (mode 0640 root:flowone-storage, see config).
 *   - Daemon refuses to start if the key file is missing or world-readable.
 *   - The key is loaded once at process startup and stays in memory.
 *
 * Payload shape: the payload (any associative array) is canonicalised via
 * sorted-key JSON encoding, signed, and wrapped:
 *
 *   {
 *     "payload": { ...original... },
 *     "sig": "base64url(hmac_sha256(canonical_json(payload)))",
 *     "alg": "HS256"
 *   }
 */
final class HmacSigner
{
    public function __construct(private string $key)
    {
        if ($this->key === '') {
            throw new \InvalidArgumentException('HmacSigner: key is empty');
        }
    }

    /**
     * Load the key from disk and return a signer instance.
     *
     * Refuses to load a world-readable key or a key wider than $maxMode.
     */
    public static function fromKeyFile(string $path, int $maxMode = 0640): self
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("HMAC key not readable: {$path}");
        }

        $perms = fileperms($path);
        if ($perms === false) {
            throw new \RuntimeException("Cannot stat HMAC key: {$path}");
        }
        $mode = $perms & 0777;
        if (($mode & 0007) !== 0) {
            throw new \RuntimeException(sprintf(
                'HMAC key %s is world-accessible (mode %o); refuse to load',
                $path,
                $mode
            ));
        }
        if ($mode > $maxMode) {
            throw new \RuntimeException(sprintf(
                'HMAC key %s mode %o exceeds maximum %o',
                $path,
                $mode,
                $maxMode
            ));
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException("HMAC key is empty: {$path}");
        }

        return new self(trim($raw));
    }

    /**
     * Sign a payload and return the wrapped envelope ready for JSON encoding.
     *
     * @param array<string,mixed> $payload
     * @return array{payload: array<string,mixed>, sig: string, alg: string}
     */
    public function sign(array $payload): array
    {
        $canonical = self::canonicalise($payload);
        $sig = hash_hmac('sha256', $canonical, $this->key, true);
        return [
            'payload' => $payload,
            'sig'     => self::base64UrlEncode($sig),
            'alg'     => 'HS256',
        ];
    }

    /**
     * Verify a wrapped envelope. Returns the unwrapped payload on success,
     * null on any failure. Constant-time comparison.
     *
     * @return array<string,mixed>|null
     */
    public function verify(mixed $envelope): ?array
    {
        if (!is_array($envelope)) {
            return null;
        }
        if (!isset($envelope['payload'], $envelope['sig'], $envelope['alg'])) {
            return null;
        }
        if ($envelope['alg'] !== 'HS256') {
            return null;
        }
        if (!is_array($envelope['payload']) || !is_string($envelope['sig'])) {
            return null;
        }

        $expected = hash_hmac('sha256', self::canonicalise($envelope['payload']), $this->key, true);
        $provided = self::base64UrlDecode($envelope['sig']);
        if ($provided === null || strlen($provided) !== strlen($expected)) {
            return null;
        }
        if (!hash_equals($expected, $provided)) {
            return null;
        }
        return $envelope['payload'];
    }

    /**
     * Sign and JSON-encode in one call (convenient for files / Redis).
     *
     * @param array<string,mixed> $payload
     */
    public function signToJson(array $payload): string
    {
        return json_encode($this->sign($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ?: throw new \RuntimeException('HmacSigner: JSON encoding failed');
    }

    /**
     * Decode JSON and verify in one call. Returns null on any failure.
     *
     * @return array<string,mixed>|null
     */
    public function verifyJson(string $json): ?array
    {
        $decoded = json_decode($json, true);
        return $this->verify($decoded);
    }

    /**
     * Canonical JSON serialisation: sorted keys, no whitespace, stable
     * floats. Required so that two implementations produce identical
     * bytes for identical logical payloads.
     */
    private static function canonicalise(array $payload): string
    {
        $sorted = self::sortRecursive($payload);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('HmacSigner: canonicalise JSON encoding failed');
        }
        return $json;
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        if ($isList) {
            foreach ($value as $v) {
                $out[] = self::sortRecursive($v);
            }
            return $out;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $k => $v) {
            $out[$k] = self::sortRecursive($v);
        }
        return $out;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $s): ?string
    {
        $padded = strtr($s, '-_', '+/');
        $padLen = (4 - (strlen($padded) % 4)) % 4;
        $padded .= str_repeat('=', $padLen);
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }
}
