-- Migration 046: Add meeting support columns to calendar_events
-- Adds meeting_link (public URL token), meeting_conversation_id (linked chat), and is_meeting flag

ALTER TABLE calendar_events
    ADD COLUMN is_meeting TINYINT(1) NOT NULL DEFAULT 0 AFTER color,
    ADD COLUMN meeting_token VARCHAR(64) DEFAULT NULL AFTER is_meeting,
    ADD COLUMN meeting_conversation_id INT DEFAULT NULL AFTER meeting_token;

-- Index for fast meeting token lookups (public join links)
CREATE UNIQUE INDEX idx_meeting_token ON calendar_events (meeting_token);

-- Index for finding meetings linked to a conversation
CREATE INDEX idx_meeting_conversation ON calendar_events (meeting_conversation_id);

