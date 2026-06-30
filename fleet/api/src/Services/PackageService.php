<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Package Service - Manages deployment package files
 * 
 * Handles storage, versioning, and retrieval of panel, email, and agent packages.
 * Supports building packages directly from local installations.
 */
class PackageService
{
    private Container $container;
    private string $basePath;
    private int $maxSize;
    
    // Valid package types
    public const TYPES = ['panel', 'email', 'agent'];
    
    // Source paths for local builds (production paths on the fleet manager server)
    public const SOURCE_PATHS = [
        'panel' => '/var/www/vps-admin',
        'email' => '/var/www/vps-email',
        'agent' => '/var/www/vps-fleet/agent',
    ];
    
    // Install script paths (relative to Fleet Manager root)
    public const INSTALL_SCRIPT_PATHS = [
        'panel' => '/var/www/vps-fleet/packages/panel/install.sh',
        'email' => '/var/www/vps-fleet/packages/email/install.sh',
        'agent' => '/var/www/vps-fleet/packages/agent/install.sh',
    ];
    
    // Type labels for display
    public const TYPE_LABELS = [
        'panel' => 'VPS Admin Panel',
        'email' => 'Email App',
        'agent' => 'Fleet Agent',
    ];
    
    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->basePath = rtrim($container->getConfig('packages.path'), '/');
        $this->maxSize = $container->getConfig('packages.max_size') ?? 104857600; // 100MB default
        
        // Ensure directories exist
        $this->ensureDirectories();
    }
    
    /**
     * Ensure package directories exist
     */
    private function ensureDirectories(): void
    {
        foreach (self::TYPES as $type) {
            $dir = "{$this->basePath}/{$type}";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * List all packages grouped by type
     */
    public function listAll(): array
    {
        $result = [];
        
        foreach (self::TYPES as $type) {
            $result[$type] = $this->listVersions($type);
        }
        
        return $result;
    }
    
    /**
     * List all versions of a package type
     */
    public function listVersions(string $type): array
    {
        if (!in_array($type, self::TYPES)) {
            throw new \InvalidArgumentException("Invalid package type: {$type}");
        }
        
        $dir = "{$this->basePath}/{$type}";
        $versions = [];
        $latestVersion = $this->getLatestVersion($type);
        
        if (!is_dir($dir)) {
            return $versions;
        }
        
        $files = glob("{$dir}/{$type}-v*.tar.gz");
        
        foreach ($files as $file) {
            $filename = basename($file);
            $version = $this->extractVersion($filename, $type);
            
            if ($version) {
                $versions[] = [
                    'version' => $version,
                    'filename' => $filename,
                    'size' => filesize($file),
                    'size_human' => $this->formatBytes(filesize($file)),
                    'checksum' => $this->getChecksum($file),
                    'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                    'is_latest' => $version === $latestVersion,
                ];
            }
        }
        
        // Sort by version descending
        usort($versions, function($a, $b) {
            return version_compare($b['version'], $a['version']);
        });
        
        return $versions;
    }
    
    /**
     * Get the current "latest" version for a type
     */
    public function getLatestVersion(string $type): ?string
    {
        $latestLink = "{$this->basePath}/{$type}/{$type}-latest.tar.gz";
        
        if (!is_link($latestLink) && !file_exists($latestLink)) {
            return null;
        }
        
        // If it's a symlink, resolve it
        if (is_link($latestLink)) {
            $target = readlink($latestLink);
            return $this->extractVersion(basename($target), $type);
        }
        
        // If it's a real file, we can't determine version
        return null;
    }
    
    /**
     * Get package info
     */
    public function getPackage(string $type, string $version): ?array
    {
        if (!in_array($type, self::TYPES)) {
            return null;
        }
        
        $filename = "{$type}-v{$version}.tar.gz";
        $path = "{$this->basePath}/{$type}/{$filename}";
        
        if (!file_exists($path)) {
            return null;
        }
        
        return [
            'type' => $type,
            'version' => $version,
            'filename' => $filename,
            'path' => $path,
            'size' => filesize($path),
            'size_human' => $this->formatBytes(filesize($path)),
            'checksum' => $this->getChecksum($path),
            'created_at' => date('Y-m-d H:i:s', filemtime($path)),
            'is_latest' => $version === $this->getLatestVersion($type),
        ];
    }
    
    /**
     * Upload a new package
     */
    public function upload(string $type, array $file, ?string $version = null): array
    {
        if (!in_array($type, self::TYPES)) {
            throw new \InvalidArgumentException("Invalid package type: {$type}");
        }
        
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('No valid file uploaded');
        }
        
        // Check size
        $size = filesize($file['tmp_name']);
        if ($size > $this->maxSize) {
            throw new \RuntimeException(
                "File too large. Maximum size is " . $this->formatBytes($this->maxSize)
            );
        }
        
        // Validate it's a tar.gz
        $originalName = $file['name'] ?? '';
        if (!preg_match('/\.tar\.gz$/', $originalName)) {
            throw new \RuntimeException('File must be a .tar.gz archive');
        }
        
        // Determine version
        if (!$version) {
            // Try to extract from filename
            $version = $this->extractVersion($originalName, $type);
        }
        
        if (!$version) {
            // Try to read VERSION file from archive
            $version = $this->extractVersionFromArchive($file['tmp_name']);
        }
        
        if (!$version) {
            throw new \RuntimeException(
                'Could not determine version. Please specify version or use filename format: ' .
                "{$type}-v1.0.0.tar.gz"
            );
        }
        
        // Validate version format
        if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+)?$/', $version)) {
            throw new \RuntimeException('Invalid version format. Use semantic versioning: X.Y.Z');
        }
        
        // Check if version already exists
        $filename = "{$type}-v{$version}.tar.gz";
        $destPath = "{$this->basePath}/{$type}/{$filename}";
        
        if (file_exists($destPath)) {
            throw new \RuntimeException("Version {$version} already exists for {$type}");
        }
        
        // Move file
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \RuntimeException('Failed to save uploaded file');
        }
        
        chmod($destPath, 0644);
        
        // Calculate checksum
        $checksum = $this->getChecksum($destPath);
        
        // Auto-set as latest if it's the only version or higher than current
        $currentLatest = $this->getLatestVersion($type);
        if (!$currentLatest || version_compare($version, $currentLatest, '>')) {
            $this->setLatest($type, $version);
        }
        
        return [
            'type' => $type,
            'version' => $version,
            'filename' => $filename,
            'size' => $size,
            'size_human' => $this->formatBytes($size),
            'checksum' => $checksum,
            'is_latest' => $version === $this->getLatestVersion($type),
        ];
    }
    
    /**
     * Set a version as the latest
     */
    public function setLatest(string $type, string $version): bool
    {
        if (!in_array($type, self::TYPES)) {
            throw new \InvalidArgumentException("Invalid package type: {$type}");
        }
        
        $filename = "{$type}-v{$version}.tar.gz";
        $sourcePath = "{$this->basePath}/{$type}/{$filename}";
        $linkPath = "{$this->basePath}/{$type}/{$type}-latest.tar.gz";
        
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Version {$version} does not exist for {$type}");
        }
        
        // Remove existing symlink/file
        if (file_exists($linkPath) || is_link($linkPath)) {
            unlink($linkPath);
        }
        
        // Create symlink
        // Use relative path for symlink
        return symlink($filename, $linkPath);
    }
    
    /**
     * Delete a package version
     */
    public function delete(string $type, string $version): bool
    {
        if (!in_array($type, self::TYPES)) {
            throw new \InvalidArgumentException("Invalid package type: {$type}");
        }
        
        $filename = "{$type}-v{$version}.tar.gz";
        $path = "{$this->basePath}/{$type}/{$filename}";
        
        if (!file_exists($path)) {
            throw new \RuntimeException("Version {$version} does not exist for {$type}");
        }
        
        // Check if this is the latest
        $latestVersion = $this->getLatestVersion($type);
        $wasLatest = $version === $latestVersion;
        
        // Delete the file
        if (!unlink($path)) {
            throw new \RuntimeException('Failed to delete package file');
        }
        
        // If this was the latest, update symlink to point to next highest version
        if ($wasLatest) {
            $linkPath = "{$this->basePath}/{$type}/{$type}-latest.tar.gz";
            if (is_link($linkPath)) {
                unlink($linkPath);
            }
            
            // Find next highest version
            $versions = $this->listVersions($type);
            if (!empty($versions)) {
                $this->setLatest($type, $versions[0]['version']);
            }
        }
        
        return true;
    }
    
    /**
     * Get package file path for download
     */
    public function getFilePath(string $type, string $version): ?string
    {
        if (!in_array($type, self::TYPES)) {
            return null;
        }
        
        $filename = "{$type}-v{$version}.tar.gz";
        $path = "{$this->basePath}/{$type}/{$filename}";
        
        return file_exists($path) ? $path : null;
    }
    
    /**
     * Get latest package path
     */
    public function getLatestPath(string $type): ?string
    {
        if (!in_array($type, self::TYPES)) {
            return null;
        }
        
        $path = "{$this->basePath}/{$type}/{$type}-latest.tar.gz";
        
        return (file_exists($path) || is_link($path)) ? $path : null;
    }
    
    /**
     * Extract version from filename
     */
    private function extractVersion(string $filename, string $type): ?string
    {
        // Match pattern: type-vX.Y.Z.tar.gz or type-vX.Y.Z-suffix.tar.gz
        if (preg_match("/{$type}-v(\\d+\\.\\d+\\.\\d+(?:-[a-zA-Z0-9]+)?)\\.tar\\.gz$/", $filename, $matches)) {
            return $matches[1];
        }
        
        // Also try without the type prefix: vX.Y.Z.tar.gz
        if (preg_match('/v(\\d+\\.\\d+\\.\\d+(?:-[a-zA-Z0-9]+)?)\\.tar\\.gz$/', $filename, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Try to extract version from VERSION file in archive
     */
    private function extractVersionFromArchive(string $path): ?string
    {
        try {
            // Read first 50KB to look for VERSION file
            $phar = new \PharData($path);
            
            foreach ($phar as $file) {
                if (basename($file) === 'VERSION') {
                    $content = file_get_contents($file->getPathname());
                    $version = trim($content);
                    if (preg_match('/^\\d+\\.\\d+\\.\\d+/', $version)) {
                        return $version;
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        
        return null;
    }
    
    /**
     * Get file checksum (SHA256)
     */
    private function getChecksum(string $path): string
    {
        return hash_file('sha256', $path);
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
    
    /**
     * Get summary statistics
     */
    public function getStats(): array
    {
        $stats = [
            'total_packages' => 0,
            'total_size' => 0,
            'by_type' => [],
        ];
        
        foreach (self::TYPES as $type) {
            $versions = $this->listVersions($type);
            $typeSize = array_sum(array_column($versions, 'size'));
            
            $stats['by_type'][$type] = [
                'count' => count($versions),
                'size' => $typeSize,
                'size_human' => $this->formatBytes($typeSize),
                'latest' => $this->getLatestVersion($type),
            ];
            
            $stats['total_packages'] += count($versions);
            $stats['total_size'] += $typeSize;
        }
        
        $stats['total_size_human'] = $this->formatBytes($stats['total_size']);
        
        return $stats;
    }
    
    // =========================================================================
    // BUILD FUNCTIONALITY - Create packages from local installations
    // =========================================================================
    
    /**
     * Get source paths info (for checking availability)
     */
    public function getSourceInfo(): array
    {
        $info = [];
        
        foreach (self::TYPES as $type) {
            $sourcePath = self::SOURCE_PATHS[$type];
            $exists = is_dir($sourcePath);
            
            $info[$type] = [
                'path' => $sourcePath,
                'exists' => $exists,
                'label' => self::TYPE_LABELS[$type],
            ];
            
            if ($exists) {
                // Get basic stats
                $info[$type]['size'] = $this->getDirectorySize($sourcePath);
                $info[$type]['size_human'] = $this->formatBytes($info[$type]['size']);
                $info[$type]['file_count'] = $this->countFilesInDirectory($sourcePath);
            }
        }
        
        return $info;
    }
    
    /**
     * Build a package from local installation
     */
    public function build(string $type): array
    {
        if (!in_array($type, self::TYPES)) {
            throw new \InvalidArgumentException("Invalid package type: {$type}");
        }
        
        $sourcePath = self::SOURCE_PATHS[$type];
        
        if (!is_dir($sourcePath)) {
            throw new \RuntimeException("Source path not found: {$sourcePath}. Make sure the application is installed on this server.");
        }
        
        // Determine next version
        $currentLatest = $this->getLatestVersion($type);
        $nextVersion = $this->incrementVersion($currentLatest ?? '0.0.0');
        
        $filename = "{$type}-v{$nextVersion}.tar.gz";
        $destDir = "{$this->basePath}/{$type}";
        $destPath = "{$destDir}/{$filename}";
        $buildDir = sys_get_temp_dir() . "/fleet-build-{$type}-" . uniqid();
        
        // Ensure destination directory exists
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        
        try {
            // Create build directory
            mkdir($buildDir, 0755, true);
            mkdir("{$buildDir}/{$type}", 0755, true);
            
            // Build based on type
            $contents = match ($type) {
                'panel' => $this->buildPanel($sourcePath, "{$buildDir}/{$type}"),
                'email' => $this->buildEmail($sourcePath, "{$buildDir}/{$type}"),
                'agent' => $this->buildAgent($sourcePath, "{$buildDir}/{$type}"),
            };
            
            // Write metadata files
            file_put_contents("{$buildDir}/{$type}/VERSION", $nextVersion);
            file_put_contents("{$buildDir}/{$type}/BUILD_DATE", date('c'));
            file_put_contents("{$buildDir}/{$type}/BUILD_INFO.json", json_encode([
                'version' => $nextVersion,
                'type' => $type,
                'built_at' => date('c'),
                'source_path' => $sourcePath,
                'directories' => $contents['directories'],
                'file_count' => $contents['files'],
                'source_size' => $contents['total_size'],
            ], JSON_PRETTY_PRINT));
            
            // Create tarball using shell command (more reliable than PharData on some systems)
            $this->createTarball($buildDir, $destPath, $type);
            
            // Set as latest (auto-set since it's the newest)
            $this->setLatest($type, $nextVersion);
            
            // Calculate final stats
            $size = filesize($destPath);
            $checksum = $this->getChecksum($destPath);
            
            return [
                'success' => true,
                'type' => $type,
                'type_label' => self::TYPE_LABELS[$type],
                'version' => $nextVersion,
                'filename' => $filename,
                'size' => $size,
                'size_human' => $this->formatBytes($size),
                'checksum' => $checksum,
                'contents' => $contents,
                'source_path' => $sourcePath,
                'built_at' => date('Y-m-d H:i:s'),
                'is_latest' => true,
            ];
            
        } finally {
            // Cleanup build directory
            $this->removeDirectory($buildDir);
        }
    }
    
    /**
     * Build Panel package - copies EVERYTHING from source
     */
    private function buildPanel(string $source, string $dest): array
    {
        $contents = ['directories' => [], 'files' => 0, 'total_size' => 0];
        
        // Copy ENTIRE source directory - everything that exists!
        $this->copyDirectoryFull("{$source}", "{$dest}");
        
        // Copy install.sh from packages directory
        $installScript = self::INSTALL_SCRIPT_PATHS['panel'];
        if (file_exists($installScript)) {
            copy($installScript, "{$dest}/install.sh");
            chmod("{$dest}/install.sh", 0755);
        } else {
            throw new \RuntimeException("Install script not found at: {$installScript}");
        }
        
        // Ensure complete schema is included from packages folder (priority over source)
        $packagesSchema = '/var/www/vps-fleet/packages/panel/database/schema.sql';
        if (file_exists($packagesSchema)) {
            if (!is_dir("{$dest}/database")) {
                mkdir("{$dest}/database", 0755, true);
            }
            copy($packagesSchema, "{$dest}/database/schema.sql");
        }
        
        // List what was copied for the build info
        $items = scandir($dest);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir("{$dest}/{$item}")) {
                $contents['directories'][] = "{$item}/";
            }
        }
        
        // Create empty directories that should exist on target
        $emptyDirs = ['storage', 'logs', 'backups', 'var'];
        foreach ($emptyDirs as $dir) {
            if (!is_dir("{$dest}/{$dir}")) {
                mkdir("{$dest}/{$dir}", 0755, true);
            }
        }
        
        // Count files and size
        $contents['files'] = $this->countFilesInDirectory($dest);
        $contents['total_size'] = $this->getDirectorySize($dest);
        $contents['total_size_human'] = $this->formatBytes($contents['total_size']);
        
        return $contents;
    }
    
    /**
     * Build Email App package - copies EVERYTHING from source
     */
    private function buildEmail(string $source, string $dest): array
    {
        $contents = ['directories' => [], 'files' => 0, 'total_size' => 0];
        
        // Copy ENTIRE source directory - everything that exists!
        $this->copyDirectoryFull("{$source}", "{$dest}");
        
        // Copy install.sh from packages directory
        $installScript = self::INSTALL_SCRIPT_PATHS['email'];
        if (file_exists($installScript)) {
            copy($installScript, "{$dest}/install.sh");
            chmod("{$dest}/install.sh", 0755);
        } else {
            throw new \RuntimeException("Install script not found at: {$installScript}");
        }
        
        // List what was copied for the build info
        $items = scandir($dest);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir("{$dest}/{$item}")) {
                $contents['directories'][] = "{$item}/";
            }
        }
        
        // Create storage directories if they don't exist
        $storageDirs = ['backend/storage', 'backend/storage/cache', 'backend/storage/drive', 'backend/storage/config'];
        foreach ($storageDirs as $dir) {
            if (!is_dir("{$dest}/{$dir}")) {
                mkdir("{$dest}/{$dir}", 0755, true);
            }
        }
        
        // Count files and size
        $contents['files'] = $this->countFilesInDirectory($dest);
        $contents['total_size'] = $this->getDirectorySize($dest);
        $contents['total_size_human'] = $this->formatBytes($contents['total_size']);
        
        return $contents;
    }
    
    /**
     * Build Agent package - copies EVERYTHING from source
     */
    private function buildAgent(string $source, string $dest): array
    {
        $contents = ['directories' => [], 'files' => 0, 'total_size' => 0];
        
        // Copy ENTIRE source directory - everything that exists!
        $this->copyDirectoryFull("{$source}", "{$dest}");
        
        // Copy install.sh from packages directory
        $installScript = self::INSTALL_SCRIPT_PATHS['agent'];
        if (file_exists($installScript)) {
            copy($installScript, "{$dest}/install.sh");
            chmod("{$dest}/install.sh", 0755);
        } else {
            throw new \RuntimeException("Install script not found at: {$installScript}");
        }
        
        // List what was copied for the build info
        $items = scandir($dest);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir("{$dest}/{$item}")) {
                $contents['directories'][] = "{$item}/";
            } else if (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
                $contents['directories'][] = '*.php (root files)';
            }
        }
        $contents['directories'] = array_unique($contents['directories']);
        
        // Ensure agent.php is executable
        if (file_exists("{$dest}/agent.php")) {
            chmod("{$dest}/agent.php", 0755);
        }
        
        // Count files and size
        $contents['files'] = $this->countFilesInDirectory($dest);
        $contents['total_size'] = $this->getDirectorySize($dest);
        $contents['total_size_human'] = $this->formatBytes($contents['total_size']);
        
        return $contents;
    }
    
    /**
     * Create tarball using tar command
     */
    private function createTarball(string $sourceDir, string $destPath, string $rootName): void
    {
        $originalDir = getcwd();
        chdir($sourceDir);
        
        $escapedDest = escapeshellarg($destPath);
        $escapedRoot = escapeshellarg($rootName);
        
        // Create gzipped tarball
        $cmd = "tar -czf {$escapedDest} {$escapedRoot}";
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        
        chdir($originalDir);
        
        if ($returnCode !== 0 || !file_exists($destPath)) {
            throw new \RuntimeException("Failed to create tarball: " . implode("\n", $output));
        }
    }
    
    /**
     * Increment semantic version
     */
    private function incrementVersion(string $version): string
    {
        $parts = explode('.', $version);
        
        // Ensure we have 3 parts
        while (count($parts) < 3) {
            $parts[] = '0';
        }
        
        // Increment patch version
        $parts[2] = (int)$parts[2] + 1;
        
        return implode('.', $parts);
    }
    
    /**
     * Copy directory FULLY - EVERYTHING except secrets and .git
     * This is the main method for package building - copies exactly what's on production
     */
    private function copyDirectoryFull(string $src, string $dst): void
    {
        if (!is_dir($src)) {
            return;
        }
        
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        
        // Exclusions - things that should NEVER be in a deployment package
        $excludeDirs = [
            '.git',           // Git history - not needed
            '.idea',          // IDE settings
            '.vscode',        // IDE settings
            'backups',        // Backup data - can be huge!
            'logs',           // Log files - not needed
            '.well-known',    // SSL verification files
            'phpmyadmin',     // phpMyAdmin - separate install
        ];
        
        // Files with secrets or server-specific config
        $excludeFiles = [
            '.env',
            '.env.local', 
            '.env.production',
            'config.local.php',  // Server-specific config
            '.DS_Store',         // macOS junk
            'Thumbs.db',         // Windows junk
        ];
        
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $srcPath = "{$src}/{$file}";
            $dstPath = "{$dst}/{$file}";
            
            // Skip excluded directories
            if (is_dir($srcPath) && in_array($file, $excludeDirs)) {
                continue;
            }
            
            // Skip excluded files
            if (is_file($srcPath) && in_array($file, $excludeFiles)) {
                continue;
            }
            
            // Skip symlinks that point nowhere
            if (is_link($srcPath) && !file_exists($srcPath)) {
                continue;
            }
            
            if (is_dir($srcPath)) {
                $this->copyDirectoryFull($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
                // Preserve executable permissions
                if (is_executable($srcPath)) {
                    chmod($dstPath, fileperms($srcPath));
                }
            }
        }
        closedir($dir);
    }
    
    /**
     * Copy directory recursively (legacy method for selective copying)
     */
    private function copyDirectory(string $src, string $dst, bool $includeVendor = false): void
    {
        // Just use the full copy method now
        $this->copyDirectoryFull($src, $dst);
    }
    
    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
    
    /**
     * Directories to exclude from size/count scanning (matches copyDirectoryFull exclusions)
     */
    private const SCAN_EXCLUDE_DIRS = [
        '.git',
        '.idea',
        '.vscode',
        'backups',
        'logs',
        '.well-known',
        'phpmyadmin',
        'deleted_sites',
    ];

    /**
     * Count files in directory recursively
     */
    private function countFilesInDirectory(string $dir): int
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return 0;
        }
        
        $count = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                    function ($file, $key, $iterator) {
                        if ($iterator->hasChildren()) {
                            $name = $file->getFilename();
                            if (in_array($name, self::SCAN_EXCLUDE_DIRS) || !$file->isReadable()) {
                                return false;
                            }
                        }
                        return true;
                    }
                ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $iterator->setMaxDepth(20);
            
            foreach ($iterator as $file) {
                try {
                    if ($file->isFile()) {
                        $count++;
                    }
                } catch (\UnexpectedValueException $e) {
                    continue;
                }
            }
        } catch (\UnexpectedValueException $e) {
            // Permission denied or other filesystem error - return what we have
        }
        
        return $count;
    }
    
    /**
     * Get directory size in bytes
     */
    private function getDirectorySize(string $dir): int
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return 0;
        }
        
        $size = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                    function ($file, $key, $iterator) {
                        if ($iterator->hasChildren()) {
                            $name = $file->getFilename();
                            if (in_array($name, self::SCAN_EXCLUDE_DIRS) || !$file->isReadable()) {
                                return false;
                            }
                        }
                        return true;
                    }
                ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $iterator->setMaxDepth(20);
            
            foreach ($iterator as $file) {
                try {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                    }
                } catch (\UnexpectedValueException $e) {
                    continue;
                }
            }
        } catch (\UnexpectedValueException $e) {
            // Permission denied or other filesystem error - return what we have
        }
        
        return $size;
    }
}


