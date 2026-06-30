-- Migration 083: CRM deal stage history for tracking stage transitions
-- Enables conversion funnel analysis and velocity tracking

CREATE TABLE IF NOT EXISTS crm_deal_stage_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deal_id INT UNSIGNED NOT NULL,
    from_stage VARCHAR(50) DEFAULT NULL,
    to_stage VARCHAR(50) NOT NULL,
    changed_by VARCHAR(255) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deal (deal_id),
    INDEX idx_stage (to_stage),
    INDEX idx_changed_at (changed_at),
    FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

