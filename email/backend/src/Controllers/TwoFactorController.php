<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\TwoFactorService;
use Webmail\Services\AuditLogger;

class TwoFactorController extends BaseController
{
    private TwoFactorService $twoFactor;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->twoFactor = new TwoFactorService($config);
    }
    
    /**
     * Get 2FA status for current user
     * GET /2fa/status
     */
    public function status(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $enabled = $this->twoFactor->isEnabled($this->userEmail);
        
        return Response::success([
            'enabled' => $enabled,
        ]);
    }
    
    /**
     * Start 2FA setup - generates secret and QR code
     * POST /2fa/setup
     */
    public function setup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        // Check if already enabled
        if ($this->twoFactor->isEnabled($this->userEmail)) {
            return Response::error('Two-factor authentication is already enabled', 400);
        }
        
        $setup = $this->twoFactor->startSetup($this->userEmail);
        
        return Response::success([
            'secret' => $setup['secret'],
            'qr_code' => $setup['qr_code'],
            'backup_codes' => $setup['backup_codes'],
        ], 'Scan the QR code with your authenticator app');
    }
    
    /**
     * Verify and enable 2FA
     * POST /2fa/verify
     */
    public function verify(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $code = $request->input('code');
        
        if (!$code) {
            return Response::error('Verification code is required', 400);
        }
        
        if ($this->twoFactor->completeSetup($this->userEmail, $code)) {
            AuditLogger::config('2fa_enabled', '2fa', null, ['email' => $this->userEmail]);
            return Response::success([
                'enabled' => true,
            ], 'Two-factor authentication enabled successfully');
        }
        
        return Response::error('Invalid verification code', 400);
    }
    
    /**
     * Disable 2FA
     * POST /2fa/disable
     */
    public function disable(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $code = $request->input('code');
        $password = $request->input('password');
        
        if (!$code || !$password) {
            return Response::error('Verification code and password are required', 400);
        }
        
        // Verify password matches current session
        if ($password !== $this->userPassword) {
            return Response::error('Invalid password', 400);
        }
        
        // Verify 2FA code
        if (!$this->twoFactor->verify($this->userEmail, $code)) {
            return Response::error('Invalid verification code', 400);
        }
        
        $this->twoFactor->disable($this->userEmail);
        
        AuditLogger::config('2fa_disabled', '2fa', null, ['email' => $this->userEmail]);

        return Response::success([
            'enabled' => false,
        ], 'Two-factor authentication disabled');
    }
    
    /**
     * Regenerate backup codes
     * POST /2fa/backup-codes
     */
    public function regenerateBackupCodes(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $code = $request->input('code');
        
        if (!$code) {
            return Response::error('Verification code is required', 400);
        }
        
        // Verify 2FA code
        if (!$this->twoFactor->verify($this->userEmail, $code)) {
            return Response::error('Invalid verification code', 400);
        }
        
        $backupCodes = $this->twoFactor->regenerateBackupCodes($this->userEmail);
        
        if (!$backupCodes) {
            return Response::error('Two-factor authentication is not enabled', 400);
        }
        
        return Response::success([
            'backup_codes' => $backupCodes,
        ], 'New backup codes generated');
    }
    
    /**
     * Verify 2FA code during login (called after password verification)
     * POST /2fa/login
     */
    public function loginVerify(Request $request): Response
    {
        $email = $request->input('email');
        $code = $request->input('code');
        $tempToken = $request->input('temp_token');
        $trustDevice = $request->input('trust_device', false);
        
        if (!$email || !$code || !$tempToken) {
            return Response::error('Email, code, and temporary token are required', 400);
        }
        
        // Verify temp token (contains encrypted password for session)
        $tokenData = $this->verifyTempToken($tempToken);
        if (!$tokenData || $tokenData['email'] !== $email) {
            return Response::error('Invalid or expired temporary token', 401);
        }
        
        // Verify 2FA code
        if (!$this->twoFactor->verify($email, $code)) {
            AuditLogger::auth('2fa_verify', 'failed', $email, ['reason' => 'invalid_code']);
            return Response::error('Invalid verification code', 400);
        }
        
        // Get request info for session/device tracking
        $userAgent = $request->getHeader('User-Agent') ?? 'Unknown';
        $ipAddress = $request->getClientIp();
        
        // Generate full auth tokens
        $sessionService = new \Webmail\Services\SessionService($this->config['jwt'], $this->config['imap_encryption_key'] ?? '');
        $tokens = $sessionService->createSession($email, $tokenData['password']);
        
        // Create session tracking record WITH encrypted password stored server-side
        $sessionToken = null;
        try {
            $sessionTracker = new \Webmail\Services\SessionTrackingService($this->config);
            $sessionToken = $sessionTracker->createSession($email, $userAgent, $ipAddress, $this->config['jwt']['expiry'], $tokens['encrypted_password']);
        } catch (\Exception $e) {
            error_log('Session tracking error (2FA login): ' . $e->getMessage());
        }
        
        // If session creation failed, re-create JWT with pwd fallback
        $accessToken = $tokens['access_token'];
        if (!$sessionToken) {
            $accessToken = $sessionService->createToken($email, ['pwd' => $tokens['encrypted_password']]);
        }
        
        $response = [
            'access_token' => $accessToken,
            'refresh_token' => $tokens['refresh_token'],
            'session_token' => $sessionToken,
            'expires_in' => $this->config['jwt']['expiry'],
            'user' => [
                'email' => $email,
                'display_name' => explode('@', $email)[0],
            ],
        ];
        
        // Store refresh token hash for rotation tracking
        if ($sessionToken) {
            try {
                $sessionTracker->storeRefreshTokenHash($email, $sessionToken, $tokens['refresh_token']);
            } catch (\Exception $e) {
                error_log('Refresh token hash storage error (2FA): ' . $e->getMessage());
            }
        }
        
        // Trust this device if requested (skip 2FA for 7 days)
        if ($trustDevice) {
            $deviceToken = $this->twoFactor->trustDevice($email, $userAgent, $ipAddress);
            $response['device_token'] = $deviceToken;
        }
        
        AuditLogger::auth('2fa_login', 'success', $email, ['ip' => $ipAddress, 'trusted_device' => (bool)$trustDevice]);

        return Response::success($response, 'Login successful');
    }
    
    /**
     * Get trusted devices for current user
     * GET /2fa/trusted-devices
     */
    public function getTrustedDevices(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $devices = $this->twoFactor->getTrustedDevices($this->userEmail);
        
        return Response::success([
            'devices' => $devices,
        ]);
    }
    
    /**
     * Revoke a specific trusted device
     * DELETE /2fa/trusted-devices/{id}
     */
    public function revokeTrustedDevice(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $deviceId = (int) $request->param('id');
        
        if (!$deviceId) {
            return Response::error('Device ID is required', 400);
        }
        
        if ($this->twoFactor->revokeTrustedDevice($this->userEmail, $deviceId)) {
            return Response::success(null, 'Device trust revoked');
        }
        
        return Response::error('Device not found', 404);
    }
    
    /**
     * Revoke all trusted devices
     * DELETE /2fa/trusted-devices
     */
    public function revokeAllTrustedDevices(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $count = $this->twoFactor->revokeAllTrustedDevices($this->userEmail);
        
        return Response::success([
            'revoked_count' => $count,
        ], 'All trusted devices revoked');
    }
    
    /**
     * Verify temporary token
     * Uses SessionService.validateToken for dual-algorithm support (RS256 + HS256 fallback)
     */
    private function verifyTempToken(string $token): ?array
    {
        try {
            $sessionService = new \Webmail\Services\SessionService($this->config['jwt'], $this->config['imap_encryption_key'] ?? '');
            $decoded = $sessionService->validateToken($token);

            if (!$decoded || ($decoded['type'] ?? '') !== '2fa_pending') {
                return null;
            }

            return [
                'email' => $decoded['sub'],
                'password' => $sessionService->decryptPassword($decoded['pwd']),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}

