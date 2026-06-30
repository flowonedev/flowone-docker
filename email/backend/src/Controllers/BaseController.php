<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\ImapService;
use Webmail\Services\SessionService;
use Webmail\Services\GoogleOAuthService;
use Webmail\Services\MicrosoftOAuthService;
use Webmail\Services\FolderInputResolver;
use Webmail\Services\DualWriteTelemetry;
use Webmail\Services\RedisCacheService;
use Webmail\Services\StructuredLog;

abstract class BaseController
{
    protected array $config;
    protected SessionService $session;
    protected ?ImapService $imap = null;
    protected ?string $userEmail = null;
    protected ?string $primaryUserEmail = null; // Original JWT email, never overwritten by account switching
    protected ?string $userPassword = null;
    protected bool $isOAuthSession = false;
    protected ?string $oauthProvider = null;
    protected ?GoogleOAuthService $googleOAuthService = null;
    protected ?MicrosoftOAuthService $microsoftOAuthService = null;
    protected ?string $lastImapFailureReason = null;
    protected ?string $lastImapFailureProvider = null;
    protected ?string $lastImapFailureAccountEmail = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->session = new SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
        
        // Early token extraction so child constructors can access userEmail for service init.
        // The full session validation (device status, session password from DB) happens
        // later in requireAuth()/getUser() when a Request object is available.
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            try {
                $payload = $this->session->validateToken($matches[1]);
                if ($payload && ($payload['type'] ?? '') === 'access') {
                    $this->userEmail = $payload['sub'] ?? null;
                    $this->primaryUserEmail = $this->userEmail;
                    if (isset($payload['pwd'])) {
                        try {
                            $this->userPassword = $this->session->decryptPassword($payload['pwd']);
                        } catch (\Exception $e) {
                            error_log('BaseController: pwd decrypt error in constructor: ' . $e->getMessage());
                        }
                    }
                    if (!empty($payload['oauth'])) {
                        $this->isOAuthSession = true;
                        $this->oauthProvider = $payload['provider'] ?? 'google';
                    }
                }
            } catch (\Exception $e) {
                // Token invalid or expired - will be handled by requireAuth() later
            }
        }
        
        // Initialize Google OAuth if configured
        if (!empty($config['google_oauth']['client_id'])) {
            $this->googleOAuthService = new GoogleOAuthService($config);
        }
        
        // Initialize Microsoft OAuth if configured
        if (!empty($config['microsoft_oauth']['client_id'])) {
            $this->microsoftOAuthService = new MicrosoftOAuthService($config);
        }
    }

    /**
     * Validate required fields
     */
    protected function validateRequired(Request $request, array $fields): ?Response
    {
        $missing = [];
        foreach ($fields as $field) {
            if ($request->input($field) === null || $request->input($field) === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return Response::error('Missing required fields: ' . implode(', ', $missing), 400);
        }

        return null;
    }

    /**
     * Get authenticated user email
     */
    protected function getUser(Request $request): ?string
    {
        if ($this->userEmail) {
            // Email already known (from constructor JWT extraction), but password
            // might not be loaded yet since we moved password storage to DB.
            // Load it from the session tracking table if missing.
            if (!$this->userPassword && !$this->isOAuthSession) {
                $sessionToken = $request->getHeader('X-Session-Token');
                if ($sessionToken) {
                    try {
                        $sessionTracker = new \Webmail\Services\SessionTrackingService($this->config);
                        $encryptedPwd = $sessionTracker->getSessionPassword($this->userEmail, $sessionToken);
                        if ($encryptedPwd) {
                            $this->userPassword = $this->session->decryptPassword($encryptedPwd);
                        }
                    } catch (\Exception $e) {
                        error_log('Session password lookup error: ' . $e->getMessage());
                    }
                }
            }
            return $this->userEmail;
        }

        $token = $request->getBearerToken();
        if (!$token) {
            return null;
        }

        $payload = $this->session->validateToken($token);
        if (!$payload || ($payload['type'] ?? '') !== 'access') {
            return null;
        }

        $this->userEmail = $payload['sub'] ?? null;
        $this->primaryUserEmail = $this->userEmail; // Preserve original JWT email
        
        // Backward compat: if old JWT still carries pwd, use it
        if (isset($payload['pwd'])) {
            $this->userPassword = $this->session->decryptPassword($payload['pwd']);
        }
        
        // New approach: look up encrypted password from the session row in DB
        // This is the preferred path - password is server-side only, not in JWT
        if (!$this->userPassword && $this->userEmail) {
            $sessionToken = $request->getHeader('X-Session-Token');
            if ($sessionToken) {
                try {
                    $sessionTracker = new \Webmail\Services\SessionTrackingService($this->config);
                    $encryptedPwd = $sessionTracker->getSessionPassword($this->userEmail, $sessionToken);
                    if ($encryptedPwd) {
                        $this->userPassword = $this->session->decryptPassword($encryptedPwd);
                    }
                } catch (\Exception $e) {
                    error_log('Session password lookup error: ' . $e->getMessage());
                }
            }
        }
        
        // Check if this is an OAuth session
        if (!empty($payload['oauth'])) {
            $this->isOAuthSession = true;
            $this->oauthProvider = $payload['provider'] ?? 'google';
        }

        return $this->userEmail;
    }

    /**
     * Get IMAP connection for current user or selected account
     */
    protected function getImap(Request $request): ?ImapService
    {
        if ($this->imap && $this->imap->isConnected()) {
            return $this->imap;
        }

        $this->lastImapFailureReason = null;
        $this->lastImapFailureProvider = $this->oauthProvider;
        $this->lastImapFailureAccountEmail = null;

        $email = $this->getUser($request);
        if (!$email) {
            return null;
        }
        
        // Check if using a secondary account
        $accountId = $_SERVER['HTTP_X_ACCOUNT_ID'] ?? null;
        if ($accountId && $accountId !== 'primary') {
            return $this->getImapForAccount((int)$accountId, $email);
        }

        // Check if user logged in via OAuth (no password)
        if (!$this->userPassword) {
            error_log("getImap: No password, checking for OAuth connection for {$email}, isOAuthSession={$this->isOAuthSession}, provider={$this->oauthProvider}");
            // Try to connect using OAuth tokens if available
            $oauthAccount = $this->getOAuthAccountByEmail($email);
            if ($oauthAccount) {
                return $this->connectOAuthAccount($oauthAccount, $email);
            }
            error_log("getImap: No OAuth account found for {$email}");
            $this->lastImapFailureReason = 'oauth_no_account';
            return null;
        }

        $this->imap = new ImapService($this->config['imap']);
        if (!$this->imap->connect($email, $this->userPassword)) {
            $this->imap = null;
            return null;
        }

        return $this->imap;
    }
    
    /**
     * Get OAuth account by email (for users who logged in with Google or Microsoft)
     * 
     * First checks for a self-referencing row (primary_email = oauth_email = logged-in email).
     * If that has no refresh token (login-only scopes), falls back to ANY row for this
     * oauth_email that DOES have a refresh token (e.g. added by another user with full scopes).
     */
    protected function getOAuthAccountByEmail(string $email): ?array
    {
        error_log("getOAuthAccountByEmail: Looking for OAuth account for email: {$email}, provider hint: {$this->oauthProvider}");
        
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $emailLower = strtolower($email);
            
            // 1) Try self-referencing row first (primary_email = oauth_email)
            if ($this->oauthProvider) {
                $stmt = $db->prepare('
                    SELECT id, primary_email, oauth_email as account_email, display_name, provider,
                           refresh_token_encrypted
                    FROM webmail_oauth_tokens 
                    WHERE primary_email = ? AND oauth_email = ? AND provider = ?
                ');
                $stmt->execute([$emailLower, $emailLower, $this->oauthProvider]);
            } else {
                $stmt = $db->prepare('
                    SELECT id, primary_email, oauth_email as account_email, display_name, provider,
                           refresh_token_encrypted
                    FROM webmail_oauth_tokens 
                    WHERE primary_email = ? AND oauth_email = ?
                ');
                $stmt->execute([$emailLower, $emailLower]);
            }
            
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // If found AND has a refresh token, use it
            if ($result && !empty($result['refresh_token_encrypted'])) {
                unset($result['refresh_token_encrypted']);
                error_log("getOAuthAccountByEmail: Found self-ref OAuth account with refresh token: " . json_encode($result));
                return $result;
            }
            
            // 2) Fallback: find ANY token row for this oauth_email that has a refresh token
            //    (e.g. added as a secondary account by another user with full mail scopes)
            if ($this->oauthProvider) {
                $stmt = $db->prepare('
                    SELECT id, primary_email, oauth_email as account_email, display_name, provider, refresh_token_encrypted
                    FROM webmail_oauth_tokens 
                    WHERE oauth_email = ?
                      AND provider = ?
                      AND refresh_token_encrypted IS NOT NULL AND refresh_token_encrypted != ""
                      AND (health = \'healthy\' OR health IS NULL)
                    ORDER BY updated_at DESC
                    LIMIT 1
                ');
                $stmt->execute([$emailLower, $this->oauthProvider]);
            } else {
                $stmt = $db->prepare('
                    SELECT id, primary_email, oauth_email as account_email, display_name, provider, refresh_token_encrypted
                    FROM webmail_oauth_tokens 
                    WHERE oauth_email = ?
                      AND refresh_token_encrypted IS NOT NULL AND refresh_token_encrypted != ""
                      AND (health = \'healthy\' OR health IS NULL)
                    ORDER BY updated_at DESC
                    LIMIT 1
                ');
                $stmt->execute([$emailLower]);
            }
            
            $fallback = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($fallback) {
                // Validate that we can actually decrypt the refresh token.
                // This prevents a broken row (wrong key / corrupted ciphertext)
                // from hijacking the flow and causing opaque 503s.
                $cryptor = new \Webmail\Services\OAuthCryptor($this->config);
                $refreshToken = $cryptor->decrypt($fallback['refresh_token_encrypted'] ?? null);
                if ($refreshToken === null || $refreshToken === '') {
                    try {
                        $upd = $db->prepare('
                            UPDATE webmail_oauth_tokens
                            SET health = \'broken\',
                                health_reason = \'decrypt_failed\',
                                health_updated_at = NOW()
                            WHERE id = ?
                        ');
                        $upd->execute([(int)$fallback['id']]);
                    } catch (\Throwable $e) {
                        // Ignore if health columns are not present (should be covered by migration)
                    }
                    return null;
                }

                unset($fallback['refresh_token_encrypted']);
                error_log("getOAuthAccountByEmail: Found fallback OAuth account (owner: {$fallback['primary_email']}): " . json_encode($fallback));
                return $fallback;
            }
            
            // 3) Return self-ref row even without refresh token (login-only tokens, no IMAP)
            if ($result) {
                unset($result['refresh_token_encrypted']);
                error_log("getOAuthAccountByEmail: Found self-ref OAuth account (no refresh token - login only): " . json_encode($result));
                return $result;
            }
            
            error_log("getOAuthAccountByEmail: No OAuth account found for {$email}");
            return null;
            
        } catch (\PDOException $e) {
            error_log("getOAuthAccountByEmail error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get IMAP connection for a secondary account
     */
    protected function getImapForAccount(int $accountId, string $ownerEmail): ?ImapService
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            // First check for OAuth accounts (Google or Microsoft)
            $oauthAccount = $this->getOAuthAccountById($accountId, $ownerEmail);
            if ($oauthAccount) {
                return $this->connectOAuthAccount($oauthAccount, $ownerEmail);
            }
            
            // Get account credentials from webmail_accounts table
            $stmt = $db->prepare('
                SELECT * FROM webmail_accounts 
                WHERE id = ? AND primary_email = ?
            ');
            $stmt->execute([$accountId, strtolower($ownerEmail)]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$account) {
                error_log("BaseController: Account not found - ID: $accountId, Owner: $ownerEmail");
                return null;
            }
            
            // Decrypt password using the same method as AccountService
            $password = $this->decryptAccountPassword($account['credentials_encrypted']);
            if (!$password) {
                error_log("BaseController: Failed to decrypt password for account ID: $accountId");
                return null;
            }
            
            // Create IMAP config for this account
            $imapConfig = [
                'host' => $account['imap_host'],
                'port' => (int)$account['imap_port'],
                'encryption' => $account['imap_encryption'],
                'validate_cert' => false,
            ];
            
            error_log("BaseController: Connecting to secondary account {$account['account_email']} via {$account['imap_host']}:{$account['imap_port']}");
            
            $this->imap = new ImapService($imapConfig);
            if (!$this->imap->connect($account['account_email'], $password)) {
                error_log("BaseController: IMAP connection failed for {$account['account_email']}");
                $this->imap = null;
                return null;
            }
            
            // Update userEmail to reflect the connected account
            $this->userEmail = $account['account_email'];
            
            error_log("BaseController: Successfully connected to secondary account {$account['account_email']}");
            return $this->imap;
        } catch (\Exception $e) {
            error_log("BaseController getImapForAccount error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get OAuth account by ID (checks both Google and Microsoft)
     */
    protected function getOAuthAccountById(int $accountId, string $ownerEmail): ?array
    {
        // Try Google OAuth first
        if ($this->googleOAuthService) {
            $account = $this->googleOAuthService->getOAuthAccountById($ownerEmail, $accountId);
            if ($account) {
                return $account;
            }
        }
        
        // Try Microsoft OAuth
        if ($this->microsoftOAuthService) {
            $account = $this->microsoftOAuthService->getOAuthAccountById($ownerEmail, $accountId);
            if ($account) {
                return $account;
            }
        }
        
        return null;
    }
    
    /**
     * Connect to an OAuth account via IMAP (Google or Microsoft)
     */
    protected function connectOAuthAccount(array $account, string $ownerEmail): ?ImapService
    {
        $accountEmail = $account['account_email'];
        $provider = $account['provider'] ?? 'google';
        $tokenOwner = $account['primary_email'] ?? $ownerEmail;
        
        error_log("BaseController: Connecting to OAuth account {$accountEmail} (provider: {$provider}, token owner: {$tokenOwner})");
        
        $accessToken = null;
        $imapConfig = null;
        
        if ($provider === 'microsoft') {
            if (!$this->microsoftOAuthService) {
                error_log("BaseController: Microsoft OAuth service not available");
                return null;
            }
            
            $accessToken = $this->microsoftOAuthService->getValidAccessToken($tokenOwner, $accountEmail);
            
            $imapConfig = [
                'host' => MicrosoftOAuthService::IMAP_HOST,
                'port' => MicrosoftOAuthService::IMAP_PORT,
                'encryption' => MicrosoftOAuthService::IMAP_ENCRYPTION,
                'validate_cert' => false,
            ];
        } else {
            if (!$this->googleOAuthService) {
                error_log("BaseController: Google OAuth service not available");
                return null;
            }
            
            $accessToken = $this->googleOAuthService->getValidAccessToken($tokenOwner, $accountEmail);
            
            $imapConfig = [
                'host' => 'imap.gmail.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => false,
            ];
        }
        
        if (!$accessToken) {
            error_log("BaseController: Failed to get access token for OAuth account {$accountEmail}");
            $this->lastImapFailureProvider = $provider;
            $this->lastImapFailureAccountEmail = $accountEmail;

            if ($provider === 'microsoft' && $this->microsoftOAuthService) {
                $this->lastImapFailureReason = $this->microsoftOAuthService->getLastFailureReason() ?? 'oauth_revoked';
            } else {
                $this->lastImapFailureReason = $this->googleOAuthService?->getLastFailureReason() ?? 'oauth_revoked';
            }
            return null;
        }
        
        $this->imap = new ImapService($imapConfig);
        
        // Use OAuth connection method
        if (!$this->imap->connectWithOAuth($accountEmail, $accessToken)) {
            error_log("BaseController: OAuth IMAP connection failed for {$accountEmail}");
            $this->imap = null;
            $this->lastImapFailureProvider = $provider;
            $this->lastImapFailureAccountEmail = $accountEmail;
            $this->lastImapFailureReason = 'oauth_xoauth2_failed';
            return null;
        }
        
        // Update userEmail to reflect the connected account
        $this->userEmail = $accountEmail;
        
        error_log("BaseController: Successfully connected to OAuth account {$accountEmail}");
        return $this->imap;
    }
    
    /**
     * Decrypt account password (must match AccountService encryption)
     */
    private function decryptAccountPassword(string $encrypted): ?string
    {
        // Use the same key derivation as AccountService
        $key = hash('sha256', $this->config['jwt']['secret'] ?? 'default_key', true);

        // New GCM format: "gcm:<base64(iv + tag + ciphertext)>"
        if (str_starts_with($encrypted, 'gcm:')) {
            $data = base64_decode(substr($encrypted, 4), true);
            if ($data === false || strlen($data) < 28) { // 12 (iv) + 16 (tag) = 28 minimum
                return null;
            }
            $iv = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $ciphertext = substr($data, 28);
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $decrypted !== false ? $decrypted : null;
        }

        // Legacy CBC format
        $data = base64_decode($encrypted);
        if ($data === false || strlen($data) < 17) {
            return null;
        }
        
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Require authentication with valid session (includes password or OAuth)
     * Also performs stateful session validation (device status, session revocation)
     */
    protected function requireAuth(Request $request): ?Response
    {
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }

        // Suspended accounts must be logged out immediately. The panel "Suspend"
        // button only blocks Dovecot logins, so an already-open webmail session
        // would otherwise survive (with a broken/empty mailbox because its IMAP
        // reconnects fail) until the JWT expired. Refusing here turns the next
        // API call into a 401 the frontend treats as an instant logout.
        if ($this->isAccountSuspended($this->userEmail)) {
            return Response::json([
                'success' => false,
                'message' => 'Account suspended',
                'action' => 'logout',
                'reason' => 'account_suspended',
            ], 401);
        }

        // Note: We intentionally do NOT check for password here.
        // Many endpoints (notifications, chat, settings, etc.) only need DB access, not IMAP.
        // The password check is handled by requireImap() for mail-specific endpoints.
        // This prevents false logouts when session password lookup has transient issues.

        // Stateful session validation - check session token and device status
        $sessionToken = $request->getHeader('X-Session-Token');
        $deviceId = $request->getHeader('X-Device-Id');

        if ($sessionToken) {
            try {
                $deviceService = new \Webmail\Services\DeviceService($this->config);
                $validation = $deviceService->validateSession($this->userEmail, $sessionToken, $deviceId);

                if (!$validation['valid']) {
                    $action = $validation['action'] ?? 'logout';
                    $reason = $validation['reason'] ?? 'session_invalid';

                    return Response::json([
                        'success' => false,
                        'message' => 'Session is no longer valid',
                        'action' => $action,
                        'reason' => $reason,
                    ], 401);
                }
            } catch (\Exception $e) {
                // If device service fails (e.g., DB issue), allow request to continue
                // Better to be available than to lock everyone out
                error_log('Device validation error: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Whether the given mailbox has been suspended from the panel
     * (mail_accounts.login_suspended = 1). Fail-open: if the column or table is
     * unreachable we never lock a user out over an infrastructure hiccup.
     */
    protected function isAccountSuspended(?string $email): bool
    {
        if (empty($email)) {
            return false;
        }

        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare("SELECT login_suspended FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
            $stmt->execute([strtolower($email)]);
            $suspended = $stmt->fetchColumn();
            return $suspended !== false && (int)$suspended === 1;
        } catch (\Throwable $e) {
            error_log('Suspension check error for ' . $email . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Require IMAP connection
     */
    protected function requireImap(Request $request): ?Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        // If no password and not OAuth, the session can't connect to IMAP
        // This is a "ghost session" - tell frontend to re-login
        if (!$this->userPassword && !$this->isOAuthSession) {
            return Response::json([
                'success' => false,
                'message' => 'Session expired. Please log in again.',
                'action' => 'logout',
                'reason' => 'no_password',
            ], 401);
        }

        if (!$this->getImap($request)) {
            $reason = $this->lastImapFailureReason;
            if (in_array($reason, ['oauth_decrypt_failed', 'oauth_revoked', 'oauth_xoauth2_failed', 'oauth_no_account'], true)) {
                return Response::json([
                    'success' => false,
                    'message' => 'OAuth re-authentication required',
                    'action' => 'oauth_reauth_required',
                    'reason' => $reason,
                    'provider' => $this->lastImapFailureProvider ?? $this->oauthProvider ?? 'google',
                    'account_email' => $this->lastImapFailureAccountEmail ?? $this->userEmail,
                ], 401);
            }

            return Response::error('Could not connect to mail server', 503);
        }

        return null;
    }
    
    /**
     * Get the active account email (primary or secondary)
     * Checks X-Account-Id header for multi-account support
     * Should be called after getUser() or requireAuth() since those set userEmail
     */
    protected function getActiveEmail(): string
    {
        $accountId = $_SERVER['HTTP_X_ACCOUNT_ID'] ?? null;
        
        if ($accountId && $accountId !== 'primary' && $this->userEmail) {
            $secondaryEmail = $this->getSecondaryAccountEmail((int)$accountId);
            if ($secondaryEmail) {
                return $secondaryEmail;
            }
        }
        
        return $this->userEmail;
    }
    
    /**
     * Folder-routing helper.
     *
     * Resolves a folder input (either a UUIDv7 folder_id or an IMAP
     * path string) to the path string that the rest of the controller
     * code uses. On a sampled fraction of requests it also runs the
     * compare-mode resolver (regression guard for identity drift).
     *
     * Reads in this order:
     *   1. $request->getParam('folder_id')   (canonical /folders/...)
     *   2. $request->getParam('folder')      (path-shaped, e.g. raw IMAP path)
     *
     * Returns the IMAP folder path. Returns null when no input was
     * supplied or when a folder_id was supplied but no identity row
     * matches the active account; the caller should treat null as 404.
     *
     * Side-effects:
     *   - Sets `$request->setParam('folder', $path)` so handler code that
     *     still reads getParam('folder') continues to work transparently.
     *   - Sets `$request->setParam('folder_id', $id)` when one is known
     *     so downstream code (dual-write callers, structured logs) can
     *     skip a second resolution.
     *
     * @param Request $request
     * @param string $sourceTag short label identifying the endpoint
     *   family (e.g. 'messages_list'), recorded in StructuredLog so
     *   we can attribute legacy traffic.
     */
    protected function getResolvedFolder(Request $request, string $sourceTag = 'unknown'): ?string
    {
        $folderId = $request->getParam('folder_id');
        $folderPath = $request->getParam('folder');

        // Empty input = caller probably routed without {folder} -- nothing to do.
        if (($folderId === null || $folderId === '') && ($folderPath === null || $folderPath === '')) {
            return null;
        }

        $input = $folderId !== null && $folderId !== '' ? (string) $folderId : (string) $folderPath;
        $accountId = $this->getActiveEmail();
        $resolver = new FolderInputResolver($this->config);
        $resolved = $resolver->resolve($accountId, $input);

        // Post-cutover regression guard. On a sampled fraction of
        // requests, run BOTH lookups (folder_id and path) and report
        // divergence. This is the only telemetry that survives the
        // cutover; identity drift between the two lookups would mean
        // the canonical and path-resolution code disagree on what
        // folder a string refers to, which is a paging-worthy bug.
        //
        // Sample rate is read once per request from env. Set
        // DUAL_RESOLVE_COMPARE_RATE=0 to disable; default 1.0 (100%).
        // Lower it later if production shows the cost is non-trivial.
        try {
            $cache = new RedisCacheService($this->config);
            $telem = new DualWriteTelemetry($cache);

            if ($this->shouldSampleCompareResolve()) {
                $compare = $resolver->compareResolve(
                    $accountId,
                    $resolved['folder_id'],
                    $resolved['folder_path']
                );
                $telem->recordResolveCompare($compare['status'], $sourceTag);
                if ($compare['status'] === 'identity_drift' || $compare['status'] === 'partial') {
                    StructuredLog::emit('dual_resolve_compare', [
                        'evt_status'  => $compare['status'],
                        'source'      => $sourceTag,
                        'folder_id'   => $resolved['folder_id'],
                        'folder_path' => $resolved['folder_path'],
                        'by_id'       => $compare['by_id']   ?? null,
                        'by_path'     => $compare['by_path'] ?? null,
                        'reason'      => $compare['details'] ?? null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Telemetry must never break a request.
        }

        // Surface the resolved pair so handlers that already read
        // getParam('folder') still get a useful path string AND
        // canonical code paths can read getParam('folder_id') without
        // re-resolving.
        if ($resolved['folder_path'] !== null) {
            $request->setParam('folder', $resolved['folder_path']);
        }
        if ($resolved['folder_id'] !== null) {
            $request->setParam('folder_id', $resolved['folder_id']);
        }

        return $resolved['folder_path'];
    }

    /**
     * Cheap per-request decision on whether to run the round-trip
     * resolve for compare-mode regression telemetry. Reads
     * `DUAL_RESOLVE_COMPARE_RATE` from env (default 1.0) and rolls a
     * random number. Out-of-range values are clamped.
     */
    private function shouldSampleCompareResolve(): bool
    {
        static $rate = null;
        if ($rate === null) {
            $raw = $_ENV['DUAL_RESOLVE_COMPARE_RATE']
                ?? getenv('DUAL_RESOLVE_COMPARE_RATE');
            $rate = is_numeric($raw) ? (float) $raw : 1.0;
            if ($rate < 0.0) {
                $rate = 0.0;
            } elseif ($rate > 1.0) {
                $rate = 1.0;
            }
        }
        if ($rate <= 0.0) {
            return false;
        }
        if ($rate >= 1.0) {
            return true;
        }
        // mt_rand() / mt_getrandmax() yields a value in [0,1]; cheap and
        // unbiased for sampling decisions.
        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    /**
     * Get secondary account email by ID (checks both regular and OAuth accounts)
     */
    protected function getSecondaryAccountEmail(int $accountId): ?string
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            $primaryEmail = strtolower($this->userEmail);
            
            // First check regular accounts
            $stmt = $db->prepare('SELECT account_email FROM webmail_accounts WHERE id = ? AND primary_email = ?');
            $stmt->execute([$accountId, $primaryEmail]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result['account_email'];
            }
            
            // Then check OAuth accounts (e.g. Gmail, Outlook)
            $stmt = $db->prepare('SELECT oauth_email FROM webmail_oauth_tokens WHERE id = ? AND primary_email = ?');
            $stmt->execute([$accountId, $primaryEmail]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $result ? $result['oauth_email'] : null;
        } catch (\Exception $e) {
            error_log("BaseController getSecondaryAccountEmail error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get secondary account with full credentials (email + password)
     * Used by controllers that need IMAP/SMTP access to secondary accounts
     */
    protected function getSecondaryAccountCredentials(int $accountId): ?array
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            // Use the original JWT email (primaryUserEmail), NOT $this->userEmail
            // which may have been overwritten by account switching (X-Account-Id)
            $primaryEmail = strtolower($this->primaryUserEmail ?? $this->userEmail);
            
            // Check regular accounts first
            $stmt = $db->prepare('SELECT * FROM webmail_accounts WHERE id = ? AND primary_email = ?');
            $stmt->execute([$accountId, $primaryEmail]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($account) {
                $password = $this->decryptAccountPassword($account['credentials_encrypted']);
                return [
                    'email' => $account['account_email'],
                    'password' => $password,
                    'imap_host' => $account['imap_host'] ?? null,
                    'imap_port' => $account['imap_port'] ?? null,
                    'smtp_host' => $account['smtp_host'] ?? null,
                    'smtp_port' => $account['smtp_port'] ?? null,
                    'smtp_encryption' => $account['smtp_encryption'] ?? null,
                    'is_oauth' => false,
                ];
            }
            
            // Check OAuth accounts
            $stmt = $db->prepare('SELECT * FROM webmail_oauth_tokens WHERE id = ? AND primary_email = ?');
            $stmt->execute([$accountId, $primaryEmail]);
            $oauthAccount = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($oauthAccount) {
                $provider = $oauthAccount['provider'] ?? 'google';
                $oauthEmail = $oauthAccount['oauth_email'];
                
                // Get a valid (decrypted + refreshed if needed) access token via the OAuth service
                $accessToken = null;
                try {
                    if ($provider === 'microsoft' && $this->microsoftOAuthService) {
                        $accessToken = $this->microsoftOAuthService->getValidAccessToken($primaryEmail, $oauthEmail);
                    } elseif ($this->googleOAuthService) {
                        $accessToken = $this->googleOAuthService->getValidAccessToken($primaryEmail, $oauthEmail);
                    }
                    error_log("getSecondaryAccountCredentials: OAuth token retrieval for {$oauthEmail} (provider: {$provider}): " . ($accessToken ? 'SUCCESS' : 'FAILED (null)'));
                } catch (\Throwable $e) {
                    error_log("getSecondaryAccountCredentials: EXCEPTION getting OAuth access token for {$oauthEmail}: " . $e->getMessage());
                }
                
                // Determine SMTP settings based on provider
                $smtpHost = null;
                $smtpPort = null;
                $smtpEncryption = null;
                if ($provider === 'microsoft') {
                    $smtpHost = 'smtp.office365.com';
                    $smtpPort = 587;
                    $smtpEncryption = 'tls';
                } elseif ($provider === 'google') {
                    $smtpHost = 'smtp.gmail.com';
                    $smtpPort = 587;
                    $smtpEncryption = 'tls';
                }
                
                return [
                    'email' => $oauthEmail,
                    'password' => null,
                    'is_oauth' => true,
                    'provider' => $provider,
                    'access_token' => $accessToken,
                    'smtp_host' => $smtpHost,
                    'smtp_port' => $smtpPort,
                    'smtp_encryption' => $smtpEncryption,
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            error_log("BaseController getSecondaryAccountCredentials error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get active account credentials (email + password), respecting X-Account-Id
     */
    protected function getActiveCredentials(): array
    {
        $accountId = $_SERVER['HTTP_X_ACCOUNT_ID'] ?? null;
        
        if ($accountId && $accountId !== 'primary' && $this->userEmail) {
            $creds = $this->getSecondaryAccountCredentials((int)$accountId);
            if ($creds) {
                return $creds;
            }
        }
        
        // For OAuth-login users, resolve an access token for SMTP
        if ($this->isOAuthSession && !$this->userPassword) {
            $oauthAccount = $this->getOAuthAccountByEmail($this->userEmail);
            if ($oauthAccount) {
                $tokenOwner = $oauthAccount['primary_email'] ?? $this->userEmail;
                $provider = $oauthAccount['provider'] ?? $this->oauthProvider ?? 'google';
                $accessToken = null;
                
                if ($provider === 'microsoft' && $this->microsoftOAuthService) {
                    $accessToken = $this->microsoftOAuthService->getValidAccessToken($tokenOwner, $this->userEmail);
                } elseif ($this->googleOAuthService) {
                    $accessToken = $this->googleOAuthService->getValidAccessToken($tokenOwner, $this->userEmail);
                }
                
                $smtpHost = $provider === 'microsoft' ? 'smtp.office365.com' : 'smtp.gmail.com';
                $smtpPort = 587;
                $smtpEncryption = 'tls';
                
                return [
                    'email' => $this->userEmail,
                    'password' => null,
                    'is_oauth' => true,
                    'provider' => $provider,
                    'access_token' => $accessToken,
                    'smtp_host' => $smtpHost,
                    'smtp_port' => $smtpPort,
                    'smtp_encryption' => $smtpEncryption,
                ];
            }
        }
        
        return [
            'email' => $this->userEmail,
            'password' => $this->userPassword,
            'is_oauth' => $this->isOAuthSession,
            'provider' => $this->oauthProvider,
        ];
    }
    
    /**
     * Sanitize a filename for use in Content-Disposition headers.
     * Prevents header injection via CRLF, null bytes, and quote escaping.
     * Returns both ASCII fallback and UTF-8 RFC 5987 encoded value.
     */
    protected function safeContentDisposition(string $disposition, string $filename): string
    {
        // Strip dangerous characters: CR, LF, null bytes, double quotes, backslashes
        $safeFilename = str_replace(["\r", "\n", "\0", '"', '\\'], '', $filename);
        
        // ASCII fallback: replace non-ASCII chars with underscore
        $asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $safeFilename);
        
        // RFC 5987 UTF-8 encoded filename for browsers that support it
        $utf8Filename = rawurlencode($safeFilename);
        
        return "Content-Disposition: {$disposition}; filename=\"{$asciiFilename}\"; filename*=UTF-8''{$utf8Filename}";
    }
    
    /**
     * Require basic authentication for chat endpoints.
     * Returns null if authenticated, or a Response object if auth fails.
     */
    protected function requireChatAuth(Request $request): ?Response
    {
        return $this->requireAuth($request);
    }

    /**
     * Stream a binary file directly to the client, bypassing Response class.
     * Cleans output buffers to prevent binary corruption from buffered content.
     */
    protected function streamBinaryFile(string $filePath, string $downloadName, string $contentType, int $fileSize): void
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');

        while (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $fileSize);
        header('Content-Encoding: identity');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $handle = fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 65536);
                flush();
            }
            fclose($handle);
        } else {
            readfile($filePath);
        }
    }
}

