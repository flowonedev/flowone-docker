-- Migration 131: Project Hub Watchers
-- Users who follow a card and receive notifications without being assigned

CREATE TABLE IF NOT EXISTS projecthub_watchers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_card_watcher (card_id, user_email),
    INDEX idx_card (card_id),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
