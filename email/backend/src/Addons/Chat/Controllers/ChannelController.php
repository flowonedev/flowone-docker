<?php

namespace Webmail\Addons\Chat\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Chat\Services\ChannelService;

/**
 * ChannelController - REST API for Chat Channels
 */
class ChannelController extends BaseController
{
    private ?ChannelService $channelService = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    private function getChannelService(): ?ChannelService
    {
        if (!$this->channelService) {
            try {
                $this->channelService = new ChannelService($this->config);
            } catch (\Throwable $e) {
                error_log("ChannelController: Failed to init ChannelService: " . $e->getMessage());
            }
        }
        return $this->channelService;
    }

    private function requireChannelAuth(Request $request): ?Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->getChannelService()) {
            return Response::json(['error' => 'Channel service unavailable'], 503);
        }
        return null;
    }

    /**
     * GET /chat/channels - Browse all channels (public + user's private)
     */
    public function browseChannels(Request $request): Response
    {
        if ($error = $this->requireChannelAuth($request)) return $error;

        $search = $request->getQuery('search');
        $result = $this->channelService->browseChannels($this->userEmail, $search);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => ['channels' => $result['channels']]
        ]);
    }

    /**
     * POST /chat/channels - Create a new channel
     */
    public function createChannel(Request $request): Response
    {
        if ($error = $this->requireChannelAuth($request)) return $error;

        $body = $request->input();
        error_log("ChannelController::createChannel body: " . json_encode($body));
        $name = $body['name'] ?? '';
        $isPublic = $body['is_public'] ?? true;
        $topic = $body['topic'] ?? null;
        $purpose = $body['purpose'] ?? null;
        $isDefault = $body['is_default'] ?? false;
        $categoryId = isset($body['category_id']) ? (int)$body['category_id'] : null;

        error_log("ChannelController::createChannel parsed: name={$name}, isPublic=" . ($isPublic ? '1' : '0') . ", user={$this->userEmail}");

        $result = $this->channelService->createChannel(
            $this->userEmail, $name, (bool)$isPublic, $topic, $purpose, (bool)$isDefault, $categoryId
        );

        if (!$result['success']) {
            error_log("ChannelController::createChannel failed: " . ($result['error'] ?? 'unknown'));
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => ['channel' => $result['channel']]
        ]);
    }

    /**
     * POST /chat/channels/{id}/join - Join a public channel
     */
    public function joinChannel(Request $request): Response
    {
        if ($error = $this->requireChannelAuth($request)) return $error;

        $channelId = (int)$request->getParam('id');
        if (!$channelId) {
            return Response::json(['success' => false, 'error' => 'Channel ID required'], 400);
        }

        $result = $this->channelService->joinChannel($channelId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }

    /**
     * POST /chat/channels/{id}/leave - Leave a channel
     */
    public function leaveChannel(Request $request): Response
    {
        if ($error = $this->requireChannelAuth($request)) return $error;

        $channelId = (int)$request->getParam('id');
        if (!$channelId) {
            return Response::json(['success' => false, 'error' => 'Channel ID required'], 400);
        }

        $result = $this->channelService->leaveChannel($channelId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true]);
    }

    /**
     * PATCH /chat/channels/{id}/topic - Set channel topic
     */
    public function setTopic(Request $request): Response
    {
        if ($error = $this->requireChannelAuth($request)) return $error;

        $channelId = (int)$request->getParam('id');
        $body = $request->input();
        $topic = $body['topic'] ?? '';

        $result = $this->channelService->setTopic($channelId, $this->userEmail, $topic);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['topic' => $result['topic']]]);
    }

    /**
     * PATCH /chat/channels/{id}/purpose - Set channel purpose
     */
    public function setPurpose(Request $request): Response
    {
        if ($error = $this->requireChannelAuth($request)) return $error;

        $channelId = (int)$request->getParam('id');
        $body = $request->input();
        $purpose = $body['purpose'] ?? '';

        $result = $this->channelService->setPurpose($channelId, $this->userEmail, $purpose);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['purpose' => $result['purpose']]]);
    }

    /**
     * GET /chat/channels/{id}/members - List all members of a channel
     */
    public function getMembers(Request $request): Response
    {
        if ($error = $this->requireChannelAuth($request)) return $error;

        $channelId = (int)$request->getParam('id');
        if (!$channelId) {
            return Response::json(['success' => false, 'error' => 'Channel ID required'], 400);
        }

        $result = $this->channelService->getChannelMembers($channelId, $this->userEmail);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json([
            'success' => true,
            'data' => [
                'members' => $result['members'],
                'member_count' => $result['member_count'],
            ]
        ]);
    }

    /**
     * POST /chat/channels/{id}/set-default - Mark as default channel (admin only)
     */
    public function setDefault(Request $request): Response
    {
        if ($error = $this->requireChannelAuth($request)) return $error;

        $channelId = (int)$request->getParam('id');
        $body = $request->input();
        $isDefault = (bool)($body['is_default'] ?? true);

        $result = $this->channelService->setDefault($channelId, $this->userEmail, $isDefault);

        if (!$result['success']) {
            return Response::json(['success' => false, 'error' => $result['error']], 400);
        }

        return Response::json(['success' => true, 'data' => ['is_default' => $result['is_default']]]);
    }
}

