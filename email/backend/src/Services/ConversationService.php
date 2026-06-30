<?php

namespace Webmail\Services;

/**
 * ConversationService - Persistent Conversation Membership
 * 
 * Manages email conversation grouping with database persistence.
 * Replaces on-the-fly computation for consistent counts and better performance.
 * 
 * Key features:
 * - Messages assigned to conversations on first fetch
 * - User overrides persist and always win
 * - Conversation counts are cached in database (and optionally Redis)
 * - Supports drag & drop message moving
 */
class ConversationService
{
    private \PDO $db;
    private array $config;
    private ?RedisCacheService $redisCache = null;
    private ?FolderIndexService $folderIndex = null;
    /** @var array<string,string|null> Per-request memoised folder->folder_id resolution. */
    private array $folderIdCache = [];
    
    /**
     * Normalize folder name for consistent storage
     * This ensures case-insensitive folder matching
     */
    private function normalizeFolder(string $folder): string
    {
        return strtolower(trim($folder));
    }
    
    /**
     * Decode and clean snippet text
     * Handles quoted-printable encoding and HTML that may have been stored
     */
    private function decodeSnippet(?string $snippet): ?string
    {
        if (empty($snippet)) {
            return $snippet;
        }
        
        // Decode quoted-printable if present (=XX patterns)
        if (preg_match('/=[0-9A-F]{2}/i', $snippet)) {
            // Fix soft line breaks first
            $snippet = str_replace("=\r\n", '', $snippet);
            $snippet = str_replace("=\n", '', $snippet);
            $snippet = str_replace("= ", '', $snippet); // Malformed soft break with space
            $snippet = quoted_printable_decode($snippet);
        }
        
        // Strip any HTML tags that may have slipped through
        // Using ?? to preserve value if preg_replace fails
        if (preg_match('/<[a-z!]/i', $snippet)) {
            $snippet = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $snippet) ?? $snippet;
            $snippet = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $snippet) ?? $snippet;
            $snippet = strip_tags($snippet ?? '');
        }
        
        // Decode HTML entities
        $snippet = html_entity_decode($snippet ?? '', ENT_QUOTES, 'UTF-8');
        
        // Normalize whitespace
        $snippet = preg_replace('/\s+/', ' ', $snippet) ?? $snippet;
        $snippet = trim($snippet ?? '');

        // If not valid UTF-8 try to detect and convert common Central European encodings
        if (!mb_check_encoding($snippet, 'UTF-8')) {
            $detected = mb_detect_encoding($snippet, ['UTF-8','ISO-8859-2','Windows-1250','ISO-8859-1'], true);
            if ($detected && $detected !== 'UTF-8') {
                $converted = @mb_convert_encoding($snippet, 'UTF-8', $detected);
                if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                    $snippet = $converted;
                }
            } else {
                // Try common fallbacks
                $tryEnc = ['ISO-8859-2','Windows-1250','CP1250','ISO-8859-1'];
                foreach ($tryEnc as $enc) {
                    $converted = @iconv($enc, 'UTF-8//TRANSLIT', $snippet);
                    if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                        $snippet = $converted;
                        break;
                    }
                }
            }
        }

        // Ensure final UTF-8 validity
        $snippet = mb_convert_encoding($snippet, 'UTF-8', 'UTF-8');

        return $snippet ?: null;
    }
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        try {
            $this->db = \Webmail\Core\Database::getConnection($config);
            
            \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
            
            // Initialize Redis cache (optional - degrades gracefully)
            try {
                $this->redisCache = new RedisCacheService($config);
            } catch (\Throwable $e) {
                error_log('[ConversationService] Redis not available, operating without cache: ' . $e->getMessage());
                $this->redisCache = null;
            }
        } catch (\Throwable $e) {
            error_log('[ConversationService] Constructor error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get the Redis cache service
     */
    private function getRedisCache(): ?RedisCacheService
    {
        if ($this->redisCache && $this->redisCache->isAvailable()) {
            return $this->redisCache;
        }
        return null;
    }

    /**
     * Lazy FolderIndexService for dual-write folder_id resolution. Returns
     * null if the service cannot be constructed; callers MUST treat that
     * as "fall back to the legacy (folder, uid) key".
     */
    private function getFolderIndex(): ?FolderIndexService
    {
        if ($this->folderIndex !== null) {
            return $this->folderIndex;
        }
        try {
            $this->folderIndex = new FolderIndexService($this->config);
        } catch (\Throwable $e) {
            error_log('[ConversationService] FolderIndexService init failed: ' . $e->getMessage());
            $this->folderIndex = null;
        }
        return $this->folderIndex;
    }

    /**
     * Resolve a folder path (already normalized) to its canonical UUIDv7,
     * memoised for the lifetime of this request to avoid hammering the
     * folder identity table on bulk message inserts.
     *
     * Returns null when the folder has not been upserted yet (very first
     * hit before any /mailbox/folders or /mailbox/init call has run for
     * this account). Callers MUST treat null as a hard failure -- post-
     * cutover, the legacy (folder, uid) key is gone, so without a
     * folder_id we cannot key a row.
     */
    private function resolveFolderId(string $userEmail, string $folder): ?string
    {
        $cacheKey = strtolower($userEmail) . '|' . $folder;
        if (array_key_exists($cacheKey, $this->folderIdCache)) {
            return $this->folderIdCache[$cacheKey];
        }
        $svc = $this->getFolderIndex();
        if ($svc === null) {
            return $this->folderIdCache[$cacheKey] = null;
        }
        try {
            $row = $svc->getByPath($userEmail, $folder);
        } catch (\Throwable $e) {
            error_log('[ConversationService] resolveFolderId failed for ' . $folder . ': ' . $e->getMessage());
            return $this->folderIdCache[$cacheKey] = null;
        }
        return $this->folderIdCache[$cacheKey] = ($row['id'] ?? null);
    }
    
    /**
     * Ensure required tables exist. Production schemas are managed by the
     * sequenced migrations (013, 017, 111, 160, 163, 165, 166); this
     * defensive CREATE TABLE only fires on a fresh install where no
     * migration has run yet, and uses the post-cutover schema (folder_id
     * mandatory, no legacy folder column).
     */
    private function ensureTablesExist(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_conversation_members (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(191) NOT NULL,
                    conversation_id VARCHAR(64) NOT NULL,
                    message_id VARCHAR(512) NOT NULL,
                    message_id_hash VARCHAR(32) NOT NULL,
                    folder_id CHAR(36) NOT NULL,
                    uid INT NOT NULL DEFAULT 0,
                    subject VARCHAR(512) DEFAULT NULL,
                    from_email VARCHAR(255) DEFAULT NULL,
                    from_name VARCHAR(255) DEFAULT NULL,
                    message_date DATETIME DEFAULT NULL,
                    is_user_override TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_msg_id (user_email, folder_id, message_id),
                    INDEX idx_conv (user_email, conversation_id),
                    INDEX idx_folder_id_conv (user_email, folder_id, conversation_id),
                    INDEX idx_user_folder_id_uid (user_email, folder_id, uid),
                    INDEX idx_msg_hash (user_email, message_id_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_conversations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(191) NOT NULL,
                    conversation_id VARCHAR(64) NOT NULL,
                    folder_id CHAR(36) NOT NULL,
                    subject VARCHAR(512) DEFAULT NULL,
                    message_count INT DEFAULT 0,
                    unread_count INT DEFAULT 0,
                    has_attachment TINYINT(1) DEFAULT 0,
                    latest_date DATETIME DEFAULT NULL,
                    latest_from VARCHAR(255) DEFAULT NULL,
                    latest_uid INT DEFAULT 0,
                    latest_message_id VARCHAR(512) DEFAULT NULL,
                    snippet VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_conv (user_email, conversation_id),
                    INDEX idx_latest_id (user_email, folder_id, latest_date DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Folder index tracking table - tracks which folders have been fully indexed
            // uidvalidity is critical for detecting when IMAP folder was rebuilt
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_folder_index (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(191) NOT NULL,
                    folder VARCHAR(191) NOT NULL,
                    is_indexed TINYINT(1) DEFAULT 0,
                    last_indexed_uid INT DEFAULT 0,
                    message_count INT DEFAULT 0,
                    uidvalidity INT NOT NULL DEFAULT 0,
                    indexed_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_folder (user_email, folder)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Migration: Add new columns to existing tables if they don't exist
            $this->migrateColumns();
            
        } catch (\PDOException $e) {
            error_log("ConversationService ensureTablesExist error: " . $e->getMessage());
            // Don't throw - tables might already exist with different schema
        }
    }
    
    /**
     * Add new columns to existing tables (migration)
     */
    private function migrateColumns(): void
    {
        // Check and add new columns to webmail_conversations
        $columnsToAdd = [
            'latest_uid' => 'INT DEFAULT 0',
            'latest_message_id' => 'VARCHAR(512) DEFAULT NULL',
            'snippet' => 'VARCHAR(255) DEFAULT NULL',
            'normalized_subject' => 'VARCHAR(512) DEFAULT NULL',
        ];
        
        foreach ($columnsToAdd as $column => $definition) {
            try {
                // Check if column exists
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as cnt FROM information_schema.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'webmail_conversations' 
                    AND COLUMN_NAME = ?
                ");
                $stmt->execute([$column]);
                $result = $stmt->fetch();
                
                if ($result && $result['cnt'] == 0) {
                    $this->db->exec("ALTER TABLE webmail_conversations ADD COLUMN $column $definition");
                    error_log("ConversationService: Added column $column to webmail_conversations");

                    if ($column === 'normalized_subject') {
                        try {
                            $this->db->exec("ALTER TABLE webmail_conversations ADD INDEX idx_norm_subject_id (user_email, folder_id, normalized_subject)");
                        } catch (\PDOException $idxErr) {
                            // Index may already exist
                        }
                    }
                }
            } catch (\PDOException $e) {
                error_log("ConversationService migration warning for $column: " . $e->getMessage());
            }
        }
        
        // Add highest_modseq to webmail_folder_index for CONDSTORE support
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'webmail_folder_index' 
                AND COLUMN_NAME = 'highest_modseq'
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            
            if ($result && $result['cnt'] == 0) {
                $this->db->exec("ALTER TABLE webmail_folder_index ADD COLUMN highest_modseq BIGINT DEFAULT 0");
                error_log("ConversationService: Added highest_modseq to webmail_folder_index");
            }
        } catch (\PDOException $e) {
            error_log("ConversationService migration warning for highest_modseq: " . $e->getMessage());
        }

        // Phase 1 (migration 180): mirror envelope/flag columns. Belt-and-
        // suspenders with the SQL migration so a fresh install that hits
        // ConversationService before the migration runner does still has
        // the columns it needs.
        $memberColumnsToAdd = [
            'to_recipients' => 'TEXT NULL',
            'cc_recipients' => 'TEXT NULL',
            'snippet'       => 'VARCHAR(512) NULL',
            'is_flagged'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_answered'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'internal_date' => 'DATETIME NULL',
            'rfc822_size'   => 'INT UNSIGNED NULL',
        ];
        foreach ($memberColumnsToAdd as $col => $def) {
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as cnt FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'webmail_conversation_members'
                    AND COLUMN_NAME = ?
                ");
                $stmt->execute([$col]);
                $result = $stmt->fetch();
                if ($result && (int)$result['cnt'] === 0) {
                    $this->db->exec("ALTER TABLE webmail_conversation_members ADD COLUMN $col $def");
                    error_log("ConversationService: Added column $col to webmail_conversation_members");
                }
            } catch (\PDOException $e) {
                error_log("ConversationService migration warning for member.$col: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Compute conversation_id from message RFC headers
     * Uses References > In-Reply-To > Message-ID hierarchy
     * Returns MD5 hash to ensure consistent length (32 chars)
     */
    public function computeConversationId(array $message, string $folder = 'unknown'): string
    {
        $references = $message['references'] ?? [];
        $inReplyTo = $message['in_reply_to'] ?? null;
        $messageId = $message['message_id'] ?? null;
        
        // Normalize references
        if (is_string($references)) {
            $references = preg_split('/\s+/', trim($references));
            $references = array_filter($references);
        }
        
        // Priority: first reference (thread root) > in_reply_to > message_id
        $rootId = null;
        if (!empty($references)) {
            $rootId = $this->normalizeMessageId($references[0]);
        } elseif ($inReplyTo) {
            $rootId = $this->normalizeMessageId($inReplyTo);
        }
        
        // If no threading headers, try subject-based fallback
        if (empty($rootId) && !empty($message['subject'])) {
            $normalizedSubject = $this->normalizeSubject($message['subject']);
            if (!empty($normalizedSubject) && strlen($normalizedSubject) >= 3) {
                $existingConv = $this->findConversationBySubject(
                    $message['_user_email'] ?? '',
                    $folder,
                    $normalizedSubject
                );
                if ($existingConv) {
                    return $existingConv;
                }
            }
        }
        
        // Use message_id as conversation root (new thread)
        if (empty($rootId)) {
            if ($messageId) {
                $rootId = $this->normalizeMessageId($messageId);
            } else {
                $msgFolder = $message['folder'] ?? $folder;
                $rootId = 'uid:' . $msgFolder . ':' . ($message['uid'] ?? uniqid());
            }
        }
        
        return md5($rootId);
    }
    
    /**
     * Find an existing conversation by normalized subject within a folder.
     * Used as fallback when threading headers (References/In-Reply-To) are missing.
     */
    private function findConversationBySubject(string $userEmail, string $folder, string $normalizedSubject): ?string
    {
        if (empty($userEmail)) {
            return null;
        }

        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return null;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT conversation_id FROM webmail_conversations
                WHERE user_email = ? AND folder_id = ? AND normalized_subject = ?
                ORDER BY latest_date DESC
                LIMIT 1
            ");
            $stmt->execute([strtolower($userEmail), $folderId, $normalizedSubject]);
            $row = $stmt->fetch();
            return $row ? $row['conversation_id'] : null;
        } catch (\PDOException $e) {
            // Column may not exist yet (pre-migration)
            return null;
        }
    }
    
    /**
     * Normalize a Message-ID (remove angle brackets, trim)
     */
    private function normalizeMessageId(string $id): string
    {
        return trim($id, '<> ');
    }

    /**
     * Encode an address list (To/Cc) for storage in the mirror.
     *
     * Accepts the shapes ImapService returns for envelope addresses:
     *   - array of { email, name } objects -> JSON-encoded as-is
     *   - string "Name <email@x>" or "a@x, b@y" -> parsed into the same shape
     *   - null/empty -> null (DB stores NULL, not an empty array)
     */
    private function encodeAddressList($addresses): ?string
    {
        if (empty($addresses)) {
            return null;
        }
        if (is_array($addresses)) {
            // Already in the {email,name} shape ImapService emits.
            $clean = [];
            foreach ($addresses as $addr) {
                if (is_array($addr)) {
                    $clean[] = [
                        'email' => isset($addr['email']) ? (string)$addr['email'] : '',
                        'name'  => isset($addr['name']) ? (string)$addr['name'] : '',
                    ];
                } elseif (is_string($addr) && $addr !== '') {
                    $clean[] = ['email' => $addr, 'name' => ''];
                }
            }
            return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
        }
        if (is_string($addresses)) {
            $parts = array_filter(array_map('trim', explode(',', $addresses)));
            $clean = [];
            foreach ($parts as $part) {
                if (preg_match('/^(.*)<([^>]+)>\s*$/', $part, $m)) {
                    $clean[] = ['email' => trim($m[2]), 'name' => trim($m[1], " \"'")];
                } else {
                    $clean[] = ['email' => $part, 'name' => ''];
                }
            }
            return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
        }
        return null;
    }

    /**
     * Trim a body snippet for the list view. Caps at 256 chars (the
     * column holds 512 so multi-byte sequences never overflow).
     */
    private function trimSnippet($snippet): ?string
    {
        if (!is_string($snippet)) {
            return null;
        }
        $snippet = preg_replace('/\s+/u', ' ', trim($snippet)) ?? '';
        if ($snippet === '') {
            return null;
        }
        return mb_substr($snippet, 0, 256);
    }

    /**
     * Normalize any date-ish value to MySQL DATETIME or NULL on failure.
     */
    private function normalizeDateTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Normalize a subject for fallback thread grouping.
     * Strips Re:/Fwd:/FW: prefixes, lowercases, trims.
     */
    public function normalizeSubject(string $subject): string
    {
        $subject = preg_replace('/^(\s*(Re|Fwd|FW|RE|Fw|re|fwd|fw)\s*:\s*)+/i', '', $subject) ?? $subject;
        $subject = mb_strtolower(trim($subject));
        return $subject;
    }
    
    /**
     * Get existing conversation assignment for a message
     */
    public function getMessageConversation(string $userEmail, string $folder, string $messageId): ?array
    {
        $folder = $this->normalizeFolder($folder);
        $userEmail = strtolower($userEmail);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return null;
        }
        $normalizedId = $this->normalizeMessageId($messageId);
        $hash = md5($normalizedId);
        $stmt = $this->db->prepare("
            SELECT * FROM webmail_conversation_members
            WHERE user_email = ? AND folder_id = ? AND message_id_hash = ?
        ");
        $stmt->execute([$userEmail, $folderId, $hash]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Assign a message to a conversation
     * Returns the conversation_id
     */
    public function assignMessageToConversation(
        string $userEmail,
        string $folder,
        array $message,
        ?string $forceConversationId = null
    ): string {
        $userEmail = strtolower($userEmail);
        $messageId = $this->normalizeMessageId($message['message_id'] ?? '');
        
        if (empty($messageId)) {
            $messageId = 'uid:' . $folder . ':' . ($message['uid'] ?? uniqid());
        }
        
        // Check if already assigned
        $existing = $this->getMessageConversation($userEmail, $folder, $messageId);
        if ($existing) {
            // If user override, don't change unless forcing with override
            if ($existing['is_user_override'] && !$forceConversationId) {
                return $existing['conversation_id'];
            }
            // If already assigned and no force, return existing
            if (!$forceConversationId) {
                return $existing['conversation_id'];
            }
        }
        
        // Inject user_email for subject-based fallback threading
        $message['_user_email'] = $userEmail;
        $conversationId = $forceConversationId ?? $this->computeConversationId($message, $folder);
        
        // Extract sender info
        $fromEmail = null;
        $fromName = null;
        if (isset($message['from'])) {
            if (is_array($message['from']) && !empty($message['from'])) {
                $from = $message['from'][0];
                $fromEmail = $from['email'] ?? null;
                $fromName = $from['name'] ?? null;
            } elseif (is_string($message['from'])) {
                $fromEmail = $message['from'];
            }
        }
        
        $messageIdHash = md5($messageId);
        $hasAttachment = !empty($message['has_attachment']) ? 1 : 0;
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            // Without a folder_id we cannot key the row. Surface the
            // condition loudly: dropping a row silently here would cause
            // missing-conversation bugs.
            error_log("[ConversationService] assignMessageToConversation skipped: no folder_id for {$userEmail}/{$folder}");
            return $conversationId;
        }

        // Phase 1 (migration 180): envelope/flag fields needed to render
        // the list view directly from the mirror. All NULLABLE — the
        // sync engine backfills any rows that pre-date this column set.
        $toJson       = $this->encodeAddressList($message['to'] ?? null);
        $ccJson       = $this->encodeAddressList($message['cc'] ?? null);
        $snippet      = $this->trimSnippet($message['snippet'] ?? null);
        $isFlagged    = !empty($message['flagged']) ? 1 : 0;
        $isAnswered   = !empty($message['answered']) ? 1 : 0;
        // The envelope carries the IMAP \Seen flag (formatMessageOverview /
        // parseFetchResponse). Persist it on insert so a mirror-served folder
        // reflects the real read state instead of defaulting the column to 0
        // (unread). Folders whose CONDSTORE flag-delta never runs (e.g. the
        // local Dovecot mailbox, whose highest_modseq is not seeded) would
        // otherwise show every already-read message as unread forever.
        $isSeen       = !empty($message['seen']) ? 1 : 0;
        $internalDate = $this->normalizeDateTime($message['internal_date'] ?? ($message['date'] ?? null));
        $rfc822Size   = isset($message['rfc822_size']) ? (int)$message['rfc822_size']
                       : (isset($message['size']) ? (int)$message['size'] : null);

        $stmt = $this->db->prepare("
            INSERT INTO webmail_conversation_members
                (user_email, conversation_id, message_id, message_id_hash, folder_id, uid,
                 subject, from_email, from_name, message_date, has_attachment, is_user_override,
                 to_recipients, cc_recipients, snippet, is_flagged, is_answered, is_seen,
                 internal_date, rfc822_size, last_verified_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                conversation_id = VALUES(conversation_id),
                message_id = VALUES(message_id),
                uid = VALUES(uid),
                subject = COALESCE(VALUES(subject), subject),
                from_email = COALESCE(VALUES(from_email), from_email),
                from_name = COALESCE(VALUES(from_name), from_name),
                message_date = COALESCE(VALUES(message_date), message_date),
                has_attachment = GREATEST(has_attachment, VALUES(has_attachment)),
                is_user_override = VALUES(is_user_override),
                to_recipients = COALESCE(VALUES(to_recipients), to_recipients),
                cc_recipients = COALESCE(VALUES(cc_recipients), cc_recipients),
                snippet = COALESCE(VALUES(snippet), snippet),
                is_flagged = VALUES(is_flagged),
                is_answered = VALUES(is_answered),
                -- Never regress a read->unread on re-sync: the authoritative
                -- unread transitions flow through the CONDSTORE flag-delta and
                -- updateMembersReadStatusBatch paths. A re-ingested envelope can
                -- only promote unread->read, never the reverse.
                is_seen = GREATEST(is_seen, VALUES(is_seen)),
                internal_date = COALESCE(VALUES(internal_date), internal_date),
                rfc822_size = COALESCE(VALUES(rfc822_size), rfc822_size),
                last_verified_at = NOW(),
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $userEmail,
            $conversationId,
            $messageId,
            $messageIdHash,
            $folderId,
            $message['uid'] ?? 0,
            $message['subject'] ?? null,
            $fromEmail,
            $fromName,
            isset($message['date']) ? date('Y-m-d H:i:s', strtotime($message['date'])) : null,
            $hasAttachment,
            $forceConversationId ? 1 : 0,
            $toJson,
            $ccJson,
            $snippet,
            $isFlagged,
            $isAnswered,
            $isSeen,
            $internalDate,
            $rfc822Size,
        ]);

        $rowsAffected = $stmt->rowCount();
        error_log("[ConversationService] Inserted/updated message in conversation. Rows affected: $rowsAffected, ConvID: $conversationId, MsgID: $messageId, Folder: $folder");

        $this->updateConversationMetadata($userEmail, $conversationId, $folder);
        error_log("[ConversationService] Updated conversation metadata for: $conversationId");

        return $conversationId;
    }
    
    /**
     * Batch assign messages to conversations
     * Returns map of message_id -> conversation_id
     */
    public function assignMessagesToConversations(string $userEmail, string $folder, array $messages): array
    {
        $result = [];
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);

        if (empty($messages)) {
            return $result;
        }

        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            error_log("[ConversationService] assignMessagesToConversations skipped: no folder_id for {$userEmail}/{$folder}");
            return $result;
        }

        // Phase 1.5: fast-path skip when every incoming UID is already at or
        // below the persisted watermark for this folder. Pagination and
        // folder navigation hit this function on every page load; without
        // this guard a 50-message page issues a SELECT plus per-message
        // assignment work on every reload even when nothing new has arrived.
        // Messages with uid <= last_indexed_uid have provably been processed
        // in a previous pass, so we can short-circuit. Messages with uid = 0
        // (placeholders / sent items waiting for IMAP confirmation) are
        // intentionally NOT skipped so updateMemberUidIfZero still fires.
        $uidsInBatch = array_map(fn($m) => (int)($m['uid'] ?? 0), $messages);
        $minUid = !empty($uidsInBatch) ? min($uidsInBatch) : 0;
        $maxUid = !empty($uidsInBatch) ? max($uidsInBatch) : 0;
        if ($minUid > 0) {
            $lastIndexedUid = $this->getLastIndexedUid($userEmail, $folder);
            if ($lastIndexedUid > 0 && $maxUid <= $lastIndexedUid) {
                // Nothing new on this page; everything already assigned in a
                // prior pass. Caller still receives an empty result array,
                // mirroring the previous "all already assigned" case where
                // we'd have done N SELECTs to discover the same thing.
                return $result;
            }
        }

        // Get existing assignments in bulk using message_id_hash for faster lookup
        $messageIdMap = []; // hash -> original normalized id
        foreach ($messages as $m) {
            $normalized = $this->normalizeMessageId($m['message_id'] ?? 'uid:' . $folder . ':' . ($m['uid'] ?? ''));
            if (!empty($normalized)) {
                $hash = md5($normalized);
                $messageIdMap[$hash] = $normalized;
            }
        }

        if (empty($messageIdMap)) {
            return $result;
        }

        $hashes = array_keys($messageIdMap);
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $stmt = $this->db->prepare("
            SELECT message_id, message_id_hash, conversation_id, is_user_override,
                   to_recipients, cc_recipients
            FROM webmail_conversation_members
            WHERE user_email = ? AND folder_id = ? AND message_id_hash IN ($placeholders)
        ");
        $params = array_merge([$userEmail, $folderId], $hashes);
        $stmt->execute($params);
        $existing = [];
        while ($row = $stmt->fetch()) {
            // Index by the original message_id for compatibility
            $existing[$row['message_id']] = $row;
        }
        
        // Process each message
        foreach ($messages as $message) {
            $messageId = $this->normalizeMessageId($message['message_id'] ?? 'uid:' . $folder . ':' . ($message['uid'] ?? ''));

            // Skip messages that were recently deleted/moved (prevents re-creation race condition)
            $uid = (int)($message['uid'] ?? 0);
            if ($uid > 0 && $this->isUidRecentlyDeleted($userEmail, $folder, $uid)) {
                continue;
            }
            
            // If already assigned with user override, keep it — but update uid if it was a placeholder (uid=0)
            if (isset($existing[$messageId]) && $existing[$messageId]['is_user_override']) {
                if ($uid > 0) {
                    $this->updateMemberUidIfZero($userEmail, $folder, $messageId, $uid);
                }
                $this->backfillMemberEnvelopeIfEmpty($userEmail, $folder, $messageId, $message, $existing[$messageId]);
                $result[$messageId] = $existing[$messageId]['conversation_id'];
                continue;
            }
            
            // If already assigned (no override), keep it — but update uid if it was a placeholder (uid=0)
            if (isset($existing[$messageId])) {
                if ($uid > 0) {
                    $this->updateMemberUidIfZero($userEmail, $folder, $messageId, $uid);
                }
                $this->backfillMemberEnvelopeIfEmpty($userEmail, $folder, $messageId, $message, $existing[$messageId]);
                $result[$messageId] = $existing[$messageId]['conversation_id'];
                continue;
            }
            
            // New message - assign to conversation
            $conversationId = $this->computeConversationId($message, $folder);
            
            // Check if any related message already has a conversation
            // This helps group messages that arrive out of order
            $conversationId = $this->findRelatedConversation($userEmail, $folder, $message) ?? $conversationId;
            
            $result[$messageId] = $this->assignMessageToConversation($userEmail, $folder, $message, $conversationId);
        }
        
        return $result;
    }
    
    /**
     * Update a conversation member's UID from 0 (placeholder) to a real IMAP UID.
     * Called when re-indexing a folder discovers the real UID for a previously sent message.
     */
    private function updateMemberUidIfZero(string $userEmail, string $folder, string $messageId, int $realUid): void
    {
        try {
            $folderId = $this->resolveFolderId($userEmail, $folder);
            if ($folderId === null) {
                return;
            }
            $hash = md5($messageId);
            $stmt = $this->db->prepare("
                UPDATE webmail_conversation_members
                SET uid = ?, updated_at = CURRENT_TIMESTAMP
                WHERE user_email = ? AND folder_id = ? AND message_id_hash = ? AND uid = 0
            ");
            $stmt->execute([$realUid, $userEmail, $folderId, $hash]);
        } catch (\PDOException $e) {
            error_log("[ConversationService] updateMemberUidIfZero failed: " . $e->getMessage());
        }
    }

    /**
     * Backfill envelope recipients on an already-assigned row when they are
     * missing. The send/compose path inserts Sent rows with no `to`, and the
     * bulk assign loop short-circuits existing rows (updating only the UID),
     * so a later full-envelope sync never repaired to_recipients. That left
     * mirror-served Sent/Drafts rendering the sender instead of "To: <x>".
     *
     * This is a pure envelope repair: COALESCE only fills NULL columns, the
     * WHERE clause restricts to rows that actually need it, and conversation
     * assignment, flags and read state are deliberately left untouched (so it
     * cannot re-thread a message or regress an unread flag).
     */
    private function backfillMemberEnvelopeIfEmpty(
        string $userEmail,
        string $folder,
        string $messageId,
        array $message,
        array $existingRow
    ): void {
        $hasStoredTo = !empty($existingRow['to_recipients']);
        $hasStoredCc = !empty($existingRow['cc_recipients']);
        if ($hasStoredTo && $hasStoredCc) {
            return;
        }

        $toJson = $hasStoredTo ? null : $this->encodeAddressList($message['to'] ?? null);
        $ccJson = $hasStoredCc ? null : $this->encodeAddressList($message['cc'] ?? null);
        if ($toJson === null && $ccJson === null) {
            return;
        }

        try {
            $folderId = $this->resolveFolderId($userEmail, $folder);
            if ($folderId === null) {
                return;
            }
            $hash = md5($messageId);
            $stmt = $this->db->prepare("
                UPDATE webmail_conversation_members
                   SET to_recipients = COALESCE(to_recipients, ?),
                       cc_recipients = COALESCE(cc_recipients, ?),
                       last_verified_at = NOW()
                 WHERE user_email = ? AND folder_id = ? AND message_id_hash = ?
                   AND (to_recipients IS NULL OR to_recipients = ''
                        OR cc_recipients IS NULL OR cc_recipients = '')
            ");
            $stmt->execute([$toJson, $ccJson, $userEmail, $folderId, $hash]);
        } catch (\PDOException $e) {
            error_log("[ConversationService] backfillMemberEnvelopeIfEmpty failed: " . $e->getMessage());
        }
    }

    /**
     * Find an existing conversation that this message belongs to
     * by checking its references and in_reply_to
     */
    private function findRelatedConversation(string $userEmail, string $folder, array $message): ?string
    {
        $references = $message['references'] ?? [];
        $inReplyTo = $message['in_reply_to'] ?? null;
        
        if (is_string($references)) {
            $references = preg_split('/\s+/', trim($references));
            $references = array_filter($references);
        }
        
        // Collect all possible related message IDs
        $relatedIds = [];
        foreach ($references as $ref) {
            $relatedIds[] = $this->normalizeMessageId($ref);
        }
        if ($inReplyTo) {
            $relatedIds[] = $this->normalizeMessageId($inReplyTo);
        }
        
        if (empty($relatedIds)) {
            return null;
        }
        
        // Check if any of these are already in a conversation (use hash for lookup)
        $hashes = array_map(fn($id) => md5($id), $relatedIds);
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $stmt = $this->db->prepare("
            SELECT conversation_id FROM webmail_conversation_members 
            WHERE user_email = ? AND message_id_hash IN ($placeholders)
            ORDER BY is_user_override DESC, created_at ASC
            LIMIT 1
        ");
        $params = array_merge([$userEmail], $hashes);
        $stmt->execute($params);
        $row = $stmt->fetch();
        
        return $row ? $row['conversation_id'] : null;
    }
    
    /**
     * Move a message to a different conversation (user action)
     */
    public function moveMessageToConversation(
        string $userEmail,
        string $folder,
        string $messageId,
        string $targetConversationId
    ): bool {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $messageId = $this->normalizeMessageId($messageId);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return false;
        }

        $existing = $this->getMessageConversation($userEmail, $folder, $messageId);
        $oldConversationId = $existing ? $existing['conversation_id'] : null;

        $hash = md5($messageId);
        $stmt = $this->db->prepare("
            UPDATE webmail_conversation_members
            SET conversation_id = ?, is_user_override = 1, updated_at = CURRENT_TIMESTAMP
            WHERE user_email = ? AND folder_id = ? AND message_id_hash = ?
        ");
        $stmt->execute([$targetConversationId, $userEmail, $folderId, $hash]);
        
        if ($stmt->rowCount() === 0) {
            // Message not in DB yet, we need to insert it
            // This shouldn't normally happen, but handle it gracefully
            return false;
        }
        
        // Update metadata for both conversations
        if ($oldConversationId && $oldConversationId !== $targetConversationId) {
            $this->updateConversationMetadata($userEmail, $oldConversationId, $folder);
        }
        $this->updateConversationMetadata($userEmail, $targetConversationId, $folder);
        
        return true;
    }
    
    /**
     * Split a message into a new conversation
     * Returns the new conversation_id
     */
    public function splitMessageToNewConversation(
        string $userEmail,
        string $folder,
        string $messageId
    ): string {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $messageId = $this->normalizeMessageId($messageId);
        $folderId = $this->resolveFolderId($userEmail, $folder);

        $existing = $this->getMessageConversation($userEmail, $folder, $messageId);
        $oldConversationId = $existing ? $existing['conversation_id'] : null;

        $newConversationId = 'split-' . bin2hex(random_bytes(8));

        if ($folderId !== null) {
            $hash = md5($messageId);
            $stmt = $this->db->prepare("
                UPDATE webmail_conversation_members
                SET conversation_id = ?, is_user_override = 1, updated_at = CURRENT_TIMESTAMP
                WHERE user_email = ? AND folder_id = ? AND message_id_hash = ?
            ");
            $stmt->execute([$newConversationId, $userEmail, $folderId, $hash]);
        }
        
        // Update metadata for both conversations
        if ($oldConversationId) {
            $this->updateConversationMetadata($userEmail, $oldConversationId, $folder);
        }
        $this->updateConversationMetadata($userEmail, $newConversationId, $folder);
        
        return $newConversationId;
    }
    
    /**
     * Merge two messages into a new conversation
     * Used when dragging one standalone email onto another
     */
    public function mergeMessagesToConversation(
        string $userEmail,
        string $folder,
        string $messageId1,
        string $messageId2
    ): ?string {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $messageId1 = $this->normalizeMessageId($messageId1);
        $messageId2 = $this->normalizeMessageId($messageId2);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return null;
        }

        $newConversationId = 'merge-' . bin2hex(random_bytes(8));

        $existing1 = $this->getMessageConversation($userEmail, $folder, $messageId1);
        $existing2 = $this->getMessageConversation($userEmail, $folder, $messageId2);

        $oldConvIds = [];
        if ($existing1) $oldConvIds[] = $existing1['conversation_id'];
        if ($existing2) $oldConvIds[] = $existing2['conversation_id'];

        $hash1 = md5($messageId1);
        $hash2 = md5($messageId2);

        $stmt = $this->db->prepare("
            UPDATE webmail_conversation_members
            SET conversation_id = ?, is_user_override = 1, updated_at = CURRENT_TIMESTAMP
            WHERE user_email = ? AND folder_id = ? AND message_id_hash IN (?, ?)
        ");
        $stmt->execute([$newConversationId, $userEmail, $folderId, $hash1, $hash2]);
        
        if ($stmt->rowCount() === 0) {
            return null;
        }
        
        // Update metadata for old conversations (if they exist)
        foreach (array_unique($oldConvIds) as $oldConvId) {
            $this->updateConversationMetadata($userEmail, $oldConvId, $folder);
        }
        
        // Update metadata for new conversation
        $this->updateConversationMetadata($userEmail, $newConversationId, $folder);
        
        return $newConversationId;
    }
    
    /**
     * Reset user override (restore auto-grouping)
     */
    public function resetMessageOverride(string $userEmail, string $folder, string $messageId): bool
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $messageId = $this->normalizeMessageId($messageId);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return false;
        }

        $existing = $this->getMessageConversation($userEmail, $folder, $messageId);
        if (!$existing) {
            return false;
        }

        // We need the original message data to recompute the conversation.
        // For now, just clear the override flag - the next fetch will reassign.
        $hash = md5($messageId);
        $stmt = $this->db->prepare("
            DELETE FROM webmail_conversation_members
            WHERE user_email = ? AND folder_id = ? AND message_id_hash = ?
        ");
        $stmt->execute([$userEmail, $folderId, $hash]);
        
        // Update the old conversation metadata
        $this->updateConversationMetadata($userEmail, $existing['conversation_id'], $folder);
        
        return true;
    }
    
    /**
     * Update conversation metadata (counts, latest message, etc.)
     * Also invalidates Redis cache for the affected folder
     */
    public function updateConversationMetadata(string $userEmail, string $conversationId, string $folder): void
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        
        // Invalidate Redis cache for ALL folders with members in this conversation
        // (member query returns cross-folder data, so all sibling folders need a refresh)
        $cache = $this->getRedisCache();
        if ($cache) {
            $cache->invalidateConversations($userEmail, $folder);
            try {
                $folderStmt = $this->db->prepare("
                    SELECT DISTINCT fi.current_path AS folder
                    FROM webmail_conversation_members m
                    LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
                    WHERE m.user_email = ? AND m.conversation_id = ?
                ");
                $folderStmt->execute([$userEmail, $conversationId]);
                while ($row = $folderStmt->fetch()) {
                    if (!empty($row['folder']) && $row['folder'] !== $folder) {
                        $cache->invalidateConversations($userEmail, $row['folder']);
                    }
                }
            } catch (\PDOException $e) {
                error_log("[ConversationService] Failed to invalidate sibling folder caches: " . $e->getMessage());
            }
        }
        
        // Get aggregated data for this conversation (try with has_attachment, fallback without)
        $hasAttachmentSupport = true;
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as message_count,
                    MAX(message_date) as latest_date,
                    MIN(subject) as subject,
                    MAX(has_attachment) as has_attachment
                FROM webmail_conversation_members
                WHERE user_email = ? AND conversation_id = ?
            ");
            $stmt->execute([$userEmail, $conversationId]);
            $data = $stmt->fetch();
        } catch (\PDOException $e) {
            $hasAttachmentSupport = false;
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as message_count,
                    MAX(message_date) as latest_date,
                    MIN(subject) as subject
                FROM webmail_conversation_members
                WHERE user_email = ? AND conversation_id = ?
            ");
            $stmt->execute([$userEmail, $conversationId]);
            $data = $stmt->fetch();
            $data['has_attachment'] = 0;
        }
        
        error_log("[ConversationService] updateConversationMetadata - ConvID: $conversationId, MessageCount: " . ($data['message_count'] ?? 0));
        
        if (!$data || $data['message_count'] == 0) {
            // No messages left - delete the conversation record
            $stmt = $this->db->prepare("
                DELETE FROM webmail_conversations 
                WHERE user_email = ? AND conversation_id = ?
            ");
            $stmt->execute([$userEmail, $conversationId]);
            return;
        }
        
        // Get latest message info
        $stmt = $this->db->prepare("
            SELECT uid, message_id, from_email, from_name, subject
            FROM webmail_conversation_members
            WHERE user_email = ? AND conversation_id = ?
            ORDER BY message_date DESC
            LIMIT 1
        ");
        $stmt->execute([$userEmail, $conversationId]);
        $latest = $stmt->fetch();
        
        $hasAttachment = (int)($data['has_attachment'] ?? 0);
        
        $subject = $latest['subject'] ?? $data['subject'];
        $normalizedSubject = $this->normalizeSubject($subject ?? '');
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            error_log("[ConversationService] updateConversationMetadata skipped: no folder_id for {$userEmail}/{$folder}");
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO webmail_conversations
                (user_email, conversation_id, folder_id, subject, normalized_subject, message_count, has_attachment, latest_date, latest_from, latest_uid, latest_message_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                folder_id = VALUES(folder_id),
                subject = VALUES(subject),
                normalized_subject = VALUES(normalized_subject),
                message_count = VALUES(message_count),
                has_attachment = VALUES(has_attachment),
                latest_date = VALUES(latest_date),
                latest_from = VALUES(latest_from),
                latest_uid = VALUES(latest_uid),
                latest_message_id = VALUES(latest_message_id),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $userEmail,
            $conversationId,
            $folderId,
            $subject,
            $normalizedSubject ?: null,
            $data['message_count'],
            $hasAttachment,
            $data['latest_date'],
            $latest['from_name'] ?? $latest['from_email'],
            $latest['uid'] ?? 0,
            $latest['message_id'] ?? null
        ]);
    }
    
    /**
     * Get all conversations for a folder with counts and message details
     * Returns display-ready data for DB-first loading
     * Uses Redis cache when available
     */
    public function getConversationsForFolder(string $userEmail, string $folder): array
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        
        // Try Redis cache first
        $cache = $this->getRedisCache();
        if ($cache) {
            $cached = $cache->getConversations($userEmail, $folder);
            if ($cached !== null) {
                // Decode snippets from cache (may contain old encoded data)
                foreach ($cached as &$conv) {
                    if (isset($conv['snippet'])) {
                        $conv['snippet'] = $this->decodeSnippet($conv['snippet']);
                    }
                }
                unset($conv);
                return $cached;
            }
        }
        
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            if ($cache) {
                $cache->setConversations($userEmail, $folder, []);
            }
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT
                    c.conversation_id,
                    c.subject,
                    c.message_count,
                    c.unread_count,
                    c.has_attachment,
                    c.latest_date,
                    UNIX_TIMESTAMP(c.latest_date) as latest_timestamp,
                    c.latest_from,
                    IFNULL(c.latest_uid, 0) as latest_uid,
                    c.latest_message_id,
                    c.snippet
                FROM webmail_conversations c
                INNER JOIN webmail_conversation_members m
                    ON c.user_email = m.user_email AND c.conversation_id = m.conversation_id
                WHERE c.user_email = ? AND m.folder_id = ?
                ORDER BY c.latest_date DESC
            ");
            $stmt->execute([$userEmail, $folderId]);
        } catch (\PDOException $e) {
            error_log("ConversationService::getConversationsForFolder failed: " . $e->getMessage());
            return [];
        }
        
        $conversations = $stmt->fetchAll();
        
        // Decode snippets (they may contain quoted-printable encoded text from database)
        foreach ($conversations as &$conv) {
            if (isset($conv['snippet'])) {
                $conv['snippet'] = $this->decodeSnippet($conv['snippet']);
            }
        }
        unset($conv);
        
        if (empty($conversations)) {
            // No conversations found, cache empty array and return
            if ($cache) {
                $cache->setConversations($userEmail, $folder, []);
            }
            return [];
        }
        
        // Get all members for all conversations in ONE query using GROUP_CONCAT
        // This fixes the N+1 query problem (was: 51 queries for 50 conversations, now: 2 queries)
        $conversationIds = array_column($conversations, 'conversation_id');
        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));
        
        $memberStmt = $this->db->prepare("
            SELECT
                m.conversation_id,
                GROUP_CONCAT(m.uid ORDER BY m.message_date DESC) as uids_csv,
                GROUP_CONCAT(m.message_id ORDER BY m.message_date DESC SEPARATOR '|||') as message_ids_csv,
                GROUP_CONCAT(COALESCE(m.from_email, '') ORDER BY m.message_date DESC SEPARATOR '|||') as from_emails_csv,
                GROUP_CONCAT(COALESCE(m.from_name, '') ORDER BY m.message_date DESC SEPARATOR '|||') as from_names_csv,
                GROUP_CONCAT(COALESCE(m.subject, '') ORDER BY m.message_date DESC SEPARATOR '|||') as subjects_csv,
                GROUP_CONCAT(m.message_date ORDER BY m.message_date DESC SEPARATOR '|||') as dates_csv,
                GROUP_CONCAT(UNIX_TIMESTAMP(m.message_date) ORDER BY m.message_date DESC) as timestamps_csv,
                GROUP_CONCAT(COALESCE(fi.current_path, '') ORDER BY m.message_date DESC SEPARATOR '|||') as folders_csv
            FROM webmail_conversation_members m
            LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
            WHERE m.user_email = ? AND m.conversation_id IN ($placeholders)
            GROUP BY m.conversation_id
        ");
        
        $memberStmt->execute(array_merge([$userEmail], $conversationIds));
        $membersByConv = [];
        while ($row = $memberStmt->fetch()) {
            $membersByConv[$row['conversation_id']] = $row;
        }
        
        // Build messages array for each conversation from aggregated data
        foreach ($conversations as &$conv) {
            $convId = $conv['conversation_id'];
            if (isset($membersByConv[$convId])) {
                $memberData = $membersByConv[$convId];
                $uids = explode(',', $memberData['uids_csv']);
                $messageIds = explode('|||', $memberData['message_ids_csv']);
                $fromEmails = explode('|||', $memberData['from_emails_csv']);
                $fromNames = explode('|||', $memberData['from_names_csv']);
                $subjects = explode('|||', $memberData['subjects_csv']);
                $dates = explode('|||', $memberData['dates_csv']);
                $timestamps = explode(',', $memberData['timestamps_csv'] ?? '');
                $folders = explode('|||', $memberData['folders_csv'] ?? '');
                
                $members = [];
                for ($i = 0; $i < count($uids); $i++) {
                    $members[] = [
                        'uid' => (int)$uids[$i],
                        'message_id' => $messageIds[$i] ?? null,
                        'from_email' => $fromEmails[$i] ?? null,
                        'from_name' => $fromNames[$i] ?? null,
                        'subject' => $subjects[$i] ?? null,
                        'message_date' => $dates[$i] ?? null,
                        'timestamp' => isset($timestamps[$i]) ? (int)$timestamps[$i] : null,
                        'folder' => $folders[$i] ?? null
                    ];
                }
                
                $conv['messages'] = $members;
                $conv['uids'] = array_map('intval', $uids);
            } else {
                $conv['messages'] = [];
                $conv['uids'] = [];
            }
        }
        
        // Cache the result in Redis
        if ($cache) {
            $cache->setConversations($userEmail, $folder, $conversations);
        }
        
        return $conversations;
    }
    
    /**
     * Get messages in a specific conversation
     */
    public function getConversationMessages(string $userEmail, string $conversationId): array
    {
        $userEmail = strtolower($userEmail);
        
        $stmt = $this->db->prepare("
            SELECT *, UNIX_TIMESTAMP(message_date) as timestamp
            FROM webmail_conversation_members
            WHERE user_email = ? AND conversation_id = ?
            ORDER BY message_date DESC
        ");
        $stmt->execute([$userEmail, $conversationId]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get global conversation messages across all folders
     * Returns messages from INBOX, Sent, etc. for a complete thread view
     * 
     * @param string $userEmail
     * @param string $conversationId
     * @param array $includeFolders Optional list of folders to include (null = all)
     * @return array Messages with folder information
     */
    public function getConversationMessagesGlobal(string $userEmail, string $conversationId, ?array $includeFolders = null): array
    {
        $userEmail = strtolower($userEmail);
        
        if ($includeFolders && !empty($includeFolders)) {
            $placeholders = implode(',', array_fill(0, count($includeFolders), '?'));
            $stmt = $this->db->prepare("
                SELECT
                    m.*,
                    fi.current_path AS folder,
                    UNIX_TIMESTAMP(m.message_date) as timestamp,
                    CASE
                        WHEN LOWER(fi.current_path) LIKE '%sent%' THEN 'sent'
                        WHEN LOWER(fi.current_path) LIKE '%draft%' THEN 'draft'
                        WHEN LOWER(fi.current_path) = 'inbox' THEN 'inbox'
                        ELSE 'other'
                    END as folder_type
                FROM webmail_conversation_members m
                LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
                WHERE m.user_email = ? AND m.conversation_id = ?
                    AND fi.current_path IN ($placeholders)
                    AND m.uid > 0
                ORDER BY m.message_date ASC
            ");
            $params = array_merge([$userEmail, $conversationId], $includeFolders);
            $stmt->execute($params);
        } else {
            $stmt = $this->db->prepare("
                SELECT
                    m.*,
                    fi.current_path AS folder,
                    UNIX_TIMESTAMP(m.message_date) as timestamp,
                    CASE
                        WHEN LOWER(fi.current_path) LIKE '%sent%' THEN 'sent'
                        WHEN LOWER(fi.current_path) LIKE '%draft%' THEN 'draft'
                        WHEN LOWER(fi.current_path) = 'inbox' THEN 'inbox'
                        ELSE 'other'
                    END as folder_type
                FROM webmail_conversation_members m
                LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
                WHERE m.user_email = ? AND m.conversation_id = ?
                    AND m.uid > 0
                ORDER BY m.message_date ASC
            ");
            $stmt->execute([$userEmail, $conversationId]);
        }
        
        return $stmt->fetchAll();
    }

    /**
     * Get conversations with global message counts (across all folders)
     * Useful for showing accurate thread counts that include sent messages
     * 
     * @param string $userEmail
     * @param string $folder Primary folder to get conversations for
     * @param bool $includeGlobalCounts If true, count messages across all folders
     * @return array Conversations with global counts
     */
    public function getConversationsWithGlobalCounts(string $userEmail, string $folder, bool $includeGlobalCounts = true): array
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        
        if (!$includeGlobalCounts) {
            return $this->getConversationsForFolder($userEmail, $folder);
        }

        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return [];
        }

        try {
            // Get conversations that have at least one message in the specified folder
            // But count ALL messages across all folders.
            $stmt = $this->db->prepare("
                SELECT
                    c.conversation_id,
                    c.subject,
                    c.latest_date,
                    UNIX_TIMESTAMP(c.latest_date) as latest_timestamp,
                    c.latest_from,
                    c.latest_uid,
                    c.latest_message_id,
                    c.snippet,
                    c.has_attachment,
                    -- Global counts (all folders)
                    (SELECT COUNT(*) FROM webmail_conversation_members m
                     WHERE m.user_email = c.user_email AND m.conversation_id = c.conversation_id) as global_message_count,
                    -- Folder-specific counts
                    c.message_count as folder_message_count,
                    c.unread_count,
                    -- List of folders this conversation spans (current paths via identity)
                    (SELECT GROUP_CONCAT(DISTINCT fi2.current_path SEPARATOR '|||')
                     FROM webmail_conversation_members m
                     LEFT JOIN webmail_folder_identity fi2 ON fi2.id = m.folder_id
                     WHERE m.user_email = c.user_email AND m.conversation_id = c.conversation_id) as folders_csv
                FROM webmail_conversations c
                INNER JOIN webmail_conversation_members m
                    ON c.user_email = m.user_email AND c.conversation_id = m.conversation_id
                WHERE c.user_email = ? AND m.folder_id = ?
                GROUP BY c.conversation_id
                ORDER BY c.latest_date DESC
            ");
            $stmt->execute([$userEmail, $folderId]);
            
            $conversations = [];
            while ($row = $stmt->fetch()) {
                $conversations[] = [
                    'conversation_id' => $row['conversation_id'],
                    'subject' => $row['subject'],
                    'message_count' => (int)$row['global_message_count'],  // Use global count
                    'folder_message_count' => (int)$row['folder_message_count'],
                    'unread_count' => (int)$row['unread_count'],
                    'has_attachment' => (bool)$row['has_attachment'],
                    'latest_date' => $row['latest_date'],
                    'latest_timestamp' => (int)$row['latest_timestamp'],
                    'latest_from' => $row['latest_from'],
                    'latest_uid' => (int)$row['latest_uid'],
                    'latest_message_id' => $row['latest_message_id'],
                    'snippet' => $this->decodeSnippet($row['snippet']),
                    'folders' => $row['folders_csv'] ? explode('|||', $row['folders_csv']) : [$folder],
                    'is_cross_folder' => strpos($row['folders_csv'] ?? '', '|||') !== false,
                ];
            }
            
            return $conversations;
            
        } catch (\Exception $e) {
            error_log('[ConversationService] getConversationsWithGlobalCounts error: ' . $e->getMessage());
            return $this->getConversationsForFolder($userEmail, $folder);
        }
    }

    /**
     * Auto-index the Sent folder when indexing INBOX
     * This ensures sent replies are linked to conversations
     * 
     * @param string $userEmail
     * @param ImapService $imap
     * @return int Number of sent messages indexed
     */
    public function autoIndexSentFolder(string $userEmail, $imap): int
    {
        $userEmail = strtolower($userEmail);
        $indexed = 0;
        
        try {
            // Find the Sent folder
            $sentFolder = null;
            $folders = $imap->listFolders();
            foreach ($folders as $folder) {
                $name = strtolower($folder['name'] ?? '');
                if ($name === 'sent' || strpos($name, 'sent') !== false || 
                    strpos($name, 'gesendet') !== false || strpos($name, 'envoyé') !== false) {
                    $sentFolder = $folder['name'];
                    break;
                }
            }
            
            if (!$sentFolder) {
                error_log('[ConversationService] No Sent folder found for ' . $userEmail);
                return 0;
            }
            
            // Check if Sent is already indexed
            $status = $this->getIndexStatus($userEmail, $sentFolder);
            if ($status && $status['is_indexed']) {
                // Already indexed, just check for new messages
                $lastUid = $status['last_indexed_uid'] ?? 0;
                $messages = $imap->getMessagesSince($sentFolder, $lastUid, 100);
                
                if (!empty($messages['messages'])) {
                    $this->assignMessagesToConversations($userEmail, $sentFolder, $messages['messages']);
                    $indexed = count($messages['messages']);
                    
                    // Update last indexed UID
                    $maxUid = max(array_column($messages['messages'], 'uid'));
                    $this->updateLastIndexedUid($userEmail, $sentFolder, $maxUid, $indexed);
                }
            } else {
                // First time indexing - get recent sent messages
                $messages = $imap->getMessages($sentFolder, 1, 100);
                
                if (!empty($messages['messages'])) {
                    $this->assignMessagesToConversations($userEmail, $sentFolder, $messages['messages']);
                    $indexed = count($messages['messages']);
                    
                    // Mark as indexed
                    $maxUid = max(array_column($messages['messages'], 'uid'));
                    $this->markFolderIndexed($userEmail, $sentFolder, $maxUid, $messages['total'] ?? $indexed);
                }
            }
            
            error_log("[ConversationService] Auto-indexed {$indexed} messages from Sent folder for {$userEmail}");
            return $indexed;
            
        } catch (\Exception $e) {
            error_log('[ConversationService] autoIndexSentFolder error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get conversation_id for a message (by folder/uid or message_id)
     */
    public function getConversationIdForMessage(string $userEmail, string $folder, ?int $uid = null, ?string $messageId = null): ?string
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);

        if ($messageId) {
            $normalized = $this->normalizeMessageId($messageId);
            $hash = md5($normalized);
            $stmt = $this->db->prepare("
                SELECT conversation_id FROM webmail_conversation_members
                WHERE user_email = ? AND message_id_hash = ?
                LIMIT 1
            ");
            $stmt->execute([$userEmail, $hash]);
        } elseif ($uid) {
            $folderId = $this->resolveFolderId($userEmail, $folder);
            if ($folderId === null) {
                return null;
            }
            $stmt = $this->db->prepare("
                SELECT conversation_id FROM webmail_conversation_members
                WHERE user_email = ? AND folder_id = ? AND uid = ?
                LIMIT 1
            ");
            $stmt->execute([$userEmail, $folderId, $uid]);
        } else {
            return null;
        }

        $row = $stmt->fetch();
        return $row ? $row['conversation_id'] : null;
    }
    
    /**
     * Migrate existing JSON-based splits to database
     */
    public function migrateFromJsonSplits(string $userEmail, array $splits): int
    {
        $userEmail = strtolower($userEmail);
        $migrated = 0;
        
        foreach ($splits as $messageId => $splitData) {
            try {
                $conversationId = $splitData['conversation_id'] ?? null;
                if (!$conversationId) continue;
                
                $normalized = $this->normalizeMessageId($messageId);
                $hash = md5($normalized);
                $stmt = $this->db->prepare("
                    UPDATE webmail_conversation_members 
                    SET conversation_id = ?, is_user_override = 1
                    WHERE user_email = ? AND message_id_hash = ?
                ");
                $stmt->execute([$conversationId, $userEmail, $hash]);
                
                if ($stmt->rowCount() > 0) {
                    $migrated++;
                }
            } catch (\PDOException $e) {
                error_log("ConversationService migrateFromJsonSplits error: " . $e->getMessage());
            }
        }
        
        return $migrated;
    }
    
    // ==========================================
    // MESSAGE LIFECYCLE SYNC METHODS
    // These keep conversation_members in sync with IMAP operations
    // ==========================================

    /**
     * Mark a UID as recently deleted in Redis so assignMessagesToConversations
     * won't re-create it before IMAP finishes propagating the delete.
     */
    private function markUidRecentlyDeleted(string $userEmail, string $folder, int $uid): void
    {
        $cache = $this->getRedisCache();
        if (!$cache) return;
        $userHash = $cache->getUserHash($userEmail);
        $key = "{$userHash}:deleted_uid:{$folder}:{$uid}";
        $cache->set($key, '1', 60);
    }

    /**
     * Check if a UID was recently deleted (within the last 60s).
     */
    public function isUidRecentlyDeleted(string $userEmail, string $folder, int $uid): bool
    {
        $cache = $this->getRedisCache();
        if (!$cache) return false;
        $userHash = $cache->getUserHash($userEmail);
        $key = "{$userHash}:deleted_uid:{$folder}:{$uid}";
        return $cache->get($key) !== null;
    }

    /**
     * Delete a message from conversation tracking (called after IMAP delete)
     * 
     * @param string $userEmail
     * @param string $folder
     * @param int $uid
     * @return bool
     */
    public function deleteConversationMember(string $userEmail, string $folder, int $uid): bool
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return true;
        }

        // Prevent assignMessagesToConversations from re-creating this member
        $this->markUidRecentlyDeleted($userEmail, $folder, $uid);

        try {
            $stmt = $this->db->prepare("
                SELECT conversation_id FROM webmail_conversation_members
                WHERE user_email = ? AND folder_id = ? AND uid = ?
            ");
            $stmt->execute([$userEmail, $folderId, $uid]);
            $member = $stmt->fetch();

            if (!$member) {
                return true;
            }

            $conversationId = $member['conversation_id'];

            $stmt = $this->db->prepare("
                DELETE FROM webmail_conversation_members
                WHERE user_email = ? AND folder_id = ? AND uid = ?
            ");
            $stmt->execute([$userEmail, $folderId, $uid]);

            $this->updateConversationMetadata($userEmail, $conversationId, $folder);

            // Record a durable tombstone so the next /delta poll can
            // surface this UID in deletedUids.
            $this->writeTombstones($userEmail, $folderId, [$uid], 'local_delete');

            error_log("[ConversationService] deleteConversationMember - Deleted UID $uid from $folder");
            return true;

        } catch (\PDOException $e) {
            error_log("[ConversationService] deleteConversationMember error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Append rows to webmail_folder_tombstones. Used as the durable
     * counterpart to markUidRecentlyDeleted (Redis, 60s TTL). The Redis
     * one prevents re-creation by assign*; this one feeds delta().
     */
    private function writeTombstones(string $userEmail, string $folderId, array $uids, string $source = 'local_delete'): void
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn($u) => $u > 0)));
        if (empty($uids)) {
            return;
        }
        try {
            $values = implode(',', array_fill(0, count($uids), '(?, ?, ?, NOW(), ?)'));
            $stmt = $this->db->prepare(
                "INSERT INTO webmail_folder_tombstones
                    (user_email, folder_id, uid, deleted_at, source)
                 VALUES {$values}"
            );
            $params = [];
            foreach ($uids as $uid) {
                $params[] = strtolower($userEmail);
                $params[] = $folderId;
                $params[] = $uid;
                $params[] = $source;
            }
            $stmt->execute($params);
        } catch (\PDOException $e) {
            // Migration 182 may not be applied yet on older deployments;
            // the failure is non-fatal because the row is already gone
            // from webmail_conversation_members. delta() will simply
            // miss this row's tombstone until the migration runs.
            error_log("[ConversationService] writeTombstones: " . $e->getMessage());
        }
    }

    /**
     * Bulk-delete conversation members in a single round trip.
     *
     * The per-UID deleteConversationMember() path does 3+ DB queries per
     * UID (SELECT member, DELETE member, then a heavy updateConversation
     * Metadata() that pulls sibling folders + aggregates per conversation).
     * For a 32-row Bin empty-out that worked out to ~100 queries and the
     * bulk-delete API call hung for several seconds even though IMAP
     * (single UID STORE + EXPUNGE) finished in well under a second. This
     * method collapses the per-UID work into:
     *   1) one SELECT to pull (uid, conversation_id) pairs
     *   2) one DELETE … WHERE uid IN (…)
     *   3) one updateConversationMetadata call per UNIQUE conversation
     *
     * Returns the number of rows that were actually deleted.
     */
    public function bulkDeleteConversationMembers(string $userEmail, string $folder, array $uids): int
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return 0;
        }

        $uids = array_values(array_unique(array_map('intval', array_filter($uids, fn($u) => $u > 0))));
        if (empty($uids)) {
            return 0;
        }

        foreach ($uids as $uid) {
            $this->markUidRecentlyDeleted($userEmail, $folder, $uid);
        }

        try {
            $placeholders = implode(',', array_fill(0, count($uids), '?'));
            $selStmt = $this->db->prepare(
                "SELECT uid, conversation_id FROM webmail_conversation_members
                 WHERE user_email = ? AND folder_id = ? AND uid IN ($placeholders)"
            );
            $selStmt->execute(array_merge([$userEmail, $folderId], $uids));
            $rows = $selStmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return 0;
            }

            $conversationIds = [];
            foreach ($rows as $row) {
                $conversationIds[(string)$row['conversation_id']] = true;
            }

            $delStmt = $this->db->prepare(
                "DELETE FROM webmail_conversation_members
                 WHERE user_email = ? AND folder_id = ? AND uid IN ($placeholders)"
            );
            $delStmt->execute(array_merge([$userEmail, $folderId], $uids));
            $deleted = $delStmt->rowCount();

            // Recompute metadata once per affected conversation (still
            // potentially N queries when each row maps to a different
            // conversation, but in practice a bulk Bin-empty hits the
            // same handful of conversation_ids over and over).
            foreach (array_keys($conversationIds) as $cid) {
                try {
                    $this->updateConversationMetadata($userEmail, $cid, $folder);
                } catch (\Throwable $e) {
                    error_log("[ConversationService] bulkDeleteConversationMembers metadata error: " . $e->getMessage());
                }
            }

            // Write tombstones in one INSERT so delta() can surface
            // deletedUids on the next poll for ALL deleted UIDs.
            $this->writeTombstones($userEmail, $folderId, $uids, 'local_delete');

            error_log("[ConversationService] bulkDeleteConversationMembers - Deleted {$deleted} UIDs from {$folder} touching " . count($conversationIds) . " conversation(s)");
            return $deleted;
        } catch (\PDOException $e) {
            error_log("[ConversationService] bulkDeleteConversationMembers error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Move a message in conversation tracking (called after IMAP move)
     * Updates the folder field, and optionally the UID if it changed
     * 
     * @param string $userEmail
     * @param string $oldFolder
     * @param int $oldUid
     * @param string $newFolder
     * @param int|null $newUid If known (IMAP MOVE returns new UID), otherwise keeps old UID
     * @return bool
     */
    public function moveConversationMember(string $userEmail, string $oldFolder, int $oldUid, string $newFolder, ?int $newUid = null): bool
    {
        $userEmail = strtolower($userEmail);
        $oldFolder = $this->normalizeFolder($oldFolder);
        $newFolder = $this->normalizeFolder($newFolder);

        $oldFolderId = $this->resolveFolderId($userEmail, $oldFolder);
        $newFolderId = $this->resolveFolderId($userEmail, $newFolder);
        if ($oldFolderId === null || $newFolderId === null) {
            error_log("[ConversationService] moveConversationMember skipped: missing folder_id ($oldFolder -> $newFolder)");
            return false;
        }

        $this->markUidRecentlyDeleted($userEmail, $oldFolder, $oldUid);

        // Transaction-nesting guard: a caller (e.g. MailboxWriteService::
        // commitMove / commitDelete) may already have opened a transaction
        // on this shared PDO connection. Opening a second beginTransaction()
        // throws "there is already an active transaction" and our own catch
        // would then roll back the CALLER's work. Only own the tx lifecycle
        // when we are the one who opened it.
        $ownTx = !$this->db->inTransaction();

        try {
            if ($ownTx) {
                $this->db->beginTransaction();
            }

            $stmt = $this->db->prepare("
                SELECT * FROM webmail_conversation_members
                WHERE user_email = ? AND folder_id = ? AND uid = ?
            ");
            $stmt->execute([$userEmail, $oldFolderId, $oldUid]);
            $source = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$source) {
                if ($ownTx) {
                    $this->db->commit();
                }
                error_log("[ConversationService] moveConversationMember - Member not found for UID $oldUid in $oldFolder");
                return true;
            }

            $conversationId = $source['conversation_id'];
            $messageIdHash  = $source['message_id_hash'];

            // Check if the target folder already has a row with the same message_id_hash.
            $stmt = $this->db->prepare("
                SELECT id, is_user_override FROM webmail_conversation_members
                WHERE user_email = ? AND folder_id = ? AND message_id_hash = ?
            ");
            $stmt->execute([$userEmail, $newFolderId, $messageIdHash]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                // Target already has this message -- merge: update the existing
                // row with the new UID and preserve the richer metadata, then
                // remove the source row.
                $mergedOverride = ($existing['is_user_override'] || $source['is_user_override']) ? 1 : 0;
                $updateFields = ['is_user_override = ?'];
                $updateParams = [$mergedOverride];

                if ($newUid !== null) {
                    $updateFields[] = 'uid = ?';
                    $updateParams[] = $newUid;
                }

                $updateParams[] = $existing['id'];
                $stmt = $this->db->prepare("
                    UPDATE webmail_conversation_members
                    SET " . implode(', ', $updateFields) . "
                    WHERE id = ?
                ");
                $stmt->execute($updateParams);

                $stmt = $this->db->prepare("
                    DELETE FROM webmail_conversation_members WHERE id = ?
                ");
                $stmt->execute([$source['id']]);

                error_log("[ConversationService] moveConversationMember - Merged UID $oldUid from $oldFolder into existing target row in $newFolder");
            } else {
                if ($newUid !== null) {
                    $stmt = $this->db->prepare("
                        UPDATE webmail_conversation_members
                        SET folder_id = ?, uid = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newFolderId, $newUid, $source['id']]);
                } else {
                    $stmt = $this->db->prepare("
                        UPDATE webmail_conversation_members
                        SET folder_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newFolderId, $source['id']]);
                }

                error_log("[ConversationService] moveConversationMember - Moved UID $oldUid from $oldFolder to $newFolder" . ($newUid ? " (new UID: $newUid)" : ""));
            }

            $this->updateConversationMetadata($userEmail, $conversationId, $newFolder);

            if ($ownTx) {
                $this->db->commit();
            }

            $cache = $this->getRedisCache();
            if ($cache) {
                $cache->invalidateConversations($userEmail, $oldFolder);
                $cache->invalidateConversations($userEmail, $newFolder);
            }

            return true;

        } catch (\PDOException $e) {
            if ($ownTx) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                error_log("[ConversationService] moveConversationMember error: " . $e->getMessage());
                return false;
            }
            // Inside a caller's transaction: re-throw so the caller can roll
            // back the whole unit of work atomically rather than committing a
            // half-applied move.
            error_log("[ConversationService] moveConversationMember error (in caller tx): " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Batch-load the DB read-state (is_seen) for a set of UIDs in a folder.
     *
     * Returns a map of uid => bool, containing ONLY UIDs that have a row in
     * webmail_conversation_members. A UID absent from the map means "the DB
     * has no opinion yet" (not indexed), which the caller must distinguish
     * from "the DB says unread" -- hence we omit missing rows rather than
     * defaulting them to false.
     *
     * This backs the read-side DB-as-truth overlay: the IMAP message list
     * reports `seen` from the live \Seen flag, which lags our DB whenever an
     * in-app read/unread has been committed to MariaDB but not yet drained
     * to IMAP by the outbox worker. The overlay reconciles the two.
     *
     * @param string $userEmail
     * @param string $folder
     * @param array<int> $uids
     * @return array<int,bool> uid => is_seen
     */
    public function getReadStateMap(string $userEmail, string $folder, array $uids): array
    {
        if (empty($uids)) {
            return [];
        }
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return [];
        }
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn($u) => $u > 0)));
        if (empty($uids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT uid, is_seen
                   FROM webmail_conversation_members
                  WHERE user_email = ? AND folder_id = ? AND uid IN ($placeholders)"
            );
            $stmt->execute(array_merge([$userEmail, $folderId], $uids));
            $map = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $map[(int)$row['uid']] = ((int)($row['is_seen'] ?? 0)) === 1;
            }
            return $map;
        } catch (\PDOException $e) {
            error_log("[ConversationService] getReadStateMap error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update read status of a message in conversation tracking (called after flag change)
     * This updates the unread_count in the conversation
     * 
     * @param string $userEmail
     * @param string $folder
     * @param int $uid
     * @param bool $seen True if message is now read, false if unread
     * @return bool
     */
    public function updateMemberReadStatus(string $userEmail, string $folder, int $uid, bool $seen): bool
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return true;
        }

        try {
            // Phase 2 of the DB-as-truth refactor: is_seen is required (migration
            // 179 enforces NOT NULL). The columnExists() guard is removed because
            // a missing column now indicates a deployment-time misstep, not a
            // legacy environment.
            //
            // We RECOMPUTE the conversation unread_count from the authoritative
            // source (count of members where is_seen=0) instead of applying a
            // delta. Delta accumulators silently drifted whenever an event
            // fired twice or a write path failed mid-stream. Recompute is
            // O(messages-in-this-conversation), which on a real mailbox is
            // single-digit rows; the round trip cost is dwarfed by the prior
            // GREATEST(0, ...) clamp's bug surface.
            $stmt = $this->db->prepare("
                SELECT conversation_id, is_seen
                FROM webmail_conversation_members
                WHERE user_email = ? AND folder_id = ? AND uid = ?
            ");
            $stmt->execute([$userEmail, $folderId, $uid]);
            $member = $stmt->fetch();

            if (!$member) {
                // Member not indexed yet; assignMessagesToConversations will
                // create it on the next sync. No counter to adjust.
                return true;
            }

            $conversationId = $member['conversation_id'];
            $currentSeen = ((int)($member['is_seen'] ?? 0)) === 1;

            if ($currentSeen !== $seen) {
                $updateMember = $this->db->prepare("
                    UPDATE webmail_conversation_members
                    SET is_seen = ?
                    WHERE user_email = ? AND folder_id = ? AND uid = ?
                ");
                $updateMember->execute([$seen ? 1 : 0, $userEmail, $folderId, $uid]);

                // Recompute (not delta) inside the same transactional
                // context. Correlated subquery touches only the rows for
                // this conversation thanks to idx_conv_member_unread.
                $recompute = $this->db->prepare("
                    UPDATE webmail_conversations c
                    SET c.unread_count = (
                        SELECT COUNT(*)
                        FROM webmail_conversation_members m
                        WHERE m.user_email = c.user_email
                          AND m.conversation_id = c.conversation_id
                          AND m.is_seen = 0
                    ),
                    c.updated_at = NOW()
                    WHERE c.user_email = ? AND c.conversation_id = ?
                ");
                $recompute->execute([$userEmail, $conversationId]);
            }

            $cache = $this->getRedisCache();
            if ($cache) {
                $cache->invalidateConversations($userEmail, $folder);
            }

            return true;

        } catch (\PDOException $e) {
            error_log("[ConversationService] updateMemberReadStatus error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Batched read/unread update for many UIDs in the same folder.
     *
     * Mirrors {@see updateMemberReadStatus} but collapses N round-trips into
     * three queries:
     *   1) one SELECT with WHERE uid IN (...)
     *   2) one UPDATE webmail_conversation_members ... WHERE uid IN (...)
     *      (only for UIDs whose is_seen actually changes)
     *   3) one UPDATE webmail_conversations per affected conversation_id with
     *      the aggregated unread_count delta (GREATEST(0, ...) clamp preserved)
     *
     * UIDs whose is_seen already matches $seen are skipped so the cached
     * unread_count never drifts. Returns true if the operation completed
     * (even with zero changes); false only on PDO error.
     *
     * @param string $userEmail
     * @param string $folder
     * @param array<int> $uids
     * @param bool $seen
     */
    public function updateMembersReadStatusBatch(string $userEmail, string $folder, array $uids, bool $seen): bool
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn($u) => $u > 0)));
        if (empty($uids)) {
            return true;
        }

        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return true;
        }

        try {
            // is_seen is required (migration 179). Single SELECT covers all UIDs.
            $placeholders = implode(',', array_fill(0, count($uids), '?'));
            $params = array_merge([$userEmail, $folderId], $uids);

            $stmt = $this->db->prepare(
                "SELECT conversation_id, uid, is_seen
                 FROM webmail_conversation_members
                 WHERE user_email = ? AND folder_id = ? AND uid IN ({$placeholders})"
            );
            $stmt->execute($params);
            $members = $stmt->fetchAll();

            if (empty($members)) {
                // Members not indexed yet; cache invalidation only.
                $cache = $this->getRedisCache();
                if ($cache) {
                    $cache->invalidateConversations($userEmail, $folder);
                }
                return true;
            }

            $uidsToFlip = [];
            $affectedConvs = [];

            foreach ($members as $m) {
                $currentSeen = ((int)($m['is_seen'] ?? 0)) === 1;
                if ($currentSeen === $seen) {
                    continue;
                }
                $uidsToFlip[] = (int)$m['uid'];
                $affectedConvs[$m['conversation_id']] = true;
            }

            if (!empty($uidsToFlip)) {
                $flipPlaceholders = implode(',', array_fill(0, count($uidsToFlip), '?'));
                $updateMembers = $this->db->prepare(
                    "UPDATE webmail_conversation_members
                     SET is_seen = ?
                     WHERE user_email = ? AND folder_id = ? AND uid IN ({$flipPlaceholders})"
                );
                $updateMembers->execute(array_merge([$seen ? 1 : 0, $userEmail, $folderId], $uidsToFlip));

                // Recompute (not delta) for every affected conversation in a
                // single statement. The IN-list is at most O(batch size), and
                // each subquery hits idx_conv_member_unread for tens of rows
                // at most. This is the right complexity ceiling for the
                // "select all -> mark read" workflow.
                if (!empty($affectedConvs)) {
                    $convIds = array_keys($affectedConvs);
                    $convPlaceholders = implode(',', array_fill(0, count($convIds), '?'));
                    $recompute = $this->db->prepare(
                        "UPDATE webmail_conversations c
                         SET c.unread_count = (
                             SELECT COUNT(*)
                             FROM webmail_conversation_members m
                             WHERE m.user_email = c.user_email
                               AND m.conversation_id = c.conversation_id
                               AND m.is_seen = 0
                         ),
                         c.updated_at = NOW()
                         WHERE c.user_email = ?
                           AND c.conversation_id IN ({$convPlaceholders})"
                    );
                    $recompute->execute(array_merge([$userEmail], $convIds));
                }
            }

            $cache = $this->getRedisCache();
            if ($cache) {
                $cache->invalidateConversations($userEmail, $folder);
            }

            return true;
        } catch (\PDOException $e) {
            error_log("[ConversationService] updateMembersReadStatusBatch error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mirror a single-UID star/unstar into webmail_conversation_members.is_flagged.
     *
     * The IMAP \Flagged write is durable via the outbox; this keeps the DB
     * mirror (the read source for synced folders) in agreement so the star
     * does not revert on the next list fetch before IMAP sync catches up.
     * Unlike read status, flagged does not affect unread_count, so there is
     * no conversation recompute.
     */
    public function updateMemberFlagStatus(string $userEmail, string $folder, int $uid, bool $flagged): bool
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return true;
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE webmail_conversation_members
                SET is_flagged = ?
                WHERE user_email = ? AND folder_id = ? AND uid = ?
            ");
            $stmt->execute([$flagged ? 1 : 0, $userEmail, $folderId, $uid]);

            $cache = $this->getRedisCache();
            if ($cache) {
                $cache->invalidateConversations($userEmail, $folder);
            }

            return true;
        } catch (\PDOException $e) {
            error_log("[ConversationService] updateMemberFlagStatus error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Batched star/unstar for many UIDs in the same folder.
     *
     * Mirrors {@see updateMemberFlagStatus} but collapses N round-trips into a
     * single UPDATE with WHERE uid IN (...). Used by the "select all -> star"
     * workflow so the DB mirror agrees with the IMAP outbox writes.
     *
     * @param array<int> $uids
     */
    public function updateMembersFlagStatusBatch(string $userEmail, string $folder, array $uids, bool $flagged): bool
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn($u) => $u > 0)));
        if (empty($uids)) {
            return true;
        }

        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return true;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($uids), '?'));
            $stmt = $this->db->prepare(
                "UPDATE webmail_conversation_members
                 SET is_flagged = ?
                 WHERE user_email = ? AND folder_id = ? AND uid IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$flagged ? 1 : 0, $userEmail, $folderId], $uids));

            $cache = $this->getRedisCache();
            if ($cache) {
                $cache->invalidateConversations($userEmail, $folder);
            }

            return true;
        } catch (\PDOException $e) {
            error_log("[ConversationService] updateMembersFlagStatusBatch error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Apply a batch of full flag changes (seen / flagged / answered) from
     * the IMAP sync engine into the mirror.
     *
     * Used by MailboxSyncService when CHANGEDSINCE returns a delta or when
     * initial sync writes a fresh row. Each entry is:
     *   [ 'uid' => int, 'seen' => bool, 'flagged' => bool, 'answered' => bool ]
     *
     * The seen recompute of webmail_conversations.unread_count is delegated
     * to updateMembersReadStatusBatch for any UID where seen actually flipped,
     * so the conversation badge stays in sync.
     */
    public function updateMembersFlagsBatch(string $userEmail, string $folder, array $changes): bool
    {
        if (empty($changes)) {
            return true;
        }

        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return true;
        }

        try {
            $stmt = $this->db->prepare(
                "UPDATE webmail_conversation_members
                    SET is_seen = ?, is_flagged = ?, is_answered = ?, updated_at = CURRENT_TIMESTAMP
                  WHERE user_email = ? AND folder_id = ? AND uid = ?"
            );

            $seenFlippedUidsTrue  = [];
            $seenFlippedUidsFalse = [];

            // Pre-read current seen so we can route the unread_count
            // recompute through updateMembersReadStatusBatch only for
            // rows that actually flipped (avoids a heavy recompute on
            // every CONDSTORE noise event).
            $uids = array_values(array_filter(array_map(
                fn($c) => (int)($c['uid'] ?? 0),
                $changes
            ), fn($u) => $u > 0));

            $currentSeen = [];
            if (!empty($uids)) {
                $placeholders = implode(',', array_fill(0, count($uids), '?'));
                $sel = $this->db->prepare(
                    "SELECT uid, is_seen FROM webmail_conversation_members
                     WHERE user_email = ? AND folder_id = ? AND uid IN ({$placeholders})"
                );
                $sel->execute(array_merge([$userEmail, $folderId], $uids));
                foreach ($sel->fetchAll() as $row) {
                    $currentSeen[(int)$row['uid']] = ((int)$row['is_seen']) === 1;
                }
            }

            foreach ($changes as $c) {
                $uid = (int)($c['uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $seen     = !empty($c['seen'])     ? 1 : 0;
                $flagged  = !empty($c['flagged'])  ? 1 : 0;
                $answered = !empty($c['answered']) ? 1 : 0;

                $stmt->execute([$seen, $flagged, $answered, $userEmail, $folderId, $uid]);

                if (isset($currentSeen[$uid]) && $currentSeen[$uid] !== (bool)$seen) {
                    if ($seen) {
                        $seenFlippedUidsTrue[] = $uid;
                    } else {
                        $seenFlippedUidsFalse[] = $uid;
                    }
                }
            }

            // Recompute unread badge only for actual flips.
            if (!empty($seenFlippedUidsTrue)) {
                $this->updateMembersReadStatusBatch($userEmail, $folder, $seenFlippedUidsTrue, true);
            }
            if (!empty($seenFlippedUidsFalse)) {
                $this->updateMembersReadStatusBatch($userEmail, $folder, $seenFlippedUidsFalse, false);
            }

            return true;
        } catch (\PDOException $e) {
            error_log("[ConversationService] updateMembersFlagsBatch error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cheap check (cached per request) for whether a column exists. Used to
     * gracefully degrade when a migration has not yet been applied on this
     * environment so we never hard-fail because of a missing column.
     */
    private array $columnExistenceCache = [];
    private function columnExists(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (isset($this->columnExistenceCache[$key])) {
            return $this->columnExistenceCache[$key];
        }
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $exists = ((int)$stmt->fetchColumn()) > 0;
        } catch (\PDOException $e) {
            $exists = false;
        }
        $this->columnExistenceCache[$key] = $exists;
        return $exists;
    }
    
    /**
     * Bulk delete conversation members for a folder (called when folder is emptied/deleted)
     * 
     * @param string $userEmail
     * @param string $folder
     * @return int Number of deleted members
     */
    public function deleteAllFolderMembers(string $userEmail, string $folder): int
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        $folderId = $this->resolveFolderId($userEmail, $folder);
        if ($folderId === null) {
            return 0;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT conversation_id FROM webmail_conversation_members
                WHERE user_email = ? AND folder_id = ?
            ");
            $stmt->execute([$userEmail, $folderId]);
            $conversations = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $stmt = $this->db->prepare("
                DELETE FROM webmail_conversation_members
                WHERE user_email = ? AND folder_id = ?
            ");
            $stmt->execute([$userEmail, $folderId]);
            $deleted = $stmt->rowCount();
            
            // Update metadata for all affected conversations
            foreach ($conversations as $convId) {
                $this->updateConversationMetadata($userEmail, $convId, $folder);
            }
            
            // Invalidate cache
            $cache = $this->getRedisCache();
            if ($cache) {
                $cache->invalidateConversations($userEmail, $folder);
            }
            
            error_log("[ConversationService] deleteAllFolderMembers - Deleted $deleted members from $folder");
            return $deleted;
            
        } catch (\PDOException $e) {
            error_log("[ConversationService] deleteAllFolderMembers error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete all conversation data for a user (for testing/reset)
     */
    public function clearUserConversations(string $userEmail): void
    {
        $userEmail = strtolower($userEmail);
        
        $this->db->prepare("DELETE FROM webmail_conversation_members WHERE user_email = ?")->execute([$userEmail]);
        $this->db->prepare("DELETE FROM webmail_conversations WHERE user_email = ?")->execute([$userEmail]);
        $this->db->prepare("DELETE FROM webmail_folder_index WHERE user_email = ?")->execute([$userEmail]);
    }
    
    // ==========================================
    // FOLDER INDEX TRACKING (Outlook-style caching)
    // ==========================================
    
    /**
     * Check if a folder has been fully indexed
     */
    public function isFolderIndexed(string $userEmail, string $folder): bool
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        
        $stmt = $this->db->prepare("
            SELECT is_indexed FROM webmail_folder_index 
            WHERE user_email = ? AND folder = ?
        ");
        $stmt->execute([$userEmail, $folder]);
        $row = $stmt->fetch();
        
        return $row && $row['is_indexed'] == 1;
    }
    
    /**
     * Get full folder index status including UIDVALIDITY for cache validation
     */
    public function getFolderIndexStatus(string $userEmail, string $folder): array
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        
        $stmt = $this->db->prepare("
            SELECT is_indexed, last_indexed_uid, message_count, uidvalidity, indexed_at
            FROM webmail_folder_index 
            WHERE user_email = ? AND folder = ?
        ");
        $stmt->execute([$userEmail, $folder]);
        $row = $stmt->fetch();
        
        if (!$row) {
            return [
                'indexed' => false,
                'lastUid' => 0,
                'messageCount' => 0,
                'uidvalidity' => 0,
                'indexedAt' => null
            ];
        }
        
        return [
            'indexed' => (bool)$row['is_indexed'],
            'lastUid' => (int)$row['last_indexed_uid'],
            'messageCount' => (int)$row['message_count'],
            'uidvalidity' => (int)($row['uidvalidity'] ?? 0),
            'indexedAt' => $row['indexed_at']
        ];
    }
    
    /**
     * Check if folder index is valid by comparing UIDVALIDITY and UIDNEXT
     * Returns: 'valid', 'new_messages', 'rebuild_needed', or 'not_indexed'
     * 
     * @param string $userEmail
     * @param string $folder
     * @param int $serverUidvalidity Current UIDVALIDITY from IMAP
     * @param int $serverUidnext Current UIDNEXT from IMAP
     * @return array ['status' => string, 'indexStatus' => array]
     */
    public function checkFolderIndexValidity(string $userEmail, string $folder, int $serverUidvalidity, int $serverUidnext): array
    {
        $folder = $this->normalizeFolder($folder);
        $indexStatus = $this->getFolderIndexStatus($userEmail, $folder);
        
        // Not indexed yet
        if (!$indexStatus['indexed']) {
            return ['status' => 'not_indexed', 'indexStatus' => $indexStatus];
        }
        
        // UIDVALIDITY changed - folder was rebuilt, must re-index everything
        if ($indexStatus['uidvalidity'] > 0 && $indexStatus['uidvalidity'] !== $serverUidvalidity) {
            error_log("[ConversationService] UIDVALIDITY changed for {$folder}: {$indexStatus['uidvalidity']} -> {$serverUidvalidity}");
            return ['status' => 'rebuild_needed', 'indexStatus' => $indexStatus];
        }
        
        // UIDNEXT decreased - server was reset/rebuilt (edge case)
        if ($serverUidnext < $indexStatus['lastUid']) {
            error_log("[ConversationService] UIDNEXT decreased for {$folder}: {$indexStatus['lastUid']} -> {$serverUidnext} (server rebuild)");
            return ['status' => 'rebuild_needed', 'indexStatus' => $indexStatus];
        }
        
        // UIDNEXT increased - new messages to fetch
        if ($serverUidnext > $indexStatus['lastUid'] + 1) {
            return ['status' => 'new_messages', 'indexStatus' => $indexStatus];
        }
        
        // Everything matches - index is valid
        return ['status' => 'valid', 'indexStatus' => $indexStatus];
    }
    
    /**
     * Mark a folder as indexed with UIDVALIDITY for cache validation
     */
    public function markFolderIndexed(string $userEmail, string $folder, int $lastUid, int $messageCount, int $uidvalidity = 0): void
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        
        $stmt = $this->db->prepare("
            INSERT INTO webmail_folder_index (user_email, folder, is_indexed, last_indexed_uid, message_count, uidvalidity, indexed_at)
            VALUES (?, ?, 1, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_indexed = 1,
                last_indexed_uid = VALUES(last_indexed_uid),
                message_count = VALUES(message_count),
                uidvalidity = VALUES(uidvalidity),
                indexed_at = NOW(),
                updated_at = NOW()
        ");
        $stmt->execute([$userEmail, $folder, $lastUid, $messageCount, $uidvalidity]);
    }
    
    /**
     * Update last indexed UID (for incremental sync)
     */
    public function updateLastIndexedUid(string $userEmail, string $folder, int $lastUid, int $newMessageCount = 0): void
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        
        $stmt = $this->db->prepare("
            UPDATE webmail_folder_index 
            SET last_indexed_uid = ?, 
                message_count = message_count + ?,
                updated_at = NOW()
            WHERE user_email = ? AND folder = ?
        ");
        $stmt->execute([$lastUid, $newMessageCount, $userEmail, $folder]);
    }
    
    /**
     * Rewind the folder-index watermark WITHOUT deleting mirrored rows.
     *
     * assignMessagesToConversations() has a fast-path that skips any batch
     * whose max UID is <= last_indexed_uid (assumes already-processed). When
     * a truncated folder is rebuilt, that pointer is still high, so the
     * rebuild would re-page every UID but persist nothing. Resetting it to 0
     * forces the rebuild to re-process (idempotent upsert) the full range and
     * refill the gap. Rows are kept; only the pointer moves.
     */
    public function resetFolderIndexWatermark(string $userEmail, string $folder): void
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);

        try {
            $stmt = $this->db->prepare("
                UPDATE webmail_folder_index
                SET last_indexed_uid = 0, updated_at = NOW()
                WHERE user_email = ? AND folder = ?
            ");
            $stmt->execute([$userEmail, $folder]);
        } catch (\Throwable $e) {
            error_log('[ConversationService] resetFolderIndexWatermark ' . $folder . ': ' . $e->getMessage());
        }
    }

    /**
     * Get the last indexed UID for incremental sync
     */
    public function getLastIndexedUid(string $userEmail, string $folder): int
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);
        
        $stmt = $this->db->prepare("
            SELECT last_indexed_uid FROM webmail_folder_index 
            WHERE user_email = ? AND folder = ?
        ");
        $stmt->execute([$userEmail, $folder]);
        $row = $stmt->fetch();
        
        return $row ? (int)$row['last_indexed_uid'] : 0;
    }
    
    /**
     * Invalidate folder index (e.g., when UIDVALIDITY changes)
     * 
     * CRITICAL: This is called when UIDVALIDITY changes, meaning the folder was rebuilt.
     * All UIDs are now invalid and we must nuke all stale data to prevent silent corruption.
     */
    public function invalidateFolderIndex(string $userEmail, string $folder): void
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);

        error_log("[ConversationService] invalidateFolderIndex called for {$userEmail}/{$folder} - UIDVALIDITY changed, nuking stale data");

        // webmail_folder_index still keys by path (separate concept from
        // canonical folder identity).
        $stmt = $this->db->prepare("
            UPDATE webmail_folder_index
            SET is_indexed = 0, updated_at = NOW()
            WHERE user_email = ? AND folder = ?
        ");
        $stmt->execute([$userEmail, $folder]);

        // Clear conversation members for this folder by canonical id.
        $folderId = $this->resolveFolderId($userEmail, $folder);
        $deletedMembers = 0;
        if ($folderId !== null) {
            $stmt = $this->db->prepare("DELETE FROM webmail_conversation_members WHERE user_email = ? AND folder_id = ?");
            $stmt->execute([$userEmail, $folderId]);
            $deletedMembers = $stmt->rowCount();
        }
        
        // Clean up orphan conversations (conversations with no remaining members)
        // This is critical - don't rely only on hourly cron for consistency
        $stmt = $this->db->prepare("
            DELETE c FROM webmail_conversations c
            LEFT JOIN webmail_conversation_members m 
                ON c.user_email = m.user_email AND c.conversation_id = m.conversation_id
            WHERE c.user_email = ? AND m.id IS NULL
        ");
        $stmt->execute([$userEmail]);
        $deletedConversations = $stmt->rowCount();
        
        error_log("[ConversationService] invalidateFolderIndex - Deleted {$deletedMembers} members, {$deletedConversations} orphan conversations");
        
        // Invalidate Redis cache
        $cache = $this->getRedisCache();
        if ($cache) {
            $cache->invalidateFolder($userEmail, $folder);
            $cache->invalidateConversations($userEmail, $folder);
        }
    }
    
    /**
     * Permanently purge all DB metadata for a destroyed folder (and its children).
     * Unlike invalidateFolderIndex() which keeps the folder_index row for rebuild,
     * this removes everything because the IMAP folder no longer exists.
     */
    public function purgeFolderData(string $userEmail, string $folder): void
    {
        $userEmail = strtolower($userEmail);
        $folder = $this->normalizeFolder($folder);

        error_log("[ConversationService] purgeFolderData called for {$userEmail}/{$folder}");

        try {
            $childPrefix = $folder . '.%';

            // Resolve all folder_ids that match the path or any descendant
            // path. The identity table has the current_path index.
            $stmt = $this->db->prepare("
                SELECT id, current_path
                FROM webmail_folder_identity
                WHERE account_id = ? AND (current_path = ? OR current_path LIKE ?)
            ");
            $stmt->execute([$userEmail, $folder, $childPrefix]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $folderIds = [];
            $childFolders = [];
            foreach ($rows as $r) {
                $folderIds[] = $r['id'];
                if (!empty($r['current_path']) && $r['current_path'] !== $folder) {
                    $childFolders[] = $r['current_path'];
                }
            }

            $deletedMembers = 0;
            $deletedConvs = 0;
            if (!empty($folderIds)) {
                $placeholders = implode(',', array_fill(0, count($folderIds), '?'));

                $stmt = $this->db->prepare("
                    SELECT DISTINCT conversation_id FROM webmail_conversation_members
                    WHERE user_email = ? AND folder_id IN ({$placeholders})
                ");
                $stmt->execute(array_merge([$userEmail], $folderIds));
                $affectedConvIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

                $stmt = $this->db->prepare("
                    DELETE FROM webmail_conversation_members
                    WHERE user_email = ? AND folder_id IN ({$placeholders})
                ");
                $stmt->execute(array_merge([$userEmail], $folderIds));
                $deletedMembers = $stmt->rowCount();

                if (!empty($affectedConvIds)) {
                    $convPlaceholders = implode(',', array_fill(0, count($affectedConvIds), '?'));
                    $stmt = $this->db->prepare("
                        DELETE c FROM webmail_conversations c
                        LEFT JOIN webmail_conversation_members m
                            ON c.user_email = m.user_email AND c.conversation_id = m.conversation_id
                        WHERE c.user_email = ? AND c.conversation_id IN ({$convPlaceholders}) AND m.id IS NULL
                    ");
                    $stmt->execute(array_merge([$userEmail], $affectedConvIds));
                    $deletedConvs = $stmt->rowCount();
                }
            }

            // webmail_folder_index still keys by path (separate concept).
            $stmt = $this->db->prepare("
                DELETE FROM webmail_folder_index
                WHERE user_email = ? AND (folder = ? OR folder LIKE ?)
            ");
            $stmt->execute([$userEmail, $folder, $childPrefix]);

            // ----------------------------------------------------------------
            // Identity-keyed cleanup (folder_id-based). Without this, deleting
            // a folder leaves: an "active" identity row that drift detection
            // and path resolution still treat as live; an OPEN path interval
            // that breaks the "one open interval per path" invariant; a
            // sync_state row the background cron keeps trying to sync; and
            // pinned_emails rows pointing at messages that no longer exist.
            // Each step is isolated so a missing table can't abort the rest.
            // ----------------------------------------------------------------
            if (!empty($folderIds)) {
                $idPlaceholders = implode(',', array_fill(0, count($folderIds), '?'));

                // 1) Tombstone the identity rows (keep them for path history /
                //    legacy-URL redirects, but exclude from live resolution).
                try {
                    $stmt = $this->db->prepare("
                        UPDATE webmail_folder_identity
                        SET state = 'deleted', last_seen_at = CURRENT_TIMESTAMP
                        WHERE account_id = ? AND id IN ({$idPlaceholders})
                    ");
                    $stmt->execute(array_merge([$userEmail], $folderIds));
                } catch (\Throwable $e) {
                    error_log("[ConversationService] purgeFolderData identity-state: " . $e->getMessage());
                }

                // 2) Close any open path interval so the path is free to be
                //    reused and the open-interval count matches live folders.
                try {
                    $stmt = $this->db->prepare("
                        UPDATE webmail_folder_path_intervals
                        SET valid_to = NOW()
                        WHERE account_id = ? AND folder_id IN ({$idPlaceholders}) AND valid_to IS NULL
                    ");
                    $stmt->execute(array_merge([$userEmail], $folderIds));
                } catch (\Throwable $e) {
                    error_log("[ConversationService] purgeFolderData close-interval: " . $e->getMessage());
                }

                // 3) Drop the background sync-state row so the cron stops
                //    trying to mirror a folder that no longer exists.
                try {
                    $stmt = $this->db->prepare("
                        DELETE FROM webmail_folder_sync_state
                        WHERE user_email = ? AND folder_id IN ({$idPlaceholders})
                    ");
                    $stmt->execute(array_merge([$userEmail], $folderIds));
                } catch (\Throwable $e) {
                    error_log("[ConversationService] purgeFolderData sync-state: " . $e->getMessage());
                }
            }

            // 4) Purge pins (keyed by path + children, not folder_id).
            try {
                $stmt = $this->db->prepare("
                    DELETE FROM pinned_emails
                    WHERE user_email = ? AND (folder = ? OR folder LIKE ?)
                ");
                $stmt->execute([$userEmail, $folder, $childPrefix]);
            } catch (\Throwable $e) {
                error_log("[ConversationService] purgeFolderData pins: " . $e->getMessage());
            }

            error_log("[ConversationService] purgeFolderData - Deleted {$deletedMembers} members, {$deletedConvs} orphan conversations for {$folder}");

            $cache = $this->getRedisCache();
            if ($cache) {
                $cache->invalidateFolder($userEmail, $folder);
                $cache->invalidateConversations($userEmail, $folder);
                foreach ($childFolders as $child) {
                    $cache->invalidateFolder($userEmail, $child);
                    $cache->invalidateConversations($userEmail, $child);
                }
            }
        } catch (\PDOException $e) {
            error_log("[ConversationService] purgeFolderData error: " . $e->getMessage());
        }
    }

    /**
     * Cascade an IMAP folder rename to webmail_folder_index (which still
     * keys by path string -- separate from canonical folder identity).
     *
     * Conversation members and conversations key by folder_id, which is
     * stable across renames, so they need no cascade. The actual identity
     * table update lives in FolderIndexService::applyRename().
     *
     * Returns the number of folder_index rows updated (parent + children).
     */
    public function updateFolderName(string $userEmail, string $oldFolder, string $newFolder): int
    {
        $userEmail = strtolower($userEmail);
        $totalUpdated = 0;

        try {
            $stmt = $this->db->prepare("
                UPDATE webmail_folder_index
                SET folder = ?, updated_at = NOW()
                WHERE user_email = ? AND folder = ?
            ");
            $stmt->execute([$newFolder, $userEmail, $oldFolder]);
            $totalUpdated += $stmt->rowCount();

            $oldFolderPrefix = $oldFolder . '.';
            $newFolderPrefix = $newFolder . '.';

            $stmt = $this->db->prepare("
                UPDATE webmail_folder_index
                SET folder = CONCAT(?, SUBSTRING(folder, ?)), updated_at = NOW()
                WHERE user_email = ? AND folder LIKE ?
            ");
            $stmt->execute([$newFolderPrefix, strlen($oldFolderPrefix) + 1, $userEmail, $oldFolderPrefix . '%']);
            $totalUpdated += $stmt->rowCount();

            error_log("[ConversationService] updateFolderName: $oldFolder -> $newFolder, updated $totalUpdated folder_index rows");

        } catch (\PDOException $e) {
            error_log("[ConversationService] updateFolderName error: " . $e->getMessage());
        }

        return $totalUpdated;
    }
}

