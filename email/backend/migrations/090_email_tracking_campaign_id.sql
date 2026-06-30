-- Add campaign_id to email_tracking so tracking records can be linked to campaigns
ALTER TABLE email_tracking
    ADD COLUMN campaign_id VARCHAR(36) DEFAULT NULL AFTER tracking_id,
    ADD INDEX idx_campaign_id (campaign_id);
