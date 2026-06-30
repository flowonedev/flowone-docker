-- NAS Storage Management Migration
-- Creates tables for NAS connection management and domain overrides
-- Run: mysql -u root -p vpsadmin < database/migrate_nas_storage.sql
-- Or use: php database/migrate_nas_storage.php

-- =====================================================
-- NAS Connections Table
-- Stores NAS/storage connection configurations
-- =====================================================
CREATE TABLE IF NOT EXISTS nas_connections (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    driver ENUM('local', 'nfs', 'cifs') DEFAULT 'nfs',
    mount_point VARCHAR(500) NOT NULL,
    nfs_server VARCHAR(255),
    nfs_path VARCHAR(500),
    nfs_options VARCHAR(500) DEFAULT 'rw,soft,timeo=10,retrans=3',
    vpn_enabled TINYINT(1) DEFAULT 0,
    vpn_config_path VARCHAR(500),
    is_default TINYINT(1) DEFAULT 0,
    status ENUM('active', 'inactive', 'error') DEFAULT 'active',
    last_check TIMESTAMP NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- NAS Domain Overrides Table
-- Allows assigning specific NAS storage to specific domains
-- =====================================================
CREATE TABLE IF NOT EXISTS nas_domain_overrides (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    nas_connection_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    sub_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain),
    FOREIGN KEY (nas_connection_id) REFERENCES nas_connections(id) ON DELETE CASCADE,
    INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Insert Default Local Storage
-- =====================================================
-- NOTE: this row is served to the email app via /api/storage/config and used
-- as the Drive file storage base path. It must point at a www-data-writable
-- directory - NEVER at the mail spool (/var/mail/vhosts), which made every
-- Drive upload fail with permission denied on freshly provisioned servers.
-- Guarded with NOT EXISTS: nas_connections has no unique key, so a plain
-- INSERT would add a duplicate default row on every package re-install and
-- could steal "default" back from an operator-configured NAS connection.
INSERT INTO nas_connections (name, driver, mount_point, is_default, status, notes)
SELECT 'Local Storage', 'local', '/var/www/vps-email/storage/drive', 1, 'active', 'Default local storage for FlowOne Drive'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM nas_connections WHERE is_default = 1);

