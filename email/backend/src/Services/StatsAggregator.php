<?php

namespace Webmail\Services;

/**
 * StatsAggregator - Background job for periodic statistics aggregation
 * 
 * This class scans mailboxes and aggregates statistics that cannot be 
 * tracked in real-time, such as total email counts and reply times.
 * 
 * Run via cron: php aggregate-stats.php (every hour)
 */
class StatsAggregator
{
    private StatisticsService $statsService;
    private array $config;
    private ?\PDO $db = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->statsService = new StatisticsService($config);
        $this->initDatabase();
    }
    
    /**
     * Initialize database connection
     */
    private function initDatabase(): void
    {
        try {
            $this->db = \Webmail\Core\Database::getConnection($this->config);
        } catch (\PDOException $e) {
            error_log("StatsAggregator database connection error: " . $e->getMessage());
        }
    }
    
    /**
     * Run the full aggregation process
     */
    public function run(): array
    {
        if (!$this->db) {
            return ['success' => false, 'error' => 'Database not available'];
        }
        
        $startTime = microtime(true);
        $results = [
            'success' => true,
            'users_processed' => 0,
            'errors' => []
        ];
        
        try {
            // Get all active users from recent events or time tracking
            $users = $this->getActiveUsers();
            
            foreach ($users as $userEmail) {
                try {
                    $this->aggregateUserStats($userEmail);
                    $results['users_processed']++;
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'user' => $userEmail,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
        } catch (\Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
        }
        
        $results['duration_seconds'] = round(microtime(true) - $startTime, 2);
        $results['completed_at'] = date('Y-m-d H:i:s');
        
        return $results;
    }
    
    /**
     * Get list of active users (users with recent activity)
     */
    private function getActiveUsers(): array
    {
        // Get users from events in the last 24 hours
        $stmt = $this->db->prepare('
            SELECT DISTINCT user_email 
            FROM webmail_stats_events 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            UNION
            SELECT DISTINCT user_email 
            FROM webmail_time_tracking 
            WHERE tracked_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');
        $stmt->execute();
        
        return array_column($stmt->fetchAll(), 'user_email');
    }
    
    /**
     * Aggregate statistics for a specific user
     */
    private function aggregateUserStats(string $userEmail): void
    {
        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $monthStart = date('Y-m-01');
        $yearStart = date('Y-01-01');
        
        // Aggregate event counts
        $this->aggregateEventCounts($userEmail, $today, 'day');
        $this->aggregateEventCounts($userEmail, $weekStart, 'week');
        $this->aggregateEventCounts($userEmail, $monthStart, 'month');
        $this->aggregateEventCounts($userEmail, $yearStart, 'year');
        
        // Calculate reply times
        $this->calculateReplyTimes($userEmail);
        
        // Update contact statistics
        $this->updateContactStats($userEmail);
    }
    
    /**
     * Aggregate event counts for a period
     */
    private function aggregateEventCounts(string $userEmail, string $periodStart, string $period): void
    {
        $eventTypes = [
            'email_sent' => 'emails_sent',
            'email_received' => 'emails_received',
            'email_replied' => 'emails_replied',
            'email_moved' => 'emails_moved',
            'email_deleted' => 'emails_deleted',
            'task_created' => 'tasks_created',
            'task_completed' => 'tasks_completed',
            'calendar_event_created' => 'events_created',
            'drive_file_uploaded' => 'files_uploaded',
            'ai_summary' => 'ai_summaries',
            'ai_rewrite' => 'ai_rewrites'
        ];
        
        foreach ($eventTypes as $eventType => $statType) {
            $stmt = $this->db->prepare('
                SELECT COUNT(*) as count 
                FROM webmail_stats_events 
                WHERE user_email = ? 
                AND event_type = ? 
                AND created_at >= ?
            ');
            $stmt->execute([strtolower($userEmail), $eventType, $periodStart . ' 00:00:00']);
            $row = $stmt->fetch();
            $count = (int)($row['count'] ?? 0);
            
            if ($count > 0) {
                $this->upsertStat($userEmail, $statType, $period, $periodStart, $count);
            }
        }
    }
    
    /**
     * Calculate and store average reply times
     */
    private function calculateReplyTimes(string $userEmail): void
    {
        // Get reply events with reply_time_seconds in event_data
        $stmt = $this->db->prepare('
            SELECT event_data, created_at
            FROM webmail_stats_events
            WHERE user_email = ? 
            AND event_type = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ');
        $stmt->execute([strtolower($userEmail), 'email_replied']);
        
        $replyTimes = [];
        $today = date('Y-m-d');
        
        while ($row = $stmt->fetch()) {
            $data = json_decode($row['event_data'], true);
            if (!empty($data['reply_time_seconds'])) {
                $replyTimes[] = (int)$data['reply_time_seconds'];
            }
        }
        
        if (!empty($replyTimes)) {
            $avgReplyTime = array_sum($replyTimes) / count($replyTimes);
            $metadata = json_encode(['count' => count($replyTimes)]);
            
            $stmt = $this->db->prepare('
                INSERT INTO webmail_statistics (user_email, stat_type, period, period_start, value, metadata)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE value = VALUES(value), metadata = VALUES(metadata), updated_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([
                strtolower($userEmail),
                'avg_reply_time',
                'month',
                date('Y-m-01'),
                $avgReplyTime,
                $metadata
            ]);
        }
    }
    
    /**
     * Update contact statistics from events
     */
    private function updateContactStats(string $userEmail): void
    {
        // Process email_sent events for contact stats
        $stmt = $this->db->prepare('
            SELECT event_data
            FROM webmail_stats_events
            WHERE user_email = ? 
            AND event_type IN (?, ?)
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ');
        $stmt->execute([strtolower($userEmail), 'email_sent', 'email_received']);
        
        $contactCounts = [];
        
        while ($row = $stmt->fetch()) {
            $data = json_decode($row['event_data'], true);
            
            // Track recipients for sent emails
            if (!empty($data['to'])) {
                foreach ((array)$data['to'] as $to) {
                    $email = is_array($to) ? ($to['email'] ?? '') : $to;
                    if ($email) {
                        $email = strtolower($email);
                        if (!isset($contactCounts[$email])) {
                            $contactCounts[$email] = ['sent' => 0, 'received' => 0, 'name' => null];
                        }
                        $contactCounts[$email]['sent']++;
                    }
                }
            }
            
            // Track senders for received emails
            if (!empty($data['from'])) {
                $from = $data['from'];
                $email = is_array($from) ? ($from['email'] ?? '') : $from;
                if ($email) {
                    $email = strtolower($email);
                    if (!isset($contactCounts[$email])) {
                        $contactCounts[$email] = ['sent' => 0, 'received' => 0, 'name' => null];
                    }
                    $contactCounts[$email]['received']++;
                    if (!empty($data['from_name'])) {
                        $contactCounts[$email]['name'] = $data['from_name'];
                    }
                }
            }
        }
        
        // Update contact stats table
        foreach ($contactCounts as $contactEmail => $counts) {
            if ($counts['sent'] > 0 || $counts['received'] > 0) {
                $stmt = $this->db->prepare('
                    INSERT INTO webmail_contact_stats (user_email, contact_email, contact_name, emails_sent, emails_received, last_contact)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        emails_sent = emails_sent + VALUES(emails_sent),
                        emails_received = emails_received + VALUES(emails_received),
                        contact_name = COALESCE(VALUES(contact_name), contact_name),
                        last_contact = NOW(),
                        updated_at = CURRENT_TIMESTAMP
                ');
                $stmt->execute([
                    strtolower($userEmail),
                    $contactEmail,
                    $counts['name'],
                    $counts['sent'],
                    $counts['received']
                ]);
            }
        }
    }
    
    /**
     * Upsert a statistic value
     */
    private function upsertStat(string $userEmail, string $statType, string $period, string $periodStart, float $value, ?string $metadata = null): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO webmail_statistics (user_email, stat_type, period, period_start, value, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), metadata = COALESCE(VALUES(metadata), metadata), updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            strtolower($userEmail),
            $statType,
            $period,
            $periodStart,
            $value,
            $metadata
        ]);
    }
    
    /**
     * Clean up old events to prevent table bloat
     * Keeps events for 90 days
     */
    public function cleanupOldEvents(int $daysToKeep = 90): int
    {
        if (!$this->db) {
            return 0;
        }
        
        $stmt = $this->db->prepare('
            DELETE FROM webmail_stats_events 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ');
        $stmt->execute([$daysToKeep]);
        
        return $stmt->rowCount();
    }
}

