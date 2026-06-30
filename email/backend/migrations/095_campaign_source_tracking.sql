-- Track where campaigns originated from (manual compose, automation, sequence)
ALTER TABLE email_campaigns
    ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS source_id VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS parent_campaign_id VARCHAR(36) DEFAULT NULL;
