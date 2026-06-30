<?php

declare(strict_types=1);

namespace FlowOne\Storage\Breakers;

use FlowOne\Storage\DurableJson;
use FlowOne\Storage\HmacSigner;

/**
 * NAS recovery circuit breaker (Phase 2).
 *
 * Bounds how aggressively the system tries to auto-recover from NAS
 * failures. Three-tier escalation:
 *
 *   Tier 1 (free attempts):
 *     Up to `attempts_per_quarantine` recovery attempts in the current
 *     quarantine cycle.
 *
 *   Tier 2 (quarantine):
 *     When tier 1 is exhausted, refuse further attempts for
 *     `quarantine_window_sec`. Counter for tier 3 is incremented.
 *
 *   Tier 3 (permanent quarantine):
 *     After `quarantines_before_permanent` quarantines within
 *     `permanent_window_sec`, the breaker enters a permanent state
 *     that requires explicit operator intervention to clear.
 *
 * Configuration lives in storage.php under `recovery_breaker`.
 *
 * State persists across daemon restarts so a crash loop cannot reset
 * the counter and DoS the NAS.
 *
 * NOTE: Phase 2 ships the breaker API (record + query). The actual
 * "attempt a recovery" caller lands in Phase 5 along with the tier-down
 * worker; until then, this just provides the policy hooks.
 */
final class RecoveryBreaker
{
    private const STATE_FILE = 'recovery-breaker.json';

    private int $attemptsPerQuarantine;
    private int $quarantineWindowSec;
    private int $quarantinesBeforePermanent;
    private int $permanentWindowSec;

    /** Number of attempts inside the current quarantine cycle. */
    private int $cycleAttempts = 0;

    /** Unix timestamp until which we are quarantined; null when not. */
    private ?int $quarantinedUntilUnix = null;

    /** Recent quarantine timestamps (unix), for tier-3 escalation. */
    /** @var list<int> */
    private array $recentQuarantines = [];

    private bool $permanent = false;

    private DurableJson $persistence;
    private HmacSigner  $signer;

    public function __construct(
        DurableJson $persistence,
        HmacSigner  $signer,
        int $attemptsPerQuarantine,
        int $quarantineWindowSec,
        int $quarantinesBeforePermanent,
        int $permanentWindowSec
    ) {
        $this->persistence                = $persistence;
        $this->signer                     = $signer;
        $this->attemptsPerQuarantine      = max(1, $attemptsPerQuarantine);
        $this->quarantineWindowSec        = max(1, $quarantineWindowSec);
        $this->quarantinesBeforePermanent = max(1, $quarantinesBeforePermanent);
        $this->permanentWindowSec         = max(1, $permanentWindowSec);

        $this->loadPersistedState();
    }

    public static function fromConfig(array $config): self
    {
        $signer = HmacSigner::fromKeyFile(
            (string) $config['state']['hmac_key_path'],
            (int) $config['state']['hmac_key_mode_max']
        );
        $persistence = new DurableJson(
            (string) $config['state']['dir'],
            self::STATE_FILE,
            (string) $config['state']['tmp_suffix'],
            (string) $config['state']['bak_suffix'],
        );
        return new self(
            $persistence,
            $signer,
            (int) $config['recovery_breaker']['attempts_per_quarantine'],
            (int) $config['recovery_breaker']['quarantine_window_sec'],
            (int) $config['recovery_breaker']['quarantines_before_permanent'],
            (int) $config['recovery_breaker']['permanent_window_sec'],
        );
    }

    /**
     * May the next recovery attempt run? Caller MUST honour the answer;
     * the breaker does not prevent invocation by itself.
     */
    public function canAttempt(?int $nowUnix = null): bool
    {
        $now = $nowUnix ?? time();
        if ($this->permanent) {
            return false;
        }
        if ($this->quarantinedUntilUnix !== null && $now < $this->quarantinedUntilUnix) {
            return false;
        }
        if ($this->quarantinedUntilUnix !== null && $now >= $this->quarantinedUntilUnix) {
            // Quarantine elapsed — reset cycle attempts for fresh tier-1 budget.
            $this->quarantinedUntilUnix = null;
            $this->cycleAttempts = 0;
            $this->persist();
        }
        return true;
    }

    /**
     * Record that the caller initiated a recovery attempt. Returns the
     * effective remaining budget after the increment.
     */
    public function recordAttempt(?int $nowUnix = null): int
    {
        $this->cycleAttempts++;
        $this->persist();
        return max(0, $this->attemptsPerQuarantine - $this->cycleAttempts);
    }

    /**
     * Recovery succeeded — clears the cycle counter. Quarantine history
     * is preserved for tier-3 escalation logic.
     */
    public function recordSuccess(?int $nowUnix = null): void
    {
        $this->cycleAttempts = 0;
        $this->quarantinedUntilUnix = null;
        $this->persist();
    }

    /**
     * Recovery attempt failed. If this exhausts the per-cycle budget,
     * we enter quarantine; if that's the Nth quarantine in the long
     * window, escalate to permanent.
     */
    public function recordFailure(?int $nowUnix = null): void
    {
        $now = $nowUnix ?? time();
        if ($this->cycleAttempts >= $this->attemptsPerQuarantine) {
            $this->quarantinedUntilUnix = $now + $this->quarantineWindowSec;
            $this->recentQuarantines[] = $now;
            $this->trimQuarantineHistory($now);
            if (count($this->recentQuarantines) >= $this->quarantinesBeforePermanent) {
                $this->permanent = true;
            }
        }
        $this->persist();
    }

    /** Operator hook: clear the permanent block. */
    public function clearPermanent(): void
    {
        $this->permanent = false;
        $this->recentQuarantines = [];
        $this->cycleAttempts = 0;
        $this->quarantinedUntilUnix = null;
        $this->persist();
    }

    public function isPermanent(): bool { return $this->permanent; }
    public function isQuarantined(?int $nowUnix = null): bool
    {
        $now = $nowUnix ?? time();
        return $this->permanent
            || ($this->quarantinedUntilUnix !== null && $now < $this->quarantinedUntilUnix);
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(?int $nowUnix = null): array
    {
        $now = $nowUnix ?? time();
        return [
            'permanent'              => $this->permanent,
            'quarantined'            => $this->isQuarantined($now),
            'quarantined_until_unix' => $this->quarantinedUntilUnix,
            'quarantined_for_sec'    => $this->quarantinedUntilUnix === null
                ? null
                : max(0, $this->quarantinedUntilUnix - $now),
            'cycle_attempts'         => $this->cycleAttempts,
            'attempts_budget'        => max(0, $this->attemptsPerQuarantine - $this->cycleAttempts),
            'quarantines_in_window'  => count($this->recentQuarantines),
            'permanent_threshold'    => $this->quarantinesBeforePermanent,
        ];
    }

    private function trimQuarantineHistory(int $now): void
    {
        $cutoff = $now - $this->permanentWindowSec;
        $this->recentQuarantines = array_values(array_filter(
            $this->recentQuarantines,
            fn($ts) => $ts >= $cutoff
        ));
    }

    private function loadPersistedState(): void
    {
        $raw = $this->persistence->readAny();
        if ($raw === null) {
            return;
        }
        try {
            $payload = $this->signer->verifyJson($raw);
            if ($payload === null) {
                return;
            }
            $this->cycleAttempts = (int) ($payload['cycle_attempts'] ?? 0);
            $this->quarantinedUntilUnix = isset($payload['quarantined_until_unix']) && $payload['quarantined_until_unix'] !== null
                ? (int) $payload['quarantined_until_unix']
                : null;
            $this->recentQuarantines = [];
            foreach (($payload['recent_quarantines'] ?? []) as $ts) {
                $this->recentQuarantines[] = (int) $ts;
            }
            $this->trimQuarantineHistory(time());
            $this->permanent = (bool) ($payload['permanent'] ?? false);
        } catch (\Throwable) {
            // Best-effort load only.
        }
    }

    private function persist(): void
    {
        try {
            $payload = [
                'cycle_attempts'         => $this->cycleAttempts,
                'quarantined_until_unix' => $this->quarantinedUntilUnix,
                'recent_quarantines'     => $this->recentQuarantines,
                'permanent'              => $this->permanent,
                'persisted_at_unix'      => time(),
            ];
            $json = $this->signer->signToJson($payload);
            $this->persistence->write($json);
        } catch (\Throwable) {
            // In-memory state remains authoritative.
        }
    }
}
