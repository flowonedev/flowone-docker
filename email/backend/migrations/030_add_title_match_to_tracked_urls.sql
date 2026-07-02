-- Migration 030: Add title_match column to board_tracked_urls
-- This allows users to specify comma-separated keywords to match in browser window titles
-- IF NOT EXISTS: fresh installs get the column from the rewritten 012, so this
-- must be a no-op there instead of failing forever with "duplicate column".

ALTER TABLE board_tracked_urls
ADD COLUMN IF NOT EXISTS title_match VARCHAR(500) DEFAULT NULL
AFTER display_name;

-- Example usage:
-- Domain: mercedes-benz.ro
-- Title Match: România, Romania, Romanian
-- This will match if the browser title contains any of these words

