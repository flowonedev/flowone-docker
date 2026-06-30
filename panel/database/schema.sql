-- VPS Admin Panel Database Schema
-- MySQL 5.7+ / MariaDB 10.2+

-- Create database
CREATE DATABASE IF NOT EXISTS vpsadmin
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE vpsadmin;

-- =====================================================
-- Admin Users Table
-- =====================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin', 'user') DEFAULT 'user',
    email VARCHAR(255),
    status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- User-Site Associations (which sites a user can access)
-- =====================================================
CREATE TABLE IF NOT EXISTS user_sites (
    user_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, domain),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Sessions Table
-- =====================================================
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(64) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Audit Logs Table
-- =====================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    source_app VARCHAR(50) NOT NULL DEFAULT 'panel',
    severity ENUM('critical','high','medium','low','info') NOT NULL DEFAULT 'info',
    action VARCHAR(100) NOT NULL,
    actor VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_email VARCHAR(255) NULL,
    target VARCHAR(255),
    details JSON,
    backup_path VARCHAR(500),
    diff MEDIUMTEXT,
    outcome ENUM('success', 'failed', 'rollback') NOT NULL DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_source_app (source_app),
    INDEX idx_severity (severity),
    INDEX idx_action (action),
    INDEX idx_actor (actor),
    INDEX idx_target (target),
    INDEX idx_outcome (outcome),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Hosting Clients (may or may not have panel login)
-- =====================================================
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    company VARCHAR(255),
    address TEXT,
    notes TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Client Domains (link clients to their sites)
-- =====================================================
CREATE TABLE IF NOT EXISTS client_domains (
    client_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    PRIMARY KEY (client_id, domain),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Client Subscriptions (billing plans)
-- =====================================================
CREATE TABLE IF NOT EXISTS client_subscriptions (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    plan_name VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'HUF',
    billing_cycle ENUM('monthly', 'yearly') DEFAULT 'yearly',
    start_date DATE NOT NULL,
    next_due_date DATE NOT NULL,
    status ENUM('active', 'cancelled', 'expired') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_next_due (next_due_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Payment History
-- =====================================================
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    subscription_id INT UNSIGNED,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'HUF',
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50),
    transaction_ref VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (subscription_id) REFERENCES client_subscriptions(id) ON DELETE SET NULL,
    INDEX idx_client (client_id),
    INDEX idx_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Billing Email Log
-- =====================================================
CREATE TABLE IF NOT EXISTS billing_emails (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    subscription_id INT UNSIGNED,
    email_type ENUM('reminder_30', 'reminder_7', 'overdue', 'receipt') NOT NULL,
    sent_to VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Billing Settings
-- =====================================================
CREATE TABLE IF NOT EXISTS billing_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Insert Default Admin User
-- Default password: admin (change immediately!)
-- =====================================================
INSERT INTO admin_users (username, password_hash, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin')
ON DUPLICATE KEY UPDATE username = username;

-- =====================================================
-- Insert Default Billing Settings
-- =====================================================
INSERT INTO billing_settings (setting_key, setting_value) VALUES
('reminder_days', '30'),
('admin_email', ''),
('email_from_name', 'VPS Admin'),
('email_from_address', ''),
('currency_default', 'HUF')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- =====================================================
-- Cleanup Procedures
-- =====================================================

-- Procedure to clean expired sessions
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_expired_sessions()
BEGIN
    DELETE FROM sessions WHERE expires_at < NOW();
END //
DELIMITER ;

-- Procedure to clean old audit logs (keep 90 days by default)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_old_audit_logs(IN days_to_keep INT)
BEGIN
    IF days_to_keep IS NULL THEN
        SET days_to_keep = 90;
    END IF;
    
    DELETE FROM audit_logs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
END //
DELIMITER ;

-- =====================================================
-- Events for automatic cleanup
-- =====================================================

-- Enable event scheduler (run once manually if needed)
-- SET GLOBAL event_scheduler = ON;

-- Create event to clean expired sessions daily
DELIMITER //
CREATE EVENT IF NOT EXISTS evt_cleanup_sessions
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_DATE + INTERVAL 3 HOUR
DO
BEGIN
    CALL cleanup_expired_sessions();
END //
DELIMITER ;

-- Create event to clean old audit logs weekly
DELIMITER //
CREATE EVENT IF NOT EXISTS evt_cleanup_audit_logs
ON SCHEDULE EVERY 1 WEEK
STARTS CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 4 HOUR
DO
BEGIN
    CALL cleanup_old_audit_logs(90);
END //
DELIMITER ;

-- =====================================================
-- AI Helper Conversations
-- =====================================================
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AI Helper Messages
-- =====================================================
CREATE TABLE IF NOT EXISTS ai_messages (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    role ENUM('user', 'assistant', 'system') NOT NULL,
    content TEXT NOT NULL,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AI Helper Cached Issues
-- =====================================================
CREATE TABLE IF NOT EXISTS ai_cached_issues (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    issue_type VARCHAR(100) NOT NULL,
    service VARCHAR(50),
    issue_key VARCHAR(255) NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    description TEXT,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    metadata JSON,
    UNIQUE KEY unique_issue (issue_type, service, issue_key),
    INDEX idx_service (service),
    INDEX idx_severity (severity),
    INDEX idx_resolved (resolved_at),
    INDEX idx_detected_at (detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AI Helper Settings
-- =====================================================
CREATE TABLE IF NOT EXISTS ai_helper_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default AI Helper settings
INSERT INTO ai_helper_settings (setting_key, setting_value) VALUES
('openai_api_key', ''),
('openai_model', 'gpt-4o'),
('max_tokens', '2000'),
('temperature', '0.3')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

