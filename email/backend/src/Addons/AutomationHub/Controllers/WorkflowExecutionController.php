<?php

namespace Webmail\Addons\AutomationHub\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\AutomationHub\Services\WorkflowEngineService;

class WorkflowExecutionController extends BaseController
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

    public function execute(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->getParam('id');
        $db = $this->getDb();

        $stmt = $db->prepare("SELECT id FROM automation_hub_workflows WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $this->userEmail]);
        if (!$stmt->fetch()) {
            return Response::notFound('Workflow not found');
        }

        try {
            $engine = new WorkflowEngineService($this->config);
            $triggerData = $request->input('trigger_data') ?? [];
            $triggerData['user_email'] = $this->userEmail;
            $triggerData['workflow_id'] = $id;
            $executionId = $engine->execute($id, $triggerData);

            return Response::success(['execution_id' => $executionId], 'Workflow execution started');
        } catch (\Throwable $e) {
            error_log("AutomationHub::execute error: " . $e->getMessage());
            return Response::error('Execution failed: ' . $e->getMessage(), 500);
        }
    }

    public function test(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->getParam('id');
        $db = $this->getDb();

        $stmt = $db->prepare("SELECT id FROM automation_hub_workflows WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $this->userEmail]);
        if (!$stmt->fetch()) {
            return Response::notFound('Workflow not found');
        }

        try {
            $engine = new WorkflowEngineService($this->config);
            $triggerData = $request->input('trigger_data') ?? [];
            $triggerData['user_email'] = $this->userEmail;
            $triggerData['workflow_id'] = $id;
            $executionId = $engine->execute($id, $triggerData, true);

            return Response::success(['execution_id' => $executionId], 'Test execution started');
        } catch (\Throwable $e) {
            error_log("AutomationHub::test error: " . $e->getMessage());
            return Response::error('Test failed: ' . $e->getMessage(), 500);
        }
    }

    public function listExecutions(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $workflowId = (int)$request->getParam('id');
        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT e.* FROM automation_hub_executions e
            JOIN automation_hub_workflows w ON e.workflow_id = w.id
            WHERE w.id = ? AND w.user_email = ?
            ORDER BY e.started_at DESC
            LIMIT 50
        ");
        $stmt->execute([$workflowId, $this->userEmail]);
        $executions = $stmt->fetchAll();

        foreach ($executions as &$ex) {
            if ($ex['trigger_data']) $ex['trigger_data'] = json_decode($ex['trigger_data'], true);
        }

        return Response::success(['executions' => $executions]);
    }

    public function getExecution(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $executionId = (int)$request->getParam('id');
        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT e.* FROM automation_hub_executions e
            JOIN automation_hub_workflows w ON e.workflow_id = w.id
            WHERE e.id = ? AND w.user_email = ?
        ");
        $stmt->execute([$executionId, $this->userEmail]);
        $execution = $stmt->fetch();

        if (!$execution) {
            return Response::notFound('Execution not found');
        }

        if ($execution['trigger_data']) {
            $execution['trigger_data'] = json_decode($execution['trigger_data'], true);
        }

        return Response::success(['execution' => $execution]);
    }

    public function getNodeExecutions(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $executionId = (int)$request->getParam('id');
        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT ne.*, n.node_type, n.label AS node_label
            FROM automation_hub_node_executions ne
            JOIN automation_hub_executions e ON ne.execution_id = e.id
            JOIN automation_hub_workflows w ON e.workflow_id = w.id
            LEFT JOIN automation_hub_nodes n ON n.workflow_id = e.workflow_id AND n.node_uid = ne.node_uid
            WHERE ne.execution_id = ? AND w.user_email = ?
            ORDER BY ne.started_at ASC
        ");
        $stmt->execute([$executionId, $this->userEmail]);
        $nodeExecs = $stmt->fetchAll();

        foreach ($nodeExecs as &$ne) {
            if ($ne['input_data']) $ne['input_data'] = json_decode($ne['input_data'], true);
            if ($ne['output_data']) $ne['output_data'] = json_decode($ne['output_data'], true);
        }

        return Response::success(['node_executions' => $nodeExecs]);
    }

    public function telegramWebhook(Request $request): Response
    {
        $token = $request->getParam('token');
        $body = $request->input();

        try {
            $engine = new WorkflowEngineService($this->config);
            $engine->onTelegramWebhook($token, $body);
            return Response::success([], 'OK');
        } catch (\Throwable $e) {
            error_log("AutomationHub::telegramWebhook error: " . $e->getMessage());
            return Response::success([], 'OK');
        }
    }

    public function webhookTrigger(Request $request): Response
    {
        $token = $request->getParam('token');
        $body = $request->input();
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }

        try {
            $engine = new WorkflowEngineService($this->config);
            $engine->onWebhookTrigger($token, $body, $headers);
            return Response::success([], 'Webhook received');
        } catch (\Throwable $e) {
            error_log("AutomationHub::webhookTrigger error: " . $e->getMessage());
            return Response::success([], 'OK');
        }
    }
}
