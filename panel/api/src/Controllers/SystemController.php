<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * System Configuration Controller
 * 
 * Manages system-level settings:
 * - System information
 * - Hostname
 * - Timezone
 * - SSH configuration
 * - Swap management
 */
class SystemController extends BaseController
{
    /**
     * Get system information
     */
    public function info(Request $request): Response
    {
        return $this->agentAction('system.info');
    }

    /**
     * Get or set hostname
     */
    public function hostname(Request $request): Response
    {
        // GET - return current hostname
        if ($request->getMethod() === 'GET') {
            return $this->agentAction('system.hostname');
        }

        // POST - set hostname (super_admin only)
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $validation = $this->validateRequired($request, ['hostname']);
        if ($validation) return $validation;

        $result = $this->agent->execute('system.hostname', [
            'hostname' => $request->input('hostname'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.hostname', $result['data']['hostname'], 'success', [
                'old_hostname' => $result['data']['old_hostname'] ?? null,
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Hostname updated');
        }

        $this->logAction('system.hostname', $request->input('hostname'), 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to update hostname');
    }

    /**
     * Get or set timezone
     */
    public function timezone(Request $request): Response
    {
        // GET - return current timezone
        if ($request->getMethod() === 'GET') {
            return $this->agentAction('system.timezone');
        }

        // POST - set timezone (super_admin only)
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $validation = $this->validateRequired($request, ['timezone']);
        if ($validation) return $validation;

        $result = $this->agent->execute('system.timezone', [
            'timezone' => $request->input('timezone'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.timezone', $request->input('timezone'), 'success');
            return Response::success($result['data'], $result['message'] ?? 'Timezone updated');
        }

        $this->logAction('system.timezone', $request->input('timezone'), 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to update timezone');
    }

    /**
     * List available timezones
     */
    public function timezones(Request $request): Response
    {
        return $this->agentAction('system.timezones');
    }

    /**
     * Get SSH configuration
     */
    public function ssh(Request $request): Response
    {
        return $this->agentAction('system.ssh');
    }

    /**
     * Get raw SSH config file
     */
    public function sshRaw(Request $request): Response
    {
        return $this->agentAction('system.sshRaw');
    }

    /**
     * Update SSH configuration (super_admin only)
     */
    public function updateSsh(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $params = array_filter([
            'port' => $request->input('port'),
            'permit_root_login' => $request->input('permit_root_login'),
            'password_authentication' => $request->input('password_authentication'),
            'pubkey_authentication' => $request->input('pubkey_authentication'),
            'max_auth_tries' => $request->input('max_auth_tries'),
            'max_sessions' => $request->input('max_sessions'),
            'client_alive_interval' => $request->input('client_alive_interval'),
            'client_alive_count_max' => $request->input('client_alive_count_max'),
            'x11_forwarding' => $request->input('x11_forwarding'),
        ], fn($v) => $v !== null);

        if (empty($params)) {
            return Response::error('No settings provided');
        }

        $result = $this->agent->execute('system.updateSsh', $params, $this->getActor());

        if ($result['success']) {
            $this->logAction('system.ssh.update', 'sshd_config', 'success', $params);
            return Response::success($result['data'], $result['message'] ?? 'SSH configuration updated');
        }

        $this->logAction('system.ssh.update', 'sshd_config', 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to update SSH configuration');
    }

    /**
     * Get swap information
     */
    public function swap(Request $request): Response
    {
        return $this->agentAction('system.swap');
    }

    /**
     * Create swap file (super_admin only)
     */
    public function createSwap(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $validation = $this->validateRequired($request, ['size']);
        if ($validation) return $validation;

        $result = $this->agent->execute('system.createSwap', [
            'size' => $request->input('size'),
            'path' => $request->input('path', '/swapfile'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.swap.create', $request->input('path', '/swapfile'), 'success', [
                'size' => $request->input('size'),
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Swap file created');
        }

        $this->logAction('system.swap.create', $request->input('path', '/swapfile'), 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to create swap file');
    }

    /**
     * Get or set swappiness
     */
    public function swappiness(Request $request): Response
    {
        // GET - return current swappiness
        if ($request->getMethod() === 'GET') {
            return $this->agentAction('system.swappiness');
        }

        // POST - set swappiness (super_admin only)
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $validation = $this->validateRequired($request, ['value']);
        if ($validation) return $validation;

        $result = $this->agent->execute('system.swappiness', [
            'value' => (int) $request->input('value'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.swappiness', (string)$request->input('value'), 'success');
            return Response::success($result['data'], $result['message'] ?? 'Swappiness updated');
        }

        return Response::error($result['error'] ?? 'Failed to update swappiness');
    }

    /**
     * Get uptime information
     */
    public function uptime(Request $request): Response
    {
        return $this->agentAction('system.uptime');
    }

    /**
     * Reboot server (super_admin only)
     */
    public function reboot(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $delay = (int) $request->input('delay', 0);

        $result = $this->agent->execute('system.reboot', [
            'delay' => $delay,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.reboot', 'server', 'success', [
                'delay_minutes' => $delay,
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Server rebooting');
        }

        $this->logAction('system.reboot', 'server', 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to reboot server');
    }

    // ============================================
    // PowerDNS Configuration
    // ============================================

    /**
     * Get PowerDNS configuration
     */
    public function pdns(Request $request): Response
    {
        return $this->agentAction('system.pdns');
    }

    /**
     * Get PowerDNS service status
     */
    public function pdnsStatus(Request $request): Response
    {
        return $this->agentAction('system.pdnsStatus');
    }

    /**
     * Update PowerDNS configuration (super_admin only)
     */
    public function updatePdns(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $validation = $this->validateRequired($request, ['config']);
        if ($validation) return $validation;

        $result = $this->agent->execute('system.updatePdns', [
            'config' => $request->input('config'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.pdns.update', 'pdns.conf', 'success');
            return Response::success($result['data'], $result['message'] ?? 'PowerDNS configuration updated');
        }

        $this->logAction('system.pdns.update', 'pdns.conf', 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to update PowerDNS configuration');
    }

    /**
     * Restart PowerDNS service (super_admin only)
     */
    public function restartPdns(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $result = $this->agent->execute('service.restart', [
            'name' => 'pdns',
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.pdns.restart', 'pdns', 'success');
            return Response::success($result['data'], 'PowerDNS restarted');
        }

        $this->logAction('system.pdns.restart', 'pdns', 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to restart PowerDNS');
    }

    // ============================================
    // MOTD (Message of the Day)
    // ============================================

    /**
     * Get current MOTD
     */
    public function motd(Request $request): Response
    {
        return $this->agentAction('system.motd');
    }

    /**
     * Update MOTD (super_admin only)
     */
    public function updateMotd(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $type = $request->input('type', 'static');
        
        $params = [
            'type' => $type,
        ];

        if ($type === 'static') {
            $validation = $this->validateRequired($request, ['content']);
            if ($validation) return $validation;
            $params['content'] = $request->input('content');
        } elseif ($type === 'script') {
            $validation = $this->validateRequired($request, ['name', 'content']);
            if ($validation) return $validation;
            $params['name'] = $request->input('name');
            $params['content'] = $request->input('content');
        }

        $result = $this->agent->execute('system.updateMotd', $params, $this->getActor());

        if ($result['success']) {
            $this->logAction('system.motd.update', $type, 'success');
            return Response::success($result['data'], $result['message'] ?? 'MOTD updated');
        }

        $this->logAction('system.motd.update', $type, 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to update MOTD');
    }

    // ============================================
    // HTML Templates
    // ============================================

    /**
     * List all available templates
     */
    public function templates(Request $request): Response
    {
        return $this->agentAction('system.templates');
    }

    /**
     * Get a specific template
     */
    public function getTemplate(Request $request): Response
    {
        $id = $request->getParam('id');
        
        return $this->agentAction('system.getTemplate', ['id' => $id]);
    }

    /**
     * Update a template (super_admin only)
     */
    public function updateTemplate(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $id = $request->getParam('id');
        
        $validation = $this->validateRequired($request, ['content']);
        if ($validation) return $validation;

        $result = $this->agent->execute('system.updateTemplate', [
            'id' => $id,
            'content' => $request->input('content'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.template.update', $id, 'success');
            return Response::success($result['data'], $result['message'] ?? 'Template saved');
        }

        $this->logAction('system.template.update', $id, 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to save template');
    }

    /**
     * Apply a template to a specific site
     */
    public function applyTemplateToSite(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $id = $request->getParam('id');
        
        $validation = $this->validateRequired($request, ['domain']);
        if ($validation) return $validation;

        $result = $this->agent->execute('system.applyTemplateToSite', [
            'template_id' => $id,
            'domain' => $request->input('domain'),
            'filename' => $request->input('filename', 'index.html'),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate sites cache so template status shows immediately
            $this->cache->delete('sites:list');
            $this->logAction('system.template.apply', $id, 'success', [
                'domain' => $request->input('domain')
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Template applied');
        }

        $this->logAction('system.template.apply', $id, 'failed', [
            'domain' => $request->input('domain'),
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to apply template');
    }

    /**
     * Deploy a template to all sites
     */
    public function deployTemplateToAllSites(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $id = $request->getParam('id');

        $result = $this->agent->execute('system.deployTemplateToAllSites', [
            'template_id' => $id,
            'filename' => $request->input('filename', 'index.html'),
            'skip_existing' => $request->input('skip_existing', true),
        ], $this->getActor());
        
        if ($result['success']) {
            // Invalidate sites cache so template status shows immediately
            $this->cache->delete('sites:list');
        }

        if ($result['success']) {
            $this->logAction('system.template.deployAll', $id, 'success', [
                'deployed' => count($result['data']['deployed'] ?? [])
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Template deployed');
        }

        $this->logAction('system.template.deployAll', $id, 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to deploy template');
    }

    /**
     * List sites available for template deployment
     */
    public function listSitesForTemplate(Request $request): Response
    {
        return $this->agentAction('system.listSitesForTemplate');
    }

    /**
     * List template backups for a site
     */
    public function listTemplateBackups(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $domain = $request->getParam('domain');

        $result = $this->agent->execute('system.listTemplateBackups', [
            'domain' => $domain,
            'filename' => $request->getQuery('filename', 'index.html'),
        ], $this->getActor());

        if ($result['success']) {
            return Response::success($result['data']);
        }

        return Response::error($result['error'] ?? 'Failed to list backups');
    }

    /**
     * Revert template - restore backup
     */
    public function revertTemplate(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $domain = $request->getParam('domain');

        $result = $this->agent->execute('system.revertTemplate', [
            'domain' => $domain,
            'filename' => $request->input('filename', 'index.html'),
            'backup_file' => $request->input('backup_file'),
            'remove_backup' => $request->input('remove_backup', false),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate sites cache so template status updates immediately
            $this->cache->delete('sites:list');
            $this->logAction('system.template.revert', $domain, 'success');
            return Response::success($result['data'], $result['message'] ?? 'Template reverted');
        }

        $this->logAction('system.template.revert', $domain, 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to revert template');
    }

    /**
     * Get all active template deployments
     */
    public function getTemplateDeployments(Request $request): Response
    {
        $result = $this->agent->execute('system.getTemplateDeployments', [
            'template_type' => $request->getQuery('template_type'),
        ], $this->getActor());

        if ($result['success']) {
            return Response::success($result['data']);
        }

        return Response::error($result['error'] ?? 'Failed to get deployments');
    }

    // ============================================
    // Config File Permissions
    // ============================================

    /**
     * Check config file permissions for services
     */
    public function checkPermissions(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $service = $request->getParam('service');

        $result = $this->agent->execute('system.checkPermissions', [
            'service' => $service,
        ], $this->getActor());

        if ($result['success']) {
            return Response::success($result['data']);
        }

        return Response::error($result['error'] ?? 'Failed to check permissions');
    }

    /**
     * Fix config file permissions for a service (super_admin only)
     */
    public function fixPermissions(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $service = $request->getParam('service');
        if (!$service) {
            return Response::error('Service parameter is required');
        }

        $result = $this->agent->execute('system.fixPermissions', [
            'service' => $service,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('system.permissions.fix', $service, 'success');
            return Response::success($result['data'], $result['message'] ?? 'Permissions fixed');
        }

        $this->logAction('system.permissions.fix', $service, 'failed', [
            'error' => $result['error']
        ]);
        return Response::error($result['error'] ?? 'Failed to fix permissions');
    }

    /**
     * Check config file syntax
     */
    public function syntaxCheck(Request $request): Response
    {
        // Any authenticated user can check syntax (it's a safe read-only operation)

        $validation = $this->validateRequired($request, ['service']);
        if ($validation) return $validation;

        // Support base64 encoded content to bypass WAF/ModSecurity
        $content = null;
        if ($request->input('content_b64')) {
            $content = base64_decode($request->input('content_b64'));
            if ($content === false) {
                return Response::error('Invalid base64 content');
            }
        } elseif ($request->input('content')) {
            $content = $request->input('content');
        }

        $result = $this->agent->execute('system.syntaxCheck', [
            'service' => $request->input('service'),
            'content' => $content, // Optional: check specific content
        ], $this->getActor());

        if ($result['success']) {
            return Response::success($result['data']);
        }

        return Response::error($result['error'] ?? 'Syntax check failed');
    }
}

