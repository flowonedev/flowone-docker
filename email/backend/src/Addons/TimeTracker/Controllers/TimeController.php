<?php

namespace Webmail\Addons\TimeTracker\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\TimeTracker\Services\ClientTimeTrackingService;

/**
 * TimeController - Handles personal time tracking statistics
 * Provides overview of time spent across all clients
 */
class TimeController extends BaseController
{
    private ClientTimeTrackingService $timeService;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->timeService = new ClientTimeTrackingService($config);
    }
    
    /**
     * GET /time/my-stats
     * Get personal time statistics across all clients
     */
    public function getMyStats(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }
        
        $period = $request->getQuery('period', 'week');
        $clientId = $request->getQuery('client_id') ? (int)$request->getQuery('client_id') : null;
        
        try {
            $stats = $this->timeService->getMyStatsAllClients($email, $period, $clientId);
            
            $sectionTime = $this->getSectionTimeStats($email, $period);
            if ($sectionTime) {
                $stats['section_time'] = $sectionTime;
                $stats['total_seconds'] = max(
                    $stats['total_seconds'] ?? 0,
                    $sectionTime['total_seconds'] ?? 0
                );
            }
            
            return Response::json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            error_log("TimeController getMyStats error: " . $e->getMessage());
            return Response::json([
                'success' => false,
                'error' => 'Failed to load time statistics: ' . $e->getMessage()
            ], 500);
        }
    }
    
    private function getSectionTimeStats(string $userEmail, string $period): ?array
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $dateCondition = match ($period) {
                'today', 'day' => date('Y-m-d'),
                'week' => date('Y-m-d', strtotime('-7 days')),
                'month' => date('Y-m-d', strtotime('-30 days')),
                'year' => date('Y-m-d', strtotime('-365 days')),
                'all' => '1970-01-01',
                default => date('Y-m-d', strtotime('-7 days')),
            };
            
            $stmt = $db->prepare("
                SELECT section, SUM(duration_seconds) as total_seconds
                FROM webmail_time_tracking
                WHERE user_email = ? AND tracked_date >= ?
                GROUP BY section
                ORDER BY total_seconds DESC
            ");
            $stmt->execute([strtolower($userEmail), $dateCondition]);
            $bySection = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $stmt = $db->prepare("
                SELECT tracked_date, section, SUM(duration_seconds) as total_seconds
                FROM webmail_time_tracking
                WHERE user_email = ? AND tracked_date >= ?
                GROUP BY tracked_date, section
                ORDER BY tracked_date
            ");
            $stmt->execute([strtolower($userEmail), $dateCondition]);
            $daily = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            return [
                'by_section' => $bySection,
                'daily' => $daily,
                'total_seconds' => array_sum(array_column($bySection, 'total_seconds')),
            ];
        } catch (\Exception $e) {
            error_log("TimeController getSectionTimeStats error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * GET /time/entity/{type}/{id}
     * Get total tracked time for a specific entity (board card, board, etc.)
     */
    public function getEntityTime(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }
        
        $entityType = $request->getParam('type');
        $entityId = $request->getParam('id');
        
        $activityType = match ($entityType) {
            'card' => 'board_task',
            'board' => 'board_view',
            'todo' => 'board_task',
            default => null
        };
        
        if (!$activityType) {
            return Response::json(['success' => false, 'error' => 'Invalid entity type'], 400);
        }
        
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(duration_seconds), 0) as total_seconds
                FROM webmail_client_time_tracking
                WHERE user_email = ? AND activity_type = ? AND entity_id = ?
            ");
            $stmt->execute([strtolower($email), $activityType, (string)$entityId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $totalSeconds = (int)($row['total_seconds'] ?? 0);
            
            return Response::json([
                'success' => true,
                'data' => [
                    'total_seconds' => $totalSeconds,
                    'formatted' => $this->formatDuration($totalSeconds)
                ]
            ]);
        } catch (\Exception $e) {
            error_log("TimeController getEntityTime error: " . $e->getMessage());
            return Response::json([
                'success' => true,
                'data' => ['total_seconds' => 0, 'formatted' => '0h']
            ]);
        }
    }
    
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds % 3600) / 60);
        if ($hours > 0) return $hours . 'h ' . ($mins > 0 ? $mins . 'm' : '');
        return $mins . 'm';
    }

    /**
     * GET /time/team-stats
     * Get team-wide time statistics across all clients.
     * Admin only — exposes every member's tracked time company-wide.
     * Optional query params: period, client_id, member (email)
     */
    public function getTeamStats(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
        if (!$colleagueService->isAdmin($email)) {
            return Response::json(['success' => false, 'error' => 'Admin access required'], 403);
        }

        $period = $request->getQuery('period', 'week');
        $clientId = $request->getQuery('client_id') ? (int)$request->getQuery('client_id') : null;
        $memberEmail = $request->getQuery('member') ?: null;

        try {
            $stats = $this->timeService->getTeamStatsAllClients($period, $clientId, $memberEmail);

            // Enrich per-client rows with load metrics (rate, value, open tasks)
            try {
                $clientIds = array_column($stats['by_client'] ?? [], 'client_id');
                if (!empty($clientIds)) {
                    $loadService = new \Webmail\Addons\TimeTracker\Services\ClientLoadService($this->config);
                    $load = $loadService->getLoadByClient($clientIds);
                    foreach ($stats['by_client'] as &$row) {
                        $cid = (int)$row['client_id'];
                        $info = $load[$cid] ?? null;
                        $row['hourly_rate'] = $info['hourly_rate'] ?? null;
                        $row['value'] = ($info && $info['hourly_rate'])
                            ? round(($row['total_seconds'] / 3600) * $info['hourly_rate'], 2)
                            : null;
                        $row['open_tasks'] = $info['open_tasks'] ?? 0;
                        $row['overdue_tasks'] = $info['overdue_tasks'] ?? 0;
                        $row['next_deadline'] = $info['next_deadline'] ?? null;
                    }
                    unset($row);
                }
            } catch (\Throwable $e) {
                error_log("TimeController getTeamStats client load enrich error: " . $e->getMessage());
            }

            return Response::json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            error_log("TimeController getTeamStats error: " . $e->getMessage());
            return Response::json([
                'success' => false,
                'error' => 'Failed to load team statistics: ' . $e->getMessage()
            ], 500);
        }
    }
}
