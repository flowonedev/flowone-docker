-- Migration 145: Watch Folders + Path Overrides
-- Full-path architecture: admin sets one path, works for entire office.
-- Remote workers use path overrides to remap prefixes.

CREATE TABLE IF NOT EXISTS watch_folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    folder_path VARCHAR(500) NOT NULL COMMENT 'Full canonical path e.g. Z:\\Clients\\BV Boros\\Design',
    client_id INT UNSIGNED NOT NULL,
    board_id INT UNSIGNED DEFAULT NULL,
    card_id INT UNSIGNED DEFAULT NULL,
    scope ENUM('shared') DEFAULT 'shared',
    assigned_emails JSON DEFAULT NULL COMMENT 'null = visible to all board members',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_creator (creator_email),
    INDEX idx_client (client_id),
    INDEX idx_board (board_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watch_folder_path_overrides (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    match_prefix VARCHAR(500) NOT NULL COMMENT 'Office prefix to match e.g. Z:\\',
    replace_prefix VARCHAR(500) NOT NULL COMMENT 'Local prefix e.g. \\\\192.168.1.106\\share',
    label VARCHAR(100) DEFAULT NULL COMMENT 'e.g. Home, VPN, Laptop',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_prefix (user_email, match_prefix),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watch_folder_activity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    watch_folder_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) DEFAULT NULL COMMENT 'Path relative to watch folder root',
    duration_seconds INT UNSIGNED DEFAULT 0,
    client_id INT UNSIGNED DEFAULT NULL,
    board_id INT UNSIGNED DEFAULT NULL,
    card_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_watch_folder (watch_folder_id),
    INDEX idx_card (card_id),
    INDEX idx_board (board_id),
    INDEX idx_user (user_email),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE webmail_client_time_tracking
    ADD COLUMN IF NOT EXISTS source ENUM('cloud', 'local_watch') DEFAULT 'cloud' AFTER entity_name;
