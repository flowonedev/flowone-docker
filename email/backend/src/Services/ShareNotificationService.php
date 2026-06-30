<?php

namespace Webmail\Services;

use Webmail\Core\Database;
use Webmail\Addons\Team\Services\ColleagueService;

/**
 * ShareNotificationService - delivers in-app notifications when a Drive
 * share link is shared with internal colleagues / groups.
 *
 * Recipient ids are resolved to emails server-side (no arbitrary mail relay),
 * scoped to the sharer's organization. Delivery is an in-app notification
 * (notifications table) plus an optional realtime push over Redis.
 */
class ShareNotificationService
{
    private \PDO $db;
    private array $config;
    private ColleagueService $colleagues;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = Database::getConnection($config);
        $this->colleagues = new ColleagueService($config);
    }

    /**
     * Resolve colleague ids + group ids to a unique list of recipient emails,
     * scoped to the owner's organization, excluding the owner themselves.
     */
    public function resolveRecipientEmails(string $ownerEmail, array $userIds, array $groupIds): array
    {
        $emails = [];

        if (!empty($userIds)) {
            foreach ($this->colleagues->getColleaguesByIds($ownerEmail, $userIds) as $c) {
                if (!empty($c['email'])) {
                    $emails[] = strtolower($c['email']);
                }
            }
        }

        foreach ($groupIds as $gid) {
            $res = $this->colleagues->getGroupMembers($ownerEmail, (int)$gid);
            if (!empty($res['success']) && !empty($res['members'])) {
                foreach ($res['members'] as $m) {
                    if (!empty($m['email'])) {
                        $emails[] = strtolower($m['email']);
                    }
                }
            }
        }

        $emails = array_values(array_unique($emails));
        $owner = strtolower($ownerEmail);
        return array_values(array_filter($emails, static fn($e) => $e !== $owner));
    }

    /**
     * Create a 'drive_share' notification for each recipient. Returns the
     * number of notifications successfully created.
     */
    public function notify(string $sharedByEmail, string $itemName, ?string $shareUrl, array $recipientEmails, string $targetType): int
    {
        if (empty($recipientEmails)) {
            return 0;
        }

        $kind = $targetType === 'folder' ? 'folder' : 'file';
        $title = 'Shared ' . $kind . ': ' . $itemName;
        $message = $sharedByEmail . ' shared a ' . $kind . ' with you';
        $data = json_encode([
            'share_url' => $shareUrl,
            'item_name' => $itemName,
            'target_type' => $kind,
            'shared_by' => $sharedByEmail,
        ]);

        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_email, type, title, message, data)
            VALUES (?, 'drive_share', ?, ?, ?)
        ");

        $sent = 0;
        foreach ($recipientEmails as $email) {
            try {
                $stmt->execute([$email, $title, $message, $data]);
                $notifId = (int)$this->db->lastInsertId();
                $this->pushRealtime($email, $notifId, $title, $message, json_decode($data, true));
                $sent++;
            } catch (\Throwable $e) {
                error_log("ShareNotificationService notify error for {$email}: " . $e->getMessage());
            }
        }

        return $sent;
    }

    private function pushRealtime(string $userEmail, int $notifId, string $title, string $message, array $data = []): void
    {
        try {
            if (!extension_loaded('redis')) {
                return;
            }
            $redis = new \Redis();
            $host = $this->config['redis']['host'] ?? '127.0.0.1';
            $port = $this->config['redis']['port'] ?? 6379;
            $redis->connect($host, $port, 2.0);
            $password = $this->config['redis']['password'] ?? null;
            if ($password) {
                $redis->auth($password);
            }
            $prefix = $this->config['redis']['prefix'] ?? 'webmail:';

            $redis->publish($prefix . 'mailbox:' . $userEmail, json_encode([
                'type' => 'NOTIFICATION_CREATED',
                'payload' => [
                    'id' => $notifId,
                    'type' => 'drive_share',
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'is_read' => false,
                    'created_at' => date('c'),
                ],
                'timestamp' => round(microtime(true) * 1000),
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log("ShareNotificationService Redis push error: " . $e->getMessage());
        }
    }
}
