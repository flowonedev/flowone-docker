-- Migration: Drive Versioning and Trash System
-- Created: 2026-01-14
-- Description: Add file versioning, activity tracking, and soft-delete (trash) functionality

-- File versions table (stores all versions of a file)
CREATE TABLE IF NOT EXISTS drive_file_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    version_number INT NOT NULL DEFAULT 1,
    filename VARCHAR(255) NOT NULL COMMENT 'Stored filename (hashed)',
    size BIGINT NOT NULL DEFAULT 0,
    modified_by VARCHAR(255) NOT NULL COMMENT 'Email of who uploaded this version',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_file_id (file_id),
    INDEX idx_version_number (file_id, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add foreign key only if it doesn't exist (safe to run multiple times)
-- Using a procedure to handle this safely
DROP PROCEDURE IF EXISTS add_version_fk;
DELIMITER //
CREATE PROCEDURE add_version_fk()
BEGIN
    DECLARE fk_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO fk_exists FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_file_versions' 
    AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_version_file';
    
    IF fk_exists = 0 THEN
        SET @sql = 'ALTER TABLE drive_file_versions ADD CONSTRAINT fk_version_file FOREIGN KEY (file_id) REFERENCES drive_files(id) ON DELETE CASCADE';
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;
CALL add_version_fk();
DROP PROCEDURE IF EXISTS add_version_fk;

-- Procedure to safely add columns if they don't exist
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
DELIMITER //
CREATE PROCEDURE add_column_if_not_exists(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(255)
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
DROP PROCEDURE IF EXISTS add_index_if_not_exists;
DELIMITER //
CREATE PROCEDURE add_index_if_not_exists(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE idx_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO idx_exists 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = p_table 
    AND INDEX_NAME = p_index;
    
    IF idx_exists = 0 THEN
        SET @sql = CONCAT('CREATE INDEX ', p_index, ' ON ', p_table, '(', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Add columns to drive_files for versioning
CALL add_column_if_not_exists('drive_files', 'created_by', 'VARCHAR(255) DEFAULT NULL COMMENT "Email of original uploader"');
CALL add_column_if_not_exists('drive_files', 'last_modified_by', 'VARCHAR(255) DEFAULT NULL');
CALL add_column_if_not_exists('drive_files', 'current_version', 'INT DEFAULT 1');

-- Add columns for activity tracking
CALL add_column_if_not_exists('drive_files', 'last_opened_at', 'TIMESTAMP NULL');
CALL add_column_if_not_exists('drive_files', 'last_opened_by', 'VARCHAR(255) DEFAULT NULL');

-- Add columns for trash functionality
CALL add_column_if_not_exists('drive_files', 'is_trashed', 'TINYINT(1) DEFAULT 0');
CALL add_column_if_not_exists('drive_files', 'trashed_at', 'TIMESTAMP NULL');
CALL add_column_if_not_exists('drive_files', 'original_folder_id', 'INT DEFAULT NULL COMMENT "Folder before trashing"');

-- Add indexes for drive_files
CALL add_index_if_not_exists('drive_files', 'idx_is_trashed', 'is_trashed');
CALL add_index_if_not_exists('drive_files', 'idx_trashed_at', 'trashed_at');

-- Add columns to drive_folders for trash functionality
CALL add_column_if_not_exists('drive_folders', 'created_by', 'VARCHAR(255) DEFAULT NULL');
CALL add_column_if_not_exists('drive_folders', 'is_trashed', 'TINYINT(1) DEFAULT 0');
CALL add_column_if_not_exists('drive_folders', 'trashed_at', 'TIMESTAMP NULL');
CALL add_column_if_not_exists('drive_folders', 'original_parent_id', 'INT DEFAULT NULL COMMENT "Parent before trashing"');

-- Add indexes for drive_folders
CALL add_index_if_not_exists('drive_folders', 'idx_folder_is_trashed', 'is_trashed');
CALL add_index_if_not_exists('drive_folders', 'idx_folder_trashed_at', 'trashed_at');

-- Clean up helper procedures
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
DROP PROCEDURE IF EXISTS add_index_if_not_exists;
