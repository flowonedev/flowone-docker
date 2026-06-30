-- Migration 012: Board Tracked URLs for website time tracking
-- This table stores website domains that should be tracked for time when visited

CREATE TABLE IF NOT EXISTS board_tracked_urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    url_domain VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_board_url (board_id, url_domain),
    INDEX idx_board (board_id),
    INDEX idx_client (client_id),
    INDEX idx_active (is_active),
    
    FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

