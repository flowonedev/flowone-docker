<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\TwoFactorService;

/**
 * Two-Factor Authentication Controller
 * 
 * Handles 2FA setup, verification, and management
 */
class TwoFactorController extends BaseController
{
    /**
     * Get current 2FA status
     * GET /api/2fa/status
     */
    public function status(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $twoFactor = $this->container->get(TwoFactorService::class);
        $settings = $twoFactor->getSettings($user->sub);
        
        $backupCodesCount = 0;
        if ($settings && $settings['backup_codes']) {
            $backupCodesCount = count($settings['backup_codes']);
        }
        
        return Response::success([
            'enabled' => $settings ? (bool)$settings['totp_enabled'] : false,
            'backup_codes_remaining' => $backupCodesCount,
        ]);
    }
    
    /**
     * Start 2FA setup - generates secret and QR code
     * POST /api/2fa/setup
     */
    public function setup(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $twoFactor = $this->container->get(TwoFactorService::class);
        
        // Check if already enabled
        if ($twoFactor->isEnabled($user->sub)) {
            return Response::error('2FA is already enabled. Disable it first to set up again.', 400);
        }
        
        $setupData = $twoFactor->startSetup($user->sub, $user->username);
        
        return Response::success([
            'secret' => $setupData['secret'],
            'qr_code' => $setupData['qr_code'],
            'backup_codes' => $setupData['backup_codes'],
        ], '2FA setup started. Scan QR code and verify with a code.');
    }
    
    /**
     * Complete 2FA setup - verify code and enable
     * POST /api/2fa/verify-setup
     */
    public function verifySetup(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $code = $request->input('code');
        if (!$code) {
            return Response::error('Verification code is required', 422);
        }
        
        $twoFactor = $this->container->get(TwoFactorService::class);
        
        // Get the backup codes before completing setup (they were saved during startSetup)
        $settings = $twoFactor->getSettings($user->sub);
        
        if ($twoFactor->completeSetup($user->sub, $code)) {
            $this->logAction('2fa.enabled', null, $user->username, 'success');
            
            // Generate fresh backup codes to show to user
            $backupCodes = $twoFactor->regenerateBackupCodes($user->sub);
            
            return Response::success([
                'backup_codes' => $backupCodes
            ], '2FA has been enabled successfully!');
        }
        
        return Response::error('Invalid verification code. Please try again.', 400);
    }
    
    /**
     * Disable 2FA
     * POST /api/2fa/disable
     */
    public function disable(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        // Require password confirmation
        $password = $request->input('password');
        if (!$password) {
            return Response::error('Password is required to disable 2FA', 422);
        }
        
        // Verify password
        $db = $this->getDatabase();
        $stmt = $db->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([$user->sub]);
        $userData = $stmt->fetch();
        
        if (!$userData || !password_verify($password, $userData['password_hash'])) {
            return Response::error('Invalid password', 401);
        }
        
        $twoFactor = $this->container->get(TwoFactorService::class);
        
        if ($twoFactor->disable($user->sub)) {
            // Also revoke all trusted devices
            $twoFactor->revokeAllTrustedDevices($user->sub);
            
            $this->logAction('2fa.disabled', null, $user->username, 'success');
            return Response::success(null, '2FA has been disabled.');
        }
        
        return Response::error('Failed to disable 2FA', 500);
    }
    
    /**
     * Regenerate backup codes
     * POST /api/2fa/backup-codes
     */
    public function regenerateBackupCodes(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        // Require password confirmation
        $password = $request->input('password');
        if (!$password) {
            return Response::error('Password is required', 422);
        }
        
        // Verify password
        $db = $this->getDatabase();
        $stmt = $db->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([$user->sub]);
        $userData = $stmt->fetch();
        
        if (!$userData || !password_verify($password, $userData['password_hash'])) {
            return Response::error('Invalid password', 401);
        }
        
        $twoFactor = $this->container->get(TwoFactorService::class);
        $codes = $twoFactor->regenerateBackupCodes($user->sub);
        
        if ($codes) {
            $this->logAction('2fa.backup_codes_regenerated', null, $user->username, 'success');
            return Response::success(['backup_codes' => $codes], 'New backup codes generated');
        }
        
        return Response::error('2FA must be enabled to regenerate backup codes', 400);
    }
    
    /**
     * Get trusted devices
     * GET /api/2fa/trusted-devices
     */
    public function getTrustedDevices(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $twoFactor = $this->container->get(TwoFactorService::class);
        $devices = $twoFactor->getTrustedDevices($user->sub);
        
        return Response::success(['devices' => $devices]);
    }
    
    /**
     * Revoke a trusted device
     * DELETE /api/2fa/trusted-devices/{id}
     */
    public function revokeTrustedDevice(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $deviceId = (int)$request->getParam('id');
        
        $twoFactor = $this->container->get(TwoFactorService::class);
        
        if ($twoFactor->revokeTrustedDevice($user->sub, $deviceId)) {
            return Response::success(null, 'Device removed from trusted list');
        }
        
        return Response::error('Device not found', 404);
    }
    
    /**
     * Revoke all trusted devices
     * DELETE /api/2fa/trusted-devices
     */
    public function revokeAllTrustedDevices(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::unauthorized();
        }
        
        $twoFactor = $this->container->get(TwoFactorService::class);
        $count = $twoFactor->revokeAllTrustedDevices($user->sub);
        
        return Response::success(['revoked' => $count], "Revoked {$count} trusted devices");
    }
}

