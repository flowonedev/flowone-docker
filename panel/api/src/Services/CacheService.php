<?php

namespace VpsAdmin\Api\Services;

use VpsAdmin\Api\Core\Container;

/**
 * Redis-based caching service for VPS Admin
 * 
 * Key patterns:
 * - vps:sites:list         → All sites list
 * - vps:site:{domain}      → Single site config
 * - vps:dns:{domain}       → DNS zone records
 * - vps:mail:{domain}      → Mail accounts/forwards
 * - vps:db:list            → Database list
 * - vps:backups:list       → Backup list
 * - vps:files:{hash}       → Directory listing
 * - vps:stats:system       → System stats
 * - vps:ssl:{domain}       → SSL certificate info
 */
class CacheService
{
    private Container $container;
    private ?\Redis $redis = null;
    private bool $enabled = true;
    private string $prefix = 'vps:';
    
    // Default TTLs in seconds
    private array $ttls = [
        'sites' => 300,      // 5 minutes
        'site' => 300,       // 5 minutes
        'dns' => 300,        // 5 minutes
        'mail' => 300,       // 5 minutes
        'db' => 300,         // 5 minutes
        'backups' => 600,    // 10 minutes
        'files' => 30,       // 30 seconds
        'stats' => 10,       // 10 seconds
        'ssl' => 3600,       // 1 hour
        'default' => 300,    // 5 minutes default
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->initRedis();
    }

    /**
     * Initialize Redis connection
     */
    private function initRedis(): void
    {
        // Check if Redis extension is loaded
        if (!extension_loaded('redis') || !class_exists('\Redis')) {
            $this->enabled = false;
            debug_log("CacheService: Redis extension not loaded, caching disabled");
            return;
        }
        
        try {
            $config = $this->container->getConfig('redis') ?? [];
            
            $this->redis = new \Redis();
            
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 6379;
            $timeout = $config['timeout'] ?? 2.0;
            
            // Connect with timeout
            $connected = @$this->redis->connect($host, $port, $timeout);
            
            if (!$connected) {
                $this->enabled = false;
                debug_log("CacheService: Failed to connect to Redis at {$host}:{$port}");
                return;
            }
            
            // Authenticate if password is set
            if (!empty($config['password'])) {
                $this->redis->auth($config['password']);
            }
            
            // Select database if specified
            if (isset($config['database'])) {
                $this->redis->select((int) $config['database']);
            }
            
            // Set prefix if configured
            if (!empty($config['prefix'])) {
                $this->prefix = $config['prefix'];
            }
            
            // Override TTLs if configured
            if (!empty($config['ttls'])) {
                $this->ttls = array_merge($this->ttls, $config['ttls']);
            }
            
            $this->enabled = true;
            
        } catch (\Exception $e) {
            $this->enabled = false;
            debug_log("CacheService: Redis initialization failed - " . $e->getMessage());
        }
    }

    /**
     * Check if caching is enabled and working
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->redis !== null;
    }

    /**
     * Get a cached value
     */
    public function get(string $key): mixed
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $fullKey = $this->prefix . $key;
            $value = $this->redis->get($fullKey);
            
            if ($value === false) {
                return null;
            }
            
            return json_decode($value, true);
        } catch (\Exception $e) {
            debug_log("CacheService: Failed to get key '{$key}' - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set a cached value
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $fullKey = $this->prefix . $key;
            $serialized = json_encode($value);
            
            // Determine TTL based on key pattern if not explicitly provided
            if ($ttl === null) {
                $ttl = $this->getTtlForKey($key);
            }
            
            return $this->redis->setex($fullKey, $ttl, $serialized);
        } catch (\Exception $e) {
            debug_log("CacheService: Failed to set key '{$key}' - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a cached value
     */
    public function delete(string $key): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $fullKey = $this->prefix . $key;
            return $this->redis->del($fullKey) > 0;
        } catch (\Exception $e) {
            debug_log("CacheService: Failed to delete key '{$key}' - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete multiple keys matching a pattern
     * 
     * @param string $pattern Pattern with * wildcard (e.g., "dns:*", "site:example.com*")
     * @return int Number of keys deleted
     */
    public function deletePattern(string $pattern): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        try {
            $fullPattern = $this->prefix . $pattern;
            $keys = $this->redis->keys($fullPattern);
            
            if (empty($keys)) {
                return 0;
            }
            
            return $this->redis->del($keys);
        } catch (\Exception $e) {
            debug_log("CacheService: Failed to delete pattern '{$pattern}' - " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get or compute a cached value
     * 
     * If the key exists, return it. Otherwise, call the callback,
     * cache its result, and return it.
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        // Try to get from cache first
        $cached = $this->get($key);
        
        if ($cached !== null) {
            return $cached;
        }
        
        // Not cached, compute the value
        $value = $callback();
        
        // Cache the result (if caching is enabled)
        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }
        
        return $value;
    }

    /**
     * Invalidate all cache for a specific domain
     */
    public function invalidateForDomain(string $domain): int
    {
        $deleted = 0;
        $deleted += $this->deletePattern("site:{$domain}*");
        $deleted += $this->deletePattern("dns:{$domain}*");
        $deleted += $this->deletePattern("mail:{$domain}*");
        $deleted += $this->deletePattern("ssl:{$domain}*");
        $deleted += $this->deletePattern("files:" . md5("/home/{$domain}") . "*");
        
        // Also invalidate lists that might include this domain
        $this->delete('sites:list');
        $deleted++;
        
        return $deleted;
    }

    /**
     * Invalidate sites list cache
     */
    public function invalidateSites(): bool
    {
        return $this->delete('sites:list');
    }

    /**
     * Invalidate DNS cache for a domain
     */
    public function invalidateDns(string $domain): int
    {
        return $this->deletePattern("dns:{$domain}*");
    }

    /**
     * Invalidate mail cache for a domain
     */
    public function invalidateMail(string $domain): int
    {
        return $this->deletePattern("mail:{$domain}*");
    }

    /**
     * Invalidate database list cache
     */
    public function invalidateDatabases(): bool
    {
        return $this->delete('db:list');
    }

    /**
     * Invalidate backups list cache
     */
    public function invalidateBackups(): bool
    {
        return $this->delete('backups:list');
    }

    /**
     * Invalidate file listing cache for a path
     */
    public function invalidateFiles(string $path): bool
    {
        $hash = md5($path);
        return $this->delete("files:{$hash}");
    }

    /**
     * Flush all VPS cache
     */
    public function flush(): int
    {
        return $this->deletePattern('*');
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        if (!$this->isEnabled()) {
            return [
                'enabled' => false,
                'error' => 'Redis not connected',
            ];
        }

        try {
            $info = $this->redis->info();
            $keys = $this->redis->keys($this->prefix . '*');
            
            return [
                'enabled' => true,
                'connected' => true,
                'keys_count' => count($keys),
                'used_memory' => $info['used_memory_human'] ?? 'N/A',
                'uptime_seconds' => $info['uptime_in_seconds'] ?? 0,
                'hits' => $info['keyspace_hits'] ?? 0,
                'misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info),
            ];
        } catch (\Exception $e) {
            return [
                'enabled' => true,
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate cache hit rate
     */
    private function calculateHitRate(array $info): string
    {
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;
        
        if ($total === 0) {
            return 'N/A';
        }
        
        return round(($hits / $total) * 100, 2) . '%';
    }

    /**
     * Determine TTL based on key pattern
     */
    private function getTtlForKey(string $key): int
    {
        $parts = explode(':', $key);
        $category = $parts[0] ?? 'default';
        
        return $this->ttls[$category] ?? $this->ttls['default'];
    }

    /**
     * Generate a cache key for file listings
     */
    public static function filesKey(string $path): string
    {
        return 'files:' . md5($path);
    }

    /**
     * Generate a cache key for a domain's DNS
     */
    public static function dnsKey(string $domain): string
    {
        return "dns:{$domain}";
    }

    /**
     * Generate a cache key for a domain's mail
     */
    public static function mailKey(string $domain, string $type = 'accounts'): string
    {
        return "mail:{$domain}:{$type}";
    }
}


