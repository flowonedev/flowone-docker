<?php

namespace Webmail\Addons\Calendar\Services;

use Webmail\Services\GoogleOAuthService;

/**
 * GoogleCalendarService - Two-way sync with Google Calendar
 * 
 * Syncs calendar events between local webmail calendar and Google Calendar
 * Supports both OAuth email accounts AND calendar-only connections
 */
class GoogleCalendarService
{
    private \PDO $db;
    private GoogleOAuthService $oauthService;
    private ?CalendarConnectionService $calendarConnectionService = null;
    private CalendarService $calendarService;
    private array $config;
    
    private const GOOGLE_CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    
    // Connection types
    public const CONNECTION_OAUTH = 'oauth';
    public const CONNECTION_CALENDAR_ONLY = 'calendar_only';
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        $this->oauthService = new GoogleOAuthService($config);
        $this->calendarConnectionService = new CalendarConnectionService($config);
        $this->calendarService = new CalendarService($config);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
    }
    
    private function ensureTableExists(): void
    {
        try {
            // Track synced events between local and Google
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS calendar_sync_map (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    local_event_id INT NOT NULL,
                    google_event_id VARCHAR(255) NOT NULL,
                    google_calendar_id VARCHAR(255) NOT NULL,
                    oauth_account_id INT NOT NULL,
                    connection_type ENUM('oauth', 'calendar_only') DEFAULT 'oauth',
                    last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    sync_direction ENUM('local_to_google', 'google_to_local', 'bidirectional') DEFAULT 'bidirectional',
                    INDEX idx_local_event (local_event_id),
                    INDEX idx_google_event (google_event_id),
                    INDEX idx_oauth_account (oauth_account_id),
                    UNIQUE KEY unique_sync (local_event_id, google_calendar_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Add connection_type column if it doesn't exist
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM calendar_sync_map LIKE 'connection_type'");
                if ($stmt->rowCount() === 0) {
                    $this->db->exec("ALTER TABLE calendar_sync_map ADD COLUMN connection_type ENUM('oauth', 'calendar_only') DEFAULT 'oauth' AFTER oauth_account_id");
                }
            } catch (\PDOException $e) {
                // Ignore
            }
            
            // Track sync state per account
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS calendar_sync_state (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    oauth_account_id INT NOT NULL,
                    connection_type ENUM('oauth', 'calendar_only') DEFAULT 'oauth',
                    google_calendar_id VARCHAR(255) NOT NULL,
                    local_calendar_id INT NOT NULL,
                    sync_token VARCHAR(255) DEFAULT NULL,
                    last_full_sync TIMESTAMP NULL,
                    sync_enabled TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_calendar_sync (oauth_account_id, google_calendar_id, connection_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Add connection_type column to sync_state if it doesn't exist
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM calendar_sync_state LIKE 'connection_type'");
                if ($stmt->rowCount() === 0) {
                    $this->db->exec("ALTER TABLE calendar_sync_state ADD COLUMN connection_type ENUM('oauth', 'calendar_only') DEFAULT 'oauth' AFTER oauth_account_id");
                    // Update unique key
                    $this->db->exec("ALTER TABLE calendar_sync_state DROP KEY unique_calendar_sync");
                    $this->db->exec("ALTER TABLE calendar_sync_state ADD UNIQUE KEY unique_calendar_sync (oauth_account_id, google_calendar_id, connection_type)");
                }
            } catch (\PDOException $e) {
                // Ignore
            }
        } catch (\PDOException $e) {
            error_log("GoogleCalendarService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Get list of Google Calendars for an OAuth account
     */
    public function getGoogleCalendars(string $primaryEmail, int $oauthAccountId): array
    {
        $account = $this->oauthService->getOAuthAccountById($primaryEmail, $oauthAccountId);
        if (!$account) {
            return [];
        }
        
        $accessToken = $this->oauthService->getValidAccessToken($primaryEmail, $account['account_email']);
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
     * Setup sync between local calendar and Google Calendar
     */
    public function setupSync(string $primaryEmail, int $oauthAccountId, string $googleCalendarId, int $localCalendarId): ?array
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO calendar_sync_state 
                (oauth_account_id, connection_type, google_calendar_id, local_calendar_id, sync_enabled)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    local_calendar_id = VALUES(local_calendar_id),
                    sync_enabled = 1
            ');
            $stmt->execute([$oauthAccountId, self::CONNECTION_OAUTH, $googleCalendarId, $localCalendarId]);
            
            return $this->getSyncState($oauthAccountId, $googleCalendarId, self::CONNECTION_OAUTH);
        } catch (\PDOException $e) {
            error_log("GoogleCalendarService setupSync error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get sync state for a calendar pair
     */
    public function getSyncState(int $oauthAccountId, string $googleCalendarId, string $connectionType = self::CONNECTION_OAUTH): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM calendar_sync_state
            WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$oauthAccountId, $googleCalendarId, $connectionType]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get all sync configurations for an OAuth account
     */
    public function getSyncConfigs(int $oauthAccountId, string $connectionType = self::CONNECTION_OAUTH): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM calendar_sync_state
            WHERE oauth_account_id = ? AND connection_type = ? AND sync_enabled = 1
        ');
        $stmt->execute([$oauthAccountId, $connectionType]);
        return $stmt->fetchAll();
    }
    
    /**
     * Sync events from Google Calendar to local calendar
     */
    public function syncFromGoogle(string $primaryEmail, int $oauthAccountId, string $googleCalendarId): array
    {
        $result = ['imported' => 0, 'updated' => 0, 'errors' => []];
        
        $syncState = $this->getSyncState($oauthAccountId, $googleCalendarId, self::CONNECTION_OAUTH);
        if (!$syncState || !$syncState['sync_enabled']) {
            $result['errors'][] = 'Sync not configured or disabled';
            return $result;
        }
        
        $account = $this->oauthService->getOAuthAccountById($primaryEmail, $oauthAccountId);
        if (!$account) {
            $result['errors'][] = 'OAuth account not found';
            return $result;
        }
        
        $accessToken = $this->oauthService->getValidAccessToken($primaryEmail, $account['account_email']);
        if (!$accessToken) {
            $result['errors'][] = 'Failed to get access token';
            return $result;
        }
        
        // Phase 3.1: paginate. Build the base params and use the new helper
        // that walks every nextPageToken page.
        // Google rejects orderBy (and timeMin/timeMax) when a syncToken is
        // present -> 400 syncTokenWithNonDefaultOrdering. Incremental sync
        // must rely on server-side ordering only; orderBy/time window apply
        // to the initial (no-token) sync.
        $baseParams = [
            'maxResults' => 250,
            'singleEvents' => 'true',
        ];
        if ($syncState['sync_token']) {
            $baseParams['syncToken'] = $syncState['sync_token'];
        } else {
            $baseParams['orderBy'] = 'updated';
            $baseParams['timeMin'] = date('c', strtotime('-30 days'));
            $baseParams['timeMax'] = date('c', strtotime('+365 days'));
        }

        $response = $this->listEventsPaginated($googleCalendarId, $baseParams, $accessToken);

        if ($response === null) {
            // Sync token might be invalid, do full sync without it.
            if ($syncState['sync_token']) {
                $fallback = [
                    'maxResults' => 250,
                    'singleEvents' => 'true',
                    'orderBy' => 'updated',
                    'timeMin' => date('c', strtotime('-30 days')),
                    'timeMax' => date('c', strtotime('+365 days')),
                ];
                $response = $this->listEventsPaginated($googleCalendarId, $fallback, $accessToken);
            }

            if ($response === null) {
                $result['errors'][] = 'Failed to fetch Google Calendar events';
                return $result;
            }
        }
        
        $localCalendarId = $syncState['local_calendar_id'];
        
        // Process events
        foreach ($response['items'] ?? [] as $googleEvent) {
            try {
                if ($googleEvent['status'] === 'cancelled') {
                    // Handle deleted events
                    $this->handleDeletedGoogleEvent($googleEvent['id'], $oauthAccountId, $googleCalendarId, self::CONNECTION_OAUTH);
                    continue;
                }
                
                $imported = $this->importGoogleEvent(
                    $primaryEmail,
                    $googleEvent,
                    $localCalendarId,
                    $oauthAccountId,
                    $googleCalendarId,
                    self::CONNECTION_OAUTH
                );
                if ($imported === 'created') {
                    $result['imported']++;
                } elseif ($imported === 'updated') {
                    $result['updated']++;
                }
            } catch (\Exception $e) {
                $result['errors'][] = "Error importing event {$googleEvent['id']}: " . $e->getMessage();
            }
        }
        
        // Save new sync token. Phase 3.1: only persist when pagination
        // actually completed; partial fetches MUST NOT advance the token or
        // we'd silently skip the remaining pages on the next run.
        if (!empty($response['nextSyncToken']) && empty($response['partial'])) {
            $this->updateSyncToken($oauthAccountId, $googleCalendarId, $response['nextSyncToken'], self::CONNECTION_OAUTH);
        }
        
        return $result;
    }
    
    /**
     * Import a single Google event to local calendar
     */
    private function importGoogleEvent(
        string $primaryEmail,
        array $googleEvent,
        int $localCalendarId,
        int $oauthAccountId,
        string $googleCalendarId,
        string $connectionType = self::CONNECTION_OAUTH
    ): string
    {
        $lockName = sprintf('google-import:%s:%d:%s:%s', $connectionType, $oauthAccountId, $googleCalendarId, $googleEvent['id']);

        return $this->withSyncLock($lockName, function() use ($primaryEmail, $googleEvent, $localCalendarId, $oauthAccountId, $googleCalendarId, $connectionType) {
            $stmt = $this->db->prepare('
                SELECT local_event_id FROM calendar_sync_map 
                WHERE google_event_id = ? AND oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
                LIMIT 1
            ');
            $stmt->execute([$googleEvent['id'], $oauthAccountId, $googleCalendarId, $connectionType]);
            $existing = $stmt->fetch();

            $eventData = $this->parseGoogleEvent($googleEvent);

            if ($existing) {
                $this->calendarService->updateEvent($primaryEmail, (int)$existing['local_event_id'], $eventData);
                return 'updated';
            }

            $localEvent = $this->calendarService->createEvent($primaryEmail, $localCalendarId, $eventData);
            if ($localEvent) {
                $stmt = $this->db->prepare('
                    INSERT INTO calendar_sync_map 
                    (local_event_id, google_event_id, google_calendar_id, oauth_account_id, connection_type, sync_direction)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $localEvent['id'],
                    $googleEvent['id'],
                    $googleCalendarId,
                    $oauthAccountId,
                    $connectionType,
                    'google_to_local',
                ]);

                return 'created';
            }

            return 'skipped';
        });
    }
    
    /**
     * Parse Google Calendar event to local format
     */
    private function parseGoogleEvent(array $googleEvent): array
    {
        $allDay = isset($googleEvent['start']['date']);
        
        if ($allDay) {
            $startTime = $googleEvent['start']['date'] . ' 00:00:00';
            $endTime = $googleEvent['end']['date'] . ' 00:00:00';
        } else {
            $startTime = date('Y-m-d H:i:s', strtotime($googleEvent['start']['dateTime']));
            $endTime = date('Y-m-d H:i:s', strtotime($googleEvent['end']['dateTime']));
        }
        
        return [
            'title' => $googleEvent['summary'] ?? 'Untitled Event',
            'description' => $googleEvent['description'] ?? null,
            'location' => $googleEvent['location'] ?? null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'all_day' => $allDay,
            'timezone' => $googleEvent['start']['timeZone'] ?? 'UTC',
            'recurrence' => isset($googleEvent['recurrence']) ? implode("\n", $googleEvent['recurrence']) : null,
        ];
    }
    
    /**
     * Handle deleted Google event
     */
    private function handleDeletedGoogleEvent(
        string $googleEventId,
        int $oauthAccountId,
        string $googleCalendarId,
        string $connectionType = self::CONNECTION_OAUTH
    ): void
    {
        $stmt = $this->db->prepare('
            SELECT id, local_event_id FROM calendar_sync_map 
            WHERE google_event_id = ? AND oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$googleEventId, $oauthAccountId, $googleCalendarId, $connectionType]);
        $mapping = $stmt->fetch();
        
        if ($mapping) {
            $this->calendarService->deleteEventById((int)$mapping['local_event_id']);
            $this->db->prepare('DELETE FROM calendar_sync_map WHERE id = ?')
                ->execute([$mapping['id']]);
        }
    }
    
    /**
     * Sync local event to Google Calendar
     */
    public function syncToGoogle(string $primaryEmail, int $oauthAccountId, int $localEventId): ?string
    {
        // Get local event
        $localEvent = $this->calendarService->getEvent($primaryEmail, $localEventId);
        if (!$localEvent) {
            return null;
        }
        
        // Find sync config for this calendar
        $stmt = $this->db->prepare('
            SELECT * FROM calendar_sync_state 
            WHERE oauth_account_id = ? AND local_calendar_id = ? AND connection_type = ? AND sync_enabled = 1
        ');
        $stmt->execute([$oauthAccountId, $localEvent['calendar_id'], self::CONNECTION_OAUTH]);
        $syncState = $stmt->fetch();
        
        if (!$syncState) {
            return null; // No sync configured for this calendar
        }
        
        $account = $this->oauthService->getOAuthAccountById($primaryEmail, $oauthAccountId);
        if (!$account) {
            return null;
        }
        
        $accessToken = $this->oauthService->getValidAccessToken($primaryEmail, $account['account_email']);
        if (!$accessToken) {
            return null;
        }
        
        $googleCalendarId = $syncState['google_calendar_id'];

        $lockName = sprintf('google-push:%d:%d:%s', $oauthAccountId, $localEventId, $googleCalendarId);

        try {
            return $this->withSyncLock($lockName, function() use ($localEventId, $oauthAccountId, $googleCalendarId, $localEvent, $accessToken) {
                $stmt = $this->db->prepare('
                    SELECT google_event_id FROM calendar_sync_map 
                    WHERE local_event_id = ? AND oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
                    LIMIT 1
                ');
                $stmt->execute([$localEventId, $oauthAccountId, $googleCalendarId, self::CONNECTION_OAUTH]);
                $mapping = $stmt->fetch();

                $googleEvent = $this->formatForGoogle($localEvent);

                if ($mapping) {
                    $response = $this->apiRequest(
                        'PUT',
                        "/calendars/" . urlencode($googleCalendarId) . "/events/" . urlencode($mapping['google_event_id']),
                        $googleEvent,
                        $accessToken,
                        true
                    );

                    return $response['id'] ?? $mapping['google_event_id'];
                }

                $response = $this->apiRequest(
                    'POST',
                    "/calendars/" . urlencode($googleCalendarId) . "/events",
                    $googleEvent,
                    $accessToken,
                    true
                );

                if ($response && isset($response['id'])) {
                    $stmt = $this->db->prepare('
                        INSERT INTO calendar_sync_map 
                        (local_event_id, google_event_id, google_calendar_id, oauth_account_id, connection_type, sync_direction)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $localEventId,
                        $response['id'],
                        $googleCalendarId,
                        $oauthAccountId,
                        self::CONNECTION_OAUTH,
                        'local_to_google',
                    ]);
                }

                return $response['id'] ?? null;
            });
        } catch (\Throwable $e) {
            error_log("GoogleCalendarService syncToGoogle error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Format local event for Google Calendar API
     */
    private function formatForGoogle(array $localEvent): array
    {
        $event = [
            'summary' => $localEvent['title'],
            'description' => $localEvent['description'] ?? '',
            'location' => $localEvent['location'] ?? '',
        ];
        
        if ($localEvent['all_day']) {
            $event['start'] = ['date' => date('Y-m-d', strtotime($localEvent['start_time']))];
            $event['end'] = ['date' => date('Y-m-d', strtotime($localEvent['end_time']))];
        } else {
            $event['start'] = [
                'dateTime' => date('c', strtotime($localEvent['start_time'])),
                'timeZone' => $localEvent['timezone'] ?? 'UTC',
            ];
            $event['end'] = [
                'dateTime' => date('c', strtotime($localEvent['end_time'])),
                'timeZone' => $localEvent['timezone'] ?? 'UTC',
            ];
        }
        
        if (!empty($localEvent['recurrence'])) {
            $event['recurrence'] = explode("\n", $localEvent['recurrence']);
        }
        
        return $event;
    }
    
    /**
     * Delete event from Google Calendar
     */
    public function deleteFromGoogle(string $primaryEmail, int $oauthAccountId, int $localEventId): bool
    {
        // Get sync mapping
        $stmt = $this->db->prepare('
            SELECT * FROM calendar_sync_map 
            WHERE local_event_id = ? AND oauth_account_id = ? AND connection_type = ?
            LIMIT 1
        ');
        $stmt->execute([$localEventId, $oauthAccountId, self::CONNECTION_OAUTH]);
        $mapping = $stmt->fetch();
        
        if (!$mapping) {
            return true; // Not synced, nothing to delete
        }
        
        $account = $this->oauthService->getOAuthAccountById($primaryEmail, $oauthAccountId);
        if (!$account) {
            return false;
        }
        
        $accessToken = $this->oauthService->getValidAccessToken($primaryEmail, $account['account_email']);
        if (!$accessToken) {
            return false;
        }
        
        // Delete from Google
        $this->apiRequest(
            'DELETE',
            "/calendars/" . urlencode($mapping['google_calendar_id']) . "/events/" . urlencode($mapping['google_event_id']),
            [],
            $accessToken
        );
        
        // Delete mapping
        $stmt = $this->db->prepare('DELETE FROM calendar_sync_map WHERE id = ?');
        $stmt->execute([$mapping['id']]);
        
        return true;
    }
    
    /**
     * Update sync token
     */
    private function updateSyncToken(
        int $oauthAccountId,
        string $googleCalendarId,
        string $syncToken,
        string $connectionType = self::CONNECTION_OAUTH
    ): void
    {
        $stmt = $this->db->prepare('
            UPDATE calendar_sync_state 
            SET sync_token = ?, last_full_sync = NOW()
            WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$syncToken, $oauthAccountId, $googleCalendarId, $connectionType]);
    }
    
    // ==================== Phase 3.6: Push channels ====================

    /**
     * Establish a Google Calendar push notification channel for the given
     * sync state. Google will POST to our webhook URL whenever events on
     * the watched calendar change. Channel max TTL is 7 days but we ask
     * for 1 day so an outage doesn't strand a stale channel for a week.
     *
     * On success we persist (channel_id, resource_id, expires_at, token_hmac)
     * to calendar_push_channels. The token is signed with the project's
     * JWT secret so the webhook handler can verify the channel wasn't
     * spoofed.
     *
     * Returns true on success. Failures are non-fatal: the polling cron
     * (Phase 3.3) keeps the calendar reasonably fresh either way.
     */
    public function watchCalendar(int $syncStateId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, oauth_account_id, connection_type, google_calendar_id
                FROM calendar_sync_state
                WHERE id = ? AND sync_enabled = 1
                LIMIT 1
            ");
            $stmt->execute([$syncStateId]);
            $state = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("watchCalendar: lookup failed: " . $e->getMessage());
            return false;
        }
        if (!$state) {
            return false;
        }

        $accountOrConnId = (int)$state['oauth_account_id'];
        $type = (string)($state['connection_type'] ?? self::CONNECTION_OAUTH);
        $googleCalendarId = (string)$state['google_calendar_id'];

        $accessToken = $this->resolveAccessTokenForSyncState($type, $accountOrConnId);
        if (!$accessToken) {
            return false;
        }

        $webhookUrl = $this->getWebhookUrl();
        if (!$webhookUrl) {
            error_log('watchCalendar: webhook URL not configured');
            return false;
        }

        $channelId = bin2hex(random_bytes(16));
        $secret = $this->config['jwt']['secret'] ?? '';
        if (!is_string($secret) || $secret === '') {
            error_log('watchCalendar: jwt secret missing, cannot sign channel token');
            return false;
        }
        $tokenHmac = hash_hmac('sha256', (string)$syncStateId . ':' . $channelId, $secret);

        // Google expects expiration as a millisecond unix timestamp.
        $expiresAtMs = (int)((time() + 86400) * 1000);

        $body = [
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $webhookUrl,
            'token' => $tokenHmac,
            'expiration' => $expiresAtMs,
        ];

        $response = $this->apiRequest(
            'POST',
            '/calendars/' . urlencode($googleCalendarId) . '/events/watch',
            $body,
            $accessToken,
            true
        );

        if (!$response || empty($response['resourceId'])) {
            error_log('watchCalendar: events.watch returned no resourceId for syncState=' . $syncStateId);
            return false;
        }

        try {
            $upsert = $this->db->prepare("
                INSERT INTO calendar_push_channels
                    (channel_id, resource_id, calendar_sync_state_id, token_hmac, expires_at)
                VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE
                    resource_id = VALUES(resource_id),
                    calendar_sync_state_id = VALUES(calendar_sync_state_id),
                    token_hmac = VALUES(token_hmac),
                    expires_at = VALUES(expires_at),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $upsert->execute([
                $channelId,
                (string)$response['resourceId'],
                $syncStateId,
                $tokenHmac,
                (int)($expiresAtMs / 1000),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('watchCalendar: persist failed: ' . $e->getMessage());
            // Best-effort: try to stop the channel we just created so we don't
            // leak a webhook subscription with no DB record.
            $this->stopChannel($channelId, (string)$response['resourceId'], $accessToken);
            return false;
        }
    }

    /**
     * Tear down a Google Calendar push channel. Called by the renewal cron
     * when an old channel is about to expire (we always stop-then-watch
     * rather than relying on the implicit Google expiry).
     */
    public function unwatchCalendar(int $channelRowId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.channel_id, c.resource_id, s.oauth_account_id, s.connection_type
                FROM calendar_push_channels c
                JOIN calendar_sync_state s ON s.id = c.calendar_sync_state_id
                WHERE c.id = ?
                LIMIT 1
            ");
            $stmt->execute([$channelRowId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('unwatchCalendar lookup failed: ' . $e->getMessage());
            return false;
        }
        if (!$row) {
            return false;
        }

        $accessToken = $this->resolveAccessTokenForSyncState(
            (string)($row['connection_type'] ?? self::CONNECTION_OAUTH),
            (int)$row['oauth_account_id']
        );
        if ($accessToken) {
            $this->stopChannel((string)$row['channel_id'], (string)$row['resource_id'], $accessToken);
        }

        try {
            $this->db->prepare("DELETE FROM calendar_push_channels WHERE id = ?")->execute([$channelRowId]);
        } catch (\Throwable $e) {
            error_log('unwatchCalendar delete failed: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * Trigger a sync for the calendar associated with the given push
     * channel. Returns the sync result array or null when the channel is
     * unknown / token mismatched / expired.
     */
    public function syncForChannel(string $channelId, string $providedToken): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT c.calendar_sync_state_id, c.token_hmac, c.expires_at,
                       s.oauth_account_id, s.connection_type, s.google_calendar_id
                FROM calendar_push_channels c
                JOIN calendar_sync_state s ON s.id = c.calendar_sync_state_id
                WHERE c.channel_id = ?
                LIMIT 1
            ");
            $stmt->execute([$channelId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('syncForChannel lookup failed: ' . $e->getMessage());
            return null;
        }
        if (!$row) {
            return null;
        }
        if (!hash_equals((string)$row['token_hmac'], (string)$providedToken)) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
            return null;
        }

        $accountOrConnId = (int)$row['oauth_account_id'];
        $type = (string)($row['connection_type'] ?? self::CONNECTION_OAUTH);
        $gcalId = (string)$row['google_calendar_id'];

        $primaryEmail = $this->resolveOwnerEmailForSyncState($type, $accountOrConnId);
        if (!$primaryEmail) {
            return null;
        }

        if ($type === self::CONNECTION_CALENDAR_ONLY) {
            return $this->syncFromGoogleConnection($primaryEmail, $accountOrConnId, $gcalId);
        }
        return $this->syncFromGoogle($primaryEmail, $accountOrConnId, $gcalId);
    }

    /**
     * Renewal helper used by the cron. Returns the rows from
     * calendar_push_channels expiring within $hours hours.
     */
    public function listChannelsExpiringWithin(int $hours): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, calendar_sync_state_id, expires_at
                FROM calendar_push_channels
                WHERE expires_at < (NOW() + INTERVAL ? HOUR)
                ORDER BY expires_at ASC
            ");
            $stmt->execute([$hours]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('listChannelsExpiringWithin failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Issue the Google channels.stop call. Best-effort; failures are
     * logged but not propagated because the channel will expire on its
     * own anyway.
     */
    private function stopChannel(string $channelId, string $resourceId, string $accessToken): void
    {
        $this->apiRequest(
            'POST',
            '/channels/stop',
            ['id' => $channelId, 'resourceId' => $resourceId],
            $accessToken,
            true
        );
    }

    private function resolveAccessTokenForSyncState(string $connectionType, int $accountOrConnId): ?string
    {
        $primaryEmail = $this->resolveOwnerEmailForSyncState($connectionType, $accountOrConnId);
        if (!$primaryEmail) {
            return null;
        }
        if ($connectionType === self::CONNECTION_CALENDAR_ONLY && $this->calendarConnectionService) {
            return $this->calendarConnectionService->getValidAccessTokenById($primaryEmail, $accountOrConnId);
        }
        $account = $this->oauthService->getOAuthAccountById($primaryEmail, $accountOrConnId);
        if (!$account) {
            return null;
        }
        return $this->oauthService->getValidAccessToken($primaryEmail, $account['account_email']);
    }

    private function resolveOwnerEmailForSyncState(string $connectionType, int $accountOrConnId): ?string
    {
        try {
            if ($connectionType === self::CONNECTION_CALENDAR_ONLY) {
                $stmt = $this->db->prepare("SELECT primary_email FROM calendar_connections WHERE id = ? LIMIT 1");
            } else {
                $stmt = $this->db->prepare("SELECT primary_email FROM webmail_oauth_tokens WHERE id = ? LIMIT 1");
            }
            $stmt->execute([$accountOrConnId]);
            $email = $stmt->fetchColumn();
            return $email ?: null;
        } catch (\Throwable $e) {
            error_log('resolveOwnerEmailForSyncState failed: ' . $e->getMessage());
            return null;
        }
    }

    private function getWebhookUrl(): ?string
    {
        $base = $this->config['app']['api_url'] ?? null;
        if (!is_string($base) || $base === '') {
            return null;
        }
        return rtrim($base, '/') . '/calendar/google/webhook';
    }

    /**
     * Phase 3.1: paginate through all pages of a Google Calendar events list
     * call. Previously the sync code only consumed the first 250 items so
     * any calendar with more than 250 events between two sync runs lost the
     * tail silently. Now we walk the nextPageToken chain until exhausted (or
     * we hit the defensive cap of 50 iterations = 12500 events). The
     * nextSyncToken Google returns at the END of the chain is preserved on
     * the last response so callers can persist it as before.
     *
     * Returns null on a transport failure on the first request (callers
     * already handle null as "retry without syncToken"). On a partial
     * failure mid-pagination we return what we have so far with the
     * accumulated items plus the last seen nextSyncToken.
     */
    private function listEventsPaginated(string $googleCalendarId, array $baseParams, string $accessToken): ?array
    {
        $endpoint = '/calendars/' . urlencode($googleCalendarId) . '/events';

        $items = [];
        $nextSyncToken = null;
        $pageToken = null;
        $maxPages = 50;
        $page = 0;

        do {
            $params = $baseParams;
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
                // syncToken and pageToken are mutually exclusive after the
                // first page (the server picks up where pageToken left off
                // using the syncToken it already saw on page 1).
                unset($params['syncToken']);
            }

            $response = $this->apiRequest('GET', $endpoint, $params, $accessToken);
            if ($response === null) {
                return $page === 0 ? null : [
                    'items' => $items,
                    'nextSyncToken' => $nextSyncToken,
                    'partial' => true,
                ];
            }

            if (!empty($response['items'])) {
                foreach ($response['items'] as $item) {
                    $items[] = $item;
                }
            }
            if (isset($response['nextSyncToken'])) {
                $nextSyncToken = $response['nextSyncToken'];
            }
            $pageToken = $response['nextPageToken'] ?? null;
            $page++;
        } while ($pageToken && $page < $maxPages);

        return [
            'items' => $items,
            'nextSyncToken' => $nextSyncToken,
            'partial' => $pageToken !== null, // hit defensive cap
        ];
    }

    /**
     * Make API request to Google Calendar
     */
    private function apiRequest(string $method, string $endpoint, array $params, string $accessToken, bool $isJson = false): ?array
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
        
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($isJson) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 204) {
            return []; // Successful delete
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true) ?: [];
        }
        
        error_log("Google Calendar API error ($httpCode): $response");
        return null;
    }
    
    /**
     * Disable sync for a calendar
     */
    public function disableSync(int $oauthAccountId, string $googleCalendarId, string $connectionType = self::CONNECTION_OAUTH): bool
    {
        $stmt = $this->db->prepare('
            UPDATE calendar_sync_state SET sync_enabled = 0 
            WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$oauthAccountId, $googleCalendarId, $connectionType]);
        return $stmt->rowCount() > 0;
    }
    
    // ==================== Calendar-Only Connection Support ====================
    
    /**
     * Get Google calendars from a calendar-only connection
     */
    public function getGoogleCalendarsFromConnection(string $primaryEmail, int $connectionId): array
    {
        if (!$this->calendarConnectionService) {
            return [];
        }
        
        $connection = $this->calendarConnectionService->getConnectionById($primaryEmail, $connectionId);
        if (!$connection) {
            return [];
        }
        
        return $this->calendarConnectionService->getGoogleCalendars($primaryEmail, $connectionId);
    }
    
    /**
     * Setup sync for a calendar-only connection
     */
    public function setupSyncForConnection(string $primaryEmail, int $connectionId, string $googleCalendarId, int $localCalendarId): ?array
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO calendar_sync_state 
                (oauth_account_id, connection_type, google_calendar_id, local_calendar_id, sync_enabled)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    local_calendar_id = VALUES(local_calendar_id),
                    sync_enabled = 1
            ');
            $stmt->execute([$connectionId, self::CONNECTION_CALENDAR_ONLY, $googleCalendarId, $localCalendarId]);
            
            return $this->getSyncStateForConnection($connectionId, $googleCalendarId);
        } catch (\PDOException $e) {
            error_log("GoogleCalendarService setupSyncForConnection error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get sync state for a calendar-only connection
     */
    public function getSyncStateForConnection(int $connectionId, string $googleCalendarId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM calendar_sync_state 
            WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$connectionId, $googleCalendarId, self::CONNECTION_CALENDAR_ONLY]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get all sync configs for a calendar-only connection
     */
    public function getSyncConfigsForConnection(int $connectionId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM calendar_sync_state 
            WHERE oauth_account_id = ? AND connection_type = ? AND sync_enabled = 1
        ');
        $stmt->execute([$connectionId, self::CONNECTION_CALENDAR_ONLY]);
        return $stmt->fetchAll();
    }
    
    /**
     * Sync from Google using calendar-only connection
     */
    public function syncFromGoogleConnection(string $primaryEmail, int $connectionId, string $googleCalendarId): array
    {
        $result = ['imported' => 0, 'updated' => 0, 'errors' => []];
        
        error_log("syncFromGoogleConnection: email=$primaryEmail, connId=$connectionId, calId=$googleCalendarId");
        
        if (!$this->calendarConnectionService) {
            $result['errors'][] = 'Calendar connection service not available';
            return $result;
        }
        
        $syncState = $this->getSyncStateForConnection($connectionId, $googleCalendarId);
        if (!$syncState || !$syncState['sync_enabled']) {
            error_log("syncFromGoogleConnection: No sync state or disabled");
            $result['errors'][] = 'Sync not configured or disabled';
            return $result;
        }
        
        error_log("syncFromGoogleConnection: syncState found, local_calendar_id=" . $syncState['local_calendar_id'] . ", sync_token=" . ($syncState['sync_token'] ?: 'none'));
        
        $connection = $this->calendarConnectionService->getConnectionById($primaryEmail, $connectionId);
        if (!$connection) {
            error_log("syncFromGoogleConnection: Connection not found");
            $result['errors'][] = 'Calendar connection not found';
            return $result;
        }
        
        $accessToken = $this->calendarConnectionService->getValidAccessTokenById($primaryEmail, $connectionId);
        if (!$accessToken) {
            error_log("syncFromGoogleConnection: Failed to get access token");
            $result['errors'][] = 'Failed to get access token';
            return $result;
        }
        
        error_log("syncFromGoogleConnection: Got access token (length=" . strlen($accessToken) . ")");
        
        // Phase 3.1: paginate via shared helper (see listEventsPaginated).
        // See note above: orderBy/timeMin are invalid alongside a syncToken
        // (400 syncTokenWithNonDefaultOrdering). Only set them for the
        // initial full sync.
        $baseParams = [
            'maxResults' => 250,
            'singleEvents' => 'true',
        ];

        if ($syncState['sync_token']) {
            $baseParams['syncToken'] = $syncState['sync_token'];
        } else {
            $baseParams['orderBy'] = 'updated';
            $baseParams['timeMin'] = date('c', strtotime('-30 days'));
            $baseParams['timeMax'] = date('c', strtotime('+365 days'));
        }

        error_log("syncFromGoogleConnection: Fetching events (paginated) with base params: " . json_encode($baseParams));
        $response = $this->listEventsPaginated($googleCalendarId, $baseParams, $accessToken);
        error_log("syncFromGoogleConnection: pagination result: " . ($response === null ? 'NULL' : 'items=' . count($response['items'] ?? []) . ', partial=' . ($response['partial'] ?? false ? '1' : '0')));

        if ($response === null) {
            if ($syncState['sync_token']) {
                error_log("syncFromGoogleConnection: Retrying without sync token");
                $fallback = [
                    'maxResults' => 250,
                    'singleEvents' => 'true',
                    'orderBy' => 'updated',
                    'timeMin' => date('c', strtotime('-30 days')),
                    'timeMax' => date('c', strtotime('+365 days')),
                ];
                $response = $this->listEventsPaginated($googleCalendarId, $fallback, $accessToken);
                error_log("syncFromGoogleConnection: Retry pagination result: " . ($response === null ? 'NULL' : 'items=' . count($response['items'] ?? [])));
            }

            if ($response === null) {
                $result['errors'][] = 'Failed to fetch Google Calendar events';
                return $result;
            }
        }
        
        $localCalendarId = $syncState['local_calendar_id'];
        
        foreach ($response['items'] ?? [] as $googleEvent) {
            try {
                if ($googleEvent['status'] === 'cancelled') {
                    $this->handleDeletedGoogleEventForConnection($googleEvent['id'], $connectionId, $googleCalendarId);
                    continue;
                }
                
                $imported = $this->importGoogleEventForConnection($primaryEmail, $googleEvent, $localCalendarId, $connectionId, $googleCalendarId);
                if ($imported === 'created') {
                    $result['imported']++;
                } elseif ($imported === 'updated') {
                    $result['updated']++;
                }
            } catch (\Exception $e) {
                $result['errors'][] = "Error importing event {$googleEvent['id']}: " . $e->getMessage();
            }
        }
        
        // Phase 3.1: only advance the sync token when pagination completed.
        if (!empty($response['nextSyncToken']) && empty($response['partial'])) {
            $this->updateSyncTokenForConnection($connectionId, $googleCalendarId, $response['nextSyncToken']);
        }
        
        return $result;
    }
    
    /**
     * Import Google event for calendar-only connection
     */
    private function importGoogleEventForConnection(string $primaryEmail, array $googleEvent, int $localCalendarId, int $connectionId, string $googleCalendarId): string
    {
        return $this->importGoogleEvent(
            $primaryEmail,
            $googleEvent,
            $localCalendarId,
            $connectionId,
            $googleCalendarId,
            self::CONNECTION_CALENDAR_ONLY
        );
    }
    
    /**
     * Handle deleted Google event for calendar-only connection
     */
    private function handleDeletedGoogleEventForConnection(string $googleEventId, int $connectionId, string $googleCalendarId): void
    {
        $this->handleDeletedGoogleEvent(
            $googleEventId,
            $connectionId,
            $googleCalendarId,
            self::CONNECTION_CALENDAR_ONLY
        );
    }
    
    /**
     * Update sync token for calendar-only connection
     */
    private function updateSyncTokenForConnection(int $connectionId, string $googleCalendarId, string $syncToken): void
    {
        $this->updateSyncToken($connectionId, $googleCalendarId, $syncToken, self::CONNECTION_CALENDAR_ONLY);
    }
    
    // ==================== Desync with Cleanup ====================
    
    /**
     * Disable sync AND delete all synced events from local calendar
     * Used when user wants to completely remove synced content
     */
    public function desyncWithCleanup(int $accountId, string $googleCalendarId, string $connectionType = self::CONNECTION_OAUTH): array
    {
        $result = ['deleted_events' => 0, 'success' => false, 'debug' => []];
        
        error_log("desyncWithCleanup: accountId=$accountId, googleCalendarId=$googleCalendarId, connectionType=$connectionType");
        
        try {
            $this->db->beginTransaction();
            
            // First check what we have in the database
            $debugStmt = $this->db->prepare('
                SELECT id, local_event_id, google_event_id, google_calendar_id, oauth_account_id, connection_type 
                FROM calendar_sync_map 
                WHERE oauth_account_id = ? AND connection_type = ?
            ');
            $debugStmt->execute([$accountId, $connectionType]);
            $allMappings = $debugStmt->fetchAll();
            error_log("desyncWithCleanup: Found " . count($allMappings) . " total mappings for account $accountId with type $connectionType");
            
            foreach ($allMappings as $m) {
                error_log("  - Mapping: google_calendar_id={$m['google_calendar_id']}, local_event_id={$m['local_event_id']}");
            }
            
            $syncState = $this->getSyncState($accountId, $googleCalendarId, $connectionType);
            $result['local_calendar_id'] = $syncState['local_calendar_id'] ?? null;

            // Get all synced events for this calendar
            $stmt = $this->db->prepare('
                SELECT local_event_id FROM calendar_sync_map 
                WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $googleCalendarId, $connectionType]);
            $mappings = $stmt->fetchAll();
            
            error_log("desyncWithCleanup: Found " . count($mappings) . " mappings matching googleCalendarId=$googleCalendarId");
            $result['debug']['mappings_found'] = count($mappings);
            
            $eventIds = array_map(fn($mapping) => (int)$mapping['local_event_id'], $mappings);
            $result['deleted_events'] = $this->calendarService->deleteEventsByIds($eventIds);
            
            // Delete all sync mappings for this calendar
            $stmt = $this->db->prepare('
                DELETE FROM calendar_sync_map 
                WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $googleCalendarId, $connectionType]);
            $result['debug']['mappings_deleted'] = $stmt->rowCount();
            
            // Delete sync state
            $stmt = $this->db->prepare('
                DELETE FROM calendar_sync_state 
                WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $googleCalendarId, $connectionType]);
            $result['debug']['states_deleted'] = $stmt->rowCount();
            
            $this->db->commit();
            $result['success'] = true;
            
            error_log("desyncWithCleanup: Success - deleted {$result['deleted_events']} events");
            
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("GoogleCalendarService desyncWithCleanup error: " . $e->getMessage());
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Disable sync but KEEP synced events (mark them as local-only)
     * Events will no longer sync but remain in calendar
     */
    public function desyncKeepEvents(int $accountId, string $googleCalendarId, string $connectionType = self::CONNECTION_OAUTH): array
    {
        $result = ['kept_events' => 0, 'success' => false];
        
        try {
            $this->db->beginTransaction();

            $syncState = $this->getSyncState($accountId, $googleCalendarId, $connectionType);
            $result['local_calendar_id'] = $syncState['local_calendar_id'] ?? null;
            
            // Count events that will be kept
            $stmt = $this->db->prepare('
                SELECT COUNT(*) as count FROM calendar_sync_map 
                WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $googleCalendarId, $connectionType]);
            $result['kept_events'] = (int)$stmt->fetch()['count'];
            
            // Delete all sync mappings (events become local-only)
            $stmt = $this->db->prepare('
                DELETE FROM calendar_sync_map 
                WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $googleCalendarId, $connectionType]);
            
            // Delete sync state
            $stmt = $this->db->prepare('
                DELETE FROM calendar_sync_state 
                WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $googleCalendarId, $connectionType]);
            
            $this->db->commit();
            $result['success'] = true;
            
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("GoogleCalendarService desyncKeepEvents error: " . $e->getMessage());
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Remove all syncs for an OAuth account (when account is removed)
     */
    public function removeAllSyncsForAccount(int $accountId, string $connectionType = self::CONNECTION_OAUTH, bool $deleteEvents = true): array
    {
        $result = ['calendars_removed' => 0, 'events_deleted' => 0];
        
        // Get all sync configs for this account
        $stmt = $this->db->prepare('
            SELECT google_calendar_id FROM calendar_sync_state 
            WHERE oauth_account_id = ? AND connection_type = ?
        ');
        $stmt->execute([$accountId, $connectionType]);
        $syncs = $stmt->fetchAll();
        
        foreach ($syncs as $sync) {
            if ($deleteEvents) {
                $cleanup = $this->desyncWithCleanup($accountId, $sync['google_calendar_id'], $connectionType);
                $result['events_deleted'] += $cleanup['deleted_events'] ?? 0;
            } else {
                $this->desyncKeepEvents($accountId, $sync['google_calendar_id'], $connectionType);
            }
            $result['calendars_removed']++;
        }
        
        return $result;
    }
    
    /**
     * Get all synced calendars info for an account
     */
    public function getSyncedCalendarsInfo(int $accountId, string $connectionType = self::CONNECTION_OAUTH): array
    {
        $stmt = $this->db->prepare('
            SELECT 
                css.*,
                (SELECT COUNT(*) FROM calendar_sync_map csm 
                 WHERE csm.oauth_account_id = css.oauth_account_id 
                 AND csm.google_calendar_id = css.google_calendar_id 
                 AND csm.connection_type = css.connection_type) as synced_events_count
            FROM calendar_sync_state css
            WHERE css.oauth_account_id = ? AND css.connection_type = ?
        ');
        $stmt->execute([$accountId, $connectionType]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get count of synced events for a specific calendar
     */
    public function getSyncedEventsCount(int $accountId, string $googleCalendarId, string $connectionType = self::CONNECTION_OAUTH): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count FROM calendar_sync_map 
            WHERE oauth_account_id = ? AND google_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$accountId, $googleCalendarId, $connectionType]);
        return (int)$stmt->fetch()['count'];
    }

    /**
     * Serialize import/push work per remote event so overlapping sync runs do not duplicate rows.
     */
    private function withSyncLock(string $lockName, callable $callback): mixed
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 10) AS acquired');
        $stmt->execute([$lockName]);
        $acquired = (int)($stmt->fetch()['acquired'] ?? 0) === 1;

        if (!$acquired) {
            throw new \RuntimeException("Failed to acquire sync lock: {$lockName}");
        }

        try {
            return $callback();
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}

