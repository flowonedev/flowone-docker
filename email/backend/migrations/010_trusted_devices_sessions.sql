-- Trusted Devices & Session Tracking
-- Migration: Add trusted devices for 2FA skip and session tracking across devices

-- Trusted devices for 2FA (remember device for 7 days)
CREATE TABLE IF NOT EXISTS webmail_2fa_trusted_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL COMMENT 'User email',
    device_token_hash VARCHAR(255) NOT NULL COMMENT 'Hashed device token for verification',
    device_name VARCHAR(255) DEFAULT NULL COMMENT 'User-friendly device name (derived from user agent)',
    user_agent TEXT COMMENT 'Full user agent string',
    ip_address VARCHAR(45) COMMENT 'IP address when device was trusted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL COMMENT 'When the trust expires (7 days default)',
    last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Last time this device was used to skip 2FA',
    
    INDEX idx_email (email),
    INDEX idx_expires (expires_at),
    INDEX idx_token_hash (device_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Active sessions tracking
CREATE TABLE IF NOT EXISTS webmail_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL COMMENT 'User email',
    session_token_hash VARCHAR(255) NOT NULL COMMENT 'Hashed session token',
    device_name VARCHAR(255) DEFAULT NULL COMMENT 'User-friendly device name',
    browser VARCHAR(100) DEFAULT NULL COMMENT 'Browser name',
    os VARCHAR(100) DEFAULT NULL COMMENT 'Operating system',
    user_agent TEXT COMMENT 'Full user agent string',
    ip_address VARCHAR(45) COMMENT 'IP address',
    location VARCHAR(255) DEFAULT NULL COMMENT 'Approximate location (city, country)',
    is_current TINYINT(1) DEFAULT 0 COMMENT 'Is this the current session',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL COMMENT 'Session expiry time',
    
    INDEX idx_email (email),
    INDEX idx_token_hash (session_token_hash),
    INDEX idx_expires (expires_at),
    INDEX idx_last_active (last_active_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

