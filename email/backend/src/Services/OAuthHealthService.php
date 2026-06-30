<?php

declare(strict_types=1);

namespace Webmail\Services;

use PDO;
use Webmail\Core\Database;

/**
 * Phase 3 - OAuth Health centralization.
 *
 * Every code path that wants to mark an OAuth account as terminally
 * broken (invalid_grant, missing refresh token, AUTHENTICATIONFAILED
 * during refresh, etc.) goes through this service. It does three
 * things and only three things:
 *
 *   1. Persist health='revoked' (or 'broken') in the relevant table.
 *   2. Set the Redis cache flag so subsequent getValidAccessToken()
 *      calls fail fast without hitting the DB.
 *   3. Publish account.revoked on the mail-sync pub/sub channel so
 *      the WebSocket layer can push a reconnect banner to the user.
 *
 * It deliberately does NOT trigger any retry / re-auth attempt. A
 * terminal OAuth failure must remain terminal until the user
 * explicitly reconnects. That is why this service is the only sanctioned
 * caller of these state changes - bypassing it (and writing health
 * directly from a controller) was the bug that caused the refresh
 * loop on revoked accounts pre-Phase-3.
 */
final class OAuthHealthService
{
    private array $config;
    private ?PDO $db = null;
    private ?OAuthTokenCache $tokenCache = null;

    public function __construct(array $config, ?OAuthTokenCache $tokenCache = null)
    {
        $this->config = $config;
        $this->tokenCache = $tokenCache;
    }

    /**
     * Mark a webmail_oauth_tokens row revoked and notify.
     *
     * Caller passes the provider so we can key the Redis cache (the
     * provider is also persisted alongside primary/oauth_email, but
     * looking it up would be a wasted query).
     */
    public function markRevoked(
        string $provider,
        string $primaryEmail,
        string $oauthEmail,
        string $reason
    ): void {
        $this->ensureDb();
        $this->updateTokensHealth($primaryEmail, $oauthEmail, 'revoked', $reason);
        $cache = $this->cache();
        $cache->markRevoked($provider, $primaryEmail, $oauthEmail, $reason);
        $cache->publishRevoked($primaryEmail, $provider, $oauthEmail, $reason);
    }

    /**
     * Mark a webmail_oauth_tokens row broken (transient bad state, not
     * necessarily terminal). Useful for decrypt failures, schema mismatches,
     * etc., where the user might be able to recover without re-consent.
     */
    public function markBroken(
        string $primaryEmail,
        string $oauthEmail,
        string $reason
    ): void {
        $this->ensureDb();
        $this->updateTokensHealth($primaryEmail, $oauthEmail, 'broken', $reason);
    }

    /**
     * Clear health flags after a successful refresh. Inverse of markRevoked
     * - re-publishes nothing, just resets the columns.
     */
    public function markHealthy(
        string $provider,
        string $primaryEmail,
        string $oauthEmail
    ): void {
        $this->ensureDb();
        $this->updateTokensHealth($primaryEmail, $oauthEmail, 'healthy', null);
        $this->cache()->clearRevoked($provider, $primaryEmail, $oauthEmail);
    }

    /**
     * Mark a calendar_connections row revoked. Calendar uses a different
     * provider key ('google_calendar') in the token cache so a revoked
     * calendar connection does not nuke the email account's IMAP token.
     */
    public function markCalendarRevoked(
        string $primaryEmail,
        string $googleEmail,
        string $reason
    ): void {
        $this->ensureDb();
        try {
            $upd = $this->db->prepare('
                UPDATE calendar_connections
                SET health = ?, health_reason = ?, health_updated_at = NOW()
                WHERE primary_email = ? AND google_email = ?
            ');
            $upd->execute(['revoked', $reason, strtolower($primaryEmail), strtolower($googleEmail)]);
        } catch (\Throwable $e) {
            // Calendar table may not have the health columns on a legacy
            // database; do not crash a cron run because of it.
        }
        $cache = $this->cache();
        $cache->markRevoked('google_calendar', $primaryEmail, $googleEmail, $reason);
        $cache->publishRevoked($primaryEmail, 'google_calendar', $googleEmail, $reason);
    }

    private function updateTokensHealth(
        string $primaryEmail,
        string $oauthEmail,
        string $health,
        ?string $reason
    ): void {
        try {
            $upd = $this->db->prepare('
                UPDATE webmail_oauth_tokens
                SET health = ?, health_reason = ?, health_updated_at = NOW()
                WHERE primary_email = ? AND oauth_email = ?
            ');
            $upd->execute([$health, $reason, strtolower($primaryEmail), strtolower($oauthEmail)]);
        } catch (\Throwable $e) {
            // Health columns may not exist yet on a legacy DB; ignore.
        }
    }

    private function cache(): OAuthTokenCache
    {
        if ($this->tokenCache === null) {
            $this->tokenCache = new OAuthTokenCache($this->config);
        }
        return $this->tokenCache;
    }

    private function ensureDb(): void
    {
        if ($this->db === null) {
            $this->db = Database::getConnection($this->config);
        }
    }
}
