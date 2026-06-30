-- Mood Boards (Milanote-style creative canvas)
-- Migration: Create mood board tables for freeform spatial canvas feature

-- Core mood board entity (the canvas itself)
CREATE TABLE IF NOT EXISTS mood_boards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_email VARCHAR(255) NOT NULL,
    client_id INT UNSIGNED DEFAULT NULL COMMENT 'Optional link to a client',
    name VARCHAR(255) NOT NULL,
    description TEXT,
    background_color VARCHAR(20) DEFAULT '#f5f5f5',
    background_image VARCHAR(500) DEFAULT NULL,
    canvas_width INT DEFAULT 4000,
    canvas_height INT DEFAULT 3000,
    zoom_level DECIMAL(4,2) DEFAULT 1.00,
    viewport_x INT DEFAULT 0,
    viewport_y INT DEFAULT 0,
    is_template TINYINT(1) DEFAULT 0,
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_email),
    INDEX idx_client (client_id),
    INDEX idx_archived (archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Items placed on the canvas (notes, images, text, links, todos, files, embeds)
CREATE TABLE IF NOT EXISTS mood_board_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    parent_id INT DEFAULT NULL COMMENT 'For grouping items inside a frame',
    type ENUM('note','image','text','link','todo_list','file','color_swatch','board_link','frame') NOT NULL,
    pos_x INT NOT NULL DEFAULT 0,
    pos_y INT NOT NULL DEFAULT 0,
    width INT DEFAULT 240,
    height INT DEFAULT NULL COMMENT 'Auto-calculated or manual',
    rotation DECIMAL(5,2) DEFAULT 0,
    z_index INT DEFAULT 0,
    locked TINYINT(1) DEFAULT 0,

    -- Content fields (used based on type)
    title VARCHAR(500) DEFAULT NULL,
    content TEXT COMMENT 'Rich text / markdown content',
    color VARCHAR(20) DEFAULT NULL COMMENT 'Card/note background color',
    url VARCHAR(2000) DEFAULT NULL COMMENT 'For link/embed types',

    -- File reference (from Drive)
    drive_file_id INT DEFAULT NULL COMMENT 'Link to drive files table',
    image_url VARCHAR(500) DEFAULT NULL COMMENT 'Uploaded or external image URL',
    thumbnail_url VARCHAR(500) DEFAULT NULL,

    -- Cross-feature link references
    linked_board_id INT DEFAULT NULL COMMENT 'Reference to a Kanban board',
    linked_card_id INT DEFAULT NULL COMMENT 'Reference to a board card',

    -- Flexible styling
    style_data JSON DEFAULT NULL COMMENT 'Font, border, shadow, opacity etc.',
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_board (board_id),
    INDEX idx_parent (parent_id),
    INDEX idx_type (type),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Todo items within a todo_list-type item
CREATE TABLE IF NOT EXISTS mood_board_todos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    text VARCHAR(500) NOT NULL,
    completed TINYINT(1) DEFAULT 0,
    position INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item (item_id),
    FOREIGN KEY (item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connections/arrows between items on the canvas
CREATE TABLE IF NOT EXISTS mood_board_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    from_item_id INT NOT NULL,
    to_item_id INT NOT NULL,
    line_style ENUM('solid','dashed','dotted') DEFAULT 'solid',
    line_color VARCHAR(20) DEFAULT '#666666',
    line_width TINYINT UNSIGNED DEFAULT 2,
    arrow_start TINYINT(1) DEFAULT 0,
    arrow_end TINYINT(1) DEFAULT 1,
    label VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_board (board_id),
    INDEX idx_from (from_item_id),
    INDEX idx_to (to_item_id),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (from_item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE,
    FOREIGN KEY (to_item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link mood boards to clients (each client can have multiple mood boards)
CREATE TABLE IF NOT EXISTS mood_board_client_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    mood_board_id INT NOT NULL,
    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_client_mood (client_id, mood_board_id),
    INDEX idx_client (client_id),
    INDEX idx_mood_board (mood_board_id),
    FOREIGN KEY (mood_board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared access / collaboration members
CREATE TABLE IF NOT EXISTS mood_board_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    role ENUM('viewer','editor','admin') DEFAULT 'editor',
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_board_member (board_id, email),
    INDEX idx_board (board_id),
    INDEX idx_email (email),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

