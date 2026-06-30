ALTER TABLE scheduled_emails
  ADD COLUMN schedule_kind ENUM('scheduled_send', 'undo_send') NOT NULL DEFAULT 'scheduled_send'
  AFTER timezone;
