<?php

namespace VpsAdmin\Api\Services;

use VpsAdmin\Api\Core\Container;
use VpsAdmin\Api\Core\Migration;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Authentication service using JWT with 2FA support
 */
class AuthService
{
    private Container $container;
    private \PDO $db;
    private static bool $migrated = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
        
        // Run security migrations once
        if (!self::$migrated) {
            $this->runMigrations();
            self::$migrated = true;
        }
    }

    /**
     * Run security migrations (login_attempts + trusted_devices tables)
     */
    private function runMigrations(): void
    {
        try {
            $migration = new Migration($this->db);
            $migration->migrateSecurity();
            $this->migrateTrustedDevices();
        } catch (\Exception $e) {
            debug_log('Security migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Create trusted_devices table if it doesn't exist
     */
    private function migrateTrustedDevices(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS trusted_devices (
                    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    token_hash VARCHAR(64) NOT NULL UNIQUE,
                    device_name VARCHAR(100),
                    ip_address VARCHAR(45),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at TIMESTAMP NOT NULL,
                    INDEX idx_user (user_id),
                    INDEX idx_token (token_hash),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            debug_log('Trusted devices migration: ' . $e->getMessage());
        }
    }

    // Rate limiting constants
    private const MAX_IP_ATTEMPTS = 10;        // Max attempts per IP in time window
    private const MAX_USER_ATTEMPTS = 5;       // Max attempts per username in time window
    private const RATE_LIMIT_WINDOW = 900;     // 15 minutes in seconds
    private const LOCKOUT_DURATION = 1800;     // 30 minutes in seconds

    /**
     * Authenticate user and generate tokens (step 1)
     * Returns pending_2fa if 2FA is enabled and device is not trusted
     */
    public function login(string $username, string $password, ?string $totpCode = null, ?string $deviceToken = null): ?array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Check rate limiting
        $rateLimitCheck = $this->checkRateLimit($ip, $username);
        if ($rateLimitCheck !== true) {
            return ['error' => $rateLimitCheck, 'rate_limited' => true];
        }

        $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Log failed attempt
            $this->logLoginAttempt($ip, $username, false);
            return null;
        }

        // Check if account is locked
        if ($this->isAccountLocked($username)) {
            return ['error' => 'Account temporarily locked due to too many failed attempts. Try again later.', 'locked' => true];
        }

        // Check if 2FA is enabled
        if (!empty($user['totp_enabled']) && $user['totp_enabled']) {
            // Check if this device is trusted (skip 2FA)
            $trusted = $deviceToken ? $this->isTrustedDevice($user['id'], $deviceToken) : false;

            if (!$trusted) {
                if (!$totpCode) {
                    // Return pending state - requires 2FA
                    return [
                        'pending_2fa' => true,
                        'user_id' => $user['id'],
                        'temp_token' => $this->generateTempToken($user['id']),
                    ];
                }

                // Verify TOTP code
                if (!$this->verifyTotpCode($user['totp_secret'], $totpCode)) {
                    // Try backup codes
                    if (!$this->useBackupCode($user['id'], $totpCode)) {
                        return null;
                    }
                }
            }
        }

        // Update last login
        $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Log successful login
        $this->logLoginAttempt($ip, $username, true);

        // Generate tokens
        $accessToken = $this->generateToken($user, 'access');
        $refreshToken = $this->generateToken($user, 'refresh');

        // Store session
        $this->createSession($user['id'], $refreshToken);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->container->getConfig('jwt.expiry'),
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'] ?? null,
                'role' => $user['role'] ?? 'user',
                'totp_enabled' => !empty($user['totp_enabled']),
            ],
        ];
    }

    /**
     * Verify 2FA code for pending login
     * If $trustDevice is true, generates and stores a trusted device token
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

        // Verify TOTP code
        if (!$this->verifyTotpCode($user['totp_secret'], $totpCode)) {
            // Try backup codes
            if (!$this->useBackupCode($user['id'], $totpCode)) {
                return null;
            }
        }

        // Update last login
        $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Generate tokens
        $accessToken = $this->generateToken($user, 'access');
        $refreshToken = $this->generateToken($user, 'refresh');

        // Store session
        $this->createSession($user['id'], $refreshToken);

        $result = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->container->getConfig('jwt.expiry'),
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'] ?? null,
                'role' => $user['role'] ?? 'user',
                'totp_enabled' => !empty($user['totp_enabled']),
            ],
        ];

        // Trust this device if requested
        if ($trustDevice) {
            $result['device_token'] = $this->trustDevice($user['id']);
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

        // Check session exists
        $stmt = $this->db->prepare("SELECT * FROM sessions WHERE id = ? AND user_id = ? AND expires_at > NOW()");
        $stmt->execute([hash('sha256', $refreshToken), $payload['sub']]);
        $session = $stmt->fetch();

        if (!$session) {
            return null;
        }

        // Update session activity
        $this->updateSessionActivity(hash('sha256', $refreshToken));

        // Get user
        $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        // Generate new access token
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
            $verification = $this->getVerificationKey();
            
            $payload = JWT::decode($token, new Key($verification['key'], $verification['algorithm']));
            
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

        // Invalidate all sessions
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ?");
        $stmt->execute([$userId]);

        return true;
    }

    // ==========================================
    // 2FA Methods
    // ==========================================

    /**
     * Generate a new TOTP secret for setup
     */
    public function generate2FASecret(int $userId): array
    {
        $secret = $this->generateBase32Secret();
        
        // Store secret temporarily (not enabled yet)
        $stmt = $this->db->prepare("UPDATE admin_users SET totp_secret = ?, totp_enabled = 0 WHERE id = ?");
        $stmt->execute([$secret, $userId]);

        // Get username for QR code
        $stmt = $this->db->prepare("SELECT username FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $issuer = 'VPS Admin';
        $accountName = $user['username'];
        $otpauthUrl = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer)
        );

        return [
            'secret' => $secret,
            'qr_url' => $otpauthUrl,
            'manual_entry' => [
                'secret' => $secret,
                'issuer' => $issuer,
                'account' => $accountName,
            ],
        ];
    }

    /**
     * Enable 2FA after verifying the code
     */
    public function enable2FA(int $userId, string $totpCode): ?array
    {
        $stmt = $this->db->prepare("SELECT totp_secret FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !$user['totp_secret']) {
            return null;
        }

        // Verify the code to ensure setup is correct
        if (!$this->verifyTotpCode($user['totp_secret'], $totpCode)) {
            return null;
        }

        // Generate backup codes
        $backupCodes = $this->generateBackupCodes();
        $hashedCodes = array_map(fn($code) => password_hash($code, PASSWORD_DEFAULT), $backupCodes);

        // Enable 2FA
        $stmt = $this->db->prepare("UPDATE admin_users SET totp_enabled = 1, totp_backup_codes = ? WHERE id = ?");
        $stmt->execute([json_encode($hashedCodes), $userId]);

        return [
            'enabled' => true,
            'backup_codes' => $backupCodes,
        ];
    }

    /**
     * Disable 2FA
     */
    public function disable2FA(int $userId, string $password): bool
    {
        // Verify password first
        $stmt = $this->db->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_backup_codes = NULL WHERE id = ?");
        $stmt->execute([$userId]);

        return true;
    }

    /**
     * Get 2FA status for user
     */
    public function get2FAStatus(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT totp_enabled, totp_backup_codes FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $backupCodesRemaining = 0;
        if (!empty($user['totp_backup_codes'])) {
            $codes = json_decode($user['totp_backup_codes'], true);
            $backupCodesRemaining = is_array($codes) ? count($codes) : 0;
        }

        return [
            'enabled' => !empty($user['totp_enabled']),
            'backup_codes_remaining' => $backupCodesRemaining,
        ];
    }

    /**
     * Regenerate backup codes
     */
    public function regenerateBackupCodes(int $userId, string $password): ?array
    {
        // Verify password
        $stmt = $this->db->prepare("SELECT password_hash, totp_enabled FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        if (!$user['totp_enabled']) {
            return null;
        }

        // Generate new backup codes
        $backupCodes = $this->generateBackupCodes();
        $hashedCodes = array_map(fn($code) => password_hash($code, PASSWORD_DEFAULT), $backupCodes);

        $stmt = $this->db->prepare("UPDATE admin_users SET totp_backup_codes = ? WHERE id = ?");
        $stmt->execute([json_encode($hashedCodes), $userId]);

        return $backupCodes;
    }

    // ==========================================
    // Session Management
    // ==========================================

    /**
     * Get all active sessions for a user
     */
    public function getSessions(int $userId, ?string $currentSessionId = null): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, ip_address, user_agent, location, device_name, created_at, last_activity, expires_at 
             FROM sessions 
             WHERE user_id = ? AND expires_at > NOW() 
             ORDER BY last_activity DESC"
        );
        $stmt->execute([$userId]);
        $sessions = $stmt->fetchAll();

        return array_map(function($session) use ($currentSessionId) {
            return [
                'id' => $session['id'],
                'ip_address' => $session['ip_address'],
                'user_agent' => $session['user_agent'],
                'browser' => $this->parseUserAgent($session['user_agent']),
                'location' => $session['location'],
                'device_name' => $session['device_name'],
                'created_at' => $session['created_at'],
                'last_activity' => $session['last_activity'],
                'expires_at' => $session['expires_at'],
                'is_current' => $currentSessionId === $session['id'],
            ];
        }, $sessions);
    }

    /**
     * Revoke a specific session
     */
    public function revokeSession(int $userId, string $sessionId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ? AND user_id = ?");
        $stmt->execute([$sessionId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke all sessions except current
     */
    public function revokeAllSessions(int $userId, ?string $exceptSessionId = null): int
    {
        if ($exceptSessionId) {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ? AND id != ?");
            $stmt->execute([$userId, $exceptSessionId]);
        } else {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
        return $stmt->rowCount();
    }

    // ==========================================
    // Trusted Device Methods
    // ==========================================

    /**
     * Create a trusted device token (30-day validity)
     */
    public function trustDevice(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser = $this->parseUserAgent($userAgent);
        $deviceName = $browser['browser'] . ' on ' . $browser['os'];

        $stmt = $this->db->prepare(
            "INSERT INTO trusted_devices (user_id, token_hash, device_name, ip_address, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))"
        );
        $stmt->execute([$userId, $tokenHash, $deviceName, $ip]);

        // Cleanup expired tokens for this user
        $this->cleanupExpiredDevices($userId);

        return $token;
    }

    /**
     * Check if a device token is trusted and still valid
     */
    public function isTrustedDevice(int $userId, string $token): bool
    {
        $tokenHash = hash('sha256', $token);

        $stmt = $this->db->prepare(
            "SELECT id FROM trusted_devices
             WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()"
        );
        $stmt->execute([$userId, $tokenHash]);

        return (bool)$stmt->fetch();
    }

    /**
     * Remove expired trusted device tokens
     */
    private function cleanupExpiredDevices(int $userId): void
    {
        try {
            $this->db->prepare("DELETE FROM trusted_devices WHERE user_id = ? AND expires_at <= NOW()")
                      ->execute([$userId]);
        } catch (\Exception $e) {
            debug_log('Cleanup expired devices: ' . $e->getMessage());
        }
    }

    // ==========================================
    // Private Helper Methods
    // ==========================================

    /**
     * Get the JWT signing key and algorithm.
     * Prefers RS256 (asymmetric) if key files exist, falls back to HS256.
     */
    private function getSigningKey(): array
    {
        return $this->resolveKey('signing');
    }

    /**
     * Get the JWT verification key and algorithm.
     */
    private function getVerificationKey(): array
    {
        return $this->resolveKey('verification');
    }

    /**
     * Resolve the JWT key + algorithm for signing or verification.
     *
     * RS256 is preferred. If RS256 is configured but the key file is
     * missing/unreadable, we FAIL CLOSED (throw) when no usable HS256
     * secret is configured - rather than silently falling back to an
     * EMPTY HS256 secret. That silent fallback was a real bug: it both
     * (a) makes every already-issued RS256 token fail verification,
     * producing a random 401 -> logout the moment the public key isn't
     * readable, and (b) would accept tokens forged with an empty key.
     *
     * @param string $purpose 'signing' (private key) | 'verification' (public key)
     * @return array{key:string, algorithm:string}
     */
    private function resolveKey(string $purpose): array
    {
        $algorithm = $this->container->getConfig('jwt.algorithm') ?? 'RS256';

        if ($algorithm === 'RS256') {
            $path = $purpose === 'signing'
                ? $this->container->getConfig('jwt.private_key')
                : $this->container->getConfig('jwt.public_key');

            if ($path && is_readable($path)) {
                $key = file_get_contents($path);
                if ($key !== false && $key !== '') {
                    return ['key' => $key, 'algorithm' => 'RS256'];
                }
            }

            // RS256 requested but the key isn't usable. Only fall back to
            // HS256 if a real secret exists; otherwise fail loudly.
            $secret = (string) ($this->container->getConfig('jwt.secret') ?? '');
            if ($secret === '') {
                $detail = $path
                    ? "key file '{$path}' is missing or unreadable"
                    : 'no key path configured';
                error_log(
                    "[AuthService] RS256 {$purpose} key unavailable ({$detail}) and "
                    . 'jwt.secret is empty - refusing empty-secret HS256 fallback'
                );
                throw new \RuntimeException(
                    "JWT {$purpose} key unavailable: RS256 configured but {$detail}, "
                    . 'and no jwt.secret fallback is set.'
                );
            }
            error_log("[AuthService] RS256 {$purpose} key unavailable - falling back to configured HS256 secret");
            return ['key' => $secret, 'algorithm' => 'HS256'];
        }

        // Explicit HS256 mode.
        $secret = (string) ($this->container->getConfig('jwt.secret') ?? '');
        if ($secret === '') {
            error_log('[AuthService] HS256 configured but jwt.secret is empty');
            throw new \RuntimeException('JWT secret is empty; cannot sign/verify HS256 tokens.');
        }
        return ['key' => $secret, 'algorithm' => 'HS256'];
    }

    /**
     * Generate JWT token
     */
    private function generateToken(array $user, string $type): string
    {
        $signing = $this->getSigningKey();
        
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
            'role' => $user['role'] ?? 'user',
        ];

        return JWT::encode($payload, $signing['key'], $signing['algorithm']);
    }

    /**
     * Generate temporary 2FA token
     */
    private function generateTempToken(int $userId): string
    {
        $signing = $this->getSigningKey();

        $payload = [
            'iss' => $this->container->getConfig('app.url'),
            'sub' => $userId,
            'iat' => time(),
            'exp' => time() + 300, // 5 minutes to enter 2FA code
            'type' => 'temp_2fa',
        ];

        return JWT::encode($payload, $signing['key'], $signing['algorithm']);
    }

    /**
     * Create a session record
     */
    private function createSession(int $userId, string $refreshToken): void
    {
        $sessionId = hash('sha256', $refreshToken);
        $expiry = $this->container->getConfig('jwt.refresh_expiry');
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Parse device info
        $browser = $this->parseUserAgent($userAgent);
        $deviceName = $browser['browser'] . ' on ' . $browser['os'];

        $stmt = $this->db->prepare(
            "INSERT INTO sessions (id, user_id, ip_address, user_agent, device_name, expires_at, last_activity) 
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
             ON DUPLICATE KEY UPDATE 
                expires_at = VALUES(expires_at), 
                last_activity = NOW(),
                device_name = VALUES(device_name)"
        );
        
        $stmt->execute([
            $sessionId,
            $userId,
            $ip,
            $userAgent,
            $deviceName,
            $expiry,
        ]);
    }

    /**
     * Update session last activity
     */
    private function updateSessionActivity(string $sessionId): void
    {
        $stmt = $this->db->prepare("UPDATE sessions SET last_activity = NOW() WHERE id = ?");
        $stmt->execute([$sessionId]);
    }

    /**
     * Generate Base32 secret for TOTP
     */
    private function generateBase32Secret(int $length = 32): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
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
     * Calculate TOTP code for a time slice
     */
    private function getTotpCode(string $secret, int $timeSlice): string
    {
        // Decode base32 secret
        $secretKey = $this->base32Decode($secret);
        
        // Pack time slice into 8 bytes
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        
        // Generate HMAC-SHA1 hash
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        
        // Get offset
        $offset = ord(substr($hash, -1)) & 0x0F;
        
        // Generate 6-digit code
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
     * Generate backup codes
     */
    private function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8 character codes
        }
        return $codes;
    }

    /**
     * Use a backup code
     */
    private function useBackupCode(int $userId, string $code): bool
    {
        $stmt = $this->db->prepare("SELECT totp_backup_codes FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !$user['totp_backup_codes']) {
            return false;
        }

        $hashedCodes = json_decode($user['totp_backup_codes'], true);
        if (!is_array($hashedCodes)) {
            return false;
        }

        foreach ($hashedCodes as $index => $hashedCode) {
            if (password_verify(strtoupper($code), $hashedCode)) {
                // Remove used code
                unset($hashedCodes[$index]);
                $hashedCodes = array_values($hashedCodes);
                
                $stmt = $this->db->prepare("UPDATE admin_users SET totp_backup_codes = ? WHERE id = ?");
                $stmt->execute([json_encode($hashedCodes), $userId]);
                
                return true;
            }
        }

        return false;
    }

    /**
     * Parse user agent string
     */
    private function parseUserAgent(string $userAgent): array
    {
        $browser = 'Unknown';
        $os = 'Unknown';

        // Detect browser
        if (preg_match('/Firefox\//i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Edg\//i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\//i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\//i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/MSIE|Trident/i', $userAgent)) {
            $browser = 'Internet Explorer';
        }

        // Detect OS
        if (preg_match('/Windows NT 10/i', $userAgent)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows NT 6\.[123]/i', $userAgent)) {
            $os = 'Windows 7/8';
        } elseif (preg_match('/Mac OS X/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $os = 'Linux';
        } elseif (preg_match('/iPhone|iPad/i', $userAgent)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/i', $userAgent)) {
            $os = 'Android';
        }

        return [
            'browser' => $browser,
            'os' => $os,
        ];
    }

    // ==========================================
    // Rate Limiting & Account Lockout Methods
    // ==========================================

    /**
     * Check rate limiting for IP and username
     * Returns true if allowed, or error message if blocked
     */
    private function checkRateLimit(string $ip, string $username): bool|string
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW);

        // Check IP rate limit
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_attempts 
             WHERE ip_address = ? AND success = 0 AND attempted_at > ?"
        );
        $stmt->execute([$ip, $cutoff]);
        $ipAttempts = (int)$stmt->fetchColumn();

        if ($ipAttempts >= self::MAX_IP_ATTEMPTS) {
            $remaining = $this->getTimeUntilReset($ip, 'ip');
            return "Too many login attempts from this IP. Try again in {$remaining} minutes.";
        }

        // Check username rate limit (only if username provided)
        if ($username) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM login_attempts 
                 WHERE username = ? AND success = 0 AND attempted_at > ?"
            );
            $stmt->execute([$username, $cutoff]);
            $userAttempts = (int)$stmt->fetchColumn();

            if ($userAttempts >= self::MAX_USER_ATTEMPTS) {
                return "Too many failed attempts for this account. Try again later or reset your password.";
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
            "SELECT COUNT(*) FROM login_attempts 
             WHERE username = ? AND success = 0 AND attempted_at > ?"
        );
        $stmt->execute([$username, $cutoff]);
        $recentFailures = (int)$stmt->fetchColumn();

        return $recentFailures >= self::MAX_USER_ATTEMPTS;
    }

    /**
     * Log a login attempt
     */
    private function logLoginAttempt(string $ip, ?string $username, bool $success): void
    {
        try {
            // Ensure table exists
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

            $stmt = $this->db->prepare(
                "INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)"
            );
            $stmt->execute([$ip, $username, $success ? 1 : 0]);

            // Cleanup old attempts (older than 24 hours)
            $this->db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        } catch (\Exception $e) {
            debug_log("Failed to log login attempt: " . $e->getMessage());
        }
    }

    /**
     * Get time until rate limit resets
     */
    private function getTimeUntilReset(string $ip, string $type): int
    {
        $stmt = $this->db->prepare(
            "SELECT MIN(attempted_at) FROM login_attempts 
             WHERE ip_address = ? AND success = 0 
             AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$ip, self::RATE_LIMIT_WINDOW]);
        $oldest = $stmt->fetchColumn();

        if ($oldest) {
            $oldestTime = strtotime($oldest);
            $resetTime = $oldestTime + self::RATE_LIMIT_WINDOW;
            $remaining = ceil(($resetTime - time()) / 60);
            return max(1, $remaining);
        }

        return 15; // Default
    }

    /**
     * Get failed login count for a user (public for UI)
     */
    public function getFailedAttempts(string $username): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::LOCKOUT_DURATION);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count, MAX(attempted_at) as last_attempt 
             FROM login_attempts 
             WHERE username = ? AND success = 0 AND attempted_at > ?"
        );
        $stmt->execute([$username, $cutoff]);
        $result = $stmt->fetch();

        return [
            'failed_attempts' => (int)$result['count'],
            'max_attempts' => self::MAX_USER_ATTEMPTS,
            'last_attempt' => $result['last_attempt'],
            'locked' => (int)$result['count'] >= self::MAX_USER_ATTEMPTS,
        ];
    }
}
