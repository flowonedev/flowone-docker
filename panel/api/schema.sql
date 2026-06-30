-- VPS Admin Panel Database Schema
-- Run this against your database to create required tables
-- Note: Uses IF NOT EXISTS to be idempotent

-- Skip tables that already exist with foreign keys
-- Only create NEW tables for this migration

-- Database-Site Links (for tracking which databases belong to which sites)
CREATE TABLE IF NOT EXISTS database_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    db_name VARCHAR(64) NOT NULL,
    db_user VARCHAR(64),
    domain VARCHAR(255) NOT NULL,
    db_host VARCHAR(255) NOT NULL DEFAULT 'localhost',
    created_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_db_domain (db_name, domain),
    INDEX idx_domain (domain),
    INDEX idx_db_name (db_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MAIL SYSTEM TABLES (Independent of CyberPanel)
-- =============================================================================

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
    quota_mb INT DEFAULT 5120,
    disk_usage_kb BIGINT DEFAULT 0,
    maildir_path VARCHAR(512),
    status ENUM('active', 'suspended', 'vacation') NOT NULL DEFAULT 'active',
    login_suspended TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Block IMAP/POP3/SMTP/webmail login while still receiving mail',
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    suspended_reason VARCHAR(255) DEFAULT NULL,
    vacation_message TEXT,
    vacation_subject VARCHAR(255),
    last_login TIMESTAMP NULL,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Require a password change on next webmail login',
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

-- =============================================================================
-- DNS SYSTEM TABLES (PowerDNS Compatible)
-- =============================================================================

-- DNS Domains (Zones) - PowerDNS compatible
CREATE TABLE IF NOT EXISTS dns_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    master VARCHAR(128) DEFAULT NULL,
    last_check INT DEFAULT NULL,
    type VARCHAR(6) NOT NULL DEFAULT 'NATIVE',
    notified_serial INT DEFAULT NULL,
    account VARCHAR(40) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DNS Records - PowerDNS compatible
CREATE TABLE IF NOT EXISTS dns_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(10) NOT NULL,
    content TEXT NOT NULL,
    ttl INT DEFAULT 3600,
    prio INT DEFAULT 0,
    disabled BOOLEAN DEFAULT FALSE,
    ordername VARCHAR(255) DEFAULT NULL,
    auth BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain_id (domain_id),
    INDEX idx_name_type (name, type),
    INDEX idx_ordername (ordername),
    FOREIGN KEY (domain_id) REFERENCES dns_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DNS Domain Metadata - PowerDNS compatible (for DNSSEC, etc.)
CREATE TABLE IF NOT EXISTS dns_domainmetadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    kind VARCHAR(32) NOT NULL,
    content TEXT,
    INDEX idx_domain_id (domain_id),
    FOREIGN KEY (domain_id) REFERENCES dns_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DNS Migration Status
CREATE TABLE IF NOT EXISTS dns_migration_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_phase ENUM('not_started', 'syncing', 'dual_write', 'switched', 'completed') NOT NULL DEFAULT 'not_started',
    last_sync_at TIMESTAMP NULL,
    zones_synced INT DEFAULT 0,
    records_synced INT DEFAULT 0,
    pdns_config_updated BOOLEAN DEFAULT FALSE,
    rollback_available BOOLEAN DEFAULT TRUE,
    notes TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert initial DNS migration status
INSERT IGNORE INTO dns_migration_status (id, migration_phase) VALUES (1, 'not_started');

-- =============================================================================
-- IMAP MIGRATION JOBS (for imapsync email transfers)
-- =============================================================================

-- =============================================================================
-- TEMPLATE DEPLOYMENTS (tracks which templates are applied to sites)
-- =============================================================================

CREATE TABLE IF NOT EXISTS template_deployments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    template_type VARCHAR(64) NOT NULL,
    backup_file VARCHAR(512),
    deployed_by VARCHAR(255),
    deployed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_template_type (template_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- IMAP MIGRATION JOBS (for imapsync email transfers)
-- =============================================================================

-- IMAP Migration Jobs - tracks email migrations from external servers
CREATE TABLE IF NOT EXISTS imap_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('single', 'batch') NOT NULL DEFAULT 'single',
    source_host VARCHAR(255) NOT NULL,
    source_port INT DEFAULT 993,
    source_ssl BOOLEAN DEFAULT TRUE,
    dest_host VARCHAR(255) NOT NULL,
    dest_port INT DEFAULT 993,
    dest_ssl BOOLEAN DEFAULT TRUE,
    accounts JSON NOT NULL COMMENT 'Array of {email, source_password, dest_email, dest_password}',
    total_accounts INT DEFAULT 1,
    completed_accounts INT DEFAULT 0,
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    progress INT DEFAULT 0 COMMENT 'Overall progress percentage',
    current_account VARCHAR(255) COMMENT 'Currently migrating account',
    pid INT COMMENT 'Process ID of running imapsync',
    log_file VARCHAR(512) COMMENT 'Path to log file',
    error_message TEXT,
    started_at DATETIME,
    completed_at DATETIME,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MAIL SECURITY GATEWAY (Rspamd + ClamAV) - V1 foundation
-- All tables are additive. Nothing here affects live mail delivery on its own.
-- =============================================================================

-- Global whitelist (allow list) - exported to Rspamd multimaps
CREATE TABLE IF NOT EXISTS mail_security_global_whitelist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('email', 'domain', 'ip', 'cidr') NOT NULL,
    value VARCHAR(255) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wl (type, value),
    INDEX idx_wl_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global blacklist (block list) - exported to Rspamd multimaps
CREATE TABLE IF NOT EXISTS mail_security_global_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('email', 'domain', 'ip', 'cidr') NOT NULL,
    value VARCHAR(255) NOT NULL,
    action ENUM('reject', 'quarantine') NOT NULL DEFAULT 'reject',
    description VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bl (type, value),
    INDEX idx_bl_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quarantine index - one row per held message (raw .eml lives in spool_path)
CREATE TABLE IF NOT EXISTS mail_quarantine (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) DEFAULT NULL,
    sender VARCHAR(320) DEFAULT NULL,
    recipient VARCHAR(320) DEFAULT NULL,
    subject VARCHAR(998) DEFAULT NULL,
    spam_score DECIMAL(6,2) DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    headers MEDIUMTEXT DEFAULT NULL,
    spool_path VARCHAR(512) NOT NULL,
    status ENUM('quarantined', 'released', 'deleted') NOT NULL DEFAULT 'quarantined',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME DEFAULT NULL,
    released_by VARCHAR(255) DEFAULT NULL,
    INDEX idx_q_recipient (recipient),
    INDEX idx_q_status (status),
    INDEX idx_q_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event log - source for dashboard widgets and reports
CREATE TABLE IF NOT EXISTS mail_security_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    event_type ENUM('clean', 'spam', 'quarantine', 'reject', 'virus', 'spf_fail', 'dkim_fail', 'dmarc_fail', 'phish', 'policy') NOT NULL,
    sender VARCHAR(320) DEFAULT NULL,
    recipient VARCHAR(320) DEFAULT NULL,
    domain VARCHAR(255) DEFAULT NULL,
    score DECIMAL(6,2) DEFAULT NULL,
    symbol VARCHAR(255) DEFAULT NULL,
    INDEX idx_ev_ts (ts),
    INDEX idx_ev_type (event_type),
    INDEX idx_ev_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value settings (scores, mode, etc.)
CREATE TABLE IF NOT EXISTS mail_security_settings (
    k VARCHAR(100) PRIMARY KEY,
    v TEXT DEFAULT NULL,
    updated_by VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachment policies (allowed / blocked extensions)
CREATE TABLE IF NOT EXISTS mail_security_attachment_policy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    extension VARCHAR(20) NOT NULL,
    list_type ENUM('allow', 'block') NOT NULL DEFAULT 'block',
    action ENUM('reject', 'quarantine', 'warn') NOT NULL DEFAULT 'quarantine',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ext (extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anti-spoofing / CEO-fraud (BEC) lists (V2)
--   vip_name         protected exec/VIP display name (normalised to alnum-lower
--                    in the engine map); spoofed when used by a non-protected sender
--   protected_domain extra "our" domains (mail_domains are auto-included too)
--   exempt_sender    sender addresses that must never be flagged (a VIP's real
--                    external address, trusted partners, etc.)
CREATE TABLE IF NOT EXISTS mail_security_impersonation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('vip_name', 'protected_domain', 'exempt_sender') NOT NULL,
    value VARCHAR(320) NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_imp (kind, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail flow rules engine (V2) - schema created now so it is migration-stable
CREATE TABLE IF NOT EXISTS mail_security_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 100,
    conditions_json JSON DEFAULT NULL,
    action ENUM('move', 'delete', 'quarantine', 'reject', 'tag') NOT NULL,
    action_arg VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rule_enabled (enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geo-IP per-recipient-domain country overrides (V2). Global policy lives in
-- mail_security_settings (geoip_*). Country is resolved by Rspamd's ASN module.
CREATE TABLE IF NOT EXISTS mail_security_geoip (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    mode ENUM('allow', 'deny') NOT NULL DEFAULT 'deny',
    countries VARCHAR(512) NOT NULL DEFAULT '',
    action ENUM('reject', 'quarantine', 'tag') NOT NULL DEFAULT 'reject',
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_geoip_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VirusTotal on-demand lookups (URLs / file hashes), cached to respect the
-- free-tier rate limits. resource_hash = sha256(normalized resource).
CREATE TABLE IF NOT EXISTS mail_security_vt_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    resource_type ENUM('url', 'file') NOT NULL,
    resource VARCHAR(2048) NOT NULL,
    resource_hash CHAR(64) NOT NULL,
    verdict VARCHAR(16) NOT NULL DEFAULT 'unknown',
    malicious INT NOT NULL DEFAULT 0,
    suspicious INT NOT NULL DEFAULT 0,
    harmless INT NOT NULL DEFAULT 0,
    undetected INT NOT NULL DEFAULT 0,
    total INT NOT NULL DEFAULT 0,
    permalink VARCHAR(512) DEFAULT NULL,
    raw JSON DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vt (resource_type, resource_hash),
    INDEX idx_vt_checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reactive Bayes learning events: every move-to-Junk / move-out-of-Junk in any
-- IMAP client appends one row here so the dashboard can show what users are
-- training the classifier on. Drained by mailsec-event-sync.php from a spool
-- written by the sieve_pipe learn wrapper.
CREATE TABLE IF NOT EXISTS mail_security_learn_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    direction ENUM('spam', 'ham') NOT NULL,
    source ENUM('imapsieve', 'webmail', 'autolearn', 'admin') NOT NULL DEFAULT 'imapsieve',
    user_email VARCHAR(320) DEFAULT NULL,
    sender VARCHAR(320) DEFAULT NULL,
    message_id VARCHAR(255) DEFAULT NULL,
    rspamc_rc INT DEFAULT NULL,
    opted_out TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_learn_ts (ts),
    INDEX idx_learn_user (user_email),
    INDEX idx_learn_dir_src (direction, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings: thresholds + safe defaults. mode='monitor' = no enforcement,
-- milter_wired='0' = Postfix is NOT yet pointed at Rspamd (zero delivery impact).
INSERT IGNORE INTO mail_security_settings (k, v) VALUES
    ('spam_score_threshold', '6'),
    ('reject_score_threshold', '15'),
    ('mode', 'monitor'),
    ('milter_wired', '0'),
    ('quarantine_retention_days', '30'),
    ('lookalike_enabled', '1'),
    ('lookalike_sensitivity', 'medium'),
    ('geoip_enabled', '0'),
    ('geoip_mode', 'deny'),
    ('geoip_countries', ''),
    ('geoip_action', 'reject'),
    ('virustotal_cache_ttl', '24'),
    ('learn_loop_enabled', '1'),
    ('learn_events_retention_days', '90'),
    ('quarantine_user_digest_enabled', '0'),
    ('quarantine_link_base', ''),
    ('quarantine_link_ttl_days', '7');

-- Default blocked executable attachment types
INSERT IGNORE INTO mail_security_attachment_policy (extension, list_type, action) VALUES
    ('exe', 'block', 'quarantine'),
    ('bat', 'block', 'quarantine'),
    ('cmd', 'block', 'quarantine'),
    ('scr', 'block', 'quarantine'),
    ('ps1', 'block', 'quarantine'),
    ('vbs', 'block', 'quarantine'),
    ('js',  'block', 'quarantine'),
    ('jar', 'block', 'quarantine');