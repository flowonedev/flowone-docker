<?php
/**
 * Provisioning Action Handler
 *
 * Exposes the asynchronous site-provisioning queue to HTTP callers
 * through the agent socket. Every method here is a thin transport over
 * a Provisioner service:
 *
 *   enqueueCreate   -> SiteStateMachine::createInProvisioning + JobDispatcher::enqueue(CREATE)
 *   enqueueDelete   -> SiteStateMachine::transition(active->deleting) + JobDispatcher::enqueue(DELETE)
 *   listSites       -> SELECT FROM sites (filtered + paginated)
 *   getSite         -> SELECT FROM sites + recent jobs + audit summary
 *   listJobs        -> SELECT FROM site_jobs (filtered + paginated)
 *   getJob          -> SELECT FROM site_jobs + site_step_executions + site_job_events tail
 *   getJobEvents    -> SELECT FROM site_job_events after a given id (SSE-friendly tail)
 *   cancelJob       -> JobDispatcher::cancel
 *   retryJob        -> Enqueue a RETRY job referencing the failed parent
 *
 * Why this lives next to the legacy VhostAction:
 *   - The legacy synchronous flow (VhostAction::create/delete) suffered a
 *     ~50% success rate because every step ran inline behind a single
 *     long-lived socket call. The queue-based flow keeps the HTTP
 *     request short and survives transient failures via retry+sweeper.
 *   - We intentionally keep BOTH paths reachable during migration. The
 *     legacy paths stay working for callers that haven't switched; the
 *     new /api/sites/v2 endpoints hit this action.
 *
 * Auth + RBAC:
 *   - The agent socket already required a token; this action additionally
 *     requires the caller to pass `actor_user_id` and (optionally) the
 *     `source_ip` so the dispatcher's audit row points to a real user.
 *   - The panel API controller is responsible for validating the JWT and
 *     filling those fields. The agent does NOT re-check JWTs.
 *
 * Output contract:
 *   - Every method returns `['success' => bool, 'data' => array|null, 'error' => string|null]`
 *     so the caller can treat the response shape identically to other
 *     agent actions (BaseAction::success / ::error).
 *   - All site_jobs / sites rows are passed through SecretMasker before
 *     returning so we never leak the (already-masked-on-insert) payload
 *     in the unlikely case an upstream caller forgets to mask.
 */

declare(strict_types=1);

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Exceptions\LockAcquisitionFailed;
use VpsAdmin\Agent\Provisioner\Exceptions\StateGuardFailed;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobDispatcher;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobPriorityClass;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobType;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\SiteJob;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SiteLock;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

final class ProvisioningAction extends BaseAction
{
    /** Max page size for list endpoints. Higher gets clamped silently. */
    private const MAX_PAGE_SIZE = 200;

    /** Default page size when caller omits ?per_page= */
    private const DEFAULT_PAGE_SIZE = 50;

    /** Event tail size for getJob detail responses. */
    private const JOB_EVENT_TAIL = 50;

    /** Max events returned per getJobEvents poll. */
    private const MAX_EVENT_TAIL = 500;

    private ?PanelDatabase $db = null;
    private ?SecretMasker $masker = null;
    private ?AuditLogger $audit = null;
    private ?JobDispatcher $dispatcher = null;
    private ?SiteStateMachine $stateMachine = null;
    private ?SiteLock $siteLock = null;

    public function getNamespace(): string
    {
        return 'provisioning';
    }

    public function getMethods(): array
    {
        return [
            'enqueueCreate',
            'enqueueDelete',
            'enqueueSuspend',
            'enqueueResume',
            'enqueueArchive',
            'enqueueRestore',
            'purgeTombstone',
            'listSites',
            'getSite',
            'listArchives',
            'listJobs',
            'getJob',
            'getJobEvents',
            'cancelJob',
            'retryJob',
        ];
    }

    /** Where the delete saga writes snapshots. Mirrors PreDeleteSnapshotStep. */
    private const SNAPSHOT_ROOT = '/var/www/vps-admin/storage/snapshots';

    /** Where the archive saga promotes snapshots. Mirrors ArchivePromoteStep. */
    private const ARCHIVE_ROOT = '/var/www/vps-admin/storage/archives';

    public function requiresBackup(string $method): bool
    {
        // The queue itself is the durable record. Adding a backup layer
        // here would only duplicate state and slow down hot writes.
        return false;
    }

    // ──────────────────────────────────────────────────────────────
    // mutating endpoints
    // ──────────────────────────────────────────────────────────────

    /**
     * Insert a sites row (or upsert config on duplicate) and enqueue a CREATE
     * job. Returns the freshly inserted job summary. Idempotent if the same
     * domain is posted twice in QUEUED state: the second call returns the
     * existing queued job rather than creating a duplicate.
     */
    public function actionEnqueueCreate(array $params, string $actor): array
    {
        $domainErr = $this->requireDomain($params);
        if ($domainErr !== null) {
            return $this->error($domainErr);
        }
        $domain = (string) $params['domain'];
        $payload = $this->normalisePayload($params['payload'] ?? []);

        $this->bootstrapServices();

        $actorCtx = $this->actorFromParams($params, $actor);

        try {
            return $this->withEnqueueLock($domain, 'enqueueCreate', function () use ($domain, $payload, $params, $actorCtx): array {
                $existing = $this->findActiveJobForDomain($domain, JobType::CREATE);
                if ($existing !== null) {
                    return $this->success([
                        'job' => $this->serialiseJob($existing),
                        'site' => $this->fetchSite($domain),
                        'duplicate' => true,
                    ], 'Existing CREATE job is still in flight');
                }

                // A DIFFERENT job type in flight (e.g. a DELETE saga mid-run)
                // means the infrastructure is actively mutating. Starting a
                // CREATE now would race the teardown step-for-step.
                $conflict = $this->findConflictingJobForDomain($domain, JobType::CREATE);
                if ($conflict !== null) {
                    return $this->error(
                        "Cannot enqueue CREATE for '{$domain}': a {$conflict->type->value} job "
                            . "(id {$conflict->id}) is still in flight. Wait for it to finish.",
                        ['code' => 'conflicting_job', 'job' => $this->serialiseJob($conflict)]
                    );
                }

                // Reject CREATE when the site already exists in a live state.
                // Without this guard the UPSERT in createInProvisioning would
                // flip a healthy site back to 'provisioning' and the saga
                // would re-run against live infrastructure. Tombstones and
                // parked failures (absent/failed/degraded) are legitimate
                // re-create targets and pass through.
                $existingSite = $this->fetchSite($domain);
                if ($existingSite !== null) {
                    $existingState = (string) ($existingSite['actual_state'] ?? '');
                    if (!in_array($existingState, ['absent', 'failed', 'degraded'], true)) {
                        return $this->error(
                            "Site '{$domain}' already exists (actual_state='{$existingState}'). "
                                . 'Delete it first, or retry once it is parked in a terminal state.',
                            ['code' => 'already_exists', 'actual_state' => $existingState]
                        );
                    }
                }

                $siteId = $this->stateMachine->createInProvisioning(
                    domain: $domain,
                    config: $payload,
                    actor: $actorCtx,
                );

                $job = $this->dispatcher->enqueue(
                    siteDomain: $domain,
                    type: JobType::CREATE,
                    payload: $payload,
                    actor: $actorCtx,
                    requestId: $params['request_id'] ?? null,
                    priority: $this->resolvePriority($params, 50),
                    priorityClass: JobPriorityClass::OPERATOR,
                    maxAttempts: max(1, (int) ($params['max_attempts'] ?? 3)),
                    dryRun: !empty($params['dry_run']),
                );

                return $this->success([
                    'job' => $this->serialiseJob($job),
                    'site_id' => $siteId,
                    'site' => $this->fetchSite($domain),
                    'duplicate' => false,
                ], 'CREATE job enqueued');
            });
        } catch (\Throwable $e) {
            $this->logger->error('enqueueCreate failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return $this->error('enqueueCreate failed: ' . $e->getMessage());
        }
    }

    /**
     * Enqueue a DELETE job for an existing site. The sites row MUST exist
     * (the worker reads it to build SiteContext); a 404-equivalent is
     * returned otherwise. If the site is in 'active' / 'failed' / 'degraded'
     * / 'suspended', we transition it to 'deleting' atomically before
     * enqueueing so concurrent readers see the intent immediately.
     */
    public function actionEnqueueDelete(array $params, string $actor): array
    {
        $domainErr = $this->requireDomain($params);
        if ($domainErr !== null) {
            return $this->error($domainErr);
        }
        $domain = (string) $params['domain'];
        $payload = $this->normalisePayload($params['payload'] ?? []);

        $this->bootstrapServices();
        $actorCtx = $this->actorFromParams($params, $actor);

        try {
            return $this->withEnqueueLock($domain, 'enqueueDelete', function () use ($domain, $payload, $params, $actorCtx): array {
            $site = $this->fetchSite($domain);
            if ($site === null) {
                return $this->error("Site '{$domain}' not found", ['code' => 'not_found']);
            }

            $existing = $this->findActiveJobForDomain($domain, JobType::DELETE);
            if ($existing !== null) {
                return $this->success([
                    'job' => $this->serialiseJob($existing),
                    'site' => $site,
                    'duplicate' => true,
                ], 'Existing DELETE job is still in flight');
            }

            $conflict = $this->findConflictingJobForDomain($domain, JobType::DELETE);
            if ($conflict !== null) {
                return $this->error(
                    "Cannot enqueue DELETE for '{$domain}': a {$conflict->type->value} job "
                        . "(id {$conflict->id}) is still in flight. Wait for it to finish.",
                    ['code' => 'conflicting_job', 'job' => $this->serialiseJob($conflict)]
                );
            }

            // Record the operator's intent. Without this every
            // successfully deleted site read as desired=active /
            // actual=absent forever - the same drift signature as a
            // genuine orphaned create, which is exactly the ambiguity
            // that let the June 2026 orphan sit unnoticed.
            $previousDesired = $this->setDesiredState((int) $site['id'], 'deleted');

            // Try to flip actual_state -> 'deleting' so a concurrent reader
            // sees the intent. Skip silently when the row is already in a
            // pre-delete or post-delete terminal state.
            //
            // CRITICAL: if we DO pre-transition successfully but the
            // subsequent dispatcher.enqueue throws, the site is left
            // wedged in 'deleting' with no job to drive it. We track
            // whether we actually pre-transitioned so the catch arm
            // below can attempt to roll the row back to its original
            // state. The StuckSiteSweeper would eventually catch this,
            // but rolling back here is faster and gives the operator
            // a clean error response.
            $current = (string) ($site['actual_state'] ?? '');
            $preTransitioned = false;
            if (in_array($current, ['active', 'failed', 'degraded', 'suspended'], true)) {
                try {
                    $this->stateMachine->transition(
                        siteId: (int) $site['id'],
                        from: $current,
                        to: 'deleting',
                        reason: 'delete requested by operator',
                        actor: $actorCtx,
                    );
                    $preTransitioned = true;
                } catch (InvalidStateTransition | StateGuardFailed $e) {
                    // Don't hard-fail - the worker will still run the saga.
                    $this->logger->warning('pre-enqueue transition failed', [
                        'domain' => $domain,
                        'from' => $current,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                $job = $this->dispatcher->enqueue(
                    siteDomain: $domain,
                    type: JobType::DELETE,
                    payload: $payload,
                    actor: $actorCtx,
                    requestId: $params['request_id'] ?? null,
                    priority: $this->resolvePriority($params, 50),
                    priorityClass: JobPriorityClass::OPERATOR,
                    maxAttempts: max(1, (int) ($params['max_attempts'] ?? 3)),
                    dryRun: !empty($params['dry_run']),
                );
            } catch (\Throwable $enqueueError) {
                // Roll the pre-transition back so we don't leave the
                // site stranded in 'deleting' with no job. The reverse
                // edge depends on the source state; we only attempt it
                // when the FSM allows it. If reversal also fails, the
                // sweeper will catch the row on its next tick.
                if ($previousDesired !== null) {
                    $this->setDesiredState((int) $site['id'], $previousDesired);
                }
                if ($preTransitioned && $this->stateMachine->canTransition('deleting', $current)) {
                    try {
                        $this->stateMachine->transition(
                            siteId: (int) $site['id'],
                            from: 'deleting',
                            to: $current,
                            reason: 'rollback after enqueue failure: '
                                . $enqueueError->getMessage(),
                            actor: $actorCtx,
                        );
                    } catch (\Throwable $rollbackError) {
                        $this->logger->error('failed to roll back pre-transition', [
                            'domain' => $domain,
                            'enqueue_error' => $enqueueError->getMessage(),
                            'rollback_error' => $rollbackError->getMessage(),
                        ]);
                    }
                }
                throw $enqueueError;
            }

            return $this->success([
                'job' => $this->serialiseJob($job),
                'site' => $this->fetchSite($domain),
                'duplicate' => false,
            ], 'DELETE job enqueued');
            });
        } catch (\Throwable $e) {
            $this->logger->error('enqueueDelete failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return $this->error('enqueueDelete failed: ' . $e->getMessage());
        }
    }

    /**
     * Enqueue a SUSPEND job for an existing active site. The site row
     * stays in 'active' during the saga (the runner's
     * SagaDirection::SUSPEND.inFlightState() == 'active') and flips
     * to 'suspended' on success. We do NOT pre-transition here.
     */
    public function actionEnqueueSuspend(array $params, string $actor): array
    {
        return $this->enqueueLifecycle(
            $params,
            $actor,
            JobType::SUSPEND,
            preTransitionTo: null,
            successMessage: 'SUSPEND job enqueued',
        );
    }

    /**
     * Enqueue a RESUME job for a suspended site. Source state must be
     * 'suspended'; we don't pre-transition because the saga assumes
     * the suspended vhost.conf is still in place on disk.
     */
    public function actionEnqueueResume(array $params, string $actor): array
    {
        return $this->enqueueLifecycle(
            $params,
            $actor,
            JobType::RESUME,
            preTransitionTo: null,
            successMessage: 'RESUME job enqueued',
        );
    }

    /**
     * Enqueue an ARCHIVE job. The saga snapshots data, promotes it
     * to the archive store, and tears down the live infrastructure.
     * Pre-transition to 'deleting' so concurrent readers see the
     * destructive intent immediately; the bridge flips the row to
     * 'archived' on success (or 'degraded' on failure).
     */
    public function actionEnqueueArchive(array $params, string $actor): array
    {
        return $this->enqueueLifecycle(
            $params,
            $actor,
            JobType::ARCHIVE,
            preTransitionTo: 'deleting',
            successMessage: 'ARCHIVE job enqueued',
        );
    }

    /**
     * Enqueue a RESTORE job. The site must be in 'archived' state
     * with a payload.archive_path pointing at a valid archive dir.
     * Pre-transition to 'restoring' so the operator UI shows the
     * in-flight indicator immediately.
     */
    public function actionEnqueueRestore(array $params, string $actor): array
    {
        return $this->enqueueLifecycle(
            $params,
            $actor,
            JobType::RESTORE,
            preTransitionTo: 'restoring',
            successMessage: 'RESTORE job enqueued',
        );
    }

    /**
     * Hard-delete a tombstone (a `sites` row whose lifecycle has
     * already landed in actual_state='absent' via the DELETE saga).
     * Removes:
     *   - the sites row itself
     *   - all dependent history (site_audit_log, site_jobs,
     *     site_job_events, site_step_executions)
     *   - the snapshot directory tree on disk
     *
     * This is the only way to fully forget a site from the panel
     * without shelling into the database. Operators reach it via the
     * v2 sites view, restricted to rows whose state is `absent`.
     *
     * Refuses to run unless `actual_state === 'absent'`. A live site
     * must go through the DELETE saga first - this endpoint is NOT a
     * shortcut around the snapshot + cleanup pipeline. That guarantee
     * is critical: it means we can never lose a live site to a
     * stray "purge" call.
     *
     * Idempotent: a second call after a successful purge returns
     * `not_found` (the row is gone, nothing to do).
     *
     * Params:
     *   - domain   (required)
     *   - dry_run  (optional, default false) - return counts only
     *
     * Audit:
     *   - The purge action writes a structured log entry BEFORE the
     *     DB mutations, because the DB rows it logs about are about
     *     to be wiped. The structured log lives in
     *     /var/www/vps-admin/backend/logs/php_errors.log and is the
     *     only durable forensic record of who purged what.
     */
    public function actionPurgeTombstone(array $params, string $actor): array
    {
        $domainErr = $this->requireDomain($params);
        if ($domainErr !== null) {
            return $this->error($domainErr);
        }
        $domain = (string) $params['domain'];
        $dryRun = !empty($params['dry_run']);

        $this->bootstrapServices();
        $actorCtx = $this->actorFromParams($params, $actor);

        try {
            $site = $this->fetchSite($domain);
            if ($site === null) {
                return $this->error(
                    "Site '{$domain}' not found",
                    ['code' => 'not_found']
                );
            }

            $actualState = (string) ($site['actual_state'] ?? '');
            if ($actualState !== 'absent') {
                return $this->error(
                    "Cannot purge '{$domain}': actual_state='{$actualState}'. "
                        . 'Only tombstone rows (actual_state=absent) may be purged. '
                        . 'Run the DELETE saga first.',
                    ['code' => 'not_a_tombstone', 'actual_state' => $actualState]
                );
            }

            $pdo = $this->db->pdo();

            $counts = [
                'site_audit_log' => $this->countRows($pdo, 'site_audit_log', 'site_domain', $domain),
                'site_job_events' => $this->countRows($pdo, 'site_job_events', 'site_domain', $domain),
                'site_step_executions' => $this->countRows($pdo, 'site_step_executions', 'site_domain', $domain),
                'site_jobs' => $this->countRows($pdo, 'site_jobs', 'site_domain', $domain),
                // Related panel registries that a partial delete saga can
                // strand (mail/DNS/database bookkeeping). A purge must
                // reclaim the domain COMPLETELY or the leftovers resurface
                // in the Mail Security / DNS / Databases views.
                'mail_domains' => $this->countRowsSafe($pdo, 'mail_domains', 'domain', $domain),
                'database_links' => $this->countRowsSafe($pdo, 'database_links', 'domain', $domain),
                'dns_zones' => $this->countDnsZones($pdo, $domain),
                'sites' => 1,
            ];
            $snapshotDir = self::SNAPSHOT_ROOT . '/' . $domain;
            $snapshotPresent = is_dir($snapshotDir);

            if ($dryRun) {
                return $this->success([
                    'domain' => $domain,
                    'site_id' => (int) ($site['id'] ?? 0),
                    'rows_to_delete' => $counts,
                    'snapshot_dir' => $snapshotDir,
                    'snapshot_present' => $snapshotPresent,
                    'dry_run' => true,
                ], 'Purge preview (no changes applied)');
            }

            // Forensic record BEFORE we wipe the audit table - this is the
            // only place an operator can later look to learn the purge
            // happened and who did it.
            $this->logger->warning('tombstone_purge applied', [
                'domain' => $domain,
                'site_id' => (int) ($site['id'] ?? 0),
                'actor_username' => $actorCtx->username,
                'actor_user_id' => $actorCtx->userId,
                'source_ip' => $actorCtx->sourceIp,
                'request_id' => $actorCtx->requestId,
                'rows_to_delete' => $counts,
                'snapshot_dir' => $snapshotDir,
                'snapshot_present' => $snapshotPresent,
            ]);

            $pdo->beginTransaction();
            try {
                // The order doesn't matter for correctness (no FKs
                // between these tables), but we drain dependents
                // first so a half-committed state never points to a
                // missing parent.
                $this->wipeByDomain($pdo, 'site_step_executions', 'site_domain', $domain);
                $this->wipeByDomain($pdo, 'site_job_events', 'site_domain', $domain);
                $this->wipeByDomain($pdo, 'site_audit_log', 'site_domain', $domain);
                $this->wipeByDomain($pdo, 'site_jobs', 'site_domain', $domain);
                // Belt-and-braces: only delete the sites row when it
                // is still a tombstone. If something flipped it back
                // to active in the millisecond between our load and
                // this query, we'd rather fail loudly than wipe a
                // live site.
                $stmt = $pdo->prepare(
                    "DELETE FROM sites WHERE domain = :domain AND actual_state = 'absent'"
                );
                $stmt->execute(['domain' => $domain]);
                if ($stmt->rowCount() !== 1) {
                    throw new \RuntimeException(
                        "sites row for '{$domain}' was modified concurrently; purge aborted"
                    );
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Related panel registries (mail / DNS / database links).
            // Best-effort and OUTSIDE the core transaction: these
            // tables have no FK ties to sites, may be absent on
            // minimal installs, and a miss here must not undo the
            // tombstone purge. validateDeletion reports stragglers.
            $related = $this->purgeRelatedRegistries($pdo, $domain);

            // Best-effort snapshot dir cleanup. We deliberately do
            // this OUTSIDE the DB transaction: if rm fails (NAS
            // hiccup, permission issue), the DB rows are still gone
            // and the operator can clean the dir by hand. A failure
            // here logs a warning but does NOT roll back the DB
            // purge - that would leave dangling rows referencing a
            // domain whose snapshots are also gone, which is worse.
            $snapshotRemoved = false;
            $snapshotError = null;
            if ($snapshotPresent) {
                $snapshotRemoved = $this->rmrf($snapshotDir);
                if (!$snapshotRemoved) {
                    $snapshotError = "rm -rf {$snapshotDir} failed (check perms / NAS mount)";
                    $this->logger->warning('tombstone_purge snapshot cleanup failed', [
                        'domain' => $domain,
                        'snapshot_dir' => $snapshotDir,
                    ]);
                }
            }

            return $this->success([
                'domain' => $domain,
                'rows_deleted' => $counts,
                'related_registries' => $related,
                'snapshot_dir' => $snapshotDir,
                'snapshot_removed' => $snapshotRemoved,
                'snapshot_error' => $snapshotError,
            ], "Tombstone for '{$domain}' purged");
        } catch (\Throwable $e) {
            $this->logger->error('purgeTombstone failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return $this->error('purgeTombstone failed: ' . $e->getMessage());
        }
    }

    private function countRows(\PDO $pdo, string $table, string $column, string $value): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :v");
        $stmt->execute(['v' => $value]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * countRows() that tolerates the table being absent (minimal
     * installs without the mail / database-link registries).
     */
    private function countRowsSafe(\PDO $pdo, string $table, string $column, string $value): int
    {
        try {
            return $this->countRows($pdo, $table, $column, $value);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Zones held for the domain across BOTH DNS table pairs: the
     * panel's dns_domains and the legacy native PowerDNS `domains`.
     */
    private function countDnsZones(\PDO $pdo, string $domain): int
    {
        $count = 0;
        foreach (['dns_domains', 'domains'] as $table) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE name = :v");
                $stmt->execute(['v' => $domain]);
                $count += (int) $stmt->fetchColumn();
            } catch (\Throwable) {
                // table absent - fine
            }
        }
        return $count;
    }

    /**
     * Best-effort wipe of the mail / DNS / database-link registries a
     * partially-failed delete saga can strand. Each registry is
     * independent: one failing (or being absent) never blocks the
     * others. Returns a per-registry rows-removed map for the caller's
     * response payload.
     *
     * @return array<string, int|string>
     */
    private function purgeRelatedRegistries(\PDO $pdo, string $domain): array
    {
        $result = [];

        $wipes = [
            'mail_accounts' => ["DELETE FROM mail_accounts WHERE domain = ?", [$domain]],
            'mail_forwards' => ["DELETE FROM mail_forwards WHERE source_domain = ?", [$domain]],
            'mail_domains' => ["DELETE FROM mail_domains WHERE domain = ?", [$domain]],
            'database_links' => ["DELETE FROM database_links WHERE domain = ?", [$domain]],
            'dns_records' => [
                "DELETE FROM dns_records WHERE domain_id IN (SELECT id FROM dns_domains WHERE name = ?)",
                [$domain],
            ],
            'dns_domains' => ["DELETE FROM dns_domains WHERE name = ?", [$domain]],
            // Legacy native PowerDNS tables (migrated servers only).
            'pdns_records' => [
                "DELETE FROM records WHERE domain_id IN (SELECT id FROM domains WHERE name = ?)",
                [$domain],
            ],
            'pdns_domainmetadata' => [
                "DELETE FROM domainmetadata WHERE domain_id IN (SELECT id FROM domains WHERE name = ?)",
                [$domain],
            ],
            'pdns_domains' => ["DELETE FROM domains WHERE name = ?", [$domain]],
        ];

        foreach ($wipes as $label => [$sql, $args]) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($args);
                $result[$label] = $stmt->rowCount();
            } catch (\Throwable $e) {
                // Absent table or transient failure - record and move on.
                $result[$label] = 'skipped: ' . $e->getMessage();
            }
        }

        return $result;
    }

    private function wipeByDomain(\PDO $pdo, string $table, string $column, string $value): int
    {
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$column} = :v");
        $stmt->execute(['v' => $value]);
        return $stmt->rowCount();
    }

    /**
     * Recursive directory removal, scoped defensively to the
     * snapshot tree so a malformed call (or a path traversal in the
     * domain string, though requireDomain should already block that)
     * can never escape into the wider filesystem.
     */
    private function rmrf(string $path): bool
    {
        $realRoot = realpath(self::SNAPSHOT_ROOT);
        $realTarget = realpath($path);
        if ($realRoot === false || $realTarget === false) {
            return false;
        }
        if (!str_starts_with($realTarget . '/', $realRoot . '/')) {
            // Path escapes the snapshot root - refuse.
            return false;
        }
        if ($realTarget === $realRoot) {
            // Refuse to delete the root itself.
            return false;
        }
        return $this->removeTreeRecursive($realTarget);
    }

    private function removeTreeRecursive(string $path): bool
    {
        if (is_link($path) || !is_dir($path)) {
            return @unlink($path);
        }
        $items = @scandir($path);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $sub = $path . '/' . $item;
            if (is_link($sub) || !is_dir($sub)) {
                if (!@unlink($sub)) {
                    return false;
                }
            } elseif (!$this->removeTreeRecursive($sub)) {
                return false;
            }
        }
        return @rmdir($path);
    }

    /**
     * Shared enqueue pipeline used by SUSPEND / RESUME / ARCHIVE /
     * RESTORE. The differences between them are encoded in the
     * arguments, not duplicated in the call body.
     */
    private function enqueueLifecycle(
        array $params,
        string $actor,
        JobType $type,
        ?string $preTransitionTo,
        string $successMessage,
    ): array {
        $domainErr = $this->requireDomain($params);
        if ($domainErr !== null) {
            return $this->error($domainErr);
        }
        $domain = (string) $params['domain'];
        $payload = $this->normalisePayload($params['payload'] ?? []);

        $this->bootstrapServices();
        $actorCtx = $this->actorFromParams($params, $actor);

        try {
            return $this->withEnqueueLock($domain, "enqueue{$type->value}", function () use ($domain, $payload, $params, $actorCtx, $type, $preTransitionTo, $successMessage): array {
            $site = $this->fetchSite($domain);
            if ($site === null) {
                return $this->error("Site '{$domain}' not found", ['code' => 'not_found']);
            }

            $existing = $this->findActiveJobForDomain($domain, $type);
            if ($existing !== null) {
                return $this->success([
                    'job' => $this->serialiseJob($existing),
                    'site' => $site,
                    'duplicate' => true,
                ], "Existing {$type->value} job is still in flight");
            }

            $conflict = $this->findConflictingJobForDomain($domain, $type);
            if ($conflict !== null) {
                return $this->error(
                    "Cannot enqueue {$type->value} for '{$domain}': a {$conflict->type->value} job "
                        . "(id {$conflict->id}) is still in flight. Wait for it to finish.",
                    ['code' => 'conflicting_job', 'job' => $this->serialiseJob($conflict)]
                );
            }

            // Record the operator's intent (see actionEnqueueDelete).
            $desiredFor = match ($type) {
                JobType::SUSPEND => 'suspended',
                JobType::RESUME => 'active',
                JobType::ARCHIVE => 'archived',
                JobType::RESTORE => 'active',
                default => null,
            };
            $previousDesired = $desiredFor !== null
                ? $this->setDesiredState((int) $site['id'], $desiredFor)
                : null;

            // Track whether the pre-transition actually ran so we can
            // roll it back if the subsequent enqueue throws. See the
            // longer rationale in actionEnqueueDelete.
            $current = (string) ($site['actual_state'] ?? '');
            $preTransitioned = false;
            if ($preTransitionTo !== null) {
                if ($current !== $preTransitionTo
                    && $this->stateMachine->canTransition($current, $preTransitionTo)
                ) {
                    try {
                        $this->stateMachine->transition(
                            siteId: (int) $site['id'],
                            from: $current,
                            to: $preTransitionTo,
                            reason: "{$type->value} requested by operator",
                            actor: $actorCtx,
                        );
                        $preTransitioned = true;
                    } catch (InvalidStateTransition | StateGuardFailed $e) {
                        $this->logger->warning('pre-enqueue transition failed', [
                            'domain' => $domain,
                            'type' => $type->value,
                            'from' => $current,
                            'to' => $preTransitionTo,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            try {
                $job = $this->dispatcher->enqueue(
                    siteDomain: $domain,
                    type: $type,
                    payload: $payload,
                    actor: $actorCtx,
                    requestId: $params['request_id'] ?? null,
                    priority: $this->resolvePriority($params, 50),
                    priorityClass: JobPriorityClass::OPERATOR,
                    maxAttempts: max(1, (int) ($params['max_attempts'] ?? 3)),
                    dryRun: !empty($params['dry_run']),
                );
            } catch (\Throwable $enqueueError) {
                if ($previousDesired !== null) {
                    $this->setDesiredState((int) $site['id'], $previousDesired);
                }
                if ($preTransitioned
                    && $preTransitionTo !== null
                    && $this->stateMachine->canTransition($preTransitionTo, $current)
                ) {
                    try {
                        $this->stateMachine->transition(
                            siteId: (int) $site['id'],
                            from: $preTransitionTo,
                            to: $current,
                            reason: "rollback after {$type->value} enqueue failure: "
                                . $enqueueError->getMessage(),
                            actor: $actorCtx,
                        );
                    } catch (\Throwable $rollbackError) {
                        $this->logger->error('failed to roll back pre-transition', [
                            'domain' => $domain,
                            'type' => $type->value,
                            'enqueue_error' => $enqueueError->getMessage(),
                            'rollback_error' => $rollbackError->getMessage(),
                        ]);
                    }
                }
                throw $enqueueError;
            }

            return $this->success([
                'job' => $this->serialiseJob($job),
                'site' => $this->fetchSite($domain),
                'duplicate' => false,
            ], $successMessage);
            });
        } catch (\Throwable $e) {
            $this->logger->error("enqueue{$type->value} failed", [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return $this->error("enqueue{$type->value} failed: " . $e->getMessage());
        }
    }

    /**
     * Operator-initiated cancel. Only works while the job is QUEUED; running
     * jobs need to be allowed to finish their current step (the worker
     * re-reads status between steps).
     */
    public function actionCancelJob(array $params, string $actor): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->error('id is required');
        }
        $reason = trim((string) ($params['reason'] ?? 'cancelled via API'));

        $this->bootstrapServices();
        $actorCtx = $this->actorFromParams($params, $actor);

        try {
            $cancelled = $this->dispatcher->cancel($id, $reason, $actorCtx);
            if (!$cancelled) {
                $job = $this->dispatcher->getById($id);
                if ($job === null) {
                    return $this->error("Job {$id} not found", ['code' => 'not_found']);
                }
                return $this->error(
                    "Job {$id} is in status '{$job->status->value}' and cannot be cancelled",
                    ['code' => 'invalid_state', 'job' => $this->serialiseJob($job)]
                );
            }
            $job = $this->dispatcher->getById($id);
            return $this->success([
                'job' => $job !== null ? $this->serialiseJob($job) : null,
            ], 'Job cancelled');
        } catch (\Throwable $e) {
            return $this->error('cancelJob failed: ' . $e->getMessage());
        }
    }

    /**
     * Operator-initiated retry. Only allowed when the parent job is in a
     * terminal failed/cancelled state; succeeded jobs cannot be "retried"
     * (use a new CREATE/DELETE instead). The new job carries
     * parent_job_id pointing at the failed one so the timeline links.
     */
    public function actionRetryJob(array $params, string $actor): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->error('id is required');
        }
        $reason = trim((string) ($params['reason'] ?? 'retry requested via API'));

        $this->bootstrapServices();
        $actorCtx = $this->actorFromParams($params, $actor);

        try {
            $parent = $this->dispatcher->getById($id);
            if ($parent === null) {
                return $this->error("Job {$id} not found", ['code' => 'not_found']);
            }
            if (!in_array($parent->status, [JobStatus::FAILED, JobStatus::CANCELLED], true)) {
                return $this->error(
                    "Job {$id} is {$parent->status->value} and cannot be retried; only failed/cancelled jobs are retryable",
                    ['code' => 'invalid_state']
                );
            }

            // The new job uses the parent's payload and type. The
            // dispatcher's audit picks up the request_id and parent_job_id.
            $newJob = $this->dispatcher->enqueue(
                siteDomain: $parent->siteDomain,
                type: $parent->type,
                payload: $parent->payload,
                actor: $actorCtx,
                requestId: $parent->requestId,
                parentJobId: $parent->id,
                priority: $parent->priority,
                priorityClass: JobPriorityClass::OPERATOR,
                maxAttempts: max(1, $parent->maxAttempts),
                dryRun: $parent->dryRun,
            );

            $this->audit->record(
                action: 'job_retry_requested',
                siteDomain: $parent->siteDomain,
                reason: $reason,
                before: ['parent_job_id' => $parent->id, 'parent_status' => $parent->status->value],
                after: ['new_job_id' => $newJob->id, 'type' => $newJob->type->value],
                actor: $actorCtx,
                jobId: $newJob->id,
            );

            return $this->success([
                'job' => $this->serialiseJob($newJob),
                'parent' => $this->serialiseJob($parent),
            ], 'Retry job enqueued');
        } catch (\Throwable $e) {
            return $this->error('retryJob failed: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────
    // read endpoints
    // ──────────────────────────────────────────────────────────────

    public function actionListSites(array $params, string $actor): array
    {
        $this->bootstrapServices();
        [$page, $perPage] = $this->paginationFromParams($params);

        $filters = [];
        $args = [];

        if (!empty($params['actual_state'])) {
            $filters[] = 'actual_state = :actual_state';
            $args['actual_state'] = (string) $params['actual_state'];
        } else {
            // Default behavior: hide tombstones (rows whose lifecycle
            // landed in actual_state='absent' after a successful
            // delete saga). The DB row is preserved for audit /
            // snapshot reference, but the UI's "All states" view
            // should only show LIVE sites. An operator who wants to
            // see deleted sites must explicitly select `actual_state
            // = absent` from the filter dropdown, which falls into
            // the first branch above.
            $filters[] = "actual_state <> 'absent'";
        }
        if (!empty($params['desired_state'])) {
            $filters[] = 'desired_state = :desired_state';
            $args['desired_state'] = (string) $params['desired_state'];
        }
        if (!empty($params['search'])) {
            $filters[] = 'domain LIKE :search';
            $args['search'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $params['search']) . '%';
        }

        // The WHERE clause was built against the `sites` table without
        // an alias. To bolt on the LEFT JOIN below (which needs an
        // alias for both sides), rewrite any bare column references
        // to the `s.` prefix in the existing filter strings. This
        // keeps the named-placeholder bindings intact.
        //
        // Critical: the regex MUST NOT match column names that appear
        // inside a placeholder (e.g. `:actual_state`) or that are
        // already aliased (e.g. `s.actual_state`). The negative
        // lookbehind `(?<![:.\w])` guards against both:
        //   - `:` -> a PDO placeholder boundary; rewriting through it
        //            produces `:s.actual_state` which PDO parses as
        //            placeholder `:s` + literal `.actual_state`,
        //            yielding the SQL syntax error
        //            "near '.actual_state'".
        //   - `.` -> already-aliased form (`s.actual_state`); a second
        //            rewrite would produce `s.s.actual_state`.
        //   - `\w` -> some longer identifier that happens to end in
        //             `actual_state` (defensive; not currently
        //             exercised by any caller).
        $aliasedFilters = array_map(
            static fn(string $f) => preg_replace(
                '/(?<![:.\w])(actual_state|desired_state|domain)\b/',
                's.$1',
                $f
            ),
            $filters
        );
        $where = $aliasedFilters ? ('WHERE ' . implode(' AND ', $aliasedFilters)) : '';
        $pdo = $this->db->pdo();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sites s {$where}");
        $countStmt->execute($args);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        // LEFT JOIN template_deployments so every row carries
        // template_type / deployed_at / deployed_by / backup_file
        // when the operator has applied a template through the
        // legacy SystemAction endpoints. has_template_backup is
        // derived in serialiseSiteRow() from these columns.
        $sql = "SELECT s.*,
                       td.template_type     AS template_type,
                       td.deployed_at       AS template_deployed_at,
                       td.deployed_by       AS template_deployed_by,
                       td.backup_file       AS template_backup_file
                  FROM sites s
                  LEFT JOIN template_deployments td ON td.domain = s.domain
                  {$where}
                 ORDER BY s.updated_at DESC, s.id DESC
                 LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return $this->success([
            'sites' => array_map(fn(array $r) => $this->serialiseSiteRow($r), $rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ]);
    }

    public function actionGetSite(array $params, string $actor): array
    {
        $domainErr = $this->requireDomain($params);
        if ($domainErr !== null) {
            return $this->error($domainErr);
        }
        $domain = (string) $params['domain'];

        $this->bootstrapServices();
        $site = $this->fetchSite($domain);
        if ($site === null) {
            return $this->error("Site '{$domain}' not found", ['code' => 'not_found']);
        }

        $jobs = $this->dispatcher->listForDomain($domain, 20);
        $audit = $this->recentAuditForDomain($domain, 20);

        return $this->success([
            'site' => $site,
            'jobs' => array_map(fn(SiteJob $j) => $this->serialiseJob($j), $jobs),
            'audit' => $audit,
        ]);
    }

    /**
     * List archive directories on disk for a given domain (or all
     * domains when none specified). Each entry includes the absolute
     * path the restore saga needs, the timestamp embedded in the
     * directory name, the job id that produced the archive, and the
     * total size on disk. Returned newest-first so the operator sees
     * the most recent archive at the top of the picker.
     *
     * Archive paths live under {@see self::ARCHIVE_ROOT} arranged as:
     *   <archive_root>/<domain>/<YYYYMMDD-HHMMSS>-job<id>/
     * See {@see ArchivePromoteStep::archiveDir()} for the producer.
     *
     * Params:
     *   - domain (optional)  - filter to one site's archives
     *   - limit  (optional, default 25, max 200)
     *
     * Failure modes:
     *   - Archive root missing -> success with empty list (NAS not
     *     mounted yet is a reasonable state for a fresh box).
     *   - Domain dir missing  -> success with empty list.
     *   - Per-archive stat error -> entry is omitted from the result
     *     with a `partial=true` flag in the response so the UI can
     *     hint that something is wrong with one entry.
     */
    public function actionListArchives(array $params, string $actor): array
    {
        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $limit = isset($params['limit']) ? (int) $params['limit'] : 25;
        if ($limit < 1) {
            $limit = 25;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $root = self::ARCHIVE_ROOT;
        if (!is_dir($root) || !is_readable($root)) {
            return $this->success([
                'root' => $root,
                'domain' => $domain ?: null,
                'archives' => [],
                'count' => 0,
                'note' => 'archive root missing or unreadable',
            ]);
        }

        $domains = $domain !== ''
            ? [$root . '/' . $domain]
            : $this->listChildDirs($root);

        $archives = [];
        $partial = false;
        foreach ($domains as $domainDir) {
            if (!is_dir($domainDir)) {
                continue;
            }
            $domainLabel = basename($domainDir);
            foreach ($this->listChildDirs($domainDir) as $archiveDir) {
                $name = basename($archiveDir);
                $parsed = $this->parseArchiveDirName($name);
                $stat = @stat($archiveDir);
                if ($stat === false) {
                    $partial = true;
                    continue;
                }
                $archives[] = [
                    'path' => $archiveDir,
                    'domain' => $domainLabel,
                    'name' => $name,
                    'archived_at' => $parsed['timestamp'] ?? null,
                    'archived_at_unix' => $parsed['timestamp_unix'] ?? null,
                    'job_id' => $parsed['job_id'] ?? null,
                    'size_bytes' => $this->archiveSizeBytes($archiveDir),
                    'mtime_unix' => (int) ($stat['mtime'] ?? 0),
                ];
            }
        }

        // Newest first: prefer the parsed archived_at_unix when present;
        // fall back to filesystem mtime so a hand-copied archive without
        // our naming convention still sorts sanely.
        usort($archives, static function (array $a, array $b): int {
            $av = (int) ($a['archived_at_unix'] ?? $a['mtime_unix'] ?? 0);
            $bv = (int) ($b['archived_at_unix'] ?? $b['mtime_unix'] ?? 0);
            return $bv <=> $av;
        });
        if (count($archives) > $limit) {
            $archives = array_slice($archives, 0, $limit);
        }

        return $this->success([
            'root' => $root,
            'domain' => $domain ?: null,
            'archives' => $archives,
            'count' => count($archives),
            'partial' => $partial,
        ]);
    }

    /**
     * @return list<string> absolute paths of immediate child directories
     */
    private function listChildDirs(string $dir): array
    {
        $out = [];
        $iter = @scandir($dir);
        if ($iter === false) {
            return $out;
        }
        foreach ($iter as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_dir($full)) {
                $out[] = $full;
            }
        }
        return $out;
    }

    /**
     * Parse `<YYYYMMDD-HHMMSS>-job<id>` into structured fields. Returns
     * empty array fields when the name doesn't match the convention -
     * the UI then falls back to mtime for display.
     *
     * @return array{timestamp?:string, timestamp_unix?:int, job_id?:int}
     */
    private function parseArchiveDirName(string $name): array
    {
        if (!preg_match('/^(\d{8}-\d{6})-job(\d+)$/', $name, $m)) {
            return [];
        }
        $iso = $m[1];
        $jobId = (int) $m[2];
        // YYYYMMDD-HHMMSS in UTC (gmdate when written; see
        // ArchivePromoteStep::archiveDir).
        $dt = \DateTimeImmutable::createFromFormat(
            'Ymd-His',
            $iso,
            new \DateTimeZone('UTC')
        );
        if ($dt === false) {
            return ['job_id' => $jobId];
        }
        return [
            'timestamp' => $dt->format(\DateTimeInterface::ATOM),
            'timestamp_unix' => $dt->getTimestamp(),
            'job_id' => $jobId,
        ];
    }

    /**
     * Recursive size of an archive directory. Capped at a single
     * filesystem-walk pass so a giant archive doesn't stall the
     * action call. Returns null when the size could not be computed
     * (permission error, missing dir).
     */
    private function archiveSizeBytes(string $dir): ?int
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return null;
        }
        $total = 0;
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $total += $file->getSize();
                }
            }
        } catch (\Throwable) {
            return null;
        }
        return $total;
    }

    public function actionListJobs(array $params, string $actor): array
    {
        $this->bootstrapServices();
        [$page, $perPage] = $this->paginationFromParams($params);

        $filters = [];
        $args = [];

        if (!empty($params['status'])) {
            $filters[] = 'status = :status';
            $args['status'] = (string) $params['status'];
        }
        if (!empty($params['type'])) {
            $filters[] = 'type = :type';
            $args['type'] = (string) $params['type'];
        }
        if (!empty($params['domain'])) {
            $filters[] = 'site_domain = :domain';
            $args['domain'] = (string) $params['domain'];
        }
        if (!empty($params['actor_user_id'])) {
            $filters[] = 'actor_user_id = :actor_user_id';
            $args['actor_user_id'] = (int) $params['actor_user_id'];
        }

        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $pdo = $this->db->pdo();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM site_jobs {$where}");
        $countStmt->execute($args);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM site_jobs {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($args as $k => $v) {
            if (is_int($v)) {
                $stmt->bindValue($k, $v, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue($k, $v);
            }
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $jobs = array_map(static fn(array $r) => SiteJob::fromRow($r), $rows);

        $counts = $this->dispatcher->countByStatus();

        return $this->success([
            'jobs' => array_map(fn(SiteJob $j) => $this->serialiseJob($j), $jobs),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
            'status_counts' => $counts,
        ]);
    }

    public function actionGetJob(array $params, string $actor): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->error('id is required');
        }
        $this->bootstrapServices();

        $job = $this->dispatcher->getById($id);
        if ($job === null) {
            return $this->error("Job {$id} not found", ['code' => 'not_found']);
        }

        $steps = $this->fetchStepExecutions($id);
        $events = $this->fetchJobEvents($id, sinceId: 0, limit: self::JOB_EVENT_TAIL);
        $site = $this->fetchSite($job->siteDomain);

        return $this->success([
            'job' => $this->serialiseJob($job),
            'steps' => $steps,
            'events' => $events,
            'site' => $site,
        ]);
    }

    /**
     * Tail events for a job since a given event id. Designed to be polled
     * (or streamed via SSE in front of this) from the operator UI to show
     * live progress without committing to a long-lived socket.
     */
    public function actionGetJobEvents(array $params, string $actor): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->error('id is required');
        }
        $sinceId = max(0, (int) ($params['since_id'] ?? 0));
        $limit = max(1, min(self::MAX_EVENT_TAIL, (int) ($params['limit'] ?? self::JOB_EVENT_TAIL)));

        $this->bootstrapServices();

        $job = $this->dispatcher->getById($id);
        if ($job === null) {
            return $this->error("Job {$id} not found", ['code' => 'not_found']);
        }

        $events = $this->fetchJobEvents($id, $sinceId, $limit);
        $lastId = $events ? (int) end($events)['id'] : $sinceId;

        return $this->success([
            'events' => $events,
            'last_id' => $lastId,
            'job_status' => $job->status->value,
            'job_terminal' => $job->isTerminal(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

    private function bootstrapServices(): void
    {
        if ($this->db !== null) {
            return;
        }
        $this->db = PanelDatabase::fromDefaultConfigFiles();
        $this->masker = new SecretMasker();
        $this->audit = new AuditLogger($this->db, $this->masker);
        $this->dispatcher = new JobDispatcher($this->db, $this->masker, $this->audit);
        $this->stateMachine = new SiteStateMachine($this->db, $this->audit);
        $this->siteLock = new SiteLock($this->db);
    }

    /**
     * Record operator intent on the sites row. desired_state is NOT
     * managed by the FSM (the FSM owns actual_state - reality); it is
     * the other half of the reconciliation pair, so every lifecycle
     * enqueue must update it. Before this, a successfully deleted site
     * kept desired_state='active' forever and was indistinguishable
     * from a genuine orphaned-create drift case.
     *
     * Best-effort: a miss here must never block the enqueue.
     *
     * @return string|null The previous desired_state for rollback, or
     *                     null when nothing changed (already at $to,
     *                     row missing, or update failed).
     */
    private function setDesiredState(int $siteId, string $to): ?string
    {
        try {
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare('SELECT desired_state FROM sites WHERE id = ?');
            $stmt->execute([$siteId]);
            $previous = $stmt->fetchColumn();
            if ($previous === false || (string) $previous === $to) {
                return null;
            }
            $pdo->prepare('UPDATE sites SET desired_state = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$to, $siteId]);
            return (string) $previous;
        } catch (\Throwable $e) {
            $this->logger->warning('desired_state update failed', [
                'site_id' => $siteId,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Serialise the "check duplicates -> pre-transition -> enqueue"
     * critical section per domain. Without the lock, two concurrent
     * enqueue requests can both pass the duplicate/conflict checks and
     * both enqueue, or a crash between pre-transition and enqueue can
     * race a concurrent caller's view of the row.
     *
     * Non-blocking: a held lock returns an immediate clean error (the
     * API maps it to 409) instead of stalling the agent socket.
     *
     * @param callable():array $criticalSection
     */
    private function withEnqueueLock(string $domain, string $purpose, callable $criticalSection): array
    {
        $holderId = sprintf('enqueue-%s-%d', gethostname() ?: 'agent', getmypid());
        $handle = $this->siteLock->tryAcquire($domain, $holderId, $purpose, null, 30);
        if ($handle === null) {
            $current = $this->siteLock->inspect($domain);
            return $this->error(
                "Another operation on '{$domain}' is in progress (lock held by '"
                    . ($current['holder_id'] ?? 'unknown') . "'). Retry shortly.",
                ['code' => 'locked', 'lock' => $current]
            );
        }
        try {
            return $criticalSection();
        } finally {
            $handle->release();
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function actorFromParams(array $params, string $actorUsername): ActorContext
    {
        return new ActorContext(
            username: mb_substr((string) ($params['actor_username'] ?? $actorUsername), 0, 128),
            userId: isset($params['actor_user_id']) && $params['actor_user_id'] !== null
                ? (int) $params['actor_user_id']
                : null,
            sourceIp: isset($params['source_ip']) ? (string) $params['source_ip'] : null,
            userAgent: isset($params['user_agent']) ? mb_substr((string) $params['user_agent'], 0, 255) : null,
            requestId: isset($params['request_id']) ? mb_substr((string) $params['request_id'], 0, 64) : null,
        );
    }

    private function requireDomain(array $params): ?string
    {
        $domain = (string) ($params['domain'] ?? '');
        if ($domain === '') {
            return 'domain is required';
        }
        if (strlen($domain) > 253) {
            return 'domain exceeds 253 characters';
        }
        // Conservative shape check: lowercase letters, digits, dots,
        // hyphens. We don't enforce full RFC 1035 here because the
        // saga's step library will reject anything that fails.
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $domain)) {
            return "domain '{$domain}' is not a valid hostname";
        }
        return null;
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function normalisePayload($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        return [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolvePriority(array $params, int $default): int
    {
        $p = (int) ($params['priority'] ?? $default);
        return max(0, min(255, $p));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0:int,1:int}
     */
    private function paginationFromParams(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? self::DEFAULT_PAGE_SIZE);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        return [$page, $perPage];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSite(string $domain): ?array
    {
        // Mirrors the JOIN in actionListSites so single-site lookups
        // (getSite, enqueue* response site echoes) include the same
        // template metadata operators see in the list view.
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*,
                    td.template_type     AS template_type,
                    td.deployed_at       AS template_deployed_at,
                    td.deployed_by       AS template_deployed_by,
                    td.backup_file       AS template_backup_file
               FROM sites s
               LEFT JOIN template_deployments td ON td.domain = s.domain
              WHERE s.domain = :domain'
        );
        $stmt->execute(['domain' => $domain]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->serialiseSiteRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialiseSiteRow(array $row): array
    {
        $row['health'] = $this->decodeJsonColumn($row['health'] ?? null);
        $row['config'] = $this->decodeJsonColumn($row['config'] ?? null);
        $row['state'] = $this->decodeJsonColumn($row['state'] ?? null);
        unset($row['rendered_fragment']); // Bulky, exposed via a dedicated endpoint if needed.

        // Mask any sensitive fields that snuck into config.
        if (is_array($row['config']) && $this->masker !== null) {
            $row['config'] = $this->masker->maskArray($row['config']);
        }

        // Template metadata (joined from template_deployments). The
        // legacy view computes has_template_backup from THREE signals:
        //   1. a row in template_deployments
        //   2. a backup file present on disk (.flowone_backup_*)
        //   3. content sniffing of index.html
        // For the v2 list response we use only (1) because (2) and
        // (3) would require filesystem I/O per row - far too slow
        // for a paginated list. Operators who apply templates via
        // the panel always create a (1) record, so this covers the
        // 99% case. Templates applied manually by SSH without the
        // panel will appear as untemplated; the operator can fix
        // that by re-applying via the modal.
        $hasTemplate = isset($row['template_type'])
            && $row['template_type'] !== null
            && $row['template_type'] !== '';
        $row['has_template_backup'] = $hasTemplate;

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseJob(SiteJob $job): array
    {
        $masked = $this->masker !== null
            ? $this->masker->maskArray($job->payload)
            : $job->payload;
        return [
            'id' => $job->id,
            'site_domain' => $job->siteDomain,
            'type' => $job->type->value,
            'status' => $job->status->value,
            'priority' => $job->priority,
            'priority_class' => $job->priorityClass->value,
            'attempts' => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            'current_step' => $job->currentStep,
            'request_id' => $job->requestId,
            'parent_job_id' => $job->parentJobId,
            'dry_run' => $job->dryRun,
            'actor' => $job->actor,
            'actor_user_id' => $job->actorUserId,
            'source_ip' => $job->sourceIp,
            'enqueued_at' => $job->enqueuedAt,
            'started_at' => $job->startedAt,
            'finished_at' => $job->finishedAt,
            'locked_by' => $job->lockedBy,
            'lease_until' => $job->leaseUntil,
            'error' => $job->error,
            'result' => $job->result,
            'payload' => $masked,
            'terminal' => $job->isTerminal(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchStepExecutions(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, step_name, attempt_number, schema_version,
                    started_at, finished_at, duration_ms,
                    outcome, exit_code, error,
                    input_snapshot, output_snapshot,
                    stdout_excerpt, stderr_excerpt,
                    worker_id, subprocess_pid, request_id
               FROM site_step_executions
              WHERE job_id = :job_id
              ORDER BY id ASC'
        );
        $stmt->execute(['job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['input_snapshot'] = $this->decodeJsonColumn($row['input_snapshot'] ?? null);
            $row['output_snapshot'] = $this->decodeJsonColumn($row['output_snapshot'] ?? null);
        }
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchJobEvents(int $jobId, int $sinceId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, step_name, level, message, metadata, request_id, occurred_at
               FROM site_job_events
              WHERE job_id = :job_id AND id > :since_id
              ORDER BY id ASC
              LIMIT :limit'
        );
        $stmt->bindValue('job_id', $jobId, \PDO::PARAM_INT);
        $stmt->bindValue('since_id', $sinceId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['metadata'] = $this->decodeJsonColumn($row['metadata'] ?? null);
        }
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentAuditForDomain(string $domain, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, occurred_at, action, actor_username, reason,
                    before_snapshot, after_snapshot, job_id, request_id
               FROM site_audit_log
              WHERE site_domain = :domain
              ORDER BY id DESC
              LIMIT :limit'
        );
        $stmt->bindValue('domain', $domain);
        $stmt->bindValue('limit', max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['before_snapshot'] = $this->decodeJsonColumn($row['before_snapshot'] ?? null);
            $row['after_snapshot'] = $this->decodeJsonColumn($row['after_snapshot'] ?? null);
        }
        return $rows;
    }

    /**
     * Look up an in-flight (queued or running) job for a domain of a given
     * type. Used to short-circuit duplicate enqueue requests.
     */
    private function findActiveJobForDomain(string $domain, JobType $type): ?SiteJob
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM site_jobs
              WHERE site_domain = :domain
                AND type = :type
                AND status IN (:queued, :running)
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([
            'domain' => $domain,
            'type' => $type->value,
            'queued' => JobStatus::QUEUED->value,
            'running' => JobStatus::RUNNING->value,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : SiteJob::fromRow($row);
    }

    /**
     * Look up an in-flight (queued or running) job for a domain of any
     * OTHER type than the one being enqueued. Two different lifecycle
     * sagas running concurrently against the same domain (e.g. CREATE
     * vs DELETE) would race each other's steps, so every enqueue path
     * rejects on a conflicting job. Same-type duplicates are handled
     * separately (idempotent return of the existing job).
     */
    private function findConflictingJobForDomain(string $domain, JobType $exceptType): ?SiteJob
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM site_jobs
              WHERE site_domain = :domain
                AND type <> :type
                AND status IN (:queued, :running)
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([
            'domain' => $domain,
            'type' => $exceptType->value,
            'queued' => JobStatus::QUEUED->value,
            'running' => JobStatus::RUNNING->value,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : SiteJob::fromRow($row);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function decodeJsonColumn($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
