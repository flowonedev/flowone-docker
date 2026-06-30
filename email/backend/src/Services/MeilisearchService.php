<?php

namespace Webmail\Services;

/**
 * MeilisearchService - Search engine integration for universal search
 * 
 * Security-first design:
 * - Meilisearch binds to 127.0.0.1 only
 * - Master key used only for indexing operations
 * - Search key used for queries
 * - Tenant tokens enforce user isolation
 */
class MeilisearchService
{
    private string $host;
    private string $masterKey;
    private string $searchKey;
    private string $indexName;
    private int $batchSize;
    private bool $enabled = false;
    
    // Index configuration
    private const SEARCHABLE_ATTRIBUTES = [
        'title',
        'content',
        'from_name',
        'from_email',
        'client_name',
        'board_name',
        'folder_name',
        'conversation_name',
    ];
    
    private const FILTERABLE_ATTRIBUTES = [
        'user_email',
        'source_type',
        'client_id',
        'board_id',
        'folder_id',
        'mime_type',
        'source_date',
    ];
    
    private const SORTABLE_ATTRIBUTES = [
        'source_date',
        'title',
    ];
    
    private const RANKING_RULES = [
        'words',
        'typo',
        'proximity',
        'attribute',
        'sort',
        'exactness',
    ];
    
    public function __construct(array $config)
    {
        $meiliConfig = $config['meilisearch'] ?? [];
        
        $this->host = $meiliConfig['host'] ?? 'http://127.0.0.1:7700';
        $this->masterKey = $meiliConfig['master_key'] ?? '';
        $this->searchKey = $meiliConfig['search_key'] ?? '';
        $this->indexName = $meiliConfig['index_name'] ?? 'documents';
        $this->batchSize = $meiliConfig['batch_size'] ?? 1000;
        
        // Service is enabled only if master key is configured
        $this->enabled = !empty($this->masterKey);
        
        if ($this->enabled) {
            $this->ensureIndexExists();
        }
    }
    
    /**
     * Check if Meilisearch is enabled and available
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * Check if Meilisearch server is healthy
     */
    public function isHealthy(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $response = $this->request('GET', '/health');
            return ($response['status'] ?? '') === 'available';
        } catch (\Exception $e) {
            error_log("MeilisearchService health check failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ensure the index exists with proper configuration
     */
    private function ensureIndexExists(): void
    {
        try {
            // Check if index exists
            $response = $this->request('GET', "/indexes/{$this->indexName}", [], true);
            
            if (isset($response['code']) && $response['code'] === 'index_not_found') {
                // Create index
                $this->request('POST', '/indexes', [
                    'uid' => $this->indexName,
                    'primaryKey' => 'id',
                ], true);
                
                // Wait for index creation
                sleep(1);
                
                // Configure index settings
                $this->configureIndex();
            }
        } catch (\Exception $e) {
            error_log("MeilisearchService ensureIndexExists error: " . $e->getMessage());
            $this->enabled = false;
        }
    }
    
    /**
     * Configure index settings (searchable, filterable, sortable attributes)
     */
    public function configureIndex(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            // Update searchable attributes
            $this->request('PUT', "/indexes/{$this->indexName}/settings/searchable-attributes", 
                self::SEARCHABLE_ATTRIBUTES, true);
            
            // Update filterable attributes
            $this->request('PUT', "/indexes/{$this->indexName}/settings/filterable-attributes", 
                self::FILTERABLE_ATTRIBUTES, true);
            
            // Update sortable attributes
            $this->request('PUT', "/indexes/{$this->indexName}/settings/sortable-attributes", 
                self::SORTABLE_ATTRIBUTES, true);
            
            // Update ranking rules
            $this->request('PUT', "/indexes/{$this->indexName}/settings/ranking-rules", 
                self::RANKING_RULES, true);
            
            // Enable typo tolerance
            $this->request('PATCH', "/indexes/{$this->indexName}/settings/typo-tolerance", [
                'enabled' => true,
                'minWordSizeForTypos' => [
                    'oneTypo' => 4,
                    'twoTypos' => 8,
                ],
            ], true);
            
            return true;
        } catch (\Exception $e) {
            error_log("MeilisearchService configureIndex error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate a tenant token for user-scoped searches
     * This ensures users can only search their own data
     */
    public function generateTenantToken(string $userEmail): string
    {
        if (empty($this->searchKey)) {
            throw new \Exception('Search key not configured');
        }
        
        // Extract API key UID from search key (first 8 characters)
        $apiKeyUid = substr($this->searchKey, 0, 8);
        
        // Token payload with user filter
        $payload = [
            'apiKeyUid' => $apiKeyUid,
            'searchRules' => [
                $this->indexName => [
                    'filter' => "user_email = '" . addslashes($userEmail) . "'",
                ],
            ],
            'exp' => time() + 3600, // 1 hour expiry
        ];
        
        // Sign the token with the search key
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "$header.$payload", $this->searchKey, true);
        $signature = base64_encode($signature);
        
        return "$header.$payload.$signature";
    }
    
    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================
    
    /**
     * Add or update a single document
     */
    public function upsertDocument(array $document): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $this->request('POST', "/indexes/{$this->indexName}/documents", [$document], true);
            return true;
        } catch (\Exception $e) {
            error_log("MeilisearchService upsertDocument error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add or update multiple documents in batch
     */
    public function upsertDocuments(array $documents): bool
    {
        if (!$this->enabled || empty($documents)) {
            return false;
        }
        
        try {
            // Process in batches
            $batches = array_chunk($documents, $this->batchSize);
            
            foreach ($batches as $batch) {
                $this->request('POST', "/indexes/{$this->indexName}/documents", $batch, true);
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("MeilisearchService upsertDocuments error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a document by ID
     */
    public function deleteDocument(string $documentId): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $this->request('DELETE', "/indexes/{$this->indexName}/documents/{$documentId}", [], true);
            return true;
        } catch (\Exception $e) {
            error_log("MeilisearchService deleteDocument error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete multiple documents by filter
     */
    public function deleteByFilter(string $filter): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $this->request('POST', "/indexes/{$this->indexName}/documents/delete", [
                'filter' => $filter,
            ], true);
            return true;
        } catch (\Exception $e) {
            error_log("MeilisearchService deleteByFilter error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete all documents for a user
     */
    public function deleteUserDocuments(string $userEmail): bool
    {
        return $this->deleteByFilter("user_email = '" . addslashes($userEmail) . "'");
    }
    
    /**
     * Get document count for a user
     */
    public function getUserDocumentCount(string $userEmail): int
    {
        if (!$this->enabled) {
            return 0;
        }
        
        try {
            $result = $this->search('', [
                'filter' => "user_email = '" . addslashes($userEmail) . "'",
                'limit' => 0,
            ]);
            
            return $result['estimatedTotalHits'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    // =========================================================================
    // SEARCH OPERATIONS
    // =========================================================================
    
    /**
     * Execute a search query
     * 
     * @param string $query Search query
     * @param array $options Search options:
     *   - filter: Filter expression (required for security - must include user_email)
     *   - limit: Max results (default 50)
     *   - offset: Pagination offset
     *   - sort: Array of sort expressions
     *   - attributesToRetrieve: Fields to return
     *   - attributesToHighlight: Fields to highlight
     */
    public function search(string $query, array $options = []): array
    {
        if (!$this->enabled) {
            return ['hits' => [], 'estimatedTotalHits' => 0];
        }
        
        // SECURITY: Ensure user_email filter is always present
        if (empty($options['filter']) || strpos($options['filter'], 'user_email') === false) {
            throw new \Exception('Security error: user_email filter is required');
        }
        
        try {
            $searchParams = [
                'q' => $query,
                'limit' => min((int)($options['limit'] ?? 50), 100),
                'offset' => (int)($options['offset'] ?? 0),
            ];
            
            // Add filter
            if (!empty($options['filter'])) {
                $searchParams['filter'] = $options['filter'];
            }
            
            // Add sort
            if (!empty($options['sort'])) {
                $searchParams['sort'] = $options['sort'];
            }
            
            // Add attributes to retrieve
            if (!empty($options['attributesToRetrieve'])) {
                $searchParams['attributesToRetrieve'] = $options['attributesToRetrieve'];
            }
            
            // Add highlighting
            if (!empty($options['attributesToHighlight'])) {
                $searchParams['attributesToHighlight'] = $options['attributesToHighlight'];
                $searchParams['highlightPreTag'] = '<mark>';
                $searchParams['highlightPostTag'] = '</mark>';
            }
            
            // Show matches position for snippet extraction
            $searchParams['showMatchesPosition'] = true;
            
            return $this->request('POST', "/indexes/{$this->indexName}/search", $searchParams, true);
        } catch (\Exception $e) {
            error_log("MeilisearchService search error: " . $e->getMessage());
            return ['hits' => [], 'estimatedTotalHits' => 0];
        }
    }
    
    /**
     * Search with pre-built user filter (convenience method)
     */
    public function searchForUser(string $userEmail, string $query, array $options = []): array
    {
        $userFilter = "user_email = '" . addslashes($userEmail) . "'";
        
        // Combine with any additional filters
        if (!empty($options['filter'])) {
            $options['filter'] = "({$userFilter}) AND ({$options['filter']})";
        } else {
            $options['filter'] = $userFilter;
        }
        
        return $this->search($query, $options);
    }
    
    // =========================================================================
    // INDEX MANAGEMENT
    // =========================================================================
    
    /**
     * Get index statistics
     */
    public function getStats(): array
    {
        if (!$this->enabled) {
            return ['enabled' => false];
        }
        
        try {
            $stats = $this->request('GET', "/indexes/{$this->indexName}/stats", [], true);
            $stats['enabled'] = true;
            $stats['healthy'] = $this->isHealthy();
            return $stats;
        } catch (\Exception $e) {
            return [
                'enabled' => true,
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Clear all documents from index
     */
    public function clearIndex(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $this->request('DELETE', "/indexes/{$this->indexName}/documents", [], true);
            return true;
        } catch (\Exception $e) {
            error_log("MeilisearchService clearIndex error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get pending tasks
     */
    public function getPendingTasks(): array
    {
        if (!$this->enabled) {
            return [];
        }
        
        try {
            return $this->request('GET', '/tasks?statuses=enqueued,processing', [], true);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Wait for all pending tasks to complete
     */
    public function waitForTasks(int $timeoutSeconds = 60): bool
    {
        $start = time();
        
        while (time() - $start < $timeoutSeconds) {
            $tasks = $this->getPendingTasks();
            $pending = $tasks['results'] ?? [];
            
            if (empty($pending)) {
                return true;
            }
            
            usleep(500000); // 500ms
        }
        
        return false;
    }
    
    // =========================================================================
    // HTTP CLIENT
    // =========================================================================
    
    /**
     * Make HTTP request to Meilisearch
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request body
     * @param bool $useMasterKey Use master key (for write operations)
     */
    private function request(string $method, string $endpoint, array $data = [], bool $useMasterKey = false): array
    {
        $url = rtrim($this->host, '/') . $endpoint;
        $apiKey = $useMasterKey ? $this->masterKey : $this->searchKey;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("Meilisearch connection error: $error");
        }
        
        $result = json_decode($response, true) ?? [];
        
        // Check for API errors
        if ($httpCode >= 400) {
            $message = $result['message'] ?? "HTTP $httpCode error";
            throw new \Exception("Meilisearch API error: $message");
        }
        
        return $result;
    }
    
    // =========================================================================
    // HELPER METHODS
    // =========================================================================
    
    /**
     * Build a Meilisearch document from search index data
     */
    public static function buildDocument(string $userEmail, string $sourceType, string $sourceId, array $data): array
    {
        // Create unique ID across all document types
        $id = self::buildDocumentId($sourceType, $sourceId);
        
        return [
            'id' => $id,
            'user_email' => strtolower($userEmail),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'title' => $data['title'] ?? '',
            'content' => $data['content_text'] ?? '',
            'snippet' => $data['content_snippet'] ?? '',
            'client_id' => $data['client_id'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'board_id' => $data['board_id'] ?? null,
            'board_name' => $data['board_name'] ?? null,
            'folder_id' => $data['folder_id'] ?? null,
            'folder_name' => $data['folder_name'] ?? null,
            'list_id' => $data['list_id'] ?? null,
            'list_name' => $data['list_name'] ?? null,
            'from_name' => $data['extra_data']['from'] ?? $data['extra_data']['from_name'] ?? null,
            'from_email' => $data['extra_data']['from_email'] ?? null,
            'conversation_name' => $data['extra_data']['conversation_name'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'source_date' => $data['source_date'] ? strtotime($data['source_date']) : null,
            'extra_data' => $data['extra_data'] ?? null,
        ];
    }
    
    /**
     * Build document ID from source type and ID
     */
    public static function buildDocumentId(string $sourceType, string $sourceId): string
    {
        // Sanitize source_id to be URL-safe
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sourceId);
        return "{$sourceType}_{$safeId}";
    }
    
    /**
     * Build filter expression from options
     */
    public static function buildFilter(string $userEmail, array $options = []): string
    {
        $filters = ["user_email = '" . addslashes($userEmail) . "'"];
        
        // Type filter
        if (!empty($options['types']) && is_array($options['types'])) {
            $typeFilters = array_map(fn($t) => "source_type = '$t'", $options['types']);
            $filters[] = '(' . implode(' OR ', $typeFilters) . ')';
        }
        
        // Client filter
        if (!empty($options['client_id'])) {
            $filters[] = "client_id = " . (int)$options['client_id'];
        }
        
        // Board filter
        if (!empty($options['board_id'])) {
            $filters[] = "board_id = " . (int)$options['board_id'];
        }
        
        // Folder filter
        if (!empty($options['folder_id'])) {
            $filters[] = "folder_id = " . (int)$options['folder_id'];
        }
        
        // MIME type filter
        if (!empty($options['mime_types']) && is_array($options['mime_types'])) {
            $mimeFilters = array_map(fn($m) => "mime_type = '$m'", $options['mime_types']);
            $filters[] = '(' . implode(' OR ', $mimeFilters) . ')';
        }
        
        // Date filters
        if (!empty($options['date_after'])) {
            $timestamp = strtotime($options['date_after']);
            $filters[] = "source_date >= $timestamp";
        }
        if (!empty($options['date_before'])) {
            $timestamp = strtotime($options['date_before']);
            $filters[] = "source_date <= $timestamp";
        }
        
        return implode(' AND ', $filters);
    }
}

