<?php

namespace Webmail\Addons\Calendar\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\Calendar\Services\CalendarService;
use Webmail\Addons\Calendar\Services\GoogleCalendarService;
use Webmail\Addons\Calendar\Services\MicrosoftCalendarService;
use Webmail\Services\CalendarInviteService;
use Webmail\Services\GuestCallService;
use Webmail\Services\MeetingRoomService;
use Webmail\Addons\Team\Services\ColleagueService;
use Webmail\Services\RedisCacheService;
use Webmail\Addons\Chat\Services\ChatService;

/**
 * CalendarController - Calendar REST API
 */
class CalendarController extends BaseController
{
    private ?CalendarService $calendarService = null;
    private ?GoogleCalendarService $googleCalendarService = null;
    private ?MicrosoftCalendarService $microsoftCalendarService = null;
    private ?CalendarInviteService $inviteService = null;
    private ?RedisCacheService $redisCache = null;
    private ?\PDO $db = null;
    private ?int $userId = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        if ($this->userEmail) {
            $this->calendarService = new CalendarService($config);
            // Only initialize Google Calendar service if OAuth is configured and service file exists
            $googleCalServiceFile = __DIR__ . '/../Services/GoogleCalendarService.php';
            $hasClientId = !empty($config['google_oauth']['client_id']);
            $fileExists = file_exists($googleCalServiceFile);
            
            if ($hasClientId && $fileExists) {
                try {
                    $this->googleCalendarService = new GoogleCalendarService($config);
                } catch (\Exception $e) {
                    error_log("GoogleCalendarService initialization error: " . $e->getMessage());
                    $this->googleCalendarService = null;
                }
            }
            
            // Initialize Microsoft Calendar service if OAuth is configured
            if (!empty($config['microsoft_oauth']['client_id'])) {
                try {
                    $this->microsoftCalendarService = new MicrosoftCalendarService($config);
                } catch (\Exception $e) {
                    error_log("MicrosoftCalendarService initialization error: " . $e->getMessage());
                    $this->microsoftCalendarService = null;
                }
            }
            
            // Initialize database and invite service
            try {
                $this->db = \Webmail\Core\Database::getConnection($config);
                $this->inviteService = new CalendarInviteService($this->db, $this->config);
            } catch (\Exception $e) {
                error_log("CalendarController DB init error: " . $e->getMessage());
            }
        }
    }
    
    // extractUserFromToken(), requireValidSession(), getActiveEmail(), getSecondaryAccountEmail() inherited from BaseController
    
    // ===== CALENDAR ENDPOINTS =====
    
    /**
     * List all calendars (own + shared with me)
     */
    public function listCalendars(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $email = $this->getActiveEmail();
        $calendars = $this->calendarService->getCalendars($email);
        
        // Mark own calendars
        foreach ($calendars as &$cal) {
            $cal['is_shared'] = false;
            $cal['is_own'] = true;
        }
        
        // Get shared calendars via user groups
        $userGroupIds = $this->getUserGroupIds($email);
        $sharedCalendars = $this->calendarService->getSharedCalendars($email, $userGroupIds);
        
        return Response::success([
            'calendars' => $calendars,
            'shared_calendars' => $sharedCalendars
        ]);
    }
    
    /**
     * Helper: Get user's group IDs from ColleagueService
     */
    private function getUserGroupIds(string $email): array
    {
        try {
            $colleagueService = new ColleagueService($this->config);
            $groups = $colleagueService->getUserGroups($email);
            return array_column($groups, 'id');
        } catch (\Exception $e) {
            error_log("CalendarController getUserGroupIds error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Helper: Get RedisCacheService for publishing real-time events
     */
    private function getRedisCache(): RedisCacheService
    {
        if (!$this->redisCache) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }
    
    /**
     * Helper: Broadcast a calendar update to the owner + all shared recipients via WebSocket
     * 
     * Always resolves the real calendar owner from the database to ensure the owner
     * is notified even when a shared user triggers the update.
     */
    private function broadcastCalendarUpdate(int $calendarId, ?int $eventId = null, string $action = 'updated', ?string $ownerEmail = null): void
    {
        try {
            $cache = $this->getRedisCache();
            
            // Always resolve the REAL calendar owner from the database
            // This is critical: when a shared user edits an event, getActiveEmail() 
            // returns the shared user's email, NOT the calendar owner's email
            $realOwner = $ownerEmail ?? $this->calendarService->getCalendarOwner($calendarId);
            if (!$realOwner) {
                $realOwner = $this->getActiveEmail();
            }
            
            // Collect all unique emails to notify (owner + all shared recipients)
            $notifyEmails = [$realOwner];
            
            $recipients = $this->calendarService->getCalendarShareRecipients($calendarId, $realOwner);
            foreach ($recipients as $recipientEmail) {
                if (!in_array($recipientEmail, $notifyEmails)) {
                    $notifyEmails[] = $recipientEmail;
                }
            }

            if ($eventId !== null) {
                $participantRecipients = $this->calendarService->getEventParticipantRecipients($eventId, $notifyEmails);
                foreach ($participantRecipients as $recipientEmail) {
                    if (!in_array($recipientEmail, $notifyEmails, true)) {
                        $notifyEmails[] = $recipientEmail;
                    }
                }
            }
            
            // Also ensure the active user (who triggered the change) is in the list
            // so their other devices also update
            $activeEmail = strtolower($this->getActiveEmail());
            if (!in_array($activeEmail, $notifyEmails, true)) {
                $notifyEmails[] = $activeEmail;
            }
            
            // Broadcast to everyone
            foreach ($notifyEmails as $email) {
                $cache->publishCalendarUpdated($email, $calendarId, $eventId, $action);
            }
        } catch (\Exception $e) {
            error_log("[CalendarController] Failed to broadcast calendar update: " . $e->getMessage());
        }
    }

    /**
     * Phase 3.2: push a local create/update/delete to every active Google
     * calendar_sync_state that targets the given local calendar. Runs inline
     * (one HTTP call per linked Google calendar). On any failure the work
     * item is enqueued in calendar_push_queue and calendar_sync_state.pending_push
     * is flipped to 1 so the cron in Phase 3.3 can drain it.
     *
     * No-op when the Google service is not configured or the local calendar
     * has no active oauth sync states.
     *
     * @param string $op  'create_update' or 'delete'
     */
    private function autoPushToGoogle(int $localCalendarId, int $localEventId, string $op): void
    {
        if (!$this->googleCalendarService || !$this->db) {
            return;
        }
        if (!in_array($op, ['create_update', 'delete'], true)) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT id, oauth_account_id, google_calendar_id
                FROM calendar_sync_state
                WHERE local_calendar_id = ?
                  AND sync_enabled = 1
                  AND connection_type = 'oauth'
            ");
            $stmt->execute([$localCalendarId]);
            $states = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('autoPushToGoogle SELECT failed: ' . $e->getMessage());
            return;
        }

        if (empty($states)) {
            return;
        }

        $activeEmail = $this->getActiveEmail();

        foreach ($states as $state) {
            $oauthAccountId = (int)$state['oauth_account_id'];
            $syncStateId = (int)$state['id'];

            $ok = false;
            $err = null;
            try {
                if ($op === 'delete') {
                    $ok = $this->googleCalendarService->deleteFromGoogle($activeEmail, $oauthAccountId, $localEventId);
                } else {
                    $googleEventId = $this->googleCalendarService->syncToGoogle($activeEmail, $oauthAccountId, $localEventId);
                    $ok = $googleEventId !== null;
                }
            } catch (\Throwable $e) {
                $ok = false;
                $err = $e->getMessage();
                error_log("autoPushToGoogle {$op} failed for event {$localEventId} acct {$oauthAccountId}: {$err}");
            }

            if (!$ok) {
                $this->enqueueCalendarPush($syncStateId, $localEventId, $op, $err);
            }
        }
    }

    /**
     * Phase 3.2 helper: write a row to calendar_push_queue and flip the
     * parent calendar_sync_state.pending_push flag. Uses an UPSERT keyed on
     * (sync_state_id, local_event_id, op) so a rapid edit storm collapses
     * into one queue entry with an incrementing attempt counter.
     */
    private function enqueueCalendarPush(int $syncStateId, int $localEventId, string $op, ?string $error): void
    {
        if (!$this->db) {
            return;
        }
        try {
            $errSnippet = $error !== null ? mb_substr($error, 0, 500) : null;
            $stmt = $this->db->prepare("
                INSERT INTO calendar_push_queue
                    (sync_state_id, local_event_id, op, attempts, last_error, next_attempt_at)
                VALUES
                    (?, ?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE))
                ON DUPLICATE KEY UPDATE
                    attempts = attempts + 1,
                    last_error = VALUES(last_error),
                    next_attempt_at = DATE_ADD(NOW(), INTERVAL LEAST(60, POW(2, LEAST(attempts, 6))) MINUTE),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$syncStateId, $localEventId, $op, $errSnippet]);

            $upd = $this->db->prepare("UPDATE calendar_sync_state SET pending_push = 1 WHERE id = ?");
            $upd->execute([$syncStateId]);
        } catch (\Throwable $e) {
            error_log('enqueueCalendarPush failed: ' . $e->getMessage());
        }
    }

    /**
     * Get a single calendar
     */
    public function getCalendar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $calendar = $this->calendarService->getCalendar($this->getActiveEmail(), $id);
        
        if (!$calendar) {
            return Response::error('Calendar not found', 404);
        }
        
        return Response::success(['calendar' => $calendar]);
    }
    
    /**
     * Create a calendar
     */
    public function createCalendar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $name = $request->input('name', 'New Calendar');
        $color = $request->input('color', '#3b82f6');
        $isDefault = (bool)$request->input('is_default', false);
        
        $calendar = $this->calendarService->createCalendar($this->getActiveEmail(), $name, $color, $isDefault);
        
        if (!$calendar) {
            return Response::error('Failed to create calendar');
        }

        $this->broadcastCalendarUpdate((int)$calendar['id'], null, 'calendar_created', $this->getActiveEmail());
        
        return Response::success(['calendar' => $calendar], 'Calendar created');
    }
    
    /**
     * Update a calendar
     */
    public function updateCalendar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $data = [];
        
        if ($request->has('name')) $data['name'] = $request->input('name');
        if ($request->has('color')) $data['color'] = $request->input('color');
        if ($request->has('timezone')) $data['timezone'] = $request->input('timezone');
        if ($request->has('is_default')) $data['is_default'] = $request->input('is_default');
        
        $calendar = $this->calendarService->updateCalendar($this->getActiveEmail(), $id, $data);
        
        if (!$calendar) {
            return Response::error('Calendar not found', 404);
        }

        $this->broadcastCalendarUpdate($id, null, 'calendar_updated', $this->getActiveEmail());
        
        return Response::success(['calendar' => $calendar], 'Calendar updated');
    }
    
    /**
     * Delete a calendar
     */
    public function deleteCalendar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $ownerEmail = $this->getActiveEmail();
        
        if (!$this->calendarService->deleteCalendar($ownerEmail, $id)) {
            return Response::error('Calendar not found', 404);
        }

        $this->broadcastCalendarUpdate($id, null, 'calendar_deleted', $ownerEmail);
        
        return Response::success(null, 'Calendar deleted');
    }
    
    // ===== EVENT ENDPOINTS =====
    
    /**
     * List events (with optional date range) - includes shared calendar events
     */
    public function listEvents(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $calendarId = $request->getQuery('calendar_id');
        $startDate = $request->getQuery('start');
        $endDate = $request->getQuery('end');
        
        error_log("[CalendarController::listEvents] calendarId=" . var_export($calendarId, true) . " startDate=" . var_export($startDate, true) . " endDate=" . var_export($endDate, true));
        
        if ($calendarId) {
            $events = $this->calendarService->getEvents(
                $this->userEmail, 
                (int)$calendarId, 
                $startDate, 
                $endDate
            );
        } else {
            $events = $this->calendarService->getAllEvents(
                $this->getActiveEmail(), 
                $startDate, 
                $endDate
            );
            
            // Also include events from calendars shared with this user
            $userGroupIds = $this->getUserGroupIds($this->getActiveEmail());
            $sharedEvents = $this->calendarService->getSharedEvents(
                $this->getActiveEmail(), 
                $userGroupIds, 
                $startDate, 
                $endDate
            );
            
            $events = array_merge($events, $sharedEvents);
            
            // Deduplicate: shared/participant queries can overlap with owned events.
            // Use composite key of (id + virtual_id) so expanded recurrence instances
            // are kept distinct while true duplicates are removed.
            $seen = [];
            $events = array_values(array_filter($events, function($e) use (&$seen) {
                $key = $e['id'] . '_' . ($e['virtual_id'] ?? '');
                if (isset($seen[$key])) return false;
                $seen[$key] = true;
                return true;
            }));
            
            // Re-sort by start_time
            usort($events, function($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });
        }

        // Inject scheduled portal calls as virtual calendar events
        try {
            $portalCalls = $this->getScheduledPortalCalls($this->getActiveEmail(), $startDate, $endDate);
            if ($portalCalls) {
                $events = array_merge($events, $portalCalls);
                usort($events, function($a, $b) {
                    return strcmp($a['start_time'], $b['start_time']);
                });
            }
        } catch (\Throwable $e) {
            // Non-critical: don't break calendar if portal_calls table doesn't exist
        }
        
        return Response::success(['events' => $events]);
    }
    
    /**
     * Get a single event
     */
    public function getEvent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $userGroupIds = $this->getUserGroupIds($this->getActiveEmail());
        $access = $this->calendarService->getEventWithAccess($this->getActiveEmail(), $id, $userGroupIds);
        
        if (!$access) {
            return Response::error('Event not found', 404);
        }
        
        $event = $access['event'];
        $event['shared_permission'] = $access['permission']; // 'own', 'view', 'edit'
        if ($access['permission'] !== 'own') {
            $event['is_shared_event'] = true;
            $event['shared_by'] = $access['owner_email'];
        }
        
        return Response::success(['event' => $event]);
    }
    
    /**
     * Create an event
     */
    public function createEvent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $calendarId = (int)$request->input('calendar_id');
        
        if (!$calendarId) {
            // Use default calendar - getCalendars auto-creates one if none exist
            $calendars = $this->calendarService->getCalendars($this->getActiveEmail());
            $defaultCal = array_filter($calendars, fn($c) => $c['is_default']);
            $calendarId = $defaultCal ? reset($defaultCal)['id'] : ($calendars[0]['id'] ?? null);
            
            if (!$calendarId) {
                // Create a default calendar as fallback
                $newCal = $this->calendarService->createCalendar($this->getActiveEmail(), 'My Calendar', '#3b82f6', true);
                $calendarId = $newCal ? $newCal['id'] : null;
            }
            
            if (!$calendarId) {
                return Response::error('Failed to create calendar', 500);
            }
        }
        
        $data = [
            'title' => $request->input('title', 'Untitled Event'),
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'all_day' => (bool)$request->input('all_day', false),
            'timezone' => $request->input('timezone'),
            'recurrence' => $request->input('recurrence'),
            'reminders' => $request->input('reminders', []),
            'color' => $request->input('color'),
            'client_id' => $request->input('client_id'), // Client linking for time tracking
            'board_id' => $request->input('board_id') ? (int)$request->input('board_id') : null,
            'card_id' => $request->input('card_id') ? (int)$request->input('card_id') : null,
        ];
        
        if (empty($data['start_time']) || empty($data['end_time'])) {
            return Response::error('Start and end time are required');
        }
        
        $event = $this->calendarService->createEvent($this->getActiveEmail(), $calendarId, $data);
        
        if (!$event) {
            return Response::error('Failed to create event');
        }
        
        // Add participants if provided (silent import, no emails sent)
        $participants = $request->input('participants', []);
        if (!empty($participants) && $this->inviteService) {
            $emails = [];
            foreach ($participants as $p) {
                $email = is_array($p) ? ($p['email'] ?? '') : (string)$p;
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
            if ($emails) {
                $this->inviteService->importParticipants(
                    (int)$event['id'],
                    $emails,
                    $this->getActiveEmail()
                );
                $event['participants'] = $this->inviteService->getParticipants((int)$event['id']);
            }
        }
        
        $this->bridgeCalendarEventTime($event);

        // Broadcast to owner + shared users
        $this->broadcastCalendarUpdate($calendarId, $event['id'], 'event_created');

        // Phase 3.2: push to any linked Google calendars (inline; enqueues on failure)
        $this->autoPushToGoogle((int)$calendarId, (int)$event['id'], 'create_update');

        return Response::success(['event' => $event], 'Event created');
    }
    
    /**
     * Update an event (supports shared calendar events with edit permission)
     */
    public function updateEvent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $data = [];
        
        if ($request->has('title')) $data['title'] = $request->input('title');
        if ($request->has('description')) $data['description'] = $request->input('description');
        if ($request->has('location')) $data['location'] = $request->input('location');
        if ($request->has('start_time')) $data['start_time'] = $request->input('start_time');
        if ($request->has('end_time')) $data['end_time'] = $request->input('end_time');
        if ($request->has('all_day')) $data['all_day'] = $request->input('all_day');
        if ($request->has('recurrence')) $data['recurrence'] = $request->input('recurrence');
        if ($request->has('reminders')) $data['reminders'] = $request->input('reminders');
        if ($request->has('color')) $data['color'] = $request->input('color');
        if ($request->has('calendar_id')) $data['calendar_id'] = $request->input('calendar_id');
        // Client linking for time tracking
        if (array_key_exists('client_id', $request->input() ?? [])) {
            $data['client_id'] = $request->input('client_id');
        }
        if (array_key_exists('board_id', $request->input() ?? [])) {
            $data['board_id'] = $request->input('board_id') ? (int)$request->input('board_id') : null;
        }
        if (array_key_exists('card_id', $request->input() ?? [])) {
            $data['card_id'] = $request->input('card_id') ? (int)$request->input('card_id') : null;
        }
        if ($request->has('is_meeting')) {
            $data['is_meeting'] = (bool)$request->input('is_meeting');
        }
        
        $userGroupIds = $this->getUserGroupIds($this->getActiveEmail());
        $access = $this->calendarService->getEventWithAccess($this->getActiveEmail(), $id, $userGroupIds);
        if (!$access) {
            return Response::error('Event not found', 404);
        }
        $oldEvent = $access['event'];
        
        if (!empty($data['is_meeting']) && empty($oldEvent['meeting_token'])) {
            $data['meeting_token'] = $this->calendarService->generateMeetingToken();
        }
        
        $event = $this->calendarService->updateEventWithAccess($this->getActiveEmail(), $id, $data, $userGroupIds);
        
        if (!$event) {
            return Response::error('Event not found or no edit permission', 404);
        }
        
        // Sync participants if provided
        $participants = $request->input('participants');
        if (is_array($participants) && $this->inviteService) {
            $newEmails = [];
            foreach ($participants as $p) {
                $email = is_array($p) ? ($p['email'] ?? '') : (string)$p;
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $newEmails[] = strtolower(trim($email));
                }
            }
            
            $existing = $this->inviteService->getParticipants($id);
            $existingEmails = array_map(fn($p) => strtolower($p['user_email']), $existing);
            
            // Add new participants
            $toAdd = array_diff($newEmails, $existingEmails);
            if ($toAdd) {
                $this->inviteService->importParticipants($id, array_values($toAdd), $this->getActiveEmail());
            }
            
            // Remove participants no longer in the list
            $toRemove = array_diff($existingEmails, $newEmails);
            foreach ($toRemove as $email) {
                $this->inviteService->removeParticipant($id, $email);
            }
            
            $event['participants'] = $this->inviteService->getParticipants($id);
        }
        
        $this->bridgeCalendarEventTime($event);

        try {
            $g = new GuestCallService($this->config);
            $wasMeeting = !empty($oldEvent['is_meeting']);
            $nowMeeting = !empty($event['is_meeting']);
            $org = $this->getActiveEmail();
            if ($g->hasPolymorphicOwnerColumns()) {
                if ($nowMeeting) {
                    if (!$wasMeeting) {
                        $waiting = (bool)$request->input('waiting_room', false);
                        $hidden = (bool)$request->input('participants_hidden', false);
                        $g->ensureCalendarMeetingAndGetUrls((int)$event['id'], $org, (string)$event['start_time'], $waiting, $hidden, []);
                    } elseif (($oldEvent['start_time'] ?? '') !== ($event['start_time'] ?? '')) {
                        $newExp = $g->calendarGuestExpiryUtcMysql((string)$event['start_time']);
                        $g->extendTokensTtlForCalendarEvent((int)$event['id'], $newExp);
                    }
                } elseif ($wasMeeting && !$nowMeeting) {
                    $g->revokeTokensForCalendarEvent((int)$event['id']);
                }
            }
        } catch (\Throwable $e) {
            error_log('CalendarController updateEvent meeting tokens: ' . $e->getMessage());
        }

        // Broadcast update to owner + all shared users
        $this->broadcastCalendarUpdate($event['calendar_id'], $id, 'event_updated');

        // Phase 3.2: push update to any linked Google calendars
        $this->autoPushToGoogle((int)$event['calendar_id'], (int)$id, 'create_update');

        return Response::success(['event' => $event], 'Event updated');
    }
    
    /**
     * Delete an event (supports shared calendar events with edit permission)
     */
    public function deleteEvent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        // First get the event to know calendar_id for broadcasting
        $userGroupIds = $this->getUserGroupIds($this->getActiveEmail());
        $access = $this->calendarService->getEventWithAccess($this->getActiveEmail(), $id, $userGroupIds);
        
        if (!$access) {
            return Response::error('Event not found', 404);
        }
        
        $calendarId = $access['event']['calendar_id'];
        $ownerEmail = $access['owner_email'];
        $wasMeeting = !empty($access['event']['is_meeting']);
        
        if ($wasMeeting) {
            try {
                $g = new GuestCallService($this->config);
                if ($g->hasPolymorphicOwnerColumns()) {
                    $g->revokeTokensForCalendarEvent($id);
                }
            } catch (\Throwable $e) {
                error_log('CalendarController deleteEvent revoke meeting: ' . $e->getMessage());
            }
        }
        
        if (!$this->calendarService->deleteEventWithAccess($this->getActiveEmail(), $id, $userGroupIds)) {
            return Response::error('Event not found or no edit permission', 404);
        }
        
        // Broadcast delete to owner + all shared users
        $this->broadcastCalendarUpdate($calendarId, $id, 'event_deleted', $ownerEmail);

        // Phase 3.2: push delete to any linked Google calendars
        $this->autoPushToGoogle((int)$calendarId, (int)$id, 'delete');

        return Response::success(null, 'Event deleted');
    }
    
    /**
     * Delete all events
     */
    public function deleteAllEvents(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $count = $this->calendarService->deleteAllEvents($this->getActiveEmail());

        foreach ($this->calendarService->getCalendars($this->getActiveEmail()) as $calendar) {
            $this->broadcastCalendarUpdate((int)$calendar['id'], null, 'events_cleared', $this->getActiveEmail());
        }
        
        return Response::success(['count' => $count], "Deleted $count events");
    }
    
    /**
     * Quick add event
     */
    public function quickAdd(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $text = $request->input('text');
        $calendarId = $request->input('calendar_id');
        
        if (empty($text)) {
            return Response::error('Event text is required');
        }
        
        if (!$calendarId) {
            // getCalendars auto-creates default if none exist
            $calendars = $this->calendarService->getCalendars($this->getActiveEmail());
            $defaultCal = array_filter($calendars, fn($c) => $c['is_default']);
            $calendarId = $defaultCal ? reset($defaultCal)['id'] : ($calendars[0]['id'] ?? null);
            
            if (!$calendarId) {
                // Create a default calendar as fallback
                $newCal = $this->calendarService->createCalendar($this->getActiveEmail(), 'My Calendar', '#3b82f6', true);
                $calendarId = $newCal ? $newCal['id'] : null;
            }
        }
        
        if (!$calendarId) {
            return Response::error('Failed to create calendar', 500);
        }
        
        $event = $this->calendarService->quickAdd($this->getActiveEmail(), (int)$calendarId, $text);
        
        if (!$event) {
            return Response::error('Failed to create event');
        }

        $this->broadcastCalendarUpdate((int)$calendarId, (int)$event['id'], 'event_created');
        
        return Response::success(['event' => $event], 'Event created');
    }
    
    /**
     * Export calendar as ICS file
     */
    public function exportICS(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $ics = $this->calendarService->exportICS($this->getActiveEmail(), $id);
        
        if (!$ics) {
            return Response::error('Calendar not found', 404);
        }
        
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendar.ics"');
        echo $ics;
        exit;
    }

    /**
     * Import an .ics file into a calendar (idempotent by UID).
     * Accepts a multipart file upload (field "file") or JSON body
     * { data, calendar_id }. Used by the in-app importer and the Panel
     * migration tooling.
     */
    public function importICS(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $data = null;
        $file = $request->getFile('file');
        if ($file && ($file['error'] ?? 1) === 0 && is_uploaded_file($file['tmp_name'])) {
            $data = file_get_contents($file['tmp_name']);
        } else {
            $data = (string) $request->input('data', '');
        }
        if ($data === null || trim($data) === '') {
            return Response::error('No calendar data provided', 400);
        }

        $importer = new \Webmail\Addons\Calendar\Services\IcsImportService($this->config);
        $requestedCalId = (int) ($request->input('calendar_id') ?? $request->getQuery('calendar_id', 0));
        $calId = $importer->resolveCalendarId($email, $requestedCalId ?: null);
        $result = $importer->importIcs($email, $calId, $data);

        $this->broadcastCalendarUpdate($calId, null, 'events_imported', $email);
        return Response::success($result, "Imported {$result['imported']} new events, updated {$result['updated']}");
    }

    // ===== CALENDAR SUBSCRIPTION ENDPOINTS =====
    
    /**
     * Get or create subscription URL for a calendar
     * GET /calendars/{id}/subscription
     */
    public function getSubscription(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $token = $this->calendarService->getOrCreateSubscriptionToken($this->getActiveEmail(), $id);
        
        if (!$token) {
            return Response::error('Calendar not found', 404);
        }
        
        // Build subscription URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host;
        
        return Response::success([
            'token' => $token,
            'webcal_url' => 'webcal://' . $host . '/api/calendar/subscribe/' . $token,
            'https_url' => $baseUrl . '/api/calendar/subscribe/' . $token,
        ]);
    }
    
    /**
     * Regenerate subscription token (invalidates old URL)
     * POST /calendars/{id}/subscription/regenerate
     */
    public function regenerateSubscription(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $token = $this->calendarService->regenerateSubscriptionToken($this->getActiveEmail(), $id);
        
        if (!$token) {
            return Response::error('Calendar not found', 404);
        }
        
        // Build subscription URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host;
        
        return Response::success([
            'token' => $token,
            'webcal_url' => 'webcal://' . $host . '/api/calendar/subscribe/' . $token,
            'https_url' => $baseUrl . '/api/calendar/subscribe/' . $token,
        ]);
    }
    
    /**
     * Public endpoint: Get calendar ICS by subscription token
     * GET /calendar/subscribe/{token}
     * No authentication required - token IS the auth
     */
    public function subscribeICS(Request $request): Response
    {
        $token = $request->getParam('token');
        
        if (!$token || strlen($token) < 32) {
            return Response::error('Invalid subscription token', 400);
        }
        
        // Initialize CalendarService for public endpoint (no auth required)
        // Must create a new instance since this endpoint doesn't have authentication
        try {
            $calendarService = $this->calendarService;
            if (!$calendarService) {
                $calendarService = new CalendarService($this->config);
            }
        } catch (\Exception $e) {
            error_log("[CalendarController::subscribeICS] Failed to create CalendarService: " . $e->getMessage());
            return Response::error('Service unavailable', 503);
        }
        
        if (!$calendarService) {
            error_log("[CalendarController::subscribeICS] CalendarService is null after initialization");
            return Response::error('Service unavailable', 503);
        }
        
        $ics = $calendarService->exportICSByToken($token);
        
        if (!$ics) {
            return Response::error('Calendar not found or subscription disabled', 404);
        }
        
        // iOS-compatible headers
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="calendar.ics"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        // CORS headers for subscription clients (iCal feeds are public, token-authenticated via URL, no cookies)
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        echo $ics;
        exit;
    }
    
    // ===== CALENDAR SHARING ENDPOINTS =====
    
    /**
     * Share a calendar with a user or group
     * POST /calendars/{id}/share
     * Body: { target_email?: string, group_id?: int, permission: 'view'|'edit', can_see_details: bool }
     */
    public function shareCalendar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $calendarId = (int)$request->getParam('id');
        $targetEmail = $request->input('target_email');
        $groupId = $request->input('group_id');
        $permission = $request->input('permission', 'view');
        $canSeeDetails = $request->input('can_see_details', true);
        
        // Handle boolean/string for can_see_details
        if (is_string($canSeeDetails)) {
            $canSeeDetails = $canSeeDetails === 'true' || $canSeeDetails === '1';
        }
        
        if (!$targetEmail && !$groupId) {
            return Response::error('Either target_email or group_id is required', 400);
        }
        
        if (!in_array($permission, ['view', 'edit'])) {
            return Response::error('Permission must be "view" or "edit"', 400);
        }
        
        if ($targetEmail) {
            $result = $this->calendarService->shareWithUser(
                $this->getActiveEmail(), $calendarId, $targetEmail, $permission, $canSeeDetails
            );
        } else {
            $result = $this->calendarService->shareWithGroup(
                $this->getActiveEmail(), $calendarId, (int)$groupId, $permission, $canSeeDetails
            );
        }
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        // Notify shared recipients so their calendar list updates in real-time
        $this->broadcastCalendarUpdate($calendarId, null, 'calendar_shared');
        
        return Response::success(null, 'Calendar shared');
    }
    
    /**
     * Remove a calendar share
     * DELETE /calendars/{id}/share
     * Body: { target_email?: string, group_id?: int }
     */
    public function unshareCalendar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $calendarId = (int)$request->getParam('id');
        $targetEmail = $request->input('target_email');
        $groupId = $request->input('group_id');
        
        if (!$targetEmail && !$groupId) {
            return Response::error('Either target_email or group_id is required', 400);
        }
        
        // Broadcast BEFORE removing (so we still know the recipients)
        $this->broadcastCalendarUpdate($calendarId, null, 'calendar_unshared');
        
        if ($targetEmail) {
            $success = $this->calendarService->unshareWithUser(
                $this->getActiveEmail(), $calendarId, $targetEmail
            );
        } else {
            $success = $this->calendarService->unshareWithGroup(
                $this->getActiveEmail(), $calendarId, (int)$groupId
            );
        }
        
        if (!$success) {
            return Response::error('Share not found', 404);
        }
        
        return Response::success(null, 'Share removed');
    }
    
    /**
     * Get all shares for a calendar
     * GET /calendars/{id}/shares
     */
    public function getCalendarShares(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $calendarId = (int)$request->getParam('id');
        $shares = $this->calendarService->getCalendarShares($this->getActiveEmail(), $calendarId);
        
        return Response::success(['shares' => $shares]);
    }
    
    // ===== GOOGLE CALENDAR SYNC ENDPOINTS =====
    
    /**
     * Get Google calendars for an OAuth account
     * GET /calendar/google/calendars?account_id=X
     */
    public function getGoogleCalendars(Request $request): Response
    {
        error_log("getGoogleCalendars called - userEmail: " . ($this->userEmail ?? 'null'));
        
        $authError = $this->requireAuth($request);
        if ($authError) {
            error_log("getGoogleCalendars auth error");
            return $authError;
        }
        
        if (!$this->googleCalendarService) {
            error_log("getGoogleCalendars - no service");
            return Response::error('Google Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->getQuery('account_id');
        error_log("getGoogleCalendars - accountId from query: " . $accountId);
        if (!$accountId) {
            return Response::error('Account ID is required', 400);
        }
        
        $calendars = $this->googleCalendarService->getGoogleCalendars($this->getActiveEmail(), $accountId);
        error_log("getGoogleCalendars - got " . count($calendars) . " calendars");
        
        return Response::success(['calendars' => $calendars]);
    }
    
    /**
     * Setup sync between local and Google calendar
     * POST /calendar/google/sync
     */
    public function setupGoogleSync(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->googleCalendarService) {
            return Response::error('Google Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->input('account_id');
        $googleCalendarId = $request->input('google_calendar_id');
        $localCalendarId = (int)$request->input('local_calendar_id');
        
        if (!$accountId || !$googleCalendarId || !$localCalendarId) {
            return Response::error('Account ID, Google Calendar ID, and Local Calendar ID are required', 400);
        }
        
        $syncState = $this->googleCalendarService->setupSync(
            $this->getActiveEmail(),
            $accountId,
            $googleCalendarId,
            $localCalendarId
        );
        
        if (!$syncState) {
            return Response::error('Failed to setup sync', 500);
        }

        // Phase 3.6: kick off a push channel so we hear about changes in
        // near-real-time. Best-effort; cron is the fallback.
        if (!empty($syncState['id'])) {
            try {
                $this->googleCalendarService->watchCalendar((int)$syncState['id']);
            } catch (\Throwable $e) {
                error_log('[setupGoogleSync] watchCalendar failed: ' . $e->getMessage());
            }
        }

        return Response::success(['sync' => $syncState], 'Sync configured');
    }

    /**
     * Batched Google sync setup: one HTTP call enables sync for many
     * Google calendars mapped to the same local calendar. Avoids the
     * Settings-page lag of firing N sequential requests when the user
     * ticks multiple calendars at once.
     *
     * Body: { account_id, google_calendar_ids: string[], local_calendar_id }
     * POST /calendar/google/sync-batch
     */
    public function setupGoogleSyncBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->googleCalendarService) {
            return Response::error('Google Calendar sync not configured', 400);
        }

        $accountId = (int)$request->input('account_id');
        $googleCalendarIds = (array)$request->input('google_calendar_ids', []);
        $localCalendarId = (int)$request->input('local_calendar_id');

        if (!$accountId || !$localCalendarId || empty($googleCalendarIds)) {
            return Response::error('account_id, local_calendar_id, and google_calendar_ids are required', 400);
        }

        $googleCalendarIds = array_values(array_unique(array_filter(array_map('strval', $googleCalendarIds))));
        $googleCalendarIds = array_slice($googleCalendarIds, 0, 50);

        $success = 0;
        $failed = 0;
        $alreadySynced = 0;
        $errors = [];

        foreach ($googleCalendarIds as $gcalId) {
            $existing = $this->googleCalendarService->getSyncState($accountId, $gcalId);
            if ($existing) {
                $alreadySynced++;
                continue;
            }
            try {
                $syncState = $this->googleCalendarService->setupSync(
                    $this->getActiveEmail(),
                    $accountId,
                    $gcalId,
                    $localCalendarId
                );
                if ($syncState) {
                    $success++;
                    // Phase 3.6: set up the Google push channel for this
                    // freshly-configured calendar. Best-effort.
                    if (!empty($syncState['id'])) {
                        try {
                            $this->googleCalendarService->watchCalendar((int)$syncState['id']);
                        } catch (\Throwable $e) {
                            error_log('[setupGoogleSyncBatch] watchCalendar failed: ' . $e->getMessage());
                        }
                    }
                } else {
                    $failed++;
                    $errors[] = "{$gcalId}: setupSync returned null";
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "{$gcalId}: " . $e->getMessage();
                error_log("[setupGoogleSyncBatch] {$gcalId}: " . $e->getMessage());
            }
        }

        return Response::success([
            'success' => $success,
            'failed' => $failed,
            'already_synced' => $alreadySynced,
            'errors' => $errors,
        ], "{$success} configured, {$failed} failed, {$alreadySynced} already synced");
    }
    
    /**
     * Get sync configurations for an account
     * GET /calendar/google/sync?account_id=X
     */
    public function getGoogleSyncConfigs(Request $request): Response
    {
        error_log("getGoogleSyncConfigs called - userEmail: " . ($this->userEmail ?? 'null'));
        
        $authError = $this->requireAuth($request);
        if ($authError) {
            error_log("getGoogleSyncConfigs auth error");
            return $authError;
        }
        
        if (!$this->googleCalendarService) {
            error_log("getGoogleSyncConfigs - no service");
            return Response::error('Google Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->getQuery('account_id');
        error_log("getGoogleSyncConfigs - accountId: " . $accountId);
        if (!$accountId) {
            return Response::error('Account ID is required', 400);
        }
        
        $configs = $this->googleCalendarService->getSyncConfigs($accountId);
        
        return Response::success(['configs' => $configs]);
    }
    
    /**
     * Sync events from Google Calendar
     * POST /calendar/google/sync/pull
     */
    public function syncFromGoogle(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->googleCalendarService) {
            return Response::error('Google Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->input('account_id');
        $googleCalendarId = $request->input('google_calendar_id');
        
        if (!$accountId || !$googleCalendarId) {
            return Response::error('Account ID and Google Calendar ID are required', 400);
        }
        
        $result = $this->googleCalendarService->syncFromGoogle(
            $this->getActiveEmail(),
            $accountId,
            $googleCalendarId
        );

        $syncState = $this->googleCalendarService->getSyncState($accountId, $googleCalendarId);
        if ($syncState && !empty($syncState['local_calendar_id'])) {
            $this->broadcastCalendarUpdate((int)$syncState['local_calendar_id'], null, 'google_sync_pull');
        }
        
        return Response::success($result, 'Sync completed');
    }

    /**
     * Batched pull from Google: pull many calendars in one HTTP call.
     * Deduplicates the realtime broadcast so a batch that touches the
     * same local calendar fires ONE FOLDER_UPDATE event at the end,
     * not N.
     *
     * Body: { account_id, google_calendar_ids: string[] }
     * POST /calendar/google/sync-pull-batch
     */
    public function syncFromGoogleBatchEndpoint(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->googleCalendarService) {
            return Response::error('Google Calendar sync not configured', 400);
        }

        $accountId = (int)$request->input('account_id');
        $googleCalendarIds = (array)$request->input('google_calendar_ids', []);

        if (!$accountId || empty($googleCalendarIds)) {
            return Response::error('account_id and google_calendar_ids are required', 400);
        }

        $googleCalendarIds = array_values(array_unique(array_filter(array_map('strval', $googleCalendarIds))));
        $googleCalendarIds = array_slice($googleCalendarIds, 0, 50);

        $totalImported = 0;
        $totalUpdated = 0;
        $errors = [];
        $perCalendar = [];
        $localCalendarsTouched = [];

        foreach ($googleCalendarIds as $gcalId) {
            try {
                $result = $this->googleCalendarService->syncFromGoogle(
                    $this->getActiveEmail(),
                    $accountId,
                    $gcalId
                );
                $imported = (int)($result['imported'] ?? 0);
                $updated = (int)($result['updated'] ?? 0);
                $totalImported += $imported;
                $totalUpdated += $updated;
                $perCalendar[$gcalId] = $result;

                $syncState = $this->googleCalendarService->getSyncState($accountId, $gcalId);
                if ($syncState && !empty($syncState['local_calendar_id'])) {
                    $localCalendarsTouched[(int)$syncState['local_calendar_id']] = true;
                }
            } catch (\Throwable $e) {
                $errors[] = "{$gcalId}: " . $e->getMessage();
                error_log("[syncFromGoogleBatch] {$gcalId}: " . $e->getMessage());
            }
        }

        // ONE broadcast per unique affected local calendar, not per imported event.
        foreach (array_keys($localCalendarsTouched) as $lcalId) {
            try {
                $this->broadcastCalendarUpdate($lcalId, null, 'google_sync_pull');
            } catch (\Throwable $e) {
                // Non-critical
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
     * Phase 3.6: Google Calendar push notification webhook
     * POST /calendar/google/webhook
     *
     * Google posts here whenever a watched calendar changes. Auth is via
     * the X-Goog-Channel-Token HMAC we set during channels.watch — we look
     * up the channel by X-Goog-Channel-ID, compare token_hmac, and (on
     * match) trigger an immediate syncFromGoogle for the calendar. Always
     * returns 200 (or 204) so Google does not retry on our application
     * bugs; observability lives in error_log instead.
     *
     * NB: this endpoint MUST NOT require auth — Google sends no Bearer
     * token. The HMAC token IS the auth.
     */
    public function googleWebhook(Request $request): Response
    {
        if (!$this->googleCalendarService) {
            return Response::json(['status' => 'disabled'], 200);
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[strtolower($k)] = $v;
        }
        $channelId = (string)($normalized['x-goog-channel-id'] ?? $_SERVER['HTTP_X_GOOG_CHANNEL_ID'] ?? '');
        $token = (string)($normalized['x-goog-channel-token'] ?? $_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN'] ?? '');
        $resourceState = (string)($normalized['x-goog-resource-state'] ?? $_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] ?? '');

        if ($channelId === '' || $token === '') {
            return Response::json(['status' => 'missing-headers'], 200);
        }

        // The sync channel handshake fires immediately after channels.watch
        // with resourceState=sync. There is no calendar change to pull yet.
        if ($resourceState === 'sync') {
            return Response::json(['status' => 'sync-ack'], 200);
        }

        try {
            $result = $this->googleCalendarService->syncForChannel($channelId, $token);
            if ($result === null) {
                error_log("[CalendarController] webhook channel unknown or token mismatch: {$channelId}");
                return Response::json(['status' => 'rejected'], 200);
            }
        } catch (\Throwable $e) {
            error_log('[CalendarController] webhook sync error: ' . $e->getMessage());
            return Response::json(['status' => 'error'], 200);
        }

        return Response::json(['status' => 'ok'], 200);
    }

    /**
     * Sync a local event to Google Calendar
     * POST /calendar/google/sync/push
     */
    public function syncToGoogle(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->googleCalendarService) {
            return Response::error('Google Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->input('account_id');
        $eventId = (int)$request->input('event_id');
        
        if (!$accountId || !$eventId) {
            return Response::error('Account ID and Event ID are required', 400);
        }
        
        $googleEventId = $this->googleCalendarService->syncToGoogle(
            $this->getActiveEmail(),
            $accountId,
            $eventId
        );
        
        if (!$googleEventId) {
            return Response::error('Failed to sync event to Google', 500);
        }

        $userGroupIds = $this->getUserGroupIds($this->getActiveEmail());
        $access = $this->calendarService->getEventWithAccess($this->getActiveEmail(), $eventId, $userGroupIds);
        if ($access) {
            $this->broadcastCalendarUpdate((int)$access['event']['calendar_id'], $eventId, 'google_sync_push', $access['owner_email'] ?? null);
        }
        
        return Response::success(['google_event_id' => $googleEventId], 'Event synced to Google');
    }
    
    /**
     * Disable sync for a calendar
     * DELETE /calendar/google/sync
     */
    public function disableGoogleSync(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->googleCalendarService) {
            return Response::error('Google Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->input('account_id');
        $googleCalendarId = $request->input('google_calendar_id');
        
        if (!$accountId || !$googleCalendarId) {
            return Response::error('Account ID and Google Calendar ID are required', 400);
        }
        
        $this->googleCalendarService->disableSync($accountId, $googleCalendarId);
        
        return Response::success(null, 'Sync disabled');
    }
    
    /**
     * Disable sync with options (keep or delete events)
     * POST /calendar/google/desync
     */
    public function desyncWithOptions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->googleCalendarService) {
            return Response::error('Google Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->input('account_id');
        $googleCalendarId = $request->input('google_calendar_id');
        $deleteEventsInput = $request->input('delete_events', false);
        // Handle both boolean and string values
        $deleteEvents = $deleteEventsInput === true || $deleteEventsInput === 'true' || $deleteEventsInput === 1 || $deleteEventsInput === '1';
        
        if (!$accountId || !$googleCalendarId) {
            return Response::error('Account ID and Google Calendar ID are required', 400);
        }
        
        error_log("OAuth Desync request: accountId=$accountId, googleCalendarId=$googleCalendarId, deleteEvents=" . ($deleteEvents ? 'true' : 'false'));
        
        if ($deleteEvents) {
            $result = $this->googleCalendarService->desyncWithCleanup(
                $accountId,
                $googleCalendarId,
                GoogleCalendarService::CONNECTION_OAUTH
            );
            error_log("OAuth DesyncWithCleanup result: " . json_encode($result));
        } else {
            $result = $this->googleCalendarService->desyncKeepEvents(
                $accountId,
                $googleCalendarId,
                GoogleCalendarService::CONNECTION_OAUTH
            );
        }
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to disable sync', 500);
        }

        $syncState = $this->googleCalendarService->getSyncState($accountId, $googleCalendarId);
        $localCalendarId = $syncState['local_calendar_id'] ?? ($result['local_calendar_id'] ?? null);
        if ($localCalendarId) {
            $this->broadcastCalendarUpdate((int)$localCalendarId, null, 'google_desync');
        }
        
        return Response::success($result, 'Sync disabled');
    }
    
    /**
     * Get synced events count for a calendar
     * GET /calendar/google/sync/events-count
     */
    public function getSyncedEventsCount(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->googleCalendarService) {
            return Response::error('Google Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->getQuery('account_id');
        $googleCalendarId = $request->getQuery('google_calendar_id');
        
        if (!$accountId || !$googleCalendarId) {
            return Response::error('Account ID and Google Calendar ID are required', 400);
        }
        
        $count = $this->googleCalendarService->getSyncedEventsCount(
            $accountId,
            $googleCalendarId,
            GoogleCalendarService::CONNECTION_OAUTH
        );
        
        return Response::success(['count' => $count]);
    }
    
    // ==================== Microsoft Calendar Sync ====================
    
    /**
     * Get Microsoft/Outlook calendars for an OAuth account
     * GET /calendar/microsoft/calendars
     */
    public function getMicrosoftCalendars(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->microsoftCalendarService) {
            return Response::error('Microsoft Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->getQuery('account_id');
        if (!$accountId) {
            return Response::error('Account ID is required', 400);
        }
        
        $calendars = $this->microsoftCalendarService->getCalendars($this->getActiveEmail(), $accountId);
        
        return Response::success(['calendars' => $calendars]);
    }
    
    /**
     * Get Microsoft sync configurations
     * GET /calendar/microsoft/sync
     */
    public function getMicrosoftSyncConfigs(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->microsoftCalendarService) {
            return Response::error('Microsoft Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->getQuery('account_id');
        if (!$accountId) {
            return Response::error('Account ID is required', 400);
        }
        
        $configs = $this->microsoftCalendarService->getSyncConfigs($accountId);
        
        return Response::success(['configs' => $configs]);
    }
    
    /**
     * Setup Microsoft calendar sync
     * POST /calendar/microsoft/sync
     */
    public function setupMicrosoftSync(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->microsoftCalendarService) {
            return Response::error('Microsoft Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->input('account_id');
        $msCalendarId = $request->input('ms_calendar_id');
        $localCalendarId = (int)$request->input('local_calendar_id');
        
        if (!$accountId || !$msCalendarId || !$localCalendarId) {
            return Response::error('Account ID, Microsoft Calendar ID, and local calendar ID are required', 400);
        }
        
        $result = $this->microsoftCalendarService->setupSync(
            $accountId,
            $msCalendarId,
            $localCalendarId,
            MicrosoftCalendarService::CONNECTION_OAUTH
        );
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to setup sync', 500);
        }
        
        return Response::success($result, 'Microsoft Calendar sync configured');
    }

    /**
     * Batched Microsoft sync setup: mirror of setupGoogleSyncBatch.
     * Body: { account_id, ms_calendar_ids: string[], local_calendar_id }
     * POST /calendar/microsoft/sync-batch
     */
    public function setupMicrosoftSyncBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->microsoftCalendarService) {
            return Response::error('Microsoft Calendar sync not configured', 400);
        }

        $accountId = (int)$request->input('account_id');
        $msCalendarIds = (array)$request->input('ms_calendar_ids', []);
        $localCalendarId = (int)$request->input('local_calendar_id');

        if (!$accountId || !$localCalendarId || empty($msCalendarIds)) {
            return Response::error('account_id, local_calendar_id, and ms_calendar_ids are required', 400);
        }

        $msCalendarIds = array_values(array_unique(array_filter(array_map('strval', $msCalendarIds))));
        $msCalendarIds = array_slice($msCalendarIds, 0, 50);

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($msCalendarIds as $msId) {
            try {
                $result = $this->microsoftCalendarService->setupSync(
                    $accountId,
                    $msId,
                    $localCalendarId,
                    MicrosoftCalendarService::CONNECTION_OAUTH
                );
                if (!empty($result['success'])) {
                    $success++;
                } else {
                    $failed++;
                    $errors[] = "{$msId}: " . ($result['error'] ?? 'unknown');
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "{$msId}: " . $e->getMessage();
                error_log("[setupMicrosoftSyncBatch] {$msId}: " . $e->getMessage());
            }
        }

        return Response::success([
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ], "{$success} configured, {$failed} failed");
    }

    /**
     * Pull events from Microsoft Calendar
     * POST /calendar/microsoft/sync/pull
     */
    public function pullFromMicrosoftCalendar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->microsoftCalendarService) {
            return Response::error('Microsoft Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->input('account_id');
        $msCalendarId = $request->input('ms_calendar_id');
        
        if (!$accountId || !$msCalendarId) {
            return Response::error('Account ID and Microsoft Calendar ID are required', 400);
        }
        
        $result = $this->microsoftCalendarService->syncFromMicrosoft(
            $this->getActiveEmail(),
            $accountId,
            $msCalendarId
        );

        $syncState = $this->microsoftCalendarService->getSyncState($accountId, $msCalendarId, MicrosoftCalendarService::CONNECTION_OAUTH);
        if ($syncState && !empty($syncState['local_calendar_id'])) {
            $this->broadcastCalendarUpdate((int)$syncState['local_calendar_id'], null, 'microsoft_sync_pull');
        }
        
        return Response::success($result, 'Microsoft Calendar synced');
    }

    /**
     * Batched Microsoft pull: mirror of syncFromGoogleBatch. One HTTP
     * call, one broadcast per unique affected local calendar.
     * Body: { account_id, ms_calendar_ids: string[] }
     * POST /calendar/microsoft/sync-pull-batch
     */
    public function pullFromMicrosoftCalendarBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->microsoftCalendarService) {
            return Response::error('Microsoft Calendar sync not configured', 400);
        }

        $accountId = (int)$request->input('account_id');
        $msCalendarIds = (array)$request->input('ms_calendar_ids', []);

        if (!$accountId || empty($msCalendarIds)) {
            return Response::error('account_id and ms_calendar_ids are required', 400);
        }

        $msCalendarIds = array_values(array_unique(array_filter(array_map('strval', $msCalendarIds))));
        $msCalendarIds = array_slice($msCalendarIds, 0, 50);

        $totalImported = 0;
        $totalUpdated = 0;
        $errors = [];
        $perCalendar = [];
        $localCalendarsTouched = [];

        foreach ($msCalendarIds as $msId) {
            try {
                $result = $this->microsoftCalendarService->syncFromMicrosoft(
                    $this->getActiveEmail(),
                    $accountId,
                    $msId
                );
                $totalImported += (int)($result['imported'] ?? 0);
                $totalUpdated += (int)($result['updated'] ?? 0);
                $perCalendar[$msId] = $result;

                $syncState = $this->microsoftCalendarService->getSyncState($accountId, $msId, MicrosoftCalendarService::CONNECTION_OAUTH);
                if ($syncState && !empty($syncState['local_calendar_id'])) {
                    $localCalendarsTouched[(int)$syncState['local_calendar_id']] = true;
                }
            } catch (\Throwable $e) {
                $errors[] = "{$msId}: " . $e->getMessage();
                error_log("[pullFromMicrosoftCalendarBatch] {$msId}: " . $e->getMessage());
            }
        }

        foreach (array_keys($localCalendarsTouched) as $lcalId) {
            try {
                $this->broadcastCalendarUpdate($lcalId, null, 'microsoft_sync_pull');
            } catch (\Throwable $e) {
                // Non-critical
            }
        }

        return Response::success([
            'imported' => $totalImported,
            'updated' => $totalUpdated,
            'errors' => $errors,
            'per_calendar' => $perCalendar,
        ], "Synced {$totalImported} new, {$totalUpdated} updated across " . count($msCalendarIds) . " Microsoft calendar(s)");
    }
    
    /**
     * Disable Microsoft calendar sync
     * POST /calendar/microsoft/desync
     */
    public function desyncMicrosoft(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->microsoftCalendarService) {
            return Response::error('Microsoft Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->input('account_id');
        $msCalendarId = $request->input('ms_calendar_id');
        $deleteEventsInput = $request->input('delete_events', false);
        $deleteEvents = $deleteEventsInput === true || $deleteEventsInput === 'true' || $deleteEventsInput === 1 || $deleteEventsInput === '1';
        
        if (!$accountId || !$msCalendarId) {
            return Response::error('Account ID and Microsoft Calendar ID are required', 400);
        }
        
        if ($deleteEvents) {
            $result = $this->microsoftCalendarService->desyncWithCleanup(
                $accountId,
                $msCalendarId,
                MicrosoftCalendarService::CONNECTION_OAUTH
            );
        } else {
            $result = $this->microsoftCalendarService->desyncKeepEvents(
                $accountId,
                $msCalendarId,
                MicrosoftCalendarService::CONNECTION_OAUTH
            );
        }
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to disable sync', 500);
        }

        $syncState = $this->microsoftCalendarService->getSyncState($accountId, $msCalendarId, MicrosoftCalendarService::CONNECTION_OAUTH);
        $localCalendarId = $syncState['local_calendar_id'] ?? ($result['local_calendar_id'] ?? null);
        if ($localCalendarId) {
            $this->broadcastCalendarUpdate((int)$localCalendarId, null, 'microsoft_desync');
        }
        
        return Response::success($result, 'Microsoft Calendar sync disabled');
    }
    
    /**
     * Get Microsoft synced events count
     * GET /calendar/microsoft/sync/events-count
     */
    public function getMicrosoftSyncedEventsCount(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->microsoftCalendarService) {
            return Response::error('Microsoft Calendar sync not configured', 400);
        }
        
        $accountId = (int)$request->getQuery('account_id');
        $msCalendarId = $request->getQuery('ms_calendar_id');
        
        if (!$accountId || !$msCalendarId) {
            return Response::error('Account ID and Microsoft Calendar ID are required', 400);
        }
        
        $count = $this->microsoftCalendarService->getSyncedEventsCount(
            $accountId,
            $msCalendarId,
            MicrosoftCalendarService::CONNECTION_OAUTH
        );
        
        return Response::success(['count' => $count]);
    }
    
    // ==================== EVENT INVITATIONS ====================
    
    /**
     * Invite participants to an event
     * POST /events/:id/invite
     */
    public function inviteParticipants(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->inviteService) {
            return Response::error('Invitation service not available', 500);
        }
        
        $eventId = (int)$request->getParam('id');
        $emails = $request->input('emails', []);
        $importOnly = (bool)$request->input('import_only', false);
        
        if (empty($emails)) {
            return Response::error('At least one email address is required', 400);
        }
        
        if (is_string($emails)) {
            $emails = array_map('trim', explode(',', $emails));
        }
        
        $organizerEmail = $this->getActiveEmail();
        
        $meetingJoinUrl = null;
        if (!$importOnly) {
            $ev = $this->calendarService->getEvent($organizerEmail, $eventId);
            if ($ev && !empty($ev['is_meeting'])) {
                try {
                    $g = new GuestCallService($this->config);
                    if ($g->hasPolymorphicOwnerColumns()) {
                        $bundle = $g->ensureCalendarMeetingAndGetUrls(
                            $eventId,
                            $organizerEmail,
                            (string)$ev['start_time'],
                            false,
                            false,
                            ['title' => $ev['title'] ?? '']
                        );
                        $meetingJoinUrl = $bundle['guest']['link'];
                    }
                } catch (\Throwable $e) {
                    error_log('CalendarController inviteParticipants meeting URL: ' . $e->getMessage());
                }
            }
        }
        
        if ($importOnly) {
            $result = $this->inviteService->importParticipants($eventId, $emails, $organizerEmail);
        } else {
            $result = $this->inviteService->inviteParticipants($eventId, $emails, $this->userId ?? 0, $organizerEmail, $meetingJoinUrl);
        }
        
        if (isset($result['error'])) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success($result, $importOnly ? 'Participants added' : 'Invitations sent');
    }
    
    /**
     * Get participants for an event
     * GET /events/:id/participants
     */
    public function getParticipants(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->inviteService) {
            return Response::error('Invitation service not available', 500);
        }
        
        $eventId = (int)$request->getParam('id');
        $participants = $this->inviteService->getParticipants($eventId);
        
        return Response::success(['participants' => $participants]);
    }
    
    /**
     * Remove a participant from an event
     * DELETE /events/:id/participants/:email
     */
    public function removeParticipant(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->inviteService) {
            return Response::error('Invitation service not available', 500);
        }
        
        $eventId = (int)$request->getParam('id');
        $email = $request->getParam('email');
        
        if (!$email) {
            return Response::error('Email is required', 400);
        }
        
        if ($this->inviteService->removeParticipant($eventId, $email)) {
            return Response::success(null, 'Participant removed');
        }
        
        return Response::error('Participant not found', 404);
    }
    
    /**
     * Get pending invitations for current user
     * GET /events/invitations
     */
    public function getMyInvitations(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->inviteService) {
            return Response::error('Invitation service not available', 500);
        }
        
        $invitations = $this->inviteService->getPendingInvitations($this->getActiveEmail());
        
        return Response::success(['invitations' => $invitations]);
    }
    
    /**
     * Respond to an invitation via token (from email link)
     * GET /calendar/invite/:token/accept or /calendar/invite/:token/decline
     */
    public function respondToInvitation(Request $request): Response
    {
        $token = $request->getParam('token');
        $response = $request->getParam('response'); // accept or decline
        
        if (!$token || !$response) {
            return Response::error('Invalid invitation link', 400);
        }
        
        // Map URL response to database status
        $statusMap = [
            'accept' => 'accepted',
            'decline' => 'declined',
            'tentative' => 'tentative'
        ];
        
        $status = $statusMap[$response] ?? null;
        if (!$status) {
            return Response::error('Invalid response type', 400);
        }
        
        if (!$this->inviteService) {
            // Initialize invite service without auth for email links
            try {
                $db = \Webmail\Core\Database::getConnection($this->config);
                $this->inviteService = new CalendarInviteService($db, $this->config);
            } catch (\Exception $e) {
                return Response::error('Service unavailable', 500);
            }
        }
        
        $result = $this->inviteService->respondToInvitation($token, $status);
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to respond', 400);
        }
        
        // Return HTML page for email link responses
        $responseText = ucfirst($status);
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invitation Response</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f3f4f6; }
        .card { background: white; padding: 40px; border-radius: 16px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 400px; }
        .icon { font-size: 48px; margin-bottom: 16px; }
        .accepted .icon { color: #22c55e; }
        .declined .icon { color: #ef4444; }
        h1 { margin: 0 0 8px 0; color: #1a1a1a; font-size: 24px; }
        p { color: #666; margin: 0; }
    </style>
</head>
<body>
    <div class="card {$status}">
        <div class="icon">{$this->getResponseIcon($status)}</div>
        <h1>Invitation {$responseText}</h1>
        <p>Your response to "{$result['event_title']}" has been recorded.</p>
    </div>
</body>
</html>
HTML;
        
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
    
    /**
     * Respond to invitation via API (for logged-in users)
     * POST /events/invitations/:token/respond
     */
    public function respondToInvitationApi(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (!$this->inviteService) {
            return Response::error('Invitation service not available', 500);
        }
        
        $token = $request->getParam('token');
        $response = $request->input('response'); // accepted, declined, tentative
        $message = $request->input('message');
        
        if (!$token || !$response) {
            return Response::error('Token and response are required', 400);
        }
        
        $result = $this->inviteService->respondToInvitation($token, $response, $message);
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to respond', 400);
        }
        
        return Response::success($result, 'Response recorded');
    }
    
    // ==================== MEETINGS ====================
    
    /**
     * Create a meeting (event + chat conversation + meeting link)
     * POST /meetings
     */
    public function createMeeting(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $calendarId = (int)$request->input('calendar_id');
        $title = $request->input('title', 'Meeting');
        $description = $request->input('description');
        $startTime = $request->input('start_time');
        $endTime = $request->input('end_time');
        $timezone = $request->input('timezone');
        $participantEmails = $request->input('participants', []);
        $waitingRoom = (bool)$request->input('waiting_room', false);
        $participantsHidden = (bool)$request->input('participants_hidden', false);
        
        if (empty($startTime) || empty($endTime)) {
            return Response::error('Start and end time are required', 400);
        }
        
        if (!$calendarId) {
            // Use default calendar
            $calendars = $this->calendarService->getCalendars($this->getActiveEmail());
            $defaultCal = array_filter($calendars, fn($c) => $c['is_default']);
            $calendarId = $defaultCal ? reset($defaultCal)['id'] : ($calendars[0]['id'] ?? null);
            
            if (!$calendarId) {
                $newCal = $this->calendarService->createCalendar($this->getActiveEmail(), 'My Calendar', '#3b82f6', true);
                $calendarId = $newCal ? $newCal['id'] : null;
            }
            
            if (!$calendarId) {
                return Response::error('Failed to create calendar', 500);
            }
        }
        
        $meetingToken = $this->calendarService->generateMeetingToken();
        
        $conversationId = null;
        try {
            $chatService = new ChatService($this->config);
            $result = $chatService->createMeetingConversation(
                $this->getActiveEmail(),
                $title,
                $participantEmails
            );
            if ($result['success']) {
                $conversationId = $result['conversation_id'];
            }
        } catch (\Exception $e) {
            error_log("CalendarController createMeeting: Failed to create chat conversation: " . $e->getMessage());
        }
        
        $guestSvc = new GuestCallService($this->config);
        
        $data = [
            'title' => $title,
            'description' => $description,
            'location' => null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'all_day' => false,
            'timezone' => $timezone,
            'reminders' => $request->input('reminders', []),
            'color' => $request->input('color'),
            'is_meeting' => true,
            'meeting_token' => $meetingToken,
            'meeting_conversation_id' => $conversationId,
        ];
        if ($guestSvc->calendarEventsColumnExists('meeting_room_name')) {
            $data['meeting_room_name'] = null;
        }
        
        $event = $this->calendarService->createEvent($this->getActiveEmail(), $calendarId, $data);
        
        if (!$event) {
            return Response::error('Failed to create meeting event', 500);
        }
        
        $eventId = (int)$event['id'];
        $organizerEmail = $this->getActiveEmail();
        
        $meetingLink = null;
        $adminLink = null;
        $guestTokenStr = null;
        $roomName = null;
        $bundleRoomName = null;
        $bundleExpiresAt = null;
        if ($guestSvc->hasPolymorphicOwnerColumns()) {
            $roomName = $guestSvc->getDefaultCalendarRoomName($eventId);
            if ($guestSvc->calendarEventsColumnExists('meeting_room_name')) {
                $this->calendarService->updateEvent($organizerEmail, $eventId, ['meeting_room_name' => $roomName]);
                $event['meeting_room_name'] = $roomName;
            }
            $bundle = $guestSvc->createCalendarMeetingTokens(
                $eventId,
                $roomName,
                $organizerEmail,
                $guestSvc->computeCalendarMeetingTtlSeconds((string)$event['start_time']),
                $waitingRoom,
                $participantsHidden,
                [
                    'title' => $title,
                    'start_time' => $event['start_time'],
                    'organizer_email' => $organizerEmail,
                ]
            );
            $meetingLink = $bundle['guest']['link'];
            $adminLink = $bundle['admin']['link'];
            $guestTokenStr = $bundle['guest']['token'];
            $bundleRoomName = $bundle['room_name'] ?? null;
            $bundleExpiresAt = $bundle['expires_at'] ?? null;
        }
        
        $inviteResults = null;
        if (!empty($participantEmails) && $this->inviteService && $meetingLink) {
            $inviteResults = $this->inviteService->inviteParticipants(
                $event['id'],
                $participantEmails,
                $this->userId ?? 0,
                $organizerEmail,
                $meetingLink
            );
        } elseif (!empty($participantEmails) && $this->inviteService) {
            $inviteResults = $this->inviteService->inviteParticipants(
                $event['id'],
                $participantEmails,
                $this->userId ?? 0,
                $organizerEmail,
                null
            );
        }
        
        $baseUrl = $this->config['app_url'] ?? 'https://flowone.pro';
        $legacyMeetLink = "{$baseUrl}/meet/{$meetingToken}";
        
        $this->broadcastCalendarUpdate((int)$calendarId, (int)$event['id'], 'event_created');
        
        return Response::success([
            'event' => $event,
            'meeting_link' => $meetingLink ?? $legacyMeetLink,
            'admin_meeting_link' => $adminLink,
            'meeting_token' => $meetingToken,
            'guest_call_token' => $guestTokenStr,
            'conversation_id' => $conversationId,
            'invitations' => $inviteResults,
            'room_name' => $bundleRoomName ?? $roomName,
            'expires_at' => $bundleExpiresAt,
            'waiting_room_enabled' => $waitingRoom,
            'participants_hidden' => $participantsHidden,
        ], 'Meeting created');
    }

    /**
     * Upgrade an existing calendar event into a FlowOne meeting.
     *
     * Idempotent. If the event already has a meeting_token, returns the
     * existing guest/admin links instead of minting new ones. This is the
     * counterpart to createMeeting() for events that started life as a
     * regular event (e.g. imported from an .ics invite, copied from
     * Google Calendar, or created without the meeting toggle).
     *
     * POST /events/{id}/add-meeting
     * Body (optional): {
     *   waiting_room: bool, participants_hidden: bool, invite_participants: bool,
     *   force: bool   // revoke existing links and mint fresh ones (recreate)
     * }
     */
    public function addMeetingToEvent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        if (!$id) {
            return Response::error('Event id is required', 400);
        }

        $userGroupIds = $this->getUserGroupIds($this->getActiveEmail());
        $access = $this->calendarService->getEventWithAccess($this->getActiveEmail(), $id, $userGroupIds);
        if (!$access) {
            return Response::error('Event not found', 404);
        }
        $event = $access['event'];

        if (empty($event['start_time']) || empty($event['end_time'])) {
            return Response::error('Event is missing start or end time', 400);
        }

        $waitingRoom = (bool)$request->input('waiting_room', false);
        $participantsHidden = (bool)$request->input('participants_hidden', false);
        $inviteParticipants = (bool)$request->input('invite_participants', false);
        // When true, any existing guest/admin tokens are revoked first so a
        // brand-new link is minted (the "cancel & recreate" flow). The room
        // is reused, but its waiting-room/workshop settings are re-applied.
        $force = (bool)$request->input('force', false);

        $organizerEmail = (string)($event['organizer_email'] ?? $this->getActiveEmail());

        // Mark event as meeting + ensure it has a meeting_token. Both are
        // safe to re-apply if already set.
        $updateData = ['is_meeting' => true];
        if (empty($event['meeting_token'])) {
            $updateData['meeting_token'] = $this->calendarService->generateMeetingToken();
        }

        // Create a chat conversation if the event doesn't already have one.
        if (empty($event['meeting_conversation_id'])) {
            try {
                $participantsForChat = [];
                if ($this->inviteService) {
                    foreach ($this->inviteService->getParticipants($id) as $p) {
                        if (!empty($p['user_email'])) {
                            $participantsForChat[] = $p['user_email'];
                        }
                    }
                }
                $chatService = new ChatService($this->config);
                $chatResult = $chatService->createMeetingConversation(
                    $organizerEmail,
                    (string)($event['title'] ?? 'Meeting'),
                    $participantsForChat
                );
                if (!empty($chatResult['success']) && !empty($chatResult['conversation_id'])) {
                    $updateData['meeting_conversation_id'] = $chatResult['conversation_id'];
                }
            } catch (\Throwable $e) {
                error_log('CalendarController addMeetingToEvent: chat conversation failed: ' . $e->getMessage());
            }
        }

        $updatedEvent = $this->calendarService->updateEventWithAccess(
            $this->getActiveEmail(),
            $id,
            $updateData,
            $userGroupIds
        );

        if (!$updatedEvent) {
            return Response::error('No edit permission for this event', 403);
        }

        // Ensure room name + guest/admin tokens exist. Idempotent thanks to
        // ensureCalendarMeetingAndGetUrls: it reuses any existing room and
        // returns active tokens if they're still valid.
        $meetingLink = null;
        $adminLink = null;
        $roomName = null;
        $expiresAt = null;
        $guestToken = null;
        try {
            $guestSvc = new GuestCallService($this->config);
            if ($guestSvc->hasPolymorphicOwnerColumns()) {
                $lockKey = 'flowone_calmeet_' . $id;
                $lockStmt = $this->db ? $this->db->prepare('SELECT GET_LOCK(?, 15)') : null;
                if ($lockStmt) $lockStmt->execute([$lockKey]);
                try {
                    // Recreate: revoke current tokens so the ensure call below
                    // mints fresh ones. Kicks anyone currently in the room.
                    if ($force) {
                        $guestSvc->revokeTokensForCalendarEvent($id);
                    }

                    $bundle = $guestSvc->ensureCalendarMeetingAndGetUrls(
                        $id,
                        $organizerEmail,
                        (string)$updatedEvent['start_time'],
                        $waitingRoom,
                        $participantsHidden,
                        [
                            'title' => $updatedEvent['title'] ?? '',
                            'start_time' => $updatedEvent['start_time'],
                            'organizer_email' => $organizerEmail,
                        ]
                    );
                    $meetingLink = $bundle['guest']['link'] ?? null;
                    $adminLink = $bundle['admin']['link'] ?? null;
                    $guestToken = $bundle['guest']['token'] ?? null;
                    $roomName = $bundle['room_name'] ?? null;
                    $expiresAt = $bundle['expires_at'] ?? null;

                    // Enforce the chosen waiting-room / workshop settings.
                    // ensureRoom() uses INSERT IGNORE so it never updates an
                    // existing room; setSettings() upserts the flags so the
                    // toggles actually take effect (incl. on recreate).
                    if ($roomName) {
                        (new MeetingRoomService($this->config))
                            ->setSettings($roomName, $waitingRoom, $participantsHidden, $organizerEmail);
                    }

                    if ($roomName && $guestSvc->calendarEventsColumnExists('meeting_room_name')
                        && empty($updatedEvent['meeting_room_name'])) {
                        $this->calendarService->updateEventWithAccess(
                            $this->getActiveEmail(),
                            $id,
                            ['meeting_room_name' => $roomName],
                            $userGroupIds
                        );
                        $updatedEvent['meeting_room_name'] = $roomName;
                    }
                } finally {
                    if ($lockStmt) {
                        $rel = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                        $rel->execute([$lockKey]);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('CalendarController addMeetingToEvent: token mint failed: ' . $e->getMessage());
        }

        // Fallback to the legacy /meet/{token} URL if guest mint is unavailable.
        if (!$meetingLink) {
            $baseUrl = rtrim($this->config['app_url'] ?? 'https://flowone.pro', '/');
            $meetingLink = $baseUrl . '/meet/' . ($updatedEvent['meeting_token'] ?? '');
        }

        // Optionally re-send invitations with the freshly-minted meeting link.
        $inviteResults = null;
        if ($inviteParticipants && $this->inviteService && $meetingLink) {
            $emails = [];
            foreach ($this->inviteService->getParticipants($id) as $p) {
                if (!empty($p['user_email'])) $emails[] = $p['user_email'];
            }
            if ($emails) {
                $inviteResults = $this->inviteService->inviteParticipants(
                    $id,
                    $emails,
                    $this->userId ?? 0,
                    $organizerEmail,
                    $meetingLink
                );
            }
        }

        $this->broadcastCalendarUpdate((int)$updatedEvent['calendar_id'], $id, 'event_updated');

        return Response::success([
            'event' => $updatedEvent,
            'meeting_link' => $meetingLink,
            'admin_meeting_link' => $adminLink,
            'meeting_token' => $updatedEvent['meeting_token'] ?? null,
            'guest_call_token' => $guestToken,
            'conversation_id' => $updatedEvent['meeting_conversation_id'] ?? null,
            'room_name' => $roomName,
            'expires_at' => $expiresAt,
            'invitations' => $inviteResults,
            'waiting_room_enabled' => $waitingRoom,
            'participants_hidden' => $participantsHidden,
        ], 'Meeting added to event');
    }

    /**
     * Fetch the meeting links + room settings for an event that is already
     * a meeting. Read-only and idempotent: ensures the guest/admin tokens
     * exist (re-minting only if they were never created), but never revokes
     * or rotates them. Used by the edit modal to surface the host link and
     * current waiting-room / workshop state when an event is reopened.
     *
     * GET /events/{id}/meeting
     */
    public function getEventMeetingLinks(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        if (!$id) {
            return Response::error('Event id is required', 400);
        }

        $userGroupIds = $this->getUserGroupIds($this->getActiveEmail());
        $access = $this->calendarService->getEventWithAccess($this->getActiveEmail(), $id, $userGroupIds);
        if (!$access) {
            return Response::error('Event not found', 404);
        }
        $event = $access['event'];

        if (empty($event['is_meeting'])) {
            return Response::success(['is_meeting' => false], 'Not a meeting');
        }

        $organizerEmail = (string)($event['organizer_email'] ?? $this->getActiveEmail());
        $meetingLink = null;
        $adminLink = null;
        $roomName = $event['meeting_room_name'] ?? null;
        $expiresAt = null;
        $waitingRoom = false;
        $participantsHidden = false;

        try {
            $guestSvc = new GuestCallService($this->config);
            if ($guestSvc->hasPolymorphicOwnerColumns() && !empty($event['start_time'])) {
                $lockKey = 'flowone_calmeet_' . $id;
                $lockStmt = $this->db ? $this->db->prepare('SELECT GET_LOCK(?, 15)') : null;
                if ($lockStmt) $lockStmt->execute([$lockKey]);
                try {
                    $bundle = $guestSvc->ensureCalendarMeetingAndGetUrls(
                        $id,
                        $organizerEmail,
                        (string)$event['start_time'],
                        false,
                        false,
                        ['title' => $event['title'] ?? '']
                    );
                    $meetingLink = $bundle['guest']['link'] ?? null;
                    $adminLink = $bundle['admin']['link'] ?? null;
                    $roomName = $bundle['room_name'] ?? $roomName;
                    $expiresAt = $bundle['expires_at'] ?? null;
                } finally {
                    if ($lockStmt) {
                        $rel = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                        $rel->execute([$lockKey]);
                    }
                }
            }

            if ($roomName) {
                $settings = (new MeetingRoomService($this->config))->getSettings($roomName);
                if ($settings) {
                    $waitingRoom = (bool)$settings['waiting_room_enabled'];
                    $participantsHidden = (bool)$settings['participants_hidden'];
                }
            }
        } catch (\Throwable $e) {
            error_log('CalendarController getEventMeetingLinks: ' . $e->getMessage());
        }

        if (!$meetingLink) {
            $baseUrl = rtrim($this->config['app_url'] ?? 'https://flowone.pro', '/');
            $meetingLink = $baseUrl . '/meet/' . ($event['meeting_token'] ?? '');
        }

        return Response::success([
            'is_meeting' => true,
            'event_id' => $id,
            'meeting_link' => $meetingLink,
            'admin_meeting_link' => $adminLink,
            'meeting_token' => $event['meeting_token'] ?? null,
            'room_name' => $roomName,
            'expires_at' => $expiresAt,
            'waiting_room_enabled' => $waitingRoom,
            'participants_hidden' => $participantsHidden,
        ]);
    }

    /**
     * Get meeting details by token (for join page)
     * GET /meetings/:token
     * Requires authentication — user must be logged in to see meeting details
     */
    public function getMeetingByToken(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $token = $request->getParam('token');
        
        if (!$token || strlen($token) < 32) {
            return Response::error('Invalid meeting token', 400);
        }
        
        $event = $this->calendarService->getEventByMeetingToken($token);
        
        if (!$event) {
            return Response::error('Meeting not found', 404);
        }
        
        $baseUrl = rtrim($this->config['app_url'] ?? 'https://flowone.pro', '/');
        $redirectTo = null;
        $adminLink = null;
        
        if ($this->db) {
            try {
                $g = new GuestCallService($this->config);
                if ($g->hasPolymorphicOwnerColumns()) {
                    $lock = 'flowone_calmeet_' . (int)$event['id'];
                    $stmt = $this->db->prepare('SELECT GET_LOCK(?, 15)');
                    $stmt->execute([$lock]);
                    try {
                        $bundle = $g->ensureCalendarMeetingAndGetUrls(
                            (int)$event['id'],
                            (string)($event['organizer_email'] ?? $this->getActiveEmail()),
                            (string)$event['start_time'],
                            false,
                            false,
                            ['title' => $event['title'] ?? '']
                        );
                        $redirectTo = $bundle['guest']['link'];
                        $adminLink = $bundle['admin']['link'];
                    } finally {
                        $rel = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                        $rel->execute([$lock]);
                    }
                }
            } catch (\Throwable $e) {
                error_log('CalendarController getMeetingByToken guest mint: ' . $e->getMessage());
            }
        }
        
        if (!$redirectTo) {
            $redirectTo = "{$baseUrl}/meet/{$token}";
        }
        
        return Response::success([
            'event' => [
                'id' => $event['id'],
                'title' => $event['title'],
                'description' => $event['description'],
                'start_time' => $event['start_time'],
                'end_time' => $event['end_time'],
                'timezone' => $event['timezone'] ?? 'UTC',
                'organizer_email' => $event['organizer_email'],
                'meeting_conversation_id' => $event['meeting_conversation_id'],
            ],
            'meeting_link' => $redirectTo,
            'redirect_to' => $redirectTo,
            'admin_meeting_link' => $adminLink,
        ]);
    }
    
    private function getResponseIcon(string $status): string
    {
        return match($status) {
            'accepted' => '&#10003;',
            'declined' => '&#10007;',
            'tentative' => '?',
            default => ''
        };
    }

    /**
     * Fetch scheduled portal calls for the current user and map them
     * to virtual calendar event objects so they appear on the calendar.
     */
    private function getScheduledPortalCalls(string $userEmail, ?string $startDate, ?string $endDate): array
    {
        if (!$this->db) return [];

        $sql = "
            SELECT pc.id, pc.client_id, pc.room_name, pc.scheduled_at, pc.status, pc.created_by,
                   c.display_name as client_name, c.domain as client_domain
            FROM portal_calls pc
            LEFT JOIN clients c ON pc.client_id = c.id
            WHERE pc.call_type = 'scheduled'
              AND pc.scheduled_at IS NOT NULL
              AND pc.status NOT IN ('ended', 'cancelled')
              AND pc.created_by = ?
        ";
        $params = [$userEmail];

        if ($startDate) {
            $sql .= ' AND pc.scheduled_at >= ?';
            $params[] = $startDate;
        }
        if ($endDate) {
            $sql .= ' AND pc.scheduled_at <= ?';
            $params[] = $endDate;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $calls = $stmt->fetchAll();

        return array_map(function ($call) {
            $clientLabel = $call['client_name'] ?: $call['client_domain'] ?: ('Client #' . $call['client_id']);
            $scheduledDt = $call['scheduled_at'];
            $endDt = date('Y-m-d H:i:s', strtotime($scheduledDt) + 1800);

            return [
                'id' => 'portal_call_' . $call['id'],
                'calendar_id' => null,
                'uid' => 'portal-call-' . $call['id'] . '@flowone',
                'title' => 'Portal Call - ' . $clientLabel,
                'description' => 'Scheduled portal call with ' . $clientLabel,
                'location' => '',
                'start_time' => $scheduledDt,
                'end_time' => $endDt,
                'all_day' => false,
                'timezone' => 'UTC',
                'recurrence' => null,
                'reminders' => [],
                'color' => '#10b981',
                'etag' => null,
                'calendar_name' => 'Portal Calls',
                'calendar_color' => '#10b981',
                'is_synced' => false,
                'is_participant_event' => false,
                'is_portal_call' => true,
                'portal_call_id' => (int)$call['id'],
                'portal_call_status' => $call['status'],
                'portal_call_room' => $call['room_name'],
                'portal_client_id' => (int)$call['client_id'],
                'participants' => [],
                'created_at' => $scheduledDt,
                'updated_at' => $scheduledDt,
            ];
        }, $calls);
    }

    /**
     * Bridge a past calendar event's time to client tracking and work sessions
     * for each non-declined participant. Skips if already bridged or event is
     * in the future or has no client.
     */
    private function bridgeCalendarEventTime(array $event): void
    {
        if (!$this->db) return;
        if (empty($event['client_id'])) return;
        if (!empty($event['time_bridged_at'])) return;

        $endTime = $event['end_time'] ?? null;
        if (!$endTime || strtotime($endTime) > time()) return;

        $startTime = $event['start_time'] ?? $event['created_at'] ?? null;
        if (!$startTime) return;

        $allDay = !empty($event['all_day']);
        $durationSeconds = $allDay
            ? 8 * 3600
            : max(0, (int)(strtotime($endTime) - strtotime($startTime)));
        if ($durationSeconds < 60) return;

        $eventId = (int)$event['id'];
        $clientId = (int)$event['client_id'];
        $cardId = !empty($event['card_id']) ? (int)$event['card_id'] : null;
        $title = $event['title'] ?? 'Calendar event';
        $creatorEmail = null;

        try {
            $stmt = $this->db->prepare("
                SELECT c.user_email FROM calendars c
                JOIN calendar_events e ON e.calendar_id = c.id
                WHERE e.id = ?
            ");
            $stmt->execute([$eventId]);
            $creatorEmail = $stmt->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            error_log("bridgeCalendarEventTime: could not resolve creator: " . $e->getMessage());
        }

        $participantEmails = [];
        try {
            $stmt = $this->db->prepare("
                SELECT user_email FROM calendar_event_participants
                WHERE event_id = ? AND status != 'declined'
            ");
            $stmt->execute([$eventId]);
            $participantEmails = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            error_log("bridgeCalendarEventTime: participant fetch failed: " . $e->getMessage());
        }

        $emails = array_unique(array_filter(
            array_merge($creatorEmail ? [$creatorEmail] : [], $participantEmails)
        ));
        if (empty($emails)) return;

        try {
            $this->db->beginTransaction();

            foreach ($emails as $email) {
                $email = strtolower(trim($email));

                $this->db->prepare("
                    INSERT INTO webmail_client_time_tracking
                        (user_email, client_id, activity_type, entity_id, entity_name,
                         duration_seconds, tracked_date)
                    VALUES (?, ?, 'calendar_event', ?, ?, ?, DATE(?))
                    ON DUPLICATE KEY UPDATE
                        duration_seconds = duration_seconds + VALUES(duration_seconds),
                        updated_at = CURRENT_TIMESTAMP
                ")->execute([
                    $email, $clientId, (string)$eventId, $title,
                    $durationSeconds, $startTime,
                ]);

                if ($cardId) {
                    $this->db->prepare("
                        INSERT INTO projecthub_work_sessions
                            (card_id, user_email, source, entity_type, entity_id,
                             entity_name, started_at, ended_at, duration_seconds)
                        VALUES (?, ?, 'calendar_event', 'calendar_event', ?, ?, ?, ?, ?)
                    ")->execute([
                        $cardId, $email, (string)$eventId, $title,
                        $startTime, $endTime, $durationSeconds,
                    ]);
                }
            }

            $this->db->prepare("
                UPDATE calendar_events SET time_bridged_at = NOW() WHERE id = ?
            ")->execute([$eventId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("bridgeCalendarEventTime failed: " . $e->getMessage());
        }
    }
}

