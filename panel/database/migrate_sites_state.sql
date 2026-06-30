-- =====================================================
-- Sites Table - Source of Truth for Site Provisioning
--
-- Replaces the prior partial draft. The new model splits state
-- into desired_state (what operator asked for) and actual_state
-- (what reality currently is). All transitions go through the
-- SiteStateMachine service - direct writes to actual_state are
-- considered a bug and caught by the architecture-boundary test.
--
-- This table is the source of truth. The filesystem, OLS config,
-- MariaDB schemas, DNS zones, etc. are projections kept in sync
-- by the reconciler.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_sites_state.sql
-- =====================================================

-- Drop prior partial draft if present. Safe because nothing references it yet.
DROP TABLE IF EXISTS provisioning_jobs;

CREATE TABLE IF NOT EXISTS sites (
    id                   INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain               VARCHAR(253) NOT NULL,

    -- Operator intent vs reality
    desired_state        ENUM('active','suspended','archived','deleted')
                             NOT NULL DEFAULT 'active',
    actual_state         ENUM(
                             'absent','provisioning','pending_dns','active',
                             'suspended','degraded','restoring','failed',
                             'deleting','archived'
                         ) NOT NULL DEFAULT 'absent',

    -- Per-subsystem health: {"vhost":"ok","ssl":"ok","dns":"missing","mail":"degraded","db":"ok"}
    health               JSON NULL,

    -- Site attributes (denormalized for fast list views and reconciler diffs)
    php_version          VARCHAR(20) NULL DEFAULT 'lsphp83',
    sftp_user            VARCHAR(64) NULL,
    home_dir             VARCHAR(255) NULL,
    document_root        VARCHAR(255) NULL,

    -- du -sb home_dir, cached by the reconciler.
    -- Nullable so newly backfilled rows render "—" until the first
    -- reconciler tick measures them.
    size_bytes           BIGINT UNSIGNED NULL,
    size_probed_at       DATETIME NULL,

    -- SSL state
    ssl_enabled          TINYINT(1) NOT NULL DEFAULT 0,
    ssl_expires_at       DATETIME NULL,
    ssl_issuer           VARCHAR(64) NULL,

    -- DNS state
    dns_enabled          TINYINT(1) NOT NULL DEFAULT 0,

    -- Mail state
    mail_enabled         TINYINT(1) NOT NULL DEFAULT 0,

    -- Linked database (one per site for now; denormalized)
    db_name              VARCHAR(64) NULL,
    db_user              VARCHAR(32) NULL,

    -- Full original creation params - enables reprovision/restore from scratch.
    -- Secrets must be vault references, never plaintext.
    config               JSON NULL,

    -- Per-step state map: {"step_name": "completed", "step2": "failed"}
    -- Resumed jobs read this via check() and skip completed steps.
    state                JSON NULL,

    -- Phase 2 cached rendered config fragment (vhost block + map entries).
    -- Updated whenever a config-affecting column changes. Avoids
    -- re-rendering the whole httpd_config.conf for every site every restart.
    rendered_fragment    LONGTEXT NULL,

    -- Lifecycle timestamps
    suspended_at         DATETIME NULL,
    suspended_reason     VARCHAR(255) NULL,
    archived_at          DATETIME NULL,
    imported_at          DATETIME NULL
                             COMMENT 'Set when backfilled from legacy filesystem',

    last_error           TEXT NULL,

    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_domain (domain),
    INDEX idx_desired (desired_state),
    INDEX idx_actual (actual_state),
    INDEX idx_ssl_expires (ssl_expires_at),
    INDEX idx_imported (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
