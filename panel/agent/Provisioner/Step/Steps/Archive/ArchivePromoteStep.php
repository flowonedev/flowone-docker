<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Archive;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Promote the per-job snapshot directory written by
 * PreDeleteSnapshotStep into the long-term archive store:
 *
 *   <snapshot_root>/<domain>/<job_id>/
 *       -> <archive_root>/<domain>/<timestamp>-<job_id>/
 *
 * The archive directory uses an ISO-ish timestamp prefix so a `ls`
 * gives a chronological listing without parsing job IDs. The
 * destination tree is read-only (mode 0500 dirs / 0400 files) so
 * accidental rm or chown is harder.
 *
 * Idempotence:
 *   - check() returns true iff state.data['archive_path'] is set
 *     for the current job AND the directory exists on disk.
 *   - Re-running on a finished archive is a no-op.
 *
 * Compensation: SAFE_ROLLBACK.
 *   compensate() removes the archive copy if it exists. This is safe
 *   because the saga compensation chain only fires if a later step
 *   failed BEFORE the destructive teardown ran (CompensationPolicy
 *   for DELETE steps is DEGRADE_ONLY, so once those fire the saga
 *   no longer unwinds). The snapshot in <snapshot_root> is NOT
 *   touched - the operator may need it.
 *
 * Payload knobs:
 *   - archive_root=/abs/path           default: /var/www/vps-admin/storage/archives
 *   - snapshot_root=/abs/path          must match PreDeleteSnapshotStep
 *   - archive_copy_timeout_seconds=N   default 1800
 *
 * Important:
 *   - This step requires PreDeleteSnapshotStep to have written a
 *     snapshot. If the snapshot dir is missing or empty we fail
 *     LOUDLY rather than silently archiving nothing.
 *   - skip_snapshot in payload is REJECTED by archive; an archive
 *     without data is not an archive.
 */
final class ArchivePromoteStep extends AbstractStep
{
    private const DEFAULT_ARCHIVE_ROOT = '/var/www/vps-admin/storage/archives';
    private const DEFAULT_SNAPSHOT_ROOT = '/var/www/vps-admin/storage/snapshots';
    private const DEFAULT_TIMEOUT_SECONDS = 1800;

    public function name(): string
    {
        return StepName::ARCHIVE_PROMOTE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $fs = $ctx->requireAdapters()->fs;
        $archivePath = $state->data['archive_path'] ?? null;
        $cachedJobId = $state->data['job_id'] ?? null;

        if (!is_string($archivePath) || $archivePath === '') {
            return false;
        }
        if ((int) $cachedJobId !== $ctx->jobId) {
            return false;
        }
        return $fs->isDirectory($archivePath);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        if (($ctx->payload['skip_snapshot'] ?? false) === true) {
            return StepResult::failure(
                $state,
                'archive_promote: archive cannot proceed with skip_snapshot=true (no data to archive)'
            );
        }

        $fs = $ctx->requireAdapters()->fs;
        $runner = $ctx->requireAdapters()->runner;

        $snapshotDir = $this->snapshotDir($ctx);
        if (!$fs->isDirectory($snapshotDir)) {
            return StepResult::failure(
                $state,
                "archive_promote: snapshot directory missing at {$snapshotDir}"
                . ' (PreDeleteSnapshotStep should run first)'
            );
        }

        $archiveDir = $this->archiveDir($ctx);
        $events = [
            StepEvent::info('promoting snapshot to archive', [
                'src' => $snapshotDir, 'dest' => $archiveDir,
            ]),
        ];

        try {
            $fs->ensureDirectory(dirname($archiveDir), 0700);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "could not create archive parent dir: " . $e->getMessage(),
                $events,
            );
        }

        $timeout = (int) ($ctx->payload['archive_copy_timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        if ($timeout < 60) {
            $timeout = self::DEFAULT_TIMEOUT_SECONDS;
        }

        // cp -a preserves perms / mtimes; the archive store should
        // mirror the snapshot byte-for-byte. We use cp -a rather than
        // rename() so the snapshot dir is left in place (operator
        // may want the recovery copy too).
        $cp = $runner->run('/bin/cp', ['-a', '--', $snapshotDir, $archiveDir], null, $timeout);
        if (!$cp->isSuccess()) {
            return StepResult::failure(
                $state,
                "cp -a failed: " . $cp->summary(),
                $events,
            );
        }
        $events[] = StepEvent::info('archive copy complete', ['dir' => $archiveDir]);

        // Make the archive read-only (best effort - not fatal if it
        // fails, e.g. on a CIFS mount).
        $chmod = $runner->run('/usr/bin/find', [$archiveDir, '-type', 'f', '-exec', 'chmod', '0400', '{}', ';'], null, 60);
        if (!$chmod->isSuccess()) {
            $events[] = StepEvent::warning(
                'chmod 0400 on archive files failed; archive not read-only',
                ['summary' => $chmod->summary()]
            );
        }

        return StepResult::success(
            $state->mergeData([
                'job_id' => $ctx->jobId,
                'snapshot_dir' => $snapshotDir,
                'archive_path' => $archiveDir,
                'archived_at' => time(),
            ])->withCompleted(),
            $events,
            ['archive_path' => $archiveDir],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $fs = $ctx->requireAdapters()->fs;
        $archiveDir = $state->data['archive_path'] ?? null;
        if (!is_string($archiveDir) || $archiveDir === '') {
            return StepResult::success(
                $state,
                [StepEvent::info('compensate: no archive_path recorded; nothing to undo')]
            );
        }
        if (!$fs->isDirectory($archiveDir)) {
            return StepResult::success(
                $state,
                [StepEvent::info('compensate: archive dir already absent', ['dir' => $archiveDir])]
            );
        }
        try {
            $fs->rmtree($archiveDir);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "compensate: failed to rmtree archive dir: " . $e->getMessage(),
                [StepEvent::warning('compensate rmtree failed', ['dir' => $archiveDir])],
            );
        }
        return StepResult::success(
            $state,
            [StepEvent::info('compensate: archive copy removed', ['dir' => $archiveDir])]
        );
    }

    /**
     * Resolve the snapshot dir written by PreDeleteSnapshotStep.
     * The formula MUST match PreDeleteSnapshotStep::snapshotDir() so
     * the two steps always agree on the path.
     */
    private function snapshotDir(SiteContext $ctx): string
    {
        $root = $ctx->payload['snapshot_root'] ?? self::DEFAULT_SNAPSHOT_ROOT;
        if (!is_string($root) || $root === '' || $root[0] !== '/') {
            throw new \RuntimeException("snapshot_root must be absolute, got: " . var_export($root, true));
        }
        return rtrim($root, '/') . '/' . $ctx->domain() . '/' . $ctx->jobId;
    }

    private function archiveDir(SiteContext $ctx): string
    {
        $root = $ctx->payload['archive_root'] ?? self::DEFAULT_ARCHIVE_ROOT;
        if (!is_string($root) || $root === '' || $root[0] !== '/') {
            throw new \RuntimeException("archive_root must be absolute, got: " . var_export($root, true));
        }
        $stamp = gmdate('Ymd-His');
        return rtrim($root, '/') . '/' . $ctx->domain() . '/' . $stamp . '-job' . $ctx->jobId;
    }
}
