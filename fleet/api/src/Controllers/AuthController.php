<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\AuthService;

/**
 * Authentication controller
 */
class AuthController extends BaseController
{
    /**
     * Login
     */
    public function login(Request $request): Response
    {
        $username = $request->input('username');
        $password = $request->input('password');
        $totpCode = $request->input('totp_code');
        $deviceToken = $request->input('device_token') ?? $_COOKIE['device_token'] ?? null;
        $trustDevice = (bool)$request->input('trust_device', false);

        if (!$username || !$password) {
            return Response::validationError([
                'username' => 'Username is required',
                'password' => 'Password is required',
            ]);
        }

        $authService = $this->container->get(AuthService::class);
        $result = $authService->login($username, $password, $totpCode, $deviceToken, $trustDevice);

        if (!$result) {
            return Response::error('Invalid credentials', 401);
        }

        if (isset($result['error'])) {
            $code = isset($result['rate_limited']) || isset($result['locked']) ? 429 : 401;
            return Response::error($result['error'], $code);
        }

        if (isset($result['pending_2fa'])) {
            return Response::success([
                'pending_2fa' => true,
                'temp_token' => $result['temp_token'],
            ], 'Two-factor authentication required');
        }

        $this->logAction('auth.login', null, $username, 'success');

        return Response::success($result, 'Login successful');
    }

    /**
     * Verify 2FA
     */
    public function verify2FA(Request $request): Response
    {
        $tempToken = $request->input('temp_token');
        $totpCode = $request->input('totp_code');
        $trustDevice = (bool)$request->input('trust_device', false);

        if (!$tempToken || !$totpCode) {
            return Response::validationError([
                'temp_token' => 'Temp token is required',
                'totp_code' => 'TOTP code is required',
            ]);
        }

        $authService = $this->container->get(AuthService::class);
        $result = $authService->verify2FA($tempToken, $totpCode, $trustDevice);

        if (!$result) {
            return Response::error('Invalid or expired code', 401);
        }

        $this->logAction('auth.2fa_verified', null, $result['user']['username'] ?? 'unknown', 'success');

        return Response::success($result, 'Login successful');
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request): Response
    {
        $refreshToken = $request->input('refresh_token');

        if (!$refreshToken) {
            return Response::validationError(['refresh_token' => 'Refresh token is required']);
        }

        $authService = $this->container->get(AuthService::class);
        $result = $authService->refresh($refreshToken);

        if (!$result) {
            return Response::error('Invalid or expired refresh token', 401);
        }

        return Response::success($result, 'Token refreshed');
    }

    /**
     * Get current user
     */
    public function me(Request $request): Response
    {
        $user = $this->getCurrentUser();

        if (!$user) {
            return Response::error('Not authenticated', 401);
        }

        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id, username, email, role, totp_enabled, last_login, created_at FROM admin_users WHERE id = ?");
        $stmt->execute([$user->sub]);
        $userData = $stmt->fetch();

        return Response::success($userData);
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
            
            $this->logAction('auth.logout', null, $user->username, 'success');
        }

        return Response::success(null, 'Logged out successfully');
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): Response
    {
        $user = $this->getCurrentUser();
        
        $currentPassword = $request->input('current_password');
        $newPassword = $request->input('new_password');

        if (!$currentPassword || !$newPassword) {
            return Response::validationError([
                'current_password' => 'Current password is required',
                'new_password' => 'New password is required',
            ]);
        }

        if (strlen($newPassword) < 8) {
            return Response::validationError(['new_password' => 'Password must be at least 8 characters']);
        }

        $authService = $this->container->get(AuthService::class);
        $success = $authService->changePassword($user->sub, $currentPassword, $newPassword);

        if (!$success) {
            return Response::error('Current password is incorrect', 400);
        }

        $this->logAction('auth.password_change', null, $user->username, 'success');

        return Response::success(null, 'Password changed successfully');
    }
}

