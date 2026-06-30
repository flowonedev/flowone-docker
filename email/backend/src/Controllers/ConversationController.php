<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\ConversationService;
use Webmail\Services\RedisCacheService;

/**
 * ConversationController - API endpoints for conversation management
 * 
 * Handles:
 * - Getting conversations for a folder (with counts)
 * - Assigning messages to conversations
 * - Moving messages between conversations (user action)
 * - Splitting messages into new conversations
 * - Resetting user overrides
 */
class ConversationController extends BaseController
{
    private ?ConversationService $conversationService = null;
    private ?RedisCacheService $redisCache = null;
    
    /**
     * Get ConversationService instance
     */
    private function getConversationService(): ConversationService
    {
        if (!$this->conversationService) {
            $this->conversationService = new ConversationService($this->config);
        }
        return $this->conversationService;
    }
    
    /**
     * Get RedisCacheService instance for publishing events
     */
    private function getRedisCache(): RedisCacheService
    {
        if (!$this->redisCache) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }
    
    /**
     * Get conversations for a folder with counts
     * GET /conversations?folder=INBOX
     */
    public function getConversations(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->getQuery('folder') ?? 'INBOX';
        $userEmail = $this->userEmail;
        
        try {
            $service = $this->getConversationService();
            $conversations = $service->getConversationsForFolder($userEmail, $folder);
            
            return Response::success([
                'conversations' => $conversations,
                'folder' => $folder,
                'count' => count($conversations)
            ]);
        } catch (\Throwable $e) {
            error_log('[ConversationController] getConversations error: ' . $e->getMessage());
            return Response::error('Failed to get conversations: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get folder index status (for DB-first loading)
     * GET /conversations/status?folder=INBOX
     */
    public function getIndexStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->getQuery('folder') ?? 'INBOX';
        
        try {
            $service = $this->getConversationService();
            $status = $service->getFolderIndexStatus($this->userEmail, $folder);
            
            return Response::success([
                'folder' => $folder,
                'indexed' => $status['indexed'],
                'lastUid' => $status['lastUid'],
                'messageCount' => $status['messageCount'],
                'indexedAt' => $status['indexedAt']
            ]);
        } catch (\Throwable $e) {
            error_log('[ConversationController] getIndexStatus error: ' . $e->getMessage());
            return Response::error('Failed to get index status: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get messages in a specific conversation
     * GET /conversations/{conversation_id}/messages
     */
    public function getConversationMessages(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $conversationId = $request->getParam('conversation_id');
        if (!$conversationId) {
            return Response::error('conversation_id is required', 400);
        }
        
        $service = $this->getConversationService();
        $messages = $service->getConversationMessages($this->userEmail, urldecode($conversationId));
        
        return Response::success([
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'count' => count($messages)
        ]);
    }

    /**
     * Get messages in a conversation across all folders (global view)
     * GET /conversations/{conversation_id}/messages/global
     * 
     * Query params:
     * - folders: comma-separated list of folders to include (optional, defaults to all)
     */
    public function getConversationMessagesGlobal(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $conversationId = $request->getParam('conversation_id');
        if (!$conversationId) {
            return Response::error('conversation_id is required', 400);
        }
        
        $foldersParam = $request->getQuery('folders');
        $includeFolders = $foldersParam ? explode(',', $foldersParam) : null;
        
        $service = $this->getConversationService();
        $messages = $service->getConversationMessagesGlobal(
            $this->userEmail, 
            urldecode($conversationId),
            $includeFolders
        );
        
        // Group by folder for easier frontend processing
        $byFolder = [];
        foreach ($messages as $msg) {
            $folder = $msg['folder'];
            if (!isset($byFolder[$folder])) {
                $byFolder[$folder] = [];
            }
            $byFolder[$folder][] = $msg;
        }
        
        return Response::success([
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'by_folder' => $byFolder,
            'folders' => array_keys($byFolder),
            'count' => count($messages)
        ]);
    }

    /**
     * Get conversations with global message counts (across all folders)
     * GET /conversations/global?folder=INBOX
     * 
     * Returns conversations that have at least one message in the specified folder,
     * but with message_count reflecting ALL messages in the thread across all folders.
     */
    public function getConversationsGlobal(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->getQuery('folder') ?? 'INBOX';
        
        try {
            $service = $this->getConversationService();
            $conversations = $service->getConversationsWithGlobalCounts($this->userEmail, $folder, true);
            
            return Response::success([
                'conversations' => $conversations,
                'folder' => $folder,
                'count' => count($conversations),
                'global_counts' => true
            ]);
        } catch (\Throwable $e) {
            error_log('[ConversationController] getConversationsGlobal error: ' . $e->getMessage());
            return Response::error('Failed to get conversations: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Assign messages to conversations (batch)
     * Called when fetching messages to ensure they're in DB
     * POST /conversations/assign
     * Body: { folder: string, messages: array, force_conversation_id?: string }
     * 
     * If force_conversation_id is provided, all messages will be assigned to that conversation
     * (useful for sent replies/forwards to stay in the same thread as the original)
     */
    public function assignMessages(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->input('folder');
        $messages = $request->input('messages');
        $forceConversationId = $request->input('force_conversation_id');
        
        if (!$folder || !is_array($messages)) {
            return Response::error('folder and messages array are required', 400);
        }
        
        try {
            $service = $this->getConversationService();
            
            // If force_conversation_id is provided, assign all messages to that conversation
            if ($forceConversationId) {
                error_log("[ConversationController] Force-assigning to conversation: $forceConversationId in folder: $folder");
                $assignments = [];
                foreach ($messages as $message) {
                    $msgId = $message['message_id'] ?? 'unknown';
                    error_log("[ConversationController] Processing message: $msgId");
                    
                    $conversationId = $service->assignMessageToConversation(
                        $this->userEmail,
                        $folder,
                        $message,
                        $forceConversationId
                    );
                    $messageId = $message['message_id'] ?? ('uid:' . ($message['uid'] ?? ''));
                    $assignments[$messageId] = $conversationId;
                    error_log("[ConversationController] Assigned message $msgId to conversation: $conversationId");
                }
            } else {
                $assignments = $service->assignMessagesToConversations($this->userEmail, $folder, $messages);
            }
            
            return Response::success([
                'assignments' => $assignments,
                'count' => count($assignments),
                'forced' => $forceConversationId ? true : false
            ]);
        } catch (\Throwable $e) {
            error_log('[ConversationController] assignMessages error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('[ConversationController] Stack trace: ' . $e->getTraceAsString());
            return Response::error('Failed to assign messages: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Move a message to a different conversation (user action)
     * PUT /conversations/move
     * Body: { folder: string, message_id: string, target_conversation_id: string }
     */
    public function moveMessage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->input('folder');
        $messageId = $request->input('message_id');
        $targetConversationId = $request->input('target_conversation_id');
        
        if (!$folder || !$messageId || !$targetConversationId) {
            return Response::error('folder, message_id, and target_conversation_id are required', 400);
        }
        
        $service = $this->getConversationService();
        $success = $service->moveMessageToConversation(
            $this->userEmail,
            $folder,
            $messageId,
            $targetConversationId
        );
        
        if (!$success) {
            return Response::error('Failed to move message. Make sure the message exists.', 400);
        }
        
        // Publish real-time event for WebSocket sync
        $cache = $this->getRedisCache();
        $cache->publishConversationUpdated($this->userEmail, $targetConversationId, $folder);
        
        // Get updated conversation data
        $conversations = $service->getConversationsForFolder($this->userEmail, $folder);
        
        return Response::success([
            'moved' => true,
            'message_id' => $messageId,
            'target_conversation_id' => $targetConversationId,
            'conversations' => $conversations
        ]);
    }
    
    /**
     * Split a message into a new conversation
     * POST /conversations/split
     * Body: { folder: string, message_id: string }
     */
    public function splitMessage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->input('folder');
        $messageId = $request->input('message_id');
        
        if (!$folder || !$messageId) {
            return Response::error('folder and message_id are required', 400);
        }
        
        $service = $this->getConversationService();
        $newConversationId = $service->splitMessageToNewConversation(
            $this->userEmail,
            $folder,
            $messageId
        );
        
        // Publish real-time event for WebSocket sync
        if ($newConversationId) {
            $cache = $this->getRedisCache();
            $cache->publishConversationUpdated($this->userEmail, $newConversationId, $folder);
        }
        
        // Get updated conversation data
        $conversations = $service->getConversationsForFolder($this->userEmail, $folder);
        
        return Response::success([
            'split' => true,
            'message_id' => $messageId,
            'new_conversation_id' => $newConversationId,
            'conversations' => $conversations
        ]);
    }
    
    /**
     * Merge two standalone emails into a new conversation
     * POST /conversations/merge
     * Body: { folder: string, message_id_1: string, message_id_2: string }
     */
    public function mergeMessages(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->input('folder');
        $messageId1 = $request->input('message_id_1');
        $messageId2 = $request->input('message_id_2');
        
        if (!$folder || !$messageId1 || !$messageId2) {
            return Response::error('folder, message_id_1, and message_id_2 are required', 400);
        }
        
        if ($messageId1 === $messageId2) {
            return Response::error('Cannot merge a message with itself', 400);
        }
        
        $service = $this->getConversationService();
        $newConversationId = $service->mergeMessagesToConversation(
            $this->userEmail,
            $folder,
            $messageId1,
            $messageId2
        );
        
        if (!$newConversationId) {
            return Response::error('Failed to merge messages. Make sure both messages exist.', 400);
        }
        
        // Publish real-time event for WebSocket sync
        $cache = $this->getRedisCache();
        $cache->publishConversationUpdated($this->userEmail, $newConversationId, $folder);
        
        // Get updated conversation data
        $conversations = $service->getConversationsForFolder($this->userEmail, $folder);
        
        return Response::success([
            'merged' => true,
            'message_id_1' => $messageId1,
            'message_id_2' => $messageId2,
            'new_conversation_id' => $newConversationId,
            'conversations' => $conversations
        ]);
    }
    
    /**
     * Reset user override (restore auto-grouping)
     * DELETE /conversations/override
     * Body: { folder: string, message_id: string }
     */
    public function resetOverride(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->input('folder');
        $messageId = $request->input('message_id');
        
        if (!$folder || !$messageId) {
            return Response::error('folder and message_id are required', 400);
        }
        
        $service = $this->getConversationService();
        $success = $service->resetMessageOverride($this->userEmail, $folder, $messageId);
        
        if (!$success) {
            return Response::error('Failed to reset override. Message may not exist.', 400);
        }
        
        return Response::success([
            'reset' => true,
            'message_id' => $messageId
        ]);
    }
    
    /**
     * Get conversation ID for a specific message
     * GET /conversations/for-message?folder=INBOX&message_id=xxx
     */
    public function getConversationForMessage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->getQuery('folder') ?? 'INBOX';
        $messageId = $request->getQuery('message_id');
        $uid = $request->getQuery('uid');
        
        if (!$messageId && !$uid) {
            return Response::error('message_id or uid is required', 400);
        }
        
        $service = $this->getConversationService();
        $conversationId = $service->getConversationIdForMessage(
            $this->userEmail,
            $folder,
            $uid ? (int)$uid : null,
            $messageId
        );
        
        return Response::success([
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'uid' => $uid
        ]);
    }
    
    /**
     * Migrate existing JSON-based splits to database
     * POST /conversations/migrate-splits
     * Body: { splits: object }
     */
    public function migrateSplits(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $splits = $request->input('splits');
        
        if (!is_array($splits)) {
            return Response::error('splits object is required', 400);
        }
        
        $service = $this->getConversationService();
        $migrated = $service->migrateFromJsonSplits($this->userEmail, $splits);
        
        return Response::success([
            'migrated' => $migrated,
            'total' => count($splits)
        ]);
    }
    
    /**
     * Sync new messages incrementally (fetch only new messages since lastUid)
     * POST /conversations/sync
     * Body: { folder: string, since_uid: int }
     */
    public function syncFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->input('folder') ?? 'INBOX';
        $sinceUid = (int)($request->input('since_uid') ?? 0);
        
        try {
            // Get IMAP connection
            $imap = $this->getImap($request);
            if (!$imap) {
                return Response::error('Could not connect to mail server', 500);
            }
            
            // Fetch only messages with UID > since_uid
            $result = $imap->getMessagesSince($folder, $sinceUid);
            $newMessages = $result['messages'] ?? [];
            
            if (empty($newMessages)) {
                // No new messages, return current state
                $service = $this->getConversationService();
                return Response::success([
                    'synced' => 0,
                    'newMessages' => [],
                    'conversations' => $service->getConversationsForFolder($this->userEmail, $folder)
                ]);
            }
            
            // Prepare messages for assignment
            $messagesToAssign = [];
            $maxUid = $sinceUid;
            
            foreach ($newMessages as $msg) {
                $messagesToAssign[] = [
                    'uid' => $msg['uid'],
                    'message_id' => $msg['message_id'] ?? null,
                    'subject' => $msg['subject'] ?? '',
                    'date' => $msg['date'] ?? null,
                    'from' => $msg['from'] ?? [],
                    'references' => $msg['references'] ?? [],
                    'in_reply_to' => $msg['in_reply_to'] ?? null,
                    'has_attachment' => $msg['has_attachment'] ?? false
                ];
                
                if ($msg['uid'] > $maxUid) {
                    $maxUid = $msg['uid'];
                }
            }
            
            // Assign to conversations
            $service = $this->getConversationService();
            $assignments = $service->assignMessagesToConversations($this->userEmail, $folder, $messagesToAssign);
            
            // Update folder index with new lastUid
            $service->updateLastIndexedUid($this->userEmail, $folder, $maxUid, count($newMessages));
            
            // Get updated conversations
            $conversations = $service->getConversationsForFolder($this->userEmail, $folder);
            
            return Response::success([
                'synced' => count($newMessages),
                'lastUid' => $maxUid,
                'assignments' => $assignments,
                'conversations' => $conversations
            ]);
        } catch (\Throwable $e) {
            error_log('[ConversationController] syncFolder error: ' . $e->getMessage());
            return Response::error('Failed to sync folder: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Full folder index (initial indexing of all messages)
     * POST /conversations/index
     * Body: { folder: string, messages: array }
     * Called by frontend after fetching all messages from IMAP
     */
    public function indexFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $folder = $request->input('folder');
        $messages = $request->input('messages');
        $lastUid = (int)($request->input('last_uid') ?? 0);
        
        if (!$folder || !is_array($messages)) {
            return Response::error('folder and messages array are required', 400);
        }
        
        try {
            $service = $this->getConversationService();
            
            // Assign all messages to conversations
            $assignments = $service->assignMessagesToConversations($this->userEmail, $folder, $messages);
            
            // Mark folder as indexed
            $service->markFolderIndexed($this->userEmail, $folder, $lastUid, count($messages));
            
            // Get conversation list
            $conversations = $service->getConversationsForFolder($this->userEmail, $folder);
            
            return Response::success([
                'indexed' => true,
                'messageCount' => count($messages),
                'conversationCount' => count($conversations),
                'lastUid' => $lastUid,
                'conversations' => $conversations
            ]);
        } catch (\Throwable $e) {
            error_log('[ConversationController] indexFolder error: ' . $e->getMessage());
            return Response::error('Failed to index folder: ' . $e->getMessage(), 500);
        }
    }
}

