<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Orchestrator\Queue;

/**
 * Coarse fairness bucket mirroring `site_jobs.priority_class`.
 *
 * The dispatcher's claim query orders by (priority_class, priority,
 * enqueued_at) so an operator-typed CREATE always wins over a
 * reconciler-typed CREATE even when both have the same in-bucket
 * priority. Without classes the reconciler would slow down every user
 * action when it has a lot of drift to fix.
 *
 * Operator > reconcile > maintenance is the ordering. Don't change the
 * enum value strings - they're also used as DB column values and as
 * Prometheus label values.
 */
enum JobPriorityClass: string
{
    /** User-initiated work (HTTP API, dashboard action). */
    case OPERATOR = 'operator';

    /** Reconciler-spawned remediation. Lower priority than operator. */
    case RECONCILE = 'reconcile';

    /** Background maintenance (backups, archival, cold-storage moves). */
    case MAINTENANCE = 'maintenance';

    /**
     * Numeric weight used for in-PHP sorting where the DB index isn't
     * available (e.g. listing queued jobs that share a domain). Lower
     * is higher priority.
     */
    public function weight(): int
    {
        return match ($this) {
            self::OPERATOR => 0,
            self::RECONCILE => 1,
            self::MAINTENANCE => 2,
        };
    }
}
