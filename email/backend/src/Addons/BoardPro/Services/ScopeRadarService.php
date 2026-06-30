<?php

namespace Webmail\Addons\BoardPro\Services;

use PDO;

class ScopeRadarService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * Get scope radar data for a specific board.
     * Analyzes time creep, activity spikes, and flags at-risk cards/clients.
     */
    public function getBoardScopeRadar(int $boardId, string $userEmail): array
    {
        try {
            $cards = $this->getCardsWithTimeAnalysis($boardId);
            $activitySpikes = $this->getActivitySpikes($boardId);
            $emailVolume = $this->getEmailVolume($boardId);
            $summary = $this->buildSummary($cards, $activitySpikes);
            $summary['email_volume'] = $emailVolume;

            return [
                'cards' => $cards,
                'activity_spikes' => $activitySpikes,
                'email_volume' => $emailVolume,
                'summary' => $summary,
            ];
        } catch (\Throwable $e) {
            error_log("ScopeRadarService::getBoardScopeRadar error: " . $e->getMessage());
            return ['cards' => [], 'activity_spikes' => [], 'email_volume' => null, 'summary' => $this->emptySummary()];
        }
    }

    /**
     * Analyze cards: planned duration vs tracked time, overdue status, budget usage.
     */
    private function getCardsWithTimeAnalysis(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                bc.id AS card_id,
                bc.title AS card_title,
                bc.start_date,
                bc.due_date,
                bc.completed,
                bc.completed_at,
                bc.created_at,
                bc.assigned_to,
                bl.name AS list_name,
                COALESCE(SUM(tt.duration_seconds), 0) AS tracked_seconds,
                (SELECT COUNT(*) FROM webmail_checklist_items ci
                 JOIN webmail_card_checklists cl ON cl.id = ci.checklist_id
                 WHERE cl.card_id = bc.id) AS total_todos,
                (SELECT COUNT(*) FROM webmail_checklist_items ci
                 JOIN webmail_card_checklists cl ON cl.id = ci.checklist_id
                 WHERE cl.card_id = bc.id AND ci.completed = 1) AS completed_todos,
                (SELECT COUNT(*) FROM webmail_card_activity ca
                 WHERE ca.card_id = bc.id
                 AND ca.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS activity_this_week,
                (SELECT COUNT(*) FROM webmail_card_activity ca
                 WHERE ca.card_id = bc.id
                 AND ca.created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
                 AND ca.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) / 3.0 AS activity_weekly_avg
            FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            LEFT JOIN webmail_client_time_tracking tt ON tt.board_card_id = bc.id
            WHERE bl.board_id = ?
              AND bc.archived = 0
            GROUP BY bc.id
            ORDER BY bc.due_date ASC, bc.position ASC
        ");
        $stmt->execute([$boardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $startDate = $row['start_date'] ? strtotime($row['start_date']) : null;
            $dueDate = $row['due_date'] ? strtotime($row['due_date']) : null;
            $now = time();

            $plannedDays = null;
            $elapsedDays = null;
            $timeCreepPct = null;

            if ($startDate && $dueDate && $dueDate > $startDate) {
                $plannedDays = ($dueDate - $startDate) / 86400;
                $elapsedDays = ($now - $startDate) / 86400;
                if ($plannedDays > 0) {
                    $timeCreepPct = round(($elapsedDays / $plannedDays) * 100);
                }
            }

            $trackedHours = round((int)$row['tracked_seconds'] / 3600, 1);

            $activityThisWeek = (int)$row['activity_this_week'];
            $activityWeeklyAvg = (float)$row['activity_weekly_avg'];
            $activitySpikePct = $activityWeeklyAvg > 0
                ? round(($activityThisWeek / $activityWeeklyAvg) * 100)
                : ($activityThisWeek > 0 ? 999 : 0);

            $isOverdue = $dueDate && !$row['completed'] && $now > $dueDate;
            $daysOverdue = $isOverdue ? round(($now - $dueDate) / 86400) : 0;

            // Scope creep flags
            $flags = [];
            if ($timeCreepPct !== null && $timeCreepPct > 100 && !$row['completed']) {
                $flags[] = 'time_exceeded';
            }
            if ($activitySpikePct > 150) {
                $flags[] = 'activity_spike';
            }
            if ($isOverdue) {
                $flags[] = 'overdue';
            }
            $totalTodos = (int)$row['total_todos'];
            $completedTodos = (int)$row['completed_todos'];
            if ($totalTodos > 10 && ($completedTodos / max($totalTodos, 1)) < 0.3) {
                $flags[] = 'todo_overload';
            }

            $severity = 'normal';
            if (count($flags) >= 3) $severity = 'critical';
            elseif (count($flags) >= 2) $severity = 'high';
            elseif (count($flags) >= 1) $severity = 'warning';

            return [
                'card_id' => (int)$row['card_id'],
                'card_title' => $row['card_title'],
                'list_name' => $row['list_name'],
                'assigned_to' => $row['assigned_to'],
                'start_date' => $row['start_date'],
                'due_date' => $row['due_date'],
                'completed' => (bool)$row['completed'],
                'planned_days' => $plannedDays ? round($plannedDays, 1) : null,
                'elapsed_days' => $elapsedDays ? round($elapsedDays, 1) : null,
                'time_creep_pct' => $timeCreepPct,
                'tracked_hours' => $trackedHours,
                'total_todos' => $totalTodos,
                'completed_todos' => $completedTodos,
                'activity_this_week' => $activityThisWeek,
                'activity_weekly_avg' => round($activityWeeklyAvg, 1),
                'activity_spike_pct' => $activitySpikePct,
                'is_overdue' => $isOverdue,
                'days_overdue' => $daysOverdue,
                'flags' => $flags,
                'severity' => $severity,
            ];
        }, $rows);
    }

    /**
     * Get board-level activity spike data: weekly activity counts.
     */
    private function getActivitySpikes(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                DATE(al.created_at) AS activity_date,
                COUNT(*) AS activity_count,
                COUNT(DISTINCT al.entity_id) AS cards_touched
            FROM activity_log al
            WHERE al.board_id = ?
              AND al.created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
            GROUP BY DATE(al.created_at)
            ORDER BY activity_date ASC
        ");
        $stmt->execute([$boardId]);
        $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $weeklyTotals = [];
        foreach ($daily as $day) {
            $weekNum = date('W', strtotime($day['activity_date']));
            if (!isset($weeklyTotals[$weekNum])) {
                $weeklyTotals[$weekNum] = ['count' => 0, 'cards' => 0, 'days' => []];
            }
            $weeklyTotals[$weekNum]['count'] += (int)$day['activity_count'];
            $weeklyTotals[$weekNum]['cards'] += (int)$day['cards_touched'];
            $weeklyTotals[$weekNum]['days'][] = $day['activity_date'];
        }

        return [
            'daily' => $daily,
            'weekly' => array_values(array_map(function ($w, $k) {
                return [
                    'week' => $k,
                    'activity_count' => $w['count'],
                    'cards_touched' => $w['cards'],
                    'start_date' => min($w['days']),
                    'end_date' => max($w['days']),
                ];
            }, $weeklyTotals, array_keys($weeklyTotals))),
        ];
    }

    /**
     * Build summary stats from analyzed cards.
     */
    private function buildSummary(array $cards, array $activitySpikes): array
    {
        $flagged = array_filter($cards, fn($c) => !empty($c['flags']));
        $critical = array_filter($cards, fn($c) => $c['severity'] === 'critical');
        $high = array_filter($cards, fn($c) => $c['severity'] === 'high');
        $warning = array_filter($cards, fn($c) => $c['severity'] === 'warning');
        $overdue = array_filter($cards, fn($c) => $c['is_overdue']);
        $timeExceeded = array_filter($cards, fn($c) => in_array('time_exceeded', $c['flags']));
        $activitySpiked = array_filter($cards, fn($c) => in_array('activity_spike', $c['flags']));

        $weekly = $activitySpikes['weekly'] ?? [];
        $currentWeekActivity = !empty($weekly) ? end($weekly)['activity_count'] : 0;
        $prevWeeks = array_slice($weekly, 0, -1);
        $avgWeekActivity = count($prevWeeks) > 0
            ? array_sum(array_column($prevWeeks, 'activity_count')) / count($prevWeeks)
            : 0;
        $boardActivitySpikePct = $avgWeekActivity > 0
            ? round(($currentWeekActivity / $avgWeekActivity) * 100)
            : ($currentWeekActivity > 0 ? 999 : 0);

        $boardSeverity = 'normal';
        if (count($critical) > 0 || $boardActivitySpikePct > 200) $boardSeverity = 'critical';
        elseif (count($high) > 0 || $boardActivitySpikePct > 150) $boardSeverity = 'high';
        elseif (count($flagged) > 0) $boardSeverity = 'warning';

        return [
            'total_cards' => count($cards),
            'flagged_cards' => count($flagged),
            'critical_count' => count($critical),
            'high_count' => count($high),
            'warning_count' => count($warning),
            'overdue_count' => count($overdue),
            'time_exceeded_count' => count($timeExceeded),
            'activity_spiked_count' => count($activitySpiked),
            'board_activity_this_week' => $currentWeekActivity,
            'board_activity_avg' => round($avgWeekActivity, 1),
            'board_activity_spike_pct' => $boardActivitySpikePct,
            'board_severity' => $boardSeverity,
        ];
    }

    /**
     * Get email volume for the client linked to this board.
     * Tracks sent + received emails via client_contacts.
     */
    private function getEmailVolume(int $boardId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT cb.client_id,
                       c.display_name AS client_name,
                       c.domain AS client_domain,
                       COALESCE(SUM(cc.email_count), 0) AS total_emails,
                       MAX(cc.last_email_at) AS last_email_at,
                       COUNT(cc.id) AS contact_count
                FROM client_boards cb
                JOIN clients c ON c.id = cb.client_id
                LEFT JOIN client_contacts cc ON cc.client_id = cb.client_id
                WHERE cb.board_id = ?
                GROUP BY cb.client_id, c.display_name, c.domain
                LIMIT 1
            ");
            $stmt->execute([$boardId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return null;

            return [
                'client_id' => (int)$row['client_id'],
                'client_name' => $row['client_name'] ?: $row['client_domain'],
                'total_emails' => (int)$row['total_emails'],
                'last_email_at' => $row['last_email_at'],
                'contact_count' => (int)$row['contact_count'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check all boards for a user and return scope creep alerts.
     * Used by the cron job to generate notifications.
     */
    public function checkAllBoardsForUser(string $userEmail): array
    {
        $alerts = [];
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT b.id, b.name
                FROM webmail_boards b
                LEFT JOIN webmail_board_members bm ON bm.board_id = b.id
                WHERE b.owner_email = ? OR bm.user_email = ?
            ");
            $stmt->execute([$userEmail, $userEmail]);
            $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($boards as $board) {
                $data = $this->getBoardScopeRadar((int)$board['id'], $userEmail);
                $summary = $data['summary'] ?? [];

                if (($summary['board_severity'] ?? 'normal') !== 'normal') {
                    $alerts[] = [
                        'board_id' => (int)$board['id'],
                        'board_name' => $board['name'],
                        'severity' => $summary['board_severity'],
                        'flagged_cards' => $summary['flagged_cards'] ?? 0,
                        'total_cards' => $summary['total_cards'] ?? 0,
                        'activity_spike_pct' => $summary['board_activity_spike_pct'] ?? 0,
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log("ScopeRadarService::checkAllBoardsForUser error: " . $e->getMessage());
        }
        return $alerts;
    }

    private function emptySummary(): array
    {
        return [
            'total_cards' => 0,
            'flagged_cards' => 0,
            'critical_count' => 0,
            'high_count' => 0,
            'warning_count' => 0,
            'overdue_count' => 0,
            'time_exceeded_count' => 0,
            'activity_spiked_count' => 0,
            'board_activity_this_week' => 0,
            'board_activity_avg' => 0,
            'board_activity_spike_pct' => 0,
            'board_severity' => 'normal',
        ];
    }
}
