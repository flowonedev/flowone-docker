<?php

namespace Webmail\Addons\BoardPro\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\BoardPro\Services\BoardProEmailService;
use Webmail\Addons\BoardPro\Services\BoardProFinancialService;
use Webmail\Addons\BoardPro\Services\BoardProTimelineService;
use Webmail\Addons\BoardPro\Services\BoardProAIService;
use Webmail\Addons\BoardPro\Services\BoardProExportService;
use Webmail\Addons\BoardPro\Services\BoardProAutomationService;
use Webmail\Addons\BoardPro\Services\ScopeRadarService;
use Webmail\Addons\KanbanBoards\Services\BoardService;
use Webmail\Services\RedisCacheService;

/**
 * BoardProController
 *
 * Main API controller for Board Pro addon.
 * All routes are prefixed with /board-pro/
 */
class BoardProController extends BaseController
{
    private ?BoardProEmailService $emailService = null;
    private ?BoardProFinancialService $financialService = null;
    private ?BoardProTimelineService $timelineService = null;
    private ?BoardProAIService $aiService = null;
    private ?BoardProExportService $exportService = null;
    private ?BoardProAutomationService $automationService = null;
    private ?BoardService $boardService = null;
    private ?RedisCacheService $redisCache = null;
    private ?ScopeRadarService $scopeRadarService = null;

    // =========================================================================
    // Service Lazy Init
    // =========================================================================

    private function getEmailService(): BoardProEmailService
    {
        if (!$this->emailService) {
            $this->emailService = new BoardProEmailService($this->config);
        }
        return $this->emailService;
    }

    private function getFinancialService(): BoardProFinancialService
    {
        if (!$this->financialService) {
            $this->financialService = new BoardProFinancialService($this->config);
        }
        return $this->financialService;
    }

    private function getTimelineService(): BoardProTimelineService
    {
        if (!$this->timelineService) {
            $this->timelineService = new BoardProTimelineService($this->config);
        }
        return $this->timelineService;
    }

    private function getAIService(): BoardProAIService
    {
        if (!$this->aiService) {
            $this->aiService = new BoardProAIService($this->config);
        }
        return $this->aiService;
    }

    private function getExportService(): BoardProExportService
    {
        if (!$this->exportService) {
            $this->exportService = new BoardProExportService($this->config);
        }
        return $this->exportService;
    }

    private function getAutomationService(): BoardProAutomationService
    {
        if (!$this->automationService) {
            $this->automationService = new BoardProAutomationService($this->config);
        }
        return $this->automationService;
    }

    private function getBoardService(): BoardService
    {
        if (!$this->boardService) {
            $this->boardService = new BoardService($this->config);
        }
        return $this->boardService;
    }

    private function getRedisCache(): RedisCacheService
    {
        if (!$this->redisCache) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }

    private function getScopeRadarService(): ScopeRadarService
    {
        if (!$this->scopeRadarService) {
            $this->scopeRadarService = new ScopeRadarService($this->config);
        }
        return $this->scopeRadarService;
    }

    /**
     * Verify the current user has access to the given board
     */
    private function verifyBoardAccess(int $boardId, string $userEmail): bool
    {
        return $this->getBoardService()->hasAccess($userEmail, $boardId);
    }

    /**
     * Publish real-time event via Redis
     */
    private function publishEvent(string $channel, array $data): void
    {
        try {
            $this->getRedisCache()->publish($channel, $data);
        } catch (\Throwable $e) {
            error_log("BoardPro publish error: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Card-Email Linking (Phase 1A)
    // =========================================================================

    /**
     * POST /board-pro/cards/{card_id}/emails
     */
    public function linkEmailToCard(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $cardId = (int) $request->param('card_id');

        $required = $this->validateRequired($request, ['board_id', 'email_uid', 'email_folder']);
        if ($required) return $required;

        $boardId = (int) $request->input('board_id');
        if (!$this->verifyBoardAccess($boardId, $userEmail)) {
            return Response::error('Access denied', 403);
        }

        $link = $this->getEmailService()->linkEmailToCard([
            'card_id' => $cardId,
            'board_id' => $boardId,
            'email_uid' => (int) $request->input('email_uid'),
            'email_folder' => $request->input('email_folder'),
            'email_subject' => $request->input('email_subject'),
            'email_from' => $request->input('email_from'),
            'email_date' => $request->input('email_date'),
            'thread_id' => $request->input('thread_id'),
            'reply_status' => $request->input('reply_status', 'none'),
            'linked_by' => $userEmail,
        ]);

        // Fire automation trigger
        $this->getAutomationService()->fireTrigger('email_received_on_card', $boardId, [
            'card_id' => $cardId,
            'email_received' => true,
            'board_id' => $boardId,
        ]);

        $this->publishEvent('board:' . $boardId, [
            'type' => 'boardpro_email_linked',
            'card_id' => $cardId,
            'link' => $link,
        ]);

        return Response::json(['success' => true, 'data' => $link]);
    }

    /**
     * GET /board-pro/cards/{card_id}/emails
     */
    public function getCardEmails(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int) $request->param('card_id');
        $emails = $this->getEmailService()->getCardEmails($cardId);

        return Response::json(['success' => true, 'data' => $emails]);
    }

    /**
     * DELETE /board-pro/cards/{card_id}/emails/{id}
     */
    public function unlinkEmail(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $id = (int) $request->param('id');

        $this->getEmailService()->unlinkEmail($id, $userEmail);

        return Response::json(['success' => true]);
    }

    /**
     * PUT /board-pro/card-emails/{id}/reply-status
     */
    public function updateReplyStatus(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int) $request->param('id');
        $status = $request->input('reply_status');

        if (!in_array($status, ['none', 'replied', 'awaiting', 'forwarded'])) {
            return Response::error('Invalid reply status', 400);
        }

        $this->getEmailService()->updateReplyStatus($id, $status);

        return Response::json(['success' => true]);
    }

    /**
     * GET /board-pro/boards/{id}/awaiting-replies
     */
    public function getAwaitingReplies(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $cards = $this->getEmailService()->getCardsAwaitingReply($boardId);

        return Response::json(['success' => true, 'data' => $cards]);
    }

    /**
     * POST /board-pro/boards/{id}/convert-email
     */
    public function convertEmailToCard(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        $required = $this->validateRequired($request, ['list_id', 'uid', 'folder']);
        if ($required) return $required;

        if (!$this->verifyBoardAccess($boardId, $userEmail)) {
            return Response::error('Access denied', 403);
        }

        $card = $this->getEmailService()->convertEmailToCard([
            'uid' => (int) $request->input('uid'),
            'folder' => $request->input('folder'),
            'subject' => $request->input('subject'),
            'from' => $request->input('from'),
            'date' => $request->input('date'),
            'thread_id' => $request->input('thread_id'),
            'snippet' => $request->input('snippet'),
        ], $boardId, (int) $request->input('list_id'), $userEmail);

        if (!$card) {
            return Response::error('Failed to create card', 500);
        }

        return Response::json(['success' => true, 'data' => $card], 201);
    }

    // =========================================================================
    // Email Auto-Link Rules (Phase 1B)
    // =========================================================================

    /**
     * GET /board-pro/boards/{id}/email-rules
     */
    public function getEmailRules(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $rules = $this->getEmailService()->getRules($boardId);

        return Response::json(['success' => true, 'data' => $rules]);
    }

    /**
     * POST /board-pro/boards/{id}/email-rules
     */
    public function createEmailRule(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        $required = $this->validateRequired($request, ['rule_type', 'rule_value']);
        if ($required) return $required;

        if (!$this->verifyBoardAccess($boardId, $userEmail)) {
            return Response::error('Access denied', 403);
        }

        $typeCategories = $request->input('type_categories');
        if (is_array($typeCategories)) {
            $typeCategories = json_encode($typeCategories);
        }

        $rule = $this->getEmailService()->createRule([
            'board_id' => $boardId,
            'list_id' => $request->input('list_id'),
            'rule_type' => $request->input('rule_type'),
            'rule_value' => $request->input('rule_value'),
            'auto_create_card' => $request->input('auto_create_card', 1),
            'auto_assign_to' => $request->input('auto_assign_to'),
            'card_title_template' => $request->input('card_title_template', ''),
            'type_categories' => $typeCategories,
            'type_default' => $request->input('type_default', 'General'),
            'body_handling' => $request->input('body_handling', 'none'),
            'checklist_title' => $request->input('checklist_title', ''),
            'auto_link_email' => $request->input('auto_link_email', 1),
            'auto_attach_files' => $request->input('auto_attach_files', 1),
            'is_active' => $request->input('is_active', 1),
            'created_by' => $userEmail,
        ]);

        return Response::json(['success' => true, 'data' => $rule], 201);
    }

    /**
     * PUT /board-pro/email-rules/{id}
     */
    public function updateEmailRule(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int) $request->param('id');
        $input = $request->input();
        if (isset($input['type_categories']) && is_array($input['type_categories'])) {
            $input['type_categories'] = json_encode($input['type_categories']);
        }
        $rule = $this->getEmailService()->updateRule($id, $input);

        if (!$rule) {
            return Response::error('Rule not found', 404);
        }

        return Response::json(['success' => true, 'data' => $rule]);
    }

    /**
     * DELETE /board-pro/email-rules/{id}
     */
    public function deleteEmailRule(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int) $request->param('id');
        $this->getEmailService()->deleteRule($id);

        return Response::json(['success' => true]);
    }

    /**
     * POST /board-pro/evaluate-email-rules
     * Called by frontend when a new email arrives via WebSocket.
     * Evaluates the email against all active rules for the user.
     */
    public function evaluateEmailRules(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->userEmail;
        $input = $request->all();

        error_log("[EmailRules::DEBUG] evaluateEmailRules endpoint called by user=$userEmail, payload=" . json_encode($input));

        $emailData = [
            'uid' => $input['uid'] ?? null,
            'folder' => $input['folder'] ?? 'INBOX',
            'subject' => $input['subject'] ?? '',
            'from' => $input['from'] ?? '',
            'from_name' => $input['from_name'] ?? '',
            'date' => $input['date'] ?? null,
            'snippet' => $input['preview'] ?? $input['snippet'] ?? '',
        ];

        $imapService = $this->getImap($request);
        error_log("[EmailRules::DEBUG] IMAP service available: " . ($imapService ? 'yes' : 'no'));

        $results = $this->getEmailService()->evaluateEmailAgainstRules($emailData, $userEmail, $imapService);

        error_log("[EmailRules::DEBUG] evaluateEmailRules results: " . json_encode($results));

        return Response::json([
            'success' => true,
            'data' => $results,
        ]);
    }

    /**
     * POST /board-pro/evaluate-email-rules-catchup
     * Evaluates active rules against recent unprocessed emails in INBOX.
     * Called on app load / WebSocket reconnect to catch emails missed while offline.
     */
    public function evaluateEmailRulesCatchup(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->userEmail;
        $imapService = $this->getImap($request);
        if (!$imapService) {
            return Response::json(['success' => true, 'data' => []]);
        }

        $emailSvc = $this->getEmailService();
        if ($emailSvc->countActiveRulesForUser($userEmail) <= 0) {
            return Response::json(['success' => true, 'data' => []]);
        }

        $allResults = [];
        try {
            $folder = $request->input('folder') ?? 'INBOX';
            $messages = $imapService->getMessages($folder, 1, 20);
            foreach (($messages['messages'] ?? []) as $msg) {
                $emailData = [
                    'uid' => $msg['uid'] ?? null,
                    'folder' => $folder,
                    'subject' => $msg['subject'] ?? '',
                    'from' => $msg['from'] ?? '',
                    'from_name' => $msg['from_name'] ?? '',
                    'date' => $msg['date'] ?? null,
                    'snippet' => $msg['snippet'] ?? $msg['preview'] ?? '',
                ];
                $results = $emailSvc->evaluateEmailAgainstRules($emailData, $userEmail, $imapService);
                if (!empty($results)) {
                    $allResults = array_merge($allResults, $results);
                }
            }
        } catch (\Throwable $e) {
            error_log("[EmailRules::DEBUG] Catchup evaluation failed: " . $e->getMessage());
        }

        return Response::json([
            'success' => true,
            'data' => $allResults,
        ]);
    }

    /**
     * POST /board-pro/email-rules/{id}/run
     * Manually run a specific rule against existing emails in the inbox.
     */
    public function runEmailRule(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) return $imapError;

        @set_time_limit(120);

        try {
            $ruleId = (int) $request->param('id');
            $folder = $request->input('folder', 'INBOX');
            $perPage = 50;
            $maxPages = 10;

            $rule = $this->getEmailService()->getRule($ruleId);
            if (!$rule) {
                return Response::json(['success' => false, 'error' => 'Rule not found'], 404);
            }

            $emailService = $this->getEmailService();
            $matched = 0;
            $cardsCreated = 0;
            $totalProcessed = 0;
            $feedbackFound = [];

            for ($page = 1; $page <= $maxPages; $page++) {
                $messagesResult = $this->imap->getMessages($folder, $page, $perPage);
                $messages = $messagesResult['messages'] ?? [];
                $totalPages = $messagesResult['pages'] ?? 1;

                if (empty($messages)) {
                    break;
                }

                foreach ($messages as $msg) {
                    $totalProcessed++;

                    $fromEmail = $msg['from_email'] ?? '';
                    $fromName = $msg['from_name'] ?? '';
                    if (empty($fromEmail) && is_array($msg['from'] ?? null) && !empty($msg['from'])) {
                        $fromEmail = $msg['from'][0]['email'] ?? '';
                        $fromName = $msg['from'][0]['name'] ?? $fromName;
                    }

                    $subject = $msg['subject'] ?? '';

                    $emailData = [
                        'uid' => $msg['uid'] ?? null,
                        'folder' => $folder,
                        'subject' => $subject,
                        'from' => $fromEmail,
                        'from_name' => $fromName,
                        'date' => $msg['date'] ?? null,
                        'snippet' => $msg['snippet'] ?? '',
                    ];

                    $result = $emailService->runSingleRuleAgainstEmail(
                        $rule, $emailData, $this->userEmail, $this->imap
                    );

                    if ($result) {
                        $matched++;
                        $entry = [
                            'uid' => $msg['uid'] ?? null,
                            'subject' => mb_substr($subject, 0, 100),
                            'action' => $result['action'] ?? 'unknown',
                        ];
                        if (!empty($result['error'])) {
                            $entry['error'] = $result['error'];
                        }
                        if (!empty($result['card_id'])) {
                            $entry['card_id'] = $result['card_id'];
                        }
                        $feedbackFound[] = $entry;
                        if (($result['action'] ?? '') === 'card_created') {
                            $cardsCreated++;
                        }
                    }
                }

                if ($page >= $totalPages) {
                    break;
                }
            }

            return Response::json([
                'success' => true,
                'data' => [
                    'processed' => $totalProcessed,
                    'matched' => $matched,
                    'cards_created' => $cardsCreated,
                ],
                'matched_emails' => $feedbackFound,
            ]);
        } catch (\Exception $e) {
            error_log("[EmailRules::DEBUG] runEmailRule FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::json(['success' => false, 'error' => 'Rule execution failed: ' . $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Card Financials (Phase 1C)
    // =========================================================================

    /**
     * GET /board-pro/cards/{card_id}/financials
     */
    public function getCardFinancials(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int) $request->param('card_id');
        $financials = $this->getFinancialService()->getCardFinancials($cardId);

        return Response::json([
            'success' => true,
            'data' => $financials ?? [
                'card_id' => $cardId,
                'estimated_revenue' => null,
                'estimated_cost' => null,
                'currency' => 'HUF',
                'time_budget_hours' => null,
                'invoice_status' => 'none',
                'margin' => 0,
            ],
        ]);
    }

    /**
     * PUT /board-pro/cards/{card_id}/financials
     */
    public function updateCardFinancials(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $cardId = (int) $request->param('card_id');

        $financials = $this->getFinancialService()->upsertCardFinancials($cardId, $request->input(), $userEmail);

        return Response::json(['success' => true, 'data' => $financials]);
    }

    /**
     * GET /board-pro/boards/{id}/financial-summary
     */
    public function getBoardFinancialSummary(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $summary = $this->getFinancialService()->getBoardFinancialSummary($boardId);

        return Response::json(['success' => true, 'data' => $summary]);
    }

    /**
     * GET /board-pro/financials/global
     */
    public function getGlobalFinancials(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $financials = $this->getFinancialService()->getGlobalFinancials($userEmail);

        return Response::json(['success' => true, 'data' => $financials]);
    }

    // =========================================================================
    // Client Health (Phase 1E)
    // =========================================================================

    /**
     * GET /board-pro/boards/{id}/client-health
     */
    public function getBoardClientHealth(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        try {
            $clientService = new \Webmail\Services\ClientService($this->config);

            // Get the board to find linked client
            $board = $this->getBoardService()->getBoard($boardId, $userEmail);
            $clientId = $board['client_id'] ?? null;

            if (!$clientId) {
                return Response::json([
                    'success' => true,
                    'data' => ['has_client' => false, 'message' => 'No client linked to this board'],
                ]);
            }

            $client = $clientService->getClient($clientId, $userEmail);

            return Response::json([
                'success' => true,
                'data' => [
                    'has_client' => true,
                    'client_id' => $clientId,
                    'client_name' => $client['name'] ?? 'Unknown',
                    'health_score' => $client['health_score'] ?? null,
                    'last_contact' => $client['last_contact_at'] ?? null,
                    'risk_level' => $this->calculateRiskLevel($client),
                    'total_revenue' => $client['total_revenue'] ?? 0,
                    'open_invoices' => $client['open_invoices'] ?? 0,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log("BoardPro client health error: " . $e->getMessage());
            return Response::json([
                'success' => true,
                'data' => ['has_client' => false, 'error' => 'Client service unavailable'],
            ]);
        }
    }

    private function calculateRiskLevel(?array $client): string
    {
        if (!$client) return 'unknown';
        $score = $client['health_score'] ?? 50;
        if ($score >= 70) return 'healthy';
        if ($score >= 40) return 'at_risk';
        return 'critical';
    }

    // =========================================================================
    // Unified Card Timeline (Phase 2B)
    // =========================================================================

    /**
     * GET /board-pro/cards/{card_id}/timeline
     */
    public function getCardTimeline(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int) $request->param('card_id');
        $limit = (int) ($request->getQuery('limit', 50));
        $offset = (int) ($request->getQuery('offset', 0));

        $timeline = $this->getTimelineService()->getCardTimeline($cardId, $limit, $offset);
        $total = $this->getTimelineService()->getCardTimelineCount($cardId);

        return Response::json([
            'success' => true,
            'data' => $timeline,
            'total' => $total,
        ]);
    }

    // =========================================================================
    // Multi-Lens Views (Phase 2C)
    // =========================================================================

    /**
     * GET /board-pro/boards/{id}/revenue-view
     */
    public function getRevenueView(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $data = $this->getFinancialService()->getRevenueView($boardId);

        return Response::json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /board-pro/boards/{id}/time-view
     */
    public function getTimeView(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $data = $this->getTimelineService()->getBoardTimeView($boardId);

        return Response::json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /board-pro/boards/{id}/client-view
     */
    public function getClientView(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $data = $this->getTimelineService()->getBoardClientView($boardId);

        return Response::json(['success' => true, 'data' => $data]);
    }

    // =========================================================================
    // MoodBoard Hybrid (Phase 2D)
    // =========================================================================

    /**
     * POST /board-pro/cards/{card_id}/moodboard-link
     */
    public function linkMoodBoardFrame(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $cardId = (int) $request->param('card_id');

        $required = $this->validateRequired($request, ['mood_board_id']);
        if ($required) return $required;

        try {
            $db = \Webmail\Core\Database::getConnection($this->config);

            $db->exec("
                CREATE TABLE IF NOT EXISTS boardpro_moodboard_card_links (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    mood_board_id INT NOT NULL,
                    mood_board_item_id INT DEFAULT NULL,
                    linked_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_link (card_id, mood_board_item_id),
                    INDEX idx_card (card_id),
                    INDEX idx_mood (mood_board_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $stmt = $db->prepare("
                INSERT INTO boardpro_moodboard_card_links (card_id, mood_board_id, mood_board_item_id, linked_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE linked_by = VALUES(linked_by)
            ");
            $stmt->execute([
                $cardId,
                (int) $request->input('mood_board_id'),
                $request->input('mood_board_item_id'),
                $userEmail,
            ]);

            return Response::json(['success' => true], 201);
        } catch (\Throwable $e) {
            return Response::error('Failed to link: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /board-pro/cards/{card_id}/moodboard-link/{id}
     */
    public function unlinkMoodBoardFrame(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int) $request->param('id');

        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare("DELETE FROM boardpro_moodboard_card_links WHERE id = ?");
            $stmt->execute([$id]);

            return Response::json(['success' => true]);
        } catch (\Throwable $e) {
            return Response::error('Failed to unlink', 500);
        }
    }

    /**
     * GET /board-pro/cards/{card_id}/moodboard-links
     */
    public function getCardMoodBoardLinks(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $cardId = (int) $request->param('card_id');

        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare("
                SELECT mcl.*, mb.name AS mood_board_name
                FROM boardpro_moodboard_card_links mcl
                LEFT JOIN mood_boards mb ON mb.id = mcl.mood_board_id
                WHERE mcl.card_id = ?
                ORDER BY mcl.created_at DESC
            ");
            $stmt->execute([$cardId]);
            $links = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return Response::json(['success' => true, 'data' => $links]);
        } catch (\Throwable $e) {
            return Response::json(['success' => true, 'data' => []]);
        }
    }

    /**
     * POST /board-pro/boards/{id}/import-moodboard
     */
    public function importMoodBoardAsCards(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        $required = $this->validateRequired($request, ['mood_board_id', 'list_id']);
        if ($required) return $required;

        if (!$this->verifyBoardAccess($boardId, $userEmail)) {
            return Response::error('Access denied', 403);
        }

        try {
            $moodBoardId = (int) $request->input('mood_board_id');
            $listId = (int) $request->input('list_id');

            // Get mood board items (frames)
            $moodService = new \Webmail\Addons\Moodboards\Services\MoodBoardService($this->config);
            $moodBoard = $moodService->getBoard($moodBoardId, $userEmail);

            if (!$moodBoard) {
                return Response::error('Mood board not found', 404);
            }

            $boardService = $this->getBoardService();
            $createdCards = [];

            foreach ($moodBoard['items'] ?? [] as $item) {
                $title = $item['content'] ?? $item['name'] ?? 'Imported frame';
                if (strlen($title) > 200) $title = substr($title, 0, 197) . '...';

                $card = $boardService->createCard([
                    'list_id' => $listId,
                    'title' => $title,
                    'description' => "Imported from Mood Board: {$moodBoard['name']}",
                ], $userEmail);

                if ($card) {
                    // Link the card to the mood board item
                    $db = \Webmail\Core\Database::getConnection($this->config);
                    $stmt = $db->prepare("
                        INSERT IGNORE INTO boardpro_moodboard_card_links (card_id, mood_board_id, mood_board_item_id, linked_by)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$card['id'], $moodBoardId, $item['id'] ?? null, $userEmail]);

                    $createdCards[] = $card;
                }
            }

            return Response::json([
                'success' => true,
                'data' => ['cards_created' => count($createdCards), 'cards' => $createdCards],
            ]);
        } catch (\Throwable $e) {
            return Response::error('Import failed: ' . $e->getMessage(), 500);
        }
    }

    // NOTE: The Phase 2E "Advanced Permissions" endpoints (card visibility,
    // stage locks) were removed in the addons audit: no UI ever wrote to the
    // tables and nothing enforced them. Migration 187 drops the tables.

    // =========================================================================
    // AI Intelligence (Phase 3A)
    // =========================================================================

    /**
     * POST /board-pro/boards/{id}/ai/summarize
     */
    public function aiSummarize(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        $result = $this->getAIService()->summarizeBoard($boardId, $userEmail);

        return Response::json($result);
    }

    /**
     * POST /board-pro/boards/{id}/ai/risk-report
     */
    public function aiRiskReport(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        $result = $this->getAIService()->detectRisks($boardId, $userEmail);

        return Response::json($result);
    }

    /**
     * POST /board-pro/boards/{id}/ai/estimate
     */
    public function aiEstimate(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        $result = $this->getAIService()->estimateDelivery($boardId, $userEmail);

        return Response::json($result);
    }

    /**
     * POST /board-pro/cards/{card_id}/ai/draft-update
     */
    public function aiDraftUpdate(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $cardId = (int) $request->param('card_id');

        $result = $this->getAIService()->draftClientUpdate($cardId, $userEmail);

        return Response::json($result);
    }

    // =========================================================================
    // Executive Mode (Phase 3B)
    // =========================================================================

    /**
     * GET /board-pro/boards/{id}/executive-report
     */
    public function getExecutiveReport(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        $format = $request->getQuery('format', 'json');

        if ($format === 'html') {
            $html = $this->getExportService()->generateReportHtml($boardId, $userEmail);
            return new Response(200, ['Content-Type' => 'text/html'], $html);
        }

        $report = $this->getExportService()->generateExecutiveReport($boardId, $userEmail);

        return Response::json($report);
    }

    /**
     * GET /board-pro/boards/{id}/revenue-projection
     */
    public function getRevenueProjection(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $projection = $this->getExportService()->getRevenueProjection($boardId);

        return Response::json(['success' => true, 'data' => $projection]);
    }

    /**
     * GET /board-pro/boards/{id}/workload-analytics
     */
    public function getWorkloadAnalytics(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $workload = $this->getExportService()->getWorkloadAnalytics($boardId);

        return Response::json(['success' => true, 'data' => $workload]);
    }

    // =========================================================================
    // Scope Radar
    // =========================================================================

    public function getScopeRadar(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $userEmail = $this->getUser($request);
        $data = $this->getScopeRadarService()->getBoardScopeRadar($boardId, $userEmail);

        return Response::json(['success' => true, 'data' => $data]);
    }
}

