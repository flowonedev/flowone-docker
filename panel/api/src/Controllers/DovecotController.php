<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class DovecotController extends BaseController
{
    /**
     * Get Dovecot status
     */
    public function status(Request $request): Response
    {
        return $this->agentAction('dovecot.status');
    }

    /**
     * Get Dovecot settings
     */
    public function settings(Request $request): Response
    {
        return $this->agentAction('dovecot.settings');
    }

    /**
     * Update Dovecot settings
     */
    public function updateSettings(Request $request): Response
    {
        $settings = $request->input('settings', []);
        
        $result = $this->agent->execute('dovecot.updateSettings', [
            'settings' => $settings,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('dovecot.settings', 'global', 'success', ['settings' => array_keys($settings)]);
            return Response::success($result['data'], $result['message'] ?? 'Settings updated');
        }
        
        $this->logAction('dovecot.settings', 'global', 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Restart Dovecot
     */
    public function restart(Request $request): Response
    {
        $result = $this->agent->execute('dovecot.restart', [], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('dovecot.restart', 'global', 'success');
            return Response::success($result['data'], $result['message'] ?? 'Restarted');
        }
        
        $this->logAction('dovecot.restart', 'global', 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Get active connections
     */
    public function connections(Request $request): Response
    {
        return $this->agentAction('dovecot.connections');
    }

    /**
     * Get raw Dovecot config file
     */
    public function rawConfig(Request $request): Response
    {
        $file = $request->input('file', '/etc/dovecot/dovecot.conf');
        
        $result = $this->agent->execute('dovecot.rawConfig', [
            'file' => $file,
        ], $this->getActor());
        
        if ($result['success']) {
            return Response::success($result['data']);
        }
        
        return Response::error($result['error']);
    }

    /**
     * Save raw Dovecot config file
     */
    public function saveRawConfig(Request $request): Response
    {
        $file = $request->input('file', '/etc/dovecot/dovecot.conf');
        
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
        
        if (empty($content)) {
            return Response::error('Content is required');
        }
        
        $result = $this->agent->execute('dovecot.saveRawConfig', [
            'file' => $file,
            'content' => $content,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('dovecot.rawConfig', $file, 'success');
            return Response::success($result['data'], $result['message'] ?? 'Configuration saved');
        }
        
        $this->logAction('dovecot.rawConfig', $file, 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }
}

