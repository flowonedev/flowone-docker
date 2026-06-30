-- Migration: Server Update Reports
-- Version: 025
-- Description: Per-server pending OS/npm update reports (fed by agent heartbeat)
--              and the update_packages task type for applying them remotely.

CREATE TABLE IF NOT EXISTS server_updates (
    server_id INT UNSIGNED PRIMARY KEY,
    os_pending INT UNSIGNED NOT NULL DEFAULT 0,
    npm_pending INT UNSIGNED NOT NULL DEFAULT 0,
    reboot_required TINYINT(1) NOT NULL DEFAULT 0,
    payload JSON NOT NULL,
    checked_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New task type for applying updates from the panel
ALTER TABLE agent_tasks MODIFY COLUMN type ENUM(
    'sync_files',
    'run_command',
    'update_agent',
    'restart_service',
    'pull_logs',
    'health_check',
    'custom',
    'update_packages'
) NOT NULL;
