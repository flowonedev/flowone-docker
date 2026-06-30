-- =====================================================
-- COLLEAGUE SYSTEM FOR ORGANIZATION MANAGEMENT
-- Syncs with Dovecot/Postfix, supports groups & permissions
-- =====================================================

-- Organization colleagues (synced from Dovecot/Postfix)
CREATE TABLE IF NOT EXISTS organization_colleagues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_domain VARCHAR(255) NOT NULL COMMENT 'e.g., pixelranger.hu',
    email VARCHAR(255) NOT NULL COMMENT 'Full email address',
    display_name VARCHAR(255) DEFAULT NULL,
    avatar_path VARCHAR(500) DEFAULT NULL COMMENT 'Path to avatar in Drive storage',
    job_title VARCHAR(255) DEFAULT NULL,
    department VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    is_admin TINYINT(1) DEFAULT 0 COMMENT 'Can manage colleagues/groups',
    status ENUM('active', 'away', 'offline', 'do_not_disturb') DEFAULT 'active',
    last_seen_at TIMESTAMP NULL,
    profile_updated_at TIMESTAMP NULL,
    synced_from_mailserver TINYINT(1) DEFAULT 0 COMMENT 'Was auto-synced from Dovecot',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_email (email),
    INDEX idx_domain (organization_domain),
    INDEX idx_admin (organization_domain, is_admin),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colleague groups (teams, departments, working types)
CREATE TABLE IF NOT EXISTS colleague_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_domain VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    color VARCHAR(20) DEFAULT '#6366f1' COMMENT 'Badge color',
    icon VARCHAR(50) DEFAULT 'group' COMMENT 'Material Symbol icon',
    sort_order INT DEFAULT 0,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain_name (organization_domain, name),
    INDEX idx_domain (organization_domain),
    INDEX idx_sort (organization_domain, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colleague group memberships
CREATE TABLE IF NOT EXISTS colleague_group_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    colleague_id INT UNSIGNED NOT NULL,
    added_by VARCHAR(255) NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_group_colleague (group_id, colleague_id),
    INDEX idx_colleague (colleague_id),
    FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (colleague_id) REFERENCES organization_colleagues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group permissions for Drive folders
CREATE TABLE IF NOT EXISTS drive_folder_group_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    permission ENUM('viewer', 'editor') DEFAULT 'viewer',
    granted_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_folder_group (folder_id, group_id),
    INDEX idx_group (group_id),
    FOREIGN KEY (folder_id) REFERENCES drive_folders(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group permissions for Boards
CREATE TABLE IF NOT EXISTS board_group_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    can_edit TINYINT(1) DEFAULT 0,
    can_view_financials TINYINT(1) DEFAULT 0,
    granted_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_board_group (board_id, group_id),
    INDEX idx_group (group_id),
    FOREIGN KEY (board_id) REFERENCES webmail_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group permissions for Calendars
CREATE TABLE IF NOT EXISTS calendar_group_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    can_edit TINYINT(1) DEFAULT 0,
    can_see_details TINYINT(1) DEFAULT 1 COMMENT 'See event details vs free/busy only',
    granted_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_calendar_group (calendar_id, group_id),
    INDEX idx_group (group_id),
    FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profile update events (for real-time sync via WebSocket)
CREATE TABLE IF NOT EXISTS colleague_profile_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colleague_id INT UNSIGNED NOT NULL,
    organization_domain VARCHAR(255) NOT NULL,
    event_type ENUM('profile_updated', 'avatar_changed', 'status_changed', 'group_added', 'group_removed') NOT NULL,
    event_data JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_colleague (colleague_id),
    INDEX idx_domain_time (organization_domain, created_at),
    FOREIGN KEY (colleague_id) REFERENCES organization_colleagues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

