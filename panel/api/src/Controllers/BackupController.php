<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class BackupController extends BaseController
{
    private string $backupPath = '/var/www/vps-admin/backups';

    /**
     * Resolve a backup id (base64 path) to a real file path.
     *
     * Accepts files in two roots: the local backup directory and the NAS
     * backups directory ({mount_point}/backups). When a local-form id points
     * at a file that was moved to NAS, the path is transparently remapped to
     * the NAS copy (local mail/ maps to NAS emails/). Returns null when the
     * file cannot be found in either root.
     */
    private function resolveBackupPath(?string $id): ?string
    {
        if (!$id) {
            return null;
        }

        $path = base64_decode($id, true);
        if (!$path) {
            return null;
        }

        $localRoot = realpath($this->backupPath);
        $real = realpath($path);

        if ($real !== false && $localRoot !== false && strpos($real, $localRoot) === 0 && is_file($real)) {
            return $real;
        }

        $nasRoot = $this->nasBackupsRoot();
        if ($nasRoot === null) {
            return null;
        }
        $nasRootReal = realpath($nasRoot);
        if ($nasRootReal === false) {
            return null;
        }

        // Direct NAS path id (NAS-only rows from the unified listing).
        if ($real !== false && strpos($real, $nasRootReal) === 0 && is_file($real)) {
            return $real;
        }

        // Local-form id whose archive was moved to NAS: remap under the NAS root.
        if (strpos($path, $this->backupPath) === 0) {
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($this->backupPath))), '/');
            if (str_starts_with($relative, 'mail/')) {
                $relative = 'emails/' . substr($relative, 5);
            }
            $candidate = realpath($nasRoot . '/' . $relative);
            if ($candidate !== false && strpos($candidate, $nasRootReal) === 0 && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Root of the backups tree on the default NAS connection, or null when
     * no active NAS is configured.
     */
    private function nasBackupsRoot(): ?string
    {
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->query("
                SELECT mount_point FROM nas_connections
                WHERE status = 'active' AND mount_point IS NOT NULL AND mount_point != ''
                ORDER BY is_default DESC, id ASC
                LIMIT 1
            ");
            $mount = $stmt->fetchColumn();
            return $mount ? rtrim($mount, '/') . '/backups' : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Whether a resolved path lives on the NAS (vs the local backup dir).
     */
    private function isNasPath(string $path): bool
    {
        $nasRoot = $this->nasBackupsRoot();
        if ($nasRoot === null) {
            return false;
        }
        $nasRootReal = realpath($nasRoot);
        return $nasRootReal !== false && strpos($path, $nasRootReal) === 0;
    }

    /**
     * List all backups
     */
    public function index(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'backups:list';
        
        // Try cache for the full backup list
        $backups = null;
        if (!$forceRefresh) {
            $backups = $this->cache->get($cacheKey);
        }
        
        if ($backups === null) {
            $backups = $this->scanBackups();
            // Cache for 10 minutes
            $this->cache->set($cacheKey, $backups, 600);
        }
        
        // Sort by date descending
        usort($backups, fn($a, $b) => strcmp($b['date'], $a['date']));

        // Pagination
        $pagination = $this->getPagination($request);
        $offset = ($pagination['page'] - 1) * $pagination['per_page'];
        $total = count($backups);
        
        $backups = array_slice($backups, $offset, $pagination['per_page']);

        return Response::success([
            'backups' => $backups,
            'pagination' => [
                'total' => $total,
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'total_pages' => ceil($total / $pagination['per_page']),
            ],
        ]);
    }

    /**
     * Get backup details
     */
    public function show(Request $request): Response
    {
        $id = $request->getParam('id');

        $path = $this->resolveBackupPath($id);
        if ($path === null) {
            return Response::notFound('Backup not found');
        }

        $metaFile = $path . '.meta.json';
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;

        $backup = [
            'id' => $id,
            'path' => $path,
            'filename' => basename($path),
            'size' => filesize($path),
            'size_human' => $this->formatBytes(filesize($path)),
            'date' => date('Y-m-d H:i:s', filemtime($path)),
            'meta' => $meta,
        ];

        // Include content preview for small files
        if (filesize($path) < 102400) { // 100KB
            $backup['content_preview'] = file_get_contents($path);
        }

        return Response::success(['backup' => $backup]);
    }

    /**
     * Download a backup file
     */
    public function download(Request $request): Response
    {
        $id = $request->getParam('id');

        $path = $this->resolveBackupPath($id);
        if ($path === null) {
            return Response::notFound('Backup not found');
        }

        $filename = basename($path);
        $mimeType = 'application/octet-stream';
        
        // Set appropriate mime type
        if (str_ends_with($filename, '.tar.gz') || str_ends_with($filename, '.tgz')) {
            $mimeType = 'application/gzip';
        } elseif (str_ends_with($filename, '.bak')) {
            $mimeType = 'text/plain';
        }

        // Return file download response
        return Response::file($path, $filename, $mimeType);
    }

    /**
     * Restore from backup
     */
    public function restore(Request $request): Response
    {
        $id = $request->getParam('id');

        $path = $this->resolveBackupPath($id);
        if ($path === null) {
            return Response::notFound('Backup not found');
        }

        $filename = basename($path);
        
        // Check if this is a tar.gz archive (multi-category backup)
        if (str_ends_with($filename, '.tar.gz')) {
            return $this->restoreArchive($path);
        }

        // Single file restore (legacy .bak files)
        return $this->restoreSingleFile($path);
    }

    /**
     * Restore a tar.gz archive containing multiple categories
     */
    private function restoreArchive(string $archivePath): Response
    {
        $tempDir = sys_get_temp_dir() . '/vps-restore-' . uniqid();
        
        if (!mkdir($tempDir, 0750, true)) {
            return Response::error('Failed to create temp directory');
        }

        try {
            // Extract archive
            exec("tar -xzf " . escapeshellarg($archivePath) . " -C " . escapeshellarg($tempDir) . " 2>&1", $output, $exitCode);
            
            if ($exitCode !== 0) {
                $this->removeDir($tempDir);
                return Response::error('Failed to extract archive: ' . implode("\n", $output));
            }

            $restored = [];
            $errors = [];
            $preRestoreDir = $this->backupPath . '/pre-restore/' . date('Y-m-d_H-i-s');

            // Scan for all .meta.json files to find original paths
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.meta.json')) {
                    continue;
                }

                $metaContent = file_get_contents($file->getPathname());
                $meta = json_decode($metaContent, true);
                
                if (!$meta || !isset($meta['original_path'])) {
                    continue;
                }

                $originalPath = $meta['original_path'];
                $backedUpPath = str_replace('.meta.json', '', $file->getPathname());
                
                if (!file_exists($backedUpPath)) {
                    $errors[] = "Backup file not found: " . basename($backedUpPath);
                    continue;
                }

                // Create pre-restore backup of current file/dir
                if (file_exists($originalPath)) {
                    $preRestorePath = $preRestoreDir . $originalPath;
                    $preRestoreParent = dirname($preRestorePath);
                    
                    if (!is_dir($preRestoreParent)) {
                        mkdir($preRestoreParent, 0750, true);
                    }
                    
                    if (is_dir($originalPath)) {
                        $this->copyDir($originalPath, $preRestorePath);
                    } else {
                        copy($originalPath, $preRestorePath);
                    }
                }

                // Ensure target directory exists
                $targetDir = dirname($originalPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                // Restore the file/directory
                if (is_dir($backedUpPath)) {
                    if ($this->copyDir($backedUpPath, $originalPath)) {
                        $restored[] = $originalPath;
                    } else {
                        $errors[] = "Failed to restore: {$originalPath}";
                    }
                } else {
                    if (copy($backedUpPath, $originalPath)) {
                        $restored[] = $originalPath;
                    } else {
                        $errors[] = "Failed to restore: {$originalPath}";
                    }
                }
            }

            // Clean up temp directory
            $this->removeDir($tempDir);

            $this->logAction('backup.restore', basename($archivePath), 'success', [
                'restored_count' => count($restored),
                'errors_count' => count($errors),
            ]);

            return Response::success([
                'restored' => $restored,
                'errors' => $errors,
                'pre_restore_backup' => $preRestoreDir,
            ], count($restored) . ' items restored' . (count($errors) ? ', ' . count($errors) . ' errors' : ''));

        } catch (\Exception $e) {
            $this->removeDir($tempDir);
            return Response::error('Restore failed: ' . $e->getMessage());
        }
    }

    /**
     * Restore a single .bak file
     */
    private function restoreSingleFile(string $path): Response
    {
        $metaFile = $path . '.meta.json';
        
        if (!file_exists($metaFile)) {
            return Response::error('Backup metadata not found');
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        
        if (!$meta || !isset($meta['original_path'])) {
            return Response::error('Invalid backup metadata');
        }

        // Create backup of current file before restore
        $originalPath = $meta['original_path'];
        
        if (file_exists($originalPath)) {
            $preRestoreBackup = $this->backupPath . '/pre-restore/' . date('Y-m-d_H-i-s') . '_' . basename($originalPath);
            $preRestoreDir = dirname($preRestoreBackup);
            
            if (!is_dir($preRestoreDir)) {
                mkdir($preRestoreDir, 0750, true);
            }
            
            copy($originalPath, $preRestoreBackup);
        }

        // Restore
        if (copy($path, $originalPath)) {
            $this->logAction('backup.restore', $originalPath, 'success', [
                'backup_file' => $path,
            ]);
            
            return Response::success([
                'restored' => [$originalPath],
            ], 'Backup restored successfully');
        }

        return Response::error('Failed to restore backup');
    }

    /**
     * Copy directory recursively
     */
    private function copyDir(string $src, string $dst): bool
    {
        $dir = opendir($src);
        if (!$dir) {
            return false;
        }

        // Remove existing destination if it's a directory
        if (is_dir($dst)) {
            $this->removeDir($dst);
        }

        if (!mkdir($dst, 0755, true)) {
            return false;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Skip .meta.json files
            if (str_ends_with($file, '.meta.json')) {
                continue;
            }

            $srcPath = "{$src}/{$file}";
            $dstPath = "{$dst}/{$file}";

            if (is_dir($srcPath)) {
                $this->copyDir($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        closedir($dir);
        return true;
    }

    /**
     * Remove directory recursively
     */
    private function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        return rmdir($dir);
    }

    /**
     * Delete a backup
     */
    public function delete(Request $request): Response
    {
        $id = $request->getParam('id');

        $path = $this->resolveBackupPath($id);
        if ($path === null) {
            return Response::notFound('Backup not found');
        }

        // NAS-resident file: deletion must go through the agent (the API web
        // user cannot unlink root-owned files on the NFS mount). Also remove
        // the local move-stub meta so the listing doesn't show a ghost row.
        if ($this->isNasPath($path)) {
            return $this->deleteNasResidentBackup($path);
        }

        $metaFile = $path . '.meta.json';
        $filename = basename($path);
        $nasDeleted = false;
        
        // Check if backup was also stored on NAS
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if (!empty($meta['nas_uploaded']) && $meta['nas_uploaded'] === true) {
                // Determine remote path based on backup type
                $remotePath = null;
                if (!empty($meta['domain'])) {
                    // Site backup
                    $remotePath = "sites/{$meta['domain']}/{$filename}";
                } elseif (!empty($meta['type']) && $meta['type'] === 'config_backup') {
                    // Config backup (stored under manual/ on the NAS)
                    $remotePath = "manual/{$filename}";
                }
                
                if ($remotePath) {
                    // Delete from NAS via agent
                    $nasResult = $this->agent->execute('backup.deleteNasBackups', [
                        'paths' => [$remotePath],
                    ], $this->getActor());
                    $nasDeleted = $nasResult['success'] ?? false;
                }
            }
        }

        if (unlink($path)) {
            @unlink($metaFile);
            
            // Also delete split parts if this is a split archive
            $manifestPath = $path . '.manifest.json';
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                if (!empty($manifest['parts'])) {
                    $baseDir = dirname($path);
                    foreach ($manifest['parts'] as $part) {
                        $partPath = $baseDir . '/' . $part['name'];
                        @unlink($partPath);
                    }
                }
                @unlink($manifestPath);
            }
            
            // Invalidate backup list cache
            $this->cache->invalidateBackups();
            
            $this->logAction('backup.delete', $filename, 'success', [
                'nas_deleted' => $nasDeleted,
            ]);
            
            $message = $nasDeleted ? 'Backup deleted from local and NAS' : 'Backup deleted';
            return Response::success(['nas_deleted' => $nasDeleted], $message);
        }

        return Response::error('Failed to delete backup');
    }

    /**
     * Delete a backup that lives only on the NAS (via the agent) and clean
     * up the local move-stub meta file if one exists.
     */
    private function deleteNasResidentBackup(string $nasPath): Response
    {
        $nasRoot = $this->nasBackupsRoot();
        $nasRootReal = $nasRoot !== null ? realpath($nasRoot) : false;
        if ($nasRootReal === false) {
            return Response::error('NAS root not available');
        }

        $relative = ltrim(str_replace('\\', '/', substr($nasPath, strlen($nasRootReal))), '/');

        $result = $this->agent->execute('backup.deleteNasBackups', [
            'paths' => [$relative],
        ], $this->getActor());

        if (empty($result['success'])) {
            return Response::error($result['error'] ?? 'Failed to delete backup from NAS');
        }

        // Remove the local stub meta (NAS emails/ maps back to local mail/).
        $localRelative = str_starts_with($relative, 'emails/')
            ? 'mail/' . substr($relative, 7)
            : $relative;
        @unlink($this->backupPath . '/' . $localRelative . '.meta.json');

        $this->cache->invalidateBackups();
        $this->logAction('backup.delete', basename($nasPath), 'success', ['nas_only' => true]);

        return Response::success(['nas_deleted' => true], 'Backup deleted from NAS');
    }

    /**
     * Cleanup old backups
     */
    public function cleanup(Request $request): Response
    {
        $maxAgeDays = $request->input('max_age_days', 30);
        $cutoff = strtotime("-{$maxAgeDays} days");
        $deleted = [];
        $nasPathsToDelete = [];

        $backups = $this->scanBackups();
        
        foreach ($backups as $backup) {
            if (strtotime($backup['date']) < $cutoff) {
                // Check if backup was also on NAS
                $metaFile = $backup['path'] . '.meta.json';
                if (file_exists($metaFile)) {
                    $meta = json_decode(file_get_contents($metaFile), true);
                    if (!empty($meta['nas_uploaded']) && $meta['nas_uploaded'] === true) {
                        $filename = basename($backup['path']);
                        if (!empty($meta['domain'])) {
                            $nasPathsToDelete[] = "sites/{$meta['domain']}/{$filename}";
                        } elseif (!empty($meta['type']) && $meta['type'] === 'config_backup') {
                            $nasPathsToDelete[] = "manual/{$filename}";
                        }
                    }
                }
                
                if (unlink($backup['path'])) {
                    @unlink($metaFile);
                    $deleted[] = $backup['filename'];
                }
            }
        }
        
        // Delete from NAS if any
        $nasDeleted = 0;
        if (!empty($nasPathsToDelete)) {
            $nasResult = $this->agent->execute('backup.deleteNasBackups', [
                'paths' => $nasPathsToDelete,
            ], $this->getActor());
            $nasDeleted = $nasResult['data']['deleted_count'] ?? 0;
        }

        // Invalidate backup list cache
        $this->cache->invalidateBackups();
        
        $this->logAction('backup.cleanup', 'backups', 'success', [
            'deleted_count' => count($deleted),
            'nas_deleted_count' => $nasDeleted,
            'max_age_days' => $maxAgeDays,
        ]);

        return Response::success([
            'deleted' => $deleted,
            'count' => count($deleted),
            'nas_deleted' => $nasDeleted,
        ], count($deleted) . ' backups deleted' . ($nasDeleted > 0 ? " ({$nasDeleted} also from NAS)" : ''));
    }

    /**
     * Get available backup categories
     */
    public function categories(Request $request): Response
    {
        return $this->agentAction('backup.getCategories');
    }

    /**
     * Create a manual backup
     */
    public function create(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['categories']);
        if ($validation) return $validation;

        $destination = $request->input('destination', 'local'); // local, nas, both

        // Use extended timeout for config backup operations (20 minutes)
        $result = $this->agent->execute('backup.create', [
            'categories' => $request->input('categories'),
            'destination' => $destination,
        ], $this->getActor(), 1200);

        if ($result['success']) {
            // Invalidate backup list cache
            $this->cache->invalidateBackups();
            
            $this->logAction('backup.create', 'manual', 'success', [
                'categories' => $request->input('categories'),
                'destination' => $destination,
            ]);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Backup created')
            : Response::error($result['error']);
    }

    /**
     * Get backup schedules
     */
    public function schedules(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'backups:schedules';
        
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached, 'Success');
            }
        }
        
        $result = $this->agent->execute('backup.schedules', [], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data'], 300);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get schedules');
    }

    /**
     * Create a backup schedule
     */
    public function createSchedule(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['frequency']);
        if ($validation) return $validation;

        $result = $this->agent->execute('backup.createSchedule', [
            'type' => $request->input('type', 'config'),
            'frequency' => $request->input('frequency'),
            'time' => $request->input('time', '03:00'),
            'day_of_week' => $request->input('day_of_week', 0),
            'categories' => $request->input('categories', []),
            'retention' => $request->input('retention', 7),
            'destination' => $request->input('destination', 'local'),
            'sites' => $request->input('sites'),
            'components' => $request->input('components', ['all']),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate schedules cache
            $this->cache->delete('backups:schedules');
            $this->logAction('backup.create_schedule', $request->input('frequency'), 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Schedule created')
            : Response::error($result['error']);
    }

    /**
     * Update a backup schedule
     */
    public function updateSchedule(Request $request): Response
    {
        $id = $request->getParam('id');

        $params = ['id' => $id];
        
        // Check if this is just a toggle (only enabled field)
        if ($request->has('enabled') && !$request->has('frequency')) {
            $params['enabled'] = $request->input('enabled');
        } else {
            // Full update with all fields
            $params['enabled'] = $request->input('enabled', true);
            $params['type'] = $request->input('type', 'config');
            $params['frequency'] = $request->input('frequency', 'daily');
            $params['time'] = $request->input('time', '03:00');
            $params['day_of_week'] = $request->input('day_of_week', 0);
            $params['retention'] = $request->input('retention', 7);
            $params['destination'] = $request->input('destination', 'local');
            
            if ($params['type'] === 'site') {
                $params['sites'] = $request->input('sites', []);
                $params['components'] = $request->input('components', ['all']);
            } else {
                $params['categories'] = $request->input('categories', []);
            }
        }

        $result = $this->agent->execute('backup.updateSchedule', $params, $this->getActor());

        if ($result['success']) {
            // Invalidate schedules cache
            $this->cache->delete('backups:schedules');
            $this->logAction('backup.update_schedule', $id, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'] ?? [], $result['message'] ?? 'Schedule updated')
            : Response::error($result['error']);
    }

    /**
     * Delete a backup schedule
     */
    public function deleteSchedule(Request $request): Response
    {
        $id = $request->getParam('id');

        $result = $this->agent->execute('backup.deleteSchedule', [
            'id' => $id,
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate schedules cache
            $this->cache->delete('backups:schedules');
            $this->logAction('backup.delete_schedule', $id, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Schedule deleted')
            : Response::error($result['error']);
    }

    /**
     * Run a schedule's backup immediately (in the background on the server).
     */
    public function runScheduleNow(Request $request): Response
    {
        $id = $request->getParam('id');

        $result = $this->agent->execute('backup.runSchedule', [
            'id' => $id,
        ], $this->getActor());

        if ($result['success']) {
            $this->cache->delete('backups:schedules');
            $this->logAction('backup.run_schedule', $id, 'success');
        }

        return $result['success']
            ? Response::success($result['data'] ?? [], $result['message'] ?? 'Backup started')
            : Response::error($result['error'] ?? 'Failed to start backup');
    }

    /**
     * Repair the cron daemon (install/enable/start + normalize cron file).
     */
    public function repairCron(Request $request): Response
    {
        if ($error = $this->requireAdmin()) {
            return $error;
        }

        // Package install + service enable can take a while on a cold dnf cache.
        $result = $this->agent->execute('backup.repairCron', [], $this->getActor(), 360);

        if ($result['success']) {
            $this->cache->delete('backups:schedules');
            $this->logAction('backup.repair_cron', 'cron', 'success');
        }

        return $result['success']
            ? Response::success($result['data'] ?? [], $result['message'] ?? 'Cron repaired')
            : Response::error($result['error'] ?? ($result['message'] ?? 'Cron repair failed'), 500);
    }

    /**
     * Manually transfer an existing backup to NAS (copy or move).
     */
    public function transferToNas(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['id']);
        if ($validation) return $validation;

        // Async (default): the agent validates, spawns a detached runner and
        // returns a status_id right away - the frontend polls for progress.
        // Sync fallback kept for API/CLI callers; large archives can take
        // minutes over the VPN/NFS link, hence the long timeout there.
        $async = (bool)$request->input('async', false);

        $result = $this->agent->execute('backup.transferToNas', [
            'id' => $request->input('id'),
            'mode' => $request->input('mode', 'copy'),
            'async' => $async,
        ], $this->getActor(), $async ? 60 : 600);

        if ($result['success']) {
            $this->cache->invalidateBackups();
            $this->logAction('backup.transfer_nas', $request->input('mode', 'copy'), 'success');
        }

        return $result['success']
            ? Response::success($result['data'] ?? [], $result['message'] ?? 'Backup transferred to NAS')
            : Response::error($result['error'] ?? 'Transfer failed');
    }

    // =========================================================================
    // SITE BACKUP METHODS
    // =========================================================================

    /**
     * List site backups
     */
    public function siteBackups(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        $result = $this->agent->execute('backup.listSiteBackups', [
            'domain' => $domain,
        ], $this->getActor());

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Success')
            : Response::error($result['error']);
    }

    /**
     * Create a site backup (files + database)
     * 
     * Supports async mode for progress tracking:
     * - async=true: Returns immediately with status_id, backup runs in background
     * - async=false (default): Waits for backup to complete (can timeout on large sites)
     */
    public function backupSite(Request $request): Response
    {
        $domain = $request->getParam('domain') ?? $request->input('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        $destination = $request->input('destination', 'local'); // local, nas, both
        $async = $request->input('async', false); // true for progress tracking

        if ($async) {
            // Async mode: short timeout, returns status_id immediately
            $result = $this->agent->execute('backup.backupSite', [
                'domain' => $domain,
                'destination' => $destination,
                'async' => true,
            ], $this->getActor(), 30);

            if ($result['success'] && isset($result['data']['status_id'])) {
                $this->logAction('backup.site.started', $domain, 'success', [
                    'status_id' => $result['data']['status_id'],
                    'destination' => $destination,
                    'async' => true,
                ]);
                
                return Response::success($result['data'], $result['message'] ?? 'Backup started');
            }
            
            return Response::error($result['error'] ?? 'Failed to start backup');
        }

        // Sync mode: extended timeout for backup operations (45 minutes)
        // Large sites (10GB+) need time for: archiving (~10min) + NAS upload (~10min)
        $result = $this->agent->execute('backup.backupSite', [
            'domain' => $domain,
            'destination' => $destination,
            'async' => false,
        ], $this->getActor(), 2700);

        if ($result['success']) {
            // Invalidate backup list cache
            $this->cache->invalidateBackups();
            
            $this->logAction('backup.site', $domain, 'success', [
                'archive' => $result['data']['archive'] ?? null,
                'size' => $result['data']['size_human'] ?? null,
                'destination' => $destination,
            ]);
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Site backed up')
            : Response::error($result['error']);
    }

    /**
     * Backup multiple sites
     */
    public function backupSites(Request $request): Response
    {
        $sites = $request->input('sites');
        
        if (!$sites) {
            return Response::error('Sites list is required');
        }

        // Use extended timeout for multiple sites backup (90 minutes)
        // Multiple large sites may need substantial time
        $result = $this->agent->execute('backup.backupSites', [
            'sites' => $sites,
        ], $this->getActor(), 5400);

        if ($result['success']) {
            $this->logAction('backup.sites', 'multiple', 'success', [
                'count' => count($result['data']['sites'] ?? []),
            ]);
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Sites backed up')
            : Response::error($result['error']);
    }

    /**
     * Restore a site from backup
     * 
     * Supports selective restore:
     * - restore_files: true/false or array of components ['plugins', 'themes', 'uploads', 'wpcore']
     * - restore_database: true/false or array of database names
     * - restore_config: bool - vhost config
     * - restore_ssl: bool - SSL certificates
     * - restore_dns: bool - DNS zone
     * - restore_mail: bool - mail accounts
     * - mode: 'merge' (safe, default) or 'replace' (uses --delete, destructive)
     */
    public function restoreSite(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $backupId = $request->input('backup_id');
        
        if (!$domain || !$backupId) {
            return Response::error('Domain and backup_id are required');
        }

        // Decode backup path from ID (transparently remaps to the NAS copy
        // when the local archive was moved off-server)
        $archivePath = $this->resolveBackupPath($backupId);

        if ($archivePath === null) {
            return Response::notFound('Backup not found');
        }

        // Build restore params - support both boolean and array values
        $restoreFiles = $request->input('restore_files', true);
        $restoreDatabase = $request->input('restore_database', true);
        
        // If components array is provided, use that instead
        $components = $request->input('components');
        if (is_array($components) && !empty($components['files'])) {
            $restoreFiles = $components['files'];
        }
        if (is_array($components) && isset($components['databases'])) {
            $restoreDatabase = $components['databases'];
        }

        // Use extended timeout for restore operations (45 minutes)
        // Large site restores need time for: download from NAS + extraction + DB import
        $result = $this->agent->execute('backup.restoreSite', [
            'domain' => $domain,
            'archive' => $archivePath,
            'restore_files' => $restoreFiles,
            'restore_database' => $restoreDatabase,
            'restore_config' => is_array($components) ? ($components['vhost'] ?? false) : $request->input('restore_config', false),
            'restore_ssl' => is_array($components) ? ($components['ssl'] ?? false) : $request->input('restore_ssl', false),
            'restore_dns' => is_array($components) ? ($components['dns'] ?? false) : $request->input('restore_dns', false),
            'restore_mail' => is_array($components) ? ($components['mail'] ?? false) : $request->input('restore_mail', false),
            'mode' => $request->input('mode', 'merge'), // 'merge' (safe) or 'replace' (destructive)
            'dry_run' => $request->input('dry_run', false),
        ], $this->getActor(), 2700);

        if ($result['success']) {
            $this->logAction('backup.restore_site', $domain, 'success', [
                'restored' => $result['data']['restored'] ?? [],
                'mode' => $request->input('mode', 'merge'),
            ]);
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Site restored')
            : Response::error($result['error']);
    }
    
    /**
     * Inspect a site backup's contents before restore
     * Returns detailed information about what can be restored
     */
    public function inspectSiteBackup(Request $request): Response
    {
        $backupId = $request->getParam('id');
        
        if (!$backupId) {
            return Response::error('Backup ID is required');
        }
        
        $archivePath = $this->resolveBackupPath($backupId);

        if ($archivePath === null) {
            return Response::notFound('Backup not found');
        }
        
        $result = $this->agent->execute('backup.inspectBackup', [
            'archive' => $archivePath,
        ], $this->getActor());
        
        return $result['success']
            ? Response::success($result['data'], 'Backup contents retrieved')
            : Response::error($result['error']);
    }
    
    /**
     * Inspect a config backup's contents before restore
     * Returns detailed information about which categories can be restored
     */
    public function inspectConfigBackup(Request $request): Response
    {
        $backupId = $request->getParam('id');
        
        if (!$backupId) {
            return Response::error('Backup ID is required');
        }
        
        $archivePath = $this->resolveBackupPath($backupId);

        if ($archivePath === null) {
            return Response::notFound('Backup not found');
        }
        
        $result = $this->agent->execute('backup.inspectConfigBackup', [
            'archive' => $archivePath,
        ], $this->getActor());
        
        return $result['success']
            ? Response::success($result['data'], 'Backup contents retrieved')
            : Response::error($result['error']);
    }
    
    /**
     * Restore selected categories from a config backup
     * 
     * @param Request $request
     *   - id: backup ID (base64 encoded path)
     *   - categories: array of category IDs to restore
     */
    public function restoreConfigBackupSelective(Request $request): Response
    {
        $backupId = $request->getParam('id');
        $categories = $request->input('categories', []);
        $dryRun = $request->input('dry_run', false);
        
        if (!$backupId) {
            return Response::error('Backup ID is required');
        }
        
        if (empty($categories)) {
            return Response::error('No categories selected for restore');
        }
        
        $archivePath = $this->resolveBackupPath($backupId);

        if ($archivePath === null) {
            return Response::notFound('Backup not found');
        }
        
        // Use shorter timeout for dry runs (5 minutes), longer for actual restores (20 minutes)
        $timeout = $dryRun ? 300 : 1200;
        
        $result = $this->agent->execute('backup.restoreConfigBackup', [
            'archive' => $archivePath,
            'categories' => $categories,
            'dry_run' => $dryRun,
        ], $this->getActor(), $timeout);
        
        // Only log actual restores, not dry runs
        if ($result['success'] && !$dryRun) {
            $this->logAction('backup.restore_config', 'selective', 'success', [
                'categories' => $categories,
                'restored_count' => count($result['data']['restored'] ?? []),
            ]);
        }
        
        // Include logs in response for dry runs
        if ($dryRun && isset($result['logs'])) {
            $result['data']['logs'] = $result['logs'];
        }
        
        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Config backup restored')
            : Response::error($result['error'], $result['logs'] ?? null);
    }

    /**
     * Backup a database
     */
    public function backupDatabase(Request $request): Response
    {
        $database = $request->input('database');
        
        if (!$database) {
            return Response::error('Database name is required');
        }

        // Use extended timeout for database backup (30 minutes for large DBs)
        $result = $this->agent->execute('backup.backupDatabase', [
            'database' => $database,
        ], $this->getActor(), 1800);

        if ($result['success']) {
            $this->logAction('backup.database', $database, 'success');
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Database backed up')
            : Response::error($result['error']);
    }

    /**
     * Restore a database from backup
     */
    public function restoreDatabase(Request $request): Response
    {
        $database = $request->input('database');
        $backupId = $request->input('backup_id');
        
        if (!$database || !$backupId) {
            return Response::error('Database and backup_id are required');
        }

        $backupFile = $this->resolveBackupPath($backupId);

        if ($backupFile === null) {
            return Response::notFound('Backup file not found');
        }

        // Use extended timeout for database restore (30 minutes for large DBs)
        $result = $this->agent->execute('backup.restoreDatabase', [
            'database' => $database,
            'file' => $backupFile,
        ], $this->getActor(), 1800);

        if ($result['success']) {
            $this->logAction('backup.restore_database', $database, 'success');
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Database restored')
            : Response::error($result['error']);
    }

    /**
     * Scan backup directories (config backups only, excludes site backups)
     */
    private function scanBackups(): array
    {
        $backups = [];

        if (!is_dir($this->backupPath)) {
            return $backups;
        }

        // Site backups are stored in /sites/ subdirectory - exclude them
        $sitesDir = realpath($this->backupPath . '/sites');
        // Email backups are stored in /mail/ subdirectory - exclude them
        $mailDir = realpath($this->backupPath . '/mail');

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->backupPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                try {
                    if (!$file->isFile()) {
                        continue;
                    }
                    
                    // Skip files in the sites directory (site backups)
                    $filePath = $file->getPathname();
                    if ($sitesDir && strpos(realpath($filePath) ?: $filePath, $sitesDir) === 0) {
                        continue;
                    }
                    
                    // Skip files in the mail directory (email backups)
                    if ($mailDir && strpos(realpath($filePath) ?: $filePath, $mailDir) === 0) {
                        continue;
                    }
                    
                    $ext = $file->getExtension();
                    // Support both .bak files and .tar.gz archives
                    $isBak = $ext === 'bak';
                    $filename = $file->getFilename();
                    $isTarGz = $ext === 'gz' && (str_ends_with($filename, '.tar.gz') || str_contains($filename, '.tar.'));

                    // Move-to-NAS stub: a .meta.json whose archive was moved
                    // off-server. Keep it visible in the list as NAS-only.
                    if (str_ends_with($filename, '.tar.gz.meta.json')) {
                        $archiveName = substr($filename, 0, -strlen('.meta.json'));
                        $archivePath = dirname($file->getPathname()) . '/' . $archiveName;
                        if (file_exists($archivePath)) {
                            continue; // archive still present - listed normally
                        }

                        $meta = json_decode((string)file_get_contents($file->getPathname()), true) ?: [];
                        if (empty($meta['nas_uploaded'])) {
                            continue;
                        }

                        $backups[] = [
                            'id' => base64_encode($archivePath), // resolver remaps to the NAS copy
                            'path' => $archivePath,
                            'filename' => $archiveName,
                            'size' => $meta['archive_size'] ?? 0,
                            'size_human' => isset($meta['archive_size']) ? $this->formatBytes((int)$meta['archive_size']) : 'N/A',
                            'date' => date('Y-m-d H:i:s', $file->getMTime()),
                            'type' => 'archive',
                            'original_path' => $meta['original_path'] ?? null,
                            'action' => $meta['action'] ?? null,
                            'actor' => $meta['actor'] ?? null,
                            'categories' => $meta['categories'] ?? null,
                            'category_labels' => $meta['category_labels'] ?? null,
                            'contents' => $meta['category_labels'] ?? null,
                            'destination' => $meta['destination'] ?? 'nas',
                            'nas_uploaded' => true,
                            'location' => 'nas',
                        ];
                        continue;
                    }

                    if ($isBak || $isTarGz) {
                        $path = $file->getPathname();
                        $metaFile = $path . '.meta.json';
                        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;

                        $nasUploaded = $meta['nas_uploaded'] ?? $meta['webdav_uploaded'] ?? false; // Support legacy webdav_uploaded

                        $backups[] = [
                            'id' => base64_encode($path),
                            'path' => $path,
                            'filename' => $filename,
                            'size' => $file->getSize(),
                            'size_human' => $this->formatBytes($file->getSize()),
                            'date' => date('Y-m-d H:i:s', $file->getMTime()),
                            'type' => $isTarGz ? 'archive' : 'config',
                            'original_path' => $meta['original_path'] ?? null,
                            'action' => $meta['action'] ?? null,
                            'actor' => $meta['actor'] ?? null,
                            'categories' => $meta['categories'] ?? null,
                            'category_labels' => $meta['category_labels'] ?? null,
                            'contents' => $meta['category_labels'] ?? ($meta['original_path'] ? [basename($meta['original_path'])] : null),
                            'destination' => $meta['destination'] ?? 'local',
                            'nas_uploaded' => $nasUploaded,
                            'location' => $nasUploaded ? 'both' : 'local',
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip files that cause errors
                    continue;
                }
            }
        } catch (\Exception $e) {
            // Return empty array on error
            return $backups;
        }

        return $backups;
    }

    /**
     * Format bytes
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // =========================================================================
    // NAS Remote Backup Methods
    // =========================================================================

    /**
     * Get available NAS connections for backup
     */
    public function nasConnections(Request $request): Response
    {
        $result = $this->callAgent('backup.getNasConnections', []);
        
        if ($result['success']) {
            return Response::success($result['data'] ?? []);
        }
        
        return Response::error($result['error'] ?? 'Failed to get NAS connections');
    }

    /**
     * List backups stored on NAS
     * 
     * Query params:
     *   - nas_id: optional NAS connection ID
     *   - path: optional subpath
     *   - type: optional filter - 'config', 'sites', 'emails', or null for all
     */
    public function nasBackups(Request $request): Response
    {
        $nasId = $request->getQuery('nas_id');
        $path = $request->getQuery('path');
        $type = $request->getQuery('type'); // 'config', 'sites', 'emails'
        
        $result = $this->callAgent('backup.listNasBackups', [
            'nas_id' => $nasId ? (int)$nasId : null,
            'path' => $path,
            'type' => $type,
        ]);
        
        if ($result['success']) {
            return Response::success($result['data'] ?? []);
        }
        
        return Response::error($result['error'] ?? 'Failed to list NAS backups');
    }
    
    /**
     * Delete backups from NAS storage
     */
    public function deleteNasBackups(Request $request): Response
    {
        $paths = $request->input('paths');
        $nasId = $request->input('nas_id');
        
        if (empty($paths) || !is_array($paths)) {
            return Response::error('No backup paths specified');
        }
        
        $result = $this->callAgent('backup.deleteNasBackups', [
            'paths' => $paths,
            'nas_id' => $nasId ? (int)$nasId : null,
        ]);
        
        if ($result['success']) {
            // Update local backup metadata to reflect NAS deletion
            $this->updateLocalMetadataAfterNasDeletion($result['data']['deleted'] ?? []);
            
            // Invalidate backup caches
            $this->cache->invalidateBackups();
            
            $this->logAction('backup.delete_nas', 'multiple', 'success', [
                'deleted_count' => $result['data']['deleted_count'] ?? 0,
            ]);
            
            return Response::success($result['data'], $result['message'] ?? 'Backups deleted');
        }
        
        return Response::error($result['error'] ?? 'Failed to delete NAS backups');
    }
    
    /**
     * Update local backup metadata after NAS files are deleted
     * This ensures the Config Backups list shows accurate NAS status
     */
    private function updateLocalMetadataAfterNasDeletion(array $deletedFilenames): void
    {
        if (empty($deletedFilenames)) return;
        
        // Scan local backups and update metadata for matching filenames
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->backupPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                
                $filename = $file->getFilename();
                
                // Check if this file was deleted from NAS
                if (in_array($filename, $deletedFilenames)) {
                    $metaFile = $file->getPathname() . '.meta.json';
                    
                    if (file_exists($metaFile)) {
                        $meta = json_decode(file_get_contents($metaFile), true);
                        if ($meta) {
                            // Mark as no longer on NAS
                            $meta['nas_uploaded'] = false;
                            $meta['nas_deleted_at'] = date('Y-m-d H:i:s');
                            
                            // If destination was 'nas' only, update to reflect it's now local only
                            if (($meta['destination'] ?? '') === 'nas') {
                                $meta['destination'] = 'local';
                            } elseif (($meta['destination'] ?? '') === 'both') {
                                $meta['destination'] = 'local';
                            }
                            
                            file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Log but don't fail - this is a best-effort update
            debug_log("Failed to update local metadata after NAS deletion: " . $e->getMessage());
        }
    }

    // =========================================================================
    // BACKUP STATUS TRACKING
    // =========================================================================

    /**
     * Get status of a running or recent backup operation
     */
    public function getBackupStatus(Request $request): Response
    {
        $statusId = $request->getQuery('status_id');
        $domain = $request->getQuery('domain');
        
        if (!$statusId && !$domain) {
            return Response::error('status_id or domain is required');
        }
        
        $result = $this->callAgent('backup.getBackupStatus', [
            'status_id' => $statusId,
            'domain' => $domain,
        ]);
        
        if ($result['success']) {
            return Response::success($result['data']);
        }
        
        return Response::error($result['error'] ?? 'Backup status not found');
    }

    /**
     * List all running backup operations
     */
    public function listRunningBackups(Request $request): Response
    {
        $result = $this->callAgent('backup.listRunningBackups', []);
        
        if ($result['success']) {
            return Response::success($result['data']);
        }
        
        return Response::error($result['error'] ?? 'Failed to get running backups');
    }

    // =========================================================================
    // EMAIL BACKUP METHODS
    // =========================================================================

    /**
     * List mail domains available for backup
     */
    public function listMailDomains(Request $request): Response
    {
        $result = $this->callAgent('backup.listMailDomains', []);
        
        if ($result['success']) {
            return Response::success($result['data']);
        }
        
        return Response::error($result['error'] ?? 'Failed to list mail domains');
    }

    /**
     * List email backups
     */
    public function mailBackups(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        $result = $this->callAgent('backup.listMailBackups', [
            'domain' => $domain,
        ]);

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Success')
            : Response::error($result['error']);
    }

    /**
     * Create an email backup
     */
    public function backupMail(Request $request): Response
    {
        $domain = $request->getParam('domain') ?? $request->input('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }

        $destination = $request->input('destination', 'local');
        $async = $request->input('async', false);

        // Use extended timeout for mail backups (30 minutes)
        $result = $this->agent->execute('backup.backupMail', [
            'domain' => $domain,
            'destination' => $destination,
            'async' => $async,
        ], $this->getActor(), 1800);

        if ($result['success']) {
            $this->cache->invalidateBackups();
            
            $this->logAction('backup.mail', $domain, 'success', [
                'archive' => $result['data']['archive'] ?? null,
                'size' => $result['data']['size_human'] ?? null,
                'accounts' => $result['data']['accounts'] ?? 0,
                'destination' => $destination,
            ]);
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Mail backed up')
            : Response::error($result['error']);
    }

    /**
     * Inspect a mail backup
     */
    public function inspectMailBackup(Request $request): Response
    {
        $id = $request->getParam('id');
        
        if (!$id) {
            return Response::error('Backup ID is required');
        }
        
        $result = $this->callAgent('backup.inspectMailBackup', [
            'id' => $id,
        ]);
        
        return $result['success']
            ? Response::success($result['data'])
            : Response::error($result['error']);
    }

    /**
     * Restore email from backup
     */
    public function restoreMail(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $body = $request->getBody();
        
        $backupId = $body['backup_id'] ?? null;
        $dryRun = $body['dry_run'] ?? false;
        
        if (!$backupId) {
            return Response::error('Backup ID is required');
        }
        
        // Use extended timeout for restore (30 minutes), shorter for dry run
        $timeout = $dryRun ? 300 : 1800;
        $result = $this->agent->execute('backup.restoreMail', [
            'id' => $backupId,
            'domain' => $domain,
            'restore_mailboxes' => $body['restore_mailboxes'] ?? true,
            'restore_accounts' => $body['restore_accounts'] ?? true,
            'restore_dkim' => $body['restore_dkim'] ?? true,
            'mode' => $body['mode'] ?? 'merge',
            'dry_run' => $dryRun,
        ], $this->getActor(), $timeout);
        
        if ($result['success']) {
            $this->cache->invalidateBackups();
            
            $this->logAction('backup.mail.restore', $domain ?? 'unknown', 'success', [
                'restored' => $result['data']['restored'] ?? [],
            ]);
        }
        
        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Mail restored')
            : Response::error($result['error'], 500, $result['data'] ?? null);
    }
}

