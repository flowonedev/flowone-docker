<?php

namespace Webmail\Addons\BoardPro\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\BoardPro\Services\BoardProAutomationService;
use Webmail\Addons\KanbanBoards\Services\BoardService;

/**
 * BoardProAutomationController
 *
 * CRUD for board automation rules and execution log viewing.
 */
class BoardProAutomationController extends BaseController
{
    private ?BoardProAutomationService $automationService = null;
    private ?BoardService $boardService = null;

    private function getAutomationService(): BoardProAutomationService
    {
        if (!$this->automationService) {
            $this->automationService = new BoardProAutomationService($this->config);
        }
        return $this->automationService;
    }

    private function getBoardService(): BoardService
    {
        if (!$this->boardService) {
            $this->boardService = new BoardService($this->config);
        }
        return $this->boardService;
    }

    /**
     * GET /board-pro/boards/{id}/automations
     */
    public function getRules(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $rules = $this->getAutomationService()->getRules($boardId);

        return Response::json(['success' => true, 'data' => $rules]);
    }

    /**
     * POST /board-pro/boards/{id}/automations
     */
    public function createRule(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $userEmail = $this->getUser($request);
        $boardId = (int) $request->param('id');

        $required = $this->validateRequired($request, ['name', 'trigger_type', 'action_type']);
        if ($required) return $required;

        if (!$this->getBoardService()->hasAccess($userEmail, $boardId)) {
            return Response::error('Access denied', 403);
        }

        $rule = $this->getAutomationService()->createRule([
            'board_id' => $boardId,
            'user_email' => $userEmail,
            'name' => $request->input('name'),
            'is_active' => $request->input('is_active', 1),
            'trigger_type' => $request->input('trigger_type'),
            'trigger_config' => $request->input('trigger_config', []),
            'action_type' => $request->input('action_type'),
            'action_config' => $request->input('action_config', []),
        ]);

        return Response::json(['success' => true, 'data' => $rule], 201);
    }

    /**
     * PUT /board-pro/automations/{id}
     */
    public function updateRule(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int) $request->param('id');
        $rule = $this->getAutomationService()->updateRule($id, $request->input());

        if (!$rule) {
            return Response::error('Rule not found', 404);
        }

        return Response::json(['success' => true, 'data' => $rule]);
    }

    /**
     * DELETE /board-pro/automations/{id}
     */
    public function deleteRule(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int) $request->param('id');
        $this->getAutomationService()->deleteRule($id);

        return Response::json(['success' => true]);
    }

    /**
     * GET /board-pro/automations/{id}/log
     */
    public function getRuleLog(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $ruleId = (int) $request->param('id');
        $limit = (int) ($request->getQuery('limit', 50));
        $offset = (int) ($request->getQuery('offset', 0));

        $log = $this->getAutomationService()->getRuleLog($ruleId, $limit, $offset);

        return Response::json(['success' => true, 'data' => $log]);
    }

    /**
     * GET /board-pro/boards/{id}/automations/log
     * Board-level execution log across all rules
     */
    public function getBoardLog(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $boardId = (int) $request->param('id');
        $limit = (int) ($request->getQuery('limit', 100));
        $offset = (int) ($request->getQuery('offset', 0));

        $log = $this->getAutomationService()->getBoardLog($boardId, $limit, $offset);

        return Response::json(['success' => true, 'data' => $log]);
    }
}

