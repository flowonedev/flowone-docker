-- Migration 101: Campaign engagement retargeting trigger
-- Adds automation trigger for campaign engagement thresholds (link click rate, video clicks, open count)

-- 1. Add campaign_engagement_threshold to trigger_type ENUM
ALTER TABLE crm_automation_rules MODIFY trigger_type ENUM(
    'deal_stage_idle','deal_stage_changed','client_health_low',
    'invoice_overdue','no_contact_days','deal_won','deal_lost',
    'task_changed','board_closed','moodboard_ready',
    'time_spent_reached','colleague_sick_status',
    'drive_folder_permission_changed',
    'email_opened','email_link_clicked',
    'campaign_engagement_threshold'
) NOT NULL;

-- 2. Deduplication table: prevents same trigger from firing twice for same recipient+campaign+rule
CREATE TABLE IF NOT EXISTS campaign_engagement_fired (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    campaign_id VARCHAR(36) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    engagement_percent DECIMAL(5,1) DEFAULT NULL,
    fired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_fire (rule_id, campaign_id, recipient_email),
    INDEX idx_campaign (campaign_id),
    INDEX idx_rule (rule_id),
    FOREIGN KEY (rule_id) REFERENCES crm_automation_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
