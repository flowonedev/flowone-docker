-- Migration 066: Separate presentation slides from layout frames
-- Rename existing 'frame' items to 'slide' (they were all presentation camera views)
-- After this, 'frame' = Figma-style layout container, 'slide' = presentation camera view

-- Step 1: Add 'slide' to the type ENUM
ALTER TABLE mood_board_items
    MODIFY COLUMN type ENUM('note','image','text','link','todo_list','file','color_swatch','board_link','frame','image_set','calendar_event','drawing','table','column','folder','shape','pen_shape','video','youtube','line','artboard','slide') NOT NULL;

-- Step 2: Convert existing frames (which were presentation camera views) to slides
UPDATE mood_board_items
SET type = 'slide'
WHERE type = 'frame';

