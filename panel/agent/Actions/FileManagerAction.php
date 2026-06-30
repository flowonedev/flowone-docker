<?php
/**
 * File Manager Action Handler
 * 
 * Provides file system operations for the VPS Admin panel
 * Supports browsing, editing, uploading, and managing files/directories
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class FileManagerAction extends BaseAction
{
    // Dangerous paths that should never be accessible
    private array $blockedPaths = [
        '/root/.ssh',
        '/root/.bash_history',
        '/etc/shadow',
        '/etc/gshadow',
        '/etc/passwd',
        '/etc/sudoers',
        '/etc/sudoers.d',
        '/proc',
        '/sys',
    ];

    // Base paths users can access (will be expanded based on context)
    private array $allowedBasePaths = [
        '/home',
        '/var/www',
        '/var/log',
        '/etc/nginx',
        '/etc/apache2',
        '/etc/systemd/system',  // For email app service files
        '/usr/local/lsws',
        '/tmp',
    ];

    public function getNamespace(): string
    {
        return 'filemanager';
    }

    public function getMethods(): array
    {
        return [
            'list',
            'read',
            'write',
            'mkdir',
            'delete',
            'copy',
            'move',
            'rename',
            'permissions',
            'info',
            'search',
            'compress',
            'extract',
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['write', 'delete', 'move', 'rename', 'permissions', 'compress', 'extract']);
    }

    /**
     * List directory contents
     */
    protected function actionList(array $params, string $actor): array
    {
        $path = $params['path'] ?? '/home';
        $showHidden = $params['show_hidden'] ?? false;
        $sortBy = $params['sort_by'] ?? 'name'; // name, size, modified, type
        $sortDir = $params['sort_dir'] ?? 'asc';

        // Resolve and validate path
        $realPath = $this->resolvePath($path);
        if (!$realPath) {
            return $this->error("Invalid path: {$path}");
        }

        if (!$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        if (!is_dir($realPath)) {
            return $this->error("Not a directory: {$path}");
        }

        $items = [];
        $dirHandle = opendir($realPath);
        
        if (!$dirHandle) {
            return $this->error("Cannot open directory: {$path}");
        }

        while (($item = readdir($dirHandle)) !== false) {
            if ($item === '.' || $item === '..') continue;
            if (!$showHidden && $item[0] === '.') continue;

            $itemPath = "{$realPath}/{$item}";
            $stat = @stat($itemPath);
            
            if (!$stat) continue;

            $isDir = is_dir($itemPath);
            $isLink = is_link($itemPath);
            
            $items[] = [
                'name' => $item,
                'path' => "{$path}/{$item}",
                'type' => $isDir ? 'directory' : 'file',
                'is_link' => $isLink,
                'link_target' => $isLink ? @readlink($itemPath) : null,
                'size' => $isDir ? null : $stat['size'],
                'size_human' => $isDir ? '-' : $this->formatBytes($stat['size']),
                'permissions' => $this->formatPermissions($stat['mode']),
                'permissions_octal' => substr(sprintf('%o', $stat['mode']), -4),
                'owner' => posix_getpwuid($stat['uid'])['name'] ?? $stat['uid'],
                'group' => posix_getgrgid($stat['gid'])['name'] ?? $stat['gid'],
                'modified' => date('Y-m-d H:i:s', $stat['mtime']),
                'modified_timestamp' => $stat['mtime'],
                'extension' => $isDir ? null : strtolower(pathinfo($item, PATHINFO_EXTENSION)),
                'mime_type' => $isDir ? 'directory' : $this->getMimeType($itemPath),
                'is_readable' => is_readable($itemPath),
                'is_writable' => is_writable($itemPath),
                'is_executable' => is_executable($itemPath),
            ];
        }

        closedir($dirHandle);

        // Sort items
        usort($items, function($a, $b) use ($sortBy, $sortDir) {
            // Directories first
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }

            $cmp = 0;
            switch ($sortBy) {
                case 'size':
                    $cmp = ($a['size'] ?? 0) <=> ($b['size'] ?? 0);
                    break;
                case 'modified':
                    $cmp = $a['modified_timestamp'] <=> $b['modified_timestamp'];
                    break;
                case 'type':
                    $cmp = ($a['extension'] ?? '') <=> ($b['extension'] ?? '');
                    break;
                default:
                    $cmp = strcasecmp($a['name'], $b['name']);
            }

            return $sortDir === 'desc' ? -$cmp : $cmp;
        });

        // Get parent path
        $parentPath = dirname($path);
        if ($parentPath === $path) {
            $parentPath = null;
        }

        // Get directory info
        $dirStat = stat($realPath);

        return $this->success([
            'path' => $path,
            'real_path' => $realPath,
            'parent' => $parentPath,
            'items' => $items,
            'count' => count($items),
            'permissions' => $this->formatPermissions($dirStat['mode']),
            'owner' => posix_getpwuid($dirStat['uid'])['name'] ?? $dirStat['uid'],
            'group' => posix_getgrgid($dirStat['gid'])['name'] ?? $dirStat['gid'],
        ]);
    }

    /**
     * Read file contents
     * Handles restricted files by temporarily changing permissions via shell (root)
     */
    protected function actionRead(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;
        $encoding = $params['encoding'] ?? 'utf-8';
        $maxSize = $params['max_size'] ?? 5 * 1024 * 1024; // 5MB default

        if (!$path) {
            return $this->error('Path is required');
        }

        $realPath = $this->resolvePath($path);
        if (!$realPath || !$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        if (!file_exists($realPath)) {
            return $this->error("File not found: {$path}");
        }

        if (is_dir($realPath)) {
            return $this->error("Cannot read directory as file: {$path}");
        }

        // Store original permissions for potential restoration
        $stat = stat($realPath);
        $originalPerms = $stat['mode'] & 0777;
        $originalPermsOctal = decoct($originalPerms);
        $permissionsChanged = false;
        
        // Check if file is readable, if not try to temporarily change permissions via shell
        if (!is_readable($realPath)) {
            // Use shell chmod (runs as root) to add world read permission
            $newPerms = $originalPerms | 0004; // Add world read
            $newPermsOctal = sprintf('%04o', $newPerms);
            
            $result = $this->execCommand('chmod', [$newPermsOctal, $realPath]);
            if ($result['exit_code'] === 0) {
                $permissionsChanged = true;
                clearstatcache(true, $realPath);
                $this->logger->info("Temporarily changed permissions on {$realPath} from {$originalPermsOctal} to {$newPermsOctal} for reading");
            } else {
                // Try 0644 as fallback
                $result = $this->execCommand('chmod', ['0644', $realPath]);
                if ($result['exit_code'] === 0) {
                    $permissionsChanged = true;
                    clearstatcache(true, $realPath);
                    $this->logger->info("Temporarily changed permissions on {$realPath} to 0644 for reading");
                } else {
                    return $this->error("Cannot read file (permission denied): {$path}");
                }
            }
        }

        $size = @filesize($realPath);
        if ($size === false) {
            if ($permissionsChanged) {
                $this->execCommand('chmod', [$originalPermsOctal, $realPath]);
            }
            return $this->error("Cannot get file size: {$path}");
        }
        
        if ($size > $maxSize) {
            if ($permissionsChanged) {
                $this->execCommand('chmod', [$originalPermsOctal, $realPath]);
            }
            return $this->error("File too large ({$this->formatBytes($size)}). Maximum: {$this->formatBytes($maxSize)}");
        }

        $content = @file_get_contents($realPath);
        
        // Restore original permissions immediately after reading
        if ($permissionsChanged) {
            $this->execCommand('chmod', [$originalPermsOctal, $realPath]);
            clearstatcache(true, $realPath);
            $this->logger->info("Restored permissions on {$realPath} to {$originalPermsOctal}");
        }
        
        if ($content === false) {
            return $this->error("Cannot read file: {$path}");
        }

        // Check if binary
        $isBinary = $this->isBinaryContent($content);
        
        // Re-stat to get current info (after restoring permissions)
        $stat = stat($realPath);
        $mimeType = $this->getMimeType($realPath);

        return $this->success([
            'path' => $path,
            'name' => basename($path),
            'content' => $isBinary ? base64_encode($content) : $content,
            'is_binary' => $isBinary,
            'encoding' => $isBinary ? 'base64' : $encoding,
            'size' => $size,
            'size_human' => $this->formatBytes($size),
            'mime_type' => $mimeType,
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'permissions' => $this->formatPermissions($stat['mode']),
            'permissions_octal' => decoct($stat['mode'] & 0777),
            'modified' => date('Y-m-d H:i:s', $stat['mtime']),
            'is_writable' => is_writable($realPath),
            'permissions_temporarily_changed' => $permissionsChanged,
        ]);
    }

    /**
     * Write file contents
     * Handles read-only files by temporarily changing permissions via shell (root)
     */
    protected function actionWrite(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;
        $content = $params['content'] ?? '';
        $encoding = $params['encoding'] ?? 'utf-8';
        $createDirs = $params['create_dirs'] ?? false;

        if (!$path) {
            return $this->error('Path is required');
        }

        $realPath = $this->resolvePath($path, true);
        if (!$realPath || !$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        // Check if trying to overwrite directory
        if (is_dir($realPath)) {
            return $this->error("Cannot write to directory: {$path}");
        }

        // Create parent directories if needed
        $parentDir = dirname($realPath);
        if (!is_dir($parentDir)) {
            if ($createDirs) {
                if (!mkdir($parentDir, 0755, true)) {
                    return $this->error("Cannot create directory: " . dirname($path));
                }
            } else {
                return $this->error("Parent directory does not exist: " . dirname($path));
            }
        }

        // Store original permissions and ownership if file exists
        $originalPermsOctal = null;
        $originalOwner = null;
        $originalGroup = null;
        $permissionsChanged = false;
        $fileExists = file_exists($realPath);
        
        if ($fileExists) {
            $stat = stat($realPath);
            $originalPerms = $stat['mode'] & 0777;
            $originalPermsOctal = sprintf('%04o', $originalPerms);
            $originalOwner = $stat['uid'];
            $originalGroup = $stat['gid'];
            
            // Get owner/group names for restoration
            $ownerInfo = posix_getpwuid($originalOwner);
            $groupInfo = posix_getgrgid($originalGroup);
            $originalOwnerName = $ownerInfo['name'] ?? $originalOwner;
            $originalGroupName = $groupInfo['name'] ?? $originalGroup;
            
            // If file is not writable, temporarily change permissions via shell
            if (!is_writable($realPath)) {
                // Make file writable (add write permission)
                $newPerms = $originalPerms | 0006; // Add world read+write
                $newPermsOctal = sprintf('%04o', $newPerms);
                
                $result = $this->execCommand('chmod', [$newPermsOctal, $realPath]);
                if ($result['exit_code'] === 0) {
                    $permissionsChanged = true;
                    clearstatcache(true, $realPath);
                    $this->logger->info("Temporarily changed permissions on {$realPath} from {$originalPermsOctal} to {$newPermsOctal}");
                } else {
                    // Try 0666 as fallback
                    $result = $this->execCommand('chmod', ['0666', $realPath]);
                    if ($result['exit_code'] === 0) {
                        $permissionsChanged = true;
                        clearstatcache(true, $realPath);
                        $this->logger->info("Temporarily changed permissions on {$realPath} to 0666");
                    } else {
                        return $this->error("Cannot change file permissions to write: {$path}");
                    }
                }
            }
        }

        // Decode base64 if needed
        if ($encoding === 'base64') {
            $content = base64_decode($content);
            if ($content === false) {
                // Restore permissions before returning error
                if ($permissionsChanged && $originalPermsOctal !== null) {
                    $this->execCommand('chmod', [$originalPermsOctal, $realPath]);
                }
                return $this->error('Invalid base64 content');
            }
        }

        // Write file
        $result = @file_put_contents($realPath, $content);
        
        // Restore original permissions if they were changed
        if ($permissionsChanged && $originalPermsOctal !== null) {
            $this->execCommand('chmod', [$originalPermsOctal, $realPath]);
            $this->logger->info("Restored permissions on {$realPath} to {$originalPermsOctal}");
        }
        
        // Restore original ownership if needed (for new files or if it changed)
        if ($fileExists && isset($originalOwnerName) && isset($originalGroupName)) {
            clearstatcache(true, $realPath);
            $newStat = stat($realPath);
            if ($newStat['uid'] !== $originalOwner || $newStat['gid'] !== $originalGroup) {
                $this->execCommand('chown', ["{$originalOwnerName}:{$originalGroupName}", $realPath]);
            }
        }
        
        if ($result === false) {
            return $this->error("Cannot write to file: {$path}");
        }

        clearstatcache(true, $realPath);
        $stat = stat($realPath);

        return $this->success([
            'path' => $path,
            'size' => $result,
            'size_human' => $this->formatBytes($result),
            'modified' => date('Y-m-d H:i:s', $stat['mtime']),
            'permissions_restored' => $permissionsChanged,
            'original_permissions' => $originalPermsOctal,
        ], $permissionsChanged ? "File saved (permissions temporarily changed then restored)" : "File saved successfully");
    }

    /**
     * Create directory
     */
    protected function actionMkdir(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;
        $recursive = $params['recursive'] ?? true;
        $mode = $params['mode'] ?? 0755;

        if (!$path) {
            return $this->error('Path is required');
        }

        $realPath = $this->resolvePath($path, true);
        if (!$realPath || !$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        if (file_exists($realPath)) {
            return $this->error("Path already exists: {$path}");
        }

        if (!mkdir($realPath, $mode, $recursive)) {
            return $this->error("Cannot create directory: {$path}");
        }

        return $this->success([
            'path' => $path,
            'created' => true,
        ], "Directory created successfully");
    }

    /**
     * Delete file or directory
     */
    protected function actionDelete(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;
        $recursive = $params['recursive'] ?? false;

        if (!$path) {
            return $this->error('Path is required');
        }

        $realPath = $this->resolvePath($path);
        if (!$realPath || !$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        if (!file_exists($realPath)) {
            return $this->error("Path not found: {$path}");
        }

        // Prevent deleting root directories
        if (in_array($realPath, ['/home', '/var', '/etc', '/usr', '/root', '/'])) {
            return $this->error("Cannot delete system directory: {$path}");
        }

        if (is_dir($realPath)) {
            if ($recursive) {
                if (!$this->deleteDirectory($realPath)) {
                    return $this->error("Cannot delete directory: {$path}");
                }
            } else {
                // Check if empty
                $items = scandir($realPath);
                if (count($items) > 2) {
                    return $this->error("Directory is not empty. Use recursive=true to delete: {$path}");
                }
                if (!rmdir($realPath)) {
                    return $this->error("Cannot delete directory: {$path}");
                }
            }
        } else {
            if (!unlink($realPath)) {
                return $this->error("Cannot delete file: {$path}");
            }
        }

        return $this->success([
            'path' => $path,
            'deleted' => true,
        ], "Deleted successfully");
    }

    /**
     * Copy file or directory
     */
    protected function actionCopy(array $params, string $actor): array
    {
        $source = $params['source'] ?? null;
        $destination = $params['destination'] ?? null;
        $overwrite = $params['overwrite'] ?? false;

        if (!$source || !$destination) {
            return $this->error('Source and destination are required');
        }

        $srcPath = $this->resolvePath($source);
        $dstPath = $this->resolvePath($destination, true);

        if (!$srcPath || !$this->isPathAllowed($srcPath)) {
            return $this->error("Access denied to source: {$source}");
        }

        if (!$dstPath || !$this->isPathAllowed($dstPath)) {
            return $this->error("Access denied to destination: {$destination}");
        }

        if (!file_exists($srcPath)) {
            return $this->error("Source not found: {$source}");
        }

        if (file_exists($dstPath) && !$overwrite) {
            return $this->error("Destination already exists: {$destination}");
        }

        if (is_dir($srcPath)) {
            if (!$this->copyDirectory($srcPath, $dstPath)) {
                return $this->error("Cannot copy directory");
            }
        } else {
            // Ensure destination directory exists
            $dstDir = dirname($dstPath);
            if (!is_dir($dstDir)) {
                mkdir($dstDir, 0755, true);
            }
            
            if (!copy($srcPath, $dstPath)) {
                return $this->error("Cannot copy file");
            }
        }

        return $this->success([
            'source' => $source,
            'destination' => $destination,
            'copied' => true,
        ], "Copied successfully");
    }

    /**
     * Move file or directory
     */
    protected function actionMove(array $params, string $actor): array
    {
        $source = $params['source'] ?? null;
        $destination = $params['destination'] ?? null;
        $overwrite = $params['overwrite'] ?? false;

        if (!$source || !$destination) {
            return $this->error('Source and destination are required');
        }

        $srcPath = $this->resolvePath($source);
        $dstPath = $this->resolvePath($destination, true);

        if (!$srcPath || !$this->isPathAllowed($srcPath)) {
            return $this->error("Access denied to source: {$source}");
        }

        if (!$dstPath || !$this->isPathAllowed($dstPath)) {
            return $this->error("Access denied to destination: {$destination}");
        }

        if (!file_exists($srcPath)) {
            return $this->error("Source not found: {$source}");
        }

        if (file_exists($dstPath) && !$overwrite) {
            return $this->error("Destination already exists: {$destination}");
        }

        // Ensure destination directory exists
        $dstDir = dirname($dstPath);
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }

        if (!rename($srcPath, $dstPath)) {
            return $this->error("Cannot move/rename");
        }

        return $this->success([
            'source' => $source,
            'destination' => $destination,
            'moved' => true,
        ], "Moved successfully");
    }

    /**
     * Rename file or directory
     */
    protected function actionRename(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;
        $newName = $params['new_name'] ?? null;

        if (!$path || !$newName) {
            return $this->error('Path and new name are required');
        }

        // Validate new name
        if (strpos($newName, '/') !== false || strpos($newName, '\\') !== false) {
            return $this->error('New name cannot contain path separators');
        }

        $destination = dirname($path) . '/' . $newName;
        
        return $this->actionMove([
            'source' => $path,
            'destination' => $destination,
        ], $actor);
    }

    /**
     * Get or set file permissions
     */
    protected function actionPermissions(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;
        $mode = $params['mode'] ?? null;
        $recursive = $params['recursive'] ?? false;
        $owner = $params['owner'] ?? null;
        $group = $params['group'] ?? null;

        if (!$path) {
            return $this->error('Path is required');
        }

        $realPath = $this->resolvePath($path);
        if (!$realPath || !$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        if (!file_exists($realPath)) {
            return $this->error("Path not found: {$path}");
        }

        // If mode is provided, set permissions
        if ($mode !== null) {
            $octal = is_string($mode) ? octdec($mode) : $mode;
            
            if ($recursive && is_dir($realPath)) {
                $this->chmodRecursive($realPath, $octal);
            } else {
                chmod($realPath, $octal);
            }
        }

        // Change owner if provided
        if ($owner !== null) {
            if ($recursive && is_dir($realPath)) {
                $this->execCommand('chown', [$recursive ? '-R' : '', $owner, $realPath]);
            } else {
                chown($realPath, $owner);
            }
        }

        // Change group if provided
        if ($group !== null) {
            if ($recursive && is_dir($realPath)) {
                $this->execCommand('chgrp', [$recursive ? '-R' : '', $group, $realPath]);
            } else {
                chgrp($realPath, $group);
            }
        }

        // Get current permissions
        $stat = stat($realPath);

        return $this->success([
            'path' => $path,
            'permissions' => $this->formatPermissions($stat['mode']),
            'permissions_octal' => substr(sprintf('%o', $stat['mode']), -4),
            'owner' => posix_getpwuid($stat['uid'])['name'] ?? $stat['uid'],
            'group' => posix_getgrgid($stat['gid'])['name'] ?? $stat['gid'],
        ], $mode !== null ? 'Permissions updated' : 'Success');
    }

    /**
     * Get detailed file/directory info
     */
    protected function actionInfo(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;

        if (!$path) {
            return $this->error('Path is required');
        }

        $realPath = $this->resolvePath($path);
        if (!$realPath || !$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        if (!file_exists($realPath)) {
            return $this->error("Path not found: {$path}");
        }

        $stat = stat($realPath);
        $isDir = is_dir($realPath);

        $info = [
            'path' => $path,
            'real_path' => $realPath,
            'name' => basename($path),
            'type' => $isDir ? 'directory' : 'file',
            'is_link' => is_link($realPath),
            'link_target' => is_link($realPath) ? readlink($realPath) : null,
            'size' => $stat['size'],
            'size_human' => $this->formatBytes($stat['size']),
            'permissions' => $this->formatPermissions($stat['mode']),
            'permissions_octal' => substr(sprintf('%o', $stat['mode']), -4),
            'owner' => posix_getpwuid($stat['uid'])['name'] ?? $stat['uid'],
            'owner_uid' => $stat['uid'],
            'group' => posix_getgrgid($stat['gid'])['name'] ?? $stat['gid'],
            'group_gid' => $stat['gid'],
            'created' => date('Y-m-d H:i:s', $stat['ctime']),
            'modified' => date('Y-m-d H:i:s', $stat['mtime']),
            'accessed' => date('Y-m-d H:i:s', $stat['atime']),
            'inode' => $stat['ino'],
            'is_readable' => is_readable($realPath),
            'is_writable' => is_writable($realPath),
            'is_executable' => is_executable($realPath),
        ];

        if (!$isDir) {
            $info['mime_type'] = $this->getMimeType($realPath);
            $info['extension'] = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        } else {
            // Get directory size (can be slow for large dirs)
            $result = $this->execCommand('du', ['-sb', $realPath]);
            if ($result['success'] && preg_match('/^(\d+)/', $result['output'], $m)) {
                $info['total_size'] = (int) $m[1];
                $info['total_size_human'] = $this->formatBytes((int) $m[1]);
            }
            
            // Count items
            $info['item_count'] = count(scandir($realPath)) - 2;
        }

        return $this->success($info);
    }

    /**
     * Search for files
     */
    protected function actionSearch(array $params, string $actor): array
    {
        $path = $params['path'] ?? '/home';
        $pattern = $params['pattern'] ?? '*';
        $type = $params['type'] ?? null; // file, directory, or null for both
        $maxDepth = $params['max_depth'] ?? 5;
        $limit = $params['limit'] ?? 100;

        $realPath = $this->resolvePath($path);
        if (!$realPath || !$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        if (!is_dir($realPath)) {
            return $this->error("Not a directory: {$path}");
        }

        $args = [$realPath, '-maxdepth', (string) $maxDepth];
        
        if ($type === 'file') {
            $args[] = '-type';
            $args[] = 'f';
        } elseif ($type === 'directory') {
            $args[] = '-type';
            $args[] = 'd';
        }

        $args[] = '-name';
        $args[] = $pattern;

        $result = $this->execCommand('find', $args);
        
        if (!$result['success']) {
            return $this->error('Search failed: ' . $result['output']);
        }

        $files = array_filter(explode("\n", trim($result['output'])));
        $files = array_slice($files, 0, $limit);

        $items = [];
        foreach ($files as $file) {
            if (!$this->isPathAllowed($file)) continue;
            
            $stat = @stat($file);
            if (!$stat) continue;

            $items[] = [
                'path' => $file,
                'name' => basename($file),
                'type' => is_dir($file) ? 'directory' : 'file',
                'size' => is_dir($file) ? null : $stat['size'],
                'size_human' => is_dir($file) ? '-' : $this->formatBytes($stat['size']),
                'modified' => date('Y-m-d H:i:s', $stat['mtime']),
            ];
        }

        return $this->success([
            'path' => $path,
            'pattern' => $pattern,
            'results' => $items,
            'count' => count($items),
            'truncated' => count($files) >= $limit,
        ]);
    }

    /**
     * Compress files/directories
     */
    protected function actionCompress(array $params, string $actor): array
    {
        $paths = $params['paths'] ?? [];
        $destination = $params['destination'] ?? null;
        $format = $params['format'] ?? 'zip'; // zip, tar.gz, tar

        if (empty($paths)) {
            return $this->error('Paths are required');
        }

        if (!$destination) {
            return $this->error('Destination is required');
        }

        $dstPath = $this->resolvePath($destination, true);
        if (!$dstPath || !$this->isPathAllowed($dstPath)) {
            return $this->error("Access denied to destination: {$destination}");
        }

        $validPaths = [];
        foreach ($paths as $path) {
            $realPath = $this->resolvePath($path);
            if ($realPath && $this->isPathAllowed($realPath) && file_exists($realPath)) {
                $validPaths[] = $realPath;
            }
        }

        if (empty($validPaths)) {
            return $this->error('No valid paths to compress');
        }

        switch ($format) {
            case 'zip':
                $result = $this->execCommand('zip', array_merge(['-r', $dstPath], $validPaths));
                break;
            case 'tar.gz':
                $result = $this->execCommand('tar', array_merge(['-czf', $dstPath], $validPaths));
                break;
            case 'tar':
                $result = $this->execCommand('tar', array_merge(['-cf', $dstPath], $validPaths));
                break;
            default:
                return $this->error("Unsupported format: {$format}");
        }

        if (!$result['success']) {
            return $this->error('Compression failed: ' . $result['output']);
        }

        return $this->success([
            'destination' => $destination,
            'format' => $format,
            'size' => filesize($dstPath),
            'size_human' => $this->formatBytes(filesize($dstPath)),
        ], 'Files compressed successfully');
    }

    /**
     * Extract archive
     */
    protected function actionExtract(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;
        $destination = $params['destination'] ?? null;

        if (!$path) {
            return $this->error('Path is required');
        }

        $realPath = $this->resolvePath($path);
        if (!$realPath || !$this->isPathAllowed($realPath)) {
            return $this->error("Access denied to path: {$path}");
        }

        if (!file_exists($realPath)) {
            return $this->error("File not found: {$path}");
        }

        // Default destination is same directory
        if (!$destination) {
            $destination = dirname($path);
        }

        $dstPath = $this->resolvePath($destination, true);
        if (!$dstPath || !$this->isPathAllowed($dstPath)) {
            return $this->error("Access denied to destination: {$destination}");
        }

        // Create destination if needed
        if (!is_dir($dstPath)) {
            mkdir($dstPath, 0755, true);
        }

        // Detect format from extension
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $name = basename($path);

        if ($ext === 'zip') {
            $result = $this->execCommand('unzip', ['-o', $realPath, '-d', $dstPath]);
        } elseif ($ext === 'gz' && str_ends_with($name, '.tar.gz')) {
            $result = $this->execCommand('tar', ['-xzf', $realPath, '-C', $dstPath]);
        } elseif ($ext === 'tar') {
            $result = $this->execCommand('tar', ['-xf', $realPath, '-C', $dstPath]);
        } elseif ($ext === 'tgz') {
            $result = $this->execCommand('tar', ['-xzf', $realPath, '-C', $dstPath]);
        } elseif ($ext === 'bz2' && str_ends_with($name, '.tar.bz2')) {
            $result = $this->execCommand('tar', ['-xjf', $realPath, '-C', $dstPath]);
        } else {
            return $this->error("Unsupported archive format: {$ext}");
        }

        if (!$result['success']) {
            return $this->error('Extraction failed: ' . $result['output']);
        }

        return $this->success([
            'path' => $path,
            'destination' => $destination,
        ], 'Archive extracted successfully');
    }

    // ============ Helper Methods ============

    /**
     * Resolve path (handle relative paths, ~, etc.)
     */
    private function resolvePath(string $path, bool $allowNew = false): ?string
    {
        // Handle home directory
        if (str_starts_with($path, '~')) {
            $path = '/root' . substr($path, 1);
        }

        // Block any path containing null bytes
        if (str_contains($path, "\0")) {
            return null;
        }

        // Get real path
        if ($allowNew) {
            // For new files, check parent directory exists and resolve it
            $parent = dirname($path);
            $realParent = realpath($parent);
            if ($realParent) {
                $name = basename($path);
                // Block filenames with path separators or traversal
                if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) {
                    return null;
                }
                return $realParent . '/' . $name;
            }
            // Parent doesn't exist – normalize manually and verify no traversal
            // Collapse ../ sequences to prevent path traversal
            $parts = explode('/', $path);
            $resolved = [];
            foreach ($parts as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                }
                if ($part === '..') {
                    array_pop($resolved);
                } else {
                    $resolved[] = $part;
                }
            }
            return '/' . implode('/', $resolved);
        }

        return realpath($path) ?: null;
    }

    /**
     * Check if path is allowed
     */
    private function isPathAllowed(string $path): bool
    {
        // Block dangerous paths
        foreach ($this->blockedPaths as $blocked) {
            if (str_starts_with($path, $blocked)) {
                return false;
            }
        }

        // Check allowed paths
        foreach ($this->allowedBasePaths as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format permissions as string (rwxrwxrwx)
     */
    private function formatPermissions(int $mode): string
    {
        $perms = '';
        
        // Owner
        $perms .= ($mode & 0x0100) ? 'r' : '-';
        $perms .= ($mode & 0x0080) ? 'w' : '-';
        $perms .= ($mode & 0x0040) ? (($mode & 0x0800) ? 's' : 'x') : (($mode & 0x0800) ? 'S' : '-');
        
        // Group
        $perms .= ($mode & 0x0020) ? 'r' : '-';
        $perms .= ($mode & 0x0010) ? 'w' : '-';
        $perms .= ($mode & 0x0008) ? (($mode & 0x0400) ? 's' : 'x') : (($mode & 0x0400) ? 'S' : '-');
        
        // Other
        $perms .= ($mode & 0x0004) ? 'r' : '-';
        $perms .= ($mode & 0x0002) ? 'w' : '-';
        $perms .= ($mode & 0x0001) ? (($mode & 0x0200) ? 't' : 'x') : (($mode & 0x0200) ? 'T' : '-');

        return $perms;
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get MIME type
     */
    private function getMimeType(string $path): string
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($path) ?: 'application/octet-stream';
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        
        return $mime ?: 'application/octet-stream';
    }

    /**
     * Check if content is binary
     */
    private function isBinaryContent(string $content): bool
    {
        // Check for null bytes
        if (strpos($content, "\0") !== false) {
            return true;
        }

        // Check ratio of non-printable characters
        $nonPrintable = 0;
        $total = min(strlen($content), 8192);
        
        for ($i = 0; $i < $total; $i++) {
            $ord = ord($content[$i]);
            if ($ord < 32 && !in_array($ord, [9, 10, 13])) {
                $nonPrintable++;
            }
        }

        return ($nonPrintable / $total) > 0.3;
    }

    /**
     * Delete directory recursively
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = "{$dir}/{$item}";
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectory(string $src, string $dst): bool
    {
        if (!is_dir($src)) {
            return false;
        }

        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $srcPath = "{$src}/{$item}";
            $dstPath = "{$dst}/{$item}";

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        return true;
    }

    /**
     * Chmod recursively
     */
    private function chmodRecursive(string $path, int $mode): void
    {
        chmod($path, $mode);
        
        if (is_dir($path)) {
            $items = scandir($path);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $this->chmodRecursive("{$path}/{$item}", $mode);
            }
        }
    }
}

