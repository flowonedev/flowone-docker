<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Core\Database;
use Webmail\Services\SmtpService;

class OnboardingController extends BaseController
{
    public function __construct(array $config)
    {
        parent::__construct($config);
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTable());
    }

    /**
     * Save quiz score for the authenticated user and send notification email
     */
    public function saveQuizScore(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) {
            return Response::error('User not found', 401);
        }

        $validation = $this->validateRequired($request, ['score', 'total', 'percent']);
        if ($validation) return $validation;

        $score   = (int) $request->input('score');
        $total   = (int) $request->input('total');
        $percent = (int) $request->input('percent');
        $notifyEmail = $request->input('notify_email');

        try {
            $db = Database::getConnection($this->config);

            $stmt = $db->prepare("
                INSERT INTO onboarding_quiz_scores (email, score, total, percent, created_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    score = VALUES(score),
                    total = VALUES(total),
                    percent = VALUES(percent),
                    attempts = attempts + 1,
                    created_at = NOW()
            ");
            $stmt->execute([$email, $score, $total, $percent]);

            if ($notifyEmail) {
                $this->sendNotification($notifyEmail, $email, $score, $total, $percent);
            }

            return Response::success([
                'email'   => $email,
                'score'   => $score,
                'total'   => $total,
                'percent' => $percent,
            ], 'Quiz score saved');

        } catch (\PDOException $e) {
            error_log('[Onboarding] DB error saving quiz score: ' . $e->getMessage());
            return Response::error('Failed to save quiz score', 500);
        }
    }

    /**
     * Get quiz score for current user
     */
    public function getQuizScore(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) {
            return Response::error('User not found', 401);
        }

        try {
            $db = Database::getConnection($this->config);
            $stmt = $db->prepare("SELECT * FROM onboarding_quiz_scores WHERE email = ?");
            $stmt->execute([$email]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return Response::success($row ?: null);
        } catch (\PDOException $e) {
            return Response::error('Failed to load quiz score', 500);
        }
    }

    /**
     * Send quiz result notification using SmtpService (same pattern as CalendarInviteService, ChatService)
     */
    private function sendNotification(string $to, string $userEmail, int $score, int $total, int $percent): void
    {
        try {
            $smtp = new SmtpService($this->config['smtp'] ?? []);
            $smtp->setCredentials(
                $this->config['smtp']['username'] ?? $this->config['mail_from'] ?? 'noreply@flowone.pro',
                $this->config['smtp']['password'] ?? ''
            );

            $color = $percent >= 90 ? '#10b981' : ($percent >= 70 ? '#3b82f6' : ($percent >= 50 ? '#f59e0b' : '#ef4444'));
            $rating = $percent >= 90 ? 'Excellent' : ($percent >= 70 ? 'Good' : ($percent >= 50 ? 'Fair' : 'Needs training'));

            $bodyHtml = "
            <div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; max-width: 480px; margin: 0 auto; padding: 32px;'>
                <div style='background: linear-gradient(135deg, #1e1b4b, #312e81); border-radius: 16px; padding: 32px; color: white; text-align: center;'>
                    <h2 style='margin: 0 0 8px; font-size: 20px;'>Onboarding Quiz Completed</h2>
                    <p style='margin: 0; opacity: 0.7; font-size: 14px;'>{$userEmail}</p>
                </div>
                <div style='background: white; border: 1px solid #e5e7eb; border-radius: 16px; padding: 32px; margin-top: 16px; text-align: center;'>
                    <div style='width: 80px; height: 80px; border-radius: 50%; background: {$color}20; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;'>
                        <span style='font-size: 36px; font-weight: 700; color: {$color};'>{$percent}%</span>
                    </div>
                    <p style='font-size: 24px; font-weight: 700; margin: 0;'>{$score} / {$total}</p>
                    <p style='font-size: 14px; color: #6b7280; margin: 8px 0 0;'>correct answers</p>
                    <p style='font-size: 13px; color: {$color}; font-weight: 600; margin: 12px 0 0;'>{$rating}</p>
                </div>
                <p style='font-size: 12px; color: #9ca3af; text-align: center; margin-top: 16px;'>DEVCON Webmail Platform</p>
            </div>";

            $bodyText = "Onboarding Quiz Result for {$userEmail}: {$score}/{$total} ({$percent}%) - {$rating}";

            $result = $smtp->send([
                'to' => [['email' => $to]],
                'subject' => "Onboarding Quiz Result - {$userEmail} ({$percent}%)",
                'body_html' => $bodyHtml,
                'body_text' => $bodyText,
                'from_name' => 'DEVCON Webmail',
            ]);

            error_log('[Onboarding] Quiz notification sent to ' . $to . ' - Message ID: ' . ($result['message_id'] ?? 'unknown'));

        } catch (\Exception $e) {
            error_log('[Onboarding] Failed to send quiz notification: ' . $e->getMessage());
        }
    }

    private function ensureTable(): void
    {
        try {
            $db = Database::getConnection($this->config);
            $db->exec("
                CREATE TABLE IF NOT EXISTS onboarding_quiz_scores (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    score INT NOT NULL DEFAULT 0,
                    total INT NOT NULL DEFAULT 0,
                    percent INT NOT NULL DEFAULT 0,
                    attempts INT NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\PDOException $e) {
            error_log('[Onboarding] Table creation error: ' . $e->getMessage());
        }
    }
}
