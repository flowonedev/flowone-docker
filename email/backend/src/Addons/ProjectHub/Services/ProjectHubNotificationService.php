<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;
use Webmail\Addons\EmailTracking\Services\TrackingService;
use Webmail\Services\RedisCacheService;

class ProjectHubNotificationService
{
    private PDO $db;
    private array $config;
    private ?TrackingService $trackingService = null;
    private ?RedisCacheService $redisCache = null;
    private ?NotificationRecipientResolver $recipientResolver = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    private function getTrackingService(): TrackingService
    {
        if (!$this->trackingService) {
            $this->trackingService = new TrackingService($this->config);
        }
        return $this->trackingService;
    }

    private function getRedisCache(): RedisCacheService
    {
        if (!$this->redisCache) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }

    private function getRecipientResolver(): NotificationRecipientResolver
    {
        if (!$this->recipientResolver) {
            $this->recipientResolver = new NotificationRecipientResolver($this->config);
        }
        return $this->recipientResolver;
    }

    /**
     * Look up the board_id for a given card so notification deep links work.
     */
    public function getCardBoardId(int $cardId): ?int
    {
        $stmt = $this->db->prepare("SELECT c.list_id, l.board_id FROM webmail_board_cards c JOIN webmail_board_lists l ON l.id = c.list_id WHERE c.id = ?");
        $stmt->execute([$cardId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['board_id'] : null;
    }

    /**
     * Send a PH notification to all card recipients (assignees + watchers),
     * excluding the actor.
     */
    public function notifyCard(int $cardId, string $actorEmail, string $type, string $title, string $message, array $extraData = []): void
    {
        $this->notifyCardAudience($cardId, $actorEmail, ['assignees', 'watchers'], $type, $title, $message, $extraData);
    }

    /**
     * @param array<int, string> $sources Subset of assignees, watchers
     */
    public function notifyCardAudience(int $cardId, string $actorEmail, array $sources, string $type, string $title, string $message, array $extraData = []): void
    {
        $recipients = $this->getRecipientResolver()->resolve($cardId, $actorEmail, $sources, [], true);
        $actorLower = strtolower($actorEmail);

        $boardId = $this->getCardBoardId($cardId);
        $data = array_merge([
            'card_id' => $cardId,
            'board_id' => $boardId,
            'actor' => $actorLower,
            'ph_type' => $type,
        ], $extraData);

        foreach ($recipients as $email) {
            $this->deliverToUser($email, $type, $title, $message, $data);
        }
    }

    /**
     * Notify assignees ∪ watchers ∪ extra emails (e.g. share created → watchers already included; extras empty).
     *
     * @param array<int, string> $additionalEmails
     */
    public function notifyCardWithExtras(
        int $cardId,
        string $actorEmail,
        string $type,
        string $title,
        string $message,
        array $extraData,
        array $additionalEmails
    ): void {
        $recipients = $this->getRecipientResolver()->resolve(
            $cardId,
            $actorEmail,
            ['assignees', 'watchers'],
            $additionalEmails,
            true
        );
        $actorLower = strtolower($actorEmail);
        $boardId = $this->getCardBoardId($cardId);
        $data = array_merge([
            'card_id' => $cardId,
            'board_id' => $boardId,
            'actor' => $actorLower,
            'ph_type' => $type,
        ], $extraData);

        foreach ($recipients as $email) {
            $this->deliverToUser($email, $type, $title, $message, $data);
        }
    }

    /**
     * @param array<int, string> $mentionedEmails
     */
    public function notifyCommentWithMentions(
        int $cardId,
        string $actorEmail,
        string $commentTitle,
        string $commentMessage,
        array $commentExtra,
        array $mentionedEmails
    ): void {
        $split = $this->getRecipientResolver()->resolveCommentAndMentionSplit($cardId, $actorEmail, $mentionedEmails);
        $actorLower = strtolower($actorEmail);
        $boardId = $this->getCardBoardId($cardId);
        $baseData = array_merge([
            'card_id' => $cardId,
            'board_id' => $boardId,
            'actor' => $actorLower,
            'ph_type' => 'ph_comment_added',
        ], $commentExtra);

        foreach ($split['comment_recipients'] as $email) {
            $this->deliverToUser($email, 'ph_comment_added', $commentTitle, $commentMessage, $baseData);
        }

        $mentionData = array_merge($baseData, ['ph_type' => 'ph_mention']);
        foreach ($split['mention_only'] as $email) {
            $this->deliverToUser($email, 'ph_mention', $commentTitle, $commentMessage, $mentionData);
        }
    }

    /**
     * Send notification to a specific user.
     */
    public function notifyUser(string $targetEmail, string $actorEmail, string $type, string $title, string $message, array $extraData = []): void
    {
        $target = strtolower($targetEmail);
        if ($target === strtolower($actorEmail)) return;

        $cardId = $extraData['card_id'] ?? null;
        $boardId = $cardId ? $this->getCardBoardId((int)$cardId) : null;
        $data = array_merge(['actor' => strtolower($actorEmail), 'ph_type' => $type, 'board_id' => $boardId], $extraData);

        $this->deliverToUser($target, $type, $title, $message, $data);
    }

    /**
     * Deliver notification to a user respecting per-channel preferences.
     * In-app: stored via TrackingService.
     * Push: published to Redis so Node.js mailsync sends web push.
     * Email: published with email flag for Node.js to dispatch.
     */
    private function deliverToUser(string $email, string $type, string $title, string $message, array $data): void
    {
        $channels = $this->getChannelPrefs($email, $type);
        if (!$channels['inapp'] && !$channels['push'] && !$channels['email']) {
            return;
        }

        $notifId = null;
        if ($channels['inapp']) {
            $notifId = $this->getTrackingService()->createNotification($email, $type, $title, $message, $data);
        }

        if ($channels['push'] || $channels['email']) {
            $pushData = array_merge($data, [
                'notification_id' => $notifId,
                'channels' => $channels,
            ]);
            $this->publishToUser($email, $type, $title, $message, $pushData, $notifId ?? 0);
        }
    }

    private function publishToUser(string $email, string $type, string $title, string $message, array $data, int $notifId): void
    {
        try {
            $this->getRedisCache()->publishEvent($email, 'NOTIFICATION_CREATED', [
                'notification' => [
                    'id' => $notifId,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'created_at' => date('c'),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log("PH notification publish error: " . $e->getMessage());
        }
    }

    /**
     * Get per-channel notification preferences for a user+type.
     * Returns ['inapp' => bool, 'push' => bool, 'email' => bool].
     * Defaults: inapp=true, push=true, email=false.
     */
    private function getChannelPrefs(string $email, string $type): array
    {
        $defaults = ['inapp' => true, 'push' => true, 'email' => false];
        try {
            $stmt = $this->db->prepare("
                SELECT channel_inapp, channel_push, channel_email
                FROM projecthub_notification_prefs
                WHERE user_email = ? AND notif_type = ?
            ");
            $stmt->execute([strtolower($email), $type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'inapp' => (bool)$row['channel_inapp'],
                    'push' => (bool)$row['channel_push'],
                    'email' => (bool)$row['channel_email'],
                ];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet -- use defaults
        }
        return $defaults;
    }

    /**
     * Get card title for notification messages.
     */
    public function getCardTitle(int $cardId): string
    {
        $stmt = $this->db->prepare("SELECT title FROM webmail_board_cards WHERE id = ?");
        $stmt->execute([$cardId]);
        return $stmt->fetchColumn() ?: "Card #$cardId";
    }
}
