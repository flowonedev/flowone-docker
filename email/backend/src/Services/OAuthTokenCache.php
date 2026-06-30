<?php

namespace Webmail\Services;

/**
 * OAuthTokenCache
 *
 * Shared Redis-backed cache + single-flight refresh mutex for OAuth access
 * tokens. Used by GoogleOAuthService and MicrosoftOAuthService to:
 *
 *   1. Avoid hitting MariaDB on every IMAP/SMTP call (token is in Redis with
 *      TTL = (expires_in - safety_margin) so the cached value is always
 *      usable for the rest of its life).
 *   2. Prevent the "thundering herd" of concurrent PHP-FPM workers all
 *      seeing an expired token simultaneously and each firing their own
 *      POST to the provider's /token endpoint. One worker holds the lock
 *      and refreshes; the rest poll the cache until the new token appears.
 *   3. Persist a single "account.revoked" signal that controllers and the
 *      WebSocket push channel can observe without re-querying the DB.
 *
 * Phase 1 of the OAuth ground-up rewrite. This service is intentionally
 * provider-agnostic so the future sync daemon (Phase 2) can share it.
 *
 * Key layout (all keys are under the RedisCacheService prefix):
 *
 *   oauth:token:{provider}:{primary_email}:{oauth_email}        -- access token, TTL = expires_in - 60s
 *   oauth:refresh-lock:{provider}:{primary_email}:{oauth_email} -- mutex, TTL = 30s
 *   oauth:revoked:{provider}:{primary_email}:{oauth_email}      -- terminal "needs reconsent" flag, TTL = 1h
 */
class OAuthTokenCache
{
    private RedisCacheService $redis;

    /** Safety margin subtracted from expires_in so the cached token is never expired in the cache. */
    private const SAFETY_MARGIN_SECONDS = 60;

    /** Max time the refresh-lock holder is allowed to hold the lock. */
    private const REFRESH_LOCK_TTL_SECONDS = 30;

    /** Total time a waiter will poll the cache for the lock holder's result. */
    private const REFRESH_WAIT_TIMEOUT_SECONDS = 8;

    /** Polling interval while waiting for the lock holder. */
    private const REFRESH_WAIT_POLL_MS = 200;

    /** How long the "revoked" flag is honoured. After this, a fresh DB read happens. */
    private const REVOKED_FLAG_TTL_SECONDS = 3600;

    public function __construct(array $config, ?RedisCacheService $redis = null)
    {
        $this->redis = $redis ?? new RedisCacheService($config);
    }

    public function isAvailable(): bool
    {
        return $this->redis->isAvailable();
    }

    // ---------- Access token cache ----------

    public function getToken(string $provider, string $primaryEmail, string $oauthEmail): ?string
    {
        $value = $this->redis->get($this->tokenKey($provider, $primaryEmail, $oauthEmail));
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Cache an access token. TTL is derived from expires_in minus a safety margin
     * so the cached value will always still be valid when read.
     *
     * Returns true on success; false on Redis unavailability, in which case the
     * caller should still return the token to the user (it just won't be cached).
     */
    public function putToken(string $provider, string $primaryEmail, string $oauthEmail, string $token, int $expiresInSeconds): bool
    {
        $ttl = max(0, $expiresInSeconds - self::SAFETY_MARGIN_SECONDS);
        if ($ttl < 30) {
            // Don't bother caching anything < 30 seconds; it'd cause more
            // churn than it'd save and the caller will refresh again very
            // soon anyway.
            return false;
        }
        return $this->redis->set($this->tokenKey($provider, $primaryEmail, $oauthEmail), $token, $ttl);
    }

    public function invalidateToken(string $provider, string $primaryEmail, string $oauthEmail): void
    {
        $this->redis->delete($this->tokenKey($provider, $primaryEmail, $oauthEmail));
    }

    // ---------- Single-flight refresh mutex ----------

    /**
     * Try to acquire the refresh lock for (provider, primary, oauth).
     * Returns true if this caller is the elected refresher and must perform
     * the actual token refresh (followed by putToken() then releaseRefreshLock()).
     * Returns false if another caller holds the lock; that caller should
     * call waitForRefreshedToken() and use whatever it returns.
     */
    public function acquireRefreshLock(string $provider, string $primaryEmail, string $oauthEmail): bool
    {
        return $this->redis->setIfNotExists(
            $this->lockKey($provider, $primaryEmail, $oauthEmail),
            (string)getmypid(),
            self::REFRESH_LOCK_TTL_SECONDS
        );
    }

    public function releaseRefreshLock(string $provider, string $primaryEmail, string $oauthEmail): void
    {
        $this->redis->delete($this->lockKey($provider, $primaryEmail, $oauthEmail));
    }

    /**
     * Block (with bounded polling) until the lock holder publishes a refreshed
     * token via putToken(). Returns the token, or null if the wait timed out
     * or the lock holder failed.
     *
     * Polls every REFRESH_WAIT_POLL_MS up to REFRESH_WAIT_TIMEOUT_SECONDS.
     */
    public function waitForRefreshedToken(string $provider, string $primaryEmail, string $oauthEmail): ?string
    {
        $deadline = microtime(true) + self::REFRESH_WAIT_TIMEOUT_SECONDS;
        $stepUs = self::REFRESH_WAIT_POLL_MS * 1000;
        while (microtime(true) < $deadline) {
            usleep($stepUs);
            $token = $this->getToken($provider, $primaryEmail, $oauthEmail);
            if ($token !== null) {
                return $token;
            }
            if ($this->isRevoked($provider, $primaryEmail, $oauthEmail)) {
                // Lock holder declared the account revoked. No point waiting.
                return null;
            }
        }
        return null;
    }

    // ---------- Revoked / needs-reconsent flag ----------

    /**
     * Mark the account as terminally revoked. This is a fast Redis flag that
     * lets concurrent and subsequent callers fail fast (without re-querying
     * the DB) when an invalid_grant has already been observed. The DB row
     * is the source of truth; this flag is a 1h hot cache.
     */
    public function markRevoked(string $provider, string $primaryEmail, string $oauthEmail, string $reason = 'invalid_grant'): void
    {
        $this->invalidateToken($provider, $primaryEmail, $oauthEmail);
        $this->redis->set(
            $this->revokedKey($provider, $primaryEmail, $oauthEmail),
            $reason,
            self::REVOKED_FLAG_TTL_SECONDS
        );
    }

    public function isRevoked(string $provider, string $primaryEmail, string $oauthEmail): bool
    {
        return $this->redis->exists($this->revokedKey($provider, $primaryEmail, $oauthEmail));
    }

    public function clearRevoked(string $provider, string $primaryEmail, string $oauthEmail): void
    {
        $this->redis->delete($this->revokedKey($provider, $primaryEmail, $oauthEmail));
    }

    /**
     * Publish a WS-bound "account.revoked" event so the frontend can surface
     * a reconnect banner without polling. Safe to call alongside markRevoked().
     */
    public function publishRevoked(string $primaryEmail, string $provider, string $oauthEmail, string $reason): void
    {
        try {
            $this->redis->publishEvent($primaryEmail, 'ACCOUNT_REVOKED', [
                'provider' => $provider,
                'oauth_email' => $oauthEmail,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            // Best-effort; never break the calling path.
        }
    }

    // ---------- Key helpers ----------

    private function tokenKey(string $provider, string $primaryEmail, string $oauthEmail): string
    {
        return sprintf(
            'oauth:token:%s:%s:%s',
            strtolower($provider),
            strtolower($primaryEmail),
            strtolower($oauthEmail)
        );
    }

    private function lockKey(string $provider, string $primaryEmail, string $oauthEmail): string
    {
        return sprintf(
            'oauth:refresh-lock:%s:%s:%s',
            strtolower($provider),
            strtolower($primaryEmail),
            strtolower($oauthEmail)
        );
    }

    private function revokedKey(string $provider, string $primaryEmail, string $oauthEmail): string
    {
        return sprintf(
            'oauth:revoked:%s:%s:%s',
            strtolower($provider),
            strtolower($primaryEmail),
            strtolower($oauthEmail)
        );
    }
}
