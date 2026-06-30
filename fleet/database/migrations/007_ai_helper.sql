-- AI Helper Tables for Fleet Manager
-- Stores conversations, messages, and settings for AI-powered config/log analysis

-- AI Conversations
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) DEFAULT 'New Conversation',
    context_type ENUM('general', 'config', 'logs', 'deployment') DEFAULT 'general',
    context_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_user_conversations (user_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Messages
CREATE TABLE IF NOT EXISTS ai_messages (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    role ENUM('user', 'assistant', 'system') NOT NULL,
    content TEXT NOT NULL,
    tokens_used INT UNSIGNED DEFAULT 0,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
    INDEX idx_conversation_messages (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Helper Settings
CREATE TABLE IF NOT EXISTS ai_helper_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Cached Issues (for tracking identified issues)
CREATE TABLE IF NOT EXISTS ai_cached_issues (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    server_id INT UNSIGNED,
    service VARCHAR(50),
    issue_type ENUM('error', 'warning', 'security', 'performance') DEFAULT 'error',
    title VARCHAR(255) NOT NULL,
    description TEXT,
    suggested_fix TEXT,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    resolved TINYINT(1) DEFAULT 0,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_server_issues (server_id, resolved, created_at DESC),
    INDEX idx_service_issues (service, resolved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO ai_helper_settings (setting_key, setting_value) VALUES
    ('openai_api_key', ''),
    ('openai_model', 'gpt-4o'),
    ('max_tokens', '4000'),
    ('temperature', '0.3'),
    ('response_language', 'en')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

