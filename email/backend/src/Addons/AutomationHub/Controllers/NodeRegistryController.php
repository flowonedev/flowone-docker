<?php

namespace Webmail\Addons\AutomationHub\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;

class NodeRegistryController extends BaseController
{
    private ?\PDO $db = null;

    private function getDb(): \PDO
    {
        if (!$this->db) {
            $dsn = "mysql:host={$this->config['db']['host']};dbname={$this->config['db']['name']};charset=utf8mb4";
            $this->db = new \PDO($dsn, $this->config['db']['user'], $this->config['db']['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }
        return $this->db;
    }

    public function list(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $registry = [
            // Triggers
            'trigger.board.shared' => [
                'category' => 'trigger', 'label' => 'Board Shared', 'subtitle' => 'When a board is shared with someone',
                'icon' => 'group_add', 'group' => 'Board',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.board.card_moved' => [
                'category' => 'trigger', 'label' => 'Card Moved', 'subtitle' => 'When a card moves to a list',
                'icon' => 'swap_horiz', 'group' => 'Board',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.board.card_created' => [
                'category' => 'trigger', 'label' => 'Card Created', 'subtitle' => 'When a new card is created',
                'icon' => 'add_card', 'group' => 'Board',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.board.card_completed' => [
                'category' => 'trigger', 'label' => 'Card Completed', 'subtitle' => 'When a card is marked complete',
                'icon' => 'task_alt', 'group' => 'Board',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.board.card_overdue' => [
                'category' => 'trigger', 'label' => 'Card Overdue', 'subtitle' => 'When a card passes its due date',
                'icon' => 'schedule', 'group' => 'Board',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.crm.deal_stage_changed' => [
                'category' => 'trigger', 'label' => 'Deal Stage Changed', 'subtitle' => 'When a deal moves to a new stage',
                'icon' => 'trending_up', 'group' => 'CRM',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.crm.deal_won' => [
                'category' => 'trigger', 'label' => 'Deal Won', 'subtitle' => 'When a deal is marked as won',
                'icon' => 'emoji_events', 'group' => 'CRM',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.crm.deal_lost' => [
                'category' => 'trigger', 'label' => 'Deal Lost', 'subtitle' => 'When a deal is marked as lost',
                'icon' => 'thumb_down', 'group' => 'CRM',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.crm.invoice_overdue' => [
                'category' => 'trigger', 'label' => 'Invoice Overdue', 'subtitle' => 'When an invoice is past due',
                'icon' => 'receipt_long', 'group' => 'CRM',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // Client triggers
            'trigger.client.health_low' => [
                'category' => 'trigger', 'label' => 'Client Health Low', 'subtitle' => 'When health score drops below threshold',
                'icon' => 'heart_broken', 'group' => 'Clients',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.client.inactive' => [
                'category' => 'trigger', 'label' => 'Client Inactive', 'subtitle' => 'When a client has no activity',
                'icon' => 'person_off', 'group' => 'Clients',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // Financial triggers
            'trigger.invoice.paid' => [
                'category' => 'trigger', 'label' => 'Invoice Paid', 'subtitle' => 'When an invoice is marked paid',
                'icon' => 'paid', 'group' => 'Financial',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.invoice.created' => [
                'category' => 'trigger', 'label' => 'Invoice Created', 'subtitle' => 'When a new invoice is created',
                'icon' => 'note_add', 'group' => 'Financial',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.financial.threshold' => [
                'category' => 'trigger', 'label' => 'Financial Threshold', 'subtitle' => 'When revenue/expenses cross a limit',
                'icon' => 'price_check', 'group' => 'Financial',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            'trigger.server.health' => [
                'category' => 'trigger', 'label' => 'Server Health', 'subtitle' => 'Monitor CPU, RAM, disk, services',
                'icon' => 'monitor_heart', 'group' => 'Server',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.manual' => [
                'category' => 'trigger', 'label' => 'Manual Trigger', 'subtitle' => 'Run workflow manually via Test button',
                'icon' => 'play_circle', 'group' => 'Core',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.schedule.cron' => [
                'category' => 'trigger', 'label' => 'Schedule', 'subtitle' => 'Run on a time schedule',
                'icon' => 'timer', 'group' => 'Core',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.webhook.incoming' => [
                'category' => 'trigger', 'label' => 'Webhook', 'subtitle' => 'Triggered by HTTP request',
                'icon' => 'webhook', 'group' => 'Core',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.telegram.message' => [
                'category' => 'trigger', 'label' => 'Telegram Message', 'subtitle' => 'When a message is received',
                'icon' => 'send', 'group' => 'Telegram',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // Actions
            'action.email.send' => [
                'category' => 'action', 'label' => 'Send Email', 'subtitle' => 'Send an email message',
                'icon' => 'mail', 'group' => 'Communication',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.chat.send' => [
                'category' => 'action', 'label' => 'Send Chat Message', 'subtitle' => 'Post to a chat or channel',
                'icon' => 'chat', 'group' => 'Communication',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.notification.send' => [
                'category' => 'action', 'label' => 'Send Notification', 'subtitle' => 'Push notification to user',
                'icon' => 'notifications', 'group' => 'Communication',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.telegram.send' => [
                'category' => 'action', 'label' => 'Telegram Send', 'subtitle' => 'Send a Telegram message',
                'icon' => 'send', 'group' => 'Telegram',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.http.request' => [
                'category' => 'action', 'label' => 'HTTP Request', 'subtitle' => 'Make an API call',
                'icon' => 'http', 'group' => 'Core',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.crm.move_deal' => [
                'category' => 'action', 'label' => 'Move Deal', 'subtitle' => 'Move deal to a pipeline stage',
                'icon' => 'move_up', 'group' => 'CRM',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.board.move_card' => [
                'category' => 'action', 'label' => 'Move Card', 'subtitle' => 'Move card to another list',
                'icon' => 'drag_indicator', 'group' => 'Board',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.task.create' => [
                'category' => 'action', 'label' => 'Create Task', 'subtitle' => 'Create a new task',
                'icon' => 'add_task', 'group' => 'Core',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.export.csv' => [
                'category' => 'action', 'label' => 'Export to CSV', 'subtitle' => 'Filter and export data to CSV file',
                'icon' => 'download', 'group' => 'Data',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // Client actions
            'action.client.get_data' => [
                'category' => 'action', 'label' => 'Get Client Data', 'subtitle' => 'Fetch client details and contacts',
                'icon' => 'person_search', 'group' => 'Clients',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.client.get_financials' => [
                'category' => 'action', 'label' => 'Client Financials', 'subtitle' => 'Get client revenue and invoices',
                'icon' => 'account_balance', 'group' => 'Clients',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.client.get_health' => [
                'category' => 'action', 'label' => 'Client Health Score', 'subtitle' => 'Calculate client health score',
                'icon' => 'health_and_safety', 'group' => 'Clients',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // Invoice actions
            'action.invoice.create' => [
                'category' => 'action', 'label' => 'Create Invoice', 'subtitle' => 'Create a new invoice for a client',
                'icon' => 'receipt', 'group' => 'Financial',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.invoice.send' => [
                'category' => 'action', 'label' => 'Send Invoice', 'subtitle' => 'Mark invoice as sent',
                'icon' => 'forward_to_inbox', 'group' => 'Financial',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.invoice.record_payment' => [
                'category' => 'action', 'label' => 'Record Payment', 'subtitle' => 'Record a payment on an invoice',
                'icon' => 'payments', 'group' => 'Financial',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // Statistics actions
            'action.stats.email' => [
                'category' => 'action', 'label' => 'Email Statistics', 'subtitle' => 'Get email stats for a period',
                'icon' => 'query_stats', 'group' => 'Statistics',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.stats.response_time' => [
                'category' => 'action', 'label' => 'Response Time', 'subtitle' => 'Get avg reply/response times',
                'icon' => 'speed', 'group' => 'Statistics',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.stats.revenue_report' => [
                'category' => 'action', 'label' => 'Revenue Report', 'subtitle' => 'Get revenue and profitability data',
                'icon' => 'bar_chart', 'group' => 'Statistics',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.stats.client_ranking' => [
                'category' => 'action', 'label' => 'Client Ranking', 'subtitle' => 'Rank clients by value',
                'icon' => 'leaderboard', 'group' => 'Statistics',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.stats.aging_report' => [
                'category' => 'action', 'label' => 'Invoice Aging', 'subtitle' => 'Overdue invoice breakdown',
                'icon' => 'update', 'group' => 'Statistics',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // ── Calendar ──────────────────────────────────────────────────
            'trigger.calendar.event_created' => [
                'category' => 'trigger', 'label' => 'Event Created', 'subtitle' => 'When a calendar event is created',
                'icon' => 'event_available', 'group' => 'Calendar',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.calendar.event_upcoming' => [
                'category' => 'trigger', 'label' => 'Event Upcoming', 'subtitle' => 'Before an event starts',
                'icon' => 'upcoming', 'group' => 'Calendar',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.calendar.create_event' => [
                'category' => 'action', 'label' => 'Create Event', 'subtitle' => 'Create a calendar event',
                'icon' => 'edit_calendar', 'group' => 'Calendar',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.calendar.get_events' => [
                'category' => 'action', 'label' => 'Get Events', 'subtitle' => 'Get events for a date range',
                'icon' => 'date_range', 'group' => 'Calendar',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.calendar.update_event' => [
                'category' => 'action', 'label' => 'Update Event', 'subtitle' => 'Update an existing event',
                'icon' => 'event_note', 'group' => 'Calendar',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.calendar.delete_event' => [
                'category' => 'action', 'label' => 'Delete Event', 'subtitle' => 'Delete a calendar event',
                'icon' => 'event_busy', 'group' => 'Calendar',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.calendar.get_upcoming' => [
                'category' => 'action', 'label' => 'Upcoming Events', 'subtitle' => 'Get next N upcoming events',
                'icon' => 'calendar_month', 'group' => 'Calendar',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // ── Drive ────────────────────────────────────────────────────
            'trigger.drive.file_uploaded' => [
                'category' => 'trigger', 'label' => 'File Uploaded', 'subtitle' => 'When a new file is uploaded',
                'icon' => 'upload_file', 'group' => 'Drive',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'trigger.drive.file_updated' => [
                'category' => 'trigger', 'label' => 'File Updated', 'subtitle' => 'When a file is modified',
                'icon' => 'sync', 'group' => 'Drive',
                'inputs' => [], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.drive.list_files' => [
                'category' => 'action', 'label' => 'List Files', 'subtitle' => 'List files in a folder',
                'icon' => 'folder_open', 'group' => 'Drive',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.drive.get_file_info' => [
                'category' => 'action', 'label' => 'Get File Info', 'subtitle' => 'Get file metadata',
                'icon' => 'description', 'group' => 'Drive',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'action.drive.create_folder' => [
                'category' => 'action', 'label' => 'Create Folder', 'subtitle' => 'Create a new drive folder',
                'icon' => 'create_new_folder', 'group' => 'Drive',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],

            // Logic
            'logic.condition' => [
                'category' => 'logic', 'label' => 'Condition', 'subtitle' => 'If / else branch',
                'icon' => 'call_split', 'group' => 'Logic',
                'inputs' => [['id' => 'input', 'label' => '']],
                'outputs' => [['id' => 'true', 'label' => 'True'], ['id' => 'false', 'label' => 'False']],
            ],
            'logic.delay' => [
                'category' => 'logic', 'label' => 'Delay', 'subtitle' => 'Wait before continuing',
                'icon' => 'hourglass_empty', 'group' => 'Logic',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'logic.filter' => [
                'category' => 'logic', 'label' => 'Filter', 'subtitle' => 'Pass or block data',
                'icon' => 'filter_alt', 'group' => 'Logic',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ],
            'logic.merge' => [
                'category' => 'logic', 'label' => 'Merge', 'subtitle' => 'Combine multiple inputs',
                'icon' => 'merge', 'group' => 'Logic',
                'inputs' => [['id' => 'input_a', 'label' => 'A'], ['id' => 'input_b', 'label' => 'B']],
                'outputs' => [['id' => 'output', 'label' => '']],
            ],
        ];

        // Conditionally add AI nodes if API key is configured
        if ($this->isAIConfigured()) {
            $registry['action.ai.prompt'] = [
                'category' => 'action', 'label' => 'AI Prompt', 'subtitle' => 'Send a prompt to AI and get a response',
                'icon' => 'auto_awesome', 'group' => 'AI',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.ai.summarize'] = [
                'category' => 'action', 'label' => 'AI Summarize', 'subtitle' => 'Summarize text content with AI',
                'icon' => 'summarize', 'group' => 'AI',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.ai.rewrite'] = [
                'category' => 'action', 'label' => 'AI Rewrite', 'subtitle' => 'Rewrite text in a chosen style',
                'icon' => 'edit_note', 'group' => 'AI',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
        }

        // Conditionally add Weather node if API key configured
        if ($this->isWeatherConfigured()) {
            $registry['action.weather.get_current'] = [
                'category' => 'action', 'label' => 'Get Weather', 'subtitle' => 'Current weather for a location',
                'icon' => 'cloud', 'group' => 'Weather',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
        }

        // Conditionally add Google nodes if OAuth token exists
        if ($this->isGoogleConnected()) {
            $registry['action.google.get_contacts'] = [
                'category' => 'action', 'label' => 'Get Contacts', 'subtitle' => 'Fetch Google contacts',
                'icon' => 'contacts', 'group' => 'Google',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.google.get_contact'] = [
                'category' => 'action', 'label' => 'Find Contact', 'subtitle' => 'Find a Google contact by name/email',
                'icon' => 'person_search', 'group' => 'Google',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.google.sync_calendar'] = [
                'category' => 'action', 'label' => 'Sync Google Calendar', 'subtitle' => 'Force Google Calendar sync',
                'icon' => 'sync', 'group' => 'Google',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
        }

        // Conditionally add Trello nodes if connected
        if ($this->isTrelloConnected()) {
            $registry['action.trello.sync_boards'] = [
                'category' => 'action', 'label' => 'Sync Trello Boards', 'subtitle' => 'Import Trello boards to local',
                'icon' => 'developer_board', 'group' => 'Trello',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.trello.get_boards'] = [
                'category' => 'action', 'label' => 'Get Trello Boards', 'subtitle' => 'Fetch Trello boards and cards',
                'icon' => 'view_kanban', 'group' => 'Trello',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
        }

        // Conditionally add Mailchimp nodes if API key configured
        if ($this->isMailchimpConnected()) {
            $registry['action.mailchimp.get_lists'] = [
                'category' => 'action', 'label' => 'MC Get Lists', 'subtitle' => 'Fetch Mailchimp audiences',
                'icon' => 'lists', 'group' => 'Mailchimp',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.mailchimp.get_members'] = [
                'category' => 'action', 'label' => 'MC Get Members', 'subtitle' => 'Fetch subscribers from an audience',
                'icon' => 'group', 'group' => 'Mailchimp',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.mailchimp.add_member'] = [
                'category' => 'action', 'label' => 'MC Add Member', 'subtitle' => 'Add or update a subscriber',
                'icon' => 'person_add', 'group' => 'Mailchimp',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.mailchimp.remove_member'] = [
                'category' => 'action', 'label' => 'MC Remove Member', 'subtitle' => 'Unsubscribe a contact',
                'icon' => 'person_remove', 'group' => 'Mailchimp',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.mailchimp.get_campaigns'] = [
                'category' => 'action', 'label' => 'MC Get Campaigns', 'subtitle' => 'Fetch Mailchimp campaigns',
                'icon' => 'campaign', 'group' => 'Mailchimp',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.mailchimp.send_campaign'] = [
                'category' => 'action', 'label' => 'MC Send Campaign', 'subtitle' => 'Send a Mailchimp campaign',
                'icon' => 'send', 'group' => 'Mailchimp',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
        }

        // Conditionally add Printer nodes if desktop app has been seen
        if ($this->isDesktopConnected()) {
            $registry['action.printer.list'] = [
                'category' => 'action', 'label' => 'List Printers', 'subtitle' => 'Get available printers from local machine',
                'icon' => 'print', 'group' => 'Printer',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
            $registry['action.printer.print'] = [
                'category' => 'action', 'label' => 'Print Document', 'subtitle' => 'Send a document to a printer',
                'icon' => 'print', 'group' => 'Printer',
                'inputs' => [['id' => 'input', 'label' => '']], 'outputs' => [['id' => 'output', 'label' => '']],
            ];
        }

        return Response::success(['registry' => $registry]);
    }

    private function isWeatherConfigured(): bool
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT 1 FROM automation_hub_connections WHERE user_email = ? AND provider = 'openweathermap' AND api_key_encrypted IS NOT NULL LIMIT 1");
            $stmt->execute([$this->userEmail]);
            return (bool)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }

    private function isGoogleConnected(): bool
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT 1 FROM webmail_oauth_tokens WHERE primary_email = ? AND provider = 'google' LIMIT 1");
            $stmt->execute([$this->userEmail]);
            return (bool)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }

    private function isTrelloConnected(): bool
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT 1 FROM automation_hub_connections WHERE user_email = ? AND provider = 'trello' AND access_token_encrypted IS NOT NULL LIMIT 1");
            $stmt->execute([$this->userEmail]);
            return (bool)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }

    private function isAIConfigured(): bool
    {
        $hash = md5(strtolower($this->userEmail));
        $file = '/var/www/vps-email/data/global/ai_' . $hash . '.json';

        if (!file_exists($file)) return false;

        $settings = json_decode(file_get_contents($file), true);
        return !empty($settings['ai_api_key_encrypted']);
    }

    private function isMailchimpConnected(): bool
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT 1 FROM automation_hub_connections WHERE user_email = ? AND provider = 'mailchimp' AND api_key_encrypted IS NOT NULL LIMIT 1");
            $stmt->execute([$this->userEmail]);
            return (bool)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }

    private function isDesktopConnected(): bool
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                SELECT 1 FROM device_registry
                WHERE user_email = ? AND device_type = 'desktop' AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$this->userEmail]);
            return (bool)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            // If device_registry table doesn't exist, always show printer nodes
            return true;
        }
    }
}
