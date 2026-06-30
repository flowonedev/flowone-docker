-- Migration 104: Automation Hub tables
-- Visual workflow automation engine

-- Workflow definitions
CREATE TABLE IF NOT EXISTS automation_hub_workflows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 0,
    category VARCHAR(50) DEFAULT 'custom',
    canvas_data JSON DEFAULT NULL,
    run_count INT UNSIGNED DEFAULT 0,
    last_run_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_email),
    INDEX idx_active (is_active),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nodes within a workflow
CREATE TABLE IF NOT EXISTS automation_hub_nodes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT UNSIGNED NOT NULL,
    node_uid VARCHAR(36) NOT NULL,
    node_type VARCHAR(100) NOT NULL,
    node_category ENUM('trigger','action','logic') NOT NULL,
    label VARCHAR(255) DEFAULT NULL,
    config JSON DEFAULT NULL,
    position_x FLOAT DEFAULT 0,
    position_y FLOAT DEFAULT 0,
    meta JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES automation_hub_workflows(id) ON DELETE CASCADE,
    INDEX idx_workflow (workflow_id),
    UNIQUE KEY uk_workflow_uid (workflow_id, node_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Edges (connections) between nodes
CREATE TABLE IF NOT EXISTS automation_hub_edges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT UNSIGNED NOT NULL,
    source_node_uid VARCHAR(36) NOT NULL,
    target_node_uid VARCHAR(36) NOT NULL,
    source_port VARCHAR(50) DEFAULT 'output',
    target_port VARCHAR(50) DEFAULT 'input',
    edge_style ENUM('solid','dashed') DEFAULT 'solid',
    FOREIGN KEY (workflow_id) REFERENCES automation_hub_workflows(id) ON DELETE CASCADE,
    INDEX idx_workflow (workflow_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Workflow execution history
CREATE TABLE IF NOT EXISTS automation_hub_executions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT UNSIGNED NOT NULL,
    trigger_node_uid VARCHAR(36) DEFAULT NULL,
    status ENUM('running','completed','failed','cancelled') DEFAULT 'running',
    trigger_data JSON DEFAULT NULL,
    is_test TINYINT(1) DEFAULT 0,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    FOREIGN KEY (workflow_id) REFERENCES automation_hub_workflows(id) ON DELETE CASCADE,
    INDEX idx_workflow_status (workflow_id, status),
    INDEX idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-node execution trace
CREATE TABLE IF NOT EXISTS automation_hub_node_executions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    node_uid VARCHAR(36) NOT NULL,
    status ENUM('pending','running','completed','failed','skipped') DEFAULT 'pending',
    input_data JSON DEFAULT NULL,
    output_data JSON DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    FOREIGN KEY (execution_id) REFERENCES automation_hub_executions(id) ON DELETE CASCADE,
    INDEX idx_execution (execution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telegram bot configurations
CREATE TABLE IF NOT EXISTS automation_hub_telegram_bots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    bot_token VARCHAR(255) NOT NULL,
    bot_username VARCHAR(100) DEFAULT NULL,
    default_chat_id VARCHAR(100) DEFAULT NULL,
    webhook_secret VARCHAR(64) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delayed execution queue (for Delay nodes)
CREATE TABLE IF NOT EXISTS automation_hub_delayed_executions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    resume_node_uid VARCHAR(36) NOT NULL,
    resume_at DATETIME NOT NULL,
    input_data JSON DEFAULT NULL,
    is_processed TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES automation_hub_executions(id) ON DELETE CASCADE,
    INDEX idx_resume (is_processed, resume_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
