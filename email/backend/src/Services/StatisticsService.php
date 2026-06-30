<?php

namespace Webmail\Services;

/**
 * StatisticsService - Manages user statistics tracking and aggregation
 */
class StatisticsService
{
    private ?\PDO $db = null;
    private array $config;
    private bool $initialized = false;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initDatabase();
    }
    
    /**
     * Initialize database connection
     */
    private function initDatabase(): void
    {
        if ($this->initialized) {
            return;
        }
        
        try {
            $this->db = \Webmail\Core\Database::getConnection($this->config);
            
            $this->ensureTablesExist();
            $this->initialized = true;
        } catch (\PDOException $e) {
            error_log("StatisticsService database connection error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if database is available
     */
    private function isDbAvailable(): bool
    {
        return $this->db !== null;
    }
    
    private function ensureTablesExist(): void
    {
        try {
            // Main statistics cache (aggregated data)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_statistics (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    stat_type VARCHAR(50) NOT NULL,
                    period VARCHAR(20) NOT NULL,
                    period_start DATE NOT NULL,
                    value DOUBLE NOT NULL DEFAULT 0,
                    metadata JSON,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_stat (user_email, stat_type, period, period_start),
                    INDEX idx_user_type (user_email, stat_type),
                    INDEX idx_period (period, period_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Time tracking (accumulated from frontend)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_time_tracking (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    section VARCHAR(50) NOT NULL,
                    folder VARCHAR(255) DEFAULT NULL,
                    duration_seconds INT NOT NULL DEFAULT 0,
                    tracked_date DATE NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_track (user_email, section, folder, tracked_date),
                    INDEX idx_user_date (user_email, tracked_date),
                    INDEX idx_section (section)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Event log for incremental stats
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_stats_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    event_type VARCHAR(50) NOT NULL,
                    event_data JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_type (user_email, event_type),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Email contact frequency cache
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_contact_stats (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    contact_email VARCHAR(255) NOT NULL,
                    contact_name VARCHAR(255) DEFAULT NULL,
                    emails_sent INT NOT NULL DEFAULT 0,
                    emails_received INT NOT NULL DEFAULT 0,
                    last_contact TIMESTAMP NULL,
                    avg_reply_time_seconds INT DEFAULT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_contact (user_email, contact_email),
                    INDEX idx_user (user_email),
                    INDEX idx_frequency (user_email, emails_sent, emails_received)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // User preferences history
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_preference_stats (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    preference_type VARCHAR(50) NOT NULL,
                    preference_value VARCHAR(100) NOT NULL,
                    usage_count INT NOT NULL DEFAULT 1,
                    last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_pref (user_email, preference_type, preference_value),
                    INDEX idx_user_type (user_email, preference_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
        } catch (\PDOException $e) {
            error_log("StatisticsService table creation error: " . $e->getMessage());
        }
    }
    
    // ==================== EVENT LOGGING ====================
    
    /**
     * Log a trackable event
     */
    public function logEvent(string $userEmail, string $eventType, array $eventData = []): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_stats_events (user_email, event_type, event_data)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([
                strtolower($userEmail),
                $eventType,
                json_encode($eventData)
            ]);
            
            // Update aggregated stats based on event type
            $this->updateStatsFromEvent($userEmail, $eventType, $eventData);
            
            return true;
        } catch (\PDOException $e) {
            error_log("StatisticsService logEvent error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update aggregated statistics from an event
     */
    private function updateStatsFromEvent(string $userEmail, string $eventType, array $eventData): void
    {
        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $monthStart = date('Y-m-01');
        $yearStart = date('Y-01-01');
        
        switch ($eventType) {
            case 'email_sent':
                $this->incrementStat($userEmail, 'emails_sent', 'day', $today);
                $this->incrementStat($userEmail, 'emails_sent', 'week', $weekStart);
                $this->incrementStat($userEmail, 'emails_sent', 'month', $monthStart);
                $this->incrementStat($userEmail, 'emails_sent', 'year', $yearStart);
                
                // Update contact stats
                if (!empty($eventData['to'])) {
                    foreach ((array)$eventData['to'] as $recipient) {
                        $this->updateContactStat($userEmail, $recipient, 'sent');
                    }
                }
                break;
                
            case 'email_received':
                $this->incrementStat($userEmail, 'emails_received', 'day', $today);
                $this->incrementStat($userEmail, 'emails_received', 'week', $weekStart);
                $this->incrementStat($userEmail, 'emails_received', 'month', $monthStart);
                $this->incrementStat($userEmail, 'emails_received', 'year', $yearStart);
                
                // Update contact stats
                if (!empty($eventData['from'])) {
                    $this->updateContactStat($userEmail, $eventData['from'], 'received', $eventData['from_name'] ?? null);
                }
                break;
                
            case 'email_replied':
                $this->incrementStat($userEmail, 'emails_replied', 'day', $today);
                if (!empty($eventData['reply_time_seconds'])) {
                    $this->recordReplyTime($userEmail, $eventData['reply_time_seconds']);
                }
                break;
                
            case 'email_moved':
                $this->incrementStat($userEmail, 'emails_moved', 'day', $today);
                if (!empty($eventData['to_folder'])) {
                    $this->incrementFolderUsage($userEmail, $eventData['to_folder']);
                }
                break;
                
            case 'email_deleted':
                $this->incrementStat($userEmail, 'emails_deleted', 'day', $today);
                break;
                
            case 'task_created':
                $this->incrementStat($userEmail, 'tasks_created', 'day', $today);
                $this->incrementStat($userEmail, 'tasks_created', 'month', $monthStart);
                break;
                
            case 'task_completed':
                $this->incrementStat($userEmail, 'tasks_completed', 'day', $today);
                $this->incrementStat($userEmail, 'tasks_completed', 'month', $monthStart);
                break;
                
            case 'calendar_event_created':
                $this->incrementStat($userEmail, 'events_created', 'day', $today);
                $this->incrementStat($userEmail, 'events_created', 'month', $monthStart);
                break;
                
            case 'drive_file_uploaded':
                $this->incrementStat($userEmail, 'files_uploaded', 'day', $today);
                if (!empty($eventData['size'])) {
                    $this->incrementStat($userEmail, 'drive_bytes_uploaded', 'month', $monthStart, $eventData['size']);
                }
                break;
                
            case 'ai_summary':
                $this->incrementStat($userEmail, 'ai_summaries', 'day', $today);
                $this->incrementStat($userEmail, 'ai_summaries', 'month', $monthStart);
                break;
                
            case 'ai_rewrite':
                $this->incrementStat($userEmail, 'ai_rewrites', 'day', $today);
                $this->incrementStat($userEmail, 'ai_rewrites', 'month', $monthStart);
                break;
                
            case 'theme_changed':
                if (!empty($eventData['theme'])) {
                    $this->trackPreference($userEmail, 'theme', $eventData['theme']);
                }
                break;
                
            case 'accent_changed':
                if (!empty($eventData['accent'])) {
                    $this->trackPreference($userEmail, 'accent_color', $eventData['accent']);
                }
                break;
        }
    }
    
    /**
     * Increment a statistic value
     */
    private function incrementStat(string $userEmail, string $statType, string $period, string $periodStart, float $amount = 1): void
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_statistics (user_email, stat_type, period, period_start, value)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE value = value + VALUES(value), updated_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([
                strtolower($userEmail),
                $statType,
                $period,
                $periodStart,
                $amount
            ]);
        } catch (\PDOException $e) {
            error_log("StatisticsService incrementStat error: " . $e->getMessage());
        }
    }
    
    /**
     * Update contact statistics
     */
    private function updateContactStat(string $userEmail, string $contactEmail, string $direction, ?string $contactName = null): void
    {
        try {
            $field = $direction === 'sent' ? 'emails_sent' : 'emails_received';
            
            $stmt = $this->db->prepare("
                INSERT INTO webmail_contact_stats (user_email, contact_email, contact_name, {$field}, last_contact)
                VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE 
                    {$field} = {$field} + 1, 
                    last_contact = CURRENT_TIMESTAMP,
                    contact_name = COALESCE(VALUES(contact_name), contact_name)
            ");
            $stmt->execute([
                strtolower($userEmail),
                strtolower($contactEmail),
                $contactName
            ]);
        } catch (\PDOException $e) {
            error_log("StatisticsService updateContactStat error: " . $e->getMessage());
        }
    }
    
    /**
     * Record reply time for averaging
     */
    private function recordReplyTime(string $userEmail, int $replyTimeSeconds): void
    {
        try {
            $today = date('Y-m-d');
            
            // Store in a separate stat with metadata for averaging
            $stmt = $this->db->prepare('
                INSERT INTO webmail_statistics (user_email, stat_type, period, period_start, value, metadata)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    value = (value * JSON_EXTRACT(metadata, "$.count") + VALUES(value)) / (JSON_EXTRACT(metadata, "$.count") + 1),
                    metadata = JSON_SET(metadata, "$.count", JSON_EXTRACT(metadata, "$.count") + 1),
                    updated_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([
                strtolower($userEmail),
                'avg_reply_time',
                'day',
                $today,
                $replyTimeSeconds,
                json_encode(['count' => 1])
            ]);
        } catch (\PDOException $e) {
            error_log("StatisticsService recordReplyTime error: " . $e->getMessage());
        }
    }
    
    /**
     * Increment folder usage count
     */
    private function incrementFolderUsage(string $userEmail, string $folder): void
    {
        try {
            $monthStart = date('Y-m-01');
            $metadata = json_encode(['folder' => $folder]);
            
            $stmt = $this->db->prepare('
                INSERT INTO webmail_statistics (user_email, stat_type, period, period_start, value, metadata)
                VALUES (?, ?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE value = value + 1, updated_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([
                strtolower($userEmail),
                'folder_usage_' . md5($folder),
                'month',
                $monthStart,
                $metadata
            ]);
        } catch (\PDOException $e) {
            error_log("StatisticsService incrementFolderUsage error: " . $e->getMessage());
        }
    }
    
    /**
     * Track preference usage
     */
    private function trackPreference(string $userEmail, string $prefType, string $prefValue): void
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_preference_stats (user_email, preference_type, preference_value, usage_count, last_used)
                VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE usage_count = usage_count + 1, last_used = CURRENT_TIMESTAMP
            ');
            $stmt->execute([
                strtolower($userEmail),
                $prefType,
                $prefValue
            ]);
        } catch (\PDOException $e) {
            error_log("StatisticsService trackPreference error: " . $e->getMessage());
        }
    }
    
    // ==================== TIME TRACKING ====================
    
    /**
     * Track time spent in a section
     */
    public function trackTime(string $userEmail, string $section, int $durationSeconds, ?string $folder = null): bool
    {
        if (!$this->isDbAvailable() || $durationSeconds < 1) {
            return false;
        }
        
        try {
            $today = date('Y-m-d');
            
            $stmt = $this->db->prepare('
                INSERT INTO webmail_time_tracking (user_email, section, folder, duration_seconds, tracked_date)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE duration_seconds = duration_seconds + VALUES(duration_seconds)
            ');
            $stmt->execute([
                strtolower($userEmail),
                $section,
                $folder,
                $durationSeconds,
                $today
            ]);
            
            return true;
        } catch (\PDOException $e) {
            error_log("StatisticsService trackTime error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get time tracking statistics
     */
    public function getTimeStats(string $userEmail, string $period = 'week'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            
            // Get time by section
            $stmt = $this->db->prepare("
                SELECT section, SUM(duration_seconds) as total_seconds
                FROM webmail_time_tracking
                WHERE user_email = ? AND tracked_date >= ?
                GROUP BY section
                ORDER BY total_seconds DESC
            ");
            $stmt->execute([strtolower($userEmail), $dateCondition]);
            $bySection = $stmt->fetchAll();
            
            // Get time by folder (for email section)
            $stmt = $this->db->prepare("
                SELECT folder, SUM(duration_seconds) as total_seconds
                FROM webmail_time_tracking
                WHERE user_email = ? AND tracked_date >= ? AND section = 'email' AND folder IS NOT NULL
                GROUP BY folder
                ORDER BY total_seconds DESC
                LIMIT 10
            ");
            $stmt->execute([strtolower($userEmail), $dateCondition]);
            $byFolder = $stmt->fetchAll();
            
            // Get daily breakdown
            $stmt = $this->db->prepare("
                SELECT tracked_date, section, SUM(duration_seconds) as total_seconds
                FROM webmail_time_tracking
                WHERE user_email = ? AND tracked_date >= ?
                GROUP BY tracked_date, section
                ORDER BY tracked_date
            ");
            $stmt->execute([strtolower($userEmail), $dateCondition]);
            $daily = $stmt->fetchAll();
            
            return [
                'by_section' => $bySection,
                'by_folder' => $byFolder,
                'daily' => $daily,
                'total_seconds' => array_sum(array_column($bySection, 'total_seconds'))
            ];
        } catch (\PDOException $e) {
            error_log("StatisticsService getTimeStats error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== EMAIL STATISTICS ====================
    
    /**
     * Get email statistics overview
     */
    public function getEmailStats(string $userEmail, string $period = 'week'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            $userEmail = strtolower($userEmail);
            
            $totals = [
                'emails_sent' => 0,
                'emails_received' => 0,
                'emails_replied' => 0,
                'emails_moved' => 0,
                'emails_deleted' => 0,
                'emails_read' => 0,
                'total_recipients' => 0
            ];
            $daily = [];
            
            // Get sent email count from email_tracking table (real tracking data)
            try {
                // Total sent emails in period
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM email_tracking 
                    WHERE user_email = ? AND sent_at >= ?
                ");
                $stmt->execute([$userEmail, $dateCondition]);
                $totals['emails_sent'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Total unique recipients emailed
                $stmt = $this->db->prepare("
                    SELECT COUNT(DISTINCT tr.recipient_email) as count 
                    FROM email_tracking_recipients tr
                    JOIN email_tracking t ON tr.tracking_id = t.tracking_id
                    WHERE t.user_email = ? AND t.sent_at >= ?
                ");
                $stmt->execute([$userEmail, $dateCondition]);
                $totals['total_recipients'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Emails that were read (have read events)
                $stmt = $this->db->prepare("
                    SELECT COUNT(DISTINCT t.id) as count 
                    FROM email_tracking t
                    JOIN email_read_events e ON t.tracking_id = e.tracking_id
                    WHERE t.user_email = ? AND t.sent_at >= ?
                ");
                $stmt->execute([$userEmail, $dateCondition]);
                $totals['emails_read'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Daily breakdown for chart
                $stmt = $this->db->prepare("
                    SELECT DATE(sent_at) as date, 'emails_sent' as stat_type, COUNT(*) as value
                    FROM email_tracking 
                    WHERE user_email = ? AND sent_at >= ?
                    GROUP BY DATE(sent_at)
                    ORDER BY date
                ");
                $stmt->execute([$userEmail, $dateCondition]);
                $daily = $stmt->fetchAll();
                
            } catch (\PDOException $e) {
                error_log("Email tracking stats error: " . $e->getMessage());
            }
            
            // Also check webmail_statistics for any additional logged events
            try {
                $stmt = $this->db->prepare("
                    SELECT stat_type, SUM(value) as total
                    FROM webmail_statistics
                    WHERE user_email = ? AND period_start >= ?
                    AND stat_type IN ('emails_sent', 'emails_received', 'emails_replied', 'emails_moved', 'emails_deleted')
                    GROUP BY stat_type
                ");
                $stmt->execute([$userEmail, $dateCondition]);
                foreach ($stmt->fetchAll() as $row) {
                    $key = $row['stat_type'];
                    // Use the higher of the two values
                    $totals[$key] = max($totals[$key] ?? 0, (int)$row['total']);
                }
            } catch (\PDOException $e) {
                // Table might not exist
            }
            
            // Get average reply time
            $avgReplyTime = null;
            try {
                $stmt = $this->db->prepare("
                    SELECT AVG(value) as avg_reply_time
                    FROM webmail_statistics
                    WHERE user_email = ? AND stat_type = 'avg_reply_time' AND period_start >= ?
                ");
                $stmt->execute([$userEmail, $dateCondition]);
                $replyTime = $stmt->fetch();
                $avgReplyTime = $replyTime['avg_reply_time'] ?? null;
            } catch (\PDOException $e) {
                // Table might not exist
            }
            
            return [
                'totals' => $totals,
                'daily' => $daily,
                'avg_reply_time_seconds' => $avgReplyTime
            ];
        } catch (\PDOException $e) {
            error_log("StatisticsService getEmailStats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get top contacts by email frequency
     */
    public function getTopContacts(string $userEmail, int $limit = 10): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        $userEmail = strtolower($userEmail);
        $contacts = [];
        
        try {
            // First try from email_tracking_recipients for accurate data
            try {
                $stmt = $this->db->prepare("
                    SELECT 
                        tr.recipient_email as contact_email,
                        tr.recipient_email as contact_name,
                        COUNT(*) as emails_sent,
                        0 as emails_received,
                        COUNT(*) as total_emails,
                        MAX(t.sent_at) as last_contact
                    FROM email_tracking_recipients tr
                    JOIN email_tracking t ON tr.tracking_id = t.tracking_id
                    WHERE t.user_email = ?
                    GROUP BY tr.recipient_email
                    ORDER BY emails_sent DESC
                    LIMIT ?
                ");
                $stmt->execute([$userEmail, $limit]);
                $contacts = $stmt->fetchAll();
                
                // Clean up contact names (use part before @)
                foreach ($contacts as &$contact) {
                    $contact['contact_name'] = ucfirst(explode('@', $contact['contact_email'])[0]);
                }
            } catch (\PDOException $e) {
                error_log("email_tracking_recipients query failed: " . $e->getMessage());
            }
            
            // If still empty, try parsing recipients JSON from email_tracking
            if (empty($contacts)) {
                try {
                    $stmt = $this->db->prepare("
                        SELECT recipients, sent_at FROM email_tracking 
                        WHERE user_email = ?
                        ORDER BY sent_at DESC
                        LIMIT 1000
                    ");
                    $stmt->execute([$userEmail]);
                    $rows = $stmt->fetchAll();
                    
                    $contactCounts = [];
                    foreach ($rows as $row) {
                        $recipients = json_decode($row['recipients'], true) ?? [];
                        foreach ($recipients as $recipient) {
                            $email = strtolower(is_array($recipient) ? ($recipient['email'] ?? $recipient['address'] ?? '') : $recipient);
                            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                            
                            if (!isset($contactCounts[$email])) {
                                $contactCounts[$email] = ['count' => 0, 'last' => $row['sent_at']];
                            }
                            $contactCounts[$email]['count']++;
                            if ($row['sent_at'] > $contactCounts[$email]['last']) {
                                $contactCounts[$email]['last'] = $row['sent_at'];
                            }
                        }
                    }
                    
                    // Sort by count and take top N
                    uasort($contactCounts, fn($a, $b) => $b['count'] - $a['count']);
                    
                    $i = 0;
                    foreach ($contactCounts as $email => $data) {
                        if ($i >= $limit) break;
                        $contacts[] = [
                            'contact_email' => $email,
                            'contact_name' => ucfirst(explode('@', $email)[0]),
                            'emails_sent' => $data['count'],
                            'emails_received' => 0,
                            'total_emails' => $data['count'],
                            'last_contact' => $data['last']
                        ];
                        $i++;
                    }
                } catch (\PDOException $e) {
                    error_log("email_tracking fallback failed: " . $e->getMessage());
                }
            }
            
            // If still empty, try from webmail_contact_stats (logged data)
            if (empty($contacts)) {
                try {
                    $stmt = $this->db->prepare('
                        SELECT contact_email, contact_name, emails_sent, emails_received, 
                               (emails_sent + emails_received) as total_emails,
                               last_contact
                        FROM webmail_contact_stats
                        WHERE user_email = ?
                        ORDER BY total_emails DESC
                        LIMIT ?
                    ');
                    $stmt->execute([$userEmail, $limit]);
                    $contacts = $stmt->fetchAll();
                } catch (\PDOException $e) {
                    // Table might not exist
                }
            }
            
            return $contacts;
        } catch (\PDOException $e) {
            error_log("StatisticsService getTopContacts error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get most active conversations (contacts with most back-and-forth)
     */
    public function getActiveConversations(string $userEmail, string $period = 'month', int $limit = 10): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        $userEmail = strtolower($userEmail);
        
        try {
            $dateCondition = $this->getDateCondition($period);
            $conversations = [];
            
            // First try from webmail_contact_stats (needs both sent and received data)
            try {
                $stmt = $this->db->prepare('
                    SELECT contact_email, contact_name, emails_sent, emails_received,
                           (emails_sent + emails_received) as total_emails,
                           LEAST(emails_sent, emails_received) as conversation_score
                    FROM webmail_contact_stats
                    WHERE user_email = ? AND last_contact >= ?
                    AND emails_sent > 0 AND emails_received > 0
                    ORDER BY conversation_score DESC
                    LIMIT ?
                ');
                $stmt->execute([$userEmail, $dateCondition, $limit]);
                $conversations = $stmt->fetchAll();
            } catch (\PDOException $e) {
                // Table might not exist
            }
            
            // If empty, fall back to showing top contacted people (sent only)
            if (empty($conversations)) {
                $contacts = $this->getTopContacts($userEmail, $limit);
                foreach ($contacts as $contact) {
                    $conversations[] = [
                        'contact_email' => $contact['contact_email'],
                        'contact_name' => $contact['contact_name'],
                        'emails_sent' => $contact['emails_sent'],
                        'emails_received' => $contact['emails_received'] ?? 0,
                        'total_emails' => $contact['total_emails'],
                        'conversation_score' => min($contact['emails_sent'], $contact['emails_received'] ?? 0)
                    ];
                }
            }
            
            return $conversations;
        } catch (\PDOException $e) {
            error_log("StatisticsService getActiveConversations error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== TASK STATISTICS ====================
    
    /**
     * Get task statistics
     */
    public function getTaskStats(string $userEmail, string $period = 'month'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            
            // First try from logged events
            $stmt = $this->db->prepare("
                SELECT stat_type, SUM(value) as total
                FROM webmail_statistics
                WHERE user_email = ? AND period_start >= ?
                AND stat_type IN ('tasks_created', 'tasks_completed')
                GROUP BY stat_type
            ");
            $stmt->execute([strtolower($userEmail), $dateCondition]);
            
            $result = ['created' => 0, 'completed' => 0];
            foreach ($stmt->fetchAll() as $row) {
                if ($row['stat_type'] === 'tasks_created') {
                    $result['created'] = (int)$row['total'];
                } else if ($row['stat_type'] === 'tasks_completed') {
                    $result['completed'] = (int)$row['total'];
                }
            }
            
            // Also get real data from webmail_todos table
            try {
                // Get all tasks in period
                $stmt = $this->db->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed_count
                    FROM webmail_todos 
                    WHERE email = ? AND created_at >= ?
                ");
                $stmt->execute([strtolower($userEmail), $dateCondition]);
                $row = $stmt->fetch();
                
                if ($row) {
                    // Use higher of logged events or actual data
                    $result['created'] = max($result['created'], (int)$row['total']);
                    $result['completed'] = max($result['completed'], (int)$row['completed_count']);
                }
            } catch (\PDOException $e) {
                // Table might not exist
            }
            
            $result['completion_rate'] = $result['created'] > 0 
                ? round(($result['completed'] / $result['created']) * 100, 1) 
                : 0;
            
            return $result;
        } catch (\PDOException $e) {
            error_log("StatisticsService getTaskStats error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== CALENDAR STATISTICS ====================
    
    /**
     * Get calendar event statistics
     */
    public function getCalendarStats(string $userEmail, string $period = 'month'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            
            // Get events created from statistics
            $stmt = $this->db->prepare("
                SELECT SUM(value) as total
                FROM webmail_statistics
                WHERE user_email = ? AND stat_type = 'events_created' AND period_start >= ?
            ");
            $stmt->execute([strtolower($userEmail), $dateCondition]);
            $row = $stmt->fetch();
            $eventsCreated = (int)($row['total'] ?? 0);
            
            // Try to get actual calendar data from calendar_events table if exists
            // Note: calendar_events links through calendars table (calendar_id -> calendars.id -> user_email)
            $upcomingEvents = 0;
            $eventsThisWeek = 0;
            $recurringEvents = 0;
            $eventsByDay = [0, 0, 0, 0, 0, 0, 0]; // Mon-Sun
            
            try {
                // Count upcoming events (JOIN with calendars to get user's events)
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count 
                    FROM calendar_events e
                    JOIN calendars c ON e.calendar_id = c.id
                    WHERE c.user_email = ? AND e.start_time > NOW()
                ");
                $stmt->execute([strtolower($userEmail)]);
                $upcomingEvents = (int)($stmt->fetch()['count'] ?? 0);
                
                // Count events this week
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count 
                    FROM calendar_events e
                    JOIN calendars c ON e.calendar_id = c.id
                    WHERE c.user_email = ? 
                    AND e.start_time >= DATE_SUB(NOW(), INTERVAL WEEKDAY(NOW()) DAY) 
                    AND e.start_time < DATE_ADD(DATE_SUB(NOW(), INTERVAL WEEKDAY(NOW()) DAY), INTERVAL 7 DAY)
                ");
                $stmt->execute([strtolower($userEmail)]);
                $eventsThisWeek = (int)($stmt->fetch()['count'] ?? 0);
                
                // Count recurring events
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count 
                    FROM calendar_events e
                    JOIN calendars c ON e.calendar_id = c.id
                    WHERE c.user_email = ? AND e.recurrence IS NOT NULL AND e.recurrence != ''
                ");
                $stmt->execute([strtolower($userEmail)]);
                $recurringEvents = (int)($stmt->fetch()['count'] ?? 0);
                
                // Events by day of week
                $stmt = $this->db->prepare("
                    SELECT DAYOFWEEK(e.start_time) as dow, COUNT(*) as count 
                    FROM calendar_events e
                    JOIN calendars c ON e.calendar_id = c.id
                    WHERE c.user_email = ? AND e.start_time >= ?
                    GROUP BY DAYOFWEEK(e.start_time)
                ");
                $stmt->execute([strtolower($userEmail), $dateCondition]);
                foreach ($stmt->fetchAll() as $row) {
                    $dow = ((int)$row['dow'] + 5) % 7; // Convert to Mon=0
                    $eventsByDay[$dow] = (int)$row['count'];
                }
            } catch (\PDOException $e) {
                // Table might not exist, ignore
                error_log("Calendar stats query error: " . $e->getMessage());
            }
            
            return [
                'events_created' => $eventsCreated,
                'upcoming_events' => $upcomingEvents,
                'events_this_week' => $eventsThisWeek,
                'recurring_events' => $recurringEvents,
                'events_by_day' => $eventsByDay
            ];
        } catch (\PDOException $e) {
            error_log("StatisticsService getCalendarStats error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== DRIVE STATISTICS ====================
    
    /**
     * Get drive usage statistics
     */
    public function getDriveStats(string $userEmail, string $period = 'month'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            
            $result = [
                'files_uploaded' => 0, 
                'total_files' => 0,
                'bytes_uploaded' => 0,
                'total_space' => -1, // -1 = unlimited
                'used_space' => 0,
                'email_attachments' => 0,
                'large_files' => 0,
                'shared_files' => 0,
                'shared_folders' => 0,
                'total_folders' => 0,
                'upload_frequency' => 0,
                'recent_uploads' => 0,
                'file_types' => [],
                'top_folders' => []
            ];
            
            // Get actual drive data from drive_files table
            try {
                // Total files and space used (all time)
                $stmt = $this->db->prepare("
                    SELECT COALESCE(SUM(size), 0) as total_size, COUNT(*) as file_count
                    FROM drive_files 
                    WHERE user_email = ?
                ");
                $stmt->execute([strtolower($userEmail)]);
                $row = $stmt->fetch();
                if ($row) {
                    $result['used_space'] = (int)$row['total_size'];
                    $result['total_files'] = (int)$row['file_count'];
                }
                
                // Files uploaded in period
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count, COALESCE(SUM(size), 0) as size
                    FROM drive_files 
                    WHERE user_email = ? AND created_at >= ?
                ");
                $stmt->execute([strtolower($userEmail), $dateCondition]);
                $row = $stmt->fetch();
                if ($row) {
                    $result['files_uploaded'] = (int)$row['count'];
                    $result['bytes_uploaded'] = (int)$row['size'];
                    $result['upload_frequency'] = (int)$row['count'];
                    $result['recent_uploads'] = (int)$row['count'];
                }
                
                // Email attachments (files marked as email attachments)
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM drive_files 
                    WHERE user_email = ? AND is_email_attachment = 1
                ");
                $stmt->execute([strtolower($userEmail)]);
                $result['email_attachments'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Large files (> 10MB)
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM drive_files 
                    WHERE user_email = ? AND size > 10485760
                ");
                $stmt->execute([strtolower($userEmail)]);
                $result['large_files'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Shared files
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM drive_files 
                    WHERE user_email = ? AND share_token IS NOT NULL
                ");
                $stmt->execute([strtolower($userEmail)]);
                $result['shared_files'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Total folders
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM drive_folders 
                    WHERE user_email = ?
                ");
                $stmt->execute([strtolower($userEmail)]);
                $result['total_folders'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Shared folders
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM drive_folders 
                    WHERE user_email = ? AND share_token IS NOT NULL
                ");
                $stmt->execute([strtolower($userEmail)]);
                $result['shared_folders'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // File types distribution
                $stmt = $this->db->prepare("
                    SELECT 
                        CASE 
                            WHEN mime_type LIKE 'image/%' THEN 'Images'
                            WHEN mime_type LIKE 'video/%' THEN 'Videos'
                            WHEN mime_type LIKE 'audio/%' THEN 'Audio'
                            WHEN mime_type LIKE '%pdf%' THEN 'PDFs'
                            WHEN mime_type LIKE '%document%' OR mime_type LIKE '%word%' OR original_name LIKE '%.doc%' THEN 'Documents'
                            WHEN mime_type LIKE '%sheet%' OR mime_type LIKE '%excel%' OR original_name LIKE '%.xls%' THEN 'Spreadsheets'
                            WHEN mime_type LIKE '%zip%' OR mime_type LIKE '%rar%' OR mime_type LIKE '%tar%' THEN 'Archives'
                            ELSE 'Other'
                        END as file_type,
                        COUNT(*) as count,
                        SUM(size) as total_size
                    FROM drive_files 
                    WHERE user_email = ?
                    GROUP BY file_type
                    ORDER BY count DESC
                ");
                $stmt->execute([strtolower($userEmail)]);
                $result['file_types'] = array_map(function($row) {
                    return [
                        'type' => $row['file_type'], 
                        'count' => (int)$row['count'],
                        'size' => (int)$row['total_size']
                    ];
                }, $stmt->fetchAll());
                
                // Top folders by file count
                $stmt = $this->db->prepare("
                    SELECT f.name, f.id, COUNT(df.id) as file_count, COALESCE(SUM(df.size), 0) as total_size
                    FROM drive_folders f
                    LEFT JOIN drive_files df ON df.folder_id = f.id
                    WHERE f.user_email = ?
                    GROUP BY f.id, f.name
                    ORDER BY file_count DESC
                    LIMIT 10
                ");
                $stmt->execute([strtolower($userEmail)]);
                $result['top_folders'] = $stmt->fetchAll();
                
                // Get user quota
                $stmt = $this->db->prepare("
                    SELECT quota_bytes, used_bytes FROM drive_quotas 
                    WHERE user_email = ?
                ");
                $stmt->execute([strtolower($userEmail)]);
                $quota = $stmt->fetch();
                if ($quota) {
                    $result['total_space'] = (int)$quota['quota_bytes'];
                }
                
            } catch (\PDOException $e) {
                error_log("StatisticsService getDriveStats table query error: " . $e->getMessage());
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("StatisticsService getDriveStats error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== BOARD STATISTICS ====================
    
    /**
     * Get board/task statistics
     */
    public function getBoardStats(string $userEmail, string $period = 'month'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            $userEmail = strtolower($userEmail);
            
            $result = [
                'total_boards' => 0,
                'total_lists' => 0,
                'total_cards' => 0,
                'completed_cards' => 0,
                'pending_cards' => 0,
                'overdue_cards' => 0,
                'cards_with_due_date' => 0,
                'cards_due_this_week' => 0,
                'cards_created_period' => 0,
                'cards_completed_period' => 0,
                'completion_rate' => 0,
                'total_checklists' => 0,
                'total_checklist_items' => 0,
                'completed_checklist_items' => 0,
                'total_comments' => 0,
                'total_attachments' => 0,
                'boards' => [],
                'upcoming_deadlines' => [],
                'cards_by_status' => [],
                'boards_with_clients' => 0
            ];
            
            try {
                // Total boards owned or member of
                $stmt = $this->db->prepare("
                    SELECT COUNT(DISTINCT b.id) as count
                    FROM webmail_boards b
                    LEFT JOIN webmail_board_members bm ON b.id = bm.board_id
                    WHERE (b.owner_email = ? OR bm.user_email = ?) AND b.archived = 0
                ");
                $stmt->execute([$userEmail, $userEmail]);
                $result['total_boards'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Get all accessible board IDs
                $stmt = $this->db->prepare("
                    SELECT DISTINCT b.id
                    FROM webmail_boards b
                    LEFT JOIN webmail_board_members bm ON b.id = bm.board_id
                    WHERE (b.owner_email = ? OR bm.user_email = ?) AND b.archived = 0
                ");
                $stmt->execute([$userEmail, $userEmail]);
                $boardIds = array_column($stmt->fetchAll(), 'id');
                
                if (!empty($boardIds)) {
                    $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
                    
                    // Total lists
                    $stmt = $this->db->prepare("
                        SELECT COUNT(*) as count FROM webmail_board_lists 
                        WHERE board_id IN ($placeholders) AND archived = 0
                    ");
                    $stmt->execute($boardIds);
                    $result['total_lists'] = (int)($stmt->fetch()['count'] ?? 0);
                    
                    // Card statistics
                    $stmt = $this->db->prepare("
                        SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN c.completed = 1 THEN 1 ELSE 0 END) as completed,
                            SUM(CASE WHEN c.completed = 0 THEN 1 ELSE 0 END) as pending,
                            SUM(CASE WHEN c.due_date IS NOT NULL AND c.due_date < NOW() AND c.completed = 0 THEN 1 ELSE 0 END) as overdue,
                            SUM(CASE WHEN c.due_date IS NOT NULL THEN 1 ELSE 0 END) as with_due_date,
                            SUM(CASE WHEN c.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND c.completed = 0 THEN 1 ELSE 0 END) as due_this_week
                        FROM webmail_board_cards c
                        JOIN webmail_board_lists l ON c.list_id = l.id
                        WHERE l.board_id IN ($placeholders) AND c.archived = 0
                    ");
                    $stmt->execute($boardIds);
                    $cardStats = $stmt->fetch();
                    if ($cardStats) {
                        $result['total_cards'] = (int)$cardStats['total'];
                        $result['completed_cards'] = (int)$cardStats['completed'];
                        $result['pending_cards'] = (int)$cardStats['pending'];
                        $result['overdue_cards'] = (int)$cardStats['overdue'];
                        $result['cards_with_due_date'] = (int)$cardStats['with_due_date'];
                        $result['cards_due_this_week'] = (int)$cardStats['due_this_week'];
                    }
                    
                    // Cards created in period
                    $stmt = $this->db->prepare("
                        SELECT COUNT(*) as count
                        FROM webmail_board_cards c
                        JOIN webmail_board_lists l ON c.list_id = l.id
                        WHERE l.board_id IN ($placeholders) AND c.created_at >= ?
                    ");
                    $params = array_merge($boardIds, [$dateCondition]);
                    $stmt->execute($params);
                    $result['cards_created_period'] = (int)($stmt->fetch()['count'] ?? 0);
                    
                    // Cards completed in period
                    $stmt = $this->db->prepare("
                        SELECT COUNT(*) as count
                        FROM webmail_board_cards c
                        JOIN webmail_board_lists l ON c.list_id = l.id
                        WHERE l.board_id IN ($placeholders) AND c.completed = 1 AND c.completed_at >= ?
                    ");
                    $stmt->execute($params);
                    $result['cards_completed_period'] = (int)($stmt->fetch()['count'] ?? 0);
                    
                    // Completion rate
                    if ($result['total_cards'] > 0) {
                        $result['completion_rate'] = round(($result['completed_cards'] / $result['total_cards']) * 100, 1);
                    }
                    
                    // Checklist stats
                    $stmt = $this->db->prepare("
                        SELECT 
                            COUNT(DISTINCT cl.id) as total_checklists,
                            COUNT(ci.id) as total_items,
                            SUM(CASE WHEN ci.completed = 1 THEN 1 ELSE 0 END) as completed_items
                        FROM webmail_card_checklists cl
                        JOIN webmail_board_cards c ON cl.card_id = c.id
                        JOIN webmail_board_lists l ON c.list_id = l.id
                        LEFT JOIN webmail_checklist_items ci ON ci.checklist_id = cl.id
                        WHERE l.board_id IN ($placeholders)
                    ");
                    $stmt->execute($boardIds);
                    $checklistStats = $stmt->fetch();
                    if ($checklistStats) {
                        $result['total_checklists'] = (int)$checklistStats['total_checklists'];
                        $result['total_checklist_items'] = (int)$checklistStats['total_items'];
                        $result['completed_checklist_items'] = (int)$checklistStats['completed_items'];
                    }
                    
                    // Comments count
                    $stmt = $this->db->prepare("
                        SELECT COUNT(*) as count
                        FROM webmail_card_comments cm
                        JOIN webmail_board_cards c ON cm.card_id = c.id
                        JOIN webmail_board_lists l ON c.list_id = l.id
                        WHERE l.board_id IN ($placeholders)
                    ");
                    $stmt->execute($boardIds);
                    $result['total_comments'] = (int)($stmt->fetch()['count'] ?? 0);
                    
                    // Attachments count
                    $stmt = $this->db->prepare("
                        SELECT COUNT(*) as count
                        FROM webmail_card_attachments a
                        JOIN webmail_board_cards c ON a.card_id = c.id
                        JOIN webmail_board_lists l ON c.list_id = l.id
                        WHERE l.board_id IN ($placeholders)
                    ");
                    $stmt->execute($boardIds);
                    $result['total_attachments'] = (int)($stmt->fetch()['count'] ?? 0);
                    
                    // Board details with card counts
                    $stmt = $this->db->prepare("
                        SELECT 
                            b.id, b.name,
                            (SELECT COUNT(*) FROM webmail_board_lists WHERE board_id = b.id AND archived = 0) as list_count,
                            (SELECT COUNT(*) FROM webmail_board_cards c2 
                             JOIN webmail_board_lists l2 ON c2.list_id = l2.id 
                             WHERE l2.board_id = b.id AND c2.archived = 0) as total_cards,
                            (SELECT COUNT(*) FROM webmail_board_cards c3 
                             JOIN webmail_board_lists l3 ON c3.list_id = l3.id 
                             WHERE l3.board_id = b.id AND c3.completed = 1 AND c3.archived = 0) as completed_cards,
                            (SELECT COUNT(*) FROM webmail_board_cards c4 
                             JOIN webmail_board_lists l4 ON c4.list_id = l4.id 
                             WHERE l4.board_id = b.id AND c4.due_date IS NOT NULL AND c4.due_date < NOW() AND c4.completed = 0 AND c4.archived = 0) as overdue_cards
                        FROM webmail_boards b
                        WHERE b.id IN ($placeholders) AND b.archived = 0
                        ORDER BY b.name
                    ");
                    $stmt->execute($boardIds);
                    $result['boards'] = $stmt->fetchAll();
                    
                    // Upcoming deadlines (next 14 days)
                    $stmt = $this->db->prepare("
                        SELECT c.id, c.title, c.due_date, b.name as board_name, l.name as list_name
                        FROM webmail_board_cards c
                        JOIN webmail_board_lists l ON c.list_id = l.id
                        JOIN webmail_boards b ON l.board_id = b.id
                        WHERE l.board_id IN ($placeholders) 
                        AND c.due_date IS NOT NULL 
                        AND c.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)
                        AND c.completed = 0
                        AND c.archived = 0
                        ORDER BY c.due_date ASC
                        LIMIT 20
                    ");
                    $stmt->execute($boardIds);
                    $result['upcoming_deadlines'] = $stmt->fetchAll();
                    
                    // Cards by list (status distribution)
                    $stmt = $this->db->prepare("
                        SELECT l.name as list_name, COUNT(c.id) as card_count, b.name as board_name
                        FROM webmail_board_lists l
                        JOIN webmail_boards b ON l.board_id = b.id
                        LEFT JOIN webmail_board_cards c ON c.list_id = l.id AND c.archived = 0
                        WHERE l.board_id IN ($placeholders) AND l.archived = 0
                        GROUP BY l.id, l.name, b.name
                        ORDER BY b.name, l.position
                    ");
                    $stmt->execute($boardIds);
                    $result['cards_by_status'] = $stmt->fetchAll();
                    
                    // Boards linked to clients
                    $stmt = $this->db->prepare("
                        SELECT COUNT(DISTINCT cb.board_id) as count
                        FROM client_boards cb
                        WHERE cb.board_id IN ($placeholders)
                    ");
                    $stmt->execute($boardIds);
                    $result['boards_with_clients'] = (int)($stmt->fetch()['count'] ?? 0);
                }
                
            } catch (\PDOException $e) {
                error_log("StatisticsService getBoardStats table query error: " . $e->getMessage());
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("StatisticsService getBoardStats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get client statistics  
     */
    public function getClientStats(string $userEmail, string $period = 'month'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            $userEmail = strtolower($userEmail);
            
            $result = [
                'total_clients' => 0,
                'clients_added_period' => 0,
                'clients_with_boards' => 0,
                'clients_with_drive_folder' => 0,
                'top_clients' => []
            ];
            
            try {
                // Total clients
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM clients 
                    WHERE user_email = ?
                ");
                $stmt->execute([$userEmail]);
                $result['total_clients'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Clients added in period
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM clients 
                    WHERE user_email = ? AND created_at >= ?
                ");
                $stmt->execute([$userEmail, $dateCondition]);
                $result['clients_added_period'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Clients with linked boards
                $stmt = $this->db->prepare("
                    SELECT COUNT(DISTINCT c.id) as count 
                    FROM clients c
                    JOIN client_boards cb ON c.id = cb.client_id
                    WHERE c.user_email = ?
                ");
                $stmt->execute([$userEmail]);
                $result['clients_with_boards'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Clients with drive folder linked
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM clients 
                    WHERE user_email = ? AND drive_folder_id IS NOT NULL
                ");
                $stmt->execute([$userEmail]);
                $result['clients_with_drive_folder'] = (int)($stmt->fetch()['count'] ?? 0);
                
                // Top clients by board activity (tasks with due dates)
                $stmt = $this->db->prepare("
                    SELECT 
                        c.id, 
                        c.email, 
                        c.display_name,
                        COUNT(DISTINCT cb.board_id) as board_count,
                        (SELECT COUNT(*) 
                         FROM webmail_board_cards card
                         JOIN webmail_board_lists lst ON card.list_id = lst.id
                         WHERE lst.board_id IN (SELECT board_id FROM client_boards WHERE client_id = c.id)
                         AND card.archived = 0 AND card.completed = 0
                        ) as pending_tasks
                    FROM clients c
                    LEFT JOIN client_boards cb ON c.id = cb.client_id
                    WHERE c.user_email = ?
                    GROUP BY c.id, c.email, c.display_name
                    HAVING board_count > 0
                    ORDER BY pending_tasks DESC
                    LIMIT 10
                ");
                $stmt->execute([$userEmail]);
                $result['top_clients'] = $stmt->fetchAll();
                
            } catch (\PDOException $e) {
                error_log("StatisticsService getClientStats table query error: " . $e->getMessage());
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("StatisticsService getClientStats error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== AI STATISTICS ====================
    
    /**
     * Get AI usage statistics
     */
    public function getAIStats(string $userEmail, string $period = 'month'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            
            $stmt = $this->db->prepare("
                SELECT stat_type, SUM(value) as total
                FROM webmail_statistics
                WHERE user_email = ? AND period_start >= ?
                AND stat_type IN ('ai_summaries', 'ai_rewrites')
                GROUP BY stat_type
            ");
            $stmt->execute([strtolower($userEmail), $dateCondition]);
            
            $result = ['summaries' => 0, 'rewrites' => 0];
            foreach ($stmt->fetchAll() as $row) {
                if ($row['stat_type'] === 'ai_summaries') {
                    $result['summaries'] = (int)$row['total'];
                } else if ($row['stat_type'] === 'ai_rewrites') {
                    $result['rewrites'] = (int)$row['total'];
                }
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("StatisticsService getAIStats error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== FOLDER STATISTICS ====================
    
    /**
     * Get folder usage statistics
     */
    public function getFolderStats(string $userEmail): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            // Get folder usage from metadata
            $stmt = $this->db->prepare("
                SELECT metadata, SUM(value) as total
                FROM webmail_statistics
                WHERE user_email = ? AND stat_type LIKE 'folder_usage_%'
                GROUP BY stat_type
                ORDER BY total DESC
                LIMIT 20
            ");
            $stmt->execute([strtolower($userEmail)]);
            
            $folders = [];
            foreach ($stmt->fetchAll() as $row) {
                $meta = json_decode($row['metadata'], true);
                if (!empty($meta['folder'])) {
                    $folders[] = [
                        'folder' => $meta['folder'],
                        'usage_count' => (int)$row['total']
                    ];
                }
            }
            
            return $folders;
        } catch (\PDOException $e) {
            error_log("StatisticsService getFolderStats error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== PREFERENCE STATISTICS ====================
    
    /**
     * Get preference usage statistics
     */
    public function getPreferenceStats(string $userEmail): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $stmt = $this->db->prepare('
                SELECT preference_type, preference_value, usage_count
                FROM webmail_preference_stats
                WHERE user_email = ?
                ORDER BY preference_type, usage_count DESC
            ');
            $stmt->execute([strtolower($userEmail)]);
            
            $result = [];
            foreach ($stmt->fetchAll() as $row) {
                $type = $row['preference_type'];
                if (!isset($result[$type])) {
                    $result[$type] = [];
                }
                $result[$type][] = [
                    'value' => $row['preference_value'],
                    'count' => (int)$row['usage_count']
                ];
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("StatisticsService getPreferenceStats error: " . $e->getMessage());
            return [];
        }
    }
    
    // ==================== OVERVIEW ====================
    
    /**
     * Get read receipt statistics from email tracking
     */
    public function getReadReceiptStats(string $userEmail, string $period = 'month'): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $dateCondition = $this->getDateCondition($period);
            
            $result = [
                'total_read' => 0,
                'avg_read_time' => null,
                'fastest_reader' => null,
                'top_readers' => []
            ];
            
            // Try to get tracking data from email_tracking + email_read_events tables
            try {
                // Total emails that have been read (have at least one read event)
                $stmt = $this->db->prepare("
                    SELECT 
                        COUNT(DISTINCT t.tracking_id) as count,
                        AVG(TIMESTAMPDIFF(SECOND, t.sent_at, e.read_at)) as avg_time
                    FROM email_tracking t
                    INNER JOIN email_read_events e ON t.tracking_id = e.tracking_id
                    WHERE t.user_email = ? AND t.sent_at >= ?
                ");
                $stmt->execute([strtolower($userEmail), $dateCondition]);
                $row = $stmt->fetch();
                if ($row) {
                    $result['total_read'] = (int)($row['count'] ?? 0);
                    $result['avg_read_time'] = $row['avg_time'] ? (int)$row['avg_time'] : null;
                }
                
                // Top readers (who opens your emails fastest) - get from read events
                $stmt = $this->db->prepare("
                    SELECT 
                        e.recipient_email as email,
                        e.recipient_email as name,
                        COUNT(*) as read_count,
                        AVG(TIMESTAMPDIFF(SECOND, t.sent_at, e.read_at)) as avg_time
                    FROM email_tracking t
                    INNER JOIN email_read_events e ON t.tracking_id = e.tracking_id
                    WHERE t.user_email = ? AND t.sent_at >= ? AND e.recipient_email IS NOT NULL
                    GROUP BY e.recipient_email
                    ORDER BY avg_time ASC
                    LIMIT 10
                ");
                $stmt->execute([strtolower($userEmail), $dateCondition]);
                $result['top_readers'] = $stmt->fetchAll();
                
                if (!empty($result['top_readers'])) {
                    $fastest = $result['top_readers'][0];
                    $result['fastest_reader'] = $fastest['name'] ?: $fastest['email'];
                }
                
            } catch (\PDOException $e) {
                // Tracking tables might not exist or have different schema, ignore
                error_log("Read tracking stats error: " . $e->getMessage());
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("StatisticsService getReadReceiptStats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get comprehensive overview of all statistics
     */
    public function getOverview(string $userEmail, string $period = 'week'): array
    {
        return [
            'email' => $this->getEmailStats($userEmail, $period),
            'top_contacts' => $this->getTopContacts($userEmail, 10),
            'active_conversations' => $this->getActiveConversations($userEmail, $period, 10),
            'tasks' => $this->getTaskStats($userEmail, $period),
            'calendar' => $this->getCalendarStats($userEmail, $period),
            'drive' => $this->getDriveStats($userEmail, $period),
            'boards' => $this->getBoardStats($userEmail, $period),
            'clients' => $this->getClientStats($userEmail, $period),
            'ai' => $this->getAIStats($userEmail, $period),
            'time' => $this->getTimeStats($userEmail, $period),
            'folders' => $this->getFolderStats($userEmail),
            'preferences' => $this->getPreferenceStats($userEmail),
            'read_receipts' => $this->getReadReceiptStats($userEmail, $period),
            'period' => $period,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Get date condition based on period
     */
    private function getDateCondition(string $period): string
    {
        switch ($period) {
            case 'day':
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
                return date('Y-m-d', strtotime('-7 days'));
        }
    }
    
    /**
     * Get recent events for debugging/review
     */
    public function getRecentEvents(string $userEmail, int $limit = 50): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        try {
            $stmt = $this->db->prepare('
                SELECT event_type, event_data, created_at
                FROM webmail_stats_events
                WHERE user_email = ?
                ORDER BY created_at DESC
                LIMIT ?
            ');
            $stmt->execute([strtolower($userEmail), $limit]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log("StatisticsService getRecentEvents error: " . $e->getMessage());
            return [];
        }
    }
}

