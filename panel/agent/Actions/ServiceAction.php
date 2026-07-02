<?php
/**
 * Service Action Handler
 * 
 * Manages system services (status, restart, reload).
 * Only allows operations on whitelisted services.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\DockerServiceBridge;
use VpsAdmin\Agent\Lib\Validator;

class ServiceAction extends BaseAction
{
    private ?DockerServiceBridge $docker = null;

    public function getNamespace(): string
    {
        return 'service';
    }

    /**
     * Hybrid boxes run the mail/email tier as Docker containers, so the systemd
     * unit for e.g. mariadb/postfix simply does not exist there. When that is
     * the case (LoadState=not-found) we route status/control/logs through the
     * Docker bridge instead of reporting a false "Stopped".
     */
    private function dockerBridge(): DockerServiceBridge
    {
        if ($this->docker === null) {
            $this->docker = new DockerServiceBridge(
                fn (string $cmd, array $args, int $timeout) => $this->execCommand($cmd, $args, $timeout)
            );
        }
        return $this->docker;
    }

    /** True when no systemd unit file exists for this name on this box. */
    private function unitMissing(string $name): bool
    {
        $res = $this->execCommand('systemctl', ['show', '-p', 'LoadState', '--value', $name]);
        return trim($res['output']) === 'not-found';
    }

    /** Whether this service should be handled by the Docker bridge here. */
    private function isDockerBacked(string $name): bool
    {
        return DockerServiceBridge::handles($name) && $this->unitMissing($name);
    }

    public function getMethods(): array
    {
        return ['status', 'list', 'restart', 'reload', 'start', 'stop', 'logs'];
    }

    public function requiresBackup(string $method): bool
    {
        return false; // Service operations don't modify config files
    }

    /**
     * Get status of all allowed services
     */
    protected function actionList(array $params, string $actor): array
    {
        $services = [];
        
        foreach ($this->config['allowed_services'] as $service) {
            $status = $this->getServiceStatus($service);
            $services[] = [
                'name' => $service,
                'status' => $status['status'],
                'active' => $status['active'],
                'enabled' => $status['enabled'],
                'uptime' => $status['uptime'] ?? null,
                'memory' => $status['memory'] ?? null,
            ];
        }

        return $this->success(['services' => $services]);
    }

    /**
     * Get status of a specific service
     */
    protected function actionStatus(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Service name is required');
        }

        $name = $params['name'];
        
        if (!Validator::serviceName($name, $this->config['allowed_services'])) {
            return $this->error("Service not allowed: {$name}");
        }

        $status = $this->getServiceStatus($name);
        
        return $this->success($status);
    }

    /**
     * Restart a service
     */
    protected function actionRestart(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Service name is required');
        }

        $name = $params['name'];
        
        if (!Validator::serviceName($name, $this->config['allowed_services'])) {
            return $this->error("Service not allowed: {$name}");
        }

        $result = $this->isDockerBacked($name)
            ? $this->dockerBridge()->control($name, 'restart')
            : $this->execCommand('systemctl', ['restart', $name]);

        if ($result['success']) {
            // Wait a moment and check status
            usleep(500000); // 0.5 seconds
            $status = $this->getServiceStatus($name);
            
            return $this->success([
                'service' => $name,
                'action' => 'restart',
                'status' => $status,
            ], "Service {$name} restarted successfully");
        }

        return $this->error("Failed to restart {$name}: " . $result['output']);
    }

    /**
     * Reload a service (graceful)
     */
    protected function actionReload(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Service name is required');
        }

        $name = $params['name'];
        
        if (!Validator::serviceName($name, $this->config['allowed_services'])) {
            return $this->error("Service not allowed: {$name}");
        }

        // Special handling for OpenLiteSpeed
        if ($name === 'lsws') {
            $result = $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
        } elseif ($this->isDockerBacked($name)) {
            $result = $this->dockerBridge()->control($name, 'reload');
        } else {
            $result = $this->execCommand('systemctl', ['reload', $name]);
        }
        
        if ($result['success']) {
            usleep(500000);
            $status = $this->getServiceStatus($name);
            
            return $this->success([
                'service' => $name,
                'action' => 'reload',
                'status' => $status,
            ], "Service {$name} reloaded successfully");
        }

        return $this->error("Failed to reload {$name}: " . $result['output']);
    }

    /**
     * Start a service
     */
    protected function actionStart(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Service name is required');
        }

        $name = $params['name'];
        
        if (!Validator::serviceName($name, $this->config['allowed_services'])) {
            return $this->error("Service not allowed: {$name}");
        }

        $result = $this->isDockerBacked($name)
            ? $this->dockerBridge()->control($name, 'start')
            : $this->execCommand('systemctl', ['start', $name]);
        
        if ($result['success']) {
            usleep(500000);
            $status = $this->getServiceStatus($name);
            
            return $this->success([
                'service' => $name,
                'action' => 'start',
                'status' => $status,
            ], "Service {$name} started successfully");
        }

        return $this->error("Failed to start {$name}: " . $result['output']);
    }

    /**
     * Stop a service
     */
    protected function actionStop(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Service name is required');
        }

        $name = $params['name'];
        
        if (!Validator::serviceName($name, $this->config['allowed_services'])) {
            return $this->error("Service not allowed: {$name}");
        }

        $result = $this->isDockerBacked($name)
            ? $this->dockerBridge()->control($name, 'stop')
            : $this->execCommand('systemctl', ['stop', $name]);
        
        if ($result['success']) {
            usleep(500000);
            $status = $this->getServiceStatus($name);
            
            return $this->success([
                'service' => $name,
                'action' => 'stop',
                'status' => $status,
            ], "Service {$name} stopped successfully");
        }

        return $this->error("Failed to stop {$name}: " . $result['output']);
    }

    /**
     * Get recent logs for a service
     */
    protected function actionLogs(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Service name is required');
        }

        $name = $params['name'];
        
        if (!Validator::serviceName($name, $this->config['allowed_services'])) {
            return $this->error("Service not allowed: {$name}");
        }

        $lines = isset($params['lines']) ? min((int)$params['lines'], 200) : 50;

        if ($this->isDockerBacked($name)) {
            $dockerLogs = $this->dockerBridge()->logs($name, $lines);
            return $this->success([
                'service' => $name,
                'logs' => $dockerLogs['output'] ?? '',
                'errors' => '',
                'lines' => $lines,
            ]);
        }

        // Get recent journal logs
        $result = $this->execCommand('journalctl', [
            '-u', $name,
            '-n', (string)$lines,
            '--no-pager',
            '--no-hostname'
        ]);
        
        $logs = $result['output'];
        
        // Also get any fatal/error lines specifically
        $errorResult = $this->execCommand('journalctl', [
            '-u', $name,
            '-n', '20',
            '--no-pager',
            '--no-hostname',
            '-p', 'err'  // Only error priority
        ]);
        
        $errors = $errorResult['output'];
        
        return $this->success([
            'service' => $name,
            'logs' => $logs,
            'errors' => $errors,
            'lines' => $lines
        ]);
    }

    /**
     * Get detailed status of a service
     */
    private function getServiceStatus(string $name): array
    {
        if ($this->isDockerBacked($name)) {
            $status = $this->dockerBridge()->status($name);
            if ($status !== null) {
                return $status;
            }
        }

        // Check if active - systemctl is-active can return:
        // active, activating, reloading (all mean "running")
        // inactive, deactivating, failed (all mean "stopped")
        $activeResult = $this->execCommand('systemctl', ['is-active', $name]);
        $activeState = trim($activeResult['output']);
        $runningStates = ['active', 'activating', 'reloading'];
        $active = in_array($activeState, $runningStates, true);

        // Check if enabled
        $enabledResult = $this->execCommand('systemctl', ['is-enabled', $name]);
        $enabled = trim($enabledResult['output']) === 'enabled';

        // Get status output
        $statusResult = $this->execCommand('systemctl', ['status', $name, '--no-pager']);
        
        // Parse memory and uptime from status
        $memory = null;
        $uptime = null;
        
        if (preg_match('/Memory:\s+(.+)$/m', $statusResult['output'], $matches)) {
            $memory = trim($matches[1]);
        }
        
        // Only parse uptime if the service is actually running
        // (otherwise the "since" line shows when it stopped, not uptime)
        if ($active && preg_match('/Active:.*since\s+(.+);(.+)$/m', $statusResult['output'], $matches)) {
            $uptime = trim($matches[2]);
        }

        // Get main PID
        $pid = null;
        if (preg_match('/Main PID:\s+(\d+)/', $statusResult['output'], $matches)) {
            $pid = (int)$matches[1];
        }

        // Map detailed state for the frontend
        $status = $active ? 'running' : ($activeState === 'failed' ? 'failed' : 'stopped');

        return [
            'name' => $name,
            'status' => $status,
            'active' => $active,
            'enabled' => $enabled,
            'pid' => $pid,
            'memory' => $memory,
            'uptime' => $uptime,
        ];
    }
}

