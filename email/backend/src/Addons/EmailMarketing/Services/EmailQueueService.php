<?php

namespace Webmail\Addons\EmailMarketing\Services;

use Webmail\Services\RedisCacheService;
use Webmail\Services\SmtpService;
use Webmail\Addons\EmailMarketing\Services\UnsubscribeService;
use Webmail\Addons\EmailMarketing\Services\MailingListService;

/**
 * Email Queue Service
 * 
 * Handles bulk email sending with rate limiting:
 * - 100 emails per hour
 * - 500 emails per day
 * 
 * Features:
 * - Campaign management (create, pause, resume, cancel)
 * - Rate limit tracking per user
 * - Background queue processing
 * - Real-time progress broadcasting via Redis
 */
class EmailQueueService
{
    private \PDO $db;
    private array $config;
    private ?RedisCacheService $redis = null;
    
    // Rate limits
    const HOURLY_LIMIT = 100;
    const DAILY_LIMIT = 500;
    const BATCH_SIZE = 10; // Emails per cron run
    
    private bool $columnsChecked = false;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        // Database connection
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // Redis for broadcasting (optional)
        try {
            $this->redis = new RedisCacheService($config);
        } catch (\Throwable $e) {
            error_log("EmailQueueService: Redis unavailable: " . $e->getMessage());
            $this->redis = null;
        }
    }
    
    private function ensureSourceColumns(): void
    {
        if ($this->columnsChecked) return;
        $this->columnsChecked = true;
        
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM email_campaigns LIKE 'source'")->fetchAll();
            if (empty($cols)) {
                $this->db->exec("ALTER TABLE email_campaigns ADD COLUMN source VARCHAR(50) DEFAULT 'manual'");
                $this->db->exec("ALTER TABLE email_campaigns ADD COLUMN source_id VARCHAR(255) DEFAULT NULL");
                $this->db->exec("ALTER TABLE email_campaigns ADD COLUMN parent_campaign_id VARCHAR(36) DEFAULT NULL");
            }
        } catch (\Throwable $e) {
            error_log("ensureSourceColumns: " . $e->getMessage());
        }
    }
    
    // ========================================
    // SINGLE-EMAIL QUEUE (for automation / sequences)
    // ========================================

    /**
     * Queue a single automated email.
     * Creates a 1-recipient campaign behind the scenes.
     *
     * @param array $data Keys: user_email, to, subject, body, source, source_id
     */
    public function queueEmail(array $data): array
    {
        $userEmail        = $data['user_email'] ?? '';
        $to               = $data['to'] ?? '';
        $subject           = $data['subject'] ?? '(no subject)';
        $body              = $data['body'] ?? '';
        $source            = $data['source'] ?? 'automation';
        $sourceId          = $data['source_id'] ?? null;
        $parentCampaignId  = $data['parent_campaign_id'] ?? null;

        if (empty($to) || empty($userEmail)) {
            return ['success' => false, 'error' => 'Missing user_email or to address'];
        }

        return $this->createCampaign(
            $userEmail,
            [['email' => $to, 'name' => '', 'type' => 'to']],
            $subject,
            $body,
            strip_tags($body),
            '',
            [],
            null,
            null,
            false,
            $source,
            $sourceId,
            $parentCampaignId
        );
    }

    // ========================================
    // CAMPAIGN MANAGEMENT
    // ========================================
    
    /**
     * Create a new email campaign and queue all recipients
     */
    public function createCampaign(
        string $userEmail,
        array $recipients,
        string $subject,
        string $bodyHtml,
        string $bodyText = '',
        string $fromName = '',
        array $attachments = [],
        ?string $inReplyTo = null,
        ?string $references = null,
        bool $trackRead = true,
        string $source = 'manual',
        ?string $sourceId = null,
        ?string $parentCampaignId = null
    ): array {
        $this->ensureSourceColumns();
        
        $campaignId = $this->generateUUID();
        $totalRecipients = count($recipients);
        
        if ($totalRecipients === 0) {
            return ['success' => false, 'error' => 'No recipients provided'];
        }
        
        // Pre-filter: collect all unsubscribed emails for this sender
        $unsubscribedEmails = [];
        try {
            $unsubService = new UnsubscribeService($this->config);
            $unsubList = $unsubService->getUnsubscribeList($userEmail, 10000, 0);
            foreach ($unsubList as $u) {
                $unsubscribedEmails[strtolower($u['unsubscribed_email'])] = true;
            }
        } catch (\Throwable $e) {
            error_log("createCampaign: Failed to check unsubscribes: " . $e->getMessage());
        }
        
        try {
            $this->db->beginTransaction();
            
            // Create campaign (total_recipients updated after filtering)
            $stmt = $this->db->prepare("
                INSERT INTO email_campaigns 
                (campaign_id, user_email, subject, body_html, body_text, from_name, 
                 attachments, in_reply_to, `references`, track_read, total_recipients, status,
                 source, source_id, parent_campaign_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
            ");
            $stmt->execute([
                $campaignId,
                $userEmail,
                $subject,
                $bodyHtml,
                $bodyText,
                $fromName,
                json_encode($attachments),
                $inReplyTo,
                $references,
                $trackRead ? 1 : 0,
                $totalRecipients,
                $source,
                $sourceId,
                $parentCampaignId
            ]);
            
            // Queue recipients, immediately skip unsubscribed ones
            $pendingStmt = $this->db->prepare("
                INSERT INTO email_queue 
                (campaign_id, recipient_email, recipient_name, recipient_type, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $skippedStmt = $this->db->prepare("
                INSERT INTO email_queue 
                (campaign_id, recipient_email, recipient_name, recipient_type, status, sent_at)
                VALUES (?, ?, ?, ?, 'skipped_unsubscribed', NOW())
            ");
            
            $skippedCount = 0;
            foreach ($recipients as $recipient) {
                $email = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
                $name = is_array($recipient) ? ($recipient['name'] ?? '') : '';
                $type = is_array($recipient) ? ($recipient['type'] ?? 'to') : 'to';
                
                if (!empty($email)) {
                    if (isset($unsubscribedEmails[strtolower($email)])) {
                        $skippedStmt->execute([$campaignId, $email, $name, $type]);
                        $skippedCount++;
                    } else {
                        $pendingStmt->execute([$campaignId, $email, $name, $type]);
                    }
                }
            }
            
            // Update sent_count for skipped recipients (they count as "processed")
            if ($skippedCount > 0) {
                $stmt = $this->db->prepare("
                    UPDATE email_campaigns SET sent_count = ? WHERE campaign_id = ?
                ");
                $stmt->execute([$skippedCount, $campaignId]);
            }
            
            $queuedCount = $totalRecipients - $skippedCount;
            $skippedMsg = $skippedCount > 0 ? " ({$skippedCount} skipped - unsubscribed)" : '';
            
            // Log campaign creation
            $this->logCampaignEvent($campaignId, 'queued', null, "Campaign created with {$totalRecipients} recipients{$skippedMsg}");
            
            $this->db->commit();
            
            // Calculate estimated time based on queued (non-skipped) recipients
            $estimatedHours = ceil($queuedCount / self::HOURLY_LIMIT);
            
            return [
                'success' => true,
                'campaign_id' => $campaignId,
                'total_recipients' => $totalRecipients,
                'queued_count' => $queuedCount,
                'skipped_unsubscribed' => $skippedCount,
                'estimated_hours' => $estimatedHours,
                'message' => "Campaign queued. {$queuedCount} emails will be sent over approximately {$estimatedHours} hour(s)." . ($skippedCount > 0 ? " {$skippedCount} unsubscribed recipient(s) skipped." : '')
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("EmailQueueService: Failed to create campaign: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create campaign: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get campaign status and progress
     */
    public function getCampaign(string $campaignId, string $userEmail): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM email_campaigns 
            WHERE campaign_id = ? AND user_email = ?
        ");
        $stmt->execute([$campaignId, $userEmail]);
        $campaign = $stmt->fetch();
        
        if (!$campaign) {
            return null;
        }
        
        if (!empty($campaign['parent_campaign_id'])) {
            try {
                $pStmt = $this->db->prepare("SELECT subject FROM email_campaigns WHERE campaign_id = ? LIMIT 1");
                $pStmt->execute([$campaign['parent_campaign_id']]);
                $parent = $pStmt->fetch();
                $campaign['parent_campaign_subject'] = $parent['subject'] ?? null;
            } catch (\Throwable $e) {
                $campaign['parent_campaign_subject'] = null;
            }
        }
        
        // Get queue stats
        $stmt = $this->db->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM email_queue 
            WHERE campaign_id = ?
            GROUP BY status
        ");
        $stmt->execute([$campaignId]);
        $stats = [];
        while ($row = $stmt->fetch()) {
            $stats[$row['status']] = (int)$row['count'];
        }
        
        $campaign['queue_stats'] = $stats;
        $campaign['progress_percent'] = $campaign['total_recipients'] > 0 
            ? round(($campaign['sent_count'] / $campaign['total_recipients']) * 100, 1)
            : 0;
        $campaign['attachments'] = json_decode($campaign['attachments'] ?? '[]', true);
        
        if (!empty($campaign['mailing_list_id'])) {
            try {
                $mlStmt = $this->db->prepare("SELECT name FROM mailing_lists WHERE id = ? LIMIT 1");
                $mlStmt->execute([$campaign['mailing_list_id']]);
                $campaign['mailing_list_name'] = $mlStmt->fetchColumn() ?: null;
            } catch (\Throwable $e) {
                $campaign['mailing_list_name'] = null;
            }
        }
        
        if (!empty($campaign['body_html'])) {
            $campaign['body_html_b64'] = base64_encode($campaign['body_html']);
        }
        
        return $campaign;
    }
    
    /**
     * Get all campaigns for a user
     */
    public function getCampaigns(string $userEmail, int $limit = 50, int $offset = 0): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    c.campaign_id, c.subject, c.total_recipients, c.sent_count, c.failed_count,
                    c.status, c.created_at, c.started_at, c.completed_at, c.updated_at,
                    c.mailing_list_id,
                    ROUND((c.sent_count / NULLIF(c.total_recipients, 0)) * 100, 1) as progress_percent,
                    c.source, c.source_id, c.parent_campaign_id,
                    p.subject AS parent_campaign_subject,
                    ml.name AS mailing_list_name
                FROM email_campaigns c
                LEFT JOIN email_campaigns p ON p.campaign_id = c.parent_campaign_id
                LEFT JOIN mailing_lists ml ON ml.id = c.mailing_list_id
                WHERE c.user_email = ?
                ORDER BY c.created_at DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
            ");
            $stmt->execute([$userEmail]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $stmt = $this->db->prepare("
                SELECT 
                    campaign_id, subject, total_recipients, sent_count, failed_count,
                    status, created_at, started_at, completed_at,
                    ROUND((sent_count / NULLIF(total_recipients, 0)) * 100, 1) as progress_percent
                FROM email_campaigns 
                WHERE user_email = ?
                ORDER BY created_at DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
            ");
            $stmt->execute([$userEmail]);
            return $stmt->fetchAll();
        }
    }
    
    /**
     * Pause a campaign
     */
    public function pauseCampaign(string $campaignId, string $userEmail): array
    {
        $stmt = $this->db->prepare("
            UPDATE email_campaigns 
            SET status = 'paused'
            WHERE campaign_id = ? AND user_email = ? AND status IN ('pending', 'processing')
        ");
        $stmt->execute([$campaignId, $userEmail]);
        
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'Campaign not found or cannot be paused'];
        }
        
        $this->logCampaignEvent($campaignId, 'paused', null, 'Campaign paused by user');
        $this->broadcastCampaignUpdate($userEmail, $campaignId, 'paused');
        
        return ['success' => true];
    }
    
    /**
     * Resume a paused campaign
     */
    public function resumeCampaign(string $campaignId, string $userEmail): array
    {
        $stmt = $this->db->prepare("
            UPDATE email_campaigns 
            SET status = 'processing'
            WHERE campaign_id = ? AND user_email = ? AND status = 'paused'
        ");
        $stmt->execute([$campaignId, $userEmail]);
        
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'Campaign not found or not paused'];
        }
        
        $this->logCampaignEvent($campaignId, 'resumed', null, 'Campaign resumed by user');
        $this->broadcastCampaignUpdate($userEmail, $campaignId, 'resumed');
        
        return ['success' => true];
    }
    
    /**
     * Cancel a campaign and delete pending emails
     */
    public function cancelCampaign(string $campaignId, string $userEmail): array
    {
        $stmt = $this->db->prepare("
            UPDATE email_campaigns 
            SET status = 'cancelled', completed_at = NOW()
            WHERE campaign_id = ? AND user_email = ? AND status IN ('pending', 'processing', 'paused')
        ");
        $stmt->execute([$campaignId, $userEmail]);
        
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'Campaign not found or already completed'];
        }
        
        // Mark pending queue items as cancelled (keep sent ones for records)
        $stmt = $this->db->prepare("
            DELETE FROM email_queue 
            WHERE campaign_id = ? AND status = 'pending'
        ");
        $stmt->execute([$campaignId]);
        
        $this->logCampaignEvent($campaignId, 'cancelled', null, 'Campaign cancelled by user');
        $this->broadcastCampaignUpdate($userEmail, $campaignId, 'cancelled');
        
        return ['success' => true];
    }
    
    /**
     * Permanently delete a campaign and all related data
     */
    public function deleteCampaign(string $campaignId, string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT campaign_id, status FROM email_campaigns 
            WHERE campaign_id = ? AND user_email = ?
        ");
        $stmt->execute([$campaignId, $userEmail]);
        $campaign = $stmt->fetch();
        
        if (!$campaign) {
            return ['success' => false, 'error' => 'Campaign not found'];
        }
        
        if (in_array($campaign['status'], ['pending', 'processing'])) {
            return ['success' => false, 'error' => 'Cannot delete an active campaign. Cancel it first.'];
        }
        
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM email_queue WHERE campaign_id = ?");
            $stmt->execute([$campaignId]);
            
            try {
                $stmt = $this->db->prepare("DELETE FROM email_campaign_log WHERE campaign_id = ?");
                $stmt->execute([$campaignId]);
            } catch (\Throwable $e) { /* table may not exist */ }
            
            $trackingIds = [];
            try {
                $stmt = $this->db->prepare("SELECT tracking_id FROM email_tracking WHERE campaign_id = ?");
                $stmt->execute([$campaignId]);
                $trackingIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $e) { /* campaign_id column may not exist */ }
            
            if (!empty($trackingIds)) {
                $ph = implode(',', array_fill(0, count($trackingIds), '?'));
                
                try {
                    $stmt = $this->db->prepare("DELETE FROM email_read_events WHERE tracking_id IN ({$ph})");
                    $stmt->execute($trackingIds);
                } catch (\Throwable $e) { }
                
                try {
                    $stmt = $this->db->prepare("
                        DELETE ce FROM email_click_events ce
                        INNER JOIN email_link_tracking lt ON ce.link_token = lt.link_token
                        WHERE lt.tracking_id IN ({$ph})
                    ");
                    $stmt->execute($trackingIds);
                } catch (\Throwable $e) { }
                
                try {
                    $stmt = $this->db->prepare("DELETE FROM email_link_tracking WHERE tracking_id IN ({$ph})");
                    $stmt->execute($trackingIds);
                } catch (\Throwable $e) { }
                
                try {
                    $stmt = $this->db->prepare("DELETE FROM email_tracking_recipients WHERE tracking_id IN ({$ph})");
                    $stmt->execute($trackingIds);
                } catch (\Throwable $e) { }
                
                try {
                    $stmt = $this->db->prepare("DELETE FROM email_tracking WHERE tracking_id IN ({$ph})");
                    $stmt->execute($trackingIds);
                } catch (\Throwable $e) { }
            }
            
            $stmt = $this->db->prepare("DELETE FROM email_campaigns WHERE campaign_id = ?");
            $stmt->execute([$campaignId]);
            
            $this->db->commit();
            return ['success' => true];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("deleteCampaign error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete campaign'];
        }
    }
    
    /**
     * Get failed recipients for retry
     */
    public function getFailedRecipients(string $campaignId, string $userEmail): array
    {
        // Verify ownership
        $stmt = $this->db->prepare("
            SELECT campaign_id FROM email_campaigns 
            WHERE campaign_id = ? AND user_email = ?
        ");
        $stmt->execute([$campaignId, $userEmail]);
        if (!$stmt->fetch()) {
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT recipient_email, recipient_name, error_message, attempts, last_attempt_at
            FROM email_queue 
            WHERE campaign_id = ? AND status = 'failed'
        ");
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Retry failed emails in a campaign
     */
    public function retryFailed(string $campaignId, string $userEmail): array
    {
        // Verify ownership
        $stmt = $this->db->prepare("
            SELECT campaign_id, status FROM email_campaigns 
            WHERE campaign_id = ? AND user_email = ?
        ");
        $stmt->execute([$campaignId, $userEmail]);
        $campaign = $stmt->fetch();
        
        if (!$campaign) {
            return ['success' => false, 'error' => 'Campaign not found'];
        }
        
        // Reset failed items to pending
        $stmt = $this->db->prepare("
            UPDATE email_queue 
            SET status = 'pending', attempts = 0, error_message = NULL
            WHERE campaign_id = ? AND status = 'failed'
        ");
        $stmt->execute([$campaignId]);
        $count = $stmt->rowCount();
        
        if ($count > 0) {
            // Update campaign status if it was completed
            if ($campaign['status'] === 'completed') {
                $stmt = $this->db->prepare("
                    UPDATE email_campaigns 
                    SET status = 'processing', completed_at = NULL
                    WHERE campaign_id = ?
                ");
                $stmt->execute([$campaignId]);
            }
            
            $this->logCampaignEvent($campaignId, 'retry', null, "{$count} failed emails queued for retry");
        }
        
        return ['success' => true, 'retried' => $count];
    }
    
    /**
     * Get full campaign analytics: recipients, opens, clicks, unsubscribes, bounces
     */
    public function getCampaignAnalytics(string $campaignId, string $userEmail): ?array
    {
        $campaign = $this->getCampaign($campaignId, $userEmail);
        if (!$campaign) return null;
        
        // 1. All recipients with delivery status
        $stmt = $this->db->prepare("
            SELECT id, recipient_email, recipient_name, recipient_type, status, 
                   attempts, sent_at, error_message
            FROM email_queue 
            WHERE campaign_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$campaignId]);
        $recipients = $stmt->fetchAll();
        
        // 2. Get all tracking_ids for this campaign
        $trackingIds = [];
        try {
            $stmt = $this->db->prepare("
                SELECT tracking_id FROM email_tracking WHERE campaign_id = ?
            ");
            $stmt->execute([$campaignId]);
            $trackingIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            // campaign_id column may not exist yet (migration pending) -- fall back to subject+user match
            error_log("getCampaignAnalytics: campaign_id column query failed, falling back: " . $e->getMessage());
            try {
                $stmt = $this->db->prepare("
                    SELECT tracking_id FROM email_tracking 
                    WHERE user_email = ? AND subject = ?
                    ORDER BY sent_at DESC
                ");
                $stmt->execute([strtolower($userEmail), $campaign['subject']]);
                $trackingIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $e2) {
                error_log("getCampaignAnalytics: fallback tracking query also failed: " . $e2->getMessage());
            }
        }
        
        $opens = [];
        $linkClicks = [];
        $totalOpens = 0;
        $uniqueOpeners = [];
        $totalClicks = 0;
        $uniqueClickers = [];
        
        if (!empty($trackingIds)) {
            $placeholders = implode(',', array_fill(0, count($trackingIds), '?'));
            
            // 3. Read events (opens) grouped per recipient
            $stmt = $this->db->prepare("
                SELECT re.tracking_id, re.recipient_email, re.ip_address, re.read_at
                FROM email_read_events re
                WHERE re.tracking_id IN ({$placeholders})
                ORDER BY re.read_at ASC
            ");
            $stmt->execute($trackingIds);
            $readEvents = $stmt->fetchAll();
            
            foreach ($readEvents as $ev) {
                $email = strtolower($ev['recipient_email']);
                if (!isset($opens[$email])) {
                    $opens[$email] = ['count' => 0, 'first_at' => $ev['read_at'], 'last_at' => $ev['read_at'], 'events' => []];
                }
                $opens[$email]['count']++;
                $opens[$email]['last_at'] = $ev['read_at'];
                $opens[$email]['events'][] = ['at' => $ev['read_at'], 'ip' => $ev['ip_address']];
                $totalOpens++;
                $uniqueOpeners[$email] = true;
            }
            
            // 4. Link click events
            $stmt = $this->db->prepare("
                SELECT lt.tracking_id, lt.original_url, lt.link_token,
                       lt.block_id, lt.block_type, lt.block_name,
                       ce.recipient_email, ce.ip_address, ce.clicked_at
                FROM email_link_tracking lt
                INNER JOIN email_click_events ce ON ce.link_token = lt.link_token
                WHERE lt.tracking_id IN ({$placeholders})
                ORDER BY ce.clicked_at ASC
            ");
            $stmt->execute($trackingIds);
            $clickEvents = $stmt->fetchAll();
            
            foreach ($clickEvents as $ev) {
                $url = $ev['original_url'];
                if (!isset($linkClicks[$url])) {
                    $linkClicks[$url] = ['url' => $url, 'total_clicks' => 0, 'unique_clickers' => [], 'events' => [],
                        'block_id' => $ev['block_id'], 'block_type' => $ev['block_type'], 'block_name' => $ev['block_name']];
                }
                $linkClicks[$url]['total_clicks']++;
                $clickerEmail = strtolower($ev['recipient_email']);
                $linkClicks[$url]['unique_clickers'][$clickerEmail] = true;
                $linkClicks[$url]['events'][] = [
                    'email' => $ev['recipient_email'],
                    'at' => $ev['clicked_at'],
                    'ip' => $ev['ip_address']
                ];
                $totalClicks++;
                $uniqueClickers[$clickerEmail] = true;
            }
        }
        
        // Finalize link click data
        $linkClicksArray = [];
        foreach ($linkClicks as $url => $data) {
            $linkClicksArray[] = [
                'url' => $data['url'],
                'total_clicks' => $data['total_clicks'],
                'unique_clickers' => count($data['unique_clickers']),
                'events' => $data['events'],
                'block_id' => $data['block_id'] ?? null,
                'block_type' => $data['block_type'] ?? null,
                'block_name' => $data['block_name'] ?? null,
            ];
        }
        usort($linkClicksArray, fn($a, $b) => $b['total_clicks'] - $a['total_clicks']);
        
        // Build per-block engagement summary
        $blockStats = [];
        foreach ($linkClicksArray as $link) {
            $bid = $link['block_id'];
            if (!$bid) continue;
            if (!isset($blockStats[$bid])) {
                $blockStats[$bid] = [
                    'block_id' => $bid,
                    'block_type' => $link['block_type'],
                    'block_name' => $link['block_name'],
                    'total_clicks' => 0,
                    'unique_clickers' => [],
                    'links' => [],
                ];
            }
            $blockStats[$bid]['total_clicks'] += $link['total_clicks'];
            foreach ($link['events'] as $ev) {
                $blockStats[$bid]['unique_clickers'][strtolower($ev['email'])] = true;
            }
            $blockStats[$bid]['links'][] = [
                'url' => $link['url'],
                'total_clicks' => $link['total_clicks'],
                'unique_clickers' => $link['unique_clickers'],
            ];
        }
        $blockStatsArray = [];
        foreach ($blockStats as $b) {
            $b['unique_clickers'] = count($b['unique_clickers']);
            $sent = (int)$campaign['sent_count'];
            $b['click_rate'] = $sent > 0 ? round(($b['unique_clickers'] / $sent) * 100, 1) : 0;
            $blockStatsArray[] = $b;
        }
        usort($blockStatsArray, fn($a, $b) => $b['total_clicks'] - $a['total_clicks']);
        
        // 5. Unsubscribes from this sender
        $campaignUnsubscribes = [];
        $recipientEmails = array_map(fn($r) => strtolower($r['recipient_email']), $recipients);
        try {
            $stmt = $this->db->prepare("
                SELECT unsubscribed_email, reason, unsubscribed_at
                FROM email_unsubscribes
                WHERE user_email = ?
                ORDER BY unsubscribed_at DESC
            ");
            $stmt->execute([$userEmail]);
            $allUnsubscribes = $stmt->fetchAll();
            
            $campaignUnsubscribes = array_values(array_filter($allUnsubscribes, function($u) use ($recipientEmails) {
                return in_array(strtolower($u['unsubscribed_email']), $recipientEmails);
            }));
        } catch (\Throwable $e) {
            error_log("getCampaignAnalytics: unsubscribes query failed: " . $e->getMessage());
        }
        
        // 6. Campaign activity log
        $activityLog = [];
        try {
            $stmt = $this->db->prepare("
                SELECT event_type, recipient_email, message, created_at
                FROM email_campaign_log
                WHERE campaign_id = ?
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$campaignId]);
            $activityLog = $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log("getCampaignAnalytics: activity log query failed: " . $e->getMessage());
        }
        
        // 7. Build per-recipient click summary
        $recipientClicks = [];
        foreach ($linkClicks as $url => $data) {
            foreach ($data['events'] as $ev) {
                $clickerEmail = strtolower($ev['email']);
                if (!isset($recipientClicks[$clickerEmail])) {
                    $recipientClicks[$clickerEmail] = ['count' => 0, 'links' => []];
                }
                $recipientClicks[$clickerEmail]['count']++;
                $recipientClicks[$clickerEmail]['links'][] = [
                    'url' => $data['url'],
                    'clicked_at' => $ev['at'],
                    'ip' => $ev['ip']
                ];
            }
        }
        
        // 8. Enrich recipients with tracking data
        $enrichedRecipients = [];
        foreach ($recipients as $r) {
            $email = strtolower($r['recipient_email']);
            $enrichedRecipients[] = array_merge($r, [
                'opened' => isset($opens[$email]),
                'open_count' => $opens[$email]['count'] ?? 0,
                'first_open_at' => $opens[$email]['first_at'] ?? null,
                'last_open_at' => $opens[$email]['last_at'] ?? null,
                'open_events' => $opens[$email]['events'] ?? [],
                'clicked' => isset($uniqueClickers[$email]),
                'click_count' => $recipientClicks[$email]['count'] ?? 0,
                'clicked_links' => $recipientClicks[$email]['links'] ?? [],
                'unsubscribed' => in_array($email, array_map('strtolower', array_column($campaignUnsubscribes, 'unsubscribed_email')))
            ]);
        }
        
        // 9. Summary stats
        $totalSent = (int)$campaign['sent_count'];
        $totalFailed = (int)$campaign['failed_count'];
        $skippedUnsub = count(array_filter($recipients, fn($r) => $r['status'] === 'skipped_unsubscribed'));
        
        $summary = [
            'total_recipients' => (int)$campaign['total_recipients'],
            'sent' => $totalSent,
            'failed' => $totalFailed,
            'skipped_unsubscribed' => $skippedUnsub,
            'pending' => count(array_filter($recipients, fn($r) => in_array($r['status'], ['pending', 'rate_limited']))),
            'total_opens' => $totalOpens,
            'unique_opens' => count($uniqueOpeners),
            'open_rate' => $totalSent > 0 ? round((count($uniqueOpeners) / $totalSent) * 100, 1) : 0,
            'total_clicks' => $totalClicks,
            'unique_clickers' => count($uniqueClickers),
            'click_rate' => $totalSent > 0 ? round((count($uniqueClickers) / $totalSent) * 100, 1) : 0,
            'unsubscribes' => count($campaignUnsubscribes),
            'unsubscribe_rate' => $totalSent > 0 ? round((count($campaignUnsubscribes) / $totalSent) * 100, 1) : 0,
            'bounce_rate' => $totalSent + $totalFailed > 0 ? round(($totalFailed / ($totalSent + $totalFailed)) * 100, 1) : 0,
        ];
        
        // Strip large fields from campaign to reduce response size
        unset($campaign['body_html'], $campaign['body_text']);
        
        return [
            'campaign' => $campaign,
            'summary' => $summary,
            'recipients' => $enrichedRecipients,
            'links' => $linkClicksArray,
            'block_stats' => $blockStatsArray,
            'unsubscribes' => $campaignUnsubscribes,
            'activity_log' => $activityLog,
        ];
    }
    
    // ========================================
    // RATE LIMITING
    // ========================================
    
    /**
     * Check rate limits for a user
     * Returns: ['hourly_available' => int, 'daily_available' => int, 'can_send' => int]
     */
    public function checkRateLimits(string $userEmail): array
    {
        // Get or create rate limit record
        $stmt = $this->db->prepare("
            SELECT * FROM email_rate_limits WHERE user_email = ?
        ");
        $stmt->execute([$userEmail]);
        $limits = $stmt->fetch();
        
        $now = new \DateTime();
        
        if (!$limits) {
            // Create new record
            $stmt = $this->db->prepare("
                INSERT INTO email_rate_limits (user_email, hourly_count, hourly_reset_at, daily_count, daily_reset_at)
                VALUES (?, 0, ?, 0, ?)
            ");
            $hourlyReset = (clone $now)->modify('+1 hour');
            $dailyReset = (clone $now)->modify('+1 day')->setTime(0, 0, 0);
            $stmt->execute([$userEmail, $hourlyReset->format('Y-m-d H:i:s'), $dailyReset->format('Y-m-d H:i:s')]);
            
            return [
                'hourly_available' => self::HOURLY_LIMIT,
                'daily_available' => self::DAILY_LIMIT,
                'can_send' => self::HOURLY_LIMIT,
                'hourly_reset_at' => $hourlyReset->format('Y-m-d H:i:s'),
                'daily_reset_at' => $dailyReset->format('Y-m-d H:i:s')
            ];
        }
        
        $hourlyReset = new \DateTime($limits['hourly_reset_at']);
        $dailyReset = new \DateTime($limits['daily_reset_at']);
        
        $hourlyCount = (int)$limits['hourly_count'];
        $dailyCount = (int)$limits['daily_count'];
        
        // Reset counters if time has passed
        if ($now >= $hourlyReset) {
            $hourlyCount = 0;
            $hourlyReset = (clone $now)->modify('+1 hour');
            
            $stmt = $this->db->prepare("
                UPDATE email_rate_limits 
                SET hourly_count = 0, hourly_reset_at = ?
                WHERE user_email = ?
            ");
            $stmt->execute([$hourlyReset->format('Y-m-d H:i:s'), $userEmail]);
        }
        
        if ($now >= $dailyReset) {
            $dailyCount = 0;
            $dailyReset = (clone $now)->modify('+1 day')->setTime(0, 0, 0);
            
            $stmt = $this->db->prepare("
                UPDATE email_rate_limits 
                SET daily_count = 0, daily_reset_at = ?
                WHERE user_email = ?
            ");
            $stmt->execute([$dailyReset->format('Y-m-d H:i:s'), $userEmail]);
        }
        
        $hourlyAvailable = max(0, self::HOURLY_LIMIT - $hourlyCount);
        $dailyAvailable = max(0, self::DAILY_LIMIT - $dailyCount);
        
        return [
            'hourly_available' => $hourlyAvailable,
            'daily_available' => $dailyAvailable,
            'can_send' => min($hourlyAvailable, $dailyAvailable),
            'hourly_reset_at' => $hourlyReset->format('Y-m-d H:i:s'),
            'daily_reset_at' => $dailyReset->format('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Increment rate limit counters after sending
     */
    private function incrementRateLimits(string $userEmail, int $count = 1): void
    {
        $stmt = $this->db->prepare("
            UPDATE email_rate_limits 
            SET hourly_count = hourly_count + ?, daily_count = daily_count + ?
            WHERE user_email = ?
        ");
        $stmt->execute([$count, $count, $userEmail]);
    }
    
    // ========================================
    // QUEUE PROCESSING (Called by cron)
    // ========================================
    
    /**
     * Process the email queue
     * Called by cron job every minute
     */
    public function processQueue(int $batchSize = null): array
    {
        $batchSize = $batchSize ?? self::BATCH_SIZE;
        $processed = 0;
        $sent = 0;
        $failed = 0;
        $rateLimited = 0;
        
        // Get campaigns that need processing
        $stmt = $this->db->prepare("
            SELECT DISTINCT c.campaign_id, c.user_email, c.subject, c.body_html, c.body_text,
                   c.from_name, c.attachments, c.in_reply_to, c.`references`, c.track_read
            FROM email_campaigns c
            INNER JOIN email_queue q ON q.campaign_id = c.campaign_id
            WHERE c.status IN ('pending', 'processing')
              AND q.status = 'pending'
            ORDER BY c.created_at ASC
            LIMIT 10
        ");
        $stmt->execute();
        $campaigns = $stmt->fetchAll();
        
        foreach ($campaigns as $campaign) {
            // Check rate limits for this user
            $limits = $this->checkRateLimits($campaign['user_email']);
            
            if ($limits['can_send'] <= 0) {
                // User has hit rate limits, mark their pending emails as rate_limited
                $stmt = $this->db->prepare("
                    UPDATE email_queue 
                    SET status = 'rate_limited'
                    WHERE campaign_id = ? AND status = 'pending'
                    LIMIT ?
                ");
                $stmt->bindValue(1, $campaign['campaign_id'], \PDO::PARAM_STR);
                $stmt->bindValue(2, (int) $batchSize, \PDO::PARAM_INT);
                $stmt->execute();
                $rateLimited += $stmt->rowCount();
                continue;
            }
            
            // Mark campaign as processing if it's pending
            $stmt = $this->db->prepare("
                UPDATE email_campaigns 
                SET status = 'processing', started_at = COALESCE(started_at, NOW())
                WHERE campaign_id = ? AND status = 'pending'
            ");
            $stmt->execute([$campaign['campaign_id']]);
            
            // Get emails to send (respect rate limit)
            $canSend = (int) min($limits['can_send'], $batchSize);
            $stmt = $this->db->prepare("
                SELECT id, recipient_email, recipient_name, recipient_type
                FROM email_queue 
                WHERE campaign_id = ? AND status IN ('pending', 'rate_limited')
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, $campaign['campaign_id'], \PDO::PARAM_STR);
            $stmt->bindValue(2, $canSend, \PDO::PARAM_INT);
            $stmt->execute();
            $emails = $stmt->fetchAll();
            
            if (empty($emails)) {
                continue;
            }
            
            // Unsubscribe check service (lazy init per campaign batch)
            $unsubCheckService = null;
            
            // Send each email
            foreach ($emails as $email) {
                // Check if recipient has unsubscribed from this sender
                try {
                    if ($unsubCheckService === null) {
                        $unsubCheckService = new UnsubscribeService($this->config);
                    }
                    if ($unsubCheckService->isUnsubscribed($campaign['user_email'], $email['recipient_email'])) {
                        $stmt = $this->db->prepare("
                            UPDATE email_queue 
                            SET status = 'skipped_unsubscribed', sent_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$email['id']]);
                        $stmt = $this->db->prepare("
                            UPDATE email_campaigns SET sent_count = sent_count + 1 WHERE campaign_id = ?
                        ");
                        $stmt->execute([$campaign['campaign_id']]);
                        $processed++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    // FAIL-SAFE: if we can't verify unsubscribe status, skip this email rather than risk sending to someone who unsubscribed
                    error_log("EmailQueueService: Unsubscribe check failed for {$email['recipient_email']} - SKIPPING send as safety measure: " . $e->getMessage());
                    $stmt = $this->db->prepare("
                        UPDATE email_queue 
                        SET status = 'failed', error_message = 'Unsubscribe check failed - safety skip', attempts = attempts + 1
                        WHERE id = ?
                    ");
                    $stmt->execute([$email['id']]);
                    $failed++;
                    $processed++;
                    continue;
                }
                
                $result = $this->sendQueuedEmail($campaign, $email);
                $processed++;
                
                if ($result['success']) {
                    $sent++;
                    $this->incrementRateLimits($campaign['user_email'], 1);
                    
                    // Update queue item
                    $stmt = $this->db->prepare("
                        UPDATE email_queue 
                        SET status = 'sent', sent_at = NOW(), attempts = attempts + 1
                        WHERE id = ?
                    ");
                    $stmt->execute([$email['id']]);
                    
                    // Update campaign sent count
                    $stmt = $this->db->prepare("
                        UPDATE email_campaigns 
                        SET sent_count = sent_count + 1
                        WHERE campaign_id = ?
                    ");
                    $stmt->execute([$campaign['campaign_id']]);
                    
                } else {
                    $failed++;
                    
                    // Update queue item with error
                    $stmt = $this->db->prepare("
                        UPDATE email_queue 
                        SET status = 'failed', error_message = ?, attempts = attempts + 1, last_attempt_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$result['error'] ?? 'Unknown error', $email['id']]);
                    
                    // Update campaign failed count
                    $stmt = $this->db->prepare("
                        UPDATE email_campaigns 
                        SET failed_count = failed_count + 1
                        WHERE campaign_id = ?
                    ");
                    $stmt->execute([$campaign['campaign_id']]);
                    
                    $this->logCampaignEvent($campaign['campaign_id'], 'failed', $email['recipient_email'], $result['error'] ?? 'Unknown error');
                }
            }
            
            // Check if campaign is complete
            $this->checkCampaignCompletion($campaign['campaign_id'], $campaign['user_email']);
            
            // Broadcast progress
            $this->broadcastCampaignProgress($campaign['user_email'], $campaign['campaign_id']);
        }
        
        // Reset rate_limited emails that can now be sent
        $this->resetRateLimitedEmails();
        
        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'rate_limited' => $rateLimited
        ];
    }
    
    /**
     * Send a single queued email
     */
    private function sendQueuedEmail(array $campaign, array $queueItem): array
    {
        try {
            // Get user's credentials (need to look this up)
            $credentials = $this->getUserCredentials($campaign['user_email']);
            
            if (!$credentials) {
                return ['success' => false, 'error' => 'User credentials not found'];
            }
            
            $smtp = new SmtpService($this->config['smtp']);
            
            if (!empty($credentials['oauth_token'])) {
                $smtp->setOAuthCredentials(
                    $campaign['user_email'], 
                    $credentials['oauth_token'], 
                    $credentials['oauth_provider'] ?? 'google'
                );
            } else {
                $smtp->setCredentials($campaign['user_email'], $credentials['password']);
            }
            
            // Replace merge tags per recipient
            $subject = $this->replaceMergeTags($campaign['subject'], $queueItem);
            $bodyHtml = $this->replaceMergeTags($campaign['body_html'], $queueItem);
            $bodyText = $this->replaceMergeTags($campaign['body_text'] ?? '', $queueItem);
            
            $params = [
                'to' => [[
                    'email' => $queueItem['recipient_email'],
                    'name' => $queueItem['recipient_name'] ?? ''
                ]],
                'cc' => [],
                'bcc' => [],
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'body_text' => $bodyText,
                'from_name' => $campaign['from_name'] ?? '',
            ];
            
            // Add reply headers if present
            if (!empty($campaign['in_reply_to'])) {
                $params['in_reply_to'] = $campaign['in_reply_to'];
            }
            if (!empty($campaign['references'])) {
                $params['references'] = $campaign['references'];
            }
            
            // Handle attachments
            $attachments = json_decode($campaign['attachments'] ?? '[]', true);
            if (!empty($attachments)) {
                $params['attachments'] = $attachments;
            }
            
            // Unsubscribe headers + footer (RFC 2369 / RFC 8058)
            try {
                $unsubService = new UnsubscribeService($this->config);
                $baseUrl = $this->getBaseUrl();
                
                $params['custom_headers'] = $unsubService->getUnsubscribeHeaders(
                    $campaign['user_email'],
                    $queueItem['recipient_email'],
                    $baseUrl
                );
                
                $footer = $unsubService->getUnsubscribeFooterHtml(
                    $campaign['user_email'],
                    $queueItem['recipient_email'],
                    $baseUrl
                );
                
                if (stripos($params['body_html'], '</body>') !== false) {
                    $params['body_html'] = str_ireplace('</body>', $footer . '</body>', $params['body_html']);
                } else {
                    $params['body_html'] .= $footer;
                }
            } catch (\Throwable $e) {
                error_log("EmailQueueService: Failed to add unsubscribe headers: " . $e->getMessage());
            }
            
            // Tracking pixel + link rewriting for campaign emails
            if (!empty($campaign['track_read'])) {
                try {
                    $trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($this->config);
                    $baseUrl = $baseUrl ?? $this->getBaseUrl();
                    
                    $trackingId = $trackingService->generateTrackingId();
                    $recipientTokens = $trackingService->createTracking(
                        $campaign['user_email'],
                        $trackingId,
                        $campaign['subject'],
                        [['email' => $queueItem['recipient_email'], 'name' => $queueItem['recipient_name'] ?? '']],
                        $campaign['campaign_id']
                    );
                    
                    if ($recipientTokens) {
                        $recipientToken = $recipientTokens[strtolower($queueItem['recipient_email'])] ?? null;
                        
                        if ($recipientToken) {
                            // Inject tracking pixel
                            $pixel = $trackingService->getSingleRecipientPixel($recipientToken, $baseUrl);
                            if (stripos($params['body_html'], '</body>') !== false) {
                                $params['body_html'] = str_ireplace('</body>', $pixel . '</body>', $params['body_html']);
                            } else {
                                $params['body_html'] .= $pixel;
                            }
                            
                            // Rewrite links for click tracking
                            $params['body_html'] = $trackingService->rewriteLinks(
                                $params['body_html'], $trackingId, $recipientToken, $baseUrl
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("EmailQueueService: Tracking injection failed: " . $e->getMessage());
                }
            }
            
            $result = $smtp->send($params);
            
            return $result;
            
        } catch (\Exception $e) {
            error_log("EmailQueueService: Failed to send email: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get the base URL for tracking/unsubscribe links.
     * Works in both web and CLI (cron) contexts.
     */
    private function getBaseUrl(): string
    {
        // Prefer the configured api_url (strip trailing /api)
        if (!empty($this->config['app']['api_url'])) {
            return rtrim(preg_replace('#/api$#', '', $this->config['app']['api_url']), '/');
        }
        if (!empty($this->config['app']['frontend_url'])) {
            return rtrim($this->config['app']['frontend_url'], '/');
        }

        // Fallback for web requests
        if (!empty($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $protocol . '://' . $_SERVER['HTTP_HOST'];
        }

        return 'https://flowone.pro';
    }
    
    /**
     * Get user credentials for sending
     */
    private function getUserCredentials(string $userEmail): ?array
    {
        $email = strtolower($userEmail);

        // 1. Check for OAuth accounts (tokens are encrypted, use OAuth service to decrypt + refresh)
        try {
            $stmt = $this->db->prepare("
                SELECT provider FROM webmail_oauth_tokens
                WHERE primary_email = ? AND oauth_email = ?
                LIMIT 1
            ");
            $stmt->execute([$email, $email]);
            $oauth = $stmt->fetch();

            if ($oauth) {
                $provider = $oauth['provider'] ?? 'google';
                $accessToken = null;

                if ($provider === 'microsoft') {
                    $ms = new \Webmail\Services\MicrosoftOAuthService($this->config);
                    $accessToken = $ms->getValidAccessToken($email, $email);
                } else {
                    $google = new \Webmail\Services\GoogleOAuthService($this->config);
                    $accessToken = $google->getValidAccessToken($email, $email);
                }

                if ($accessToken) {
                    return [
                        'oauth_token' => $accessToken,
                        'oauth_provider' => $provider
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log("EmailQueueService: OAuth credential lookup failed: " . $e->getMessage());
        }

        // 2. Get password from most recent active session (encrypted in webmail_sessions)
        try {
            $stmt = $this->db->prepare("
                SELECT encrypted_password FROM webmail_sessions
                WHERE email = ? AND encrypted_password IS NOT NULL AND expires_at > NOW()
                ORDER BY last_active_at DESC
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $session = $stmt->fetch();

            if ($session && !empty($session['encrypted_password'])) {
                $sessionService = new \Webmail\Services\SessionService(
                    $this->config['jwt'],
                    $this->config['imap_encryption_key'] ?? ''
                );
                $password = $sessionService->decryptPassword($session['encrypted_password']);
                if ($password) {
                    return ['password' => $password];
                }
            }
        } catch (\Throwable $e) {
            error_log("EmailQueueService: Session password lookup failed: " . $e->getMessage());
        }

        return null;
    }
    
    /**
     * Check if campaign is complete and update status
     */
    private function checkCampaignCompletion(string $campaignId, string $userEmail): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as pending
            FROM email_queue 
            WHERE campaign_id = ? AND status IN ('pending', 'sending', 'rate_limited')
        ");
        $stmt->execute([$campaignId]);
        $result = $stmt->fetch();
        
        if ((int)$result['pending'] === 0) {
            $stmt = $this->db->prepare("
                UPDATE email_campaigns 
                SET status = 'completed', completed_at = NOW()
                WHERE campaign_id = ?
            ");
            $stmt->execute([$campaignId]);
            
            $this->logCampaignEvent($campaignId, 'completed', null, 'Campaign completed');
            $this->broadcastCampaignUpdate($userEmail, $campaignId, 'completed');
        }
    }
    
    /**
     * Reset rate_limited emails when limits reset
     */
    private function resetRateLimitedEmails(): void
    {
        // Get users who have rate_limited emails
        $stmt = $this->db->prepare("
            SELECT DISTINCT c.user_email, q.campaign_id
            FROM email_queue q
            INNER JOIN email_campaigns c ON c.campaign_id = q.campaign_id
            WHERE q.status = 'rate_limited'
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        foreach ($users as $user) {
            $limits = $this->checkRateLimits($user['user_email']);
            
            if ($limits['can_send'] > 0) {
                // Reset rate_limited to pending
                $stmt = $this->db->prepare("
                    UPDATE email_queue 
                    SET status = 'pending'
                    WHERE campaign_id = ? AND status = 'rate_limited'
                ");
                $stmt->execute([$user['campaign_id']]);
            }
        }
    }
    
    // ========================================
    // DRAFT CAMPAIGNS
    // ========================================
    
    public function createDraftCampaign(string $userEmail, string $subject = ''): array
    {
        $this->ensureSourceColumns();
        $campaignId = $this->generateUUID();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_campaigns 
                (campaign_id, user_email, subject, body_html, body_text, total_recipients, status, source)
                VALUES (?, ?, ?, '', '', 0, 'draft', 'manual')
            ");
            $stmt->execute([$campaignId, $userEmail, $subject]);
            
            $this->logCampaignEvent($campaignId, 'queued', null, 'Draft campaign created');
            
            return [
                'success' => true,
                'campaign_id' => $campaignId,
            ];
        } catch (\Exception $e) {
            error_log("EmailQueueService: Failed to create draft: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create draft: ' . $e->getMessage()];
        }
    }
    
    public function updateDraftCampaign(string $campaignId, string $userEmail, array $data): array
    {
        $campaign = $this->getCampaign($campaignId, $userEmail);
        if (!$campaign) {
            return ['success' => false, 'error' => 'Campaign not found'];
        }
        if ($campaign['status'] !== 'draft') {
            return ['success' => false, 'error' => 'Only draft campaigns can be edited'];
        }
        
        $fields = [];
        $values = [];
        
        $allowedFields = ['subject', 'body_html', 'body_text', 'from_name', 'mailing_list_id', 'track_read'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (array_key_exists('attachments', $data)) {
            $fields[] = "attachments = ?";
            $values[] = json_encode($data['attachments']);
        }
        
        
        if (empty($fields)) {
            return ['success' => true];
        }
        
        $values[] = $campaignId;
        $values[] = $userEmail;
        
        try {
            $stmt = $this->db->prepare("
                UPDATE email_campaigns SET " . implode(', ', $fields) . "
                WHERE campaign_id = ? AND user_email = ? AND status = 'draft'
            ");
            $stmt->execute($values);
            
            return ['success' => true];
        } catch (\Exception $e) {
            error_log("EmailQueueService: Failed to update draft: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to update draft: ' . $e->getMessage()];
        }
    }
    
    public function finalizeDraftCampaign(string $campaignId, string $userEmail): array
    {
        $campaign = $this->getCampaign($campaignId, $userEmail);
        if (!$campaign) {
            return ['success' => false, 'error' => 'Campaign not found'];
        }
        if ($campaign['status'] !== 'draft') {
            return ['success' => false, 'error' => 'Only draft campaigns can be finalized'];
        }
        if (empty($campaign['body_html'])) {
            return ['success' => false, 'error' => 'Email content is required before sending'];
        }
        if (empty($campaign['mailing_list_id'])) {
            return ['success' => false, 'error' => 'A mailing list must be assigned before sending'];
        }
        
        try {
            $mlService = new MailingListService($this->config);
            $contacts = $mlService->getContacts((int)$campaign['mailing_list_id'], $userEmail);
            
            if (empty($contacts)) {
                return ['success' => false, 'error' => 'Mailing list has no contacts'];
            }
            
            $unsubscribedEmails = [];
            try {
                $unsubService = new UnsubscribeService($this->config);
                $unsubList = $unsubService->getUnsubscribeList($userEmail, 10000, 0);
                foreach ($unsubList as $u) {
                    $unsubscribedEmails[strtolower($u['unsubscribed_email'])] = true;
                }
            } catch (\Throwable $e) {
                error_log("finalizeDraft: Failed to check unsubscribes: " . $e->getMessage());
            }
            
            $this->db->beginTransaction();
            
            $pendingStmt = $this->db->prepare("
                INSERT INTO email_queue 
                (campaign_id, recipient_email, recipient_name, recipient_type, recipient_data, status)
                VALUES (?, ?, ?, 'to', ?, 'pending')
            ");
            $skippedStmt = $this->db->prepare("
                INSERT INTO email_queue 
                (campaign_id, recipient_email, recipient_name, recipient_type, recipient_data, status, sent_at)
                VALUES (?, ?, ?, 'to', ?, 'skipped_unsubscribed', NOW())
            ");
            
            $totalRecipients = count($contacts);
            $skippedCount = 0;
            
            foreach ($contacts as $contact) {
                $email = $contact['email'] ?? '';
                $name = $contact['name'] ?? '';
                if (empty($email)) continue;
                
                $recipientData = json_encode([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $contact['phone'] ?? '',
                    'position' => $contact['position'] ?? '',
                    'company' => $contact['company'] ?? '',
                    'custom_fields' => json_decode($contact['custom_fields'] ?? '{}', true) ?: [],
                ]);
                
                if (isset($unsubscribedEmails[strtolower($email)])) {
                    $skippedStmt->execute([$campaignId, $email, $name, $recipientData]);
                    $skippedCount++;
                } else {
                    $pendingStmt->execute([$campaignId, $email, $name, $recipientData]);
                }
            }
            
            $stmt = $this->db->prepare("
                UPDATE email_campaigns 
                SET status = 'pending', total_recipients = ?, sent_count = ?
                WHERE campaign_id = ? AND user_email = ?
            ");
            $stmt->execute([$totalRecipients, $skippedCount, $campaignId, $userEmail]);
            
            $queuedCount = $totalRecipients - $skippedCount;
            $skippedMsg = $skippedCount > 0 ? " ({$skippedCount} skipped - unsubscribed)" : '';
            $this->logCampaignEvent($campaignId, 'queued', null, "Draft finalized with {$totalRecipients} recipients{$skippedMsg}");
            
            $this->db->commit();
            
            $estimatedHours = ceil($queuedCount / self::HOURLY_LIMIT);
            
            return [
                'success' => true,
                'campaign_id' => $campaignId,
                'total_recipients' => $totalRecipients,
                'queued_count' => $queuedCount,
                'skipped_unsubscribed' => $skippedCount,
                'estimated_hours' => $estimatedHours,
                'message' => "Campaign queued. {$queuedCount} emails will be sent over approximately {$estimatedHours} hour(s)."
            ];
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("EmailQueueService: Failed to finalize draft: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to finalize draft: ' . $e->getMessage()];
        }
    }
    
    // ========================================
    // MERGE TAG REPLACEMENT
    // ========================================
    
    private function replaceMergeTags(string $content, array $queueItem): string
    {
        $recipientData = json_decode($queueItem['recipient_data'] ?? '{}', true);
        if (empty($recipientData) && empty($queueItem['recipient_email'])) {
            return $content;
        }
        
        $vars = [
            '{name}' => $recipientData['name'] ?? $queueItem['recipient_name'] ?? '',
            '{email}' => $recipientData['email'] ?? $queueItem['recipient_email'] ?? '',
            '{phone}' => $recipientData['phone'] ?? '',
            '{position}' => $recipientData['position'] ?? '',
            '{company}' => $recipientData['company'] ?? '',
        ];
        
        $customFields = $recipientData['custom_fields'] ?? [];
        foreach ($customFields as $key => $value) {
            $vars['{' . $key . '}'] = (string)$value;
        }
        
        return str_replace(array_keys($vars), array_values($vars), $content);
    }
    
    // ========================================
    // LOGGING & BROADCASTING
    // ========================================
    
    private function logCampaignEvent(string $campaignId, string $eventType, ?string $recipientEmail, ?string $message): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_campaign_log (campaign_id, event_type, recipient_email, message)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$campaignId, $eventType, $recipientEmail, $message]);
        } catch (\Exception $e) {
            error_log("EmailQueueService: Failed to log event: " . $e->getMessage());
        }
    }
    
    private function broadcastCampaignProgress(string $userEmail, string $campaignId): void
    {
        if (!$this->redis) return;
        
        try {
            $campaign = $this->getCampaign($campaignId, $userEmail);
            if (!$campaign) return;
            
            $this->redis->publish('mailsync:events', json_encode([
                'type' => 'CAMPAIGN_PROGRESS',
                'user' => $userEmail,
                'payload' => [
                    'campaign_id' => $campaignId,
                    'total_recipients' => $campaign['total_recipients'],
                    'sent_count' => $campaign['sent_count'],
                    'failed_count' => $campaign['failed_count'],
                    'progress_percent' => $campaign['progress_percent'],
                    'status' => $campaign['status']
                ]
            ]));
        } catch (\Exception $e) {
            error_log("EmailQueueService: Failed to broadcast progress: " . $e->getMessage());
        }
    }
    
    private function broadcastCampaignUpdate(string $userEmail, string $campaignId, string $action): void
    {
        if (!$this->redis) return;
        
        try {
            $campaign = $this->getCampaign($campaignId, $userEmail);
            
            $this->redis->publish('mailsync:events', json_encode([
                'type' => 'CAMPAIGN_UPDATE',
                'user' => $userEmail,
                'payload' => [
                    'action' => $action,
                    'campaign_id' => $campaignId,
                    'campaign' => $campaign
                ]
            ]));
        } catch (\Exception $e) {
            error_log("EmailQueueService: Failed to broadcast update: " . $e->getMessage());
        }
    }
    
    // ========================================
    // UTILITIES
    // ========================================
    
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

