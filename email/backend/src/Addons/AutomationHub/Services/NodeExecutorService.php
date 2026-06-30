<?php

namespace Webmail\Addons\AutomationHub\Services;

class NodeExecutorService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Execute a single node and return its output data.
     */
    public function execute(string $nodeType, array $config, array $inputData, bool $isTest = false): array
    {
        // Resolve template variables in config
        $config = $this->resolveVariables($config, $inputData);

        return match (true) {
            // Triggers pass through their data
            str_starts_with($nodeType, 'trigger.') => $this->executeTrigger($nodeType, $config, $inputData, $isTest),

            // Logic nodes
            $nodeType === 'logic.condition' => $this->executeCondition($config, $inputData),
            $nodeType === 'logic.delay' => array_merge($inputData, ['delayed' => true]),
            $nodeType === 'logic.filter' => $this->executeFilter($config, $inputData),
            $nodeType === 'logic.merge' => $this->executeMerge($config, $inputData),

            // Actions
            $nodeType === 'action.email.send' => $this->executeSendEmail($config, $inputData, $isTest),
            $nodeType === 'action.chat.send' => $this->executeSendChat($config, $inputData, $isTest),
            $nodeType === 'action.notification.send' => $this->executeSendNotification($config, $inputData, $isTest),
            $nodeType === 'action.telegram.send' => $this->executeTelegramSend($config, $inputData, $isTest),
            $nodeType === 'action.http.request' => $this->executeHttpRequest($config, $inputData, $isTest),
            $nodeType === 'action.crm.move_deal' => $this->executeMoveDeal($config, $inputData, $isTest),
            $nodeType === 'action.board.move_card' => $this->executeMoveCard($config, $inputData, $isTest),
            $nodeType === 'action.task.create' => $this->executeCreateTask($config, $inputData, $isTest),
            $nodeType === 'action.export.csv' => $this->executeExportCsv($config, $inputData, $isTest),

            // Client actions
            $nodeType === 'action.client.get_data' => $this->executeClientGetData($config, $inputData, $isTest),
            $nodeType === 'action.client.get_financials' => $this->executeClientGetFinancials($config, $inputData, $isTest),
            $nodeType === 'action.client.get_health' => $this->executeClientGetHealth($config, $inputData, $isTest),

            // Invoice actions
            $nodeType === 'action.invoice.create' => $this->executeInvoiceCreate($config, $inputData, $isTest),
            $nodeType === 'action.invoice.send' => $this->executeInvoiceSend($config, $inputData, $isTest),
            $nodeType === 'action.invoice.record_payment' => $this->executeInvoiceRecordPayment($config, $inputData, $isTest),

            // Statistics actions
            $nodeType === 'action.stats.email' => $this->executeStatsEmail($config, $inputData, $isTest),
            $nodeType === 'action.stats.response_time' => $this->executeStatsResponseTime($config, $inputData, $isTest),
            $nodeType === 'action.stats.revenue_report' => $this->executeStatsRevenueReport($config, $inputData, $isTest),
            $nodeType === 'action.stats.client_ranking' => $this->executeStatsClientRanking($config, $inputData, $isTest),
            $nodeType === 'action.stats.aging_report' => $this->executeStatsAgingReport($config, $inputData, $isTest),

            // AI actions
            $nodeType === 'action.ai.prompt' => $this->executeAIPrompt($config, $inputData),
            $nodeType === 'action.ai.summarize' => $this->executeAISummarize($config, $inputData),
            $nodeType === 'action.ai.rewrite' => $this->executeAIRewrite($config, $inputData),

            // Calendar actions
            $nodeType === 'action.calendar.create_event' => $this->executeCalendarCreateEvent($config, $inputData),
            $nodeType === 'action.calendar.get_events' => $this->executeCalendarGetEvents($config, $inputData),
            $nodeType === 'action.calendar.update_event' => $this->executeCalendarUpdateEvent($config, $inputData),
            $nodeType === 'action.calendar.delete_event' => $this->executeCalendarDeleteEvent($config, $inputData),
            $nodeType === 'action.calendar.get_upcoming' => $this->executeCalendarGetUpcoming($config, $inputData),

            // Drive actions
            $nodeType === 'action.drive.list_files' => $this->executeDriveListFiles($config, $inputData),
            $nodeType === 'action.drive.get_file_info' => $this->executeDriveGetFileInfo($config, $inputData),
            $nodeType === 'action.drive.create_folder' => $this->executeDriveCreateFolder($config, $inputData),

            // Weather
            $nodeType === 'action.weather.get_current' => $this->executeWeatherGetCurrent($config, $inputData),

            // Google
            $nodeType === 'action.google.get_contacts' => $this->executeGoogleGetContacts($config, $inputData),
            $nodeType === 'action.google.get_contact' => $this->executeGoogleGetContact($config, $inputData),
            $nodeType === 'action.google.sync_calendar' => $this->executeGoogleSyncCalendar($config, $inputData),

            // Trello
            $nodeType === 'action.trello.sync_boards' => $this->executeTrelloSyncBoards($config, $inputData),
            $nodeType === 'action.trello.get_boards' => $this->executeTrelloGetBoards($config, $inputData),

            // Email Campaigns
            $nodeType === 'action.campaign.get_stats' => $this->executeCampaignGetStats($config, $inputData),
            $nodeType === 'action.campaign.send' => $this->executeCampaignSend($config, $inputData, $isTest),

            // SQL Query
            $nodeType === 'action.sql.query' => $this->executeSqlQuery($config, $inputData),

            // Lists
            $nodeType === 'action.list.get_mailing_list' => $this->executeListGetMailingList($config, $inputData),
            $nodeType === 'action.list.get_team' => $this->executeListGetTeam($config, $inputData),
            $nodeType === 'action.list.add_contact' => $this->executeListAddContact($config, $inputData, $isTest),
            $nodeType === 'action.list.remove_contact' => $this->executeListRemoveContact($config, $inputData, $isTest),

            // Sequences
            $nodeType === 'action.sequence.start' => $this->executeSequenceStart($config, $inputData, $isTest),
            $nodeType === 'action.sequence.stop' => $this->executeSequenceStop($config, $inputData, $isTest),
            $nodeType === 'action.sequence.get_status' => $this->executeSequenceGetStatus($config, $inputData),

            // Moodboards
            $nodeType === 'action.moodboard.get_info' => $this->executeMoodboardGetInfo($config, $inputData),
            $nodeType === 'action.moodboard.list' => $this->executeMoodboardList($config, $inputData),
            $nodeType === 'action.moodboard.share' => $this->executeMoodboardShare($config, $inputData, $isTest),

            // Invoice / Billingo
            $nodeType === 'action.invoice.push_billingo' => $this->executeInvoicePushBillingo($config, $inputData, $isTest),
            $nodeType === 'action.invoice.download_pdf' => $this->executeInvoiceDownloadPdf($config, $inputData, $isTest),
            $nodeType === 'action.invoice.send_to_client' => $this->executeInvoiceSendToClient($config, $inputData, $isTest),
            $nodeType === 'action.invoice.get_status' => $this->executeInvoiceGetStatus($config, $inputData),

            // Printer (desktop relay)
            $nodeType === 'action.printer.list' => $this->executePrinterList($config, $inputData, $isTest),
            $nodeType === 'action.printer.print' => $this->executePrinterPrint($config, $inputData, $isTest),

            // Mailchimp
            $nodeType === 'action.mailchimp.get_lists' => $this->executeMailchimpGetLists($config, $inputData),
            $nodeType === 'action.mailchimp.get_members' => $this->executeMailchimpGetMembers($config, $inputData),
            $nodeType === 'action.mailchimp.add_member' => $this->executeMailchimpAddMember($config, $inputData, $isTest),
            $nodeType === 'action.mailchimp.remove_member' => $this->executeMailchimpRemoveMember($config, $inputData, $isTest),
            $nodeType === 'action.mailchimp.get_campaigns' => $this->executeMailchimpGetCampaigns($config, $inputData),
            $nodeType === 'action.mailchimp.send_campaign' => $this->executeMailchimpSendCampaign($config, $inputData, $isTest),

            default => throw new \RuntimeException("Unknown node type: {$nodeType}"),
        };
    }

    // ── Triggers ────────────────────────────────────────────────────────

    private function executeTrigger(string $nodeType, array $config, array $inputData, bool $isTest = false): array
    {
        // Server health trigger: always fetch real data (even for test runs)
        if ($nodeType === 'trigger.server.health' && !isset($inputData['value'])) {
            $inputData = array_merge($inputData, $this->fetchLiveServerHealth($config));
        }

        $defaults = $this->getTriggerDefaults($nodeType, $config);
        return array_merge($defaults, ['trigger_type' => $nodeType], $inputData);
    }

    private function fetchLiveServerHealth(array $config): array
    {
        try {
            $monitor = new ServerMonitorBridge($this->config);
            $metric = $config['metric'] ?? 'cpu_load';
            $condition = $config['condition'] ?? 'above';
            $threshold = (float)($config['threshold'] ?? 90);
            $service = $config['service'] ?? null;
            $check = $monitor->checkMetric($metric, $condition, $threshold, $service);
            return [
                'metric' => $check['metric'] ?? $metric,
                'value' => $check['value'] ?? 0,
                'threshold' => $check['threshold'] ?? $threshold,
                'service' => $check['service'] ?? ($service ?? 'all'),
                'status' => $check['value'] ?? 'unknown',
                'hostname' => gethostname() ?: 'vps',
            ];
        } catch (\Throwable $e) {
            error_log("AutomationHub: Failed to fetch live server health: " . $e->getMessage());
            return [];
        }
    }

    private function getTriggerDefaults(string $nodeType, array $config): array
    {
        return match ($nodeType) {
            'trigger.server.health' => [
                'metric' => $config['metric'] ?? 'cpu_load',
                'value' => 0,
                'threshold' => $config['threshold'] ?? '90',
                'service' => $config['service'] ?? 'all',
                'status' => 'unknown',
                'hostname' => gethostname() ?: 'vps',
            ],
            'trigger.schedule.cron' => [
                'scheduled_at' => date('Y-m-d H:i:s'),
                'schedule_type' => $config['schedule_type'] ?? 'interval',
            ],
            'trigger.crm.deal_won', 'trigger.crm.deal_lost', 'trigger.crm.deal_stage_changed' => [
                'deal_id' => 0,
                'deal_title' => 'Sample Deal',
                'expected_value' => '1000',
                'from_stage' => 'Proposal',
                'to_stage' => 'Won',
                'lost_reason' => '',
                'pipeline_name' => 'Default Pipeline',
            ],
            'trigger.crm.invoice_overdue' => [
                'invoice_id' => 0,
                'invoice_number' => 'INV-0000',
                'client_name' => 'Sample Client',
                'amount' => '500',
                'due_date' => date('Y-m-d', strtotime('-7 days')),
                'days_overdue' => 7,
            ],
            'trigger.board.shared' => [
                'board_id' => 0,
                'board_name' => 'Sample Board',
                'member_email' => 'colleague@example.com',
                'invited_by' => 'owner@example.com',
                'role' => 'editor',
            ],
            'trigger.board.card_created' => [
                'card_id' => 0,
                'card_title' => 'Sample Card',
                'board_name' => 'Sample Board',
                'list_name' => 'To Do',
                'created_by' => '',
                'assigned_to' => '',
            ],
            'trigger.board.card_completed', 'trigger.board.card_moved', 'trigger.board.card_overdue' => [
                'card_id' => 0,
                'card_title' => 'Sample Card',
                'board_name' => 'Sample Board',
                'list_name' => 'To Do',
                'to_list' => 'Done',
                'due_date' => date('Y-m-d'),
                'assigned_to' => '',
            ],
            'trigger.webhook.incoming' => [
                'webhook_body' => '{}',
                'webhook_method' => 'POST',
                'webhook_headers' => [],
            ],
            'trigger.telegram.message' => [
                'telegram_text' => '/test',
                'telegram_chat_id' => '',
                'telegram_user' => 'TestUser',
            ],
            'trigger.client.health_low' => [
                'client_id' => 0,
                'client_name' => 'Sample Client',
                'health_score' => $config['threshold'] ?? 30,
                'days_since_activity' => 45,
            ],
            'trigger.client.inactive' => [
                'client_id' => 0,
                'client_name' => 'Sample Client',
                'days_inactive' => $config['days'] ?? 30,
            ],
            'trigger.invoice.paid' => [
                'invoice_id' => 0,
                'invoice_number' => 'INV-0000',
                'client_name' => 'Sample Client',
                'paid_amount' => '500',
                'currency' => 'EUR',
            ],
            'trigger.invoice.created' => [
                'invoice_id' => 0,
                'invoice_number' => 'INV-0000',
                'client_name' => 'Sample Client',
                'total' => '500',
                'currency' => 'EUR',
            ],
            'trigger.financial.threshold' => [
                'metric' => $config['metric'] ?? 'revenue',
                'value' => 0,
                'threshold' => $config['threshold'] ?? '10000',
                'period' => $config['period'] ?? '1m',
                'currency' => 'EUR',
            ],
            'trigger.manual' => [
                'triggered_at' => date('Y-m-d H:i:s'),
            ],
            'trigger.calendar.event_created' => [
                'event_id' => 0,
                'event_title' => 'Sample Event',
                'event_start' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'event_end' => date('Y-m-d H:i:s', strtotime('+2 hours')),
                'calendar_name' => 'Default',
            ],
            'trigger.calendar.event_upcoming' => [
                'event_id' => 0,
                'event_title' => 'Upcoming Meeting',
                'event_start' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
                'event_end' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'minutes_until' => $config['minutes_before'] ?? 15,
                'calendar_name' => 'Default',
            ],
            'trigger.drive.file_uploaded' => [
                'file_id' => 0,
                'file_name' => 'document.pdf',
                'file_type' => 'application/pdf',
                'file_size' => 1024,
                'folder_name' => 'Uploads',
            ],
            'trigger.drive.file_updated' => [
                'file_id' => 0,
                'file_name' => 'report.xlsx',
                'file_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'updated_at' => date('Y-m-d H:i:s'),
                'folder_name' => 'Documents',
            ],
            default => [],
        };
    }

    // ── Logic ───────────────────────────────────────────────────────────

    private function executeCondition(array $config, array $inputData): array
    {
        $field = $config['field'] ?? '';
        $operator = $config['operator'] ?? 'equals';
        $compareValue = $config['value'] ?? '';

        $actualValue = $this->resolveField($field, $inputData);

        $result = match ($operator) {
            'equals' => (string)$actualValue === (string)$compareValue,
            'not_equals' => (string)$actualValue !== (string)$compareValue,
            'greater_than' => is_numeric($actualValue) && is_numeric($compareValue) && (float)$actualValue > (float)$compareValue,
            'less_than' => is_numeric($actualValue) && is_numeric($compareValue) && (float)$actualValue < (float)$compareValue,
            'contains' => is_string($actualValue) && str_contains($actualValue, $compareValue),
            'not_empty' => !empty($actualValue),
            'is_empty' => empty($actualValue),
            default => false,
        };

        return array_merge($inputData, [
            'condition_result' => $result,
            'condition_field' => $field,
            'condition_actual' => $actualValue,
        ]);
    }

    private function executeFilter(array $config, array $inputData): array
    {
        $field = $config['field'] ?? '';
        $operator = $config['operator'] ?? 'not_empty';
        $compareValue = $config['value'] ?? '';
        $actualValue = $this->resolveField($field, $inputData);

        $passes = match ($operator) {
            'equals' => (string)$actualValue === (string)$compareValue,
            'not_equals' => (string)$actualValue !== (string)$compareValue,
            'greater_than' => is_numeric($actualValue) && (float)$actualValue > (float)$compareValue,
            'less_than' => is_numeric($actualValue) && (float)$actualValue < (float)$compareValue,
            'contains' => is_string($actualValue) && str_contains($actualValue, $compareValue),
            'not_empty' => !empty($actualValue),
            'is_empty' => empty($actualValue),
            default => true,
        };

        return array_merge($inputData, ['passes' => $passes]);
    }

    private function executeMerge(array $config, array $inputData): array
    {
        $strategy = $config['strategy'] ?? 'combine';
        $inputA = $inputData['input_a'] ?? [];
        $inputB = $inputData['input_b'] ?? [];

        return match ($strategy) {
            'combine' => array_merge(
                is_array($inputA) ? $inputA : ['input_a' => $inputA],
                is_array($inputB) ? $inputB : ['input_b' => $inputB],
                ['merge_strategy' => 'combine']
            ),
            'first' => array_merge(
                is_array($inputA) ? $inputA : ['data' => $inputA],
                ['merge_strategy' => 'first']
            ),
            'last' => array_merge(
                is_array($inputB) ? $inputB : ['data' => $inputB],
                ['merge_strategy' => 'last']
            ),
            default => array_merge($inputA, $inputB, ['merge_strategy' => $strategy]),
        };
    }

    // ── Actions ─────────────────────────────────────────────────────────

    private function executeSendEmail(array $config, array $inputData, bool $isTest): array
    {
        try {
            $recipientSource = $config['recipient_source'] ?? 'manual';
            $recipients = [];
            $userEmail = $inputData['user_email'] ?? '';

            switch ($recipientSource) {
                case 'mailing_list':
                    $listId = (int)($config['list_id'] ?? 0);
                    if ($listId) {
                        $mlService = new \Webmail\Addons\EmailMarketing\Services\MailingListService($this->config);
                        $contacts = $mlService->getContacts($listId, $userEmail);
                        $recipients = array_filter(array_map(fn($c) => $c['email'] ?? '', $contacts));
                    }
                    break;
                case 'team_group':
                    $groupId = (int)($config['group_id'] ?? 0);
                    $domain = explode('@', $userEmail)[1] ?? '';
                    $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
                    $members = $groupId ? $colleagueService->getGroupMembers($groupId) : $colleagueService->getColleagues($domain);
                    $recipients = array_filter(array_map(fn($m) => $m['email'] ?? '', $members));
                    break;
                case 'upstream':
                    $emailStr = $inputData['contact_emails'] ?? ($inputData['member_emails'] ?? '');
                    $recipients = array_filter(array_map('trim', explode(',', $emailStr)));
                    break;
                default:
                    $recipients = [$config['to'] ?? ''];
            }

            $recipients = array_filter($recipients);
            if (empty($recipients)) {
                return array_merge($inputData, ['action' => 'send_email', 'success' => false, 'error' => 'No recipients resolved']);
            }

            if ($isTest) {
                return array_merge($inputData, ['action' => 'send_email', 'success' => true, 'test_mode' => true, 'to' => implode(', ', $recipients), 'recipient_count' => count($recipients)]);
            }

            $emailQueue = new \Webmail\Services\EmailQueueService($this->config);
            foreach ($recipients as $to) {
                $emailQueue->enqueue([
                    'to' => $to,
                    'subject' => $config['subject'] ?? 'Automation Hub',
                    'html_body' => $config['body'] ?? '',
                    'from_email' => $config['from'] ?? ($this->config['mail']['from'] ?? ''),
                ]);
            }
            return array_merge($inputData, ['action' => 'send_email', 'success' => true, 'to' => implode(', ', $recipients), 'recipient_count' => count($recipients)]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'send_email', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeSendChat(array $config, array $inputData, bool $isTest): array
    {
        try {
            $conversationId = (int)($config['channel_id'] ?? ($config['conversation_id'] ?? 0));
            $senderEmail = $config['sender_email'] ?? ($inputData['user_email'] ?? '');
            $content = $config['message'] ?? 'Automation Hub notification';

            error_log("AutomationHub::executeSendChat - conversationId={$conversationId}, sender={$senderEmail}, content_length=" . strlen($content) . ", content=" . mb_substr($content, 0, 200));
            error_log("AutomationHub::executeSendChat - raw config: " . json_encode($config));

            if (!$conversationId) {
                return array_merge($inputData, ['action' => 'send_chat', 'success' => false, 'error' => 'No channel or conversation selected']);
            }

            if (empty($senderEmail)) {
                return array_merge($inputData, ['action' => 'send_chat', 'success' => false, 'error' => 'No sender email resolved']);
            }

            $chatService = new \Webmail\Addons\Chat\Services\ChatService($this->config);
            $result = $chatService->sendMessage(
                $conversationId,
                $senderEmail,
                $content,
                null,
                null,
                null,
                false,
                true
            );

            $messageId = $result['message']['id'] ?? null;
            error_log("AutomationHub::executeSendChat - result: success=" . ($result['success'] ? 'true' : 'false') . ", messageId={$messageId}, error=" . ($result['error'] ?? 'none'));

            return array_merge($inputData, [
                'action' => 'send_chat',
                'success' => $result['success'] ?? false,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'sent_content' => mb_substr($content, 0, 200),
                'sender_email' => $senderEmail,
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log("AutomationHub::executeSendChat - EXCEPTION: " . $e->getMessage());
            return array_merge($inputData, ['action' => 'send_chat', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeSendNotification(array $config, array $inputData, bool $isTest): array
    {
        try {
            $recipientType = $config['recipient_type'] ?? 'trigger_user';
            $title = $config['title'] ?? 'Automation Hub';
            $message = $config['message'] ?? 'Workflow notification';
            $userEmail = $inputData['user_email'] ?? '';

            $emails = [];
            if ($recipientType === 'custom') {
                $emails[] = $config['to_email'] ?? $userEmail;
            } elseif (str_starts_with($recipientType, 'colleague:')) {
                $emails[] = substr($recipientType, strlen('colleague:'));
            } elseif (str_starts_with($recipientType, 'group:')) {
                $groupId = (int)substr($recipientType, strlen('group:'));
                if ($groupId) {
                    $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
                    $members = $colleagueService->getGroupMembers($groupId);
                    $emails = array_filter(array_map(fn($m) => $m['email'] ?? '', $members));
                }
            } elseif ($recipientType === 'mailing_list') {
                $listId = (int)($config['list_id'] ?? 0);
                if ($listId) {
                    $mlService = new \Webmail\Addons\EmailMarketing\Services\MailingListService($this->config);
                    $contacts = $mlService->getContacts($listId, $userEmail);
                    $emails = array_filter(array_map(fn($c) => $c['email'] ?? '', $contacts));
                }
            } else {
                $emails[] = $userEmail;
            }

            $emails = array_filter($emails);
            if (empty($emails)) {
                return array_merge($inputData, ['action' => 'send_notification', 'success' => false, 'error' => 'No recipients resolved']);
            }

            $trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($this->config);
            $notifIds = [];

            foreach ($emails as $email) {
                $notifId = $trackingService->createNotification(
                    $email, 'automation_hub', $title, $message,
                    ['source' => 'automation_hub', 'workflow_id' => $inputData['workflow_id'] ?? null]
                );
                $notifIds[] = $notifId;

                try {
                    $redis = new \Redis();
                    $host = $this->config['redis']['host'] ?? '127.0.0.1';
                    $port = $this->config['redis']['port'] ?? 6379;
                    $redis->connect($host, $port, 2.0);
                    $password = $this->config['redis']['password'] ?? null;
                    if ($password) $redis->auth($password);
                    $prefix = $this->config['redis']['prefix'] ?? 'webmail:';
                    $redis->publish($prefix . 'notifications:' . $email, json_encode([
                        'type' => 'automation_hub', 'title' => $title, 'message' => $message, 'id' => $notifId,
                    ]));
                    $redis->close();
                } catch (\Throwable $e) {
                    // Real-time push is best-effort
                }
            }

            return array_merge($inputData, [
                'action' => 'send_notification', 'success' => true,
                'notification_id' => $notifIds[0] ?? null,
                'recipient_count' => count($emails),
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'send_notification', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeTelegramSend(array $config, array $inputData, bool $isTest): array
    {
        $botToken = $config['bot_token'] ?? '';
        $chatId = $config['chat_id'] ?? ($inputData['telegram_chat_id'] ?? '');
        $message = $config['message'] ?? '';
        $parseMode = $config['parse_mode'] ?? 'Markdown';

        if (empty($botToken) || empty($chatId) || empty($message)) {
            return array_merge($inputData, ['action' => 'telegram_send', 'success' => false, 'error' => 'Missing bot_token, chat_id, or message']);
        }

        try {
            $telegramService = new TelegramBotService();
            $result = $telegramService->sendMessage($botToken, $chatId, $message, $parseMode);
            return array_merge($inputData, ['action' => 'telegram_send', 'success' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'telegram_send', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeHttpRequest(array $config, array $inputData, bool $isTest): array
    {
        $method = strtoupper($config['method'] ?? 'GET');
        $url = $config['url'] ?? '';

        if (empty($url)) {
            return array_merge($inputData, ['action' => 'http_request', 'success' => false, 'error' => 'URL is required']);
        }

        try {
            $headers = [];
            if (!empty($config['headers'])) {
                $parsed = is_string($config['headers']) ? json_decode($config['headers'], true) : $config['headers'];
                if (is_array($parsed)) {
                    foreach ($parsed as $k => $v) {
                        $headers[] = "{$k}: {$v}";
                    }
                }
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($config['request_body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($config['request_body']) ? $config['request_body'] : json_encode($config['request_body']));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return array_merge($inputData, ['action' => 'http_request', 'success' => false, 'error' => $error]);
            }

            $decoded = json_decode($response, true);
            return array_merge($inputData, [
                'action' => 'http_request',
                'success' => $httpCode >= 200 && $httpCode < 400,
                'status_code' => $httpCode,
                'response' => $decoded ?? $response,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'http_request', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeMoveDeal(array $config, array $inputData, bool $isTest): array
    {
        try {
            $dealId = (int)($config['deal_id'] ?? ($inputData['deal_id'] ?? 0));
            $stage = $config['target_stage'] ?? ($config['stage'] ?? '');
            $userEmail = $inputData['user_email'] ?? '';

            if ($dealId && $stage) {
                $dealService = new \Webmail\Addons\CrmPro\Services\CrmDealService($this->config);
                $dealService->update($dealId, ['stage' => $stage], $userEmail);
            }
            return array_merge($inputData, ['action' => 'move_deal', 'success' => true, 'deal_id' => $dealId, 'stage' => $stage]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'move_deal', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeMoveCard(array $config, array $inputData, bool $isTest): array
    {
        try {
            $cardId = (int)($config['card_id'] ?? ($inputData['card_id'] ?? 0));
            $listId = (int)($config['target_list_id'] ?? ($config['list_id'] ?? 0));

            if ($cardId && $listId) {
                $db = $this->getDb();
                $db->prepare("UPDATE webmail_board_cards SET list_id = ?, updated_at = NOW() WHERE id = ?")->execute([$listId, $cardId]);
            }
            return array_merge($inputData, ['action' => 'move_card', 'success' => true, 'card_id' => $cardId, 'list_id' => $listId]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'move_card', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeCreateTask(array $config, array $inputData, bool $isTest): array
    {
        try {
            $db = $this->getDb();

            $listId = (int)($config['list_id'] ?? 0);
            $title = $config['title'] ?? 'Task from Automation Hub';
            $description = $config['description'] ?? '';
            $assignee = $config['assignee'] ?? ($inputData['user_email'] ?? '');

            if ($listId) {
                $posStmt = $db->prepare("SELECT COALESCE(MAX(position), 0) + 1 AS next_pos FROM webmail_board_cards WHERE list_id = ?");
                $posStmt->execute([$listId]);
                $nextPos = (int)$posStmt->fetchColumn();

                $stmt = $db->prepare("
                    INSERT INTO webmail_board_cards (list_id, title, description, assigned_to, created_by, position, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$listId, $title, $description, $assignee, $assignee, $nextPos]);
                return array_merge($inputData, ['action' => 'create_task', 'success' => true, 'card_id' => (int)$db->lastInsertId(), 'list_id' => $listId]);
            }

            $stmt = $db->prepare("
                INSERT INTO webmail_todos (email, title, description, priority, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$inputData['user_email'] ?? '', $title, $description, $config['priority'] ?? 'normal']);
            return array_merge($inputData, ['action' => 'create_task', 'success' => true, 'task_id' => (int)$db->lastInsertId()]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'create_task', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeExportCsv(array $config, array $inputData, bool $isTest): array
    {
        $dataSource = $config['data_source'] ?? 'input';
        $columnsRaw = $config['columns'] ?? '';
        $columns = $this->parseColumnList($columnsRaw);
        $userEmail = $inputData['user_email'] ?? '';

        $workflowName = '';
        $workflowId = (int)($inputData['workflow_id'] ?? 0);
        if ($workflowId) {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT name FROM automation_hub_workflows WHERE id = ?");
            $stmt->execute([$workflowId]);
            $workflowName = $stmt->fetchColumn() ?: '';
        }

        $filename = $config['filename'] ?? '';
        if (empty($filename)) {
            $safeName = $workflowName ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $workflowName) : 'export';
            $filename = $safeName . '-' . date('Y-m-d-His') . '.csv';
        }
        if (!str_ends_with(strtolower($filename), '.csv')) {
            $filename .= '.csv';
        }

        try {
            $rows = $this->fetchExportData($dataSource, $config, $inputData);

            if (!empty($config['filter_metric']) && $config['filter_metric'] !== 'none') {
                $rows = $this->applyExportFilter($rows, $config);
            }

            if (!empty($columns)) {
                $rows = array_map(function ($row) use ($columns) {
                    $filtered = [];
                    foreach ($columns as $col) {
                        $filtered[$col] = $row[$col] ?? '';
                    }
                    return $filtered;
                }, $rows);
            }

            $csvContent = "\xEF\xBB\xBF";
            if (!empty($rows)) {
                $tmpStream = fopen('php://temp', 'r+');
                fprintf($tmpStream, "\xEF\xBB\xBF");
                fputcsv($tmpStream, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($tmpStream, array_values($row));
                }
                rewind($tmpStream);
                $csvContent = stream_get_contents($tmpStream);
                fclose($tmpStream);
            }

            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

            // Save to Drive in "CSV Exports" folder
            $driveService = new \Webmail\Services\DriveService($this->config);
            $db = $this->getDb();

            $stmt = $db->prepare("SELECT id FROM drive_folders WHERE user_email = ? AND name = 'CSV Exports' AND parent_id IS NULL LIMIT 1");
            $stmt->execute([$userEmail]);
            $folderId = $stmt->fetchColumn();

            if (!$folderId) {
                $folder = $driveService->createFolder($userEmail, 'CSV Exports');
                $folderId = $folder['id'] ?? null;
            }

            $driveFile = $driveService->uploadFileContent(
                $userEmail, $safeFilename, $csvContent, 'text/csv', $folderId ? (int)$folderId : null
            );

            $fileId = $driveFile['id'] ?? null;
            $driveUrl = $fileId ? "/drive/files/{$fileId}" : null;

            // Also save to storage/exports for API download
            $exportDir = dirname(__DIR__, 4) . '/storage/exports';
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0777, true);
            }
            $uniqueName = uniqid() . '_' . $safeFilename;
            $filePath = $exportDir . '/' . $uniqueName;
            file_put_contents($filePath, $csvContent);

            $downloadUrl = '/api/automation-hub/exports/' . $uniqueName;

            if (($config['notify'] ?? 'none') === 'email') {
                $this->sendExportNotificationEmail($config, $inputData, $downloadUrl, count($rows), $safeFilename);
            } elseif (($config['notify'] ?? 'none') === 'notification') {
                $this->sendExportNotificationPush($config, $inputData, $downloadUrl, count($rows), $safeFilename);
            }

            return array_merge($inputData, [
                'action' => 'export_csv',
                'success' => true,
                'filename' => $safeFilename,
                'download_url' => $downloadUrl,
                'drive_file_id' => $fileId,
                'drive_url' => $driveUrl,
                'folder_name' => 'CSV Exports',
                'row_count' => count($rows),
                'file_size' => strlen($csvContent),
                'workflow_name' => $workflowName,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'export_csv', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function fetchExportData(string $source, array $config, array $inputData): array
    {
        $dsn = "mysql:host={$this->config['db']['host']};dbname={$this->config['db']['name']};charset=utf8mb4";
        $db = new \PDO($dsn, $this->config['db']['user'], $this->config['db']['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        switch ($source) {
            case 'email_campaign':
                return $this->fetchEmailCampaignData($db, $config);

            case 'crm_deals':
                return $this->fetchCrmDealData($db, $config);

            case 'board_cards':
                return $this->fetchBoardCardData($db, $config);

            case 'google_contacts':
                $raw = $inputData['contacts'] ?? [];
                if (empty($raw)) return [];
                return array_map(fn($c) => [
                    'name' => $c['name'] ?? '',
                    'email' => $c['email'] ?? '',
                    'phone' => $c['phone'] ?? '',
                    'company' => $c['company'] ?? '',
                ], $raw);

            case 'input':
            default:
                $data = $inputData['contacts'] ?? $inputData['members'] ?? $inputData['data'] ?? $inputData['rows'] ?? $inputData;
                if (isset($data[0]) && is_array($data[0])) {
                    return $data;
                }
                return [$data];
        }
    }

    private function fetchEmailCampaignData(\PDO $db, array $config): array
    {
        $campaignId = $config['campaign_id'] ?? null;

        $sql = "
            SELECT 
                er.recipient_email AS email,
                er.recipient_name AS name,
                er.status,
                er.opened_at,
                er.clicked_at,
                COALESCE(er.open_count, 0) AS open_count,
                COALESCE(er.click_count, 0) AS click_count,
                COALESCE(er.link_count, 0) AS total_links,
                CASE 
                    WHEN er.link_count > 0 THEN ROUND(er.click_count * 100.0 / er.link_count, 1)
                    ELSE 0 
                END AS click_rate,
                CASE
                    WHEN er.sent_at IS NOT NULL AND er.opened_at IS NOT NULL THEN 100
                    ELSE 0
                END AS open_rate,
                er.bounced,
                er.unsubscribed,
                er.sent_at,
                ec.name AS campaign_name
            FROM email_campaign_recipients er
            JOIN email_campaigns ec ON er.campaign_id = ec.id
        ";

        $params = [];
        if ($campaignId) {
            $sql .= " WHERE er.campaign_id = ?";
            $params[] = $campaignId;
        }
        $sql .= " ORDER BY er.sent_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function fetchCrmDealData(\PDO $db, array $config): array
    {
        $sql = "SELECT d.*, p.name AS pipeline_name FROM crm_deals d LEFT JOIN crm_pipelines p ON d.pipeline_id = p.id WHERE 1=1";
        $params = [];

        if (!empty($config['pipeline_id'])) {
            $sql .= " AND d.pipeline_id = ?";
            $params[] = $config['pipeline_id'];
        }
        if (!empty($config['stage_filter'])) {
            $sql .= " AND d.stage = ?";
            $params[] = $config['stage_filter'];
        }
        $sql .= " ORDER BY d.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function fetchBoardCardData(\PDO $db, array $config): array
    {
        $sql = "
            SELECT c.*, l.name AS list_title, b.name AS board_title 
            FROM webmail_board_cards c 
            LEFT JOIN webmail_board_lists l ON c.list_id = l.id 
            LEFT JOIN webmail_boards b ON l.board_id = b.id 
            WHERE 1=1
        ";
        $params = [];

        if (!empty($config['board_id'])) {
            $sql .= " AND b.id = ?";
            $params[] = $config['board_id'];
        }
        if (!empty($config['list_filter'])) {
            $sql .= " AND l.name = ?";
            $params[] = $config['list_filter'];
        }
        $sql .= " ORDER BY c.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function applyExportFilter(array $rows, array $config): array
    {
        $metric = $config['filter_metric'] ?? '';
        $condition = $config['filter_condition'] ?? 'above';
        $threshold = (float)($config['filter_threshold'] ?? 0);

        return array_values(array_filter($rows, function ($row) use ($metric, $condition, $threshold) {
            if ($metric === 'bounce') return !empty($row['bounced']);
            if ($metric === 'unsubscribed') return !empty($row['unsubscribed']);

            $value = (float)($row[$metric] ?? 0);
            return match ($condition) {
                'above' => $value > $threshold,
                'below' => $value < $threshold,
                'equals' => abs($value - $threshold) < 0.01,
                default => true,
            };
        }));
    }

    private function parseColumnList(string $raw): array
    {
        if (empty(trim($raw))) return [];

        $parts = preg_split('/[\n,]+/', $raw);
        return array_values(array_filter(array_map('trim', $parts)));
    }

    private function sendExportNotificationEmail(array $config, array $inputData, string $url, int $rowCount, string $filename): void
    {
        try {
            $to = $config['notify_email'] ?? ($inputData['user_email'] ?? '');
            if (empty($to)) return;

            $emailQueue = new \Webmail\Services\EmailQueueService($this->config);
            $emailQueue->enqueue([
                'to' => $to,
                'subject' => "CSV Export Ready: {$filename}",
                'html_body' => "<p>Your CSV export is ready.</p><p><b>File:</b> {$filename}<br><b>Rows:</b> {$rowCount}</p><p><a href=\"https://flowone.pro{$url}\">Download CSV</a></p>",
                'from_email' => $this->config['mail']['from'] ?? '',
            ]);
        } catch (\Throwable $e) {
            error_log("AutomationHub CSV export email notification error: " . $e->getMessage());
        }
    }

    private function sendExportNotificationPush(array $config, array $inputData, string $url, int $rowCount, string $filename): void
    {
        try {
            $email = $inputData['user_email'] ?? '';
            if (empty($email) || !extension_loaded('redis')) return;

            $redis = new \Redis();
            $host = $this->config['redis']['host'] ?? '127.0.0.1';
            $port = $this->config['redis']['port'] ?? 6379;
            $redis->connect($host, $port, 2.0);
            $password = $this->config['redis']['password'] ?? null;
            if ($password) $redis->auth($password);
            $prefix = $this->config['redis']['prefix'] ?? 'webmail:';

            $redis->publish($prefix . 'notifications:' . $email, json_encode([
                'type' => 'automation_hub_export',
                'title' => 'CSV Export Ready',
                'message' => "{$filename} ({$rowCount} rows)",
                'download_url' => $url,
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log("AutomationHub CSV export push notification error: " . $e->getMessage());
        }
    }

    // ── Client Actions ─────────────────────────────────────────────────

    private function getDb(): \PDO
    {
        $dsn = "mysql:host={$this->config['db']['host']};dbname={$this->config['db']['name']};charset=utf8mb4";
        return new \PDO($dsn, $this->config['db']['user'], $this->config['db']['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private function executeClientGetData(array $config, array $inputData, bool $isTest): array
    {
        $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));

        try {
            $db = $this->getDb();

            $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch();
            if (!$client) {
                return ['action' => 'client_get_data', 'success' => false, 'error' => 'Client not found'];
            }

            $stmt2 = $db->prepare("SELECT email, name, phone, position FROM client_contacts WHERE client_id = ?");
            $stmt2->execute([$clientId]);
            $contacts = $stmt2->fetchAll();

            return array_merge($inputData, [
                'action' => 'client_get_data',
                'success' => true,
                'client_id' => $client['id'],
                'client_name' => $client['display_name'] ?? $client['domain'] ?? '',
                'client_domain' => $client['domain'] ?? '',
                'client_status' => $client['status'] ?? 'active',
                'contacts' => $contacts,
                'open_task_count' => (int)($client['open_task_count'] ?? 0),
                'overdue_task_count' => (int)($client['overdue_task_count'] ?? 0),
                'last_activity' => $client['last_activity_at'] ?? null,
                'hourly_rate' => $client['hourly_rate'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'client_get_data', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeClientGetFinancials(array $config, array $inputData, bool $isTest): array
    {
        $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));

        try {
            $db = $this->getDb();

            $stmt = $db->prepare("
                SELECT
                    COALESCE(SUM(total), 0) AS total_invoiced,
                    COALESCE(SUM(paid_amount), 0) AS total_paid,
                    COALESCE(SUM(total) - SUM(paid_amount), 0) AS outstanding,
                    COUNT(*) AS invoice_count
                FROM crm_invoices
                WHERE client_id = ? AND status != 'cancelled'
            ");
            $stmt->execute([$clientId]);
            $inv = $stmt->fetch();

            $stmt2 = $db->prepare("SELECT hourly_rate FROM clients WHERE id = ?");
            $stmt2->execute([$clientId]);
            $client = $stmt2->fetch();

            return array_merge($inputData, [
                'action' => 'client_get_financials',
                'success' => true,
                'client_id' => $clientId,
                'total_invoiced' => (float)$inv['total_invoiced'],
                'total_paid' => (float)$inv['total_paid'],
                'outstanding' => (float)$inv['outstanding'],
                'invoice_count' => (int)$inv['invoice_count'],
                'total_revenue' => (float)$inv['total_paid'],
                'hourly_rate' => $client['hourly_rate'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'client_get_financials', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeClientGetHealth(array $config, array $inputData, bool $isTest): array
    {
        $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));

        try {
            $db = $this->getDb();

            $stmt = $db->prepare("SELECT id, display_name, domain, last_activity_at FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch();
            if (!$client) {
                return ['action' => 'client_get_health', 'success' => false, 'error' => 'Client not found'];
            }

            $lastActivity = $client['last_activity_at'] ? strtotime($client['last_activity_at']) : 0;
            $daysSince = $lastActivity ? (int)((time() - $lastActivity) / 86400) : 999;

            if ($daysSince <= 7) $score = 100;
            elseif ($daysSince <= 14) $score = 80;
            elseif ($daysSince <= 30) $score = 60;
            elseif ($daysSince <= 60) $score = 40;
            elseif ($daysSince <= 90) $score = 20;
            else $score = 10;

            return array_merge($inputData, [
                'action' => 'client_get_health',
                'success' => true,
                'client_id' => $clientId,
                'client_name' => $client['display_name'] ?? $client['domain'],
                'health_score' => $score,
                'last_activity' => $client['last_activity_at'],
                'days_since_activity' => $daysSince,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'client_get_health', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Invoice Actions ──────────────────────────────────────────────────

    private function executeInvoiceCreate(array $config, array $inputData, bool $isTest): array
    {
        $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));

        try {
            $invoiceService = new \Webmail\Addons\CrmPro\Services\CrmInvoiceService($this->config);
            $dueOffset = (int)($config['due_date_offset'] ?? 30);
            $result = $invoiceService->createInvoice($inputData['user_email'] ?? '', [
                'client_id' => $clientId,
                'items' => json_decode($config['items'] ?? '[]', true) ?: [],
                'due_date' => $config['due_date'] ?? date('Y-m-d', strtotime("+{$dueOffset} days")),
                'notes' => $config['notes'] ?? '',
                'currency' => $config['currency'] ?? 'EUR',
            ]);

            return array_merge($inputData, [
                'action' => 'invoice_create',
                'success' => true,
                'invoice_id' => $result['id'] ?? null,
                'invoice_number' => $result['invoice_number'] ?? '',
                'total' => $result['total'] ?? 0,
                'status' => 'draft',
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'invoice_create', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeInvoiceSend(array $config, array $inputData, bool $isTest): array
    {
        $invoiceId = (int)($config['invoice_id'] ?? ($inputData['invoice_id'] ?? 0));

        try {
            $db = $this->getDb();
            $stmt = $db->prepare("UPDATE crm_invoices SET status = 'sent', sent_at = NOW() WHERE id = ? AND status = 'draft'");
            $stmt->execute([$invoiceId]);

            return array_merge($inputData, [
                'action' => 'invoice_send',
                'success' => $stmt->rowCount() > 0,
                'invoice_id' => $invoiceId,
                'status' => 'sent',
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'invoice_send', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeInvoiceRecordPayment(array $config, array $inputData, bool $isTest): array
    {
        $invoiceId = (int)($config['invoice_id'] ?? ($inputData['invoice_id'] ?? 0));
        $amount = (float)($config['amount'] ?? ($inputData['payment_amount'] ?? 0));
        $method = $config['payment_method'] ?? 'other';

        try {
            $invoiceService = new \Webmail\Addons\CrmPro\Services\CrmInvoiceService($this->config);
            $result = $invoiceService->recordPayment($invoiceId, $inputData['user_email'] ?? '', [
                'amount' => $amount,
                'payment_method' => $method,
                'payment_date' => date('Y-m-d'),
                'reference' => $config['reference'] ?? 'Automation Hub',
            ]);

            return array_merge($inputData, [
                'action' => 'invoice_record_payment',
                'success' => true,
                'invoice_id' => $invoiceId,
                'payment_amount' => $amount,
                'new_status' => $result['status'] ?? '',
                'remaining' => $result['remaining'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'invoice_record_payment', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Statistics Actions ────────────────────────────────────────────────

    private function executeStatsEmail(array $config, array $inputData, bool $isTest): array
    {
        $period = $config['period'] ?? '30d';

        try {
            $db = $this->getDb();
            $userEmail = $inputData['user_email'] ?? '';
            $days = $this->periodToDays($period);
            $since = date('Y-m-d', strtotime("-{$days} days"));

            $stmt = $db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN stat_type = 'emails_sent' THEN value ELSE 0 END), 0) AS total_sent,
                    COALESCE(SUM(CASE WHEN stat_type = 'emails_received' THEN value ELSE 0 END), 0) AS total_received,
                    COALESCE(AVG(CASE WHEN stat_type = 'avg_reply_time' THEN value ELSE NULL END), 0) AS avg_reply_time
                FROM webmail_statistics
                WHERE user_email = ? AND period_start >= ?
            ");
            $stmt->execute([$userEmail, $since]);
            $stats = $stmt->fetch();

            return array_merge($inputData, [
                'action' => 'stats_email',
                'success' => true,
                'total_sent' => (int)$stats['total_sent'],
                'total_received' => (int)$stats['total_received'],
                'avg_reply_time' => (int)$stats['avg_reply_time'],
                'period' => $period,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'stats_email', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeStatsResponseTime(array $config, array $inputData, bool $isTest): array
    {
        $limit = (int)($config['top_contacts_limit'] ?? 10);

        try {
            $db = $this->getDb();
            $userEmail = $inputData['user_email'] ?? '';

            $stmt = $db->prepare("
                SELECT contact_email, avg_reply_time_seconds, emails_sent, emails_received
                FROM webmail_contact_stats
                WHERE user_email = ? AND avg_reply_time_seconds IS NOT NULL AND avg_reply_time_seconds > 0
                ORDER BY avg_reply_time_seconds ASC
                LIMIT ?
            ");
            $stmt->execute([$userEmail, $limit]);
            $contacts = $stmt->fetchAll();

            $avgStmt = $db->prepare("
                SELECT AVG(avg_reply_time_seconds) AS avg_reply_time
                FROM webmail_contact_stats
                WHERE user_email = ? AND avg_reply_time_seconds IS NOT NULL AND avg_reply_time_seconds > 0
            ");
            $avgStmt->execute([$userEmail]);
            $avgRow = $avgStmt->fetch();
            $avgTime = (int)($avgRow['avg_reply_time'] ?? 0);

            return array_merge($inputData, [
                'action' => 'stats_response_time',
                'success' => true,
                'avg_reply_time' => $avgTime,
                'avg_reply_time_formatted' => $this->formatDuration($avgTime),
                'top_contacts' => $contacts,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'stats_response_time', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeStatsRevenueReport(array $config, array $inputData, bool $isTest): array
    {
        $period = $config['period'] ?? '12m';
        $months = $this->periodToMonths($period);

        try {
            $db = $this->getDb();
            $since = date('Y-m-d', strtotime("-{$months} months"));

            $revStmt = $db->prepare("
                SELECT
                    DATE_FORMAT(paid_at, '%Y-%m') AS month,
                    COALESCE(SUM(paid_amount), 0) AS revenue
                FROM crm_invoices
                WHERE status IN ('paid', 'partial') AND paid_at >= ?
                GROUP BY month ORDER BY month
            ");
            $revStmt->execute([$since]);
            $revenueRows = $revStmt->fetchAll();

            $expStmt = $db->prepare("
                SELECT
                    DATE_FORMAT(expense_date, '%Y-%m') AS month,
                    COALESCE(SUM(amount), 0) AS expenses
                FROM crm_expenses
                WHERE expense_date >= ?
                GROUP BY month ORDER BY month
            ");
            $expStmt->execute([$since]);
            $expenseRows = $expStmt->fetchAll();

            $expByMonth = [];
            foreach ($expenseRows as $r) $expByMonth[$r['month']] = (float)$r['expenses'];

            $totalRevenue = 0;
            $totalExpenses = 0;
            $breakdown = [];
            foreach ($revenueRows as $r) {
                $m = $r['month'];
                $rev = (float)$r['revenue'];
                $exp = $expByMonth[$m] ?? 0;
                $totalRevenue += $rev;
                $totalExpenses += $exp;
                $breakdown[] = ['month' => $m, 'revenue' => $rev, 'expenses' => $exp, 'profit' => $rev - $exp];
            }

            return array_merge($inputData, [
                'action' => 'stats_revenue_report',
                'success' => true,
                'total_revenue' => $totalRevenue,
                'total_expenses' => $totalExpenses,
                'net_profit' => $totalRevenue - $totalExpenses,
                'period' => $period,
                'monthly_breakdown' => $breakdown,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'stats_revenue_report', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeStatsClientRanking(array $config, array $inputData, bool $isTest): array
    {
        $limit = (int)($config['limit'] ?? 10);
        $period = $config['period'] ?? '12m';
        $months = $this->periodToMonths($period);

        try {
            $db = $this->getDb();
            $since = date('Y-m-d', strtotime("-{$months} months"));

            $stmt = $db->prepare("
                SELECT
                    c.id AS client_id,
                    c.display_name AS client_name,
                    c.domain,
                    COALESCE(SUM(i.paid_amount), 0) AS total_revenue,
                    COUNT(i.id) AS invoice_count
                FROM clients c
                LEFT JOIN crm_invoices i ON i.client_id = c.id AND i.status IN ('paid', 'partial') AND i.paid_at >= ?
                GROUP BY c.id
                ORDER BY total_revenue DESC
                LIMIT ?
            ");
            $stmt->execute([$since, $limit]);
            $rankings = $stmt->fetchAll();

            $top = $rankings[0] ?? null;

            return array_merge($inputData, [
                'action' => 'stats_client_ranking',
                'success' => true,
                'rankings' => $rankings,
                'top_client' => $top ? ($top['client_name'] ?: $top['domain']) : '',
                'top_client_revenue' => $top ? (float)$top['total_revenue'] : 0,
                'period' => $period,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'stats_client_ranking', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeStatsAgingReport(array $config, array $inputData, bool $isTest): array
    {
        try {
            $db = $this->getDb();

            $stmt = $db->prepare("
                SELECT id, invoice_number, client_id, total, paid_amount, due_date,
                       DATEDIFF(NOW(), due_date) AS days_overdue
                FROM crm_invoices
                WHERE status IN ('sent', 'viewed', 'partial', 'overdue')
                  AND due_date < CURDATE()
                ORDER BY days_overdue DESC
            ");
            $stmt->execute();
            $overdue = $stmt->fetchAll();

            $buckets = ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
            $totalOverdue = 0;
            foreach ($overdue as $inv) {
                $remaining = (float)$inv['total'] - (float)$inv['paid_amount'];
                $totalOverdue += $remaining;
                $days = (int)$inv['days_overdue'];
                if ($days <= 30) $buckets['0_30'] += $remaining;
                elseif ($days <= 60) $buckets['31_60'] += $remaining;
                elseif ($days <= 90) $buckets['61_90'] += $remaining;
                else $buckets['90_plus'] += $remaining;
            }

            return array_merge($inputData, [
                'action' => 'stats_aging_report',
                'success' => true,
                'overdue_count' => count($overdue),
                'overdue_total' => $totalOverdue,
                'aging_buckets' => $buckets,
                'invoices' => array_slice($overdue, 0, 20),
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'stats_aging_report', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── AI Actions ────────────────────────────────────────────────────────

    private function getAIServiceForUser(string $userEmail): ?\Webmail\Addons\AIAssistant\Services\AIService
    {
        $settings = [];

        if ($userEmail) {
            $hash = md5(strtolower($userEmail));
            $file = '/var/www/vps-email/data/global/ai_' . $hash . '.json';
            if (file_exists($file)) {
                $settings = json_decode(file_get_contents($file), true) ?: [];
            }
        }

        // Fallback: find any configured AI settings
        if (empty($settings['ai_api_key_encrypted'])) {
            $files = glob('/var/www/vps-email/data/global/ai_*.json');
            foreach ($files as $f) {
                $parsed = json_decode(file_get_contents($f), true);
                if (!empty($parsed['ai_api_key_encrypted'])) {
                    $settings = $parsed;
                    break;
                }
            }
        }

        if (empty($settings['ai_api_key_encrypted'])) {
            return null;
        }

        $secret = $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
        $apiKey = \Webmail\Addons\AIAssistant\Services\AIService::decryptApiKey($settings['ai_api_key_encrypted'], $secret);

        if (!$apiKey) return null;

        return new \Webmail\Addons\AIAssistant\Services\AIService(
            $apiKey,
            $settings['ai_model'] ?? 'gpt-5-nano',
            ['temperature' => $settings['ai_temperature'] ?? 1.0]
        );
    }

    private function executeAIPrompt(array $config, array $inputData): array
    {
        try {
            $aiService = $this->getAIServiceForUser($inputData['user_email'] ?? '');
            if (!$aiService) {
                return ['action' => 'ai_prompt', 'success' => false, 'error' => 'AI not configured. Add your API key in Settings > AI Assistant.'];
            }

            $prompt = $config['prompt'] ?? '';
            $systemPrompt = $config['system_prompt'] ?? 'You are a helpful AI assistant integrated into an automation workflow. Respond concisely and actionably.';

            if (empty($prompt)) {
                return ['action' => 'ai_prompt', 'success' => false, 'error' => 'Prompt is required'];
            }

            $result = $aiService->chat($systemPrompt, $prompt);

            return array_merge($inputData, [
                'action' => 'ai_prompt',
                'success' => $result['success'] ?? false,
                'ai_response' => $result['content'] ?? '',
                'ai_model' => $result['model'] ?? '',
                'ai_tokens' => $result['usage']['total_tokens'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'ai_prompt', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeAISummarize(array $config, array $inputData): array
    {
        try {
            $aiService = $this->getAIServiceForUser($inputData['user_email'] ?? '');
            if (!$aiService) {
                return ['action' => 'ai_summarize', 'success' => false, 'error' => 'AI not configured'];
            }

            $textSource = $config['text_source'] ?? 'input';
            if ($textSource === 'custom') {
                $text = $config['text'] ?? '';
            } else {
                $text = $inputData['ai_response'] ?? $inputData['message'] ?? $inputData['description'] ?? json_encode($inputData);
            }

            $result = $aiService->summarize($text, $inputData['user_email'] ?? '');

            return array_merge($inputData, [
                'action' => 'ai_summarize',
                'success' => $result['success'] ?? false,
                'ai_summary' => $result['summary'] ?? ($result['content'] ?? ''),
                'ai_key_points' => $result['key_points'] ?? [],
                'ai_sentiment' => $result['sentiment'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'ai_summarize', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeAIRewrite(array $config, array $inputData): array
    {
        try {
            $aiService = $this->getAIServiceForUser($inputData['user_email'] ?? '');
            if (!$aiService) {
                return ['action' => 'ai_rewrite', 'success' => false, 'error' => 'AI not configured'];
            }

            $textSource = $config['text_source'] ?? 'input';
            if ($textSource === 'custom') {
                $text = $config['text'] ?? '';
            } else {
                $text = $inputData['ai_response'] ?? $inputData['message'] ?? $inputData['description'] ?? '';
            }

            $style = $config['writing_style'] ?? 'professional';

            $result = $aiService->rewrite($text, $style);

            return array_merge($inputData, [
                'action' => 'ai_rewrite',
                'success' => $result['success'] ?? false,
                'ai_rewritten' => $result['rewritten'] ?? ($result['content'] ?? ''),
                'ai_style' => $style,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'ai_rewrite', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Calendar Actions ──────────────────────────────────────────────────

    private function getCalendarService(): \Webmail\Addons\Calendar\Services\CalendarService
    {
        return new \Webmail\Addons\Calendar\Services\CalendarService($this->config);
    }

    private function executeCalendarCreateEvent(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $calSvc = $this->getCalendarService();
            $calendarId = (int)($config['calendar_id'] ?? 0);
            if (!$calendarId) {
                $default = $calSvc->getDefaultCalendar($email);
                $calendarId = $default['id'] ?? 0;
            }
            if (!$calendarId) return ['action' => 'calendar_create_event', 'success' => false, 'error' => 'No calendar found'];

            $event = $calSvc->createEvent($email, $calendarId, [
                'title' => $config['title'] ?? 'Automation Event',
                'description' => $config['description'] ?? '',
                'start_time' => $config['start_time'] ?? date('Y-m-d H:i:s', strtotime('+1 hour')),
                'end_time' => $config['end_time'] ?? date('Y-m-d H:i:s', strtotime('+2 hours')),
                'all_day' => (bool)($config['all_day'] ?? false),
                'location' => $config['location'] ?? '',
            ]);
            return array_merge($inputData, [
                'action' => 'calendar_create_event', 'success' => (bool)$event,
                'event_id' => $event['id'] ?? 0, 'event_title' => $event['title'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'calendar_create_event', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeCalendarGetEvents(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $calSvc = $this->getCalendarService();
            $calendarId = (int)($config['calendar_id'] ?? 0);
            $start = $config['start_date'] ?? date('Y-m-d');
            $end = $config['end_date'] ?? date('Y-m-d', strtotime('+7 days'));

            $events = $calendarId
                ? $calSvc->getEvents($email, $calendarId, $start, $end)
                : $calSvc->getAllEvents($email, $start, $end);

            return array_merge($inputData, [
                'action' => 'calendar_get_events', 'success' => true,
                'events' => $events, 'events_count' => count($events),
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'calendar_get_events', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeCalendarUpdateEvent(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $eventId = (int)($config['event_id'] ?? ($inputData['event_id'] ?? 0));
            if (!$eventId) return ['action' => 'calendar_update_event', 'success' => false, 'error' => 'No event ID'];

            $data = array_filter([
                'title' => $config['title'] ?? null,
                'description' => $config['description'] ?? null,
                'start_time' => $config['start_time'] ?? null,
                'end_time' => $config['end_time'] ?? null,
                'location' => $config['location'] ?? null,
            ], fn($v) => $v !== null);

            $calSvc = $this->getCalendarService();
            $event = $calSvc->updateEvent($email, $eventId, $data);
            return array_merge($inputData, [
                'action' => 'calendar_update_event', 'success' => (bool)$event,
                'event_id' => $eventId,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'calendar_update_event', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeCalendarDeleteEvent(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $eventId = (int)($config['event_id'] ?? ($inputData['event_id'] ?? 0));
            if (!$eventId) return ['action' => 'calendar_delete_event', 'success' => false, 'error' => 'No event ID'];

            $calSvc = $this->getCalendarService();
            $ok = $calSvc->deleteEvent($email, $eventId);
            return array_merge($inputData, ['action' => 'calendar_delete_event', 'success' => $ok, 'event_id' => $eventId]);
        } catch (\Throwable $e) {
            return ['action' => 'calendar_delete_event', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeCalendarGetUpcoming(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $calSvc = $this->getCalendarService();
            $limit = (int)($config['limit'] ?? 5);
            $now = date('Y-m-d H:i:s');
            $end = date('Y-m-d H:i:s', strtotime('+30 days'));

            $events = $calSvc->getAllEvents($email, $now, $end);
            $events = array_slice($events, 0, $limit);

            return array_merge($inputData, [
                'action' => 'calendar_get_upcoming', 'success' => true,
                'events' => $events, 'events_count' => count($events),
                'next_event_title' => $events[0]['title'] ?? '',
                'next_event_start' => $events[0]['start_time'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'calendar_get_upcoming', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Drive Actions ─────────────────────────────────────────────────────

    private function getDriveService(string $email): \Webmail\Services\DriveService
    {
        return new \Webmail\Services\DriveService($this->config, $email);
    }

    private function executeDriveListFiles(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $drvSvc = $this->getDriveService($email);
            $folderId = (int)($config['folder_id'] ?? 0) ?: null;
            $files = $drvSvc->getFilesWithDetails($email, $folderId);

            $typeFilter = $config['file_type_filter'] ?? 'all';
            if ($typeFilter !== 'all') {
                $typeMap = [
                    'documents' => ['application/pdf', 'application/msword', 'application/vnd', 'text/'],
                    'images' => ['image/'],
                    'videos' => ['video/'],
                ];
                $prefixes = $typeMap[$typeFilter] ?? [];
                if ($prefixes) {
                    $files = array_values(array_filter($files, function ($f) use ($prefixes) {
                        foreach ($prefixes as $p) {
                            if (str_starts_with($f['mime_type'] ?? '', $p)) return true;
                        }
                        return false;
                    }));
                }
            }
            return array_merge($inputData, [
                'action' => 'drive_list_files', 'success' => true,
                'files' => $files, 'files_count' => count($files),
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'drive_list_files', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeDriveGetFileInfo(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $fileId = (int)($config['file_id'] ?? ($inputData['file_id'] ?? 0));
            if (!$fileId) return ['action' => 'drive_get_file_info', 'success' => false, 'error' => 'No file ID'];

            $drvSvc = $this->getDriveService($email);
            $file = $drvSvc->getFileWithDetails($email, $fileId);
            if (!$file) return ['action' => 'drive_get_file_info', 'success' => false, 'error' => 'File not found'];

            return array_merge($inputData, [
                'action' => 'drive_get_file_info', 'success' => true,
                'file_id' => $file['id'], 'file_name' => $file['name'] ?? $file['original_name'] ?? '',
                'file_type' => $file['mime_type'] ?? '', 'file_size' => $file['size'] ?? 0,
                'updated_at' => $file['updated_at'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'drive_get_file_info', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeDriveCreateFolder(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $name = $config['folder_name'] ?? 'New Folder';
            $parentId = (int)($config['parent_folder_id'] ?? 0) ?: null;

            $drvSvc = $this->getDriveService($email);
            $folder = $drvSvc->createFolder($email, $name, $parentId);
            return array_merge($inputData, [
                'action' => 'drive_create_folder', 'success' => (bool)$folder,
                'folder_id' => $folder['id'] ?? 0, 'folder_name' => $folder['name'] ?? $name,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'drive_create_folder', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Weather Actions ───────────────────────────────────────────────────

    private function executeWeatherGetCurrent(array $config, array $inputData): array
    {
        try {
            $db = $this->getDb();
            $email = $inputData['user_email'] ?? '';
            $stmt = $db->prepare("SELECT api_key_encrypted FROM automation_hub_connections WHERE user_email = ? AND provider = 'openweathermap' LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if (!$row || empty($row['api_key_encrypted'])) return ['action' => 'weather', 'success' => false, 'error' => 'No OpenWeatherMap API key configured'];

            $secret = $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
            $apiKey = \Webmail\Addons\AIAssistant\Services\AIService::decryptApiKey($row['api_key_encrypted'], $secret);
            if (!$apiKey) return ['action' => 'weather', 'success' => false, 'error' => 'Failed to decrypt API key'];

            $location = $config['location'] ?? 'London';
            $units = $config['units'] ?? 'metric';
            $url = 'https://api.openweathermap.org/data/2.5/weather?' . http_build_query(['q' => $location, 'units' => $units, 'appid' => $apiKey]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = json_decode($resp, true);
            if ($code !== 200) return ['action' => 'weather', 'success' => false, 'error' => $data['message'] ?? "HTTP $code"];

            return array_merge($inputData, [
                'action' => 'weather', 'success' => true,
                'weather_city' => $data['name'] ?? $location,
                'weather_temp' => $data['main']['temp'] ?? 0,
                'weather_description' => $data['weather'][0]['description'] ?? '',
                'weather_humidity' => $data['main']['humidity'] ?? 0,
                'weather_wind_speed' => $data['wind']['speed'] ?? 0,
                'weather_icon' => $data['weather'][0]['icon'] ?? '',
                'weather_feels_like' => $data['main']['feels_like'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'weather', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Google Actions ────────────────────────────────────────────────────

    private function getGoogleAccessToken(string $email): ?string
    {
        $oauthSvc = new \Webmail\Services\GoogleOAuthService($this->config);
        $accounts = $oauthSvc->getOAuthAccounts($email);
        if (empty($accounts)) return null;

        $oauthEmail = $accounts[0]['account_email'] ?? '';
        if (!$oauthEmail) return null;

        return $oauthSvc->getValidAccessToken($email, $oauthEmail);
    }

    private function executeGoogleGetContacts(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $token = $this->getGoogleAccessToken($email);
            if (!$token) return array_merge($inputData, ['action' => 'google_get_contacts', 'success' => false, 'error' => 'No Google account connected']);

            $maxResults = (int)($config['max_results'] ?? 500);
            $query = $config['search'] ?? '';
            $fetchAll = (bool)($config['fetch_all'] ?? false);
            $fields = 'names,emailAddresses,phoneNumbers,organizations';

            $contacts = [];
            $pageToken = null;
            $pageSize = min($maxResults, 200);

            do {
                if ($query) {
                    $url = 'https://people.googleapis.com/v1/people:searchContacts?query=' . urlencode($query) . '&readMask=' . $fields . '&pageSize=' . $pageSize;
                } else {
                    $url = 'https://people.googleapis.com/v1/people/me/connections?personFields=' . $fields . '&pageSize=' . $pageSize . '&sortOrder=LAST_NAME_ASCENDING';
                    if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);
                }

                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code !== 200) {
                    $err = json_decode($resp, true);
                    return array_merge($inputData, ['action' => 'google_get_contacts', 'success' => false, 'error' => $err['error']['message'] ?? "HTTP $code"]);
                }

                $data = json_decode($resp, true);
                $items = $data['connections'] ?? $data['results'] ?? [];
                foreach ($items as $person) {
                    $p = $person['person'] ?? $person;
                    $name = $p['names'][0]['displayName'] ?? '';
                    $emailAddr = $p['emailAddresses'][0]['value'] ?? '';
                    $phone = $p['phoneNumbers'][0]['value'] ?? '';
                    $company = $p['organizations'][0]['name'] ?? '';
                    if (!$name && !$emailAddr) continue;
                    $contacts[] = [
                        'name' => $name,
                        'email' => $emailAddr,
                        'phone' => $phone,
                        'company' => $company,
                    ];
                }

                $pageToken = $data['nextPageToken'] ?? null;

                if (!$fetchAll && count($contacts) >= $maxResults) {
                    $contacts = array_slice($contacts, 0, $maxResults);
                    break;
                }
                if ($query) break;
            } while ($pageToken);

            $emails = array_filter(array_map(fn($c) => $c['email'], $contacts));
            $lines = [];
            foreach ($contacts as $i => $c) {
                $line = ($i + 1) . '. ' . ($c['name'] ?: $c['email']);
                if ($c['email']) $line .= ' <' . $c['email'] . '>';
                if ($c['company']) $line .= ' (' . $c['company'] . ')';
                $lines[] = $line;
            }

            return array_merge($inputData, [
                'action' => 'google_get_contacts', 'success' => true,
                'contacts' => $contacts, 'contacts_count' => count($contacts),
                'contacts_list' => $lines ? implode("\n", $lines) : 'No contacts found',
                'contact_emails' => implode(', ', $emails),
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'google_get_contacts', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeGoogleGetContact(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $token = $this->getGoogleAccessToken($email);
            if (!$token) return array_merge($inputData, ['action' => 'google_get_contact', 'success' => false, 'error' => 'No Google account connected']);

            $search = $config['search'] ?? ($inputData['contact_email'] ?? '');
            if (!$search) return array_merge($inputData, ['action' => 'google_get_contact', 'success' => false, 'error' => 'Search term required']);

            $url = 'https://people.googleapis.com/v1/people:searchContacts?query=' . urlencode($search) . '&readMask=names,emailAddresses,phoneNumbers,organizations&pageSize=1';
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
            $resp = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($resp, true);
            $results = $data['results'] ?? [];
            if (empty($results)) return array_merge($inputData, ['action' => 'google_get_contact', 'success' => true, 'found' => false]);

            $p = $results[0]['person'] ?? $results[0];
            return array_merge($inputData, [
                'action' => 'google_get_contact', 'success' => true, 'found' => true,
                'contact_name' => $p['names'][0]['displayName'] ?? '',
                'contact_email' => $p['emailAddresses'][0]['value'] ?? '',
                'contact_phone' => $p['phoneNumbers'][0]['value'] ?? '',
                'contact_company' => $p['organizations'][0]['name'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'google_get_contact', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeGoogleSyncCalendar(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $db = $this->getDb();

            $oauthSvc = new \Webmail\Services\GoogleOAuthService($this->config);
            $accounts = $oauthSvc->getOAuthAccounts($email);
            if (empty($accounts)) return ['action' => 'google_sync_calendar', 'success' => false, 'error' => 'No Google account connected'];

            $googleCalSvc = new \Webmail\Addons\Calendar\Services\GoogleCalendarService($this->config);
            $oauthAccountId = (int)$accounts[0]['id'];

            $calendars = $googleCalSvc->getGoogleCalendars($email, $oauthAccountId);
            if (empty($calendars)) return ['action' => 'google_sync_calendar', 'success' => false, 'error' => 'No Google calendars found'];

            $targetCalId = $config['google_calendar_id'] ?? ($calendars[0]['id'] ?? 'primary');
            $result = $googleCalSvc->syncFromGoogle($email, $oauthAccountId, $targetCalId);

            return array_merge($inputData, [
                'action' => 'google_sync_calendar', 'success' => true,
                'sync_status' => 'completed',
                'events_synced' => ($result['imported'] ?? 0) + ($result['updated'] ?? 0),
                'events_imported' => $result['imported'] ?? 0,
                'events_updated' => $result['updated'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'google_sync_calendar', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Trello Actions ────────────────────────────────────────────────────

    private function getTrelloToken(string $email): ?string
    {
        $db = $this->getDb();
        $stmt = $db->prepare("SELECT access_token_encrypted FROM automation_hub_connections WHERE user_email = ? AND provider = 'trello' LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || empty($row['access_token_encrypted'])) return null;

        $secret = $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
        return \Webmail\Addons\AIAssistant\Services\AIService::decryptApiKey($row['access_token_encrypted'], $secret);
    }

    private function trelloApiGet(string $token, string $path): ?array
    {
        $apiKey = $this->config['trello']['api_key'] ?? '';
        $url = 'https://api.trello.com/1/' . ltrim($path, '/') . (str_contains($path, '?') ? '&' : '?') . 'key=' . $apiKey . '&token=' . $token;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return null;
        return json_decode($resp, true);
    }

    private function executeTrelloGetBoards(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $token = $this->getTrelloToken($email);
            if (!$token) return ['action' => 'trello_get_boards', 'success' => false, 'error' => 'Trello not connected'];

            $boards = $this->trelloApiGet($token, 'members/me/boards?fields=name,url,closed&filter=open');
            if ($boards === null) return ['action' => 'trello_get_boards', 'success' => false, 'error' => 'Failed to fetch Trello boards'];

            $boardId = $config['board_id'] ?? '';
            if ($boardId) {
                $lists = $this->trelloApiGet($token, "boards/$boardId/lists?cards=open&card_fields=name,desc,due,labels");
                return array_merge($inputData, [
                    'action' => 'trello_get_boards', 'success' => true,
                    'boards' => $boards, 'boards_count' => count($boards),
                    'lists' => $lists ?? [], 'lists_count' => count($lists ?? []),
                ]);
            }
            return array_merge($inputData, [
                'action' => 'trello_get_boards', 'success' => true,
                'boards' => $boards, 'boards_count' => count($boards),
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'trello_get_boards', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeTrelloSyncBoards(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $token = $this->getTrelloToken($email);
            if (!$token) return ['action' => 'trello_sync_boards', 'success' => false, 'error' => 'Trello not connected'];

            $boardId = $config['board_id'] ?? '';
            $boards = $boardId
                ? [$this->trelloApiGet($token, "boards/$boardId?fields=name,url")]
                : ($this->trelloApiGet($token, 'members/me/boards?fields=name,url&filter=open') ?? []);

            $db = $this->getDb();
            $boardsSynced = 0;
            $cardsSynced = 0;

            foreach ($boards as $tb) {
                if (!$tb || !($tb['id'] ?? '')) continue;

                // Create or find local board
                $stmt = $db->prepare("SELECT id FROM webmail_boards WHERE name = ? AND created_by = ? LIMIT 1");
                $stmt->execute([$tb['name'], $email]);
                $localBoard = $stmt->fetch();

                if (!$localBoard) {
                    $db->prepare("INSERT INTO webmail_boards (name, created_by, created_at) VALUES (?, ?, NOW())")->execute([$tb['name'] . ' (Trello)', $email]);
                    $localBoardId = (int)$db->lastInsertId();
                } else {
                    $localBoardId = (int)$localBoard['id'];
                }

                // Sync lists and cards
                $lists = $this->trelloApiGet($token, "boards/{$tb['id']}/lists?cards=open&card_fields=name,desc,due,pos") ?? [];
                $listPos = 0;
                foreach ($lists as $tl) {
                    $stmt = $db->prepare("SELECT id FROM webmail_board_lists WHERE board_id = ? AND name = ? LIMIT 1");
                    $stmt->execute([$localBoardId, $tl['name']]);
                    $localList = $stmt->fetch();

                    if (!$localList) {
                        $db->prepare("INSERT INTO webmail_board_lists (board_id, name, position, created_at) VALUES (?, ?, ?, NOW())")->execute([$localBoardId, $tl['name'], $listPos]);
                        $localListId = (int)$db->lastInsertId();
                    } else {
                        $localListId = (int)$localList['id'];
                    }
                    $listPos++;

                    $cards = $tl['cards'] ?? [];
                    $cardPos = 0;
                    foreach ($cards as $tc) {
                        $stmt = $db->prepare("SELECT id FROM webmail_board_cards WHERE list_id = ? AND title = ? LIMIT 1");
                        $stmt->execute([$localListId, $tc['name']]);
                        $exists = $stmt->fetch();
                        if (!$exists) {
                            $db->prepare("INSERT INTO webmail_board_cards (list_id, title, description, position, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
                                ->execute([$localListId, $tc['name'], $tc['desc'] ?? '', $cardPos, $email]);
                            $cardsSynced++;
                        }
                        $cardPos++;
                    }
                }
                $boardsSynced++;
            }

            return array_merge($inputData, [
                'action' => 'trello_sync_boards', 'success' => true,
                'boards_synced' => $boardsSynced, 'cards_synced' => $cardsSynced,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'trello_sync_boards', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Email Campaigns ──────────────────────────────────────────────────

    private function executeCampaignGetStats(array $config, array $inputData): array
    {
        try {
            $db = $this->getDb();
            $userEmail = $inputData['user_email'] ?? '';
            $campaignId = $config['campaign_id'] ?? '';
            $statusFilter = $config['status_filter'] ?? '';

            if ($campaignId) {
                $stmt = $db->prepare("
                    SELECT c.campaign_id, c.subject, c.status, c.total_recipients,
                           c.sent_count, c.failed_count,
                           c.created_at, c.started_at, c.completed_at,
                           c.mailing_list_id,
                           ROUND((c.sent_count / NULLIF(c.total_recipients, 0)) * 100, 1) AS progress_percent,
                           ml.name AS mailing_list_name
                    FROM email_campaigns c
                    LEFT JOIN mailing_lists ml ON ml.id = c.mailing_list_id
                    WHERE c.campaign_id = ? AND c.user_email = ?
                ");
                $stmt->execute([$campaignId, $userEmail]);
            } elseif ($statusFilter) {
                $stmt = $db->prepare("
                    SELECT c.campaign_id, c.subject, c.status, c.total_recipients,
                           c.sent_count, c.failed_count,
                           c.created_at, c.started_at, c.completed_at,
                           c.mailing_list_id,
                           ROUND((c.sent_count / NULLIF(c.total_recipients, 0)) * 100, 1) AS progress_percent,
                           ml.name AS mailing_list_name
                    FROM email_campaigns c
                    LEFT JOIN mailing_lists ml ON ml.id = c.mailing_list_id
                    WHERE c.user_email = ? AND c.status = ?
                    ORDER BY c.created_at DESC LIMIT 1
                ");
                $stmt->execute([$userEmail, $statusFilter]);
            } else {
                $stmt = $db->prepare("
                    SELECT c.campaign_id, c.subject, c.status, c.total_recipients,
                           c.sent_count, c.failed_count,
                           c.created_at, c.started_at, c.completed_at,
                           c.mailing_list_id,
                           ROUND((c.sent_count / NULLIF(c.total_recipients, 0)) * 100, 1) AS progress_percent,
                           ml.name AS mailing_list_name
                    FROM email_campaigns c
                    LEFT JOIN mailing_lists ml ON ml.id = c.mailing_list_id
                    WHERE c.user_email = ?
                    ORDER BY c.created_at DESC LIMIT 1
                ");
                $stmt->execute([$userEmail]);
            }

            $campaign = $stmt->fetch();
            if (!$campaign) {
                return array_merge($inputData, [
                    'action' => 'campaign_get_stats', 'success' => false,
                    'error' => 'No campaign found',
                ]);
            }

            $cid = $campaign['campaign_id'];

            $openStmt = $db->prepare("
                SELECT COUNT(DISTINCT recipient_email) AS opened
                FROM email_queue
                WHERE campaign_id = ? AND status = 'sent'
                  AND opened_at IS NOT NULL
            ");
            $openStmt->execute([$cid]);
            $openCount = (int)$openStmt->fetchColumn();

            $clickStmt = $db->prepare("
                SELECT COUNT(DISTINCT recipient_email) AS clicked
                FROM email_queue
                WHERE campaign_id = ? AND status = 'sent'
                  AND clicked_at IS NOT NULL
            ");
            $clickStmt->execute([$cid]);
            $clickCount = (int)$clickStmt->fetchColumn();

            $bounceStmt = $db->prepare("
                SELECT COUNT(*) FROM email_queue
                WHERE campaign_id = ? AND bounced = 1
            ");
            $bounceStmt->execute([$cid]);
            $bounceCount = (int)$bounceStmt->fetchColumn();

            $unsubStmt = $db->prepare("
                SELECT COUNT(*) FROM email_queue
                WHERE campaign_id = ? AND unsubscribed = 1
            ");
            $unsubStmt->execute([$cid]);
            $unsubCount = (int)$unsubStmt->fetchColumn();

            $sent = (int)$campaign['sent_count'];
            $openRate = $sent > 0 ? round($openCount / $sent * 100, 1) : 0;
            $clickRate = $sent > 0 ? round($clickCount / $sent * 100, 1) : 0;

            return array_merge($inputData, [
                'action' => 'campaign_get_stats',
                'success' => true,
                'campaign_id' => $cid,
                'campaign_subject' => $campaign['subject'] ?? '',
                'campaign_status' => $campaign['status'],
                'total_recipients' => (int)$campaign['total_recipients'],
                'sent_count' => $sent,
                'failed_count' => (int)$campaign['failed_count'],
                'open_count' => $openCount,
                'open_rate' => $openRate,
                'click_count' => $clickCount,
                'click_rate' => $clickRate,
                'bounce_count' => $bounceCount,
                'unsubscribe_count' => $unsubCount,
                'progress_percent' => (float)($campaign['progress_percent'] ?? 0),
                'mailing_list_name' => $campaign['mailing_list_name'] ?? '',
                'created_at' => $campaign['created_at'],
                'started_at' => $campaign['started_at'],
                'completed_at' => $campaign['completed_at'],
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'campaign_get_stats', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeCampaignSend(array $config, array $inputData, bool $isTest): array
    {
        try {
            $userEmail = $inputData['user_email'] ?? '';
            $sendMode = $config['send_mode'] ?? 'draft';

            $queueService = new \Webmail\Addons\EmailMarketing\Services\EmailQueueService($this->config);

            if ($sendMode === 'draft') {
                $campaignId = $config['campaign_id'] ?? ($inputData['campaign_id'] ?? '');
                if (empty($campaignId)) {
                    return array_merge($inputData, ['action' => 'campaign_send', 'success' => false, 'error' => 'No campaign selected']);
                }

                if ($isTest) {
                    return array_merge($inputData, [
                        'action' => 'campaign_send', 'success' => true,
                        'campaign_id' => $campaignId, 'test_mode' => true,
                        'message' => 'Test mode: draft campaign would be finalized and sent',
                    ]);
                }

                $result = $queueService->finalizeDraftCampaign($campaignId, $userEmail);
                return array_merge($inputData, [
                    'action' => 'campaign_send',
                    'success' => $result['success'] ?? false,
                    'campaign_id' => $campaignId,
                    'total_recipients' => $result['total_recipients'] ?? 0,
                    'skipped_unsubscribed' => $result['skipped_unsubscribed'] ?? 0,
                    'error' => $result['error'] ?? null,
                ]);
            }

            // send_mode === 'new': create and send a new campaign from a mailing list
            $mailingListId = (int)($config['mailing_list_id'] ?? 0);
            $subject = $config['subject'] ?? '';
            $bodyHtml = $config['body_html'] ?? '';

            if (!$mailingListId || !$subject || !$bodyHtml) {
                return array_merge($inputData, [
                    'action' => 'campaign_send', 'success' => false,
                    'error' => 'Mailing list, subject, and body are required for a new campaign',
                ]);
            }

            if ($isTest) {
                return array_merge($inputData, [
                    'action' => 'campaign_send', 'success' => true,
                    'test_mode' => true,
                    'message' => "Test mode: new campaign '{$subject}' would be created and sent to mailing list #{$mailingListId}",
                ]);
            }

            $mlService = new \Webmail\Addons\EmailMarketing\Services\MailingListService($this->config);
            $contacts = $mlService->getContacts($mailingListId, $userEmail);

            if (empty($contacts)) {
                return array_merge($inputData, ['action' => 'campaign_send', 'success' => false, 'error' => 'Mailing list has no contacts']);
            }

            $recipients = array_map(fn($c) => [
                'email' => $c['email'],
                'name' => $c['name'] ?? '',
                'data' => $c,
            ], $contacts);

            $result = $queueService->createCampaign(
                $userEmail,
                $recipients,
                $subject,
                $bodyHtml,
                strip_tags($bodyHtml),
                $config['from_name'] ?? '',
                [],
                null,
                null,
                true,
                'automation',
                (string)($inputData['workflow_id'] ?? ''),
            );

            return array_merge($inputData, [
                'action' => 'campaign_send',
                'success' => $result['success'] ?? false,
                'campaign_id' => $result['campaign_id'] ?? null,
                'total_recipients' => count($recipients),
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'campaign_send', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── SQL Query ────────────────────────────────────────────────────────

    private const SQL_ALLOWED_TABLES = [
        'clients', 'client_contacts', 'crm_invoices', 'crm_invoice_items',
        'crm_deals', 'email_campaigns', 'email_queue', 'mailing_lists',
        'mailing_list_contacts', 'organization_colleagues', 'colleague_groups',
        'mood_boards', 'crm_sequences', 'crm_sequence_enrollments',
        'webmail_board_cards', 'webmail_boards', 'drive_files', 'drive_folders',
        'calendar_events', 'crm_expenses', 'crm_invoice_payments',
    ];

    private function executeSqlQuery(array $config, array $inputData): array
    {
        try {
            $db = $this->getDb();
            $queryType = $config['query_type'] ?? 'table';

            if ($queryType === 'custom') {
                $customSql = trim($config['custom_sql'] ?? '');
                if (empty($customSql)) {
                    return array_merge($inputData, ['action' => 'sql_query', 'success' => false, 'error' => 'Custom SQL query is empty']);
                }

                // Replace {var} placeholders with named PDO parameters to prevent SQL injection
                $varMap = $this->buildVarMap($inputData);
                $bindParams = [];
                foreach ($varMap as $key => $val) {
                    if (is_string($val) || is_numeric($val)) {
                        $paramName = ':var_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                        if (str_contains($customSql, '{' . $key . '}')) {
                            $customSql = str_replace('{' . $key . '}', $paramName, $customSql);
                            $bindParams[$paramName] = (string)$val;
                        }
                    }
                }

                $normalized = strtoupper(preg_replace('/\s+/', ' ', trim($customSql)));
                if (!str_starts_with($normalized, 'SELECT ')) {
                    return array_merge($inputData, ['action' => 'sql_query', 'success' => false, 'error' => 'Only SELECT queries are allowed']);
                }
                $forbidden = ['INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER ', 'TRUNCATE ', 'CREATE ', 'GRANT ', 'REVOKE '];
                foreach ($forbidden as $kw) {
                    if (str_contains($normalized, $kw)) {
                        return array_merge($inputData, ['action' => 'sql_query', 'success' => false, 'error' => "Forbidden keyword: " . trim($kw)]);
                    }
                }

                $hasAllowedTable = false;
                foreach (self::SQL_ALLOWED_TABLES as $t) {
                    if (str_contains($normalized, strtoupper($t))) {
                        $hasAllowedTable = true;
                        break;
                    }
                }
                if (!$hasAllowedTable) {
                    return array_merge($inputData, ['action' => 'sql_query', 'success' => false, 'error' => 'Query must reference an allowed table']);
                }

                if (!preg_match('/LIMIT\s+\d+/i', $customSql)) {
                    $customSql .= ' LIMIT 500';
                }

                $stmt = $db->prepare($customSql);
                $stmt->execute($bindParams);
                $rows = $stmt->fetchAll();

                $result = array_merge($inputData, [
                    'action' => 'sql_query', 'success' => true,
                    'rows' => $rows, 'row_count' => count($rows),
                    'query' => $customSql,
                ]);
                if (!empty($rows) && count($rows) === 1) {
                    foreach ($rows[0] as $key => $val) {
                        if (!is_numeric($key)) $result[$key] = $val;
                    }
                }
                return $result;
            }

            $table = $config['table'] ?? '';
            if (!in_array($table, self::SQL_ALLOWED_TABLES, true)) {
                return array_merge($inputData, ['action' => 'sql_query', 'success' => false, 'error' => "Table '{$table}' is not allowed"]);
            }

            $columns = $config['columns'] ?? '*';
            if (is_array($columns)) {
                $columns = implode(', ', array_map(fn($c) => '`' . preg_replace('/[^a-zA-Z0-9_]/', '', $c) . '`', $columns));
            } elseif (is_string($columns) && $columns !== '*' && !empty(trim($columns))) {
                $parts = array_map('trim', explode(',', $columns));
                $columns = implode(', ', array_map(fn($c) => '`' . preg_replace('/[^a-zA-Z0-9_]/', '', $c) . '`', $parts));
            }
            if (empty($columns) || $columns === '*') $columns = '*';

            $sql = "SELECT {$columns} FROM `{$table}`";
            $params = [];

            $conditions = $config['conditions'] ?? [];
            if (!empty($conditions) && is_array($conditions)) {
                $wheres = [];
                foreach ($conditions as $cond) {
                    $field = preg_replace('/[^a-zA-Z0-9_.]/', '', $cond['field'] ?? '');
                    $op = $cond['operator'] ?? '=';
                    $val = $cond['value'] ?? '';
                    if (empty($field)) continue;
                    $allowedOps = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL'];
                    if (!in_array(strtoupper($op), $allowedOps, true)) $op = '=';
                    if (in_array(strtoupper($op), ['IS NULL', 'IS NOT NULL'])) {
                        $wheres[] = "`{$field}` {$op}";
                    } else {
                        $wheres[] = "`{$field}` {$op} ?";
                        $params[] = $val;
                    }
                }
                if ($wheres) $sql .= ' WHERE ' . implode(' AND ', $wheres);
            }

            $orderBy = $config['order_by'] ?? '';
            if ($orderBy) {
                $orderField = preg_replace('/[^a-zA-Z0-9_]/', '', $orderBy);
                $orderDir = strtoupper($config['order_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
                $sql .= " ORDER BY `{$orderField}` {$orderDir}";
            }

            $limit = min((int)($config['limit'] ?? 100), 1000);
            $sql .= " LIMIT {$limit}";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            return array_merge($inputData, [
                'action' => 'sql_query', 'success' => true,
                'rows' => $rows, 'row_count' => count($rows),
                'table' => $table, 'query' => $sql,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'sql_query', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Lists ────────────────────────────────────────────────────────────

    private function executeListGetMailingList(array $config, array $inputData): array
    {
        try {
            $listId = (int)($config['list_id'] ?? ($inputData['list_id'] ?? 0));
            $userEmail = $inputData['user_email'] ?? '';
            if (!$listId) {
                return array_merge($inputData, ['action' => 'list_get_mailing_list', 'success' => false, 'error' => 'No mailing list selected']);
            }

            $mlService = new \Webmail\Addons\EmailMarketing\Services\MailingListService($this->config);
            $contacts = $mlService->getContacts($listId, $userEmail);

            $db = $this->getDb();
            $stmt = $db->prepare("SELECT name FROM mailing_lists WHERE id = ?");
            $stmt->execute([$listId]);
            $listName = $stmt->fetchColumn() ?: "List #{$listId}";

            $emails = array_map(fn($c) => $c['email'] ?? '', $contacts);
            $lines = [];
            foreach ($contacts as $i => $c) {
                $lines[] = ($i + 1) . '. ' . ($c['name'] ?? '') . ' <' . ($c['email'] ?? '') . '>';
            }

            return array_merge($inputData, [
                'action' => 'list_get_mailing_list', 'success' => true,
                'contacts' => $contacts, 'contacts_count' => count($contacts),
                'contacts_list' => $lines ? implode("\n", $lines) : 'No contacts',
                'list_name' => $listName,
                'contact_emails' => implode(', ', array_filter($emails)),
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'list_get_mailing_list', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeListGetTeam(array $config, array $inputData): array
    {
        try {
            $groupId = (int)($config['group_id'] ?? 0);
            $userEmail = $inputData['user_email'] ?? '';
            $domain = explode('@', $userEmail)[1] ?? '';

            $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);

            if ($groupId) {
                $members = $colleagueService->getGroupMembers($groupId);
                $group = $colleagueService->getGroupWithMembers($groupId);
                $groupName = $group['name'] ?? "Group #{$groupId}";
            } else {
                $members = $colleagueService->getColleagues($domain);
                $groupName = 'All Colleagues';
            }

            $emails = array_map(fn($m) => $m['email'] ?? '', $members);
            $lines = [];
            foreach ($members as $i => $m) {
                $lines[] = ($i + 1) . '. ' . ($m['display_name'] ?? $m['email'] ?? '');
            }

            return array_merge($inputData, [
                'action' => 'list_get_team', 'success' => true,
                'members' => $members, 'members_count' => count($members),
                'members_list' => $lines ? implode("\n", $lines) : 'No members',
                'group_name' => $groupName,
                'member_emails' => implode(', ', array_filter($emails)),
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'list_get_team', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeListAddContact(array $config, array $inputData, bool $isTest): array
    {
        try {
            $listId = (int)($config['list_id'] ?? 0);
            $email = $config['email'] ?? ($inputData['contact_email'] ?? '');
            $name = $config['name'] ?? ($inputData['contact_name'] ?? '');
            $userEmail = $inputData['user_email'] ?? '';

            if (!$listId || !$email) {
                return array_merge($inputData, ['action' => 'list_add_contact', 'success' => false, 'error' => 'Mailing list and email are required']);
            }

            if ($isTest) {
                return array_merge($inputData, ['action' => 'list_add_contact', 'success' => true, 'test_mode' => true, 'message' => "Would add {$email} to list #{$listId}"]);
            }

            $mlService = new \Webmail\Addons\EmailMarketing\Services\MailingListService($this->config);
            $result = $mlService->addContact($listId, $userEmail, [
                'email' => $email, 'name' => $name,
                'phone' => $config['phone'] ?? '', 'company' => $config['company'] ?? '',
            ]);

            $db = $this->getDb();
            $stmt = $db->prepare("SELECT name FROM mailing_lists WHERE id = ?");
            $stmt->execute([$listId]);
            $listName = $stmt->fetchColumn() ?: '';

            return array_merge($inputData, [
                'action' => 'list_add_contact', 'success' => $result['success'] ?? true,
                'contact_id' => $result['contact_id'] ?? null,
                'contact_email' => $email, 'list_name' => $listName,
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'list_add_contact', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeListRemoveContact(array $config, array $inputData, bool $isTest): array
    {
        try {
            $listId = (int)($config['list_id'] ?? 0);
            $email = $config['email'] ?? ($inputData['contact_email'] ?? '');
            $userEmail = $inputData['user_email'] ?? '';

            if (!$listId || !$email) {
                return array_merge($inputData, ['action' => 'list_remove_contact', 'success' => false, 'error' => 'Mailing list and email are required']);
            }

            if ($isTest) {
                return array_merge($inputData, ['action' => 'list_remove_contact', 'success' => true, 'test_mode' => true, 'message' => "Would remove {$email} from list #{$listId}"]);
            }

            $db = $this->getDb();
            $stmt = $db->prepare("SELECT id FROM mailing_list_contacts WHERE list_id = ? AND email = ?");
            $stmt->execute([$listId, $email]);
            $contactId = $stmt->fetchColumn();

            if (!$contactId) {
                return array_merge($inputData, ['action' => 'list_remove_contact', 'success' => false, 'error' => "Contact {$email} not found in list"]);
            }

            $mlService = new \Webmail\Addons\EmailMarketing\Services\MailingListService($this->config);
            $mlService->deleteContact((int)$contactId, $userEmail);

            $stmt2 = $db->prepare("SELECT name FROM mailing_lists WHERE id = ?");
            $stmt2->execute([$listId]);
            $listName = $stmt2->fetchColumn() ?: '';

            return array_merge($inputData, [
                'action' => 'list_remove_contact', 'success' => true,
                'removed_email' => $email, 'list_name' => $listName,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'list_remove_contact', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Sequences ────────────────────────────────────────────────────────

    private function executeSequenceStart(array $config, array $inputData, bool $isTest): array
    {
        try {
            $sequenceId = (int)($config['sequence_id'] ?? 0);
            $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));
            $dealId = (int)($config['deal_id'] ?? ($inputData['deal_id'] ?? 0));
            $userEmail = $inputData['user_email'] ?? '';

            if (!$sequenceId) {
                return array_merge($inputData, ['action' => 'sequence_start', 'success' => false, 'error' => 'No sequence selected']);
            }

            $db = $this->getDb();
            $stmt = $db->prepare("SELECT name FROM crm_sequences WHERE id = ?");
            $stmt->execute([$sequenceId]);
            $seqName = $stmt->fetchColumn() ?: '';

            if ($isTest) {
                return array_merge($inputData, ['action' => 'sequence_start', 'success' => true, 'test_mode' => true, 'sequence_name' => $seqName, 'message' => "Would enroll client #{$clientId} in sequence '{$seqName}'"]);
            }

            $seqService = new \Webmail\Addons\CrmPro\Services\CrmSequenceService($this->config);
            $result = $seqService->enrollInSequence($sequenceId, $userEmail, $clientId ?: null, $dealId ?: null);

            return array_merge($inputData, [
                'action' => 'sequence_start',
                'success' => $result['success'] ?? false,
                'enrollment_id' => $result['enrollment_id'] ?? null,
                'sequence_name' => $seqName,
                'client_id' => $clientId,
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'sequence_start', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeSequenceStop(array $config, array $inputData, bool $isTest): array
    {
        try {
            $enrollmentId = (int)($config['enrollment_id'] ?? ($inputData['enrollment_id'] ?? 0));
            $userEmail = $inputData['user_email'] ?? '';

            if (!$enrollmentId) {
                $sequenceId = (int)($config['sequence_id'] ?? 0);
                $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));
                if ($sequenceId && $clientId) {
                    $db = $this->getDb();
                    $stmt = $db->prepare("SELECT id FROM crm_sequence_enrollments WHERE sequence_id = ? AND client_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$sequenceId, $clientId]);
                    $enrollmentId = (int)$stmt->fetchColumn();
                }
            }

            if (!$enrollmentId) {
                return array_merge($inputData, ['action' => 'sequence_stop', 'success' => false, 'error' => 'No enrollment found']);
            }

            if ($isTest) {
                return array_merge($inputData, ['action' => 'sequence_stop', 'success' => true, 'test_mode' => true, 'enrollment_id' => $enrollmentId]);
            }

            $seqService = new \Webmail\Addons\CrmPro\Services\CrmSequenceService($this->config);
            $result = $seqService->cancelEnrollment($enrollmentId, $userEmail);

            return array_merge($inputData, [
                'action' => 'sequence_stop',
                'success' => $result['success'] ?? false,
                'enrollment_id' => $enrollmentId,
                'status' => 'cancelled',
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'sequence_stop', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeSequenceGetStatus(array $config, array $inputData): array
    {
        try {
            $db = $this->getDb();
            $enrollmentId = (int)($config['enrollment_id'] ?? ($inputData['enrollment_id'] ?? 0));

            if (!$enrollmentId) {
                $sequenceId = (int)($config['sequence_id'] ?? 0);
                $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));
                if ($sequenceId && $clientId) {
                    $stmt = $db->prepare("SELECT id FROM crm_sequence_enrollments WHERE sequence_id = ? AND client_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$sequenceId, $clientId]);
                    $enrollmentId = (int)$stmt->fetchColumn();
                }
            }

            if (!$enrollmentId) {
                return array_merge($inputData, ['action' => 'sequence_get_status', 'success' => false, 'error' => 'No enrollment found']);
            }

            $stmt = $db->prepare("
                SELECT e.*, s.name AS sequence_name, s.steps
                FROM crm_sequence_enrollments e
                JOIN crm_sequences s ON e.sequence_id = s.id
                WHERE e.id = ?
            ");
            $stmt->execute([$enrollmentId]);
            $enrollment = $stmt->fetch();

            if (!$enrollment) {
                return array_merge($inputData, ['action' => 'sequence_get_status', 'success' => false, 'error' => 'Enrollment not found']);
            }

            $steps = json_decode($enrollment['steps'] ?? '[]', true);

            return array_merge($inputData, [
                'action' => 'sequence_get_status', 'success' => true,
                'enrollment_id' => $enrollmentId,
                'sequence_name' => $enrollment['sequence_name'],
                'status' => $enrollment['status'],
                'current_step' => (int)$enrollment['current_step'],
                'total_steps' => count($steps),
                'next_run_at' => $enrollment['next_run_at'],
                'started_at' => $enrollment['started_at'],
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'sequence_get_status', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Moodboards ───────────────────────────────────────────────────────

    private function executeMoodboardGetInfo(array $config, array $inputData): array
    {
        try {
            $db = $this->getDb();
            $boardId = (int)($config['board_id'] ?? ($inputData['moodboard_id'] ?? 0));
            $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));

            if (!$boardId && $clientId) {
                $stmt = $db->prepare("SELECT mb.id FROM mood_boards mb JOIN mood_board_client_links mcl ON mb.id = mcl.mood_board_id WHERE mcl.client_id = ? AND mb.archived = 0 ORDER BY mb.updated_at DESC LIMIT 1");
                $stmt->execute([$clientId]);
                $boardId = (int)$stmt->fetchColumn();
            }

            if (!$boardId) {
                return array_merge($inputData, ['action' => 'moodboard_get_info', 'success' => false, 'error' => 'No moodboard found']);
            }

            $stmt = $db->prepare("
                SELECT mb.*, COUNT(mbi.id) AS item_count
                FROM mood_boards mb
                LEFT JOIN mood_board_items mbi ON mbi.board_id = mb.id
                WHERE mb.id = ?
                GROUP BY mb.id
            ");
            $stmt->execute([$boardId]);
            $board = $stmt->fetch();

            if (!$board) {
                return array_merge($inputData, ['action' => 'moodboard_get_info', 'success' => false, 'error' => 'Moodboard not found']);
            }

            return array_merge($inputData, [
                'action' => 'moodboard_get_info', 'success' => true,
                'moodboard_id' => (int)$board['id'],
                'moodboard_name' => $board['name'] ?? '',
                'client_id' => (int)($board['client_id'] ?? 0),
                'item_count' => (int)$board['item_count'],
                'archived' => (bool)$board['archived'],
                'created_at' => $board['created_at'],
                'updated_at' => $board['updated_at'],
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'moodboard_get_info', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeMoodboardList(array $config, array $inputData): array
    {
        try {
            $db = $this->getDb();
            $userEmail = $inputData['user_email'] ?? '';
            $clientId = (int)($config['client_id'] ?? ($inputData['client_id'] ?? 0));
            $showArchived = (bool)($config['show_archived'] ?? false);
            $limit = min((int)($config['limit'] ?? 20), 100);

            $sql = "SELECT mb.id, mb.name, mb.client_id, mb.archived, mb.created_at, mb.updated_at, COUNT(mbi.id) AS item_count FROM mood_boards mb LEFT JOIN mood_board_items mbi ON mbi.board_id = mb.id WHERE mb.owner_email = ?";
            $params = [$userEmail];

            if ($clientId) {
                $sql .= " AND mb.id IN (SELECT mood_board_id FROM mood_board_client_links WHERE client_id = ?)";
                $params[] = $clientId;
            }
            if (!$showArchived) {
                $sql .= " AND mb.archived = 0";
            }
            $sql .= " GROUP BY mb.id ORDER BY mb.updated_at DESC LIMIT {$limit}";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $boards = $stmt->fetchAll();

            $lines = [];
            foreach ($boards as $i => $b) {
                $lines[] = ($i + 1) . '. ' . ($b['name'] ?? 'Untitled') . ' (' . $b['item_count'] . ' items)';
            }

            return array_merge($inputData, [
                'action' => 'moodboard_list', 'success' => true,
                'moodboards' => $boards, 'moodboard_count' => count($boards),
                'moodboards_list' => $lines ? implode("\n", $lines) : 'No moodboards',
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'moodboard_list', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeMoodboardShare(array $config, array $inputData, bool $isTest): array
    {
        try {
            $boardId = (int)($config['board_id'] ?? ($inputData['moodboard_id'] ?? 0));
            $userEmail = $inputData['user_email'] ?? '';
            $recipientEmail = $config['recipient_email'] ?? ($inputData['client_email'] ?? '');

            if (!$boardId) {
                return array_merge($inputData, ['action' => 'moodboard_share', 'success' => false, 'error' => 'No moodboard selected']);
            }

            $db = $this->getDb();
            $stmt = $db->prepare("SELECT name FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $boardName = $stmt->fetchColumn() ?: 'Untitled';

            $moodBoardService = new \Webmail\Addons\Moodboards\Services\MoodBoardService($this->config);
            $shareResult = $moodBoardService->createShareLink($boardId, $userEmail);
            $shareToken = $shareResult['token'] ?? ($shareResult['share_token'] ?? '');
            $shareUrl = "https://flowone.pro/mood-boards/share/{$shareToken}";

            if ($isTest) {
                return array_merge($inputData, ['action' => 'moodboard_share', 'success' => true, 'test_mode' => true, 'share_url' => $shareUrl, 'moodboard_name' => $boardName]);
            }

            $sentTo = '';
            if (!empty($recipientEmail)) {
                $emailQueue = new \Webmail\Services\EmailQueueService($this->config);
                $emailQueue->enqueue([
                    'to' => $recipientEmail,
                    'subject' => "Moodboard: {$boardName}",
                    'html_body' => "<p>A moodboard has been shared with you:</p><p><strong>{$boardName}</strong></p><p><a href=\"{$shareUrl}\">View Moodboard</a></p>",
                    'from_email' => $userEmail,
                ]);
                $sentTo = $recipientEmail;
            }

            return array_merge($inputData, [
                'action' => 'moodboard_share', 'success' => true,
                'share_url' => $shareUrl, 'share_token' => $shareToken,
                'moodboard_name' => $boardName, 'sent_to' => $sentTo,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'moodboard_share', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Invoice / Billingo ───────────────────────────────────────────────

    private function executeInvoicePushBillingo(array $config, array $inputData, bool $isTest): array
    {
        try {
            $invoiceId = (int)($config['invoice_id'] ?? ($inputData['invoice_id'] ?? 0));
            $userEmail = $inputData['user_email'] ?? '';

            if (!$invoiceId) {
                return array_merge($inputData, ['action' => 'invoice_push_billingo', 'success' => false, 'error' => 'No invoice selected']);
            }

            if ($isTest) {
                return array_merge($inputData, ['action' => 'invoice_push_billingo', 'success' => true, 'test_mode' => true, 'invoice_id' => $invoiceId, 'message' => 'Would push invoice to billing provider']);
            }

            $billingService = new \Webmail\Services\Billing\BillingService($this->config);
            $result = $billingService->pushToProvider($invoiceId, $userEmail);

            return array_merge($inputData, [
                'action' => 'invoice_push_billingo',
                'success' => $result['success'] ?? false,
                'invoice_id' => $invoiceId,
                'external_invoice_id' => $result['external_invoice_id'] ?? null,
                'external_invoice_url' => $result['external_invoice_url'] ?? null,
                'external_pdf_url' => $result['external_pdf_url'] ?? null,
                'provider' => $result['provider'] ?? 'billingo',
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'invoice_push_billingo', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeInvoiceDownloadPdf(array $config, array $inputData, bool $isTest): array
    {
        try {
            $invoiceId = (int)($config['invoice_id'] ?? ($inputData['invoice_id'] ?? 0));
            $folderId = (int)($config['folder_id'] ?? 0);
            $userEmail = $inputData['user_email'] ?? '';

            if (!$invoiceId) {
                return array_merge($inputData, ['action' => 'invoice_download_pdf', 'success' => false, 'error' => 'No invoice selected']);
            }

            if ($isTest) {
                return array_merge($inputData, ['action' => 'invoice_download_pdf', 'success' => true, 'test_mode' => true, 'invoice_id' => $invoiceId]);
            }

            $billingService = new \Webmail\Services\Billing\BillingService($this->config);
            $pdfResult = $billingService->downloadPdf($invoiceId, $userEmail);

            if (!($pdfResult['success'] ?? false)) {
                return array_merge($inputData, ['action' => 'invoice_download_pdf', 'success' => false, 'error' => $pdfResult['error'] ?? 'Failed to download PDF']);
            }

            $db = $this->getDb();
            $stmt = $db->prepare("SELECT invoice_number FROM crm_invoices WHERE id = ?");
            $stmt->execute([$invoiceId]);
            $invoiceNumber = $stmt->fetchColumn() ?: "invoice-{$invoiceId}";
            $fileName = $invoiceNumber . '.pdf';

            $fileId = null;
            $folderName = '';
            if ($folderId && !empty($pdfResult['pdf_path'])) {
                $stmt2 = $db->prepare("SELECT name FROM drive_folders WHERE id = ?");
                $stmt2->execute([$folderId]);
                $folderName = $stmt2->fetchColumn() ?: '';

                $pdfContent = file_get_contents($pdfResult['pdf_path']);
                if ($pdfContent) {
                    $storagePath = dirname(__DIR__, 4) . '/storage/drive/' . $userEmail;
                    if (!is_dir($storagePath)) mkdir($storagePath, 0755, true);
                    $diskName = uniqid() . '_' . $fileName;
                    file_put_contents($storagePath . '/' . $diskName, $pdfContent);

                    $ins = $db->prepare("INSERT INTO drive_files (user_email, folder_id, name, disk_name, mime_type, size, created_at, updated_at) VALUES (?, ?, ?, ?, 'application/pdf', ?, NOW(), NOW())");
                    $ins->execute([$userEmail, $folderId, $fileName, $diskName, strlen($pdfContent)]);
                    $fileId = (int)$db->lastInsertId();
                }
            }

            return array_merge($inputData, [
                'action' => 'invoice_download_pdf', 'success' => true,
                'invoice_id' => $invoiceId, 'file_id' => $fileId,
                'file_name' => $fileName, 'folder_name' => $folderName,
                'download_url' => $pdfResult['pdf_url'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'invoice_download_pdf', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeInvoiceSendToClient(array $config, array $inputData, bool $isTest): array
    {
        try {
            $invoiceId = (int)($config['invoice_id'] ?? ($inputData['invoice_id'] ?? 0));
            $userEmail = $inputData['user_email'] ?? '';
            $recipientType = $config['recipient_type'] ?? 'client';

            if (!$invoiceId) {
                return array_merge($inputData, ['action' => 'invoice_send_to_client', 'success' => false, 'error' => 'No invoice selected']);
            }

            $db = $this->getDb();
            $stmt = $db->prepare("SELECT i.*, c.display_name AS client_name FROM crm_invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch();
            if (!$invoice) {
                return array_merge($inputData, ['action' => 'invoice_send_to_client', 'success' => false, 'error' => 'Invoice not found']);
            }

            $recipientEmail = '';
            if ($recipientType === 'custom') {
                $recipientEmail = $config['custom_email'] ?? '';
            } elseif ($recipientType === 'upstream') {
                $recipientEmail = $inputData['contact_email'] ?? ($inputData['client_email'] ?? '');
            } else {
                $ccStmt = $db->prepare("SELECT email FROM client_contacts WHERE client_id = ? ORDER BY id LIMIT 1");
                $ccStmt->execute([$invoice['client_id']]);
                $recipientEmail = $ccStmt->fetchColumn() ?: '';
            }

            if (empty($recipientEmail)) {
                return array_merge($inputData, ['action' => 'invoice_send_to_client', 'success' => false, 'error' => 'No recipient email found']);
            }

            if ($isTest) {
                return array_merge($inputData, ['action' => 'invoice_send_to_client', 'success' => true, 'test_mode' => true, 'sent_to' => $recipientEmail, 'invoice_number' => $invoice['invoice_number'] ?? '']);
            }

            $subject = $config['subject'] ?? ('Invoice ' . ($invoice['invoice_number'] ?? "#{$invoiceId}"));
            $body = $config['body'] ?? ("<p>Please find attached invoice " . ($invoice['invoice_number'] ?? "#{$invoiceId}") . " for " . ($invoice['client_name'] ?? 'your review') . ".</p>");

            $billingService = new \Webmail\Services\Billing\BillingService($this->config);
            $result = $billingService->sendToClient($invoiceId, $userEmail, $recipientEmail, $subject, $body);

            return array_merge($inputData, [
                'action' => 'invoice_send_to_client',
                'success' => $result['success'] ?? false,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoice['invoice_number'] ?? '',
                'sent_to' => $recipientEmail,
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'invoice_send_to_client', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function executeInvoiceGetStatus(array $config, array $inputData): array
    {
        try {
            $invoiceId = (int)($config['invoice_id'] ?? ($inputData['invoice_id'] ?? 0));
            $userEmail = $inputData['user_email'] ?? '';

            if (!$invoiceId) {
                return array_merge($inputData, ['action' => 'invoice_get_status', 'success' => false, 'error' => 'No invoice selected']);
            }

            $db = $this->getDb();

            if (!empty($config['sync_from_provider'])) {
                try {
                    $billingService = new \Webmail\Services\Billing\BillingService($this->config);
                    $billingService->syncStatus($invoiceId, $userEmail);
                } catch (\Throwable $e) {
                    error_log("AutomationHub: invoice status sync failed: " . $e->getMessage());
                }
            }

            $stmt = $db->prepare("
                SELECT i.id, i.invoice_number, i.status, i.total, i.paid_amount, i.currency,
                       i.due_date, i.billing_provider, i.external_invoice_id, i.external_invoice_url,
                       c.display_name AS client_name
                FROM crm_invoices i
                LEFT JOIN clients c ON i.client_id = c.id
                WHERE i.id = ?
            ");
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch();

            if (!$invoice) {
                return array_merge($inputData, ['action' => 'invoice_get_status', 'success' => false, 'error' => 'Invoice not found']);
            }

            $pmtStmt = $db->prepare("SELECT MAX(payment_date) AS last_payment FROM crm_invoice_payments WHERE invoice_id = ?");
            $pmtStmt->execute([$invoiceId]);
            $lastPayment = $pmtStmt->fetchColumn();

            return array_merge($inputData, [
                'action' => 'invoice_get_status', 'success' => true,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoice['invoice_number'] ?? '',
                'status' => $invoice['status'],
                'total' => (float)$invoice['total'],
                'paid_amount' => (float)$invoice['paid_amount'],
                'currency' => $invoice['currency'] ?? 'EUR',
                'due_date' => $invoice['due_date'],
                'client_name' => $invoice['client_name'] ?? '',
                'external_status' => $invoice['billing_provider'] ? 'synced' : 'local_only',
                'external_invoice_url' => $invoice['external_invoice_url'] ?? '',
                'payment_date' => $lastPayment,
            ]);
        } catch (\Throwable $e) {
            return array_merge($inputData, ['action' => 'invoice_get_status', 'success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function periodToDays(string $period): int
    {
        return match ($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            '12m' => 365,
            'all' => 3650,
            default => 30,
        };
    }

    private function periodToMonths(string $period): int
    {
        return match ($period) {
            '7d' => 1,
            '30d' => 1,
            '90d' => 3,
            '12m' => 12,
            'all' => 120,
            default => 12,
        };
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}s";
        if ($seconds < 3600) return round($seconds / 60) . 'm';
        if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
        return round($seconds / 86400, 1) . 'd';
    }

    // ── Template variable resolution ────────────────────────────────────

    private function resolveVariables(array $config, array $inputData): array
    {
        $vars = $this->buildVarMap($inputData);
        return $this->replaceVarsRecursive($config, $vars);
    }

    private function buildVarMap(array $data, string $prefix = ''): array
    {
        $vars = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;
            if (is_array($value)) {
                $vars = array_merge($vars, $this->buildVarMap($value, $fullKey));
            } else {
                $vars['{' . $fullKey . '}'] = (string)$value;
            }
        }

        // Auto-generate formatted summary variables for known top-level arrays
        if ($prefix === '') {
            $vars = array_merge($vars, $this->buildArraySummaries($data));
        }

        return $vars;
    }

    /**
     * Generate readable summary variables for common array data (files, events, contacts, etc.)
     */
    private function buildArraySummaries(array $data): array
    {
        $summaries = [];

        if (array_key_exists('files', $data) && is_array($data['files'])) {
            $names = [];
            $lines = [];
            foreach ($data['files'] as $i => $file) {
                $name = $file['original_name'] ?? $file['filename'] ?? $file['name'] ?? 'file';
                $size = isset($file['size']) ? $this->formatFileSize((int)$file['size']) : '';
                $names[] = $name;
                $lines[] = ($i + 1) . '. ' . $name . ($size ? " ({$size})" : '');
            }
            $summaries['{files_list}'] = $lines ? implode("\n", $lines) : 'No files';
            $summaries['{files_names}'] = $names ? implode(', ', $names) : 'No files';
        }

        if (array_key_exists('events', $data) && is_array($data['events'])) {
            $lines = [];
            foreach ($data['events'] as $i => $event) {
                $title = $event['title'] ?? $event['name'] ?? 'Event';
                $start = $event['start_time'] ?? $event['start'] ?? '';
                $lines[] = ($i + 1) . '. ' . $title . ($start ? " ({$start})" : '');
            }
            $summaries['{events_list}'] = $lines ? implode("\n", $lines) : 'No events';
        }

        if (array_key_exists('contacts', $data) && is_array($data['contacts'])) {
            $lines = [];
            foreach ($data['contacts'] as $i => $contact) {
                $name = $contact['name'] ?? $contact['display_name'] ?? '';
                $email = $contact['email'] ?? '';
                $label = $name ?: $email;
                if ($name && $email) $label = "{$name} <{$email}>";
                $lines[] = ($i + 1) . '. ' . $label;
            }
            $summaries['{contacts_list}'] = $lines ? implode("\n", $lines) : 'No contacts';
        }

        if (array_key_exists('rankings', $data) && is_array($data['rankings'])) {
            $lines = [];
            foreach ($data['rankings'] as $i => $rank) {
                $name = $rank['client_name'] ?? $rank['domain'] ?? 'Client';
                $rev = isset($rank['total_revenue']) ? number_format((float)$rank['total_revenue'], 2) : '';
                $lines[] = ($i + 1) . '. ' . $name . ($rev ? " - {$rev}" : '');
            }
            $summaries['{rankings_list}'] = $lines ? implode("\n", $lines) : 'No data';
        }

        if (array_key_exists('boards', $data) && is_array($data['boards'])) {
            $lines = [];
            foreach ($data['boards'] as $i => $board) {
                $name = $board['name'] ?? 'Board';
                $lines[] = ($i + 1) . '. ' . $name;
            }
            $summaries['{boards_list}'] = $lines ? implode("\n", $lines) : 'No boards';
        }

        if (array_key_exists('monthly_breakdown', $data) && is_array($data['monthly_breakdown'])) {
            $lines = [];
            foreach ($data['monthly_breakdown'] as $row) {
                $month = $row['month'] ?? '';
                $rev = isset($row['revenue']) ? number_format((float)$row['revenue'], 2) : '0';
                $profit = isset($row['profit']) ? number_format((float)$row['profit'], 2) : '0';
                $lines[] = "{$month}: revenue {$rev}, profit {$profit}";
            }
            $summaries['{monthly_breakdown_list}'] = $lines ? implode("\n", $lines) : 'No data';
        }

        if (array_key_exists('invoices', $data) && is_array($data['invoices'])) {
            $lines = [];
            foreach ($data['invoices'] as $i => $inv) {
                $num = $inv['invoice_number'] ?? "#{$inv['id']}";
                $total = isset($inv['total']) ? number_format((float)$inv['total'], 2) : '';
                $days = $inv['days_overdue'] ?? '';
                $lines[] = ($i + 1) . ". {$num}" . ($total ? " - {$total}" : '') . ($days ? " ({$days}d overdue)" : '');
            }
            $summaries['{invoices_list}'] = $lines ? implode("\n", $lines) : 'No invoices';
        }

        return $summaries;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 1) . ' GB';
    }

    private function replaceVarsRecursive(array $config, array $vars): array
    {
        foreach ($config as $k => $v) {
            if (is_string($v)) {
                $config[$k] = str_replace(array_keys($vars), array_values($vars), $v);
            } elseif (is_array($v)) {
                $config[$k] = $this->replaceVarsRecursive($v, $vars);
            }
        }
        return $config;
    }

    private function resolveField(string $field, array $data): mixed
    {
        if (empty($field)) return null;

        $parts = explode('.', $field);
        $current = $data;
        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }
        return $current;
    }

    // ── Printer nodes (desktop relay via task queue) ─────────────────────

    private function getExecutorDb(): \PDO
    {
        static $db = null;
        if (!$db) {
            $dsn = "mysql:host={$this->config['db']['host']};dbname={$this->config['db']['name']};charset=utf8mb4";
            $db = new \PDO($dsn, $this->config['db']['user'], $this->config['db']['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }
        return $db;
    }

    private function executePrinterList(array $config, array $inputData, bool $isTest = false): array
    {
        $email = $inputData['user_email'] ?? '';

        if ($isTest) {
            return [
                'action' => 'printer_list',
                'success' => true,
                'printers' => [
                    ['name' => 'Test Printer', 'displayName' => 'Test Printer', 'status' => 0, 'isDefault' => true],
                ],
                'printers_count' => 1,
                'default_printer' => 'Test Printer',
                'test_mode' => true,
            ];
        }

        try {
            $db = $this->getExecutorDb();

            $taskId = \Webmail\Addons\AutomationHub\Controllers\DesktopTaskController::createTask(
                $db,
                $email,
                'printer_list',
                [],
                $inputData['_execution_id'] ?? null,
                $inputData['_node_uid'] ?? null
            );

            $this->sendDesktopNotification($db, $email, $taskId, 'printer_list');

            $result = \Webmail\Addons\AutomationHub\Controllers\DesktopTaskController::waitForResult($db, $taskId, 15);

            if (!$result || $result['status'] === 'timeout') {
                return [
                    'action' => 'printer_list',
                    'success' => false,
                    'error' => 'Desktop app did not respond in time. Make sure FlowOne Email and Drive are running.',
                    'printers' => [],
                    'printers_count' => 0,
                    'default_printer' => '',
                ];
            }

            $printers = $result['result']['printers'] ?? [];
            $defaultPrinter = '';
            foreach ($printers as $p) {
                if (!empty($p['isDefault'])) {
                    $defaultPrinter = $p['name'] ?? $p['displayName'] ?? '';
                    break;
                }
            }

            return [
                'action' => 'printer_list',
                'success' => $result['status'] === 'completed',
                'printers' => $printers,
                'printers_count' => count($printers),
                'default_printer' => $defaultPrinter,
                'error' => $result['result']['error'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['action' => 'printer_list', 'success' => false, 'error' => $e->getMessage(), 'printers' => [], 'printers_count' => 0, 'default_printer' => ''];
        }
    }

    private function executePrinterPrint(array $config, array $inputData, bool $isTest = false): array
    {
        $email = $inputData['user_email'] ?? '';
        $printerName = $config['printer_name'] ?? ($inputData['printer_name'] ?? '');

        if (!$printerName) {
            return ['action' => 'printer_print', 'success' => false, 'error' => 'No printer specified', 'print_success' => false, 'print_printer' => ''];
        }

        if ($isTest) {
            return [
                'action' => 'printer_print',
                'success' => true,
                'print_success' => true,
                'print_printer' => $printerName,
                'test_mode' => true,
            ];
        }

        try {
            $payload = [
                'printer_name' => $printerName,
                'print_source' => $config['print_source'] ?? 'upstream',
                'copies' => (int)($config['copies'] ?? 1),
                'silent' => ($config['silent'] ?? true) !== false,
                'duplex' => $config['duplex'] ?? 'default',
            ];

            $source = $config['print_source'] ?? 'upstream';
            if ($source === 'drive_file') {
                $payload['drive_file_id'] = $config['drive_file_id'] ?? ($inputData['file_id'] ?? null);
            } elseif ($source === 'html') {
                $payload['html_content'] = $config['html_content'] ?? ($inputData['html_content'] ?? '');
            } else {
                $payload['file_path'] = $inputData['file_path'] ?? ($inputData['local_path'] ?? null);
                $payload['html_content'] = $inputData['html_content'] ?? null;
            }

            $db = $this->getExecutorDb();

            $taskId = \Webmail\Addons\AutomationHub\Controllers\DesktopTaskController::createTask(
                $db,
                $email,
                'printer_print',
                $payload,
                $inputData['_execution_id'] ?? null,
                $inputData['_node_uid'] ?? null
            );

            $this->sendDesktopNotification($db, $email, $taskId, 'printer_print');

            $result = \Webmail\Addons\AutomationHub\Controllers\DesktopTaskController::waitForResult($db, $taskId, 30);

            if (!$result || $result['status'] === 'timeout') {
                return [
                    'action' => 'printer_print',
                    'success' => false,
                    'print_success' => false,
                    'print_printer' => $printerName,
                    'print_error' => 'Desktop app did not respond in time.',
                ];
            }

            return [
                'action' => 'printer_print',
                'success' => $result['status'] === 'completed',
                'print_success' => $result['result']['success'] ?? false,
                'print_printer' => $printerName,
                'print_error' => $result['result']['error'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['action' => 'printer_print', 'success' => false, 'print_success' => false, 'print_printer' => $printerName, 'print_error' => $e->getMessage()];
        }
    }

    /**
     * Notify connected desktop app of a pending task via WebSocket broadcast file.
     * The WebSocket server picks up the broadcast and delivers to the user's session.
     */
    private function sendDesktopNotification(\PDO $db, string $userEmail, int $taskId, string $taskType): void
    {
        try {
            $broadcastDir = '/tmp/mailflow_ws_broadcast';
            if (!is_dir($broadcastDir)) {
                @mkdir($broadcastDir, 0777, true);
            }

            $event = [
                'type' => 'DESKTOP_TASK',
                'target_email' => $userEmail,
                'task_id' => $taskId,
                'task_type' => $taskType,
                'timestamp' => time(),
            ];

            $filename = $broadcastDir . '/desktop_task_' . $taskId . '_' . time() . '.json';
            file_put_contents($filename, json_encode($event));
        } catch (\Throwable $e) {
            // Non-fatal: desktop will pick up via polling if broadcast fails
        }
    }

    // ── Mailchimp Actions ────────────────────────────────────────────────

    private function getMailchimpApiKey(string $email): ?string
    {
        $db = $this->getDb();
        $stmt = $db->prepare("SELECT api_key_encrypted FROM automation_hub_connections WHERE user_email = ? AND provider = 'mailchimp' LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || empty($row['api_key_encrypted'])) return null;

        $secret = $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
        return \Webmail\Addons\AIAssistant\Services\AIService::decryptApiKey($row['api_key_encrypted'], $secret);
    }

    private function getMailchimpDataCenter(string $apiKey): string
    {
        $parts = explode('-', $apiKey);
        return $parts[1] ?? 'us1';
    }

    private function mailchimpApiRequest(string $apiKey, string $method, string $path, ?array $body = null): array
    {
        $dc = $this->getMailchimpDataCenter($apiKey);
        $url = "https://{$dc}.api.mailchimp.com/3.0/" . ltrim($path, '/');

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) return ['success' => false, 'error' => "cURL error: $err", 'http_code' => 0];

        $decoded = json_decode($resp, true);
        if ($httpCode >= 400) {
            $detail = $decoded['detail'] ?? $decoded['title'] ?? "HTTP $httpCode";
            return ['success' => false, 'error' => $detail, 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    private function executeMailchimpGetLists(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $apiKey = $this->getMailchimpApiKey($email);
            if (!$apiKey) return ['action' => 'mailchimp_get_lists', 'success' => false, 'error' => 'Mailchimp not connected'];

            $result = $this->mailchimpApiRequest($apiKey, 'GET', 'lists?count=100&fields=lists.id,lists.name,lists.stats.member_count,lists.date_created');
            if (!$result['success']) return array_merge($inputData, ['action' => 'mailchimp_get_lists', 'success' => false, 'error' => $result['error']]);

            $lists = [];
            foreach (($result['data']['lists'] ?? []) as $l) {
                $lists[] = [
                    'id' => $l['id'],
                    'name' => $l['name'],
                    'member_count' => $l['stats']['member_count'] ?? 0,
                    'date_created' => $l['date_created'] ?? '',
                ];
            }

            $formatted = [];
            foreach ($lists as $i => $l) {
                $formatted[] = ($i + 1) . '. ' . $l['name'] . ' (' . $l['member_count'] . ' members)';
            }

            return array_merge($inputData, [
                'action' => 'mailchimp_get_lists', 'success' => true,
                'lists' => $lists,
                'lists_count' => count($lists),
                'lists_list' => implode("\n", $formatted),
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'mailchimp_get_lists', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeMailchimpGetMembers(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $apiKey = $this->getMailchimpApiKey($email);
            if (!$apiKey) return ['action' => 'mailchimp_get_members', 'success' => false, 'error' => 'Mailchimp not connected'];

            $listId = $config['list_id'] ?? '';
            if (!$listId) return array_merge($inputData, ['action' => 'mailchimp_get_members', 'success' => false, 'error' => 'Audience/List ID is required']);

            $limit = min((int)($config['limit'] ?? 100), 1000);
            $status = $config['status'] ?? '';

            $path = "lists/{$listId}/members?count={$limit}&fields=members.email_address,members.status,members.merge_fields,members.id,members.full_name,total_items";
            if ($status) $path .= '&status=' . urlencode($status);

            $result = $this->mailchimpApiRequest($apiKey, 'GET', $path);
            if (!$result['success']) return array_merge($inputData, ['action' => 'mailchimp_get_members', 'success' => false, 'error' => $result['error']]);

            $members = [];
            $emails = [];
            foreach (($result['data']['members'] ?? []) as $m) {
                $members[] = [
                    'id' => $m['id'],
                    'email' => $m['email_address'],
                    'status' => $m['status'],
                    'full_name' => $m['full_name'] ?? '',
                    'first_name' => $m['merge_fields']['FNAME'] ?? '',
                    'last_name' => $m['merge_fields']['LNAME'] ?? '',
                ];
                $emails[] = $m['email_address'];
            }

            $formatted = [];
            foreach ($members as $i => $m) {
                $name = $m['full_name'] ?: ($m['first_name'] . ' ' . $m['last_name']);
                $formatted[] = ($i + 1) . '. ' . trim($name) . ' <' . $m['email'] . '> [' . $m['status'] . ']';
            }

            $listInfoResult = $this->mailchimpApiRequest($apiKey, 'GET', "lists/{$listId}?fields=name");
            $listName = $listInfoResult['success'] ? ($listInfoResult['data']['name'] ?? $listId) : $listId;

            return array_merge($inputData, [
                'action' => 'mailchimp_get_members', 'success' => true,
                'members' => $members,
                'members_count' => count($members),
                'members_list' => implode("\n", $formatted),
                'member_emails' => implode(', ', $emails),
                'list_name' => $listName,
                'total_items' => $result['data']['total_items'] ?? count($members),
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'mailchimp_get_members', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeMailchimpAddMember(array $config, array $inputData, bool $isTest = false): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $apiKey = $this->getMailchimpApiKey($email);
            if (!$apiKey) return ['action' => 'mailchimp_add_member', 'success' => false, 'error' => 'Mailchimp not connected'];

            $listId = $config['list_id'] ?? '';
            $memberEmail = $config['email'] ?? '';
            if (!$listId || !$memberEmail) return array_merge($inputData, ['action' => 'mailchimp_add_member', 'success' => false, 'error' => 'List ID and email are required']);

            if ($isTest) {
                return array_merge($inputData, [
                    'action' => 'mailchimp_add_member', 'success' => true,
                    'member_email' => $memberEmail, 'member_id' => 'test-id',
                    'member_status' => $config['status'] ?? 'subscribed',
                    'list_name' => $listId, '_test' => true,
                ]);
            }

            $body = [
                'email_address' => $memberEmail,
                'status' => $config['status'] ?? 'subscribed',
            ];

            $mergeFields = [];
            if (!empty($config['first_name'])) $mergeFields['FNAME'] = $config['first_name'];
            if (!empty($config['last_name'])) $mergeFields['LNAME'] = $config['last_name'];
            if (!empty($mergeFields)) $body['merge_fields'] = $mergeFields;

            $updateExisting = $config['update_existing'] ?? true;

            if ($updateExisting) {
                $subscriberHash = md5(strtolower($memberEmail));
                $body['status_if_new'] = $body['status'];
                $result = $this->mailchimpApiRequest($apiKey, 'PUT', "lists/{$listId}/members/{$subscriberHash}", $body);
            } else {
                $result = $this->mailchimpApiRequest($apiKey, 'POST', "lists/{$listId}/members", $body);
            }

            if (!$result['success']) return array_merge($inputData, ['action' => 'mailchimp_add_member', 'success' => false, 'error' => $result['error']]);

            $listInfoResult = $this->mailchimpApiRequest($apiKey, 'GET', "lists/{$listId}?fields=name");
            $listName = $listInfoResult['success'] ? ($listInfoResult['data']['name'] ?? $listId) : $listId;

            return array_merge($inputData, [
                'action' => 'mailchimp_add_member', 'success' => true,
                'member_email' => $result['data']['email_address'] ?? $memberEmail,
                'member_id' => $result['data']['id'] ?? '',
                'member_status' => $result['data']['status'] ?? '',
                'list_name' => $listName,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'mailchimp_add_member', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeMailchimpRemoveMember(array $config, array $inputData, bool $isTest = false): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $apiKey = $this->getMailchimpApiKey($email);
            if (!$apiKey) return ['action' => 'mailchimp_remove_member', 'success' => false, 'error' => 'Mailchimp not connected'];

            $listId = $config['list_id'] ?? '';
            $memberEmail = $config['email'] ?? '';
            if (!$listId || !$memberEmail) return array_merge($inputData, ['action' => 'mailchimp_remove_member', 'success' => false, 'error' => 'List ID and email are required']);

            if ($isTest) {
                return array_merge($inputData, [
                    'action' => 'mailchimp_remove_member', 'success' => true,
                    'removed_email' => $memberEmail, 'member_status' => 'unsubscribed',
                    'list_name' => $listId, '_test' => true,
                ]);
            }

            $subscriberHash = md5(strtolower($memberEmail));
            $result = $this->mailchimpApiRequest($apiKey, 'PATCH', "lists/{$listId}/members/{$subscriberHash}", [
                'status' => 'unsubscribed',
            ]);

            if (!$result['success']) return array_merge($inputData, ['action' => 'mailchimp_remove_member', 'success' => false, 'error' => $result['error']]);

            $listInfoResult = $this->mailchimpApiRequest($apiKey, 'GET', "lists/{$listId}?fields=name");
            $listName = $listInfoResult['success'] ? ($listInfoResult['data']['name'] ?? $listId) : $listId;

            return array_merge($inputData, [
                'action' => 'mailchimp_remove_member', 'success' => true,
                'removed_email' => $memberEmail,
                'member_status' => 'unsubscribed',
                'list_name' => $listName,
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'mailchimp_remove_member', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeMailchimpGetCampaigns(array $config, array $inputData): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $apiKey = $this->getMailchimpApiKey($email);
            if (!$apiKey) return ['action' => 'mailchimp_get_campaigns', 'success' => false, 'error' => 'Mailchimp not connected'];

            $limit = min((int)($config['limit'] ?? 10), 100);
            $status = $config['status'] ?? '';

            $path = "campaigns?count={$limit}&sort_field=send_time&sort_dir=DESC&fields=campaigns.id,campaigns.settings.title,campaigns.settings.subject_line,campaigns.status,campaigns.send_time,campaigns.emails_sent,campaigns.report_summary,total_items";
            if ($status) $path .= '&status=' . urlencode($status);

            $result = $this->mailchimpApiRequest($apiKey, 'GET', $path);
            if (!$result['success']) return array_merge($inputData, ['action' => 'mailchimp_get_campaigns', 'success' => false, 'error' => $result['error']]);

            $campaigns = [];
            foreach (($result['data']['campaigns'] ?? []) as $c) {
                $campaigns[] = [
                    'id' => $c['id'],
                    'title' => $c['settings']['title'] ?? '',
                    'subject' => $c['settings']['subject_line'] ?? '',
                    'status' => $c['status'],
                    'send_time' => $c['send_time'] ?? '',
                    'emails_sent' => $c['emails_sent'] ?? 0,
                    'open_rate' => $c['report_summary']['open_rate'] ?? null,
                    'click_rate' => $c['report_summary']['click_rate'] ?? null,
                ];
            }

            $formatted = [];
            foreach ($campaigns as $i => $c) {
                $line = ($i + 1) . '. ' . ($c['title'] ?: $c['subject']) . ' [' . $c['status'] . ']';
                if ($c['send_time']) $line .= ' sent ' . substr($c['send_time'], 0, 10);
                $formatted[] = $line;
            }

            return array_merge($inputData, [
                'action' => 'mailchimp_get_campaigns', 'success' => true,
                'campaigns' => $campaigns,
                'campaigns_count' => count($campaigns),
                'campaigns_list' => implode("\n", $formatted),
                'total_items' => $result['data']['total_items'] ?? count($campaigns),
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'mailchimp_get_campaigns', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeMailchimpSendCampaign(array $config, array $inputData, bool $isTest = false): array
    {
        try {
            $email = $inputData['user_email'] ?? '';
            $apiKey = $this->getMailchimpApiKey($email);
            if (!$apiKey) return ['action' => 'mailchimp_send_campaign', 'success' => false, 'error' => 'Mailchimp not connected'];

            $campaignId = $config['campaign_id'] ?? '';
            if (!$campaignId) return array_merge($inputData, ['action' => 'mailchimp_send_campaign', 'success' => false, 'error' => 'Campaign ID is required']);

            $infoResult = $this->mailchimpApiRequest($apiKey, 'GET', "campaigns/{$campaignId}?fields=id,settings.title,status");
            if (!$infoResult['success']) return array_merge($inputData, ['action' => 'mailchimp_send_campaign', 'success' => false, 'error' => $infoResult['error']]);

            $campaignTitle = $infoResult['data']['settings']['title'] ?? '';
            $campaignStatus = $infoResult['data']['status'] ?? '';

            if ($isTest) {
                return array_merge($inputData, [
                    'action' => 'mailchimp_send_campaign', 'success' => true,
                    'campaign_id' => $campaignId, 'campaign_title' => $campaignTitle,
                    'send_status' => 'sent', '_test' => true,
                ]);
            }

            if ($campaignStatus !== 'save') {
                return array_merge($inputData, [
                    'action' => 'mailchimp_send_campaign', 'success' => false,
                    'error' => "Campaign is in '{$campaignStatus}' status. Only draft ('save') campaigns can be sent.",
                ]);
            }

            $result = $this->mailchimpApiRequest($apiKey, 'POST', "campaigns/{$campaignId}/actions/send");
            if (!$result['success']) return array_merge($inputData, ['action' => 'mailchimp_send_campaign', 'success' => false, 'error' => $result['error']]);

            return array_merge($inputData, [
                'action' => 'mailchimp_send_campaign', 'success' => true,
                'campaign_id' => $campaignId,
                'campaign_title' => $campaignTitle,
                'send_status' => 'sent',
            ]);
        } catch (\Throwable $e) {
            return ['action' => 'mailchimp_send_campaign', 'success' => false, 'error' => $e->getMessage()];
        }
    }
}
