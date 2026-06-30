-- DEVCON Fleet Manager Database Schema
-- MySQL 5.7+ / MariaDB 10.2+

CREATE DATABASE IF NOT EXISTS fleet_manager
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE fleet_manager;

-- =====================================================
-- Admin Users Table
-- =====================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    role ENUM('super_admin', 'admin') DEFAULT 'admin',
    status ENUM('active', 'suspended') DEFAULT 'active',
    totp_secret VARCHAR(255) NULL,
    totp_enabled TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_status (status)
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
-- Login Attempts (Rate Limiting)
-- =====================================================
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Blueprints (Server Templates)
-- =====================================================
CREATE TABLE IF NOT EXISTS blueprints (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    source_server VARCHAR(255),
    source_ip VARCHAR(45),
    version VARCHAR(20) DEFAULT '1.0.0',
    panel_version VARCHAR(20),
    email_app_version VARCHAR(20),
    is_default TINYINT(1) DEFAULT 0,
    variables JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Blueprint Templates (Config Files)
-- =====================================================
CREATE TABLE IF NOT EXISTS blueprint_templates (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    blueprint_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    target_path VARCHAR(500) NOT NULL,
    content LONGTEXT NOT NULL,
    permissions VARCHAR(10) DEFAULT '0644',
    owner VARCHAR(50) DEFAULT 'root',
    group_name VARCHAR(50) DEFAULT 'root',
    is_optional TINYINT(1) DEFAULT 0,
    requires_module VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (blueprint_id) REFERENCES blueprints(id) ON DELETE CASCADE,
    INDEX idx_blueprint (blueprint_id),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Managed Servers
-- =====================================================
CREATE TABLE IF NOT EXISTS servers (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    
    -- Connection
    ip_address VARCHAR(45) NOT NULL,
    ssh_port INT DEFAULT 22,
    ssh_auth_method ENUM('password', 'key') DEFAULT 'password',
    ssh_user VARCHAR(50) DEFAULT 'root',
    ssh_password_encrypted TEXT NULL,
    ssh_key_installed TINYINT(1) DEFAULT 0,
    ssh_authorized_key TEXT NULL,
    
    -- Domains
    panel_domain VARCHAR(255) NOT NULL,
    email_domain VARCHAR(255) NOT NULL,
    mail_domain VARCHAR(255),
    
    -- Blueprint
    blueprint_id INT UNSIGNED NULL,
    
    -- Agent
    agent_token VARCHAR(64) UNIQUE,
    agent_version VARCHAR(20),
    
    -- Versions
    panel_version VARCHAR(20),
    email_app_version VARCHAR(20),
    os_info VARCHAR(100),
    
    -- Status
    status ENUM('pending', 'provisioning', 'active', 'offline', 'error', 'maintenance') DEFAULT 'pending',
    provision_step VARCHAR(100),
    provision_progress INT DEFAULT 0,
    last_heartbeat DATETIME,
    last_error TEXT,
    
    -- Credentials (encrypted)
    db_root_password_encrypted TEXT,
    panel_db_password_encrypted TEXT,
    email_db_password_encrypted TEXT,
    mail_db_password_encrypted TEXT,
    panel_admin_email VARCHAR(255),
    panel_admin_password_encrypted TEXT,
    cpguard_license_key_encrypted TEXT,
    
    -- Optional modules
    vpn_enabled TINYINT(1) DEFAULT 0,
    nas_enabled TINYINT(1) DEFAULT 0,
    vpn_config_encrypted TEXT,
    nas_ip VARCHAR(45),
    nas_path VARCHAR(255),
    nas_mount VARCHAR(255) DEFAULT '/mnt/nas-drive',
    
    -- Meta
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (blueprint_id) REFERENCES blueprints(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_ip (ip_address),
    INDEX idx_panel_domain (panel_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Server Credentials (all passwords, keys, secrets)
-- =====================================================
CREATE TABLE IF NOT EXISTS server_credentials (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,
    credential_key VARCHAR(100) NOT NULL,
    label VARCHAR(100) NOT NULL,
    value_encrypted TEXT NOT NULL,
    is_secret TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE KEY uk_server_credential (server_id, credential_key),
    INDEX idx_server_id (server_id),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Deployment History
-- =====================================================
CREATE TABLE IF NOT EXISTS deployments (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    blueprint_id INT UNSIGNED NULL,
    type ENUM('full_provision', 'panel_update', 'email_update', 'agent_update', 'config_update', 'ssl_renew') NOT NULL,
    version VARCHAR(20),
    status ENUM('pending', 'running', 'success', 'failed', 'cancelled', 'rollback') DEFAULT 'pending',
    progress INT DEFAULT 0,
    current_step VARCHAR(100),
    total_steps INT DEFAULT 0,
    log LONGTEXT,
    error_message TEXT,
    started_at DATETIME,
    completed_at DATETIME,
    created_by INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (blueprint_id) REFERENCES blueprints(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_server (server_id),
    INDEX idx_status (status),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Server Health Snapshots
-- =====================================================
CREATE TABLE IF NOT EXISTS server_health (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    
    -- Services status
    openlitespeed_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    mariadb_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    postfix_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    dovecot_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    fail2ban_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    firewalld_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    fleet_agent_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    openvpn_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'disabled',
    redis_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    meilisearch_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    spamassassin_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    opendkim_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    opendmarc_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    clamav_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    pdns_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    coturn_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    livekit_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    stunnel_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    collab_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    mailsync_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown',
    
    -- Resources
    disk_total_gb DECIMAL(10,2),
    disk_used_gb DECIMAL(10,2),
    disk_percent INT,
    memory_total_mb INT,
    memory_used_mb INT,
    memory_percent INT,
    cpu_load_1m DECIMAL(5,2),
    cpu_load_5m DECIMAL(5,2),
    cpu_load_15m DECIMAL(5,2),
    
    -- SSL
    panel_ssl_expiry DATE,
    email_ssl_expiry DATE,
    mail_ssl_expiry DATE,
    
    -- Additional info
    uptime_seconds BIGINT,
    
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_server (server_id),
    INDEX idx_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Server Errors (Aggregated from agents)
-- =====================================================
CREATE TABLE IF NOT EXISTS server_errors (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'error',
    source VARCHAR(50),
    message TEXT NOT NULL,
    details TEXT,
    log_file VARCHAR(255),
    occurrence_count INT DEFAULT 1,
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved TINYINT(1) DEFAULT 0,
    resolved_at DATETIME,
    resolved_by INT UNSIGNED,
    resolution_notes TEXT,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_server (server_id),
    INDEX idx_severity (severity),
    INDEX idx_resolved (resolved),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- App Packages (Versioned deployable packages)
-- =====================================================
CREATE TABLE IF NOT EXISTS packages (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name ENUM('panel', 'email_app', 'agent') NOT NULL,
    version VARCHAR(20) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_size BIGINT,
    checksum_sha256 VARCHAR(64),
    changelog TEXT,
    is_latest TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_package_version (name, version),
    INDEX idx_name (name),
    INDEX idx_latest (is_latest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Audit Log
-- =====================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    server_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    target VARCHAR(255),
    details JSON,
    ip_address VARCHAR(45),
    outcome ENUM('success', 'failed') DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_server (server_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Settings
-- =====================================================
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Insert Default Admin User
-- Default password: admin (change immediately!)
-- =====================================================
INSERT INTO admin_users (username, password_hash, email, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', 'super_admin')
ON DUPLICATE KEY UPDATE username = username;

-- =====================================================
-- Insert Default Settings
-- =====================================================
INSERT INTO settings (setting_key, setting_value) VALUES
('encryption_key', ''),
('fleet_url', ''),
('panel_package_latest', ''),
('email_app_package_latest', ''),
('agent_package_latest', ''),
('ssh_default_port', '22'),
('ssh_timeout', '30'),
('heartbeat_interval', '60'),
('health_retention_days', '30'),
('error_retention_days', '90')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- =====================================================
-- Cleanup Procedures
-- =====================================================

DELIMITER //

-- Clean old health records
CREATE PROCEDURE IF NOT EXISTS cleanup_old_health(IN days_to_keep INT)
BEGIN
    IF days_to_keep IS NULL THEN
        SET days_to_keep = 30;
    END IF;
    DELETE FROM server_health 
    WHERE recorded_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
END //

-- Clean old resolved errors
CREATE PROCEDURE IF NOT EXISTS cleanup_old_errors(IN days_to_keep INT)
BEGIN
    IF days_to_keep IS NULL THEN
        SET days_to_keep = 90;
    END IF;
    DELETE FROM server_errors 
    WHERE resolved = 1 AND resolved_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
END //

-- Clean expired sessions
CREATE PROCEDURE IF NOT EXISTS cleanup_expired_sessions()
BEGIN
    DELETE FROM sessions WHERE expires_at < NOW();
END //

DELIMITER ;

