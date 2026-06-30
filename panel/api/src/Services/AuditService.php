<?php

namespace VpsAdmin\Api\Services;

use VpsAdmin\Api\Core\Container;

/**
 * Audit logging service - centralized for all apps
 * Receives events from: panel, email app, mailsync, collab
 */
class AuditService
{
    private Container $container;
    private \PDO $db;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
    }

    /**
     * Log an action (panel-originated — backward compatible)
     */
    public function log(
        string $action,
        string $actor,
        string $target,
        string $outcome,
        array $details = [],
        ?string $backupPath = null,
        ?string $diff = null
    ): int {
        return $this->logEvent([
            'source_app' => 'panel',
            'severity' => 'info',
            'action' => $action,
            'actor' => $actor,
            'target' => $target,
            'outcome' => $outcome,
            'details' => $details,
            'backup_path' => $backupPath,
            'diff' => $diff,
        ]);
    }

    /**
     * Log a structured event (multi-app support)
     */
    public function logEvent(array $event): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (source_app, severity, action, actor, ip_address, user_email, target, details, backup_path, diff, outcome) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $event['source_app'] ?? 'panel',
            $event['severity'] ?? 'info',
            $event['action'] ?? '',
            $event['actor'] ?? 'system',
            $event['ip_address'] ?? null,
            $event['user_email'] ?? null,
            $event['target'] ?? '',
            json_encode($event['details'] ?? []),
            $event['backup_path'] ?? null,
            $event['diff'] ?? null,
            $event['outcome'] ?? 'success',
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Ingest batch of events from external apps
     */
    public function ingestBatch(array $events, string $sourceApp): array
    {
        $inserted = 0;
        $errors = [];

        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (source_app, severity, action, actor, ip_address, user_email, target, details, outcome) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($events as $i => $event) {
            try {
                // Validate required fields
                if (empty($event['action'])) {
                    $errors[] = "Event {$i}: missing 'action' field";
                    continue;
                }

                $stmt->execute([
                    $sourceApp,
                    $event['severity'] ?? 'info',
                    $event['action'],
                    $event['actor'] ?? 'system',
                    $event['ip_address'] ?? null,
                    $event['user_email'] ?? null,
                    $event['target'] ?? '',
                    json_encode($event['details'] ?? []),
                    $event['outcome'] ?? 'success',
                ]);
                $inserted++;
            } catch (\PDOException $e) {
                $errors[] = "Event {$i}: " . $e->getMessage();
            }
        }

        return [
            'inserted' => $inserted,
            'errors' => $errors,
        ];
    }

    /**
     * Get audit logs with pagination and filters
     */
    public function getLogs(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['source_app'])) {
            $where[] = 'source_app = ?';
            $params[] = $filters['source_app'];
        }

        if (!empty($filters['severity'])) {
            $where[] = 'severity = ?';
            $params[] = $filters['severity'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'action LIKE ?';
            $params[] = '%' . $filters['action'] . '%';
        }

        if (!empty($filters['actor'])) {
            $where[] = 'actor = ?';
            $params[] = $filters['actor'];
        }

        if (!empty($filters['user_email'])) {
            $where[] = 'user_email LIKE ?';
            $params[] = '%' . $filters['user_email'] . '%';
        }

        if (!empty($filters['target'])) {
            $where[] = 'target LIKE ?';
            $params[] = '%' . $filters['target'] . '%';
        }

        if (!empty($filters['outcome'])) {
            $where[] = 'outcome = ?';
            $params[] = $filters['outcome'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['to'];
        }

        // Full-text search across multiple fields
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $where[] = '(action LIKE ? OR target LIKE ? OR actor LIKE ? OR user_email LIKE ? OR details LIKE ?)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countSql = "SELECT COUNT(*) FROM audit_logs {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Get page
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM audit_logs {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        // Decode JSON details
        foreach ($logs as &$log) {
            $log['details'] = json_decode($log['details'], true) ?? [];
        }

        return [
            'data' => $logs,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Get a single log entry
     */
    public function getLog(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM audit_logs WHERE id = ?");
        $stmt->execute([$id]);
        $log = $stmt->fetch();

        if ($log) {
            $log['details'] = json_decode($log['details'], true) ?? [];
        }

        return $log ?: null;
    }

    /**
     * Get logs for a specific target
     */
    public function getLogsForTarget(string $target, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM audit_logs WHERE target = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$target, $limit]);
        $logs = $stmt->fetchAll();

        foreach ($logs as &$log) {
            $log['details'] = json_decode($log['details'], true) ?? [];
        }

        return $logs;
    }

    /**
     * Get summary statistics for the dashboard
     */
    public function getStats(int $hours = 24): array
    {
        // Overall counts by outcome
        $stmt = $this->db->prepare(
            "SELECT 
                outcome,
                COUNT(*) as count,
                COUNT(DISTINCT action) as unique_actions
             FROM audit_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY outcome"
        );
        $stmt->execute([$hours]);
        $byOutcome = $stmt->fetchAll();

        // Counts by source app
        $stmt = $this->db->prepare(
            "SELECT source_app, COUNT(*) as count
             FROM audit_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY source_app
             ORDER BY count DESC"
        );
        $stmt->execute([$hours]);
        $bySource = $stmt->fetchAll();

        // Counts by severity
        $stmt = $this->db->prepare(
            "SELECT severity, COUNT(*) as count
             FROM audit_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY severity
             ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low', 'info')"
        );
        $stmt->execute([$hours]);
        $bySeverity = $stmt->fetchAll();

        // Total in period
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        $stmt->execute([$hours]);
        $total = (int)$stmt->fetchColumn();

        // Recent critical/high events
        $stmt = $this->db->prepare(
            "SELECT id, source_app, severity, action, actor, user_email, target, outcome, created_at
             FROM audit_logs 
             WHERE severity IN ('critical', 'high') 
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY created_at DESC
             LIMIT 10"
        );
        $stmt->execute([$hours]);
        $recentCritical = $stmt->fetchAll();

        return [
            'total' => $total,
            'hours' => $hours,
            'by_outcome' => $byOutcome,
            'by_source' => $bySource,
            'by_severity' => $bySeverity,
            'recent_critical' => $recentCritical,
        ];
    }

    /**
     * Export logs as CSV-friendly array
     */
    public function exportLogs(array $filters = [], int $limit = 10000): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['source_app'])) {
            $where[] = 'source_app = ?';
            $params[] = $filters['source_app'];
        }

        if (!empty($filters['severity'])) {
            $where[] = 'severity = ?';
            $params[] = $filters['severity'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['to'];
        }

        if (!empty($filters['outcome'])) {
            $where[] = 'outcome = ?';
            $params[] = $filters['outcome'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT id, source_app, severity, action, actor, ip_address, user_email, target, outcome, created_at
                FROM audit_logs {$whereClause} ORDER BY created_at DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Get summary of recent actions (backward compatible)
     */
    public function getSummary(int $hours = 24): array
    {
        return $this->getStats($hours);
    }
}
