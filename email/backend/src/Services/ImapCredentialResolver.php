<?php

declare(strict_types=1);

namespace Webmail\Services;

use PDO;
use Webmail\Core\Database;

/**
 * ImapCredentialResolver
 *
 * Single source of truth for "given an account email, how do I authenticate
 * to its IMAP server right now?" — used by background workers (the outbox
 * drainer, attachment indexer, unread-count refresher) that operate outside
 * the request lifecycle and therefore cannot rely on BaseController's
 * session-bound IMAP connection.
 *
 * Resolution order mirrors the proven logic in index-attachments.php:
 *   1. OAuth tokens in `webmail_oauth_tokens` (Google / Microsoft). The
 *      provider service handles AES-256-GCM decrypt + lazy refresh, so the
 *      caller always gets a *fresh* bearer token. This is why credential
 *      resolution lives in PHP and not in the Node mailsync worker — the
 *      refresh-token decrypt key is only available to PHP.
 *   2. The most recent valid `webmail_sessions` row, whose `encrypted_password`
 *      is decrypted with IMAP_ENCRYPTION_KEY via SessionService.
 *
 * Returns one of:
 *   ['email', 'oauth_provider', 'access_token', 'imap_host', 'imap_port']  (OAuth)
 *   ['email', 'password']                                                  (password)
 *   null                                                                   (no usable creds)
 */
final class ImapCredentialResolver
{
    private array $config;
    private ?PDO $db;

    public function __construct(array $config, ?PDO $db = null)
    {
        $this->config = $config;
        $this->db = $db;
    }

    private function db(): PDO
    {
        if ($this->db === null) {
            $this->db = Database::getConnection($this->config);
        }
        return $this->db;
    }

    /**
     * @return array{email:string,oauth_provider?:string,access_token?:string,imap_host?:string,imap_port?:int,password?:string}|null
     */
    public function resolve(string $accountEmail): ?array
    {
        $oauth = $this->resolveOAuth($accountEmail);
        if ($oauth !== null) {
            return $oauth;
        }
        return $this->resolvePassword($accountEmail);
    }

    private function resolveOAuth(string $accountEmail): ?array
    {
        try {
            $stmt = $this->db()->prepare(
                "SELECT oauth_email, provider
                   FROM webmail_oauth_tokens
                  WHERE primary_email = ? AND oauth_email = ?
                    AND COALESCE(health, 'healthy') != 'revoked'
                  ORDER BY id DESC
                  LIMIT 1"
            );
            $stmt->execute([$accountEmail, $accountEmail]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $provider = $row['provider'] ?? 'google';
            $token = null;
            $host = null;
            $port = 993;

            if ($provider === 'microsoft' && !empty($this->config['microsoft_oauth']['client_id'])) {
                $token = (new MicrosoftOAuthService($this->config))
                    ->getValidAccessToken($accountEmail, (string)$row['oauth_email']);
                $host = MicrosoftOAuthService::IMAP_HOST;
                $port = MicrosoftOAuthService::IMAP_PORT;
            } elseif (!empty($this->config['google_oauth']['client_id'])) {
                $token = (new GoogleOAuthService($this->config))
                    ->getValidAccessToken($accountEmail, (string)$row['oauth_email']);
                $host = 'imap.gmail.com';
                $port = 993;
            }

            if ($token) {
                return [
                    'email'          => (string)$row['oauth_email'],
                    'oauth_provider' => $provider,
                    'access_token'   => $token,
                    'imap_host'      => $host,
                    'imap_port'      => $port,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ImapCredentialResolver] OAuth resolve failed for ' . $accountEmail . ': ' . $e->getMessage());
        }
        return null;
    }

    private function resolvePassword(string $accountEmail): ?array
    {
        try {
            $stmt = $this->db()->prepare(
                'SELECT encrypted_password FROM webmail_sessions
                  WHERE email = ? AND is_valid = 1 AND expires_at > NOW()
                  ORDER BY last_active_at DESC
                  LIMIT 1'
            );
            $stmt->execute([$accountEmail]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session || empty($session['encrypted_password'])) {
                return null;
            }

            $key = $this->config['imap_encryption_key'] ?? '';
            if ($key === '') {
                error_log('[ImapCredentialResolver] IMAP_ENCRYPTION_KEY not set; cannot decrypt session password for ' . $accountEmail);
                return null;
            }

            $sessionService = new SessionService($this->config['jwt'] ?? [], $key);
            $password = $sessionService->decryptPassword((string)$session['encrypted_password']);
            if (!$password) {
                return null;
            }
            return ['email' => $accountEmail, 'password' => $password];
        } catch (\Throwable $e) {
            error_log('[ImapCredentialResolver] session resolve failed for ' . $accountEmail . ': ' . $e->getMessage());
            return null;
        }
    }
}
