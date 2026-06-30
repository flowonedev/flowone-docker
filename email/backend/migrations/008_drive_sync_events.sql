-- Drive sync events for real-time notifications
-- This table stores lightweight sync events that clients can poll efficiently

CREATE TABLE IF NOT EXISTS webmail_drive_sync_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    event_type ENUM('file_created', 'file_updated', 'file_deleted', 'folder_created', 'folder_deleted') NOT NULL,
    file_id INT NULL,
    folder_id INT NULL,
    file_name VARCHAR(255) NULL,
    new_version INT NULL,
    modified_by VARCHAR(255) NULL,
    source VARCHAR(50) DEFAULT 'web',  -- 'web', 'electron', 'api'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_email, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto-cleanup: Events older than 24 hours can be deleted
-- This keeps the table small and queries fast

