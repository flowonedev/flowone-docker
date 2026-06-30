<?php

namespace Webmail\Addons\Chat\Services;

/**
 * MentionService - Parse and store @mentions from chat messages
 * 
 * Handles:
 * - @DisplayName mentions (resolve to colleague IDs)
 * - @here (notify online users in conversation)
 * - @channel (notify all members)
 * - Push notifications for mentioned users
 */
class MentionService
{
    private \PDO $db;
    private array $config;
    private ?\Webmail\Services\RedisCacheService $redis = null;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->db = \Webmail\Core\Database::getConnection($config);

        $this->ensureMentionsTable();

        try {
            $this->redis = new \Webmail\Services\RedisCacheService($config);
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }

    /**
     * Self-healing: ensure mentions table exists
     */
    private function ensureMentionsTable(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'chat_mentions'");
            if ($result->rowCount() === 0) {
                $migrationFile = __DIR__ . '/../../migrations/050_chat_mentions.sql';
                if (file_exists($migrationFile)) {
                    $sql = file_get_contents($migrationFile);
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($statements as $statement) {
                        if (!empty($statement) && !str_starts_with($statement, '--')) {
                            $this->db->exec($statement);
                        }
                    }
                    error_log("MentionService: Created chat_mentions table from migration");
                }
            }
        } catch (\PDOException $e) {
            error_log("MentionService: ensureMentionsTable failed: " . $e->getMessage());
        }
    }

    /**
     * Process mentions in a message content
     * Called after a message is stored in ChatService::sendMessage()
     */
    public function processMentions(int $messageId, int $conversationId, string $content, int $senderId): array
    {
        $mentions = $this->parseMentions($content);
        if (empty($mentions)) {
            return [];
        }

        // Get all colleagues in the conversation for resolving display names
        $stmt = $this->db->prepare('
            SELECT oc.id, oc.email, oc.display_name
            FROM chat_participants p
            JOIN organization_colleagues oc ON p.colleague_id = oc.id
            WHERE p.conversation_id = ?
        ');
        $stmt->execute([$conversationId]);
        $participants = $stmt->fetchAll();

        // Build lookup by display name (case-insensitive)
        $nameMap = [];
        foreach ($participants as $p) {
            $name = strtolower($p['display_name'] ?? '');
            if ($name) $nameMap[$name] = $p;
            // Also map by first part of email
            $emailName = strtolower(explode('@', $p['email'])[0]);
            if (!isset($nameMap[$emailName])) {
                $nameMap[$emailName] = $p;
            }
        }

        $insertStmt = $this->db->prepare('
            INSERT INTO chat_mentions (message_id, conversation_id, mentioned_colleague_id, mention_type)
            VALUES (?, ?, ?, ?)
        ');

        $notifyUserIds = [];

        foreach ($mentions as $mention) {
            if ($mention === '@here') {
                $insertStmt->execute([$messageId, $conversationId, null, 'here']);
                // Notify all online participants (except sender)
                foreach ($participants as $p) {
                    if ($p['id'] != $senderId) {
                        $notifyUserIds[] = $p['id'];
                    }
                }
            } elseif ($mention === '@channel') {
                $insertStmt->execute([$messageId, $conversationId, null, 'channel']);
                // Notify all participants (except sender)
                foreach ($participants as $p) {
                    if ($p['id'] != $senderId) {
                        $notifyUserIds[] = $p['id'];
                    }
                }
            } else {
                // @DisplayName - resolve to colleague
                $name = strtolower(ltrim($mention, '@'));
                if (isset($nameMap[$name])) {
                    $colleague = $nameMap[$name];
                    if ($colleague['id'] != $senderId) {
                        $insertStmt->execute([$messageId, $conversationId, $colleague['id'], 'user']);
                        $notifyUserIds[] = $colleague['id'];
                    }
                }
            }
        }

        // Send push notifications for mentions
        $notifyUserIds = array_unique($notifyUserIds);
        $this->sendMentionNotifications($notifyUserIds, $messageId, $conversationId, $senderId);

        return $notifyUserIds;
    }

    /**
     * Parse @mentions from message content
     * Returns array of mention strings: ['@DisplayName', '@here', '@channel']
     */
    private function parseMentions(string $content): array
    {
        $mentions = [];

        // Match @here and @channel
        if (preg_match('/\b@here\b/i', $content)) {
            $mentions[] = '@here';
        }
        if (preg_match('/\b@channel\b/i', $content)) {
            $mentions[] = '@channel';
        }

        // Match @DisplayName (alphanumeric + spaces, delimited by word boundaries)
        // Pattern: @ followed by display name (letters, numbers, spaces, dots, hyphens)
        if (preg_match_all('/@([A-Za-z][A-Za-z0-9 .\-_]{0,50}?)(?=\s|$|[,.:;!?\)\]\}])/u', $content, $matches)) {
            foreach ($matches[0] as $match) {
                $match = trim($match);
                if ($match && !in_array(strtolower($match), ['@here', '@channel'])) {
                    $mentions[] = $match;
                }
            }
        }

        return array_unique($mentions);
    }

    /**
     * Send push notifications for mentioned users
     */
    private function sendMentionNotifications(array $colleagueIds, int $messageId, int $conversationId, int $senderId): void
    {
        if (empty($colleagueIds) || !$this->redis) return;

        // Get sender info
        $stmt = $this->db->prepare('SELECT display_name, email FROM organization_colleagues WHERE id = ?');
        $stmt->execute([$senderId]);
        $sender = $stmt->fetch();
        $senderName = $sender ? ($sender['display_name'] ?: explode('@', $sender['email'])[0]) : 'Someone';

        // Get mentioned colleagues' emails for Redis broadcast
        $placeholders = implode(',', array_fill(0, count($colleagueIds), '?'));
        $stmt = $this->db->prepare("SELECT id, email FROM organization_colleagues WHERE id IN ({$placeholders})");
        $stmt->execute($colleagueIds);
        $colleagues = $stmt->fetchAll();

        foreach ($colleagues as $colleague) {
            try {
                $this->redis->publish("webmail:mailbox:{$colleague['email']}", json_encode([
                    'type' => 'CHAT_MENTION',
                    'payload' => [
                        'message_id' => $messageId,
                        'conversation_id' => $conversationId,
                        'sender_id' => $senderId,
                        'sender_name' => $senderName,
                        'mentioned_colleague_id' => $colleague['id']
                    ]
                ]));
            } catch (\Throwable $e) {
                // Continue
            }
        }
    }

    /**
     * Get all mentions for a user (paginated)
     */
    public function getMentions(string $userEmail, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare('SELECT id FROM organization_colleagues WHERE email = ? LIMIT 1');
        $stmt->execute([$userEmail]);
        $colleague = $stmt->fetch();
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $stmt = $this->db->prepare('
            SELECT m.*, cm.mention_type, cm.created_at as mentioned_at,
                   oc.display_name as sender_name, oc.email as sender_email, oc.avatar_path as sender_avatar,
                   c.name as conversation_name, c.type as conversation_type, c.slug as conversation_slug
            FROM chat_mentions cm
            JOIN chat_messages m ON cm.message_id = m.id
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            JOIN chat_conversations c ON cm.conversation_id = c.id
            WHERE (cm.mentioned_colleague_id = ? OR cm.mention_type IN ("here", "channel"))
              AND m.deleted_at IS NULL
              AND EXISTS (SELECT 1 FROM chat_participants WHERE conversation_id = cm.conversation_id AND colleague_id = ?)
            ORDER BY cm.created_at DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset . '
        ');
        $stmt->execute([$colleague['id'], $colleague['id']]);
        $mentions = $stmt->fetchAll();

        return ['success' => true, 'mentions' => $mentions];
    }

    /**
     * Get unread mention count
     */
    public function getUnreadMentionCount(string $userEmail): array
    {
        $stmt = $this->db->prepare('SELECT id FROM organization_colleagues WHERE email = ? LIMIT 1');
        $stmt->execute([$userEmail]);
        $colleague = $stmt->fetch();
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Count mentions newer than user's last read timestamp per conversation
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as cnt
            FROM chat_mentions cm
            JOIN chat_messages m ON cm.message_id = m.id
            JOIN chat_participants p ON p.conversation_id = cm.conversation_id AND p.colleague_id = ?
            WHERE (cm.mentioned_colleague_id = ? OR cm.mention_type IN ("here", "channel"))
              AND m.deleted_at IS NULL
              AND p.unread_count > 0
        ');
        $stmt->execute([$colleague['id'], $colleague['id']]);
        $result = $stmt->fetch();

        return ['success' => true, 'count' => (int)($result['cnt'] ?? 0)];
    }
}

