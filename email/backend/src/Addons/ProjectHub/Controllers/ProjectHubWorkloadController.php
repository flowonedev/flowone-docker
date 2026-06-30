<?php

namespace Webmail\Addons\ProjectHub\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService;
use Webmail\Addons\ProjectHub\Services\ProjectHubCalendarBridge;
use Webmail\Addons\Team\Services\ColleagueService;

class ProjectHubWorkloadController extends BaseController
{
    private ?ProjectHubWorkTrackingService $workTrackingService = null;
    private ?ProjectHubCalendarBridge $calBridge = null;

    private function getWorkTrackingService(): ProjectHubWorkTrackingService
    {
        if (!$this->workTrackingService) {
            $this->workTrackingService = new ProjectHubWorkTrackingService($this->config);
        }
        return $this->workTrackingService;
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

    private function extractMemberFilters(Request $request): array
    {
        $filters = [];
        if ($me = $request->getQuery('member_email')) {
            $filters['member_email'] = $me;
        }
        if ($gid = $request->getQuery('group_id')) {
            $filters['group_id'] = $gid;
        }
        if ($slug = $request->getQuery('role_slug')) {
            $filters['role_slug'] = is_string($slug) ? trim($slug) : $slug;
        }

        return $filters;
    }

    /** GET /project-hub/my-work */
    public function getMyWork(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $grouping = $request->getQuery('grouping', 'day');
            $tasks = $this->getWorkTrackingService()->getMyWork($this->getActiveEmail(), $grouping);
            return Response::json(['tasks' => $tasks]);
        } catch (\Throwable $e) {
            error_log('getMyWork error: ' . $e->getMessage());
            return Response::json(['tasks' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /project-hub/my-created */
    public function getMyCreated(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $tasks = $this->getWorkTrackingService()->getMyCreated($this->getActiveEmail());
            return Response::json(['tasks' => $tasks]);
        } catch (\Throwable $e) {
            error_log('getMyCreated error: ' . $e->getMessage());
            return Response::json(['tasks' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /project-hub/director-summary */
    public function getDirectorSummary(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        try {
            $filters = $this->extractMemberFilters($request);
            $members = $this->getWorkTrackingService()->getDirectorSummary($filters);
            return Response::json(['members' => $members]);
        } catch (\Throwable $e) {
            error_log('getDirectorSummary error: ' . $e->getMessage());
            return Response::json(['members' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /project-hub/workload/team-schedule */
    public function getTeamSchedule(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        try {
            $startDate = $request->getQuery('start_date', date('Y-m-d', strtotime('monday this week')));
            $endDate = $request->getQuery('end_date', date('Y-m-d', strtotime('sunday this week')));
            $filters = $this->extractMemberFilters($request);
            $data = $this->getWorkTrackingService()->getTeamSchedule($startDate, $endDate, $filters);
            return Response::json(['members' => $data]);
        } catch (\Throwable $e) {
            error_log('getTeamSchedule error: ' . $e->getMessage());
            return Response::json(['members' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /project-hub/workload/traffic */
    public function getWorkloadTraffic(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        try {
            $startDate = $request->getQuery('start_date', date('Y-m-d', strtotime('monday this week')));
            $endDate = $request->getQuery('end_date', date('Y-m-d', strtotime('sunday this week')));
            $granularity = $request->getQuery('granularity', 'day');
            $filters = $this->extractMemberFilters($request);

            $data = $this->getWorkTrackingService()->getTrafficData($startDate, $endDate, $granularity, $filters);
            return Response::json(['members' => $data]);
        } catch (\Throwable $e) {
            error_log('getWorkloadTraffic error: ' . $e->getMessage());
            return Response::json(['members' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /project-hub/workload/completions -- who finished what, day by day */
    public function getWorkloadCompletions(Request $request): Response
    {
        $guard = $this->requireAdminAuth($request);
        if ($guard) return $guard;

        try {
            $startDate = $request->getQuery('start_date', date('Y-m-d', strtotime('monday this week')));
            $endDate = $request->getQuery('end_date', date('Y-m-d', strtotime('sunday this week')));
            $filters = $this->extractMemberFilters($request);

            $service = new \Webmail\Addons\ProjectHub\Services\ProjectHubCompletionsService($this->config);
            $days = $service->getCompletions($startDate, $endDate, $filters);
            return Response::json(['days' => $days]);
        } catch (\Throwable $e) {
            error_log('getWorkloadCompletions error: ' . $e->getMessage());
            return Response::json(['days' => [], 'error' => $e->getMessage()]);
        }
    }

    /** GET /project-hub/notification-prefs */
    public function getNotificationPrefs(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $prefs = $this->getWorkTrackingService()->getNotificationPrefs($this->getActiveEmail());
        return Response::json(['prefs' => $prefs]);
    }

    /** PUT /project-hub/notification-prefs */
    public function updateNotificationPrefs(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $prefs = $request->input('prefs');
        if (!is_array($prefs)) {
            return Response::error('prefs must be an array', 400);
        }

        $this->getWorkTrackingService()->updateNotificationPrefs($this->getActiveEmail(), $prefs);
        return Response::json(['success' => true]);
    }

    // =========================================================================
    // Card-linked Drive Files
    // =========================================================================

    /** GET /project-hub/cards/{id}/drive-files -- search Drive files tagged with [PH-{cardId}] */
    public function getCardDriveFiles(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $email = strtolower($this->getActiveEmail());
        $tag = "[PH-$cardId]";

        $db = \Webmail\Core\Database::getConnection($this->config);
        $stmt = $db->prepare(
            "SELECT id, original_name, mime_type, size, folder_id, created_at
             FROM drive_files
             WHERE user_email = ? AND original_name LIKE ? AND (is_trashed = 0 OR is_trashed IS NULL)
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$email, "%$tag%"]);
        $files = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return Response::json(['files' => $files]);
    }

    public function getCardClientFiles(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int)$request->param('id');
        $email = strtolower($this->getActiveEmail());
        $db = \Webmail\Core\Database::getConnection($this->config);

        $stmt = $db->prepare("
            SELECT s.client_id, c2.display_name AS client_name, c2.drive_folder_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            LEFT JOIN projecthub_folder_boards fb ON fb.board_id = b.id
            LEFT JOIN projecthub_folders f ON f.id = fb.folder_id
            LEFT JOIN projecthub_spaces s ON s.id = f.space_id
            LEFT JOIN clients c2 ON c2.id = s.client_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$cardId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !$row['drive_folder_id']) {
            return Response::json(['success' => true, 'data' => ['client_name' => $row['client_name'] ?? null, 'files' => []]]);
        }

        $folderId = (int)$row['drive_folder_id'];

        $tree = $this->buildFolderTree($db, $folderId, 4);

        return Response::json([
            'success' => true,
            'data' => [
                'client_name' => $row['client_name'],
                'folder_id' => $folderId,
                'files' => $tree['files'],
                'subfolders' => $tree['subfolders'],
            ]
        ]);
    }

    private function buildFolderTree(\PDO $db, int $folderId, int $maxDepth): array
    {
        $fileStmt = $db->prepare("
            SELECT id, original_name, mime_type, size, folder_id, created_at
            FROM drive_files
            WHERE folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ORDER BY original_name ASC
            LIMIT 100
        ");
        $fileStmt->execute([$folderId]);
        $files = $fileStmt->fetchAll(\PDO::FETCH_ASSOC);

        $subfolders = [];
        if ($maxDepth > 0) {
            $subStmt = $db->prepare("SELECT id, name FROM drive_folders WHERE parent_id = ? ORDER BY name ASC");
            $subStmt->execute([$folderId]);
            foreach ($subStmt->fetchAll(\PDO::FETCH_ASSOC) as $sf) {
                $child = $this->buildFolderTree($db, (int)$sf['id'], $maxDepth - 1);
                $sf['files'] = $child['files'];
                $sf['subfolders'] = $child['subfolders'];
                $sf['total_files'] = count($child['files']);
                foreach ($child['subfolders'] as $grandchild) {
                    $sf['total_files'] += $grandchild['total_files'] ?? 0;
                }
                $subfolders[] = $sf;
            }
        }

        return ['files' => $files, 'subfolders' => $subfolders];
    }

    // =========================================================================
    // Calendar Bridge
    // =========================================================================

    private function getCalBridge(): ProjectHubCalendarBridge
    {
        if (!$this->calBridge) {
            $this->calBridge = new ProjectHubCalendarBridge($this->config);
        }
        return $this->calBridge;
    }

    /** GET /project-hub/cards/{id}/calendar-sync */
    public function getCardCalendarSync(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $cardId = (int)$request->param('id');
            $map = $this->getCalBridge()->getCardCalendarMap($cardId, $this->getActiveEmail());
            return Response::json(['sync' => $map]);
        } catch (\Throwable $e) {
            error_log('getCardCalendarSync error: ' . $e->getMessage());
            return Response::json(['sync' => null, 'error' => $e->getMessage()]);
        }
    }

    /** POST /project-hub/cards/{id}/calendar-sync */
    public function enableCardCalendarSync(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $required = $this->validateRequired($request, ['calendar_id']);
            if ($required) return $required;

            $cardId = (int)$request->param('id');
            $calendarId = (int)$request->input('calendar_id');
            $map = $this->getCalBridge()->enableSync($cardId, $this->getActiveEmail(), $calendarId);

            return $map ? Response::json($map, 201) : Response::error('Failed to enable sync', 500);
        } catch (\Throwable $e) {
            error_log('enableCardCalendarSync error: ' . $e->getMessage());
            return Response::error($e->getMessage(), 500);
        }
    }

    /** DELETE /project-hub/cards/{id}/calendar-sync */
    public function disableCardCalendarSync(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        try {
            $cardId = (int)$request->param('id');
            $disabled = $this->getCalBridge()->disableSync($cardId, $this->getActiveEmail());
            return $disabled ? Response::json(['success' => true]) : Response::error('Not synced', 404);
        } catch (\Throwable $e) {
            error_log('disableCardCalendarSync error: ' . $e->getMessage());
            return Response::error($e->getMessage(), 500);
        }
    }
}
