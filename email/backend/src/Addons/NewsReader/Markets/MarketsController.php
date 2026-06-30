<?php

namespace Webmail\Addons\NewsReader\Markets;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;

/**
 * REST surface for the Markets panel inside the News dashboard.
 *
 * Endpoints:
 *   GET /markets/overview   -> stocks + crypto for the user's basket
 *   GET /markets/available  -> the curated allow-list (for Settings UI)
 *
 * Auth model mirrors NewsReaderController: requireAuth() verifies the
 * bearer token on every request. The user's basket selections are read
 * from their settings JSON file (the same file SettingsController
 * persists), keyed by the user's email — so the cache key is per-basket
 * and shared between users who happen to pick the same set.
 */
class MarketsController extends BaseController
{
    /**
     * Settings storage layout matches SettingsController exactly:
     *   /var/www/vps-email/data/settings/{md5(lowercase_email)}.json
     * Centralising the path here keeps us from touching SettingsController
     * for a one-key read.
     */
    private const SETTINGS_DIR = '/var/www/vps-email/data/settings';

    private ?MarketsService $service = null;

    private function svc(): MarketsService
    {
        if ($this->service === null) {
            $this->service = new MarketsService($this->config);
        }

        return $this->service;
    }

    public function overview(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $email = $this->resolveActiveEmail();
        $userSettings = $this->loadUserSettings($email);

        // Query params override the saved settings, useful for ad-hoc
        // testing. Production traffic doesn't send them.
        $stocksParam = $request->getQuery('stocks');
        $cryptoParam = $request->getQuery('crypto');
        $stocks = $stocksParam ?? $userSettings['news_markets_stocks'] ?? null;
        $crypto = $cryptoParam ?? $userSettings['news_markets_crypto'] ?? null;

        try {
            $payload = $this->svc()->getOverview($stocks, $crypto);
        } catch (\Throwable $e) {
            error_log('MarketsController overview: ' . $e->getMessage());

            return Response::error('Markets temporarily unavailable', 503);
        }

        return Response::success($payload);
    }

    public function available(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        return Response::success($this->svc()->getAvailable());
    }

    /**
     * Resolve the email whose settings we should read. Mirrors
     * SettingsController::getActiveEmail() — falls back to the JWT
     * email when no X-Account-ID header is present.
     */
    private function resolveActiveEmail(): string
    {
        $accountId = $_SERVER['HTTP_X_ACCOUNT_ID'] ?? null;
        if ($accountId && $accountId !== 'primary') {
            // Look up the secondary account email via BaseController's
            // helper, same as SettingsController does.
            $secondary = $this->getSecondaryAccountEmail((int) $accountId);
            if ($secondary) {
                return $secondary;
            }
        }

        return (string) ($this->userEmail ?? '');
    }

    /**
     * Load the user's settings JSON file. Returns an empty array when
     * the file is missing — the service will fall back to default
     * baskets in that case.
     */
    private function loadUserSettings(string $email): array
    {
        if ($email === '') {
            return [];
        }
        $hash = md5(strtolower($email));
        $file = self::SETTINGS_DIR . '/' . $hash . '.json';
        if (!is_readable($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
