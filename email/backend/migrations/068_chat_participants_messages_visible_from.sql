-- =====================================================
-- CHAT PARTICIPANTS - Add messages_visible_from column
-- Migration: 068_chat_participants_messages_visible_from.sql
--
-- When a user archives a DM and later starts a new
-- conversation with the same person, the old conversation
-- is unarchived but messages before this timestamp are
-- hidden, giving the user a fresh-start experience.
-- =====================================================

ALTER TABLE chat_participants ADD COLUMN messages_visible_from DATETIME DEFAULT NULL AFTER is_archived;

