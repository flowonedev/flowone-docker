<?php

declare(strict_types=1);

namespace Webmail\Services;

use PDO;
use Webmail\Core\Database;

/**
 * AccountTeardownService
 *
 * Single owner of the "fully remove a linked/secondary email account from the
 * server" workflow. The credential ROW delete stays where it belongs (each
 * table's own service: GoogleOAuthService / MicrosoftOAuthService /
 * AccountService); this service tears down everything ELSE that keeps a
 * removed account alive and still pushing notifications:
 *
 *   - webmail_folder_sync_state rows  -> else the background sync cron keeps
 *                                        claiming the mailbox every pass and,
 *                                        while credentials are still resolvable,
 *                                        keeps publishing MESSAGE_NEW events
 *                                        that fan out as push notifications.
 *   - cached OAuth access token + revoked flag (Redis) -> else
 *                                        getValidAccessToken() serves a cached
 *                                        token for up to ~1h after the DB row is
 *                                        gone, so sync + push continue.
 *   - webmail_folder_identity rows for the secondary mailbox.
 *   - per-user unread count cache (rebuilt lazily on next read).
 *   - OPTIONAL: revoke the refresh token at the provider (default OFF;
 *               only attempted when the caller passes the refresh token).
 *
 * Call AFTER the credential row has been deleted. Every step is best-effort and
 * isolated so a single failure can never abort the rest of the teardown.
 */
final class AccountTeardownService
{
    private array $config;
    private PDO $db;
    private OAuthTokenCache $tokenCache;
    private MailboxSyncService $sync;
    private ?UnreadCountCache $unread;

    private const GOOGLE_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function __construct(
        array $config,
        ?PDO $db = null,
        ?OAuthTokenCache $tokenCache = null,
        ?MailboxSyncService $sync = null,
        ?UnreadCountCache $unread = null
    ) {
        $this->config     = $config;
        $this->db         = $db ?? Database::getConnection($config);
        $this->tokenCache = $tokenCache ?? new OAuthTokenCache($config);
        $this->sync       = $sync ?? new MailboxSyncService($config, $this->db);

        $this->unread = $unread;
        if ($this->unread === null) {
            try {
                $this->unread = new UnreadCountCache($config);
            } catch (\Throwable $e) {
                $this->unread = null;
            }
        }
    }

    /**
     * Tear down all residual server-side state for a removed account.
     *
     * @param string      $primaryEmail Owner FlowOne login (webmail_*.primary_email / user_email).
     * @param string      $accountEmail Secondary mailbox address (oauth_email / account_email).
     * @param string|null $provider     'google' | 'microsoft' | null (null clears both caches).
     * @param array       $opts         Optional: ['refresh_token' => string] to also revoke at Google.
     *
     * @return array Summary of what was removed (for logging / CLI / tests).
     */
    public function purge(string $primaryEmail, string $accountEmail, ?string $provider = null, array $opts = []): array
    {
        $primaryEmail = strtolower(trim($primaryEmail));
        $accountEmail = strtolower(trim($accountEmail));

        $summary = [
            'primary_email'    => $primaryEmail,
            'account_email'    => $accountEmail,
            'sync_state_rows'  => 0,
            'identity_rows'    => 0,
            'token_cache'      => false,
            'unread_cache'     => false,
            'provider_revoked' => false,
        ];

        if ($primaryEmail === '' || $accountEmail === '') {
            return $summary;
        }

        // 1. Folder sync state - the cron's per-folder work queue for this
        //    mailbox. Removing it is what actually stops the recurring sync.
        $summary['sync_state_rows'] = $this->sync->deleteAccountState($primaryEmail, $accountEmail);

        // 2. Folder identity rows for the secondary mailbox. Guarded so we can
        //    never touch the primary account's own identity rows.
        if ($accountEmail !== $primaryEmail) {
            $summary['identity_rows'] = $this->deleteFolderIdentity($accountEmail);
        }

        // 3. Redis OAuth caches. invalidateToken drops the cached access token;
        //    clearRevoked drops a stale "needs reconsent" flag so a future
        //    re-add starts clean. Provider unknown -> clear both families.
        $providers = $provider ? [strtolower($provider)] : ['google', 'microsoft'];
        foreach ($providers as $p) {
            try {
                $this->tokenCache->invalidateToken($p, $primaryEmail, $accountEmail);
                $this->tokenCache->clearRevoked($p, $primaryEmail, $accountEmail);
                $summary['token_cache'] = true;
            } catch (\Throwable $e) {
                error_log('[AccountTeardownService] token cache clear (' . $p . '): ' . $e->getMessage());
            }
        }

        // 4. Per-user unread cache (rebuilt lazily on next read).
        if ($this->unread) {
            try {
                $summary['unread_cache'] = $this->unread->invalidate($primaryEmail);
            } catch (\Throwable $e) {
                error_log('[AccountTeardownService] unread cache invalidate: ' . $e->getMessage());
            }
        }

        // 5. OPTIONAL provider-side revoke (default OFF). Only when the caller
        //    supplies the refresh token (the DB row is already gone by now).
        $refresh = (string)($opts['refresh_token'] ?? '');
        if ($refresh !== '' && ($provider === null || strtolower($provider) === 'google')) {
            $summary['provider_revoked'] = $this->revokeGoogleToken($refresh);
        }

        return $summary;
    }

    /**
     * Delete folder-identity rows for a secondary mailbox. account_id stores
     * the mailbox address for linked accounts; for the primary mailbox it is
     * the user's own login (never reached - guarded by the caller).
     */
    private function deleteFolderIdentity(string $accountEmail): int
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM webmail_folder_identity WHERE LOWER(account_id) = ?');
            $stmt->execute([$accountEmail]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[AccountTeardownService] deleteFolderIdentity: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Best-effort refresh-token revoke at Google. Default OFF; the caller opts
     * in by passing the decrypted refresh token before it is destroyed.
     */
    private function revokeGoogleToken(string $refreshToken): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        try {
            $ch = curl_init(self::GOOGLE_REVOKE_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['token' => $refreshToken]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // Google returns 200 on success and 400 if the token is already
            // dead - both mean "no live grant remains", so treat 400 as done.
            return $code === 200 || $code === 400;
        } catch (\Throwable $e) {
            error_log('[AccountTeardownService] revokeGoogleToken: ' . $e->getMessage());
            return false;
        }
    }
}
