<?php

namespace Webmail\Services;

/**
 * Correlation IDs for request tracing.
 *
 * Generates a per-request ULID once and re-uses it for the rest of the
 * request lifecycle. Every structured log line emitted while servicing a
 * request must carry that id so distributed mailbox fetches can be
 * reconstructed from the logs.
 *
 * Usage:
 *   $rid = CorrelationId::current();        // generates if not yet set
 *   error_log(StructuredLog::format('allmail_skip', ['request_id' => $rid, ...]));
 *
 * The ULID format gives us:
 *   - 26 chars, URL-safe Crockford base32
 *   - lexicographic time ordering (first 10 chars = ms since epoch)
 *   - 80 bits of randomness (collision-safe across many workers)
 */
final class CorrelationId
{
    private static ?string $current = null;

    /**
     * Return the request_id for the current request, generating one if needed.
     */
    public static function current(): string
    {
        if (self::$current === null) {
            self::$current = self::generate();
        }
        return self::$current;
    }

    /**
     * Reset the cached id. Use only at the very start of a new request when
     * php-fpm/cli reuses a process across logical requests.
     */
    public static function reset(?string $forceTo = null): void
    {
        self::$current = $forceTo;
    }

    /**
     * Generate a fresh ULID-like 26-char identifier prefixed with "req_".
     *
     * Format: req_<26-char ULID>. Total length 30. Safe to embed in JSON.
     */
    public static function generate(): string
    {
        return 'req_' . self::ulid();
    }

    /**
     * 26-char Crockford base32 ULID. 48 bits of timestamp (ms), 80 bits of
     * randomness. We use random_bytes for the entropy half so collisions
     * across workers are vanishingly unlikely.
     */
    private static function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $tsMs = (int) round(microtime(true) * 1000);

        // First 10 characters: 48 bits of milliseconds, big-endian, base32.
        $tsChars = '';
        for ($i = 9; $i >= 0; $i--) {
            $tsChars = $alphabet[$tsMs & 0x1F] . $tsChars;
            $tsMs >>= 5;
        }

        // Last 16 characters: 80 random bits, base32.
        try {
            $bytes = random_bytes(10);
        } catch (\Throwable $e) {
            $bytes = '';
            for ($i = 0; $i < 10; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }
        }

        $randChars = '';
        $bits = 0;
        $bitCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $bits = ($bits << 8) | ord($bytes[$i]);
            $bitCount += 8;
            while ($bitCount >= 5) {
                $bitCount -= 5;
                $randChars .= $alphabet[($bits >> $bitCount) & 0x1F];
            }
        }
        if ($bitCount > 0) {
            $randChars .= $alphabet[($bits << (5 - $bitCount)) & 0x1F];
        }

        return $tsChars . substr($randChars, 0, 16);
    }
}
