-- Security Device Registry & Session Enhancement
-- Migration: Add device registry for remote wipe, device blocking, and stateful session validation

-- Device registry (tracks all devices that have logged in)
CREATE TABLE IF NOT EXISTS webmail_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL COMMENT 'User email',
    device_id VARCHAR(255) NOT NULL COMMENT 'Unique device fingerprint (machine ID + hash)',
    device_name VARCHAR(255) DEFAULT NULL COMMENT 'User-friendly device name',
    platform ENUM('web', 'desktop', 'drive') NOT NULL DEFAULT 'web' COMMENT 'App type',
    os VARCHAR(100) DEFAULT NULL COMMENT 'Operating system',
    app_version VARCHAR(50) DEFAULT NULL COMMENT 'App version',
    status ENUM('active', 'blocked', 'wipe_pending', 'wiped') NOT NULL DEFAULT 'active',
    last_ip VARCHAR(45) DEFAULT NULL COMMENT 'Last known IP address',
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Last activity from this device',
    wipe_requested_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When wipe was requested',
    wipe_confirmed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When device confirmed wipe complete',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_email_device (email, device_id),
    INDEX idx_email (email),
    INDEX idx_device_id (device_id),
    INDEX idx_status (status),
    INDEX idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add device_id and is_valid flag to sessions for stateful validation
ALTER TABLE webmail_sessions
    ADD COLUMN device_id VARCHAR(255) DEFAULT NULL COMMENT 'Links to webmail_devices.device_id' AFTER email,
    ADD COLUMN is_valid TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether session is still valid (for instant revocation)' AFTER is_current,
    ADD INDEX idx_device_id (device_id),
    ADD INDEX idx_is_valid (is_valid);

