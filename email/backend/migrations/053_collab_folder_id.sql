-- Migration: Add folder_id column to collab_documents
-- Links collab documents to their parent Drive folder for organization

ALTER TABLE collab_documents 
ADD COLUMN IF NOT EXISTS folder_id INT DEFAULT NULL COMMENT 'Optional Drive folder ID for organization',
ADD INDEX IF NOT EXISTS idx_collab_folder_id (folder_id);

