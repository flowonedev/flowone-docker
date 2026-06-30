-- Add guides JSON column to mood_boards for storing ruler guide lines
ALTER TABLE mood_boards
    ADD COLUMN IF NOT EXISTS guides JSON DEFAULT NULL COMMENT 'Array of {id, axis, position} guide lines';

-- Add artboard to the item type ENUM
ALTER TABLE mood_board_items
    MODIFY COLUMN type ENUM('note','image','text','link','todo_list','file','color_swatch','board_link','frame','image_set','calendar_event','drawing','table','column','folder','shape','pen_shape','video','youtube','line','artboard') NOT NULL;

