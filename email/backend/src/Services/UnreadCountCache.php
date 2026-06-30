<?php

namespace Webmail\Services;

/**
 * UnreadCountCache
 *
 * Stores per-user, per-account unread counts in Redis so the
 * GET /accounts/unread-counts endpoint can answer instantly without
 * opening an IMAP / XOAUTH2 connection on every poll.
 *
 * Phase 1 of the OAuth rewrite. The old controller path opened a fresh
 * TLS socket to imap.gmail.com:993 (or the configured IMAP host) for
 * every linked account on every 60-second poll - that's what got the
 * VPS IP banned by CPGuard. With this cache, the endpoint reads a
 * single Redis JSON blob; the actual IMAP traffic moves out to
 * cron/refresh-unread-counts.php which runs at a sane cadence and is
 * exempt from CPGuard rate limits (no public ingress).
 *
 * Cache schema (one entry per user):
 *
 *   Key: webmail:{userHash}:unread
 *   Value: {
 *     "counts": {
 *       "primary":      <int>,        // standard IMAP login mailbox
 *       "imap:<id>":    <int>,        // secondary password-based IMAP account
 *       "google:<id>":  <int>,        // Google OAuth account
 *       "microsoft:<id>": <int>       // Microsoft OAuth account
 *     },
 *     "updated_at": <unix_seconds>,
 *     "stale": false
 *   }
 *   TTL: 10 minutes (so a permanently-stopped cron eventually evicts the cache)
 *
 * The cache is best-effort. If Redis is unavailable, the controller
 * returns whatever counts it can build without opening IMAP (or zeros)
 * rather than firing the old fan-out that caused the ban.
 */
class UnreadCountCache
{
    private RedisCacheService $redis;

    private const TTL_SECONDS = 600;

    public function __construct(array $config, ?RedisCacheService $redis = null)
    {
        $this->redis = $redis ?? new RedisCacheService($config);
    }

    public function isAvailable(): bool
    {
        return $this->redis->isAvailable();
    }

    /**
     * Fetch all cached counts for a user. Returns null if no entry exists.
     *
     * Response shape:
     *   ['counts' => [...], 'updated_at' => int, 'stale' => bool]
     */
    public function get(string $primaryEmail): ?array
    {
        $val = $this->redis->get($this->key($primaryEmail));
        if (!is_array($val)) {
            return null;
        }
        // Tolerate older entries that only carried the counts.
        if (!isset($val['counts']) || !is_array($val['counts'])) {
            return null;
        }
        $updatedAt = (int)($val['updated_at'] ?? 0);
        $age = $updatedAt > 0 ? (time() - $updatedAt) : PHP_INT_MAX;
        return [
            'counts' => $val['counts'],
            'updated_at' => $updatedAt,
            'stale' => $age > self::TTL_SECONDS / 2,
        ];
    }

    /**
     * Replace the entire counts map for a user. Use this from the cron
     * after gathering all per-account counts in one pass.
     *
     * @param array<string,int> $counts account_key => int
     */
    public function setAll(string $primaryEmail, array $counts): bool
    {
        $payload = [
            'counts' => array_map(fn($c) => max(0, (int)$c), $counts),
            'updated_at' => time(),
        ];
        return $this->redis->set($this->key($primaryEmail), $payload, self::TTL_SECONDS);
    }

    /**
     * Update a single account's count in-place. Used by event-driven paths
     * (a daemon push, a controller after a flag change) to keep the
     * sidebar fresh without re-running the whole cron pass.
     */
    public function setOne(string $primaryEmail, string $accountKey, int $count): bool
    {
        $existing = $this->get($primaryEmail);
        $counts = $existing['counts'] ?? [];
        $counts[$accountKey] = max(0, (int)$count);
        return $this->setAll($primaryEmail, $counts);
    }

    public function invalidate(string $primaryEmail): bool
    {
        return $this->redis->delete($this->key($primaryEmail));
    }

    /**
     * Build the canonical account_key for a given account row.
     *
     * Standard IMAP secondary accounts use the int row id; OAuth
     * accounts are prefixed with their provider so the same numeric
     * id from webmail_accounts and webmail_oauth_tokens never collide.
     */
    public static function accountKey(string $kind, int $id = 0): string
    {
        if ($kind === 'primary') return 'primary';
        return $kind . ':' . $id;
    }

    private function key(string $primaryEmail): string
    {
        $hash = $this->redis->getUserHash($primaryEmail);
        return "{$hash}:unread";
    }
}
