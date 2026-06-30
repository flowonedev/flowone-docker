<?php

namespace Webmail\Addons\Moodboards\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Moodboards\Services\MoodBoardService;
use Webmail\Addons\Moodboards\Services\MoodBoardPptxService;
use Webmail\Services\RedisCacheService;
use Webmail\Services\SearchIndexerService;

class MoodBoardController extends BaseController
{
    private ?MoodBoardService $moodBoardService = null;
    private ?RedisCacheService $redisCache = null;
    private ?SearchIndexerService $searchIndexer = null;
    
    private function getSearchIndexer(): SearchIndexerService
    {
        if (!$this->searchIndexer) {
            $this->searchIndexer = new SearchIndexerService($this->config);
        }
        return $this->searchIndexer;
    }
    
    /**
     * Index a mood board item for search (call after add/update)
     */
    private function triggerMoodBoardItemIndex(array $item, int $boardId): void
    {
        try {
            $email = $this->getActiveEmail();
            $board = $this->getMoodBoardService()->getBoard($email, $boardId);
            
            // Get todo texts if todo_list
            $todoTexts = [];
            if (($item['type'] ?? '') === 'todo_list' && !empty($item['todos'])) {
                foreach ($item['todos'] as $todo) {
                    if (!empty($todo['text'])) {
                        $todoTexts[] = $todo['text'];
                    }
                }
            }
            
            // Index for the owner
            $ownerEmail = $board['owner_email'] ?? $email;
            $this->getSearchIndexer()->indexMoodBoardItem($ownerEmail, $item, $board ?: [], $todoTexts);
            
            // Also index for board members
            if ($board) {
                $memberEmails = $this->getMoodBoardService()->getBoardMemberEmails($boardId);
                foreach ($memberEmails as $memberEmail) {
                    if (strtolower($memberEmail) !== strtolower($ownerEmail)) {
                        $this->getSearchIndexer()->indexMoodBoardItem($memberEmail, $item, $board, $todoTexts);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("MoodBoardController triggerMoodBoardItemIndex error: " . $e->getMessage());
        }
    }
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->moodBoardService = new MoodBoardService($config);
    }
    
    private function getMoodBoardService(): MoodBoardService
    {
        if (!$this->moodBoardService) {
            $this->moodBoardService = new MoodBoardService($this->config);
        }
        return $this->moodBoardService;
    }
    
    private function getRedisCache(): RedisCacheService
    {
        if ($this->redisCache === null) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }
    
    /** Max items accepted by AI modify/variations and public batch endpoints. */
    private const MAX_BATCH_ITEMS = 200;
    
    /**
     * Per-user rate limit for AI endpoints (OpenAI spend protection).
     * Returns a 429 Response when over the limit, null when allowed.
     */
    private function checkAiRateLimit(): ?Response
    {
        try {
            $limiter = new \Webmail\Services\RateLimiter($this->config);
            $key = 'rl:mood-ai:' . md5(strtolower((string)$this->userEmail));
            $result = $limiter->allow($key, 30, 3600);
            if (!$result['allowed']) {
                header('Retry-After: ' . $result['retry_after']);
                return Response::json([
                    'success' => false,
                    'message' => 'AI rate limit reached. Try again in ' . max(1, (int)ceil($result['retry_after'] / 60)) . ' minute(s).',
                ], 429);
            }
        } catch (\Throwable $e) {
            // Limiter outage must not block the feature
        }
        return null;
    }
    
    /**
     * Resolve the server encryption key. Returns null when not configured —
     * never fall back to a hardcoded secret.
     */
    private function getEncryptionKey(): ?string
    {
        $secret = $this->config['encryption_key'] ?? '';
        return is_string($secret) && $secret !== '' ? $secret : null;
    }
    
    /**
     * Brute-force protection for share-link password attempts.
     * 10 attempts per IP per 15 minutes. Returns 429 Response when exceeded.
     */
    private function checkSharePasswordRateLimit(Request $request, string $token): ?Response
    {
        try {
            $limiter = new \Webmail\Services\RateLimiter($this->config);
            $key = 'rl:mood-share-pw:' . md5($request->getClientIp() . ':' . $token);
            $result = $limiter->allow($key, 10, 900);
            if (!$result['allowed']) {
                header('Retry-After: ' . $result['retry_after']);
                return Response::json([
                    'success' => false,
                    'message' => 'Too many password attempts. Try again later.',
                ], 429);
            }
        } catch (\Throwable $e) {
            // Limiter outage must not block legitimate access
        }
        return null;
    }
    
    /**
     * Broadcast a mood board event to all members (excluding the sender).
     * The sender's email is excluded from per-user Redis channels to prevent
     * echo events from overwriting the sender's optimistic local state.
     * The board-level room channel still carries sender_email so that the
     * frontend client can skip events it originated.
     */
    private function broadcastMoodBoardEvent(string $eventType, int $boardId, array $payload, ?string $excludeEmail = null): void
    {
        try {
            $memberEmails = $this->getMoodBoardService()->getBoardMemberEmails($boardId);

            $redis = $this->getRedisCache();
            // Attach a unique event_id so the frontend can dedup dual-path deliveries
            $eventId = bin2hex(random_bytes(8)) . '-' . microtime(true);
            $mergedPayload = array_merge(['board_id' => $boardId, 'event_id' => $eventId], $payload);

            // Attach per-tab sender_id so the frontend can distinguish same-user
            // multi-tab sessions (sender_email only filters by user, not by tab).
            // Sanitize: it is client-supplied and gets broadcast to all members.
            $senderId = $_SERVER['HTTP_X_SENDER_ID'] ?? null;
            if ($senderId && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $senderId)) {
                $mergedPayload['sender_id'] = $senderId;
            }

            $excludeLower = $excludeEmail ? strtolower($excludeEmail) : null;

            if (!empty($memberEmails)) {
                foreach ($memberEmails as $email) {
                    if ($excludeLower && strtolower($email) === $excludeLower) {
                        continue;
                    }
                    $redis->publishEvent($email, $eventType, $mergedPayload);
                }
            }

            // Also publish to the board-level channel so guests (who have
            // no user-email Redis channel) receive the event via the WS room.
            // Include sender_email so the frontend can filter out its own echoes.
            if ($excludeEmail) {
                $mergedPayload['sender_email'] = $excludeEmail;
            }
            $redis->publishMoodBoardRoomEvent($boardId, $eventType, $mergedPayload);
        } catch (\Throwable $e) {
            // Don't fail the request if broadcasting fails
            error_log("MoodBoard broadcast error: " . $e->getMessage());
        }
    }
    
    /**
     * Broadcast a comment event to ALL board members via Redis.
     * Used by public (share-token) endpoints where there is no authenticated sender to exclude.
     */
    private function broadcastPublicCommentEvent(string $eventType, int $boardId, array $payload): void
    {
        try {
            $memberEmails = $this->getMoodBoardService()->getBoardMemberEmails($boardId);
            $redis = $this->getRedisCache();
            $mergedPayload = array_merge(['board_id' => $boardId], $payload);

            if (!empty($memberEmails)) {
                foreach ($memberEmails as $email) {
                    $redis->publishEvent($email, $eventType, $mergedPayload);
                }
            }

            // Also publish to the board-level channel for guest-to-guest relay.
            $redis->publishMoodBoardRoomEvent($boardId, $eventType, $mergedPayload);
        } catch (\Throwable $e) {
            error_log("MoodBoard public comment broadcast error: " . $e->getMessage());
        }
    }

    /**
     * Broadcast an activity entry to all board members (including sender for real-time feed)
     */
    private function broadcastActivity(
        int $boardId,
        string $action,
        ?int $itemId = null,
        ?string $itemType = null,
        ?string $itemLabel = null,
        ?int $targetItemId = null,
        ?string $targetLabel = null
    ): void {
        try {
            $senderEmail = strtolower($this->getActiveEmail());
            $userName = explode('@', $senderEmail)[0];
            $memberEmails = $this->getMoodBoardService()->getBoardMemberEmails($boardId);
            
            $payload = [
                'board_id' => $boardId,
                'user_email' => $senderEmail,
                'user_name' => $userName,
                'action' => $action,
                'item_id' => $itemId,
                'item_type' => $itemType,
                'item_label' => $itemLabel,
                'target_item_id' => $targetItemId,
                'target_label' => $targetLabel,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            
            $redis = $this->getRedisCache();
            foreach ($memberEmails as $email) {
                $redis->publishEvent($email, 'MOOD_BOARD_ACTIVITY', $payload);
            }
        } catch (\Throwable $e) {
            error_log("MoodBoard activity broadcast error: " . $e->getMessage());
        }
    }
    
    // ========================================
    // READY STATE TOGGLE
    // ========================================
    
    /**
     * POST /mood-boards/{id}/ready - Toggle the "ready" state on a mood board
     */
    public function toggleReady(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $board = $this->moodBoardService->toggleReady($this->getActiveEmail(), $boardId);
        
        if (!$board) {
            return Response::json(['success' => false, 'message' => 'Board not found or access denied'], 404);
        }
        
        // Broadcast to collaborators
        $this->broadcastMoodBoardEvent('MOOD_BOARD_UPDATED', $boardId, ['board' => $board], $this->getActiveEmail());
        
        $action = !empty($board['is_ready']) ? 'marked_ready' : 'unmarked_ready';
        $this->broadcastActivity($boardId, $action);
        
        return Response::json(['success' => true, 'data' => ['board' => $board], 'message' => $action === 'marked_ready' ? 'Board marked as ready' : 'Board unmarked as ready']);
    }
    
    // ========================================
    // ACTIVITY ENDPOINTS
    // ========================================
    
    /**
     * GET /mood-boards/{id}/activity - Get activity log
     */
    public function getActivity(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $limit = (int)($request->getQuery('limit', 100));
        $offset = (int)($request->getQuery('offset', 0));
        
        $activities = $this->moodBoardService->getActivity($this->getActiveEmail(), $boardId, $limit, $offset);
        
        return Response::json(['success' => true, 'data' => ['activities' => $activities]]);
    }
    
    // ========================================
    // BOARD ENDPOINTS
    // ========================================
    
    /**
     * GET /mood-boards - List all mood boards
     */
    public function listBoards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $includeArchived = $request->getQuery('include_archived', 'false') === 'true';
        $boards = $this->moodBoardService->getBoards($this->getActiveEmail(), $includeArchived);
        
        return Response::json(['success' => true, 'data' => ['boards' => $boards]]);
    }
    
    /**
     * GET /mood-boards/{id} - Get a single board with all items
     */
    public function getBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $board = $this->moodBoardService->getBoard($this->getActiveEmail(), $id);
        
        if (!$board) {
            return Response::json(['success' => false, 'message' => 'Board not found or access denied'], 404);
        }
        
        return Response::json(['success' => true, 'data' => ['board' => $board]]);
    }
    
    /**
     * POST /mood-boards - Create a new mood board
     */
    public function createBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'background_color' => $request->input('background_color'),
            'client_id' => $request->input('client_id'),
            'folder_id' => $request->input('folder_id'),
        ];
        
        if (empty($data['name'])) {
            return Response::json(['success' => false, 'message' => 'Board name is required'], 400);
        }
        
        $board = $this->moodBoardService->createBoard($this->getActiveEmail(), $data);
        
        if (!$board) {
            return Response::json(['success' => false, 'message' => 'Failed to create board'], 500);
        }
        
        return Response::json(['success' => true, 'data' => ['board' => $board], 'message' => 'Board created']);
    }
    
    /**
     * PUT /mood-boards/{id} - Update a mood board
     */
    public function updateBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        $data = [];
        $fields = ['name', 'description', 'background_color', 'background_image',
                    'background_image_size',
                    'canvas_width', 'canvas_height', 'zoom_level', 'viewport_x',
                    'viewport_y', 'canvas_strokes', 'archived', 'client_id', 'folder_id',
                    'conn_panel_position'];
        
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }
        
        // JSON fields (stored as JSON strings)
        $jsonFields = ['motion_settings', 'color_palette', 'gradient_palette',
                       'background_effect', 'brush_presets', 'brush_settings', 'guides', 'bg_audio',
                       'global_text_styles'];
        foreach ($jsonFields as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                $data[$field] = is_array($val) ? json_encode($val) : $val;
            }
        }
        
        $board = $this->moodBoardService->updateBoard($this->getActiveEmail(), $id, $data);
        
        if (!$board) {
            return Response::json(['success' => false, 'message' => 'Board not found or access denied'], 404);
        }
        
        // Broadcast collaborative property changes (skip viewport-only saves)
        $viewportOnly = ['zoom_level', 'viewport_x', 'viewport_y'];
        if (!empty(array_diff(array_keys($data), $viewportOnly))) {
            $this->broadcastMoodBoardEvent('MOOD_BOARD_UPDATED', $id, ['board' => $board], $this->getActiveEmail());
        }
        
        return Response::json(['success' => true, 'data' => ['board' => $board], 'message' => 'Board updated']);
    }
    
    /**
     * DELETE /mood-boards/{id} - Delete a mood board
     */
    public function deleteBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        if (!$this->moodBoardService->deleteBoard($this->getActiveEmail(), $id)) {
            return Response::json(['success' => false, 'message' => 'Board not found or access denied'], 404);
        }
        
        return Response::json(['success' => true, 'message' => 'Board deleted']);
    }
    
    /**
     * POST /mood-boards/{id}/duplicate - Duplicate a mood board
     */
    public function duplicateBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $newName = $request->input('name');
        
        $board = $this->moodBoardService->duplicateBoard($this->getActiveEmail(), $id, $newName);
        
        if (!$board) {
            return Response::json(['success' => false, 'message' => 'Failed to duplicate board'], 500);
        }
        
        return Response::json(['success' => true, 'data' => ['board' => $board], 'message' => 'Board duplicated']);
    }
    
    // ========================================
    // ITEM ENDPOINTS
    // ========================================
    
    /**
     * POST /mood-boards/{id}/items - Add an item to the canvas
     */
    public function addItem(Request $request): Response
    {
        try {
            $authError = $this->requireAuth($request);
            if ($authError) return $authError;
            
            $boardId = (int)$request->getParam('id');
            $email = $this->getActiveEmail();
            
            $data = [
                'type' => $request->input('type'),
                'pos_x' => (int)$request->input('pos_x', 0),
                'pos_y' => (int)$request->input('pos_y', 0),
                'width' => $request->input('width') ? (int)$request->input('width') : 240,
                'height' => $request->input('height') ? (int)$request->input('height') : null,
                'rotation' => (float)$request->input('rotation', 0),
                'parent_id' => $request->input('parent_id') ? (int)$request->input('parent_id') : null,
                'title' => $request->input('title'),
                'content' => $request->input('content'),
                'color' => $request->input('color'),
                'url' => $request->input('url'),
                'drive_file_id' => $request->input('drive_file_id') ? (int)$request->input('drive_file_id') : null,
                'image_url' => $request->input('image_url'),
                'thumbnail_url' => $request->input('thumbnail_url'),
                'linked_board_id' => $request->input('linked_board_id') ? (int)$request->input('linked_board_id') : null,
                'linked_card_id' => $request->input('linked_card_id') ? (int)$request->input('linked_card_id') : null,
                'style_data' => $request->input('style_data'),
                'todos' => $request->input('todos'),
                'slide_order' => $request->input('slide_order') !== null ? (int)$request->input('slide_order') : null,
                'transition_type' => $request->input('transition_type', 'fly'),
                'transition_duration' => $request->input('transition_duration') !== null ? (float)$request->input('transition_duration') : null,
                'presenter_notes' => $request->input('presenter_notes'),
            ];
            
            // Handle color_data for color_swatch items
            if ($request->has('color_data')) {
                $data['color_data'] = $request->input('color_data');
            }
            
            // Handle calendar_event_id
            if ($request->has('calendar_event_id')) {
                $data['calendar_event_id'] = (int)$request->input('calendar_event_id');
            }
            
            // Handle image_set_items for image_set creation
            if ($request->has('image_set_items')) {
                $data['image_set_items'] = $request->input('image_set_items');
            }
            
            $validTypes = ['note', 'image', 'text', 'link', 'todo_list', 'file', 'color_swatch', 'board_link', 'frame', 'slide', 'image_set', 'calendar_event', 'drawing', 'table', 'column', 'folder', 'shape', 'pen_shape', 'video', 'youtube', 'line', 'artboard', 'audio', 'group', 'repeat_grid'];
            if (!in_array($data['type'], $validTypes)) {
                return Response::json(['success' => false, 'message' => 'Invalid item type: ' . ($data['type'] ?? 'null')], 400);
            }
            
            $this->getMoodBoardService()->log("addItem DEBUG: board={$boardId}, email={$email}, type={$data['type']}");
            
            $item = $this->moodBoardService->addItem($email, $boardId, $data);
            
            if (!$item) {
                $this->getMoodBoardService()->log("addItem FAILED: board={$boardId}, email={$email}, type={$data['type']} — service returned null (access denied or DB error)");
                return Response::json(['success' => false, 'message' => 'Failed to add item'], 500);
            }
            
            // Log activity
            $label = $this->moodBoardService->getItemLabel($item);
            $this->moodBoardService->logActivity($boardId, $email, 'item_added', (int)$item['id'], $data['type'], $label);
            
            // Index for search
            $this->triggerMoodBoardItemIndex($item, $boardId);
            
            // Broadcast to collaborators (include activity for real-time panel)
            $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEM_CREATED', $boardId, ['item' => $item], $email);
            $this->broadcastActivity($boardId, 'item_added', (int)$item['id'], $data['type'], $label);
            
            return Response::json(['success' => true, 'data' => ['item' => $item], 'message' => 'Item added']);
        } catch (\Throwable $e) {
            // Catch-all: log ANY error that could cause a 500
            @file_put_contents(
                __DIR__ . '/../../../../storage/mood-boards.log',
                '[' . date('Y-m-d H:i:s') . "] addItem UNCAUGHT: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n",
                FILE_APPEND
            );
            return Response::json(['success' => false, 'message' => 'Server error'], 500);
        }
    }
    
    /**
     * PUT /mood-boards/{id}/items/{itemId} - Update an item
     */
    public function updateItem(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $itemId = (int)$request->getParam('itemId');
        
        $data = [];
        $fields = ['parent_id', 'pos_x', 'pos_y', 'width', 'height', 'rotation',
                    'z_index', 'locked', 'title', 'content', 'color', 'url',
                    'drive_file_id', 'image_url', 'thumbnail_url',
                    'linked_board_id', 'linked_card_id', 'calendar_event_id',
                    'style_data', 'color_data',
                    'slide_order', 'transition_type', 'transition_duration', 'presenter_notes'];
        
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }
        
        $item = $this->moodBoardService->updateItem($this->getActiveEmail(), $boardId, $itemId, $data);
        
        if (!$item) {
            return Response::json(['success' => false, 'message' => 'Item not found or access denied'], 404);
        }
        
        // Log meaningful changes (skip position-only / style-only noise)
        $label = $this->moodBoardService->getItemLabel($item);
        if (array_key_exists('locked', $data)) {
            $action = $data['locked'] ? 'item_locked' : 'item_unlocked';
            $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), $action, $itemId, $item['type'] ?? null, $label);
            $this->broadcastActivity($boardId, $action, $itemId, $item['type'] ?? null, $label);
        } elseif (array_key_exists('title', $data) || array_key_exists('content', $data)) {
            $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), 'item_edited', $itemId, $item['type'] ?? null, $label);
            $this->broadcastActivity($boardId, 'item_edited', $itemId, $item['type'] ?? null, $label);
        }
        
        // Re-index if content changed (skip position-only updates)
        if (array_key_exists('title', $data) || array_key_exists('content', $data) || array_key_exists('url', $data)) {
            $this->triggerMoodBoardItemIndex($item, $boardId);
        }
        
        // Broadcast to collaborators
        $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEM_UPDATED', $boardId, ['item' => $item], $this->getActiveEmail());
        
        return Response::json(['success' => true, 'data' => ['item' => $item]]);
    }
    
    /**
     * PUT /mood-boards/{id}/items/batch - Batch update item positions
     */
    public function batchUpdateItems(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $updates = $request->input('updates');
        
        if (!is_array($updates) || empty($updates)) {
            return Response::json(['success' => false, 'message' => 'Updates array required'], 400);
        }
        
        $success = $this->moodBoardService->batchUpdateItems($this->getActiveEmail(), $boardId, $updates);
        
        if (!$success) {
            return Response::json(['success' => false, 'message' => 'Failed to update items'], 500);
        }
        
        // Broadcast position updates to collaborators
        $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_MOVED', $boardId, ['updates' => $updates], $this->getActiveEmail());
        
        return Response::json(['success' => true, 'message' => 'Items updated']);
    }
    
    /**
     * DELETE /mood-boards/{id}/items/{itemId} - Delete an item
     */
    public function deleteItem(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $itemId = (int)$request->getParam('itemId');
        
        // Get item info before deleting for the activity log
        $itemForLog = $this->moodBoardService->getItem($itemId);
        
        if (!$this->moodBoardService->deleteItem($this->getActiveEmail(), $boardId, $itemId)) {
            return Response::json(['success' => false, 'message' => 'Item not found or access denied'], 404);
        }
        
        // Log activity
        $label = $itemForLog ? $this->moodBoardService->getItemLabel($itemForLog) : 'Item';
        $type = $itemForLog['type'] ?? null;
        $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), 'item_deleted', $itemId, $type, $label);
        $this->broadcastActivity($boardId, 'item_deleted', $itemId, $type, $label);
        
        // Remove from search index
        try {
            $email = $this->getActiveEmail();
            $board = $this->getMoodBoardService()->getBoard($email, $boardId);
            $ownerEmail = $board['owner_email'] ?? $email;
            $this->getSearchIndexer()->removeFromIndex($ownerEmail, 'mood_board_item', (string)$itemId);
            
            if ($board) {
                $memberEmails = $this->getMoodBoardService()->getBoardMemberEmails($boardId);
                foreach ($memberEmails as $memberEmail) {
                    if (strtolower($memberEmail) !== strtolower($ownerEmail)) {
                        $this->getSearchIndexer()->removeFromIndex($memberEmail, 'mood_board_item', (string)$itemId);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("MoodBoardController deleteItem search index error: " . $e->getMessage());
        }
        
        // Broadcast to collaborators
        $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEM_DELETED', $boardId, ['item_id' => $itemId], $this->getActiveEmail());
        
        return Response::json(['success' => true, 'message' => 'Item deleted']);
    }
    
    /**
     * POST /mood-boards/{id}/items/batch-delete - Batch delete multiple items at once
     */
    public function batchDeleteItems(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $itemIds = $request->input('item_ids');
        
        if (!is_array($itemIds) || empty($itemIds)) {
            return Response::json(['success' => false, 'message' => 'item_ids array required'], 400);
        }
        
        // Sanitize IDs
        $itemIds = array_map('intval', $itemIds);

        // Auto-snapshot before batch delete (safety net for bulk operations)
        if (count($itemIds) >= 3) {
            $this->moodBoardService->createSnapshot($boardId, $this->getActiveEmail(), 'pre_delete', 'Before deleting ' . count($itemIds) . ' items');
        }

        $deleted = $this->moodBoardService->batchDeleteItems($this->getActiveEmail(), $boardId, $itemIds);
        
        // Always return 200: if 0 rows changed the items were already soft-deleted
        // or don't exist -- either way the user's intent (remove from canvas) is fulfilled.
        
        if ($deleted > 0) {
            $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), 'items_batch_deleted', null, null, "{$deleted} items");
            $this->broadcastActivity($boardId, 'items_batch_deleted', null, null, "{$deleted} items");
        }
        
        // Remove from search index
        try {
            $email = $this->getActiveEmail();
            $board = $this->getMoodBoardService()->getBoard($email, $boardId);
            $ownerEmail = $board['owner_email'] ?? $email;
            foreach ($itemIds as $itemId) {
                $this->getSearchIndexer()->removeFromIndex($ownerEmail, 'mood_board_item', (string)$itemId);
            }
            if ($board) {
                $memberEmails = $this->getMoodBoardService()->getBoardMemberEmails($boardId);
                foreach ($memberEmails as $memberEmail) {
                    if (strtolower($memberEmail) !== strtolower($ownerEmail)) {
                        foreach ($itemIds as $itemId) {
                            $this->getSearchIndexer()->removeFromIndex($memberEmail, 'mood_board_item', (string)$itemId);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("MoodBoardController batchDeleteItems search index error: " . $e->getMessage());
        }
        
        // Broadcast to collaborators
        if ($deleted > 0) {
            $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_DELETED', $boardId, ['item_ids' => $itemIds], $this->getActiveEmail());
        }
        
        return Response::json(['success' => true, 'data' => ['deleted' => $deleted]]);
    }

    /**
     * POST /mood-boards/{id}/items/{itemId}/restore - Restore a soft-deleted item
     */
    public function restoreItem(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $itemId = (int)$request->getParam('itemId');

        $restored = $this->moodBoardService->restoreItem($this->getActiveEmail(), $boardId, $itemId);

        if (!$restored) {
            return Response::json(['success' => false, 'message' => 'Item not found in trash or access denied'], 404);
        }

        $item = $this->moodBoardService->getItem($itemId);
        $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEM_CREATED', $boardId, ['item' => $item], $this->getActiveEmail());

        return Response::json(['success' => true, 'data' => ['item' => $item], 'message' => 'Item restored']);
    }

    /**
     * POST /mood-boards/{id}/items/restore-batch - Restore multiple soft-deleted items
     */
    public function restoreItems(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $itemIds = $request->input('item_ids');

        if (!is_array($itemIds) || empty($itemIds)) {
            return Response::json(['success' => false, 'message' => 'item_ids array required'], 400);
        }

        $itemIds = array_map('intval', $itemIds);
        $count = $this->moodBoardService->restoreItems($this->getActiveEmail(), $boardId, $itemIds);

        if ($count === 0) {
            return Response::json(['success' => false, 'message' => 'No items restored'], 404);
        }

        $restoredItems = [];
        foreach ($itemIds as $id) {
            $item = $this->moodBoardService->getItem($id);
            if ($item) $restoredItems[] = $item;
        }
        $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_CREATED', $boardId, ['items' => $restoredItems], $this->getActiveEmail());

        return Response::json(['success' => true, 'data' => ['restored' => $count]]);
    }

    /**
     * POST /mood-boards/{id}/restore-all - Restore all soft-deleted items on a board
     */
    public function restoreAllItems(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $count = $this->moodBoardService->restoreAllItems($this->getActiveEmail(), $boardId);

        if ($count > 0) {
            $this->broadcastMoodBoardEvent('MOOD_BOARD_FULL_REFRESH', $boardId, ['board_id' => $boardId], $this->getActiveEmail());
        }

        return Response::json(['success' => true, 'data' => ['restored' => $count]]);
    }

    /**
     * GET /mood-boards/{id}/trash - Get soft-deleted items for a board
     */
    public function getTrash(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $items = $this->moodBoardService->getDeletedItems($this->getActiveEmail(), $boardId);

        return Response::json(['success' => true, 'data' => ['items' => $items]]);
    }

    // ========================================
    // SNAPSHOTS
    // ========================================

    /**
     * GET /mood-boards/{id}/snapshots - List snapshots for a board
     */
    public function getSnapshots(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $snapshots = $this->moodBoardService->getSnapshots($this->getActiveEmail(), $boardId);

        return Response::json(['success' => true, 'data' => ['snapshots' => $snapshots]]);
    }

    /**
     * POST /mood-boards/{id}/snapshots - Create a manual snapshot
     */
    public function createSnapshot(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $label = $request->input('label') ?: 'Manual save';

        $id = $this->moodBoardService->createSnapshot($boardId, $this->getActiveEmail(), 'manual', $label);

        if (!$id) {
            return Response::json(['success' => false, 'message' => 'Failed to create snapshot'], 500);
        }

        return Response::json(['success' => true, 'data' => ['snapshot_id' => $id]]);
    }

    /**
     * POST /mood-boards/{id}/snapshots/{snapshotId}/restore - Restore board from snapshot
     */
    public function restoreSnapshot(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $snapshotId = (int)$request->getParam('snapshotId');

        $restored = $this->moodBoardService->restoreSnapshot($this->getActiveEmail(), $boardId, $snapshotId);

        if (!$restored) {
            return Response::json(['success' => false, 'message' => 'Snapshot not found or access denied'], 404);
        }

        $this->broadcastMoodBoardEvent('MOOD_BOARD_FULL_REFRESH', $boardId, ['board_id' => $boardId], $this->getActiveEmail());

        return Response::json(['success' => true, 'message' => 'Board restored from snapshot']);
    }

    /**
     * POST /mood-boards/{id}/items/batch-add - Batch add multiple items at once
     */
    public function batchAddItems(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $items = $request->input('items');
        
        if (!is_array($items) || empty($items)) {
            return Response::json(['success' => false, 'message' => 'items array required'], 400);
        }
        
        try {
            $newItems = $this->moodBoardService->batchAddItems($this->getActiveEmail(), $boardId, $items);
        } catch (\RuntimeException $e) {
            error_log("MoodBoardController::batchAddItems error: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Failed to add items'], 500);
        }
        
        if (empty($newItems)) {
            return Response::json(['success' => false, 'message' => 'Failed to add items or access denied'], 500);
        }
        
        // Log activity
        $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), 'items_batch_added', null, null, count($newItems) . " items");
        $this->broadcastActivity($boardId, 'items_batch_added', null, null, count($newItems) . " items");
        
        // Index for search
        foreach ($newItems as $item) {
            $this->triggerMoodBoardItemIndex($item, $boardId);
        }
        
        // Broadcast to collaborators
        $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_CREATED', $boardId, ['items' => $newItems], $this->getActiveEmail());
        
        return Response::json(['success' => true, 'data' => ['items' => $newItems]]);
    }
    
    /**
     * POST /mood-boards/{id}/ai/generate - Generate moodboard items from a text prompt via OpenAI
     */
    public function aiGenerate(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $rateLimited = $this->checkAiRateLimit();
        if ($rateLimited) return $rateLimited;

        try {
            $boardId = (int)$request->getParam('id');
            $prompt = trim($request->input('prompt', ''));

            if (empty($prompt)) {
                return Response::json(['success' => false, 'message' => 'prompt is required'], 400);
            }
            if (strlen($prompt) > 8000) {
                return Response::json(['success' => false, 'message' => 'Prompt too long (max 8000 chars)'], 400);
            }

            // Load AI settings (same path as AIController)
            $hash = md5(strtolower($this->userEmail));
            $aiFile = '/var/www/vps-email/data/global/ai_' . $hash . '.json';

            if (!file_exists($aiFile)) {
                return Response::json(['success' => false, 'message' => 'AI not configured. Add your OpenAI API key in Settings.'], 400);
            }

            $aiSettings = json_decode(file_get_contents($aiFile), true) ?: [];

            if (empty($aiSettings['ai_api_key_encrypted'])) {
                return Response::json(['success' => false, 'message' => 'AI API key not configured'], 400);
            }

            $secret = $this->getEncryptionKey();
            if ($secret === null) {
                error_log('MoodBoard AI: encryption_key is not configured');
                return Response::json(['success' => false, 'message' => 'Server encryption key not configured'], 500);
            }
            $apiKey = \Webmail\Addons\AIAssistant\Services\AIService::decryptApiKey($aiSettings['ai_api_key_encrypted'], $secret);

            if (!$apiKey) {
                return Response::json(['success' => false, 'message' => 'Failed to decrypt AI API key'], 500);
            }

            $model = $aiSettings['ai_model'] ?? 'gpt-4.1-mini';
            $aiService = new \Webmail\Addons\AIAssistant\Services\AIService($apiKey, $model);
            $moodAI = new \Webmail\Addons\Moodboards\Services\MoodBoardAIService($aiService);

            $referenceImage = $request->input('reference_image');
            if ($referenceImage && strlen($referenceImage) > 10 * 1024 * 1024) {
                return Response::json(['success' => false, 'message' => 'Reference image too large (max 10 MB)'], 400);
            }

            $result = $moodAI->generate($prompt, $referenceImage ?: null);

            if (!$result['success']) {
                $errMsg = $result['error'] ?? 'AI generation failed';
                error_log("MoodBoard AI generate error: $errMsg");
                return Response::json(['success' => false, 'message' => $errMsg], 422);
            }

            // Offset generated items to the user's current viewport center
            $vcx = (int)$request->input('viewport_center_x', 0);
            $vcy = (int)$request->input('viewport_center_y', 0);
            if ($vcx !== 0 || $vcy !== 0) {
                $items = $result['items'];
                // Find bounding box center of the generated layout
                $minX = PHP_INT_MAX; $minY = PHP_INT_MAX;
                $maxX = PHP_INT_MIN; $maxY = PHP_INT_MIN;
                foreach ($items as $item) {
                    $x = (int)($item['pos_x'] ?? 0);
                    $y = (int)($item['pos_y'] ?? 0);
                    $w = (int)($item['width'] ?? 0);
                    $h = (int)($item['height'] ?? 0);
                    if ($x < $minX) $minX = $x;
                    if ($y < $minY) $minY = $y;
                    if ($x + $w > $maxX) $maxX = $x + $w;
                    if ($y + $h > $maxY) $maxY = $y + $h;
                }
                $bboxCenterX = (int)(($minX + $maxX) / 2);
                $bboxCenterY = (int)(($minY + $maxY) / 2);
                $offsetX = $vcx - $bboxCenterX;
                $offsetY = $vcy - $bboxCenterY;
                foreach ($items as &$item) {
                    $item['pos_x'] = ($item['pos_x'] ?? 0) + $offsetX;
                    $item['pos_y'] = ($item['pos_y'] ?? 0) + $offsetY;
                }
                unset($item);
                $result['items'] = $items;
            }

            $newItems = $this->moodBoardService->batchAddItems($this->getActiveEmail(), $boardId, $result['items']);

            if (empty($newItems)) {
                return Response::json(['success' => false, 'message' => 'Failed to add AI-generated items to board'], 500);
            }

            $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), 'ai_items_generated', null, null, count($newItems) . ' items from AI');

            try {
                $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_CREATED', $boardId, ['items' => $newItems], $this->getActiveEmail());
            } catch (\Throwable $e) {
                // WebSocket broadcast is non-critical
            }

            foreach ($newItems as $item) {
                try { $this->triggerMoodBoardItemIndex($item, $boardId); } catch (\Throwable $e) {}
            }

            return Response::json([
                'success' => true,
                'data' => ['items' => $newItems],
                'usage' => $result['usage'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log("MoodBoard AI generate exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return Response::json(['success' => false, 'message' => 'AI generation failed'], 500);
        }
    }

    /**
     * POST /mood-boards/{id}/ai/modify - Modify selected items via AI instruction
     */
    public function aiModify(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $rateLimited = $this->checkAiRateLimit();
        if ($rateLimited) return $rateLimited;

        try {
            $boardId = (int)$request->getParam('id');
            $prompt = trim($request->input('prompt', ''));
            $items = $request->input('items');

            if (empty($prompt)) {
                return Response::json(['success' => false, 'message' => 'prompt is required'], 400);
            }
            if (strlen($prompt) > 8000) {
                return Response::json(['success' => false, 'message' => 'Prompt too long (max 8000 chars)'], 400);
            }
            if (!is_array($items) || empty($items)) {
                return Response::json(['success' => false, 'message' => 'items array is required'], 400);
            }
            if (count($items) > self::MAX_BATCH_ITEMS) {
                return Response::json(['success' => false, 'message' => 'Too many items (max ' . self::MAX_BATCH_ITEMS . ')'], 400);
            }

            $hash = md5(strtolower($this->userEmail));
            $aiFile = '/var/www/vps-email/data/global/ai_' . $hash . '.json';

            if (!file_exists($aiFile)) {
                return Response::json(['success' => false, 'message' => 'AI not configured. Add your OpenAI API key in Settings.'], 400);
            }

            $aiSettings = json_decode(file_get_contents($aiFile), true) ?: [];

            if (empty($aiSettings['ai_api_key_encrypted'])) {
                return Response::json(['success' => false, 'message' => 'AI API key not configured'], 400);
            }

            $secret = $this->getEncryptionKey();
            if ($secret === null) {
                error_log('MoodBoard AI: encryption_key is not configured');
                return Response::json(['success' => false, 'message' => 'Server encryption key not configured'], 500);
            }
            $apiKey = \Webmail\Addons\AIAssistant\Services\AIService::decryptApiKey($aiSettings['ai_api_key_encrypted'], $secret);

            if (!$apiKey) {
                return Response::json(['success' => false, 'message' => 'Failed to decrypt AI API key'], 500);
            }

            $model = $aiSettings['ai_model'] ?? 'gpt-4.1-mini';
            $aiService = new \Webmail\Addons\AIAssistant\Services\AIService($apiKey, $model);
            $moodAI = new \Webmail\Addons\Moodboards\Services\MoodBoardAIService($aiService);

            $referenceImage = $request->input('reference_image');
            if ($referenceImage && strlen($referenceImage) > 10 * 1024 * 1024) {
                return Response::json(['success' => false, 'message' => 'Reference image too large (max 10 MB)'], 400);
            }

            $result = $moodAI->modify($prompt, $items, $referenceImage ?: null);

            if (!$result['success']) {
                $errMsg = $result['error'] ?? 'AI modification failed';
                error_log("MoodBoard AI modify error: $errMsg");
                return Response::json(['success' => false, 'message' => $errMsg], 422);
            }

            // Apply updates to each item via the service
            $updatedItems = [];
            foreach ($result['items'] as $modifiedItem) {
                $itemId = (int)($modifiedItem['id'] ?? 0);
                if (!$itemId) continue;

                $updateData = [];
                foreach (['pos_x', 'pos_y', 'width', 'height', 'content'] as $key) {
                    if (isset($modifiedItem[$key])) $updateData[$key] = $modifiedItem[$key];
                }
                if (!empty($modifiedItem['style_data'])) {
                    $updateData['style_data'] = $modifiedItem['style_data'];
                }

                if (!empty($updateData)) {
                    $updated = $this->moodBoardService->updateItem($this->getActiveEmail(), $boardId, $itemId, $updateData);
                    if ($updated) $updatedItems[] = $updated;
                }
            }

            $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), 'ai_items_modified', null, null, count($updatedItems) . ' items modified by AI');

            try {
                $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_UPDATED', $boardId, ['items' => $updatedItems], $this->getActiveEmail());
            } catch (\Throwable $e) {}

            return Response::json([
                'success' => true,
                'data' => ['items' => $updatedItems],
                'usage' => $result['usage'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log("MoodBoard AI modify exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return Response::json(['success' => false, 'message' => 'AI modification failed'], 500);
        }
    }

    /**
     * POST /mood-boards/{id}/ai/variations - Create color variations of selected items
     */
    public function aiVariations(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $rateLimited = $this->checkAiRateLimit();
        if ($rateLimited) return $rateLimited;

        try {
            $boardId = (int)$request->getParam('id');
            $prompt = trim($request->input('prompt', ''));
            $items = $request->input('items');
            $count = max(2, min(10, (int)($request->input('count', 5))));

            if (!is_array($items) || empty($items)) {
                return Response::json(['success' => false, 'message' => 'items array is required'], 400);
            }
            if (count($items) > self::MAX_BATCH_ITEMS) {
                return Response::json(['success' => false, 'message' => 'Too many items (max ' . self::MAX_BATCH_ITEMS . ')'], 400);
            }

            $hash = md5(strtolower($this->userEmail));
            $aiFile = '/var/www/vps-email/data/global/ai_' . $hash . '.json';

            if (!file_exists($aiFile)) {
                return Response::json(['success' => false, 'message' => 'AI not configured. Add your OpenAI API key in Settings.'], 400);
            }

            $aiSettings = json_decode(file_get_contents($aiFile), true) ?: [];
            if (empty($aiSettings['ai_api_key_encrypted'])) {
                return Response::json(['success' => false, 'message' => 'AI API key not configured'], 400);
            }

            $secret = $this->getEncryptionKey();
            if ($secret === null) {
                error_log('MoodBoard AI: encryption_key is not configured');
                return Response::json(['success' => false, 'message' => 'Server encryption key not configured'], 500);
            }
            $apiKey = \Webmail\Addons\AIAssistant\Services\AIService::decryptApiKey($aiSettings['ai_api_key_encrypted'], $secret);
            if (!$apiKey) {
                return Response::json(['success' => false, 'message' => 'Failed to decrypt AI API key'], 500);
            }

            $model = $aiSettings['ai_model'] ?? 'gpt-4.1-mini';
            $aiService = new \Webmail\Addons\AIAssistant\Services\AIService($apiKey, $model);
            $moodAI = new \Webmail\Addons\Moodboards\Services\MoodBoardAIService($aiService);

            $instruction = !empty($prompt) ? $prompt : "Create {$count} color variations with different harmonizing color schemes.";
            $result = $moodAI->variations($instruction, $items, $count);

            if (!$result['success']) {
                $errMsg = $result['error'] ?? 'AI variation generation failed';
                error_log("MoodBoard AI variations error: $errMsg");
                return Response::json(['success' => false, 'message' => $errMsg], 422);
            }

            // Calculate bounding box of original items for offset spacing
            $minX = PHP_INT_MAX; $minY = PHP_INT_MAX;
            $maxX = PHP_INT_MIN; $maxY = PHP_INT_MIN;
            foreach ($items as $item) {
                $x = (int)($item['pos_x'] ?? 0);
                $y = (int)($item['pos_y'] ?? 0);
                $w = (int)($item['width'] ?? 0);
                $h = (int)($item['height'] ?? 0);
                if ($x < $minX) $minX = $x;
                if ($y < $minY) $minY = $y;
                if ($x + $w > $maxX) $maxX = $x + $w;
                if ($y + $h > $maxY) $maxY = $y + $h;
            }
            $bboxW = $maxX - $minX;
            $gap = 40;
            $itemsPerSet = $result['items_per_set'] ?: count($items);

            // Offset each variation set horizontally from the original
            $allItems = $result['items'];
            for ($i = 0; $i < count($allItems); $i++) {
                $setIndex = (int)floor($i / $itemsPerSet);
                $offsetX = ($setIndex + 1) * ($bboxW + $gap);
                $allItems[$i]['pos_x'] = ($allItems[$i]['pos_x'] ?? 0) + $offsetX;
            }

            $newItems = $this->moodBoardService->batchAddItems($this->getActiveEmail(), $boardId, $allItems);

            if (empty($newItems)) {
                return Response::json(['success' => false, 'message' => 'Failed to add variation items'], 500);
            }

            $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), 'ai_variations_created', null, null, count($newItems) . " variation items ({$count} sets)");

            try {
                $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_CREATED', $boardId, ['items' => $newItems], $this->getActiveEmail());
            } catch (\Throwable $e) {}

            return Response::json([
                'success' => true,
                'data' => ['items' => $newItems, 'variation_count' => $count],
                'usage' => $result['usage'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log("MoodBoard AI variations exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return Response::json(['success' => false, 'message' => 'AI variation generation failed'], 500);
        }
    }

    // ========================================
    // FILE UPLOAD ENDPOINTS
    // ========================================
    
    /**
     * POST /mood-boards/{id}/upload - Upload file(s) to mood board
     * Accepts single or multiple file uploads via multipart/form-data
     * Returns upload info with URLs for creating items
     */
    public function uploadFiles(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $service = $this->getMoodBoardService();
        
        if (!$service->hasAccess($this->getActiveEmail(), $boardId, 'editor')) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $uploads = [];
        
        // Handle multiple files (files[] or file)
        if (!empty($_FILES['files'])) {
            $fileCount = is_array($_FILES['files']['name']) ? count($_FILES['files']['name']) : 1;
            
            if (is_array($_FILES['files']['name'])) {
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    
                    $fileInfo = [
                        'name' => $_FILES['files']['name'][$i],
                        'type' => $_FILES['files']['type'][$i],
                        'tmp_name' => $_FILES['files']['tmp_name'][$i],
                        'size' => $_FILES['files']['size'][$i],
                    ];
                    
                    $upload = $service->uploadFile($this->getActiveEmail(), $boardId, $fileInfo);
                    if ($upload) $uploads[] = $upload;
                }
            } else {
                $upload = $service->uploadFile($this->getActiveEmail(), $boardId, $_FILES['files']);
                if ($upload) $uploads[] = $upload;
            }
        } elseif (!empty($_FILES['file'])) {
            $upload = $service->uploadFile($this->getActiveEmail(), $boardId, $_FILES['file']);
            if ($upload) $uploads[] = $upload;
        } else {
            return Response::json(['success' => false, 'message' => 'No files uploaded'], 400);
        }
        
        if (empty($uploads)) {
            return Response::json(['success' => false, 'message' => 'Upload failed'], 500);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['uploads' => $uploads],
            'message' => count($uploads) . ' file(s) uploaded'
        ]);
    }
    
    /**
     * GET /mood-boards/{id}/uploads/{filename} - Serve an uploaded file
     * Handles both local files and Drive-stored files transparently.
     * Does not require auth — mood board uploads are served by filename (unguessable).
     */
    public function serveUpload(Request $request): Response
    {
        $filename = basename((string)$request->getParam('filename'));
        $boardId = (int)$request->getParam('id');
        
        // 1. Try local storage first
        $localPath = __DIR__ . '/../../../../storage/mood-uploads/' . $boardId . '/' . $filename;
        if (file_exists($localPath)) {
            $this->streamFileWithCache($localPath, null, $boardId . '/' . $filename);
        }
        
        // 2. Look up in mood_board_uploads table (for Drive-stored files)
        try {
            $service = $this->getMoodBoardService();
            $db = $service->getDb();
            
            $stmt = $db->prepare("
                SELECT * FROM mood_board_uploads 
                WHERE board_id = ? AND stored_filename = ?
                LIMIT 1
            ");
            $stmt->execute([$boardId, $filename]);
            $upload = $stmt->fetch();
            
            if ($upload && $upload['drive_file_id'] && str_starts_with($upload['file_path'] ?? '', 'drive://')) {
                $driveFileId = (int)$upload['drive_file_id'];
                $uploaderEmail = $upload['uploaded_by'];
                
                $driveService = new \Webmail\Services\DriveService($this->config, $uploaderEmail);
                $filePath = $driveService->getFilePath($uploaderEmail, $driveFileId);
                
                if ($filePath && file_exists($filePath)) {
                    // Cache locally so future requests never touch NAS
                    try {
                        $cacheDir = __DIR__ . '/../../../../storage/mood-uploads/' . $boardId;
                        if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
                        $cacheDest = $cacheDir . '/' . $filename;
                        if (!file_exists($cacheDest)) @copy($filePath, $cacheDest);
                    } catch (\Throwable $ce) {}
                    
                    $this->streamFileWithCache($filePath, $upload['mime_type'] ?? null, $boardId . '/' . $filename);
                }
            }
        } catch (\Exception $e) {
            error_log("serveUpload: Error looking up drive file: " . $e->getMessage());
        }
        
        return Response::json(['success' => false, 'message' => 'File not found'], 404);
    }
    
    /**
     * GET /mood-boards/{id}/uploads/thumbs/{filename} - Serve a thumbnail file
     * No auth required — thumbnails use unguessable filenames.
     */
    public function serveThumb(Request $request): Response
    {
        $filename = basename((string)$request->getParam('filename'));
        $boardId = (int)$request->getParam('id');
        
        $thumbService = new \Webmail\Services\ImageThumbnailService();
        $thumbPath = $thumbService->getThumbsDir((int)$boardId) . '/' . $filename;
        
        if (file_exists($thumbPath)) {
            $this->streamFileWithCache($thumbPath, null, 'thumb/' . $boardId . '/' . $filename);
        }
        
        return Response::json(['success' => false, 'message' => 'Thumbnail not found'], 404);
    }
    
    /**
     * POST /mood-boards/{id}/generate-thumbnails - Batch generate thumbnails for all board uploads
     * Useful for existing boards that were created before thumbnail support.
     */
    public function generateThumbnails(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $service = $this->getMoodBoardService();
        
        if (!$service->hasAccess($this->getActiveEmail(), $boardId, 'viewer')) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $thumbService = new \Webmail\Services\ImageThumbnailService();
        $result = $thumbService->generateBoardThumbnails($service->getDb(), $boardId, $this->config);
        
        return Response::json([
            'success' => true,
            'data' => $result,
            'message' => "Generated {$result['generated']} thumbnails, skipped {$result['skipped']}, failed {$result['failed']}"
        ]);
    }
    
    /**
     * Stream a file with aggressive caching headers (ETag + immutable).
     * Uploaded mood board files never change, so 1-year cache is safe.
     */
    private function streamFileWithCache(string $filePath, ?string $mimeType, string $etagSeed): void
    {
        $mimeType = $mimeType ?: (mime_content_type($filePath) ?: 'application/octet-stream');
        $fileSize = filesize($filePath);
        $etag = '"' . md5($etagSeed . '-' . $fileSize . '-' . filemtime($filePath)) . '"';

        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientEtag === $etag) {
            http_response_code(304);
            header('Cache-Control: public, max-age=31536000, immutable');
            header('ETag: ' . $etag);
            exit;
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('Vary: Accept-Encoding');
        header('X-Content-Type-Options: nosniff');
        if (stripos($mimeType, 'svg') !== false || stripos($mimeType, 'html') !== false || stripos($mimeType, 'xml') !== false) {
            // Neutralize stored-XSS via SVG/HTML/XML payloads when opened directly
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        }

        set_time_limit(15);
        $handle = @fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 65536);
                if (connection_aborted()) {
                    break;
                }
            }
            fclose($handle);
        } else {
            readfile($filePath);
        }
        exit;
    }
    
    /**
     * POST /mood-boards/{id}/import-drive-file - Import a Drive file into mood board uploads
     * Body: { drive_file_id: int }
     * Returns: upload info with URL
     */
    public function importDriveFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $driveFileId = (int)$request->input('drive_file_id');
        
        if (!$driveFileId) {
            return Response::json(['success' => false, 'message' => 'drive_file_id is required'], 400);
        }
        
        $service = $this->getMoodBoardService();
        $upload = $service->importDriveFile($this->getActiveEmail(), $boardId, $driveFileId, $this->config);
        
        if (!$upload) {
            return Response::json(['success' => false, 'message' => 'Failed to import drive file'], 500);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['upload' => $upload]
        ]);
    }
    
    // ========================================
    // IMAGE SET ENDPOINTS
    // ========================================
    
    /**
     * POST /mood-boards/{id}/items/{itemId}/images - Add image to image_set
     */
    public function addImageToSet(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $itemId = (int)$request->getParam('itemId');
        
        $data = [
            'image_url' => $request->input('image_url'),
            'thumbnail_url' => $request->input('thumbnail_url'),
            'drive_file_id' => $request->input('drive_file_id') ? (int)$request->input('drive_file_id') : null,
            'original_filename' => $request->input('original_filename'),
            'file_size' => $request->input('file_size') ? (int)$request->input('file_size') : null,
            'width_px' => $request->input('width_px') ? (int)$request->input('width_px') : null,
            'height_px' => $request->input('height_px') ? (int)$request->input('height_px') : null,
        ];
        
        if (empty($data['image_url'])) {
            return Response::json(['success' => false, 'message' => 'image_url is required'], 400);
        }
        
        $image = $this->moodBoardService->addImageToSet($this->getActiveEmail(), $boardId, $itemId, $data);
        
        if (!$image) {
            return Response::json(['success' => false, 'message' => 'Failed to add image to set'], 500);
        }
        
        return Response::json(['success' => true, 'data' => ['image' => $image]]);
    }
    
    /**
     * POST /mood-boards/{id}/items/{itemId}/images/batch - Add many images at once
     *
     * Body: { images: [{ image_url, thumbnail_url?, ... }, ...] }
     */
    public function addImagesToSetBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $itemId = (int)$request->getParam('itemId');
        $images = (array)$request->input('images', []);

        if (empty($images)) {
            return Response::json(['success' => false, 'message' => 'images array required'], 400);
        }
        if (count($images) > 200) {
            $images = array_slice($images, 0, 200);
        }

        $r = $this->moodBoardService->addImagesToSetBatch($this->getActiveEmail(), $boardId, $itemId, $images);

        return Response::json([
            'success' => true,
            'data' => [
                'success' => $r['success'],
                'failed' => $r['failed'],
                'images' => $r['images'],
            ],
        ]);
    }

    /**
     * DELETE /mood-boards/{id}/images/{imageId} - Remove image from image_set
     */
    public function removeImageFromSet(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $imageId = (int)$request->getParam('imageId');
        
        if (!$this->moodBoardService->removeImageFromSet($this->getActiveEmail(), $boardId, $imageId)) {
            return Response::json(['success' => false, 'message' => 'Image not found or access denied'], 404);
        }
        
        return Response::json(['success' => true, 'message' => 'Image removed']);
    }
    
    // ========================================
    // TODO ENDPOINTS (within todo_list items)
    // ========================================
    
    /**
     * POST /mood-boards/{id}/items/{itemId}/todos - Add a todo
     */
    public function addTodo(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $itemId = (int)$request->getParam('itemId');
        
        $data = [
            'text' => $request->input('text'),
            'completed' => (int)$request->input('completed', 0),
        ];
        
        if (empty($data['text'])) {
            return Response::json(['success' => false, 'message' => 'Todo text is required'], 400);
        }
        
        $todo = $this->moodBoardService->addTodo($this->getActiveEmail(), $boardId, $itemId, $data);
        
        if (!$todo) {
            return Response::json(['success' => false, 'message' => 'Failed to add todo'], 500);
        }
        
        return Response::json(['success' => true, 'data' => ['todo' => $todo]]);
    }
    
    /**
     * PUT /mood-boards/{id}/todos/{todoId} - Update a todo
     */
    public function updateTodo(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $todoId = (int)$request->getParam('todoId');
        
        $data = [];
        if ($request->has('text')) $data['text'] = $request->input('text');
        if ($request->has('completed')) $data['completed'] = (int)$request->input('completed');
        if ($request->has('position')) $data['position'] = (int)$request->input('position');
        
        $todo = $this->moodBoardService->updateTodo($this->getActiveEmail(), $boardId, $todoId, $data);
        
        if (!$todo) {
            return Response::json(['success' => false, 'message' => 'Todo not found or access denied'], 404);
        }
        
        return Response::json(['success' => true, 'data' => ['todo' => $todo]]);
    }
    
    /**
     * DELETE /mood-boards/{id}/todos/{todoId} - Delete a todo
     */
    public function deleteTodo(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $todoId = (int)$request->getParam('todoId');
        
        if (!$this->moodBoardService->deleteTodo($this->getActiveEmail(), $boardId, $todoId)) {
            return Response::json(['success' => false, 'message' => 'Todo not found or access denied'], 404);
        }
        
        return Response::json(['success' => true, 'message' => 'Todo deleted']);
    }
    
    // ========================================
    // CONNECTION ENDPOINTS
    // ========================================
    
    /**
     * POST /mood-boards/{id}/connections - Create a connection
     */
    public function addConnection(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        $data = [
            'from_item_id' => (int)$request->input('from_item_id'),
            'to_item_id' => (int)$request->input('to_item_id'),
            'line_style' => $request->input('line_style', 'solid'),
            'line_color' => $request->input('line_color', '#666666'),
            'arrow_start' => (int)$request->input('arrow_start', 0),
            'arrow_end' => (int)$request->input('arrow_end', 1),
            'label' => $request->input('label'),
        ];

        $optionalFields = [
            'from_anchor_x', 'from_anchor_y', 'to_anchor_x', 'to_anchor_y',
            'bend_x', 'bend_y', 'bend2_x', 'bend2_y',
            'glow_enabled', 'glow_color', 'glow_opacity', 'glow_blur',
            'gradient_enabled', 'gradient_color_start', 'gradient_color_end',
            'render_above',
        ];
        $body = $request->input();
        foreach ($optionalFields as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }
        
        if (!$data['from_item_id'] || !$data['to_item_id']) {
            return Response::json(['success' => false, 'message' => 'Both from_item_id and to_item_id are required'], 400);
        }
        
        $connection = $this->moodBoardService->addConnection($this->getActiveEmail(), $boardId, $data);
        
        if (!$connection) {
            return Response::json(['success' => false, 'message' => 'Failed to create connection'], 500);
        }
        
        // Log activity — resolve item labels for "connected A to B"
        $fromItem = $this->moodBoardService->getItem($data['from_item_id']);
        $toItem = $this->moodBoardService->getItem($data['to_item_id']);
        $fromLabel = $fromItem ? $this->moodBoardService->getItemLabel($fromItem) : 'Item';
        $toLabel = $toItem ? $this->moodBoardService->getItemLabel($toItem) : 'Item';
        $this->moodBoardService->logActivity(
            $boardId, $this->getActiveEmail(), 'connection_added',
            $data['from_item_id'], $fromItem['type'] ?? null, $fromLabel,
            $data['to_item_id'], $toLabel
        );
        $this->broadcastActivity($boardId, 'connection_added', $data['from_item_id'], $fromItem['type'] ?? null, $fromLabel, $data['to_item_id'], $toLabel);
        
        // Broadcast to collaborators
        $this->broadcastMoodBoardEvent('MOOD_BOARD_CONNECTION_CREATED', $boardId, ['connection' => $connection], $this->getActiveEmail());
        
        return Response::json(['success' => true, 'data' => ['connection' => $connection]]);
    }
    
    /**
     * POST /mood-boards/{id}/connections/batch - Batch create connections
     */
    public function batchAddConnections(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $connections = $request->input('connections');

        if (!is_array($connections) || empty($connections)) {
            return Response::json(['success' => false, 'message' => 'connections array is required'], 400);
        }

        $prepared = [];
        foreach ($connections as $conn) {
            if (empty($conn['from_item_id']) || empty($conn['to_item_id'])) continue;
            $prepared[] = $conn;
        }

        if (empty($prepared)) {
            return Response::json(['success' => false, 'message' => 'No valid connections provided'], 400);
        }

        $result = $this->moodBoardService->batchAddConnections($this->getActiveEmail(), $boardId, $prepared);

        if (empty($result)) {
            return Response::json(['success' => false, 'message' => 'Failed to create connections'], 500);
        }

        $this->broadcastMoodBoardEvent('MOOD_BOARD_CONNECTIONS_BATCH_CREATED', $boardId, ['connections' => $result], $this->getActiveEmail());

        return Response::json(['success' => true, 'data' => ['connections' => $result]]);
    }

    /**
     * PUT /mood-boards/{id}/connections/{connId} - Update a connection
     */
    public function updateConnection(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $connId = (int)$request->getParam('connId');
        
        $data = [];
        $fields = ['line_style', 'line_color', 'line_width', 'arrow_start', 'arrow_end', 'label',
                    'glow_enabled', 'glow_color', 'glow_opacity', 'glow_blur',
                    'gradient_enabled', 'gradient_color_start', 'gradient_color_end',
                    'render_above'];
        foreach ($fields as $field) {
            if ($request->has($field)) $data[$field] = $request->input($field);
        }
        // Anchor fields and bend point support explicit null (reset to auto)
        $nullableFloatFields = ['from_anchor_x', 'from_anchor_y', 'to_anchor_x', 'to_anchor_y', 'bend_x', 'bend_y', 'bend2_x', 'bend2_y'];
        $body = $request->input(); // full body array
        foreach ($nullableFloatFields as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field] !== null ? (float)$body[$field] : null;
            }
        }
        
        $connection = $this->moodBoardService->updateConnection($this->getActiveEmail(), $boardId, $connId, $data);
        
        if (!$connection) {
            return Response::json(['success' => false, 'message' => 'Connection not found or access denied'], 404);
        }
        
        return Response::json(['success' => true, 'data' => ['connection' => $connection]]);
    }
    
    /**
     * DELETE /mood-boards/{id}/connections/{connId} - Delete a connection
     */
    public function deleteConnection(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $connId = (int)$request->getParam('connId');
        
        if (!$this->moodBoardService->deleteConnection($this->getActiveEmail(), $boardId, $connId)) {
            return Response::json(['success' => false, 'message' => 'Connection not found or access denied'], 404);
        }
        
        // Log activity
        $this->moodBoardService->logActivity($boardId, $this->getActiveEmail(), 'connection_deleted');
        $this->broadcastActivity($boardId, 'connection_deleted');
        
        // Broadcast to collaborators
        $this->broadcastMoodBoardEvent('MOOD_BOARD_CONNECTION_DELETED', $boardId, ['connection_id' => $connId], $this->getActiveEmail());
        
        return Response::json(['success' => true, 'message' => 'Connection deleted']);
    }
    
    /**
     * POST /mood-boards/{id}/connections/purge-orphans - Remove connections to deleted/missing items
     */
    public function purgeOrphanConnections(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $removed = $this->moodBoardService->purgeOrphanConnections($this->getActiveEmail(), $boardId);

        return Response::json([
            'success' => true,
            'removed' => $removed,
            'message' => $removed > 0 ? "Purged {$removed} orphan connection(s)" : 'No orphan connections found',
        ]);
    }

    // ========================================
    // MEASUREMENT ENDPOINTS
    // ========================================

    /**
     * POST /mood-boards/{id}/measurements - Create a measurement
     */
    public function addMeasurement(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');

        $data = [
            'x1' => (float)$request->input('x1'),
            'y1' => (float)$request->input('y1'),
            'x2' => (float)$request->input('x2'),
            'y2' => (float)$request->input('y2'),
            'distance' => (int)($request->input('distance', 0)),
            'width'    => (int)($request->input('width', 0)),
            'height'   => (int)($request->input('height', 0)),
            'angle'    => (float)($request->input('angle', 0)),
        ];

        $measurement = $this->moodBoardService->addMeasurement($this->getActiveEmail(), $boardId, $data);

        if (!$measurement) {
            return Response::json(['success' => false, 'message' => 'Failed to create measurement'], 500);
        }

        $this->broadcastMoodBoardEvent('MOOD_BOARD_MEASUREMENT_CREATED', $boardId, ['measurement' => $measurement], $this->getActiveEmail());

        return Response::json(['success' => true, 'data' => ['measurement' => $measurement]]);
    }

    /**
     * DELETE /mood-boards/{id}/measurements/{measureId} - Delete a measurement
     */
    public function deleteMeasurement(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $measureId = (int)$request->getParam('measureId');

        if (!$this->moodBoardService->deleteMeasurement($this->getActiveEmail(), $boardId, $measureId)) {
            return Response::json(['success' => false, 'message' => 'Measurement not found or access denied'], 404);
        }

        $this->broadcastMoodBoardEvent('MOOD_BOARD_MEASUREMENT_DELETED', $boardId, ['measurement_id' => $measureId], $this->getActiveEmail());

        return Response::json(['success' => true, 'message' => 'Measurement deleted']);
    }

    /**
     * DELETE /mood-boards/{id}/measurements - Clear all measurements
     */
    public function clearMeasurements(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');

        if (!$this->moodBoardService->clearMeasurements($this->getActiveEmail(), $boardId)) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $this->broadcastMoodBoardEvent('MOOD_BOARD_MEASUREMENTS_CLEARED', $boardId, [], $this->getActiveEmail());

        return Response::json(['success' => true, 'message' => 'Measurements cleared']);
    }

    /**
     * PUT /mood-boards/{id}/measure-settings - Update board measure display settings
     */
    public function updateMeasureSettings(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $email = $this->getActiveEmail();

        if (!$this->moodBoardService->hasAccess($email, $boardId, 'editor')) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $sets = [];
        $vals = [];
        if ($request->has('measure_color')) {
            $sets[] = 'measure_color = ?';
            $vals[] = $request->input('measure_color');
        }
        if ($request->has('measure_width')) {
            $sets[] = 'measure_width = ?';
            $vals[] = (float)$request->input('measure_width');
        }
        if ($request->has('measure_visible')) {
            $sets[] = 'measure_visible = ?';
            $vals[] = (int)$request->input('measure_visible');
        }

        if (empty($sets)) {
            return Response::json(['success' => false, 'message' => 'No settings to update'], 400);
        }

        $vals[] = $boardId;
        $db = \App\Core\Database::getConnection($this->config);
        $db->prepare("UPDATE mood_boards SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);

        $this->broadcastMoodBoardEvent('MOOD_BOARD_MEASURE_SETTINGS', $boardId, [
            'measure_color' => $request->input('measure_color'),
            'measure_width' => $request->input('measure_width'),
            'measure_visible' => $request->input('measure_visible'),
        ], $this->getActiveEmail());

        return Response::json(['success' => true]);
    }

    // ========================================
    // CLIENT LINKING ENDPOINTS
    // ========================================
    
    /**
     * GET /clients/{clientId}/mood-boards - Get client's mood boards
     */
    public function getClientBoards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $clientId = (int)$request->getParam('clientId');
        $boards = $this->moodBoardService->getClientBoards($this->getActiveEmail(), $clientId);
        
        return Response::json(['success' => true, 'data' => ['boards' => $boards]]);
    }
    
    /**
     * POST /clients/{clientId}/mood-boards - Link a mood board to client
     */
    public function linkToClient(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $clientId = (int)$request->getParam('clientId');
        $boardId = (int)$request->input('mood_board_id');
        
        if (!$boardId) {
            return Response::json(['success' => false, 'message' => 'mood_board_id is required'], 400);
        }
        
        if (!$this->moodBoardService->linkToClient($this->getActiveEmail(), $clientId, $boardId)) {
            return Response::json(['success' => false, 'message' => 'Failed to link board to client'], 500);
        }
        
        return Response::json(['success' => true, 'message' => 'Board linked to client']);
    }
    
    /**
     * DELETE /clients/{clientId}/mood-boards/{boardId} - Unlink mood board from client
     */
    public function unlinkFromClient(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $clientId = (int)$request->getParam('clientId');
        $boardId = (int)$request->getParam('boardId');
        
        if (!$this->moodBoardService->unlinkFromClient($this->getActiveEmail(), $clientId, $boardId)) {
            return Response::json(['success' => false, 'message' => 'Failed to unlink board from client'], 500);
        }
        
        return Response::json(['success' => true, 'message' => 'Board unlinked from client']);
    }
    
    // ========================================
    // MEMBER ENDPOINTS
    // ========================================
    
    /**
     * GET /mood-boards/{id}/members - Get board members
     */
    public function getMembers(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->moodBoardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::json(['success' => false, 'message' => 'Board not found or access denied'], 404);
        }
        
        $members = $this->moodBoardService->getMembers($boardId);
        
        return Response::json(['success' => true, 'data' => ['members' => $members]]);
    }
    
    /**
     * POST /mood-boards/{id}/members - Add a member
     */
    public function addMember(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $memberEmail = $request->input('email');
        $role = $request->input('role', 'editor');
        
        if (empty($memberEmail)) {
            return Response::json(['success' => false, 'message' => 'Email is required'], 400);
        }
        
        if (!in_array($role, ['viewer', 'editor', 'admin'])) {
            return Response::json(['success' => false, 'message' => 'Invalid role'], 400);
        }
        
        if (!$this->moodBoardService->addMember($this->getActiveEmail(), $boardId, $memberEmail, $role)) {
            return Response::json(['success' => false, 'message' => 'Failed to add member. Check the email is valid and not the board owner.'], 500);
        }
        
        $members = $this->moodBoardService->getMembers($boardId);
        
        return Response::json(['success' => true, 'data' => ['members' => $members], 'message' => 'Member added']);
    }
    
    /**
     * PUT /mood-boards/{id}/members/{email} - Update member role
     */
    public function updateMember(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $memberEmail = urldecode($request->getParam('email'));
        $role = $request->input('role');
        
        if (!in_array($role, ['viewer', 'editor', 'admin'])) {
            return Response::json(['success' => false, 'message' => 'Invalid role'], 400);
        }
        
        if (!$this->moodBoardService->updateMemberRole($this->getActiveEmail(), $boardId, $memberEmail, $role)) {
            return Response::json(['success' => false, 'message' => 'Failed to update member role'], 500);
        }
        
        $members = $this->moodBoardService->getMembers($boardId);
        
        return Response::json(['success' => true, 'data' => ['members' => $members], 'message' => 'Member role updated']);
    }
    
    /**
     * DELETE /mood-boards/{id}/members/{email} - Remove a member
     */
    public function removeMember(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $memberEmail = urldecode($request->getParam('email'));
        
        if (!$this->moodBoardService->removeMember($this->getActiveEmail(), $boardId, $memberEmail)) {
            return Response::json(['success' => false, 'message' => 'Failed to remove member'], 500);
        }
        
        return Response::json(['success' => true, 'message' => 'Member removed']);
    }
    
    // ========================================
    // GROUP ACCESS ENDPOINTS
    // ========================================
    
    /**
     * GET /mood-boards/{id}/groups - Get groups with access
     */
    public function getGroupAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->moodBoardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $groups = $this->moodBoardService->getGroupAccess($boardId);
        
        return Response::json(['success' => true, 'data' => ['groups' => $groups]]);
    }
    
    /**
     * POST /mood-boards/{id}/groups - Grant group access
     */
    public function addGroupAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $groupId = (int)$request->input('group_id');
        $role = $request->input('role', 'editor');
        
        if (!$groupId) {
            return Response::json(['success' => false, 'message' => 'group_id is required'], 400);
        }
        
        if (!in_array($role, ['viewer', 'editor'])) {
            return Response::json(['success' => false, 'message' => 'Invalid role'], 400);
        }
        
        if (!$this->moodBoardService->addGroupAccess($this->getActiveEmail(), $boardId, $groupId, $role)) {
            return Response::json(['success' => false, 'message' => 'Failed to grant group access'], 500);
        }
        
        $groups = $this->moodBoardService->getGroupAccess($boardId);
        
        return Response::json(['success' => true, 'data' => ['groups' => $groups], 'message' => 'Group access granted']);
    }
    
    /**
     * DELETE /mood-boards/{id}/groups/{groupId} - Remove group access
     */
    public function removeGroupAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $groupId = (int)$request->getParam('groupId');
        
        if (!$this->moodBoardService->removeGroupAccess($this->getActiveEmail(), $boardId, $groupId)) {
            return Response::json(['success' => false, 'message' => 'Failed to remove group access'], 500);
        }
        
        return Response::json(['success' => true, 'message' => 'Group access removed']);
    }
    
    // ========================================
    // BOARD LINKING ENDPOINTS (Mood <-> Kanban)
    // ========================================
    
    /**
     * GET /mood-boards/{id}/board-links - Get linked kanban boards
     */
    public function getLinkedBoards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->moodBoardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $links = $this->moodBoardService->getLinkedBoards($boardId);
        
        return Response::json(['success' => true, 'data' => ['linked_boards' => $links]]);
    }
    
    /**
     * POST /mood-boards/{id}/board-links - Link to a kanban board
     */
    public function linkToBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $moodBoardId = (int)$request->getParam('id');
        $kanbanBoardId = (int)$request->input('kanban_board_id');
        
        if (!$kanbanBoardId) {
            return Response::json(['success' => false, 'message' => 'kanban_board_id is required'], 400);
        }
        
        if (!$this->moodBoardService->linkToBoard($this->getActiveEmail(), $moodBoardId, $kanbanBoardId)) {
            return Response::json(['success' => false, 'message' => 'Failed to link boards'], 500);
        }
        
        $links = $this->moodBoardService->getLinkedBoards($moodBoardId);
        
        return Response::json(['success' => true, 'data' => ['linked_boards' => $links], 'message' => 'Boards linked']);
    }
    
    /**
     * DELETE /mood-boards/{id}/board-links/{kanbanBoardId} - Unlink from kanban board
     */
    public function unlinkFromBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $moodBoardId = (int)$request->getParam('id');
        $kanbanBoardId = (int)$request->getParam('kanbanBoardId');
        
        if (!$this->moodBoardService->unlinkFromBoard($this->getActiveEmail(), $moodBoardId, $kanbanBoardId)) {
            return Response::json(['success' => false, 'message' => 'Failed to unlink boards'], 500);
        }
        
        return Response::json(['success' => true, 'message' => 'Board link removed']);
    }
    
    /**
     * GET /kanban-boards/{kanbanBoardId}/mood-boards - Get mood boards linked to a kanban board (for reverse lookup)
     */
    public function getMoodBoardsForKanban(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $kanbanBoardId = (int)$request->getParam('kanbanBoardId');
        $moodBoards = $this->moodBoardService->getMoodBoardsForKanban($this->getActiveEmail(), $kanbanBoardId);
        
        return Response::json(['success' => true, 'data' => ['mood_boards' => $moodBoards]]);
    }
    
    // ========================================
    // PUBLIC SHARING ENDPOINTS (Authenticated)
    // ========================================
    
    /**
     * POST /mood-boards/{id}/share - Create a public share link
     * Body: { mode: 'view'|'edit', password?: string, expires_hours?: int }
     */
    public function createShareLink(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $mode = $request->input('mode') ?? 'view';
        $password = $request->input('password');
        $expiresHours = $request->input('expires_hours') ? (int)$request->input('expires_hours') : null;
        
        if (!in_array($mode, ['view', 'edit'])) {
            return Response::json(['success' => false, 'message' => 'Invalid share mode'], 400);
        }
        
        $result = $this->getMoodBoardService()->createShareLink(
            $this->getActiveEmail(), $boardId, $mode, $password, $expiresHours
        );
        
        if (!$result) {
            return Response::json(['success' => false, 'message' => 'Failed to create share link'], 500);
        }
        
        return Response::json(['success' => true, 'data' => $result]);
    }
    
    /**
     * PUT /mood-boards/{id}/share - Update share link settings
     * Body: { mode?: 'view'|'edit', password?: string|null, expires_hours?: int|null }
     */
    public function updateShareLink(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $data = [];
        
        $mode = $request->input('mode');
        if ($mode !== null) {
            if (!in_array($mode, ['view', 'edit'])) {
                return Response::json(['success' => false, 'message' => 'Invalid share mode'], 400);
            }
            $data['mode'] = $mode;
        }
        
        if ($request->has('password')) {
            $data['password'] = $request->input('password');
        }
        if ($request->has('expires_hours')) {
            $data['expires_hours'] = $request->input('expires_hours') ? (int)$request->input('expires_hours') : null;
        }
        
        $result = $this->getMoodBoardService()->updateShareLink($this->getActiveEmail(), $boardId, $data);
        
        if (!$result) {
            return Response::json(['success' => false, 'message' => 'Failed to update share link'], 500);
        }
        
        return Response::json(['success' => true, 'data' => $result]);
    }
    
    /**
     * DELETE /mood-boards/{id}/share - Remove/disable share link
     */
    public function removeShareLink(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->getMoodBoardService()->removeShareLink($this->getActiveEmail(), $boardId)) {
            return Response::json(['success' => false, 'message' => 'Failed to remove share link'], 500);
        }
        
        return Response::json(['success' => true, 'message' => 'Share link removed']);
    }
    
    /**
     * GET /mood-boards/{id}/share/stats - Get share analytics for a board
     */
    public function getShareStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $stats = $this->getMoodBoardService()->getShareStats($this->getActiveEmail(), $boardId);
        
        if (!$stats) {
            return Response::json(['success' => false, 'message' => 'Not found or no access'], 404);
        }
        
        return Response::json(['success' => true, 'data' => $stats]);
    }
    
    /**
     * GET /mood-boards/shared - List all publicly shared boards with stats
     */
    public function getSharedBoards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boards = $this->getMoodBoardService()->getSharedBoards($this->getActiveEmail());
        
        return Response::json(['success' => true, 'data' => ['boards' => $boards]]);
    }
    
    // ========================================
    // PUBLIC ENDPOINTS (No authentication)
    // ========================================
    
    /**
     * GET /mood-boards/share/{token} - Public view of shared board
     * No authentication required. Token IS the authentication.
     * Returns full board data for rendering.
     */
    public function publicView(Request $request): Response
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            return Response::json(['success' => false, 'message' => 'Invalid link'], 400);
        }
        
        $service = $this->getMoodBoardService();
        
        // Get share info first
        $shareInfo = $service->getShareInfo($token);
        if (!$shareInfo) {
            return Response::json(['success' => false, 'message' => 'Board not found or link disabled'], 404);
        }
        
        // Check expiry
        if ($shareInfo['is_expired']) {
            return Response::json([
                'success' => false, 
                'message' => 'This share link has expired',
                'expired' => true,
                'board_name' => $shareInfo['name']
            ], 410);
        }
        
        // Check password — never accept it via GET params (leaks into access
        // logs and browser history); POST body or X-Share-Password header only.
        if ($shareInfo['requires_password']) {
            $password = $request->input('password') ?? ($_SERVER['HTTP_X_SHARE_PASSWORD'] ?? '');
            if (empty($password)) {
                return Response::json([
                    'success' => false,
                    'message' => 'Password required',
                    'requires_password' => true,
                    'board_name' => $shareInfo['name']
                ], 403);
            }
            $rateLimited = $this->checkSharePasswordRateLimit($request, $token);
            if ($rateLimited) return $rateLimited;
            if (!$service->validateSharePassword($token, $password)) {
                return Response::json([
                    'success' => false,
                    'message' => 'Incorrect password',
                    'requires_password' => true,
                    'board_name' => $shareInfo['name']
                ], 403);
            }
        }
        
        // Load full board data
        $board = $service->getBoardByShareToken($token);
        if (!$board) {
            return Response::json(['success' => false, 'message' => 'Board not found'], 404);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'board' => $board,
                'share_mode' => $shareInfo['mode']
            ]
        ]);
    }
    
    /**
     * POST /mood-boards/share/{token}/validate-password
     */
    public function publicValidateSharePassword(Request $request): Response
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            return Response::json(['success' => false, 'message' => 'Invalid link'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo) {
            return Response::json(['success' => false, 'message' => 'Board not found'], 404);
        }

        if ($shareInfo['is_expired']) {
            return Response::json(['success' => false, 'message' => 'This share link has expired', 'expired' => true], 410);
        }

        if (!$shareInfo['requires_password']) {
            return Response::json(['success' => true, 'message' => 'No password required']);
        }

        $password = $request->input('password') ?? '';
        if (empty($password)) {
            return Response::json(['success' => false, 'message' => 'Password required', 'requires_password' => true], 403);
        }

        $rateLimited = $this->checkSharePasswordRateLimit($request, $token);
        if ($rateLimited) return $rateLimited;

        $valid = $service->validateSharePassword($token, $password);
        return Response::json([
            'success' => $valid,
            'message' => $valid ? 'Password accepted' : 'Incorrect password',
            'requires_password' => !$valid,
        ], $valid ? 200 : 403);
    }

    /**
     * GET /mood-boards/share/{token}/uploads/{filename} - Serve upload for shared board
     * No auth required — validates token, then serves the file.
     */
    public function publicServeUpload(Request $request): Response
    {
        $token = $request->getParam('token');
        $filename = basename((string)$request->getParam('filename'));
        
        if (empty($token) || empty($filename)) {
            return Response::json(['success' => false, 'message' => 'Invalid request'], 400);
        }
        
        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);
        
        if (!$shareInfo || $shareInfo['is_expired']) {
            return Response::json(['success' => false, 'message' => 'Not found'], 404);
        }
        
        $boardId = $shareInfo['board_id'];
        
        // Try local storage first
        $localPath = __DIR__ . '/../../../../storage/mood-uploads/' . $boardId . '/' . $filename;
        if (file_exists($localPath)) {
            $this->streamFileWithCache($localPath, null, $boardId . '/' . $filename);
        }
        
        // Look up in mood_board_uploads table
        try {
            $db = $service->getDb();
            $stmt = $db->prepare("
                SELECT * FROM mood_board_uploads 
                WHERE board_id = ? AND stored_filename = ?
                LIMIT 1
            ");
            $stmt->execute([$boardId, $filename]);
            $upload = $stmt->fetch();
            
            if ($upload && $upload['drive_file_id'] && str_starts_with($upload['file_path'] ?? '', 'drive://')) {
                $driveFileId = (int)$upload['drive_file_id'];
                $uploaderEmail = $upload['uploaded_by'];
                $driveService = new \Webmail\Services\DriveService($this->config, $uploaderEmail);
                $filePath = $driveService->getFilePath($uploaderEmail, $driveFileId);
                
                if ($filePath && file_exists($filePath)) {
                    // Cache locally so future requests never touch NAS
                    try {
                        $cacheDir = __DIR__ . '/../../../../storage/mood-uploads/' . $boardId;
                        if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
                        $cacheDest = $cacheDir . '/' . $filename;
                        if (!file_exists($cacheDest)) @copy($filePath, $cacheDest);
                    } catch (\Throwable $ce) {}
                    
                    $this->streamFileWithCache($filePath, $upload['mime_type'] ?? null, $boardId . '/' . $filename);
                }
            }
        } catch (\Exception $e) {
            error_log("publicServeUpload error: " . $e->getMessage());
        }
        
        return Response::json(['success' => false, 'message' => 'File not found'], 404);
    }
    
    /**
     * GET /mood-boards/share/{token}/uploads/thumbs/{filename} - Serve thumbnail for shared board
     * No auth required — validates token, then serves the thumbnail.
     */
    public function publicServeThumb(Request $request): Response
    {
        $token = $request->getParam('token');
        $filename = basename((string)$request->getParam('filename'));
        
        if (empty($token) || empty($filename)) {
            return Response::json(['success' => false, 'message' => 'Invalid request'], 400);
        }
        
        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);
        
        if (!$shareInfo || $shareInfo['is_expired']) {
            return Response::json(['success' => false, 'message' => 'Not found'], 404);
        }
        
        $boardId = $shareInfo['board_id'];
        $thumbService = new \Webmail\Services\ImageThumbnailService();
        $thumbPath = $thumbService->getThumbsDir($boardId) . '/' . $filename;
        
        if (file_exists($thumbPath)) {
            $this->streamFileWithCache($thumbPath, null, 'thumb/' . $boardId . '/' . $filename);
        }
        
        return Response::json(['success' => false, 'message' => 'Thumbnail not found'], 404);
    }
    
    /**
     * POST /mood-boards/share/{token}/ws-token
     * Issue a short-lived JWT so a public guest can connect to the mailsync WebSocket server.
     * Body: { guest_id: string, guest_name: string }
     */
    public function publicRequestWsToken(Request $request): Response
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            return Response::json(['success' => false, 'message' => 'Invalid link'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo) {
            return Response::json(['success' => false, 'message' => 'Board not found or link disabled'], 404);
        }

        if ($shareInfo['is_expired']) {
            return Response::json(['success' => false, 'message' => 'Share link has expired'], 410);
        }

        $data = $request->input();
        $guestId = trim($data['guest_id'] ?? '');
        $guestName = trim($data['guest_name'] ?? 'Guest');

        if (empty($guestId) || strlen($guestId) > 64) {
            return Response::json(['success' => false, 'message' => 'guest_id is required (max 64 chars)'], 400);
        }

        try {
            $sessionService = new \Webmail\Services\SessionService(
                $this->config['jwt'] ?? [],
                $this->config['imap_encryption_key'] ?? ''
            );

            $jwt = $sessionService->createToken('guest_' . $guestId . '@mood.guest', [
                'type' => 'mood_guest',
                'board_id' => $shareInfo['board_id'],
                'share_token' => $token,
                'guest_name' => substr($guestName, 0, 100),
                'guest_id' => $guestId,
                'exp' => time() + 900,
            ]);

            return Response::json([
                'success' => true,
                'data' => [
                    'token' => $jwt,
                    'expires_in' => 900,
                    'board_id' => $shareInfo['board_id'],
                ],
            ]);
        } catch (\Throwable $e) {
            error_log("[MoodBoard] WS token generation failed: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Failed to generate token'], 500);
        }
    }

    /**
     * POST /mood-boards/share/{token}/track - Track a share view (analytics)
     * No auth required. Body: { session_id: string, referrer?: string }
     */
    public function publicTrackView(Request $request): Response
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            return Response::json(['success' => false], 400);
        }
        
        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);
        
        if (!$shareInfo || $shareInfo['is_expired']) {
            return Response::json(['success' => false], 404);
        }
        
        $sessionId = $request->input('session_id');
        if (empty($sessionId)) {
            return Response::json(['success' => false, 'message' => 'session_id required'], 400);
        }
        
        $service->trackShareView($shareInfo['board_id'], $sessionId, [
            'ip' => $request->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referrer' => $request->input('referrer') ?? $_SERVER['HTTP_REFERER'] ?? null
        ]);
        
        return Response::json(['success' => true]);
    }
    
    /**
     * PUT /mood-boards/share/{token}/heartbeat - Update view duration
     * No auth required. Body: { session_id: string, duration: int, slides_viewed?: int }
     */
    public function publicHeartbeat(Request $request): Response
    {
        $token = $request->getParam('token');
        $sessionId = $request->input('session_id');
        $duration = (int)($request->input('duration') ?? 0);
        $slidesViewed = (int)($request->input('slides_viewed') ?? 0);
        
        if (empty($token) || empty($sessionId)) {
            return Response::json(['success' => false], 400);
        }
        
        $service = $this->getMoodBoardService();
        
        // Validate the share token — otherwise anyone can spoof analytics
        // for arbitrary session IDs.
        $shareInfo = $service->getShareInfo($token);
        if (!$shareInfo || $shareInfo['is_expired']) {
            return Response::json(['success' => false], 404);
        }
        
        $service->updateShareViewHeartbeat($sessionId, $duration, $slidesViewed);
        
        return Response::json(['success' => true]);
    }

    // ========================================
    // COMPONENT BLOCKS
    // ========================================

    /**
     * GET /mood-boards/components - List saved components
     */
    public function listComponents(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $category = $request->getQuery('category');
        $components = $this->moodBoardService->getComponents($this->getActiveEmail(), $category);
        return Response::json(['success' => true, 'data' => $components]);
    }

    /**
     * POST /mood-boards/components - Save selected items as a component
     */
    public function saveComponent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $data = [
            'name' => $request->input('name') ?? 'Untitled Component',
            'description' => $request->input('description'),
            'items_data' => $request->input('items_data'),
            'category' => $request->input('category') ?? 'custom',
            'is_global' => $request->input('is_global') ? 1 : 0,
        ];

        $component = $this->moodBoardService->saveComponent($this->getActiveEmail(), $data);
        if (!$component) {
            return Response::json(['success' => false, 'message' => 'Failed to save component'], 500);
        }
        return Response::json(['success' => true, 'data' => $component], 201);
    }

    /**
     * PUT /mood-boards/components/{id} - Update a component
     */
    public function updateComponent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        $data = [];
        foreach (['name', 'description', 'category', 'is_global', 'items_data'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        $component = $this->moodBoardService->updateComponent($this->getActiveEmail(), $id, $data);
        if (!$component) {
            return Response::json(['success' => false, 'message' => 'Component not found or access denied'], 404);
        }
        return Response::json(['success' => true, 'data' => $component]);
    }

    /**
     * DELETE /mood-boards/components/{id} - Delete a component
     */
    public function deleteComponent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        $deleted = $this->moodBoardService->deleteComponent($this->getActiveEmail(), $id);
        if (!$deleted) {
            return Response::json(['success' => false, 'message' => 'Component not found or access denied'], 404);
        }
        return Response::json(['success' => true, 'message' => 'Component deleted']);
    }

    /**
     * POST /mood-boards/components/{id}/push - Push component changes to all instances
     */
    public function pushComponentChanges(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        $result = $this->moodBoardService->pushComponentChanges($this->getActiveEmail(), $id);

        if (isset($result['error'])) {
            return Response::json(['success' => false, 'message' => $result['error']], 500);
        }
        return Response::json([
            'success' => true,
            'updated' => $result['updated'],
            'instances' => $result['instances'],
        ]);
    }

    /**
     * POST /mood-boards/components/{id}/push-from-item
     */
    public function pushFromItem(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $componentId = (int)$request->getParam('id');
        $body = $request->getParsedBody();
        $itemId = (int)($body['item_id'] ?? 0);
        $boardId = (int)($body['board_id'] ?? 0);

        if (!$itemId || !$boardId) {
            return Response::json(['success' => false, 'message' => 'item_id and board_id required'], 400);
        }

        $result = $this->moodBoardService->pushFromItem($this->getActiveEmail(), $componentId, $itemId, $boardId);
        return Response::json($result);
    }

    /**
     * POST /mood-boards/{id}/items/detach-component - Detach items from their component
     */
    public function detachComponentInstance(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $instanceId = $request->input('instance_id');
        if (!$instanceId) {
            return Response::json(['success' => false, 'message' => 'instance_id required'], 400);
        }

        $detached = $this->moodBoardService->detachComponentInstance($this->getActiveEmail(), $boardId, $instanceId);
        return Response::json(['success' => $detached]);
    }

    /**
     * GET /mood-boards/{id}/design-tokens
     */
    public function getDesignTokens(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $tokens = $this->moodBoardService->getDesignTokens($this->getActiveEmail(), $boardId);
        return Response::json(['success' => true, 'data' => $tokens]);
    }

    /**
     * PUT /mood-boards/{id}/design-tokens
     */
    public function saveDesignTokens(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $tokens = $request->input('tokens') ?? [];
        $saved = $this->moodBoardService->saveDesignTokens($this->getActiveEmail(), $boardId, $tokens);
        return Response::json(['success' => $saved]);
    }

    /**
     * POST /mood-boards/{id}/design-tokens/update-color - Change a token color and propagate
     */
    public function updateDesignTokenColor(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $oldColor = $request->input('old_color');
        $newColor = $request->input('new_color');
        if (!$oldColor || !$newColor) {
            return Response::json(['success' => false, 'message' => 'old_color and new_color required'], 400);
        }

        $count = $this->moodBoardService->updateDesignTokenColor($this->getActiveEmail(), $boardId, $oldColor, $newColor);
        return Response::json(['success' => true, 'items_updated' => $count]);
    }

    /**
     * GET /mood-boards/{id}/global-text-styles
     */
    public function getGlobalTextStyles(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $styles = $this->moodBoardService->getGlobalTextStyles($this->getActiveEmail(), $boardId);
        return Response::json(['success' => true, 'data' => $styles]);
    }

    /**
     * PUT /mood-boards/{id}/global-text-styles
     */
    public function saveGlobalTextStyles(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $styles = $request->input('styles') ?? [];
        $saved = $this->moodBoardService->saveGlobalTextStyles($this->getActiveEmail(), $boardId, $styles);
        return Response::json(['success' => $saved]);
    }

    /**
     * GET /mood-boards/{id}/global-css-classes
     */
    public function getGlobalCssClasses(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $classes = $this->moodBoardService->getGlobalCssClasses($this->getActiveEmail(), $boardId);
        return Response::json(['success' => true, 'data' => $classes]);
    }

    /**
     * PUT /mood-boards/{id}/global-css-classes
     */
    public function saveGlobalCssClasses(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $classes = $request->input('classes') ?? [];
        $saved = $this->moodBoardService->saveGlobalCssClasses($this->getActiveEmail(), $boardId, $classes);
        return Response::json(['success' => $saved]);
    }

    /**
     * POST /mood-boards/{id}/globals/propagate-color - ID-based semantic propagation
     */
    public function propagateGlobalColor(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $tokenId = $request->input('token_id');
        $newColor = $request->input('new_color');
        if (!$tokenId || !$newColor) {
            return Response::json(['success' => false, 'message' => 'token_id and new_color required'], 400);
        }

        $count = $this->moodBoardService->propagateGlobalColor($this->getActiveEmail(), $boardId, $tokenId, $newColor);
        return Response::json(['success' => true, 'items_updated' => $count]);
    }

    /**
     * POST /mood-boards/{id}/globals/propagate-text-style
     */
    public function propagateGlobalTextStyle(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $styleId = $request->input('style_id');
        $props = $request->input('props') ?? [];
        if (!$styleId) {
            return Response::json(['success' => false, 'message' => 'style_id required'], 400);
        }

        $count = $this->moodBoardService->propagateGlobalTextStyle($this->getActiveEmail(), $boardId, $styleId, $props);
        return Response::json(['success' => true, 'items_updated' => $count]);
    }

    // ========================================
    // USER PALETTES (shareable across boards)
    // ========================================

    /**
     * GET /mood-boards/palettes - List user's palettes + shared from same domain
     */
    public function listPalettes(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $palettes = $this->getMoodBoardService()->listUserPalettes($this->getActiveEmail());
        return Response::json(['success' => true, 'data' => $palettes]);
    }

    /**
     * POST /mood-boards/palettes - Create a new user palette
     */
    public function createPalette(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $data = [
            'name' => $request->input('name', 'Untitled Palette'),
            'colors' => $request->input('colors', []),
            'gradients' => $request->input('gradients', []),
            'is_shared' => $request->input('is_shared', false),
        ];

        $palette = $this->getMoodBoardService()->createUserPalette($this->getActiveEmail(), $data);
        if (!$palette) {
            return Response::json(['success' => false, 'message' => 'Failed to create palette'], 500);
        }
        return Response::json(['success' => true, 'data' => $palette, 'message' => 'Palette created']);
    }

    /**
     * PUT /mood-boards/palettes/{id} - Update a user palette
     */
    public function updatePalette(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        $data = [];
        if ($request->has('name')) $data['name'] = $request->input('name');
        if ($request->has('colors')) $data['colors'] = $request->input('colors');
        if ($request->has('gradients')) $data['gradients'] = $request->input('gradients');
        if ($request->has('is_shared')) $data['is_shared'] = $request->input('is_shared');

        $palette = $this->getMoodBoardService()->updateUserPalette($this->getActiveEmail(), $id, $data);
        if (!$palette) {
            return Response::json(['success' => false, 'message' => 'Palette not found or access denied'], 404);
        }
        return Response::json(['success' => true, 'data' => $palette, 'message' => 'Palette updated']);
    }

    /**
     * DELETE /mood-boards/palettes/{id} - Delete a user palette
     */
    public function deletePalette(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        $deleted = $this->getMoodBoardService()->deleteUserPalette($this->getActiveEmail(), $id);
        if (!$deleted) {
            return Response::json(['success' => false, 'message' => 'Palette not found or access denied'], 404);
        }
        return Response::json(['success' => true, 'message' => 'Palette deleted']);
    }

    /**
     * POST /mood-boards/palettes/from-board/{boardId} - Save board palette as user palette
     */
    public function saveBoardPalette(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('boardId');
        $name = $request->input('name', 'Board Palette');
        $isShared = (bool)$request->input('is_shared', false);

        $palette = $this->getMoodBoardService()->saveBoardAsUserPalette(
            $this->getActiveEmail(), $boardId, $name, $isShared
        );
        if (!$palette) {
            return Response::json(['success' => false, 'message' => 'Board not found or access denied'], 404);
        }
        return Response::json(['success' => true, 'data' => $palette, 'message' => 'Palette saved from board']);
    }

    /**
     * POST /mood-boards/palettes/{id}/apply/{boardId} - Apply palette to a board
     */
    public function applyPaletteToBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $paletteId = (int)$request->getParam('id');
        $boardId = (int)$request->getParam('boardId');
        $mode = $request->input('mode', 'merge'); // 'merge' or 'replace'

        $board = $this->getMoodBoardService()->applyPaletteToBoard(
            $this->getActiveEmail(), $paletteId, $boardId, $mode
        );
        if (!$board) {
            return Response::json(['success' => false, 'message' => 'Palette or board not found'], 404);
        }
        return Response::json(['success' => true, 'data' => ['board' => $board], 'message' => 'Palette applied to board']);
    }
    
    // ========================================
    // FOLDER MANAGEMENT
    // ========================================
    
    /**
     * GET /mood-boards/folders - List all folders for the authenticated user
     */
    public function listFolders(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $folders = $this->getMoodBoardService()->getFolders($this->getActiveEmail());
        return Response::json(['success' => true, 'data' => ['folders' => $folders]]);
    }
    
    /**
     * POST /mood-boards/folders - Create a new folder
     */
    public function createFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $name = $request->input('name');
        if (empty($name)) {
            return Response::json(['success' => false, 'message' => 'Folder name is required'], 400);
        }
        
        $data = [
            'name' => $name,
            'parent_id' => $request->input('parent_id'),
            'color' => $request->input('color'),
            'sort_order' => $request->input('sort_order', 0),
        ];
        
        $folder = $this->getMoodBoardService()->createFolder($this->getActiveEmail(), $data);
        if (!$folder) {
            return Response::json(['success' => false, 'message' => 'Failed to create folder'], 500);
        }
        return Response::json(['success' => true, 'data' => ['folder' => $folder], 'message' => 'Folder created']);
    }
    
    /**
     * PUT /mood-boards/folders/{id} - Update a folder
     */
    public function updateFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $data = [];
        foreach (['name', 'parent_id', 'color', 'sort_order'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }
        
        $folder = $this->getMoodBoardService()->updateFolder($this->getActiveEmail(), $id, $data);
        if (!$folder) {
            return Response::json(['success' => false, 'message' => 'Folder not found or access denied'], 404);
        }
        return Response::json(['success' => true, 'data' => ['folder' => $folder], 'message' => 'Folder updated']);
    }
    
    /**
     * DELETE /mood-boards/folders/{id} - Delete a folder
     */
    public function deleteFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $ok = $this->getMoodBoardService()->deleteFolder($this->getActiveEmail(), $id);
        if (!$ok) {
            return Response::json(['success' => false, 'message' => 'Folder not found or access denied'], 404);
        }
        return Response::json(['success' => true, 'message' => 'Folder deleted']);
    }
    
    /**
     * PUT /mood-boards/folders/reorder - Batch update folder sort_order
     */
    public function reorderFolders(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $orders = $request->input('orders');
        if (!is_array($orders)) {
            return Response::json(['success' => false, 'message' => 'orders array is required'], 400);
        }
        
        $ok = $this->getMoodBoardService()->reorderFolders($this->getActiveEmail(), $orders);
        return Response::json(['success' => $ok]);
    }
    
    /**
     * PUT /mood-boards/{id}/move - Move a board into a folder (or root)
     */
    public function moveBoardToFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $folderId = $request->input('folder_id');
        $folderId = ($folderId !== null && $folderId !== '') ? (int)$folderId : null;
        
        $ok = $this->getMoodBoardService()->moveBoard($this->getActiveEmail(), $boardId, $folderId);
        if (!$ok) {
            return Response::json(['success' => false, 'message' => 'Board or folder not found'], 404);
        }
        return Response::json(['success' => true, 'message' => 'Board moved']);
    }
    
    // ========================================
    // TEXT CSV EXPORT / IMPORT
    // ========================================
    
    /**
     * GET /mood-boards/{id}/export-texts - Download text items as CSV
     */
    public function exportTexts(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $csv = $this->getMoodBoardService()->exportTexts($this->getActiveEmail(), $boardId);
        
        if ($csv === null) {
            return Response::json(['success' => false, 'message' => 'Board not found or access denied'], 404);
        }
        
        // Get board name for filename
        $board = $this->getMoodBoardService()->getBoard($this->getActiveEmail(), $boardId);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $board['name'] ?? 'board');
        
        return Response::raw($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $safeName . '_texts.csv"',
        ]);
    }
    
    /**
     * GET /mood-boards/{id}/export-presentation - Download self-contained HTML presentation
     */
    public function exportPresentation(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        try {
            $boardId = (int)$request->getParam('id');
            $service = $this->getMoodBoardService();

            if (!$service->hasAccess($this->getActiveEmail(), $boardId, 'viewer')) {
                return Response::json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $oldLimit = ini_get('memory_limit');
            ini_set('memory_limit', '512M');

            try {
                $exportData = $service->getBoardForExport($this->getActiveEmail(), $boardId);
            } finally {
                ini_set('memory_limit', $oldLimit);
            }

            if (!$exportData) {
                return Response::json(['success' => false, 'message' => 'Board not found'], 404);
            }

            $board = $exportData['board'];
            $assetMap = $exportData['assets'];

            $slideItems = array_filter($board['items'] ?? [], fn($i) => ($i['type'] ?? '') === 'slide');
            if (empty($slideItems)) {
                return Response::json(['success' => false, 'message' => 'Board has no slides to present'], 400);
            }

            $boardName = $board['name'] ?? 'Presentation';
            $slideCount = count($slideItems);
            $boardDataJson = json_encode($board, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $assetMapJson = json_encode($assetMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $templatePath = __DIR__ . '/../../../../templates/presentation-export.php';
            if (!file_exists($templatePath)) {
                return Response::json(['success' => false, 'message' => 'Export template not found at: ' . realpath(__DIR__) . '/../../../../templates/'], 500);
            }

            ob_start();
            require $templatePath;
            $html = ob_get_clean();

            if (empty($html)) {
                return Response::json(['success' => false, 'message' => 'Template rendered empty output'], 500);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $boardName);

            return Response::raw($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $safeName . '_presentation.html"',
            ]);
        } catch (\Throwable $e) {
            $this->getMoodBoardService()->log("exportPresentation error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return Response::json([
                'success' => false,
                'message' => 'Export failed',
            ], 500);
        }
    }

    /**
     * GET /mood-boards/{id}/export-pptx - Download board as PowerPoint file
     */
    public function exportPptx(Request $request): ?Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $pptxService = null;

        try {
            $boardId = (int)$request->getParam('id');
            $service = $this->getMoodBoardService();

            if (!$service->hasAccess($this->getActiveEmail(), $boardId, 'viewer')) {
                return Response::json(['success' => false, 'message' => 'Access denied'], 403);
            }

            set_time_limit(300);
            ini_set('memory_limit', '1G');

            $exportData = $service->getBoardForExport($this->getActiveEmail(), $boardId);

            if (!$exportData) {
                return Response::json(['success' => false, 'message' => 'Board not found'], 404);
            }

            $board = $exportData['board'];
            $assetMap = $exportData['assets'];
            $filePathMap = $exportData['filePaths'] ?? [];
            $boardName = $board['name'] ?? 'Moodboard';

            $pptxService = new MoodBoardPptxService();
            $filePath = $pptxService->generate($board, $assetMap, $filePathMap);

            unset($assetMap, $filePathMap, $exportData);

            if (!file_exists($filePath)) {
                return Response::json(['success' => false, 'message' => 'PPTX generation failed'], 500);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $boardName);
            $fileSize = filesize($filePath);

            $this->streamBinaryFile($filePath, $safeName . '.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', $fileSize);

            $pptxService->cleanup();
            exit;
        } catch (\Throwable $e) {
            if ($pptxService) {
                $pptxService->cleanup();
            }
            $this->getMoodBoardService()->log("exportPptx error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return Response::json([
                'success' => false,
                'message' => 'PPTX export failed',
            ], 500);
        }
    }

    /**
     * GET /mood-boards/{id}/export-pdf - Download board as PDF file
     */
    public function exportPdf(Request $request): ?Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $pdfService = null;

        try {
            $boardId = (int)$request->getParam('id');
            $service = $this->getMoodBoardService();

            if (!$service->hasAccess($this->getActiveEmail(), $boardId, 'viewer')) {
                return Response::json(['success' => false, 'message' => 'Access denied'], 403);
            }

            set_time_limit(300);
            ini_set('memory_limit', '1G');

            $exportData = $service->getBoardForExport($this->getActiveEmail(), $boardId);

            if (!$exportData) {
                return Response::json(['success' => false, 'message' => 'Board not found'], 404);
            }

            $board = $exportData['board'];
            $assetMap = $exportData['assets'];
            $filePathMap = $exportData['filePaths'] ?? [];
            $boardName = $board['name'] ?? 'Moodboard';

            $pdfService = new \Webmail\Addons\Moodboards\Services\MoodBoardPdfService();
            $filePath = $pdfService->generate($board, $assetMap, $filePathMap);

            unset($assetMap, $filePathMap, $exportData);

            if (!file_exists($filePath)) {
                return Response::json(['success' => false, 'message' => 'PDF generation failed'], 500);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $boardName);
            $fileSize = filesize($filePath);

            $this->streamBinaryFile($filePath, $safeName . '.pdf', 'application/pdf', $fileSize);

            $pdfService->cleanup();
            exit;
        } catch (\Throwable $e) {
            if ($pdfService) {
                $pdfService->cleanup();
            }
            $this->getMoodBoardService()->log("exportPdf error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return Response::json([
                'success' => false,
                'message' => 'PDF export failed',
            ], 500);
        }
    }

    /**
     * POST /mood-boards/{id}/import-texts - Upload CSV to update text items
     */
    public function importTexts(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        $csvContent = null;
        $file = $request->getFile('file');
        
        if (!empty($file['tmp_name'])) {
            $csvContent = file_get_contents($file['tmp_name']);
        } elseif ($request->has('csv')) {
            $csvContent = $request->input('csv');
        }
        
        if (empty($csvContent)) {
            return Response::json(['success' => false, 'message' => 'No CSV file or content provided'], 400);
        }
        
        $result = $this->getMoodBoardService()->importTexts($this->getActiveEmail(), $boardId, $csvContent);
        
        if (!empty($result['errors'])) {
            return Response::json([
                'success' => false,
                'message' => implode('; ', $result['errors']),
                'data' => $result
            ], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => $result,
            'message' => "Updated {$result['updated']} items, skipped {$result['skipped']}"
        ]);
    }

    // ========================================
    // COMMENTS (authenticated)
    // ========================================

    /**
     * GET /mood-boards/{id}/comments - List all comments for a board
     */
    public function listComments(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $service = $this->getMoodBoardService();

        if (!$service->hasAccess($this->getActiveEmail(), $boardId, 'viewer')) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $threads = $service->getComments($boardId);
        $counts = $service->getCommentCountsByItem($boardId);
        $allowComments = $service->boardAllowsComments($boardId);

        return Response::json([
            'success' => true,
            'data' => ['threads' => $threads, 'item_counts' => $counts, 'allow_comments' => $allowComments]
        ]);
    }

    /**
     * POST /mood-boards/{id}/comments - Add a comment (internal user)
     */
    public function addComment(Request $request): Response
    {
        try {
            $authError = $this->requireAuth($request);
            if ($authError) return $authError;

            $boardId = (int)$request->getParam('id');
            $email = $this->getActiveEmail();
            $service = $this->getMoodBoardService();

            if (!$service->hasAccess($email, $boardId, 'viewer')) {
                return Response::json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $data = $request->input();
            $data['author_email'] = $email;
            if (empty($data['author_name'])) {
                $data['author_name'] = explode('@', $email)[0];
            }
            $data['is_public'] = 0;

            $comment = $service->addComment($boardId, $data);
            if (!$comment) {
                return Response::json(['success' => false, 'message' => 'Failed to add comment - service returned null. Check mood-boards.log'], 500);
            }

            $this->triggerCommentNotification($boardId, $comment);
            $this->broadcastMoodBoardEvent('MOOD_BOARD_COMMENT_ADDED', $boardId, ['comment' => $comment], $this->getActiveEmail());

            return Response::json(['success' => true, 'data' => ['comment' => $comment]]);
        } catch (\Throwable $e) {
            error_log("MoodBoardController::addComment FATAL: " . $e->getMessage() . " | " . $e->getTraceAsString());
            return Response::json(['success' => false, 'message' => 'Failed to add comment'], 500);
        }
    }

    /**
     * PUT /mood-boards/{id}/comments/{commentId} - Edit a comment
     */
    public function updateComment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $commentId = (int)$request->getParam('commentId');
        $email = $this->getActiveEmail();
        $service = $this->getMoodBoardService();

        $existing = $service->getComment($commentId);
        if (!$existing || $existing['board_id'] !== $boardId) {
            return Response::json(['success' => false, 'message' => 'Comment not found'], 404);
        }

        if ($existing['author_email'] && strtolower($existing['author_email']) !== strtolower($email)) {
            if (!$service->hasAccess($email, $boardId, 'admin')) {
                return Response::json(['success' => false, 'message' => 'You can only edit your own comments'], 403);
            }
        }

        $content = $request->input('content');
        if (empty(trim($content ?? ''))) {
            return Response::json(['success' => false, 'message' => 'Content is required'], 400);
        }

        $updated = $service->updateComment($commentId, $content);
        if (!$updated) {
            return Response::json(['success' => false, 'message' => 'Failed to update comment'], 500);
        }

        return Response::json(['success' => true, 'data' => ['comment' => $updated]]);
    }

    /**
     * DELETE /mood-boards/{id}/comments/{commentId} - Delete a comment
     */
    public function deleteComment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $commentId = (int)$request->getParam('commentId');
        $email = $this->getActiveEmail();
        $service = $this->getMoodBoardService();

        $existing = $service->getComment($commentId);
        if (!$existing || $existing['board_id'] !== $boardId) {
            return Response::json(['success' => false, 'message' => 'Comment not found'], 404);
        }

        $isOwn = $existing['author_email'] && strtolower($existing['author_email']) === strtolower($email);
        $isAdmin = $service->hasAccess($email, $boardId, 'admin');
        $isBoardOwner = false;
        try {
            $ownerInfo = $service->getBoardOwnerInfo($boardId);
            $isBoardOwner = $ownerInfo && strtolower($ownerInfo['owner_email']) === strtolower($email);
        } catch (\Exception $e) {
            error_log("deleteComment: failed owner check: " . $e->getMessage());
        }

        if (!$isOwn && !$isAdmin && !$isBoardOwner) {
            return Response::json(['success' => false, 'message' => 'You can only delete your own comments'], 403);
        }

        $deleted = $service->deleteComment($commentId);
        if ($deleted) {
            $this->broadcastMoodBoardEvent('MOOD_BOARD_COMMENT_DELETED', $boardId, ['comment_id' => $commentId], $this->getActiveEmail());
        }
        return Response::json(['success' => $deleted, 'message' => $deleted ? 'Comment deleted' : 'Failed to delete comment']);
    }

    /**
     * DELETE /mood-boards/{id}/comments/threads/{threadId}
     */
    public function deleteCommentThread(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId  = (int)$request->getParam('id');
        $threadId = $request->getParam('threadId');
        $email    = $this->getActiveEmail();
        $service  = $this->getMoodBoardService();

        if (!$service->hasAccess($email, $boardId, 'editor')) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $count = $service->deleteThread($boardId, $threadId);
        if ($count > 0) {
            $this->broadcastMoodBoardEvent('MOOD_BOARD_THREAD_DELETED', $boardId, ['thread_id' => $threadId], $this->getActiveEmail());
        }
        return Response::json(['success' => $count > 0, 'data' => ['deleted' => $count]]);
    }

    /**
     * POST /mood-boards/{id}/comments/threads/{threadId}/resolve
     */
    public function resolveCommentThread(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $threadId = $request->getParam('threadId');
        $email = $this->getActiveEmail();
        $service = $this->getMoodBoardService();

        if (!$service->hasAccess($email, $boardId, 'editor')) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $resolved = $service->resolveThread($boardId, $threadId, $email);
        if ($resolved) {
            $this->broadcastMoodBoardEvent('MOOD_BOARD_THREAD_RESOLVED', $boardId, ['thread_id' => $threadId, 'resolved' => true], $this->getActiveEmail());
        }
        return Response::json(['success' => $resolved, 'message' => $resolved ? 'Thread resolved' : 'Failed to resolve thread']);
    }

    /**
     * POST /mood-boards/{id}/comments/threads/{threadId}/unresolve
     */
    public function unresolveCommentThread(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $boardId = (int)$request->getParam('id');
        $threadId = $request->getParam('threadId');
        $email = $this->getActiveEmail();
        $service = $this->getMoodBoardService();

        if (!$service->hasAccess($email, $boardId, 'editor')) {
            return Response::json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $unreserved = $service->unresolveThread($boardId, $threadId);
        if ($unreserved) {
            $this->broadcastMoodBoardEvent('MOOD_BOARD_THREAD_RESOLVED', $boardId, ['thread_id' => $threadId, 'resolved' => false], $this->getActiveEmail());
        }
        return Response::json(['success' => $unreserved, 'message' => $unreserved ? 'Thread reopened' : 'Failed to reopen thread']);
    }

    // ========================================
    // COMMENTS (public — via share token)
    // ========================================

    /**
     * GET /mood-boards/share/{token}/comments - List comments on shared board
     */
    public function publicListComments(Request $request): Response
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            return Response::json(['success' => false, 'message' => 'Invalid link'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo || $shareInfo['is_expired']) {
            return Response::json(['success' => false, 'message' => 'Board not found or link expired'], 404);
        }

        $boardId = $shareInfo['board_id'];
        $threads = $service->getComments($boardId);
        $counts = $service->getCommentCountsByItem($boardId);
        $allowComments = $service->boardAllowsComments($boardId);

        return Response::json([
            'success' => true,
            'data' => [
                'threads' => $threads,
                'item_counts' => $counts,
                'allow_comments' => $allowComments,
            ]
        ]);
    }

    /**
     * POST /mood-boards/share/{token}/comments - Add comment on shared board (public)
     */
    public function publicAddComment(Request $request): Response
    {
        try {
            $token = $request->getParam('token');
            if (empty($token)) {
                return Response::json(['success' => false, 'message' => 'Invalid link'], 400);
            }

            $service = $this->getMoodBoardService();
            $shareInfo = $service->getShareInfo($token);

            if (!$shareInfo || $shareInfo['is_expired']) {
                return Response::json(['success' => false, 'message' => 'Board not found or link expired'], 404);
            }

            $boardId = $shareInfo['board_id'];

            // Default to allowing comments if column doesn't exist yet
            try {
                $allowsComments = $service->boardAllowsComments($boardId);
            } catch (\Throwable $e) {
                $allowsComments = true;
            }

            if (!$allowsComments) {
                return Response::json(['success' => false, 'message' => 'Comments are disabled on this board'], 403);
            }

            $input = $request->input();
            // Explicit whitelist — guests must never set author_email (impersonation /
            // notification suppression) or other internal fields.
            $data = [
                'thread_id'   => $input['thread_id'] ?? null,
                'parent_id'   => $input['parent_id'] ?? null,
                'item_id'     => $input['item_id'] ?? null,
                'content'     => $input['content'] ?? '',
                'author_name' => mb_substr(trim($input['author_name'] ?? ''), 0, 100),
                'pin_x'       => $input['pin_x'] ?? null,
                'pin_y'       => $input['pin_y'] ?? null,
                'is_public'   => 1,
                'share_token' => $token,
            ];

            if ($data['author_name'] === '') {
                $data['author_name'] = 'Guest';
            }

            $comment = $service->addComment($boardId, $data);
            if (!$comment) {
                return Response::json(['success' => false, 'message' => 'Failed to add comment'], 500);
            }

            $this->triggerCommentNotification($boardId, $comment);
            $this->broadcastPublicCommentEvent('MOOD_BOARD_COMMENT_ADDED', $boardId, ['comment' => $comment]);

            return Response::json(['success' => true, 'data' => ['comment' => $comment]]);
        } catch (\Throwable $e) {
            error_log("MoodBoardController::publicAddComment FATAL: " . $e->getMessage() . " | " . $e->getTraceAsString());
            return Response::json(['success' => false, 'message' => 'Failed to add comment'], 500);
        }
    }

    /**
     * POST /mood-boards/share/{token}/comments/threads/{threadId}/resolve
     */
    public function publicResolveThread(Request $request): Response
    {
        $token = $request->getParam('token');
        $threadId = $request->getParam('threadId');

        if (empty($token) || empty($threadId)) {
            return Response::json(['success' => false, 'message' => 'Invalid request'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo || $shareInfo['is_expired'] || $shareInfo['mode'] !== 'edit') {
            return Response::json(['success' => false, 'message' => 'Cannot resolve — view-only or expired'], 403);
        }

        $resolved = $service->resolveThread($shareInfo['board_id'], $threadId, 'shared-user');
        if ($resolved) {
            $this->broadcastPublicCommentEvent('MOOD_BOARD_THREAD_RESOLVED', $shareInfo['board_id'], ['thread_id' => $threadId, 'resolved' => true]);
        }
        return Response::json(['success' => $resolved, 'message' => $resolved ? 'Thread resolved' : 'Failed to resolve thread']);
    }

    /**
     * PUT /mood-boards/share/{token}/comments/{commentId}
     */
    public function publicUpdateComment(Request $request): Response
    {
        $token = $request->getParam('token');
        $commentId = (int)$request->getParam('commentId');

        if (empty($token) || !$commentId) {
            return Response::json(['success' => false, 'message' => 'Invalid request'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo || $shareInfo['is_expired'] || $shareInfo['mode'] !== 'edit') {
            return Response::json(['success' => false, 'message' => 'Cannot edit — view-only or expired'], 403);
        }

        $boardId = $shareInfo['board_id'];
        $existing = $service->getComment($commentId);
        if (!$existing || $existing['board_id'] !== $boardId) {
            return Response::json(['success' => false, 'message' => 'Comment not found'], 404);
        }

        if (!$existing['is_public'] || $existing['share_token'] !== $token) {
            return Response::json(['success' => false, 'message' => 'You can only edit your own comments'], 403);
        }

        $content = $request->input('content');
        if (empty(trim($content ?? ''))) {
            return Response::json(['success' => false, 'message' => 'Content is required'], 400);
        }

        $updated = $service->updateComment($commentId, $content);
        if (!$updated) {
            return Response::json(['success' => false, 'message' => 'Failed to update comment'], 500);
        }

        $this->broadcastPublicCommentEvent('MOOD_BOARD_COMMENT_UPDATED', $boardId, ['comment' => $updated]);
        return Response::json(['success' => true, 'data' => ['comment' => $updated]]);
    }

    /**
     * DELETE /mood-boards/share/{token}/comments/{commentId}
     */
    public function publicDeleteComment(Request $request): Response
    {
        $token = $request->getParam('token');
        $commentId = (int)$request->getParam('commentId');

        if (empty($token) || !$commentId) {
            return Response::json(['success' => false, 'message' => 'Invalid request'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo || $shareInfo['is_expired'] || $shareInfo['mode'] !== 'edit') {
            return Response::json(['success' => false, 'message' => 'Cannot delete — view-only or expired'], 403);
        }

        $boardId = $shareInfo['board_id'];
        $existing = $service->getComment($commentId);
        if (!$existing || $existing['board_id'] !== $boardId) {
            return Response::json(['success' => false, 'message' => 'Comment not found'], 404);
        }

        if (!$existing['is_public'] || $existing['share_token'] !== $token) {
            return Response::json(['success' => false, 'message' => 'You can only delete your own comments'], 403);
        }

        $deleted = $service->deleteComment($commentId);
        if ($deleted) {
            $this->broadcastPublicCommentEvent('MOOD_BOARD_COMMENT_DELETED', $boardId, ['comment_id' => $commentId]);
        }
        return Response::json(['success' => $deleted, 'message' => $deleted ? 'Comment deleted' : 'Failed to delete comment']);
    }

    /**
     * DELETE /mood-boards/share/{token}/comments/threads/{threadId}
     */
    public function publicDeleteCommentThread(Request $request): Response
    {
        $token = $request->getParam('token');
        $threadId = $request->getParam('threadId');

        if (empty($token) || empty($threadId)) {
            return Response::json(['success' => false, 'message' => 'Invalid request'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo || $shareInfo['is_expired'] || $shareInfo['mode'] !== 'edit') {
            return Response::json(['success' => false, 'message' => 'Cannot delete thread — view-only or expired'], 403);
        }

        $boardId = $shareInfo['board_id'];
        $count = $service->deleteThread($boardId, $threadId);
        if ($count > 0) {
            $this->broadcastPublicCommentEvent('MOOD_BOARD_THREAD_DELETED', $boardId, ['thread_id' => $threadId]);
        }
        return Response::json(['success' => $count > 0, 'data' => ['deleted' => $count]]);
    }

    /**
     * POST /mood-boards/share/{token}/comments/threads/{threadId}/unresolve
     */
    public function publicUnresolveThread(Request $request): Response
    {
        $token = $request->getParam('token');
        $threadId = $request->getParam('threadId');

        if (empty($token) || empty($threadId)) {
            return Response::json(['success' => false, 'message' => 'Invalid request'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo || $shareInfo['is_expired'] || $shareInfo['mode'] !== 'edit') {
            return Response::json(['success' => false, 'message' => 'Cannot unresolve — view-only or expired'], 403);
        }

        $boardId = $shareInfo['board_id'];
        $unresolved = $service->unresolveThread($boardId, $threadId);
        if ($unresolved) {
            $this->broadcastPublicCommentEvent('MOOD_BOARD_THREAD_RESOLVED', $boardId, ['thread_id' => $threadId, 'resolved' => false]);
        }
        return Response::json(['success' => $unresolved, 'message' => $unresolved ? 'Thread reopened' : 'Failed to reopen thread']);
    }

    /**
     * Trigger notification to board owner about new comment.
     * Non-blocking — failures are logged, never bubble up.
     */
    private function triggerCommentNotification(int $boardId, array $comment): void
    {
        try {
            $service = $this->getMoodBoardService();
            $ownerInfo = $service->getBoardOwnerInfo($boardId);

            if (!$ownerInfo || !$ownerInfo['notify_on_comment']) {
                return;
            }

            // Don't notify the owner about their own comments
            if ($comment['author_email'] && strtolower($comment['author_email']) === strtolower($ownerInfo['owner_email'])) {
                return;
            }

            $notifier = new \Webmail\Addons\Moodboards\Services\MoodCommentNotificationService($this->config);
            $notifier->notifyOwner($ownerInfo, $comment);
        } catch (\Throwable $e) {
            error_log("[MoodBoard] Comment notification failed (non-critical): " . $e->getMessage());
        }
    }

    // ========================================
    // PUBLIC SHARE — ITEM EDITING
    // ========================================

    /**
     * Validate share token and require edit mode.
     * Returns [service, shareInfo, boardId, ownerEmail] on success, or a Response on failure.
     */
    private function requireShareEditAccess(Request $request): array|Response
    {
        $token = $request->getParam('token');
        if (empty($token)) {
            return Response::json(['success' => false, 'message' => 'Invalid link'], 400);
        }

        $service = $this->getMoodBoardService();
        $shareInfo = $service->getShareInfo($token);

        if (!$shareInfo || $shareInfo['is_expired']) {
            return Response::json(['success' => false, 'message' => 'Board not found or link expired'], 404);
        }

        if ($shareInfo['mode'] !== 'edit') {
            return Response::json(['success' => false, 'message' => 'This share link is view-only'], 403);
        }

        return [
            'service' => $service,
            'shareInfo' => $shareInfo,
            'boardId' => $shareInfo['board_id'],
            'ownerEmail' => $shareInfo['owner_email'],
            'senderToken' => 'guest:' . substr($token, 0, 12),
        ];
    }

    /**
     * PUT /mood-boards/share/{token}/items/{itemId} - Update a single item via share link
     */
    public function publicUpdateItem(Request $request): Response
    {
        try {
            $access = $this->requireShareEditAccess($request);
            if ($access instanceof Response) return $access;

            $itemId = (int)$request->getParam('itemId');
            $data = [];
            $fields = ['parent_id', 'pos_x', 'pos_y', 'width', 'height', 'rotation',
                        'z_index', 'locked', 'title', 'content', 'color', 'url',
                        'style_data', 'color_data',
                        'slide_order', 'transition_type', 'transition_duration', 'presenter_notes'];

            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $data[$field] = $request->input($field);
                }
            }

            $item = $access['service']->updateItem($access['ownerEmail'], $access['boardId'], $itemId, $data);

            if (!$item) {
                return Response::json(['success' => false, 'message' => 'Item not found'], 404);
            }

            $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEM_UPDATED', $access['boardId'], ['item' => $item, 'sender_email' => $access['senderToken']]);

            return Response::json(['success' => true, 'data' => ['item' => $item]]);
        } catch (\Throwable $e) {
            error_log("MoodBoardController::publicUpdateItem FATAL: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    /**
     * PUT /mood-boards/share/{token}/items/batch - Batch update item positions via share link
     */
    public function publicBatchUpdateItems(Request $request): Response
    {
        try {
            $access = $this->requireShareEditAccess($request);
            if ($access instanceof Response) return $access;

            $updates = $request->input('updates');

            if (!is_array($updates) || empty($updates)) {
                return Response::json(['success' => false, 'message' => 'Updates array required'], 400);
            }

            $success = $access['service']->batchUpdateItems($access['ownerEmail'], $access['boardId'], $updates);

            if (!$success) {
                return Response::json(['success' => false, 'message' => 'Failed to update items'], 500);
            }

            $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_MOVED', $access['boardId'], ['updates' => $updates, 'sender_email' => $access['senderToken']]);

            return Response::json(['success' => true, 'message' => 'Items updated']);
        } catch (\Throwable $e) {
            error_log("MoodBoardController::publicBatchUpdateItems FATAL: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    /**
     * POST /mood-boards/share/{token}/items - Add item via share link
     */
    public function publicAddItem(Request $request): Response
    {
        try {
            $access = $this->requireShareEditAccess($request);
            if ($access instanceof Response) return $access;

            $data = [
                'type' => $request->input('type'),
                'pos_x' => (int)$request->input('pos_x', 0),
                'pos_y' => (int)$request->input('pos_y', 0),
                'width' => $request->input('width') ? (int)$request->input('width') : 240,
                'height' => $request->input('height') ? (int)$request->input('height') : null,
                'rotation' => (float)$request->input('rotation', 0),
                'parent_id' => $request->input('parent_id') ? (int)$request->input('parent_id') : null,
                'title' => $request->input('title'),
                'content' => $request->input('content'),
                'color' => $request->input('color'),
                'url' => $request->input('url'),
                'image_url' => $request->input('image_url'),
                'thumbnail_url' => $request->input('thumbnail_url'),
                'style_data' => $request->input('style_data'),
                'slide_order' => $request->input('slide_order') !== null ? (int)$request->input('slide_order') : null,
            ];

            if ($request->has('color_data')) {
                $data['color_data'] = $request->input('color_data');
            }

            $validTypes = ['note', 'image', 'text', 'link', 'todo_list', 'file', 'color_swatch', 'board_link', 'frame', 'slide', 'image_set', 'calendar_event', 'drawing', 'table', 'column', 'folder', 'shape', 'pen_shape', 'video', 'youtube', 'line', 'artboard', 'audio', 'group', 'repeat_grid'];
            if (!in_array($data['type'], $validTypes)) {
                return Response::json(['success' => false, 'message' => 'Invalid item type'], 400);
            }

            $item = $access['service']->addItem($access['ownerEmail'], $access['boardId'], $data);

            if (!$item) {
                return Response::json(['success' => false, 'message' => 'Failed to add item'], 500);
            }

            $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEM_CREATED', $access['boardId'], ['item' => $item, 'sender_email' => $access['senderToken']]);

            return Response::json(['success' => true, 'data' => ['item' => $item], 'message' => 'Item added']);
        } catch (\Throwable $e) {
            error_log("MoodBoardController::publicAddItem FATAL: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    /**
     * DELETE /mood-boards/share/{token}/items/{itemId} - Delete item via share link
     */
    public function publicDeleteItem(Request $request): Response
    {
        try {
            $access = $this->requireShareEditAccess($request);
            if ($access instanceof Response) return $access;

            $itemId = (int)$request->getParam('itemId');

            if (!$access['service']->deleteItem($access['ownerEmail'], $access['boardId'], $itemId)) {
                return Response::json(['success' => false, 'message' => 'Item not found'], 404);
            }

            $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEM_DELETED', $access['boardId'], ['item_id' => $itemId, 'sender_email' => $access['senderToken']]);

            return Response::json(['success' => true, 'message' => 'Item deleted']);
        } catch (\Throwable $e) {
            error_log("MoodBoardController::publicDeleteItem FATAL: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    /**
     * POST /mood-boards/share/{token}/items/batch-add - Batch add items via share link
     */
    public function publicBatchAddItems(Request $request): Response
    {
        try {
            $access = $this->requireShareEditAccess($request);
            if ($access instanceof Response) return $access;

            $itemsData = $request->input('items');
            if (!is_array($itemsData) || empty($itemsData)) {
                return Response::json(['success' => false, 'message' => 'Items array required'], 400);
            }
            if (count($itemsData) > self::MAX_BATCH_ITEMS) {
                return Response::json(['success' => false, 'message' => 'Too many items (max ' . self::MAX_BATCH_ITEMS . ')'], 400);
            }

            $createdItems = [];
            foreach ($itemsData as $data) {
                $item = $access['service']->addItem($access['ownerEmail'], $access['boardId'], $data);
                if ($item) $createdItems[] = $item;
            }

            $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_CREATED', $access['boardId'], ['items' => $createdItems, 'sender_email' => $access['senderToken']]);

            return Response::json(['success' => true, 'data' => ['items' => $createdItems]]);
        } catch (\Throwable $e) {
            error_log("MoodBoardController::publicBatchAddItems FATAL: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    /**
     * POST /mood-boards/share/{token}/items/batch-delete - Batch delete items via share link
     */
    public function publicBatchDeleteItems(Request $request): Response
    {
        try {
            $access = $this->requireShareEditAccess($request);
            if ($access instanceof Response) return $access;

            $itemIds = $request->input('item_ids');
            if (!is_array($itemIds) || empty($itemIds)) {
                return Response::json(['success' => false, 'message' => 'item_ids array required'], 400);
            }
            if (count($itemIds) > self::MAX_BATCH_ITEMS) {
                return Response::json(['success' => false, 'message' => 'Too many items (max ' . self::MAX_BATCH_ITEMS . ')'], 400);
            }

            foreach ($itemIds as $id) {
                $access['service']->deleteItem($access['ownerEmail'], $access['boardId'], (int)$id);
            }

            $this->broadcastMoodBoardEvent('MOOD_BOARD_ITEMS_DELETED', $access['boardId'], ['item_ids' => $itemIds, 'sender_email' => $access['senderToken']]);

            return Response::json(['success' => true, 'message' => 'Items deleted']);
        } catch (\Throwable $e) {
            error_log("MoodBoardController::publicBatchDeleteItems FATAL: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error'], 500);
        }
    }
}

