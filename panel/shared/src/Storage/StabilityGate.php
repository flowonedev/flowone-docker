<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 2 stability gate (invariant I-1).
 *
 * Prevents the status from flapping back to HEALTHY too quickly after
 * a failure. When the classifier wants to promote a previously-failing
 * system, we only allow the promotion if the system has been observed
 * non-failing for at least `min_stable_sec` seconds.
 *
 * State is intentionally tiny — just two timestamps held in memory by
 * the monitor daemon. We do NOT persist this across daemon restarts:
 * a restart starts a fresh stability window (boot epoch bumped, all
 * clients re-read state), which is the conservative behaviour.
 *
 * Wall-clock independent: uses MonotonicClock so a clock jump (NTP,
 * timezone change) cannot incorrectly satisfy the gate.
 */
final class StabilityGate
{
    private int $minStableNs;

    /** First time we observed a non-failing classification after a failure. */
    private ?int $stableSinceMonoNs = null;

    /** Last time we observed any failing classification. */
    private ?int $lastFailureMonoNs = null;

    public function __construct(float $minStableSec)
    {
        $this->minStableNs = (int) round(max(0.0, $minStableSec) * 1_000_000_000);
    }

    public static function fromConfig(array $config): self
    {
        $sec = (float) ($config['stability_gate']['min_stable_sec'] ?? 60);
        return new self($sec);
    }

    /**
     * Record an observation. Call exactly once per probe cycle, BEFORE
     * calling allowPromotion().
     */
    public function observe(string $observedState, ?int $nowMonoNs = null): void
    {
        $now = $nowMonoNs ?? MonotonicClock::nowNs();
        if (self::isFailingState($observedState)) {
            $this->lastFailureMonoNs = $now;
            $this->stableSinceMonoNs = null;
            return;
        }
        if ($this->stableSinceMonoNs === null) {
            $this->stableSinceMonoNs = $now;
        }
    }

    /**
     * Decide whether the classifier is allowed to promote to HEALTHY
     * right now. Caller passes the currently-published state ($from)
     * and the desired new state ($to). Returns true if the transition
     * is permissible under the gate. Other transitions are unaffected
     * (only HEALTHY promotions are gated).
     */
    public function allowPromotion(string $from, string $to, ?int $nowMonoNs = null): bool
    {
        if ($to !== HealthState::HEALTHY) {
            return true;
        }
        // Already healthy — no promotion needed.
        if ($from === HealthState::HEALTHY) {
            return true;
        }
        // No failure observed yet (boot path): allow.
        if ($this->lastFailureMonoNs === null) {
            return true;
        }
        // Require min_stable_sec of continuously non-failing observations.
        if ($this->stableSinceMonoNs === null) {
            return false;
        }
        $now = $nowMonoNs ?? MonotonicClock::nowNs();
        return ($now - $this->stableSinceMonoNs) >= $this->minStableNs;
    }

    /**
     * Diagnostic snapshot, for inclusion in the published payload so
     * operators can see why the gate is/isn't allowing promotion.
     *
     * @return array<string,mixed>
     */
    public function snapshot(?int $nowMonoNs = null): array
    {
        $now = $nowMonoNs ?? MonotonicClock::nowNs();
        $stableForSec = $this->stableSinceMonoNs === null
            ? 0.0
            : max(0.0, ($now - $this->stableSinceMonoNs) / 1_000_000_000);
        return [
            'min_stable_sec'    => $this->minStableNs / 1_000_000_000,
            'stable_for_sec'    => round($stableForSec, 2),
            'satisfied'         => $this->stableSinceMonoNs !== null
                && ($now - $this->stableSinceMonoNs) >= $this->minStableNs,
        ];
    }

    private static function isFailingState(string $state): bool
    {
        return in_array($state, [
            HealthState::READ_ONLY,
            HealthState::OFFLINE,
            HealthState::QUARANTINED,
        ], true);
    }
}
