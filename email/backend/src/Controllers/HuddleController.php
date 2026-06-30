<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\HuddleService;

class HuddleController extends BaseController
{
    private HuddleService $huddleService;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $db = \Webmail\Core\Database::getConnection($config);
        $this->huddleService = new HuddleService($db);
    }

    private function requireHuddleAuth(Request $request): ?Response
    {
        return $this->requireAuth($request);
    }

    /**
     * POST /chat/huddles/start - Start or join a huddle in a conversation
     */
    public function start(Request $request): Response
    {
        if ($error = $this->requireHuddleAuth($request)) return $error;

        $body = $request->input();
        $conversationId = (int)($body['conversation_id'] ?? 0);

        if (!$conversationId) {
            return Response::json(['success' => false, 'error' => 'conversation_id is required'], 400);
        }

        $result = $this->huddleService->startHuddle($conversationId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['huddle' => $result['huddle']]]);
    }

    /**
     * POST /chat/huddles/{id}/join - Join an existing huddle
     */
    public function join(Request $request): Response
    {
        if ($error = $this->requireHuddleAuth($request)) return $error;

        $huddleId = (int)$request->getParam('id');
        $result = $this->huddleService->joinHuddleById($huddleId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['huddle' => $result['huddle']]]);
    }

    /**
     * POST /chat/huddles/{id}/leave - Leave a huddle
     */
    public function leave(Request $request): Response
    {
        if ($error = $this->requireHuddleAuth($request)) return $error;

        $huddleId = (int)$request->getParam('id');
        $result = $this->huddleService->leaveHuddle($huddleId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['ended' => $result['ended']]]);
    }

    /**
     * GET /chat/huddles/active/{conversationId} - Get active huddle for a conversation
     */
    public function getActive(Request $request): Response
    {
        if ($error = $this->requireHuddleAuth($request)) return $error;

        $conversationId = (int)$request->getParam('id');
        $result = $this->huddleService->getActiveHuddle($conversationId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['huddle' => $result['huddle']]]);
    }

    /**
     * GET /chat/huddles/active-all - Get all active huddles across user's conversations
     */
    public function getAllActive(Request $request): Response
    {
        if ($error = $this->requireHuddleAuth($request)) return $error;

        $result = $this->huddleService->getAllActiveHuddles($this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['huddles' => $result['huddles']]]);
    }
}

