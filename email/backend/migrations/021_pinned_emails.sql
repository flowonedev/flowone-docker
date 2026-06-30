-- Pinned emails table
-- Since IMAP doesn't have a native "pin" flag, we store pins in the database

CREATE TABLE IF NOT EXISTS pinned_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    folder VARCHAR(255) NOT NULL,
    uid INT NOT NULL,
    message_id VARCHAR(512) NULL,
    subject VARCHAR(512) NULL,
    pinned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pin (user_email, folder, uid),
    INDEX idx_user_folder (user_email, folder),
    INDEX idx_message_id (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

