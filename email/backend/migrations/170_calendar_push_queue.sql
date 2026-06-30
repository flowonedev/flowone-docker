-- 170_calendar_push_queue.sql
-- Phase 3.2: queue table for local->Google calendar pushes that failed inline,
-- plus a flag on calendar_sync_state so the cron knows which calendars have
-- pending work without scanning the queue every time.

ALTER TABLE calendar_sync_state
    ADD COLUMN IF NOT EXISTS pending_push TINYINT(1) NOT NULL DEFAULT 0
    AFTER sync_enabled;

CREATE TABLE IF NOT EXISTS calendar_push_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_state_id INT NOT NULL,
    local_event_id INT NULL,
    google_event_id VARCHAR(255) NULL,
    op ENUM('create_update', 'delete') NOT NULL DEFAULT 'create_update',
    attempts INT NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    next_attempt_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sync_state (sync_state_id),
    INDEX idx_next_attempt (next_attempt_at),
    INDEX idx_local_event (local_event_id),
    UNIQUE KEY unique_pending (sync_state_id, local_event_id, op)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
