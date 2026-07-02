<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\MigrationService;
use FleetManager\Api\Services\SelfCheckService;
use FleetManager\Api\Services\SnapshotService;

class SystemController extends BaseController
{
    /**
     * Get migration status
     * GET /api/system/migrations
     */
    public function migrations(Request $request): Response
    {
        $migrationsPath = __DIR__ . '/../../../database/migrations';
        $migrationService = new MigrationService(
            $this->getDatabase(), 
            $migrationsPath
        );

        return Response::success($migrationService->getStatus());
    }

    /**
     * Run pending migrations manually
     * POST /api/system/migrations/run
     */
    public function runMigrations(Request $request): Response
    {
        $migrationsPath = __DIR__ . '/../../../database/migrations';
        $migrationService = new MigrationService(
            $this->getDatabase(), 
            $migrationsPath
        );

        $results = $migrationService->runPendingMigrations();

        if (!empty($results['errors'])) {
            return Response::error('Some migrations failed', 500, [
                'results' => $results,
            ]);
        }

        return Response::success($results, 'Migrations completed');
    }

    /**
     * System health check
     * GET /api/system/health
     */
    public function health(Request $request): Response
    {
        $db = $this->getDatabase();
        $dbOk = false;
        
        try {
            $db->query("SELECT 1");
            $dbOk = true;
        } catch (\Exception $e) {
            // Database not available
        }

        return Response::success([
            'status' => $dbOk ? 'healthy' : 'unhealthy',
            'database' => $dbOk ? 'connected' : 'disconnected',
            'php_version' => PHP_VERSION,
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Full self-check: PHP, DB, agent, filesystem, config, templates, packages
     * GET /api/system/self-check
     */
    public function selfCheck(Request $request): Response
    {
        $service = $this->container->get(SelfCheckService::class);
        $report = $service->runAll();

        return Response::success($report);
    }

    /**
     * Bootstrap: create dirs, run migrations, generate token
     * POST /api/system/bootstrap
     */
    public function bootstrap(Request $request): Response
    {
        $service = $this->container->get(SelfCheckService::class);
        $result = $service->bootstrap();

        $this->logAction('system.bootstrap', null, null, 'success', $result);

        return Response::success($result, 'Bootstrap completed');
    }

    /**
     * Refresh THIS Fleet Manager from git: runs master-update.sh in the
     * background (git pull + sync fleet/email files + rebuild panel/shared/
     * agent packages + restart PHP). The web user is granted exactly this one
     * command via /etc/sudoers.d/flowone-fleet-refresh, which master-update.sh
     * installs on its first (manual) run.
     *
     * POST /api/system/refresh
     */
    public function refresh(Request $request): Response
    {
        $logFile = $this->refreshLogFile();
        $lockFile = dirname($logFile) . '/master-update.lock';

        // Refuse to double-run: the script restarts PHP at the end, two
        // overlapping runs would corrupt the package staging dirs.
        if (file_exists($lockFile)) {
            $age = time() - (int) filemtime($lockFile);
            if ($age < 900) {
                return Response::error('A refresh is already running (started ' . $age . 's ago)', 409);
            }
            @unlink($lockFile); // stale lock (>15 min) - a crashed run
        }

        if (!file_exists('/usr/local/bin/flowone-master-update')) {
            return Response::error(
                'Refresh wrapper not installed. Run once on the master as root: '
                . 'bash /opt/flowone-repo/fleet/master-update.sh (it self-installs the wrapper + sudoers entry)',
                412
            );
        }

        @mkdir(dirname($logFile), 0755, true);
        @touch($lockFile);

        // nohup + setsid so the run SURVIVES the PHP restart the script performs.
        // sudo -n: fail immediately instead of hanging on a password prompt if
        // the sudoers entry is missing.
        $cmd = sprintf(
            'nohup setsid bash -c %s > %s 2>&1 &',
            escapeshellarg('sudo -n /usr/local/bin/flowone-master-update; rc=$?; rm -f ' . $lockFile . '; echo "REFRESH_EXIT_CODE:$rc"'),
            escapeshellarg($logFile)
        );
        exec($cmd);

        $this->logAction('system.refresh', null, null, 'success');

        return Response::success([
            'status' => 'started',
            'log_file' => basename($logFile),
        ], 'Fleet Manager refresh started - packages rebuild in the background');
    }

    /**
     * Poll the state of the last refresh: running/success/failed + log tail.
     * GET /api/system/refresh/status
     */
    public function refreshStatus(Request $request): Response
    {
        $logFile = $this->refreshLogFile();
        $lockFile = dirname($logFile) . '/master-update.lock';

        if (!file_exists($logFile)) {
            return Response::success(['status' => 'never_run', 'log' => '']);
        }

        $log = (string) file_get_contents($logFile);
        // Strip ANSI colour codes from the shell script's output.
        $log = preg_replace('/\x1b\[[0-9;]*m/', '', $log);

        $status = 'running';
        if (preg_match('/REFRESH_EXIT_CODE:(\d+)/', $log, $m)) {
            $status = ((int) $m[1]) === 0 ? 'success' : 'failed';
        } elseif (!file_exists($lockFile) || (time() - (int) filemtime($lockFile)) > 900) {
            $status = 'failed'; // no exit marker and no live lock - crashed
        }

        return Response::success([
            'status' => $status,
            'finished_at' => $status !== 'running' ? date('c', (int) filemtime($logFile)) : null,
            'log' => substr($log, -8000),
        ]);
    }

    private function refreshLogFile(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs/master-update.log';
    }

    /**
     * Take a server snapshot (reads all configs from local server via agent)
     * POST /api/system/snapshots
     */
    public function takeSnapshot(Request $request): Response
    {
        $service = $this->container->get(SnapshotService::class);
        
        $options = [
            'mode' => $request->input('mode', 'full_clone'),
            'categories' => $request->input('categories'),
            'label' => $request->input('label', ''),
        ];

        $result = $service->take($options);

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Snapshot failed', 500);
        }

        $this->logAction('system.snapshot', null, $result['snapshot_id'] ?? null, 'success');

        return Response::success($result, 'Snapshot taken');
    }

    /**
     * List available snapshots
     * GET /api/system/snapshots
     */
    public function listSnapshots(Request $request): Response
    {
        $service = $this->container->get(SnapshotService::class);
        return Response::success($service->list());
    }

    /**
     * Get a single snapshot by ID
     * GET /api/system/snapshots/{id}
     */
    public function getSnapshot(Request $request): Response
    {
        $id = $request->getParam('id');
        $service = $this->container->get(SnapshotService::class);
        $snapshot = $service->get($id);

        if (!$snapshot) {
            return Response::notFound('Snapshot not found');
        }

        return Response::success($snapshot);
    }

    /**
     * Delete a snapshot
     * DELETE /api/system/snapshots/{id}
     */
    public function deleteSnapshot(Request $request): Response
    {
        $id = $request->getParam('id');
        $service = $this->container->get(SnapshotService::class);
        $result = $service->delete($id);

        if (!$result) {
            return Response::notFound('Snapshot not found');
        }

        $this->logAction('system.snapshot.delete', null, $id, 'success');
        return Response::success(null, 'Snapshot deleted');
    }

    /**
     * Create a blueprint from a stored snapshot
     * POST /api/system/snapshots/{id}/create-blueprint
     * 
     * Templates are DYNAMICALLY generated from the actual server configs
     * in the snapshot. Server-specific values are detected and replaced
     * with {{VARIABLE}} placeholders automatically.
     */
    public function createBlueprintFromSnapshot(Request $request): Response
    {
        $snapshotId = $request->getParam('id');
        $service = $this->container->get(SnapshotService::class);
        $snapshot = $service->get($snapshotId);

        if (!$snapshot) {
            return Response::notFound('Snapshot not found');
        }

        $name = $request->input('name');
        $description = $request->input('description', '');
        $selectedCategories = $request->input('categories');

        if (!$name) {
            return Response::validationError(['name' => 'Blueprint name is required']);
        }

        $result = $service->createBlueprintFromSnapshot($snapshotId, $name, $description, $selectedCategories);

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Blueprint creation failed', 500);
        }

        $this->logAction('blueprint.create_from_snapshot', null, $name, 'success');

        return Response::success($result, 'Blueprint created from snapshot');
    }

    /**
     * Preview templates that would be generated from a snapshot
     * POST /api/system/snapshots/{id}/preview-templates
     * 
     * Returns the templates that WOULD be generated without saving anything.
     * Useful for reviewing detected variables and template content before
     * creating a blueprint.
     */
    public function previewTemplatesFromSnapshot(Request $request): Response
    {
        $snapshotId = $request->getParam('id');
        $service = $this->container->get(SnapshotService::class);
        $selectedCategories = $request->input('categories');

        $result = $service->previewTemplatesFromSnapshot($snapshotId, $selectedCategories);

        if (isset($result['success']) && !$result['success']) {
            return Response::error($result['error'] ?? 'Preview failed', 500);
        }

        return Response::success($result);
    }

    /**
     * Regenerate blueprint templates from a new snapshot
     * POST /api/system/snapshots/{id}/regenerate-blueprint
     * 
     * Takes a snapshot ID and an existing blueprint ID.
     * Regenerates all templates in the blueprint from the snapshot's
     * real server configs.
     */
    public function regenerateBlueprintFromSnapshot(Request $request): Response
    {
        $snapshotId = $request->getParam('id');
        $blueprintId = (int) $request->input('blueprint_id');

        if (!$blueprintId) {
            return Response::validationError(['blueprint_id' => 'Blueprint ID is required']);
        }

        $snapshotService = $this->container->get(SnapshotService::class);
        $snapshot = $snapshotService->get($snapshotId);

        if (!$snapshot) {
            return Response::notFound('Snapshot not found');
        }

        $templateGenerator = $this->container->get(\FleetManager\Api\Services\TemplateGeneratorService::class);
        $result = $templateGenerator->regenerateBlueprint($blueprintId, $snapshot);

        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Regeneration failed', 500);
        }

        $this->logAction('blueprint.regenerate', null, $blueprintId, 'success');

        return Response::success($result, 'Blueprint regenerated from snapshot');
    }
}

