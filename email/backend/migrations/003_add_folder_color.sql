-- Add color column to drive_folders table
-- Enables custom folder colors

ALTER TABLE drive_folders ADD COLUMN IF NOT EXISTS color VARCHAR(20) DEFAULT NULL;

-- Color can be one of: amber, blue, green, purple, pink, red, orange, teal, slate
-- NULL means auto-detect from folder name

