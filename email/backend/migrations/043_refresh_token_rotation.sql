-- Add refresh token hash to sessions for token rotation
-- When a refresh token is used, a new one is issued and the old hash is replaced.
-- If a stolen token is replayed (hash mismatch), the entire session is killed.
ALTER TABLE webmail_sessions ADD COLUMN IF NOT EXISTS refresh_token_hash VARCHAR(128) DEFAULT NULL;

