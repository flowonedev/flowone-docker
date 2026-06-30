<?php

namespace Webmail\Addons\Team\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\Team\Services\ColleagueService;

/**
 * ColleagueController - API endpoints for colleague management
 * 
 * Endpoints:
 * - GET /colleagues - List all colleagues in organization
 * - GET /colleagues/:id - Get single colleague
 * - POST /colleagues - Add colleague (admin)
 * - PUT /colleagues/:id - Update colleague (admin or self)
 * - DELETE /colleagues/:id - Delete colleague (admin)
 * - POST /colleagues/sync - Sync from mail server (admin)
 * 
 * - GET /colleagues/groups - List all groups
 * - GET /colleagues/groups/:id - Get group with members
 * - POST /colleagues/groups - Create group (admin)
 * - PUT /colleagues/groups/:id - Update group (admin)
 * - DELETE /colleagues/groups/:id - Delete group (admin)
 * 
 * - POST /colleagues/groups/:id/members - Add members to group (admin)
 * - DELETE /colleagues/groups/:id/members/:colleagueId - Remove from group (admin)
 * - PUT /colleagues/:id/groups - Set colleague's groups (admin)
 * 
 * - GET /colleagues/me - Get current user's colleague profile
 * - PUT /colleagues/me - Update own profile
 * - POST /colleagues/me/avatar - Upload profile avatar
 * - DELETE /colleagues/me/avatar - Remove profile avatar
 * - GET /colleagues/avatar/{filename} - Serve avatar image (public)
 */
class ColleagueController extends BaseController
{
    private ?ColleagueService $service = null;
    
    private function getService(): ColleagueService
    {
        if ($this->service === null) {
            $this->service = new ColleagueService($this->config);
        }
        return $this->service;
    }
    
    // ========================================
    // COLLEAGUE ENDPOINTS
    // ========================================
    
    /**
     * GET /colleagues - List all colleagues
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        $colleagues = $service->getColleagues($this->userEmail);
        
        return Response::success([
            'colleagues' => $colleagues,
            'is_admin' => $service->isAdmin($this->userEmail)
        ]);
    }
    
    /**
     * GET /colleagues/me - Get current user's profile
     */
    public function getMe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        
        // Ensure colleague record exists
        $colleague = $service->ensureColleagueExists($this->userEmail);
        
        if (!$colleague) {
            return Response::error('Could not create colleague record', 500);
        }
        
        // Get groups
        $groups = $service->getUserGroups($this->userEmail);
        $colleague['groups'] = $groups;
        $colleague['is_admin'] = $service->isAdmin($this->userEmail);
        
        // Update last seen
        $service->updateLastSeen($this->userEmail);
        
        return Response::success(['colleague' => $colleague]);
    }
    
    /**
     * PUT /colleagues/me - Update own profile
     */
    public function updateMe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        
        // Ensure colleague exists
        $colleague = $service->ensureColleagueExists($this->userEmail);
        if (!$colleague) {
            return Response::error('Colleague record not found', 404);
        }
        
        $data = $request->input();
        $result = $service->updateColleague($this->userEmail, $colleague['id'], $data);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Profile updated']);
    }
    
    /**
     * POST /colleagues/me/avatar - Upload profile avatar
     */
    public function uploadAvatar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        
        // Ensure colleague exists
        $colleague = $service->ensureColleagueExists($this->userEmail);
        if (!$colleague) {
            return Response::error('Colleague record not found', 404);
        }
        
        // Check for uploaded file
        $file = $_FILES['avatar'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::error('No file uploaded or upload error', 400);
        }
        
        try {
            // Validate file type (finfo is preferred; fall back gracefully if the
            // fileinfo extension is unavailable so we never throw a fatal here)
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $mimeType = $this->detectImageMime($file['tmp_name']);
            
            if (!in_array($mimeType, $allowedTypes, true)) {
                return Response::error('Invalid file type. Allowed: JPEG, PNG, GIF, WebP', 400);
            }
            
            // Max 5 MB
            if ($file['size'] > 5 * 1024 * 1024) {
                return Response::error('File too large. Maximum size is 5 MB', 400);
            }
            
            // Create avatars directory and verify it is writable. mkdir failures
            // only raise warnings (suppressed by the global error handler), so we
            // check explicitly and return a clear error instead of failing later.
            $avatarDir = __DIR__ . '/../../../../storage/avatars';
            if (!is_dir($avatarDir) && !@mkdir($avatarDir, 0755, true) && !is_dir($avatarDir)) {
                error_log("ColleagueController: failed to create avatar dir: {$avatarDir}");
                return Response::error('Avatar storage is not available', 500);
            }
            if (!is_writable($avatarDir)) {
                error_log("ColleagueController: avatar dir not writable: {$avatarDir}");
                return Response::error('Avatar storage is not writable', 500);
            }
            
            // Generate unique filename based on email hash
            $emailHash = md5(strtolower($this->userEmail));
            $ext = match($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg'
            };
            $filename = $emailHash . '_' . time() . '.' . $ext;
            $filepath = $avatarDir . '/' . $filename;
            
            // Resize to max 512x512 to save space and speed up loading. If GD (or
            // the specific format) is unavailable, resizeAvatar returns false and
            // we store the original file un-resized.
            $resized = $this->resizeAvatar($file['tmp_name'], $filepath, $mimeType, 512);
            if (!$resized) {
                // Fallback: just move the file (move_uploaded_file fails on a
                // already-moved temp file, so copy as a last resort)
                if (!@move_uploaded_file($file['tmp_name'], $filepath)
                    && !@copy($file['tmp_name'], $filepath)) {
                    error_log("ColleagueController: failed to save avatar to {$filepath}");
                    return Response::error('Failed to save avatar', 500);
                }
            }
            
            // Delete old avatar file if exists
            $oldPath = $colleague['avatar_path'] ?? null;
            if ($oldPath) {
                $oldFile = $avatarDir . '/' . basename($oldPath);
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
            
            // Update database with new avatar path
            $avatarPath = 'avatars/' . $filename;
            $result = $service->updateColleague($this->userEmail, $colleague['id'], [
                'avatar_path' => $avatarPath
            ]);
            
            if (!$result['success']) {
                @unlink($filepath);
                return Response::error('Failed to update avatar record', 500);
            }
            
            return Response::success([
                'avatar_path' => $avatarPath,
                'avatar_url' => '/api/colleagues/avatar/' . $filename,
                'message' => 'Avatar uploaded'
            ]);
        } catch (\Throwable $e) {
            error_log(
                'ColleagueController::uploadAvatar failed: ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine()
            );
            return Response::error('Failed to process avatar', 500);
        }
    }
    
    /**
     * Detect an image MIME type, preferring the fileinfo extension but falling
     * back to getimagesize()/mime_content_type() so a missing extension cannot
     * trigger a fatal error.
     */
    private function detectImageMime(string $path): ?string
    {
        if (class_exists('finfo') && defined('FILEINFO_MIME_TYPE')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        
        $info = @getimagesize($path);
        if (is_array($info) && !empty($info['mime'])) {
            return $info['mime'];
        }
        
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        
        return null;
    }
    
    /**
     * DELETE /colleagues/me/avatar - Remove profile avatar
     */
    public function deleteAvatar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        
        $colleague = $service->ensureColleagueExists($this->userEmail);
        if (!$colleague) {
            return Response::error('Colleague record not found', 404);
        }
        
        // Delete file
        $oldPath = $colleague['avatar_path'] ?? null;
        if ($oldPath) {
            $avatarDir = __DIR__ . '/../../../../storage/avatars';
            $oldFile = $avatarDir . '/' . basename($oldPath);
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }
        
        // Clear avatar_path in database
        $result = $service->updateColleague($this->userEmail, $colleague['id'], [
            'avatar_path' => null
        ]);
        
        if (!$result['success']) {
            return Response::error('Failed to remove avatar', 500);
        }
        
        return Response::success(['message' => 'Avatar removed']);
    }
    
    /**
     * GET /colleagues/avatar/{filename} - Serve avatar image
     * This endpoint does not require authentication (images are semi-public within the org)
     */
    public function serveAvatar(Request $request): Response
    {
        $filename = $request->getParam('filename');
        if (!$filename) {
            return Response::error('Filename required', 400);
        }
        
        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        $filepath = __DIR__ . '/../../../../storage/avatars/' . $filename;
        
        if (!file_exists($filepath)) {
            return Response::error('Avatar not found', 404);
        }
        
        $mimeType = mime_content_type($filepath) ?: 'image/jpeg';
        
        // Send with long cache headers (filename changes on update)
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . md5_file($filepath) . '"');
        readfile($filepath);
        exit;
    }
    
    /**
     * Resize an image to fit within maxSize x maxSize, maintaining aspect ratio
     */
    private function resizeAvatar(string $sourcePath, string $destPath, string $mimeType, int $maxSize): bool
    {
        // GD may be absent, or compiled without support for a specific format
        // (WebP especially). Calling an undefined imagecreatefrom*/image* function
        // would throw a fatal Error (the @ operator does NOT suppress that), so we
        // bail out and let the caller store the original file un-resized instead.
        if (!extension_loaded('gd')) {
            return false;
        }
        
        $decoder = match($mimeType) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
            default => null
        };
        $encoder = match($mimeType) {
            'image/jpeg' => 'imagejpeg',
            'image/png' => 'imagepng',
            'image/gif' => 'imagegif',
            'image/webp' => 'imagewebp',
            default => null
        };
        if (!$decoder || !$encoder || !function_exists($decoder) || !function_exists($encoder)) {
            return false;
        }
        
        $sourceImage = @$decoder($sourcePath);
        if (!$sourceImage) return false;
        
        $origW = imagesx($sourceImage);
        $origH = imagesy($sourceImage);
        
        // Only resize if larger than maxSize
        if ($origW <= $maxSize && $origH <= $maxSize) {
            imagedestroy($sourceImage);
            return move_uploaded_file($sourcePath, $destPath);
        }
        
        // Calculate new dimensions
        $ratio = min($maxSize / $origW, $maxSize / $origH);
        $newW = (int)round($origW * $ratio);
        $newH = (int)round($origH * $ratio);
        
        $resized = imagecreatetruecolor($newW, $newH);
        
        // Preserve transparency for PNG and WebP
        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        
        imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        
        $result = match($mimeType) {
            'image/jpeg' => imagejpeg($resized, $destPath, 90),
            'image/png' => imagepng($resized, $destPath, 6),
            'image/gif' => imagegif($resized, $destPath),
            'image/webp' => imagewebp($resized, $destPath, 90),
            default => false
        };
        
        imagedestroy($sourceImage);
        imagedestroy($resized);
        
        return $result;
    }
    
    /**
     * PUT /colleagues/me/status - Update own presence status
     */
    public function updateMyStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        $data = $request->input();
        
        $status = $data['status'] ?? null;
        if (!in_array($status, ['active', 'away', 'do_not_disturb', 'offline'])) {
            return Response::error('Invalid status. Must be: active, away, do_not_disturb, or offline', 400);
        }
        
        $result = $service->updateColleagueStatus($this->userEmail, $status);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Status updated', 'status' => $status]);
    }
    
    /**
     * GET /colleagues/:id - Get single colleague
     */
    public function get(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $service = $this->getService();
        
        $colleague = $service->getColleagueById($id);
        if (!$colleague) {
            return Response::error('Colleague not found', 404);
        }
        
        // Add groups
        $groups = $service->getUserGroups($colleague['email']);
        $colleague['groups'] = $groups;
        
        return Response::success(['colleague' => $colleague]);
    }
    
    /**
     * POST /colleagues - Add colleague (admin only)
     */
    public function create(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        $data = $request->input();
        
        $result = $service->addColleague($this->userEmail, $data);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['id' => $result['id'], 'message' => 'Colleague added']);
    }
    
    /**
     * PUT /colleagues/:id - Update colleague
     */
    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $service = $this->getService();
        $data = $request->input();
        
        $result = $service->updateColleague($this->userEmail, $id, $data);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Colleague updated']);
    }
    
    /**
     * DELETE /colleagues/:id - Delete colleague (admin only)
     */
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $service = $this->getService();
        
        $result = $service->deleteColleague($this->userEmail, $id);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Colleague deleted']);
    }
    
    /**
     * POST /colleagues/sync - Sync from mail server (admin only)
     */
    public function sync(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        $result = $service->syncFromMailServer($this->userEmail);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success([
            'message' => "Synced {$result['synced']} new, updated {$result['updated']} existing colleagues",
            'synced' => $result['synced'],
            'updated' => $result['updated'],
            'total' => $result['total'],
            'sources' => $result['sources'] ?? [],
            'emails_found' => $result['emails_found'] ?? [],
        ]);
    }
    
    /**
     * PUT /colleagues/:id/groups - Set colleague's groups (admin only)
     */
    public function setGroups(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $service = $this->getService();
        $data = $request->input();
        
        $groupIds = $data['group_ids'] ?? [];
        if (!is_array($groupIds)) {
            return Response::error('group_ids must be an array', 400);
        }
        
        $result = $service->setColleagueGroups($this->userEmail, $id, $groupIds);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Groups updated']);
    }
    
    // ========================================
    // GROUP ENDPOINTS
    // ========================================
    
    /**
     * GET /colleagues/groups - List all groups
     */
    public function listGroups(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        $groups = $service->getGroups($this->userEmail);
        
        return Response::success(['groups' => $groups]);
    }
    
    /**
     * GET /colleagues/groups/:id - Get group with members
     */
    public function getGroup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $service = $this->getService();
        
        $group = $service->getGroupWithMembers($this->userEmail, $id);
        if (!$group) {
            return Response::error('Group not found', 404);
        }
        
        return Response::success(['group' => $group]);
    }
    
    /**
     * POST /colleagues/groups - Create group (admin only)
     */
    public function createGroup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getService();
        $data = $request->input();
        
        $result = $service->createGroup($this->userEmail, $data);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['id' => $result['id'], 'message' => 'Group created']);
    }
    
    /**
     * PUT /colleagues/groups/:id - Update group (admin only)
     */
    public function updateGroup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $service = $this->getService();
        $data = $request->input();
        
        $result = $service->updateGroup($this->userEmail, $id, $data);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Group updated']);
    }
    
    /**
     * DELETE /colleagues/groups/:id - Delete group (admin only)
     */
    public function deleteGroup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $service = $this->getService();
        
        $result = $service->deleteGroup($this->userEmail, $id);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Group deleted']);
    }
    
    /**
     * GET /colleagues/groups/:id/members - List members of a group
     */
    public function listGroupMembers(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $groupId = (int)$request->getParam('id');
        $service = $this->getService();
        
        $result = $service->getGroupMembers($this->userEmail, $groupId);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success($result['members']);
    }
    
    /**
     * POST /colleagues/groups/:id/members - Add members to group (admin only)
     */
    public function addGroupMembers(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $groupId = (int)$request->getParam('id');
        $service = $this->getService();
        $data = $request->input();
        
        $colleagueIds = $data['colleague_ids'] ?? [];
        if (!is_array($colleagueIds) || empty($colleagueIds)) {
            return Response::error('colleague_ids must be a non-empty array', 400);
        }
        
        $result = $service->addToGroup($this->userEmail, $groupId, $colleagueIds);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['added' => $result['added'], 'message' => "Added {$result['added']} members"]);
    }
    
    /**
     * DELETE /colleagues/groups/:groupId/members/:colleagueId - Remove from group
     */
    public function removeGroupMember(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $groupId = (int)$request->getParam('groupId');
        $colleagueId = (int)$request->getParam('colleagueId');
        $service = $this->getService();
        
        $result = $service->removeFromGroup($this->userEmail, $groupId, $colleagueId);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Member removed from group']);
    }
    
    // ========================================
    // GROUP PERMISSION ENDPOINTS
    // ========================================
    
    /**
     * POST /colleagues/groups/:id/share/folder - Share folder with group
     */
    public function shareFolderWithGroup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $groupId = (int)$request->getParam('id');
        $service = $this->getService();
        $data = $request->input();
        
        $folderId = (int)($data['folder_id'] ?? 0);
        $permission = $data['permission'] ?? 'viewer';
        
        if (!$folderId) {
            return Response::error('folder_id is required', 400);
        }
        
        $result = $service->shareFolderWithGroup($this->userEmail, $folderId, $groupId, $permission);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success([
            'message' => "Shared with {$result['group']} as {$result['permission']}"
        ]);
    }
    
    /**
     * POST /colleagues/groups/:id/share/board - Share board with group
     */
    public function shareBoardWithGroup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $groupId = (int)$request->getParam('id');
        $service = $this->getService();
        $data = $request->input();
        
        $boardId = (int)($data['board_id'] ?? 0);
        $canEdit = (bool)($data['can_edit'] ?? false);
        $canViewFinancials = (bool)($data['can_view_financials'] ?? false);
        
        if (!$boardId) {
            return Response::error('board_id is required', 400);
        }
        
        $result = $service->shareBoardWithGroup($this->userEmail, $boardId, $groupId, $canEdit, $canViewFinancials);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => "Board shared with {$result['group']}"]);
    }
    
    /**
     * POST /colleagues/groups/:id/share/calendar - Share calendar with group
     */
    public function shareCalendarWithGroup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $groupId = (int)$request->getParam('id');
        $service = $this->getService();
        $data = $request->input();
        
        $calendarId = (int)($data['calendar_id'] ?? 0);
        $canEdit = (bool)($data['can_edit'] ?? false);
        $canSeeDetails = (bool)($data['can_see_details'] ?? true);
        
        if (!$calendarId) {
            return Response::error('calendar_id is required', 400);
        }
        
        $result = $service->shareCalendarWithGroup($this->userEmail, $calendarId, $groupId, $canEdit, $canSeeDetails);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => "Calendar shared with {$result['group']}"]);
    }
    
    /**
     * GET /colleagues/groups/:id/folder-access - Get group's folder access
     */
    public function getGroupFolderAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $folderId = (int)$request->getQuery('folder_id', 0);
        $service = $this->getService();
        
        if (!$folderId) {
            return Response::error('folder_id is required', 400);
        }
        
        $access = $service->getFolderGroupAccess($folderId);
        
        return Response::success(['group_access' => $access]);
    }

    /**
     * GET /colleagues/me/permissions - Get current user's effective group permissions
     */
    public function getMyPermissions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $service = $this->getService();
        $perms = $service->getEffectiveGroupPermissions($this->userEmail);

        return Response::success(['permissions' => $perms]);
    }
}

