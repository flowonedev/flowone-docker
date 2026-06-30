<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Restore;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Extract the archived home.tar.gz into the freshly-created home
 * dir. Runs AFTER HomeDirCreateStep so the parent dir + skeleton
 * exists.
 *
 * The tar was created with `-C parent --` so the archive contains a
 * single top-level entry equal to the basename of the home dir.
 * Extraction uses the same `-C` indirection to drop the archive's
 * contents back into the parent, restoring the original layout.
 *
 * Idempotence:
 *   - check() returns true iff state.completed && state.data['home']
 *     matches the current resolved home dir, AND the extraction
 *     "sentinel" file inside the home dir is present. The sentinel
 *     is a 0-byte marker `.flowone-restored-from-job<jobId>` we
 *     write at the end of execute() so resumes don't re-extract.
 *
 * Compensation: SAFE_ROLLBACK; no-op.
 *   The destructive teardown chain isn't fired on RESTORE
 *   compensation (the create steps' compensate() removes the home
 *   dir entirely), so we don't need to remove the extracted files
 *   ourselves.
 *
 * Payload knobs:
 *   - skip_home_hydrate=true            skip silently (operator
 *                                        wants a bare home dir).
 *   - home_tar_path=/abs/path           override the resolved path
 *                                        (default: archive_path/home.tar.gz).
 *   - restore_timeout_seconds=N         default 1800.
 */
final class HomeDirHydrateStep extends AbstractStep
{
    private const DEFAULT_TIMEOUT_SECONDS = 1800;

    public function name(): string
    {
        return StepName::HOME_DIR_HYDRATE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        if (($ctx->payload['skip_home_hydrate'] ?? false) === true) {
            return true;
        }
        if (!$state->isComplete()) {
            return false;
        }
        $fs = $ctx->requireAdapters()->fs;
        $cachedHome = $state->data['home'] ?? null;
        $sentinel = $state->data['sentinel'] ?? null;
        if (!is_string($cachedHome) || !is_string($sentinel)) {
            return false;
        }
        return $fs->isFile($sentinel);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        if (($ctx->payload['skip_home_hydrate'] ?? false) === true) {
            return StepResult::success(
                $state->mergeData(['skipped_by_operator' => true])->withCompleted(),
                [StepEvent::warning('home hydrate skipped by operator (skip_home_hydrate=true)')],
            );
        }

        $fs = $ctx->requireAdapters()->fs;
        $runner = $ctx->requireAdapters()->runner;
        $home = $this->resolveHomeDir($ctx);
        $tarPath = $this->resolveTarPath($ctx);

        if (!is_string($tarPath) || $tarPath === '') {
            return StepResult::failure(
                $state,
                'home_dir_hydrate: archive_path missing - preflight should have caught this'
            );
        }
        if (!$fs->isFile($tarPath)) {
            return StepResult::failure(
                $state,
                "home_dir_hydrate: home tar not found at {$tarPath}"
            );
        }
        if (!$fs->isDirectory($home)) {
            return StepResult::failure(
                $state,
                "home_dir_hydrate: home dir does not exist at {$home}; HomeDirCreateStep must run first"
            );
        }

        $events = [StepEvent::info('extracting home tar', [
            'tar' => $tarPath, 'home' => $home,
        ])];

        $parent = dirname($home);
        if ($parent === '' || $parent === '/') {
            return StepResult::failure(
                $state,
                "home_dir_hydrate: refusing to extract to root-equivalent parent: {$parent}"
            );
        }

        $timeout = (int) ($ctx->payload['restore_timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        if ($timeout < 60) {
            $timeout = self::DEFAULT_TIMEOUT_SECONDS;
        }

        // tar -C <parent> -xzf <tar>: the archive's top-level entry
        // matches basename(home) so extraction lands inside $home.
        // We use --overwrite so a freshly-created skeleton's files
        // get replaced by the archive's versions.
        $args = ['-C', $parent, '-xzf', $tarPath, '--overwrite'];
        $r = $runner->run('/bin/tar', $args, null, $timeout);
        if (!$r->isSuccess()) {
            return StepResult::failure(
                $state,
                "tar extract failed: " . $r->summary(),
                $events,
            );
        }
        $events[] = StepEvent::info('tar extract complete');

        $sentinel = rtrim($home, '/') . '/.flowone-restored-from-job' . $ctx->jobId;
        try {
            $fs->writeAtomic($sentinel, '');
        } catch (\Throwable $e) {
            // Non-fatal: the extraction succeeded, the sentinel is
            // only an idempotency hint. Emit a warning.
            $events[] = StepEvent::warning(
                'could not write sentinel; resume detection may re-extract',
                ['sentinel' => $sentinel, 'error' => $e->getMessage()]
            );
        }

        return StepResult::success(
            $state->mergeData([
                'home' => $home,
                'tar_path' => $tarPath,
                'sentinel' => $sentinel,
                'restored_at' => time(),
            ])->withCompleted(),
            $events,
            ['home_extracted' => 1],
        );
    }

    private function resolveHomeDir(SiteContext $ctx): string
    {
        $payload = $ctx->payload;
        if (!empty($payload['home_dir']) && is_string($payload['home_dir'])) {
            return $payload['home_dir'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['home_dir']) && is_string($row['home_dir'])) {
            return $row['home_dir'];
        }
        return '/home/' . $ctx->domain();
    }

    private function resolveTarPath(SiteContext $ctx): ?string
    {
        $payload = $ctx->payload;
        if (!empty($payload['home_tar_path']) && is_string($payload['home_tar_path'])) {
            return $payload['home_tar_path'];
        }
        $archive = $payload['archive_path'] ?? null;
        if (is_string($archive) && $archive !== '') {
            return rtrim($archive, '/') . '/home.tar.gz';
        }
        return null;
    }
}
