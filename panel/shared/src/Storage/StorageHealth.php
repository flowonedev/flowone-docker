<?php

declare(strict_types=1);

namespace FlowOne\Storage;

use Redis;
use RedisException;

/**
 * Consumer client for the storage daemon's published health state.
 *
 * Every consumer in the system (email backend, panel API, panel agent,
 * crons, daemons) reads health through this class. NEVER probe NFS or
 * VPN directly from a request thread (invariants I-6, I-8) — call
 * StorageHealth and trust the daemon.
 *
 * Read pipeline (returns the first source that produces a valid,
 * HMAC-verified payload):
 *
 *   1. Per-process static cache    (microseconds; refreshed every {ttl}s)
 *   2. Redis cache                 (single round-trip; signed payload)
 *   3. Authoritative JSON current  (disk read; signed payload)
 *   4. Authoritative JSON backup   (disk read; signed; marks is_stale)
 *   5. Hard-coded safe default     (STATUS_UNKNOWN; never blocks decisions)
 *
 * Any failed signature, malformed payload, or stale generation is
 * silently discarded and the next source is consulted. The structured
 * log record is written via OperationJournal when in daemon contexts.
 *
 * Per-request hot path: getStatus() should never exceed 1 ms in the
 * common case (per-process cache hit). Redis fall-through is ~1 ms.
 * Disk fall-through is ~5 ms. Default is instant.
 */
final class StorageHealth implements HealthStatusProvider
{
    private const PROCESS_CACHE_TTL_SEC = 5.0;

    private static ?HealthStatus $processCache = null;
    private static float $processCachedAtMonotonic = 0.0;

    public function __construct(
        private HmacSigner $signer,
        private DurableJson $stateFile,
        private BootEpoch $bootEpoch,
        private ?Redis $redis = null,
        private string $redisStatusKey = 'flowone:storage:status',
        private int $redisStatusTtl = 60,
    ) {}

    /**
     * Build a StorageHealth from the shared config. Wires Redis lazily.
     */
    public static function fromConfig(?Redis $redis = null): self
    {
        $config = Config::load();

        $signer = HmacSigner::fromKeyFile(
            (string) $config['state']['hmac_key_path'],
            (int) $config['state']['hmac_key_mode_max']
        );
        $stateFile = new DurableJson(
            (string) $config['state']['dir'],
            (string) $config['state']['current_file'],
            (string) $config['state']['tmp_suffix'],
            (string) $config['state']['bak_suffix'],
        );
        $bootEpoch = new BootEpoch(
            rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['boot_epoch_file']
        );
        $statusKey = trim((string) $config['redis']['prefix'], ':') . ':' . (string) $config['redis']['status_key'];

        return new self(
            signer: $signer,
            stateFile: $stateFile,
            bootEpoch: $bootEpoch,
            redis: $redis,
            redisStatusKey: $statusKey,
            redisStatusTtl: (int) $config['redis']['status_ttl_sec'],
        );
    }

    /**
     * Return the current health status. Always returns a HealthStatus —
     * never throws on unavailability. Use isHealthy()/isOffline()/isUnknown()
     * to branch.
     */
    public function getStatus(): HealthStatus
    {
        $now = MonotonicClock::nowSec();
        if (self::$processCache !== null && ($now - self::$processCachedAtMonotonic) < self::PROCESS_CACHE_TTL_SEC) {
            return self::$processCache;
        }

        $expectedEpoch = $this->bootEpoch->current();

        $status = $this->readFromRedis($expectedEpoch)
            ?? $this->readFromState($expectedEpoch)
            ?? HealthStatus::safeDefault();

        self::$processCache = $status;
        self::$processCachedAtMonotonic = $now;
        return $status;
    }

    /**
     * Refresh the per-process cache on the next call. Tests + chaos use this.
     */
    public static function resetProcessCache(): void
    {
        self::$processCache = null;
        self::$processCachedAtMonotonic = 0.0;
    }

    /**
     * Backwards-compatible boolean check used by legacy NasHealthCheck callers
     * (invariant I-6 — never blocks on a real probe).
     */
    public function isNasAvailable(): bool
    {
        $status = $this->getStatus();
        return $status->isHealthy();
    }

    /**
     * Returns true if Drive operations should treat NAS-resident files as
     * inaccessible right now. Equivalent to !isNasAvailable() but spelled
     * out for caller clarity at call sites.
     */
    public function shouldSkipNasReads(): bool
    {
        return !$this->isNasAvailable();
    }

    private function readFromRedis(int $expectedEpoch): ?HealthStatus
    {
        if ($this->redis === null) {
            return null;
        }
        try {
            $raw = $this->redis->get($this->redisStatusKey);
        } catch (RedisException $e) {
            error_log('[StorageHealth] redis get failed: ' . $e->getMessage());
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $verified = $this->signer->verifyJson($raw);
        if ($verified === null) {
            // I-9: silently fall through; Redis poisoning is the threat we
            // are defending against here.
            error_log('[StorageHealth] redis payload HMAC invalid; falling through');
            return null;
        }
        return HealthStatus::fromPayload($verified, 'redis', $expectedEpoch);
    }

    private function readFromState(int $expectedEpoch): ?HealthStatus
    {
        [$raw, $whichFile] = $this->stateFile->readAny();
        if ($raw === null) {
            return null;
        }
        $verified = $this->signer->verifyJson($raw);
        if ($verified === null) {
            error_log('[StorageHealth] state file ' . $whichFile . ' HMAC invalid');
            // Try the backup if we just failed on current.
            if ($whichFile === 'current') {
                $bakRaw = $this->stateFile->readBackup();
                if ($bakRaw !== null) {
                    $verified = $this->signer->verifyJson($bakRaw);
                    if ($verified !== null) {
                        return HealthStatus::fromPayload($verified, 'backup', $expectedEpoch);
                    }
                }
            }
            return null;
        }
        $source = $whichFile === 'backup' ? 'backup' : 'current';
        return HealthStatus::fromPayload($verified, $source, $expectedEpoch);
    }
}
