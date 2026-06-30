-- Add info_report_path column to servers table
ALTER TABLE servers ADD COLUMN IF NOT EXISTS info_report_path VARCHAR(255) NULL AFTER notes;

-- Create server_issues table for tracking issues from heartbeat
CREATE TABLE IF NOT EXISTS server_issues (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED NOT NULL,
    issue_type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    details JSON,
    resolved_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_server_issues_server (server_id),
    INDEX idx_server_issues_type (issue_type),
    INDEX idx_server_issues_created (created_at),
    
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

