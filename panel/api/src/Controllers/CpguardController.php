<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class CpguardController extends BaseController
{
    // ============================================
    // Status & Statistics
    // ============================================

    /**
     * Get CPGuard status and statistics
     */
    public function status(Request $request): Response
    {
        return $this->agentAction('cpguard.status');
    }

    /**
     * Get WAF status
     */
    public function wafStatus(Request $request): Response
    {
        return $this->agentAction('cpguard.wafStatus');
    }

    /**
     * Get block statistics
     */
    public function stats(Request $request): Response
    {
        return $this->agentAction('cpguard.stats');
    }

    // ============================================
    // Installation & License Management
    // ============================================

    /**
     * Install CPGuard with license key
     */
    public function install(Request $request): Response
    {
        $licenseKey = $request->input('license_key');
        
        if (empty($licenseKey)) {
            return Response::error('License key is required', 400);
        }
        
        return $this->agentAction('cpguard.install', [
            'license_key' => $licenseKey,
        ]);
    }

    /**
     * Uninstall CPGuard
     */
    public function uninstall(Request $request): Response
    {
        return $this->agentAction('cpguard.uninstall');
    }

    /**
     * Get license information
     */
    public function getLicense(Request $request): Response
    {
        return $this->agentAction('cpguard.getLicense');
    }

    /**
     * Update/renew license key
     */
    public function updateLicense(Request $request): Response
    {
        $licenseKey = $request->input('license_key');
        
        if (empty($licenseKey)) {
            return Response::error('License key is required', 400);
        }
        
        return $this->agentAction('cpguard.updateLicense', [
            'license_key' => $licenseKey,
        ]);
    }

    // ============================================
    // Whitelist / Blacklist Management
    // ============================================

    /**
     * Get all whitelists and blacklists
     */
    public function getLists(Request $request): Response
    {
        return $this->agentAction('cpguard.getLists');
    }

    /**
     * Add IP to whitelist
     */
    public function addWhitelistIp(Request $request): Response
    {
        $ip = $request->input('ip');
        
        if (empty($ip)) {
            return Response::error('IP address is required', 400);
        }
        
        return $this->agentAction('cpguard.addToWhitelist', [
            'type' => 'ip',
            'value' => $ip,
        ]);
    }

    /**
     * Remove IP from whitelist
     */
    public function removeWhitelistIp(Request $request, string $ip): Response
    {
        return $this->agentAction('cpguard.removeFromWhitelist', [
            'type' => 'ip',
            'value' => urldecode($ip),
        ]);
    }

    /**
     * Add domain to whitelist
     */
    public function addWhitelistDomain(Request $request): Response
    {
        $domain = $request->input('domain');
        
        if (empty($domain)) {
            return Response::error('Domain is required', 400);
        }
        
        return $this->agentAction('cpguard.addToWhitelist', [
            'type' => 'domain',
            'value' => $domain,
        ]);
    }

    /**
     * Remove domain from whitelist
     */
    public function removeWhitelistDomain(Request $request, string $domain): Response
    {
        return $this->agentAction('cpguard.removeFromWhitelist', [
            'type' => 'domain',
            'value' => urldecode($domain),
        ]);
    }

    /**
     * Add IP to blacklist
     */
    public function addBlacklistIp(Request $request): Response
    {
        $ip = $request->input('ip');
        
        if (empty($ip)) {
            return Response::error('IP address is required', 400);
        }
        
        return $this->agentAction('cpguard.addToBlacklist', [
            'type' => 'ip',
            'value' => $ip,
        ]);
    }

    /**
     * Remove IP from blacklist
     */
    public function removeBlacklistIp(Request $request, string $ip): Response
    {
        return $this->agentAction('cpguard.removeFromBlacklist', [
            'type' => 'ip',
            'value' => urldecode($ip),
        ]);
    }

    /**
     * Add file path to blacklist
     */
    public function addBlacklistFile(Request $request): Response
    {
        $file = $request->input('file');
        
        if (empty($file)) {
            return Response::error('File path is required', 400);
        }
        
        return $this->agentAction('cpguard.addToBlacklist', [
            'type' => 'file',
            'value' => $file,
        ]);
    }

    /**
     * Remove file path from blacklist
     */
    public function removeBlacklistFile(Request $request): Response
    {
        $file = $request->input('file');
        
        if (empty($file)) {
            return Response::error('File path is required', 400);
        }
        
        return $this->agentAction('cpguard.removeFromBlacklist', [
            'type' => 'file',
            'value' => $file,
        ]);
    }

    // ============================================
    // Configuration Management
    // ============================================

    /**
     * Get full CPGuard configuration
     */
    public function getConfig(Request $request): Response
    {
        return $this->agentAction('cpguard.getConfig');
    }

    /**
     * Update CPGuard configuration
     */
    public function updateConfig(Request $request): Response
    {
        $settings = $request->input('settings');
        
        if (empty($settings) || !is_array($settings)) {
            return Response::error('Settings array is required', 400);
        }
        
        return $this->agentAction('cpguard.updateConfig', [
            'settings' => $settings,
        ]);
    }

    /**
     * Toggle a CPGuard module on/off
     */
    public function toggleModule(Request $request): Response
    {
        $module = $request->input('module');
        $enabled = $request->input('enabled', true);
        
        if (empty($module)) {
            return Response::error('Module name is required', 400);
        }
        
        return $this->agentAction('cpguard.toggleModule', [
            'module' => $module,
            'enabled' => $enabled,
        ]);
    }

    // ============================================
    // Service Management
    // ============================================

    /**
     * Restart CPGuard service
     */
    public function restartService(Request $request): Response
    {
        $action = $request->input('action', 'restart');
        
        return $this->agentAction('cpguard.restartService', [
            'action' => $action,
        ]);
    }

    /**
     * Trigger a manual malware scan
     */
    public function triggerScan(Request $request): Response
    {
        $path = $request->input('path', '/home');
        $background = $request->input('background', true);
        
        return $this->agentAction('cpguard.triggerScan', [
            'path' => $path,
            'background' => $background,
        ]);
    }
}
