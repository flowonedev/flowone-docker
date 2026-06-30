<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class AppController extends BaseController
{
    /**
     * Get available application templates
     */
    public function templates(Request $request): Response
    {
        $result = $this->agent->execute('app.templates', [], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get templates');
        }
        
        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * List all installed applications (filtered by user permissions)
     */
    public function index(Request $request): Response
    {
        $domain = $request->getQuery('domain');
        
        $params = [];
        if ($domain) {
            // Check if user can access this site
            if (!$this->canAccessSite($domain)) {
                return Response::error('Access denied to this site', 403);
            }
            $params['domain'] = $domain;
        }
        
        $result = $this->agent->execute('app.list', $params, $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list applications');
        }
        
        // Filter by allowed sites for non-admin users
        $allowedSites = $this->getAllowedSites();
        if ($allowedSites !== null) {
            $apps = $result['data']['applications'] ?? [];
            $apps = array_values(array_filter($apps, function($app) use ($allowedSites) {
                return in_array($app['domain'], $allowedSites);
            }));
            $result['data']['applications'] = $apps;
        }
        
        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Get applications for a specific site
     */
    public function siteApps(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $result = $this->agent->execute('app.list', ['domain' => $domain], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list applications');
        }
        
        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Install an application
     */
    public function install(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['domain', 'app_slug']);
        if ($validation) return $validation;
        
        $domain = $request->input('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $params = [
            'domain' => $domain,
            'app_slug' => $request->input('app_slug'),
            'admin_email' => $request->input('admin_email'),
            'admin_user' => $request->input('admin_user', 'admin'),
            'admin_password' => $request->input('admin_password'),
            'site_title' => $request->input('site_title', $domain),
            'db_name' => $request->input('db_name'),
            'db_user' => $request->input('db_user'),
            'db_password' => $request->input('db_password'),
        ];
        
        $result = $this->agent->execute('app.install', $params, $this->getActor());
        
        // Log the action with proper outcome
        $this->logAction('app.install', $domain, $result['success'] ? 'success' : 'failed', [
            'app_slug' => $params['app_slug'],
            'error' => $result['error'] ?? null,
        ]);
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to install application');
        }
        
        return Response::success($result['data'], $result['message'] ?? 'Application installed successfully');
    }

    /**
     * Uninstall an application
     */
    public function uninstall(Request $request): Response
    {
        $appId = $request->getParam('id');
        
        if (!$appId) {
            return Response::error('Application ID is required');
        }
        
        // Get the app details first to check access
        $statusResult = $this->agent->execute('app.status', ['app_id' => $appId], $this->getActor());
        
        if (!$statusResult['success']) {
            return Response::error($statusResult['error'] ?? 'Application not found');
        }
        
        $app = $statusResult['data']['application'] ?? null;
        if (!$app) {
            return Response::error('Application not found');
        }
        
        // Check if user can access this site
        if (!$this->canAccessSite($app['domain'])) {
            return Response::error('Access denied to this site', 403);
        }
        
        $params = [
            'app_id' => $appId,
            'keep_files' => $request->input('keep_files', false),
            'keep_database' => $request->input('keep_database', false),
        ];
        
        $result = $this->agent->execute('app.uninstall', $params, $this->getActor());
        
        // Log the action with proper outcome
        $this->logAction('app.uninstall', $app['domain'], $result['success'] ? 'success' : 'failed', [
            'app_id' => $appId,
            'app_slug' => $app['app_slug'],
            'error' => $result['error'] ?? null,
        ]);
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to uninstall application');
        }
        
        return Response::success($result['data'], $result['message'] ?? 'Application uninstalled successfully');
    }

    /**
     * Get application status
     */
    public function status(Request $request): Response
    {
        $appId = $request->getParam('id');
        
        if (!$appId) {
            return Response::error('Application ID is required');
        }
        
        $result = $this->agent->execute('app.status', ['app_id' => $appId], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get application status');
        }
        
        $app = $result['data']['application'] ?? null;
        if ($app) {
            // Check if user can access this site
            if (!$this->canAccessSite($app['domain'])) {
                return Response::error('Access denied to this site', 403);
            }
        }
        
        return Response::success($result['data'], $result['message'] ?? 'Success');
    }
}

