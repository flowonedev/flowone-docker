<?php

namespace Webmail\Addons\Tasks\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Tasks\Services\TodoService;
use Webmail\Addons\KanbanBoards\Services\BoardService;
use Webmail\Services\AddonService;

class MyWorkController extends BaseController
{
    private ?TodoService $todoService = null;
    private ?BoardService $boardService = null;
    private ?AddonService $addonService = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
    }
    
    private function getTodoService(): TodoService
    {
        if (!$this->todoService) {
            $this->todoService = new TodoService($this->config);
        }
        return $this->todoService;
    }
    
    private function getBoardService(): ?BoardService
    {
        if (!$this->boardService) {
            $addonService = $this->getAddonService();
            if ($addonService->isKanbanBoardsEnabled()) {
                $this->boardService = new BoardService($this->config);
            }
        }
        return $this->boardService;
    }
    
    private function getAddonService(): AddonService
    {
        if (!$this->addonService) {
            $this->addonService = new AddonService($this->config);
        }
        return $this->addonService;
    }
    
    /**
     * Aggregate endpoint: returns todos + assigned board cards + cards created by user
     */
    public function getMyWork(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $email = $this->getActiveEmail();
        $includeCompleted = $request->getQuery('include_completed', 'false') === 'true';
        
        $todos = $this->getTodoService()->getTodos($email, $includeCompleted);
        
        $assignedCards = [];
        $boardService = $this->getBoardService();
        if ($boardService) {
            $assignedCards = $boardService->getAssignedCards($email);
            
            if ($includeCompleted) {
                $completedCards = $this->getCompletedAssignedCards($boardService, $email);
                $assignedCards = array_merge($assignedCards, $completedCards);
            }
        }
        
        $stats = $this->getStats($email, $boardService);
        
        return Response::success([
            'todos' => $todos,
            'assigned_cards' => $assignedCards,
            'kanban_enabled' => $boardService !== null,
            'stats' => $stats,
        ]);
    }
    
    /**
     * Get aggregated stats (always queries DB, independent of include_completed filter)
     */
    private function getStats(string $email, ?BoardService $boardService): array
    {
        $email = strtolower($email);
        $db = \Webmail\Core\Database::getConnection($this->config);
        
        $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
        
        $todoCompleted = 0;
        $todoCompletedThisWeek = 0;
        $todoTotal = 0;
        
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM webmail_todos WHERE user_email = ? AND completed = 1");
            $stmt->execute([$email]);
            $todoCompleted = (int)$stmt->fetchColumn();
            
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM webmail_todos WHERE user_email = ? AND completed = 1 AND completed_at >= ?");
            $stmt->execute([$email, $weekStart]);
            $todoCompletedThisWeek = (int)$stmt->fetchColumn();
            
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM webmail_todos WHERE user_email = ? AND completed = 0");
            $stmt->execute([$email]);
            $todoTotal = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            error_log("MyWorkController getStats todos error: " . $e->getMessage());
        }
        
        $cardCompleted = 0;
        $cardCompletedThisWeek = 0;
        
        if ($boardService) {
            try {
                $bdb = $boardService->getDb();
                $stmt = $bdb->prepare("
                    SELECT COUNT(*) FROM webmail_board_cards 
                    WHERE assigned_to = ? AND completed = 1 AND archived = 0
                ");
                $stmt->execute([$email]);
                $cardCompleted = (int)$stmt->fetchColumn();
                
                $stmt = $bdb->prepare("
                    SELECT COUNT(*) FROM webmail_board_cards 
                    WHERE assigned_to = ? AND completed = 1 AND archived = 0 AND completed_at >= ?
                ");
                $stmt->execute([$email, $weekStart]);
                $cardCompletedThisWeek = (int)$stmt->fetchColumn();
            } catch (\Exception $e) {
                error_log("MyWorkController getStats cards error: " . $e->getMessage());
            }
        }
        
        return [
            'completed_total' => $todoCompleted + $cardCompleted,
            'completed_this_week' => $todoCompletedThisWeek + $cardCompletedThisWeek,
        ];
    }

    /**
     * Fetch completed cards assigned to user (the default getAssignedCards only returns non-completed)
     */
    private function getCompletedAssignedCards(BoardService $boardService, string $email): array
    {
        try {
            $db = $boardService->getDb();
            $stmt = $db->prepare("
                SELECT c.*, l.name as list_name, b.name as board_name, b.id as board_id,
                       df.id as board_drive_folder_id
                FROM webmail_board_cards c
                JOIN webmail_board_lists l ON c.list_id = l.id
                JOIN webmail_boards b ON l.board_id = b.id
                LEFT JOIN drive_folders df ON df.board_id = b.id AND (df.is_trashed = 0 OR df.is_trashed IS NULL)
                WHERE c.assigned_to = ?
                AND c.archived = 0
                AND c.completed = 1
                ORDER BY c.completed_at DESC
                LIMIT 50
            ");
            $stmt->execute([strtolower($email)]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("MyWorkController getCompletedAssignedCards error: " . $e->getMessage());
            return [];
        }
    }
}
