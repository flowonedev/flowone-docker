<?php

namespace Webmail\Addons\BoardPro\Services;

use PDO;

/**
 * BoardProAIService
 *
 * AI intelligence layer for Board Pro: risk detection, board summarization,
 * delivery estimation, client update drafting.
 * Delegates to the existing AIService for OpenAI API calls.
 */
class BoardProAIService
{
    private PDO $db;
    private array $config;

    /** Max AI calls per user per hour */
    private const RATE_LIMIT_PER_HOUR = 10;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Rate Limiting
    // =========================================================================

    private function checkRateLimit(string $userEmail): bool
    {
        // Use boardpro_automation_log to track AI calls rate
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM boardpro_automation_log
            WHERE user_email = ? AND action_taken LIKE 'ai_%' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$userEmail]);
        $count = (int) $stmt->fetchColumn();

        return $count < self::RATE_LIMIT_PER_HOUR;
    }

    private function logAICall(string $userEmail, string $action, int $boardId): void
    {
        // We reuse the automation log for AI call tracking (rule_id = 0 for AI calls)
        // First ensure a dummy rule exists or just insert with 0 and no FK
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS boardpro_ai_log (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    board_id INT NOT NULL,
                    action_type VARCHAR(100) NOT NULL,
                    tokens_used INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_time (user_email, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $stmt = $this->db->prepare("
                INSERT INTO boardpro_ai_log (user_email, board_id, action_type) VALUES (?, ?, ?)
            ");
            $stmt->execute([$userEmail, $boardId, $action]);
        } catch (\Throwable $e) {
            error_log("BoardProAI log error: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Board Summarization
    // =========================================================================

    /**
     * Generate an AI summary of a board's current state
     */
    public function summarizeBoard(int $boardId, string $userEmail): array
    {
        if (!$this->checkRateLimit($userEmail)) {
            return ['success' => false, 'error' => 'AI rate limit exceeded (max ' . self::RATE_LIMIT_PER_HOUR . '/hour)'];
        }

        // Check for cached summary (within last hour)
        $stmt = $this->db->prepare("
            SELECT last_ai_summary, last_ai_summary_at
            FROM boardpro_board_settings
            WHERE board_id = ? AND last_ai_summary_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$boardId]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cached && $cached['last_ai_summary']) {
            return [
                'success' => true,
                'summary' => $cached['last_ai_summary'],
                'generated_at' => $cached['last_ai_summary_at'],
                'cached' => true,
            ];
        }

        // Gather board data for AI
        $boardData = $this->gatherBoardContext($boardId);

        $prompt = $this->buildSummaryPrompt($boardData);

        try {
            $aiService = new \Webmail\Addons\AIAssistant\Services\AIService($this->config);
            $response = $aiService->chat([
                ['role' => 'system', 'content' => 'You are a project management assistant. Provide concise, actionable board summaries. Focus on: progress overview, blockers, upcoming deadlines, and key recommendations. Keep it under 300 words.'],
                ['role' => 'user', 'content' => $prompt],
            ]);

            $summary = $response['content'] ?? $response['message'] ?? '';

            // Cache the summary
            $stmtCache = $this->db->prepare("
                INSERT INTO boardpro_board_settings (board_id, last_ai_summary, last_ai_summary_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    last_ai_summary = VALUES(last_ai_summary),
                    last_ai_summary_at = NOW()
            ");
            $stmtCache->execute([$boardId, $summary]);

            $this->logAICall($userEmail, 'ai_summarize', $boardId);

            return [
                'success' => true,
                'summary' => $summary,
                'generated_at' => date('Y-m-d H:i:s'),
                'cached' => false,
            ];
        } catch (\Throwable $e) {
            error_log("BoardProAI summarize error: " . $e->getMessage());
            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    // =========================================================================
    // Risk Detection
    // =========================================================================

    /**
     * Analyze board for risks (overdue, idle, financial gaps, etc.)
     */
    public function detectRisks(int $boardId, string $userEmail): array
    {
        if (!$this->checkRateLimit($userEmail)) {
            return ['success' => false, 'error' => 'AI rate limit exceeded'];
        }

        $boardData = $this->gatherBoardContext($boardId);

        $prompt = $this->buildRiskPrompt($boardData);

        try {
            $aiService = new \Webmail\Addons\AIAssistant\Services\AIService($this->config);
            $response = $aiService->chat([
                ['role' => 'system', 'content' => 'You are a project risk analyst. Identify and categorize risks as: HIGH (immediate action needed), MEDIUM (monitor closely), LOW (awareness only). For each risk, provide: risk description, affected cards/areas, recommended action. Return as structured JSON array.'],
                ['role' => 'user', 'content' => $prompt],
            ]);

            $this->logAICall($userEmail, 'ai_risk', $boardId);

            $content = $response['content'] ?? $response['message'] ?? '';

            // Try to parse JSON from response
            $risks = json_decode($content, true);
            if (!$risks) {
                $risks = [['severity' => 'info', 'description' => $content, 'action' => 'Review manually']];
            }

            return [
                'success' => true,
                'risks' => $risks,
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            error_log("BoardProAI risk error: " . $e->getMessage());
            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    // =========================================================================
    // Delivery Estimation
    // =========================================================================

    /**
     * Estimate delivery timeline based on current velocity
     */
    public function estimateDelivery(int $boardId, string $userEmail): array
    {
        if (!$this->checkRateLimit($userEmail)) {
            return ['success' => false, 'error' => 'AI rate limit exceeded'];
        }

        $boardData = $this->gatherBoardContext($boardId);

        $prompt = $this->buildEstimationPrompt($boardData);

        try {
            $aiService = new \Webmail\Addons\AIAssistant\Services\AIService($this->config);
            $response = $aiService->chat([
                ['role' => 'system', 'content' => 'You are a project delivery analyst. Based on current card velocity (completed vs remaining), provide: estimated completion date, confidence level (high/medium/low), key assumptions, and risks that could delay delivery. Be realistic and data-driven.'],
                ['role' => 'user', 'content' => $prompt],
            ]);

            $this->logAICall($userEmail, 'ai_estimate', $boardId);

            return [
                'success' => true,
                'estimation' => $response['content'] ?? $response['message'] ?? '',
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    // =========================================================================
    // Client Update Drafting
    // =========================================================================

    /**
     * Draft a client update email based on card progress
     */
    public function draftClientUpdate(int $cardId, string $userEmail): array
    {
        if (!$this->checkRateLimit($userEmail)) {
            return ['success' => false, 'error' => 'AI rate limit exceeded'];
        }

        // Get card details
        $stmt = $this->db->prepare("
            SELECT bc.*, bl.name AS list_name, b.name AS board_name, b.id AS board_id
            FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            JOIN webmail_boards b ON b.id = bl.board_id
            WHERE bc.id = ?
        ");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            return ['success' => false, 'error' => 'Card not found'];
        }

        // Get recent activity
        $stmtActivity = $this->db->prepare("
            SELECT action, details, created_at
            FROM webmail_card_activity
            WHERE card_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmtActivity->execute([$cardId]);
        $activity = $stmtActivity->fetchAll(PDO::FETCH_ASSOC);

        $prompt = "Draft a professional client update email about this task:\n\n";
        $prompt .= "Project: {$card['board_name']}\n";
        $prompt .= "Task: {$card['title']}\n";
        $prompt .= "Current Stage: {$card['list_name']}\n";
        $prompt .= "Description: " . ($card['description'] ?? 'N/A') . "\n\n";

        if (!empty($activity)) {
            $prompt .= "Recent Activity:\n";
            foreach ($activity as $act) {
                $prompt .= "- [{$act['created_at']}] {$act['action']}: {$act['details']}\n";
            }
        }

        try {
            $aiService = new \Webmail\Addons\AIAssistant\Services\AIService($this->config);
            $response = $aiService->chat([
                ['role' => 'system', 'content' => 'You are a professional project manager drafting client update emails. Be concise, positive but honest, and include: current status, recent progress, next steps, and any blockers. Keep a professional yet friendly tone. No more than 200 words.'],
                ['role' => 'user', 'content' => $prompt],
            ]);

            $this->logAICall($userEmail, 'ai_draft_update', (int) $card['board_id']);

            return [
                'success' => true,
                'draft' => $response['content'] ?? $response['message'] ?? '',
                'card_title' => $card['title'],
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    // =========================================================================
    // Context Gathering
    // =========================================================================

    private function gatherBoardContext(int $boardId): array
    {
        $data = ['board_id' => $boardId];

        // Board info
        $stmt = $this->db->prepare("SELECT * FROM webmail_boards WHERE id = ?");
        $stmt->execute([$boardId]);
        $data['board'] = $stmt->fetch(PDO::FETCH_ASSOC);

        // Lists with card counts
        $stmt = $this->db->prepare("
            SELECT bl.id, bl.name, bl.position,
                   COUNT(bc.id) AS card_count,
                   SUM(CASE WHEN bc.completed = 1 THEN 1 ELSE 0 END) AS completed_count,
                   SUM(CASE WHEN bc.due_date < NOW() AND bc.completed = 0 THEN 1 ELSE 0 END) AS overdue_count
            FROM webmail_board_lists bl
            LEFT JOIN webmail_board_cards bc ON bc.list_id = bl.id
            WHERE bl.board_id = ?
            GROUP BY bl.id, bl.name, bl.position
            ORDER BY bl.position ASC
        ");
        $stmt->execute([$boardId]);
        $data['lists'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cards with upcoming due dates
        $stmt = $this->db->prepare("
            SELECT bc.title, bc.due_date, bc.assigned_to, bl.name AS list_name, bc.completed
            FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            WHERE bl.board_id = ? AND bc.due_date IS NOT NULL
            ORDER BY bc.due_date ASC
            LIMIT 20
        ");
        $stmt->execute([$boardId]);
        $data['cards_with_dates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Financial summary
        $financialService = new BoardProFinancialService($this->config);
        $data['financials'] = $financialService->getBoardFinancialSummary($boardId);

        // Idle cards (no update in 7+ days)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            WHERE bl.board_id = ? AND bc.completed = 0 AND bc.updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$boardId]);
        $data['idle_card_count'] = (int) $stmt->fetchColumn();

        return $data;
    }

    private function buildSummaryPrompt(array $data): string
    {
        $board = $data['board'] ?? [];
        $prompt = "Board: " . ($board['name'] ?? 'Unknown') . "\n\n";

        $prompt .= "Lists:\n";
        foreach ($data['lists'] ?? [] as $list) {
            $prompt .= "- {$list['name']}: {$list['card_count']} cards ({$list['completed_count']} done, {$list['overdue_count']} overdue)\n";
        }

        if (!empty($data['cards_with_dates'])) {
            $prompt .= "\nUpcoming deadlines:\n";
            foreach ($data['cards_with_dates'] as $card) {
                $status = $card['completed'] ? 'DONE' : 'PENDING';
                $prompt .= "- [{$status}] {$card['title']} (due: {$card['due_date']}, stage: {$card['list_name']})\n";
            }
        }

        $prompt .= "\nIdle cards (7+ days no activity): {$data['idle_card_count']}\n";

        if (!empty($data['financials']['by_currency'])) {
            $prompt .= "\nFinancials:\n";
            foreach ($data['financials']['by_currency'] as $currency => $fin) {
                $prompt .= "- {$currency}: Revenue {$fin['total_revenue']}, Cost {$fin['total_cost']}, Margin {$fin['margin_percent']}%\n";
            }
        }

        return $prompt;
    }

    private function buildRiskPrompt(array $data): string
    {
        return "Analyze this board for project risks:\n\n" . $this->buildSummaryPrompt($data) .
            "\n\nReturn risks as JSON array: [{\"severity\": \"HIGH|MEDIUM|LOW\", \"description\": \"...\", \"affected\": \"...\", \"action\": \"...\"}]";
    }

    private function buildEstimationPrompt(array $data): string
    {
        $totalCards = 0;
        $completedCards = 0;
        foreach ($data['lists'] ?? [] as $list) {
            $totalCards += (int) $list['card_count'];
            $completedCards += (int) $list['completed_count'];
        }

        return "Estimate delivery for this board:\n\n" . $this->buildSummaryPrompt($data) .
            "\n\nTotal cards: {$totalCards}, Completed: {$completedCards}" .
            "\nProvide estimated completion date and confidence level.";
    }
}

