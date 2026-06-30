-- Add subscription token for calendar sharing/subscription URLs
ALTER TABLE calendars ADD COLUMN IF NOT EXISTS subscription_token VARCHAR(64) DEFAULT NULL;
ALTER TABLE calendars ADD INDEX IF NOT EXISTS idx_subscription_token (subscription_token);

