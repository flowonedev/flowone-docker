-- Migration 076: User palettes - shareable color & gradient palettes across moodboards
-- Users can save named palettes and share them with other boards/users

CREATE TABLE IF NOT EXISTS mood_board_user_palettes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL COMMENT 'Owner email',
    name VARCHAR(100) NOT NULL DEFAULT 'Untitled Palette',
    colors JSON DEFAULT NULL COMMENT 'Array of hex color strings',
    gradients JSON DEFAULT NULL COMMENT 'Array of gradient objects {type, angle, stops}',
    is_shared TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = visible to colleagues on same domain',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_shared (is_shared, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

