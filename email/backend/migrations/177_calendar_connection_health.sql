-- Phase 3 - OAuth hardening for calendar.
-- Mirror the health columns we already have on webmail_oauth_tokens
-- so OAuthHealthService::markCalendarRevoked() can record terminal
-- failures (invalid_grant on Google's calendar token endpoint) and
-- the refresh-oauth-tokens cron can skip those rows on the next pass.
ALTER TABLE calendar_connections
    ADD COLUMN IF NOT EXISTS health VARCHAR(32) NOT NULL DEFAULT 'healthy',
    ADD COLUMN IF NOT EXISTS health_reason VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS health_updated_at DATETIME NULL;

-- Index supports the cron's "give me rows expiring soon AND still
-- healthy" query without a full table scan once we have many users.
ALTER TABLE calendar_connections
    ADD INDEX IF NOT EXISTS idx_calconn_health_expiry (health, token_expires_at);
