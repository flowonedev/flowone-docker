-- Migration 085: CRM Internal Sharing
-- Share CRM data (clients, deals, invoices, pipeline) with colleagues or groups

-- Individual colleague shares
CREATE TABLE IF NOT EXISTS crm_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_email VARCHAR(255) NOT NULL COMMENT 'The CRM owner sharing their data',
    shared_with_email VARCHAR(255) NOT NULL COMMENT 'Colleague who receives access',
    permission ENUM('viewer', 'editor', 'manager') DEFAULT 'viewer' COMMENT 'viewer=read-only, editor=can update, manager=full access',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_share (owner_email, shared_with_email),
    INDEX idx_owner (owner_email),
    INDEX idx_shared_with (shared_with_email),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group shares (references colleague_groups)
CREATE TABLE IF NOT EXISTS crm_group_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_email VARCHAR(255) NOT NULL COMMENT 'The CRM owner sharing their data',
    group_id INT UNSIGNED NOT NULL COMMENT 'References colleague_groups.id',
    permission ENUM('viewer', 'editor', 'manager') DEFAULT 'viewer',
    granted_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_group_share (owner_email, group_id),
    INDEX idx_owner (owner_email),
    INDEX idx_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log of share activity
CREATE TABLE IF NOT EXISTS crm_share_activity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_email VARCHAR(255) NOT NULL COMMENT 'Whose CRM was accessed',
    colleague_email VARCHAR(255) NOT NULL COMMENT 'Who performed the action',
    action VARCHAR(50) NOT NULL COMMENT 'viewed_client, edited_deal, added_note, etc.',
    target_type VARCHAR(50) DEFAULT NULL,
    target_id INT UNSIGNED DEFAULT NULL,
    detail TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_email, created_at),
    INDEX idx_colleague (colleague_email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

