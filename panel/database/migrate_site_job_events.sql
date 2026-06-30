-- =====================================================
-- Site Job Events - Append-Only Event Log
--
-- Higher granularity than site_step_executions. Used to stream
-- live progress to the UI via Server-Sent Events on
-- GET /jobs/{id}/events.
--
-- One step can emit many events (start, progress milestones,
-- warnings, end). step_executions has one row per step attempt.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_site_job_events.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS site_job_events (
    id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    job_id            BIGINT UNSIGNED NOT NULL,
    site_domain       VARCHAR(253) NOT NULL,
    step_name         VARCHAR(64) NULL,

    level             ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
    message           TEXT NOT NULL,
    metadata          JSON NULL,

    request_id        VARCHAR(64) NULL,

    occurred_at       DATETIME(3) NOT NULL,

    INDEX idx_job (job_id, id),
    INDEX idx_site_time (site_domain, occurred_at),
    INDEX idx_level (level, occurred_at),
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
