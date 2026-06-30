-- Migration 100: Campaign drafts, merge tags, mailing list custom fields
-- Adds draft status, mailing list assignment, contact custom fields, and recipient data for merge tags

-- 1. Add 'draft' to campaign status enum
ALTER TABLE email_campaigns MODIFY status ENUM('draft','pending','processing','completed','paused','cancelled') DEFAULT 'pending';

-- 2. Add mailing_list_id to campaigns (for draft -> finalize flow)
ALTER TABLE email_campaigns ADD COLUMN mailing_list_id INT UNSIGNED DEFAULT NULL AFTER track_read;

-- 3. Add updated_at to campaigns (track last edit on drafts)
ALTER TABLE email_campaigns ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER completed_at;

-- 4. Add recipient_data JSON to email_queue (stores contact fields for merge tag replacement)
ALTER TABLE email_queue ADD COLUMN recipient_data JSON DEFAULT NULL AFTER recipient_type;

-- 5. Custom field definitions per mailing list
CREATE TABLE IF NOT EXISTS mailing_list_custom_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id INT UNSIGNED NOT NULL,
    field_key VARCHAR(50) NOT NULL,
    field_label VARCHAR(100) NOT NULL,
    field_type ENUM('text','number','date','select') DEFAULT 'text',
    options JSON DEFAULT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_list_field (list_id, field_key),
    INDEX idx_list (list_id),
    FOREIGN KEY (list_id) REFERENCES mailing_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Add custom_fields JSON to contacts
ALTER TABLE mailing_list_contacts ADD COLUMN custom_fields JSON DEFAULT NULL AFTER notes;
