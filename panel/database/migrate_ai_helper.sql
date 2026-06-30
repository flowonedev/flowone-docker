-- AI Helper Migration
-- Run this to add AI Helper tables to existing database

USE vpsadmin;

-- =====================================================
-- AI Helper Conversations
-- =====================================================
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AI Helper Messages
-- =====================================================
CREATE TABLE IF NOT EXISTS ai_messages (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    role ENUM('user', 'assistant', 'system') NOT NULL,
    content TEXT NOT NULL,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AI Helper Cached Issues
-- =====================================================
CREATE TABLE IF NOT EXISTS ai_cached_issues (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    issue_type VARCHAR(100) NOT NULL,
    service VARCHAR(50),
    issue_key VARCHAR(255) NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    description TEXT,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    metadata JSON,
    UNIQUE KEY unique_issue (issue_type, service, issue_key),
    INDEX idx_service (service),
    INDEX idx_severity (severity),
    INDEX idx_resolved (resolved_at),
    INDEX idx_detected_at (detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AI Helper Settings
-- =====================================================
CREATE TABLE IF NOT EXISTS ai_helper_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default AI Helper settings
INSERT INTO ai_helper_settings (setting_key, setting_value) VALUES
('openai_api_key', ''),
('openai_model', 'gpt-4o'),
('max_tokens', '2000'),
('temperature', '0.3')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

