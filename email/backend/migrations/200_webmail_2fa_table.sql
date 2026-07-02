-- Migration 200: create the webmail_2fa table.
--
-- TwoFactorService reads/writes this table, and AuthController::login checks
-- isEnabled() FAIL-CLOSED: if the query throws, login answers 503 for every
-- user. The table existed only on the original production box (created by
-- hand, never captured in a migration), so every freshly provisioned server
-- had webmail login hard-broken until this file. Idempotent by design.

CREATE TABLE IF NOT EXISTS webmail_2fa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL COMMENT 'User email (one 2FA config per mailbox)',
    secret VARCHAR(64) DEFAULT NULL COMMENT 'Base32 TOTP secret (NULL when disabled)',
    enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 once the user confirmed the first code',
    backup_codes TEXT DEFAULT NULL COMMENT 'JSON array of bcrypt-hashed one-time backup codes',
    trusted_device_count INT NOT NULL DEFAULT 0 COMMENT 'Denormalized count of active trusted devices',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
