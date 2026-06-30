-- Add last_verified_at column for reconciliation tracking
-- Migration: Add timestamp tracking for when each conversation member was last verified against IMAP
-- 
-- This enables:
--   - Confidence scoring per message (recently verified = higher confidence)
--   - Efficient reconciliation (skip recently verified messages)
--   - Observability (detect drifting/stale data)
--   - Orphan cleanup (unverified records can be removed by cron)

ALTER TABLE webmail_conversation_members 
ADD COLUMN last_verified_at TIMESTAMP NULL DEFAULT NULL 
COMMENT 'Last time this record was verified to exist in IMAP';

-- Add index for efficient reconciliation queries (find unverified/stale records)
ALTER TABLE webmail_conversation_members 
ADD INDEX idx_verified (user_email, last_verified_at);

-- Also add message_id_hash column if not exists (for faster lookups)
-- This may already exist from a previous migration
ALTER TABLE webmail_conversation_members 
ADD COLUMN IF NOT EXISTS message_id_hash CHAR(32) DEFAULT NULL 
COMMENT 'MD5 hash of message_id for faster unique lookups';

ALTER TABLE webmail_conversation_members 
ADD INDEX IF NOT EXISTS idx_msg_hash (user_email, message_id_hash);

