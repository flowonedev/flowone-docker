-- Fleet Manager Migration: Blueprint Packages & Idempotency Tracking
-- Version: 003
-- Description: Add package definitions to blueprints and tracking tables for idempotent deployments

-- =====================================================
-- Update deployments table ENUM (add new deployment types)
-- =====================================================
ALTER TABLE deployments 
MODIFY COLUMN type ENUM(
    'full_provision', 
    'config_only', 
    'packages_config', 
    'panel_update', 
    'email_update', 
    'agent_update', 
    'config_update', 
    'ssl_renew'
) NOT NULL;

-- =====================================================
-- Package definitions for blueprints
-- =====================================================
CREATE TABLE IF NOT EXISTS blueprint_packages (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    blueprint_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,  -- 'base', 'web', 'mail', 'security', 'php'
    package_name VARCHAR(100) NOT NULL,
    version_constraint VARCHAR(50),  -- NULL = latest, or '8.3', '>=10.4'
    is_required TINYINT(1) DEFAULT 1,
    install_order INT DEFAULT 0,
    pre_install_script TEXT,  -- Optional: run before installing
    post_install_script TEXT, -- Optional: run after installing
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (blueprint_id) REFERENCES blueprints(id) ON DELETE CASCADE,
    INDEX idx_blueprint_cat (blueprint_id, category),
    INDEX idx_install_order (blueprint_id, install_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Track what's installed on servers (for idempotency)
-- =====================================================
CREATE TABLE IF NOT EXISTS server_packages (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    package_name VARCHAR(100) NOT NULL,
    installed_version VARCHAR(50),
    installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_server_package (server_id, package_name),
    INDEX idx_server (server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Track config file hashes (for idempotency)
-- =====================================================
CREATE TABLE IF NOT EXISTS server_configs (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    target_path VARCHAR(500) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,  -- SHA-256
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    template_id INT UNSIGNED,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES blueprint_templates(id) ON DELETE SET NULL,
    UNIQUE KEY unique_server_path (server_id, target_path),
    INDEX idx_server (server_id),
    INDEX idx_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Config deployment backups
-- =====================================================
CREATE TABLE IF NOT EXISTS config_backups (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    deployment_id INT UNSIGNED,
    target_path VARCHAR(500) NOT NULL,
    backup_content LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE SET NULL,
    INDEX idx_server_path (server_id, target_path),
    INDEX idx_deployment (deployment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Insert default package categories
-- =====================================================
INSERT INTO settings (setting_key, setting_value) VALUES
('package_categories', '["base","web","php","mail","security","database"]')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

