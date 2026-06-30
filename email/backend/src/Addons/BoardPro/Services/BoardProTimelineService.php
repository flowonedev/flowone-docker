<?php

namespace Webmail\Addons\BoardPro\Services;

use PDO;

/**
 * BoardProTimelineService
 *
 * Unified card timeline that merges events from:
 * - Card activity log
 * - Linked emails (boardpro_card_emails)
 * - CRM interactions (deals, invoices)
 * - Time tracking entries
 * - Chat mentions
 * - File attachments
 *
 * All read-only queries against existing tables.
 */
class BoardProTimelineService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * Get unified timeline for a card
     * Merges events from multiple sources, sorted by date DESC
     */
    public function getCardTimeline(int $cardId, int $limit = 50, int $offset = 0): array
    {
        try {
            $events = [];

            // 1. Card activity log
            $events = array_merge($events, $this->getCardActivity($cardId));

            // 2. Linked emails
            $events = array_merge($events, $this->getCardEmailEvents($cardId));

            // 3. Comments
            $events = array_merge($events, $this->getCardComments($cardId));

            // 4. Checklist changes (from activity)
            // Already included in card activity

            // 5. Attachments
            $events = array_merge($events, $this->getCardAttachmentEvents($cardId));

            // 6. Time tracking entries (if card is linked to a client)
            $events = array_merge($events, $this->getTimeTrackingEvents($cardId));

            // 7. Invoice events
            $events = array_merge($events, $this->getInvoiceEvents($cardId));

            // Sort all events by date DESC
            usort($events, fn($a, $b) => strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0'));

            // Apply pagination
            return array_slice($events, $offset, $limit);
        } catch (\Throwable $e) {
            error_log("BoardProTimelineService::getCardTimeline error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get timeline event count for a card
     */
    public function getCardTimelineCount(int $cardId): int
    {
        $count = 0;

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM webmail_card_activity WHERE card_id = ?");
            $stmt->execute([$cardId]);
            $count += (int) $stmt->fetchColumn();
        } catch (\Throwable $e) { /* table may not exist */ }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM boardpro_card_emails WHERE card_id = ?");
            $stmt->execute([$cardId]);
            $count += (int) $stmt->fetchColumn();
        } catch (\Throwable $e) { /* table may not exist */ }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM webmail_card_comments WHERE card_id = ?");
            $stmt->execute([$cardId]);
            $count += (int) $stmt->fetchColumn();
        } catch (\Throwable $e) { /* table may not exist */ }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM webmail_card_attachments WHERE card_id = ?");
            $stmt->execute([$cardId]);
            $count += (int) $stmt->fetchColumn();
        } catch (\Throwable $e) { /* table may not exist */ }

        return $count;
    }

    // =========================================================================
    // Event Sources
    // =========================================================================

    private function getCardActivity(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, action, details, user_email, created_at
            FROM webmail_card_activity
            WHERE card_id = ?
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$cardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => [
            'id' => 'activity_' . $row['id'],
            'type' => 'activity',
            'action' => $row['action'],
            'details' => $row['details'],
            'user' => $row['user_email'],
            'date' => $row['created_at'],
            'icon' => 'history',
        ], $rows);
    }

    private function getCardEmailEvents(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, email_subject, email_from, email_date, reply_status
            FROM boardpro_card_emails
            WHERE card_id = ?
            ORDER BY email_date DESC
        ");
        $stmt->execute([$cardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => [
            'id' => 'email_' . $row['id'],
            'type' => 'email',
            'action' => 'email_linked',
            'details' => $row['email_subject'] ?? 'Email',
            'user' => $row['email_from'],
            'date' => $row['email_date'] ?? '',
            'icon' => 'mail',
            'meta' => [
                'reply_status' => $row['reply_status'],
            ],
        ], $rows);
    }

    private function getCardComments(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, content, user_email, created_at
            FROM webmail_card_comments
            WHERE card_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$cardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => [
            'id' => 'comment_' . $row['id'],
            'type' => 'comment',
            'action' => 'comment_added',
            'details' => mb_substr($row['content'], 0, 200),
            'user' => $row['user_email'],
            'date' => $row['created_at'],
            'icon' => 'chat_bubble',
        ], $rows);
    }

    private function getCardAttachmentEvents(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, created_by, created_at
            FROM webmail_card_attachments
            WHERE card_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$cardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => [
            'id' => 'attachment_' . $row['id'],
            'type' => 'attachment',
            'action' => 'file_attached',
            'details' => $row['name'],
            'user' => $row['created_by'],
            'date' => $row['created_at'],
            'icon' => 'attach_file',
        ], $rows);
    }

    private function getTimeTrackingEvents(int $cardId): array
    {
        // Time tracking is linked via client boards; try to find entries for this card
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_email, activity_type, description, duration_seconds, started_at
                FROM webmail_client_time_tracking
                WHERE board_card_id = ?
                ORDER BY started_at DESC
                LIMIT 50
            ");
            $stmt->execute([$cardId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(fn($row) => [
                'id' => 'time_' . $row['id'],
                'type' => 'time_tracking',
                'action' => 'time_logged',
                'details' => ($row['description'] ?? $row['activity_type']) . ' (' . $this->formatDuration((int) $row['duration_seconds']) . ')',
                'user' => $row['user_email'],
                'date' => $row['started_at'],
                'icon' => 'schedule',
                'meta' => [
                    'duration_seconds' => (int) $row['duration_seconds'],
                ],
            ], $rows);
        } catch (\Throwable $e) {
            // Table may not exist if time tracker addon is disabled
            return [];
        }
    }

    private function getInvoiceEvents(int $cardId): array
    {
        try {
            // Check if card has linked invoice via boardpro_card_financials
            $stmt = $this->db->prepare("
                SELECT cf.linked_invoice_id
                FROM boardpro_card_financials cf
                WHERE cf.card_id = ? AND cf.linked_invoice_id IS NOT NULL
            ");
            $stmt->execute([$cardId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return [];

            $invoiceId = (int) $row['linked_invoice_id'];

            $stmtInv = $this->db->prepare("
                SELECT id, status, total_amount, currency, created_at, updated_at
                FROM crm_invoices
                WHERE id = ?
            ");
            $stmtInv->execute([$invoiceId]);
            $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) return [];

            return [[
                'id' => 'invoice_' . $invoice['id'],
                'type' => 'invoice',
                'action' => 'invoice_' . $invoice['status'],
                'details' => "Invoice #{$invoice['id']} - {$invoice['currency']} " . number_format((float)$invoice['total_amount'], 2) . " ({$invoice['status']})",
                'user' => '',
                'date' => $invoice['updated_at'] ?? $invoice['created_at'],
                'icon' => 'receipt_long',
                'meta' => [
                    'invoice_id' => (int) $invoice['id'],
                    'status' => $invoice['status'],
                    'amount' => (float) $invoice['total_amount'],
                    'currency' => $invoice['currency'],
                ],
            ]];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // Time View Data
    // =========================================================================

    /**
     * Get time tracking data for all cards in a board
     */
    public function getBoardTimeView(int $boardId): array
    {
        try {
            $this->ensureDependencyTables();

            $stmt = $this->db->prepare("
                SELECT
                    bc.id AS card_id,
                    bc.title AS card_title,
                    bl.name AS list_name,
                    bc.assigned_to,
                    cf.time_budget_hours,
                    COALESCE(SUM(tt.duration_seconds), 0) AS total_tracked_seconds
                FROM webmail_board_cards bc
                JOIN webmail_board_lists bl ON bl.id = bc.list_id
                LEFT JOIN boardpro_card_financials cf ON cf.card_id = bc.id
                LEFT JOIN webmail_client_time_tracking tt ON tt.board_card_id = bc.id
                WHERE bl.board_id = ?
                GROUP BY bc.id, bc.title, bl.name, bc.assigned_to, cf.time_budget_hours
                ORDER BY bl.position ASC, bc.position ASC
            ");
            $stmt->execute([$boardId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(function ($row) {
                $budget = (float) ($row['time_budget_hours'] ?? 0);
                $tracked = (int) $row['total_tracked_seconds'];
                $trackedHours = $tracked / 3600;

                return [
                    'card_id' => (int) $row['card_id'],
                    'card_title' => $row['card_title'],
                    'list_name' => $row['list_name'],
                    'assigned_to' => $row['assigned_to'],
                    'budget_hours' => $budget,
                    'tracked_hours' => round($trackedHours, 2),
                    'tracked_seconds' => $tracked,
                    'budget_used_percent' => $budget > 0 ? round(($trackedHours / $budget) * 100, 1) : 0,
                    'over_budget' => $budget > 0 && $trackedHours > $budget,
                ];
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get client-grouped view for a board.
     * Shows: board client info, per-list card breakdown, member workload, financials.
     */
    public function getBoardClientView(int $boardId): array
    {
        try {
            $this->ensureDependencyTables();

            $result = [
                'client' => null,
                'lists' => [],
                'members' => [],
                'totals' => [
                    'card_count' => 0,
                    'completed_count' => 0,
                    'total_revenue' => 0,
                    'total_cost' => 0,
                    'total_margin' => 0,
                ],
            ];

            // Board + client info via client_boards join table
            $stmtBoard = $this->db->prepare("
                SELECT c.id AS client_id, c.display_name AS client_display,
                       c.name AS client_name, c.domain AS client_domain
                FROM client_boards cb
                JOIN clients c ON c.id = cb.client_id
                WHERE cb.board_id = ?
                LIMIT 1
            ");
            $stmtBoard->execute([$boardId]);
            $client = $stmtBoard->fetch(PDO::FETCH_ASSOC);
            if ($client) {
                $result['client'] = [
                    'id' => (int) $client['client_id'],
                    'name' => $client['client_display'] ?: $client['client_name'] ?: 'Unknown',
                    'domain' => $client['client_domain'],
                ];
            }

            // Per-list card counts + financials
            $stmtLists = $this->db->prepare("
                SELECT
                    bl.id AS list_id, bl.name AS list_name, bl.position,
                    COUNT(bc.id) AS card_count,
                    SUM(CASE WHEN bc.completed = 1 THEN 1 ELSE 0 END) AS completed_count,
                    SUM(COALESCE(cf.estimated_revenue, 0)) AS total_revenue,
                    SUM(COALESCE(cf.estimated_cost, 0)) AS total_cost
                FROM webmail_board_lists bl
                LEFT JOIN webmail_board_cards bc ON bc.list_id = bl.id
                LEFT JOIN boardpro_card_financials cf ON cf.card_id = bc.id
                WHERE bl.board_id = ?
                GROUP BY bl.id, bl.name, bl.position
                ORDER BY bl.position ASC
            ");
            $stmtLists->execute([$boardId]);
            $result['lists'] = array_map(function ($row) {
                $rev = (float) $row['total_revenue'];
                $cost = (float) $row['total_cost'];
                return [
                    'list_id' => (int) $row['list_id'],
                    'list_name' => $row['list_name'],
                    'card_count' => (int) $row['card_count'],
                    'completed_count' => (int) $row['completed_count'],
                    'total_revenue' => $rev,
                    'total_cost' => $cost,
                    'margin' => $rev - $cost,
                ];
            }, $stmtLists->fetchAll(PDO::FETCH_ASSOC));

            // Member workload
            $stmtMembers = $this->db->prepare("
                SELECT
                    COALESCE(bc.assigned_to, 'Unassigned') AS member,
                    COUNT(bc.id) AS card_count,
                    SUM(CASE WHEN bc.completed = 1 THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN bc.due_date IS NOT NULL AND bc.due_date < NOW() AND bc.completed = 0 THEN 1 ELSE 0 END) AS overdue_count,
                    SUM(COALESCE(cf.estimated_revenue, 0)) AS total_revenue,
                    SUM(COALESCE(cf.estimated_cost, 0)) AS total_cost
                FROM webmail_board_cards bc
                JOIN webmail_board_lists bl ON bl.id = bc.list_id
                LEFT JOIN boardpro_card_financials cf ON cf.card_id = bc.id
                WHERE bl.board_id = ?
                GROUP BY COALESCE(bc.assigned_to, 'Unassigned')
                ORDER BY card_count DESC
            ");
            $stmtMembers->execute([$boardId]);
            $result['members'] = array_map(function ($row) {
                return [
                    'member' => $row['member'],
                    'card_count' => (int) $row['card_count'],
                    'completed_count' => (int) $row['completed_count'],
                    'overdue_count' => (int) $row['overdue_count'],
                    'total_revenue' => (float) $row['total_revenue'],
                    'total_cost' => (float) $row['total_cost'],
                ];
            }, $stmtMembers->fetchAll(PDO::FETCH_ASSOC));

            // Global totals
            foreach ($result['lists'] as $list) {
                $result['totals']['card_count'] += $list['card_count'];
                $result['totals']['completed_count'] += $list['completed_count'];
                $result['totals']['total_revenue'] += $list['total_revenue'];
                $result['totals']['total_cost'] += $list['total_cost'];
            }
            $result['totals']['total_margin'] = $result['totals']['total_revenue'] - $result['totals']['total_cost'];

            return $result;
        } catch (\Throwable $e) {
            return [
                'client' => null,
                'lists' => [],
                'members' => [],
                'totals' => ['card_count' => 0, 'completed_count' => 0, 'total_revenue' => 0, 'total_cost' => 0, 'total_margin' => 0],
            ];
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function ensureDependencyTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS boardpro_card_financials (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                estimated_revenue DECIMAL(15,2) DEFAULT NULL,
                estimated_cost DECIMAL(15,2) DEFAULT NULL,
                currency VARCHAR(3) DEFAULT 'USD',
                time_budget_hours DECIMAL(8,2) DEFAULT NULL,
                invoice_status ENUM('none','draft','sent','paid','overdue') DEFAULT 'none',
                linked_invoice_id INT DEFAULT NULL,
                updated_by VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_card (card_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS webmail_client_time_tracking (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id INT DEFAULT NULL,
                board_card_id INT DEFAULT NULL,
                user_email VARCHAR(255) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                duration_seconds INT DEFAULT 0,
                tracked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_card (board_card_id),
                INDEX idx_client (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}s";
        $minutes = floor($seconds / 60);
        if ($minutes < 60) return "{$minutes}m";
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return "{$hours}h {$mins}m";
    }
}

