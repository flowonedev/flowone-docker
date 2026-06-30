-- Add missing sent_at column to portal_documents (idempotent)
-- The sendDocument endpoint references this column but it was missing from the original schema

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_documents' AND COLUMN_NAME = 'sent_at');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE portal_documents ADD COLUMN sent_at DATETIME DEFAULT NULL AFTER signing_deadline', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

