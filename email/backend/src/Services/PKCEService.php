<?php

namespace Webmail\Services;

/**
 * PKCEService — Proof Key for Code Exchange (RFC 7636) helper.
 *
 * Phase 3 of the OAuth + IMAP ground-up rewrite. Required by
 * draft-ietf-oauth-v2-1 for ALL OAuth 2.0 clients including
 * confidential ones (which historically were exempt). Adding PKCE
 * mitigates the "authorization code interception" attack: even if an
 * attacker grabs the authorization code on its way back from the
 * provider, they cannot exchange it for tokens without the original
 * code_verifier that only our backend holds in Redis.
 *
 * Flow:
 *
 *   1. AuthURL endpoint calls createChallenge() which:
 *        - generates a 32-byte cryptographically random verifier
 *        - base64url-encodes it (43 chars)
 *        - stores it in Redis keyed by the state nonce (TTL 15 min)
 *        - returns the S256 challenge for the auth URL
 *
 *   2. Auth URL includes code_challenge=<challenge> &
 *      code_challenge_method=S256
 *
 *   3. Callback retrieves verifier with consumeVerifier(stateNonce);
 *      Redis entry is deleted on read so replay attacks are blocked.
 *
 *   4. Token exchange sends code_verifier=<verifier> alongside code.
 *      Provider hashes it, compares to stored challenge, accepts/rejects.
 *
 * Verifier-not-found is a hard failure: the callback should treat it
 * the same as an invalid state — no token exchange attempted.
 */
class PKCEService
{
    private RedisCacheService $redis;
    private const TTL_SECONDS = 900; // 15 minutes (matches OAuthStateService)

    public function __construct(array $config, ?RedisCacheService $redis = null)
    {
        $this->redis = $redis ?? new RedisCacheService($config);
    }

    /**
     * Generate a verifier + S256 challenge pair, persist the verifier
     * keyed by stateNonce, and return the challenge.
     *
     * @return array{ challenge: string, method: string }
     */
    public function createChallenge(string $stateNonce): array
    {
        $verifier = $this->generateVerifier();
        $challenge = $this->s256($verifier);
        $this->redis->set($this->key($stateNonce), $verifier, self::TTL_SECONDS);
        return ['challenge' => $challenge, 'method' => 'S256'];
    }

    /**
     * Single-use retrieval of the verifier for a given state nonce.
     * Returns null if no verifier was stored (TTL expired, never
     * generated, already consumed). The entry is deleted on success
     * to prevent replay.
     */
    public function consumeVerifier(string $stateNonce): ?string
    {
        $verifier = $this->redis->get($this->key($stateNonce));
        if (!is_string($verifier) || $verifier === '') {
            return null;
        }
        $this->redis->delete($this->key($stateNonce));
        return $verifier;
    }

    /**
     * Generate a 43-character base64url verifier from 32 random bytes.
     * RFC 7636 requires 43-128 chars from the unreserved set.
     */
    private function generateVerifier(): string
    {
        $raw = random_bytes(32);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * SHA-256 challenge per RFC 7636 §4.2: BASE64URL(SHA256(ASCII(verifier))).
     */
    private function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function key(string $stateNonce): string
    {
        return 'oauth:pkce:' . $stateNonce;
    }
}
