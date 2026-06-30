<?php

namespace Webmail\Services;

/**
 * MicrosoftOAuthService - Handles Microsoft OAuth 2.0 authentication for Outlook/Microsoft 365
 * 
 * Implements OAuth flow for "Sign in with Microsoft" and XOAUTH2 for IMAP/SMTP
 */
class MicrosoftOAuthService
{
    private array $config;
    private array $fullConfig;
    private \PDO $db;
    private OAuthCryptor $cryptor;
    private OAuthTokenCache $tokenCache;
    private OAuthHealthService $health;
    private ?string $lastFailureReason = null;
    private ?string $lastRefreshError = null;
    
    // Microsoft OAuth endpoints (using "common" for multi-tenant support)
    private const MS_AUTH_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const MS_TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const MS_USERINFO_URL = 'https://graph.microsoft.com/v1.0/me';
    
    // Microsoft/Outlook server settings
    public const IMAP_HOST = 'outlook.office365.com';
    public const IMAP_PORT = 993;
    public const IMAP_ENCRYPTION = 'ssl';
    public const SMTP_HOST = 'smtp-mail.outlook.com';
    public const SMTP_PORT = 587;
    public const SMTP_ENCRYPTION = 'tls';

    /**
     * OAuth 2.0 error codes that mean the refresh token is dead. Do not retry.
     */
    private const TERMINAL_REFRESH_ERRORS = [
        'invalid_grant',
        'invalid_client',
        'unauthorized_client',
        'consent_required',
        'interaction_required',
    ];
    
    public function __construct(array $config, ?OAuthTokenCache $tokenCache = null)
    {
        $this->fullConfig = $config;
        $this->config = $config['microsoft_oauth'] ?? [];
        
        $this->db = \Webmail\Core\Database::getConnection($config);

        $this->cryptor = new OAuthCryptor($config);
        $this->tokenCache = $tokenCache ?? new OAuthTokenCache($config);
        $this->health = new OAuthHealthService($config, $this->tokenCache);
        
        $this->ensureTableExists();
    }
    
    private function ensureTableExists(): void
    {
        // Uses the same table as Google OAuth (webmail_oauth_tokens) with provider='microsoft'
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_oauth_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    primary_email VARCHAR(255) NOT NULL COMMENT 'The main login email (owner)',
                    oauth_email VARCHAR(255) NOT NULL COMMENT 'The OAuth account email',
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
        } catch (\PDOException $e) {
            error_log("MicrosoftOAuthService table creation error: " . $e->getMessage());
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
     */
    public function getAuthorizationUrl(
        string $state = '',
        ?string $redirectUri = null,
        ?string $loginHint = null,
        ?string $prompt = null,
        ?string $codeChallenge = null
    ): string
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $redirectUri ?? $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $this->config['scopes']),
            'response_mode' => 'query',
            'prompt' => $prompt ?? 'consent', // Force consent to get refresh token
            'state' => $state,
        ];

        if ($loginHint) {
            $params['login_hint'] = $loginHint;
        }

        // Phase 3: PKCE S256. Microsoft Identity Platform supports PKCE
        // on both v1 and v2 endpoints; required by OAuth 2.1.
        if ($codeChallenge !== null && $codeChallenge !== '') {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return self::MS_AUTH_URL . '?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for tokens.
     *
     * Phase 3: PKCE-aware variant. Pass codeVerifier when one was
     * generated by PKCEService::createChallenge() for this auth flow.
     */
    public function exchangeCodeForTokens(string $code, ?string $redirectUri = null, ?string $codeVerifier = null): ?array
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $redirectUri ?? $this->config['redirect_uri'],
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $params['code_verifier'] = $codeVerifier;
        }
        
        $response = $this->httpPost(self::MS_TOKEN_URL, $params);
        
        if (!$response || isset($response['error'])) {
            error_log('Microsoft OAuth token exchange error: ' . json_encode($response));
            return null;
        }
        
        return $response;
    }
    
    /**
     * Refresh access token using refresh token
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $this->lastRefreshError = null;

        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => implode(' ', $this->config['scopes']),
        ];

        $response = $this->httpPost(self::MS_TOKEN_URL, $params);

        if (!$response || isset($response['error'])) {
            $this->lastRefreshError = $response['error'] ?? 'transport_error';
            error_log('Microsoft OAuth token refresh error: ' . json_encode($response));
            return null;
        }

        return $response;
    }

    public function getLastRefreshError(): ?string
    {
        return $this->lastRefreshError;
    }

    public function isLastRefreshErrorTerminal(): bool
    {
        return $this->lastRefreshError !== null
            && in_array($this->lastRefreshError, self::TERMINAL_REFRESH_ERRORS, true);
    }
    
    /**
     * Get user info from Microsoft Graph
     */
    public function getUserInfo(string $accessToken): ?array
    {
        $ch = curl_init(self::MS_USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log('Microsoft userinfo error: ' . $response);
            return null;
        }
        
        $data = json_decode($response, true);
        
        // Normalize response to match Google format
        return [
            'id' => $data['id'] ?? null,
            'email' => $data['mail'] ?? $data['userPrincipalName'] ?? null,
            'name' => $data['displayName'] ?? null,
            'given_name' => $data['givenName'] ?? null,
            'family_name' => $data['surname'] ?? null,
        ];
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
                'microsoft',
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
            error_log("MicrosoftOAuthService storeTokens error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Store OAuth tokens for login (primary email = oauth email)
     * Login tokens have minimal scopes -- no IMAP/SMTP access.
     * Refresh token is intentionally NOT stored so the IMAP layer
     * falls back to full-scope tokens from the "add account" flow.
     */
    public function storeTokensForLogin(string $microsoftEmail, array $tokenData, array $userInfo): bool
    {
        $microsoftEmail = strtolower($microsoftEmail);
        $expiresAt = date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600));
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_oauth_tokens 
                (primary_email, oauth_email, provider, access_token_encrypted, refresh_token_encrypted, 
                 token_expires_at, display_name, account_type, sync_frequency, leave_on_server, sync_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    access_token_encrypted = VALUES(access_token_encrypted),
                    refresh_token_encrypted = VALUES(refresh_token_encrypted),
                    token_expires_at = VALUES(token_expires_at),
                    display_name = VALUES(display_name),
                    health = 'healthy',
                    health_reason = NULL,
                    health_updated_at = NOW(),
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                $microsoftEmail,
                $microsoftEmail,
                'microsoft',
                $this->encryptToken($tokenData['access_token']),
                '',
                $expiresAt,
                $userInfo['name'] ?? explode('@', $microsoftEmail)[0],
                'separate',
                15,
                1,
                1,
            ]);
            
            return true;
            
        } catch (\PDOException $e) {
            error_log("MicrosoftOAuthService storeTokensForLogin error: " . $e->getMessage());
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
            WHERE primary_email = ? AND oauth_email = ? AND provider = ?
        ');
        $stmt->execute([strtolower($primaryEmail), strtolower($oauthEmail), 'microsoft']);
        $account = $stmt->fetch();
        
        if ($account) {
            $account['leave_on_server'] = (bool)$account['leave_on_server'];
            $account['sync_enabled'] = (bool)$account['sync_enabled'];
            // Add Outlook server settings
            $account['imap_host'] = self::IMAP_HOST;
            $account['imap_port'] = self::IMAP_PORT;
            $account['imap_encryption'] = self::IMAP_ENCRYPTION;
            $account['smtp_host'] = self::SMTP_HOST;
            $account['smtp_port'] = self::SMTP_PORT;
            $account['smtp_encryption'] = self::SMTP_ENCRYPTION;
        }
        
        return $account ?: null;
    }
    
    /**
     * Get all Microsoft OAuth accounts for a user
     */
    public function getOAuthAccounts(string $primaryEmail, ?string $accountType = null): array
    {
        $sql = '
            SELECT id, primary_email, oauth_email as account_email, provider, display_name,
                   account_type, sync_frequency, leave_on_server, auto_label, signature, sync_enabled,
                   last_sync, created_at, token_expires_at,
                   "oauth" as auth_type
            FROM webmail_oauth_tokens 
            WHERE primary_email = ? AND provider = ?
        ';
        $params = [strtolower($primaryEmail), 'microsoft'];
        
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
            // Add Outlook server settings
            $acc['imap_host'] = self::IMAP_HOST;
            $acc['imap_port'] = self::IMAP_PORT;
            $acc['imap_encryption'] = self::IMAP_ENCRYPTION;
            $acc['smtp_host'] = self::SMTP_HOST;
            $acc['smtp_port'] = self::SMTP_PORT;
            $acc['smtp_encryption'] = self::SMTP_ENCRYPTION;
            return $acc;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get valid access token (Phase 1 of OAuth rewrite).
     *
     * Resolution: Redis cache hit -> Redis revoked flag -> DB read -> single-flight
     * refresh via Redis mutex -> mark revoked on terminal error.
     * See GoogleOAuthService::getValidAccessToken() for the rationale.
     */
    public function getValidAccessToken(string $primaryEmail, string $oauthEmail): ?string
    {
        $this->lastFailureReason = null;
        $primaryEmail = strtolower($primaryEmail);
        $oauthEmail = strtolower($oauthEmail);

        $cached = $this->tokenCache->getToken('microsoft', $primaryEmail, $oauthEmail);
        if ($cached !== null) {
            return $cached;
        }

        if ($this->tokenCache->isRevoked('microsoft', $primaryEmail, $oauthEmail)) {
            $this->lastFailureReason = 'oauth_revoked';
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT id, access_token_encrypted, refresh_token_encrypted, token_expires_at, health
            FROM webmail_oauth_tokens 
            WHERE primary_email = ? AND oauth_email = ? AND provider = ?
        ');
        $stmt->execute([$primaryEmail, $oauthEmail, 'microsoft']);
        $token = $stmt->fetch();

        if (!$token) {
            $this->lastFailureReason = 'oauth_no_account';
            return null;
        }

        if (!empty($token['health']) && $token['health'] === 'revoked') {
            $this->tokenCache->markRevoked('microsoft', $primaryEmail, $oauthEmail, 'db_revoked');
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
            $this->tokenCache->putToken('microsoft', $primaryEmail, $oauthEmail, $accessToken, $ttl);
            return $accessToken;
        }

        return $this->refreshWithSingleFlight($primaryEmail, $oauthEmail, $token);
    }

    private function refreshWithSingleFlight(string $primaryEmail, string $oauthEmail, array $tokenRow): ?string
    {
        if (!$this->tokenCache->acquireRefreshLock('microsoft', $primaryEmail, $oauthEmail)) {
            $refreshed = $this->tokenCache->waitForRefreshedToken('microsoft', $primaryEmail, $oauthEmail);
            if ($refreshed !== null) {
                return $refreshed;
            }
            $this->tokenCache->releaseRefreshLock('microsoft', $primaryEmail, $oauthEmail);
            if (!$this->tokenCache->acquireRefreshLock('microsoft', $primaryEmail, $oauthEmail)) {
                $this->lastFailureReason = 'oauth_refresh_contention';
                return null;
            }
        }

        try {
            return $this->doRefresh($primaryEmail, $oauthEmail, $tokenRow);
        } finally {
            $this->tokenCache->releaseRefreshLock('microsoft', $primaryEmail, $oauthEmail);
        }
    }

    private function doRefresh(string $primaryEmail, string $oauthEmail, array $tokenRow): ?string
    {
        if (empty($tokenRow['refresh_token_encrypted'])) {
            $this->lastFailureReason = 'oauth_revoked';
            $this->health->markRevoked('microsoft', $primaryEmail, $oauthEmail, 'missing_refresh_token');
            error_log("MicrosoftOAuth: Cannot refresh - no refresh token stored for {$oauthEmail}");
            return null;
        }

        $refreshToken = $this->decryptToken($tokenRow['refresh_token_encrypted']);
        if ($refreshToken === '') {
            $this->lastFailureReason = 'oauth_decrypt_failed';
            $this->markHealth((int)$tokenRow['id'], 'broken', 'decrypt_failed');
            error_log("MicrosoftOAuth: Cannot refresh - refresh token decryption failed for {$oauthEmail}");
            return null;
        }

        $newTokens = $this->refreshAccessToken($refreshToken);

        if (!$newTokens) {
            $isTerminal = $this->isLastRefreshErrorTerminal();
            $errorCode = $this->lastRefreshError ?? 'refresh_failed';

            if ($isTerminal) {
                $this->lastFailureReason = 'oauth_revoked';
                $this->health->markRevoked('microsoft', $primaryEmail, $oauthEmail, $errorCode);
            } else {
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
            WHERE primary_email = ? AND oauth_email = ? AND provider = ?
        ');
        $upd->execute([
            $this->encryptToken($newAccessToken),
            $newExpiresAt,
            $primaryEmail,
            $oauthEmail,
            'microsoft',
        ]);

        // Microsoft always rotates refresh tokens on refresh - persist the new one.
        if (!empty($newTokens['refresh_token'])) {
            $rot = $this->db->prepare('
                UPDATE webmail_oauth_tokens 
                SET refresh_token_encrypted = ?
                WHERE primary_email = ? AND oauth_email = ? AND provider = ?
            ');
            $rot->execute([
                $this->encryptToken($newTokens['refresh_token']),
                $primaryEmail,
                $oauthEmail,
                'microsoft',
            ]);
        }

        $this->health->markHealthy('microsoft', $primaryEmail, $oauthEmail);
        $this->tokenCache->putToken('microsoft', $primaryEmail, $oauthEmail, $newAccessToken, $expiresIn);

        return $newAccessToken;
    }

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
        $stmt = $this->db->prepare('DELETE FROM webmail_oauth_tokens WHERE primary_email = ? AND id = ? AND provider = ?');
        $stmt->execute([strtolower($primaryEmail), $id, 'microsoft']);
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
                WHERE primary_email = ? AND id = ? AND provider = "microsoft"
            ');
            $stmt->execute($values);
            
            return $this->getOAuthAccountById($primaryEmail, $id);
        } catch (\PDOException $e) {
            error_log("MicrosoftOAuthService updateOAuthAccount error: " . $e->getMessage());
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
            WHERE primary_email = ? AND id = ? AND provider = ?
        ');
        $stmt->execute([strtolower($primaryEmail), $id, 'microsoft']);
        $account = $stmt->fetch();
        
        if ($account) {
            $account['leave_on_server'] = (bool)$account['leave_on_server'];
            $account['sync_enabled'] = (bool)$account['sync_enabled'];
            $account['imap_host'] = self::IMAP_HOST;
            $account['imap_port'] = self::IMAP_PORT;
            $account['imap_encryption'] = self::IMAP_ENCRYPTION;
            $account['smtp_host'] = self::SMTP_HOST;
            $account['smtp_port'] = self::SMTP_PORT;
            $account['smtp_encryption'] = self::SMTP_ENCRYPTION;
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
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("HTTP POST to $url failed with code $httpCode: $response");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Update last sync time
     */
    public function updateLastSync(string $primaryEmail, int $id): void
    {
        $stmt = $this->db->prepare('UPDATE webmail_oauth_tokens SET last_sync = NOW() WHERE primary_email = ? AND id = ? AND provider = ?');
        $stmt->execute([strtolower($primaryEmail), $id, 'microsoft']);
    }
    
    /**
     * Check if service is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->config['client_id']) && !empty($this->config['client_secret']);
    }
}

