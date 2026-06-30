<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\StatisticsService;

class StatisticsController extends BaseController
{
    private ?StatisticsService $statsService = null;
    
    /**
     * Lazy-load the statistics service
     */
    private function getStatsService(): ?StatisticsService
    {
        if ($this->statsService === null) {
            $this->statsService = new StatisticsService($this->config);
        }
        return $this->statsService;
    }
    
    /**
     * Get comprehensive statistics overview
     * GET /api/statistics/overview
     */
    public function getOverview(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'week');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $overview = $stats->getOverview($userEmail, $period);
        
        return Response::success($overview);
    }
    
    /**
     * Get email statistics
     * GET /api/statistics/emails
     */
    public function getEmailStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'week');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $emailStats = $stats->getEmailStats($userEmail, $period);
        
        return Response::success($emailStats);
    }
    
    /**
     * Get active conversations
     * GET /api/statistics/conversations
     */
    public function getConversations(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'month');
        $limit = (int)$request->getQuery('limit', 10);
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $conversations = $stats->getActiveConversations($userEmail, $period, $limit);
        
        return Response::success($conversations);
    }
    
    /**
     * Get top contacts
     * GET /api/statistics/contacts
     */
    public function getContacts(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $limit = (int)$request->getQuery('limit', 10);
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $contacts = $stats->getTopContacts($userEmail, $limit);
        
        return Response::success($contacts);
    }
    
    /**
     * Get folder statistics
     * GET /api/statistics/folders
     */
    public function getFolderStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $folders = $stats->getFolderStats($userEmail);
        
        return Response::success($folders);
    }
    
    /**
     * Get task statistics
     * GET /api/statistics/tasks
     */
    public function getTaskStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'month');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $tasks = $stats->getTaskStats($userEmail, $period);
        
        return Response::success($tasks);
    }
    
    /**
     * Get calendar statistics
     * GET /api/statistics/calendar
     */
    public function getCalendarStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'month');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $calendar = $stats->getCalendarStats($userEmail, $period);
        
        return Response::success($calendar);
    }
    
    /**
     * Get drive statistics
     * GET /api/statistics/drive
     */
    public function getDriveStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'month');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $drive = $stats->getDriveStats($userEmail, $period);
        
        return Response::success($drive);
    }
    
    /**
     * Get board/task statistics
     * GET /api/statistics/boards
     */
    public function getBoardStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'month');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $boards = $stats->getBoardStats($userEmail, $period);
        
        return Response::success($boards);
    }
    
    /**
     * Get client statistics
     * GET /api/statistics/clients
     */
    public function getClientStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'month');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $clients = $stats->getClientStats($userEmail, $period);
        
        return Response::success($clients);
    }
    
    /**
     * Get AI usage statistics
     * GET /api/statistics/ai
     */
    public function getAIStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'month');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $ai = $stats->getAIStats($userEmail, $period);
        
        return Response::success($ai);
    }
    
    /**
     * Get time tracking statistics
     * GET /api/statistics/time
     */
    public function getTimeStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $period = $request->getQuery('period', 'week');
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $time = $stats->getTimeStats($userEmail, $period);
        
        return Response::success($time);
    }
    
    /**
     * Get preference statistics
     * GET /api/statistics/preferences
     */
    public function getPreferenceStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $prefs = $stats->getPreferenceStats($userEmail);
        
        return Response::success($prefs);
    }
    
    /**
     * Track time spent in a section
     * POST /api/statistics/track-time
     */
    public function trackTime(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $section = $request->input('section');
        $duration = (int)$request->input('duration_seconds', 0);
        $folder = $request->input('folder');
        
        if (!$section || $duration < 1) {
            return Response::error('Invalid tracking data', 400);
        }
        
        $validSections = [
            'email', 'calendar', 'drive', 'settings', 'todo', 'mood',
            'boards', 'time_tracker', 'clients', 'chat', 'team',
            'crm', 'financials', 'automation', 'other',
        ];
        if (!in_array($section, $validSections)) {
            return Response::error('Invalid section: ' . $section, 400);
        }
        
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $success = $stats->trackTime($userEmail, $section, $duration, $folder);
        
        if ($success) {
            return Response::success(['tracked' => true]);
        }
        
        return Response::error('Failed to track time', 500);
    }
    
    /**
     * Track time spent in multiple sections (batch)
     * POST /api/statistics/track-time-batch
     * 
     * Used by sendBeacon on page unload for reliable delivery.
     * Accepts JSON array of entries: [{section, duration_seconds, folder?}, ...]
     */
    public function trackTimeBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        // sendBeacon sends as text/plain, so we need to read raw body
        $rawBody = file_get_contents('php://input');
        $entries = json_decode($rawBody, true);
        
        // Fallback to regular input if raw body parsing failed
        if (!is_array($entries)) {
            $entries = $request->input('entries', []);
        }
        
        if (empty($entries) || !is_array($entries)) {
            return Response::error('No entries provided', 400);
        }
        
        $validSections = [
            'email', 'calendar', 'drive', 'settings', 'todo', 'mood',
            'boards', 'time_tracker', 'clients', 'chat', 'team',
            'crm', 'financials', 'automation', 'other',
        ];
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $tracked = 0;
        $errors = 0;
        
        foreach ($entries as $entry) {
            $section = $entry['section'] ?? null;
            $duration = (int)($entry['duration_seconds'] ?? 0);
            $folder = $entry['folder'] ?? null;
            
            // Validate entry
            if (!$section || $duration < 1) {
                $errors++;
                continue;
            }
            
            if (!in_array($section, $validSections)) {
                $errors++;
                continue;
            }
            
            // Track this entry
            if ($stats->trackTime($userEmail, $section, $duration, $folder)) {
                $tracked++;
            } else {
                $errors++;
            }
        }
        
        return Response::success([
            'tracked' => $tracked,
            'errors' => $errors,
            'total' => count($entries)
        ]);
    }
    
    /**
     * Log a trackable event
     * POST /api/statistics/log-event
     */
    public function logEvent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $eventType = $request->input('event_type');
        $eventData = $request->input('event_data', []);
        
        if (!$eventType) {
            return Response::error('Event type required', 400);
        }
        
        // Validate event types
        $validTypes = [
            'email_sent', 'email_received', 'email_replied', 'email_moved', 'email_deleted',
            'task_created', 'task_completed',
            'calendar_event_created',
            'drive_file_uploaded',
            'ai_summary', 'ai_rewrite',
            'theme_changed', 'accent_changed', 'density_changed'
        ];
        
        if (!in_array($eventType, $validTypes)) {
            return Response::error('Invalid event type', 400);
        }
        
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $success = $stats->logEvent($userEmail, $eventType, $eventData);
        
        if ($success) {
            return Response::success(['logged' => true]);
        }
        
        return Response::error('Failed to log event', 500);
    }
    
    /**
     * Get recent events (for debugging)
     * GET /api/statistics/events
     */
    public function getRecentEvents(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $limit = (int)$request->getQuery('limit', 50);
        $userEmail = $this->getActiveEmail();
        
        $stats = $this->getStatsService();
        if (!$stats) {
            return Response::error('Statistics service unavailable', 503);
        }
        
        $events = $stats->getRecentEvents($userEmail, $limit);
        
        return Response::success($events);
    }
}

