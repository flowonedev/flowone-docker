<?php

declare(strict_types=1);

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\Simulation\PreflightChecker;
use Webmail\Services\Simulation\TestSimulationService;

final class TestSimulationController extends BaseController
{
    private function gate(Request $request): ?Response
    {
        // Order per plan §4: (a) ENABLED, (b) requireAuth, (c) domain allowlist.
        // Kill switch comes first so a disabled feature never reads request state at all.
        if (!TestSimulationService::ENABLED) {
            return Response::json([
                'success' => false,
                'reason' => 'feature_disabled',
                'message' => 'Test simulation is disabled',
            ], 503);
        }
        $auth = $this->requireAuth($request);
        if ($auth) {
            return $auth;
        }
        $email = strtolower((string) $this->getUser($request));
        try {
            (new TestSimulationService($this->config))->assertAllowedDomain($email);
        } catch (\RuntimeException) {
            return Response::forbidden('Domain not allowed for test simulation');
        }
        return null;
    }

    public function preflight(Request $request): Response
    {
        $g = $this->gate($request);
        if ($g) {
            return $g;
        }
        $email = strtolower((string) $this->getUser($request));
        $pf = (new PreflightChecker($this->config))->check($email);
        return Response::success($pf);
    }

    public function generate(Request $request): Response
    {
        $g = $this->gate($request);
        if ($g) {
            return $g;
        }
        $email = strtolower((string) $this->getUser($request));
        $body = $request->input('promote_admin') ?? $request->input('promoteAdmin');
        $promote = $body === true || $body === 1 || $body === '1' || $body === 'true';
        $svc = new TestSimulationService($this->config);
        try {
            $summary = $svc->generateRun($email, $promote);
            return Response::success($summary);
        } catch (\RuntimeException $e) {
            $m = $e->getMessage();
            if ($m === 'REQUIRES_ADMIN_PROMOTION') {
                return Response::json([
                    'success' => false,
                    'reason' => 'requires_admin_promotion',
                    'message' => 'Admin rights required for workload views; confirm promote_admin to continue.',
                ], 428);
            }
            if ($m === 'LOCK_FAILED') {
                return Response::json([
                    'success' => false,
                    'reason' => 'lock_failed',
                    'message' => 'Another simulation run is in progress for this account.',
                ], 409);
            }
            if (str_starts_with($m, 'PREFLIGHT:')) {
                $missing = json_decode(substr($m, strlen('PREFLIGHT:')), true);
                return Response::json([
                    'success' => false,
                    'reason' => 'preflight_failed',
                    'missing' => $missing,
                ], 400);
            }
            return Response::serverError($m);
        }
    }

    public function listRuns(Request $request): Response
    {
        $g = $this->gate($request);
        if ($g) {
            return $g;
        }
        $email = strtolower((string) $this->getUser($request));
        $rows = (new TestSimulationService($this->config))->listRuns($email);
        return Response::success(['runs' => $rows]);
    }

    public function deleteRun(Request $request): Response
    {
        $g = $this->gate($request);
        if ($g) {
            return $g;
        }
        $email = strtolower((string) $this->getUser($request));
        $runId = (string) ($request->param('runId') ?? $request->param('id') ?? '');
        if ($runId === '') {
            return Response::badRequest('runId required');
        }
        try {
            $out = (new TestSimulationService($this->config))->deleteRun($email, $runId);
            return Response::success($out);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'FORBIDDEN_OWNER' || $e->getMessage() === 'RUN_NOT_FOUND') {
                return Response::forbidden('Not allowed to delete this run');
            }
            throw $e;
        }
    }

    public function deleteAll(Request $request): Response
    {
        $g = $this->gate($request);
        if ($g) {
            return $g;
        }
        $email = strtolower((string) $this->getUser($request));
        $out = (new TestSimulationService($this->config))->deleteAllRuns($email);
        return Response::success($out);
    }
}
