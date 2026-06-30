-- Saved component blocks (reusable groups of items)
CREATE TABLE IF NOT EXISTS mood_board_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT 'Untitled Component',
    description TEXT DEFAULT NULL,
    thumbnail_url VARCHAR(500) DEFAULT NULL,
    items_data JSON NOT NULL COMMENT 'Array of item definitions (positions, styles, content)',
    is_global TINYINT(1) DEFAULT 0 COMMENT 'If 1, shared with all team members',
    category VARCHAR(100) DEFAULT 'custom' COMMENT 'Category: custom, button, card, layout, etc.',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_email),
    INDEX idx_category (category),
    INDEX idx_global (is_global)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

