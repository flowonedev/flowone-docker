<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Create;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * Create the per-site Linux user. Primary group is the one produced by
 * SftpGroupCreateStep. Home directory is /home/<domain> by default but
 * the caller can override.
 *
 * Inputs:
 *   - payload['sftp_user']:    explicit username (preferred)
 *   - siteRow['sftp_user']:    persisted username from prior runs
 *   - payload['home_dir']:     explicit home directory
 *   - siteRow['home_dir']:     persisted home directory
 *   - payload['sftp_group']:   explicit primary group (else read from
 *                              site_state JSON sftp_group_create step)
 *
 * Persisted password handling:
 *   - We do NOT generate a password here. A separate optional step
 *     `SftpPasswordSetStep` (not in the create saga by default) is
 *     responsible for password rotation. Until that runs, the user
 *     has no password and SFTP-key-based access is the only path.
 *   - This matches the "no plaintext credentials" rule.
 *
 * Idempotence:
 *   - check() returns true iff getent passwd <user> succeeds.
 *   - execute() calls SftpAdapter::createUser which is idempotent.
 *
 * Compensation: SAFE_ROLLBACK.
 */
final class SftpUserCreateStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::SFTP_USER_CREATE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        // Honour the skip_sftp payload knob - see the matching guard
        // in SftpGroupCreateStep. When set, the orchestrator records
        // this step as SKIPPED and HomeDir/Vhost fall back to
        // www-data:www-data for ownership.
        if (!empty($ctx->payload['skip_sftp'])) {
            return true;
        }
        $sftp = $ctx->requireAdapters()->sftp;
        $name = $this->resolveUserName($ctx, $state);
        return $sftp->userExists($name);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $sftp = $ctx->requireAdapters()->sftp;
        $user = $this->resolveUserName($ctx, $state);
        $group = $this->resolveGroupName($ctx);
        $home = $this->resolveHomeDir($ctx);

        $events = [StepEvent::info('creating sftp user', [
            'user' => $user, 'group' => $group, 'home' => $home,
        ])];

        try {
            $created = $sftp->createUser($user, $home, $group);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['user' => $user, 'group' => $group, 'home' => $home]),
                "useradd failed: " . $e->getMessage(),
                $events,
            );
        }

        $events[] = $created
            ? StepEvent::info('user created', ['user' => $user])
            : StepEvent::info('user already present, no-op', ['user' => $user]);

        return StepResult::success(
            $state
                ->mergeData(['user' => $user, 'group' => $group, 'home' => $home])
                ->withCompleted(),
            $events,
            ['created' => $created ? 1 : 0],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $sftp = $ctx->requireAdapters()->sftp;
        $user = $state->data['user'] ?? null;
        if (!is_string($user) || $user === '') {
            return StepResult::success(
                $state,
                [StepEvent::info('compensate: no user recorded, nothing to delete')]
            );
        }
        $events = [StepEvent::info('compensate: deleting sftp user', ['user' => $user])];
        try {
            $sftp->deleteUser($user);
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning(
                'compensate: userdel failed',
                ['user' => $user, 'error' => $e->getMessage()]
            );
        }
        return StepResult::success($state, $events);
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
        // Default: shared derivation with SftpGroupCreateStep so the
        // user and its primary group end up with the same name and
        // operators can grep one to find the other.
        return ResourceNameDeriver::sftpName($ctx->domain());
    }

    private function resolveGroupName(SiteContext $ctx): string
    {
        // Resolution order (most authoritative wins):
        //   1. explicit payload override
        //   2. denormalised siteRow column
        //   3. SftpGroupCreateStep's persisted state under siteRow.state
        //      (forward-looking: production today does NOT hydrate this
        //      field into SiteContext - the contract is documented in
        //      StepStateStore but not yet implemented in the
        //      orchestrator - so the lookup always misses and we fall
        //      through to step 4)
        //   4. shared derivation: matches SftpGroupCreateStep
        //      byte-for-byte via ResourceNameDeriver, so the group the
        //      previous step just created is always findable here
        //      regardless of whether the cross-step state hydration
        //      ever lands. This is the path that fixes the
        //      "cannot resolve primary group" regression.
        $payload = $ctx->payload;
        if (!empty($payload['sftp_group']) && is_string($payload['sftp_group'])) {
            return $payload['sftp_group'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['sftp_group']) && is_string($row['sftp_group'])) {
            return $row['sftp_group'];
        }
        $stateJson = $row['state'] ?? null;
        $stateMap = is_string($stateJson) ? json_decode($stateJson, true) : $stateJson;
        if (is_array($stateMap)
            && isset($stateMap[StepName::SFTP_GROUP_CREATE]['data']['group'])
            && is_string($stateMap[StepName::SFTP_GROUP_CREATE]['data']['group'])
        ) {
            return $stateMap[StepName::SFTP_GROUP_CREATE]['data']['group'];
        }
        return ResourceNameDeriver::sftpName($ctx->domain());
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
}
