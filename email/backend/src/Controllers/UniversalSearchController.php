<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\UniversalSearchService;
use Webmail\Services\SearchIndexerService;

/**
 * UniversalSearchController - API endpoints for Super Master Search
 * 
 * Endpoints:
 * - GET /search/universal - Main search across all sources
 * - GET /search/quick - Quick autocomplete search
 * - POST /search/index/rebuild - Rebuild search index for user
 * - POST /search/index/attachments - Index attachment content (uses active IMAP session)
 * - GET /search/index/stats - Get index statistics
 */
class UniversalSearchController extends BaseController
{
    private ?UniversalSearchService $searchService = null;
    private ?SearchIndexerService $indexerService = null;
    
    /**
     * Get search service instance
     */
    private function getSearchService(): UniversalSearchService
    {
        if (!$this->searchService) {
            $configWithAI = $this->config;
            
            // Load user's AI settings from JSON file
            try {
                $hash = md5(strtolower($this->userEmail));
                $aiSettingsFile = '/var/www/vps-email/data/global/ai_' . $hash . '.json';
                
                if (file_exists($aiSettingsFile)) {
                    $content = file_get_contents($aiSettingsFile);
                    $aiSettings = json_decode($content, true);
                    
                    if (!empty($aiSettings['ai_api_key_encrypted'])) {
                        $secret = $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
                        $apiKey = \Webmail\Addons\AIAssistant\Services\AIService::decryptApiKey($aiSettings['ai_api_key_encrypted'], $secret);
                        $configWithAI['ai'] = [
                            'api_key' => $apiKey,
                            'model' => $aiSettings['ai_model'] ?? 'gpt-4.1-mini',
                        ];
                        error_log("UniversalSearchController: AI configured with model: " . ($aiSettings['ai_model'] ?? 'gpt-4.1-mini'));
                    }
                } else {
                    error_log("UniversalSearchController: AI settings file not found: $aiSettingsFile");
                }
            } catch (\Exception $e) {
                error_log("UniversalSearchController: Failed to load AI settings: " . $e->getMessage());
            }
            
            $this->searchService = new UniversalSearchService($configWithAI);
        }
        return $this->searchService;
    }
    
    /**
     * Get indexer service instance
     */
    private function getIndexerService(): SearchIndexerService
    {
        if (!$this->indexerService) {
            $this->indexerService = new SearchIndexerService($this->config);
        }
        return $this->indexerService;
    }
    
    /**
     * Main universal search endpoint
     * 
     * GET /search/universal?q=query&types=email,card&ai=1
     * 
     * Query params:
     * - q: Search query (required)
     * - types: Comma-separated source types to search (optional)
     * - ai: Set to 1 to enable AI answer extraction (optional)
     * - client_id: Filter by client (optional)
     * - board_id: Filter by board (optional)
     * - date_from: Filter by start date (optional)
     * - date_to: Filter by end date (optional)
     * - limit: Max results, default 50 (optional)
     * - offset: Pagination offset (optional)
     */
    public function search(Request $request): Response
    {
        // Authenticate and set userEmail
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }
        
        $query = trim($request->getQuery('q', ''));
        
        if (empty($query)) {
            return Response::error('Search query is required', 400);
        }
        
        // Parse types if provided
        $typesParam = $request->getQuery('types', '');
        $types = null;
        if (!empty($typesParam)) {
            $types = array_filter(array_map('trim', explode(',', $typesParam)));
            
            // Validate types
            $validTypes = ['email', 'email_attachment', 'calendar_event', 'drive_file', 'drive_folder', 'board', 'card', 'todo', 'client', 'collab_doc'];
            $types = array_intersect($types, $validTypes);
            if (empty($types)) {
                $types = null; // Search all if no valid types
            }
        }
        
        $options = [
            'types' => $types,
            'ai_answer' => $request->getQuery('ai') === '1',
            'client_id' => $request->getQuery('client_id') ? (int)$request->getQuery('client_id') : null,
            'board_id' => $request->getQuery('board_id') ? (int)$request->getQuery('board_id') : null,
            'date_from' => $request->getQuery('date_from'),
            'date_to' => $request->getQuery('date_to'),
            'limit' => min((int)($request->getQuery('limit', 50) ?: 50), 100),
            'offset' => (int)($request->getQuery('offset', 0) ?: 0),
        ];
        
        try {
            $results = $this->getSearchService()->search($this->getActiveEmail(), $query, $options);
            return Response::success($results);
        } catch (\Exception $e) {
            error_log("UniversalSearchController search error: " . $e->getMessage());
            return Response::error('Search failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Quick search for autocomplete
     * 
     * GET /search/quick?q=query&limit=10
     */
    public function quickSearch(Request $request): Response
    {
        // Authenticate and set userEmail
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }
        
        $query = trim($request->getQuery('q', ''));
        $limit = min((int)($request->getQuery('limit', 10) ?: 10), 20);
        
        if (empty($query) || strlen($query) < 2) {
            return Response::success(['results' => []]);
        }
        
        try {
            $results = $this->getSearchService()->quickSearch($this->getActiveEmail(), $query, $limit);
            return Response::success(['results' => $results]);
        } catch (\Exception $e) {
            error_log("UniversalSearchController quickSearch error: " . $e->getMessage());
            return Response::success(['results' => []]);
        }
    }
    
    /**
     * Rebuild search index for user
     * 
     * POST /search/index/rebuild
     */
    public function rebuildIndex(Request $request): Response
    {
        // Authenticate and set userEmail
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }
        
        // A full rebuild can touch thousands of rows across many sources plus a
        // batched Meilisearch push; the default PHP-FPM time limit (~30s) is the
        // classic cause of an opaque 500 here. Lift it for this request.
        @set_time_limit(0);
        
        try {
            $stats = $this->getIndexerService()->rebuildUserIndex($this->getActiveEmail());
            
            return Response::success([
                'message' => 'Index rebuilt successfully',
                'indexed' => $stats,
            ]);
        } catch (\Throwable $e) {
            // Catch \Throwable (not just \Exception) so TypeError/Error also
            // return a clean JSON 500 with a usable message instead of a fatal.
            error_log(sprintf(
                "UniversalSearchController rebuildIndex error: %s: %s in %s:%d",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return Response::error('Failed to rebuild index: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get index statistics
     * 
     * GET /search/index/stats
     */
    public function indexStats(Request $request): Response
    {
        // Authenticate and set userEmail
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }
        
        try {
            $stats = $this->getIndexerService()->getIndexStats($this->getActiveEmail());
            
            $total = array_sum($stats);
            
            return Response::success([
                'stats' => $stats,
                'total' => $total,
            ]);
        } catch (\Exception $e) {
            error_log("UniversalSearchController indexStats error: " . $e->getMessage());
            return Response::error('Failed to get index stats', 500);
        }
    }
    
    /**
     * Index a single item (for real-time updates)
     * 
     * POST /search/index/item
     * Body: { type: 'card', id: 123, data: {...} }
     */
    public function indexItem(Request $request): Response
    {
        // Authenticate and set userEmail
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }
        
        $type = $request->input('type');
        $id = $request->input('id');
        $data = $request->input('data');
        
        if (!$type || !$id) {
            return Response::error('Type and ID are required', 400);
        }
        
        try {
            $indexer = $this->getIndexerService();
            $userEmail = $this->getActiveEmail();
            $success = false;
            
            switch ($type) {
                case 'card':
                    $success = $indexer->indexCard($userEmail, $data ?? ['id' => $id]);
                    break;
                case 'board':
                    $success = $indexer->indexBoard($userEmail, $data ?? ['id' => $id]);
                    break;
                case 'todo':
                    $success = $indexer->indexTodo($userEmail, $data ?? ['id' => $id]);
                    break;
                case 'drive_file':
                    $success = $indexer->indexDriveFile($userEmail, $data ?? ['id' => $id]);
                    break;
                case 'client':
                    $success = $indexer->indexClient($userEmail, $data ?? ['id' => $id]);
                    break;
                default:
                    return Response::error('Unknown type: ' . $type, 400);
            }
            
            return Response::success(['indexed' => $success]);
        } catch (\Exception $e) {
            error_log("UniversalSearchController indexItem error: " . $e->getMessage());
            return Response::error('Failed to index item', 500);
        }
    }
    
    /**
     * Index attachment content for the current user
     * Uses the active IMAP session to download and extract attachment content
     * 
     * POST /search/index/attachments
     * Body: { limit: 30 } (optional)
     * 
     * Called automatically on login and periodically by frontend
     */
    public function indexAttachments(Request $request): Response
    {
        // Authenticate and set userEmail
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }
        
        // Get IMAP connection
        $imap = $this->getImap($request);
        if (!$imap) {
            return Response::success([
                'message' => 'IMAP not available',
                'processed' => 0,
                'remaining' => 0,
                'skipped' => true,
            ]);
        }
        
        $limit = min((int)($request->input('limit', 30)), 50); // Max 50 per request
        
        try {
            $result = $this->getIndexerService()->indexAttachmentContentBatch(
                $this->getActiveEmail(),
                $imap,
                $limit
            );
            
            return Response::success([
                'message' => 'Attachment indexing complete',
                'processed' => $result['processed'] ?? 0,
                'success' => $result['success'] ?? 0,
                'errors' => $result['errors'] ?? 0,
                'remaining' => $result['remaining'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            // Catch \Throwable so a transient indexer fault (DB hiccup, IMAP
            // glitch, stale folder identity) never surfaces as a 500 to the
            // browser. The frontend polls this every 10 minutes; spamming
            // 500s in DevTools and the LSWS error log adds no value. The
            // failure is still captured in php_errors.log for diagnostics.
            error_log("UniversalSearchController indexAttachments error: " . $e->getMessage());
            return Response::success([
                'message' => 'Attachment indexing temporarily unavailable',
                'processed' => 0,
                'success' => 0,
                'errors' => 0,
                'remaining' => 0,
                'skipped' => true,
                'reason' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Index email body content in batches
     * Uses the active IMAP session to fetch full email bodies
     * and update the search index with full-text content
     * 
     * POST /search/index/bodies
     * Body: { limit: 20 } (optional)
     * 
     * Called automatically on login and periodically by frontend
     */
    public function indexBodies(Request $request): Response
    {
        // Authenticate and set userEmail
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }
        
        // Get IMAP connection
        $imap = $this->getImap($request);
        if (!$imap) {
            return Response::success([
                'message' => 'IMAP not available',
                'processed' => 0,
                'remaining' => 0,
                'skipped' => true,
            ]);
        }
        
        $limit = min((int)($request->input('limit', 20)), 30); // Max 30 per request
        
        try {
            $result = $this->getIndexerService()->indexEmailBodiesBatch(
                $this->getActiveEmail(),
                $imap,
                $limit
            );
            
            return Response::success([
                'message' => 'Email body indexing complete',
                'processed' => $result['processed'] ?? 0,
                'success' => $result['success'] ?? 0,
                'errors' => $result['errors'] ?? 0,
                'remaining' => $result['remaining'] ?? 0,
            ]);
        } catch (\Exception $e) {
            error_log("UniversalSearchController indexBodies error: " . $e->getMessage());
            return Response::error('Failed to index email bodies: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Remove item from index
     * 
     * DELETE /search/index/item?type=card&id=123
     */
    public function removeIndexItem(Request $request): Response
    {
        // Authenticate and set userEmail
        if (!$this->getUser($request)) {
            return Response::unauthorized('Authentication required');
        }
        
        $type = $request->getQuery('type');
        $id = $request->getQuery('id');
        
        if (!$type || !$id) {
            return Response::error('Type and ID are required', 400);
        }
        
        try {
            $success = $this->getIndexerService()->removeFromIndex(
                $this->getActiveEmail(),
                $type,
                (string)$id
            );
            
            return Response::success(['removed' => $success]);
        } catch (\Exception $e) {
            error_log("UniversalSearchController removeIndexItem error: " . $e->getMessage());
            return Response::error('Failed to remove from index', 500);
        }
    }
}

