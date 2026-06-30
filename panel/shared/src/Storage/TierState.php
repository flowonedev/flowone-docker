<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 4 tier-state value class.
 *
 * Pure (no I/O). Defines the canonical tier_state alphabet and the
 * adjacency table for valid transitions. Used by both:
 *   - TierStateService (DB-aware): refuses to UPDATE a row to an
 *     illegal next state.
 *   - Invariants::assertTierTransitionAllowed() (defence-in-depth):
 *     called by every consumer right before the DB write.
 *
 * Transition table (read row=from, column=to):
 *
 *                  hot   tiering   cold   recalling   lost
 *   hot             *       Y       -        -          Y
 *   tiering         Y       *       Y        -          Y
 *   cold            -       -       *        Y          Y
 *   recalling       Y       -       Y        *          Y
 *   lost            -       -       -        -          *
 *
 * Notes on the table:
 *   - Self-loops are always allowed (idempotent re-writes are safe).
 *   - hot -> cold is NOT allowed directly; the tier-down worker MUST
 *     first transition to 'tiering', copy bytes to NAS, verify, then
 *     transition to 'cold'. This guarantees we never delete the VPS
 *     copy before the NAS copy is durable.
 *   - cold -> hot is NOT allowed directly; the recall worker MUST
 *     first transition to 'recalling', copy bytes back to VPS, verify,
 *     then transition to 'hot'.
 *   - 'lost' is a terminal sink — once a file is declared lost, only
 *     an explicit operator action (outside the state machine) can
 *     resurrect it (e.g. restore from backup).
 */
final class TierState
{
    public const HOT        = 'hot';
    public const TIERING    = 'tiering';
    public const COLD       = 'cold';
    public const RECALLING  = 'recalling';
    public const LOST       = 'lost';

    /** Used only by the audit log when prior state is unknown (first ever record). */
    public const UNKNOWN    = 'unknown';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::HOT, self::TIERING, self::COLD, self::RECALLING, self::LOST];
    }

    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    /**
     * @return array<string,list<string>>  from-state => list of allowed to-states (excluding self)
     */
    public static function transitions(): array
    {
        return [
            self::HOT       => [self::TIERING, self::LOST],
            self::TIERING   => [self::HOT, self::COLD, self::LOST],
            self::COLD      => [self::RECALLING, self::LOST],
            self::RECALLING => [self::HOT, self::COLD, self::LOST],
            self::LOST      => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }
        if ($from === $to) {
            return true; // self-loops are always allowed
        }
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /**
     * Map the legacy `storage_location` column (added in migration 022)
     * to its corresponding tier_state. Used by the backfill cron.
     *
     *   'local'             -> 'hot'
     *   NULL / unknown      -> 'hot' (the safe default)
     *   'nas'               -> 'cold'
     *   'pending_migration' -> 'tiering'
     */
    public static function fromLegacyLocation(?string $location): string
    {
        return match ($location) {
            'nas'               => self::COLD,
            'pending_migration' => self::TIERING,
            default             => self::HOT,
        };
    }

    /**
     * Inverse of fromLegacyLocation(): pick the storage_location value
     * that a given tier_state implies. Used during the Phase 4/5
     * transition window when both columns must stay in sync.
     */
    public static function toLegacyLocation(string $state): string
    {
        return match ($state) {
            self::COLD                       => 'nas',
            self::TIERING, self::RECALLING   => 'pending_migration',
            default                          => 'local', // hot, lost, unknown -> 'local'
        };
    }

    /**
     * True if the file's bytes can still be read from the VPS local
     * disk for this state. Used by Phase 5 reader logic.
     */
    public static function bytesOnVps(string $state): bool
    {
        return in_array($state, [self::HOT, self::TIERING, self::RECALLING], true);
    }

    /**
     * True if the file's bytes are present on the NAS for this state.
     * Note: 'tiering' is OFF here — bytes are mid-copy, not yet
     * durable on NAS, so consumers must not read from NAS until the
     * transition to 'cold' commits.
     */
    public static function bytesOnNas(string $state): bool
    {
        return in_array($state, [self::COLD, self::RECALLING], true);
    }
}
