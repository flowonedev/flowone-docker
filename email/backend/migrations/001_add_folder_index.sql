-- Migration: Add folder index tracking and new columns
-- Run this on your production database if auto-migration fails

-- Create folder index table
CREATE TABLE IF NOT EXISTS webmail_folder_index (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(191) NOT NULL,
    folder VARCHAR(191) NOT NULL,
    is_indexed TINYINT(1) DEFAULT 0,
    last_indexed_uid INT DEFAULT 0,
    message_count INT DEFAULT 0,
    indexed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_folder (user_email, folder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add new columns to webmail_conversations (ignore errors if they exist)
ALTER TABLE webmail_conversations ADD COLUMN latest_uid INT DEFAULT 0;
ALTER TABLE webmail_conversations ADD COLUMN latest_message_id VARCHAR(512) DEFAULT NULL;
ALTER TABLE webmail_conversations ADD COLUMN snippet VARCHAR(255) DEFAULT NULL;

