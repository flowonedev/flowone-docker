<?php

namespace Webmail\Addons\Calendar\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Services\OAuthStateService;
use Webmail\Addons\Calendar\Services\CalendarConnectionService;
use Webmail\Addons\Calendar\Services\GoogleCalendarService;

/**
 * CalendarConnectionController - Manages calendar-only Google connections
 * 
 * Allows syncing Google Calendar without full email OAuth access.
 */
class CalendarConnectionController extends BaseController
{
    private ?CalendarConnectionService $connectionService = null;
    private ?GoogleCalendarService $calendarService = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        if ($this->userEmail) {
            $this->connectionService = new CalendarConnectionService($config);
            $this->calendarService = new GoogleCalendarService($config);
        }
    }
    
    // extractUserFromToken() and requireValidSession() inherited from BaseController
    
    /**
     * Get all calendar-only connections
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $connections = $this->connectionService->getConnections($this->userEmail);
        
        // Add sync info for each connection
        foreach ($connections as &$conn) {
            $conn['synced_calendars'] = $this->calendarService->getSyncedCalendarsInfo(
                $conn['id'], 
                GoogleCalendarService::CONNECTION_CALENDAR_ONLY
            );
        }
        
        return Response::success(['connections' => $connections]);
    }
    
    /**
     * Get OAuth URL for calendar-only connection
     */
    public function getAuthUrl(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->connectionService) {
            return Response::error('Google OAuth is not configured', 500);
        }
        
        // Phase 2.1: HMAC-signed state via shared OAuthStateService.
        // Previous behaviour (plain base64(JSON)) allowed an attacker to
        // craft state with any user_email and link a victim's Google
        // calendar tokens to a different account.
        $stateService = new OAuthStateService($this->config);
        $nonce = bin2hex(random_bytes(16));
        $state = $stateService->sign([
            'action' => 'connect_calendar',
            'user_email' => $this->userEmail,
            'type' => 'calendar_only',
            'nonce' => $nonce,
        ]);

        // Phase 3: PKCE S256 (the redirect lands in
        // AccountController::googleCallback which dispatches calendar-only
        // state to AccountController::handleCalendarOnlyCallback; the
        // verifier is consumed there).
        $pkce = new \Webmail\Services\PKCEService($this->config);
        $challenge = $pkce->createChallenge($nonce);

        $authUrl = $this->connectionService->getAuthorizationUrl($state, $challenge['challenge']);

        return Response::success(['auth_url' => $authUrl]);
    }
    
    // Phase 2.6: callback() (GET handler for /calendar/connections/callback)
    // removed. The Google OAuth redirect_uri configured in google_oauth.config
    // points at /api/auth/google/callback (handled by
    // AccountController::googleCallback), which dispatches calendar-only
    // state through AccountController::handleCalendarOnlyCallback. The
    // corresponding GET route in routes.php has also been removed.

    // Phase 2.6: renderOAuthPopupClose() removed alongside callback().
    // The HTML popup-close page is rendered by
    // AccountController::renderOAuthPopupClose (the canonical implementation,
    // shared by email and calendar-only OAuth flows).

    // Phase 3 (orphan cleanup): callbackPost() / POST
    // /calendar/connections/callback removed. The frontend's
    // calendar-only connect flow uses the popup -> GET
    // /api/auth/google/callback path (handled by
    // AccountController::googleCallback, which dispatches calendar-only
    // state to handleCalendarOnlyCallback). The POST variant had no
    // frontend caller and skipping PKCE on this duplicate path was a
    // foot-gun. Per orphan-code-hygiene.mdc.

    /**
     * Get Google calendars for a connection
     */
    public function getCalendars(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $connectionId = (int)$request->getQuery('connection_id');
        if (!$connectionId) {
            return Response::error('Connection ID is required');
        }
        
        $calendars = $this->calendarService->getGoogleCalendarsFromConnection($this->userEmail, $connectionId);
        
        return Response::success(['calendars' => $calendars]);
    }
    
    /**
     * Setup sync for a calendar-only connection
     */
    public function setupSync(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $connectionId = (int)$request->input('connection_id');
        $googleCalendarId = $request->input('google_calendar_id');
        $localCalendarId = (int)$request->input('local_calendar_id');
        
        if (!$connectionId || !$googleCalendarId || !$localCalendarId) {
            return Response::error('Connection ID, Google calendar ID, and local calendar ID are required');
        }
        
        $sync = $this->calendarService->setupSyncForConnection(
            $this->userEmail,
            $connectionId,
            $googleCalendarId,
            $localCalendarId
        );
        
        if (!$sync) {
            return Response::error('Failed to setup sync');
        }
        
        return Response::success(['sync' => $sync], 'Calendar sync configured');
    }

    /**
     * Batched setup for calendar-only connections.
     * Body: { connection_id, google_calendar_ids: string[], local_calendar_id }
     * POST /calendar/connections/sync-batch
     */
    public function setupSyncBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $connectionId = (int)$request->input('connection_id');
        $googleCalendarIds = (array)$request->input('google_calendar_ids', []);
        $localCalendarId = (int)$request->input('local_calendar_id');

        if (!$connectionId || !$localCalendarId || empty($googleCalendarIds)) {
            return Response::error('connection_id, local_calendar_id, and google_calendar_ids are required');
        }

        $googleCalendarIds = array_values(array_unique(array_filter(array_map('strval', $googleCalendarIds))));
        $googleCalendarIds = array_slice($googleCalendarIds, 0, 50);

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($googleCalendarIds as $gcalId) {
            try {
                $sync = $this->calendarService->setupSyncForConnection(
                    $this->userEmail,
                    $connectionId,
                    $gcalId,
                    $localCalendarId
                );
                if ($sync) {
                    $success++;
                } else {
                    $failed++;
                    $errors[] = "{$gcalId}: setupSyncForConnection returned null";
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "{$gcalId}: " . $e->getMessage();
                error_log("[CalendarConnection::setupSyncBatch] {$gcalId}: " . $e->getMessage());
            }
        }

        return Response::success([
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ], "{$success} configured, {$failed} failed");
    }

    /**
     * Batched pull for calendar-only connections.
     * Body: { connection_id, google_calendar_ids: string[] }
     * POST /calendar/connections/sync-pull-batch
     */
    public function syncFromGoogleBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $connectionId = (int)$request->input('connection_id');
        $googleCalendarIds = (array)$request->input('google_calendar_ids', []);

        if (!$connectionId || empty($googleCalendarIds)) {
            return Response::error('connection_id and google_calendar_ids are required');
        }

        $googleCalendarIds = array_values(array_unique(array_filter(array_map('strval', $googleCalendarIds))));
        $googleCalendarIds = array_slice($googleCalendarIds, 0, 50);

        $totalImported = 0;
        $totalUpdated = 0;
        $errors = [];
        $perCalendar = [];

        foreach ($googleCalendarIds as $gcalId) {
            try {
                $result = $this->calendarService->syncFromGoogleConnection(
                    $this->userEmail,
                    $connectionId,
                    $gcalId
                );
                $totalImported += (int)($result['imported'] ?? 0);
                $totalUpdated += (int)($result['updated'] ?? 0);
                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $err) {
                        $errors[] = "{$gcalId}: {$err}";
                    }
                }
                $perCalendar[$gcalId] = $result;
            } catch (\Throwable $e) {
                $errors[] = "{$gcalId}: " . $e->getMessage();
                error_log("[CalendarConnection::syncFromGoogleBatch] {$gcalId}: " . $e->getMessage());
            }
        }

        return Response::success([
            'imported' => $totalImported,
            'updated' => $totalUpdated,
            'errors' => $errors,
            'per_calendar' => $perCalendar,
        ], "Synced {$totalImported} new, {$totalUpdated} updated across " . count($googleCalendarIds) . " calendar(s)");
    }

    /**
     * Sync from Google (calendar-only connection)
     */
    public function syncFromGoogle(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $connectionId = (int)$request->input('connection_id');
        $googleCalendarId = $request->input('google_calendar_id');
        
        if (!$connectionId || !$googleCalendarId) {
            return Response::error('Connection ID and Google calendar ID are required');
        }
        
        $result = $this->calendarService->syncFromGoogleConnection(
            $this->userEmail,
            $connectionId,
            $googleCalendarId
        );
        
        error_log("CalendarConnectionController syncFromGoogle result: " . json_encode($result));
        
        if (!empty($result['errors'])) {
            return Response::error($result['errors'][0]);
        }
        
        return Response::success($result, 'Sync completed');
    }
    
    /**
     * Disable sync with options (keep or delete events)
     */
    public function disableSync(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $connectionId = (int)$request->input('connection_id');
        $googleCalendarId = $request->input('google_calendar_id');
        $deleteEventsInput = $request->input('delete_events', false);
        // Handle both boolean and string values
        $deleteEvents = $deleteEventsInput === true || $deleteEventsInput === 'true' || $deleteEventsInput === 1 || $deleteEventsInput === '1';
        
        if (!$connectionId || !$googleCalendarId) {
            return Response::error('Connection ID and Google calendar ID are required');
        }
        
        error_log("Desync request: connectionId=$connectionId, googleCalendarId=$googleCalendarId, deleteEvents=" . ($deleteEvents ? 'true' : 'false'));
        
        if ($deleteEvents) {
            $result = $this->calendarService->desyncWithCleanup(
                $connectionId,
                $googleCalendarId,
                GoogleCalendarService::CONNECTION_CALENDAR_ONLY
            );
            error_log("DesyncWithCleanup result: " . json_encode($result));
        } else {
            $result = $this->calendarService->desyncKeepEvents(
                $connectionId,
                $googleCalendarId,
                GoogleCalendarService::CONNECTION_CALENDAR_ONLY
            );
        }
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to disable sync');
        }
        
        return Response::success($result, 'Sync disabled');
    }
    
    /**
     * Get synced events count for a calendar
     */
    public function getSyncedEventsCount(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $connectionId = (int)$request->getQuery('connection_id');
        $googleCalendarId = $request->getQuery('google_calendar_id');
        
        if (!$connectionId || !$googleCalendarId) {
            return Response::error('Connection ID and Google calendar ID are required');
        }
        
        $count = $this->calendarService->getSyncedEventsCount(
            $connectionId,
            $googleCalendarId,
            GoogleCalendarService::CONNECTION_CALENDAR_ONLY
        );
        
        return Response::success(['count' => $count]);
    }
    
    /**
     * Remove a calendar-only connection
     */
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $deleteEvents = $request->input('delete_events', false);
        
        // First remove all syncs for this connection
        $this->calendarService->removeAllSyncsForAccount(
            $id,
            GoogleCalendarService::CONNECTION_CALENDAR_ONLY,
            $deleteEvents
        );
        
        // Then delete the connection
        if (!$this->connectionService->deleteConnection($this->userEmail, $id)) {
            return Response::error('Connection not found', 404);
        }
        
        return Response::success(null, 'Calendar connection removed');
    }
    
    // ==================== Account History ====================
    
    /**
     * Get account history
     */
    public function getHistory(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $history = $this->connectionService->getAccountHistory($this->userEmail);
        
        return Response::success(['history' => $history]);
    }
    
    /**
     * Delete history entry
     */
    public function deleteHistory(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        if (!$this->connectionService->deleteHistoryEntry($this->userEmail, $id)) {
            return Response::error('History entry not found', 404);
        }
        
        return Response::success(null, 'History entry deleted');
    }
}

