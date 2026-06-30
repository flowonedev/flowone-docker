-- =====================================================
-- Orphan Resources - Quarantine for Unmanaged Artifacts
--
-- The reconciler may find filesystem dirs, DB schemas, DNS zones,
-- SFTP users, or SSL certs with no matching sites row. Auto-importing
-- these is dangerous (could pick up unrelated customer data) and
-- auto-destroying them is even worse. Instead they are moved to a
-- quarantine path on disk and a row is inserted here in
-- status='pending_review' until an operator decides.
--
-- This is the same model Terraform uses for unmanaged resources
-- and ArgoCD uses for prune protection.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_orphan_resources.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS orphan_resources (
    id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    resource_type     ENUM(
                          'vhost_dir','home_dir','ols_config_entry',
                          'database','db_user','sftp_user',
                          'dns_zone','mail_domain',
                          'letsencrypt_cert','dkim_key',
                          'postfix_map_entry','dovecot_map_entry'
                      ) NOT NULL,

    -- Name of the artifact (vhost dir name, db name, username, etc.)
    name              VARCHAR(253) NOT NULL,
    -- Original location on disk if any (e.g. /home/foo, /usr/local/lsws/conf/vhosts/foo)
    location          VARCHAR(500) NULL,

    -- Inferred owning domain if the reconciler could guess one (NULL = totally unknown)
    associated_domain VARCHAR(253) NULL,

    -- Where the artifact was moved to (filesystem orphans only)
    quarantine_path   VARCHAR(500) NULL,

    -- Free-form metadata (size, mtime, owner, contents summary, etc.)
    metadata          JSON NULL,

    discovered_at     DATETIME NOT NULL,
    discovered_by     VARCHAR(64) NOT NULL DEFAULT 'reconciler',

    status            ENUM('pending_review','imported','destroyed','dismissed')
                          NOT NULL DEFAULT 'pending_review',

    -- Operator review
    reviewed_by       VARCHAR(128) NULL,
    reviewed_at       DATETIME NULL,
    review_action     VARCHAR(64) NULL
                          COMMENT 'e.g. "imported_as_site:foo.com", "destroyed", "dismissed"',
    review_notes      TEXT NULL,

    UNIQUE KEY uniq_resource (resource_type, name),
    INDEX idx_status (status, discovered_at),
    INDEX idx_associated (associated_domain),
    INDEX idx_discovered (discovered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
