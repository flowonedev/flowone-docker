-- Migration 133: Project Hub Calendar Map
-- Links PH cards to local calendar events and tracks Google Calendar sync

CREATE TABLE IF NOT EXISTS projecthub_card_calendar_map (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    calendar_event_id INT UNSIGNED DEFAULT NULL,
    google_event_id VARCHAR(255) DEFAULT NULL,
    calendar_id VARCHAR(255) DEFAULT NULL,
    user_email VARCHAR(255) NOT NULL,
    sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_synced_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_card_user (card_id, user_email),
    INDEX idx_card (card_id),
    INDEX idx_user (user_email),
    INDEX idx_google_event (google_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
