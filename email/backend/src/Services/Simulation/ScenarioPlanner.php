<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

/**
 * Pure planning: workload profile per simulated user index (0–29).
 */
final class ScenarioPlanner
{
    /** @return list<'overloaded'|'balanced'|'light'> */
    public static function userProfiles(string $runId): array
    {
        $profiles = [];
        for ($i = 0; $i < 30; $i++) {
            if ($i < 5) {
                $profiles[] = 'overloaded';
            } elseif ($i < 10) {
                $profiles[] = 'light';
            } else {
                $profiles[] = 'balanced';
            }
        }
        return $profiles;
    }

    /** @return 'over'|'under'|'none' */
    public static function cardBudgetOutcome(int $cardIndex): string
    {
        $m = $cardIndex % 10;
        if ($m < 2) {
            return 'over';
        }
        if ($m < 7) {
            return 'under';
        }
        return 'none';
    }

    /**
     * Distribution per top-level card, target: 30% solo / 50% pair / 15% trio / 5% squad (period 20).
     * Solo: m < 6 (6/20 = 30%)
     * Pair: m < 16 (10/20 = 50%)
     * Trio: m < 19 (3/20 = 15%)
     * Squad: m == 19 (1/20 = 5%)
     */
    public static function assigneeCountForCard(int $cardIndex): int
    {
        $m = $cardIndex % 20;
        if ($m < 6) {
            return 1;
        }
        if ($m < 16) {
            return 2;
        }
        if ($m < 19) {
            return 3;
        }
        return 4 + ($cardIndex % 2);
    }

    /**
     * Per-assignee status mix, target 25% done / 35% working / 15% assigned / 15% review / 10% blocked.
     * Pool of 20 entries gives exact percentages; cycling with (cardIndex + i) preserves the mix
     * across many small cards while keeping single cards diverse.
     * @return list<string> one of assigned, working, review, done, blocked
     */
    public static function assigneeStatuses(int $count, int $cardIndex): array
    {
        $pool = [
            'done', 'done', 'done', 'done', 'done',
            'working', 'working', 'working', 'working', 'working', 'working', 'working',
            'assigned', 'assigned', 'assigned',
            'review', 'review', 'review',
            'blocked', 'blocked',
        ];
        $out = [];
        $n = count($pool);
        for ($i = 0; $i < $count; $i++) {
            // crc32 of "card:i" gives a well-mixed pseudo-random index — keeps the result
            // deterministic per (card, slot) while pulling uniformly from the weighted pool.
            $h = crc32($cardIndex . ':' . $i);
            $out[] = $pool[$h % $n];
        }
        return $out;
    }

    /**
     * Subtask state (30% done / 40% in-progress / 30% open). Period 10.
     * @return 'done'|'in_progress'|'open'
     */
    public static function subtaskState(int $subIndex): string
    {
        $m = $subIndex % 10;
        if ($m < 3) {
            return 'done';
        }
        if ($m < 7) {
            return 'in_progress';
        }
        return 'open';
    }

    public static function cardCompletionBucket(int $cardIndex): string
    {
        $m = $cardIndex % 10;
        if ($m < 3) {
            return 'done';
        }
        if ($m < 8) {
            return 'progress';
        }
        return 'open';
    }

    public static function isOverdueIncomplete(int $cardIndex): bool
    {
        $bucket = self::cardCompletionBucket($cardIndex);
        if ($bucket === 'done') {
            return false;
        }
        return ($cardIndex % 3) === 0;
    }
}
