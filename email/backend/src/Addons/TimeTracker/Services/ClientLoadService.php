<?php

namespace Webmail\Addons\TimeTracker\Services;

use PDO;

/**
 * ClientLoadService - per-client load metrics for management views
 *
 * Combines hourly rate (monetary value of tracked time), open/overdue task
 * counts and the next deadline so PMs can answer "how much load does
 * client X take up?" in one panel.
 */
class ClientLoadService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * Load metrics keyed by client_id:
     *   ['hourly_rate','open_tasks','overdue_tasks','next_deadline']
     *
     * @param int[] $clientIds
     */
    public function getLoadByClient(array $clientIds): array
    {
        $clientIds = array_values(array_filter(array_map('intval', $clientIds)));
        if (empty($clientIds)) return [];

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $load = [];

        try {
            $stmt = $this->db->prepare("SELECT id, hourly_rate FROM clients WHERE id IN ($placeholders)");
            $stmt->execute($clientIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $load[(int)$row['id']] = [
                    'hourly_rate' => $row['hourly_rate'] !== null ? (float)$row['hourly_rate'] : null,
                    'open_tasks' => 0,
                    'overdue_tasks' => 0,
                    'next_deadline' => null,
                ];
            }
        } catch (\PDOException $e) {
            error_log("ClientLoadService rates error: " . $e->getMessage());
            return [];
        }

        try {
            // Boards belong to a client via webmail_boards.client_id or client_boards
            $stmt = $this->db->prepare("
                SELECT cb.client_id,
                       COUNT(*) AS open_tasks,
                       SUM(CASE WHEN c.due_date IS NOT NULL AND c.due_date < NOW() THEN 1 ELSE 0 END) AS overdue_tasks,
                       MIN(CASE WHEN c.due_date >= NOW() THEN c.due_date END) AS next_deadline
                FROM (
                    SELECT id AS board_id, client_id FROM webmail_boards
                    WHERE client_id IN ($placeholders) AND archived = 0
                    UNION
                    SELECT b.id AS board_id, clb.client_id
                    FROM client_boards clb
                    JOIN webmail_boards b ON b.id = clb.board_id AND b.archived = 0
                    WHERE clb.client_id IN ($placeholders)
                ) cb
                JOIN webmail_board_lists l ON l.board_id = cb.board_id AND l.archived = 0
                JOIN webmail_board_cards c ON c.list_id = l.id AND c.completed = 0 AND c.archived = 0
                GROUP BY cb.client_id
            ");
            $stmt->execute(array_merge($clientIds, $clientIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cid = (int)$row['client_id'];
                if (!isset($load[$cid])) continue;
                $load[$cid]['open_tasks'] = (int)$row['open_tasks'];
                $load[$cid]['overdue_tasks'] = (int)$row['overdue_tasks'];
                $load[$cid]['next_deadline'] = $row['next_deadline'];
            }
        } catch (\PDOException $e) {
            error_log("ClientLoadService tasks error: " . $e->getMessage());
        }

        return $load;
    }
}
