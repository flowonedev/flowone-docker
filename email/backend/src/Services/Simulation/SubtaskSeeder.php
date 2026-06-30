<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

final class SubtaskSeeder
{
    /**
     * Realistic agency-style subtask titles. Picked deterministically per (parent_index,
     * slot) so the same run always reproduces the same set, but titles vary across cards.
     * Using a 24-entry pool means even a parent with 6 subs typically gets distinct titles.
     */
    private const TITLES = [
        'Kickoff brief',
        'Research & references',
        'Moodboard',
        'Concept sketches',
        'Wireframes',
        'Copy draft',
        'Copy review',
        'Visual direction',
        'First-round designs',
        'Internal review',
        'Client review prep',
        'Revisions round 1',
        'Revisions round 2',
        'Final visuals',
        'Final renders',
        'Asset export',
        'Handoff package',
        'QA pass',
        'Stakeholder sign-off',
        'Launch checklist',
        'Post-launch report',
        'Retrospective notes',
        'Estimate update',
        'Timeline update',
    ];

    public function __construct(private array $config, private RunRegistry $registry)
    {
    }

    /**
     * @param list<array{card_id: int, board_id: int, list_id: int, card_index: int}> $parentCards
     * @param list<string> $simEmails
     * @return list<array{card_id: int, board_id: int, list_id: int, card_index: int, parent_card_id: int}>
     */
    public function seed(string $runId, string $ownerEmail, array $parentCards, array $simEmails): array
    {
        $db = \Webmail\Core\Database::getConnection($this->config);
        $owner = strtolower($ownerEmail);
        $subs = [];
        $titleCount = count(self::TITLES);
        foreach ($parentCards as $parent) {
            $pidx = (int) $parent['card_index'];
            // ~85% of parent cards get subtasks (skip every 7th). Real agency tasks almost
            // always have a breakdown, so leaving most cards empty made the panel feel broken.
            if ($pidx % 7 === 6) {
                continue;
            }
            $n = 2 + ($pidx % 4);
            $listId = (int) $parent['list_id'];
            $pid = (int) $parent['card_id'];

            // Plan §2: subtask assignees should typically come from the parent's pool.
            $poolStmt = $db->prepare('SELECT user_email FROM projecthub_card_assignees WHERE card_id = ?');
            $poolStmt->execute([$pid]);
            $parentPool = array_values(array_filter(array_map(
                static fn ($e): string => strtolower((string) $e),
                $poolStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []
            )));
            if ($parentPool === []) {
                $parentPool = $simEmails;
            }
            for ($s = 0; $s < $n; $s++) {
                $state = ScenarioPlanner::subtaskState($pidx * 5 + $s);
                $completed = $state === 'done' ? 1 : 0;
                $completedAt = $completed ? gmdate('Y-m-d H:i:s', strtotime('-' . $s . ' days')) : null;
                // Agency-style title from the pool, salted by (pidx,s) so siblings differ.
                $titleIdx = ($pidx * 7 + $s * 3) % $titleCount;
                $title = '[SIM ' . $runId . '] ' . self::TITLES[$titleIdx];
                $budget = (3600 * (1 + ($s % 3)));
                $db->prepare('
                    INSERT INTO webmail_board_cards
                      (list_id, title, position, parent_card_id, due_date, completed, completed_at,
                       time_estimate_seconds, time_budget_alert_sent, created_by, assigned_to, simulation_run_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NULL, ?)
                ')->execute([
                    $listId,
                    $title,
                    $s,
                    $pid,
                    gmdate('Y-m-d H:i:s', strtotime('+' . ($s + 3) . ' days')),
                    $completed,
                    $completedAt,
                    $budget,
                    $owner,
                    $runId,
                ]);
                $cid = (int) $db->lastInsertId();
                $this->registry->track($runId, RunRegistry::TYPE_SUBTASK, $cid, null);
                // Typically pick from the parent's pool to keep ownership coherent…
                $assignee = $parentPool[($pidx + $s) % count($parentPool)];
                // …but every 4th sub goes to a different colleague to test cross-task ownership.
                if (($s % 4) === 3) {
                    $assignee = $simEmails[($pidx + $s + 7) % 30];
                }
                $assigneeStatus = match ($state) {
                    'done' => 'done',
                    'in_progress' => 'working',
                    default => 'assigned',
                };
                $db->prepare('
                    INSERT INTO projecthub_card_assignees
                      (card_id, user_email, role, status, difficulty_weight, simulation_run_id)
                    VALUES (?, ?, \'assignee\', ?, ?, ?)
                ')->execute([
                    $cid,
                    $assignee,
                    $assigneeStatus,
                    1 + ($s % 5),
                    $runId,
                ]);
                $aid = (int) $db->lastInsertId();
                $this->registry->track($runId, RunRegistry::TYPE_ASSIGNEE, $aid, null);
                $db->prepare('UPDATE webmail_board_cards SET assigned_to = ? WHERE id = ?')
                    ->execute([$assignee, $cid]);
                $subs[] = [
                    'card_id' => $cid,
                    'board_id' => (int) $parent['board_id'],
                    'list_id' => $listId,
                    'card_index' => 10000 + $pidx * 10 + $s,
                    'parent_card_id' => $pid,
                ];
            }
        }
        return $subs;
    }
}
