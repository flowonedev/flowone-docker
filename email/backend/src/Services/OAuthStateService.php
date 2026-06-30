<?php

namespace Webmail\Services;

/**
 * OAuthStateService - HMAC-signed OAuth state token utility.
 *
 * Generates and verifies CSRF-resistant state parameters for OAuth flows.
 * The signed envelope is base64(json.hmac) where the HMAC is SHA-256 over the
 * JSON payload using the JWT secret as the key. This is the same scheme
 * AuthController::googleLoginUrl already uses for the login flow; this service
 * centralises it so AccountController (add-account flow) and
 * CalendarConnectionController (calendar-only flow) can use the same scheme
 * without duplicating the cryptography.
 *
 * Verification rejects:
 *   - Missing / un-base64-able strings
 *   - Strings without a "." separator (legacy unsigned payloads)
 *   - Strings whose HMAC does not validate via hash_equals
 *   - Payloads older than the configured TTL (default 900s = 15 min)
 *
 * Phase 2.1 - addresses the audit's BUG-1 (Critical) finding.
 */
class OAuthStateService
{
    private string $secret;
    private int $maxAgeSeconds;

    public function __construct(array $config, int $maxAgeSeconds = 900)
    {
        $secret = $config['jwt']['secret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            throw new \RuntimeException('OAuthStateService requires config[jwt][secret]');
        }
        $this->secret = $secret;
        $this->maxAgeSeconds = $maxAgeSeconds;
    }

    /**
     * Sign a payload and return a URL-safe base64 string.
     * A random nonce and the current timestamp are injected if not present so
     * the caller cannot accidentally produce identical state tokens.
     */
    public function sign(array $payload): string
    {
        if (!isset($payload['nonce'])) {
            $payload['nonce'] = bin2hex(random_bytes(16));
        }
        if (!isset($payload['timestamp'])) {
            $payload['timestamp'] = time();
        }
        $json = json_encode($payload);
        if ($json === false) {
            throw new \RuntimeException('OAuthStateService: payload not JSON-encodable');
        }
        $hmac = hash_hmac('sha256', $json, $this->secret);
        return base64_encode($json . '.' . $hmac);
    }

    /**
     * Verify a signed state string. Returns the decoded payload on success or
     * null on any verification failure. Never throws.
     */
    public function verify(?string $state): ?array
    {
        if (!is_string($state) || $state === '') {
            return null;
        }
        $decoded = base64_decode($state, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        $dotPos = strrpos($decoded, '.');
        if ($dotPos === false) {
            // Legacy unsigned payload - reject for security
            return null;
        }
        $json = substr($decoded, 0, $dotPos);
        $receivedHmac = substr($decoded, $dotPos + 1);
        $expectedHmac = hash_hmac('sha256', $json, $this->secret);
        if (!hash_equals($expectedHmac, $receivedHmac)) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        $timestamp = (int)($payload['timestamp'] ?? 0);
        if ($timestamp <= 0 || (time() - $timestamp) > $this->maxAgeSeconds) {
            return null;
        }
        return $payload;
    }
}
