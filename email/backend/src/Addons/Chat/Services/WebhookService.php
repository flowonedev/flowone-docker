<?php

namespace Webmail\Addons\Chat\Services;

/**
 * WebhookService - Incoming Webhook Management for Chat
 * 
 * Handles:
 * - Creating/deleting incoming webhooks for conversations
 * - Token-based message posting (no auth required)
 * - Webhook message formatting
 */
class WebhookService
{
    private \PDO $db;
    private array $config;
    private ?ChatService $chatService = null;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->db = \Webmail\Core\Database::getConnection($config);

        $this->ensureWebhooksTable();
    }

    private function getChatService(): ChatService
    {
        if (!$this->chatService) {
            $this->chatService = new ChatService($this->config);
        }
        return $this->chatService;
    }

    /**
     * Self-healing: ensure webhooks table exists
     */
    private function ensureWebhooksTable(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'chat_webhooks'");
            if ($result->rowCount() === 0) {
                $migrationFile = __DIR__ . '/../../migrations/051_chat_webhooks.sql';
                if (file_exists($migrationFile)) {
                    $sql = file_get_contents($migrationFile);
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($statements as $statement) {
                        if (!empty($statement) && !str_starts_with($statement, '--')) {
                            $this->db->exec($statement);
                        }
                    }
                    error_log("WebhookService: Created chat_webhooks table from migration");
                } else {
                    // Inline creation fallback
                    $this->db->exec("
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
                            INDEX idx_webhook_token (token),
                            INDEX idx_webhook_conversation (conversation_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    error_log("WebhookService: Created chat_webhooks table inline");
                }
            }
        } catch (\PDOException $e) {
            error_log("WebhookService: ensureWebhooksTable failed: " . $e->getMessage());
        }
    }

    /**
     * Generate a secure random token
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get colleague by email
     */
    private function getColleague(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organization_colleagues WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    // ========================================
    // WEBHOOK CRUD
    // ========================================

    /**
     * Create a new incoming webhook for a conversation
     */
    public function createWebhook(string $userEmail, int $conversationId, string $name, ?string $avatarUrl = null): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Verify user is a participant (and ideally admin)
        $stmt = $this->db->prepare('SELECT is_admin FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        $membership = $stmt->fetch();

        if (!$membership) {
            return ['success' => false, 'error' => 'Not a member of this conversation'];
        }

        $name = trim($name) ?: 'Webhook';
        if (strlen($name) > 100) {
            return ['success' => false, 'error' => 'Name must be 100 characters or less'];
        }

        $token = $this->generateToken();

        try {
            $stmt = $this->db->prepare('
                INSERT INTO chat_webhooks (conversation_id, creator_id, name, avatar_url, token)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$conversationId, $colleague['id'], $name, $avatarUrl, $token]);
            $webhookId = (int)$this->db->lastInsertId();

            $baseUrl = $this->config['app']['url'] ?? 'https://flowone.pro';

            return [
                'success' => true,
                'webhook' => [
                    'id' => $webhookId,
                    'conversation_id' => $conversationId,
                    'name' => $name,
                    'avatar_url' => $avatarUrl,
                    'token' => $token,
                    'webhook_url' => rtrim($baseUrl, '/') . '/api/webhook/' . $token,
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            ];
        } catch (\PDOException $e) {
            error_log("WebhookService::createWebhook error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create webhook'];
        }
    }

    /**
     * List all webhooks for conversations the user is a member of
     */
    public function listWebhooks(string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $baseUrl = $this->config['app']['url'] ?? 'https://flowone.pro';

        $stmt = $this->db->prepare('
            SELECT w.*, c.name as conversation_name, c.type as conversation_type,
                   oc.display_name as creator_name, oc.email as creator_email
            FROM chat_webhooks w
            JOIN chat_conversations c ON w.conversation_id = c.id
            JOIN organization_colleagues oc ON w.creator_id = oc.id
            WHERE w.conversation_id IN (
                SELECT conversation_id FROM chat_participants WHERE colleague_id = ?
            )
            ORDER BY w.created_at DESC
        ');
        $stmt->execute([$colleague['id']]);
        $webhooks = $stmt->fetchAll();

        foreach ($webhooks as &$w) {
            $w['webhook_url'] = rtrim($baseUrl, '/') . '/api/webhook/' . $w['token'];
            $w['is_active'] = (bool)$w['is_active'];
        }

        return ['success' => true, 'webhooks' => $webhooks];
    }

    /**
     * Delete a webhook
     */
    public function deleteWebhook(int $webhookId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Only the creator or a conversation admin can delete
        $stmt = $this->db->prepare('
            SELECT w.*, p.is_admin
            FROM chat_webhooks w
            JOIN chat_participants p ON p.conversation_id = w.conversation_id AND p.colleague_id = ?
            WHERE w.id = ?
        ');
        $stmt->execute([$colleague['id'], $webhookId]);
        $webhook = $stmt->fetch();

        if (!$webhook) {
            return ['success' => false, 'error' => 'Webhook not found or no access'];
        }

        if ($webhook['creator_id'] != $colleague['id'] && !$webhook['is_admin']) {
            return ['success' => false, 'error' => 'Only the creator or an admin can delete this webhook'];
        }

        $stmt = $this->db->prepare('DELETE FROM chat_webhooks WHERE id = ?');
        $stmt->execute([$webhookId]);

        return ['success' => true];
    }

    // ========================================
    // INCOMING WEBHOOK MESSAGE
    // ========================================

    /**
     * Receive a message via webhook token (no auth required)
     */
    public function receiveMessage(string $token, array $payload): array
    {
        // Look up webhook by token
        $stmt = $this->db->prepare('SELECT * FROM chat_webhooks WHERE token = ? AND is_active = 1');
        $stmt->execute([$token]);
        $webhook = $stmt->fetch();

        if (!$webhook) {
            return ['success' => false, 'error' => 'Invalid or inactive webhook'];
        }

        $text = $payload['text'] ?? $payload['content'] ?? $payload['message'] ?? '';
        if (empty(trim($text))) {
            return ['success' => false, 'error' => 'Message text is required'];
        }

        // Optional: override the webhook name per-message
        $username = $payload['username'] ?? $webhook['name'];

        // Format the content with webhook attribution
        $content = $text;

        try {
            // Use the webhook creator as the sender, but prefix with webhook name
            $chatService = $this->getChatService();

            // Insert message directly (webhook messages appear as system-like)
            $stmt = $this->db->prepare("
                INSERT INTO chat_messages (conversation_id, sender_id, content, content_type, created_at)
                VALUES (?, ?, ?, 'text', NOW())
            ");
            $stmt->execute([$webhook['conversation_id'], $webhook['creator_id'], "[webhook:{$username}] {$content}"]);
            $messageId = (int)$this->db->lastInsertId();

            // Update last_used_at
            $stmt = $this->db->prepare('UPDATE chat_webhooks SET last_used_at = NOW() WHERE id = ?');
            $stmt->execute([$webhook['id']]);

            // Update conversation message count and last_message_at
            $stmt = $this->db->prepare('
                UPDATE chat_conversations 
                SET message_count = message_count + 1, last_message_at = NOW() 
                WHERE id = ?
            ');
            $stmt->execute([$webhook['conversation_id']]);

            // Broadcast via Redis
            $this->broadcastWebhookMessage($webhook, $messageId, $username, $content);

            return [
                'success' => true,
                'message_id' => $messageId
            ];
        } catch (\PDOException $e) {
            error_log("WebhookService::receiveMessage error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to post message'];
        }
    }

    /**
     * Broadcast webhook message to conversation participants
     */
    private function broadcastWebhookMessage(array $webhook, int $messageId, string $username, string $content): void
    {
        try {
            $redis = new \Webmail\Services\RedisCacheService($this->config);
        } catch (\Throwable $e) {
            return;
        }

        $stmt = $this->db->prepare('
            SELECT oc.email FROM chat_participants p
            JOIN organization_colleagues oc ON p.colleague_id = oc.id
            WHERE p.conversation_id = ?
        ');
        $stmt->execute([$webhook['conversation_id']]);
        $participants = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($participants as $email) {
            try {
                $redis->publish("webmail:mailbox:{$email}", json_encode([
                    'type' => 'CHAT_NEW_MESSAGE',
                    'payload' => [
                        'conversation_id' => $webhook['conversation_id'],
                        'message' => [
                            'id' => $messageId,
                            'conversation_id' => $webhook['conversation_id'],
                            'sender_id' => $webhook['creator_id'],
                            'content' => "[webhook:{$username}] {$content}",
                            'message_type' => 'text',
                            'is_webhook' => true,
                            'webhook_name' => $username,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]
                    ]
                ]));
            } catch (\Throwable $e) {
                // Continue
            }
        }
    }
}

