-- Phase 1 of the OAuth + IMAP ground-up rewrite.
--
-- refresh-oauth-tokens.php runs every 15 minutes and queries
--   WHERE COALESCE(health, 'healthy') = 'healthy'
--     AND token_expires_at < (NOW() + INTERVAL 30 MINUTE)
--
-- Without this index that's a full table scan of webmail_oauth_tokens
-- on every cron pass. The compound index on (token_expires_at) lets
-- MariaDB do a range scan instead. The same index also helps the
-- (rare) ad-hoc queries that filter by expiry.
--
-- Idempotent: safe to re-run.

CREATE INDEX IF NOT EXISTS idx_token_expires_at
    ON webmail_oauth_tokens (token_expires_at);
