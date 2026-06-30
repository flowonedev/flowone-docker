<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\WeatherService;

/**
 * WeatherController - header weather chip endpoint.
 *
 * Auth-required, idempotent GET. All caching and external-API logic lives in
 * WeatherService; this controller is intentionally thin.
 */
class WeatherController extends BaseController
{
    private WeatherService $weather;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->weather = new WeatherService($config);
    }

    public function current(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        try {
            $result = $this->weather->getForUser(
                $this->userEmail,
                $request->getClientIp()
            );
            return Response::success($result);
        } catch (\Throwable $e) {
            error_log('WeatherController::current error: ' . $e->getMessage());
            return Response::success([
                'available'    => false,
                'stale'        => true,
                'weather_code' => null,
                'temperature_c' => null,
            ]);
        }
    }
}
