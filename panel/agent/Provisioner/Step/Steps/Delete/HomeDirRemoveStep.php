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
 * Recursively remove the per-site home directory tree (/home/<domain>
 * by default). Inverse of HomeDirCreateStep.
 *
 * Safety: this is the most destructive step in the delete saga - it
 * is the only path that loses raw bytes. The SAGA ORDER is the
 * safety mechanism: PreDeleteSnapshotStep runs first; if it fails,
 * the orchestrator aborts the saga and this step never runs.
 * Operators wiring custom sagas that omit PreDeleteSnapshotStep are
 * responsible for capturing their own backup; the step itself does
 * NOT second-guess the saga because cross-step state inspection is
 * not yet a SiteContext primitive (see step4c roadmap).
 *
 * Resolution order for the home path:
 *
 *   1. state.data['home']               - within-saga cache (resume)
 *   2. payload['home_dir']              - operator override
 *   3. siteRow['home_dir']              - denormalized column
 *   4. CREATE state map[home_dir_create]['data']['home']
 *   5. /home/<domain>                   - default convention
 *
 * Allowed-root governance:
 *   - FilesystemAdapter::rmtree() refuses paths outside its allowed
 *     roots. Production wires `/home` as an allowed root at agent
 *     boot; tests use a sandbox root and addAllowedRoot() in the
 *     test harness. This step does NOT add roots dynamically - that
 *     would defeat the safety boundary.
 *
 * Idempotence:
 *   - check() returns true iff the home directory does NOT exist.
 *   - execute() is a no-op when the directory is already gone.
 *
 * Compensation: DEGRADE_ONLY. We cannot reverse rmtree.
 */
final class HomeDirRemoveStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::HOME_DIR_REMOVE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $home = $this->resolveHome($ctx, $state);
        $fs = $ctx->requireAdapters()->fs;
        return !$fs->exists($home);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $fs = $ctx->requireAdapters()->fs;
        $home = $this->resolveHome($ctx, $state);
        $events = [StepEvent::info('removing home directory tree', ['home' => $home])];

        if (!$fs->exists($home)) {
            $events[] = StepEvent::info('home dir already absent, no-op', ['home' => $home]);
            return StepResult::success(
                $state->mergeData(['home' => $home, 'removed' => false])->withCompleted(),
                $events,
                ['removed' => 0],
            );
        }

        try {
            $removedEntries = $fs->rmtree($home);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['home' => $home]),
                "rmtree failed: " . $e->getMessage(),
                $events,
            );
        }

        $events[] = StepEvent::info('home dir removed', [
            'home' => $home, 'entries_removed' => $removedEntries,
        ]);

        return StepResult::success(
            $state->mergeData([
                'home' => $home,
                'removed' => true,
                'entries_removed' => $removedEntries,
            ])->withCompleted(),
            $events,
            ['removed' => 1],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $home = $state->data['home'] ?? null;
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: home dir NOT recreated (DEGRADE_ONLY); restore from snapshot if needed',
                ['home' => $home]
            )]
        );
    }

    private function resolveHome(SiteContext $ctx, StepState $state): string
    {
        if (!empty($state->data['home']) && is_string($state->data['home'])) {
            return $state->data['home'];
        }
        $payload = $ctx->payload;
        if (!empty($payload['home_dir']) && is_string($payload['home_dir'])) {
            return $payload['home_dir'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['home_dir']) && is_string($row['home_dir'])) {
            return $row['home_dir'];
        }
        $fromCreate = $this->lookupFromCreateState($ctx, StepName::HOME_DIR_CREATE, 'home');
        if ($fromCreate !== null) {
            return $fromCreate;
        }
        return '/home/' . $ctx->domain();
    }

    private function lookupFromCreateState(SiteContext $ctx, string $createStep, string $key): ?string
    {
        $stateJson = $ctx->siteRow['state'] ?? null;
        $map = is_string($stateJson) ? json_decode($stateJson, true) : (is_array($stateJson) ? $stateJson : null);
        if (is_array($map)
            && isset($map[$createStep]['data'][$key])
            && is_string($map[$createStep]['data'][$key])
        ) {
            return $map[$createStep]['data'][$key];
        }
        return null;
    }
}
