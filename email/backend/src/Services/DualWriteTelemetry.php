<?php

namespace Webmail\Services;

/**
 * DualWriteTelemetry - post-cutover regression guard.
 *
 * Pre-cutover this class also tracked dual-write counters (legacy_reads,
 * legacy_writes, dual_writes, legacy_route_hits, canonical_route_hits)
 * that gated the schema cutover. Those code paths are gone; the columns
 * they tracked do not exist anymore and the routes they tracked have
 * been removed. Only the compare-mode regression guard remains.
 *
 * The compare-mode resolver runs (sampled) on every folder-scoped
 * request. It looks up the folder by both `folder_id` and the path
 * string and reports any divergence as a regression -- code MUST never
 * disagree on what folder a path-or-id refers to. A non-zero
 * `dual_resolve_divergences_24h` is a paging-worthy alert.
 *
 * In addition this service still owns `folder_identity_version`, a
 * per-account monotonic counter the frontend consults to invalidate
 * folder-level caches when a rename, namespace move, or delimiter
 * change is detected.
 */
final class DualWriteTelemetry
{
    public const KEY_RESOLVE_OK_24H            = 'telemetry:dual_write:dual_resolve_ok_24h';
    public const KEY_RESOLVE_DIVERGENCES_24H   = 'telemetry:dual_write:dual_resolve_divergences_24h';
    public const KEY_RESOLVE_PARTIAL_24H       = 'telemetry:dual_write:dual_resolve_partial_24h';
    public const KEY_RESOLVE_SAMPLES_24H       = 'telemetry:dual_write:dual_resolve_samples_24h';

    /** 24h + 5 min of slop for the readiness cron. */
    private const COUNTER_TTL = 87300;

    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Record the outcome of a sampled compare-mode resolve. The
     * `$status` argument matches FolderInputResolver::compareResolve()'s
     * return key:
     *   - 'ok'              both lookups agreed
     *   - 'identity_drift'  THE bug we MUST keep at zero
     *   - 'partial'         one side returned nothing (recoverable)
     *   - 'skipped'         input was insufficient -- not a sample
     *
     * The `samples` counter ticks on every status EXCEPT 'skipped' so
     * the readiness cron can compute a divergence ratio
     * (divergences / samples) without needing to know the sample rate.
     *
     * Always bumps `samples` first, then exactly one outcome counter.
     * `$source` is an endpoint-family tag (e.g. 'messages_list',
     * 'message_get').
     */
    public function recordResolveCompare(string $status, string $source = 'unknown'): void
    {
        if ($status === 'skipped') {
            return;
        }

        $this->bump(self::KEY_RESOLVE_SAMPLES_24H);

        switch ($status) {
            case 'ok':
                $this->bump(self::KEY_RESOLVE_OK_24H);
                break;
            case 'identity_drift':
                $this->bump(self::KEY_RESOLVE_DIVERGENCES_24H);
                StructuredLog::emit('dual_resolve_divergence', [
                    'source' => $source,
                ]);
                break;
            case 'partial':
                $this->bump(self::KEY_RESOLVE_PARTIAL_24H);
                StructuredLog::emit('dual_resolve_partial', [
                    'source' => $source,
                ]);
                break;
        }
    }

    /**
     * Bump the per-account folder identity version. The frontend watches
     * this counter and invalidates folder caches when it advances.
     *
     * Returns the new version (best-effort: 0 if Redis is unavailable).
     */
    public function bumpFolderIdentityVersion(string $accountId): int
    {
        if (!$this->redis->isAvailable()) {
            return 0;
        }
        $key = $this->folderIdentityVersionKey($accountId);
        $val = $this->redis->increment($key);

        // Restart-safe baseline. The frontend treats this counter as strictly
        // monotonic (it only invalidates when remote > local). If Redis is
        // flushed/evicted, increment() recreates the key at 1 -- BELOW any
        // value the client already saw -- which would silently suppress all
        // future folder-cache invalidations until the counter clawed back
        // above the last-seen value. When we detect that fresh-key case
        // (val === 1) we reseed to a wall-clock baseline: time() only ever
        // advances, and renames (the only bump source) are far rarer than one
        // per second, so the counter stays monotonic across restarts.
        if ($val === 1) {
            $baseline = time();
            $this->redis->set($key, $baseline); // no TTL: this must persist
            return $baseline;
        }
        return is_int($val) ? $val : 0;
    }

    /**
     * Read the current folder identity version. Returns 0 if Redis is
     * down (callers must treat this as "unknown, do not optimise").
     */
    public function getFolderIdentityVersion(string $accountId): int
    {
        if (!$this->redis->isAvailable()) {
            return 0;
        }
        $val = $this->redis->get($this->folderIdentityVersionKey($accountId));
        if (is_array($val) && isset($val['value'])) {
            $val = $val['value'];
        }
        return is_numeric($val) ? (int) $val : 0;
    }

    private function folderIdentityVersionKey(string $accountId): string
    {
        return 'account:' . strtolower($accountId) . ':folder_identity_version';
    }

    private function bump(string $key): void
    {
        if (!$this->redis->isAvailable()) {
            return;
        }
        try {
            $val = $this->redis->increment($key);
            if ($val === 1) {
                $this->redis->expire($key, self::COUNTER_TTL);
            }
        } catch (\Throwable $e) {
            // Silent no-op. Telemetry must never break callers.
        }
    }
}
