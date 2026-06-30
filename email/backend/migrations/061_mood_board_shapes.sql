-- Add 'shape' type to mood_board_items
ALTER TABLE mood_board_items
    MODIFY COLUMN type ENUM('note','image','text','link','todo_list','file','color_swatch','board_link','frame','image_set','calendar_event','drawing','table','column','folder','shape') NOT NULL;

