-- 196_notification_preferences.sql
-- User-wide push notification preferences (Option A: one row per user, applies
-- to all of that user's devices). Per-device preferences are intentionally out
-- of scope.
--
-- Flat columns are simplest for v1, but the SENDER contract is type-keyed and
-- future-proof: PushNotificationService mirrors these into the Redis map
-- notif_prefs:{email} = {"email":1,"chat":1,...}. Adding a new notification type
-- later is a column + one map key, with no change to the mailsync sender. If
-- types ever proliferate, migrate to a child table (user_email, type, enabled)
-- with the same Redis map contract.

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_email VARCHAR(255) NOT NULL PRIMARY KEY,
    push_email TINYINT(1) NOT NULL DEFAULT 1,
    push_chat TINYINT(1) NOT NULL DEFAULT 1,
    push_calls TINYINT(1) NOT NULL DEFAULT 1,
    push_calendar TINYINT(1) NOT NULL DEFAULT 1,
    push_boards TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
