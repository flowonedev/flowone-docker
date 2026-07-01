<?php
/**
 * Fleet Manager API Routes
 */

use FleetManager\Api\Controllers\AuthController;
use FleetManager\Api\Controllers\DashboardController;
use FleetManager\Api\Controllers\ServerController;
use FleetManager\Api\Controllers\BlueprintController;
use FleetManager\Api\Controllers\DeploymentController;
use FleetManager\Api\Controllers\AgentController;
use FleetManager\Api\Controllers\TwoFactorController;
use FleetManager\Api\Controllers\SessionController;
use FleetManager\Api\Controllers\SystemController;
use FleetManager\Api\Controllers\AIHelperController;
use FleetManager\Api\Controllers\PackageController;
use FleetManager\Api\Controllers\SettingsController;

// Public routes
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/auth/2fa/verify', [AuthController::class, 'verify2FA']);

// System routes (health check is public)
$router->get('/api/system/health', [SystemController::class, 'health']);

// Agent routes (authenticated by X-Agent-Token header)
$router->group(['middleware' => 'agent'], function($router) {
    $router->post('/api/agent/heartbeat', [AgentController::class, 'heartbeat']);
    $router->post('/api/agent/errors', [AgentController::class, 'reportErrors']);
    $router->post('/api/agent/progress', [AgentController::class, 'reportProgress']);
    $router->get('/api/agent/config', [AgentController::class, 'getConfig']);
    
    // Task status updates from agent
    $router->post('/api/agent/task/{id}/start', [AgentController::class, 'taskStart']);
    $router->post('/api/agent/task/{id}/progress', [AgentController::class, 'taskProgress']);
    $router->post('/api/agent/task/{id}/complete', [AgentController::class, 'taskComplete']);
    $router->post('/api/agent/task/{id}/fail', [AgentController::class, 'taskFail']);
});

// Protected routes (require JWT authentication)
$router->group(['middleware' => 'auth'], function($router) {
    
    // Auth
    $router->get('/api/auth/me', [AuthController::class, 'me']);
    $router->post('/api/auth/logout', [AuthController::class, 'logout']);
    $router->post('/api/auth/password', [AuthController::class, 'changePassword']);

    // Two-Factor Authentication
    $router->get('/api/2fa/status', [TwoFactorController::class, 'status']);
    $router->post('/api/2fa/setup', [TwoFactorController::class, 'setup']);
    $router->post('/api/2fa/verify-setup', [TwoFactorController::class, 'verifySetup']);
    $router->post('/api/2fa/disable', [TwoFactorController::class, 'disable']);
    $router->post('/api/2fa/backup-codes', [TwoFactorController::class, 'regenerateBackupCodes']);
    $router->get('/api/2fa/trusted-devices', [TwoFactorController::class, 'getTrustedDevices']);
    $router->delete('/api/2fa/trusted-devices/{id}', [TwoFactorController::class, 'revokeTrustedDevice']);
    $router->delete('/api/2fa/trusted-devices', [TwoFactorController::class, 'revokeAllTrustedDevices']);

    // Session Management
    $router->get('/api/sessions', [SessionController::class, 'index']);
    $router->delete('/api/sessions/{id}', [SessionController::class, 'revoke']);
    $router->post('/api/sessions/revoke-others', [SessionController::class, 'revokeOthers']);
    $router->post('/api/sessions/revoke-all', [SessionController::class, 'revokeAll']);

    // Dashboard
    $router->get('/api/dashboard', [DashboardController::class, 'index']);

    // System (migrations, self-check, snapshots)
    $router->get('/api/system/migrations', [SystemController::class, 'migrations']);
    $router->post('/api/system/migrations/run', [SystemController::class, 'runMigrations']);
    $router->get('/api/system/self-check', [SystemController::class, 'selfCheck']);
    $router->post('/api/system/bootstrap', [SystemController::class, 'bootstrap']);
    $router->get('/api/system/snapshots', [SystemController::class, 'listSnapshots']);
    $router->post('/api/system/snapshots', [SystemController::class, 'takeSnapshot']);
    $router->get('/api/system/snapshots/{id}', [SystemController::class, 'getSnapshot']);
    $router->delete('/api/system/snapshots/{id}', [SystemController::class, 'deleteSnapshot']);
    $router->post('/api/system/snapshots/{id}/create-blueprint', [SystemController::class, 'createBlueprintFromSnapshot']);
    $router->post('/api/system/snapshots/{id}/preview-templates', [SystemController::class, 'previewTemplatesFromSnapshot']);
    $router->post('/api/system/snapshots/{id}/regenerate-blueprint', [SystemController::class, 'regenerateBlueprintFromSnapshot']);

    // Servers
    $router->get('/api/servers', [ServerController::class, 'index']);
    $router->get('/api/servers/stats', [ServerController::class, 'stats']);
    $router->get('/api/servers/{id}', [ServerController::class, 'show']);
    $router->post('/api/servers', [ServerController::class, 'create']);
    $router->put('/api/servers/{id}', [ServerController::class, 'update']);
    $router->delete('/api/servers/{id}', [ServerController::class, 'delete']);
    $router->post('/api/servers/{id}/regenerate-token', [ServerController::class, 'regenerateToken']);
    $router->post('/api/servers/{id}/test-connection', [ServerController::class, 'testConnection']);
    $router->post('/api/servers/{id}/authorized-key', [ServerController::class, 'updateAuthorizedKey']);
    $router->post('/api/servers/{id}/reset-status', [ServerController::class, 'resetStatus']);
    $router->post('/api/servers/{id}/wipe', [ServerController::class, 'wipe']);

    // Server Tasks
    $router->get('/api/servers/{id}/tasks', [ServerController::class, 'getTasks']);
    $router->get('/api/servers/{id}/tasks/{taskId}', [ServerController::class, 'getTask']);
    $router->post('/api/servers/{id}/tasks', [ServerController::class, 'createTask']);
    $router->post('/api/servers/{id}/tasks/{taskId}/cancel', [ServerController::class, 'cancelTask']);
    $router->post('/api/servers/{id}/run-command', [ServerController::class, 'runCommand']);
    $router->post('/api/servers/{id}/sync-files', [ServerController::class, 'syncFiles']);
    $router->post('/api/servers/{id}/restart-service', [ServerController::class, 'restartService']);
    $router->get('/api/servers/{id}/updates', [ServerController::class, 'getUpdates']);
    $router->post('/api/servers/{id}/updates/apply', [ServerController::class, 'applyUpdates']);

    // Server Credentials & Audit
    $router->get('/api/servers/{id}/credentials', [ServerController::class, 'getCredentials']);
    $router->get('/api/servers/{id}/provision-log', [ServerController::class, 'getProvisionLog']);
    $router->get('/api/servers/{id}/dns', [ServerController::class, 'getDns']);
    $router->post('/api/servers/{id}/dns/reseed', [ServerController::class, 'reseedDns']);
    $router->get('/api/servers/{id}/audit', [ServerController::class, 'getAudit']);
    $router->post('/api/servers/{id}/audit', [DeploymentController::class, 'audit']);
    $router->post('/api/servers/{id}/audit/fix', [DeploymentController::class, 'auditFix']);

    // CPGuard (per-server license, installed on demand or during provisioning)
    $router->get('/api/servers/{id}/cpguard', [ServerController::class, 'cpguardStatus']);
    $router->post('/api/servers/{id}/cpguard/install', [ServerController::class, 'installCpguard']);

    // Live Docker container health for Docker-provisioned servers
    $router->get('/api/servers/{id}/docker-status', [ServerController::class, 'dockerStatus']);

    // Server Reports & Issues
    $router->get('/api/servers/{id}/reports', [ServerController::class, 'listReports']);
    $router->get('/api/servers/{id}/reports/download', [ServerController::class, 'downloadReport']);
    $router->post('/api/servers/{id}/reports/generate', [ServerController::class, 'generateReport']);
    $router->get('/api/servers/{id}/issues', [ServerController::class, 'listIssues']);
    $router->get('/api/servers/{id}/issue-logs', [ServerController::class, 'listIssueLogs']);
    $router->get('/api/servers/{id}/issue-logs/{date}', [ServerController::class, 'getIssueLog']);

    // Blueprints - static routes MUST come before {id} routes
    $router->get('/api/blueprints', [BlueprintController::class, 'index']);
    $router->get('/api/blueprints/categories', [BlueprintController::class, 'getCategories']);
    $router->get('/api/blueprints/package-categories', [BlueprintController::class, 'getPackageCategories']);
    $router->post('/api/blueprints/test-connection', [BlueprintController::class, 'testConnection']);
    $router->post('/api/blueprints/extract', [BlueprintController::class, 'extract']);
    $router->post('/api/blueprints/detect-variables', [BlueprintController::class, 'detectVariablesFromExtraction']);
    $router->post('/api/blueprints/create-from-extraction', [BlueprintController::class, 'createFromExtraction']);
    
    // Blueprints - dynamic {id} routes
    $router->get('/api/blueprints/{id}', [BlueprintController::class, 'show']);
    $router->post('/api/blueprints', [BlueprintController::class, 'create']);
    $router->put('/api/blueprints/{id}', [BlueprintController::class, 'update']);
    $router->delete('/api/blueprints/{id}', [BlueprintController::class, 'delete']);
    $router->post('/api/blueprints/{id}/duplicate', [BlueprintController::class, 'duplicate']);
    
    // Blueprint templates
    $router->get('/api/blueprints/{id}/templates/{templateId}', [BlueprintController::class, 'getTemplate']);
    $router->post('/api/blueprints/{id}/templates', [BlueprintController::class, 'saveTemplate']);
    $router->delete('/api/blueprints/{id}/templates/{templateId}', [BlueprintController::class, 'deleteTemplate']);

    // Blueprint packages (dynamic {id} routes)
    $router->get('/api/blueprints/{id}/packages', [BlueprintController::class, 'getPackages']);
    $router->post('/api/blueprints/{id}/packages', [BlueprintController::class, 'savePackages']);
    $router->post('/api/blueprints/{id}/packages/add', [BlueprintController::class, 'addPackage']);
    $router->post('/api/blueprints/{id}/packages/import-defaults', [BlueprintController::class, 'importDefaultPackages']);
    $router->put('/api/blueprints/{id}/packages/{packageId}', [BlueprintController::class, 'updatePackage']);
    $router->delete('/api/blueprints/{id}/packages/{packageId}', [BlueprintController::class, 'deletePackage']);

    // Deployments
    $router->get('/api/deployments', [DeploymentController::class, 'index']);
    $router->get('/api/deployments/types', [DeploymentController::class, 'types']);
    $router->get('/api/deployments/apps', [DeploymentController::class, 'apps']);
    $router->get('/api/deployments/{id}', [DeploymentController::class, 'show']);
    $router->get('/api/deployments/{id}/logs', [DeploymentController::class, 'logs']);
    $router->get('/api/deployments/{id}/backups', [DeploymentController::class, 'backups']);
    $router->get('/api/deployments/{id}/steps', [DeploymentController::class, 'steps']);
    $router->get('/api/deployments/{id}/steps/{stepKey}/log', [DeploymentController::class, 'stepLog']);
    $router->post('/api/deployments', [DeploymentController::class, 'create']);
    $router->post('/api/deployments/batch', [DeploymentController::class, 'batch']);
    $router->post('/api/deployments/preview', [DeploymentController::class, 'preview']);
    $router->post('/api/deployments/preflight', [DeploymentController::class, 'preflight']);
    $router->post('/api/deployments/diff', [DeploymentController::class, 'diff']);
    $router->post('/api/deployments/{id}/cancel', [DeploymentController::class, 'cancel']);
    $router->post('/api/deployments/{id}/resume', [DeploymentController::class, 'resume']);
    $router->post('/api/deployments/{id}/rollback', [DeploymentController::class, 'rollback']);
    $router->post('/api/deployments/test-connection', [DeploymentController::class, 'testConnection']);

    // Fleet-wide settings (SSH management key, etc.)
    $router->get('/api/settings/ssh', [SettingsController::class, 'getSshKey']);
    $router->put('/api/settings/ssh', [SettingsController::class, 'updateSshKey']);
    $router->put('/api/settings/ssh-defaults', [SettingsController::class, 'updateSshDefaults']);

    // AI Helper
    $router->get('/api/ai-helper/settings', [AIHelperController::class, 'getSettings']);
    $router->put('/api/ai-helper/settings', [AIHelperController::class, 'updateSettings']);
    $router->get('/api/ai-helper/conversations', [AIHelperController::class, 'listConversations']);
    $router->post('/api/ai-helper/conversations', [AIHelperController::class, 'createConversation']);
    $router->get('/api/ai-helper/conversations/{id}', [AIHelperController::class, 'getConversation']);
    $router->delete('/api/ai-helper/conversations/{id}', [AIHelperController::class, 'deleteConversation']);
    $router->post('/api/ai-helper/conversations/{id}/messages', [AIHelperController::class, 'sendMessage']);
    $router->post('/api/ai-helper/analyze-logs', [AIHelperController::class, 'analyzeLogs']);
    $router->post('/api/ai-helper/analyze-config', [AIHelperController::class, 'analyzeConfig']);
    $router->get('/api/ai-helper/cached-issues', [AIHelperController::class, 'getCachedIssues']);
    $router->post('/api/ai-helper/cached-issues/{id}/resolve', [AIHelperController::class, 'resolveIssue']);

    // Packages - Deployment package management
    $router->get('/api/packages', [PackageController::class, 'index']);
    $router->get('/api/packages/{type}', [PackageController::class, 'listVersions']);
    $router->post('/api/packages/{type}/upload', [PackageController::class, 'upload']);
    $router->post('/api/packages/{type}/build', [PackageController::class, 'build']);
    $router->get('/api/packages/{type}/{version}', [PackageController::class, 'show']);
    $router->post('/api/packages/{type}/{version}/set-latest', [PackageController::class, 'setLatest']);
    $router->delete('/api/packages/{type}/{version}', [PackageController::class, 'delete']);
    $router->get('/api/packages/{type}/{version}/download', [PackageController::class, 'download']);
});

