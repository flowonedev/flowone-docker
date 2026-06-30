<?php

namespace VpsAdmin\Api\Core;

/**
 * Simple migration system for database schema updates
 */
class Migration
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }


    /**
     * Get list of existing tables
     */
    private function getExistingTables(array $tableNames): array
    {
        $placeholders = implode(',', array_fill(0, count($tableNames), '?'));
        $stmt = $this->db->prepare("
            SELECT TABLE_NAME 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME IN ({$placeholders})
        ");
        $stmt->execute($tableNames);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Create ai_conversations table
     */
    private function createAIConversationsTable(): void
    {
        // Check if admin_users table exists before creating foreign key
        $stmt = $this->db->query("SHOW TABLES LIKE 'admin_users'");
        $hasAdminUsers = $stmt->rowCount() > 0;
        
        if ($hasAdminUsers) {
            // Create with foreign key
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_conversations (
                    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    title VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
                    INDEX idx_user_id (user_id),
                    INDEX idx_updated_at (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            // Create without foreign key if admin_users doesn't exist
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_conversations (
                    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    title VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_updated_at (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    /**
     * Create ai_messages table
     */
    private function createAIMessagesTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ai_messages (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                conversation_id INT UNSIGNED NOT NULL,
                role ENUM('user', 'assistant', 'system') NOT NULL,
                content TEXT NOT NULL,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
                INDEX idx_conversation_id (conversation_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Create ai_cached_issues table
     */
    private function createAICachedIssuesTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ai_cached_issues (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                issue_type VARCHAR(100) NOT NULL,
                service VARCHAR(50),
                issue_key VARCHAR(255) NOT NULL,
                severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                description TEXT,
                detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL,
                metadata JSON,
                UNIQUE KEY unique_issue (issue_type, service, issue_key),
                INDEX idx_service (service),
                INDEX idx_severity (severity),
                INDEX idx_resolved (resolved_at),
                INDEX idx_detected_at (detected_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Create ai_helper_settings table
     */
    private function createAIHelperSettingsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ai_helper_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Insert default settings if they don't exist
        $defaults = [
            ['openai_api_key', ''],
            ['openai_model', 'gpt-5'],
            ['max_tokens', '2000'],
            ['temperature', '0.3'],
        ];

        $stmt = $this->db->prepare("INSERT IGNORE INTO ai_helper_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaults as $setting) {
            $stmt->execute($setting);
        }
    }

    /**
     * Create login_attempts table for rate limiting and account lockout
     */
    private function createLoginAttemptsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                ip_address VARCHAR(45) NOT NULL,
                username VARCHAR(255),
                success TINYINT(1) DEFAULT 0,
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip (ip_address),
                INDEX idx_username (username),
                INDEX idx_attempted_at (attempted_at),
                INDEX idx_ip_time (ip_address, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Run security migrations
     */
    public function migrateSecurity(): bool
    {
        try {
            $tables = ['login_attempts'];
            $existingTables = $this->getExistingTables($tables);

            if (count($existingTables) === count($tables)) {
                return true;
            }

            $this->createLoginAttemptsTable();
            return true;
        } catch (\Exception $e) {
            debug_log('Security migration failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Run AI Helper migrations if tables don't exist
     */
    public function migrateAIHelper(): bool
    {
        try {
            // Check if tables exist
            $tables = ['ai_conversations', 'ai_messages', 'ai_cached_issues', 'ai_helper_settings'];
            $existingTables = $this->getExistingTables($tables);

            if (count($existingTables) === count($tables)) {
                return true; // All tables exist
            }

            // Create missing tables
            $this->createAIConversationsTable();
            $this->createAIMessagesTable();
            $this->createAICachedIssuesTable();
            $this->createAIHelperSettingsTable();

            // Verify tables were created
            $existingTables = $this->getExistingTables($tables);
            if (count($existingTables) === count($tables)) {
                return true;
            }
            
            debug_log('AI Helper migration: Not all tables were created. Expected: ' . count($tables) . ', Created: ' . count($existingTables));
            return false;
        } catch (\PDOException $e) {
            debug_log('AI Helper migration failed (PDO): ' . $e->getMessage());
            debug_log('SQL State: ' . $e->getCode());
            return false;
        } catch (\Exception $e) {
            debug_log('AI Helper migration failed: ' . $e->getMessage());
            debug_log('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Create the Mail Security Gateway tables if missing (idempotent).
     *
     * Mirrors the mail-security section of panel/api/schema.sql so that a code
     * deploy which adds a new table never requires a separate manual SQL step
     * (which is exactly how mail_security_impersonation went missing). Safe to
     * run on every boot: every statement is CREATE TABLE IF NOT EXISTS or
     * INSERT IGNORE, and the early-return skips the work once all tables exist.
     */
    public function migrateMailSecurity(): bool
    {
        try {
            $tables = [
                'mail_security_global_whitelist',
                'mail_security_global_blacklist',
                'mail_quarantine',
                'mail_security_events',
                'mail_security_settings',
                'mail_security_attachment_policy',
                'mail_security_impersonation',
                'mail_security_rules',
                'mail_security_geoip',
                'mail_security_vt_cache',
                'mail_security_learn_events',
            ];

            if (count($this->getExistingTables($tables)) === count($tables)) {
                return true; // All present - nothing to do.
            }

            $statements = [
                "CREATE TABLE IF NOT EXISTS mail_security_global_whitelist (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type ENUM('email', 'domain', 'ip', 'cidr') NOT NULL,
                    value VARCHAR(255) NOT NULL,
                    description VARCHAR(255) DEFAULT NULL,
                    created_by VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_wl (type, value),
                    INDEX idx_wl_type (type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_global_blacklist (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type ENUM('email', 'domain', 'ip', 'cidr') NOT NULL,
                    value VARCHAR(255) NOT NULL,
                    action ENUM('reject', 'quarantine') NOT NULL DEFAULT 'reject',
                    description VARCHAR(255) DEFAULT NULL,
                    created_by VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_bl (type, value),
                    INDEX idx_bl_type (type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_quarantine (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_events (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_settings (
                    k VARCHAR(100) PRIMARY KEY,
                    v TEXT DEFAULT NULL,
                    updated_by VARCHAR(255) DEFAULT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_attachment_policy (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    extension VARCHAR(20) NOT NULL,
                    list_type ENUM('allow', 'block') NOT NULL DEFAULT 'block',
                    action ENUM('reject', 'quarantine', 'warn') NOT NULL DEFAULT 'quarantine',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_ext (extension)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_impersonation (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    kind ENUM('vip_name', 'protected_domain', 'exempt_sender') NOT NULL,
                    value VARCHAR(320) NOT NULL,
                    note VARCHAR(255) DEFAULT NULL,
                    created_by VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_imp (kind, value)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_rules (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_geoip (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    domain VARCHAR(255) NOT NULL,
                    mode ENUM('allow', 'deny') NOT NULL DEFAULT 'deny',
                    countries VARCHAR(512) NOT NULL DEFAULT '',
                    action ENUM('reject', 'quarantine', 'tag') NOT NULL DEFAULT 'reject',
                    created_by VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_geoip_domain (domain)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_vt_cache (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS mail_security_learn_events (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];

            foreach ($statements as $sql) {
                $this->db->exec($sql);
            }

            // Safe defaults (idempotent). Monitor mode + un-wired milter = zero
            // delivery impact until an admin explicitly enables enforcement.
            $this->db->exec(
                "INSERT IGNORE INTO mail_security_settings (k, v) VALUES
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
                    ('quarantine_link_ttl_days', '7')"
            );
            $this->db->exec(
                "INSERT IGNORE INTO mail_security_attachment_policy (extension, list_type, action) VALUES
                    ('exe', 'block', 'quarantine'),
                    ('bat', 'block', 'quarantine'),
                    ('cmd', 'block', 'quarantine'),
                    ('scr', 'block', 'quarantine'),
                    ('ps1', 'block', 'quarantine'),
                    ('vbs', 'block', 'quarantine'),
                    ('js',  'block', 'quarantine'),
                    ('jar', 'block', 'quarantine')"
            );

            return count($this->getExistingTables($tables)) === count($tables);
        } catch (\Throwable $e) {
            debug_log('Mail security migration failed: ' . $e->getMessage());
            return false;
        }
    }
}

