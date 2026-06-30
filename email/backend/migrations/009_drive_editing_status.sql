-- Drive editing status - tracks who is currently editing files
-- Used for real-time collaboration indicators

CREATE TABLE IF NOT EXISTS drive_editing_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NULL,
    filename VARCHAR(255) NOT NULL,
    folder_id INT NULL,
    user_email VARCHAR(255) NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_heartbeat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_email (user_email),
    INDEX idx_file_id (file_id),
    INDEX idx_folder_id (folder_id),
    INDEX idx_last_heartbeat (last_heartbeat),
    UNIQUE KEY unique_user_file (user_email, filename, folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto-cleanup: Sessions older than 5 minutes are considered expired
-- The clearExpiredEditingSessions() method handles this

