-- Migration 059: Public share links and analytics tracking for mood boards
-- Enables sharing mood boards with clients via public URL (no authentication required)

-- 1. Add public sharing columns to mood_boards
ALTER TABLE mood_boards
    ADD COLUMN IF NOT EXISTS share_token VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS share_mode ENUM('off','view','edit') DEFAULT 'off',
    ADD COLUMN IF NOT EXISTS share_password VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS share_expires TIMESTAMP NULL DEFAULT NULL;

-- Add unique index on share_token (use procedure to handle idempotency)
DROP PROCEDURE IF EXISTS add_mood_share_token_index;
DELIMITER //
CREATE PROCEDURE add_mood_share_token_index()
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO idx_exists FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mood_boards' AND INDEX_NAME = 'idx_mood_share_token';
    IF idx_exists = 0 THEN
        ALTER TABLE mood_boards ADD UNIQUE INDEX idx_mood_share_token (share_token);
    END IF;
END //
DELIMITER ;
CALL add_mood_share_token_index();
DROP PROCEDURE IF EXISTS add_mood_share_token_index;

-- 2. Share view tracking for analytics
CREATE TABLE IF NOT EXISTS mood_board_share_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    session_id VARCHAR(64) NOT NULL COMMENT 'Client-generated session ID for duration tracking',
    visitor_ip VARCHAR(45) DEFAULT NULL COMMENT 'IPv4 or IPv6 address',
    user_agent VARCHAR(500) DEFAULT NULL,
    referrer VARCHAR(2000) DEFAULT NULL,
    device_type ENUM('desktop','mobile','tablet','unknown') DEFAULT 'unknown',
    browser VARCHAR(100) DEFAULT NULL,
    os VARCHAR(100) DEFAULT NULL,
    duration_seconds INT DEFAULT 0 COMMENT 'Total viewing time in seconds',
    slides_viewed INT DEFAULT 0 COMMENT 'Number of presentation slides viewed',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_heartbeat_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_board (board_id),
    INDEX idx_session (session_id),
    INDEX idx_started (started_at),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

