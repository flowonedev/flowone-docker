-- VPN Connections Management Migration
-- Creates table for OpenVPN client connection management
-- Run: mysql -u root -p vpsadmin < database/migrate_vpn_connections.sql

-- =====================================================
-- VPN Connections Table
-- Stores OpenVPN client configurations
-- =====================================================
CREATE TABLE IF NOT EXISTS vpn_connections (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    config_name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    server_address VARCHAR(255),
    server_port INT DEFAULT 1194,
    protocol ENUM('udp', 'tcp') DEFAULT 'udp',
    status ENUM('connected', 'disconnected', 'connecting', 'error') DEFAULT 'disconnected',
    local_ip VARCHAR(45),
    remote_ip VARCHAR(45),
    connected_at TIMESTAMP NULL,
    last_error TEXT,
    auto_start TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_status (status),
    INDEX idx_config_name (config_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

