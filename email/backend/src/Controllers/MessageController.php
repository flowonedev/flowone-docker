<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\SmtpService;
use Webmail\Addons\EmailTracking\Services\TrackingService;
use Webmail\Services\DriveService;

class MessageController extends BaseController
{
    /**
     * Send a new email
     * POST /messages/send
     * 
     * When tracking is enabled, sends individual emails to each recipient
     * so each gets their own unique tracking pixel for accurate read tracking.
     */
    public function send(Request $request): Response
    {
        // Debug: write to a guaranteed file so we can trace send failures
        $debugLog = '/var/www/vps-email/backend/storage/logs/send-debug.log';
        $logEntry = function($msg) use ($debugLog) {
            @file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
        };
        $logEntry("=== SEND START === to=" . json_encode($request->input('to')) . ", subject=" . json_encode($request->input('subject')));
        
        try {
            $imapError = $this->requireImap($request);
            if ($imapError) {
                $logEntry("IMAP ERROR: requireImap failed");
                return $imapError;
            }

            $validation = $this->validateRequired($request, ['to']);
            if ($validation) {
                $logEntry("VALIDATION ERROR: missing 'to' field");
                return $validation;
            }

            $to = $request->input('to');
            if (!is_array($to)) {
                $to = [$to];
            }

            $cc = $request->input('cc', []);
            $bcc = $request->input('bcc', []);

            // Validate all recipients
            $allRecipients = array_merge($to, $cc, $bcc);
            foreach ($allRecipients as $recipient) {
                $email = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return Response::error("Invalid recipient email: $email", 400);
                }
            }

            // Deduplicate the merged recipient list keyed by lowercased email.
            // The per-recipient SMTP loop below sends ONE message per entry for
            // tracking pixels; without this dedup, a user appearing in both
            // To and Cc (or twice in Cc) would receive multiple physical copies
            // of the same email. From-self exclusion is added later, once we
            // know which account is actually sending.
            $allRecipients = $this->dedupeRecipients($allRecipients);

            // Check if sending from a secondary/linked account
            $fromAccountId = $request->input('from_account_id');
            $fromEmail = $request->input('from_email');
            $actualSenderEmail = $this->userEmail; // Track actual sender for Sent folder, conversations, etc.
            
            error_log("[MessageController::send] primaryUserEmail={$this->primaryUserEmail}, userEmail={$this->userEmail}, fromAccountId=" . ($fromAccountId ?? 'null') . ", fromEmail=" . ($fromEmail ?? 'null') . ", hasPassword=" . ($this->userPassword ? 'yes' : 'no') . ", isOAuth={$this->isOAuthSession}");
            
            if ($fromAccountId && $fromEmail) {
                // Sending from a secondary account - get its SMTP credentials
                error_log("[MessageController::send] Secondary account path: looking up account_id={$fromAccountId} for primary={$this->primaryUserEmail}");
                $secondaryAccount = $this->getSecondaryAccountCredentials((int)$fromAccountId);
                
                if (!$secondaryAccount) {
                    error_log("[MessageController::send] FAILED: Secondary account not found for id={$fromAccountId}, primary={$this->primaryUserEmail}");
                    return Response::error('Secondary account not found or credentials unavailable', 400);
                }
                
                error_log("[MessageController::send] Secondary account found: email={$secondaryAccount['email']}, is_oauth=" . ($secondaryAccount['is_oauth'] ? 'true' : 'false') . ", has_access_token=" . (!empty($secondaryAccount['access_token']) ? 'yes' : 'no') . ", has_password=" . (!empty($secondaryAccount['password']) ? 'yes' : 'no'));
                
                $actualSenderEmail = $secondaryAccount['email'];
                
                // Build SMTP config from secondary account settings
                $smtpConfig = $this->config['smtp'];
                if (!empty($secondaryAccount['smtp_host'])) {
                    $smtpConfig['host'] = $secondaryAccount['smtp_host'];
                }
                if (!empty($secondaryAccount['smtp_port'])) {
                    $smtpConfig['port'] = (int)$secondaryAccount['smtp_port'];
                }
                if (isset($secondaryAccount['smtp_encryption'])) {
                    $smtpConfig['encryption'] = $secondaryAccount['smtp_encryption'];
                }
                
                $smtp = new SmtpService($smtpConfig);
                
                if (!empty($secondaryAccount['is_oauth']) && $secondaryAccount['is_oauth']) {
                    // OAuth account (Google/Microsoft) - use access token from account credentials
                    if (!empty($secondaryAccount['access_token'])) {
                        $provider = $secondaryAccount['provider'] ?? 'google';
                        $smtp->setOAuthCredentials($secondaryAccount['email'], $secondaryAccount['access_token'], $provider);
                    } else {
                        return Response::error('OAuth token expired for secondary account. Please re-authenticate in Settings > Accounts.', 400);
                    }
                } else {
                    // Password-based account
                    if (empty($secondaryAccount['password'])) {
                        return Response::error('No password available for secondary account', 400);
                    }
                    $smtp->setCredentials($secondaryAccount['email'], $secondaryAccount['password']);
                }
                
                $logEntry("Sending from SECONDARY account: {$actualSenderEmail} (account_id: {$fromAccountId})");
                error_log("Sending from secondary account: {$actualSenderEmail} (account_id: {$fromAccountId})");
            } else {
                // Sending from primary account
                $logEntry("Primary account: email={$this->userEmail}, hasPassword=" . ($this->userPassword ? 'yes' : 'no') . ", isOAuth={$this->isOAuthSession}");
                error_log("[MessageController::send] Primary account path: email={$this->userEmail}, hasPassword=" . ($this->userPassword ? 'yes' : 'no') . ", isOAuth={$this->isOAuthSession}, provider=" . ($this->oauthProvider ?? 'none'));
                $smtp = new SmtpService($this->config['smtp']);
                
                if ($this->userPassword) {
                    $smtp->setCredentials($this->userEmail, $this->userPassword);
                } elseif ($this->isOAuthSession && $this->oauthProvider) {
                    // Get a valid (decrypted + refreshed) access token from the OAuth service
                    $accessToken = null;
                    if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                        $accessToken = $this->microsoftOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    } elseif ($this->googleOAuthService) {
                        $accessToken = $this->googleOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    }
                    
                    if ($accessToken) {
                        $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
                    } else {
                        return Response::error('OAuth token expired. Please re-authenticate.', 401);
                    }
                } else {
                    return Response::error('No credentials available to send email. Please log in again.', 401);
                }
            }

            // Strip the actual sender from the delivery list. Replying from a
            // linked Gmail account where you are also listed in the original
            // To/Cc used to deliver a copy back to yourself.
            $allRecipients = $this->excludeRecipient($allRecipients, $actualSenderEmail);

            $bodyHtml = $request->input('body_html', $request->input('body', ''));
            $bodyText = $request->input('body_text', '');
            $subject = $request->input('subject', '');
            $fromName = $request->input('from_name', '');
            // High-priority flag: when set, mark the message as important so
            // recipients' clients (Outlook, Apple Mail, etc.) show it as such.
            $importance = $request->input('important') ? 'high' : null;
            $trackingId = null;
            $recipientTokens = [];

            // Safety: if dedup + self-exclusion removed every recipient, fail
            // fast with a useful error rather than sending an SMTP envelope
            // with zero RCPT TO addresses.
            if (empty($allRecipients)) {
                $logEntry("ZERO recipients after dedup/self-exclude (from={$actualSenderEmail})");
                return Response::error('No recipients left after removing duplicates and your own address.', 400);
            }

            // Check if tracking is enabled (respects addon toggle)
            $enableTracking = (bool)$request->input('track_read', true); // Default enabled
            if ($enableTracking) {
                $addonService = new \Webmail\Services\AddonService($this->config);
                if (!$addonService->isEmailTrackingEnabled()) {
                    $enableTracking = false;
                }
            }
            
            // Generate shared Message-ID for threading (all recipients get same Message-ID)
            $sharedMessageId = '<' . bin2hex(random_bytes(16)) . '@' . ($_SERVER['HTTP_HOST'] ?? 'webmail') . '>';
            
            // Base URL for tracking pixels
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') 
                       . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            // Create tracking record if enabled (wrapped in try-catch so email still sends if tracking fails)
            if ($enableTracking && !empty($bodyHtml)) {
                try {
                    $trackingService = new TrackingService($this->config);
                    $trackingId = $trackingService->generateTrackingId();
                    
                    // Create tracking record - this also creates per-recipient tokens.
                    // Pass the shared Message-ID (the one written to the Sent copy,
                    // normalized without angle brackets) so notifications can later
                    // resolve the exact IMAP message regardless of age/folder.
                    $recipientTokens = $trackingService->createTracking(
                        $this->primaryUserEmail ?? $this->userEmail,
                        $trackingId,
                        $subject,
                        $allRecipients,
                        null,
                        trim($sharedMessageId, '<>')
                    );
                } catch (\Exception $e) {
                    error_log("TrackingService error (email will still send): " . $e->getMessage());
                    $enableTracking = false;
                    $trackingId = null;
                    $recipientTokens = [];
                }
            }

        // Prepare common params
        $attachments = $this->processAttachments($request->input('attachments', []));
        $inlineImages = $request->input('inline_images', []);
        
        // Ensure in_reply_to and references are strings (not arrays)
        $inReplyTo = $request->input('in_reply_to');
        if (is_array($inReplyTo)) {
            $inReplyTo = $inReplyTo[0] ?? null; // Take first element if array
        }
        $inReplyTo = $inReplyTo ? (string)$inReplyTo : null;
        
        $references = $request->input('references');
        if (is_array($references)) {
            $references = implode(' ', $references); // Join multiple references with space
        }
        $references = $references ? (string)$references : null;

        // Build display strings for cosmetic headers (so each recipient can see
        // who else got the email, even though they receive individual copies).
        // Dedupe so the visible header doesn't show the same address twice.
        $displayTo = $this->buildRecipientString($this->dedupeRecipients($to));
        $displayCc = $this->buildRecipientString($this->dedupeRecipients($cc));

        // Build the REAL (visible) To/Cc header lists used for each individual
        // delivery. Every recipient gets their own copy delivered only to them,
        // but these headers carry the full recipient list so everyone can see
        // who else received the email and "reply all" keeps the Cc.
        [$headerTo, $headerCc] = $this->buildHeaderRecipientLists($to, $cc);

        $firstRawMessage = null;
        $sentCount = 0;
        $failedRecipients = [];
        $smtpError = '';

        // Send individual emails per recipient so each gets a unique tracking
        // pixel for accurate per-recipient read tracking.
        // CRITICAL: each send gets its own unique Message-ID. The old code
        // reused one shared Message-ID across all sends, which caused
        // receiving servers (Gmail, Outlook) to silently de-duplicate and
        // drop emails -- especially when CC + attachments were involved.
        foreach ($allRecipients as $recipient) {
            $recipientEmail = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
            $recipientName = is_array($recipient) ? ($recipient['name'] ?? '') : '';

            if (empty($recipientEmail)) {
                continue;
            }

            // Generate a UNIQUE Message-ID for this individual send
            $individualMessageId = '<' . bin2hex(random_bytes(16)) . '@' . ($_SERVER['HTTP_HOST'] ?? 'webmail') . '>';

            // Prepare body with this recipient's unique tracking pixel + link rewriting
            $recipientBodyHtml = $bodyHtml;
            if ($enableTracking && !empty($bodyHtml) && !empty($recipientTokens)) {
                $token = $recipientTokens[strtolower($recipientEmail)] ?? $recipientTokens[$recipientEmail] ?? null;
                if ($token) {
                    $trackingService = $trackingService ?? new TrackingService($this->config);

                    $rewritten = $trackingService->rewriteLinks(
                        $recipientBodyHtml, $trackingId, $token, $baseUrl
                    );
                    if ($rewritten !== null && $rewritten !== '') {
                        $recipientBodyHtml = $rewritten;
                    }

                    $trackingPixel = $trackingService->getSingleRecipientPixel($token, $baseUrl);
                    if (stripos($recipientBodyHtml, '</body>') !== false) {
                        $recipientBodyHtml = str_ireplace('</body>', $trackingPixel . '</body>', $recipientBodyHtml);
                    } else {
                        $recipientBodyHtml .= $trackingPixel;
                    }
                }
            }

            $params = [
                // Visible headers list every To/Cc recipient; delivery is
                // restricted to this single envelope recipient (see SmtpService).
                'header_to' => $headerTo,
                'header_cc' => $headerCc,
                'envelope_to' => ['email' => $recipientEmail, 'name' => $recipientName],
                'subject' => $subject,
                'body_html' => $recipientBodyHtml,
                'body_text' => $bodyText,
                'from_name' => $fromName,
                'message_id' => $individualMessageId,
                'in_reply_to' => $inReplyTo,
                'references' => $references,
                'attachments' => $attachments,
                'inline_images' => $inlineImages,
                'display_to' => $displayTo,
                'display_cc' => $displayCc,
                'importance' => $importance,
            ];

            $logEntry("SMTP->send() calling for: {$recipientEmail} (MsgID: {$individualMessageId})");
            $result = $smtp->send($params);

            if ($result['success']) {
                $sentCount++;
                $logEntry("SMTP->send() SUCCESS for: {$recipientEmail}");
                if ($firstRawMessage === null) {
                    $firstRawMessage = $result['raw_message'] ?? null;
                }
            } else {
                $failedRecipients[] = $recipientEmail;
                $smtpError = $result['error'] ?? 'Unknown error';
                $logEntry("SMTP->send() FAILED for {$recipientEmail}: {$smtpError}");
                error_log("Failed to send to {$recipientEmail}: " . $smtpError);
            }
        }

        if ($sentCount === 0) {
            $failedList = implode(', ', $failedRecipients);
            $logEntry("ALL FAILED. Recipients: {$failedList}, lastError: {$smtpError}");
            error_log("[MessageController::send] ALL recipients failed. Recipients: {$failedList}, error: {$smtpError}");
            return Response::error('Failed to send email: ' . ($smtpError ?: 'Unknown SMTP error'), 500);
        }

        // Save ONE copy to Sent folder (with all recipients in headers)
        if ($firstRawMessage) {
            // Build a "master" message with all recipients for the Sent folder
            // Use the actual sender's SMTP service (may be secondary account)
            $sentMessage = $this->buildSentFolderMessage([
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'subject' => $subject,
                'body_html' => $bodyHtml, // Original body without tracking pixel
                'body_text' => $bodyText,
                'from_name' => $fromName,
                'message_id' => $sharedMessageId,
                'in_reply_to' => $inReplyTo,
                'references' => $references,
                'attachments' => $attachments,
                'inline_images' => $inlineImages,
                'importance' => $importance,
            ], $fromAccountId ? (int)$fromAccountId : null);
            
            $sentFolder = $this->findSentFolder();
            if ($sentFolder && $sentMessage) {
                $this->imap->saveToSent($sentMessage, $sentFolder);
            } elseif ($sentFolder) {
                // Fallback: strip tracking pixels from raw message before saving to Sent
                // This prevents sender's own opens from being counted as recipient reads
                $cleanMessage = $this->stripTrackingPixels($firstRawMessage);
                $this->imap->saveToSent($cleanMessage, $sentFolder);
            }
        }

        // Delete draft if this was sending from a draft
        $draftUid = $request->input('draft_uid');
        if ($draftUid) {
            $draftsFolder = $this->findDraftsFolder();
            if ($draftsFolder) {
                $this->imap->deleteMessage($draftsFolder, (int)$draftUid);
            }
        }

        // Record recipients as contacts for autocomplete
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            $contactsService = new \Webmail\Services\ContactsService($db, $this->config);
            $contactsService->recordContacts($this->primaryUserEmail ?? $this->userEmail, $allRecipients);
        } catch (\Exception $e) {
            error_log("Contact recording error: " . $e->getMessage());
        }
        
        // Update client activity for sent emails (status becomes "waiting")
        try {
            $clientService = new \Webmail\Services\ClientService($this->config);
            foreach ($allRecipients as $recipient) {
                $recipientEmail = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
                $recipientName = is_array($recipient) ? ($recipient['name'] ?? '') : '';
                if (!empty($recipientEmail)) {
                    $clientService->updateActivity($this->primaryUserEmail ?? $this->userEmail, $recipientEmail, 'outbound', $recipientName);
                }
            }
        } catch (\Exception $e) {
            error_log("Client activity update error: " . $e->getMessage());
        }

        // Save thread link if this is a reply
        if ($inReplyTo && !empty($sharedMessageId)) {
            $threadSentFolder = isset($sentFolder) ? $sentFolder : $this->findSentFolder();
            $this->saveThreadLink($sharedMessageId, $inReplyTo, $subject, $threadSentFolder);
        }

        // Build sent message details for frontend (for immediate thread display)
        $actualSentFolder = isset($sentFolder) ? $sentFolder : $this->findSentFolder();
        
        // Add sent message to conversation database immediately (so it appears in thread)
        // This ensures the sent reply/forward is part of the conversation right away
        try {
            $conversationService = new \Webmail\Services\ConversationService($this->config);
            
            // Parse references into array
            $referencesArray = [];
            if (!empty($references)) {
                $referencesArray = preg_split('/\s+/', trim($references));
                $referencesArray = array_filter($referencesArray);
            }
            
            $conversationService->assignMessageToConversation(
                $this->primaryUserEmail ?? $this->userEmail,
                $actualSentFolder ?? 'Sent',
                [
                    'uid' => 0, // Will be updated when Sent folder is synced
                    'message_id' => $sharedMessageId,
                    'subject' => $subject,
                    'date' => date('r'),
                    'from' => [['email' => $actualSenderEmail, 'name' => $fromName]],
                    // Persist the real recipients so a mirror-served Sent/Drafts
                    // list shows "To: <recipient>" instead of falling back to the
                    // sender. Without this, every sent row stored to_recipients
                    // NULL and the list rendered the user's own name.
                    'to' => $to,
                    'cc' => $cc,
                    'in_reply_to' => $inReplyTo,
                    'references' => $referencesArray,
                ]
            );
            error_log("[MessageController] Added sent message to conversation DB: $sharedMessageId");
        } catch (\Exception $e) {
            // Don't fail the send if conversation assignment fails
            error_log("[MessageController] Failed to add sent message to conversation: " . $e->getMessage());
        }

        // Real-time cross-device sync: signal that the Sent folder gained a
        // message so the user's OTHER devices refresh their Sent view / folder
        // counts. The real UID is assigned by IMAP (APPEND) and picked up by
        // the incremental fetch this event triggers, so uid=0 is fine here.
        // Best-effort; never fail the send on a Redis hiccup.
        try {
            $cache = new \Webmail\Services\RedisCacheService($this->config);
            $cache->publishNewMessage(
                $this->primaryUserEmail ?? $this->userEmail,
                $actualSentFolder ?? 'Sent',
                0,
                [
                    'subject'   => $subject,
                    'from'      => $actualSenderEmail,
                    'from_name' => $fromName,
                    'is_sent'   => true,
                    // This is the user's OWN outgoing copy landing in Sent — it
                    // must NEVER raise a device push. The flag rides on the
                    // MESSAGE_NEW payload so the Node sender drops the push
                    // explicitly (locale/sender-agnostic) while the event still
                    // refreshes the user's other devices' Sent view.
                    'no_push'   => true,
                ]
            );
        } catch (\Throwable $e) {
            error_log("[MessageController] Sent-folder realtime publish failed: " . $e->getMessage());
        }

        // Parse the outbound body for @mentions and persist them on the
        // sender's "Sent" copy so the per-message mention chips render on
        // sent mail too. Best-effort; never fail the send.
        try {
            $allRecipientEmails = [];
            foreach (array_merge($to, $cc, $bcc ?? []) as $r) {
                $em = is_array($r) ? ($r['email'] ?? '') : (string) $r;
                if ($em !== '') $allRecipientEmails[] = $em;
            }
            $processor = new \Webmail\Services\Mentions\MentionsProcessor($this->config);
            $processor->process(
                $this->primaryUserEmail ?? $this->userEmail,
                [
                    'message_id'   => $sharedMessageId,
                    'direction'    => 'outbound',
                    'sender_email' => $actualSenderEmail,
                    'subject'      => $subject,
                    'sent_at'      => date('Y-m-d H:i:s'),
                    'folder'       => $actualSentFolder ?? 'Sent',
                    'uid'          => null, // assigned by IMAP later; mention table tolerates NULL
                    'recipients'   => $allRecipientEmails,
                ],
                $bodyHtml,
                $bodyText
            );
        } catch (\Throwable $e) {
            error_log('[MessageController] Mention processing (outbound) failed: ' . $e->getMessage());
        }
        // Save sent attachments to Drive: Attachments/Sent/{Subject}/
        if (!empty($attachments)) {
            try {
                $this->saveAttachmentsToDrive($attachments, $subject);
            } catch (\Exception $e) {
                error_log("[MessageController] Failed to save attachments to Drive: " . $e->getMessage());
                // Don't fail the send if Drive save fails
            }
        }

        // Move Drive-attached files to Attachments/Sent/{Subject}/ folder
        $driveFileIds = $request->input('drive_file_ids', []);
        if (!empty($driveFileIds) && is_array($driveFileIds)) {
            try {
                $this->moveDriveFilesToSentFolder($driveFileIds, $subject);
            } catch (\Exception $e) {
                error_log("[MessageController] Failed to move Drive files to Sent folder: " . $e->getMessage());
            }
        }

        $sentMessageDetails = [
            'message_id' => trim($sharedMessageId, '<>'),
            'subject' => $subject,
            'from' => [['email' => $actualSenderEmail, 'name' => $fromName]],
            'to' => $to,
            'cc' => $cc,
            'timestamp' => time(),
            'date' => date('r'),
            'folder' => $actualSentFolder ?? 'Sent',
            'in_reply_to' => $inReplyTo ? trim($inReplyTo, '<>') : null,
            'is_sent' => true,
            'is_read' => true,
            'has_attachment' => !empty($attachments),
        ];

        $response = [
            'message_id' => $sharedMessageId,
            'tracking_id' => $trackingId,
            'sent_count' => $sentCount,
            'total_recipients' => count($allRecipients),
            'sent_message' => $sentMessageDetails,
        ];

        if (!empty($failedRecipients)) {
            $response['failed_recipients'] = $failedRecipients;
            return Response::success($response, 'Email sent to ' . $sentCount . ' of ' . count($allRecipients) . ' recipients');
        }

        $logEntry("=== SEND SUCCESS ===");
        return Response::success($response, 'Email sent successfully');
        
        } catch (\Throwable $e) {
            // Log the full error for debugging
            $debugLog = '/var/www/vps-email/backend/storage/logs/send-debug.log';
            @file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);
            error_log("MessageController::send FATAL ERROR: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            // Return a helpful error message
            return Response::error('Failed to send email: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Build a recipient string for display headers
     */
    private function buildRecipientString(array $recipients): string
    {
        $parts = [];
        foreach ($recipients as $recipient) {
            if (is_array($recipient)) {
                $email = $recipient['email'] ?? '';
                $name = $recipient['name'] ?? '';
                if ($email) {
                    $parts[] = $name ? "$name <$email>" : $email;
                }
            } elseif (!empty($recipient)) {
                $parts[] = $recipient;
            }
        }
        return implode(', ', $parts);
    }

    /**
     * Extract a lowercased email from a recipient entry.
     * Accepts either ['email' => ..., 'name' => ...] or a raw string.
     */
    private function recipientEmailLower($recipient): string
    {
        $email = is_array($recipient) ? ($recipient['email'] ?? '') : (string)$recipient;
        return strtolower(trim($email));
    }

    /**
     * Deduplicate a recipient list keyed by lowercased email. Keeps the first
     * occurrence (so the To-position name/display wins over a later Cc copy).
     */
    private function dedupeRecipients(array $recipients): array
    {
        $seen = [];
        $unique = [];
        foreach ($recipients as $recipient) {
            $email = $this->recipientEmailLower($recipient);
            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $unique[] = $recipient;
        }
        return $unique;
    }

    /**
     * Build the visible To/Cc header lists for individual per-recipient
     * delivery. Each list is deduped, then anyone present in To is removed
     * from Cc (To wins) so the same address is never shown in both headers.
     * Bcc is intentionally excluded from both lists. Returns [$headerTo, $headerCc].
     */
    private function buildHeaderRecipientLists(array $to, array $cc): array
    {
        $headerTo = $this->dedupeRecipients($to);
        $headerCc = $this->dedupeRecipients($cc);

        $toEmails = [];
        foreach ($headerTo as $recipient) {
            $toEmails[$this->recipientEmailLower($recipient)] = true;
        }
        $headerCc = array_values(array_filter($headerCc, function ($recipient) use ($toEmails) {
            return !isset($toEmails[$this->recipientEmailLower($recipient)]);
        }));

        return [$headerTo, $headerCc];
    }

    /**
     * Remove a single email (case-insensitive) from a recipient list.
     * Used to keep the sender out of their own delivery list when their
     * address happens to appear in the merged To/Cc/Bcc.
     */
    private function excludeRecipient(array $recipients, string $email): array
    {
        $needle = strtolower(trim($email));
        if ($needle === '') {
            return $recipients;
        }
        $filtered = [];
        foreach ($recipients as $recipient) {
            if ($this->recipientEmailLower($recipient) === $needle) {
                continue;
            }
            $filtered[] = $recipient;
        }
        return $filtered;
    }

    /**
     * Save a thread link for conversation threading
     */
    private function saveThreadLink(string $sentMessageId, ?string $inReplyTo, string $subject, ?string $folder): void
    {
        // Guard against null/empty values
        if (empty($inReplyTo)) {
            return;
        }
        
        try {
            $threadsDir = '/var/www/vps-email/data/threads';
            
            // Create directory if needed
            if (!is_dir($threadsDir)) {
                mkdir($threadsDir, 0755, true);
            }
            
            // Get file path
            $normalizedEmail = strtolower($this->userEmail);
            $hash = md5($normalizedEmail);
            $file = $threadsDir . '/' . $hash . '.json';
            
            // Load existing data
            $data = ['links' => []];
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if ($content) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            }
            
            // Normalize message IDs
            $sentMessageId = trim($sentMessageId, '<>');
            $inReplyTo = trim($inReplyTo, '<>');
            
            // Save the link
            $data['links'][$sentMessageId] = [
                'in_reply_to' => $inReplyTo,
                'subject' => $subject,
                'timestamp' => time(),
                'folder' => $folder ?? 'INBOX.Sent',
                'created_at' => time(),
            ];
            
            // Write file
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            error_log("Thread link saved: $sentMessageId -> $inReplyTo");
        } catch (\Exception $e) {
            error_log("Failed to save thread link: " . $e->getMessage());
        }
    }

    /**
     * Build a complete message for saving to Sent folder
     * This includes all recipients in the headers
     */
    private function buildSentFolderMessage(array $params, ?int $fromAccountId = null): ?string
    {
        try {
            if ($fromAccountId) {
                // Secondary account - use its SMTP settings for "From" header
                $secondaryAccount = $this->getSecondaryAccountCredentials($fromAccountId);
                if (!$secondaryAccount) {
                    return null;
                }
                
                $smtpConfig = $this->config['smtp'];
                if (!empty($secondaryAccount['smtp_host'])) {
                    $smtpConfig['host'] = $secondaryAccount['smtp_host'];
                }
                
                $smtp = new SmtpService($smtpConfig);
                
                if (!empty($secondaryAccount['is_oauth']) && $secondaryAccount['is_oauth']) {
                    if (!empty($secondaryAccount['access_token'])) {
                        $smtp->setOAuthCredentials($secondaryAccount['email'], $secondaryAccount['access_token'], $secondaryAccount['provider'] ?? 'google');
                    } else {
                        return null;
                    }
                } else {
                    $smtp->setCredentials($secondaryAccount['email'], $secondaryAccount['password'] ?? '');
                }
            } else {
                // Primary account
                $smtp = new SmtpService($this->config['smtp']);
                
                if ($this->userPassword) {
                    $smtp->setCredentials($this->userEmail, $this->userPassword);
                } elseif ($this->isOAuthSession && $this->oauthProvider) {
                    $accessToken = null;
                    if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                        $accessToken = $this->microsoftOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    } elseif ($this->googleOAuthService) {
                        $accessToken = $this->googleOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    }
                    if ($accessToken) {
                        $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
            }
            
            return $smtp->buildDraftMessage($params);
        } catch (\Exception $e) {
            error_log("Error building sent folder message: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Strip tracking pixels from raw email message
     * This is used to clean the message before saving to Sent folder
     * to prevent sender's own opens from being counted as recipient reads
     * 
     * Handles MIME-encoded bodies (base64, quoted-printable) by decoding
     * each part, stripping pixels, then re-encoding before reassembly.
     */
    private function stripTrackingPixels(string $rawMessage): string
    {
        $patterns = [
            // Standard tracking pixel pattern
            '/<img[^>]*src=["\'][^"\']*\/api\/track\/[a-f0-9]+\.gif["\'][^>]*\/?>/i',
            // Also match any 1x1 tracking pixels with display:none
            '/<img[^>]*width=["\']1["\'][^>]*height=["\']1["\'][^>]*style=["\'][^"\']*display:\s*none["\'][^>]*\/?>/i',
        ];
        
        // First try direct regex on raw message (works for 7bit/8bit/unencoded)
        $cleaned = $rawMessage;
        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }
        
        // If direct regex made changes, we're done
        if ($cleaned !== $rawMessage) {
            return $cleaned;
        }
        
        // Otherwise, handle MIME-encoded parts (base64, quoted-printable)
        // Split the message into MIME parts and decode each HTML part for stripping
        return preg_replace_callback(
            '/^(Content-Transfer-Encoding:\s*(base64|quoted-printable)\s*\r?\n(?:.*?\r?\n)*?\r?\n)([\s\S]*?)(?=\r?\n--|\Z)/mi',
            function ($matches) use ($patterns) {
                $headers = $matches[1];
                $encoding = strtolower(trim($matches[2]));
                $encodedBody = trim($matches[3]);
                
                // Decode the body
                if ($encoding === 'base64') {
                    $decoded = base64_decode($encodedBody);
                } elseif ($encoding === 'quoted-printable') {
                    $decoded = quoted_printable_decode($encodedBody);
                } else {
                    return $matches[0];
                }
                
                if ($decoded === false) {
                    return $matches[0];
                }
                
                // Check if this part contains HTML with our tracking pixel
                $hasPixel = false;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $decoded)) {
                        $hasPixel = true;
                        break;
                    }
                }
                
                if (!$hasPixel) {
                    return $matches[0];
                }
                
                // Strip tracking pixels from decoded content
                $stripped = $decoded;
                foreach ($patterns as $pattern) {
                    $stripped = preg_replace($pattern, '', $stripped);
                }
                
                // Re-encode the body
                if ($encoding === 'base64') {
                    $reEncoded = rtrim(chunk_split(base64_encode($stripped), 76, "\r\n"));
                } else {
                    $reEncoded = quoted_printable_encode($stripped);
                }
                
                return $headers . $reEncoded;
            },
            $rawMessage
        );
    }

    /**
     * Save sent attachments to Drive in folder: Attachments/Sent/{Email Subject}/
     * Creates the folder hierarchy if it doesn't exist.
     */
    private function saveAttachmentsToDrive(array $attachments, string $subject): void
    {
        if (empty($this->userEmail) || empty($attachments)) {
            return;
        }

        $driveService = new DriveService($this->config, $this->userEmail);

        // Get or create "Attachments" root folder
        $attachmentsFolder = $driveService->findFolderByName($this->userEmail, 'Attachments', null);
        if (!$attachmentsFolder) {
            $attachmentsFolder = $driveService->createFolder($this->userEmail, 'Attachments', null);
            if (!$attachmentsFolder) {
                error_log("[MessageController] Failed to create Attachments folder");
                return;
            }
        }

        // Get or create "Sent" subfolder inside Attachments
        $sentFolder = $driveService->findFolderByName($this->userEmail, 'Sent', (int)$attachmentsFolder['id']);
        if (!$sentFolder) {
            $sentFolder = $driveService->createFolder($this->userEmail, 'Sent', (int)$attachmentsFolder['id']);
            if (!$sentFolder) {
                error_log("[MessageController] Failed to create Attachments/Sent folder");
                return;
            }
        }

        // Sanitize subject for folder name (remove characters not safe for display)
        $safeName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $subject);
        $safeName = trim($safeName);
        if (empty($safeName)) {
            $safeName = 'No Subject';
        }
        // Limit folder name length
        if (mb_strlen($safeName) > 100) {
            $safeName = mb_substr($safeName, 0, 100);
        }

        // Get or create subject folder inside Sent
        $subjectFolder = $driveService->findFolderByName($this->userEmail, $safeName, (int)$sentFolder['id']);
        if (!$subjectFolder) {
            $subjectFolder = $driveService->createFolder($this->userEmail, $safeName, (int)$sentFolder['id']);
            if (!$subjectFolder) {
                error_log("[MessageController] Failed to create subject folder: $safeName");
                return;
            }
        }

        $folderId = (int)$subjectFolder['id'];
        $savedCount = 0;

        foreach ($attachments as $attachment) {
            $name = $attachment['name'] ?? 'attachment';
            $content = null;

            if (isset($attachment['path']) && file_exists($attachment['path'])) {
                $content = file_get_contents($attachment['path']);
            } elseif (isset($attachment['content'])) {
                $content = $attachment['content']; // Already decoded binary content
            }

            if ($content === null || $content === false) {
                error_log("[MessageController] Could not read attachment content: $name");
                continue;
            }

            $mimeType = $attachment['type'] ?? 'application/octet-stream';
            $result = $driveService->uploadFileContent($this->userEmail, $name, $content, $mimeType, $folderId);
            
            if ($result) {
                $savedCount++;
            } else {
                error_log("[MessageController] Failed to save attachment to Drive: $name");
            }
        }

        error_log("[MessageController] Saved $savedCount/" . count($attachments) . " attachments to Drive: Attachments/Sent/$safeName/");
    }

    /**
     * Move Drive-attached files to Attachments/Sent/{Subject}/ folder.
     * Called after a successful email send to organize Drive files that were
     * attached via compose (they may be in root or any random folder).
     */
    private function moveDriveFilesToSentFolder(array $driveFileIds, string $subject): void
    {
        if (empty($this->userEmail) || empty($driveFileIds)) {
            return;
        }

        $driveService = new DriveService($this->config, $this->userEmail);

        // Get or create "Attachments" root folder
        $attachmentsFolder = $driveService->findFolderByName($this->userEmail, 'Attachments', null);
        if (!$attachmentsFolder) {
            $attachmentsFolder = $driveService->createFolder($this->userEmail, 'Attachments', null);
            if (!$attachmentsFolder) {
                error_log("[MessageController] Failed to create Attachments folder for Drive file move");
                return;
            }
        }

        // Get or create "Sent" subfolder inside Attachments
        $sentFolder = $driveService->findFolderByName($this->userEmail, 'Sent', (int)$attachmentsFolder['id']);
        if (!$sentFolder) {
            $sentFolder = $driveService->createFolder($this->userEmail, 'Sent', (int)$attachmentsFolder['id']);
            if (!$sentFolder) {
                error_log("[MessageController] Failed to create Attachments/Sent folder for Drive file move");
                return;
            }
        }

        // Sanitize subject for folder name
        $safeName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $subject);
        $safeName = trim($safeName);
        if (empty($safeName)) {
            $safeName = 'No Subject';
        }
        if (mb_strlen($safeName) > 100) {
            $safeName = mb_substr($safeName, 0, 100);
        }

        // Get or create subject folder inside Sent
        $subjectFolder = $driveService->findFolderByName($this->userEmail, $safeName, (int)$sentFolder['id']);
        if (!$subjectFolder) {
            $subjectFolder = $driveService->createFolder($this->userEmail, $safeName, (int)$sentFolder['id']);
            if (!$subjectFolder) {
                error_log("[MessageController] Failed to create subject folder for Drive file move: $safeName");
                return;
            }
        }

        $targetFolderId = (int)$subjectFolder['id'];
        $movedCount = 0;

        error_log("[MessageController] moveDriveFiles: userEmail={$this->userEmail}, targetFolder=$targetFolderId, fileIds=" . json_encode($driveFileIds));

        foreach ($driveFileIds as $fileId) {
            $fileId = (int)$fileId;
            if ($fileId <= 0) continue;

            // Debug: check if file exists for this user
            $file = $driveService->getFile($this->userEmail, $fileId);
            if (!$file) {
                error_log("[MessageController] Drive file #$fileId NOT FOUND for user {$this->userEmail} - trying direct DB query");
                // Fallback: try to move by ID only (in case email casing differs)
                $db = $driveService->getDb();
                $stmt = $db->prepare('SELECT id, user_email, original_name, folder_id FROM drive_files WHERE id = ?');
                $stmt->execute([$fileId]);
                $dbFile = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($dbFile) {
                    error_log("[MessageController] File #$fileId found in DB: user_email={$dbFile['user_email']}, name={$dbFile['original_name']}, folder_id={$dbFile['folder_id']}");
                    // Only organize transient, root-level uploads. A file that
                    // already lives in a folder was picked from the user's own
                    // Drive and must stay where they put it.
                    if (!empty($dbFile['folder_id'])) {
                        error_log("[MessageController] Skipping move for file #$fileId - already filed in folder {$dbFile['folder_id']}");
                        continue;
                    }
                    // Move using the actual user_email from DB
                    $moved = $driveService->moveFile($dbFile['user_email'], $fileId, $targetFolderId);
                } else {
                    error_log("[MessageController] File #$fileId does NOT exist in drive_files table at all!");
                    continue;
                }
            } else {
                // Only organize transient, root-level uploads. A file already
                // sitting in a folder was picked from the user's own Drive, so
                // moving it would silently relocate their files out of the
                // folder they chose (the EletfaBackup bug). Leave it in place.
                if (!empty($file['folder_id'])) {
                    error_log("[MessageController] Skipping move for file #$fileId ({$file['original_name']}) - already filed in folder {$file['folder_id']}");
                    continue;
                }
                error_log("[MessageController] Moving file #$fileId ({$file['original_name']}) from folder_id={$file['folder_id']} to $targetFolderId");
                $moved = $driveService->moveFile($this->userEmail, $fileId, $targetFolderId);
            }

            if ($moved) {
                $movedCount++;
            } else {
                error_log("[MessageController] moveFile returned false for file #$fileId");
            }
        }

        error_log("[MessageController] Moved $movedCount/" . count($driveFileIds) . " Drive files to Attachments/Sent/$safeName/");
    }

    /**
     * Schedule an email for later sending
     * POST /messages/schedule
     */
    public function scheduleSend(Request $request): Response
    {
        try {
            $imapError = $this->requireImap($request);
            if ($imapError) {
                return $imapError;
            }

            $draftUid = $request->input('draft_uid');
            $rawAttachments = $request->input('attachments', []);
            if ($draftUid) {
                $draftsFolder = $this->findDraftsFolder();
                if ($draftsFolder) {
                    $rawAttachments = $this->rehydrateDraftAttachments($rawAttachments, $draftsFolder, $draftUid);
                }
            }

            $validation = $this->validateRequired($request, ['to', 'subject', 'scheduled_at']);
            if ($validation) {
                return $validation;
            }

            $scheduledAt = $request->input('scheduled_at');
            $timezone = $request->input('timezone', 'UTC');
            $scheduleKind = $request->input('schedule_kind', 'scheduled_send');
            if (!in_array($scheduleKind, ['scheduled_send', 'undo_send'], true)) {
                $scheduleKind = 'scheduled_send';
            }
            
            // Validate scheduled_at is in the future
            // Frontend sends UTC (via toISOString), so parse both in UTC
            $scheduledTime = new \DateTime($scheduledAt, new \DateTimeZone('UTC'));
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            if ($scheduleKind === 'undo_send') {
                // Allow up to 30s in the past to tolerate clock skew / network latency
                $now->modify('-30 seconds');
            }
            if ($scheduledTime <= $now) {
                return Response::error('Scheduled time must be in the future', 400);
            }

            // Normalize to MySQL datetime format (Y-m-d H:i:s)
            $scheduledAt = $scheduledTime->format('Y-m-d H:i:s');

            // Serialize attachments to persistent storage
            $attachResult = $this->serializeScheduledAttachments($rawAttachments);
            $serializedAttachments = $attachResult['attachments'];
            $failedAttachments = $attachResult['failed'];

            // If any attachments failed to serialize, abort so user knows
            if (!empty($failedAttachments)) {
                error_log("[scheduleSend] Attachment serialization failed for: " . implode(', ', $failedAttachments));
                return Response::error(
                    'Failed to process attachment(s): ' . implode(', ', $failedAttachments) . '. Please try again.',
                    500
                );
            }

            // Build the email payload (store everything needed to send later)
            $payload = [
                'to' => $request->input('to', []),
                'cc' => $request->input('cc', []),
                'bcc' => $request->input('bcc', []),
                'subject' => $request->input('subject'),
                'body_html' => $request->input('body_html', $request->input('body', '')),
                'body_text' => $request->input('body_text', ''),
                'from_name' => $request->input('from_name', ''),
                'attachments' => $serializedAttachments,
                'in_reply_to' => $request->input('in_reply_to'),
                'references' => $request->input('references'),
                'track_read' => $request->input('track_read', true),
                'important' => (bool)$request->input('important', false),
                'draft_uid' => $draftUid,
                'from_account_id' => $request->input('from_account_id'),
                'from_email' => $request->input('from_email'),
                'drive_file_ids' => $request->input('drive_file_ids', []),
            ];

            // Store encrypted SMTP credentials so cron can send later
            if ($this->userPassword) {
                $payload['_encrypted_password'] = $this->session->encryptPassword($this->userPassword);
            } elseif ($this->isOAuthSession && $this->oauthProvider) {
                $accessToken = null;
                if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                    $accessToken = $this->microsoftOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                } elseif ($this->googleOAuthService) {
                    $accessToken = $this->googleOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                }
                if ($accessToken) {
                    $payload['_oauth_token'] = $accessToken;
                    $payload['_oauth_provider'] = $this->oauthProvider;
                }
            }

            // Store base URL for tracking pixels
            $payload['_base_url'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') 
                                    . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            $service = new \Webmail\Services\ScheduledEmailService($this->config);
            $result = $service->schedule(
                $this->primaryUserEmail ?? $this->userEmail,
                $payload,
                $scheduledAt,
                $timezone,
                $scheduleKind
            );

            if ($result['success']) {
                return Response::success($result, 'Email scheduled successfully');
            } else {
                return Response::error($result['error'] ?? 'Failed to schedule email', 500);
            }
        } catch (\Throwable $e) {
            error_log("MessageController::scheduleSend error: " . $e->getMessage());
            return Response::error('Failed to schedule email: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancel a scheduled email
     * DELETE /messages/schedule/{scheduleId}
     */
    public function cancelScheduled(Request $request): Response
    {
        try {
            $authError = $this->requireAuth($request);
            if ($authError) {
                return $authError;
            }

            $scheduleId = $request->param('scheduleId');
            if (!$scheduleId) {
                return Response::error('Schedule ID is required', 400);
            }

            $undoSendOnly = (bool)($request->getQuery('undo_send') ?? $request->input('undo_send', false));

            $service = new \Webmail\Services\ScheduledEmailService($this->config);

            $scheduled = $service->getScheduledById($scheduleId, $this->primaryUserEmail ?? $this->userEmail);
            $result = $service->cancel($scheduleId, $this->primaryUserEmail ?? $this->userEmail, $undoSendOnly);

            if ($result['success']) {
                if ($scheduled) {
                    $this->cleanupScheduledAttachmentFiles($scheduled['email_payload']['attachments'] ?? []);
                }
                return Response::success(null, 'Scheduled email cancelled');
            } else {
                $code = ($result['error'] === 'too_late') ? 409 : 404;
                return Response::error($result['message'] ?? $result['error'] ?? 'Failed to cancel', $code);
            }
        } catch (\Throwable $e) {
            error_log("MessageController::cancelScheduled error: " . $e->getMessage());
            return Response::error('Failed to cancel scheduled email', 500);
        }
    }

    /**
     * Get pending scheduled emails
     * GET /messages/scheduled
     */
    public function getScheduled(Request $request): Response
    {
        try {
            $authError = $this->requireAuth($request);
            if ($authError) {
                return $authError;
            }

            $service = new \Webmail\Services\ScheduledEmailService($this->config);
            $scheduled = $service->getScheduled($this->primaryUserEmail ?? $this->userEmail);

            return Response::success(['scheduled' => $scheduled]);
        } catch (\Throwable $e) {
            error_log("MessageController::getScheduled error: " . $e->getMessage());
            return Response::error('Failed to fetch scheduled emails', 500);
        }
    }

    /**
     * Get a single scheduled email with full payload (for editing)
     * GET /messages/schedule/{scheduleId}
     */
    public function getScheduledById(Request $request): Response
    {
        try {
            $authError = $this->requireAuth($request);
            if ($authError) {
                return $authError;
            }

            $scheduleId = $request->param('scheduleId');
            if (!$scheduleId) {
                return Response::error('Schedule ID is required', 400);
            }

            $service = new \Webmail\Services\ScheduledEmailService($this->config);
            $result = $service->getScheduledById($scheduleId, $this->userEmail);

            if (!$result) {
                return Response::error('Scheduled email not found', 404);
            }

            return Response::success(['scheduled' => $result]);
        } catch (\Throwable $e) {
            error_log("MessageController::getScheduledById error: " . $e->getMessage());
            return Response::error('Failed to fetch scheduled email', 500);
        }
    }

    /**
     * Save draft
     * POST /messages/draft
     */
    public function saveDraft(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $smtp = new SmtpService($this->config['smtp']);
        
        // Set credentials (password or OAuth)
        if ($this->userPassword) {
            $smtp->setCredentials($this->userEmail, $this->userPassword);
        } elseif ($this->isOAuthSession && $this->oauthProvider) {
            $accessToken = null;
            if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                $accessToken = $this->microsoftOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
            } elseif ($this->googleOAuthService) {
                $accessToken = $this->googleOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
            }
            if ($accessToken) {
                $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
            } else {
                return Response::error('OAuth token expired. Please re-authenticate.', 401);
            }
        } else {
            return Response::error('No credentials available. Please log in again.', 401);
        }

        $draftsFolder = $this->findDraftsFolder();
        if (!$draftsFolder) {
            return Response::error('Drafts folder not found', 500);
        }

        $oldDraftUid = $request->input('draft_uid');
        $rawAttachments = $request->input('attachments', []);

        // Re-fetch IMAP-based attachments from the old draft before deleting it
        $rawAttachments = $this->rehydrateDraftAttachments($rawAttachments, $draftsFolder, $oldDraftUid);

        $params = [
            'to' => $request->input('to', []),
            'cc' => $request->input('cc', []),
            'subject' => $request->input('subject', ''),
            'body_html' => $request->input('body_html', $request->input('body', '')),
            'body_text' => $request->input('body_text', ''),
            'from_name' => $request->input('from_name', ''),
            'attachments' => $this->processAttachments($rawAttachments),
            'importance' => $request->input('important') ? 'high' : null,
        ];

        $rawMessage = $smtp->buildDraftMessage($params);

        if (empty($rawMessage)) {
            return Response::error('Failed to build draft message', 500);
        }

        // Delete old draft if updating
        if ($oldDraftUid) {
            $this->imap->deleteMessage($draftsFolder, (int)$oldDraftUid);
        }

        // Save draft and get the new UID
        $newUid = $this->imap->saveDraftAndGetUid($rawMessage, $draftsFolder);

        if (!$newUid) {
            return Response::error('Failed to save draft', 500);
        }

        return Response::success(['uid' => $newUid], 'Draft saved');
    }

    /**
     * Reply to a message
     * POST /messages/{uid}/reply
     */
    public function reply(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $request->getQuery('folder', 'INBOX');
        $uid = (int)$request->getParam('uid');

        // Get original message
        $original = $this->imap->getMessage($folder, $uid);
        if (!$original) {
            return Response::notFound('Original message not found');
        }

        // Determine recipients
        $replyAll = (bool)$request->input('reply_all', false);
        $to = [];
        $cc = [];

        // Reply to sender (or reply-to if set)
        if (!empty($original['reply_to'])) {
            $to = $original['reply_to'];
        } elseif (!empty($original['from'])) {
            $to = $original['from'];
        }

        // Reply all includes original recipients
        if ($replyAll) {
            foreach ($original['to'] ?? [] as $recipient) {
                if (strtolower($recipient['email']) !== strtolower($this->userEmail)) {
                    $cc[] = $recipient;
                }
            }
            foreach ($original['cc'] ?? [] as $recipient) {
                if (strtolower($recipient['email']) !== strtolower($this->userEmail)) {
                    $cc[] = $recipient;
                }
            }
        }

        // Build reply subject
        $subject = $original['subject'] ?? '';
        if (!preg_match('/^Re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        // Get message ID for threading
        $messageId = '';
        $references = '';
        // These would come from the original message headers

        $request->setParam('uid', null); // Clear for send
        
        return Response::success([
            'to' => $to,
            'cc' => $cc,
            'subject' => $subject,
            'in_reply_to' => $messageId,
            'references' => $references,
            'original' => [
                'from' => $original['from'],
                'date' => $original['date'],
                'body_html' => $original['body_html'] ?? '',
                'body_text' => $original['body_text'] ?? '',
            ],
        ]);
    }

    /**
     * Forward a message
     * POST /messages/{uid}/forward
     */
    public function forward(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $folder = $request->getQuery('folder', 'INBOX');
        $uid = (int)$request->getParam('uid');

        // Get original message
        $original = $this->imap->getMessage($folder, $uid);
        if (!$original) {
            return Response::notFound('Original message not found');
        }

        // Build forward subject
        $subject = $original['subject'] ?? '';
        if (!preg_match('/^Fwd:/i', $subject)) {
            $subject = 'Fwd: ' . $subject;
        }

        return Response::success([
            'subject' => $subject,
            'original' => [
                'from' => $original['from'],
                'to' => $original['to'],
                'date' => $original['date'],
                'subject' => $original['subject'],
                'body_html' => $original['body_html'] ?? '',
                'body_text' => $original['body_text'] ?? '',
                'attachments' => $original['attachments'] ?? [],
            ],
        ]);
    }

    /**
     * Upload attachment
     * POST /attachments/upload
     */
    public function uploadAttachment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $file = $request->getFile('file');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::error('No file uploaded or upload error', 400);
        }

        $maxSize = $this->config['upload']['max_size'] ?? 25 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return Response::error('File too large', 400);
        }

        // Create temp directory if needed
        $tempDir = $this->config['upload']['temp_dir'] ?? '/tmp/webmail_attachments';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Generate unique filename
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('attach_') . ($ext ? ".$ext" : '');
        $path = $tempDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return Response::error('Failed to save uploaded file', 500);
        }

        return Response::success([
            'id' => $filename,
            'name' => $file['name'],
            'size' => $file['size'],
            'type' => $file['type'],
            'path' => $path,
        ], 'File uploaded');
    }

    /**
     * Re-fetch IMAP-based attachments from the old draft message.
     * Attachments that came from IMAP have a 'part' field but no 'id' or 'content'.
     * This fetches their binary content and saves to temp files so processAttachments can handle them.
     */
    private function rehydrateDraftAttachments(array $attachments, string $draftsFolder, $oldDraftUid): array
    {
        if (!$oldDraftUid) {
            return $attachments;
        }

        $tempDir = $this->config['upload']['temp_dir'] ?? '/tmp/webmail_attachments';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $result = [];
        foreach ($attachments as $attachment) {
            if (isset($attachment['part']) && !isset($attachment['id']) && !isset($attachment['content'])) {
                $partData = $this->imap->getAttachment($draftsFolder, (int)$oldDraftUid, $attachment['part']);
                if ($partData && !empty($partData['content'])) {
                    $ext = pathinfo($partData['filename'] ?? '', PATHINFO_EXTENSION);
                    $tempName = uniqid('draft_attach_') . ($ext ? ".$ext" : '');
                    $tempPath = $tempDir . '/' . $tempName;
                    file_put_contents($tempPath, $partData['content']);

                    $result[] = [
                        'id' => $tempName,
                        'name' => $attachment['name'] ?? $attachment['filename'] ?? $partData['filename'] ?? 'attachment',
                        'type' => $attachment['type'] ?? $partData['type'] ?? 'application/octet-stream',
                        'size' => strlen($partData['content']),
                    ];
                } else {
                    error_log("Failed to re-fetch draft attachment part={$attachment['part']} uid=$oldDraftUid");
                }
            } else {
                $result[] = $attachment;
            }
        }

        return $result;
    }

    /**
     * Process attachments for sending
     */
    private function processAttachments(array $attachments): array
    {
        $processed = [];
        $tempDir = $this->config['upload']['temp_dir'] ?? '/tmp/webmail_attachments';

        error_log("Processing attachments from dir: $tempDir");
        error_log("Attachments input: " . json_encode($attachments));

        foreach ($attachments as $attachment) {
            if (isset($attachment['id'])) {
                $path = $tempDir . '/' . $attachment['id'];
                error_log("Looking for attachment at: $path");
                if (file_exists($path)) {
                    error_log("Found attachment: $path");
                    $processed[] = [
                        'path' => $path,
                        'name' => $attachment['name'] ?? $attachment['id'],
                        'type' => $attachment['type'] ?? mime_content_type($path),
                    ];
                } else {
                    error_log("Attachment NOT FOUND: $path");
                }
            } elseif (isset($attachment['content'])) {
                $processed[] = [
                    'content' => base64_decode($attachment['content']),
                    'name' => $attachment['name'] ?? 'attachment',
                    'type' => $attachment['type'] ?? 'application/octet-stream',
                ];
            } elseif (isset($attachment['content_base64'])) {
                $decoded = base64_decode((string)$attachment['content_base64'], true);
                if ($decoded !== false) {
                    $processed[] = [
                        'content' => $decoded,
                        'name' => $attachment['name'] ?? 'attachment',
                        'type' => $attachment['type'] ?? 'application/octet-stream',
                    ];
                } else {
                    error_log("Attachment content_base64 decode failed for: " . ($attachment['name'] ?? 'attachment'));
                }
            } elseif (isset($attachment['part']) && isset($attachment['source_uid'])) {
                // Forwarded attachment: an IMAP part reference from the original
                // message that carries no bytes. Fetch the content directly from
                // IMAP so it can be re-attached to the outgoing mail.
                $srcFolder = $attachment['source_folder'] ?? 'INBOX';
                $partData = $this->imap->getAttachment(
                    $srcFolder,
                    (int)$attachment['source_uid'],
                    (string)$attachment['part']
                );
                if ($partData && !empty($partData['content'])) {
                    if (!is_dir($tempDir)) {
                        mkdir($tempDir, 0755, true);
                    }
                    $name = $attachment['name'] ?? $attachment['filename'] ?? $partData['filename'] ?? 'attachment';
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $tempName = uniqid('fwd_attach_') . ($ext ? ".$ext" : '');
                    $tempPath = $tempDir . '/' . $tempName;
                    file_put_contents($tempPath, $partData['content']);
                    $processed[] = [
                        'path' => $tempPath,
                        'name' => $name,
                        'type' => $attachment['type'] ?? $partData['type'] ?? 'application/octet-stream',
                    ];
                } else {
                    error_log("Forwarded attachment re-fetch failed part={$attachment['part']} uid={$attachment['source_uid']} folder={$srcFolder}");
                }
            }
        }

        error_log("Processed attachments count: " . count($processed));
        return $processed;
    }

    /**
     * Serialize attachments for scheduled sends.
     * Copies attachment files to persistent storage so they survive
     * temp-directory cleanup before the cron sends them.
     *
     * @return array{attachments: array, failed: array}
     */
    private function serializeScheduledAttachments(array $attachments): array
    {
        $serialized = [];
        $failed = [];
        $schedDir = dirname(__DIR__, 2) . '/storage/scheduled-attachments';
        if (!is_dir($schedDir)) {
            if (!@mkdir($schedDir, 0755, true) && !is_dir($schedDir)) {
                error_log("[serializeScheduledAttachments] FATAL: Cannot create dir: $schedDir");
                return ['attachments' => [], 'failed' => array_map(fn($a) => $a['name'] ?? 'attachment', $attachments)];
            }
        }

        $processed = $this->processAttachments($attachments);
        if (empty($processed) && !empty($attachments)) {
            error_log("[serializeScheduledAttachments] processAttachments returned empty for " . count($attachments) . " input attachment(s)");
            return ['attachments' => [], 'failed' => array_map(fn($a) => $a['name'] ?? 'attachment', $attachments)];
        }

        foreach ($processed as $attachment) {
            $name = $attachment['name'] ?? 'attachment';
            $type = $attachment['type'] ?? 'application/octet-stream';

            $uid = uniqid('sched_', true);
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $diskName = $uid . ($ext ? '.' . $ext : '');
            $destPath = $schedDir . '/' . $diskName;

            $saved = false;

            if (!empty($attachment['path']) && file_exists($attachment['path'])) {
                $sourceSize = filesize($attachment['path']);
                error_log("[serializeScheduledAttachments] Copying {$name} ({$sourceSize} bytes) from {$attachment['path']} -> {$destPath}");
                $saved = @copy($attachment['path'], $destPath);
                if (!$saved) {
                    $err = error_get_last();
                    error_log("[serializeScheduledAttachments] COPY FAILED for {$name}: " . ($err['message'] ?? 'unknown error'));
                    $diskFree = @disk_free_space(dirname($destPath));
                    error_log("[serializeScheduledAttachments] Disk free space: " . ($diskFree !== false ? number_format($diskFree) . ' bytes' : 'unknown'));
                }
            } elseif (isset($attachment['content'])) {
                $content = (string)$attachment['content'];
                if ($content !== '') {
                    $saved = (@file_put_contents($destPath, $content) !== false);
                    if (!$saved) {
                        error_log("[serializeScheduledAttachments] WRITE FAILED for {$name}");
                    }
                }
            } else {
                error_log("[serializeScheduledAttachments] No path or content for attachment: {$name}");
            }

            if (!$saved) {
                $failed[] = $name;
                continue;
            }

            $serialized[] = [
                'path' => $destPath,
                'name' => $name,
                'type' => $type,
                'size' => filesize($destPath),
            ];
        }

        if (!empty($failed)) {
            error_log("[serializeScheduledAttachments] FAILED to serialize " . count($failed) . " attachment(s): " . implode(', ', $failed));
        }

        return ['attachments' => $serialized, 'failed' => $failed];
    }

    private function cleanupScheduledAttachmentFiles(array $attachments): void
    {
        $schedDir = realpath(dirname(__DIR__, 2) . '/storage/scheduled-attachments');
        if (!$schedDir) return;

        foreach ($attachments as $att) {
            if (empty($att['path'])) continue;
            $real = realpath($att['path']);
            if ($real && str_starts_with($real, $schedDir) && file_exists($real)) {
                @unlink($real);
            }
        }
    }

    /**
     * Find Sent folder
     */
    private function findSentFolder(): ?string
    {
        $folders = $this->imap->listFolders();
        
        foreach ($folders as $folder) {
            if ($folder['type'] === 'sent') {
                return $folder['name'];
            }
        }
        
        $sentNames = ['Sent', 'Sent Items', 'Sent Messages'];
        foreach ($folders as $folder) {
            if (in_array($folder['name'], $sentNames)) {
                return $folder['name'];
            }
        }
        
        return null;
    }

    /**
     * Find Drafts folder
     */
    private function findDraftsFolder(): ?string
    {
        $folders = $this->imap->listFolders();
        
        // First try to find by IMAP special-use attribute
        foreach ($folders as $folder) {
            if ($folder['type'] === 'drafts') {
                return $folder['name'];
            }
        }
        
        // Fall back to common folder names (case-insensitive)
        $draftNames = ['drafts', 'draft'];
        foreach ($folders as $folder) {
            $folderBaseName = strtolower(basename(str_replace('.', '/', $folder['name'])));
            if (in_array($folderBaseName, $draftNames)) {
                return $folder['name'];
            }
        }
        
        // Try exact matches including with INBOX prefix
        foreach ($folders as $folder) {
            $name = strtolower($folder['name']);
            if ($name === 'drafts' || $name === 'inbox.drafts' || $name === 'draft') {
                return $folder['name'];
            }
        }
        
        return null;
    }

    /**
     * Upload inline image for email body
     * POST /message/upload-inline-image
     */
    public function uploadInlineImage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $file = $request->getFile('image');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::error('No image uploaded or upload error', 400);
        }

        // Validate it's an image
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return Response::error('Invalid image type. Allowed: JPEG, PNG, GIF, WebP', 400);
        }

        // Max 5MB for inline images
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return Response::error('Image too large. Maximum 5MB.', 400);
        }

        // Create inline images directory
        $inlineDir = '/var/www/vps-email/data/inline-images';
        if (!is_dir($inlineDir)) {
            mkdir($inlineDir, 0755, true);
        }

        // Generate unique filename
        $ext = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg'
        };
        $filename = uniqid('img_') . '_' . time() . '.' . $ext;
        $path = $inlineDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return Response::error('Failed to save uploaded image', 500);
        }

        // Build URL to access the image
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        $imageUrl = $baseUrl . '/api/inline-image/' . $filename;

        return Response::success([
            'url' => $imageUrl,
            'filename' => $filename,
        ], 'Image uploaded');
    }

    /**
     * Serve inline image
     * GET /inline-image/{filename}
     * Public endpoint -- filenames are unguessable (random hex + timestamp)
     */
    public function serveInlineImage(Request $request): Response
    {
        $filename = $request->getParam('filename');
        
        // Sanitize filename
        $filename = basename($filename);
        if (!preg_match('/^img_[a-f0-9]+_\d+\.(jpg|png|gif|webp)$/i', $filename)) {
            return Response::error('Invalid image filename', 400);
        }

        $path = '/var/www/vps-email/data/inline-images/' . $filename;
        
        if (!file_exists($path)) {
            return Response::error('Image not found', 404);
        }

        // Determine content type
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $contentType = match(strtolower($ext)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream'
        };

        // Send image
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

}

