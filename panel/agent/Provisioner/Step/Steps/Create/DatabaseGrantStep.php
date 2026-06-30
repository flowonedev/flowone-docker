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
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * GRANT ALL PRIVILEGES ON <db>.* TO <user>@<host>.
 *
 * Reads the db_name from DatabaseCreateStep's state and the
 * user/host from DatabaseUserCreateStep's state.
 *
 * Idempotence:
 *   - check() runs a SHOW GRANTS and looks for the desired grant line.
 *     This avoids running GRANT a second time (which is harmless but
 *     trips audit triggers in some MariaDB setups).
 *   - execute() always issues the GRANT; MariaDB itself is idempotent
 *     on identical grants.
 *
 * Compensation: SAFE_ROLLBACK.
 *   - REVOKE is logically the inverse of GRANT. We REVOKE only the
 *     privileges we granted, on the specific database. Other grants
 *     attached to the user (if any) are untouched.
 *   - This is safe because the user was created in the same saga;
 *     no production traffic depends on these grants yet.
 */
final class DatabaseGrantStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::DATABASE_GRANT;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        try {
            $bundle = $this->resolveBundle($ctx, $state);
        } catch (\Throwable) {
            return false;
        }
        return $this->grantExists(
            $ctx,
            $bundle['db_name'],
            $bundle['user'],
            $bundle['host'],
        );
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $mysql = $ctx->requireAdapters()->mysql;
        try {
            $bundle = $this->resolveBundle($ctx, $state);
        } catch (\Throwable $e) {
            return StepResult::failure($state, $e->getMessage());
        }

        $events = [StepEvent::info('granting ALL on database', [
            'db' => $bundle['db_name'],
            'user' => $bundle['user'],
            'host' => $bundle['host'],
        ])];

        try {
            $mysql->grantAllOnDatabase(
                $bundle['db_name'],
                $bundle['user'],
                $bundle['host'],
            );
        } catch (\Throwable $e) {
            $msg = "GRANT failed: " . $e->getMessage();
            if ($hint = MysqlAdminCredentials::privilegeHint($e)) {
                $msg .= ' | hint: ' . $hint;
            }
            return StepResult::failure(
                $state->mergeData($bundle),
                $msg,
                $events,
            );
        }

        $events[] = StepEvent::info('grant complete');
        return StepResult::success(
            $state->mergeData($bundle)->withCompleted(),
            $events,
        );
    }

    /**
     * Overridden so that when SHOW GRANTS doesn't show what we just
     * granted, the audit trail captures the actual rows MariaDB
     * returned. Without this, "verify(): check() returned false
     * after execute()" gives the operator nothing to debug with.
     */
    public function verify(SiteContext $ctx, StepState $state): StepResult
    {
        try {
            $bundle = $this->resolveBundle($ctx, $state);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "verify(): could not resolve bundle: " . $e->getMessage()
            );
        }
        $mysql = $ctx->requireAdapters()->mysql;
        $inspection = $mysql->grantInspection(
            $bundle['db_name'],
            $bundle['user'],
            $bundle['host'],
        );
        if ($inspection['has_all']) {
            return StepResult::success($state);
        }
        // Failure path: emit the raw SHOW GRANTS dump so a future
        // mis-parse is debuggable from the saga audit log alone.
        $events = [StepEvent::error('verify(): hasAllPrivilegesOn returned false', [
            'db' => $bundle['db_name'],
            'user' => $bundle['user'],
            'host' => $bundle['host'],
            'show_grants_raw' => $inspection['raw'],
            'show_grants_normalised' => $inspection['normalised'],
            'show_grants_error' => $inspection['error'],
        ])];
        return StepResult::failure(
            $state,
            'verify(): hasAllPrivilegesOn returned false after grant succeeded'
                . ' (raw SHOW GRANTS captured in audit log)',
            $events,
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $mysql = $ctx->requireAdapters()->mysql;
        $db = $state->data['db_name'] ?? null;
        $user = $state->data['user'] ?? null;
        $host = $state->data['host'] ?? 'localhost';
        if (!is_string($db) || !is_string($user) || $db === '' || $user === '') {
            return StepResult::success(
                $state, [StepEvent::info('compensate: no grant recorded, nothing to revoke')]
            );
        }
        $events = [StepEvent::info('compensate: revoking grants', [
            'db' => $db, 'user' => $user, 'host' => $host,
        ])];
        try {
            // Build a precise REVOKE matching the GRANT we issued, so
            // we don't strip other grants the user might also hold.
            $stmt = sprintf(
                "REVOKE ALL PRIVILEGES ON `%s`.* FROM %s@%s",
                $db,
                $this->quoteValue($user),
                $this->quoteValue($host),
            );
            $mysql->grantCustom($stmt);
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning(
                'compensate: REVOKE failed',
                ['error' => $e->getMessage()]
            );
        }
        return StepResult::success($state, $events);
    }

    /**
     * @return array{db_name: string, user: string, host: string}
     */
    private function resolveBundle(SiteContext $ctx, StepState $state): array
    {
        // Resolution order:
        //   1. local state (resume mid-saga)
        //   2. cross-step JSON in siteRow.state (forward-looking; not
        //      hydrated in production today)
        //   3. denormalised siteRow column
        //   4. explicit payload override
        //   5. shared derivation - byte-identical to what
        //      DatabaseCreateStep / DatabaseUserCreateStep produce when
        //      they fall through to their own derivation. This is the
        //      fallback that keeps the grant aligned with the actual
        //      CREATE result when none of the explicit signals are
        //      present (the Job #543-style regression).
        $db = $this->lookup($ctx, $state, StepName::DATABASE_CREATE, 'db_name')
            ?? ($ctx->siteRow['db_name'] ?? $ctx->payload['db_name'] ?? null);
        if (!is_string($db) || $db === '') {
            $db = ResourceNameDeriver::dbName($ctx->domain());
        }

        $user = $this->lookup($ctx, $state, StepName::DATABASE_USER_CREATE, 'user')
            ?? ($ctx->siteRow['db_user'] ?? $ctx->payload['db_user'] ?? null);
        if (!is_string($user) || $user === '') {
            $user = ResourceNameDeriver::dbUser($ctx->domain());
        }

        $host = $this->lookup($ctx, $state, StepName::DATABASE_USER_CREATE, 'host')
            ?? ($ctx->payload['db_host'] ?? 'localhost');

        return [
            'db_name' => $db,
            'user' => $user,
            'host' => is_string($host) && $host !== '' ? $host : 'localhost',
        ];
    }

    private function lookup(SiteContext $ctx, StepState $state, string $stepName, string $key): ?string
    {
        // 1. local state (resume)
        if (!empty($state->data[$key]) && is_string($state->data[$key])) {
            return $state->data[$key];
        }
        // 2. site_state JSON from earlier steps
        $stateJson = $ctx->siteRow['state'] ?? null;
        $map = is_string($stateJson) ? json_decode($stateJson, true) : (is_array($stateJson) ? $stateJson : null);
        if (is_array($map)
            && isset($map[$stepName]['data'][$key])
            && is_string($map[$stepName]['data'][$key])
        ) {
            return $map[$stepName]['data'][$key];
        }
        return null;
    }

    private function grantExists(SiteContext $ctx, string $db, string $user, string $host): bool
    {
        return $ctx->requireAdapters()->mysql->hasAllPrivilegesOn($db, $user, $host);
    }

    /**
     * Conservative single-quote escape for values we splice into a
     * grantCustom() string. MySQL identifiers (db names) are guarded
     * by assertSafeName(); these are values like usernames + hosts.
     */
    private function quoteValue(string $v): string
    {
        $v = str_replace("'", "''", $v);
        return "'" . $v . "'";
    }
}
