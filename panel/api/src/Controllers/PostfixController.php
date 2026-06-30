<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class PostfixController extends BaseController
{
    /**
     * Get Postfix status
     */
    public function status(Request $request): Response
    {
        return $this->agentAction('postfix.status');
    }

    /**
     * Get Postfix settings
     */
    public function settings(Request $request): Response
    {
        return $this->agentAction('postfix.settings');
    }

    /**
     * Update Postfix settings
     */
    public function updateSettings(Request $request): Response
    {
        $settings = $request->input('settings', []);
        
        $result = $this->agent->execute('postfix.updateSettings', [
            'settings' => $settings,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('postfix.settings', 'global', 'success', ['settings' => array_keys($settings)]);
            return Response::success($result['data'], $result['message'] ?? 'Settings updated');
        }
        
        $this->logAction('postfix.settings', 'global', 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Restart Postfix
     */
    public function restart(Request $request): Response
    {
        $result = $this->agent->execute('postfix.restart', [], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('postfix.restart', 'global', 'success');
            return Response::success($result['data'], $result['message'] ?? 'Restarted');
        }
        
        $this->logAction('postfix.restart', 'global', 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Flush mail queue
     */
    public function flush(Request $request): Response
    {
        $result = $this->agent->execute('postfix.flush', [], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('postfix.flush', 'queue', 'success');
            return Response::success($result['data'], $result['message'] ?? 'Queue flushed');
        }
        
        $this->logAction('postfix.flush', 'queue', 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Get mail queue
     */
    public function queue(Request $request): Response
    {
        return $this->agentAction('postfix.queue');
    }

    /**
     * Get raw Postfix config file
     */
    public function rawConfig(Request $request): Response
    {
        $file = $request->input('file', '/etc/postfix/main.cf');
        
        $result = $this->agent->execute('postfix.rawConfig', [
            'file' => $file,
        ], $this->getActor());
        
        if ($result['success']) {
            return Response::success($result['data']);
        }
        
        return Response::error($result['error']);
    }

    /**
     * Save raw Postfix config file
     */
    public function saveRawConfig(Request $request): Response
    {
        $file = $request->input('file', '/etc/postfix/main.cf');
        
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
        
        $result = $this->agent->execute('postfix.saveRawConfig', [
            'file' => $file,
            'content' => $content,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('postfix.rawConfig', $file, 'success');
            return Response::success($result['data'], $result['message'] ?? 'Configuration saved');
        }
        
        $this->logAction('postfix.rawConfig', $file, 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }
}

