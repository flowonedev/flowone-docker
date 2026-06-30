-- =====================================================
-- Site Audit Log - Every Destructive or Sensitive Action
--
-- Append-only. Required for any enterprise hosting panel handling
-- customer data. Records actor, IP, API token, reason, and before/
-- after JSON snapshots so the full history is reconstructable.
--
-- Distinct from the panel-wide audit_logs table (which is generic
-- panel-action logging). This one is site-specific with structured
-- before/after diffs.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_site_audit_log.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS site_audit_log (
    id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    occurred_at       DATETIME(3) NOT NULL,
    action            VARCHAR(64) NOT NULL
                          COMMENT 'create, delete, suspend, resume, archive, restore, state_transition, secret_read, secret_rotate, force_destroy, manual_config_edit, orphan_import, orphan_destroy, etc.',
    site_domain       VARCHAR(253) NULL,

    -- Actor identification
    actor_user_id     INT UNSIGNED NULL,
    actor_username    VARCHAR(128) NULL,
    source_ip         VARCHAR(45) NULL,
    api_token_id      VARCHAR(64) NULL,
    user_agent        VARCHAR(255) NULL,

    -- Free-form operator-supplied reason (e.g. "customer cancellation")
    reason            TEXT NULL,

    -- Before / after snapshots of the affected resource (sites row, secret, etc.)
    before_snapshot   JSON NULL,
    after_snapshot    JSON NULL,

    -- Linking
    job_id            BIGINT UNSIGNED NULL,
    request_id        VARCHAR(64) NULL,

    INDEX idx_site (site_domain, occurred_at),
    INDEX idx_actor (actor_user_id, occurred_at),
    INDEX idx_action (action, occurred_at),
    INDEX idx_request (request_id),
    INDEX idx_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
