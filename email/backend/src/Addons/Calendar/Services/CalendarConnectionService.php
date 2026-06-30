<?php

namespace Webmail\Addons\Calendar\Services;

use Webmail\Services\OAuthCryptor;

/**
 * CalendarConnectionService - Handles calendar-only Google connections
 * 
 * Allows syncing Google Calendar without full email OAuth access.
 * Uses reduced OAuth scopes (calendar only, no gmail access).
 *
 * Phase 2.4: token encryption migrated from AES-256-CBC keyed off the JWT
 * secret to AES-256-GCM via OAuthCryptor (same scheme as
 * webmail_oauth_tokens). GCM provides authenticated encryption (tamper
 * detection) and OAuthCryptor supports key versioning for rotation.
 * Legacy CBC-encrypted rows continue to be readable via the in-line CBC
 * fallback below; on next save they get rewritten as GCM envelopes.
 */
class CalendarConnectionService
{
    private array $config;
    /** Full app config; needed for OAuthHealthService */
    private array $fullConfig;
    private \PDO $db;
    /** @deprecated Phase 2.4 - retained only for legacy CBC decryption fallback */
    private string $encryptionKey;
    private OAuthCryptor $cryptor;

    /**
     * Phase 3: classify refresh errors. Mirrors GoogleOAuthService so the
     * cron can tell a transient transport error apart from a terminal
     * invalid_grant.
     */
    public ?string $lastRefreshError = null;

    private const TERMINAL_REFRESH_ERRORS = [
        'invalid_grant',
        'invalid_client',
        'unauthorized_client',
        'invalid_scope',
    ];
    
    private const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const GOOGLE_CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    
    // Calendar-only scopes (no email access)
    private const CALENDAR_SCOPES = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
    ];
    
    public function __construct(array $config)
    {
        $this->config = $config['google_oauth'] ?? [];
        $this->fullConfig = $config;

        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // Legacy CBC key (sha256 of JWT secret) kept ONLY for backwards-
        // compatible reads of rows encrypted before Phase 2.4. New writes
        // go through $this->cryptor.
        $this->encryptionKey = hash('sha256', $config['jwt']['secret'] ?? 'default_key', true);

        // OAuthCryptor handles versioned GCM envelopes plus a separate legacy
        // CBC reader for webmail_oauth_tokens. It does not know our JWT-keyed
        // CBC scheme, so we keep the in-line CBC fallback in decryptToken().
        $this->cryptor = new OAuthCryptor($config);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
    }
    
    private function ensureTablesExist(): void
    {
        try {
            // Calendar-only connections (no email access)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS calendar_connections (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    primary_email VARCHAR(255) NOT NULL COMMENT 'The main login email (owner)',
                    google_email VARCHAR(255) NOT NULL COMMENT 'The Google account email',
                    display_name VARCHAR(255) DEFAULT NULL,
                    access_token_encrypted TEXT NOT NULL,
                    refresh_token_encrypted TEXT NOT NULL,
                    token_expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_primary_email (primary_email),
                    UNIQUE KEY unique_calendar_connection (primary_email, google_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Account history for quick reconnection
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS account_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    primary_email VARCHAR(255) NOT NULL COMMENT 'The main login email (owner)',
                    account_email VARCHAR(255) NOT NULL COMMENT 'The connected account email',
                    account_type ENUM('imap', 'google_oauth', 'microsoft_oauth', 'google_calendar') NOT NULL,
                    display_name VARCHAR(255) DEFAULT NULL,
                    server_settings JSON DEFAULT NULL COMMENT 'IMAP/SMTP settings for quick reconnect',
                    provider VARCHAR(50) DEFAULT NULL COMMENT 'google, microsoft, or provider preset name',
                    disconnected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_primary_email (primary_email),
                    INDEX idx_account_type (account_type),
                    UNIQUE KEY unique_history (primary_email, account_email, account_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log("CalendarConnectionService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Encrypt token for storage (Phase 2.4: AES-256-GCM via OAuthCryptor).
     *
     * Output format is the versioned envelope `v{N}:{base64(iv|ct|tag)}` so
     * decryptToken() can distinguish new GCM rows from legacy CBC rows by
     * the leading `v`. Tampering with ciphertext or IV produces a decrypt
     * failure (no silent garbage).
     */
    private function encryptToken(string $token): string
    {
        return $this->cryptor->encrypt($token);
    }

    /**
     * Decrypt token from storage. Tries GCM first; falls back to the
     * pre-Phase-2.4 CBC scheme (key = sha256(jwt_secret)) so existing rows
     * remain readable until they are re-encrypted on next save.
     */
    private function decryptToken(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        // GCM envelope (new format)
        $plain = $this->cryptor->decrypt($encrypted);
        if ($plain !== null && $plain !== '') {
            return $plain;
        }

        // Legacy CBC fallback (key = sha256(jwt_secret), 16-byte IV prefix).
        // OAuthCryptor's own legacy CBC reader uses a different key
        // (imap_encryption_key), so we cannot delegate this path.
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 17) {
            return '';
        }
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $legacy = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return $legacy === false ? '' : $legacy;
    }
    
    /**
     * Generate OAuth authorization URL for calendar-only access
     * Uses the main Google OAuth redirect_uri (shared with email OAuth)
     */
    public function getAuthorizationUrl(string $state = '', ?string $codeChallenge = null): string
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', self::CALENDAR_SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];
        // Phase 3: PKCE S256.
        if ($codeChallenge !== null && $codeChallenge !== '') {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }
        
        return self::GOOGLE_AUTH_URL . '?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for tokens.
     *
     * Phase 3: PKCE-aware. Pass codeVerifier when one was generated
     * by PKCEService::createChallenge() for this auth flow.
     */
    public function exchangeCodeForTokens(string $code, ?string $codeVerifier = null): ?array
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $params['code_verifier'] = $codeVerifier;
        }
        
        $response = $this->httpPost(self::GOOGLE_TOKEN_URL, $params);
        
        if (!$response || isset($response['error'])) {
            error_log('Calendar OAuth token exchange error: ' . json_encode($response));
            return null;
        }
        
        return $response;
    }
    
    /**
     * Refresh access token
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

        $response = $this->httpPost(self::GOOGLE_TOKEN_URL, $params);

        if (!$response || isset($response['error'])) {
            $this->lastRefreshError = is_array($response)
                ? ($response['error'] ?? 'transport_error')
                : 'transport_error';
            error_log('Calendar OAuth token refresh error: ' . $this->lastRefreshError);
            return null;
        }

        return $response;
    }

    /**
     * True when the most recent refreshAccessToken() failed with an error
     * Google considers terminal (refresh token will never work again).
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
        $ch = curl_init(self::GOOGLE_USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log('Google userinfo error: ' . $response);
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Store calendar connection tokens
     */
    public function storeConnection(string $primaryEmail, array $tokenData, array $userInfo): ?array
    {
        $primaryEmail = strtolower($primaryEmail);
        $googleEmail = strtolower($userInfo['email']);
        $expiresAt = date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600));
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO calendar_connections 
                (primary_email, google_email, display_name, access_token_encrypted, refresh_token_encrypted, token_expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    access_token_encrypted = VALUES(access_token_encrypted),
                    refresh_token_encrypted = COALESCE(VALUES(refresh_token_encrypted), refresh_token_encrypted),
                    token_expires_at = VALUES(token_expires_at),
                    display_name = VALUES(display_name),
                    updated_at = NOW()
            ');
            
            $stmt->execute([
                $primaryEmail,
                $googleEmail,
                $userInfo['name'] ?? null,
                $this->encryptToken($tokenData['access_token']),
                isset($tokenData['refresh_token']) ? $this->encryptToken($tokenData['refresh_token']) : null,
                $expiresAt,
            ]);
            
            return $this->getConnection($primaryEmail, $googleEmail);
            
        } catch (\PDOException $e) {
            error_log("CalendarConnectionService storeConnection error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get a calendar connection
     */
    public function getConnection(string $primaryEmail, string $googleEmail): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, primary_email, google_email, display_name, token_expires_at, created_at
            FROM calendar_connections 
            WHERE primary_email = ? AND google_email = ?
        ');
        $stmt->execute([strtolower($primaryEmail), strtolower($googleEmail)]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get all calendar connections for a user
     */
    public function getConnections(string $primaryEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT id, primary_email, google_email, display_name, token_expires_at, created_at
            FROM calendar_connections 
            WHERE primary_email = ?
            ORDER BY created_at ASC
        ');
        $stmt->execute([strtolower($primaryEmail)]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get connection by ID
     */
    public function getConnectionById(string $primaryEmail, int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, primary_email, google_email, display_name, token_expires_at, created_at
            FROM calendar_connections 
            WHERE primary_email = ? AND id = ?
        ');
        $stmt->execute([strtolower($primaryEmail), $id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get valid access token (refreshes if expired)
     */
    public function getValidAccessToken(string $primaryEmail, string $googleEmail): ?string
    {
        $stmt = $this->db->prepare('
            SELECT access_token_encrypted, refresh_token_encrypted, token_expires_at
            FROM calendar_connections 
            WHERE primary_email = ? AND google_email = ?
        ');
        $stmt->execute([strtolower($primaryEmail), strtolower($googleEmail)]);
        $token = $stmt->fetch();
        
        if (!$token) {
            return null;
        }
        
        // Check if token is expired (with 5 min buffer)
        $expiresAt = strtotime($token['token_expires_at']);
        if (time() > ($expiresAt - 300)) {
            // Token expired, refresh it
            $refreshToken = $this->decryptToken($token['refresh_token_encrypted']);
            $newTokens = $this->refreshAccessToken($refreshToken);

            if (!$newTokens) {
                // Phase 3: terminal errors mark the connection revoked so
                // the cron stops thrashing the token endpoint.
                if ($this->isLastRefreshErrorTerminal()) {
                    $health = new \Webmail\Services\OAuthHealthService($this->fullConfig);
                    $health->markCalendarRevoked($primaryEmail, $googleEmail, $this->lastRefreshError ?? 'invalid_grant');
                }
                return null;
            }

            // Update stored tokens. Phase 3: persist a rotated refresh
            // token if Google issued one (rare for Google but OAuth 2.1
            // requires we support it).
            $newExpiresAt = date('Y-m-d H:i:s', time() + ($newTokens['expires_in'] ?? 3600));
            if (!empty($newTokens['refresh_token'])) {
                $stmt = $this->db->prepare('
                    UPDATE calendar_connections
                    SET access_token_encrypted = ?,
                        refresh_token_encrypted = ?,
                        token_expires_at = ?,
                        updated_at = NOW()
                    WHERE primary_email = ? AND google_email = ?
                ');
                $stmt->execute([
                    $this->encryptToken($newTokens['access_token']),
                    $this->encryptToken($newTokens['refresh_token']),
                    $newExpiresAt,
                    strtolower($primaryEmail),
                    strtolower($googleEmail),
                ]);
            } else {
                $stmt = $this->db->prepare('
                    UPDATE calendar_connections
                    SET access_token_encrypted = ?, token_expires_at = ?, updated_at = NOW()
                    WHERE primary_email = ? AND google_email = ?
                ');
                $stmt->execute([
                    $this->encryptToken($newTokens['access_token']),
                    $newExpiresAt,
                    strtolower($primaryEmail),
                    strtolower($googleEmail),
                ]);
            }

            return $newTokens['access_token'];
        }

        return $this->decryptToken($token['access_token_encrypted']);
    }
    
    /**
     * Get valid access token by connection ID
     */
    public function getValidAccessTokenById(string $primaryEmail, int $connectionId): ?string
    {
        $connection = $this->getConnectionById($primaryEmail, $connectionId);
        if (!$connection) {
            return null;
        }
        return $this->getValidAccessToken($primaryEmail, $connection['google_email']);
    }
    
    /**
     * Delete a calendar connection and archive to history
     */
    public function deleteConnection(string $primaryEmail, int $id): bool
    {
        $connection = $this->getConnectionById($primaryEmail, $id);
        if (!$connection) {
            return false;
        }
        
        // Archive to history before deleting
        $this->archiveToHistory($primaryEmail, $connection['google_email'], 'google_calendar', [
            'display_name' => $connection['display_name'],
        ]);
        
        $stmt = $this->db->prepare('DELETE FROM calendar_connections WHERE primary_email = ? AND id = ?');
        $stmt->execute([strtolower($primaryEmail), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get list of Google Calendars from this connection
     */
    public function getGoogleCalendars(string $primaryEmail, int $connectionId): array
    {
        $accessToken = $this->getValidAccessTokenById($primaryEmail, $connectionId);
        if (!$accessToken) {
            return [];
        }
        
        $response = $this->apiRequest('GET', '/users/me/calendarList', [], $accessToken);
        
        if (!$response || !isset($response['items'])) {
            return [];
        }
        
        return array_map(function($cal) {
            return [
                'id' => $cal['id'],
                'summary' => $cal['summary'] ?? 'Untitled',
                'description' => $cal['description'] ?? '',
                'color' => $cal['backgroundColor'] ?? '#3b82f6',
                'primary' => $cal['primary'] ?? false,
                'accessRole' => $cal['accessRole'] ?? 'reader',
            ];
        }, $response['items']);
    }
    
    /**
     * Make API request to Google Calendar
     */
    private function apiRequest(string $method, string $endpoint, array $params, string $accessToken): ?array
    {
        $url = self::GOOGLE_CALENDAR_API . $endpoint;
        
        $ch = curl_init();
        
        $headers = [
            'Authorization: Bearer ' . $accessToken,
        ];
        
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true) ?: [];
        }
        
        error_log("Google Calendar API error ($httpCode): $response");
        return null;
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            error_log("CalendarConnectionService HTTP error: $error");
            return null;
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode !== 200 || !$decoded) {
            error_log("CalendarConnectionService HTTP response: code=$httpCode body=" . substr($response, 0, 500));
        }
        
        return $decoded;
    }
    
    // ==================== Account History Methods ====================
    
    /**
     * Archive an account to history when disconnected
     */
    public function archiveToHistory(string $primaryEmail, string $accountEmail, string $accountType, array $options = []): bool
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO account_history 
                (primary_email, account_email, account_type, display_name, server_settings, provider, disconnected_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    display_name = VALUES(display_name),
                    server_settings = VALUES(server_settings),
                    provider = VALUES(provider),
                    disconnected_at = NOW()
            ');
            
            $stmt->execute([
                strtolower($primaryEmail),
                strtolower($accountEmail),
                $accountType,
                $options['display_name'] ?? null,
                isset($options['server_settings']) ? json_encode($options['server_settings']) : null,
                $options['provider'] ?? null,
            ]);
            
            return true;
        } catch (\PDOException $e) {
            error_log("CalendarConnectionService archiveToHistory error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get account history for a user
     */
    public function getAccountHistory(string $primaryEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT id, primary_email, account_email, account_type, display_name, 
                   server_settings, provider, disconnected_at
            FROM account_history 
            WHERE primary_email = ?
            ORDER BY disconnected_at DESC
        ');
        $stmt->execute([strtolower($primaryEmail)]);
        
        return array_map(function($row) {
            if ($row['server_settings']) {
                $row['server_settings'] = json_decode($row['server_settings'], true);
            }
            return $row;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get a single history entry
     */
    public function getHistoryEntry(string $primaryEmail, int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, primary_email, account_email, account_type, display_name, 
                   server_settings, provider, disconnected_at
            FROM account_history 
            WHERE primary_email = ? AND id = ?
        ');
        $stmt->execute([strtolower($primaryEmail), $id]);
        $row = $stmt->fetch();
        
        if ($row && $row['server_settings']) {
            $row['server_settings'] = json_decode($row['server_settings'], true);
        }
        
        return $row ?: null;
    }
    
    /**
     * Delete a history entry permanently
     */
    public function deleteHistoryEntry(string $primaryEmail, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM account_history WHERE primary_email = ? AND id = ?');
        $stmt->execute([strtolower($primaryEmail), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Remove from history when account is reconnected
     */
    public function removeFromHistory(string $primaryEmail, string $accountEmail, string $accountType): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM account_history 
            WHERE primary_email = ? AND account_email = ? AND account_type = ?
        ');
        $stmt->execute([strtolower($primaryEmail), strtolower($accountEmail), $accountType]);
        return $stmt->rowCount() > 0;
    }
}

