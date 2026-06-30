<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

/**
 * Strategy interface used by the {@see ReconcilerService}.
 *
 * Why decouple here:
 *   - The adapters the production SiteProber relies on
 *     ({@see \VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter} et al.)
 *     are `final` for safety reasons. Tests can't subclass them, but the
 *     reconciler must be exercised against controlled drift scenarios.
 *     This interface lets a test wire a programmed probe without
 *     touching the live adapters.
 *   - Production code keeps using {@see SiteProber}; the interface is
 *     just a seam.
 */
interface SiteProberInterface
{
    /**
     * @param array<string, mixed> $siteRow Decoded `sites` row.
     */
    public function probe(array $siteRow): SiteHealthProbe;
}
