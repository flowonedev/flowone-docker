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
 * Remove the per-site Linux user. Inverse of SftpUserCreateStep.
 *
 * The user MUST be removed BEFORE the group (a primary-group member
 * blocks groupdel) AND BEFORE the home dir (the kernel allows
 * userdel-without-rm-home but reusing the same UID later would leak
 * file ownership). The saga order in SagaRegistry::deleteSequence()
 * encodes this.
 *
 * Resolution order for the username (must match SftpUserCreateStep):
 *
 *   1. payload['sftp_user']           - operator override
 *   2. state.data['user']             - within-saga cache
 *   3. siteRow['sftp_user']           - denormalized column written
 *                                       by the CREATE saga
 *   4. state JSON map[sftp_user_create]['data']['user']
 *   5. derived from domain            - "site_<sanitized>" up to 31
 *                                       chars with stable hash suffix
 *
 * Idempotence:
 *   - check() returns true iff getent passwd reports the user is GONE.
 *   - execute() is a no-op when the user never existed.
 *
 * Compensation: DEGRADE_ONLY. We cannot rebuild the same numeric UID.
 */
final class SftpUserRemoveStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::SFTP_USER_REMOVE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $sftp = $ctx->requireAdapters()->sftp;
        $name = $this->resolveUserName($ctx, $state);
        return !$sftp->userExists($name);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $sftp = $ctx->requireAdapters()->sftp;
        $name = $this->resolveUserName($ctx, $state);
        $events = [StepEvent::info('deleting sftp user', ['user' => $name])];

        // Resolve the user's primary group BEFORE deletion so the
        // downstream SftpGroupRemoveStep can use the real group name
        // (e.g. "test", "email_devcon", "www-data") rather than the
        // domain-derived fallback. After userdel runs, getent passwd
        // returns nothing, so this lookup has to happen first.
        $primaryGroup = null;
        try {
            $primaryGroup = $sftp->primaryGroupName($name);
        } catch (\Throwable $e) {
            // Non-fatal - logging only; the group step has its own
            // fallback chain.
            $events[] = StepEvent::warning(
                'could not resolve primary group before delete',
                ['user' => $name, 'error' => $e->getMessage()]
            );
        }
        if ($primaryGroup !== null) {
            $events[] = StepEvent::info(
                'cached primary group for downstream step',
                ['user' => $name, 'primary_group' => $primaryGroup]
            );
        }

        try {
            // force=true: the home dir was removed by HomeDirRemoveStep
            // upstream, so any process still holding the UID is a
            // zombie. userdel(8) returns exit 8 ("user is currently
            // used by process N") without -f, blocking the saga.
            $deleted = $sftp->deleteUser($name, force: true);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData([
                    'user' => $name,
                    'primary_group' => $primaryGroup,
                ]),
                "userdel failed: " . $e->getMessage(),
                $events,
            );
        }

        $events[] = $deleted
            ? StepEvent::info('user removed', ['user' => $name])
            : StepEvent::info('user already absent, no-op', ['user' => $name]);

        return StepResult::success(
            $state->mergeData([
                'user' => $name,
                'primary_group' => $primaryGroup,
                'removed' => $deleted,
            ])->withCompleted(),
            $events,
            ['removed' => $deleted ? 1 : 0],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $name = $state->data['user'] ?? null;
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: user NOT recreated (DEGRADE_ONLY); restore from snapshot if needed',
                ['user' => $name]
            )]
        );
    }

    private function resolveUserName(SiteContext $ctx, StepState $state): string
    {
        if (!empty($state->data['user']) && is_string($state->data['user'])) {
            return $state->data['user'];
        }
        $payload = $ctx->payload;
        if (!empty($payload['sftp_user']) && is_string($payload['sftp_user'])) {
            return $payload['sftp_user'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['sftp_user']) && is_string($row['sftp_user'])) {
            return $row['sftp_user'];
        }
        $fromCreate = $this->lookupFromCreateState($ctx, StepName::SFTP_USER_CREATE, 'user');
        if ($fromCreate !== null) {
            return $fromCreate;
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
     * MUST match SftpUserCreateStep::resolveUserName() derivation.
     */
    private function deriveFromDomain(string $domain): string
    {
        // Single source of truth - matches SftpUserCreateStep's
        // default branch so DELETE finds the user CREATE made.
        return ResourceNameDeriver::sftpName($domain);
    }
}
