<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\DeletionLeftoverScanner;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Support\LegacyCacheInvalidator;
use VpsAdmin\Agent\Provisioner\Support\SiteRowBackfiller;

/**
 * Bridge between the SagaOrchestrator and the SiteStateMachine.
 *
 * Without this bridge the orchestrator is a pure FSM that just walks a
 * step list. It has no idea what `sites.actual_state` should be.
 * Letting steps or the orchestrator write actual_state directly is
 * forbidden by architecture-boundary tests for good reason - it would
 * scatter state knowledge across the codebase.
 *
 * The bridge is the canonical entry point any caller (HTTP controller,
 * worker, CLI) uses to run a site saga end-to-end:
 *
 *   1. Read the site's current actual_state from $ctx->siteRow.
 *   2. Transition source -> in-flight state (provisioning / deleting /
 *      restoring) via SiteStateMachine. If the site is already in the
 *      in-flight state (a resume) we skip this step.
 *   3. Hand the saga to SagaOrchestrator and wait for the SagaResult.
 *   4. Map the SagaOutcome to a terminal actual_state per the
 *      direction's outcome table:
 *
 *        direction | SUCCEEDED  | FAILED    | DEGRADED | ABORTED
 *        ----------|------------|-----------|----------|-------------
 *        CREATE    | active     | failed    | degraded | (unchanged)
 *        DELETE    | absent     | degraded  | degraded | (unchanged)
 *        RESTORE   | active     | failed    | failed   | (unchanged)
 *
 *   5. Transition in-flight -> terminal state. On ABORTED we leave the
 *      site in the in-flight state so the next worker can resume; we
 *      DO record an audit entry capturing the abort so the timeline
 *      isn't silent.
 *
 * The SiteStateMachine wraps each transition in its own DB transaction
 * with FOR UPDATE locking + audit log insert. The bridge does NOT wrap
 * the saga itself in a transaction - that would hold a row lock for
 * the entire saga duration (potentially minutes) and block all other
 * panel writes against that row.
 *
 * Errors:
 *   - InvalidStateTransition: source state isn't legal for this
 *     direction (e.g. trying to DELETE from 'absent'). The bridge
 *     rethrows; the worker should fail the job.
 *   - StateGuardFailed: someone changed the row under us between
 *     reads. Rethrown for the worker to retry.
 *   - Any throw from the orchestrator bubbles. We still attempt the
 *     terminal transition to a safe state if reasonable; if even that
 *     fails the worker handles it.
 *
 * The bridge is stateless and safe to share across saga runs.
 */
final class ProvisioningSagaRunner
{
    /**
     * @param SiteStateMachine        $stateMachine FSM gate around state transitions.
     * @param AuditLogger             $audit        Records every saga boundary.
     * @param SiteRowBackfiller|null  $backfiller   Pulls denormalized columns
     *                                              (home_dir, document_root,
     *                                              sftp_user, db_name, ...) out
     *                                              of the saga StepState map and
     *                                              writes them to the sites row
     *                                              after a SUCCEEDED CREATE /
     *                                              RESTORE. Null in tests that
     *                                              don't care about the legacy
     *                                              column shape.
     * @param LegacyCacheInvalidator|null $cache    Busts the legacy v1 Redis
     *                                              cache keys (`sites:list`,
     *                                              `site:<domain>`) so the
     *                                              legacy SitesView reflects
     *                                              v2-driven changes
     *                                              immediately. Null when no
     *                                              legacy cache is present
     *                                              (tests / fresh installs).
     */
    public function __construct(
        private readonly SiteStateMachine $stateMachine,
        private readonly AuditLogger $audit,
        private readonly ?SiteRowBackfiller $backfiller = null,
        private readonly ?LegacyCacheInvalidator $cache = null,
        /**
         * Optional per-step subprocess isolator. When provided, each
         * SagaOrchestrator constructed for a saga run is wired with
         * this isolator so step calls run inside a forked child. When
         * null (the default and the historical test-time wiring) the
         * orchestrator runs in-process exactly as before.
         */
        private readonly ?StepProcessIsolator $isolator = null,
    ) {
    }

    /**
     * Run a saga end-to-end with state transitions wrapped around it.
     *
     * $ctx->siteRow MUST contain an 'actual_state' field with the
     * site's current state and an 'id' field with the site's row id.
     * Pre-created via SiteStateMachine::createInProvisioning() for
     * brand-new sites; pre-fetched from the sites table for existing
     * ones.
     */
    public function run(
        SagaDirection $direction,
        SagaSequence $sequence,
        SiteContext $ctx,
        StepStateStore $store,
        SagaEventSink $sink
    ): ProvisioningRunResult {
        $siteId = $ctx->siteId();
        $domain = $ctx->domain();

        if ($siteId <= 0) {
            throw new \InvalidArgumentException(
                'ProvisioningSagaRunner requires a populated siteRow with id'
            );
        }

        $previousState = (string) ($ctx->siteRow['actual_state'] ?? '');
        if ($previousState === '') {
            throw new \InvalidArgumentException(
                "ProvisioningSagaRunner requires siteRow.actual_state for site {$siteId}"
            );
        }

        $inFlightState = $direction->inFlightState();

        // ---- Entry transition (skip when already in-flight) ----
        $entered = false;
        if ($previousState !== $inFlightState) {
            if (!in_array($previousState, $direction->legalSourceStates(), true)) {
                throw new InvalidStateTransition($previousState, $inFlightState);
            }
            $this->stateMachine->transition(
                siteId: $siteId,
                from: $previousState,
                to: $inFlightState,
                reason: "saga.{$direction->value}.start",
                actor: $ctx->actor,
                jobId: $ctx->jobId,
                extraAfter: ['saga_request_id' => $ctx->requestId],
            );
            $entered = true;
        }

        // ---- Run the saga ----
        // SagaOrchestrator is single-use; we create a fresh one per run.
        $orchestrator = new SagaOrchestrator($store, $sink, $this->isolator);
        $sagaResult = $orchestrator->run($sequence, $ctx);

        // ---- Backfill denormalized sites columns ON SUCCESS ONLY ----
        // Runs BEFORE the terminal transition so the legacy SitesView,
        // which reads home_dir/document_root/sftp_user/db_name directly,
        // sees a fully-populated row the instant actual_state flips to
        // 'active'. Failures are non-fatal (logged inside the helper).
        // Only meaningful for forward sagas (CREATE / RESTORE) - DELETE
        // is removing the row anyway, ARCHIVE keeps history but doesn't
        // re-shape the row, SUSPEND/RESUME don't change identity.
        if ($this->backfiller !== null
            && $sagaResult->outcome === SagaOutcome::SUCCEEDED
            && in_array($direction, [SagaDirection::CREATE, SagaDirection::RESTORE], true)
        ) {
            $this->backfiller->backfill($siteId, $store->all(), $ctx->payload, $ctx->domain());
        }

        // ---- Terminal transition (or audit-only on ABORTED) ----
        $finalState = $inFlightState;
        $exited = false;

        $terminal = $this->terminalStateFor($direction, $sagaResult->outcome, $sagaResult);
        if ($terminal === null) {
            // ABORTED: leave the site in-flight for the next worker.
            // Audit the abort so the timeline isn't silent.
            $this->audit->record(
                action: 'saga_aborted',
                siteDomain: $domain,
                reason: "saga.{$direction->value}.aborted: " . ($sagaResult->failureError ?? 'deadline exceeded'),
                before: ['actual_state' => $inFlightState],
                after: ['actual_state' => $inFlightState],
                actor: $ctx->actor,
                jobId: $ctx->jobId,
            );
        } elseif ($terminal === $inFlightState) {
            // Edge case: a saga's success terminal IS the in-flight
            // state (e.g. a planned resume that left the site
            // provisioning). Don't double-transition; just audit.
            $this->audit->record(
                action: 'saga_no_op_terminal',
                siteDomain: $domain,
                reason: "saga.{$direction->value} terminal matches in-flight ({$inFlightState})",
                before: ['actual_state' => $inFlightState],
                after: ['actual_state' => $inFlightState],
                actor: $ctx->actor,
                jobId: $ctx->jobId,
            );
            $finalState = $inFlightState;
        } else {
            $this->stateMachine->transition(
                siteId: $siteId,
                from: $inFlightState,
                to: $terminal,
                reason: "saga.{$direction->value}.{$sagaResult->outcome->value}"
                    . ($sagaResult->failureStepName !== null
                        ? " (failed at {$sagaResult->failureStepName})"
                        : ''),
                actor: $ctx->actor,
                jobId: $ctx->jobId,
                extraAfter: [
                    'saga_outcome' => $sagaResult->outcome->value,
                    'saga_request_id' => $ctx->requestId,
                    'failure_step' => $sagaResult->failureStepName,
                ],
            );
            $finalState = $terminal;
            $exited = true;
        }

        // ---- Post-delete leftover scan (audit-only) ----
        // A DELETE that lands on `absent` gets one cheap read-only
        // verification pass. Any leftover (orphaned mail_domains row,
        // surviving DKIM keys, native pdns zone, ...) is written to the
        // audit log as `delete_leftovers_found` so it surfaces in the
        // site timeline instead of rotting invisibly - the failure mode
        // behind the June 2026 test.com / testsite.hu incident. Scan
        // problems are swallowed: verification must never fail a
        // successful delete.
        if ($direction === SagaDirection::DELETE
            && $sagaResult->outcome === SagaOutcome::SUCCEEDED
            && $exited
        ) {
            try {
                $scanner = new DeletionLeftoverScanner($ctx->database);
                $leftovers = $scanner->scan($domain);
                $this->audit->record(
                    action: $leftovers === [] ? 'delete_verified_clean' : 'delete_leftovers_found',
                    siteDomain: $domain,
                    reason: $leftovers === []
                        ? 'post-delete scan found no leftover artifacts'
                        : 'post-delete scan found leftovers: ' . implode('; ', $leftovers),
                    before: ['actual_state' => 'absent'],
                    after: ['actual_state' => 'absent', 'leftovers' => $leftovers],
                    actor: $ctx->actor,
                    jobId: $ctx->jobId,
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[ProvisioningSagaRunner] post-delete leftover scan failed for %s: %s',
                    $domain,
                    $e->getMessage(),
                ));
            }
        }

        // ---- Bust the legacy v1 Redis cache keys ----
        // The legacy SitesView and detail views key on `sites:list` and
        // `site:<domain>`; if the v2 saga mutates a site without
        // touching those keys, the legacy UI shows stale data until the
        // TTL expires (60s today). We invalidate on ANY terminal that
        // changed state, not just SUCCEEDED, because a degraded /
        // failed terminal is still a state change the UI must reflect.
        if ($this->cache !== null && $exited) {
            try {
                $this->cache->invalidateForDomain($domain);
            } catch (\Throwable $e) {
                // Cache invalidation is best-effort. A missing /
                // unreachable Redis must NEVER fail the saga's terminal
                // landing - the source of truth is the sites table,
                // and the worst case is "legacy UI is stale for 60s".
                error_log(sprintf(
                    '[ProvisioningSagaRunner] cache invalidation failed for %s: %s',
                    $domain,
                    $e->getMessage(),
                ));
            }
        }

        return new ProvisioningRunResult(
            direction: $direction,
            saga: $sagaResult,
            previousState: $previousState,
            inFlightState: $inFlightState,
            finalState: $finalState,
            enteredInFlight: $entered,
            exitedInFlight: $exited,
        );
    }

    /**
     * Map a saga outcome to the actual_state the bridge should
     * transition to after the saga completes.
     *
     * Returns NULL for ABORTED outcomes (no transition; site stays in
     * its in-flight state for the next worker to resume).
     *
     * Pass `$sagaResult` so CREATE / RESTORE outcomes can be downgraded
     * to `pending_dns` when {@see SslIssueStep} marked its state as
     * `ssl_deferred=true`. Without the result the runner falls back to
     * the legacy mapping (treated as `active` on success), which is the
     * behaviour test scaffolding relies on.
     */
    public function terminalStateFor(
        SagaDirection $direction,
        SagaOutcome $outcome,
        ?SagaResult $sagaResult = null
    ): ?string {
        return match ($direction) {
            SagaDirection::CREATE => match ($outcome) {
                SagaOutcome::SUCCEEDED => $this->successOrPendingDns($sagaResult),
                SagaOutcome::FAILED => 'failed',
                SagaOutcome::DEGRADED => 'degraded',
                SagaOutcome::ABORTED => null,
            },
            SagaDirection::DELETE => match ($outcome) {
                SagaOutcome::SUCCEEDED => 'absent',
                // The state machine does NOT allow 'deleting -> failed'.
                // A failed delete that rolled back leaves the site still
                // "kind of present"; degraded is the canonical landing.
                SagaOutcome::FAILED => 'degraded',
                SagaOutcome::DEGRADED => 'degraded',
                SagaOutcome::ABORTED => null,
            },
            SagaDirection::RESTORE => match ($outcome) {
                // RESTORE doesn't currently include an SslIssueStep,
                // so the pending_dns hook isn't wired here. If a future
                // restore-time SSL retry is needed, add `restoring ->
                // pending_dns` to SiteStateMachine first.
                SagaOutcome::SUCCEEDED => 'active',
                SagaOutcome::FAILED => 'failed',
                // No 'restoring -> degraded' edge today; degraded restore
                // is treated as failed for now (operator decides next).
                SagaOutcome::DEGRADED => 'failed',
                SagaOutcome::ABORTED => null,
            },
            SagaDirection::ARCHIVE => match ($outcome) {
                SagaOutcome::SUCCEEDED => 'archived',
                // Archive's destructive teardown can leave the site in
                // a half-deleted state on failure; the canonical landing
                // is degraded (operator decides cleanup).
                SagaOutcome::FAILED => 'degraded',
                SagaOutcome::DEGRADED => 'degraded',
                SagaOutcome::ABORTED => null,
            },
            SagaDirection::SUSPEND => match ($outcome) {
                SagaOutcome::SUCCEEDED => 'suspended',
                // Suspend failed; compensate restored the original
                // vhost.conf, site stays as it was.
                SagaOutcome::FAILED => 'active',
                SagaOutcome::DEGRADED => 'degraded',
                SagaOutcome::ABORTED => null,
            },
            SagaDirection::RESUME => match ($outcome) {
                SagaOutcome::SUCCEEDED => 'active',
                // Resume failed; site stays suspended for operator to
                // diagnose. The FSM does not allow 'suspended -> degraded'
                // directly so DEGRADED also lands as suspended; operator
                // must promote to active/degraded manually.
                SagaOutcome::FAILED => 'suspended',
                SagaOutcome::DEGRADED => 'suspended',
                SagaOutcome::ABORTED => null,
            },
        };
    }

    /**
     * Whether this saga's terminal landings include the SUSPEND/RESUME
     * "stay-put" case where the bridge writes a no-op transition.
     * Exposed for test introspection.
     */
    public function isStayPutDirection(SagaDirection $direction): bool
    {
        return in_array($direction, [SagaDirection::SUSPEND, SagaDirection::RESUME], true);
    }

    /**
     * Decide between `active` and `pending_dns` after a SUCCEEDED CREATE
     * / RESTORE saga. The {@see SslIssueStep} marks its state with
     * `ssl_deferred=true` whenever DNS for the primary domain has not
     * propagated by the time we try to issue. Without this hook the
     * site would land on `active` with no visible cue that SSL is
     * still pending, and the reconciler would never re-attempt
     * issuance (because the assessor sees a fully-provisioned site).
     *
     * Returns `'pending_dns'` only when:
     *   - SagaResult is available (test sites built without a result
     *     keep the legacy `active` landing),
     *   - the SSL step ran and persisted `ssl_deferred=true`.
     *
     * The reconciler later re-enqueues CREATE for `pending_dns` sites;
     * SslIssueStep's check() / DNS probe handle the retry.
     */
    private function successOrPendingDns(?SagaResult $sagaResult): string
    {
        if ($sagaResult === null) {
            return 'active';
        }
        $sslRecord = $sagaResult->findStep(StepName::SSL_ISSUE);
        if ($sslRecord === null) {
            return 'active';
        }
        $data = $sslRecord->finalState->data ?? [];
        if (!empty($data['ssl_deferred'])) {
            return 'pending_dns';
        }
        return 'active';
    }
}
