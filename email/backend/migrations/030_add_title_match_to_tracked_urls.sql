-- Migration 030: Add title_match column to board_tracked_urls
-- This allows users to specify comma-separated keywords to match in browser window titles

ALTER TABLE board_tracked_urls 
ADD COLUMN title_match VARCHAR(500) DEFAULT NULL 
AFTER display_name;

-- Example usage:
-- Domain: mercedes-benz.ro
-- Title Match: România, Romania, Romanian
-- This will match if the browser title contains any of these words

