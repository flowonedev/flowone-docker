<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

/**
 * ProjectHubCompletionsService - "who finished what, each day"
 *
 * Builds a day-by-day completions log for the admin Team views by merging
 * two completion signals:
 *  - per-assignee completions (projecthub_card_assignees.completed_at)
 *  - card-level completions (webmail_board_cards.completed_at)
 */
class ProjectHubCompletionsService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * Completions in [startDate, endDate], grouped by day then by member.
     * Filters: member_email, group_id.
     */
    public function getCompletions(string $startDate, string $endDate, array $filters = []): array
    {
        [$assigneeFilterSql, $assigneeParams] = $this->buildMemberFilters($filters, 'a.user_email');
        [$cardFilterSql, $cardParams] = $this->buildMemberFilters($filters, 'c.assigned_to');

        $entries = [];
        $covered = []; // "cardId|email" handled by an assignee row

        // 1. Per-assignee completions (someone marked their part done)
        $stmt = $this->db->prepare("
            SELECT a.user_email, a.completed_at, a.role,
                   c.id AS card_id, c.title AS card_title,
                   b.id AS board_id, b.name AS board_name,
                   cl.display_name AS client_name
            FROM projecthub_card_assignees a
            JOIN webmail_board_cards c ON c.id = a.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            LEFT JOIN clients cl ON cl.id = b.client_id
            WHERE a.status = 'done'
              AND a.completed_at IS NOT NULL
              AND DATE(a.completed_at) BETWEEN ? AND ?
              $assigneeFilterSql
            ORDER BY a.completed_at DESC
        ");
        $stmt->execute(array_merge([$startDate, $endDate], $assigneeParams));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = strtolower($row['user_email']);
            $covered[$row['card_id'] . '|' . $email] = true;
            $entries[] = [
                'user_email' => $email,
                'card_id' => (int)$row['card_id'],
                'card_title' => $row['card_title'],
                'board_id' => (int)$row['board_id'],
                'board_name' => $row['board_name'],
                'client_name' => $row['client_name'],
                'completed_at' => $row['completed_at'],
                'kind' => 'assignee_done',
                'role' => $row['role'],
            ];
        }

        // 2. Card-level completions (card closed), attributed to assigned_to
        $stmt = $this->db->prepare("
            SELECT c.id AS card_id, c.title AS card_title, c.completed_at,
                   LOWER(COALESCE(NULLIF(c.assigned_to, ''), 'unassigned')) AS user_email,
                   b.id AS board_id, b.name AS board_name,
                   cl.display_name AS client_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            LEFT JOIN clients cl ON cl.id = b.client_id
            WHERE c.completed = 1
              AND c.completed_at IS NOT NULL
              AND DATE(c.completed_at) BETWEEN ? AND ?
              $cardFilterSql
            ORDER BY c.completed_at DESC
        ");
        $stmt->execute(array_merge([$startDate, $endDate], $cardParams));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = $row['user_email'];
            if (isset($covered[$row['card_id'] . '|' . $email])) continue;
            $entries[] = [
                'user_email' => $email,
                'card_id' => (int)$row['card_id'],
                'card_title' => $row['card_title'],
                'board_id' => (int)$row['board_id'],
                'board_name' => $row['board_name'],
                'client_name' => $row['client_name'],
                'completed_at' => $row['completed_at'],
                'kind' => 'card_completed',
                'role' => null,
            ];
        }

        return $this->groupByDay($entries);
    }

    /** @return array[] [{date, total, members: [{email, completions: [...]}]}] sorted desc */
    private function groupByDay(array $entries): array
    {
        $days = [];
        foreach ($entries as $entry) {
            $date = substr($entry['completed_at'], 0, 10);
            $email = $entry['user_email'];
            $days[$date] ??= ['date' => $date, 'total' => 0, 'members' => []];
            $days[$date]['members'][$email] ??= ['email' => $email, 'completions' => []];
            $days[$date]['members'][$email]['completions'][] = $entry;
            $days[$date]['total']++;
        }

        krsort($days);
        foreach ($days as &$day) {
            usort($day['members'], fn($a, $b) => count($b['completions']) <=> count($a['completions']));
            $day['members'] = array_values($day['members']);
        }

        return array_values($days);
    }

    private function buildMemberFilters(array $filters, string $emailColumn): array
    {
        $sql = '';
        $params = [];

        if (!empty($filters['member_email'])) {
            $sql .= " AND LOWER($emailColumn) = ?";
            $params[] = strtolower($filters['member_email']);
        }

        if (!empty($filters['group_id'])) {
            $sql .= "
              AND LOWER($emailColumn) IN (
                  SELECT LOWER(oc.email) FROM organization_colleagues oc
                  JOIN colleague_group_members cgm ON cgm.colleague_id = oc.id
                  WHERE cgm.group_id = ?
              )
            ";
            $params[] = (int)$filters['group_id'];
        }

        return [$sql, $params];
    }
}
