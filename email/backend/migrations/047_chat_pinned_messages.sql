-- =====================================================
-- CHAT PINNED MESSAGES
-- Migration: 047_chat_pinned_messages.sql
--
-- Adds ability to pin messages within chat conversations
-- =====================================================

ALTER TABLE chat_messages
ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_edited,
ADD COLUMN pinned_at TIMESTAMP NULL AFTER is_pinned,
ADD COLUMN pinned_by INT UNSIGNED NULL AFTER pinned_at;

CREATE INDEX idx_pinned ON chat_messages (conversation_id, is_pinned, pinned_at DESC);

