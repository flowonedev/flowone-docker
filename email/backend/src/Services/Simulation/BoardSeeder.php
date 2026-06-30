<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

final class BoardSeeder
{
    public function __construct(private array $config, private RunRegistry $registry)
    {
    }

    /**
     * @return array{
     *   board_ids: list<int>,
     *   cards: list<array{card_id: int, board_id: int, list_id: int, card_index: int}>
     * }
     */
    public function seed(string $runId, string $ownerEmail, array $simEmails): array
    {
        $db = \Webmail\Core\Database::getConnection($this->config);
        $owner = strtolower($ownerEmail);
        $boardIds = [];
        $cards = [];
        $cardIndex = 0;

        for ($b = 0; $b < 5; $b++) {
            $title = '[SIM ' . $runId . '] Board ' . ($b + 1);
            $db->prepare('INSERT INTO webmail_boards (name, owner_email, simulation_run_id) VALUES (?, ?, ?)')
                ->execute([$title, $owner, $runId]);
            $boardId = (int) $db->lastInsertId();
            $this->registry->track($runId, RunRegistry::TYPE_BOARD, $boardId, null);
            $boardIds[] = $boardId;

            $db->prepare('INSERT INTO webmail_board_members (board_id, user_email, role) VALUES (?, ?, \'owner\')')
                ->execute([$boardId, $owner]);
            $mid = (int) $db->lastInsertId();
            $this->registry->track($runId, RunRegistry::TYPE_BOARD_MEMBER, $mid, ['board_id' => $boardId, 'user_email' => $owner]);

            foreach ($simEmails as $em) {
                $db->prepare('INSERT INTO webmail_board_members (board_id, user_email, role) VALUES (?, ?, \'editor\')')
                    ->execute([$boardId, $em]);
                $mid2 = (int) $db->lastInsertId();
                $this->registry->track($runId, RunRegistry::TYPE_BOARD_MEMBER, $mid2, ['board_id' => $boardId, 'user_email' => $em]);
            }

            $listIds = [];
            foreach (['Backlog', 'In Progress', 'Done'] as $pos => $lname) {
                $db->prepare('INSERT INTO webmail_board_lists (board_id, name, position) VALUES (?, ?, ?)')
                    ->execute([$boardId, $lname, $pos]);
                $lid = (int) $db->lastInsertId();
                $this->registry->track($runId, RunRegistry::TYPE_LIST, $lid, null);
                $listIds[] = $lid;
            }

            for ($c = 0; $c < 8; $c++) {
                $listId = $listIds[$c % 3];
                $bucket = ScenarioPlanner::cardCompletionBucket($cardIndex);
                $completed = $bucket === 'done' ? 1 : 0;
                $completedAt = $completed ? gmdate('Y-m-d H:i:s', strtotime('-' . (1 + ($c % 10)) . ' days')) : null;
                $overdue = ScenarioPlanner::isOverdueIncomplete($cardIndex);
                $due = $overdue
                    ? gmdate('Y-m-d H:i:s', strtotime('-' . (2 + ($c % 5)) . ' days'))
                    : gmdate('Y-m-d H:i:s', strtotime('+' . (5 + $c) . ' days'));
                $start = gmdate('Y-m-d H:i:s', strtotime('-' . (10 + $c) . ' days'));
                $budget = ScenarioPlanner::cardBudgetOutcome($cardIndex);
                $est = null;
                if ($budget !== 'none') {
                    $est = (2 + ($cardIndex % 8)) * 3600;
                }
                $ctitle = '[SIM ' . $runId . '] Task ' . ($cardIndex + 1);
                $db->prepare('
                    INSERT INTO webmail_board_cards
                      (list_id, title, position, due_date, start_date, completed, completed_at,
                       time_estimate_seconds, time_budget_alert_sent, created_by, assigned_to, simulation_run_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NULL, ?)
                ')->execute([
                    $listId,
                    $ctitle,
                    $c,
                    $due,
                    $start,
                    $completed,
                    $completedAt,
                    $est,
                    $owner,
                    $runId,
                ]);
                $cid = (int) $db->lastInsertId();
                $this->registry->track($runId, RunRegistry::TYPE_CARD, $cid, null);
                // Plan §2: "open" bucket = not started (no sessions yet, all assignees still 'assigned').
                // SessionSeeder reads seed_sessions and skips cards where it is false.
                $cards[] = [
                    'card_id' => $cid,
                    'board_id' => $boardId,
                    'list_id' => $listId,
                    'card_index' => $cardIndex,
                    'bucket' => $bucket,
                    'seed_sessions' => $bucket !== 'open',
                ];
                $cardIndex++;
            }
        }

        foreach ($cards as $row) {
            $this->seedAssigneesForCard($runId, $row, $simEmails, $owner);
        }

        return ['board_ids' => $boardIds, 'cards' => $cards];
    }

    private function seedAssigneesForCard(
        string $runId,
        array $row,
        array $simEmails,
        string $owner
    ): void {
        $db = \Webmail\Core\Database::getConnection($this->config);
        $idx = (int) $row['card_index'];
        $bucket = (string) ($row['bucket'] ?? 'progress');
        $n = ScenarioPlanner::assigneeCountForCard($idx);
        // Plan §2: "open" bucket = not started, ALL assignees still 'assigned'. The bucket overrides
        // the regular per-assignee status mix for these cards. Non-open cards use the weighted pool.
        $statuses = $bucket === 'open'
            ? array_fill(0, $n, 'assigned')
            : ScenarioPlanner::assigneeStatuses($n, $idx);
        $picked = [];
        for ($i = 0; $i < $n; $i++) {
            $si = ($idx + $i * 3) % 30;
            $picked[] = $simEmails[$si];
        }
        $primary = $picked[0];
        foreach ($picked as $i => $email) {
            $role = 'assignee';
            if ($n >= 2 && $i === 1) {
                $role = 'reviewer';
            }
            if ($n >= 3 && $i === $n - 1) {
                $role = 'observer';
            }
            $st = $statuses[$i] ?? 'working';
            $w = 1 + (($idx + $i) % 5);
            $db->prepare('
                INSERT INTO projecthub_card_assignees
                  (card_id, user_email, role, status, difficulty_weight, simulation_run_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ')->execute([(int) $row['card_id'], $email, $role, $st, $w, $runId]);
            $aid = (int) $db->lastInsertId();
            $this->registry->track($runId, RunRegistry::TYPE_ASSIGNEE, $aid, null);
        }
        if ($idx % 5 === 0) {
            $chk = $db->prepare('SELECT id FROM projecthub_card_assignees WHERE card_id = ? AND user_email = ?');
            $chk->execute([(int) $row['card_id'], $owner]);
            $ex = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$ex) {
                $db->prepare('
                    INSERT INTO projecthub_card_assignees
                      (card_id, user_email, role, status, difficulty_weight, simulation_run_id)
                    VALUES (?, ?, \'assignee\', \'working\', 2, ?)
                ')->execute([(int) $row['card_id'], $owner, $runId]);
                $this->registry->track($runId, RunRegistry::TYPE_ASSIGNEE, (int) $db->lastInsertId(), null);
            }
        }
        $db->prepare('UPDATE webmail_board_cards SET assigned_to = ? WHERE id = ?')
            ->execute([$primary, (int) $row['card_id']]);
    }
}
