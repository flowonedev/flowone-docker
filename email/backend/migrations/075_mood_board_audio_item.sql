-- Add 'audio' and 'slide' to mood_board_items type ENUM
ALTER TABLE mood_board_items 
MODIFY COLUMN type ENUM('note','image','text','link','todo_list','file','color_swatch','board_link','frame','image_set','calendar_event','drawing','table','column','folder','shape','pen_shape','video','youtube','line','artboard','audio','slide') NOT NULL;

