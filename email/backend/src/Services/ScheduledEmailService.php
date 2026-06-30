<?php

namespace Webmail\Services;

/**
 * Scheduled Email Service
 * 
 * Handles scheduling emails for later sending.
 * Emails are stored in the database and processed by cron.
 * 
 * Cron setup (every minute):
 *   * * * * * php /var/www/vps-email/backend/cron/process-scheduled-emails.php
 */
class ScheduledEmailService
{
    private \PDO $db;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // CRITICAL: Frontend sends UTC times (via toISOString()).
        // MySQL session must be UTC so TIMESTAMP columns interpret values correctly.
        // Without this, MySQL uses server local TZ (e.g. CET=UTC+1), causing
        // scheduled emails to fire 1 hour early.
        $this->db->exec("SET time_zone = '+00:00'");
    }
    
    /**
     * Schedule an email for later sending
     */
    public function schedule(
        string $userEmail,
        array $emailPayload,
        string $scheduledAt,
        string $timezone = 'UTC',
        string $scheduleKind = 'scheduled_send'
    ): array {
        $scheduleId = $this->generateUUID();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO scheduled_emails 
                    (schedule_id, user_email, email_payload, scheduled_at, timezone, schedule_kind, status)
                VALUES 
                    (:schedule_id, :user_email, :email_payload, :scheduled_at, :timezone, :schedule_kind, 'pending')
            ");
            
            $stmt->execute([
                'schedule_id' => $scheduleId,
                'user_email' => $userEmail,
                'email_payload' => json_encode($emailPayload),
                'scheduled_at' => $scheduledAt,
                'timezone' => $timezone,
                'schedule_kind' => $scheduleKind,
            ]);
            
            return [
                'success' => true,
                'schedule_id' => $scheduleId,
                'scheduled_at' => $scheduledAt,
            ];
        } catch (\Exception $e) {
            error_log("ScheduledEmailService::schedule error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to schedule email: ' . $e->getMessage()];
        }
    }
    
    /**
     * Cancel a scheduled email
     * Works for pending, sending (stuck), and failed emails
     */
    public function cancel(string $scheduleId, string $userEmail, bool $undoSendOnly = false): array
    {
        try {
            // For undo_send cancels, only allow cancelling while still pending
            // (once the worker claims it as 'sending', it's too late)
            if ($undoSendOnly) {
                $allowedStatuses = "('pending')";
            } else {
                $allowedStatuses = "('pending', 'sending', 'failed')";
            }

            $stmt = $this->db->prepare("
                UPDATE scheduled_emails 
                SET status = 'cancelled', cancelled_at = NOW()
                WHERE schedule_id = :schedule_id 
                  AND user_email = :user_email 
                  AND status IN {$allowedStatuses}
            ");
            
            $stmt->execute([
                'schedule_id' => $scheduleId,
                'user_email' => $userEmail,
            ]);
            
            if ($stmt->rowCount() === 0) {
                // Check if it exists but was already claimed by the worker
                if ($undoSendOnly) {
                    $check = $this->db->prepare("
                        SELECT status FROM scheduled_emails
                        WHERE schedule_id = :schedule_id AND user_email = :user_email
                    ");
                    $check->execute(['schedule_id' => $scheduleId, 'user_email' => $userEmail]);
                    $row = $check->fetch();
                    if ($row && in_array($row['status'], ['sending', 'sent'])) {
                        return ['success' => false, 'error' => 'too_late', 'message' => 'Email is already being sent'];
                    }
                }
                return ['success' => false, 'error' => 'Scheduled email not found or already sent'];
            }
            
            return ['success' => true];
        } catch (\Exception $e) {
            error_log("ScheduledEmailService::cancel error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to cancel scheduled email'];
        }
    }
    
    /**
     * Update schedule time
     * Also resets failed emails back to pending so they can be retried
     */
    public function reschedule(string $scheduleId, string $userEmail, string $newScheduledAt): array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE scheduled_emails 
                SET scheduled_at = :scheduled_at,
                    status = 'pending',
                    error_message = NULL,
                    attempts = 0
                WHERE schedule_id = :schedule_id 
                  AND user_email = :user_email 
                  AND status IN ('pending', 'failed')
            ");
            
            $stmt->execute([
                'schedule_id' => $scheduleId,
                'user_email' => $userEmail,
                'scheduled_at' => $newScheduledAt,
            ]);
            
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'Scheduled email not found or already sent'];
            }
            
            return ['success' => true, 'scheduled_at' => $newScheduledAt];
        } catch (\Exception $e) {
            error_log("ScheduledEmailService::reschedule error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to reschedule email'];
        }
    }
    
    /**
     * Get all visible scheduled emails for a user
     * Shows pending, sending (stuck), and failed so the user can act on them
     */
    public function getScheduled(string $userEmail): array
    {
        // First, recover any stuck 'sending' emails (older than 5 minutes)
        $this->recoverStuckEmails();
        
        $stmt = $this->db->prepare("
            SELECT schedule_id, scheduled_at, timezone, status, created_at, error_message,
                   JSON_EXTRACT(email_payload, '$.subject') as subject,
                   JSON_EXTRACT(email_payload, '$.to') as recipients
            FROM scheduled_emails
            WHERE user_email = :user_email
              AND status IN ('pending', 'sending', 'failed')
              AND schedule_kind = 'scheduled_send'
            ORDER BY scheduled_at ASC
        ");
        
        $stmt->execute(['user_email' => $userEmail]);
        $results = $stmt->fetchAll();
        
        // Clean up JSON string wrapping
        foreach ($results as &$row) {
            $row['subject'] = trim($row['subject'] ?? '', '"');
            $row['recipients'] = json_decode($row['recipients'] ?? '[]', true);
        }
        
        return $results;
    }
    
    /**
     * Get a single scheduled email with full payload (for viewing/editing)
     * Returns any non-terminal status so user can always view their scheduled emails
     */
    public function getScheduledById(string $scheduleId, string $userEmail): ?array
    {
        $stmt = $this->db->prepare("
            SELECT schedule_id, email_payload, scheduled_at, timezone, status, created_at, error_message
            FROM scheduled_emails
            WHERE schedule_id = :schedule_id 
              AND user_email = :user_email
              AND status IN ('pending', 'sending', 'failed')
        ");
        
        $stmt->execute([
            'schedule_id' => $scheduleId,
            'user_email' => $userEmail,
        ]);
        
        $row = $stmt->fetch();
        if (!$row) return null;
        
        $row['email_payload'] = json_decode($row['email_payload'], true);
        return $row;
    }
    
    /**
     * Recover emails stuck in 'sending' state for more than 5 minutes.
     * This handles cron crashes / process kills that leave emails in limbo.
     * Uses scheduled_at as reference: if 5+ min past send time and still 'sending', it's stuck.
     */
    public function recoverStuckEmails(): int
    {
        $stmt = $this->db->prepare("
            UPDATE scheduled_emails 
            SET status = CASE 
                    WHEN attempts >= max_attempts THEN 'failed'
                    ELSE 'pending'
                END,
                error_message = CONCAT(COALESCE(error_message, ''), ' [recovered from stuck sending state]')
            WHERE status = 'sending'
              AND scheduled_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute();
        $recovered = $stmt->rowCount();
        
        if ($recovered > 0) {
            error_log("[ScheduledEmail] Recovered {$recovered} stuck email(s) from 'sending' state");
        }
        
        return $recovered;
    }
    
    /**
     * Get due emails that need to be sent (called by cron)
     */
    public function getDueEmails(int $limit = 10): array
    {
        // First recover any stuck emails
        $this->recoverStuckEmails();
        
        $stmt = $this->db->prepare("
            SELECT id, schedule_id, user_email, email_payload, scheduled_at, timezone, attempts
            FROM scheduled_emails
            WHERE status = 'pending'
              AND scheduled_at <= NOW()
              AND attempts < max_attempts
            ORDER BY scheduled_at ASC
            LIMIT :limit
        ");
        
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Mark email as sending (lock it)
     */
    public function markSending(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE scheduled_emails 
            SET status = 'sending', attempts = attempts + 1
            WHERE id = :id AND status = 'pending'
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Mark email as sent
     */
    public function markSent(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE scheduled_emails 
            SET status = 'sent', sent_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
    }
    
    /**
     * Mark email as failed
     */
    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->db->prepare("
            UPDATE scheduled_emails 
            SET status = CASE 
                    WHEN attempts >= max_attempts THEN 'failed'
                    ELSE 'pending'
                END,
                error_message = :error
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id, 'error' => $error]);
    }
    
    /**
     * Clean up old completed/cancelled records (older than 30 days)
     */
    public function cleanup(): int
    {
        $schedDir = dirname(__DIR__, 2) . '/storage/scheduled-attachments';
        if (is_dir($schedDir)) {
            $cutoff = time() - 86400;
            foreach (glob($schedDir . '/sched_*') as $file) {
                if (filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }
        }

        $stmt = $this->db->prepare("
            DELETE FROM scheduled_emails
            WHERE status IN ('sent', 'cancelled', 'failed')
              AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    /**
     * Get count of pending scheduled emails for a user
     */
    public function getPendingCount(string $userEmail): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM scheduled_emails
            WHERE user_email = :user_email AND status = 'pending'
              AND schedule_kind = 'scheduled_send'
        ");
        $stmt->execute(['user_email' => $userEmail]);
        return (int)$stmt->fetchColumn();
    }
    
    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

