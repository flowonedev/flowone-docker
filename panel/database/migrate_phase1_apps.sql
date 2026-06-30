-- Phase 1: Application Installer
-- Run: mysql -u root -p < database/migrate_phase1_apps.sql

USE vpsadmin;

-- Application templates
CREATE TABLE IF NOT EXISTS app_templates (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    version VARCHAR(20),
    category ENUM('cms', 'ecommerce', 'forum', 'framework', 'other') DEFAULT 'cms',
    icon VARCHAR(255),
    download_url VARCHAR(500),
    requirements JSON,
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
    INDEX idx_app (app_slug),
    FOREIGN KEY (installed_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default templates
INSERT INTO app_templates (slug, name, description, category, icon, requirements, status) VALUES
('wordpress', 'WordPress', 'The world''s most popular CMS for blogs and websites', 'cms', 'wordpress', 
 '{"php": ">=7.4", "mysql": true, "extensions": ["curl", "gd", "mbstring", "xml"]}', 'active'),
 
('joomla', 'Joomla', 'Flexible CMS for building websites and applications', 'cms', 'joomla',
 '{"php": ">=8.0", "mysql": true, "extensions": ["curl", "gd", "mbstring", "xml", "json"]}', 'active'),
 
('drupal', 'Drupal', 'Enterprise-grade CMS for complex websites', 'cms', 'drupal',
 '{"php": ">=8.1", "mysql": true, "extensions": ["curl", "gd", "mbstring", "xml", "json", "pdo"]}', 'active'),

('laravel', 'Laravel', 'PHP framework for web artisans', 'framework', 'laravel',
 '{"php": ">=8.1", "mysql": true, "extensions": ["curl", "mbstring", "xml", "json", "pdo", "openssl"], "composer": true}', 'active'),

('prestashop', 'PrestaShop', 'Open-source e-commerce solution', 'ecommerce', 'prestashop',
 '{"php": ">=7.4", "mysql": true, "extensions": ["curl", "gd", "mbstring", "xml", "json", "zip"]}', 'active'),

('opencart', 'OpenCart', 'Free shopping cart solution', 'ecommerce', 'opencart',
 '{"php": ">=8.0", "mysql": true, "extensions": ["curl", "gd", "mbstring", "xml", "json", "zip"]}', 'active'),

('moodle', 'Moodle', 'Open-source learning platform', 'other', 'moodle',
 '{"php": ">=8.0", "mysql": true, "extensions": ["curl", "gd", "mbstring", "xml", "json", "zip", "intl"]}', 'active'),

('phpbb', 'phpBB', 'Free forum software', 'forum', 'phpbb',
 '{"php": ">=7.4", "mysql": true, "extensions": ["json"]}', 'active')

ON DUPLICATE KEY UPDATE name = VALUES(name);

SELECT 'Phase 1 migration complete: app_templates and site_applications tables created' AS status;

