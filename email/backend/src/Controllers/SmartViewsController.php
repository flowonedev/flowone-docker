<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\SmartViewsService;

/**
 * REST endpoints for user-defined Smart Views.
 *
 * Routes (wired in routes.php):
 *   GET    /smart-views              → list
 *   POST   /smart-views              → create
 *   PUT    /smart-views/{id}         → update
 *   DELETE /smart-views/{id}         → delete
 *   PATCH  /smart-views/reorder      → reorder (body: { order: [id, id, …] })
 *
 * Execution lives client-side — the frontend reads the `query` string off a
 * Smart View and pipes it through useEmailSearchStore, which already knows
 * how to call /mailbox/search. This controller is pure CRUD.
 */
class SmartViewsController extends BaseController
{
    private ?SmartViewsService $service = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    private function getService(): SmartViewsService
    {
        if (!$this->service) {
            $this->service = new SmartViewsService($this->config);
        }
        return $this->service;
    }

    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        return Response::success([
            'smart_views' => $this->getService()->listForUser($email),
        ]);
    }

    public function create(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $body  = [
            'name'         => $request->input('name'),
            'icon'         => $request->input('icon', 'filter_alt'),
            'color'        => $request->input('color', 'primary'),
            'query'        => $request->input('query', ''),
            'filters_json' => $request->input('filters_json'),
            'scope'        => $request->input('scope', 'all'),
        ];

        try {
            $view = $this->getService()->create($email, $body);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\PDOException $e) {
            error_log('[SmartViewsController::create] ' . $e->getMessage());
            return Response::error('Failed to create Smart View', 500);
        }

        return Response::success(['smart_view' => $view]);
    }

    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $id    = (int)$request->getParam('id');
        if ($id <= 0) return Response::error('Invalid id', 400);

        $body = [
            'name'         => $request->input('name'),
            'icon'         => $request->input('icon'),
            'color'        => $request->input('color'),
            'query'        => $request->input('query'),
            'filters_json' => $request->input('filters_json'),
            'scope'        => $request->input('scope'),
        ];
        // Strip nulls so existing values survive partial updates.
        $body = array_filter($body, static fn($v) => $v !== null);

        try {
            $view = $this->getService()->update($email, $id, $body);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        }

        if (!$view) return Response::error('Smart View not found', 404);
        return Response::success(['smart_view' => $view]);
    }

    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $id    = (int)$request->getParam('id');
        if ($id <= 0) return Response::error('Invalid id', 400);

        $ok = $this->getService()->delete($email, $id);
        if (!$ok) return Response::error('Smart View not found', 404);
        return Response::success(['deleted' => true]);
    }

    public function reorder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $order = $request->input('order');
        if (!is_array($order)) return Response::error('order[] is required', 400);

        try {
            $this->getService()->reorder($email, $order);
        } catch (\Throwable $e) {
            error_log('[SmartViewsController::reorder] ' . $e->getMessage());
            return Response::error('Failed to reorder Smart Views', 500);
        }

        return Response::success([
            'smart_views' => $this->getService()->listForUser($email),
        ]);
    }
}
