<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\DriveService;
use Webmail\Services\DriveFileSharingService;

/**
 * DriveFileShareController - file-level sharing with people and groups.
 *
 * Endpoints (all authenticated):
 *   GET    /drive/files/{id}/collaborators            list people the file is shared with
 *   POST   /drive/files/{id}/collaborators            share with a person {email, permission}
 *   PUT    /drive/files/{id}/collaborators/{email}    change a person's permission
 *   DELETE /drive/files/{id}/collaborators/{email}    unshare from a person
 *   GET    /drive/files/{id}/group-access             list groups the file is shared with
 *   POST   /drive/files/{id}/group-access             share with a group {group_id, permission}
 *   DELETE /drive/files/{id}/group-access/{groupId}   unshare from a group
 *   GET    /drive/shared-files/{id}/download          download a file shared with me
 *   GET    /drive/shared-files/{id}/preview           inline preview of a file shared with me
 */
class DriveFileShareController extends BaseController
{
    private ?DriveService $driveService = null;
    private ?DriveFileSharingService $sharing = null;

    private function drive(): DriveService
    {
        if (!$this->driveService) {
            $this->driveService = new DriveService($this->config, $this->userEmail);
        }
        return $this->driveService;
    }

    private function sharing(): DriveFileSharingService
    {
        if (!$this->sharing) {
            $this->sharing = new DriveFileSharingService($this->drive()->getDb());
        }
        return $this->sharing;
    }

    // =========================================================================
    // People
    // =========================================================================

    public function getCollaborators(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $fileId = (int)$request->getParam('id');
        $collaborators = $this->sharing()->getCollaborators($this->getActiveEmail(), $fileId);

        return Response::success(['collaborators' => $collaborators]);
    }

    public function addCollaborator(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $fileId = (int)$request->getParam('id');
        $collaboratorEmail = (string)$request->input('email');
        $permission = (string)$request->input('permission', 'viewer');

        if (empty($collaboratorEmail) || !filter_var($collaboratorEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email address required');
        }

        $result = $this->sharing()->addCollaborator($this->getActiveEmail(), $fileId, $collaboratorEmail, $permission);
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to add collaborator');
        }

        // In-app + realtime push (web + mobile) so the recipient is notified
        // inside FlowOne, not only by the share UI. Best-effort; never block.
        try {
            $file = $this->drive()->getFile($this->getActiveEmail(), $fileId);
            $itemName = $file['original_name'] ?? ($file['filename'] ?? 'a file');
            (new \Webmail\Services\ShareNotificationService($this->config))->notify(
                $this->getActiveEmail(),
                $itemName,
                null,
                [$collaboratorEmail],
                'file'
            );
        } catch (\Throwable $e) {
            error_log('DriveFileShareController::addCollaborator notify failed: ' . $e->getMessage());
        }

        return Response::success($result, 'Collaborator added');
    }

    public function updateCollaborator(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $fileId = (int)$request->getParam('id');
        $collaboratorEmail = (string)$request->getParam('email');
        $permission = (string)$request->input('permission');

        if (!in_array($permission, ['viewer', 'editor'], true)) {
            return Response::error('Permission must be "viewer" or "editor"');
        }

        if (!$this->sharing()->updateCollaboratorPermission($this->getActiveEmail(), $fileId, $collaboratorEmail, $permission)) {
            return Response::error('Collaborator not found', 404);
        }

        return Response::success(null, 'Permission updated');
    }

    public function removeCollaborator(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $fileId = (int)$request->getParam('id');
        $collaboratorEmail = (string)$request->getParam('email');

        if (!$this->sharing()->removeCollaborator($this->getActiveEmail(), $fileId, $collaboratorEmail)) {
            return Response::error('Collaborator not found', 404);
        }

        return Response::success(null, 'Collaborator removed');
    }

    // =========================================================================
    // Groups
    // =========================================================================

    public function getGroupAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $fileId = (int)$request->getParam('id');
        $groups = $this->sharing()->getGroupAccess($this->getActiveEmail(), $fileId);

        return Response::success(['groups' => $groups]);
    }

    public function addGroupAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $fileId = (int)$request->getParam('id');
        $groupId = (int)$request->input('group_id');
        $permission = (string)$request->input('permission', 'viewer');

        if ($groupId <= 0) {
            return Response::error('group_id required');
        }

        $result = $this->sharing()->addGroupAccess($this->getActiveEmail(), $fileId, $groupId, $permission);
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to share with group');
        }

        return Response::success($result, 'Group access granted');
    }

    public function removeGroupAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $fileId = (int)$request->getParam('id');
        $groupId = (int)$request->getParam('groupId');

        $result = $this->sharing()->removeGroupAccess($this->getActiveEmail(), $fileId, $groupId);
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to remove group access', 400);
        }

        return Response::success(['message' => 'Group access removed']);
    }

    // =========================================================================
    // Download / preview for directly shared files
    // =========================================================================

    /**
     * Resolve any read access to a file for the current user:
     * direct person/group share, or folder-tree collaborator access.
     */
    private function resolveReadAccess(string $email, int $fileId)
    {
        $access = $this->sharing()->resolveDirectFileAccess($email, $fileId);
        if ($access) {
            return $access;
        }
        return $this->drive()->hasFileCollaboratorAccess($email, $fileId);
    }

    public function download(Request $request): Response
    {
        return $this->streamSharedFile($request, 'attachment');
    }

    public function preview(Request $request): Response
    {
        return $this->streamSharedFile($request, 'inline');
    }

    private function streamSharedFile(Request $request, string $disposition): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');

        if (!$this->resolveReadAccess($email, $fileId)) {
            return Response::error('File not found or access denied', 404);
        }

        $file = $this->drive()->getFileByIdWithPath($fileId);
        if (!$file || !empty($file['is_trashed']) || empty($file['storage_path']) || !file_exists($file['storage_path'])) {
            return Response::error('File not found', 404);
        }

        // View-only restriction: block downloads for VIEW-access recipients
        // (owner/editors are unaffected). Inline previews stay allowed and are
        // recorded in the file's access history.
        if ($disposition === 'attachment' && $this->drive()->isViewerDownloadBlocked($file, $email)) {
            $this->drive()->logFileAccess(
                $fileId, $email, 'download_blocked',
                $request->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return Response::error('Downloading is disabled for this file', 403);
        }
        if ($disposition === 'inline') {
            $this->drive()->logFileAccess(
                $fileId, $email, 'open',
                $request->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null
            );
        }

        // Stream manually (streamBinaryFile forces attachment disposition,
        // which would break inline previews).
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: ' . ($file['mime_type'] ?? 'application/octet-stream'));
        header($this->safeContentDisposition($disposition, $file['original_name']));
        header('Content-Length: ' . filesize($file['storage_path']));
        header('Cache-Control: ' . ($disposition === 'inline' ? 'public, max-age=86400' : 'no-cache, must-revalidate'));
        header('Content-Encoding: identity');
        header('X-Content-Type-Options: nosniff');

        set_time_limit(0);
        $handle = @fopen($file['storage_path'], 'rb');
        if ($handle === false) {
            @readfile($file['storage_path']);
            exit;
        }
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) break;
            echo $chunk;
            flush();
            if (connection_aborted()) break;
        }
        fclose($handle);
        exit;
    }
}
