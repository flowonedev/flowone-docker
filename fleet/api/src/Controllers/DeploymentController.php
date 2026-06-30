<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Enums\DeploymentType;
use FleetManager\Api\Services\ProvisioningService;
use FleetManager\Api\Services\ConfigDeploymentService;
use FleetManager\Api\Services\PreflightService;
use FleetManager\Api\Services\AuditService;
use FleetManager\Api\Services\SSHService;

/**
 * Deployment controller - handles all deployment operations
 */
class DeploymentController extends BaseController
{
    /**
     * List deployments for a server
     */
    public function index(Request $request): Response
    {
        $serverId = $request->getQuery('server_id');
        $db = $this->getDatabase();
        $pagination = $this->getPagination($request);

        $where = [];
        $params = [];

        if ($serverId) {
            $where[] = 'd.server_id = ?';
            $params[] = $serverId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total
        $stmt = $db->prepare("SELECT COUNT(*) FROM deployments d {$whereClause}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Get deployments
        $sql = "SELECT d.*, s.name as server_name, b.name as blueprint_name
                FROM deployments d
                JOIN servers s ON d.server_id = s.id
                LEFT JOIN blueprints b ON d.blueprint_id = b.id
                {$whereClause}
                ORDER BY d.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $pagination['per_page'];
        $params[] = $pagination['offset'];

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deployments = $stmt->fetchAll();

        foreach ($deployments as &$d) {
            if (!empty($d['preflight_results']) && is_string($d['preflight_results'])) {
                $d['preflight_results'] = json_decode($d['preflight_results'], true);
            }
        }
        unset($d);

        return Response::success([
            'deployments' => $deployments,
            'total' => $total,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
        ]);
    }

    /**
     * Get single deployment with log
     */
    public function show(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare(
            "SELECT d.*, s.name as server_name, b.name as blueprint_name
             FROM deployments d
             JOIN servers s ON d.server_id = s.id
             LEFT JOIN blueprints b ON d.blueprint_id = b.id
             WHERE d.id = ?"
        );
        $stmt->execute([$id]);
        $deployment = $stmt->fetch();

        if (!$deployment) {
            return Response::notFound('Deployment not found');
        }

        return Response::success($deployment);
    }

    /**
     * Get available deployment types with descriptions
     */
    public function types(Request $request): Response
    {
        $types = [];
        
        foreach (DeploymentType::all() as $type) {
            $types[] = [
                'type' => $type,
                'label' => DeploymentType::label($type),
                'description' => DeploymentType::description($type),
                'requires_blueprint' => DeploymentType::requiresBlueprint($type),
                'requires_active_server' => DeploymentType::requiresActiveServer($type),
            ];
        }

        return Response::success($types);
    }

    /**
     * Start a new deployment
     */
    public function create(Request $request): Response
    {
        $serverId = (int)$request->input('server_id');
        $blueprintId = $request->input('blueprint_id') ? (int)$request->input('blueprint_id') : null;
        $type = $request->input('type', DeploymentType::FULL_PROVISION);

        if (!$serverId) {
            return Response::validationError(['server_id' => 'Server ID is required']);
        }

        // Validate deployment type
        if (!in_array($type, DeploymentType::all())) {
            return Response::validationError(['type' => 'Invalid deployment type']);
        }

        // Check if blueprint is required
        if (DeploymentType::requiresBlueprint($type) && !$blueprintId) {
            return Response::validationError(['blueprint_id' => 'Blueprint is required for this deployment type']);
        }

        $db = $this->getDatabase();

        // Verify server exists
        $stmt = $db->prepare("SELECT id, status FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();

        if (!$server) {
            return Response::notFound('Server not found');
        }

        // Check if server is already being provisioned
        if ($server['status'] === 'provisioning') {
            return Response::error('Server is already being provisioned', 400);
        }

        // Check if server needs to be active for this deployment type
        if (DeploymentType::requiresActiveServer($type) && !in_array($server['status'], ['active', 'maintenance'])) {
            return Response::error('Server must be active or in maintenance mode for this deployment type', 400);
        }

        // Handle different deployment types
        switch ($type) {
            case DeploymentType::FULL_PROVISION:
                return $this->handleFullProvision($serverId, $blueprintId, $request);
                
            case DeploymentType::CONFIG_ONLY:
                return $this->handleConfigOnly($serverId, $blueprintId, $request);
                
            case DeploymentType::PACKAGES_CONFIG:
                return $this->handlePackagesConfig($serverId, $blueprintId, $request);

            case DeploymentType::APP_UPDATE:
                return $this->handleAppUpdate($serverId, $request);
                
            default:
                return $this->handleOtherDeployment($serverId, $blueprintId, $type);
        }
    }

    /**
     * Handle app update deployment (code only, preserve configs)
     */
    private function handleAppUpdate(int $serverId, Request $request): Response
    {
        $apps = $request->input('apps', ['panel', 'email', 'agent']);
        
        if (empty($apps)) {
            return Response::validationError(['apps' => 'At least one app must be selected']);
        }

        // Validate app names
        $validApps = ['panel', 'email', 'agent'];
        foreach ($apps as $app) {
            if (!in_array($app, $validApps)) {
                return Response::validationError(['apps' => "Invalid app: {$app}"]);
            }
        }

        $provisioning = $this->container->get(ProvisioningService::class);
        $result = $provisioning->deployAppUpdate($serverId, $apps);

        if ($result['success']) {
            $this->logAction('deployment.app_update', $serverId, DeploymentType::APP_UPDATE, 'success');
            return Response::success($result, 'App update completed');
        } else {
            return Response::error($result['error'] ?? 'App update failed');
        }
    }

    /**
     * Handle full provision deployment
     */
    private function handleFullProvision(int $serverId, ?int $blueprintId, ?Request $request = null): Response
    {
        $preflightResults = $request ? $request->input('preflight_results') : null;
        $deploymentId = $this->startFullProvision($serverId, $blueprintId, $preflightResults);

        $this->logAction('deployment.start', $serverId, DeploymentType::FULL_PROVISION, 'success');

        return Response::success([
            'deployment_id' => $deploymentId,
            'status' => 'pending',
            'message' => 'Provisioning started in background'
        ], 'Provisioning started');
    }

    /**
     * Create a deployment record and launch full provisioning in the background.
     * Shared by single deploys (handleFullProvision) and batch deploys (batch),
     * so both go through the exact same non-blocking cli/provision.php path.
     *
     * @return int The new deployment ID.
     */
    private function startFullProvision(int $serverId, ?int $blueprintId, $preflightResults = null): int
    {
        // Start provisioning in background via exec (non-blocking)
        $apiPath = dirname(__DIR__, 2);
        $logDir = $apiPath . '/storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/provision_' . $serverId . '.log';
        $cmd = sprintf(
            'nohup setsid php -d memory_limit=512M -d max_execution_time=0 %s/cli/provision.php %d %d > %s 2>&1 &',
            $apiPath,
            $serverId,
            $blueprintId ?? 0,
            $logFile
        );

        // Create deployment record (include preflight results if provided)
        $db = $this->getDatabase();

        $stmt = $db->prepare(
            "INSERT INTO deployments (server_id, blueprint_id, type, status, progress, current_step, preflight_results, preflight_at) 
             VALUES (?, ?, ?, 'pending', 0, 'Initializing...', ?, ?)"
        );
        $stmt->execute([
            $serverId,
            $blueprintId,
            DeploymentType::FULL_PROVISION,
            $preflightResults ? json_encode($preflightResults) : null,
            $preflightResults ? date('Y-m-d H:i:s') : null,
        ]);
        $deploymentId = (int)$db->lastInsertId();

        // Update server status
        $stmt = $db->prepare("UPDATE servers SET status = 'provisioning' WHERE id = ?");
        $stmt->execute([$serverId]);

        // Execute provisioning in background
        exec($cmd);

        return $deploymentId;
    }

    /**
     * Handle config-only deployment
     */
    private function handleConfigOnly(int $serverId, int $blueprintId, Request $request): Response
    {
        $configService = $this->container->get(ConfigDeploymentService::class);
        
        $options = [
            'backup' => $request->input('backup', true),
            'dry_run' => $request->input('dry_run', false),
            'force' => $request->input('force', false),
            'categories' => $request->input('categories'),
        ];

        $result = $configService->deploy($serverId, $blueprintId, $options);

        if ($result['success']) {
            $this->logAction('deployment.config_only', $serverId, DeploymentType::CONFIG_ONLY, 'success');
            return Response::success($result, $result['dry_run'] ?? false ? 'Preview generated' : 'Configuration deployed');
        } else {
            return Response::error($result['error'] ?? 'Config deployment failed');
        }
    }

    /**
     * Handle packages + config deployment
     */
    private function handlePackagesConfig(int $serverId, int $blueprintId, ?Request $request = null): Response
    {
        // Save preflight results if provided
        $preflightResults = $request ? $request->input('preflight_results') : null;
        if ($preflightResults) {
            $db = $this->getDatabase();
            $stmt = $db->prepare(
                "UPDATE deployments SET preflight_results = ?, preflight_at = NOW() 
                 WHERE server_id = ? AND type = ? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([json_encode($preflightResults), $serverId, DeploymentType::PACKAGES_CONFIG]);
        }

        $provisioning = $this->container->get(ProvisioningService::class);
        $result = $provisioning->deployPackagesAndConfig($serverId, $blueprintId);

        if ($result['success']) {
            $this->logAction('deployment.packages_config', $serverId, DeploymentType::PACKAGES_CONFIG, 'success');
            return Response::success($result, 'Packages and configuration deployed');
        } else {
            return Response::error($result['error'] ?? 'Deployment failed');
        }
    }

    /**
     * Handle other deployment types
     */
    private function handleOtherDeployment(int $serverId, ?int $blueprintId, string $type): Response
    {
        $db = $this->getDatabase();
        $user = $this->getCurrentUser();
        
        $stmt = $db->prepare(
            "INSERT INTO deployments (server_id, blueprint_id, type, status, created_by)
             VALUES (?, ?, ?, 'pending', ?)"
        );
        $stmt->execute([$serverId, $blueprintId, $type, $user ? $user->sub : null]);

        $deploymentId = (int)$db->lastInsertId();

        $this->logAction('deployment.create', $serverId, $type, 'success');

        return Response::success(['id' => $deploymentId], 'Deployment created');
    }

    /**
     * Preview a deployment (dry run)
     */
    public function preview(Request $request): Response
    {
        $serverId = (int)$request->input('server_id');
        $blueprintId = (int)$request->input('blueprint_id');
        $type = $request->input('type', DeploymentType::CONFIG_ONLY);

        if (!$serverId || !$blueprintId) {
            return Response::validationError([
                'server_id' => 'Server ID is required',
                'blueprint_id' => 'Blueprint ID is required',
            ]);
        }

        // For config deployments, use ConfigDeploymentService with dry_run
        if ($type === DeploymentType::CONFIG_ONLY) {
            $configService = $this->container->get(ConfigDeploymentService::class);
            
            $result = $configService->deploy($serverId, $blueprintId, [
                'dry_run' => true,
                'categories' => $request->input('categories'),
            ]);

            return $result['success'] 
                ? Response::success($result, 'Preview generated')
                : Response::error($result['error'] ?? 'Preview failed');
        }

        // For other types, return basic info
        return Response::success([
            'dry_run' => true,
            'type' => $type,
            'message' => 'Preview not available for this deployment type',
        ]);
    }

    /**
     * Run preflight checks on a server before full provision.
     * Returns per-check pass/warn/fail results and an overall go/no-go.
     */
    public function preflight(Request $request): Response
    {
        $serverId = (int)$request->input('server_id');
        $blueprintId = $request->input('blueprint_id') ? (int)$request->input('blueprint_id') : null;

        if (!$serverId) {
            return Response::validationError(['server_id' => 'Server ID is required']);
        }

        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        if (!$stmt->fetch()) {
            return Response::notFound('Server not found');
        }

        $preflight = $this->container->get(PreflightService::class);
        $result = $preflight->run($serverId, $blueprintId);

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Preflight check failed');
        }

        $this->logAction('deployment.preflight', $serverId, 'preflight',
            $result['summary']['can_proceed'] ? 'success' : 'warning',
            ['summary' => $result['summary']]);

        return Response::success($result, 'Preflight checks completed');
    }

    /**
     * Run on-demand audit for a server
     * POST /api/servers/{id}/audit
     */
    public function audit(Request $request): Response
    {
        $serverId = (int)$request->getParam('id');

        if (!$serverId) {
            return Response::validationError(['server_id' => 'Server ID is required']);
        }

        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        if (!$stmt->fetch()) {
            return Response::notFound('Server not found');
        }

        $auditService = $this->container->get(AuditService::class);
        $result = $auditService->run($serverId);

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Audit failed');
        }

        $this->logAction('deployment.audit', $serverId, 'audit',
            ($result['audit']['failed'] ?? 0) > 0 ? 'warning' : 'success',
            ['summary' => [
                'passed' => $result['audit']['passed'],
                'failed' => $result['audit']['failed'],
                'warnings' => $result['audit']['warnings'],
            ]]);

        return Response::success($result, 'Audit completed');
    }

    /**
     * Run a single fix action for a failed audit check
     * POST /api/servers/{id}/audit/fix
     */
    public function auditFix(Request $request): Response
    {
        $serverId = (int)$request->getParam('id');
        $action = $request->input('action');
        $params = $request->input('params', []);

        if (!$serverId) {
            return Response::validationError(['server_id' => 'Server ID is required']);
        }
        if (!$action) {
            return Response::validationError(['action' => 'Fix action is required']);
        }

        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT id, name FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        if (!$server) {
            return Response::notFound('Server not found');
        }

        $auditService = $this->container->get(AuditService::class);
        $result = $auditService->fix($serverId, $action, $params);

        $this->logAction('audit.fix', $serverId, $server['name'],
            $result['success'] ? 'success' : 'warning',
            ['action' => $action, 'params' => $params, 'message' => $result['message'] ?? '']);

        if (!$result['success']) {
            return Response::json([
                'success' => false,
                'error' => $result['message'] ?? 'Fix failed',
                'output' => $result['output'] ?? null,
                'details' => $result['details'] ?? null,
            ], 422);
        }

        return Response::success($result, $result['message'] ?? 'Fix applied');
    }

    /**
     * Get diff for a specific config file
     */
    public function diff(Request $request): Response
    {
        $serverId = (int)$request->input('server_id');
        $blueprintId = (int)$request->input('blueprint_id');
        $targetPath = $request->input('target_path');

        if (!$serverId || !$blueprintId || !$targetPath) {
            return Response::validationError([
                'server_id' => 'Server ID is required',
                'blueprint_id' => 'Blueprint ID is required',
                'target_path' => 'Target path is required',
            ]);
        }

        $configService = $this->container->get(ConfigDeploymentService::class);
        $result = $configService->getDiff($serverId, $blueprintId, $targetPath);

        return $result['success']
            ? Response::success($result)
            : Response::error($result['error'] ?? 'Failed to generate diff');
    }

    /**
     * Rollback a deployment
     */
    public function rollback(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        
        $db = $this->getDatabase();
        $stmt = $db->prepare("SELECT type FROM deployments WHERE id = ?");
        $stmt->execute([$id]);
        $deployment = $stmt->fetch();

        if (!$deployment) {
            return Response::notFound('Deployment not found');
        }

        // Only config deployments can be rolled back
        if ($deployment['type'] !== DeploymentType::CONFIG_ONLY) {
            return Response::error('Only config-only deployments can be rolled back', 400);
        }

        $configService = $this->container->get(ConfigDeploymentService::class);
        $result = $configService->rollback($id);

        if ($result['success']) {
            $this->logAction('deployment.rollback', null, $id, 'success');
            return Response::success($result, 'Rollback completed');
        } else {
            return Response::error($result['error'] ?? 'Rollback failed');
        }
    }

    /**
     * Cancel a running deployment (kills the background process if PID is known)
     */
    public function cancel(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM deployments WHERE id = ?");
        $stmt->execute([$id]);
        $deployment = $stmt->fetch();

        if (!$deployment) {
            return Response::notFound('Deployment not found');
        }

        if (!in_array($deployment['status'], ['pending', 'running'])) {
            return Response::error('Deployment cannot be cancelled', 400);
        }

        // Kill the background provisioning process if PID is tracked
        if (!empty($deployment['pid'])) {
            $pid = (int)$deployment['pid'];
            // SIGTERM first, then SIGKILL after a short grace period
            @exec("kill -TERM {$pid} 2>/dev/null");
            usleep(500000);
            @exec("kill -KILL {$pid} 2>/dev/null");
        }

        $stmt = $db->prepare("UPDATE deployments SET status = 'cancelled', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);

        // Mark pending/running steps as skipped
        $stmt = $db->prepare(
            "UPDATE deployment_steps SET status = 'skipped', completed_at = NOW() WHERE deployment_id = ? AND status IN ('pending','running')"
        );
        $stmt->execute([$id]);

        // Update server status
        $stmt = $db->prepare("UPDATE servers SET status = 'pending' WHERE id = ? AND status = 'provisioning'");
        $stmt->execute([$deployment['server_id']]);

        $this->logAction('deployment.cancel', $deployment['server_id'], $deployment['type'], 'success');

        return Response::success(null, 'Deployment cancelled');
    }

    /**
     * Test SSH connection to a server
     */
    public function testConnection(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['ip_address', 'ssh_password']);
        if ($validation) return $validation;

        $ssh = $this->container->get(SSHService::class);

        $result = $ssh->testConnection(
            $request->input('ip_address'),
            (int)$request->input('ssh_port', 22),
            $request->input('ssh_user', 'root'),
            $request->input('ssh_password')
        );

        if ($result['success']) {
            return Response::success($result, 'Connection successful');
        }

        return Response::error($result['error'] ?? 'Connection failed');
    }

    /**
     * Get deployment logs (for streaming) -- enhanced with heartbeat + step info
     */
    public function logs(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $offset = (int)$request->getQuery('offset', 0);
        $db = $this->getDatabase();

        $stmt = $db->prepare(
            "SELECT log, status, progress, current_step, pid, last_heartbeat, 
                    failed_step, steps_completed, steps_total, resumed_from_step, audit_results,
                    started_at, completed_at
             FROM deployments WHERE id = ?"
        );
        $stmt->execute([$id]);
        $deployment = $stmt->fetch();

        if (!$deployment) {
            return Response::notFound('Deployment not found');
        }

        $log = $deployment['log'] ?? '';
        $newContent = substr($log, $offset);

        // Calculate heartbeat staleness
        $heartbeatAge = null;
        if ($deployment['last_heartbeat']) {
            $heartbeatAge = time() - strtotime($deployment['last_heartbeat']);
        }

        // Elapsed time computed server-side (avoids client clock skew). While the
        // deploy is in flight this is "now - started_at"; once finished it's the
        // frozen total "completed_at - started_at".
        $elapsedSeconds = null;
        if (!empty($deployment['started_at'])) {
            $endTs = !empty($deployment['completed_at'])
                ? strtotime($deployment['completed_at'])
                : time();
            $elapsedSeconds = max(0, $endTs - strtotime($deployment['started_at']));
        }

        $auditResults = null;
        if (!empty($deployment['audit_results']) && is_string($deployment['audit_results'])) {
            $auditResults = json_decode($deployment['audit_results'], true);
        }

        return Response::success([
            'content' => $newContent,
            'offset' => strlen($log),
            'status' => $deployment['status'],
            'progress' => (int)$deployment['progress'],
            'current_step' => $deployment['current_step'],
            'pid' => $deployment['pid'] ? (int)$deployment['pid'] : null,
            'last_heartbeat' => $deployment['last_heartbeat'],
            'heartbeat_age_seconds' => $heartbeatAge,
            'failed_step' => $deployment['failed_step'],
            'steps_completed' => (int)($deployment['steps_completed'] ?? 0),
            'steps_total' => (int)($deployment['steps_total'] ?? 0),
            'resumed_from_step' => $deployment['resumed_from_step'],
            'audit_results' => $auditResults,
            'started_at' => $deployment['started_at'],
            'completed_at' => $deployment['completed_at'],
            'elapsed_seconds' => $elapsedSeconds,
        ]);
    }

    /**
     * Get backups for a deployment
     */
    public function backups(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare(
            "SELECT id, target_path, created_at FROM config_backups WHERE deployment_id = ? ORDER BY created_at"
        );
        $stmt->execute([$id]);
        $backups = $stmt->fetchAll();

        return Response::success($backups);
    }

    /**
     * Batch deployment - deploy to multiple servers at once
     */
    public function batch(Request $request): Response
    {
        $serverIds = $request->input('server_ids', []);
        $type = $request->input('type', DeploymentType::APP_UPDATE);
        $apps = $request->input('apps', ['panel', 'email', 'agent']);

        if (empty($serverIds)) {
            return Response::validationError(['server_ids' => 'At least one server must be selected']);
        }

        // Batch supports app updates (live servers) and full provisioning (fresh servers).
        if (!in_array($type, [DeploymentType::APP_UPDATE, DeploymentType::FULL_PROVISION], true)) {
            return Response::validationError(['type' => 'Only app_update and full_provision are supported for batch deployment']);
        }

        $db = $this->getDatabase();

        // Verify all servers exist
        $placeholders = implode(',', array_fill(0, count($serverIds), '?'));
        $stmt = $db->prepare(
            "SELECT id, name, status, blueprint_id FROM servers WHERE id IN ({$placeholders})"
        );
        $stmt->execute($serverIds);
        $servers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (count($servers) !== count($serverIds)) {
            return Response::validationError(['server_ids' => 'One or more servers not found']);
        }

        // --- Full provision: launch one background deploy per server, each using
        //     its own assigned blueprint. Used to provision a batch of fresh boxes. ---
        if ($type === DeploymentType::FULL_PROVISION) {
            $results = [];
            foreach ($servers as $server) {
                $sid = (int) $server['id'];
                if ($server['status'] === 'provisioning') {
                    $results[$sid] = ['success' => false, 'error' => 'Already provisioning'];
                    continue;
                }
                $bpId = $server['blueprint_id'] ? (int) $server['blueprint_id'] : null;
                if (!$bpId) {
                    $results[$sid] = ['success' => false, 'error' => 'No blueprint assigned'];
                    continue;
                }
                try {
                    $deploymentId = $this->startFullProvision($sid, $bpId);
                    $results[$sid] = ['success' => true, 'deployment_id' => $deploymentId];
                } catch (\Throwable $e) {
                    $results[$sid] = ['success' => false, 'error' => $e->getMessage()];
                }
            }

            $this->logAction('deployment.batch', null, DeploymentType::FULL_PROVISION, 'success');

            return Response::success([
                'success'  => true,
                'type'     => DeploymentType::FULL_PROVISION,
                'results'  => $results,
                'summary'  => [
                    'total'      => count($serverIds),
                    'successful' => count(array_filter($results, fn($r) => $r['success'])),
                    'failed'     => count(array_filter($results, fn($r) => !$r['success'])),
                ],
            ], 'Batch provisioning started in background');
        }

        // --- App update: requires every server to be active/maintenance. ---
        $inactive = array_filter($servers, fn($s) => !in_array($s['status'], ['active', 'maintenance']));
        if (!empty($inactive)) {
            $names = array_column($inactive, 'name');
            return Response::error('Some servers are not active (App Update only runs on live servers): ' . implode(', ', $names), 400);
        }

        // Run batch deployment
        $provisioning = $this->container->get(ProvisioningService::class);
        $result = $provisioning->deployAppUpdateBatch($serverIds, $apps);

        $this->logAction('deployment.batch', null, DeploymentType::APP_UPDATE, 'success');

        return Response::success($result, 'Batch deployment completed');
    }

    /**
     * Get updatable apps info
     */
    public function apps(Request $request): Response
    {
        return Response::success(DeploymentType::getUpdatableApps());
    }

    /**
     * Get all step records for a deployment (for the step timeline UI)
     */
    public function steps(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare(
            "SELECT step_key, step_name, step_order, weight, status, started_at, completed_at,
                    duration_ms, error_message, error_type, retry_count, max_retries, can_skip, idempotent
             FROM deployment_steps
             WHERE deployment_id = ?
             ORDER BY step_order"
        );
        $stmt->execute([$id]);
        $steps = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return Response::success($steps);
    }

    /**
     * Get the command log for a specific step (for debugging failed steps)
     */
    public function stepLog(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $stepKey = $request->getParam('stepKey');
        $db = $this->getDatabase();

        $stmt = $db->prepare(
            "SELECT step_key, step_name, status, command_log, error_message, error_type, duration_ms, retry_count
             FROM deployment_steps
             WHERE deployment_id = ? AND step_key = ?"
        );
        $stmt->execute([$id, $stepKey]);
        $step = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$step) {
            return Response::notFound('Step not found');
        }

        return Response::success($step);
    }

    /**
     * Resume a failed deployment from the failed step (or skip it and continue)
     */
    public function resume(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $skipFailed = (bool)$request->input('skip_failed', false);
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM deployments WHERE id = ?");
        $stmt->execute([$id]);
        $deployment = $stmt->fetch();

        if (!$deployment) {
            return Response::notFound('Deployment not found');
        }

        if ($deployment['status'] !== 'failed') {
            return Response::error('Only failed deployments can be resumed', 400);
        }

        $serverId = (int)$deployment['server_id'];
        $blueprintId = $deployment['blueprint_id'] ? (int)$deployment['blueprint_id'] : null;

        // Launch the resume in background (same pattern as handleFullProvision)
        $apiPath = dirname(__DIR__, 2);
        $logDir = $apiPath . '/storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/provision_' . $serverId . '_resume.log';

        $skipFlag = $skipFailed ? ' --skip-failed' : '';
        $cmd = sprintf(
            'nohup setsid php -d memory_limit=512M -d max_execution_time=0 %s/cli/provision.php %d %d --resume=%d%s > %s 2>&1 &',
            $apiPath,
            $serverId,
            $blueprintId ?? 0,
            $id,
            $skipFlag,
            $logFile
        );

        // Update server status
        $stmt = $db->prepare("UPDATE servers SET status = 'provisioning' WHERE id = ?");
        $stmt->execute([$serverId]);

        exec($cmd);

        $this->logAction('deployment.resume', $serverId, DeploymentType::FULL_PROVISION, 'success');

        return Response::success([
            'deployment_id' => $id,
            'status' => 'running',
            'skip_failed' => $skipFailed,
            'message' => 'Deployment resumed in background',
        ], 'Deployment resumed');
    }
}
