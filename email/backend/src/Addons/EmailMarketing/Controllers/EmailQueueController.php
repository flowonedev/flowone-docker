<?php

namespace Webmail\Addons\EmailMarketing\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\EmailMarketing\Services\EmailQueueService;

/**
 * Email Queue Controller
 * 
 * Handles API endpoints for bulk email campaigns:
 * - Queue bulk emails for sending
 * - View campaign status and progress
 * - Pause, resume, cancel campaigns
 * - Retry failed emails
 */
class EmailQueueController extends BaseController
{
    private ?EmailQueueService $queueService = null;
    
    private function getQueueService(): EmailQueueService
    {
        if ($this->queueService === null) {
            $this->queueService = new EmailQueueService($this->config);
        }
        return $this->queueService;
    }
    
    /**
     * POST /email-queue/send
     * Queue a bulk email for sending
     */
    public function send(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = $request->input();
        
        // Validate required fields
        if (empty($data['to']) && empty($data['recipients'])) {
            return Response::error('Recipients are required', 400);
        }
        
        if (empty($data['subject'])) {
            return Response::error('Subject is required', 400);
        }
        
        // Build recipients list from to, cc, bcc
        $recipients = [];
        
        // Process 'to' recipients
        $toRecipients = $data['to'] ?? $data['recipients'] ?? [];
        foreach ($toRecipients as $recipient) {
            $email = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
            $name = is_array($recipient) ? ($recipient['name'] ?? '') : '';
            if (!empty($email)) {
                $recipients[] = ['email' => $email, 'name' => $name, 'type' => 'to'];
            }
        }
        
        // Process 'cc' recipients
        $ccRecipients = $data['cc'] ?? [];
        foreach ($ccRecipients as $recipient) {
            $email = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
            $name = is_array($recipient) ? ($recipient['name'] ?? '') : '';
            if (!empty($email)) {
                $recipients[] = ['email' => $email, 'name' => $name, 'type' => 'cc'];
            }
        }
        
        // Process 'bcc' recipients
        $bccRecipients = $data['bcc'] ?? [];
        foreach ($bccRecipients as $recipient) {
            $email = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
            $name = is_array($recipient) ? ($recipient['name'] ?? '') : '';
            if (!empty($email)) {
                $recipients[] = ['email' => $email, 'name' => $name, 'type' => 'bcc'];
            }
        }
        
        if (empty($recipients)) {
            return Response::error('At least one valid recipient is required', 400);
        }
        
        $service = $this->getQueueService();
        
        $result = $service->createCampaign(
            $this->userEmail,
            $recipients,
            $data['subject'],
            $data['body_html'] ?? $data['body'] ?? '',
            $data['body_text'] ?? '',
            $data['from_name'] ?? '',
            $data['attachments'] ?? [],
            $data['in_reply_to'] ?? null,
            $data['references'] ?? null,
            $data['track_read'] ?? true
        );
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success($result);
    }
    
    /**
     * GET /email-queue/campaigns
     * List user's campaigns
     */
    public function listCampaigns(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $limit = (int)($request->getParam('limit') ?? 50);
        $offset = (int)($request->getParam('offset') ?? 0);
        
        $service = $this->getQueueService();
        $campaigns = $service->getCampaigns($this->userEmail, $limit, $offset);
        
        return Response::success(['campaigns' => $campaigns]);
    }
    
    /**
     * GET /email-queue/campaigns/:id
     * Get campaign details
     */
    public function getCampaign(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $service = $this->getQueueService();
        $campaign = $service->getCampaign($campaignId, $this->userEmail);
        
        if (!$campaign) {
            return Response::error('Campaign not found', 404);
        }
        
        return Response::success(['campaign' => $campaign]);
    }
    
    /**
     * POST /email-queue/campaigns/:id/pause
     * Pause a campaign
     */
    public function pauseCampaign(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $service = $this->getQueueService();
        $result = $service->pauseCampaign($campaignId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Campaign paused']);
    }
    
    /**
     * POST /email-queue/campaigns/:id/resume
     * Resume a paused campaign
     */
    public function resumeCampaign(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $service = $this->getQueueService();
        $result = $service->resumeCampaign($campaignId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Campaign resumed']);
    }
    
    /**
     * DELETE /email-queue/campaigns/:id
     * Cancel a campaign
     */
    public function cancelCampaign(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $service = $this->getQueueService();
        $result = $service->cancelCampaign($campaignId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Campaign cancelled']);
    }
    
    /**
     * POST /email-queue/campaigns/:id/delete
     * Permanently delete a campaign and all related data
     */
    public function destroyCampaign(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $service = $this->getQueueService();
        $result = $service->deleteCampaign($campaignId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Campaign permanently deleted']);
    }
    
    /**
     * GET /email-queue/campaigns/:id/analytics
     * Full campaign analytics: recipients, opens, clicks, bounces, unsubscribes
     */
    public function getCampaignAnalytics(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        try {
            $service = $this->getQueueService();
            $analytics = $service->getCampaignAnalytics($campaignId, $this->userEmail);
            
            if (!$analytics) {
                return Response::error('Campaign not found', 404);
            }
            
            return Response::success($analytics);
        } catch (\Throwable $e) {
            error_log("getCampaignAnalytics error: " . $e->getMessage());
            return Response::error('Analytics error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /email-queue/campaigns/:id/failed
     * Get failed recipients for a campaign
     */
    public function getFailedRecipients(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $service = $this->getQueueService();
        $failed = $service->getFailedRecipients($campaignId, $this->userEmail);
        
        return Response::success(['failed' => $failed]);
    }
    
    /**
     * POST /email-queue/campaigns/:id/retry
     * Retry failed emails in a campaign
     */
    public function retryFailed(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $service = $this->getQueueService();
        $result = $service->retryFailed($campaignId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success([
            'message' => "Retrying {$result['retried']} failed emails",
            'retried' => $result['retried']
        ]);
    }
    
    /**
     * POST /email-queue/campaigns/draft
     */
    public function createDraft(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = $request->input();
        $service = $this->getQueueService();
        $result = $service->createDraftCampaign($this->userEmail, $data['subject'] ?? '');
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success($result, 201);
    }
    
    /**
     * PUT /email-queue/campaigns/:id/draft
     */
    public function updateDraft(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $data = $request->input();
        
        if (!empty($data['body_html_b64'])) {
            $data['body_html'] = base64_decode($data['body_html_b64']);
            unset($data['body_html_b64']);
        }
        
        $service = $this->getQueueService();
        $result = $service->updateDraftCampaign($campaignId, $this->userEmail, $data);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success([
            'updated' => true,
            'received_body_len' => strlen($data['body_html'] ?? ''),
        ]);
    }
    
    /**
     * POST /email-queue/campaigns/:id/finalize
     */
    public function finalizeDraft(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $campaignId = $request->getParam('id');
        if (empty($campaignId)) {
            return Response::error('Campaign ID is required', 400);
        }
        
        $service = $this->getQueueService();
        $result = $service->finalizeDraftCampaign($campaignId, $this->userEmail);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success($result);
    }
    
    /**
     * GET /email-queue/rate-limits
     * Get current rate limit status
     */
    public function getRateLimits(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $service = $this->getQueueService();
        $limits = $service->checkRateLimits($this->userEmail);
        
        return Response::success([
            'limits' => $limits,
            'hourly_limit' => EmailQueueService::HOURLY_LIMIT,
            'daily_limit' => EmailQueueService::DAILY_LIMIT
        ]);
    }
}

