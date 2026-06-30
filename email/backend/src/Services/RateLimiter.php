<?php

namespace Webmail\Services;

/**
 * Sliding-window rate limiter (Redis + Lua), shared by ApiRateLimiter and guest-call gates.
 */
class RateLimiter
{
    private ?\Redis $redis = null;
    private bool $connected = false;

    private const LUA_SCRIPT = <<<'LUA'
local key = KEYS[1]
local limit = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local member = ARGV[4]

redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)

local count = redis.call('ZCARD', key)

if count >= limit then
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local retry_after = 1
    if #oldest >= 2 then
        retry_after = math.max(1, math.ceil(tonumber(oldest[2]) + window - now))
    end
    return {0, count, retry_after}
end

redis.call('ZADD', key, now, member)
redis.call('EXPIRE', key, window + 10)
return {1, count + 1, 0}
LUA;

    public function isAvailable(): bool
    {
        return $this->connected && $this->redis !== null;
    }

    public function __construct(array $config)
    {
        $redisConfig = $config['redis'] ?? [];
        try {
            $this->redis = new \Redis();
            $host = $redisConfig['host'] ?? '127.0.0.1';
            $port = (int) ($redisConfig['port'] ?? 6379);
            $timeout = (float) ($redisConfig['timeout'] ?? 2.0);
            if (!$this->redis->connect($host, $port, $timeout)) {
                $this->redis = null;
                return;
            }
            if (!empty($redisConfig['password'])) {
                $this->redis->auth($redisConfig['password']);
            }
            if (isset($redisConfig['database'])) {
                $this->redis->select((int) $redisConfig['database']);
            }
            $this->connected = true;
        } catch (\Throwable $e) {
            error_log('RateLimiter: Redis not available - ' . $e->getMessage());
            $this->redis = null;
            $this->connected = false;
        }
    }

    /**
     * @return array{allowed: bool, retry_after: int, current: int}
     */
    public function allow(string $key, int $maxPerWindow, int $windowSeconds): array
    {
        if (!$this->connected || !$this->redis) {
            return ['allowed' => true, 'retry_after' => 0, 'current' => 0];
        }
        $now = time();
        $member = $now . ':' . mt_rand();
        try {
            $result = $this->redis->eval(
                self::LUA_SCRIPT,
                [$key, (string) $maxPerWindow, (string) $windowSeconds, (string) $now, $member],
                1
            );
            if (is_array($result) && count($result) >= 3 && !(int) $result[0]) {
                return [
                    'allowed' => false,
                    'retry_after' => max(1, (int) $result[2]),
                    'current' => (int) $result[1],
                ];
            }
            return ['allowed' => true, 'retry_after' => 0, 'current' => is_array($result) ? (int) ($result[1] ?? 0) : 0];
        } catch (\Throwable $e) {
            error_log('RateLimiter error: ' . $e->getMessage());
            return ['allowed' => true, 'retry_after' => 0, 'current' => 0];
        }
    }
}
