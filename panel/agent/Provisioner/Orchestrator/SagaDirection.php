<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

/**
 * Which kind of saga the orchestrator is being asked to run.
 *
 * The direction determines:
 *   - the IN-FLIGHT actual_state the bridge transitions a site into
 *     before running the saga (provisioning vs deleting vs restoring),
 *   - the TERMINAL actual_state the bridge transitions to based on the
 *     SagaOutcome (e.g. CREATE.SUCCEEDED -> active; DELETE.SUCCEEDED
 *     -> absent),
 *   - which set of legal source states the runner accepts at the
 *     start (e.g. you may DELETE from active/degraded/suspended; you
 *     may CREATE from absent/failed/degraded).
 *
 * Step 4c adds ARCHIVE, RESTORE, SUSPEND, and RESUME. ARCHIVE shares
 * the DELETE infrastructure but parks the row in 'archived' rather
 * than 'absent'. SUSPEND / RESUME do not own a destructive teardown -
 * they just gate the live vhost.
 */
enum SagaDirection: string
{
    case CREATE = 'create';
    case DELETE = 'delete';
    case RESTORE = 'restore';
    case ARCHIVE = 'archive';
    case SUSPEND = 'suspend';
    case RESUME = 'resume';

    /**
     * The actual_state value the bridge transitions a site INTO before
     * starting the saga. The saga itself runs while the site is in this
     * state.
     *
     * SUSPEND / RESUME do not have dedicated "suspending" / "resuming"
     * FSM states. The site stays in its existing state during the saga
     * (active for SUSPEND, suspended for RESUME) and transitions only
     * at terminal. This means the runner skips the entry transition
     * for those flows (inFlightState == legal source state).
     */
    public function inFlightState(): string
    {
        return match ($this) {
            self::CREATE => 'provisioning',
            self::DELETE => 'deleting',
            self::ARCHIVE => 'deleting',
            self::RESTORE => 'restoring',
            // No transition at start; site stays here for the saga.
            self::SUSPEND => 'active',
            self::RESUME => 'suspended',
        };
    }

    /**
     * Source actual_states from which entering this saga's in-flight
     * state is legal. The bridge consults this list when deciding
     * whether to skip the entry transition (already in-flight) or
     * perform it.
     *
     * @return list<string>
     */
    public function legalSourceStates(): array
    {
        return match ($this) {
            // 'pending_dns' is a legal source so the reconciler can re-run
            // the CREATE saga to retry SSL issuance once DNS propagates.
            // Most steps' check() reports already-satisfied; only
            // SslIssueStep re-executes and either issues the cert (->
            // active) or defers again (-> pending_dns).
            self::CREATE => ['absent', 'failed', 'degraded', 'provisioning', 'pending_dns'],
            self::DELETE => ['active', 'degraded', 'failed', 'suspended', 'deleting'],
            self::ARCHIVE => ['active', 'degraded', 'suspended', 'failed', 'deleting'],
            self::RESTORE => ['archived', 'restoring'],
            // SUSPEND: only 'active' is the canonical source; 'degraded'
            // is allowed so operators can flip a struggling site to
            // suspended.
            self::SUSPEND => ['active', 'degraded'],
            // RESUME: must be currently suspended to come back online.
            self::RESUME => ['suspended'],
        };
    }

    public function humanLabel(): string
    {
        return $this->value;
    }
}
