-- =====================================================
-- Secrets Vault - Encrypted Secret Storage
--
-- All secrets (DB passwords, DKIM private keys, LE account keys,
-- API tokens, SFTP passwords) are encrypted at rest with libsodium
-- crypto_secretbox (XSalsa20-Poly1305).
--
-- The master key lives in /etc/flowone/master.key, mode 0400 root,
-- never in the database, never in git. Without the master key the
-- ciphertext is unrecoverable. This is the disaster-recovery seam.
--
-- Rotation: put a new version with rotate(), keep the old one for
-- 7 days as is_current=0 + expires_at, then expire it. Lets callers
-- roll back if the new value is rejected by the downstream system.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_secrets_vault.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS secrets_vault (
    id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Scope namespaces secrets so we never mix them up.
    -- Examples: "site:example.com", "system", "le_account:admin@example.com"
    scope             VARCHAR(64) NOT NULL,
    key_name          VARCHAR(128) NOT NULL,

    -- Version starts at 1, increments on every rotation.
    version           INT UNSIGNED NOT NULL DEFAULT 1,
    is_current        TINYINT(1) NOT NULL DEFAULT 1,

    -- libsodium crypto_secretbox payload
    ciphertext        BLOB NOT NULL,
    nonce             BINARY(24) NOT NULL
                          COMMENT '24-byte XSalsa20 nonce',
    algo              VARCHAR(32) NOT NULL DEFAULT 'crypto_secretbox',
    master_key_id     VARCHAR(64) NOT NULL DEFAULT 'master.v1'
                          COMMENT 'Identifies which master key was used. Enables master-key rotation.',

    -- Optional metadata to help callers without decrypting
    description       VARCHAR(255) NULL,
    metadata          JSON NULL,

    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    rotated_at        DATETIME NULL
                          COMMENT 'When this version became non-current',
    expires_at        DATETIME NULL
                          COMMENT 'Old versions are purged after expires_at',

    UNIQUE KEY uniq_scope_key_version (scope, key_name, version),
    INDEX idx_current (scope, key_name, is_current),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- Secret Access Audit
--
-- Every put/get/rotate/wipe is recorded. This is separate from
-- site_audit_log so secret reads (which happen frequently during
-- provisioning) don't drown out site-level audit events.
-- =====================================================

CREATE TABLE IF NOT EXISTS secrets_audit (
    id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    scope             VARCHAR(64) NOT NULL,
    key_name          VARCHAR(128) NOT NULL,
    action            ENUM('put','get','rotate','wipe','expire') NOT NULL,
    version           INT UNSIGNED NULL,

    actor_user_id     INT UNSIGNED NULL,
    actor_username    VARCHAR(128) NULL,
    actor_service     VARCHAR(64) NULL
                          COMMENT 'e.g. "site-worker", "reconciler", "step:database.create"',
    source_ip         VARCHAR(45) NULL,
    request_id        VARCHAR(64) NULL,

    occurred_at       DATETIME(3) NOT NULL,

    INDEX idx_scope_key (scope, key_name, occurred_at),
    INDEX idx_actor (actor_user_id, occurred_at),
    INDEX idx_request (request_id),
    INDEX idx_action (action, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
