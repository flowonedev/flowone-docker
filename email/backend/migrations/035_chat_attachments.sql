-- =====================================================
-- CHAT ATTACHMENTS TRACKING TABLE
-- Migration: 035_chat_attachments.sql
-- 
-- Track all attachments uploaded to chats for:
-- - Easy retrieval of all conversation attachments
-- - "Save to Drive" feature
-- - Gallery views
-- =====================================================

CREATE TABLE IF NOT EXISTS chat_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    uploader_id INT UNSIGNED NOT NULL COMMENT 'Colleague ID who uploaded',
    
    -- File info
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(512) NOT NULL COMMENT 'Storage path',
    file_size INT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    
    -- Categorization
    file_category ENUM('image', 'video', 'audio', 'document', 'archive', 'other') NOT NULL DEFAULT 'other',
    
    -- Image metadata (for galleries)
    image_width INT UNSIGNED DEFAULT NULL,
    image_height INT UNSIGNED DEFAULT NULL,
    thumbnail_path VARCHAR(512) DEFAULT NULL,
    
    -- Drive integration
    drive_file_id INT UNSIGNED DEFAULT NULL COMMENT 'If saved to Drive',
    saved_to_drive_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_conversation (conversation_id),
    INDEX idx_message (message_id),
    INDEX idx_uploader (uploader_id),
    INDEX idx_category (conversation_id, file_category),
    INDEX idx_created (conversation_id, created_at DESC),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

