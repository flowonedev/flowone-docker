-- =====================================================
-- MAILING LISTS SYSTEM
-- External contact lists for email campaigns
-- Similar to colleague groups but for external contacts
-- =====================================================

-- Mailing lists (groups of external contacts)
CREATE TABLE IF NOT EXISTS mailing_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL COMMENT 'Owner of the mailing list',
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    color VARCHAR(20) DEFAULT '#6366f1' COMMENT 'Badge color',
    icon VARCHAR(50) DEFAULT 'mail' COMMENT 'Material Symbol icon',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_name (user_email, name),
    INDEX idx_user (user_email),
    INDEX idx_sort (user_email, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mailing list contacts (external emails)
CREATE TABLE IF NOT EXISTS mailing_list_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    position VARCHAR(255) DEFAULT NULL COMMENT 'Job title/position',
    company VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_list_email (list_id, email),
    INDEX idx_email (email),
    INDEX idx_name (name),
    FOREIGN KEY (list_id) REFERENCES mailing_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import history for tracking Excel imports
CREATE TABLE IF NOT EXISTS mailing_list_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    filename VARCHAR(255) DEFAULT NULL,
    total_rows INT DEFAULT 0,
    imported_count INT DEFAULT 0,
    skipped_count INT DEFAULT 0,
    error_count INT DEFAULT 0,
    errors JSON DEFAULT NULL COMMENT 'Details of import errors',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_list (list_id),
    FOREIGN KEY (list_id) REFERENCES mailing_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

