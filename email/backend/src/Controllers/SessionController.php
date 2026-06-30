<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\SessionTrackingService;
use Webmail\Services\TwoFactorService;
use Webmail\Services\AuditLogger;

class SessionController extends BaseController
{
    private SessionTrackingService $sessionService;
    private TwoFactorService $twoFactorService;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->sessionService = new SessionTrackingService($config);
        $this->twoFactorService = new TwoFactorService($config);
    }
    
    /**
     * Get all active sessions
     * GET /sessions
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        // Get current session token from header or request
        $currentSessionToken = $request->getHeader('X-Session-Token');
        
        $sessions = $this->sessionService->getSessions($this->userEmail, $currentSessionToken);
        
        return Response::success([
            'sessions' => $sessions,
        ]);
    }
    
    /**
     * Revoke a specific session
     * DELETE /sessions/{id}
     */
    public function revoke(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $sessionId = (int) $request->param('id');
        
        if (!$sessionId) {
            return Response::error('Session ID is required', 400);
        }
        
        if ($this->sessionService->revokeSession($this->userEmail, $sessionId)) {
            AuditLogger::log('session.revoke', 'medium', 'success', ['session_id' => $sessionId], 'session', 'user', $this->userEmail);
            return Response::success(null, 'Session revoked');
        }
        
        return Response::error('Session not found', 404);
    }
    
    /**
     * Revoke all other sessions (except current)
     * POST /sessions/revoke-others
     */
    public function revokeOthers(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $currentSessionToken = $request->getHeader('X-Session-Token');
        $count = $this->sessionService->revokeAllOtherSessions($this->userEmail, $currentSessionToken);
        
        return Response::success([
            'revoked_count' => $count,
        ], 'Other sessions revoked');
    }
    
    /**
     * Revoke all sessions (sign out everywhere)
     * POST /sessions/revoke-all
     */
    public function revokeAll(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        // Also revoke all trusted devices
        $this->twoFactorService->revokeAllTrustedDevices($this->userEmail);
        
        $count = $this->sessionService->revokeAllSessions($this->userEmail);
        
        AuditLogger::log('session.revoke_all', 'high', 'success', ['revoked_count' => $count], 'session', 'user', $this->userEmail);

        return Response::success([
            'revoked_count' => $count,
        ], 'All sessions revoked. Please log in again.');
    }
    
    /**
     * Update session activity (heartbeat)
     * POST /sessions/heartbeat
     */
    public function heartbeat(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $sessionToken = $request->getHeader('X-Session-Token');
        
        if ($sessionToken) {
            $this->sessionService->updateActivity($this->userEmail, $sessionToken);
        }
        
        return Response::success(null, 'Activity updated');
    }
}

