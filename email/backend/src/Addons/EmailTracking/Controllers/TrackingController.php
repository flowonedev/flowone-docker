<?php

namespace Webmail\Addons\EmailTracking\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\EmailTracking\Services\TrackingService;

/**
 * TrackingController - Email tracking and notifications API
 */
class TrackingController extends BaseController
{
    private ?TrackingService $trackingService = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->trackingService = new TrackingService($config);
    }
    
    /**
     * Serve tracking pixel (1x1 transparent GIF)
     * This is called when email is opened
     */
    public function pixel(Request $request): Response
    {
        $trackingId = $request->getParam('id');
        
        if ($trackingId) {
            // Remove .gif extension if present
            $trackingId = preg_replace('/\.gif$/', '', $trackingId);
            
            // Only record read event if email tracking addon is enabled
            // (pixel is always served to avoid broken images in already-sent emails)
            $addonService = new \Webmail\Services\AddonService($this->config);
            if ($addonService->isEmailTrackingEnabled()) {
                $this->trackingService->recordReadEvent($trackingId);
            }
        }
        
        // Return 1x1 transparent GIF
        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // 1x1 transparent GIF binary
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
    
    /**
     * Get tracked emails list
     */
    public function listTracked(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $limit = min((int)$request->getParam('limit', 50), 100);
        $tracked = $this->trackingService->getTrackedEmails($this->getActiveEmail(), $limit);
        
        return Response::success(['tracked' => $tracked]);
    }
    
    /**
     * Get tracking details for a specific email
     */
    public function getTracking(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $trackingId = $request->getParam('id');
        $tracking = $this->trackingService->getTracking($this->getActiveEmail(), $trackingId);
        
        if (!$tracking) {
            return Response::error('Tracking not found', 404);
        }
        
        return Response::success(['tracking' => $tracking]);
    }
    
    // ===== CLICK TRACKING =====
    
    /**
     * Handle click redirect: records click event and redirects to original URL.
     * Public endpoint (no auth) -- called when a recipient clicks a tracked link.
     */
    public function clickRedirect(Request $request): Response
    {
        $linkToken = $request->getParam('linkToken');
        $recipientToken = $request->getParam('recipientToken');

        $addonService = new \Webmail\Services\AddonService($this->config);
        if ($addonService->isEmailTrackingEnabled()) {
            $originalUrl = $this->trackingService->recordClickEvent($linkToken, $recipientToken);
        } else {
            $stmt = \Webmail\Core\Database::getConnection($this->config)
                ->prepare('SELECT original_url FROM email_link_tracking WHERE link_token = ?');
            $stmt->execute([$linkToken]);
            $row = $stmt->fetch();
            $originalUrl = $row ? $row['original_url'] : null;
        }

        if (!$originalUrl) {
            return Response::error('Link not found', 404);
        }

        header('Location: ' . $originalUrl, true, 302);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        exit;
    }

    /**
     * Get click stats for a tracked email (authenticated).
     */
    public function getClickStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $trackingId = $request->getParam('id');
        $tracking = $this->trackingService->getTracking($this->getActiveEmail(), $trackingId);
        if (!$tracking) {
            return Response::error('Tracking not found', 404);
        }

        $stats = $this->trackingService->getClickStats($trackingId);
        return Response::success(['clicks' => $stats]);
    }

    /**
     * GET /tracking/{id}/locate
     * Resolve a tracking record to the IMAP folder + uid of the sent email so a
     * notification can open it. Returns 404 when the email can't be found
     * (e.g. permanently deleted and subject search also misses).
     */
    public function locate(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $trackingId = $request->getParam('id');
        if (!$trackingId) {
            return Response::error('Tracking id required', 400);
        }

        $imap = $this->getImap($request);
        if (!$imap) {
            return Response::error('Mailbox connection unavailable', 503);
        }

        $located = $this->trackingService->locateEmail($this->getActiveEmail(), $trackingId, $imap);
        if (!$located) {
            return Response::error('Email not found', 404);
        }

        return Response::success([
            'folder' => $located['folder'],
            'uid' => $located['uid'],
        ]);
    }

    /**
     * GET /tracking/links
     * Get distinct tracked links for the user, optionally filtered by campaign_id
     */
    public function getTrackedLinks(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $campaignId = $request->getParam('campaign_id') ?: null;
        $links = $this->trackingService->getTrackedLinks($this->getActiveEmail(), $campaignId);

        return Response::success(['links' => $links]);
    }

    // ===== NOTIFICATIONS =====
    
    /**
     * Get notifications
     */
    public function listNotifications(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $unreadOnly = (bool)$request->getParam('unread_only', false);
        $limit = min((int)$request->getParam('limit', 50), 100);
        $consolidate = $request->getParam('consolidate', false);
        
        // Auto-consolidate duplicates if requested
        if ($consolidate) {
            $this->trackingService->consolidateDuplicateNotifications($this->getActiveEmail());
        }
        
        $notifications = $this->trackingService->getNotifications($this->getActiveEmail(), $unreadOnly, $limit);
        $unreadCount = $this->trackingService->getUnreadCount($this->getActiveEmail());
        
        return Response::success([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }
    
    /**
     * Get unread count only
     */
    public function unreadCount(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $count = $this->trackingService->getUnreadCount($this->getActiveEmail());
        
        return Response::success(['unread_count' => $count]);
    }
    
    /**
     * Mark notification as read
     */
    public function markRead(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        if (!$this->trackingService->markAsRead($this->getActiveEmail(), $id)) {
            return Response::error('Notification not found', 404);
        }
        
        return Response::success(null, 'Marked as read');
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllRead(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $count = $this->trackingService->markAllAsRead($this->getActiveEmail());
        
        return Response::success(['marked' => $count], 'All notifications marked as read');
    }
    
    /**
     * Create a notification (for client-side events like missed calls)
     */
    public function createNotification(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $type = $request->input('type');
        $title = $request->input('title');
        $message = $request->input('message');
        $data = $request->input('data', []);
        $targetEmail = $request->input('target_email'); // Optional: create for another user
        
        if (!$type || !$title || !$message) {
            return Response::error('type, title, and message are required', 400);
        }
        
        // Only allow specific types from the client
        $allowedTypes = ['missed_call', 'thread_reply', 'chat_message', 'drive_share', 'task_completed'];
        if (!in_array($type, $allowedTypes)) {
            return Response::error('Invalid notification type', 400);
        }
        
        $email = $targetEmail ? strtolower($targetEmail) : $this->getActiveEmail();
        
        $id = $this->trackingService->createNotification($email, $type, $title, $message, $data);
        
        if (!$id) {
            return Response::error('Failed to create notification', 500);
        }
        
        return Response::success(['id' => $id], 'Notification created');
    }
    
    /**
     * Delete a notification
     */
    public function deleteNotification(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        if (!$this->trackingService->deleteNotification($this->getActiveEmail(), $id)) {
            return Response::error('Notification not found', 404);
        }
        
        return Response::success(null, 'Notification deleted');
    }
    
    /**
     * Clear all notifications (optionally scoped to a tab: email|campaigns|general|all)
     */
    public function clearAll(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $scope = $request->getParam('scope');
        $allowed = ['email', 'campaigns', 'general', 'all'];
        if ($scope !== null && $scope !== '' && !in_array($scope, $allowed, true)) {
            return Response::error('Invalid scope', 400);
        }

        $count = $this->trackingService->clearAllNotifications($this->getActiveEmail(), $scope ?: null);

        $message = match ($scope) {
            'email'     => 'Email notifications cleared',
            'campaigns' => 'Campaign notifications cleared',
            'general'   => 'General notifications cleared',
            default     => 'All notifications cleared',
        };

        return Response::success(['cleared' => $count, 'scope' => $scope ?: 'all'], $message);
    }
    
    /**
     * Consolidate duplicate notifications
     */
    public function consolidateNotifications(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $result = $this->trackingService->consolidateDuplicateNotifications($this->getActiveEmail());
        
        return Response::success($result, 'Notifications consolidated');
    }
    
    /**
     * Toggle pin status for a notification
     */
    public function togglePin(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        if (!$this->trackingService->togglePin($this->getActiveEmail(), $id)) {
            return Response::error('Notification not found', 404);
        }
        
        return Response::success(null, 'Pin toggled');
    }
    
    /**
     * Set pin status for a notification
     */
    public function setPinned(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $pinned = (bool)$request->input('pinned');
        
        if (!$this->trackingService->setPinned($this->getActiveEmail(), $id, $pinned)) {
            return Response::error('Notification not found', 404);
        }
        
        return Response::success(['pinned' => $pinned], $pinned ? 'Notification pinned' : 'Notification unpinned');
    }
}
