<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;

/**
 * SystemController - System health and maintenance operations
 */
class SystemController extends BaseController
{
    private string $dataDir = '/var/www/vps-email/data';
    private string $expectedOwner = 'nobody';
    private string $expectedGroup = 'nogroup';
    private int $expectedPerms = 0755;
    
    /**
     * Check data folder permissions and ownership
     */
    public function checkPermissions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $results = [
            'path' => $this->dataDir,
            'exists' => false,
            'owner' => null,
            'group' => null,
            'permissions' => null,
            'owner_correct' => false,
            'group_correct' => false,
            'permissions_correct' => false,
            'all_correct' => false,
            'expected' => [
                'owner' => $this->expectedOwner,
                'group' => $this->expectedGroup,
                'permissions' => sprintf('%o', $this->expectedPerms),
            ],
        ];
        
        try {
            if (!file_exists($this->dataDir)) {
                $results['error'] = 'Data directory does not exist';
                return Response::success(['permissions' => $results]);
            }
            
            $results['exists'] = true;
            
            // Get current ownership
            $stat = @stat($this->dataDir);
            if ($stat) {
                // Check if posix extension is available
                if (function_exists('posix_getpwuid')) {
                    $ownerInfo = @posix_getpwuid($stat['uid']);
                    $groupInfo = @posix_getgrgid($stat['gid']);
                    
                    $results['owner'] = $ownerInfo['name'] ?? (string)$stat['uid'];
                    $results['group'] = $groupInfo['name'] ?? (string)$stat['gid'];
                } else {
                    // Fallback: use numeric IDs or try shell command
                    $owner = $this->getOwnerViaShell($this->dataDir);
                    $results['owner'] = $owner['user'] ?? (string)$stat['uid'];
                    $results['group'] = $owner['group'] ?? (string)$stat['gid'];
                }
                
                $results['permissions'] = sprintf('%o', $stat['mode'] & 0777);
                
                $results['owner_correct'] = ($results['owner'] === $this->expectedOwner);
                $results['group_correct'] = ($results['group'] === $this->expectedGroup);
                $results['permissions_correct'] = (($stat['mode'] & 0777) === $this->expectedPerms);
                
                $results['all_correct'] = $results['owner_correct'] && 
                                          $results['group_correct'] && 
                                          $results['permissions_correct'];
            }
            
            // Also check subdirectories
            $subdirs = ['settings', 'sync-queue', 'labels', 'filters', 'todos', 'inline-images'];
            $results['subdirectories'] = [];
            
            foreach ($subdirs as $subdir) {
                $path = $this->dataDir . '/' . $subdir;
                $subdirResult = [
                    'path' => $subdir,
                    'exists' => file_exists($path),
                    'correct' => false,
                ];
                
                if ($subdirResult['exists']) {
                    $subStat = @stat($path);
                    if ($subStat) {
                        if (function_exists('posix_getpwuid')) {
                            $subOwner = @posix_getpwuid($subStat['uid']);
                            $subdirResult['owner'] = $subOwner['name'] ?? (string)$subStat['uid'];
                        } else {
                            $owner = $this->getOwnerViaShell($path);
                            $subdirResult['owner'] = $owner['user'] ?? (string)$subStat['uid'];
                        }
                        $subdirResult['permissions'] = sprintf('%o', $subStat['mode'] & 0777);
                        $subdirResult['correct'] = (
                            $subdirResult['owner'] === $this->expectedOwner &&
                            ($subStat['mode'] & 0777) >= 0755
                        );
                    }
                }
                
                $results['subdirectories'][] = $subdirResult;
            }
            
            // Also check if directory is writable by web server
            $results['writable'] = is_writable($this->dataDir);
            
        } catch (\Exception $e) {
            $results['error'] = 'Error checking permissions: ' . $e->getMessage();
            error_log("SystemController checkPermissions error: " . $e->getMessage());
        }
        
        return Response::success(['permissions' => $results]);
    }
    
    /**
     * Get owner/group via shell command (fallback when posix not available)
     */
    private function getOwnerViaShell(string $path): array
    {
        $result = ['user' => null, 'group' => null];
        
        // Try using ls -ld
        $output = @shell_exec("ls -ld " . escapeshellarg($path) . " 2>/dev/null");
        if ($output) {
            $parts = preg_split('/\s+/', trim($output));
            if (count($parts) >= 4) {
                $result['user'] = $parts[2];
                $result['group'] = $parts[3];
            }
        }
        
        return $result;
    }
    
    /**
     * Fix data folder permissions (creates missing dirs, sets ownership/permissions)
     */
    public function fixPermissions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $results = [
            'success' => false,
            'actions' => [],
            'errors' => [],
        ];
        
        try {
            // Create main data directory if not exists
            if (!file_exists($this->dataDir)) {
                if (@mkdir($this->dataDir, 0755, true)) {
                    $results['actions'][] = "Created directory: {$this->dataDir}";
                } else {
                    $results['errors'][] = "Failed to create directory: {$this->dataDir}";
                    return Response::error('Failed to create data directory. Run manually: sudo mkdir -p ' . $this->dataDir, 500);
                }
            }
            
            // Create subdirectories
            $subdirs = ['settings', 'sync-queue', 'labels', 'filters', 'todos', 'inline-images', 'drive', 'calendar'];
            foreach ($subdirs as $subdir) {
                $path = $this->dataDir . '/' . $subdir;
                if (!file_exists($path)) {
                    if (@mkdir($path, 0755, true)) {
                        $results['actions'][] = "Created directory: $path";
                    } else {
                        $results['errors'][] = "Failed to create: $path";
                    }
                }
            }
            
            // Try to fix permissions using PHP (may fail without sudo)
            // This will work if the web server already owns the directory
            $fixedPerms = @chmod($this->dataDir, 0755);
            if ($fixedPerms) {
                $results['actions'][] = "Set permissions 755 on {$this->dataDir}";
            }
            
            // Fix subdirectory permissions
            foreach ($subdirs as $subdir) {
                $path = $this->dataDir . '/' . $subdir;
                if (file_exists($path)) {
                    if (@chmod($path, 0755)) {
                        $results['actions'][] = "Set permissions 755 on $path";
                    }
                }
            }
            
            // Check if we need shell commands for ownership
            $stat = @stat($this->dataDir);
            $currentOwner = '';
            
            if ($stat) {
                if (function_exists('posix_getpwuid')) {
                    $ownerInfo = @posix_getpwuid($stat['uid']);
                    $currentOwner = $ownerInfo['name'] ?? '';
                } else {
                    $owner = $this->getOwnerViaShell($this->dataDir);
                    $currentOwner = $owner['user'] ?? '';
                }
            }
            
            if ($currentOwner !== $this->expectedOwner) {
                // Can't change ownership without sudo - provide the command
                $results['manual_commands'] = [
                    "sudo chown -R {$this->expectedOwner}:{$this->expectedGroup} {$this->dataDir}",
                    "sudo chmod -R 755 {$this->dataDir}",
                ];
                $results['needs_manual'] = true;
                $results['actions'][] = "Ownership change requires sudo - see manual_commands";
            } else {
                $results['needs_manual'] = false;
            }
            
            $results['success'] = empty($results['errors']);
            
        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            error_log("SystemController fixPermissions error: " . $e->getMessage());
        }
        
        return Response::success($results);
    }
}
