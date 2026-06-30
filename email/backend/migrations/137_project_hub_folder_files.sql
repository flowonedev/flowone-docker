-- Migration 137: Project Hub Folder Files & Links
-- Adds folder-level file management (Drive-backed), link collections, and unseen tracking

-- Links Drive files to PH folders with grouping metadata
CREATE TABLE IF NOT EXISTS projecthub_folder_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    drive_file_id INT UNSIGNED NOT NULL,
    group_name VARCHAR(50) NOT NULL DEFAULT 'General',
    added_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_folder (folder_id),
    INDEX idx_drive_file (drive_file_id),
    INDEX idx_folder_group (folder_id, group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user "last seen" timestamps for unseen file/link tracking
CREATE TABLE IF NOT EXISTS projecthub_folder_file_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_folder_user (folder_id, user_email),
    INDEX idx_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Folder-level link collection (richer than bookmarks)
CREATE TABLE IF NOT EXISTS projecthub_folder_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    link_type VARCHAR(30) NOT NULL DEFAULT 'url',
    group_name VARCHAR(50) DEFAULT NULL,
    added_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
