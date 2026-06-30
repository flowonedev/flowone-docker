<?php

namespace Webmail\Services;

class DeviceService
{
    private ?\PDO $db = null;
    private array $config;
    
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
    
    /**
     * Register or update a device on login
     */
    public function registerDevice(
        string $email,
        string $deviceId,
        string $platform = 'web',
        ?string $deviceName = null,
        ?string $os = null,
        ?string $appVersion = null,
        ?string $ipAddress = null
    ): array {
        $email = strtolower($email);
        
        // Check if device already exists
        $stmt = $this->getDb()->prepare('
            SELECT id, status FROM webmail_devices 
            WHERE email = ? AND device_id = ?
        ');
        $stmt->execute([$email, $deviceId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // If device was wiped, don't allow re-registration until unblocked
            if ($existing['status'] === 'blocked') {
                return [
                    'success' => false,
                    'action' => 'blocked',
                    'message' => 'This device has been blocked. Contact your administrator.'
                ];
            }
            
            // Update existing device
            $stmt = $this->getDb()->prepare('
                UPDATE webmail_devices 
                SET device_name = COALESCE(?, device_name),
                    platform = ?,
                    os = COALESCE(?, os),
                    app_version = COALESCE(?, app_version),
                    last_ip = ?,
                    last_seen_at = NOW(),
                    status = CASE WHEN status = "wiped" THEN "active" ELSE status END
                WHERE email = ? AND device_id = ?
            ');
            $stmt->execute([$deviceName, $platform, $os, $appVersion, $ipAddress, $email, $deviceId]);
            
            return [
                'success' => true,
                'device_id' => $deviceId,
                'status' => $existing['status'] === 'wiped' ? 'active' : $existing['status']
            ];
        }
        
        // Insert new device
        $stmt = $this->getDb()->prepare('
            INSERT INTO webmail_devices 
            (email, device_id, device_name, platform, os, app_version, last_ip, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, "active")
        ');
        $stmt->execute([$email, $deviceId, $deviceName, $platform, $os, $appVersion, $ipAddress]);
        
        return [
            'success' => true,
            'device_id' => $deviceId,
            'status' => 'active'
        ];
    }
    
    /**
     * Get all devices for a user
     */
    public function getDevices(string $email): array
    {
        $stmt = $this->getDb()->prepare('
            SELECT id, device_id, device_name, platform, os, app_version, status,
                   last_ip, last_seen_at, wipe_requested_at, wipe_confirmed_at, created_at
            FROM webmail_devices 
            WHERE email = ?
            ORDER BY last_seen_at DESC
        ');
        $stmt->execute([strtolower($email)]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get a single device
     */
    public function getDevice(string $email, int $id): ?array
    {
        $stmt = $this->getDb()->prepare('
            SELECT id, device_id, device_name, platform, os, app_version, status,
                   last_ip, last_seen_at, wipe_requested_at, wipe_confirmed_at, created_at
            FROM webmail_devices 
            WHERE email = ? AND id = ?
        ');
        $stmt->execute([strtolower($email), $id]);
        $device = $stmt->fetch();
        return $device ?: null;
    }
    
    /**
     * Block a device and invalidate all its sessions
     */
    public function blockDevice(string $email, int $deviceId): bool
    {
        $email = strtolower($email);
        
        // Get the device_id string
        $stmt = $this->getDb()->prepare('SELECT device_id FROM webmail_devices WHERE email = ? AND id = ?');
        $stmt->execute([$email, $deviceId]);
        $device = $stmt->fetch();
        if (!$device) return false;
        
        // Update device status
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_devices SET status = "blocked" WHERE email = ? AND id = ?
        ');
        $stmt->execute([$email, $deviceId]);
        
        // Invalidate all sessions for this device
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_sessions SET is_valid = 0 WHERE email = ? AND device_id = ?
        ');
        $stmt->execute([$email, $device['device_id']]);
        
        return true;
    }
    
    /**
     * Unblock a device
     */
    public function unblockDevice(string $email, int $deviceId): bool
    {
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_devices SET status = "active" WHERE email = ? AND id = ?
        ');
        $stmt->execute([strtolower($email), $deviceId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Request remote wipe for a device
     */
    public function requestWipe(string $email, int $deviceId): bool
    {
        $email = strtolower($email);
        
        // Get the device_id string
        $stmt = $this->getDb()->prepare('SELECT device_id FROM webmail_devices WHERE email = ? AND id = ?');
        $stmt->execute([$email, $deviceId]);
        $device = $stmt->fetch();
        if (!$device) return false;
        
        // Set device to wipe_pending
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_devices 
            SET status = "wipe_pending", wipe_requested_at = NOW() 
            WHERE email = ? AND id = ?
        ');
        $stmt->execute([$email, $deviceId]);
        
        // Invalidate all sessions for this device
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_sessions SET is_valid = 0 WHERE email = ? AND device_id = ?
        ');
        $stmt->execute([$email, $device['device_id']]);
        
        return true;
    }
    
    /**
     * Confirm wipe was completed by the device
     */
    public function confirmWipe(string $email, string $deviceIdString): bool
    {
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_devices 
            SET status = "wiped", wipe_confirmed_at = NOW() 
            WHERE email = ? AND device_id = ? AND status = "wipe_pending"
        ');
        $stmt->execute([strtolower($email), $deviceIdString]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Quick status check for a device (used by polling)
     * Returns the action the device should take
     */
    public function getDeviceStatus(string $email, string $deviceIdString): array
    {
        $email = strtolower($email);
        
        $stmt = $this->getDb()->prepare('
            SELECT status, wipe_requested_at FROM webmail_devices 
            WHERE email = ? AND device_id = ?
        ');
        $stmt->execute([$email, $deviceIdString]);
        $device = $stmt->fetch();
        
        if (!$device) {
            return ['status' => 'unknown', 'action' => 'none'];
        }
        
        // Update last_seen
        $updateStmt = $this->getDb()->prepare('
            UPDATE webmail_devices SET last_seen_at = NOW() WHERE email = ? AND device_id = ?
        ');
        $updateStmt->execute([$email, $deviceIdString]);
        
        switch ($device['status']) {
            case 'blocked':
                return ['status' => 'blocked', 'action' => 'logout'];
            case 'wipe_pending':
                return ['status' => 'wipe_pending', 'action' => 'wipe'];
            case 'wiped':
                return ['status' => 'wiped', 'action' => 'logout'];
            default:
                return ['status' => 'active', 'action' => 'none'];
        }
    }
    
    /**
     * Check if a session is valid (stateful check)
     * Returns action the client should take
     */
    public function validateSession(string $email, ?string $sessionToken, ?string $deviceIdString = null): array
    {
        $email = strtolower($email);
        
        // If no session token, allow (backwards compatibility with sessions created before this feature)
        if (!$sessionToken) {
            return ['valid' => true, 'action' => 'none'];
        }
        
        $tokenHash = hash('sha256', $sessionToken);
        
        // Check session validity
        $stmt = $this->getDb()->prepare('
            SELECT id, is_valid, device_id, ip_address FROM webmail_sessions 
            WHERE email = ? AND session_token_hash = ? AND expires_at > NOW()
        ');
        $stmt->execute([$email, $tokenHash]);
        $session = $stmt->fetch();
        
        if (!$session) {
            return ['valid' => false, 'action' => 'logout', 'reason' => 'session_expired'];
        }
        
        if (!$session['is_valid']) {
            return ['valid' => false, 'action' => 'logout', 'reason' => 'session_revoked'];
        }
        
        // Optional IP binding: if enabled via config, reject sessions from different IPs
        // This prevents session hijacking but may cause issues with mobile users who change IPs often
        $enforceIpBinding = $this->config['session']['enforce_ip_binding'] ?? false;
        if ($enforceIpBinding && !empty($session['ip_address'])) {
            $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
            if ($currentIp && $currentIp !== $session['ip_address']) {
                error_log("Session IP mismatch for {$email}: stored={$session['ip_address']}, current={$currentIp}");
                return ['valid' => false, 'action' => 'logout', 'reason' => 'ip_changed'];
            }
        }
        
        // Check device status if device_id is provided
        $checkDeviceId = $deviceIdString ?: $session['device_id'];
        if ($checkDeviceId) {
            $deviceStatus = $this->getDeviceStatus($email, $checkDeviceId);
            if ($deviceStatus['action'] !== 'none') {
                return [
                    'valid' => false,
                    'action' => $deviceStatus['action'],
                    'reason' => 'device_' . $deviceStatus['status']
                ];
            }
        }
        
        // Update last active
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_sessions SET last_active_at = NOW() WHERE id = ?
        ');
        $stmt->execute([$session['id']]);
        
        return ['valid' => true, 'action' => 'none'];
    }
    
    /**
     * Link a session to a device
     */
    public function linkSessionToDevice(string $email, string $sessionToken, string $deviceIdString): void
    {
        $tokenHash = hash('sha256', $sessionToken);
        
        $stmt = $this->getDb()->prepare('
            UPDATE webmail_sessions SET device_id = ? WHERE email = ? AND session_token_hash = ?
        ');
        $stmt->execute([$deviceIdString, strtolower($email), $tokenHash]);
    }
}

