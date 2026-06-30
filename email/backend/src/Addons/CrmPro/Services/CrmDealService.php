<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmDealService
 * 
 * Handles sales pipeline / deal tracking: CRUD, stage movement, pipeline summary.
 */
class CrmDealService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    public function getDb(): PDO
    {
        return $this->db;
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    public function createDeal(string $userEmail, array $data): array
    {
        $stmt = $this->db->prepare('
            INSERT INTO crm_deals (
                client_id, user_email, title, description, pipeline_stage,
                expected_value, currency, probability, expected_close_date,
                contact_id, assigned_to, board_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            (int)$data['client_id'],
            $userEmail,
            $data['title'],
            $data['description'] ?? null,
            $data['pipeline_stage'] ?? 'lead',
            $data['expected_value'] ?? null,
            $data['currency'] ?? 'HUF',
            $data['probability'] ?? 50,
            $data['expected_close_date'] ?? null,
            $data['contact_id'] ?? null,
            $data['assigned_to'] ?? null,
            $data['board_id'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();
        return $this->getDeal($id, $userEmail);
    }

    public function getDeal(int $id, string $userEmail): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM crm_deals WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        return $stmt->fetch() ?: null;
    }

    public function listDeals(string $userEmail, array $filters = []): array
    {
        $where = ['d.user_email = ?'];
        $params = [$userEmail];

        if (!empty($filters['client_id'])) {
            $where[] = 'd.client_id = ?';
            $params[] = (int)$filters['client_id'];
        }
        if (!empty($filters['pipeline_stage'])) {
            $where[] = 'd.pipeline_stage = ?';
            $params[] = $filters['pipeline_stage'];
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 'd.assigned_to = ?';
            $params[] = $filters['assigned_to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(d.title LIKE ? OR d.description LIKE ?)';
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT d.*
            FROM crm_deals d
            WHERE {$whereClause}
            ORDER BY d.created_at DESC
        ");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function updateDeal(int $id, string $userEmail, array $data): ?array
    {
        $fields = [];
        $params = [];
        $allowed = [
            'client_id', 'title', 'description', 'pipeline_stage', 'expected_value',
            'currency', 'probability', 'expected_close_date', 'actual_close_date',
            'lost_reason', 'contact_id', 'assigned_to', 'board_id', 'invoice_id',
        ];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) return $this->getDeal($id, $userEmail);

        $params[] = $id;
        $params[] = $userEmail;
        $stmt = $this->db->prepare("UPDATE crm_deals SET " . implode(', ', $fields) . " WHERE id = ? AND user_email = ?");
        $stmt->execute($params);

        return $this->getDeal($id, $userEmail);
    }

    public function deleteDeal(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare('DELETE FROM crm_deals WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Stage Management
    // =========================================================================

    public function updateStage(int $id, string $userEmail, string $stage, ?string $lostReason = null): ?array
    {
        $deal = $this->getDeal($id, $userEmail);
        if (!$deal) return null;

        $fromStage = $deal['pipeline_stage'] ?? null;
        $updates = ['pipeline_stage' => $stage];

        if ($stage === 'won') {
            $updates['actual_close_date'] = date('Y-m-d');
        } elseif ($stage === 'lost') {
            $updates['actual_close_date'] = date('Y-m-d');
            $updates['lost_reason'] = $lostReason;
        }

        $result = $this->updateDeal($id, $userEmail, $updates);

        // Record stage transition in history table
        if ($result && $fromStage !== $stage) {
            $this->recordStageChange($id, $fromStage, $stage, $userEmail);

            // --- Integration hooks: fire automation rules & sequence triggers ---
            try {
                $automationService = new CrmAutomationService($this->config);
                $automationService->onDealStageChanged($id, $fromStage ?? '', $stage, $userEmail);
            } catch (\Throwable $e) {
                error_log("CrmDealService::updateStage automation hook error: " . $e->getMessage());
            }

            try {
                $clientId = (int)($deal['client_id'] ?? 0);
                if ($clientId > 0) {
                    $sequenceService = new CrmSequenceService($this->config);
                    $sequenceService->checkStageTriggers($stage, $id, $clientId, $userEmail);
                }
            } catch (\Throwable $e) {
                error_log("CrmDealService::updateStage sequence hook error: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Record a deal stage transition for funnel/velocity analysis
     */
    private function recordStageChange(int $dealId, ?string $fromStage, string $toStage, string $changedBy): void
    {
        try {
            // Ensure table exists
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS crm_deal_stage_history (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    deal_id INT UNSIGNED NOT NULL,
                    from_stage VARCHAR(50) DEFAULT NULL,
                    to_stage VARCHAR(50) NOT NULL,
                    changed_by VARCHAR(255) NOT NULL,
                    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_deal (deal_id),
                    INDEX idx_stage (to_stage),
                    INDEX idx_changed_at (changed_at),
                    FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $stmt = $this->db->prepare("
                INSERT INTO crm_deal_stage_history (deal_id, from_stage, to_stage, changed_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$dealId, $fromStage, $toStage, $changedBy]);
        } catch (\Throwable $e) {
            error_log("CrmDealService::recordStageChange error: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Pipeline View
    // =========================================================================

    /**
     * Get deals organized by pipeline stage with value summaries
     */
    public function getPipelineView(string $userEmail): array
    {
        $stages = ['lead', 'contacted', 'proposal', 'negotiation', 'won', 'lost'];
        $deals = $this->listDeals($userEmail);

        $pipeline = [];
        foreach ($stages as $stage) {
            $stageDeals = array_filter($deals, fn($d) => $d['pipeline_stage'] === $stage);
            $stageDeals = array_values($stageDeals);

            $totalValue = array_sum(array_map(fn($d) => (float)($d['expected_value'] ?? 0), $stageDeals));
            $weightedValue = array_sum(array_map(fn($d) => (float)($d['expected_value'] ?? 0) * (int)($d['probability'] ?? 0) / 100, $stageDeals));

            $pipeline[] = [
                'stage' => $stage,
                'deals' => $stageDeals,
                'count' => count($stageDeals),
                'total_value' => $totalValue,
                'weighted_value' => $weightedValue,
            ];
        }

        return $pipeline;
    }

    /**
     * Get pipeline summary stats
     */
    public function getSummary(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_deals,
                COALESCE(SUM(CASE WHEN pipeline_stage NOT IN ('won','lost') THEN expected_value ELSE 0 END), 0) as pipeline_value,
                COALESCE(SUM(CASE WHEN pipeline_stage = 'won' THEN expected_value ELSE 0 END), 0) as won_value,
                COUNT(CASE WHEN pipeline_stage = 'won' THEN 1 END) as won_count,
                COUNT(CASE WHEN pipeline_stage = 'lost' THEN 1 END) as lost_count,
                COUNT(CASE WHEN pipeline_stage NOT IN ('won','lost') THEN 1 END) as active_count,
                COALESCE(AVG(CASE WHEN pipeline_stage NOT IN ('won','lost') THEN probability END), 0) as avg_probability
            FROM crm_deals WHERE user_email = ?
        ");
        $stmt->execute([$userEmail]);

        return $stmt->fetch();
    }

    // =========================================================================
    // Velocity & Forecast Metrics
    // =========================================================================

    /**
     * Get pipeline velocity metrics:
     * - avg days per stage
     * - median close time (lead → won)
     * - stage-to-stage conversion rates
     */
    public function getVelocityMetrics(string $userEmail): array
    {
        $result = [
            'avg_days_per_stage' => [],
            'avg_days_to_close' => null,
            'conversion_rates' => [],
        ];

        // Check if stage history table exists
        try {
            $this->db->query("SELECT 1 FROM crm_deal_stage_history LIMIT 1");
        } catch (\Throwable $e) {
            // Table not yet created; return empty metrics
            return $result;
        }

        // Avg days per stage (from stage history)
        $stmt = $this->db->prepare("
            SELECT h.from_stage as stage,
                   AVG(TIMESTAMPDIFF(HOUR, 
                       (SELECT MAX(h2.changed_at) FROM crm_deal_stage_history h2 
                        WHERE h2.deal_id = h.deal_id AND h2.to_stage = h.from_stage),
                       h.changed_at
                   )) / 24.0 as avg_days
            FROM crm_deal_stage_history h
            INNER JOIN crm_deals d ON d.id = h.deal_id
            WHERE d.user_email = ? AND h.from_stage IS NOT NULL
            GROUP BY h.from_stage
        ");
        $stmt->execute([$userEmail]);
        foreach ($stmt->fetchAll() as $row) {
            $result['avg_days_per_stage'][$row['stage']] = round((float)$row['avg_days'], 1);
        }

        // Avg days to close (lead → won)
        $stmt = $this->db->prepare("
            SELECT AVG(DATEDIFF(actual_close_date, created_at)) as avg_days
            FROM crm_deals
            WHERE user_email = ? AND pipeline_stage = 'won' AND actual_close_date IS NOT NULL
        ");
        $stmt->execute([$userEmail]);
        $row = $stmt->fetch();
        if ($row && $row['avg_days'] !== null) {
            $result['avg_days_to_close'] = round((float)$row['avg_days'], 1);
        }

        // Stage-to-stage conversion
        $stages = ['lead', 'contacted', 'proposal', 'negotiation', 'won'];
        $stmt = $this->db->prepare("
            SELECT h.to_stage, COUNT(DISTINCT h.deal_id) as deal_count
            FROM crm_deal_stage_history h
            INNER JOIN crm_deals d ON d.id = h.deal_id
            WHERE d.user_email = ?
            GROUP BY h.to_stage
        ");
        $stmt->execute([$userEmail]);
        $stageCounts = [];
        foreach ($stmt->fetchAll() as $row) {
            $stageCounts[$row['to_stage']] = (int)$row['deal_count'];
        }

        // Total deals as lead baseline
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM crm_deals WHERE user_email = ?");
        $stmt->execute([$userEmail]);
        $totalDeals = (int)$stmt->fetchColumn();
        if (!isset($stageCounts['lead'])) {
            $stageCounts['lead'] = $totalDeals;
        }

        for ($i = 1; $i < count($stages); $i++) {
            $prev = $stages[$i - 1];
            $curr = $stages[$i];
            $prevCount = $stageCounts[$prev] ?? 0;
            $currCount = $stageCounts[$curr] ?? 0;
            $result['conversion_rates'][$curr] = $prevCount > 0
                ? round(($currCount / $prevCount) * 100, 1)
                : 0;
        }

        return $result;
    }

    /**
     * Get weighted deal forecast grouped by month.
     * Returns optimistic (raw value) and weighted (probability-adjusted) totals.
     *
     * @param int $months Number of months forward
     */
    public function getForecastByMonth(string $userEmail, int $months = 6): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(expected_close_date, '%Y-%m') as month,
                COUNT(*) as deal_count,
                SUM(expected_value) as optimistic_value,
                SUM(expected_value * probability / 100) as weighted_value,
                AVG(probability) as avg_probability
            FROM crm_deals
            WHERE user_email = ?
              AND pipeline_stage NOT IN ('won', 'lost')
              AND expected_close_date IS NOT NULL
              AND expected_close_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              AND expected_close_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(expected_close_date, '%Y-%m')
            ORDER BY month ASC
        ");
        $stmt->execute([$userEmail, $months]);
        $rawData = $stmt->fetchAll();

        // Fill in gaps for every month in the range
        $forecast = [];
        $startDate = new \DateTime('first day of this month');
        for ($i = 0; $i < $months; $i++) {
            $monthKey = $startDate->format('Y-m');
            $existing = null;
            foreach ($rawData as $row) {
                if ($row['month'] === $monthKey) {
                    $existing = $row;
                    break;
                }
            }
            $forecast[] = [
                'month' => $monthKey,
                'deal_count' => (int)($existing['deal_count'] ?? 0),
                'optimistic_value' => (float)($existing['optimistic_value'] ?? 0),
                'weighted_value' => round((float)($existing['weighted_value'] ?? 0), 0),
                'avg_probability' => round((float)($existing['avg_probability'] ?? 0), 0),
            ];
            $startDate->modify('+1 month');
        }

        return $forecast;
    }
}

