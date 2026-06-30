-- Email Attachments Cache for search indexing
-- Stores attachment metadata from emails for fast searching

CREATE TABLE IF NOT EXISTS webmail_email_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    folder VARCHAR(255) NOT NULL,
    uid INT NOT NULL,
    message_id VARCHAR(512) DEFAULT NULL,
    
    -- Attachment details
    filename VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    size INT DEFAULT 0,
    
    -- Sender info (for grouping)
    from_email VARCHAR(255) DEFAULT NULL,
    from_name VARCHAR(255) DEFAULT NULL,
    
    -- Message context
    subject VARCHAR(512) DEFAULT NULL,
    message_date DATETIME DEFAULT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    UNIQUE KEY unique_attachment (user_email, folder, uid, filename(255)),
    INDEX idx_user_email (user_email),
    INDEX idx_filename (filename(100)),
    INDEX idx_mime_type (mime_type),
    INDEX idx_from_email (from_email),
    INDEX idx_date (message_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add email_attachment to the search index enum
ALTER TABLE universal_search_index 
MODIFY COLUMN source_type ENUM('email', 'email_attachment', 'calendar_event', 'drive_file', 'drive_folder', 'board', 'card', 'checklist_item', 'todo', 'client', 'contact', 'collab_doc') NOT NULL;

