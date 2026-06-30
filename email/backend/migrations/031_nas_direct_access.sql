-- Migration: NAS Direct Access Support
-- Created: 2026-02-03
-- Description: Add support for desktop clients to access NAS directly
--              when on the same network, with server metadata synchronization

-- Procedure to safely add columns if they don't exist
DROP PROCEDURE IF EXISTS add_column_if_not_exists_031;
DELIMITER //
CREATE PROCEDURE add_column_if_not_exists_031(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(500)
)
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO col_exists 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = p_table 
    AND COLUMN_NAME = p_column;
    
    IF col_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_column, ' ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Procedure to safely add index if it doesn't exist
DROP PROCEDURE IF EXISTS add_index_if_not_exists_031;
DELIMITER //
CREATE PROCEDURE add_index_if_not_exists_031(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO idx_exists 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = p_index;
    
    IF idx_exists = 0 THEN
        SET @sql = CONCAT('CREATE INDEX ', p_index, ' ON ', p_table, '(', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Add NAS relative path to drive_files for direct access
-- This stores the path relative to NAS mount point (e.g., /user@domain.com/Projects/file.docx)
CALL add_column_if_not_exists_031('drive_files', 'nas_relative_path', 'VARCHAR(1000) DEFAULT NULL COMMENT "Path relative to NAS mount for direct access"');

-- Update storage_location to support pending_migration state
-- ALTER TABLE drive_files MODIFY storage_location VARCHAR(20) DEFAULT 'nas';
-- Note: Existing column already exists, we'll handle values in code

-- Add checksum column if not exists (for integrity verification)
CALL add_column_if_not_exists_031('drive_files', 'checksum', 'VARCHAR(64) DEFAULT NULL COMMENT "MD5 checksum for file integrity"');

-- Add NAS relative path to drive_folders
CALL add_column_if_not_exists_031('drive_folders', 'nas_relative_path', 'VARCHAR(1000) DEFAULT NULL COMMENT "Path relative to NAS mount for direct access"');

-- Table to track files that need migration from local server to NAS
-- Used when NAS was unavailable during upload, file stored locally, 
-- needs to be moved to NAS when it reconnects
CREATE TABLE IF NOT EXISTS drive_pending_nas_migration (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    local_path VARCHAR(1000) NOT NULL COMMENT 'Current path on server local storage',
    nas_target_path VARCHAR(1000) NOT NULL COMMENT 'Target path on NAS when migrated',
    user_email VARCHAR(255) NOT NULL COMMENT 'Owner of the file',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    migrated_at TIMESTAMP NULL COMMENT 'When migration completed',
    attempts INT DEFAULT 0 COMMENT 'Number of migration attempts',
    last_attempt_at TIMESTAMP NULL,
    status ENUM('pending', 'migrating', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,
    INDEX idx_pending_migration_status (status),
    INDEX idx_pending_migration_user (user_email),
    INDEX idx_pending_migration_file (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NAS configuration cache (synced from Panel, cached locally)
CREATE TABLE IF NOT EXISTS nas_connection_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nas_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default NAS configuration (will be overwritten by Panel sync)
INSERT INTO nas_connection_config (config_key, config_value) VALUES
    ('nas_enabled', 'true'),
    ('nas_ip', '192.168.1.106'),
    ('nas_smb_share', 'mailflow-drive'),
    ('nas_nfs_path', '/volume1/mailflow-drive'),
    ('direct_access_enabled', 'true')
ON DUPLICATE KEY UPDATE config_value = config_value;

-- Clean up helper procedures
DROP PROCEDURE IF EXISTS add_column_if_not_exists_031;
DROP PROCEDURE IF EXISTS add_index_if_not_exists_031;

