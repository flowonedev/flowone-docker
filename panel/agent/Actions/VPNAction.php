<?php
/**
 * VPN Action Handler
 * 
 * Manages OpenVPN client connections:
 * - List existing VPN configs
 * - Get VPN status
 * - Start/Stop/Restart VPN services
 * - Create/Delete VPN configs
 * - Get VPN logs
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

class VPNAction extends BaseAction
{
    private const CONFIG_DIR = '/etc/openvpn/client';
    private const SERVICE_PREFIX = 'openvpn-client@';

    public function getNamespace(): string
    {
        return 'vpn';
    }

    public function getMethods(): array
    {
        return [
            'list',      // List all VPN configs
            'status',    // Get VPN service status
            'start',     // Start VPN service
            'stop',      // Stop VPN service
            'restart',   // Restart VPN service
            'ensure',    // Make sure VPN is connected (start/restart as needed)
            'create',    // Create new VPN config
            'update',    // Update config content + restart tunnel
            'delete',    // Delete VPN config
            'logs',      // Get VPN logs
            'getConfig', // Get config file content
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['create', 'update', 'delete']);
    }

    /**
     * List all OpenVPN client configs
     */
    public function actionList(array $params, string $actor): array
    {
        if (!is_dir(self::CONFIG_DIR)) {
            return $this->success(['connections' => []], 'No VPN configs found');
        }

        $connections = [];
        $files = glob(self::CONFIG_DIR . '/*.conf');

        foreach ($files as $file) {
            $configName = basename($file, '.conf');
            $status = $this->getServiceStatus($configName);
            
            $connections[] = [
                'name' => $configName,
                'config_file' => $file,
                'status' => $status['status'],
                'local_ip' => $status['local_ip'],
                'remote_ip' => $status['remote_ip'],
                'connected_at' => $status['connected_at'],
                'enabled' => $status['enabled'],
            ];
        }

        return $this->success([
            'connections' => $connections,
            'count' => count($connections),
        ]);
    }

    /**
     * Get VPN service status
     */
    public function actionStatus(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        
        if (empty($name)) {
            return $this->error('VPN name is required');
        }

        $status = $this->getServiceStatus($name);
        
        return $this->success($status);
    }

    /**
     * Start VPN service
     */
    public function actionStart(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        
        if (empty($name)) {
            return $this->error('VPN name is required');
        }

        $configFile = self::CONFIG_DIR . "/{$name}.conf";
        if (!file_exists($configFile)) {
            return $this->error("VPN config not found: {$name}");
        }

        $serviceName = self::SERVICE_PREFIX . $name;
        
        $result = $this->execCommand('systemctl', ['start', $serviceName]);
        
        if (!$result['success']) {
            return $this->error("Failed to start VPN: " . $result['output']);
        }

        // Wait a moment for connection to establish
        sleep(2);
        
        $status = $this->getServiceStatus($name);
        
        $this->logger->info("VPN started", [
            'name' => $name,
            'actor' => $actor,
            'status' => $status['status'],
        ]);

        return $this->success($status, "VPN {$name} started");
    }

    /**
     * Stop VPN service
     */
    public function actionStop(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        
        if (empty($name)) {
            return $this->error('VPN name is required');
        }

        $serviceName = self::SERVICE_PREFIX . $name;
        
        $result = $this->execCommand('systemctl', ['stop', $serviceName]);
        
        if (!$result['success']) {
            return $this->error("Failed to stop VPN: " . $result['output']);
        }

        $this->logger->info("VPN stopped", [
            'name' => $name,
            'actor' => $actor,
        ]);

        return $this->success([
            'name' => $name,
            'status' => 'disconnected',
        ], "VPN {$name} stopped");
    }

    /**
     * Restart VPN service
     */
    public function actionRestart(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        
        if (empty($name)) {
            return $this->error('VPN name is required');
        }

        $serviceName = self::SERVICE_PREFIX . $name;
        
        $result = $this->execCommand('systemctl', ['restart', $serviceName]);
        
        if (!$result['success']) {
            return $this->error("Failed to restart VPN: " . $result['output']);
        }

        // Wait a moment for connection to establish
        sleep(2);
        
        $status = $this->getServiceStatus($name);
        
        $this->logger->info("VPN restarted", [
            'name' => $name,
            'actor' => $actor,
            'status' => $status['status'],
        ]);

        return $this->success($status, "VPN {$name} restarted");
    }

    /**
     * Ensure the VPN tunnel is connected, taking whatever action is needed:
     * already connected -> no-op, stopped -> start, failed -> restart.
     * Polls for the tunnel interface to come up instead of a blind sleep.
     */
    public function actionEnsure(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';

        if (empty($name)) {
            return $this->error('VPN name is required');
        }

        $configFile = self::CONFIG_DIR . "/{$name}.conf";
        if (!file_exists($configFile)) {
            return $this->error("VPN config not found: {$name}");
        }

        $serviceName = self::SERVICE_PREFIX . $name;
        $status = $this->getServiceStatus($name);
        $actionTaken = 'none';

        if ($status['status'] !== 'connected' || empty($status['local_ip'])) {
            // 'failed' units need restart; a plain start would be refused.
            $verb = $status['status'] === 'error' ? 'restart' : 'start';
            $result = $this->execCommand('systemctl', [$verb, $serviceName], 30);
            if (!$result['success']) {
                return $this->error("Failed to {$verb} VPN: " . $result['output'], [
                    'status' => $status,
                ]);
            }
            $actionTaken = $verb === 'restart' ? 'restarted' : 'started';

            // Poll up to ~12s for the tunnel interface to get an IP.
            for ($i = 0; $i < 6; $i++) {
                sleep(2);
                $status = $this->getServiceStatus($name);
                if ($status['status'] === 'connected' && !empty($status['local_ip'])) {
                    break;
                }
                if ($status['status'] === 'error') {
                    break;
                }
            }
        }

        // Make sure it comes back after reboots too.
        $this->execCommand('systemctl', ['enable', $serviceName], 15);

        $connected = $status['status'] === 'connected' && !empty($status['local_ip']);
        $status['action_taken'] = $actionTaken;

        if (!$connected) {
            $logResult = $this->execCommand('journalctl', ['-u', $serviceName, '-n', '10', '--no-pager', '-o', 'cat'], 15);
            return $this->error("VPN {$name} did not come up (status: {$status['status']})", [
                'status' => $status,
                'recent_logs' => trim($logResult['output']),
            ]);
        }

        $this->logger->info('VPN ensured connected', [
            'name' => $name,
            'action_taken' => $actionTaken,
            'local_ip' => $status['local_ip'],
            'actor' => $actor,
        ]);

        return $this->success($status, $actionTaken === 'none'
            ? "VPN {$name} already connected"
            : "VPN {$name} {$actionTaken} and connected");
    }

    /**
     * Create new VPN config
     */
    public function actionCreate(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        $configContent = $params['config_content'] ?? '';
        $upScript = $params['up_script'] ?? '';
        $downScript = $params['down_script'] ?? '';
        
        if (empty($name)) {
            return $this->error('VPN name is required');
        }
        
        if (empty($configContent)) {
            return $this->error('Config content is required');
        }

        // Sanitize name (only alphanumeric, dash, underscore)
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        
        if (empty($name)) {
            return $this->error('Invalid VPN name');
        }

        $configFile = self::CONFIG_DIR . "/{$name}.conf";
        
        if (file_exists($configFile)) {
            return $this->error("VPN config already exists: {$name}");
        }

        // Ensure config directory exists
        if (!is_dir(self::CONFIG_DIR)) {
            if (!@mkdir(self::CONFIG_DIR, 0755, true)) {
                return $this->error('Failed to create config directory');
            }
        }

        // Write config file
        if (@file_put_contents($configFile, $configContent) === false) {
            return $this->error('Failed to write config file');
        }
        chmod($configFile, 0600);

        // Write up script if provided
        if (!empty($upScript)) {
            $upScriptFile = self::CONFIG_DIR . "/{$name}-up.sh";
            if (@file_put_contents($upScriptFile, $upScript) !== false) {
                chmod($upScriptFile, 0755);
            }
        }

        // Write down script if provided
        if (!empty($downScript)) {
            $downScriptFile = self::CONFIG_DIR . "/{$name}-down.sh";
            if (@file_put_contents($downScriptFile, $downScript) !== false) {
                chmod($downScriptFile, 0755);
            }
        }

        // Enable service for auto-start
        $serviceName = self::SERVICE_PREFIX . $name;
        $this->execCommand('systemctl', ['enable', $serviceName]);

        $this->logger->info("VPN config created", [
            'name' => $name,
            'actor' => $actor,
        ]);

        return $this->success([
            'name' => $name,
            'config_file' => $configFile,
        ], "VPN config {$name} created");
    }

    /**
     * Update an existing VPN config and restart the tunnel so the new
     * settings take effect ("re-import .ovpn" from the UI).
     */
    public function actionUpdate(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        $configContent = $params['config_content'] ?? '';
        $upScript = $params['up_script'] ?? null;
        $downScript = $params['down_script'] ?? null;

        if (empty($name)) {
            return $this->error('VPN name is required');
        }
        if (empty($configContent)) {
            return $this->error('Config content is required');
        }

        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        $configFile = self::CONFIG_DIR . "/{$name}.conf";

        if (!file_exists($configFile)) {
            return $this->error("VPN config not found: {$name} (use create instead)");
        }

        $this->backupFile($configFile, 'update', $actor);

        if (@file_put_contents($configFile, $configContent) === false) {
            return $this->error('Failed to write config file');
        }
        chmod($configFile, 0600);

        // null = leave scripts untouched, '' = remove, non-empty = replace
        if ($upScript !== null) {
            $upScriptFile = self::CONFIG_DIR . "/{$name}-up.sh";
            if ($upScript === '') {
                @unlink($upScriptFile);
            } elseif (@file_put_contents($upScriptFile, $upScript) !== false) {
                chmod($upScriptFile, 0755);
            }
        }
        if ($downScript !== null) {
            $downScriptFile = self::CONFIG_DIR . "/{$name}-down.sh";
            if ($downScript === '') {
                @unlink($downScriptFile);
            } elseif (@file_put_contents($downScriptFile, $downScript) !== false) {
                chmod($downScriptFile, 0755);
            }
        }

        // Restart only if the tunnel is currently running; a stopped tunnel
        // stays stopped (the operator may be staging config for later).
        $serviceName = self::SERVICE_PREFIX . $name;
        $activeResult = $this->execCommand('systemctl', ['is-active', $serviceName], 10);
        $restarted = false;
        if (trim($activeResult['output']) === 'active') {
            $restart = $this->execCommand('systemctl', ['restart', $serviceName], 30);
            $restarted = $restart['success'];
            if (!$restart['success']) {
                return $this->error('Config updated but restart failed: ' . $restart['output']);
            }
            sleep(2);
        }

        $status = $this->getServiceStatus($name);

        $this->logger->info('VPN config updated', [
            'name' => $name,
            'restarted' => $restarted,
            'actor' => $actor,
        ]);

        return $this->success(array_merge($status, ['restarted' => $restarted]),
            $restarted ? "VPN {$name} updated and restarted" : "VPN {$name} config updated");
    }

    /**
     * Delete VPN config
     */
    public function actionDelete(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        
        if (empty($name)) {
            return $this->error('VPN name is required');
        }

        $configFile = self::CONFIG_DIR . "/{$name}.conf";
        
        if (!file_exists($configFile)) {
            return $this->error("VPN config not found: {$name}");
        }

        // Stop and disable service first
        $serviceName = self::SERVICE_PREFIX . $name;
        $this->execCommand('systemctl', ['stop', $serviceName]);
        $this->execCommand('systemctl', ['disable', $serviceName]);

        // Backup config before deletion
        $this->backupFile($configFile, 'delete', $actor);

        // Delete config file
        if (!@unlink($configFile)) {
            return $this->error('Failed to delete config file');
        }

        // Delete scripts if they exist
        $upScriptFile = self::CONFIG_DIR . "/{$name}-up.sh";
        $downScriptFile = self::CONFIG_DIR . "/{$name}-down.sh";
        
        if (file_exists($upScriptFile)) {
            @unlink($upScriptFile);
        }
        if (file_exists($downScriptFile)) {
            @unlink($downScriptFile);
        }

        $this->logger->info("VPN config deleted", [
            'name' => $name,
            'actor' => $actor,
        ]);

        return $this->success([
            'name' => $name,
        ], "VPN config {$name} deleted");
    }

    /**
     * Get VPN logs
     */
    public function actionLogs(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        $lines = $params['lines'] ?? 50;
        
        if (empty($name)) {
            return $this->error('VPN name is required');
        }

        $serviceName = self::SERVICE_PREFIX . $name;
        
        $result = $this->execCommand('journalctl', [
            '-u', $serviceName,
            '-n', (string)$lines,
            '--no-pager',
            '-o', 'short-iso'
        ]);

        return $this->success([
            'name' => $name,
            'logs' => $result['output'],
        ]);
    }

    /**
     * Get config file content
     */
    public function actionGetConfig(array $params, string $actor): array
    {
        $name = $params['name'] ?? '';
        
        if (empty($name)) {
            return $this->error('VPN name is required');
        }

        $configFile = self::CONFIG_DIR . "/{$name}.conf";
        
        if (!file_exists($configFile)) {
            return $this->error("VPN config not found: {$name}");
        }

        $content = @file_get_contents($configFile);
        
        if ($content === false) {
            return $this->error('Failed to read config file');
        }

        // Also check for up/down scripts
        $upScript = '';
        $downScript = '';
        
        $upScriptFile = self::CONFIG_DIR . "/{$name}-up.sh";
        $downScriptFile = self::CONFIG_DIR . "/{$name}-down.sh";
        
        if (file_exists($upScriptFile)) {
            $upScript = @file_get_contents($upScriptFile) ?: '';
        }
        if (file_exists($downScriptFile)) {
            $downScript = @file_get_contents($downScriptFile) ?: '';
        }

        return $this->success([
            'name' => $name,
            'config_content' => $content,
            'up_script' => $upScript,
            'down_script' => $downScript,
        ]);
    }

    /**
     * Get service status details
     */
    private function getServiceStatus(string $name): array
    {
        $serviceName = self::SERVICE_PREFIX . $name;
        
        $status = [
            'name' => $name,
            'status' => 'disconnected',
            'local_ip' => null,
            'remote_ip' => null,
            'connected_at' => null,
            'enabled' => false,
            'error' => null,
        ];

        // Check if service is enabled
        $enabledResult = $this->execCommand('systemctl', ['is-enabled', $serviceName]);
        $status['enabled'] = trim($enabledResult['output']) === 'enabled';

        // Check if service is active
        $activeResult = $this->execCommand('systemctl', ['is-active', $serviceName]);
        $activeStatus = trim($activeResult['output']);

        if ($activeStatus === 'active') {
            $status['status'] = 'connected';
            
            // Get connection details from systemctl show
            $showResult = $this->execCommand('systemctl', ['show', $serviceName, '--property=ActiveEnterTimestamp']);
            if (preg_match('/ActiveEnterTimestamp=(.+)/', $showResult['output'], $matches)) {
                $timestamp = trim($matches[1]);
                if (!empty($timestamp) && $timestamp !== 'n/a') {
                    $status['connected_at'] = date('Y-m-d H:i:s', strtotime($timestamp));
                }
            }

            // Try to get IP from tun interface
            $ipResult = $this->execCommand('ip', ['addr', 'show']);
            if (preg_match('/inet\s+(10\.\d+\.\d+\.\d+)\/\d+.*tun/m', $ipResult['output'], $matches)) {
                $status['local_ip'] = $matches[1];
            }
            
            // Try to get peer IP
            if (preg_match('/peer\s+(10\.\d+\.\d+\.\d+)/m', $ipResult['output'], $matches)) {
                $status['remote_ip'] = $matches[1];
            }
        } elseif ($activeStatus === 'activating') {
            $status['status'] = 'connecting';
        } elseif ($activeStatus === 'failed') {
            $status['status'] = 'error';
            
            // Get error from journal
            $logResult = $this->execCommand('journalctl', ['-u', $serviceName, '-n', '5', '--no-pager', '-o', 'cat']);
            $status['error'] = trim($logResult['output']);
        }

        return $status;
    }
}

