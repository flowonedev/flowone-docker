<?php

namespace Webmail\Addons\Reactions\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Reactions\Services\ReactionService;
use Webmail\Services\SmtpService;

class ReactionController extends BaseController
{
    private ReactionService $reactionService;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->reactionService = new ReactionService($config);
    }
    
    /**
     * Add or toggle a reaction
     * POST /reactions
     */
    public function add(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        // Get JSON body using input() method
        $data = $request->input();
        
        // Validate required fields
        $required = ['message_id', 'emoji', 'participants'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return Response::error("Missing required field: $field", 400);
            }
        }
        
        $messageId = $data['message_id'];
        $emoji = $data['emoji'];
        $participants = $data['participants'];
        $subject = $data['subject'] ?? null;
        $snippet = $data['snippet'] ?? null;
        $sendNotification = $data['send_notification'] ?? true;
        $accentColor = $data['accent_color'] ?? null; // User's accent color for email styling
        
        // Get reactor info from base controller
        $reactorEmail = $this->userEmail;
        $reactorName = $data['reactor_name'] ?? explode('@', $reactorEmail)[0];
        
        // Ensure reactor is in participants
        if (!in_array(strtolower($reactorEmail), array_map('strtolower', $participants))) {
            $participants[] = $reactorEmail;
        }
        
        try {
            $reaction = $this->reactionService->addReaction(
                $messageId,
                $reactorEmail,
                $reactorName,
                $emoji,
                $participants,
                $subject
            );
            
            if ($reaction === null) {
                // Reaction was toggled off
                return Response::success([
                    'action' => 'removed',
                    'message_id' => $messageId,
                    'emoji' => $emoji,
                ]);
            }
            
            // Send notification emails to external participants if enabled
            if ($sendNotification && $subject) {
                error_log("ReactionController: Attempting to send notification. Password available: " . ($this->userPassword ? 'yes' : 'no') . ", OAuth: " . ($this->isOAuthSession ? 'yes' : 'no'));
                
                $this->sendNotificationEmails(
                    $participants,
                    $reactorEmail,
                    $reactorName,
                    $emoji,
                    $subject,
                    $snippet,
                    $messageId, // Pass message_id for threading
                    $accentColor // Pass accent color for styling
                );
            }
            
            // Get updated summary
            $summary = $this->reactionService->getReactionSummary($messageId, $reactorEmail);
            
            return Response::success([
                'action' => 'added',
                'reaction' => $reaction,
                'summary' => $summary,
            ]);
            
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            error_log("Reaction add error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::error('Failed to add reaction: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Remove a reaction
     * DELETE /reactions
     */
    public function remove(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = $request->input();
        
        $messageId = $data['message_id'] ?? null;
        $emoji = $data['emoji'] ?? null;
        
        if (!$messageId || !$emoji) {
            return Response::error('Missing message_id or emoji', 400);
        }
        
        $removed = $this->reactionService->removeReactionByDetails(
            $messageId,
            $this->userEmail,
            $emoji
        );
        
        if ($removed) {
            $summary = $this->reactionService->getReactionSummary($messageId, $this->userEmail);
            return Response::success([
                'removed' => true,
                'summary' => $summary,
            ]);
        }
        
        return Response::error('Reaction not found', 404);
    }
    
    /**
     * Get reactions for a single message
     * GET /reactions/message?message_id=xxx
     */
    public function getMessage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $messageId = $request->getQuery('message_id');
        if (!$messageId) {
            return Response::error('Missing message_id', 400);
        }
        
        $summary = $this->reactionService->getReactionSummary($messageId, $this->userEmail);
        
        return Response::success([
            'message_id' => $messageId,
            'reactions' => $summary,
        ]);
    }
    
    /**
     * Get reactions for multiple messages (batch)
     * POST /reactions/batch
     */
    public function batch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        try {
            $data = $request->input();
            $messageIds = $data['message_ids'] ?? [];
            
            if (empty($messageIds)) {
                return Response::success(['reactions' => []]);
            }
            
            // Limit to prevent abuse
            $messageIds = array_slice($messageIds, 0, 100);
            
            $summaries = $this->reactionService->getReactionSummaries($messageIds, $this->userEmail);
            
            return Response::success([
                'reactions' => $summaries,
            ]);
        } catch (\Exception $e) {
            error_log("ReactionController::batch error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::error('Failed to fetch reactions: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get available emoji options
     * GET /reactions/emojis
     */
    public function emojis(Request $request): Response
    {
        return Response::success([
            'emojis' => ReactionService::getAvailableEmojis(),
        ]);
    }
    
    /**
     * Send notification emails to external recipients
     */
    private function sendNotificationEmails(
        array $participants,
        string $reactorEmail,
        string $reactorName,
        string $emoji,
        string $subject,
        ?string $snippet,
        ?string $originalMessageId = null,
        ?string $accentColor = null
    ): void {
        error_log("ReactionController::sendNotificationEmails - Starting");
        error_log("ReactionController - Participants: " . json_encode($participants));
        error_log("ReactionController - Reactor: $reactorEmail");
        
        // Find external participants (not the reactor)
        $externalRecipients = [];
        foreach ($participants as $participant) {
            $participantLower = strtolower($participant);
            $isReactor = ($participantLower === strtolower($reactorEmail));
            $isLocal = $this->reactionService->isLocalEmail($participantLower);
            
            error_log("ReactionController - Checking participant: $participant, isReactor: " . ($isReactor ? 'yes' : 'no') . ", isLocal: " . ($isLocal ? 'yes' : 'no'));
            
            if (!$isReactor && !$isLocal) {
                $externalRecipients[] = $participantLower;
                error_log("ReactionController - Added to external recipients: $participantLower");
            }
        }
        
        if (empty($externalRecipients)) {
            error_log("ReactionController - No external recipients found, skipping notification");
            return; // No external recipients
        }
        
        error_log("ReactionController - External recipients: " . json_encode($externalRecipients));
        
        // Generate notification email with user's accent color
        $notification = $this->reactionService->generateNotificationEmail(
            $reactorName,
            $emoji,
            $subject,
            $snippet,
            $accentColor
        );
        
        // Send to each external recipient
        foreach ($externalRecipients as $recipient) {
            try {
                error_log("ReactionController - Sending notification to: $recipient");
                error_log("ReactionController - Notification subject: " . $notification['subject']);
                
                if ($this->userPassword) {
                    error_log("ReactionController - Using SMTP with password");
                    // Use SMTP with user credentials
                    $smtp = new SmtpService($this->config['smtp']);
                    $smtp->setCredentials($reactorEmail, $this->userPassword);
                    // Build threading headers for proper Gmail/Outlook threading
                    $sendParams = [
                        'from_name' => $reactorName,
                        'to' => [$recipient],
                        'subject' => $notification['subject'],
                        'body_html' => $notification['html'],
                        'body_text' => $notification['text'],
                    ];
                    
                    // Add threading headers if we have the original message ID
                    if ($originalMessageId) {
                        // Ensure message ID is properly formatted with angle brackets
                        $formattedMsgId = $originalMessageId;
                        if (strpos($formattedMsgId, '<') !== 0) {
                            $formattedMsgId = '<' . $formattedMsgId . '>';
                        }
                        $sendParams['in_reply_to'] = $formattedMsgId;
                        $sendParams['references'] = $formattedMsgId;
                    }
                    
                    $smtp->send($sendParams);
                    error_log("ReactionController - SUCCESS: Notification sent via SMTP to $recipient");
                } else {
                    error_log("ReactionController - Using local sendmail (no password available)");
                    // Use local sendmail (PHPMailer without SMTP auth)
                    $this->sendViaLocalMail(
                        $reactorEmail,
                        $reactorName,
                        $recipient,
                        $notification['subject'],
                        $notification['html'],
                        $notification['text'],
                        $originalMessageId
                    );
                    error_log("ReactionController - SUCCESS: Notification sent via local mail to $recipient");
                }
            } catch (\Exception $e) {
                error_log("ReactionController - FAILED to send notification to $recipient: " . $e->getMessage());
                error_log("ReactionController - Error trace: " . $e->getTraceAsString());
            }
        }
    }
    
    /**
     * Send email via local mail server (sendmail/postfix)
     * Used when user credentials are not available
     */
    private function sendViaLocalMail(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $htmlBody,
        string $textBody,
        ?string $originalMessageId = null
    ): void {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Use sendmail instead of SMTP
            $mail->isSendmail();
            
            // Sender
            $mail->setFrom($fromEmail, $fromName);
            $mail->addReplyTo($fromEmail, $fromName);
            
            // Recipient
            $mail->addAddress($toEmail);
            
            // Content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
            
            // Add threading headers for proper Gmail/Outlook threading
            if ($originalMessageId) {
                $formattedMsgId = $originalMessageId;
                if (strpos($formattedMsgId, '<') !== 0) {
                    $formattedMsgId = '<' . $formattedMsgId . '>';
                }
                $mail->addCustomHeader('In-Reply-To', $formattedMsgId);
                $mail->addCustomHeader('References', $formattedMsgId);
            }
            
            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            throw new \Exception("Sendmail failed: " . $mail->ErrorInfo);
        }
    }
}


