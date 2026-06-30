-- Migration: 020_add_uidvalidity_to_folder_index.sql
-- Description: Add UIDVALIDITY tracking to folder index for proper cache invalidation
-- Also drops the webmail_folder_counts table as counts now come directly from IMAP

-- Add uidvalidity column to webmail_folder_index if it doesn't exist
ALTER TABLE webmail_folder_index 
ADD COLUMN IF NOT EXISTS uidvalidity INT NOT NULL DEFAULT 0 AFTER message_count;

-- Drop the folder counts table - counts now always come from IMAP
DROP TABLE IF EXISTS webmail_folder_counts;

