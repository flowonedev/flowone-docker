-- Add client_call activity type so portal call durations appear in time tracking

ALTER TABLE webmail_client_time_tracking
MODIFY COLUMN activity_type ENUM(
  'email_read','email_compose','calendar_event',
  'board_view','board_task',
  'drive_browse','document_open','document_edit',
  'website_work',
  'mood_board_view','mood_board_edit',
  'client_call'
) NOT NULL;
