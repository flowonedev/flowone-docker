-- =====================================================
-- CHAT CONVERSATION SETTINGS
-- Migration: 036_chat_settings.sql
-- 
-- Add settings column for storing conversation settings
-- like background image, opacity, etc.
-- =====================================================

ALTER TABLE chat_conversations 
ADD COLUMN IF NOT EXISTS settings JSON DEFAULT NULL;

