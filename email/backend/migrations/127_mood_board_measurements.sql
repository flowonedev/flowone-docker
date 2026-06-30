-- Measurement lines on mood boards (persisted, shared with all collaborators)
CREATE TABLE IF NOT EXISTS mood_board_measurements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    x1 FLOAT NOT NULL,
    y1 FLOAT NOT NULL,
    x2 FLOAT NOT NULL,
    y2 FLOAT NOT NULL,
    distance INT NOT NULL DEFAULT 0,
    width INT NOT NULL DEFAULT 0,
    height INT NOT NULL DEFAULT 0,
    angle FLOAT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_board (board_id),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-board measurement display settings (color, width, visibility)
ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS measure_color VARCHAR(20) DEFAULT '#0ea5e9';
ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS measure_width DECIMAL(3,1) DEFAULT 1.5;
ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS measure_visible TINYINT(1) DEFAULT 1;
