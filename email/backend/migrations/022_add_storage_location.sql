-- Add storage_location column to track where files are stored (local or nas)
ALTER TABLE drive_files ADD COLUMN storage_location VARCHAR(20) DEFAULT 'local' COMMENT 'Where file is stored: local, nas';

-- Update existing files to be marked as local
UPDATE drive_files SET storage_location = 'local' WHERE storage_location IS NULL;

