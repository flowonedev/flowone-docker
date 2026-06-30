<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

/**
 * Checks for stale/inactive Project Hub cards and sends alerts.
 *
 * "Inactive" = no comment, status change, or work session logged within
 * a configurable window (default 90 days). Designed to be called by a
 * daily cron job or scheduled task.
 */
class ProjectHubInactivityChecker
{
    private PDO $db;
    private array $config;
    private int $thresholdDays;

    public function __construct(array $config, int $thresholdDays = 90)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->config = $config;
        $this->thresholdDays = $thresholdDays;
    }

    /**
     * Find all open cards with no recent activity across:
     *   - card updated_at
     *   - comments (webmail_card_comments.created_at)
     *   - assignee status changes (projecthub_card_assignees.updated_at)
     *   - work sessions (projecthub_work_sessions.started_at)
     *
     * Returns card details including created_by for targeted creator alerts.
     */
    public function findInactiveCards(): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$this->thresholdDays} days"));

        $sql = "
            SELECT
                c.id AS card_id,
                c.title,
                l.board_id,
                c.created_by,
                c.updated_at AS card_updated_at,
                GREATEST(
                    COALESCE(c.updated_at, '2000-01-01'),
                    COALESCE(latest_comment.ts, '2000-01-01'),
                    COALESCE(latest_assignee.ts, '2000-01-01'),
                    COALESCE(latest_session.ts, '2000-01-01')
                ) AS last_activity_at
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            LEFT JOIN (
                SELECT card_id, MAX(created_at) AS ts
                FROM webmail_card_comments
                GROUP BY card_id
            ) latest_comment ON latest_comment.card_id = c.id
            LEFT JOIN (
                SELECT card_id, MAX(updated_at) AS ts
                FROM projecthub_card_assignees
                GROUP BY card_id
            ) latest_assignee ON latest_assignee.card_id = c.id
            LEFT JOIN (
                SELECT card_id, MAX(started_at) AS ts
                FROM projecthub_work_sessions
                GROUP BY card_id
            ) latest_session ON latest_session.card_id = c.id
            WHERE c.archived = 0
              AND c.completed = 0
            HAVING last_activity_at < ?
            ORDER BY last_activity_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Run the check and send notifications for each inactive card.
     * Alerts the task creator primarily; falls back to assignees if no creator.
     * Returns the count of alerts sent.
     */
    public function runAndNotify(): int
    {
        $inactiveCards = $this->findInactiveCards();
        if (empty($inactiveCards)) return 0;

        $notifService = new ProjectHubNotificationService($this->config);
        $workTracking = new ProjectHubWorkTrackingService($this->config);
        $count = 0;

        foreach ($inactiveCards as $card) {
            $cardId = (int)$card['card_id'];
            $daysSince = max(1, (int)round(
                (time() - strtotime($card['last_activity_at'])) / 86400
            ));

            $creator = !empty($card['created_by']) ? strtolower(trim($card['created_by'])) : null;

            if ($creator) {
                $notifService->notifyUser(
                    $creator,
                    'system',
                    'ph_inactivity',
                    "Inactive task: {$card['title']}",
                    "No activity for {$daysSince} days on \"{$card['title']}\"",
                    ['card_id' => $cardId, 'days_inactive' => $daysSince]
                );
                $count++;
            } else {
                $recipients = $workTracking->getCardNotificationRecipients($cardId);
                foreach ($recipients as $email) {
                    $notifService->notifyUser(
                        $email,
                        'system',
                        'ph_inactivity',
                        "Inactive task: {$card['title']}",
                        "No activity for {$daysSince} days on \"{$card['title']}\"",
                        ['card_id' => $cardId, 'days_inactive' => $daysSince]
                    );
                    $count++;
                }
            }
        }

        return $count;
    }
}
