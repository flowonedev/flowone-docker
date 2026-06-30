<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Container;
use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\AuditService;
use FleetManager\Api\Services\EncryptionService;

/**
 * Base controller with common functionality
 */
abstract class BaseController
{
    protected Container $container;
    protected AuditService $audit;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->audit = $container->get(AuditService::class);
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
     * Get current server (for agent requests)
     */
    protected function getCurrentServer(): ?object
    {
        try {
            return $this->container->get('current_server');
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
        return $user ? $user->username : 'system';
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
            'offset' => ($page - 1) * $perPage,
        ];
    }

    /**
     * Log action to audit
     */
    protected function logAction(
        string $action,
        ?int $serverId = null,
        ?string $target = null,
        string $outcome = 'success',
        array $details = []
    ): void {
        $user = $this->getCurrentUser();
        
        $this->audit->log(
            $action,
            $user ? $user->sub : null,
            $serverId,
            $target,
            $outcome,
            $details
        );
    }

    /**
     * Check if current user is super_admin
     */
    protected function isSuperAdmin(): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        return ($user->role ?? 'admin') === 'super_admin';
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
     * Get encryption service
     */
    protected function getEncryption(): EncryptionService
    {
        return $this->container->get(EncryptionService::class);
    }

    /**
     * Get database connection
     */
    protected function getDatabase(): \PDO
    {
        return $this->container->getDatabase();
    }
}

