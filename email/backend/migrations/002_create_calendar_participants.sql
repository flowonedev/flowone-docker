-- Migration: Create calendar event participants table

-- Calendar event participants table
-- Note: Uses user_email pattern like other tables, not user_id foreign keys
CREATE TABLE IF NOT EXISTS calendar_event_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    status ENUM('pending', 'accepted', 'declined', 'tentative') NOT NULL DEFAULT 'pending',
    invited_by_email VARCHAR(255) NOT NULL,
    invited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    response_message TEXT DEFAULT NULL,
    invite_token VARCHAR(64) NOT NULL,
    reminder_sent TINYINT(1) DEFAULT 0,
    
    INDEX idx_event_id (event_id),
    INDEX idx_user_email (user_email),
    INDEX idx_status (status),
    INDEX idx_invite_token (invite_token),
    
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_event_participant (event_id, user_email),
    UNIQUE KEY unique_invite_token (invite_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
