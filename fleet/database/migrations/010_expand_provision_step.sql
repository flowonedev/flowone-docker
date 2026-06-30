-- Expand provision_step and current_step columns to accommodate longer messages
ALTER TABLE servers MODIFY COLUMN provision_step TEXT;
ALTER TABLE deployments MODIFY COLUMN current_step TEXT;

