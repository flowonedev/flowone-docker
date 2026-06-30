-- Migration: Drive Share Restrictions + Open History
-- Created: 2026-06-23
-- Description: Per-file "no download" / "no print" restrictions that apply to
--              VIEW-only recipients, plus an access log for who/when/how-many-times
--              a file was opened (shown in the file Properties panel).

-- =====================================================
-- Part 1: View-only restriction flags on drive_files
-- =====================================================
DROP PROCEDURE IF EXISTS add_drive_restriction_columns;
DELIMITER //
CREATE PROCEDURE add_drive_restriction_columns()
BEGIN
    DECLARE col_exists INT DEFAULT 0;

    -- no_download: when set, recipients with VIEW access cannot download the file
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_files' AND COLUMN_NAME = 'no_download';
    IF col_exists = 0 THEN
        ALTER TABLE drive_files ADD COLUMN no_download TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'Block download for VIEW-access recipients (owner/editors unaffected)';
    END IF;

    -- no_print: when set, recipients with VIEW access cannot print the file
    SELECT COUNT(*) INTO col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_files' AND COLUMN_NAME = 'no_print';
    IF col_exists = 0 THEN
        ALTER TABLE drive_files ADD COLUMN no_print TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'Block printing for VIEW-access recipients (owner/editors unaffected)';
    END IF;
END //
DELIMITER ;
CALL add_drive_restriction_columns();
DROP PROCEDURE IF EXISTS add_drive_restriction_columns;

-- =====================================================
-- Part 2: File access log (who / when / how many times)
-- =====================================================
CREATE TABLE IF NOT EXISTS drive_file_access_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL COMMENT 'Who performed the action',
    action ENUM('open', 'download', 'print', 'download_blocked') NOT NULL DEFAULT 'open',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dfal_file (file_id),
    INDEX idx_dfal_file_user (file_id, user_email),
    INDEX idx_dfal_created (created_at),
    FOREIGN KEY (file_id) REFERENCES drive_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
