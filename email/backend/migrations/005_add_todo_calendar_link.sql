-- Add calendar_event_id to webmail_todos to track linked calendar events
ALTER TABLE webmail_todos ADD COLUMN IF NOT EXISTS calendar_event_id INT DEFAULT NULL AFTER ref_selected_text;
ALTER TABLE webmail_todos ADD INDEX IF NOT EXISTS idx_calendar_event_id (calendar_event_id);

