-- Add 'drawing' type to mood_board_items
-- Drawings store vector path data as JSON in 'content' field
-- and a rendered PNG image URL in 'image_url' for display

ALTER TABLE mood_board_items 
    MODIFY COLUMN type ENUM('note','image','text','link','todo_list','file','color_swatch','board_link','frame','image_set','calendar_event','drawing') NOT NULL;

