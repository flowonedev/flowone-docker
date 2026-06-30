-- =====================================================
-- DIRECT MESSAGE CHAT SYSTEM
-- Migration: 034_chat_system.sql
-- 
-- Implements 1:1 direct messaging between colleagues
-- Real-time via Redis pub/sub -> WebSocket
-- =====================================================

-- Chat conversations (DM only for now, type='direct')
CREATE TABLE IF NOT EXISTS chat_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_domain VARCHAR(255) NOT NULL COMMENT 'Domain for scoping',
    type ENUM('direct', 'group') NOT NULL DEFAULT 'direct',
    
    -- Metadata
    created_by INT UNSIGNED NOT NULL COMMENT 'Colleague ID who initiated',
    last_message_at TIMESTAMP NULL COMMENT 'For sorting conversation list',
    last_message_preview VARCHAR(255) DEFAULT NULL COMMENT 'Snippet for list view',
    last_message_sender_id INT UNSIGNED DEFAULT NULL COMMENT 'Who sent last message',
    message_count INT UNSIGNED DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_domain (organization_domain),
    INDEX idx_last_msg (organization_domain, last_message_at DESC),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversation participants (exactly 2 for DM)
CREATE TABLE IF NOT EXISTS chat_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    colleague_id INT UNSIGNED NOT NULL,
    
    -- Read tracking
    last_read_message_id INT UNSIGNED DEFAULT NULL,
    last_read_at TIMESTAMP NULL,
    unread_count INT UNSIGNED DEFAULT 0,
    
    -- Preferences
    is_pinned TINYINT(1) DEFAULT 0,
    is_muted TINYINT(1) DEFAULT 0,
    is_archived TINYINT(1) DEFAULT 0,
    
    -- State
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_conv_user (conversation_id, colleague_id),
    INDEX idx_colleague (colleague_id),
    INDEX idx_unread (colleague_id, unread_count),
    INDEX idx_archived (colleague_id, is_archived),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DM lookup table for fast finding existing DM between two users
-- colleague_a_id is always the LOWER id, colleague_b_id is HIGHER (canonical ordering)
CREATE TABLE IF NOT EXISTS chat_dm_lookup (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    colleague_a_id INT UNSIGNED NOT NULL COMMENT 'Lower colleague ID',
    colleague_b_id INT UNSIGNED NOT NULL COMMENT 'Higher colleague ID',
    
    UNIQUE KEY unique_dm (colleague_a_id, colleague_b_id),
    INDEX idx_a (colleague_a_id),
    INDEX idx_b (colleague_b_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL COMMENT 'Colleague ID of sender',
    
    -- Content
    content TEXT NOT NULL,
    content_type ENUM('text', 'file', 'image', 'system') DEFAULT 'text',
    
    -- Threading
    reply_to_id INT UNSIGNED DEFAULT NULL COMMENT 'Parent message for replies',
    
    -- Attachments (JSON array of file objects from Drive)
    -- Format: [{"id": 123, "name": "file.pdf", "size": 1024, "type": "application/pdf", "drive_id": 456}]
    attachments JSON DEFAULT NULL,
    
    -- Edit/delete state
    is_edited TINYINT(1) DEFAULT 0,
    edited_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL COMMENT 'Soft delete',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_conv_time (conversation_id, created_at DESC),
    INDEX idx_conv_id (conversation_id, id DESC),
    INDEX idx_sender (sender_id),
    INDEX idx_reply (reply_to_id),
    INDEX idx_deleted (deleted_at),
    FULLTEXT idx_content (content),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (reply_to_id) REFERENCES chat_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message reactions (emoji reactions on messages)
CREATE TABLE IF NOT EXISTS chat_message_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    colleague_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(32) NOT NULL COMMENT 'Unicode emoji or shortcode',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_reaction (message_id, colleague_id, emoji),
    INDEX idx_message (message_id),
    INDEX idx_colleague (colleague_id),
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read receipts (track who has read each message)
CREATE TABLE IF NOT EXISTS chat_read_receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    colleague_id INT UNSIGNED NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_receipt (message_id, colleague_id),
    INDEX idx_message (message_id),
    INDEX idx_colleague (colleague_id),
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Typing indicators (ephemeral, cleaned up periodically)
-- This is mostly handled in-memory/Redis, but table exists for recovery
CREATE TABLE IF NOT EXISTS chat_typing_status (
    conversation_id INT UNSIGNED NOT NULL,
    colleague_id INT UNSIGNED NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (conversation_id, colleague_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

