<?php

namespace Webmail\Addons\ProjectHub\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\ProjectHub\Services\ProjectHubFileService;
use Webmail\Addons\ProjectHub\Services\ProjectHubService;
use Webmail\Services\DriveService;

class ProjectHubFileController extends BaseController
{
    private ?ProjectHubFileService $fileService = null;
    private ?DriveService $driveService = null;
    private bool $tablesEnsured = false;

    private function ensureTables(): void
    {
        if (!$this->tablesEnsured) {
            new ProjectHubService($this->config);
            $this->tablesEnsured = true;
        }
    }

    private function getFileService(): ProjectHubFileService
    {
        $this->ensureTables();
        if (!$this->fileService) {
            $this->fileService = new ProjectHubFileService($this->config);
        }
        return $this->fileService;
    }

    private function getDriveService(): DriveService
    {
        if (!$this->driveService) {
            $this->driveService = new DriveService($this->config);
        }
        return $this->driveService;
    }

    // =========================================================================
    // Folder Files
    // =========================================================================

    /** GET /project-hub/folders/{id}/files */
    public function listFiles(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $files = $this->getFileService()->listFolderFiles($folderId, $this->getActiveEmail());

        return Response::json(['files' => $files]);
    }

    /** POST /project-hub/folders/{id}/files */
    public function addFile(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['drive_file_id']);
        if ($required) return $required;

        $folderId = (int)$request->param('id');
        $driveFileId = (int)$request->input('drive_file_id');
        $groupName = $request->input('group_name') ?? 'General';

        $file = $this->getFileService()->addFileToFolder($folderId, $driveFileId, $groupName, $this->getActiveEmail());
        if (!$file) {
            return Response::error('File already linked to this folder', 409);
        }

        $this->publishEvent('board_events', [
            'type' => 'folder_file_added',
            'folder_id' => $folderId,
            'file' => $file,
            'added_by' => $this->getActiveEmail(),
        ]);

        return Response::json($file, 201);
    }

    /** PUT /project-hub/folder-files/{id}/group */
    public function updateGroup(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['group_name']);
        if ($required) return $required;

        $id = (int)$request->param('id');
        $this->getFileService()->updateFileGroup($id, $request->input('group_name'));

        return Response::json(['success' => true]);
    }

    /** PUT /project-hub/folder-files/batch-group */
    public function batchGroup(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['ids', 'group_name']);
        if ($required) return $required;

        $ids = $request->input('ids');
        if (!is_array($ids) || empty($ids)) {
            return Response::error('ids must be a non-empty array', 400);
        }

        $count = $this->getFileService()->batchUpdateGroup($ids, $request->input('group_name'));
        return Response::json(['updated' => $count]);
    }

    /** DELETE /project-hub/folder-files/{id} */
    public function removeFile(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $this->getFileService()->removeFile($id);

        return Response::json(['success' => true]);
    }

    /** POST /project-hub/folders/{id}/files/mark-seen */
    public function markSeen(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $this->getFileService()->markSeen($folderId, $this->getActiveEmail());

        return Response::json(['success' => true]);
    }

    /** GET /project-hub/folders/{id}/files/unseen-count */
    public function unseenCount(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $count = $this->getFileService()->getUnseenCount($folderId, $this->getActiveEmail());

        return Response::json(['count' => $count]);
    }

    /** GET /project-hub/folders/unseen-counts */
    public function unseenCountsBatch(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $idsParam = $request->getQuery('ids') ?? '';
        $ids = array_filter(array_map('intval', explode(',', $idsParam)));

        if (empty($ids)) {
            return Response::json(['counts' => []]);
        }

        $counts = $this->getFileService()->getUnseenCountsBatch($ids, $this->getActiveEmail());
        return Response::json(['counts' => $counts]);
    }

    /** GET /project-hub/folders/{id}/files/export */
    public function exportZip(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $groupName = $request->getQuery('group') ?: null;
        $email = $this->getActiveEmail();

        $driveFileIds = $this->getFileService()->getDriveFileIdsForExport($folderId, $groupName);
        if (empty($driveFileIds)) {
            return Response::error('No files to export', 404);
        }

        $zipResult = $this->getDriveService()->createFilesZip($email, $driveFileIds);
        if (!$zipResult || !isset($zipResult['path'])) {
            return Response::error('Failed to create ZIP archive', 500);
        }

        $zipPath = $zipResult['path'];
        $zipName = $groupName
            ? "folder_{$folderId}_{$groupName}.zip"
            : "folder_{$folderId}_all_files.zip";

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    /** GET /project-hub/folders/{id}/files/groups */
    public function fileGroups(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $groups = $this->getFileService()->getFileGroups($folderId);

        return Response::json(['groups' => $groups]);
    }

    // =========================================================================
    // Folder Links
    // =========================================================================

    /** GET /project-hub/folders/{id}/links */
    public function listLinks(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $folderId = (int)$request->param('id');
        $links = $this->getFileService()->listFolderLinks($folderId, $this->getActiveEmail());

        return Response::json(['links' => $links]);
    }

    /** POST /project-hub/folders/{id}/links */
    public function addLink(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $required = $this->validateRequired($request, ['title', 'url']);
        if ($required) return $required;

        $folderId = (int)$request->param('id');
        $link = $this->getFileService()->addLink($folderId, [
            'title' => $request->input('title'),
            'url' => $request->input('url'),
            'link_type' => $request->input('link_type') ?? 'url',
            'group_name' => $request->input('group_name'),
        ], $this->getActiveEmail());

        $this->publishEvent('board_events', [
            'type' => 'folder_link_added',
            'folder_id' => $folderId,
            'link' => $link,
            'added_by' => $this->getActiveEmail(),
        ]);

        return Response::json($link, 201);
    }

    /** PUT /project-hub/folder-links/{id} */
    public function updateLink(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $this->getFileService()->updateLink($id, [
            'title' => $request->input('title'),
            'url' => $request->input('url'),
            'link_type' => $request->input('link_type'),
            'group_name' => $request->input('group_name'),
            'sort_order' => $request->input('sort_order'),
        ]);

        return Response::json(['success' => true]);
    }

    /** DELETE /project-hub/folder-links/{id} */
    public function deleteLink(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $this->getFileService()->deleteLink($id);

        return Response::json(['success' => true]);
    }
}
