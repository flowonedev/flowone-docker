<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class ServiceController extends BaseController
{
    /**
     * List all services - cached for 30 seconds
     */
    public function index(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'services:list';

        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        $data = $this->cache->remember($cacheKey, 30, function() {
            $result = $this->agent->execute('service.list');
            return $result['success'] ? $result['data'] : null;
        });

        if ($data === null) {
            return Response::error('Failed to fetch services');
        }

        return Response::success($data);
    }

    /**
     * Get service status
     */
    public function show(Request $request): Response
    {
        $name = $request->getParam('name');
        return $this->agentAction('service.status', ['name' => $name]);
    }

    /**
     * Restart service
     */
    public function restart(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('service.restart', ['name' => $name], $this->getActor());
        
        if ($result['success']) {
            $this->cache->delete('services:list'); // Invalidate cache
            $this->logAction('service.restart', $name, 'success');
            return Response::success($result['data'], $result['message'] ?? "Service {$name} restarted");
        }
        
        $this->logAction('service.restart', $name, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to restart service');
    }

    /**
     * Reload service
     */
    public function reload(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('service.reload', ['name' => $name], $this->getActor());
        
        if ($result['success']) {
            $this->cache->delete('services:list');
            $this->logAction('service.reload', $name, 'success');
            return Response::success($result['data'], $result['message'] ?? "Service {$name} reloaded");
        }
        
        $this->logAction('service.reload', $name, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to reload service');
    }

    /**
     * Start service
     */
    public function start(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('service.start', ['name' => $name], $this->getActor());
        
        if ($result['success']) {
            $this->cache->delete('services:list');
            $this->logAction('service.start', $name, 'success');
            return Response::success($result['data'], $result['message'] ?? "Service {$name} started");
        }
        
        $this->logAction('service.start', $name, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to start service');
    }

    /**
     * Stop service
     */
    public function stop(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('service.stop', ['name' => $name], $this->getActor());
        
        if ($result['success']) {
            $this->cache->delete('services:list');
            $this->logAction('service.stop', $name, 'success');
            return Response::success($result['data'], $result['message'] ?? "Service {$name} stopped");
        }
        
        $this->logAction('service.stop', $name, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to stop service');
    }

    /**
     * Get service logs
     * Falls back to direct shell command if agent is down
     */
    public function logs(Request $request): Response
    {
        $name = $request->getParam('name');
        $lines = min((int)($request->getQuery('lines') ?? 50), 200);
        
        // Only allow specific services for security
        $allowedServices = [
            'vpsadmin-agent', 'lsws', 'mysql', 'mariadb', 'redis',
            'postfix', 'dovecot', 'pdns', 'named', 'fail2ban',
            'mailsync-server', 'collab-server' // Email app services
        ];
        
        if (!in_array($name, $allowedServices)) {
            return Response::error('Service not allowed');
        }
        
        // Try agent first
        $result = $this->agent->execute('service.logs', [
            'name' => $name,
            'lines' => $lines
        ], $this->getActor());
        
        if ($result['success']) {
            return Response::success($result['data']);
        }
        
        // Fallback: fetch logs directly (when agent is down)
        return $this->getLogsDirectly($name, $lines);
    }
    
    /**
     * Get logs directly via shell when agent is unavailable
     * Requires www-data to be in systemd-journal group
     */
    private function getLogsDirectly(string $service, int $lines): Response
    {
        // Escape service name for safety
        $serviceName = trim($service);
        $serviceEsc = escapeshellarg($serviceName);
        $lines = (int)$lines;
        
        // Get recent logs (www-data must be in systemd-journal group)
        $logs = shell_exec("journalctl -u {$serviceEsc} -n {$lines} --no-pager --no-hostname 2>&1");
        
        // Get error-level logs
        $errors = shell_exec("journalctl -u {$serviceEsc} -n 30 --no-pager --no-hostname -p err 2>&1");
        
        // Check for permission error
        if ($logs && strpos($logs, 'No journal files were opened') !== false) {
            return Response::success([
                'service' => $serviceName,
                'logs' => '',
                'errors' => 'Permission denied. Run: sudo usermod -a -G systemd-journal www-data && sudo systemctl restart lsws',
                'lines' => $lines,
                'source' => 'direct',
                'permission_error' => true
            ]);
        }
        
        return Response::success([
            'service' => $serviceName,
            'logs' => $logs ?? 'No logs available',
            'errors' => $errors ?? '',
            'lines' => $lines,
            'source' => 'direct'
        ]);
    }
}

