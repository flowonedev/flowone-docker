-- Add is_deleted flag to chat_participants for soft delete (user-level)
-- When a user deletes a conversation, it's hidden from them but not from other participants

ALTER TABLE chat_participants ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_archived;

