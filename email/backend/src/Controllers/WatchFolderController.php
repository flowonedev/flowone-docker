<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\WatchFolderService;

class WatchFolderController extends BaseController
{
    private WatchFolderService $service;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->service = new WatchFolderService($config);
    }

    // =========================================================================
    // WATCH FOLDERS
    // =========================================================================

    public function list(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        try {
            return Response::json(['success' => true, 'data' => $this->service->getWatchFolders($email)]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::list] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function resolvedList(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        try {
            return Response::json(['success' => true, 'data' => $this->service->getResolvedWatchFolders($email)]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::resolvedList] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function create(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        $data = [
            'name' => $request->input('name', ''),
            'folder_path' => $request->input('folder_path', ''),
            'client_id' => $request->input('client_id'),
            'board_id' => $request->input('board_id'),
            'card_id' => $request->input('card_id'),
            'assigned_emails' => $request->input('assigned_emails'),
        ];

        if (!$data['name'] || !$data['folder_path']) {
            return Response::json(['success' => false, 'error' => 'Name and folder_path are required'], 400);
        }

        try {
            $folder = $this->service->createWatchFolder($email, $data);
            if (!$folder) {
                return Response::json(['success' => false, 'error' => 'Permission denied or creation failed'], 403);
            }
            return Response::json(['success' => true, 'data' => $folder]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::create] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        $id = (int)$request->getParam('id');
        $body = $request->input() ?? [];
        $data = [];
        foreach (['name', 'folder_path', 'client_id', 'board_id', 'card_id', 'assigned_emails'] as $key) {
            if (array_key_exists($key, $body)) {
                $data[$key] = $body[$key];
            }
        }

        try {
            $folder = $this->service->updateWatchFolder($email, $id, $data);
            if (!$folder) {
                return Response::json(['success' => false, 'error' => 'Not found or permission denied'], 404);
            }
            return Response::json(['success' => true, 'data' => $folder]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::update] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function delete(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        $id = (int)$request->getParam('id');

        try {
            $deleted = $this->service->deleteWatchFolder($email, $id);
            return $deleted
                ? Response::json(['success' => true])
                : Response::json(['success' => false, 'error' => 'Not found or permission denied'], 404);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::delete] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PATH OVERRIDES
    // =========================================================================

    public function listOverrides(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        try {
            $targetEmail = $request->getQuery('user') ?: null;
            return Response::json(['success' => true, 'data' => $this->service->getPathOverrides($email, $targetEmail)]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::listOverrides] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function upsertOverride(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        $targetEmail = $request->input('target_email') ?: $email;
        $data = [
            'id' => $request->input('id'),
            'match_prefix' => $request->input('match_prefix', ''),
            'replace_prefix' => $request->input('replace_prefix', ''),
            'label' => $request->input('label', ''),
            'is_active' => $request->input('is_active', true),
        ];

        try {
            $override = $this->service->upsertPathOverride($email, $targetEmail, $data);
            if (!$override) {
                return Response::json(['success' => false, 'error' => 'Permission denied or invalid data'], 403);
            }
            return Response::json(['success' => true, 'data' => $override]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::upsertOverride] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteOverride(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        $id = (int)$request->getParam('id');
        $targetEmail = $request->input('target_email') ?: $email;

        try {
            $this->service->deletePathOverride($email, $targetEmail, $id);
            return Response::json(['success' => true]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::deleteOverride] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function teamOverrideStatus(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        if (!$this->service->isAdmin($email)) {
            return Response::json(['success' => false, 'error' => 'Admin only'], 403);
        }

        try {
            return Response::json(['success' => true, 'data' => $this->service->getTeamOverrideStatus()]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::teamOverrideStatus] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // FILE ACTIVITY
    // =========================================================================

    public function logFileActivity(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        $data = [
            'watch_folder_id' => $request->input('watch_folder_id'),
            'file_name' => $request->input('file_name', ''),
            'file_path' => $request->input('file_path'),
            'duration_seconds' => (int)$request->input('duration_seconds', 0),
            'client_id' => $request->input('client_id'),
            'board_id' => $request->input('board_id'),
            'card_id' => $request->input('card_id'),
        ];

        if (!$data['watch_folder_id'] || !$data['file_name']) {
            return Response::json(['success' => false, 'error' => 'watch_folder_id and file_name are required'], 400);
        }

        try {
            $activity = $this->service->logFileActivity($email, $data);
            if (!$activity) {
                return Response::json(['success' => false, 'error' => 'Permission denied'], 403);
            }
            return Response::json(['success' => true, 'data' => $activity]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::logFileActivity] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function cardFileActivity(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        try {
            $cardId = (int)$request->getParam('cardId');
            return Response::json(['success' => true, 'data' => $this->service->getCardFileActivity($email, $cardId)]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::cardFileActivity] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function boardFileActivity(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);

        try {
            $boardId = (int)$request->getParam('boardId');
            return Response::json(['success' => true, 'data' => $this->service->getBoardFileActivity($email, $boardId)]);
        } catch (\Throwable $e) {
            error_log('[WatchFolderController::boardFileActivity] ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
