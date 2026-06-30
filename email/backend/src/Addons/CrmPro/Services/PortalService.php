<?php

namespace Webmail\Addons\CrmPro\Services;

/**
 * PortalService - Client Portal Authentication & Access Control
 * 
 * Handles magic link generation, session management, and access control
 * for the external-facing Client Portal. Portal auth is completely 
 * independent from the internal JWT system.
 * 
 * Key principles:
 * - Single-use magic links (24h expiry)
 * - Portal sessions (30-day expiry, independent from internal JWT)
 * - Every portal request validated via session token
 * - Data isolation: client A never sees client B's data
 * - Rate limits on magic link requests (5/hr/email)
 */
class PortalService
{
    private \PDO $db;
    private array $config;

    // Constants
    private const MAGIC_LINK_EXPIRY_HOURS = 24;
    private const SESSION_EXPIRY_DAYS = 30;
    private const MAGIC_LINK_TOKEN_LENGTH = 64;
    private const SESSION_TOKEN_LENGTH = 64;
    private const RATE_LIMIT_PER_HOUR = 5;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Portal Access Management (Internal User Actions)
    // =========================================================================

    /**
     * Grant portal access to a client contact
     */
    public function grantAccess(int $clientId, string $email, ?string $name, string $createdBy, ?int $contactId = null): array
    {
        // Check if access already exists
        $stmt = $this->db->prepare('
            SELECT id, is_active FROM portal_access 
            WHERE client_id = ? AND email = ?
        ');
        $stmt->execute([$clientId, strtolower($email)]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['is_active']) {
                return ['error' => 'Portal access already granted for this email'];
            }
            // Re-enable previously revoked access
            $stmt = $this->db->prepare('
                UPDATE portal_access 
                SET is_active = 1, name = COALESCE(?, name), contact_id = COALESCE(?, contact_id), 
                    created_by = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$name, $contactId, $createdBy, $existing['id']]);
            return $this->getAccess($existing['id']);
        }

        // Create new access
        $stmt = $this->db->prepare('
            INSERT INTO portal_access (client_id, contact_id, email, name, is_active, created_by)
            VALUES (?, ?, ?, ?, 1, ?)
        ');
        $stmt->execute([$clientId, $contactId, strtolower($email), $name, $createdBy]);

        return $this->getAccess((int)$this->db->lastInsertId());
    }

    /**
     * Revoke portal access (deactivates + kills all sessions)
     */
    public function revokeAccess(int $accessId): bool
    {
        // Deactivate access
        $stmt = $this->db->prepare('UPDATE portal_access SET is_active = 0, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$accessId]);

        // Kill all sessions
        $stmt = $this->db->prepare('DELETE FROM portal_sessions WHERE portal_access_id = ?');
        $stmt->execute([$accessId]);

        // Invalidate all unused magic links
        $stmt = $this->db->prepare('
            UPDATE portal_magic_links SET used_at = NOW() 
            WHERE portal_access_id = ? AND used_at IS NULL
        ');
        $stmt->execute([$accessId]);

        return true;
    }

    /**
     * List all portal access entries for a client
     */
    public function listAccess(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT pa.*, 
                   cc.name as contact_name, cc.email as contact_email,
                   (SELECT COUNT(*) FROM portal_sessions ps WHERE ps.portal_access_id = pa.id AND ps.expires_at > NOW()) as active_sessions
            FROM portal_access pa
            LEFT JOIN client_contacts cc ON pa.contact_id = cc.id
            WHERE pa.client_id = ?
            ORDER BY pa.created_at DESC
        ');
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single portal access record
     */
    public function getAccess(int $accessId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM portal_access WHERE id = ?');
        $stmt->execute([$accessId]);
        $access = $stmt->fetch();
        return $access ?: [];
    }

    // =========================================================================
    // Magic Links
    // =========================================================================

    /**
     * Generate a magic link token for a portal access entry
     */
    public function generateMagicLink(int $accessId, ?string $ipAddress = null): array
    {
        // Verify access exists and is active
        $access = $this->getAccess($accessId);
        if (!$access || !$access['is_active']) {
            return ['error' => 'Portal access not found or is revoked'];
        }

        // Generate secure token
        $token = bin2hex(random_bytes(self::MAGIC_LINK_TOKEN_LENGTH / 2));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::MAGIC_LINK_EXPIRY_HOURS . ' hours'));

        $stmt = $this->db->prepare('
            INSERT INTO portal_magic_links (portal_access_id, token, expires_at, ip_address)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$accessId, $token, $expiresAt, $ipAddress]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'portal_access_id' => $accessId,
            'link' => $this->buildPortalUrl('/portal/auth/' . $token),
        ];
    }

    /**
     * Send a magic link email to a portal access contact
     */
    public function sendMagicLinkEmail(int $clientId, int $accessId, string $senderEmail): array
    {
        $access = $this->getAccess($accessId);
        if (!$access || !$access['is_active']) {
            return ['error' => 'Portal access not found or is revoked'];
        }

        // Get client info for the email
        $stmt = $this->db->prepare('SELECT display_name, domain FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();

        if (!$client) {
            return ['error' => 'Client not found'];
        }

        // Generate magic link
        $link = $this->generateMagicLink($accessId);
        if (isset($link['error'])) return $link;

        // Get sender credentials for SMTP
        $stmt = $this->db->prepare('
            SELECT encrypted_password FROM webmail_sessions 
            WHERE email = ? ORDER BY last_activity DESC LIMIT 1
        ');
        $stmt->execute([$senderEmail]);
        $session = $stmt->fetch();

        if (!$session) {
            return ['error' => 'Unable to send email: sender session not found'];
        }

        // Send the email
        try {
            $smtp = new \Webmail\Services\SmtpService($this->config['smtp'] ?? []);
            $decryptedPassword = $this->decryptPassword($session['encrypted_password'], $senderEmail);
            $smtp->setCredentials($senderEmail, $decryptedPassword);

            $clientName = $client['display_name'] ?: $client['domain'];
            $recipientName = $access['name'] ?: $access['email'];

            $smtp->send([
                'to' => [['email' => $access['email'], 'name' => $recipientName]],
                'subject' => "Your portal access link - {$clientName}",
                'body_html' => $this->buildMagicLinkEmailHtml($recipientName, $clientName, $link['link'], $link['expires_at']),
                'body_text' => $this->buildMagicLinkEmailText($recipientName, $clientName, $link['link'], $link['expires_at']),
            ]);

            return [
                'success' => true,
                'sent_to' => $access['email'],
                'expires_at' => $link['expires_at'],
            ];
        } catch (\Throwable $e) {
            error_log("PortalService: Failed to send magic link email: " . $e->getMessage());
            return ['error' => 'Failed to send magic link email'];
        }
    }

    /**
     * Consume a magic link token and create a portal session
     */
    public function consumeMagicLink(string $token, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        // Find the magic link
        $stmt = $this->db->prepare('
            SELECT ml.*, pa.client_id, pa.email, pa.name, pa.is_active
            FROM portal_magic_links ml
            JOIN portal_access pa ON ml.portal_access_id = pa.id
            WHERE ml.token = ?
        ');
        $stmt->execute([$token]);
        $link = $stmt->fetch();

        if (!$link) {
            return ['error' => 'Invalid or expired link', 'code' => 'invalid_token'];
        }

        if ($link['used_at'] !== null) {
            return ['error' => 'This link has already been used. Please request a new one.', 'code' => 'already_used'];
        }

        if (strtotime($link['expires_at']) < time()) {
            return ['error' => 'This link has expired. Please request a new one.', 'code' => 'expired'];
        }

        if (!$link['is_active']) {
            return ['error' => 'Portal access has been revoked.', 'code' => 'revoked'];
        }

        // Mark token as used
        $stmt = $this->db->prepare('UPDATE portal_magic_links SET used_at = NOW(), ip_address = ? WHERE id = ?');
        $stmt->execute([$ipAddress, $link['id']]);

        // Create a portal session
        $session = $this->createSession($link['portal_access_id'], $ipAddress, $userAgent);

        // Update last login + increment session count
        $stmt = $this->db->prepare('
            UPDATE portal_access SET last_login_at = NOW(), session_count = session_count + 1, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$link['portal_access_id']]);

        // Get client info
        $stmt = $this->db->prepare('
            SELECT c.id, c.display_name, c.domain
            FROM clients c WHERE c.id = ?
        ');
        $stmt->execute([$link['client_id']]);
        $client = $stmt->fetch();

        return [
            'session_token' => $session['session_token'],
            'expires_at' => $session['expires_at'],
            'portal_user' => [
                'email' => $link['email'],
                'name' => $link['name'],
                'client_id' => $link['client_id'],
                'client_name' => $client['display_name'] ?: $client['domain'],
            ],
        ];
    }

    /**
     * Check if magic link request is rate limited
     */
    private function isMagicLinkRateLimited(string $email): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM portal_magic_links ml
            JOIN portal_access pa ON ml.portal_access_id = pa.id
            WHERE pa.email = ? 
              AND ml.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
              AND ml.used_at IS NULL
        ');
        $stmt->execute([strtolower($email)]);
        return (int)$stmt->fetchColumn() >= self::RATE_LIMIT_PER_HOUR;
    }

    // =========================================================================
    // Portal Sessions
    // =========================================================================

    /**
     * Create a new portal session
     */
    private function createSession(int $portalAccessId, ?string $ipAddress, ?string $userAgent): array
    {
        $sessionToken = bin2hex(random_bytes(self::SESSION_TOKEN_LENGTH / 2));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::SESSION_EXPIRY_DAYS . ' days'));

        $stmt = $this->db->prepare('
            INSERT INTO portal_sessions (portal_access_id, session_token, user_agent, ip_address, expires_at, last_active_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$portalAccessId, $sessionToken, $userAgent, $ipAddress, $expiresAt]);

        return [
            'session_token' => $sessionToken,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Validate a portal session token and return portal user info
     * Also updates last_active_at for activity tracking
     */
    public function validateSession(string $sessionToken): ?array
    {
        $stmt = $this->db->prepare('
            SELECT ps.*, pa.client_id, pa.email, pa.name, pa.is_active, pa.contact_id
            FROM portal_sessions ps
            JOIN portal_access pa ON ps.portal_access_id = pa.id
            WHERE ps.session_token = ? AND ps.expires_at > NOW()
        ');
        $stmt->execute([$sessionToken]);
        $session = $stmt->fetch();

        if (!$session) return null;
        if (!$session['is_active']) return null;

        // Update last active timestamp (async-safe, non-blocking pattern)
        $stmt = $this->db->prepare('UPDATE portal_sessions SET last_active_at = NOW() WHERE id = ?');
        $stmt->execute([$session['id']]);

        // Get client info
        $stmt = $this->db->prepare('SELECT id, display_name, domain FROM clients WHERE id = ?');
        $stmt->execute([$session['client_id']]);
        $client = $stmt->fetch();

        return [
            'session_id' => $session['id'],
            'portal_access_id' => $session['portal_access_id'],
            'client_id' => $session['client_id'],
            'contact_id' => $session['contact_id'],
            'email' => $session['email'],
            'name' => $session['name'],
            'client_name' => $client ? ($client['display_name'] ?: $client['domain']) : 'Unknown',
        ];
    }

    /**
     * End a portal session (logout)
     */
    public function endSession(string $sessionToken): bool
    {
        $stmt = $this->db->prepare('DELETE FROM portal_sessions WHERE session_token = ?');
        $stmt->execute([$sessionToken]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get current portal user info (for /portal/me endpoint)
     */
    public function getPortalUser(string $sessionToken): ?array
    {
        $session = $this->validateSession($sessionToken);
        if (!$session) return null;

        // Get unread update count
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM portal_updates pu
            WHERE pu.client_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM portal_update_reads pur 
                WHERE pur.update_id = pu.id AND pur.portal_access_id = ?
            )
        ');
        $stmt->execute([$session['client_id'], $session['portal_access_id']]);
        $unreadUpdates = (int)$stmt->fetchColumn();

        // Get pending document count: documents needing attention
        // = sent but not yet viewed + documents where this user's signature is pending
        $stmt = $this->db->prepare('
            SELECT COUNT(DISTINCT pd.id) FROM portal_documents pd
            LEFT JOIN portal_document_signers pds ON pds.document_id = pd.id AND pds.portal_access_id = ?
            WHERE pd.client_id = ? AND pd.status NOT IN (?, ?)
            AND (
                pd.status = ?
                OR pds.status = ?
            )
        ');
        $stmt->execute([
            $session['portal_access_id'],
            $session['client_id'],
            'draft', 'archived',
            'sent',
            'pending'
        ]);
        $pendingDocuments = (int)$stmt->fetchColumn();

        // Get active call count
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM portal_calls 
            WHERE client_id = ? AND status IN (?, ?)
        ');
        $stmt->execute([$session['client_id'], 'waiting', 'active']);
        $activeCalls = (int)$stmt->fetchColumn();

        return [
            'email' => $session['email'],
            'name' => $session['name'],
            'client_id' => $session['client_id'],
            'client_name' => $session['client_name'],
            'unread_updates' => $unreadUpdates,
            'pending_documents' => $pendingDocuments,
            'active_calls' => $activeCalls,
        ];
    }

    /**
     * Handle public magic link request (client requests a new link by email)
     * Rate limited: 5/hr/email
     * 
     * Uses the original admin's (created_by) session credentials to send the email.
     * If no admin session is available, the link is still generated and will
     * be picked up the next time an admin re-sends from the CRM UI.
     */
    public function requestMagicLink(string $email, ?string $ipAddress = null): array
    {
        $email = strtolower(trim($email));

        // Rate limit
        if ($this->isMagicLinkRateLimited($email)) {
            return ['error' => 'Too many requests. Please try again later.'];
        }

        // Find active portal access for this email
        $stmt = $this->db->prepare('
            SELECT pa.*, c.display_name, c.domain
            FROM portal_access pa
            JOIN clients c ON pa.client_id = c.id
            WHERE pa.email = ? AND pa.is_active = 1
            LIMIT 1
        ');
        $stmt->execute([$email]);
        $access = $stmt->fetch();

        if (!$access) {
            // Don't reveal whether email exists (security)
            return ['success' => true, 'message' => 'If this email has portal access, a magic link has been sent.'];
        }

        // Generate the magic link
        $link = $this->generateMagicLink($access['id'], $ipAddress);
        if (isset($link['error'])) return $link;

        // Try to send via the original admin's session (created_by field)
        $senderEmail = $access['created_by'] ?? null;
        if ($senderEmail) {
            try {
                $this->sendMagicLinkEmailInternal($access, $link, $senderEmail);
            } catch (\Throwable $e) {
                // Non-blocking: link is generated even if email fails
                error_log("PortalService: Public magic link email send failed: " . $e->getMessage());
            }
        }

        // Always return the same message (security: don't reveal if email exists)
        return [
            'success' => true,
            'message' => 'If this email has portal access, a magic link has been sent.',
        ];
    }

    /**
     * Internal helper: send a magic link email using stored admin credentials
     */
    private function sendMagicLinkEmailInternal(array $access, array $linkData, string $senderEmail): void
    {
        // Get sender credentials from their most recent session
        $stmt = $this->db->prepare('
            SELECT encrypted_password FROM webmail_sessions 
            WHERE email = ? ORDER BY last_activity DESC LIMIT 1
        ');
        $stmt->execute([$senderEmail]);
        $session = $stmt->fetch();

        if (!$session) {
            throw new \RuntimeException('No active session for sender: ' . $senderEmail);
        }

        // Get client info
        $stmt = $this->db->prepare('SELECT display_name, domain FROM clients WHERE id = ?');
        $stmt->execute([$access['client_id']]);
        $client = $stmt->fetch();

        $smtp = new \Webmail\Services\SmtpService($this->config['smtp'] ?? []);
        $decryptedPassword = $this->decryptPassword($session['encrypted_password'], $senderEmail);
        $smtp->setCredentials($senderEmail, $decryptedPassword);

        $clientName = $client ? ($client['display_name'] ?: $client['domain']) : 'Your Provider';
        $recipientName = $access['name'] ?: $access['email'];

        $smtp->send([
            'to' => [['email' => $access['email'], 'name' => $recipientName]],
            'subject' => "Your portal access link - {$clientName}",
            'body_html' => $this->buildMagicLinkEmailHtml($recipientName, $clientName, $linkData['link'], $linkData['expires_at']),
            'body_text' => $this->buildMagicLinkEmailText($recipientName, $clientName, $linkData['link'], $linkData['expires_at']),
        ]);
    }

    /**
     * Clean up expired sessions (called by cron)
     */
    public function cleanupExpiredSessions(): int
    {
        $stmt = $this->db->prepare('DELETE FROM portal_sessions WHERE expires_at < NOW()');
        $stmt->execute();
        $deletedSessions = $stmt->rowCount();

        // Also clean up old used/expired magic links (older than 7 days)
        $stmt = $this->db->prepare('
            DELETE FROM portal_magic_links 
            WHERE (used_at IS NOT NULL OR expires_at < NOW()) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');
        $stmt->execute();
        $deletedLinks = $stmt->rowCount();

        return $deletedSessions + $deletedLinks;
    }

    // =========================================================================
    // Email Templates
    // =========================================================================

    /**
     * Build magic link email HTML
     */
    private function buildMagicLinkEmailHtml(string $name, string $clientName, string $link, string $expiresAt): string
    {
        $expiry = date('M j, Y g:i A', strtotime($expiresAt));
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f4f4f5;">
<div style="max-width:560px;margin:40px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
  <div style="padding:32px 32px 0;">
    <h2 style="margin:0 0 8px;color:#18181b;font-size:20px;">Portal Access</h2>
    <p style="margin:0 0 24px;color:#71717a;font-size:14px;">{$clientName}</p>
  </div>
  <div style="padding:0 32px 32px;">
    <p style="color:#3f3f46;font-size:15px;line-height:1.6;">Hi {$name},</p>
    <p style="color:#3f3f46;font-size:15px;line-height:1.6;">Click the button below to access your client portal. This link is single-use and expires on {$expiry}.</p>
    <div style="text-align:center;margin:28px 0;">
      <a href="{$link}" style="display:inline-block;padding:14px 32px;background:#6366f1;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:15px;">Open Portal</a>
    </div>
    <p style="color:#a1a1aa;font-size:12px;line-height:1.5;">If you didn't request this link, you can safely ignore this email.<br>Link expires: {$expiry}</p>
  </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * Build magic link email plaintext
     */
    private function buildMagicLinkEmailText(string $name, string $clientName, string $link, string $expiresAt): string
    {
        $expiry = date('M j, Y g:i A', strtotime($expiresAt));
        return "Portal Access - {$clientName}\n\n" .
            "Hi {$name},\n\n" .
            "Click the link below to access your client portal:\n{$link}\n\n" .
            "This link is single-use and expires on {$expiry}.\n\n" .
            "If you didn't request this link, you can safely ignore this email.";
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a full portal URL
     */
    private function buildPortalUrl(string $path): string
    {
        $baseUrl = $this->config['app']['url'] ?? $this->config['app']['frontend_url'] ?? '';
        
        // Fallback: detect from the current request if config is empty
        if (empty($baseUrl) && !empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        
        return rtrim($baseUrl, '/') . $path;
    }

    /**
     * Decrypt a password (reuse existing pattern from session management)
     */
    private function decryptPassword(string $encrypted, string $email): string
    {
        $encryptionKey = $this->config['encryption_key'] ?? $this->config['jwt']['secret'] ?? 'fallback-key';
        $key = hash('sha256', $encryptionKey . $email, true);
        $data = base64_decode($encrypted);
        $ivLen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLen);
        $ciphertext = substr($data, $ivLen);
        return openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Get the PDO instance (for use by other services that need the same connection)
     */
    public function getDb(): \PDO
    {
        return $this->db;
    }
}

