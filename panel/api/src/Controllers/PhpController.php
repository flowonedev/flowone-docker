<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class PhpController extends BaseController
{
    /**
     * Get installed PHP versions
     */
    public function versions(Request $request): Response
    {
        return $this->agentAction('php.versions');
    }

    /**
     * Get PHP settings for a version
     */
    public function settings(Request $request): Response
    {
        $version = $request->getParam('version');
        return $this->agentAction('php.settings', ['version' => $version]);
    }

    /**
     * Update PHP settings
     */
    public function updateSettings(Request $request): Response
    {
        $version = $request->getParam('version');
        $settings = $request->input('settings', []);
        
        $result = $this->agent->execute('php.updateSettings', [
            'version' => $version,
            'settings' => $settings,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('php.settings', $version, 'success', ['settings' => array_keys($settings)]);
            return Response::success($result['data'], $result['message'] ?? 'Settings updated');
        }
        
        $this->logAction('php.settings', $version, 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Restart PHP (LiteSpeed)
     */
    public function restart(Request $request): Response
    {
        $version = $request->getParam('version');
        
        $result = $this->agent->execute('php.restart', ['version' => $version], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('php.restart', $version, 'success');
            return Response::success($result['data'], $result['message'] ?? 'Restarted');
        }
        
        $this->logAction('php.restart', $version, 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }

    /**
     * Get raw PHP config file
     */
    public function rawConfig(Request $request): Response
    {
        $version = $request->getParam('version');
        $file = $request->getQuery('file'); // Optional: specific file path
        
        return $this->agentAction('php.rawConfig', [
            'version' => $version,
            'file' => $file,
        ]);
    }

    /**
     * Save raw PHP config file
     */
    public function saveRawConfig(Request $request): Response
    {
        $version = $request->getParam('version');
        $content = $request->input('content');
        $file = $request->input('file'); // Optional: specific file path
        
        if (empty($content)) {
            return Response::error('Content is required');
        }
        
        $result = $this->agent->execute('php.saveRawConfig', [
            'version' => $version,
            'content' => $content,
            'file' => $file,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('php.rawConfig', $version, 'success', ['file' => $file]);
            return Response::success($result['data'], $result['message'] ?? 'Configuration saved');
        }
        
        $this->logAction('php.rawConfig', $version, 'failed', ['error' => $result['error']]);
        return Response::error($result['error']);
    }
}

