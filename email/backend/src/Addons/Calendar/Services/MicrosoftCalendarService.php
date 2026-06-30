<?php

namespace Webmail\Addons\Calendar\Services;

use Webmail\Services\MicrosoftOAuthService;

/**
 * MicrosoftCalendarService - Handles Microsoft/Outlook Calendar sync via Graph API
 */
class MicrosoftCalendarService
{
    private array $config;
    private \PDO $db;
    private ?MicrosoftOAuthService $oauthService = null;
    private ?CalendarService $calendarService = null;
    
    private const GRAPH_API_URL = 'https://graph.microsoft.com/v1.0';
    public const CONNECTION_OAUTH = 'microsoft_oauth';
    public const CONNECTION_CALENDAR_ONLY = 'microsoft_calendar_only';
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        if (!empty($config['microsoft_oauth']['client_id'])) {
            $this->oauthService = new MicrosoftOAuthService($config);
        }
        
        $this->calendarService = new CalendarService($config);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
    }
    
    private function ensureTablesExist(): void
    {
        try {
            // Microsoft calendar sync map - stores mapping between local and Microsoft events
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ms_calendar_sync_map (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    local_event_id INT NOT NULL,
                    ms_event_id VARCHAR(255) NOT NULL,
                    ms_calendar_id VARCHAR(255) NOT NULL,
                    oauth_account_id INT NOT NULL,
                    connection_type VARCHAR(50) DEFAULT 'microsoft_oauth',
                    sync_direction ENUM('ms_to_local', 'local_to_ms', 'bidirectional') DEFAULT 'bidirectional',
                    last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ms_event (ms_event_id),
                    INDEX idx_local_event (local_event_id),
                    INDEX idx_oauth_account (oauth_account_id),
                    UNIQUE KEY unique_sync (local_event_id, ms_event_id, oauth_account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Microsoft calendar sync state - stores sync state per calendar
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ms_calendar_sync_state (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    oauth_account_id INT NOT NULL,
                    ms_calendar_id VARCHAR(255) NOT NULL,
                    local_calendar_id INT NOT NULL,
                    connection_type VARCHAR(50) DEFAULT 'microsoft_oauth',
                    delta_link TEXT COMMENT 'Delta link for incremental sync',
                    sync_enabled TINYINT(1) DEFAULT 1,
                    last_full_sync TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_sync_state (oauth_account_id, ms_calendar_id, connection_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
        } catch (\PDOException $e) {
            error_log("MicrosoftCalendarService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Make authenticated request to Microsoft Graph API
     */
    private function apiRequest(string $method, string $endpoint, ?array $data = null, string $accessToken = null): ?array
    {
        $url = str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')
            ? $endpoint
            : self::GRAPH_API_URL . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Prefer: outlook.timezone="UTC"',
            ],
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'GET' && $data) {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            error_log("Microsoft Graph API error ($httpCode): $response");
            return null;
        }
        
        if (empty($response)) {
            return ['success' => true];
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Get list of Microsoft/Outlook calendars
     */
    public function getCalendars(string $primaryEmail, int $oauthAccountId): array
    {
        $account = $this->oauthService->getOAuthAccountById($primaryEmail, $oauthAccountId);
        if (!$account) {
            return [];
        }
        
        $accessToken = $this->oauthService->getValidAccessToken($primaryEmail, $account['account_email']);
        if (!$accessToken) {
            return [];
        }
        
        $response = $this->apiRequest('GET', '/me/calendars', null, $accessToken);
        
        if (!$response || !isset($response['value'])) {
            return [];
        }
        
        return array_map(function($cal) {
            return [
                'id' => $cal['id'],
                'name' => $cal['name'],
                'color' => $this->mapMicrosoftColor($cal['color'] ?? 'auto'),
                'isDefault' => $cal['isDefaultCalendar'] ?? false,
                'canEdit' => $cal['canEdit'] ?? true,
            ];
        }, $response['value']);
    }
    
    /**
     * Map Microsoft color names to hex colors
     */
    private function mapMicrosoftColor(string $msColor): string
    {
        $colors = [
            'auto' => '#3b82f6',
            'lightBlue' => '#60a5fa',
            'lightGreen' => '#4ade80',
            'lightOrange' => '#fb923c',
            'lightGray' => '#9ca3af',
            'lightYellow' => '#facc15',
            'lightTeal' => '#2dd4bf',
            'lightPink' => '#f472b6',
            'lightBrown' => '#a8a29e',
            'lightRed' => '#f87171',
            'maxColor' => '#a855f7',
        ];
        
        return $colors[$msColor] ?? '#3b82f6';
    }
    
    /**
     * Setup sync between Microsoft and local calendar
     */
    public function setupSync(int $oauthAccountId, string $msCalendarId, int $localCalendarId, string $connectionType = self::CONNECTION_OAUTH): array
    {
        $result = ['success' => false];
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO ms_calendar_sync_state 
                (oauth_account_id, ms_calendar_id, local_calendar_id, connection_type, sync_enabled)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    local_calendar_id = VALUES(local_calendar_id),
                    sync_enabled = 1,
                    updated_at = NOW()
            ');
            $stmt->execute([$oauthAccountId, $msCalendarId, $localCalendarId, $connectionType]);
            
            $result['success'] = true;
            $result['sync'] = [
                'oauth_account_id' => $oauthAccountId,
                'ms_calendar_id' => $msCalendarId,
                'local_calendar_id' => $localCalendarId,
            ];
            
        } catch (\PDOException $e) {
            error_log("MicrosoftCalendarService setupSync error: " . $e->getMessage());
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Get sync state for a calendar
     */
    public function getSyncState(int $oauthAccountId, string $msCalendarId, string $connectionType = self::CONNECTION_OAUTH): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM ms_calendar_sync_state 
            WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$oauthAccountId, $msCalendarId, $connectionType]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Sync events from Microsoft Calendar to local
     */
    public function syncFromMicrosoft(string $primaryEmail, int $oauthAccountId, string $msCalendarId): array
    {
        $result = ['imported' => 0, 'updated' => 0, 'errors' => []];
        
        $syncState = $this->getSyncState($oauthAccountId, $msCalendarId);
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
        
        $response = null;
        if (!empty($syncState['delta_link'])) {
            $response = $this->apiRequest('GET', $syncState['delta_link'], null, $accessToken);
        }

        if (!$response) {
            $endpoint = "/me/calendars/" . urlencode($msCalendarId) . "/events";
            $params = [
                '$top' => 250,
                '$select' => 'id,subject,body,start,end,location,isAllDay,recurrence,showAs,sensitivity',
                '$orderby' => 'lastModifiedDateTime desc',
            ];

            $startDate = date('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
            $endDate = date('Y-m-d\TH:i:s\Z', strtotime('+365 days'));
            $params['$filter'] = "start/dateTime ge '$startDate' and start/dateTime le '$endDate'";

            $response = $this->apiRequest('GET', $endpoint . '?' . http_build_query($params), null, $accessToken);
        }
        
        if (!$response) {
            $result['errors'][] = 'Failed to fetch Microsoft Calendar events';
            return $result;
        }
        
        $localCalendarId = $syncState['local_calendar_id'];
        
        // Process events
        foreach ($response['value'] ?? [] as $msEvent) {
            try {
                if (isset($msEvent['@removed'])) {
                    $this->handleDeletedMicrosoftEvent($msEvent['id'], $oauthAccountId, $msCalendarId, self::CONNECTION_OAUTH);
                    continue;
                }

                $imported = $this->importMicrosoftEvent($primaryEmail, $msEvent, $localCalendarId, $oauthAccountId, $msCalendarId);
                if ($imported === 'created') {
                    $result['imported']++;
                } elseif ($imported === 'updated') {
                    $result['updated']++;
                }
            } catch (\Exception $e) {
                $result['errors'][] = "Error importing event {$msEvent['id']}: " . $e->getMessage();
            }
        }
        
        // Store delta link if available
        if (isset($response['@odata.deltaLink'])) {
            $this->updateDeltaLink($oauthAccountId, $msCalendarId, $response['@odata.deltaLink']);
        }
        
        return $result;
    }
    
    /**
     * Import a Microsoft event to local calendar
     */
    private function importMicrosoftEvent(string $primaryEmail, array $msEvent, int $localCalendarId, int $oauthAccountId, string $msCalendarId): string
    {
        $lockName = sprintf('ms-import:%s:%d:%s:%s', self::CONNECTION_OAUTH, $oauthAccountId, $msCalendarId, $msEvent['id']);

        return $this->withSyncLock($lockName, function() use ($primaryEmail, $msEvent, $localCalendarId, $oauthAccountId, $msCalendarId) {
            $stmt = $this->db->prepare('
                SELECT local_event_id FROM ms_calendar_sync_map 
                WHERE ms_event_id = ? AND oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
                LIMIT 1
            ');
            $stmt->execute([$msEvent['id'], $oauthAccountId, $msCalendarId, self::CONNECTION_OAUTH]);
            $existing = $stmt->fetch();

            $eventData = $this->parseMicrosoftEvent($msEvent);

            if ($existing) {
                $this->calendarService->updateEvent($primaryEmail, (int)$existing['local_event_id'], $eventData);
                return 'updated';
            }

            $localEvent = $this->calendarService->createEvent($primaryEmail, $localCalendarId, $eventData);

            if ($localEvent) {
                $stmt = $this->db->prepare('
                    INSERT INTO ms_calendar_sync_map 
                    (local_event_id, ms_event_id, ms_calendar_id, oauth_account_id, connection_type, sync_direction)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $localEvent['id'],
                    $msEvent['id'],
                    $msCalendarId,
                    $oauthAccountId,
                    self::CONNECTION_OAUTH,
                    'ms_to_local',
                ]);

                return 'created';
            }

            return 'skipped';
        });
    }
    
    /**
     * Parse Microsoft event to local format
     */
    private function parseMicrosoftEvent(array $msEvent): array
    {
        $isAllDay = $msEvent['isAllDay'] ?? false;
        
        // Parse start/end times
        $startTime = $msEvent['start']['dateTime'] ?? date('Y-m-d H:i:s');
        $endTime = $msEvent['end']['dateTime'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Microsoft returns times in the specified timezone or UTC
        if (strpos($startTime, 'Z') === false && !$isAllDay) {
            $startTime .= 'Z';
            $endTime .= 'Z';
        }
        
        // Convert Microsoft recurrence pattern to iCal RRULE format
        $rrule = null;
        if (!empty($msEvent['recurrence'])) {
            $rrule = $this->convertMicrosoftRecurrenceToRRule($msEvent['recurrence']);
        }
        
        return [
            'title' => $msEvent['subject'] ?? 'Untitled Event',
            'description' => strip_tags($msEvent['body']['content'] ?? ''),
            'location' => $msEvent['location']['displayName'] ?? null,
            'start_time' => date('Y-m-d H:i:s', strtotime($startTime)),
            'end_time' => date('Y-m-d H:i:s', strtotime($endTime)),
            'all_day' => $isAllDay,
            'recurrence' => $rrule,
            'color' => '#0078d4', // Microsoft blue
        ];
    }

    /**
     * Remove a local event when Microsoft reports the remote event as deleted.
     */
    private function handleDeletedMicrosoftEvent(
        string $msEventId,
        int $oauthAccountId,
        string $msCalendarId,
        string $connectionType = self::CONNECTION_OAUTH
    ): void
    {
        $stmt = $this->db->prepare('
            SELECT id, local_event_id
            FROM ms_calendar_sync_map
            WHERE ms_event_id = ? AND oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
            LIMIT 1
        ');
        $stmt->execute([$msEventId, $oauthAccountId, $msCalendarId, $connectionType]);
        $mapping = $stmt->fetch();

        if (!$mapping) {
            return;
        }

        $this->calendarService->deleteEventById((int)$mapping['local_event_id']);
        $stmt = $this->db->prepare('DELETE FROM ms_calendar_sync_map WHERE id = ?');
        $stmt->execute([$mapping['id']]);
    }
    
    /**
     * Convert Microsoft Graph API recurrence object to iCal RRULE string.
     * 
     * Microsoft format: { "pattern": { "type": "weekly", "interval": 1, ... }, "range": { "type": "endDate", ... } }
     * iCal format: RRULE:FREQ=WEEKLY;INTERVAL=1;UNTIL=20270803T235959Z
     */
    private function convertMicrosoftRecurrenceToRRule(array $recurrence): ?string
    {
        $pattern = $recurrence['pattern'] ?? null;
        $range = $recurrence['range'] ?? null;
        
        if (!$pattern || empty($pattern['type'])) {
            return null;
        }
        
        // Map Microsoft pattern type to iCal FREQ
        $freqMap = [
            'daily' => 'DAILY',
            'weekly' => 'WEEKLY',
            'absoluteMonthly' => 'MONTHLY',
            'relativeMonthly' => 'MONTHLY',
            'absoluteYearly' => 'YEARLY',
            'relativeYearly' => 'YEARLY',
        ];
        
        $freq = $freqMap[$pattern['type']] ?? null;
        if (!$freq) {
            error_log("[MicrosoftCalendarService] Unknown recurrence pattern type: {$pattern['type']}");
            return null;
        }
        
        $parts = ["FREQ={$freq}"];
        
        // Interval (default 1)
        $interval = $pattern['interval'] ?? 1;
        if ($interval > 1) {
            $parts[] = "INTERVAL={$interval}";
        }
        
        // Range handling
        if ($range) {
            switch ($range['type'] ?? '') {
                case 'endDate':
                    if (!empty($range['endDate'])) {
                        // Convert end date to iCal UNTIL format: YYYYMMDDTHHMMSSZ
                        $until = str_replace('-', '', $range['endDate']) . 'T235959Z';
                        $parts[] = "UNTIL={$until}";
                    }
                    break;
                case 'numbered':
                    if (!empty($range['numberOfOccurrences'])) {
                        $parts[] = "COUNT={$range['numberOfOccurrences']}";
                    }
                    break;
                case 'noEnd':
                    // No UNTIL or COUNT = infinite recurrence (expansion capped by backend limit)
                    break;
            }
        }
        
        $rrule = 'RRULE:' . implode(';', $parts);
        error_log("[MicrosoftCalendarService] Converted recurrence: " . json_encode($recurrence) . " -> {$rrule}");
        
        return $rrule;
    }
    
    /**
     * Update delta link for incremental sync
     */
    private function updateDeltaLink(
        int $oauthAccountId,
        string $msCalendarId,
        string $deltaLink,
        string $connectionType = self::CONNECTION_OAUTH
    ): void
    {
        $stmt = $this->db->prepare('
            UPDATE ms_calendar_sync_state 
            SET delta_link = ?, last_full_sync = NOW()
            WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$deltaLink, $oauthAccountId, $msCalendarId, $connectionType]);
    }
    
    /**
     * Check if a Microsoft calendar is synced
     */
    public function isMicrosoftCalendarSynced(int $oauthAccountId, string $msCalendarId, string $connectionType = self::CONNECTION_OAUTH): bool
    {
        $stmt = $this->db->prepare('
            SELECT sync_enabled FROM ms_calendar_sync_state 
            WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ? AND sync_enabled = 1
        ');
        $stmt->execute([$oauthAccountId, $msCalendarId, $connectionType]);
        return (bool)$stmt->fetch();
    }
    
    /**
     * Get sync configs for an account
     */
    public function getSyncConfigs(int $oauthAccountId, string $connectionType = self::CONNECTION_OAUTH): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM ms_calendar_sync_state 
            WHERE oauth_account_id = ? AND connection_type = ? AND sync_enabled = 1
        ');
        $stmt->execute([$oauthAccountId, $connectionType]);
        return $stmt->fetchAll();
    }
    
    /**
     * Disable sync and optionally delete events
     */
    public function desyncWithCleanup(int $accountId, string $msCalendarId, string $connectionType = self::CONNECTION_OAUTH): array
    {
        $result = ['deleted_events' => 0, 'success' => false];
        
        try {
            $this->db->beginTransaction();

            $syncState = $this->getSyncState($accountId, $msCalendarId, $connectionType);
            $result['local_calendar_id'] = $syncState['local_calendar_id'] ?? null;
            
            // Get all synced events for this calendar
            $stmt = $this->db->prepare('
                SELECT local_event_id FROM ms_calendar_sync_map 
                WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $msCalendarId, $connectionType]);
            $mappings = $stmt->fetchAll();
            
            $eventIds = array_map(fn($mapping) => (int)$mapping['local_event_id'], $mappings);
            $result['deleted_events'] = $this->calendarService->deleteEventsByIds($eventIds);
            
            // Delete sync mappings
            $stmt = $this->db->prepare('
                DELETE FROM ms_calendar_sync_map 
                WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $msCalendarId, $connectionType]);
            
            // Delete sync state
            $stmt = $this->db->prepare('
                DELETE FROM ms_calendar_sync_state 
                WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $msCalendarId, $connectionType]);
            
            $this->db->commit();
            $result['success'] = true;
            
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("MicrosoftCalendarService desyncWithCleanup error: " . $e->getMessage());
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Disable sync but keep events
     */
    public function desyncKeepEvents(int $accountId, string $msCalendarId, string $connectionType = self::CONNECTION_OAUTH): array
    {
        $result = ['kept_events' => 0, 'success' => false];
        
        try {
            $this->db->beginTransaction();

            $syncState = $this->getSyncState($accountId, $msCalendarId, $connectionType);
            $result['local_calendar_id'] = $syncState['local_calendar_id'] ?? null;
            
            // Count events that will be kept
            $stmt = $this->db->prepare('
                SELECT COUNT(*) as count FROM ms_calendar_sync_map 
                WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $msCalendarId, $connectionType]);
            $result['kept_events'] = (int)$stmt->fetch()['count'];
            
            // Delete sync mappings (events become local-only)
            $stmt = $this->db->prepare('
                DELETE FROM ms_calendar_sync_map 
                WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $msCalendarId, $connectionType]);
            
            // Delete sync state
            $stmt = $this->db->prepare('
                DELETE FROM ms_calendar_sync_state 
                WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
            ');
            $stmt->execute([$accountId, $msCalendarId, $connectionType]);
            
            $this->db->commit();
            $result['success'] = true;
            
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("MicrosoftCalendarService desyncKeepEvents error: " . $e->getMessage());
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Get synced events count
     */
    public function getSyncedEventsCount(int $accountId, string $msCalendarId, string $connectionType = self::CONNECTION_OAUTH): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count FROM ms_calendar_sync_map 
            WHERE oauth_account_id = ? AND ms_calendar_id = ? AND connection_type = ?
        ');
        $stmt->execute([$accountId, $msCalendarId, $connectionType]);
        return (int)$stmt->fetch()['count'];
    }

    private function withSyncLock(string $lockName, callable $callback): mixed
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 10) AS acquired');
        $stmt->execute([$lockName]);
        $acquired = (int)($stmt->fetch()['acquired'] ?? 0) === 1;

        if (!$acquired) {
            throw new \RuntimeException("Failed to acquire Microsoft sync lock: {$lockName}");
        }

        try {
            return $callback();
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}

