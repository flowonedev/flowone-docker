<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;
use FleetManager\Api\Enums\DeploymentType;

/**
 * Config Deployment Service - Handles config-only deployments
 * 
 * This service deploys configuration templates to existing servers
 * without installing packages or deploying applications.
 */
class ConfigDeploymentService
{
    private Container $container;
    private SSHService $ssh;
    private TemplateService $templates;
    private EncryptionService $encryption;
    private \PDO $db;
    private array $deploymentLog = [];

    // Steps for config-only deployment
    private const STEPS = [
        'connect' => ['name' => 'Connecting to server', 'weight' => 10],
        'backup' => ['name' => 'Backing up current configs', 'weight' => 15],
        'deploy_configs' => ['name' => 'Deploying configurations', 'weight' => 50],
        'restart_services' => ['name' => 'Restarting affected services', 'weight' => 20],
        'verify' => ['name' => 'Verifying configuration', 'weight' => 5],
    ];

    // Map config paths to services
    private const SERVICE_MAP = [
        '/etc/postfix/' => 'postfix',
        '/etc/dovecot/' => 'dovecot',
        '/usr/local/lsws/' => 'lshttpd',
        '/etc/fail2ban/' => 'fail2ban',
        '/etc/firewalld/' => 'firewalld',
        '/etc/openvpn/' => 'openvpn',
        '/etc/mysql/' => 'mariadb',
        '/etc/php/' => 'lshttpd',
        '/etc/redis/' => 'redis-server',
        '/etc/opendkim' => 'opendkim',
        '/etc/opendmarc' => 'opendmarc',
        '/etc/spamassassin/' => 'spamassassin',
        '/etc/meilisearch' => 'meilisearch',
        '/var/www/vps-admin/' => null, // No service restart needed
        '/var/www/vps-email/' => null, // No service restart needed
        '/opt/fleet-agent/' => 'fleet-agent',
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->ssh = $container->get(SSHService::class);
        $this->templates = $container->get(TemplateService::class);
        $this->encryption = $container->get(EncryptionService::class);
        $this->db = $container->getDatabase();
    }

    /**
     * Deploy configurations only to a server
     * 
     * @param int $serverId Target server ID
     * @param int $blueprintId Blueprint with templates to deploy
     * @param array $options Optional settings:
     *   - backup: bool (default: true) - Backup existing configs before overwriting
     *   - dry_run: bool (default: false) - Preview changes without applying
     *   - force: bool (default: false) - Apply even if unchanged
     *   - categories: array|null - Only deploy specific categories
     * @return array Result with success status and details
     */
    public function deploy(int $serverId, int $blueprintId, array $options = []): array
    {
        $backup = $options['backup'] ?? true;
        $dryRun = $options['dry_run'] ?? false;
        $force = $options['force'] ?? false;
        $categories = $options['categories'] ?? null;

        // Get server details
        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();

        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        // Verify server is active (has agent running)
        if (!in_array($server['status'], ['active', 'maintenance'])) {
            return [
                'success' => false,
                'error' => 'Server must be active or in maintenance mode for config deployment'
            ];
        }

        // Create deployment record (unless dry run)
        $deploymentId = null;
        if (!$dryRun) {
            $deploymentId = $this->createDeployment($serverId, $blueprintId);
            $this->updateServerStatus($serverId, 'maintenance', 'Config deployment in progress');
        }

        // Generate variables
        $variables = $this->templates->generateServerVariables($server);

        try {
            // Connect to server
            if (!$dryRun) {
                $this->updateProgress($deploymentId, 'connect', 0);
            }
            
            if (!$this->ssh->connectToServer($server)) {
                throw new \Exception('Failed to connect to server');
            }

            if (!$dryRun) {
                $this->updateProgress($deploymentId, 'connect', 100);
            }

            // Get templates to deploy
            $allTemplates = $this->templates->processBlueprintTemplates($blueprintId, $variables);
            
            // Filter by categories if specified
            if ($categories !== null) {
                $allTemplates = array_filter($allTemplates, function($t) use ($categories) {
                    return in_array($t['category'], $categories);
                });
            }

            // Analyze what needs to change
            $changes = $this->analyzeChanges($serverId, $allTemplates, $force);

            // Dry run - return preview
            if ($dryRun) {
                $this->ssh->disconnect();
                return [
                    'success' => true,
                    'dry_run' => true,
                    'changes' => $changes,
                    'summary' => [
                        'total_templates' => count($allTemplates),
                        'to_update' => count($changes['to_update']),
                        'unchanged' => count($changes['unchanged']),
                        'services_to_restart' => $changes['services'],
                    ],
                ];
            }

            // Backup current configs
            $this->updateProgress($deploymentId, 'backup', 0);
            if ($backup && !empty($changes['to_update'])) {
                $this->backupConfigs($serverId, $deploymentId, $changes['to_update']);
            }
            $this->updateProgress($deploymentId, 'backup', 100);

            // Deploy configs
            $this->updateProgress($deploymentId, 'deploy_configs', 0);
            $deployed = $this->deployTemplates($serverId, $changes['to_update']);
            $this->updateProgress($deploymentId, 'deploy_configs', 100);

            // Restart affected services
            $this->updateProgress($deploymentId, 'restart_services', 0);
            $this->restartServices($changes['services']);
            $this->updateProgress($deploymentId, 'restart_services', 100);

            // Verify services are running
            $this->updateProgress($deploymentId, 'verify', 0);
            $verifyResults = $this->verifyServices($changes['services']);
            $this->updateProgress($deploymentId, 'verify', 100);

            // Update server status
            $this->updateServerStatus($serverId, 'active');
            $this->completeDeployment($deploymentId, 'success');

            return [
                'success' => true,
                'deployment_id' => $deploymentId,
                'message' => 'Configuration deployed successfully',
                'configs_updated' => count($deployed),
                'configs_unchanged' => count($changes['unchanged']),
                'services_restarted' => $changes['services'],
                'verification' => $verifyResults,
            ];

        } catch (\Exception $e) {
            if (!$dryRun && $deploymentId) {
                $this->updateServerStatus($serverId, 'error', $e->getMessage());
                $this->completeDeployment($deploymentId, 'failed', $e->getMessage());
            }

            return [
                'success' => false,
                'deployment_id' => $deploymentId,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Analyze what configs need to change
     */
    private function analyzeChanges(int $serverId, array $templates, bool $force = false): array
    {
        $toUpdate = [];
        $unchanged = [];
        $services = [];

        foreach ($templates as $template) {
            $needsUpdate = $force || $this->shouldApplyConfig(
                $serverId,
                $template['target_path'],
                $template['content']
            );

            if ($needsUpdate) {
                $toUpdate[] = $template;
                
                // Determine affected service
                $service = $this->getAffectedService($template['target_path']);
                if ($service !== null && !in_array($service, $services)) {
                    $services[] = $service;
                }
            } else {
                $unchanged[] = $template['target_path'];
            }
        }

        return [
            'to_update' => $toUpdate,
            'unchanged' => $unchanged,
            'services' => $services,
        ];
    }

    /**
     * Check if a config should be applied (compares hash)
     */
    private function shouldApplyConfig(int $serverId, string $path, string $newContent): bool
    {
        $newHash = hash('sha256', $newContent);

        // Check database for stored hash
        $stmt = $this->db->prepare(
            "SELECT content_hash FROM server_configs WHERE server_id = ? AND target_path = ?"
        );
        $stmt->execute([$serverId, $path]);
        $existingHash = $stmt->fetchColumn();

        // Also check actual file on server if no record exists
        if (!$existingHash) {
            $result = $this->ssh->exec("sha256sum {$path} 2>/dev/null | awk '{print \$1}'");
            $serverHash = trim($result['output'] ?? '');
            if ($serverHash === $newHash) {
                return false;
            }
        }

        return $existingHash !== $newHash;
    }

    /**
     * Get the service affected by a config path
     */
    private function getAffectedService(string $path): ?string
    {
        foreach (self::SERVICE_MAP as $prefix => $service) {
            if (str_starts_with($path, $prefix)) {
                return $service;
            }
        }
        return null;
    }

    /**
     * Backup existing configs before deployment
     */
    private function backupConfigs(int $serverId, int $deploymentId, array $templates): void
    {
        $this->log("Backing up " . count($templates) . " configs");

        foreach ($templates as $template) {
            $path = $template['target_path'];
            
            // Check if file exists
            $result = $this->ssh->exec("test -f {$path} && cat {$path}");
            if (!empty($result['output'])) {
                // Store backup in database
                $stmt = $this->db->prepare(
                    "INSERT INTO config_backups (server_id, deployment_id, target_path, backup_content)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->execute([$serverId, $deploymentId, $path, $result['output']]);
                $this->log("Backed up: {$path}");
            }
        }
    }

    /**
     * Deploy templates to server
     */
    private function deployTemplates(int $serverId, array $templates): array
    {
        $deployed = [];
        $blocked = [];

        // Pre-deploy guard: never write a config that still contains literal {{VAR}}
        // placeholders - that always breaks the target service. Fail loudly instead.
        foreach ($templates as $template) {
            $unresolved = $this->templates->findUnresolvedPlaceholders($template['content'] ?? '');
            if (!empty($unresolved)) {
                $blocked[] = "{$template['target_path']} (missing: " . implode(', ', $unresolved) . ')';
            }
        }
        if (!empty($blocked)) {
            throw new \Exception(
                'Refusing to deploy configs with unresolved template variables: ' . implode('; ', $blocked)
            );
        }

        foreach ($templates as $template) {
            // Ensure directory exists
            $dir = dirname($template['target_path']);
            $this->ssh->mkdir($dir);

            // Upload content
            $this->ssh->uploadContent($template['content'], $template['target_path']);

            // Set permissions
            $this->ssh->chmod($template['target_path'], $template['permissions']);
            $this->ssh->chown($template['target_path'], $template['owner'], $template['group']);

            // Record the config hash
            $this->recordConfigApplied($serverId, $template);

            $deployed[] = $template['target_path'];
            $this->log("Deployed: {$template['target_path']}");
        }

        return $deployed;
    }

    /**
     * Record that a config was applied
     */
    private function recordConfigApplied(int $serverId, array $template): void
    {
        $hash = hash('sha256', $template['content']);

        $stmt = $this->db->prepare(
            "INSERT INTO server_configs (server_id, target_path, content_hash, template_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content_hash = VALUES(content_hash), template_id = VALUES(template_id), updated_at = NOW()"
        );
        $stmt->execute([$serverId, $template['target_path'], $hash, $template['id'] ?? null]);
    }

    /**
     * Restart affected services
     */
    private function restartServices(array $services): void
    {
        foreach ($services as $service) {
            $this->log("Restarting: {$service}");
            $this->ssh->restartService($service);
        }
    }

    /**
     * Verify services are running after restart
     */
    private function verifyServices(array $services): array
    {
        $results = [];

        foreach ($services as $service) {
            $result = $this->ssh->exec("systemctl is-active {$service} 2>/dev/null");
            $status = trim($result['output'] ?? 'unknown');
            $results[$service] = $status;
            
            if ($status !== 'active') {
                $this->log("Warning: Service {$service} is not active (status: {$status})");
            } else {
                $this->log("Verified: {$service} is active");
            }
        }

        return $results;
    }

    /**
     * Rollback to backup configs
     */
    public function rollback(int $deploymentId): array
    {
        // Get deployment info
        $stmt = $this->db->prepare(
            "SELECT d.*, s.* FROM deployments d
             JOIN servers s ON d.server_id = s.id
             WHERE d.id = ?"
        );
        $stmt->execute([$deploymentId]);
        $deployment = $stmt->fetch();

        if (!$deployment) {
            return ['success' => false, 'error' => 'Deployment not found'];
        }

        // Get backups for this deployment
        $stmt = $this->db->prepare(
            "SELECT * FROM config_backups WHERE deployment_id = ?"
        );
        $stmt->execute([$deploymentId]);
        $backups = $stmt->fetchAll();

        if (empty($backups)) {
            return ['success' => false, 'error' => 'No backups found for this deployment'];
        }

        try {
            // Connect to server
            if (!$this->ssh->connectToServer($deployment)) {
                throw new \Exception('Failed to connect to server');
            }

            $services = [];
            $restored = [];

            // Restore each backup
            foreach ($backups as $backup) {
                $this->ssh->uploadContent($backup['backup_content'], $backup['target_path']);
                $restored[] = $backup['target_path'];

                // Track affected services
                $service = $this->getAffectedService($backup['target_path']);
                if ($service !== null && !in_array($service, $services)) {
                    $services[] = $service;
                }
            }

            // Restart affected services
            $this->restartServices($services);

            // Update deployment status
            $stmt = $this->db->prepare(
                "UPDATE deployments SET status = 'rollback' WHERE id = ?"
            );
            $stmt->execute([$deploymentId]);

            return [
                'success' => true,
                'message' => 'Rollback completed successfully',
                'configs_restored' => count($restored),
                'services_restarted' => $services,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Get diff between current config and new template
     */
    public function getDiff(int $serverId, int $blueprintId, string $targetPath): array
    {
        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();

        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        $variables = $this->templates->generateServerVariables($server);
        $templates = $this->templates->processBlueprintTemplates($blueprintId, $variables);

        // Find the specific template
        $template = null;
        foreach ($templates as $t) {
            if ($t['target_path'] === $targetPath) {
                $template = $t;
                break;
            }
        }

        if (!$template) {
            return ['success' => false, 'error' => 'Template not found for this path'];
        }

        try {
            if (!$this->ssh->connectToServer($server)) {
                throw new \Exception('Failed to connect to server');
            }

            // Get current content from server
            $result = $this->ssh->exec("cat {$targetPath} 2>/dev/null");
            $currentContent = $result['output'] ?? '';
            $newContent = $template['content'];

            // Generate diff
            $diff = $this->generateDiff($currentContent, $newContent);

            return [
                'success' => true,
                'target_path' => $targetPath,
                'current_exists' => !empty($currentContent),
                'will_change' => $currentContent !== $newContent,
                'diff' => $diff,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Generate a simple diff between two strings
     */
    private function generateDiff(string $old, string $new): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);
        
        $diff = [];
        $maxLines = max(count($oldLines), count($newLines));
        
        for ($i = 0; $i < $maxLines; $i++) {
            $oldLine = $oldLines[$i] ?? null;
            $newLine = $newLines[$i] ?? null;
            
            if ($oldLine === $newLine) {
                $diff[] = ['type' => 'same', 'line' => $i + 1, 'content' => $oldLine];
            } elseif ($oldLine === null) {
                $diff[] = ['type' => 'add', 'line' => $i + 1, 'content' => $newLine];
            } elseif ($newLine === null) {
                $diff[] = ['type' => 'remove', 'line' => $i + 1, 'content' => $oldLine];
            } else {
                $diff[] = ['type' => 'remove', 'line' => $i + 1, 'content' => $oldLine];
                $diff[] = ['type' => 'add', 'line' => $i + 1, 'content' => $newLine];
            }
        }
        
        return $diff;
    }

    /**
     * Add a log entry
     */
    private function log(string $message): void
    {
        $this->deploymentLog[] = [
            'time' => date('Y-m-d H:i:s'),
            'message' => $message,
        ];
    }

    /**
     * Create deployment record
     */
    private function createDeployment(int $serverId, int $blueprintId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO deployments (server_id, blueprint_id, type, status, total_steps, started_at)
             VALUES (?, ?, ?, 'running', ?, NOW())"
        );
        $stmt->execute([$serverId, $blueprintId, DeploymentType::CONFIG_ONLY, count(self::STEPS)]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update deployment progress
     */
    private function updateProgress(int $deploymentId, string $step, int $stepProgress): void
    {
        $stepInfo = self::STEPS[$step] ?? ['name' => $step, 'weight' => 1];
        
        $totalWeight = array_sum(array_column(self::STEPS, 'weight'));
        $completedWeight = 0;
        
        foreach (self::STEPS as $name => $info) {
            if ($name === $step) {
                $completedWeight += ($info['weight'] * $stepProgress / 100);
                break;
            }
            $completedWeight += $info['weight'];
        }
        
        $progress = (int)round(($completedWeight / $totalWeight) * 100);

        $stmt = $this->db->prepare(
            "UPDATE deployments SET current_step = ?, progress = ? WHERE id = ?"
        );
        $stmt->execute([$stepInfo['name'], $progress, $deploymentId]);
    }

    /**
     * Complete deployment
     */
    private function completeDeployment(int $deploymentId, string $status, ?string $error = null): void
    {
        $logText = implode("\n", array_map(
            fn($entry) => "[{$entry['time']}] {$entry['message']}",
            $this->deploymentLog
        ));

        $stepLabel = $status === 'success' ? 'Deployment complete' : ($status === 'failed' ? 'Deployment failed' : 'Deployment ' . $status);

        $stmt = $this->db->prepare(
            "UPDATE deployments SET status = ?, current_step = ?, error_message = ?, log = ?, completed_at = NOW(), progress = 100 WHERE id = ?"
        );
        $stmt->execute([$status, $stepLabel, $error, $logText, $deploymentId]);
    }

    /**
     * Update server status
     */
    private function updateServerStatus(int $serverId, string $status, ?string $step = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE servers SET status = ?, provision_step = ?, last_error = NULL WHERE id = ?"
        );
        $stmt->execute([$status, $step, $serverId]);
    }
}

