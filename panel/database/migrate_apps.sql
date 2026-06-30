-- VPS Admin Panel - Application Installer Tables
-- Phase 1: Application management schema
-- Run with: mysql -u root -p devc_vps_dash < database/migrate_apps.sql

USE devc_vps_dash;

-- Application templates (WordPress, Joomla, etc.)
CREATE TABLE IF NOT EXISTS app_templates (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    version VARCHAR(20),
    category ENUM('cms', 'ecommerce', 'forum', 'framework', 'other') DEFAULT 'cms',
    icon VARCHAR(255),
    download_url VARCHAR(500),
    requirements JSON COMMENT '{"php": ">=8.0", "mysql": true, "extensions": ["curl", "gd"]}',
    install_script TEXT,
    post_install TEXT,
    status ENUM('active', 'deprecated') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Installed applications per site
CREATE TABLE IF NOT EXISTS site_applications (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    app_slug VARCHAR(50) NOT NULL,
    app_version VARCHAR(20),
    install_path VARCHAR(500) NOT NULL,
    admin_url VARCHAR(255),
    admin_user VARCHAR(100),
    database_name VARCHAR(100),
    installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    installed_by INT UNSIGNED,
    status ENUM('active', 'updating', 'failed') DEFAULT 'active',
    notes TEXT,
    INDEX idx_domain (domain),
    INDEX idx_app_slug (app_slug),
    FOREIGN KEY (installed_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert WordPress template only
INSERT INTO app_templates (slug, name, description, version, category, icon, requirements) VALUES
('wordpress', 'WordPress', 'The world''s most popular content management system. Powers over 40% of all websites.', 'latest', 'cms', 'web', '{"php": ">=7.4", "mysql": true}')
ON DUPLICATE KEY UPDATE name = VALUES(name);

SELECT 'Migration completed successfully!' AS status;

