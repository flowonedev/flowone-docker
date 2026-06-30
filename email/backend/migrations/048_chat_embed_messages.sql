-- Migration 048: Add 'embed' content_type for rich content sharing in chat
-- Supports sharing: drive files/folders, calendar events, boards, board cards, todos
-- =====================================================

-- Add 'embed' to content_type ENUM
ALTER TABLE chat_messages 
  MODIFY COLUMN content_type ENUM('text', 'file', 'image', 'system', 'voice', 'call', 'embed') DEFAULT 'text';

