-- =====================================================
-- CHAT CHANNELS SUPPORT
-- Migration: 049_chat_channels.sql
--
-- Extends the group chat system with public/private channels.
-- Channels are discoverable, joinable topic-based conversations.
-- =====================================================

-- Add channel type and channel-specific columns to chat_conversations
ALTER TABLE chat_conversations
  MODIFY COLUMN type ENUM('direct','group','channel') NOT NULL DEFAULT 'direct';

-- Add channel-specific columns (safe: only adds if not exist)
-- Using separate ALTER statements for self-healing compatibility

ALTER TABLE chat_conversations
  ADD COLUMN is_public TINYINT(1) DEFAULT 1 COMMENT 'Public channels browsable by all org members';

ALTER TABLE chat_conversations
  ADD COLUMN slug VARCHAR(100) DEFAULT NULL COMMENT 'Unique #channel-name identifier';

ALTER TABLE chat_conversations
  ADD COLUMN topic VARCHAR(500) DEFAULT NULL COMMENT 'Channel topic shown in header';

ALTER TABLE chat_conversations
  ADD COLUMN purpose TEXT DEFAULT NULL COMMENT 'Channel purpose/description (longer)';

ALTER TABLE chat_conversations
  ADD COLUMN is_default TINYINT(1) DEFAULT 0 COMMENT 'Auto-join for new org members';

-- Unique slug per organization
CREATE UNIQUE INDEX idx_channel_slug ON chat_conversations (organization_domain, slug);

