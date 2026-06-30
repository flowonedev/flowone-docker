<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

/**
 * ProjectHubLiveActivityService - real "working right now" signals
 *
 * Surfaces the most recent tracked activity per user (file being edited,
 * website being worked on, card open) from the two time-tracking tables.
 * Used by the admin Workload Live view so managers see actual activity,
 * not just self-reported assignee status.
 */
class ProjectHubLiveActivityService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * Latest activity signal per user within the time window.
     *
     * @return array<string, array> keyed by lowercase user email:
     *   ['kind','detail','client_name','card_id','card_title','source','at']
     */
    public function getRecentSignals(int $windowMinutes = 10): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
        $signals = [];

        foreach ($this->getClientTimeSignals($cutoff) as $email => $sig) {
            $signals[$email] = $sig;
        }

        // Work sessions win on ties/newer timestamps (they carry card context)
        foreach ($this->getWorkSessionSignals($cutoff) as $email => $sig) {
            if (!isset($signals[$email]) || $sig['at'] >= $signals[$email]['at']) {
                $signals[$email] = $sig;
            }
        }

        return $signals;
    }

    /** Latest webmail_client_time_tracking entry per user (flushed every ~30s). */
    private function getClientTimeSignals(string $cutoff): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT t.user_email, t.activity_type, t.entity_name, t.source,
                       t.updated_at, c.display_name AS client_name
                FROM webmail_client_time_tracking t
                LEFT JOIN clients c ON c.id = t.client_id
                JOIN (
                    SELECT user_email, MAX(updated_at) AS max_updated
                    FROM webmail_client_time_tracking
                    WHERE updated_at >= ?
                    GROUP BY user_email
                ) latest ON latest.user_email = t.user_email AND latest.max_updated = t.updated_at
                WHERE t.updated_at >= ?
            ");
            $stmt->execute([$cutoff, $cutoff]);

            $signals = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $email = strtolower($row['user_email']);
                if (isset($signals[$email])) continue;
                $signals[$email] = [
                    'kind' => $row['activity_type'],
                    'detail' => $row['entity_name'],
                    'client_name' => $row['client_name'],
                    'card_id' => null,
                    'card_title' => null,
                    'source' => $row['source'] ?: 'cloud',
                    'at' => $row['updated_at'],
                ];
            }
            return $signals;
        } catch (\PDOException $e) {
            error_log("ProjectHubLiveActivityService getClientTimeSignals error: " . $e->getMessage());
            return [];
        }
    }

    /** Latest projecthub_work_sessions row per user (carries card context). */
    private function getWorkSessionSignals(string $cutoff): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT ws.user_email, ws.source, ws.entity_name, ws.created_at,
                       ws.card_id, c.title AS card_title, cl.display_name AS client_name
                FROM projecthub_work_sessions ws
                JOIN (
                    SELECT user_email, MAX(created_at) AS max_created
                    FROM projecthub_work_sessions
                    WHERE created_at >= ?
                    GROUP BY user_email
                ) latest ON latest.user_email = ws.user_email AND latest.max_created = ws.created_at
                LEFT JOIN webmail_board_cards c ON c.id = ws.card_id
                LEFT JOIN webmail_board_lists l ON l.id = c.list_id
                LEFT JOIN webmail_boards b ON b.id = l.board_id
                LEFT JOIN clients cl ON cl.id = b.client_id
                WHERE ws.created_at >= ?
            ");
            $stmt->execute([$cutoff, $cutoff]);

            $signals = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $email = strtolower($row['user_email']);
                if (isset($signals[$email])) continue;
                $signals[$email] = [
                    'kind' => $row['source'],
                    'detail' => $row['entity_name'] ?: $row['card_title'],
                    'client_name' => $row['client_name'],
                    'card_id' => $row['card_id'] ? (int)$row['card_id'] : null,
                    'card_title' => $row['card_title'],
                    'source' => $row['source'],
                    'at' => $row['created_at'],
                ];
            }
            return $signals;
        } catch (\PDOException $e) {
            error_log("ProjectHubLiveActivityService getWorkSessionSignals error: " . $e->getMessage());
            return [];
        }
    }
}
