<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

class ProjectHubTimeBreakdownService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    private static array $activityLabels = [
        'email_read'      => 'Email',
        'email_compose'   => 'Email',
        'calendar_event'  => 'Calendar',
        'board_view'      => 'Board Viewing',
        'drive_browse'    => 'Drive',
        'document_open'   => 'Documents',
        'document_edit'   => 'Documents',
        'website_work'    => 'Website Work',
        'mood_board_view' => 'Mood Boards',
        'mood_board_edit' => 'Mood Boards',
        'client_call'     => 'Calls',
        'manual_entry'    => 'Manual Entry',
    ];

    /**
     * Returns flat aggregated rows for the time breakdown view.
     * Merges card-level detail from projecthub_work_sessions with
     * non-card client time from webmail_client_time_tracking.
     */
    public function getTimeBreakdown(
        string $actorEmail,
        bool $isAdmin,
        string $period = 'month',
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $clientId = null,
        ?int $boardId = null,
        ?string $filterUserEmail = null
    ): array {
        $actorEmail = strtolower($actorEmail);
        $domain = substr($actorEmail, strpos($actorEmail, '@') + 1);
        $dateRange = $this->resolveDateRange($period, $startDate, $endDate);

        $debug = [
            'period' => $period,
            'date_start' => $dateRange['start'],
            'date_end' => $dateRange['end'],
            'actor' => $actorEmail,
            'domain' => $domain,
            'is_admin' => $isAdmin,
        ];

        $cardRows = [];
        try {
            $cardRows = $this->getCardTimeRows($actorEmail, $isAdmin, $domain, $dateRange, $clientId, $boardId, $filterUserEmail);
            $debug['card_rows'] = count($cardRows);
        } catch (\Exception $e) {
            error_log("[TimeBreakdown] getCardTimeRows failed: " . $e->getMessage());
            $debug['card_error'] = $e->getMessage();
        }

        $activityRows = [];
        if (!$boardId) {
            try {
                $activityRows = $this->getActivityTimeRows($actorEmail, $isAdmin, $domain, $dateRange, $clientId, $filterUserEmail);
                $debug['activity_rows'] = count($activityRows);
            } catch (\Exception $e) {
                error_log("[TimeBreakdown] getActivityTimeRows failed: " . $e->getMessage());
                $debug['activity_error'] = $e->getMessage();
            }
        } else {
            $debug['activity_rows'] = 'skipped (board filter)';
        }

        $this->_debug = $debug;
        return array_merge($cardRows, $activityRows);
    }

    public array $_debug = [];

    /**
     * Card + file level time from projecthub_work_sessions.
     * Groups by card AND entity_name so the frontend can show per-file breakdowns.
     */
    private function getCardTimeRows(
        string $actorEmail, bool $isAdmin, string $domain,
        array $dateRange, ?int $clientId, ?int $boardId, ?string $filterUserEmail
    ): array {
        $sql = "
            SELECT
                cl.id AS client_id,
                COALESCE(cl.display_name, cl.domain, 'No Client') AS client_name,
                b.id AS board_id,
                b.name AS board_name,
                ws.user_email,
                COALESCE(oc.display_name, ws.user_email) AS user_name,
                c.id AS card_id,
                c.title AS card_title,
                ws.entity_type,
                ws.entity_id,
                ws.entity_name,
                ws.source,
                SUM(ws.duration_seconds) AS total_seconds,
                COUNT(ws.id) AS session_count,
                MAX(ws.started_at) AS last_active
            FROM projecthub_work_sessions ws
            JOIN webmail_board_cards c ON c.id = ws.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            LEFT JOIN (
                SELECT board_id, MIN(client_id) AS client_id
                FROM client_boards
                GROUP BY board_id
            ) cb ON cb.board_id = b.id
            LEFT JOIN clients cl ON cl.id = cb.client_id
            LEFT JOIN organization_colleagues oc ON oc.email = ws.user_email
            WHERE DATE(ws.started_at) >= ?
              AND DATE(ws.started_at) <= ?
        ";

        $params = [$dateRange['start'], $dateRange['end']];

        if (!$isAdmin) {
            $sql .= " AND ws.user_email = ?";
            $params[] = $actorEmail;
        } else {
            $sql .= " AND ws.user_email LIKE ?";
            $params[] = '%@' . $domain;
        }

        if ($filterUserEmail) {
            $sql .= " AND ws.user_email = ?";
            $params[] = strtolower($filterUserEmail);
        }
        if ($clientId) {
            $sql .= " AND cl.id = ?";
            $params[] = $clientId;
        }
        if ($boardId) {
            $sql .= " AND b.id = ?";
            $params[] = $boardId;
        }

        $sql .= "
            GROUP BY cl.id, cl.display_name, cl.domain, b.id, b.name,
                     ws.user_email, oc.display_name, c.id, c.title,
                     ws.entity_type, ws.entity_id, ws.entity_name, ws.source
            ORDER BY client_name ASC, b.name ASC, user_name ASC, c.title ASC, total_seconds DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['client_id'] = $row['client_id'] ? (int)$row['client_id'] : null;
            $row['board_id'] = (int)$row['board_id'];
            $row['card_id'] = (int)$row['card_id'];
            $row['entity_id'] = $row['entity_id'] ? (int)$row['entity_id'] : null;
            $row['total_seconds'] = (int)$row['total_seconds'];
            $row['session_count'] = (int)$row['session_count'];
        }
        return $rows;
    }

    /**
     * Non-card client time from webmail_client_time_tracking.
     * Excludes board_task to avoid double-counting with work sessions.
     */
    private function getActivityTimeRows(
        string $actorEmail, bool $isAdmin, string $domain,
        array $dateRange, ?int $clientId, ?string $filterUserEmail
    ): array {
        $sql = "
            SELECT
                cl.id AS client_id,
                COALESCE(cl.display_name, cl.domain, 'No Client') AS client_name,
                ct.activity_type,
                ct.user_email,
                COALESCE(oc.display_name, ct.user_email) AS user_name,
                SUM(ct.duration_seconds) AS total_seconds,
                COUNT(*) AS session_count,
                MAX(ct.tracked_date) AS last_active
            FROM webmail_client_time_tracking ct
            LEFT JOIN clients cl ON cl.id = ct.client_id
            LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = LOWER(ct.user_email)
            WHERE ct.activity_type != 'board_task'
              AND ct.tracked_date >= ?
              AND ct.tracked_date <= ?
        ";

        $params = [$dateRange['start'], $dateRange['end']];

        if (!$isAdmin) {
            $sql .= " AND LOWER(ct.user_email) = ?";
            $params[] = $actorEmail;
        } else {
            $sql .= " AND LOWER(ct.user_email) LIKE ?";
            $params[] = '%@' . $domain;
        }

        if ($filterUserEmail) {
            $sql .= " AND LOWER(ct.user_email) = ?";
            $params[] = strtolower($filterUserEmail);
        }
        if ($clientId) {
            $sql .= " AND ct.client_id = ?";
            $params[] = $clientId;
        }

        $sql .= "
            GROUP BY cl.id, cl.display_name, cl.domain, ct.activity_type,
                     ct.user_email, oc.display_name
            ORDER BY client_name ASC, ct.activity_type ASC, user_name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $actType = $row['activity_type'];
            $label = self::$activityLabels[$actType] ?? ucwords(str_replace('_', ' ', $actType));
            $result[] = [
                'client_id'     => $row['client_id'] ? (int)$row['client_id'] : null,
                'client_name'   => $row['client_name'],
                'board_id'      => null,
                'board_name'    => $label,
                'user_email'    => $row['user_email'],
                'user_name'     => $row['user_name'],
                'card_id'       => null,
                'card_title'    => $label,
                'total_seconds' => (int)$row['total_seconds'],
                'session_count' => (int)$row['session_count'],
                'last_active'   => $row['last_active'],
            ];
        }
        return $result;
    }

    private function resolveDateRange(string $period, ?string $startDate, ?string $endDate): array
    {
        $end = $endDate ?: date('Y-m-d');

        $start = match ($period) {
            'today'   => date('Y-m-d'),
            'week'    => date('Y-m-d', strtotime('-7 days')),
            'month'   => date('Y-m-d', strtotime('-30 days')),
            'quarter' => date('Y-m-d', strtotime('-3 months')),
            'year'    => date('Y-m-d', strtotime('-365 days')),
            'all'     => '1970-01-01',
            'custom'  => $startDate ?: date('Y-m-d', strtotime('-29 days')),
            default   => date('Y-m-d', strtotime('-30 days')),
        };

        return ['start' => $start, 'end' => $end];
    }
}
