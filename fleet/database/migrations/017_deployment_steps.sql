-- Deployment Steps: per-step tracking for reliable, observable, resumable deployments
-- Each provisioning step gets its own row with lifecycle state, timing, error classification
-- No foreign key on deployment_id -- app logic handles referential integrity
-- (avoids errno 150 from type/charset mismatches across migration history)

CREATE TABLE IF NOT EXISTS deployment_steps (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    deployment_id INT UNSIGNED NOT NULL,
    step_key VARCHAR(50) NOT NULL,
    step_name VARCHAR(100) NOT NULL,
    step_order INT UNSIGNED NOT NULL,
    weight INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('pending','running','success','failed','skipped','warning') DEFAULT 'pending',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    duration_ms INT UNSIGNED NULL,
    error_message TEXT NULL,
    error_type ENUM('script_bug','server_issue','timeout','dependency','race_condition','ssh_error','unknown') NULL,
    retry_count INT UNSIGNED DEFAULT 0,
    max_retries INT UNSIGNED DEFAULT 2,
    command_log LONGTEXT NULL,
    can_skip TINYINT(1) DEFAULT 0,
    idempotent TINYINT(1) DEFAULT 1,
    UNIQUE KEY uk_deployment_step (deployment_id, step_key),
    INDEX idx_deployment (deployment_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add process tracking columns to deployments
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS pid INT UNSIGNED NULL AFTER current_step;
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS last_heartbeat DATETIME NULL AFTER pid;
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS resumed_from_step VARCHAR(50) NULL AFTER last_heartbeat;
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS failed_step VARCHAR(50) NULL AFTER resumed_from_step;
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS steps_completed INT UNSIGNED DEFAULT 0 AFTER failed_step;
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS steps_total INT UNSIGNED DEFAULT 0 AFTER steps_completed;
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS audit_results JSON NULL;
