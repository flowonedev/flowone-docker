-- Migration 012: Board Tracked URLs for website time tracking
-- This table stores website domains that should be tracked for time when visited
--
-- NOTE (fresh installs): the original version declared
--   FOREIGN KEY (board_id) REFERENCES boards(id)
-- but no `boards` table exists — kanban boards live in `webmail_boards`
-- (created lazily by BoardService). On a fresh box that FK made this migration
-- fail with errno 150 on EVERY request (and 030 with it) forever. Production
-- already recorded 012 as applied against a legacy `boards` table, so this
-- rewrite only affects new installs. The FK is dropped entirely: board_id is
-- indexed, and board deletion cleanup is handled at the application layer.

CREATE TABLE IF NOT EXISTS board_tracked_urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    url_domain VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_board_url (board_id, url_domain),
    INDEX idx_board (board_id),
    INDEX idx_client (client_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
