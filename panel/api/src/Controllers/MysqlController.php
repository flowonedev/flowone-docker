<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class MysqlController extends BaseController
{
    /**
     * Get MySQL status
     */
    public function status(Request $request): Response
    {
        return $this->agentAction('mysql.status');
    }

    /**
     * Get MySQL settings
     */
    public function settings(Request $request): Response
    {
        return $this->agentAction('mysql.settings');
    }

    /**
     * Update MySQL settings
     */
    public function updateSettings(Request $request): Response
    {
        $settings = $request->input('settings', []);
        
        $result = $this->agent->execute('mysql.updateSettings', [
            'settings' => $settings,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('mysql.settings', 'global', 'success', ['settings' => array_keys($settings)]);
            return Response::success($result['data'], $result['message'] ?? 'Settings updated');
        }
        
        $this->logAction('mysql.settings', 'global', 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Restart MySQL
     */
    public function restart(Request $request): Response
    {
        $result = $this->agent->execute('mysql.restart', [], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('mysql.restart', 'global', 'success');
            return Response::success($result['data'], $result['message'] ?? 'Restarted');
        }
        
        $this->logAction('mysql.restart', 'global', 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Get raw MySQL config file
     */
    public function rawConfig(Request $request): Response
    {
        $file = $request->input('file');
        
        $result = $this->agent->execute('mysql.rawConfig', [
            'file' => $file,
        ], $this->getActor());
        
        if ($result['success']) {
            return Response::success($result['data']);
        }
        
        return Response::error($result['error']);
    }

    /**
     * Save raw MySQL config file
     */
    public function saveRawConfig(Request $request): Response
    {
        $file = $request->input('file');
        $content = $request->input('content');
        $contentB64 = $request->input('content_b64');
        
        // Decode base64 content if provided
        if ($contentB64) {
            $content = base64_decode($contentB64);
            if ($content === false) {
                return Response::error('Invalid Base64 content provided');
            }
        }
        
        if (empty($content)) {
            return Response::error('Content is required');
        }
        
        $result = $this->agent->execute('mysql.saveRawConfig', [
            'file' => $file,
            'content' => $content,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('mysql.rawConfig', 'global', 'success', ['file' => $file]);
            return Response::success($result['data'], $result['message'] ?? 'Configuration saved');
        }
        
        $this->logAction('mysql.rawConfig', 'global', 'failed', ['file' => $file, 'error' => $result['error']]);
        return Response::error($result['error']);
    }
}

