<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\ImapService;
use Webmail\Services\TwoFactorService;
use Webmail\Services\RateLimitService;
use Webmail\Services\RedisCacheService;
use Webmail\Services\AuditLogger;
use Webmail\Services\OAuthStateService;

class AuthController extends BaseController
{
    private string $settingsDir = '/var/www/vps-email/data/settings';

    /**
     * Get the display name from user settings file, or fall back to email prefix
     */
    private function getDisplayName(string $email): string
    {
        $normalizedEmail = strtolower($email);
        $hash = md5($normalizedEmail);
        $file = $this->settingsDir . '/' . $hash . '.json';

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $settings = json_decode($content, true);
            if (is_array($settings) && !empty($settings['display_name'])) {
                return $settings['display_name'];
            }
        }

        return explode('@', $email)[0];
    }

    /**
     * Whether the shared mail_accounts row has the "force password change on
     * next login" flag set. Defensive: any error (missing column / table /
     * connection) is treated as "not forced" so login is never blocked.
     */
    private function getForcePasswordChange(string $email): bool
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare("SELECT force_password_change FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
            $stmt->execute([strtolower($email)]);
            $val = $stmt->fetchColumn();
            return $val !== false && (int) $val === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Login with email and password
     * POST /auth/login
     */
    public function login(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['email', 'password']);
        if ($validation) {
            return $validation;
        }

        $email = trim(strtolower($request->input('email')));
        $password = $request->input('password');
        $deviceToken = $request->input('device_token'); // Trusted device token from browser

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Invalid email format', 400);
        }

        // Rate limiting - check before attempting IMAP connection
        $ipAddress = $request->getClientIp();
        try {
            $rateLimiter = new RateLimitService($this->config);
            $rateCheck = $rateLimiter->checkLoginAllowed($email, $ipAddress);
            if (!$rateCheck['allowed']) {
                $retryAfter = $rateCheck['retry_after'] ?? 900;
                header('Retry-After: ' . $retryAfter);
                AuditLogger::auth('login_rate_limited', 'failed', $email, ['ip' => $ipAddress, 'reason' => $rateCheck['reason']]);
                return Response::json([
                    'success' => false,
                    'message' => $rateCheck['reason'],
                    'retry_after' => $retryAfter,
                ], 429);
            }
        } catch (\Exception $e) {
            // Fail-closed: if rate limit service is unavailable, deny login
            error_log('Rate limit check error: ' . $e->getMessage());
            return Response::error('Service temporarily unavailable. Please try again.', 503);
        }

        // Attempt IMAP connection to validate credentials
        $imap = new ImapService($this->config['imap']);
        
        if (!$imap->connect($email, $password)) {
            // Record the failed attempt
            try {
                if ($rateLimiter) {
                    $rateLimiter->recordFailedAttempt($email, $ipAddress);
                }
            } catch (\Exception $e) {
                error_log('Rate limit record error: ' . $e->getMessage());
            }
            AuditLogger::auth('login', 'failed', $email, ['reason' => 'invalid_credentials', 'ip' => $ipAddress]);
            return Response::error('Invalid email or password', 401);
        }

        // Successful login - clear failed attempts for this email
        try {
            if ($rateLimiter) {
                $rateLimiter->clearAttempts($email);
            }
        } catch (\Exception $e) {
            error_log('Rate limit clear error: ' . $e->getMessage());
        }

        $imap->disconnect();

        // Get request info for session tracking
        $userAgent = $request->getHeader('User-Agent') ?? 'Unknown';
        $ipAddress = $request->getClientIp();

        // Check if 2FA is enabled
        try {
            $twoFactor = new TwoFactorService($this->config);
            if ($twoFactor->isEnabled($email)) {
                // Check if this device is trusted (skip 2FA)
                if ($deviceToken && $twoFactor->isDeviceTrusted($email, $deviceToken)) {
                    // Device is trusted - skip 2FA, proceed to login
                    return $this->completeLogin($email, $password, $userAgent, $ipAddress, $deviceToken);
                }
                
                // Return temp token for 2FA verification
                $tempToken = $this->session->createTempToken($email, $password);
                
                return Response::success([
                    'requires_2fa' => true,
                    'temp_token' => $tempToken,
                    'email' => $email,
                ], 'Two-factor authentication required');
            }
        } catch (\Exception $e) {
            // Fail-closed: if 2FA service is unavailable, deny login
            error_log('2FA service error: ' . $e->getMessage());
            return Response::error('Service temporarily unavailable. Please try again.', 503);
        }

        // No 2FA - complete login
        return $this->completeLogin($email, $password, $userAgent, $ipAddress);
    }
    
    /**
     * Complete login and return tokens
     */
    private function completeLogin(string $email, string $password, string $userAgent, string $ipAddress, ?string $existingDeviceToken = null): Response
    {
        // Encrypt password for server-side storage (prefer DB over JWT)
        $encryptedPassword = $this->session->encryptPassword($password);
        
        $refreshToken = $this->session->createRefreshToken($email);
        
        // Create session tracking record WITH the encrypted password
        $sessionToken = null;
        try {
            $sessionTracker = new \Webmail\Services\SessionTrackingService($this->config);
            $sessionToken = $sessionTracker->createSession($email, $userAgent, $ipAddress, $this->config['jwt']['expiry'] ?? 43200, $encryptedPassword);
        } catch (\Exception $e) {
            error_log('Session tracking error: ' . $e->getMessage());
        }
        
        // Security: never include passwords (even encrypted) in JWTs.
        // If session tracking failed, the token still works but password-dependent
        // features (IMAP) will require re-authentication.
        $displayName = $this->getDisplayName($email);
        $accessToken = $this->session->createToken($email, [
            'display_name' => $displayName,
        ]);
        
        if (!$sessionToken) {
            error_log("WARNING: Session tracking failed for {$email} - IMAP features will require re-login");
        }

        $response = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->config['jwt']['expiry'] ?? 3600,
            'user' => [
                'email' => $email,
                'display_name' => $displayName,
                'force_password_change' => $this->getForcePasswordChange($email),
            ],
        ];
        
        if ($sessionToken) {
            $response['session_token'] = $sessionToken;
            
            // Store refresh token hash for rotation tracking
            try {
                $sessionTracker->storeRefreshTokenHash($email, $sessionToken, $refreshToken);
            } catch (\Exception $e) {
                error_log('Refresh token hash storage error: ' . $e->getMessage());
            }
        }
        
        // Pass through the existing device token if it was used
        if ($existingDeviceToken) {
            $response['device_token'] = $existingDeviceToken;
        }

        AuditLogger::auth('login', 'success', $email, ['ip' => $ipAddress, 'user_agent' => substr($userAgent, 0, 100)]);

        return Response::success($response, 'Login successful');
    }

    /**
     * Refresh access token with token rotation
     * POST /auth/refresh
     * 
     * Requires: refresh_token in body + X-Session-Token header
     * Returns: new access_token + new refresh_token (old refresh token is invalidated)
     * 
     * If a previously-used refresh token is replayed (possible theft),
     * the entire session is killed for safety.
     */
    public function refresh(Request $request): Response
    {
        $oldRefreshToken = $request->input('refresh_token');
        $sessionToken = $request->getHeader('X-Session-Token');
        
        if (!$oldRefreshToken) {
            return Response::unauthorized('Refresh token is required');
        }
        
        if (!$sessionToken) {
            return Response::unauthorized('Session token is required for refresh');
        }
        
        // Validate the refresh JWT
        $payload = $this->session->validateToken($oldRefreshToken);
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            return Response::unauthorized('Invalid or expired refresh token. Please log in again.');
        }
        
        $email = $payload['sub'] ?? null;
        if (!$email) {
            return Response::unauthorized('Invalid refresh token payload');
        }
        
        // Rotate: validate old refresh hash + issue new tokens + update hash
        try {
            $sessionTracker = new \Webmail\Services\SessionTrackingService($this->config);
            $newRefreshToken = $this->session->createRefreshToken($email);
            
            $encryptedPassword = $sessionTracker->rotateRefreshToken(
                $email, $sessionToken, $oldRefreshToken, $newRefreshToken
            );
            
            if ($encryptedPassword === null) {
                // Rotation failed — either session gone or replay attack detected
                return Response::unauthorized('Session invalidated. Please log in again.');
            }
            
            // Issue new access token with display name
            $displayName = $this->getDisplayName($email);
            $accessToken = $this->session->createToken($email, [
                'display_name' => $displayName,
            ]);
            
            return Response::success([
                'access_token' => $accessToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => $this->config['jwt']['expiry'] ?? 3600,
            ], 'Token refreshed');
            
        } catch (\Exception $e) {
            error_log('Token refresh error: ' . $e->getMessage());
            return Response::error('Service temporarily unavailable. Please try again.', 503);
        }
    }

    /**
     * Get current user info
     * GET /auth/me
     */
    public function me(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $email = $this->getUser($request);
        $displayName = $this->getDisplayName($email);

        // Get avatar from colleague record
        $avatarUrl = null;
        try {
            $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
            $colleague = $colleagueService->ensureColleagueExists($email);
            if ($colleague && !empty($colleague['avatar_path'])) {
                $avatarUrl = '/api/colleagues/avatar/' . basename($colleague['avatar_path']);
            }
        } catch (\Exception $e) {
            // Non-critical — avatar is optional
        }

        return Response::success([
            'email' => $email,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'force_password_change' => $this->getForcePasswordChange($email),
        ]);
    }

    /**
     * Logout (client-side token removal)
     * POST /auth/logout
     */
    public function logout(Request $request): Response
    {
        // JWT tokens are stateless, so logout is handled client-side
        // This endpoint exists for consistency and potential future server-side session management
        return Response::success(null, 'Logged out successfully');
    }
    
    /**
     * Check if Google OAuth is enabled
     * GET /auth/google/enabled
     */
    public function googleEnabled(Request $request): Response
    {
        return Response::success([
            'enabled' => $this->googleOAuthService !== null,
        ]);
    }
    
    /**
     * Check if Microsoft OAuth is enabled
     * GET /auth/microsoft/enabled
     */
    public function microsoftEnabled(Request $request): Response
    {
        return Response::success([
            'enabled' => $this->microsoftOAuthService !== null,
        ]);
    }
    
    /**
     * Get Google OAuth URL for login (public - no auth required)
     * GET /auth/google/login
     */
    public function googleLoginUrl(Request $request): Response
    {
        if (!$this->googleOAuthService) {
            return Response::error('Google OAuth is not configured', 500);
        }
        
        // Native mobile apps pass redirect_scheme so OAuth callback deep-links back into the app
        $redirectScheme = $request->getQuery('redirect_scheme');

        // HMAC-signed state via the shared OAuthStateService. Previously the
        // login flow built the state envelope inline (Phase 2.1 missed this
        // file); the duplicated code meant any future change to the signing
        // scheme had to be applied in three places. The service injects a
        // random nonce + timestamp so we don't need to do it here.
        $stateService = new OAuthStateService($this->config);
        $nonce = bin2hex(random_bytes(16));
        $statePayload = ['action' => 'login', 'nonce' => $nonce];
        if ($redirectScheme && preg_match('/^[a-z][a-z0-9+\-\.]*$/i', $redirectScheme)) {
            $statePayload['redirect_scheme'] = $redirectScheme;
        }
        $state = $stateService->sign($statePayload);

        // Phase 3: PKCE S256 verifier/challenge for the login flow.
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $challenge = $pkce->createChallenge($nonce);
        
        // Use login-specific redirect URI with minimal approved scopes (openid email profile only)
        $loginRedirectUri = rtrim($this->config['app']['api_url'] ?? 'https://flowone.pro/api', '/') . '/auth/google/login/callback';
        $authUrl = $this->googleOAuthService->getAuthorizationUrl(
            $state,
            $loginRedirectUri,
            true,
            null,
            null,
            $challenge['challenge']
        );

        return Response::success(['auth_url' => $authUrl]);
    }
    
    /**
     * Handle Google OAuth callback for login (GET - browser redirect)
     * GET /auth/google/login/callback
     */
    public function googleLoginCallback(Request $request): Response
    {
        $code = $request->getQuery('code');
        $state = $request->getQuery('state');
        $error = $request->getQuery('error');
        
        // Build the redirect URL for the frontend (may be overridden by native deep link scheme)
        $frontendUrl = rtrim($this->config['app']['frontend_url'] ?? 'https://flowone.pro', '/');
        $stateData = $this->verifyOAuthState($state);
        $redirectBase = $this->resolveOAuthRedirectBase($stateData, $frontendUrl);
        
        if ($error) {
            header("Location: {$redirectBase}/login?oauth_error=" . urlencode($error));
            exit;
        }
        
        if (!$code) {
            header("Location: {$redirectBase}/login?oauth_error=no_code");
            exit;
        }
        
        // OAuthStateService::verify() already enforces both the HMAC and
        // the 15-minute TTL, returning null on any failure - so a null
        // $stateData here means tampered OR expired.
        if (!$stateData || ($stateData['action'] ?? '') !== 'login') {
            header("Location: {$redirectBase}/login?oauth_error=invalid_state");
            exit;
        }

        if (!$this->googleOAuthService) {
            header("Location: {$redirectBase}/login?oauth_error=oauth_not_configured");
            exit;
        }
        
        // Phase 3: consume PKCE verifier (single-use).
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $verifier = isset($stateData['nonce']) ? $pkce->consumeVerifier((string)$stateData['nonce']) : null;

        // Exchange code for tokens (use login redirect URI)
        $loginRedirectUri = rtrim($this->config['app']['api_url'] ?? 'https://flowone.pro/api', '/') . '/auth/google/login/callback';
        $tokens = $this->googleOAuthService->exchangeCodeForTokensWithRedirect($code, $loginRedirectUri, $verifier);
        if (!$tokens) {
            header("Location: {$redirectBase}/login?oauth_error=token_exchange_failed");
            exit;
        }
        
        // Get user info from Google
        $userInfo = $this->googleOAuthService->getUserInfo($tokens['access_token']);
        if (!$userInfo || !isset($userInfo['email'])) {
            header("Location: {$redirectBase}/login?oauth_error=user_info_failed");
            exit;
        }
        
        $googleEmail = strtolower($userInfo['email']);

        // Verification: the merged login flow MUST receive a refresh token
        // because we request access_type=offline + prompt=consent + mail.google.com.
        // If this logs "no", IMAP-via-OAuth will break once the access token expires.
        error_log(sprintf(
            'GoogleOAuth LOGIN: scope grant for %s - refresh_token=%s, scope=%s',
            $googleEmail,
            !empty($tokens['refresh_token']) ? 'yes' : 'no',
            $tokens['scope'] ?? 'unknown'
        ));

        // Store/update OAuth tokens for this user as their primary account.
        // Login now grants full Gmail scope so this row backs IMAP/SMTP via XOAUTH2.
        $this->googleOAuthService->storeTokensForLogin($googleEmail, $tokens, $userInfo);
        
        // Create session tokens
        $displayName = $this->getDisplayName($googleEmail) ?: ($userInfo['name'] ?? explode('@', $googleEmail)[0]);
        $accessToken = $this->session->createToken($googleEmail, [
            'oauth' => true,
            'provider' => 'google',
            'display_name' => $displayName,
        ]);
        
        $refreshToken = $this->session->createRefreshToken($googleEmail);
        
        // Create session tracking record (use '__oauth__:google' marker instead of password)
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $sessionToken = null;
        try {
            $sessionTracker = new \Webmail\Services\SessionTrackingService($this->config);
            $sessionToken = $sessionTracker->createSession(
                $googleEmail, $userAgent, $ipAddress,
                $this->config['jwt']['expiry'] ?? 43200,
                '__oauth__:google'
            );
            
            // Store refresh token hash for rotation tracking
            if ($sessionToken) {
                $sessionTracker->storeRefreshTokenHash($googleEmail, $sessionToken, $refreshToken);
            }
        } catch (\Exception $e) {
            error_log('OAuth session tracking error (Google): ' . $e->getMessage());
        }
        
        // Trust this device automatically for OAuth logins
        $deviceToken = null;
        try {
            $twoFactor = new TwoFactorService($this->config);
            $deviceToken = $twoFactor->trustDevice($googleEmail, $userAgent, $ipAddress);
        } catch (\Exception $e) {
            error_log('OAuth device trust error (Google): ' . $e->getMessage());
        }
        
        // Redirect with tokens
        $tokenData = base64_encode(json_encode([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'session_token' => $sessionToken,
            'device_token' => $deviceToken,
            'expires_in' => $this->config['jwt']['expiry'] ?? 3600,
            'user' => [
                'email' => $googleEmail,
                'display_name' => $displayName,
                'force_password_change' => $this->getForcePasswordChange($googleEmail),
            ],
        ]));
        
        AuditLogger::auth('oauth_login', 'success', $googleEmail, ['provider' => 'google', 'ip' => $ipAddress]);
        
        // Phase 2.2: hand the token payload off via a one-time Redis-backed
        // code instead of embedding it in the URL. Falls back to the legacy
        // ?oauth_success= payload only if Redis is unreachable.
        $tokenPayload = json_decode(base64_decode($tokenData), true);
        $handoff = $this->issueOAuthHandoff(is_array($tokenPayload) ? $tokenPayload : []);
        if ($handoff['code']) {
            header("Location: {$redirectBase}/login?handoff=" . urlencode($handoff['code']));
        } else {
            header("Location: {$redirectBase}/login?oauth_success=" . urlencode($handoff['fallback']));
        }
        exit;
    }
    
    /**
     * Get Microsoft OAuth URL for login (public - no auth required)
     * GET /auth/microsoft/login
     */
    public function microsoftLoginUrl(Request $request): Response
    {
        if (!$this->microsoftOAuthService) {
            return Response::error('Microsoft OAuth is not configured', 500);
        }
        
        // Native mobile apps pass redirect_scheme so OAuth callback deep-links back into the app
        $redirectScheme = $request->getQuery('redirect_scheme');

        // HMAC-signed state via the shared OAuthStateService (Phase 1 of
        // the OAuth rewrite consolidation - removes the inline duplicate).
        $stateService = new OAuthStateService($this->config);
        $nonce = bin2hex(random_bytes(16));
        $statePayload = ['action' => 'login', 'nonce' => $nonce];
        if ($redirectScheme && preg_match('/^[a-z][a-z0-9+\-\.]*$/i', $redirectScheme)) {
            $statePayload['redirect_scheme'] = $redirectScheme;
        }
        $state = $stateService->sign($statePayload);

        // Phase 3: PKCE S256 verifier/challenge.
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $challenge = $pkce->createChallenge($nonce);

        // Use login-specific redirect URI
        $loginRedirectUri = rtrim($this->config['app']['api_url'] ?? 'https://flowone.pro/api', '/') . '/auth/microsoft/login/callback';
        $authUrl = $this->microsoftOAuthService->getAuthorizationUrl(
            $state,
            $loginRedirectUri,
            null,
            null,
            $challenge['challenge']
        );
        
        return Response::success(['auth_url' => $authUrl]);
    }
    
    /**
     * Handle Microsoft OAuth callback for login (GET - browser redirect)
     * GET /auth/microsoft/login/callback
     */
    public function microsoftLoginCallback(Request $request): Response
    {
        $code = $request->getQuery('code');
        $state = $request->getQuery('state');
        $error = $request->getQuery('error');
        $errorDescription = $request->getQuery('error_description');
        
        // Build the redirect URL for the frontend (may be overridden by native deep link scheme)
        $frontendUrl = rtrim($this->config['app']['frontend_url'] ?? 'https://flowone.pro', '/');
        $stateData = $this->verifyOAuthState($state);
        $redirectBase = $this->resolveOAuthRedirectBase($stateData, $frontendUrl);
        
        if ($error) {
            $errorMsg = $errorDescription ?: $error;
            header("Location: {$redirectBase}/login?oauth_error=" . urlencode($errorMsg));
            exit;
        }
        
        if (!$code) {
            header("Location: {$redirectBase}/login?oauth_error=no_code");
            exit;
        }
        
        // OAuthStateService::verify() already enforces both the HMAC and
        // the 15-minute TTL (Phase 1 cleanup).
        if (!$stateData || ($stateData['action'] ?? '') !== 'login') {
            header("Location: {$redirectBase}/login?oauth_error=invalid_state");
            exit;
        }

        if (!$this->microsoftOAuthService) {
            header("Location: {$redirectBase}/login?oauth_error=oauth_not_configured");
            exit;
        }
        
        // Phase 3: consume PKCE verifier.
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $verifier = isset($stateData['nonce']) ? $pkce->consumeVerifier((string)$stateData['nonce']) : null;

        // Exchange code for tokens (use login redirect URI)
        $loginRedirectUri = rtrim($this->config['app']['api_url'] ?? 'https://flowone.pro/api', '/') . '/auth/microsoft/login/callback';
        $tokens = $this->microsoftOAuthService->exchangeCodeForTokens($code, $loginRedirectUri, $verifier);
        if (!$tokens) {
            header("Location: {$redirectBase}/login?oauth_error=token_exchange_failed");
            exit;
        }
        
        // Get user info from Microsoft
        $userInfo = $this->microsoftOAuthService->getUserInfo($tokens['access_token']);
        if (!$userInfo || !isset($userInfo['email'])) {
            header("Location: {$redirectBase}/login?oauth_error=user_info_failed");
            exit;
        }
        
        $microsoftEmail = strtolower($userInfo['email']);
        
        // Store/update OAuth tokens for this user as their primary account
        $this->microsoftOAuthService->storeTokensForLogin($microsoftEmail, $tokens, $userInfo);
        
        // Create session tokens
        $displayName = $this->getDisplayName($microsoftEmail) ?: ($userInfo['name'] ?? explode('@', $microsoftEmail)[0]);
        $accessToken = $this->session->createToken($microsoftEmail, [
            'oauth' => true,
            'provider' => 'microsoft',
            'display_name' => $displayName,
        ]);
        
        $refreshToken = $this->session->createRefreshToken($microsoftEmail);
        
        // Create session tracking record (use '__oauth__:microsoft' marker instead of password)
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $sessionToken = null;
        try {
            $sessionTracker = new \Webmail\Services\SessionTrackingService($this->config);
            $sessionToken = $sessionTracker->createSession(
                $microsoftEmail, $userAgent, $ipAddress,
                $this->config['jwt']['expiry'] ?? 43200,
                '__oauth__:microsoft'
            );
            
            // Store refresh token hash for rotation tracking
            if ($sessionToken) {
                $sessionTracker->storeRefreshTokenHash($microsoftEmail, $sessionToken, $refreshToken);
            }
        } catch (\Exception $e) {
            error_log('OAuth session tracking error (Microsoft): ' . $e->getMessage());
        }
        
        // Trust this device automatically for OAuth logins
        $deviceToken = null;
        try {
            $twoFactor = new TwoFactorService($this->config);
            $deviceToken = $twoFactor->trustDevice($microsoftEmail, $userAgent, $ipAddress);
        } catch (\Exception $e) {
            error_log('OAuth device trust error (Microsoft): ' . $e->getMessage());
        }
        
        // Redirect with tokens
        $tokenData = base64_encode(json_encode([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'session_token' => $sessionToken,
            'device_token' => $deviceToken,
            'expires_in' => $this->config['jwt']['expiry'] ?? 3600,
            'user' => [
                'email' => $microsoftEmail,
                'display_name' => $displayName,
                'force_password_change' => $this->getForcePasswordChange($microsoftEmail),
            ],
        ]));
        
        AuditLogger::auth('oauth_login', 'success', $microsoftEmail, ['provider' => 'microsoft', 'ip' => $ipAddress]);
        
        // Phase 2.2: handoff code instead of in-URL token payload (same fix
        // as Google login above - keeps tokens out of browser history,
        // Referer headers, and access logs).
        $tokenPayload = json_decode(base64_decode($tokenData), true);
        $handoff = $this->issueOAuthHandoff(is_array($tokenPayload) ? $tokenPayload : []);
        if ($handoff['code']) {
            header("Location: {$redirectBase}/login?handoff=" . urlencode($handoff['code']));
        } else {
            header("Location: {$redirectBase}/login?oauth_success=" . urlencode($handoff['fallback']));
        }
        exit;
    }
    
    /**
     * Verify an HMAC-signed OAuth state parameter.
     *
     * Thin wrapper around OAuthStateService::verify() so the login flow
     * shares the exact same signing scheme as the add-account flow
     * (AccountController) and the calendar-only flow
     * (CalendarConnectionController). The shared service also enforces
     * the 15-minute state TTL, so callers no longer need to compare
     * timestamps themselves.
     */
    private function verifyOAuthState(?string $state): ?array
    {
        try {
            $service = new OAuthStateService($this->config);
            return $service->verify($state);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Resolve the OAuth redirect base URL.
     * If the state contains a native app redirect_scheme (e.g. "flowone-chat"),
     * returns "flowone-chat:/" so the callback deep-links into the native app.
     * Otherwise falls back to the standard frontend URL.
     */
    private function resolveOAuthRedirectBase(?array $stateData, string $frontendUrl): string
    {
        $scheme = $stateData['redirect_scheme'] ?? null;
        if ($scheme && preg_match('/^[a-z][a-z0-9+\-\.]*$/i', $scheme)) {
            return $scheme . ':/';
        }
        return $frontendUrl;
    }

    /**
     * Phase 2.2: issue a one-time handoff code that the frontend exchanges
     * for the actual access/refresh/session tokens via POST. Previously the
     * tokens were base64-stuffed into ?oauth_success= which leaked them into
     * browser history, the Referer header, and access logs. The code is a
     * 32-byte random hex string stored in Redis with a 60-second TTL and a
     * single-use guarantee (the GET handler deletes the key after returning).
     *
     * Falls back to the legacy in-URL payload only when Redis is unavailable
     * so a Redis outage cannot lock users out of OAuth login. The fallback
     * path emits a warning log so operators can spot it.
     */
    private function issueOAuthHandoff(array $tokenPayload): array
    {
        try {
            $cache = new RedisCacheService($this->config);
            if ($cache->isAvailable()) {
                $code = bin2hex(random_bytes(32));
                $key = 'oauth:handoff:' . $code;
                $cache->set($key, $tokenPayload, 60);
                return ['code' => $code, 'fallback' => null];
            }
            error_log('AuthController::issueOAuthHandoff - Redis unavailable, falling back to inline token payload');
        } catch (\Throwable $e) {
            error_log('AuthController::issueOAuthHandoff - error: ' . $e->getMessage());
        }
        // Fallback: inline base64 payload (preserves the legacy behaviour
        // when Redis is unreachable; never reached in normal operation).
        return ['code' => null, 'fallback' => base64_encode(json_encode($tokenPayload))];
    }

    /**
     * Exchange a one-time handoff code (issued by issueOAuthHandoff above)
     * for the actual token payload. Single-use: the Redis key is deleted on
     * read, so a replayed code returns 404.
     *
     * POST /auth/oauth/handoff
     * Body: { code: "<64-hex>" }
     */
    public function oauthHandoff(Request $request): Response
    {
        $code = (string)$request->input('code', '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $code)) {
            return Response::error('Invalid handoff code', 400);
        }
        try {
            $cache = new RedisCacheService($this->config);
            if (!$cache->isAvailable()) {
                return Response::error('Session store unavailable', 503);
            }
            $key = 'oauth:handoff:' . $code;
            $payload = $cache->get($key);
            // Single-use: delete unconditionally so a stolen code can't be
            // exchanged a second time even on a network-retry race.
            $cache->delete($key);
            if (!is_array($payload) || empty($payload)) {
                return Response::error('Handoff code expired or already used', 404);
            }
            return Response::success($payload);
        } catch (\Throwable $e) {
            error_log('AuthController::oauthHandoff - error: ' . $e->getMessage());
            return Response::error('Handoff failed', 500);
        }
    }
}

