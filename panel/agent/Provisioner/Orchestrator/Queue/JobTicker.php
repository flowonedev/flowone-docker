<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * Minimal seam between the daemon loop and the concrete claim/execute
 * machinery.
 *
 * Production has exactly one implementer: JobWorker. The interface
 * exists so WorkerDaemon + WorkerSupervisor tests can substitute a
 * scripted fake without touching the SagaRegistry, SiteStateMachine,
 * SecretVault, or PanelDatabase — keeping those test suites focused
 * on loop semantics (signal handling, idle backoff, rotation
 * thresholds) rather than the whole orchestration stack.
 *
 * Don't add methods here. If the daemon needs more state from the
 * worker, expose it via JobClaimResult or via stats — never by
 * widening this surface.
 */
interface JobTicker
{
    public function tickOnce(): JobClaimResult;
}
