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
use Webmail\Services\OutboxService;
use Webmail\Services\MailboxWriteService;
use Webmail\Addons\AIAssistant\Services\AIService;

class MailboxController extends BaseController
{
    private ?RedisCacheService $redisCache = null;
    private ?ConversationService $conversationService = null;
    private ?FolderIndexService $folderIndexService = null;
    private ?DualWriteTelemetry $dualWriteTelemetry = null;
    private ?OutboxService $outboxService = null;
    private ?MailboxWriteService $writeService = null;

    /**
     * Lazy-load the OutboxService used by Phase 2 write paths.
     * Every UI-initiated state mutation (mark-read, move, delete, rename)
     * enqueues an outbox row inside the same transaction as the DB-side
     * write. The Node mailsync worker drains the queue to IMAP with
     * retry/backoff, so the request returns in <50ms regardless of IMAP
     * latency.
     */
    private function getOutboxService(): OutboxService
    {
        if ($this->outboxService === null) {
            $this->outboxService = new OutboxService($this->config);
        }
        return $this->outboxService;
    }

    /**
     * Phase 5 write-split: lazy-load the write service that centralises
     * the DB-first commit pattern (begin tx -> conv update -> outbox
     * enqueue -> commit) shared by setFlag/move/delete + batch variants.
     */
    private function getWriteService(): MailboxWriteService
    {
        if ($this->writeService === null) {
            $this->writeService = new MailboxWriteService(
                $this->config,
                \Webmail\Core\Database::getConnection($this->config),
                $this->getConversationService(),
                $this->getOutboxService(),
                $this->getRedisCache()
            );
        }
        return $this->writeService;
    }
    
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
        
        // IMAP single source of truth: the list always comes from live IMAP.
        // The DB mirror is a cache only - the IMAP path warms it below
        // (assignMessagesToConversations) but it is never served as truth.
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

        error_log("MailboxController::messages - folder: $folder, source: imap, total: " . ($result['total'] ?? 'null') . ", messages count: " . count($result['messages'] ?? []));

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
        
        // AUTO-ASSIGN: warm the conversation cache from the IMAP result and
        // return the rollup so the UI gets conversations in one request.
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

        // In-flight overlay: reconcile each message's `seen` with our DB so an
        // in-app read that has not yet drained to IMAP does not re-appear as
        // unread (and re-trigger auto-mark-read). This trusts the DB ONLY for
        // UIDs with a pending outbox op; otherwise IMAP wins (see reconcileSeen).
        $this->applyReadStateOverlay($folder, $result['messages']);

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

        // IMAP single source of truth: the incremental list comes from live IMAP.
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

            // AUTO-ASSIGN: warm the conversation cache from the IMAP result
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

            // In-flight overlay (see messages()).
            $this->applyReadStateOverlay($folder, $result['messages']);
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

        // IMAP single source of truth: the delta - new messages, flag changes
        // and counts - is computed live from IMAP below (STATUS for counts,
        // getMessagesSince for new, CONDSTORE for flag changes). There is no
        // mirror delta path: serving counts/flags from the DB is exactly what
        // let a transient mirror is_seen/updated_at emit a spurious unread
        // flagChange (the read->unread + 0->4->0 jumps).
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

                    // Read-side DB-as-truth overlay (see messages()).
                    $this->applyReadStateOverlay($folder, $result['newMessages']);

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
                // IMAP -> DB: persist IMAP's flag truth into our mirror before
                // we hand the (outbox-filtered) view to the client.
                $this->persistImapFlagChanges($folder, $flagResult['changes']);
                $result['flagChanges'] = $this->filterFlagChangesAgainstOutbox($folder, $flagResult['changes']);
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

        // IMAP -> DB: persist IMAP's flag truth into our mirror so the DB stays
        // faithful even for reads/unreads made on other devices.
        $this->persistImapFlagChanges($folder, $result['changes']);

        // Also return current sync state so client can update its stored values
        $syncState = $this->imap->getFolderSyncState();
        
        return Response::success([
            'folder' => $folder,
            'changes' => $this->filterFlagChangesAgainstOutbox($folder, $result['changes']),
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
     * Mailbox storage quota (Dovecot/IMAP GETQUOTAROOT).
     * GET /mailbox/quota
     *
     * Returns the user's enforced mailbox storage limit + current usage so the
     * sidebar can render a usage card (mirrors the Drive quota card). Only
     * IMAP servers that advertise the QUOTA extension (our Dovecot) return a
     * real limit; OAuth providers (Gmail/Microsoft) or unlimited mailboxes
     * report enabled=false and the frontend hides the card.
     *
     * Response: { enabled, used_bytes, limit_bytes, unlimited }
     */
    public function quota(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $result = [
            'enabled'     => false,
            'used_bytes'  => 0,
            'limit_bytes' => 0,
            'unlimited'   => true,
        ];

        try {
            $conn = $this->imap->getRawConnection();

            // imap_get_quotaroot() only works on a real c-client IMAP
            // connection (IMAP\Connection in PHP 8.1+, an 'imap' resource on
            // older builds). OAuth accounts use a raw stream socket, which has
            // no quota concept here, so we leave the card disabled for them.
            $isImapConn = ($conn instanceof \IMAP\Connection)
                || (is_resource($conn) && get_resource_type($conn) === 'imap');

            if (function_exists('imap_get_quotaroot') && $isImapConn) {
                $quota = @imap_get_quotaroot($conn, 'INBOX');
                if (is_array($quota)) {
                    // PHP exposes STORAGE either nested under 'STORAGE' or at
                    // the top level depending on version; values are in KB.
                    $storage = isset($quota['STORAGE']) && is_array($quota['STORAGE'])
                        ? $quota['STORAGE']
                        : $quota;
                    $usageKb = isset($storage['usage']) ? (int)$storage['usage'] : 0;
                    $limitKb = isset($storage['limit']) ? (int)$storage['limit'] : 0;

                    $result['used_bytes'] = $usageKb * 1024;
                    if ($limitKb > 0) {
                        $result['limit_bytes'] = $limitKb * 1024;
                        $result['unlimited']   = false;
                        $result['enabled']     = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[MailboxController::quota] ' . $e->getMessage());
        }

        return Response::success($result);
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
     * Per-user outbox health for the "sync issues" banner.
     * GET /mailbox/sync-issues
     *
     * Returns counts of pending / failed / dead outbox rows plus the age of
     * the oldest pending row. The frontend surfaces a non-blocking banner
     * when dead > 0 (writes that exhausted all retries and never reached
     * IMAP), with a retry affordance.
     */
    public function outboxStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        try {
            $stats = $this->getOutboxService()->getUserQueueStats($this->userEmail);
        } catch (\Throwable $e) {
            error_log('[MailboxController::outboxStats] ' . $e->getMessage());
            $stats = ['pending' => 0, 'failed' => 0, 'dead' => 0, 'oldest_pending_age_sec' => null];
        }
        return Response::success($stats);
    }

    /**
     * Phase 4 sync-health observability.
     * GET /mailbox/sync-stats
     *
     * Returns per-status counts of webmail_folder_sync_state rows plus
     * an "attention" list of folders that are either failed or have
     * not had a successful incremental pass in the last 15 minutes.
     * Drives the sync-issues banner the UI shows when the mirror is
     * lagging behind IMAP.
     */
    public function syncStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        try {
            $svc = new \Webmail\Services\MailboxSyncService($this->config);
            $stats = $svc->getUserSyncStats($this->userEmail);
        } catch (\Throwable $e) {
            error_log('[MailboxController::syncStats] ' . $e->getMessage());
            $stats = [
                'synced'            => 0,
                'pending'           => 0,
                'initial_syncing'   => 0,
                'failed'            => 0,
                'uidvalidity_reset' => 0,
                'total_folders'     => 0,
                'attention_folders' => [],
            ];
        }
        return Response::success($stats);
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
            $message['calendar_event'] = $this->attachInviteResponse(
                $this->parseCalendarEvent($message['body_calendar'])
            );
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

        // Auto-mark as read only if not already read.
        // Phase 2: DB-first via the outbox. The IMAP `\Seen` flag is written
        // asynchronously by the mailsync worker; we just mark the message
        // seen in our DB and publish the FLAGS_CHANGED event so the UI
        // (and any other open device) updates instantly.
        if (empty($message['seen'])) {
            $folderId = $this->resolveFolderId($folder);
            if ($folderId === null) {
                // Folder identity not yet established -- fall back to legacy direct write.
                try {
                    $this->imap->setFlag($folder, $uid, 'seen', true);
                    $cache = $this->getRedisCache();
                    $cache->invalidateMessage($this->userEmail, $folder, $uid);
                    $cache->publishFlagsChanged($this->userEmail, $folder, $uid, [
                        'flag' => 'seen', 'value' => true, 'imapFlags' => ['\\Seen'],
                    ]);
                    $this->getConversationService()->updateMemberReadStatus($this->userEmail, $folder, $uid, true);
                } catch (\Exception $e) {
                    error_log("[MailboxController::message] Legacy auto-mark-read failed: " . $e->getMessage());
                }
            } else {
                $db = \Webmail\Core\Database::getConnection($this->config);
                // Construct services BEFORE beginTransaction: their constructors
                // run DDL (ensureTablesExist) which implicit-commits any open
                // transaction in MySQL/MariaDB. See setFlag() for the full note.
                $conv = $this->getConversationService();
                $outbox = $this->getOutboxService();
                try {
                    $db->beginTransaction();
                    $conv->updateMemberReadStatus(
                        $this->userEmail,
                        $folder,
                        $uid,
                        true
                    );
                    $outbox->enqueue([
                        'user_email'    => $this->userEmail,
                        'account_email' => $this->userEmail,
                        'op'            => 'set_flag',
                        'folder_id'     => $folderId,
                        'uid'           => $uid,
                        'nonce'         => $this->resolveOpNonce($request),
                        'payload'       => [
                            'flag'        => 'seen',
                            'value'       => true,
                            'imap_flag'   => '\\Seen',
                            // Worker needs the IMAP folder path to open the
                            // mailbox lock; folder_id alone is not resolvable
                            // pump-side.
                            'source_path' => $folder,
                        ],
                    ]);
                    $db->commit();

                    $cache = $this->getRedisCache();
                    $cache->invalidateMessage($this->userEmail, $folder, $uid);
                    $cache->publishFlagsChanged($this->userEmail, $folder, $uid, [
                        'flag' => 'seen', 'value' => true, 'imapFlags' => ['\\Seen'],
                    ]);
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log("[MailboxController::message] Auto-mark-read enqueue failed: " . $e->getMessage());
                }
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

        // Parse the inbound body for @mentions and persist them so the
        // per-message mention chips can render who was tagged. Triggers a
        // per-user dedup'd in-app notification if the active user was
        // actually @-mentioned and they have `notify_on_mention` ON (default).
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
                    $message['calendar_event'] = $this->attachInviteResponse(
                        $this->parseCalendarEvent($message['body_calendar'])
                    );
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

        // Read-side DB-as-truth overlay (see messages()).
        $this->applyReadStateOverlay($folder, $messages);
        
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
                            $message['calendar_event'] = $this->attachInviteResponse(
                                $this->parseCalendarEvent($message['body_calendar'])
                            );
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

        // Phase 2: DB-first write. We commit the read-state change to MariaDB
        // (and recompute conversation unread_count) in a single transaction,
        // then enqueue an outbox row that the mailsync worker drains to IMAP
        // asynchronously. The request returns in <50ms regardless of IMAP
        // latency; the user's UI updates instantly via the FLAGS_CHANGED
        // pub/sub event we publish right after the commit.
        //
        // Idempotency: the outbox row's hash key includes (user, op, folder,
        // uid) and a per-day nonce, so duplicate clicks collapse into a
        // single IMAP write. The worker also has its own idempotency guard.
        //
        // Resolving folder_id once up front so both the DB write and the
        // outbox enqueue can use it (the legacy ConversationService
        // resolves internally, which is wasteful here).
        //
        // Prefer the canonical folder_id that getResolvedFolder() already
        // resolved from the request: id-based routes carry it directly and
        // it came from the same resolve that produced $folder. Re-resolving
        // the *normalized* path via FolderIndexService::getByPath() is lossy
        // -- normalizeFolderName() can rewrite the string so getByPath() no
        // longer matches, which wrongly forced the legacy IMAP fallback and
        // surfaced to the client as a silent 400. Only fall back to a path
        // lookup for legacy path-based routes that never supplied a folder_id.
        $folderId = $request->getParam('folder_id');
        if ($folderId === null || $folderId === '') {
            $folderId = $this->resolveFolderId($folder);
        }
        if ($folderId === null || $folderId === '') {
            // Without a stable folder identity we cannot enqueue a durable
            // outbox row -- worker would not know which IMAP folder to
            // operate against. Fall back to the legacy direct IMAP write
            // so we never regress on edge cases (folder freshly created,
            // identity service init failed, etc.).
            return $this->legacyDirectImapSetFlag($folder, $uid, $flag, $value);
        }

        // The DB-first transaction (begin tx -> conv update -> outbox
        // enqueue -> commit) is centralised in MailboxWriteService.
        // The service's constructor instantiates ConversationService /
        // OutboxService BEFORE the tx opens, so the DDL-implicit-commit
        // bug that produced the "Failed to set flag" 400s cannot
        // happen here.
        $result = $this->getWriteService()->commitFlag(
            $this->userEmail,
            $folder,
            (string)$folderId,
            $uid,
            $flag,
            $value,
            $this->resolveOpNonce($request)
        );
        if (!($result['ok'] ?? false)) {
            return Response::error($result['error'] ?? 'Failed to set flag');
        }

        // Post-commit best-effort cache + pubsub. These are NOT part of the
        // transaction because Redis is not transactional with MySQL, and
        // a Redis blip should not roll back the user's UI state change.
        $cache = $this->getRedisCache();
        $cache->invalidateMessage($this->userEmail, $folder, $uid);
        $this->getWriteService()->publishFlagEvent($this->userEmail, $folder, $uid, $flag, $value);

        return Response::success(null, 'Flag updated');
    }

    /**
     * Legacy direct-IMAP setFlag, used only when folder identity is not
     * yet established for this folder. The Phase 2 outbox path requires a
     * stable folder_id; this fallback preserves correctness for the
     * narrow window between first-discovery and FolderIndexService upsert.
     */
    private function legacyDirectImapSetFlag(string $folder, int $uid, string $flag, bool $value): Response
    {
        $success = $this->imap->setFlag($folder, $uid, $flag, $value);
        if (!$success) {
            error_log("[MailboxController::legacyDirectImapSetFlag] imap->setFlag returned false: folder=$folder, uid=$uid, flag=$flag, value=" . ($value ? 'true' : 'false'));
            return Response::error('Failed to set flag (IMAP write rejected)');
        }

        $cache = $this->getRedisCache();
        $cache->invalidateMessage($this->userEmail, $folder, $uid);

        // Keep the DB mirror in agreement for both user-toggled flags. These
        // are best-effort and no-op when the folder has no identity row yet
        // (such folders read live from IMAP, so there is nothing to go stale);
        // once the identity is established a later edit routes through the
        // durable outbox path. We mirror flagged too for parity with
        // MailboxWriteService::commitFlag so a star never reverts.
        $flagLower = strtolower($flag);
        if ($flagLower === 'seen') {
            try {
                $this->getConversationService()->updateMemberReadStatus(
                    $this->userEmail,
                    $folder,
                    $uid,
                    $value
                );
            } catch (\Exception $e) {
                error_log("[MailboxController::legacyDirectImapSetFlag] DB sync failed: " . $e->getMessage());
            }
        } elseif ($flagLower === 'flagged') {
            try {
                $this->getConversationService()->updateMemberFlagStatus(
                    $this->userEmail,
                    $folder,
                    $uid,
                    $value
                );
            } catch (\Exception $e) {
                error_log("[MailboxController::legacyDirectImapSetFlag] DB flag sync failed: " . $e->getMessage());
            }
        }

        $imapFlag = '\\' . ucfirst(strtolower($flag));
        $cache->publishFlagsChanged($this->userEmail, $folder, $uid, [
            'flag' => $flag,
            'value' => $value,
            'imapFlags' => $value ? [$imapFlag] : [],
        ]);

        return Response::success(null, 'Flag updated');
    }

    /**
     * Drop CONDSTORE flag changes for UIDs that still have an unconfirmed
     * local flag write in the outbox. The user's intent (already reflected
     * in our DB + optimistic UI) wins until the drainer pushes it to IMAP;
     * otherwise a poll during the in-flight window re-applies IMAP's stale
     * pre-write flag and the message "jumps back" to its old read state.
     */
    private function filterFlagChangesAgainstOutbox(string $folder, array $changes): array
    {
        if (empty($changes)) {
            return $changes;
        }
        $folderId = $this->resolveFolderId($folder);
        if ($folderId === null) {
            return $changes;
        }
        $uids = [];
        foreach ($changes as $c) {
            if (isset($c['uid'])) {
                $uids[] = (int)$c['uid'];
            }
        }
        if (empty($uids)) {
            return $changes;
        }
        try {
            $pending = $this->getOutboxService()->pendingFlagUids($this->userEmail, $folderId, $uids);
        } catch (\Throwable $e) {
            error_log('[MailboxController::filterFlagChangesAgainstOutbox] ' . $e->getMessage());
            return $changes;
        }
        if (empty($pending)) {
            return $changes;
        }
        $pendingSet = array_flip($pending);
        return array_values(array_filter(
            $changes,
            fn($c) => !isset($pendingSet[(int)($c['uid'] ?? -1)])
        ));
    }

    /**
     * Read-side DB-as-truth overlay.
     *
     * The IMAP message list reports each message's `seen` from the live
     * \Seen flag. That flag is eventually-consistent with our DB: when a
     * user reads (or unreads) a message in-app we commit is_seen to MariaDB
     * immediately but only push \Seen to IMAP asynchronously via the outbox
     * worker. In the window before the worker drains -- or if a past write
     * never reached IMAP -- the IMAP flag is stale and a just-read message
     * re-appears as unread, so the client re-fires auto-mark-read and the
     * row visibly flaps. This overlay makes the DB win on reads, matching
     * the DB-as-truth write path.
     *
     * Per-UID reconciliation:
     *   - UID with a pending/running outbox flag op: that row is the user's
     *     latest un-drained intent (covers mark-as-UNREAD too), so the DB
     *     value wins outright.
     *   - Otherwise: seen = IMAP_seen OR DB_seen. This renders as read both
     *     a message read on another device (IMAP \Seen set, our DB row never
     *     updated) and a message read in-app (DB is_seen=1, IMAP not yet
     *     updated), while leaving genuinely-unread messages (both false)
     *     untouched.
     *
     * UIDs with no member row in the DB are left exactly as IMAP reported.
     * Mutates $messages in place. Best-effort: any failure leaves the
     * IMAP-reported state intact.
     *
     * @param string $folder    normalized IMAP folder path
     * @param array  $messages  list of message arrays (each with 'uid','seen')
     */
    private function applyReadStateOverlay(string $folder, array &$messages): void
    {
        if (empty($messages)) {
            return;
        }
        $uids = [];
        foreach ($messages as $m) {
            if (isset($m['uid'])) {
                $uids[] = (int)$m['uid'];
            }
        }
        if (empty($uids)) {
            return;
        }

        try {
            $seenMap = $this->getConversationService()->getReadStateMap($this->userEmail, $folder, $uids);
        } catch (\Throwable $e) {
            error_log('[MailboxController::applyReadStateOverlay] read-state load failed: ' . $e->getMessage());
            return;
        }
        if (empty($seenMap)) {
            return;
        }

        // In-flight user intent (read OR unread) wins over IMAP for these UIDs.
        $pendingSet = [];
        $folderId = $this->resolveFolderId($folder);
        if ($folderId !== null) {
            try {
                $pendingSet = array_flip(
                    $this->getOutboxService()->pendingFlagUids($this->userEmail, $folderId, $uids)
                );
            } catch (\Throwable $e) {
                // Non-fatal: fall back to OR semantics for every UID.
            }
        }

        foreach ($messages as &$m) {
            if (!isset($m['uid'])) {
                continue;
            }
            $uid = (int)$m['uid'];
            if (!array_key_exists($uid, $seenMap)) {
                continue;
            }
            $m['seen'] = self::reconcileSeen(
                !empty($m['seen']),
                $seenMap[$uid],
                isset($pendingSet[$uid])
            );
        }
        unset($m);
    }

    /**
     * IMAP -> DB persistence: write CONDSTORE-detected flag changes into our
     * database so MariaDB stays a faithful mirror of IMAP. This is the other
     * half of DB-as-truth: the outbox pushes our writes TO IMAP, and this
     * pulls IMAP's truth (including reads/unreads made on other devices) back
     * INTO the DB. Without it the DB silently drifts unread-forever whenever a
     * message is read outside this exact session, which is what made a
     * read-this-morning message re-appear as unread.
     *
     * UIDs with a pending/running outbox flag op are SKIPPED: that row is our
     * latest un-drained intent and must win over the (about-to-be-overwritten)
     * IMAP state until the drainer confirms it. This mirrors the read-side
     * filterFlagChangesAgainstOutbox guard so the two never fight.
     *
     * Best-effort: never throws into the request path. Reuses the batch
     * read-status writer (single SELECT + single UPDATE + per-conversation
     * unread recompute) so a 100-change poll is a handful of queries.
     *
     * @param string $folder   normalized IMAP folder path
     * @param array  $changes  rows from ImapService::fetchFlagChangesSince()
     *                         (each: uid, seen, flagged, answered, ...)
     */
    private function persistImapFlagChanges(string $folder, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $uids = [];
        foreach ($changes as $c) {
            if (isset($c['uid'])) {
                $uids[] = (int)$c['uid'];
            }
        }
        if (empty($uids)) {
            return;
        }

        // Protect un-drained local intent.
        $pendingSet = [];
        $folderId = $this->resolveFolderId($folder);
        if ($folderId !== null) {
            try {
                $pendingSet = array_flip(
                    $this->getOutboxService()->pendingFlagUids($this->userEmail, $folderId, $uids)
                );
            } catch (\Throwable $e) {
                // Non-fatal: without the guard we'd risk clobbering an in-flight
                // write, so on failure we conservatively persist nothing.
                return;
            }
        }

        $seenUids = [];
        $unseenUids = [];
        foreach ($changes as $c) {
            $uid = (int)($c['uid'] ?? 0);
            if ($uid <= 0 || isset($pendingSet[$uid])) {
                continue;
            }
            if (!empty($c['seen'])) {
                $seenUids[] = $uid;
            } else {
                $unseenUids[] = $uid;
            }
        }

        if (empty($seenUids) && empty($unseenUids)) {
            return;
        }

        try {
            $conv = $this->getConversationService();
            if (!empty($seenUids)) {
                $conv->updateMembersReadStatusBatch($this->userEmail, $folder, $seenUids, true);
            }
            if (!empty($unseenUids)) {
                $conv->updateMembersReadStatusBatch($this->userEmail, $folder, $unseenUids, false);
            }
        } catch (\Throwable $e) {
            error_log('[MailboxController::persistImapFlagChanges] ' . $e->getMessage());
        }
    }

    /**
     * Pure read-state merge rule shared by the overlay (kept static and
     * side-effect-free so it is unit-testable in isolation).
     *
     *   - $pending true  -> the DB row is the user's latest un-drained
     *     intent (read OR unread); it wins outright.
     *   - $pending false -> a message is read if EITHER source says read,
     *     covering "read elsewhere" (IMAP only) and "read in-app, not yet
     *     drained" (DB only) without resurrecting genuinely-unread rows.
     */
    public static function reconcileSeen(bool $imapSeen, bool $dbSeen, bool $pending): bool
    {
        return $pending ? $dbSeen : ($imapSeen || $dbSeen);
    }

    /**
     * Resolve the idempotency nonce for an outbox enqueue.
     *
     * Industry-standard (Stripe/Gmail-offline-queue) idempotency: the CLIENT
     * stamps each user action with a UUID and reuses it only when retrying
     * that exact action. We read it from the body (`clientOpId`), query string,
     * or `X-Client-Op-Id` header.
     *
     * When the client supplies an id: a network retry of the same gesture
     * reuses it -> the outbox collapses the duplicate; a genuinely new gesture
     * carries a new id -> the op is always honoured (this is what kills the
     * read->unread->read "jumps back" divergence the per-day nonce caused).
     *
     * When absent (legacy client, or server-initiated auto-mark): we generate
     * a fresh per-request nonce so distinct intents NEVER collapse. A `set_flag`
     * replay is harmless; move/delete are guarded by their source-UID checks.
     */
    private function resolveOpNonce(Request $request): string
    {
        $id = $request->input('clientOpId');
        if ($id === null || $id === '') {
            $id = $request->getQuery('clientOpId', '');
        }
        if ($id === null || $id === '') {
            $id = $request->getHeader('X-Client-Op-Id', '');
        }
        $id = trim((string)$id);
        if ($id !== '') {
            // Bound the length so a hostile client can't bloat the key input.
            return substr($id, 0, 128);
        }
        return bin2hex(random_bytes(16));
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

        // Race-safe insert. The SELECT above handles the common already-pinned
        // case, but two concurrent pin clicks can both pass it and then race
        // the INSERT; the second would hit the unique key and 500. Treat a
        // duplicate-key violation as success so pinning is idempotent.
        try {
            $stmt = $db->prepare(
                "INSERT INTO pinned_emails (user_email, folder_id, uid, message_id, subject)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$this->userEmail, $folderId, $uid, $messageId, $subject]);
        } catch (\PDOException $e) {
            // SQLSTATE 23000 = integrity constraint violation (duplicate key).
            if (($e->getCode() !== '23000') && (($e->errorInfo[1] ?? null) !== 1062)) {
                throw $e;
            }
            return Response::success(['pinned' => true], 'Already pinned');
        }

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

        // Phase 2 DB-first move. We update the conversation_members row
        // to point at the target folder (with a placeholder uid that the
        // worker will fill in once the IMAP server returns the real new
        // UID). The outbox row drives the actual IMAP MOVE. UI re-renders
        // instantly via MESSAGE_MOVED pub/sub.
        //
        // The placeholder uid is the OLD source UID, retained until the
        // worker overwrites it. The frontend already keys by folder_id +
        // uid; readers will see a member row in the target folder
        // immediately, with the old UID. The CONDSTORE-backed delta sync
        // (or the worker's complete() result) eventually corrects the UID
        // — readers handle this transition because the message_id is the
        // stable identifier used for deduping in the canonical store.
        $folderId = $this->resolveFolderId($folder);
        $targetFolderId = $this->resolveFolderId($targetFolder);
        if ($folderId === null || $targetFolderId === null) {
            // Fall back to legacy direct IMAP if either folder lacks identity.
            return $this->legacyDirectImapMove($folder, $uid, $targetFolder);
        }

        $result = $this->getWriteService()->commitMove(
            $this->userEmail,
            $folder,
            (string)$folderId,
            $uid,
            $targetFolder,
            (string)$targetFolderId,
            $this->resolveOpNonce($request)
        );
        if (!($result['ok'] ?? false)) {
            return Response::error($result['error'] ?? 'Failed to move message');
        }

        $this->getWriteService()->publishMoveEvent($this->userEmail, $folder, $targetFolder, $uid, null);
        return Response::success(['new_uid' => null], 'Message move enqueued');
    }

    /**
     * Legacy direct-IMAP move fallback used when folder identity is not
     * yet established for source or target. Preserves correctness during
     * the narrow window between folder discovery and FolderIndexService
     * upsert.
     */
    private function legacyDirectImapMove(string $folder, int $uid, string $targetFolder): Response
    {
        $success = $this->imap->moveMessage($folder, $uid, $targetFolder);
        $newUid = $this->imap->getLastMoveNewUid();
        if (!$success) {
            $msg = 'Failed to move message';
            if ($e = $this->imap->getLastError()) $msg .= ': ' . $e;
            return Response::error($msg);
        }
        $cache = $this->getRedisCache();
        $cache->invalidateMessage($this->userEmail, $folder, $uid);
        $cache->invalidateFolder($this->userEmail, $folder);
        $cache->invalidateFolder($this->userEmail, $targetFolder);
        try {
            $this->getConversationService()->moveConversationMember($this->userEmail, $folder, $uid, $targetFolder, $newUid);
        } catch (\Exception $e) {
            error_log("[MailboxController::legacyDirectImapMove] DB sync failed: " . $e->getMessage());
        }
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

        $trashFolder = $permanent ? null : $this->findTrashFolder();
        $isInTrash = false;
        if ($trashFolder !== null) {
            $isInTrash = strtolower(trim($folder)) === strtolower(trim($trashFolder));
        }

        // Effective op resolution:
        //   * explicit permanent=true -> delete (UID EXPUNGE on IMAP)
        //   * already-in-trash -> delete
        //   * no trash folder discoverable -> delete (matches legacy fallback)
        //   * otherwise -> move to trash
        $effectiveOp = ($permanent || $trashFolder === null || $isInTrash) ? 'delete' : 'move';

        $folderId = $this->resolveFolderId($folder);
        $trashFolderId = ($effectiveOp === 'move' && $trashFolder !== null)
            ? $this->resolveFolderId($trashFolder)
            : null;

        if ($folderId === null || ($effectiveOp === 'move' && $trashFolderId === null)) {
            // Fall back to legacy direct write when folder identity is missing.
            return $this->legacyDirectImapDelete($folder, $uid, $permanent);
        }

        $opNonce = $this->resolveOpNonce($request);
        $writeSvc = $this->getWriteService();

        $result = $writeSvc->commitDelete(
            $this->userEmail,
            $folder,
            (string)$folderId,
            $uid,
            $opNonce,
            $effectiveOp === 'move' ? $trashFolder : null,
            $effectiveOp === 'move' ? (string)$trashFolderId : null
        );
        if (!($result['ok'] ?? false)) {
            return Response::error($result['error'] ?? 'Failed to delete message');
        }

        if ($effectiveOp === 'delete') {
            $writeSvc->publishDeleteEvent($this->userEmail, $folder, $uid);
        } else {
            $writeSvc->publishMoveEvent($this->userEmail, $folder, $trashFolder, $uid, null);
        }
        return Response::success(null, 'Message deleted');
    }

    /**
     * Legacy direct-IMAP delete fallback used when folder identity is
     * unavailable. Preserves correctness for the narrow folder-discovery
     * window.
     */
    private function legacyDirectImapDelete(string $folder, int $uid, bool $permanent): Response
    {
        $trashFolder = null;
        $isInTrash = false;
        $trashNewUid = null;

        if ($permanent) {
            $success = $this->imap->deleteMessage($folder, $uid);
        } else {
            $trashFolder = $this->findTrashFolder();
            if ($trashFolder) {
                $isInTrash = strtolower(trim($folder)) === strtolower(trim($trashFolder));
            }
            if ($trashFolder && !$isInTrash) {
                $success = $this->imap->moveMessage($folder, $uid, $trashFolder);
                $trashNewUid = $this->imap->getLastMoveNewUid();
                if (!$success) {
                    $success = $this->imap->deleteMessage($folder, $uid);
                    if (!$success) {
                        return Response::error('Failed to delete message');
                    }
                    $permanent = true;
                    $trashNewUid = null;
                }
            } else {
                $success = $this->imap->deleteMessage($folder, $uid);
                $permanent = true;
            }
        }

        if (!$success) {
            return Response::error('Failed to delete message');
        }

        $cache = $this->getRedisCache();
        $cache->invalidateMessage($this->userEmail, $folder, $uid);
        $cache->invalidateFolder($this->userEmail, $folder);

        try {
            $cs = $this->getConversationService();
            if ($permanent || !$trashFolder || $isInTrash) {
                $cs->deleteConversationMember($this->userEmail, $folder, $uid);
            } else {
                $cs->moveConversationMember($this->userEmail, $folder, $uid, $trashFolder, $trashNewUid);
            }
        } catch (\Exception $e) {
            error_log("[MailboxController::legacyDirectImapDelete] DB sync failed: " . $e->getMessage());
        }

        if ($permanent || !$trashFolder || $isInTrash) {
            $cache->publishMessageDeleted($this->userEmail, $folder, $uid, true);
        } else {
            $cache->publishMessageMoved($this->userEmail, $folder, $trashFolder, $uid, $trashNewUid);
            $cache->invalidateFolder($this->userEmail, $trashFolder);
        }
        return Response::success(null, 'Message deleted');
    }

    /**
     * Batch flag set/clear across many messages.
     *
     * POST /mailbox/batch-flag
     * Body: { items: [{uid, folder}, ...], flag: "seen", value: true }
     *
     * Replaces N round trips (one per UID via /folders/{id}/messages/{uid}/flag)
     * with a single DB transaction + outbox enqueue. The "select all -> mark
     * read" workflow on a 500-message folder used to fire 500 HTTP calls;
     * this collapses to 1.
     */
    public function batchSetFlag(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $items = $request->input('items', []);
        $flag = strtolower((string)$request->input('flag', ''));
        $value = (bool)$request->input('value', true);

        $allowedFlags = ['seen', 'flagged', 'answered', 'deleted', 'draft'];
        if (!in_array($flag, $allowedFlags, true)) {
            return Response::error('Invalid flag', 400);
        }
        if (empty($items) || !is_array($items)) {
            return Response::error('items array is required', 400);
        }

        // Cap batch size so a single request cannot DoS the DB / outbox.
        $items = array_slice($items, 0, 500);

        // Group items by source folder, then resolve folder_id once per
        // folder (cheap upsert). The actual DB UPDATE + outbox enqueue
        // pattern is delegated to MailboxWriteService::commitFlagBatch.
        $byFolder = [];
        $skipped = 0;
        $errors = [];
        foreach ($items as $it) {
            if (!isset($it['uid'], $it['folder'])) continue;
            $f = $this->normalizeFolderName((string)$it['folder']);
            if (!isset($byFolder[$f])) {
                $folderId = $this->resolveFolderId($f);
                if ($folderId === null) {
                    $errors[] = "no folder identity for {$f}";
                    $skipped++;
                    continue;
                }
                $byFolder[$f] = ['folder_id' => (string)$folderId, 'uids' => []];
            }
            $byFolder[$f]['uids'][] = (int)$it['uid'];
        }

        $writeSvc = $this->getWriteService();
        $result = $writeSvc->commitFlagBatch(
            $this->userEmail,
            $byFolder,
            $flag,
            $value,
            $this->resolveOpNonce($request)
        );
        if (!($result['ok'] ?? false)) {
            return Response::error($result['errors'][0] ?? 'Batch flag failed', 500);
        }
        $success  = (int)($result['success'] ?? 0);
        $skipped += (int)($result['skipped'] ?? 0);
        $errors   = array_merge($errors, (array)($result['errors'] ?? []));

        // Post-commit pubsub. Per-UID FLAGS_CHANGED with a batch marker
        // so the frontend can collapse N reactive updates into 1.
        $cache = $this->getRedisCache();
        foreach ($byFolder as $folder => $entry) {
            foreach ($entry['uids'] as $uid) {
                $writeSvc->publishFlagEvent($this->userEmail, $folder, (int)$uid, $flag, $value, true);
            }
            $cache->invalidateFolder($this->userEmail, $folder);
        }

        return Response::success([
            'success' => $success,
            'skipped' => $skipped,
            'errors'  => $errors,
        ], "{$success} flagged, {$skipped} skipped");
    }

    /**
     * Batch move messages from multiple folders to a single target.
     * POST /mailbox/batch-move
     * Body: { messages: [{uid, folder}, ...], target: "INBOX.Trash" }
     *
     * Phase 2: DB-first. Updates webmail_conversation_members and
     * enqueues outbox rows inside one transaction; returns immediately.
     */
    public function batchMove(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $messageList = $request->input('messages', []);
        $targetFolder = $request->input('target');

        if (!$targetFolder) {
            return Response::error('Target folder is required', 400);
        }
        if (empty($messageList) || !is_array($messageList)) {
            return Response::error('Messages array is required', 400);
        }

        $targetFolder = $this->normalizeFolderName($targetFolder);
        $messageList = array_slice($messageList, 0, 500);

        $targetFolderId = $this->resolveFolderId($targetFolder);
        if ($targetFolderId === null) {
            return Response::error('Target folder identity not established', 400);
        }

        // Group source-side: folder -> {folder_id, uids[]}. Same shape
        // MailboxWriteService::commitMoveBatch expects.
        $bySource = [];
        $skipped  = 0;
        $errors   = [];
        foreach ($messageList as $item) {
            if (!isset($item['uid'], $item['folder'])) continue;
            $f = $this->normalizeFolderName((string)$item['folder']);
            if ($f === $targetFolder) continue;
            if (!isset($bySource[$f])) {
                $folderId = $this->resolveFolderId($f);
                if ($folderId === null) {
                    $errors[] = "no folder identity for {$f}";
                    $skipped++;
                    continue;
                }
                $bySource[$f] = ['folder_id' => (string)$folderId, 'uids' => []];
            }
            $bySource[$f]['uids'][] = (int)$item['uid'];
        }

        $writeSvc = $this->getWriteService();
        $result = $writeSvc->commitMoveBatch(
            $this->userEmail,
            $targetFolder,
            (string)$targetFolderId,
            $bySource,
            $this->resolveOpNonce($request)
        );
        if (!($result['ok'] ?? false)) {
            return Response::error($result['errors'][0] ?? 'Batch move failed', 500);
        }
        $success = (int)($result['success'] ?? 0);
        $failed  = $skipped + (int)($result['skipped'] ?? 0);
        $errors  = array_merge($errors, (array)($result['errors'] ?? []));

        $cache = $this->getRedisCache();
        foreach ($bySource as $folder => $entry) {
            foreach ($entry['uids'] as $uid) {
                $writeSvc->publishMoveEvent($this->userEmail, $folder, $targetFolder, (int)$uid, null);
            }
            $cache->invalidateFolder($this->userEmail, $folder);
        }
        $cache->invalidateFolder($this->userEmail, $targetFolder);

        return Response::success([
            'success' => $success,
            'failed'  => $failed,
            'errors'  => $errors,
        ], "{$success} moved, {$failed} failed");
    }

    /**
     * Batch delete messages from multiple folders.
     * POST /mailbox/batch-delete
     * Body: { messages: [{uid, folder}, ...], permanent: false }
     *
     * Phase 2: DB-first. The dual-path (move-to-trash vs permanent) is
     * resolved per source folder once, then enqueued individually.
     */
    public function batchDelete(Request $request): Response
    {
        // Soft delete needs the live folder list to discover the trash
        // folder (findTrashFolder uses $this->imap->listFolders()), so we
        // require an IMAP handle here exactly like the single delete()
        // path does. requireAuth alone leaves $this->imap null and makes
        // findTrashFolder() fatal with "listFolders() on null".
        $imapError = $this->requireImap($request);
        if ($imapError) return $imapError;

        $messageList = $request->input('messages', []);
        $permanent = (bool)$request->input('permanent', false);

        if (empty($messageList) || !is_array($messageList)) {
            return Response::error('Messages array is required', 400);
        }

        $messageList = array_slice($messageList, 0, 500);
        $trashFolder = $permanent ? null : $this->findTrashFolder();

        // Safety gate: a non-permanent delete that cannot discover a trash
        // folder must NOT silently fall through to permanent hard-delete.
        // Better to fail loudly than to irrecoverably destroy mail.
        if (!$permanent && $trashFolder === null) {
            return Response::error('Could not locate a Trash folder for this account', 503);
        }

        $trashFolderId = ($trashFolder !== null) ? $this->resolveFolderId($trashFolder) : null;
        $batchNonce = $this->resolveOpNonce($request);

        // Split into two work lists by per-folder effective op:
        //   - hardDelete   = permanent OR no trash OR already in trash
        //   - moveToTrash  = soft delete (target = trash)
        $hardByFolder = [];
        $moveBySource = [];
        $skipped = 0;
        $errors  = [];
        foreach ($messageList as $item) {
            if (!isset($item['uid'], $item['folder'])) continue;
            $f = $this->normalizeFolderName((string)$item['folder']);
            $folderId = $this->resolveFolderId($f);
            if ($folderId === null) {
                $errors[] = "no folder identity for {$f}";
                $skipped++;
                continue;
            }
            $isInTrash = $trashFolder !== null && strtolower(trim($f)) === strtolower(trim($trashFolder));
            $effectivePermanent = $permanent || $trashFolder === null || $trashFolderId === null || $isInTrash;
            if ($effectivePermanent) {
                if (!isset($hardByFolder[$f])) {
                    $hardByFolder[$f] = ['folder_id' => (string)$folderId, 'uids' => []];
                }
                $hardByFolder[$f]['uids'][] = (int)$item['uid'];
            } else {
                if (!isset($moveBySource[$f])) {
                    $moveBySource[$f] = ['folder_id' => (string)$folderId, 'uids' => []];
                }
                $moveBySource[$f]['uids'][] = (int)$item['uid'];
            }
        }

        $writeSvc = $this->getWriteService();
        $success = 0;
        $failed  = $skipped;

        // Run hard-deletes AND soft-moves under ONE transaction so the whole
        // "delete these messages" action is atomic in the DB mirror -- no
        // more half-applied selections when the second work list throws.
        if (!empty($hardByFolder) || !empty($moveBySource)) {
            $r = $writeSvc->commitDeleteAndMoveBatch(
                $this->userEmail,
                $hardByFolder,
                $moveBySource,
                $trashFolder,
                $trashFolderId !== null ? (string)$trashFolderId : null,
                $batchNonce
            );
            if (!($r['ok'] ?? false)) {
                return Response::error($r['errors'][0] ?? 'Batch delete failed', 500);
            }
            $success += (int)($r['success'] ?? 0);
            $failed  += (int)($r['skipped'] ?? 0);
            $errors   = array_merge($errors, (array)($r['errors'] ?? []));
        }

        $cache = $this->getRedisCache();
        foreach ($hardByFolder as $folder => $entry) {
            foreach ($entry['uids'] as $uid) {
                $writeSvc->publishDeleteEvent($this->userEmail, $folder, (int)$uid);
            }
            $cache->invalidateFolder($this->userEmail, $folder);
        }
        foreach ($moveBySource as $folder => $entry) {
            foreach ($entry['uids'] as $uid) {
                $writeSvc->publishMoveEvent($this->userEmail, $folder, $trashFolder, (int)$uid, null);
            }
            $cache->invalidateFolder($this->userEmail, $folder);
        }
        if ($trashFolder) {
            $cache->invalidateFolder($this->userEmail, $trashFolder);
        }

        return Response::success([
            'success' => $success,
            'failed'  => $failed,
            'errors'  => $errors,
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
        
        // Phase 2: DB-first rename.
        //
        // The identity rename (`FolderIndexService::applyRename`) is the
        // *system of record* for FlowOne: every conversation_member, filter,
        // pin, and label keys by the stable folder_id UUID, not by path. So
        // we apply the identity rename inside a DB transaction and enqueue
        // the IMAP RENAME for the worker to perform asynchronously.
        //
        // If the worker's IMAP write fails permanently (the folder name
        // collides on the server, ACL denied, etc.) the outbox row goes
        // `dead` and the user sees a sync-issues banner. We deliberately
        // do NOT roll back the identity rename in that case because:
        //   * the user already sees the new name in their UI,
        //   * the next CONDSTORE pull will detect the divergence and
        //     re-apply the rename via the inverse path, or
        //   * a manual retry from the banner re-enqueues the op.
        $folderId = $this->resolveFolderId($oldFolder);
        if ($folderId === null) {
            // Fall back to legacy direct rename when the identity row
            // doesn't exist yet. This is the path for never-listed
            // folders (which essentially shouldn't happen for a rename
            // because the user must have seen it to click "rename",
            // but we keep the fallback for safety).
            return $this->legacyDirectImapRename($oldFolder, $newFolder);
        }

        $db = \Webmail\Core\Database::getConnection($this->config);
        $outbox = $this->getOutboxService();
        $svc = $this->getFolderIndexService();

        $identityRenameApplied = false;
        try {
            $db->beginTransaction();

            if ($svc !== null) {
                $identityRenameApplied = $svc->applyRename(
                    $this->userEmail,
                    $folderId,
                    $oldFolder,
                    $newFolder,
                    null,
                    null,
                    null,
                    $this->getDualWriteTelemetry()
                );
            }

            $outbox->enqueue([
                'user_email'    => $this->userEmail,
                'account_email' => $this->userEmail,
                'op'            => 'rename_folder',
                'folder_id'     => $folderId,
                'payload'       => [
                    'old_path' => $oldFolder,
                    'new_path' => $newFolder,
                ],
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("[MailboxController::renameFolder] DB-first rename failed: " . $e->getMessage());
            return Response::error('Failed to rename folder: ' . $e->getMessage(), 500);
        }

        // Post-commit best-effort cascade: filter references, Redis
        // invalidation, WS broadcast. None of these are transactional
        // with MariaDB; failures here MUST NOT roll back the user's
        // visible rename. We wrap each in a try/catch \Throwable so a
        // fatal in one step can't take down the whole controller.
        $updatedFilters = 0;
        $cacheInvalidated = 0;
        $conversationsUpdated = 0;

        try {
            $filterService = new FilterService($this->config);
            $activeEmail = $this->getActiveEmail();
            $updatedFilters = $filterService->updateFolderReferences($activeEmail, $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] filter update failed: " . $e->getMessage());
        }

        try {
            $cache = $this->getRedisCache();
            $cacheInvalidated = $cache->handleFolderRename($this->userEmail, $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] Redis handleFolderRename failed: " . $e->getMessage());
        }

        try {
            $conversationsUpdated = $this->getConversationService()->updateFolderName($this->userEmail, $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] updateFolderName failed: " . $e->getMessage());
        }

        try {
            $this->getRedisCache()->publishFolderChanged($this->userEmail, 'renamed', $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::renameFolder] publishFolderChanged failed: " . $e->getMessage());
        }

        try {
            $invalidator = new FolderCacheInvalidator($this->getRedisCache());
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
        ], 'Folder rename enqueued');
    }

    /**
     * Legacy direct-IMAP rename fallback for folders without identity rows.
     */
    private function legacyDirectImapRename(string $oldFolder, string $newFolder): Response
    {
        $success = $this->imap->renameFolder($oldFolder, $newFolder);
        if (!$success) {
            $errors = imap_errors();
            $alerts = imap_alerts();
            $allErrors = array_merge($errors ?: [], $alerts ?: []);
            $msg = !empty($allErrors) ? implode(', ', $allErrors) : 'IMAP rename rejected';
            return Response::error('Failed to rename folder: ' . $msg, 500);
        }

        try {
            $filterService = new FilterService($this->config);
            $filterService->updateFolderReferences($this->getActiveEmail(), $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::legacyDirectImapRename] filter update failed: " . $e->getMessage());
        }

        try {
            $this->getRedisCache()->handleFolderRename($this->userEmail, $oldFolder, $newFolder);
            $this->getRedisCache()->publishFolderChanged($this->userEmail, 'renamed', $oldFolder, $newFolder);
        } catch (\Throwable $e) {
            error_log("[MailboxController::legacyDirectImapRename] cache/publish failed: " . $e->getMessage());
        }

        return Response::success(['folder' => $newFolder], 'Folder renamed');
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

        // Special operator: `is:pinned` — IMAP has no native pin flag, so we
        // strip the token from the IMAP query and remember a (folder,uid)
        // whitelist sourced from the `pinned_emails` table, then post-filter
        // the IMAP result set against it. Mixed queries like
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
            // pins outside the active folder.
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
        // Defensive: callers must have established IMAP (requireImap) before
        // reaching here. If they didn't, fail soft (null) instead of fataling
        // with "listFolders() on null". Returning null makes the caller treat
        // the op as a permanent delete, so callers MUST gate destructive
        // behaviour on a non-null trash folder, never silently hard-delete.
        if ($this->imap === null) {
            error_log('[findTrashFolder] IMAP handle is null; cannot discover trash folder');
            return null;
        }

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
        
        // Folder count refresh is handled by the frontend's MESSAGE_MOVED ->
        // debouncedFetchFolders cascade. We do NOT publish FOLDER_COUNTS
        // directly here because imap_status() can race with the IMAP MOVE/EXPUNGE
        // operation and return a poisoned response (unseen=0 with messages>0)
        // which the WebSocket would relay to the UI, zeroing the badge.
        
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
     * RSVP to a meeting invitation contained in this message.
     * POST /folders/{folder_id}/messages/{uid}/rsvp
     * Body: { response: 'accepted'|'declined'|'tentative', comment?: string }
     *
     * Sends an RFC 5545 METHOD:REPLY iCalendar to the organizer from the
     * user's own mailbox (using the active account's SMTP/OAuth creds) and
     * persists the chosen status in calendar_invite_responses so the UI
     * can show it on subsequent views.
     */
    public function rsvp(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $response = (string)$request->input('response', '');
        $comment = $request->input('comment', null);
        $allowed = ['accepted', 'declined', 'tentative'];
        if (!in_array($response, $allowed, true)) {
            return Response::error('Invalid response. Must be accepted, declined, or tentative.', 400);
        }

        $folder = $this->getResolvedFolder($request, 'message_rsvp');
        $uid = (int)$request->getParam('uid');
        if ($folder === null || $uid <= 0) {
            return Response::error('Folder or message not found', 404);
        }
        $folder = $this->normalizeFolderName($folder);

        $message = $this->imap->getMessage($folder, $uid);
        if (!$message) {
            $actualFolder = $this->findActualFolderName($folder);
            if ($actualFolder && $actualFolder !== $folder) {
                $message = $this->imap->getMessage($actualFolder, $uid);
            }
        }
        if (!$message) {
            return Response::error('Message not found', 404);
        }
        if (empty($message['body_calendar'])) {
            return Response::error('Message does not contain a calendar invitation', 400);
        }

        $event = $this->parseCalendarEvent($message['body_calendar']);
        if (!$event || empty($event['uid']) || empty($event['organizer_email'])) {
            return Response::error('Calendar invitation is missing required fields (UID or organizer)', 400);
        }

        // Don't RSVP to non-REQUEST methods (REPLY, CANCEL, COUNTER).
        $method = strtoupper((string)($event['method'] ?? 'REQUEST'));
        if ($method !== 'REQUEST' && $method !== '') {
            return Response::error("Cannot RSVP to a {$method} invite", 400);
        }

        $organizerEmail = strtolower(trim((string)$event['organizer_email']));
        if (!filter_var($organizerEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Organizer email is invalid', 400);
        }

        // Active account = the identity this RSVP should be sent from.
        $attendeeEmail = strtolower($this->getActiveEmail());
        if (!$attendeeEmail) {
            return Response::error('No active account email', 400);
        }

        // One response per invite: if this user already responded to this UID,
        // do not send again (prevents re-sending / spamming the organizer).
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $check = $db->prepare(
                "SELECT status FROM calendar_invite_responses
                 WHERE user_email = ? AND ical_uid = ?
                 LIMIT 1"
            );
            $check->execute([$attendeeEmail, $event['uid']]);
            $existing = $check->fetch(\PDO::FETCH_ASSOC);
            if ($existing) {
                return Response::error('You have already responded to this invitation.', 409);
            }
        } catch (\Throwable $e) {
            // Table missing (pre-migration) or transient DB issue: fall through
            // and let the send proceed; the upsert below still records it.
        }

        // Build SMTP transport from the active account's credentials.
        $creds = $this->getActiveCredentials();
        $smtpConfig = $this->config['smtp'] ?? [];
        if (!empty($creds['smtp_host'])) {
            $smtpConfig['host'] = $creds['smtp_host'];
        }
        if (!empty($creds['smtp_port'])) {
            $smtpConfig['port'] = (int)$creds['smtp_port'];
        }
        if (isset($creds['smtp_encryption'])) {
            $smtpConfig['encryption'] = $creds['smtp_encryption'];
        }

        $smtp = new \Webmail\Services\SmtpService($smtpConfig);
        if (!empty($creds['is_oauth'])) {
            if (empty($creds['access_token'])) {
                return Response::error('OAuth token unavailable. Please re-authenticate in Settings.', 401);
            }
            $smtp->setOAuthCredentials($creds['email'], $creds['access_token'], $creds['provider'] ?? 'google');
        } else {
            if (empty($creds['password'])) {
                return Response::error('No credentials available to send RSVP.', 401);
            }
            $smtp->setCredentials($creds['email'], $creds['password']);
        }

        // Build the iCalendar REPLY.
        $icalReply = \Webmail\Services\CalendarInviteService::buildIcalReply(
            $event,
            $attendeeEmail,
            $response,
            null,
            is_string($comment) && $comment !== '' ? $comment : null
        );

        $summary = (string)($event['summary'] ?? 'Meeting');
        $subjectPrefix = [
            'accepted'  => 'Accepted',
            'declined'  => 'Declined',
            'tentative' => 'Tentative',
        ][$response];
        $subject = "{$subjectPrefix}: {$summary}";

        $verb = [
            'accepted'  => 'accepted',
            'declined'  => 'declined',
            'tentative' => 'tentatively accepted',
        ][$response];

        $bodyHtmlSafeSummary = htmlspecialchars($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bodyHtmlSafeAttendee = htmlspecialchars($attendeeEmail, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $commentHtml = '';
        $commentText = '';
        if (is_string($comment) && $comment !== '') {
            $safeComment = htmlspecialchars($comment, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $commentHtml = "<p>Message: {$safeComment}</p>";
            $commentText = "\nMessage: {$comment}";
        }

        $bodyHtml = "<p><strong>{$bodyHtmlSafeAttendee}</strong> has {$verb} the invitation:</p>"
            . "<p><strong>{$bodyHtmlSafeSummary}</strong></p>"
            . $commentHtml;
        $bodyText = "{$attendeeEmail} has {$verb} the invitation: {$summary}{$commentText}";

        $sendResult = $smtp->send([
            'from_name' => $attendeeEmail,
            'to' => [['email' => $organizerEmail]],
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'ical' => $icalReply,
            'attachments' => [
                [
                    'content' => $icalReply,
                    'name' => 'response.ics',
                    'type' => 'text/calendar; method=REPLY',
                ],
            ],
        ]);

        if (empty($sendResult['success'])) {
            $err = $sendResult['error'] ?? 'Failed to send RSVP';
            error_log("MailboxController::rsvp send failed for {$attendeeEmail} -> {$organizerEmail}: {$err}");
            return Response::error('Failed to send RSVP: ' . $err, 500);
        }

        // Persist the user's response (upsert).
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare(
                "INSERT INTO calendar_invite_responses
                    (user_email, ical_uid, organizer_email, summary, status)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    organizer_email = VALUES(organizer_email),
                    summary = VALUES(summary)"
            );
            $stmt->execute([
                $attendeeEmail,
                $event['uid'],
                $organizerEmail,
                mb_substr($summary, 0, 512),
                $response,
            ]);
        } catch (\Throwable $e) {
            // Reply already sent; log but don't fail the request.
            error_log("MailboxController::rsvp persist failed: " . $e->getMessage());
        }

        return Response::success([
            'status' => $response,
            'organizer' => $organizerEmail,
            'summary' => $summary,
        ], 'RSVP sent');
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

        // Capture any VTIMEZONE blocks verbatim so we can re-emit them in a
        // REPLY when we can't resolve the TZID to an IANA zone (e.g. Outlook
        // ships Windows names like "Central Europe Standard Time").
        $vtimezones = [];
        if (preg_match_all('/BEGIN:VTIMEZONE.*?END:VTIMEZONE/is', $raw, $tzMatches)) {
            foreach ($tzMatches[0] as $block) {
                $block = preg_replace('/\r?\n/', "\r\n", trim($block));
                if ($block !== '') {
                    $vtimezones[] = $block;
                }
            }
        }

        $lines = preg_split('/\r?\n/', $raw);

        $event = [];
        if (!empty($vtimezones)) {
            $event['vtimezones'] = $vtimezones;
        }
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
                case 'UID':
                    $event['uid'] = trim($value);
                    break;
                case 'SEQUENCE':
                    $event['sequence'] = (int)$value;
                    break;
                case 'SUMMARY':
                    $event['summary'] = $this->unescapeIcal($value);
                    break;
                case 'DTSTART':
                    $event['dtstart'] = $this->parseIcalDate($value, $params);
                    $event['dtstart_raw'] = $value;
                    $event['dtstart_params'] = $params;
                    // VALUE=DATE (without time) signals an all-day event
                    if (stripos($params, 'VALUE=DATE') !== false || (strlen(trim($value)) === 8 && ctype_digit(trim($value)))) {
                        $event['all_day'] = true;
                    }
                    break;
                case 'DTEND':
                    $event['dtend'] = $this->parseIcalDate($value, $params);
                    $event['dtend_raw'] = $value;
                    $event['dtend_params'] = $params;
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

    /**
     * Augment a parsed calendar_event with the user's previously saved RSVP.
     * Sets `my_response` to 'accepted' | 'declined' | 'tentative' when a row
     * exists in calendar_invite_responses for (active email, ical uid).
     * Silently no-ops on any DB error so message rendering is never blocked.
     */
    private function attachInviteResponse(?array $event): ?array
    {
        if (!$event || empty($event['uid'])) {
            return $event;
        }
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare(
                "SELECT status FROM calendar_invite_responses
                 WHERE user_email = ? AND ical_uid = ?
                 LIMIT 1"
            );
            $stmt->execute([strtolower($this->getActiveEmail()), $event['uid']]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $event['my_response'] = $row['status'];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet (pre-migration) or transient DB blip; ignore.
        }
        return $event;
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

        // Action buttons (Add to Calendar + RSVP) are rendered by the Vue
        // MeetingInviteActions component outside the email iframe, so the
        // same controls appear for invites that ship with a full HTML body
        // (Gmail/Outlook) and those that don't.

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
        // Do NOT urldecode() here: PHP already percent-decodes query params
        // into $_GET. A second decode corrupted URLs whose values contain
        // encoded sequences (Instagram CDN signatures with %3D, literal +),
        // making every fetch fail signature validation.
        $url = $request->getQuery('url');
        if (!$url) {
            return Response::error('Missing url parameter', 400);
        }

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
            // A 4xx/5xx makes the browser render the <img alt> text, which
            // for CDN images is a huge filename+query string that wraps one
            // token per line inside narrow table cells and shreds the email
            // layout. Serve a neutral placeholder instead (no-store so a
            // transient failure isn't cached for a day).
            header('Content-Type: image/svg+xml');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo \Webmail\Services\RemoteImageProxyService::placeholderSvg();
            exit;
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

            // The canonical-identity cutover dropped the legacy `folder`
            // column from webmail_conversation_members; folder is now keyed
            // by folder_id -> webmail_folder_identity. Resolve "sent-like"
            // via SPECIAL-USE (\Sent) first, with a path-name fallback for
            // servers that don't advertise SPECIAL-USE.
            $sql = "SELECT DISTINCT cm1.message_id
                    FROM webmail_conversation_members cm1
                    JOIN webmail_conversation_members cm2
                      ON cm2.user_email = cm1.user_email
                     AND cm2.conversation_id = cm1.conversation_id
                    JOIN webmail_folder_identity fi
                      ON fi.id = cm2.folder_id
                     AND (
                          fi.special_use LIKE '%Sent%'
                          OR (LOWER(fi.current_path) LIKE '%sent%'
                              AND LOWER(fi.current_path) NOT LIKE '%unsent%')
                     )
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

