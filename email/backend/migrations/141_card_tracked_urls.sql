-- Migration 141: Card-level tracked URLs for Project Hub website time tracking
-- Mirrors board_tracked_urls but scoped to individual cards

CREATE TABLE IF NOT EXISTS projecthub_card_tracked_urls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    url_domain VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    title_match VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_card_url (card_id, url_domain),
    INDEX idx_card (card_id),
    INDEX idx_domain (url_domain),
    INDEX idx_active (is_active),
    FOREIGN KEY (card_id) REFERENCES webmail_board_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add 'website_work' to the source ENUM on projecthub_work_sessions
ALTER TABLE projecthub_work_sessions
    MODIFY COLUMN source ENUM('manual','drive_edit','board_view','timer','card_view','website_work') DEFAULT 'manual';
