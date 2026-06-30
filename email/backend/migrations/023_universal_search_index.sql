-- Universal Search Index for Super Master Search
-- Allows full-text search across emails, drive files, boards, cards, todos, clients

CREATE TABLE IF NOT EXISTS universal_search_index (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    
    -- Source identification
    source_type ENUM('email', 'email_attachment', 'calendar_event', 'drive_file', 'drive_folder', 'board', 'card', 'checklist_item', 'todo', 'client', 'contact', 'collab_doc') NOT NULL,
    source_id VARCHAR(255) NOT NULL,  -- The ID within source (uid for email, id for others)
    
    -- Searchable content
    title VARCHAR(500),               -- Subject/filename/card title/todo title
    content_text LONGTEXT,            -- Full text (email body, doc content, description)
    content_snippet VARCHAR(1000),    -- Preview snippet (~500 chars)
    
    -- Relationships (for showing context in results)
    client_id INT DEFAULT NULL,
    client_name VARCHAR(255) DEFAULT NULL,
    board_id INT DEFAULT NULL,
    board_name VARCHAR(255) DEFAULT NULL,
    folder_id INT DEFAULT NULL,
    folder_name VARCHAR(255) DEFAULT NULL,
    list_id INT DEFAULT NULL,
    list_name VARCHAR(255) DEFAULT NULL,
    
    -- Metadata
    source_date DATETIME,             -- When created/sent
    mime_type VARCHAR(100) DEFAULT NULL,
    extra_data JSON,                  -- Any additional info (from/to for emails, etc.)
    
    indexed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for fast lookups
    UNIQUE KEY unique_source (user_email, source_type, source_id),
    INDEX idx_user (user_email),
    INDEX idx_client (user_email, client_id),
    INDEX idx_board (user_email, board_id),
    INDEX idx_date (source_date),
    INDEX idx_type (user_email, source_type),
    
    -- Full-text index for search
    FULLTEXT INDEX ft_search (title, content_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for faster LIKE searches as fallback
CREATE INDEX idx_title ON universal_search_index(title(100));

