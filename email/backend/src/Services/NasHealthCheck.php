<?php

namespace Webmail\Services;

use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\StorageHealth;
use Throwable;

/**
 * NasHealthCheck — backwards-compatible facade over FlowOne\Storage\StorageHealth.
 *
 * History: this class used to implement its own multi-layer NAS probe with
 * a Redis kill-switch. As of Phase 1 of the unified storage architecture
 * (see shared/docs/INVARIANTS.md), the authoritative health source is the
 * single privileged `flowone-storage-monitord` daemon. The daemon publishes
 * HMAC-signed JSON state to /var/lib/flowone, which StorageHealth reads.
 *
 * Why this wrapper still exists:
 *   - Many existing callers in DriveService / StorageService / cron scripts
 *     call NasHealthCheck::isAvailable() / ::isNasPath() / ::shouldSkipPath().
 *   - Phase 1 kill switch (config.phases.phase1_shared_health) lets us
 *     fall back to the legacy probe if the daemon hasn't rolled out yet.
 *   - The class will be marked @deprecated in Phase 8/9 cleanup once every
 *     caller has migrated to StorageHealth directly.
 *
 * Invariant I-6 (request path never blocks on storage probes): the shared
 * StorageHealth call is bounded by its per-process cache (<1ms hot path)
 * and the Redis/JSON fallback (<5ms cold). No filesystem probe is ever
 * triggered from a request thread.
 *
 * @deprecated Use FlowOne\Storage\StorageHealth directly in new code.
 */
class NasHealthCheck
{
    private static ?bool $cachedResult = null;
    private static ?StorageHealth $sharedClient = null;

    private const DEFAULT_MOUNT = '/mnt/nas-drive';
    private const HEALTH_FILE   = '.healthcheck';

    private const REDIS_FORCE_OFFLINE_KEY = 'nas:force_offline';
    private const REDIS_STATUS_KEY        = 'nas:status';

    public static function isAvailable(string $mountPoint = self::DEFAULT_MOUNT): bool
    {
        if (self::$cachedResult !== null) {
            return self::$cachedResult;
        }

        // Manual kill switch: legacy Redis key still honoured so ops have
        // a familiar emergency-cut. Use the new freeze flag for the
        // industry-standard equivalent.
        if (self::isForceOffline()) {
            self::$cachedResult = false;
            return false;
        }

        if (self::sharedHealthEnabled()) {
            try {
                $client = self::sharedClient();
                if ($client !== null) {
                    $available = $client->isNasAvailable();
                    self::$cachedResult = $available;
                    return $available;
                }
            } catch (Throwable $e) {
                error_log('[NasHealthCheck] shared StorageHealth unavailable, falling back: ' . $e->getMessage());
                // Fall through to legacy probe so a misconfigured daemon
                // does not take down the email app.
            }
        }

        $result = self::legacyProbe($mountPoint);
        self::$cachedResult = $result;
        return $result;
    }

    public static function isNasPath(string $path, string $mountPoint = self::DEFAULT_MOUNT): bool
    {
        return str_starts_with($path, $mountPoint);
    }

    public static function shouldSkipPath(string $path, string $mountPoint = self::DEFAULT_MOUNT): bool
    {
        return self::isNasPath($path, $mountPoint) && !self::isAvailable($mountPoint);
    }

    public static function reset(): void
    {
        self::$cachedResult = null;
        StorageHealth::resetProcessCache();
    }

    /**
     * Legacy: set the force-offline kill switch. Phase 2 onwards prefer
     * the freeze flag (storage-ctl freeze) which is the canonical pause.
     * This method is preserved so existing watchdog crons keep working.
     */
    public static function setForceOffline(array $config, int $ttlSeconds = 300): void
    {
        try {
            $redis = new RedisCacheService($config);
            $redis->set(self::REDIS_FORCE_OFFLINE_KEY, '1', $ttlSeconds);
        } catch (Throwable $e) {
            error_log('[NasHealthCheck] setForceOffline failed: ' . $e->getMessage());
        }
    }

    public static function clearForceOffline(array $config): void
    {
        try {
            $redis = new RedisCacheService($config);
            $redis->delete(self::REDIS_FORCE_OFFLINE_KEY);
            $redis->delete(self::REDIS_STATUS_KEY);
        } catch (Throwable $e) {
            error_log('[NasHealthCheck] clearForceOffline failed: ' . $e->getMessage());
        }
    }

    private static function sharedHealthEnabled(): bool
    {
        try {
            return (bool) StorageConfig::get('phases.phase1_shared_health', true);
        } catch (Throwable) {
            return false;
        }
    }

    private static function sharedClient(): ?StorageHealth
    {
        if (self::$sharedClient !== null) {
            return self::$sharedClient;
        }
        try {
            // Wire Redis if available so reads stay on the hot path.
            $redis = self::getRawRedis();
            self::$sharedClient = StorageHealth::fromConfig($redis);
            return self::$sharedClient;
        } catch (Throwable $e) {
            error_log('[NasHealthCheck] could not build StorageHealth: ' . $e->getMessage());
            return null;
        }
    }

    private static function getRawRedis(): ?\Redis
    {
        try {
            $configPath = __DIR__ . '/../config.php';
            if (!file_exists($configPath)) {
                return null;
            }
            // config.php handles the config.local.php override merge
            // internally, so no separate merge is needed here.
            $config = require $configPath;
            if (!class_exists(\Redis::class)) {
                return null;
            }
            $r = new \Redis();
            $host = $config['redis']['host'] ?? '127.0.0.1';
            $port = (int) ($config['redis']['port'] ?? 6379);
            if (!$r->connect($host, $port, (float) ($config['redis']['timeout'] ?? 2.0))) {
                return null;
            }
            $password = $config['redis']['password'] ?? null;
            if ($password && !$r->auth($password)) {
                return null;
            }
            if ((int) ($config['redis']['database'] ?? 0) > 0) {
                $r->select((int) $config['redis']['database']);
            }
            return $r;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isForceOffline(): bool
    {
        try {
            $redis = self::getRawRedis();
            if ($redis === null) return false;
            $val = $redis->get('flowone:storage:nas:force_offline')
                ?: $redis->get(self::REDIS_FORCE_OFFLINE_KEY);
            return $val === '1' || $val === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Legacy in-process probe. Only invoked when the shared daemon is
     * unavailable. Kept here so the rollout has a clean fallback.
     */
    private static function legacyProbe(string $mountPoint): bool
    {
        $prevLimit = (int) ini_get('max_execution_time');
        set_time_limit(5);

        $start  = microtime(true);
        $exists = @file_exists($mountPoint . '/' . self::HEALTH_FILE);
        $elapsed = microtime(true) - $start;

        if ($exists) {
            $writeTest = $mountPoint . '/.nas_write_probe_' . getmypid();
            $writeOk = @file_put_contents($writeTest, time());
            if ($writeOk !== false) {
                @unlink($writeTest);
            } else {
                error_log("[NasHealthCheck:legacy] write probe failed on {$mountPoint}");
                $exists = false;
            }
        }

        set_time_limit($prevLimit ?: 0);

        if ($elapsed > 2.0) {
            error_log("[NasHealthCheck:legacy] probe took {$elapsed}s — treating NAS as unavailable");
            $exists = false;
        }
        return $exists;
    }
}
