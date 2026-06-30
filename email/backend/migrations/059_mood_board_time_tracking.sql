-- Add mood_board_view and mood_board_edit to the activity_type ENUM in client time tracking
-- This allows tracking time spent on mood boards per client

ALTER TABLE webmail_client_time_tracking
    MODIFY COLUMN activity_type ENUM(
        'email_read', 'email_compose', 'calendar_event',
        'board_view', 'board_task', 'drive_browse',
        'document_open', 'document_edit', 'website_work',
        'mood_board_view', 'mood_board_edit'
    ) NOT NULL;

