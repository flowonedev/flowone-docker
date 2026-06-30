<?php

namespace Webmail\Addons\TimeTracker\Services;

/**
 * ClientTimeTrackingService - Per-client time tracking across all activities
 * 
 * Tracks time spent on each client by activity type (email, calendar, boards, drive)
 * for both the owner and all team members.
 */
class ClientTimeTrackingService
{
    public const ACTIVITY_TYPES = [
        'email_read', 'email_compose', 'calendar_event',
        'board_view', 'board_task', 'drive_browse',
        'document_open', 'document_edit', 'website_work',
        'mood_board_view', 'mood_board_edit',
        'client_call', 'manual_entry'
    ];

    private \PDO $db;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
    }
    
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_client_time_tracking (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    client_id INT UNSIGNED NOT NULL,
                    activity_type ENUM('email_read', 'email_compose', 'calendar_event', 
                                       'board_view', 'board_task', 'drive_browse', 
                                       'document_open', 'document_edit', 'website_work',
                                       'mood_board_view', 'mood_board_edit',
                                       'client_call', 'manual_entry') NOT NULL,
                    entity_id VARCHAR(255) DEFAULT NULL,
                    entity_name VARCHAR(500) DEFAULT NULL,
                    source ENUM('cloud', 'local_watch') DEFAULT 'cloud',
                    duration_seconds INT NOT NULL DEFAULT 0,
                    tracked_date DATE NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_track (user_email, client_id, activity_type, entity_id, tracked_date),
                    INDEX idx_user_client_date (user_email, client_id, tracked_date),
                    INDEX idx_client_date (client_id, tracked_date),
                    INDEX idx_activity (activity_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log("ClientTimeTrackingService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Track time for a specific activity
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to accumulate time
     */
    public function trackActivity(
        string $userEmail,
        int $clientId,
        string $activityType,
        int $durationSeconds,
        ?string $entityId = null,
        ?string $entityName = null,
        string $source = 'cloud'
    ): bool {
        $userEmail = strtolower($userEmail);
        $today = date('Y-m-d');
        
        if (!in_array($activityType, self::ACTIVITY_TYPES, true)) {
            error_log("ClientTimeTrackingService trackActivity: rejected unknown activity_type '{$activityType}'");
            return false;
        }
        
        // Sanitize entity_id for uniqueness (null becomes empty string for unique key)
        $entityIdForKey = $entityId ?? '';
        
        // Ensure source column exists (self-healing for pre-migration state)
        $validSources = ['cloud', 'local_watch'];
        if (!in_array($source, $validSources)) $source = 'cloud';
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_client_time_tracking 
                    (user_email, client_id, activity_type, entity_id, entity_name, source, duration_seconds, tracked_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    duration_seconds = duration_seconds + VALUES(duration_seconds),
                    entity_name = COALESCE(VALUES(entity_name), entity_name),
                    updated_at = CURRENT_TIMESTAMP
            ');
            
            $stmt->execute([
                $userEmail,
                $clientId,
                $activityType,
                $entityIdForKey ?: null,
                $entityName,
                $source,
                $durationSeconds,
                $today
            ]);
            
            return true;
        } catch (\PDOException $e) {
            error_log("ClientTimeTrackingService trackActivity error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get time statistics for a client (for a specific user)
     */
    public function getMyTimeStats(string $userEmail, int $clientId, string $period = 'week'): array
    {
        $userEmail = strtolower($userEmail);
        $dateCondition = $this->getDateCondition($period);
        
        // Total time
        $stmt = $this->db->prepare('
            SELECT SUM(duration_seconds) as total
            FROM webmail_client_time_tracking
            WHERE user_email = ? AND client_id = ? AND tracked_date >= ?
        ');
        $stmt->execute([$userEmail, $clientId, $dateCondition]);
        $totalSeconds = (int)($stmt->fetch()['total'] ?? 0);
        
        // Time by activity type
        $stmt = $this->db->prepare('
            SELECT activity_type, SUM(duration_seconds) as total
            FROM webmail_client_time_tracking
            WHERE user_email = ? AND client_id = ? AND tracked_date >= ?
            GROUP BY activity_type
            ORDER BY total DESC
        ');
        $stmt->execute([$userEmail, $clientId, $dateCondition]);
        $byActivity = [];
        foreach ($stmt->fetchAll() as $row) {
            $byActivity[$row['activity_type']] = (int)$row['total'];
        }
        
        return [
            'total_seconds' => $totalSeconds,
            'by_activity' => $byActivity
        ];
    }
    
    /**
     * Get team time statistics for a client (all members)
     */
    public function getTeamTimeStats(int $clientId, string $period = 'week'): array
    {
        $dateCondition = $this->getDateCondition($period);
        
        // Total team time
        $stmt = $this->db->prepare('
            SELECT SUM(duration_seconds) as total
            FROM webmail_client_time_tracking
            WHERE client_id = ? AND tracked_date >= ?
        ');
        $stmt->execute([$clientId, $dateCondition]);
        $totalSeconds = (int)($stmt->fetch()['total'] ?? 0);
        
        // Time by member - try with organization_colleagues JOIN first, fallback to without
        $byMember = [];
        try {
            $stmt = $this->db->prepare('
                SELECT t.user_email, SUM(t.duration_seconds) as total,
                       oc.display_name
                FROM webmail_client_time_tracking t
                LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = LOWER(t.user_email)
                WHERE t.client_id = ? AND t.tracked_date >= ?
                GROUP BY t.user_email, oc.display_name
                ORDER BY total DESC
            ');
            $stmt->execute([$clientId, $dateCondition]);
            foreach ($stmt->fetchAll() as $row) {
                $email = $row['user_email'];
                $total = (int)$row['total'];
                $byMember[] = [
                    'email' => $email,
                    'name' => $row['display_name'] ?: explode('@', $email)[0],
                    'seconds' => $total,
                    'total_seconds' => $total
                ];
            }
        } catch (\PDOException $e) {
            // Fallback: query without organization_colleagues join
            error_log("getTeamTimeStats: organization_colleagues JOIN failed, using fallback: " . $e->getMessage());
            $stmt = $this->db->prepare('
                SELECT user_email, SUM(duration_seconds) as total
                FROM webmail_client_time_tracking
                WHERE client_id = ? AND tracked_date >= ?
                GROUP BY user_email
                ORDER BY total DESC
            ');
            $stmt->execute([$clientId, $dateCondition]);
            foreach ($stmt->fetchAll() as $row) {
                $email = $row['user_email'];
                $total = (int)$row['total'];
                $byMember[] = [
                    'email' => $email,
                    'name' => explode('@', $email)[0],
                    'seconds' => $total,
                    'total_seconds' => $total
                ];
            }
        }
        
        // Time by activity type (all members combined)
        $stmt = $this->db->prepare('
            SELECT activity_type, SUM(duration_seconds) as total
            FROM webmail_client_time_tracking
            WHERE client_id = ? AND tracked_date >= ?
            GROUP BY activity_type
            ORDER BY total DESC
        ');
        $stmt->execute([$clientId, $dateCondition]);
        $byActivity = [];
        foreach ($stmt->fetchAll() as $row) {
            $byActivity[$row['activity_type']] = (int)$row['total'];
        }
        
        return [
            'total_seconds' => $totalSeconds,
            'by_member' => $byMember,
            'by_activity' => $byActivity
        ];
    }
    
    /**
     * Get daily breakdown of time spent
     */
    public function getDailyBreakdown(
        int $clientId,
        string $userEmail,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $userEmail = strtolower($userEmail);
        $startDate = $startDate ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $endDate ?? date('Y-m-d');
        
        // Cap to maximum 90 days to prevent memory issues (e.g. period='all' spans from 1970)
        $maxStart = date('Y-m-d', strtotime('-90 days'));
        if ($startDate < $maxStart) {
            $startDate = $maxStart;
        }
        
        // My daily time
        $stmt = $this->db->prepare('
            SELECT tracked_date, SUM(duration_seconds) as total
            FROM webmail_client_time_tracking
            WHERE client_id = ? AND user_email = ? AND tracked_date BETWEEN ? AND ?
            GROUP BY tracked_date
            ORDER BY tracked_date
        ');
        $stmt->execute([$clientId, $userEmail, $startDate, $endDate]);
        $myDaily = [];
        foreach ($stmt->fetchAll() as $row) {
            $myDaily[$row['tracked_date']] = (int)$row['total'];
        }
        
        // Team daily time (excluding my time)
        $stmt = $this->db->prepare('
            SELECT tracked_date, SUM(duration_seconds) as total
            FROM webmail_client_time_tracking
            WHERE client_id = ? AND tracked_date BETWEEN ? AND ?
            GROUP BY tracked_date
            ORDER BY tracked_date
        ');
        $stmt->execute([$clientId, $startDate, $endDate]);
        $teamDaily = [];
        foreach ($stmt->fetchAll() as $row) {
            $teamDaily[$row['tracked_date']] = (int)$row['total'];
        }
        
        // Build combined daily breakdown
        $result = [];
        $period = new \DatePeriod(
            new \DateTime($startDate),
            new \DateInterval('P1D'),
            (new \DateTime($endDate))->modify('+1 day')
        );
        
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $result[] = [
                'date' => $dateStr,
                'my_seconds' => $myDaily[$dateStr] ?? 0,
                'team_seconds' => $teamDaily[$dateStr] ?? 0
            ];
        }
        
        return $result;
    }
    
    /**
     * Get cumulative total time for a client (all time)
     */
    public function getCumulativeTime(int $clientId, ?string $userEmail = null): array
    {
        if ($userEmail) {
            $userEmail = strtolower($userEmail);
            $stmt = $this->db->prepare('
                SELECT SUM(duration_seconds) as total
                FROM webmail_client_time_tracking
                WHERE client_id = ? AND user_email = ?
            ');
            $stmt->execute([$clientId, $userEmail]);
        } else {
            $stmt = $this->db->prepare('
                SELECT SUM(duration_seconds) as total
                FROM webmail_client_time_tracking
                WHERE client_id = ?
            ');
            $stmt->execute([$clientId]);
        }
        
        return [
            'total_seconds' => (int)($stmt->fetch()['total'] ?? 0)
        ];
    }
    
    /**
     * Get detailed activity log for a client
     */
    public function getActivityLog(
        int $clientId,
        ?string $userEmail = null,
        string $period = 'week',
        int $limit = 50
    ): array {
        $dateCondition = $this->getDateCondition($period);
        
        // Cast limit to int for safe SQL embedding (MariaDB doesn't support bound LIMIT params)
        $limit = (int)$limit;
        
        $sql = '
            SELECT *
            FROM webmail_client_time_tracking
            WHERE client_id = ? AND tracked_date >= ?
        ';
        $params = [$clientId, $dateCondition];
        
        if ($userEmail) {
            $sql .= ' AND user_email = ?';
            $params[] = strtolower($userEmail);
        }
        
        $sql .= " ORDER BY updated_at DESC LIMIT {$limit}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get complete time stats (my time + team time + daily breakdown)
     * This is the main method for the API response
     * Each sub-query is wrapped in try/catch so partial data is returned on failure
     */
    public function getCompleteTimeStats(
        string $userEmail,
        int $clientId,
        string $period = 'week'
    ): array {
        $dateCondition = $this->getDateCondition($period);
        $errors = [];
        
        // My time stats
        $myTime = ['total_seconds' => 0, 'by_activity' => []];
        try {
            $myTime = $this->getMyTimeStats($userEmail, $clientId, $period);
        } catch (\Exception $e) {
            error_log("getCompleteTimeStats: getMyTimeStats failed for client={$clientId}: " . $e->getMessage());
            $errors[] = 'my_time: ' . $e->getMessage();
        }
        
        // Team time stats
        $teamTime = ['total_seconds' => 0, 'by_member' => [], 'by_activity' => []];
        try {
            $teamTime = $this->getTeamTimeStats($clientId, $period);
        } catch (\Exception $e) {
            error_log("getCompleteTimeStats: getTeamTimeStats failed for client={$clientId}: " . $e->getMessage());
            $errors[] = 'team_time: ' . $e->getMessage();
        }
        
        // Daily breakdown
        $dailyBreakdown = [];
        try {
            $dailyBreakdown = $this->getDailyBreakdown(
                $clientId,
                $userEmail,
                $dateCondition,
                date('Y-m-d')
            );
        } catch (\Exception $e) {
            error_log("getCompleteTimeStats: getDailyBreakdown failed for client={$clientId}: " . $e->getMessage());
            $errors[] = 'daily_breakdown: ' . $e->getMessage();
        }
        
        // Cumulative totals
        $myTotal = 0;
        $teamTotal = 0;
        try {
            $myTotal = $this->getCumulativeTime($clientId, $userEmail)['total_seconds'];
        } catch (\Exception $e) {
            error_log("getCompleteTimeStats: getCumulativeTime(my) failed for client={$clientId}: " . $e->getMessage());
            $errors[] = 'cumulative_my: ' . $e->getMessage();
        }
        try {
            $teamTotal = $this->getCumulativeTime($clientId)['total_seconds'];
        } catch (\Exception $e) {
            error_log("getCompleteTimeStats: getCumulativeTime(team) failed for client={$clientId}: " . $e->getMessage());
            $errors[] = 'cumulative_team: ' . $e->getMessage();
        }
        
        $result = [
            'client_id' => $clientId,
            'period' => $period,
            'my_time' => $myTime,
            'team_time' => $teamTime,
            'daily_breakdown' => $dailyBreakdown,
            'cumulative' => [
                'my_total' => $myTotal,
                'team_total' => $teamTotal
            ]
        ];
        
        if (!empty($errors)) {
            $result['_errors'] = $errors;
        }
        
        return $result;
    }
    
    /**
     * Get total time spent for all clients (for compact list view)
     */
    public function getClientTimeTotals(string $userEmail, string $period = 'week'): array
    {
        $userEmail = strtolower($userEmail);
        $dateCondition = $this->getDateCondition($period);
        
        // Get my time per client
        $stmt = $this->db->prepare('
            SELECT client_id, SUM(duration_seconds) as my_total
            FROM webmail_client_time_tracking
            WHERE user_email = ? AND tracked_date >= ?
            GROUP BY client_id
        ');
        $stmt->execute([$userEmail, $dateCondition]);
        $myTime = [];
        foreach ($stmt->fetchAll() as $row) {
            $myTime[$row['client_id']] = (int)$row['my_total'];
        }
        
        // Get team time per client (for clients where user is owner or member)
        $stmt = $this->db->prepare('
            SELECT t.client_id, SUM(t.duration_seconds) as team_total
            FROM webmail_client_time_tracking t
            WHERE t.tracked_date >= ?
            AND (
                t.client_id IN (SELECT id FROM clients WHERE user_email = ?)
                OR t.client_id IN (SELECT client_id FROM client_members WHERE user_email = ?)
            )
            GROUP BY t.client_id
        ');
        $stmt->execute([$dateCondition, $userEmail, $userEmail]);
        $teamTime = [];
        foreach ($stmt->fetchAll() as $row) {
            $teamTime[$row['client_id']] = (int)$row['team_total'];
        }
        
        // Combine results
        $allClientIds = array_unique(array_merge(array_keys($myTime), array_keys($teamTime)));
        $result = [];
        foreach ($allClientIds as $clientId) {
            $result[$clientId] = [
                'my_time' => $myTime[$clientId] ?? 0,
                'team_time' => $teamTime[$clientId] ?? 0
            ];
        }
        
        return $result;
    }
    
    /**
     * Get date condition based on period
     */
    private function getDateCondition(string $period): string
    {
        switch ($period) {
            case 'day':
            case 'today':
                return date('Y-m-d');
            case 'week':
                return date('Y-m-d', strtotime('-7 days'));
            case 'month':
                return date('Y-m-d', strtotime('-30 days'));
            case 'year':
                return date('Y-m-d', strtotime('-365 days'));
            case 'all':
                return '1970-01-01';
            default:
                // Assume it's a specific date
                return $period;
        }
    }
    
    /**
     * Format seconds as human readable time
     */
    public static function formatTime(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }
        $hours = floor($seconds / 3600);
        $mins = round(($seconds % 3600) / 60);
        return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
    }
    
    /**
     * Calculate total time from completed calendar events for a client
     * This counts the actual event duration (e.g., 1pm-2pm meeting = 1 hour)
     * for events that have already ended
     */
    public function getCalendarEventDurations(int $clientId, string $period = 'all'): array
    {
        $dateCondition = $this->getDateCondition($period);
        
        try {
            // Get completed events (events that have ended) linked to this client
            $stmt = $this->db->prepare("
                SELECT 
                    e.id,
                    e.title,
                    e.start_time,
                    e.end_time,
                    e.all_day,
                    TIMESTAMPDIFF(SECOND, e.start_time, e.end_time) as duration_seconds
                FROM calendar_events e
                WHERE e.client_id = ?
                  AND e.end_time <= NOW()
                  AND DATE(e.start_time) >= ?
                ORDER BY e.start_time DESC
            ");
            $stmt->execute([$clientId, $dateCondition]);
            $events = $stmt->fetchAll();
            
            $totalSeconds = 0;
            $eventDetails = [];
            
            foreach ($events as $event) {
                $duration = (int)$event['duration_seconds'];
                
                // For all-day events, count as 8 hours of work
                if ($event['all_day']) {
                    $duration = 8 * 3600; // 8 hours
                }
                
                $totalSeconds += $duration;
                
                $eventDetails[] = [
                    'id' => $event['id'],
                    'title' => $event['title'],
                    'start_time' => $event['start_time'],
                    'end_time' => $event['end_time'],
                    'duration_seconds' => $duration,
                    'all_day' => (bool)$event['all_day']
                ];
            }
            
            return [
                'total_seconds' => $totalSeconds,
                'event_count' => count($events),
                'events' => $eventDetails
            ];
        } catch (\PDOException $e) {
            error_log("ClientTimeTrackingService getCalendarEventDurations error: " . $e->getMessage());
            return [
                'total_seconds' => 0,
                'event_count' => 0,
                'events' => [],
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get completed portal call durations for a client.
     * Queries the portal_calls table directly for calls that have ended.
     */
    public function getPortalCallDurations(int $clientId, string $period = 'all'): array
    {
        $dateCondition = $this->getDateCondition($period);
        
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    pc.id,
                    pc.call_type,
                    pc.created_by,
                    pc.started_at,
                    pc.ended_at,
                    pc.duration_seconds,
                    pc.had_screen_share,
                    pc.notes,
                    pc.created_at
                FROM portal_calls pc
                WHERE pc.client_id = ?
                  AND pc.status = 'ended'
                  AND pc.duration_seconds > 0
                  AND DATE(COALESCE(pc.started_at, pc.created_at)) >= ?
                ORDER BY pc.started_at DESC
            ");
            $stmt->execute([$clientId, $dateCondition]);
            $calls = $stmt->fetchAll();
            
            $totalSeconds = 0;
            $callDetails = [];
            
            foreach ($calls as $call) {
                $duration = (int)$call['duration_seconds'];
                $totalSeconds += $duration;
                
                $callDetails[] = [
                    'id' => $call['id'],
                    'call_type' => $call['call_type'],
                    'created_by' => $call['created_by'],
                    'started_at' => $call['started_at'],
                    'ended_at' => $call['ended_at'],
                    'duration_seconds' => $duration,
                    'had_screen_share' => (bool)$call['had_screen_share'],
                    'notes' => $call['notes'],
                ];
            }
            
            return [
                'total_seconds' => $totalSeconds,
                'call_count' => count($calls),
                'calls' => $callDetails,
            ];
        } catch (\PDOException $e) {
            error_log("ClientTimeTrackingService getPortalCallDurations error: " . $e->getMessage());
            return [
                'total_seconds' => 0,
                'call_count' => 0,
                'calls' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get combined time stats including calendar event durations
     * This provides a complete picture: tracked time + scheduled event time
     */
    public function getCompleteTimeWithEvents(
        string $userEmail,
        int $clientId,
        string $period = 'week'
    ): array {
        $baseStats = $this->getCompleteTimeStats($userEmail, $clientId, $period);
        $eventDurations = $this->getCalendarEventDurations($clientId, $period);
        
        return array_merge($baseStats, [
            'calendar_event_durations' => $eventDurations,
            'total_with_events' => [
                'tracked_seconds' => $baseStats['cumulative']['team_total'] ?? 0,
                'event_seconds' => $eventDurations['total_seconds'],
                'combined_seconds' => ($baseStats['cumulative']['team_total'] ?? 0) + $eventDurations['total_seconds']
            ]
        ]);
    }
    
    /**
     * Get personal time stats across all clients
     * Used for the Time Tracker overview page
     */
    public function getMyStatsAllClients(
        string $userEmail,
        string $period = 'week',
        ?int $clientId = null
    ): array {
        $userEmail = strtolower($userEmail);
        $dateCondition = $this->getDateCondition($period);
        
        // Build client filter condition
        $clientFilter = '';
        $params = [$userEmail, $dateCondition];
        
        if ($clientId) {
            $clientFilter = 'AND client_id = ?';
            $params[] = $clientId;
        }
        
        // Total time
        $stmt = $this->db->prepare("
            SELECT SUM(duration_seconds) as total
            FROM webmail_client_time_tracking
            WHERE user_email = ? AND tracked_date >= ? {$clientFilter}
        ");
        $stmt->execute($params);
        $totalSeconds = (int)($stmt->fetch()['total'] ?? 0);
        
        // Time by client with client info
        $stmt = $this->db->prepare("
            SELECT 
                t.client_id,
                c.domain,
                c.display_name,
                SUM(t.duration_seconds) as total_seconds
            FROM webmail_client_time_tracking t
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE t.user_email = ? AND t.tracked_date >= ? {$clientFilter}
            GROUP BY t.client_id, c.domain, c.display_name
            ORDER BY total_seconds DESC
        ");
        $stmt->execute($params);
        $byClient = $stmt->fetchAll();
        
        // Cast totals to integers
        foreach ($byClient as &$row) {
            $row['total_seconds'] = (int)$row['total_seconds'];
        }
        
        // Time by activity type
        $stmt = $this->db->prepare("
            SELECT activity_type, SUM(duration_seconds) as total
            FROM webmail_client_time_tracking
            WHERE user_email = ? AND tracked_date >= ? {$clientFilter}
            GROUP BY activity_type
            ORDER BY total DESC
        ");
        $stmt->execute($params);
        $byActivity = [];
        foreach ($stmt->fetchAll() as $row) {
            $byActivity[$row['activity_type']] = (int)$row['total'];
        }
        
        // Daily breakdown
        $dailyBreakdown = $this->getDailyBreakdownAllClients($userEmail, $period, $clientId);
        
        // Recent activity log
        $limitParams = $clientId ? [$clientId, $dateCondition, $userEmail] : [$dateCondition, $userEmail];
        $clientLogFilter = $clientId ? 'client_id = ? AND' : '';
        
        $stmt = $this->db->prepare("
            SELECT t.*, c.domain, c.display_name
            FROM webmail_client_time_tracking t
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE {$clientLogFilter} t.tracked_date >= ? AND t.user_email = ?
            ORDER BY t.updated_at DESC
            LIMIT 50
        ");
        $stmt->execute($limitParams);
        $activityLog = $stmt->fetchAll();
        
        return [
            'total_seconds' => $totalSeconds,
            'by_client' => $byClient,
            'by_activity' => $byActivity,
            'daily_breakdown' => $dailyBreakdown,
            'activity_log' => $activityLog,
            'period' => $period
        ];
    }
    
    /**
     * Get team-wide time stats across all clients.
     * Used by the "Team" and "Company" tabs in the supercharged Time Tracker.
     * Requires CRM Pro to be active (enforced at controller level).
     */
    public function getTeamStatsAllClients(
        string $period = 'week',
        ?int $clientId = null,
        ?string $memberEmail = null
    ): array {
        $dateCondition = $this->getDateCondition($period);

        $clientFilter = '';
        $memberFilter = '';
        $params = [$dateCondition];

        if ($clientId) {
            $clientFilter = 'AND t.client_id = ?';
            $params[] = $clientId;
        }
        if ($memberEmail) {
            $memberFilter = 'AND LOWER(t.user_email) = ?';
            $params[] = strtolower($memberEmail);
        }

        // Total company time
        $stmt = $this->db->prepare("
            SELECT SUM(t.duration_seconds) as total
            FROM webmail_client_time_tracking t
            WHERE t.tracked_date >= ? {$clientFilter} {$memberFilter}
        ");
        $stmt->execute($params);
        $totalSeconds = (int)($stmt->fetch()['total'] ?? 0);

        // By member (with display_name from organization_colleagues)
        $byMember = [];
        try {
            $stmt = $this->db->prepare("
                SELECT t.user_email, SUM(t.duration_seconds) as total_seconds,
                       oc.display_name
                FROM webmail_client_time_tracking t
                LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = LOWER(t.user_email)
                WHERE t.tracked_date >= ? {$clientFilter} {$memberFilter}
                GROUP BY t.user_email, oc.display_name
                ORDER BY total_seconds DESC
            ");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $email = $row['user_email'];
                $secs = (int)$row['total_seconds'];
                $byMember[] = [
                    'email' => $email,
                    'name' => $row['display_name'] ?: explode('@', $email)[0],
                    'total_seconds' => $secs,
                ];
            }
        } catch (\PDOException $e) {
            error_log("getTeamStatsAllClients: organization_colleagues JOIN failed: " . $e->getMessage());
            $stmt = $this->db->prepare("
                SELECT user_email, SUM(duration_seconds) as total_seconds
                FROM webmail_client_time_tracking
                WHERE tracked_date >= ? " . str_replace('t.', '', $clientFilter) . " " . str_replace('t.', '', $memberFilter) . "
                GROUP BY user_email ORDER BY total_seconds DESC
            ");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $email = $row['user_email'];
                $byMember[] = [
                    'email' => $email,
                    'name' => explode('@', $email)[0],
                    'total_seconds' => (int)$row['total_seconds'],
                ];
            }
        }

        // By client
        $stmt = $this->db->prepare("
            SELECT t.client_id, c.domain, c.display_name,
                   SUM(t.duration_seconds) as total_seconds
            FROM webmail_client_time_tracking t
            LEFT JOIN clients c ON c.id = t.client_id
            WHERE t.tracked_date >= ? {$clientFilter} {$memberFilter}
            GROUP BY t.client_id, c.domain, c.display_name
            ORDER BY total_seconds DESC
        ");
        $stmt->execute($params);
        $byClient = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['total_seconds'] = (int)$row['total_seconds'];
            $byClient[] = $row;
        }

        // By activity type
        $stmt = $this->db->prepare("
            SELECT activity_type, SUM(t.duration_seconds) as total
            FROM webmail_client_time_tracking t
            WHERE t.tracked_date >= ? {$clientFilter} {$memberFilter}
            GROUP BY activity_type ORDER BY total DESC
        ");
        $stmt->execute($params);
        $byActivity = [];
        foreach ($stmt->fetchAll() as $row) {
            $byActivity[$row['activity_type']] = (int)$row['total'];
        }

        // Daily breakdown (all users combined)
        $startDate = $this->getStartDate($period);
        $endDate = date('Y-m-d');

        $dailyParams = [$startDate, $endDate];
        $dailyClientFilter = '';
        $dailyMemberFilter = '';
        if ($clientId) {
            $dailyClientFilter = 'AND t.client_id = ?';
            $dailyParams[] = $clientId;
        }
        if ($memberEmail) {
            $dailyMemberFilter = 'AND LOWER(t.user_email) = ?';
            $dailyParams[] = strtolower($memberEmail);
        }

        $stmt = $this->db->prepare("
            SELECT tracked_date, SUM(duration_seconds) as total_seconds
            FROM webmail_client_time_tracking t
            WHERE t.tracked_date BETWEEN ? AND ? {$dailyClientFilter} {$dailyMemberFilter}
            GROUP BY tracked_date ORDER BY tracked_date
        ");
        $stmt->execute($dailyParams);
        $rawDaily = [];
        foreach ($stmt->fetchAll() as $row) {
            $rawDaily[$row['tracked_date']] = (int)$row['total_seconds'];
        }

        $dailyBreakdown = [];
        $datePeriod = new \DatePeriod(
            new \DateTime($startDate),
            new \DateInterval('P1D'),
            (new \DateTime($endDate))->modify('+1 day')
        );
        foreach ($datePeriod as $date) {
            $ds = $date->format('Y-m-d');
            $dailyBreakdown[] = ['date' => $ds, 'total_seconds' => $rawDaily[$ds] ?? 0];
        }

        // Member x Client matrix (top 10 members, top 10 clients)
        $matrix = [];
        if (!$memberEmail) {
            $topMembers = array_slice(array_column($byMember, 'email'), 0, 10);
            $topClients = array_slice(array_column($byClient, 'client_id'), 0, 10);
            if (!empty($topMembers) && !empty($topClients)) {
                $mPlaceholders = implode(',', array_fill(0, count($topMembers), '?'));
                $cPlaceholders = implode(',', array_fill(0, count($topClients), '?'));
                $stmt = $this->db->prepare("
                    SELECT user_email, client_id, SUM(duration_seconds) as total_seconds
                    FROM webmail_client_time_tracking
                    WHERE tracked_date >= ?
                      AND user_email IN ({$mPlaceholders})
                      AND client_id IN ({$cPlaceholders})
                    GROUP BY user_email, client_id
                ");
                $stmt->execute(array_merge([$dateCondition], $topMembers, $topClients));
                foreach ($stmt->fetchAll() as $row) {
                    $matrix[$row['user_email']][$row['client_id']] = (int)$row['total_seconds'];
                }
            }
        }

        return [
            'total_seconds' => $totalSeconds,
            'member_count' => count($byMember),
            'client_count' => count($byClient),
            'by_member' => $byMember,
            'by_client' => $byClient,
            'by_activity' => $byActivity,
            'daily_breakdown' => $dailyBreakdown,
            'matrix' => $matrix,
            'period' => $period,
        ];
    }

    private function getStartDate(string $period): string
    {
        switch ($period) {
            case 'today': return date('Y-m-d');
            case 'week': return date('Y-m-d', strtotime('-6 days'));
            case 'month': return date('Y-m-d', strtotime('-29 days'));
            case 'year': return date('Y-m-d', strtotime('-364 days'));
            case 'all': return date('Y-m-d', strtotime('-364 days'));
            default: return date('Y-m-d', strtotime('-6 days'));
        }
    }

    /**
     * Get daily breakdown across all clients
     */
    private function getDailyBreakdownAllClients(
        string $userEmail,
        string $period = 'week',
        ?int $clientId = null
    ): array {
        $userEmail = strtolower($userEmail);
        
        // Determine date range
        switch ($period) {
            case 'today':
                $startDate = date('Y-m-d');
                break;
            case 'week':
                $startDate = date('Y-m-d', strtotime('-6 days'));
                break;
            case 'month':
                $startDate = date('Y-m-d', strtotime('-29 days'));
                break;
            case 'year':
                // For year, show monthly breakdown
                $startDate = date('Y-m-d', strtotime('-364 days'));
                break;
            case 'all':
                $startDate = date('Y-m-d', strtotime('-364 days')); // Limit to last year for sanity
                break;
            default:
                $startDate = date('Y-m-d', strtotime('-6 days'));
        }
        $endDate = date('Y-m-d');
        
        $clientFilter = '';
        $params = [$userEmail, $startDate, $endDate];
        if ($clientId) {
            $clientFilter = 'AND client_id = ?';
            $params[] = $clientId;
        }
        
        $stmt = $this->db->prepare("
            SELECT tracked_date, SUM(duration_seconds) as total_seconds
            FROM webmail_client_time_tracking
            WHERE user_email = ? AND tracked_date BETWEEN ? AND ? {$clientFilter}
            GROUP BY tracked_date
            ORDER BY tracked_date
        ");
        $stmt->execute($params);
        
        $rawData = [];
        foreach ($stmt->fetchAll() as $row) {
            $rawData[$row['tracked_date']] = (int)$row['total_seconds'];
        }
        
        // Build complete date range
        $result = [];
        $period = new \DatePeriod(
            new \DateTime($startDate),
            new \DateInterval('P1D'),
            (new \DateTime($endDate))->modify('+1 day')
        );
        
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $result[] = [
                'date' => $dateStr,
                'total_seconds' => $rawData[$dateStr] ?? 0
            ];
        }
        
        return $result;
    }
}

