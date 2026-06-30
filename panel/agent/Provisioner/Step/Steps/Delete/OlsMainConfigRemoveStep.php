<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Delete;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Remove the per-site `virtualHost <domain> { ... }` block AND every
 * `map <domain> ...` line in the Default / SSL listeners.
 *
 * Inverse of OlsMainConfigInsertStep. Both mutations are applied to
 * the same in-memory Document so the single writeMainConfig() emits
 * one timestamped backup, one rolling backup, and one atomic rename.
 * No half-applied state is possible.
 *
 * Order in the saga:
 *   - BEFORE VhostConfigRemoveStep so OLS no longer points at the
 *     per-site directory we're about to delete.
 *   - BEFORE OlsRestartStep so the restart picks up the removal in
 *     a single sweep.
 *
 * Idempotence:
 *   - check() returns true iff neither the vhost block nor any
 *     listener-map line exists for the domain.
 *   - execute() reruns removeVirtualHost / removeListenerMaps which
 *     are themselves no-ops when there's nothing to remove.
 *
 * Compensation: DEGRADE_ONLY.
 *   The mutator does keep a backup of the previous main config via
 *   OlsConfigWriter, but restoring a whole-file backup just to undo
 *   one delete would also undo any unrelated changes that landed in
 *   the meantime (other sites being provisioned concurrently). The
 *   operator can re-run CREATE to rebuild the block.
 */
final class OlsMainConfigRemoveStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::OLS_MAIN_CONFIG_REMOVE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $ols = $ctx->requireAdapters()->ols;
        $mut = $ols->mutator();
        $doc = $ols->loadMainConfig();
        $domain = $ctx->domain();

        if ($mut->findVirtualHostBlock($doc, $domain) !== null) {
            return false;
        }
        // Any lingering listener-map line for this domain means we
        // still need to run.
        foreach ($doc->findAllBlocks('listener') as $listener) {
            foreach ($listener->findAllChildDirectives('map') as $map) {
                $firstToken = strtok($map->value, " \t");
                if ($firstToken === $domain) {
                    return false;
                }
            }
        }
        return true;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $mut = $ols->mutator();
        $domain = $ctx->domain();
        $events = [StepEvent::info('removing main-config entries', ['domain' => $domain])];
        $vhostRemoved = false;
        $mapsRemoved = false;

        try {
            $doc = $ols->loadMainConfig();
            $vhostRemoved = $mut->removeVirtualHost($doc, $domain);
            $mapsRemoved = $mut->removeListenerMaps($doc, $domain);

            if ($vhostRemoved || $mapsRemoved) {
                $writeResult = $ols->writeMainConfig($doc);
                $events[] = StepEvent::info('main config written', [
                    'vhost_removed' => $vhostRemoved,
                    'maps_removed' => $mapsRemoved,
                    'backup' => $writeResult['timestamped_backup'] ?? null,
                    'bytes' => $writeResult['bytes'] ?? null,
                ]);
            } else {
                $events[] = StepEvent::info('main config already clean, no-op', [
                    'domain' => $domain,
                ]);
            }
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['domain' => $domain]),
                "main config mutation failed: " . $e->getMessage(),
                $events,
            );
        }

        return StepResult::success(
            $state->mergeData([
                'domain' => $domain,
                'vhost_removed' => $vhostRemoved,
                'maps_removed' => $mapsRemoved,
            ])->withCompleted(),
            $events,
            [
                'vhost_removed' => $vhostRemoved ? 1 : 0,
                'maps_removed' => $mapsRemoved ? 1 : 0,
            ],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $domain = $state->data['domain'] ?? $ctx->domain();
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: main-config entries NOT restored (DEGRADE_ONLY); re-run CREATE saga to restore',
                ['domain' => $domain]
            )]
        );
    }
}
