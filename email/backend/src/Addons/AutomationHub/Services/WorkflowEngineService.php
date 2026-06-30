<?php

namespace Webmail\Addons\AutomationHub\Services;

class WorkflowEngineService
{
    private array $config;
    private \PDO $db;
    private ?NodeExecutorService $executor = null;
    private const MAX_CHAIN_DEPTH = 50;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->executor = new NodeExecutorService($config);
    }

    /**
     * Execute a workflow from its trigger node.
     * Returns the execution ID.
     */
    public function execute(int $workflowId, array $triggerData = [], bool $isTest = false): int
    {
        // Load graph
        $nodes = $this->loadNodes($workflowId);
        $edges = $this->loadEdges($workflowId);

        if (empty($nodes)) {
            throw new \RuntimeException('Workflow has no nodes');
        }

        // Find all enabled trigger nodes
        $triggerNodes = [];
        foreach ($nodes as $n) {
            if ($n['node_category'] === 'trigger') {
                $cfg = is_string($n['config']) ? json_decode($n['config'], true) : ($n['config'] ?? []);
                if (!empty($cfg['disabled'])) continue;
                $triggerNodes[] = $n;
            }
        }

        if (empty($triggerNodes)) {
            throw new \RuntimeException('Workflow has no enabled trigger node');
        }

        // Create execution record (use first trigger as primary)
        $stmt = $this->db->prepare("
            INSERT INTO automation_hub_executions (workflow_id, trigger_node_uid, status, trigger_data, is_test)
            VALUES (?, ?, 'running', ?, ?)
        ");
        $stmt->execute([$workflowId, $triggerNodes[0]['node_uid'], json_encode($triggerData), $isTest ? 1 : 0]);
        $executionId = (int)$this->db->lastInsertId();

        try {
            // Build adjacency list
            $adj = $this->buildAdjacencyList($edges);
            $nodeMap = [];
            foreach ($nodes as $n) {
                $nodeMap[$n['node_uid']] = $n;
            }

            // Execute each enabled trigger chain
            foreach ($triggerNodes as $triggerNode) {
                $this->executeNode($executionId, $triggerNode, $triggerData, $adj, $nodeMap, $isTest, 0);
            }

            // Mark complete
            $this->db->prepare("UPDATE automation_hub_executions SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$executionId]);

            // Update workflow stats
            if (!$isTest) {
                $this->db->prepare("UPDATE automation_hub_workflows SET run_count = run_count + 1, last_run_at = NOW() WHERE id = ?")->execute([$workflowId]);
            }

            // Publish completion event via Redis
            $this->publishExecutionEvent($executionId, 'completed');

        } catch (\Throwable $e) {
            $this->db->prepare("UPDATE automation_hub_executions SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?")->execute([$e->getMessage(), $executionId]);
            $this->publishExecutionEvent($executionId, 'failed');
            throw $e;
        }

        return $executionId;
    }

    /**
     * Recursively execute a node and its downstream neighbors.
     */
    private function executeNode(int $executionId, array $node, array $inputData, array $adj, array $nodeMap, bool $isTest, int $depth): array
    {
        if ($depth > self::MAX_CHAIN_DEPTH) {
            throw new \RuntimeException("Maximum chain depth exceeded at node: {$node['node_uid']}");
        }

        $nodeUid = $node['node_uid'];
        $config = is_string($node['config']) ? json_decode($node['config'], true) : ($node['config'] ?? []);

        if (!empty($config['disabled'])) {
            $this->db->prepare("
                INSERT INTO automation_hub_node_executions (execution_id, node_uid, status, input_data, output_data, started_at, completed_at, duration_ms)
                VALUES (?, ?, 'skipped', ?, ?, NOW(), NOW(), 0)
            ")->execute([$executionId, $nodeUid, json_encode($inputData), json_encode($inputData)]);
            $this->publishNodeEvent($executionId, $nodeUid, 'skipped');
            return $inputData;
        }

        $startTime = microtime(true);

        // Log node start
        $stmt = $this->db->prepare("
            INSERT INTO automation_hub_node_executions (execution_id, node_uid, status, input_data, started_at)
            VALUES (?, ?, 'running', ?, NOW())
        ");
        $stmt->execute([$executionId, $nodeUid, json_encode($inputData)]);
        $nodeExecId = (int)$this->db->lastInsertId();

        $this->publishNodeEvent($executionId, $nodeUid, 'running');

        try {
            // Execute the node
            $result = $this->executor->execute($node['node_type'], $config, $inputData, $isTest);

            // Merge upstream context with this node's output for downstream nodes.
            // This preserves trigger variables ({metric}, {value}, {hostname}, etc.)
            // through the entire chain. The node's own output keys take precedence.
            $result = array_merge($inputData, $result);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            // Log node completion
            $this->db->prepare("
                UPDATE automation_hub_node_executions 
                SET status = 'completed', output_data = ?, completed_at = NOW(), duration_ms = ?
                WHERE id = ?
            ")->execute([json_encode($result), $durationMs, $nodeExecId]);

            $this->publishNodeEvent($executionId, $nodeUid, 'completed');

            // Determine which downstream edges to follow
            $downstreamEdges = $adj[$nodeUid] ?? [];

            if ($node['node_type'] === 'logic.condition') {
                // For conditions, only follow the matching branch
                $conditionResult = $result['condition_result'] ?? false;
                $branchPort = $conditionResult ? 'true' : 'false';

                foreach ($downstreamEdges as $edge) {
                    if ($edge['source_port'] === $branchPort) {
                        $targetNode = $nodeMap[$edge['target_node_uid']] ?? null;
                        if ($targetNode) {
                            $this->executeNode($executionId, $targetNode, $result, $adj, $nodeMap, $isTest, $depth + 1);
                        }
                    }
                }

                // Mark skipped branch
                $skippedPort = $conditionResult ? 'false' : 'true';
                foreach ($downstreamEdges as $edge) {
                    if ($edge['source_port'] === $skippedPort) {
                        $this->markBranchSkipped($executionId, $edge['target_node_uid'], $adj, $nodeMap);
                    }
                }
            } elseif ($node['node_type'] === 'logic.delay') {
                // Schedule continuation
                $delaySeconds = $this->calculateDelaySeconds($config);
                $resumeAt = date('Y-m-d H:i:s', time() + $delaySeconds);

                $this->db->prepare("
                    INSERT INTO automation_hub_delayed_executions (execution_id, resume_node_uid, resume_at, input_data)
                    VALUES (?, ?, ?, ?)
                ")->execute([$executionId, $nodeUid, $resumeAt, json_encode($result)]);

                // Execution will be resumed by the cron job
            } elseif ($node['node_type'] === 'logic.filter') {
                // Only continue if filter passes
                $passes = $result['passes'] ?? true;
                if ($passes) {
                    foreach ($downstreamEdges as $edge) {
                        $targetNode = $nodeMap[$edge['target_node_uid']] ?? null;
                        if ($targetNode) {
                            $this->executeNode($executionId, $targetNode, $result, $adj, $nodeMap, $isTest, $depth + 1);
                        }
                    }
                }
            } else {
                // Standard: follow all downstream edges
                foreach ($downstreamEdges as $edge) {
                    $targetNode = $nodeMap[$edge['target_node_uid']] ?? null;
                    if ($targetNode) {
                        $this->executeNode($executionId, $targetNode, $result, $adj, $nodeMap, $isTest, $depth + 1);
                    }
                }
            }

            return $result;

        } catch (\Throwable $e) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $this->db->prepare("
                UPDATE automation_hub_node_executions 
                SET status = 'failed', error_message = ?, completed_at = NOW(), duration_ms = ?
                WHERE id = ?
            ")->execute([$e->getMessage(), $durationMs, $nodeExecId]);

            $this->publishNodeEvent($executionId, $nodeUid, 'failed');
            throw $e;
        }
    }

    private function markBranchSkipped(int $executionId, string $nodeUid, array $adj, array $nodeMap): void
    {
        $this->db->prepare("
            INSERT INTO automation_hub_node_executions (execution_id, node_uid, status, started_at, completed_at)
            VALUES (?, ?, 'skipped', NOW(), NOW())
        ")->execute([$executionId, $nodeUid]);

        $this->publishNodeEvent($executionId, $nodeUid, 'skipped');

        foreach (($adj[$nodeUid] ?? []) as $edge) {
            $this->markBranchSkipped($executionId, $edge['target_node_uid'], $adj, $nodeMap);
        }
    }

    private function calculateDelaySeconds(array $config): int
    {
        $value = (int)($config['delay_value'] ?? 5);
        $unit = $config['delay_unit'] ?? 'minutes';
        return match ($unit) {
            'seconds' => $value,
            'minutes' => $value * 60,
            'hours' => $value * 3600,
            'days' => $value * 86400,
            default => $value * 60,
        };
    }

    /**
     * Resume a delayed execution from a specific node's downstream neighbors.
     * Unlike execute(), this continues an existing execution instead of creating a new one.
     */
    public function resumeFromNode(int $executionId, int $workflowId, string $delayNodeUid, array $inputData): void
    {
        $nodes = $this->loadNodes($workflowId);
        $edges = $this->loadEdges($workflowId);
        $adj = $this->buildAdjacencyList($edges);
        $nodeMap = [];
        foreach ($nodes as $n) {
            $nodeMap[$n['node_uid']] = $n;
        }

        // Update existing execution back to running
        $this->db->prepare("UPDATE automation_hub_executions SET status = 'running' WHERE id = ?")->execute([$executionId]);

        try {
            $downstreamEdges = $adj[$delayNodeUid] ?? [];
            foreach ($downstreamEdges as $edge) {
                $targetNode = $nodeMap[$edge['target_node_uid']] ?? null;
                if ($targetNode) {
                    $this->executeNode($executionId, $targetNode, $inputData, $adj, $nodeMap, false, 0);
                }
            }

            // Check if there are more pending delays for this execution
            $pendingStmt = $this->db->prepare("
                SELECT COUNT(*) FROM automation_hub_delayed_executions
                WHERE execution_id = ? AND is_processed = 0
            ");
            $pendingStmt->execute([$executionId]);
            $pendingCount = (int)$pendingStmt->fetchColumn();

            if ($pendingCount === 0) {
                $this->db->prepare("UPDATE automation_hub_executions SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$executionId]);
                $this->publishExecutionEvent($executionId, 'completed');
            }
        } catch (\Throwable $e) {
            $this->db->prepare("UPDATE automation_hub_executions SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?")->execute([$e->getMessage(), $executionId]);
            $this->publishExecutionEvent($executionId, 'failed');
            error_log("AutomationHub resumeFromNode error: " . $e->getMessage());
        }
    }

    /**
     * Handle incoming webhook trigger.
     */
    public function onWebhookTrigger(string $token, array $body, array $headers): void
    {
        $stmt = $this->db->prepare("
            SELECT n.workflow_id, n.node_uid, n.config
            FROM automation_hub_nodes n
            JOIN automation_hub_workflows w ON n.workflow_id = w.id
            WHERE n.node_type = 'trigger.webhook.incoming'
              AND w.is_active = 1
              AND JSON_UNQUOTE(JSON_EXTRACT(n.config, '$.webhook_token')) = ?
        ");
        $stmt->execute([$token]);

        foreach ($stmt->fetchAll() as $row) {
            try {
                $this->execute((int)$row['workflow_id'], [
                    'webhook_body' => $body,
                    'webhook_headers' => $headers,
                ]);
            } catch (\Throwable $e) {
                error_log("AutomationHub webhook trigger error: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle Telegram webhook.
     */
    public function onTelegramWebhook(string $botToken, array $update): void
    {
        $stmt = $this->db->prepare("
            SELECT n.workflow_id, n.node_uid, n.config
            FROM automation_hub_nodes n
            JOIN automation_hub_workflows w ON n.workflow_id = w.id
            WHERE n.node_type = 'trigger.telegram.message'
              AND w.is_active = 1
              AND JSON_UNQUOTE(JSON_EXTRACT(n.config, '$.bot_token')) = ?
        ");
        $stmt->execute([$botToken]);

        $message = $update['message'] ?? $update['callback_query']['message'] ?? [];
        $text = $message['text'] ?? '';

        foreach ($stmt->fetchAll() as $row) {
            $config = json_decode($row['config'], true) ?? [];
            $triggerOn = $config['trigger_on'] ?? 'any';

            if ($triggerOn === 'command' && !empty($config['command'])) {
                if (!str_starts_with($text, $config['command'])) {
                    continue;
                }
            }

            try {
                $this->execute((int)$row['workflow_id'], [
                    'telegram_update' => $update,
                    'telegram_message' => $message,
                    'telegram_text' => $text,
                    'telegram_chat_id' => (string)($message['chat']['id'] ?? ''),
                    'telegram_from' => $message['from'] ?? [],
                ]);
            } catch (\Throwable $e) {
                error_log("AutomationHub telegram trigger error: " . $e->getMessage());
            }
        }
    }

    /**
     * Called by external systems (Board Pro, CRM Pro) to fire events.
     * When user_email is provided in $context, only workflows belonging to that user are triggered.
     */
    public function onEvent(string $triggerType, array $context): void
    {
        $userEmail = $context['user_email'] ?? null;

        if ($userEmail) {
            $stmt = $this->db->prepare("
                SELECT n.workflow_id, n.node_uid
                FROM automation_hub_nodes n
                JOIN automation_hub_workflows w ON n.workflow_id = w.id
                WHERE n.node_type = ?
                  AND w.is_active = 1
                  AND w.user_email = ?
            ");
            $stmt->execute([$triggerType, $userEmail]);
        } else {
            $stmt = $this->db->prepare("
                SELECT n.workflow_id, n.node_uid
                FROM automation_hub_nodes n
                JOIN automation_hub_workflows w ON n.workflow_id = w.id
                WHERE n.node_type = ?
                  AND w.is_active = 1
            ");
            $stmt->execute([$triggerType]);
        }

        foreach ($stmt->fetchAll() as $row) {
            try {
                $this->execute((int)$row['workflow_id'], $context);
            } catch (\Throwable $e) {
                error_log("AutomationHub event trigger error [{$triggerType}]: " . $e->getMessage());
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function loadNodes(int $workflowId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM automation_hub_nodes WHERE workflow_id = ?");
        $stmt->execute([$workflowId]);
        return $stmt->fetchAll();
    }

    private function loadEdges(int $workflowId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM automation_hub_edges WHERE workflow_id = ?");
        $stmt->execute([$workflowId]);
        return $stmt->fetchAll();
    }

    private function buildAdjacencyList(array $edges): array
    {
        $adj = [];
        foreach ($edges as $e) {
            $adj[$e['source_node_uid']][] = $e;
        }
        return $adj;
    }

    private function publishExecutionEvent(int $executionId, string $status): void
    {
        $this->publishRedis("automation_hub:execution:{$executionId}", json_encode([
            'execution_id' => $executionId,
            'status' => $status,
        ]));
    }

    private function publishNodeEvent(int $executionId, string $nodeUid, string $status): void
    {
        $this->publishRedis("automation_hub:execution:{$executionId}", json_encode([
            'execution_id' => $executionId,
            'node_uid' => $nodeUid,
            'status' => $status,
        ]));
    }

    private function publishRedis(string $channel, string $message): void
    {
        try {
            if (!extension_loaded('redis')) return;
            $redis = new \Redis();
            $host = $this->config['redis']['host'] ?? '127.0.0.1';
            $port = $this->config['redis']['port'] ?? 6379;
            $redis->connect($host, $port, 2.0);
            $password = $this->config['redis']['password'] ?? null;
            if ($password) $redis->auth($password);
            $prefix = $this->config['redis']['prefix'] ?? 'webmail:';
            $redis->publish($prefix . $channel, $message);
            $redis->close();
        } catch (\Throwable $e) {
            error_log("AutomationHub Redis publish error: " . $e->getMessage());
        }
    }
}
