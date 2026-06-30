<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\SessionTrackingService;

/**
 * Session Management Controller
 * 
 * Handles viewing and managing active sessions
 */
class SessionController extends BaseController
{
    /**
     * Get all active sessions
     * GET /api/sessions
     */
    public function index(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $sessionTracking = $this->container->get(SessionTrackingService::class);
        
        // Get current session token from header or cookie
        $currentToken = $this->getCurrentSessionToken($request);
        
        $sessions = $sessionTracking->getSessions($user->sub, $currentToken);
        
        return Response::success(['sessions' => $sessions]);
    }
    
    /**
     * Revoke a specific session
     * DELETE /api/sessions/{id}
     */
    public function revoke(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $sessionId = $request->getParam('id');
        
        $sessionTracking = $this->container->get(SessionTrackingService::class);
        
        if ($sessionTracking->revokeSession($user->sub, $sessionId)) {
            return Response::success(null, 'Session revoked');
        }
        
        return Response::error('Session not found', 404);
    }
    
    /**
     * Revoke all other sessions (logout everywhere except current)
     * POST /api/sessions/revoke-others
     */
    public function revokeOthers(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $sessionTracking = $this->container->get(SessionTrackingService::class);
        
        $currentToken = $this->getCurrentSessionToken($request);
        $count = $sessionTracking->revokeAllOtherSessions($user->sub, $currentToken);
        
        $this->logAction('sessions.revoke_others', null, $user->username, 'success');
        
        return Response::success(['revoked' => $count], "Revoked {$count} other sessions");
    }
    
    /**
     * Revoke all sessions (logout everywhere)
     * POST /api/sessions/revoke-all
     */
    public function revokeAll(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $sessionTracking = $this->container->get(SessionTrackingService::class);
        $count = $sessionTracking->revokeAllSessions($user->sub);
        
        $this->logAction('sessions.revoke_all', null, $user->username, 'success');
        
        return Response::success(['revoked' => $count], "Revoked all {$count} sessions");
    }
    
    /**
     * Get current session token from request
     */
    private function getCurrentSessionToken(Request $request): ?string
    {
        // Check X-Session-Token header
        $token = $request->getHeader('X-Session-Token');
        if ($token) {
            return $token;
        }
        
        // Check cookie
        return $_COOKIE['session_token'] ?? null;
    }
}

