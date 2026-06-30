-- Add block tracking columns to email_link_tracking for per-block engagement analytics
ALTER TABLE email_link_tracking
    ADD COLUMN block_id VARCHAR(36) DEFAULT NULL AFTER link_index,
    ADD COLUMN block_type VARCHAR(50) DEFAULT NULL AFTER block_id,
    ADD COLUMN block_name VARCHAR(100) DEFAULT NULL AFTER block_type,
    ADD INDEX idx_block_id (block_id);
