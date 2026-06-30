-- Migration: Enhanced Drive Sharing System
-- Created: 2026-01-14
-- Description: Add download limits, password protection, and user-specific folder sharing

-- =====================================================
-- Part 1: Enhanced Public Share Links
-- =====================================================

-- Add download limit and count columns to drive_files
DROP PROCEDURE IF EXISTS add_share_columns_files;
DELIMITER //
CREATE PROCEDURE add_share_columns_files()
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    
    -- max_downloads
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_files' AND COLUMN_NAME = 'max_downloads';
    IF col_exists = 0 THEN
        ALTER TABLE drive_files ADD COLUMN max_downloads INT DEFAULT NULL COMMENT 'Maximum allowed downloads, NULL = unlimited';
    END IF;
    
    -- download_count
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_files' AND COLUMN_NAME = 'download_count';
    IF col_exists = 0 THEN
        ALTER TABLE drive_files ADD COLUMN download_count INT DEFAULT 0 COMMENT 'Current download count';
    END IF;
    
    -- share_password
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_files' AND COLUMN_NAME = 'share_password';
    IF col_exists = 0 THEN
        ALTER TABLE drive_files ADD COLUMN share_password VARCHAR(255) DEFAULT NULL COMMENT 'Hashed password for protected shares';
    END IF;
END //
DELIMITER ;
CALL add_share_columns_files();
DROP PROCEDURE IF EXISTS add_share_columns_files;

-- Add download limit and count columns to drive_folders
DROP PROCEDURE IF EXISTS add_share_columns_folders;
DELIMITER //
CREATE PROCEDURE add_share_columns_folders()
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    
    -- max_downloads
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_folders' AND COLUMN_NAME = 'max_downloads';
    IF col_exists = 0 THEN
        ALTER TABLE drive_folders ADD COLUMN max_downloads INT DEFAULT NULL COMMENT 'Maximum allowed downloads, NULL = unlimited';
    END IF;
    
    -- download_count
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_folders' AND COLUMN_NAME = 'download_count';
    IF col_exists = 0 THEN
        ALTER TABLE drive_folders ADD COLUMN download_count INT DEFAULT 0 COMMENT 'Current download count';
    END IF;
    
    -- share_password
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_folders' AND COLUMN_NAME = 'share_password';
    IF col_exists = 0 THEN
        ALTER TABLE drive_folders ADD COLUMN share_password VARCHAR(255) DEFAULT NULL COMMENT 'Hashed password for protected shares';
    END IF;
END //
DELIMITER ;
CALL add_share_columns_folders();
DROP PROCEDURE IF EXISTS add_share_columns_folders;

-- =====================================================
-- Part 2: User-Specific Folder Sharing (Collaborators)
-- =====================================================

-- Create drive_folder_collaborators table
CREATE TABLE IF NOT EXISTS drive_folder_collaborators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folder_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL COMMENT 'Email of the collaborator',
    permission ENUM('viewer', 'editor') DEFAULT 'viewer' COMMENT 'viewer=read/download, editor=upload/delete',
    invited_by VARCHAR(255) NOT NULL COMMENT 'Email of who shared the folder',
    accepted_at TIMESTAMP NULL COMMENT 'When the invite was accepted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_folder_user (folder_id, user_email),
    INDEX idx_user_email (user_email),
    INDEX idx_folder_id (folder_id),
    INDEX idx_invited_by (invited_by),
    FOREIGN KEY (folder_id) REFERENCES drive_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Part 3: Board Integration - Drive Folder Linking
-- =====================================================

-- Add drive folder columns to webmail_board_members
DROP PROCEDURE IF EXISTS add_board_drive_columns;
DELIMITER //
CREATE PROCEDURE add_board_drive_columns()
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    
    -- drive_folder_id - which folder this member can access
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webmail_board_members' AND COLUMN_NAME = 'drive_folder_id';
    IF col_exists = 0 THEN
        ALTER TABLE webmail_board_members ADD COLUMN drive_folder_id INT DEFAULT NULL COMMENT 'Linked Drive folder for this member';
    END IF;
    
    -- drive_permission - viewer or editor
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webmail_board_members' AND COLUMN_NAME = 'drive_permission';
    IF col_exists = 0 THEN
        ALTER TABLE webmail_board_members ADD COLUMN drive_permission ENUM('viewer', 'editor') DEFAULT 'viewer' COMMENT 'Drive access permission level';
    END IF;
END //
DELIMITER ;
CALL add_board_drive_columns();
DROP PROCEDURE IF EXISTS add_board_drive_columns;

-- Add board_id to drive_folders for board-linked folders
DROP PROCEDURE IF EXISTS add_folder_board_link;
DELIMITER //
CREATE PROCEDURE add_folder_board_link()
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_folders' AND COLUMN_NAME = 'board_id';
    IF col_exists = 0 THEN
        ALTER TABLE drive_folders ADD COLUMN board_id INT DEFAULT NULL COMMENT 'Linked board ID if this is a board folder';
        ALTER TABLE drive_folders ADD INDEX idx_board_id (board_id);
    END IF;
END //
DELIMITER ;
CALL add_folder_board_link();
DROP PROCEDURE IF EXISTS add_folder_board_link;

