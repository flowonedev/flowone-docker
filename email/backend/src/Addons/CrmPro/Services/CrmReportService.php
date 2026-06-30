<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmReportService
 * 
 * Advanced CRM reporting: aging invoices, client value ranking,
 * time profitability, deal forecasting, and conversion funnel.
 */
class CrmReportService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Aging Invoices
    // =========================================================================

    /**
     * Get invoice aging report grouped into 4 buckets: 0-30, 31-60, 61-90, 90+
     * Returns bucket summary + individual invoices per bucket.
     */
    public function getAgingReport(string $userEmail): array
    {
        // Bucket summary
        $stmt = $this->db->prepare("
            SELECT
                CASE
                    WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 0 AND 30 THEN '0-30'
                    WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN '31-60'
                    WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN '61-90'
                    ELSE '90+'
                END as bucket,
                COUNT(*) as count,
                COALESCE(SUM(i.total - i.paid_amount), 0) as outstanding
            FROM crm_invoices i
            WHERE i.user_email = ?
              AND i.status NOT IN ('paid', 'cancelled', 'refunded')
              AND i.due_date < CURDATE()
            GROUP BY bucket
            ORDER BY FIELD(bucket, '0-30', '31-60', '61-90', '90+')
        ");
        $stmt->execute([$userEmail]);
        $bucketSummary = $stmt->fetchAll();

        // Ensure all buckets exist
        $bucketMap = ['0-30' => null, '31-60' => null, '61-90' => null, '90+' => null];
        foreach ($bucketSummary as $row) {
            $bucketMap[$row['bucket']] = $row;
        }
        $buckets = [];
        foreach ($bucketMap as $key => $row) {
            $buckets[] = [
                'bucket' => $key,
                'count' => (int)($row['count'] ?? 0),
                'outstanding' => (float)($row['outstanding'] ?? 0),
            ];
        }

        // Individual invoices (overdue only)
        $stmt = $this->db->prepare("
            SELECT i.id, i.invoice_number, i.client_id, i.total, i.paid_amount,
                   i.due_date, i.status, i.currency,
                   (i.total - i.paid_amount) as outstanding_amount,
                   DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                   c.name as client_name, c.domain as client_domain
            FROM crm_invoices i
            LEFT JOIN clients c ON i.client_id = c.id
            WHERE i.user_email = ?
              AND i.status NOT IN ('paid', 'cancelled', 'refunded')
              AND i.due_date < CURDATE()
            ORDER BY i.due_date ASC
        ");
        $stmt->execute([$userEmail]);
        $invoices = $stmt->fetchAll();

        // Group invoices into buckets
        $invoicesByBucket = ['0-30' => [], '31-60' => [], '61-90' => [], '90+' => []];
        foreach ($invoices as $inv) {
            $days = (int)$inv['days_overdue'];
            if ($days <= 30) $bucket = '0-30';
            elseif ($days <= 60) $bucket = '31-60';
            elseif ($days <= 90) $bucket = '61-90';
            else $bucket = '90+';
            $invoicesByBucket[$bucket][] = $inv;
        }

        $totalOutstanding = array_sum(array_column($buckets, 'outstanding'));
        $totalCount = array_sum(array_column($buckets, 'count'));

        return [
            'buckets' => $buckets,
            'invoices_by_bucket' => $invoicesByBucket,
            'total_outstanding' => $totalOutstanding,
            'total_count' => $totalCount,
        ];
    }

    // =========================================================================
    // Client Value Ranking
    // =========================================================================

    /**
     * Rank clients by revenue over a period, including deal count, avg deal size,
     * hours tracked, and effective hourly rate.
     *
     * @param int $months Number of months to look back (0 = lifetime)
     */
    public function getClientRanking(string $userEmail, int $months = 0): array
    {
        $dateFilter = '';
        $params = [$userEmail];

        if ($months > 0) {
            $dateFilter = 'AND ip.payment_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)';
            $params[] = $months;
        }

        // Revenue per client (from paid invoices)
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, c.domain, c.status as client_status,
                   COALESCE(rev.total_revenue, 0) as revenue,
                   COALESCE(rev.payment_count, 0) as payment_count,
                   COALESCE(deals.deal_count, 0) as deal_count,
                   COALESCE(deals.won_count, 0) as won_count,
                   COALESCE(deals.avg_deal_value, 0) as avg_deal_value,
                   COALESCE(tt.total_hours, 0) as hours_tracked,
                   CASE 
                       WHEN COALESCE(tt.total_hours, 0) > 0 
                       THEN COALESCE(rev.total_revenue, 0) / tt.total_hours 
                       ELSE NULL 
                   END as effective_hourly_rate
            FROM clients c
            LEFT JOIN (
                SELECT i.client_id,
                       SUM(ip.amount) as total_revenue,
                       COUNT(ip.id) as payment_count
                FROM crm_invoices i
                INNER JOIN crm_invoice_payments ip ON ip.invoice_id = i.id
                WHERE i.user_email = ? {$dateFilter}
                GROUP BY i.client_id
            ) rev ON rev.client_id = c.id
            LEFT JOIN (
                SELECT client_id,
                       COUNT(*) as deal_count,
                       COUNT(CASE WHEN pipeline_stage = 'won' THEN 1 END) as won_count,
                       AVG(expected_value) as avg_deal_value
                FROM crm_deals
                WHERE user_email = ?
                GROUP BY client_id
            ) deals ON deals.client_id = c.id
            LEFT JOIN (
                SELECT client_id,
                       SUM(duration_seconds) / 3600.0 as total_hours
                FROM webmail_client_time_tracking
                WHERE user_email = ?
                GROUP BY client_id
            ) tt ON tt.client_id = c.id
            ORDER BY revenue DESC
        ");
        $allParams = array_merge($params, [$userEmail, $userEmail]);
        $stmt->execute($allParams);
        $clients = $stmt->fetchAll();

        // Compute rank
        foreach ($clients as $i => &$client) {
            $client['rank'] = $i + 1;
            $client['revenue'] = (float)$client['revenue'];
            $client['hours_tracked'] = round((float)$client['hours_tracked'], 1);
            $client['effective_hourly_rate'] = $client['effective_hourly_rate'] !== null
                ? round((float)$client['effective_hourly_rate'], 0)
                : null;
            $client['avg_deal_value'] = round((float)$client['avg_deal_value'], 0);
        }

        $totalRevenue = array_sum(array_column($clients, 'revenue'));

        return [
            'clients' => $clients,
            'total_revenue' => $totalRevenue,
            'period_months' => $months ?: 'lifetime',
        ];
    }

    // =========================================================================
    // Time Profitability
    // =========================================================================

    /**
     * Per-client profitability: revenue vs hours spent = effective hourly rate.
     * Also includes breakdown by activity type.
     */
    public function getProfitabilityReport(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, c.domain,
                   COALESCE(inv.total_revenue, 0) as revenue,
                   COALESCE(exp.total_expenses, 0) as expenses,
                   COALESCE(inv.total_revenue, 0) - COALESCE(exp.total_expenses, 0) as profit,
                   COALESCE(tt.total_hours, 0) as hours,
                   CASE 
                       WHEN COALESCE(tt.total_hours, 0) > 0 
                       THEN (COALESCE(inv.total_revenue, 0) - COALESCE(exp.total_expenses, 0)) / tt.total_hours 
                       ELSE NULL 
                   END as effective_rate,
                   c.hourly_rate as target_rate
            FROM clients c
            LEFT JOIN (
                SELECT client_id, SUM(total) as total_revenue 
                FROM crm_invoices 
                WHERE user_email = ? AND status = 'paid' 
                GROUP BY client_id
            ) inv ON inv.client_id = c.id
            LEFT JOIN (
                SELECT client_id, SUM(amount) as total_expenses
                FROM crm_expenses
                WHERE user_email = ?
                GROUP BY client_id
            ) exp ON exp.client_id = c.id
            LEFT JOIN (
                SELECT client_id, SUM(duration_seconds) / 3600.0 as total_hours 
                FROM webmail_client_time_tracking 
                WHERE user_email = ? 
                GROUP BY client_id
            ) tt ON tt.client_id = c.id
            WHERE COALESCE(inv.total_revenue, 0) > 0 OR COALESCE(tt.total_hours, 0) > 0
            ORDER BY effective_rate DESC
        ");
        $stmt->execute([$userEmail, $userEmail, $userEmail]);
        $clients = $stmt->fetchAll();

        foreach ($clients as &$client) {
            $client['revenue'] = (float)$client['revenue'];
            $client['expenses'] = (float)$client['expenses'];
            $client['profit'] = (float)$client['profit'];
            $client['hours'] = round((float)$client['hours'], 1);
            $client['effective_rate'] = $client['effective_rate'] !== null
                ? round((float)$client['effective_rate'], 0)
                : null;
            $client['target_rate'] = $client['target_rate'] !== null
                ? (float)$client['target_rate']
                : null;
            // Rate health: green if effective >= target, red if below
            if ($client['effective_rate'] !== null && $client['target_rate'] !== null && $client['target_rate'] > 0) {
                $ratio = $client['effective_rate'] / $client['target_rate'];
                $client['rate_health'] = $ratio >= 1.0 ? 'profitable' : ($ratio >= 0.7 ? 'marginal' : 'unprofitable');
            } else {
                $client['rate_health'] = 'unknown';
            }
        }

        // Activity breakdown across all clients
        $stmt = $this->db->prepare("
            SELECT activity_type, 
                   SUM(duration_seconds) / 3600.0 as total_hours,
                   COUNT(DISTINCT client_id) as client_count
            FROM webmail_client_time_tracking
            WHERE user_email = ?
            GROUP BY activity_type
            ORDER BY total_hours DESC
        ");
        $stmt->execute([$userEmail]);
        $activityBreakdown = $stmt->fetchAll();

        foreach ($activityBreakdown as &$a) {
            $a['total_hours'] = round((float)$a['total_hours'], 1);
        }

        return [
            'clients' => $clients,
            'activity_breakdown' => $activityBreakdown,
        ];
    }

    // =========================================================================
    // Deal Forecasting
    // =========================================================================

    /**
     * Weighted deal forecast by month.
     * Returns optimistic (expected_value sum) and weighted (expected_value * probability / 100).
     *
     * @param int $months How many months forward to forecast
     */
    public function getForecastReport(string $userEmail, int $months = 6): array
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
        $forecastData = $stmt->fetchAll();

        // Ensure we have entries for every month in the range
        $forecast = [];
        $startDate = new \DateTime('first day of this month');
        for ($i = 0; $i < $months; $i++) {
            $monthKey = $startDate->format('Y-m');
            $existing = null;
            foreach ($forecastData as $row) {
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

        // Total forecast
        $totalOptimistic = array_sum(array_column($forecast, 'optimistic_value'));
        $totalWeighted = array_sum(array_column($forecast, 'weighted_value'));

        // Deals without close date
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(expected_value), 0) as value
            FROM crm_deals
            WHERE user_email = ? AND pipeline_stage NOT IN ('won', 'lost') AND expected_close_date IS NULL
        ");
        $stmt->execute([$userEmail]);
        $unscheduled = $stmt->fetch();

        return [
            'forecast' => $forecast,
            'total_optimistic' => $totalOptimistic,
            'total_weighted' => round($totalWeighted, 0),
            'unscheduled_deals' => [
                'count' => (int)$unscheduled['count'],
                'value' => (float)$unscheduled['value'],
            ],
            'months' => $months,
        ];
    }

    // =========================================================================
    // Conversion Funnel
    // =========================================================================

    /**
     * Stage-to-stage conversion rates, avg days in each stage, velocity.
     * Uses crm_deal_stage_history if available, falls back to current snapshot.
     */
    public function getFunnelReport(string $userEmail): array
    {
        $stages = ['lead', 'contacted', 'proposal', 'negotiation', 'won', 'lost'];

        // Check if stage history table exists
        $hasHistory = false;
        try {
            $this->db->query("SELECT 1 FROM crm_deal_stage_history LIMIT 1");
            $hasHistory = true;
        } catch (\Throwable $e) {
            // Table doesn't exist yet
        }

        if ($hasHistory) {
            return $this->getFunnelFromHistory($userEmail, $stages);
        }

        return $this->getFunnelFromSnapshot($userEmail, $stages);
    }

    /**
     * Funnel from stage history (accurate, tracks actual transitions)
     */
    private function getFunnelFromHistory(string $userEmail, array $stages): array
    {
        // Count deals that entered each stage
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

        // Also count deals currently in 'lead' that may not have history (created directly)
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM crm_deals WHERE user_email = ?");
        $stmt->execute([$userEmail]);
        $totalDeals = (int)$stmt->fetchColumn();
        if (!isset($stageCounts['lead'])) {
            $stageCounts['lead'] = $totalDeals;
        }

        // Avg days per stage (from history transitions)
        $stmt = $this->db->prepare("
            SELECT h.from_stage,
                   AVG(TIMESTAMPDIFF(DAY, 
                       (SELECT MAX(h2.changed_at) FROM crm_deal_stage_history h2 
                        WHERE h2.deal_id = h.deal_id AND h2.to_stage = h.from_stage),
                       h.changed_at
                   )) as avg_days
            FROM crm_deal_stage_history h
            INNER JOIN crm_deals d ON d.id = h.deal_id
            WHERE d.user_email = ? AND h.from_stage IS NOT NULL
            GROUP BY h.from_stage
        ");
        $stmt->execute([$userEmail]);
        $avgDays = [];
        foreach ($stmt->fetchAll() as $row) {
            $avgDays[$row['from_stage']] = round((float)$row['avg_days'], 1);
        }

        // Build funnel
        $funnel = [];
        $activeStages = array_diff($stages, ['lost']);
        foreach ($activeStages as $i => $stage) {
            $count = $stageCounts[$stage] ?? 0;
            $prevCount = $i > 0 ? ($stageCounts[$activeStages[$i - 1]] ?? 0) : $count;
            $conversionRate = $prevCount > 0 ? round(($count / $prevCount) * 100, 1) : 0;

            $funnel[] = [
                'stage' => $stage,
                'count' => $count,
                'conversion_from_previous' => $i > 0 ? $conversionRate : 100,
                'conversion_from_lead' => ($stageCounts['lead'] ?? 0) > 0
                    ? round(($count / $stageCounts['lead']) * 100, 1)
                    : 0,
                'avg_days_in_stage' => $avgDays[$stage] ?? null,
            ];
        }

        // Overall velocity: avg days from lead to won
        $stmt = $this->db->prepare("
            SELECT AVG(DATEDIFF(d.actual_close_date, d.created_at)) as avg_days_to_close
            FROM crm_deals d
            WHERE d.user_email = ? AND d.pipeline_stage = 'won' AND d.actual_close_date IS NOT NULL
        ");
        $stmt->execute([$userEmail]);
        $avgCloseTime = $stmt->fetchColumn();

        // Lost stats
        $lostCount = $stageCounts['lost'] ?? 0;

        return [
            'funnel' => $funnel,
            'lost_count' => $lostCount,
            'total_deals' => $totalDeals,
            'avg_days_to_close' => $avgCloseTime !== null ? round((float)$avgCloseTime, 1) : null,
            'win_rate' => $totalDeals > 0
                ? round((($stageCounts['won'] ?? 0) / $totalDeals) * 100, 1)
                : 0,
            'source' => 'history',
        ];
    }

    /**
     * Funnel from current deal snapshot (less accurate, but works without history table)
     */
    private function getFunnelFromSnapshot(string $userEmail, array $stages): array
    {
        $stmt = $this->db->prepare("
            SELECT pipeline_stage, COUNT(*) as count
            FROM crm_deals
            WHERE user_email = ?
            GROUP BY pipeline_stage
        ");
        $stmt->execute([$userEmail]);
        $stageCounts = [];
        $totalDeals = 0;
        foreach ($stmt->fetchAll() as $row) {
            $stageCounts[$row['pipeline_stage']] = (int)$row['count'];
            $totalDeals += (int)$row['count'];
        }

        // For snapshot, we estimate cumulative: deals that reached each stage
        // = current in that stage + all stages after it
        $activeStages = ['lead', 'contacted', 'proposal', 'negotiation', 'won'];
        $cumulative = [];
        $runningTotal = 0;
        for ($i = count($activeStages) - 1; $i >= 0; $i--) {
            $runningTotal += ($stageCounts[$activeStages[$i]] ?? 0);
            $cumulative[$activeStages[$i]] = $runningTotal;
        }
        // Add lost deals to lead count (they all started as leads)
        $cumulative['lead'] = ($cumulative['lead'] ?? 0) + ($stageCounts['lost'] ?? 0);

        $funnel = [];
        foreach ($activeStages as $i => $stage) {
            $count = $cumulative[$stage] ?? 0;
            $prevCount = $i > 0 ? ($cumulative[$activeStages[$i - 1]] ?? 0) : $count;
            $conversionRate = $prevCount > 0 ? round(($count / $prevCount) * 100, 1) : 0;

            $funnel[] = [
                'stage' => $stage,
                'count' => $count,
                'conversion_from_previous' => $i > 0 ? $conversionRate : 100,
                'conversion_from_lead' => ($cumulative['lead'] ?? 0) > 0
                    ? round(($count / $cumulative['lead']) * 100, 1)
                    : 0,
                'avg_days_in_stage' => null,
            ];
        }

        // Overall velocity
        $stmt = $this->db->prepare("
            SELECT AVG(DATEDIFF(actual_close_date, created_at)) as avg_days_to_close
            FROM crm_deals
            WHERE user_email = ? AND pipeline_stage = 'won' AND actual_close_date IS NOT NULL
        ");
        $stmt->execute([$userEmail]);
        $avgCloseTime = $stmt->fetchColumn();

        return [
            'funnel' => $funnel,
            'lost_count' => $stageCounts['lost'] ?? 0,
            'total_deals' => $totalDeals,
            'avg_days_to_close' => $avgCloseTime !== null ? round((float)$avgCloseTime, 1) : null,
            'win_rate' => $totalDeals > 0
                ? round((($stageCounts['won'] ?? 0) / $totalDeals) * 100, 1)
                : 0,
            'source' => 'snapshot',
        ];
    }
}

