<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

/**
 * The reconciler's per-site verdict.
 *
 * Outcomes (one of):
 *   - HEALTHY      : reality matches desired state; nothing to do.
 *   - SKIPPED      : caller-declared reason for not reconciling
 *                    (e.g. already a queued/running job, or unevaluated
 *                    subsystems made the picture too unclear).
 *   - RECONCILE    : drift detected and the assessor recommends
 *                    re-running the CREATE saga (idempotent steps will
 *                    refill missing parts).
 *   - DEGRADE_ONLY : drift detected but the assessor refuses to
 *                    auto-remediate (e.g. desired_state=deleted but
 *                    artifacts still present, or out-of-band manual
 *                    changes that would be clobbered).
 *
 * Severity is independent of recommendation: an ssl-only mismatch is
 * RECONCILE/low; missing home dir is RECONCILE/high.
 */
final class DriftAssessment
{
    public const RECOMMEND_HEALTHY = 'healthy';
    public const RECOMMEND_SKIP = 'skip';
    public const RECOMMEND_RECONCILE = 'reconcile';
    public const RECOMMEND_DEGRADE = 'degrade';

    public const SEVERITY_NONE = 'none';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';

    /**
     * @param list<string> $reasons    Human-readable explanations for the
     *                                 recommendation (each is a bullet).
     * @param list<string> $missing    Subsystems probed as missing.
     * @param list<string> $unevaluated Subsystems the prober could not
     *                                  inspect (incomplete data).
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $recommendation,
        public readonly string $severity,
        public readonly array $reasons,
        public readonly array $missing,
        public readonly array $unevaluated,
        public readonly ?string $skipReason = null,
        public readonly float $assessedAtUnix = 0.0
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->recommendation === self::RECOMMEND_HEALTHY;
    }

    public function needsReconcile(): bool
    {
        return $this->recommendation === self::RECOMMEND_RECONCILE;
    }

    public function needsDegrade(): bool
    {
        return $this->recommendation === self::RECOMMEND_DEGRADE;
    }

    public function wasSkipped(): bool
    {
        return $this->recommendation === self::RECOMMEND_SKIP;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'recommendation' => $this->recommendation,
            'severity' => $this->severity,
            'reasons' => $this->reasons,
            'missing' => $this->missing,
            'unevaluated' => $this->unevaluated,
            'skip_reason' => $this->skipReason,
            'assessed_at_unix' => $this->assessedAtUnix,
        ];
    }
}
