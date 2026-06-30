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
 * Create the per-site MariaDB database.
 *
 * Inputs:
 *   - payload['db_name']:  explicit DB name (preferred)
 *   - derived default:     `flowone_<sanitized-domain>` truncated to 64
 *
 * Persisted output:
 *   - data['db_name']     final DB name we created
 *   - data['db_charset']  charset used (always utf8mb4)
 *   - data['db_collate']  collation (utf8mb4_unicode_ci)
 *
 * Compensation: DEGRADE_ONLY.
 *
 *   The user's site is allowed to have a database with their data in
 *   it. If we created the DB here, and a *later* step in the same saga
 *   fails (say GrantStep), we MUST NOT silently drop the database -
 *   it might contain seed data the user expects to keep.
 *
 *   Instead, compensate() is a no-op that records "did not roll back
 *   DB; site moved to degraded state". The orchestrator picks this up
 *   and parks the site in `sites.state = degraded`, awaiting operator
 *   review. The explicit DatabaseDropStep in the delete saga is the
 *   only path that ever destroys a DB.
 */
final class DatabaseCreateStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::DATABASE_CREATE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $mysql = $ctx->requireAdapters()->mysql;
        $name = $this->resolveDbName($ctx, $state);
        return $mysql->databaseExists($name);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $mysql = $ctx->requireAdapters()->mysql;
        $name = $this->resolveDbName($ctx, $state);

        $events = [StepEvent::info('creating database', ['db' => $name])];

        try {
            $created = $mysql->createDatabase($name);
        } catch (\Throwable $e) {
            $msg = "createDatabase failed: " . $e->getMessage();
            if ($hint = MysqlAdminCredentials::privilegeHint($e)) {
                $msg .= ' | hint: ' . $hint;
            }
            return StepResult::failure(
                $state->mergeData(['db_name' => $name]),
                $msg,
                $events,
            );
        }

        $events[] = $created
            ? StepEvent::info('database created', ['db' => $name])
            : StepEvent::info('database already present, no-op', ['db' => $name]);

        return StepResult::success(
            $state->mergeData([
                'db_name' => $name,
                'db_charset' => 'utf8mb4',
                'db_collate' => 'utf8mb4_unicode_ci',
            ])->withCompleted(),
            $events,
            ['created' => $created ? 1 : 0],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $name = $state->data['db_name'] ?? null;
        // DEGRADE_ONLY: we never drop the DB on compensate. The
        // orchestrator reads compensationPolicy() and parks the site
        // in degraded state. compensate() runs purely so the saga has a
        // matched return and the event log shows why we didn't roll back.
        $events = [StepEvent::warning(
            'compensate: database NOT dropped (DEGRADE_ONLY policy); site will enter degraded state',
            ['db' => $name]
        )];
        return StepResult::success($state, $events);
    }

    private function resolveDbName(SiteContext $ctx, StepState $state): string
    {
        if (!empty($state->data['db_name']) && is_string($state->data['db_name'])) {
            return $state->data['db_name'];
        }
        $payload = $ctx->payload;
        if (!empty($payload['db_name']) && is_string($payload['db_name'])) {
            return $payload['db_name'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['db_name']) && is_string($row['db_name'])) {
            return $row['db_name'];
        }
        return $this->deriveFromDomain($ctx->domain());
    }

    /**
     * MariaDB allows up to 64 chars for DB names. Strategy:
     *   - lowercase
     *   - replace non-[a-z0-9_] with underscores
     *   - prefix "flowone_"
     *   - if > 64 chars, truncate + append 6-char stable hash
     */
    private function deriveFromDomain(string $domain): string
    {
        // Single source of truth - shared with DatabaseGrantStep's
        // fallback so the grant always lands on the DB this step
        // actually creates.
        return ResourceNameDeriver::dbName($domain);
    }
}
