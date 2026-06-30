<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;
use VpsAdmin\Api\Services\AuthService;

class AuthController extends BaseController
{
    /**
     * Login
     */
    public function login(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['username', 'password']);
        if ($validation) return $validation;

        $authService = $this->container->get(AuthService::class);
        
        $result = $authService->login(
            $request->input('username'),
            $request->input('password'),
            $request->input('totp_code'),
            $request->input('device_token')
        );

        if (!$result) {
            $this->logAction('auth.login', $request->input('username'), 'failed', [
                'ip' => $request->getClientIp(),
            ]);
            return Response::unauthorized('Invalid credentials');
        }

        // Check for rate limiting or lockout
        if (!empty($result['rate_limited']) || !empty($result['locked'])) {
            $this->logAction('auth.login', $request->input('username'), 'failed', [
                'ip' => $request->getClientIp(),
                'reason' => $result['error'],
                'blocked' => true,
            ]);
            return Response::error($result['error'], 429);
        }

        // Check if 2FA is required
        if (!empty($result['pending_2fa'])) {
            return Response::success([
                'pending_2fa' => true,
                'temp_token' => $result['temp_token'],
            ], '2FA verification required');
        }

        $this->logAction('auth.login', $result['user']['username'], 'success', [
            'ip' => $request->getClientIp(),
        ]);

        return Response::success($result, 'Login successful');
    }

    /**
     * Verify 2FA code
     */
    public function verify2FA(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['temp_token', 'totp_code']);
        if ($validation) return $validation;

        $authService = $this->container->get(AuthService::class);
        
        $result = $authService->verify2FA(
            $request->input('temp_token'),
            $request->input('totp_code'),
            (bool)$request->input('trust_device', false)
        );

        if (!$result) {
            $this->logAction('auth.2fa_verify', 'unknown', 'failed', [
                'ip' => $request->getClientIp(),
            ]);
            return Response::unauthorized('Invalid 2FA code');
        }

        $this->logAction('auth.2fa_verify', $result['user']['username'], 'success', [
            'ip' => $request->getClientIp(),
        ]);

        return Response::success($result, 'Login successful');
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request): Response
    {
        $refreshToken = $request->input('refresh_token');
        
        if (!$refreshToken) {
            return Response::error('Refresh token is required');
        }

        $authService = $this->container->get(AuthService::class);
        $result = $authService->refresh($refreshToken);

        if (!$result) {
            return Response::unauthorized('Invalid or expired refresh token');
        }

        return Response::success($result);
    }

    /**
     * Get current user
     */
    public function me(Request $request): Response
    {
        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        $twoFaStatus = $authService->get2FAStatus($user->sub);
        
        // Get user details from database for email
        $db = $this->container->getDatabase();
        $stmt = $db->prepare("SELECT email, role, status FROM admin_users WHERE id = ?");
        $stmt->execute([$user->sub]);
        $userDetails = $stmt->fetch();
        
        $response = [
            'id' => $user->sub,
            'username' => $user->username,
            'email' => $userDetails['email'] ?? null,
            'role' => $userDetails['role'] ?? $user->role ?? 'user',
            'status' => $userDetails['status'] ?? 'active',
            'totp_enabled' => $twoFaStatus['enabled'],
        ];
        
        // For regular users, include their assigned sites
        if (($userDetails['role'] ?? 'user') === 'user') {
            $stmt = $db->prepare("SELECT domain FROM user_sites WHERE user_id = ? ORDER BY domain");
            $stmt->execute([$user->sub]);
            $response['allowed_sites'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        return Response::success($response);
    }

    /**
     * Logout
     */
    public function logout(Request $request): Response
    {
        $user = $this->getCurrentUser();
        $token = $request->getBearerToken();

        if ($user && $token) {
            $authService = $this->container->get(AuthService::class);
            $authService->logout($user->sub, $token);
            
            $this->logAction('auth.logout', $user->username, 'success');
        }

        return Response::success(null, 'Logged out successfully');
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['current_password', 'new_password']);
        if ($validation) return $validation;

        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $newPassword = $request->input('new_password');
        
        // Validate password strength
        $passwordErrors = $this->validatePasswordStrength($newPassword);
        if (!empty($passwordErrors)) {
            return Response::validationError(['new_password' => implode(' ', $passwordErrors)]);
        }

        $authService = $this->container->get(AuthService::class);
        
        $result = $authService->changePassword(
            $user->sub,
            $request->input('current_password'),
            $newPassword
        );

        if (!$result) {
            return Response::error('Current password is incorrect');
        }

        $this->logAction('auth.change_password', $user->username, 'success');

        return Response::success(null, 'Password changed successfully. Please login again.');
    }

    // ==========================================
    // 2FA Endpoints
    // ==========================================

    /**
     * Get 2FA status
     */
    public function get2FAStatus(Request $request): Response
    {
        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        $status = $authService->get2FAStatus($user->sub);

        return Response::success($status);
    }

    /**
     * Setup 2FA - generate secret and QR code
     */
    public function setup2FA(Request $request): Response
    {
        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        $setup = $authService->generate2FASecret($user->sub);

        $this->logAction('auth.2fa_setup', $user->username, 'success');

        return Response::success($setup);
    }

    /**
     * Enable 2FA
     */
    public function enable2FA(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['totp_code']);
        if ($validation) return $validation;

        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        $result = $authService->enable2FA($user->sub, $request->input('totp_code'));

        if (!$result) {
            return Response::error('Invalid verification code. Please try again.');
        }

        $this->logAction('auth.2fa_enable', $user->username, 'success');

        return Response::success($result, '2FA has been enabled successfully');
    }

    /**
     * Disable 2FA
     */
    public function disable2FA(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['password']);
        if ($validation) return $validation;

        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        $result = $authService->disable2FA($user->sub, $request->input('password'));

        if (!$result) {
            return Response::error('Invalid password');
        }

        $this->logAction('auth.2fa_disable', $user->username, 'success');

        return Response::success(null, '2FA has been disabled');
    }

    /**
     * Regenerate backup codes
     */
    public function regenerateBackupCodes(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['password']);
        if ($validation) return $validation;

        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        $codes = $authService->regenerateBackupCodes($user->sub, $request->input('password'));

        if (!$codes) {
            return Response::error('Invalid password or 2FA not enabled');
        }

        $this->logAction('auth.2fa_backup_regenerate', $user->username, 'success');

        return Response::success(['backup_codes' => $codes], 'New backup codes generated');
    }

    // ==========================================
    // Session Endpoints
    // ==========================================

    /**
     * Get active sessions
     */
    public function getSessions(Request $request): Response
    {
        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        
        // Get current session ID from refresh token if available
        $currentSessionId = null;
        $refreshToken = $request->input('refresh_token');
        if ($refreshToken) {
            $currentSessionId = hash('sha256', $refreshToken);
        }

        $sessions = $authService->getSessions($user->sub, $currentSessionId);

        return Response::success(['sessions' => $sessions]);
    }

    /**
     * Revoke a session
     */
    public function revokeSession(Request $request, string $sessionId): Response
    {
        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        $result = $authService->revokeSession($user->sub, $sessionId);

        if (!$result) {
            return Response::error('Session not found');
        }

        $this->logAction('auth.session_revoke', $user->username, 'success', [
            'session_id' => substr($sessionId, 0, 16) . '...',
        ]);

        return Response::success(null, 'Session revoked');
    }

    /**
     * Revoke all sessions except current
     */
    public function revokeAllSessions(Request $request): Response
    {
        $user = $this->getCurrentUser();
        
        if (!$user) {
            return Response::unauthorized();
        }

        $authService = $this->container->get(AuthService::class);
        
        // Keep current session
        $refreshToken = $request->input('refresh_token');
        $currentSessionId = $refreshToken ? hash('sha256', $refreshToken) : null;
        
        $count = $authService->revokeAllSessions($user->sub, $currentSessionId);

        $this->logAction('auth.sessions_revoke_all', $user->username, 'success', [
            'revoked_count' => $count,
        ]);

        return Response::success(['revoked' => $count], "{$count} session(s) revoked");
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Validate password strength
     * Requirements: 8+ chars, uppercase, lowercase, number, special char
     */
    private function validatePasswordStrength(string $password): array
    {
        $errors = [];

        if (strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character.';
        }

        return $errors;
    }
}
