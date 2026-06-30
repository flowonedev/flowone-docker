-- Add background settings columns to boards table
-- Enables background blur and overlay effects

ALTER TABLE webmail_boards ADD COLUMN IF NOT EXISTS background_blur INT DEFAULT 0;
ALTER TABLE webmail_boards ADD COLUMN IF NOT EXISTS background_overlay_color VARCHAR(20) DEFAULT NULL;
ALTER TABLE webmail_boards ADD COLUMN IF NOT EXISTS background_overlay_opacity INT DEFAULT 0;

