-- Mood Board Sharing & Cross-linking
-- Migration 058: Group sharing, enhanced members, board-to-board linking

-- 1. Add invited_by to mood_board_members if not present
ALTER TABLE mood_board_members
    ADD COLUMN IF NOT EXISTS invited_by VARCHAR(255) DEFAULT NULL AFTER role;

-- 2. Group-based sharing for mood boards (mirrors board_group_access pattern)
CREATE TABLE IF NOT EXISTS mood_board_group_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    role ENUM('viewer','editor') DEFAULT 'editor',
    granted_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_mood_board_group (board_id, group_id),
    INDEX idx_group (group_id),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bidirectional linking between mood boards and kanban boards
CREATE TABLE IF NOT EXISTS mood_board_board_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mood_board_id INT NOT NULL,
    kanban_board_id INT NOT NULL COMMENT 'References webmail_boards.id',
    linked_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_mood_kanban (mood_board_id, kanban_board_id),
    INDEX idx_mood (mood_board_id),
    INDEX idx_kanban (kanban_board_id),
    FOREIGN KEY (mood_board_id) REFERENCES mood_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (kanban_board_id) REFERENCES webmail_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

