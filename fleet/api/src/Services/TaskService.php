<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Task Service - Manages agent task queue
 */
class TaskService
{
    private \PDO $db;

    // Task types
    public const TYPE_SYNC_FILES = 'sync_files';
    public const TYPE_RUN_COMMAND = 'run_command';
    public const TYPE_UPDATE_AGENT = 'update_agent';
    public const TYPE_RESTART_SERVICE = 'restart_service';
    public const TYPE_PULL_LOGS = 'pull_logs';
    public const TYPE_HEALTH_CHECK = 'health_check';
    public const TYPE_CUSTOM = 'custom';
    public const TYPE_UPDATE_PACKAGES = 'update_packages';

    // Task statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(Container $container)
    {
        $this->db = $container->getDatabase();
    }

    /**
     * Create a new task for a server
     */
    public function createTask(
        int $serverId,
        string $type,
        array $payload,
        int $priority = 5,
        ?int $createdBy = null,
        int $timeout = 300,
        int $maxRetries = 3
    ): array {
        $stmt = $this->db->prepare(
            "INSERT INTO agent_tasks 
                (server_id, type, payload, priority, created_by, timeout_seconds, max_retries)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $serverId,
            $type,
            json_encode($payload),
            $priority,
            $createdBy,
            $timeout,
            $maxRetries,
        ]);

        $taskId = (int)$this->db->lastInsertId();

        return $this->getTask($taskId);
    }

    /**
     * Create task to run a command on server
     */
    public function createCommandTask(
        int $serverId,
        string $command,
        ?int $createdBy = null,
        int $timeout = 300
    ): array {
        return $this->createTask(
            $serverId,
            self::TYPE_RUN_COMMAND,
            ['command' => $command],
            5,
            $createdBy,
            $timeout
        );
    }

    /**
     * Create task to sync files to server
     */
    public function createSyncFilesTask(
        int $serverId,
        array $files,
        ?int $createdBy = null
    ): array {
        // Files should be array of: [{path, content, mode, owner}]
        return $this->createTask(
            $serverId,
            self::TYPE_SYNC_FILES,
            ['files' => $files],
            5,
            $createdBy,
            600 // 10 minutes for file sync
        );
    }

    /**
     * Create task to restart a service
     */
    public function createRestartServiceTask(
        int $serverId,
        string $service,
        ?int $createdBy = null
    ): array {
        return $this->createTask(
            $serverId,
            self::TYPE_RESTART_SERVICE,
            ['service' => $service],
            3, // Higher priority
            $createdBy,
            120
        );
    }

    /**
     * Create task to apply pending OS/npm updates.
     * No automatic retries: re-running a half-failed package upgrade
     * unattended can make things worse — failures surface in the panel.
     */
    public function createUpdatePackagesTask(
        int $serverId,
        array $payload,
        ?int $createdBy = null
    ): array {
        return $this->createTask(
            $serverId,
            self::TYPE_UPDATE_PACKAGES,
            $payload,
            4,
            $createdBy,
            1800, // 30 minutes: apt upgrade + npm update + service restarts
            0
        );
    }

    /**
     * Create task for multiple servers (bulk)
     */
    public function createBulkTask(
        array $serverIds,
        string $type,
        array $payload,
        ?int $createdBy = null
    ): array {
        $tasks = [];
        foreach ($serverIds as $serverId) {
            $tasks[] = $this->createTask($serverId, $type, $payload, 5, $createdBy);
        }
        return $tasks;
    }

    /**
     * Get pending tasks for a server (called by agent)
     */
    public function getPendingTasks(int $serverId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, type, payload, priority, timeout_seconds, retry_count, max_retries
             FROM agent_tasks 
             WHERE server_id = ? AND status IN ('pending', 'queued')
             ORDER BY priority ASC, created_at ASC
             LIMIT ?"
        );
        $stmt->execute([$serverId, $limit]);
        $tasks = $stmt->fetchAll();

        // Mark as queued
        if (!empty($tasks)) {
            $ids = array_column($tasks, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare(
                "UPDATE agent_tasks SET status = 'queued', queued_at = NOW() 
                 WHERE id IN ({$placeholders})"
            )->execute($ids);
        }

        // Parse JSON payload
        foreach ($tasks as &$task) {
            $task['payload'] = json_decode($task['payload'], true);
        }

        return $tasks;
    }

    /**
     * Mark task as started
     */
    public function startTask(int $taskId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE agent_tasks SET status = 'running', started_at = NOW(), progress = 0 WHERE id = ?"
        );
        $stmt->execute([$taskId]);

        $this->logTask($taskId, 'info', 'Task execution started');
    }

    /**
     * Update task progress
     */
    public function updateProgress(int $taskId, int $progress, ?string $message = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE agent_tasks SET progress = ? WHERE id = ?"
        );
        $stmt->execute([min(100, max(0, $progress)), $taskId]);

        if ($message) {
            $this->logTask($taskId, 'info', $message);
        }
    }

    /**
     * Complete task successfully
     */
    public function completeTask(int $taskId, ?string $result = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE agent_tasks 
             SET status = 'success', progress = 100, result = ?, completed_at = NOW() 
             WHERE id = ?"
        );
        $stmt->execute([$result, $taskId]);

        $this->logTask($taskId, 'info', 'Task completed successfully');
    }

    /**
     * Fail task
     */
    public function failTask(int $taskId, string $error): void
    {
        // Check if we should retry
        $stmt = $this->db->prepare(
            "SELECT retry_count, max_retries FROM agent_tasks WHERE id = ?"
        );
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if ($task && $task['retry_count'] < $task['max_retries']) {
            // Retry - put back to pending
            $stmt = $this->db->prepare(
                "UPDATE agent_tasks 
                 SET status = 'pending', retry_count = retry_count + 1, error_message = ?
                 WHERE id = ?"
            );
            $stmt->execute([$error, $taskId]);
            $this->logTask($taskId, 'warning', "Task failed, will retry: {$error}");
        } else {
            // Final failure
            $stmt = $this->db->prepare(
                "UPDATE agent_tasks 
                 SET status = 'failed', error_message = ?, completed_at = NOW() 
                 WHERE id = ?"
            );
            $stmt->execute([$error, $taskId]);
            $this->logTask($taskId, 'error', "Task failed permanently: {$error}");
        }
    }

    /**
     * Cancel a task
     */
    public function cancelTask(int $taskId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE agent_tasks 
             SET status = 'cancelled', completed_at = NOW() 
             WHERE id = ? AND status IN ('pending', 'queued')"
        );
        $stmt->execute([$taskId]);

        if ($stmt->rowCount() > 0) {
            $this->logTask($taskId, 'info', 'Task cancelled');
            return true;
        }
        return false;
    }

    /**
     * Get a single task
     */
    public function getTask(int $taskId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, s.name as server_name
             FROM agent_tasks t
             JOIN servers s ON t.server_id = s.id
             WHERE t.id = ?"
        );
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if ($task) {
            $task['payload'] = json_decode($task['payload'], true);
        }

        return $task ?: null;
    }

    /**
     * Get tasks for a server
     */
    public function getServerTasks(int $serverId, ?string $status = null, int $limit = 50): array
    {
        $sql = "SELECT * FROM agent_tasks WHERE server_id = ?";
        $params = [$serverId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();

        foreach ($tasks as &$task) {
            $task['payload'] = json_decode($task['payload'], true);
        }

        return $tasks;
    }

    /**
     * Get all tasks with filters
     */
    public function getTasks(array $filters = [], int $limit = 100): array
    {
        $sql = "SELECT t.*, s.name as server_name
                FROM agent_tasks t
                JOIN servers s ON t.server_id = s.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND t.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND t.type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['server_id'])) {
            $sql .= " AND t.server_id = ?";
            $params[] = $filters['server_id'];
        }

        $sql .= " ORDER BY t.created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();

        foreach ($tasks as &$task) {
            $task['payload'] = json_decode($task['payload'], true);
        }

        return $tasks;
    }

    /**
     * Get task logs
     */
    public function getTaskLogs(int $taskId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM agent_task_logs WHERE task_id = ? ORDER BY created_at ASC"
        );
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }

    /**
     * Log task event
     */
    public function logTask(int $taskId, string $level, string $message): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO agent_task_logs (task_id, level, message) VALUES (?, ?, ?)"
        );
        $stmt->execute([$taskId, $level, $message]);
    }

    /**
     * Clean up old completed tasks
     */
    public function cleanupOldTasks(int $daysOld = 30): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM agent_tasks 
             WHERE status IN ('success', 'failed', 'cancelled') 
             AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    }

    /**
     * Get task statistics
     */
    public function getStats(): array
    {
        $stmt = $this->db->query(
            "SELECT 
                status,
                COUNT(*) as count
             FROM agent_tasks
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY status"
        );
        $stats = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        return [
            'pending' => (int)($stats['pending'] ?? 0),
            'queued' => (int)($stats['queued'] ?? 0),
            'running' => (int)($stats['running'] ?? 0),
            'success' => (int)($stats['success'] ?? 0),
            'failed' => (int)($stats['failed'] ?? 0),
            'cancelled' => (int)($stats['cancelled'] ?? 0),
        ];
    }
}

