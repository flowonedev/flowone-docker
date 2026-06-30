<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmReminderService
 * 
 * Manages follow-up reminders for clients: CRUD, completion, recurring, and due queries.
 */
class CrmReminderService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    public function listReminders(string $userEmail, array $filters = []): array
    {
        $where = ['r.user_email = ?'];
        $params = [$userEmail];

        if (!empty($filters['client_id'])) {
            $where[] = 'r.client_id = ?';
            $params[] = (int)$filters['client_id'];
        }
        if (isset($filters['is_completed'])) {
            $where[] = 'r.is_completed = ?';
            $params[] = (int)$filters['is_completed'];
        }
        if (!empty($filters['due_before'])) {
            $where[] = 'r.remind_at <= ?';
            $params[] = $filters['due_before'];
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT r.*
            FROM crm_reminders r
            WHERE {$whereClause}
            ORDER BY r.is_completed ASC, r.remind_at ASC
        ");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function createReminder(string $userEmail, array $data): array
    {
        $stmt = $this->db->prepare('
            INSERT INTO crm_reminders (client_id, user_email, title, description, remind_at, is_recurring, recurrence_interval, contact_id, deal_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            (int)$data['client_id'],
            $userEmail,
            $data['title'],
            $data['description'] ?? null,
            $data['remind_at'],
            (int)($data['is_recurring'] ?? 0),
            $data['recurrence_interval'] ?? null,
            $data['contact_id'] ?? null,
            $data['deal_id'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT * FROM crm_reminders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function updateReminder(int $id, string $userEmail, array $data): ?array
    {
        $fields = [];
        $params = [];
        $allowed = ['title', 'description', 'remind_at', 'is_recurring', 'recurrence_interval', 'contact_id', 'deal_id'];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) return null;

        $params[] = $id;
        $params[] = $userEmail;
        $this->db->prepare("UPDATE crm_reminders SET " . implode(', ', $fields) . " WHERE id = ? AND user_email = ?")
            ->execute($params);

        $stmt = $this->db->prepare('SELECT * FROM crm_reminders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function deleteReminder(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare('DELETE FROM crm_reminders WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    public function completeReminder(int $id, string $userEmail): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM crm_reminders WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        $reminder = $stmt->fetch();

        if (!$reminder) return null;

        $this->db->prepare('UPDATE crm_reminders SET is_completed = 1, completed_at = NOW() WHERE id = ?')
            ->execute([$id]);

        // If recurring, create next occurrence
        if ($reminder['is_recurring'] && $reminder['recurrence_interval']) {
            $nextDate = $this->calculateNextDate($reminder['remind_at'], $reminder['recurrence_interval']);
            if ($nextDate) {
                $this->createReminder($userEmail, [
                    'client_id' => $reminder['client_id'],
                    'title' => $reminder['title'],
                    'description' => $reminder['description'],
                    'remind_at' => $nextDate,
                    'is_recurring' => 1,
                    'recurrence_interval' => $reminder['recurrence_interval'],
                    'contact_id' => $reminder['contact_id'],
                    'deal_id' => $reminder['deal_id'],
                ]);
            }
        }

        $stmt = $this->db->prepare('SELECT * FROM crm_reminders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Get upcoming due reminders (for cron/notification)
     */
    public function getDueReminders(string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM crm_reminders
            WHERE user_email = ? AND is_completed = 0 AND remind_at <= NOW() AND notification_sent = 0
            ORDER BY remind_at ASC
        ');
        $stmt->execute([$userEmail]);
        return $stmt->fetchAll();
    }

    /**
     * Mark reminder notifications as sent
     */
    public function markNotificationSent(array $ids): void
    {
        if (empty($ids)) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->prepare("UPDATE crm_reminders SET notification_sent = 1 WHERE id IN ({$placeholders})")
            ->execute($ids);
    }

    private function calculateNextDate(string $date, string $interval): ?string
    {
        $map = [
            'daily' => '+1 day',
            'weekly' => '+1 week',
            'biweekly' => '+2 weeks',
            'monthly' => '+1 month',
        ];
        $mod = $map[$interval] ?? null;
        if (!$mod) return null;
        return date('Y-m-d H:i:s', strtotime($date . ' ' . $mod));
    }
}

