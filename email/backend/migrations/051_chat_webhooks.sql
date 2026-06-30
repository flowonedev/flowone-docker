-- Chat Webhooks: Incoming webhooks for posting messages to channels/conversations
CREATE TABLE IF NOT EXISTS chat_webhooks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    creator_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL DEFAULT 'Webhook',
    avatar_url VARCHAR(500) DEFAULT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (creator_id) REFERENCES organization_colleagues(id) ON DELETE CASCADE,
    INDEX idx_webhook_token (token),
    INDEX idx_webhook_conversation (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

