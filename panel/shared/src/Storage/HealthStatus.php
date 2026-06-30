<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Immutable value object representing the daemon's published health state.
 *
 * Wraps the (unwrapped, HMAC-verified) payload that StorageHealth returns
 * to callers. Phase 1 only uses three status values; Phase 2 introduces
 * the full 6-state enum. Keeping a single class lets Phase 2 extend
 * without breaking any Phase 1 consumers.
 *
 * Source values:
 *   - 'current'  — fresh signed JSON from /var/lib/flowone/storage-health.json
 *   - 'backup'   — fell back to .bak (current missing/corrupt)
 *   - 'redis'    — read from Redis cache (latency win, still signed + verified)
 *   - 'default'  — hard-coded safe default (no daemon, no Redis, no JSON)
 */
final class HealthStatus
{
    /** Status known in Phase 1. */
    public const STATUS_HEALTHY  = HealthState::HEALTHY;
    public const STATUS_DEGRADED = HealthState::DEGRADED;
    public const STATUS_OFFLINE  = HealthState::OFFLINE;
    public const STATUS_UNKNOWN  = HealthState::UNKNOWN;
    /** Additional states introduced in Phase 2. */
    public const STATUS_READ_ONLY   = HealthState::READ_ONLY;
    public const STATUS_QUARANTINED = HealthState::QUARANTINED;
    public const STATUS_FROZEN      = HealthState::FROZEN;

    /**
     * @param array<string,mixed> $checks
     * @param array<string,mixed> $phase2  optional Phase 2 block (gate + breakers)
     * @param array<string,mixed> $autoRecovery  optional auto-recovery block
     *        published by the daemon's RecoveryOrchestrator
     *        ({attempted, action, success, breaker}).
     */
    public function __construct(
        public readonly string $status,
        public readonly int $bootEpoch,
        public readonly int $generation,
        public readonly int $publishedAtUnix,
        public readonly string $source,
        public readonly bool $isStale,
        public readonly ?string $rootCause = null,
        public readonly ?string $rootCauseDetail = null,
        public readonly array $checks = [],
        public readonly float $observedAgeSec = 0.0,
        public readonly array $phase2 = [],
        public readonly array $autoRecovery = [],
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    public function isDegraded(): bool
    {
        return $this->status === self::STATUS_DEGRADED;
    }

    public function isOffline(): bool
    {
        return $this->status === self::STATUS_OFFLINE;
    }

    public function isUnknown(): bool
    {
        return $this->status === self::STATUS_UNKNOWN;
    }

    public function isReadOnly(): bool
    {
        return $this->status === self::STATUS_READ_ONLY;
    }

    public function isQuarantined(): bool
    {
        return $this->status === self::STATUS_QUARANTINED;
    }

    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_FROZEN;
    }

    /**
     * True when reads can be attempted against the NAS. Mirrors
     * HealthState::isReadable() for any caller that already has a
     * HealthStatus in hand.
     */
    public function isReadable(): bool
    {
        return HealthState::isReadable($this->status);
    }

    /**
     * True when writes can be attempted against the NAS.
     */
    public function isWritable(): bool
    {
        return HealthState::isWritable($this->status);
    }

    /**
     * Render to associative array (for JSON responses, logs, etc.).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'status'           => $this->status,
            'boot_epoch'       => $this->bootEpoch,
            'generation'       => $this->generation,
            'published_at'     => $this->publishedAtUnix,
            'observed_age_sec' => $this->observedAgeSec,
            'source'           => $this->source,
            'is_stale'         => $this->isStale,
            'root_cause'       => $this->rootCause,
            'root_cause_detail'=> $this->rootCauseDetail,
            'checks'           => $this->checks,
        ];
        if (!empty($this->phase2)) {
            $out['phase2'] = $this->phase2;
        }
        if (!empty($this->autoRecovery)) {
            $out['auto_recovery'] = $this->autoRecovery;
        }
        return $out;
    }

    /**
     * Build from the unwrapped payload (i.e. the contents of the "payload"
     * envelope after HMAC verification). $source records where it came from.
     *
     * If the payload is malformed, returns a STATUS_UNKNOWN fallback. The
     * intent is that callers always get a usable HealthStatus, never a
     * thrown exception.
     *
     * @param array<string,mixed> $payload
     */
    public static function fromPayload(array $payload, string $source, ?int $expectedBootEpoch = null): self
    {
        $status = isset($payload['status']) && is_string($payload['status'])
            ? $payload['status']
            : self::STATUS_UNKNOWN;

        $bootEpoch = isset($payload['boot_epoch']) ? (int) $payload['boot_epoch'] : 0;
        $generation = isset($payload['generation']) ? (int) $payload['generation'] : 0;
        $publishedAt = isset($payload['published_at']) ? (int) $payload['published_at'] : 0;
        $checks = isset($payload['checks']) && is_array($payload['checks']) ? $payload['checks'] : [];
        $rootCause = isset($payload['root_cause']) && is_string($payload['root_cause']) ? $payload['root_cause'] : null;
        $rootCauseDetail = isset($payload['root_cause_detail']) && is_string($payload['root_cause_detail']) ? $payload['root_cause_detail'] : null;
        $phase2 = isset($payload['phase2']) && is_array($payload['phase2']) ? $payload['phase2'] : [];
        $autoRecovery = isset($payload['auto_recovery']) && is_array($payload['auto_recovery']) ? $payload['auto_recovery'] : [];

        $age = $publishedAt > 0 ? max(0.0, (float) (time() - $publishedAt)) : 0.0;

        $isStale = $source === 'backup'
            || $source === 'default'
            || $age > 120
            || ($expectedBootEpoch !== null && $expectedBootEpoch !== $bootEpoch && $expectedBootEpoch !== 0);

        return new self(
            status: $status,
            bootEpoch: $bootEpoch,
            generation: $generation,
            publishedAtUnix: $publishedAt,
            source: $source,
            isStale: $isStale,
            rootCause: $rootCause,
            rootCauseDetail: $rootCauseDetail,
            checks: $checks,
            observedAgeSec: $age,
            phase2: $phase2,
            autoRecovery: $autoRecovery,
        );
    }

    /**
     * Hard-coded safe default for when no source is available. Returns
     * STATUS_UNKNOWN so callers know not to make destructive decisions.
     */
    public static function safeDefault(): self
    {
        return new self(
            status: self::STATUS_UNKNOWN,
            bootEpoch: 0,
            generation: 0,
            publishedAtUnix: 0,
            source: 'default',
            isStale: true,
            rootCause: 'no_state_available',
            rootCauseDetail: 'No daemon state, Redis cache, or backup file present',
            checks: [],
            observedAgeSec: 0.0,
        );
    }
}
