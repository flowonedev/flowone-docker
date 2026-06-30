-- Link portal calls to boards/cards for work session bridging
ALTER TABLE portal_calls
    ADD COLUMN IF NOT EXISTS board_id INT UNSIGNED DEFAULT NULL AFTER client_id,
    ADD COLUMN IF NOT EXISTS card_id INT UNSIGNED DEFAULT NULL AFTER board_id;

-- Track call participants and their individual durations
CREATE TABLE IF NOT EXISTS portal_call_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id INT UNSIGNED NOT NULL,
    participant_type ENUM('internal', 'portal', 'guest') NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    joined_at DATETIME DEFAULT NULL,
    left_at DATETIME DEFAULT NULL,
    duration_seconds INT UNSIGNED DEFAULT 0,
    INDEX idx_call (call_id),
    INDEX idx_email (email),
    FOREIGN KEY (call_id) REFERENCES portal_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add 'portal_call' to the source ENUM on projecthub_work_sessions
ALTER TABLE projecthub_work_sessions
    MODIFY COLUMN source ENUM(
        'manual','drive_edit','board_view','timer',
        'card_view','website_work','portal_call'
    ) DEFAULT 'manual';
