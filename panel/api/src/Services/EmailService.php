<?php

namespace VpsAdmin\Api\Services;

use VpsAdmin\Api\Core\Container;

/**
 * Email Service
 * 
 * Handles sending emails for billing reminders and notifications.
 * Uses PHP mail() function by default, can be extended for SMTP.
 */
class EmailService
{
    private Container $container;
    private \PDO $db;
    private array $settings = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
        $this->loadSettings();
    }

    /**
     * Load billing settings from database
     */
    private function loadSettings(): void
    {
        $stmt = $this->db->query("SELECT setting_key, setting_value FROM billing_settings");
        $rows = $stmt->fetchAll();
        
        foreach ($rows as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    /**
     * Send a payment reminder email to client
     */
    public function sendPaymentReminder(array $subscription, string $reminderType = 'reminder_30'): bool
    {
        $clientEmail = $subscription['client_email'];
        $clientName = $subscription['client_name'];
        $planName = $subscription['plan_name'];
        $amount = $subscription['amount'];
        $currency = $subscription['currency'] ?? 'HUF';
        $dueDate = $subscription['next_due_date'];
        
        // Check if already sent
        if ($this->wasEmailSent($subscription['client_id'], $subscription['id'], $reminderType)) {
            return false;
        }
        
        $subject = $this->getReminderSubject($reminderType, $planName);
        $body = $this->getReminderBody($reminderType, [
            'client_name' => $clientName,
            'plan_name' => $planName,
            'amount' => $this->formatCurrency($amount, $currency),
            'due_date' => $this->formatDate($dueDate),
            'days' => $this->getDaysUntilDue($dueDate),
        ]);
        
        $success = $this->sendEmail($clientEmail, $subject, $body);
        
        if ($success) {
            $this->logEmail($subscription['client_id'], $subscription['id'], $reminderType, $clientEmail);
        }
        
        return $success;
    }

    /**
     * Send admin summary of upcoming/overdue payments
     */
    public function sendAdminSummary(array $upcoming, array $overdue): bool
    {
        $adminEmail = $this->settings['admin_email'] ?? '';
        
        if (empty($adminEmail)) {
            return false;
        }
        
        $subject = 'VPS Admin - Billing Summary';
        
        $body = $this->getAdminSummaryBody([
            'upcoming_count' => count($upcoming),
            'overdue_count' => count($overdue),
            'upcoming' => $upcoming,
            'overdue' => $overdue,
            'upcoming_total' => array_reduce($upcoming, fn($c, $s) => $c + $s['amount'], 0),
            'overdue_total' => array_reduce($overdue, fn($c, $s) => $c + $s['amount'], 0),
        ]);
        
        return $this->sendEmail($adminEmail, $subject, $body);
    }

    /**
     * Send email using PHP mail()
     */
    private function sendEmail(string $to, string $subject, string $body): bool
    {
        $fromName = $this->settings['email_from_name'] ?? 'VPS Admin';
        $fromEmail = $this->settings['email_from_address'] ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        
        $headers = [
            'From' => "{$fromName} <{$fromEmail}>",
            'Reply-To' => $fromEmail,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Mailer' => 'VPS Admin Panel',
        ];
        
        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= "{$key}: {$value}\r\n";
        }
        
        return @mail($to, $subject, $body, $headerString);
    }

    /**
     * Check if email was already sent
     */
    private function wasEmailSent(int $clientId, int $subscriptionId, string $emailType): bool
    {
        // For reminders, check if sent within the appropriate window
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM billing_emails 
            WHERE client_id = ? 
            AND subscription_id = ? 
            AND email_type = ?
            AND sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$clientId, $subscriptionId, $emailType]);
        
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Log sent email
     */
    private function logEmail(int $clientId, ?int $subscriptionId, string $emailType, string $sentTo): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO billing_emails (client_id, subscription_id, email_type, sent_to)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$clientId, $subscriptionId, $emailType, $sentTo]);
    }

    /**
     * Get reminder email subject
     */
    private function getReminderSubject(string $type, string $planName): string
    {
        switch ($type) {
            case 'reminder_30':
                return "Payment Reminder - {$planName} subscription";
            case 'reminder_7':
                return "Urgent: Payment Due Soon - {$planName}";
            case 'overdue':
                return "OVERDUE: Payment Required - {$planName}";
            default:
                return "Payment Notice - {$planName}";
        }
    }

    /**
     * Get reminder email body HTML
     */
    private function getReminderBody(string $type, array $data): string
    {
        $urgencyText = match($type) {
            'reminder_30' => 'This is a friendly reminder that your subscription payment is due soon.',
            'reminder_7' => 'Your subscription payment is due in just a few days.',
            'overdue' => 'Your subscription payment is now overdue. Please make payment as soon as possible to avoid service interruption.',
            default => 'A payment is due for your subscription.',
        };
        
        $urgencyColor = match($type) {
            'overdue' => '#dc2626',
            'reminder_7' => '#f59e0b',
            default => '#3b82f6',
        };
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, {$urgencyColor}, #1e40af); padding: 30px; border-radius: 12px 12px 0 0; text-align: center;">
        <h1 style="color: white; margin: 0; font-size: 24px;">Payment Reminder</h1>
    </div>
    
    <div style="background: #fff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 12px 12px;">
        <p style="font-size: 16px;">Hello <strong>{$data['client_name']}</strong>,</p>
        
        <p style="font-size: 16px;">{$urgencyText}</p>
        
        <div style="background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">Subscription:</td>
                    <td style="padding: 8px 0; text-align: right; font-weight: 600;">{$data['plan_name']}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">Amount Due:</td>
                    <td style="padding: 8px 0; text-align: right; font-weight: 600; font-size: 18px; color: {$urgencyColor};">{$data['amount']}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">Due Date:</td>
                    <td style="padding: 8px 0; text-align: right; font-weight: 600;">{$data['due_date']}</td>
                </tr>
            </table>
        </div>
        
        <p style="font-size: 14px; color: #6b7280;">
            If you have already made this payment, please disregard this notice.
        </p>
        
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;">
        
        <p style="font-size: 12px; color: #9ca3af; text-align: center;">
            This is an automated message from VPS Admin Panel.<br>
            Please do not reply to this email.
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get admin summary email body
     */
    private function getAdminSummaryBody(array $data): string
    {
        $upcomingRows = '';
        foreach ($data['upcoming'] as $sub) {
            $upcomingRows .= "<tr>
                <td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$sub['client_name']}</td>
                <td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$sub['plan_name']}</td>
                <td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$this->formatCurrency($sub['amount'])}</td>
                <td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$this->formatDate($sub['next_due_date'])}</td>
            </tr>";
        }
        
        $overdueRows = '';
        foreach ($data['overdue'] as $sub) {
            $overdueRows .= "<tr>
                <td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$sub['client_name']}</td>
                <td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$sub['plan_name']}</td>
                <td style='padding: 8px; border-bottom: 1px solid #e5e7eb; color: #dc2626;'>{$this->formatCurrency($sub['amount'])}</td>
                <td style='padding: 8px; border-bottom: 1px solid #e5e7eb; color: #dc2626;'>{$sub['days_overdue']} days</td>
            </tr>";
        }
        
        $date = date('Y-m-d H:i');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #3b82f6, #1e40af); padding: 30px; border-radius: 12px 12px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 24px;">Billing Summary</h1>
        <p style="color: rgba(255,255,255,0.8); margin: 5px 0 0;">{$date}</p>
    </div>
    
    <div style="background: #fff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 12px 12px;">
        <!-- Summary Cards -->
        <div style="display: flex; gap: 20px; margin-bottom: 30px;">
            <div style="flex: 1; background: #fef2f2; padding: 20px; border-radius: 8px; text-align: center;">
                <p style="margin: 0; color: #dc2626; font-size: 32px; font-weight: bold;">{$data['overdue_count']}</p>
                <p style="margin: 5px 0 0; color: #991b1b;">Overdue</p>
                <p style="margin: 5px 0 0; color: #dc2626; font-weight: 600;">{$this->formatCurrency($data['overdue_total'])}</p>
            </div>
            <div style="flex: 1; background: #fffbeb; padding: 20px; border-radius: 8px; text-align: center;">
                <p style="margin: 0; color: #f59e0b; font-size: 32px; font-weight: bold;">{$data['upcoming_count']}</p>
                <p style="margin: 5px 0 0; color: #92400e;">Due in 30 days</p>
                <p style="margin: 5px 0 0; color: #f59e0b; font-weight: 600;">{$this->formatCurrency($data['upcoming_total'])}</p>
            </div>
        </div>
        
        <!-- Overdue Section -->
        <h2 style="color: #dc2626; font-size: 18px; margin-bottom: 15px;">
            Overdue Payments ({$data['overdue_count']})
        </h2>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <thead>
                <tr style="background: #f3f4f6;">
                    <th style="padding: 10px; text-align: left;">Client</th>
                    <th style="padding: 10px; text-align: left;">Plan</th>
                    <th style="padding: 10px; text-align: left;">Amount</th>
                    <th style="padding: 10px; text-align: left;">Overdue</th>
                </tr>
            </thead>
            <tbody>
                {$overdueRows}
            </tbody>
        </table>
        
        <!-- Upcoming Section -->
        <h2 style="color: #f59e0b; font-size: 18px; margin-bottom: 15px;">
            Upcoming Payments ({$data['upcoming_count']})
        </h2>
        
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f3f4f6;">
                    <th style="padding: 10px; text-align: left;">Client</th>
                    <th style="padding: 10px; text-align: left;">Plan</th>
                    <th style="padding: 10px; text-align: left;">Amount</th>
                    <th style="padding: 10px; text-align: left;">Due Date</th>
                </tr>
            </thead>
            <tbody>
                {$upcomingRows}
            </tbody>
        </table>
        
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 30px 0 20px;">
        
        <p style="font-size: 12px; color: #9ca3af; text-align: center;">
            VPS Admin Panel - Automated Billing Summary
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Format currency
     */
    private function formatCurrency(float $amount, string $currency = 'HUF'): string
    {
        return number_format($amount, 0, ',', ' ') . ' ' . $currency;
    }

    /**
     * Format date
     */
    private function formatDate(string $date): string
    {
        return date('Y-m-d', strtotime($date));
    }

    /**
     * Get days until due date
     */
    private function getDaysUntilDue(string $dueDate): int
    {
        $diff = strtotime($dueDate) - time();
        return (int)ceil($diff / (60 * 60 * 24));
    }
}

