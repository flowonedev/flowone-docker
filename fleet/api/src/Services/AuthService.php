<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Authentication service using JWT with 2FA support
 */
class AuthService
{
    private Container $container;
    private \PDO $db;

    // Rate limiting constants
    private const MAX_IP_ATTEMPTS = 10;
    private const MAX_USER_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW = 900; // 15 minutes
    private const LOCKOUT_DURATION = 1800; // 30 minutes

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
        $this->ensureLoginAttemptsTable();
    }

    /**
     * Ensure login_attempts table exists
     */
    private function ensureLoginAttemptsTable(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    ip_address VARCHAR(45) NOT NULL,
                    username VARCHAR(255),
                    success TINYINT(1) DEFAULT 0,
                    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ip (ip_address),
                    INDEX idx_username (username),
                    INDEX idx_attempted_at (attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            // Table might already exist
        }
    }

    /**
     * Authenticate user and generate tokens
     * 
     * @param string $username
     * @param string $password
     * @param string|null $totpCode
     * @param string|null $deviceToken - Trusted device token from cookie
     * @param bool $trustDevice - Whether to trust this device
     */
    public function login(string $username, string $password, ?string $totpCode = null, ?string $deviceToken = null, bool $trustDevice = false): ?array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Check rate limiting
        $rateLimitCheck = $this->checkRateLimit($ip, $username);
        if ($rateLimitCheck !== true) {
            return ['error' => $rateLimitCheck, 'rate_limited' => true];
        }

        $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->logLoginAttempt($ip, $username, false);
            return null;
        }

        // Check if account is locked
        if ($this->isAccountLocked($username)) {
            return ['error' => 'Account temporarily locked due to too many failed attempts.', 'locked' => true];
        }

        // Check if 2FA is enabled
        if (!empty($user['totp_enabled']) && $user['totp_enabled']) {
            // Check if device is trusted (skip 2FA)
            $twoFactorService = $this->container->get(TwoFactorService::class);
            $isDeviceTrusted = $twoFactorService->isDeviceTrusted($user['id'], $deviceToken);
            
            if (!$isDeviceTrusted) {
                if (!$totpCode) {
                    return [
                        'pending_2fa' => true,
                        'user_id' => $user['id'],
                        'temp_token' => $this->generateTempToken($user['id']),
                    ];
                }

                if (!$this->verifyTotpCode($user['totp_secret'], $totpCode)) {
                    return null;
                }
            }
        }

        // Update last login
        $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        $this->logLoginAttempt($ip, $username, true);

        // Generate tokens
        $accessToken = $this->generateToken($user, 'access');
        $refreshToken = $this->generateToken($user, 'refresh');

        // Store session with device info
        $sessionToken = $this->createSessionWithDeviceInfo($user['id'], $refreshToken, $userAgent, $ip);

        $result = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'session_token' => $sessionToken,
            'expires_in' => $this->container->getConfig('jwt.expiry'),
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'] ?? null,
                'role' => $user['role'] ?? 'admin',
                'totp_enabled' => !empty($user['totp_enabled']),
            ],
        ];

        // Trust device if requested and 2FA is enabled
        if ($trustDevice && !empty($user['totp_enabled'])) {
            $twoFactorService = $this->container->get(TwoFactorService::class);
            $result['device_token'] = $twoFactorService->trustDevice($user['id'], $userAgent, $ip);
        }

        return $result;
    }

    /**
     * Verify 2FA code for pending login
     * 
     * @param bool $trustDevice - Whether to trust this device for future logins
     */
    public function verify2FA(string $tempToken, string $totpCode, bool $trustDevice = false): ?array
    {
        $payload = $this->validateToken($tempToken);
        
        if (!$payload || ($payload['type'] ?? '') !== 'temp_2fa') {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        // Use TwoFactorService for verification (supports backup codes)
        $twoFactorService = $this->container->get(TwoFactorService::class);
        if (!$twoFactorService->verify($user['id'], $totpCode)) {
            return null;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        $this->logLoginAttempt($ip, $user['username'], true);

        $accessToken = $this->generateToken($user, 'access');
        $refreshToken = $this->generateToken($user, 'refresh');

        $sessionToken = $this->createSessionWithDeviceInfo($user['id'], $refreshToken, $userAgent, $ip);

        $result = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'session_token' => $sessionToken,
            'expires_in' => $this->container->getConfig('jwt.expiry'),
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'] ?? null,
                'role' => $user['role'] ?? 'admin',
                'totp_enabled' => !empty($user['totp_enabled']),
            ],
        ];

        // Trust device if requested
        if ($trustDevice) {
            $result['device_token'] = $twoFactorService->trustDevice($user['id'], $userAgent, $ip);
        }

        return $result;
    }

    /**
     * Refresh access token
     */
    public function refresh(string $refreshToken): ?array
    {
        $payload = $this->validateToken($refreshToken);
        
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM sessions WHERE id = ? AND user_id = ? AND expires_at > NOW()");
        $stmt->execute([hash('sha256', $refreshToken), $payload['sub']]);
        $session = $stmt->fetch();

        if (!$session) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        $accessToken = $this->generateToken($user, 'access');

        return [
            'access_token' => $accessToken,
            'expires_in' => $this->container->getConfig('jwt.expiry'),
        ];
    }

    /**
     * Validate a token and return payload
     */
    public function validateToken(string $token): ?array
    {
        try {
            $secret = $this->container->getConfig('jwt.secret');
            $algorithm = $this->container->getConfig('jwt.algorithm');
            
            $payload = JWT::decode($token, new Key($secret, $algorithm));
            
            return (array)$payload;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Logout - invalidate session
     */
    public function logout(int $userId, string $token): void
    {
        $sessionId = hash('sha256', $token);
        
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ? OR user_id = ?");
        $stmt->execute([$sessionId, $userId]);
    }

    /**
     * Change password
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $stmt = $this->db->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return false;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newHash, $userId]);

        $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ?");
        $stmt->execute([$userId]);

        return true;
    }

    /**
     * Generate JWT token
     */
    private function generateToken(array $user, string $type): string
    {
        $secret = $this->container->getConfig('jwt.secret');
        $algorithm = $this->container->getConfig('jwt.algorithm');
        
        $expiry = $type === 'refresh' 
            ? $this->container->getConfig('jwt.refresh_expiry')
            : $this->container->getConfig('jwt.expiry');

        $payload = [
            'iss' => $this->container->getConfig('app.url'),
            'sub' => $user['id'],
            'iat' => time(),
            'exp' => time() + $expiry,
            'type' => $type,
            'username' => $user['username'],
            'role' => $user['role'] ?? 'admin',
        ];

        return JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * Generate temporary 2FA token
     */
    private function generateTempToken(int $userId): string
    {
        $secret = $this->container->getConfig('jwt.secret');
        $algorithm = $this->container->getConfig('jwt.algorithm');

        $payload = [
            'iss' => $this->container->getConfig('app.url'),
            'sub' => $userId,
            'iat' => time(),
            'exp' => time() + 300,
            'type' => 'temp_2fa',
        ];

        return JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * Create a session record (legacy)
     */
    private function createSession(int $userId, string $refreshToken): void
    {
        $sessionId = hash('sha256', $refreshToken);
        $expiry = $this->container->getConfig('jwt.refresh_expiry');
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $stmt = $this->db->prepare(
            "INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at) 
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)"
        );
        
        $stmt->execute([$sessionId, $userId, $ip, $userAgent, $expiry]);
    }

    /**
     * Create a session record with device info
     * Returns a session token for the client
     */
    private function createSessionWithDeviceInfo(int $userId, string $refreshToken, string $userAgent, string $ip): string
    {
        $sessionToken = bin2hex(random_bytes(32));
        $expiry = $this->container->getConfig('jwt.refresh_expiry');

        // Parse user agent for device info
        $twoFactorService = $this->container->get(TwoFactorService::class);
        $uaInfo = $twoFactorService->parseUserAgent($userAgent);

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO sessions (id, user_id, ip_address, user_agent, device_name, browser, os, expires_at, last_active_at, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW(), NOW())"
            );
            
            $stmt->execute([
                $sessionToken, 
                $userId, 
                $ip, 
                $userAgent,
                $uaInfo['device_name'],
                $uaInfo['browser'],
                $uaInfo['os'],
                $expiry
            ]);
        } catch (\Exception $e) {
            // Fallback to basic session if new columns don't exist yet
            $stmt = $this->db->prepare(
                "INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at) 
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
            );
            $stmt->execute([$sessionToken, $userId, $ip, $userAgent, $expiry]);
        }

        return $sessionToken;
    }

    /**
     * Verify TOTP code
     */
    private function verifyTotpCode(string $secret, string $code, int $window = 1): bool
    {
        $timeSlice = floor(time() / 30);
        
        for ($i = -$window; $i <= $window; $i++) {
            $calcCode = $this->getTotpCode($secret, $timeSlice + $i);
            if (hash_equals($calcCode, $code)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Calculate TOTP code
     */
    private function getTotpCode(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;
        
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Decode Base32 string
     */
    private function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper($b32);
        $output = '';
        $v = 0;
        $vbits = 0;
        
        for ($i = 0; $i < strlen($b32); $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) continue;
            
            $v = ($v << 5) | $pos;
            $vbits += 5;
            
            if ($vbits >= 8) {
                $vbits -= 8;
                $output .= chr(($v >> $vbits) & 0xFF);
            }
        }
        
        return $output;
    }

    /**
     * Check rate limiting
     */
    private function checkRateLimit(string $ip, string $username): bool|string
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > ?"
        );
        $stmt->execute([$ip, $cutoff]);
        $ipAttempts = (int)$stmt->fetchColumn();

        if ($ipAttempts >= self::MAX_IP_ATTEMPTS) {
            return "Too many login attempts from this IP. Try again later.";
        }

        if ($username) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at > ?"
            );
            $stmt->execute([$username, $cutoff]);
            $userAttempts = (int)$stmt->fetchColumn();

            if ($userAttempts >= self::MAX_USER_ATTEMPTS) {
                return "Too many failed attempts for this account.";
            }
        }

        return true;
    }

    /**
     * Check if account is locked
     */
    private function isAccountLocked(string $username): bool
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::LOCKOUT_DURATION);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at > ?"
        );
        $stmt->execute([$username, $cutoff]);
        
        return (int)$stmt->fetchColumn() >= self::MAX_USER_ATTEMPTS;
    }

    /**
     * Log a login attempt
     */
    private function logLoginAttempt(string $ip, ?string $username, bool $success): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)"
            );
            $stmt->execute([$ip, $username, $success ? 1 : 0]);

            // Cleanup old attempts
            $this->db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        } catch (\Exception $e) {
            error_log("Failed to log login attempt: " . $e->getMessage());
        }
    }
}

