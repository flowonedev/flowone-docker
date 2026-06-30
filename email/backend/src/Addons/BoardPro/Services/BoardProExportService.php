<?php

namespace Webmail\Addons\BoardPro\Services;

use PDO;

/**
 * BoardProExportService
 *
 * Executive PDF generation, revenue projections, workload analytics.
 * Uses HTML-to-PDF conversion for report generation.
 */
class BoardProExportService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Executive Report
    // =========================================================================

    /**
     * Generate executive report data for a board
     * Returns structured data that can be rendered as HTML/PDF
     */
    public function generateExecutiveReport(int $boardId, string $userEmail): array
    {
        $financialService = new BoardProFinancialService($this->config);
        $timelineService = new BoardProTimelineService($this->config);

        // Board info
        $stmt = $this->db->prepare("SELECT * FROM webmail_boards WHERE id = ?");
        $stmt->execute([$boardId]);
        $board = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$board) {
            return ['success' => false, 'error' => 'Board not found'];
        }

        // Lists with progress
        $stmt = $this->db->prepare("
            SELECT bl.name,
                   COUNT(bc.id) AS total_cards,
                   SUM(CASE WHEN bc.completed = 1 THEN 1 ELSE 0 END) AS completed,
                   SUM(CASE WHEN bc.due_date < NOW() AND bc.completed = 0 THEN 1 ELSE 0 END) AS overdue
            FROM webmail_board_lists bl
            LEFT JOIN webmail_board_cards bc ON bc.list_id = bl.id
            WHERE bl.board_id = ?
            GROUP BY bl.id, bl.name
            ORDER BY bl.position ASC
        ");
        $stmt->execute([$boardId]);
        $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Overall progress
        $totalCards = array_sum(array_column($lists, 'total_cards'));
        $completedCards = array_sum(array_column($lists, 'completed'));
        $overdueCards = array_sum(array_column($lists, 'overdue'));

        // Financial summary
        $financials = $financialService->getBoardFinancialSummary($boardId);

        // Team workload
        $workload = $this->getWorkloadAnalytics($boardId);

        // Recent milestones (completed in last 30 days)
        $stmt = $this->db->prepare("
            SELECT bc.title, bc.updated_at, bl.name AS list_name
            FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            WHERE bl.board_id = ? AND bc.completed = 1
              AND bc.updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY bc.updated_at DESC
            LIMIT 10
        ");
        $stmt->execute([$boardId]);
        $recentMilestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'report' => [
                'board_name' => $board['name'],
                'generated_at' => date('Y-m-d H:i:s'),
                'generated_by' => $userEmail,
                'progress' => [
                    'total_cards' => $totalCards,
                    'completed' => $completedCards,
                    'overdue' => $overdueCards,
                    'completion_percent' => $totalCards > 0 ? round(($completedCards / $totalCards) * 100, 1) : 0,
                ],
                'lists' => $lists,
                'financials' => $financials,
                'workload' => $workload,
                'recent_milestones' => $recentMilestones,
            ],
        ];
    }

    /**
     * Generate HTML for the executive report (for PDF conversion)
     */
    public function generateReportHtml(int $boardId, string $userEmail): string
    {
        $data = $this->generateExecutiveReport($boardId, $userEmail);

        if (!$data['success']) {
            return '<h1>Error generating report</h1>';
        }

        $report = $data['report'];
        $progress = $report['progress'];

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $html .= '<style>';
        $html .= 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 40px; color: #1a1a2e; }';
        $html .= 'h1 { color: #16213e; border-bottom: 2px solid #0f3460; padding-bottom: 10px; }';
        $html .= 'h2 { color: #0f3460; margin-top: 30px; }';
        $html .= '.meta { color: #666; font-size: 12px; margin-bottom: 30px; }';
        $html .= '.progress-bar { background: #e0e0e0; border-radius: 8px; height: 20px; margin: 10px 0; }';
        $html .= '.progress-fill { background: #0f3460; border-radius: 8px; height: 20px; text-align: center; color: white; font-size: 12px; line-height: 20px; }';
        $html .= 'table { width: 100%; border-collapse: collapse; margin: 15px 0; }';
        $html .= 'th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #e0e0e0; }';
        $html .= 'th { background: #f5f5f5; font-weight: 600; }';
        $html .= '.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; }';
        $html .= '.badge-green { background: #d4edda; color: #155724; }';
        $html .= '.badge-red { background: #f8d7da; color: #721c24; }';
        $html .= '.badge-yellow { background: #fff3cd; color: #856404; }';
        $html .= '</style></head><body>';

        $html .= "<h1>{$report['board_name']} - Executive Report</h1>";
        $html .= "<div class='meta'>Generated: {$report['generated_at']} by {$report['generated_by']}</div>";

        // Progress overview
        $html .= '<h2>Progress Overview</h2>';
        $pct = $progress['completion_percent'];
        $html .= "<p>Cards: {$progress['completed']}/{$progress['total_cards']} completed ({$pct}%)";
        if ($progress['overdue'] > 0) {
            $html .= " | <span class='badge badge-red'>{$progress['overdue']} overdue</span>";
        }
        $html .= '</p>';
        $html .= "<div class='progress-bar'><div class='progress-fill' style='width:{$pct}%'>{$pct}%</div></div>";

        // List breakdown
        $html .= '<h2>Stage Breakdown</h2>';
        $html .= '<table><tr><th>Stage</th><th>Cards</th><th>Done</th><th>Overdue</th></tr>';
        foreach ($report['lists'] as $list) {
            $html .= "<tr><td>{$list['name']}</td><td>{$list['total_cards']}</td><td>{$list['completed']}</td><td>{$list['overdue']}</td></tr>";
        }
        $html .= '</table>';

        // Financials
        if (!empty($report['financials']['by_currency'])) {
            $html .= '<h2>Financial Summary</h2>';
            $html .= '<table><tr><th>Currency</th><th>Revenue</th><th>Cost</th><th>Margin</th><th>Paid</th><th>Overdue</th></tr>';
            foreach ($report['financials']['by_currency'] as $currency => $fin) {
                $html .= "<tr>";
                $html .= "<td>{$currency}</td>";
                $html .= "<td>" . number_format($fin['total_revenue'], 0) . "</td>";
                $html .= "<td>" . number_format($fin['total_cost'], 0) . "</td>";
                $html .= "<td>{$fin['margin_percent']}%</td>";
                $html .= "<td>" . number_format($fin['paid_revenue'], 0) . "</td>";
                $html .= "<td>" . number_format($fin['overdue_revenue'], 0) . "</td>";
                $html .= "</tr>";
            }
            $html .= '</table>';
        }

        // Team workload
        if (!empty($report['workload'])) {
            $html .= '<h2>Team Workload</h2>';
            $html .= '<table><tr><th>Member</th><th>Assigned</th><th>Completed</th><th>Overdue</th></tr>';
            foreach ($report['workload'] as $member) {
                $html .= "<tr><td>{$member['email']}</td><td>{$member['assigned']}</td><td>{$member['completed']}</td><td>{$member['overdue']}</td></tr>";
            }
            $html .= '</table>';
        }

        // Recent milestones
        if (!empty($report['recent_milestones'])) {
            $html .= '<h2>Recent Milestones (Last 30 Days)</h2>';
            $html .= '<table><tr><th>Task</th><th>Stage</th><th>Completed</th></tr>';
            foreach ($report['recent_milestones'] as $m) {
                $html .= "<tr><td>{$m['title']}</td><td>{$m['list_name']}</td><td>{$m['updated_at']}</td></tr>";
            }
            $html .= '</table>';
        }

        $html .= '</body></html>';
        return $html;
    }

    // =========================================================================
    // Revenue Projections
    // =========================================================================

    /**
     * Generate revenue projection based on current data and trends
     */
    public function getRevenueProjection(int $boardId): array
    {
        $financialService = new BoardProFinancialService($this->config);
        $summary = $financialService->getBoardFinancialSummary($boardId);

        // Get completion rate (cards completed per week over last 4 weeks)
        $stmt = $this->db->prepare("
            SELECT
                YEARWEEK(bc.updated_at) AS week,
                COUNT(*) AS completed_count,
                SUM(COALESCE(cf.estimated_revenue, 0)) AS week_revenue
            FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            LEFT JOIN boardpro_card_financials cf ON cf.card_id = bc.id
            WHERE bl.board_id = ? AND bc.completed = 1
              AND bc.updated_at > DATE_SUB(NOW(), INTERVAL 4 WEEK)
            GROUP BY YEARWEEK(bc.updated_at)
            ORDER BY week ASC
        ");
        $stmt->execute([$boardId]);
        $weeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate velocity
        $avgCardsPerWeek = 0;
        $avgRevenuePerWeek = 0;
        if (count($weeklyData) > 0) {
            $avgCardsPerWeek = array_sum(array_column($weeklyData, 'completed_count')) / count($weeklyData);
            $avgRevenuePerWeek = array_sum(array_column($weeklyData, 'week_revenue')) / count($weeklyData);
        }

        // Remaining cards
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            WHERE bl.board_id = ? AND bc.completed = 0
        ");
        $stmt->execute([$boardId]);
        $remainingCards = (int) $stmt->fetchColumn();

        // Projected weeks to completion
        $projectedWeeks = $avgCardsPerWeek > 0 ? ceil($remainingCards / $avgCardsPerWeek) : null;

        return [
            'weekly_velocity' => round($avgCardsPerWeek, 1),
            'weekly_revenue_velocity' => round($avgRevenuePerWeek, 2),
            'remaining_cards' => $remainingCards,
            'projected_weeks_to_completion' => $projectedWeeks,
            'projected_completion_date' => $projectedWeeks
                ? date('Y-m-d', strtotime("+{$projectedWeeks} weeks"))
                : null,
            'weekly_breakdown' => $weeklyData,
            'financial_summary' => $summary,
        ];
    }

    // =========================================================================
    // Workload Analytics
    // =========================================================================

    /**
     * Get workload distribution across team members
     */
    public function getWorkloadAnalytics(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(bc.assigned_to, 'Unassigned') AS email,
                COUNT(*) AS assigned,
                SUM(CASE WHEN bc.completed = 1 THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN bc.due_date < NOW() AND bc.completed = 0 THEN 1 ELSE 0 END) AS overdue,
                SUM(COALESCE(cf.estimated_revenue, 0)) AS total_revenue,
                SUM(COALESCE(cf.time_budget_hours, 0)) AS total_budget_hours
            FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            LEFT JOIN boardpro_card_financials cf ON cf.card_id = bc.id
            WHERE bl.board_id = ?
            GROUP BY bc.assigned_to
            ORDER BY assigned DESC
        ");
        $stmt->execute([$boardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

