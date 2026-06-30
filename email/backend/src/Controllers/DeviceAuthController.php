<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Core\Database;
use Webmail\Controllers\Concerns\SsoSupportTrait;

/**
 * DeviceAuthController — device authorization ("scan to sign in").
 *
 * A desktop app (e.g. FlowOne Drive) starts an ANONYMOUS request and shows a QR
 * + a 2-digit match number. An already-signed-in web session opens the approval
 * page, taps the matching number, and the request is bound to that user. The
 * desktop polls (holding a poll_secret) and, once approved, receives a one-time
 * sso_codes code which it redeems through the existing SSOController::exchange().
 *
 * Split out of SSOController so each controller owns one flow (seed/code/clone
 * vs. device-authorization). Shared low-level helpers live in SsoSupportTrait.
 */
class DeviceAuthController extends BaseController
{
    use SsoSupportTrait;

    /** Device request lifetime, seconds. Long enough to reach for another device. */
    private const REQUEST_TTL = 300;

    /** Wrong number-match guesses allowed before a request is auto-denied. */
    private const MAX_ATTEMPTS = 3;

    /** Max pending requests per target email (or IP) within the rate window. */
    private const RATE_MAX = 5;

    /** Rate-limit window, seconds. */
    private const RATE_WINDOW = 60;

    private string $serverKey;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->serverKey = $config['sso']['server_key'] ?? '';
        if (empty($this->serverKey)) {
            error_log('[SSO] WARNING: sso.server_key not configured. Device login will reject requests.');
        }
    }

    /**
     * POST /api/sso/device/start (unauthenticated, TLS enforced)
     * Creates an anonymous request and returns a request_id (the scannable
     * capability), a poll_secret (kept private by the device), a 2-digit match
     * number to display, and the verification URL to encode in the QR.
     */
    public function deviceStart(Request $request): Response
    {
        $tlsError = $this->requireTLS($request);
        if ($tlsError) return $tlsError;

        if (empty($this->serverKey)) {
            return Response::error('SSO not configured', 503);
        }

        $db = Database::getConnection($this->config);

        // Probabilistic cleanup of stale rows (10% of starts).
        if (random_int(1, 10) === 1) {
            try {
                $db->exec("DELETE FROM sso_device_requests WHERE expires_at < NOW() OR (status = 'consumed' AND consumed_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))");
            } catch (\Throwable $e) {
                // cleanup is best-effort
            }
        }

        $requestId = $this->generateUuid();
        $pollSecret = $this->generateSecureToken(32);
        $pollSecretHmac = hash_hmac('sha256', $pollSecret, $this->serverKey);

        // Match number + two distinct decoys (all 00-99).
        $matchNumber = random_int(0, 99);
        $decoyA = $matchNumber;
        while ($decoyA === $matchNumber) { $decoyA = random_int(0, 99); }
        $decoyB = $matchNumber;
        while ($decoyB === $matchNumber || $decoyB === $decoyA) { $decoyB = random_int(0, 99); }

        $deviceLabel = $this->sanitizeDeviceLabel(
            (string)($request->input('device_label') ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Desktop app'))
        );
        $ip = $request->getClientIp();

        // Optional: the new device names the account it wants to sign in to so
        // that account's already-signed-in sessions can DISCOVER this request
        // (via /sso/device/pending) and show an approval modal automatically.
        $targetEmail = $this->normalizeTargetEmail($request->input('email'));

        // Refuse outright if this IP was blocked for this account by the user.
        if ($targetEmail !== null && $this->isIpBlocked($db, $ip, $targetEmail)) {
            error_log("[SSO] device_start blocked ip={$ip} target={$targetEmail}");
            return Response::json(['error' => 'DEVICE_BLOCKED'], 403);
        }

        // Throttle so nobody can spam approval prompts at an account. Keyed on the
        // target email only (not IP) so a whole office behind one NAT isn't locked
        // out. Anonymous QR-only starts (no target) notify no one, so aren't capped.
        if ($targetEmail !== null && $this->startRateExceeded($db, $targetEmail)) {
            return Response::json(['error' => 'DEVICE_RATE_LIMITED'], 429);
        }

        // expires_at is computed on the DB clock (DATE_ADD(NOW(), ...)) so it is
        // always consistent with the NOW()/UNIX_TIMESTAMP comparisons used when
        // reading it back. Computing it in PHP would break whenever PHP's
        // timezone differs from MySQL's (it did: PHP=UTC, MySQL=UTC+2), which made
        // every request look already-expired to /pending and the cleanup sweep.
        $stmt = $db->prepare('INSERT INTO sso_device_requests
            (request_id, poll_secret_hmac, match_number, decoy_a, decoy_b, device_label, ip_address, target_email, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
        $stmt->execute([$requestId, $pollSecretHmac, $matchNumber, $decoyA, $decoyB, $deviceLabel, $ip, $targetEmail, self::REQUEST_TTL]);

        error_log("[SSO] device_start request={$requestId} ip={$ip} target=" . ($targetEmail ?? '-'));

        return Response::success([
            'request_id'   => $requestId,
            'poll_secret'  => $pollSecret,
            'match_number' => $matchNumber,
            'verify_url'   => $this->selfOrigin() . '/link-device?req=' . $requestId,
            'expires_in'   => self::REQUEST_TTL,
        ]);
    }

    /**
     * GET /api/sso/device/info?req=... (authenticated)
     * Returns the device details and the three candidate numbers (correct + two
     * decoys, shuffled). Never reveals which is correct.
     */
    public function deviceInfo(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $requestId = (string)($request->getQuery('req') ?? '');
        if (!$this->isUuid($requestId)) {
            return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 400);
        }

        $db = Database::getConnection($this->config);
        $stmt = $db->prepare('SELECT *, UNIX_TIMESTAMP(expires_at) AS expires_ts FROM sso_device_requests WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 404);
        }
        if ($row['status'] !== 'pending') {
            return Response::success(['status' => $row['status']]);
        }
        if ((int)$row['expires_ts'] < time()) {
            return Response::success(['status' => 'expired']);
        }

        $numbers = [(int)$row['match_number'], (int)$row['decoy_a'], (int)$row['decoy_b']];
        shuffle($numbers);

        return Response::success([
            'status'       => 'pending',
            'device_label' => $row['device_label'],
            'ip_address'   => $row['ip_address'],
            'created_at'   => $row['created_at'],
            'numbers'      => $numbers,
        ]);
    }

    /**
     * GET /api/sso/device/pending (authenticated)
     * Returns the pending device-sign-in requests TARGETED at the signed-in
     * user's account. Each already-signed-in session polls this and pops an
     * approval modal automatically, so the user never has to scan the QR.
     * Each entry carries the device label, IP, and three shuffled numbers
     * (correct + two decoys) — never which one is correct.
     */
    public function devicePending(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) return Response::unauthorized();

        $db = Database::getConnection($this->config);
        $stmt = $db->prepare("SELECT request_id, device_label, ip_address, match_number, decoy_a, decoy_b, created_at
            FROM sso_device_requests
            WHERE target_email = ? AND status = 'pending' AND expires_at > NOW()
            ORDER BY created_at ASC
            LIMIT 5");
        $stmt->execute([strtolower(trim($email))]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $requests = [];
        foreach ($rows as $row) {
            $numbers = [(int)$row['match_number'], (int)$row['decoy_a'], (int)$row['decoy_b']];
            shuffle($numbers);
            $requests[] = [
                'request_id'   => $row['request_id'],
                'device_label' => $row['device_label'],
                'ip_address'   => $row['ip_address'],
                'created_at'   => $row['created_at'],
                'numbers'      => $numbers,
            ];
        }

        return Response::success(['requests' => $requests]);
    }

    /**
     * POST /api/sso/device/approve (authenticated)
     * Validates the tapped number, binds the request to the approver, and mints
     * a one-time sso_codes code the device will exchange.
     */
    public function deviceApprove(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) return Response::unauthorized();

        if (empty($this->serverKey)) {
            return Response::error('SSO not configured', 503);
        }

        $requestId = (string)($request->input('request_id') ?? '');
        $number = $request->input('number');
        if (!$this->isUuid($requestId) || !is_numeric($number)) {
            return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 400);
        }
        $number = (int)$number;

        $db = Database::getConnection($this->config);
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT *, UNIX_TIMESTAMP(expires_at) AS expires_ts FROM sso_device_requests WHERE request_id = ? FOR UPDATE');
            $stmt->execute([$requestId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $db->rollBack();
                return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 404);
            }
            // If the request named a target account, only that account may approve
            // it. (QR-only requests have no target_email and stay open to any
            // signed-in approver, preserving the original scan flow.)
            if (!empty($row['target_email']) && strtolower(trim($row['target_email'])) !== strtolower(trim($email))) {
                $db->rollBack();
                return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 404);
            }
            if ($row['status'] !== 'pending') {
                $db->rollBack();
                return Response::json(['error' => 'DEVICE_REQUEST_NOT_PENDING'], 409);
            }
            if ((int)$row['expires_ts'] < time()) {
                $db->rollBack();
                return Response::json(['error' => 'DEVICE_REQUEST_EXPIRED'], 410);
            }

            // Wrong number: count the attempt, auto-deny once the cap is hit.
            if ($number !== (int)$row['match_number']) {
                $attempts = (int)$row['approve_attempts'] + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $upd = $db->prepare("UPDATE sso_device_requests SET approve_attempts = ?, status = 'denied' WHERE request_id = ?");
                    $upd->execute([$attempts, $requestId]);
                    $db->commit();
                    error_log("[SSO] device_approve_denied request={$requestId} reason=too_many_attempts user={$email}");
                    return Response::json(['error' => 'DEVICE_NUMBER_MISMATCH', 'attempts_left' => 0], 401);
                }
                $upd = $db->prepare('UPDATE sso_device_requests SET approve_attempts = ? WHERE request_id = ?');
                $upd->execute([$attempts, $requestId]);
                $db->commit();
                return Response::json([
                    'error' => 'DEVICE_NUMBER_MISMATCH',
                    'attempts_left' => self::MAX_ATTEMPTS - $attempts,
                ], 401);
            }

            // Correct: mint a one-time code (redeemed via SSOController::exchange).
            // expires_at MUST be on the DB clock: the redemption path sweeps
            // `DELETE FROM sso_codes WHERE expires_at < NOW()`, so a PHP-clock
            // value (UTC, behind MySQL's UTC+2) would be born already-expired and
            // could be deleted before the device redeems it — the intermittent
            // "works on the second try" sign-in failure.
            $code = $this->generateCode(12);
            $nonce = $this->generateCode(12);
            $insCode = $db->prepare('INSERT INTO sso_codes (code, nonce, user_email, expires_at)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
            $insCode->execute([$code, $nonce, $email, self::REQUEST_TTL]);

            $upd = $db->prepare("UPDATE sso_device_requests SET status = 'approved', user_email = ?, code = ?, approved_at = NOW() WHERE request_id = ?");
            $upd->execute([$email, $code, $requestId]);

            $db->commit();
            error_log("[SSO] device_approved request={$requestId} user={$email}");

            return Response::success(['status' => 'approved']);
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("[SSO] device_approve_failed request={$requestId} error={$e->getMessage()}");
            return Response::error('Approval failed, please retry', 500);
        }
    }

    /**
     * POST /api/sso/device/deny (authenticated)
     * The approver rejects the request. Idempotent for non-pending rows.
     */
    public function deviceDeny(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $requestId = (string)($request->input('request_id') ?? '');
        if (!$this->isUuid($requestId)) {
            return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 400);
        }

        $db = Database::getConnection($this->config);
        $stmt = $db->prepare("UPDATE sso_device_requests SET status = 'denied' WHERE request_id = ? AND status = 'pending'");
        $stmt->execute([$requestId]);

        error_log("[SSO] device_denied request={$requestId}");
        return Response::success(['status' => 'denied']);
    }

    /**
     * POST /api/sso/device/block (authenticated)
     * The user marks the attempt as an intruder: deny the request AND block the
     * originating IP from any future device sign-in attempts targeted at this
     * account. Scoped per (ip, account) so it can never lock other users out.
     */
    public function deviceBlock(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) return Response::unauthorized();
        $email = strtolower(trim($email));

        $requestId = (string)($request->input('request_id') ?? '');
        if (!$this->isUuid($requestId)) {
            return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 400);
        }

        $db = Database::getConnection($this->config);
        $stmt = $db->prepare('SELECT ip_address, target_email FROM sso_device_requests WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 404);
        }

        // Only the targeted account may block its own request's IP.
        if (!empty($row['target_email']) && strtolower(trim($row['target_email'])) !== $email) {
            return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 404);
        }

        $ip = (string)($row['ip_address'] ?? '');

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE sso_device_requests SET status = 'denied' WHERE request_id = ? AND status = 'pending'")
               ->execute([$requestId]);
            if ($ip !== '') {
                $ins = $db->prepare('INSERT INTO sso_device_blocked_ips (ip_address, target_email, blocked_by, reason)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE created_at = NOW(), blocked_by = VALUES(blocked_by)');
                $ins->execute([$ip, $email, $email, 'blocked from approval prompt']);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("[SSO] device_block_failed request={$requestId} error={$e->getMessage()}");
            return Response::error('Block failed, please retry', 500);
        }

        error_log("[SSO] device_blocked request={$requestId} ip={$ip} account={$email}");
        return Response::success(['status' => 'blocked', 'ip_address' => $ip]);
    }

    /**
     * POST /api/sso/device/poll (unauthenticated, TLS enforced)
     * The device polls with its poll_secret. While pending it gets {status};
     * once approved it receives the one-time code exactly once (then the row is
     * consumed). The poll_secret check stops a bystander from harvesting tokens.
     */
    public function devicePoll(Request $request): Response
    {
        $tlsError = $this->requireTLS($request);
        if ($tlsError) return $tlsError;

        if (empty($this->serverKey)) {
            return Response::error('SSO not configured', 503);
        }

        $requestId = (string)($request->input('request_id') ?? '');
        $pollSecret = (string)($request->input('poll_secret') ?? '');
        if (!$this->isUuid($requestId) || $pollSecret === '') {
            return Response::json(['error' => 'DEVICE_POLL_INVALID'], 400);
        }

        $db = Database::getConnection($this->config);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT *, UNIX_TIMESTAMP(expires_at) AS expires_ts FROM sso_device_requests WHERE request_id = ? FOR UPDATE');
            $stmt->execute([$requestId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $db->rollBack();
                return Response::json(['error' => 'DEVICE_REQUEST_INVALID'], 404);
            }

            // Verify the device's secret before revealing anything.
            $expected = hash_hmac('sha256', $pollSecret, $this->serverKey);
            if (!hash_equals($expected, $row['poll_secret_hmac'])) {
                $db->rollBack();
                return Response::json(['error' => 'DEVICE_POLL_INVALID'], 401);
            }

            $status = $row['status'];

            if ($status === 'approved') {
                $code = $row['code'];
                $consume = $db->prepare("UPDATE sso_device_requests SET status = 'consumed', consumed_at = NOW() WHERE request_id = ?");
                $consume->execute([$requestId]);
                $db->commit();
                return Response::success(['status' => 'approved', 'code' => $code]);
            }

            $db->commit();

            if ($status === 'pending' && (int)$row['expires_ts'] < time()) {
                return Response::success(['status' => 'expired']);
            }

            return Response::success(['status' => $status]);
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("[SSO] device_poll_failed request={$requestId} error={$e->getMessage()}");
            return Response::error('Poll failed, please retry', 500);
        }
    }

    /** Validate + normalise the optional target email; null if absent/invalid. */
    private function normalizeTargetEmail($value): ?string
    {
        $email = strtolower(trim((string)($value ?? '')));
        if ($email === '') {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            return null;
        }
        return $email;
    }

    /** True when this IP has been blocked for this account's device sign-ins. */
    private function isIpBlocked(\PDO $db, string $ip, string $targetEmail): bool
    {
        if ($ip === '') return false;
        try {
            $stmt = $db->prepare('SELECT 1 FROM sso_device_blocked_ips WHERE ip_address = ? AND target_email = ? LIMIT 1');
            $stmt->execute([$ip, $targetEmail]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // If the block table isn't available, fail open (don't break logins).
            return false;
        }
    }

    /** True when too many pending requests already target this email in the window. */
    private function startRateExceeded(\PDO $db, string $targetEmail): bool
    {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM sso_device_requests
                WHERE status = 'pending'
                  AND target_email = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$targetEmail, self::RATE_WINDOW]);
            return (int)$stmt->fetchColumn() >= self::RATE_MAX;
        } catch (\Throwable $e) {
            // Rate-limit checks are best-effort; never block a legit start on error.
            return false;
        }
    }
}
