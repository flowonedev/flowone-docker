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
 * Remove the per-site vhost directory:
 *   <vhostsRoot>/<domain>/   (typically /usr/local/lsws/conf/vhosts/<domain>/)
 *
 * Inverse of VhostConfigWriteStep. Runs AFTER OlsMainConfigRemoveStep
 * has detached the vhost from httpd_config so OLS will no longer
 * reference these files. If we removed the directory first, the next
 * OLS reload (which can happen any time due to another saga) could
 * fail to find the included file and OLS would refuse to start.
 *
 * Idempotence:
 *   - check() returns true iff the per-site vhost directory does NOT
 *     exist.
 *   - execute() is a no-op when the directory is already gone.
 *
 * Compensation: DEGRADE_ONLY.
 *   The directory is small (a single vhost.conf, usually 1-2KB) and
 *   re-rendering is trivial via the create saga, so an operator can
 *   recover by re-running CREATE with the same params. The step
 *   itself does not attempt restoration.
 */
final class VhostConfigRemoveStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::VHOST_CONFIG_REMOVE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $ols = $ctx->requireAdapters()->ols;
        $fs = $ctx->requireAdapters()->fs;
        $domain = $ctx->domain();
        $vhostFile = $ols->vhostConfigPath($domain);
        $dir = dirname($vhostFile);
        return !$fs->exists($dir);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $domain = $ctx->domain();
        $vhostFile = $ols->vhostConfigPath($domain);
        $dir = dirname($vhostFile);
        $events = [StepEvent::info('removing vhost directory', [
            'domain' => $domain, 'dir' => $dir,
        ])];

        try {
            $removedEntries = $ols->removeVhostConfig($domain);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['domain' => $domain, 'dir' => $dir]),
                "removeVhostConfig failed: " . $e->getMessage(),
                $events,
            );
        }

        $events[] = $removedEntries > 0
            ? StepEvent::info('vhost directory removed', [
                'dir' => $dir, 'entries_removed' => $removedEntries,
            ])
            : StepEvent::info('vhost directory already absent, no-op', ['dir' => $dir]);

        return StepResult::success(
            $state->mergeData([
                'domain' => $domain,
                'dir' => $dir,
                'entries_removed' => $removedEntries,
            ])->withCompleted(),
            $events,
            ['entries_removed' => $removedEntries],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $dir = $state->data['dir'] ?? null;
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: vhost directory NOT recreated (DEGRADE_ONLY); re-run CREATE saga to restore',
                ['dir' => $dir]
            )]
        );
    }
}
