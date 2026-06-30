<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\CallService;
use Webmail\Addons\Chat\Services\ChatService;
use Webmail\Addons\EmailTracking\Services\TrackingService;
use Webmail\Services\RedisCacheService;

/**
 * CallController - REST API for Voice/Video Call Support
 * 
 * Endpoints:
 * - POST /call/livekit-token   - Generate a LiveKit room token
 * - GET  /call/ice-servers     - Get ICE/TURN credentials (legacy, Coturn)
 * - GET  /call/history/{id}    - Get call history for a conversation
 * - POST /call/history         - Save a call record
 */
class CallController extends BaseController
{
    private ?CallService $callService = null;
    private ?ChatService $chatService = null;
    private ?TrackingService $trackingService = null;
    private ?RedisCacheService $redisCache = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
    }
    
    private function getCallService(): ?CallService
    {
        if (!$this->callService) {
            try {
                $this->callService = new CallService($this->config);
            } catch (\Throwable $e) {
                error_log("CallController: Failed to init CallService: " . $e->getMessage());
            }
        }
        return $this->callService;
    }
    
    private function getChatService(): ?ChatService
    {
        if (!$this->chatService) {
            try {
                $this->chatService = new ChatService($this->config);
            } catch (\Throwable $e) {
                error_log("CallController: Failed to init ChatService: " . $e->getMessage());
            }
        }
        return $this->chatService;
    }
    
    private function getTrackingService(): ?TrackingService
    {
        if (!$this->trackingService) {
            try {
                $this->trackingService = new TrackingService($this->config);
            } catch (\Throwable $e) {
                error_log("CallController: Failed to init TrackingService: " . $e->getMessage());
            }
        }
        return $this->trackingService;
    }
    
    private function getRedisCache(): ?RedisCacheService
    {
        if (!$this->redisCache) {
            try {
                $this->redisCache = new RedisCacheService($this->config);
            } catch (\Throwable $e) {
                error_log("CallController: Failed to init RedisCacheService: " . $e->getMessage());
            }
        }
        return $this->redisCache;
    }
    
    private function requireCallAuth(Request $request): ?Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->getCallService()) {
            return Response::json(['error' => 'Call service unavailable'], 503);
        }
        return null;
    }
    
    /**
     * POST /call/livekit-token
     * Generate a LiveKit room access token for a participant.
     * 
     * Body: { room_name: string, display_name?: string }
     * Returns: { success: true, data: { token: string, ws_url: string } }
     */
    public function getLiveKitToken(Request $request): Response
    {
        if ($error = $this->requireCallAuth($request)) return $error;
        
        $body = $request->input();
        $roomName = $body['room_name'] ?? null;
        
        if (!$roomName) {
            return Response::json(['error' => 'Missing room_name'], 400);
        }
        
        try {
            $displayName = $body['display_name'] ?? '';
            $result = $this->callService->getLiveKitToken($roomName, $this->userEmail, $displayName);
            
            return Response::json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Throwable $e) {
            error_log("CallController: LiveKit token error: " . $e->getMessage());
            return Response::json(['error' => 'Failed to generate token'], 500);
        }
    }
    
    /**
     * GET /call/ice-servers
     * Returns STUN/TURN server configuration with time-limited credentials (legacy)
     */
    public function getIceServers(Request $request): Response
    {
        if ($error = $this->requireCallAuth($request)) return $error;
        
        $result = $this->callService->getTurnCredentials($this->userEmail);
        
        return Response::json([
            'success' => true,
            'data' => $result
        ]);
    }
    
    /**
     * GET /call/history/{conversationId}
     * Returns call history for a conversation
     */
    public function getCallHistory(Request $request): Response
    {
        if ($error = $this->requireCallAuth($request)) return $error;
        
        $conversationId = (int)$request->getParam('id');
        $limit = (int)($request->getQuery('limit') ?? 50);
        $offset = (int)($request->getQuery('offset') ?? 0);
        
        $calls = $this->callService->getCallHistory($conversationId, $limit, $offset);
        
        return Response::json([
            'success' => true,
            'data' => ['calls' => $calls]
        ]);
    }
    
    /**
     * POST /call/history
     * Save a call record (called by frontend when call ends)
     */
    public function saveCallRecord(Request $request): Response
    {
        if ($error = $this->requireCallAuth($request)) return $error;
        
        $body = $request->input();
        
        $required = ['call_id', 'conversation_id', 'call_type', 'status'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return Response::json(['error' => "Missing required field: $field"], 400);
            }
        }
        
        // Get initiator colleague ID
        $initiatorId = $this->callService->getColleagueIdByEmail($this->userEmail);
        if (!$initiatorId) {
            return Response::json(['error' => 'User not found'], 400);
        }
        
        $status = $body['status'];
        $callType = $body['call_type'];
        $conversationId = (int)$body['conversation_id'];
        $durationSeconds = (int)($body['duration_seconds'] ?? 0);
        
        $result = $this->callService->saveCallHistory([
            'call_id' => $body['call_id'],
            'conversation_id' => $conversationId,
            'initiated_by' => $initiatorId,
            'call_type' => $callType,
            'status' => $status,
            'started_at' => $body['started_at'] ?? null,
            'answered_at' => $body['answered_at'] ?? null,
            'ended_at' => $body['ended_at'] ?? null,
            'duration_seconds' => $durationSeconds,
            'participants' => $body['participants'] ?? [],
            'had_screen_share' => (int)($body['had_screen_share'] ?? 0)
        ]);
        
        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 500);
        }
        
        // Insert a system message in the chat for call events
        // Only do this once (the result id > 0 means it was a new insert, not a duplicate update)
        if ($result['id'] > 0) {
            $chatService = $this->getChatService();
            if ($chatService) {
                $callTypeLabel = $callType === 'video' ? 'Video call' : 'Voice call';
                
                if ($status === 'missed') {
                    $content = "[call:missed:{$callType}:{$this->userEmail}]";
                } elseif ($status === 'completed' && $durationSeconds > 0) {
                    $duration = gmdate('H:i:s', $durationSeconds);
                    if ($durationSeconds < 3600) {
                        $duration = gmdate('i:s', $durationSeconds);
                    }
                    $content = "[call:completed:{$callType}:{$duration}:{$this->userEmail}]";
                } elseif ($status === 'cancelled') {
                    $content = "[call:cancelled:{$callType}:{$this->userEmail}]";
                } elseif ($status === 'declined') {
                    // Include rejector email so the chat bubble can show "XXX busy - call rejected"
                    $rejectorEmail = $body['rejected_by'] ?? '';
                    $content = "[call:declined:{$callType}:{$rejectorEmail}:{$this->userEmail}]";
                } else {
                    $content = null; // Don't create system message for other statuses
                }
                
                if ($content) {
                    try {
                        $chatService->sendCallSystemMessage($conversationId, $initiatorId, $content);
                    } catch (\Throwable $e) {
                        error_log("CallController: Failed to send call system message: " . $e->getMessage());
                    }
                }
            }
            
            // Create missed call notifications server-side for all other participants
            // This is more reliable than client-side creation (handles offline users too)
            // Trigger for: 'missed' (caller gave up / timed out), 'declined' (recipient rejected),
            // and 'cancelled' (caller hung up before anyone answered)
            // In all cases the recipient couldn't be reached, so they need to see it
            if (($status === 'missed' || $status === 'declined' || $status === 'cancelled') && !empty($body['participants'])) {
                $trackingService = $this->getTrackingService();
                if ($trackingService) {
                    $callerName = explode('@', $this->userEmail)[0];
                    $callTypeLabel = $callType === 'video' ? 'video call' : 'call';
                    if ($status === 'declined') {
                        $notifMessage = "You declined a {$callTypeLabel} from {$callerName}";
                    } else {
                        // Both 'missed' and 'cancelled' show as missed from the recipient's perspective
                        $notifMessage = "You missed a {$callTypeLabel} from {$callerName}";
                    }
                    
                    $redis = $this->getRedisCache();
                    
                    foreach ($body['participants'] as $participantEmail) {
                        $participantEmail = strtolower(trim($participantEmail));
                        // Skip the caller - they initiated the call, not missed it
                        if ($participantEmail === strtolower($this->userEmail)) continue;
                        
                        try {
                            $notificationId = $trackingService->createNotification(
                                $participantEmail,
                                'missed_call',
                                'Missed Call',
                                $notifMessage,
                                [
                                    'call_id' => $body['call_id'],
                                    'conversation_id' => $conversationId,
                                    'call_type' => $callType,
                                    'caller_email' => $this->userEmail,
                                    'caller_name' => $callerName
                                ]
                            );
                            
                            // Publish notification via WebSocket so the client
                            // gets it instantly without needing to poll
                            if ($notificationId && $redis) {
                                $redis->publishEvent($participantEmail, 'NOTIFICATION_CREATED', [
                                    'id' => $notificationId,
                                    'type' => 'missed_call',
                                    'title' => 'Missed Call',
                                    'message' => $notifMessage,
                                    'data' => [
                                        'call_id' => $body['call_id'],
                                        'conversation_id' => $conversationId,
                                        'call_type' => $callType,
                                        'caller_email' => $this->userEmail,
                                        'caller_name' => $callerName
                                    ],
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'is_read' => false
                                ]);
                            }
                        } catch (\Throwable $e) {
                            error_log("CallController: Failed to create missed call notification for {$participantEmail}: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        return Response::json(['success' => true, 'data' => ['id' => $result['id']]]);
    }
}

