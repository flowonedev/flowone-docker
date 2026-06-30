-- =============================================
-- Board Pro Addon Tables
-- Migration 088: Board Pro addon
-- All prefixed with boardpro_ for identification
-- =============================================

-- 1. Card-Email Linking (emails linked to specific cards)
CREATE TABLE IF NOT EXISTS boardpro_card_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL COMMENT 'FK to webmail_board_cards.id',
    board_id INT NOT NULL COMMENT 'FK to webmail_boards.id',
    email_uid INT NOT NULL,
    email_folder VARCHAR(255) NOT NULL,
    email_subject VARCHAR(500) DEFAULT NULL,
    email_from VARCHAR(255) DEFAULT NULL,
    email_date DATETIME DEFAULT NULL,
    thread_id VARCHAR(255) DEFAULT NULL,
    reply_status ENUM('none','replied','awaiting','forwarded') DEFAULT 'none',
    linked_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_card (card_id),
    INDEX idx_board (board_id),
    INDEX idx_email (email_uid, email_folder),
    INDEX idx_thread (thread_id),
    INDEX idx_reply_status (reply_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Auto-Link Email Rules
CREATE TABLE IF NOT EXISTS boardpro_email_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    list_id INT DEFAULT NULL COMMENT 'Target list for auto-created cards',
    rule_type ENUM('subject_contains','sender_domain','sender_email','label_match') NOT NULL,
    rule_value VARCHAR(500) NOT NULL,
    auto_create_card TINYINT(1) DEFAULT 1,
    auto_assign_to VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_board (board_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Card Financial Fields (extends cards without altering base table)
CREATE TABLE IF NOT EXISTS boardpro_card_financials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL COMMENT 'FK to webmail_board_cards.id',
    estimated_revenue DECIMAL(15,2) DEFAULT NULL,
    estimated_cost DECIMAL(15,2) DEFAULT NULL,
    currency VARCHAR(3) DEFAULT 'HUF',
    time_budget_hours DECIMAL(8,2) DEFAULT NULL,
    invoice_status ENUM('none','draft','sent','paid','overdue') DEFAULT 'none',
    linked_invoice_id INT UNSIGNED DEFAULT NULL,
    updated_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_card (card_id),
    INDEX idx_invoice (linked_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Board Automation Rules
CREATE TABLE IF NOT EXISTS boardpro_automation_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    trigger_type ENUM(
        'card_moved_to_list','card_completed','card_overdue',
        'card_idle_days','list_all_completed',
        'email_received_on_card','checklist_completed',
        'label_added','card_created'
    ) NOT NULL,
    trigger_config JSON NOT NULL,
    action_type ENUM(
        'move_card','assign_member','add_label',
        'create_invoice_draft','send_notification',
        'send_email','update_deal_stage',
        'start_crm_sequence','create_calendar_event',
        'post_chat_message'
    ) NOT NULL,
    action_config JSON NOT NULL,
    last_run_at DATETIME DEFAULT NULL,
    run_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_board (board_id),
    INDEX idx_user (user_email),
    INDEX idx_active (is_active, trigger_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Automation Execution Log
CREATE TABLE IF NOT EXISTS boardpro_automation_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    action_taken VARCHAR(100) NOT NULL,
    result_detail TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rule (rule_id),
    INDEX idx_target (target_type, target_id),
    INDEX idx_user_date (user_email, created_at),
    FOREIGN KEY (rule_id) REFERENCES boardpro_automation_rules(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Board-level settings/metadata for Board Pro features
CREATE TABLE IF NOT EXISTS boardpro_board_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    cached_total_revenue JSON DEFAULT NULL COMMENT '{"HUF":50000,"EUR":200}',
    cached_total_cost JSON DEFAULT NULL,
    cached_health_score INT DEFAULT NULL,
    last_ai_summary TEXT DEFAULT NULL,
    last_ai_summary_at DATETIME DEFAULT NULL,
    settings JSON DEFAULT NULL COMMENT 'Per-board Board Pro config',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_board (board_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Card-level permissions/visibility (extends card without altering base)
CREATE TABLE IF NOT EXISTS boardpro_card_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    visibility ENUM('all','members_only','owner_only') DEFAULT 'all',
    stage_lock_list_ids JSON DEFAULT NULL COMMENT 'Lists this card cannot be moved from',
    portal_visible TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_card (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Member stage-level permissions
CREATE TABLE IF NOT EXISTS boardpro_member_stage_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    list_id INT NOT NULL,
    can_view TINYINT(1) DEFAULT 1,
    can_edit TINYINT(1) DEFAULT 0,
    can_move_to TINYINT(1) DEFAULT 0,
    can_move_from TINYINT(1) DEFAULT 0,
    UNIQUE KEY unique_member_stage (board_id, user_email, list_id),
    INDEX idx_board (board_id),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. MoodBoard frame-to-card linking
CREATE TABLE IF NOT EXISTS boardpro_moodboard_card_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    mood_board_id INT NOT NULL,
    mood_board_item_id INT DEFAULT NULL COMMENT 'Specific frame/item',
    linked_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_link (card_id, mood_board_item_id),
    INDEX idx_card (card_id),
    INDEX idx_mood (mood_board_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

