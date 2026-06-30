-- 171_calendar_push_channels.sql
-- Phase 3.6: track Google Calendar push notification channels.
-- One row per active channels.watch subscription. The webhook receiver
-- looks up sync_state_id by channel_id (X-Goog-Channel-ID header) and
-- validates the token HMAC stored here before triggering a sync.
-- A separate cron renews channels expiring within 6 hours.

CREATE TABLE IF NOT EXISTS calendar_push_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id VARCHAR(255) NOT NULL,
    resource_id VARCHAR(255) NOT NULL,
    calendar_sync_state_id INT NOT NULL,
    token_hmac VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_channel (channel_id),
    INDEX idx_sync_state (calendar_sync_state_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
