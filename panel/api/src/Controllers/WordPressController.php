<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class WordPressController extends BaseController
{
    /**
     * Determine appropriate HTTP status code for agent errors
     */
    private function getErrorStatusCode(string $error): int
    {
        $notFoundPatterns = ['not found', 'could not find', 'does not exist'];
        $errorLower = strtolower($error);
        
        foreach ($notFoundPatterns as $pattern) {
            if (str_contains($errorLower, $pattern)) {
                return 404;
            }
        }
        
        return 400;
    }

    /**
     * Check if an agent error indicates WordPress is not installed
     */
    private function isWordPressNotFound(array $result): bool
    {
        if ($result['success']) {
            return false;
        }
        $error = strtolower($result['error'] ?? '');
        return str_contains($error, 'not found') || str_contains($error, 'does not exist');
    }

    /**
     * Get WordPress installation info for a site
     */
    public function info(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.info', ['domain' => $domain], $this->getActor());

        if (!$result['success']) {
            // WordPress not installed is not an error -- return clean "not installed" response
            if ($this->isWordPressNotFound($result)) {
                return Response::success([
                    'installed' => false,
                    'domain' => $domain,
                ], 'WordPress not installed');
            }
            $error = $result['error'] ?? 'Failed to get WordPress info';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Get all plugins for a WordPress site
     */
    public function plugins(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.plugins', ['domain' => $domain], $this->getActor());

        if (!$result['success']) {
            if ($this->isWordPressNotFound($result)) {
                return Response::success(['plugins' => []], 'WordPress not installed');
            }
            $error = $result['error'] ?? 'Failed to get plugins';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Update a single plugin
     */
    public function updatePlugin(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $plugin = $request->input('plugin');
        
        if (!$domain || !$plugin) {
            return Response::error('Domain and plugin name are required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.updatePlugin', [
            'domain' => $domain,
            'plugin' => $plugin,
        ], $this->getActor());

        $this->logAction('wordpress.updatePlugin', $domain, $result['success'] ? 'success' : 'failed', [
            'plugin' => $plugin,
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to update plugin';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Plugin updated successfully');
    }

    /**
     * Update all plugins
     */
    public function updateAllPlugins(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.updateAllPlugins', [
            'domain' => $domain,
        ], $this->getActor());

        $this->logAction('wordpress.updateAllPlugins', $domain, $result['success'] ? 'success' : 'failed', [
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to update plugins';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'All plugins updated successfully');
    }

    /**
     * Update all themes
     */
    public function updateAllThemes(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.updateAllThemes', [
            'domain' => $domain,
        ], $this->getActor());

        $this->logAction('wordpress.updateAllThemes', $domain, $result['success'] ? 'success' : 'failed', [
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to update themes';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'All themes updated successfully');
    }

    /**
     * Update WordPress core
     */
    public function updateCore(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.updateCore', [
            'domain' => $domain,
        ], $this->getActor());

        $this->logAction('wordpress.updateCore', $domain, $result['success'] ? 'success' : 'failed', [
            'old_version' => $result['data']['old_version'] ?? null,
            'new_version' => $result['data']['version'] ?? null,
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to update WordPress core';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'WordPress core updated successfully');
    }

    /**
     * Update everything: core, plugins, and themes
     */
    public function updateAll(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.updateAll', [
            'domain' => $domain,
        ], $this->getActor());

        $this->logAction('wordpress.updateAll', $domain, $result['success'] ? 'success' : 'partial', [
            'core' => $result['data']['core'] ?? null,
            'plugins' => $result['data']['plugins']['success'] ?? null,
            'themes' => $result['data']['themes']['success'] ?? null,
            'errors' => $result['data']['errors'] ?? [],
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to update WordPress';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'WordPress fully updated');
    }

    /**
     * Get all themes
     */
    public function themes(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.themes', ['domain' => $domain], $this->getActor());

        if (!$result['success']) {
            if ($this->isWordPressNotFound($result)) {
                return Response::success(['themes' => []], 'WordPress not installed');
            }
            $error = $result['error'] ?? 'Failed to get themes';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Get all users with pagination and filtering
     */
    public function users(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $params = [
            'domain' => $domain,
            'limit' => (int) ($request->getQuery('limit') ?? 10),
            'page' => (int) ($request->getQuery('page') ?? 1),
            'search' => $request->getQuery('search'),
            'role' => $request->getQuery('role'),
        ];

        $result = $this->agent->execute('wordpress.users', $params, $this->getActor());

        if (!$result['success']) {
            if ($this->isWordPressNotFound($result)) {
                return Response::success(['users' => []], 'WordPress not installed');
            }
            $error = $result['error'] ?? 'Failed to get users';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Get post type counts
     */
    public function posts(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.posts', ['domain' => $domain], $this->getActor());

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to get posts';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Set file permissions
     */
    public function permissions(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $target = $request->input('target', 'all'); // wp-config, htaccess, uploads, all
        $mode = $request->input('mode', 'secure'); // secure, normal
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.permissions', [
            'domain' => $domain,
            'target' => $target,
            'mode' => $mode,
        ], $this->getActor());

        $this->logAction('wordpress.permissions', $domain, $result['success'] ? 'success' : 'failed', [
            'target' => $target,
            'mode' => $mode,
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to set permissions';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Permissions updated');
    }

    /**
     * Secure WordPress files
     */
    public function secureFiles(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.secureFiles', [
            'domain' => $domain,
        ], $this->getActor());

        $this->logAction('wordpress.secureFiles', $domain, $result['success'] ? 'success' : 'failed', [
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to secure files';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Files secured successfully');
    }

    /**
     * Unlock WordPress files for editing (reverse of secureFiles)
     */
    public function unsecureFiles(Request $request): Response
    {
        $domain = $request->getParam('domain');

        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.unsecureFiles', [
            'domain' => $domain,
        ], $this->getActor());

        $this->logAction('wordpress.unsecureFiles', $domain, $result['success'] ? 'success' : 'failed', [
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to unlock files';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Files unlocked successfully');
    }

    /**
     * Enable/disable maintenance or development mode
     */
    public function maintenance(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $mode = $request->input('mode', 'enable'); // enable, disable
        $type = $request->input('type', 'development'); // maintenance, development
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.maintenance', [
            'domain' => $domain,
            'mode' => $mode,
            'type' => $type,
        ], $this->getActor());

        $this->logAction('wordpress.maintenance', $domain, $result['success'] ? 'success' : 'failed', [
            'mode' => $mode,
            'type' => $type,
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to set maintenance mode';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Maintenance mode updated');
    }

    /**
     * Get WordPress core info and update status
     */
    public function core(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.core', ['domain' => $domain], $this->getActor());

        if (!$result['success']) {
            if ($this->isWordPressNotFound($result)) {
                return Response::success(['installed' => false, 'updates' => []], 'WordPress not installed');
            }
            $error = $result['error'] ?? 'Failed to get core info';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Get database info
     */
    public function dbInfo(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.dbInfo', ['domain' => $domain], $this->getActor());

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to get database info';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Disable a WordPress user
     */
    public function disableUser(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $userId = $request->input('user_id');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }
        
        if (!$userId) {
            return Response::error('User ID is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.disableUser', [
            'domain' => $domain,
            'user_id' => $userId,
        ], $this->getActor());

        $this->logAction('wordpress.disableUser', $domain, $result['success'] ? 'success' : 'failed', [
            'user_id' => $userId,
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to disable user';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'User disabled successfully');
    }

    /**
     * Enable a WordPress user
     */
    public function enableUser(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $userId = $request->input('user_id');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }
        
        if (!$userId) {
            return Response::error('User ID is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.enableUser', [
            'domain' => $domain,
            'user_id' => $userId,
        ], $this->getActor());

        $this->logAction('wordpress.enableUser', $domain, $result['success'] ? 'success' : 'failed', [
            'user_id' => $userId,
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to enable user';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'User enabled successfully');
    }

    /**
     * Rename a WordPress user (change username)
     */
    public function renameUser(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $userId = $request->input('user_id');
        $newUsername = $request->input('new_username');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        if (!$userId) {
            return Response::error('User ID is required');
        }

        if (!$newUsername) {
            return Response::error('New username is required');
        }

        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('wordpress.renameUser', [
            'domain' => $domain,
            'user_id' => $userId,
            'new_username' => $newUsername,
        ], $this->getActor());

        $this->logAction('wordpress.renameUser', $domain, $result['success'] ? 'success' : 'failed', [
            'user_id' => $userId,
            'new_username' => $newUsername,
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Failed to rename user';
            return Response::error($error, $this->getErrorStatusCode($error));
        }

        return Response::success($result['data'], $result['message'] ?? 'User renamed successfully');
    }
}

