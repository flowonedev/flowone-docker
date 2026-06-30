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
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * Create the per-site Linux group that owns the home directory and is
 * later set as the primary group of the SFTP user.
 *
 * Inputs (from $ctx->siteRow OR $ctx->payload):
 *   - sftp_group: explicit group name (preferred)
 *   - domain:     fallback - derives "site_<sanitized-domain>" if no
 *                 explicit group given.
 *
 * Outputs (written into StepState.data):
 *   - group: the actual group name we created (so later steps don't
 *            re-derive it and risk inconsistency)
 *
 * Idempotence:
 *   - check() returns true iff getent group <name> succeeds.
 *   - execute() calls SftpAdapter::createGroup which is idempotent.
 *
 * Compensation: SAFE_ROLLBACK.
 *   - compensate() runs groupdel <name>. This is safe because we only
 *     compensate when a LATER step in the SAME job failed, which means
 *     the group was just created in this same saga - no user data
 *     depends on it yet.
 */
final class SftpGroupCreateStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::SFTP_GROUP_CREATE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        // Honour the skip_sftp payload knob (set by CreateSiteV2Modal
        // when the operator unchecks "Create SFTP user"). When skipping
        // we tell the orchestrator the step is already satisfied so it
        // records SKIPPED and never runs groupadd. HomeDirCreateStep
        // and VhostConfigWriteStep know to substitute www-data:www-data
        // as the owner in this mode.
        if (!empty($ctx->payload['skip_sftp'])) {
            return true;
        }
        $sftp = $ctx->requireAdapters()->sftp;
        $name = $this->resolveGroupName($ctx, $state);
        return $sftp->groupExists($name);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $sftp = $ctx->requireAdapters()->sftp;
        $name = $this->resolveGroupName($ctx, $state);

        $events = [StepEvent::info('creating sftp group', ['group' => $name])];

        try {
            $created = $sftp->createGroup($name);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['group' => $name]),
                "groupadd failed: " . $e->getMessage(),
                $events,
            );
        }

        $events[] = $created
            ? StepEvent::info('group created', ['group' => $name])
            : StepEvent::info('group already present, no-op', ['group' => $name]);

        return StepResult::success(
            $state->mergeData(['group' => $name])->withCompleted(),
            $events,
            ['created' => $created ? 1 : 0],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $sftp = $ctx->requireAdapters()->sftp;
        $name = $state->data['group'] ?? null;
        if (!is_string($name) || $name === '') {
            return StepResult::success(
                $state,
                [StepEvent::info('compensate: no group recorded, nothing to delete')]
            );
        }
        $events = [StepEvent::info('compensate: deleting sftp group', ['group' => $name])];
        try {
            $sftp->deleteGroup($name);
        } catch (\Throwable $e) {
            // Best-effort compensation. Group may still have members
            // from a partial later step; log + warn but do not fail
            // the overall compensate chain.
            $events[] = StepEvent::warning(
                'compensate: groupdel failed (likely has remaining members)',
                ['group' => $name, 'error' => $e->getMessage()]
            );
        }
        return StepResult::success($state, $events);
    }

    /**
     * Pick the group name from the most authoritative source available.
     * The payload wins over derived defaults so the operator can
     * override naming in tests / migrations.
     */
    private function resolveGroupName(SiteContext $ctx, StepState $state): string
    {
        // 1. previously persisted value (after a resume)
        if (!empty($state->data['group']) && is_string($state->data['group'])) {
            return $state->data['group'];
        }
        // 2. explicit payload
        $payload = $ctx->payload;
        if (!empty($payload['sftp_group']) && is_string($payload['sftp_group'])) {
            return $payload['sftp_group'];
        }
        // 3. site row override
        $row = $ctx->siteRow;
        if (!empty($row['sftp_group']) && is_string($row['sftp_group'])) {
            return $row['sftp_group'];
        }
        // 4. derive from domain (shared with SftpUserCreateStep via
        //    the central helper so the saga's user and group names
        //    always converge on the same value)
        return ResourceNameDeriver::sftpName($ctx->domain());
    }
}
