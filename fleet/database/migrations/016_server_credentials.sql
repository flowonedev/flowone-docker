-- Server credentials table
-- Stores all generated passwords, users, and secrets from provisioning
-- Values are encrypted with the fleet manager's encryption key

CREATE TABLE IF NOT EXISTS server_credentials (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,
    credential_key VARCHAR(100) NOT NULL,
    label VARCHAR(100) NOT NULL,
    value_encrypted TEXT NOT NULL,
    is_secret TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE KEY uk_server_credential (server_id, credential_key),
    INDEX idx_server_id (server_id),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Also store panel_admin_password if not already in servers table
ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS panel_admin_password_encrypted TEXT NULL AFTER panel_admin_email;

-- Ensure redis/meili columns exist (may already from migration 012)
ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS redis_password_encrypted TEXT NULL AFTER mail_db_password_encrypted;
ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS meili_master_key_encrypted TEXT NULL AFTER redis_password_encrypted;

