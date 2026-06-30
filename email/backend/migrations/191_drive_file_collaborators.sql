-- =====================================================
-- FILE-LEVEL SHARING (PEOPLE + GROUPS)
-- Share a single Drive file with specific colleagues or
-- colleague groups, with viewer/editor permission.
-- Mirrors drive_folder_collaborators / drive_folder_group_access.
-- =====================================================

-- Individual user access to a single file
CREATE TABLE IF NOT EXISTS drive_file_collaborators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL COMMENT 'Email of the collaborator',
    permission ENUM('viewer', 'editor') DEFAULT 'viewer' COMMENT 'viewer=open/download, editor=edit in office editor',
    invited_by VARCHAR(255) NOT NULL COMMENT 'Email of who shared the file',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_file_user (file_id, user_email),
    INDEX idx_user_email (user_email),
    INDEX idx_file_id (file_id),
    INDEX idx_invited_by (invited_by),
    FOREIGN KEY (file_id) REFERENCES drive_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colleague group access to a single file
CREATE TABLE IF NOT EXISTS drive_file_group_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    permission ENUM('viewer', 'editor') DEFAULT 'viewer',
    granted_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_file_group (file_id, group_id),
    INDEX idx_group (group_id),
    FOREIGN KEY (file_id) REFERENCES drive_files(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
