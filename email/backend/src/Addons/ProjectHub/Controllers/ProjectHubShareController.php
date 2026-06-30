<?php

declare(strict_types=1);

namespace Webmail\Addons\ProjectHub\Controllers;

use Webmail\Addons\ProjectHub\Services\ProjectHubShareService;
use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\DriveService;
use Webmail\Services\RateLimiter;

class ProjectHubShareController extends BaseController
{
    private ?ProjectHubShareService $shareService = null;
    private ?DriveService $driveService = null;
    private ?RateLimiter $rateLimiter = null;

    private function getShareService(): ProjectHubShareService
    {
        if (!$this->shareService) {
            $this->shareService = new ProjectHubShareService($this->config);
        }

        return $this->shareService;
    }

    private function getDriveService(): DriveService
    {
        if (!$this->driveService) {
            $this->driveService = new DriveService($this->config);
        }

        return $this->driveService;
    }

    private function getRateLimiter(): RateLimiter
    {
        if (!$this->rateLimiter) {
            $this->rateLimiter = new RateLimiter($this->config);
        }

        return $this->rateLimiter;
    }

    /** POST /project-hub/cards/{id}/shares */
    public function createShare(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) {
            return $auth;
        }

        $cardId = (int) $request->param('id');
        $ids = $request->input('drive_file_ids');
        if (!is_array($ids) || $ids === []) {
            return Response::error('drive_file_ids array required', 400);
        }

        try {
            $share = $this->getShareService()->createCardShare(
                $cardId,
                $this->getActiveEmail(),
                $ids,
                [
                    'title' => $request->input('title'),
                    'message' => $request->input('message'),
                    'expires_at' => $request->input('expires_at'),
                    'max_downloads' => $request->input('max_downloads'),
                    'password' => $request->input('password'),
                ]
            );
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Forbidden' ? 403 : 400;

            return Response::error($e->getMessage(), $code);
        }

        return Response::json(['success' => true, 'share' => $share], 201);
    }

    /** GET /project-hub/cards/{id}/shares */
    public function listShares(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) {
            return $auth;
        }
        $cardId = (int) $request->param('id');
        $rows = $this->getShareService()->listSharesForCard($cardId, $this->getActiveEmail());

        return Response::json(['shares' => $rows]);
    }

    /** DELETE /project-hub/shares/{id} */
    public function deleteShare(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) {
            return $auth;
        }
        $id = (int) $request->param('id');
        $ok = $this->getShareService()->revokeShare($id, $this->getActiveEmail());
        if (!$ok) {
            return Response::error('Not found', 404);
        }

        return Response::json(['success' => true]);
    }

    /** GET /project-hub/share/{token}/info (public) */
    public function publicShareInfo(Request $request): Response
    {
        $token = (string) $request->param('token');
        $svc = $this->getShareService();
        $state = $svc->classifyPublicToken($token);
        if ($state === 'missing') {
            return Response::json(['success' => false, 'error' => 'not_found'], 404);
        }
        if ($state === 'locked') {
            return Response::json(['success' => false, 'error' => 'locked'], 423);
        }
        if ($state === 'revoked' || $state === 'expired') {
            return Response::json(['success' => false, 'error' => $state], 410);
        }

        $payload = $svc->getPublicSharePayload($token, true);
        if (!$payload) {
            return Response::json(['success' => false, 'error' => 'unavailable'], 410);
        }

        return Response::json(['success' => true, 'data' => $payload]);
    }

    /** POST /project-hub/share/{token}/validate (public) */
    public function publicShareValidate(Request $request): Response
    {
        $token = (string) $request->param('token');
        $body = $request->input() ?: [];
        $password = (string) ($body['password'] ?? '');
        $ip = $request->getClientIp();

        $res = $this->getShareService()->validatePasswordWithRateLimit($token, $password, $ip, $this->getRateLimiter());
        if ($res['http'] === 429) {
            return Response::json(['success' => false, 'error' => 'rate_limited', 'retry_after' => $res['retry_after'] ?? 60], 429);
        }
        if ($res['http'] === 423) {
            return Response::json(['success' => false, 'error' => 'locked'], 423);
        }
        if ($res['http'] === 404) {
            return Response::json(['success' => false, 'error' => 'not_found'], 404);
        }
        if (!$res['ok']) {
            return Response::json(['success' => false, 'error' => 'invalid_password'], 403);
        }

        return Response::json(['success' => true]);
    }

    /** GET /project-hub/share/{token}/download/{fid} (public, streams binary) */
    public function publicShareDownload(Request $request): void
    {
        $token = (string) $request->param('token');
        $fid = (int) $request->param('fid');
        $pwd = $_GET['p'] ?? $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? null;

        $result = $this->getShareService()->tryAuthorizeShareDownload($token, $fid, $pwd, $this->getDriveService());
        if (!$result['ok']) {
            http_response_code((int) $result['http']);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $result['error']]);

            exit;
        }

        $auth = $result['data'];
        $share = $auth['share'];
        $path = $auth['path'];
        $mime = $auth['mime'];
        $name = $auth['original_name'];

        try {
            $this->getShareService()->recordDownload((int) $share['id'], $fid);
        } catch (\Throwable $e) {
            http_response_code(410);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'download_not_allowed']);

            exit;
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        $isImage = str_starts_with($mime, 'image/');
        $wantsPreview = !empty($_GET['preview']);
        $isPreviewable = in_array($mime, [
            'application/pdf',
            'text/plain',
            'text/html',
            'text/csv',
            'application/json',
        ], true);
        $disposition = ($isImage || ($wantsPreview && $isPreviewable)) ? 'inline' : 'attachment';

        header('Content-Type: ' . $mime);
        header($this->safeContentDisposition($disposition, basename($name)));
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }
}
