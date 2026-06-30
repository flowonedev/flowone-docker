-- =====================================================
-- SEARCH INDEX: Add chat_message & mood_board_item types
-- Migration: 070_search_index_chat_moodboard.sql
--
-- Extends universal search to include:
--   - Chat messages (DM, group, channel)
--   - MoodBoard items (notes, text, links, todos, etc.)
-- =====================================================

-- Extend the source_type ENUM to include new types
ALTER TABLE universal_search_index
MODIFY COLUMN source_type ENUM(
    'email', 'email_attachment', 'calendar_event',
    'drive_file', 'drive_folder', 'board', 'card',
    'checklist_item', 'todo', 'client', 'contact', 'collab_doc',
    'chat_message', 'mood_board_item'
) NOT NULL;

