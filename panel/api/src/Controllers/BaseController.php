<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Container;
use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;
use VpsAdmin\Api\Services\AgentService;
use VpsAdmin\Api\Services\AuditService;
use VpsAdmin\Api\Services\CacheService;

/**
 * Base controller with common functionality
 */
abstract class BaseController
{
    protected Container $container;
    protected AgentService $agent;
    protected AuditService $audit;
    protected CacheService $cache;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->agent = $container->get(AgentService::class);
        $this->audit = $container->get(AuditService::class);
        $this->cache = $container->get(CacheService::class);
    }

    /**
     * Get current authenticated user
     */
    protected function getCurrentUser(): ?object
    {
        try {
            return $this->container->get('current_user');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get username for audit logging
     */
    protected function getActor(): string
    {
        $user = $this->getCurrentUser();
        return $user ? $user->username : 'api';
    }

    /**
     * Execute agent action and return response
     */
    protected function agentAction(string $action, array $params = []): Response
    {
        $result = $this->agent->execute($action, $params, $this->getActor());
        
        if ($result['success']) {
            return Response::success($result['data'] ?? null, $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Action failed');
    }

    /**
     * Call agent and return raw result array (for custom handling)
     */
    protected function callAgent(string $action, array $params = []): array
    {
        return $this->agent->execute($action, $params, $this->getActor());
    }

    /**
     * Validate required fields
     */
    protected function validateRequired(Request $request, array $fields): ?Response
    {
        $errors = [];
        
        foreach ($fields as $field) {
            if ($request->input($field) === null || $request->input($field) === '') {
                $errors[$field] = "{$field} is required";
            }
        }
        
        if (!empty($errors)) {
            return Response::validationError($errors);
        }
        
        return null;
    }

    /**
     * Get pagination parameters from request
     */
    protected function getPagination(Request $request): array
    {
        $page = max(1, (int)$request->getQuery('page', 1));
        $perPage = min(100, max(1, (int)$request->getQuery('per_page', 50)));
        
        return [
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Log action to audit
     */
    protected function logAction(
        string $action,
        string $target,
        string $outcome,
        array $details = [],
        ?string $backupPath = null,
        ?string $diff = null
    ): void {
        $this->audit->log(
            $action,
            $this->getActor(),
            $target,
            $outcome,
            $details,
            $backupPath,
            $diff
        );
    }

    /**
     * Check if current user has a specific role
     */
    protected function hasRole(string $role): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        return ($user->role ?? 'user') === $role;
    }

    /**
     * Check if current user is super_admin
     */
    protected function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Check if current user is admin or super_admin
     */
    protected function isAdmin(): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        $role = strtolower($user->role ?? 'user');
        return in_array($role, ['admin', 'super_admin']);
    }

    /**
     * Require admin or super_admin role, return error response if not
     */
    protected function requireAdmin(): ?Response
    {
        if (!$this->isAdmin()) {
            return Response::error('Access denied. Admin required.', 403);
        }
        return null;
    }

    /**
     * Require super_admin role, return error response if not
     */
    protected function requireSuperAdmin(): ?Response
    {
        if (!$this->isSuperAdmin()) {
            return Response::error('Access denied. Super admin required.', 403);
        }
        return null;
    }

    /**
     * Get sites the current user has access to (null = all sites for super_admin)
     */
    protected function getAllowedSites(): ?array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return [];
        }
        
        // Super admins have access to all sites
        if ($this->isSuperAdmin()) {
            return null;
        }
        
        // Regular users have access to assigned sites only
        $db = $this->container->getDatabase();
        $stmt = $db->prepare("SELECT domain FROM user_sites WHERE user_id = ?");
        $stmt->execute([$user->sub]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Check if current user can access a specific site
     */
    protected function canAccessSite(string $domain): bool
    {
        $allowedSites = $this->getAllowedSites();
        
        // null means all sites (super_admin)
        if ($allowedSites === null) {
            return true;
        }
        
        return in_array($domain, $allowedSites);
    }
}

