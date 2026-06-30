-- =====================================================
-- CHAT VOICE MESSAGES
-- Migration: 044_chat_voice_messages.sql
--
-- Adds 'voice' content type for audio messages
-- and duration metadata column
-- =====================================================

-- Add 'voice', 'call', 'embed' to content_type ENUM
ALTER TABLE chat_messages 
  MODIFY COLUMN content_type ENUM('text', 'file', 'image', 'system', 'voice', 'call', 'embed') DEFAULT 'text';

-- Add voice_duration column (seconds, with decimal for precision)
ALTER TABLE chat_messages
  ADD COLUMN voice_duration DECIMAL(8,2) DEFAULT NULL COMMENT 'Duration in seconds for voice messages'
  AFTER attachments;

