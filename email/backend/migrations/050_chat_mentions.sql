-- =====================================================
-- CHAT MENTIONS TABLE
-- Migration: 050_chat_mentions.sql
--
-- Tracks @mentions in chat messages for notification
-- and mention-feed purposes.
-- =====================================================

CREATE TABLE IF NOT EXISTS chat_mentions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    conversation_id INT UNSIGNED NOT NULL,
    mentioned_colleague_id INT UNSIGNED NULL COMMENT 'NULL for @here/@channel',
    mention_type ENUM('user','here','channel') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mentioned (mentioned_colleague_id, created_at DESC),
    INDEX idx_conversation (conversation_id),
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

