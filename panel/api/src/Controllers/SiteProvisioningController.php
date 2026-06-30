<?php

declare(strict_types=1);

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Asynchronous site provisioning endpoints (v2).
 *
 * The legacy synchronous flow that lived in SiteController::create()
 * suffered a ~50% success rate because every step ran inline behind a
 * long-lived agent socket call. Network blips, OLS reload races, and
 * partial step failures all dropped the whole request. Phase 5 of the
 * V2 consolidation deleted that path entirely; this controller and
 * the saga worker are now the only way to provision and tear down
 * sites.
 *
 * This v2 controller is a thin transport over the agent's
 * `provisioning.*` action namespace. It only:
 *   - validates the incoming HTTP shape,
 *   - enriches the actor context with JWT user id + request IP,
 *   - calls the agent, and
 *   - returns the enqueued job descriptor.
 *
 * All state machine writes, audit entries, and saga orchestration happen
 * in the agent process (and ultimately the WorkerDaemon). The HTTP
 * request finishes in milliseconds with a job id; the caller polls
 * `GET /api/jobs/{id}` (see {@see JobsController}) to watch progress.
 *
 * Route ordering note: routes are registered under `/api/sites/v2/...`
 * deliberately. The bare `/api/sites` routes continue to point at the
 * legacy SiteController during migration so existing UI keeps working.
 */
class SiteProvisioningController extends BaseController
{
    /**
     * POST /api/sites/v2
     *
     * Body:
     *   {
     *     "domain": "example.com",                  // required
     *     "payload": {                              // forwarded to the saga
     *       "php_version": "lsphp83",
     *       "home_dir": "/home/example.com",       // optional, default /home/<domain>
     *       "sftp_user": "example",                 // optional, derived from domain
     *       "sftp_group": "example",                // optional, derived from domain
     *       "db_name": "example_db",                // optional
     *       "db_user": "example_user",              // optional
     *       "db_password": "...",                   // optional, vault-ref recommended
     *       "ssl_enabled": true                     // optional
     *     },
     *     "priority": 50,                           // optional, 0..255
     *     "max_attempts": 3,                        // optional
     *     "dry_run": false                          // optional
     *   }
     *
     * Response: 202 Accepted with the job summary. Body:
     *   {
     *     "success": true,
     *     "message": "CREATE job enqueued",
     *     "data": {
     *       "job":  { id, status: "queued", site_domain, ... },
     *       "site": { id, domain, actual_state: "provisioning", ... },
     *       "duplicate": false
     *     }
     *   }
     */
    public function create(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $validation = $this->validateRequired($request, ['domain']);
        if ($validation !== null) {
            return $validation;
        }

        $domain = trim((string) $request->input('domain'));
        if ($domain === '') {
            return Response::validationError(['domain' => 'domain is required']);
        }

        // A CREATE that targets an EXISTING site row is effectively a
        // mutation of that site (re-provision / resurrect), so the
        // per-site allowlist applies. A brand-new domain passes: it
        // cannot be on anyone's allowlist yet, and admins must be able
        // to create fresh sites.
        if (!$this->canAccessSite($domain)) {
            $existing = $this->callAgent('provisioning.getSite', ['domain' => $domain]);
            if (!empty($existing['success'])) {
                return Response::forbidden('Access denied to this site');
            }
        }

        $params = $this->buildAgentParams($request, [
            'domain' => $domain,
            'payload' => is_array($request->input('payload')) ? $request->input('payload') : [],
            'priority' => $request->input('priority'),
            'max_attempts' => $request->input('max_attempts'),
            'dry_run' => (bool) $request->input('dry_run', false),
        ]);

        $result = $this->callAgent('provisioning.enqueueCreate', $params);
        // Bust the V2-backed /api/sites dropdown cache so the new
        // site appears in dropdowns immediately (in provisioning
        // state) instead of waiting for the cache TTL to expire. The
        // create saga also provisions a DB, so refresh `db:list` too.
        $this->cache->invalidateSites();
        $this->cache->invalidateDatabases();
        return $this->respondFromAgent($result, defaultStatus: 202);
    }

    /**
     * DELETE /api/sites/v2/{domain}
     *
     * Body (all optional):
     *   {
     *     "payload": {
     *       "skip_snapshot": false,         // skip PreDeleteSnapshotStep entirely
     *       "skip_db_snapshot": false,      // keep tarball but skip mysqldump
     *       "skip_home_snapshot": false,    // keep mysqldump but skip tarball
     *       "snapshot_root": "/var/backups/flowone-deletes"
     *     },
     *     "priority": 50,
     *     "max_attempts": 3,
     *     "dry_run": false
     *   }
     */
    public function delete(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $domain = (string) $request->getParam('domain');
        if ($domain === '') {
            return Response::validationError(['domain' => 'domain is required']);
        }

        if (!$this->canAccessSite($domain)) {
            return Response::forbidden('Access denied to this site');
        }

        $params = $this->buildAgentParams($request, [
            'domain' => $domain,
            'payload' => is_array($request->input('payload')) ? $request->input('payload') : [],
            'priority' => $request->input('priority'),
            'max_attempts' => $request->input('max_attempts'),
            'dry_run' => (bool) $request->input('dry_run', false),
        ]);

        $result = $this->callAgent('provisioning.enqueueDelete', $params);
        // Bust the V2-backed /api/sites dropdown cache so the deleted
        // site disappears from dropdowns immediately instead of
        // waiting for the cache TTL to expire. Also bust the database
        // list: the DELETE saga drops the site's DB, so a stale
        // `db:list` would keep showing the dropped schema for up to the
        // cache TTL (the "rogue database still listed" symptom).
        $this->cache->invalidateForDomain($domain);
        $this->cache->invalidateSites();
        $this->cache->invalidateDatabases();
        return $this->respondFromAgent($result, defaultStatus: 202);
    }

    /**
     * POST /api/sites/v2/{domain}/suspend
     *
     * Body (all optional):
     *   {
     *     "payload": {
     *       "suspend_message": "Maintenance window in progress"
     *     },
     *     "priority": 50,
     *     "max_attempts": 3
     *   }
     *
     * Site must currently be in 'active' or 'degraded'. The vhost is
     * swapped for a 503-only config and the row transitions to
     * 'suspended' on success.
     */
    public function suspend(Request $request): Response
    {
        return $this->lifecycleAction(
            $request,
            agentMethod: 'provisioning.enqueueSuspend',
            denyMessage: 'Access denied to this site',
        );
    }

    /**
     * POST /api/sites/v2/{domain}/resume
     *
     * Body: same shape as suspend (payload usually empty).
     *
     * Site must currently be in 'suspended'. The saga restores the
     * original vhost.conf from the suspended-backup and reloads OLS.
     */
    public function resume(Request $request): Response
    {
        return $this->lifecycleAction(
            $request,
            agentMethod: 'provisioning.enqueueResume',
            denyMessage: 'Access denied to this site',
        );
    }

    /**
     * POST /api/sites/v2/{domain}/archive
     *
     * Body (all optional):
     *   {
     *     "payload": {
     *       "snapshot_root": "/var/backups/flowone",
     *       "archive_root": "/var/archive/flowone",
     *       "snapshot_timeout_seconds": 900,
     *       "archive_copy_timeout_seconds": 1800
     *     },
     *     "priority": 50
     *   }
     *
     * Captures a mandatory snapshot, promotes it to the archive
     * store, and tears down the live infrastructure. The sites row
     * transitions to 'archived' on success (or 'degraded' on
     * partial failure).
     */
    public function archive(Request $request): Response
    {
        return $this->lifecycleAction(
            $request,
            agentMethod: 'provisioning.enqueueArchive',
            denyMessage: 'Access denied to this site',
        );
    }

    /**
     * POST /api/sites/v2/{domain}/restore
     *
     * Body (REQUIRED archive_path):
     *   {
     *     "payload": {
     *       "archive_path": "/var/archive/flowone/example.com/20260520-XXX",
     *       "home_dir": "/home/example.com",
     *       "db_name": "example_db",
     *       "skip_db_hydrate": false,
     *       "skip_home_hydrate": false
     *     },
     *     "priority": 50
     *   }
     *
     * Site must currently be in 'archived'. The saga re-lays the
     * infrastructure and hydrates from the archived snapshot.
     */
    public function restore(Request $request): Response
    {
        return $this->lifecycleAction(
            $request,
            agentMethod: 'provisioning.enqueueRestore',
            denyMessage: 'Access denied to this site',
        );
    }

    /**
     * POST /api/sites/v2/{domain}/purge
     *
     * Hard-delete a tombstone (a sites row whose lifecycle is in
     * actual_state='absent'). Removes the sites row, all history
     * tables (audit, jobs, events, step executions), and the
     * snapshot directory on disk.
     *
     * Requires admin. Refuses to operate on live sites - those must
     * go through the DELETE saga first, this endpoint does NOT
     * shortcut the snapshot pipeline.
     *
     * Body (all optional):
     *   {
     *     "dry_run": false   // when true, returns row counts only
     *   }
     *
     * Response: 200 OK on success with:
     *   {
     *     "success": true,
     *     "data": {
     *       "domain": "test.com",
     *       "rows_deleted": { ... },
     *       "snapshot_dir": "/var/www/vps-admin/storage/snapshots/test.com",
     *       "snapshot_removed": true,
     *       "snapshot_error": null
     *     }
     *   }
     */
    public function purgeTombstone(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $domain = (string) $request->getParam('domain');
        if ($domain === '') {
            return Response::validationError(['domain' => 'domain is required']);
        }
        if (!$this->canAccessSite($domain)) {
            return Response::forbidden('Access denied to this site');
        }

        $params = $this->buildAgentParams($request, [
            'domain' => $domain,
            'dry_run' => (bool) $request->input('dry_run', false),
        ]);

        $result = $this->callAgent('provisioning.purgeTombstone', $params);
        return $this->respondFromAgent($result, defaultStatus: 200);
    }

    /**
     * GET /api/sites/v2/archives
     * GET /api/sites/v2/{domain}/archives
     *
     * List the archives on disk under the archive root. When a domain
     * is in the path, the result is scoped to that site so the
     * restore picker can show only the archives that match.
     *
     * Query: ?limit=25
     *
     * Response shape (see ProvisioningAction::actionListArchives):
     *   {
     *     "root": "/var/www/vps-admin/storage/archives",
     *     "domain": "example.com" | null,
     *     "archives": [
     *       {
     *         "path": "...",
     *         "domain": "...",
     *         "name": "20260520-080000-job123",
     *         "archived_at": "2026-05-20T08:00:00+00:00",
     *         "archived_at_unix": 1779609600,
     *         "job_id": 123,
     *         "size_bytes": 1048576,
     *         "mtime_unix": 1779609600
     *       }
     *     ],
     *     "count": 1,
     *     "partial": false
     *   }
     *
     * Read-only. Admin-gated because archives can contain plaintext
     * dumps; non-admin users have no business inspecting them.
     */
    public function listArchives(Request $request): Response
    {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $domain = (string) $request->getParam('domain', '');
        $params = [
            'limit' => (int) $request->getQuery('limit', 25),
        ];
        if ($domain !== '') {
            if (!$this->canAccessSite($domain)) {
                return Response::forbidden('Access denied to this site');
            }
            $params['domain'] = $domain;
        }

        $result = $this->callAgent('provisioning.listArchives', $params);
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list archives');
        }

        $data = $result['data'] ?? ['archives' => [], 'count' => 0];

        // RBAC filter when no specific domain was requested: drop
        // archives whose domain is outside the caller's allowlist.
        if ($domain === '' && isset($data['archives']) && is_array($data['archives'])) {
            $allowed = $this->getAllowedSites();
            if ($allowed !== null) {
                $allowedSet = array_flip($allowed);
                $data['archives'] = array_values(array_filter(
                    $data['archives'],
                    static fn(array $a) => isset($a['domain']) && isset($allowedSet[(string) $a['domain']])
                ));
                $data['count'] = count($data['archives']);
            }
        }

        return Response::success($data);
    }

    /**
     * Shared body for SUSPEND/RESUME/ARCHIVE/RESTORE. The only thing
     * that differs is which agent method is called.
     */
    private function lifecycleAction(
        Request $request,
        string $agentMethod,
        string $denyMessage,
    ): Response {
        $authError = $this->requireAdmin();
        if ($authError !== null) {
            return $authError;
        }

        $domain = (string) $request->getParam('domain');
        if ($domain === '') {
            return Response::validationError(['domain' => 'domain is required']);
        }
        if (!$this->canAccessSite($domain)) {
            return Response::forbidden($denyMessage);
        }

        $params = $this->buildAgentParams($request, [
            'domain' => $domain,
            'payload' => is_array($request->input('payload')) ? $request->input('payload') : [],
            'priority' => $request->input('priority'),
            'max_attempts' => $request->input('max_attempts'),
            'dry_run' => (bool) $request->input('dry_run', false),
        ]);

        $result = $this->callAgent($agentMethod, $params);
        return $this->respondFromAgent($result, defaultStatus: 202);
    }

    /**
     * GET /api/sites/v2
     *
     * Query: ?actual_state= &desired_state= &search= &page= &per_page=
     */
    public function index(Request $request): Response
    {
        $params = [
            'page' => (int) $request->getQuery('page', 1),
            'per_page' => (int) $request->getQuery('per_page', 50),
        ];
        foreach (['actual_state', 'desired_state', 'search'] as $f) {
            $v = $request->getQuery($f);
            if ($v !== null && $v !== '') {
                $params[$f] = (string) $v;
            }
        }

        $result = $this->callAgent('provisioning.listSites', $params);
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list sites');
        }

        $data = $result['data'] ?? ['sites' => []];

        // RBAC filter for non-super-admins: only return sites they own.
        $allowed = $this->getAllowedSites();
        if ($allowed !== null && isset($data['sites']) && is_array($data['sites'])) {
            $allowedSet = array_flip($allowed);
            $data['sites'] = array_values(array_filter(
                $data['sites'],
                static fn(array $s) => isset($s['domain']) && isset($allowedSet[(string) $s['domain']])
            ));
            if (isset($data['pagination']['total'])) {
                $data['pagination']['filtered_total'] = count($data['sites']);
            }
        }

        return Response::success($data);
    }

    /**
     * GET /api/sites/v2/{domain}
     */
    public function show(Request $request): Response
    {
        $domain = (string) $request->getParam('domain');
        if ($domain === '') {
            return Response::validationError(['domain' => 'domain is required']);
        }
        if (!$this->canAccessSite($domain)) {
            return Response::forbidden('Access denied to this site');
        }

        $result = $this->callAgent('provisioning.getSite', ['domain' => $domain]);
        return $this->respondFromAgent($result);
    }

    /**
     * Build the params payload passed to the agent. Strips nulls, then
     * stamps the actor identity from the authenticated session and the
     * propagated request id from the headers (or a freshly minted one if
     * the upstream proxy did not set one).
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function buildAgentParams(Request $request, array $base): array
    {
        $user = $this->getCurrentUser();
        $extra = [
            'actor_username' => $user->username ?? 'api',
            'actor_user_id' => $user->sub ?? null,
            'source_ip' => $request->getClientIp(),
            'user_agent' => substr($request->getUserAgent(), 0, 255),
            'request_id' => $this->resolveRequestId($request),
        ];

        $merged = array_merge($base, $extra);
        // Strip nulls / empties so the agent receives a clean payload.
        return array_filter(
            $merged,
            static fn($v) => $v !== null && $v !== ''
        );
    }

    /**
     * The reverse proxy (OLS) is expected to set X-Request-Id. If missing,
     * mint a 32-hex correlation token so every downstream log line can
     * still be joined.
     */
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

    /**
     * Translate the agent's standard envelope into an HTTP response while
     * preserving structured error data the UI may want to render.
     *
     * @param array<string, mixed> $result
     */
    private function respondFromAgent(array $result, int $defaultStatus = 200): Response
    {
        if ($result['success'] ?? false) {
            $body = [
                'success' => true,
                'message' => $result['message'] ?? 'Success',
                'data' => $result['data'] ?? null,
            ];
            return Response::json($body, $defaultStatus);
        }

        // Map well-known agent error codes to HTTP statuses so the UI
        // can branch on response.status, not on a substring of the
        // message.
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
