<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\LabelService;
use Webmail\Services\FilterService;
use Webmail\Services\RedisCacheService;
use Webmail\Services\ConversationService;
use Webmail\Services\ScheduledEmailService;
use Webmail\Services\CorrelationId;
use Webmail\Services\StructuredLog;
use Webmail\Services\CircuitBreaker;
use Webmail\Services\FolderStateMachine;
use Webmail\Services\FolderIndexService;
use Webmail\Services\FolderCacheInvalidator;
use Webmail\Services\DualWriteTelemetry;
use Webmail\Addons\AIAssistant\Services\AIService;

class MailboxController extends BaseController
{
    private ?RedisCacheService $redisCache = null;
    private ?ConversationService $conversationService = null;
    private ?FolderIndexService $folderIndexService = null;
    private ?DualWriteTelemetry $dualWriteTelemetry = null;
    
    /**
     * Get the Redis cache service (lazy initialization)
     */
    private function getRedisCache(): RedisCacheService
    {
        if ($this->redisCache === null) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }
    
    /**
     * Get the conversation service (lazy initialization)
     */
    private function getConversationService(): ConversationService
    {
        if ($this->conversationService === null) {
            $this->conversationService = new ConversationService($this->config);
        }
        return $this->conversationService;
    }

    /**
     * Get the FolderIndexService (lazy). Failure to construct (e.g. DB
     * unavailable for a brief moment) is logged but does NOT raise; the
     * dual-write contract is that legacy reads/writes still succeed.
     */
    private function getFolderIndexService(): ?FolderIndexService
    {
        if ($this->folderIndexService === null) {
            try {
                $this->folderIndexService = new FolderIndexService($this->config);
            } catch (\Throwable $e) {
                error_log('[MailboxController] FolderIndexService init failed: ' . $e->getMessage());
                return null;
            }
        }
        return $this->folderIndexService;
    }

    /**
     * Get the DualWriteTelemetry helper (lazy). Always non-null because
     * RedisCacheService itself silently no-ops when Redis is unavailable.
     */
    private function getDualWriteTelemetry(): DualWriteTelemetry
    {
        if ($this->dualWriteTelemetry === null) {
            $this->dualWriteTelemetry = new DualWriteTelemetry($this->getRedisCache());
        }
        return $this->dualWriteTelemetry;
    }

    /**
     * Ensure provider_type is fingerprinted (Redis-cached 7d) and
     * registered with StructuredLog so every log line emitted during
     * the rest of this request carries it. Safe to call repeatedly.
     */
    private function attachProviderContext(): void
    {
        if (empty($this->userEmail)) {
            return;
        }
        $svc = $this->getFolderIndexService();
        if ($svc === null) {
            return;
        }
        $cache = $this->getRedisCache();
        // Fast path: read from Redis/DB without an IMAP round trip. The
        // first request after a 7-day cache miss for this account pays
        // the fingerprint cost; everything else is free.
        $providerType = $svc->getProviderType($this->userEmail, $cache);

        if ($providerType === 'unknown'
            && $this->imap !== null
            && method_exists($this->imap, 'getRawConnection')) {
            try {
                $conn = $this->imap->getRawConnection();
                if ($conn) {
                    $providerType = $svc->ensureProviderFingerprint($conn, $this->userEmail, $cache);
                }
            } catch (\Throwable $e) {
                error_log('[MailboxController] provider fingerprint failed: ' . $e->getMessage());
            }
        }

        StructuredLog::setContext([
            'account_id'    => $this->userEmail,
            'provider_type' => $providerType,
        ]);
    }

    /**
     * Walk the freshly-listed folder array and upsert each one through
     * FolderIndexService so every row carries a stable folder_id. Returns
     * the augmented list. Best-effort: any per-folder failure is logged
     * and the row is left without a folder_id (legacy path keeps working).
     *
     * @param array $folders raw rows from ImapService::listFolders()
     * @return array same shape, with `folder_id` set where possible
     */
    private function annotateFoldersWithIdentity(array $folders): array
    {
        $svc = $this->getFolderIndexService();
        if ($svc === null || empty($this->userEmail)) {
            return $folders;
        }
        foreach ($folders as &$row) {
            try {
                $row['folder_id'] = $svc->upsertFromListing($this->userEmail, $row);
            } catch (\Throwable $e) {
                error_log('[MailboxController] folder identity upsert failed for '
                    . ($row['name'] ?? '?') . ': ' . $e->getMessage());
                if (!array_key_exists('folder_id', $row)) {
                    $row['folder_id'] = null;
                }
            }
        }
        unset($row);
        return $folders;
    }

    /**
     * Persist a folder snapshot row + enqueue an asynchronous rename
     * analysis job for this account. Best-effort:
     *
     *   - At-most-once-per-30s coalescing keyed in Redis so a chatty
     *     client (every poll, every reconnect) doesn't overflow the
     *     snapshots table.
     *   - SET-IF-NOT-EXISTS pending key with 1h TTL so the analyzer
     *     cron does ONE pass per account regardless of how many
     *     snapshots arrived in between.
     *   - Tolerates the snapshots table being absent (migration 164
     *     not yet applied) and Redis being down.
     */
    private function captureFolderSnapshot(array $folders): void
    {
        if (empty($this->userEmail) || empty($folders)) {
            return;
        }

        $cache = $this->getRedisCache();
        $coalesceKey = 'folder_snapshot:throttle:' . md5(strtolower($this->userEmail));
        $pendingKey  = 'folder_rename_analysis:pending:' . md5(strtolower($this->userEmail));

        // Coalesce: if we wrote a snapshot less than 30s ago, skip the
        // write but still extend the pending flag so the cron picks up
        // any analysis we might have skipped.
        if ($cache->isAvailable() && !$cache->setIfNotExists($coalesceKey, '1', 30)) {
            $cache->setIfNotExists($pendingKey, '1', 3600);
            return;
        }

        // Persist the snapshot.
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $payload = json_encode($folders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                return;
            }
            $stmt = $db->prepare(
                'INSERT INTO webmail_folder_snapshots
                    (account_id, snapshot, folder_count, captured_at, request_id)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)'
            );
            $stmt->execute([
                strtolower($this->userEmail),
                $payload,
                count($folders),
                CorrelationId::current(),
            ]);
        } catch (\Throwable $e) {
            // Snapshots table may not exist on a partial deploy; that is
            // tolerable -- the analyzer will simply find nothing to do.
            error_log('[MailboxController] folder snapshot write skipped: ' . $e->getMessage());
            return;
        }

        // Mark the account as having pending rename analysis. The
        // analyzer cron will pop the latest unconsumed snapshot.
        if ($cache->isAvailable()) {
            $cache->setIfNotExists($pendingKey, '1', 3600);
        }
    }

    /**
     * Combined initial load endpoint
     * GET /mailbox/init
     * 
     * Returns in single response:
     * - folders: Folder list with counts
     * - messages: INBOX messages (page 1)
     * - conversations: INBOX conversations
     * - syncVersion: Current sync version for WebSocket
     * 
     * This reduces initial load from 3+ API calls to 1.
     */
    public function init(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        // Register provider_type with StructuredLog so every line emitted
        // for the rest of this request carries it without per-call wiring.
        $this->attachProviderContext();

        $cache = $this->getRedisCache();
        $result = [
            'folders' => [],
            'messages' => [],
            'conversations' => [],
            'pagination' => null,
            'syncVersion' => 0,
        ];

        // 1. Get folders from IMAP (source of truth) and annotate each row
        // with its stable folder_id. Identity upsert is best-effort: legacy
        // (folder, uid) reads/writes still succeed if it fails. We also
        // capture a snapshot so the async rename analyzer has prior-state
        // data to diff against.
        try {
            $result['folders'] = $this->annotateFoldersWithIdentity(
                $this->imap->listFolders()
            );
            $this->captureFolderSnapshot($result['folders']);
        } catch (\Exception $e) {
            error_log("[MailboxController::init] Failed to get folders: " . $e->getMessage());
        }

        // 2. Get INBOX messages (page 1)
        try {
            $folder = 'INBOX';
            $page = 1;
            $limit = 50;
            
            $messagesResult = $this->imap->getMessages($folder, $page, $limit);
            
            if ($messagesResult) {
                $result['messages'] = $messagesResult['messages'] ?? [];
                $result['pagination'] = [
                    'page' => $page,
                    'pages' => $messagesResult['pages'] ?? 1,
                    'total' => $messagesResult['total'] ?? 0,
                    'limit' => $limit,
                ];
                
                // Auto-assign to conversations
                if (!empty($result['messages'])) {
                    try {
                        $conversationService = $this->getConversationService();
                        $conversationService->assignMessagesToConversations($this->userEmail, $folder, $result['messages']);
                        
                        // Mark folder as indexed
                        $maxUid = max(array_column($result['messages'], 'uid'));
                        $conversationService->markFolderIndexed($this->userEmail, $folder, $maxUid, $result['pagination']['total']);
                    } catch (\Exception $e) {
                        error_log("[MailboxController::init] Failed to assign conversations: " . $e->getMessage());
                    }

                    $this->enrichRepliedStatus($result['messages']);
                }
            }
        } catch (\Exception $e) {
            error_log("[MailboxController::init] Failed to get messages: " . $e->getMessage());
        }

        // 3. Get INBOX conversations
        try {
            $conversationService = $this->getConversationService();
            $result['conversations'] = $conversationService->getConversationsForFolder($this->userEmail, 'INBOX');
        } catch (\Exception $e) {
            error_log("[MailboxController::init] Failed to get conversations: " . $e->getMessage());
        }

        // 4. Get current sync version (for WebSocket reconnection)
        try {
            if ($cache->isAvailable()) {
                $versionKey = 'sync:version:' . $this->userEmail;
                $version = $cache->get($versionKey);
                $result['syncVersion'] = $version ? (int)$version : 0;
            }
        } catch (\Exception $e) {
            error_log("[MailboxController::init] Failed to get sync version: " . $e->getMessage());
        }

        // 5. Pinned emails (DB query). Returns folder_id (canonical) plus
        // the current path string via JOIN to the identity table for
        // display. The path is denormalised at read time so the frontend
        // never needs to do a second round-trip to render the pinned list.
        try {
            $db = $this->getPinnedDb();
            $stmt = $db->prepare(
                "SELECT pe.folder_id, fi.current_path AS folder, pe.uid,
                        pe.message_id, pe.subject, pe.pinned_at
                 FROM pinned_emails pe
                 LEFT JOIN webmail_folder_identity fi ON fi.id = pe.folder_id
                 WHERE pe.user_email = ?
                 ORDER BY pe.pinned_at DESC"
            );
            $stmt->execute([$this->userEmail]);
            $result['pinned'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("[MailboxController::init] Failed to get pinned emails: " . $e->getMessage());
            $result['pinned'] = [];
        }

        // 6. Scheduled email count
        try {
            $schedService = new ScheduledEmailService($this->config);
            $scheduled = $schedService->getScheduled($this->primaryUserEmail ?? $this->userEmail);
            $result['scheduled_count'] = count($scheduled);
        } catch (\Exception $e) {
            error_log("[MailboxController::init] Failed to get scheduled count: " . $e->getMessage());
            $result['scheduled_count'] = 0;
        }

        // 7. AI config (file-based settings + static model lists)
        try {
            $hash = md5(strtolower($this->userEmail));
            $aiFile = '/var/www/vps-email/data/global/ai_' . $hash . '.json';
            $aiSettings = [];
            if (file_exists($aiFile)) {
                $content = file_get_contents($aiFile);
                $parsed = json_decode($content, true);
                if (is_array($parsed)) $aiSettings = $parsed;
            }
            $result['ai_config'] = [
                'configured' => !empty($aiSettings['ai_api_key_encrypted']),
                'model' => $aiSettings['ai_model'] ?? 'gpt-5-nano',
                'style' => $aiSettings['ai_writing_style'] ?? 'professional',
                'models' => AIService::getModels(),
                'styles' => AIService::getWritingStyles(),
                'default_prompts' => AIService::getDefaultPrompts(),
            ];
        } catch (\Exception $e) {
            error_log("[MailboxController::init] Failed to get AI config: " . $e->getMessage());
            $result['ai_config'] = null;
        }

        return Response::success($result);
    }

    /**
     * Get list of folders
     * GET /mailbox/folders
     * 
     * Query params:
     * - skip_cache: If '1', bypass Redis cache
     */
    public function folders(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $this->attachProviderContext();

        // Get folders directly from IMAP (source of truth) and assign /
        // refresh canonical folder_id on each row. This is the single point
        // where folder identity is upserted on every request, so a freshly
        // discovered folder gets a UUIDv7 within one round trip. We also
        // capture a snapshot for the async rename analyzer to diff against.
        $folders = $this->annotateFoldersWithIdentity(
            $this->imap->listFolders()
        );
        $this->captureFolderSnapshot($folders);

        return Response::success([
            'folders' => $folders,
        ]);
    }

    /**
     * Get messages in a folder
     * GET /mailbox/{folder}/messages
     */
    public function messages(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            error_log("MailboxController::messages - IMAP connection failed");
            return $imapError;
        }

        // Wave 2 P2: accept both legacy /mailbox/{folder}/... and canonical
        // /folders/{folder_id}/... URL shapes. Telemetry counts which form
        // was used so the cutover gate can verify migration progress.
        $folder = $this->getResolvedFolder($request, 'messages_list');
        if ($folder === null) {
            return Response::error('Folder not found', 404);
        }
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        
        $page = (int)$request->getQuery('page', 1);
        $limit = (int)$request->getQuery('limit', 50);
        $sortBy = $request->getQuery('sort_by', 'date');
        $sortOrder = $request->getQuery('sort_order', 'desc');
        $clientUidvalidity = (int)$request->getQuery('client_uidvalidity', 0);

        // Limit to reasonable values
        $limit = max(10, min(100, $limit));
        $page = max(1, $page);

        error_log("MailboxController::messages - folder: $folder, page: $page, limit: $limit");
        
        // Check UIDVALIDITY if client provided one - CRITICAL for cache consistency
        if ($clientUidvalidity > 0) {
            try {
                $status = $this->imap->getFolderStatus($folder);
                $serverUidvalidity = $status['uidvalidity'] ?? 0;
                
                if ($serverUidvalidity > 0 && $serverUidvalidity !== $clientUidvalidity) {
                    // UIDVALIDITY changed - folder was rebuilt, nuke all stale data
                    $conversationService = $this->getConversationService();
                    $conversationService->invalidateFolderIndex($this->userEmail, $folder);
                    error_log("[MailboxController::messages] UIDVALIDITY changed for {$folder}: {$clientUidvalidity} -> {$serverUidvalidity} - cleared database");
                }
            } catch (\Exception $e) {
                error_log("[MailboxController::messages] UIDVALIDITY check failed: " . $e->getMessage());
            }
        }
        
        $result = $this->imap->getMessages($folder, $page, $limit, $sortBy, $sortOrder);
        
        // If result is empty, try case-insensitive folder match
        if (empty($result['messages']) && $result['total'] === 0) {
            $actualFolder = $this->findActualFolderName($folder);
            if ($actualFolder && $actualFolder !== $folder) {
                error_log("MailboxController::messages - Folder case mismatch: '$folder' -> '$actualFolder'");
                $result = $this->imap->getMessages($actualFolder, $page, $limit, $sortBy, $sortOrder);
                $folder = $actualFolder; // Use corrected folder name
            }
        }
        
        error_log("MailboxController::messages - result total: " . ($result['total'] ?? 'null') . ", messages count: " . count($result['messages'] ?? []));

        // Attach labels to messages using message_id
        try {
            $labelService = new LabelService($this->config);
            $messageIds = array_filter(array_column($result['messages'], 'message_id'));
            if (!empty($messageIds)) {
                $labelsMap = $labelService->getMessageLabelsForList($this->userEmail, $messageIds);
                foreach ($result['messages'] as &$message) {
                    $message['labels'] = $labelsMap[$message['message_id'] ?? ''] ?? [];
                }
            }
        } catch (\Exception $e) {
            // Labels are optional, don't fail if DB is unavailable
        }
        
        // AUTO-ASSIGN: Add messages to conversations database and return conversations with response
        // This enables instant conversation loading without a separate API call
        $conversations = [];
        if (!empty($result['messages'])) {
            try {
                $conversationService = $this->getConversationService();
                $conversationService->assignMessagesToConversations($this->userEmail, $folder, $result['messages']);
                
                // Get max UID and mark folder as indexed if this is page 1
                if ($page === 1 && !empty($result['messages'])) {
                    $maxUid = max(array_column($result['messages'], 'uid'));
                    $conversationService->markFolderIndexed($this->userEmail, $folder, $maxUid, $result['total']);
                }
                
                // Get conversations for this folder to include in response
                $conversations = $conversationService->getConversationsForFolder($this->userEmail, $folder);
            } catch (\Exception $e) {
                // Conversations are optional, don't fail if DB is unavailable
                error_log("MailboxController: Auto-assign conversations failed: " . $e->getMessage());
            }
        }

        // Include conversations in response for single-request folder loading
        $result['conversations'] = $conversations;

        // Enrich replied status from conversation DB (covers messages where
        // \Answered IMAP flag was never set by FlowOne)
        $this->enrichRepliedStatus($result['messages']);

        // Evaluate Board Pro email rules against messages on this page
        if (!empty($result['messages'])) {
            try {
                $emailRuleService = new \Webmail\Addons\BoardPro\Services\BoardProEmailService($this->config);
                $activeRules = $emailRuleService->getActiveRulesForUser($this->userEmail);
                if (!empty($activeRules)) {
                    error_log("[EmailRules::DEBUG] messages() - Found " . count($activeRules) . " active rules, checking " . count($result['messages']) . " messages in $folder");
                    foreach ($result['messages'] as $msg) {
                        $fromEmail = $msg['from_email'] ?? '';
                        $fromName = $msg['from_name'] ?? '';
                        if (empty($fromEmail) && is_array($msg['from'] ?? null) && !empty($msg['from'])) {
                            $fromEmail = $msg['from'][0]['email'] ?? '';
                            $fromName = $msg['from'][0]['name'] ?? $fromName;
                        }

                        $emailRuleService->evaluateEmailAgainstRules([
                            'uid' => $msg['uid'] ?? null,
                            'folder' => $folder,
                            'subject' => $msg['subject'] ?? '',
                            'from' => $fromEmail,
                            'from_name' => $fromName,
                            'date' => $msg['date'] ?? null,
                            'snippet' => $msg['snippet'] ?? '',
                        ], $this->userEmail, $this->imap);
                    }
                }
            } catch (\Exception $e) {
                error_log("[EmailRules::DEBUG] messages() rule evaluation error: " . $e->getMessage());
            }
        }
        
        // Include folder status for UIDVALIDITY tracking
        try {
            $status = $this->imap->getFolderStatus($folder);
            if ($status) {
                $result['folderStatus'] = [
                    'uidvalidity' => $status['uidvalidity'] ?? 0,
                    'uidnext' => $status['uidnext'] ?? 0,
                    'total' => $status['messages'] ?? 0,
                    'unread' => $status['unseen'] ?? 0,
                ];
            }
        } catch (\Exception $e) {
            // Non-critical
        }
        
        return Response::success($result);
    }
    
    /**
     * Get messages since a given UID (for incremental sync)
     * GET /mailbox/{folder}/messages/since?uid_gt={last_uid}
     */
    public function messagesSince(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'messages_since');
        if ($folder === null) return Response::error('Folder not found', 404);
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        
        $sinceUid = (int)$request->getQuery('uid_gt', 0);
        $limit = (int)$request->getQuery('limit', 100);
        
        // Limit to reasonable values
        $limit = max(10, min(500, $limit));
        
        $result = $this->imap->getMessagesSince($folder, $sinceUid, $limit);
        
        // Attach labels to messages
        if (!empty($result['messages'])) {
            try {
                $labelService = new LabelService($this->config);
                $messageIds = array_filter(array_column($result['messages'], 'message_id'));
                if (!empty($messageIds)) {
                    $labelsMap = $labelService->getMessageLabelsForList($this->userEmail, $messageIds);
                    foreach ($result['messages'] as &$message) {
                        $message['labels'] = $labelsMap[$message['message_id'] ?? ''] ?? [];
                    }
                }
            } catch (\Exception $e) {
                // Labels are optional
            }
            
            // AUTO-ASSIGN: Add new messages to conversations database
            try {
                $conversationService = $this->getConversationService();
                $conversationService->assignMessagesToConversations($this->userEmail, $folder, $result['messages']);
                
                // Update last indexed UID
                $maxUid = max(array_column($result['messages'], 'uid'));
                $conversationService->updateLastIndexedUid($this->userEmail, $folder, $maxUid, count($result['messages']));
            } catch (\Exception $e) {
                error_log("MailboxController: Auto-assign conversations (since) failed: " . $e->getMessage());
            }

            $this->enrichRepliedStatus($result['messages']);
        }

        return Response::success($result);
    }

    /**
     * Get delta changes for a folder
     * GET /mailbox/{folder}/delta
     * 
     * Efficient sync endpoint that returns only changes since last sync:
     * - New messages (UID > since_uid)
     * - Folder counts
     * - UIDVALIDITY for cache invalidation
     * 
     * Query params:
     * - since_uid: Get messages with UID greater than this
     * - since_uidvalidity: Client's cached UIDVALIDITY (for detecting folder rebuild)
     * - include_counts: If '1', include folder counts
     */
    public function delta(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'delta');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        
        $sinceUid = (int)$request->getQuery('since_uid', 0);
        $clientUidvalidity = (int)$request->getQuery('since_uidvalidity', 0);
        $sinceModseq = (int)$request->getQuery('since_modseq', 0);
        $includeCounts = $request->getQuery('include_counts', '1') === '1';
        $limit = (int)$request->getQuery('limit', 100);
        
        $limit = max(10, min(500, $limit));
        
        $result = [
            'folder' => $folder,
            'newMessages' => [],
            'deletedUids' => [],
            'flagChanges' => [],
            'counts' => null,
            'uidvalidity' => 0,
            'uidnext' => 0,
            'highest_modseq' => 0,
            'uidvalidityChanged' => false,
            'syncRequired' => false,
        ];
        
        // Get folder status (SELECT with CONDSTORE populates sync state)
        try {
            $status = $this->imap->getFolderStatus($folder);
            $syncState = $this->imap->getFolderSyncState();
            if ($status) {
                $result['uidvalidity'] = $syncState['uidvalidity'] ?: ($status['uidvalidity'] ?? 0);
                $result['uidnext'] = $syncState['uidnext'] ?: ($status['uidnext'] ?? 0);
                $result['highest_modseq'] = $syncState['highest_modseq'] ?? 0;
                
                // Check if UIDVALIDITY changed (folder was rebuilt)
                if ($clientUidvalidity > 0 && $result['uidvalidity'] !== $clientUidvalidity) {
                    // CRITICAL: Nuke all stale data for this folder
                    try {
                        $conversationService = $this->getConversationService();
                        $conversationService->invalidateFolderIndex($this->userEmail, $folder);
                        error_log("[MailboxController::delta] UIDVALIDITY changed for {$folder}: {$clientUidvalidity} -> {$result['uidvalidity']} - cleared database");
                    } catch (\Exception $e) {
                        error_log("[MailboxController::delta] Failed to invalidate folder index: " . $e->getMessage());
                    }
                    
                    $result['uidvalidityChanged'] = true;
                    $result['syncRequired'] = true;
                    // Return early - client needs to do full refresh
                    return Response::success($result);
                }
                
                if ($includeCounts) {
                    $result['counts'] = [
                        'total' => $status['messages'] ?? 0,
                        'unread' => $status['unseen'] ?? 0,
                        'recent' => $status['recent'] ?? 0,
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("[MailboxController::delta] Failed to get folder status: " . $e->getMessage());
        }
        
        // Get new messages since the given UID
        if ($sinceUid > 0 && $result['uidnext'] > $sinceUid) {
            try {
                $messagesResult = $this->imap->getMessagesSince($folder, $sinceUid, $limit);
                
                if (!empty($messagesResult['messages'])) {
                    $result['newMessages'] = $messagesResult['messages'];
                    
                    // Attach labels
                    try {
                        $labelService = new LabelService($this->config);
                        $messageIds = array_filter(array_column($result['newMessages'], 'message_id'));
                        if (!empty($messageIds)) {
                            $labelsMap = $labelService->getMessageLabelsForList($this->userEmail, $messageIds);
                            foreach ($result['newMessages'] as &$message) {
                                $message['labels'] = $labelsMap[$message['message_id'] ?? ''] ?? [];
                            }
                        }
                    } catch (\Exception $e) {
                        // Labels optional
                    }
                    
                    // Auto-assign to conversations
                    try {
                        $conversationService = $this->getConversationService();
                        $conversationService->assignMessagesToConversations($this->userEmail, $folder, $result['newMessages']);
                    } catch (\Exception $e) {
                        // Conversations optional
                    }

                    // Evaluate Board Pro email auto-link rules
                    try {
                        error_log("[EmailRules::DEBUG] Delta has " . count($result['newMessages']) . " new messages, folder=$folder, user={$this->userEmail}");
                        $emailRuleService = new \Webmail\Addons\BoardPro\Services\BoardProEmailService($this->config);
                        foreach ($result['newMessages'] as $msg) {
                            $fromEmail = $msg['from_email'] ?? '';
                            $fromName = $msg['from_name'] ?? '';
                            if (empty($fromEmail) && is_array($msg['from'] ?? null) && !empty($msg['from'])) {
                                $fromEmail = $msg['from'][0]['email'] ?? '';
                                $fromName = $msg['from'][0]['name'] ?? $fromName;
                            }

                            $emailPayload = [
                                'uid' => $msg['uid'] ?? null,
                                'folder' => $folder,
                                'subject' => $msg['subject'] ?? '',
                                'from' => $fromEmail,
                                'from_name' => $fromName,
                                'date' => $msg['date'] ?? null,
                                'snippet' => $msg['snippet'] ?? '',
                                'thread_id' => $msg['thread_id'] ?? null,
                                'message_id' => $msg['message_id'] ?? null,
                            ];
                            error_log("[EmailRules::DEBUG] Processing msg uid={$emailPayload['uid']}, subject=\"{$emailPayload['subject']}\", from={$emailPayload['from']}");

                            $ruleResults = $emailRuleService->evaluateEmailAgainstRules($emailPayload, $this->userEmail, $this->imap);
                            error_log("[EmailRules::DEBUG] Rule results for uid={$emailPayload['uid']}: " . json_encode($ruleResults));
                        }
                    } catch (\Exception $e) {
                        error_log("[EmailRules::DEBUG] EXCEPTION: " . $e->getMessage() . " | trace: " . $e->getTraceAsString());
                    }
                }
            } catch (\Exception $e) {
                error_log("[MailboxController::delta] Failed to get new messages: " . $e->getMessage());
            }
        }
        
        // Fetch flag changes via CONDSTORE if client provides modseq
        if ($sinceModseq > 0 && $result['highest_modseq'] > $sinceModseq) {
            try {
                $flagResult = $this->imap->fetchFlagChangesSince($folder, $sinceModseq, $sinceUid ?: 0);
                $result['flagChanges'] = $flagResult['changes'];
                if ($flagResult['highest_modseq'] > $result['highest_modseq']) {
                    $result['highest_modseq'] = $flagResult['highest_modseq'];
                }
            } catch (\Exception $e) {
                error_log("[MailboxController::delta] Failed to get flag changes: " . $e->getMessage());
            }
        }
        
        return Response::success($result);
    }
    
    /**
     * Get folder sync state from CONDSTORE SELECT.
     * GET /mailbox/{folder}/sync-state
     *
     * Returns uidvalidity, uidnext, highest_modseq, exists.
     * Used by clients to determine what sync operations are needed.
     */
    public function syncState(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }
        
        $folder = $this->getResolvedFolder($request, 'sync_state');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        
        // SELECT folder triggers CONDSTORE, populates sync state
        if (!$this->imap->selectFolder($folder)) {
            return Response::error('Failed to select folder', 500);
        }
        
        $state = $this->imap->getFolderSyncState();
        $state['folder'] = $folder;
        
        return Response::success($state);
    }
    
    /**
     * Get flag changes since a given MODSEQ (CONDSTORE).
     * GET /mailbox/{folder}/flag-changes?since_modseq=N&max_uid=M
     *
     * Returns only messages whose flags changed after the given modseq.
     * This makes flag sync O(changes) instead of O(mailbox_size).
     */
    public function flagChanges(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }
        
        $folder = $this->getResolvedFolder($request, 'flag_changes');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        
        $sinceModseq = (int)$request->getQuery('since_modseq', 0);
        $maxUid = (int)$request->getQuery('max_uid', 0);
        
        if ($sinceModseq <= 0) {
            return Response::error('since_modseq parameter is required', 400);
        }
        
        $result = $this->imap->fetchFlagChangesSince($folder, $sinceModseq, $maxUid);
        
        // Also return current sync state so client can update its stored values
        $syncState = $this->imap->getFolderSyncState();
        
        return Response::success([
            'folder' => $folder,
            'changes' => $result['changes'],
            'highest_modseq' => $result['highest_modseq'],
            'sync_state' => $syncState,
        ]);
    }
    
    /**
     * Get status for multiple folders at once (efficient polling)
     * POST /mailbox/folders/status
     * Body: { folders: ["INBOX", "INBOX.Sent", ...], skip_cache: bool }
     * 
     * Uses Redis cache for folder status with short TTL (2 min)
     */
    public function foldersStatus(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folders = $request->input('folders', []);
        
        if (empty($folders) || !is_array($folders)) {
            return Response::error('Folders array is required', 400);
        }
        
        // Limit number of folders to check
        $folders = array_slice($folders, 0, 50);
        
        // Get folder status directly from IMAP (source of truth)
        $result = $this->imap->getMultiFolderStatus($folders);

        return Response::success([
            'folders' => $result
        ]);
    }

    /**
     * Lightweight read of the per-account folder_identity_version.
     * GET /mailbox/folders/identity-version
     *
     * Used by the frontend on WebSocket reconnect to detect rename/move/delete
     * events that fired while the tab was offline (or that were dropped by an
     * intermediate proxy). Returns Redis-only data so it has zero IMAP cost
     * and is safe to call frequently.
     *
     * Response: { folder_identity_version: <int> }
     */
    public function foldersIdentityVersion(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $version = 0;
        try {
            $cacheSvc = $this->getRedisCache();
            $telem = new \Webmail\Services\DualWriteTelemetry($cacheSvc);
            $version = $telem->getFolderIdentityVersion($this->getActiveEmail());
        } catch (\Throwable $e) {
            // Treat as 0 (= "unknown"). The client falls back to full
            // invalidate-on-reconnect when it sees 0, so the response is
            // always actionable.
            error_log('[MailboxController::foldersIdentityVersion] ' . $e->getMessage());
        }

        return Response::success(['folder_identity_version' => $version]);
    }

    /**
     * Get a single message
     * GET /mailbox/{folder}/messages/{uid}
     */
    public function message(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        // Wave 2 P2 dual-routing: see comment in messages().
        $folder = $this->getResolvedFolder($request, 'message_get');
        $uid = (int)$request->getParam('uid');
        if ($folder === null) {
            return Response::error('Folder not found', 404);
        }
        // Normalize folder name (handle case mismatches like 'inbox.work' -> 'INBOX.work')
        $folder = $this->normalizeFolderName($folder);
        
        // Fetch from IMAP (source of truth)
        $message = $this->imap->getMessage($folder, $uid);

        // If message not found, try to find the folder with case-insensitive match
        if (!$message) {
            $actualFolder = $this->findActualFolderName($folder);
            if ($actualFolder && $actualFolder !== $folder) {
                error_log("MailboxController::message - Folder case mismatch: '$folder' -> '$actualFolder'");
                $message = $this->imap->getMessage($actualFolder, $uid);
                if ($message) {
                    $folder = $actualFolder; // Use the correct folder name for caching
                }
            }
        }

        if (!$message) {
            error_log("MailboxController::message - Message not found: folder='$folder', uid=$uid");
            return Response::notFound('Message not found');
        }

        // Sanitize HTML body
        $sanitizer = new \Webmail\Services\HtmlSanitizer();
        
        // First check if body_html has content
        if (!empty($message['body_html']) && strlen(trim($message['body_html'])) > 10) {
            $message['body_html'] = $sanitizer->sanitize($message['body_html']);
        } elseif (!empty($message['body_text'])) {
            $message['body_html'] = $sanitizer->textToHtml($message['body_text']);
        } elseif (!empty($message['body_calendar'])) {
            $message['body_html'] = $this->renderCalendarInvite($message['body_calendar']);
        } else {
            $message['body_html'] = '<p style="color: #666; font-style: italic;">No message content</p>';
        }

        // If there's calendar data alongside HTML body, append it as structured data
        if (!empty($message['body_calendar'])) {
            $message['calendar_event'] = $this->parseCalendarEvent($message['body_calendar']);
        }

        // Strip our own tracking pixels from the HTML
        // This prevents self-reads when viewing Sent folder copies that still contain tracking pixels
        if (!empty($message['body_html'])) {
            $message['body_html'] = preg_replace(
                '/<img[^>]*src=["\'][^"\']*\/api\/track\/[a-f0-9]+\.gif["\'][^>]*\/?>/i',
                '',
                $message['body_html']
            );
        }

        // Get labels using message_id
        try {
            if (!empty($message['message_id'])) {
                $labelService = new LabelService($this->config);
                $message['labels'] = $labelService->getMessageLabels($this->userEmail, $message['message_id']);
            } else {
                $message['labels'] = [];
            }
        } catch (\Exception $e) {
            $message['labels'] = [];
        }

        // Auto-mark as read only if not already read
        if (empty($message['seen'])) {
            try {
                $this->imap->setFlag($folder, $uid, 'seen', true);
                $cache = $this->getRedisCache();
                $cache->invalidateMessage($this->userEmail, $folder, $uid);
                $cache->publishFlagsChanged($this->userEmail, $folder, $uid, [
                    'flag' => 'seen', 'value' => true, 'imapFlags' => ['\\Seen'],
                ]);
                $status = $this->imap->getFolderStatus($folder);
                if ($status) {
                    $cache->publishFolderCounts($this->userEmail, $folder, $status['messages'] ?? 0, $status['unseen'] ?? 0, $status['uidnext'] ?? null, $status['uidvalidity'] ?? null);
                }
                // Keep webmail_conversations.unread_count in sync. Without this,
                // the conversation-level unread badge stays stale until the next
                // full conversation rebuild (the explicit /flag endpoint already
                // does this; the body-fetch auto-mark path historically did not).
                try {
                    $this->getConversationService()->updateMemberReadStatus($this->userEmail, $folder, $uid, true);
                } catch (\Exception $convEx) {
                    error_log("[MailboxController::message] updateMemberReadStatus failed: " . $convEx->getMessage());
                }
            } catch (\Exception $e) {
                error_log("[MailboxController::message] Auto-mark-read failed: " . $e->getMessage());
            }
        }
        
        // Index email body for search (non-blocking)
        try {
            $indexer = new \Webmail\Services\SearchIndexerService($this->config);
            $indexer->indexEmail($this->userEmail, $message, $folder);
        } catch (\Exception $e) {
            // Don't fail if search indexing fails
            error_log("MailboxController: Search index update failed: " . $e->getMessage());
        }

        // Phase 3: parse the inbound body for @mentions and persist them so
        // `mentions:me` returns this message. Triggers a per-user dedup'd
        // in-app notification if the active user was actually @-mentioned
        // and they have `notify_on_mention` ON (default).
        //
        // Hook here (after the index update) so we get the same try/catch
        // safety the index path enjoys — a mention parse blow-up never
        // breaks message rendering. The recipient list pulled off the
        // message gives the parser enough hint context to resolve bare
        // `@firstname` tokens.
        try {
            // ImapService::getMessage returns from/to/cc/bcc as a list of
            // {name, email} maps (see ImapService::formatAddressList). The
            // earlier `address` / `from_email` lookup was a dead path that
            // always produced empty $senderEmail and broke trust resolution.
            $senderEmail = '';
            if (!empty($message['from']) && is_array($message['from'])) {
                $first = $message['from'][0] ?? null;
                if (is_array($first)) {
                    $senderEmail = (string) ($first['email'] ?? $first['address'] ?? '');
                } elseif (is_string($first)) {
                    $senderEmail = $first;
                }
            } elseif (is_string($message['from'] ?? null)) {
                $senderEmail = (string) $message['from'];
            }
            $recipients = [];
            foreach (['to', 'cc', 'bcc'] as $field) {
                $list = $message[$field] ?? [];
                if (is_string($list)) $list = [$list];
                foreach ((array) $list as $r) {
                    $em = is_array($r) ? ($r['email'] ?? $r['address'] ?? '') : (string) $r;
                    if ($em !== '') $recipients[] = $em;
                }
            }
            $processor = new \Webmail\Services\Mentions\MentionsProcessor($this->config);
            $processor->process(
                $this->userEmail,
                [
                    'message_id'   => (string) ($message['message_id'] ?? ''),
                    'direction'    => 'inbound',
                    'sender_email' => $senderEmail,
                    'subject'      => (string) ($message['subject'] ?? ''),
                    'sent_at'      => (string) ($message['date'] ?? ''),
                    'folder'       => $folder,
                    'uid'          => isset($message['uid']) ? (int) $message['uid'] : null,
                    'recipients'   => $recipients,
                ],
                (string) ($message['body_html'] ?? ''),
                (string) ($message['body_text'] ?? $message['body_plain'] ?? '')
            );
        } catch (\Throwable $e) {
            error_log('[MailboxController] Mention processing (inbound) failed: ' . $e->getMessage());
        }
        
        // Update client activity for received emails (inbound)
        // Only for inbox-like folders (not Sent, Drafts, etc.)
        $folderLower = strtolower($folder);
        $isInboxFolder = (
            $folderLower === 'inbox' || 
            strpos($folderLower, 'inbox') !== false && 
            strpos($folderLower, 'sent') === false &&
            strpos($folderLower, 'draft') === false
        );
        
        if ($isInboxFolder && !empty($message['from'])) {
            try {
                $fromEmail = is_array($message['from']) 
                    ? ($message['from']['address'] ?? $message['from_email'] ?? null)
                    : $message['from'];
                $fromName = is_array($message['from']) 
                    ? ($message['from']['name'] ?? null) 
                    : null;
                    
                if ($fromEmail && $fromEmail !== $this->userEmail) {
                    $clientService = new \Webmail\Services\ClientService($this->config);
                    // Only update if this sender is already a tracked client
                    $domain = $clientService->extractDomain($fromEmail);
                    if ($domain) {
                        $isGeneric = $clientService->isGenericDomain($domain);
                        $clientIdentifier = $isGeneric ? strtolower($fromEmail) : $domain;
                        $existingClient = $clientService->getClientByDomain($this->userEmail, $clientIdentifier);
                        if ($existingClient) {
                            $clientService->updateActivity($this->userEmail, $fromEmail, 'inbound', $fromName);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Don't fail if client tracking fails
                error_log("Client activity tracking error: " . $e->getMessage());
            }
        }
        
        return Response::success($message);
    }
    
    /**
     * Get multiple messages in batch (for pre-fetching conversations)
     * POST /mailbox/{folder}/messages/batch
     * 
     * Body:
     * - uids: array of UIDs to fetch
     */
    public function batchMessages(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'messages_batch');
        if ($folder === null) return Response::error('Folder not found', 404);
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        
        $uids = $request->input('uids', []);
        
        if (empty($uids) || !is_array($uids)) {
            return Response::error('UIDs array is required', 400);
        }
        
        // Limit batch size
        $uids = array_slice($uids, 0, 50);
        
        $sanitizer = new \Webmail\Services\HtmlSanitizer();
        $labelService = null;
        
        // Batch IMAP fetch: one UID FETCH command instead of N per-message calls
        $rawMessages = $this->imap->getMessagesBatch($folder, array_map('intval', $uids));
        
        $messages = [];
        $fetched = 0;
        
        foreach ($rawMessages as $uid => $message) {
            try {
                if (!empty($message['body_html']) && strlen(trim($message['body_html'])) > 10) {
                    $message['body_html'] = $sanitizer->sanitize($message['body_html']);
                } elseif (!empty($message['body_text'])) {
                    $message['body_html'] = $sanitizer->textToHtml($message['body_text']);
                } elseif (!empty($message['body_calendar'])) {
                    $message['body_html'] = $this->renderCalendarInvite($message['body_calendar']);
                } else {
                    $message['body_html'] = '<p style="color: #666; font-style: italic;">No message content</p>';
                }

                if (!empty($message['body_calendar'])) {
                    $message['calendar_event'] = $this->parseCalendarEvent($message['body_calendar']);
                }
                
                if (!empty($message['body_html'])) {
                    $message['body_html'] = preg_replace(
                        '/<img[^>]*src=["\'][^"\']*\/api\/track\/[a-f0-9]+\.gif["\'][^>]*\/?>/i',
                        '',
                        $message['body_html']
                    );
                }
                
                try {
                    if (!empty($message['message_id'])) {
                        if ($labelService === null) {
                            $labelService = new LabelService($this->config);
                        }
                        $message['labels'] = $labelService->getMessageLabels($this->userEmail, $message['message_id']);
                    } else {
                        $message['labels'] = [];
                    }
                } catch (\Exception $e) {
                    $message['labels'] = [];
                }
                
                $messages[$uid] = $message;
                $fetched++;
            } catch (\Exception $e) {
                error_log("Failed to process message $uid in batch: " . $e->getMessage());
            }
        }
        
        return Response::success([
            'messages' => $messages,
            'stats' => [
                'requested' => count($uids),
                'fetched' => $fetched,
                'failed' => count($uids) - $fetched,
            ]
        ]);
    }
    
    /**
     * Get multiple messages from multiple folders in batch (for conversation loading)
     * POST /mailbox/messages/batch-multi
     * 
     * Body:
     * - requests: array of { folder: string, uid: number }
     * - skip_cache: optional, bypass cache
     * 
     * This is more efficient than making N separate API calls for conversation threads
     * that span multiple folders (e.g., INBOX + Sent)
     */
    public function batchMessagesMultiFolder(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $requests = $request->input('requests', []);
        $skipCache = (bool)$request->input('skip_cache', false);
        
        if (empty($requests) || !is_array($requests)) {
            return Response::error('Requests array is required', 400);
        }
        
        // Limit batch size to prevent abuse
        $requests = array_slice($requests, 0, 50);
        
        $cache = $this->getRedisCache();
        $sanitizer = new \Webmail\Services\HtmlSanitizer();
        $labelService = null;
        
        $messages = [];
        $stats = [
            'requested' => count($requests),
            'from_cache' => 0,
            'fetched' => 0,
            'failed' => 0,
        ];
        
        // Group requests by folder for efficient IMAP folder switching
        $byFolder = [];
        foreach ($requests as $req) {
            if (!isset($req['folder']) || !isset($req['uid'])) {
                $stats['failed']++;
                continue;
            }
            
            $folder = $this->normalizeFolderName($req['folder']);
            $uid = (int)$req['uid'];
            
            if (!isset($byFolder[$folder])) {
                $byFolder[$folder] = [];
            }
            $byFolder[$folder][] = $uid;
        }
        
        // Process each folder
        foreach ($byFolder as $folder => $uids) {
            // Check Redis cache first for all UIDs in this folder
            $uidsToFetch = [];
            
            if (!$skipCache) {
                $cachedMessages = $cache->getMessages($this->userEmail, $folder, $uids);
                foreach ($uids as $uid) {
                    $key = "{$folder}:{$uid}";
                    if (isset($cachedMessages[$uid])) {
                        $messages[$key] = $cachedMessages[$uid];
                        // _from_cache already set by getMessages()
                        $messages[$key]['folder'] = $folder;
                        $stats['from_cache']++;
                    } else {
                        $uidsToFetch[] = $uid;
                    }
                }
            } else {
                $uidsToFetch = $uids;
            }
            
            // Batch fetch remaining from IMAP (one command per folder)
            if (!empty($uidsToFetch)) {
                $fetchFolder = $folder;
                $batchResults = $this->imap->getMessagesBatch($fetchFolder, $uidsToFetch);
                
                // Retry with case-insensitive folder if batch returned nothing
                if (empty($batchResults)) {
                    $actualFolder = $this->findActualFolderName($folder);
                    if ($actualFolder && $actualFolder !== $folder) {
                        $fetchFolder = $actualFolder;
                        $batchResults = $this->imap->getMessagesBatch($fetchFolder, $uidsToFetch);
                    }
                }
                
                foreach ($uidsToFetch as $uid) {
                    $key = "{$folder}:{$uid}";
                    $message = $batchResults[$uid] ?? null;
                    
                    if (!$message) {
                        $stats['failed']++;
                        continue;
                    }
                    
                    try {
                        if (!empty($message['body_html']) && strlen(trim($message['body_html'])) > 10) {
                            $message['body_html'] = $sanitizer->sanitize($message['body_html']);
                        } elseif (!empty($message['body_text'])) {
                            $message['body_html'] = $sanitizer->textToHtml($message['body_text']);
                        } elseif (!empty($message['body_calendar'])) {
                            $message['body_html'] = $this->renderCalendarInvite($message['body_calendar']);
                        } else {
                            $message['body_html'] = '<p style="color: #666; font-style: italic;">No message content</p>';
                        }

                        if (!empty($message['body_calendar'])) {
                            $message['calendar_event'] = $this->parseCalendarEvent($message['body_calendar']);
                        }
                        
                        if (!empty($message['body_html'])) {
                            $message['body_html'] = preg_replace(
                                '/<img[^>]*src=["\'][^"\']*\/api\/track\/[a-f0-9]+\.gif["\'][^>]*\/?>/i',
                                '',
                                $message['body_html']
                            );
                        }
                        
                        try {
                            if (!empty($message['message_id'])) {
                                if ($labelService === null) {
                                    $labelService = new LabelService($this->config);
                                }
                                $message['labels'] = $labelService->getMessageLabels($this->userEmail, $message['message_id']);
                            } else {
                                $message['labels'] = [];
                            }
                        } catch (\Exception $e) {
                            $message['labels'] = [];
                        }
                        
                        $cache->setMessage($this->userEmail, $folder, $uid, $message);
                        
                        $message['_from_cache'] = false;
                        $message['folder'] = $folder;
                        $messages[$key] = $message;
                        $stats['fetched']++;
                    } catch (\Exception $e) {
                        error_log("Failed to process message {$folder}:{$uid} in multi-batch: " . $e->getMessage());
                        $stats['failed']++;
                    }
                }
            }
        }
        
        return Response::success([
            'messages' => $messages,
            'stats' => $stats,
        ]);
    }

    /**
     * Get raw/original message (like Gmail's "Show Original")
     * GET /mailbox/{folder}/messages/{uid}/raw
     */
    public function rawMessage(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'message_raw');
        if ($folder === null) return Response::error('Folder not found', 404);
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');

        $rawMessage = $this->imap->getOriginalMessage($folder, $uid);

        if (!$rawMessage) {
            return Response::notFound('Message not found');
        }

        return Response::success($rawMessage);
    }

    /**
     * Debug MIME structure of a message
     * GET /mailbox/{folder}/messages/{uid}/debug-structure
     */
    public function debugMimeStructure(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'message_debug_structure');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');

        $tree = $this->imap->getMimeStructureTree($folder, $uid);
        if (!$tree) {
            return Response::notFound('Message not found or could not parse structure');
        }

        return Response::success($tree);
    }

    /**
     * Download raw message source as .eml file
     * GET /mailbox/{folder}/messages/{uid}/download
     */
    public function downloadRawMessage(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'message_download');
        if ($folder === null) return Response::error('Folder not found', 404);
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');

        $rawMessage = $this->imap->getOriginalMessage($folder, $uid);

        if (!$rawMessage || empty($rawMessage['raw_source'])) {
            return Response::notFound('Message not found');
        }

        // Generate filename from subject
        $subject = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $rawMessage['subject'] ?? 'email');
        $subject = trim(substr($subject, 0, 50));
        if (empty($subject)) {
            $subject = 'email';
        }
        $filename = $subject . '.eml';

        // Send as downloadable file
        header('Content-Type: message/rfc822');
        header($this->safeContentDisposition('attachment', $filename));
        header('Content-Length: ' . strlen($rawMessage['raw_source']));
        echo $rawMessage['raw_source'];
        exit;
    }

    /**
     * Set message flag
     * POST /mailbox/{folder}/messages/{uid}/flag
     */
    public function setFlag(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        // Wave 2 P2 dual-routing.
        $folder = $this->getResolvedFolder($request, 'flag_set');
        if ($folder === null) {
            return Response::error('Folder not found', 404);
        }
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');
        
        // Read from body first, fallback to query params (OLS/LSAPI can strip POST bodies)
        $flag = $request->input('flag');
        if (!$flag) {
            $flag = $request->getQuery('flag');
        }
        $bodyValue = $request->input('value');
        if ($bodyValue !== null) {
            $value = (bool)$bodyValue;
        } else {
            $queryValue = $request->getQuery('value');
            $value = $queryValue !== null ? filter_var($queryValue, FILTER_VALIDATE_BOOLEAN) : true;
        }

        error_log("[MailboxController::setFlag] folder=$folder, uid=$uid, flag=$flag, value=" . ($value ? 'true' : 'false') . ", bodyFlag=" . ($request->input('flag') ?? 'NULL') . ", queryFlag=" . ($request->getQuery('flag') ?? 'NULL'));

        $allowedFlags = ['seen', 'flagged', 'answered', 'deleted', 'draft'];
        if (!$flag || !in_array(strtolower($flag), $allowedFlags)) {
            error_log("[MailboxController::setFlag] REJECTED: flag='$flag' not in allowed list. Body: " . json_encode($request->input()) . " | Query: " . json_encode($request->getQuery()));
            return Response::error('Invalid flag: ' . ($flag ?: 'empty'), 400);
        }

        $success = $this->imap->setFlag($folder, $uid, $flag, $value);

        if (!$success) {
            return Response::error('Failed to set flag');
        }
        
        // Invalidate Redis cache for this message (flag change affects cached data)
        $cache = $this->getRedisCache();
        $cache->invalidateMessage($this->userEmail, $folder, $uid);
        
        // Sync conversation database for read/unread status changes
        if (strtolower($flag) === 'seen') {
            try {
                $conversationService = $this->getConversationService();
                $conversationService->updateMemberReadStatus($this->userEmail, $folder, $uid, $value);
            } catch (\Exception $e) {
                error_log("[MailboxController::setFlag] Failed to sync conversation database: " . $e->getMessage());
            }
        }
        
        // Publish real-time event for WebSocket sync
        $imapFlag = '\\' . ucfirst(strtolower($flag));
        $flags = $value ? [$imapFlag] : [];
        $cache->publishFlagsChanged($this->userEmail, $folder, $uid, [
            'flag' => $flag,
            'value' => $value,
            'imapFlags' => $flags,
        ]);
        
        // Publish updated folder counts when read/unread changes (affects unread badge)
        if (strtolower($flag) === 'seen') {
            try {
                $status = $this->imap->getFolderStatus($folder);
                if ($status) {
                    $cache->publishFolderCounts(
                        $this->userEmail,
                        $folder,
                        $status['messages'] ?? 0,
                        $status['unseen'] ?? 0,
                        $status['uidnext'] ?? null,
                        $status['uidvalidity'] ?? null
                    );
                }
            } catch (\Exception $e) {
                error_log("[MailboxController::setFlag] Failed to publish folder counts: " . $e->getMessage());
            }
        }

        return Response::success(null, 'Flag updated');
    }

    /**
     * Get database connection for pinned emails
     */
    private function getPinnedDb(): \PDO
    {
        return \Webmail\Core\Database::getConnection($this->config);
    }

    /**
     * Resolve a folder path to its canonical UUIDv7. Walks the path-history
     * tombstones if the path was renamed. Returns null when:
     *   - FolderIndexService cannot be constructed (DB blip, etc.)
     *   - The folder has not been upserted yet (very first hit before any
     *     /mailbox/folders or /mailbox/init call has run for this account).
     *
     * Pinning / unpinning / isPinned all hard-fail (4xx) when this returns
     * null, since post-cutover the legacy (folder, uid) key is gone.
     */
    private function resolveFolderId(string $folder): ?string
    {
        $svc = $this->getFolderIndexService();
        if ($svc === null || $folder === '' || empty($this->userEmail)) {
            return null;
        }
        try {
            $row = $svc->getByPath($this->userEmail, $folder);
        } catch (\Throwable $e) {
            error_log('[MailboxController] resolveFolderId failed for ' . $folder . ': ' . $e->getMessage());
            return null;
        }
        return $row['id'] ?? null;
    }

    /**
     * Get all pinned emails for the current user
     * GET /mailbox/pinned
     */
    public function getPinnedEmails(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $db = $this->getPinnedDb();

        // JOIN identity to project the current path string for display.
        // The path is denormalised at read time; folder_id is the system
        // of record.
        $stmt = $db->prepare(
            "SELECT pe.folder_id, fi.current_path AS folder, pe.uid,
                    pe.message_id, pe.subject, pe.pinned_at
             FROM pinned_emails pe
             LEFT JOIN webmail_folder_identity fi ON fi.id = pe.folder_id
             WHERE pe.user_email = ?
             ORDER BY pe.pinned_at DESC"
        );
        $stmt->execute([$this->userEmail]);
        $pins = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return Response::success($pins);
    }

    /**
     * Pin an email
     * POST /folders/{folder_id}/messages/{uid}/pin
     */
    public function pinEmail(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $folder = $this->getResolvedFolder($request, 'pin');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');
        $messageId = $request->input('message_id');
        $subject = $request->input('subject');

        $db = $this->getPinnedDb();
        $folderId = $this->resolveFolderId($folder);
        if ($folderId === null) {
            return Response::error('Folder identity not found; refresh folders and retry', 409);
        }

        $stmt = $db->prepare(
            "SELECT id FROM pinned_emails WHERE user_email = ? AND folder_id = ? AND uid = ? LIMIT 1"
        );
        $stmt->execute([$this->userEmail, $folderId, $uid]);
        if ($stmt->fetch()) {
            return Response::success(['pinned' => true], 'Already pinned');
        }

        $stmt = $db->prepare(
            "INSERT INTO pinned_emails (user_email, folder_id, uid, message_id, subject)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$this->userEmail, $folderId, $uid, $messageId, $subject]);

        // Publish real-time event (non-blocking).
        try {
            $cache = $this->getRedisCache();
            $cache->publishPinChanged($this->userEmail, $folder, $uid, true);
        } catch (\Exception $e) {
            // Silent fail - pin was saved, just no real-time notification
        }

        return Response::success(['pinned' => true], 'Email pinned');
    }

    /**
     * Unpin an email
     * DELETE /folders/{folder_id}/messages/{uid}/pin
     */
    public function unpinEmail(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $folder = $this->getResolvedFolder($request, 'unpin');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');

        $db = $this->getPinnedDb();
        $folderId = $this->resolveFolderId($folder);
        if ($folderId === null) {
            return Response::error('Folder identity not found; refresh folders and retry', 409);
        }

        $stmt = $db->prepare(
            "DELETE FROM pinned_emails WHERE user_email = ? AND folder_id = ? AND uid = ?"
        );
        $stmt->execute([$this->userEmail, $folderId, $uid]);

        // Publish real-time event (non-blocking).
        try {
            $cache = $this->getRedisCache();
            $cache->publishPinChanged($this->userEmail, $folder, $uid, false);
        } catch (\Exception $e) {
            // Silent fail - unpin was saved, just no real-time notification
        }

        return Response::success(['pinned' => false], 'Email unpinned');
    }

    /**
     * Check if email is pinned
     * GET /folders/{folder_id}/messages/{uid}/pin
     */
    public function isPinned(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $folder = $this->getResolvedFolder($request, 'is_pinned');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');

        $db = $this->getPinnedDb();
        $folderId = $this->resolveFolderId($folder);
        if ($folderId === null) {
            return Response::success(['pinned' => false, 'pinned_at' => null]);
        }

        $stmt = $db->prepare(
            "SELECT id, pinned_at FROM pinned_emails
             WHERE user_email = ? AND folder_id = ? AND uid = ? LIMIT 1"
        );
        $stmt->execute([$this->userEmail, $folderId, $uid]);
        $pin = $stmt->fetch(\PDO::FETCH_ASSOC);

        return Response::success([
            'pinned' => (bool)$pin,
            'pinned_at' => $pin['pinned_at'] ?? null
        ]);
    }

    /**
     * Move message to folder
     * POST /mailbox/{folder}/messages/{uid}/move
     */
    public function move(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        // Wave 2 P2 dual-routing.
        $folder = $this->getResolvedFolder($request, 'message_move');
        if ($folder === null) {
            return Response::error('Folder not found', 404);
        }
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');
        
        // Get target from body - check multiple possible formats
        $targetFolder = $request->input('target');
        if (!$targetFolder) {
            $targetFolder = $request->input('targetFolder');
        }
        if (!$targetFolder) {
            $targetFolder = $request->input('destination');
        }
        if (!$targetFolder) {
            $targetFolder = $request->input('folder');
        }
        // Fallback: check query parameter (body can be lost by OLS/LSAPI edge cases)
        if (!$targetFolder) {
            $targetFolder = $request->getQuery('target');
        }

        if (!$targetFolder) {
            error_log("MOVE ERROR: Target folder is empty/missing. Body: " . json_encode($request->input()) . " | Query: " . json_encode($request->getQuery()));
            return Response::error('Target folder is required', 400);
        }

        // Normalize target folder too (handle case mismatches)
        $targetFolder = $this->normalizeFolderName($targetFolder);

        if ($folder === $targetFolder) {
            return Response::error('Source and target folders are the same', 400);
        }

        $success = $this->imap->moveMessage($folder, $uid, $targetFolder);
        $newUid = $this->imap->getLastMoveNewUid();

        if (!$success) {
            $imapError = $this->imap->getLastError();
            $msg = 'Failed to move message';
            if ($imapError) {
                $msg .= ': ' . $imapError;
            }
            error_log("MOVE FAILED: UID {$uid} from '{$folder}' to '{$targetFolder}' - {$msg}");
            return Response::error($msg);
        }
        
        // Invalidate Redis cache for moved message (UID changes after move)
        $cache = $this->getRedisCache();
        $cache->invalidateMessage($this->userEmail, $folder, $uid);
        $cache->invalidateFolder($this->userEmail, $folder);
        $cache->invalidateFolder($this->userEmail, $targetFolder);
        
        // Sync conversation database - update folder and UID for moved message
        try {
            $conversationService = $this->getConversationService();
            $conversationService->moveConversationMember($this->userEmail, $folder, $uid, $targetFolder, $newUid);
        } catch (\Exception $e) {
            error_log("[MailboxController::move] Failed to sync conversation database: " . $e->getMessage());
        }
        
        // Publish real-time event for WebSocket sync
        $cache->publishMessageMoved($this->userEmail, $folder, $targetFolder, $uid, $newUid);

        return Response::success(['new_uid' => $newUid], 'Message moved');
    }

    /**
     * Delete message
     * DELETE /mailbox/{folder}/messages/{uid}
     */
    public function delete(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        // Wave 2 P2 dual-routing.
        $folder = $this->getResolvedFolder($request, 'message_delete');
        if ($folder === null) {
            return Response::error('Folder not found', 404);
        }
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');
        
        // Properly parse boolean from query string (string "false" should be false, not true)
        $permanentParam = $request->getQuery('permanent', '');
        $permanent = filter_var($permanentParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        error_log("DELETE: folder='{$folder}', uid={$uid}, permanentParam='{$permanentParam}', permanent=" . ($permanent ? 'true' : 'false'));

        if ($permanent) {
            // Permanently delete
            $success = $this->imap->deleteMessage($folder, $uid);
            error_log("DELETE: Permanent delete, success=" . ($success ? 'true' : 'false'));
        } else {
            // Move to trash
            $trashFolder = $this->findTrashFolder();
            error_log("DELETE: Found trash folder: '" . ($trashFolder ?: 'NULL') . "'");
            
            // Check if we're already in trash folder
            $isInTrash = false;
            if ($trashFolder) {
                $folderLower = strtolower(trim($folder));
                $trashLower = strtolower(trim($trashFolder));
                $isInTrash = ($folderLower === $trashLower);
                error_log("DELETE: Comparing folders - source='{$folderLower}', trash='{$trashLower}', isInTrash=" . ($isInTrash ? 'true' : 'false'));
            }
            
            if ($trashFolder && !$isInTrash) {
                error_log("DELETE: Moving from '{$folder}' to '{$trashFolder}'");
                $success = $this->imap->moveMessage($folder, $uid, $trashFolder);
                $trashNewUid = $this->imap->getLastMoveNewUid();
                error_log("DELETE: Move result success=" . ($success ? 'true' : 'false') . ", trashNewUid=" . ($trashNewUid ?? 'null'));
                
                if (!$success) {
                    error_log("DELETE: Move to trash failed, falling back to permanent delete");
                    $success = $this->imap->deleteMessage($folder, $uid);
                    if (!$success) {
                        error_log("DELETE: Permanent delete fallback also failed!");
                        return Response::error('Failed to delete message');
                    }
                    $permanent = true;
                    $trashNewUid = null;
                }
            } else {
                error_log("DELETE: Already in trash or no trash folder found, permanently deleting");
                $success = $this->imap->deleteMessage($folder, $uid);
            }
        }

        if (!$success) {
            return Response::error('Failed to delete message');
        }
        
        // Invalidate Redis cache for deleted message + source folder
        $cache = $this->getRedisCache();
        $cache->invalidateMessage($this->userEmail, $folder, $uid);
        $cache->invalidateFolder($this->userEmail, $folder);
        
        // Sync conversation database
        try {
            $conversationService = $this->getConversationService();
            if ($permanent || !isset($trashFolder) || !$trashFolder || (isset($isInTrash) && $isInTrash)) {
                $conversationService->deleteConversationMember($this->userEmail, $folder, $uid);
            } else {
                $conversationService->moveConversationMember($this->userEmail, $folder, $uid, $trashFolder, $trashNewUid ?? null);
            }
        } catch (\Exception $e) {
            error_log("[MailboxController::delete] Failed to sync conversation database: " . $e->getMessage());
        }
        
        // Publish real-time event for WebSocket sync
        if ($permanent || !$trashFolder || $isInTrash) {
            $cache->publishMessageDeleted($this->userEmail, $folder, $uid, true);
        } else {
            $cache->publishMessageMoved($this->userEmail, $folder, $trashFolder, $uid, $trashNewUid ?? null);
            $cache->invalidateFolder($this->userEmail, $trashFolder);
        }

        return Response::success(null, 'Message deleted');
    }

    /**
     * Batch move messages from multiple folders to a single target.
     * POST /mailbox/batch-move
     * Body: { messages: [{uid, folder}, ...], target: "INBOX.Trash" }
     */
    public function batchMove(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $messageList = $request->input('messages', []);
        $targetFolder = $request->input('target');

        if (!$targetFolder) {
            return Response::error('Target folder is required', 400);
        }
        if (empty($messageList) || !is_array($messageList)) {
            return Response::error('Messages array is required', 400);
        }

        $targetFolder = $this->normalizeFolderName($targetFolder);
        $messageList = array_slice($messageList, 0, 100);
        $cache = $this->getRedisCache();
        $conversationService = $this->getConversationService();

        $success = 0;
        $failed = 0;
        $errors = [];

        $byFolder = [];
        foreach ($messageList as $item) {
            if (!isset($item['uid']) || !isset($item['folder'])) {
                $failed++;
                continue;
            }
            $folder = $this->normalizeFolderName($item['folder']);
            if ($folder === $targetFolder) {
                continue;
            }
            $byFolder[$folder][] = (int)$item['uid'];
        }

        foreach ($byFolder as $folder => $uids) {
            if (!$this->imap->selectFolder($folder)) {
                $failed += count($uids);
                $errors[] = "Failed to select folder: {$folder}";
                continue;
            }
            foreach ($uids as $uid) {
                $result = $this->imap->moveMessage($folder, $uid, $targetFolder);
                $newUid = $this->imap->getLastMoveNewUid();
                if ($result) {
                    $success++;
                    $cache->invalidateMessage($this->userEmail, $folder, $uid);
                    try {
                        $conversationService->moveConversationMember($this->userEmail, $folder, $uid, $targetFolder, $newUid);
                    } catch (\Exception $e) {
                        // Non-critical
                    }
                    $cache->publishMessageMoved($this->userEmail, $folder, $targetFolder, $uid, $newUid);
                } else {
                    $failed++;
                    $errors[] = "UID {$uid} in {$folder}: " . ($this->imap->getLastError() ?: 'Unknown error');
                }
            }
        }

        // Invalidate source + target folder caches
        foreach (array_keys($byFolder) as $srcFolder) {
            $cache->invalidateFolder($this->userEmail, $srcFolder);
        }
        $cache->invalidateFolder($this->userEmail, $targetFolder);

        return Response::success([
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ], "{$success} moved, {$failed} failed");
    }

    /**
     * Batch delete messages from multiple folders.
     * POST /mailbox/batch-delete
     * Body: { messages: [{uid, folder}, ...], permanent: false }
     */
    public function batchDelete(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $messageList = $request->input('messages', []);
        $permanent = (bool)$request->input('permanent', false);

        if (empty($messageList) || !is_array($messageList)) {
            return Response::error('Messages array is required', 400);
        }

        $messageList = array_slice($messageList, 0, 100);
        $cache = $this->getRedisCache();
        $conversationService = $this->getConversationService();
        $trashFolder = $permanent ? null : $this->findTrashFolder();

        $success = 0;
        $failed = 0;
        $errors = [];

        $byFolder = [];
        foreach ($messageList as $item) {
            if (!isset($item['uid']) || !isset($item['folder'])) {
                $failed++;
                continue;
            }
            $folder = $this->normalizeFolderName($item['folder']);
            $byFolder[$folder][] = (int)$item['uid'];
        }

        foreach ($byFolder as $folder => $uids) {
            if (!$this->imap->selectFolder($folder)) {
                $failed += count($uids);
                $errors[] = "Failed to select folder: {$folder}";
                continue;
            }

            $isInTrash = $trashFolder && strtolower(trim($folder)) === strtolower(trim($trashFolder));

            foreach ($uids as $uid) {
                $result = false;
                $wasMovedToTrash = false;

                $trashNewUid = null;
                if ($permanent || !$trashFolder || $isInTrash) {
                    $result = $this->imap->deleteMessage($folder, $uid);
                } else {
                    $result = $this->imap->moveMessage($folder, $uid, $trashFolder);
                    $trashNewUid = $this->imap->getLastMoveNewUid();
                    $wasMovedToTrash = $result;
                    if (!$result) {
                        $result = $this->imap->deleteMessage($folder, $uid);
                        $trashNewUid = null;
                    }
                }

                if ($result) {
                    $success++;
                    $cache->invalidateMessage($this->userEmail, $folder, $uid);
                    try {
                        if ($wasMovedToTrash) {
                            $conversationService->moveConversationMember($this->userEmail, $folder, $uid, $trashFolder, $trashNewUid);
                        } else {
                            $conversationService->deleteConversationMember($this->userEmail, $folder, $uid);
                        }
                    } catch (\Exception $e) {
                        // Non-critical
                    }
                    if ($wasMovedToTrash) {
                        $cache->publishMessageMoved($this->userEmail, $folder, $trashFolder, $uid, $trashNewUid);
                    } else {
                        $cache->publishMessageDeleted($this->userEmail, $folder, $uid, true);
                    }
                } else {
                    $failed++;
                    $errors[] = "UID {$uid} in {$folder}: " . ($this->imap->getLastError() ?: 'Unknown error');
                }
            }
        }

        // Invalidate source + trash folder caches
        foreach (array_keys($byFolder) as $srcFolder) {
            $cache->invalidateFolder($this->userEmail, $srcFolder);
        }
        if ($trashFolder) {
            $cache->invalidateFolder($this->userEmail, $trashFolder);
        }

        return Response::success([
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ], "{$success} deleted, {$failed} failed");
    }

    /**
     * Download attachment
     * GET /mailbox/{folder}/messages/{uid}/attachments/{part}
     */
    public function attachment(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'attachment');
        if ($folder === null) return Response::error('Folder not found', 404);
        // Normalize folder name (handle case mismatches)
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');
        $part = $request->getParam('part');

        $attachment = $this->imap->getAttachment($folder, $uid, $part);

        if (!$attachment) {
            return Response::notFound('Attachment not found');
        }

        // Return as base64 for download
        return Response::success([
            'filename' => $attachment['filename'],
            'type' => $attachment['type'],
            'size' => $attachment['size'],
            'content' => base64_encode($attachment['content']),
        ]);
    }

    /**
     * Get attachment thumbnail (for images only)
     * GET /mailbox/{folder}/messages/{uid}/attachments/{part}/thumbnail
     */
    public function attachmentThumbnail(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'attachment_thumbnail');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');
        $part = $request->getParam('part');
        $size = (int)($request->getQuery('size') ?? 80); // Default 80px
        $size = min(max($size, 40), 200); // Clamp between 40-200px

        // Check cache first
        $cacheKey = "att_thumb_{$this->getActiveEmail()}_{$folder}_{$uid}_{$part}_{$size}";
        $cacheDir = $this->config['drive']['storage_path'] ?? '/tmp';
        $cachePath = $cacheDir . '/thumbnails/' . md5($cacheKey) . '.jpg';
        
        // Ensure cache directory exists
        $thumbDir = dirname($cachePath);
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0755, true);
        }
        
        // Serve from cache if exists and not too old (24 hours)
        if (file_exists($cachePath) && (time() - filemtime($cachePath)) < 86400) {
            $thumbnail = file_get_contents($cachePath);
            return $this->imageResponse($thumbnail, 'image/jpeg');
        }

        // Fetch attachment
        $attachment = $this->imap->getAttachment($folder, $uid, $part);

        if (!$attachment) {
            return Response::notFound('Attachment not found');
        }

        // Check if it's an image
        $mimeType = strtolower($attachment['type'] ?? '');
        if (!str_starts_with($mimeType, 'image/')) {
            return Response::error('Not an image', 400);
        }

        // Skip SVG and other non-raster formats
        if (in_array($mimeType, ['image/svg+xml', 'image/x-icon'])) {
            return Response::error('Cannot thumbnail this format', 400);
        }

        // Generate thumbnail
        try {
            $thumbnail = $this->generateThumbnail($attachment['content'], $size, $mimeType);
            
            // Cache it
            @file_put_contents($cachePath, $thumbnail);
            
            return $this->imageResponse($thumbnail, 'image/jpeg');
        } catch (\Exception $e) {
            error_log("Thumbnail generation failed: " . $e->getMessage());
            return Response::error('Failed to generate thumbnail', 500);
        }
    }

    /**
     * Generate thumbnail from image data
     */
    private function generateThumbnail(string $imageData, int $size, string $mimeType): string
    {
        // Create image from string
        $source = @imagecreatefromstring($imageData);
        if (!$source) {
            throw new \Exception('Failed to create image from data');
        }

        $origWidth = imagesx($source);
        $origHeight = imagesy($source);

        // Calculate new dimensions (maintain aspect ratio, fit in square)
        if ($origWidth > $origHeight) {
            $newWidth = $size;
            $newHeight = (int)($origHeight * ($size / $origWidth));
        } else {
            $newHeight = $size;
            $newWidth = (int)($origWidth * ($size / $origHeight));
        }

        // Create thumbnail
        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        
        // Handle transparency for PNG/GIF
        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
            imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
        } else {
            // White background for JPEG
            $white = imagecolorallocate($thumb, 255, 255, 255);
            imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $white);
        }

        // Resize
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        // Output to string
        ob_start();
        imagejpeg($thumb, null, 85);
        $thumbnail = ob_get_clean();

        // Cleanup
        imagedestroy($source);
        imagedestroy($thumb);

        return $thumbnail;
    }

    /**
     * Return raw image response
     */
    private function imageResponse(string $data, string $contentType): Response
    {
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
        echo $data;
        exit;
    }

    /**
     * Save email attachments to Drive folder
     * POST /mailbox/save-attachments-to-drive
     * 
     * Body: {
     *   folder: string (email folder),
     *   uid: int,
     *   parts: string[] (attachment part numbers),
     *   drive_folder_id: int|null (null = root)
     * }
     */
    public function saveAttachmentsToDrive(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $request->input('folder');
        $uid = (int)$request->input('uid');
        $parts = $request->input('parts', []);
        $driveFolderId = $request->input('drive_folder_id');
        
        if (empty($folder) || empty($uid) || empty($parts)) {
            return Response::error('Folder, uid and parts are required', 400);
        }
        
        error_log("saveAttachmentsToDrive: folder='$folder', uid=$uid, parts=" . json_encode($parts));
        
        // Normalize folder name
        $folder = $this->normalizeFolderName($folder);
        error_log("saveAttachmentsToDrive: normalized folder='$folder'");
        
        // Initialize Drive service
        $activeEmail = $this->getActiveEmail();
        $driveService = new \Webmail\Services\DriveService($this->config, $activeEmail);
        
        $saved = [];
        $failed = [];
        
        foreach ($parts as $part) {
            try {
                // Fetch attachment from IMAP
                $attachment = $this->imap->getAttachment($folder, $uid, $part);
                
                if (!$attachment) {
                    $failed[] = ['part' => $part, 'error' => 'Attachment not found'];
                    continue;
                }
                
                // Save to Drive (tag with IMAP source so the email view
                // can show a "Saved to Drive" indicator on this card on
                // future visits without rescanning Drive).
                $result = $driveService->uploadFileContent(
                    $activeEmail,
                    $attachment['filename'],
                    $attachment['content'],
                    $attachment['type'] ?? 'application/octet-stream',
                    $driveFolderId,
                    $folder,
                    $uid,
                    (string)$part
                );
                
                if ($result) {
                    $saved[] = [
                        'part' => $part,
                        'filename' => $attachment['filename'],
                        'file' => $result
                    ];
                } else {
                    error_log("saveAttachmentsToDrive: uploadFileContent returned null for '{$attachment['filename']}' (email=$activeEmail, folderId=$driveFolderId, size={$attachment['size']})");
                    $failed[] = ['part' => $part, 'filename' => $attachment['filename'], 'error' => 'Failed to save to Drive'];
                }
            } catch (\Exception $e) {
                error_log("saveAttachmentsToDrive error for part $part: " . $e->getMessage());
                $failed[] = ['part' => $part, 'error' => $e->getMessage()];
            }
        }
        
        return Response::success([
            'saved' => $saved,
            'failed' => $failed,
            'total' => count($parts),
            'success_count' => count($saved),
            'failed_count' => count($failed)
        ], count($saved) . ' attachment(s) saved to Drive');
    }

    /**
     * Create folder
     * POST /mailbox/folders
     */
    public function createFolder(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $name = $request->input('name');
        $parent = $request->input('parent'); // Optional parent folder
        
        // Debug logging
        error_log("createFolder: name=" . var_export($name, true) . ", parent=" . var_export($parent, true));
        error_log("createFolder: raw body=" . file_get_contents('php://input'));
        
        if (!$name) {
            return Response::error('Folder name is required', 400);
        }
        
        // Sanitize folder name - remove invalid characters (but allow dots for subfolders)
        $name = preg_replace('/[\/\\\\<>:"|?*]/', '', trim($name));
        if (empty($name)) {
            return Response::error('Invalid folder name', 400);
        }
        
        // If parent is specified, create as subfolder
        $fullName = $name;
        if ($parent) {
            // Remove INBOX. prefix from parent if present for clean path building
            $parentClean = $parent;
            if (stripos($parent, 'INBOX.') === 0) {
                $parentClean = substr($parent, 6);
            } elseif (strtoupper($parent) === 'INBOX') {
                $parentClean = '';
            }
            
            if ($parentClean) {
                $fullName = $parentClean . '.' . $name;
            }
        }

        $success = $this->imap->createFolder($fullName);

        if (!$success) {
            $errors = imap_errors();
            $errorMsg = $errors ? implode(', ', $errors) : 'Unknown error';
            error_log("Folder creation failed for '$fullName': $errorMsg");
            return Response::error('Failed to create folder: ' . $errorMsg);
        }
        
        // Publish real-time event for WebSocket sync
        $createdFolder = 'INBOX.' . $fullName;
        $cache = $this->getRedisCache();
        $cache->invalidateFolderList($this->userEmail);
        $cache->publishFolderChanged($this->userEmail, 'created', $createdFolder);

        return Response::success(['folder' => $createdFolder], 'Folder created');
    }

    /**
     * Delete folder
     * DELETE /mailbox/folders/{folder}
     */
    public function deleteFolder(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        // Wave 2 P2 dual-routing.
        $folder = $this->getResolvedFolder($request, 'folder_delete');
        if ($folder === null) {
            return Response::error('Folder not found', 404);
        }

        // Prevent deletion of system folders
        $protected = ['inbox', 'sent', 'drafts', 'trash', 'junk', 'spam'];
        if (in_array(strtolower($folder), $protected)) {
            return Response::error('Cannot delete system folder', 400);
        }

        $success = $this->imap->deleteFolder($folder);

        if (!$success) {
            return Response::error('Failed to delete folder');
        }
        
        // Purge all DB metadata for the destroyed folder (and any children).
        try {
            $conversationService = $this->getConversationService();
            $conversationService->purgeFolderData($this->userEmail, $folder);
        } catch (\Exception $e) {
            error_log("[MailboxController::deleteFolder] Failed to purge folder data: " . $e->getMessage());
        }

        // Publish real-time event for WebSocket sync
        $cache = $this->getRedisCache();
        $cache->invalidateFolder($this->userEmail, $folder);
        $cache->invalidateFolderList($this->userEmail);
        $cache->publishFolderChanged($this->userEmail, 'deleted', $folder);

        // Wave 2: surface the delete through the documented invalidation
        // pipeline (3-second debounce per account+scope). Best-effort.
        try {
            (new FolderCacheInvalidator($cache))
                ->invalidate($this->userEmail ?? '', $folder, FolderCacheInvalidator::REASON_DELETED);
        } catch (\Throwable $e) {
            error_log('[MailboxController::deleteFolder] cache invalidator: ' . $e->getMessage());
        }

        return Response::success(null, 'Folder deleted');
    }

    /**
     * Empty folder (delete all messages in Trash or Spam)
     * POST /mailbox/folders/{folder}/empty
     */
    public function emptyFolder(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $this->getResolvedFolder($request, 'folder_empty');
        if ($folder === null) return Response::error('Folder not found', 404);

        // Only allow emptying Trash and Spam folders
        $folderLower = strtolower($folder);
        $allowed = ['trash', 'bin', 'junk', 'spam', 'deleted items', 'junk e-mail'];
        $isAllowed = false;
        
        foreach ($allowed as $a) {
            if (str_contains($folderLower, $a)) {
                $isAllowed = true;
                break;
            }
        }
        
        if (!$isAllowed) {
            return Response::error('Can only empty Trash or Spam folders', 400);
        }

        $deletedCount = $this->imap->emptyFolder($folder);

        if ($deletedCount === false) {
            return Response::error('Failed to empty folder');
        }
        
        // Remove conversation member rows for the emptied folder so recycled
        // UIDs from Dovecot don't silently map to stale conversation records.
        try {
            $conversationService = $this->getConversationService();
            $conversationService->deleteAllFolderMembers($this->userEmail, $folder);
        } catch (\Exception $e) {
            error_log("[MailboxController::emptyFolder] Failed to clean conversation data: " . $e->getMessage());
        }

        // Invalidate folder cache and publish events so other clients refresh
        $cache = $this->getRedisCache();
        $cache->invalidateFolder($this->userEmail, $folder);
        $cache->publishFolderCounts($this->userEmail, $folder, 0, 0);

        return Response::success(['deleted' => $deletedCount], 'Folder emptied');
    }

    /**
     * Rename/Move folder
     * PUT /mailbox/folders/{folder}
     */
    public function renameFolder(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        // Wave 2 P2 dual-routing.
        $oldFolder = $this->getResolvedFolder($request, 'folder_rename');
        if ($oldFolder === null) {
            return Response::error('Folder not found', 404);
        }

        $newName = $request->input('name');
        $newParent = $request->input('parent'); // Optional - for moving to different parent
        
        if (!$newName) {
            return Response::error('New folder name is required', 400);
        }
        
        // Prevent renaming system folders
        $protected = ['inbox', 'sent', 'drafts', 'trash', 'junk', 'spam'];
        $oldBaseName = strtolower(basename(str_replace('.', '/', $oldFolder)));
        if (in_array($oldBaseName, $protected) || strtoupper($oldFolder) === 'INBOX') {
            return Response::error('Cannot rename system folder', 400);
        }
        
        // Sanitize new name
        $newName = preg_replace('/[\/\\\\<>:"|?*]/', '', trim($newName));
        if (empty($newName)) {
            return Response::error('Invalid folder name', 400);
        }
        
        // Build new folder path
        if ($newParent !== null) {
            // Moving to new parent
            if (strtoupper($newParent) === 'INBOX' || empty($newParent)) {
                $newFolder = 'INBOX.' . $newName;
            } else {
                $newFolder = $newParent . '.' . $newName;
            }
        } else {
            // Just renaming - keep same parent
            $parts = explode('.', $oldFolder);
            array_pop($parts);
            $parts[] = $newName;
            $newFolder = implode('.', $parts);
        }
        
        $success = $this->imap->renameFolder($oldFolder, $newFolder);

        if (!$success) {
            $errors = imap_errors();
            $alerts = imap_alerts();
            $allErrors = array_merge($errors ?: [], $alerts ?: []);
            $errorMsg = !empty($allErrors) ? implode(', ', $allErrors) : 'Check server logs for details';
            error_log("Folder rename failed: $oldFolder -> $newFolder. Errors: " . ($errorMsg ?: 'None'));
            return Response::error('Failed to rename folder: ' . $errorMsg, 500);
        }
        
        // Post-rename cascade. The IMAP rename already SUCCEEDED above, so
        // every step from here on is best-effort: a failure must NEVER
        // turn an already-renamed mailbox into a 500 the user sees.
        // We catch \Throwable (not just \Exception) on every step so a
        // fatal TypeError / undefined-method / DB-shape mismatch in a
        // helper can't bubble out of the controller.
        $updatedFilters = 0;
        $cacheInvalidated = 0;
        $conversationsUpdated = 0;
        $identityRenameApplied = false;

        // 1. Update any filters that reference this folder.
        try {
            $filterService = new FilterService($this->config);
            $activeEmail = $this->getActiveEmail();
            $updatedFilters = $filterService->updateFolderReferences($activeEmail, $oldFolder, $newFolder);
            if ($updatedFilters > 0) {
                error_log("Updated $updatedFilters filter(s) after renaming folder '$oldFolder' to '$newFolder'");
            }
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] filter update failed: " . $e->getMessage());
        }

        // 2. Apply canonical identity rename synchronously. We have the
        // authoritative (oldPath, newPath) right now, so we don't need
        // to wait for the async snapshot analyzer to figure it out by
        // diffing. Falls back silently if the identity row doesn't yet
        // exist (folder was never listed) or migration 164 isn't applied.
        try {
            $svc = $this->getFolderIndexService();
            if ($svc !== null && !empty($this->userEmail)) {
                $existing = $svc->getByPath($this->userEmail, $oldFolder);
                if ($existing && !empty($existing['id'])) {
                    $identityRenameApplied = $svc->applyRename(
                        $this->userEmail,
                        (string) $existing['id'],
                        $oldFolder,
                        $newFolder,
                        null,
                        null,
                        null,
                        $this->getDualWriteTelemetry()
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] identity rename skipped: " . $e->getMessage());
        }

        // 3. Cascade the rename to webmail_folder_index (which still keys
        // by path), invalidate Redis caches for both old and new paths,
        // and broadcast the folder-renamed event to connected clients.
        // Conversation/conversation-member rows now key by folder_id and
        // need no rename cascade -- folder_id is stable.
        try {
            $cache = $this->getRedisCache();
            $cacheInvalidated = $cache->handleFolderRename($this->userEmail, $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] Redis handleFolderRename failed: " . $e->getMessage());
        }

        try {
            $conversationService = $this->getConversationService();
            $conversationsUpdated = $conversationService->updateFolderName($this->userEmail, $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] updateFolderName failed: " . $e->getMessage());
        }

        try {
            $cache = $this->getRedisCache();
            $cache->publishFolderChanged($this->userEmail, 'renamed', $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] publishFolderChanged failed: " . $e->getMessage());
        }

        try {
            $cache = $this->getRedisCache();
            $invalidator = new FolderCacheInvalidator($cache);
            $invalidator->invalidate($this->userEmail ?? '', $oldFolder, FolderCacheInvalidator::REASON_RENAME);
            $invalidator->invalidate($this->userEmail ?? '', $newFolder, FolderCacheInvalidator::REASON_RENAME);
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] FolderCacheInvalidator failed: " . $e->getMessage());
        }

        error_log("[MailboxController::renameFolder] $oldFolder -> $newFolder | "
            . "filters=$updatedFilters cache_keys=$cacheInvalidated "
            . "conversations=$conversationsUpdated identity=" . ($identityRenameApplied ? '1' : '0'));

        return Response::success([
            'folder' => $newFolder,
            'filters_updated' => $updatedFilters,
            'cache_invalidated' => $cacheInvalidated,
            'conversations_updated' => $conversationsUpdated,
            'identity_rename_applied' => $identityRenameApplied,
        ], 'Folder renamed');
    }

    /**
     * Get conversation thread (includes related sent emails)
     * GET /mailbox/thread
     * 
     * Parameters:
     * - subject: The email subject to search for
     * - message_id: A specific Message-ID to find related messages
     * - references: JSON array of Message-IDs from the References header
     * - current_folder: The folder the user is currently in (to mark is_sent correctly)
     */
    public function getThread(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $subject = $request->getQuery('subject');
        $messageId = $request->getQuery('message_id');
        $referencesJson = $request->getQuery('references');
        $currentFolder = $request->getQuery('current_folder', 'INBOX');
        
        // Parse references array
        $references = [];
        if ($referencesJson) {
            $decoded = json_decode($referencesJson, true);
            if (is_array($decoded)) {
                $references = $decoded;
            }
        }
        
        if (!$subject && !$messageId && empty($references)) {
            return Response::error('Subject, message_id, or references is required', 400);
        }
        
        $messages = [];
        $seenMessageIds = []; // Track seen Message-IDs to avoid duplicates
        
        // Normalize subject for search
        $normalizedSubject = $subject;
        if ($normalizedSubject) {
            // Remove Re:, Fwd:, etc.
            $normalizedSubject = preg_replace('/^(Re|Fwd|Fw|Válasz|Továbbítás):\s*/i', '', $normalizedSubject);
            $normalizedSubject = preg_replace('/^(Re|Fwd|Fw|Válasz|Továbbítás)\[\d+\]:\s*/i', '', $normalizedSubject);
            $normalizedSubject = trim($normalizedSubject);
        }
        
        // Build list of all Message-IDs to search for (from references + the message itself)
        $searchMessageIds = $references;
        if ($messageId && !in_array($messageId, $searchMessageIds)) {
            $searchMessageIds[] = $messageId;
        }
        
        // Folders to search: INBOX, Sent, Drafts, and user folders
        $foldersToSearch = [];
        $folders = $this->imap->listFolders();
        
        // Prioritize common folders
        $priorityTypes = ['inbox', 'sent', 'drafts'];
        foreach ($priorityTypes as $type) {
            foreach ($folders as $folder) {
                if ($folder['type'] === $type) {
                    $foldersToSearch[] = $folder['name'];
                }
            }
        }
        
        // Also search the current folder if not already included
        if (!in_array($currentFolder, $foldersToSearch)) {
            array_unshift($foldersToSearch, $currentFolder);
        }
        
        // Search each folder for related messages
        foreach ($foldersToSearch as $folder) {
            try {
                $folderMessages = [];
                
                // DO NOT search by subject - it causes unrelated emails to be grouped
                // Only use Message-ID based threading (like Gmail)
                
                // Search for messages that reference any of our Message-IDs
                // This catches replies to our messages
                foreach ($searchMessageIds as $searchId) {
                    if (empty($searchId)) continue;
                    
                    // Clean the message ID (remove angle brackets, whitespace, quotes)
                    $cleanId = trim($searchId);
                    $cleanId = trim($cleanId, '<>"\'');
                    $cleanId = trim($cleanId); // Trim again after removing brackets
                    
                    if (empty($cleanId)) continue;
                    
                    // Search for messages with this Message-ID in their References header
                    try {
                        $refResults = $this->imap->searchHeader($folder, 'References', $cleanId);
                        $folderMessages = array_merge($folderMessages, $refResults);
                    } catch (\Exception $e) {
                        // Some servers don't support header search, ignore
                        error_log("Header search (References) failed: " . $e->getMessage());
                    }
                    
                    // Also search In-Reply-To header
                    try {
                        $replyResults = $this->imap->searchHeader($folder, 'In-Reply-To', $cleanId);
                        $folderMessages = array_merge($folderMessages, $replyResults);
                    } catch (\Exception $e) {
                        error_log("Header search (In-Reply-To) failed: " . $e->getMessage());
                    }
                    
                    // Also search for the message ID itself (this message)
                    try {
                        $msgIdResults = $this->imap->searchHeader($folder, 'Message-ID', $cleanId);
                        $folderMessages = array_merge($folderMessages, $msgIdResults);
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
                
                // Deduplicate and mark folder source
                foreach ($folderMessages as $msg) {
                    // Normalize message_id (remove angle brackets and whitespace)
                    $msgId = $msg['message_id'] ?? null;
                    if ($msgId) {
                        $msgId = trim($msgId, '<> ');
                    }
                    
                    // Use multiple keys for deduplication:
                    // 1. Normalized message_id
                    // 2. UID + folder combination
                    $uidKey = $msg['uid'] . '-' . $folder;
                    
                    // Skip if we've seen this message_id OR this uid+folder combo
                    if (($msgId && isset($seenMessageIds['mid:' . $msgId])) || 
                        isset($seenMessageIds['uid:' . $uidKey])) {
                        continue;
                    }
                    
                    $msg['folder'] = $folder;
                    $msg['is_sent'] = ($folder !== $currentFolder);
                    $messages[] = $msg;
                    
                    // Mark both keys as seen
                    if ($msgId) {
                        $seenMessageIds['mid:' . $msgId] = true;
                    }
                    $seenMessageIds['uid:' . $uidKey] = true;
                }
            } catch (\Exception $e) {
                error_log("Error searching folder $folder for thread: " . $e->getMessage());
            }
        }
        
        // Sort by timestamp (oldest first for chronological view)
        usort($messages, function($a, $b) {
            return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
        });
        
        return Response::success([
            'messages' => $messages,
            'count' => count($messages),
            'subject' => $subject,
            'thread_ids' => $searchMessageIds,
        ]);
    }

    /**
     * Search messages
     * GET /mailbox/search
     */
    public function search(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        // Reset and capture a request_id so every downstream structured log
        // line on this turn is greppable by the same id.
        CorrelationId::reset();
        $requestId = CorrelationId::current();

        $folder = $request->getQuery('folder', 'INBOX');
        $query = $request->getQuery('q', '');
        $allFolders = $request->getQuery('all_folders') === 'true';
        
        $filters = [
            'from' => $request->getQuery('from'),
            'to' => $request->getQuery('to'),
            'since' => $request->getQuery('since'),
            'before' => $request->getQuery('before'),
            'unread' => $request->getQuery('unread') === '1',
            'flagged' => $request->getQuery('flagged') === '1',
        ];

        $messages = [];
        $labelFilters = [];
        $paginationData = null;
        $degradedFolders = [];

        // Phase 3 special operator: `mentions:me` — strip from the IMAP
        // query (IMAP doesn't know this operator) and remember the
        // mentioned-message-id whitelist to post-filter with, exactly the
        // same way the label-filter post-filter works further down.
        //
        // We only strip the exact token; mixed queries like `mentions:me
        // from:alice` keep `from:alice` going through to IMAP and apply
        // the mention whitelist on top.
        $mentionWhitelistIds = null;
        if (preg_match('/(?:^|\s)mentions\s*:\s*me\b/i', $query)) {
            try {
                $mentionsSvc = new \Webmail\Services\Mentions\MentionsService($this->config);
                $mentionWhitelistIds = $mentionsSvc->getMessageIdsWhereMentioned($this->getActiveEmail(), 500);
            } catch (\Throwable $e) {
                error_log('[MailboxController::search] mentions lookup failed: ' . $e->getMessage());
                $mentionWhitelistIds = [];
            }
            $query = trim(preg_replace('/(?:^|\s)mentions\s*:\s*me\b/i', ' ', $query) ?? '');
            // Forcing all-folders mode: mentions arrive in whatever folder
            // the sync threw them in; scoping to INBOX would silently miss
            // mentions that landed in custom folders or rules-filed mail.
            if (!$allFolders) {
                $allFolders = true;
            }
            // Empty whitelist = zero results, but we still let the rest of
            // the search pipeline run (it will produce 0 messages, then the
            // post-filter on the empty whitelist will keep it at 0).
        }

        // Special operator: `is:pinned` — IMAP has no native pin flag, so we
        // strip the token from the IMAP query and remember a (folder,uid)
        // whitelist sourced from the `pinned_emails` table. Mirrors the
        // mentions:me pattern above. Mixed queries like
        // `is:pinned has:attachment` keep `has:attachment` going through to
        // IMAP and apply the pin whitelist on top.
        $pinnedWhitelistKeys = null;
        if (preg_match('/(?:^|\s)is\s*:\s*pinned\b/i', $query)) {
            try {
                $db = $this->getPinnedDb();
                // Project the current path string via the identity JOIN so
                // the whitelist key shape matches the IMAP-side $msg[folder]
                // string the post-filter on line ~3300 compares against.
                $stmt = $db->prepare(
                    "SELECT fi.current_path AS folder, pe.uid
                     FROM pinned_emails pe
                     LEFT JOIN webmail_folder_identity fi ON fi.id = pe.folder_id
                     WHERE pe.user_email = ?"
                );
                $stmt->execute([$this->userEmail]);
                $pinnedWhitelistKeys = [];
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $pinnedWhitelistKeys[($row['folder'] ?? '') . ':' . ($row['uid'] ?? '')] = true;
                }
            } catch (\Throwable $e) {
                error_log('[MailboxController::search] pinned lookup failed: ' . $e->getMessage());
                $pinnedWhitelistKeys = [];
            }
            $query = trim(preg_replace('/(?:^|\s)is\s*:\s*pinned\b/i', ' ', $query) ?? '');
            // Pinned emails can live in ANY folder (Inbox, custom folders,
            // even Sent). Force all-folders mode so we don't silently miss
            // pins outside the active folder, same reasoning as mentions:me.
            if (!$allFolders) {
                $allFolders = true;
            }
            // Empty whitelist = zero results; the rest of the pipeline still
            // runs and gets zeroed out by the post-filter below.
        }
        
        if ($allFolders) {
            $page = (int)$request->getQuery('page', 1);
            $limit = (int)$request->getQuery('limit', 50);
            $limit = max(10, min(100, $limit));
            $page = max(1, $page);
            
            $hasSearchQuery = !empty($query) || !empty(array_filter($filters));
            
            $folders = $this->imap->listFolders();
            $eligibleFolders = array_filter($folders, function($f) {
                return !in_array($f['type'], ['drafts', 'trash', 'spam', 'sent']);
            });

            $folderCount = count($eligibleFolders);
            error_log("[ALLMAIL] Starting scan: {$folderCount} eligible folders for {$this->userEmail}");
            
            if ($hasSearchQuery) {
                // Search mode: use existing search with results from all folders
                $this->imap->resetFolderSelectCounter();
                $searchIdx = 0;
                foreach ($eligibleFolders as $f) {
                    $searchIdx++;
                    if ($searchIdx > 1 && $searchIdx % 20 === 1) {
                        $this->imap->reconnectIfStale();
                    }
                    $folderMessages = $this->imap->search($f['name'], $query, array_filter($filters));
                    if (empty($labelFilters)) {
                        $labelFilters = $this->imap->getLastSearchLabelFilters();
                    }
                    foreach ($folderMessages as &$msg) {
                        $msg['folder'] = $f['name'];
                        $msg['folder_display'] = $f['display_name'] ?? $f['name'];
                    }
                    $messages = array_merge($messages, $folderMessages);
                }
                usort($messages, function($a, $b) {
                    return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
                });
                $total = count($messages);
                $pages = $total > 0 ? (int)ceil($total / $limit) : 0;
                $page = max(1, min($page, max(1, $pages)));
                $offset = ($page - 1) * $limit;
                $messages = array_slice($messages, $offset, $limit);
                $paginationData = ['page' => $page, 'pages' => $pages, 'total' => $total, 'limit' => $limit];
            } else {
                // All Mail browse mode: collect UIDs+dates from all folders, paginate, then fetch details
                $allEntries = [];
                $folderDisplayNames = [];
                $scannedCount = 0;
                $totalMessages = 0;

                $accountKey = $this->getAccountKeyForBreaker();
                $cache = $this->getRedisCache();
                $breaker = new CircuitBreaker($cache);
                $stateMachine = new FolderStateMachine($cache);

                // Wave 2: best-effort folder-identity upsert so folder_id
                // populates organically as users browse All Mail. Failure
                // here MUST NOT break the scan; it is purely additive.
                $folderIndex = null;
                try {
                    $folderIndex = new FolderIndexService($this->config);
                } catch (\Throwable $e) {
                    error_log('[MailboxController] FolderIndexService init failed: ' . $e->getMessage());
                }

                $this->imap->resetFolderSelectCounter();

                foreach ($eligibleFolders as $f) {
                    $folderName = $f['name'];
                    $folderDisplayNames[$folderName] = $f['display_name'] ?? $folderName;
                    $scannedCount++;

                    if ($folderIndex !== null) {
                        try {
                            $folderId = $folderIndex->upsertFromListing($this->userEmail ?? '', $f);
                            // Annotate the row in place so downstream payloads
                            // can flow folder_id through to the client.
                            $f['folder_id'] = $folderId;
                        } catch (\Throwable $e) {
                            error_log('[MailboxController] folder index upsert failed for ' . $folderName . ': ' . $e->getMessage());
                        }
                    }

                    if ($scannedCount > 1 && $scannedCount % 20 === 1) {
                        $this->imap->reconnectIfStale();
                    }

                    // Circuit breaker: if this folder is in cooldown, skip the
                    // fetch but still surface it via degraded_folders so the
                    // never-silently-drop invariant holds.
                    $breakerInspect = $breaker->inspect($accountKey, $folderName);
                    if ($breakerInspect['state'] === CircuitBreaker::STATE_OPEN) {
                        $stateMachine->transition($accountKey, $folderName, FolderStateMachine::QUARANTINED, [
                            'account_id' => $this->userEmail,
                            'fallback_stage' => null,
                            'reason' => 'circuit_breaker_open',
                            'retry_after' => $breakerInspect['retry_after'],
                        ]);
                        $degradedFolders[] = $this->buildDegradedEntry(
                            $folderName,
                            $folderDisplayNames[$folderName],
                            FolderStateMachine::QUARANTINED,
                            (int) ($f['total'] ?? 0),
                            0,
                            [],
                            0,
                            null,
                            'circuit_breaker_open: failures within 10 minute window',
                            $breakerInspect['retry_after'],
                            $requestId
                        );
                        continue;
                    }

                    $entries = $this->imap->getUidsWithTimestamps($folderName);
                    $meta = $this->imap->getLastScanMeta();
                    $entryCount = count($entries);
                    $folderTotal = (int) ($f['total'] ?? ($meta['total'] ?? 0));

                    $isDegraded = ($meta['state'] ?? 'healthy') !== 'healthy';
                    if ($isDegraded) {
                        // Record one failure with the breaker; if the threshold
                        // is reached the folder will be quarantined next turn.
                        $bres = $breaker->recordFailure($accountKey, $folderName);
                        $effectiveState = $bres['state'] === CircuitBreaker::STATE_OPEN
                            ? FolderStateMachine::QUARANTINED
                            : FolderStateMachine::DEGRADED;

                        $stateMachine->transition($accountKey, $folderName, $effectiveState, [
                            'account_id' => $this->userEmail,
                            'fallback_stage' => $meta['fallback_stage'] ?? null,
                            'reason' => $meta['failure_reason'] ?? 'unknown',
                            'retry_after' => $bres['retry_after'] ?? null,
                        ]);
                        $degradedFolders[] = $this->buildDegradedEntry(
                            $folderName,
                            $folderDisplayNames[$folderName],
                            $effectiveState,
                            $folderTotal,
                            $entryCount,
                            $meta['bad_uids'] ?? [],
                            (int) ($meta['bad_uids_truncated_count'] ?? 0),
                            $meta['fallback_stage'] ?? null,
                            $meta['failure_reason'] ?? null,
                            $bres['retry_after'] ?? null,
                            $requestId
                        );
                    } else {
                        $stateMachine->transition($accountKey, $folderName, FolderStateMachine::HEALTHY, [
                            'account_id' => $this->userEmail,
                            'fallback_stage' => $meta['fallback_stage'] ?? null,
                        ]);
                        $breaker->recordSuccess($accountKey, $folderName);
                    }

                    $totalMessages += $entryCount;
                    foreach ($entries as &$entry) {
                        $entry['folder'] = $folderName;
                    }
                    unset($entry);
                    $allEntries = array_merge($allEntries, $entries);
                }

                StructuredLog::emit('allmail_scan_complete', [
                    'user_email' => $this->userEmail,
                    'scan_mode' => 'all_mail',
                    'folder_count' => $scannedCount,
                    'message_count' => $totalMessages,
                    'degraded_count' => count($degradedFolders),
                    'duration_ms' => 0, // duration tracked per-folder in scan meta
                ]);

                // Invariant: any folder visible in imap_list is represented in
                // the response. Either it produced entries (healthy folder), or
                // it appears in degraded_folders[]. Anything else is a silent
                // drop and a regression.
                $coveredFolders = [];
                foreach ($eligibleFolders as $f) {
                    $coveredFolders[$f['name']] = false;
                }
                foreach ($allEntries as $e) {
                    if (isset($e['folder'])) {
                        $coveredFolders[$e['folder']] = true;
                    }
                }
                foreach ($degradedFolders as $d) {
                    if (isset($d['folder_path'])) {
                        $coveredFolders[$d['folder_path']] = true;
                    }
                }
                foreach ($coveredFolders as $name => $covered) {
                    if ($covered) {
                        continue;
                    }
                    // Empty-but-listed folder. Surface as healthy with 0 retrieved.
                    // We add nothing to $allEntries (it has no messages) but we DO
                    // want to flag this in logs so future regressions are easy
                    // to spot.
                    $rowTotal = 0;
                    foreach ($eligibleFolders as $f) {
                        if ($f['name'] === $name) {
                            $rowTotal = (int) ($f['total'] ?? 0);
                            break;
                        }
                    }
                    if ($rowTotal > 0) {
                        // Folder claims to have messages but produced no entries
                        // and didn't declare itself degraded. That is the bug
                        // we are fixing; record it loudly.
                        $degradedFolders[] = $this->buildDegradedEntry(
                            $name,
                            $folderDisplayNames[$name] ?? $name,
                            FolderStateMachine::DEGRADED,
                            $rowTotal,
                            0,
                            [],
                            0,
                            null,
                            'invariant: folder listed but produced no entries and no degraded meta',
                            null,
                            $requestId
                        );
                        StructuredLog::emit('allmail_invariant_violation', [
                            'folder_path' => $name,
                            'reason' => 'listed_but_no_entries_and_not_degraded',
                            'user_email' => $this->userEmail,
                        ]);
                    }
                }
                
                usort($allEntries, function($a, $b) {
                    return $b['timestamp'] - $a['timestamp'];
                });
                
                $total = count($allEntries);
                $pages = $total > 0 ? (int)ceil($total / $limit) : 0;
                $page = max(1, min($page, max(1, $pages)));
                $offset = ($page - 1) * $limit;
                $pageEntries = array_slice($allEntries, $offset, $limit);
                
                // Group by folder for efficient batch fetching
                $byFolder = [];
                $orderMap = [];
                foreach ($pageEntries as $idx => $entry) {
                    $byFolder[$entry['folder']][] = $entry['uid'];
                    $orderMap[$entry['folder'] . ':' . $entry['uid']] = $idx;
                }
                
                // Fetch full details for page entries, grouped by folder
                $orderedMessages = array_fill(0, count($pageEntries), null);
                foreach ($byFolder as $folderName => $uids) {
                    $folderMessages = $this->imap->getMessageDetailsByUids($folderName, $uids);
                    foreach ($folderMessages as $msg) {
                        $msg['folder'] = $folderName;
                        $msg['folder_display'] = $folderDisplayNames[$folderName] ?? $folderName;
                        $key = $folderName . ':' . $msg['uid'];
                        if (isset($orderMap[$key])) {
                            $orderedMessages[$orderMap[$key]] = $msg;
                        }
                    }
                }
                
                $messages = array_values(array_filter($orderedMessages, fn($m) => $m !== null));
                $paginationData = ['page' => $page, 'pages' => $pages, 'total' => $total, 'limit' => $limit];
                
                // On page 1, ensure pinned emails are always included even
                // if they're too old for the current page. The path string
                // is resolved at read time via the identity JOIN so the
                // downstream $existingKeys[folder:uid] comparison still
                // matches what IMAP gave us via $msg['folder'].
                if ($page === 1) {
                    try {
                        $db = $this->getPinnedDb();
                        $stmt = $db->prepare(
                            "SELECT pe.folder_id, fi.current_path AS folder, pe.uid
                             FROM pinned_emails pe
                             LEFT JOIN webmail_folder_identity fi ON fi.id = pe.folder_id
                             WHERE pe.user_email = ?
                             ORDER BY pe.pinned_at DESC"
                        );
                        $stmt->execute([$this->userEmail]);
                        $pins = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                        
                        if (!empty($pins)) {
                            $existingKeys = [];
                            foreach ($messages as $msg) {
                                $existingKeys[$msg['folder'] . ':' . $msg['uid']] = true;
                            }
                            
                            $missingPinsByFolder = [];
                            foreach ($pins as $pin) {
                                // Skip orphaned pins where the folder was
                                // deleted (LEFT JOIN returns folder=NULL).
                                if (empty($pin['folder'])) {
                                    continue;
                                }
                                $key = $pin['folder'] . ':' . $pin['uid'];
                                if (!isset($existingKeys[$key])) {
                                    $missingPinsByFolder[$pin['folder']][] = (int)$pin['uid'];
                                }
                            }
                            
                            if (!empty($missingPinsByFolder)) {
                                $pinnedMessages = [];
                                foreach ($missingPinsByFolder as $pinFolder => $pinUids) {
                                    $pinDetails = $this->imap->getMessageDetailsByUids($pinFolder, $pinUids);
                                    foreach ($pinDetails as $pinMsg) {
                                        $pinMsg['folder'] = $pinFolder;
                                        $pinMsg['folder_display'] = $folderDisplayNames[$pinFolder] ?? $pinFolder;
                                        $pinnedMessages[] = $pinMsg;
                                    }
                                }
                                $messages = array_merge($pinnedMessages, $messages);
                            }
                        }
                    } catch (\Exception $e) {
                        // Non-critical: pinned emails merge failed, continue with normal results
                    }
                }
            }
        } else {
            $messages = $this->imap->search($folder, $query, array_filter($filters));
            $labelFilters = $this->imap->getLastSearchLabelFilters();
        }
        
        // Filter by labels if specified (labels are stored in database, not IMAP)
        if (!empty($labelFilters)) {
            $labelService = new LabelService($this->config);
            $activeEmail = $this->getActiveEmail();
            
            // Get message IDs that have the required labels
            $labeledMessageIds = $labelService->getMessageIdsWithLabels($activeEmail, $labelFilters);
            
            if (empty($labeledMessageIds)) {
                // No messages have these labels
                $messages = [];
            } else {
                // Filter messages to only those with matching labels
                $messages = array_filter($messages, function($msg) use ($labeledMessageIds) {
                    $msgId = trim($msg['message_id'] ?? '', '<>');
                    return in_array($msgId, $labeledMessageIds);
                });
                $messages = array_values($messages); // Re-index array
            }
        }

        // Phase 3: post-filter by `mentions:me` whitelist.
        // Empty whitelist → zero results (the user has never been mentioned
        // yet, so the smart view is intentionally empty rather than wrong).
        if ($mentionWhitelistIds !== null) {
            if (empty($mentionWhitelistIds)) {
                $messages = [];
            } else {
                $whitelistMap = array_flip($mentionWhitelistIds);
                $messages = array_values(array_filter($messages, function($msg) use ($whitelistMap) {
                    $msgId = trim($msg['message_id'] ?? '', '<>');
                    return isset($whitelistMap[$msgId]);
                }));
            }
        }

        // Post-filter by `is:pinned` whitelist (folder:uid composite keys).
        // Same shape as the mentions filter above. Empty whitelist → zero
        // results so an account with no pins shows an empty Pinned view
        // rather than every message in the mailbox.
        if ($pinnedWhitelistKeys !== null) {
            if (empty($pinnedWhitelistKeys)) {
                $messages = [];
            } else {
                $messages = array_values(array_filter($messages, function($msg) use ($pinnedWhitelistKeys) {
                    $key = ($msg['folder'] ?? '') . ':' . ($msg['uid'] ?? '');
                    return isset($pinnedWhitelistKeys[$key]);
                }));
            }
        }
        
        // Attach labels to search results (like in messages endpoint)
        if (!empty($messages)) {
            try {
                $labelService = new LabelService($this->config);
                $activeEmail = $this->getActiveEmail();
                $messageIds = array_filter(array_map(function($m) {
                    return trim($m['message_id'] ?? '', '<>');
                }, $messages));
                
                if (!empty($messageIds)) {
                    $labelsMap = $labelService->getMessageLabelsForList($activeEmail, $messageIds);
                    foreach ($messages as &$message) {
                        $msgId = trim($message['message_id'] ?? '', '<>');
                        $message['labels'] = $labelsMap[$msgId] ?? [];
                    }
                }
            } catch (\Exception $e) {
                error_log("Failed to attach labels to search results: " . $e->getMessage());
            }
        }

        // Enrich replied status from conversation DB
        $this->enrichRepliedStatus($messages);

        $responseData = [
            'messages' => $messages,
            'count' => count($messages),
            'query' => $query,
            'folder' => $allFolders ? 'ALL' : $folder,
            'all_folders' => $allFolders,
            'request_id' => $requestId,
        ];

        if ($paginationData) {
            $responseData['page'] = $paginationData['page'];
            $responseData['pages'] = $paginationData['pages'];
            $responseData['total'] = $paginationData['total'];
            $responseData['limit'] = $paginationData['limit'];
        }

        if ($allFolders) {
            // Always emit the field on All Mail responses (empty when healthy)
            // so the frontend can ignore it cleanly when there is nothing to
            // surface.
            $responseData['degraded_folders'] = $degradedFolders;
        }

        return Response::success($responseData);
    }

    /**
     * Build one degraded_folders[] entry per the Wave 1 payload contract.
     * folder_id is null in Wave 1; populated in Wave 2.
     *
     * @param int[] $badUids
     */
    private function buildDegradedEntry(
        string $folderPath,
        string $displayName,
        string $state,
        int $total,
        int $retrieved,
        array $badUids,
        int $badUidsTruncatedCount,
        ?string $fallbackStage,
        ?string $failureReason,
        ?int $retryAfter,
        string $requestId
    ): array {
        // Best-effort canonical identity attach. Degraded folders are rare,
        // so the extra lookup cost is negligible and lets the frontend
        // route degraded entries via /m/<folder_id>.
        $folderId = $this->resolveFolderId($folderPath);
        return [
            'folder_path' => $folderPath,
            'folder_display' => $displayName,
            'folder_id' => $folderId,
            'state' => $state,
            'total' => $total,
            'retrieved' => $retrieved,
            'bad_uids' => array_values(array_slice($badUids, 0, 50)),
            'bad_uids_truncated_count' => $badUidsTruncatedCount,
            'last_attempt_at' => gmdate('c'),
            'retry_after' => $retryAfter !== null ? gmdate('c', $retryAfter) : null,
            'failure_reason' => $failureReason,
            'fallback_stage' => $fallbackStage,
            'request_id' => $requestId,
        ];
    }

    /**
     * Account key for circuit-breaker / state-machine Redis namespacing.
     * Uses the same hashing helper as the rest of the cache so keys are
     * consistent.
     */
    private function getAccountKeyForBreaker(): string
    {
        $email = $this->userEmail ?? 'anonymous';
        return $this->getRedisCache()->getUserHash($email);
    }

    /**
     * Find trash folder name - prioritize INBOX.Deleted Items (Dovecot standard)
     */
    private function findTrashFolder(): ?string
    {
        $folders = $this->imap->listFolders();
        
        // Debug: log all folders
        $folderNames = array_column($folders, 'name');
        error_log("findTrashFolder: Available folders: " . json_encode($folderNames));
        
        // Priority order for trash folders (INBOX.Deleted Items is our standard)
        $priorityNames = [
            'INBOX.Deleted Items',
            'Deleted Items',
            'INBOX.Trash',
            'Trash', 
            'Deleted',
            'Deleted Messages'
        ];
        
        // Check in priority order
        foreach ($priorityNames as $trashName) {
            foreach ($folders as $folder) {
                if (strcasecmp($folder['name'], $trashName) === 0) {
                    error_log("findTrashFolder: Found match: {$folder['name']}");
                    return $folder['name'];
                }
            }
        }
        
        // Fallback: return first folder with trash type
        foreach ($folders as $folder) {
            if ($folder['type'] === 'trash') {
                error_log("findTrashFolder: Found by type: {$folder['name']}");
                return $folder['name'];
            }
        }
        
        // Create INBOX.Deleted Items if no trash folder exists
        error_log("findTrashFolder: No trash folder found, creating INBOX.Deleted Items");
        if ($this->imap->createFolder('INBOX.Deleted Items')) {
            return 'INBOX.Deleted Items';
        }
        
        error_log("findTrashFolder: Failed to create trash folder");
        return null;
    }
    
    /**
     * Restore message from Trash to original folder or INBOX
     */
    public function restoreMessage(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }
        
        $folder = $this->getResolvedFolder($request, 'message_restore');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        $uid = (int)$request->getParam('uid');
        $targetFolder = $this->normalizeFolderName($request->input('target_folder', 'INBOX'));
        
        $success = $this->imap->moveMessage($folder, $uid, $targetFolder);
        $newUid = $this->imap->getLastMoveNewUid();
        
        if ($success) {
            // Sync conversation database with the new UID
            try {
                $conversationService = $this->getConversationService();
                $conversationService->moveConversationMember($this->userEmail, $folder, $uid, $targetFolder, $newUid);
            } catch (\Exception $e) {
                error_log("[MailboxController::restoreMessage] Failed to sync conversation database: " . $e->getMessage());
            }
            
            // Invalidate caches for source + target
            $cache = $this->getRedisCache();
            $cache->invalidateMessage($this->userEmail, $folder, $uid);
            $cache->invalidateFolder($this->userEmail, $folder);
            $cache->invalidateFolder($this->userEmail, $targetFolder);
            
            // Publish real-time event for WebSocket sync
            $cache->publishMessageMoved($this->userEmail, $folder, $targetFolder, $uid, $newUid);
            
            return Response::success(['new_uid' => $newUid], 'Message restored');
        }
        
        return Response::error('Failed to restore message');
    }
    
    /**
     * Restore all messages from Trash to INBOX
     */
    public function restoreAllFromTrash(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }
        
        $folder = $this->getResolvedFolder($request, 'restore_all');
        if ($folder === null) return Response::error('Folder not found', 404);
        $folder = $this->normalizeFolderName($folder);
        $targetFolder = $this->normalizeFolderName($request->input('target_folder', 'INBOX'));
        
        // Get all messages in trash
        $messages = $this->imap->getMessages($folder, 1, 5000, 'date', true);
        $restored = 0;
        $failed = 0;
        $conversationService = null;
        
        try {
            $conversationService = $this->getConversationService();
        } catch (\Exception $e) {
            error_log("[MailboxController::restoreAllFromTrash] Failed to get conversation service: " . $e->getMessage());
        }
        
        $cache = $this->getRedisCache();
        
        foreach ($messages['messages'] ?? [] as $msg) {
            if ($this->imap->moveMessage($folder, $msg['uid'], $targetFolder)) {
                $newUid = $this->imap->getLastMoveNewUid();
                $restored++;
                // Sync conversation database
                if ($conversationService) {
                    try {
                        $conversationService->moveConversationMember($this->userEmail, $folder, $msg['uid'], $targetFolder, $newUid);
                    } catch (\Exception $e) {
                        // Log but continue
                    }
                }
                $cache->invalidateMessage($this->userEmail, $folder, $msg['uid']);
                $cache->publishMessageMoved($this->userEmail, $folder, $targetFolder, $msg['uid'], $newUid);
            } else {
                $failed++;
            }
        }
        
        // Invalidate caches
        $cache->invalidateFolder($this->userEmail, $folder);
        $cache->invalidateFolder($this->userEmail, $targetFolder);
        
        return Response::success([
            'restored' => $restored,
            'failed' => $failed
        ], "Restored $restored messages");
    }
    
    /**
     * Clean folder - move all messages to Trash (batch processing)
     * POST /mailbox/clean-folder
     */
    public function cleanFolder(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }
        
        $folder = $request->input('folder');
        $targetFolder = $request->input('targetFolder');
        $batchSize = (int)$request->input('batchSize', 50);
        $page = (int)$request->input('page', 1);
        
        if (!$folder) {
            return Response::error('Source folder is required', 400);
        }
        
        if (!$targetFolder) {
            return Response::error('Target folder is required', 400);
        }
        
        $folder = $this->normalizeFolderName($folder);
        $targetFolder = $this->normalizeFolderName($targetFolder);
        
        // Don't allow cleaning trash or spam (use empty instead)
        $folderLower = strtolower($folder);
        if (strpos($folderLower, 'trash') !== false || strpos($folderLower, 'spam') !== false || strpos($folderLower, 'junk') !== false) {
            return Response::error('Use Empty for Trash/Spam folders', 400);
        }
        
        // Get messages for this batch
        $messages = $this->imap->getMessages($folder, 1, $batchSize, 'date', 'desc');
        $total = $messages['total'] ?? 0;
        $moved = 0;
        $failed = 0;
        
        $conversationService = null;
        try {
            $conversationService = $this->getConversationService();
        } catch (\Exception $e) {
            error_log("[MailboxController::cleanFolder] Failed to get conversation service: " . $e->getMessage());
        }
        
        $cache = $this->getRedisCache();
        
        foreach ($messages['messages'] ?? [] as $msg) {
            if ($this->imap->moveMessage($folder, $msg['uid'], $targetFolder)) {
                $newUid = $this->imap->getLastMoveNewUid();
                $moved++;
                // Sync conversation database
                if ($conversationService) {
                    try {
                        $conversationService->moveConversationMember($this->userEmail, $folder, $msg['uid'], $targetFolder, $newUid);
                    } catch (\Exception $e) {
                        // Log but continue
                    }
                }
                $cache->invalidateMessage($this->userEmail, $folder, $msg['uid']);
                $cache->publishMessageMoved($this->userEmail, $folder, $targetFolder, $msg['uid'], $newUid);
            } else {
                $failed++;
            }
        }
        
        // Invalidate caches
        $cache->invalidateFolder($this->userEmail, $folder);
        $cache->invalidateFolder($this->userEmail, $targetFolder);
        
        // Publish WebSocket events for folder count updates
        if ($moved > 0) {
            try {
                // Get updated folder counts from IMAP and publish via WebSocket
                $sourceFolderStatus = $this->imap->getStatus($folder);
                if ($sourceFolderStatus) {
                    $cache->publishFolderCounts(
                        $this->userEmail,
                        $folder,
                        $sourceFolderStatus['messages'] ?? 0,
                        $sourceFolderStatus['unseen'] ?? 0,
                        $sourceFolderStatus['uidnext'] ?? null,
                        $sourceFolderStatus['uidvalidity'] ?? null
                    );
                }
                
                $targetFolderStatus = $this->imap->getStatus($targetFolder);
                if ($targetFolderStatus) {
                    $cache->publishFolderCounts(
                        $this->userEmail,
                        $targetFolder,
                        $targetFolderStatus['messages'] ?? 0,
                        $targetFolderStatus['unseen'] ?? 0,
                        $targetFolderStatus['uidnext'] ?? null,
                        $targetFolderStatus['uidvalidity'] ?? null
                    );
                }
            } catch (\Exception $e) {
                error_log("[MailboxController::cleanFolder] Failed to publish folder counts: " . $e->getMessage());
            }
        }
        
        // Check if there are more messages remaining
        $remaining = max(0, $total - $moved);
        
        return Response::success([
            'moved' => $moved,
            'failed' => $failed,
            'total' => $total,
            'remaining' => $remaining,
            'hasMore' => $remaining > 0
        ]);
    }
    
    /**
     * Handle email unsubscribe action
     * POST /mailbox/unsubscribe
     */
    public function unsubscribe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $type = $request->input('type'); // 'mailto' or 'https'
        $url = $request->input('url');
        $email = $request->input('email');
        $oneClick = (bool)$request->input('one_click', false);
        
        if (!$type) {
            return Response::error('Unsubscribe type is required', 400);
        }
        
        // Handle mailto: unsubscribe
        if ($type === 'mailto') {
            if (!$email) {
                return Response::error('Unsubscribe email address is required', 400);
            }
            
            return $this->handleMailtoUnsubscribe($email, $request);
        }
        
        // Handle https: unsubscribe
        if ($type === 'https') {
            if (!$url) {
                return Response::error('Unsubscribe URL is required', 400);
            }
            
            // Always open URL for HTTPS - one-click is unreliable as many servers
            // return 200 for confirmation pages instead of actually unsubscribing.
            // Let the user confirm they completed the unsubscribe on the website.
            return Response::success([
                'action' => 'open_url',
                'url' => $url
            ], 'Open URL to unsubscribe');
        }
        
        return Response::error('Invalid unsubscribe type', 400);
    }
    
    /**
     * Send unsubscribe email via mailto:
     */
    private function handleMailtoUnsubscribe(string $mailtoUrl, Request $request): Response
    {
        // Parse mailto URL - format: unsubscribe@example.com?subject=unsub&body=...
        $parts = parse_url('mailto:' . $mailtoUrl);
        $toEmail = $parts['path'] ?? '';
        
        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Invalid unsubscribe email address', 400);
        }
        
        // Parse query parameters for subject and body
        $subject = 'Unsubscribe';
        $body = 'Please unsubscribe me from this mailing list.';
        
        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            if (isset($queryParams['subject'])) {
                $subject = urldecode($queryParams['subject']);
            }
            if (isset($queryParams['body'])) {
                $body = urldecode($queryParams['body']);
            }
        }
        
        try {
            $fromEmail = $this->userEmail;
            if (!$fromEmail) {
                return Response::error('Not authenticated', 401);
            }
            $fromName = $fromEmail;
            
            $smtpService = new \Webmail\Services\SmtpService($this->config['smtp']);
            
            if ($this->userPassword) {
                $smtpService->setCredentials($fromEmail, $this->userPassword);
            } elseif ($this->isOAuthSession && $this->oauthProvider) {
                $accessToken = null;
                if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                    $accessToken = $this->microsoftOAuthService->getValidAccessToken($fromEmail, $fromEmail);
                } elseif ($this->googleOAuthService) {
                    $accessToken = $this->googleOAuthService->getValidAccessToken($fromEmail, $fromEmail);
                }
                
                if ($accessToken) {
                    $smtpService->setOAuthCredentials($fromEmail, $accessToken, $this->oauthProvider);
                } else {
                    return Response::error('OAuth token expired. Please re-authenticate.', 401);
                }
            } else {
                return Response::error('No credentials available. Please log in again.', 401);
            }
            
            // Send unsubscribe email
            $result = $smtpService->send([
                'from_name' => $fromName,
                'to' => [['email' => $toEmail, 'name' => '']],
                'cc' => [],
                'bcc' => [],
                'subject' => $subject,
                'body_text' => $body,
                'body_html' => '',
                'attachments' => []
            ]);
            
            if ($result['success']) {
                return Response::success([
                    'action' => 'email_sent',
                    'to' => $toEmail
                ], 'Unsubscribe email sent successfully');
            } else {
                error_log("Unsubscribe send failed: " . ($result['error'] ?? 'Unknown error'));
                return Response::error('Failed to send unsubscribe email: ' . ($result['error'] ?? 'Unknown error'), 500);
            }
            
        } catch (\Exception $e) {
            error_log("Unsubscribe mailto error: " . $e->getMessage());
            return Response::error('Failed to send unsubscribe email: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Handle one-click unsubscribe via POST request (RFC 8058)
     */
    private function handleOneClickUnsubscribe(string $url): Response
    {
        try {
            // Validate URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return Response::error('Invalid unsubscribe URL', 400);
            }
            
            // Only allow https URLs for security
            if (strpos($url, 'https://') !== 0) {
                // Fall back to opening URL in browser for non-https
                return Response::success([
                    'action' => 'open_url',
                    'url' => $url
                ], 'Open URL to unsubscribe');
            }
            
            // Make POST request with List-Unsubscribe=One-Click body
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'List-Unsubscribe=One-Click',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: Webmail/1.0'
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                error_log("One-click unsubscribe curl error: $error");
                // Fall back to opening URL
                return Response::success([
                    'action' => 'open_url',
                    'url' => $url
                ], 'Could not auto-unsubscribe, please visit the link');
            }
            
            // Success codes are typically 200-299
            if ($httpCode >= 200 && $httpCode < 300) {
                return Response::success([
                    'action' => 'unsubscribed',
                    'http_code' => $httpCode
                ], 'Successfully unsubscribed');
            }
            
            // For other codes, fall back to opening URL
            return Response::success([
                'action' => 'open_url',
                'url' => $url,
                'http_code' => $httpCode
            ], 'Please complete unsubscribe in browser');
            
        } catch (\Exception $e) {
            error_log("One-click unsubscribe error: " . $e->getMessage());
            // Fall back to opening URL
            return Response::success([
                'action' => 'open_url',
                'url' => $url
            ], 'Could not auto-unsubscribe, please visit the link');
        }
    }
    
    /**
     * Normalize folder name (handle INBOX prefix for subfolders)
     * Converts 'inbox.work.greyskull' to 'INBOX.work.greyskull'
     */
    /**
     * Parse iCalendar data into structured event info
     */
    private function parseCalendarEvent(string $icalData): ?array
    {
        // Step 1: Unfold lines (RFC 5545: CRLF + whitespace = continuation)
        $raw = preg_replace('/\r?\n[ \t]/', '', $icalData);
        $lines = preg_split('/\r?\n/', $raw);
        
        $event = [];
        $inEvent = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') { $inEvent = true; continue; }
            if ($line === 'END:VEVENT') { $inEvent = false; continue; }
            
            // Parse METHOD from VCALENDAR level
            if (!$inEvent && preg_match('/^METHOD:(.+)$/i', $line, $m)) {
                $event['method'] = trim($m[1]);
                continue;
            }
            
            if (!$inEvent) continue;
            
            // Split property into name;params:value
            if (!preg_match('/^([A-Z-]+)(;[^:]*)?:(.*)$/i', $line, $m)) continue;
            
            $prop = strtoupper($m[1]);
            $params = $m[2] ?? '';
            $value = trim($m[3]);
            
            switch ($prop) {
                case 'SUMMARY':
                    $event['summary'] = $this->unescapeIcal($value);
                    break;
                case 'DTSTART':
                    $event['dtstart'] = $this->parseIcalDate($value, $params);
                    $event['dtstart_raw'] = $value;
                    break;
                case 'DTEND':
                    $event['dtend'] = $this->parseIcalDate($value, $params);
                    $event['dtend_raw'] = $value;
                    break;
                case 'LOCATION':
                    $event['location'] = $this->unescapeIcal($value);
                    break;
                case 'DESCRIPTION':
                    $event['description'] = $this->unescapeIcal($value);
                    break;
                case 'STATUS':
                    $event['status'] = $value;
                    break;
                case 'ORGANIZER':
                    $email = preg_replace('/^mailto:/i', '', $value);
                    $name = '';
                    if (preg_match('/CN=("?)([^";]+)\1/i', $params, $cn)) {
                        $name = $cn[2];
                    }
                    $event['organizer'] = $name ? "{$name} <{$email}>" : $email;
                    $event['organizer_email'] = $email;
                    break;
                case 'ATTENDEE':
                    $email = preg_replace('/^mailto:/i', '', $value);
                    $name = '';
                    if (preg_match('/CN=("?)([^";]+)\1/i', $params, $cn)) {
                        $name = $cn[2];
                    }
                    $event['attendees'] = $event['attendees'] ?? [];
                    $event['attendees'][] = [
                        'name' => $name,
                        'email' => $email,
                        'display' => $name ? "{$name} <{$email}>" : $email,
                    ];
                    break;
            }
        }
        
        return !empty($event) ? $event : null;
    }

    private function unescapeIcal(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
    }

    private function parseIcalDate(string $value, string $params = ''): string
    {
        $tz = null;
        if (preg_match('/TZID=([^;:]+)/i', $params, $tzm)) {
            $tz = $tzm[1];
        }
        
        $value = rtrim($value, 'Z');
        
        try {
            if (strlen($value) === 8) {
                $dt = \DateTime::createFromFormat('Ymd', $value);
                return $dt ? $dt->format('Y-m-d') : $value;
            }
            $dt = \DateTime::createFromFormat('Ymd\THis', $value);
            if ($dt && $tz) {
                try { $dt->setTimezone(new \DateTimeZone($tz)); } catch (\Exception $e) {}
            }
            return $dt ? $dt->format('Y-m-d H:i') : $value;
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Render iCalendar data as HTML for display
     */
    private function renderCalendarInvite(string $icalData): string
    {
        $event = $this->parseCalendarEvent($icalData);
        if (!$event) {
            return '<p style="color: #666; font-style: italic;">Calendar invite (could not parse)</p>';
        }

        $summary = htmlspecialchars($event['summary'] ?? 'Meeting Invitation');
        $method = strtoupper($event['method'] ?? 'REQUEST');
        $status = $event['status'] ?? '';
        
        $methodLabel = match($method) {
            'REQUEST' => 'Meeting Invitation',
            'CANCEL' => 'Meeting Cancelled',
            'REPLY' => 'Meeting Response',
            'COUNTER' => 'Counter Proposal',
            default => 'Calendar Event',
        };

        $methodColor = match($method) {
            'CANCEL' => '#ef4444',
            'REQUEST' => '#8b5cf6',
            default => '#6b7280',
        };

        $html = '<div class="calendar-invite-card" style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; max-width: 520px; margin: 16px 0;">';
        $html .= '<div style="background: rgba(139, 92, 246, 0.08); border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 12px; padding: 24px;">';
        
        // Header badge
        $html .= '<div style="margin-bottom: 16px;">';
        $html .= '<span style="display: inline-block; font-size: 11px; font-weight: 700; color: ' . $methodColor . '; text-transform: uppercase; letter-spacing: 1px; padding: 4px 10px; background: rgba(139, 92, 246, 0.15); border-radius: 6px;">' . $methodLabel . '</span>';
        $html .= '</div>';
        
        // Title
        $html .= '<div style="font-size: 20px; font-weight: 600; color: inherit; margin-bottom: 20px;">' . $summary . '</div>';
        
        // Details table
        $html .= '<table style="width: 100%; border-collapse: collapse;">';
        
        $labelStyle = 'padding: 8px 16px 8px 0; opacity: 0.55; font-size: 13px; vertical-align: top; white-space: nowrap; font-weight: 500;';
        $valueStyle = 'padding: 8px 0; color: inherit; font-size: 14px; font-weight: 500;';
        
        if (!empty($event['dtstart'])) {
            $html .= '<tr><td style="' . $labelStyle . '">Start</td>';
            $html .= '<td style="' . $valueStyle . '">' . htmlspecialchars($event['dtstart']) . '</td></tr>';
        }
        if (!empty($event['dtend'])) {
            $html .= '<tr><td style="' . $labelStyle . '">End</td>';
            $html .= '<td style="' . $valueStyle . '">' . htmlspecialchars($event['dtend']) . '</td></tr>';
        }
        if (!empty($event['location'])) {
            $html .= '<tr><td style="' . $labelStyle . '">Location</td>';
            $html .= '<td style="' . $valueStyle . '">' . htmlspecialchars($event['location']) . '</td></tr>';
        }
        if (!empty($event['organizer'])) {
            $html .= '<tr><td style="' . $labelStyle . '">Organizer</td>';
            $html .= '<td style="' . $valueStyle . '">' . htmlspecialchars($event['organizer']) . '</td></tr>';
        }
        if (!empty($event['attendees'])) {
            $attendeeNames = array_map(fn($a) => htmlspecialchars(is_array($a) ? ($a['name'] ?: $a['email']) : $a), $event['attendees']);
            $html .= '<tr><td style="' . $labelStyle . '">Attendees</td>';
            $html .= '<td style="' . $valueStyle . '">' . implode(', ', $attendeeNames) . '</td></tr>';
        }
        if (!empty($event['description'])) {
            $desc = htmlspecialchars(mb_substr($event['description'], 0, 500));
            $desc = nl2br($desc);
            $html .= '<tr><td style="' . $labelStyle . '">Details</td>';
            $html .= '<td style="' . $valueStyle . '">' . $desc . '</td></tr>';
        }
        
        $html .= '</table>';
        
        // Add to Calendar button
        $html .= '<div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid rgba(139, 92, 246, 0.15);">';
        $html .= '<a href="#add-to-calendar" data-action="add-to-calendar" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; background: #8b5cf6; color: #fff; border-radius: 999px; text-decoration: none; font-size: 14px; font-weight: 500; cursor: pointer; transition: opacity 0.15s;">Add to Calendar</a>';
        $html .= '</div>';
        
        $html .= '</div></div>';
        
        return $html;
    }

    private function normalizeFolderName(string $folder): string
    {
        // Reject virtual/frontend-only folder names that don't exist on IMAP
        $virtualFolders = ['ALL_MAIL', 'SEARCH_RESULTS', 'SCHEDULED'];
        if (in_array($folder, $virtualFolders, true)) {
            error_log("WARNING: Virtual folder '{$folder}' passed to backend - this is a frontend bug.");
        }
        
        // If it starts with lowercase 'inbox.', convert to 'INBOX.'
        if (stripos($folder, 'inbox.') === 0 && substr($folder, 0, 6) !== 'INBOX.') {
            return 'INBOX.' . substr($folder, 6);
        }
        
        // If it's just 'inbox' (case-insensitive), convert to 'INBOX'
        if (strcasecmp($folder, 'inbox') === 0) {
            return 'INBOX';
        }
        
        // Try to resolve actual folder name via case-insensitive IMAP lookup
        // This handles lowercased folder names from URL routing (e.g. [gmail]/bin → [Gmail]/Bin)
        $actual = $this->findActualFolderName($folder);
        if ($actual !== null) {
            return $actual;
        }
        
        return $folder;
    }
    
    /**
     * Find the actual folder name from the folder list (case-insensitive match)
     * Returns the matched folder name or null if not found
     */
    private function findActualFolderName(string $folder): ?string
    {
        if (!$this->imap || !$this->imap->isConnected()) {
            return null;
        }
        
        $folders = $this->imap->listFolders();
        $lowerFolder = strtolower($folder);
        
        foreach ($folders as $f) {
            if (strtolower($f['name']) === $lowerFolder) {
                return $f['name'];
            }
        }
        
        return null;
    }
    
    /**
     * Get Redis cache statistics
     * GET /mailbox/cache/stats
     */
    public function cacheStats(Request $request): Response
    {
        try {
            // Require authentication
            $authError = $this->requireAuth($request);
            if ($authError) {
                return $authError;
            }
            
            $userEmail = $this->userEmail;
            $cache = $this->getRedisCache();
            
            // Get user-specific stats
            $userStats = $cache->getUserStats($userEmail);
            
            // Get server info
            $serverInfo = $cache->getServerInfo();
            
            // Get TTL configuration
            $config = $this->config['redis'] ?? [];
            $ttlConfig = $config['ttl'] ?? [
                'message' => 3600,
                'conversation' => 300,
                'folder_status' => 120,
                'thumbnail' => 86400,
            ];
            
            return Response::success([
                'available' => $cache->isAvailable(),
                'server' => $serverInfo,
                'user' => $userStats,
                'ttl' => $ttlConfig,
            ]);
        } catch (\Throwable $e) {
            error_log("cacheStats error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return Response::error('Cache stats error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Clear user's Redis cache
     * POST /mailbox/cache/clear
     * 
     * Body:
     * - type: 'all' | 'messages' | 'conversations' | 'folders' | 'thumbnails'
     */
    public function clearCache(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $userEmail = $this->userEmail;
        $type = $request->input('type', 'all');
        
        $cache = $this->getRedisCache();
        $cleared = 0;
        
        if (!$cache->isAvailable()) {
            return Response::error('Redis not available', 503);
        }
        
        $userHash = $cache->getUserHash($userEmail);
        
        switch ($type) {
            case 'all':
                $cleared = $cache->invalidateUser($userEmail);
                break;
            case 'messages':
                $cleared = $cache->deletePattern("{$userHash}:msg:*");
                $cleared += $cache->deletePattern("{$userHash}:msglist:*");
                break;
            case 'conversations':
                $cleared = $cache->deletePattern("{$userHash}:conv:*");
                break;
            case 'folders':
                $cleared = $cache->deletePattern("{$userHash}:folder*");
                break;
            case 'thumbnails':
                $cleared = $cache->deletePattern("drive:{$userHash}:*:thumb");
                break;
            default:
                return Response::error('Invalid cache type', 400);
        }
        
        return Response::success([
            'cleared' => $cleared,
            'type' => $type,
        ], "Cleared $cleared cache entries");
    }

    /**
     * Proxy a remote image to protect user privacy.
     * Prevents sender from learning user IP, referrer, or access timing.
     */
    public function imageProxy(Request $request): Response
    {
        $url = $request->getQuery('url');
        if (!$url) {
            return Response::error('Missing url parameter', 400);
        }

        $url = urldecode($url);

        try {
            $proxy = new \Webmail\Services\RemoteImageProxyService();
            $result = $proxy->fetch($url);

            header('Content-Type: ' . $result['content_type']);
            header('Cache-Control: public, max-age=' . $result['cache_seconds']);
            header('X-Content-Type-Options: nosniff');
            echo $result['data'];
            exit;
        } catch (\RuntimeException $e) {
            error_log("[MailboxController::imageProxy] Failed: " . $e->getMessage() . " URL: " . substr($url, 0, 200));
            return Response::error('Image proxy failed', 502);
        }
    }

    /**
     * Enrich messages with replied status from conversation_members table.
     * If a message's conversation contains a member from a Sent-like folder,
     * mark the non-sent messages as answered (the user replied in that thread).
     */
    private function enrichRepliedStatus(array &$messages): void
    {
        if (empty($messages)) return;

        $messageIds = array_filter(array_column($messages, 'message_id'));
        if (empty($messageIds)) return;

        try {
            $db = \Webmail\Core\Database::getConnection($this->config);

            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $params = array_merge([$this->userEmail], $messageIds);

            $sql = "SELECT DISTINCT cm1.message_id
                    FROM webmail_conversation_members cm1
                    JOIN webmail_conversation_members cm2
                      ON cm2.user_email = cm1.user_email
                     AND cm2.conversation_id = cm1.conversation_id
                     AND LOWER(cm2.folder) LIKE '%sent%'
                     AND LOWER(cm2.folder) NOT LIKE '%unsent%'
                    WHERE cm1.user_email = ?
                      AND cm1.message_id IN ({$placeholders})";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $repliedIds = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
            $repliedSet = array_flip($repliedIds);

            foreach ($messages as &$msg) {
                if (!empty($msg['message_id']) && isset($repliedSet[$msg['message_id']])) {
                    $msg['answered'] = true;
                }
            }
        } catch (\Exception $e) {
            error_log("[MailboxController::enrichRepliedStatus] " . $e->getMessage());
        }
    }
}

