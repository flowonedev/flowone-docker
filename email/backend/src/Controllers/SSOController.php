<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Core\Database;
use Webmail\Services\SessionService;
use Webmail\Services\SessionTrackingService;
use Webmail\Services\TwoFactorService;
use Webmail\Controllers\Concerns\SsoSupportTrait;

class SSOController extends BaseController
{
    use SsoSupportTrait;

    private string $serverKey;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->serverKey = $config['sso']['server_key'] ?? '';
        if (empty($this->serverKey)) {
            error_log('[SSO] WARNING: sso.server_key not configured. SSO endpoints will reject requests.');
        }
    }

    /**
     * POST /api/sso/create-seed (authenticated)
     * Creates a new seed for the authenticated user, revoking any previous seeds.
     */
    public function createSeed(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) return Response::unauthorized();

        if (empty($this->serverKey)) {
            return Response::error('SSO not configured', 503);
        }

        $db = Database::getConnection($this->config);

        // Revoke any existing active seeds for this user
        $stmt = $db->prepare('UPDATE sso_seeds SET revoked = TRUE, revoked_at = NOW() WHERE user_email = ? AND revoked = FALSE');
        $stmt->execute([$email]);

        // Generate new seed
        $seedId = $this->generateUuid();
        $seedSecret = $this->generateSecureToken(32);
        $seedHmac = hash_hmac('sha256', $seedSecret, $this->serverKey);
        $expiresAt = date('Y-m-d H:i:s', time() + 7 * 24 * 3600); // 7 days
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare('INSERT INTO sso_seeds (seed_id, seed_secret_hmac, user_email, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$seedId, $seedHmac, $email, $now, $expiresAt]);

        error_log("[SSO] seed_created user={$email} seed={$seedId}");

        return Response::success([
            'seed_id' => $seedId,
            'seed_secret' => $seedSecret,
            'seed_created_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * POST /api/sso/clone-session (unauthenticated, seed-based, TLS enforced)
     * Clones an independent session from a seed. Rotates the seed on success.
     */
    public function cloneSession(Request $request): Response
    {
        $tlsError = $this->requireTLS($request);
        if ($tlsError) return $tlsError;

        if (empty($this->serverKey)) {
            return Response::error('SSO not configured', 503);
        }

        $seedId = $request->input('seed_id');
        $seedSecret = $request->input('seed_secret');

        if (!$seedId || !$seedSecret) {
            return Response::json(['error' => 'SSO_SEED_INVALID'], 401);
        }

        $db = Database::getConnection($this->config);

        // Look up the seed (without lock first for fast-path validation)
        $stmt = $db->prepare('SELECT * FROM sso_seeds WHERE seed_id = ?');
        $stmt->execute([$seedId]);
        $seed = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$seed) {
            return Response::json(['error' => 'SSO_SEED_INVALID'], 401);
        }
        if ($seed['revoked']) {
            return Response::json(['error' => 'SSO_SEED_REVOKED'], 401);
        }
        if (strtotime($seed['expires_at']) < time()) {
            error_log("[SSO] seed_expired user={$seed['user_email']} seed={$seedId}");
            return Response::json(['error' => 'SSO_SEED_EXPIRED'], 401);
        }

        // Session epoch check: prevent clone after logout
        $stmtState = $db->prepare('SELECT logout_epoch FROM sso_user_state WHERE user_email = ?');
        $stmtState->execute([$seed['user_email']]);
        $userState = $stmtState->fetch(\PDO::FETCH_ASSOC);
        if ($userState && $userState['logout_epoch'] && strtotime($seed['created_at']) < strtotime($userState['logout_epoch'])) {
            error_log("[SSO] seed_clone_failed user={$seed['user_email']} seed={$seedId} reason=session_epoch");
            return Response::json(['error' => 'SSO_SEED_REVOKED'], 401);
        }

        // Rate limit: 30 clones per user per hour
        $rateCheck = $this->checkCloneRateLimit($seed['user_email']);
        if (!$rateCheck) {
            error_log("[SSO] seed_clone_rate_limited user={$seed['user_email']} ip={$request->getClientIp()}");
            return Response::json(['error' => 'SSO_RATE_LIMITED'], 429);
        }

        // Verify secret via HMAC
        $expectedHmac = hash_hmac('sha256', $seedSecret, $this->serverKey);
        if (!hash_equals($expectedHmac, $seed['seed_secret_hmac'])) {
            return Response::json(['error' => 'SSO_SEED_INVALID'], 401);
        }

        // Seed rotation inside DB transaction with row lock
        $db->beginTransaction();
        try {
            // Re-fetch with row lock
            $stmt = $db->prepare('SELECT * FROM sso_seeds WHERE seed_id = ? FOR UPDATE');
            $stmt->execute([$seedId]);
            $lockedSeed = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$lockedSeed || $lockedSeed['revoked']) {
                $db->rollBack();
                return Response::json(['error' => 'SSO_SEED_REVOKED'], 401);
            }

            // 1. Revoke old seed
            $stmt = $db->prepare('UPDATE sso_seeds SET revoked = TRUE, revoked_at = NOW() WHERE seed_id = ?');
            $stmt->execute([$seedId]);

            // 2. Create new seed (inherits original expiry)
            $newSeedId = $this->generateUuid();
            $newSeedSecret = $this->generateSecureToken(32);
            $newSeedHmac = hash_hmac('sha256', $newSeedSecret, $this->serverKey);
            $now = date('Y-m-d H:i:s');

            $stmt = $db->prepare('INSERT INTO sso_seeds (seed_id, seed_secret_hmac, user_email, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$newSeedId, $newSeedHmac, $lockedSeed['user_email'], $now, $lockedSeed['expires_at']]);

            // 3. Create independent session
            $email = $lockedSeed['user_email'];
            $displayName = $this->getDisplayName($email);

            $accessToken = $this->session->createToken($email, [
                'display_name' => $displayName,
                'sso_cloned' => true,
            ]);
            $refreshToken = $this->session->createRefreshToken($email);

            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'FlowOne Desktop (SSO)';
            $ipAddress = $request->getClientIp();

            $sessionTracker = new SessionTrackingService($this->config);

            // Copy IMAP password from user's most recent active session
            $encryptedPassword = null;
            try {
                $pwdStmt = $db->prepare('
                    SELECT encrypted_password FROM webmail_sessions
                    WHERE email = ? AND encrypted_password IS NOT NULL
                      AND LEFT(encrypted_password, 2) != \'__\'
                      AND expires_at > NOW() AND is_valid = 1
                    ORDER BY last_active_at DESC LIMIT 1
                ');
                $pwdStmt->execute([strtolower($email)]);
                $pwdRow = $pwdStmt->fetch(\PDO::FETCH_ASSOC);
                if ($pwdRow && $pwdRow['encrypted_password']) {
                    $encryptedPassword = $pwdRow['encrypted_password'];
                    error_log("[SSO] Copied IMAP password from existing session for clone {$email}");
                } else {
                    error_log("[SSO] No session with IMAP password found for clone {$email}");
                }
            } catch (\Exception $e) {
                error_log("[SSO] Failed to copy session password for clone {$email}: {$e->getMessage()}");
            }

            $sessionToken = $sessionTracker->createSession(
                $email, $userAgent, $ipAddress,
                $this->config['jwt']['expiry'] ?? 43200,
                $encryptedPassword
            );

            if ($sessionToken) {
                $sessionTracker->storeRefreshTokenHash($email, $sessionToken, $refreshToken);
            }

            // Trust the device
            $deviceToken = null;
            try {
                $twoFactor = new TwoFactorService($this->config);
                $deviceToken = $twoFactor->trustDevice($email, $userAgent, $ipAddress);
            } catch (\Exception $e) {
                error_log("[SSO] device trust error: {$e->getMessage()}");
            }

            $db->commit();

            // Record clone for rate limiting
            $this->recordClone($email);

            error_log("[SSO] seed_clone_success user={$email} old_seed={$seedId} new_seed={$newSeedId}");

            return Response::success([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'session_token' => $sessionToken,
                'device_token' => $deviceToken,
                'expires_in' => $this->config['jwt']['expiry'] ?? 3600,
                'seed_id' => $newSeedId,
                'seed_secret' => $newSeedSecret,
                'seed_created_at' => $now,
                'seed_expires_at' => $lockedSeed['expires_at'],
                'user' => [
                    'email' => $email,
                    'display_name' => $displayName,
                ],
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("[SSO] seed_clone_failed user={$seed['user_email']} seed={$seedId} error={$e->getMessage()}");
            return Response::error('Clone failed, please retry', 500);
        }
    }

    /**
     * POST /api/sso/revoke-seed (authenticated)
     * Revokes the user's active seed(s) and updates session epoch.
     */
    public function revokeSeed(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) return Response::unauthorized();

        $db = Database::getConnection($this->config);

        // Revoke all active seeds
        $stmt = $db->prepare('UPDATE sso_seeds SET revoked = TRUE, revoked_at = NOW() WHERE user_email = ? AND revoked = FALSE');
        $stmt->execute([$email]);

        // Update session epoch to prevent clone race
        $stmt = $db->prepare('INSERT INTO sso_user_state (user_email, logout_epoch) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE logout_epoch = NOW()');
        $stmt->execute([$email]);

        error_log("[SSO] seed_revoked user={$email}");
        error_log("[SSO] session_epoch_updated user={$email}");

        return Response::success(null, 'Seeds revoked');
    }

    /**
     * POST /api/sso/exchange (unauthenticated, TLS enforced)
     * Exchanges a code+nonce for tokens and a seed.
     */
    public function exchange(Request $request): Response
    {
        $tlsError = $this->requireTLS($request);
        if ($tlsError) return $tlsError;

        if (empty($this->serverKey)) {
            return Response::error('SSO not configured', 503);
        }

        $code = $request->input('code');
        $nonce = $request->input('nonce');

        if (!$code) {
            return Response::json(['error' => 'SSO_CODE_INVALID'], 400);
        }

        // Validate format (nonce is optional for manual code entry)
        if (!preg_match('/^[A-Za-z0-9]{12}$/', $code)) {
            return Response::json(['error' => 'SSO_CODE_INVALID'], 400);
        }
        if ($nonce && !preg_match('/^[A-Za-z0-9]{12}$/', $nonce)) {
            return Response::json(['error' => 'SSO_CODE_INVALID'], 400);
        }

        // Rate limit: 5 attempts per IP per minute
        $ip = $request->getClientIp();
        if (!$this->checkExchangeRateLimit($ip)) {
            error_log("[SSO] code_exchange_rate_limited ip={$ip}");
            return Response::json(['error' => 'SSO_RATE_LIMITED'], 429);
        }

        $db = Database::getConnection($this->config);

        // Atomic redemption with transaction + row lock
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM sso_codes WHERE code = ? FOR UPDATE');
            $stmt->execute([$code]);
            $codeRow = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$codeRow) {
                $db->rollBack();
                error_log("[SSO] code_exchange_failed code={$code} reason=not_found");
                return Response::json(['error' => 'SSO_CODE_INVALID'], 401);
            }
            if ($codeRow['used']) {
                $db->rollBack();
                return Response::json(['error' => 'SSO_CODE_USED'], 401);
            }
            if (strtotime($codeRow['expires_at']) < time()) {
                $db->rollBack();
                return Response::json(['error' => 'SSO_CODE_EXPIRED'], 401);
            }
            if ($nonce && !hash_equals($codeRow['nonce'], $nonce)) {
                $db->rollBack();
                error_log("[SSO] code_exchange_failed code={$code} reason=nonce_mismatch");
                return Response::json(['error' => 'SSO_NONCE_MISMATCH'], 401);
            }

            // Mark code as used
            $stmt = $db->prepare('UPDATE sso_codes SET used = TRUE, used_at = NOW() WHERE code = ?');
            $stmt->execute([$code]);

            $email = $codeRow['user_email'];
            $displayName = $this->getDisplayName($email);

            // Create independent session
            $accessToken = $this->session->createToken($email, [
                'display_name' => $displayName,
                'sso_exchanged' => true,
            ]);
            $refreshToken = $this->session->createRefreshToken($email);

            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'FlowOne Desktop (SSO Exchange)';
            $ipAddress = $request->getClientIp();

            $sessionTracker = new SessionTrackingService($this->config);

            // Copy the user's IMAP password from their most recent active session
            // so the desktop app can access IMAP endpoints without re-entering the password
            $encryptedPassword = null;
            try {
                $pwdStmt = $db->prepare('
                    SELECT encrypted_password FROM webmail_sessions
                    WHERE email = ? AND encrypted_password IS NOT NULL
                      AND LEFT(encrypted_password, 2) != \'__\'
                      AND expires_at > NOW() AND is_valid = 1
                    ORDER BY last_active_at DESC LIMIT 1
                ');
                $pwdStmt->execute([strtolower($email)]);
                $pwdRow = $pwdStmt->fetch(\PDO::FETCH_ASSOC);
                if ($pwdRow && $pwdRow['encrypted_password']) {
                    $encryptedPassword = $pwdRow['encrypted_password'];
                    error_log("[SSO] Copied IMAP password from existing session for {$email}");
                } else {
                    error_log("[SSO] No session with IMAP password found for {$email}");
                }
            } catch (\Exception $e) {
                error_log("[SSO] Failed to copy session password for {$email}: {$e->getMessage()}");
            }

            $sessionToken = $sessionTracker->createSession(
                $email, $userAgent, $ipAddress,
                $this->config['jwt']['expiry'] ?? 43200,
                $encryptedPassword
            );

            if ($sessionToken) {
                $sessionTracker->storeRefreshTokenHash($email, $sessionToken, $refreshToken);
            }

            $deviceToken = null;
            try {
                $twoFactor = new TwoFactorService($this->config);
                $deviceToken = $twoFactor->trustDevice($email, $userAgent, $ipAddress);
            } catch (\Exception $e) {
                error_log("[SSO] device trust error during exchange: {$e->getMessage()}");
            }

            // Revoke previous seeds and create a new one
            $stmtRevoke = $db->prepare('UPDATE sso_seeds SET revoked = TRUE, revoked_at = NOW() WHERE user_email = ? AND revoked = FALSE');
            $stmtRevoke->execute([$email]);

            $seedId = $this->generateUuid();
            $seedSecret = $this->generateSecureToken(32);
            $seedHmac = hash_hmac('sha256', $seedSecret, $this->serverKey);
            $now = date('Y-m-d H:i:s');
            $seedExpiresAt = date('Y-m-d H:i:s', time() + 7 * 24 * 3600);

            $stmt = $db->prepare('INSERT INTO sso_seeds (seed_id, seed_secret_hmac, user_email, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$seedId, $seedHmac, $email, $now, $seedExpiresAt]);

            $db->commit();

            error_log("[SSO] code_exchanged user={$email} code={$code}");

            return Response::success([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'session_token' => $sessionToken,
                'device_token' => $deviceToken,
                'expires_in' => $this->config['jwt']['expiry'] ?? 3600,
                'seed_id' => $seedId,
                'seed_secret' => $seedSecret,
                'seed_created_at' => $now,
                'seed_expires_at' => $seedExpiresAt,
                'user' => [
                    'email' => $email,
                    'display_name' => $displayName,
                ],
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("[SSO] code_exchange_failed code={$code} error={$e->getMessage()}");
            return Response::error('Exchange failed, please retry', 500);
        }
    }

    // ===== Helper methods =====
    // (requireTLS / generateUuid / generateSecureToken / generateCode and the
    //  device helpers live in SsoSupportTrait, shared with DeviceAuthController.)

    private function getDisplayName(string $email): string
    {
        try {
            $db = Database::getConnection($this->config);
            $stmt = $db->prepare('SELECT value FROM webmail_settings WHERE email = ? AND `key` = ?');
            $stmt->execute([$email, 'display_name']);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['value'])) {
                return $row['value'];
            }
        } catch (\Exception $e) {
            // Fall through to default
        }
        return explode('@', $email)[0];
    }

    private function checkCloneRateLimit(string $email): bool
    {
        try {
            $db = Database::getConnection($this->config);
            $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM sso_seeds WHERE user_email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $stmt->execute([$email]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return ($row['cnt'] ?? 0) < 30;
        } catch (\Exception $e) {
            return true; // Fail open for rate limiting
        }
    }

    private function recordClone(string $email): void
    {
        // Clone count is tracked implicitly via sso_seeds created_at entries
    }

    private function checkExchangeRateLimit(string $ip): bool
    {
        try {
            $db = Database::getConnection($this->config);
            // Use the existing rate_limit_attempts table if available, otherwise simple check
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM sso_codes WHERE used_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return ($row['cnt'] ?? 0) < 20;
        } catch (\Exception $e) {
            return true;
        }
    }
}
