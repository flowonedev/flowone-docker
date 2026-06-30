<?php

namespace Webmail\Addons\Chat\Services;

/**
 * ChatService - Direct Message Chat System
 * 
 * Features:
 * - 1:1 direct messaging between colleagues
 * - Real-time via Redis pub/sub -> WebSocket
 * - Message reactions, replies, attachments
 * - Typing indicators and read receipts
 */
class ChatService
{
    private \PDO $db;
    private array $config;
    private ?\Webmail\Services\RedisCacheService $redis = null;
    private ?\Webmail\Addons\Team\Services\ColleagueService $colleagueService = null;
    private ?\Webmail\Addons\EmailTracking\Services\TrackingService $trackingService = null;
    private ?\Webmail\Services\StorageService $storage = null;
    
    /**
     * Get or create TrackingService instance for notifications
     */
    private function getTrackingService(): TrackingService
    {
        if (!$this->trackingService) {
            $this->trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($this->config);
        }
        return $this->trackingService;
    }
    
    /**
     * Get StorageService (lazy init). Uses NAS if configured via Panel, falls back to local.
     */
    private function getStorage(?string $userEmail = null): \Webmail\Services\StorageService
    {
        if (!$this->storage) {
            try {
                $this->storage = new \Webmail\Services\StorageService($this->config, $userEmail);
            } catch (\Throwable $e) {
                error_log("ChatService: StorageService init failed, using local fallback: " . $e->getMessage());
            }
        }
        return $this->storage;
    }
    
    /**
     * Get the base directory for chat attachments.
     * Prefers NAS (via StorageService/Panel), falls back to local storage.
     */
    public function getChatAttachmentsBaseDir(?string $userEmail = null): string
    {
        try {
            $storage = $this->getStorage($userEmail);
            if ($storage) {
                $basePath = $storage->getBasePath();
                if ($basePath && \Webmail\Services\NasHealthCheck::isAvailable()
                    && is_dir($basePath) && is_writable($basePath)) {
                    return $basePath;
                }
                if ($basePath && !\Webmail\Services\NasHealthCheck::isAvailable()) {
                    error_log("ChatService: NAS unavailable, falling back to local storage");
                }
            }
        } catch (\Throwable $e) {
            error_log("ChatService: StorageService error, falling back to local: " . $e->getMessage());
        }
        
        return $this->config['storage_path'] ?? '/var/www/vps-email/storage';
    }
    
    // Event types for WebSocket broadcasts
    const EVENT_MESSAGE_NEW = 'CHAT_MESSAGE_NEW';
    const EVENT_MESSAGE_EDITED = 'CHAT_MESSAGE_EDITED';
    const EVENT_MESSAGE_DELETED = 'CHAT_MESSAGE_DELETED';
    const EVENT_REACTION_ADDED = 'CHAT_REACTION_ADDED';
    const EVENT_REACTION_REMOVED = 'CHAT_REACTION_REMOVED';
    const EVENT_TYPING_START = 'CHAT_TYPING_START';
    const EVENT_TYPING_STOP = 'CHAT_TYPING_STOP';
    const EVENT_READ_RECEIPT = 'CHAT_READ_RECEIPT';
    const EVENT_CONVERSATION_CREATED = 'CHAT_CONVERSATION_CREATED';
    const EVENT_SETTINGS_UPDATED = 'CHAT_SETTINGS_UPDATED';
    const EVENT_VIEW_SESSION_START = 'CHAT_VIEW_SESSION_START';
    const EVENT_VIEW_SESSION_END = 'CHAT_VIEW_SESSION_END';
    const EVENT_VIEW_SYNC = 'CHAT_VIEW_SYNC';
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // Ensure tables exist (gated to once per code version, not per request)
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
        
        // Redis for real-time events (optional)
        try {
            $this->redis = new \Webmail\Services\RedisCacheService($config);
        } catch (\Throwable $e) {
            error_log("ChatService: Redis unavailable: " . $e->getMessage());
            $this->redis = null;
        }
        
        // Colleague service for user info
        try {
            $this->colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($config);
        } catch (\Throwable $e) {
            error_log("ChatService: ColleagueService unavailable: " . $e->getMessage());
        }
    }
    
    /**
     * Ensure all required tables exist
     */
    private function ensureTablesExist(): void
    {
        // Run migration SQL if tables don't exist
        $migrationFile = __DIR__ . '/../../../../migrations/034_chat_system.sql';
        if (file_exists($migrationFile)) {
            try {
                // Check if main table exists
                $result = $this->db->query("SHOW TABLES LIKE 'chat_conversations'");
                if ($result->rowCount() === 0) {
                    $sql = file_get_contents($migrationFile);
                    // Split by semicolons and execute each statement
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($statements as $statement) {
                        if (!empty($statement) && !str_starts_with($statement, '--')) {
                            $this->db->exec($statement);
                        }
                    }
                    error_log("ChatService: Created chat tables from migration");
                }
                
                // Ensure settings column exists (migration 036)
                $this->ensureSettingsColumn();
                
                // Ensure invitations table exists
                $this->ensureInvitationsTable();
                
                // Ensure content_type ENUM includes all values (call, embed)
                $this->ensureContentTypeEnum();
                
                // Ensure channel columns exist (Feature: Channels)
                $this->ensureChannelColumns();
                
            } catch (\PDOException $e) {
                error_log("ChatService: Failed to run migration: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Ensure the settings column exists in chat_conversations
     */
    private function ensureSettingsColumn(): void
    {
        try {
            // Check if settings column exists
            $result = $this->db->query("SHOW COLUMNS FROM chat_conversations LIKE 'settings'");
            if ($result->rowCount() === 0) {
                // Add the settings column
                $this->db->exec("ALTER TABLE chat_conversations ADD COLUMN settings JSON DEFAULT NULL");
                error_log("ChatService: Added settings column to chat_conversations");
            }
        } catch (\PDOException $e) {
            error_log("ChatService: Failed to add settings column: " . $e->getMessage());
        }
    }
    
    /**
     * Ensure chat_invitations table exists
     */
    private function ensureInvitationsTable(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'chat_invitations'");
            if ($result->rowCount() === 0) {
                $this->db->exec("
                    CREATE TABLE chat_invitations (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        inviter_email VARCHAR(255) NOT NULL,
                        invitee_email VARCHAR(255) NOT NULL,
                        organization_domain VARCHAR(255) NOT NULL,
                        token VARCHAR(64) NOT NULL UNIQUE,
                        status ENUM('pending', 'accepted', 'declined', 'expired') DEFAULT 'pending',
                        conversation_id INT UNSIGNED NULL,
                        created_at DATETIME NOT NULL,
                        expires_at DATETIME NOT NULL,
                        accepted_at DATETIME NULL,
                        INDEX idx_invitee (invitee_email),
                        INDEX idx_token (token),
                        INDEX idx_status (status)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                error_log("ChatService: Created chat_invitations table");
            }
        } catch (\PDOException $e) {
            error_log("ChatService: Failed to create invitations table: " . $e->getMessage());
        }
    }
    
    /**
     * Ensure channel columns exist on chat_conversations (Feature: Channels)
     */
    private function ensureChannelColumns(): void
    {
        try {
            // Check if type ENUM includes 'channel'
            $result = $this->db->query("SHOW COLUMNS FROM chat_conversations WHERE Field = 'type'");
            $col = $result->fetch(\PDO::FETCH_ASSOC);
            if ($col && stripos($col['Type'] ?? '', "'channel'") === false) {
                $this->db->exec("ALTER TABLE chat_conversations MODIFY COLUMN type ENUM('direct','group','channel') NOT NULL DEFAULT 'direct'");
                error_log("ChatService: Added 'channel' to type ENUM");
            }

            $columns = [
                'is_public'  => "ADD COLUMN is_public TINYINT(1) DEFAULT 1",
                'slug'       => "ADD COLUMN slug VARCHAR(100) DEFAULT NULL",
                'topic'      => "ADD COLUMN topic VARCHAR(500) DEFAULT NULL",
                'purpose'    => "ADD COLUMN purpose TEXT DEFAULT NULL",
                'is_default' => "ADD COLUMN is_default TINYINT(1) DEFAULT 0",
            ];
            foreach ($columns as $name => $ddl) {
                $check = $this->db->query("SHOW COLUMNS FROM chat_conversations LIKE '{$name}'");
                if ($check->rowCount() === 0) {
                    $this->db->exec("ALTER TABLE chat_conversations {$ddl}");
                    error_log("ChatService: Added channel column {$name}");
                }
            }
        } catch (\PDOException $e) {
            error_log("ChatService: ensureChannelColumns failed: " . $e->getMessage());
        }
    }

    /**
     * Ensure content_type ENUM includes all required values (voice, call, embed)
     * Self-healing: fixes the ENUM if an older migration overwrites it
     */
    private function ensureContentTypeEnum(): void
    {
        try {
            $result = $this->db->query("SHOW COLUMNS FROM chat_messages WHERE Field = 'content_type'");
            $column = $result->fetch(\PDO::FETCH_ASSOC);
            if ($column) {
                $type = $column['Type'] ?? '';
                // Check if 'embed' is missing from the ENUM
                if (stripos($type, "'embed'") === false) {
                    $this->db->exec("ALTER TABLE chat_messages MODIFY COLUMN content_type ENUM('text','file','image','system','voice','call','embed') DEFAULT 'text'");
                    error_log("ChatService: Updated content_type ENUM to include call and embed");
                }
            }
        } catch (\PDOException $e) {
            error_log("ChatService: Failed to update content_type ENUM: " . $e->getMessage());
        }
    }
    
    /**
     * Get domain from email address
     */
    private function getDomain(string $email): string
    {
        return strtolower(substr($email, strpos($email, '@') + 1));
    }
    
    /**
     * Get colleague by email (public accessor for auth checks in controller)
     */
    public function getColleagueByEmail(string $email): ?array
    {
        return $this->getColleague($email);
    }
    
    /**
     * Get the PDO database connection (for auth checks in controller)
     */
    public function getDb(): \PDO
    {
        return $this->db;
    }
    
    /**
     * Get colleague by email
     */
    private function getColleague(string $email): ?array
    {
        if ($this->colleagueService) {
            return $this->colleagueService->getColleagueByEmail($email);
        }
        
        // Fallback: direct query
        $stmt = $this->db->prepare('SELECT * FROM organization_colleagues WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get colleague by ID
     */
    private function getColleagueById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organization_colleagues WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Broadcast event to all conversation participants via Redis
     */
    private function broadcastToConversation(int $conversationId, string $eventType, array $payload): void
    {
        if (!$this->redis) return;
        
        try {
            // Fetch participants with their per-conversation mute state. We keep
            // delivering the realtime event to muted users (so the message still
            // arrives and the unread count updates), but tag new-message events
            // with the recipient's mute flag so the push layer can suppress the
            // web/native notification + sound for them.
            $stmt = $this->db->prepare('
                SELECT c.email, p.is_muted
                FROM chat_participants p
                JOIN organization_colleagues c ON p.colleague_id = c.id
                WHERE p.conversation_id = ?
            ');
            $stmt->execute([$conversationId]);
            $participants = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $isMessageEvent = ($eventType === self::EVENT_MESSAGE_NEW);
            
            // Broadcast to each participant, carrying their own mute state.
            foreach ($participants as $participant) {
                $recipientPayload = $payload;
                if ($isMessageEvent) {
                    $recipientPayload['recipient_muted'] = (bool)$participant['is_muted'];
                }
                $this->redis->publishEvent($participant['email'], $eventType, $recipientPayload);
            }
        } catch (\Throwable $e) {
            error_log("ChatService: Broadcast failed: " . $e->getMessage());
        }
    }
    
    // ========================================
    // CONVERSATION MANAGEMENT
    // ========================================
    
    /**
     * Get or create DM conversation between current user and target colleague
     */
    public function getOrCreateDMConversation(string $currentEmail, int $targetColleagueId): array
    {
        $currentColleague = $this->getColleague($currentEmail);
        if (!$currentColleague) {
            return ['success' => false, 'error' => 'Current user not found in colleagues'];
        }
        
        $targetColleague = $this->getColleagueById($targetColleagueId);
        if (!$targetColleague) {
            return ['success' => false, 'error' => 'Target colleague not found'];
        }
        
        // Ensure same domain
        if ($currentColleague['organization_domain'] !== $targetColleague['organization_domain']) {
            return ['success' => false, 'error' => 'Cannot message colleagues from different organizations'];
        }
        
        // Canonical ordering for DM lookup (lower ID first)
        $colleagueAId = min($currentColleague['id'], $targetColleagueId);
        $colleagueBId = max($currentColleague['id'], $targetColleagueId);
        
        // Check if DM already exists
        $stmt = $this->db->prepare('
            SELECT conversation_id FROM chat_dm_lookup 
            WHERE colleague_a_id = ? AND colleague_b_id = ?
        ');
        $stmt->execute([$colleagueAId, $colleagueBId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Check if the conversation was archived/deleted by this user
            $stmt = $this->db->prepare('
                SELECT is_archived, is_deleted FROM chat_participants 
                WHERE conversation_id = ? AND colleague_id = ?
            ');
            $stmt->execute([$existing['conversation_id'], $currentColleague['id']]);
            $participant = $stmt->fetch();
            
            if ($participant && ((int)$participant['is_archived'] === 1 || (int)$participant['is_deleted'] === 1)) {
                // Unarchive/undelete and set messages_visible_from so old messages are hidden.
                // This gives the user a "fresh start" after archiving/deleting a large conversation.
                $stmt = $this->db->prepare('
                    UPDATE chat_participants 
                    SET is_archived = 0, is_deleted = 0, messages_visible_from = NOW()
                    WHERE conversation_id = ? AND colleague_id = ?
                ');
                $stmt->execute([$existing['conversation_id'], $currentColleague['id']]);
            }
            
            return $this->getConversation($existing['conversation_id'], $currentEmail);
        }
        
        // Create new DM conversation
        $this->db->beginTransaction();
        try {
            // Create conversation
            $stmt = $this->db->prepare('
                INSERT INTO chat_conversations (organization_domain, type, created_by)
                VALUES (?, \'direct\', ?)
            ');
            $stmt->execute([
                $currentColleague['organization_domain'],
                $currentColleague['id']
            ]);
            $conversationId = (int)$this->db->lastInsertId();
            
            // Add both participants
            $stmt = $this->db->prepare('
                INSERT INTO chat_participants (conversation_id, colleague_id)
                VALUES (?, ?), (?, ?)
            ');
            $stmt->execute([
                $conversationId, $currentColleague['id'],
                $conversationId, $targetColleagueId
            ]);
            
            // Create DM lookup entry
            $stmt = $this->db->prepare('
                INSERT INTO chat_dm_lookup (conversation_id, colleague_a_id, colleague_b_id)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$conversationId, $colleagueAId, $colleagueBId]);
            
            $this->db->commit();
            
            // Broadcast new conversation to both users
            $conversation = $this->getConversationInternal($conversationId);
            $this->broadcastToConversation($conversationId, self::EVENT_CONVERSATION_CREATED, [
                'conversation' => $conversation
            ]);
            
            return $this->getConversation($conversationId, $currentEmail);
            
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("ChatService: Failed to create DM: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create conversation'];
        }
    }
    
    /**
     * Invite an external user to chat
     * Creates/updates colleague entry and optionally creates DM conversation
     */
    public function inviteExternalUser(string $inviterEmail, string $inviteeEmail, bool $sendEmail = true): array
    {
        $inviter = $this->getColleague($inviterEmail);
        if (!$inviter) {
            return ['success' => false, 'error' => 'Inviter not found'];
        }
        
        $inviterDomain = $inviter['organization_domain'];
        $inviteeDomain = substr(strrchr($inviteeEmail, '@'), 1);
        
        // Check if invitee is already a colleague in the same domain
        $existingColleague = $this->getColleagueByEmailAnyDomain($inviteeEmail);
        
        if ($existingColleague && $existingColleague['organization_domain'] === $inviterDomain) {
            // Already a colleague, just create DM
            return $this->getOrCreateDMConversation($inviterEmail, $existingColleague['id']);
        }
        
        // Create invite record or add as external contact
        try {
            $this->db->beginTransaction();
            
            // Check for existing invite
            $stmt = $this->db->prepare('
                SELECT * FROM chat_invitations 
                WHERE inviter_email = ? AND invitee_email = ? AND status = "pending"
            ');
            $stmt->execute([$inviterEmail, $inviteeEmail]);
            $existingInvite = $stmt->fetch();
            
            if ($existingInvite) {
                $this->db->commit();
                return [
                    'success' => true,
                    'invite_id' => $existingInvite['id'],
                    'invite_token' => $existingInvite['token'],
                    'inviter' => $inviter,
                    'message' => 'Invitation already sent'
                ];
            }
            
            // Create invitation record
            $inviteToken = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare('
                INSERT INTO chat_invitations (inviter_email, invitee_email, organization_domain, token, status, created_at, expires_at)
                VALUES (?, ?, ?, ?, "pending", NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
            ');
            $stmt->execute([$inviterEmail, $inviteeEmail, $inviterDomain, $inviteToken]);
            $inviteId = (int)$this->db->lastInsertId();
            
            $this->db->commit();
            
            // Send invitation email (if requested - controller may send it instead)
            if ($sendEmail) {
                $this->sendInvitationEmail($inviterEmail, $inviteeEmail, $inviteToken, $inviter);
            }
            
            return [
                'success' => true,
                'invite_id' => $inviteId,
                'invite_token' => $inviteToken,
                'inviter' => $inviter,
                'message' => 'Invitation sent successfully'
            ];
            
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("ChatService: Failed to create invite: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to send invitation'];
        }
    }
    
    /**
     * Get colleague by email from any domain
     */
    private function getColleagueByEmailAnyDomain(string $email): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM organization_colleagues WHERE email = ?
        ');
        $stmt->execute([$email]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Send chat invitation email
     */
    private function sendInvitationEmail(string $inviterEmail, string $inviteeEmail, string $token, array $inviter): void
    {
        try {
            $appName = $this->config['app']['name'] ?? 'Email App';
            $baseUrl = $this->config['app']['url'] ?? 'https://flowone.pro';
            $inviteUrl = $baseUrl . '/chat/invite/' . $token;
            $inviterName = $inviter['display_name'] ?? explode('@', $inviterEmail)[0];
            
            $subject = "{$inviterName} invited you to chat on {$appName}";
            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; padding: 30px; border-radius: 12px 12px 0 0; text-align: center;">
        <h1 style="margin: 0; font-size: 24px;">You're Invited to Chat!</h1>
    </div>
    <div style="background: #f8fafc; padding: 30px; border-radius: 0 0 12px 12px;">
        <p style="font-size: 16px; color: #334155;">
            <strong>{$inviterName}</strong> ({$inviterEmail}) has invited you to chat on {$appName}.
        </p>
        <p style="font-size: 14px; color: #64748b;">
            Click the button below to accept the invitation and start chatting.
        </p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{$inviteUrl}" style="display: inline-block; background: #6366f1; color: white; padding: 14px 32px; border-radius: 9999px; text-decoration: none; font-weight: 600;">
                Accept Invitation
            </a>
        </div>
        <p style="font-size: 12px; color: #94a3b8; text-align: center;">
            This invitation expires in 7 days.
        </p>
    </div>
</body>
</html>
HTML;
            
            $textBody = "{$inviterName} ({$inviterEmail}) has invited you to chat on {$appName}.\n\n";
            $textBody .= "Click here to accept: {$inviteUrl}\n\n";
            $textBody .= "This invitation expires in 7 days.";
            
            // Use SmtpService to send the email
            $smtp = new \Webmail\Services\SmtpService($this->config['smtp'] ?? []);
            $smtp->setCredentials(
                $this->config['smtp']['username'] ?? $this->config['mail_from'] ?? 'noreply@flowone.pro',
                $this->config['smtp']['password'] ?? ''
            );
            
            $result = $smtp->send([
                'to' => [['email' => $inviteeEmail]],
                'subject' => $subject,
                'body_html' => $htmlBody,
                'body_text' => $textBody,
            ]);
            
            if (!$result['success']) {
                error_log("ChatService: Failed to send invitation email: " . ($result['error'] ?? 'Unknown error'));
            }
            
        } catch (\Throwable $e) {
            error_log("ChatService: Failed to send invitation email: " . $e->getMessage());
        }
    }
    
    // ========================================
    // INVITATION MANAGEMENT (accept/decline)
    // ========================================
    
    /**
     * Get pending invitations for a user (as invitee)
     */
    public function getPendingInvitations(string $email): array
    {
        try {
            $stmt = $this->db->prepare('
                SELECT i.*, 
                       c.display_name as inviter_name,
                       c.avatar_path as inviter_avatar,
                       c.job_title as inviter_job_title
                FROM chat_invitations i
                LEFT JOIN organization_colleagues c ON c.email = i.inviter_email
                WHERE i.invitee_email = ? 
                  AND i.status = "pending"
                  AND i.expires_at > NOW()
                ORDER BY i.created_at DESC
            ');
            $stmt->execute([strtolower($email)]);
            $invitations = $stmt->fetchAll();
            
            return ['success' => true, 'invitations' => $invitations];
        } catch (\PDOException $e) {
            error_log("ChatService: Failed to get pending invitations: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to fetch invitations'];
        }
    }
    
    /**
     * Accept a chat invitation
     * Creates colleague entry + DM conversation between inviter and invitee
     */
    public function acceptInvitation(string $inviteeEmail, int $invitationId): array
    {
        try {
            // Fetch the invitation
            $stmt = $this->db->prepare('
                SELECT * FROM chat_invitations 
                WHERE id = ? AND invitee_email = ? AND status = "pending"
            ');
            $stmt->execute([$invitationId, strtolower($inviteeEmail)]);
            $invitation = $stmt->fetch();
            
            if (!$invitation) {
                return ['success' => false, 'error' => 'Invitation not found or already processed'];
            }
            
            if (new \DateTime($invitation['expires_at']) < new \DateTime()) {
                return ['success' => false, 'error' => 'Invitation has expired'];
            }
            
            $this->db->beginTransaction();
            
            $inviterEmail = $invitation['inviter_email'];
            $domain = $invitation['organization_domain'];
            
            // Ensure the invitee exists as a colleague in the inviter's organization
            $inviteeColleague = $this->getColleagueByEmailAnyDomain($inviteeEmail);
            if (!$inviteeColleague) {
                // Create colleague entry for the invitee
                $displayName = explode('@', $inviteeEmail)[0];
                $stmt = $this->db->prepare('
                    INSERT INTO organization_colleagues (organization_domain, email, display_name, synced_from_mailserver)
                    VALUES (?, ?, ?, 0)
                ');
                $stmt->execute([$domain, strtolower($inviteeEmail), $displayName]);
                $inviteeColleagueId = (int)$this->db->lastInsertId();
            } else {
                $inviteeColleagueId = (int)$inviteeColleague['id'];
                // If they're in a different domain, update to inviter's domain
                if ($inviteeColleague['organization_domain'] !== $domain) {
                    $stmt = $this->db->prepare('UPDATE organization_colleagues SET organization_domain = ? WHERE id = ?');
                    $stmt->execute([$domain, $inviteeColleagueId]);
                }
            }
            
            // Mark invitation as accepted
            $stmt = $this->db->prepare('
                UPDATE chat_invitations SET status = "accepted", accepted_at = NOW() WHERE id = ?
            ');
            $stmt->execute([$invitationId]);
            
            $this->db->commit();
            
            // Create DM conversation between inviter and invitee
            $dmResult = $this->getOrCreateDMConversation($inviteeEmail, $this->getColleague($inviterEmail)['id'] ?? 0);
            
            // Update invitation with conversation_id
            if (!empty($dmResult['conversation']['id'])) {
                $stmt = $this->db->prepare('UPDATE chat_invitations SET conversation_id = ? WHERE id = ?');
                $stmt->execute([$dmResult['conversation']['id'], $invitationId]);
            }
            
            return [
                'success' => true,
                'conversation_id' => $dmResult['conversation']['id'] ?? null,
                'message' => 'Invitation accepted'
            ];
            
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("ChatService: Failed to accept invitation: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to accept invitation'];
        }
    }
    
    /**
     * Decline a chat invitation
     */
    public function declineInvitation(string $inviteeEmail, int $invitationId): array
    {
        try {
            $stmt = $this->db->prepare('
                UPDATE chat_invitations 
                SET status = "declined" 
                WHERE id = ? AND invitee_email = ? AND status = "pending"
            ');
            $stmt->execute([$invitationId, strtolower($inviteeEmail)]);
            
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'Invitation not found or already processed'];
            }
            
            return ['success' => true, 'message' => 'Invitation declined'];
        } catch (\PDOException $e) {
            error_log("ChatService: Failed to decline invitation: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to decline invitation'];
        }
    }
    
    /**
     * Look up an invitation by token (for processing email link clicks)
     */
    public function getInvitationByToken(string $token): ?array
    {
        try {
            $stmt = $this->db->prepare('
                SELECT i.*, 
                       c.display_name as inviter_name,
                       c.avatar_path as inviter_avatar
                FROM chat_invitations i
                LEFT JOIN organization_colleagues c ON c.email = i.inviter_email
                WHERE i.token = ?
            ');
            $stmt->execute([$token]);
            $invitation = $stmt->fetch();
            return $invitation ?: null;
        } catch (\PDOException $e) {
            error_log("ChatService: Failed to get invitation by token: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get conversation details
     */
    public function getConversation(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check if user is participant
        $stmt = $this->db->prepare('
            SELECT p.*, c.type, c.name, c.description, c.organization_domain, c.last_message_at, 
                   c.last_message_preview, c.last_message_sender_id, c.message_count
            FROM chat_participants p
            JOIN chat_conversations c ON p.conversation_id = c.id
            WHERE p.conversation_id = ? AND p.colleague_id = ?
        ');
        $stmt->execute([$conversationId, $colleague['id']]);
        $participant = $stmt->fetch();
        
        if (!$participant) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        // Get the other participant(s) for DM
        $stmt = $this->db->prepare('
            SELECT oc.id, oc.email, oc.display_name, oc.avatar_path, oc.status, oc.last_seen_at
            FROM chat_participants p
            JOIN organization_colleagues oc ON p.colleague_id = oc.id
            WHERE p.conversation_id = ? AND p.colleague_id != ?
        ');
        $stmt->execute([$conversationId, $colleague['id']]);
        $otherParticipants = $stmt->fetchAll();
        
        // If user has a "fresh start" timestamp, hide old preview
        $messagesVisibleFrom = $participant['messages_visible_from'] ?? null;
        $lastMessageAt = $participant['last_message_at'] ?? null;
        if ($messagesVisibleFrom && $lastMessageAt && $lastMessageAt < $messagesVisibleFrom) {
            $participant['last_message_preview'] = '';
            $participant['last_message_sender_id'] = null;
        }
        
        // Sanitize preview - never show raw encoded content
        $preview = $participant['last_message_preview'] ?? '';
        if (preg_match('/^\[voice:\d/', $preview)) {
            $preview = 'Voice message';
        } elseif (preg_match('/^\[gif:/', $preview)) {
            $preview = 'Sent a GIF';
        } elseif (preg_match('/^\[call:/', $preview)) {
            $preview = $this->formatCallPreview($preview);
        }
        
        return [
            'success' => true,
            'conversation' => [
                'id' => $conversationId,
                'type' => $participant['type'],
                'name' => $participant['name'],
                'description' => $participant['description'],
                'participants' => $otherParticipants,
                'last_message_at' => $participant['last_message_at'],
                'last_message_preview' => $preview,
                'last_message_sender_id' => $participant['last_message_sender_id'],
                'message_count' => (int)$participant['message_count'],
                'unread_count' => (int)$participant['unread_count'],
                'is_pinned' => (bool)$participant['is_pinned'],
                'is_muted' => (bool)$participant['is_muted'],
                'is_archived' => (bool)$participant['is_archived'],
            ]
        ];
    }
    
    /**
     * Get conversation internal (no access check)
     */
    private function getConversationInternal(int $conversationId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT c.*, 
                   GROUP_CONCAT(oc.id) as participant_ids,
                   GROUP_CONCAT(oc.email) as participant_emails
            FROM chat_conversations c
            JOIN chat_participants p ON c.id = p.conversation_id
            JOIN organization_colleagues oc ON p.colleague_id = oc.id
            WHERE c.id = ?
            GROUP BY c.id
        ');
        $stmt->execute([$conversationId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get all conversations for a user
     */
    public function getConversations(string $userEmail, int $limit = 50, int $offset = 0): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        $stmt = $this->db->prepare('
            SELECT c.id, c.type, c.name, c.description, c.last_message_at, c.last_message_preview, 
                   c.last_message_sender_id, c.message_count,
                   c.slug, c.topic, c.purpose, c.is_public, c.is_default,
                   p.unread_count, p.is_pinned, p.is_muted, p.is_archived, p.messages_visible_from
            FROM chat_conversations c
            JOIN chat_participants p ON c.id = p.conversation_id
            WHERE p.colleague_id = ? AND p.is_archived = 0
            ORDER BY p.is_pinned DESC, c.last_message_at DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset . '
        ');
        $stmt->execute([$colleague['id']]);
        $conversations = $stmt->fetchAll();
        
        // Get other participants for each conversation
        foreach ($conversations as &$conv) {
            $stmt = $this->db->prepare('
                SELECT oc.id, oc.email, oc.display_name, oc.avatar_path, oc.status, oc.last_seen_at
                FROM chat_participants p
                JOIN organization_colleagues oc ON p.colleague_id = oc.id
                WHERE p.conversation_id = ? AND p.colleague_id != ?
            ');
            $stmt->execute([$conv['id'], $colleague['id']]);
            $conv['participants'] = $stmt->fetchAll();
            $conv['unread_count'] = (int)$conv['unread_count'];
            $conv['message_count'] = (int)$conv['message_count'];
            $conv['is_pinned'] = (bool)$conv['is_pinned'];
            $conv['is_muted'] = (bool)$conv['is_muted'];
            $conv['is_archived'] = (bool)$conv['is_archived'];
            
            // If user has a "fresh start" timestamp, hide old preview
            if (!empty($conv['messages_visible_from']) && $conv['last_message_at'] && $conv['last_message_at'] < $conv['messages_visible_from']) {
                $conv['last_message_preview'] = '';
                $conv['last_message_sender_id'] = null;
            }
            unset($conv['messages_visible_from']); // Don't expose to frontend
            
            // Sanitize last_message_preview - never show raw encoded content
            $preview = $conv['last_message_preview'] ?? '';
            if (preg_match('/^\[voice:\d/', $preview)) {
                $conv['last_message_preview'] = 'Voice message';
            } elseif (preg_match('/^\[gif:/', $preview)) {
                $conv['last_message_preview'] = 'Sent a GIF';
            } elseif (preg_match('/^\[call:/', $preview)) {
                $conv['last_message_preview'] = $this->formatCallPreview($preview);
            }
        }
        
        return [
            'success' => true,
            'conversations' => $conversations
        ];
    }
    
    /**
     * Get total unread counts across all conversations
     */
    public function getUnreadCounts(string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        $stmt = $this->db->prepare('
            SELECT conversation_id, unread_count
            FROM chat_participants
            WHERE colleague_id = ? AND unread_count > 0
        ');
        $stmt->execute([$colleague['id']]);
        $counts = $stmt->fetchAll();
        
        $total = 0;
        $byConversation = [];
        foreach ($counts as $row) {
            $total += $row['unread_count'];
            $byConversation[$row['conversation_id']] = (int)$row['unread_count'];
        }
        
        return [
            'success' => true,
            'total' => $total,
            'by_conversation' => $byConversation
        ];
    }
    
    // ========================================
    // MESSAGES
    // ========================================
    
    /**
     * Get messages for a conversation (paginated)
     */
    public function getMessages(int $conversationId, string $userEmail, int $limit = 50, ?int $beforeId = null): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access and get participant settings (including messages_visible_from)
        $stmt = $this->db->prepare('SELECT messages_visible_from FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        $participant = $stmt->fetch();
        if (!$participant) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        $sql = '
            SELECT m.*, 
                   oc.email as sender_email, oc.display_name as sender_name, oc.avatar_path as sender_avatar,
                   (SELECT COUNT(*) FROM chat_messages r WHERE r.reply_to_id = m.id AND r.deleted_at IS NULL) as reply_count
            FROM chat_messages m
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            WHERE m.conversation_id = ? AND m.deleted_at IS NULL
              AND m.reply_to_id IS NULL
        ';
        
        $params = [$conversationId];
        
        // Hide messages before the user's "fresh start" timestamp
        if (!empty($participant['messages_visible_from'])) {
            $sql .= ' AND m.created_at >= ?';
            $params[] = $participant['messages_visible_from'];
        }
        
        if ($beforeId) {
            $sql .= ' AND m.id < ?';
            $params[] = $beforeId;
        }
        
        $sql .= ' ORDER BY m.id DESC LIMIT ' . (int)$limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();
        
        // Get reactions for these messages
        $messageIds = array_column($messages, 'id');
        $reactions = [];
        if (!empty($messageIds)) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $stmt = $this->db->prepare("
                SELECT r.*, oc.display_name as colleague_name
                FROM chat_message_reactions r
                JOIN organization_colleagues oc ON r.colleague_id = oc.id
                WHERE r.message_id IN ($placeholders)
            ");
            $stmt->execute($messageIds);
            foreach ($stmt->fetchAll() as $reaction) {
                $reactions[$reaction['message_id']][] = $reaction;
            }
        }
        
        // Format messages
        foreach ($messages as &$msg) {
            $msg['attachments'] = $msg['attachments'] ? json_decode($msg['attachments'], true) : [];
            $msg['is_edited'] = (bool)$msg['is_edited'];
            $msg['is_pinned'] = (bool)($msg['is_pinned'] ?? false);
            $msg['is_own'] = $msg['sender_id'] == $colleague['id'];
            $msg['reactions'] = $reactions[$msg['id']] ?? [];
            $msg['reply_count'] = (int)($msg['reply_count'] ?? 0);
            $msg['reply_to'] = null;
        }
        
        // Reverse to get chronological order
        $messages = array_reverse($messages);
        
        return [
            'success' => true,
            'messages' => $messages,
            'has_more' => count($messages) === $limit
        ];
    }
    
    /**
     * Send a message
     */
    public function sendMessage(
        int $conversationId, 
        string $senderEmail, 
        string $content, 
        ?int $replyToId = null, 
        ?array $attachments = null,
        ?float $voiceDuration = null,
        bool $alsoSendToChannel = false,
        bool $skipAccessCheck = false
    ): array {
        $colleague = $this->getColleague($senderEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access (skip for system/automation messages)
        if (!$skipAccessCheck) {
            $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
            $stmt->execute([$conversationId, $colleague['id']]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'Access denied'];
            }
        }
        
        // Validate reply_to if provided
        if ($replyToId) {
            $stmt = $this->db->prepare('SELECT 1 FROM chat_messages WHERE id = ? AND conversation_id = ?');
            $stmt->execute([$replyToId, $conversationId]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'Reply message not found'];
            }
        }
        
        $content = trim($content);
        if (empty($content) && empty($attachments)) {
            return ['success' => false, 'error' => 'Message content is required'];
        }
        
        $contentType = 'text';
        if (preg_match('/^\[embed:\w+:\d+\]$/', $content)) {
            // Embed message: shared content (drive file, board card, calendar event, etc.)
            $contentType = 'embed';
        } elseif ($voiceDuration !== null && !empty($attachments)) {
            // Voice message: has duration and audio attachment
            $contentType = 'voice';
        } elseif (!empty($attachments)) {
            // Check if all attachments are images
            $allImages = true;
            foreach ($attachments as $att) {
                if (!str_starts_with($att['type'] ?? '', 'image/')) {
                    $allImages = false;
                    break;
                }
            }
            $contentType = $allImages ? 'image' : 'file';
        }
        
        $this->db->beginTransaction();
        try {
            // Insert message
            $stmt = $this->db->prepare('
                INSERT INTO chat_messages (conversation_id, sender_id, content, content_type, reply_to_id, attachments, voice_duration)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $conversationId,
                $colleague['id'],
                $content,
                $contentType,
                $replyToId,
                $attachments ? json_encode($attachments) : null,
                $voiceDuration
            ]);
            $messageId = (int)$this->db->lastInsertId();
            
            // Update conversation metadata
            if ($contentType === 'voice') {
                $durationFormatted = $voiceDuration ? gmdate('i:s', (int)$voiceDuration) : '0:00';
                $preview = 'Voice message (' . $durationFormatted . ')';
            } elseif ($contentType === 'embed') {
                $embedTypeMap = [
                    'drive_file' => 'Shared a file',
                    'drive_folder' => 'Shared a folder',
                    'calendar_event' => 'Shared a calendar event',
                    'board' => 'Shared a board',
                    'board_card' => 'Shared a board card',
                ];
                $embedType = '';
                if (preg_match('/^\[embed:(\w+):/', $content, $m)) {
                    $embedType = $m[1];
                }
                $preview = $embedTypeMap[$embedType] ?? 'Shared content';
            } else {
                $preview = mb_substr(strip_tags($content), 0, 100);
                if (mb_strlen($content) > 100) {
                    $preview .= '...';
                }
            }
            
            $stmt = $this->db->prepare('
                UPDATE chat_conversations 
                SET last_message_at = NOW(), 
                    last_message_preview = ?,
                    last_message_sender_id = ?,
                    message_count = message_count + 1
                WHERE id = ?
            ');
            $stmt->execute([$preview, $colleague['id'], $conversationId]);
            
            // Increment unread count for other participants
            $stmt = $this->db->prepare('
                UPDATE chat_participants 
                SET unread_count = unread_count + 1
                WHERE conversation_id = ? AND colleague_id != ?
            ');
            $stmt->execute([$conversationId, $colleague['id']]);
            
            // Auto-unarchive for other participants receiving this message
            // If they had archived or deleted the conversation, it should reappear when new messages arrive
            $stmt = $this->db->prepare('
                UPDATE chat_participants 
                SET is_archived = 0, is_deleted = 0
                WHERE conversation_id = ? AND colleague_id != ? AND (is_archived = 1 OR is_deleted = 1)
            ');
            $stmt->execute([$conversationId, $colleague['id']]);
            
            $this->db->commit();
            
            // Fetch the complete message
            $stmt = $this->db->prepare('
                SELECT m.*, oc.email as sender_email, oc.display_name as sender_name, oc.avatar_path as sender_avatar
                FROM chat_messages m
                JOIN organization_colleagues oc ON m.sender_id = oc.id
                WHERE m.id = ?
            ');
            $stmt->execute([$messageId]);
            $message = $stmt->fetch();
            $message['attachments'] = $message['attachments'] ? json_decode($message['attachments'], true) : [];
            $message['is_edited'] = false;
            $message['reactions'] = [];
            
            // Fetch reply_to if exists
            if ($replyToId) {
                $stmt = $this->db->prepare('
                    SELECT m.id, m.content, oc.display_name as sender_name
                    FROM chat_messages m
                    JOIN organization_colleagues oc ON m.sender_id = oc.id
                    WHERE m.id = ?
                ');
                $stmt->execute([$replyToId]);
                $message['reply_to'] = $stmt->fetch() ?: null;
            } else {
                $message['reply_to'] = null;
            }
            
            // Broadcast to all participants
            $this->broadcastToConversation($conversationId, self::EVENT_MESSAGE_NEW, [
                'conversation_id' => $conversationId,
                'message' => $message
            ]);
            
            // Process @mentions
            if ($contentType === 'text' && (str_contains($content, '@'))) {
                try {
                    $mentionService = new MentionService($this->config);
                    $mentionService->processMentions($messageId, $conversationId, $content, $colleague['id']);
                } catch (\Throwable $e) {
                    error_log("ChatService: Mention processing failed: " . $e->getMessage());
                }
            }

            // Auto-share embedded content with conversation participants
            if ($contentType === 'embed' && preg_match('/^\[embed:(\w+):(\d+)\]$/', $content, $embedMatch)) {
                $this->autoShareEmbed($conversationId, $senderEmail, $embedMatch[1], (int)$embedMatch[2]);
            }

            // Send notification for thread replies
            if ($replyToId) {
                try {
                    // Get the parent message sender
                    $parentStmt = $this->db->prepare('
                        SELECT m.sender_id, m.content, oc.email as parent_sender_email, oc.display_name as parent_sender_name
                        FROM chat_messages m
                        JOIN organization_colleagues oc ON m.sender_id = oc.id
                        WHERE m.id = ?
                    ');
                    $parentStmt->execute([$replyToId]);
                    $parentMsg = $parentStmt->fetch();
                    
                    if ($parentMsg && strtolower($parentMsg['parent_sender_email']) !== strtolower($senderEmail)) {
                        // Notify parent message author about the thread reply
                        $tracking = $this->getTrackingService();
                        $senderName = $colleague['display_name'] ?? $senderEmail;
                        $parentPreview = mb_substr(strip_tags($parentMsg['content']), 0, 50);
                        $replyPreview = mb_substr(strip_tags($content), 0, 50);
                        
                        $tracking->createNotification(
                            $parentMsg['parent_sender_email'],
                            'thread_reply',
                            'New Thread Reply',
                            "{$senderName} replied to your message: \"{$parentPreview}\"",
                            [
                                'conversation_id' => $conversationId,
                                'message_id' => $replyToId,
                                'reply_id' => $messageId,
                                'sender_email' => $senderEmail,
                                'sender_name' => $senderName,
                                'parent_preview' => $parentPreview,
                                'reply_preview' => $replyPreview
                            ]
                        );
                    }
                    
                    // Also notify other thread participants (people who have replied to the same parent)
                    $threadParticipantsStmt = $this->db->prepare('
                        SELECT DISTINCT oc.email
                        FROM chat_messages m
                        JOIN organization_colleagues oc ON m.sender_id = oc.id
                        WHERE m.reply_to_id = ? AND m.deleted_at IS NULL
                          AND oc.email != ? AND oc.email != ?
                    ');
                    $threadParticipantsStmt->execute([$replyToId, strtolower($senderEmail), strtolower($parentMsg['parent_sender_email'] ?? '')]);
                    $threadParticipants = $threadParticipantsStmt->fetchAll();
                    
                    $tracking = $this->getTrackingService();
                    $senderName = $colleague['display_name'] ?? $senderEmail;
                    
                    foreach ($threadParticipants as $tp) {
                        $tracking->createNotification(
                            $tp['email'],
                            'thread_reply',
                            'New Thread Reply',
                            "{$senderName} replied in a thread you participated in",
                            [
                                'conversation_id' => $conversationId,
                                'message_id' => $replyToId,
                                'reply_id' => $messageId,
                                'sender_email' => $senderEmail,
                                'sender_name' => $senderName,
                                'reply_preview' => mb_substr(strip_tags($content), 0, 50)
                            ]
                        );
                    }
                } catch (\Throwable $e) {
                    error_log("ChatService: Thread notification failed: " . $e->getMessage());
                }
            }
            
            // "Also send to channel" - duplicate the message as a top-level message
            if ($alsoSendToChannel && $replyToId) {
                try {
                    $channelCopyContent = $content;
                    $stmt2 = $this->db->prepare('
                        INSERT INTO chat_messages (conversation_id, sender_id, content, content_type, reply_to_id, attachments, voice_duration)
                        VALUES (?, ?, ?, ?, NULL, ?, ?)
                    ');
                    $stmt2->execute([
                        $conversationId,
                        $colleague['id'],
                        $channelCopyContent,
                        $contentType,
                        $attachments ? json_encode($attachments) : null,
                        $voiceDuration
                    ]);
                    $channelCopyId = (int)$this->db->lastInsertId();

                    // Update conversation preview with this copy (since it's top-level)
                    $stmt2 = $this->db->prepare('
                        UPDATE chat_conversations 
                        SET last_message_at = NOW(), 
                            last_message_preview = ?,
                            last_message_sender_id = ?,
                            message_count = message_count + 1
                        WHERE id = ?
                    ');
                    $stmt2->execute([$preview, $colleague['id'], $conversationId]);

                    // Fetch the channel copy message
                    $stmt2 = $this->db->prepare('
                        SELECT m.*, oc.email as sender_email, oc.display_name as sender_name, oc.avatar_path as sender_avatar
                        FROM chat_messages m
                        JOIN organization_colleagues oc ON m.sender_id = oc.id
                        WHERE m.id = ?
                    ');
                    $stmt2->execute([$channelCopyId]);
                    $channelCopy = $stmt2->fetch();
                    $channelCopy['attachments'] = $channelCopy['attachments'] ? json_decode($channelCopy['attachments'], true) : [];
                    $channelCopy['is_edited'] = false;
                    $channelCopy['reactions'] = [];
                    $channelCopy['reply_to'] = null;

                    // Broadcast the channel copy as a new top-level message
                    $this->broadcastToConversation($conversationId, self::EVENT_MESSAGE_NEW, [
                        'conversation_id' => $conversationId,
                        'message' => $channelCopy
                    ]);

                    $message['also_sent_to_channel'] = true;
                } catch (\Throwable $e) {
                    error_log("ChatService: Failed to also send to channel: " . $e->getMessage());
                    // Non-fatal: the thread reply was already sent successfully
                }
            }
            
            return [
                'success' => true,
                'message' => $message
            ];
            
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("ChatService: Failed to send message: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to send message'];
        }
    }
    
    /**
     * Edit a message (only own messages)
     */
    public function editMessage(int $messageId, string $userEmail, string $newContent): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Get message and verify ownership
        $stmt = $this->db->prepare('SELECT * FROM chat_messages WHERE id = ? AND sender_id = ? AND deleted_at IS NULL');
        $stmt->execute([$messageId, $colleague['id']]);
        $message = $stmt->fetch();
        
        if (!$message) {
            return ['success' => false, 'error' => 'Message not found or not editable'];
        }
        
        $newContent = trim($newContent);
        if (empty($newContent)) {
            return ['success' => false, 'error' => 'Message content is required'];
        }
        
        $stmt = $this->db->prepare('
            UPDATE chat_messages 
            SET content = ?, is_edited = 1, edited_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$newContent, $messageId]);
        
        // Update last message preview if this was the last message
        $stmt = $this->db->prepare('
            UPDATE chat_conversations 
            SET last_message_preview = ?
            WHERE id = ? AND last_message_sender_id = ?
        ');
        $preview = mb_substr(strip_tags($newContent), 0, 100);
        $stmt->execute([$preview, $message['conversation_id'], $colleague['id']]);
        
        // Broadcast edit
        $this->broadcastToConversation($message['conversation_id'], self::EVENT_MESSAGE_EDITED, [
            'conversation_id' => $message['conversation_id'],
            'message_id' => $messageId,
            'content' => $newContent,
            'edited_at' => date('Y-m-d H:i:s')
        ]);
        
        return ['success' => true];
    }
    
    /**
     * Delete a message (soft delete, only own messages)
     */
    public function deleteMessage(int $messageId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Get message and verify ownership
        $stmt = $this->db->prepare('SELECT * FROM chat_messages WHERE id = ? AND sender_id = ? AND deleted_at IS NULL');
        $stmt->execute([$messageId, $colleague['id']]);
        $message = $stmt->fetch();
        
        if (!$message) {
            return ['success' => false, 'error' => 'Message not found or already deleted'];
        }
        
        $stmt = $this->db->prepare('UPDATE chat_messages SET deleted_at = NOW() WHERE id = ?');
        $stmt->execute([$messageId]);
        
        // Broadcast deletion
        $this->broadcastToConversation($message['conversation_id'], self::EVENT_MESSAGE_DELETED, [
            'conversation_id' => $message['conversation_id'],
            'message_id' => $messageId
        ]);
        
        return ['success' => true];
    }
    
    /**
     * Delete an entire thread (all replies to a parent message).
     * Only the parent message author can delete the thread.
     */
    public function deleteThread(int $parentMessageId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Get parent message and verify ownership
        $stmt = $this->db->prepare('SELECT * FROM chat_messages WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$parentMessageId]);
        $parentMessage = $stmt->fetch();
        
        if (!$parentMessage) {
            return ['success' => false, 'error' => 'Message not found'];
        }
        
        if ((int)$parentMessage['sender_id'] !== (int)$colleague['id']) {
            return ['success' => false, 'error' => 'Only the thread starter can delete the thread'];
        }
        
        // Soft delete all replies
        $stmt = $this->db->prepare('UPDATE chat_messages SET deleted_at = NOW() WHERE reply_to_id = ? AND deleted_at IS NULL');
        $stmt->execute([$parentMessageId]);
        $deletedCount = $stmt->rowCount();
        
        // Broadcast thread deletion
        $this->broadcastToConversation($parentMessage['conversation_id'], self::EVENT_MESSAGE_DELETED, [
            'conversation_id' => $parentMessage['conversation_id'],
            'message_id' => $parentMessageId,
            'thread_deleted' => true,
            'deleted_replies' => $deletedCount
        ]);
        
        return ['success' => true, 'deleted_count' => $deletedCount];
    }
    
    // ========================================
    // REACTIONS
    // ========================================
    
    /**
     * Add reaction to a message
     */
    public function addReaction(int $messageId, string $userEmail, string $emoji): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Get message and check access
        $stmt = $this->db->prepare('
            SELECT m.*, p.colleague_id as participant_id
            FROM chat_messages m
            JOIN chat_participants p ON m.conversation_id = p.conversation_id AND p.colleague_id = ?
            WHERE m.id = ? AND m.deleted_at IS NULL
        ');
        $stmt->execute([$colleague['id'], $messageId]);
        $message = $stmt->fetch();
        
        if (!$message) {
            return ['success' => false, 'error' => 'Message not found or access denied'];
        }
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO chat_message_reactions (message_id, colleague_id, emoji)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE created_at = NOW()
            ');
            $stmt->execute([$messageId, $colleague['id'], $emoji]);
            
            // Broadcast reaction
            $this->broadcastToConversation($message['conversation_id'], self::EVENT_REACTION_ADDED, [
                'conversation_id' => $message['conversation_id'],
                'message_id' => $messageId,
                'colleague_id' => $colleague['id'],
                'colleague_name' => $colleague['display_name'] ?? $colleague['email'],
                'emoji' => $emoji
            ]);
            
            return ['success' => true];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => 'Failed to add reaction'];
        }
    }
    
    /**
     * Remove reaction from a message
     */
    public function removeReaction(int $messageId, string $userEmail, string $emoji): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Get message for conversation_id
        $stmt = $this->db->prepare('SELECT conversation_id FROM chat_messages WHERE id = ?');
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        
        if (!$message) {
            return ['success' => false, 'error' => 'Message not found'];
        }
        
        $stmt = $this->db->prepare('
            DELETE FROM chat_message_reactions 
            WHERE message_id = ? AND colleague_id = ? AND emoji = ?
        ');
        $stmt->execute([$messageId, $colleague['id'], $emoji]);
        
        // Broadcast reaction removal
        $this->broadcastToConversation($message['conversation_id'], self::EVENT_REACTION_REMOVED, [
            'conversation_id' => $message['conversation_id'],
            'message_id' => $messageId,
            'colleague_id' => $colleague['id'],
            'emoji' => $emoji
        ]);
        
        return ['success' => true];
    }
    
    // ========================================
    // MESSAGE PINNING
    // ========================================
    
    const EVENT_MESSAGE_PINNED = 'CHAT_MESSAGE_PINNED';
    
    /**
     * Toggle pin status of a message
     */
    public function togglePinMessage(int $messageId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Get message and check access
        $stmt = $this->db->prepare('
            SELECT m.*, p.colleague_id as participant_id
            FROM chat_messages m
            JOIN chat_participants p ON m.conversation_id = p.conversation_id AND p.colleague_id = ?
            WHERE m.id = ? AND m.deleted_at IS NULL
        ');
        $stmt->execute([$colleague['id'], $messageId]);
        $message = $stmt->fetch();
        
        if (!$message) {
            return ['success' => false, 'error' => 'Message not found or access denied'];
        }
        
        $isPinned = !(bool)$message['is_pinned'];
        
        if ($isPinned) {
            $stmt = $this->db->prepare('
                UPDATE chat_messages SET is_pinned = 1, pinned_at = NOW(), pinned_by = ?
                WHERE id = ?
            ');
            $stmt->execute([$colleague['id'], $messageId]);
        } else {
            $stmt = $this->db->prepare('
                UPDATE chat_messages SET is_pinned = 0, pinned_at = NULL, pinned_by = NULL
                WHERE id = ?
            ');
            $stmt->execute([$messageId]);
        }
        
        // Broadcast pin change
        $this->broadcastToConversation($message['conversation_id'], self::EVENT_MESSAGE_PINNED, [
            'conversation_id' => (int)$message['conversation_id'],
            'message_id' => $messageId,
            'is_pinned' => $isPinned,
            'pinned_by' => $colleague['id'],
            'pinned_by_name' => $colleague['display_name'] ?? $colleague['email']
        ]);
        
        return [
            'success' => true,
            'is_pinned' => $isPinned
        ];
    }
    
    /**
     * Get all pinned messages in a conversation
     */
    public function getPinnedMessages(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        $stmt = $this->db->prepare('
            SELECT m.*, 
                   oc.email as sender_email, oc.display_name as sender_name, oc.avatar_path as sender_avatar,
                   poc.display_name as pinned_by_name
            FROM chat_messages m
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            LEFT JOIN organization_colleagues poc ON m.pinned_by = poc.id
            WHERE m.conversation_id = ? AND m.is_pinned = 1 AND m.deleted_at IS NULL
            ORDER BY m.pinned_at DESC
        ');
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll();
        
        // Get reactions for these messages
        $messageIds = array_column($messages, 'id');
        $reactions = [];
        if (!empty($messageIds)) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $stmt = $this->db->prepare("
                SELECT r.*, oc.display_name as colleague_name
                FROM chat_message_reactions r
                JOIN organization_colleagues oc ON r.colleague_id = oc.id
                WHERE r.message_id IN ($placeholders)
            ");
            $stmt->execute($messageIds);
            foreach ($stmt->fetchAll() as $reaction) {
                $reactions[$reaction['message_id']][] = $reaction;
            }
        }
        
        foreach ($messages as &$msg) {
            $msg['attachments'] = $msg['attachments'] ? json_decode($msg['attachments'], true) : [];
            $msg['is_edited'] = (bool)$msg['is_edited'];
            $msg['is_pinned'] = (bool)$msg['is_pinned'];
            $msg['is_own'] = $msg['sender_id'] == $colleague['id'];
            $msg['reactions'] = $reactions[$msg['id']] ?? [];
        }
        
        return [
            'success' => true,
            'messages' => $messages
        ];
    }
    
    // ========================================
    // READ RECEIPTS & TYPING
    // ========================================
    
    /**
     * Mark conversation as read up to a message
     */
    public function markAsRead(int $conversationId, string $userEmail, ?int $messageId = null): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // If no messageId provided, get the latest message
        if (!$messageId) {
            $stmt = $this->db->prepare('
                SELECT id FROM chat_messages 
                WHERE conversation_id = ? AND deleted_at IS NULL
                ORDER BY id DESC LIMIT 1
            ');
            $stmt->execute([$conversationId]);
            $latest = $stmt->fetch();
            $messageId = $latest ? $latest['id'] : null;
        }
        
        if (!$messageId) {
            return ['success' => true]; // No messages to mark as read
        }
        
        // Update participant's read status
        $stmt = $this->db->prepare('
            UPDATE chat_participants 
            SET last_read_message_id = ?, last_read_at = NOW(), unread_count = 0
            WHERE conversation_id = ? AND colleague_id = ?
        ');
        $stmt->execute([$messageId, $conversationId, $colleague['id']]);
        
        // Add read receipt
        try {
            $stmt = $this->db->prepare('
                INSERT INTO chat_read_receipts (message_id, colleague_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE read_at = NOW()
            ');
            $stmt->execute([$messageId, $colleague['id']]);
        } catch (\PDOException $e) {
            // Ignore duplicate errors
        }
        
        // Broadcast read receipt
        $this->broadcastToConversation($conversationId, self::EVENT_READ_RECEIPT, [
            'conversation_id' => $conversationId,
            'colleague_id' => $colleague['id'],
            'colleague_name' => $colleague['display_name'] ?? $colleague['email'],
            'message_id' => $messageId,
            'read_at' => date('Y-m-d H:i:s')
        ]);
        
        return ['success' => true];
    }
    
    /**
     * Update typing status
     */
    public function updateTypingStatus(int $conversationId, string $userEmail, bool $isTyping): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        if ($isTyping) {
            // Insert/update typing status
            $stmt = $this->db->prepare('
                INSERT INTO chat_typing_status (conversation_id, colleague_id, started_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE started_at = NOW()
            ');
            $stmt->execute([$conversationId, $colleague['id']]);
            
            $this->broadcastToConversation($conversationId, self::EVENT_TYPING_START, [
                'conversation_id' => $conversationId,
                'colleague_id' => $colleague['id'],
                'colleague_name' => $colleague['display_name'] ?? $colleague['email']
            ]);
        } else {
            // Remove typing status
            $stmt = $this->db->prepare('DELETE FROM chat_typing_status WHERE conversation_id = ? AND colleague_id = ?');
            $stmt->execute([$conversationId, $colleague['id']]);
            
            $this->broadcastToConversation($conversationId, self::EVENT_TYPING_STOP, [
                'conversation_id' => $conversationId,
                'colleague_id' => $colleague['id']
            ]);
        }
        
        return ['success' => true];
    }
    
    // ========================================
    // SEARCH
    // ========================================
    
    /**
     * Search messages across all conversations or within a specific one
     */
    public function searchMessages(string $userEmail, string $query, ?int $conversationId = null, int $limit = 50): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        $query = trim($query);
        if (strlen($query) < 2) {
            return ['success' => false, 'error' => 'Search query must be at least 2 characters'];
        }
        
        $sql = '
            SELECT m.*, c.type as conversation_type,
                   oc.email as sender_email, oc.display_name as sender_name, oc.avatar_path as sender_avatar
            FROM chat_messages m
            JOIN chat_conversations c ON m.conversation_id = c.id
            JOIN chat_participants p ON m.conversation_id = p.conversation_id
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            WHERE p.colleague_id = ? 
              AND m.deleted_at IS NULL
              AND MATCH(m.content) AGAINST(? IN NATURAL LANGUAGE MODE)
        ';
        
        $params = [$colleague['id'], $query];
        
        if ($conversationId) {
            $sql .= ' AND m.conversation_id = ?';
            $params[] = $conversationId;
        }
        
        $sql .= ' ORDER BY m.created_at DESC LIMIT ' . (int)$limit;
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $messages = $stmt->fetchAll();
            
            // Get other participants for context
            foreach ($messages as &$msg) {
                $stmt = $this->db->prepare('
                    SELECT oc.display_name 
                    FROM chat_participants p
                    JOIN organization_colleagues oc ON p.colleague_id = oc.id
                    WHERE p.conversation_id = ? AND p.colleague_id != ?
                ');
                $stmt->execute([$msg['conversation_id'], $colleague['id']]);
                $others = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                $msg['conversation_with'] = implode(', ', $others);
                $msg['attachments'] = $msg['attachments'] ? json_decode($msg['attachments'], true) : [];
            }
            
            return [
                'success' => true,
                'messages' => $messages
            ];
        } catch (\PDOException $e) {
            // Fallback to LIKE search if FULLTEXT fails
            $sql = str_replace(
                'MATCH(m.content) AGAINST(? IN NATURAL LANGUAGE MODE)',
                'm.content LIKE ?',
                $sql
            );
            $params[1] = '%' . $query . '%';
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return [
                'success' => true,
                'messages' => $stmt->fetchAll()
            ];
        }
    }
    
    // ========================================
    // CONVERSATION SETTINGS
    // ========================================
    
    /**
     * Toggle pin status for a conversation
     */
    public function togglePin(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        $stmt = $this->db->prepare('
            UPDATE chat_participants 
            SET is_pinned = NOT is_pinned
            WHERE conversation_id = ? AND colleague_id = ?
        ');
        $stmt->execute([$conversationId, $colleague['id']]);
        
        // Get new status
        $stmt = $this->db->prepare('SELECT is_pinned FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        $result = $stmt->fetch();
        
        return [
            'success' => true,
            'is_pinned' => (bool)$result['is_pinned']
        ];
    }
    
    /**
     * Toggle mute status for a conversation
     */
    public function toggleMute(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        $stmt = $this->db->prepare('
            UPDATE chat_participants 
            SET is_muted = NOT is_muted
            WHERE conversation_id = ? AND colleague_id = ?
        ');
        $stmt->execute([$conversationId, $colleague['id']]);
        
        // Get new status
        $stmt = $this->db->prepare('SELECT is_muted FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        $result = $stmt->fetch();
        
        return [
            'success' => true,
            'is_muted' => (bool)$result['is_muted']
        ];
    }
    
    /**
     * Archive a conversation
     */
    public function archiveConversation(int $conversationId, string $userEmail, bool $archive = true): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        $stmt = $this->db->prepare('
            UPDATE chat_participants 
            SET is_archived = ?
            WHERE conversation_id = ? AND colleague_id = ?
        ');
        $stmt->execute([$archive ? 1 : 0, $conversationId, $colleague['id']]);
        
        return [
            'success' => true,
            'is_archived' => $archive
        ];
    }
    
    /**
     * Delete a conversation for a specific user (soft delete).
     * Archives the conversation, hides all messages, and clears unread count.
     * Other participants are NOT affected.
     */
    public function deleteConversationForUser(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Verify user is a participant
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Not a participant'];
        }
        
        // Soft delete: archive + set messages_visible_from far in the future so no messages show
        // Use a future timestamp that's clearly a "deleted" marker
        $stmt = $this->db->prepare('
            UPDATE chat_participants 
            SET is_archived = 1, 
                is_deleted = 1,
                unread_count = 0,
                messages_visible_from = NOW()
            WHERE conversation_id = ? AND colleague_id = ?
        ');
        $stmt->execute([$conversationId, $colleague['id']]);
        
        return ['success' => true];
    }
    
    // ========================================
    // ATTACHMENTS
    // ========================================
    
    /**
     * Upload attachment(s) to a conversation
     * Returns array of attachment objects to be stored with message
     */
    public function uploadAttachments(int $conversationId, string $userEmail, array $files): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        // Ensure chat attachments directory exists (NAS preferred, local fallback).
        // mkdir() is recursive, so it also creates the chat_attachments parent when
        // the storage root is writable. The web/PHP user (not root) must own or be
        // able to write the parent -- a failure here is almost always a server-side
        // permission/ownership issue, so log enough detail to pinpoint it.
        $baseDir = $this->getChatAttachmentsBaseDir($userEmail);
        $chatDir = $baseDir . '/chat_attachments/' . $conversationId;
        if (!is_dir($chatDir)) {
            // The "&& !is_dir" guard absorbs a concurrent create (race between two
            // uploads); 0775 lets the web group write future siblings.
            if (!@mkdir($chatDir, 0775, true) && !is_dir($chatDir)) {
                $osError = error_get_last()['message'] ?? 'unknown error';
                $parent  = $baseDir . '/chat_attachments';
                $runAs   = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid())
                    : 'unknown';
                error_log(sprintf(
                    'ChatService: Failed to create chat attachments dir: %s | baseDir=%s (exists=%s, writable=%s) | parent=%s (exists=%s, writable=%s) | runAs=%s | error=%s',
                    $chatDir,
                    $baseDir, is_dir($baseDir) ? 'yes' : 'no', is_writable($baseDir) ? 'yes' : 'no',
                    $parent, is_dir($parent) ? 'yes' : 'no', is_writable($parent) ? 'yes' : 'no',
                    $runAs, $osError
                ));
                return ['success' => false, 'error' => 'Storage directory could not be created'];
            }
        }
        
        $attachments = [];
        // Per-file skip reasons. Every path that drops a file records WHY here so
        // a failed upload returns/logs a specific cause instead of the useless
        // generic "No files were uploaded" (which hides NAS/permission/MIME bugs).
        $skipReasons = [];
        $maxFileSize = 50 * 1024 * 1024; // 50MB max per file
        
        // Blocked MIME types (executable, scripts, server-side code)
        $blockedMimeTypes = [
            'application/x-httpd-php', 'application/x-php', 'text/x-php',
            'application/x-executable', 'application/x-sharedlib',
            'application/x-msdos-program', 'application/x-msdownload',
            'application/x-dosexec', 'application/bat', 'application/x-bat',
            'application/x-sh', 'application/x-csh',
            'application/java-archive', 'application/x-java-class',
        ];
        
        // Blocked file extensions (double-extension attacks, server-side scripts)
        $blockedExtensions = [
            'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
            'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll',
            'sh', 'bash', 'csh', 'ksh',
            'jsp', 'jspx', 'asp', 'aspx',
            'py', 'pl', 'rb', 'cgi',
            'htaccess', 'htpasswd',
        ];
        
        foreach ($files as $file) {
            $label = isset($file['name']) ? basename((string)$file['name']) : 'file';

            // Files flagged is_local_temp were decoded by the controller from a
            // base64 JSON body (native shells, where multipart is stripped in
            // transit). Their bytes ride along in $file['data'] and get written
            // straight to storage -- there is no HTTP upload, so is_uploaded_file()
            // does not apply. Everything else is a real multipart upload whose
            // tmp_name must pass is_uploaded_file().
            $isLocalTemp = !empty($file['is_local_temp']);
            $tmpName = $file['tmp_name'] ?? '';
            $validSource = $isLocalTemp
                ? (isset($file['data']) && is_string($file['data']) && $file['data'] !== '')
                : ($tmpName !== '' && is_uploaded_file($tmpName));
            if (!$validSource) {
                $skipReasons[] = "{$label}: not a valid uploaded file (request body may have been stripped in transit)";
                error_log("ChatService: invalid upload source for '{$label}' tmp_name=" . var_export($tmpName, true) . " is_local_temp=" . var_export($isLocalTemp, true));
                continue;
            }
            
            if ($file['size'] > $maxFileSize) {
                $skipReasons[] = "{$label}: exceeds the 50MB per-file limit";
                continue; // Skip files that are too large
            }
            
            $originalName = $file['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            // Block dangerous file extensions — check ALL extensions in the filename
            // to prevent double-extension attacks (e.g., "shell.php.jpg")
            $nameParts = explode('.', strtolower($originalName));
            array_shift($nameParts); // Remove the base filename
            $hasBlockedExtension = false;
            foreach ($nameParts as $ext) {
                if (in_array($ext, $blockedExtensions, true)) {
                    $hasBlockedExtension = true;
                    break;
                }
            }
            if ($hasBlockedExtension) {
                $skipReasons[] = "{$label}: file type is not allowed";
                error_log("ChatService: Blocked upload of dangerous file type: {$originalName}");
                continue;
            }
            
            // Server-side MIME detection using finfo (cannot be spoofed by client).
            // Native uploads have no temp file, so sniff the in-memory buffer.
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMimeType = $isLocalTemp
                ? $finfo->buffer($file['data'])
                : $finfo->file($file['tmp_name']);
            
            // Use the server-detected MIME type, NOT the client-provided one
            $mimeType = $detectedMimeType ?: ($file['type'] ?? 'application/octet-stream');
            
            // Block dangerous MIME types
            if (in_array($mimeType, $blockedMimeTypes, true)) {
                $skipReasons[] = "{$label}: file type ({$mimeType}) is not allowed";
                error_log("ChatService: Blocked upload with dangerous MIME type: {$mimeType} ({$originalName})");
                continue;
            }
            $filename = uniqid() . '_' . time() . '.' . $extension;
            $filepath = $chatDir . '/' . $filename;
            
            // Persist the upload. Native (base64) uploads write their decoded
            // bytes straight to the destination with file_put_contents() -- the
            // same way thumbnails are written here -- so nothing is moved across a
            // filesystem boundary onto the NAS (a copy()/rename() the hardened host
            // can refuse). HTTP multipart uploads use move_uploaded_file(), which
            // can return false over a soft NFS mount (root_squash perms /
            // cross-device rename); the Drive + avatar paths fall back to copy(),
            // so mirror that here instead of dead-ending.
            $persisted = $isLocalTemp
                ? (@file_put_contents($filepath, $file['data']) !== false)
                : (@move_uploaded_file($file['tmp_name'], $filepath) || @copy($file['tmp_name'], $filepath));
            if (!$persisted) {
                $osError  = error_get_last()['message'] ?? 'unknown error';
                $writable = is_dir($chatDir) && is_writable($chatDir) ? 'writable' : 'NOT writable';
                $free     = @disk_free_space($chatDir);
                $free     = is_numeric($free) ? round($free / 1048576) . 'MB free' : 'free space unknown';
                $skipReasons[] = "{$label}: could not be saved to storage";
                error_log("ChatService: failed to write upload to {$filepath} (dest dir {$chatDir} is {$writable}, {$free}): {$osError}");
                continue;
            }

            // Soft-NFS guard: a move/copy can report success yet leave a
            // truncated file. $_FILES['size'] is the byte count PHP actually
            // received, so a mismatch means the storage write was incomplete.
            clearstatcache(true, $filepath);
            $written = @filesize($filepath);
            if ($written === false || (int)$written !== (int)$file['size']) {
                @unlink($filepath);
                $skipReasons[] = "{$label}: storage write was incomplete";
                error_log("ChatService: upload size mismatch for {$filepath} (expected={$file['size']}, on-disk=" . var_export($written, true) . ")");
                continue;
            }
            
            // Determine category
            $category = $this->getFileCategory($mimeType);
            
            // Get image dimensions if applicable
            $width = null;
            $height = null;
            $thumbnailPath = null;
            
            if ($category === 'image' && function_exists('getimagesize')) {
                $imageInfo = @getimagesize($filepath);
                if ($imageInfo) {
                    $width = $imageInfo[0];
                    $height = $imageInfo[1];
                    
                    // Create thumbnail for images
                    $thumbnailPath = $this->createThumbnail($filepath, $chatDir, $filename);
                }
            }
            
            $attachment = [
                'id' => uniqid('att_'),
                'filename' => $filename,
                'original_name' => $originalName,
                'path' => '/chat_attachments/' . $conversationId . '/' . $filename,
                'size' => $file['size'],
                'type' => $mimeType,
                'category' => $category,
                'width' => $width,
                'height' => $height,
                'thumbnail' => $thumbnailPath ? '/chat_attachments/' . $conversationId . '/' . basename($thumbnailPath) : null,
                'uploaded_at' => date('Y-m-d H:i:s')
            ];
            
            $attachments[] = $attachment;
        }
        
        if (empty($attachments)) {
            $detail = !empty($skipReasons)
                ? implode('; ', array_unique($skipReasons))
                : 'No files were uploaded';
            error_log("ChatService: uploadAttachments saved nothing for conversation {$conversationId}. Reasons: {$detail}");
            return ['success' => false, 'error' => $detail];
        }
        
        return [
            'success' => true,
            'attachments' => $attachments
        ];
    }
    
    /**
     * Get file category from mime type
     */
    private function getFileCategory(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) return 'image';
        if (str_starts_with($mimeType, 'video/')) return 'video';
        if (str_starts_with($mimeType, 'audio/')) return 'audio';
        if (in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
        ])) return 'document';
        if (in_array($mimeType, [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/gzip',
            'application/x-tar',
        ])) return 'archive';
        
        return 'other';
    }
    
    /**
     * Create thumbnail for an image
     */
    private function createThumbnail(string $sourcePath, string $destDir, string $filename): ?string
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return null;
        }
        
        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo) return null;
        
        $mimeType = $imageInfo['mime'];
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        // Don't create thumbnail for small images
        if ($width <= 300 && $height <= 300) {
            return null;
        }
        
        // Create source image
        $source = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $source = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $source = @imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $source = @imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $source = @imagecreatefromwebp($sourcePath);
                break;
        }
        
        if (!$source) return null;
        
        // Calculate thumbnail dimensions (max 300x300, maintain aspect ratio)
        $maxSize = 300;
        $ratio = min($maxSize / $width, $maxSize / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
        
        // Create thumbnail
        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG
        if ($mimeType === 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Save thumbnail
        $thumbFilename = 'thumb_' . $filename;
        $thumbPath = $destDir . '/' . $thumbFilename;
        
        switch ($mimeType) {
            case 'image/jpeg':
                imagejpeg($thumb, $thumbPath, 85);
                break;
            case 'image/png':
                imagepng($thumb, $thumbPath, 8);
                break;
            case 'image/gif':
                imagegif($thumb, $thumbPath);
                break;
            case 'image/webp':
                imagewebp($thumb, $thumbPath, 85);
                break;
        }
        
        imagedestroy($source);
        imagedestroy($thumb);
        
        return $thumbPath;
    }
    
    /**
     * Get all attachments for a conversation
     */
    public function getConversationAttachments(int $conversationId, string $userEmail, ?string $category = null, ?int $messageId = null): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        // Get messages with attachments (optionally filter by message ID)
        // Exclude voice messages - their audio attachments are not browsable files
        $sql = '
            SELECT m.id as message_id, m.attachments, m.created_at, 
                   oc.display_name as sender_name, oc.email as sender_email
            FROM chat_messages m
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            WHERE m.conversation_id = ? 
              AND m.attachments IS NOT NULL 
              AND m.deleted_at IS NULL
              AND m.content_type != \'voice\'
        ';
        
        $params = [$conversationId];
        
        if ($messageId) {
            $sql .= ' AND m.id = ?';
            $params[] = $messageId;
        }
        
        $sql .= ' ORDER BY m.created_at DESC';
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();
        
        $allAttachments = [];
        foreach ($messages as $msg) {
            $attachments = json_decode($msg['attachments'], true) ?: [];
            foreach ($attachments as $att) {
                // Filter by category if specified
                if ($category && ($att['category'] ?? 'other') !== $category) {
                    continue;
                }
                
                $att['message_id'] = $msg['message_id'];
                $att['sent_at'] = $msg['created_at'];
                $att['sender_name'] = $msg['sender_name'];
                $att['sender_email'] = $msg['sender_email'];
                $allAttachments[] = $att;
            }
        }
        
        // Group by category for easy frontend use
        $grouped = [
            'images' => [],
            'videos' => [],
            'documents' => [],
            'other' => []
        ];
        
        foreach ($allAttachments as $att) {
            $cat = $att['category'] ?? 'other';
            switch ($cat) {
                case 'image':
                    $grouped['images'][] = $att;
                    break;
                case 'video':
                    $grouped['videos'][] = $att;
                    break;
                case 'document':
                case 'archive':
                    $grouped['documents'][] = $att;
                    break;
                default:
                    $grouped['other'][] = $att;
            }
        }
        
        return [
            'success' => true,
            'attachments' => $allAttachments,
            'grouped' => $grouped,
            'counts' => [
                'images' => count($grouped['images']),
                'videos' => count($grouped['videos']),
                'documents' => count($grouped['documents']),
                'other' => count($grouped['other']),
                'total' => count($allAttachments)
            ]
        ];
    }
    
    /**
     * Save conversation attachments to Drive
     * Creates folder structure: Chats/{ConversationName}/{files}
     */
    public function saveAttachmentsToDrive(int $conversationId, string $userEmail, ?array $attachmentIds = null, ?int $messageId = null): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access & get conversation info
        $stmt = $this->db->prepare('
            SELECT c.*, p.colleague_id
            FROM chat_conversations c
            JOIN chat_participants p ON c.id = p.conversation_id
            WHERE c.id = ? AND p.colleague_id = ?
        ');
        $stmt->execute([$conversationId, $colleague['id']]);
        $conv = $stmt->fetch();
        
        if (!$conv) {
            return ['success' => false, 'error' => 'Conversation not found or access denied'];
        }
        
        // Get the other participant's name for folder naming
        $stmt = $this->db->prepare('
            SELECT oc.display_name, oc.email
            FROM chat_participants p
            JOIN organization_colleagues oc ON p.colleague_id = oc.id
            WHERE p.conversation_id = ? AND p.colleague_id != ?
        ');
        $stmt->execute([$conversationId, $colleague['id']]);
        $otherParticipant = $stmt->fetch();
        
        $chatName = $otherParticipant ? ($otherParticipant['display_name'] ?: explode('@', $otherParticipant['email'])[0]) : 'Chat_' . $conversationId;
        $chatName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $chatName);
        
        // Get attachments (optionally filter by message_id)
        $result = $this->getConversationAttachments($conversationId, $userEmail, null, $messageId);
        if (!$result['success']) {
            return $result;
        }
        
        $attachments = $result['attachments'];
        if (empty($attachments)) {
            return ['success' => false, 'error' => 'No attachments to save'];
        }
        
        // Filter specific attachments if IDs provided
        if ($attachmentIds) {
            $attachments = array_filter($attachments, fn($a) => in_array($a['id'] ?? '', $attachmentIds));
        }
        
        if (empty($attachments)) {
            return ['success' => false, 'error' => 'No matching attachments found'];
        }
        
        // Use DriveService to save files
        try {
            $driveService = new \Webmail\Services\DriveService($this->config);
            
            // Create folder structure: Chats/{ChatName}
            $domain = $this->getDomain($userEmail);
            
            // Find or create "Chats" folder
            $chatsFolder = $driveService->findOrCreateFolder($userEmail, 'Chats', null);
            if (!$chatsFolder) {
                return ['success' => false, 'error' => 'Failed to create Chats folder'];
            }
            
            // Find or create chat-specific folder
            $chatFolder = $driveService->findOrCreateFolder($userEmail, $chatName, $chatsFolder['id']);
            if (!$chatFolder) {
                return ['success' => false, 'error' => 'Failed to create chat folder'];
            }
            
            $savedCount = 0;
            $errors = [];
            $baseDir = $this->getChatAttachmentsBaseDir($userEmail);
            
            foreach ($attachments as $att) {
                $sourcePath = $baseDir . ($att['path'] ?? '');
                
                if (!file_exists($sourcePath)) {
                    $errors[] = "File not found: " . ($att['original_name'] ?? 'unknown');
                    continue;
                }
                
                // Copy to Drive
                $result = $driveService->uploadFromPath(
                    $userEmail,
                    $sourcePath,
                    $chatFolder['id'],
                    $att['original_name'] ?? basename($sourcePath)
                );
                
                if ($result && isset($result['id'])) {
                    $savedCount++;
                } else {
                    $errors[] = "Failed to save: " . ($att['original_name'] ?? 'unknown');
                }
            }
            
            return [
                'success' => $savedCount > 0,
                'saved_count' => $savedCount,
                'total' => count($attachments),
                'folder_path' => 'Chats/' . $chatName,
                'folder_id' => $chatFolder['id'],
                'errors' => $errors
            ];
            
        } catch (\Throwable $e) {
            error_log("ChatService::saveAttachmentsToDrive error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Drive service unavailable'];
        }
    }
    
    /**
     * Get conversation settings
     */
    public function getConversationSettings(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        // Get settings from conversation
        $stmt = $this->db->prepare('SELECT settings FROM chat_conversations WHERE id = ?');
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();
        
        $settings = $row && $row['settings'] ? json_decode($row['settings'], true) : [];
        
        return [
            'success' => true,
            'settings' => $settings
        ];
    }
    
    /**
     * Update conversation settings and broadcast to all participants
     */
    public function updateConversationSettings(int $conversationId, string $userEmail, array $newSettings): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        // Get current settings
        $stmt = $this->db->prepare('SELECT settings FROM chat_conversations WHERE id = ?');
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();
        
        $currentSettings = $row && $row['settings'] ? json_decode($row['settings'], true) : [];
        
        // Merge new settings
        $mergedSettings = array_merge($currentSettings, $newSettings);
        
        // Save to database
        $stmt = $this->db->prepare('UPDATE chat_conversations SET settings = ? WHERE id = ?');
        $stmt->execute([json_encode($mergedSettings), $conversationId]);
        
        // Broadcast to all participants
        $this->broadcastToConversation($conversationId, self::EVENT_SETTINGS_UPDATED, [
            'conversation_id' => $conversationId,
            'settings' => $mergedSettings,
            'updated_by' => [
                'id' => $colleague['id'],
                'name' => $colleague['display_name'] ?: explode('@', $colleague['email'])[0]
            ]
        ]);
        
        return [
            'success' => true,
            'settings' => $mergedSettings
        ];
    }
    
    // ========================================
    // VIEW TOGETHER (Collaborative Viewing)
    // ========================================
    
    /**
     * Start a view together session
     */
    public function startViewSession(int $conversationId, string $userEmail, string $contentType, string $contentId): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access to conversation
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        $session = [
            'conversation_id' => $conversationId,
            'started_by' => [
                'id' => $colleague['id'],
                'name' => $colleague['display_name'] ?: explode('@', $colleague['email'])[0]
            ],
            'content_type' => $contentType,
            'content_id' => $contentId,
            'started_at' => date('Y-m-d H:i:s')
        ];
        
        // Broadcast to all participants
        $this->broadcastToConversation($conversationId, self::EVENT_VIEW_SESSION_START, $session);
        
        return [
            'success' => true,
            'session' => $session
        ];
    }
    
    /**
     * End a view together session
     */
    public function endViewSession(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access to conversation
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        // Broadcast end to all participants
        $this->broadcastToConversation($conversationId, self::EVENT_VIEW_SESSION_END, [
            'conversation_id' => $conversationId,
            'ended_by' => [
                'id' => $colleague['id'],
                'name' => $colleague['display_name'] ?: explode('@', $colleague['email'])[0]
            ],
            'ended_at' => date('Y-m-d H:i:s')
        ]);
        
        return ['success' => true];
    }
    
    /**
     * Sync view position/cursor during a view together session
     * @param array $data Can contain 'position' and/or 'cursor' keys
     */
    public function syncViewPosition(int $conversationId, string $userEmail, array $data): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Check access to conversation
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        // Build broadcast payload
        $payload = [
            'conversation_id' => $conversationId,
            'user' => [
                'id' => $colleague['id'],
                'name' => $colleague['display_name'] ?: explode('@', $colleague['email'])[0]
            ],
            'timestamp' => round(microtime(true) * 1000)
        ];
        
        // Include position if provided
        if (isset($data['position'])) {
            $payload['position'] = $data['position'];
        }
        
        // Include cursor if provided
        if (isset($data['cursor'])) {
            $payload['cursor'] = $data['cursor'];
        }
        
        // Include sync scroll mode if provided (presenter mode)
        if (isset($data['syncScroll'])) {
            $payload['syncScroll'] = $data['syncScroll'];
        }
        
        // Broadcast to all participants
        $this->broadcastToConversation($conversationId, self::EVENT_VIEW_SYNC, $payload);
        
        return ['success' => true];
    }
    
    // ========================================
    // GROUP CHAT MANAGEMENT
    // ========================================
    
    /**
     * Create a new group conversation
     */
    public function createGroup(string $creatorEmail, array $memberIds, string $name, ?string $description = null): array
    {
        $creator = $this->getColleague($creatorEmail);
        if (!$creator) {
            return ['success' => false, 'error' => 'Creator not found'];
        }
        
        if (empty($name)) {
            return ['success' => false, 'error' => 'Group name is required'];
        }
        
        if (count($memberIds) < 1) {
            return ['success' => false, 'error' => 'At least one member is required'];
        }
        
        // Validate all member IDs exist and are in same domain
        $validMembers = [];
        foreach ($memberIds as $memberId) {
            $member = $this->getColleagueById($memberId);
            if (!$member) {
                continue; // Skip invalid member
            }
            if ($member['organization_domain'] !== $creator['organization_domain']) {
                continue; // Skip cross-domain
            }
            $validMembers[] = $member;
        }
        
        try {
            $this->db->beginTransaction();
            
            // Create conversation
            $stmt = $this->db->prepare('
                INSERT INTO chat_conversations (organization_domain, type, name, description, created_by)
                VALUES (?, "group", ?, ?, ?)
            ');
            $stmt->execute([
                $creator['organization_domain'],
                $name,
                $description,
                $creator['id']
            ]);
            $conversationId = (int)$this->db->lastInsertId();
            
            // Add creator as admin
            $stmt = $this->db->prepare('
                INSERT INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                VALUES (?, ?, 1, ?)
            ');
            $stmt->execute([$conversationId, $creator['id'], $creator['id']]);
            
            // Add members
            $stmt = $this->db->prepare('
                INSERT INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                VALUES (?, ?, 0, ?)
            ');
            foreach ($validMembers as $member) {
                if ($member['id'] !== $creator['id']) {
                    $stmt->execute([$conversationId, $member['id'], $creator['id']]);
                }
            }
            
            // Add system message
            $this->createSystemMessage($conversationId, $creator['id'], 'created this group');
            
            $this->db->commit();
            
            // Broadcast to all members
            $conversation = $this->getConversationInternal($conversationId);
            $this->broadcastToConversation($conversationId, self::EVENT_CONVERSATION_CREATED, [
                'conversation' => $conversation
            ]);
            
            return $this->getConversation($conversationId, $creatorEmail);
            
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("ChatService: Failed to create group: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create group'];
        }
    }
    
    /**
     * Create a meeting conversation (group chat for a scheduled meeting)
     * Used by CalendarController::createMeeting
     */
    public function createMeetingConversation(string $organizerEmail, string $meetingTitle, array $participantEmails = []): array
    {
        $organizer = $this->getColleague($organizerEmail);
        if (!$organizer) {
            return ['success' => false, 'error' => 'Organizer not found in colleagues'];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Create conversation of type "group" with a meeting name
            $name = "Meeting: {$meetingTitle}";
            $stmt = $this->db->prepare('
                INSERT INTO chat_conversations (organization_domain, type, name, description, created_by)
                VALUES (?, "group", ?, ?, ?)
            ');
            $stmt->execute([
                $organizer['organization_domain'],
                $name,
                "Meeting chat for: {$meetingTitle}",
                $organizer['id']
            ]);
            $conversationId = (int)$this->db->lastInsertId();
            
            // Add organizer as admin
            $stmt = $this->db->prepare('
                INSERT INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                VALUES (?, ?, 1, ?)
            ');
            $stmt->execute([$conversationId, $organizer['id'], $organizer['id']]);
            
            // Add participants by email if they are colleagues in the same domain
            if (!empty($participantEmails)) {
                $addStmt = $this->db->prepare('
                    INSERT IGNORE INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                    VALUES (?, ?, 0, ?)
                ');
                foreach ($participantEmails as $email) {
                    $email = strtolower(trim($email));
                    if ($email === strtolower($organizerEmail)) continue;
                    
                    $member = $this->getColleague($email);
                    if ($member && $member['organization_domain'] === $organizer['organization_domain']) {
                        $addStmt->execute([$conversationId, $member['id'], $organizer['id']]);
                    }
                }
            }
            
            // System message
            $this->createSystemMessage($conversationId, $organizer['id'], "created meeting: {$meetingTitle}");
            
            $this->db->commit();
            
            // Broadcast
            $conversation = $this->getConversationInternal($conversationId);
            $this->broadcastToConversation($conversationId, self::EVENT_CONVERSATION_CREATED, [
                'conversation' => $conversation
            ]);
            
            return ['success' => true, 'conversation_id' => $conversationId];
            
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("ChatService: Failed to create meeting conversation: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create meeting conversation'];
        }
    }
    
    /**
     * Add a participant to an existing conversation by email
     * Used when an external participant accepts a meeting invitation
     */
    public function addParticipantByEmail(int $conversationId, string $participantEmail, string $addedByEmail): array
    {
        $participant = $this->getColleague($participantEmail);
        if (!$participant) {
            return ['success' => false, 'error' => 'Participant not found in colleagues'];
        }
        
        $addedBy = $this->getColleague($addedByEmail);
        if (!$addedBy) {
            return ['success' => false, 'error' => 'Added-by user not found'];
        }
        
        try {
            $stmt = $this->db->prepare('
                INSERT IGNORE INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
                VALUES (?, ?, 0, ?)
            ');
            $stmt->execute([$conversationId, $participant['id'], $addedBy['id']]);
            
            return ['success' => true];
        } catch (\PDOException $e) {
            error_log("ChatService: Failed to add participant: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to add participant'];
        }
    }
    
    /**
     * Create group from organization colleague group
     */
    public function createGroupFromColleagueGroup(string $creatorEmail, int $groupId, ?string $customName = null): array
    {
        $creator = $this->getColleague($creatorEmail);
        if (!$creator) {
            return ['success' => false, 'error' => 'Creator not found'];
        }
        
        // Get colleague group
        $stmt = $this->db->prepare('
            SELECT g.*, 
                   GROUP_CONCAT(gm.colleague_id) as member_ids
            FROM colleague_groups g
            LEFT JOIN colleague_group_members gm ON g.id = gm.group_id
            WHERE g.id = ? AND g.organization_domain = ?
            GROUP BY g.id
        ');
        $stmt->execute([$groupId, $creator['organization_domain']]);
        $group = $stmt->fetch();
        
        if (!$group) {
            return ['success' => false, 'error' => 'Group not found'];
        }
        
        $memberIds = $group['member_ids'] ? array_map('intval', explode(',', $group['member_ids'])) : [];
        $groupName = $customName ?: $group['name'];
        
        return $this->createGroup($creatorEmail, $memberIds, $groupName, $group['description'] ?? null);
    }
    
    /**
     * Add members to a group
     */
    public function addGroupMembers(int $conversationId, string $requesterEmail, array $memberIds): array
    {
        $requester = $this->getColleague($requesterEmail);
        if (!$requester) {
            return ['success' => false, 'error' => 'Requester not found'];
        }
        
        // Check requester is admin of the group
        if (!$this->isGroupAdmin($conversationId, $requester['id'])) {
            return ['success' => false, 'error' => 'Only admins can add members'];
        }
        
        // Get conversation (allow both direct and group - direct will be auto-upgraded)
        $stmt = $this->db->prepare('SELECT * FROM chat_conversations WHERE id = ?');
        $stmt->execute([$conversationId]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            return ['success' => false, 'error' => 'Conversation not found'];
        }
        
        // Auto-upgrade direct conversation to group when adding members
        if ($conversation['type'] === 'direct') {
            $this->db->prepare('UPDATE chat_conversations SET type = "group" WHERE id = ?')->execute([$conversationId]);
            $conversation['type'] = 'group';
        }
        
        $addedMembers = [];
        $stmt = $this->db->prepare('
            INSERT IGNORE INTO chat_participants (conversation_id, colleague_id, is_admin, added_by)
            VALUES (?, ?, 0, ?)
        ');
        
        foreach ($memberIds as $memberId) {
            $member = $this->getColleagueById($memberId);
            if (!$member || $member['organization_domain'] !== $conversation['organization_domain']) {
                continue;
            }
            
            $stmt->execute([$conversationId, $memberId, $requester['id']]);
            if ($stmt->rowCount() > 0) {
                $addedMembers[] = $member;
            }
        }
        
        if (count($addedMembers) > 0) {
            // Create system message
            $names = array_map(fn($m) => $m['display_name'] ?: explode('@', $m['email'])[0], $addedMembers);
            $this->createSystemMessage($conversationId, $requester['id'], 'added ' . implode(', ', $names));
            
            // Broadcast update
            $this->broadcastToConversation($conversationId, 'CHAT_GROUP_MEMBERS_ADDED', [
                'conversation_id' => $conversationId,
                'added_by' => $requester['id'],
                'members' => $addedMembers
            ]);
        }
        
        return ['success' => true, 'added_count' => count($addedMembers)];
    }
    
    /**
     * Remove a member from a group
     */
    public function removeGroupMember(int $conversationId, string $requesterEmail, int $memberId): array
    {
        $requester = $this->getColleague($requesterEmail);
        if (!$requester) {
            return ['success' => false, 'error' => 'Requester not found'];
        }
        
        // Can remove self, or admin can remove others
        $isSelfRemoval = $requester['id'] === $memberId;
        if (!$isSelfRemoval && !$this->isGroupAdmin($conversationId, $requester['id'])) {
            return ['success' => false, 'error' => 'Only admins can remove members'];
        }
        
        // Get member info
        $member = $this->getColleagueById($memberId);
        if (!$member) {
            return ['success' => false, 'error' => 'Member not found'];
        }
        
        // Remove from participants
        $stmt = $this->db->prepare('
            DELETE FROM chat_participants 
            WHERE conversation_id = ? AND colleague_id = ?
        ');
        $stmt->execute([$conversationId, $memberId]);
        
        if ($stmt->rowCount() > 0) {
            $memberName = $member['display_name'] ?: explode('@', $member['email'])[0];
            if ($isSelfRemoval) {
                $this->createSystemMessage($conversationId, $requester['id'], 'left the group');
            } else {
                $this->createSystemMessage($conversationId, $requester['id'], "removed $memberName");
            }
            
            $this->broadcastToConversation($conversationId, 'CHAT_GROUP_MEMBER_REMOVED', [
                'conversation_id' => $conversationId,
                'removed_by' => $requester['id'],
                'member_id' => $memberId
            ]);
        }
        
        return ['success' => true];
    }
    
    /**
     * Batched remove-members. One auth check, one admin check, ONE
     * DELETE WHERE IN(...). System messages + broadcast still happen
     * per removed member (existing per-member contract).
     *
     * @param int $conversationId
     * @param string $requesterEmail
     * @param array<int> $memberIds
     * @return array{success:bool, removed?:int, error?:string}
     */
    public function removeGroupMembersBatch(int $conversationId, string $requesterEmail, array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), fn($x) => $x > 0)));
        if (empty($memberIds)) {
            return ['success' => true, 'removed' => 0];
        }

        $requester = $this->getColleague($requesterEmail);
        if (!$requester) {
            return ['success' => false, 'error' => 'Requester not found'];
        }

        $isAdmin = $this->isGroupAdmin($conversationId, $requester['id']);
        // Anything other than removing ONLY yourself requires admin.
        if (!$isAdmin && (count($memberIds) > 1 || (int)$memberIds[0] !== (int)$requester['id'])) {
            return ['success' => false, 'error' => 'Only admins can remove members'];
        }

        // Pre-fetch display names so we can craft system messages for
        // each one without an N+1 lookup loop.
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $colStmt = $this->db->prepare(
            "SELECT id, email, display_name FROM colleagues WHERE id IN ({$placeholders})"
        );
        $colStmt->execute($memberIds);
        $colleagues = [];
        foreach ($colStmt->fetchAll() as $c) $colleagues[(int)$c['id']] = $c;

        // ONE DELETE for the whole batch.
        $delStmt = $this->db->prepare(
            "DELETE FROM chat_participants
             WHERE conversation_id = ? AND colleague_id IN ({$placeholders})"
        );
        $delStmt->execute(array_merge([$conversationId], $memberIds));
        $removed = $delStmt->rowCount();

        if ($removed > 0) {
            foreach ($memberIds as $mid) {
                $member = $colleagues[$mid] ?? null;
                if (!$member) continue;
                $name = $member['display_name'] ?: explode('@', $member['email'])[0];
                if ($mid === (int)$requester['id']) {
                    $this->createSystemMessage($conversationId, $requester['id'], 'left the group');
                } else {
                    $this->createSystemMessage($conversationId, $requester['id'], "removed $name");
                }
                $this->broadcastToConversation($conversationId, 'CHAT_GROUP_MEMBER_REMOVED', [
                    'conversation_id' => $conversationId,
                    'removed_by' => $requester['id'],
                    'member_id' => $mid,
                ]);
            }
        }

        return ['success' => true, 'removed' => $removed];
    }

    /**
     * Update group info (name, description, avatar)
     */
    public function updateGroup(int $conversationId, string $requesterEmail, array $updates): array
    {
        $requester = $this->getColleague($requesterEmail);
        if (!$requester) {
            return ['success' => false, 'error' => 'Requester not found'];
        }
        
        if (!$this->isGroupAdmin($conversationId, $requester['id'])) {
            return ['success' => false, 'error' => 'Only admins can update group'];
        }
        
        $allowedFields = ['name', 'description', 'avatar'];
        $sets = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($updates[$field])) {
                $sets[] = "$field = ?";
                $params[] = $updates[$field];
            }
        }
        
        if (empty($sets)) {
            return ['success' => false, 'error' => 'No valid fields to update'];
        }
        
        $params[] = $conversationId;
        $stmt = $this->db->prepare('UPDATE chat_conversations SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        
        // Broadcast update
        $this->broadcastToConversation($conversationId, 'CHAT_GROUP_UPDATED', [
            'conversation_id' => $conversationId,
            'updated_by' => $requester['id'],
            'updates' => $updates
        ]);
        
        return ['success' => true];
    }
    
    /**
     * Promote/demote group admin
     */
    public function setGroupAdmin(int $conversationId, string $requesterEmail, int $memberId, bool $isAdmin): array
    {
        $requester = $this->getColleague($requesterEmail);
        if (!$requester) {
            return ['success' => false, 'error' => 'Requester not found'];
        }
        
        if (!$this->isGroupAdmin($conversationId, $requester['id'])) {
            return ['success' => false, 'error' => 'Only admins can change admin status'];
        }
        
        $stmt = $this->db->prepare('
            UPDATE chat_participants SET is_admin = ?
            WHERE conversation_id = ? AND colleague_id = ?
        ');
        $stmt->execute([$isAdmin ? 1 : 0, $conversationId, $memberId]);
        
        $member = $this->getColleagueById($memberId);
        $memberName = $member ? ($member['display_name'] ?: explode('@', $member['email'])[0]) : 'Unknown';
        
        $action = $isAdmin ? "made $memberName an admin" : "removed $memberName from admins";
        $this->createSystemMessage($conversationId, $requester['id'], $action);
        
        return ['success' => true];
    }
    
    /**
     * Get group members
     */
    public function getGroupMembers(int $conversationId, string $requesterEmail): array
    {
        $requester = $this->getColleague($requesterEmail);
        if (!$requester) {
            return ['success' => false, 'error' => 'Requester not found'];
        }
        
        // Verify requester is a participant
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $requester['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Not a member of this group'];
        }
        
        $stmt = $this->db->prepare('
            SELECT c.id, c.email, c.display_name, c.job_title, c.avatar_path,
                   p.is_admin, p.joined_at, p.added_by
            FROM chat_participants p
            JOIN organization_colleagues c ON p.colleague_id = c.id
            WHERE p.conversation_id = ?
            ORDER BY p.is_admin DESC, c.display_name
        ');
        $stmt->execute([$conversationId]);
        $members = $stmt->fetchAll();
        
        return ['success' => true, 'members' => $members];
    }
    
    /**
     * Invite external user to a group
     */
    public function inviteToGroup(int $conversationId, string $inviterEmail, string $inviteeEmail, ?string $message = null): array
    {
        $inviter = $this->getColleague($inviterEmail);
        if (!$inviter) {
            return ['success' => false, 'error' => 'Inviter not found'];
        }
        
        // Check inviter is admin
        if (!$this->isGroupAdmin($conversationId, $inviter['id'])) {
            return ['success' => false, 'error' => 'Only admins can invite'];
        }
        
        // Get conversation (allow both direct and group - direct will be auto-upgraded)
        $stmt = $this->db->prepare('SELECT * FROM chat_conversations WHERE id = ?');
        $stmt->execute([$conversationId]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            return ['success' => false, 'error' => 'Conversation not found'];
        }
        
        // Auto-upgrade direct conversation to group when inviting external users
        if ($conversation['type'] === 'direct') {
            $this->db->prepare('UPDATE chat_conversations SET type = "group" WHERE id = ?')->execute([$conversationId]);
            $conversation['type'] = 'group';
        }
        
        // Check if invitee is already a colleague
        $existingColleague = $this->getColleagueByEmailAnyDomain($inviteeEmail);
        if ($existingColleague && $existingColleague['organization_domain'] === $conversation['organization_domain']) {
            // Already a colleague, just add them
            return $this->addGroupMembers($conversationId, $inviterEmail, [$existingColleague['id']]);
        }
        
        // Create group invitation
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('
            INSERT INTO chat_group_invitations (conversation_id, invited_email, invited_by, token, message, expires_at)
            VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ON DUPLICATE KEY UPDATE token = VALUES(token), status = "pending", expires_at = VALUES(expires_at)
        ');
        $stmt->execute([$conversationId, $inviteeEmail, $inviter['id'], $token, $message]);
        
        return [
            'success' => true,
            'token' => $token,
            'group_name' => $conversation['name'] ?: 'Group Chat',
            'invitee_email' => $inviteeEmail
        ];
    }
    
    /**
     * Check if user is group admin (chat group admin OR organization admin)
     * Organization admins can manage any group within their domain.
     */
    private function isGroupAdmin(int $conversationId, int $colleagueId): bool
    {
        // Check if user is a group-level admin for this conversation
        $stmt = $this->db->prepare('
            SELECT p.is_admin AS chat_admin, c.is_admin AS org_admin
            FROM chat_participants p
            JOIN organization_colleagues c ON c.id = p.colleague_id
            WHERE p.conversation_id = ? AND p.colleague_id = ?
        ');
        $stmt->execute([$conversationId, $colleagueId]);
        $row = $stmt->fetch();
        
        if (!$row) {
            // Not a member of this conversation - check if org admin anyway
            $stmt2 = $this->db->prepare('SELECT is_admin FROM organization_colleagues WHERE id = ?');
            $stmt2->execute([$colleagueId]);
            $orgRow = $stmt2->fetch();
            return $orgRow && (bool)$orgRow['is_admin'];
        }
        
        // Either chat group admin or organization admin
        return (bool)$row['chat_admin'] || (bool)$row['org_admin'];
    }
    
    /**
     * Format call message content into a readable preview
     */
    private function formatCallPreview(string $content): string
    {
        // Extract caller name (last email-like part before closing bracket)
        $callerName = 'unknown';
        if (preg_match_all('/([^:\]]+@[^:\]]+)/', $content, $emailMatches)) {
            $lastEmail = end($emailMatches[1]);
            $callerName = explode('@', $lastEmail)[0];
        }
        
        // Extract rejector name for declined (first email in declined content)
        $rejectorName = '';
        if (str_contains($content, ':declined:') && preg_match_all('/([^:\]]+@[^:\]]+)/', $content, $emailMatches) && count($emailMatches[1]) > 1) {
            $rejectorName = explode('@', $emailMatches[1][0])[0];
        }
        
        $isVideo = str_contains($content, ':video:');
        $videoPrefix = $isVideo ? 'video ' : '';
        
        if (str_contains($content, ':missed:')) return "Missed {$videoPrefix}call from {$callerName}";
        if (str_contains($content, ':completed:')) {
            // Extract duration (non-email part between type and caller)
            $duration = '00:00';
            if (preg_match('/\[call:completed:\w+:([^@\]]+?):[^:\]]+@/', $content, $m)) {
                $duration = trim($m[1]);
            }
            return $isVideo ? "Video call ({$duration})" : "Call ({$duration})";
        }
        if (str_contains($content, ':cancelled:')) return "Missed {$videoPrefix}call from {$callerName}";
        if (str_contains($content, ':declined:')) return $rejectorName ? "{$rejectorName} busy - call rejected" : 'Call rejected';
        return 'Call';
    }
    
    /**
     * Create a system message (for group/channel actions)
     */
    public function createSystemMessage(int $conversationId, int $actorId, string $action): void
    {
        $actor = $this->getColleagueById($actorId);
        $actorName = $actor ? ($actor['display_name'] ?: explode('@', $actor['email'])[0]) : 'Someone';
        
        $stmt = $this->db->prepare('
            INSERT INTO chat_messages (conversation_id, sender_id, content, content_type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$conversationId, $actorId, "$actorName $action", 'system']);
        
        // Update conversation
        $this->db->prepare('
            UPDATE chat_conversations 
            SET last_message_at = NOW(), 
                last_message_preview = ?,
                message_count = message_count + 1
            WHERE id = ?
        ')->execute(["$actorName $action", $conversationId]);
    }
    
    /**
     * Create a call system message (missed call, completed call, etc.)
     * Content format: [call:status:type:info:callerEmail]
     * Broadcasts to all participants in the conversation via Redis.
     */
    public function sendCallSystemMessage(int $conversationId, int $senderId, string $content): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO chat_messages (conversation_id, sender_id, content, content_type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$conversationId, $senderId, $content, 'call']);
        $messageId = (int)$this->db->lastInsertId();
        
        // Determine preview text using the shared formatter
        $preview = $this->formatCallPreview($content);
        
        // Update conversation preview
        $this->db->prepare('
            UPDATE chat_conversations 
            SET last_message_at = NOW(), 
                last_message_preview = ?,
                last_message_sender_id = ?,
                message_count = message_count + 1
            WHERE id = ?
        ')->execute([$preview, $senderId, $conversationId]);
        
        // Fetch the complete message for broadcast
        $stmt = $this->db->prepare('
            SELECT m.*, oc.email as sender_email, oc.display_name as sender_name, oc.avatar_path as sender_avatar
            FROM chat_messages m
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            WHERE m.id = ?
        ');
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        if ($message) {
            $message['attachments'] = [];
            $message['is_edited'] = false;
            $message['reactions'] = [];
            $message['reply_to'] = null;
            
            // Broadcast to all participants so it shows in real-time
            $this->broadcastToConversation($conversationId, self::EVENT_MESSAGE_NEW, [
                'conversation_id' => $conversationId,
                'message' => $message
            ]);
        }
    }
    
    /**
     * Resolve an embed reference to its current data
     * Used when a chat message contains [embed:TYPE:ID] and the recipient needs the item data
     */
    public function resolveEmbed(string $type, int $id, string $userEmail): array
    {
        try {
            switch ($type) {
                case 'drive_file':
                    return $this->resolveEmbedDriveFile($id, $userEmail);
                case 'drive_folder':
                    return $this->resolveEmbedDriveFolder($id, $userEmail);
                case 'calendar_event':
                    return $this->resolveEmbedCalendarEvent($id, $userEmail);
                case 'board':
                    return $this->resolveEmbedBoard($id, $userEmail);
                case 'board_card':
                    return $this->resolveEmbedBoardCard($id, $userEmail);
                case 'collab_doc':
                    return $this->resolveEmbedCollabDoc($id, $userEmail);
                case 'mood_board':
                    return $this->resolveEmbedMoodBoard($id, $userEmail);
                default:
                    return ['success' => false, 'error' => 'Unknown embed type'];
            }
        } catch (\Throwable $e) {
            error_log("ChatService::resolveEmbed error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to resolve embed'];
        }
    }
    
    private function resolveEmbedDriveFile(int $id, string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT f.id, f.original_name, f.size, f.mime_type, f.folder_id, f.user_email, f.share_token, f.created_at, f.updated_at,
                   df.name as folder_name
            FROM drive_files f
            LEFT JOIN drive_folders df ON f.folder_id = df.id
            WHERE f.id = ?
        ');
        $stmt->execute([$id]);
        $file = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$file) {
            return ['success' => false, 'error' => 'File not found'];
        }
        
        // Build folder path
        $folderPath = $file['folder_name'] ? '/' . $file['folder_name'] : '/';
        $isOwner = strtolower($file['user_email']) === strtolower($userEmail);
        
        return [
            'success' => true,
            'data' => [
                'type' => 'drive_file',
                'id' => $file['id'],
                'name' => $file['original_name'],
                'size' => (int)$file['size'],
                'mime_type' => $file['mime_type'],
                'folder_id' => $file['folder_id'],
                'folder_path' => $folderPath,
                'updated_at' => $file['updated_at'],
                'owner_email' => $file['user_email'],
                'is_own' => $isOwner,
                'share_token' => !$isOwner ? ($file['share_token'] ?? null) : null,
            ]
        ];
    }
    
    private function resolveEmbedDriveFolder(int $id, string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT df.id, df.name, df.parent_id, df.color, df.user_email, df.created_at,
                   (SELECT COUNT(*) FROM drive_files f WHERE f.folder_id = df.id) as file_count,
                   (SELECT COUNT(*) FROM drive_folders sf WHERE sf.parent_id = df.id AND (sf.is_trashed = 0 OR sf.is_trashed IS NULL)) as subfolder_count,
                   (SELECT COALESCE(SUM(f.size), 0) FROM drive_files f WHERE f.folder_id = df.id) as total_size
            FROM drive_folders df
            WHERE df.id = ? AND (df.is_trashed = 0 OR df.is_trashed IS NULL)
        ');
        $stmt->execute([$id]);
        $folder = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$folder) {
            return ['success' => false, 'error' => 'Folder not found'];
        }
        
        $isOwner = strtolower($folder['user_email']) === strtolower($userEmail);
        
        return [
            'success' => true,
            'data' => [
                'type' => 'drive_folder',
                'id' => $folder['id'],
                'name' => $folder['name'],
                'color' => $folder['color'],
                'parent_id' => $folder['parent_id'],
                'file_count' => (int)$folder['file_count'],
                'subfolder_count' => (int)$folder['subfolder_count'],
                'total_size' => (int)$folder['total_size'],
                'created_at' => $folder['created_at'],
                'owner_email' => $folder['user_email'],
                'is_own' => $isOwner,
            ]
        ];
    }
    
    private function resolveEmbedCalendarEvent(int $id, string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT e.id, e.title, e.description, e.location, e.start_time, e.end_time, e.all_day, e.color,
                   c.name as calendar_name, c.color as calendar_color, c.user_email as owner_email
            FROM calendar_events e
            JOIN calendars c ON e.calendar_id = c.id
            WHERE e.id = ?
        ');
        $stmt->execute([$id]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$event) {
            return ['success' => false, 'error' => 'Event not found'];
        }
        
        $isOwner = strtolower($event['owner_email']) === strtolower($userEmail);
        
        return [
            'success' => true,
            'data' => [
                'type' => 'calendar_event',
                'id' => $event['id'],
                'title' => $event['title'],
                'description' => $event['description'] ? mb_substr($event['description'], 0, 200) : null,
                'location' => $event['location'],
                'start_time' => $event['start_time'],
                'end_time' => $event['end_time'],
                'all_day' => (bool)$event['all_day'],
                'color' => $event['color'],
                'calendar_name' => $event['calendar_name'],
                'calendar_color' => $event['calendar_color'],
                'owner_email' => $event['owner_email'],
                'is_own' => $isOwner,
            ]
        ];
    }
    
    /**
     * Resolve the calendar meeting linked to a chat conversation.
     * Meeting conversations are linked one-way via calendar_events.meeting_conversation_id.
     * Returns the event id + timing + whether the requesting user is the host
     * (calendar owner), so the chat header can fetch meeting links/participants.
     */
    public function getMeetingForConversation(int $conversationId, string $userEmail): array
    {
        // Verify the requester is a member of this conversation.
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }
        $check = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $check->execute([$conversationId, $colleague['id']]);
        if (!$check->fetchColumn()) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        try {
            $stmt = $this->db->prepare('
                SELECT e.id, e.title, e.start_time, e.end_time, e.is_meeting,
                       c.user_email AS owner_email
                FROM calendar_events e
                JOIN calendars c ON e.calendar_id = c.id
                WHERE e.meeting_conversation_id = ? AND e.is_meeting = 1
                LIMIT 1
            ');
            $stmt->execute([$conversationId]);
            $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Column may not exist on older schemas.
            error_log('ChatService::getMeetingForConversation: ' . $e->getMessage());
            return ['success' => true, 'is_meeting' => false];
        }

        if (!$event) {
            return ['success' => true, 'is_meeting' => false];
        }

        return [
            'success' => true,
            'is_meeting' => true,
            'event_id' => (int)$event['id'],
            'title' => $event['title'],
            'start_time' => $event['start_time'],
            'end_time' => $event['end_time'],
            'is_host' => strtolower((string)$event['owner_email']) === strtolower($userEmail),
        ];
    }

    private function resolveEmbedBoard(int $id, string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT b.id, b.name, b.description, b.background_color, b.archived,
                   (SELECT COUNT(*) FROM webmail_board_lists l WHERE l.board_id = b.id AND l.archived = 0) as list_count,
                   (SELECT COUNT(*) FROM webmail_board_cards c JOIN webmail_board_lists l ON c.list_id = l.id WHERE l.board_id = b.id AND c.archived = 0) as card_count,
                   (SELECT COUNT(*) FROM webmail_board_members m WHERE m.board_id = b.id) as member_count
            FROM webmail_boards b
            WHERE b.id = ?
        ');
        $stmt->execute([$id]);
        $board = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$board) {
            return ['success' => false, 'error' => 'Board not found'];
        }
        
        return [
            'success' => true,
            'data' => [
                'type' => 'board',
                'id' => $board['id'],
                'name' => $board['name'],
                'description' => $board['description'] ? mb_substr($board['description'], 0, 200) : null,
                'background_color' => $board['background_color'],
                'archived' => (bool)$board['archived'],
                'list_count' => (int)$board['list_count'],
                'card_count' => (int)$board['card_count'],
                'member_count' => (int)$board['member_count'],
            ]
        ];
    }
    
    private function resolveEmbedBoardCard(int $id, string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT c.id, c.title, c.description, c.due_date, c.start_date, c.completed, c.cover_color,
                   c.assigned_to, c.archived,
                   l.name as list_name, l.board_id,
                   b.name as board_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            JOIN webmail_boards b ON l.board_id = b.id
            WHERE c.id = ?
        ');
        $stmt->execute([$id]);
        $card = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$card) {
            return ['success' => false, 'error' => 'Card not found'];
        }
        
        // Get labels for the card
        $labelStmt = $this->db->prepare('
            SELECT bl.name, bl.color
            FROM webmail_board_card_labels cl
            JOIN webmail_board_labels bl ON cl.label_id = bl.id
            WHERE cl.card_id = ?
        ');
        $labelStmt->execute([$id]);
        $labels = $labelStmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => [
                'type' => 'board_card',
                'id' => $card['id'],
                'title' => $card['title'],
                'description' => $card['description'] ? mb_substr($card['description'], 0, 200) : null,
                'due_date' => $card['due_date'],
                'start_date' => $card['start_date'],
                'completed' => (bool)$card['completed'],
                'cover_color' => $card['cover_color'],
                'assigned_to' => $card['assigned_to'],
                'archived' => (bool)$card['archived'],
                'list_name' => $card['list_name'],
                'board_id' => (int)$card['board_id'],
                'board_name' => $card['board_name'],
                'labels' => $labels,
            ]
        ];
    }
    
    private function resolveEmbedCollabDoc(int $id, string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT cd.id, cd.uuid, cd.owner_email, cd.title, cd.type, cd.created_at, cd.updated_at,
                   (SELECT COUNT(*) FROM collab_permissions cp WHERE cp.document_id = cd.id) as collaborator_count
            FROM collab_documents cd
            WHERE cd.id = ? AND cd.deleted_at IS NULL
        ');
        $stmt->execute([$id]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$doc) {
            return ['success' => false, 'error' => 'Document not found'];
        }
        
        $isOwner = strtolower($doc['owner_email']) === strtolower($userEmail);
        
        return [
            'success' => true,
            'data' => [
                'type' => 'collab_doc',
                'id' => (int)$doc['id'],
                'uuid' => $doc['uuid'],
                'title' => $doc['title'],
                'doc_type' => $doc['type'],
                'owner_email' => $doc['owner_email'],
                'is_own' => $isOwner,
                'collaborator_count' => (int)$doc['collaborator_count'],
                'created_at' => $doc['created_at'],
                'updated_at' => $doc['updated_at'],
            ]
        ];
    }
    
    private function resolveEmbedMoodBoard(int $id, string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT mb.id, mb.name, mb.description, mb.background_color, mb.archived,
                   (SELECT COUNT(*) FROM mood_board_items mbi WHERE mbi.board_id = mb.id) as item_count,
                   (SELECT COUNT(*) FROM mood_board_members mbm WHERE mbm.board_id = mb.id) as member_count
            FROM mood_boards mb
            WHERE mb.id = ?
        ');
        $stmt->execute([$id]);
        $board = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$board) {
            return ['success' => false, 'error' => 'Mood board not found'];
        }
        
        return [
            'success' => true,
            'data' => [
                'type' => 'mood_board',
                'id' => (int)$board['id'],
                'name' => $board['name'],
                'description' => $board['description'] ? mb_substr($board['description'], 0, 200) : null,
                'background_color' => $board['background_color'],
                'archived' => (bool)$board['archived'],
                'item_count' => (int)$board['item_count'],
                'member_count' => (int)$board['member_count'],
            ]
        ];
    }
    
    /**
     * Auto-share embedded content with all conversation participants.
     * Called after an embed message is sent so recipients can access the shared resource.
     * Best-effort: failures are logged but don't prevent the message from being sent.
     */
    private function autoShareEmbed(int $conversationId, string $senderEmail, string $embedType, int $embedId): void
    {
        try {
            // Get all participant emails except the sender
            $stmt = $this->db->prepare('
                SELECT oc.email
                FROM chat_participants cp
                JOIN organization_colleagues oc ON cp.colleague_id = oc.id
                WHERE cp.conversation_id = ? AND LOWER(oc.email) != LOWER(?)
            ');
            $stmt->execute([$conversationId, $senderEmail]);
            $participants = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            if (empty($participants)) return;
            
            switch ($embedType) {
                case 'drive_folder':
                    $this->autoShareDriveFolder($senderEmail, $embedId, $participants);
                    break;
                case 'drive_file':
                    $this->autoShareDriveFile($senderEmail, $embedId, $participants);
                    break;
                case 'board':
                    $this->autoShareBoard($senderEmail, $embedId, $participants);
                    break;
                case 'board_card':
                    $this->autoShareBoardCard($senderEmail, $embedId, $participants);
                    break;
                case 'calendar_event':
                    $this->autoShareCalendarEvent($senderEmail, $embedId, $participants);
                    break;
                case 'collab_doc':
                    $this->autoShareCollabDoc($senderEmail, $embedId, $participants);
                    break;
                case 'mood_board':
                    $this->autoShareMoodBoard($senderEmail, $embedId, $participants);
                    break;
            }
            
            // Create notifications for drive file/folder shares
            if (in_array($embedType, ['drive_file', 'drive_folder'])) {
                try {
                    $tracking = $this->getTrackingService();
                    $senderName = explode('@', $senderEmail)[0];
                    $itemType = $embedType === 'drive_file' ? 'file' : 'folder';
                    
                    // Try to get the item name
                    $itemName = $itemType;
                    if ($embedType === 'drive_file') {
                        $nameStmt = $this->db->prepare('SELECT filename FROM drive_files WHERE id = ?');
                        $nameStmt->execute([$embedId]);
                        $nameRow = $nameStmt->fetch();
                        if ($nameRow) $itemName = $nameRow['filename'];
                    } else {
                        $nameStmt = $this->db->prepare('SELECT name FROM drive_folders WHERE id = ?');
                        $nameStmt->execute([$embedId]);
                        $nameRow = $nameStmt->fetch();
                        if ($nameRow) $itemName = $nameRow['name'];
                    }
                    
                    foreach ($participants as $participantEmail) {
                        $tracking->createNotification(
                            strtolower($participantEmail),
                            'drive_share',
                            'File Shared With You',
                            "{$senderName} shared {$itemType} \"{$itemName}\" with you in chat",
                            [
                                'conversation_id' => $conversationId,
                                'embed_type' => $embedType,
                                'embed_id' => $embedId,
                                'item_name' => $itemName,
                                'sender_email' => $senderEmail,
                                'sender_name' => $senderName
                            ]
                        );
                    }
                } catch (\Throwable $e) {
                    error_log("ChatService: Drive share notification failed: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log("ChatService::autoShareEmbed error ({$embedType}:{$embedId}): " . $e->getMessage());
        }
    }
    
    /**
     * Auto-share a drive folder by adding participants as viewer collaborators
     */
    private function autoShareDriveFolder(string $senderEmail, int $folderId, array $participantEmails): void
    {
        try {
            // Look up folder owner
            $stmt = $this->db->prepare('SELECT user_email FROM drive_folders WHERE id = ?');
            $stmt->execute([$folderId]);
            $folder = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$folder) return;
            
            $ownerEmail = $folder['user_email'];
            
            // Only the folder owner can share it
            if (strtolower($ownerEmail) !== strtolower($senderEmail)) {
                error_log("ChatService::autoShareDriveFolder: sender {$senderEmail} is not the folder owner");
                return;
            }
            
            $driveService = new \Webmail\Services\DriveService($this->config);
            foreach ($participantEmails as $email) {
                $driveService->addFolderCollaborator($ownerEmail, $folderId, $email, 'viewer');
            }
            error_log("ChatService::autoShareDriveFolder: shared folder {$folderId} with " . count($participantEmails) . " participants");
        } catch (\Throwable $e) {
            error_log("ChatService::autoShareDriveFolder error: " . $e->getMessage());
        }
    }
    
    /**
     * Auto-share a drive file by creating a share link for it (does NOT share the parent folder)
     */
    private function autoShareDriveFile(string $senderEmail, int $fileId, array $participantEmails): void
    {
        try {
            // Look up file owner
            $stmt = $this->db->prepare('SELECT user_email, share_token FROM drive_files WHERE id = ?');
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$file) return;
            
            $ownerEmail = $file['user_email'];
            
            // Only the file owner can share it
            if (strtolower($ownerEmail) !== strtolower($senderEmail)) {
                error_log("ChatService::autoShareDriveFile: sender {$senderEmail} is not the file owner");
                return;
            }
            
            // Create a share link for this specific file (not the folder) if one doesn't exist
            if (empty($file['share_token'])) {
                $driveService = new \Webmail\Services\DriveService($this->config);
                $driveService->createShareLink($ownerEmail, $fileId, 2160); // 90-day expiry
                error_log("ChatService::autoShareDriveFile: created share link for file {$fileId} (90-day expiry)");
            } else {
                error_log("ChatService::autoShareDriveFile: file {$fileId} already has a share link");
            }
        } catch (\Throwable $e) {
            error_log("ChatService::autoShareDriveFile error: " . $e->getMessage());
        }
    }
    
    /**
     * Auto-share a board by adding participants as viewer members
     */
    private function autoShareBoard(string $senderEmail, int $boardId, array $participantEmails): void
    {
        try {
            // Look up board owner
            $stmt = $this->db->prepare('SELECT owner_email FROM webmail_boards WHERE id = ?');
            $stmt->execute([$boardId]);
            $board = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$board) return;
            
            $ownerEmail = strtolower($board['owner_email']);
            
            // Only the board owner can add members
            if ($ownerEmail !== strtolower($senderEmail)) {
                error_log("ChatService::autoShareBoard: sender {$senderEmail} is not the board owner");
                return;
            }
            
            foreach ($participantEmails as $email) {
                $email = strtolower($email);
                // Skip if already owner
                if ($email === $ownerEmail) continue;
                
                // Check if already a member
                $check = $this->db->prepare('SELECT id FROM webmail_board_members WHERE board_id = ? AND LOWER(user_email) = ?');
                $check->execute([$boardId, $email]);
                if ($check->fetch()) continue;
                
                // Add as viewer member
                $ins = $this->db->prepare('
                    INSERT INTO webmail_board_members (board_id, user_email, role, invited_by)
                    VALUES (?, ?, ?, ?)
                ');
                $ins->execute([$boardId, $email, 'viewer', $senderEmail]);
            }
            error_log("ChatService::autoShareBoard: shared board {$boardId} with " . count($participantEmails) . " participants");
        } catch (\Throwable $e) {
            error_log("ChatService::autoShareBoard error: " . $e->getMessage());
        }
    }
    
    /**
     * Auto-share a board card by adding participants to its parent board as viewer members
     */
    private function autoShareBoardCard(string $senderEmail, int $cardId, array $participantEmails): void
    {
        try {
            // Look up card → list → board
            $stmt = $this->db->prepare('
                SELECT l.board_id
                FROM webmail_board_cards c
                JOIN webmail_board_lists l ON c.list_id = l.id
                WHERE c.id = ?
            ');
            $stmt->execute([$cardId]);
            $card = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$card) return;
            
            // Delegate to board sharing
            $this->autoShareBoard($senderEmail, (int)$card['board_id'], $participantEmails);
        } catch (\Throwable $e) {
            error_log("ChatService::autoShareBoardCard error: " . $e->getMessage());
        }
    }
    
    /**
     * Auto-share a calendar event by silently adding participants (no invitation email)
     */
    // ========================================
    // THREADS
    // ========================================

    /**
     * Get a thread: parent message + all replies
     */
    public function getThread(int $messageId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Get parent message
        $stmt = $this->db->prepare('
            SELECT m.*, oc.display_name as sender_name, oc.email as sender_email, oc.avatar_path as sender_avatar
            FROM chat_messages m
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            WHERE m.id = ? AND m.deleted_at IS NULL
        ');
        $stmt->execute([$messageId]);
        $parent = $stmt->fetch();
        if (!$parent) {
            return ['success' => false, 'error' => 'Message not found'];
        }

        // Verify user is participant
        $check = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $check->execute([$parent['conversation_id'], $colleague['id']]);
        if (!$check->fetch()) {
            return ['success' => false, 'error' => 'Not a participant'];
        }

        // Get all replies to this message
        $stmt = $this->db->prepare('
            SELECT m.*, oc.display_name as sender_name, oc.email as sender_email, oc.avatar_path as sender_avatar
            FROM chat_messages m
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            WHERE m.reply_to_id = ? AND m.deleted_at IS NULL
            ORDER BY m.created_at ASC
        ');
        $stmt->execute([$messageId]);
        $replies = $stmt->fetchAll();

        $messages = [$parent, ...$replies];

        // Attach reactions to each message
        foreach ($messages as &$msg) {
            $stmt = $this->db->prepare('
                SELECT cr.emoji, oc.display_name as colleague_name, cr.colleague_id
                FROM chat_message_reactions cr
                JOIN organization_colleagues oc ON cr.colleague_id = oc.id
                WHERE cr.message_id = ?
            ');
            $stmt->execute([$msg['id']]);
            $msg['reactions'] = $stmt->fetchAll();
            $msg['attachments'] = json_decode($msg['attachments'] ?? '[]', true) ?: [];
            $msg['reply_to'] = null;
        }

        return ['success' => true, 'messages' => $messages];
    }

    /**
     * Get all active threads the user participates in (messages with replies)
     */
    public function getActiveThreads(string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Find all parent messages that have at least one reply,
        // in conversations where the user is a participant
        $stmt = $this->db->prepare('
            SELECT m.id, m.conversation_id, m.content, m.content_type, m.created_at,
                   oc.display_name as sender_name, oc.email as sender_email, oc.avatar_path as sender_avatar,
                   c.name as conversation_name, c.type as conversation_type,
                   (SELECT COUNT(*) FROM chat_messages r WHERE r.reply_to_id = m.id AND r.deleted_at IS NULL) as reply_count,
                   (SELECT MAX(r2.created_at) FROM chat_messages r2 WHERE r2.reply_to_id = m.id AND r2.deleted_at IS NULL) as last_reply_at
            FROM chat_messages m
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            JOIN chat_conversations c ON m.conversation_id = c.id
            JOIN chat_participants p ON p.conversation_id = m.conversation_id AND p.colleague_id = ?
            WHERE m.deleted_at IS NULL
              AND m.reply_to_id IS NULL
              AND EXISTS (SELECT 1 FROM chat_messages r3 WHERE r3.reply_to_id = m.id AND r3.deleted_at IS NULL)
            ORDER BY last_reply_at DESC
            LIMIT 50
        ');
        $stmt->execute([$colleague['id']]);
        $threads = $stmt->fetchAll();

        foreach ($threads as &$t) {
            $t['reply_count'] = (int)$t['reply_count'];
            $t['attachments'] = [];
        }

        return ['success' => true, 'threads' => $threads];
    }

    // ========================================
    // BOOKMARKS (SAVED MESSAGES)
    // ========================================

    /**
     * Toggle bookmark on a message
     */
    public function toggleBookmark(int $messageId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Ensure table exists
        $this->ensureBookmarksTable();

        // Check if already bookmarked
        $stmt = $this->db->prepare('SELECT id FROM chat_bookmarks WHERE message_id = ? AND colleague_id = ?');
        $stmt->execute([$messageId, $colleague['id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $this->db->prepare('DELETE FROM chat_bookmarks WHERE id = ?');
            $stmt->execute([$existing['id']]);
            return ['success' => true, 'bookmarked' => false];
        } else {
            $stmt = $this->db->prepare('INSERT INTO chat_bookmarks (message_id, colleague_id, created_at) VALUES (?, ?, NOW())');
            $stmt->execute([$messageId, $colleague['id']]);
            return ['success' => true, 'bookmarked' => true];
        }
    }

    /**
     * Get all bookmarked messages
     */
    public function getBookmarks(string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $this->ensureBookmarksTable();

        $stmt = $this->db->prepare('
            SELECT m.*, oc.display_name as sender_name, oc.email as sender_email,
                   cb.id as bookmark_id, cb.created_at as bookmarked_at,
                   c.name as conversation_name, c.type as conversation_type
            FROM chat_bookmarks cb
            JOIN chat_messages m ON cb.message_id = m.id
            JOIN organization_colleagues oc ON m.sender_id = oc.id
            JOIN chat_conversations c ON m.conversation_id = c.id
            WHERE cb.colleague_id = ? AND m.deleted_at IS NULL
            ORDER BY cb.created_at DESC
        ');
        $stmt->execute([$colleague['id']]);
        $bookmarks = $stmt->fetchAll();

        foreach ($bookmarks as &$bm) {
            $bm['attachments'] = json_decode($bm['attachments'] ?? '[]', true) ?: [];
        }

        return ['success' => true, 'bookmarks' => $bookmarks];
    }

    /**
     * Delete a bookmark
     */
    public function deleteBookmark(int $bookmarkId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $stmt = $this->db->prepare('DELETE FROM chat_bookmarks WHERE id = ? AND colleague_id = ?');
        $stmt->execute([$bookmarkId, $colleague['id']]);

        return ['success' => true];
    }

    private function ensureBookmarksTable(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'chat_bookmarks'");
            if ($result->rowCount() === 0) {
                $this->db->exec("
                    CREATE TABLE chat_bookmarks (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        message_id INT UNSIGNED NOT NULL,
                        colleague_id INT UNSIGNED NOT NULL,
                        created_at DATETIME NOT NULL,
                        UNIQUE KEY uk_bookmark (message_id, colleague_id),
                        INDEX idx_colleague (colleague_id, created_at DESC),
                        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
                        FOREIGN KEY (colleague_id) REFERENCES organization_colleagues(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                error_log("ChatService: Created chat_bookmarks table");
            }
        } catch (\PDOException $e) {
            error_log("ChatService: ensureBookmarksTable failed: " . $e->getMessage());
        }
    }

    // ========================================
    // SCHEDULED MESSAGES
    // ========================================

    /**
     * Schedule a message for later
     */
    public function scheduleMessage(int $conversationId, string $userEmail, string $content, string $scheduledAt): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $this->ensureScheduledMessagesTable();

        $dt = new \DateTime($scheduledAt);
        if ($dt <= new \DateTime()) {
            return ['success' => false, 'error' => 'Scheduled time must be in the future'];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            INSERT INTO chat_scheduled_messages (conversation_id, colleague_id, content, scheduled_at, created_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$conversationId, $colleague['id'], $content, $dt->format('Y-m-d H:i:s'), $now]);
        $id = (int)$this->db->lastInsertId();

        return ['success' => true, 'scheduled_message' => [
            'id' => $id,
            'conversation_id' => $conversationId,
            'content' => $content,
            'scheduled_at' => $dt->format('Y-m-d H:i:s')
        ]];
    }

    /**
     * Get all scheduled messages for a user
     */
    public function getScheduledMessages(string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $this->ensureScheduledMessagesTable();

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            SELECT sm.*, c.name as conversation_name, c.type as conversation_type
            FROM chat_scheduled_messages sm
            JOIN chat_conversations c ON sm.conversation_id = c.id
            WHERE sm.colleague_id = ? AND sm.status = \'pending\' AND sm.scheduled_at > ?
            ORDER BY sm.scheduled_at ASC
        ');
        $stmt->execute([$colleague['id'], $now]);

        return ['success' => true, 'messages' => $stmt->fetchAll()];
    }

    /**
     * Update a scheduled message
     */
    public function updateScheduledMessage(int $id, string $userEmail, array $body): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $sets = [];
        $params = [];

        if (isset($body['content'])) {
            $sets[] = 'content = ?';
            $params[] = $body['content'];
        }
        if (isset($body['scheduled_at'])) {
            $sets[] = 'scheduled_at = ?';
            $params[] = $body['scheduled_at'];
        }

        if (empty($sets)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        $params[] = $id;
        $params[] = $colleague['id'];
        $stmt = $this->db->prepare('UPDATE chat_scheduled_messages SET ' . implode(', ', $sets) . ' WHERE id = ? AND colleague_id = ?');
        $stmt->execute($params);

        return ['success' => true];
    }

    /**
     * Delete (cancel) a scheduled message
     */
    public function deleteScheduledMessage(int $id, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $stmt = $this->db->prepare('DELETE FROM chat_scheduled_messages WHERE id = ? AND colleague_id = ?');
        $stmt->execute([$id, $colleague['id']]);

        return ['success' => true];
    }

    /**
     * Process and send all pending scheduled messages whose time has arrived.
     * Called by cron every minute.
     * 
     * @return array Summary of processed messages
     */
    public function processScheduledMessages(): array
    {
        $this->ensureScheduledMessagesTable();

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            SELECT sm.*, oc.email as sender_email
            FROM chat_scheduled_messages sm
            JOIN organization_colleagues oc ON sm.colleague_id = oc.id
            WHERE sm.status = \'pending\' AND sm.scheduled_at <= ?
            ORDER BY sm.scheduled_at ASC
            LIMIT 50
        ');
        $stmt->execute([$now]);
        $pending = $stmt->fetchAll();

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($pending as $msg) {
            try {
                // Send the message using the existing sendMessage method
                $result = $this->sendMessage(
                    (int)$msg['conversation_id'],
                    $msg['sender_email'],
                    $msg['content']
                );

                if ($result['success']) {
                    // Mark as sent
                    $update = $this->db->prepare('UPDATE chat_scheduled_messages SET status = "sent" WHERE id = ?');
                    $update->execute([$msg['id']]);
                    $sent++;
                } else {
                    // Mark as failed but keep for retry? For now just log
                    $error = $result['error'] ?? 'Unknown error';
                    error_log("ChatService: Failed to send scheduled message #{$msg['id']}: {$error}");
                    $errors[] = "Message #{$msg['id']}: {$error}";
                    $failed++;
                }
            } catch (\Exception $e) {
                error_log("ChatService: Exception sending scheduled message #{$msg['id']}: " . $e->getMessage());
                $errors[] = "Message #{$msg['id']}: " . $e->getMessage();
                $failed++;
            }
        }

        return [
            'total_pending' => count($pending),
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function ensureScheduledMessagesTable(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'chat_scheduled_messages'");
            if ($result->rowCount() === 0) {
                $this->db->exec("
                    CREATE TABLE chat_scheduled_messages (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        conversation_id INT UNSIGNED NOT NULL,
                        colleague_id INT UNSIGNED NOT NULL,
                        content TEXT NOT NULL,
                        scheduled_at DATETIME NOT NULL,
                        status ENUM('pending','sent','cancelled') DEFAULT 'pending',
                        created_at DATETIME NOT NULL,
                        INDEX idx_colleague (colleague_id, status),
                        INDEX idx_scheduled (status, scheduled_at),
                        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
                        FOREIGN KEY (colleague_id) REFERENCES organization_colleagues(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                error_log("ChatService: Created chat_scheduled_messages table");
            }
        } catch (\PDOException $e) {
            error_log("ChatService: ensureScheduledMessagesTable failed: " . $e->getMessage());
        }
    }

    private function autoShareCalendarEvent(string $senderEmail, int $eventId, array $participantEmails): void
    {
        try {
            // Look up event to verify sender is the calendar owner
            $stmt = $this->db->prepare('
                SELECT e.id, c.user_email as calendar_owner
                FROM calendar_events e
                JOIN calendars c ON e.calendar_id = c.id
                WHERE e.id = ?
            ');
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$event) return;
            
            $calendarOwner = strtolower($event['calendar_owner']);
            
            // Only the calendar owner can share events
            if ($calendarOwner !== strtolower($senderEmail)) {
                error_log("ChatService::autoShareCalendarEvent: sender {$senderEmail} is not the calendar owner");
                return;
            }
            
            foreach ($participantEmails as $email) {
                $email = strtolower($email);
                
                // Check if already a participant
                $check = $this->db->prepare('SELECT id FROM calendar_event_participants WHERE event_id = ? AND LOWER(user_email) = ?');
                $check->execute([$eventId, $email]);
                if ($check->fetch()) continue;
                
                // Silently add as accepted participant (no invitation email)
                $token = bin2hex(random_bytes(32));
                $ins = $this->db->prepare("
                    INSERT INTO calendar_event_participants 
                    (event_id, user_email, invited_by_email, invite_token, status, responded_at)
                    VALUES (?, ?, ?, ?, 'accepted', NOW())
                ");
                $ins->execute([$eventId, $email, $senderEmail, $token]);
            }
            error_log("ChatService::autoShareCalendarEvent: shared event {$eventId} with " . count($participantEmails) . " participants");
        } catch (\Throwable $e) {
            error_log("ChatService::autoShareCalendarEvent error: " . $e->getMessage());
        }
    }

    /**
     * Auto-share a collab document by adding participants as viewers
     */
    private function autoShareCollabDoc(string $senderEmail, int $docId, array $participantEmails): void
    {
        try {
            // Verify sender owns or has editor access to the document
            $stmt = $this->db->prepare('SELECT owner_email FROM collab_documents WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$doc) return;
            
            $isOwner = strtolower($doc['owner_email']) === strtolower($senderEmail);
            if (!$isOwner) {
                // Check if sender has at least editor access
                $permStmt = $this->db->prepare('
                    SELECT role FROM collab_permissions 
                    WHERE document_id = ? AND LOWER(user_email) = LOWER(?) AND role IN ("owner", "editor")
                ');
                $permStmt->execute([$docId, $senderEmail]);
                if (!$permStmt->fetch()) {
                    error_log("ChatService::autoShareCollabDoc: sender {$senderEmail} lacks permission to share doc {$docId}");
                    return;
                }
            }
            
            foreach ($participantEmails as $email) {
                $email = strtolower($email);
                
                // Skip if already has permission
                $check = $this->db->prepare('SELECT id FROM collab_permissions WHERE document_id = ? AND LOWER(user_email) = ?');
                $check->execute([$docId, $email]);
                if ($check->fetch()) continue;
                
                // Add as viewer
                $ins = $this->db->prepare("
                    INSERT INTO collab_permissions (document_id, user_email, role, invited_by, accepted_at, created_at)
                    VALUES (?, ?, 'viewer', ?, NOW(), NOW())
                ");
                $ins->execute([$docId, $email, $senderEmail]);
            }
            error_log("ChatService::autoShareCollabDoc: shared doc {$docId} with " . count($participantEmails) . " participants");
        } catch (\Throwable $e) {
            error_log("ChatService::autoShareCollabDoc error: " . $e->getMessage());
        }
    }

    private function autoShareMoodBoard(string $senderEmail, int $boardId, array $participantEmails): void
    {
        try {
            // Verify the board exists and sender is owner or member
            $stmt = $this->db->prepare('SELECT owner_email FROM mood_boards WHERE id = ?');
            $stmt->execute([$boardId]);
            $board = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$board) return;
            
            foreach ($participantEmails as $email) {
                $email = strtolower($email);
                
                // Skip the owner
                if ($email === strtolower($board['owner_email'])) continue;
                
                // Skip if already a member
                $check = $this->db->prepare('SELECT id FROM mood_board_members WHERE board_id = ? AND LOWER(email) = ?');
                $check->execute([$boardId, $email]);
                if ($check->fetch()) continue;
                
                // Add as viewer
                $ins = $this->db->prepare("
                    INSERT INTO mood_board_members (board_id, email, role, invited_by)
                    VALUES (?, ?, 'viewer', ?)
                ");
                $ins->execute([$boardId, $email, $senderEmail]);
            }
            error_log("ChatService::autoShareMoodBoard: shared mood board {$boardId} with " . count($participantEmails) . " participants");
        } catch (\Throwable $e) {
            error_log("ChatService::autoShareMoodBoard error: " . $e->getMessage());
        }
    }

    // ========================================
    // DRIVE SHARING LOOKUP
    // ========================================

    /**
     * Get all drive file/folder IDs that have been shared in any chat
     * the user is a participant of.
     * Looks for embed patterns: [embed:drive_file:ID] and [embed:drive_folder:ID]
     */
    public function getSharedDriveIds(string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        try {
            // Find all embed messages in conversations the user is part of
            $stmt = $this->db->prepare('
                SELECT DISTINCT m.content
                FROM chat_messages m
                JOIN chat_participants p ON p.conversation_id = m.conversation_id AND p.colleague_id = ?
                WHERE m.deleted_at IS NULL
                  AND m.content_type = \'embed\'
                  AND (m.content LIKE \'[embed:drive_file:%]\' OR m.content LIKE \'[embed:drive_folder:%]\')
            ');
            $stmt->execute([$colleague['id']]);
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $fileIds = [];
            $folderIds = [];

            foreach ($rows as $content) {
                if (preg_match('/\[embed:drive_file:(\d+)\]/', $content, $m)) {
                    $fileIds[] = (int)$m[1];
                } elseif (preg_match('/\[embed:drive_folder:(\d+)\]/', $content, $m)) {
                    $folderIds[] = (int)$m[1];
                }
            }

            return [
                'success' => true,
                'file_ids' => array_values(array_unique($fileIds)),
                'folder_ids' => array_values(array_unique($folderIds)),
            ];
        } catch (\PDOException $e) {
            error_log("ChatService::getSharedDriveIds error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error'];
        }
    }
}

