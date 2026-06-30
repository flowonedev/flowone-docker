<?php

namespace Webmail\Services;

/**
 * StorageService - Abstracts file storage operations
 * 
 * Storage configuration comes from Panel API:
 * - Panel defines storage connections (local, NFS mounts)
 * - Panel sets default storage for the server
 * - Panel can override storage per domain
 * 
 * Falls back to config.php if Panel is unavailable
 */
class StorageService
{
    private string $driver;
    private string $basePath;
    private array $config;
    private ?array $appConfig;
    private ?string $storageName = null;
    
    // Cache keys for Panel storage config
    private const PANEL_CACHE_KEY = 'panel_storage_config';
    private const PANEL_CACHE_STALE_KEY = 'panel_storage_config_stale';
    
    public function __construct(?array $appConfig = null, ?string $userEmail = null)
    {
        $this->appConfig = $appConfig;
        
        // Extract domain from user email
        $userDomain = null;
        if ($userEmail) {
            $parts = explode('@', $userEmail);
            $userDomain = $parts[1] ?? null;
        }
        
        // Try to get storage config from Panel
        $panelStorage = $this->fetchStorageFromPanel($userDomain);
        
        if ($panelStorage) {
            // Use Panel-provided storage configuration
            $this->driver = $panelStorage['type'] ?? 'nfs';
            $this->basePath = $panelStorage['mount_point'];
            $this->storageName = $panelStorage['name'] ?? null;
            $this->config = [
                'driver' => $this->driver,
                'source' => 'panel',
                'storage_id' => $panelStorage['id'] ?? null,
                'storage_name' => $this->storageName,
                'path' => $this->basePath,
                'status' => $panelStorage['status'] ?? 'unknown',
            ];
        } else {
            // Fallback to local config
            $this->loadFallbackConfig($appConfig);
        }
    }
    
    /**
     * Fetch storage configuration from Panel API
     */
    private function fetchStorageFromPanel(?string $domain): ?array
    {
        if (!$this->appConfig || empty($this->appConfig['panel']['api_url'])) {
            return null; // Panel not configured
        }
        
        $panelUrl = $this->appConfig['panel']['api_url'];
        $apiKey = $this->appConfig['panel']['api_key'] ?? '';
        
        if (empty($apiKey)) {
            error_log('[StorageService] Panel API key not configured, using fallback storage');
            return null;
        }
        
        // Try to get from cache first
        $cachedConfig = $this->getCachedPanelConfig();
        
        if ($cachedConfig === null) {
            // Fetch from Panel API
            $cachedConfig = $this->callPanelApi($panelUrl, $apiKey);
            
            if ($cachedConfig) {
                // Cache the result
                $this->cachePanelConfig($cachedConfig);
            } else {
                // Try stale cache as last resort before falling back to local
                $cachedConfig = $this->getStalePanelConfig();
                if ($cachedConfig) {
                    error_log("[StorageService] Using stale cache - Panel API unavailable");
                }
            }
        }
        
        if (!$cachedConfig) {
            error_log("[StorageService] No Panel config available (fresh, API, or stale) - using fallback");
            return null;
        }
        
        // Resolve which storage to use for this domain
        return $this->resolveStorageForDomain($cachedConfig, $domain);
    }
    
    /**
     * Call Panel API to get storage configuration
     */
    private function callPanelApi(string $panelUrl, string $apiKey): ?array
    {
        $url = rtrim($panelUrl, '/') . '/storage/config';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $apiKey,
                'Accept: application/json',
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("[StorageService] Panel API curl error: $error");
            return null;
        }
        
        if ($httpCode !== 200) {
            error_log("[StorageService] Panel API returned HTTP $httpCode");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['success']) || !$data['success']) {
            error_log("[StorageService] Panel API returned invalid response");
            return null;
        }
        
        return $data['data'] ?? null;
    }
    
    /**
     * Get cached Panel config from Redis
     */
    private function getCachedPanelConfig(): ?array
    {
        try {
            $cache = new RedisCacheService($this->appConfig);
            $cached = $cache->get(self::PANEL_CACHE_KEY);
            return $cached ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Cache Panel config in Redis
     */
    private function cachePanelConfig(array $config): void
    {
        try {
            $ttl = $this->appConfig['panel']['storage_cache_ttl'] ?? 300;
            $cache = new RedisCacheService($this->appConfig);
            $cache->set(self::PANEL_CACHE_KEY, $config, $ttl);
            // Also save stale backup with 1 hour TTL as fallback
            $cache->set(self::PANEL_CACHE_STALE_KEY, $config, 3600);
        } catch (\Exception $e) {
            // Caching failed, continue without cache
            error_log("[StorageService] Failed to cache Panel config: " . $e->getMessage());
        }
    }
    
    /**
     * Get stale Panel config from Redis (longer TTL fallback)
     */
    private function getStalePanelConfig(): ?array
    {
        try {
            $cache = new RedisCacheService($this->appConfig);
            $cached = $cache->get(self::PANEL_CACHE_STALE_KEY);
            return $cached ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Resolve which storage to use for a given domain
     */
    private function resolveStorageForDomain(array $panelConfig, ?string $domain): ?array
    {
        // Check domain overrides first
        if ($domain && !empty($panelConfig['domain_overrides'])) {
            foreach ($panelConfig['domain_overrides'] as $override) {
                if (isset($override['domain']) && strtolower($override['domain']) === strtolower($domain)) {
                    // Domain has a specific storage override
                    $storage = $override['storage'] ?? null;
                    if ($storage) {
                        // Apply sub_path if specified
                        if (!empty($override['sub_path'])) {
                            $storage['mount_point'] = rtrim($storage['mount_point'], '/') . '/' . ltrim($override['sub_path'], '/');
                        }
                        return $storage;
                    }
                }
            }
        }
        
        // Fall back to default storage
        return $panelConfig['default_storage'] ?? null;
    }
    
    /**
     * Load fallback configuration when Panel is unavailable
     */
    private function loadFallbackConfig(?array $appConfig): void
    {
        $this->driver = 'local';
        $this->basePath = $appConfig['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive';
        $this->storageName = 'Local Storage';
        $this->config = [
            'driver' => 'local',
            'source' => 'fallback',
            'storage_name' => $this->storageName,
            'path' => $this->basePath,
        ];
    }
    
    /**
     * Get the current storage driver type
     */
    public function getDriver(): string
    {
        return $this->driver;
    }
    
    /**
     * Get the storage name (from Panel)
     */
    public function getStorageName(): ?string
    {
        return $this->storageName;
    }
    
    /**
     * Get the base storage path
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
    
    /**
     * Get full configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Check if config came from Panel
     */
    public function isFromPanel(): bool
    {
        return ($this->config['source'] ?? '') === 'panel';
    }
    
    /**
     * Get the full path for a relative path
     */
    public function getFullPath(string $relativePath): string
    {
        return rtrim($this->basePath, '/') . '/' . ltrim($relativePath, '/');
    }
    
    /**
     * Returns true when basePath is on NAS and the mount is down.
     * Every public method that touches the filesystem should check this first.
     */
    private function isNasUnavailable(): bool
    {
        return NasHealthCheck::isNasPath($this->basePath) && !NasHealthCheck::isAvailable();
    }
    
    /**
     * Ensure a directory exists
     */
    public function ensureDirectory(string $path): bool
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        if (!is_dir($path)) {
            return mkdir($path, 0755, true);
        }
        return true;
    }
    
    /**
     * Check if a file or directory exists
     */
    public function exists(string $path): bool
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        return file_exists($path);
    }
    
    /**
     * Check if path is a directory
     */
    public function isDirectory(string $path): bool
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        return is_dir($path);
    }
    
    /**
     * Read file contents
     */
    public function get(string $path): string|false
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        if (!file_exists($path)) {
            return false;
        }
        return file_get_contents($path);
    }
    
    /**
     * Write file contents
     */
    public function put(string $path, string $contents): bool
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        $dir = dirname($path);
        $this->ensureDirectory($dir);
        return file_put_contents($path, $contents) !== false;
    }
    
    /**
     * Delete a file
     */
    public function delete(string $path): bool
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        if (!file_exists($path)) {
            return true;
        }
        return unlink($path);
    }
    
    /**
     * Move a file
     */
    public function move(string $from, string $to): bool
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        $dir = dirname($to);
        $this->ensureDirectory($dir);
        return rename($from, $to);
    }
    
    /**
     * Copy a file
     */
    public function copy(string $from, string $to): bool
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        $dir = dirname($to);
        $this->ensureDirectory($dir);
        return copy($from, $to);
    }
    
    /**
     * Move an uploaded file (wrapper for move_uploaded_file)
     */
    public function moveUploadedFile(string $tmpName, string $destination): bool
    {
        if ($this->isNasUnavailable()) {
            return false;
        }
        $dir = dirname($destination);
        $this->ensureDirectory($dir);
        return move_uploaded_file($tmpName, $destination);
    }
    
    /**
     * Get file size
     */
    public function size(string $path): int
    {
        if ($this->isNasUnavailable()) {
            return 0;
        }
        if (!file_exists($path)) {
            return 0;
        }
        return filesize($path) ?: 0;
    }
    
    /**
     * Get file modification time
     */
    public function lastModified(string $path): int
    {
        if ($this->isNasUnavailable()) {
            return 0;
        }
        if (!file_exists($path)) {
            return 0;
        }
        return filemtime($path) ?: 0;
    }
    
    /**
     * Test if storage is accessible and writable
     */
    public function testConnection(): array
    {
        if ($this->isNasUnavailable()) {
            return [
                'success' => false,
                'error' => 'NAS storage is currently unavailable (mount down)'
            ];
        }
        
        // Check if base path exists
        if (!is_dir($this->basePath)) {
            return [
                'success' => false,
                'error' => 'Storage path does not exist: ' . $this->basePath
            ];
        }
        
        // Check if writable
        if (!is_writable($this->basePath)) {
            return [
                'success' => false,
                'error' => 'Storage path is not writable: ' . $this->basePath
            ];
        }
        
        // Try to create and delete a test file
        $testFile = $this->basePath . '/.storage_test_' . time() . '_' . mt_rand();
        try {
            if (!file_put_contents($testFile, 'test')) {
                return [
                    'success' => false,
                    'error' => 'Failed to write test file'
                ];
            }
            
            $content = file_get_contents($testFile);
            if ($content !== 'test') {
                @unlink($testFile);
                return [
                    'success' => false,
                    'error' => 'Failed to read test file correctly'
                ];
            }
            
            @unlink($testFile);
            
            return [
                'success' => true,
                'driver' => $this->driver,
                'storage_name' => $this->storageName,
                'path' => $this->basePath,
                'source' => $this->config['source'] ?? 'unknown',
                'message' => 'Storage is accessible and writable'
            ];
        } catch (\Exception $e) {
            @unlink($testFile);
            return [
                'success' => false,
                'error' => 'Test failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get storage usage statistics
     */
    public function getUsageStats(): array
    {
        if ($this->isNasUnavailable()) {
            return [
                'available' => false,
                'error' => 'NAS storage is currently unavailable'
            ];
        }
        
        $total = @disk_total_space($this->basePath);
        $free = @disk_free_space($this->basePath);
        
        if ($total === false || $free === false) {
            return [
                'available' => false,
                'error' => 'Could not determine disk space'
            ];
        }
        
        $used = $total - $free;
        
        return [
            'available' => true,
            'driver' => $this->driver,
            'storage_name' => $this->storageName,
            'path' => $this->basePath,
            'source' => $this->config['source'] ?? 'unknown',
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'total_formatted' => $this->formatBytes($total),
            'used_formatted' => $this->formatBytes($used),
            'free_formatted' => $this->formatBytes($free),
            'percent_used' => $total > 0 ? round(($used / $total) * 100, 1) : 0
        ];
    }
    
    /**
     * Format bytes to human readable string
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
