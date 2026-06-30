<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\PushNotificationService;

/**
 * PushNotificationController - API endpoints for Web Push subscriptions
 * 
 * Endpoints:
 *   GET  /push/vapid-key   - Get VAPID public key (for frontend)
 *   POST /push/subscribe   - Save push subscription
 *   POST /push/unsubscribe - Remove push subscription
 */
class PushNotificationController extends BaseController
{
    private PushNotificationService $pushService;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->pushService = new PushNotificationService($config);
    }
    
    /**
     * Get VAPID public key - needed by frontend to subscribe to push
     * This endpoint does NOT require authentication (key is public)
     */
    public function getVapidKey(Request $request): Response
    {
        $publicKey = $this->pushService->getVapidPublicKey();
        
        if (empty($publicKey)) {
            return Response::json([
                'publicKey' => null,
                'error' => 'Push notifications not configured'
            ]);
        }
        
        return Response::json(['publicKey' => $publicKey]);
    }
    
    /**
     * Subscribe to push notifications
     * Saves the browser's push subscription for this user
     */
    public function subscribe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $userEmail = $this->getActiveEmail();
        
        $endpoint = $request->input('endpoint');
        $keys = $request->input('keys') ?? [];
        
        if (!$endpoint || empty($keys['p256dh']) || empty($keys['auth'])) {
            return Response::json(['error' => 'Missing required fields: endpoint, keys.p256dh, keys.auth'], 400);
        }
        
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $result = $this->pushService->subscribe(
            $userEmail,
            $endpoint,
            $keys['p256dh'],
            $keys['auth'],
            $userAgent
        );
        
        return Response::json($result, $result['success'] ? 200 : 500);
    }
    
    /**
     * Unsubscribe from push notifications
     * Removes the push subscription for this user
     */
    public function unsubscribe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $userEmail = $this->getActiveEmail();
        
        $endpoint = $request->input('endpoint');
        
        if (!$endpoint) {
            return Response::json(['error' => 'Missing required field: endpoint'], 400);
        }
        
        $result = $this->pushService->unsubscribe($userEmail, $endpoint);
        
        return Response::json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Register a native (FCM) device token from the Capacitor iOS/Android app.
     * Called on every app start/resume so last_seen_at stays fresh and rotated
     * tokens replace the previous one for the same device.
     */
    public function nativeRegister(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $userEmail = $this->getActiveEmail();

        $token = $request->input('token');
        $platform = $request->input('platform') ?: 'ios';
        $appId = $request->input('app_id') ?: 'com.flowone.pro';
        $deviceName = $request->input('device_name');

        // 'fcm' (default alert token) or 'voip' (iOS PushKit token for CallKit).
        $tokenKind = $request->input('token_kind') === 'voip' ? 'voip' : 'fcm';

        // Stable per-install id from @capacitor/device. Fall back to a token-derived
        // id so older app builds (that don't send device_id yet) still register.
        $deviceId = $request->input('device_id');

        if (!$token) {
            return Response::json(['error' => 'Missing required field: token'], 400);
        }

        if (!$deviceId) {
            $deviceId = 'token_' . substr(hash('sha256', $token), 0, 32);
        }

        $result = $this->pushService->registerNativeToken(
            $userEmail,
            (string)$platform,
            (string)$appId,
            (string)$deviceId,
            $deviceName !== null ? (string)$deviceName : null,
            (string)$token,
            $tokenKind
        );

        return Response::json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Remove a native device token (logout / device removal).
     * Accepts token (preferred) or device_id (optionally scoped to app_id).
     */
    public function nativeUnregister(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $userEmail = $this->getActiveEmail();

        $token = $request->input('token');
        $deviceId = $request->input('device_id');
        $appId = $request->input('app_id');

        if (!$token && !$deviceId) {
            return Response::json(['error' => 'Missing required field: token or device_id'], 400);
        }

        $result = $this->pushService->removeNativeToken(
            $userEmail,
            $token !== null ? (string)$token : null,
            $deviceId !== null ? (string)$deviceId : null,
            $appId !== null ? (string)$appId : null
        );

        return Response::json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Store the client's authoritative unread badge total in Redis so the Node
     * mailsync server can seed the iOS/Android app-icon badge on push.
     * Body: { count: int }.
     */
    public function setBadge(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $userEmail = $this->getActiveEmail();

        $count = (int)$request->input('count');
        if ($count < 0) {
            $count = 0;
        }

        $result = $this->pushService->setBadgeCount($userEmail, $count);
        return Response::json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Get the user's push notification preferences ({type: bool} map).
     */
    public function getPreferences(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $userEmail = $this->getActiveEmail();
        return Response::json(['preferences' => $this->pushService->getPreferences($userEmail)]);
    }

    /**
     * Update the user's push notification preferences. Accepts a partial map of
     * { email, chat, calls, calendar, boards } booleans (also tolerates a nested
     * { preferences: {...} } body).
     */
    public function updatePreferences(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $userEmail = $this->getActiveEmail();

        $prefs = $request->input('preferences');
        if (!is_array($prefs)) {
            $prefs = [];
            foreach (['email', 'chat', 'calls', 'calendar', 'boards'] as $type) {
                $val = $request->input($type);
                if ($val !== null) {
                    $prefs[$type] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        $result = $this->pushService->updatePreferences($userEmail, $prefs);
        return Response::json($result, $result['success'] ? 200 : 500);
    }
}

