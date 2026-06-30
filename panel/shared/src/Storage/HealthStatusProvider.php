<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Thin read-only seam over StorageHealth::getStatus().
 *
 * Exists for ONE reason: TierRecallService and any other consumer
 * that only needs the current health snapshot can depend on this
 * interface rather than the concrete `final class StorageHealth`,
 * which lets unit tests substitute a deterministic stub WITHOUT
 * removing `final` from the production class (which is the right
 * keyword for it — there is no production reason to subclass).
 *
 * `StorageHealth implements HealthStatusProvider` is a zero-cost
 * change: the interface adds nothing the real class doesn't already
 * publicly expose. Every production wiring point keeps passing the
 * concrete `StorageHealth` instance and gets the same behaviour.
 */
interface HealthStatusProvider
{
    /**
     * Return the current/last-known storage health snapshot. Must
     * never throw — health reads are on the hot path and a thrown
     * exception here would cascade into every recall attempt.
     */
    public function getStatus(): HealthStatus;
}
