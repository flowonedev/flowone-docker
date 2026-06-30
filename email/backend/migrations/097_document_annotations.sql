-- Document annotation system: positional pins with threaded comments on any document type

CREATE TABLE IF NOT EXISTS portal_document_annotations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    page_number INT NOT NULL DEFAULT 1 COMMENT '1-based page index (1 for single-page images)',
    x_percent DECIMAL(7,4) NOT NULL COMMENT 'Pin X position as % of page/image width',
    y_percent DECIMAL(7,4) NOT NULL COMMENT 'Pin Y position as % of page/image height',
    status ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    created_by_email VARCHAR(255) NOT NULL,
    created_by_name VARCHAR(255) DEFAULT NULL,
    created_by_type ENUM('internal', 'portal') NOT NULL DEFAULT 'internal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_document (document_id),
    INDEX idx_document_page (document_id, page_number),
    INDEX idx_status (document_id, status),
    FOREIGN KEY (document_id) REFERENCES portal_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_annotation_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    annotation_id INT UNSIGNED NOT NULL,
    parent_comment_id INT UNSIGNED DEFAULT NULL COMMENT 'For threaded replies',
    author_email VARCHAR(255) NOT NULL,
    author_name VARCHAR(255) DEFAULT NULL,
    author_type ENUM('internal', 'portal') NOT NULL DEFAULT 'internal',
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_annotation (annotation_id),
    INDEX idx_parent (parent_comment_id),
    FOREIGN KEY (annotation_id) REFERENCES portal_document_annotations(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES portal_annotation_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_annotation_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id INT UNSIGNED NOT NULL,
    filename VARCHAR(500) NOT NULL COMMENT 'Stored filename on disk',
    original_name VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    file_path VARCHAR(1000) DEFAULT NULL COMMENT 'Absolute path on disk (fallback)',
    drive_file_id INT UNSIGNED DEFAULT NULL COMMENT 'Drive file reference if uploaded to Drive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_comment (comment_id),
    FOREIGN KEY (comment_id) REFERENCES portal_annotation_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
