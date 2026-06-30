-- 194_native_push_tokens.sql
-- Native (FCM) push tokens for the Capacitor iOS/Android apps.
--
-- One row per (user, app, device): the FCM token rotates in place so a device
-- never accumulates stale tokens. last_seen_at is refreshed on every app
-- start/resume re-register; rows unseen for ~75 days are pruned by cron.
--
-- MySQL is the source of truth. The mailsync server reads a derived Redis cache
-- (fcm_tokens:{email}) that PushNotificationService rebuilds after every change.

CREATE TABLE IF NOT EXISTS native_push_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'ios',
    app_id VARCHAR(100) NOT NULL DEFAULT 'com.flowone.pro',
    device_id VARCHAR(191) NOT NULL,
    device_name VARCHAR(255) DEFAULT NULL,
    token VARCHAR(512) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_device (user_email, app_id, device_id),
    INDEX idx_user_email (user_email),
    INDEX idx_token (token(191)),
    INDEX idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
