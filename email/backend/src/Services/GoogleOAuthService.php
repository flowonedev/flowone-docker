<?php

namespace Webmail\Services;

/**
 * GoogleOAuthService - Handles Google OAuth 2.0 authentication for Gmail
 * 
 * Implements OAuth flow for "Sign in with Google" and XOAUTH2 for IMAP/SMTP
 */
class GoogleOAuthService
{
    private array $config;
    private array $fullConfig;
    private \PDO $db;
    private OAuthCryptor $cryptor;
    private OAuthTokenCache $tokenCache;
    private OAuthHealthService $health;
    private ?string $lastFailureReason = null;
    private ?string $lastRefreshError = null;
    
    private const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * OAuth 2.0 errors that MUST NOT be retried. Once Google returns one of
     * these, the refresh token is dead and the user has to reconnect. We mark
     * the row 'revoked', set the Redis flag, and emit a WS event so the
     * frontend shows a reconnect banner instead of looping forever.
     */
    private const TERMINAL_REFRESH_ERRORS = [
        'invalid_grant',
        'invalid_client',
        'unauthorized_client',
    ];
    
    public function __construct(array $config, ?OAuthTokenCache $tokenCache = null)
    {
        $this->fullConfig = $config;
        $this->config = $config['google_oauth'] ?? [];
        
        $this->db = \Webmail\Core\Database::getConnection($config);

        $this->cryptor = new OAuthCryptor($config);
        $this->tokenCache = $tokenCache ?? new OAuthTokenCache($config);
        $this->health = new OAuthHealthService($config, $this->tokenCache);
        
        $this->ensureTableExists();
    }
    
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_oauth_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    primary_email VARCHAR(255) NOT NULL COMMENT 'The main login email (owner)',
                    oauth_email VARCHAR(255) NOT NULL COMMENT 'The Google account email',
                    provider VARCHAR(50) DEFAULT 'google',
                    access_token_encrypted TEXT NOT NULL,
                    refresh_token_encrypted TEXT NOT NULL,
                    token_expires_at TIMESTAMP NOT NULL,
                    display_name VARCHAR(255) DEFAULT NULL,
                    account_type ENUM('separate', 'linked') DEFAULT 'separate',
                    sync_frequency INT DEFAULT 15,
                    leave_on_server TINYINT(1) DEFAULT 1,
                    auto_label VARCHAR(255) DEFAULT NULL,
                    signature TEXT DEFAULT NULL,
                    sync_enabled TINYINT(1) DEFAULT 1,
                    last_sync TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_primary_email (primary_email),
                    INDEX idx_provider (provider),
                    UNIQUE KEY unique_oauth_account (primary_email, oauth_email, provider)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Add signature column if table already exists
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM webmail_oauth_tokens LIKE 'signature'");
                if ($stmt->rowCount() === 0) {
                    $this->db->exec("ALTER TABLE webmail_oauth_tokens ADD COLUMN signature TEXT DEFAULT NULL AFTER auto_label");
                }
            } catch (\PDOException $e) {
                // Ignore
            }
        } catch (\PDOException $e) {
            error_log("GoogleOAuthService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Encrypt token for storage
     */
    private function encryptToken(string $token): string
    {
        return $this->cryptor->encrypt($token);
    }
    
    /**
     * Decrypt token from storage
     */
    private function decryptToken(?string $encrypted): string
    {
        $out = $this->cryptor->decrypt($encrypted);
        return $out ?? '';
    }

    public function getLastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }
    
    /**
     * Generate OAuth authorization URL
     * 
     * @param bool $loginOnly  When true, uses the minimal approved login_scopes (openid email profile)
     *                         instead of the full account scopes (Gmail, Calendar, Contacts).
     *                         Always pass true for the "Sign in with Google" login flow.
     */
    public function getAuthorizationUrl(
        string $state = '',
        ?string $redirectUri = null,
        bool $loginOnly = false,
        ?string $loginHint = null,
        ?string $prompt = null,
        ?string $codeChallenge = null
    ): string
    {
        $scopes = $loginOnly
            ? ($this->config['login_scopes'] ?? ['openid', 'email', 'profile'])
            : $this->config['scopes'];

        $params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $redirectUri ?? $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'prompt' => $prompt ?? 'consent',
            'state' => $state,
        ];

        if ($loginHint) {
            $params['login_hint'] = $loginHint;
        }

        // Phase 3: PKCE S256. RFC 7636 + draft-ietf-oauth-v2-1 require
        // this on every OAuth code flow, even confidential clients.
        if ($codeChallenge !== null && $codeChallenge !== '') {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return self::GOOGLE_AUTH_URL . '?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for tokens with custom redirect URI.
     *
     * Phase 3: when a PKCE verifier was generated for this flow, the
     * caller MUST pass it here. The provider hashes it and compares to
     * the challenge sent on the auth URL — a mismatch returns
     * invalid_grant, which our refresh-error classifier already treats
     * as terminal.
     */
    public function exchangeCodeForTokensWithRedirect(string $code, string $redirectUri, ?string $codeVerifier = null): ?array
    {
        $params = [
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $params['code_verifier'] = $codeVerifier;
        }
        
        error_log("GoogleOAuth: Exchanging code for tokens with redirect_uri: {$redirectUri}");
        
        $ch = curl_init(self::GOOGLE_TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("GoogleOAuth: cURL error during token exchange: {$curlError}");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $data['error'] ?? 'unknown';
            $errorDesc = $data['error_description'] ?? $response;
            error_log("GoogleOAuth: Token exchange failed (HTTP {$httpCode}) - Error: {$errorMsg}, Description: {$errorDesc}");
            error_log("GoogleOAuth: Used redirect_uri: {$redirectUri}");
            return null;
        }
        
        error_log("GoogleOAuth: Token exchange successful, got access_token: " . (isset($data['access_token']) ? 'yes' : 'no') . ", refresh_token: " . (isset($data['refresh_token']) ? 'yes' : 'no'));
        
        return $data;
    }
    
    /**
     * Exchange authorization code for tokens.
     *
     * Phase 3: PKCE-aware variant. Pass codeVerifier when one was
     * generated by PKCEService::createChallenge() for this auth flow.
     */
    public function exchangeCodeForTokens(string $code, ?string $codeVerifier = null): ?array
    {
        $redirectUri = $this->config['redirect_uri'];
        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $params['code_verifier'] = $codeVerifier;
        }
        
        error_log("GoogleOAuth: Exchanging code for tokens with redirect_uri: {$redirectUri}");
        
        $response = $this->httpPost(self::GOOGLE_TOKEN_URL, $params);
        
        if (!$response || isset($response['error'])) {
            $errorMsg = $response['error'] ?? 'unknown';
            $errorDesc = $response['error_description'] ?? 'no description';
            error_log("GoogleOAuth: Token exchange error - Error: {$errorMsg}, Description: {$errorDesc}");
            error_log("GoogleOAuth: Used redirect_uri: {$redirectUri}");
            error_log("GoogleOAuth: Full response: " . json_encode($response));
            return null;
        }
        
        error_log("GoogleOAuth: Token exchange successful, got access_token: " . (isset($response['access_token']) ? 'yes' : 'no') . ", refresh_token: " . (isset($response['refresh_token']) ? 'yes' : 'no'));
        
        return $response;
    }
    
    /**
     * Refresh access token using refresh token.
     *
     * On error, the OAuth error code is exposed via getLastRefreshError() so
     * the caller can distinguish terminal failures (invalid_grant -> revoke
     * account, no retry) from transient failures (5xx, network -> may retry).
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $this->lastRefreshError = null;

        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];
        
        error_log("GoogleOAuth: Refreshing access token");
        
        $response = $this->httpPost(self::GOOGLE_TOKEN_URL, $params);
        
        if (!$response || isset($response['error'])) {
            $errorMsg = $response['error'] ?? 'transport_error';
            $errorDesc = $response['error_description'] ?? 'no description';
            $this->lastRefreshError = $errorMsg;
            error_log("GoogleOAuth: Token refresh error - Error: {$errorMsg}, Description: {$errorDesc}");
            return null;
        }
        
        error_log("GoogleOAuth: Token refresh successful");
        
        return $response;
    }

    /**
     * OAuth 2.0 error code from the most recent refreshAccessToken() call.
     * Returns null when the last call succeeded or was never made.
     */
    public function getLastRefreshError(): ?string
    {
        return $this->lastRefreshError;
    }

    /**
     * True when the most recent refresh failure means the refresh token is
     * dead and the user has to reconnect. Anything else (5xx, network) may
     * be safely retried later.
     */
    public function isLastRefreshErrorTerminal(): bool
    {
        return $this->lastRefreshError !== null
            && in_array($this->lastRefreshError, self::TERMINAL_REFRESH_ERRORS, true);
    }
    
    /**
     * Get user info from Google
     */
    public function getUserInfo(string $accessToken): ?array
    {
        error_log("GoogleOAuth: Fetching user info from Google");
        
        $ch = curl_init(self::GOOGLE_USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("GoogleOAuth: cURL error fetching user info: {$curlError}");
            return null;
        }
        
        if ($httpCode !== 200) {
            error_log("GoogleOAuth: User info request failed (HTTP {$httpCode}): {$response}");
            return null;
        }
        
        $data = json_decode($response, true);
        error_log("GoogleOAuth: Got user info for email: " . ($data['email'] ?? 'unknown'));
        
        return $data;
    }
    
    /**
     * Store OAuth tokens for a user
     */
    public function storeTokens(string $primaryEmail, array $tokenData, array $userInfo, array $options = []): ?array
    {
        $primaryEmail = strtolower($primaryEmail);
        $oauthEmail = strtolower($userInfo['email']);
        $expiresAt = date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600));
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_oauth_tokens 
                (primary_email, oauth_email, provider, access_token_encrypted, refresh_token_encrypted, 
                 token_expires_at, display_name, account_type, sync_frequency, leave_on_server, auto_label, sync_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    access_token_encrypted = VALUES(access_token_encrypted),
                    refresh_token_encrypted = COALESCE(VALUES(refresh_token_encrypted), refresh_token_encrypted),
                    token_expires_at = VALUES(token_expires_at),
                    display_name = VALUES(display_name),
                    account_type = VALUES(account_type),
                    sync_frequency = VALUES(sync_frequency),
                    leave_on_server = VALUES(leave_on_server),
                    auto_label = VALUES(auto_label),
                    sync_enabled = VALUES(sync_enabled),
                    health = 'healthy',
                    health_reason = NULL,
                    health_updated_at = NOW(),
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                $primaryEmail,
                $oauthEmail,
                'google',
                $this->encryptToken($tokenData['access_token']),
                isset($tokenData['refresh_token']) ? $this->encryptToken($tokenData['refresh_token']) : null,
                $expiresAt,
                $userInfo['name'] ?? $options['display_name'] ?? null,
                $options['account_type'] ?? 'separate',
                (int)($options['sync_frequency'] ?? 15),
                ($options['leave_on_server'] ?? true) ? 1 : 0,
                $options['auto_label'] ?? null,
                ($options['sync_enabled'] ?? true) ? 1 : 0,
            ]);
            
            return $this->getOAuthAccount($primaryEmail, $oauthEmail);
            
        } catch (\PDOException $e) {
            error_log("GoogleOAuthService storeTokens error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Store OAuth tokens for login (primary email = oauth email).
     * Used when user logs in with Google directly.
     *
     * Login now uses the FULL Gmail scope (mail.google.com) so the access token
     * is usable for IMAP via XOAUTH2 and a refresh token is returned. We persist
     * the refresh token so IMAP works seamlessly after the access token expires.
     *
     * If Google omits the refresh token (can happen on re-consent when the user
     * was already granted), preserve any existing stored refresh token instead
     * of clobbering it with an empty string.
     */
    public function storeTokensForLogin(string $googleEmail, array $tokenData, array $userInfo): bool
    {
        $googleEmail = strtolower($googleEmail);
        $expiresAt = date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600));

        $hasNewRefreshToken = !empty($tokenData['refresh_token']);
        // Pass NULL (not '') when Google omits the refresh token so the UPDATE branch's
        // COALESCE keeps the previously stored refresh token instead of clobbering it.
        $encryptedRefreshToken = $hasNewRefreshToken
            ? $this->encryptToken($tokenData['refresh_token'])
            : null;

        try {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_oauth_tokens
                (primary_email, oauth_email, provider, access_token_encrypted, refresh_token_encrypted,
                 token_expires_at, display_name, account_type, sync_frequency, leave_on_server, sync_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    access_token_encrypted = VALUES(access_token_encrypted),
                    refresh_token_encrypted = COALESCE(VALUES(refresh_token_encrypted), refresh_token_encrypted),
                    token_expires_at = VALUES(token_expires_at),
                    display_name = VALUES(display_name),
                    health = 'healthy',
                    health_reason = NULL,
                    health_updated_at = NOW(),
                    updated_at = NOW()
            ");

            $stmt->bindValue(1, $googleEmail, \PDO::PARAM_STR);
            $stmt->bindValue(2, $googleEmail, \PDO::PARAM_STR);
            $stmt->bindValue(3, 'google', \PDO::PARAM_STR);
            $stmt->bindValue(4, $this->encryptToken($tokenData['access_token']), \PDO::PARAM_STR);
            $stmt->bindValue(5, $encryptedRefreshToken, $encryptedRefreshToken === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            $stmt->bindValue(6, $expiresAt, \PDO::PARAM_STR);
            $stmt->bindValue(7, $userInfo['name'] ?? explode('@', $googleEmail)[0], \PDO::PARAM_STR);
            $stmt->bindValue(8, 'separate', \PDO::PARAM_STR);
            $stmt->bindValue(9, 15, \PDO::PARAM_INT);
            $stmt->bindValue(10, 1, \PDO::PARAM_INT);
            $stmt->bindValue(11, 1, \PDO::PARAM_INT);
            $stmt->execute();

            return true;

        } catch (\PDOException $e) {
            error_log("GoogleOAuthService storeTokensForLogin error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get OAuth account details
     */
    public function getOAuthAccount(string $primaryEmail, string $oauthEmail): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, primary_email, oauth_email as account_email, provider, display_name,
                   account_type, sync_frequency, leave_on_server, auto_label, signature, sync_enabled,
                   last_sync, created_at, token_expires_at,
                   "oauth" as auth_type
            FROM webmail_oauth_tokens 
            WHERE primary_email = ? AND oauth_email = ?
        ');
        $stmt->execute([strtolower($primaryEmail), strtolower($oauthEmail)]);
        $account = $stmt->fetch();
        
        if ($account) {
            $account['leave_on_server'] = (bool)$account['leave_on_server'];
            $account['sync_enabled'] = (bool)$account['sync_enabled'];
            // Add Gmail server settings
            $account['imap_host'] = 'imap.gmail.com';
            $account['imap_port'] = 993;
            $account['imap_encryption'] = 'ssl';
            $account['smtp_host'] = 'smtp.gmail.com';
            $account['smtp_port'] = 587;
            $account['smtp_encryption'] = 'tls';
        }
        
        return $account ?: null;
    }
    
    /**
     * Get all OAuth accounts for a user
     */
    public function getOAuthAccounts(string $primaryEmail, ?string $accountType = null): array
    {
        $sql = '
            SELECT id, primary_email, oauth_email as account_email, provider, display_name,
                   account_type, sync_frequency, leave_on_server, auto_label, signature, sync_enabled,
                   last_sync, created_at, token_expires_at,
                   "oauth" as auth_type
            FROM webmail_oauth_tokens 
            WHERE primary_email = ?
        ';
        $params = [strtolower($primaryEmail)];
        
        if ($accountType) {
            $sql .= ' AND account_type = ?';
            $params[] = $accountType;
        }
        
        $sql .= ' ORDER BY created_at ASC';
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return array_map(function($acc) {
            $acc['leave_on_server'] = (bool)$acc['leave_on_server'];
            $acc['sync_enabled'] = (bool)$acc['sync_enabled'];
            // Add Gmail server settings
            $acc['imap_host'] = 'imap.gmail.com';
            $acc['imap_port'] = 993;
            $acc['imap_encryption'] = 'ssl';
            $acc['smtp_host'] = 'smtp.gmail.com';
            $acc['smtp_port'] = 587;
            $acc['smtp_encryption'] = 'tls';
            return $acc;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get a valid access token for (primaryEmail, oauthEmail).
     *
     * Resolution order (Phase 1 of the OAuth rewrite):
     *   1. Redis cache hit -> return immediately, no DB, no HTTP.
     *   2. Redis revoked flag -> fail fast with oauth_revoked.
     *   3. DB read. If access_token still valid -> cache it, return.
     *   4. Refresh: single-flight via Redis mutex. The lock holder calls
     *      Google's /token endpoint; concurrent callers BLOCK-poll the
     *      cache instead of all hammering Google in parallel.
     *   5. invalid_grant / unauthorized_client -> mark row revoked, set
     *      Redis flag, publish ACCOUNT_REVOKED, return null. Never retry.
     */
    public function getValidAccessToken(string $primaryEmail, string $oauthEmail): ?string
    {
        $this->lastFailureReason = null;
        $primaryEmail = strtolower($primaryEmail);
        $oauthEmail = strtolower($oauthEmail);

        // 1. Redis cache hit.
        $cached = $this->tokenCache->getToken('google', $primaryEmail, $oauthEmail);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Revoked? Fail fast without DB.
        if ($this->tokenCache->isRevoked('google', $primaryEmail, $oauthEmail)) {
            $this->lastFailureReason = 'oauth_revoked';
            return null;
        }

        // 3. DB read.
        $stmt = $this->db->prepare('
            SELECT id, access_token_encrypted, refresh_token_encrypted, token_expires_at, health
            FROM webmail_oauth_tokens 
            WHERE primary_email = ? AND oauth_email = ?
        ');
        $stmt->execute([$primaryEmail, $oauthEmail]);
        $token = $stmt->fetch();

        if (!$token) {
            $this->lastFailureReason = 'oauth_no_account';
            return null;
        }

        if (!empty($token['health']) && $token['health'] === 'revoked') {
            // DB already marked revoked. Mirror to Redis so subsequent calls
            // skip the DB hit, and tell the caller.
            $this->tokenCache->markRevoked('google', $primaryEmail, $oauthEmail, 'db_revoked');
            $this->lastFailureReason = 'oauth_revoked';
            return null;
        }

        $expiresAt = strtotime($token['token_expires_at']);
        $needsRefresh = time() > ($expiresAt - 300);

        if (!$needsRefresh) {
            $accessToken = $this->decryptToken($token['access_token_encrypted']);
            if ($accessToken === '') {
                $this->lastFailureReason = 'oauth_decrypt_failed';
                $this->markHealth((int)$token['id'], 'broken', 'decrypt_failed');
                return null;
            }
            $ttl = max(0, $expiresAt - time());
            $this->tokenCache->putToken('google', $primaryEmail, $oauthEmail, $accessToken, $ttl);
            return $accessToken;
        }

        // 4. Refresh required. Single-flight via Redis mutex.
        return $this->refreshWithSingleFlight($primaryEmail, $oauthEmail, $token);
    }

    /**
     * Acquire the per-account refresh mutex and perform the actual refresh.
     * Concurrent callers block-poll the cache for the lock holder's result
     * rather than all firing their own POST to Google's /token endpoint.
     */
    private function refreshWithSingleFlight(string $primaryEmail, string $oauthEmail, array $tokenRow): ?string
    {
        if (!$this->tokenCache->acquireRefreshLock('google', $primaryEmail, $oauthEmail)) {
            // Another worker is refreshing. Wait for them.
            $refreshed = $this->tokenCache->waitForRefreshedToken('google', $primaryEmail, $oauthEmail);
            if ($refreshed !== null) {
                return $refreshed;
            }
            // Lock holder failed or timed out. Fall through and try ourselves,
            // but try once - if there's a persistent failure the terminal-error
            // path below will set the revoked flag.
            $this->tokenCache->releaseRefreshLock('google', $primaryEmail, $oauthEmail);
            if (!$this->tokenCache->acquireRefreshLock('google', $primaryEmail, $oauthEmail)) {
                $this->lastFailureReason = 'oauth_refresh_contention';
                return null;
            }
        }

        try {
            return $this->doRefresh($primaryEmail, $oauthEmail, $tokenRow);
        } finally {
            $this->tokenCache->releaseRefreshLock('google', $primaryEmail, $oauthEmail);
        }
    }

    /**
     * The actual refresh, assuming this caller holds the lock.
     */
    private function doRefresh(string $primaryEmail, string $oauthEmail, array $tokenRow): ?string
    {
        if (empty($tokenRow['refresh_token_encrypted'])) {
            $this->lastFailureReason = 'oauth_revoked';
            $this->health->markRevoked('google', $primaryEmail, $oauthEmail, 'missing_refresh_token');
            error_log("GoogleOAuth: Cannot refresh - no refresh token stored for {$oauthEmail}");
            return null;
        }

        $refreshToken = $this->decryptToken($tokenRow['refresh_token_encrypted']);
        if ($refreshToken === '') {
            $this->lastFailureReason = 'oauth_decrypt_failed';
            $this->markHealth((int)$tokenRow['id'], 'broken', 'decrypt_failed');
            error_log("GoogleOAuth: Cannot refresh - refresh token decryption failed for {$oauthEmail}");
            return null;
        }

        $newTokens = $this->refreshAccessToken($refreshToken);

        if (!$newTokens) {
            $isTerminal = $this->isLastRefreshErrorTerminal();
            $errorCode = $this->lastRefreshError ?? 'refresh_failed';

            if ($isTerminal) {
                $this->lastFailureReason = 'oauth_revoked';
                $this->health->markRevoked('google', $primaryEmail, $oauthEmail, $errorCode);
            } else {
                // Transient (5xx, network). Don't mark revoked; let cron or the
                // next on-demand call try again later.
                $this->lastFailureReason = 'oauth_refresh_transient';
            }
            return null;
        }

        $expiresIn = (int)($newTokens['expires_in'] ?? 3600);
        $newExpiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        $newAccessToken = (string)$newTokens['access_token'];

        $upd = $this->db->prepare('
            UPDATE webmail_oauth_tokens
            SET access_token_encrypted = ?, token_expires_at = ?, updated_at = NOW()
            WHERE primary_email = ? AND oauth_email = ?
        ');
        $upd->execute([
            $this->encryptToken($newAccessToken),
            $newExpiresAt,
            $primaryEmail,
            $oauthEmail,
        ]);

        // Persist rotated refresh token if Google issued one (rare for Google,
        // but standard behaviour for Microsoft and required by OAuth 2.1).
        if (!empty($newTokens['refresh_token'])) {
            $rot = $this->db->prepare('
                UPDATE webmail_oauth_tokens
                SET refresh_token_encrypted = ?
                WHERE primary_email = ? AND oauth_email = ?
            ');
            $rot->execute([
                $this->encryptToken($newTokens['refresh_token']),
                $primaryEmail,
                $oauthEmail,
            ]);
        }

        $this->health->markHealthy('google', $primaryEmail, $oauthEmail);
        $this->tokenCache->putToken('google', $primaryEmail, $oauthEmail, $newAccessToken, $expiresIn);

        return $newAccessToken;
    }

    /**
     * Best-effort health column update. Silently ignores schema mismatches
     * (legacy DBs without the health columns from migration 151).
     */
    private function markHealth(int $tokenId, string $health, ?string $reason): void
    {
        try {
            $upd = $this->db->prepare('
                UPDATE webmail_oauth_tokens
                SET health = ?, health_reason = ?, health_updated_at = NOW()
                WHERE id = ?
            ');
            $upd->execute([$health, $reason, $tokenId]);
        } catch (\Throwable $e) {
            // Health columns may not exist yet on a legacy DB; ignore.
        }
    }
    
    /**
     * Generate XOAUTH2 string for IMAP/SMTP authentication
     */
    public function getXOAuth2String(string $email, string $accessToken): string
    {
        $authString = "user={$email}\x01auth=Bearer {$accessToken}\x01\x01";
        return base64_encode($authString);
    }
    
    /**
     * Delete OAuth account
     */
    public function deleteOAuthAccount(string $primaryEmail, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM webmail_oauth_tokens WHERE primary_email = ? AND id = ?');
        $stmt->execute([strtolower($primaryEmail), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Update OAuth account settings
     */
    public function updateOAuthAccount(string $primaryEmail, int $id, array $data): ?array
    {
        $fields = [];
        $values = [];
        
        if (isset($data['display_name'])) {
            $fields[] = 'display_name = ?';
            $values[] = $data['display_name'];
        }
        if (isset($data['account_type'])) {
            $fields[] = 'account_type = ?';
            $values[] = $data['account_type'];
        }
        if (isset($data['sync_frequency'])) {
            $fields[] = 'sync_frequency = ?';
            $values[] = (int)$data['sync_frequency'];
        }
        if (isset($data['leave_on_server'])) {
            $fields[] = 'leave_on_server = ?';
            $values[] = $data['leave_on_server'] ? 1 : 0;
        }
        if (isset($data['auto_label'])) {
            $fields[] = 'auto_label = ?';
            $values[] = $data['auto_label'] ?: null;
        }
        if (array_key_exists('signature', $data)) {
            $fields[] = 'signature = ?';
            $values[] = $data['signature'] ?: null;
        }
        if (isset($data['sync_enabled'])) {
            $fields[] = 'sync_enabled = ?';
            $values[] = $data['sync_enabled'] ? 1 : 0;
        }
        
        if (empty($fields)) {
            return $this->getOAuthAccountById($primaryEmail, $id);
        }
        
        $values[] = strtolower($primaryEmail);
        $values[] = $id;
        
        try {
            $stmt = $this->db->prepare('
                UPDATE webmail_oauth_tokens 
                SET ' . implode(', ', $fields) . ' 
                WHERE primary_email = ? AND id = ?
            ');
            $stmt->execute($values);
            
            return $this->getOAuthAccountById($primaryEmail, $id);
        } catch (\PDOException $e) {
            error_log("GoogleOAuthService updateOAuthAccount error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get OAuth account by ID
     */
    public function getOAuthAccountById(string $primaryEmail, int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, primary_email, oauth_email as account_email, provider, display_name,
                   account_type, sync_frequency, leave_on_server, auto_label, signature, sync_enabled,
                   last_sync, created_at, token_expires_at,
                   "oauth" as auth_type
            FROM webmail_oauth_tokens 
            WHERE primary_email = ? AND id = ?
        ');
        $stmt->execute([strtolower($primaryEmail), $id]);
        $account = $stmt->fetch();
        
        if ($account) {
            $account['leave_on_server'] = (bool)$account['leave_on_server'];
            $account['sync_enabled'] = (bool)$account['sync_enabled'];
            $account['imap_host'] = 'imap.gmail.com';
            $account['imap_port'] = 993;
            $account['imap_encryption'] = 'ssl';
            $account['smtp_host'] = 'smtp.gmail.com';
            $account['smtp_port'] = 587;
            $account['smtp_encryption'] = 'tls';
        }
        
        return $account ?: null;
    }
    
    /**
     * HTTP POST helper
     */
    private function httpPost(string $url, array $params): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("GoogleOAuth: cURL error during POST to {$url}: {$curlError}");
            return null;
        }
        
        if ($httpCode !== 200) {
            error_log("GoogleOAuth: HTTP POST to {$url} failed with code {$httpCode}");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Update last sync time
     */
    public function updateLastSync(string $primaryEmail, int $id): void
    {
        $stmt = $this->db->prepare('UPDATE webmail_oauth_tokens SET last_sync = NOW() WHERE primary_email = ? AND id = ?');
        $stmt->execute([strtolower($primaryEmail), $id]);
    }
}

