<?php

namespace Webmail\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;

class SmtpService
{
    private array $config;
    private string $email;
    private string $password;
    private ?string $oauthToken = null;
    private ?string $oauthProvider = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Set credentials for sending (password-based)
     */
    public function setCredentials(string $email, string $password): void
    {
        $this->email = $email;
        $this->password = $password;
        $this->oauthToken = null;
    }
    
    /**
     * Set OAuth credentials for sending
     */
    public function setOAuthCredentials(string $email, string $accessToken, string $provider = 'google'): void
    {
        $this->email = $email;
        $this->oauthToken = $accessToken;
        $this->oauthProvider = $provider;
        $this->password = '';
    }

    /**
     * Send an email
     */
    public function send(array $params): array
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->config['host'] ?? 'localhost';
            $mail->Port = $this->config['port'] ?? 25;
            
            if ($this->config['auth'] ?? true) {
                $mail->SMTPAuth = true;
                $mail->Username = $this->email;
                
                // Use OAuth2 XOAUTH2 if token is available
                if ($this->oauthToken) {
                    $mail->AuthType = 'XOAUTH2';
                    $mail->setOAuth(new class($this->email, $this->oauthToken) extends OAuth {
                        private string $email;
                        private string $token;
                        
                        public function __construct(string $email, string $token)
                        {
                            $this->email = $email;
                            $this->token = $token;
                        }
                        
                        public function getOauth64(): string
                        {
                            return base64_encode("user={$this->email}\1auth=Bearer {$this->token}\1\1");
                        }
                    });
                } else {
                    $mail->Password = $this->password;
                }
            }

            $encryption = $this->config['encryption'] ?? '';
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }
            
            // Disable certificate verification if configured (for localhost connections)
            if (($this->config['verify_peer'] ?? true) === false) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]
                ];
            }

            // Sender
            $fromName = $params['from_name'] ?? '';
            $fromEmail = $params['from_email'] ?? $this->email;
            $mail->setFrom($fromEmail, $fromName);
            $mail->addReplyTo($fromEmail, $fromName);

            // Individual-delivery mode: when 'envelope_to' is set, the visible
            // To/Cc headers are built from the full header_to/header_cc lists,
            // but the message is delivered to a single envelope recipient (the
            // SMTP envelope is restricted after preSend(), further below). This
            // lets each recipient get their own copy + unique tracking pixel
            // while still seeing the full recipient list, so that "reply all"
            // keeps the Cc. When 'envelope_to' is absent, behaviour is
            // unchanged: to/cc/bcc drive both the headers and the envelope.
            $envelopeTo = '';
            if (!empty($params['envelope_to'])) {
                $envelopeTo = is_array($params['envelope_to'])
                    ? ($params['envelope_to']['email'] ?? '')
                    : (string)$params['envelope_to'];
            }
            $individualMode = $envelopeTo !== '';

            $toList  = $individualMode ? ($params['header_to'] ?? []) : ($params['to'] ?? []);
            $ccList  = $individualMode ? ($params['header_cc'] ?? []) : ($params['cc'] ?? []);
            $bccList = $individualMode ? [] : ($params['bcc'] ?? []);

            // Recipients
            foreach ($toList as $recipient) {
                if (is_array($recipient)) {
                    if (!empty($recipient['email'])) {
                        $mail->addAddress($recipient['email'], $recipient['name'] ?? '');
                    }
                } elseif (!empty($recipient)) {
                    $mail->addAddress($recipient);
                }
            }

            foreach ($ccList as $recipient) {
                if (is_array($recipient)) {
                    if (!empty($recipient['email'])) {
                        $mail->addCC($recipient['email'], $recipient['name'] ?? '');
                    }
                } elseif (!empty($recipient)) {
                    $mail->addCC($recipient);
                }
            }

            foreach ($bccList as $recipient) {
                if (is_array($recipient)) {
                    if (!empty($recipient['email'])) {
                        $mail->addBCC($recipient['email'], $recipient['name'] ?? '');
                    }
                } elseif (!empty($recipient)) {
                    $mail->addBCC($recipient);
                }
            }

            // Cc-only messages: PHPMailer omits the To header entirely when To
            // is empty but Cc is set (its undisclosed-recipients fallback only
            // fires when Cc is also empty). Add an explicit placeholder so every
            // delivered copy still carries a visible To header.
            if ($individualMode && empty($toList) && !empty($ccList)) {
                $mail->addCustomHeader('To', 'undisclosed-recipients:;');
            }

            // Content
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'quoted-printable'; // Safe encoding for Hungarian/special characters
            $mail->Subject = $params['subject'] ?? '';
            
            // Ensure body is a string (not array)
            $bodyHtml = $params['body_html'] ?? $params['body'] ?? '';
            if (is_array($bodyHtml)) {
                $bodyHtml = $bodyHtml['content'] ?? $bodyHtml[0] ?? '';
            }
            $bodyHtml = (string)$bodyHtml;
            
            $bodyText = $params['body_text'] ?? '';
            if (is_array($bodyText)) {
                $bodyText = $bodyText['content'] ?? $bodyText[0] ?? '';
            }
            $bodyText = (string)$bodyText;
            
            if (!empty($bodyHtml)) {
                $mail->isHTML(true);
                
                // Auto-convert inline images (server URLs + base64 data URIs) to CID embeds
                $cidResult = $this->convertInlineImagesToCid($bodyHtml);
                $bodyHtml = $cidResult['html'];
                $params['inline_images'] = array_merge($params['inline_images'] ?? [], $cidResult['images']);
                
                $mail->Body = $bodyHtml;
                $mail->AltBody = $bodyText ?: strip_tags($bodyHtml);
            } else {
                // Text-only email (e.g. unsubscribe requests)
                $mail->isHTML(false);
                $mail->Body = $bodyText;
            }

            // Custom Message-ID for threading (used when sending individual emails per recipient)
            if (!empty($params['message_id'])) {
                $mail->MessageID = $params['message_id'];
            }

            // Reply headers (sanitize to remove IMAP-folded line breaks that PHPMailer rejects)
            if (!empty($params['in_reply_to'])) {
                $mail->addCustomHeader('In-Reply-To', $this->sanitizeHeaderValue($params['in_reply_to']));
            }
            if (!empty($params['references'])) {
                $mail->addCustomHeader('References', $this->sanitizeHeaderValue($params['references']));
            }
            
            // Custom headers for showing all recipients (cosmetic, for individual sends)
            if (!empty($params['display_to'])) {
                $mail->addCustomHeader('X-Original-To', $this->sanitizeHeaderValue($params['display_to']));
            }
            if (!empty($params['display_cc'])) {
                $mail->addCustomHeader('X-Original-Cc', $this->sanitizeHeaderValue($params['display_cc']));
            }

            // iCalendar invite (RFC 5545) - triggers native calendar UI in Gmail/Outlook
            if (!empty($params['ical'])) {
                $mail->Ical = $params['ical'];
            }

            // Generic custom headers (used by campaigns for List-Unsubscribe, Precedence, etc.)
            foreach ($params['custom_headers'] ?? [] as $headerName => $headerValue) {
                $mail->addCustomHeader($headerName, $headerValue);
            }

            // High-importance / priority headers (so Outlook, Apple Mail, etc.
            // show the message as important where supported).
            $this->applyImportance($mail, $params);

            // Attachments
            foreach ($params['attachments'] ?? [] as $attachment) {
                $this->addAttachmentToMailer($mail, $attachment);
            }

            // Inline images
            foreach ($params['inline_images'] ?? [] as $image) {
                $mail->addStringEmbeddedImage(
                    $image['content'],
                    $image['cid'],
                    $image['name'] ?? 'image',
                    'base64',
                    $image['type'] ?? 'image/png'
                );
            }

            // Log authentication method (for debugging)
            $authMethod = $this->oauthToken ? 'XOAUTH2' : 'password';
            error_log("SMTP Auth - User: {$this->email}, Method: {$authMethod}");

            // dry_run builds the full MIME and resolves the final SMTP envelope
            // but performs no network transmission. Used by the regression
            // suite to assert that the visible To/Cc headers and the per-copy
            // RCPT TO envelope are produced correctly.
            $dryRun = !empty($params['dry_run']);

            if ($individualMode) {
                // Build the MIME message now -- this freezes the visible To/Cc
                // headers. Then restrict the SMTP envelope to the single
                // delivery recipient before the actual SMTP transaction, so
                // only this recipient receives this copy (with its own pixel).
                $mail->preSend();

                $envelopeName = '';
                if (!empty($params['envelope_name'])) {
                    $envelopeName = (string)$params['envelope_name'];
                } elseif (is_array($params['envelope_to'] ?? null)) {
                    $envelopeName = $params['envelope_to']['name'] ?? '';
                }

                $mail->clearAllRecipients();
                $mail->addAddress($envelopeTo, $envelopeName);

                // Safety: if the envelope address was queued instead of added
                // immediately (e.g. a raw non-ASCII/IDN domain), the live
                // recipient list would be empty and we'd send a zero-RCPT
                // envelope. Flushing via preSend() guarantees delivery (the
                // visible To/Cc collapse to this recipient in that rare case).
                if (count($mail->getAllRecipientAddresses()) === 0) {
                    $mail->preSend();
                }

                if (!$dryRun) {
                    $mail->postSend();
                }
            } elseif ($dryRun) {
                // Build the MIME without transmitting (send() = preSend + postSend).
                $mail->preSend();
            } else {
                $mail->send();
            }

            // Return raw message for saving to Sent folder. 'envelope' is the
            // resolved RCPT TO list (single address in individual mode).
            return [
                'success' => true,
                'message_id' => $mail->getLastMessageID(),
                'raw_message' => $mail->getSentMIMEMessage(),
                'envelope' => array_keys($mail->getAllRecipientAddresses()),
            ];

        } catch (Exception $e) {
            error_log("SMTP Exception: " . $e->getMessage());
            error_log("SMTP ErrorInfo: " . $mail->ErrorInfo);
            error_log("SMTP Debug: Host={$this->config['host']}, Port={$this->config['port']}, User={$this->email}");
            return [
                'success' => false,
                'error' => $mail->ErrorInfo ?: $e->getMessage(),
            ];
        }
    }

    /**
     * Apply high-importance / priority headers to a PHPMailer message when
     * $params['importance'] === 'high'. Sets the widely-recognised header set
     * (X-Priority via PHPMailer's Priority property, plus X-MSMail-Priority,
     * Importance and Priority) so Outlook/Apple Mail/Thunderbird flag the mail
     * as important. (Gmail ignores sender-set importance.)
     */
    private function applyImportance(PHPMailer $mail, array $params): void
    {
        if (strtolower((string)($params['importance'] ?? '')) !== 'high') {
            return;
        }
        $mail->Priority = 1; // emits "X-Priority: 1"
        $mail->addCustomHeader('X-MSMail-Priority', 'High');
        $mail->addCustomHeader('Importance', 'High');
        $mail->addCustomHeader('Priority', 'urgent');
    }

    /**
     * Build raw message for draft saving or Sent folder
     */
    public function buildDraftMessage(array $params): string
    {
        $mail = new PHPMailer(true);
        
        try {
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'quoted-printable'; // Safe encoding for Hungarian/special characters
            $mail->setFrom($this->email, $params['from_name'] ?? '');
            
            // Custom Message-ID if provided
            if (!empty($params['message_id'])) {
                $mail->MessageID = $params['message_id'];
            }
            
            $hasRecipients = false;
            
            foreach ($params['to'] ?? [] as $recipient) {
                if (is_array($recipient)) {
                    if (!empty($recipient['email'])) {
                        $mail->addAddress($recipient['email'], $recipient['name'] ?? '');
                        $hasRecipients = true;
                    }
                } elseif (!empty($recipient)) {
                    $mail->addAddress($recipient);
                    $hasRecipients = true;
                }
            }

            foreach ($params['cc'] ?? [] as $recipient) {
                if (is_array($recipient)) {
                    if (!empty($recipient['email'])) {
                        $mail->addCC($recipient['email'], $recipient['name'] ?? '');
                        $hasRecipients = true;
                    }
                } elseif (!empty($recipient)) {
                    $mail->addCC($recipient);
                    $hasRecipients = true;
                }
            }

            foreach ($params['bcc'] ?? [] as $recipient) {
                if (is_array($recipient)) {
                    if (!empty($recipient['email'])) {
                        $mail->addBCC($recipient['email'], $recipient['name'] ?? '');
                        $hasRecipients = true;
                    }
                } elseif (!empty($recipient)) {
                    $mail->addBCC($recipient);
                    $hasRecipients = true;
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $params['subject'] ?? '';
            
            // Ensure body is a string (not array)
            $body = $params['body_html'] ?? $params['body'] ?? '';
            if (is_array($body)) {
                $body = $body['content'] ?? $body[0] ?? '';
            }
            $body = (string)$body;

            if (!empty($body)) {
                $cidResult = $this->convertInlineImagesToCid($body);
                $body = $cidResult['html'];
                $params['inline_images'] = array_merge($params['inline_images'] ?? [], $cidResult['images']);
                $params['body_html'] = $body;
            }

            $mail->Body = $body;
            
            $bodyText = $params['body_text'] ?? '';
            if (is_array($bodyText)) {
                $bodyText = $bodyText['content'] ?? $bodyText[0] ?? '';
            }
            $mail->AltBody = (string)$bodyText ?: strip_tags($mail->Body);

            if (!empty($params['in_reply_to'])) {
                $mail->addCustomHeader('In-Reply-To', $this->sanitizeHeaderValue((string)$params['in_reply_to']));
            }
            if (!empty($params['references'])) {
                $mail->addCustomHeader('References', $this->sanitizeHeaderValue((string)$params['references']));
            }

            // Preserve high-importance headers on the Sent/draft copy.
            $this->applyImportance($mail, $params);

            // Attachments
            foreach ($params['attachments'] ?? [] as $attachment) {
                $this->addAttachmentToMailer($mail, $attachment);
            }

            foreach ($params['inline_images'] ?? [] as $image) {
                $mail->addStringEmbeddedImage(
                    $image['content'],
                    $image['cid'],
                    $image['name'] ?? 'image',
                    'base64',
                    $image['type'] ?? 'image/png'
                );
            }

            // If no recipients OR empty body, build manually
            // PHPMailer's preSend() fails without recipients or with empty body
            if (!$hasRecipients || empty(trim($body))) {
                return $this->buildRawDraftWithoutRecipients($params);
            }

            $mail->preSend();
            return $mail->getSentMIMEMessage();

        } catch (Exception $e) {
            error_log("Draft build error: " . $e->getMessage());
            // Fallback to manual build
            return $this->buildRawDraftWithoutRecipients($params);
        }
    }
    
    /**
     * Build a raw draft message without requiring recipients
     * Used when saving drafts before recipients are added
     */
    private function buildRawDraftWithoutRecipients(array $params): string
    {
        $date = date('r');
        $messageId = $params['message_id'] ?? ('<' . uniqid() . '@' . gethostname() . '>');
        $attachments = $params['attachments'] ?? [];
        $inlineImages = $params['inline_images'] ?? [];
        $hasInlineImages = !empty($inlineImages);
        $hasAttachments = !empty($attachments);
        $hasCalendarAttachment = $this->hasCalendarAttachment($attachments);
        $hasIcal = !empty($params['ical']);
        $needsMixedBoundary = $hasAttachments || $hasIcal;
        
        $altBoundary = '----=_Alt_' . md5(uniqid('alt'));
        $mixedBoundary = '----=_Mix_' . md5(uniqid('mix'));
        $relatedBoundary = '----=_Rel_' . md5(uniqid('rel'));
        
        $headers = [];
        $headers[] = "Date: $date";
        $headers[] = "Message-ID: $messageId";
        $headers[] = "From: " . $this->email;
        $headers[] = "Subject: " . ($params['subject'] ?? '');
        $headers[] = "MIME-Version: 1.0";
        
        if ($needsMixedBoundary) {
            $headers[] = "Content-Type: multipart/mixed; boundary=\"$mixedBoundary\"";
        } elseif ($hasInlineImages) {
            $headers[] = "Content-Type: multipart/related; boundary=\"$relatedBoundary\"";
        } else {
            $headers[] = "Content-Type: multipart/alternative; boundary=\"$altBoundary\"";
        }
        
        // Add To header if present
        $toAddresses = [];
        foreach ($params['to'] ?? [] as $recipient) {
            if (is_array($recipient) && !empty($recipient['email'])) {
                $toAddresses[] = $recipient['email'];
            } elseif (is_string($recipient) && !empty($recipient)) {
                $toAddresses[] = $recipient;
            }
        }
        if (!empty($toAddresses)) {
            $headers[] = "To: " . implode(', ', $toAddresses);
        }
        
        // Add CC header if present
        $ccAddresses = [];
        foreach ($params['cc'] ?? [] as $recipient) {
            if (is_array($recipient) && !empty($recipient['email'])) {
                $ccAddresses[] = $recipient['email'];
            } elseif (is_string($recipient) && !empty($recipient)) {
                $ccAddresses[] = $recipient;
            }
        }
        if (!empty($ccAddresses)) {
            $headers[] = "Cc: " . implode(', ', $ccAddresses);
        }

        // Add BCC header if present so saved drafts/Sent copies keep the full recipient list
        $bccAddresses = [];
        foreach ($params['bcc'] ?? [] as $recipient) {
            if (is_array($recipient) && !empty($recipient['email'])) {
                $bccAddresses[] = $recipient['email'];
            } elseif (is_string($recipient) && !empty($recipient)) {
                $bccAddresses[] = $recipient;
            }
        }
        if (!empty($bccAddresses)) {
            $headers[] = "Bcc: " . implode(', ', $bccAddresses);
        }

        if (!empty($params['in_reply_to'])) {
            $headers[] = "In-Reply-To: " . $this->sanitizeHeaderValue((string)$params['in_reply_to']);
        }
        if (!empty($params['references'])) {
            $headers[] = "References: " . $this->sanitizeHeaderValue((string)$params['references']);
        }

        // High-importance headers so the saved draft/Sent copy is flagged too.
        if (strtolower((string)($params['importance'] ?? '')) === 'high') {
            $headers[] = "X-Priority: 1";
            $headers[] = "X-MSMail-Priority: High";
            $headers[] = "Importance: High";
            $headers[] = "Priority: urgent";
        }
        
        $body = $params['body_html'] ?? $params['body'] ?? '';
        if (is_array($body)) {
            $body = $body['content'] ?? $body[0] ?? '';
        }
        $body = (string)$body;
        
        $textBody = $params['body_text'] ?? '';
        if (is_array($textBody)) {
            $textBody = $textBody['content'] ?? $textBody[0] ?? '';
        }
        $textBody = (string)$textBody ?: strip_tags($body);
        
        if (empty(trim($body))) {
            $body = ' ';
            $textBody = ' ';
        }
        
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        
        if ($needsMixedBoundary) {
            // multipart/mixed wrapper -> body part(s) + attachments
            $message .= "--$mixedBoundary\r\n";
            if ($hasInlineImages) {
                $message .= "Content-Type: multipart/related; boundary=\"$relatedBoundary\"\r\n\r\n";
            } else {
                $message .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";
            }
        }

        if ($hasInlineImages) {
            $message .= "--$relatedBoundary\r\n";
            $message .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";
        }
        
        // Text/HTML body parts
        $message .= "--$altBoundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $message .= quoted_printable_encode($textBody) . "\r\n\r\n";
        $message .= "--$altBoundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $message .= quoted_printable_encode($body) . "\r\n\r\n";
        $message .= "--$altBoundary--\r\n";
        
        if ($hasInlineImages) {
            foreach ($inlineImages as $image) {
                $imageContent = $this->getAttachmentBinaryContent($image);
                if ($imageContent === null || empty($image['cid'])) {
                    continue;
                }

                $filename = $image['name'] ?? 'image';
                $mimeType = $image['type'] ?? 'image/png';
                $encoded = chunk_split(base64_encode($imageContent));

                $message .= "\r\n--$relatedBoundary\r\n";
                $message .= "Content-Type: $mimeType; name=\"$filename\"\r\n";
                $message .= "Content-ID: <{$image['cid']}>\r\n";
                $message .= "Content-Disposition: inline; filename=\"$filename\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= $encoded;
            }
            $message .= "--$relatedBoundary--\r\n";
        }

        if ($needsMixedBoundary) {
            foreach ($attachments as $attachment) {
                $fileContent = $this->getAttachmentBinaryContent($attachment);
                if ($fileContent === null) {
                    continue;
                }

                $filename = $attachment['name'] ?? (isset($attachment['path']) ? basename($attachment['path']) : 'attachment');
                $mimeType = $attachment['type'] ?? 'application/octet-stream';
                $encoded = chunk_split(base64_encode($fileContent));
                
                $message .= "\r\n--$mixedBoundary\r\n";
                $message .= "Content-Type: $mimeType; name=\"$filename\"\r\n";
                $message .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= $encoded;
            }

            if ($hasIcal && !$hasCalendarAttachment) {
                $calendarMethod = stripos((string)$params['ical'], 'METHOD:REPLY') !== false ? 'REPLY' : 'REQUEST';
                $calendarFilename = $calendarMethod === 'REPLY' ? 'response.ics' : 'invite.ics';
                $calendarContent = chunk_split(base64_encode((string)$params['ical']));

                $message .= "\r\n--$mixedBoundary\r\n";
                $message .= "Content-Type: text/calendar; method=$calendarMethod; charset=UTF-8; name=\"$calendarFilename\"\r\n";
                $message .= "Content-Disposition: attachment; filename=\"$calendarFilename\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= $calendarContent;
            }

            $message .= "--$mixedBoundary--\r\n";
        }
        
        return $message;
    }

    private function addAttachmentToMailer(PHPMailer $mail, array $attachment): void
    {
        if (isset($attachment['path']) && is_string($attachment['path']) && file_exists($attachment['path'])) {
            $mail->addAttachment(
                $attachment['path'],
                $attachment['name'] ?? basename($attachment['path']),
                'base64',
                $attachment['type'] ?? ''
            );
            return;
        }

        $content = $this->getAttachmentBinaryContent($attachment);
        if ($content === null) {
            return;
        }

        $mail->addStringAttachment(
            $content,
            $attachment['name'] ?? 'attachment',
            'base64',
            $attachment['type'] ?? 'application/octet-stream'
        );
    }

    private function getAttachmentBinaryContent(array $attachment): ?string
    {
        if (isset($attachment['path']) && is_string($attachment['path']) && file_exists($attachment['path'])) {
            $content = @file_get_contents($attachment['path']);
            return $content === false ? null : $content;
        }

        if (isset($attachment['content_base64'])) {
            $decoded = base64_decode((string)$attachment['content_base64'], true);
            return $decoded === false ? null : $decoded;
        }

        if (isset($attachment['content'])) {
            return (string)$attachment['content'];
        }

        return null;
    }

    private function hasCalendarAttachment(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            if (stripos((string)($attachment['type'] ?? ''), 'text/calendar') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert inline images in HTML body to CID-embedded attachments.
     * 
     * Handles two cases:
     * 1) <img src="https://.../api/inline-image/img_xxx.png"> — server-hosted pasted images
     * 2) <img src="data:image/png;base64,..."> — base64-encoded fallback images
     * 
     * Replaces each src with "cid:UNIQUE_ID" and returns the image data for PHPMailer embedding.
     * This ensures recipients see images inline without clicking "load external images".
     *
     * @return array{html: string, images: array}
     */
    private function convertInlineImagesToCid(string $html): array
    {
        $images = [];
        $counter = 0;
        $inlineDir = '/var/www/vps-email/data/inline-images';

        // --- 1) Server-hosted inline images: /api/inline-image/filename ---
        $result = preg_replace_callback(
            '/<img([^>]*)\ssrc=["\']https?:\/\/[^"\']*\/api\/inline-image\/([a-zA-Z0-9_]+\.\w+)["\']([^>]*)>/i',
            function ($matches) use (&$images, &$counter, $inlineDir) {
                $beforeSrc = $matches[1];
                $filename = basename($matches[2]);
                $afterSrc = $matches[3];

                // Sanitize filename
                if (!preg_match('/^img_[a-f0-9]+_\d+\.(jpg|png|gif|webp)$/i', $filename)) {
                    return $matches[0]; // Skip invalid filenames
                }

                $path = $inlineDir . '/' . $filename;
                if (!file_exists($path)) {
                    return $matches[0]; // Skip if file not found
                }

                $counter++;
                $cid = 'inline_img_' . $counter . '_' . bin2hex(random_bytes(8));
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $mimeType = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/png',
                };

                $images[] = [
                    'content' => file_get_contents($path),
                    'cid' => $cid,
                    'name' => $filename,
                    'type' => $mimeType,
                ];

                return '<img' . $beforeSrc . ' src="cid:' . $cid . '"' . $afterSrc . '>';
            },
            $html
        );
        if ($result !== null) {
            $html = $result;
        }

        // --- 2) Base64 data-URI images ---
        $result = preg_replace_callback(
            '/<img([^>]*)\ssrc=["\']data:(image\/(png|jpe?g|gif|webp));base64,([^"\']+)["\']([^>]*)>/i',
            function ($matches) use (&$images, &$counter) {
                $beforeSrc = $matches[1];
                $mimeType = $matches[2]; // e.g. image/png
                $ext = $matches[3];      // e.g. png
                $base64Data = $matches[4];
                $afterSrc = $matches[5];

                // Skip oversized images (10 MB decoded max)
                $decodedSize = (int)(strlen($base64Data) * 3 / 4);
                if ($decodedSize > 10 * 1024 * 1024) {
                    return $matches[0];
                }

                $counter++;
                $cid = 'inline_img_' . $counter . '_' . bin2hex(random_bytes(8));
                $safeName = 'pasted_image_' . $counter . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);

                $images[] = [
                    'content' => base64_decode($base64Data),
                    'cid' => $cid,
                    'name' => $safeName,
                    'type' => $mimeType,
                ];

                return '<img' . $beforeSrc . ' src="cid:' . $cid . '"' . $afterSrc . '>';
            },
            $html
        );
        if ($result !== null) {
            $html = $result;
        }

        if (!empty($images)) {
            error_log('[SmtpService] Converted ' . count($images) . ' inline image(s) to CID embeds');
        }

        return ['html' => $html, 'images' => $images];
    }

    /**
     * Strip line breaks and null bytes from header values.
     * IMAP servers often fold long headers (References, In-Reply-To) across
     * multiple lines. PHPMailer rejects values containing \r or \n.
     */
    private function sanitizeHeaderValue(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $value = str_replace("\0", '', $value);
        return preg_replace('/\s{2,}/', ' ', trim($value));
    }
}

