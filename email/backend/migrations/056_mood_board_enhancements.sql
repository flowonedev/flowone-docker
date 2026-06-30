-- Add image_set and calendar_event types to mood_board_items
ALTER TABLE mood_board_items 
    MODIFY COLUMN type ENUM('note','image','text','link','todo_list','file','color_swatch','board_link','frame','image_set','calendar_event') NOT NULL;

-- Image set items (multiple images grouped together)
CREATE TABLE IF NOT EXISTS mood_board_image_set_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL COMMENT 'The parent image_set item',
    image_url VARCHAR(500) NOT NULL,
    thumbnail_url VARCHAR(500) DEFAULT NULL,
    drive_file_id INT DEFAULT NULL COMMENT 'If sourced from Drive',
    original_filename VARCHAR(255) DEFAULT NULL,
    file_size INT DEFAULT NULL,
    width_px INT DEFAULT NULL,
    height_px INT DEFAULT NULL,
    position INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item (item_id),
    FOREIGN KEY (item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add calendar event reference column
ALTER TABLE mood_board_items ADD COLUMN calendar_event_id INT DEFAULT NULL COMMENT 'Reference to calendar event' AFTER linked_card_id;

-- Add CMYK color data column for color swatches
ALTER TABLE mood_board_items ADD COLUMN color_data JSON DEFAULT NULL COMMENT 'Extended color data: hex, rgb, cmyk' AFTER color;

-- File upload tracking for mood board items
CREATE TABLE IF NOT EXISTS mood_board_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    item_id INT DEFAULT NULL COMMENT 'Links to the mood_board_item this upload is used in',
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT DEFAULT 0,
    width_px INT DEFAULT NULL,
    height_px INT DEFAULT NULL,
    uploaded_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_board (board_id),
    INDEX idx_item (item_id),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

