-- Add part column to email attachments cache for accurate thumbnail fetching
-- The part is the MIME part identifier (e.g., "1", "1.1", "2") used by IMAP

ALTER TABLE webmail_email_attachments 
ADD COLUMN part VARCHAR(50) DEFAULT NULL AFTER filename;

-- Add index for lookups
ALTER TABLE webmail_email_attachments 
ADD INDEX idx_part (part);

