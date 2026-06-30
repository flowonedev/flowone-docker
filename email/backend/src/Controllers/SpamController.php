<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\SpamService;
use Webmail\Services\SieveSyncService;
use Webmail\Services\TrustedSenderSync;
use Webmail\Services\ConversationService;
use Webmail\Services\RedisCacheService;

class SpamController extends BaseController
{
    private ?SpamService $spamService = null;
    private ?ConversationService $conversationService = null;
    private ?RedisCacheService $redisCache = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        // Lazy initialization - don't create services until needed
    }
    
    /**
     * Get SpamService instance (lazy initialization)
     */
    private function getSpamService(): SpamService
    {
        if ($this->spamService === null) {
            $this->spamService = new SpamService($this->config);
        }
        return $this->spamService;
    }
    
    /**
     * Get ConversationService instance (lazy initialization)
     */
    private function getConversationService(): ConversationService
    {
        if ($this->conversationService === null) {
            $this->conversationService = new ConversationService($this->config);
        }
        return $this->conversationService;
    }
    
    private function getRedisCache(): RedisCacheService
    {
        if ($this->redisCache === null) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }
    
    // ==================== BLOCKED SENDERS ====================
    
    /**
     * Get all blocked senders
     * GET /spam/blocked-senders
     */
    public function getBlockedSenders(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $userEmail = $this->getActiveEmail();
        $blockedSenders = $this->getSpamService()->getBlockedSenders($userEmail);
        
        return Response::success($blockedSenders);
    }
    
    /**
     * Block a sender
     * POST /spam/block-sender
     */
    public function blockSender(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $senderEmail = $request->input('email');
        $reason = $request->input('reason');
        $blockDomain = (bool)$request->input('block_domain', false);
        $createFilter = (bool)$request->input('create_filter', true);
        
        if (!$senderEmail || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email address required', 400);
        }
        
        $userEmail = $this->getActiveEmail();

        $success = $this->getSpamService()->blockSender($userEmail, $senderEmail, $reason, $blockDomain);
        if (!$success) {
            return Response::error('Failed to save blocked sender', 500);
        }

        $sync = ['synced' => false, 'warning' => null];
        if ($createFilter) {
            $sync = $this->syncSieveRules($userEmail);
        }
        
        $payload = [
            'blocked' => true,
            'filter_created' => $sync['synced'],
            'sieve_synced' => $sync['synced'],
        ];
        if ($sync['warning']) {
            $payload['sieve_warning'] = $sync['warning'];
        }
        
        return Response::success($payload, $sync['synced']
            ? 'Sender blocked successfully'
            : 'Sender blocked, but the mailbox server filter could not be created. New mail may still reach Inbox until Sieve is available.');
    }
    
    /**
     * Unblock a sender
     * DELETE /spam/blocked-sender/{id}
     */
    public function unblockSender(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        if (!$id) {
            return Response::error('ID required', 400);
        }
        
        $userEmail = $this->getActiveEmail();
        $success = $this->getSpamService()->unblockSender($userEmail, $id);
        
        if (!$success) {
            return Response::error('Failed to unblock sender', 500);
        }
        
        $sync = $this->syncSieveRules($userEmail);
        
        $payload = ['sieve_synced' => $sync['synced']];
        if ($sync['warning']) {
            $payload['sieve_warning'] = $sync['warning'];
        }
        
        return Response::success($payload, 'Sender unblocked');
    }
    
    // ==================== SAFE SENDERS ====================
    
    /**
     * Get all safe senders
     * GET /spam/safe-senders
     */
    public function getSafeSenders(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $userEmail = $this->getActiveEmail();

        // One-time migration: pull settings trusted senders into the DB
        try {
            $sync = new TrustedSenderSync($this->config);
            $sync->migrateSettingsToDb($userEmail);
        } catch (\Throwable $e) {
            error_log("[SpamController::getSafeSenders] Migration error: " . $e->getMessage());
        }

        $safeSenders = $this->getSpamService()->getSafeSenders($userEmail);
        
        return Response::success($safeSenders);
    }
    
    /**
     * Add a safe sender
     * POST /spam/safe-sender
     */
    public function addSafeSender(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $senderEmail = $request->input('email');
        $trustDomain = (bool)$request->input('trust_domain', false);
        
        if (!$senderEmail || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email address required', 400);
        }
        
        $userEmail = $this->getActiveEmail();

        $trustedSync = new TrustedSenderSync($this->config);
        $trustedSync->addTrusted($userEmail, $senderEmail);

        if ($trustDomain) {
            $this->getSpamService()->addSafeSender($userEmail, $senderEmail, true);
        }
        
        $sync = $this->syncSieveRules($userEmail);
        
        $payload = ['sieve_synced' => $sync['synced']];
        if ($sync['warning']) {
            $payload['sieve_warning'] = $sync['warning'];
        }
        
        return Response::success($payload, 'Trusted sender added');
    }
    
    /**
     * Remove a safe sender
     * DELETE /spam/safe-sender/{id}
     */
    public function removeSafeSender(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        if (!$id) {
            return Response::error('ID required', 400);
        }
        
        $userEmail = $this->getActiveEmail();

        $trustedSync = new TrustedSenderSync($this->config);
        $result = $trustedSync->removeTrustedById($userEmail, $id);

        if (!$result['database']) {
            return Response::error('Failed to remove trusted sender', 500);
        }
        
        $sync = $this->syncSieveRules($userEmail);
        
        $payload = ['sieve_synced' => $sync['synced']];
        if ($sync['warning']) {
            $payload['sieve_warning'] = $sync['warning'];
        }
        
        return Response::success($payload, 'Trusted sender removed');
    }
    
    // ==================== SPAM REPORTING ====================
    
    /**
     * Report email as spam (moves to spam + optionally trains SpamAssassin)
     * POST /spam/report
     */
    public function reportSpam(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) return $imapError;
        
        $folder = $request->input('folder', 'INBOX');
        $uid = (int)$request->input('uid');
        $train = (bool)$request->input('train', true);
        $blockSender = (bool)$request->input('block_sender', false);
        
        if (!$uid) {
            return Response::error('Message UID required', 400);
        }
        
        try {
            $userEmail = $this->getActiveEmail();
            
            if (!$this->imap->selectFolder($folder)) {
                return Response::error('Failed to open folder: ' . $folder, 500);
            }
            
            // Get the raw email for training before moving
            $rawEmail = null;
            if ($train) {
                $rawEmail = $this->imap->getRawMessage($uid);
            }
            
            // Get sender email for blocking
            $senderEmail = null;
            if ($blockSender) {
                $message = $this->imap->getMessage($folder, $uid);
                if ($message && !empty($message['from'])) {
                    $from = is_array($message['from']) ? $message['from'][0] : $message['from'];
                    $senderEmail = $from['email'] ?? null;
                }
            }
            
            // Find spam folder
            $spamFolder = $this->findSpamFolder();
            if (!$spamFolder) {
                return Response::error('Spam/Junk folder not found. Please create a Spam or Junk folder in your mailbox.', 500);
            }
            
            // Move to spam
            $moved = $this->imap->moveMessage($folder, $uid, $spamFolder);
            if (!$moved) {
                $reason = $this->imap->getLastError();
                error_log("[SpamController::reportSpam] Move failed (folder='{$folder}', uid={$uid}, target='{$spamFolder}'): " . ($reason ?: 'unknown'));
                return Response::error(
                    'Failed to move message to spam folder' . ($reason ? ': ' . $reason : ''),
                    500
                );
            }
            $newUid = $this->imap->getLastMoveNewUid();
            
            // Sync conversation database
            try {
                $conversationService = $this->getConversationService();
                $conversationService->moveConversationMember($userEmail, $folder, $uid, $spamFolder, $newUid);
            } catch (\Exception $e) {
                error_log("[SpamController::reportSpam] Failed to sync conversation database: " . $e->getMessage());
            }
            
            // Invalidate caches and publish real-time event for WebSocket sync
            $cache = $this->getRedisCache();
            $cache->invalidateMessage($userEmail, $folder, $uid);
            $cache->invalidateFolder($userEmail, $folder);
            $cache->invalidateFolder($userEmail, $spamFolder);
            $cache->publishMessageMoved($userEmail, $folder, $spamFolder, $uid, $newUid);
            
            // Log the spam report action for stats
            try {
                $this->getSpamService()->logReportedSpam($userEmail, $senderEmail);
            } catch (\Exception $e) {
                error_log("[SpamController::reportSpam] Failed to log spam report: " . $e->getMessage());
            }
            
            // Train SpamAssassin
            $trained = false;
            if ($train && $rawEmail) {
                $trained = $this->getSpamService()->trainAsSpam($userEmail, $rawEmail);
            }
            
            // Block sender if requested (DB + Sieve rule)
            $blocked = false;
            if ($blockSender && $senderEmail) {
                $blocked = $this->getSpamService()->blockSender($userEmail, $senderEmail, 'Reported as spam');
                if ($blocked) {
                    $this->syncSieveRules($userEmail);
                }
            }
            
            return Response::success([
                'moved' => true,
                'trained' => $trained,
                'sender_blocked' => $blocked,
            ], 'Reported as spam');
            
        } catch (\Exception $e) {
            error_log("[SpamController::reportSpam] Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::error('Failed to report spam: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Mark email as not spam (moves to inbox + trains as ham)
     * POST /spam/not-spam
     */
    public function notSpam(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) return $imapError;
        
        $folder = $request->input('folder');
        $uid = (int)$request->input('uid');
        $train = (bool)$request->input('train', true);
        $addToSafe = (bool)$request->input('add_to_safe', false);
        
        if (!$uid || !$folder) {
            return Response::error('Message UID and folder required', 400);
        }
        
        $userEmail = $this->getActiveEmail();
        
        // Select folder first
        if (!$this->imap->selectFolder($folder)) {
            return Response::error('Failed to open folder', 500);
        }
        
        // Get the raw email for training before moving
        $rawEmail = null;
        if ($train) {
            $rawEmail = $this->imap->getRawMessage($uid);
        }
        
        // Get sender email for safe list
        $senderEmail = null;
        if ($addToSafe) {
            $message = $this->imap->getMessage($folder, $uid);
            if ($message && !empty($message['from'])) {
                $from = is_array($message['from']) ? $message['from'][0] : $message['from'];
                $senderEmail = $from['email'] ?? null;
            }
        }
        
        // Move to inbox
        $moved = $this->imap->moveMessage($folder, $uid, 'INBOX');
        if (!$moved) {
            $reason = $this->imap->getLastError();
            error_log("[SpamController::notSpam] Move failed (folder='{$folder}', uid={$uid}): " . ($reason ?: 'unknown'));
            return Response::error(
                'Failed to move message to inbox' . ($reason ? ': ' . $reason : ''),
                500
            );
        }
        $newUid = $this->imap->getLastMoveNewUid();
        
        // Sync conversation database
        try {
            $conversationService = $this->getConversationService();
            $conversationService->moveConversationMember($userEmail, $folder, $uid, 'INBOX', $newUid);
        } catch (\Exception $e) {
            error_log("[SpamController::notSpam] Failed to sync conversation database: " . $e->getMessage());
        }
        
        // Invalidate caches and publish real-time event for WebSocket sync
        $cache = $this->getRedisCache();
        $cache->invalidateMessage($userEmail, $folder, $uid);
        $cache->invalidateFolder($userEmail, $folder);
        $cache->invalidateFolder($userEmail, 'INBOX');
        $cache->publishMessageMoved($userEmail, $folder, 'INBOX', $uid, $newUid);
        
        // Always log the not-spam action for stats (independent of training)
        $this->getSpamService()->logNotSpam($userEmail, $senderEmail);
        
        // Train SpamAssassin as ham
        $trained = false;
        if ($train && $rawEmail) {
            $trained = $this->getSpamService()->trainAsHam($userEmail, $rawEmail);
        }
        
        // Add to trusted senders (both DB + settings) if requested, then resync Sieve
        $addedToSafe = false;
        $sieveSynced = false;
        $sieveWarning = null;
        if ($addToSafe && $senderEmail) {
            $trustedSync = new TrustedSenderSync($this->config);
            $result = $trustedSync->addTrusted($userEmail, $senderEmail);
            $addedToSafe = $result['database'];
            if ($addedToSafe) {
                $sync = $this->syncSieveRules($userEmail);
                $sieveSynced = $sync['synced'];
                $sieveWarning = $sync['warning'];
            }
        }
        
        $payload = [
            'moved' => true,
            'trained' => $trained,
            'added_to_safe' => $addedToSafe,
            'sieve_synced' => $sieveSynced,
        ];
        if ($sieveWarning) {
            $payload['sieve_warning'] = $sieveWarning;
        }
        
        return Response::success($payload, 'Marked as not spam');
    }
    
    /**
     * Batched mark-as-not-spam. Mirrors notSpam() semantics but
     * collapses N HTTP requests to one. Each entry in items is
     * { folder, uid }. Moves to INBOX, logs ham, optionally trains.
     *
     * POST /spam/not-spam-batch
     */
    public function notSpamBatch(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) return $imapError;

        $items = $request->input('items', []);
        $train = (bool)$request->input('train', true);

        if (empty($items) || !is_array($items)) {
            return Response::error('Items array required (each with uid and folder)', 400);
        }

        $userEmail = $this->getActiveEmail();
        $cache = $this->getRedisCache();

        $moved = 0;
        $trained = 0;
        $conversationService = $this->getConversationService();
        $spamService = $this->getSpamService();

        foreach ($items as $item) {
            $uid = (int)($item['uid'] ?? 0);
            $folder = $item['folder'] ?? null;
            if (!$uid || !$folder) continue;

            try {
                if (!$this->imap->selectFolder($folder)) continue;

                $rawEmail = null;
                if ($train) {
                    $rawEmail = $this->imap->getRawMessage($uid);
                }

                $ok = $this->imap->moveMessage($folder, $uid, 'INBOX');
                if (!$ok) continue;

                $newUid = $this->imap->getLastMoveNewUid();
                $moved++;

                try {
                    $conversationService->moveConversationMember($userEmail, $folder, $uid, 'INBOX', $newUid);
                } catch (\Exception $e) { /* non-critical */ }

                $cache->invalidateMessage($userEmail, $folder, $uid);
                $cache->invalidateFolder($userEmail, $folder);
                $cache->invalidateFolder($userEmail, 'INBOX');
                $cache->publishMessageMoved($userEmail, $folder, 'INBOX', $uid, $newUid);

                if ($train && $rawEmail) {
                    if ($spamService->trainAsHam($userEmail, $rawEmail)) {
                        $trained++;
                    }
                }
            } catch (\Exception $e) {
                error_log("[SpamController::notSpamBatch] Error on UID {$uid}: " . $e->getMessage());
            }
        }

        try {
            $spamService->logNotSpam($userEmail, null);
        } catch (\Exception $e) { /* non-critical */ }

        return Response::success([
            'moved' => $moved,
            'trained' => $trained,
            'total' => count($items),
        ], "Marked {$moved} message(s) as not spam");
    }

    // ==================== SPAM EMAILS (IMAP FOLDER) ====================
    
    /**
     * List emails in the Spam/Junk IMAP folder
     * GET /spam/emails
     */
    public function getSpamEmails(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) return $imapError;
        
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(100, max(10, (int)$request->getQuery('limit', 50)));
        
        try {
            $spamFolder = $this->findSpamFolder();
            if (!$spamFolder) {
                return Response::success([
                    'emails' => [],
                    'total' => 0,
                    'page' => 1,
                    'pages' => 0,
                    'folder' => null,
                ]);
            }
            
            $result = $this->imap->getMessages($spamFolder, $page, $limit, 'date', 'desc');
            
            return Response::success([
                'emails' => $result['messages'] ?? [],
                'total' => $result['total'] ?? 0,
                'page' => $result['page'] ?? $page,
                'pages' => $result['pages'] ?? 0,
                'folder' => $spamFolder,
            ]);
            
        } catch (\Exception $e) {
            error_log("[SpamController::getSpamEmails] Error: " . $e->getMessage());
            return Response::error('Failed to fetch spam emails: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Batch report multiple emails as spam (move + optional training).
     * POST /spam/report-batch
     */
    public function reportSpamBatch(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) return $imapError;

        $items = $request->input('items', []);
        $train = (bool)$request->input('train', true);

        if (empty($items) || !is_array($items)) {
            return Response::error('Items array required (each with uid and folder)', 400);
        }

        $userEmail = $this->getActiveEmail();
        $spamFolder = $this->findSpamFolder();
        if (!$spamFolder) {
            return Response::error('Spam/Junk folder not found', 500);
        }

        $moved = 0;
        $trained = 0;
        $cache = $this->getRedisCache();

        foreach ($items as $item) {
            $uid = (int)($item['uid'] ?? 0);
            $folder = $item['folder'] ?? 'INBOX';
            if (!$uid) continue;

            try {
                if (!$this->imap->selectFolder($folder)) continue;

                $rawEmail = null;
                if ($train) {
                    $rawEmail = $this->imap->getRawMessage($uid);
                }

                $ok = $this->imap->moveMessage($folder, $uid, $spamFolder);
                if (!$ok) continue;

                $newUid = $this->imap->getLastMoveNewUid();
                $moved++;

                try {
                    $this->getConversationService()->moveConversationMember($userEmail, $folder, $uid, $spamFolder, $newUid);
                } catch (\Exception $e) { /* non-critical */ }

                $cache->invalidateMessage($userEmail, $folder, $uid);
                $cache->invalidateFolder($userEmail, $folder);
                $cache->invalidateFolder($userEmail, $spamFolder);
                $cache->publishMessageMoved($userEmail, $folder, $spamFolder, $uid, $newUid);

                if ($train && $rawEmail) {
                    if ($this->getSpamService()->trainAsSpam($userEmail, $rawEmail)) {
                        $trained++;
                    }
                }
            } catch (\Exception $e) {
                error_log("[SpamController::reportSpamBatch] Error on UID {$uid}: " . $e->getMessage());
            }
        }

        try {
            $this->getSpamService()->logReportedSpam($userEmail, null);
        } catch (\Exception $e) { /* non-critical */ }

        return Response::success([
            'moved' => $moved,
            'trained' => $trained,
            'total' => count($items),
        ], "Reported {$moved} message(s) as spam");
    }

    // ==================== SETTINGS & STATS ====================
    
    /**
     * Get spam settings
     * GET /spam/settings
     */
    public function getSettings(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $userEmail = $this->getActiveEmail();
        $settings = $this->getSpamService()->getSettings($userEmail);
        
        return Response::success($settings);
    }
    
    /**
     * Update spam settings
     * PUT /spam/settings
     */
    public function updateSettings(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $userEmail = $this->getActiveEmail();
        
        $settings = [
            'auto_delete_days' => (int)$request->input('auto_delete_days', 30),
            'auto_training_enabled' => (bool)$request->input('auto_training_enabled', true),
        ];
        
        $success = $this->getSpamService()->updateSettings($userEmail, $settings);
        
        if (!$success) {
            return Response::error('Failed to update settings', 500);
        }
        
        return Response::success($settings, 'Settings updated');
    }
    
    /**
     * Get spam statistics
     * GET /spam/stats
     */
    public function getStats(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) return $imapError;
        
        $userEmail = $this->getActiveEmail();
        $days = (int)$request->getQuery('days', 30);
        
        $stats = $this->getSpamService()->getStats($userEmail, $days);
        
        // Also get counts
        $stats['blocked_senders_count'] = count($this->getSpamService()->getBlockedSenders($userEmail));
        $stats['safe_senders_count'] = count($this->getSpamService()->getSafeSenders($userEmail));
        
        // Get actual spam folder email count from IMAP
        $stats['spam_folder_count'] = 0;
        try {
            $spamFolder = $this->findSpamFolder();
            if ($spamFolder) {
                $folderStatus = $this->imap->getFolderStatus($spamFolder);
                $stats['spam_folder_count'] = $folderStatus['messages'] ?? 0;
            }
        } catch (\Exception $e) {
            error_log("[SpamController::getStats] Failed to get spam folder count: " . $e->getMessage());
        }
        
        return Response::success($stats);
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Find the spam/junk folder
     */
    private function findSpamFolder(): ?string
    {
        if (!$this->imap) {
            return null;
        }
        
        $folders = $this->imap->listFolders();
        
        $found = null;
        foreach ($folders as $folder) {
            if (isset($folder['type']) && in_array($folder['type'], ['spam', 'junk'])) {
                $found = $folder['name'];
                break;
            }
        }
        
        if (!$found) {
            $commonNames = ['INBOX.Spam', 'INBOX.Junk', 'Spam', 'Junk', 'Junk E-mail'];
            foreach ($commonNames as $name) {
                foreach ($folders as $folder) {
                    if (strcasecmp($folder['name'], $name) === 0) {
                        $found = $folder['name'];
                        break 2;
                    }
                }
            }
        }

        if ($found) {
            try {
                $userEmail = $this->getActiveEmail();
                $this->getSpamService()->setSpamFolder($userEmail, $found);
            } catch (\Throwable $e) {
                error_log("[SpamController::findSpamFolder] Failed to persist spam folder: " . $e->getMessage());
            }
        }
        
        return $found;
    }
    
    /**
     * Regenerate and push the unified Sieve script (blocked + safe + filters + vacation).
     * Tries ManageSieve (if password available), falls back to disk write.
     */
    /**
     * Returns ['synced' => bool, 'warning' => ?string].
     */
    private function syncSieveRules(string $userEmail): array
    {
        try {
            $creds = $this->getActiveCredentials();
            $password = $creds['password'] ?? null;

            $syncService = new SieveSyncService($this->config);
            $result = $syncService->sync($userEmail, $password);

            if (!$result['success']) {
                $err = $result['error'] ?? 'unknown';
                error_log("[SpamController] Sieve sync failed for {$userEmail}: {$err}");
                return ['synced' => false, 'warning' => 'Saved but server-side filter sync failed: ' . $err];
            }

            return ['synced' => true, 'warning' => null];
        } catch (\Throwable $e) {
            error_log("[SpamController] Sieve sync error: " . $e->getMessage());
            return ['synced' => false, 'warning' => 'Saved but server-side filter sync failed'];
        }
    }
}
