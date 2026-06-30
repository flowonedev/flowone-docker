-- Migration 183: calendar_invite_responses
--
-- Persists the user's RSVP choice (accepted/declined/tentative) for
-- incoming meeting invitations received via email (RFC 5545 iCalendar
-- VEVENT). Keyed by (user_email, ical_uid) so that reopening the same
-- invite later shows the previously chosen state in the UI without
-- re-sending the iCalendar REPLY.
--
-- Written by:
--   - MailboxController::rsvp() upserts on Accept / Maybe / Decline.
-- Read by:
--   - MailboxController::message() attaches the saved status as
--     calendar_event.my_response when serving the message.

CREATE TABLE IF NOT EXISTS calendar_invite_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email       VARCHAR(255) NOT NULL,
    ical_uid         VARCHAR(255) NOT NULL,
    organizer_email  VARCHAR(255) DEFAULT NULL,
    summary          VARCHAR(512) DEFAULT NULL,
    status           ENUM('accepted','declined','tentative') NOT NULL,
    responded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_email (user_email),
    INDEX idx_ical_uid (ical_uid(191)),

    UNIQUE KEY unique_user_uid (user_email, ical_uid(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
