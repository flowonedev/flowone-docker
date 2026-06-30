<?php

namespace Webmail\Addons\ProjectHub\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\ProjectHub\Services\ProjectHubService;
use Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService;
use Webmail\Addons\ProjectHub\Services\ProjectHubRoleService;
use Webmail\Addons\ProjectHub\Services\ProjectHubNotificationService;
use Webmail\Addons\ProjectHub\Services\ProjectHubActivityService;
use Webmail\Services\RedisCacheService;

class ProjectHubController extends BaseController
{
    private ?ProjectHubService $hubService = null;
    private ?ProjectHubWorkTrackingService $workTrackingService = null;
    private ?ProjectHubRoleService $roleService = null;
    private ?ProjectHubNotificationService $notifService = null;
    private ?RedisCacheService $redisCache = null;
    private ?ProjectHubActivityService $activityService = null;

    // =========================================================================
    // Service Lazy Init
    // =========================================================================

    private function getHubService(): ProjectHubService
    {
        if (!$this->hubService) {
            $this->hubService = new ProjectHubService($this->config);
        }
        return $this->hubService;
    }

    private function getWorkTrackingService(): ProjectHubWorkTrackingService
    {
        if (!$this->workTrackingService) {
            $this->workTrackingService = new ProjectHubWorkTrackingService($this->config);
        }
        return $this->workTrackingService;
    }

    private function getNotifService(): ProjectHubNotificationService
    {
        if (!$this->notifService) {
            $this->notifService = new ProjectHubNotificationService($this->config);
        }
        return $this->notifService;
    }

    private function getRoleService(): ProjectHubRoleService
    {
        if (!$this->roleService) {
            $this->roleService = new ProjectHubRoleService($this->config);
        }
        return $this->roleService;
    }

    private function getRedisCache(): RedisCacheService
    {
        if (!$this->redisCache) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }

    private function getActivityService(): ProjectHubActivityService
    {
        if (!$this->activityService) {
            $this->activityService = new ProjectHubActivityService($this->config);
        }
        return $this->activityService;
    }

    private function logActivity(int $cardId, string $action, array $details = [], bool $skipBoardLog = false): void
    {
        try {
            $this->getActivityService()->log($cardId, $this->getActiveEmail(), $action, $details, $skipBoardLog);
        } catch (\Throwable $e) {
            error_log("PH activity log error: " . $e->getMessage());
        }
    }

    private function publishEvent(string $channel, array $data): void
    {
        try {
            $this->getRedisCache()->publish($channel, $data);
        } catch (\Throwable $e) {
            error_log("ProjectHub publish error: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Hierarchy -- full tree for sidebar
    // =========================================================================

    /** GET /project-hub/hierarchy */
    public function getHierarchy(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getActiveEmail();
        $data = $this->getHubService()->getFullHierarchy($userEmail);
        return Response::json($data);
    }

    // =========================================================================
    // Spaces CRUD
    // =========================================================================

    /** GET /project-hub/spaces */
    public function getSpaces(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $spaces = $this->getHubService()->getSpaces($this->getActiveEmail());
        return Response::json(['spaces' => $spaces]);
    }

    /** GET /project-hub/spaces/{id}/overview */
    public function getSpaceOverview(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $data = $this->getHubService()->getSpaceOverview($id, $this->getActiveEmail());
        return Response::json($data);
    }

    /** POST /project-hub/spaces */
    public function createSpace(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['name']);
        if ($required) return $required;

        try {
            $space = $this->getHubService()->createSpace($this->getActiveEmail(), [
                'name' => $request->input('name'),
                'color' => $request->input('color'),
                'icon' => $request->input('icon'),
            ]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 409);
        }

        if (!$space) {
            return Response::error('Failed to create space', 500);
        }

        $this->publishEvent('board_events', [
            'type' => 'SPACE_UPDATED',
            'action' => 'created',
            'space' => $space,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json($space, 201);
    }

    /** PUT /project-hub/spaces/{id} */
    public function updateSpace(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $body = $request->input() ?: [];
        $payload = [];
        foreach (['name', 'color', 'icon', 'sort_order', 'archived', 'client_id', 'is_favorite'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = $body[$field];
            }
        }
        $space = $this->getHubService()->updateSpace($id, $payload);

        $this->publishEvent('board_events', [
            'type' => 'SPACE_UPDATED',
            'action' => 'updated',
            'space' => $space,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json($space);
    }

    /** DELETE /project-hub/spaces/{id} */
    public function deleteSpace(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $this->getHubService()->deleteSpace($id);

        $this->publishEvent('board_events', [
            'type' => 'SPACE_UPDATED',
            'action' => 'deleted',
            'space_id' => $id,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json(['success' => true]);
    }

    /** POST /project-hub/spaces/reorder */
    public function reorderSpaces(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $ids = $request->input('ids');
        if (!is_array($ids)) {
            return Response::error('ids must be an array', 400);
        }

        $this->getHubService()->reorderSpaces($this->getActiveEmail(), $ids);
        return Response::json(['success' => true]);
    }

    // =========================================================================
    // Folders CRUD
    // =========================================================================

    /** GET /project-hub/spaces/{id}/folders */
    public function getFolders(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $spaceId = (int)$request->param('id');
        $folders = $this->getHubService()->getFolders($spaceId);
        return Response::json(['folders' => $folders]);
    }

    /** POST /project-hub/spaces/{id}/folders */
    public function createFolder(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['name']);
        if ($required) return $required;

        $spaceId = (int)$request->param('id');
        $folder = $this->getHubService()->createFolder($spaceId, $this->getActiveEmail(), [
            'name' => $request->input('name'),
            'color' => $request->input('color'),
            'icon' => $request->input('icon'),
        ]);

        if (!$folder) {
            return Response::error('Failed to create folder', 500);
        }

        $this->publishEvent('board_events', [
            'type' => 'FOLDER_UPDATED',
            'action' => 'created',
            'folder' => $folder,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json($folder, 201);
    }

    /** PUT /project-hub/folders/{id} */
    public function updateFolder(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $folder = $this->getHubService()->updateFolder($id, [
            'name' => $request->input('name'),
            'color' => $request->input('color'),
            'icon' => $request->input('icon'),
            'sort_order' => $request->input('sort_order'),
            'archived' => $request->input('archived'),
        ]);

        $this->publishEvent('board_events', [
            'type' => 'FOLDER_UPDATED',
            'action' => 'updated',
            'folder' => $folder,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json($folder);
    }

    /** DELETE /project-hub/folders/{id} */
    public function deleteFolder(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $this->getHubService()->deleteFolder($id);

        $this->publishEvent('board_events', [
            'type' => 'FOLDER_UPDATED',
            'action' => 'deleted',
            'folder_id' => $id,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json(['success' => true]);
    }

    /** POST /project-hub/folders/{id}/duplicate */
    public function duplicateFolder(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $folder = $this->getHubService()->duplicateFolder($id, $this->getActiveEmail());
        if (!$folder) return Response::error('Failed to duplicate folder', 500);

        $this->publishEvent('board_events', [
            'type' => 'FOLDER_UPDATED',
            'action' => 'duplicated',
            'folder' => $folder,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json($folder, 201);
    }

    /** POST /project-hub/folders/reorder */
    public function reorderFolders(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $ids = $request->input('ids');
        if (!is_array($ids)) {
            return Response::error('ids must be an array', 400);
        }

        $this->getHubService()->reorderFolders($ids);
        return Response::json(['success' => true]);
    }

    // =========================================================================
    // Folder <-> Board Links
    // =========================================================================

    /** GET /project-hub/folders/{id}/boards */
    public function getFolderBoards(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $boards = $this->getHubService()->getFolderBoards($folderId);
        return Response::json(['boards' => $boards]);
    }

    /** POST /project-hub/folders/{id}/boards */
    public function linkBoard(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['board_id']);
        if ($required) return $required;

        $folderId = (int)$request->param('id');
        $boardId = (int)$request->input('board_id');
        $sortOrder = (int)($request->input('sort_order') ?? 0);

        $this->getHubService()->linkBoardToFolder($folderId, $boardId, $sortOrder);

        $this->publishEvent('board_events', [
            'type' => 'FOLDER_UPDATED',
            'action' => 'board_linked',
            'folder_id' => $folderId,
            'board_id' => $boardId,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json(['success' => true], 201);
    }

    /** DELETE /project-hub/folders/{fid}/boards/{bid} */
    public function unlinkBoard(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('fid');
        $boardId = (int)$request->param('bid');
        $this->getHubService()->unlinkBoardFromFolder($folderId, $boardId);

        $this->publishEvent('board_events', [
            'type' => 'FOLDER_UPDATED',
            'action' => 'board_unlinked',
            'folder_id' => $folderId,
            'board_id' => $boardId,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json(['success' => true]);
    }

    /** POST /project-hub/folders/{id}/boards/reorder */
    public function reorderFolderBoards(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $ids = $request->input('board_ids');
        if (!is_array($ids)) {
            return Response::error('board_ids must be an array', 400);
        }

        $this->getHubService()->reorderFolderBoards($folderId, $ids);
        return Response::json(['success' => true]);
    }

    /** GET /project-hub/folders/{id}/board-attachments */
    public function getFolderBoardAttachments(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $data = $this->getHubService()->getFolderBoardAttachments($folderId);
        return Response::json($data);
    }

    /** GET /project-hub/folders/{id}/tracked-urls */
    public function getFolderTrackedUrls(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $data = $this->getHubService()->getFolderTrackedUrls($folderId);
        return Response::json($data);
    }

    /** GET /project-hub/folders/{id}/overview */
    public function getFolderOverview(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $data = $this->getHubService()->getFolderOverviewEnriched($folderId, $this->getActiveEmail());
        return Response::json($data);
    }

    // =========================================================================
    // Bookmarks
    // =========================================================================

    /** GET /project-hub/folders/{id}/bookmarks */
    public function getBookmarks(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $bookmarks = $this->getHubService()->getBookmarks($folderId);
        return Response::json(['bookmarks' => $bookmarks]);
    }

    /** POST /project-hub/folders/{id}/bookmarks */
    public function createBookmark(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['title', 'url']);
        if ($required) return $required;

        $folderId = (int)$request->param('id');
        $bookmark = $this->getHubService()->createBookmark($folderId, $this->getActiveEmail(), [
            'title' => $request->input('title'),
            'url' => $request->input('url'),
            'favicon_url' => $request->input('favicon_url'),
        ]);

        return Response::json($bookmark, 201);
    }

    /** DELETE /project-hub/bookmarks/{id} */
    public function deleteBookmark(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $this->getHubService()->deleteBookmark($id);
        return Response::json(['success' => true]);
    }

    // =========================================================================
    // Multi-Assignee Management
    // =========================================================================

    /** GET /project-hub/cards/{id}/assignees */
    public function getAssignees(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $assignees = $this->getWorkTrackingService()->getCardAssignees($cardId);
        return Response::json(['assignees' => $assignees]);
    }

    /** POST /project-hub/cards/{id}/assignees */
    public function addAssignee(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['user_email']);
        if ($required) return $required;

        $cardId = (int)$request->param('id');
        $assignee = $this->getWorkTrackingService()->addAssignee(
            $cardId,
            $request->input('user_email'),
            $request->input('role') ?? 'assignee'
        );

        if (!$assignee) {
            return Response::error('Failed to add assignee', 500);
        }

        $this->publishEvent('board_events', [
            'type' => 'CARD_ASSIGNEE_ADDED',
            'card_id' => $cardId,
            'assignee' => $assignee,
            'user_email' => $this->getActiveEmail(),
        ]);

        $notif = $this->getNotifService();
        $title = $notif->getCardTitle($cardId);
        $actor = $this->getActiveEmail();
        $assignedEmail = strtolower($request->input('user_email'));
        $notif->notifyUser($assignedEmail, $actor, 'ph_assigned',
            "You've been assigned: $title",
            "$actor assigned you to \"$title\"",
            ['card_id' => $cardId]
        );

        $this->logActivity($cardId, 'assignee_added', [
            'assignee_email' => $assignedEmail,
            'role' => $request->input('role') ?? 'assignee',
        ]);

        return Response::json($assignee, 201);
    }

    /**
     * Batched add: assign many users to a card in one HTTP call. Mostly
     * used by CardAssigneesPanel.vue::assignGroup when assigning a whole
     * team to a card.
     *
     * Body: { emails: string[], role?: string }
     * Returns: { added, skipped, assignees: [...] }
     *
     * POST /project-hub/cards/{id}/assignees/batch
     */
    public function addAssigneesBatch(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $emails = (array)$request->input('emails', []);
        $role = $request->input('role') ?? 'assignee';

        if (empty($emails)) {
            return Response::error('emails[] is required', 400);
        }

        // Cap to avoid one request becoming a DoS for huge groups.
        if (count($emails) > 100) {
            $emails = array_slice($emails, 0, 100);
        }

        $service = $this->getWorkTrackingService();
        $existing = array_column($service->getCardAssignees($cardId), 'user_email');
        $existingSet = array_flip(array_map('strtolower', $existing));
        $newEmails = array_values(array_filter(
            array_map(fn($e) => strtolower(trim((string)$e)), $emails),
            fn($e) => $e !== '' && !isset($existingSet[$e])
        ));

        $result = $service->addAssigneesBatch($cardId, $newEmails, $role);

        // One event per newly-assigned user (downstream consumers expect
        // CARD_ASSIGNEE_ADDED; we don't aggregate this to preserve
        // compatibility with the WebSocket consumer).
        $actor = $this->getActiveEmail();
        $notif = $this->getNotifService();
        $title = $notif->getCardTitle($cardId);
        foreach ($newEmails as $email) {
            try {
                $this->publishEvent('board_events', [
                    'type' => 'CARD_ASSIGNEE_ADDED',
                    'card_id' => $cardId,
                    'user_email' => $actor,
                ]);
                $notif->notifyUser($email, $actor, 'ph_assigned',
                    "You've been assigned: $title",
                    "$actor assigned you to \"$title\"",
                    ['card_id' => $cardId]
                );
                $this->logActivity($cardId, 'assignee_added', [
                    'assignee_email' => $email,
                    'role' => $role,
                ]);
            } catch (\Throwable $e) {
                error_log('[addAssigneesBatch] notify/log failed for ' . $email . ': ' . $e->getMessage());
            }
        }

        return Response::json($result, 201);
    }

    /**
     * Batched fetch of card assignees for many cards in one HTTP call.
     * Used to fan-in the N subtask-assignee fetches that
     * useSubtaskLinkedCards::loadSubtaskAssignees and
     * CardAssigneesPanel did via Promise.all.
     *
     * Body: { card_ids: int[] }
     * Returns: { assignees: { "<card_id>": [...] } }
     *
     * POST /project-hub/cards/assignees/batch-fetch
     */
    public function getAssigneesBatch(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardIds = (array)$request->input('card_ids', []);
        if (empty($cardIds)) {
            return Response::json(['assignees' => new \stdClass()]);
        }
        if (count($cardIds) > 500) {
            $cardIds = array_slice($cardIds, 0, 500);
        }

        $map = $this->getWorkTrackingService()->getCardAssigneesBatch($cardIds);
        return Response::json(['assignees' => $map]);
    }

    /** PUT /project-hub/card-assignees/{id} */
    public function updateAssignee(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $assignee = $this->getWorkTrackingService()->updateAssignee($id, [
            'role' => $request->input('role'),
            'status' => $request->input('status'),
            'notes' => $request->input('notes'),
            'difficulty_weight' => $request->input('difficulty_weight'),
        ]);

        if (!$assignee) {
            return Response::error('Assignee not found', 404);
        }

        $this->publishEvent('board_events', [
            'type' => 'CARD_ASSIGNEE_UPDATED',
            'assignee' => $assignee,
            'user_email' => $this->getActiveEmail(),
        ]);

        return Response::json($assignee);
    }

    /** DELETE /project-hub/card-assignees/{id} */
    public function removeAssignee(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $assignee = $this->getWorkTrackingService()->getAssignee($id);

        $this->getWorkTrackingService()->removeAssignee($id);

        if ($assignee) {
            $this->publishEvent('board_events', [
                'type' => 'CARD_ASSIGNEE_REMOVED',
                'card_id' => $assignee['card_id'],
                'assignee_id' => $id,
                'user_email' => $this->getActiveEmail(),
            ]);

            $this->logActivity((int)$assignee['card_id'], 'assignee_removed', [
                'assignee_email' => $assignee['user_email'] ?? '',
            ]);
        }

        return Response::json(['success' => true]);
    }

    /**
     * Batched DELETE: remove many assignee rows in ONE query.
     * Mirrors the per-row removeAssignee semantics (event publish +
     * activity log) but in a single HTTP request.
     *
     * Body: { ids: int[] }
     *
     * DELETE /project-hub/card-assignees/batch
     */
    public function removeAssigneesBatch(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $ids = (array)$request->input('ids', []);
        if (empty($ids)) {
            return Response::error('ids[] is required', 400);
        }
        if (count($ids) > 200) {
            $ids = array_slice($ids, 0, 200);
        }

        $service = $this->getWorkTrackingService();
        $result = $service->removeAssigneesBatch($ids);

        $actor = $this->getActiveEmail();
        foreach ($result['rows'] as $row) {
            try {
                $this->publishEvent('board_events', [
                    'type' => 'CARD_ASSIGNEE_REMOVED',
                    'card_id' => (int)$row['card_id'],
                    'assignee_id' => (int)$row['id'],
                    'user_email' => $actor,
                ]);
                $this->logActivity((int)$row['card_id'], 'assignee_removed', [
                    'assignee_email' => $row['user_email'] ?? '',
                ]);
            } catch (\Throwable $e) {
                error_log('[removeAssigneesBatch] event/log failed for assignee ' . $row['id'] . ': ' . $e->getMessage());
            }
        }

        return Response::json([
            'success' => true,
            'deleted' => $result['deleted'],
        ]);
    }

    /** POST /project-hub/card-assignees/{id}/status */
    public function changeAssigneeStatus(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['status']);
        if ($required) return $required;

        $id = (int)$request->param('id');
        $newStatus = $request->input('status');

        if (!$this->getRoleService()->validateStatusChange($id, $newStatus)) {
            $allowed = $this->getRoleService()->getStatusesForCardAssignee($id);
            $slugs = array_column($allowed, 'slug');
            return Response::error('Invalid status. Allowed: ' . implode(', ', $slugs), 400);
        }

        $assignee = $this->getWorkTrackingService()->updateAssigneeStatus($id, $newStatus);

        if (!$assignee) {
            return Response::error('Assignee not found', 404);
        }

        // If assignee marked as done, check if dependent cards can be unblocked
        if ($newStatus === 'done') {
            $cardId = $assignee['card_id'];
            $allAssignees = $this->getWorkTrackingService()->getCardAssignees($cardId);
            $allDone = true;
            foreach ($allAssignees as $a) {
                if ($a['role'] === 'assignee' && $a['status'] !== 'done') {
                    $allDone = false;
                    break;
                }
            }
            if ($allDone) {
                $this->getHubService()->checkAndUnblockDependents($cardId);
            }
        }

        $this->publishEvent('board_events', [
            'type' => 'CARD_ASSIGNEE_UPDATED',
            'assignee' => $assignee,
            'user_email' => $this->getActiveEmail(),
        ]);

        $cardId = $assignee['card_id'] ?? null;
        if ($cardId) {
            $notif = $this->getNotifService();
            $title = $notif->getCardTitle($cardId);
            $actor = $this->getActiveEmail();
            $notif->notifyCard($cardId, $actor, 'ph_status_changed',
                "Status changed on: $title",
                "$actor changed status to \"$newStatus\" on \"$title\"",
                ['card_id' => $cardId, 'new_status' => $newStatus]
            );

            $this->logActivity((int)$cardId, 'status_changed', [
                'assignee_email' => $assignee['user_email'] ?? '',
                'new_status' => $newStatus,
            ]);
        }

        return Response::json($assignee);
    }

    // =========================================================================
    // Work Sessions
    // =========================================================================

    /** GET /project-hub/cards/{id}/work-sessions */
    public function getWorkSessions(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $userFilter = $request->input('user_email');
        $sessions = $this->getWorkTrackingService()->getCardWorkSessions($cardId, $userFilter);
        return Response::json(['sessions' => $sessions]);
    }

    /** POST /project-hub/work-sessions */
    public function logWorkSession(Request $request): Response
    {
        try {
            $auth = $this->requireAuth($request);
            if ($auth) return $auth;

            $cardId = (int)($request->input('card_id') ?? 0);
            if ($cardId <= 0) {
                return Response::json(['logged' => false, 'error' => 'card_id required'], 400);
            }

            $durationSeconds = (int)($request->input('duration_seconds') ?? 0);
            if ($durationSeconds <= 0) {
                return Response::json(['logged' => false, 'error' => 'duration must be > 0'], 400);
            }

            $email = $this->getActiveEmail();
            $targetEmail = strtolower((string)($request->input('user_email') ?? $email));

            if ($targetEmail !== strtolower($email)) {
                $ownerStmt = $this->db->prepare("
                    SELECT b.owner_email
                    FROM webmail_board_cards c
                    JOIN webmail_board_lists l ON l.id = c.list_id
                    JOIN webmail_boards b ON b.id = l.board_id
                    WHERE c.id = ?
                ");
                $ownerStmt->execute([$cardId]);
                $ownerEmail = strtolower((string)($ownerStmt->fetchColumn() ?: ''));
                if ($ownerEmail !== strtolower($email)) {
                    return Response::json(['logged' => false, 'error' => 'Only board owner can add time for another member'], 403);
                }
            }

            $service = $this->getWorkTrackingService();

            $session = $service->logWorkSession($cardId, $targetEmail, [
                'source' => $request->input('source') ?? 'manual',
                'entity_type' => $request->input('entity_type'),
                'entity_id' => $request->input('entity_id'),
                'entity_name' => $request->input('entity_name'),
                'started_at' => $request->input('started_at'),
                'ended_at' => $request->input('ended_at'),
                'duration_seconds' => $durationSeconds,
            ]);

            if ($session) {
                try {
                    $this->publishEvent('board_events', [
                        'type' => 'CARD_WORK_SESSION',
                        'card_id' => $cardId,
                        'session' => $session,
                        'user_email' => $targetEmail,
                    ]);
                } catch (\Throwable $e) {
                    error_log('logWorkSession publish error: ' . $e->getMessage());
                }

                $this->logActivity($cardId, 'work_session', [
                    'duration_seconds' => $durationSeconds,
                    'source' => $request->input('source') ?? 'manual',
                ]);

                return Response::json(['logged' => true, 'session' => $session], 201);
            }

            return Response::json(['logged' => false, 'error' => 'Insert returned null'], 201);
        } catch (\Throwable $e) {
            error_log('logWorkSession fatal: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return Response::json(['logged' => false, 'error' => $e->getMessage()], 201);
        }
    }

    /** GET /project-hub/card-assignees/{id}/time */
    public function getAssigneeTime(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $time = $this->getWorkTrackingService()->getAssigneeTime($id);
        return Response::json($time);
    }

    /** GET /project-hub/work-sessions/summary */
    public function getWorkSessionsSummary(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $email = $this->getActiveEmail();
        $period = $request->input('period') ?? 'week';
        $boardId = $request->input('board_id') ? (int)$request->input('board_id') : null;

        $boards = $this->getWorkTrackingService()->getSessionsSummaryByBoard($email, $period, $boardId);
        return Response::json(['success' => true, 'data' => ['boards' => $boards]]);
    }

    /** POST /project-hub/work-sessions/drive-bridge */
    public function driveBridge(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['drive_file_id', 'duration_seconds']);
        if ($required) return $required;

        $results = $this->getWorkTrackingService()->bridgeDriveActivity(
            (int)$request->input('drive_file_id'),
            $this->getActiveEmail(),
            (int)$request->input('duration_seconds'),
            $request->input('file_name')
        );

        return Response::json(['results' => $results]);
    }

    // =========================================================================
    // Dependencies
    // =========================================================================

    /** GET /project-hub/cards/{id}/dependencies */
    public function getDependencies(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $cardId = (int)$request->param('id');
            $deps = $this->getHubService()->getCardDependencies($cardId);
            return Response::json($deps);
        } catch (\Throwable $e) {
            error_log('getDependencies error: ' . $e->getMessage());
            return Response::json(['waiting_on' => [], 'blocking' => []]);
        }
    }

    /** POST /project-hub/cards/{id}/dependencies */
    public function createDependency(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['depends_on_card_id']);
        if ($required) return $required;

        $cardId = (int)$request->param('id');
        $dep = $this->getHubService()->createDependency(
            $cardId,
            (int)$request->input('depends_on_card_id'),
            $request->input('type') ?? 'finish_to_start',
            $this->getActiveEmail()
        );

        if (!$dep) {
            return Response::error('Failed to create dependency (may already exist or self-reference)', 400);
        }

        $dependsOnId = (int)$request->input('depends_on_card_id');

        $this->publishEvent('board_events', [
            'type' => 'CARD_DEPENDENCY_ADDED',
            'card_id' => $cardId,
            'dependency' => $dep,
            'user_email' => $this->getActiveEmail(),
        ]);

        $notif = $this->getNotifService();
        $title = $notif->getCardTitle($cardId);
        $depTitle = $notif->getCardTitle($dependsOnId);
        $actor = $this->getActiveEmail();
        $notif->notifyCard($cardId, $actor, 'ph_dependency_added',
            "Dependency added to: $title",
            "$actor linked \"$title\" as dependent on \"$depTitle\"",
            ['card_id' => $cardId, 'depends_on_card_id' => $dependsOnId]
        );

        $this->logActivity($cardId, 'dependency_added', [
            'depends_on_card_id' => $dependsOnId,
        ]);

        return Response::json($dep, 201);
    }

    /** DELETE /project-hub/dependencies/{id} */
    public function deleteDependency(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');

        $db = \Webmail\Core\Database::getConnection($this->config);
        $dep = $db->query(
            "SELECT card_id, depends_on_card_id FROM projecthub_card_dependencies WHERE id = $id"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->getHubService()->deleteDependency($id);

        $this->publishEvent('board_events', [
            'type' => 'CARD_DEPENDENCY_REMOVED',
            'dependency_id' => $id,
            'user_email' => $this->getActiveEmail(),
        ]);

        if ($dep) {
            $notif = $this->getNotifService();
            $title = $notif->getCardTitle((int)$dep['card_id']);
            $actor = $this->getActiveEmail();
            $notif->notifyCard((int)$dep['card_id'], $actor, 'ph_dependency_removed',
                "Dependency removed from: $title",
                "$actor removed a dependency from \"$title\"",
                ['card_id' => (int)$dep['card_id'], 'depends_on_card_id' => (int)$dep['depends_on_card_id']]
            );

            $this->logActivity((int)$dep['card_id'], 'dependency_removed', [
                'depends_on_card_id' => (int)$dep['depends_on_card_id'],
            ]);
        }

        return Response::json(['success' => true]);
    }

    /** GET /project-hub/cards/{id}/subtask-card-links */
    public function getSubtaskCardLinks(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $cardId = (int)$request->param('id');
            $links = $this->getHubService()->getSubtaskCardLinks($cardId);
            return Response::json(['links' => $links]);
        } catch (\Throwable $e) {
            error_log('getSubtaskCardLinks error: ' . $e->getMessage());
            return Response::json(['links' => []]);
        }
    }

    /** GET /project-hub/cards/{id}/origin-link */
    public function getCardOriginLink(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $cardId = (int)$request->param('id');
            $link = $this->getHubService()->getCardOriginLink($cardId);
            return Response::json(['link' => $link]);
        } catch (\Throwable $e) {
            error_log('getCardOriginLink error: ' . $e->getMessage());
            return Response::json(['link' => null], 500);
        }
    }

    /** POST /project-hub/cards/{id}/subtasks/{subtaskId}/linked-card */
    public function createSubtaskCardLink(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['linked_card_id']);
        if ($required) return $required;

        $parentCardId = (int)$request->param('id');
        $subtaskCardId = (int)$request->param('subtaskId');

        $link = $this->getHubService()->createSubtaskCardLink(
            $parentCardId,
            $subtaskCardId,
            (int)$request->input('linked_card_id'),
            $this->getActiveEmail()
        );

        if (!$link) {
            return Response::error('Failed to create subtask card link', 400);
        }

        return Response::json($link, 201);
    }

    // =========================================================================
    // Comment Reactions & Read Tracking
    // =========================================================================

    /** POST /project-hub/comments/{id}/reactions */
    public function toggleReaction(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['emoji']);
        if ($required) return $required;

        try {
            $commentId = (int)$request->param('id');
            $result = $this->getWorkTrackingService()->toggleReaction(
                $commentId,
                $this->getActiveEmail(),
                $request->input('emoji')
            );

            $db = \Webmail\Core\Database::getConnection($this->config);
            $cardId = $db->query(
                "SELECT card_id FROM webmail_card_comments WHERE id = " . (int)$commentId
            )->fetchColumn();

            $this->publishEvent('board_events', [
                'type' => 'CARD_COMMENT_REACTION',
                'card_id' => (int)$cardId,
                'comment_id' => $commentId,
                'action' => $result['action'],
                'user_email' => $this->getActiveEmail(),
                'emoji' => $request->input('emoji'),
            ]);

            return Response::json($result);
        } catch (\Throwable $e) {
            error_log('toggleReaction error: ' . $e->getMessage());
            return Response::error('Failed to toggle reaction: ' . $e->getMessage(), 500);
        }
    }

    /** GET /project-hub/comments/{id}/reactions */
    public function getReactions(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $commentId = (int)$request->param('id');
        $reactions = $this->getWorkTrackingService()->getCommentReactions($commentId);
        return Response::json(['reactions' => $reactions]);
    }

    /**
     * Batched reactions fetch: one query for many comments.
     * Replaces the per-comment GET loop that was firing on every
     * thread render (EnhancedComments.vue::loadAllReactions).
     *
     * Body: { comment_ids: int[] }
     * Returns: { reactions: { [commentId]: [{emoji, users, count}] } }
     *
     * POST /project-hub/comments/reactions/batch
     */
    public function getReactionsBatch(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $ids = (array)$request->input('comment_ids', []);
        if (empty($ids)) {
            return Response::json(['reactions' => new \stdClass()]);
        }
        $reactions = $this->getWorkTrackingService()->getCommentReactionsBatch($ids);
        return Response::json(['reactions' => $reactions]);
    }

    /** POST /project-hub/comments/{id}/attachments */
    public function addCommentAttachment(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $commentId = (int)$request->param('id');
        $attachment = $this->getWorkTrackingService()->addCommentAttachment($commentId, [
            'type' => $request->input('type') ?? 'file',
            'drive_file_id' => $request->input('drive_file_id'),
            'drive_folder_id' => $request->input('drive_folder_id'),
            'url' => $request->input('url'),
            'name' => $request->input('name'),
        ]);

        return Response::json($attachment, 201);
    }

    /** POST /project-hub/cards/{id}/mark-read */
    public function markRead(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $this->getWorkTrackingService()->markCommentsRead($cardId, $this->getActiveEmail());
        return Response::json(['success' => true]);
    }

    /** GET /project-hub/cards/{id}/unread-count */
    public function getUnreadCount(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $count = $this->getWorkTrackingService()->getUnreadCommentCount($cardId, $this->getActiveEmail());
        return Response::json(['unread_count' => $count]);
    }

    // =========================================================================
    // Workload Planner (admin-only)
    // =========================================================================

    /** GET /project-hub/workload/timeline */
    public function getWorkloadTimeline(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $startDate = $request->getQuery('start_date') ?? date('Y-m-d');
            $endDate = $request->getQuery('end_date') ?? date('Y-m-d', strtotime('+30 days'));
            $spaceId = $request->getQuery('space_id') ? (int)$request->getQuery('space_id') : null;

            $filters = [];
            if ($request->getQuery('member_email')) {
                $filters['member_email'] = $request->getQuery('member_email');
            }
            if ($request->getQuery('group_id')) {
                $filters['group_id'] = (int)$request->getQuery('group_id');
            }
            if ($request->getQuery('label_id')) {
                $filters['label_id'] = (int)$request->getQuery('label_id');
            }

            $members = $this->getWorkTrackingService()->getWorkloadTimeline($startDate, $endDate, $spaceId, $filters);
            return Response::json(['members' => $members]);
        } catch (\Throwable $e) {
            error_log('getWorkloadTimeline error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return Response::json(['members' => [], 'error' => $e->getMessage()], 200);
        }
    }

    /** GET /project-hub/workload/labels */
    public function getWorkloadLabels(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $labels = $this->getWorkTrackingService()->getAvailableLabels();
            return Response::json(['labels' => $labels]);
        } catch (\Throwable $e) {
            error_log('getWorkloadLabels error: ' . $e->getMessage());
            return Response::json(['labels' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /project-hub/workload/live */
    public function getWorkloadLive(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        // Exposes every member's current activity — admin only (matches the UI gate)
        $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
        if (!$colleagueService->isAdmin($this->getActiveEmail())) {
            return Response::error('Admin access required', 403);
        }

        try {
            $filters = [];
            if ($me = $request->getQuery('member_email')) $filters['member_email'] = $me;
            if ($gid = $request->getQuery('group_id')) $filters['group_id'] = $gid;
            $members = $this->getWorkTrackingService()->getWorkloadLive($filters);

            // Merge real tracked-activity signals (file/website/card, last 10 min)
            try {
                $liveService = new \Webmail\Addons\ProjectHub\Services\ProjectHubLiveActivityService($this->config);
                $signals = $liveService->getRecentSignals(10);

                $byEmail = [];
                foreach ($members as $i => $m) {
                    $byEmail[strtolower($m['email'])] = $i;
                }
                foreach ($signals as $email => $sig) {
                    if (isset($byEmail[$email])) {
                        $members[$byEmail[$email]]['live_activity'] = $sig;
                    } else {
                        // Active right now but no card in 'working' status
                        $members[] = [
                            'email' => $email,
                            'current_card' => $sig['card_id']
                                ? ['card_id' => $sig['card_id'], 'title' => $sig['card_title'], 'board_name' => null]
                                : null,
                            'status' => null,
                            'last_activity_at' => $sig['at'],
                            'time_spent_today' => $this->getWorkTrackingService()->getUserTimeToday($email),
                            'live_activity' => $sig,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                error_log('getWorkloadLive signals error: ' . $e->getMessage());
            }

            return Response::json(['members' => $members]);
        } catch (\Throwable $e) {
            error_log('getWorkloadLive error: ' . $e->getMessage());
            return Response::json(['members' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /project-hub/workload/member/{email} */
    public function getMemberWorkload(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $email = $request->param('email');
            $cards = $this->getWorkTrackingService()->getMemberWorkload($email);
            return Response::json(['cards' => $cards]);
        } catch (\Throwable $e) {
            error_log('getMemberWorkload error: ' . $e->getMessage());
            return Response::json(['cards' => [], 'error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // PH Card Proxy Endpoints (wrap board APIs + fire PH notifications)
    // =========================================================================

    /** PUT /project-hub/cards/{id} -- update card via PH context */
    public function proxyUpdateCard(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $cardId = (int)$request->param('id');
            $payload = $request->input() ?: [];
            $actor = $this->getActiveEmail();

            $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($this->config);
            $updated = $boardService->updateCard($actor, $cardId, $payload);

            if ($updated) {
                try {
                    $notif = $this->getNotifService();
                    $title = $notif->getCardTitle($cardId);

                    $changedFields = array_keys($payload);
                    $fieldStr = implode(', ', $changedFields);
                    $notif->notifyCard($cardId, $actor, 'ph_card_updated',
                        "Task updated: $title",
                        "$actor updated $fieldStr on \"$title\"",
                        ['card_id' => $cardId, 'changed_fields' => $changedFields]
                    );
                } catch (\Throwable $e) {
                    error_log("PH proxyUpdateCard notification error: " . $e->getMessage());
                }

                try {
                    $bridge = new \Webmail\Addons\ProjectHub\Services\ProjectHubCalendarBridge($this->config);
                    $bridge->onCardUpdated($cardId);
                } catch (\Throwable $e) {
                    error_log("PH calendar bridge error: " . $e->getMessage());
                }

                if (array_key_exists('time_estimate_seconds', $payload)) {
                    try {
                        $budgetService = new \Webmail\Addons\ProjectHub\Services\ProjectHubTimeBudgetService($this->config);
                        $budgetService->resetAlert($cardId);
                        $budgetService->checkAndAlert($cardId, $actor);
                    } catch (\Throwable $e) {
                        error_log("PH time budget check error: " . $e->getMessage());
                    }
                }

                // skipBoardLog=true: BoardService::updateCard() already wrote to activity_log
                if (array_key_exists('completed', $payload)) {
                    $isCompleted = !empty($payload['completed']);
                    $this->logActivity($cardId, $isCompleted ? 'card_completed' : 'card_reopened', [], true);
                }

                $otherFields = array_diff(array_keys($payload), ['completed']);
                if (!empty($otherFields)) {
                    $this->logActivity($cardId, 'card_updated', [
                        'changed_fields' => array_values($otherFields),
                    ], true);
                }
            }

            return $updated ? Response::json(['success' => true, 'data' => ['card' => $updated]]) : Response::error('Card not found', 404);
        } catch (\Throwable $e) {
            error_log("proxyUpdateCard error: " . $e->getMessage());
            return Response::error('Failed to update card: ' . $e->getMessage(), 500);
        }
    }

    /** POST /project-hub/cards/{id}/comments -- add comment via PH context */
    public function proxyAddComment(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $content = $request->input('content', '');
        $actor = $this->getActiveEmail();

        $mentions = $request->input('mentions');
        $structured = null;
        if (is_array($mentions)) {
            $structured = $mentions;
        } elseif (is_string($mentions) && $mentions !== '') {
            $decoded = json_decode($mentions, true);
            $structured = is_array($decoded) ? $decoded : null;
        }

        $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($this->config);
        $comment = $boardService->addComment($actor, $cardId, $content, null, $structured);

        if ($comment) {
            $preview = mb_strlen($content) > 80 ? mb_substr(strip_tags($content), 0, 80) . '...' : strip_tags($content);

            // skipBoardLog=true: BoardService::addComment() already wrote to activity_log
            $this->logActivity($cardId, 'comment_added', [
                'comment_id' => $comment['id'] ?? null,
                'preview' => $preview,
            ], true);
        }

        return $comment
            ? Response::success(['comment' => $comment], 'Comment added')
            : Response::error('Failed to add comment', 500);
    }

    // =========================================================================
    // Watchers
    // =========================================================================

    /** GET /project-hub/cards/{id}/watchers */
    public function getWatchers(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $watchers = $this->getWorkTrackingService()->getCardWatchers($cardId);
        $isWatching = $this->getWorkTrackingService()->isWatching($cardId, $this->getActiveEmail());
        return Response::json(['watchers' => $watchers, 'is_watching' => $isWatching]);
    }

    /** POST /project-hub/cards/{id}/watchers */
    public function addWatcher(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $email = $request->input('user_email') ?: $this->getActiveEmail();
        $watcher = $this->getWorkTrackingService()->addWatcher($cardId, $email);

        $this->publishEvent('board_events', [
            'type' => 'CARD_WATCHER_ADDED',
            'card_id' => $cardId,
            'user_email' => strtolower($email),
        ]);

        $actor = $this->getActiveEmail();
        $watchedEmail = strtolower($email);
        if ($watchedEmail !== strtolower($actor)) {
            $notif = $this->getNotifService();
            $title = $notif->getCardTitle($cardId);
            $notif->notifyUser($watchedEmail, $actor, 'ph_watcher_added',
                "You're now watching: $title",
                "$actor added you as a watcher on \"$title\"",
                ['card_id' => $cardId]
            );
        }

        $this->logActivity($cardId, 'watcher_added', [
            'watcher_email' => $watchedEmail,
        ]);

        return Response::json($watcher, 201);
    }

    /** DELETE /project-hub/cards/{id}/watchers */
    public function removeWatcher(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $email = $request->input('user_email') ?: $this->getActiveEmail();
        $removed = $this->getWorkTrackingService()->removeWatcher($cardId, $email);

        return $removed ? Response::json(['success' => true]) : Response::error('Not watching', 404);
    }

    // =========================================================================
    // Activity Timeline
    // =========================================================================

    /** GET /project-hub/cards/{id}/activity */
    public function getCardActivity(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $limit = (int)($request->getQuery('limit', '50'));
        $offset = (int)($request->getQuery('offset', '0'));

        $events = $this->getActivityService()->getCardTimeline($cardId, $limit, $offset);
        $total = $this->getActivityService()->getCardTimelineCount($cardId);

        return Response::json([
            'events' => $events,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    // =========================================================================
    // Subtask -> Card Promotion
    // =========================================================================

    /** POST /project-hub/cards/{id}/promote-from-subtask */
    public function promoteFromSubtask(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $newCardId = (int)$request->param('id');
        $sourceCardId = (int)($request->input('source_card_id') ?? 0);
        $assigneeEmails = $request->input('assignee_emails') ?? [];

        if ($newCardId <= 0) {
            return Response::error('Invalid new card id', 400);
        }

        $actor = $this->getActiveEmail();
        $service = $this->getWorkTrackingService();
        $results = ['assignees_added' => 0, 'sessions_copied' => 0];

        foreach ($assigneeEmails as $email) {
            try {
                $assignee = $service->addAssignee($newCardId, $email, 'assignee');
                if ($assignee) $results['assignees_added']++;
            } catch (\Throwable $e) {
                error_log("promoteFromSubtask addAssignee error for $email: " . $e->getMessage());
            }
        }

        if ($sourceCardId > 0) {
            try {
                $results['sessions_copied'] = $service->copyWorkSessions($sourceCardId, $newCardId);
            } catch (\Throwable $e) {
                error_log("promoteFromSubtask copyWorkSessions error: " . $e->getMessage());
            }
        }

        $this->logActivity($newCardId, 'card_promoted', [
            'source_card_id' => $sourceCardId,
            'assignees_carried' => count($assigneeEmails),
            'sessions_copied' => $results['sessions_copied'],
        ]);

        return Response::json(['success' => true, 'results' => $results]);
    }

    // =========================================================================
    // Time Breakdown (admin / account manager overview)
    // =========================================================================

    /** GET /project-hub/time-breakdown */
    public function getTimeBreakdown(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $email = $this->getActiveEmail();
        $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
        $isAdmin = $colleagueService->isAdmin($email);

        $period = $request->getQuery('period', 'month');
        $startDate = $request->getQuery('start_date');
        $endDate = $request->getQuery('end_date');
        $clientId = $request->getQuery('client_id') ? (int)$request->getQuery('client_id') : null;
        $boardId = $request->getQuery('board_id') ? (int)$request->getQuery('board_id') : null;
        $filterUser = $request->getQuery('user_email');

        $service = new \Webmail\Addons\ProjectHub\Services\ProjectHubTimeBreakdownService($this->config);
        $rows = $service->getTimeBreakdown(
            $email, $isAdmin, $period, $startDate, $endDate, $clientId, $boardId, $filterUser
        );

        $debug = $service->_debug;

        // Direct test: count non-board_task rows in webmail_client_time_tracking
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $domain = substr(strtolower($email), strpos(strtolower($email), '@') + 1);
            $testStmt = $db->prepare("
                SELECT COUNT(*) AS cnt, SUM(duration_seconds) AS total_sec
                FROM webmail_client_time_tracking
                WHERE activity_type != 'board_task'
                  AND tracked_date >= '2000-01-01'
            ");
            $testStmt->execute();
            $testRow = $testStmt->fetch(\PDO::FETCH_ASSOC);
            $debug['direct_test_all'] = $testRow;

            $testStmt2 = $db->prepare("
                SELECT activity_type, COUNT(*) AS cnt, SUM(duration_seconds) AS total_sec
                FROM webmail_client_time_tracking
                WHERE activity_type != 'board_task'
                  AND LOWER(user_email) LIKE ?
                GROUP BY activity_type
                LIMIT 10
            ");
            $testStmt2->execute(['%@' . $domain]);
            $debug['direct_test_by_activity'] = $testStmt2->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $debug['direct_test_error'] = $e->getMessage();
        }

        return Response::json([
            'success' => true,
            'data' => [
                'rows' => $rows,
                'is_admin' => $isAdmin,
                '_debug' => $debug,
            ]
        ]);
    }

    // =========================================================================
    // Card Tracked URLs
    // =========================================================================

    private function getCardUrlService(): \Webmail\Addons\ProjectHub\Services\ProjectHubCardUrlService
    {
        static $svc = null;
        if (!$svc) {
            $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubCardUrlService($this->config);
        }
        return $svc;
    }

    public function getCardTrackedUrls(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->getParam('id');
        $urls = $this->getCardUrlService()->getCardTrackedUrls($cardId);

        return Response::json(['success' => true, 'data' => $urls]);
    }

    public function addCardTrackedUrl(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->getParam('id');
        $data = [
            'url_domain' => $request->input('url_domain'),
            'display_name' => $request->input('display_name'),
            'title_match' => $request->input('title_match'),
        ];

        $url = $this->getCardUrlService()->addCardTrackedUrl($cardId, $data, $this->getActiveEmail());
        if (!$url) {
            return Response::json(['success' => false, 'message' => 'Domain is required'], 400);
        }

        return Response::json(['success' => true, 'data' => $url]);
    }

    public function deleteCardTrackedUrl(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->getParam('id');
        $deleted = $this->getCardUrlService()->removeCardTrackedUrl($id);

        return $deleted
            ? Response::json(['success' => true])
            : Response::json(['success' => false, 'message' => 'Not found'], 404);
    }

    public function toggleCardTrackedUrl(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->getParam('id');
        $active = (bool)$request->input('is_active', true);
        $this->getCardUrlService()->toggleCardTrackedUrl($id, $active);

        return Response::json(['success' => true]);
    }

}
