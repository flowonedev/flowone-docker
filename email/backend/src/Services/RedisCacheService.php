<?php

namespace Webmail\Services;

/**
 * RedisCacheService - Unified Redis caching for the webmail application
 * 
 * Replaces file-based MessageCacheService with faster Redis caching.
 * Provides caching for messages, conversations, folders, and thumbnails.
 * 
 * Key Structure:
 *   webmail:{user_hash}:msg:{folder}:{uid}        -> Full message JSON
 *   webmail:{user_hash}:conv:{folder}             -> Conversations list JSON  
 *   webmail:{user_hash}:conv:members:{conv_id}    -> Conversation messages
 *   webmail:{user_hash}:folders                   -> Folder list with counts
 *   webmail:{user_hash}:folder:{name}:status      -> Single folder status
 *   webmail:drive:{user_hash}:{file_id}:thumb     -> Thumbnail binary (base64)
 */
class RedisCacheService
{
    private ?\Redis $redis = null;
    private array $config;
    private string $prefix;
    private bool $connected = false;
    
    public function __construct(array $config)
    {
        $this->config = $config['redis'] ?? [];
        $this->prefix = $this->config['prefix'] ?? 'webmail:';
        $this->connect();
    }
    
    /**
     * Connect to Redis server
     */
    private function connect(): bool
    {
        if ($this->connected && $this->redis) {
            return true;
        }
        
        try {
            $this->redis = new \Redis();
            
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = $this->config['port'] ?? 6379;
            $timeout = $this->config['timeout'] ?? 2.0;
            
            $connected = $this->redis->connect($host, $port, $timeout);
            
            if (!$connected) {
                error_log('[RedisCacheService] Failed to connect to Redis');
                $this->redis = null;
                return false;
            }
            
            // Authenticate if password is set
            $password = $this->config['password'] ?? null;
            if ($password) {
                if (!$this->redis->auth($password)) {
                    error_log('[RedisCacheService] Redis authentication failed');
                    $this->redis = null;
                    return false;
                }
            }
            
            // Select database
            $database = $this->config['database'] ?? 0;
            if ($database > 0) {
                $this->redis->select($database);
            }
            
            $this->connected = true;
            return true;
            
        } catch (\Throwable $e) {
            error_log('[RedisCacheService] Redis connection error: ' . $e->getMessage());
            $this->redis = null;
            $this->connected = false;
            return false;
        }
    }
    
    /**
     * Check if Redis is available
     */
    public function isAvailable(): bool
    {
        return $this->connected && $this->redis !== null;
    }
    
    /**
     * Get TTL for a specific cache type
     */
    public function getTtl(string $type): int
    {
        return $this->config['ttl'][$type] ?? 3600;
    }
    
    // ===== BASIC OPERATIONS =====
    
    /**
     * Get a value from cache
     */
    public function get(string $key): mixed
    {
        if (!$this->isAvailable()) {
            return null;
        }
        
        try {
            $fullKey = $this->prefix . $key;
            $value = $this->redis->get($fullKey);
            
            if ($value === false) {
                return null;
            }
            
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
            
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] Get error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Set a value in cache
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $fullKey = $this->prefix . $key;
            $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            
            if ($ttl !== null && $ttl > 0) {
                return $this->redis->setex($fullKey, $ttl, $encoded);
            }
            
            return $this->redis->set($fullKey, $encoded);
            
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] Set error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a key from cache
     */
    public function delete(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $fullKey = $this->prefix . $key;
            return $this->redis->del($fullKey) > 0;
            
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] Delete error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Append a value to the tail of a Redis list (RPUSH).
     * Used as a durable work queue (e.g. fcm_prune_queue) drained by a PHP cron.
     */
    public function listPush(string $key, string $value): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return $this->redis->rPush($this->prefix . $key, $value) !== false;
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] listPush error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Pop a value from the head of a Redis list (LPOP). Returns null when empty.
     */
    public function listPop(string $key): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $value = $this->redis->lPop($this->prefix . $key);
            return $value === false ? null : $value;
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] listPop error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Current length of a Redis list.
     */
    public function listLen(string $key): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        try {
            return (int) $this->redis->lLen($this->prefix . $key);
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] listLen error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete all keys matching a pattern
     */
    public function deletePattern(string $pattern): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        
        try {
            $fullPattern = $this->prefix . $pattern;
            $keys = $this->redis->keys($fullPattern);
            
            if (empty($keys)) {
                return 0;
            }
            
            return $this->redis->del($keys);
            
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] DeletePattern error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Check if a key exists
     */
    public function exists(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $fullKey = $this->prefix . $key;
            return (bool)$this->redis->exists($fullKey);
            
        } catch (\RedisException $e) {
            return false;
        }
    }
    
    /**
     * Get multiple values at once
     */
    public function getMultiple(array $keys): array
    {
        if (!$this->isAvailable() || empty($keys)) {
            return [];
        }
        
        try {
            $fullKeys = array_map(fn($k) => $this->prefix . $k, $keys);
            $values = $this->redis->mget($fullKeys);
            
            $result = [];
            foreach ($keys as $i => $key) {
                if (isset($values[$i]) && $values[$i] !== false) {
                    $decoded = json_decode($values[$i], true);
                    $result[$key] = $decoded !== null ? $decoded : $values[$i];
                }
            }
            
            return $result;
            
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] GetMultiple error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Set multiple values at once
     */
    public function setMultiple(array $items, ?int $ttl = null): bool
    {
        if (!$this->isAvailable() || empty($items)) {
            return false;
        }
        
        try {
            $pipeline = $this->redis->multi(\Redis::PIPELINE);
            
            foreach ($items as $key => $value) {
                $fullKey = $this->prefix . $key;
                $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
                
                if ($ttl !== null && $ttl > 0) {
                    $pipeline->setex($fullKey, $ttl, $encoded);
                } else {
                    $pipeline->set($fullKey, $encoded);
                }
            }
            
            $pipeline->exec();
            return true;
            
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] SetMultiple error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Set expiry on a key
     */
    public function expire(string $key, int $ttl): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $fullKey = $this->prefix . $key;
            return $this->redis->expire($fullKey, $ttl);
            
        } catch (\RedisException $e) {
            return false;
        }
    }
    
    /**
     * Increment a value
     */
    public function increment(string $key, int $by = 1): int|false
    {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $fullKey = $this->prefix . $key;
            return $this->redis->incrBy($fullKey, $by);
            
        } catch (\RedisException $e) {
            return false;
        }
    }

    // ===== SORTED-SET / HASH PRIMITIVES =====
    //
    // These wrap the raw \Redis client for callers that need
    // ZADD/ZCARD/ZREMRANGEBYSCORE (CircuitBreaker), HSET/HGET/HDEL
    // (FolderCacheInvalidator), and SETNX (scan-generation fence).
    // All operations are best-effort: if Redis is unavailable they
    // return safe defaults so callers can fail open.

    /**
     * ZADD wrapper. Returns the number of newly added elements, or 0 on error.
     */
    public function zAdd(string $key, float $score, string $member): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        try {
            $added = $this->redis->zAdd($this->prefix . $key, $score, $member);
            return is_int($added) ? $added : 0;
        } catch (\RedisException $e) {
            return 0;
        }
    }

    /**
     * ZCARD wrapper. Returns the cardinality of the sorted set, or 0 on error.
     */
    public function zCard(string $key): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        try {
            $count = $this->redis->zCard($this->prefix . $key);
            return is_int($count) ? $count : 0;
        } catch (\RedisException $e) {
            return 0;
        }
    }

    /**
     * ZREMRANGEBYSCORE wrapper. Returns number of elements removed, or 0 on error.
     */
    public function zRemRangeByScore(string $key, float|string $min, float|string $max): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        try {
            $removed = $this->redis->zRemRangeByScore($this->prefix . $key, (string) $min, (string) $max);
            return is_int($removed) ? $removed : 0;
        } catch (\RedisException $e) {
            return 0;
        }
    }

    /**
     * SETNX wrapper. Sets key to value only if it does not already exist.
     * Returns true if set, false otherwise (including when Redis is down).
     * Optional TTL applied via separate EXPIRE for portability.
     */
    public function setIfNotExists(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            $fullKey = $this->prefix . $key;
            $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $set = (bool) $this->redis->set($fullKey, $encoded, ['NX']);
            if ($set && $ttl !== null && $ttl > 0) {
                $this->redis->expire($fullKey, $ttl);
            }
            return $set;
        } catch (\RedisException $e) {
            return false;
        }
    }

    /**
     * HSET wrapper. Sets a single field on a hash. Returns true on success.
     */
    public function hSet(string $key, string $field, mixed $value): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            return $this->redis->hSet($this->prefix . $key, $field, $encoded) !== false;
        } catch (\RedisException $e) {
            return false;
        }
    }

    /**
     * HGETALL wrapper. Returns the hash as an assoc array (decoded), or [] on error.
     */
    public function hGetAll(string $key): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        try {
            $raw = $this->redis->hGetAll($this->prefix . $key);
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $field => $value) {
                $decoded = json_decode((string) $value, true);
                $out[$field] = $decoded !== null ? $decoded : $value;
            }
            return $out;
        } catch (\RedisException $e) {
            return [];
        }
    }

    /**
     * HDEL wrapper. Returns true on success.
     */
    public function hDelete(string $key, string $field): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        try {
            return $this->redis->hDel($this->prefix . $key, $field) > 0;
        } catch (\RedisException $e) {
            return false;
        }
    }

    /**
     * LPUSH wrapper. Generic list-push helper used by any code that
     * needs Redis-backed FIFO queues.
     * Returns the new length of the list, or 0 on error.
     */
    public function lPush(string $key, mixed $value): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        try {
            $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $len = $this->redis->lPush($this->prefix . $key, $encoded);
            return is_int($len) ? $len : 0;
        } catch (\RedisException $e) {
            return 0;
        }
    }

    /**
     * BRPOP wrapper. Blocks until an entry is pushed to the list or
     * timeoutSeconds elapses. Returns the decoded payload, or null on
     * timeout or error.
     */
    public function bRPop(string $key, int $timeoutSeconds = 1): mixed
    {
        if (!$this->isAvailable()) {
            return null;
        }
        try {
            $result = $this->redis->brPop([$this->prefix . $key], max(0, $timeoutSeconds));
            if (!is_array($result) || count($result) < 2) {
                return null;
            }
            $value = $result[1];
            $decoded = json_decode((string)$value, true);
            return $decoded !== null ? $decoded : $value;
        } catch (\RedisException $e) {
            return null;
        }
    }

    /**
     * LLEN wrapper. Returns the length of the list, or 0 on error.
     */
    public function lLen(string $key): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        try {
            $len = $this->redis->lLen($this->prefix . $key);
            return is_int($len) ? $len : 0;
        } catch (\RedisException $e) {
            return 0;
        }
    }

    /**
     * RPOP wrapper. Returns the popped value or null when the list is
     * empty / Redis is down. Used by the sync cron to drain
     * cross-process queues (e.g. flowone:idle:tombstones, populated by
     * the Node mailsync worker).
     */
    public function rPop(string $key): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        try {
            $val = $this->redis->rPop($this->prefix . $key);
            return $val === false || $val === null ? null : (string)$val;
        } catch (\RedisException $e) {
            return null;
        }
    }

    // ===== KEY HELPERS =====
    
    /**
     * Get user hash for cache keys (consistent with old file-based approach)
     */
    public function getUserHash(string $userEmail): string
    {
        return substr(md5(strtolower($userEmail)), 0, 16);
    }
    
    /**
     * Generate message cache key
     */
    public function messageKey(string $userEmail, string $folder, int $uid): string
    {
        $userHash = $this->getUserHash($userEmail);
        $folderSafe = str_replace(['/', '\\', ':'], '_', $folder);
        return "{$userHash}:msg:{$folderSafe}:{$uid}";
    }
    
    /**
     * Generate message list cache key (envelope data only)
     */
    public function messageListKey(string $userEmail, string $folder, int $page = 1): string
    {
        $userHash = $this->getUserHash($userEmail);
        $folderSafe = str_replace(['/', '\\', ':'], '_', $folder);
        return "{$userHash}:msglist:{$folderSafe}:p{$page}";
    }
    
    /**
     * Generate conversations list cache key
     */
    public function conversationsKey(string $userEmail, string $folder): string
    {
        $userHash = $this->getUserHash($userEmail);
        $folderSafe = str_replace(['/', '\\', ':'], '_', $folder);
        return "{$userHash}:conv:{$folderSafe}";
    }
    
    /**
     * Generate conversation members cache key
     */
    public function conversationMembersKey(string $userEmail, string $conversationId): string
    {
        $userHash = $this->getUserHash($userEmail);
        return "{$userHash}:conv:members:{$conversationId}";
    }
    
    /**
     * Generate folder list cache key
     */
    public function folderListKey(string $userEmail): string
    {
        $userHash = $this->getUserHash($userEmail);
        return "{$userHash}:folders";
    }
    
    /**
     * Generate folder status cache key
     */
    public function folderStatusKey(string $userEmail, string $folder): string
    {
        $userHash = $this->getUserHash($userEmail);
        $folderSafe = str_replace(['/', '\\', ':'], '_', $folder);
        return "{$userHash}:folder:{$folderSafe}:status";
    }
    
    /**
     * Generate thumbnail cache key
     */
    public function thumbnailKey(string $userEmail, int $fileId): string
    {
        $userHash = $this->getUserHash($userEmail);
        return "{$userHash}:thumb:{$fileId}";
    }
    
    // ===== VERSION TRACKING (ETags) =====
    
    /**
     * Generate version key for a cached item
     */
    public function versionKey(string $userEmail, string $type, string $identifier): string
    {
        $userHash = $this->getUserHash($userEmail);
        return "{$userHash}:version:{$type}:{$identifier}";
    }
    
    /**
     * Get current version for a cached item
     */
    public function getVersion(string $userEmail, string $type, string $identifier): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        
        $key = $this->versionKey($userEmail, $type, $identifier);
        $version = $this->get($key);
        return $version ? (int)$version : 0;
    }
    
    /**
     * Increment version for a cached item (returns new version)
     */
    public function incrementVersion(string $userEmail, string $type, string $identifier): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        
        $key = $this->versionKey($userEmail, $type, $identifier);
        $newVersion = $this->increment($key);
        
        // Set expiry to match item TTL
        $this->expire($key, 86400); // 24 hours
        
        return $newVersion ?: 1;
    }
    
    /**
     * Generate ETag from version and identifier
     */
    public function generateEtag(int $version, string $identifier): string
    {
        return '"' . md5($version . ':' . $identifier) . '"';
    }
    
    /**
     * Check if client ETag matches current version
     */
    public function checkEtag(string $clientEtag, int $currentVersion, string $identifier): bool
    {
        $serverEtag = $this->generateEtag($currentVersion, $identifier);
        return $clientEtag === $serverEtag;
    }
    
    // ===== MESSAGE CACHE OPERATIONS =====
    
    /**
     * Get cached message with version
     */
    public function getMessage(string $userEmail, string $folder, int $uid): ?array
    {
        $key = $this->messageKey($userEmail, $folder, $uid);
        $data = $this->get($key);
        
        if ($data && is_array($data)) {
            $data['_from_cache'] = true;
            $data['_cache_type'] = 'redis';
            
            // Add version/ETag
            $versionId = "{$folder}:{$uid}";
            $version = $this->getVersion($userEmail, 'msg', $versionId);
            $data['_version'] = $version;
            $data['_etag'] = $this->generateEtag($version, $versionId);
            
            return $data;
        }
        
        return null;
    }
    
    /**
     * Cache a message with version tracking
     */
    public function setMessage(string $userEmail, string $folder, int $uid, array $message, bool $incrementVersion = false): bool
    {
        // Remove cache metadata before storing
        unset($message['_from_cache'], $message['_cache_type'], $message['_cached_at'], $message['_version'], $message['_etag']);
        
        $key = $this->messageKey($userEmail, $folder, $uid);
        $ttl = $this->getTtl('message');
        
        // Increment version if this is an update
        if ($incrementVersion) {
            $versionId = "{$folder}:{$uid}";
            $this->incrementVersion($userEmail, 'msg', $versionId);
        }
        
        return $this->set($key, $message, $ttl);
    }
    
    /**
     * Get multiple messages at once
     */
    public function getMessages(string $userEmail, string $folder, array $uids): array
    {
        if (empty($uids)) {
            return [];
        }
        
        $keys = [];
        $keyToUid = [];
        
        foreach ($uids as $uid) {
            $key = $this->messageKey($userEmail, $folder, (int)$uid);
            $keys[] = $key;
            $keyToUid[$key] = $uid;
        }
        
        $cached = $this->getMultiple($keys);
        
        $result = [];
        foreach ($cached as $key => $message) {
            if (is_array($message)) {
                $uid = $keyToUid[$key] ?? null;
                if ($uid !== null) {
                    $message['_from_cache'] = true;
                    $message['_cache_type'] = 'redis';
                    $result[$uid] = $message;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Cache multiple messages at once
     */
    public function setMessages(string $userEmail, string $folder, array $messages): bool
    {
        if (empty($messages)) {
            return true;
        }
        
        $items = [];
        $ttl = $this->getTtl('message');
        
        foreach ($messages as $uid => $message) {
            unset($message['_from_cache'], $message['_cache_type'], $message['_cached_at']);
            $key = $this->messageKey($userEmail, $folder, (int)$uid);
            $items[$key] = $message;
        }
        
        return $this->setMultiple($items, $ttl);
    }
    
    /**
     * Invalidate a single message cache
     */
    public function invalidateMessage(string $userEmail, string $folder, int $uid): bool
    {
        $key = $this->messageKey($userEmail, $folder, $uid);
        return $this->delete($key);
    }
    
    /**
     * Invalidate all messages in a folder
     */
    public function invalidateFolder(string $userEmail, string $folder): int
    {
        $userHash = $this->getUserHash($userEmail);
        $folderSafe = str_replace(['/', '\\', ':'], '_', $folder);
        
        $count = 0;
        
        // Delete message cache
        $count += $this->deletePattern("{$userHash}:msg:{$folderSafe}:*");
        
        // Delete message list cache
        $count += $this->deletePattern("{$userHash}:msglist:{$folderSafe}:*");
        
        // Delete conversation cache for this folder
        $this->delete("{$userHash}:conv:{$folderSafe}");
        $count++;
        
        // Delete folder status cache
        $this->delete("{$userHash}:folder:{$folderSafe}:status");
        $count++;
        
        return $count;
    }
    
    /**
     * Invalidate all cache for a user
     */
    public function invalidateUser(string $userEmail): int
    {
        $userHash = $this->getUserHash($userEmail);
        return $this->deletePattern("{$userHash}:*");
    }
    
    // ===== CONVERSATION CACHE OPERATIONS =====
    
    /**
     * Get cached conversations for a folder
     */
    public function getConversations(string $userEmail, string $folder): ?array
    {
        $key = $this->conversationsKey($userEmail, $folder);
        $data = $this->get($key);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Cache conversations for a folder
     */
    public function setConversations(string $userEmail, string $folder, array $conversations): bool
    {
        $key = $this->conversationsKey($userEmail, $folder);
        $ttl = $this->getTtl('conversation');
        return $this->set($key, $conversations, $ttl);
    }
    
    /**
     * Invalidate conversations cache for a folder
     */
    public function invalidateConversations(string $userEmail, string $folder): bool
    {
        $key = $this->conversationsKey($userEmail, $folder);
        return $this->delete($key);
    }
    
    /**
     * Get cached conversation members
     */
    public function getConversationMembers(string $userEmail, string $conversationId): ?array
    {
        $key = $this->conversationMembersKey($userEmail, $conversationId);
        $data = $this->get($key);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Cache conversation members
     */
    public function setConversationMembers(string $userEmail, string $conversationId, array $members): bool
    {
        $key = $this->conversationMembersKey($userEmail, $conversationId);
        $ttl = $this->getTtl('conversation');
        return $this->set($key, $members, $ttl);
    }
    
    /**
     * Invalidate conversation members cache
     */
    public function invalidateConversationMembers(string $userEmail, string $conversationId): bool
    {
        $key = $this->conversationMembersKey($userEmail, $conversationId);
        return $this->delete($key);
    }
    
    // ===== FOLDER CACHE OPERATIONS =====
    
    /**
     * Get cached folder list
     */
    public function getFolderList(string $userEmail): ?array
    {
        $key = $this->folderListKey($userEmail);
        $data = $this->get($key);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Cache folder list
     */
    public function setFolderList(string $userEmail, array $folders): bool
    {
        $key = $this->folderListKey($userEmail);
        $ttl = $this->getTtl('folder_list');
        return $this->set($key, $folders, $ttl);
    }
    
    /**
     * Invalidate folder list cache
     */
    public function invalidateFolderList(string $userEmail): bool
    {
        $key = $this->folderListKey($userEmail);
        return $this->delete($key);
    }
    
    /**
     * Bump folder counts in the cached folder list without dropping the
     * whole cache.
     *
     * Phase 1.3 originally called invalidateFolderList() on every move /
     * delete / rename. On Gmail OAuth that forces the next sidebar refresh
     * to STATUS every folder over the OAuth socket (hundreds of ms each),
     * which is what made simple moves "feel slow".
     *
     * This helper applies the same delta the operation just produced
     * (unread / total) to the matching row(s) of the cached folder list,
     * leaving every other row intact. When the cache is missing or the
     * target folder isn't in the cached list, returns false and the
     * caller is free to fall back to a hard invalidation.
     *
     * @param array $deltas [folder_name => ['unread' => int, 'total' => int]]
     */
    public function bumpFolderCounts(string $userEmail, array $deltas): bool
    {
        if (empty($deltas)) {
            return true;
        }
        $key = $this->folderListKey($userEmail);
        $cached = $this->get($key);
        if (!is_array($cached) || empty($cached)) {
            return false;
        }
        $touched = false;
        foreach ($cached as &$folder) {
            $name = $folder['name'] ?? null;
            if ($name === null || !isset($deltas[$name])) {
                continue;
            }
            $delta = $deltas[$name];
            if (isset($delta['unread'])) {
                $folder['unread'] = max(0, (int)($folder['unread'] ?? 0) + (int)$delta['unread']);
            }
            if (isset($delta['total'])) {
                $folder['total'] = max(0, (int)($folder['total'] ?? 0) + (int)$delta['total']);
            }
            $touched = true;
        }
        unset($folder);
        if (!$touched) {
            return false;
        }
        $ttl = $this->getTtl('folder_list');
        return $this->set($key, $cached, $ttl);
    }
    
    /**
     * Get cached folder status
     */
    public function getFolderStatus(string $userEmail, string $folder): ?array
    {
        $key = $this->folderStatusKey($userEmail, $folder);
        $data = $this->get($key);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Cache folder status
     */
    public function setFolderStatus(string $userEmail, string $folder, array $status): bool
    {
        $key = $this->folderStatusKey($userEmail, $folder);
        $ttl = $this->getTtl('folder_status');
        return $this->set($key, $status, $ttl);
    }
    
    /**
     * Invalidate folder status cache
     */
    public function invalidateFolderStatus(string $userEmail, string $folder): bool
    {
        $key = $this->folderStatusKey($userEmail, $folder);
        return $this->delete($key);
    }
    
    // ===== THUMBNAIL CACHE OPERATIONS =====
    
    /**
     * Get cached thumbnail (base64 encoded)
     */
    public function getThumbnail(string $userEmail, int $fileId): ?string
    {
        // Try new key format first
        $key = $this->thumbnailKey($userEmail, $fileId);
        $data = $this->get($key);
        if (is_string($data)) {
            return $data;
        }
        
        // Fallback to legacy key format (drive:{userHash}:{fileId}:thumb)
        $userHash = $this->getUserHash($userEmail);
        $legacyKey = "drive:{$userHash}:{$fileId}:thumb";
        $data = $this->get($legacyKey);
        return is_string($data) ? $data : null;
    }
    
    /**
     * Cache thumbnail (base64 encoded)
     */
    public function setThumbnail(string $userEmail, int $fileId, string $base64Data): bool
    {
        $key = $this->thumbnailKey($userEmail, $fileId);
        $ttl = $this->getTtl('thumbnail');
        return $this->set($key, $base64Data, $ttl);
    }
    
    /**
     * Invalidate thumbnail cache
     */
    public function invalidateThumbnail(string $userEmail, int $fileId): bool
    {
        // Delete new format key
        $key = $this->thumbnailKey($userEmail, $fileId);
        $result = $this->delete($key);
        
        // Also delete legacy format key if it exists
        $userHash = $this->getUserHash($userEmail);
        $legacyKey = "drive:{$userHash}:{$fileId}:thumb";
        $this->delete($legacyKey);
        
        return $result;
    }
    
    // ===== FOLDER RENAME SUPPORT =====
    
    /**
     * Handle folder rename - migrate all cache keys from old to new folder name
     * This invalidates cache for the old folder since UIDs may have changed
     */
    public function handleFolderRename(string $userEmail, string $oldFolder, string $newFolder): int
    {
        $userHash = $this->getUserHash($userEmail);
        $oldFolderSafe = str_replace(['/', '\\', ':'], '_', $oldFolder);
        $newFolderSafe = str_replace(['/', '\\', ':'], '_', $newFolder);
        
        $count = 0;
        
        // Invalidate old folder's message cache (UIDs may be different)
        $count += $this->deletePattern("{$userHash}:msg:{$oldFolderSafe}:*");
        
        // Invalidate old folder's message list cache
        $count += $this->deletePattern("{$userHash}:msglist:{$oldFolderSafe}:*");
        
        // Invalidate conversation cache for old folder
        $this->delete("{$userHash}:conv:{$oldFolderSafe}");
        $count++;
        
        // Invalidate folder status for old folder
        $this->delete("{$userHash}:folder:{$oldFolderSafe}:status");
        $count++;
        
        // Invalidate folder list (needs refresh)
        $this->delete("{$userHash}:folders");
        $count++;
        
        // Also handle child folders (e.g., INBOX.Work -> INBOX.Projects means INBOX.Work.Sub -> INBOX.Projects.Sub)
        $count += $this->deletePattern("{$userHash}:msg:{$oldFolderSafe}.*");
        $count += $this->deletePattern("{$userHash}:msglist:{$oldFolderSafe}.*");
        $count += $this->deletePattern("{$userHash}:conv:{$oldFolderSafe}.*");
        $count += $this->deletePattern("{$userHash}:folder:{$oldFolderSafe}.*");
        
        error_log("[RedisCacheService] Folder rename cache cleanup: {$oldFolder} -> {$newFolder}, invalidated {$count} keys");
        
        return $count;
    }
    
    // ===== STATS & MAINTENANCE =====
    
    /**
     * Get cache statistics for a user
     */
    public function getUserStats(string $userEmail): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false];
        }
        
        $userHash = $this->getUserHash($userEmail);
        
        try {
            // Get keys with standard format: {prefix}{userHash}:*
            $keys = $this->redis->keys($this->prefix . "{$userHash}:*");
            
            // Also get legacy thumbnail keys: {prefix}drive:{userHash}:*
            $legacyThumbKeys = $this->redis->keys($this->prefix . "drive:{$userHash}:*");
            
            $stats = [
                'available' => true,
                'total_keys' => count($keys) + count($legacyThumbKeys),
                'messages' => 0,
                'conversations' => 0,
                'folders' => 0,
                'thumbnails' => count($legacyThumbKeys), // Count legacy thumbnails
            ];
            
            foreach ($keys as $key) {
                if (strpos($key, ':msg:') !== false) {
                    $stats['messages']++;
                } elseif (strpos($key, ':conv:') !== false) {
                    $stats['conversations']++;
                } elseif (strpos($key, ':folder') !== false) {
                    $stats['folders']++;
                } elseif (strpos($key, ':thumb:') !== false) {
                    $stats['thumbnails']++;
                }
            }
            
            return $stats;
            
        } catch (\RedisException $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get Redis server info
     */
    public function getServerInfo(): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false];
        }
        
        try {
            $info = $this->redis->info();
            
            return [
                'available' => true,
                'version' => $info['redis_version'] ?? 'unknown',
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'uptime_days' => ($info['uptime_in_seconds'] ?? 0) / 86400,
                'total_keys' => $info['db0']['keys'] ?? 0,
            ];
            
        } catch (\RedisException $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Flush all webmail cache (dangerous - use carefully)
     */
    public function flushAll(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $keys = $this->redis->keys($this->prefix . '*');
            
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            
            error_log('[RedisCacheService] Flushed all cache: ' . count($keys) . ' keys deleted');
            return true;
            
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] FlushAll error: ' . $e->getMessage());
            return false;
        }
    }
    
    // ===== PUB/SUB FOR REAL-TIME SYNC =====
    
    /**
     * Event types for WebSocket sync
     */
    const EVENT_MESSAGE_NEW = 'MESSAGE_NEW';
    const EVENT_MESSAGE_DELETED = 'MESSAGE_DELETED';
    const EVENT_MESSAGE_MOVED = 'MESSAGE_MOVED';
    const EVENT_FLAGS_CHANGED = 'FLAGS_CHANGED';
    const EVENT_FOLDER_COUNTS = 'FOLDER_COUNTS';
    const EVENT_CONVERSATION_UPDATED = 'CONVERSATION_UPDATED';
    const EVENT_FOLDER_CHANGED = 'FOLDER_CHANGED';
    const EVENT_SETTINGS_CHANGED = 'SETTINGS_CHANGED';
    const EVENT_PIN_CHANGED = 'PIN_CHANGED';
    const EVENT_LABELS_CHANGED = 'LABELS_CHANGED';
    
    // Board events
    const EVENT_BOARD_UPDATED = 'BOARD_UPDATED';
    const EVENT_LIST_UPDATED = 'LIST_UPDATED';
    const EVENT_CARD_UPDATED = 'CARD_UPDATED';
    const EVENT_CALENDAR_UPDATED = 'CALENDAR_UPDATED';
    const EVENT_CHECKLIST_UPDATED = 'CHECKLIST_UPDATED';
    const EVENT_TODO_UPDATED = 'TODO_UPDATED';
    
    /**
     * Publish an event to the user's mailbox channel
     * Events are picked up by the WebSocket server for real-time sync
     * 
     * @param string $userEmail User's email address
     * @param string $eventType Event type constant
     * @param array $payload Event payload data
     * @return bool Success status
     */
    public function publishEvent(string $userEmail, string $eventType, array $payload): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $channel = $this->prefix . 'mailbox:' . $userEmail;
            
            $message = json_encode([
                'type' => $eventType,
                'payload' => $payload,
                'timestamp' => round(microtime(true) * 1000), // milliseconds
            ], JSON_UNESCAPED_UNICODE);
            
            $result = $this->redis->publish($channel, $message);
            
            // Log for debugging (can be disabled in production)
            error_log("[RedisCacheService] Published {$eventType} to {$channel}: " . ($result > 0 ? "{$result} subscribers" : "no subscribers"));
            
            return true;
            
        } catch (\RedisException $e) {
            error_log('[RedisCacheService] Publish error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Claim the right to publish a new-mail event for a single (user, folder, uid)
     * exactly once across concurrent sync passes.
     *
     * The full 5-minute cache-warmer and the per-minute incremental tick can
     * both detect the same fresh UID before highest_uid advances, which fans out
     * the same MESSAGE_NEW push twice (or more, on minute marks where both fire).
     * A short-lived SET NX key lets the first pass win and the others skip, so
     * each new message produces exactly one push.
     *
     * Fails OPEN (returns true) when Redis is unavailable: a rare duplicate is
     * preferable to silently dropping a new-mail notification.
     */
    public function claimNewMailPublish(string $userEmail, string $folder, int $uid, int $ttl = 3600): bool
    {
        if (!$this->isAvailable() || $uid <= 0) {
            return true;
        }

        try {
            $key = $this->prefix . 'pushed:' . strtolower($userEmail) . ':' . $folder . ':' . $uid;
            $ok = $this->redis->set($key, '1', ['nx', 'ex' => $ttl]);
            return $ok === true;
        } catch (\Throwable $e) {
            error_log('[RedisCacheService] claimNewMailPublish error: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Publish a new message event
     *
     * When the preview carries no_push=true (e.g. the user's own Sent-folder
     * copy from MessageController), the flag rides along on the payload so the
     * Node mailsync sender suppresses the device push while the event still
     * fans out to connected clients for cross-device sync. Defaults to false,
     * so every existing caller (cron sync, IDLE) is unaffected.
     */
    public function publishNewMessage(string $userEmail, string $folder, int $uid, array $messagePreview = []): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_MESSAGE_NEW, [
            'folder' => $folder,
            'uid' => $uid,
            'from' => $messagePreview['from'] ?? null,
            'subject' => $messagePreview['subject'] ?? null,
            'date' => $messagePreview['date'] ?? null,
            'no_push' => !empty($messagePreview['no_push']),
        ]);
    }
    
    /**
     * Publish a message deleted event
     */
    public function publishMessageDeleted(string $userEmail, string $folder, int $uid, bool $permanent = false): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_MESSAGE_DELETED, [
            'folder' => $folder,
            'uid' => $uid,
            'permanent' => $permanent,
        ]);
    }
    
    /**
     * Publish a message moved event
     */
    public function publishMessageMoved(string $userEmail, string $sourceFolder, string $targetFolder, int $oldUid, ?int $newUid = null): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_MESSAGE_MOVED, [
            'sourceFolder' => $sourceFolder,
            'targetFolder' => $targetFolder,
            'oldUid' => $oldUid,
            'newUid' => $newUid,
        ]);
    }
    
    /**
     * Publish a flags changed event
     */
    public function publishFlagsChanged(string $userEmail, string $folder, int $uid, array $flags): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_FLAGS_CHANGED, [
            'folder' => $folder,
            'uid' => $uid,
            'flags' => $flags,
        ]);
    }
    
    /**
     * Publish a pin changed event
     */
    public function publishPinChanged(string $userEmail, string $folder, int $uid, bool $pinned): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_PIN_CHANGED, [
            'folder' => $folder,
            'uid' => $uid,
            'pinned' => $pinned,
        ]);
    }

    public function publishLabelsChanged(string $userEmail, string $messageId, int $labelId, string $action, ?array $label = null): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_LABELS_CHANGED, [
            'messageId' => $messageId,
            'labelId' => $labelId,
            'action' => $action,
            'label' => $label,
        ]);
    }
    
    /**
     * Publish folder counts updated event
     */
    public function publishFolderCounts(string $userEmail, string $folder, int $total, int $unread, ?int $uidnext = null, ?int $uidvalidity = null): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_FOLDER_COUNTS, [
            'folder' => $folder,
            'total' => $total,
            'unread' => $unread,
            'uidnext' => $uidnext,
            'uidvalidity' => $uidvalidity,
        ]);
    }
    
    /**
     * Publish conversation updated event
     */
    public function publishConversationUpdated(string $userEmail, string $conversationId, string $folder): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_CONVERSATION_UPDATED, [
            'conversationId' => $conversationId,
            'folder' => $folder,
        ]);
    }
    
    /**
     * Publish folder changed event (created/renamed/deleted)
     */
    public function publishFolderChanged(string $userEmail, string $action, string $folder, ?string $newName = null): bool
    {
        // Read the (already-bumped) per-account folder_identity_version so
        // every receiver can update its baseline atomically with the event.
        // Any clients that miss this event will detect drift on reconnect
        // via the /mailbox/folders/identity-version endpoint and invalidate.
        $version = 0;
        try {
            $telem = new DualWriteTelemetry($this);
            $version = $telem->getFolderIdentityVersion($userEmail);
        } catch (\Throwable $e) {
            // Telemetry must never break event delivery. The frontend
            // treats version=0 as "ignore field, fall back to event-only
            // invalidation".
        }

        return $this->publishEvent($userEmail, self::EVENT_FOLDER_CHANGED, [
            'action' => $action, // 'created', 'renamed', 'deleted'
            'folder' => $folder,
            'newName' => $newName,
            'folder_identity_version' => $version,
        ]);
    }
    
    /**
     * Publish settings changed event (theme, accent color, density, etc.)
     */
    public function publishSettingsChanged(string $userEmail, array $changedSettings): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_SETTINGS_CHANGED, [
            'settings' => $changedSettings,
        ]);
    }
    
    /**
     * Publish board updated event
     */
    public function publishBoardUpdated(string $userEmail, int $boardId, array $changes = []): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_BOARD_UPDATED, [
            'board_id' => $boardId,
            'changes' => $changes,
        ]);
    }
    
    /**
     * Publish list updated event
     */
    public function publishListUpdated(string $userEmail, int $boardId, int $listId, string $action = 'updated'): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_LIST_UPDATED, [
            'board_id' => $boardId,
            'list_id' => $listId,
            'action' => $action,
        ]);
    }
    
    /**
     * Publish card updated event
     */
    public function publishCardUpdated(string $userEmail, int $boardId, int $cardId, string $action = 'updated'): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_CARD_UPDATED, [
            'board_id' => $boardId,
            'card_id' => $cardId,
            'action' => $action,
        ]);
    }
    
    /**
     * Publish calendar updated event
     */
    public function publishCalendarUpdated(string $userEmail, int $calendarId, ?int $eventId = null, string $action = 'updated'): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_CALENDAR_UPDATED, [
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
            'action' => $action,
        ]);
    }
    
    /**
     * Publish checklist item updated event (board checklists)
     */
    public function publishChecklistUpdated(string $userEmail, int $cardId, int $itemId, string $action = 'updated', ?bool $completed = null): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_CHECKLIST_UPDATED, [
            'card_id' => $cardId,
            'item_id' => $itemId,
            'action' => $action,
            'completed' => $completed,
        ]);
    }
    
    /**
     * Publish todo updated event (standalone todos)
     */
    public function publishTodoUpdated(string $userEmail, int $todoId, string $action = 'updated', ?bool $completed = null): bool
    {
        return $this->publishEvent($userEmail, self::EVENT_TODO_UPDATED, [
            'todo_id' => $todoId,
            'action' => $action,
            'completed' => $completed,
        ]);
    }
    
    // ========================================
    // MOOD BOARD COLLABORATION EVENTS
    // ========================================
    
    /**
     * Broadcast mood board item event to all board members
     */
    public function publishMoodBoardItemEvent(string $eventType, int $boardId, array $item, array $memberEmails): void
    {
        $payload = [
            'board_id' => $boardId,
            'item' => $item,
        ];
        
        foreach ($memberEmails as $email) {
            $this->publishEvent($email, $eventType, $payload);
        }
    }
    
    /**
     * Broadcast mood board items moved event
     */
    public function publishMoodBoardItemsMoved(int $boardId, array $updates, array $memberEmails): void
    {
        $payload = [
            'board_id' => $boardId,
            'updates' => $updates,
        ];
        
        foreach ($memberEmails as $email) {
            $this->publishEvent($email, 'MOOD_BOARD_ITEMS_MOVED', $payload);
        }
    }
    
    /**
     * Broadcast mood board connection event
     */
    public function publishMoodBoardConnectionEvent(string $eventType, int $boardId, array $connection, array $memberEmails): void
    {
        $payload = [
            'board_id' => $boardId,
            'connection' => $connection,
        ];
        
        foreach ($memberEmails as $email) {
            $this->publishEvent($email, $eventType, $payload);
        }
    }
    
    /**
     * Close Redis connection
     */
    /**
     * Publish an event to a mood-board-level channel so the WS server can
     * relay it to all room subscribers (including unauthenticated guests).
     */
    public function publishMoodBoardRoomEvent(int $boardId, string $eventType, array $payload): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $channel = $this->prefix . 'mood_board:' . $boardId;
            $message = json_encode([
                'type'      => $eventType,
                'payload'   => $payload,
                'timestamp' => round(microtime(true) * 1000),
            ]);
            $this->redis->publish($channel, $message);
            return true;
        } catch (\RedisException $e) {
            error_log("[RedisCacheService] publishMoodBoardRoomEvent error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Publish a message to a Redis channel (for WebSocket broadcasting)
     */
    public function publish(string $channel, string $message): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        
        try {
            $this->redis->publish($channel, $message);
            return true;
        } catch (\RedisException $e) {
            error_log("[RedisCacheService] Publish failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function close(): void
    {
        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (\RedisException $e) {
                // Ignore close errors
            }
            $this->redis = null;
            $this->connected = false;
        }
    }
    
    public function __destruct()
    {
        $this->close();
    }
}

