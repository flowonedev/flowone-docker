-- OAuth health + canary table for at-rest token encryption hardening
-- Idempotent: safe to run on a DB where the columns / table already exist.

ALTER TABLE webmail_oauth_tokens
    ADD COLUMN IF NOT EXISTS health ENUM('healthy','broken','revoked') NOT NULL DEFAULT 'healthy',
    ADD COLUMN IF NOT EXISTS health_reason VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS health_updated_at TIMESTAMP NULL;

-- MariaDB 10.0.2+ supports CREATE INDEX IF NOT EXISTS.
CREATE INDEX IF NOT EXISTS idx_oauth_health ON webmail_oauth_tokens (health);

-- Single-row canary table used by OAuthCryptor::canaryCheck()
CREATE TABLE IF NOT EXISTS webmail_canary (
    id TINYINT PRIMARY KEY,
    canary_encrypted TEXT NOT NULL,
    encrypted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    rotated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
