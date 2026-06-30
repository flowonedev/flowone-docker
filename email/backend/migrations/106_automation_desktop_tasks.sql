-- Desktop task queue for automation hub
-- Allows the VPS backend to dispatch tasks to desktop apps (FlowOneEmail/FlowOneDrive)
-- and receive results back asynchronously via WebSocket + HTTP callback.

CREATE TABLE IF NOT EXISTS automation_desktop_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_type VARCHAR(50) NOT NULL COMMENT 'printer_list, printer_print, etc.',
    payload JSON NOT NULL COMMENT 'Task config/parameters',
    status ENUM('pending','processing','completed','failed','timeout') DEFAULT 'pending',
    result JSON NULL COMMENT 'Response from desktop app',
    workflow_execution_id INT NULL COMMENT 'Associated workflow execution',
    node_uid VARCHAR(64) NULL COMMENT 'Workflow node that triggered this task',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_user_status (user_id, status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
