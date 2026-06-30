<?php

declare(strict_types=1);

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Job queue inspection + control endpoints.
 *
 * Operators use these to watch the asynchronous provisioning pipeline:
 *   - list/filter the queue (queued vs running vs failed)
 *   - drill into a single job (steps, events, payload)
 *   - tail events for live progress (intended target for SSE later)
 *   - cancel a queued job, retry a failed one
 *
 * All endpoints are thin transports over the agent's `provisioning.*`
 * action namespace. Authorization is enforced at the panel side: any
 * authenticated admin can read the queue; only super-admins can cancel
 * or retry jobs that affect sites outside their assignment.
 */
class JobsController extends BaseController
{
    /**
     * GET /api/jobs
     *
     * Query: ?status= &type= &domain= &actor_user_id= &page= &per_page=
     */
    public function index(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $params = [
            'page' => (int) $request->getQuery('page', 1),
            'per_page' => (int) $request->getQuery('per_page', 50),
        ];
        foreach (['status', 'type', 'domain', 'actor_user_id'] as $f) {
            $v = $request->getQuery($f);
            if ($v !== null && $v !== '') {
                $params[$f] = (string) $v;
            }
        }

        // Non-super-admin users: clamp the listing to their own jobs by
        // default. Super admins get the global view.
        if (!$this->isSuperAdmin()) {
            $user = $this->getCurrentUser();
            if ($user && isset($user->sub)) {
                $params['actor_user_id'] = (string) $user->sub;
            }
        }

        $result = $this->callAgent('provisioning.listJobs', $params);
        return $this->respondFromAgent($result);
    }

    /**
     * GET /api/jobs/{id}
     */
    public function show(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $id = (int) $request->getParam('id');
        if ($id <= 0) {
            return Response::validationError(['id' => 'numeric id is required']);
        }

        $result = $this->callAgent('provisioning.getJob', ['id' => $id]);

        // Per-site RBAC: if the job's domain is not in the user's allowed
        // set, treat it as 404 (don't leak existence to non-owners).
        if (($result['success'] ?? false) && !$this->isSuperAdmin()) {
            $domain = $result['data']['job']['site_domain'] ?? null;
            if (is_string($domain) && !$this->canAccessSite($domain)) {
                return Response::notFound("Job {$id} not found");
            }
        }

        return $this->respondFromAgent($result);
    }

    /**
     * GET /api/jobs/{id}/events?since_id=&limit=
     *
     * Lightweight tail for polling-based progress UIs. Returns the events
     * after $since_id (0 = from the beginning) and the current job
     * status so a polling loop can stop once `job_terminal` is true.
     */
    public function events(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $id = (int) $request->getParam('id');
        if ($id <= 0) {
            return Response::validationError(['id' => 'numeric id is required']);
        }

        $params = [
            'id' => $id,
            'since_id' => max(0, (int) $request->getQuery('since_id', 0)),
            'limit' => max(1, (int) $request->getQuery('limit', 50)),
        ];

        $result = $this->callAgent('provisioning.getJobEvents', $params);
        return $this->respondFromAgent($result);
    }

    /**
     * POST /api/jobs/{id}/cancel
     *
     * Body: { "reason": "free text" } (optional)
     */
    public function cancel(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $id = (int) $request->getParam('id');
        if ($id <= 0) {
            return Response::validationError(['id' => 'numeric id is required']);
        }

        $params = $this->stampActor($request, [
            'id' => $id,
            'reason' => $this->trimReason($request->input('reason'), 'cancelled via UI'),
        ]);

        $result = $this->callAgent('provisioning.cancelJob', $params);
        return $this->respondFromAgent($result);
    }

    /**
     * POST /api/jobs/{id}/retry
     *
     * Body: { "reason": "free text" } (optional)
     */
    public function retry(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $id = (int) $request->getParam('id');
        if ($id <= 0) {
            return Response::validationError(['id' => 'numeric id is required']);
        }

        $params = $this->stampActor($request, [
            'id' => $id,
            'reason' => $this->trimReason($request->input('reason'), 'retry via UI'),
        ]);

        $result = $this->callAgent('provisioning.retryJob', $params);
        return $this->respondFromAgent($result, defaultStatus: 202);
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function stampActor(Request $request, array $base): array
    {
        $user = $this->getCurrentUser();
        $extra = [
            'actor_username' => $user->username ?? 'api',
            'actor_user_id' => $user->sub ?? null,
            'source_ip' => $request->getClientIp(),
            'user_agent' => substr($request->getUserAgent(), 0, 255),
            'request_id' => $this->resolveRequestId($request),
        ];
        return array_filter(
            array_merge($base, $extra),
            static fn($v) => $v !== null && $v !== ''
        );
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = $request->getHeader('X-Request-Id')
            ?? $request->getHeader('X-Correlation-Id');
        if (is_string($incoming) && $incoming !== '') {
            return substr($incoming, 0, 64);
        }
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return uniqid('req_', true);
        }
    }

    private function trimReason(mixed $reason, string $default): string
    {
        if (!is_string($reason)) {
            return $default;
        }
        $r = trim($reason);
        if ($r === '') {
            return $default;
        }
        return substr($r, 0, 255);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function respondFromAgent(array $result, int $defaultStatus = 200): Response
    {
        if ($result['success'] ?? false) {
            return Response::json([
                'success' => true,
                'message' => $result['message'] ?? 'Success',
                'data' => $result['data'] ?? null,
            ], $defaultStatus);
        }

        $details = $result['data'] ?? [];
        $code = is_array($details) ? (string) ($details['code'] ?? '') : '';
        $status = match ($code) {
            'not_found' => 404,
            'invalid_state' => 409,
            'forbidden' => 403,
            default => 400,
        };

        $body = [
            'success' => false,
            'error' => $result['error'] ?? 'Agent rejected the request',
        ];
        if (is_array($details) && $details !== []) {
            $body['details'] = $details;
        }
        return Response::json($body, $status);
    }
}
