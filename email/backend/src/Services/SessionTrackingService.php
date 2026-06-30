<?php

namespace Webmail\Services;

class SessionTrackingService
{
    private ?\PDO $db = null;
    private array $config;
    private ?TwoFactorService $twoFactorService = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Lazy DB connection — only connects when a method actually needs the database.
     * Prevents crashing the entire app during route registration.
     */
    private function getDb(): \PDO
    {
        if ($this->db === null) {
            $this->db = \Webmail\Core\Database::getConnection($this->config);
        }
        return $this->db;
    }
    
    private function getTwoFactorService(): TwoFactorService
    {
        if ($this->twoFactorService === null) {
            $this->twoFactorService = new TwoFactorService($this->config);
        }
        return $this->twoFactorService;
    }
    
    /**
     * Create a new session record
     * Returns the session token to be stored alongside the JWT
     * 
     * @param string|null $encryptedPassword AES-encrypted IMAP password to store server-side
     */
    public function createSession(string $email, string $userAgent, string $ipAddress, int $expiresIn = 43200, ?string $encryptedPassword = null): string
    {
        $email = strtolower($email);
        
        // Generate session token
        $sessionToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $sessionToken);
        
        // Parse user agent
        $uaInfo = $this->getTwoFactorService()->parseUserAgent($userAgent);
        $deviceName = $uaInfo['device_name'];
        $browser = $uaInfo['browser'];
        $os = $uaInfo['os'];
        
        // Calculate expiry
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        // Clean up old sessions first (keep max 10 per user)
        $this->cleanupOldSessions($email);
        
        // Insert new session (including encrypted password if provided)
        $stmt = $this->getDb()->prepare('
            INSERT INTO webmail_sessions 
            (email, session_token_hash, encrypted_password, device_name, browser, os, user_agent, ip_address, is_current, expires_at, last_active_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
        ');
        $stmt->execute([$email, $tokenHash, $encryptedPassword, $deviceName, $browser, $os, $userAgent, $ipAddress, $expiresAt]);
        
        return $sessionToken;
    }
    
    /**
     * Store refresh token hash for a session (for token rotation)
     */
    public function storeRefreshTokenHash(string $email, string $sessionToken, string $refreshToken): void
    {
        $sessionHash = hash('sha256', $sessionToken);
        $refreshHash = hash('sha256', $refreshToken);
        
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_sessions 
            SET refresh_token_hash = ? 
            WHERE email = ? AND session_token_hash = ? AND expires_at > NOW()
        ');
        $stmt->execute([$refreshHash, strtolower($email), $sessionHash]);
    }
    
    /**
     * Validate and rotate a refresh token.
     * Returns the encrypted password if valid, null if invalid.
     * 
     * Multi-tab safe: when a user has the same session open in multiple browser
     * tabs (e.g. duplicated tab), both tabs share the same refresh token. If Tab 1
     * refreshes first, Tab 2 still holds the OLD refresh token. Without a grace
     * period, Tab 2's refresh would be treated as a replay attack and kill the
     * session — logging out BOTH tabs.
     * 
     * Fix: we store the previous refresh token hash with a timestamp. The old
     * token is accepted for up to 2 minutes after rotation. After that, a
     * mismatch is treated as a real replay attack and the session is killed.
     */
    public function rotateRefreshToken(string $email, string $sessionToken, string $oldRefreshToken, string $newRefreshToken): ?string
    {
        $email = strtolower($email);
        $sessionHash = hash('sha256', $sessionToken);
        $oldRefreshHash = hash('sha256', $oldRefreshToken);
        $newRefreshHash = hash('sha256', $newRefreshToken);
        
        // Grace period for multi-tab scenarios (seconds)
        $graceSeconds = 120;
        
        // Look up the session (include previous hash + rotation timestamp)
        $stmt = $this->getDb()->prepare('
            SELECT id, encrypted_password, refresh_token_hash, previous_refresh_token_hash, refresh_rotated_at
            FROM webmail_sessions 
            WHERE email = ? AND session_token_hash = ? AND expires_at > NOW()
        ');
        $stmt->execute([$email, $sessionHash]);
        $session = $stmt->fetch();
        
        if (!$session) {
            return null; // Session not found or expired
        }
        
        // Check if the current refresh token hash matches
        $currentHashMatches = !$session['refresh_token_hash'] 
            || $session['refresh_token_hash'] === $oldRefreshHash;
        
        // Check if the PREVIOUS refresh token hash matches (within grace period)
        $previousHashMatches = false;
        if (!$currentHashMatches 
            && $session['previous_refresh_token_hash'] 
            && $session['refresh_rotated_at']
        ) {
            $rotatedAt = strtotime($session['refresh_rotated_at']);
            $elapsed = time() - $rotatedAt;
            if ($elapsed < $graceSeconds && $session['previous_refresh_token_hash'] === $oldRefreshHash) {
                $previousHashMatches = true;
            }
        }
        
        if (!$currentHashMatches && !$previousHashMatches) {
            // Neither current nor previous hash matches — possible token theft
            error_log("SECURITY: Refresh token replay detected for {$email}, session {$session['id']}. Killing session.");
            $stmt = $this->getDb()->prepare('DELETE FROM webmail_sessions WHERE id = ?');
            $stmt->execute([$session['id']]);
            return null;
        }
        
        // Rotate: move current hash to previous, store new hash + timestamp
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_sessions 
            SET previous_refresh_token_hash = refresh_token_hash,
                refresh_token_hash = ?, 
                refresh_rotated_at = NOW(),
                last_active_at = NOW() 
            WHERE id = ?
        ');
        $stmt->execute([$newRefreshHash, $session['id']]);
        
        return $session['encrypted_password'];
    }
    
    /**
     * Retrieve the encrypted password for a valid session
     * Returns the encrypted password string or null if session not found/expired
     */
    public function getSessionPassword(string $email, string $sessionToken): ?string
    {
        if (!$sessionToken) {
            return null;
        }
        
        $tokenHash = hash('sha256', $sessionToken);
        
        $stmt = $this->getDb()->prepare('
            SELECT encrypted_password FROM webmail_sessions 
            WHERE email = ? AND session_token_hash = ? AND expires_at > NOW()
        ');
        $stmt->execute([strtolower($email), $tokenHash]);
        $row = $stmt->fetch();
        
        return $row ? ($row['encrypted_password'] ?? null) : null;
    }
    
    /**
     * Update last active time for a session
     */
    public function updateActivity(string $email, string $sessionToken): bool
    {
        if (!$sessionToken) {
            return false;
        }
        
        $tokenHash = hash('sha256', $sessionToken);
        
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_sessions 
            SET last_active_at = NOW() 
            WHERE email = ? AND session_token_hash = ? AND expires_at > NOW()
        ');
        $stmt->execute([strtolower($email), $tokenHash]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get all active sessions for a user
     */
    public function getSessions(string $email, ?string $currentSessionToken = null): array
    {
        $email = strtolower($email);
        $currentTokenHash = $currentSessionToken ? hash('sha256', $currentSessionToken) : null;
        
        // Clean up expired sessions first
        $this->cleanupExpiredSessions($email);
        
        $stmt = $this->getDb()->prepare('
            SELECT id, device_name, browser, os, ip_address, location, created_at, last_active_at, session_token_hash
            FROM webmail_sessions 
            WHERE email = ? AND expires_at > NOW()
            ORDER BY last_active_at DESC
        ');
        $stmt->execute([$email]);
        
        $sessions = $stmt->fetchAll();
        
        // Mark current session
        foreach ($sessions as &$session) {
            $session['is_current'] = ($currentTokenHash && $session['session_token_hash'] === $currentTokenHash);
            unset($session['session_token_hash']); // Don't expose hash
        }
        
        return $sessions;
    }
    
    /**
     * Revoke a specific session
     */
    public function revokeSession(string $email, int $sessionId): bool
    {
        $stmt = $this->getDb()->prepare('DELETE FROM webmail_sessions WHERE email = ? AND id = ?');
        $stmt->execute([strtolower($email), $sessionId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Revoke all sessions except current
     */
    public function revokeAllOtherSessions(string $email, ?string $currentSessionToken = null): int
    {
        $email = strtolower($email);
        
        if ($currentSessionToken) {
            $currentTokenHash = hash('sha256', $currentSessionToken);
            $stmt = $this->getDb()->prepare('
                DELETE FROM webmail_sessions 
                WHERE email = ? AND session_token_hash != ?
            ');
            $stmt->execute([$email, $currentTokenHash]);
        } else {
            $stmt = $this->getDb()->prepare('DELETE FROM webmail_sessions WHERE email = ?');
            $stmt->execute([$email]);
        }
        
        return $stmt->rowCount();
    }
    
    /**
     * Revoke all sessions for a user (logout everywhere)
     */
    public function revokeAllSessions(string $email): int
    {
        $stmt = $this->getDb()->prepare('DELETE FROM webmail_sessions WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        return $stmt->rowCount();
    }
    
    /**
     * Check if session is valid
     */
    public function isSessionValid(string $email, string $sessionToken): bool
    {
        if (!$sessionToken) {
            return false;
        }
        
        $tokenHash = hash('sha256', $sessionToken);
        
        $stmt = $this->getDb()->prepare('
            SELECT id FROM webmail_sessions 
            WHERE email = ? AND session_token_hash = ? AND expires_at > NOW()
        ');
        $stmt->execute([strtolower($email), $tokenHash]);
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Clean up expired sessions
     */
    private function cleanupExpiredSessions(string $email): void
    {
        $stmt = $this->getDb()->prepare('DELETE FROM webmail_sessions WHERE email = ? AND expires_at <= NOW()');
        $stmt->execute([strtolower($email)]);
    }
    
    /**
     * Keep only the most recent sessions (max 10 per user)
     */
    private function cleanupOldSessions(string $email): void
    {
        $email = strtolower($email);
        
        // Delete expired first
        $this->cleanupExpiredSessions($email);
        
        // Count current sessions
        $countStmt = $this->getDb()->prepare('SELECT COUNT(*) as cnt FROM webmail_sessions WHERE email = ?');
        $countStmt->execute([$email]);
        $count = $countStmt->fetch()['cnt'] ?? 0;
        
        // If more than 10, delete oldest ones
        if ($count >= 10) {
            $deleteStmt = $this->getDb()->prepare('
                DELETE FROM webmail_sessions 
                WHERE email = ? AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM webmail_sessions 
                        WHERE email = ? 
                        ORDER BY last_active_at DESC 
                        LIMIT 9
                    ) as keep_ids
                )
            ');
            $deleteStmt->execute([$email, $email]);
        }
    }
}

