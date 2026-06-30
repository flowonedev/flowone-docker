<?php

namespace Webmail\Addons\CrmPro\Controllers;

use Webmail\Controllers\BaseController;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\CrmPro\Services\CrmSharingService;

/**
 * CrmSharingController
 * 
 * API endpoints for CRM internal sharing: share with colleagues/groups,
 * list shares, revoke, update permissions.
 */
class CrmSharingController extends BaseController
{
    private CrmSharingService $sharingService;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->sharingService = new CrmSharingService($config);
    }

    // =========================================================================
    // List Shares
    // =========================================================================

    /**
     * GET /crm/sharing
     * Returns my shares (who I shared with) + shared with me
     */
    public function index(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $myShares = $this->sharingService->getMyShares($this->userEmail);
        $sharedWithMe = $this->sharingService->getSharedWithMe($this->userEmail);
        $accessibleOwners = $this->sharingService->getAccessibleCrmOwners($this->userEmail);

        return Response::success([
            'my_shares' => $myShares,
            'shared_with_me' => $sharedWithMe,
            'accessible_owners' => $accessibleOwners
        ]);
    }

    // =========================================================================
    // Share with Colleague
    // =========================================================================

    /**
     * POST /crm/sharing/colleague
     * Body: { shared_with_email, permission }
     */
    public function shareWithColleague(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $body = $request->input();
        if (empty($body['shared_with_email'])) {
            return Response::badRequest('shared_with_email is required');
        }

        $permission = $body['permission'] ?? 'viewer';
        if (!in_array($permission, ['viewer', 'editor', 'manager'])) {
            return Response::badRequest('Invalid permission level');
        }

        try {
            $share = $this->sharingService->shareWithColleague(
                $this->userEmail,
                $body['shared_with_email'],
                $permission
            );
            return Response::success(['share' => $share]);
        } catch (\InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        }
    }

    // =========================================================================
    // Share with Group
    // =========================================================================

    /**
     * POST /crm/sharing/group
     * Body: { group_id, permission }
     */
    public function shareWithGroup(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $body = $request->input();
        if (empty($body['group_id'])) {
            return Response::badRequest('group_id is required');
        }

        $permission = $body['permission'] ?? 'viewer';
        if (!in_array($permission, ['viewer', 'editor', 'manager'])) {
            return Response::badRequest('Invalid permission level');
        }

        try {
            $share = $this->sharingService->shareWithGroup(
                $this->userEmail,
                (int)$body['group_id'],
                $permission,
                $this->userEmail
            );
            return Response::success(['share' => $share]);
        } catch (\Exception $e) {
            return Response::error($e->getMessage());
        }
    }

    // =========================================================================
    // Update Permission
    // =========================================================================

    /**
     * PUT /crm/sharing/{id}
     * Body: { type: 'individual'|'group', permission }
     */
    public function updatePermission(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->param('id');
        $body = $request->input();
        $type = $body['type'] ?? 'individual';
        $permission = $body['permission'] ?? null;

        if (!$permission || !in_array($permission, ['viewer', 'editor', 'manager'])) {
            return Response::badRequest('Valid permission is required');
        }

        if ($type === 'group') {
            $this->sharingService->updateGroupPermission($id, $this->userEmail, $permission);
        } else {
            $this->sharingService->updateSharePermission($id, $this->userEmail, $permission);
        }

        return Response::success(['updated' => true]);
    }

    // =========================================================================
    // Revoke Share
    // =========================================================================

    /**
     * DELETE /crm/sharing/{id}
     * Query: ?type=individual|group
     */
    public function revoke(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->param('id');
        $type = $request->getQuery('type', 'individual');

        if ($type === 'group') {
            $this->sharingService->revokeGroupAccess($id, $this->userEmail);
        } else {
            $this->sharingService->revokeShare($id, $this->userEmail);
        }

        return Response::success(['revoked' => true]);
    }

    // =========================================================================
    // Check Access (for other controllers to use)
    // =========================================================================

    /**
     * GET /crm/sharing/check?owner=email@example.com
     * Check if current user can access another user's CRM
     */
    public function checkAccess(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $ownerEmail = $request->getQuery('owner');
        if (!$ownerEmail) {
            return Response::badRequest('owner email is required');
        }

        $permission = $this->sharingService->canAccessCrm($this->userEmail, $ownerEmail);

        return Response::success([
            'has_access' => $permission !== null,
            'permission' => $permission
        ]);
    }

    // =========================================================================
    // Activity Log
    // =========================================================================

    /**
     * GET /crm/sharing/activity
     */
    public function getActivity(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $limit = (int)($request->getQuery('limit') ?: 50);
        $activity = $this->sharingService->getActivity($this->userEmail, min($limit, 200));

        return Response::success(['activity' => $activity]);
    }
}

