-- Client Portal Tables
-- Migration: All portal_ tables for the CRM Pro addon (client portal auth, updates, documents, calls)
-- These tables are always created (even when addon is disabled) so data persists across toggle cycles.

-- =========================================================================
-- Phase 1: Portal Core (Auth + Sessions)
-- =========================================================================

-- Which client contacts have portal access
CREATE TABLE IF NOT EXISTS portal_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED DEFAULT NULL COMMENT 'Links to client_contacts.id',
    email VARCHAR(255) NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    session_count INT DEFAULT 0,
    created_by VARCHAR(255) NOT NULL COMMENT 'Internal user who granted access',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_client_email (client_id, email),
    INDEX idx_client (client_id),
    INDEX idx_email (email),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use magic link tokens
CREATE TABLE IF NOT EXISTS portal_magic_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    portal_access_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL COMMENT '24 hours from creation',
    used_at DATETIME DEFAULT NULL COMMENT 'NULL until consumed',
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_portal_access (portal_access_id),
    INDEX idx_token (token),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (portal_access_id) REFERENCES portal_access(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Portal sessions (independent from internal JWT system)
CREATE TABLE IF NOT EXISTS portal_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    portal_access_id INT UNSIGNED NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    user_agent VARCHAR(500) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    expires_at DATETIME NOT NULL COMMENT '30 days',
    last_active_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_portal_access (portal_access_id),
    INDEX idx_session_token (session_token),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (portal_access_id) REFERENCES portal_access(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Phase 2: Updates & Comments
-- =========================================================================

-- Updates pushed to clients through the portal
CREATE TABLE IF NOT EXISTS portal_updates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    created_by VARCHAR(255) NOT NULL COMMENT 'Internal user who created the update',
    title VARCHAR(500) NOT NULL,
    content_html TEXT DEFAULT NULL,
    content_text TEXT DEFAULT NULL,
    update_type ENUM('general', 'design', 'milestone', 'deliverable') DEFAULT 'general',
    mood_board_id INT DEFAULT NULL COMMENT 'Optional linked mood board',
    mood_board_share_token VARCHAR(64) DEFAULT NULL,
    drive_file_ids JSON DEFAULT NULL COMMENT 'Array of drive file IDs',
    board_id INT DEFAULT NULL,
    board_card_id INT DEFAULT NULL,
    is_pinned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_client (client_id),
    INDEX idx_created (created_at),
    INDEX idx_type (update_type),
    INDEX idx_pinned (is_pinned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track which portal users have read which updates
CREATE TABLE IF NOT EXISTS portal_update_reads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    update_id INT UNSIGNED NOT NULL,
    portal_access_id INT UNSIGNED NOT NULL,
    read_at DATETIME NOT NULL,

    UNIQUE KEY unique_read (update_id, portal_access_id),
    INDEX idx_update (update_id),
    INDEX idx_portal_access (portal_access_id),
    FOREIGN KEY (update_id) REFERENCES portal_updates(id) ON DELETE CASCADE,
    FOREIGN KEY (portal_access_id) REFERENCES portal_access(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comments on updates (both internal and portal users)
CREATE TABLE IF NOT EXISTS portal_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    update_id INT UNSIGNED NOT NULL,
    author_type ENUM('internal', 'portal') NOT NULL,
    author_email VARCHAR(255) NOT NULL,
    author_name VARCHAR(255) DEFAULT NULL,
    content_text TEXT NOT NULL,
    parent_comment_id INT UNSIGNED DEFAULT NULL COMMENT 'Threaded replies',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_update (update_id),
    INDEX idx_parent (parent_comment_id),
    INDEX idx_author (author_type, author_email),
    FOREIGN KEY (update_id) REFERENCES portal_updates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files attached to updates
CREATE TABLE IF NOT EXISTS portal_update_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    update_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL COMMENT 'Stored filename',
    original_name VARCHAR(500) NOT NULL COMMENT 'Original upload name',
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT 0,
    drive_file_id INT DEFAULT NULL COMMENT 'Optional link to Drive file',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_update (update_id),
    FOREIGN KEY (update_id) REFERENCES portal_updates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Phase 3: Documents & Signing
-- =========================================================================

-- Documents managed in the portal (contracts, invoices, NDAs, etc.)
CREATE TABLE IF NOT EXISTS portal_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT DEFAULT NULL,
    document_type ENUM('contract', 'invoice', 'proposal', 'quote', 'nda', 'agreement', 'receipt', 'other') NOT NULL,
    status ENUM('draft', 'sent', 'viewed', 'signing', 'signed', 'rejected', 'expired', 'archived') DEFAULT 'draft',
    -- File
    filename VARCHAR(255) NOT NULL COMMENT 'Stored filename',
    original_name VARCHAR(500) NOT NULL COMMENT 'Original upload name',
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT 0,
    file_path VARCHAR(1000) NOT NULL COMMENT 'Relative storage path',
    drive_file_id INT DEFAULT NULL COMMENT 'Optional link to Drive file',
    -- Signing config
    signing_method ENUM('upload', 'pad', 'both') DEFAULT 'both',
    requires_all_signers TINYINT(1) DEFAULT 1,
    signing_deadline DATE DEFAULT NULL,
    -- Financial
    amount DECIMAL(15,2) DEFAULT NULL,
    currency VARCHAR(3) DEFAULT 'HUF',
    -- Versioning
    reference_number VARCHAR(100) DEFAULT NULL,
    version INT DEFAULT 1,
    parent_document_id INT UNSIGNED DEFAULT NULL COMMENT 'Previous version of this document',
    -- Tracking
    viewed_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    reminder_sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_client (client_id),
    INDEX idx_status (status),
    INDEX idx_type (document_type),
    INDEX idx_deadline (signing_deadline),
    INDEX idx_parent (parent_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who needs to sign each document and their status
CREATE TABLE IF NOT EXISTS portal_document_signers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    portal_access_id INT UNSIGNED DEFAULT NULL,
    signer_email VARCHAR(255) NOT NULL,
    signer_name VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'signed', 'rejected') DEFAULT 'pending',
    signed_at DATETIME DEFAULT NULL,
    signature_type ENUM('upload', 'pad') DEFAULT NULL,
    uploaded_file_path VARCHAR(1000) DEFAULT NULL,
    uploaded_filename VARCHAR(500) DEFAULT NULL,
    signature_data TEXT DEFAULT NULL COMMENT 'Base64 PNG for pad signatures',
    signature_ip VARCHAR(45) DEFAULT NULL,
    signature_user_agent VARCHAR(500) DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    reminder_count INT DEFAULT 0,
    last_reminder_at DATETIME DEFAULT NULL,
    sign_order INT DEFAULT 0 COMMENT '0 = parallel, 1+ = sequential',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_doc_signer (document_id, signer_email),
    INDEX idx_document (document_id),
    INDEX idx_status (status),
    INDEX idx_portal_access (portal_access_id),
    FOREIGN KEY (document_id) REFERENCES portal_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full audit trail for every action on a document
CREATE TABLE IF NOT EXISTS portal_document_audit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    action ENUM('created', 'sent', 'viewed', 'downloaded', 'signed', 'rejected',
                'uploaded', 'reminder_sent', 'expired', 'archived', 'version_created') NOT NULL,
    actor_type ENUM('internal', 'portal', 'system') NOT NULL,
    actor_email VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    details JSON DEFAULT NULL COMMENT 'Additional action-specific data',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_document (document_id),
    INDEX idx_created (created_at),
    INDEX idx_action (action),
    FOREIGN KEY (document_id) REFERENCES portal_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Phase 4: Portal Calls
-- =========================================================================

-- Video/audio calls through the portal (using LiveKit)
CREATE TABLE IF NOT EXISTS portal_calls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    created_by VARCHAR(255) NOT NULL COMMENT 'Internal user who created the call',
    room_name VARCHAR(100) NOT NULL UNIQUE COMMENT 'LiveKit room name',
    call_type ENUM('instant', 'scheduled') DEFAULT 'instant',
    status ENUM('waiting', 'active', 'ended') DEFAULT 'waiting',
    scheduled_at DATETIME DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    duration_seconds INT DEFAULT 0,
    had_screen_share TINYINT(1) DEFAULT 0,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_client (client_id),
    INDEX idx_status (status),
    INDEX idx_scheduled (scheduled_at),
    INDEX idx_room (room_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

