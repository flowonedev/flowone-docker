-- Add campaign_id to notifications table for separating campaign tracking from regular email tracking
ALTER TABLE notifications
    ADD COLUMN campaign_id VARCHAR(36) DEFAULT NULL AFTER tracking_id,
    ADD INDEX idx_campaign_id (campaign_id);

-- Backfill campaign_id for existing notifications linked to campaign emails
UPDATE notifications n
    INNER JOIN email_tracking et ON n.tracking_id = et.tracking_id
SET n.campaign_id = et.campaign_id
WHERE et.campaign_id IS NOT NULL
  AND n.campaign_id IS NULL
  AND n.type IN ('read_receipt', 'link_click');
