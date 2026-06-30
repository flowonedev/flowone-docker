<?php

namespace Webmail\Services;

/**
 * RateLimitService - Protects login endpoint from brute-force attacks
 * 
 * Tracks failed login attempts by email and IP:
 * - 5 failed attempts per email within 15 min => lock email for 15 min
 * - 10 failed attempts per IP within 15 min => lock IP for 30 min
 * Returns 429 Too Many Requests with Retry-After header when limits exceeded
 */
class RateLimitService
{
    private \PDO $db;
    
    // Rate limit thresholds
    private const EMAIL_MAX_ATTEMPTS = 5;
    private const EMAIL_WINDOW_SECONDS = 900;   // 15 min
    private const EMAIL_LOCKOUT_SECONDS = 900;  // 15 min
    
    private const IP_MAX_ATTEMPTS = 10;
    private const IP_WINDOW_SECONDS = 900;      // 15 min
    private const IP_LOCKOUT_SECONDS = 1800;    // 30 min
    
    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }
    
    /**
     * Check if a login attempt is allowed for the given email and IP
     * Returns ['allowed' => true] or ['allowed' => false, 'retry_after' => seconds, 'reason' => string]
     */
    public function checkLoginAllowed(string $email, string $ip): array
    {
        // Check email-based lock first
        $emailCheck = $this->checkIdentifier($email, 'email', self::EMAIL_MAX_ATTEMPTS, self::EMAIL_WINDOW_SECONDS);
        if (!$emailCheck['allowed']) {
            return $emailCheck;
        }
        
        // Check IP-based lock
        $ipCheck = $this->checkIdentifier($ip, 'ip', self::IP_MAX_ATTEMPTS, self::IP_WINDOW_SECONDS);
        if (!$ipCheck['allowed']) {
            return $ipCheck;
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Record a failed login attempt for email and IP
     */
    public function recordFailedAttempt(string $email, string $ip): void
    {
        $this->incrementAttempt($email, 'email', self::EMAIL_MAX_ATTEMPTS, self::EMAIL_LOCKOUT_SECONDS, self::EMAIL_WINDOW_SECONDS);
        $this->incrementAttempt($ip, 'ip', self::IP_MAX_ATTEMPTS, self::IP_LOCKOUT_SECONDS, self::IP_WINDOW_SECONDS);
    }
    
    /**
     * Clear failed attempts on successful login (for the email only)
     */
    public function clearAttempts(string $email): void
    {
        $stmt = $this->db->prepare('
            DELETE FROM login_rate_limits 
            WHERE identifier = ? AND identifier_type = ?
        ');
        $stmt->execute([$email, 'email']);
    }
    
    /**
     * Check a single identifier (email or IP) against its limits
     */
    private function checkIdentifier(string $identifier, string $type, int $maxAttempts, int $windowSeconds): array
    {
        // Check for active lockout
        $stmt = $this->db->prepare('
            SELECT locked_until FROM login_rate_limits 
            WHERE identifier = ? AND identifier_type = ? 
            AND locked_until IS NOT NULL AND locked_until > NOW()
            LIMIT 1
        ');
        $stmt->execute([$identifier, $type]);
        $row = $stmt->fetch();
        
        if ($row) {
            $lockedUntil = strtotime($row['locked_until']);
            $retryAfter = max(1, $lockedUntil - time());
            return [
                'allowed' => false,
                'retry_after' => $retryAfter,
                'reason' => $type === 'email' 
                    ? 'Too many failed login attempts for this account. Try again later.'
                    : 'Too many failed login attempts from this address. Try again later.',
            ];
        }
        
        // Check attempt count within the window
        $stmt = $this->db->prepare('
            SELECT attempt_count FROM login_rate_limits 
            WHERE identifier = ? AND identifier_type = ? 
            AND first_attempt_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND locked_until IS NULL
            LIMIT 1
        ');
        $stmt->execute([$identifier, $type, $windowSeconds]);
        $row = $stmt->fetch();
        
        if ($row && $row['attempt_count'] >= $maxAttempts) {
            // Threshold reached but lockout not yet applied (race condition guard)
            return [
                'allowed' => false,
                'retry_after' => $windowSeconds,
                'reason' => $type === 'email'
                    ? 'Too many failed login attempts for this account. Try again later.'
                    : 'Too many failed login attempts from this address. Try again later.',
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Increment the attempt counter for an identifier and apply lockout if threshold reached
     */
    private function incrementAttempt(string $identifier, string $type, int $maxAttempts, int $lockoutSeconds, int $windowSeconds): void
    {
        // Clean old entries outside the window first
        $stmt = $this->db->prepare('
            DELETE FROM login_rate_limits 
            WHERE identifier = ? AND identifier_type = ? 
            AND first_attempt_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND (locked_until IS NULL OR locked_until < NOW())
        ');
        $stmt->execute([$identifier, $type, $windowSeconds]);
        
        // Try to update existing record within window
        $stmt = $this->db->prepare('
            UPDATE login_rate_limits 
            SET attempt_count = attempt_count + 1, 
                last_attempt_at = NOW()
            WHERE identifier = ? AND identifier_type = ? 
            AND first_attempt_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND locked_until IS NULL
        ');
        $stmt->execute([$identifier, $type, $windowSeconds]);
        
        if ($stmt->rowCount() === 0) {
            // No existing record - create new one
            $stmt = $this->db->prepare('
                INSERT INTO login_rate_limits (identifier, identifier_type, attempt_count, first_attempt_at, last_attempt_at)
                VALUES (?, ?, 1, NOW(), NOW())
            ');
            $stmt->execute([$identifier, $type]);
        }
        
        // Check if we need to apply lockout
        $stmt = $this->db->prepare('
            SELECT id, attempt_count FROM login_rate_limits 
            WHERE identifier = ? AND identifier_type = ? 
            AND first_attempt_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND locked_until IS NULL
            LIMIT 1
        ');
        $stmt->execute([$identifier, $type, $windowSeconds]);
        $row = $stmt->fetch();
        
        if ($row && $row['attempt_count'] >= $maxAttempts) {
            $stmt = $this->db->prepare('
                UPDATE login_rate_limits 
                SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) 
                WHERE id = ?
            ');
            $stmt->execute([$lockoutSeconds, $row['id']]);
        }
    }
}

