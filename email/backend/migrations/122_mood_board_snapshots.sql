-- Board snapshots for point-in-time recovery
-- Stores full JSON of items + connections so a board can be restored

CREATE TABLE IF NOT EXISTS mood_board_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    trigger_type VARCHAR(30) NOT NULL,
    label VARCHAR(255) DEFAULT NULL,
    items_json LONGTEXT NOT NULL,
    connections_json TEXT DEFAULT NULL,
    item_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_snap_board (board_id),
    INDEX idx_snap_created (created_at),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
