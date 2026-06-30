-- Chat Huddles: Persistent audio rooms per conversation
-- Unlike regular calls (1:1, ring-and-answer), huddles are always-open audio rooms
-- that any conversation member can join/leave at will

CREATE TABLE IF NOT EXISTS chat_huddles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    started_by INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (started_by) REFERENCES organization_colleagues(id) ON DELETE CASCADE,
    INDEX idx_huddle_conv_active (conversation_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_huddle_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    huddle_id INT UNSIGNED NOT NULL,
    colleague_id INT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME DEFAULT NULL,
    is_muted TINYINT(1) NOT NULL DEFAULT 0,
    is_deafened TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (huddle_id) REFERENCES chat_huddles(id) ON DELETE CASCADE,
    FOREIGN KEY (colleague_id) REFERENCES organization_colleagues(id) ON DELETE CASCADE,
    UNIQUE KEY idx_huddle_participant (huddle_id, colleague_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
