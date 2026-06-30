<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\DeviceService;
use Webmail\Services\AuditLogger;

class DeviceController extends BaseController
{
    private DeviceService $deviceService;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->deviceService = new DeviceService($config);
    }
    
    /**
     * List all devices for the current user
     * GET /devices
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $devices = $this->deviceService->getDevices($this->userEmail);
        
        return Response::success([
            'devices' => $devices,
        ]);
    }
    
    /**
     * Register a device (called on login from desktop/drive apps)
     * POST /devices/register
     */
    public function register(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $deviceId = $request->input('device_id');
        if (!$deviceId) {
            return Response::error('device_id is required', 400);
        }
        
        $result = $this->deviceService->registerDevice(
            $this->userEmail,
            $deviceId,
            $request->input('platform') ?? 'web',
            $request->input('device_name'),
            $request->input('os'),
            $request->input('app_version'),
            $request->getClientIp()
        );
        
        if (!$result['success']) {
            return Response::json([
                'success' => false,
                'message' => $result['message'] ?? 'Device registration failed',
                'action' => $result['action'] ?? 'none',
            ], 403);
        }
        
        // Link session to device if session token provided
        $sessionToken = $request->getHeader('X-Session-Token');
        if ($sessionToken) {
            $this->deviceService->linkSessionToDevice($this->userEmail, $sessionToken, $deviceId);
        }
        
        return Response::success($result, 'Device registered');
    }
    
    /**
     * Block a device
     * POST /devices/{id}/block
     */
    public function block(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $deviceId = (int) $request->param('id');
        if (!$deviceId) {
            return Response::error('Device ID is required', 400);
        }
        
        // Make sure the device belongs to this user
        $device = $this->deviceService->getDevice($this->userEmail, $deviceId);
        if (!$device) {
            return Response::error('Device not found', 404);
        }
        
        if ($this->deviceService->blockDevice($this->userEmail, $deviceId)) {
            return Response::success(null, 'Device blocked and sessions invalidated');
        }
        
        return Response::error('Failed to block device', 500);
    }
    
    /**
     * Unblock a device
     * POST /devices/{id}/unblock
     */
    public function unblock(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $deviceId = (int) $request->param('id');
        if (!$deviceId) {
            return Response::error('Device ID is required', 400);
        }
        
        if ($this->deviceService->unblockDevice($this->userEmail, $deviceId)) {
            return Response::success(null, 'Device unblocked');
        }
        
        return Response::error('Device not found', 404);
    }
    
    /**
     * Request remote wipe for a device
     * POST /devices/{id}/wipe
     */
    public function wipe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $deviceId = (int) $request->param('id');
        if (!$deviceId) {
            return Response::error('Device ID is required', 400);
        }
        
        // Make sure the device belongs to this user
        $device = $this->deviceService->getDevice($this->userEmail, $deviceId);
        if (!$device) {
            return Response::error('Device not found', 404);
        }
        
        if ($device['platform'] === 'web') {
            return Response::error('Cannot remote wipe a web browser session. Use session revocation instead.', 400);
        }
        
        if ($this->deviceService->requestWipe($this->userEmail, $deviceId)) {
            AuditLogger::log('device.remote_wipe', 'high', 'success', [
                'device_id' => $deviceId, 
                'device_name' => $device['device_name'] ?? 'unknown',
                'platform' => $device['platform'] ?? 'unknown',
            ], 'device', 'user', $this->userEmail);
            return Response::success(null, 'Remote wipe requested. Device will wipe on next check-in.');
        }
        
        return Response::error('Failed to request wipe', 500);
    }
    
    /**
     * Confirm wipe was completed by the device
     * POST /devices/{id}/wipe-confirm
     */
    public function wipeConfirm(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $deviceIdString = $request->input('device_id');
        if (!$deviceIdString) {
            return Response::error('device_id is required', 400);
        }
        
        if ($this->deviceService->confirmWipe($this->userEmail, $deviceIdString)) {
            return Response::success(null, 'Wipe confirmed');
        }
        
        return Response::error('No pending wipe for this device', 404);
    }
    
    /**
     * Quick status check for a device (lightweight endpoint for polling)
     * GET /devices/check
     */
    public function check(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $deviceIdString = $request->getQuery('device_id') ?? $request->getHeader('X-Device-Id');
        if (!$deviceIdString) {
            return Response::success(['status' => 'unknown', 'action' => 'none']);
        }
        
        $status = $this->deviceService->getDeviceStatus($this->userEmail, $deviceIdString);
        
        return Response::success($status);
    }
}

