-- =============================================================================
-- Collab System - Initial Database Schema
-- =============================================================================
-- 
-- Migration 014: Collaborative editing tables for Documents & Presentations
-- All tables use the collab_ prefix for isolation and easier debugging
-- 
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Table: collab_documents
-- Main documents table storing both documents and presentations
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collab_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'Public document identifier',
    owner_email VARCHAR(255) NOT NULL COMMENT 'Email of document creator/owner',
    title VARCHAR(500) NOT NULL DEFAULT 'Untitled Document' COMMENT 'Document title',
    type ENUM('document', 'presentation') NOT NULL COMMENT 'Document type',
    crdt_state LONGBLOB NULL COMMENT 'Y.js encoded CRDT state (binary)',
    snapshot_html LONGTEXT NULL COMMENT 'Last rendered HTML snapshot for preview/search',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL COMMENT 'Soft delete timestamp',
    
    INDEX collab_idx_owner (owner_email),
    INDEX collab_idx_type (type),
    INDEX collab_idx_uuid (uuid),
    INDEX collab_idx_deleted (deleted_at),
    INDEX collab_idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: collab_slides
-- Slides within presentations (only for type='presentation')
-- Slides store their own CRDT state for large presentations
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collab_slides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL COMMENT 'Parent presentation ID',
    slide_index INT NOT NULL DEFAULT 0 COMMENT 'Order within presentation (0-based)',
    crdt_state LONGBLOB NULL COMMENT 'Y.js state for this slide (optional, for sharding)',
    thumbnail_url VARCHAR(500) NULL COMMENT 'URL to slide thumbnail image',
    thumbnail_generated_at TIMESTAMP NULL COMMENT 'When thumbnail was last generated',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX collab_idx_slides_document (document_id),
    UNIQUE KEY collab_uk_doc_index (document_id, slide_index),
    CONSTRAINT collab_fk_slide_doc FOREIGN KEY (document_id) 
        REFERENCES collab_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: collab_permissions
-- Access control for documents (owner, editor, viewer roles)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collab_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL COMMENT 'Document being shared',
    user_email VARCHAR(255) NOT NULL COMMENT 'User granted access',
    role ENUM('owner', 'editor', 'viewer') NOT NULL DEFAULT 'viewer' COMMENT 'Permission level',
    invited_by VARCHAR(255) NULL COMMENT 'Email of user who invited',
    accepted_at TIMESTAMP NULL COMMENT 'When invitation was accepted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY collab_uk_doc_user (document_id, user_email),
    INDEX collab_idx_perm_user (user_email),
    INDEX collab_idx_perm_role (role),
    CONSTRAINT collab_fk_perm_doc FOREIGN KEY (document_id) 
        REFERENCES collab_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: collab_sessions
-- Active editing sessions for presence tracking
-- Sessions expire after 5 minutes of inactivity
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collab_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL COMMENT 'Document being edited',
    user_email VARCHAR(255) NOT NULL COMMENT 'User email',
    connection_id VARCHAR(100) NOT NULL COMMENT 'WebSocket connection identifier',
    cursor_position JSON NULL COMMENT 'Current cursor position/selection',
    color VARCHAR(7) NOT NULL COMMENT 'Hex color for cursor/avatar',
    user_name VARCHAR(255) NULL COMMENT 'Display name for presence',
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX collab_idx_sess_document (document_id),
    INDEX collab_idx_sess_user (user_email),
    INDEX collab_idx_sess_last_seen (last_seen),
    UNIQUE KEY collab_uk_sess_conn (connection_id),
    CONSTRAINT collab_fk_sess_doc FOREIGN KEY (document_id) 
        REFERENCES collab_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: collab_versions
-- Version history / named snapshots
-- Stores CRDT state snapshots for restore functionality
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collab_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL COMMENT 'Document ID',
    version_number INT NOT NULL COMMENT 'Sequential version number',
    version_name VARCHAR(255) NULL COMMENT 'Optional user-given name',
    crdt_state LONGBLOB NOT NULL COMMENT 'Y.js encoded state at this version',
    snapshot_html LONGTEXT NULL COMMENT 'HTML snapshot for preview',
    created_by VARCHAR(255) NOT NULL COMMENT 'Email of user who created version',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY collab_uk_doc_version (document_id, version_number),
    INDEX collab_idx_ver_created (created_at),
    CONSTRAINT collab_fk_ver_doc FOREIGN KEY (document_id) 
        REFERENCES collab_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: collab_comments
-- Comments and annotations on documents
-- Supports threaded replies and resolution
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collab_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL COMMENT 'Document ID',
    thread_id CHAR(36) NOT NULL COMMENT 'Groups replies into threads',
    parent_id INT NULL COMMENT 'For nested replies',
    user_email VARCHAR(255) NOT NULL COMMENT 'Comment author',
    content TEXT NOT NULL COMMENT 'Comment text (can include @mentions)',
    selection_anchor JSON NULL COMMENT 'Y.js relative position for anchoring',
    resolved_at TIMESTAMP NULL COMMENT 'When thread was resolved',
    resolved_by VARCHAR(255) NULL COMMENT 'Who resolved the thread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL COMMENT 'Soft delete timestamp',
    
    INDEX collab_idx_comment_document (document_id),
    INDEX collab_idx_comment_thread (thread_id),
    INDEX collab_idx_comment_parent (parent_id),
    INDEX collab_idx_comment_resolved (resolved_at),
    CONSTRAINT collab_fk_comment_doc FOREIGN KEY (document_id) 
        REFERENCES collab_documents(id) ON DELETE CASCADE,
    CONSTRAINT collab_fk_comment_parent FOREIGN KEY (parent_id) 
        REFERENCES collab_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: collab_activity_log
-- Audit log for document changes (optional, for analytics)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collab_activity_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL COMMENT 'Document ID',
    user_email VARCHAR(255) NOT NULL COMMENT 'User who performed action',
    action ENUM(
        'created',
        'viewed', 
        'edited',
        'shared',
        'unshared',
        'restored',
        'deleted',
        'commented',
        'resolved_comment'
    ) NOT NULL COMMENT 'Action type',
    details JSON NULL COMMENT 'Additional action details',
    ip_address VARCHAR(45) NULL COMMENT 'Client IP address',
    user_agent VARCHAR(500) NULL COMMENT 'Client user agent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX collab_idx_activity_document (document_id),
    INDEX collab_idx_activity_user (user_email),
    INDEX collab_idx_activity_action (action),
    INDEX collab_idx_activity_created (created_at),
    CONSTRAINT collab_fk_activity_doc FOREIGN KEY (document_id) 
        REFERENCES collab_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Stored Procedures for common operations
-- -----------------------------------------------------------------------------

-- Cleanup expired sessions (older than 5 minutes)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS collab_cleanup_expired_sessions()
BEGIN
    DELETE FROM collab_sessions 
    WHERE last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE);
END //
DELIMITER ;

-- Create a scheduled event to cleanup sessions every minute
-- Note: Event scheduler must be enabled: SET GLOBAL event_scheduler = ON;
CREATE EVENT IF NOT EXISTS collab_session_cleanup_event
ON SCHEDULE EVERY 1 MINUTE
DO CALL collab_cleanup_expired_sessions();

-- -----------------------------------------------------------------------------
-- Indexes for full-text search (optional, for document search feature)
-- -----------------------------------------------------------------------------

-- Add full-text index on document title and snapshot_html
ALTER TABLE collab_documents 
ADD FULLTEXT INDEX collab_ft_search (title, snapshot_html);

-- Add full-text index on comments
ALTER TABLE collab_comments
ADD FULLTEXT INDEX collab_ft_comments (content);

