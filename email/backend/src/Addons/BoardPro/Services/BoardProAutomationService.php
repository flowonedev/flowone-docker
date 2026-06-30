<?php

namespace Webmail\Addons\BoardPro\Services;

use PDO;

/**
 * BoardProAutomationService
 *
 * Board-level automation engine: triggers based on card events,
 * actions that integrate with CRM, calendar, chat, email.
 * Includes circuit breaker to prevent automation loops.
 */
class BoardProAutomationService
{
    private PDO $db;
    private array $config;

    /** Max chained automations per event to prevent loops */
    private const MAX_CHAIN_DEPTH = 3;

    /** Current chain depth for recursion prevention */
    private static int $chainDepth = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTables());
    }

    // =========================================================================
    // Table Bootstrap
    // =========================================================================

    private function ensureTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS boardpro_automation_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                board_id INT NOT NULL,
                user_email VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                trigger_type ENUM(
                    'card_moved_to_list','card_completed','card_overdue',
                    'card_idle_days','list_all_completed',
                    'email_received_on_card','checklist_completed',
                    'label_added','card_created'
                ) NOT NULL,
                trigger_config JSON NOT NULL,
                action_type ENUM(
                    'move_card','assign_member','add_label',
                    'create_invoice_draft','send_notification',
                    'send_email','update_deal_stage',
                    'start_crm_sequence','create_calendar_event',
                    'post_chat_message'
                ) NOT NULL,
                action_config JSON NOT NULL,
                last_run_at DATETIME DEFAULT NULL,
                run_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_board (board_id),
                INDEX idx_user (user_email),
                INDEX idx_active (is_active, trigger_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS boardpro_automation_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_id INT UNSIGNED NOT NULL,
                user_email VARCHAR(255) NOT NULL,
                target_type VARCHAR(50) NOT NULL,
                target_id INT UNSIGNED NOT NULL,
                action_taken VARCHAR(100) NOT NULL,
                result_detail TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_rule (rule_id),
                INDEX idx_target (target_type, target_id),
                INDEX idx_user_date (user_email, created_at),
                FOREIGN KEY (rule_id) REFERENCES boardpro_automation_rules(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // =========================================================================
    // Rule CRUD
    // =========================================================================

    public function getRules(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM boardpro_automation_rules
            WHERE board_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$boardId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as &$rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?? [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?? [];
        }

        return $rules;
    }

    public function getRule(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM boardpro_automation_rules WHERE id = ?");
        $stmt->execute([$id]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?? [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?? [];
        }

        return $rule ?: null;
    }

    public function createRule(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO boardpro_automation_rules
                (board_id, user_email, name, is_active, trigger_type, trigger_config, action_type, action_config)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['board_id'],
            $data['user_email'],
            $data['name'],
            $data['is_active'] ?? 1,
            $data['trigger_type'],
            json_encode($data['trigger_config'] ?? []),
            $data['action_type'],
            json_encode($data['action_config'] ?? []),
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->getRule($id);
    }

    public function updateRule(int $id, array $data): ?array
    {
        $fields = [];
        $values = [];

        $allowedFields = ['name', 'is_active', 'trigger_type', 'action_type'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        // JSON fields
        if (array_key_exists('trigger_config', $data)) {
            $fields[] = "trigger_config = ?";
            $values[] = json_encode($data['trigger_config']);
        }
        if (array_key_exists('action_config', $data)) {
            $fields[] = "action_config = ?";
            $values[] = json_encode($data['action_config']);
        }

        if (empty($fields)) {
            return $this->getRule($id);
        }

        $values[] = $id;
        $sql = "UPDATE boardpro_automation_rules SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return $this->getRule($id);
    }

    public function deleteRule(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM boardpro_automation_rules WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function getRuleLog(int $ruleId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM boardpro_automation_log
            WHERE rule_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$ruleId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get execution log for all rules on a board (board-level view)
     */
    public function getBoardLog(int $boardId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT l.*, r.name AS rule_name, r.trigger_type, r.action_type
            FROM boardpro_automation_log l
            JOIN boardpro_automation_rules r ON l.rule_id = r.id
            WHERE r.board_id = ?
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $boardId, \PDO::PARAM_INT);
        $stmt->bindValue(2, (int) $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, (int) $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Trigger Evaluation
    // =========================================================================

    /**
     * Fire an event trigger and execute matching rules
     * Called by hooks in BoardProController when card events happen
     */
    public function fireTrigger(string $triggerType, int $boardId, array $context = []): array
    {
        // Circuit breaker: prevent automation loops
        if (self::$chainDepth >= self::MAX_CHAIN_DEPTH) {
            error_log("BoardProAutomation: chain depth limit reached ({$triggerType} on board {$boardId})");
            return [];
        }

        self::$chainDepth++;
        $results = [];

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM boardpro_automation_rules
                WHERE board_id = ? AND trigger_type = ? AND is_active = 1
            ");
            $stmt->execute([$boardId, $triggerType]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rules as $rule) {
                $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?? [];
                $rule['action_config'] = json_decode($rule['action_config'], true) ?? [];

                if ($this->matchesTrigger($rule, $context)) {
                    $result = $this->executeAction($rule, $context);
                    $results[] = $result;

                    // Log the execution
                    $this->logExecution(
                        (int) $rule['id'],
                        $rule['user_email'],
                        $context['target_type'] ?? 'card',
                        (int) ($context['target_id'] ?? $context['card_id'] ?? 0),
                        $rule['action_type'],
                        json_encode($result)
                    );

                    // Update rule stats
                    $this->db->prepare("
                        UPDATE boardpro_automation_rules
                        SET last_run_at = NOW(), run_count = run_count + 1
                        WHERE id = ?
                    ")->execute([$rule['id']]);
                }
            }
        } finally {
            self::$chainDepth--;
        }

        // Fire Automation Hub workflow event (non-blocking)
        try {
            $hubTriggerType = 'trigger.board.' . $triggerType;
            $hubEngine = new \Webmail\Addons\AutomationHub\Services\WorkflowEngineService($this->config);
            $hubEngine->onEvent($hubTriggerType, array_merge($context, ['board_id' => $boardId]));
        } catch (\Throwable $e) {
            // Automation Hub errors must never break Board Pro automations
        }

        return $results;
    }

    /**
     * Evaluate time-based triggers (called by cron)
     */
    public function evaluateTimeTriggers(): array
    {
        $results = [];

        // Get all boards with active time-based rules
        $stmt = $this->db->prepare("
            SELECT DISTINCT board_id
            FROM boardpro_automation_rules
            WHERE is_active = 1 AND trigger_type IN ('card_overdue', 'card_idle_days')
        ");
        $stmt->execute();
        $boardIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($boardIds as $boardId) {
            // Card overdue
            $results = array_merge($results, $this->evaluateOverdueCards((int) $boardId));

            // Card idle
            $results = array_merge($results, $this->evaluateIdleCards((int) $boardId));
        }

        return $results;
    }

    private function evaluateOverdueCards(int $boardId): array
    {
        $results = [];

        $stmt = $this->db->prepare("
            SELECT r.*
            FROM boardpro_automation_rules r
            WHERE r.board_id = ? AND r.trigger_type = 'card_overdue' AND r.is_active = 1
        ");
        $stmt->execute([$boardId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rules)) return $results;

        // Find overdue cards
        $stmtCards = $this->db->prepare("
            SELECT bc.id AS card_id, bc.title, bc.due_date, bc.assigned_to, bl.id AS list_id
            FROM webmail_board_cards bc
            JOIN webmail_board_lists bl ON bl.id = bc.list_id
            WHERE bl.board_id = ? AND bc.due_date < NOW() AND bc.completed = 0
        ");
        $stmtCards->execute([$boardId]);
        $overdueCards = $stmtCards->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?? [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?? [];

            foreach ($overdueCards as $card) {
                // Check if already triggered for this card recently (prevent re-firing)
                $stmtLog = $this->db->prepare("
                    SELECT id FROM boardpro_automation_log
                    WHERE rule_id = ? AND target_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $stmtLog->execute([$rule['id'], $card['card_id']]);
                if ($stmtLog->fetch()) continue;

                $context = [
                    'card_id' => $card['card_id'],
                    'target_type' => 'card',
                    'target_id' => $card['card_id'],
                    'card_title' => $card['title'],
                    'assigned_to' => $card['assigned_to'],
                    'list_id' => $card['list_id'],
                ];

                $result = $this->executeAction($rule, $context);
                $results[] = $result;

                $this->logExecution(
                    (int) $rule['id'],
                    $rule['user_email'],
                    'card',
                    (int) $card['card_id'],
                    $rule['action_type'],
                    json_encode($result)
                );

                $this->db->prepare("
                    UPDATE boardpro_automation_rules SET last_run_at = NOW(), run_count = run_count + 1 WHERE id = ?
                ")->execute([$rule['id']]);
            }
        }

        return $results;
    }

    private function evaluateIdleCards(int $boardId): array
    {
        $results = [];

        $stmt = $this->db->prepare("
            SELECT r.*
            FROM boardpro_automation_rules r
            WHERE r.board_id = ? AND r.trigger_type = 'card_idle_days' AND r.is_active = 1
        ");
        $stmt->execute([$boardId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?? [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?? [];

            $idleDays = (int) ($rule['trigger_config']['days'] ?? 7);

            // Find cards that haven't had activity in N days
            $stmtCards = $this->db->prepare("
                SELECT bc.id AS card_id, bc.title, bc.assigned_to, bc.updated_at, bl.id AS list_id
                FROM webmail_board_cards bc
                JOIN webmail_board_lists bl ON bl.id = bc.list_id
                WHERE bl.board_id = ?
                  AND bc.completed = 0
                  AND bc.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmtCards->execute([$boardId, $idleDays]);
            $idleCards = $stmtCards->fetchAll(PDO::FETCH_ASSOC);

            foreach ($idleCards as $card) {
                // Prevent re-firing within 24 hours
                $stmtLog = $this->db->prepare("
                    SELECT id FROM boardpro_automation_log
                    WHERE rule_id = ? AND target_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $stmtLog->execute([$rule['id'], $card['card_id']]);
                if ($stmtLog->fetch()) continue;

                $context = [
                    'card_id' => $card['card_id'],
                    'target_type' => 'card',
                    'target_id' => $card['card_id'],
                    'card_title' => $card['title'],
                    'assigned_to' => $card['assigned_to'],
                    'list_id' => $card['list_id'],
                ];

                $result = $this->executeAction($rule, $context);
                $results[] = $result;

                $this->logExecution(
                    (int) $rule['id'],
                    $rule['user_email'],
                    'card',
                    (int) $card['card_id'],
                    $rule['action_type'],
                    json_encode($result)
                );

                $this->db->prepare("
                    UPDATE boardpro_automation_rules SET last_run_at = NOW(), run_count = run_count + 1 WHERE id = ?
                ")->execute([$rule['id']]);
            }
        }

        return $results;
    }

    // =========================================================================
    // Trigger Matching
    // =========================================================================

    private function matchesTrigger(array $rule, array $context): bool
    {
        $config = $rule['trigger_config'];

        switch ($rule['trigger_type']) {
            case 'card_moved_to_list':
                $targetListId = $config['list_id'] ?? null;
                return $targetListId && (int) $targetListId === (int) ($context['to_list_id'] ?? 0);

            case 'card_completed':
                return !empty($context['completed']);

            case 'card_created':
                $targetListId = $config['list_id'] ?? null;
                return !$targetListId || (int) $targetListId === (int) ($context['list_id'] ?? 0);

            case 'label_added':
                $targetLabelId = $config['label_id'] ?? null;
                return $targetLabelId && (int) $targetLabelId === (int) ($context['label_id'] ?? 0);

            case 'checklist_completed':
                return !empty($context['checklist_completed']);

            case 'email_received_on_card':
                return !empty($context['email_received']);

            case 'list_all_completed':
                return !empty($context['list_all_completed']);

            default:
                return true;
        }
    }

    // =========================================================================
    // Action Execution
    // =========================================================================

    private function executeAction(array $rule, array $context): array
    {
        $config = $rule['action_config'];
        $result = ['success' => false, 'action' => $rule['action_type']];

        try {
            switch ($rule['action_type']) {
                case 'move_card':
                    $result = $this->actionMoveCard($config, $context, $rule);
                    break;

                case 'assign_member':
                    $result = $this->actionAssignMember($config, $context, $rule);
                    break;

                case 'add_label':
                    $result = $this->actionAddLabel($config, $context, $rule);
                    break;

                case 'create_invoice_draft':
                    $result = $this->actionCreateInvoiceDraft($config, $context, $rule);
                    break;

                case 'send_notification':
                    $result = $this->actionSendNotification($config, $context, $rule);
                    break;

                case 'send_email':
                    $result = $this->actionSendEmail($config, $context, $rule);
                    break;

                case 'update_deal_stage':
                    $result = $this->actionUpdateDealStage($config, $context, $rule);
                    break;

                case 'create_calendar_event':
                    $result = $this->actionCreateCalendarEvent($config, $context, $rule);
                    break;

                case 'post_chat_message':
                    $result = $this->actionPostChatMessage($config, $context, $rule);
                    break;

                default:
                    $result = ['success' => false, 'error' => 'Unknown action type'];
            }
        } catch (\Throwable $e) {
            error_log("BoardProAutomation action error: " . $e->getMessage());
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        return $result;
    }

    private function actionMoveCard(array $config, array $context, array $rule): array
    {
        $targetListId = $config['list_id'] ?? null;
        $cardId = $context['card_id'] ?? null;

        if (!$targetListId || !$cardId) {
            return ['success' => false, 'error' => 'Missing list_id or card_id'];
        }

        $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($this->config);
        $boardService->moveCard((int) $cardId, (int) $targetListId, 0);

        return ['success' => true, 'action' => 'move_card', 'card_id' => $cardId, 'to_list' => $targetListId];
    }

    private function actionAssignMember(array $config, array $context, array $rule): array
    {
        $assignTo = $config['email'] ?? null;
        $cardId = $context['card_id'] ?? null;

        if (!$assignTo || !$cardId) {
            return ['success' => false, 'error' => 'Missing email or card_id'];
        }

        $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($this->config);
        $boardService->updateCard($rule['user_email'], (int) $cardId, ['assigned_to' => $assignTo]);

        return ['success' => true, 'action' => 'assign_member', 'card_id' => $cardId, 'assigned_to' => $assignTo];
    }

    private function actionAddLabel(array $config, array $context, array $rule): array
    {
        $labelId = $config['label_id'] ?? null;
        $cardId = $context['card_id'] ?? null;

        if (!$labelId || !$cardId) {
            return ['success' => false, 'error' => 'Missing label_id or card_id'];
        }

        $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($this->config);
        $boardService->addCardLabel((int) $cardId, (int) $labelId);

        return ['success' => true, 'action' => 'add_label', 'card_id' => $cardId, 'label_id' => $labelId];
    }

    private function actionCreateInvoiceDraft(array $config, array $context, array $rule): array
    {
        try {
            $invoiceService = new \Webmail\Addons\CrmPro\Services\CrmInvoiceService($this->config);

            // Get card financials for amount
            $financialService = new BoardProFinancialService($this->config);
            $financials = $financialService->getCardFinancials((int) ($context['card_id'] ?? 0));

            $invoiceData = [
                'user_email' => $rule['user_email'],
                'client_id' => $config['client_id'] ?? null,
                'status' => 'draft',
                'currency' => $financials['currency'] ?? $config['currency'] ?? 'HUF',
                'notes' => 'Auto-generated from Board Pro automation: ' . ($context['card_title'] ?? ''),
                'board_card_id' => $context['card_id'] ?? null,
            ];

            $invoice = $invoiceService->create($invoiceData, $rule['user_email']);

            if ($invoice && $financials) {
                // Link invoice to card financials
                $financialService->linkInvoice(
                    (int) ($context['card_id']),
                    (int) $invoice['id'],
                    $rule['user_email']
                );
            }

            return ['success' => true, 'action' => 'create_invoice_draft', 'invoice_id' => $invoice['id'] ?? null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function actionSendNotification(array $config, array $context, array $rule): array
    {
        // Use Redis pub/sub to send real-time notification
        try {
            $redis = new \Redis();
            $redis->connect(
                $this->config['redis']['host'] ?? '127.0.0.1',
                $this->config['redis']['port'] ?? 6379
            );

            $message = $config['message'] ?? 'Board automation triggered';
            $message = $this->interpolateMessage($message, $context);

            $recipients = $config['recipients'] ?? [$rule['user_email']];
            foreach ($recipients as $email) {
                $prefix = $this->config['redis']['prefix'] ?? 'webmail:';
                $redis->publish($prefix . 'notifications:' . $email, json_encode([
                    'type' => 'board_pro_automation',
                    'title' => 'Board Automation',
                    'message' => $message,
                    'card_id' => $context['card_id'] ?? null,
                    'board_id' => $context['board_id'] ?? null,
                ]));
            }

            return ['success' => true, 'action' => 'send_notification', 'recipients' => count($recipients)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function actionSendEmail(array $config, array $context, array $rule): array
    {
        // Placeholder: email sending through SMTP service
        return ['success' => false, 'error' => 'Email action not yet implemented'];
    }

    private function actionUpdateDealStage(array $config, array $context, array $rule): array
    {
        try {
            $dealId = $config['deal_id'] ?? null;
            $stage = $config['stage'] ?? null;

            if (!$dealId || !$stage) {
                return ['success' => false, 'error' => 'Missing deal_id or stage'];
            }

            $dealService = new \Webmail\Addons\CrmPro\Services\CrmDealService($this->config);
            $dealService->update((int) $dealId, ['stage' => $stage], $rule['user_email']);

            return ['success' => true, 'action' => 'update_deal_stage', 'deal_id' => $dealId, 'stage' => $stage];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function actionCreateCalendarEvent(array $config, array $context, array $rule): array
    {
        // Placeholder: calendar event creation
        return ['success' => false, 'error' => 'Calendar action not yet implemented'];
    }

    private function actionPostChatMessage(array $config, array $context, array $rule): array
    {
        try {
            $channelId = $config['channel_id'] ?? null;
            $message = $config['message'] ?? 'Automation: Card updated';
            $message = $this->interpolateMessage($message, $context);

            if (!$channelId) {
                return ['success' => false, 'error' => 'Missing channel_id'];
            }

            $chatService = new \Webmail\Addons\Chat\Services\ChatService($this->config);
            $chatService->sendMessage([
                'channel_id' => $channelId,
                'sender_email' => $rule['user_email'],
                'content' => $message,
                'type' => 'text',
            ]);

            return ['success' => true, 'action' => 'post_chat_message', 'channel_id' => $channelId];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function logExecution(int $ruleId, string $userEmail, string $targetType, int $targetId, string $actionTaken, string $resultDetail): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO boardpro_automation_log
                (rule_id, user_email, target_type, target_id, action_taken, result_detail)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$ruleId, $userEmail, $targetType, $targetId, $actionTaken, $resultDetail]);
    }

    /**
     * Replace {{variable}} placeholders in messages
     */
    private function interpolateMessage(string $message, array $context): string
    {
        $replacements = [
            '{{card_title}}' => $context['card_title'] ?? '',
            '{{card_id}}' => $context['card_id'] ?? '',
            '{{assigned_to}}' => $context['assigned_to'] ?? '',
            '{{board_id}}' => $context['board_id'] ?? '',
            '{{list_name}}' => $context['list_name'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }
}

