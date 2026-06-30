<?php

namespace Webmail\Services;

use Webmail\Services\ImapService;

/**
 * SearchIndexerService - Indexes content for universal search
 * 
 * Handles extraction and indexing of:
 * - Emails (subject, body, from/to)
 * - Drive files (filename, document content)
 * - Board cards (title, description, checklists)
 * - Todos (title, description)
 * - Clients (name, domain, contacts)
 * 
 * Dual-writes to both MySQL (metadata/fallback) and Meilisearch (primary search)
 */
class SearchIndexerService
{
    private \PDO $db;
    private array $config;
    private ?string $storagePath = null;
    private ?MeilisearchService $meilisearch = null;
    /**
     * When true, upsertIndex() writes only to MySQL and skips the per-row
     * Meilisearch HTTP POST. A full rebuild sets this so it can push every
     * document to Meilisearch in a few batched requests at the end instead of
     * firing thousands of synchronous round-trips (which times out the request).
     */
    private bool $deferMeiliWrites = false;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // Use NAS only if mount is healthy — avoids hanging on frozen NFS
        $nasPath = '/mnt/nas-drive';
        if (NasHealthCheck::isAvailable($nasPath)) {
            $this->storagePath = $nasPath;
        } else {
            $this->storagePath = $config['storage_path'] ?? __DIR__ . '/../../storage/drive';
        }
        
        // Initialize Meilisearch service if configured
        $this->initMeilisearch();
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
    }
    
    /**
     * Initialize Meilisearch service
     */
    private function initMeilisearch(): void
    {
        try {
            $this->meilisearch = new MeilisearchService($this->config);
            if (!$this->meilisearch->isEnabled()) {
                $this->meilisearch = null;
            }
        } catch (\Exception $e) {
            error_log("SearchIndexerService: Meilisearch init failed: " . $e->getMessage());
            $this->meilisearch = null;
        }
    }
    
    /**
     * Check if Meilisearch is available
     */
    public function isMeilisearchEnabled(): bool
    {
        return $this->meilisearch !== null && $this->meilisearch->isEnabled();
    }
    
    /**
     * Get Meilisearch service instance
     */
    public function getMeilisearch(): ?MeilisearchService
    {
        return $this->meilisearch;
    }
    
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS universal_search_index (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    source_type ENUM('email', 'email_attachment', 'calendar_event', 'drive_file', 'drive_folder', 'board', 'card', 'checklist_item', 'todo', 'client', 'contact', 'collab_doc', 'chat_message', 'mood_board_item') NOT NULL,
                    source_id VARCHAR(255) NOT NULL,
                    title VARCHAR(500),
                    content_text LONGTEXT,
                    content_snippet VARCHAR(1000),
                    client_id INT DEFAULT NULL,
                    client_name VARCHAR(255) DEFAULT NULL,
                    board_id INT DEFAULT NULL,
                    board_name VARCHAR(255) DEFAULT NULL,
                    folder_id INT DEFAULT NULL,
                    folder_name VARCHAR(255) DEFAULT NULL,
                    list_id INT DEFAULT NULL,
                    list_name VARCHAR(255) DEFAULT NULL,
                    source_date DATETIME,
                    mime_type VARCHAR(100) DEFAULT NULL,
                    extra_data JSON,
                    indexed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_source (user_email, source_type, source_id),
                    INDEX idx_user (user_email),
                    INDEX idx_client (user_email, client_id),
                    INDEX idx_board (user_email, board_id),
                    INDEX idx_date (source_date),
                    INDEX idx_type (user_email, source_type),
                    FULLTEXT INDEX ft_search (title, content_text)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\PDOException $e) {
            // Table might already exist
            if (strpos($e->getMessage(), 'already exists') === false) {
                error_log("SearchIndexerService table creation error: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Upsert an item into the search index
     * Writes to both MySQL (metadata) and Meilisearch (search)
     */
    public function upsertIndex(string $userEmail, array $data): bool
    {
        $mysqlSuccess = false;
        $meiliSuccess = true; // Default true if not enabled
        
        // Normalize source_date to MySQL DATETIME. Callers pass a mix of
        // formats: email headers are raw RFC-2822 ('Tue, 19 May 2026
        // 09:17:08 +0200'), which the DATETIME column rejects (SQLSTATE
        // 22007 / errno 1292); calendar/card/board sources pass ISO-8601 or
        // already-MySQL datetimes. strtotime() normalizes all of them; an
        // unparseable value becomes NULL rather than failing the whole row.
        $rawDate = $data['source_date'] ?? null;
        $sourceDate = null;
        if ($rawDate !== null && $rawDate !== '') {
            $ts = strtotime((string)$rawDate);
            if ($ts !== false) {
                $sourceDate = date('Y-m-d H:i:s', $ts);
            }
        }

        // Write to MySQL (source of truth)
        try {
            $stmt = $this->db->prepare("
                INSERT INTO universal_search_index 
                (user_email, source_type, source_id, title, content_text, content_snippet,
                 client_id, client_name, board_id, board_name, folder_id, folder_name,
                 list_id, list_name, source_date, mime_type, extra_data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    content_text = VALUES(content_text),
                    content_snippet = VALUES(content_snippet),
                    client_id = VALUES(client_id),
                    client_name = VALUES(client_name),
                    board_id = VALUES(board_id),
                    board_name = VALUES(board_name),
                    folder_id = VALUES(folder_id),
                    folder_name = VALUES(folder_name),
                    list_id = VALUES(list_id),
                    list_name = VALUES(list_name),
                    source_date = VALUES(source_date),
                    mime_type = VALUES(mime_type),
                    extra_data = VALUES(extra_data),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                strtolower($userEmail),
                $data['source_type'],
                (string)$data['source_id'],
                $this->sanitizeString($data['title'] ?? null),
                $this->sanitizeString($data['content_text'] ?? null),
                $this->sanitizeString($data['content_snippet'] ?? null),
                $data['client_id'] ?? null,
                $data['client_name'] ?? null,
                $data['board_id'] ?? null,
                $data['board_name'] ?? null,
                $data['folder_id'] ?? null,
                $data['folder_name'] ?? null,
                $data['list_id'] ?? null,
                $data['list_name'] ?? null,
                $sourceDate,
                $data['mime_type'] ?? null,
                isset($data['extra_data']) ? json_encode($data['extra_data']) : null,
            ]);
            
            $mysqlSuccess = true;
        } catch (\PDOException $e) {
            error_log("SearchIndexerService upsertIndex MySQL error: " . $e->getMessage());
        }
        
        // Write to Meilisearch (primary search engine).
        // Skipped while a full rebuild is in progress ($deferMeiliWrites) — the
        // rebuild batch-pushes everything to Meilisearch once at the end.
        if ($this->meilisearch !== null && !$this->deferMeiliWrites) {
            try {
                $document = MeilisearchService::buildDocument(
                    $userEmail,
                    $data['source_type'],
                    (string)$data['source_id'],
                    $data
                );
                $meiliSuccess = $this->meilisearch->upsertDocument($document);
            } catch (\Exception $e) {
                error_log("SearchIndexerService upsertIndex Meilisearch error: " . $e->getMessage());
                $meiliSuccess = false;
            }
        }
        
        return $mysqlSuccess; // Return MySQL status as primary
    }
    
    /**
     * Remove an item from the search index
     * Deletes from both MySQL and Meilisearch
     */
    public function removeFromIndex(string $userEmail, string $sourceType, string $sourceId): bool
    {
        $mysqlSuccess = false;
        
        // Delete from MySQL
        try {
            $stmt = $this->db->prepare("
                DELETE FROM universal_search_index 
                WHERE user_email = ? AND source_type = ? AND source_id = ?
            ");
            $stmt->execute([strtolower($userEmail), $sourceType, $sourceId]);
            $mysqlSuccess = true;
        } catch (\PDOException $e) {
            error_log("SearchIndexerService removeFromIndex MySQL error: " . $e->getMessage());
        }
        
        // Delete from Meilisearch
        if ($this->meilisearch !== null) {
            try {
                $documentId = MeilisearchService::buildDocumentId($sourceType, $sourceId);
                $this->meilisearch->deleteDocument($documentId);
            } catch (\Exception $e) {
                error_log("SearchIndexerService removeFromIndex Meilisearch error: " . $e->getMessage());
            }
        }
        
        return $mysqlSuccess;
    }
    
    /**
     * Remove many items of the same source type for a user in a single MySQL
     * DELETE, then best-effort batch-delete from Meilisearch. Used by bulk
     * operations like "Clear all completed todos" so we don't fire N HTTP
     * round-trips at the indexer for N rows.
     *
     * Source ids are coerced to strings to match the `source_id` column.
     *
     * @param array<int|string> $sourceIds
     * @return int Number of MySQL rows removed (0 on failure)
     */
    public function removeManyFromIndex(string $userEmail, string $sourceType, array $sourceIds): int
    {
        if (empty($sourceIds)) {
            return 0;
        }

        $userEmail = strtolower($userEmail);
        $ids = array_values(array_map('strval', $sourceIds));
        $deleted = 0;

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "DELETE FROM universal_search_index
                 WHERE user_email = ? AND source_type = ?
                 AND source_id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$userEmail, $sourceType], $ids));
            $deleted = (int)$stmt->rowCount();
        } catch (\PDOException $e) {
            error_log('SearchIndexerService removeManyFromIndex MySQL error: ' . $e->getMessage());
        }

        if ($this->meilisearch !== null) {
            try {
                $documentIds = [];
                foreach ($ids as $sid) {
                    $documentIds[] = MeilisearchService::buildDocumentId($sourceType, $sid);
                }
                // Prefer a batch delete if the client exposes one; otherwise
                // fall back to per-doc deletes (still server-side, no client
                // round-trips).
                if (method_exists($this->meilisearch, 'deleteDocuments')) {
                    $this->meilisearch->deleteDocuments($documentIds);
                } else {
                    foreach ($documentIds as $docId) {
                        $this->meilisearch->deleteDocument($docId);
                    }
                }
            } catch (\Exception $e) {
                error_log('SearchIndexerService removeManyFromIndex Meilisearch error: ' . $e->getMessage());
            }
        }

        return $deleted;
    }

    /**
     * Sync all MySQL index data to Meilisearch for a specific user
     * Used for migration or rebuilding Meilisearch index
     */
    public function syncUserToMeilisearch(string $userEmail, ?callable $progressCallback = null): array
    {
        if (!$this->meilisearch) {
            return ['success' => false, 'error' => 'Meilisearch not enabled', 'synced' => 0];
        }
        
        $userEmail = strtolower($userEmail);
        $synced = 0;
        $errors = 0;
        $batchSize = $this->config['meilisearch']['batch_size'] ?? 1000;
        
        try {
            // Count total documents
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM universal_search_index WHERE user_email = ?");
            $countStmt->execute([$userEmail]);
            $total = (int)$countStmt->fetchColumn();
            
            if ($progressCallback) {
                $progressCallback(0, $total, "Starting sync for $userEmail");
            }
            
            // Fetch and batch insert
            $offset = 0;
            while ($offset < $total) {
                $stmt = $this->db->prepare("
                    SELECT * FROM universal_search_index 
                    WHERE user_email = ? 
                    ORDER BY id 
                    LIMIT " . (int)$batchSize . " OFFSET " . (int)$offset . "
                ");
                $stmt->execute([$userEmail]);
                $rows = $stmt->fetchAll();
                
                if (empty($rows)) {
                    break;
                }
                
                // Build documents
                $documents = [];
                foreach ($rows as $row) {
                    $extraData = $row['extra_data'] ? json_decode($row['extra_data'], true) : [];
                    $documents[] = MeilisearchService::buildDocument(
                        $row['user_email'],
                        $row['source_type'],
                        $row['source_id'],
                        [
                            'title' => $row['title'],
                            'content_text' => $row['content_text'],
                            'content_snippet' => $row['content_snippet'],
                            'client_id' => $row['client_id'],
                            'client_name' => $row['client_name'],
                            'board_id' => $row['board_id'],
                            'board_name' => $row['board_name'],
                            'folder_id' => $row['folder_id'],
                            'folder_name' => $row['folder_name'],
                            'list_id' => $row['list_id'],
                            'list_name' => $row['list_name'],
                            'source_date' => $row['source_date'],
                            'mime_type' => $row['mime_type'],
                            'extra_data' => $extraData,
                        ]
                    );
                }
                
                // Batch insert to Meilisearch
                if ($this->meilisearch->upsertDocuments($documents)) {
                    $synced += count($documents);
                } else {
                    $errors += count($documents);
                }
                
                $offset += $batchSize;
                
                if ($progressCallback) {
                    $progressCallback($synced, $total, "Synced $synced / $total documents");
                }
            }
            
            return [
                'success' => true,
                'synced' => $synced,
                'errors' => $errors,
                'total' => $total,
            ];
        } catch (\Exception $e) {
            error_log("SearchIndexerService syncUserToMeilisearch error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'synced' => $synced,
                'errors' => $errors,
            ];
        }
    }
    
    /**
     * Sync all users' data to Meilisearch
     * Used for full migration
     */
    public function syncAllToMeilisearch(?callable $progressCallback = null): array
    {
        if (!$this->meilisearch) {
            return ['success' => false, 'error' => 'Meilisearch not enabled'];
        }
        
        $results = [];
        
        try {
            // Get all unique users
            $stmt = $this->db->query("SELECT DISTINCT user_email FROM universal_search_index");
            $users = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            $totalUsers = count($users);
            $currentUser = 0;
            
            foreach ($users as $userEmail) {
                $currentUser++;
                
                if ($progressCallback) {
                    $progressCallback($currentUser, $totalUsers, "Syncing user $currentUser / $totalUsers: $userEmail");
                }
                
                $result = $this->syncUserToMeilisearch($userEmail);
                $results[$userEmail] = $result;
            }
            
            // Calculate totals
            $totalSynced = array_sum(array_column($results, 'synced'));
            $totalErrors = array_sum(array_column($results, 'errors'));
            
            return [
                'success' => true,
                'users' => $totalUsers,
                'synced' => $totalSynced,
                'errors' => $totalErrors,
                'details' => $results,
            ];
        } catch (\Exception $e) {
            error_log("SearchIndexerService syncAllToMeilisearch error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => $results,
            ];
        }
    }
    
    /**
     * Clear and rebuild Meilisearch index for a user
     */
    public function rebuildMeilisearchIndex(string $userEmail): array
    {
        if (!$this->meilisearch) {
            return ['success' => false, 'error' => 'Meilisearch not enabled'];
        }
        
        $userEmail = strtolower($userEmail);
        
        // Delete all user's documents from Meilisearch
        $this->meilisearch->deleteUserDocuments($userEmail);
        
        // Wait for deletion to complete
        $this->meilisearch->waitForTasks(30);
        
        // Re-sync from MySQL
        return $this->syncUserToMeilisearch($userEmail);
    }
    
    /**
     * Index attachment content in batches using an authenticated IMAP service
     * Called during user's active session when we have IMAP access
     * 
     * @param string $userEmail User's email
     * @param ImapService $imap Authenticated IMAP connection
     * @param int $limit Max attachments to process per call
     * @return array Results with processed, success, errors, remaining counts
     */
    public function indexAttachmentContentBatch(string $userEmail, ImapService $imap, int $limit = 30): array
    {
        $userEmail = strtolower($userEmail);
        $processed = 0;
        $success = 0;
        $errors = 0;
        $remaining = 0;
        
        try {
            // Get unindexed attachments that are extractable
            $extractableMimeTypes = self::EXTRACTABLE_MIME_TYPES;
            $mimeTypePlaceholders = implode(',', array_fill(0, count($extractableMimeTypes), '?'));
            
            // Count remaining
            $countSql = "
                SELECT COUNT(*) FROM webmail_email_attachments 
                WHERE user_email = ? 
                AND content_indexed = 0 
                AND mime_type IN ($mimeTypePlaceholders)
                AND size > 0 
                AND size <= " . self::MAX_ATTACHMENT_SIZE_FOR_EXTRACTION . "
            ";
            $countParams = array_merge([$userEmail], $extractableMimeTypes);
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($countParams);
            $totalRemaining = (int)$countStmt->fetchColumn();
            
            if ($totalRemaining === 0) {
                return [
                    'processed' => 0,
                    'success' => 0,
                    'errors' => 0,
                    'remaining' => 0,
                    'message' => 'No attachments to index',
                ];
            }
            
            // Fetch batch of unindexed attachments
            $sql = "
                SELECT * FROM webmail_email_attachments 
                WHERE user_email = ? 
                AND content_indexed = 0 
                AND mime_type IN ($mimeTypePlaceholders)
                AND size > 0 
                AND size <= " . self::MAX_ATTACHMENT_SIZE_FOR_EXTRACTION . "
                ORDER BY message_date DESC 
                LIMIT " . (int)$limit . "
            ";
            $params = array_merge([$userEmail], $extractableMimeTypes);
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $attachments = $stmt->fetchAll();
            
            // Canonicalize folder paths via the folder-identity system before
            // every IMAP call. Cron-style readers like this one bypass
            // BaseController::getResolvedFolder, so stale-cased rows from
            // legacy writers (or rows whose folder was renamed since insert)
            // would otherwise hit Dovecot with the wrong name. The resolver
            // caches per-account so a 50-row batch hits the identity table
            // at most once per distinct path.
            $folderResolver = new \Webmail\Services\FolderImapResolver($this->config);
            
            foreach ($attachments as $att) {
                $processed++;
                
                try {
                    $canonicalFolder = $folderResolver->resolveForImap($userEmail, (string)$att['folder']);
                    
                    // Guard: confirm the folder still exists on IMAP before any
                    // fetch. Stale rows (case mismatch, deleted folder, renamed)
                    // would otherwise throw deep in the imap_* layer and risk
                    // escaping as \Error under PHP 8. Mark them permanently
                    // failed so we never retry them.
                    if (!$imap->selectFolder($canonicalFolder)) {
                        error_log("indexAttachmentContentBatch: folder unreachable, skipping attachment {$att['id']} folder='{$att['folder']}' canonical='{$canonicalFolder}' uid={$att['uid']}");
                        $this->markAttachmentIndexed($att['id'], false);
                        $errors++;
                        continue;
                    }
                    
                    $partToUse = $att['part'];
                    if (empty($partToUse)) {
                        $message = $imap->getMessage($canonicalFolder, (int)$att['uid']);
                        if (!empty($message['attachments'])) {
                            foreach ($message['attachments'] as $msgAtt) {
                                if (($msgAtt['filename'] ?? '') === $att['filename']) {
                                    $partToUse = $msgAtt['part'] ?? '1';
                                    $this->updateAttachmentPart($att['id'], $partToUse);
                                    break;
                                }
                            }
                        }
                        if (empty($partToUse)) {
                            $partToUse = '1';
                        }
                    }
                    
                    $attachmentData = $imap->getAttachment(
                        $canonicalFolder, 
                        (int)$att['uid'], 
                        $partToUse
                    );
                    
                    if (!$attachmentData || empty($attachmentData['content'])) {
                        // Mark as failed so we don't retry forever
                        $this->markAttachmentIndexed($att['id'], false);
                        $errors++;
                        continue;
                    }
                    
                    // Index with content extraction
                    $indexData = [
                        'filename' => $att['filename'],
                        'mime_type' => $att['mime_type'],
                        'from_email' => $att['from_email'],
                        'from_name' => $att['from_name'],
                        'subject' => $att['subject'],
                        'folder' => $att['folder'],
                        'uid' => $att['uid'],
                        'part' => $att['part'] ?? '1',
                        'size' => $att['size'],
                        'message_date' => $att['message_date'],
                        'content' => $attachmentData['content'],
                    ];
                    
                    $result = $this->indexEmailAttachment($userEmail, $indexData);
                    
                    if ($result) {
                        $this->markAttachmentIndexed($att['id'], true);
                        $success++;
                    } else {
                        $this->markAttachmentIndexed($att['id'], false);
                        $errors++;
                    }
                    
                } catch (\Throwable $e) {
                    // Catch \Throwable (not just \Exception) so PHP 8 \TypeError
                    // from imap_* returning false into typed code paths cannot
                    // escape this loop and 500 the calling endpoint.
                    error_log("indexAttachmentContentBatch error for {$att['filename']}: " . $e->getMessage());
                    $this->markAttachmentIndexed($att['id'], false);
                    $errors++;
                }
            }
            
            $remaining = max(0, $totalRemaining - $processed);
            
            return [
                'processed' => $processed,
                'success' => $success,
                'errors' => $errors,
                'remaining' => $remaining,
            ];
            
        } catch (\Throwable $e) {
            error_log("indexAttachmentContentBatch error: " . $e->getMessage());
            return [
                'processed' => $processed,
                'success' => $success,
                'errors' => $errors,
                'remaining' => $remaining,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Index email body content in batches using an authenticated IMAP service
     * Called during user's active session when we have IMAP access
     * Fetches full email bodies and updates the search index with full-text content
     * 
     * @param string $userEmail User's email
     * @param ImapService $imap Authenticated IMAP connection
     * @param int $limit Max emails to process per call
     * @return array Results with processed, success, errors, remaining counts
     */
    public function indexEmailBodiesBatch(string $userEmail, ImapService $imap, int $limit = 20): array
    {
        $userEmail = strtolower($userEmail);
        $processed = 0;
        $success = 0;
        $errors = 0;
        $remaining = 0;
        
        try {
            // Find emails in the search index that only have snippet-level content
            // These are emails indexed via indexEmailFromCache() without full body
            // We detect them by checking if content_text is short (< 500 chars means likely no body)
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) FROM universal_search_index 
                WHERE user_email = ? 
                AND source_type = 'email'
                AND (LENGTH(content_text) < 500 OR content_text IS NULL)
                AND (content_text IS NULL OR content_text NOT LIKE '%[body-indexed]%')
            ");
            $countStmt->execute([$userEmail]);
            $totalRemaining = (int)$countStmt->fetchColumn();
            
            if ($totalRemaining === 0) {
                return [
                    'processed' => 0,
                    'success' => 0,
                    'errors' => 0,
                    'remaining' => 0,
                    'message' => 'All email bodies already indexed',
                ];
            }
            
            // Fetch batch of emails that need body indexing (newest first)
            $stmt = $this->db->prepare("
                SELECT source_id, title, folder_name, extra_data
                FROM universal_search_index 
                WHERE user_email = ? 
                AND source_type = 'email'
                AND (LENGTH(content_text) < 500 OR content_text IS NULL)
                AND (content_text IS NULL OR content_text NOT LIKE '%[body-indexed]%')
                ORDER BY source_date DESC 
                LIMIT " . (int)$limit . "
            ");
            $stmt->execute([$userEmail]);
            $emails = $stmt->fetchAll();
            
            // Same canonicalization pattern as indexAttachmentContentBatch:
            // body indexer also reads folder strings from a DB row and goes
            // straight to IMAP, bypassing the BaseController normalization.
            $folderResolver = new \Webmail\Services\FolderImapResolver($this->config);
            
            foreach ($emails as $email) {
                $processed++;
                
                try {
                    // Parse source_id which is "folder:uid"
                    $extra = $email['extra_data'] ? json_decode($email['extra_data'], true) : [];
                    $folder = $extra['folder'] ?? null;
                    $uid = $extra['uid'] ?? null;
                    
                    // Fallback: parse from source_id
                    if ((!$folder || !$uid) && strpos($email['source_id'], ':') !== false) {
                        $parts = explode(':', $email['source_id'], 2);
                        $folder = $folder ?: $parts[0];
                        $uid = $uid ?: (int)$parts[1];
                    }
                    
                    if (!$folder || !$uid) {
                        // Unparseable row (no folder/uid): mark it so it stops
                        // being re-selected on every batch.
                        $this->markEmailBodyIndexed($userEmail, $email['source_id']);
                        $errors++;
                        continue;
                    }
                    
                    $canonicalFolder = $folderResolver->resolveForImap($userEmail, (string)$folder);
                    
                    // Same selectFolder guard as the attachment indexer: skip
                    // stale-cased / deleted folders cleanly instead of letting
                    // a deeper imap_* failure escape as \Error.
                    if (!$imap->selectFolder($canonicalFolder)) {
                        error_log("indexEmailBodiesBatch: folder unreachable, skipping source_id={$email['source_id']} folder='{$folder}' canonical='{$canonicalFolder}'");
                        $this->markEmailBodyIndexed($userEmail, $email['source_id']);
                        $errors++;
                        continue;
                    }
                    
                    $message = $imap->getMessage($canonicalFolder, (int)$uid);
                    
                    if (!$message) {
                        $this->markEmailBodyIndexed($userEmail, $email['source_id']);
                        $errors++;
                        continue;
                    }
                    
                    // Index under the ORIGINAL selected source_id so the upsert
                    // UPDATES this exact row instead of inserting a duplicate
                    // under a canonical-folder id (the duplicate is what kept
                    // the original short row in the "remaining" set forever).
                    if ($this->indexEmail($userEmail, $message, $canonicalFolder, $email['source_id'])) {
                        $success++;
                    } else {
                        $errors++;
                    }

                    // Stamp the body-indexed marker so this row drains even when
                    // the full body is genuinely short (< 500 chars). Without it,
                    // short emails are re-selected every batch and `remaining`
                    // never falls. No-op for rows already >= 500 chars.
                    $this->markEmailBodyIndexed($userEmail, $email['source_id']);
                    
                } catch (\Throwable $e) {
                    // Catch \Throwable (not just \Exception) so a PHP 8
                    // \TypeError from a missing IMAP folder/structure can't
                    // escape and 500 the runtime endpoint.
                    error_log("indexEmailBodiesBatch error for source_id={$email['source_id']}: " . $e->getMessage());
                    $errors++;
                }
            }
            
            $remaining = max(0, $totalRemaining - $processed);
            
            return [
                'processed' => $processed,
                'success' => $success,
                'errors' => $errors,
                'remaining' => $remaining,
            ];
            
        } catch (\Throwable $e) {
            error_log("indexEmailBodiesBatch error: " . $e->getMessage());
            return [
                'processed' => $processed,
                'success' => $success,
                'errors' => $errors,
                'remaining' => $remaining,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Mark an email as body-indexed (prevent retry for deleted/failed emails)
     * Sets content_text to a minimum marker so it won't be picked up again
     */
    private function markEmailBodyIndexed(string $userEmail, string $sourceId): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE universal_search_index 
                SET content_text = CONCAT(COALESCE(content_text, ''), '\n[body-indexed]')
                WHERE user_email = ? AND source_type = 'email' AND source_id = ?
                AND (content_text IS NULL OR LENGTH(content_text) < 500)
                AND (content_text IS NULL OR content_text NOT LIKE '%[body-indexed]%')
            ");
            $stmt->execute([strtolower($userEmail), $sourceId]);
        } catch (\PDOException $e) {
            error_log("markEmailBodyIndexed error: " . $e->getMessage());
        }
    }
    
    /**
     * Mark an attachment as indexed (or failed)
     */
    private function markAttachmentIndexed(int $attachmentId, bool $success): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE webmail_email_attachments 
                SET content_indexed = ?, content_indexed_at = NOW() 
                WHERE id = ?
            ");
            // 1 = success, -1 = failed
            $stmt->execute([$success ? 1 : -1, $attachmentId]);
        } catch (\PDOException $e) {
            error_log("markAttachmentIndexed error: " . $e->getMessage());
        }
    }
    
    /**
     * Update the part number for an attachment (discovered during indexing)
     */
    private function updateAttachmentPart(int $attachmentId, string $part): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE webmail_email_attachments 
                SET part = ? 
                WHERE id = ?
            ");
            $stmt->execute([$part, $attachmentId]);
        } catch (\PDOException $e) {
            error_log("updateAttachmentPart error: " . $e->getMessage());
        }
    }
    
    /**
     * Index a calendar event
     */
    public function indexCalendarEvent(string $userEmail, array $event): bool
    {
        $title = $event['title'] ?? 'Untitled Event';
        $description = $event['description'] ?? '';
        $location = $event['location'] ?? '';
        
        // Build searchable content
        $contentParts = array_filter([
            $title,
            $description,
            $location,
            $event['calendar_name'] ?? '',
        ]);
        
        // Format date for display
        $startTime = $event['start_time'] ?? null;
        $dateStr = '';
        if ($startTime) {
            try {
                $date = new \DateTime($startTime);
                $dateStr = $date->format('Y-m-d H:i');
            } catch (\Exception $e) {
                $dateStr = $startTime;
            }
        }
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'calendar_event',
            'source_id' => (string)$event['id'],
            'title' => $title,
            'content_text' => implode("\n", $contentParts),
            'content_snippet' => $description ?: ($location ? "Location: $location" : $dateStr),
            'source_date' => $startTime,
            'extra_data' => [
                'calendar_id' => $event['calendar_id'] ?? null,
                'calendar_name' => $event['calendar_name'] ?? null,
                'location' => $location,
                'start_time' => $startTime,
                'end_time' => $event['end_time'] ?? null,
                'all_day' => $event['all_day'] ?? false,
                'color' => $event['color'] ?? $event['calendar_color'] ?? null,
            ],
        ]);
    }
    
    /**
     * MIME types that support content extraction.
     *
     * Public so cron scripts (cron/index-attachments.php) can reuse the same
     * list without drifting. If you add a new mime here, make sure
     * extractFileContent() has a branch for it AND the corresponding PHP
     * package is in composer.json.
     */
    public const EXTRACTABLE_MIME_TYPES = [
        'application/pdf',
        // Word
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        // Excel
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/vnd.ms-excel.sheet.macroenabled.12',
        // PowerPoint
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-powerpoint',
        // Plain
        'text/plain',
        'text/csv',
        'text/markdown',
        'text/html',
    ];
    
    /**
     * Maximum attachment size for content extraction (10MB)
     */
    private const MAX_ATTACHMENT_SIZE_FOR_EXTRACTION = 10 * 1024 * 1024;
    
    /**
     * Index an email attachment
     * 
     * @param string $userEmail User's email
     * @param array $attachment Attachment data:
     *   - filename: Attachment filename
     *   - mime_type: MIME type
     *   - from_email: Sender email
     *   - from_name: Sender name
     *   - subject: Email subject
     *   - folder: IMAP folder
     *   - uid: Message UID
     *   - part: Attachment part number
     *   - size: File size in bytes
     *   - message_date: Message date
     *   - content: (optional) Binary content for extraction
     *   - extract_content: (optional) Force content extraction
     */
    public function indexEmailAttachment(string $userEmail, array $attachment): bool
    {
        try {
        $filename = $attachment['filename'] ?? 'Unknown';
        $mimeType = $attachment['mime_type'] ?? '';
        $fromEmail = $attachment['from_email'] ?? '';
        $fromName = $attachment['from_name'] ?? '';
        $subject = $attachment['subject'] ?? '';
        $isGeneric = ($filename === 'Attachment(s)');
        $size = $attachment['size'] ?? 0;
        
        // Extract document content if available and mime type is extractable
        $extractedContent = '';
        $contentIndexed = false;
        
        if (!$isGeneric && $this->isExtractableMimeType($mimeType) && $size <= self::MAX_ATTACHMENT_SIZE_FOR_EXTRACTION) {
            // Check if content is provided directly
            if (!empty($attachment['content'])) {
                $extractedContent = $this->extractContentFromBinary($mimeType, $attachment['content'], $filename);
                $contentIndexed = !empty($extractedContent);
            }
        }
        
        // Build searchable content - filename, sender, subject + extracted content
        $contentText = implode("\n", array_filter([
            $filename,
            $isGeneric ? 'attachment file attached' : pathinfo($filename, PATHINFO_EXTENSION),
            $fromName,
            $fromEmail,
            $subject,
            $extractedContent, // Include extracted document content
        ]));
        
        // Try to find client from sender
        $clientId = null;
        $clientName = null;
        if ($fromEmail && strpos($fromEmail, '@') !== false) {
            $client = $this->findClientByEmail($userEmail, $fromEmail);
            if ($client) {
                $clientId = $client['id'];
                $clientName = $client['display_name'] ?? $client['domain'];
            }
        }
        
        // Format file size
        $sizeStr = $size > 0 ? $this->formatFileSize($size) : '';
        
        // Build title and snippet based on whether we have specific file info
        $title = $isGeneric ? $subject : $filename;
        $senderDisplay = $fromName ?: $fromEmail;
        
        // Use extracted content for snippet if available
        $snippet = $isGeneric 
            ? "Email with attachment from: " . $senderDisplay
            : "From: " . $senderDisplay . ($sizeStr ? " • " . $sizeStr : "");
        
        if ($contentIndexed && strlen($extractedContent) > 0) {
            // Create snippet from extracted content
            $snippet = $this->createSnippet($extractedContent, 200);
        }
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'email_attachment',
            'source_id' => ($attachment['folder'] ?? 'INBOX') . ':' . ($attachment['uid'] ?? '0') . ':' . md5($filename),
            'title' => $title,
            'content_text' => $contentText,
            'content_snippet' => $snippet,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'folder_name' => $attachment['folder'] ?? null,
            'source_date' => $attachment['message_date'] ?? null,
            'mime_type' => $mimeType,
            'extra_data' => [
                'from' => $fromName,
                'from_email' => $fromEmail,
                'folder' => $attachment['folder'] ?? null,
                'uid' => $attachment['uid'] ?? null,
                'part' => $attachment['part'] ?? '1',
                'subject' => $subject,
                'size' => $size,
                'is_generic' => $isGeneric,
                'content_indexed' => $contentIndexed,
            ],
        ]);
        } catch (\Exception $e) {
            error_log("SearchIndexerService indexEmailAttachment error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if MIME type supports content extraction
     */
    public function isExtractableMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::EXTRACTABLE_MIME_TYPES, true) 
            || str_starts_with($mimeType, 'text/');
    }
    
    /**
     * Extract text content from binary data
     */
    private function extractContentFromBinary(string $mimeType, string $content, string $filename = ''): string
    {
        if (empty($content)) {
            return '';
        }
        
        try {
            // Save to temp file for extraction
            $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'tmp';
            $tempFile = tempnam(sys_get_temp_dir(), 'att_') . '.' . $extension;
            file_put_contents($tempFile, $content);
            
            // Extract using existing method
            $extractedText = $this->extractFileContent($mimeType, $tempFile);
            
            // Clean up temp file
            @unlink($tempFile);
            
            // Limit extracted content size (50KB)
            if (strlen($extractedText) > 50000) {
                $extractedText = substr($extractedText, 0, 50000) . '...';
            }
            
            return $extractedText;
        } catch (\Exception $e) {
            error_log("SearchIndexerService extractContentFromBinary error: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Format file size for display
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 1) . ' GB';
    }
    
    /**
     * Index an email from cached folder_index data
     * This is faster than full email indexing as it uses pre-cached snippets
     */
    public function indexEmailFromCache(string $userEmail, array $cachedEmail, ?string $folder = null): bool
    {
        $subject = $cachedEmail['subject'] ?? '(No Subject)';
        $snippet = $cachedEmail['snippet'] ?? '';
        $sender = $cachedEmail['from'] ?? $cachedEmail['sender'] ?? '';
        
        // Use explicit from_email/from_name if provided, otherwise parse from sender string
        $fromEmail = $cachedEmail['from_email'] ?? null;
        $fromName = $cachedEmail['from_name'] ?? null;
        
        if (!$fromEmail || !$fromName) {
            // Parse sender - could be "Name <email>" or just "email" or just "name"
            $fromName = $fromName ?: $sender;
            $fromEmail = $fromEmail ?: $sender;
            if (preg_match('/^(.+?)\s*<(.+?)>$/', $sender, $matches)) {
                $fromName = trim($matches[1], '"\'');
                $fromEmail = $matches[2];
            }
        }
        
        // Try to find client from sender email
        $clientId = null;
        $clientName = null;
        if ($fromEmail && strpos($fromEmail, '@') !== false) {
            $client = $this->findClientByEmail($userEmail, $fromEmail);
            if ($client) {
                $clientId = $client['id'];
                $clientName = $client['display_name'] ?? $client['domain'];
            }
        }
        
        // Check if email has attachments (from conversation data)
        $hasAttachment = $cachedEmail['has_attachment'] ?? false;
        
        // Build searchable content - include BOTH name and email for better search
        $contentText = implode("\n", array_filter([
            $subject,
            $fromName,
            $fromEmail,  // This is the crucial part - email address must be searchable
            $snippet,
            $hasAttachment ? 'attachment attachments has_attachment file' : null, // Make attachment emails searchable
        ]));
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'email',
            'source_id' => ($folder ?? 'INBOX') . ':' . ($cachedEmail['uid'] ?? '0'),
            'title' => $subject,
            'content_text' => $contentText,
            'content_snippet' => $snippet ?: ($fromName ? "From: $fromName" : ''),
            'client_id' => $clientId,
            'client_name' => $clientName,
            'folder_name' => $folder,
            'source_date' => $cachedEmail['date'] ?? null,
            'extra_data' => [
                'from' => $fromName,
                'from_email' => $fromEmail,
                'folder' => $folder,
                'uid' => $cachedEmail['uid'] ?? null,
            ],
        ]);
    }
    
    /**
     * Index an email message (full version with body)
     */
    public function indexEmail(string $userEmail, array $message, ?string $folder = null, ?string $overrideSourceId = null): bool
    {
        $subject = $message['subject'] ?? '(No Subject)';
        $body = $this->extractEmailBody($message);
        $snippet = $this->createSnippet($body, 500);
        
        // Extract sender info
        $from = '';
        $fromEmail = '';
        if (isset($message['from'])) {
            if (is_array($message['from']) && !empty($message['from'])) {
                $fromEmail = $message['from'][0]['email'] ?? '';
                $from = $message['from'][0]['name'] ?? $fromEmail;
            } elseif (is_string($message['from'])) {
                $from = $message['from'];
                $fromEmail = $message['from'];
            }
        }
        
        // Try to find client from sender domain
        $clientId = null;
        $clientName = null;
        if ($fromEmail) {
            $client = $this->findClientByEmail($userEmail, $fromEmail);
            if ($client) {
                $clientId = $client['id'];
                $clientName = $client['display_name'] ?? $client['domain'];
            }
        }
        
        // Build searchable content: subject + from + to + body
        $to = $this->extractRecipients($message['to'] ?? []);
        $cc = $this->extractRecipients($message['cc'] ?? []);
        $contentText = implode("\n", array_filter([
            $subject,
            "From: $from",
            $to ? "To: $to" : null,
            $cc ? "Cc: $cc" : null,
            $body
        ]));
        
        // Use same source_id format as indexEmailFromCache: "folder:uid"
        // This ensures upsert correctly updates entries created by the cache indexer
        $emailFolder = $folder ?? $message['folder'] ?? 'INBOX';
        $emailUid = $message['uid'] ?? '0';
        // When the caller knows the exact row being refreshed (e.g. the body
        // indexer re-reading a snippet row), honour its source_id so the upsert
        // UPDATES that row rather than inserting a duplicate under a different
        // (canonical-folder) id.
        $sourceId = $overrideSourceId ?? ($emailFolder . ':' . $emailUid);
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'email',
            'source_id' => $sourceId,
            'title' => $subject,
            'content_text' => $contentText,
            'content_snippet' => $snippet,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'folder_name' => $emailFolder,
            'source_date' => $message['date'] ?? null,
            'extra_data' => [
                'folder' => $emailFolder,
                'from' => $from,
                'from_email' => $fromEmail,
                'to' => $to,
                'uid' => $emailUid,
                'has_attachment' => !empty($message['attachments']),
                'message_id' => $message['message_id'] ?? null,
            ],
        ]);
    }
    
    /**
     * Index a board card with relationships
     */
    public function indexCard(string $userEmail, array $card, ?array $list = null, ?array $board = null, ?array $client = null): bool
    {
        // Build content from card details
        $contentParts = [$card['title'] ?? ''];
        
        if (!empty($card['description'])) {
            $contentParts[] = $card['description'];
        }
        
        // Add checklist items to searchable content
        if (!empty($card['checklists'])) {
            foreach ($card['checklists'] as $checklist) {
                $contentParts[] = "Checklist: " . ($checklist['title'] ?? '');
                if (!empty($checklist['items'])) {
                    foreach ($checklist['items'] as $item) {
                        $contentParts[] = "- " . ($item['title'] ?? '');
                    }
                }
            }
        }
        
        $contentText = implode("\n", $contentParts);
        $snippet = $this->createSnippet($card['description'] ?? $card['title'] ?? '', 500);
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'card',
            'source_id' => $card['id'],
            'title' => $card['title'] ?? 'Untitled Card',
            'content_text' => $contentText,
            'content_snippet' => $snippet,
            'client_id' => $client['id'] ?? $board['client_id'] ?? null,
            'client_name' => $client['display_name'] ?? null,
            'board_id' => $board['id'] ?? $card['board_id'] ?? null,
            'board_name' => $board['name'] ?? null,
            'list_id' => $list['id'] ?? $card['list_id'] ?? null,
            'list_name' => $list['name'] ?? null,
            'source_date' => $card['created_at'] ?? null,
            'extra_data' => [
                'due_date' => $card['due_date'] ?? null,
                'completed' => $card['completed'] ?? false,
                'assigned_to' => $card['assigned_to'] ?? null,
                'labels' => $card['labels'] ?? [],
            ],
        ]);
    }
    
    /**
     * Index a board
     */
    public function indexBoard(string $userEmail, array $board, ?array $client = null): bool
    {
        return $this->upsertIndex($userEmail, [
            'source_type' => 'board',
            'source_id' => $board['id'],
            'title' => $board['name'] ?? 'Untitled Board',
            'content_text' => ($board['name'] ?? '') . "\n" . ($board['description'] ?? ''),
            'content_snippet' => $this->createSnippet($board['description'] ?? $board['name'] ?? '', 500),
            'client_id' => $client['id'] ?? $board['client_id'] ?? null,
            'client_name' => $client['display_name'] ?? null,
            'source_date' => $board['created_at'] ?? null,
        ]);
    }
    
    /**
     * Index a todo with email reference
     */
    public function indexTodo(string $userEmail, array $todo): bool
    {
        $extra = [];
        
        // Include email reference if exists
        if (!empty($todo['ref_message_id'])) {
            $extra['email_ref'] = [
                'folder' => $todo['ref_folder'] ?? null,
                'uid' => $todo['ref_uid'] ?? null,
                'subject' => $todo['ref_subject'] ?? null,
                'from' => $todo['ref_from'] ?? null,
            ];
        }
        
        $contentText = ($todo['title'] ?? '') . "\n" . ($todo['description'] ?? '');
        if (!empty($todo['ref_subject'])) {
            $contentText .= "\nLinked email: " . $todo['ref_subject'];
        }
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'todo',
            'source_id' => $todo['id'],
            'title' => $todo['title'] ?? 'Untitled Todo',
            'content_text' => $contentText,
            'content_snippet' => $this->createSnippet($todo['description'] ?? $todo['title'] ?? '', 500),
            'source_date' => $todo['created_at'] ?? null,
            'extra_data' => array_merge($extra, [
                'due_date' => $todo['due_date'] ?? null,
                'completed' => $todo['completed'] ?? false,
                'priority' => $todo['priority'] ?? 'normal',
            ]),
        ]);
    }
    
    /**
     * Index a drive file with content extraction
     */
    public function indexDriveFile(string $userEmail, array $file, ?array $folder = null, ?array $client = null, ?array $board = null): bool
    {
        $filePath = $this->getDriveFilePath($userEmail, $file);
        $content = '';
        $mimeType = $file['mime_type'] ?? '';
        $originalName = $file['original_name'] ?? $file['filename'] ?? 'unknown';
        
        // Extract text content for searchable documents
        if ($filePath && file_exists($filePath)) {
            $content = $this->extractFileContent($mimeType, $filePath);
            if (!empty($content)) {
                error_log("SearchIndexerService indexDriveFile: extracted " . strlen($content) . " chars from '{$originalName}' (mime: {$mimeType}, path: {$filePath})");
            } else {
                error_log("SearchIndexerService indexDriveFile: no text extracted from '{$originalName}' (mime: {$mimeType}, path: {$filePath})");
            }
        } else {
            error_log("SearchIndexerService indexDriveFile: file not found for '{$originalName}' (resolved path: " . ($filePath ?: 'null') . ")");
        }
        
        // Fallback to filename if no content extracted
        if (empty($content)) {
            $content = $originalName;
        }
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'drive_file',
            'source_id' => $file['id'],
            'title' => $file['original_name'] ?? $file['filename'] ?? 'Unknown File',
            'content_text' => $content,
            'content_snippet' => $this->createSnippet($content, 500),
            'client_id' => $folder['client_id'] ?? $client['id'] ?? null,
            'client_name' => $client['display_name'] ?? null,
            'board_id' => $folder['board_id'] ?? $board['id'] ?? null,
            'board_name' => $board['name'] ?? null,
            'folder_id' => $folder['id'] ?? $file['folder_id'] ?? null,
            'folder_name' => $folder['name'] ?? null,
            'source_date' => $file['created_at'] ?? $file['uploaded_at'] ?? null,
            'mime_type' => $file['mime_type'] ?? null,
            'extra_data' => [
                'size' => $file['size'] ?? 0,
                'extension' => pathinfo($file['original_name'] ?? '', PATHINFO_EXTENSION),
            ],
        ]);
    }
    
    /**
     * Index a drive folder
     */
    public function indexDriveFolder(string $userEmail, array $folder, ?array $client = null, ?array $board = null): bool
    {
        return $this->upsertIndex($userEmail, [
            'source_type' => 'drive_folder',
            'source_id' => $folder['id'],
            'title' => $folder['name'] ?? 'Untitled Folder',
            'content_text' => $folder['name'] ?? '',
            'content_snippet' => $folder['name'] ?? '',
            'client_id' => $folder['client_id'] ?? $client['id'] ?? null,
            'client_name' => $client['display_name'] ?? null,
            'board_id' => $folder['board_id'] ?? $board['id'] ?? null,
            'board_name' => $board['name'] ?? null,
            'source_date' => $folder['created_at'] ?? null,
        ]);
    }
    
    /**
     * Index a client
     */
    public function indexClient(string $userEmail, array $client): bool
    {
        $contentParts = [
            $client['display_name'] ?? '',
            $client['domain'] ?? '',
        ];
        
        // Add contacts if available
        if (!empty($client['contacts'])) {
            foreach ($client['contacts'] as $contact) {
                $contentParts[] = ($contact['name'] ?? '') . ' ' . ($contact['email'] ?? '');
            }
        }
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'client',
            'source_id' => $client['id'],
            'title' => $client['display_name'] ?? $client['domain'] ?? 'Unknown Client',
            'content_text' => implode("\n", array_filter($contentParts)),
            'content_snippet' => $client['display_name'] ?? $client['domain'] ?? '',
            'client_id' => $client['id'],
            'client_name' => $client['display_name'] ?? $client['domain'],
            'source_date' => $client['created_at'] ?? null,
            'extra_data' => [
                'domain' => $client['domain'] ?? null,
                'status' => $client['status'] ?? null,
            ],
        ]);
    }
    
    /**
     * Index a chat message
     * 
     * @param string $userEmail User's email (participant who can search this message)
     * @param array $message Message data with: id, content, content_type, created_at, sender_name, sender_email, conversation_id
     * @param array|null $conversation Conversation data with: type, name, topic, slug
     */
    public function indexChatMessage(string $userEmail, array $message, ?array $conversation = null): bool
    {
        $content = $message['content'] ?? '';
        $senderName = $message['sender_name'] ?? '';
        $senderEmail = $message['sender_email'] ?? '';
        $convName = $conversation['name'] ?? $conversation['slug'] ?? null;
        $convType = $conversation['type'] ?? 'direct';
        
        // Skip system messages and empty content
        $contentType = $message['content_type'] ?? 'text';
        if ($contentType === 'system' || empty(trim($content))) {
            return false;
        }
        
        // Build searchable content
        $contentParts = array_filter([
            $content,
            $senderName,
            $senderEmail,
            $convName,
            $conversation['topic'] ?? null,
        ]);
        $contentText = implode("\n", $contentParts);
        
        // Build a title from the message preview
        $title = $this->createSnippet(strip_tags($content), 100);
        
        // Build snippet
        $snippet = $this->createSnippet(strip_tags($content), 200);
        if ($senderName) {
            $snippet = $senderName . ': ' . $snippet;
        }
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'chat_message',
            'source_id' => (string)$message['id'],
            'title' => $title,
            'content_text' => $contentText,
            'content_snippet' => $snippet,
            'source_date' => $message['created_at'] ?? null,
            'extra_data' => [
                'conversation_id' => $message['conversation_id'] ?? null,
                'conversation_type' => $convType,
                'conversation_name' => $convName,
                'from' => $senderName,
                'from_email' => $senderEmail,
                'content_type' => $contentType,
            ],
        ]);
    }
    
    /**
     * Index a mood board item (note, text, link, todo_list, image, etc.)
     * 
     * @param string $userEmail Owner's email
     * @param array $item Item data with: id, type, title, content, url, board_id, created_at
     * @param array|null $board Board data with: id, name, description, client_id
     * @param array $todoTexts Optional array of todo item texts for todo_list type items
     */
    public function indexMoodBoardItem(string $userEmail, array $item, ?array $board = null, array $todoTexts = []): bool
    {
        $itemType = $item['type'] ?? 'note';
        $title = $item['title'] ?? '';
        $content = $item['content'] ?? '';
        $url = $item['url'] ?? '';
        $boardName = $board['name'] ?? '';
        
        // Skip items with no searchable content (e.g. plain images without titles, color swatches)
        if (empty($title) && empty($content) && empty($url) && empty($todoTexts)) {
            return false;
        }
        
        // Build searchable content
        $contentParts = array_filter([
            $title,
            $content,
            $url,
            $boardName,
        ]);
        
        // Add todo items if available
        foreach ($todoTexts as $todoText) {
            $contentParts[] = $todoText;
        }
        
        $contentText = implode("\n", $contentParts);
        
        // Build a meaningful title
        if (empty($title)) {
            $title = $boardName ? $boardName . ' - ' . ucfirst($itemType) : ucfirst($itemType);
        }
        
        return $this->upsertIndex($userEmail, [
            'source_type' => 'mood_board_item',
            'source_id' => (string)$item['id'],
            'title' => $title,
            'content_text' => $contentText,
            'content_snippet' => $this->createSnippet($content ?: $title, 200),
            'client_id' => $board['client_id'] ?? null,
            'source_date' => $item['created_at'] ?? null,
            'extra_data' => [
                'board_id' => $item['board_id'] ?? $board['id'] ?? null,
                'board_name' => $boardName,
                'item_type' => $itemType,
            ],
        ]);
    }
    
    /**
     * Index a collab document
     */
    public function indexCollabDoc(string $userEmail, array $doc): bool
    {
        return $this->upsertIndex($userEmail, [
            'source_type' => 'collab_doc',
            'source_id' => $doc['uuid'] ?? $doc['id'],
            'title' => $doc['title'] ?? 'Untitled Document',
            'content_text' => ($doc['title'] ?? '') . "\n" . ($doc['content_text'] ?? ''),
            'content_snippet' => $this->createSnippet($doc['content_text'] ?? $doc['title'] ?? '', 500),
            'source_date' => $doc['created_at'] ?? null,
            'extra_data' => [
                'type' => $doc['type'] ?? 'document',
                'drive_file_id' => $doc['drive_file_id'] ?? null,
            ],
        ]);
    }
    
    /**
     * Bulk index multiple items (for initial indexing)
     */
    public function bulkIndex(string $userEmail, string $sourceType, array $items, callable $transformer): int
    {
        $indexed = 0;
        
        foreach ($items as $item) {
            $data = $transformer($item);
            if ($data && $this->upsertIndex($userEmail, array_merge(['source_type' => $sourceType], $data))) {
                $indexed++;
            }
        }
        
        return $indexed;
    }
    
    /**
     * Rebuild index for a specific user
     */
    public function rebuildUserIndex(string $userEmail): array
    {
        // A full rebuild can iterate thousands of rows; never let the default
        // PHP time limit abort it half-way (the classic opaque-500 cause).
        @set_time_limit(0);

        // Defer Meilisearch writes: every index*() below routes through
        // upsertIndex(), which would otherwise fire one synchronous HTTP POST
        // to Meilisearch per row. On a real mailbox that's thousands of
        // round-trips in a single web request and reliably times out. Instead
        // we write MySQL only, then batch-push to Meilisearch once at the end.
        $this->deferMeiliWrites = true;

        $stats = [
            'emails' => 0,
            'attachments' => 0,
            'calendar_events' => 0,
            'boards' => 0,
            'cards' => 0,
            'todos' => 0,
            'drive_files' => 0,
            'clients' => 0,
            'chat_messages' => 0,
            'mood_board_items' => 0,
        ];
        
        $userEmail = strtolower($userEmail);
        
        // Index emails from conversation_members table (has from_email and from_name)
        try {
            // Before clearing, save existing rich email body content (indexed via full IMAP fetch)
            // so we can restore it after re-indexing from cache (which only has short snippets)
            $richContentStmt = $this->db->prepare("
                SELECT source_id, content_text, content_snippet 
                FROM universal_search_index 
                WHERE user_email = ? AND source_type = 'email' 
                AND content_text IS NOT NULL AND LENGTH(content_text) >= 500
            ");
            $richContentStmt->execute([$userEmail]);
            $richContent = [];
            while ($row = $richContentStmt->fetch()) {
                $richContent[$row['source_id']] = [
                    'content_text' => $row['content_text'],
                    'content_snippet' => $row['content_snippet'],
                ];
            }
            
            // Clear all existing email entries for this user (removes stale/deleted emails)
            $clearStmt = $this->db->prepare("DELETE FROM universal_search_index WHERE user_email = ? AND source_type = 'email'");
            $clearStmt->execute([$userEmail]);
            
            // Folders to skip (trash, deleted items, spam, junk, drafts)
            $skipFolders = ['trash', 'deleted items', 'deleted', 'spam', 'junk', 'drafts'];
            
            $stmt = $this->db->prepare("
                SELECT
                    m.uid,
                    fi.current_path AS folder,
                    m.subject,
                    m.from_email,
                    m.from_name,
                    m.message_date as date,
                    c.snippet,
                    c.has_attachment
                FROM webmail_conversation_members m
                LEFT JOIN webmail_conversations c
                    ON c.user_email = m.user_email
                    AND c.conversation_id = m.conversation_id
                LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
                WHERE m.user_email = ?
                ORDER BY m.message_date DESC
                LIMIT 2000
            ");
            $stmt->execute([$userEmail]);
            $messages = $stmt->fetchAll();
            
            foreach ($messages as $msg) {
                // Skip trash, deleted items, spam, junk, drafts folders
                $folderLower = strtolower($msg['folder'] ?? '');
                $shouldSkip = false;
                foreach ($skipFolders as $skip) {
                    if (strpos($folderLower, $skip) !== false) {
                        $shouldSkip = true;
                        break;
                    }
                }
                if ($shouldSkip) {
                    continue;
                }
                
                // Build sender string with both name and email for better search
                $sender = $msg['from_name'] ?: $msg['from_email'];
                if ($msg['from_name'] && $msg['from_email'] && $msg['from_name'] !== $msg['from_email']) {
                    $sender = $msg['from_name'] . ' <' . $msg['from_email'] . '>';
                }
                
                $message = [
                    'uid' => $msg['uid'],
                    'subject' => $msg['subject'],
                    'from' => $sender,
                    'from_email' => $msg['from_email'],
                    'from_name' => $msg['from_name'],
                    'snippet' => $msg['snippet'],
                    'date' => $msg['date'],
                    'has_attachment' => (bool)($msg['has_attachment'] ?? false),
                ];
                
                if ($this->indexEmailFromCache($userEmail, $message, $msg['folder'])) {
                    $stats['emails']++;
                }
            }
            
            // Restore rich email body content that was previously indexed via full IMAP fetch
            // This prevents rebuild from downgrading full-body content back to short snippets
            if (!empty($richContent)) {
                $restoreStmt = $this->db->prepare("
                    UPDATE universal_search_index 
                    SET content_text = ?, content_snippet = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE user_email = ? AND source_type = 'email' AND source_id = ?
                ");
                $restoredCount = 0;
                foreach ($richContent as $sourceId => $data) {
                    try {
                        $restoreStmt->execute([
                            $data['content_text'], 
                            $data['content_snippet'], 
                            $userEmail, 
                            $sourceId
                        ]);
                        if ($restoreStmt->rowCount() > 0) {
                            $restoredCount++;
                            // Also update Meilisearch if available.
                            // Skipped during a full rebuild ($deferMeiliWrites):
                            // the end-of-rebuild batch sync re-pushes these rows.
                            if ($this->meilisearch !== null && !$this->deferMeiliWrites) {
                                try {
                                    // Read full row to build complete Meilisearch document
                                    $rowStmt = $this->db->prepare("
                                        SELECT * FROM universal_search_index 
                                        WHERE user_email = ? AND source_type = 'email' AND source_id = ?
                                    ");
                                    $rowStmt->execute([$userEmail, $sourceId]);
                                    $fullRow = $rowStmt->fetch();
                                    if ($fullRow) {
                                        $document = MeilisearchService::buildDocument(
                                            $userEmail,
                                            'email',
                                            $sourceId,
                                            [
                                                'title' => $fullRow['title'],
                                                'content_text' => $fullRow['content_text'],
                                                'content_snippet' => $fullRow['content_snippet'],
                                                'folder_name' => $fullRow['folder_name'],
                                                'source_date' => $fullRow['source_date'],
                                                'extra_data' => $fullRow['extra_data'] ? json_decode($fullRow['extra_data'], true) : null,
                                            ]
                                        );
                                        $this->meilisearch->upsertDocument($document);
                                    }
                                } catch (\Exception $e) {
                                    // Non-critical: Meilisearch will be updated on next search
                                    error_log("Restore rich content Meilisearch sync error: " . $e->getMessage());
                                }
                            }
                        }
                    } catch (\PDOException $e) {
                        error_log("Restore rich content error for {$sourceId}: " . $e->getMessage());
                    }
                }
                if ($restoredCount > 0) {
                    error_log("SearchIndexerService: Restored {$restoredCount} rich email body entries during rebuild");
                }
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex emails error: " . $e->getMessage());
        }
        
        // Index email attachments - create entries for emails with attachments
        try {
            // Clear existing attachment entries
            $clearStmt = $this->db->prepare("DELETE FROM universal_search_index WHERE user_email = ? AND source_type = 'email_attachment'");
            $clearStmt->execute([$userEmail]);
            
            // First try the dedicated attachments cache table
            $tableExists = false;
            try {
                $this->db->query("SELECT 1 FROM webmail_email_attachments LIMIT 1");
                $tableExists = true;
            } catch (\PDOException $e) {
                // Table doesn't exist yet
            }
            
            if ($tableExists) {
                try {
                    $stmt = $this->db->prepare("
                        SELECT * FROM webmail_email_attachments 
                        WHERE user_email = ?
                        ORDER BY created_at DESC
                        LIMIT 5000
                    ");
                    $stmt->execute([$userEmail]);
                    $attachments = $stmt->fetchAll();
                    
                    foreach ($attachments as $att) {
                        if ($this->indexEmailAttachment($userEmail, $att)) {
                            $stats['attachments']++;
                        }
                    }
                } catch (\PDOException $e) {
                    // Table might have different schema, will fallback below
                    error_log("SearchIndexerService: webmail_email_attachments query failed: " . $e->getMessage());
                }
            }
            
            // If no attachments from cache, create entries from emails with has_attachment flag
            if ($stats['attachments'] === 0) {
                error_log("SearchIndexerService: Entering fallback attachment indexing for $userEmail");
                
                // Skip these folders
                $skipFolders = ['trash', 'deleted items', 'deleted', 'spam', 'junk', 'drafts'];
                
                // Check members table for has_attachment (backfilled via script)
                // OR fallback to conversations table
                $stmt = $this->db->prepare("
                    SELECT
                        m.uid,
                        fi.current_path AS folder,
                        m.subject,
                        m.from_email,
                        m.from_name,
                        m.message_date as date,
                        COALESCE(m.has_attachment, c.has_attachment, 0) as has_attachment
                    FROM webmail_conversation_members m
                    LEFT JOIN webmail_conversations c
                        ON c.user_email = m.user_email
                        AND c.conversation_id = m.conversation_id
                    LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
                    WHERE m.user_email = ?
                        AND (m.has_attachment = 1 OR c.has_attachment = 1)
                    ORDER BY m.message_date DESC
                    LIMIT 2000
                ");
                $stmt->execute([$userEmail]);
                $emailsWithAttachments = $stmt->fetchAll();
                
                error_log("SearchIndexerService: Found " . count($emailsWithAttachments) . " emails with attachments");
                
                foreach ($emailsWithAttachments as $email) {
                    // Skip trash, deleted items, spam, junk, drafts folders
                    $folderLower = strtolower($email['folder'] ?? '');
                    $shouldSkip = false;
                    foreach ($skipFolders as $skip) {
                        if (strpos($folderLower, $skip) !== false) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    if ($shouldSkip) continue;
                    
                    // Build sender string
                    $sender = $email['from_name'] ?: $email['from_email'];
                    if ($email['from_name'] && $email['from_email'] && $email['from_name'] !== $email['from_email']) {
                        $sender = $email['from_name'] . ' <' . $email['from_email'] . '>';
                    }
                    
                    // Create attachment entry for this email
                    $attData = [
                        'filename' => 'Attachment(s)',
                        'mime_type' => 'application/octet-stream',
                        'from_email' => $email['from_email'],
                        'from_name' => $email['from_name'],
                        'subject' => $email['subject'],
                        'folder' => $email['folder'],
                        'uid' => $email['uid'],
                        'message_date' => $email['date'],
                        'size' => 0,
                    ];
                    
                    $result = $this->indexEmailAttachment($userEmail, $attData);
                    if ($result) {
                        $stats['attachments']++;
                    } else {
                        error_log("SearchIndexerService: indexEmailAttachment FAILED for uid={$email['uid']}, folder={$email['folder']}");
                    }
                }
                error_log("SearchIndexerService: Attachment indexing complete. Total: {$stats['attachments']}");
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex attachments error: " . $e->getMessage());
        }
        
        // Index calendar events
        try {
            $stmt = $this->db->prepare("
                SELECT e.*, c.name as calendar_name, c.color as calendar_color
                FROM calendar_events e
                JOIN calendars c ON e.calendar_id = c.id
                WHERE c.user_email = ?
                ORDER BY e.start_time DESC
                LIMIT 500
            ");
            $stmt->execute([$userEmail]);
            $events = $stmt->fetchAll();
            
            foreach ($events as $event) {
                if ($this->indexCalendarEvent($userEmail, $event)) {
                    $stats['calendar_events']++;
                }
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex calendar_events error: " . $e->getMessage());
        }
        
        // Index boards and cards
        try {
            $stmt = $this->db->prepare("SELECT * FROM webmail_boards WHERE owner_email = ?");
            $stmt->execute([$userEmail]);
            $boards = $stmt->fetchAll();
            
            foreach ($boards as $board) {
                $this->indexBoard($userEmail, $board);
                $stats['boards']++;
                
                // Get cards for this board
                $cardStmt = $this->db->prepare("
                    SELECT c.*, l.name as list_name, l.id as list_id
                    FROM webmail_board_cards c
                    JOIN webmail_board_lists l ON c.list_id = l.id
                    WHERE l.board_id = ?
                ");
                $cardStmt->execute([$board['id']]);
                $cards = $cardStmt->fetchAll();
                
                foreach ($cards as $card) {
                    $this->indexCard($userEmail, $card, ['id' => $card['list_id'], 'name' => $card['list_name']], $board);
                    $stats['cards']++;
                }
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex boards error: " . $e->getMessage());
        }
        
        // Index todos
        try {
            $stmt = $this->db->prepare("SELECT * FROM webmail_todos WHERE email = ?");
            $stmt->execute([$userEmail]);
            $todos = $stmt->fetchAll();
            
            foreach ($todos as $todo) {
                $this->indexTodo($userEmail, $todo);
                $stats['todos']++;
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex todos error: " . $e->getMessage());
        }
        
        // Index drive files
        try {
            $stmt = $this->db->prepare("
                SELECT f.*, fo.name as folder_name, fo.client_id, fo.board_id
                FROM drive_files f
                LEFT JOIN drive_folders fo ON f.folder_id = fo.id
                WHERE f.user_email = ? AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
            ");
            $stmt->execute([$userEmail]);
            $files = $stmt->fetchAll();
            
            foreach ($files as $file) {
                // Per-file guard: one unreadable/corrupt file (e.g. a PhpWord
                // \Error on a malformed OOXML, or a per-row DB failure) must not
                // unwind the whole foreach and silently skip every later file.
                // Without this, a single bad doc near the start truncated the
                // rebuild (e.g. 413 of 472 files indexed).
                try {
                    $folder = $file['folder_id'] ? [
                        'id' => $file['folder_id'],
                        'name' => $file['folder_name'],
                        'client_id' => $file['client_id'],
                        'board_id' => $file['board_id'],
                    ] : null;
                    $this->indexDriveFile($userEmail, $file, $folder);
                    $stats['drive_files']++;
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'SearchIndexerService rebuildUserIndex drive file %s (%s) failed: %s',
                        $file['id'] ?? '?',
                        $file['original_name'] ?? $file['filename'] ?? '?',
                        $e->getMessage()
                    ));
                }
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex drive_files error: " . $e->getMessage());
        }
        
        // Index clients (with contacts)
        try {
            $stmt = $this->db->prepare("SELECT * FROM clients WHERE user_email = ?");
            $stmt->execute([$userEmail]);
            $clients = $stmt->fetchAll();
            
            // Fetch contacts for all clients
            $contactStmt = $this->db->prepare("SELECT * FROM client_contacts WHERE client_id = ?");
            
            foreach ($clients as $client) {
                // Get contacts for this client
                $contactStmt->execute([$client['id']]);
                $client['contacts'] = $contactStmt->fetchAll();
                
                $this->indexClient($userEmail, $client);
                $stats['clients']++;
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex clients error: " . $e->getMessage());
        }
        
        // Index chat messages
        try {
            // Clear existing chat entries
            $clearStmt = $this->db->prepare("DELETE FROM universal_search_index WHERE user_email = ? AND source_type = 'chat_message'");
            $clearStmt->execute([$userEmail]);
            
            // Find the user's colleague ID
            $colleagueStmt = $this->db->prepare("SELECT id FROM organization_colleagues WHERE email = ? LIMIT 1");
            $colleagueStmt->execute([$userEmail]);
            $colleague = $colleagueStmt->fetch();
            
            if ($colleague) {
                $colleagueId = (int)$colleague['id'];
                
                // Get all conversations this user participates in
                $convStmt = $this->db->prepare("
                    SELECT cp.conversation_id, c.type, c.name, c.topic, c.slug
                    FROM chat_participants cp
                    JOIN chat_conversations c ON cp.conversation_id = c.id
                    WHERE cp.colleague_id = ?
                ");
                $convStmt->execute([$colleagueId]);
                $conversations = $convStmt->fetchAll();
                
                foreach ($conversations as $conv) {
                    // Get messages (skip system messages, limit to recent 2000 per conversation)
                    $msgStmt = $this->db->prepare("
                        SELECT m.id, m.conversation_id, m.content, m.content_type, m.created_at,
                               oc.display_name as sender_name, oc.email as sender_email
                        FROM chat_messages m
                        JOIN organization_colleagues oc ON m.sender_id = oc.id
                        WHERE m.conversation_id = ?
                        AND m.deleted_at IS NULL
                        AND m.content_type IN ('text', 'file', 'image')
                        ORDER BY m.created_at DESC
                        LIMIT 2000
                    ");
                    $msgStmt->execute([$conv['conversation_id']]);
                    $messages = $msgStmt->fetchAll();
                    
                    foreach ($messages as $msg) {
                        if ($this->indexChatMessage($userEmail, $msg, $conv)) {
                            $stats['chat_messages']++;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex chat_messages error: " . $e->getMessage());
        }
        
        // Index mood board items
        try {
            // Clear existing mood board entries
            $clearStmt = $this->db->prepare("DELETE FROM universal_search_index WHERE user_email = ? AND source_type = 'mood_board_item'");
            $clearStmt->execute([$userEmail]);
            
            // Get all mood boards owned by or shared with this user
            $boardStmt = $this->db->prepare("
                SELECT DISTINCT mb.* FROM mood_boards mb
                LEFT JOIN mood_board_members mbm ON mb.id = mbm.board_id
                WHERE (mb.owner_email = ? OR mbm.email = ?)
                AND mb.archived = 0
            ");
            $boardStmt->execute([$userEmail, $userEmail]);
            $moodBoards = $boardStmt->fetchAll();
            
            foreach ($moodBoards as $mb) {
                // Get items on this board
                $itemStmt = $this->db->prepare("
                    SELECT * FROM mood_board_items WHERE board_id = ?
                ");
                $itemStmt->execute([$mb['id']]);
                $items = $itemStmt->fetchAll();
                
                // Prepare todo texts lookup for todo_list items
                $todoStmt = $this->db->prepare("
                    SELECT text FROM mood_board_todos WHERE item_id = ?
                ");
                
                foreach ($items as $item) {
                    $todoTexts = [];
                    if ($item['type'] === 'todo_list') {
                        $todoStmt->execute([$item['id']]);
                        $todoTexts = $todoStmt->fetchAll(\PDO::FETCH_COLUMN);
                    }
                    
                    if ($this->indexMoodBoardItem($userEmail, $item, $mb, $todoTexts)) {
                        $stats['mood_board_items']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService rebuildUserIndex mood_board_items error: " . $e->getMessage());
        }
        
        // MySQL (source of truth) is now fully rebuilt. Push everything to
        // Meilisearch in batched requests (a few HTTP calls total) rather than
        // the per-row writes we deferred above. Failures here are non-fatal:
        // the MySQL index is already valid and search falls back to it, so a
        // Meilisearch hiccup must not turn a successful rebuild into a 500.
        $this->deferMeiliWrites = false;
        if ($this->meilisearch !== null) {
            try {
                $meiliResult = $this->rebuildMeilisearchIndex($userEmail);
                if (empty($meiliResult['success'])) {
                    error_log(
                        "SearchIndexerService rebuildUserIndex Meilisearch sync failed: "
                        . ($meiliResult['error'] ?? 'unknown error')
                    );
                }
            } catch (\Throwable $e) {
                error_log("SearchIndexerService rebuildUserIndex Meilisearch sync error: " . $e->getMessage());
            }
        }
        
        return $stats;
    }

    /**
     * Re-index ONLY a user's drive files (no emails/attachments/IMAP).
     *
     * This is the fast, targeted backfill used by cron/reindex-drive.php. It
     * mirrors the drive leg of rebuildUserIndex() but:
     *   - skips the expensive email + IMAP-attachment rebuild entirely, and
     *   - guards every file individually so one corrupt doc cannot abort the
     *     batch, and
     *   - defers per-row Meilisearch writes, then pushes once via the batched
     *     rebuildMeilisearchIndex() at the end.
     *
     * @return array{drive_files:int, failed:int, total:int}
     */
    public function reindexUserDriveFiles(string $userEmail): array
    {
        @set_time_limit(0);
        $userEmail = strtolower($userEmail);
        $stats = ['drive_files' => 0, 'failed' => 0, 'total' => 0];

        // Defer per-row Meili writes; we flush once in a batch at the end.
        $this->deferMeiliWrites = true;
        try {
            $stmt = $this->db->prepare("
                SELECT f.*, fo.name as folder_name, fo.client_id, fo.board_id
                FROM drive_files f
                LEFT JOIN drive_folders fo ON f.folder_id = fo.id
                WHERE f.user_email = ? AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
            ");
            $stmt->execute([$userEmail]);
            $files = $stmt->fetchAll();
            $stats['total'] = count($files);

            foreach ($files as $file) {
                try {
                    $folder = $file['folder_id'] ? [
                        'id' => $file['folder_id'],
                        'name' => $file['folder_name'],
                        'client_id' => $file['client_id'],
                        'board_id' => $file['board_id'],
                    ] : null;
                    $this->indexDriveFile($userEmail, $file, $folder);
                    $stats['drive_files']++;
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    error_log(sprintf(
                        'SearchIndexerService reindexUserDriveFiles: file %s (%s) failed: %s',
                        $file['id'] ?? '?',
                        $file['original_name'] ?? $file['filename'] ?? '?',
                        $e->getMessage()
                    ));
                }
            }
        } catch (\Throwable $e) {
            error_log("SearchIndexerService reindexUserDriveFiles error: " . $e->getMessage());
        } finally {
            $this->deferMeiliWrites = false;
        }

        // Batch-push the now-correct MySQL rows to Meilisearch. Non-fatal:
        // MySQL is the source of truth and search falls back to it.
        if ($this->meilisearch !== null) {
            try {
                $this->rebuildMeilisearchIndex($userEmail);
            } catch (\Throwable $e) {
                error_log("SearchIndexerService reindexUserDriveFiles Meili sync error: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Get index statistics for a user
     * Returns stats with plural keys to match rebuild response format
     */
    public function getIndexStats(string $userEmail): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT source_type, COUNT(*) as count
                FROM universal_search_index
                WHERE user_email = ?
                GROUP BY source_type
            ");
            $stmt->execute([strtolower($userEmail)]);
            
            // Initialize with zeros
            $stats = [
                'emails' => 0,
                'attachments' => 0,
                'calendar_events' => 0,
                'drive_files' => 0,
                'boards' => 0,
                'cards' => 0,
                'todos' => 0,
                'clients' => 0,
                'chat_messages' => 0,
                'mood_board_items' => 0,
            ];
            
            // Map source_type to plural keys
            $keyMap = [
                'email' => 'emails',
                'email_attachment' => 'attachments',
                'calendar_event' => 'calendar_events',
                'drive_file' => 'drive_files',
                'drive_folder' => 'drive_files', // Count folders with files
                'board' => 'boards',
                'card' => 'cards',
                'todo' => 'todos',
                'client' => 'clients',
                'collab_doc' => 'drive_files', // Count docs with files
                'chat_message' => 'chat_messages',
                'mood_board_item' => 'mood_board_items',
            ];
            
            while ($row = $stmt->fetch()) {
                $key = $keyMap[$row['source_type']] ?? $row['source_type'];
                if (isset($stats[$key])) {
                    $stats[$key] += (int)$row['count'];
                }
            }
            
            return $stats;
        } catch (\PDOException $e) {
            return [];
        }
    }
    
    // =========================================================================
    // HELPER METHODS
    // =========================================================================
    
    /**
     * Extract body text from email message
     */
    private function extractEmailBody(array $message): string
    {
        // Try plain text first
        if (!empty($message['body_text'])) {
            return $message['body_text'];
        }
        
        // Fall back to HTML body, strip tags
        if (!empty($message['body_html'])) {
            return $this->stripHtmlToText($message['body_html']);
        }
        
        // Try 'body' field
        if (!empty($message['body'])) {
            if (strpos($message['body'], '<') !== false) {
                return $this->stripHtmlToText($message['body']);
            }
            return $message['body'];
        }
        
        return '';
    }
    
    /**
     * Strip HTML to plain text
     */
    private function stripHtmlToText(string $html): string
    {
        // Remove script and style contents
        // Using ?? to preserve value if preg_replace fails
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        
        // Convert common elements to newlines
        $html = preg_replace('/<(br|p|div|h[1-6]|li)[^>]*>/i', "\n", $html) ?? $html;
        
        // Strip remaining tags
        $text = strip_tags($html ?? '');
        
        // Decode entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n\s*\n/', "\n\n", $text) ?? $text;
        
        return trim($text ?? '');
    }
    
    /**
     * Extract recipients as string
     */
    private function extractRecipients($recipients): string
    {
        if (empty($recipients)) return '';
        
        if (is_string($recipients)) return $recipients;
        
        $names = [];
        foreach ($recipients as $r) {
            if (is_array($r)) {
                $names[] = $r['name'] ?? $r['email'] ?? '';
            } else {
                $names[] = $r;
            }
        }
        
        return implode(', ', array_filter($names));
    }
    
    /**
     * Find client by email address
     */
    private function findClientByEmail(string $userEmail, string $contactEmail): ?array
    {
        try {
            $contactEmail = strtolower(trim($contactEmail));
            $parts = explode('@', $contactEmail);
            if (count($parts) !== 2) return null;
            
            $domain = strtolower($parts[1]);
            
            // Generic email providers (gmail, yahoo, etc.) store full email as domain
            $genericDomains = ['gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 
                'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com',
                'aol.com', 'mail.com', 'protonmail.com', 'proton.me',
                'yandex.com', 'gmx.com', 'zoho.com'];
            
            $isGeneric = in_array($domain, $genericDomains);
            $clientIdentifier = $isGeneric ? $contactEmail : $domain;
            
            $stmt = $this->db->prepare("SELECT * FROM clients WHERE user_email = ? AND domain = ? LIMIT 1");
            $stmt->execute([strtolower($userEmail), $clientIdentifier]);
            $client = $stmt->fetch() ?: null;
            
            if ($client) return $client;
            
            // Check domain aliases (merged clients)
            try {
                $stmt = $this->db->prepare('
                    SELECT c.* FROM client_domain_aliases cda
                    JOIN clients c ON c.id = cda.client_id AND c.user_email = cda.user_email
                    WHERE cda.user_email = ? AND cda.alias_domain = ?
                    LIMIT 1
                ');
                $stmt->execute([strtolower($userEmail), $clientIdentifier]);
                return $stmt->fetch() ?: null;
            } catch (\PDOException $e) {
                // Table may not exist yet
                return null;
            }
        } catch (\PDOException $e) {
            return null;
        }
    }
    
    /**
     * Get drive file path - checks multiple storage locations (local + NAS)
     */
    private function getDriveFilePath(string $userEmail, array $file): ?string
    {
        $filename = $file['filename'] ?? null;
        if (!$filename) return null;
        
        $userPath = md5(strtolower($userEmail));
        $pathsToCheck = [];
        
        // 1. Check storage_location from DB if available (most accurate)
        $storageLocation = $file['storage_location'] ?? null;
        if ($storageLocation === 'nas') {
            $pathsToCheck[] = '/mnt/nas-drive/' . $userPath . '/' . $filename;
        } elseif ($storageLocation === 'local') {
            $localBase = $this->config['storage_path'] ?? __DIR__ . '/../../storage/drive';
            $pathsToCheck[] = $localBase . '/' . $userPath . '/' . $filename;
        }
        
        // 2. Check current storagePath (constructor-resolved)
        $pathsToCheck[] = $this->storagePath . '/' . $userPath . '/' . $filename;
        
        // 3. Check NAS explicitly
        $nasPath = '/mnt/nas-drive/' . $userPath . '/' . $filename;
        if (!in_array($nasPath, $pathsToCheck)) {
            $pathsToCheck[] = $nasPath;
        }
        
        // 4. Check local storage explicitly
        $localBase = $this->config['storage_path'] ?? __DIR__ . '/../../storage/drive';
        $localPath = $localBase . '/' . $userPath . '/' . $filename;
        if (!in_array($localPath, $pathsToCheck)) {
            $pathsToCheck[] = $localPath;
        }
        
        // Return first path that exists — skip NAS paths when mount is down
        $nasDown = !NasHealthCheck::isAvailable();
        foreach ($pathsToCheck as $path) {
            if ($nasDown && NasHealthCheck::isNasPath($path)) {
                continue;
            }
            if (file_exists($path)) {
                return $path;
            }
        }
        
        error_log("SearchIndexerService getDriveFilePath: file not found in any location for {$filename} (user: {$userEmail}, nasDown={$nasDown}). Checked: " . implode(', ', $pathsToCheck));
        return null;
    }
    
    /**
     * Extract text content from file based on mime type (with extension fallback)
     */
    private function extractFileContent(string $mimeType, string $filePath): string
    {
        if (!file_exists($filePath)) return '';
        
        // Resolve effective type: if mime is generic/ambiguous, detect by extension.
        // OnlyOffice saves go through DriveService::updateFileContent(), which
        // recomputes the mime via mime_content_type(); for OOXML files (docx,
        // xlsx, pptx are ZIP containers) that frequently yields a generic
        // 'application/zip' (or false -> ''), which would otherwise skip text
        // extraction and leave the search index with filename-only content.
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $effectiveMime = $mimeType;
        $genericMimes = [
            'application/octet-stream',
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
        ];
        if (in_array($mimeType, $genericMimes, true) || empty($mimeType)) {
            $extMap = [
                'pdf'  => 'application/pdf',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'doc'  => 'application/msword',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'xls'  => 'application/vnd.ms-excel',
                'xlsm' => 'application/vnd.ms-excel.sheet.macroenabled.12',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'ppt'  => 'application/vnd.ms-powerpoint',
                'txt'  => 'text/plain',
                'md'   => 'text/markdown',
                'csv'  => 'text/csv',
                'html' => 'text/html',
                'htm'  => 'text/html',
            ];
            if (isset($extMap[$ext])) {
                $effectiveMime = $extMap[$ext];
                error_log("SearchIndexerService extractFileContent: overrode mime '{$mimeType}' -> '{$effectiveMime}' based on extension .{$ext}");
            }
        }
        
        try {
            // Plain text files
            if (str_starts_with($effectiveMime, 'text/')) {
                $content = file_get_contents($filePath);
                // Limit size
                if (strlen($content) > 100000) {
                    $content = substr($content, 0, 100000);
                }
                return $content;
            }
            
            // DOCX files
            if ($effectiveMime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                || $effectiveMime === 'application/msword') {
                return $this->extractDocxText($filePath);
            }
            
            // Excel files (xlsx, xls, xlsm)
            if ($effectiveMime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                || $effectiveMime === 'application/vnd.ms-excel'
                || $effectiveMime === 'application/vnd.ms-excel.sheet.macroenabled.12') {
                return $this->extractSpreadsheetText($filePath);
            }
            
            // PowerPoint files (pptx, ppt)
            if ($effectiveMime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
                || $effectiveMime === 'application/vnd.ms-powerpoint') {
                return $this->extractPresentationText($filePath);
            }
            
            // PDF files
            if ($effectiveMime === 'application/pdf') {
                return $this->extractPdfText($filePath);
            }
            
            // Plain-text by extension fallback. Catches simple text files
            // (txt, md, csv, logs, config/markup) that arrived with an odd or
            // wrong mime that neither started with 'text/' nor matched the
            // generic-mime extension override above.
            $textExtensions = [
                'txt', 'md', 'markdown', 'text', 'log',
                'csv', 'tsv', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf',
            ];
            if (in_array($ext, $textExtensions, true)) {
                $content = file_get_contents($filePath);
                if (strlen($content) > 100000) {
                    $content = substr($content, 0, 100000);
                }
                return $content;
            }
            
        } catch (\Throwable $e) {
            // \Throwable (not just \Exception): PhpWord/PhpSpreadsheet can emit
            // \Error (e.g. TypeError) on malformed OOXML. Degrade to empty
            // (caller falls back to filename) instead of letting it escape and
            // abort a whole batch index.
            error_log("SearchIndexerService extractFileContent error: " . $e->getMessage());
        }
        
        return '';
    }
    
    /**
     * Extract text from DOCX file using PhpWord
     */
    private function extractDocxText(string $filePath): string
    {
        try {
            if (!class_exists('\PhpOffice\PhpWord\IOFactory')) {
                return '';
            }
            
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
            $text = '';
            
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->extractTextFromElement($element) . "\n";
                }
            }
            
            return trim($text);
        } catch (\Throwable $e) {
            // \Throwable: a malformed OnlyOffice docx can trigger a PhpWord
            // \Error, which would otherwise unwind the entire index loop.
            error_log("SearchIndexerService extractDocxText error: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Recursively extract text from PhpWord element.
     *
     * Handles three structural shapes that do not overlap per element type:
     *   - text-bearing runs expose getText()
     *   - containers (sections, paragraphs, cells) expose getElements()
     *   - Table exposes getRows(); Row exposes getCells()
     * Tables were previously dropped because neither getText() nor
     * getElements() reaches their cells, so table-only documents (e.g. pricing
     * tables) indexed as filename-only.
     */
    private function extractTextFromElement($element): string
    {
        $text = '';
        
        if (method_exists($element, 'getText')) {
            $value = $element->getText();
            if (is_string($value)) {
                $text .= $value;
            }
        }
        
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= ' ' . $this->extractTextFromElement($child);
            }
        }
        
        // Table -> rows
        if (method_exists($element, 'getRows')) {
            foreach ($element->getRows() as $row) {
                $text .= ' ' . $this->extractTextFromElement($row);
            }
        }
        
        // Row -> cells (each Cell exposes getElements(), handled above on recursion)
        if (method_exists($element, 'getCells')) {
            foreach ($element->getCells() as $cell) {
                $text .= ' ' . $this->extractTextFromElement($cell);
            }
        }
        
        return $text;
    }
    
    /**
     * Extract text from Excel/CSV files using PhpSpreadsheet.
     *
     * Walks every sheet, concatenates every non-empty cell value separated by
     * spaces with newlines between rows and sheet headers. Caps total output at
     * 100KB to keep the index document size sane.
     */
    private function extractSpreadsheetText(string $filePath): string
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            error_log("SearchIndexerService extractSpreadsheetText: PhpSpreadsheet not installed (composer require phpoffice/phpspreadsheet). File: {$filePath}");
            return '';
        }

        try {
            // Use read-only mode and skip cell formatting for speed
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);

            $maxChars = 100000; // 100KB cap
            $buffer = '';
            $truncated = false;

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                if ($truncated) break;

                $sheetTitle = $sheet->getTitle();
                $buffer .= "\n[Sheet: {$sheetTitle}]\n";

                $highestRow = $sheet->getHighestDataRow();
                $highestCol = $sheet->getHighestDataColumn();

                // Iterate row-by-row; rangeToArray is a lot faster than per-cell access
                $rows = $sheet->rangeToArray(
                    "A1:{$highestCol}{$highestRow}",
                    null,
                    false,
                    false,
                    false
                );

                foreach ($rows as $row) {
                    $cells = [];
                    foreach ($row as $value) {
                        if ($value === null || $value === '') continue;
                        // Stringify scalars / dates / formulas-as-values
                        if (is_scalar($value)) {
                            $cells[] = (string)$value;
                        }
                    }
                    if (!empty($cells)) {
                        $buffer .= implode(' ', $cells) . "\n";
                        if (strlen($buffer) > $maxChars) {
                            $buffer = substr($buffer, 0, $maxChars) . '...';
                            $truncated = true;
                            break;
                        }
                    }
                }
            }

            // Free memory immediately — spreadsheets are heavy
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            $len = strlen($buffer);
            if ($len === 0) {
                error_log("SearchIndexerService extractSpreadsheetText: empty result for {$filePath}");
            } else {
                error_log("SearchIndexerService extractSpreadsheetText: extracted {$len} chars from {$filePath}");
            }
            return trim($buffer);
        } catch (\Exception $e) {
            error_log("SearchIndexerService extractSpreadsheetText error for {$filePath}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Extract text from PowerPoint files using PhpPresentation.
     *
     * Walks each slide's shapes and pulls text from RichTextElement / Text
     * shapes. Caps output at 100KB.
     */
    private function extractPresentationText(string $filePath): string
    {
        if (!class_exists('\PhpOffice\PhpPresentation\IOFactory')) {
            error_log("SearchIndexerService extractPresentationText: PhpPresentation not installed. File: {$filePath}");
            return '';
        }

        try {
            // Try PowerPoint2007 (pptx) first, fall back to PowerPoint97 (ppt) and ODP
            $reader = null;
            foreach (['PowerPoint2007', 'PowerPoint97', 'ODPresentation'] as $readerName) {
                try {
                    $candidate = \PhpOffice\PhpPresentation\IOFactory::createReader($readerName);
                    if ($candidate->canRead($filePath)) {
                        $reader = $candidate;
                        break;
                    }
                } catch (\Exception $e) {
                    // Try next reader
                }
            }

            if ($reader === null) {
                error_log("SearchIndexerService extractPresentationText: no reader accepted {$filePath}");
                return '';
            }

            $presentation = $reader->load($filePath);

            $maxChars = 100000;
            $buffer = '';
            $slideNumber = 0;

            foreach ($presentation->getAllSlides() as $slide) {
                $slideNumber++;
                $buffer .= "\n[Slide {$slideNumber}]\n";

                foreach ($slide->getShapeCollection() as $shape) {
                    $text = $this->extractTextFromShape($shape);
                    if ($text !== '') {
                        $buffer .= $text . "\n";
                        if (strlen($buffer) > $maxChars) {
                            $buffer = substr($buffer, 0, $maxChars) . '...';
                            return trim($buffer);
                        }
                    }
                }
            }

            $len = strlen($buffer);
            if ($len === 0) {
                error_log("SearchIndexerService extractPresentationText: empty result for {$filePath}");
            } else {
                error_log("SearchIndexerService extractPresentationText: extracted {$len} chars from {$filePath} ({$slideNumber} slides)");
            }
            return trim($buffer);
        } catch (\Exception $e) {
            error_log("SearchIndexerService extractPresentationText error for {$filePath}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Recursively pull text out of a PhpPresentation shape (RichText / Group / Table).
     */
    private function extractTextFromShape($shape): string
    {
        $text = '';

        // RichText shapes: walk paragraphs -> rich text elements
        if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
            foreach ($shape->getParagraphs() as $paragraph) {
                foreach ($paragraph->getRichTextElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . ' ';
                    }
                }
                $text .= "\n";
            }
            return trim($text);
        }

        // Tables: walk rows and cells
        if ($shape instanceof \PhpOffice\PhpPresentation\Shape\Table) {
            foreach ($shape->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getParagraphs() as $paragraph) {
                        foreach ($paragraph->getRichTextElements() as $element) {
                            if (method_exists($element, 'getText')) {
                                $text .= $element->getText() . ' ';
                            }
                        }
                    }
                }
                $text .= "\n";
            }
            return trim($text);
        }

        // Group shapes: recurse
        if ($shape instanceof \PhpOffice\PhpPresentation\Shape\Group) {
            foreach ($shape->getShapeCollection() as $child) {
                $childText = $this->extractTextFromShape($child);
                if ($childText !== '') {
                    $text .= $childText . "\n";
                }
            }
            return trim($text);
        }

        return '';
    }

    /**
     * Extract text from PDF using pdftotext command
     */
    private function extractPdfText(string $filePath): string
    {
        // Check if pdftotext is available
        $check = shell_exec('which pdftotext 2>/dev/null');
        if (empty($check)) {
            error_log("SearchIndexerService extractPdfText: pdftotext NOT installed. Install with: apt install poppler-utils. File: {$filePath}");
            return '';
        }
        
        try {
            $output = shell_exec("pdftotext -q -enc UTF-8 " . escapeshellarg($filePath) . " - 2>/dev/null");
            $result = $output ?? '';
            $len = strlen($result);
            if ($len === 0) {
                error_log("SearchIndexerService extractPdfText: pdftotext returned empty for {$filePath} (file size: " . filesize($filePath) . " bytes)");
            } else {
                error_log("SearchIndexerService extractPdfText: extracted {$len} chars from {$filePath}");
            }
            return $result;
        } catch (\Exception $e) {
            error_log("SearchIndexerService extractPdfText error: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Create a snippet from text
     */
    private function createSnippet(string $text, int $maxLength = 500): string
    {
        $text = trim($text);
        
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        
        // Try to cut at word boundary
        $snippet = substr($text, 0, $maxLength);
        $lastSpace = strrpos($snippet, ' ');
        
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.8) {
            $snippet = substr($snippet, 0, $lastSpace);
        }
        
        return $snippet . '...';
    }
    
    /**
     * Sanitize string for database storage
     */
    private function sanitizeString(?string $text): ?string
    {
        if ($text === null) return null;
        
        // Remove invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Remove control characters except newlines and tabs
        // Using ?? to preserve value if preg_replace fails
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        
        return $text;
    }
}

