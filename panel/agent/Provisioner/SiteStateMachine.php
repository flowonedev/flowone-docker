<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Exceptions\StateGuardFailed;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * The single chokepoint for every write to `sites.actual_state`.
 *
 * Why centralize: the system has many actors (HTTP controllers, worker,
 * reconciler, CLI tools, backfill) and many transitions. Without one
 * service enforcing legality, illegal states leak in and the reconciler
 * cannot reason about reality. The `architecture-boundary-test` ensures
 * nothing else writes the column.
 *
 * Transitions are encoded as an explicit allowlist. Anything not in the
 * map throws InvalidStateTransition - silent allow-all is unsafe.
 *
 * Every transition writes a row to `site_audit_log` so the history is
 * reconstructable. The whole transition (UPDATE + audit insert) happens
 * inside a single MariaDB transaction so a half-applied transition is
 * impossible.
 */
final class SiteStateMachine
{
    /**
     * actual_state -> list of legal next actual_state values.
     *
     * Read this map carefully when changing it: every site-affecting
     * provisioner step assumes these transitions exist. Removing one
     * could strand sites in a state with no exit.
     */
    private const TRANSITIONS = [
        'absent' => [
            'provisioning', // CREATE saga starting
            'failed',       // stuck-site-sweeper parking an orphaned
                            // failed-create row (absent + latest job is a
                            // dead CREATE) so it becomes visible in the UI
                            // instead of sitting invisibly for weeks
        ],

        'provisioning' => [
            'pending_dns',  // create finished core, waiting for DNS before SSL
            'active',       // create finished, fully healthy
            'failed',       // create gave up
            'degraded',     // partial success, user data exists, manual review
        ],

        'pending_dns' => [
            'provisioning', // reconciler re-running CREATE saga to retry SSL
            'active',       // SSL acquired after propagation
            'failed',       // exceeded retry budget
            'degraded',     // DNS propagated but SSL itself failed
        ],

        'active' => [
            'degraded',     // reconciler saw drift
            'deleting',     // operator requested delete
            'suspended',    // operator paused
            'archived',     // moved to cold storage
            'provisioning', // operator-requested reprovision (e.g. PHP version change)
        ],

        'suspended' => [
            'active',       // operator resumed
            'archived',     // operator archived
            'deleting',     // operator escalated to delete
        ],

        'archived' => [
            'restoring',    // operator-requested restore
            'absent',       // final purge
        ],

        'restoring' => [
            'active',       // restore succeeded
            'failed',       // restore gave up
            'archived',     // restore aborted, return to cold
        ],

        'degraded' => [
            'active',       // reconciler / manual fix healed it
            'failed',       // exceeded heal budget
            'deleting',     // operator gave up and deleted
            'provisioning', // operator-requested re-attempt
        ],

        'failed' => [
            'provisioning', // operator-requested retry
            'deleting',     // operator chose delete instead
        ],

        'deleting' => [
            'absent',       // delete succeeded
            'degraded',     // delete partially failed, manual review
            'archived',     // archive saga (DELETE-shaped teardown that
                            // promotes to cold storage instead of purging)
            // Rollback edges: actionEnqueueDelete pre-transitions the row
            // to 'deleting' BEFORE enqueueing the job; if the enqueue
            // throws it rolls the row back to wherever it came from.
            // Without these edges that rollback was dead code (the FSM
            // refused) and the row sat wedged in 'deleting' until the
            // stuck-site sweeper parked it in 'degraded'.
            'active',       // enqueue-failure rollback (was active)
            'suspended',    // enqueue-failure rollback (was suspended)
            'failed',       // enqueue-failure rollback (was failed)
        ],
    ];

    public function __construct(
        private readonly PanelDatabase $database,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Move a site from $from to $to atomically.
     *
     * The UPDATE is guarded by `WHERE actual_state = :from` so concurrent
     * transitions cannot both succeed - whichever loses the race gets
     * StateGuardFailed and must refetch.
     *
     * The audit row is written in the same transaction so partial
     * "transition happened but audit missing" is impossible.
     *
     * @throws InvalidStateTransition if $from -> $to is not in the legal map.
     * @throws StateGuardFailed       if the current row is not $from (or row missing).
     */
    public function transition(
        int $siteId,
        string $from,
        string $to,
        string $reason,
        ActorContext $actor,
        ?int $jobId = null,
        ?array $extraAfter = null
    ): void {
        if (!$this->canTransition($from, $to)) {
            throw new InvalidStateTransition($from, $to);
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $row = $this->fetchSiteRowForUpdate($pdo, $siteId);

            if ($row === null) {
                throw new StateGuardFailed(
                    "Site id {$siteId} not found while attempting {$from} -> {$to}"
                );
            }
            if ($row['actual_state'] !== $from) {
                throw new StateGuardFailed(sprintf(
                    'Site id %d is in state %s, expected %s (concurrent modification?)',
                    $siteId,
                    $row['actual_state'],
                    $from
                ));
            }

            $update = $pdo->prepare(
                'UPDATE sites
                    SET actual_state = :to, updated_at = NOW()
                    WHERE id = :id AND actual_state = :from'
            );
            $update->execute([
                'to' => $to,
                'id' => $siteId,
                'from' => $from,
            ]);

            if ($update->rowCount() === 0) {
                // Should be unreachable because we held FOR UPDATE, but defend.
                throw new StateGuardFailed(
                    "Lost the row update race on site id {$siteId}"
                );
            }

            $beforeSnapshot = ['actual_state' => $from];
            $afterSnapshot = array_merge(['actual_state' => $to], $extraAfter ?? []);

            $this->audit->record(
                action: 'state_transition',
                siteDomain: $row['domain'],
                reason: $reason,
                before: $beforeSnapshot,
                after: $afterSnapshot,
                actor: $actor,
                jobId: $jobId,
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Variant for the very first transition out of `absent` when no row exists yet.
     * Inserts a new sites row in `provisioning` state under a single transaction
     * with the audit row, so creation is also atomic.
     *
     * Returns the new site id.
     */
    public function createInProvisioning(
        string $domain,
        array $config,
        ActorContext $actor,
        ?int $jobId = null
    ): int {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            // ON DUPLICATE KEY: the row may be a tombstone (absent) or a
            // parked failure (failed/degraded) from an earlier attempt.
            // Those MUST be resurrected into `provisioning`, otherwise we
            // queue a CREATE job against a row stuck at actual_state =
            // 'absent' - the exact production orphan that sat invisible
            // for 11 days (desired=active + actual=absent, June 2026).
            // A row in any LIVE state (active/provisioning/suspended/...)
            // keeps its actual_state untouched; clobbering a live site is
            // the enqueue guard's job to prevent, and this IF makes the
            // UPSERT harmless if that guard is ever bypassed.
            //
            // Assignment order is significant: MySQL evaluates the UPDATE
            // list left-to-right, and `state`/`last_error` must read the
            // OLD actual_state before the actual_state assignment runs.
            // A resurrected tombstone also gets its step-state map wiped:
            // after a completed delete saga the old checkpoints describe
            // infrastructure that no longer exists.
            $insert = $pdo->prepare(
                "INSERT INTO sites
                    (domain, desired_state, actual_state, config, created_at, updated_at)
                  VALUES
                    (:domain, :desired_state, :actual_state, :config, NOW(), NOW())
                  ON DUPLICATE KEY UPDATE
                    desired_state = VALUES(desired_state),
                    config = VALUES(config),
                    state = IF(actual_state = 'absent', NULL, state),
                    last_error = IF(actual_state IN ('absent','failed','degraded'), NULL, last_error),
                    actual_state = IF(actual_state IN ('absent','failed','degraded'), VALUES(actual_state), actual_state),
                    updated_at = NOW()"
            );
            $insert->execute([
                'domain' => $domain,
                'desired_state' => 'active',
                'actual_state' => 'provisioning',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            ]);

            $siteId = (int) $pdo->lastInsertId();
            if ($siteId === 0) {
                // Row already existed - look it up.
                $lookup = $pdo->prepare('SELECT id FROM sites WHERE domain = :domain');
                $lookup->execute(['domain' => $domain]);
                $siteId = (int) ($lookup->fetchColumn() ?: 0);
            }

            $this->audit->record(
                action: 'state_transition',
                siteDomain: $domain,
                reason: 'site created and entered provisioning',
                before: ['actual_state' => 'absent'],
                after: ['actual_state' => 'provisioning'],
                actor: $actor,
                jobId: $jobId,
            );

            $pdo->commit();
            return $siteId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Adopt an existing OLS vhost that was created outside the saga
     * pipeline (e.g. via the legacy SitesView or by hand).
     *
     * This is NOT a state-machine transition - the row goes straight
     * to `actual_state = active`, which is illegal under the normal
     * TRANSITIONS map (no `absent -> active`). The legality of bypass
     * lives at this method's boundary: only the backfill CLI is
     * supposed to call it, gated by the operator's explicit run.
     *
     * Existing rows for the same domain are left untouched unless
     * `$overwrite` is true - we do not want a rerun of the backfill
     * to clobber state the saga or operator just produced.
     *
     * Returns the site id, the number of rows actually inserted (0 or
     * 1), and a flag indicating whether the row was already present.
     *
     * @param array<string, mixed> $config   Site attributes (php_version,
     *                                       sftp_user, home_dir,
     *                                       document_root, db_name,
     *                                       db_user, ssl_enabled,
     *                                       ssl_expires_at, ssl_issuer,
     *                                       config json blob, ...).
     *
     * @return array{site_id:int, inserted:int, already_existed:bool}
     */
    public function adoptExisting(
        string $domain,
        array $config,
        ActorContext $actor,
        bool $overwrite = false
    ): array {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            // Inspect first - we want to know if a row already exists
            // so the caller can report inserted vs skipped accurately.
            $lookup = $pdo->prepare('SELECT id, actual_state FROM sites WHERE domain = :d');
            $lookup->execute(['d' => $domain]);
            $existing = $lookup->fetch();

            if ($existing && !$overwrite) {
                $pdo->commit();
                return [
                    'site_id' => (int) $existing['id'],
                    'inserted' => 0,
                    'already_existed' => true,
                ];
            }

            // Columns we adopt straight into the table for fast list
            // views. Everything else lives in the JSON `config` blob.
            $columnFields = [
                'php_version' => $config['php_version'] ?? null,
                'sftp_user' => $config['sftp_user'] ?? null,
                'home_dir' => $config['home_dir'] ?? null,
                'document_root' => $config['document_root'] ?? null,
                'db_name' => $config['db_name'] ?? null,
                'db_user' => $config['db_user'] ?? null,
                'ssl_enabled' => !empty($config['ssl_enabled']) ? 1 : 0,
                'ssl_expires_at' => $config['ssl_expires_at'] ?? null,
                'ssl_issuer' => $config['ssl_issuer'] ?? null,
                'dns_enabled' => !empty($config['dns_enabled']) ? 1 : 0,
                'mail_enabled' => !empty($config['mail_enabled']) ? 1 : 0,
            ];
            $configJson = json_encode($config, JSON_UNESCAPED_SLASHES);

            $insert = $pdo->prepare(
                'INSERT INTO sites
                    (domain, desired_state, actual_state,
                     php_version, sftp_user, home_dir, document_root,
                     db_name, db_user,
                     ssl_enabled, ssl_expires_at, ssl_issuer,
                     dns_enabled, mail_enabled,
                     config, imported_at, created_at, updated_at)
                  VALUES
                    (:domain, :desired, :actual,
                     :php_version, :sftp_user, :home_dir, :document_root,
                     :db_name, :db_user,
                     :ssl_enabled, :ssl_expires_at, :ssl_issuer,
                     :dns_enabled, :mail_enabled,
                     :config, NOW(), NOW(), NOW())
                  ON DUPLICATE KEY UPDATE
                    desired_state = VALUES(desired_state),
                    actual_state = VALUES(actual_state),
                    php_version = VALUES(php_version),
                    sftp_user = VALUES(sftp_user),
                    home_dir = VALUES(home_dir),
                    document_root = VALUES(document_root),
                    db_name = VALUES(db_name),
                    db_user = VALUES(db_user),
                    ssl_enabled = VALUES(ssl_enabled),
                    ssl_expires_at = VALUES(ssl_expires_at),
                    ssl_issuer = VALUES(ssl_issuer),
                    dns_enabled = VALUES(dns_enabled),
                    mail_enabled = VALUES(mail_enabled),
                    config = VALUES(config),
                    imported_at = COALESCE(imported_at, NOW()),
                    updated_at = NOW()'
            );
            $insert->execute(array_merge([
                'domain' => $domain,
                'desired' => 'active',
                'actual' => 'active',
                'config' => $configJson,
            ], $columnFields));

            $siteId = (int) $pdo->lastInsertId();
            if ($siteId === 0) {
                $lookup2 = $pdo->prepare('SELECT id FROM sites WHERE domain = :d');
                $lookup2->execute(['d' => $domain]);
                $siteId = (int) ($lookup2->fetchColumn() ?: 0);
            }

            $beforeSnapshot = $existing
                ? ['actual_state' => $existing['actual_state'] ?? null]
                : ['actual_state' => 'absent'];
            $this->audit->record(
                action: 'site_adopted_existing',
                siteDomain: $domain,
                reason: 'backfill: legacy vhost adopted into sites table',
                before: $beforeSnapshot,
                after: ['actual_state' => 'active'] + $columnFields,
                actor: $actor,
            );

            $pdo->commit();
            return [
                'site_id' => $siteId,
                'inserted' => $existing ? 0 : 1,
                'already_existed' => (bool) $existing,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function legalTransitionsFrom(string $state): array
    {
        return self::TRANSITIONS[$state] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function fullTransitionMap(): array
    {
        return self::TRANSITIONS;
    }

    private function fetchSiteRowForUpdate(\PDO $pdo, int $siteId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, domain, desired_state, actual_state
               FROM sites
              WHERE id = :id
              FOR UPDATE'
        );
        $stmt->execute(['id' => $siteId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
