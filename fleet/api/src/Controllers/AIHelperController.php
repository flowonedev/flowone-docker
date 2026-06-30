<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\AIHelperService;

class AIHelperController extends BaseController
{
    private AIHelperService $aiHelper;

    public function __construct($container)
    {
        parent::__construct($container);
        $this->aiHelper = new AIHelperService($container);
    }

    /**
     * List user's conversations
     */
    public function listConversations(Request $request): Response
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                return Response::error('Unauthorized', 401);
            }

            $conversations = $this->aiHelper->getUserConversations($user->sub);
            return Response::success(['conversations' => $conversations]);
        } catch (\Exception $e) {
            error_log('AI Helper listConversations error: ' . $e->getMessage());
            return Response::success(['conversations' => []]);
        }
    }

    /**
     * Create new conversation
     */
    public function createConversation(Request $request): Response
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                return Response::error('Unauthorized', 401);
            }

            $title = $request->input('title');
            $contextType = $request->input('context_type', 'general');
            $contextData = $request->input('context_data');

            $conversation = $this->aiHelper->createConversation(
                $user->sub,
                $title,
                $contextType,
                $contextData
            );

            return Response::success(['conversation' => $conversation]);
        } catch (\Exception $e) {
            error_log('AI Helper createConversation error: ' . $e->getMessage());
            return Response::error('Failed to create conversation: ' . $e->getMessage());
        }
    }

    /**
     * Get conversation with messages
     */
    public function getConversation(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $id = (int)$request->getParam('id');
        $conversation = $this->aiHelper->getConversation($id, $user->sub);

        if (!$conversation) {
            return Response::notFound('Conversation not found');
        }

        return Response::success(['conversation' => $conversation]);
    }

    /**
     * Delete a conversation
     */
    public function deleteConversation(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $id = (int)$request->getParam('id');
        $success = $this->aiHelper->deleteConversation($id, $user->sub);

        if (!$success) {
            return Response::notFound('Conversation not found or access denied');
        }

        return Response::success(['message' => 'Conversation deleted successfully']);
    }

    /**
     * Send message to AI
     */
    public function sendMessage(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $validation = $this->validateRequired($request, ['message']);
        if ($validation) {
            return $validation;
        }

        $conversationId = (int)$request->getParam('id');
        $message = $request->input('message');
        $context = $request->input('context', []);

        try {
            // Verify conversation belongs to user
            $conversation = $this->aiHelper->getConversation($conversationId, $user->sub);
            if (!$conversation) {
                return Response::notFound('Conversation not found');
            }

            // Add context from conversation
            $context['type'] = $context['type'] ?? $conversation['context_type'];
            if ($conversation['context_data']) {
                $contextData = json_decode($conversation['context_data'], true);
                $context = array_merge($contextData, $context);
            }

            // Add user message
            $this->aiHelper->addMessage($conversationId, 'user', $message);

            // Get conversation history
            $history = $this->aiHelper->getConversationHistory($conversationId);

            // Get AI response
            $response = $this->aiHelper->sendToOpenAI($message, $history, $context);

            // Add AI response
            $this->aiHelper->addMessage(
                $conversationId,
                'assistant',
                $response['message'],
                ['tokens_used' => $response['tokens_used']],
                $response['tokens_used']
            );

            return Response::success([
                'message' => $response['message'],
                'tokens_used' => $response['tokens_used'],
            ]);
        } catch (\Exception $e) {
            error_log('AI Helper sendMessage error: ' . $e->getMessage());
            return Response::error($e->getMessage());
        }
    }

    /**
     * Get AI Helper settings
     */
    public function getSettings(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $settings = $this->aiHelper->getSettings();
        return Response::success(['settings' => $settings]);
    }

    /**
     * Update AI Helper settings
     */
    public function updateSettings(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        // Check admin role
        if (!in_array($user->role, ['super_admin', 'admin'])) {
            return Response::error('Admin access required', 403);
        }

        $settings = $request->input('settings', []);
        $success = $this->aiHelper->updateSettings($settings);

        if (!$success) {
            return Response::error('Failed to update settings');
        }

        return Response::success([
            'message' => 'Settings updated successfully',
            'settings' => $this->aiHelper->getSettings()
        ]);
    }

    /**
     * Analyze logs with AI
     */
    public function analyzeLogs(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $validation = $this->validateRequired($request, ['logs', 'service']);
        if ($validation) {
            return $validation;
        }

        $logs = $request->input('logs');
        $service = $request->input('service');

        if (!is_array($logs)) {
            $logs = explode("\n", $logs);
        }

        try {
            $result = $this->aiHelper->analyzeLogs($logs, $service, $user->sub);
            return Response::success($result);
        } catch (\Exception $e) {
            error_log('AI Helper analyzeLogs error: ' . $e->getMessage());
            return Response::error($e->getMessage());
        }
    }

    /**
     * Analyze config with AI
     */
    public function analyzeConfig(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $validation = $this->validateRequired($request, ['content', 'service']);
        if ($validation) {
            return $validation;
        }

        $content = $request->input('content');
        $service = $request->input('service');
        $filePath = $request->input('file_path');

        try {
            $result = $this->aiHelper->analyzeConfig($content, $service, $user->sub, $filePath);
            return Response::success($result);
        } catch (\Exception $e) {
            error_log('AI Helper analyzeConfig error: ' . $e->getMessage());
            return Response::error($e->getMessage());
        }
    }

    /**
     * Get cached issues
     */
    public function getCachedIssues(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $serverId = $request->input('server_id');
        $service = $request->input('service');
        $includeResolved = $request->input('resolved') === 'true';

        $issues = $this->aiHelper->getCachedIssues(
            $serverId ? (int)$serverId : null,
            $service,
            $includeResolved
        );

        return Response::success(['issues' => $issues]);
    }

    /**
     * Mark issue as resolved
     */
    public function resolveIssue(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $issueId = (int)$request->getParam('id');
        $success = $this->aiHelper->resolveIssue($issueId);

        if (!$success) {
            return Response::notFound('Issue not found');
        }

        return Response::success(['message' => 'Issue marked as resolved']);
    }
}

