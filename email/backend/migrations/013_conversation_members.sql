-- Conversation Members Table
-- Migration: Create webmail_conversation_members for persistent conversation grouping
-- 
-- This replaces on-the-fly conversation computation with database-backed storage.
-- Benefits:
--   - Consistent counts (no more 5 -> 2 -> 7 fluctuation)
--   - Faster (no re-computation on every fetch)
--   - User overrides persist across sessions/devices
--   - Reduced server load

CREATE TABLE IF NOT EXISTS webmail_conversation_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL COMMENT 'The webmail user who owns this record',
    conversation_id VARCHAR(64) NOT NULL COMMENT 'Unique identifier for the conversation group',
    message_id VARCHAR(512) NOT NULL COMMENT 'RFC 2822 Message-ID header value',
    folder VARCHAR(255) NOT NULL COMMENT 'IMAP folder where message resides',
    uid INT NOT NULL COMMENT 'IMAP UID of the message',
    subject VARCHAR(512) DEFAULT NULL COMMENT 'Cached subject for display',
    from_email VARCHAR(255) DEFAULT NULL COMMENT 'Cached sender email',
    from_name VARCHAR(255) DEFAULT NULL COMMENT 'Cached sender name',
    message_date DATETIME DEFAULT NULL COMMENT 'Message date for sorting',
    is_user_override BOOLEAN DEFAULT FALSE COMMENT 'True if user manually moved this message',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Unique constraint: one message can only be in one conversation per user
    UNIQUE KEY unique_msg (user_email, folder, message_id),
    
    -- Index for getting all messages in a conversation
    INDEX idx_conv (user_email, conversation_id),
    
    -- Index for folder-based queries
    INDEX idx_folder (user_email, folder),
    
    -- Index for conversation counts per folder
    INDEX idx_folder_conv (user_email, folder, conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversation metadata table for caching conversation-level info
CREATE TABLE IF NOT EXISTS webmail_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    conversation_id VARCHAR(64) NOT NULL COMMENT 'Same as in members table',
    folder VARCHAR(255) NOT NULL COMMENT 'Primary folder for this conversation',
    subject VARCHAR(512) DEFAULT NULL COMMENT 'Conversation subject (from first/latest message)',
    message_count INT DEFAULT 0 COMMENT 'Cached count of messages',
    unread_count INT DEFAULT 0 COMMENT 'Cached count of unread messages',
    has_attachment BOOLEAN DEFAULT FALSE COMMENT 'Any message has attachment',
    latest_date DATETIME DEFAULT NULL COMMENT 'Date of newest message',
    latest_from VARCHAR(255) DEFAULT NULL COMMENT 'Sender of newest message',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_conv (user_email, conversation_id),
    INDEX idx_folder (user_email, folder),
    INDEX idx_latest (user_email, folder, latest_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

