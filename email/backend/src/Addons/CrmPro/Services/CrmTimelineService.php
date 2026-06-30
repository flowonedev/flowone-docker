<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmTimelineService
 * 
 * Aggregates all client-related activity (portal updates, documents, invoices,
 * deals, calls, call logs, meeting notes, reminders) into a single unified timeline.
 */
class CrmTimelineService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * Get unified timeline for a client
     * Merges events from multiple sources sorted by date DESC.
     */
    public function getTimeline(int $clientId, string $userEmail, int $limit = 50, int $offset = 0): array
    {
        $events = [];

        // Portal updates
        try {
            $stmt = $this->db->prepare('
                SELECT id, "portal_update" as event_type, title as event_title,
                       CONCAT("Update: ", update_type) as event_detail,
                       created_at as event_date, created_by as event_actor
                FROM portal_updates
                WHERE client_id = ?
            ');
            $stmt->execute([$clientId]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) { /* table may not exist yet */ }

        // Portal documents
        try {
            $stmt = $this->db->prepare('
                SELECT id, "portal_document" as event_type, title as event_title,
                       CONCAT("Document: ", status) as event_detail,
                       created_at as event_date, uploaded_by as event_actor
                FROM portal_documents
                WHERE client_id = ?
            ');
            $stmt->execute([$clientId]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Portal calls
        try {
            $stmt = $this->db->prepare('
                SELECT id, "portal_call" as event_type,
                       CONCAT(call_type, " call") as event_title,
                       CONCAT("Status: ", status) as event_detail,
                       created_at as event_date, created_by as event_actor
                FROM portal_calls
                WHERE client_id = ?
            ');
            $stmt->execute([$clientId]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Invoices
        try {
            $stmt = $this->db->prepare('
                SELECT id, "crm_invoice" as event_type, invoice_number as event_title,
                       CONCAT("Invoice ", status, " - ", total, " ", currency) as event_detail,
                       created_at as event_date, user_email as event_actor
                FROM crm_invoices
                WHERE client_id = ? AND user_email = ?
            ');
            $stmt->execute([$clientId, $userEmail]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Deals
        try {
            $stmt = $this->db->prepare('
                SELECT id, "crm_deal" as event_type, title as event_title,
                       CONCAT("Deal: ", pipeline_stage) as event_detail,
                       created_at as event_date, user_email as event_actor
                FROM crm_deals
                WHERE client_id = ? AND user_email = ?
            ');
            $stmt->execute([$clientId, $userEmail]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Call log
        try {
            $stmt = $this->db->prepare('
                SELECT id, "crm_call_log" as event_type,
                       CONCAT(direction, " phone call") as event_title,
                       CONCAT("Outcome: ", outcome, COALESCE(CONCAT(" - ", duration_minutes, "m"), "")) as event_detail,
                       call_date as event_date, user_email as event_actor
                FROM crm_call_log
                WHERE client_id = ? AND user_email = ?
            ');
            $stmt->execute([$clientId, $userEmail]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Meeting notes
        try {
            $stmt = $this->db->prepare('
                SELECT id, "crm_meeting_note" as event_type, title as event_title,
                       "Meeting note" as event_detail,
                       meeting_date as event_date, user_email as event_actor
                FROM crm_meeting_notes
                WHERE client_id = ? AND user_email = ?
            ');
            $stmt->execute([$clientId, $userEmail]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Reminders completed
        try {
            $stmt = $this->db->prepare('
                SELECT id, "crm_reminder" as event_type, title as event_title,
                       IF(is_completed, "Completed", CONCAT("Due: ", remind_at)) as event_detail,
                       COALESCE(completed_at, remind_at) as event_date, user_email as event_actor
                FROM crm_reminders
                WHERE client_id = ? AND user_email = ?
            ');
            $stmt->execute([$clientId, $userEmail]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Deal stage transitions
        try {
            $stmt = $this->db->prepare('
                SELECT h.id, "deal_stage_change" as event_type,
                       CONCAT(d.title, ": ", COALESCE(h.from_stage, "new"), " → ", h.to_stage) as event_title,
                       CONCAT("Deal stage changed") as event_detail,
                       h.changed_at as event_date, h.changed_by as event_actor
                FROM crm_deal_stage_history h
                INNER JOIN crm_deals d ON d.id = h.deal_id
                WHERE d.client_id = ? AND d.user_email = ?
            ');
            $stmt->execute([$clientId, $userEmail]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Automation log entries
        try {
            $stmt = $this->db->prepare('
                SELECT l.id, "automation_action" as event_type,
                       COALESCE(r.name, "Automation") as event_title,
                       CONCAT(l.action_taken, ": ", COALESCE(l.result_detail, "")) as event_detail,
                       l.created_at as event_date, l.user_email as event_actor
                FROM crm_automation_log l
                LEFT JOIN crm_automation_rules r ON r.id = l.rule_id
                WHERE l.target_type IN ("deal", "client")
                  AND l.user_email = ?
                  AND (
                      (l.target_type = "client" AND l.target_id = ?)
                      OR (l.target_type = "deal" AND l.target_id IN (
                          SELECT id FROM crm_deals WHERE client_id = ? AND user_email = ?
                      ))
                  )
            ');
            $stmt->execute([$userEmail, $clientId, $clientId, $userEmail]);
            $events = array_merge($events, $stmt->fetchAll());
        } catch (\Throwable $e) {}

        // Sort by event_date descending
        usort($events, function ($a, $b) {
            return strtotime($b['event_date'] ?? '0') - strtotime($a['event_date'] ?? '0');
        });

        $total = count($events);
        $events = array_slice($events, $offset, $limit);

        return [
            'events' => $events,
            'total' => $total,
        ];
    }
}

