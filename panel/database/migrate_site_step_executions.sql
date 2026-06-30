-- =====================================================
-- Site Step Executions - Per-Attempt Transaction Journal
--
-- One append-only row per step execution attempt. Captures input,
-- output, duration, exit code, worker, and stdout/stderr excerpts.
-- This becomes the foundation of the per-domain timeline view and
-- enables failure-pattern queries ("which step fails most often?").
--
-- All snapshots are pre-masked by SecretMasker before write.
-- Never insert plaintext credentials here.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_site_step_executions.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS site_step_executions (
    id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    job_id            BIGINT UNSIGNED NOT NULL,
    site_domain       VARCHAR(253) NOT NULL,
    step_name         VARCHAR(64) NOT NULL,
    attempt_number    INT UNSIGNED NOT NULL,

    -- Schema version of the step state shape captured below.
    -- StepStateMigrator uses this on resume.
    schema_version    INT UNSIGNED NOT NULL DEFAULT 1,

    -- Lifecycle (millisecond precision for short steps)
    started_at        DATETIME(3) NOT NULL,
    finished_at       DATETIME(3) NULL,
    duration_ms       INT UNSIGNED NULL,

    -- Outcome
    outcome           ENUM('success','failure','skipped','timeout','killed') NULL,
    exit_code         INT NULL,

    -- Captured state - secrets are pre-masked
    input_snapshot    JSON NULL,
    output_snapshot   JSON NULL,
    stdout_excerpt    TEXT NULL COMMENT 'Last 2KB of stdout',
    stderr_excerpt    TEXT NULL COMMENT 'Last 2KB of stderr',
    error             TEXT NULL,

    -- Provenance / correlation
    worker_id         VARCHAR(64) NULL,
    subprocess_pid    INT UNSIGNED NULL,
    request_id        VARCHAR(64) NULL,

    INDEX idx_job (job_id, started_at),
    INDEX idx_site (site_domain, started_at),
    INDEX idx_step_outcome (step_name, outcome, started_at),
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
