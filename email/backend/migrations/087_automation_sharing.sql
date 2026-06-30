-- Migration 087: Automation rule sharing
-- Allows rules to be shared with individual colleagues or colleague groups

-- Add visibility column to automation rules (safe: skip if already exists)
-- ALTER TABLE crm_automation_rules ADD COLUMN visibility ENUM('private', 'shared') DEFAULT 'private' AFTER is_active;
-- ALTER TABLE crm_automation_rules ADD INDEX idx_visibility (visibility);
-- NOTE: Column already created by ensureTables() in CrmAutomationService.php

-- Individual colleague shares
CREATE TABLE IF NOT EXISTS crm_automation_rule_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    shared_with_email VARCHAR(255) NOT NULL,
    permission ENUM('viewer', 'editor') DEFAULT 'viewer' COMMENT 'viewer=can use/copy, editor=can edit',
    shared_by_email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rule_share (rule_id, shared_with_email),
    INDEX idx_shared_with (shared_with_email),
    INDEX idx_rule (rule_id),
    FOREIGN KEY (rule_id) REFERENCES crm_automation_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group shares
CREATE TABLE IF NOT EXISTS crm_automation_rule_group_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    permission ENUM('viewer', 'editor') DEFAULT 'viewer',
    shared_by_email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rule_group_share (rule_id, group_id),
    INDEX idx_group (group_id),
    INDEX idx_rule (rule_id),
    FOREIGN KEY (rule_id) REFERENCES crm_automation_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

