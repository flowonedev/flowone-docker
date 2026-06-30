<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\FilterService;
use Webmail\Services\ImapService;
use Webmail\Services\LabelService;
use Webmail\Services\SieveService;
use Webmail\Services\SieveSyncService;
use Webmail\Services\ConversationService;
use Webmail\Services\RedisCacheService;

class FilterController extends BaseController
{
    private ?FilterService $filterService = null;
    private ?ConversationService $conversationService = null;
    private ?RedisCacheService $redisCache = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        if ($this->userEmail) {
            $this->filterService = new FilterService($config);
        }
    }
    
    // extractUserFromToken(), requireValidSession(), getActiveEmail(), getActiveCredentials(),
    // getSecondaryAccountCredentials(), decryptAccountPassword() inherited from BaseController
    
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        return Response::success([
            'filters' => $this->filterService->getFilters($activeEmail),
            'fields' => FilterService::getAvailableFields(),
            'operators' => FilterService::getAvailableOperators(),
            'actions' => FilterService::getAvailableActions(),
        ]);
    }
    
    public function get(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $filter = $this->filterService->getFilter($activeEmail, $id);
        
        if (!$filter) {
            return Response::error('Filter not found', 404);
        }
        
        return Response::success(['filter' => $filter]);
    }
    
    public function create(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        try {
            $data = [
                'name' => $request->input('name'),
                'enabled' => $request->input('enabled', true),
                'priority' => (int)$request->input('priority', 0),
                'conditions' => $request->input('conditions', []),
                'actions' => $request->input('actions', []),
                'stop_processing' => (bool)$request->input('stop_processing', false),
            ];
            
            error_log("Filter create request: " . json_encode($data));
            
            if (empty($data['name'])) {
                return Response::error('Filter name is required');
            }
            
            $filter = $this->filterService->createFilter($activeEmail, $data);
            
            if (!$filter) {
                return Response::error('Failed to create filter');
            }
            
            $sync = $this->autoSyncSieve($activeEmail);
            
            $payload = ['filter' => $filter, 'sieve_synced' => $sync['synced']];
            if ($sync['warning']) {
                $payload['sieve_warning'] = $sync['warning'];
            }
            
            return Response::success($payload, $sync['synced'] ? 'Filter created and synced' : 'Filter created');
        } catch (\Exception $e) {
            error_log("Filter create error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::error('Failed to create filter: ' . $e->getMessage());
        }
    }
    
    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        try {
            $id = (int)$request->getParam('id');
            
            $data = [];
            if ($request->has('name')) $data['name'] = $request->input('name');
            if ($request->has('enabled')) $data['enabled'] = $request->input('enabled');
            if ($request->has('priority')) $data['priority'] = (int)$request->input('priority');
            if ($request->has('conditions')) $data['conditions'] = $request->input('conditions');
            if ($request->has('actions')) $data['actions'] = $request->input('actions');
            if ($request->has('stop_processing')) $data['stop_processing'] = (bool)$request->input('stop_processing');
            
            error_log("Filter update request ID=$id: " . json_encode($data));
            
            $filter = $this->filterService->updateFilter($activeEmail, $id, $data);
            
            if (!$filter) {
                return Response::error('Filter not found', 404);
            }
            
            $sync = $this->autoSyncSieve($activeEmail);
            
            $payload = ['filter' => $filter, 'sieve_synced' => $sync['synced']];
            if ($sync['warning']) {
                $payload['sieve_warning'] = $sync['warning'];
            }
            
            return Response::success($payload, $sync['synced'] ? 'Filter updated and synced' : 'Filter updated');
        } catch (\Exception $e) {
            error_log("Filter update error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::error('Failed to update filter: ' . $e->getMessage());
        }
    }
    
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        if (!$this->filterService->deleteFilter($activeEmail, $id)) {
            return Response::error('Filter not found', 404);
        }
        
        $sync = $this->autoSyncSieve($activeEmail);
        
        $payload = ['sieve_synced' => $sync['synced']];
        if ($sync['warning']) {
            $payload['sieve_warning'] = $sync['warning'];
        }
        
        return Response::success($payload, $sync['synced'] ? 'Filter deleted and synced' : 'Filter deleted');
    }
    
    /**
     * Bulk enable/disable filters with a single Sieve sync.
     * POST /filters/bulk-toggle  { enabled: bool }
     */
    public function bulkToggle(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $enabled = (bool)$request->input('enabled', true);
        
        try {
            $allFilters = $this->filterService->getFilters($activeEmail);
            $updated = 0;
            
            foreach ($allFilters as $filter) {
                if ((bool)$filter['enabled'] !== $enabled) {
                    $this->filterService->updateFilter($activeEmail, $filter['id'], ['enabled' => $enabled]);
                    $updated++;
                }
            }
            
            $sync = $this->autoSyncSieve($activeEmail);
            
            $payload = [
                'updated' => $updated,
                'enabled' => $enabled,
                'sieve_synced' => $sync['synced'],
            ];
            if ($sync['warning']) {
                $payload['sieve_warning'] = $sync['warning'];
            }
            
            return Response::success($payload, "Toggled {$updated} filters");
        } catch (\Exception $e) {
            error_log("[FilterController::bulkToggle] Error: " . $e->getMessage());
            return Response::error('Failed to toggle filters: ' . $e->getMessage());
        }
    }

    /**
     * Apply filters to messages in a folder
     */
    public function apply(Request $request): Response
    {
        $authError = $this->requireImap($request);
        if ($authError) return $authError;
        
        $creds = $this->getActiveCredentials();
        $activeEmail = $creds['email'];
        $activePassword = $creds['password'];
        
        $folder = $request->input('folder', 'INBOX');
        $filterIds = $request->input('filter_ids', []); // Empty = apply all enabled filters
        $limit = min((int)$request->input('limit', 100), 500); // Max 500 at a time
        $page = max(1, (int)$request->input('page', 1)); // Page number for pagination
        
        error_log("Filter apply: folder=$folder, user={$activeEmail}, limit=$limit, page=$page");
        
        try {
            $imap = new ImapService($this->config['imap']);
            
            if (!$imap->connect($activeEmail, $activePassword)) {
                error_log("Filter apply: IMAP connection failed for {$activeEmail}");
                return Response::error('Failed to connect to email server');
            }
            
            error_log("Filter apply: IMAP connected successfully");
            
            // Get filters to apply
            $allFilters = $this->filterService->getFilters($activeEmail);
            $filters = array_filter($allFilters, function($f) use ($filterIds) {
                if (!$f['enabled']) return false;
                if (!empty($filterIds) && !in_array($f['id'], $filterIds)) return false;
                return true;
            });
            
            error_log("Filter apply: Found " . count($filters) . " enabled filters");
            
            if (empty($filters)) {
                return Response::success(['processed' => 0, 'total_messages' => 0, 'actions' => []], 'No filters to apply');
            }
            
            // Get messages from folder (paginated)
            $messages = $imap->getMessages($folder, $page, $limit);
            $folderTotal = $messages['total'] ?? 0;
            $totalPages = $messages['pages'] ?? 1;
            error_log("Filter apply: Got " . count($messages['messages'] ?? []) . " messages from $folder page $page/$totalPages (folder total: $folderTotal)");
            
            $processed = 0;
            $actionsApplied = [];
            $labelService = new LabelService($this->config);
            
            $needsLabels = $this->filtersUseField($filters, 'has_label');
            
            foreach ($messages['messages'] as $message) {
                // Get full message for body matching
                $fullMessage = $imap->getMessage($folder, $message['uid']);
                
                if ($needsLabels && $fullMessage && !empty($fullMessage['message_id'])) {
                    $fullMessage['labels'] = $labelService->getMessageLabels($activeEmail, $fullMessage['message_id']);
                }
                
                foreach ($filters as $filter) {
                    if ($this->filterService->matchesFilter($fullMessage, $filter['conditions'])) {
                        // Apply actions
                        foreach ($filter['actions'] as $action) {
                            $actionType = $action['action'] ?? '';
                            $actionValue = $action['value'] ?? '';
                            
                            $result = $this->applyAction($imap, $labelService, $folder, $message['uid'], $actionType, $actionValue);
                            
                            if ($result) {
                                $actionsApplied[] = [
                                    'uid' => $message['uid'],
                                    'subject' => $message['subject'] ?? '',
                                    'filter' => $filter['name'],
                                    'action' => $actionType,
                                ];
                            }
                        }
                        
                        $processed++;
                        
                        // Stop processing more filters for this message if configured
                        if ($filter['stop_processing']) {
                            break;
                        }
                    }
                }
            }
            
            $imap->disconnect();
            
            return Response::success([
                'processed' => $processed,
                'batch_size' => count($messages['messages']),
                'folder_total' => $folderTotal,
                'page' => $page,
                'total_pages' => $totalPages,
                'actions_count' => count($actionsApplied),
                'actions' => $actionsApplied,
            ], "Applied filters to $processed messages");
            
        } catch (\Exception $e) {
            error_log("Filter apply error: " . $e->getMessage());
            return Response::error('Failed to apply filters: ' . $e->getMessage());
        }
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

    private function applyAction(ImapService $imap, LabelService $labelService, string $folder, int $uid, string $action, string $value): bool
    {
        try {
            $result = false;
            $activeEmail = $this->getActiveEmail();
            
            switch ($action) {
                case 'move':
                    if ($value && $value !== $folder) {
                        $result = $imap->moveMessage($folder, $uid, $value);
                        if ($result && $activeEmail) {
                            $newUid = $imap->getLastMoveNewUid();
                            try {
                                $conversationService = $this->getConversationService();
                                $conversationService->moveConversationMember($activeEmail, $folder, $uid, $value, $newUid);
                            } catch (\Exception $e) {
                                error_log("[FilterController::applyAction] Failed to sync move: " . $e->getMessage());
                            }
                            $cache = $this->getRedisCache();
                            $cache->invalidateMessage($activeEmail, $folder, $uid);
                            $cache->invalidateFolder($activeEmail, $folder);
                            $cache->invalidateFolder($activeEmail, $value);
                            $cache->publishMessageMoved($activeEmail, $folder, $value, $uid, $newUid);
                        }
                    }
                    return $result;
                    
                case 'delete':
                    $result = $imap->deleteMessage($folder, $uid);
                    if ($result && $activeEmail) {
                        try {
                            $conversationService = $this->getConversationService();
                            $conversationService->deleteConversationMember($activeEmail, $folder, $uid);
                        } catch (\Exception $e) {
                            error_log("[FilterController::applyAction] Failed to sync delete: " . $e->getMessage());
                        }
                        $cache = $this->getRedisCache();
                        $cache->invalidateMessage($activeEmail, $folder, $uid);
                        $cache->invalidateFolder($activeEmail, $folder);
                        $cache->publishMessageDeleted($activeEmail, $folder, $uid, true);
                    }
                    return $result;
                    
                case 'mark_read':
                    $result = $imap->setFlag($folder, $uid, 'seen', true);
                    if ($result && $activeEmail) {
                        try {
                            $conversationService = $this->getConversationService();
                            $conversationService->updateMemberReadStatus($activeEmail, $folder, $uid, true);
                        } catch (\Exception $e) {
                            // Ignore
                        }
                        $cache = $this->getRedisCache();
                        $cache->invalidateMessage($activeEmail, $folder, $uid);
                        $cache->publishFlagsChanged($activeEmail, $folder, $uid, [
                            'flag' => 'seen', 'value' => true, 'imapFlags' => ['\\Seen'],
                        ]);
                        // Folder count refresh is handled by the frontend's FLAGS_CHANGED ->
                        // debouncedFetchFolders cascade. We do NOT call getFolderStatus()
                        // here because imap_status() races with imap_setflag_full and
                        // would broadcast a poisoned unread=0 (the INBOX badge flicker).
                    }
                    return $result;
                    
                case 'mark_unread':
                    $result = $imap->setFlag($folder, $uid, 'seen', false);
                    if ($result && $activeEmail) {
                        try {
                            $conversationService = $this->getConversationService();
                            $conversationService->updateMemberReadStatus($activeEmail, $folder, $uid, false);
                        } catch (\Exception $e) {
                            // Ignore
                        }
                        $cache = $this->getRedisCache();
                        $cache->invalidateMessage($activeEmail, $folder, $uid);
                        $cache->publishFlagsChanged($activeEmail, $folder, $uid, [
                            'flag' => 'seen', 'value' => false, 'imapFlags' => [],
                        ]);
                        // Folder count refresh handled by frontend FLAGS_CHANGED cascade.
                        // See mark_read above for rationale.
                    }
                    return $result;
                    
                case 'star':
                    $result = $imap->setFlag($folder, $uid, 'flagged', true);
                    if ($result && $activeEmail) {
                        $cache = $this->getRedisCache();
                        $cache->invalidateMessage($activeEmail, $folder, $uid);
                        $cache->publishFlagsChanged($activeEmail, $folder, $uid, [
                            'flag' => 'flagged', 'value' => true, 'imapFlags' => ['\\Flagged'],
                        ]);
                    }
                    return $result;
                    
                case 'unstar':
                    $result = $imap->setFlag($folder, $uid, 'flagged', false);
                    if ($result && $activeEmail) {
                        $cache = $this->getRedisCache();
                        $cache->invalidateMessage($activeEmail, $folder, $uid);
                        $cache->publishFlagsChanged($activeEmail, $folder, $uid, [
                            'flag' => 'flagged', 'value' => false, 'imapFlags' => [],
                        ]);
                    }
                    return $result;
                    
                case 'label':
                    if ($value) {
                        $message = $imap->getMessage($folder, $uid);
                        if ($message && isset($message['message_id'])) {
                            $labelResult = $labelService->addLabelToMessage($activeEmail, $message['message_id'], (int)$value);
                            if ($labelResult && $activeEmail) {
                                $labelData = null;
                                $allLabels = $labelService->getLabels($activeEmail);
                                foreach ($allLabels as $l) {
                                    if ($l['id'] === (int)$value) {
                                        $labelData = $l;
                                        break;
                                    }
                                }
                                $cache = $this->getRedisCache();
                                $cache->publishLabelsChanged($activeEmail, $message['message_id'], (int)$value, 'add', $labelData);
                            }
                            return $labelResult;
                        }
                    }
                    return false;
                    
                default:
                    return false;
            }
        } catch (\Exception $e) {
            error_log("Action error ($action): " . $e->getMessage());
            return false;
        }
    }
    
    private function filtersUseField(array $filters, string $field): bool
    {
        foreach ($filters as $filter) {
            $conditions = $filter['conditions'] ?? [];
            $groups = $conditions['groups'] ?? [$conditions];
            foreach ($groups as $group) {
                $rules = $group['rules'] ?? [];
                foreach ($rules as $rule) {
                    if (($rule['field'] ?? '') === $field) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
    
    /**
     * Auto-sync Sieve after any filter change (create/update/delete).
     * Non-fatal: if sync fails, the DB change is still kept.
     * Returns ['synced' => bool, 'warning' => ?string] for the API response.
     */
    private function autoSyncSieve(string $email): array
    {
        try {
            $creds = $this->getActiveCredentials();
            $password = $creds['password'] ?? null;

            $syncService = new SieveSyncService($this->config);
            $result = $syncService->sync($email, $password);

            if (!$result['success']) {
                $err = $result['error'] ?? 'unknown';
                error_log("[FilterController::autoSyncSieve] Sync failed for {$email}: {$err}");
                return ['synced' => false, 'warning' => 'Filter saved but server-side sync failed: ' . $err];
            }

            return ['synced' => true, 'warning' => null];
        } catch (\Throwable $e) {
            error_log("[FilterController::autoSyncSieve] Error: " . $e->getMessage());
            return ['synced' => false, 'warning' => 'Filter saved but server-side sync failed'];
        }
    }

    /**
     * Sync filters to server-side Sieve (ManageSieve)
     * This enables automatic filtering of incoming emails 24/7.
     * Includes user filters, blocked/safe senders, and vacation in one script.
     */
    public function syncSieve(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $creds = $this->getActiveCredentials();
        $activeEmail = $creds['email'];
        $activePassword = $creds['password'] ?? null;
        $isOAuth = empty($activePassword);
        
        try {
            $syncService = new SieveSyncService($this->config);
            $result = $syncService->sync($activeEmail, $activePassword);
            
            if (!$result['success']) {
                error_log("Sieve sync failed for {$activeEmail}: " . ($result['error'] ?? 'Unknown error'));
                return Response::error('Failed to sync filters: ' . ($result['error'] ?? 'Unknown error'));
            }
            
            $filters = $this->filterService->getFilters($activeEmail);
            $vacation = SieveSyncService::getActiveVacationConfig($activeEmail);
            
            return Response::success([
                'synced' => true,
                'method' => $result['method'] ?? ($isOAuth ? 'disk' : 'managesieve'),
                'oauth_account' => $isOAuth,
                'filters_count' => count(array_filter($filters, fn($f) => $f['enabled'])),
                'vacation_active' => $vacation !== null,
                'script_preview' => $result['script'] ?? null,
            ], 'Filters synced to server');
            
        } catch (\Exception $e) {
            error_log("Sieve sync error: " . $e->getMessage());
            return Response::error('Failed to sync filters: ' . $e->getMessage());
        }
    }
    
    /**
     * Get Sieve sync status
     */
    public function sieveStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $creds = $this->getActiveCredentials();
        $activeEmail = $creds['email'];
        $activePassword = $creds['password'] ?? null;
        $isOAuth = empty($activePassword);
        
        // OAuth accounts: check disk-based sieve script existence
        if ($isOAuth) {
            $active = $this->checkDiskSieveActive($activeEmail);
            return Response::success([
                'available' => true,
                'method' => 'disk',
                'oauth_account' => true,
                'active' => $active,
            ]);
        }
        
        try {
            $sieveConfig = $this->config['imap'];
            $host = $sieveConfig['sieve_host'] ?? $sieveConfig['host'] ?? 'localhost';
            $port = $sieveConfig['sieve_port'] ?? 4190;
            
            $sieveService = new SieveService($sieveConfig);
            
            if (!$sieveService->connect($activeEmail, $activePassword)) {
                $error = $sieveService->getLastError();
                error_log("Sieve connection failed: $error");
                return Response::success([
                    'available' => false,
                    'error' => $error,
                    'active' => false,
                    'debug' => [
                        'host' => $host,
                        'port' => $port,
                    ],
                ]);
            }
            
            $scripts = $sieveService->listScripts();
            $sieveService->disconnect();
            
            $ourScript = null;
            foreach ($scripts as $script) {
                if ($script['name'] === SieveService::SCRIPT_NAME) {
                    $ourScript = $script;
                    break;
                }
            }
            
            return Response::success([
                'available' => true,
                'method' => 'managesieve',
                'active' => $ourScript ? $ourScript['active'] : false,
                'scripts' => $scripts,
                'debug' => [
                    'host' => $host,
                    'port' => $port,
                ],
            ]);
            
        } catch (\Exception $e) {
            error_log("Sieve status exception: " . $e->getMessage());
            return Response::success([
                'available' => false,
                'error' => $e->getMessage(),
                'active' => false,
            ]);
        }
    }

    /**
     * Check if a disk-based Sieve script exists and has active rules (for OAuth accounts).
     */
    private function checkDiskSieveActive(string $email): bool
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return false;

        [$user, $domain] = $parts;
        $scriptPath = "/home/vmail/{$domain}/{$user}/sieve/webmail_filters.sieve";

        $output = [];
        $rc = 0;
        exec("sudo test -s " . escapeshellarg($scriptPath) . " && echo 'exists' 2>/dev/null", $output, $rc);

        return $rc === 0 && !empty($output);
    }
}

