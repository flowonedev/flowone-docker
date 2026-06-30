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
 * Verify the archive payload required for a RESTORE saga exists
 * and is readable BEFORE the destructive CREATE-style steps wipe
 * any previous state on disk.
 *
 * What we check:
 *   - payload.archive_path points at a directory
 *   - <archive_path>/<db_name>.sql exists (if site has db_name)
 *   - <archive_path>/home.tar.gz exists
 *   - all files are readable by the worker uid
 *
 * Why upfront rather than per-step:
 *   - The hydrate steps fire AFTER the live infrastructure has been
 *     re-created. If we discover the archive is incomplete halfway
 *     through, the site is left with an empty home dir and empty db.
 *     Failing early lets the saga abort cleanly before any side
 *     effects.
 *
 * Idempotence:
 *   - check() returns true iff state.completed && state.data['archive_path']
 *     matches the current payload (resumes pick up cached verification).
 *
 * Compensation: SAFE_ROLLBACK; no-op (read-only step).
 */
final class ArchiveRestorePreflightStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::ARCHIVE_RESTORE_PREFLIGHT;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        if (!$state->isComplete()) {
            return false;
        }
        $cached = $state->data['archive_path'] ?? null;
        $current = $ctx->payload['archive_path'] ?? null;
        return is_string($cached) && is_string($current) && $cached === $current;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $fs = $ctx->requireAdapters()->fs;
        $archivePath = $ctx->payload['archive_path'] ?? null;

        if (!is_string($archivePath) || $archivePath === '') {
            return StepResult::failure(
                $state,
                'restore preflight: payload.archive_path is required for RESTORE jobs'
            );
        }
        if ($archivePath[0] !== '/') {
            return StepResult::failure(
                $state,
                "restore preflight: archive_path must be absolute, got: {$archivePath}"
            );
        }
        if (!$fs->isDirectory($archivePath)) {
            return StepResult::failure(
                $state,
                "restore preflight: archive directory missing at {$archivePath}"
            );
        }

        $events = [StepEvent::info('verifying archive payload', ['archive' => $archivePath])];

        $dbName = $this->resolveDbName($ctx);
        $dbDumpPath = null;
        if ($dbName !== null) {
            $dbDumpPath = $archivePath . '/' . $dbName . '.sql';
            if (!$fs->isFile($dbDumpPath)) {
                return StepResult::failure(
                    $state,
                    "restore preflight: db dump missing at {$dbDumpPath}",
                    $events,
                );
            }
            if (!is_readable($dbDumpPath)) {
                return StepResult::failure(
                    $state,
                    "restore preflight: db dump not readable at {$dbDumpPath}",
                    $events,
                );
            }
            $size = (int) (@filesize($dbDumpPath) ?: 0);
            $events[] = StepEvent::info('db dump verified', [
                'path' => $dbDumpPath, 'bytes' => $size,
            ]);
        } else {
            $events[] = StepEvent::info('site has no db_name; db dump not expected');
        }

        $homeTarPath = $archivePath . '/home.tar.gz';
        $homeTarVerified = false;
        if ($fs->isFile($homeTarPath)) {
            if (!is_readable($homeTarPath)) {
                return StepResult::failure(
                    $state,
                    "restore preflight: home tar not readable at {$homeTarPath}",
                    $events,
                );
            }
            $size = (int) (@filesize($homeTarPath) ?: 0);
            $events[] = StepEvent::info('home tar verified', [
                'path' => $homeTarPath, 'bytes' => $size,
            ]);
            $homeTarVerified = true;
        } else {
            $events[] = StepEvent::warning(
                'home tar missing; HomeDirHydrateStep will fail unless skip_home_hydrate=true',
                ['path' => $homeTarPath]
            );
        }

        return StepResult::success(
            $state->mergeData([
                'archive_path' => $archivePath,
                'db_dump_path' => $dbDumpPath,
                'home_tar_path' => $homeTarVerified ? $homeTarPath : null,
                'verified_at' => time(),
            ])->withCompleted(),
            $events,
            [
                'db_dump_present' => $dbDumpPath !== null ? 1 : 0,
                'home_tar_present' => $homeTarVerified ? 1 : 0,
            ],
        );
    }

    private function resolveDbName(SiteContext $ctx): ?string
    {
        if (!empty($ctx->payload['db_name']) && is_string($ctx->payload['db_name'])) {
            return $ctx->payload['db_name'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['db_name']) && is_string($row['db_name'])) {
            return $row['db_name'];
        }
        return null;
    }
}
