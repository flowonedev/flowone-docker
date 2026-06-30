<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

class ProjectHubTimeBudgetService
{
    private PDO $db;
    private array $config;
    private ?ProjectHubNotificationService $notificationService = null;

    private const WARNING_THRESHOLD = 0.90;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    private function getNotificationService(): ProjectHubNotificationService
    {
        if (!$this->notificationService) {
            $this->notificationService = new ProjectHubNotificationService($this->config);
        }
        return $this->notificationService;
    }

    /**
     * Check if a card has crossed the 90% time budget threshold.
     * Called after every work session log (timer stop or manual entry).
     * Sends notification to board owner + org admins if threshold just crossed.
     */
    public function checkAndAlert(int $cardId, string $actorEmail): void
    {
        try {
            $card = $this->getCardWithEstimate($cardId);
            if (!$card) return;

            $estimate = (int)($card['time_estimate_seconds'] ?? 0);
            if ($estimate <= 0) return;

            if ((int)$card['time_budget_alert_sent']) return;

            $totalTracked = $this->getTotalTrackedSeconds($cardId);
            $ratio = $totalTracked / $estimate;

            if ($ratio < self::WARNING_THRESHOLD) return;

            $this->markAlertSent($cardId);

            $recipients = $this->getBudgetAlertRecipients($cardId, $actorEmail);
            if (empty($recipients)) return;

            $cardTitle = $card['title'] ?? "Card #$cardId";
            $pct = round($ratio * 100);
            $notifService = $this->getNotificationService();

            $title = "Time budget warning: {$cardTitle}";
            $message = "Tracked time has reached {$pct}% of the {$this->formatSeconds($estimate)} estimate.";

            $boardId = $notifService->getCardBoardId($cardId);

            foreach ($recipients as $email) {
                $notifService->notifyUser(
                    $email,
                    $actorEmail,
                    'ph_time_budget_warning',
                    $title,
                    $message,
                    [
                        'card_id' => $cardId,
                        'board_id' => $boardId,
                        'estimate_seconds' => $estimate,
                        'tracked_seconds' => $totalTracked,
                        'percentage' => $pct,
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log("TimeBudgetService::checkAndAlert error: " . $e->getMessage());
        }
    }

    /**
     * Reset the alert flag when estimate changes so it can fire again.
     */
    public function resetAlert(int $cardId): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE webmail_board_cards SET time_budget_alert_sent = 0 WHERE id = ?");
            $stmt->execute([$cardId]);
        } catch (\Throwable $e) {
            error_log("TimeBudgetService::resetAlert error: " . $e->getMessage());
        }
    }

    private function getCardWithEstimate(int $cardId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.title, c.time_estimate_seconds, c.time_budget_alert_sent, l.board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getTotalTrackedSeconds(int $cardId): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(duration_seconds), 0) AS total
            FROM projecthub_work_sessions
            WHERE card_id = ?
        ");
        $stmt->execute([$cardId]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    private function markAlertSent(int $cardId): void
    {
        $stmt = $this->db->prepare("UPDATE webmail_board_cards SET time_budget_alert_sent = 1 WHERE id = ?");
        $stmt->execute([$cardId]);
    }

    /**
     * Collect board owner + domain admins as alert recipients.
     */
    private function getBudgetAlertRecipients(int $cardId, string $excludeEmail): array
    {
        $excludeLower = strtolower($excludeEmail);
        $emails = [];

        $stmt = $this->db->prepare("
            SELECT b.owner_email
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $owner = $stmt->fetchColumn();
        if ($owner) {
            $emails[] = strtolower($owner);
        }

        if ($owner) {
            $domain = substr($owner, strpos($owner, '@') + 1);
            $adminStmt = $this->db->prepare("
                SELECT email FROM organization_colleagues
                WHERE email LIKE ? AND is_admin = 1
            ");
            $adminStmt->execute(['%@' . $domain]);
            foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminEmail) {
                $emails[] = strtolower($adminEmail);
            }
        }

        $emails = array_unique($emails);
        return array_values(array_filter($emails, fn($e) => $e !== $excludeLower));
    }

    private function formatSeconds(int $seconds): string
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        if ($h > 0 && $m > 0) return "{$h}h {$m}m";
        if ($h > 0) return "{$h}h";
        return "{$m}m";
    }
}
