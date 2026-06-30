<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator;

use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Where the orchestrator persists per-step StepState between calls.
 *
 * Why is this an interface and not just "load it from sites.state JSON":
 *   - In Step 5a (this layer) we run in-process and an InMemory impl is
 *     enough.
 *   - In Step 5c the DB-backed impl persists into the
 *     site_step_executions journal so a crashed worker can resume
 *     mid-saga, AND so the UI can poll real-time progress.
 *   - Tests substitute fakes.
 *
 * Semantics:
 *   - load($stepName) returns NULL when the step hasn't been recorded
 *     yet. The orchestrator treats null as "fresh start".
 *   - save($state) is upsert by ($scope, $stepName). The 'scope' is the
 *     saga's unique scope (typically "domain:<domain>" or
 *     "job:<job_id>") and is provided at construction time.
 *
 * Implementations must be thread-safe enough for a single-worker model
 * (no concurrent saga runs against the same scope). Step 5c's DB impl
 * gets a row-level lock from MariaDB; the in-memory impl assumes
 * single-thread.
 */
interface StepStateStore
{
    public function load(string $stepName): ?StepState;

    /**
     * Persist the given StepState.
     *
     * The optional $lastOutcome / $lastError describe the result that
     * produced this state, so the DB-backed implementation can fill in
     * the `outcome` and `error` columns of the site_step_executions
     * journal. In-memory implementations ignore these fields.
     */
    public function save(
        StepState $state,
        ?StepOutcome $lastOutcome = null,
        ?string $lastError = null
    ): void;

    /**
     * Return every recorded state keyed by step name. Used by the
     * orchestrator to hydrate the run plan up-front so steps can read
     * each other's persisted data via SiteContext.siteRow.state.
     *
     * @return array<string, StepState>
     */
    public function all(): array;

    /**
     * Drop every record for the current scope. Used after a successful
     * saga completes and the orchestrator wants to forget intermediate
     * scratch state, or after a delete saga where the site no longer
     * exists.
     */
    public function clear(): void;
}
