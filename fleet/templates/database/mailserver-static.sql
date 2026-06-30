-- Static mailserver schema for provisioning
-- Used when the main server doesn't have a mailserver database to dump from
-- Source: VPS Admin Panel api/schema.sql (mail tables)
-- This file is maintained manually and should match the Panel's schema

-- Mail Domains
CREATE TABLE IF NOT EXISTS mail_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    dkim_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    dkim_selector VARCHAR(64) DEFAULT 'default',
    dkim_private_key TEXT,
    dkim_public_key TEXT,
    spf_record VARCHAR(512),
    dmarc_record VARCHAR(512),
    catch_all_email VARCHAR(255),
    max_accounts INT DEFAULT 100,
    max_quota_mb INT DEFAULT 5120,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail Accounts
CREATE TABLE IF NOT EXISTS mail_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    domain VARCHAR(255) NOT NULL,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    quota_mb INT DEFAULT 512,
    disk_usage_kb BIGINT DEFAULT 0,
    maildir_path VARCHAR(512),
    status ENUM('active', 'suspended', 'vacation') NOT NULL DEFAULT 'active',
    login_suspended TINYINT(1) NOT NULL DEFAULT 0,
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    suspended_reason VARCHAR(255) DEFAULT NULL,
    vacation_message TEXT,
    vacation_subject VARCHAR(255),
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_status (status),
    INDEX idx_login_suspended (login_suspended)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail Forwards (Aliases)
CREATE TABLE IF NOT EXISTS mail_forwards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_email VARCHAR(255) NOT NULL,
    source_domain VARCHAR(255) NOT NULL,
    destination VARCHAR(512) NOT NULL,
    keep_copy BOOLEAN NOT NULL DEFAULT FALSE,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_source (source_email),
    INDEX idx_domain (source_domain),
    UNIQUE KEY unique_forward (source_email, destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail Migration Status (tracks sync state)
CREATE TABLE IF NOT EXISTS mail_migration_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_phase ENUM('not_started', 'syncing', 'dual_write', 'switched', 'completed') NOT NULL DEFAULT 'not_started',
    last_sync_at TIMESTAMP NULL,
    accounts_synced INT DEFAULT 0,
    forwards_synced INT DEFAULT 0,
    domains_synced INT DEFAULT 0,
    postfix_config_updated BOOLEAN DEFAULT FALSE,
    dovecot_config_updated BOOLEAN DEFAULT FALSE,
    rollback_available BOOLEAN DEFAULT TRUE,
    notes TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert initial migration status
INSERT IGNORE INTO mail_migration_status (id, migration_phase) VALUES (1, 'not_started');

