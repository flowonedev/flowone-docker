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
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * Remove the per-site Linux group.
 *
 * Runs near the end of the DELETE saga, AFTER the user has been
 * removed (groupdel refuses to delete a group that is still a user's
 * primary group). The corresponding CREATE step is
 * SftpGroupCreateStep.
 *
 * Resolution order for the group name (must match SftpGroupCreateStep
 * exactly so the same site round-trips):
 *
 *   1. payload['sftp_group']        - operator override
 *   2. state.data['group']          - resume-friendly cache
 *   3. derived from $ctx->domain()  - "site_<sanitized>" up to 31
 *                                     chars with stable hash suffix
 *
 * Idempotence:
 *   - check() returns true iff getent reports the group is GONE.
 *   - execute() is a no-op when the group never existed.
 *
 * Compensation: DEGRADE_ONLY.
 *   Once we groupdel, we cannot rebuild the SAME numeric GID and the
 *   file ownerships that referenced it. The home-dir step would have
 *   already removed the only files referencing this GID, so this is
 *   safe in the normal flow. If a LATER step fails, we land in
 *   degraded - the operator restores from the snapshot taken by
 *   PreDeleteSnapshotStep.
 */
final class SftpGroupRemoveStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::SFTP_GROUP_REMOVE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $sftp = $ctx->requireAdapters()->sftp;
        $name = $this->resolveGroupName($ctx, $state);
        // Already absent → done.
        if (!$sftp->groupExists($name)) {
            return true;
        }
        // Shared system group (e.g. www-data, users) that other
        // accounts still depend on → there's nothing FOR THIS SITE
        // to clean up; treat as already satisfied. The execute()
        // path will produce the same outcome with a clear audit log.
        return $sftp->groupHasMembers($name);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $sftp = $ctx->requireAdapters()->sftp;
        $name = $this->resolveGroupName($ctx, $state);
        $events = [StepEvent::info('deleting sftp group', ['group' => $name])];

        // Refuse to groupdel a shared system group. groupdel(8)
        // itself refuses when the group is a user's primary group,
        // but happily removes a group whose only references are
        // supplementary members - which would silently break file
        // ownership on other sites. groupHasMembers() catches BOTH
        // cases.
        if ($sftp->groupHasMembers($name)) {
            $events[] = StepEvent::warning(
                'group is shared with other users; leaving in place',
                ['group' => $name]
            );
            return StepResult::success(
                $state->mergeData([
                    'group' => $name,
                    'removed' => false,
                    'shared' => true,
                ])->withCompleted(),
                $events,
                ['removed' => 0, 'shared' => 1],
            );
        }

        try {
            $deleted = $sftp->deleteGroup($name);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['group' => $name]),
                "groupdel failed: " . $e->getMessage(),
                $events,
            );
        }

        $events[] = $deleted
            ? StepEvent::info('group removed', ['group' => $name])
            : StepEvent::info('group already absent, no-op', ['group' => $name]);

        return StepResult::success(
            $state->mergeData([
                'group' => $name,
                'removed' => $deleted,
            ])->withCompleted(),
            $events,
            ['removed' => $deleted ? 1 : 0],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        // DEGRADE_ONLY: cannot un-groupdel. Re-creating the group would
        // get a different GID and would not restore file ownerships.
        $name = $state->data['group'] ?? null;
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: group NOT recreated (DEGRADE_ONLY); restore from snapshot if needed',
                ['group' => $name]
            )]
        );
    }

    private function resolveGroupName(SiteContext $ctx, StepState $state): string
    {
        if (!empty($state->data['group']) && is_string($state->data['group'])) {
            return $state->data['group'];
        }
        $payload = $ctx->payload;
        if (!empty($payload['sftp_group']) && is_string($payload['sftp_group'])) {
            return $payload['sftp_group'];
        }
        // Prior CREATE saga's persisted group, if a state map exists.
        $fromCreate = $this->lookupFromCreateState($ctx, StepName::SFTP_GROUP_CREATE, 'group');
        if ($fromCreate !== null) {
            return $fromCreate;
        }
        // For sites adopted from legacy vhosts the CREATE-saga state map
        // is empty, so the group name was never recorded there. The
        // upstream SftpUserRemoveStep resolves the user's actual
        // primary group via getent (before userdel runs) and stashes
        // it under its own state.data, which the worker persists into
        // sites.state JSON. Reading it here lets us delete the right
        // group regardless of whether it follows the modern
        // `site_<domain>` convention or a legacy name like
        // `email_devcon` / `www-data`.
        $fromUserRemove = $this->lookupFromCreateState($ctx, StepName::SFTP_USER_REMOVE, 'primary_group');
        if ($fromUserRemove !== null) {
            return $fromUserRemove;
        }
        return $this->deriveFromDomain($ctx->domain());
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

    /**
     * Single source of truth - matches SftpGroupCreateStep so DELETE
     * finds the group CREATE made.
     */
    private function deriveFromDomain(string $domain): string
    {
        return ResourceNameDeriver::sftpName($domain);
    }
}
