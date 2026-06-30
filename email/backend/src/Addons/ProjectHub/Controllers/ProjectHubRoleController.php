<?php

namespace Webmail\Addons\ProjectHub\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\ProjectHub\Services\ProjectHubRoleService;
use Webmail\Addons\Team\Services\ColleagueService;

class ProjectHubRoleController extends BaseController
{
    private ?ProjectHubRoleService $roleService = null;

    private function getRoleService(): ProjectHubRoleService
    {
        if (!$this->roleService) {
            $this->roleService = new ProjectHubRoleService($this->config);
        }
        return $this->roleService;
    }

    private function requireAdminAuth(Request $request): ?Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $colleagueService = new ColleagueService($this->config);
        if (!$colleagueService->isAdmin($this->getActiveEmail())) {
            return Response::error('Admin access required', 403);
        }
        return null;
    }

    /** GET /project-hub/roles -- any authenticated user can read roles */
    public function getRoles(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            return Response::json(['roles' => $this->getRoleService()->getRoles()]);
        } catch (\Throwable $e) {
            error_log('getRoles error: ' . $e->getMessage());
            return Response::json(['roles' => [], 'error' => $e->getMessage()]);
        }
    }

    /** POST /project-hub/roles */
    public function createRole(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $required = $this->validateRequired($request, ['name']);
        if ($required) return $required;

        $role = $this->getRoleService()->createRole($request->all(), $this->getActiveEmail());
        return Response::json($role, 201);
    }

    /** PUT /project-hub/roles/{id} */
    public function updateRole(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $role = $this->getRoleService()->updateRole((int)$request->param('id'), $request->all());
        return $role ? Response::json($role) : Response::error('Role not found', 404);
    }

    /** DELETE /project-hub/roles/{id} */
    public function deleteRole(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $deleted = $this->getRoleService()->deleteRole((int)$request->param('id'));
        return $deleted ? Response::json(['success' => true]) : Response::error('Role not found', 404);
    }

    /** POST /project-hub/roles/reorder */
    public function reorderRoles(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $required = $this->validateRequired($request, ['ids']);
        if ($required) return $required;

        $this->getRoleService()->reorderRoles($request->input('ids'));
        return Response::json(['success' => true]);
    }

    /** GET /project-hub/roles/{id}/statuses -- any authenticated user can read statuses */
    public function getRoleStatuses(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $statuses = $this->getRoleService()->getRoleStatuses((int)$request->param('id'));
            return Response::json(['statuses' => $statuses]);
        } catch (\Throwable $e) {
            error_log('getRoleStatuses error: ' . $e->getMessage());
            return Response::json(['statuses' => [], 'error' => $e->getMessage()]);
        }
    }

    /** POST /project-hub/roles/{id}/statuses */
    public function createRoleStatus(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $required = $this->validateRequired($request, ['name']);
        if ($required) return $required;

        try {
            $status = $this->getRoleService()->createRoleStatus((int)$request->param('id'), $request->all());
            return $status ? Response::json($status, 201) : Response::error('Failed to create status', 500);
        } catch (\Throwable $e) {
            error_log('createRoleStatus error: ' . $e->getMessage());
            return Response::error('Failed to create status: ' . $e->getMessage(), 500);
        }
    }

    /** PUT /project-hub/role-statuses/{id} */
    public function updateRoleStatus(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $status = $this->getRoleService()->updateRoleStatus((int)$request->param('id'), $request->all());
        return $status ? Response::json($status) : Response::error('Status not found', 404);
    }

    /** DELETE /project-hub/role-statuses/{id} */
    public function deleteRoleStatus(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $deleted = $this->getRoleService()->deleteRoleStatus((int)$request->param('id'));
        return $deleted ? Response::json(['success' => true]) : Response::error('Status not found', 404);
    }

    /** POST /project-hub/roles/{id}/statuses/reorder */
    public function reorderRoleStatuses(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $required = $this->validateRequired($request, ['ids']);
        if ($required) return $required;

        $this->getRoleService()->reorderRoleStatuses((int)$request->param('id'), $request->input('ids'));
        return Response::json(['success' => true]);
    }

    /** GET /project-hub/users/{email}/roles -- any authenticated user can read user roles */
    public function getUserRoles(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $roles = $this->getRoleService()->getUserRoles($request->param('email'));
        return Response::json(['roles' => $roles]);
    }

    /** POST /project-hub/users/{email}/roles */
    public function assignUserRole(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $required = $this->validateRequired($request, ['role_id']);
        if ($required) return $required;

        $mapping = $this->getRoleService()->assignUserRole(
            $request->param('email'),
            (int)$request->input('role_id'),
            $this->getActiveEmail()
        );
        return Response::json($mapping, 201);
    }

    /** DELETE /project-hub/users/{email}/roles/{roleId} */
    public function removeUserRole(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        $deleted = $this->getRoleService()->removeUserRole(
            $request->param('email'),
            (int)$request->param('roleId')
        );
        return $deleted ? Response::json(['success' => true]) : Response::error('Not found', 404);
    }
}
