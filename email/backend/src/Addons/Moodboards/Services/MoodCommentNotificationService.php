<?php

namespace Webmail\Addons\Moodboards\Services;

use Webmail\Services\SmtpService;
use Webmail\Services\SessionService;

class MoodCommentNotificationService
{
    private \PDO $db;
    private array $config;
    private string $logFile;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logFile = __DIR__ . '/../../../../storage/mood-boards.log';
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] [CommentNotify] $message\n", FILE_APPEND);
    }

    /**
     * Notify the board owner about a new comment.
     * Best-effort: logs failures, never throws.
     */
    public function notifyOwner(array $ownerInfo, array $comment): void
    {
        $ownerEmail = $ownerInfo['owner_email'] ?? null;
        $boardName = $ownerInfo['name'] ?? 'Untitled Board';
        $boardId = $ownerInfo['id'] ?? 0;

        if (!$ownerEmail) {
            $this->log("notifyOwner: no owner email for board #{$boardId}");
            return;
        }

        $authorName = $comment['author_name'] ?? 'Someone';
        $content = $comment['content'] ?? '';
        $preview = mb_strlen($content) > 200 ? mb_substr($content, 0, 200) . '...' : $content;
        $isPublic = !empty($comment['is_public']);

        $subject = "{$authorName} commented on \"{$boardName}\"";

        $frontendUrl = $this->config['app']['frontend_url']
            ?? $this->config['frontend_url']
            ?? 'https://flowone.pro';
        $boardUrl = rtrim($frontendUrl, '/') . "/mood/{$boardId}";

        $badgeLabel = $isPublic ? 'Client / External' : 'Team Member';
        $badgeColor = $isPublic ? '#f59e0b' : '#6366f1';

        $html = $this->buildEmailHtml($boardName, $authorName, $preview, $boardUrl, $badgeLabel, $badgeColor);

        try {
            $sent = $this->sendViaOwnerCredentials($ownerEmail, $subject, $html);
            if ($sent) {
                $this->log("notifyOwner: emailed {$ownerEmail} about comment by {$authorName} on board #{$boardId}");
            } else {
                $this->log("notifyOwner: SMTP send failed for {$ownerEmail}, comment notification skipped");
            }
        } catch (\Throwable $e) {
            $this->log("notifyOwner: exception sending to {$ownerEmail}: " . $e->getMessage());
        }
    }

    private function sendViaOwnerCredentials(string $ownerEmail, string $subject, string $html): bool
    {
        try {
            $stmt = $this->db->prepare('
                SELECT encrypted_password FROM webmail_sessions
                WHERE email = ? AND is_valid = 1 ORDER BY last_active_at DESC LIMIT 1
            ');
            $stmt->execute([$ownerEmail]);
            $session = $stmt->fetch();

            if (!$session || empty($session['encrypted_password'])) {
                $this->log("sendViaOwnerCredentials: no active session for {$ownerEmail}");
                return false;
            }

            $sessionService = new SessionService(
                $this->config['jwt'],
                $this->config['imap_encryption_key'] ?? ''
            );
            $password = $sessionService->decryptPassword($session['encrypted_password']);

            if (!$password) {
                $this->log("sendViaOwnerCredentials: failed to decrypt password for {$ownerEmail}");
                return false;
            }

            $smtp = new SmtpService($this->config['smtp']);
            $smtp->setCredentials($ownerEmail, $password);
            $smtp->send([
                'from_name'  => 'FlowOne Moodboard',
                'from_email' => $ownerEmail,
                'to'         => [['email' => $ownerEmail, 'name' => '']],
                'subject'    => $subject,
                'body_html'  => $html,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->log("sendViaOwnerCredentials: SMTP error for {$ownerEmail}: " . $e->getMessage());
            return false;
        }
    }

    private function buildEmailHtml(
        string $boardName,
        string $authorName,
        string $contentPreview,
        string $boardUrl,
        string $badgeLabel,
        string $badgeColor
    ): string {
        $boardNameEsc = htmlspecialchars($boardName, ENT_QUOTES, 'UTF-8');
        $authorNameEsc = htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8');
        $contentEsc = nl2br(htmlspecialchars($contentPreview, ENT_QUOTES, 'UTF-8'));
        $boardUrlEsc = htmlspecialchars($boardUrl, ENT_QUOTES, 'UTF-8');
        $badgeLabelEsc = htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
  <tr><td style="padding:24px 28px 16px;">
    <p style="margin:0 0 4px;font-size:13px;color:#71717a;">New comment on</p>
    <h2 style="margin:0;font-size:20px;color:#18181b;">{$boardNameEsc}</h2>
  </td></tr>
  <tr><td style="padding:0 28px 20px;">
    <table cellpadding="0" cellspacing="0" style="width:100%;background:#fafafa;border-radius:12px;border:1px solid #e4e4e7;">
      <tr><td style="padding:16px;">
        <div style="display:inline-flex;align-items:center;gap:8px;margin-bottom:10px;">
          <span style="font-weight:600;font-size:14px;color:#18181b;">{$authorNameEsc}</span>
          <span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500;color:#fff;background:{$badgeColor};">{$badgeLabelEsc}</span>
        </div>
        <p style="margin:0;font-size:14px;line-height:1.5;color:#3f3f46;">{$contentEsc}</p>
      </td></tr>
    </table>
  </td></tr>
  <tr><td style="padding:0 28px 24px;" align="center">
    <a href="{$boardUrlEsc}" style="display:inline-block;padding:10px 28px;background:#6366f1;color:#ffffff;text-decoration:none;border-radius:24px;font-size:14px;font-weight:500;">View Board</a>
  </td></tr>
  <tr><td style="padding:0 28px 16px;">
    <p style="margin:0;font-size:11px;color:#a1a1aa;text-align:center;">You can disable comment notifications in the board settings.</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
