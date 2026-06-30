-- Add thumbnail_filename column to mood_board_uploads for optimized image thumbnails
ALTER TABLE mood_board_uploads 
    ADD COLUMN thumbnail_filename VARCHAR(255) DEFAULT NULL COMMENT 'Generated thumbnail filename in /thumbs/ subdirectory' 
    AFTER file_size;

-- Add drive_file_id column if it doesn't exist (some installs may not have it)
-- This is a safe no-op if the column already exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mood_board_uploads' AND COLUMN_NAME = 'drive_file_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE mood_board_uploads ADD COLUMN drive_file_id INT DEFAULT NULL COMMENT ''Reference to drive_files if stored in Drive'' AFTER uploaded_by',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

