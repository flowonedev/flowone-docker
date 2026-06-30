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
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * DROP DATABASE <db>. Inverse of DatabaseCreateStep.
 *
 * Runs near the start of the DELETE saga, AFTER PreDeleteSnapshot
 * captures a mysqldump.
 *
 * Safety: SAGA ORDER is the safety mechanism. PreDeleteSnapshotStep
 *   runs first; if it fails (or is omitted by an operator running a
 *   custom saga) and the orchestrator does not abort, this step
 *   proceeds. Cross-step state inspection is not yet a SiteContext
 *   primitive (see step4c roadmap), so the step itself does not
 *   second-guess the saga.
 *
 * Resolution order for the DB name (must match DatabaseCreateStep):
 *
 *   1. state.data['db_name']                - within-saga cache
 *   2. payload['db_name']                   - operator override
 *   3. siteRow['db_name']                   - denormalized column
 *   4. CREATE state map[database_create]['data']['db_name']
 *   5. derived from domain (`flowone_<sanitized>`)
 *
 * Panel bookkeeping:
 *   The panel's `database_links` table maps db_name <-> domain for
 *   the Databases UI. Rows for the dropped DB - and any other link
 *   rows for this domain - are deleted here too; a link to a domain
 *   being deleted is dead weight, and orphaned link rows were a
 *   confirmed production leftover (testsite.hu, June 2026). Cleanup
 *   runs even when the DB itself is already absent, because that is
 *   exactly the orphan case.
 *
 * Idempotence:
 *   - check() returns true iff the DB does NOT exist AND no
 *     database_links row remains for the db/domain.
 *   - execute() short-circuits the DROP when the DB is already gone
 *     but still sweeps the link rows.
 *
 * Compensation: DEGRADE_ONLY.
 *   Re-creating the DB does not restore its content. Restoring from
 *   the mysqldump is a separate operator-driven flow.
 */
final class DatabaseDropStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::DATABASE_DROP;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        try {
            $name = $this->resolveDbName($ctx, $state);
        } catch (\Throwable) {
            return true;
        }
        $mysql = $ctx->requireAdapters()->mysql;
        if ($mysql->databaseExists($name)) {
            return false;
        }
        return !$this->linkRowsExist($ctx, $name);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $mysql = $ctx->requireAdapters()->mysql;
        try {
            $name = $this->resolveDbName($ctx, $state);
        } catch (\Throwable $e) {
            return StepResult::success(
                $state->withCompleted(),
                [StepEvent::warning(
                    'no DB name could be resolved for this site; skipping DROP DATABASE',
                    ['error' => $e->getMessage()]
                )],
                ['removed' => 0],
            );
        }

        $events = [StepEvent::info('checking database for drop', ['db' => $name])];

        if (!$mysql->databaseExists($name)) {
            $events[] = StepEvent::info('database already absent, no-op', ['db' => $name]);
            $this->removeLinkRows($ctx, $name, $events);
            return StepResult::success(
                $state->mergeData(['db_name' => $name, 'removed' => false])->withCompleted(),
                $events,
                ['removed' => 0],
            );
        }

        try {
            $dropped = $mysql->dropDatabase($name);
        } catch (\Throwable $e) {
            $msg = "dropDatabase failed: " . $e->getMessage();
            if ($hint = MysqlAdminCredentials::privilegeHint($e)) {
                $msg .= ' | hint: ' . $hint;
            }
            return StepResult::failure(
                $state->mergeData(['db_name' => $name]),
                $msg,
                $events,
            );
        }

        // Verify the drop actually took effect. dropDatabase() can
        // return without throwing yet leave the schema in place if the
        // admin connection lacks DROP privilege on it (some MariaDB
        // setups swallow the error) - re-probe so a silent no-op turns
        // into a real failure instead of a false "absent" landing.
        if ($mysql->databaseExists($name)) {
            return StepResult::failure(
                $state->mergeData(['db_name' => $name]),
                "DROP DATABASE reported no error but '{$name}' still exists. "
                    . 'The MysqlAdapter account likely lacks DROP privilege on it. '
                    . 'Set "database_admin" in /var/www/vps-admin/api/config.local.php '
                    . 'or place admin credentials in /root/.my.cnf.',
                $events,
            );
        }

        $events[] = $dropped
            ? StepEvent::info('database dropped', ['db' => $name])
            : StepEvent::info('database already absent (race), no-op', ['db' => $name]);

        $this->removeLinkRows($ctx, $name, $events);

        return StepResult::success(
            $state->mergeData([
                'db_name' => $name,
                'removed' => $dropped,
            ])->withCompleted(),
            $events,
            ['removed' => $dropped ? 1 : 0],
        );
    }

    /**
     * Sweep panel `database_links` rows for the dropped DB and for the
     * site's domain. Best-effort: the table may not exist on minimal
     * installs and a bookkeeping miss must not fail the saga -
     * validateDeletion reports stragglers.
     *
     * @param list<StepEvent> $events Appended by reference.
     */
    private function removeLinkRows(SiteContext $ctx, string $dbName, array &$events): void
    {
        try {
            $pdo = $ctx->database->pdo();
            $stmt = $pdo->prepare("DELETE FROM database_links WHERE db_name = ? OR domain = ?");
            $stmt->execute([$dbName, $ctx->domain()]);
            if ($stmt->rowCount() > 0) {
                $events[] = StepEvent::info('database_links rows removed', [
                    'db' => $dbName,
                    'domain' => $ctx->domain(),
                    'rows' => $stmt->rowCount(),
                ]);
            }
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning('database_links cleanup skipped', [
                'db' => $dbName,
                'detail' => $e->getMessage(),
            ]);
        }
    }

    private function linkRowsExist(SiteContext $ctx, string $dbName): bool
    {
        try {
            $stmt = $ctx->database->pdo()->prepare(
                "SELECT id FROM database_links WHERE db_name = ? OR domain = ? LIMIT 1"
            );
            $stmt->execute([$dbName, $ctx->domain()]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $name = $state->data['db_name'] ?? null;
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: database NOT recreated (DEGRADE_ONLY); restore from snapshot mysqldump',
                ['db' => $name]
            )]
        );
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
        $fromCreate = $this->lookupFromCreateState($ctx, StepName::DATABASE_CREATE, 'db_name');
        if ($fromCreate !== null) {
            return $fromCreate;
        }
        // Last resort: derive the name the same way DatabaseCreateStep
        // and PreDeleteSnapshotStep do (`flowone_<sanitized-domain>`).
        //
        // The earlier version of this step refused to derive, to avoid
        // dropping a DB that an operator had manually created under our
        // naming convention for an unrelated site. In practice that
        // caution backfired: the snapshot step DOES derive and dumps the
        // DB, then this step skipped the drop and reported success,
        // leaving the schema behind ("rogue" databases after every
        // delete). Two guardrails make deriving safe here:
        //   1. The derived name lives in the `flowone_` namespace, which
        //      this system owns by convention.
        //   2. PreDeleteSnapshotStep already captured a mysqldump of this
        //      exact DB earlier in the saga, so even an unexpected match
        //      is recoverable from the snapshot.
        // execute() still probes databaseExists() before dropping, so a
        // site that never had a DB is a clean no-op.
        return ResourceNameDeriver::dbName($ctx->domain());
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
}
