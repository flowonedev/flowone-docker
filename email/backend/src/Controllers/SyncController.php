<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\DriveService;

/**
 * SyncController - Desktop sync client API endpoints
 * 
 * Provides delta sync capabilities for the FlowOneDrive desktop app:
 * - Get changes since last sync
 * - Upload with checksum verification
 * - Conflict detection
 */
class SyncController extends BaseController
{
    private ?DriveService $driveService = null;
    private ?\PDO $db = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
    }
    
    /**
     * Get DB connection (lazy init)
     */
    private function getDb(): \PDO
    {
        if (!$this->db) {
            $this->db = \Webmail\Core\Database::getConnection($this->config);
            
            $this->ensureChecksumColumn();
        }
        return $this->db;
    }
    
    /**
     * Get DriveService (lazy init, needs userEmail)
     */
    private function getDriveService(): DriveService
    {
        if (!$this->driveService) {
            $this->driveService = new DriveService($this->config, $this->userEmail);
        }
        return $this->driveService;
    }
    
    private function ensureChecksumColumn(): void
    {
        try {
            $result = $this->db->query("SHOW COLUMNS FROM drive_files LIKE 'checksum'");
            if ($result->rowCount() === 0) {
                $this->db->exec("ALTER TABLE drive_files ADD COLUMN checksum VARCHAR(64) DEFAULT NULL COMMENT 'MD5 checksum for sync'");
                $this->db->exec("CREATE INDEX idx_checksum ON drive_files(checksum)");
            }
        } catch (\Exception $e) {
            error_log("SyncController: Failed to add checksum column: " . $e->getMessage());
        }
    }
    
    /**
     * Get all changes since a specific timestamp
     * GET /api/sync/changes?since=2024-01-01T00:00:00Z
     */
    public function getChanges(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $db = $this->getDb();
        $activeEmail = $this->getActiveEmail();
        $since = $request->getQuery('since');
        
        if (!$since) {
            $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        } else {
            $since = date('Y-m-d H:i:s', strtotime($since));
        }
        
        // Get changed files
        $stmt = $db->prepare("
            SELECT 
                id, folder_id, original_name, filename, size, mime_type, checksum,
                created_at, updated_at, is_trashed, current_version
            FROM drive_files 
            WHERE user_email = ? 
            AND (updated_at > ? OR created_at > ?)
            AND is_trashed = 0
            ORDER BY updated_at DESC
        ");
        $stmt->execute([$activeEmail, $since, $since]);
        $changedFiles = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Get changed folders
        $stmt = $db->prepare("
            SELECT 
                id, parent_id, name, created_at, updated_at, is_trashed
            FROM drive_folders 
            WHERE user_email = ? 
            AND (updated_at > ? OR created_at > ?)
            AND is_trashed = 0
            ORDER BY updated_at DESC
        ");
        $stmt->execute([$activeEmail, $since, $since]);
        $changedFolders = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Get deleted items
        $stmt = $db->prepare("
            SELECT id, 'file' as type, original_name as name, trashed_at
            FROM drive_files 
            WHERE user_email = ? AND is_trashed = 1 AND trashed_at > ?
            UNION ALL
            SELECT id, 'folder' as type, name, trashed_at
            FROM drive_folders 
            WHERE user_email = ? AND is_trashed = 1 AND trashed_at > ?
            ORDER BY trashed_at DESC
        ");
        $stmt->execute([$activeEmail, $since, $activeEmail, $since]);
        $deletedItems = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $cursor = date('c');
        
        return Response::success([
            'files' => $changedFiles,
            'folders' => $changedFolders,
            'deleted' => $deletedItems,
            'cursor' => $cursor,
            'has_more' => false,
        ]);
    }
    
    /**
     * Get current sync cursor
     * GET /api/sync/status
     */
    public function getStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $db = $this->getDb();
        $activeEmail = $this->getActiveEmail();
        
        $stmt = $db->prepare("
            SELECT MAX(updated_at) as latest_update
            FROM (
                SELECT updated_at FROM drive_files WHERE user_email = ? AND is_trashed = 0
                UNION ALL
                SELECT updated_at FROM drive_folders WHERE user_email = ? AND is_trashed = 0
            ) as changes
        ");
        $stmt->execute([$activeEmail, $activeEmail]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM drive_files WHERE user_email = ? AND is_trashed = 0");
        $stmt->execute([$activeEmail]);
        $fileCount = $stmt->fetch(\PDO::FETCH_ASSOC)['count'];
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM drive_folders WHERE user_email = ? AND is_trashed = 0");
        $stmt->execute([$activeEmail]);
        $folderCount = $stmt->fetch(\PDO::FETCH_ASSOC)['count'];
        
        $quota = $this->getDriveService()->getQuota($activeEmail);
        
        return Response::success([
            'cursor' => $result['latest_update'] ? date('c', strtotime($result['latest_update'])) : null,
            'file_count' => (int)$fileCount,
            'folder_count' => (int)$folderCount,
            'quota' => $quota,
        ]);
    }
    
    /**
     * Upload file with checksum verification
     * POST /api/sync/upload
     */
    public function upload(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (empty($_FILES['file'])) {
            return Response::error('No file uploaded');
        }
        
        $db = $this->getDb();
        $activeEmail = $this->getActiveEmail();
        $folderId = $request->input('folder_id');
        $clientChecksum = $request->input('checksum');
        
        $serverChecksum = md5_file($_FILES['file']['tmp_name']);
        
        if ($clientChecksum && strtolower($clientChecksum) !== strtolower($serverChecksum)) {
            return Response::error('Checksum mismatch - file may be corrupted', 400);
        }
        
        $stmt = $db->prepare("
            SELECT id, original_name FROM drive_files 
            WHERE user_email = ? AND folder_id IS NOT DISTINCT FROM ? AND checksum = ? AND is_trashed = 0
        ");
        $stmt->execute([$activeEmail, $folderId, $serverChecksum]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($existing) {
            return Response::success([
                'file' => $existing,
                'skipped' => true,
                'reason' => 'File with identical content already exists',
            ]);
        }
        
        $quota = $this->getDriveService()->getQuota($activeEmail);
        $fileSize = $_FILES['file']['size'];
        
        if (!$quota['unlimited'] && $fileSize > $quota['available']) {
            return Response::error('Not enough storage space');
        }
        
        try {
            $file = $this->getDriveService()->uploadFileWithVersioning(
                $activeEmail,
                $_FILES['file'],
                $folderId ? (int)$folderId : null
            );
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }
        
        if (!$file) {
            return Response::error('Failed to upload file');
        }
        
        $stmt = $db->prepare("UPDATE drive_files SET checksum = ? WHERE id = ?");
        $stmt->execute([$serverChecksum, $file['id']]);
        $file['checksum'] = $serverChecksum;
        
        return Response::success([
            'file' => $file,
            'skipped' => false,
        ], 'File uploaded');
    }
    
    /**
     * Get files that may have conflicts
     * POST /api/sync/conflicts
     */
    public function getConflicts(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $db = $this->getDb();
        $activeEmail = $this->getActiveEmail();
        $clientFiles = $request->input('files', []);
        
        if (empty($clientFiles)) {
            return Response::success(['conflicts' => []]);
        }
        
        $conflicts = [];
        
        foreach ($clientFiles as $clientFile) {
            if (empty($clientFile['remote_id'])) continue;
            
            $stmt = $db->prepare("
                SELECT id, original_name, checksum, updated_at, size
                FROM drive_files 
                WHERE id = ? AND user_email = ? AND is_trashed = 0
            ");
            $stmt->execute([$clientFile['remote_id'], $activeEmail]);
            $serverFile = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$serverFile) continue;
            
            $clientChecksum = $clientFile['checksum'] ?? null;
            $serverChecksum = $serverFile['checksum'];
            
            if ($clientChecksum && $serverChecksum && strtolower($clientChecksum) !== strtolower($serverChecksum)) {
                $clientUpdated = $clientFile['local_updated_at'] ?? null;
                $serverUpdated = $serverFile['updated_at'];
                
                $conflicts[] = [
                    'file_id' => $serverFile['id'],
                    'filename' => $serverFile['original_name'],
                    'server_checksum' => $serverChecksum,
                    'client_checksum' => $clientChecksum,
                    'server_updated' => $serverUpdated,
                    'client_updated' => $clientUpdated,
                    'server_size' => $serverFile['size'],
                    'client_size' => $clientFile['size'] ?? null,
                ];
            }
        }
        
        return Response::success(['conflicts' => $conflicts]);
    }
    
    /**
     * Batch update file checksums
     * POST /api/sync/checksums
     */
    public function updateChecksums(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $db = $this->getDb();
        $activeEmail = $this->getActiveEmail();
        $checksums = $request->input('checksums', []);
        
        if (empty($checksums)) {
            return Response::error('No checksums provided');
        }
        
        $updated = 0;
        $stmt = $db->prepare("
            UPDATE drive_files SET checksum = ? 
            WHERE id = ? AND user_email = ?
        ");
        
        foreach ($checksums as $item) {
            if (empty($item['file_id']) || empty($item['checksum'])) continue;
            
            $stmt->execute([$item['checksum'], $item['file_id'], $activeEmail]);
            if ($stmt->rowCount() > 0) {
                $updated++;
            }
        }
        
        return Response::success([
            'updated' => $updated,
        ]);
    }
    
    /**
     * Get shared folder activity for notifications
     * GET /api/sync/shared-activity?since=...
     */
    public function getSharedActivity(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $db = $this->getDb();
        $activeEmail = $this->getActiveEmail();
        $since = $request->getQuery('since');
        
        if (!$since) {
            $since = date('Y-m-d H:i:s', strtotime('-1 day'));
        } else {
            $since = date('Y-m-d H:i:s', strtotime($since));
        }
        
        $stmt = $db->prepare("
            SELECT 
                f.id as folder_id,
                f.name as folder_name,
                f.user_email as owner_email,
                c.permission,
                f.updated_at
            FROM drive_folder_collaborators c
            JOIN drive_folders f ON f.id = c.folder_id
            WHERE c.user_email = ?
            AND f.updated_at > ?
            AND f.is_trashed = 0
            ORDER BY f.updated_at DESC
        ");
        $stmt->execute([$activeEmail, $since]);
        $updatedFolders = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $activity = [];
        foreach ($updatedFolders as $folder) {
            $stmt = $db->prepare("
                SELECT 
                    id, original_name, size, updated_at, last_modified_by
                FROM drive_files 
                WHERE folder_id = ? 
                AND updated_at > ?
                AND is_trashed = 0
                ORDER BY updated_at DESC
                LIMIT 10
            ");
            $stmt->execute([$folder['folder_id'], $since]);
            $changedFiles = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            if (!empty($changedFiles)) {
                $activity[] = [
                    'folder' => $folder,
                    'files' => $changedFiles,
                ];
            }
        }
        
        return Response::success([
            'activity' => $activity,
            'cursor' => date('c'),
        ]);
    }
}
