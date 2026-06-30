<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Six-state health enum (Phase 2).
 *
 *   HEALTHY     – probes pass, latency under threshold, breakers closed.
 *   DEGRADED    – probes pass but slow, OR helper unreachable, OR read
 *                 breaker open (system functional but reduced).
 *   READ_ONLY   – reads succeed, writes fail (NAS export read-only,
 *                 quota exceeded, transient EROFS). Apps can still
 *                 serve cached + previously-fetched data.
 *   QUARANTINED – recovery breaker permanently tripped. System refuses
 *                 to auto-recover; operator must clear.
 *   FROZEN      – operator-initiated freeze via /var/lib/flowone/freeze.flag.
 *                 No writes attempted, no recovery attempted. Reads OK
 *                 if the mount still works.
 *   OFFLINE     – NAS unreachable, mount stale, or rw probe failing.
 *
 * Transition rules are encoded in transitions() and enforced by
 * Invariants::assertValidStateTransition() (I-2).
 *
 * Backwards compatibility: the existing Phase 1 string values
 * "healthy" / "degraded" / "offline" remain valid (they ARE three
 * of these six). Phase 2 just adds the other three.
 */
final class HealthState
{
    public const HEALTHY     = 'healthy';
    public const DEGRADED    = 'degraded';
    public const READ_ONLY   = 'read_only';
    public const QUARANTINED = 'quarantined';
    public const FROZEN      = 'frozen';
    public const OFFLINE     = 'offline';

    /** Used only before the first classification has run. */
    public const UNKNOWN     = 'unknown';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::HEALTHY,
            self::DEGRADED,
            self::READ_ONLY,
            self::QUARANTINED,
            self::FROZEN,
            self::OFFLINE,
            self::UNKNOWN,
        ];
    }

    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    /**
     * Returns true when the state is one we consider "the system is
     * usable for at least reads". Used by consumers to decide whether
     * to serve from NAS or fall back to VPS cache.
     */
    public static function isReadable(string $state): bool
    {
        return in_array($state, [
            self::HEALTHY,
            self::DEGRADED,
            self::READ_ONLY,
            self::FROZEN, // reads still attempted; writes blocked
        ], true);
    }

    public static function isWritable(string $state): bool
    {
        return in_array($state, [
            self::HEALTHY,
            self::DEGRADED,
        ], true);
    }

    /**
     * Adjacency list of permitted transitions. A transition not listed
     * here is rejected by Invariants::assertValidStateTransition() (I-2).
     *
     * Self-transitions are always allowed (no-op cycle is a transition
     * with from == to).
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            // From HEALTHY we can degrade, lose writes, freeze, or fully fail.
            self::HEALTHY => [
                self::DEGRADED,
                self::READ_ONLY,
                self::FROZEN,
                self::OFFLINE,
            ],
            // DEGRADED is reachable from anywhere upstream of OFFLINE.
            self::DEGRADED => [
                self::HEALTHY,    // recovery (must pass stability gate)
                self::READ_ONLY,
                self::FROZEN,
                self::OFFLINE,
            ],
            // READ_ONLY can recover (writes return) or fail fully.
            self::READ_ONLY => [
                self::HEALTHY,
                self::DEGRADED,
                self::FROZEN,
                self::OFFLINE,
            ],
            // QUARANTINED is sticky: only operator unblock or full
            // recovery confirmed by health probes can leave it.
            self::QUARANTINED => [
                self::OFFLINE,    // probes continue to fail
                self::DEGRADED,   // recovery succeeded but warming up
                self::FROZEN,     // operator override
            ],
            // FROZEN is also sticky until operator clears the freeze flag.
            // When cleared, we drop to OFFLINE and let probes re-classify.
            self::FROZEN => [
                self::OFFLINE,
                self::DEGRADED,
            ],
            // OFFLINE can be recovered or get quarantined by the breaker.
            self::OFFLINE => [
                self::DEGRADED,     // recovery starting (must stabilise to HEALTHY)
                self::READ_ONLY,    // partial recovery
                self::QUARANTINED,  // recovery breaker tripped
                self::FROZEN,       // operator override
            ],
            // UNKNOWN is allowed to transition to anything (boot path).
            self::UNKNOWN => self::all(),
        ];
    }

    /**
     * @return bool true if from->to is in the transition table (or from === to)
     */
    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }
        if ($from === $to) {
            return true;
        }
        $table = self::transitions();
        return in_array($to, $table[$from] ?? [], true);
    }
}
