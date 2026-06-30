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

/**
 * Tell OpenLiteSpeed to gracefully reload after a config mutation.
 *
 * Why this is a real step and not a side-effect of OlsMainConfigInsertStep:
 *   - Multiple sites can be created in parallel. Each one mutating
 *     httpd_config.conf and then calling lswsctrl restart immediately
 *     would thrash OLS and risk corrupt in-memory state. The
 *     OlsRestartCoordinator debounces + locks restart attempts to a
 *     single in-flight restart at a time, with a per-DB lock and a
 *     short debounce window.
 *   - The orchestrator can also coalesce: if the create saga has
 *     OlsMainConfigInsertStep AND the delete saga of another site has
 *     OlsMainConfigRemoveStep, both can fire restart at the end and
 *     the coordinator collapses them to one actual reload.
 *
 * Idempotence:
 *   - check() returns true iff we've already restarted in this saga
 *     (state.data['restart_outcome'] is set). That way a resume on the
 *     SAME saga doesn't fire a second restart.
 *   - execute() consults the coordinator, which itself debounces. We
 *     record the outcome and move on.
 *
 * Failure modes:
 *   - 'restarted' -> success
 *   - 'debounced' -> success (another caller restarted recently, our
 *                    config changes are picked up by that restart)
 *   - 'contended' -> warning, treat as success (the next saga's
 *                    restart will pick our changes up; alternatively
 *                    the reconciler observes config drift and forces
 *                    a restart)
 *
 *   Only an actual non-zero exit from lswsctrl bubbles up as a failure.
 *
 * Compensation: SAFE_ROLLBACK but a no-op.
 *   We can't "un-restart" OLS. The COMPENSATE chain for the saga will
 *   roll back the config changes (vhost block removed, listener maps
 *   removed). The next OLS restart picks up the cleaned config; until
 *   then OLS is still serving with stale-but-valid config.
 */
final class OlsRestartStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::OLS_RESTART;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        // Already issued a restart in this saga - don't re-issue.
        return !empty($state->data['restart_outcome']);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $coordinator = $ctx->requireAdapters()->olsRestart;
        $events = [];

        if ($coordinator === null) {
            // Bootstrap / unit-test path: fall back to a direct
            // lswsctrl restart via the OlsAdapter without
            // debounce/lock. We emit a warning so reviewers know the
            // context wasn't fully wired.
            $events[] = StepEvent::warning(
                'OlsRestartCoordinator not wired; falling back to direct OlsAdapter::restart()'
            );
            try {
                $r = $ctx->requireAdapters()->ols->restart();
                if (!$r->isSuccess()) {
                    return StepResult::failure(
                        $state,
                        "direct ols restart failed: " . $r->summary(),
                        $events,
                    );
                }
                $events[] = StepEvent::info('direct ols restart issued');
                return StepResult::success(
                    $state->mergeData([
                        'restart_outcome' => 'direct',
                        'restarted_at' => time(),
                    ])->withCompleted(),
                    $events,
                );
            } catch (\Throwable $e) {
                return StepResult::failure(
                    $state,
                    "direct ols restart failed: " . $e->getMessage(),
                    $events,
                );
            }
        }

        $holderId = 'job:' . $ctx->jobId . ':worker';
        $events[] = StepEvent::info('requesting ols restart via coordinator', [
            'holder' => $holderId, 'request_id' => $ctx->requestId,
        ]);

        try {
            $outcome = $coordinator->request(
                holderId: $holderId,
                requestId: $ctx->requestId,
                blocking: true,
                maxWaitMs: 30_000,
            );
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "ols restart request failed: " . $e->getMessage(),
                $events,
            );
        }

        // Translate the coordinator's outcome to step semantics.
        switch ($outcome) {
            case 'restarted':
                $events[] = StepEvent::info('ols restarted');
                break;
            case 'debounced':
                $events[] = StepEvent::info('ols restart debounced (recent restart already picked up our changes)');
                break;
            case 'contended':
                $events[] = StepEvent::warning(
                    'ols restart lock contended; another worker will reload OLS shortly'
                );
                break;
            default:
                $events[] = StepEvent::warning(
                    'ols restart returned unexpected outcome',
                    ['outcome' => $outcome]
                );
        }

        return StepResult::success(
            $state->mergeData([
                'restart_outcome' => $outcome,
                'restarted_at' => time(),
            ])->withCompleted(),
            $events,
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success(
            $state,
            [StepEvent::info(
                'compensate: cannot un-restart OLS; later compensate steps remove the config they added'
            )]
        );
    }
}
