-- Template Deployments Tracking
-- Tracks which template is deployed to which site

CREATE TABLE IF NOT EXISTS template_deployments (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    template_type ENUM('site_placeholder', 'site_coming_soon', 'site_maintenance') NOT NULL,
    deployed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deployed_by VARCHAR(50),
    backup_file VARCHAR(255),
    INDEX idx_domain (domain),
    INDEX idx_template_type (template_type),
    UNIQUE KEY unique_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

