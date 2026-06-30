<?php

namespace Webmail\Addons\BoardPro\Services;

use PDO;

/**
 * BoardProFinancialService
 *
 * Card-level financial fields, board aggregation, revenue views.
 * Reads from webmail_board_* and crm_invoices but only writes to boardpro_* tables.
 */
class BoardProFinancialService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->ensureTables();
    }

    // =========================================================================
    // Table Bootstrap
    // =========================================================================

    private function ensureTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS boardpro_card_financials (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL COMMENT 'FK to webmail_board_cards.id',
                estimated_revenue DECIMAL(15,2) DEFAULT NULL,
                estimated_cost DECIMAL(15,2) DEFAULT NULL,
                currency VARCHAR(3) DEFAULT 'HUF',
                time_budget_hours DECIMAL(8,2) DEFAULT NULL,
                invoice_status ENUM('none','draft','sent','paid','overdue') DEFAULT 'none',
                linked_invoice_id INT UNSIGNED DEFAULT NULL,
                updated_by VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_card (card_id),
                INDEX idx_invoice (linked_invoice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS boardpro_board_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                board_id INT NOT NULL,
                cached_total_revenue JSON DEFAULT NULL,
                cached_total_cost JSON DEFAULT NULL,
                cached_health_score INT DEFAULT NULL,
                last_ai_summary TEXT DEFAULT NULL,
                last_ai_summary_at DATETIME DEFAULT NULL,
                settings JSON DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_board (board_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // =========================================================================
    // Card Financials CRUD
    // =========================================================================

    /**
     * Get or create financial data for a card
     */
    public function getCardFinancials(int $cardId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM boardpro_card_financials WHERE card_id = ?");
        $stmt->execute([$cardId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['margin'] = $this->calculateMargin(
                (float) ($row['estimated_revenue'] ?? 0),
                (float) ($row['estimated_cost'] ?? 0)
            );
        }

        return $row ?: null;
    }

    /**
     * Upsert financial data for a card
     */
    public function upsertCardFinancials(int $cardId, array $data, string $userEmail): array
    {
        $existing = $this->getCardFinancials($cardId);

        if ($existing) {
            $fields = [];
            $values = [];

            $allowedFields = [
                'estimated_revenue', 'estimated_cost', 'currency',
                'time_budget_hours', 'invoice_status', 'linked_invoice_id',
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $values[] = $data[$field];
                }
            }

            if (!empty($fields)) {
                $fields[] = "updated_by = ?";
                $values[] = $userEmail;
                $values[] = $cardId;

                $sql = "UPDATE boardpro_card_financials SET " . implode(', ', $fields) . " WHERE card_id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($values);
            }
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO boardpro_card_financials
                    (card_id, estimated_revenue, estimated_cost, currency, time_budget_hours, invoice_status, linked_invoice_id, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $cardId,
                $data['estimated_revenue'] ?? null,
                $data['estimated_cost'] ?? null,
                $data['currency'] ?? 'HUF',
                $data['time_budget_hours'] ?? null,
                $data['invoice_status'] ?? 'none',
                $data['linked_invoice_id'] ?? null,
                $userEmail,
            ]);
        }

        // Invalidate board-level cache
        $this->invalidateBoardCache($cardId);

        return $this->getCardFinancials($cardId);
    }

    /**
     * Link an invoice to a card and sync status
     */
    public function linkInvoice(int $cardId, int $invoiceId, string $userEmail): ?array
    {
        // Check if the invoice exists in CRM
        $stmt = $this->db->prepare("SELECT id, status FROM crm_invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            return null;
        }

        // Map CRM invoice status to our status enum
        $statusMap = [
            'draft' => 'draft',
            'sent' => 'sent',
            'viewed' => 'sent',
            'paid' => 'paid',
            'partially_paid' => 'sent',
            'overdue' => 'overdue',
            'cancelled' => 'none',
        ];

        $invoiceStatus = $statusMap[$invoice['status']] ?? 'none';

        return $this->upsertCardFinancials($cardId, [
            'linked_invoice_id' => $invoiceId,
            'invoice_status' => $invoiceStatus,
        ], $userEmail);
    }

    // =========================================================================
    // Board-Level Financial Aggregation
    // =========================================================================

    /**
     * Get financial summary for a board
     */
    public function getBoardFinancialSummary(int $boardId): array
    {
        // Try cache first
        $cached = $this->getCachedBoardFinancials($boardId);
        if ($cached !== null) {
            return $cached;
        }

        // Aggregate from card financials
        $stmt = $this->db->prepare("
            SELECT
                cf.currency,
                SUM(cf.estimated_revenue) AS total_revenue,
                SUM(cf.estimated_cost) AS total_cost,
                SUM(cf.time_budget_hours) AS total_time_budget,
                COUNT(*) AS card_count,
                SUM(CASE WHEN cf.invoice_status = 'paid' THEN cf.estimated_revenue ELSE 0 END) AS paid_revenue,
                SUM(CASE WHEN cf.invoice_status = 'overdue' THEN cf.estimated_revenue ELSE 0 END) AS overdue_revenue,
                SUM(CASE WHEN cf.invoice_status = 'draft' THEN 1 ELSE 0 END) AS draft_invoices,
                SUM(CASE WHEN cf.invoice_status = 'sent' THEN 1 ELSE 0 END) AS sent_invoices,
                SUM(CASE WHEN cf.invoice_status = 'paid' THEN 1 ELSE 0 END) AS paid_invoices,
                SUM(CASE WHEN cf.invoice_status = 'overdue' THEN 1 ELSE 0 END) AS overdue_invoices
            FROM boardpro_card_financials cf
            JOIN webmail_board_cards bc ON bc.id = cf.card_id
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            WHERE bl.board_id = ?
            GROUP BY cf.currency
        ");
        $stmt->execute([$boardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also get per-list breakdown
        $stmtList = $this->db->prepare("
            SELECT
                bl.id AS list_id,
                bl.name AS list_name,
                cf.currency,
                SUM(cf.estimated_revenue) AS total_revenue,
                SUM(cf.estimated_cost) AS total_cost,
                COUNT(*) AS card_count
            FROM boardpro_card_financials cf
            JOIN webmail_board_cards bc ON bc.id = cf.card_id
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            WHERE bl.board_id = ?
            GROUP BY bl.id, bl.name, cf.currency
            ORDER BY bl.position ASC
        ");
        $stmtList->execute([$boardId]);
        $listRows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'by_currency' => [],
            'by_list' => [],
            'total_cards_with_financials' => 0,
        ];

        foreach ($rows as $row) {
            $revenue = (float) $row['total_revenue'];
            $cost = (float) $row['total_cost'];
            $result['by_currency'][$row['currency']] = [
                'total_revenue' => $revenue,
                'total_cost' => $cost,
                'total_margin' => $revenue - $cost,
                'margin_percent' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 1) : 0,
                'total_time_budget' => (float) $row['total_time_budget'],
                'paid_revenue' => (float) $row['paid_revenue'],
                'overdue_revenue' => (float) $row['overdue_revenue'],
                'invoice_counts' => [
                    'draft' => (int) $row['draft_invoices'],
                    'sent' => (int) $row['sent_invoices'],
                    'paid' => (int) $row['paid_invoices'],
                    'overdue' => (int) $row['overdue_invoices'],
                ],
            ];
            $result['total_cards_with_financials'] += (int) $row['card_count'];
        }

        foreach ($listRows as $row) {
            $key = $row['list_id'] . ':' . $row['currency'];
            $result['by_list'][] = [
                'list_id' => (int) $row['list_id'],
                'list_name' => $row['list_name'],
                'currency' => $row['currency'],
                'total_revenue' => (float) $row['total_revenue'],
                'total_cost' => (float) $row['total_cost'],
                'card_count' => (int) $row['card_count'],
            ];
        }

        // Cache the result
        $this->cacheBoardFinancials($boardId, $result);

        return $result;
    }

    /**
     * Get global financial overview across all boards for a user
     */
    public function getGlobalFinancials(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT
                b.id AS board_id,
                b.name AS board_name,
                cf.currency,
                SUM(cf.estimated_revenue) AS total_revenue,
                SUM(cf.estimated_cost) AS total_cost,
                COUNT(*) AS card_count,
                SUM(CASE WHEN cf.invoice_status = 'paid' THEN cf.estimated_revenue ELSE 0 END) AS paid_revenue,
                SUM(CASE WHEN cf.invoice_status = 'overdue' THEN cf.estimated_revenue ELSE 0 END) AS overdue_revenue
            FROM boardpro_card_financials cf
            JOIN webmail_board_cards bc ON bc.id = cf.card_id
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            JOIN webmail_boards b ON b.id = bl.board_id
            LEFT JOIN webmail_board_members bm ON bm.board_id = b.id AND bm.user_email = ?
            WHERE (b.owner_email = ? OR bm.user_email = ?)
              AND b.archived = 0
            GROUP BY b.id, b.name, cf.currency
            ORDER BY b.name ASC
        ");
        $stmt->execute([$userEmail, $userEmail, $userEmail]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $boards = [];
        $totals = [];

        foreach ($rows as $row) {
            $boardId = (int) $row['board_id'];
            if (!isset($boards[$boardId])) {
                $boards[$boardId] = [
                    'board_id' => $boardId,
                    'board_name' => $row['board_name'],
                    'currencies' => [],
                ];
            }

            $revenue = (float) $row['total_revenue'];
            $cost = (float) $row['total_cost'];

            $boards[$boardId]['currencies'][$row['currency']] = [
                'revenue' => $revenue,
                'cost' => $cost,
                'margin' => $revenue - $cost,
                'paid' => (float) $row['paid_revenue'],
                'overdue' => (float) $row['overdue_revenue'],
                'cards' => (int) $row['card_count'],
            ];

            if (!isset($totals[$row['currency']])) {
                $totals[$row['currency']] = ['revenue' => 0, 'cost' => 0, 'paid' => 0, 'overdue' => 0, 'cards' => 0];
            }
            $totals[$row['currency']]['revenue'] += $revenue;
            $totals[$row['currency']]['cost'] += $cost;
            $totals[$row['currency']]['paid'] += (float) $row['paid_revenue'];
            $totals[$row['currency']]['overdue'] += (float) $row['overdue_revenue'];
            $totals[$row['currency']]['cards'] += (int) $row['card_count'];
        }

        return [
            'boards' => array_values($boards),
            'totals' => $totals,
        ];
    }

    /**
     * Get revenue view data for a board (cards grouped by list with financial data)
     */
    public function getRevenueView(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                bl.id AS list_id,
                bl.name AS list_name,
                bl.position,
                bc.id AS card_id,
                bc.title AS card_title,
                bc.assigned_to,
                cf.estimated_revenue,
                cf.estimated_cost,
                cf.currency,
                cf.time_budget_hours,
                cf.invoice_status,
                cf.linked_invoice_id
            FROM webmail_board_lists bl
            LEFT JOIN webmail_board_cards bc ON bc.list_id = bl.id
            LEFT JOIN boardpro_card_financials cf ON cf.card_id = bc.id
            WHERE bl.board_id = ?
            ORDER BY bl.position ASC, bc.position ASC
        ");
        $stmt->execute([$boardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lists = [];
        foreach ($rows as $row) {
            $listId = (int) $row['list_id'];
            if (!isset($lists[$listId])) {
                $lists[$listId] = [
                    'list_id' => $listId,
                    'list_name' => $row['list_name'],
                    'position' => (int) $row['position'],
                    'cards' => [],
                    'totals' => [],
                ];
            }

            if ($row['card_id']) {
                $revenue = (float) ($row['estimated_revenue'] ?? 0);
                $cost = (float) ($row['estimated_cost'] ?? 0);
                $currency = $row['currency'] ?? 'HUF';

                $lists[$listId]['cards'][] = [
                    'card_id' => (int) $row['card_id'],
                    'title' => $row['card_title'],
                    'assigned_to' => $row['assigned_to'],
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'margin' => $revenue - $cost,
                    'currency' => $currency,
                    'time_budget' => (float) ($row['time_budget_hours'] ?? 0),
                    'invoice_status' => $row['invoice_status'] ?? 'none',
                ];

                if (!isset($lists[$listId]['totals'][$currency])) {
                    $lists[$listId]['totals'][$currency] = ['revenue' => 0, 'cost' => 0];
                }
                $lists[$listId]['totals'][$currency]['revenue'] += $revenue;
                $lists[$listId]['totals'][$currency]['cost'] += $cost;
            }
        }

        return array_values($lists);
    }

    // =========================================================================
    // Cache Management
    // =========================================================================

    private function getCachedBoardFinancials(int $boardId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT cached_total_revenue, cached_total_cost
            FROM boardpro_board_settings
            WHERE board_id = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$boardId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['cached_total_revenue']) {
            return null;
        }

        // Cache only stores summary; return null to force fresh calculation
        // This is a performance trade-off: we cache for 5 minutes
        return null;
    }

    private function cacheBoardFinancials(int $boardId, array $data): void
    {
        $revenue = json_encode($data['by_currency'] ?? []);
        $cost = json_encode(array_map(fn($c) => $c['total_cost'] ?? 0, $data['by_currency'] ?? []));

        $stmt = $this->db->prepare("
            INSERT INTO boardpro_board_settings (board_id, cached_total_revenue, cached_total_cost)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                cached_total_revenue = VALUES(cached_total_revenue),
                cached_total_cost = VALUES(cached_total_cost),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$boardId, $revenue, $cost]);
    }

    /**
     * Invalidate cache when card financials change
     */
    private function invalidateBoardCache(int $cardId): void
    {
        // Find the board for this card
        $stmt = $this->db->prepare("
            SELECT bl.board_id
            FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            WHERE bc.id = ?
        ");
        $stmt->execute([$cardId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->db->prepare("
                UPDATE boardpro_board_settings
                SET cached_total_revenue = NULL, cached_total_cost = NULL
                WHERE board_id = ?
            ")->execute([$row['board_id']]);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function calculateMargin(float $revenue, float $cost): float
    {
        if ($revenue <= 0) return 0;
        return round((($revenue - $cost) / $revenue) * 100, 1);
    }
}

