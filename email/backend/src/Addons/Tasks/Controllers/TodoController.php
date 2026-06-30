<?php

namespace Webmail\Addons\Tasks\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\Tasks\Services\TodoService;
use Webmail\Services\SearchIndexerService;
use Webmail\Services\RedisCacheService;

class TodoController extends BaseController
{
    private ?TodoService $todoService = null;
    private ?SearchIndexerService $searchIndexer = null;
    private ?RedisCacheService $redisCache = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
    }
    
    /**
     * Get TodoService (lazy init)
     */
    private function getTodoService(): TodoService
    {
        if (!$this->todoService) {
            $this->todoService = new TodoService($this->config);
        }
        return $this->todoService;
    }
    
    /**
     * Get search indexer for indexing todos
     */
    private function getSearchIndexer(): SearchIndexerService
    {
        if (!$this->searchIndexer) {
            $this->searchIndexer = new SearchIndexerService($this->config);
        }
        return $this->searchIndexer;
    }
    
    /**
     * Get RedisCacheService for publishing real-time events
     */
    private function getRedisCache(): RedisCacheService
    {
        if (!$this->redisCache) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }
    
    /**
     * Index a todo for search
     */
    private function triggerTodoIndex(array $todo): void
    {
        try {
            $this->getSearchIndexer()->indexTodo($this->getActiveEmail(), $todo);
        } catch (\Exception $e) {
            error_log("TodoController triggerTodoIndex error: " . $e->getMessage());
        }
    }
    
    /**
     * List all todos
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $includeCompleted = $request->getQuery('include_completed', 'false') === 'true';
        
        return Response::success([
            'todos' => $this->getTodoService()->getTodos($this->getActiveEmail(), $includeCompleted),
            'incomplete_count' => $this->getTodoService()->getIncompleteCount($this->getActiveEmail()),
        ]);
    }
    
    /**
     * Get a single todo
     */
    public function get(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $todo = $this->getTodoService()->getTodo($this->getActiveEmail(), $id);
        
        if (!$todo) {
            return Response::error('Todo not found', 404);
        }
        
        return Response::success(['todo' => $todo]);
    }
    
    /**
     * Create a new todo
     */
    public function create(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = [
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'priority' => $request->input('priority', 'normal'),
            'due_date' => $request->input('due_date'),
            'parent_id' => $request->input('parent_id'), // Support subtodos
            'ref_folder' => $request->input('ref_folder'),
            'ref_uid' => $request->input('ref_uid'),
            'ref_message_id' => $request->input('ref_message_id'),
            'ref_subject' => $request->input('ref_subject'),
            'ref_from' => $request->input('ref_from'),
            'ref_date' => $request->input('ref_date'),
            'ref_selected_text' => $request->input('ref_selected_text'),
        ];
        
        if (empty($data['title'])) {
            return Response::error('Title is required');
        }
        
        $todo = $this->getTodoService()->createTodo($this->getActiveEmail(), $data);
        
        if (!$todo) {
            return Response::error('Failed to create todo');
        }
        
        // Index for search
        $this->triggerTodoIndex($todo);
        
        return Response::success(['todo' => $todo], 'Todo created');
    }
    
    /**
     * Create todo from email
     */
    public function createFromEmail(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $emailData = [
            'folder' => $request->input('folder'),
            'uid' => $request->input('uid'),
            'message_id' => $request->input('message_id'),
            'subject' => $request->input('subject'),
            'from' => $request->input('from'),
            'date' => $request->input('date'),
            'snippet' => $request->input('snippet'),
        ];
        
        $selectedText = $request->input('selected_text');
        
        $todo = $this->getTodoService()->createFromEmail($this->getActiveEmail(), $emailData, $selectedText);
        
        if (!$todo) {
            return Response::error('Failed to create todo');
        }
        
        // Index for search
        $this->triggerTodoIndex($todo);
        
        return Response::success(['todo' => $todo], 'Todo created from email');
    }
    
    /**
     * Update a todo
     */
    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        $data = [];
        if ($request->has('title')) $data['title'] = $request->input('title');
        if ($request->has('description')) $data['description'] = $request->input('description');
        if ($request->has('priority')) $data['priority'] = $request->input('priority');
        if ($request->has('due_date')) $data['due_date'] = $request->input('due_date');
        if ($request->has('completed')) $data['completed'] = $request->input('completed');
        if ($request->has('position')) $data['position'] = $request->input('position');
        
        $todo = $this->getTodoService()->updateTodo($this->getActiveEmail(), $id, $data);
        
        if (!$todo) {
            return Response::error('Todo not found', 404);
        }
        
        // Index for search
        $this->triggerTodoIndex($todo);
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $completed = isset($data['completed']) ? (bool)$data['completed'] : null;
            $cache->publishTodoUpdated($this->getActiveEmail(), $id, 'updated', $completed);
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(['todo' => $todo], 'Todo updated');
    }
    
    /**
     * Toggle todo completion
     */
    public function toggle(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $todo = $this->getTodoService()->toggleTodo($this->getActiveEmail(), $id);
        
        if (!$todo) {
            return Response::error('Todo not found', 404);
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishTodoUpdated($this->getActiveEmail(), $id, 'toggled', (bool)$todo['completed']);
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(['todo' => $todo], 'Todo toggled');
    }
    
    /**
     * Delete a todo
     */
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $rawId = $request->getParam('id');
        // Guard against the {id} placeholder being matched against a sibling
        // path like /todos/completed when route registration order is broken
        // by a stale deploy. (int)"completed" === 0, which would silently
        // return "Todo not found" and mask the real bug.
        if (!is_numeric($rawId) || (int)$rawId <= 0) {
            return Response::error('Invalid todo id: ' . (string)$rawId, 400);
        }
        $id = (int)$rawId;

        if (!$this->getTodoService()->deleteTodo($this->getActiveEmail(), $id)) {
            return Response::error('Todo not found', 404);
        }

        return Response::success(null, 'Todo deleted');
    }
    
    /**
     * Delete every completed todo for the active user. Used by the panel's
     * "Clear completed" action. Returns the count + the affected root ids so
     * the frontend can drop them from the local store and reindex search.
     */
    public function deleteCompleted(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $result = $this->getTodoService()->deleteAllCompleted($this->getActiveEmail());

        // Drop the deleted ids from the search index in one bulk DELETE so
        // we don't fan out into N round-trips. Best-effort: indexer failures
        // shouldn't block the response.
        try {
            if (!empty($result['ids'])) {
                $this->getSearchIndexer()->removeManyFromIndex(
                    $this->getActiveEmail(),
                    'todo',
                    $result['ids']
                );
            }
        } catch (\Throwable $e) {
            error_log('TodoController deleteCompleted indexer cleanup: ' . $e->getMessage());
        }

        return Response::success([
            'deleted' => $result['deleted'],
        ], 'Completed todos cleared');
    }

    /**
     * Reorder todos
     */
    public function reorder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $todoIds = $request->input('todo_ids', []);
        
        if (empty($todoIds) || !is_array($todoIds)) {
            return Response::error('Invalid todo_ids');
        }
        
        if (!$this->getTodoService()->reorderTodos($this->getActiveEmail(), $todoIds)) {
            return Response::error('Failed to reorder todos');
        }
        
        return Response::success([
            'todos' => $this->getTodoService()->getTodos($this->getActiveEmail()),
        ], 'Todos reordered');
    }
}


