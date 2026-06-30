<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Session Tracking Service
 * 
 * Manages user sessions with device info for security visibility
 */
class SessionTrackingService
{
    private Container $container;
    private \PDO $db;
    private TwoFactorService $twoFactorService;
    
    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
        $this->twoFactorService = new TwoFactorService($container);
    }
    
    /**
     * Create a new session record
     * Returns the session token to be stored alongside the JWT
     */
    public function createSession(int $userId, string $userAgent, string $ipAddress, int $expiresIn = 86400): string
    {
        // Generate session token
        $sessionToken = bin2hex(random_bytes(32));
        
        // Parse user agent
        $uaInfo = $this->twoFactorService->parseUserAgent($userAgent);
        
        // Calculate expiry
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        try {
            // Clean up old sessions first (keep max 10 per user)
            $this->cleanupOldSessions($userId);
            
            // Try inserting with extended columns first
            $stmt = $this->db->prepare('
                INSERT INTO sessions 
                (id, user_id, ip_address, user_agent, device_name, browser, os, expires_at, last_active_at, is_current, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, NOW())
            ');
            $stmt->execute([
                $sessionToken,
                $userId, 
                $ipAddress, 
                $userAgent,
                $uaInfo['device_name'], 
                $uaInfo['browser'], 
                $uaInfo['os'], 
                $expiresAt
            ]);
        } catch (\PDOException $e) {
            // Columns might not exist - try with basic columns only
            $stmt = $this->db->prepare('
                INSERT INTO sessions 
                (id, user_id, ip_address, user_agent, expires_at, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $sessionToken,
                $userId, 
                $ipAddress, 
                $userAgent,
                $expiresAt
            ]);
        }
        
        return $sessionToken;
    }
    
    /**
     * Update last active time for a session
     */
    public function updateActivity(int $userId, string $sessionToken): bool
    {
        if (!$sessionToken) {
            return false;
        }
        
        $stmt = $this->db->prepare('
            UPDATE sessions 
            SET last_active_at = NOW() 
            WHERE user_id = ? AND id = ? AND expires_at > NOW()
        ');
        $stmt->execute([$userId, $sessionToken]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get all active sessions for a user
     */
    public function getSessions(int $userId, ?string $currentSessionToken = null): array
    {
        // Clean up expired sessions first
        $this->cleanupExpiredSessions($userId);
        
        try {
            // Try with extended columns first
            $stmt = $this->db->prepare('
                SELECT id, device_name, browser, os, ip_address, created_at, last_active_at
                FROM sessions 
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY last_active_at DESC, created_at DESC
            ');
            $stmt->execute([$userId]);
            $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            // Columns might not exist - try with basic columns
            try {
                $stmt = $this->db->prepare('
                    SELECT id, ip_address, user_agent, created_at
                    FROM sessions 
                    WHERE user_id = ? AND expires_at > NOW()
                    ORDER BY created_at DESC
                ');
                $stmt->execute([$userId]);
                $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                
                // Parse user agent to fill in missing fields
                foreach ($sessions as &$session) {
                    $uaInfo = $this->twoFactorService->parseUserAgent($session['user_agent'] ?? '');
                    $session['device_name'] = $uaInfo['device_name'];
                    $session['browser'] = $uaInfo['browser'];
                    $session['os'] = $uaInfo['os'];
                    $session['last_active_at'] = $session['created_at'];
                }
            } catch (\PDOException $e2) {
                return [];
            }
        }
        
        // Mark current session
        foreach ($sessions as &$session) {
            $session['is_current'] = ($currentSessionToken && isset($session['id']) && $session['id'] === $currentSessionToken);
            // Don't expose full session ID
            $session['session_id'] = isset($session['id']) ? substr($session['id'], 0, 8) . '...' : '';
            unset($session['id']);
        }
        
        return $sessions;
    }
    
    /**
     * Revoke a specific session by its short ID prefix
     */
    public function revokeSession(int $userId, string $sessionIdPrefix): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE user_id = ? AND id LIKE ?');
        $stmt->execute([$userId, $sessionIdPrefix . '%']);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Revoke a specific session by full ID
     */
    public function revokeSessionById(int $userId, string $sessionId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $sessionId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Revoke all sessions except current
     */
    public function revokeAllOtherSessions(int $userId, ?string $currentSessionToken = null): int
    {
        if ($currentSessionToken) {
            $stmt = $this->db->prepare('
                DELETE FROM sessions 
                WHERE user_id = ? AND id != ?
            ');
            $stmt->execute([$userId, $currentSessionToken]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM sessions WHERE user_id = ?');
            $stmt->execute([$userId]);
        }
        
        return $stmt->rowCount();
    }
    
    /**
     * Revoke all sessions for a user (logout everywhere)
     */
    public function revokeAllSessions(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
    
    /**
     * Check if session is valid
     */
    public function isSessionValid(int $userId, string $sessionToken): bool
    {
        if (!$sessionToken) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare('
                SELECT id FROM sessions 
                WHERE user_id = ? AND id = ? AND expires_at > NOW()
            ');
            $stmt->execute([$userId, $sessionToken]);
            
            return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    /**
     * Clean up expired sessions
     */
    private function cleanupExpiredSessions(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE user_id = ? AND expires_at <= NOW()');
        $stmt->execute([$userId]);
    }
    
    /**
     * Keep only the most recent sessions (max 10 per user)
     */
    private function cleanupOldSessions(int $userId): void
    {
        try {
            // Delete expired first
            $this->cleanupExpiredSessions($userId);
            
            // Count current sessions
            $countStmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM sessions WHERE user_id = ?');
            $countStmt->execute([$userId]);
            $row = $countStmt->fetch(\PDO::FETCH_ASSOC);
            $count = $row['cnt'] ?? 0;
            
            // If more than 10, delete oldest ones
            if ($count >= 10) {
                // Get IDs to keep (newest 9) - try with last_active_at first
                try {
                    $keepStmt = $this->db->prepare('
                        SELECT id FROM sessions 
                        WHERE user_id = ? 
                        ORDER BY last_active_at DESC, created_at DESC
                        LIMIT 9
                    ');
                    $keepStmt->execute([$userId]);
                } catch (\PDOException $e) {
                    // last_active_at might not exist
                    $keepStmt = $this->db->prepare('
                        SELECT id FROM sessions 
                        WHERE user_id = ? 
                        ORDER BY created_at DESC
                        LIMIT 9
                    ');
                    $keepStmt->execute([$userId]);
                }
                $keepIds = $keepStmt->fetchAll(\PDO::FETCH_COLUMN);
                
                if (!empty($keepIds)) {
                    $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
                    $deleteStmt = $this->db->prepare("
                        DELETE FROM sessions 
                        WHERE user_id = ? AND id NOT IN ({$placeholders})
                    ");
                    $deleteStmt->execute(array_merge([$userId], $keepIds));
                }
            }
        } catch (\PDOException $e) {
            // Ignore errors during cleanup
        }
    }
}

