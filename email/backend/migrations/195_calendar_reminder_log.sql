-- 195_calendar_reminder_log.sql
-- Dedupe log for server-side calendar reminder pushes.
--
-- Keyed PER OCCURRENCE (occurrence_start), not per event: a recurring event
-- reuses one event_id across many occurrences, so dedupe must include the
-- specific occurrence instant. The UNIQUE key + INSERT-then-skip-on-duplicate
-- in process-calendar-reminders.php guarantees a reminder fires at most once
-- even if the cron runs twice.

CREATE TABLE IF NOT EXISTS calendar_reminder_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    occurrence_start DATETIME NOT NULL,
    minutes INT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reminder (event_id, user_email, occurrence_start, minutes),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
