-- Add linked email fields to calendar_events table
-- This allows calendar events to be linked to specific emails

ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS linked_message_id VARCHAR(512) DEFAULT NULL COMMENT 'Email message ID reference';
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS linked_email_subject VARCHAR(500) DEFAULT NULL COMMENT 'Cached email subject for display';
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS linked_email_sender VARCHAR(255) DEFAULT NULL COMMENT 'Cached sender email/name';
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS linked_email_folder VARCHAR(255) DEFAULT NULL COMMENT 'Folder where email lives';

ALTER TABLE calendar_events ADD INDEX IF NOT EXISTS idx_linked_message_id (linked_message_id(191));

