CREATE TABLE IF NOT EXISTS card_asset_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_id INT NOT NULL,
    parent_id INT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    drive_folder_id INT DEFAULT NULL,
    position INT DEFAULT 0,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_card_id (card_id),
    INDEX idx_parent_id (parent_id),
    FOREIGN KEY (card_id) REFERENCES webmail_board_cards(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES card_asset_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE webmail_card_attachments
    ADD COLUMN IF NOT EXISTS folder_id INT DEFAULT NULL AFTER created_by;
