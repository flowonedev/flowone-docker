-- Mood Board Folders: nested folder grouping for organizing mood boards
-- Migration: Create mood_board_folders table and add folder_id to mood_boards

CREATE TABLE IF NOT EXISTS mood_board_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_email VARCHAR(255) NOT NULL,
    parent_id INT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    color VARCHAR(20) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_email),
    INDEX idx_parent (parent_id),
    FOREIGN KEY (parent_id) REFERENCES mood_board_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add folder_id to mood_boards (SET NULL on folder delete so boards are never lost)
ALTER TABLE mood_boards
    ADD COLUMN folder_id INT DEFAULT NULL AFTER client_id,
    ADD INDEX idx_folder (folder_id),
    ADD CONSTRAINT fk_mood_boards_folder FOREIGN KEY (folder_id) REFERENCES mood_board_folders(id) ON DELETE SET NULL;
