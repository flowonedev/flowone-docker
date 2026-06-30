-- Migration: Agent Task Queue
-- Version: 006
-- Description: Task queue for agent communication (file sync, commands, updates)

CREATE TABLE IF NOT EXISTS agent_tasks (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    type ENUM(
        'sync_files',       -- Push files to server
        'run_command',      -- Execute shell command
        'update_agent',     -- Update the fleet agent
        'restart_service',  -- Restart a service
        'pull_logs',        -- Pull log files back
        'health_check',     -- Force immediate health check
        'custom'            -- Custom task with script
    ) NOT NULL,
    priority TINYINT UNSIGNED DEFAULT 5,  -- 1=highest, 10=lowest
    payload JSON NOT NULL,
    status ENUM('pending', 'queued', 'running', 'success', 'failed', 'cancelled') DEFAULT 'pending',
    progress TINYINT UNSIGNED DEFAULT 0,
    result TEXT,
    error_message TEXT,
    retry_count TINYINT UNSIGNED DEFAULT 0,
    max_retries TINYINT UNSIGNED DEFAULT 3,
    timeout_seconds INT UNSIGNED DEFAULT 300,
    created_by INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    queued_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_server_status (server_id, status),
    INDEX idx_status_priority (status, priority, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task execution history for audit
CREATE TABLE IF NOT EXISTS agent_task_logs (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    level ENUM('info', 'warning', 'error', 'debug') DEFAULT 'info',
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES agent_tasks(id) ON DELETE CASCADE,
    INDEX idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

