<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Monotonic clock helper.
 *
 * Internal invariants (stability gate, breaker windows, lease checks) MUST
 * use this clock — wall time can jump backward (NTP, manual `date -s`,
 * VM clock skew). Monotonic time only ever increases.
 *
 * For values that need to be human-readable or persisted (timestamps in the
 * journal, last_tier_change in the DB), use wall time (time() / date()).
 * The two clocks are NOT interchangeable.
 *
 *   - {@see nowNs()}  returns nanoseconds since an arbitrary epoch.
 *   - {@see nowSec()} returns seconds (float) since the same epoch.
 *
 * Differences between two readings are meaningful; absolute values are not.
 */
final class MonotonicClock
{
    /**
     * Monotonic nanoseconds since an arbitrary epoch.
     */
    public static function nowNs(): int
    {
        return hrtime(true);
    }

    /**
     * Monotonic seconds (float) since an arbitrary epoch.
     */
    public static function nowSec(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    /**
     * Elapsed seconds between two monotonic samples (in ns).
     */
    public static function elapsedSec(int $sinceNs): float
    {
        return (hrtime(true) - $sinceNs) / 1_000_000_000;
    }

    /**
     * Sleep for $seconds using a monotonic deadline so signals/clock jumps
     * cannot extend the sleep arbitrarily.
     */
    public static function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $deadline = self::nowNs() + (int) ($seconds * 1_000_000_000);
        $remaining = $seconds;
        while ($remaining > 0) {
            usleep((int) min(250_000, $remaining * 1_000_000));
            $remaining = ($deadline - self::nowNs()) / 1_000_000_000;
        }
    }
}
