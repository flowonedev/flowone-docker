-- Migration: Add drive_file_id to collab_documents table
-- This links collaborative documents to their source Drive files

ALTER TABLE collab_documents 
ADD COLUMN IF NOT EXISTS drive_file_id INT DEFAULT NULL,
ADD INDEX IF NOT EXISTS idx_drive_file_id (drive_file_id);

-- Optional: Add foreign key constraint (if drive_files table exists)
-- ALTER TABLE collab_documents 
-- ADD CONSTRAINT fk_collab_drive_file 
-- FOREIGN KEY (drive_file_id) REFERENCES drive_files(id) ON DELETE SET NULL;

