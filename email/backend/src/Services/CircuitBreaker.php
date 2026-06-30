<?php

namespace Webmail\Services;

/**
 * Per-folder circuit breaker for All Mail / mailbox scans.
 *
 * Wraps a Redis sorted set of failure timestamps per
 * (account_id, folder_path). When five failures occur within a
 * ten-minute sliding window the folder is quarantined for fifteen
 * minutes (with +/-10% jitter to prevent reconnect storms across
 * accounts after a shared upstream blip). Subsequent failures while
 * quarantined apply exponential backoff capped at four hours.
 *
 * Design notes:
 *   - All Redis ops are best-effort. If Redis is unavailable the
 *     breaker opens nothing and behaves as fail-open. Worst case we
 *     hammer a broken folder a little harder, but we never block real
 *     fetches because of a missing cache layer.
 *   - Keys live under the existing webmail: prefix so they show up
 *     alongside the rest of the cache and inherit eviction policy.
 */
final class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';

    /** Failure-window length in seconds. */
    private const WINDOW_SECONDS = 600; // 10 min

    /** Number of failures within the window that trip the breaker. */
    private const TRIP_THRESHOLD = 5;

    /** Base cooldown values in seconds for successive trips of the same folder. */
    private const COOLDOWN_LADDER = [900, 1800, 3600, 14400]; // 15m, 30m, 60m, 4h

    /** Maximum jitter +/- as a fraction of the cooldown (0.10 = +/-10%). */
    private const JITTER_FRACTION = 0.10;

    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Build the canonical key for a folder.
     */
    public static function keyFor(string $accountKey, string $folderPath): string
    {
        $folder = self::normalizePath($folderPath);
        return "circuit:{$accountKey}:{$folder}";
    }

    /**
     * Record one failure event. Returns the current state after recording.
     *
     * @return array{state:string, retry_after:?int, failure_count:int, cooldown_seconds:int}
     */
    public function recordFailure(string $accountKey, string $folderPath, int $now = 0): array
    {
        $now = $now ?: time();
        $failKey = $this->failKey($accountKey, $folderPath);
        $stateKey = $this->stateKey($accountKey, $folderPath);
        $tripKey = $this->tripCountKey($accountKey, $folderPath);

        if (!$this->redis->isAvailable()) {
            return [
                'state' => self::STATE_CLOSED,
                'retry_after' => null,
                'failure_count' => 0,
                'cooldown_seconds' => 0,
            ];
        }

        try {
            // Slide the window: drop entries older than WINDOW_SECONDS.
            $windowStart = $now - self::WINDOW_SECONDS;
            $this->redis->zRemRangeByScore($failKey, 0, $windowStart);

            // Add this failure. Use a unique member to avoid collisions when
            // two failures land in the same second.
            $member = $now . '_' . bin2hex(random_bytes(3));
            $this->redis->zAdd($failKey, $now, $member);
            $this->redis->expire($failKey, self::WINDOW_SECONDS * 2);

            $count = (int) $this->redis->zCard($failKey);
            if ($count < self::TRIP_THRESHOLD) {
                return [
                    'state' => self::STATE_CLOSED,
                    'retry_after' => null,
                    'failure_count' => $count,
                    'cooldown_seconds' => 0,
                ];
            }

            // Trip. Pick a cooldown from the ladder based on how many trips
            // this folder has had recently, then apply +/-10% jitter.
            $tripCount = (int) ($this->redis->increment($tripKey) ?: 1);
            $this->redis->expire($tripKey, max(self::COOLDOWN_LADDER) * 2);

            $base = self::COOLDOWN_LADDER[min($tripCount - 1, count(self::COOLDOWN_LADDER) - 1)];
            $cooldown = $this->withJitter($base);
            $retryAfter = $now + $cooldown;

            $this->redis->set($stateKey, json_encode([
                'state' => self::STATE_OPEN,
                'retry_after' => $retryAfter,
                'opened_at' => $now,
                'cooldown_seconds' => $cooldown,
            ]), $cooldown + 60);

            return [
                'state' => self::STATE_OPEN,
                'retry_after' => $retryAfter,
                'failure_count' => $count,
                'cooldown_seconds' => $cooldown,
            ];
        } catch (\Throwable $e) {
            error_log('[CircuitBreaker] recordFailure error: ' . $e->getMessage());
            return [
                'state' => self::STATE_CLOSED,
                'retry_after' => null,
                'failure_count' => 0,
                'cooldown_seconds' => 0,
            ];
        }
    }

    /**
     * Mark a folder healthy. Closes the breaker and resets the trip counter.
     */
    public function recordSuccess(string $accountKey, string $folderPath): void
    {
        if (!$this->redis->isAvailable()) {
            return;
        }
        try {
            $this->redis->delete($this->failKey($accountKey, $folderPath));
            $this->redis->delete($this->stateKey($accountKey, $folderPath));
            $this->redis->delete($this->tripCountKey($accountKey, $folderPath));
        } catch (\Throwable $e) {
            error_log('[CircuitBreaker] recordSuccess error: ' . $e->getMessage());
        }
    }

    /**
     * Get the current state for a folder. Returns ['state', 'retry_after', 'failure_count'].
     *
     * @return array{state:string, retry_after:?int, failure_count:int, cooldown_seconds:int}
     */
    public function inspect(string $accountKey, string $folderPath, int $now = 0): array
    {
        $now = $now ?: time();
        $closed = [
            'state' => self::STATE_CLOSED,
            'retry_after' => null,
            'failure_count' => 0,
            'cooldown_seconds' => 0,
        ];
        if (!$this->redis->isAvailable()) {
            return $closed;
        }

        try {
            $raw = $this->redis->get($this->stateKey($accountKey, $folderPath));
            if (is_array($raw) && ($raw['state'] ?? '') === self::STATE_OPEN) {
                $retryAfter = (int) ($raw['retry_after'] ?? 0);
                if ($retryAfter > $now) {
                    return [
                        'state' => self::STATE_OPEN,
                        'retry_after' => $retryAfter,
                        'failure_count' => (int) ($this->redis->zCard($this->failKey($accountKey, $folderPath)) ?: 0),
                        'cooldown_seconds' => (int) ($raw['cooldown_seconds'] ?? 0),
                    ];
                }
                $this->redis->delete($this->stateKey($accountKey, $folderPath));
            }

            $count = (int) ($this->redis->zCard($this->failKey($accountKey, $folderPath)) ?: 0);
            return [
                'state' => self::STATE_CLOSED,
                'retry_after' => null,
                'failure_count' => $count,
                'cooldown_seconds' => 0,
            ];
        } catch (\Throwable $e) {
            error_log('[CircuitBreaker] inspect error: ' . $e->getMessage());
            return $closed;
        }
    }

    /**
     * Convenience: should this folder be skipped right now?
     */
    public function isOpen(string $accountKey, string $folderPath, int $now = 0): bool
    {
        return $this->inspect($accountKey, $folderPath, $now)['state'] === self::STATE_OPEN;
    }

    /**
     * Apply +/- JITTER_FRACTION to the supplied cooldown.
     */
    private function withJitter(int $base): int
    {
        $delta = $base * self::JITTER_FRACTION;
        $offset = (mt_rand() / mt_getrandmax()) * (2 * $delta) - $delta;
        return max(1, (int) round($base + $offset));
    }

    private function failKey(string $accountKey, string $folderPath): string
    {
        return 'circuit:fails:' . $accountKey . ':' . self::normalizePath($folderPath);
    }

    private function stateKey(string $accountKey, string $folderPath): string
    {
        return 'circuit:state:' . $accountKey . ':' . self::normalizePath($folderPath);
    }

    private function tripCountKey(string $accountKey, string $folderPath): string
    {
        return 'circuit:trips:' . $accountKey . ':' . self::normalizePath($folderPath);
    }

    /**
     * Normalize a folder path so equivalent paths share a key.
     * Lowercased, whitespace-trimmed, slashes collapsed.
     */
    public static function normalizePath(string $path): string
    {
        $p = trim($path);
        $p = preg_replace('#//+#', '/', $p) ?? $p;
        return mb_strtolower($p);
    }
}
