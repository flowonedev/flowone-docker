<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\GuestCallAttachmentService;
use Webmail\Services\GuestCallService;
use Webmail\Services\RateLimiter;

/**
 * GuestCallController - Public endpoints for guest video call access.
 *
 * Two types of endpoints:
 * 1. Public (no auth): Token validation and joining for external guests
 * 2. Internal (JWT auth): Token creation/management for CRM users
 */
class GuestCallController extends BaseController
{
    private ?GuestCallService $guestCallService = null;
    private ?GuestCallAttachmentService $attachmentService = null;

    private function getService(): GuestCallService
    {
        if (!$this->guestCallService) {
            $this->guestCallService = new GuestCallService($this->config);
        }
        return $this->guestCallService;
    }

    private function getAttachmentService(): GuestCallAttachmentService
    {
        if (!$this->attachmentService) {
            $this->attachmentService = new GuestCallAttachmentService($this->config);
        }
        return $this->attachmentService;
    }

    private function withGuestCallSecurityHeaders(Response $response): Response
    {
        return $response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * GET /guest/call/{token}/info
     * HEAD — same as GET without body (for health checks / bots).
     */
    public function getInfo(Request $request): Response
    {
        $token = $request->getParam('token');
        if (!$token) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token is required'], 400));
        }

        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $ipKey = 'guestcall:info:ip:' . hash('sha256', $ip);
        $r = $lim->allow($ipKey, 60, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }

        if (strtoupper($request->getMethod()) === 'HEAD') {
            return $this->withGuestCallSecurityHeaders(Response::raw('', 200, ['Content-Type' => 'application/json']));
        }

        $info = $this->getService()->getTokenInfo($token);
        if (!$info) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Invalid link'], 404));
        }

        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $info]));
    }

    /**
     * POST /guest/call/{token}/join
     */
    public function join(Request $request): Response
    {
        $token = $request->getParam('token');
        if (!$token) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token is required'], 400));
        }

        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $ipKey = 'guestcall:join:ip:' . hash('sha256', $ip);
        $tokKey = 'guestcall:join:tok:' . hash('sha256', $token);
        $rIp = $lim->allow($ipKey, 30, 60);
        if (!$rIp['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $rIp['retry_after']], 429)
            );
        }
        $rTok = $lim->allow($tokKey, 10, 60);
        if (!$rTok['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $rTok['retry_after']], 429)
            );
        }

        $body = $request->input();
        $guestName = trim($body['name'] ?? 'Guest');
        if ($guestName === '') {
            $guestName = 'Guest';
        }

        $ua = (string)($request->getHeader('USER-AGENT') ?? '');
        $result = $this->getService()->validateAndJoin($token, $guestName, $ip, $ua);

        if (isset($result['error'])) {
            $httpCode = match ($result['code'] ?? 'error') {
                'invalid' => 404,
                'expired' => 410,
                'max_uses' => 410,
                default => 500,
            };
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => $result['error']], $httpCode));
        }

        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $result]));
    }

    /**
     * GET /guest/call/{token}/admission/{id}
     */
    public function getAdmissionStatus(Request $request): Response
    {
        $token = $request->getParam('token');
        $id = (int)$request->getParam('id');
        if (!$token || !$id) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token and request id are required'], 400));
        }
        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:admissionpoll:ip:' . hash('sha256', $ip), 120, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }
        $row = $this->getService()->getAdmissionStatus($id, $token);
        if ($row === null) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Not found'], 404));
        }

        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $row]));
    }

    /**
     * POST /guest/call/admission/{id}/approve
     * Body: { "admin_token": "..." }
     */
    public function approveAdmission(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $adminToken = trim((string)($request->input('admin_token') ?? ''));
        if (!$id || $adminToken === '') {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'request id and admin_token are required'], 400));
        }
        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:admissionact:ip:' . hash('sha256', $ip), 60, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }
        $out = $this->getService()->approveAdmission($id, $adminToken);
        if (empty($out['success'])) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => $out['error'] ?? 'Failed'], 403));
        }

        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $out]));
    }

    /**
     * POST /guest/call/admission/{id}/deny
     * Body: { "admin_token": "..." }
     */
    public function denyAdmission(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $adminToken = trim((string)($request->input('admin_token') ?? ''));
        if (!$id || $adminToken === '') {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'request id and admin_token are required'], 400));
        }
        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:admissionact:ip:' . hash('sha256', $ip), 60, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }
        $out = $this->getService()->denyAdmission($id, $adminToken);
        if (empty($out['success'])) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => $out['error'] ?? 'Failed'], 403));
        }

        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $out]));
    }

    /**
     * GET /guest/call/lobby?admin_token=...
     */
    public function listAdmissionLobby(Request $request): Response
    {
        $adminToken = trim((string)($request->getQuery('admin_token') ?? ''));
        if ($adminToken === '') {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'admin_token is required'], 400));
        }
        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:lobby:ip:' . hash('sha256', $ip), 60, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }
        $rows = $this->getService()->listAdmissionLobby($adminToken);

        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $rows]));
    }

    /**
     * GET /guest/call/{token}/attendees
     * Admin-only. Returns the LiveKit-reported participant roster for the room.
     * Useful in workshop mode where guests are hidden from each other.
     */
    public function listAttendees(Request $request): Response
    {
        $adminToken = (string)$request->getParam('token');
        if ($adminToken === '') {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token is required'], 400));
        }
        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:attendees:ip:' . hash('sha256', $ip), 60, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }
        $out = $this->getService()->listRoomAttendees($adminToken);
        if (empty($out['success'])) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => $out['error'] ?? 'Failed'], 403));
        }
        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $out['participants'] ?? []]));
    }

    /**
     * POST /guest/call/{token}/kick
     * Body: { "identity": "<livekit identity to remove>" }
     * The {token} must be an admin token; that admin can only kick from its own room.
     */
    public function kickParticipant(Request $request): Response
    {
        $adminToken = (string)$request->getParam('token');
        if ($adminToken === '') {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token is required'], 400));
        }
        $identity = trim((string)($request->input('identity') ?? ''));
        if ($identity === '') {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'identity is required'], 400));
        }
        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:kick:ip:' . hash('sha256', $ip), 30, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }
        $out = $this->getService()->kickParticipantByIdentity($adminToken, $identity);
        if (empty($out['success'])) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => $out['error'] ?? 'Failed'], 403));
        }
        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true]));
    }

    /**
     * POST /guest/call/{token}/revoke-room
     * Admin-only "end this meeting for everyone": kicks all live participants and
     * revokes every active token tied to the same room.
     */
    public function revokeRoom(Request $request): Response
    {
        $adminToken = (string)$request->getParam('token');
        if ($adminToken === '') {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token is required'], 400));
        }
        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:revoke:ip:' . hash('sha256', $ip), 10, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }
        $out = $this->getService()->revokeRoomByAdminToken($adminToken);
        if (empty($out['success'])) {
            $code = !empty($out['unauthorized']) ? 403 : (!empty($out['not_found']) ? 404 : 500);
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => $out['error'] ?? 'Failed'], $code)
            );
        }
        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true]));
    }

    /**
     * POST /guest/call/{token}/transcript
     */
    public function saveTranscript(Request $request): Response
    {
        $token = $request->getParam('token');
        if (!$token) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token is required'], 400));
        }
        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:transcript:ip:' . hash('sha256', $ip), 20, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }

        $body = $request->input();
        $messages = $body['messages'] ?? [];
        $duration = (int)($body['duration'] ?? 0);

        if (empty($messages)) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['success' => true, 'data' => ['skipped' => true, 'reason' => 'No messages']])
            );
        }

        $result = $this->getService()->sendTranscript($token, $messages, $duration);

        if (isset($result['error'])) {
            // sendBeacon callers never read this response — log so failures are
            // diagnosable from php_errors.log after the fact.
            error_log('GuestCallController::saveTranscript failed: ' . $result['error']);
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => $result['error']], 500));
        }

        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $result]));
    }

    /**
     * POST /guest/call/{token}/attachments
     * Multipart upload of a single in-call chat attachment (field: "file").
     * Optional "uploaded_by" form field carries the sender's display name.
     * Any participant with a valid (non-expired) token for the room may upload.
     */
    public function uploadAttachment(Request $request): Response
    {
        $token = (string)$request->getParam('token');
        if ($token === '') {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token is required'], 400));
        }

        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $rIp = $lim->allow('guestcall:attupload:ip:' . hash('sha256', $ip), 20, 60);
        if (!$rIp['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many uploads', 'retry_after' => $rIp['retry_after']], 429)
            );
        }
        $rTok = $lim->allow('guestcall:attupload:tok:' . hash('sha256', $token), 40, 60);
        if (!$rTok['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many uploads', 'retry_after' => $rTok['retry_after']], 429)
            );
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || !isset($file['tmp_name']) || is_array($file['tmp_name'])) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'A single "file" upload field is required'], 400)
            );
        }

        $uploadedBy = trim((string)($_POST['uploaded_by'] ?? ''));
        $result = $this->getAttachmentService()->upload($token, $file, $uploadedBy);

        if (isset($result['error'])) {
            $httpCode = match ($result['code'] ?? 'error') {
                'invalid_token' => 410,
                'too_large', 'room_quota' => 413,
                'blocked_type' => 415,
                'no_file', 'empty_file', 'upload_error' => 400,
                default => 500,
            };
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => $result['error']], $httpCode));
        }

        return $this->withGuestCallSecurityHeaders(Response::json(['success' => true, 'data' => $result['attachment']]));
    }

    /**
     * GET /guest/call/{token}/attachments/{id}
     * Streams an attachment. The token must be valid and belong to the same
     * room as the attachment (each participant uses their OWN token).
     */
    public function downloadAttachment(Request $request): Response
    {
        $token = (string)$request->getParam('token');
        $id = (int)$request->getParam('id');
        if ($token === '' || !$id) {
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => 'Token and attachment id are required'], 400));
        }

        $lim = new RateLimiter($this->config);
        $ip = $request->getClientIp();
        $r = $lim->allow('guestcall:attdownload:ip:' . hash('sha256', $ip), 120, 60);
        if (!$r['allowed']) {
            return $this->withGuestCallSecurityHeaders(
                Response::json(['error' => 'Too many requests', 'retry_after' => $r['retry_after']], 429)
            );
        }

        $result = $this->getAttachmentService()->resolveForDownload($token, $id);
        if (isset($result['error'])) {
            $httpCode = ($result['code'] ?? '') === 'invalid_token' ? 410 : 404;
            return $this->withGuestCallSecurityHeaders(Response::json(['error' => $result['error']], $httpCode));
        }

        header('Content-Type: ' . $result['mime']);
        header('Content-Length: ' . $result['size']);
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        $disposition = str_starts_with((string)$result['mime'], 'image/') ? 'inline' : 'attachment';
        header($this->safeContentDisposition($disposition, $result['name']));

        set_time_limit(30);
        readfile($result['path']);
        exit;
    }

    /**
     * POST /clients/{id}/portal/calls/{callId}/transcript
     */
    public function resendTranscript(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $callId = (int)$request->getParam('callId');
        if (!$callId) {
            return Response::json(['error' => 'Call ID is required'], 400);
        }

        $result = $this->getService()->resendTranscript($callId, $this->userEmail);

        if (isset($result['error'])) {
            return Response::json(['error' => $result['error']], $result['code'] ?? 400);
        }

        return Response::json(['success' => true, 'data' => $result]);
    }

    /**
     * POST /clients/{id}/portal/calls/{callId}/guest-link
     */
    public function createGuestLink(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $clientId = (int)$request->getParam('id');
        $callId = (int)$request->getParam('callId');

        $db = \Webmail\Core\Database::getConnection($this->config);
        $stmt = $db->prepare('SELECT * FROM portal_calls WHERE id = ? AND client_id = ?');
        $stmt->execute([$callId, $clientId]);
        $call = $stmt->fetch();

        if (!$call) {
            return Response::json(['error' => 'Call not found'], 404);
        }

        $body = $request->input();
        $ttlHours = (int)($body['ttl_hours'] ?? 24);
        $maxUses = (int)($body['max_uses'] ?? 0);
        $role = in_array($body['role'] ?? 'guest', ['admin', 'guest']) ? $body['role'] : 'guest';

        $result = $this->getService()->createToken(
            $callId,
            $call['room_name'],
            $clientId,
            $this->userEmail,
            $ttlHours,
            $maxUses,
            $role
        );

        return Response::json([
            'success' => true,
            'data' => $result,
            'message' => 'Guest call link created. Share it with the client.',
        ]);
    }

    /**
     * POST /chat/guest-call-link
     */
    public function createChatGuestLink(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $body = $request->input();
        $ttlHours = (int)($body['ttl_hours'] ?? 24);
        $waiting = (bool)($body['waiting_room'] ?? false);
        $hidden = (bool)($body['participants_hidden'] ?? false);

        $result = $this->getService()->createStandaloneGuestToken(
            $this->userEmail,
            $ttlHours,
            $waiting,
            $hidden
        );

        return Response::json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * GET /chat/guest-call-links
     */
    public function listChatGuestLinks(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $links = $this->getService()->listUserLinks($this->userEmail);

        return Response::json([
            'success' => true,
            'data' => $links,
        ]);
    }

    /**
     * DELETE /chat/guest-call-links/{token}
     */
    public function revokeChatGuestLink(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $token = $request->getParam('token');
        if (!$token) {
            return Response::json(['error' => 'Token is required'], 400);
        }

        $revoked = $this->getService()->revokeToken($token);

        return Response::json([
            'success' => true,
            'data' => ['revoked' => $revoked],
        ]);
    }
}
