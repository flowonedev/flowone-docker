<?php

namespace Webmail\Addons\EmailMarketing\Services;

/**
 * UnsubscribeService - RFC 2369 / RFC 8058 compliant unsubscribe management
 * 
 * Handles:
 * - HMAC-signed unsubscribe tokens (tamper-proof, no DB lookup needed for validation)
 * - Unsubscribe/resubscribe record keeping
 * - Pre-send unsubscribe checks
 * - Unsubscribe footer HTML generation
 */
class UnsubscribeService
{
    private \PDO $db;
    private array $config;
    private string $hmacSecret;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->hmacSecret = $config['jwt']['secret'] ?? '';
        
        if (empty($this->hmacSecret)) {
            $this->hmacSecret = hash('sha256', ($config['db']['name'] ?? 'webmail') . '-unsubscribe');
        }
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
    }
    
    private function ensureTablesExist(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_unsubscribes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL COMMENT 'Sender who was unsubscribed from',
                    unsubscribed_email VARCHAR(255) NOT NULL COMMENT 'Recipient who unsubscribed',
                    reason VARCHAR(500) DEFAULT NULL,
                    unsubscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_unsub (user_email, unsubscribed_email),
                    INDEX idx_user (user_email),
                    INDEX idx_recipient (unsubscribed_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log("UnsubscribeService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Generate an HMAC-signed unsubscribe token.
     * The token encodes sender + recipient and is tamper-proof.
     * Format: base64(json_payload.hmac_signature)
     */
    public function generateUnsubscribeToken(string $senderEmail, string $recipientEmail): string
    {
        $payload = json_encode([
            's' => strtolower($senderEmail),
            'r' => strtolower($recipientEmail),
            't' => time(),
        ]);
        
        $hmac = hash_hmac('sha256', $payload, $this->hmacSecret);
        
        return rtrim(strtr(base64_encode($payload . '.' . $hmac), '+/', '-_'), '=');
    }
    
    /**
     * Validate an unsubscribe token.
     * Returns ['sender' => ..., 'recipient' => ...] or null if invalid.
     */
    public function validateToken(string $token): ?array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'));
        if (!$decoded) {
            return null;
        }
        
        $dotPos = strrpos($decoded, '.');
        if ($dotPos === false) {
            return null;
        }
        
        $payloadJson = substr($decoded, 0, $dotPos);
        $receivedHmac = substr($decoded, $dotPos + 1);
        
        $expectedHmac = hash_hmac('sha256', $payloadJson, $this->hmacSecret);
        if (!hash_equals($expectedHmac, $receivedHmac)) {
            return null;
        }
        
        $payload = json_decode($payloadJson, true);
        if (!$payload || empty($payload['s']) || empty($payload['r'])) {
            return null;
        }
        
        return [
            'sender' => $payload['s'],
            'recipient' => $payload['r'],
            'timestamp' => $payload['t'] ?? 0,
        ];
    }
    
    /**
     * Record an unsubscribe. Idempotent -- calling twice is safe.
     */
    public function unsubscribe(string $senderEmail, string $recipientEmail, ?string $reason = null): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_unsubscribes (user_email, unsubscribed_email, reason)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = COALESCE(VALUES(reason), reason), unsubscribed_at = NOW()
            ");
            $stmt->execute([
                strtolower($senderEmail),
                strtolower($recipientEmail),
                $reason,
            ]);
            return true;
        } catch (\PDOException $e) {
            error_log("UnsubscribeService unsubscribe error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a recipient has unsubscribed from a sender.
     */
    public function isUnsubscribed(string $senderEmail, string $recipientEmail): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM email_unsubscribes 
            WHERE user_email = ? AND unsubscribed_email = ?
            LIMIT 1
        ");
        $stmt->execute([strtolower($senderEmail), strtolower($recipientEmail)]);
        return (bool)$stmt->fetch();
    }
    
    /**
     * Get all unsubscribed addresses for a sender.
     */
    public function getUnsubscribeList(string $senderEmail, int $limit = 500, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT unsubscribed_email, reason, unsubscribed_at
            FROM email_unsubscribes 
            WHERE user_email = ?
            ORDER BY unsubscribed_at DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ");
        $stmt->execute([strtolower($senderEmail)]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get total unsubscribe count for a sender.
     */
    public function getUnsubscribeCount(string $senderEmail): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt FROM email_unsubscribes WHERE user_email = ?
        ");
        $stmt->execute([strtolower($senderEmail)]);
        return (int)$stmt->fetch()['cnt'];
    }
    
    /**
     * Remove an unsubscribe (resubscribe).
     */
    public function resubscribe(string $senderEmail, string $recipientEmail): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM email_unsubscribes 
            WHERE user_email = ? AND unsubscribed_email = ?
        ");
        $stmt->execute([strtolower($senderEmail), strtolower($recipientEmail)]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Remove unsubscribed recipient from all mailing lists owned by the sender,
     * and cancel any pending queue items across all campaigns.
     */
    public function cleanupAfterUnsubscribe(string $senderEmail, string $recipientEmail): void
    {
        $sender = strtolower($senderEmail);
        $recipient = strtolower($recipientEmail);
        
        // Remove from all mailing lists owned by this sender
        try {
            $stmt = $this->db->prepare("
                DELETE mlc FROM mailing_list_contacts mlc
                INNER JOIN mailing_lists ml ON ml.id = mlc.list_id
                WHERE ml.user_email = ? AND LOWER(mlc.email) = ?
            ");
            $stmt->execute([$sender, $recipient]);
            $removedFromLists = $stmt->rowCount();
            if ($removedFromLists > 0) {
                error_log("UnsubscribeService: Removed {$recipient} from {$removedFromLists} mailing list(s) for sender {$sender}");
            }
        } catch (\Throwable $e) {
            error_log("UnsubscribeService: Failed to remove from mailing lists: " . $e->getMessage());
        }
        
        // Cancel any pending queue items for this recipient across all sender's campaigns
        try {
            $stmt = $this->db->prepare("
                UPDATE email_queue eq
                INNER JOIN email_campaigns ec ON ec.campaign_id = eq.campaign_id
                SET eq.status = 'skipped_unsubscribed', eq.sent_at = NOW()
                WHERE ec.user_email = ? AND LOWER(eq.recipient_email) = ? AND eq.status IN ('pending', 'rate_limited')
            ");
            $stmt->execute([$sender, $recipient]);
            $cancelledQueue = $stmt->rowCount();
            if ($cancelledQueue > 0) {
                error_log("UnsubscribeService: Cancelled {$cancelledQueue} pending email(s) for {$recipient} from sender {$sender}");
            }
        } catch (\Throwable $e) {
            error_log("UnsubscribeService: Failed to cancel pending queue items: " . $e->getMessage());
        }
    }
    
    /**
     * Build the unsubscribe URL for a given sender/recipient pair.
     */
    public function buildUnsubscribeUrl(string $senderEmail, string $recipientEmail, string $baseUrl): string
    {
        $token = $this->generateUnsubscribeToken($senderEmail, $recipientEmail);
        return rtrim($baseUrl, '/') . '/api/unsubscribe/' . $token;
    }
    
    /**
     * Generate RFC-compliant email headers for unsubscribe.
     * Returns associative array of header name => value.
     */
    public function getUnsubscribeHeaders(string $senderEmail, string $recipientEmail, string $baseUrl): array
    {
        $url = $this->buildUnsubscribeUrl($senderEmail, $recipientEmail, $baseUrl);
        
        return [
            'List-Unsubscribe' => "<{$url}>, <mailto:{$senderEmail}?subject=unsubscribe>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'Precedence' => 'bulk',
        ];
    }
    
    /**
     * Generate unsubscribe footer HTML to append to email body.
     */
    public function getUnsubscribeFooterHtml(string $senderEmail, string $recipientEmail, string $baseUrl): string
    {
        $url = $this->buildUnsubscribeUrl($senderEmail, $recipientEmail, $baseUrl);
        $domain = explode('@', $senderEmail)[1] ?? $senderEmail;
        
        return '
<div style="margin-top: 40px; padding-top: 16px; border-top: 1px solid #e5e7eb; text-align: center;">
  <p style="margin: 0; font-size: 11px; color: #9ca3af; line-height: 1.6;">
    You received this email from ' . htmlspecialchars($domain) . '.
    <a href="' . htmlspecialchars($url) . '" style="color: #6b7280; text-decoration: underline;" target="_blank">Unsubscribe</a>
  </p>
</div>';
    }
}
