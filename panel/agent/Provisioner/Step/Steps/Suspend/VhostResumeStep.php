<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Suspend;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Inverse of VhostSuspendStep. Promotes
 *   <vhostsRoot>/<domain>/vhost.conf.suspended-backup
 * back to vhost.conf and deletes the backup. After OlsRestartStep
 * runs the site is fully live again.
 *
 * Why a separate step (rather than just removing the suspended
 * config and re-rendering): the site's vhost.conf may include
 * operator hand-edits that the create template doesn't model.
 * Restoring from backup preserves those edits byte-for-byte.
 *
 * Idempotence:
 *   - check() returns true iff:
 *       * the backup file is absent, AND
 *       * the live vhost.conf does NOT contain the SUSPENDED marker.
 *     i.e. the site is already in its post-resume shape.
 *   - If the backup is missing but the live config IS suspended,
 *     execute() refuses to proceed - there is no way to restore
 *     the original.
 *
 * Compensation: SAFE_ROLLBACK.
 *   compensate() re-suspends the site by re-running the same
 *   live-replace logic VhostSuspendStep uses. This is only invoked
 *   if some later step in the RESUME saga fails and the orchestrator
 *   unwinds.
 */
final class VhostResumeStep extends AbstractStep
{
    private const SUSPENDED_MARKER = '# flowone:suspended=true';
    private const BACKUP_SUFFIX = '.suspended-backup';

    public function name(): string
    {
        return StepName::VHOST_RESUME;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $ols = $ctx->requireAdapters()->ols;
        $fs = $ctx->requireAdapters()->fs;
        $livePath = $ols->vhostConfigPath($ctx->domain());
        $backupPath = $livePath . self::BACKUP_SUFFIX;

        // Defensive stat-cache clear; see VhostSuspendStep::check() for
        // the rationale (atomic rename + back-to-back runs).
        clearstatcache(true, $livePath);
        clearstatcache(true, $backupPath);

        if ($fs->isFile($backupPath)) {
            return false;
        }
        if (!$fs->isFile($livePath)) {
            // No live config + no backup is a weird state; safer to
            // run execute() which will surface the failure cleanly
            // than to silently treat as "already resumed".
            return false;
        }
        $live = $fs->readFile($livePath);
        return is_string($live) && !str_contains($live, self::SUSPENDED_MARKER);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $fs = $ctx->requireAdapters()->fs;
        $domain = $ctx->domain();
        $livePath = $ols->vhostConfigPath($domain);
        $backupPath = $livePath . self::BACKUP_SUFFIX;

        $events = [StepEvent::info('resuming vhost', [
            'domain' => $domain,
            'live' => $livePath,
            'backup' => $backupPath,
        ])];

        if (!$fs->isFile($backupPath)) {
            // If the live config is already healthy (no marker), this
            // is a successful no-op. Otherwise we cannot recover the
            // pre-suspend config and must fail loudly.
            if ($fs->isFile($livePath)) {
                $live = $fs->readFile($livePath);
                if (is_string($live) && !str_contains($live, self::SUSPENDED_MARKER)) {
                    $events[] = StepEvent::info('backup absent but live config is not suspended; treating as already resumed');
                    return StepResult::success(
                        $state->mergeData([
                            'domain' => $domain,
                            'live_path' => $livePath,
                            'resumed_no_op' => true,
                            'resumed_at' => time(),
                        ])->withCompleted(),
                        $events,
                    );
                }
            }
            return StepResult::failure(
                $state->mergeData(['domain' => $domain]),
                "cannot resume: backup file missing at {$backupPath} and live config not in healthy state",
                $events,
            );
        }

        $original = $fs->readFile($backupPath);
        if (!is_string($original)) {
            return StepResult::failure(
                $state->mergeData(['domain' => $domain]),
                "cannot read backup file {$backupPath}",
                $events,
            );
        }

        try {
            $ols->writeVhostConfig($domain, $original);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['domain' => $domain, 'backup_path' => $backupPath]),
                "could not write restored vhost.conf at {$livePath}: " . $e->getMessage(),
                $events,
            );
        }
        $events[] = StepEvent::info('vhost.conf restored from backup', [
            'live' => $livePath,
            'bytes' => strlen($original),
        ]);

        // Remove the backup AFTER the rename succeeded; if the rename
        // had failed we'd still have the backup for retry.
        try {
            $fs->deleteFile($backupPath);
            $events[] = StepEvent::info('backup file removed', ['backup' => $backupPath]);
        } catch (\Throwable $e) {
            // Non-fatal: log a warning and keep going. RESUME succeeded
            // structurally; the backup file just lingers.
            $events[] = StepEvent::warning(
                'could not delete backup file after restore; leaving it in place',
                ['backup' => $backupPath, 'error' => $e->getMessage()]
            );
        }

        return StepResult::success(
            $state->mergeData([
                'domain' => $domain,
                'live_path' => $livePath,
                'backup_path' => $backupPath,
                'resumed_at' => time(),
            ])->withCompleted(),
            $events,
            ['restored_bytes' => strlen($original)],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: VhostResumeStep does not re-suspend automatically. '
                . 'If a later RESUME-saga step failed, operator must re-run SUSPEND or RESUME.'
            )]
        );
    }
}
