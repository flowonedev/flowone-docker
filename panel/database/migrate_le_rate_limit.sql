-- =====================================================
-- Let's Encrypt Rate Limit Ledger
--
-- Tracks every certbot request so the LetsEncryptLimiter service
-- can enforce LE's published quotas before we hit them:
--   - 50 certs/week per registered domain
--   - 5 duplicate certs/week
--   - 5 failed validations/hour per account
--   - 300 new orders/account/3h
--
-- Hitting any of these locks out ALL new certs for the whole server
-- until the window expires, so this ledger is critical.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_le_rate_limit.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS le_rate_limit (
    id                  BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    account_email       VARCHAR(255) NOT NULL,
    registered_domain   VARCHAR(253) NOT NULL
                            COMMENT 'eTLD+1, e.g. example.com for foo.example.com',
    full_domain         VARCHAR(253) NOT NULL,

    request_type        ENUM('issue','renew','revoke') NOT NULL,
    outcome             ENUM('success','failure','rate_limited','pending') NOT NULL,

    cert_serial         VARCHAR(128) NULL,
    cert_expires_at     DATETIME NULL,

    -- For failure analysis and retry-after handling
    error_code          VARCHAR(64) NULL,
    error_message       TEXT NULL,
    retry_after         DATETIME NULL,

    -- Link back to the job that made this request
    job_id              BIGINT UNSIGNED NULL,

    requested_at        DATETIME NOT NULL,

    INDEX idx_account_time (account_email, requested_at),
    INDEX idx_registered (registered_domain, requested_at),
    INDEX idx_outcome (outcome, requested_at),
    INDEX idx_retry_after (retry_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
