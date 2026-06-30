-- =====================================================
-- Site Jobs - Async Job Queue
--
-- The worker daemon claims jobs via SELECT ... FOR UPDATE SKIP LOCKED.
-- Each job carries a lease (locked_by + lease_until). If a worker dies,
-- the lease expires after 60s and another worker can take over, but
-- only after verifying checkpoint_hash matches its read of the site row.
--
-- request_id correlates a job back to the original HTTP request and
-- forward to every step execution, event, and adapter call.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_site_jobs.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS site_jobs (
    id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    site_domain       VARCHAR(253) NOT NULL,
    type              ENUM(
                          'create','delete','reconcile','retry',
                          'suspend','resume','archive','restore'
                      ) NOT NULL,

    status            ENUM('queued','running','succeeded','failed','cancelled')
                          NOT NULL DEFAULT 'queued',

    -- Queue fairness: priority_class drives WFQ buckets,
    -- priority is the within-class order (lower = earlier).
    priority          TINYINT UNSIGNED NOT NULL DEFAULT 50,
    priority_class    ENUM('operator','reconcile','maintenance')
                          NOT NULL DEFAULT 'operator',

    -- Aging: effective priority recomputed by scheduler from age + base priority.
    aged_priority     SMALLINT UNSIGNED NULL,

    -- Payload is the immutable input. Secrets are vault refs only.
    payload           JSON NOT NULL,
    schema_version    INT UNSIGNED NOT NULL DEFAULT 1,

    -- Progress tracking
    current_step      VARCHAR(64) NULL,
    step_state        JSON NULL,
    checkpoint_hash   CHAR(64) NULL
                          COMMENT 'sha256(step_state||site_row) verified on takeover',

    attempts          INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts      INT UNSIGNED NOT NULL DEFAULT 3,

    -- Lease for crash recovery
    locked_by         VARCHAR(64) NULL,
    lease_until       DATETIME NULL,

    -- Dry-run mode produces a PlanDocument without side effects.
    dry_run           TINYINT(1) NOT NULL DEFAULT 0,

    -- Correlation
    request_id        VARCHAR(64) NULL
                          COMMENT 'Propagated from API -> step -> adapter -> log',
    parent_job_id     BIGINT UNSIGNED NULL
                          COMMENT 'Set when this job was enqueued by another job (e.g. reconcile -> heal)',

    result            JSON NULL,
    error             TEXT NULL,

    -- Provenance
    actor             VARCHAR(128) NOT NULL,
    actor_user_id     INT UNSIGNED NULL,
    source_ip         VARCHAR(45) NULL,

    enqueued_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at        DATETIME NULL,
    finished_at       DATETIME NULL,

    INDEX idx_dequeue (status, priority_class, priority, enqueued_at),
    INDEX idx_aged (status, aged_priority, enqueued_at),
    INDEX idx_site (site_domain),
    INDEX idx_request (request_id),
    INDEX idx_lease (locked_by, lease_until),
    INDEX idx_finished (finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
