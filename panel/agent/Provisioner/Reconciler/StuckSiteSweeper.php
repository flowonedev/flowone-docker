<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Exceptions\StateGuardFailed;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Recovers sites whose `actual_state` is wedged in an in-flight value
 * (`provisioning`, `deleting`, `restoring`) with no live `site_jobs`
 * row to drive the saga forward.
 *
 * Why this exists
 * ---------------
 * The provisioning bridge (ProvisioningSagaRunner) flips the site
 * row's `actual_state` to an in-flight value at the start of a saga
 * and to a terminal value at the end. Both transitions are
 * transactional and audited, so under normal operation the site
 * cannot be observed in an in-flight state without a matching live
 * job.
 *
 * In practice the row CAN drift out of band:
 *
 *   1. ProvisioningAction::actionEnqueueDelete pre-transitions the
 *      row to `deleting` BEFORE calling JobDispatcher::enqueue. If
 *      the enqueue throws (DB blip, audit table full, etc.) the row
 *      is left in `deleting` with no job to drive it.
 *
 *   2. The JobWorker catches uncaught throwables from the saga
 *      runner and finalises the JOB row, but does NOT roll the SITE
 *      row's actual_state back. A bug in a step that crashes the
 *      worker BEFORE the bridge's terminal transition leaves the
 *      site stranded.
 *
 *   3. A worker is killed mid-bridge-transition (between saga
 *      finishing and `deleting -> degraded` running). The DEAD
 *      LEASE sweeper requeues the JOB but the SITE row is still
 *      in-flight. If the requeued job is later cancelled by an
 *      operator, the site stays in-flight forever.
 *
 *   4. An operator runs raw SQL to flip a state and forgets to
 *      enqueue a saga. Hands up: this happens.
 *
 *   5. A CREATE saga fails terminally while the row never left
 *      `absent` (historically possible via the createInProvisioning
 *      UPSERT that didn't reset actual_state on duplicate rows;
 *      production hit exactly this with test.com, which sat at
 *      desired=active / actual=absent for 11 days). The row is
 *      invisible to every other safety net: DriftAssessor sees
 *      "absent, nothing to do" and the in-flight sweep below only
 *      looks at in-flight states. We sweep these "orphaned create"
 *      rows into `failed` when their LATEST job is a dead CREATE.
 *
 * The DriftAssessor explicitly SKIPs in-flight states (rule 2 in
 * DriftAssessor::assess) so the existing reconciler tick never sees
 * these rows. We need a separate sweep with different rules.
 *
 * What we do
 * ----------
 *   - Find sites with `actual_state IN ('provisioning','deleting',
 *     'restoring')` whose `updated_at` is older than $graceSeconds.
 *   - For each, check the `site_jobs` table: if there is ANY job for
 *     this domain in `queued` or `running` status, leave it alone.
 *     The saga is genuinely in flight; not our problem.
 *   - Otherwise the site is wedged. Map the in-flight state to its
 *     canonical "stuck" landing using the saga direction's FAILED
 *     terminal:
 *
 *       provisioning -> degraded   (CREATE.FAILED is `failed`, but
 *                                   `degraded` is the safer landing
 *                                   when we don't know how far the
 *                                   saga got: it preserves any
 *                                   partial artifacts for operator
 *                                   review.)
 *       deleting     -> degraded   (DELETE.FAILED maps here too.)
 *       restoring    -> failed     (RESTORE.FAILED canonical landing;
 *                                   FSM allows it.)
 *
 *     Important: we deliberately use the FAILED-style landing rather
 *     than the SUCCESS landing. Optimistically transitioning to
 *     `active` / `absent` could mask a half-done saga and silently
 *     promote a broken site to "healthy".
 *
 *   - Each recovery is wrapped in SiteStateMachine::transition so we
 *     get the same atomic UPDATE + audit-log row guarantee a normal
 *     transition has.
 *
 * Concurrency
 * -----------
 * The sweep is idempotent. SiteStateMachine guards each transition
 * with a `WHERE actual_state = :from` clause; if a real saga
 * concurrently flips the state, our UPDATE matches zero rows and
 * StateGuardFailed surfaces - we count that as a SKIP and move on.
 *
 * Schedule
 * --------
 * Run from a systemd timer alongside `reconcile-sites`. The grace
 * period exists so a saga that's about to commit its terminal
 * transition isn't yanked out from under itself; 5 minutes is a
 * conservative default.
 */
final class StuckSiteSweeper
{
    /**
     * Default grace: how long the row must have been wedged before
     * we'll touch it. Tuned to a multiple of the longest saga step
     * timeout we expect (DNS propagation excluded - those use
     * `pending_dns`, which is NOT swept).
     */
    public const DEFAULT_GRACE_SECONDS = 300;

    /**
     * In-flight states we consider sweep candidates. `pending_dns`
     * is intentionally absent: it can legitimately sit for hours
     * waiting for DNS propagation, with no job.
     *
     * @var list<string>
     */
    private const SWEEPABLE_STATES = ['provisioning', 'deleting', 'restoring'];

    private readonly ActorContext $actor;

    public function __construct(
        private readonly PanelDatabase $database,
        private readonly SiteStateMachine $stateMachine,
        private readonly AuditLogger $audit,
        private readonly int $graceSeconds = self::DEFAULT_GRACE_SECONDS,
        ?ActorContext $actor = null
    ) {
        if ($graceSeconds < 0) {
            throw new \InvalidArgumentException('graceSeconds must be >= 0');
        }
        $this->actor = $actor ?? ActorContext::system('stuck-site-sweeper');
    }

    /**
     * Find candidate rows + recover them. Returns the result.
     */
    public function sweep(?int $limit = null, bool $dryRun = false): StuckSiteSweepResult
    {
        $started = microtime(true);
        $candidates = array_merge(
            $this->findCandidates($limit),
            $this->findOrphanedCreateCandidates($limit),
        );
        $recovered = 0;
        $skipped = 0;
        $recoveries = [];

        foreach ($candidates as $row) {
            $domain = (string) ($row['domain'] ?? '');
            $from = (string) ($row['actual_state'] ?? '');
            $landing = $this->landingFor($from);
            if ($landing === null) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $recoveries[] = [
                    'site_id' => (int) $row['id'],
                    'domain' => $domain,
                    'from' => $from,
                    'to' => $landing,
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'dry_run' => true,
                ];
                continue;
            }

            try {
                $this->stateMachine->transition(
                    siteId: (int) $row['id'],
                    from: $from,
                    to: $landing,
                    reason: "stuck-site-sweeper: in-flight state '{$from}' for >{$this->graceSeconds}s "
                        . "with no live site_jobs row; landing in '{$landing}' for operator review",
                    actor: $this->actor,
                );
                $recovered++;
                $recoveries[] = [
                    'site_id' => (int) $row['id'],
                    'domain' => $domain,
                    'from' => $from,
                    'to' => $landing,
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'dry_run' => false,
                ];
            } catch (StateGuardFailed | InvalidStateTransition $e) {
                // Lost the race against a real saga, or the FSM
                // refused (which would be a bug in the landing map -
                // log loudly).
                $skipped++;
                try {
                    $this->audit->record(
                        action: 'stuck_site_skip',
                        siteDomain: $domain,
                        reason: 'sweeper could not transition: ' . $e->getMessage(),
                        before: ['actual_state' => $from],
                        after: ['actual_state' => $from],
                        actor: $this->actor,
                    );
                } catch (\Throwable) {
                    // ignore - audit problems shouldn't break the sweep
                }
            }
        }

        return new StuckSiteSweepResult(
            scanned: count($candidates),
            recovered: $recovered,
            skipped: $skipped,
            recoveries: $recoveries,
            elapsedMs: (int) max(0, round((microtime(true) - $started) * 1000)),
            dryRun: $dryRun,
        );
    }

    /**
     * Inspect-only variant. Useful for dashboards and the CLI's
     * --list mode.
     *
     * @return list<array<string,mixed>>
     */
    public function listCandidates(?int $limit = null): array
    {
        return array_merge(
            $this->findCandidates($limit),
            $this->findOrphanedCreateCandidates($limit),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findCandidates(?int $limit): array
    {
        // Anti-join against site_jobs: candidate rows are sites in
        // an in-flight state whose updated_at is older than the
        // grace period AND have no queued/running job for the same
        // domain. NOT EXISTS keeps the query independent of how many
        // historical jobs the site has accumulated.
        $placeholders = [];
        $params = ['grace' => $this->graceSeconds];
        foreach (self::SWEEPABLE_STATES as $i => $state) {
            $key = "state_{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $state;
        }
        foreach ([JobStatus::QUEUED, JobStatus::RUNNING] as $i => $status) {
            $key = "active_status_{$i}";
            $params[$key] = $status->value;
        }

        $sql = 'SELECT s.id, s.domain, s.actual_state, s.updated_at
                  FROM sites s
                 WHERE s.actual_state IN (' . implode(',', $placeholders) . ')
                   AND s.updated_at < DATE_SUB(NOW(), INTERVAL :grace SECOND)
                   AND NOT EXISTS (
                         SELECT 1 FROM site_jobs j
                          WHERE j.site_domain = s.domain
                            AND j.status IN (:active_status_0, :active_status_1)
                       )
                 ORDER BY s.updated_at ASC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Orphaned-create rows: actual_state='absent' but the LATEST job
     * for the domain is a CREATE that is no longer live (failed /
     * cancelled / timed out). A legit post-delete tombstone has a
     * DELETE as its latest job, and a row with NO jobs at all is left
     * alone (could be a manual insert the operator is mid-way through
     * wiring up). The NOT EXISTS live-job clause mirrors the in-flight
     * sweep so a CREATE that is queued-but-not-started is never
     * touched.
     *
     * Landing is `failed` (legal via the absent->failed FSM edge), so
     * the row shows up in the panel's failed filter where the operator
     * can retry or purge it - instead of being invisible in every
     * view, which is how test.com survived 11 days unnoticed.
     *
     * @return list<array<string,mixed>>
     */
    private function findOrphanedCreateCandidates(?int $limit): array
    {
        $sql = "SELECT s.id, s.domain, s.actual_state, s.updated_at
                  FROM sites s
                 WHERE s.actual_state = 'absent'
                   AND s.updated_at < DATE_SUB(NOW(), INTERVAL :grace SECOND)
                   AND NOT EXISTS (
                         SELECT 1 FROM site_jobs j
                          WHERE j.site_domain = s.domain
                            AND j.status IN (:active_status_0, :active_status_1)
                       )
                   AND (
                         SELECT j2.type FROM site_jobs j2
                          WHERE j2.site_domain = s.domain
                          ORDER BY j2.id DESC
                          LIMIT 1
                       ) = :create_type
                 ORDER BY s.updated_at ASC";
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute([
            'grace' => $this->graceSeconds,
            'active_status_0' => JobStatus::QUEUED->value,
            'active_status_1' => JobStatus::RUNNING->value,
            'create_type' => \VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobType::CREATE->value,
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Map an in-flight state to its canonical "stuck" landing. We
     * deliberately prefer FAILED-style landings to preserve any
     * partial artifacts for operator review.
     *
     * `absent` only reaches this map via findOrphanedCreateCandidates
     * (latest job = dead CREATE) - a legit tombstone never becomes a
     * candidate in the first place.
     */
    private function landingFor(string $inFlight): ?string
    {
        return match ($inFlight) {
            'provisioning' => 'degraded',
            'deleting'     => 'degraded',
            'restoring'    => 'failed',
            'absent'       => 'failed',
            default        => null,
        };
    }
}
