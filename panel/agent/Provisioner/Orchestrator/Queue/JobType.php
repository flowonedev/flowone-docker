<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

use VpsAdmin\Agent\Provisioner\Orchestrator\SagaDirection;

/**
 * The kind of work a job describes, mirroring the `site_jobs.type` ENUM.
 *
 * The worker uses this to pick the right SagaSequence out of the
 * SagaRegistry. RECONCILE / RETRY are NOT saga directions on their own -
 * they're operator-driven wrappers that re-enqueue a CREATE or DELETE
 * job after fixing whatever caused the previous attempt to fail.
 *
 * Step 5c-2 wired CREATE end-to-end. Step 4b adds DELETE. Step 4c
 * wires SUSPEND, RESUME, ARCHIVE, RESTORE. RECONCILE and RETRY are
 * not direct sagas; they re-use the CREATE sequence with intent
 * supplied via the job payload (so the bridge can route the FSM
 * transitions appropriately).
 */
enum JobType: string
{
    case CREATE = 'create';
    case DELETE = 'delete';
    case RECONCILE = 'reconcile';
    case RETRY = 'retry';
    case SUSPEND = 'suspend';
    case RESUME = 'resume';
    case ARCHIVE = 'archive';
    case RESTORE = 'restore';

    /**
     * Map a job type to the saga direction the runner should execute.
     *
     * SUSPEND / RESUME do not have a destructive saga direction;
     * they reuse the CREATE/active state machine path because the
     * site row stays in the live table either way. ARCHIVE behaves
     * like DELETE for FSM transitions but the bridge promotes the
     * sites row to 'archived' instead of 'absent'.
     */
    public function toSagaDirection(): ?SagaDirection
    {
        return match ($this) {
            self::CREATE, self::RETRY, self::RECONCILE => SagaDirection::CREATE,
            self::DELETE => SagaDirection::DELETE,
            self::ARCHIVE => SagaDirection::ARCHIVE,
            self::RESTORE => SagaDirection::RESTORE,
            self::SUSPEND => SagaDirection::SUSPEND,
            self::RESUME => SagaDirection::RESUME,
        };
    }

    /**
     * Whether the worker has a saga sequence ready for this type today.
     * Used to fail-fast on unsupported job types at claim time so they
     * don't churn through retries.
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::CREATE,
            self::DELETE,
            self::RECONCILE,
            self::RETRY,
            self::SUSPEND,
            self::RESUME,
            self::ARCHIVE,
            self::RESTORE => true,
        };
    }
}
