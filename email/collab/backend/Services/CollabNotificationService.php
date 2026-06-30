<?php

namespace Collab\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * CollabNotificationService
 * 
 * Sends email notifications for collaboration events.
 */
class CollabNotificationService
{
    private array $config;
    private string $appUrl;
    private string $appName;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->appUrl = rtrim($config['app_url'] ?? 'https://email.devcon1.hu', '/');
        $this->appName = $config['app_name'] ?? 'FlowOne';
    }
    
    /**
     * Send collaboration invite email
     */
    public function sendCollaboratorInvite(
        string $recipientEmail,
        string $documentTitle,
        string $documentUuid,
        string $inviterEmail,
        string $role,
        string $documentType = 'document'
    ): bool {
        $documentUrl = "{$this->appUrl}/drive?doc={$documentUuid}";
        $roleLabel = $role === 'editor' ? 'edit' : 'view';
        $typeLabel = $documentType === 'presentation' ? 'presentation' : 'document';
        
        $subject = "{$inviterEmail} shared a {$typeLabel} with you";
        
        $htmlBody = $this->buildInviteHtml(
            $recipientEmail,
            $documentTitle,
            $documentUrl,
            $inviterEmail,
            $roleLabel,
            $typeLabel
        );
        
        $textBody = $this->buildInviteText(
            $recipientEmail,
            $documentTitle,
            $documentUrl,
            $inviterEmail,
            $roleLabel,
            $typeLabel
        );
        
        return $this->sendEmail($recipientEmail, $subject, $htmlBody, $textBody);
    }
    
    /**
     * Send email using PHPMailer or PHP mail()
     */
    private function sendEmail(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        // Try PHPMailer first if SMTP config exists
        if (!empty($this->config['smtp']['host'])) {
            return $this->sendViaSMTP($to, $subject, $htmlBody, $textBody);
        }
        
        // Fallback to PHP mail()
        return $this->sendViaMail($to, $subject, $htmlBody, $textBody);
    }
    
    /**
     * Send via SMTP using PHPMailer
     */
    private function sendViaSMTP(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $mail = new PHPMailer(true);
        
        try {
            $mail->isSMTP();
            $mail->Host = $this->config['smtp']['host'] ?? 'localhost';
            $mail->Port = $this->config['smtp']['port'] ?? 587;
            
            // Support both 'user'/'pass' and 'username'/'password' keys
            $smtpUser = $this->config['smtp']['user'] ?? $this->config['smtp']['username'] ?? '';
            $smtpPass = $this->config['smtp']['pass'] ?? $this->config['smtp']['password'] ?? '';
            
            $mail->SMTPAuth = !empty($smtpUser) && !empty($smtpPass);
            
            if ($mail->SMTPAuth) {
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
            }
            
            $encryption = $this->config['smtp']['encryption'] ?? 'tls';
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }
            
            // Allow self-signed certs for local servers
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ];
            
            // Use smtp username as from_email if not specified
            $fromEmail = $this->config['smtp']['from_email'] ?? $smtpUser ?: 'noreply@' . parse_url($this->appUrl, PHP_URL_HOST);
            $fromName = $this->config['smtp']['from_name'] ?? $this->appName;
            
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
            
            $mail->send();
            error_log("[CollabNotification] Email sent to {$to}: {$subject}");
            return true;
            
        } catch (Exception $e) {
            error_log("[CollabNotification] SMTP error: " . $e->getMessage());
            // Try fallback
            return $this->sendViaMail($to, $subject, $htmlBody, $textBody);
        }
    }
    
    /**
     * Send via PHP mail() function
     */
    private function sendViaMail(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $boundary = md5(uniqid(time()));
        $fromEmail = $this->config['smtp']['from_email'] ?? 'noreply@' . parse_url($this->appUrl, PHP_URL_HOST);
        $fromName = $this->config['smtp']['from_name'] ?? $this->appName;
        
        $headers = [
            'From' => "{$fromName} <{$fromEmail}>",
            'Reply-To' => $fromEmail,
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"{$boundary}\"",
            'X-Mailer' => 'PHP/' . phpversion(),
        ];
        
        $headerStr = '';
        foreach ($headers as $key => $value) {
            $headerStr .= "{$key}: {$value}\r\n";
        }
        
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($textBody) . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n\r\n";
        $body .= "--{$boundary}--\r\n";
        
        $result = @mail($to, $subject, $body, $headerStr);
        
        if ($result) {
            error_log("[CollabNotification] Email sent via mail() to {$to}: {$subject}");
        } else {
            error_log("[CollabNotification] mail() failed for {$to}: {$subject}");
        }
        
        return $result;
    }
    
    /**
     * Build HTML email body for invite
     */
    private function buildInviteHtml(
        string $recipientEmail,
        string $documentTitle,
        string $documentUrl,
        string $inviterEmail,
        string $roleLabel,
        string $typeLabel
    ): string {
        $primaryColor = '#22c55e'; // Green accent color
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: {$primaryColor}; padding: 30px 40px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 600;">{$this->appName}</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="color: #1f2937; margin: 0 0 20px 0; font-size: 20px; font-weight: 600;">
                                You've been invited to collaborate!
                            </h2>
                            
                            <p style="color: #4b5563; margin: 0 0 20px 0; font-size: 16px; line-height: 1.6;">
                                <strong>{$inviterEmail}</strong> has shared a {$typeLabel} with you and given you permission to <strong>{$roleLabel}</strong> it.
                            </p>
                            
                            <div style="background-color: #f9fafb; border-radius: 8px; padding: 20px; margin: 0 0 30px 0;">
                                <p style="color: #6b7280; margin: 0 0 8px 0; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Document</p>
                                <p style="color: #1f2937; margin: 0; font-size: 18px; font-weight: 600;">{$documentTitle}</p>
                            </div>
                            
                            <div style="text-align: center;">
                                <a href="{$documentUrl}" style="display: inline-block; background-color: {$primaryColor}; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-size: 16px; font-weight: 600;">
                                    Open Document
                                </a>
                            </div>
                            
                            <p style="color: #9ca3af; margin: 30px 0 0 0; font-size: 14px; text-align: center;">
                                Or copy this link:<br>
                                <a href="{$documentUrl}" style="color: {$primaryColor}; word-break: break-all;">{$documentUrl}</a>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 20px 40px; text-align: center; border-top: 1px solid #e5e7eb;">
                            <p style="color: #9ca3af; margin: 0; font-size: 12px;">
                                This email was sent to {$recipientEmail} because someone shared a document with you.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
    
    /**
     * Build plain text email body for invite
     */
    private function buildInviteText(
        string $recipientEmail,
        string $documentTitle,
        string $documentUrl,
        string $inviterEmail,
        string $roleLabel,
        string $typeLabel
    ): string {
        return <<<TEXT
You've been invited to collaborate!

{$inviterEmail} has shared a {$typeLabel} with you and given you permission to {$roleLabel} it.

Document: {$documentTitle}

Open the document: {$documentUrl}

---
This email was sent to {$recipientEmail} because someone shared a document with you.
TEXT;
    }
}

