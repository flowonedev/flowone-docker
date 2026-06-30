<?php

namespace Webmail\Services;

class TwoFactorService
{
    private ?\PDO $db = null;
    private array $config;
    private string $issuer = 'Webmail';
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Lazy DB connection — only connects when first needed,
     * so route registration doesn't crash the entire app if DB is temporarily unavailable
     */
    private function getDb(): \PDO
    {
        if ($this->db === null) {
            $this->db = \Webmail\Core\Database::getConnection($this->config);
        }
        return $this->db;
    }
    
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
    public function getQRCodeUrl(string $email, string $secret): string
    {
        $label = urlencode($this->issuer . ':' . $email);
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
    
    // Database methods
    
    /**
     * Get 2FA settings for user
     */
    public function getSettings(string $email): ?array
    {
        $stmt = $this->getDb()->prepare('SELECT * FROM webmail_2fa WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        $result = $stmt->fetch();
        
        if ($result && $result['backup_codes']) {
            $result['backup_codes'] = json_decode($result['backup_codes'], true);
        }
        
        return $result ?: null;
    }
    
    /**
     * Check if 2FA is enabled for user
     */
    public function isEnabled(string $email): bool
    {
        $settings = $this->getSettings($email);
        return $settings && $settings['enabled'] == 1;
    }
    
    /**
     * Start 2FA setup (generate secret but don't enable yet)
     */
    public function startSetup(string $email): array
    {
        $email = strtolower($email);
        $secret = $this->generateSecret();
        $backupCodes = $this->generateBackupCodes();
        $hashedCodes = $this->hashBackupCodes($backupCodes);
        
        // Upsert - insert or update
        $stmt = $this->getDb()->prepare('
            INSERT INTO webmail_2fa (email, secret, enabled, backup_codes) 
            VALUES (?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE secret = ?, backup_codes = ?, enabled = 0
        ');
        
        $codesJson = json_encode($hashedCodes);
        $stmt->execute([$email, $secret, $codesJson, $secret, $codesJson]);
        
        return [
            'secret' => $secret,
            'qr_code' => $this->getQRCodeUrl($email, $secret),
            'backup_codes' => $backupCodes,
        ];
    }
    
    /**
     * Complete 2FA setup (verify code and enable)
     */
    public function completeSetup(string $email, string $code): bool
    {
        $settings = $this->getSettings($email);
        
        if (!$settings || !$settings['secret']) {
            return false;
        }
        
        if (!$this->verifyCode($settings['secret'], $code)) {
            return false;
        }
        
        $stmt = $this->getDb()->prepare('UPDATE webmail_2fa SET enabled = 1 WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        
        return true;
    }
    
    /**
     * Verify 2FA for login
     */
    public function verify(string $email, string $code): bool
    {
        $settings = $this->getSettings($email);
        
        if (!$settings || !$settings['enabled']) {
            return true; // 2FA not enabled, allow login
        }
        
        // Try TOTP code first
        if ($this->verifyCode($settings['secret'], $code)) {
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
                
                $stmt = $this->getDb()->prepare('UPDATE webmail_2fa SET backup_codes = ? WHERE email = ?');
                $stmt->execute([json_encode($codes), strtolower($email)]);
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Disable 2FA
     */
    public function disable(string $email): bool
    {
        $stmt = $this->getDb()->prepare('UPDATE webmail_2fa SET enabled = 0, secret = NULL, backup_codes = NULL WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Regenerate backup codes
     */
    public function regenerateBackupCodes(string $email): ?array
    {
        if (!$this->isEnabled($email)) {
            return null;
        }
        
        $backupCodes = $this->generateBackupCodes();
        $hashedCodes = $this->hashBackupCodes($backupCodes);
        
        $stmt = $this->getDb()->prepare('UPDATE webmail_2fa SET backup_codes = ? WHERE email = ?');
        $stmt->execute([json_encode($hashedCodes), strtolower($email)]);
        
        return $backupCodes;
    }
    
    // ============================================
    // TRUSTED DEVICE METHODS
    // ============================================
    
    /**
     * Trust a device for 7 days (skip 2FA on subsequent logins)
     * Returns the device token to store in browser
     */
    public function trustDevice(string $email, string $userAgent, string $ipAddress): string
    {
        $email = strtolower($email);
        
        // Generate secure random token
        $deviceToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $deviceToken);
        
        // Parse user agent for device name
        $deviceName = $this->parseDeviceName($userAgent);
        
        // Expire in 7 days
        $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 60 * 60));
        
        $stmt = $this->getDb()->prepare('
            INSERT INTO webmail_2fa_trusted_devices 
            (email, device_token_hash, device_name, user_agent, ip_address, expires_at, last_used_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$email, $tokenHash, $deviceName, $userAgent, $ipAddress, $expiresAt]);
        
        // Update trusted device count
        $this->updateTrustedDeviceCount($email);
        
        return $deviceToken;
    }
    
    /**
     * Check if a device is trusted (valid token, not expired)
     */
    public function isDeviceTrusted(string $email, ?string $deviceToken): bool
    {
        if (!$deviceToken) {
            return false;
        }
        
        $email = strtolower($email);
        $tokenHash = hash('sha256', $deviceToken);
        
        $stmt = $this->getDb()->prepare('
            SELECT id FROM webmail_2fa_trusted_devices 
            WHERE email = ? AND device_token_hash = ? AND expires_at > NOW()
        ');
        $stmt->execute([$email, $tokenHash]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Update last used timestamp
            $updateStmt = $this->getDb()->prepare('
                UPDATE webmail_2fa_trusted_devices 
                SET last_used_at = NOW() 
                WHERE id = ?
            ');
            $updateStmt->execute([$result['id']]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get all trusted devices for a user
     */
    public function getTrustedDevices(string $email): array
    {
        $email = strtolower($email);
        
        // Clean up expired devices first
        $this->cleanupExpiredDevices($email);
        
        $stmt = $this->getDb()->prepare('
            SELECT id, device_name, user_agent, ip_address, created_at, expires_at, last_used_at
            FROM webmail_2fa_trusted_devices 
            WHERE email = ? AND expires_at > NOW()
            ORDER BY last_used_at DESC
        ');
        $stmt->execute([$email]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Revoke a specific trusted device
     */
    public function revokeTrustedDevice(string $email, int $deviceId): bool
    {
        $email = strtolower($email);
        
        $stmt = $this->getDb()->prepare('
            DELETE FROM webmail_2fa_trusted_devices 
            WHERE email = ? AND id = ?
        ');
        $stmt->execute([$email, $deviceId]);
        
        $this->updateTrustedDeviceCount($email);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Revoke all trusted devices for a user
     */
    public function revokeAllTrustedDevices(string $email): int
    {
        $email = strtolower($email);
        
        $stmt = $this->getDb()->prepare('DELETE FROM webmail_2fa_trusted_devices WHERE email = ?');
        $stmt->execute([$email]);
        
        $this->updateTrustedDeviceCount($email);
        
        return $stmt->rowCount();
    }
    
    /**
     * Clean up expired trusted devices
     */
    private function cleanupExpiredDevices(string $email): void
    {
        $stmt = $this->getDb()->prepare('DELETE FROM webmail_2fa_trusted_devices WHERE email = ? AND expires_at <= NOW()');
        $stmt->execute([strtolower($email)]);
    }
    
    /**
     * Update the trusted device count in webmail_2fa (if column exists)
     */
    private function updateTrustedDeviceCount(string $email): void
    {
        // This is optional - silently skip if column doesn't exist
        try {
            $email = strtolower($email);
            
            $countStmt = $this->getDb()->prepare('
                SELECT COUNT(*) as cnt FROM webmail_2fa_trusted_devices 
                WHERE email = ? AND expires_at > NOW()
            ');
            $countStmt->execute([$email]);
            $count = $countStmt->fetch()['cnt'] ?? 0;
            
            $updateStmt = $this->getDb()->prepare('
                UPDATE webmail_2fa SET trusted_device_count = ? WHERE email = ?
            ');
            $updateStmt->execute([$count, $email]);
        } catch (\Exception $e) {
            // Column might not exist - that's OK, it's optional
        }
    }
    
    /**
     * Parse user agent to get a friendly device name
     */
    private function parseDeviceName(string $userAgent): string
    {
        $browser = 'Unknown Browser';
        $os = 'Unknown OS';
        
        // Detect browser
        if (preg_match('/Edg\//', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\//', $userAgent) && !preg_match('/Edg\//', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\//', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//', $userAgent) && !preg_match('/Chrome\//', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/MSIE|Trident\//', $userAgent)) {
            $browser = 'Internet Explorer';
        }
        
        // Detect OS
        if (preg_match('/Windows NT 10/', $userAgent)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows/', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/iPhone/', $userAgent)) {
            $os = 'iPhone';
        } elseif (preg_match('/iPad/', $userAgent)) {
            $os = 'iPad';
        } elseif (preg_match('/Android/', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/Linux/', $userAgent)) {
            $os = 'Linux';
        }
        
        return "{$browser} on {$os}";
    }
    
    /**
     * Get browser and OS info from user agent (for sessions)
     */
    public function parseUserAgent(string $userAgent): array
    {
        $browser = 'Unknown';
        $os = 'Unknown';
        
        // Detect browser
        if (preg_match('/Edg\/(\d+)/', $userAgent, $m)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/Chrome\/(\d+)/', $userAgent, $m) && !preg_match('/Edg\//', $userAgent)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/Firefox\/(\d+)/', $userAgent, $m)) {
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
        } elseif (preg_match('/Windows NT 6\.2/', $userAgent)) {
            $os = 'Windows 8';
        } elseif (preg_match('/Windows NT 6\.1/', $userAgent)) {
            $os = 'Windows 7';
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

