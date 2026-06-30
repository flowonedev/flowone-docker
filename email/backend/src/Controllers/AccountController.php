<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\AccountService;
use Webmail\Services\AccountTeardownService;
use Webmail\Services\LinkedAccountSyncService;
use Webmail\Services\OAuthStateService;
use Webmail\Addons\Calendar\Services\CalendarConnectionService;
use Webmail\Addons\Calendar\Services\GoogleCalendarService;

/**
 * AccountController - Manages linked email accounts
 */
class AccountController extends BaseController
{
    private ?AccountService $accountService = null;
    private ?CalendarConnectionService $calendarConnectionService = null;
    private ?GoogleCalendarService $googleCalendarService = null;
    private ?AccountTeardownService $accountTeardownService = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        if ($this->userEmail) {
            $this->accountService = new AccountService($config);
            $this->calendarConnectionService = new CalendarConnectionService($config);
            $this->googleCalendarService = new GoogleCalendarService($config);
        }
    }

    /**
     * Tear down residual server-side state (folder sync queue, cached OAuth
     * token, folder identity, unread cache) for a just-removed account. Lazily
     * built so only the delete paths pay the cost, and wrapped best-effort so a
     * teardown hiccup never fails the user-facing delete.
     */
    private function teardownRemovedAccount(string $accountEmail, ?string $provider): void
    {
        if ($accountEmail === '' || !$this->userEmail) {
            return;
        }
        try {
            if (!$this->accountTeardownService) {
                $this->accountTeardownService = new AccountTeardownService($this->config);
            }
            $this->accountTeardownService->purge($this->userEmail, $accountEmail, $provider);
        } catch (\Throwable $e) {
            error_log('[AccountController] teardownRemovedAccount(' . $accountEmail . '): ' . $e->getMessage());
        }
    }
    
    // extractUserFromToken() and requireValidSession() inherited from BaseController
    
    /**
     * List all linked accounts (including OAuth accounts)
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $accounts = $this->accountService->getAccounts($this->userEmail);
        $presets = AccountService::getProviderPresets();
        
        // Include Google OAuth accounts if service is available
        // Filter out the primary account (same email as logged-in user) to avoid duplicates
        $oauthAccounts = [];
        if ($this->googleOAuthService) {
            $googleAccounts = $this->googleOAuthService->getOAuthAccounts($this->userEmail);
            // Filter out primary account and add flags
            $googleAccounts = array_filter($googleAccounts, function($acc) {
                // Exclude if oauth_email matches primary email (it's already shown as primary)
                return strtolower($acc['account_email'] ?? '') !== strtolower($this->userEmail);
            });
            $googleAccounts = array_map(function($acc) {
                $acc['is_oauth'] = true;
                $acc['provider'] = 'google';
                // Get calendar sync info if available
                if ($this->googleCalendarService) {
                    $acc['synced_calendars'] = $this->googleCalendarService->getSyncedCalendarsInfo(
                        $acc['id'],
                        GoogleCalendarService::CONNECTION_OAUTH
                    );
                }
                return $acc;
            }, $googleAccounts);
            $oauthAccounts = array_merge($oauthAccounts, $googleAccounts);
        }
        
        // Include Microsoft OAuth accounts if service is available
        if ($this->microsoftOAuthService) {
            $microsoftAccounts = $this->microsoftOAuthService->getOAuthAccounts($this->userEmail);
            // Filter out primary account and add flags
            $microsoftAccounts = array_filter($microsoftAccounts, function($acc) {
                return strtolower($acc['account_email'] ?? '') !== strtolower($this->userEmail);
            });
            $microsoftAccounts = array_map(function($acc) {
                $acc['is_oauth'] = true;
                $acc['provider'] = 'microsoft';
                return $acc;
            }, $microsoftAccounts);
            $oauthAccounts = array_merge($oauthAccounts, $microsoftAccounts);
        }
        
        // Merge all accounts, marking standard ones
        $accounts = array_map(function($acc) {
            $acc['is_oauth'] = false;
            return $acc;
        }, $accounts);
        
        $allAccounts = array_merge($accounts, $oauthAccounts);
        
        // Get calendar-only connections
        $calendarConnections = [];
        if ($this->calendarConnectionService) {
            $connections = $this->calendarConnectionService->getConnections($this->userEmail);
            foreach ($connections as &$conn) {
                $conn['type'] = 'calendar_only';
                if ($this->googleCalendarService) {
                    $conn['synced_calendars'] = $this->googleCalendarService->getSyncedCalendarsInfo(
                        $conn['id'],
                        GoogleCalendarService::CONNECTION_CALENDAR_ONLY
                    );
                }
            }
            $calendarConnections = $connections;
        }
        
        return Response::success([
            'accounts' => $allAccounts,
            'calendar_connections' => $calendarConnections,
            'presets' => $presets,
            'google_oauth_enabled' => !empty($this->config['google_oauth']['client_id']),
            'microsoft_oauth_enabled' => !empty($this->config['microsoft_oauth']['client_id']),
        ]);
    }
    
    /**
     * Get a single account
     */
    public function get(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $account = $this->accountService->getAccount($this->userEmail, $id);
        
        if (!$account) {
            return Response::error('Account not found', 404);
        }
        
        return Response::success(['account' => $account]);
    }
    
    /**
     * Add a new linked account
     */
    public function create(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = [
            'account_email' => $request->input('account_email'),
            'password' => $request->input('password'),
            'display_name' => $request->input('display_name'),
            'imap_host' => $request->input('imap_host'),
            'imap_port' => $request->input('imap_port', 993),
            'imap_encryption' => $request->input('imap_encryption', 'ssl'),
            'smtp_host' => $request->input('smtp_host'),
            'smtp_port' => $request->input('smtp_port', 587),
            'smtp_encryption' => $request->input('smtp_encryption', 'tls'),
            'is_default' => $request->input('is_default', false),
            // New linked account fields
            'account_type' => $request->input('account_type', 'separate'),
            'sync_frequency' => $request->input('sync_frequency', 15),
            'leave_on_server' => $request->input('leave_on_server', true),
            'auto_label' => $request->input('auto_label'),
            'sync_enabled' => $request->input('sync_enabled', true),
        ];
        
        if (empty($data['account_email'])) {
            return Response::error('Email address is required');
        }
        
        if (empty($data['password'])) {
            return Response::error('Password is required');
        }
        
        if (empty($data['imap_host'])) {
            return Response::error('IMAP host is required');
        }
        
        // Validate account_type
        if (!in_array($data['account_type'], ['separate', 'linked'])) {
            return Response::error('Invalid account type');
        }
        
        // Auto-generate auto_label from account email for linked accounts
        if ($data['account_type'] === 'linked' && empty($data['auto_label'])) {
            $data['auto_label'] = strtolower($data['account_email']);
        }
        
        // Test connection before saving
        $testResult = $this->accountService->testConnection($data);
        if (!$testResult['success']) {
            return Response::error('Connection test failed: ' . ($testResult['error'] ?? 'Unknown error'));
        }
        
        $account = $this->accountService->addAccount($this->userEmail, $data);
        
        if (!$account) {
            return Response::error('Failed to add account. It may already exist.');
        }
        
        return Response::success(['account' => $account], 'Account added successfully');
    }
    
    /**
     * Update an account
     */
    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        $data = [];
        if ($request->has('display_name')) $data['display_name'] = $request->input('display_name');
        if ($request->has('imap_host')) $data['imap_host'] = $request->input('imap_host');
        if ($request->has('imap_port')) $data['imap_port'] = $request->input('imap_port');
        if ($request->has('imap_encryption')) $data['imap_encryption'] = $request->input('imap_encryption');
        if ($request->has('smtp_host')) $data['smtp_host'] = $request->input('smtp_host');
        if ($request->has('smtp_port')) $data['smtp_port'] = $request->input('smtp_port');
        if ($request->has('smtp_encryption')) $data['smtp_encryption'] = $request->input('smtp_encryption');
        if ($request->has('password') && !empty($request->input('password'))) {
            $data['password'] = $request->input('password');
        }
        if ($request->has('is_default')) $data['is_default'] = $request->input('is_default');
        // Account type switching (separate <-> linked)
        if ($request->has('account_type')) {
            $newType = $request->input('account_type');
            if (in_array($newType, ['separate', 'linked'])) {
                $data['account_type'] = $newType;
                // Auto-set auto_label when switching to linked
                if ($newType === 'linked') {
                    $account = $this->accountService->getAccount($this->userEmail, $id);
                    if ($account && empty($account['auto_label'])) {
                        $data['auto_label'] = strtolower($account['account_email']);
                    }
                }
            }
        }
        // Linked account fields
        if ($request->has('sync_frequency')) $data['sync_frequency'] = $request->input('sync_frequency');
        if ($request->has('leave_on_server')) $data['leave_on_server'] = $request->input('leave_on_server');
        if ($request->has('auto_label')) $data['auto_label'] = $request->input('auto_label');
        if ($request->has('signature')) $data['signature'] = $request->input('signature');
        if ($request->has('sync_enabled')) $data['sync_enabled'] = $request->input('sync_enabled');
        
        $account = $this->accountService->updateAccount($this->userEmail, $id, $data);
        
        if (!$account) {
            return Response::error('Account not found', 404);
        }
        
        return Response::success(['account' => $account], 'Account updated');
    }
    
    /**
     * Delete an account
     */
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        // Get account details before deleting for history
        $account = $this->accountService->getAccount($this->userEmail, $id);
        if (!$account) {
            return Response::error('Account not found', 404);
        }
        
        // Archive to history before deleting
        $this->calendarConnectionService->archiveToHistory(
            $this->userEmail,
            $account['account_email'],
            'imap',
            [
                'display_name' => $account['display_name'],
                'server_settings' => [
                    'imap_host' => $account['imap_host'],
                    'imap_port' => $account['imap_port'],
                    'imap_encryption' => $account['imap_encryption'],
                    'smtp_host' => $account['smtp_host'],
                    'smtp_port' => $account['smtp_port'],
                    'smtp_encryption' => $account['smtp_encryption'],
                ],
            ]
        );
        
        if (!$this->accountService->deleteAccount($this->userEmail, $id)) {
            return Response::error('Failed to delete account', 500);
        }
        
        $this->teardownRemovedAccount((string)($account['account_email'] ?? ''), null);
        
        return Response::success(null, 'Account deleted');
    }
    
    /**
     * Test account connection without saving
     */
    public function test(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $config = [
            'account_email' => $request->input('account_email'),
            'password' => $request->input('password'),
            'imap_host' => $request->input('imap_host'),
            'imap_port' => $request->input('imap_port', 993),
            'imap_encryption' => $request->input('imap_encryption', 'ssl'),
        ];
        
        if (empty($config['account_email']) || empty($config['password']) || empty($config['imap_host'])) {
            return Response::error('Email, password and IMAP host are required');
        }
        
        $result = $this->accountService->testConnection($config);
        
        if ($result['success']) {
            return Response::success($result, 'Connection successful');
        } else {
            return Response::error($result['error'] ?? 'Connection failed');
        }
    }
    
    /**
     * Set an account as default
     */
    public function setDefault(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        $account = $this->accountService->updateAccount($this->userEmail, $id, ['is_default' => true]);
        
        if (!$account) {
            return Response::error('Account not found', 404);
        }
        
        return Response::success(['account' => $account], 'Default account updated');
    }
    
    /**
     * Auto-detect server settings based on email domain
     */
    public function detectSettings(Request $request): Response
    {
        $email = $request->input('email');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email address is required');
        }
        
        $result = AccountService::detectSettings($email);
        
        return Response::success($result);
    }
    
    /**
     * Get sync status for linked accounts
     */
    public function syncStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $syncService = new LinkedAccountSyncService($this->config);
        $status = $syncService->getSyncStatus($this->userEmail);
        
        return Response::success(['accounts' => $status]);
    }
    
    /**
     * Manually trigger sync for a linked account
     */
    public function triggerSync(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        if (!$id) {
            return Response::error('Account ID is required');
        }
        
        try {
            $syncService = new LinkedAccountSyncService($this->config);
            $result = $syncService->triggerSync($this->userEmail, $id);
            
            // Process the queue to actually import fetched emails into primary IMAP inbox
            $imported = 0;
            $importErrors = [];
            if ($result['fetched'] > 0 && $this->userPassword) {
                try {
                    $queueResult = $syncService->processQueue($this->userEmail, $this->userPassword);
                    $imported = $queueResult['processed'] ?? 0;
                    $importErrors = $queueResult['errors'] ?? [];
                } catch (\Exception $e) {
                    error_log("Queue processing after sync failed: " . $e->getMessage());
                    $importErrors[] = $e->getMessage();
                }
            }
            
            return Response::success([
                'fetched' => $result['fetched'],
                'deleted' => $result['deleted'],
                'imported' => $imported,
                'import_errors' => $importErrors,
            ], 'Sync completed');
        } catch (\Exception $e) {
            return Response::error('Sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Trigger sync for ALL sync-enabled linked accounts in ONE HTTP
     * call. Runs LinkedAccountSyncService::triggerSync() for each
     * account, then drains the queue ONCE at the end (vs once per
     * account in the legacy per-account loop).
     *
     * POST /accounts/sync/trigger-all
     */
    public function triggerSyncAll(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        try {
            $syncService = new LinkedAccountSyncService($this->config);
            $accounts = $this->accountService->getLinkedAccounts($this->userEmail);

            $perAccount = [];
            $totalFetched = 0;
            $totalDeleted = 0;
            foreach ($accounts as $account) {
                if (empty($account['sync_enabled'])) continue;
                $accountId = (int)$account['id'];
                try {
                    $r = $syncService->triggerSync($this->userEmail, $accountId);
                    $totalFetched += (int)($r['fetched'] ?? 0);
                    $totalDeleted += (int)($r['deleted'] ?? 0);
                    $perAccount[] = [
                        'id' => $accountId,
                        'email' => $account['account_email'],
                        'fetched' => (int)($r['fetched'] ?? 0),
                        'deleted' => (int)($r['deleted'] ?? 0),
                    ];
                } catch (\Exception $e) {
                    error_log("[AccountController::triggerSyncAll] account {$accountId}: " . $e->getMessage());
                    $perAccount[] = [
                        'id' => $accountId,
                        'email' => $account['account_email'],
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Drain the queue ONCE at the end. Each per-account
            // triggerSync above only fetches into the queue; the
            // queue-process pass below is what actually imports
            // into the primary IMAP inbox.
            $imported = 0;
            $importErrors = [];
            if ($totalFetched > 0 && $this->userPassword) {
                try {
                    $queueResult = $syncService->processQueue($this->userEmail, $this->userPassword);
                    $imported = $queueResult['processed'] ?? 0;
                    $importErrors = $queueResult['errors'] ?? [];
                } catch (\Exception $e) {
                    error_log("Queue processing after sync-all failed: " . $e->getMessage());
                    $importErrors[] = $e->getMessage();
                }
            }

            return Response::success([
                'fetched' => $totalFetched,
                'deleted' => $totalDeleted,
                'imported' => $imported,
                'import_errors' => $importErrors,
                'accounts' => $perAccount,
            ], 'Sync-all completed');
        } catch (\Exception $e) {
            return Response::error('Sync-all failed: ' . $e->getMessage());
        }
    }

    /**
     * Process queued messages for the current user
     * Called periodically by the frontend to import synced messages
     */
    public function processQueue(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        try {
            $syncService = new LinkedAccountSyncService($this->config);
            $result = $syncService->processQueue($this->userEmail, $this->userPassword);
            
            return Response::success([
                'processed' => $result['processed'],
                'errors' => $result['errors'],
            ]);
        } catch (\Exception $e) {
            return Response::error('Queue processing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get send addresses (for compose "From" selector)
     */
    public function getSendAddresses(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $addresses = [
            [
                'email' => $this->userEmail,
                'name' => null,
                'is_primary' => true,
                'signature' => null, // Primary uses global settings signature
            ]
        ];
        
        // Get all accounts with SMTP configured
        $accounts = $this->accountService->getAccounts($this->userEmail);
        
        foreach ($accounts as $account) {
            if (!empty($account['smtp_host'])) {
                $addresses[] = [
                    'email' => $account['account_email'],
                    'name' => $account['display_name'],
                    'is_primary' => false,
                    'account_id' => $account['id'],
                    'account_type' => $account['account_type'],
                    'signature' => $account['signature'] ?? null,
                ];
            }
        }
        
        // Include Google OAuth accounts
        if ($this->googleOAuthService) {
            $oauthAccounts = $this->googleOAuthService->getOAuthAccounts($this->userEmail);
            foreach ($oauthAccounts as $account) {
                $addresses[] = [
                    'email' => $account['account_email'],
                    'name' => $account['display_name'],
                    'is_primary' => false,
                    'account_id' => $account['id'],
                    'account_type' => $account['account_type'],
                    'auth_type' => 'oauth',
                    'provider' => 'google',
                    'signature' => $account['signature'] ?? null,
                ];
            }
        }
        
        // Include Microsoft OAuth accounts
        if ($this->microsoftOAuthService) {
            $msAccounts = $this->microsoftOAuthService->getOAuthAccounts($this->userEmail);
            foreach ($msAccounts as $account) {
                $addresses[] = [
                    'email' => $account['account_email'],
                    'name' => $account['display_name'],
                    'is_primary' => false,
                    'account_id' => $account['id'],
                    'account_type' => $account['account_type'],
                    'auth_type' => 'oauth',
                    'provider' => 'microsoft',
                    'signature' => $account['signature'] ?? null,
                ];
            }
        }
        
        return Response::success(['addresses' => $addresses]);
    }
    
    /**
     * Get unread email counts for all accounts.
     *
     * Phase 1 of the OAuth rewrite: this endpoint NEVER opens an IMAP /
     * XOAUTH2 connection. It returns the Redis cache populated by
     * cron/refresh-unread-counts.php. Every-60-second client polling
     * against this endpoint used to be the #1 cause of the CPGuard ban
     * (one fresh `AUTHENTICATE XOAUTH2` per OAuth account per minute);
     * the cache makes the endpoint a single Redis read.
     *
     * The shape returned to the frontend is intentionally identical to
     * the legacy fan-out version so the store doesn't need to change.
     * Account IDs map to account_keys via UnreadCountCache::accountKey():
     *   - 'primary'      -> int (the JWT-owner's INBOX, only when a primary password is loaded)
     *   - 'imap:<id>'    -> alias for <id> (kept under the bare int key for backwards compat)
     *   - 'google:<id>'  -> alias for <id>
     *   - 'microsoft:<id>' -> alias for <id>
     *
     * `_meta.stale` is true when the cron last wrote the cache more
     * than 5 minutes ago, letting the frontend show a faint "syncing"
     * indicator without falling back to a synchronous IMAP fan-out.
     */
    public function getUnreadCounts(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $cache = new \Webmail\Services\UnreadCountCache($this->config);
        $entry = $cache->get($this->userEmail);
        $cached = $entry['counts'] ?? [];

        // OAuth (google / microsoft) and IMAP unread counts are populated
        // by the refresh-unread-counts cron (every 2 min). Primary
        // mailbox count is filled in below from a short-lived session
        // IMAP connection. No long-running daemon involved.

        // Flatten 'imap:<id>' / 'google:<id>' / 'microsoft:<id>' down to
        // the bare numeric id the frontend has always used, while ALSO
        // exposing the prefixed keys for any new consumer.
        $counts = [];
        foreach ($cached as $key => $value) {
            $counts[$key] = (int)$value;
            if ($key === 'primary') {
                continue;
            }
            if (preg_match('/^(imap|google|microsoft):(\d+)$/', (string)$key, $m)) {
                $counts[(int)$m[2]] = (int)$value;
            }
        }

        // IMAP single source of truth: the primary mailbox unread count comes
        // from live IMAP STATUS (below). The cached Redis value still wins
        // when fresh; we only recompute on miss/stale. The DB count is kept
        // strictly as a degraded fallback for when IMAP can't be reached.
        $primaryFresh = isset($cached['primary'])
            && isset($entry['updated_at'])
            && (time() - (int)$entry['updated_at']) < 60;

        if (!$primaryFresh) {
            $primaryUnread = null;

            // IMAP-single-source-of-truth: read the primary INBOX unread
            // straight from IMAP STATUS (UNSEEN) so the account badge can
            // never disagree with the IMAP-served folder badge. We connect to
            // the PRIMARY mailbox explicitly (its own email + password) rather
            // than via getImap(), because an active X-Account-Id header would
            // otherwise make us count a *secondary* mailbox's INBOX here.
            // Best-effort: OAuth-only primaries (no password) and any IMAP
            // failure fall through to the DB cache below, so this endpoint's
            // contract and failure modes are unchanged.
            if (!empty($this->userPassword)) {
                try {
                    $primaryImap = new \Webmail\Services\ImapService($this->config['imap']);
                    if ($primaryImap->connect($this->userEmail, $this->userPassword)) {
                        $status = $primaryImap->getFolderStatus('INBOX');
                        if (is_array($status) && isset($status['unseen'])) {
                            $primaryUnread = (int)$status['unseen'];
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[AccountController::getUnreadCounts] primary IMAP STATUS failed: ' . $e->getMessage());
                }
            }

            // Degraded fallback (OAuth-only primary with no password, or IMAP
            // unreachable): the in-transaction-maintained DB count.
            if ($primaryUnread === null) {
                try {
                    $primaryUnread = $this->computePrimaryUnreadFromDb($this->userEmail);
                } catch (\Throwable $e) {
                    error_log('[AccountController::getUnreadCounts] DB read failed: ' . $e->getMessage());
                }
            }

            if ($primaryUnread !== null) {
                $counts['primary'] = $primaryUnread;
                $cache->setOne($this->userEmail, 'primary', $primaryUnread);
            }
        }
        if (!isset($counts['primary'])) {
            $counts['primary'] = 0;
        }

        $meta = [
            'cache_hit' => $entry !== null,
            'updated_at' => $entry['updated_at'] ?? null,
            'stale' => $entry['stale'] ?? false,
        ];

        return Response::success(['counts' => $counts, '_meta' => $meta]);
    }

    /**
     * Compute the primary mailbox's unread count directly from MariaDB.
     *
     * Returns null only when the user has no folder identity row for
     * INBOX yet (brand-new account, indexing hasn't run). The Redis
     * cache layer treats null as "leave the existing cached value
     * alone" so a partial index never overwrites a real count with 0.
     *
     * This is the Phase 3 replacement for the per-request live IMAP
     * `STATUS INBOX (UNSEEN)` round-trip. Because every UI write commits
     * is_seen to webmail_conversation_members in the same transaction
     * as the HTTP response, the count returned here is always at least
     * as fresh as the UI itself, even across browser tabs.
     */
    private function computePrimaryUnreadFromDb(string $userEmail): ?int
    {
        $db = \Webmail\Core\Database::getConnection($this->config);

        // Locate the INBOX folder identity for this user. Match on
        // canonical_path (the post-rename current name) since
        // webmail_conversation_members is keyed on the stable id.
        $idStmt = $db->prepare(
            "SELECT id
               FROM webmail_folder_identity
              WHERE user_email = :u
                AND account_email = :a
                AND (UPPER(canonical_path) = 'INBOX' OR special_use = '\\\\Inbox')
              ORDER BY (UPPER(canonical_path) = 'INBOX') DESC
              LIMIT 1"
        );
        $idStmt->execute([
            ':u' => strtolower($userEmail),
            ':a' => strtolower($userEmail),
        ]);
        $folderId = $idStmt->fetchColumn();
        if (!$folderId) {
            return null;
        }

        $cntStmt = $db->prepare(
            "SELECT COUNT(*)
               FROM webmail_conversation_members
              WHERE user_email = :u
                AND folder_id = :f
                AND is_seen = 0"
        );
        $cntStmt->execute([':u' => strtolower($userEmail), ':f' => $folderId]);
        return (int)$cntStmt->fetchColumn();
    }

    // ==================== Google OAuth Methods ====================
    
    /**
     * Get Google OAuth authorization URL
     */
    public function googleAuthUrl(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->googleOAuthService) {
            return Response::error('Google OAuth is not configured', 500);
        }
        
        // Get account type preference from query
        $accountType = $request->getQuery('account_type', 'separate');
        $syncFrequency = $request->getQuery('sync_frequency', 15);
        $leaveOnServer = $request->getQuery('leave_on_server', 1);
        $autoLabel = $request->getQuery('auto_label', '');
        
        // Phase 2.1: HMAC-signed state. Previously this was plain base64(JSON)
        // with no signature, which allowed an attacker who could observe one
        // valid state to craft tampered variants (different user_email, etc.)
        // and link a victim's Google tokens to any account.
        $stateService = new OAuthStateService($this->config);
        $nonce = bin2hex(random_bytes(16));
        $state = $stateService->sign([
            'action' => 'add_account',
            'user_email' => $this->userEmail,
            'account_type' => $accountType,
            'sync_frequency' => $syncFrequency,
            'leave_on_server' => $leaveOnServer,
            'auto_label' => $autoLabel,
            'nonce' => $nonce,
        ]);

        // Phase 3: PKCE S256. The verifier is stored in Redis keyed
        // by the state nonce; the callback retrieves it (single-use)
        // before exchanging the code for tokens.
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $challenge = $pkce->createChallenge($nonce);

        $authUrl = $this->googleOAuthService->getAuthorizationUrl(
            $state,
            null,
            false,
            null,
            null,
            $challenge['challenge']
        );

        return Response::success(['auth_url' => $authUrl]);
    }

    // googleConnect() removed: the silent re-consent popup it backed has been replaced
    // by a hard /login redirect on oauth_reauth_required. The merged login flow re-issues
    // a valid token + refresh token via the standard /auth/google/login path.

    /**
     * Handle Google OAuth callback (GET - browser redirect)
     * Handles both email OAuth and calendar-only OAuth based on state.type
     * Renders an HTML page that closes the popup and notifies the parent window
     */
    public function googleCallback(Request $request): Response
    {
        // Debug: Log that callback was reached
        error_log("GoogleOAuth CALLBACK: Request received");
        error_log("GoogleOAuth CALLBACK: Full URL: " . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
        
        $code = $request->getQuery('code');
        $state = $request->getQuery('state');
        $error = $request->getQuery('error');
        
        error_log("GoogleOAuth CALLBACK: code=" . ($code ? 'present' : 'missing') . ", state=" . ($state ? 'present' : 'missing') . ", error=" . ($error ?: 'none'));
        
        $frontendUrl = 'https://flowone.pro';
        
        // Check if OAuth service is available
        if (!$this->googleOAuthService) {
            error_log("GoogleOAuth CALLBACK: ERROR - googleOAuthService is null!");
            $this->renderOAuthPopupClose($frontendUrl, false, 'oauth_not_configured');
            exit;
        }
        
        if ($error) {
            error_log("GoogleOAuth CALLBACK: Google returned error: {$error}");
            $this->renderOAuthPopupClose($frontendUrl, false, $error);
            exit;
        }
        
        if (!$code) {
            error_log("GoogleOAuth CALLBACK: No code received");
            $this->renderOAuthPopupClose($frontendUrl, false, 'no_code');
            exit;
        }
        
        // Phase 2.1: verify HMAC signature on state. verify() also enforces
        // the 15-minute TTL via the embedded timestamp. Any tampering or
        // expiry returns null and we abort the flow.
        $stateService = new OAuthStateService($this->config);
        $stateData = $stateService->verify($state);
        if (!$stateData || !isset($stateData['user_email'])) {
            error_log("GoogleOAuth CALLBACK: Invalid/expired state");
            $this->renderOAuthPopupClose($frontendUrl, false, 'invalid_state');
            exit;
        }

        error_log("GoogleOAuth CALLBACK: State verified - user_email: {$stateData['user_email']}, type: " . ($stateData['type'] ?? 'email'));
        
        // Check if this is a calendar-only connection request
        if (($stateData['type'] ?? '') === 'calendar_only') {
            error_log("GoogleOAuth CALLBACK: Calendar-only flow");
            $this->handleCalendarOnlyCallback($code, $stateData, $frontendUrl);
            exit;
        }
        
        // Regular email OAuth flow.
        // Phase 3: PKCE verifier exchange. The verifier is keyed by the
        // state nonce that we put in the signed envelope at auth-url
        // time; consume it here (single-use) and hand it to Google
        // alongside the authorization code. If no verifier is present
        // we still send no code_verifier — Google accepts that for
        // backwards compatibility, but new flows always get one.
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $verifier = isset($stateData['nonce']) ? $pkce->consumeVerifier((string)$stateData['nonce']) : null;

        error_log("GoogleOAuth CALLBACK: Exchanging code for tokens...");
        $tokens = $this->googleOAuthService->exchangeCodeForTokens($code, $verifier);
        if (!$tokens) {
            $this->renderOAuthPopupClose($frontendUrl, false, 'token_exchange_failed');
            exit;
        }
        
        // Get user info from Google
        $userInfo = $this->googleOAuthService->getUserInfo($tokens['access_token']);
        if (!$userInfo || !isset($userInfo['email'])) {
            $this->renderOAuthPopupClose($frontendUrl, false, 'userinfo_failed');
            exit;
        }
        
        // Store tokens
        error_log("GoogleOAuth CALLBACK: Storing tokens for {$stateData['user_email']} -> Google account: {$userInfo['email']}");
        $account = $this->googleOAuthService->storeTokens(
            $stateData['user_email'],
            $tokens,
            $userInfo,
            [
                'account_type' => $stateData['account_type'] ?? 'separate',
                'sync_frequency' => $stateData['sync_frequency'] ?? 15,
                'leave_on_server' => $stateData['leave_on_server'] ?? true,
                'auto_label' => $stateData['auto_label'] ?? null,
            ]
        );
        
        if (!$account) {
            error_log("GoogleOAuth CALLBACK: Failed to store tokens!");
            $this->renderOAuthPopupClose($frontendUrl, false, 'storage_failed');
            exit;
        }
        
        // Success - render page that closes popup and notifies parent
        error_log("GoogleOAuth CALLBACK: SUCCESS! Account stored, rendering success page");
        $this->renderOAuthPopupClose($frontendUrl, true, null, $userInfo['email']);
        exit;
    }
    
    /**
     * Handle calendar-only OAuth callback
     */
    private function handleCalendarOnlyCallback(string $code, array $stateData, string $frontendUrl): void
    {
        try {
            $connectionService = new \Webmail\Addons\Calendar\Services\CalendarConnectionService($this->config);

            // Phase 3: consume PKCE verifier (the challenge was created by
            // CalendarConnectionController::getAuthUrl, keyed by state nonce).
            $pkce = new \Webmail\Services\PKCEService($this->config);
            $verifier = isset($stateData['nonce']) ? $pkce->consumeVerifier((string)$stateData['nonce']) : null;

            $tokens = $connectionService->exchangeCodeForTokens($code, $verifier);
            if (!$tokens) {
                error_log("Calendar OAuth: Token exchange failed");
                $this->renderOAuthPopupClose($frontendUrl, false, 'token_exchange_failed', null, 'google_calendar');
                return;
            }
            
            $userInfo = $connectionService->getUserInfo($tokens['access_token']);
            if (!$userInfo || !isset($userInfo['email'])) {
                error_log("Calendar OAuth: Failed to get user info");
                $this->renderOAuthPopupClose($frontendUrl, false, 'userinfo_failed', null, 'google_calendar');
                return;
            }
            
            $connection = $connectionService->storeConnection(
                $stateData['user_email'],
                $tokens,
                $userInfo
            );
            
            if (!$connection) {
                error_log("Calendar OAuth: Failed to store connection");
                $this->renderOAuthPopupClose($frontendUrl, false, 'storage_failed', null, 'google_calendar');
                return;
            }
            
            $this->renderOAuthPopupClose($frontendUrl, true, null, $userInfo['email'], 'google_calendar');
            
        } catch (\Exception $e) {
            error_log("Calendar OAuth Exception: " . $e->getMessage());
            $this->renderOAuthPopupClose($frontendUrl, false, 'error: ' . $e->getMessage(), null, 'google_calendar');
        }
    }
    
    /**
     * Render an HTML page that notifies the parent window and closes the popup
     */
    private function renderOAuthPopupClose(string $frontendUrl, bool $success, ?string $error = null, ?string $accountEmail = null, ?string $provider = 'google'): void
    {
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $data = [
            'success' => $success,
            'error' => $error,
            'account_email' => $accountEmail,
            'provider' => $provider,
        ];
        
        $jsonData = htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8');
        $statusText = $success ? 'Connected successfully!' : ('Connection failed' . ($error ? ": $error" : ''));
        $statusClass = $success ? 'success' : 'error';
        
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>OAuth Complete</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }
        .message {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 400px;
        }
        .checkmark {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: block;
            stroke-width: 3;
            stroke: #10b981;
            stroke-miterlimit: 10;
            margin: 0 auto 20px;
            animation: scale .3s ease-in-out .3s both;
        }
        .checkmark-circle {
            stroke-dasharray: 166;
            stroke-dashoffset: 166;
            stroke-width: 3;
            stroke-miterlimit: 10;
            stroke: #10b981;
            fill: none;
            animation: stroke .4s cubic-bezier(0.65, 0, 0.45, 1) forwards;
        }
        .checkmark-check {
            transform-origin: 50% 50%;
            stroke-dasharray: 48;
            stroke-dashoffset: 48;
            animation: stroke .3s cubic-bezier(0.65, 0, 0.45, 1) .4s forwards;
        }
        .error-icon { color: #ef4444; font-size: 48px; margin-bottom: 20px; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        @keyframes stroke {
            100% { stroke-dashoffset: 0; }
        }
        @keyframes scale {
            0%, 100% { transform: none; }
            50% { transform: scale3d(1.1, 1.1, 1); }
        }
    </style>
</head>
<body>
    <div class="message" id="msg">
        <svg class="checkmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
            <circle class="checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
            <path class="checkmark-check" fill="none" d="m14.1 27.2 7.1 7.2 16.7-16.8"/>
        </svg>
        <p class="{$statusClass}" style="font-size:18px;font-weight:600;">{$statusText}</p>
        <p style="color:#666;margin-top:10px;">Closing window...</p>
    </div>
    <script>
        (function() {
            const data = {$jsonData};
            const frontendUrl = '{$frontendUrl}';
            
            console.log('[OAuth Callback] Processing result:', data.success ? 'success' : 'error');
            
            // ALWAYS store in sessionStorage first - this ensures the result is available
            // even if postMessage fails or the popup context is weird
            try {
                sessionStorage.setItem('oauth_callback_result', JSON.stringify({ type: 'oauth_callback', ...data }));
                console.log('[OAuth Callback] Stored result in sessionStorage');
            } catch (e) {
                console.error('[OAuth Callback] SessionStorage failed:', e);
            }
            
            // Check if we're in a popup with an opener
            const hasOpener = window.opener && !window.opener.closed;
            console.log('[OAuth Callback] Has opener:', hasOpener);
            
            if (hasOpener) {
                // Phase 2.3: target the known frontend origin explicitly
                // instead of '*'. Prevents any cross-origin window from
                // spoofing oauth_callback messages.
                try {
                    window.opener.postMessage({ type: 'oauth_callback', ...data }, frontendUrl);
                    console.log('[OAuth Callback] PostMessage sent to opener');
                } catch (e) {
                    console.error('[OAuth Callback] PostMessage failed:', e);
                }
                
                // Multiple close attempts - browsers are picky about window.close()
                function tryClose() {
                    try { window.close(); } catch(e) {}
                    try { self.close(); } catch(e) {}
                    try { window.open('', '_self').close(); } catch(e) {}
                }
                
                // Attempt close immediately
                tryClose();
                
                // Keep trying for a bit
                var attempts = 0;
                var closeInterval = setInterval(function() {
                    attempts++;
                    if (window.closed) {
                        clearInterval(closeInterval);
                        return;
                    }
                    tryClose();
                    
                    // After 5 attempts (500ms), show manual close button AND redirect option
                    if (attempts >= 5) {
                        clearInterval(closeInterval);
                        if (!window.closed) {
                            document.getElementById('msg').innerHTML += 
                                '<button onclick="window.close();self.close();" style="margin-top:20px;padding:12px 32px;background:#7c3aed;color:white;border:none;border-radius:24px;cursor:pointer;font-weight:600;font-size:14px;">Close This Window</button>' +
                                '<p style="color:#999;font-size:12px;margin-top:10px;">Your account is connected. You can close this window.</p>' +
                                '<p style="margin-top:15px;"><a href="' + frontendUrl + '?oauth_complete=1" style="color:#7c3aed;font-size:13px;">Or click here to return to the app</a></p>';
                        }
                    }
                }, 100);
            } else {
                // No opener - we were opened as a tab or popup was blocked
                // Redirect to frontend immediately (result already in sessionStorage)
                console.log('[OAuth Callback] No opener, redirecting to frontend');
                window.location.href = frontendUrl + '?oauth_complete=1';
            }
        })();
    </script>
</body>
</html>
HTML;
    }
    
    // Phase 3 (orphan cleanup): googleCallbackPost() / POST /auth/google/callback
    // removed. The legitimate add-account flow uses the popup -> GET
    // /auth/google/callback path (googleCallback). The POST variant was an
    // unused leftover from an earlier popup-vs-redirect experiment; its only
    // frontend caller (accounts.js::connectGoogleAccount) was also unused.
    // Per orphan-code-hygiene rules, both the route and the function are gone.

    /**
     * Delete an OAuth account (Google or Microsoft)
     */
    public function deleteOAuthAccount(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $deleteCalendarEvents = (bool)$request->input('delete_calendar_events', false);
        
        // Try Google OAuth first
        if ($this->googleOAuthService) {
            $account = $this->googleOAuthService->getOAuthAccountById($this->userEmail, $id);
            if ($account) {
                // Archive to history
                $this->calendarConnectionService->archiveToHistory(
                    $this->userEmail,
                    $account['account_email'],
                    'google_oauth',
                    [
                        'display_name' => $account['display_name'],
                        'provider' => 'google',
                    ]
                );
                
                // Remove calendar syncs for this account
                if ($this->googleCalendarService) {
                    $this->googleCalendarService->removeAllSyncsForAccount(
                        $id,
                        GoogleCalendarService::CONNECTION_OAUTH,
                        $deleteCalendarEvents
                    );
                }
                
                if ($this->googleOAuthService->deleteOAuthAccount($this->userEmail, $id)) {
                    $this->teardownRemovedAccount((string)($account['account_email'] ?? ''), 'google');
                    return Response::success(null, 'OAuth account deleted');
                }
            }
        }
        
        // Try Microsoft OAuth
        if ($this->microsoftOAuthService) {
            $account = $this->microsoftOAuthService->getOAuthAccountById($this->userEmail, $id);
            if ($account) {
                // Archive to history
                $this->calendarConnectionService->archiveToHistory(
                    $this->userEmail,
                    $account['account_email'],
                    'microsoft_oauth',
                    [
                        'display_name' => $account['display_name'],
                        'provider' => 'microsoft',
                    ]
                );
                
                if ($this->microsoftOAuthService->deleteOAuthAccount($this->userEmail, $id)) {
                    $this->teardownRemovedAccount((string)($account['account_email'] ?? ''), 'microsoft');
                    return Response::success(null, 'OAuth account deleted');
                }
            }
        }
        
        return Response::error('Account not found', 404);
    }
    
    // ==================== Microsoft OAuth Methods ====================
    
    /**
     * Get Microsoft OAuth authorization URL
     */
    public function microsoftAuthUrl(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->microsoftOAuthService) {
            return Response::error('Microsoft OAuth is not configured', 500);
        }
        
        // Get account type preference from query
        $accountType = $request->getQuery('account_type', 'separate');
        $syncFrequency = $request->getQuery('sync_frequency', 15);
        $leaveOnServer = $request->getQuery('leave_on_server', 1);
        $autoLabel = $request->getQuery('auto_label', '');
        
        // Phase 2.1: HMAC-signed state (same CSRF fix as Google add-account).
        $stateService = new OAuthStateService($this->config);
        $nonce = bin2hex(random_bytes(16));
        $state = $stateService->sign([
            'action' => 'add_account',
            'user_email' => $this->userEmail,
            'account_type' => $accountType,
            'sync_frequency' => $syncFrequency,
            'leave_on_server' => $leaveOnServer,
            'auto_label' => $autoLabel,
            'nonce' => $nonce,
        ]);

        // Phase 3: PKCE S256 (matches the Google add-account flow).
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $challenge = $pkce->createChallenge($nonce);

        $authUrl = $this->microsoftOAuthService->getAuthorizationUrl(
            $state,
            null,
            null,
            null,
            $challenge['challenge']
        );

        return Response::success(['auth_url' => $authUrl]);
    }

    // microsoftConnect() removed alongside googleConnect() - see note above.

    /**
     * Handle Microsoft OAuth callback (GET - browser redirect)
     * Renders an HTML page that closes the popup and notifies the parent window
     */
    public function microsoftCallback(Request $request): Response
    {
        $code = $request->getQuery('code');
        $state = $request->getQuery('state');
        $error = $request->getQuery('error');
        $errorDescription = $request->getQuery('error_description');
        
        $frontendUrl = rtrim($this->config['app']['frontend_url'] ?? 'https://flowone.pro', '/');
        
        if ($error) {
            $errorMsg = $errorDescription ?: $error;
            $this->renderOAuthPopupClose($frontendUrl, false, $errorMsg);
            exit;
        }
        
        if (!$code) {
            $this->renderOAuthPopupClose($frontendUrl, false, 'no_code');
            exit;
        }
        
        // Phase 2.1: HMAC verify via shared service (CSRF protection).
        $stateService = new OAuthStateService($this->config);
        $stateData = $stateService->verify($state);
        if (!$stateData || !isset($stateData['user_email'])) {
            $this->renderOAuthPopupClose($frontendUrl, false, 'invalid_state');
            exit;
        }

        if (!$this->microsoftOAuthService) {
            $this->renderOAuthPopupClose($frontendUrl, false, 'oauth_not_configured');
            exit;
        }

        // Phase 3: consume PKCE verifier (single-use).
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $verifier = isset($stateData['nonce']) ? $pkce->consumeVerifier((string)$stateData['nonce']) : null;

        // Exchange code for tokens
        $tokens = $this->microsoftOAuthService->exchangeCodeForTokens($code, null, $verifier);
        if (!$tokens) {
            $this->renderOAuthPopupClose($frontendUrl, false, 'token_exchange_failed');
            exit;
        }
        
        // Get user info from Microsoft
        $userInfo = $this->microsoftOAuthService->getUserInfo($tokens['access_token']);
        if (!$userInfo || !isset($userInfo['email'])) {
            $this->renderOAuthPopupClose($frontendUrl, false, 'userinfo_failed');
            exit;
        }
        
        // Store tokens
        $account = $this->microsoftOAuthService->storeTokens(
            $stateData['user_email'],
            $tokens,
            $userInfo,
            [
                'account_type' => $stateData['account_type'] ?? 'separate',
                'sync_frequency' => $stateData['sync_frequency'] ?? 15,
                'leave_on_server' => $stateData['leave_on_server'] ?? true,
                'auto_label' => $stateData['auto_label'] ?? null,
            ]
        );
        
        if (!$account) {
            $this->renderOAuthPopupClose($frontendUrl, false, 'storage_failed');
            exit;
        }
        
        // Success - render page that closes popup and notifies parent
        $this->renderOAuthPopupClose($frontendUrl, true, null, $userInfo['email'], 'microsoft');
        exit;
    }
    
    // Phase 3 (orphan cleanup): microsoftCallbackPost() / POST
    // /auth/microsoft/callback removed (same rationale as
    // googleCallbackPost above). The popup -> GET callback path
    // (microsoftCallback) handles real connections.
}

