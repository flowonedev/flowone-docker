-- 192: Board cards schema repair.
--
-- Fresh (fleet-deployed) servers never get the webmail_board_cards ALTERs
-- from migrations 107/139/142/148/154 because the table itself was created
-- lazily by BoardService::ensureTablesExist() AFTER install-time migrations
-- ran, and parent_card_id was only ever added by ProjectHubService's lazy
-- ensureSchema(). Result: "Unknown column 'c.parent_card_id'" in My Work.
--
-- This migration makes the schema deterministic:
--   1. Creates the boards -> lists -> cards chain (FK dependency order)
--      with the FULL current schema, so a fresh database is complete at
--      migration time.
--   2. Guarded ALTERs repair servers where the table already exists
--      without the drifted columns.

CREATE TABLE IF NOT EXISTS webmail_boards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    background_color VARCHAR(20) DEFAULT '#1e1e26',
    background_image VARCHAR(500) DEFAULT NULL,
    background_blur INT DEFAULT 0,
    background_overlay_color VARCHAR(20) DEFAULT NULL,
    background_overlay_opacity INT DEFAULT 0,
    client_id INT UNSIGNED DEFAULT NULL,
    payment_terms_days INT DEFAULT NULL,
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_email),
    INDEX idx_archived (archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webmail_board_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    position INT DEFAULT 0,
    collapsed TINYINT(1) NOT NULL DEFAULT 0,
    expected_amount DECIMAL(12,2) DEFAULT NULL,
    invoice_date DATE DEFAULT NULL,
    is_milestone TINYINT(1) DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'HUF',
    payment_status VARCHAR(20) DEFAULT 'unpaid',
    list_color VARCHAR(7) DEFAULT NULL,
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_board_id (board_id),
    INDEX idx_position (position),
    FOREIGN KEY (board_id) REFERENCES webmail_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webmail_board_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    list_id INT NOT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT,
    position INT DEFAULT 0,
    due_date DATETIME DEFAULT NULL,
    start_date DATETIME DEFAULT NULL,
    completed TINYINT(1) DEFAULT 0,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    cover_color VARCHAR(20) DEFAULT NULL,
    card_color VARCHAR(7) DEFAULT NULL,
    cover_image_id INT DEFAULT NULL,
    calendar_event_id INT DEFAULT NULL,
    created_by VARCHAR(255),
    assigned_to VARCHAR(255) DEFAULT NULL,
    archived TINYINT(1) DEFAULT 0,
    parent_card_id INT DEFAULT NULL,
    time_estimate_seconds INT UNSIGNED DEFAULT NULL,
    time_budget_alert_sent TINYINT(1) NOT NULL DEFAULT 0,
    full_task_visibility TINYINT(1) NOT NULL DEFAULT 0,
    simulation_run_id VARCHAR(16) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_list_id (list_id),
    INDEX idx_position (position),
    INDEX idx_due_date (due_date),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_parent_card (parent_card_id),
    INDEX idx_webmail_board_cards_sim_run (simulation_run_id),
    FOREIGN KEY (list_id) REFERENCES webmail_board_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repair existing installations that predate the columns above.
ALTER TABLE webmail_board_cards ADD COLUMN IF NOT EXISTS card_color VARCHAR(7) DEFAULT NULL AFTER cover_color;
ALTER TABLE webmail_board_cards ADD COLUMN IF NOT EXISTS parent_card_id INT DEFAULT NULL;
ALTER TABLE webmail_board_cards ADD COLUMN IF NOT EXISTS time_estimate_seconds INT UNSIGNED DEFAULT NULL;
ALTER TABLE webmail_board_cards ADD COLUMN IF NOT EXISTS time_budget_alert_sent TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE webmail_board_cards ADD COLUMN IF NOT EXISTS full_task_visibility TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE webmail_board_cards ADD COLUMN IF NOT EXISTS simulation_run_id VARCHAR(16) NULL;
ALTER TABLE webmail_board_cards ADD INDEX IF NOT EXISTS idx_parent_card (parent_card_id);
ALTER TABLE webmail_board_cards ADD INDEX IF NOT EXISTS idx_webmail_board_cards_sim_run (simulation_run_id);

ALTER TABLE webmail_boards ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE webmail_boards ADD COLUMN IF NOT EXISTS payment_terms_days INT DEFAULT NULL;
