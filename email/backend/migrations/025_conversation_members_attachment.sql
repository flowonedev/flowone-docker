-- Add has_attachment column to webmail_conversation_members
ALTER TABLE webmail_conversation_members 
ADD COLUMN IF NOT EXISTS has_attachment TINYINT(1) DEFAULT 0 AFTER message_date;

-- Create index for faster lookups
ALTER TABLE webmail_conversation_members 
ADD INDEX IF NOT EXISTS idx_has_attachment (user_email, has_attachment);

