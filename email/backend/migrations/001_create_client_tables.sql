-- Client Overview Tables
-- Migration: Create clients and client_contacts tables for Client Overview feature

-- Core client entity (domain-based)
-- Clients are derived from email domains, not manually created
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL COMMENT 'The webmail user who owns this client record',
    domain VARCHAR(255) NOT NULL COMMENT 'Email domain that identifies this client',
    display_name VARCHAR(255) DEFAULT NULL COMMENT 'Optional custom display name (auto-derived if null)',
    status ENUM('active', 'waiting', 'attention') DEFAULT 'active' COMMENT 'Computed status: active=progressing, waiting=awaiting response, attention=overdue/stalled',
    last_activity_at DATETIME DEFAULT NULL COMMENT 'Most recent activity (email, task update, etc.)',
    last_email_direction ENUM('inbound', 'outbound') DEFAULT NULL COMMENT 'Direction of last email for responsibility tracking',
    open_task_count INT DEFAULT 0 COMMENT 'Cached count of open tasks',
    overdue_task_count INT DEFAULT 0 COMMENT 'Cached count of overdue tasks',
    next_deadline DATE DEFAULT NULL COMMENT 'Cached next upcoming deadline',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_email (user_email),
    INDEX idx_domain (domain),
    INDEX idx_status (status),
    INDEX idx_last_activity (last_activity_at),
    UNIQUE KEY unique_user_domain (user_email, domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual contacts within a client (domain)
-- Tracks each unique email address associated with the client
CREATE TABLE IF NOT EXISTS client_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    email VARCHAR(255) NOT NULL COMMENT 'Individual email address',
    name VARCHAR(255) DEFAULT NULL COMMENT 'Contact name if known',
    last_email_at DATETIME DEFAULT NULL COMMENT 'Most recent email from/to this contact',
    email_count INT DEFAULT 0 COMMENT 'Total emails exchanged with this contact',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_client_id (client_id),
    INDEX idx_email (email),
    UNIQUE KEY unique_client_email (client_id, email),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Client-Board linking table
-- Links clients to boards for task aggregation
CREATE TABLE IF NOT EXISTS client_boards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    board_id INT NOT NULL,
    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_client_board (client_id, board_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

