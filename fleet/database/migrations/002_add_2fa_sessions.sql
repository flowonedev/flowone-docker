-- Fleet Manager Migration: 2FA with Trusted Devices & Session Management
-- Run with: mysql -u fleet_devcon1_hu -p'YPuHHY$uMudaHEpH' fleet_devcon1_hu < 002_add_2fa_sessions.sql

-- =====================================================
-- Trusted Devices Table (skip 2FA for trusted devices)
-- =====================================================
CREATE TABLE IF NOT EXISTS trusted_devices (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    device_token_hash VARCHAR(64) NOT NULL,
    device_name VARCHAR(100),
    browser VARCHAR(50),
    os VARCHAR(50),
    user_agent TEXT,
    ip_address VARCHAR(45),
    expires_at TIMESTAMP NOT NULL,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_token (user_id, device_token_hash),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Add backup_codes to admin_users (if not exists)
-- =====================================================
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS backup_codes TEXT NULL AFTER totp_enabled;

-- =====================================================
-- Improve sessions table with device info
-- =====================================================
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS device_name VARCHAR(100) AFTER user_agent;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS browser VARCHAR(50) AFTER device_name;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS os VARCHAR(50) AFTER browser;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS last_active_at TIMESTAMP NULL AFTER expires_at;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS is_current TINYINT(1) DEFAULT 0 AFTER last_active_at;

-- =====================================================
-- Add success column to login_attempts if not exists
-- =====================================================
ALTER TABLE login_attempts ADD COLUMN IF NOT EXISTS success TINYINT(1) DEFAULT 0 AFTER username;

-- =====================================================
-- Done
-- =====================================================
SELECT 'Migration 002_add_2fa_sessions completed successfully!' as status;

