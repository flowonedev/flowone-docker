<?php

namespace Webmail\Addons\CrmPro\Controllers;

use Webmail\Controllers\BaseController;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\CrmPro\Services\CrmDealService;
use Webmail\Addons\CrmPro\Services\CrmTagService;
use Webmail\Addons\CrmPro\Services\CrmReminderService;
use Webmail\Addons\CrmPro\Services\CrmTimelineService;
use Webmail\Addons\CrmPro\Services\CrmReportService;

/**
 * CrmDealController
 * 
 * Handles deal/pipeline, tags, and custom field API endpoints for CRM Pro.
 */
class CrmDealController extends BaseController
{
    private CrmDealService $dealService;
    private CrmTagService $tagService;
    private CrmReminderService $reminderService;
    private CrmTimelineService $timelineService;
    private ?CrmReportService $reportService = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->dealService = new CrmDealService($config);
        $this->tagService = new CrmTagService($config);
        $this->reminderService = new CrmReminderService($config);
        $this->timelineService = new CrmTimelineService($config);
    }

    /**
     * Lazy-load report service (not needed for every request)
     */
    private function getReportService(): CrmReportService
    {
        if (!$this->reportService) {
            $this->reportService = new CrmReportService($this->config);
        }
        return $this->reportService;
    }

    /**
     * List deals with optional filters
     * GET /crm/deals?client_id=&pipeline_stage=&assigned_to=&search=
     */
    public function list(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $filters = [
            'client_id' => $request->getQuery('client_id'),
            'pipeline_stage' => $request->getQuery('pipeline_stage'),
            'assigned_to' => $request->getQuery('assigned_to'),
            'search' => $request->getQuery('search'),
        ];

        $deals = $this->dealService->listDeals($this->userEmail, array_filter($filters));
        $summary = $this->dealService->getSummary($this->userEmail);

        return Response::success(['deals' => $deals, 'summary' => $summary]);
    }

    /**
     * Get pipeline view (deals organized by stage)
     * GET /crm/deals/pipeline
     */
    public function pipeline(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $pipeline = $this->dealService->getPipelineView($this->userEmail);
        $summary = $this->dealService->getSummary($this->userEmail);

        return Response::success(['pipeline' => $pipeline, 'summary' => $summary]);
    }

    /**
     * Get a single deal
     * GET /crm/deals/{id}
     */
    public function get(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $deal = $this->dealService->getDeal($id, $this->userEmail);

        if (!$deal) {
            return Response::notFound('Deal not found');
        }

        return Response::success($deal);
    }

    /**
     * Create a new deal
     * POST /crm/deals
     */
    public function create(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $data = $request->input();
        if (empty($data['client_id']) || empty($data['title'])) {
            return Response::badRequest('client_id and title are required');
        }

        try {
            $deal = $this->dealService->createDeal($this->userEmail, $data);
            return Response::success($deal, 'Deal created');
        } catch (\Throwable $e) {
            error_log("CrmDealController::create error: " . $e->getMessage());
            return Response::serverError('Failed to create deal');
        }
    }

    /**
     * Update a deal
     * PUT /crm/deals/{id}
     */
    public function update(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $data = $request->input();

        $deal = $this->dealService->updateDeal($id, $this->userEmail, $data);
        if (!$deal) {
            return Response::notFound('Deal not found');
        }

        return Response::success($deal, 'Deal updated');
    }

    /**
     * Delete a deal
     * DELETE /crm/deals/{id}
     */
    public function delete(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $deleted = $this->dealService->deleteDeal($id, $this->userEmail);

        if (!$deleted) {
            return Response::notFound('Deal not found');
        }

        return Response::success(null, 'Deal deleted');
    }

    /**
     * Update a deal's pipeline stage (drag & drop support)
     * PUT /crm/deals/{id}/stage
     */
    public function updateStage(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $stage = $request->input('pipeline_stage');
        $lostReason = $request->input('lost_reason');

        if (!$stage) {
            return Response::badRequest('pipeline_stage is required');
        }

        $validStages = ['lead', 'contacted', 'proposal', 'negotiation', 'won', 'lost'];
        if (!in_array($stage, $validStages)) {
            return Response::badRequest('Invalid pipeline stage');
        }

        $deal = $this->dealService->updateStage($id, $this->userEmail, $stage, $lostReason);
        if (!$deal) {
            return Response::notFound('Deal not found');
        }

        return Response::success($deal, 'Stage updated');
    }

    // =========================================================================
    // Tags
    // =========================================================================

    public function listTags(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $tags = $this->tagService->listTags($this->userEmail);
        return Response::success(['tags' => $tags]);
    }

    public function createTag(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $data = $request->input();
        if (empty($data['name'])) {
            return Response::badRequest('Tag name is required');
        }

        try {
            $tag = $this->tagService->createTag($this->userEmail, $data);
            return Response::success($tag, 'Tag created');
        } catch (\Throwable $e) {
            return Response::badRequest('Tag already exists or invalid data');
        }
    }

    public function updateTag(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $tag = $this->tagService->updateTag($id, $this->userEmail, $request->input());

        if (!$tag) return Response::notFound('Tag not found');
        return Response::success($tag, 'Tag updated');
    }

    public function deleteTag(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$this->tagService->deleteTag($id, $this->userEmail)) {
            return Response::notFound('Tag not found');
        }
        return Response::success(null, 'Tag deleted');
    }

    public function assignTag(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $tagId = (int)$request->input('tag_id');
        if (!$tagId) return Response::badRequest('tag_id is required');

        $this->tagService->assignTag($clientId, $tagId);
        return Response::success(null, 'Tag assigned');
    }

    public function removeTag(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $tagId = (int)$request->getParam('tagId');

        $this->tagService->removeTag($clientId, $tagId);
        return Response::success(null, 'Tag removed');
    }

    public function getClientTags(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $tags = $this->tagService->getClientTags($clientId);
        return Response::success(['tags' => $tags]);
    }

    // =========================================================================
    // Custom Fields
    // =========================================================================

    public function listFieldDefinitions(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $fields = $this->tagService->listFieldDefinitions($this->userEmail);
        return Response::success(['fields' => $fields]);
    }

    public function createFieldDefinition(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $data = $request->input();
        if (empty($data['field_name']) || empty($data['field_type'])) {
            return Response::badRequest('field_name and field_type are required');
        }

        try {
            $field = $this->tagService->createFieldDefinition($this->userEmail, $data);
            return Response::success($field, 'Custom field created');
        } catch (\Throwable $e) {
            return Response::badRequest('Field already exists or invalid data');
        }
    }

    public function updateFieldDefinition(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $field = $this->tagService->updateFieldDefinition($id, $this->userEmail, $request->input());

        if (!$field) return Response::notFound('Custom field not found');
        return Response::success($field, 'Custom field updated');
    }

    public function deleteFieldDefinition(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$this->tagService->deleteFieldDefinition($id, $this->userEmail)) {
            return Response::notFound('Custom field not found');
        }
        return Response::success(null, 'Custom field deleted');
    }

    public function getClientFieldValues(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $fields = $this->tagService->getClientFieldValues($clientId, $this->userEmail);
        return Response::success(['fields' => $fields]);
    }

    public function setClientFieldValues(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $values = $request->input('values');

        if (!is_array($values)) {
            return Response::badRequest('values object is required (field_id => value)');
        }

        $this->tagService->setClientFieldValues($clientId, $values);
        $fields = $this->tagService->getClientFieldValues($clientId, $this->userEmail);
        return Response::success(['fields' => $fields], 'Custom fields updated');
    }

    // =========================================================================
    // Reminders
    // =========================================================================

    public function listReminders(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $filters = [
            'client_id' => $request->getQuery('client_id'),
            'is_completed' => $request->getQuery('is_completed'),
        ];

        $reminders = $this->reminderService->listReminders($this->userEmail, array_filter($filters, fn($v) => $v !== null));
        return Response::success(['reminders' => $reminders]);
    }

    public function createReminder(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $data = $request->input();
        if (empty($data['client_id']) || empty($data['title']) || empty($data['remind_at'])) {
            return Response::badRequest('client_id, title, and remind_at are required');
        }

        $reminder = $this->reminderService->createReminder($this->userEmail, $data);
        return Response::success($reminder, 'Reminder created');
    }

    public function updateReminder(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $reminder = $this->reminderService->updateReminder($id, $this->userEmail, $request->input());

        if (!$reminder) return Response::notFound('Reminder not found');
        return Response::success($reminder, 'Reminder updated');
    }

    public function deleteReminder(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        if (!$this->reminderService->deleteReminder($id, $this->userEmail)) {
            return Response::notFound('Reminder not found');
        }
        return Response::success(null, 'Reminder deleted');
    }

    public function completeReminder(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $reminder = $this->reminderService->completeReminder($id, $this->userEmail);

        if (!$reminder) return Response::notFound('Reminder not found');
        return Response::success($reminder, 'Reminder completed');
    }

    // =========================================================================
    // Call Log
    // =========================================================================

    public function getCallLog(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $db = $this->dealService->getDb();

        // Manual call log entries
        $stmt = $db->prepare('SELECT * FROM crm_call_log WHERE client_id = ? AND user_email = ? ORDER BY call_date DESC');
        $stmt->execute([$clientId, $this->userEmail]);
        $manualCalls = $stmt->fetchAll();

        foreach ($manualCalls as &$c) {
            $c['source'] = 'manual';
            $c['sort_date'] = $c['call_date'];
        }
        unset($c);

        // Portal video calls
        $portalCalls = [];
        try {
            $stmt = $db->prepare('
                SELECT id, client_id, created_by, room_name, call_type, status,
                       scheduled_at, started_at, ended_at, duration_seconds,
                       had_screen_share, notes, chat_transcript, created_at
                FROM portal_calls
                WHERE client_id = ?
                ORDER BY created_at DESC
                LIMIT 50
            ');
            $stmt->execute([$clientId]);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $portalCalls[] = [
                    'id' => 'portal_' . $row['id'],
                    'source' => 'portal',
                    'client_id' => $row['client_id'],
                    'direction' => 'outbound',
                    'call_type' => $row['call_type'],
                    'status' => $row['status'],
                    'outcome' => $row['status'] === 'ended' ? 'connected' : ($row['status'] === 'cancelled' ? 'cancelled' : 'active'),
                    'duration_minutes' => $row['duration_seconds'] ? round($row['duration_seconds'] / 60, 1) : null,
                    'notes' => $row['notes'],
                    'has_transcript' => !empty($row['chat_transcript']),
                    'had_screen_share' => (bool)$row['had_screen_share'],
                    'created_by' => $row['created_by'],
                    'room_name' => $row['room_name'],
                    'scheduled_at' => $row['scheduled_at'],
                    'started_at' => $row['started_at'],
                    'ended_at' => $row['ended_at'],
                    'call_date' => $row['started_at'] ?? $row['scheduled_at'] ?? $row['created_at'],
                    'sort_date' => $row['started_at'] ?? $row['scheduled_at'] ?? $row['created_at'],
                    'created_at' => $row['created_at'],
                ];
            }
        } catch (\Throwable $e) {
            // portal_calls table may not exist yet
        }

        $all = array_merge($manualCalls, $portalCalls);
        usort($all, fn($a, $b) => strcmp($b['sort_date'] ?? '', $a['sort_date'] ?? ''));

        return Response::success(['calls' => $all]);
    }

    public function createCallLog(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $data = $request->input();

        $db = $this->dealService->getDb();
        $stmt = $db->prepare('
            INSERT INTO crm_call_log (client_id, user_email, contact_id, direction, duration_minutes, outcome, notes, call_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $clientId,
            $this->userEmail,
            $data['contact_id'] ?? null,
            $data['direction'] ?? 'outbound',
            $data['duration_minutes'] ?? null,
            $data['outcome'] ?? 'connected',
            $data['notes'] ?? null,
            $data['call_date'] ?? date('Y-m-d H:i:s'),
        ]);

        $id = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM crm_call_log WHERE id = ?');
        $stmt->execute([$id]);
        return Response::success($stmt->fetch(), 'Call logged');
    }

    // =========================================================================
    // Meeting Notes
    // =========================================================================

    public function getMeetingNotes(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $db = $this->dealService->getDb();

        $stmt = $db->prepare('SELECT * FROM crm_meeting_notes WHERE client_id = ? AND user_email = ? ORDER BY meeting_date DESC');
        $stmt->execute([$clientId, $this->userEmail]);

        $notes = $stmt->fetchAll();
        foreach ($notes as &$n) {
            if ($n['attendees']) $n['attendees'] = json_decode($n['attendees'], true);
            if ($n['action_items']) $n['action_items'] = json_decode($n['action_items'], true);
        }

        return Response::success(['notes' => $notes]);
    }

    public function createMeetingNote(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $data = $request->input();

        if (empty($data['title'])) {
            return Response::badRequest('Title is required');
        }

        $db = $this->dealService->getDb();
        $stmt = $db->prepare('
            INSERT INTO crm_meeting_notes (client_id, user_email, title, content, meeting_date, attendees, action_items, deal_id, portal_call_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $clientId,
            $this->userEmail,
            $data['title'],
            $data['content'] ?? null,
            $data['meeting_date'] ?? date('Y-m-d H:i:s'),
            isset($data['attendees']) ? json_encode($data['attendees']) : null,
            isset($data['action_items']) ? json_encode($data['action_items']) : null,
            $data['deal_id'] ?? null,
            $data['portal_call_id'] ?? null,
        ]);

        $id = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM crm_meeting_notes WHERE id = ?');
        $stmt->execute([$id]);
        $note = $stmt->fetch();
        if ($note['attendees']) $note['attendees'] = json_decode($note['attendees'], true);
        if ($note['action_items']) $note['action_items'] = json_decode($note['action_items'], true);

        return Response::success($note, 'Meeting note created');
    }

    public function updateMeetingNote(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $noteId = (int)$request->getParam('noteId');
        $data = $request->input();
        $db = $this->dealService->getDb();

        $fields = [];
        $params = [];
        foreach (['title', 'content', 'meeting_date', 'deal_id', 'portal_call_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }
        if (array_key_exists('attendees', $data)) {
            $fields[] = 'attendees = ?';
            $params[] = json_encode($data['attendees']);
        }
        if (array_key_exists('action_items', $data)) {
            $fields[] = 'action_items = ?';
            $params[] = json_encode($data['action_items']);
        }

        if (empty($fields)) return Response::badRequest('No fields to update');

        $params[] = $noteId;
        $params[] = $this->userEmail;
        $db->prepare("UPDATE crm_meeting_notes SET " . implode(', ', $fields) . " WHERE id = ? AND user_email = ?")
            ->execute($params);

        $stmt = $db->prepare('SELECT * FROM crm_meeting_notes WHERE id = ?');
        $stmt->execute([$noteId]);
        $note = $stmt->fetch();
        if (!$note) return Response::notFound('Note not found');
        if ($note['attendees']) $note['attendees'] = json_decode($note['attendees'], true);
        if ($note['action_items']) $note['action_items'] = json_decode($note['action_items'], true);

        return Response::success($note, 'Meeting note updated');
    }

    // =========================================================================
    // Timeline
    // =========================================================================

    /**
     * Get unified timeline for a client
     * GET /clients/{id}/timeline?limit=50&offset=0
     */
    public function getTimeline(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $limit = (int)($request->getQuery('limit') ?? 50);
        $offset = (int)($request->getQuery('offset') ?? 0);

        $result = $this->timelineService->getTimeline($clientId, $this->userEmail, $limit, $offset);

        return Response::success($result);
    }

    // =========================================================================
    // Dashboard & Reporting
    // =========================================================================

    /**
     * Get CRM dashboard data
     * GET /crm/dashboard
     */
    public function getDashboard(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $db = $this->dealService->getDb();

        // Deal summary
        $dealSummary = $this->dealService->getSummary($this->userEmail);

        // Recent activity (last 10 events across all clients)
        $recentActivity = [];
        try {
            // Recent deals
            $stmt = $db->prepare('SELECT id, title, pipeline_stage, updated_at FROM crm_deals WHERE user_email = ? ORDER BY updated_at DESC LIMIT 5');
            $stmt->execute([$this->userEmail]);
            foreach ($stmt->fetchAll() as $d) {
                $recentActivity[] = [
                    'type' => 'deal',
                    'title' => $d['title'],
                    'detail' => "Stage: {$d['pipeline_stage']}",
                    'date' => $d['updated_at'],
                ];
            }
            // Recent invoices
            $stmt = $db->prepare('SELECT id, invoice_number, status, total, currency, updated_at FROM crm_invoices WHERE user_email = ? ORDER BY updated_at DESC LIMIT 5');
            $stmt->execute([$this->userEmail]);
            foreach ($stmt->fetchAll() as $inv) {
                $recentActivity[] = [
                    'type' => 'invoice',
                    'title' => "Invoice #{$inv['invoice_number']}",
                    'detail' => "{$inv['status']} - {$inv['total']} {$inv['currency']}",
                    'date' => $inv['updated_at'],
                ];
            }
        } catch (\Throwable $e) {}

        usort($recentActivity, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
        $recentActivity = array_slice($recentActivity, 0, 10);

        // Upcoming reminders
        $upcomingReminders = $this->reminderService->listReminders($this->userEmail, ['is_completed' => '0']);
        $upcomingReminders = array_slice($upcomingReminders, 0, 5);

        // Invoice stats
        $invoiceStats = ['outstanding' => 0, 'overdue' => 0, 'paid_this_month' => 0];
        try {
            $stmt = $db->prepare('SELECT SUM(total - paid_amount) as outstanding FROM crm_invoices WHERE user_email = ? AND status NOT IN ("paid","cancelled","refunded")');
            $stmt->execute([$this->userEmail]);
            $invoiceStats['outstanding'] = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $db->prepare('SELECT SUM(total - paid_amount) as overdue FROM crm_invoices WHERE user_email = ? AND status = "overdue"');
            $stmt->execute([$this->userEmail]);
            $invoiceStats['overdue'] = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $db->prepare('SELECT SUM(amount) as total FROM crm_invoice_payments WHERE recorded_by = ? AND payment_date >= DATE_FORMAT(NOW(), "%Y-%m-01")');
            $stmt->execute([$this->userEmail]);
            $invoiceStats['paid_this_month'] = (float)($stmt->fetchColumn() ?: 0);
        } catch (\Throwable $e) {}

        return Response::success([
            'deals' => $dealSummary,
            'recent_activity' => $recentActivity,
            'upcoming_reminders' => $upcomingReminders,
            'invoice_stats' => $invoiceStats,
        ]);
    }

    /**
     * Get revenue report data
     * GET /crm/reports/revenue?period=monthly&months=12
     */
    public function getRevenueReport(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $months = (int)($request->getQuery('months') ?? 12);
        $db = $this->dealService->getDb();

        $revenue = [];
        try {
            $stmt = $db->prepare("
                SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
                       SUM(amount) as total
                FROM crm_invoice_payments
                WHERE recorded_by = ? AND payment_date >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                ORDER BY month ASC
            ");
            $stmt->execute([$this->userEmail, $months]);
            $revenue = $stmt->fetchAll();
        } catch (\Throwable $e) {}

        $invoiced = [];
        try {
            $stmt = $db->prepare("
                SELECT DATE_FORMAT(issue_date, '%Y-%m') as month,
                       SUM(total) as total
                FROM crm_invoices
                WHERE user_email = ? AND issue_date >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                GROUP BY DATE_FORMAT(issue_date, '%Y-%m')
                ORDER BY month ASC
            ");
            $stmt->execute([$this->userEmail, $months]);
            $invoiced = $stmt->fetchAll();
        } catch (\Throwable $e) {}

        $expenses = [];
        try {
            $stmt = $db->prepare("
                SELECT DATE_FORMAT(expense_date, '%Y-%m') as month,
                       SUM(amount) as total
                FROM crm_expenses
                WHERE user_email = ? AND expense_date >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                ORDER BY month ASC
            ");
            $stmt->execute([$this->userEmail, $months]);
            $expenses = $stmt->fetchAll();
        } catch (\Throwable $e) {}

        return Response::success([
            'revenue' => $revenue,
            'invoiced' => $invoiced,
            'expenses' => $expenses,
        ]);
    }

    /**
     * Get pipeline report data
     * GET /crm/reports/pipeline
     */
    public function getPipelineReport(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $db = $this->dealService->getDb();

        $stageStats = [];
        try {
            $stmt = $db->prepare("
                SELECT pipeline_stage, COUNT(*) as count, SUM(expected_value) as total_value
                FROM crm_deals
                WHERE user_email = ?
                GROUP BY pipeline_stage
            ");
            $stmt->execute([$this->userEmail]);
            $stageStats = $stmt->fetchAll();
        } catch (\Throwable $e) {}

        $conversionRate = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM crm_deals WHERE user_email = ?");
            $stmt->execute([$this->userEmail]);
            $totalDeals = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM crm_deals WHERE user_email = ? AND pipeline_stage = 'won'");
            $stmt->execute([$this->userEmail]);
            $wonDeals = (int)$stmt->fetchColumn();

            $conversionRate = $totalDeals > 0 ? round(($wonDeals / $totalDeals) * 100, 1) : 0;
        } catch (\Throwable $e) {}

        $recentWins = [];
        try {
            $stmt = $db->prepare("
                SELECT d.*, c.name as client_name, c.domain as client_domain
                FROM crm_deals d
                LEFT JOIN clients c ON d.client_id = c.id
                WHERE d.user_email = ? AND d.pipeline_stage = 'won'
                ORDER BY d.won_at DESC LIMIT 5
            ");
            $stmt->execute([$this->userEmail]);
            $recentWins = $stmt->fetchAll();
        } catch (\Throwable $e) {}

        return Response::success([
            'stage_stats' => $stageStats,
            'conversion_rate' => $conversionRate,
            'recent_wins' => $recentWins,
        ]);
    }

    /**
     * Get client health report
     * GET /crm/reports/health
     */
    public function getHealthReport(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $db = $this->dealService->getDb();

        $clientHealth = [];
        try {
            // Get all clients with their last activity dates
            $stmt = $db->prepare("
                SELECT c.id, c.name, c.domain,
                       (SELECT MAX(created_at) FROM portal_updates WHERE client_id = c.id) as last_update,
                       (SELECT MAX(created_at) FROM crm_invoices WHERE client_id = c.id AND user_email = ?) as last_invoice,
                       (SELECT MAX(call_date) FROM crm_call_log WHERE client_id = c.id AND user_email = ?) as last_call,
                       (SELECT MAX(meeting_date) FROM crm_meeting_notes WHERE client_id = c.id AND user_email = ?) as last_meeting,
                       (SELECT SUM(total) FROM crm_invoices WHERE client_id = c.id AND user_email = ? AND status = 'paid') as total_revenue
                FROM clients c
                ORDER BY c.name ASC
            ");
            $stmt->execute([$this->userEmail, $this->userEmail, $this->userEmail, $this->userEmail]);
            $clients = $stmt->fetchAll();

            foreach ($clients as &$client) {
                $dates = array_filter([
                    $client['last_update'],
                    $client['last_invoice'],
                    $client['last_call'],
                    $client['last_meeting'],
                ]);
                $lastActivity = $dates ? max($dates) : null;
                $client['last_activity'] = $lastActivity;

                // Health score: based on recency of interaction
                $score = 0;
                if ($lastActivity) {
                    $daysSince = (time() - strtotime($lastActivity)) / 86400;
                    if ($daysSince <= 7) $score = 100;
                    elseif ($daysSince <= 14) $score = 80;
                    elseif ($daysSince <= 30) $score = 60;
                    elseif ($daysSince <= 60) $score = 40;
                    elseif ($daysSince <= 90) $score = 20;
                    else $score = 10;
                }
                $client['health_score'] = $score;
                $client['total_revenue'] = (float)($client['total_revenue'] ?? 0);
            }

            // Sort by health score ascending (show at-risk first)
            usort($clients, fn($a, $b) => $a['health_score'] - $b['health_score']);
            $clientHealth = $clients;
        } catch (\Throwable $e) {
            error_log("Health report error: " . $e->getMessage());
        }

        return Response::success(['clients' => $clientHealth]);
    }

    // =========================================================================
    // Deal Activity
    // =========================================================================

    /**
     * Get activity feed for a specific deal (filtered timeline)
     * GET /crm/deals/{id}/activity
     */
    public function getDealActivity(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $dealId = (int)$request->getParam('id');
        if (!$dealId) return Response::badRequest('Deal ID required');

        // Get the deal to find client_id
        $deal = $this->dealService->getDeal($dealId, $this->userEmail);
        if (!$deal) return Response::notFound('Deal not found');

        $clientId = (int)($deal['client_id'] ?? 0);
        if (!$clientId) return Response::success(['activities' => [], 'deal' => $deal]);

        // Get full timeline for the client, then filter for deal-related activity
        $timeline = $this->timelineService->getTimeline($clientId, $this->userEmail, 200, 0);

        // Filter activities that reference this deal
        $dealActivities = array_filter($timeline, function ($event) use ($dealId, $deal) {
            // Include deal-specific events
            if (isset($event['deal_id']) && (int)$event['deal_id'] === $dealId) return true;
            // Include reminders tied to this deal
            if (isset($event['deal_id_ref']) && (int)$event['deal_id_ref'] === $dealId) return true;
            // Include events whose title references the deal title
            if (isset($event['title']) && isset($deal['title']) && stripos($event['title'], $deal['title']) !== false) return true;
            return false;
        });

        // Get stage history for this deal
        $stageHistory = [];
        try {
            $stmt = $this->dealService->getDb()->prepare("
                SELECT * FROM crm_deal_stage_history
                WHERE deal_id = ?
                ORDER BY changed_at DESC
            ");
            $stmt->execute([$dealId]);
            $stageHistory = $stmt->fetchAll();
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        // Merge stage history into activities
        foreach ($stageHistory as $sh) {
            $dealActivities[] = [
                'type' => 'stage_change',
                'title' => 'Stage changed' . ($sh['from_stage'] ? " from {$sh['from_stage']}" : '') . " to {$sh['to_stage']}",
                'date' => $sh['changed_at'],
                'detail' => "Changed by {$sh['changed_by']}",
            ];
        }

        // Sort by date descending
        usort($dealActivities, function ($a, $b) {
            return strtotime($b['date'] ?? $b['created_at'] ?? '0') - strtotime($a['date'] ?? $a['created_at'] ?? '0');
        });

        return Response::success([
            'activities' => array_values($dealActivities),
            'deal' => $deal,
            'stage_history' => $stageHistory,
        ]);
    }

    // =========================================================================
    // Advanced Reporting
    // =========================================================================

    /**
     * Get invoice aging report
     * GET /crm/reports/aging
     */
    public function getAgingReport(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        try {
            $data = $this->getReportService()->getAgingReport($this->userEmail);
            return Response::success($data);
        } catch (\Throwable $e) {
            error_log("CrmDealController::getAgingReport error: " . $e->getMessage());
            return Response::serverError('Failed to generate aging report');
        }
    }

    /**
     * Get client value ranking
     * GET /crm/reports/client-ranking?period=12
     */
    public function getClientRanking(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $months = (int)($request->getQuery('period') ?? 0);

        try {
            $data = $this->getReportService()->getClientRanking($this->userEmail, $months);
            return Response::success($data);
        } catch (\Throwable $e) {
            error_log("CrmDealController::getClientRanking error: " . $e->getMessage());
            return Response::serverError('Failed to generate client ranking');
        }
    }

    /**
     * Get time profitability report
     * GET /crm/reports/profitability
     */
    public function getProfitabilityReport(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        try {
            $data = $this->getReportService()->getProfitabilityReport($this->userEmail);
            return Response::success($data);
        } catch (\Throwable $e) {
            error_log("CrmDealController::getProfitabilityReport error: " . $e->getMessage());
            return Response::serverError('Failed to generate profitability report');
        }
    }

    /**
     * Get deal forecast report
     * GET /crm/reports/forecast?months=6
     */
    public function getForecastReport(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $months = (int)($request->getQuery('months') ?? 6);
        if ($months < 1) $months = 6;
        if ($months > 24) $months = 24;

        try {
            $data = $this->getReportService()->getForecastReport($this->userEmail, $months);
            return Response::success($data);
        } catch (\Throwable $e) {
            error_log("CrmDealController::getForecastReport error: " . $e->getMessage());
            return Response::serverError('Failed to generate forecast report');
        }
    }

    /**
     * Get conversion funnel report
     * GET /crm/reports/funnel
     */
    public function getFunnelReport(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        try {
            $data = $this->getReportService()->getFunnelReport($this->userEmail);
            return Response::success($data);
        } catch (\Throwable $e) {
            error_log("CrmDealController::getFunnelReport error: " . $e->getMessage());
            return Response::serverError('Failed to generate funnel report');
        }
    }

    // =========================================================================
    // Field Definitions (aliased from custom-fields for simpler API)
    // =========================================================================

    public function listFields(Request $request): Response
    {
        return $this->listFieldDefinitions($request);
    }

    public function createField(Request $request): Response
    {
        return $this->createFieldDefinition($request);
    }

    public function updateField(Request $request): Response
    {
        return $this->updateFieldDefinition($request);
    }

    public function deleteField(Request $request): Response
    {
        return $this->deleteFieldDefinition($request);
    }

    public function saveFieldValues(Request $request): Response
    {
        return $this->setClientFieldValues($request);
    }
}

