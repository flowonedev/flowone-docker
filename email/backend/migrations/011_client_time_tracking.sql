-- Client Time Tracking & Team Management Migration
-- This migration adds:
-- 1. client_members table for team membership per client
-- 2. webmail_client_time_tracking table for per-client time tracking
-- 3. client_id column to calendar_events for calendar-client linking

-- =====================================================
-- Part 1: Client Team Membership
-- =====================================================
CREATE TABLE IF NOT EXISTS client_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    role ENUM('owner', 'member') DEFAULT 'member',
    added_by VARCHAR(255) NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_member (client_id, user_email),
    INDEX idx_client (client_id),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Part 2: Per-Client Time Tracking
-- =====================================================
CREATE TABLE IF NOT EXISTS webmail_client_time_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    activity_type ENUM('email_read', 'email_compose', 'calendar_event', 
                       'board_view', 'board_task', 'drive_browse', 
                       'document_open', 'document_edit') NOT NULL,
    entity_id VARCHAR(255) DEFAULT NULL,
    entity_name VARCHAR(500) DEFAULT NULL,
    duration_seconds INT NOT NULL DEFAULT 0,
    tracked_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_track (user_email, client_id, activity_type, entity_id, tracked_date),
    INDEX idx_user_client_date (user_email, client_id, tracked_date),
    INDEX idx_client_date (client_id, tracked_date),
    INDEX idx_activity (activity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Part 3: Calendar-Client Linking
-- =====================================================
-- Add client_id column to calendar_events if it doesn't exist
-- Using a procedure to check if column exists first

SET @dbname = DATABASE();
SET @tablename = 'calendar_events';
SET @columnname = 'client_id';
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @dbname
       AND TABLE_NAME = @tablename
       AND COLUMN_NAME = @columnname) > 0,
    'SELECT 1',
    'ALTER TABLE calendar_events ADD COLUMN client_id INT UNSIGNED DEFAULT NULL, ADD INDEX idx_client (client_id)'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

