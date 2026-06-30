-- Phase 6: Enhancements & Polish
-- Run: mysql -u root -p < database/migrate_phase6_enhancements.sql

USE vpsadmin;

-- Child domains (subdomains)
CREATE TABLE IF NOT EXISTS child_domains (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    parent_domain VARCHAR(255) NOT NULL,
    subdomain VARCHAR(100) NOT NULL,
    full_domain VARCHAR(255) NOT NULL UNIQUE,
    document_root VARCHAR(500),
    php_version VARCHAR(20),
    ssl_enabled BOOLEAN DEFAULT FALSE,
    ssl_expires TIMESTAMP NULL,
    status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_parent (parent_domain),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Redirects
CREATE TABLE IF NOT EXISTS redirects (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    source_path VARCHAR(500) NOT NULL,
    target_url VARCHAR(500) NOT NULL,
    redirect_type ENUM('301', '302', '307', '308') DEFAULT '301',
    is_regex BOOLEAN DEFAULT FALSE,
    enabled BOOLEAN DEFAULT TRUE,
    hit_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_domain (domain),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom error pages
CREATE TABLE IF NOT EXISTS error_pages (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    error_code INT NOT NULL,
    content MEDIUMTEXT,
    file_path VARCHAR(500),
    enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_domain_code (domain, error_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hotlink protection rules
CREATE TABLE IF NOT EXISTS hotlink_protection (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL UNIQUE,
    enabled BOOLEAN DEFAULT FALSE,
    allowed_domains JSON,
    protected_extensions JSON,
    redirect_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-site IP rules
CREATE TABLE IF NOT EXISTS site_ip_rules (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    action ENUM('allow', 'deny') NOT NULL,
    reason VARCHAR(255),
    expires_at TIMESTAMP NULL,
    hit_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_domain (domain),
    INDEX idx_action (action),
    UNIQUE KEY idx_domain_ip (domain, ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email quotas (alter existing or create if not exists)
-- Note: These should be added to your mail database if using virtual users
CREATE TABLE IF NOT EXISTS mail_quotas (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL UNIQUE,
    quota_mb INT DEFAULT 1024,
    used_mb INT DEFAULT 0,
    last_calculated TIMESTAMP NULL,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_sending_limits (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL UNIQUE,
    hourly_limit INT DEFAULT 100,
    daily_limit INT DEFAULT 500,
    sent_this_hour INT DEFAULT 0,
    sent_today INT DEFAULT 0,
    last_hour_reset TIMESTAMP NULL,
    last_day_reset TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Site clone history
CREATE TABLE IF NOT EXISTS site_clone_history (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    source_domain VARCHAR(255) NOT NULL,
    target_domain VARCHAR(255) NOT NULL,
    include_database BOOLEAN DEFAULT TRUE,
    include_files BOOLEAN DEFAULT TRUE,
    include_emails BOOLEAN DEFAULT FALSE,
    status ENUM('started', 'success', 'failed') NOT NULL,
    log TEXT,
    duration_seconds INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_source (source_domain),
    INDEX idx_target (target_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Phase 6 migration complete: enhancement tables created' AS status;

