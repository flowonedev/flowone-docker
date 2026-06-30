<?php

namespace Webmail\Addons\AutomationHub\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;

class DesktopTaskController extends BaseController
{
    private ?\PDO $db = null;

    private function getDb(): \PDO
    {
        if (!$this->db) {
            $dsn = "mysql:host={$this->config['db']['host']};dbname={$this->config['db']['name']};charset=utf8mb4";
            $this->db = new \PDO($dsn, $this->config['db']['user'], $this->config['db']['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }
        return $this->db;
    }

    /**
     * Get pending desktop tasks for the authenticated user.
     * Called by FlowOneEmail to pick up work.
     */
    public function pending(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $db = $this->getDb();
        $stmt = $db->prepare("
            SELECT id, task_type, payload, status, created_at
            FROM automation_desktop_tasks
            WHERE user_id = (SELECT id FROM users WHERE email = ? LIMIT 1)
              AND status = 'pending'
            ORDER BY created_at ASC
            LIMIT 20
        ");
        $stmt->execute([$this->userEmail]);
        $tasks = $stmt->fetchAll();

        foreach ($tasks as &$task) {
            $task['payload'] = json_decode($task['payload'], true);
        }

        return Response::success(['tasks' => $tasks]);
    }

    /**
     * Desktop app reports the result of a completed task.
     */
    public function reportResult(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $taskId = (int)$request->param('id');
        $body = $request->body();
        $status = ($body['success'] ?? false) ? 'completed' : 'failed';
        $result = $body['result'] ?? $body;

        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT id FROM automation_desktop_tasks
            WHERE id = ?
              AND user_id = (SELECT id FROM users WHERE email = ? LIMIT 1)
              AND status IN ('pending', 'processing')
        ");
        $stmt->execute([$taskId, $this->userEmail]);
        if (!$stmt->fetch()) {
            return Response::error('Task not found or already completed', 404);
        }

        $stmt = $db->prepare("
            UPDATE automation_desktop_tasks
            SET status = ?, result = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, json_encode($result), $taskId]);

        return Response::success(['updated' => true]);
    }

    /**
     * Create a desktop task (internal use by NodeExecutorService).
     * Returns the task ID for polling.
     */
    public static function createTask(\PDO $db, string $userEmail, string $taskType, array $payload, ?int $executionId = null, ?string $nodeUid = null): int
    {
        $stmt = $db->prepare("
            INSERT INTO automation_desktop_tasks (user_id, task_type, payload, workflow_execution_id, node_uid)
            VALUES ((SELECT id FROM users WHERE email = ? LIMIT 1), ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userEmail,
            $taskType,
            json_encode($payload),
            $executionId,
            $nodeUid,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Poll for task completion (internal use by NodeExecutorService).
     */
    public static function waitForResult(\PDO $db, int $taskId, int $timeoutSeconds = 15): ?array
    {
        $deadline = time() + $timeoutSeconds;
        $interval = 500000; // 500ms in microseconds

        while (time() < $deadline) {
            $stmt = $db->prepare("
                SELECT status, result FROM automation_desktop_tasks WHERE id = ?
            ");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();

            if (!$task) {
                return null;
            }

            if (in_array($task['status'], ['completed', 'failed'], true)) {
                return [
                    'status' => $task['status'],
                    'result' => json_decode($task['result'], true),
                ];
            }

            usleep($interval);
        }

        // Mark as timeout
        $stmt = $db->prepare("
            UPDATE automation_desktop_tasks SET status = 'timeout' WHERE id = ? AND status IN ('pending', 'processing')
        ");
        $stmt->execute([$taskId]);

        return ['status' => 'timeout', 'result' => null];
    }
}
