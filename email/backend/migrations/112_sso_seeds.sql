-- Migration 112: SSO seed-based authentication for desktop app cross-auth
-- Seeds are purpose-limited credentials that can only be used to clone sessions
-- They rotate on every clone and can be revoked server-side

CREATE TABLE IF NOT EXISTS sso_seeds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seed_id VARCHAR(64) NOT NULL,
    seed_secret_hmac VARCHAR(128) NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked BOOLEAN NOT NULL DEFAULT FALSE,
    revoked_at DATETIME DEFAULT NULL,
    UNIQUE INDEX idx_seed_id (seed_id),
    INDEX idx_user_active (user_email, revoked),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sso_user_state (
    user_email VARCHAR(255) NOT NULL PRIMARY KEY,
    logout_epoch DATETIME DEFAULT NULL COMMENT 'Seeds created before this timestamp are rejected by clone-session',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sso_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(16) NOT NULL,
    nonce VARCHAR(16) NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    used_at DATETIME DEFAULT NULL,
    UNIQUE INDEX idx_code (code),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
