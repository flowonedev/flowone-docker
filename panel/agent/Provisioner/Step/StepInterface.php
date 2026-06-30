<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step;

/**
 * The contract every provisioning step implements.
 *
 * Lifecycle (orchestrated by Provisioner; steps DO NOT call each other):
 *
 *   1. orchestrator constructs the step (DI-free, plain new())
 *   2. orchestrator loads previous state for this step (or fresh)
 *   3. orchestrator calls check($ctx, $state)
 *        - if returns true: SKIPPED, advance
 *        - if returns false: continue
 *   4. orchestrator opens site_step_executions row, sets started_at
 *   5. orchestrator calls execute($ctx, $state)
 *   6. orchestrator persists the returned StepState + events + journal row
 *   7. if SUCCESS: advance
 *      if FAILURE: invoke compensate() chain per CompensationPolicy
 *      if RETRY_LATER: requeue job with backoff
 *      if PARTIAL: re-execute on next worker tick
 *
 * Hard requirements EVERY step must satisfy:
 *
 *   - **Idempotent**: running execute() twice with the same input must
 *     produce the same end state. check() is the gatekeeper that lets
 *     us skip already-done work on resume.
 *   - **Pure observable side effects**: every shell command and DB
 *     write must be visible in StepResult::$events. No silent writes.
 *   - **No state across calls**: instances are short-lived. All state
 *     lives in the StepState passed back and forth.
 *   - **Subprocess-safe**: a step's execute() runs in an isolated
 *     PHP child process via step-runner.php (Step 5). No globals,
 *     no static caches that need to outlive the call.
 *   - **Deadline-aware**: respect $ctx->remainingMs(); return
 *     RETRY_LATER rather than starting a long external call with
 *     no time to finish.
 *   - **Secret-clean**: never put plaintext credentials into
 *     StepState or events. Use SecretVault references.
 *
 * Steps that need to alter the sites row (e.g. recording an SFTP user
 * was created) do so by reading `$ctx->siteRow` and returning the
 * needed update as `metrics['site_row_patch']` - the orchestrator
 * applies the patch atomically with the state machine transition.
 * Direct UPDATE-on-sites from a step is forbidden by architecture-
 * boundary tests.
 */
interface StepInterface
{
    /**
     * Stable identifier used in sites.state JSON and in job_events.
     * Must match the class basename in lowercase-snake form, e.g.
     * `home_dir`, `sftp_user`, `vhost_config`. Changing this would
     * orphan all persisted state - effectively a destructive schema
     * change. If a rename is unavoidable, bump schemaVersion() and
     * handle the alias in StepStateMigrator.
     */
    public function name(): string;

    /**
     * Compensation policy describes what the orchestrator does on a
     * downstream failure once THIS step has completed successfully.
     */
    public function compensationPolicy(): CompensationPolicy;

    /**
     * State shape version. Increment when `StepState::$data` changes.
     */
    public function schemaVersion(): int;

    /**
     * Returns true when reality already matches the desired state and
     * execute() would be a no-op. The orchestrator skips execute() in
     * that case and records `outcome=SKIPPED`.
     *
     * check() must be cheap. No external network calls. Allowed:
     * filesystem stats, DB SELECT, cached capability lookup.
     *
     * Throwing is treated as "couldn't verify" which forces an
     * execute() call - that's a safe default but slower.
     */
    public function check(SiteContext $ctx, StepState $state): bool;

    /**
     * Perform the step's side effects to bring reality into the
     * desired state. Returns a StepResult carrying the new state
     * and events.
     *
     * MUST be idempotent: calling execute() when check() returns
     * true should still succeed without re-doing the work
     * (defensive convergence).
     */
    public function execute(SiteContext $ctx, StepState $state): StepResult;

    /**
     * Reverse this step's side effects. Only called when
     * compensationPolicy() is SAFE_ROLLBACK (or PARTIAL with an
     * applicable sub-state).
     *
     * For DEGRADE_ONLY steps the orchestrator never calls
     * compensate(); the implementation may throw to signal a bug
     * if it is somehow called.
     *
     * Like execute(), compensate() must be idempotent (running it
     * twice yields the same final state).
     */
    public function compensate(SiteContext $ctx, StepState $state): StepResult;

    /**
     * Optional post-execute sanity check. Called by the orchestrator
     * AFTER execute() returned SUCCESS, to confirm reality really
     * matches our recorded state. If verify() returns FAILURE the
     * orchestrator treats the step as failed and applies the
     * compensation policy.
     *
     * Default semantics for steps that don't override: re-run
     * check() and return SUCCESS if it's true, FAILURE otherwise.
     * (Implementers can call $this->check($ctx, $state) and wrap.)
     */
    public function verify(SiteContext $ctx, StepState $state): StepResult;
}
