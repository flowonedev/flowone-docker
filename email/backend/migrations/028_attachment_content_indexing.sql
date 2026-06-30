-- Add content indexing tracking to email attachments
-- Tracks whether attachment content has been extracted and indexed for full-text search

-- Add content_indexed column to track extraction status
ALTER TABLE webmail_email_attachments 
ADD COLUMN content_indexed TINYINT(1) DEFAULT 0 AFTER subject,
ADD COLUMN content_indexed_at DATETIME DEFAULT NULL AFTER content_indexed,
ADD COLUMN part VARCHAR(20) DEFAULT '1' AFTER uid;

-- Index for finding unindexed attachments efficiently
CREATE INDEX idx_content_indexed ON webmail_email_attachments(user_email, content_indexed, mime_type);

-- Index for chronological processing (newest first)
CREATE INDEX idx_indexing_queue ON webmail_email_attachments(content_indexed, message_date DESC);

