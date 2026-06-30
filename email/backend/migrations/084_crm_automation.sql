-- Migration 084: CRM Automation Engine - rules, execution log, sequences, enrollments

-- Automation rules: "when X happens, do Y"
CREATE TABLE IF NOT EXISTS crm_automation_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    -- Trigger
    trigger_type ENUM(
        'deal_stage_idle',
        'deal_stage_changed',
        'client_health_low',
        'invoice_overdue',
        'no_contact_days',
        'deal_won',
        'deal_lost'
    ) NOT NULL,
    trigger_config JSON NOT NULL COMMENT '{"stage":"proposal","days":7}',
    -- Action
    action_type ENUM(
        'create_reminder',
        'send_email',
        'create_invoice_draft',
        'move_deal_stage',
        'notify_user',
        'start_sequence'
    ) NOT NULL,
    action_config JSON NOT NULL COMMENT '{"template_id":5,"delay_hours":0}',
    -- Tracking
    last_run_at DATETIME DEFAULT NULL,
    run_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_email),
    INDEX idx_active (is_active, trigger_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log of automation executions
CREATE TABLE IF NOT EXISTS crm_automation_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    target_type ENUM('deal', 'client', 'invoice') NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    action_taken VARCHAR(100) NOT NULL,
    result_detail TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rule (rule_id),
    INDEX idx_target (target_type, target_id),
    INDEX idx_user_date (user_email, created_at),
    FOREIGN KEY (rule_id) REFERENCES crm_automation_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email sequences (multi-step drip campaigns)
CREATE TABLE IF NOT EXISTS crm_sequences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    trigger_stage VARCHAR(50) DEFAULT NULL COMMENT 'Auto-start when deal enters this stage',
    is_active TINYINT(1) DEFAULT 1,
    steps JSON NOT NULL COMMENT '[{"delay_days":0,"template_id":1,"subject":"..."},{"delay_days":3,"template_id":2}]',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_email),
    INDEX idx_trigger_stage (trigger_stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracks which deals/clients are enrolled in which sequence
CREATE TABLE IF NOT EXISTS crm_sequence_enrollments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sequence_id INT UNSIGNED NOT NULL,
    deal_id INT UNSIGNED DEFAULT NULL,
    client_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    current_step INT DEFAULT 0,
    status ENUM('active', 'completed', 'cancelled', 'paused') DEFAULT 'active',
    next_run_at DATETIME DEFAULT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    INDEX idx_sequence (sequence_id),
    INDEX idx_status_next (status, next_run_at),
    INDEX idx_deal (deal_id),
    INDEX idx_user (user_email),
    FOREIGN KEY (sequence_id) REFERENCES crm_sequences(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

