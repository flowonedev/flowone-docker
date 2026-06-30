-- Migration 132: Project Hub Notification Preferences
-- Per-user toggles for each PH notification type (in-app, push, email)

CREATE TABLE IF NOT EXISTS projecthub_notification_prefs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    notif_type VARCHAR(50) NOT NULL,
    channel_inapp TINYINT(1) NOT NULL DEFAULT 1,
    channel_push TINYINT(1) NOT NULL DEFAULT 1,
    channel_email TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_type (user_email, notif_type),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
