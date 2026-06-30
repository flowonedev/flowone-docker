<?php
/**
 * Backup Manager
 * 
 * Handles automatic backups before any configuration changes.
 * Creates timestamped backups and manages retention.
 */

namespace VpsAdmin\Agent\Lib;

class BackupManager
{
    private string $backupPath;
    private int $maxAgeDays;
    private int $maxCount;

    public function __construct(array $config)
    {
        $this->backupPath = $config['paths']['backups'];
        $this->maxAgeDays = $config['backup']['max_age_days'] ?? 30;
        $this->maxCount = $config['backup']['max_count'] ?? 100;
        
        $this->ensureDirectory();
    }

    /**
     * Create a backup of a file before modification
     */
    public function backup(string $filePath, string $action, string $actor): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $timestamp = date('Y-m-d_H-i-s');
        $basename = basename($filePath);
        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $basename);
        
        $backupDir = $this->backupPath . '/' . date('Y-m-d');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $backupFile = sprintf(
            '%s/%s_%s_%s.bak',
            $backupDir,
            $timestamp,
            $safeName,
            substr(md5($action . $actor), 0, 8)
        );

        if (copy($filePath, $backupFile)) {
            // Store metadata
            $meta = [
                'original_path' => $filePath,
                'action' => $action,
                'actor' => $actor,
                'timestamp' => $timestamp,
                'size' => filesize($filePath),
                'checksum' => md5_file($filePath),
            ];
            file_put_contents($backupFile . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT));
            
            return $backupFile;
        }

        return null;
    }

    /**
     * Restore a file from backup
     */
    public function restore(string $backupFile): bool
    {
        if (!file_exists($backupFile)) {
            return false;
        }

        $metaFile = $backupFile . '.meta.json';
        if (!file_exists($metaFile)) {
            return false;
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        if (!$meta || !isset($meta['original_path'])) {
            return false;
        }

        return copy($backupFile, $meta['original_path']);
    }

    /**
     * List all backups for a specific file
     */
    public function listBackups(string $originalPath = null): array
    {
        $backups = [];
        $dirs = glob($this->backupPath . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $files = glob($dir . '/*.bak');
            foreach ($files as $file) {
                $metaFile = $file . '.meta.json';
                if (file_exists($metaFile)) {
                    $meta = json_decode(file_get_contents($metaFile), true);
                    if ($originalPath === null || $meta['original_path'] === $originalPath) {
                        $backups[] = array_merge($meta, ['backup_path' => $file]);
                    }
                }
            }
        }

        // Sort by timestamp descending
        usort($backups, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

        return $backups;
    }

    /**
     * Clean old backups based on retention policy
     */
    public function cleanup(): array
    {
        $deleted = [];
        $cutoff = strtotime("-{$this->maxAgeDays} days");
        $allBackups = $this->listBackups();

        // Delete by age
        foreach ($allBackups as $backup) {
            $backupTime = strtotime(str_replace('_', ' ', $backup['timestamp']));
            if ($backupTime < $cutoff) {
                if (unlink($backup['backup_path'])) {
                    @unlink($backup['backup_path'] . '.meta.json');
                    $deleted[] = $backup['backup_path'];
                }
            }
        }

        // Delete by count (keep only maxCount newest)
        $remaining = array_diff(
            array_column($this->listBackups(), 'backup_path'),
            $deleted
        );

        if (count($remaining) > $this->maxCount) {
            $toDelete = array_slice($remaining, $this->maxCount);
            foreach ($toDelete as $file) {
                if (unlink($file)) {
                    @unlink($file . '.meta.json');
                    $deleted[] = $file;
                }
            }
        }

        return $deleted;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0750, true);
        }
    }
}

