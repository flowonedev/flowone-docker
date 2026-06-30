<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;

class FeedbackController extends BaseController
{
    private const FEEDBACK_FROM = 'support@devcon1.hu';
    private const FEEDBACK_TO   = 'robert@pixelranger.hu';
    private const MAX_SCREENSHOT_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * POST /api/feedback
     * Receives user feedback and sends it via email.
     */
    public function submit(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $view         = trim($request->input('view') ?? '');
        $viewLabel    = trim($request->input('view_label') ?? $view);
        $category     = trim($request->input('category') ?? '');
        $categoryLabel = trim($request->input('category_label') ?? $category);
        $description  = trim($request->input('description') ?? '');

        if ($category === '' || $description === '') {
            return Response::error('Category and description are required', 400);
        }

        // Decode screenshot if provided
        $screenshotData = null;
        $screenshotRaw  = $request->input('screenshot');
        if ($screenshotRaw && is_string($screenshotRaw)) {
            // Strip the data URI prefix: "data:image/png;base64,..."
            $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $screenshotRaw);
            $decoded = base64_decode($base64, true);
            if ($decoded !== false && strlen($decoded) <= self::MAX_SCREENSHOT_BYTES) {
                $screenshotData = $decoded;
            }
        }

        $userEmail  = $this->userEmail ?? 'unknown';
        $userAgent  = trim($request->input('user_agent') ?? '');
        $screenSize = trim($request->input('screen_size') ?? '');
        $url        = trim($request->input('url') ?? '');
        $timestamp  = date('Y-m-d H:i:s T');

        $consoleLogs = $request->input('console_logs');
        $networkLogs = $request->input('network_logs');
        if (!is_array($consoleLogs)) $consoleLogs = [];
        if (!is_array($networkLogs)) $networkLogs = [];

        $subject = "[Feedback] {$categoryLabel} - {$viewLabel} ({$userEmail})";

        $hasScreenshot = $screenshotData !== null;
        $body = $this->buildEmailBody([
            'user_email'     => $userEmail,
            'view'           => $viewLabel,
            'category'       => $categoryLabel,
            'description'    => $description,
            'url'            => $url,
            'user_agent'     => $userAgent,
            'screen_size'    => $screenSize,
            'timestamp'      => $timestamp,
            'has_screenshot'  => $hasScreenshot,
            'console_logs'   => $consoleLogs,
            'network_logs'   => $networkLogs,
        ]);

        $plainText = $this->buildPlainTextBody([
            'user_email'     => $userEmail,
            'view'           => $viewLabel,
            'category'       => $categoryLabel,
            'description'    => $description,
            'url'            => $url,
            'user_agent'     => $userAgent,
            'screen_size'    => $screenSize,
            'timestamp'      => $timestamp,
            'console_logs'   => $consoleLogs,
            'network_logs'   => $networkLogs,
        ]);

        $sent = $this->sendFeedbackEmail($subject, $body, $plainText, $screenshotData, $userEmail);

        if (!$sent) {
            error_log("FeedbackController: Failed to send feedback email from {$userEmail}");
            return Response::error('Failed to send feedback. Please try again later.', 500);
        }

        return Response::success(null, 'Feedback sent successfully');
    }

    private function sendFeedbackEmail(string $subject, string $htmlBody, string $plainTextBody, ?string $screenshotData = null, string $userEmail = ''): bool
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host       = 'localhost';
            $mail->Port       = 25;
            $mail->SMTPAuth   = false;
            $mail->SMTPAutoTLS = false;

            $mail->setFrom(self::FEEDBACK_FROM, 'DEVCON Feedback');
            $mail->addAddress(self::FEEDBACK_TO, 'Robert');

            if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($userEmail, $userEmail);
            }

            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $plainTextBody;

            if ($screenshotData !== null) {
                $mail->addStringEmbeddedImage(
                    $screenshotData,
                    'feedback-screenshot',
                    'screenshot.png',
                    'base64',
                    'image/png'
                );
            }

            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log("FeedbackController sendFeedbackEmail error: " . $e->getMessage());
            return false;
        }
    }

    private function buildEmailBody(array $data): string
    {
        $desc = nl2br(htmlspecialchars($data['description'], ENT_QUOTES, 'UTF-8'));

        $screenshotHtml = '';
        if (!empty($data['has_screenshot'])) {
            $screenshotHtml = <<<HTML
        <tr>
          <td style="padding:0 32px 24px;">
            <h3 style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;">Screenshot</h3>
            <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <img src="cid:feedback-screenshot" alt="Screenshot" style="display:block;width:100%;height:auto;border-radius:8px;" />
            </div>
          </td>
        </tr>
HTML;
        }

        $consoleHtml = $this->buildConsoleLogsHtml($data['console_logs'] ?? []);
        $networkHtml = $this->buildNetworkLogsHtml($data['network_logs'] ?? []);

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        
        <!-- Header -->
        <tr>
          <td style="background:#4f46e5;padding:24px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:600;">User Feedback</h1>
            <p style="margin:4px 0 0;color:#c7d2fe;font-size:13px;">{$data['timestamp']}</p>
          </td>
        </tr>
        
        <!-- Meta -->
        <tr>
          <td style="padding:24px 32px 0;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <tr>
                <td style="padding:12px 16px;background:#f9fafb;border-bottom:1px solid #e5e7eb;width:120px;">
                  <strong style="font-size:12px;color:#6b7280;text-transform:uppercase;">From</strong>
                </td>
                <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#111827;">
                  {$data['user_email']}
                </td>
              </tr>
              <tr>
                <td style="padding:12px 16px;background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                  <strong style="font-size:12px;color:#6b7280;text-transform:uppercase;">Page</strong>
                </td>
                <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#111827;">
                  {$data['view']}
                </td>
              </tr>
              <tr>
                <td style="padding:12px 16px;background:#f9fafb;">
                  <strong style="font-size:12px;color:#6b7280;text-transform:uppercase;">Category</strong>
                </td>
                <td style="padding:12px 16px;font-size:14px;color:#111827;">
                  <span style="display:inline-block;padding:4px 12px;background:#eef2ff;color:#4f46e5;border-radius:9999px;font-size:12px;font-weight:600;">{$data['category']}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        
        <!-- Description -->
        <tr>
          <td style="padding:24px 32px;">
            <h3 style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;">Description</h3>
            <div style="padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;font-size:14px;line-height:1.6;color:#374151;">
              {$desc}
            </div>
          </td>
        </tr>
        
        <!-- Screenshot (if attached) -->
        {$screenshotHtml}
        
        <!-- Console Logs -->
        {$consoleHtml}
        
        <!-- Network Logs -->
        {$networkHtml}
        
        <!-- Debug info -->
        <tr>
          <td style="padding:0 32px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:11px;color:#9ca3af;">
              <tr>
                <td style="padding:4px 0;"><strong>URL:</strong> {$data['url']}</td>
              </tr>
              <tr>
                <td style="padding:4px 0;"><strong>Screen:</strong> {$data['screen_size']}</td>
              </tr>
              <tr>
                <td style="padding:4px 0;"><strong>UA:</strong> {$data['user_agent']}</td>
              </tr>
            </table>
          </td>
        </tr>
        
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function buildConsoleLogsHtml(array $logs): string
    {
        if (empty($logs)) return '';

        $levelColors = [
            'error'               => '#dc2626',
            'warn'                => '#d97706',
            'uncaught'            => '#dc2626',
            'unhandled_rejection' => '#dc2626',
            'log'                 => '#6b7280',
        ];

        $rows = '';
        foreach ($logs as $entry) {
            if (!is_array($entry)) continue;
            $ts    = htmlspecialchars($entry['ts'] ?? '', ENT_QUOTES, 'UTF-8');
            $level = htmlspecialchars($entry['level'] ?? 'log', ENT_QUOTES, 'UTF-8');
            $msg   = htmlspecialchars(mb_substr($entry['msg'] ?? '', 0, 500), ENT_QUOTES, 'UTF-8');
            $color = $levelColors[$level] ?? '#6b7280';

            $rows .= <<<HTML
<tr>
  <td style="padding:3px 8px;white-space:nowrap;color:#9ca3af;font-size:10px;border-bottom:1px solid #f3f4f6;">{$ts}</td>
  <td style="padding:3px 8px;white-space:nowrap;border-bottom:1px solid #f3f4f6;">
    <span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;color:#fff;background:{$color};text-transform:uppercase;">{$level}</span>
  </td>
  <td style="padding:3px 8px;font-size:11px;color:#374151;border-bottom:1px solid #f3f4f6;word-break:break-all;">{$msg}</td>
</tr>
HTML;
        }

        return <<<HTML
        <tr>
          <td style="padding:0 32px 24px;">
            <h3 style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;">Console Logs (last {$this->countLabel($logs)})</h3>
            <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <table width="100%" cellpadding="0" cellspacing="0" style="font-family:'SF Mono',Menlo,Consolas,monospace;">
                {$rows}
              </table>
            </div>
          </td>
        </tr>
HTML;
    }

    private function buildNetworkLogsHtml(array $logs): string
    {
        if (empty($logs)) return '';

        $rows = '';
        foreach ($logs as $entry) {
            if (!is_array($entry)) continue;
            $ts       = htmlspecialchars($entry['ts'] ?? '', ENT_QUOTES, 'UTF-8');
            $method   = htmlspecialchars($entry['method'] ?? 'GET', ENT_QUOTES, 'UTF-8');
            $url      = htmlspecialchars(mb_substr($entry['url'] ?? '', 0, 200), ENT_QUOTES, 'UTF-8');
            $status   = $entry['status'] ?? null;
            $duration = $entry['duration_ms'] ?? null;
            $error    = htmlspecialchars($entry['error'] ?? '', ENT_QUOTES, 'UTF-8');

            $statusColor = '#10b981';
            if ($status === null || $status >= 500) $statusColor = '#dc2626';
            elseif ($status >= 400) $statusColor = '#d97706';

            $statusLabel = $status !== null ? (string)$status : 'ERR';
            $durationLabel = $duration !== null ? "{$duration}ms" : '-';
            $errorCell = $error ? "<br><span style=\"color:#dc2626;font-size:10px;\">{$error}</span>" : '';

            $responseBodyHtml = '';
            $responseBody = $entry['response_body'] ?? null;
            if ($responseBody && is_string($responseBody) && $status !== null && $status >= 400) {
                $safeBody = htmlspecialchars(mb_substr($responseBody, 0, 2000), ENT_QUOTES, 'UTF-8');
                $responseBodyHtml = <<<HTML
<tr>
  <td colspan="5" style="padding:4px 8px 8px 28px;border-bottom:1px solid #f3f4f6;">
    <div style="padding:6px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:10px;color:#991b1b;white-space:pre-wrap;word-break:break-all;line-height:1.5;">{$safeBody}</div>
  </td>
</tr>
HTML;
            }

            $rows .= <<<HTML
<tr>
  <td style="padding:3px 8px;white-space:nowrap;color:#9ca3af;font-size:10px;border-bottom:1px solid #f3f4f6;">{$ts}</td>
  <td style="padding:3px 8px;white-space:nowrap;font-size:11px;font-weight:600;color:#374151;border-bottom:1px solid #f3f4f6;">{$method}</td>
  <td style="padding:3px 8px;font-size:11px;color:#374151;border-bottom:1px solid #f3f4f6;word-break:break-all;">{$url}{$errorCell}</td>
  <td style="padding:3px 8px;white-space:nowrap;border-bottom:1px solid #f3f4f6;">
    <span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:600;color:#fff;background:{$statusColor};">{$statusLabel}</span>
  </td>
  <td style="padding:3px 8px;white-space:nowrap;font-size:10px;color:#9ca3af;border-bottom:1px solid #f3f4f6;">{$durationLabel}</td>
</tr>
{$responseBodyHtml}
HTML;
        }

        return <<<HTML
        <tr>
          <td style="padding:0 32px 24px;">
            <h3 style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;">Network Requests (last {$this->countLabel($logs)})</h3>
            <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <table width="100%" cellpadding="0" cellspacing="0" style="font-family:'SF Mono',Menlo,Consolas,monospace;">
                {$rows}
              </table>
            </div>
          </td>
        </tr>
HTML;
    }

    private function countLabel(array $items): string
    {
        $count = count($items);
        return $count === 1 ? '1 entry' : "{$count} entries";
    }

    private function buildPlainTextBody(array $data): string
    {
        $lines = [];
        $lines[] = "User Feedback: {$data['timestamp']}";
        $lines[] = "From: {$data['user_email']}";
        $lines[] = "Page: {$data['view']}";
        $lines[] = "Category: {$data['category']}";
        $lines[] = "";
        $lines[] = "Description: {$data['description']}";
        $lines[] = "";
        $lines[] = "URL: {$data['url']}";
        $lines[] = "Screen: {$data['screen_size']}";
        $lines[] = "UA: {$data['user_agent']}";

        $consoleLogs = $data['console_logs'] ?? [];
        if (!empty($consoleLogs)) {
            $lines[] = "";
            $lines[] = str_repeat('-', 60);
            $lines[] = "CONSOLE LOGS (last " . $this->countLabel($consoleLogs) . ")";
            $lines[] = str_repeat('-', 60);
            foreach ($consoleLogs as $entry) {
                if (!is_array($entry)) continue;
                $ts    = $entry['ts'] ?? '';
                $level = strtoupper($entry['level'] ?? 'LOG');
                $msg   = mb_substr($entry['msg'] ?? '', 0, 500);
                $lines[] = "[{$ts}] [{$level}] {$msg}";
            }
        }

        $networkLogs = $data['network_logs'] ?? [];
        if (!empty($networkLogs)) {
            $lines[] = "";
            $lines[] = str_repeat('-', 60);
            $lines[] = "NETWORK REQUESTS (last " . $this->countLabel($networkLogs) . ")";
            $lines[] = str_repeat('-', 60);
            foreach ($networkLogs as $entry) {
                if (!is_array($entry)) continue;
                $ts       = $entry['ts'] ?? '';
                $method   = $entry['method'] ?? 'GET';
                $url      = mb_substr($entry['url'] ?? '', 0, 200);
                $status   = $entry['status'] ?? 'ERR';
                $duration = isset($entry['duration_ms']) ? "{$entry['duration_ms']}ms" : '-';
                $error    = $entry['error'] ?? '';

                $line = "[{$ts}] {$method} {$url} -> {$status} ({$duration})";
                if ($error) $line .= " ERROR: {$error}";
                $lines[] = $line;

                $responseBody = $entry['response_body'] ?? null;
                if ($responseBody && is_string($responseBody) && $status >= 400) {
                    $lines[] = "  RESPONSE: " . mb_substr($responseBody, 0, 2000);
                }
            }
        }

        return implode("\n", $lines);
    }
}
