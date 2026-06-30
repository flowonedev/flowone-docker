<?php

namespace Webmail\Addons\Chat\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Chat\Services\WebhookService;

/**
 * WebhookController - REST API for Chat Webhooks
 * 
 * Authenticated endpoints for managing webhooks,
 * plus a public endpoint for receiving webhook messages.
 */
class WebhookController extends BaseController
{
    private ?WebhookService $webhookService = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    private function getWebhookService(): ?WebhookService
    {
        if (!$this->webhookService) {
            try {
                $this->webhookService = new WebhookService($this->config);
            } catch (\Throwable $e) {
                error_log("WebhookController: Failed to init WebhookService: " . $e->getMessage());
            }
        }
        return $this->webhookService;
    }

    private function requireWebhookAuth(Request $request): ?Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->getWebhookService()) {
            return Response::json(['error' => 'Webhook service unavailable'], 503);
        }
        return null;
    }

    /**
     * POST /chat/webhooks - Create a new incoming webhook
     */
    public function createWebhook(Request $request): Response
    {
        if ($error = $this->requireWebhookAuth($request)) return $error;

        $body = $request->input();
        $conversationId = (int)($body['conversation_id'] ?? 0);
        $name = $body['name'] ?? 'Webhook';
        $avatarUrl = $body['avatar_url'] ?? null;

        if (!$conversationId) {
            return Response::json(['success' => false, 'error' => 'conversation_id is required'], 400);
        }

        $result = $this->webhookService->createWebhook($this->userEmail, $conversationId, $name, $avatarUrl);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['webhook' => $result['webhook']]]);
    }

    /**
     * GET /chat/webhooks - List all webhooks the user has access to
     */
    public function listWebhooks(Request $request): Response
    {
        if ($error = $this->requireWebhookAuth($request)) return $error;

        $result = $this->webhookService->listWebhooks($this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['webhooks' => $result['webhooks']]]);
    }

    /**
     * DELETE /chat/webhooks/{id} - Delete a webhook
     */
    public function deleteWebhook(Request $request): Response
    {
        if ($error = $this->requireWebhookAuth($request)) return $error;

        $webhookId = (int)$request->getParam('id');
        if (!$webhookId) {
            return Response::json(['success' => false, 'error' => 'Webhook ID required'], 400);
        }

        $result = $this->webhookService->deleteWebhook($webhookId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }

    /**
     * POST /webhook/{token} - Public endpoint to receive webhook messages (no auth)
     */
    public function receiveWebhook(Request $request): Response
    {
        $token = $request->getParam('token');
        if (!$token) {
            return Response::json(['success' => false, 'error' => 'Token required'], 400);
        }

        // Get the webhook service without auth
        $service = $this->getWebhookService();
        if (!$service) {
            return Response::json(['success' => false, 'error' => 'Webhook service unavailable'], 503);
        }

        // Parse body (support both JSON and form data)
        $body = $request->input();
        if (empty($body)) {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true) ?: [];
        }

        $result = $service->receiveMessage($token, $body);

        if (!$result['success']) {
            $code = $result['error'] === 'Invalid or inactive webhook' ? 404 : 400;
            return Response::json(['success' => false, 'error' => $result['error']], $code);
        }

        return Response::json(['success' => true, 'data' => ['message_id' => $result['message_id']]]);
    }
}

