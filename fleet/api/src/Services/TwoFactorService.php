<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Two-Factor Authentication Service
 * 
 * Handles TOTP generation/verification, backup codes, and trusted devices
 */
class TwoFactorService
{
    private Container $container;
    private \PDO $db;
    private string $issuer = 'Fleet Manager';
    
    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
    }
    
    // ============================================
    // TOTP METHODS
    // ============================================
    
    /**
     * Generate a new TOTP secret
     */
    public function generateSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }
    
    /**
     * Generate QR code URL for authenticator apps
     */
    public function getQRCodeUrl(string $username, string $secret): string
    {
        $label = urlencode($this->issuer . ':' . $username);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $this->issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
        
        $otpauth = "otpauth://totp/{$label}?{$params}";
        
        // Use QR Server API (free, no API key needed)
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($otpauth);
    }
    
    /**
     * Verify a TOTP code
     */
    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }
        
        $timestamp = time();
        
        // Check current and adjacent time windows
        for ($i = -$window; $i <= $window; $i++) {
            $calculatedCode = $this->generateTOTP($secret, $timestamp + ($i * 30));
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate TOTP code for a given timestamp
     */
    private function generateTOTP(string $secret, int $timestamp): string
    {
        $counter = floor($timestamp / 30);
        $binary = pack('N*', 0) . pack('N*', $counter);
        
        $key = $this->base32Decode($secret);
        $hash = hash_hmac('sha1', $binary, $key, true);
        
        $offset = ord($hash[19]) & 0x0f;
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;
        
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Decode base32 string
     */
    private function base32Decode(string $input): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper($input);
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';
        
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            if ($char === '=' || $char === ' ') continue;
            
            $val = strpos($chars, $char);
            if ($val === false) continue;
            
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }
        
        return $result;
    }
    
    // ============================================
    // BACKUP CODES
    // ============================================
    
    /**
     * Generate backup codes
     */
    public function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
        }
        return $codes;
    }
    
    /**
     * Hash backup codes for storage
     */
    public function hashBackupCodes(array $codes): array
    {
        return array_map(function($code) {
            return password_hash(str_replace('-', '', $code), PASSWORD_BCRYPT);
        }, $codes);
    }
    
    /**
     * Verify a backup code
     */
    public function verifyBackupCode(string $code, array $hashedCodes): ?int
    {
        $code = str_replace('-', '', $code);
        
        foreach ($hashedCodes as $index => $hash) {
            if (password_verify($code, $hash)) {
                return $index;
            }
        }
        
        return null;
    }
    
    // ============================================
    // USER 2FA MANAGEMENT
    // ============================================
    
    /**
     * Check if 2FA is enabled for user
     */
    public function isEnabled(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT totp_enabled FROM admin_users WHERE id = ?');
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result && $result['totp_enabled'] == 1;
    }
    
    /**
     * Get user's 2FA settings
     */
    public function getSettings(int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT id, username, totp_secret, totp_enabled, backup_codes FROM admin_users WHERE id = ?');
            $stmt->execute([$userId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result && isset($result['backup_codes']) && $result['backup_codes']) {
                $result['backup_codes'] = json_decode($result['backup_codes'], true) ?: [];
            } else if ($result) {
                $result['backup_codes'] = [];
            }
            
            return $result ?: null;
        } catch (\PDOException $e) {
            // backup_codes column might not exist yet - try without it
            $stmt = $this->db->prepare('SELECT id, username, totp_secret, totp_enabled FROM admin_users WHERE id = ?');
            $stmt->execute([$userId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result) {
                $result['backup_codes'] = [];
            }
            
            return $result ?: null;
        }
    }
    
    /**
     * Start 2FA setup (generate secret but don't enable yet)
     */
    public function startSetup(int $userId, string $username): array
    {
        $secret = $this->generateSecret();
        $backupCodes = $this->generateBackupCodes();
        $hashedCodes = $this->hashBackupCodes($backupCodes);
        
        // Store secret temporarily (not enabled yet)
        $stmt = $this->db->prepare('
            UPDATE admin_users 
            SET totp_secret = ?, backup_codes = ?, totp_enabled = 0 
            WHERE id = ?
        ');
        $stmt->execute([$secret, json_encode($hashedCodes), $userId]);
        
        return [
            'secret' => $secret,
            'qr_code' => $this->getQRCodeUrl($username, $secret),
            'backup_codes' => $backupCodes,
        ];
    }
    
    /**
     * Complete 2FA setup (verify code and enable)
     */
    public function completeSetup(int $userId, string $code): bool
    {
        $settings = $this->getSettings($userId);
        
        if (!$settings || !$settings['totp_secret']) {
            return false;
        }
        
        if (!$this->verifyCode($settings['totp_secret'], $code)) {
            return false;
        }
        
        $stmt = $this->db->prepare('UPDATE admin_users SET totp_enabled = 1 WHERE id = ?');
        $stmt->execute([$userId]);
        
        return true;
    }
    
    /**
     * Verify 2FA for login (TOTP or backup code)
     */
    public function verify(int $userId, string $code): bool
    {
        $settings = $this->getSettings($userId);
        
        if (!$settings || !$settings['totp_enabled']) {
            return true; // 2FA not enabled, allow login
        }
        
        // Try TOTP code first
        if ($this->verifyCode($settings['totp_secret'], $code)) {
            return true;
        }
        
        // Try backup code
        if ($settings['backup_codes']) {
            $index = $this->verifyBackupCode($code, $settings['backup_codes']);
            
            if ($index !== null) {
                // Remove used backup code
                $codes = $settings['backup_codes'];
                unset($codes[$index]);
                $codes = array_values($codes);
                
                $stmt = $this->db->prepare('UPDATE admin_users SET backup_codes = ? WHERE id = ?');
                $stmt->execute([json_encode($codes), $userId]);
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Disable 2FA
     */
    public function disable(int $userId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE admin_users 
            SET totp_enabled = 0, totp_secret = NULL, backup_codes = NULL 
            WHERE id = ?
        ');
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Regenerate backup codes
     */
    public function regenerateBackupCodes(int $userId): ?array
    {
        if (!$this->isEnabled($userId)) {
            return null;
        }
        
        $backupCodes = $this->generateBackupCodes();
        $hashedCodes = $this->hashBackupCodes($backupCodes);
        
        $stmt = $this->db->prepare('UPDATE admin_users SET backup_codes = ? WHERE id = ?');
        $stmt->execute([json_encode($hashedCodes), $userId]);
        
        return $backupCodes;
    }
    
    // ============================================
    // TRUSTED DEVICES
    // ============================================
    
    /**
     * Trust a device for 7 days (skip 2FA on subsequent logins)
     * Returns the device token to store in browser, or empty string on failure
     */
    public function trustDevice(int $userId, string $userAgent, string $ipAddress): string
    {
        try {
            // Generate secure random token
            $deviceToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $deviceToken);
            
            // Parse user agent for device name
            $uaInfo = $this->parseUserAgent($userAgent);
            
            // Expire in 7 days
            $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 60 * 60));
            
            $stmt = $this->db->prepare('
                INSERT INTO trusted_devices 
                (user_id, device_token_hash, device_name, browser, os, user_agent, ip_address, expires_at, last_used_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $userId, 
                $tokenHash, 
                $uaInfo['device_name'], 
                $uaInfo['browser'],
                $uaInfo['os'],
                $userAgent, 
                $ipAddress, 
                $expiresAt
            ]);
            
            return $deviceToken;
        } catch (\PDOException $e) {
            // Table might not exist yet
            return '';
        }
    }
    
    /**
     * Check if a device is trusted (valid token, not expired)
     */
    public function isDeviceTrusted(int $userId, ?string $deviceToken): bool
    {
        if (!$deviceToken) {
            return false;
        }
        
        try {
            $tokenHash = hash('sha256', $deviceToken);
            
            $stmt = $this->db->prepare('
                SELECT id FROM trusted_devices 
                WHERE user_id = ? AND device_token_hash = ? AND expires_at > NOW()
            ');
            $stmt->execute([$userId, $tokenHash]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result) {
                // Update last used timestamp
                $updateStmt = $this->db->prepare('
                    UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?
                ');
                $updateStmt->execute([$result['id']]);
                return true;
            }
            
            return false;
        } catch (\PDOException $e) {
            // Table might not exist yet
            return false;
        }
    }
    
    /**
     * Get all trusted devices for a user
     */
    public function getTrustedDevices(int $userId): array
    {
        try {
            // Clean up expired devices first
            $this->cleanupExpiredDevices($userId);
            
            $stmt = $this->db->prepare('
                SELECT id, device_name, browser, os, ip_address, created_at, expires_at, last_used_at
                FROM trusted_devices 
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY last_used_at DESC
            ');
            $stmt->execute([$userId]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            // Table might not exist yet
            return [];
        }
    }
    
    /**
     * Revoke a specific trusted device
     */
    public function revokeTrustedDevice(int $userId, int $deviceId): bool
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM trusted_devices WHERE user_id = ? AND id = ?');
            $stmt->execute([$userId, $deviceId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    /**
     * Revoke all trusted devices for a user
     */
    public function revokeAllTrustedDevices(int $userId): int
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM trusted_devices WHERE user_id = ?');
            $stmt->execute([$userId]);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return 0;
        }
    }
    
    /**
     * Clean up expired trusted devices
     */
    private function cleanupExpiredDevices(int $userId): void
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM trusted_devices WHERE user_id = ? AND expires_at <= NOW()');
            $stmt->execute([$userId]);
        } catch (\PDOException $e) {
            // Table might not exist yet - ignore
        }
    }
    
    /**
     * Parse user agent to get browser, OS, and device name
     */
    public function parseUserAgent(string $userAgent): array
    {
        $browser = 'Unknown';
        $os = 'Unknown';
        
        // Detect browser
        if (preg_match('/Edg\/(\d+)/', $userAgent)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/Chrome\/(\d+)/', $userAgent) && !preg_match('/Edg\//', $userAgent)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/Firefox\/(\d+)/', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//', $userAgent) && !preg_match('/Chrome\//', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/MSIE|Trident\//', $userAgent)) {
            $browser = 'Internet Explorer';
        }
        
        // Detect OS
        if (preg_match('/Windows NT 10/', $userAgent)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows NT 6\.3/', $userAgent)) {
            $os = 'Windows 8.1';
        } elseif (preg_match('/Windows/', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X (\d+)[._](\d+)/', $userAgent, $m)) {
            $os = 'macOS ' . $m[1] . '.' . $m[2];
        } elseif (preg_match('/Mac OS X/', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/iPhone/', $userAgent)) {
            $os = 'iOS (iPhone)';
        } elseif (preg_match('/iPad/', $userAgent)) {
            $os = 'iOS (iPad)';
        } elseif (preg_match('/Android (\d+)/', $userAgent, $m)) {
            $os = 'Android ' . $m[1];
        } elseif (preg_match('/Android/', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/Linux/', $userAgent)) {
            $os = 'Linux';
        }
        
        return [
            'browser' => $browser,
            'os' => $os,
            'device_name' => "{$browser} on {$os}",
        ];
    }
}

