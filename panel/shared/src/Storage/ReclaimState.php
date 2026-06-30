<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 6c — Reclaim daemon state machine.
 *
 * Five states, deterministic transitions driven by the current
 * StorageBudget watermark + cooldown timer + operator pause flag.
 *
 *   IDLE        budget clear; long polling cadence. Default state at startup.
 *   WARMING     watermark >= WM_HIGH; daemon raises cadence and prepares
 *               to reclaim on the next tick.
 *   RECLAIMING  daemon is actively running a tier-down + sweep cycle.
 *               Single-shot per state visit — once the cycle returns the
 *               daemon transitions to COOLDOWN regardless of outcome.
 *   COOLDOWN    cycle just finished; mandatory wait of reclaim.cooldown_sec
 *               before considering another reclaim, even if watermark is
 *               still HIGH. Prevents thrash + gives the OS time to settle.
 *   PAUSED     operator set the pause flag; daemon keeps polling state but
 *               will not transition to RECLAIMING. Returns to IDLE the
 *               moment the flag is removed.
 *
 * The state machine is intentionally simple: most transitions are
 * "look at current budget + timer, return the new state". No nested
 * states, no parallel regions. ReclaimController owns the decision
 * function and is pure — easy to unit-test.
 */
final class ReclaimState
{
    public const IDLE       = 'idle';
    public const WARMING    = 'warming';
    public const RECLAIMING = 'reclaiming';
    public const COOLDOWN   = 'cooldown';
    public const PAUSED     = 'paused';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::IDLE, self::WARMING, self::RECLAIMING, self::COOLDOWN, self::PAUSED];
    }

    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    /**
     * Whether a given transition is allowed. The state machine is
     * mostly permissive (any state can be entered from any other) but
     * a few transitions are nonsensical and worth catching:
     *   - PAUSED can only be entered when operator pause is observed
     *   - RECLAIMING can only be entered from WARMING (the daemon must
     *     pass through WARMING to confirm pressure before doing work)
     *   - COOLDOWN can only be entered from RECLAIMING (it represents
     *     the post-reclaim settle period; entering from elsewhere
     *     means a bug)
     *
     * Note: PAUSED can be entered from anything; leaving PAUSED always
     * goes to IDLE (we never resume "in the middle" of a cycle).
     */
    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }
        if ($from === $to) {
            return true; // self-loop is always allowed (re-entering same state on the next tick)
        }
        // PAUSED is a sink that anything can enter, and only IDLE can follow.
        if ($to === self::PAUSED) {
            return true;
        }
        if ($from === self::PAUSED) {
            return $to === self::IDLE;
        }
        // Strict ordering for the work path:
        if ($to === self::RECLAIMING) {
            return $from === self::WARMING;
        }
        if ($to === self::COOLDOWN) {
            return $from === self::RECLAIMING;
        }
        // Everything else (IDLE <-> WARMING, COOLDOWN -> IDLE/WARMING) is allowed.
        return true;
    }
}
