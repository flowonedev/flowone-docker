<?php

namespace Webmail\Addons\Chat\Services;

/**
 * ChannelService - Public/Private Channel Management
 * 
 * Channels extend the existing group chat system with:
 * - Public channels (browsable, self-join)
 * - Private channels (invite-only)
 * - Topic and purpose
 * - Default channels (auto-join new members)
 * - Slug-based naming (#channel-name)
 */
class ChannelService
{
    private \PDO $db;
    private array $config;
    private ?ChatService $chatService = null;
    private ?\Webmail\Addons\Team\Services\ColleagueService $colleagueService = null;
    private ?\Webmail\Services\RedisCacheService $redis = null;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->db = \Webmail\Core\Database::getConnection($config);

        $this->ensureChannelColumns();

        try {
            $this->redis = new \Webmail\Services\RedisCacheService($config);
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }

    private function getChatService(): ChatService
    {
        if (!$this->chatService) {
            $this->chatService = new ChatService($this->config);
        }
        return $this->chatService;
    }

    private function getColleagueService(): \Webmail\Addons\Team\Services\ColleagueService
    {
        if (!$this->colleagueService) {
            $this->colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
        }
        return $this->colleagueService;
    }

    /**
     * Self-healing: ensure channel columns exist on chat_conversations
     */
    private function ensureChannelColumns(): void
    {
        try {
            // Check if type ENUM includes 'channel'
            $result = $this->db->query("SHOW COLUMNS FROM chat_conversations WHERE Field = 'type'");
            $col = $result->fetch(\PDO::FETCH_ASSOC);
            if ($col && stripos($col['Type'] ?? '', "'channel'") === false) {
                $this->db->exec("ALTER TABLE chat_conversations MODIFY COLUMN type ENUM('direct','group','channel') NOT NULL DEFAULT 'direct'");
                error_log("ChannelService: Added 'channel' to type ENUM");
            }

            // Add missing columns
            $columns = [
                'is_public'   => "ADD COLUMN is_public TINYINT(1) DEFAULT 1",
                'slug'        => "ADD COLUMN slug VARCHAR(100) DEFAULT NULL",
                'topic'       => "ADD COLUMN topic VARCHAR(500) DEFAULT NULL",
                'purpose'     => "ADD COLUMN purpose TEXT DEFAULT NULL",
                'is_default'  => "ADD COLUMN is_default TINYINT(1) DEFAULT 0",
                'category_id' => "ADD COLUMN category_id INT UNSIGNED NULL",
                'position'    => "ADD COLUMN position INT UNSIGNED DEFAULT 0",
            ];

            foreach ($columns as $name => $ddl) {
                $check = $this->db->query("SHOW COLUMNS FROM chat_conversations LIKE '{$name}'");
                if ($check->rowCount() === 0) {
                    $this->db->exec("ALTER TABLE chat_conversations {$ddl}");
                    error_log("ChannelService: Added column {$name}");
                }
            }

            // Add unique index on slug if not exists
            try {
                $indexes = $this->db->query("SHOW INDEX FROM chat_conversations WHERE Key_name = 'idx_channel_slug'");
                if ($indexes->rowCount() === 0) {
                    $this->db->exec("CREATE UNIQUE INDEX idx_channel_slug ON chat_conversations (organization_domain, slug)");
                }
            } catch (\PDOException $e) {
                // Index might already exist
            }
        } catch (\PDOException $e) {
            error_log("ChannelService: ensureChannelColumns failed: " . $e->getMessage());
        }
    }

    /**
     * Slugify a channel name: lowercase, alphanumeric + hyphens
     */
    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Get colleague by email
     */
    private function getColleague(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organization_colleagues WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower($email)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get domain from email
     */
    private function getDomain(string $email): string
    {
        return strtolower(substr($email, strpos($email, '@') + 1));
    }

    /**
     * Broadcast event to all participants of a conversation via Redis
     */
    private function broadcastToConversation(int $conversationId, string $eventType, array $payload): void
    {
        if (!$this->redis) return;

        $stmt = $this->db->prepare('
            SELECT oc.email FROM chat_participants p
            JOIN organization_colleagues oc ON p.colleague_id = oc.id
            WHERE p.conversation_id = ?
        ');
        $stmt->execute([$conversationId]);
        $participants = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($participants as $email) {
            try {
                $this->redis->publish("webmail:mailbox:{$email}", json_encode([
                    'type' => $eventType,
                    'payload' => $payload
                ]));
            } catch (\Throwable $e) {
                // Continue broadcasting
            }
        }
    }

    /**
     * Broadcast to all domain members (for channel discovery events)
     */
    private function broadcastToDomain(string $domain, string $eventType, array $payload): void
    {
        if (!$this->redis) return;

        $stmt = $this->db->prepare('SELECT email FROM organization_colleagues WHERE organization_domain = ?');
        $stmt->execute([$domain]);
        $emails = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($emails as $email) {
            try {
                $this->redis->publish("webmail:mailbox:{$email}", json_encode([
                    'type' => $eventType,
                    'payload' => $payload
                ]));
            } catch (\Throwable $e) {
                // Continue
            }
        }
    }

    // ========================================
    // CHANNEL CRUD
    // ========================================

    /**
     * Create a new channel
     */
    public function createChannel(string $creatorEmail, string $name, bool $isPublic = true, ?string $topic = null, ?string $purpose = null, bool $isDefault = false, ?int $categoryId = null): array
    {
        error_log("ChannelService::createChannel called for: {$creatorEmail}, name: {$name}");

        $creator = $this->getColleague($creatorEmail);
        if (!$creator) {
            error_log("ChannelService::createChannel - User not found: {$creatorEmail}");
            return ['success' => false, 'error' => 'User not found: ' . $creatorEmail];
        }

        $name = trim($name);
        if (empty($name) || strlen($name) > 100) {
            return ['success' => false, 'error' => 'Channel name must be 1-100 characters'];
        }

        $slug = $this->slugify($name);
        if (empty($slug)) {
            return ['success' => false, 'error' => 'Invalid channel name (slug empty after sanitize)'];
        }

        $domain = $creator['organization_domain'];
        error_log("ChannelService::createChannel - domain: {$domain}, slug: {$slug}, creator_id: {$creator['id']}");

        // Check slug uniqueness
        $stmt = $this->db->prepare('SELECT id FROM chat_conversations WHERE organization_domain = ? AND slug = ?');
        $stmt->execute([$domain, $slug]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'A channel with this name already exists (slug: #' . $slug . ')'];
        }

        try {
            $this->db->beginTransaction();

            // Create conversation as channel
            $stmt = $this->db->prepare("
                INSERT INTO chat_conversations (organization_domain, type, name, description, slug, topic, purpose, is_public, is_default, created_by, category_id)
                VALUES (?, 'channel', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $domain,
                $name,
                $purpose,
                $slug,
                $topic,
                $purpose,
                $isPublic ? 1 : 0,
                $isDefault ? 1 : 0,
                $creator['id'],
                $categoryId,
            ]);
            $channelId = (int)$this->db->lastInsertId();

            // Add creator as admin member
            $stmt = $this->db->prepare('
                INSERT INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                VALUES (?, ?, 1, ?)
            ');
            $stmt->execute([$channelId, $creator['id'], $creator['id']]);

            // If default channel, add all org members
            if ($isDefault) {
                $stmt2 = $this->db->prepare('
                    SELECT id FROM organization_colleagues 
                    WHERE organization_domain = ? AND id != ?
                ');
                $stmt2->execute([$domain, $creator['id']]);
                $others = $stmt2->fetchAll(\PDO::FETCH_COLUMN);

                $insertStmt = $this->db->prepare('
                    INSERT IGNORE INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                    VALUES (?, ?, 0, ?)
                ');
                foreach ($others as $memberId) {
                    $insertStmt->execute([$channelId, $memberId, $creator['id']]);
                }
            }

            // MUST commit before createSystemMessage — ChatService uses a separate
            // DB connection that cannot see uncommitted rows, so the FK on
            // chat_messages.conversation_id would fail if we call it inside the txn.
            $this->db->commit();

            // System message (non-fatal — channel already created & committed)
            try {
                $this->getChatService()->createSystemMessage($channelId, $creator['id'], 'created this channel');
            } catch (\Throwable $e) {
                error_log("ChannelService: system message failed (non-fatal): " . $e->getMessage());
            }

            $channel = $this->getChannelInfo($channelId);

            // Broadcast to domain so everyone can see new channel in browser
            $this->broadcastToDomain($domain, 'CHANNEL_CREATED', ['channel' => $channel]);

            return ['success' => true, 'channel' => $channel];

        } catch (\PDOException $e) {
            $this->db->rollBack();
            $dbError = $e->getMessage();
            error_log("ChannelService::createChannel PDO error: " . $dbError);

            // If the ENUM doesn't include 'channel', try self-healing once
            if (stripos($dbError, "Data truncated") !== false || stripos($dbError, "Incorrect enum") !== false) {
                error_log("ChannelService: Attempting self-heal for type ENUM");
                try {
                    $this->db->exec("ALTER TABLE chat_conversations MODIFY COLUMN type ENUM('direct','group','channel') NOT NULL DEFAULT 'direct'");
                } catch (\PDOException $ignored) {}
            }

            return ['success' => false, 'error' => 'Failed to create channel: ' . $dbError];
        }
    }

    /**
     * Browse available channels (public ones the user hasn't joined + all user's channels)
     */
    public function browseChannels(string $userEmail, ?string $search = null): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $domain = $colleague['organization_domain'];

        $sql = "
            SELECT c.id, c.name, c.slug, c.topic, c.purpose, c.is_public, c.is_default, c.message_count, c.created_at,
                   c.category_id, c.position,
                   (SELECT COUNT(*) FROM chat_participants WHERE conversation_id = c.id) as member_count,
                   (SELECT 1 FROM chat_participants WHERE conversation_id = c.id AND colleague_id = ?) as is_member
            FROM chat_conversations c
            WHERE c.organization_domain = ? AND c.type = 'channel'
              AND (c.is_public = 1 OR EXISTS (SELECT 1 FROM chat_participants WHERE conversation_id = c.id AND colleague_id = ?))
        ";
        $params = [$colleague['id'], $domain, $colleague['id']];

        if ($search) {
            $sql .= ' AND (c.name LIKE ? OR c.slug LIKE ? OR c.topic LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY c.name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $channels = $stmt->fetchAll();

        foreach ($channels as &$ch) {
            $ch['member_count'] = (int)$ch['member_count'];
            $ch['is_member'] = (bool)$ch['is_member'];
            $ch['is_public'] = (bool)$ch['is_public'];
            $ch['is_default'] = (bool)$ch['is_default'];
            $ch['message_count'] = (int)$ch['message_count'];
            $ch['category_id'] = $ch['category_id'] ? (int)$ch['category_id'] : null;
            $ch['position'] = (int)($ch['position'] ?? 0);
        }

        return ['success' => true, 'channels' => $channels];
    }

    /**
     * Join a public channel (self-join)
     */
    public function joinChannel(int $channelId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Verify it's a public channel in same domain
        $stmt = $this->db->prepare("
            SELECT * FROM chat_conversations 
            WHERE id = ? AND type = 'channel' AND organization_domain = ?
        ");
        $stmt->execute([$channelId, $colleague['organization_domain']]);
        $channel = $stmt->fetch();

        if (!$channel) {
            return ['success' => false, 'error' => 'Channel not found'];
        }

        if (!$channel['is_public']) {
            return ['success' => false, 'error' => 'This is a private channel. You need an invitation to join.'];
        }

        // Check if already member
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$channelId, $colleague['id']]);
        if ($stmt->fetch()) {
            return ['success' => true, 'message' => 'Already a member'];
        }

        // Add as participant
        $stmt = $this->db->prepare('
            INSERT INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
            VALUES (?, ?, 0, ?)
        ');
        $stmt->execute([$channelId, $colleague['id'], $colleague['id']]);

        // System message
        $this->getChatService()->createSystemMessage($channelId, $colleague['id'], 'joined the channel');

        // Broadcast
        $this->broadcastToConversation($channelId, 'CHANNEL_MEMBER_JOINED', [
            'conversation_id' => $channelId,
            'colleague_id' => $colleague['id'],
            'colleague_name' => $colleague['display_name'] ?? $colleague['email']
        ]);

        return ['success' => true];
    }

    /**
     * Leave a channel
     */
    public function leaveChannel(int $channelId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Verify membership
        $stmt = $this->db->prepare('SELECT is_admin FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$channelId, $colleague['id']]);
        $membership = $stmt->fetch();

        if (!$membership) {
            return ['success' => false, 'error' => 'Not a member of this channel'];
        }

        // If admin, check there's at least one other admin
        if ($membership['is_admin']) {
            $stmt = $this->db->prepare('
                SELECT COUNT(*) as cnt FROM chat_participants 
                WHERE conversation_id = ? AND is_admin = 1 AND colleague_id != ?
            ');
            $stmt->execute([$channelId, $colleague['id']]);
            $adminCount = (int)$stmt->fetch()['cnt'];
            
            if ($adminCount === 0) {
                // Check if there are other members to promote
                $stmt = $this->db->prepare('
                    SELECT colleague_id FROM chat_participants 
                    WHERE conversation_id = ? AND colleague_id != ? LIMIT 1
                ');
                $stmt->execute([$channelId, $colleague['id']]);
                $nextMember = $stmt->fetch();
                
                if ($nextMember) {
                    // Auto-promote next member
                    $this->db->prepare('UPDATE chat_participants SET is_admin = 1 WHERE conversation_id = ? AND colleague_id = ?')
                        ->execute([$channelId, $nextMember['colleague_id']]);
                }
            }
        }

        // Remove participant
        $stmt = $this->db->prepare('DELETE FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$channelId, $colleague['id']]);

        // System message
        $this->getChatService()->createSystemMessage($channelId, $colleague['id'], 'left the channel');

        // Broadcast
        $this->broadcastToConversation($channelId, 'CHANNEL_MEMBER_LEFT', [
            'conversation_id' => $channelId,
            'colleague_id' => $colleague['id']
        ]);

        return ['success' => true];
    }

    /**
     * Set channel topic
     */
    public function setTopic(int $channelId, string $userEmail, string $topic): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Verify membership
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$channelId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Not a member'];
        }

        $topic = trim(mb_substr($topic, 0, 500));

        $stmt = $this->db->prepare('UPDATE chat_conversations SET topic = ? WHERE id = ? AND type = ?');
        $stmt->execute([$topic, $channelId, 'channel']);

        // System message
        $displayName = $colleague['display_name'] ?? explode('@', $colleague['email'])[0];
        $this->getChatService()->createSystemMessage($channelId, $colleague['id'], 
            $topic ? "set the channel topic: {$topic}" : 'cleared the channel topic'
        );

        $this->broadcastToConversation($channelId, 'CHANNEL_TOPIC_UPDATED', [
            'conversation_id' => $channelId,
            'topic' => $topic,
            'updated_by' => $colleague['id']
        ]);

        return ['success' => true, 'topic' => $topic];
    }

    /**
     * Set channel purpose
     */
    public function setPurpose(int $channelId, string $userEmail, string $purpose): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$channelId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Not a member'];
        }

        $purpose = trim($purpose);
        $stmt = $this->db->prepare('UPDATE chat_conversations SET purpose = ?, description = ? WHERE id = ? AND type = ?');
        $stmt->execute([$purpose, $purpose, $channelId, 'channel']);

        $this->broadcastToConversation($channelId, 'CHANNEL_PURPOSE_UPDATED', [
            'conversation_id' => $channelId,
            'purpose' => $purpose,
            'updated_by' => $colleague['id']
        ]);

        return ['success' => true, 'purpose' => $purpose];
    }

    /**
     * Set default channel status (admin only)
     */
    public function setDefault(int $channelId, string $userEmail, bool $isDefault): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Must be channel admin
        $stmt = $this->db->prepare('SELECT is_admin FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$channelId, $colleague['id']]);
        $membership = $stmt->fetch();

        if (!$membership || !$membership['is_admin']) {
            return ['success' => false, 'error' => 'Admin access required'];
        }

        $stmt = $this->db->prepare('UPDATE chat_conversations SET is_default = ? WHERE id = ? AND type = ?');
        $stmt->execute([$isDefault ? 1 : 0, $channelId, 'channel']);

        // If marking as default, add all current org members who aren't already in
        if ($isDefault) {
            $stmt = $this->db->prepare('SELECT organization_domain FROM chat_conversations WHERE id = ?');
            $stmt->execute([$channelId]);
            $ch = $stmt->fetch();
            if ($ch) {
                $stmt2 = $this->db->prepare('
                    SELECT id FROM organization_colleagues 
                    WHERE organization_domain = ? 
                    AND id NOT IN (SELECT colleague_id FROM chat_participants WHERE conversation_id = ?)
                ');
                $stmt2->execute([$ch['organization_domain'], $channelId]);
                $missingMembers = $stmt2->fetchAll(\PDO::FETCH_COLUMN);

                $insertStmt = $this->db->prepare('
                    INSERT IGNORE INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                    VALUES (?, ?, 0, ?)
                ');
                foreach ($missingMembers as $memberId) {
                    $insertStmt->execute([$channelId, $memberId, $colleague['id']]);
                }
            }
        }

        return ['success' => true, 'is_default' => $isDefault];
    }

    /**
     * Get channel info with member count
     */
    public function getChannelInfo(int $channelId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, c.slug, c.topic, c.purpose, c.is_public, c.is_default, 
                   c.message_count, c.created_at, c.created_by, c.organization_domain,
                   c.category_id, c.position,
                   (SELECT COUNT(*) FROM chat_participants WHERE conversation_id = c.id) as member_count
            FROM chat_conversations c
            WHERE c.id = ? AND c.type = 'channel'
        ");
        $stmt->execute([$channelId]);
        $channel = $stmt->fetch();

        if (!$channel) return null;

        $channel['member_count'] = (int)$channel['member_count'];
        $channel['is_public'] = (bool)$channel['is_public'];
        $channel['is_default'] = (bool)$channel['is_default'];
        $channel['message_count'] = (int)$channel['message_count'];
        $channel['category_id'] = $channel['category_id'] ? (int)$channel['category_id'] : null;
        $channel['position'] = (int)$channel['position'];

        return $channel;
    }

    /**
     * Get all members of a channel with their colleague data.
     */
    public function getChannelMembers(int $channelId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Verify channel exists in same domain
        $stmt = $this->db->prepare("
            SELECT id, organization_domain FROM chat_conversations 
            WHERE id = ? AND type = 'channel' AND organization_domain = ?
        ");
        $stmt->execute([$channelId, $colleague['organization_domain']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Channel not found'];
        }

        $stmt = $this->db->prepare("
            SELECT oc.id, oc.email, oc.display_name, oc.avatar, oc.job_title,
                   p.is_admin, p.joined_at
            FROM chat_participants p
            JOIN organization_colleagues oc ON p.colleague_id = oc.id
            WHERE p.conversation_id = ?
            ORDER BY p.is_admin DESC, oc.display_name ASC
        ");
        $stmt->execute([$channelId]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($members as &$m) {
            $m['id'] = (int)$m['id'];
            $m['is_admin'] = (bool)$m['is_admin'];
        }

        return [
            'success' => true,
            'members' => $members,
            'member_count' => count($members),
        ];
    }

    /**
     * Auto-join a new colleague to all default channels
     */
    public function autoJoinDefaultChannels(int $colleagueId, string $domain): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM chat_conversations 
                WHERE organization_domain = ? AND type = 'channel' AND is_default = 1
            ");
            $stmt->execute([$domain]);
            $channels = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $insertStmt = $this->db->prepare('
                INSERT IGNORE INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                VALUES (?, ?, 0, ?)
            ');
            foreach ($channels as $channelId) {
                $insertStmt->execute([$channelId, $colleagueId, $colleagueId]);
            }
        } catch (\Throwable $e) {
            error_log("ChannelService::autoJoinDefaultChannels error: " . $e->getMessage());
        }
    }
}

