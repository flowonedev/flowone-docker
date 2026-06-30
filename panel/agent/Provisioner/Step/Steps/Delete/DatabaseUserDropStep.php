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
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;

/**
 * DROP USER <user>@<host>. Inverse of DatabaseUserCreateStep +
 * DatabaseGrantStep (DROP USER implicitly revokes all grants the
 * user held, so there is no separate revoke step).
 *
 * Order in the saga:
 *   - AFTER DatabaseDropStep so the user no longer has an owned DB
 *     to clean up.
 *   - BEFORE SftpUserRemoveStep so the SFTP user removal is the only
 *     final touch on the unix side.
 *
 * Resolution order for the (user, host) tuple:
 *
 *   1. state.data['db_user'] / state.data['db_host']     - cache
 *   2. payload['db_user'] / payload['db_host']           - override
 *   3. siteRow['db_user']                                - column
 *   4. CREATE state map[database_user_create]['data']['user'/'host']
 *   5. derived from domain (matches DatabaseUserCreateStep default)
 *
 * Host defaults to 'localhost' everywhere because MariaDB stores
 * grants keyed on (User, Host) and the panel only ever creates
 * localhost-scoped users.
 *
 * Idempotence:
 *   - check() returns true iff userExists() returns false.
 *   - execute() is a no-op when the user is already gone.
 *
 * Compensation: DEGRADE_ONLY. Re-creating the user would not restore
 * its prior password hash or grants - that requires a logical
 * restore from PreDeleteSnapshotStep's mysqldump.
 */
final class DatabaseUserDropStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::DATABASE_USER_DROP;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        try {
            [$user, $host] = $this->resolveUserHost($ctx, $state);
        } catch (\Throwable) {
            // Cannot derive a user - nothing to drop. Treat as done.
            return true;
        }
        $mysql = $ctx->requireAdapters()->mysql;
        return !$mysql->userExists($user, $host);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $mysql = $ctx->requireAdapters()->mysql;
        try {
            [$user, $host] = $this->resolveUserHost($ctx, $state);
        } catch (\Throwable $e) {
            // Failed to resolve a user, treat as nothing-to-do success
            // (we don't want to fail the saga if the site never had a
            // DB user provisioned).
            return StepResult::success(
                $state->mergeData(['removed' => false])->withCompleted(),
                [StepEvent::warning(
                    'no DB user could be resolved for this site; skipping DROP USER',
                    ['error' => $e->getMessage()]
                )],
                ['removed' => 0],
            );
        }

        $events = [StepEvent::info('dropping db user', ['user' => $user, 'host' => $host])];

        try {
            $dropped = $mysql->dropUser($user, $host);
        } catch (\Throwable $e) {
            $msg = "dropUser failed: " . $e->getMessage();
            if ($hint = MysqlAdminCredentials::privilegeHint($e)) {
                $msg .= ' | hint: ' . $hint;
            }
            return StepResult::failure(
                $state->mergeData(['db_user' => $user, 'db_host' => $host]),
                $msg,
                $events,
            );
        }

        $events[] = $dropped
            ? StepEvent::info('db user dropped', ['user' => $user, 'host' => $host])
            : StepEvent::info('db user already absent, no-op', ['user' => $user, 'host' => $host]);

        return StepResult::success(
            $state->mergeData([
                'db_user' => $user,
                'db_host' => $host,
                'removed' => $dropped,
            ])->withCompleted(),
            $events,
            ['removed' => $dropped ? 1 : 0],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $user = $state->data['db_user'] ?? null;
        $host = $state->data['db_host'] ?? null;
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: db user NOT recreated (DEGRADE_ONLY); restore from snapshot',
                ['user' => $user, 'host' => $host]
            )]
        );
    }

    /**
     * @return array{0:string,1:string} [user, host]
     */
    private function resolveUserHost(SiteContext $ctx, StepState $state): array
    {
        // Cached in this saga's state
        if (!empty($state->data['db_user']) && is_string($state->data['db_user'])) {
            $host = is_string($state->data['db_host'] ?? null)
                ? $state->data['db_host']
                : 'localhost';
            return [$state->data['db_user'], $host];
        }

        // Payload override
        $payload = $ctx->payload;
        if (!empty($payload['db_user']) && is_string($payload['db_user'])) {
            $host = is_string($payload['db_host'] ?? null) ? $payload['db_host'] : 'localhost';
            return [$payload['db_user'], $host];
        }

        // Denormalized column
        $row = $ctx->siteRow;
        if (!empty($row['db_user']) && is_string($row['db_user'])) {
            return [$row['db_user'], 'localhost'];
        }

        // Prior CREATE state
        $fromCreateUser = $this->lookupFromCreateState($ctx, StepName::DATABASE_USER_CREATE, 'user');
        if ($fromCreateUser !== null) {
            $fromCreateHost = $this->lookupFromCreateState($ctx, StepName::DATABASE_USER_CREATE, 'host')
                ?? 'localhost';
            return [$fromCreateUser, $fromCreateHost];
        }

        // No positive evidence that this site ever had a DB user.
        // Refuse to guess a name from the domain - that pattern was
        // safe for sites the saga created (because the worker had
        // root-level mysql.user access), but for ADOPTED legacy
        // sites with no DB at all it forces a userExists() probe
        // that's both pointless (no user could exist for them) and
        // dangerous (it requires SELECT on mysql.user, which the
        // panel's vpsadmin grant may not include).
        //
        // The caller (check() / execute()) catches this and treats
        // it as "no user to drop, step satisfied".
        throw new \RuntimeException(
            "no DB user recorded for {$ctx->domain()}; nothing to drop"
        );
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
     * MUST match DatabaseUserCreateStep::deriveFromDomain() byte-for-
     * byte. MariaDB usernames are 32 chars max (10.x; cap at 32 for
     * portability with 11.x). The prefix `fo_` is short enough that
     * even a 24-char domain slug fits without hashing.
     */
}
