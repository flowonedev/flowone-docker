<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Create;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Insert the per-site virtualHost block + listener map entries into
 * the main httpd_config.conf via the AST-aware mutator.
 *
 * Two operations are performed under a single read/mutate/write cycle:
 *
 *   1. mutator.upsertVirtualHost($doc, $domain, $overrides)
 *        - inserts (or updates) the `virtualHost <domain> { ... }`
 *          block. Adds vhRoot, configFile, allowSymbolLink, etc.
 *
 *   2. mutator.upsertListenerMaps($doc, $domain, [$domain, "www.$domain"])
 *        - threads `map <domain> <domain> www.<domain>` lines into
 *          the Default + SSL listeners. Idempotent.
 *
 * Both mutations are applied to the SAME in-memory Document so the
 * single writeMainConfig() emits one timestamped backup, one rolling
 * backup, and one atomic rename. This avoids the "half-applied"
 * window the legacy code suffered from where the vhost block was
 * inserted but the listener map line was lost on the next restart.
 *
 * Idempotence:
 *   - check() returns true iff BOTH the vhost block exists AND every
 *     listener that should map the domain has the map line.
 *   - execute() reruns mutator.upsertX which is no-op when desired.
 *
 * Compensation: SAFE_ROLLBACK. compensate() runs removeVirtualHost +
 * removeListenerMaps + writeMainConfig in the same cycle. If the saga
 * had already triggered an OLS restart, the next restart (Step
 * OLS_RESTART running its own compensate path) picks up the removal.
 */
final class OlsMainConfigInsertStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::OLS_MAIN_CONFIG_INSERT;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $ols = $ctx->requireAdapters()->ols;
        $doc = $ols->loadMainConfig();
        $mut = $ols->mutator();
        $domain = $ctx->domain();

        $vhost = $mut->findVirtualHostBlock($doc, $domain);
        if ($vhost === null) {
            return false;
        }

        // Verify all expected listener maps are present.
        $aliases = $this->resolveAliases($ctx);
        $expectedMapValues = [trim($domain . ' ' . implode(' ', $aliases))];
        foreach ($doc->findAllBlocks('listener') as $listener) {
            $name = $listener->args ?? '';
            if (!in_array($name, ['Default', 'SSL'], true)) {
                continue;
            }
            $found = false;
            foreach ($listener->findAllChildDirectives('map') as $map) {
                $firstToken = strtok($map->value, " \t");
                if ($firstToken === $domain) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $mut = $ols->mutator();
        $domain = $ctx->domain();
        $aliases = $this->resolveAliases($ctx);

        $events = [StepEvent::info('mutating httpd_config.conf', [
            'domain' => $domain, 'aliases' => $aliases,
        ])];

        try {
            $doc = $ols->loadMainConfig();
            $vhostChanged = $mut->upsertVirtualHost($doc, $domain, [
                // Overrides over the mutator's VHOST_DEFAULTS. Empty for
                // now; SSL etc lands via UpdateOlsVhostBlockStep later.
            ]);
            $mapChanged = $mut->upsertListenerMaps($doc, $domain, $aliases, false);

            if ($vhostChanged || $mapChanged) {
                $writeResult = $ols->writeMainConfig($doc);
                $events[] = StepEvent::info('main config written', [
                    'vhost_changed' => $vhostChanged,
                    'map_changed' => $mapChanged,
                    'backup' => $writeResult['timestamped_backup'] ?? null,
                    'bytes' => $writeResult['bytes'] ?? null,
                ]);
            } else {
                $events[] = StepEvent::info('main config already in desired state, no-op');
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
                'aliases' => $aliases,
            ])->withCompleted(),
            $events,
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $mut = $ols->mutator();
        $domain = $state->data['domain'] ?? $ctx->domain();
        if (!is_string($domain) || $domain === '') {
            return StepResult::success(
                $state, [StepEvent::info('compensate: no domain recorded')]
            );
        }
        $events = [StepEvent::info('compensate: removing main-config entries', ['domain' => $domain])];
        try {
            $doc = $ols->loadMainConfig();
            $vhostRemoved = $mut->removeVirtualHost($doc, $domain);
            $mapsRemoved = $mut->removeListenerMaps($doc, $domain);
            if ($vhostRemoved || $mapsRemoved) {
                $ols->writeMainConfig($doc);
                $events[] = StepEvent::info('main config rolled back', [
                    'vhost_removed' => $vhostRemoved,
                    'maps_removed' => $mapsRemoved,
                ]);
            } else {
                $events[] = StepEvent::info('compensate: nothing to remove');
            }
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning(
                'compensate: main config rollback failed',
                ['domain' => $domain, 'error' => $e->getMessage()]
            );
        }
        return StepResult::success($state, $events);
    }

    /**
     * @return list<string>
     */
    private function resolveAliases(SiteContext $ctx): array
    {
        $domain = $ctx->domain();
        $payload = $ctx->payload['aliases'] ?? null;
        if (is_array($payload)) {
            return array_values(array_filter(array_map('strval', $payload), static fn($s) => $s !== ''));
        }
        // Default: www.<domain>
        return ['www.' . $domain];
    }
}
