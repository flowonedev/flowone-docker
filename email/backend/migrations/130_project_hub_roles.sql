-- Migration 130: Project Hub Roles System
-- Admin-defined roles with custom statuses, plus difficulty weighting for workload

CREATE TABLE IF NOT EXISTS projecthub_roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    color VARCHAR(20) DEFAULT '#6366f1',
    icon VARCHAR(50) DEFAULT 'badge',
    description VARCHAR(500) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_slug (slug),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projecthub_role_statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    color VARCHAR(20) DEFAULT '#6b7280',
    icon VARCHAR(50) DEFAULT 'circle',
    is_terminal TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_role_status (role_id, slug),
    INDEX idx_sort (role_id, sort_order),
    FOREIGN KEY (role_id) REFERENCES projecthub_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projecthub_user_roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_role (user_email, role_id),
    INDEX idx_user (user_email),
    INDEX idx_role (role_id),
    FOREIGN KEY (role_id) REFERENCES projecthub_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add difficulty weight to card assignees for workload calculations
ALTER TABLE projecthub_card_assignees
    ADD COLUMN IF NOT EXISTS difficulty_weight TINYINT UNSIGNED DEFAULT 1;

-- Seed default roles matching the existing hardcoded statuses
INSERT IGNORE INTO projecthub_roles (name, slug, color, icon, description, sort_order, created_by) VALUES
    ('Assignee', 'assignee', '#3b82f6', 'person', 'Default task assignee', 1, 'system'),
    ('Reviewer', 'reviewer', '#f59e0b', 'rate_review', 'Reviews completed work', 2, 'system'),
    ('Observer', 'observer', '#6b7280', 'visibility', 'Watches progress without active role', 3, 'system');

-- Seed default statuses for the Assignee role
INSERT IGNORE INTO projecthub_role_statuses (role_id, name, slug, color, icon, is_terminal, sort_order)
SELECT r.id, s.name, s.slug, s.color, s.icon, s.is_terminal, s.sort_order
FROM projecthub_roles r
CROSS JOIN (
    SELECT 'Assigned' AS name, 'assigned' AS slug, '#6b7280' AS color, 'radio_button_unchecked' AS icon, 0 AS is_terminal, 1 AS sort_order
    UNION ALL SELECT 'Working', 'working', '#3b82f6', 'play_circle', 0, 2
    UNION ALL SELECT 'Review', 'review', '#f59e0b', 'rate_review', 0, 3
    UNION ALL SELECT 'Done', 'done', '#22c55e', 'check_circle', 1, 4
    UNION ALL SELECT 'Blocked', 'blocked', '#ef4444', 'block', 0, 5
) s
WHERE r.slug = 'assignee';

-- Seed default statuses for the Reviewer role
INSERT IGNORE INTO projecthub_role_statuses (role_id, name, slug, color, icon, is_terminal, sort_order)
SELECT r.id, s.name, s.slug, s.color, s.icon, s.is_terminal, s.sort_order
FROM projecthub_roles r
CROSS JOIN (
    SELECT 'Pending Review' AS name, 'pending_review' AS slug, '#f59e0b' AS color, 'hourglass_top' AS icon, 0 AS is_terminal, 1 AS sort_order
    UNION ALL SELECT 'Reviewing', 'reviewing', '#3b82f6', 'play_circle', 0, 2
    UNION ALL SELECT 'Approved', 'approved', '#22c55e', 'verified', 1, 3
    UNION ALL SELECT 'Changes Requested', 'changes_requested', '#ef4444', 'undo', 0, 4
) s
WHERE r.slug = 'reviewer';

-- Seed default statuses for the Observer role
INSERT IGNORE INTO projecthub_role_statuses (role_id, name, slug, color, icon, is_terminal, sort_order)
SELECT r.id, s.name, s.slug, s.color, s.icon, s.is_terminal, s.sort_order
FROM projecthub_roles r
CROSS JOIN (
    SELECT 'Watching' AS name, 'watching' AS slug, '#6b7280' AS color, 'visibility' AS icon, 0 AS is_terminal, 1 AS sort_order
) s
WHERE r.slug = 'observer';
