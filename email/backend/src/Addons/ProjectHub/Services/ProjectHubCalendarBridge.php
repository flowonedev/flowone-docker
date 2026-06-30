<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;
use Webmail\Addons\Calendar\Services\CalendarService;
use Webmail\Addons\Calendar\Services\GoogleCalendarService;

/**
 * Bridges Project Hub cards to the Calendar addon.
 *
 * Creates local calendar events from card due dates and syncs
 * them to Google Calendar via the existing GoogleCalendarService.
 */
class ProjectHubCalendarBridge
{
    private const REVERSE_SYNC_FIELDS = ['start_date', 'due_date'];

    private PDO $db;
    private array $config;
    private ?CalendarService $calendarService = null;
    private ?GoogleCalendarService $googleCalService = null;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->config = $config;
    }

    private function getCalendarService(): CalendarService
    {
        if (!$this->calendarService) {
            $this->calendarService = new CalendarService($this->config);
        }
        return $this->calendarService;
    }

    private function getGoogleCalService(): ?GoogleCalendarService
    {
        if (!$this->googleCalService) {
            try {
                $this->googleCalService = new GoogleCalendarService($this->config);
            } catch (\Throwable $e) {
                error_log("[PHCalBridge] GoogleCalendarService unavailable: " . $e->getMessage());
                return null;
            }
        }
        return $this->googleCalService;
    }

    /**
     * Get the sync mapping for a card + user.
     */
    public function getCardCalendarMap(int $cardId, string $userEmail): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM projecthub_card_calendar_map
             WHERE card_id = ? AND user_email = ?"
        );
        $stmt->execute([$cardId, strtolower($userEmail)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Enable sync for a card: creates local calendar event + maps it.
     */
    public function enableSync(int $cardId, string $userEmail, int $calendarId): ?array
    {
        $email = strtolower($userEmail);

        $existing = $this->getCardCalendarMap($cardId, $email);
        if ($existing && $existing['sync_enabled']) {
            return $existing;
        }

        $card = $this->getCard($cardId);
        if (!$card) return null;

        $eventData = $this->cardToEventData($card);
        $calService = $this->getCalendarService();
        $event = $calService->createEvent($email, $calendarId, $eventData);

        if (!$event) return null;

        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE projecthub_card_calendar_map
                 SET calendar_event_id = ?, calendar_id = ?, sync_enabled = 1, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$event['id'], $calendarId, $existing['id']]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO projecthub_card_calendar_map
                    (card_id, calendar_event_id, calendar_id, user_email, sync_enabled)
                 VALUES (?, ?, ?, ?, 1)"
            );
            $stmt->execute([$cardId, $event['id'], $calendarId, $email]);
        }

        $this->pushToGoogle($event['id'], $email);

        return $this->getCardCalendarMap($cardId, $email);
    }

    /**
     * Disable sync for a card.
     */
    public function disableSync(int $cardId, string $userEmail): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE projecthub_card_calendar_map
             SET sync_enabled = 0, updated_at = NOW()
             WHERE card_id = ? AND user_email = ?"
        );
        $stmt->execute([$cardId, strtolower($userEmail)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * When a card is updated (title, due_date, etc.), push changes to
     * the linked calendar event and Google.
     */
    public function onCardUpdated(int $cardId): void
    {
        $card = $this->getCard($cardId);
        if (!$card) return;

        $stmt = $this->db->prepare(
            "SELECT * FROM projecthub_card_calendar_map
             WHERE card_id = ? AND sync_enabled = 1"
        );
        $stmt->execute([$cardId]);
        $mappings = $stmt->fetchAll() ?: [];

        $eventData = $this->cardToEventData($card);
        $calService = $this->getCalendarService();

        foreach ($mappings as $map) {
            if (!$map['calendar_event_id']) continue;

            $calService->updateEvent($map['user_email'], (int)$map['calendar_event_id'], $eventData);
            $this->pushToGoogle((int)$map['calendar_event_id'], $map['user_email']);

            $this->db->prepare(
                "UPDATE projecthub_card_calendar_map SET last_synced_at = NOW() WHERE id = ?"
            )->execute([$map['id']]);
        }
    }

    /**
     * When pulling from Google, check if any synced event was modified
     * and update the card's due date accordingly.
     */
    public function onCalendarEventUpdated(int $localEventId, array $eventData): void
    {
        $stmt = $this->db->prepare(
            "SELECT card_id FROM projecthub_card_calendar_map
             WHERE calendar_event_id = ? AND sync_enabled = 1
             LIMIT 1"
        );
        $stmt->execute([$localEventId]);
        $map = $stmt->fetch();
        if (!$map) return;

        $cardId = (int)$map['card_id'];
        $updates = [];

        $startVal = $eventData['start_date'] ?? $eventData['start_time'] ?? null;
        $endVal = $eventData['end_date'] ?? $eventData['end_time'] ?? null;

        if (!empty($startVal)) {
            $updates['start_date'] = substr($startVal, 0, 10);
        }
        if (!empty($endVal)) {
            $updates['due_date'] = substr($endVal, 0, 10);
        }

        $allowed = [];
        foreach (self::REVERSE_SYNC_FIELDS as $col) {
            if (isset($updates[$col]) && $updates[$col] !== '') {
                $allowed[$col] = $updates[$col];
            }
        }
        $updates = $allowed;

        if (!empty($updates)) {
            $sets = [];
            $params = [];
            foreach ($updates as $col => $val) {
                $sets[] = "$col = ?";
                $params[] = $val;
            }
            $params[] = $cardId;
            $this->db->prepare(
                "UPDATE webmail_board_cards SET " . implode(', ', $sets) . " WHERE id = ?"
            )->execute($params);
        }
    }

    /**
     * Try to push a local event to Google Calendar via existing sync infra.
     */
    private function pushToGoogle(int $localEventId, string $userEmail): void
    {
        $google = $this->getGoogleCalService();
        if (!$google) return;

        $stmt = $this->db->prepare(
            "SELECT css.oauth_account_id
             FROM calendar_sync_state css
             JOIN calendar_events ce ON ce.calendar_id = css.local_calendar_id
             WHERE ce.id = ? AND css.sync_enabled = 1
             LIMIT 1"
        );
        $stmt->execute([$localEventId]);
        $sync = $stmt->fetch();
        if (!$sync) return;

        try {
            $google->syncToGoogle($userEmail, (int)$sync['oauth_account_id'], $localEventId);
        } catch (\Throwable $e) {
            error_log("[PHCalBridge] Google push failed for event $localEventId: " . $e->getMessage());
        }
    }

    private function getCard(int $cardId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM webmail_board_cards WHERE id = ?");
        $stmt->execute([$cardId]);
        return $stmt->fetch() ?: null;
    }

    private function cardToEventData(array $card): array
    {
        $startDate = $card['start_date'] ?? $card['due_date'] ?? date('Y-m-d');
        $endDate = $card['due_date'] ?? $startDate;

        return [
            'title'       => '[PH] ' . ($card['title'] ?? 'Task'),
            'start_time'  => $startDate . ' 00:00:00',
            'end_time'    => $endDate . ' 23:59:59',
            'all_day'     => 1,
            'description' => $card['description'] ?? '',
            'color'       => '#6366f1',
        ];
    }
}
