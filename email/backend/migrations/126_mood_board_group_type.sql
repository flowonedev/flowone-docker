-- Add 'group' to mood_board_items type column for nested grouping support
ALTER TABLE mood_board_items
MODIFY COLUMN type VARCHAR(30) NOT NULL;
