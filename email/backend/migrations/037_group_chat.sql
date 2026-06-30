-- =====================================================
-- GROUP CHAT SUPPORT
-- Migration: 037_group_chat.sql
-- 
-- Adds group chat functionality:
-- - Group name and avatar
-- - Admin roles for group management
-- - Ability to add/remove participants
-- =====================================================

-- Add group-specific fields to chat_conversations
ALTER TABLE chat_conversations 
ADD COLUMN name VARCHAR(100) DEFAULT NULL COMMENT 'Group name (null for DM)',
ADD COLUMN avatar VARCHAR(500) DEFAULT NULL COMMENT 'Group avatar URL',
ADD COLUMN description TEXT DEFAULT NULL COMMENT 'Group description';

-- Add admin flag to participants (for group management)
ALTER TABLE chat_participants
ADD COLUMN is_admin TINYINT(1) DEFAULT 0 COMMENT 'Can manage group members',
ADD COLUMN added_by INT UNSIGNED DEFAULT NULL COMMENT 'Who added this participant',
ADD COLUMN nickname VARCHAR(100) DEFAULT NULL COMMENT 'Custom nickname in this group';

-- Index for admin queries
CREATE INDEX idx_admin ON chat_participants (conversation_id, is_admin);

-- Set existing conversation creators as admins
UPDATE chat_participants p
JOIN chat_conversations c ON p.conversation_id = c.id
SET p.is_admin = 1
WHERE p.colleague_id = c.created_by;

-- Group invitations (for external users not yet in organization)
CREATE TABLE IF NOT EXISTS chat_group_invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    invited_email VARCHAR(255) NOT NULL,
    invited_by INT UNSIGNED NOT NULL COMMENT 'Colleague ID who sent invite',
    
    -- Invitation state
    token VARCHAR(100) NOT NULL COMMENT 'Unique invitation token',
    status ENUM('pending', 'accepted', 'declined', 'expired') DEFAULT 'pending',
    
    -- Metadata
    message TEXT DEFAULT NULL COMMENT 'Optional invitation message',
    expires_at TIMESTAMP NULL COMMENT 'When invitation expires',
    responded_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_invite (conversation_id, invited_email),
    UNIQUE KEY unique_token (token),
    INDEX idx_email (invited_email),
    INDEX idx_status (status),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

