<?php

namespace Webmail\Addons\Chat\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Chat\Services\ChatService;
use Webmail\Addons\Chat\Services\MentionService;
use Webmail\Services\SmtpService;
use Webmail\Services\SearchIndexerService;

/**
 * ChatController - REST API for Direct Message Chat
 */
class ChatController extends BaseController
{
    private ?ChatService $chatService = null;
    private ?SearchIndexerService $searchIndexer = null;
    
    private function getSearchIndexer(): SearchIndexerService
    {
        if (!$this->searchIndexer) {
            $this->searchIndexer = new SearchIndexerService($this->config);
        }
        return $this->searchIndexer;
    }
    
    /**
     * Index a chat message for search (call after send/edit)
     */
    private function triggerChatMessageIndex(array $message, int $conversationId): void
    {
        try {
            $email = $this->userEmail;
            
            // Get conversation info
            $db = \Webmail\Core\Database::getConnection($this->config);
            $convStmt = $db->prepare('SELECT type, name, topic, slug FROM chat_conversations WHERE id = ?');
            $convStmt->execute([$conversationId]);
            $conversation = $convStmt->fetch(\PDO::FETCH_ASSOC);
            
            // Get all participants to index the message for each of them
            $partStmt = $db->prepare('
                SELECT oc.email FROM chat_participants cp
                JOIN organization_colleagues oc ON cp.colleague_id = oc.id
                WHERE cp.conversation_id = ?
            ');
            $partStmt->execute([$conversationId]);
            $participants = $partStmt->fetchAll(\PDO::FETCH_COLUMN);
            
            foreach ($participants as $participantEmail) {
                $this->getSearchIndexer()->indexChatMessage(
                    $participantEmail,
                    $message,
                    $conversation ?: []
                );
            }
        } catch (\Exception $e) {
            error_log("ChatController triggerChatMessageIndex error: " . $e->getMessage());
        }
    }
    
    /**
     * Remove a chat message from search index for all participants
     */
    private function removeChatMessageFromIndex(int $messageId, int $conversationId): void
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $partStmt = $db->prepare('
                SELECT oc.email FROM chat_participants cp
                JOIN organization_colleagues oc ON cp.colleague_id = oc.id
                WHERE cp.conversation_id = ?
            ');
            $partStmt->execute([$conversationId]);
            $participants = $partStmt->fetchAll(\PDO::FETCH_COLUMN);
            
            foreach ($participants as $participantEmail) {
                $this->getSearchIndexer()->removeFromIndex(
                    $participantEmail,
                    'chat_message',
                    (string)$messageId
                );
            }
        } catch (\Exception $e) {
            error_log("ChatController removeChatMessageFromIndex error: " . $e->getMessage());
        }
    }
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->chatService = new ChatService($config);
    }
    
    /**
     * Get ChatService (lazy init after auth)
     */
    private function getChatService(): ?ChatService
    {
        if (!$this->chatService) {
            try {
                $this->chatService = new ChatService($this->config);
            } catch (\Throwable $e) {
                error_log("ChatController: Failed to init ChatService: " . $e->getMessage());
            }
        }
        return $this->chatService;
    }
    
    /**
     * Require auth + chat service availability
     */
    protected function requireChatAuth(Request $request): ?Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->getChatService()) {
            return Response::json(['error' => 'Chat service unavailable'], 503);
        }
        return null;
    }
    
    // ========================================
    // CONVERSATIONS
    // ========================================
    
    /**
     * GET /chat/conversations
     * List all conversations for the current user
     */
    public function listConversations(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $limit = (int)$request->getQuery('limit', 50);
        $offset = (int)$request->getQuery('offset', 0);
        
        $result = $this->chatService->getConversations($this->userEmail, $limit, $offset);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'conversations' => $result['conversations']
            ]
        ]);
    }
    
    /**
     * POST /chat/dm/{colleagueId}
     * Get or create a DM conversation with a colleague
     */
    public function getOrCreateDM(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $colleagueId = (int)$request->getParam('colleagueId');
        if (!$colleagueId) {
            return Response::json(['success' => false, 'error' => 'Colleague ID required'], 400);
        }
        
        try {
            $result = $this->chatService->getOrCreateDMConversation($this->userEmail, $colleagueId);
            
            if (!$result['success']) {
                return Response::json(['success' => false, 'error' => $result['error']], 400);
            }
            
            return Response::json([
                'success' => true,
                'data' => [
                    'conversation' => $result['conversation']
                ]
            ]);
        } catch (\Throwable $e) {
            error_log("ChatController::getOrCreateDM error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * POST /chat/invite
     * Invite an external user to chat (sends email invitation)
     */
    public function inviteToChat(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $data = $request->input();
        $email = trim($data['email'] ?? '');
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['success' => false, 'error' => 'Valid email address required'], 400);
        }
        
        try {
            // Create invite without sending email (we'll send from controller with user's credentials)
            $result = $this->chatService->inviteExternalUser($this->userEmail, $email, false);
            
            if (!$result['success']) {
                return Response::json(['success' => false, 'error' => $result['error']], 400);
            }
            
            // Send invitation email from user's own account
            if (!empty($result['invite_token']) && !empty($result['inviter'])) {
                $emailSent = $this->sendInvitationEmail($email, $result['invite_token'], $result['inviter']);
                if (!$emailSent) {
                    // Invite was created but email failed - still return success but warn
                    return Response::json([
                        'success' => true,
                        'data' => [
                            'invite_id' => $result['invite_id'] ?? null,
                            'conversation_id' => $result['conversation_id'] ?? null,
                            'message' => 'Invitation created but email could not be sent. Please share the invite link manually.'
                        ]
                    ]);
                }
            }
            
            return Response::json([
                'success' => true,
                'data' => [
                    'invite_id' => $result['invite_id'] ?? null,
                    'conversation_id' => $result['conversation_id'] ?? null,
                    'message' => $result['message'] ?? 'Invitation sent'
                ]
            ]);
        } catch (\Throwable $e) {
            error_log("ChatController::inviteToChat error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::json(['success' => false, 'error' => 'Failed to send invitation'], 500);
        }
    }
    
    /**
     * Send chat invitation email from user's own account
     */
    private function sendInvitationEmail(string $inviteeEmail, string $token, array $inviter): bool
    {
        try {
            $smtp = null;
            
            // Check if using OAuth or password authentication (same pattern as BoardController)
            if ($this->isOAuthSession && $this->oauthProvider) {
                $accessToken = null;
                $smtpConfig = null;
                
                if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                    $accessToken = $this->microsoftOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    $smtpConfig = [
                        'host' => 'smtp.office365.com',
                        'port' => 587,
                        'encryption' => 'tls',
                        'auth' => true,
                    ];
                } elseif ($this->oauthProvider === 'google' && $this->googleOAuthService) {
                    $accessToken = $this->googleOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    $smtpConfig = [
                        'host' => 'smtp.gmail.com',
                        'port' => 587,
                        'encryption' => 'tls',
                        'auth' => true,
                    ];
                }
                
                if (!$accessToken) {
                    error_log("ChatController: Cannot send invite email - failed to get OAuth access token");
                    return false;
                }
                
                $smtp = new SmtpService($smtpConfig);
                $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
            } elseif ($this->userPassword) {
                // Password-based authentication
                $smtp = new SmtpService($this->config['smtp']);
                $smtp->setCredentials($this->userEmail, $this->userPassword);
            } else {
                error_log("ChatController: Cannot send invite email - session expired, user needs to re-login");
                return false;
            }
            
            $appName = $this->config['app']['name'] ?? 'Email App';
            $baseUrl = $this->config['app']['frontend_url'] ?? $this->config['app']['url'] ?? 'https://flowone.pro';
            $inviteUrl = $baseUrl . '/chat/invite/' . $token;
            $inviterName = $inviter['display_name'] ?? explode('@', $this->userEmail)[0];
            
            $subject = "{$inviterName} invited you to chat";
            $htmlBody = $this->buildInviteEmailHtml($inviterName, $this->userEmail, $inviteUrl, $appName);
            $textBody = "{$inviterName} ({$this->userEmail}) has invited you to chat on {$appName}.\n\n";
            $textBody .= "Click here to accept: {$inviteUrl}\n\n";
            $textBody .= "This invitation expires in 7 days.";
            
            $result = $smtp->send([
                'from_name' => $inviterName,
                'to' => [['email' => $inviteeEmail]],
                'subject' => $subject,
                'body_html' => $htmlBody,
                'body_text' => $textBody,
            ]);
            
            if ($result['success'] ?? false) {
                error_log("ChatController: Invite email sent to {$inviteeEmail}");
                return true;
            } else {
                error_log("ChatController: Failed to send invite email: " . ($result['error'] ?? 'Unknown error'));
                return false;
            }
        } catch (\Throwable $e) {
            error_log("ChatController: Failed to send invite email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Build HTML email for chat invitation
     */
    private function buildInviteEmailHtml(string $inviterName, string $inviterEmail, string $inviteUrl, string $appName): string
    {
        return <<<HTML
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
    }
    
    // ========================================
    // INVITATION ENDPOINTS (accept / decline / list)
    // ========================================
    
    /**
     * GET /chat/invitations
     * Get pending invitations for the logged-in user
     */
    public function getPendingInvitations(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $result = $this->chatService->getPendingInvitations($this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 500);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'invitations' => $result['invitations']
            ]
        ]);
    }
    
    /**
     * POST /chat/invitations/{id}/accept
     * Accept a pending invitation
     */
    public function acceptInvitation(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $invitationId = (int)$request->getParam('id');
        
        $result = $this->chatService->acceptInvitation($this->userEmail, $invitationId);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'conversation_id' => $result['conversation_id'] ?? null,
                'message' => $result['message'] ?? 'Invitation accepted'
            ]
        ]);
    }
    
    /**
     * POST /chat/invitations/{id}/decline
     * Decline a pending invitation
     */
    public function declineInvitation(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $invitationId = (int)$request->getParam('id');
        
        $result = $this->chatService->declineInvitation($this->userEmail, $invitationId);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'message' => $result['message'] ?? 'Invitation declined'
            ]
        ]);
    }
    
    /**
     * GET /chat/invitations/token/{token}
     * Look up an invitation by token (from email link)
     */
    public function getInvitationByToken(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $token = $request->getParam('token');
        
        if (!$token) {
            return Response::json(['success' => false, 'error' => 'Token required'], 400);
        }
        
        $invitation = $this->chatService->getInvitationByToken($token);
        
        if (!$invitation) {
            return Response::json(['success' => false, 'error' => 'Invitation not found'], 404);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'invitation' => $invitation
            ]
        ]);
    }
    
    /**
     * GET /chat/conversations/{id}
     * Get a specific conversation
     */
    public function getConversation(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->getConversation($conversationId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'conversation' => $result['conversation']
            ]
        ]);
    }

    /**
     * GET /chat/conversations/{id}/meeting
     * Resolve the calendar meeting (if any) linked to a meeting conversation.
     * Used by the chat meeting header to show time + host/participant links.
     */
    public function getConversationMeeting(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $conversationId = (int)$request->getParam('id');

        $result = $this->chatService->getMeetingForConversation($conversationId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => $result,
        ]);
    }
    
    /**
     * GET /chat/unread
     * Get unread counts for all conversations
     */
    /**
     * GET /chat/init - Combined initial load for chat
     * Replaces: GET /chat/unread + GET /chat/invitations + GET /chat/huddles/active-all
     */
    public function init(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $result = [
            'unread_total' => 0,
            'unread_by_conversation' => [],
            'invitations' => [],
            'active_huddles' => [],
        ];

        // 1. Unread counts
        try {
            $unreadResult = $this->chatService->getUnreadCounts($this->userEmail);
            if ($unreadResult['success']) {
                $result['unread_total'] = $unreadResult['total'];
                $result['unread_by_conversation'] = $unreadResult['by_conversation'];
            }
        } catch (\Exception $e) {
            error_log("[ChatController::init] Failed to get unread counts: " . $e->getMessage());
        }

        // 2. Pending invitations
        try {
            $invResult = $this->chatService->getPendingInvitations($this->userEmail);
            if ($invResult['success']) {
                $result['invitations'] = $invResult['invitations'];
            }
        } catch (\Exception $e) {
            error_log("[ChatController::init] Failed to get invitations: " . $e->getMessage());
        }

        // 3. Active huddles
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $huddleService = new \Webmail\Services\HuddleService($db);
            $huddleResult = $huddleService->getAllActiveHuddles($this->userEmail);
            if ($huddleResult['success']) {
                $result['active_huddles'] = $huddleResult['huddles'];
            }
        } catch (\Exception $e) {
            error_log("[ChatController::init] Failed to get active huddles: " . $e->getMessage());
        }

        return Response::json(['success' => true, 'data' => $result]);
    }

    public function getUnreadCounts(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $result = $this->chatService->getUnreadCounts($this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'total' => $result['total'],
                'by_conversation' => $result['by_conversation']
            ]
        ]);
    }
    
    // ========================================
    // MESSAGES
    // ========================================
    
    /**
     * GET /chat/conversations/{id}/messages
     * Get messages for a conversation (paginated)
     */
    public function getMessages(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $limit = (int)$request->getQuery('limit', 50);
        $beforeId = $request->getQuery('before_id') ? (int)$request->getQuery('before_id') : null;
        
        $result = $this->chatService->getMessages($conversationId, $this->userEmail, $limit, $beforeId);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'messages' => $result['messages'],
                'has_more' => $result['has_more']
            ]
        ]);
    }
    
    /**
     * POST /chat/conversations/{id}/messages
     * Send a message
     */
    public function sendMessage(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $body = $request->input();
        
        $content = $body['content'] ?? '';
        $replyToId = isset($body['reply_to_id']) ? (int)$body['reply_to_id'] : null;
        $attachments = $body['attachments'] ?? null;
        $voiceDuration = isset($body['voice_duration']) ? (float)$body['voice_duration'] : null;
        $alsoSendToChannel = !empty($body['also_send_to_channel']);
        
        $result = $this->chatService->sendMessage(
            $conversationId,
            $this->userEmail,
            $content,
            $replyToId,
            $attachments,
            $voiceDuration,
            $alsoSendToChannel
        );
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        // Index for search
        if (!empty($result['message'])) {
            $this->triggerChatMessageIndex($result['message'], $conversationId);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'message' => $result['message']
            ]
        ]);
    }
    
    /**
     * PATCH /chat/messages/{id}
     * Edit a message
     */
    public function editMessage(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $messageId = (int)$request->getParam('id');
        $body = $request->input();
        
        $content = $body['content'] ?? '';
        if (empty(trim($content))) {
            return Response::json(['success' => false, 'error' => 'Content required'], 400);
        }
        
        $result = $this->chatService->editMessage($messageId, $this->userEmail, $content);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        // Re-index edited message
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare('
                SELECT m.id, m.conversation_id, m.content, m.content_type, m.created_at,
                       oc.display_name as sender_name, oc.email as sender_email
                FROM chat_messages m
                JOIN organization_colleagues oc ON m.sender_id = oc.id
                WHERE m.id = ?
            ');
            $stmt->execute([$messageId]);
            $msg = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($msg) {
                $this->triggerChatMessageIndex($msg, (int)$msg['conversation_id']);
            }
        } catch (\Exception $e) {
            error_log("ChatController editMessage search index error: " . $e->getMessage());
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * DELETE /chat/messages/{id}
     * Delete a message
     */
    public function deleteMessage(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $messageId = (int)$request->getParam('id');
        
        // Get conversation_id before deleting (for search removal)
        $conversationId = null;
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare('SELECT conversation_id FROM chat_messages WHERE id = ?');
            $stmt->execute([$messageId]);
            $conversationId = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            error_log("ChatController deleteMessage pre-lookup error: " . $e->getMessage());
        }
        
        $result = $this->chatService->deleteMessage($messageId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        // Remove from search index
        if ($conversationId) {
            $this->removeChatMessageFromIndex($messageId, $conversationId);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * DELETE /chat/messages/{id}/thread
     * Delete an entire thread (all replies to a parent message)
     */
    public function deleteThread(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $messageId = (int)$request->getParam('id');
        
        $result = $this->chatService->deleteThread($messageId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true, 'deleted_count' => $result['deleted_count']]);
    }
    
    // ========================================
    // REACTIONS
    // ========================================
    
    /**
     * POST /chat/messages/{id}/reactions
     * Add a reaction to a message
     */
    public function addReaction(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $messageId = (int)$request->getParam('id');
        $body = $request->input();
        
        $emoji = $body['emoji'] ?? '';
        if (empty($emoji)) {
            return Response::json(['success' => false, 'error' => 'Emoji required'], 400);
        }
        
        $result = $this->chatService->addReaction($messageId, $this->userEmail, $emoji);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * DELETE /chat/messages/{id}/reactions/{emoji}
     * Remove a reaction from a message
     */
    public function removeReaction(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $messageId = (int)$request->getParam('id');
        $emoji = urldecode($request->getParam('emoji'));
        
        if (empty($emoji)) {
            return Response::json(['success' => false, 'error' => 'Emoji required'], 400);
        }
        
        $result = $this->chatService->removeReaction($messageId, $this->userEmail, $emoji);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    // ========================================
    // MESSAGE PINNING
    // ========================================
    
    /**
     * POST /chat/messages/{id}/pin
     * Toggle pin status of a message
     */
    public function togglePinMessage(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $messageId = (int)$request->getParam('id');
        
        $result = $this->chatService->togglePinMessage($messageId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['is_pinned' => $result['is_pinned']]
        ]);
    }
    
    /**
     * GET /chat/conversations/{id}/pinned
     * Get all pinned messages in a conversation
     */
    public function getPinnedMessages(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->getPinnedMessages($conversationId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['messages' => $result['messages']]
        ]);
    }
    
    // ========================================
    // READ RECEIPTS & TYPING
    // ========================================
    
    /**
     * POST /chat/conversations/{id}/read
     * Mark conversation as read
     */
    public function markAsRead(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $body = $request->input();
        $messageId = isset($body['message_id']) ? (int)$body['message_id'] : null;
        
        $result = $this->chatService->markAsRead($conversationId, $this->userEmail, $messageId);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * POST /chat/conversations/{id}/typing
     * Update typing status
     */
    public function updateTyping(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $body = $request->input();
        $isTyping = (bool)($body['is_typing'] ?? false);
        
        $result = $this->chatService->updateTypingStatus($conversationId, $this->userEmail, $isTyping);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    // ========================================
    // SEARCH
    // ========================================
    
    /**
     * GET /chat/search
     * Search messages
     */
    public function searchMessages(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $query = $request->getQuery('q', '');
        $conversationId = $request->getQuery('conversation_id') ? (int)$request->getQuery('conversation_id') : null;
        $limit = (int)$request->getQuery('limit', 50);
        
        if (empty(trim($query))) {
            return Response::json(['success' => false, 'error' => 'Search query required'], 400);
        }
        
        $result = $this->chatService->searchMessages($this->userEmail, $query, $conversationId, $limit);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'messages' => $result['messages']
            ]
        ]);
    }
    
    // ========================================
    // CONVERSATION SETTINGS
    // ========================================
    
    /**
     * POST /chat/conversations/{id}/pin
     * Toggle pin status
     */
    public function togglePin(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->togglePin($conversationId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'is_pinned' => $result['is_pinned']
            ]
        ]);
    }
    
    /**
     * POST /chat/conversations/{id}/mute
     * Toggle mute status
     */
    public function toggleMute(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->toggleMute($conversationId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'is_muted' => $result['is_muted']
            ]
        ]);
    }
    
    /**
     * POST /chat/conversations/{id}/archive
     * Archive a conversation
     */
    public function archiveConversation(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->archiveConversation($conversationId, $this->userEmail, true);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * POST /chat/conversations/{id}/unarchive
     * Unarchive a conversation
     */
    public function unarchiveConversation(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->archiveConversation($conversationId, $this->userEmail, false);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * DELETE /chat/conversations/{id}
     * Delete a conversation for the current user (soft delete - archives + clears messages for this user only)
     */
    public function deleteConversation(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->deleteConversationForUser($conversationId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    // ========================================
    // ATTACHMENTS
    // ========================================
    
    /**
     * POST /chat/conversations/{id}/attachments
     * Upload attachments to a conversation
     */
    public function uploadAttachments(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        // Multipart (web/desktop) populates $_FILES. The native iOS/Android shells
        // cannot send multipart through CapacitorHttp (the binary part is dropped
        // in transit), so they POST a base64 JSON body instead. decodeBase64Files()
        // turns that into in-memory file entries the service writes straight to
        // storage -- no system temp file, so nothing has to be moved across a
        // filesystem boundary onto the NAS (a copy() the hardened host can refuse).
        $files = $this->normalizeMultipartFiles($_FILES['files'] ?? []);
        if (empty($files)) {
            $files = $this->decodeBase64Files($request->input('files'));
        }
        
        if (empty($files)) {
            return Response::json(['success' => false, 'error' => 'No files uploaded'], 400);
        }
        
        $result = $this->chatService->uploadAttachments($conversationId, $this->userEmail, $files);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'attachments' => $result['attachments']
            ]
        ]);
    }
    
    /**
     * Normalize a raw $_FILES['files'] payload (single or multiple) into the
     * flat list of file entries ChatService::uploadAttachments expects.
     */
    private function normalizeMultipartFiles($files): array
    {
        if (!is_array($files) || empty($files) || !isset($files['tmp_name'])) {
            return [];
        }
        
        // Single file
        if (!is_array($files['tmp_name'])) {
            return [[
                'name' => $files['name'],
                'type' => $files['type'],
                'tmp_name' => $files['tmp_name'],
                'error' => $files['error'],
                'size' => $files['size'],
            ]];
        }
        
        // Multiple files - restructure the parallel arrays into a list
        $restructured = [];
        $count = count($files['tmp_name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $restructured[] = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];
            }
        }
        return $restructured;
    }
    
    /**
     * Decode a base64 JSON files payload (native shells) into in-memory file
     * entries flagged is_local_temp so ChatService stores them without
     * is_uploaded_file()/move_uploaded_file(). The payload is validated BEFORE
     * decoding so malformed or oversized requests never reach the decode step.
     * Decoded bytes are passed in 'data' and written straight to storage by the
     * service -- avoiding a system temp file that the host may refuse to move
     * onto the NAS mount.
     */
    private function decodeBase64Files($items): array
    {
        if (!is_array($items) || $items === []) {
            return [];
        }
        
        $maxBytes = 50 * 1024 * 1024; // mirror ChatService's 50MB per-file cap
        $files = [];
        
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            
            $name = isset($item['name']) ? (string)$item['name'] : '';
            $type = isset($item['type']) ? (string)$item['type'] : '';
            $data = isset($item['data']) ? (string)$item['data'] : '';
            if ($name === '' || $type === '' || $data === '') {
                continue;
            }
            
            // Strip an optional "data:*;base64," URI prefix; if it looks like a
            // data URI it MUST declare base64, otherwise the payload is malformed.
            if (stripos($data, 'data:') === 0) {
                $commaPos = strpos($data, ',');
                if ($commaPos === false
                    || stripos(substr($data, 0, $commaPos), ';base64') === false) {
                    continue;
                }
                $data = substr($data, $commaPos + 1);
            }
            if ($data === '') continue;
            
            // Strict decode once; validate the size before keeping the bytes.
            $decoded = base64_decode($data, true);
            if ($decoded === false) {
                continue;
            }
            $size = strlen($decoded);
            if ($size === 0 || $size > $maxBytes) {
                unset($decoded);
                continue;
            }
            
            $files[] = [
                'name' => $name,
                'type' => $type,
                'data' => $decoded,
                'size' => $size,
                'is_local_temp' => true,
            ];
        }
        
        return $files;
    }
    
    /**
     * GET /chat/conversations/{id}/attachments
     * Get all attachments for a conversation
     */
    public function getAttachments(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $category = $request->getQuery('category'); // 'image', 'video', 'document', etc.
        
        $result = $this->chatService->getConversationAttachments($conversationId, $this->userEmail, $category);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'attachments' => $result['attachments'],
                'grouped' => $result['grouped'],
                'counts' => $result['counts']
            ]
        ]);
    }
    
    /**
     * POST /chat/conversations/{id}/attachments/save-to-drive
     * Save conversation attachments to Drive
     */
    public function saveAttachmentsToDrive(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $data = $request->input();
        
        // Optional: specific attachment IDs or message ID to save
        $attachmentIds = $data['attachment_ids'] ?? null;
        $messageId = $data['message_id'] ?? null;
        
        $result = $this->chatService->saveAttachmentsToDrive($conversationId, $this->userEmail, $attachmentIds, $messageId);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'saved_count' => $result['saved_count'],
                'total' => $result['total'],
                'folder_path' => $result['folder_path'],
                'folder_id' => $result['folder_id'],
                'errors' => $result['errors'] ?? []
            ]
        ]);
    }
    
    /**
     * GET /chat/conversations/{id}/settings
     * Get conversation settings (background, etc.)
     */
    public function getSettings(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $result = $this->chatService->getConversationSettings($conversationId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => $result['settings']
        ]);
    }
    
    /**
     * PUT /chat/conversations/{id}/settings
     * Update conversation settings (broadcasts to all participants)
     */
    public function updateSettings(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $settings = $request->input();
        
        $result = $this->chatService->updateConversationSettings($conversationId, $this->userEmail, $settings);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => $result['settings']
        ]);
    }
    
    /**
     * GET /chat/attachments/{conversationId}/{filename}
     * Serve a chat attachment file (requires authentication + conversation membership)
     */
    public function serveAttachment(Request $request): Response
    {
        // Try normal header-based auth first.
        // Fallback: accept ?token= query param for browser-loaded resources (<img>, <audio>)
        // that cannot send Authorization headers.
        $authError = $this->requireChatAuth($request);
        if ($authError) {
            $queryToken = $request->getQuery('token');
            if ($queryToken) {
                $payload = $this->session->validateToken($queryToken);
                if ($payload && ($payload['type'] ?? '') === 'access') {
                    $this->userEmail = $payload['sub'] ?? null;
                    $authError = null;
                    // Re-check chat service availability
                    if (!$this->getChatService()) {
                        return Response::json(['error' => 'Chat service unavailable'], 503);
                    }
                }
            }
            if ($authError) return $authError;
        }
        
        $conversationId = (int)$request->getParam('conversationId');
        $filename = $request->getParam('filename');
        
        if (!$conversationId || !$filename) {
            return Response::json(['success' => false, 'error' => 'Invalid parameters'], 400);
        }
        
        // Verify the authenticated user is a participant in this conversation
        $colleague = $this->chatService->getColleagueByEmail($this->userEmail);
        if (!$colleague) {
            return Response::json(['success' => false, 'error' => 'Access denied'], 403);
        }
        
        $db = $this->chatService->getDb();
        $stmt = $db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return Response::json(['success' => false, 'error' => 'Access denied'], 403);
        }
        
        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        
        $baseDir = $this->chatService->getChatAttachmentsBaseDir($this->userEmail);
        $filepath = $baseDir . '/chat_attachments/' . $conversationId . '/' . $filename;
        
        if (!file_exists($filepath)) {
            return Response::json(['success' => false, 'error' => 'File not found'], 404);
        }
        
        // Determine MIME type
        $mimeType = mime_content_type($filepath) ?: 'application/octet-stream';
        
        // Get file size
        $fileSize = filesize($filepath);
        
        // Set headers for file download/preview
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: private, max-age=3600'); // Private cache, 1 hour max
        header('Accept-Ranges: bytes');
        
        // For images, allow inline display; use safeContentDisposition to prevent header injection
        $disposition = str_starts_with($mimeType, 'image/') ? 'inline' : 'attachment';
        header($this->safeContentDisposition($disposition, $filename));
        
        set_time_limit(15);
        readfile($filepath);
        exit;
    }
    
    // ========================================
    // VIEW TOGETHER ENDPOINTS
    // ========================================
    
    /**
     * POST /chat/conversations/{id}/view-session
     * Start a view together session
     */
    public function startViewSession(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $data = $request->input();
        
        $contentType = $data['content_type'] ?? '';
        $contentId = $data['content_id'] ?? '';
        
        if (!$contentType || !$contentId) {
            return Response::json(['success' => false, 'error' => 'Content type and ID required'], 400);
        }
        
        $result = $this->chatService->startViewSession($conversationId, $this->userEmail, $contentType, $contentId);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => $result['session']
        ]);
    }
    
    /**
     * DELETE /chat/conversations/{id}/view-session
     * End a view together session
     */
    public function endViewSession(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->endViewSession($conversationId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * PUT /chat/conversations/{id}/view-session/sync
     * Sync position during a view together session
     */
    public function syncViewPosition(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $position = $request->input();
        
        if (empty($position)) {
            return Response::json(['success' => false, 'error' => 'Position data required'], 400);
        }
        
        $result = $this->chatService->syncViewPosition($conversationId, $this->userEmail, $position);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    // ========================================
    // GROUP CHAT ENDPOINTS
    // ========================================
    
    /**
     * POST /chat/groups
     * Create a new group chat
     */
    public function createGroup(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $body = $request->input();
        $name = trim($body['name'] ?? '');
        $description = $body['description'] ?? null;
        $memberIds = $body['member_ids'] ?? [];
        
        if (empty($name)) {
            return Response::json(['success' => false, 'error' => 'Group name is required'], 400);
        }
        
        if (!is_array($memberIds) || count($memberIds) < 1) {
            return Response::json(['success' => false, 'error' => 'At least one member is required'], 400);
        }
        
        $result = $this->chatService->createGroup($this->userEmail, $memberIds, $name, $description);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['conversation' => $result['conversation']]
        ]);
    }
    
    /**
     * POST /chat/groups/from-colleague-group
     * Create group chat from an existing colleague group
     */
    public function createGroupFromColleagueGroup(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $body = $request->input();
        $groupId = (int)($body['group_id'] ?? 0);
        $customName = $body['name'] ?? null;
        
        if ($groupId <= 0) {
            return Response::json(['success' => false, 'error' => 'Group ID is required'], 400);
        }
        
        $result = $this->chatService->createGroupFromColleagueGroup($this->userEmail, $groupId, $customName);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['conversation' => $result['conversation']]
        ]);
    }
    
    /**
     * GET /chat/groups/{id}/members
     * Get group members
     */
    public function getGroupMembers(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        
        $result = $this->chatService->getGroupMembers($conversationId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['members' => $result['members']]
        ]);
    }
    
    /**
     * POST /chat/groups/{id}/members
     * Add members to a group
     */
    public function addGroupMembers(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $body = $request->input();
        $memberIds = $body['member_ids'] ?? [];
        
        if (!is_array($memberIds) || empty($memberIds)) {
            return Response::json(['success' => false, 'error' => 'Member IDs required'], 400);
        }
        
        $result = $this->chatService->addGroupMembers($conversationId, $this->userEmail, $memberIds);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['added_count' => $result['added_count']]
        ]);
    }
    
    /**
     * DELETE /chat/groups/{id}/members/{memberId}
     * Remove a member from a group
     */
    public function removeGroupMember(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $memberId = (int)$request->getParam('memberId');
        
        $result = $this->chatService->removeGroupMember($conversationId, $this->userEmail, $memberId);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * DELETE /chat/groups/{id}/members
     * Batched remove of multiple group members in one request.
     * Body: { member_ids: int[] }
     */
    public function removeGroupMembersBatch(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $conversationId = (int)$request->getParam('id');
        $memberIds = (array)$request->input('member_ids', []);
        if (empty($memberIds)) {
            return Response::json(['success' => false, 'error' => 'member_ids array required'], 400);
        }
        if (count($memberIds) > 200) {
            $memberIds = array_slice($memberIds, 0, 200);
        }

        $result = $this->chatService->removeGroupMembersBatch($conversationId, $this->userEmail, $memberIds);
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => ['removed' => $result['removed'] ?? 0],
        ]);
    }

    /**
     * PATCH /chat/groups/{id}
     * Update group info
     */
    public function updateGroup(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $body = $request->input();
        
        $result = $this->chatService->updateGroup($conversationId, $this->userEmail, $body);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * POST /chat/groups/{id}/admins
     * Promote member to admin
     */
    public function setGroupAdmin(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $body = $request->input();
        $memberId = (int)($body['member_id'] ?? 0);
        $isAdmin = (bool)($body['is_admin'] ?? true);
        
        if ($memberId <= 0) {
            return Response::json(['success' => false, 'error' => 'Member ID required'], 400);
        }
        
        $result = $this->chatService->setGroupAdmin($conversationId, $this->userEmail, $memberId, $isAdmin);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * POST /chat/groups/{id}/invite
     * Invite external user to group
     */
    public function inviteToGroup(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $body = $request->input();
        $email = trim($body['email'] ?? '');
        $message = $body['message'] ?? null;
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['success' => false, 'error' => 'Valid email is required'], 400);
        }
        
        $result = $this->chatService->inviteToGroup($conversationId, $this->userEmail, $email, $message);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }
        
        // Send invitation email if we got a token (new external invite)
        $emailSent = false;
        if (!empty($result['token'])) {
            $inviter = [
                'display_name' => explode('@', $this->userEmail)[0],
                'email' => $this->userEmail
            ];
            
            // Try to get a better display name
            try {
                $colleague = $this->chatService->getColleagueByEmail($this->userEmail);
                if ($colleague && !empty($colleague['display_name'])) {
                    $inviter['display_name'] = $colleague['display_name'];
                }
            } catch (\Throwable $e) {
                // Use fallback name
            }
            
            $emailSent = $this->sendGroupInvitationEmail(
                $email,
                $result['token'],
                $inviter,
                $result['group_name'] ?? 'Group Chat',
                $message
            );
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'token' => $result['token'] ?? null,
                'group_name' => $result['group_name'] ?? null,
                'email_sent' => $emailSent,
                'message' => $emailSent 
                    ? "Invitation sent to {$email}" 
                    : (!empty($result['token']) ? 'Invitation created but email could not be sent' : 'Member added to group')
            ]
        ]);
    }
    
    /**
     * Send group invitation email from user's own account
     */
    private function sendGroupInvitationEmail(string $inviteeEmail, string $token, array $inviter, string $groupName, ?string $personalMessage = null): bool
    {
        try {
            $smtp = null;
            
            if ($this->isOAuthSession && $this->oauthProvider) {
                $accessToken = null;
                $smtpConfig = null;
                
                if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                    $accessToken = $this->microsoftOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    $smtpConfig = [
                        'host' => 'smtp.office365.com',
                        'port' => 587,
                        'encryption' => 'tls',
                        'auth' => true,
                    ];
                } elseif ($this->oauthProvider === 'google' && $this->googleOAuthService) {
                    $accessToken = $this->googleOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    $smtpConfig = [
                        'host' => 'smtp.gmail.com',
                        'port' => 587,
                        'encryption' => 'tls',
                        'auth' => true,
                    ];
                }
                
                if (!$accessToken) {
                    error_log("ChatController: Cannot send group invite email - failed to get OAuth access token");
                    return false;
                }
                
                $smtp = new SmtpService($smtpConfig);
                $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
            } elseif ($this->userPassword) {
                $smtp = new SmtpService($this->config['smtp']);
                $smtp->setCredentials($this->userEmail, $this->userPassword);
            } else {
                error_log("ChatController: Cannot send group invite email - session expired");
                return false;
            }
            
            $appName = $this->config['app']['name'] ?? 'Email App';
            $baseUrl = $this->config['app']['frontend_url'] ?? $this->config['app']['url'] ?? 'https://flowone.pro';
            $inviteUrl = $baseUrl . '/chat/invite/' . $token;
            $inviterName = $inviter['display_name'] ?? explode('@', $this->userEmail)[0];
            
            $subject = "{$inviterName} invited you to join \"{$groupName}\"";
            $htmlBody = $this->buildGroupInviteEmailHtml($inviterName, $this->userEmail, $groupName, $inviteUrl, $appName, $personalMessage);
            
            $textBody = "{$inviterName} ({$this->userEmail}) has invited you to join the group \"{$groupName}\" on {$appName}.\n\n";
            if ($personalMessage) {
                $textBody .= "Message: {$personalMessage}\n\n";
            }
            $textBody .= "Click here to accept: {$inviteUrl}\n\n";
            $textBody .= "This invitation expires in 7 days.";
            
            $result = $smtp->send([
                'from_name' => $inviterName,
                'to' => [['email' => $inviteeEmail]],
                'subject' => $subject,
                'body_html' => $htmlBody,
                'body_text' => $textBody,
            ]);
            
            if ($result['success'] ?? false) {
                error_log("ChatController: Group invite email sent to {$inviteeEmail} for group {$groupName}");
                return true;
            } else {
                error_log("ChatController: Failed to send group invite email: " . ($result['error'] ?? 'Unknown error'));
                return false;
            }
        } catch (\Throwable $e) {
            error_log("ChatController: Failed to send group invite email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Build HTML email for group chat invitation
     */
    private function buildGroupInviteEmailHtml(string $inviterName, string $inviterEmail, string $groupName, string $inviteUrl, string $appName, ?string $personalMessage = null): string
    {
        $messageHtml = '';
        if ($personalMessage) {
            $escapedMessage = htmlspecialchars($personalMessage, ENT_QUOTES, 'UTF-8');
            $messageHtml = <<<MSG
        <div style="background: #e0e7ff; padding: 16px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #6366f1;">
            <p style="font-size: 13px; color: #64748b; margin: 0 0 4px 0;">Personal message:</p>
            <p style="font-size: 14px; color: #334155; margin: 0; font-style: italic;">&ldquo;{$escapedMessage}&rdquo;</p>
        </div>
MSG;
        }
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; padding: 30px; border-radius: 12px 12px 0 0; text-align: center;">
        <h1 style="margin: 0; font-size: 22px;">You're Invited to a Group Chat</h1>
        <p style="margin: 8px 0 0; font-size: 16px; opacity: 0.9;">{$groupName}</p>
    </div>
    <div style="background: #f8fafc; padding: 30px; border-radius: 0 0 12px 12px;">
        <p style="font-size: 16px; color: #334155;">
            <strong>{$inviterName}</strong> ({$inviterEmail}) has invited you to join the group <strong>&ldquo;{$groupName}&rdquo;</strong> on {$appName}.
        </p>
        {$messageHtml}
        <p style="font-size: 14px; color: #64748b;">
            Click the button below to accept the invitation and join the conversation.
        </p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{$inviteUrl}" style="display: inline-block; background: #6366f1; color: white; padding: 14px 32px; border-radius: 9999px; text-decoration: none; font-weight: 600;">
                Join Group Chat
            </a>
        </div>
        <p style="font-size: 12px; color: #94a3b8; text-align: center;">
            This invitation expires in 7 days.
        </p>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * GET /chat/mentions - Get all messages where current user was mentioned
     */
    public function getMentions(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        try {
            $mentionService = new MentionService($this->config);
            $limit = (int)($request->getQuery('limit') ?: 50);
            $offset = (int)($request->getQuery('offset') ?: 0);
            $result = $mentionService->getMentions($this->userEmail, $limit, $offset);

            if (!$result['success']) {
                return Response::json(['success' => false, 'error' => $result['error']], 400);
            }

            return Response::json(['success' => true, 'data' => ['mentions' => $result['mentions']]]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => 'Failed to fetch mentions'], 500);
        }
    }

    /**
     * GET /chat/mentions/unread - Count of unread mentions
     */
    public function getUnreadMentions(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        try {
            $mentionService = new MentionService($this->config);
            $result = $mentionService->getUnreadMentionCount($this->userEmail);

            return Response::json(['success' => true, 'data' => ['count' => $result['count'] ?? 0]]);
        } catch (\Throwable $e) {
            return Response::json(['success' => true, 'data' => ['count' => 0]]);
        }
    }

    /**
     * GET /chat/messages/{id}/thread - Get thread (parent + replies)
     */
    public function getThread(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $messageId = (int)$request->getParam('id');
        if (!$messageId) {
            return Response::json(['success' => false, 'error' => 'Message ID required'], 400);
        }

        $result = $this->chatService->getThread($messageId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['messages' => $result['messages']]]);
    }

    /**
     * GET /chat/threads - List all active threads the user participates in
     */
    public function getActiveThreads(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $result = $this->chatService->getActiveThreads($this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['threads' => $result['threads']]]);
    }

    /**
     * POST /chat/messages/{id}/bookmark - Toggle bookmark on a message
     */
    public function toggleBookmark(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $messageId = (int)$request->getParam('id');
        if (!$messageId) {
            return Response::json(['success' => false, 'error' => 'Message ID required'], 400);
        }

        $result = $this->chatService->toggleBookmark($messageId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['bookmarked' => $result['bookmarked']]]);
    }

    /**
     * GET /chat/bookmarks - List all bookmarked messages
     */
    public function getBookmarks(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $result = $this->chatService->getBookmarks($this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['bookmarks' => $result['bookmarks']]]);
    }

    /**
     * DELETE /chat/bookmarks/{id} - Remove a bookmark
     */
    public function deleteBookmark(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $bookmarkId = (int)$request->getParam('id');
        if (!$bookmarkId) {
            return Response::json(['success' => false, 'error' => 'Bookmark ID required'], 400);
        }

        $result = $this->chatService->deleteBookmark($bookmarkId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }

    /**
     * GET /chat/scheduled - List user's scheduled messages
     */
    public function getScheduledMessages(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $result = $this->chatService->getScheduledMessages($this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['messages' => $result['messages']]]);
    }

    /**
     * POST /chat/conversations/{id}/schedule - Schedule a message
     */
    public function scheduleMessage(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $conversationId = (int)$request->getParam('id');
        $body = $request->input();
        $content = $body['content'] ?? '';
        $scheduledAt = $body['scheduled_at'] ?? '';

        if (!$conversationId || !$content || !$scheduledAt) {
            return Response::json(['success' => false, 'error' => 'conversation_id, content, and scheduled_at are required'], 400);
        }

        $result = $this->chatService->scheduleMessage($conversationId, $this->userEmail, $content, $scheduledAt);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['scheduled_message' => $result['scheduled_message']]]);
    }

    /**
     * PATCH /chat/scheduled/{id} - Edit scheduled message
     */
    public function updateScheduledMessage(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $id = (int)$request->getParam('id');
        $body = $request->input();

        $result = $this->chatService->updateScheduledMessage($id, $this->userEmail, $body);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }

    /**
     * DELETE /chat/scheduled/{id} - Cancel scheduled message
     */
    public function deleteScheduledMessage(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $id = (int)$request->getParam('id');

        $result = $this->chatService->deleteScheduledMessage($id, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }

    /**
     * GET /chat/link-preview - Fetch link preview for a URL
     */
    public function getLinkPreview(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $url = $request->getQuery('url', '');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return Response::json(['success' => false, 'error' => 'Valid URL required'], 400);
        }

        try {
            $linkPreviewService = new \Webmail\Services\LinkPreviewService($this->config);
            $result = $linkPreviewService->getPreview($url);

            if (!$result['success']) {
                return Response::json(['success' => false, 'error' => $result['error']], 400);
            }

            return Response::json(['success' => true, 'data' => ['preview' => $result['preview']]]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => 'Failed to fetch preview'], 500);
        }
    }

    /**
     * GET /chat/embed/resolve
     * Resolve an embed reference to its current data
     * Query params: type (string), embed_id (int)
     */
    public function resolveEmbed(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;
        
        $type = $request->getQuery('type', '');
        $embedId = (int)$request->getQuery('embed_id', 0);
        
        if (!$type || !$embedId) {
            return Response::json(['success' => false, 'error' => 'Type and embed_id are required'], 400);
        }
        
        $allowedTypes = ['drive_file', 'drive_folder', 'calendar_event', 'board', 'board_card', 'todo', 'collab_doc', 'mood_board'];
        if (!in_array($type, $allowedTypes)) {
            return Response::json(['success' => false, 'error' => 'Invalid embed type'], 400);
        }
        
        $result = $this->chatService->resolveEmbed($type, $embedId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 404);
        }
        
        return Response::json([
            'success' => true,
            'data' => $result['data']
        ]);
    }

    /**
     * GET /chat/shared-drive-ids
     * Returns file/folder IDs that have been shared in chat via embeds
     */
    public function getSharedDriveIds(Request $request): Response
    {
        if ($error = $this->requireChatAuth($request)) return $error;

        $result = $this->chatService->getSharedDriveIds($this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => [
                'file_ids' => $result['file_ids'],
                'folder_ids' => $result['folder_ids'],
            ]
        ]);
    }
}

