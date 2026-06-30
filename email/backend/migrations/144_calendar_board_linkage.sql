-- Link calendar events to boards/cards for work session bridging
ALTER TABLE calendar_events
    ADD COLUMN IF NOT EXISTS board_id INT UNSIGNED DEFAULT NULL AFTER client_id,
    ADD COLUMN IF NOT EXISTS card_id INT UNSIGNED DEFAULT NULL AFTER board_id,
    ADD COLUMN IF NOT EXISTS time_bridged_at DATETIME DEFAULT NULL AFTER card_id;

-- Add 'calendar_event' to the source ENUM on projecthub_work_sessions
ALTER TABLE projecthub_work_sessions
    MODIFY COLUMN source ENUM(
        'manual','drive_edit','board_view','timer',
        'card_view','website_work','portal_call','calendar_event'
    ) DEFAULT 'manual';
