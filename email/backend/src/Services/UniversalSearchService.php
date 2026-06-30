<?php

namespace Webmail\Services;

/**
 * UniversalSearchService - Super Master Search across all sources
 * 
 * Searches across:
 * - Emails (subject, body, attachments)
 * - Drive files (filename, document content)
 * - Board cards (title, description, checklists)
 * - Todos (title, description)
 * - Clients (name, domain, contacts)
 * 
 * Features:
 * - Meilisearch for blazing-fast search (primary)
 * - MySQL FULLTEXT as fallback
 * - Full-text search with relevance ranking
 * - Relationship context (shows connections between items)
 * - AI-powered answer extraction (optional)
 * - Filter by source type
 */
class UniversalSearchService
{
    private \PDO $db;
    private array $config;
    private ?AIService $aiService = null;
    private ?MeilisearchService $meilisearch = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // Initialize Meilisearch service
        $this->initMeilisearch();
        
        // Initialize AI service if configured
        $this->initAIService();
    }
    
    /**
     * Initialize Meilisearch service for fast search
     */
    private function initMeilisearch(): void
    {
        try {
            $this->meilisearch = new MeilisearchService($this->config);
            if (!$this->meilisearch->isEnabled()) {
                $this->meilisearch = null;
            }
        } catch (\Exception $e) {
            error_log("UniversalSearchService: Meilisearch init failed: " . $e->getMessage());
            $this->meilisearch = null;
        }
    }
    
    /**
     * Check if Meilisearch is available
     */
    public function isMeilisearchEnabled(): bool
    {
        return $this->meilisearch !== null && $this->meilisearch->isEnabled();
    }
    
    /**
     * Initialize AI service for answer extraction
     */
    private function initAIService(): void
    {
        try {
            $apiKey = $this->config['ai']['api_key'] ?? '';
            $model = $this->config['ai']['model'] ?? 'gpt-4.1-mini';
            
            if (!empty($apiKey)) {
                $this->aiService = new AIService($apiKey, $model, $this->config['ai'] ?? []);
            }
        } catch (\Exception $e) {
            // AI service not available
        }
    }
    
    /**
     * Main search method - searches across all sources
     * 
     * @param string $userEmail User's email
     * @param string $query Search query
     * @param array $options Search options:
     *   - types: array of source types to search (null = all)
     *   - limit: max results (default 50)
     *   - offset: pagination offset
     *   - ai_answer: bool to enable AI answer extraction
     *   - client_id: filter by client
     *   - board_id: filter by board
     *   - date_from: filter by date
     *   - date_to: filter by date
     */
    public function search(string $userEmail, string $query, array $options = []): array
    {
        $userEmail = strtolower($userEmail);
        $query = trim($query);
        
        if (empty($query)) {
            return $this->emptyResults();
        }
        
        // Parse natural language query for filters
        $parsed = $this->parseNaturalLanguageQuery($query, $userEmail);
        
        // Debug: log when from: filter is detected
        if (!empty($parsed['options']['from_filter'])) {
            error_log("SEARCH DEBUG: query='$query'");
            error_log("SEARCH DEBUG: original options=" . json_encode($options));
            error_log("SEARCH DEBUG: parsed options=" . json_encode($parsed['options']));
            error_log("SEARCH DEBUG: parsed search_query='" . $parsed['search_query'] . "'");
        }
        
        // Merge parsed options - parsed should override incoming
        $options = array_merge($options, $parsed['options']);
        $searchQuery = $parsed['search_query'];
        
        // Debug: log merged options and verify types filter
        if (!empty($options['from_filter'])) {
            error_log("SEARCH DEBUG: MERGED options=" . json_encode($options));
            error_log("SEARCH DEBUG: types filter=" . json_encode($options['types'] ?? 'NULL'));
            error_log("SEARCH DEBUG: from_filter=" . ($options['from_filter'] ?? 'NULL'));
        }
        
        $results = [
            'query' => $query,
            'parsed_query' => $searchQuery,
            'applied_filters' => $parsed['filters_applied'],
            'search_engine' => 'mysql', // Will be updated after search
            'ai_answer' => null,
            'results' => [],
            'grouped' => [],
            'counts' => [
                'total' => 0,
                'email' => 0,
                'email_attachment' => 0,
                'calendar_event' => 0,
                'drive_file' => 0,
                'drive_folder' => 0,
                'board' => 0,
                'card' => 0,
                'todo' => 0,
                'client' => 0,
                'collab_doc' => 0,
                'chat_message' => 0,
                'mood_board_item' => 0,
            ],
        ];
        
        // 1. Try Meilisearch first (fastest)
        $searchResults = [];
        $searchMethod = 'none';
        
        if ($this->meilisearch !== null) {
            $searchResults = $this->searchMeilisearch($userEmail, $searchQuery, $options);
            $searchMethod = 'meilisearch';
        }
        
        // 2. Fallback to MySQL FULLTEXT if Meilisearch unavailable or returned nothing
        if (empty($searchResults)) {
            $searchResults = $this->searchFullText($userEmail, $searchQuery, $options);
            $searchMethod = 'fulltext';
        }
        
        // 3. If full-text returns nothing, try LIKE search as last resort
        // Note: use $searchQuery (parsed, without filter operators) not $query
        if (empty($searchResults)) {
            $searchResults = $this->searchLike($userEmail, $searchQuery, $options);
            $searchMethod = 'like';
        }
        
        // Debug: log search results info
        if (!empty($options['from_filter'])) {
            error_log("SEARCH DEBUG: method=$searchMethod, results=" . count($searchResults));
            if (count($searchResults) > 0) {
                $types = array_unique(array_column($searchResults, 'source_type'));
                error_log("SEARCH DEBUG: result types=" . json_encode(array_values($types)));
            }
        }
        
        // 3. Format results and count by type
        foreach ($searchResults as $row) {
            $formatted = $this->formatResult($row, $searchQuery);
            $results['results'][] = $formatted;
            
            $type = $row['source_type'];
            if (isset($results['counts'][$type])) {
                $results['counts'][$type]++;
            }
            
            // Group by type
            if (!isset($results['grouped'][$type])) {
                $results['grouped'][$type] = [];
            }
            $results['grouped'][$type][] = $formatted;
        }
        
        $results['counts']['total'] = count($results['results']);
        
        // 4. AI-powered answer extraction (if requested and results exist)
        if (($options['ai_answer'] ?? false) && $results['counts']['total'] > 0) {
            $results['ai_answer'] = $this->extractAIAnswer($query, array_slice($results['results'], 0, 5), $userEmail);
        }
        
        // Set search engine used
        $results['search_engine'] = $searchMethod;
        
        // Add debug info to response (always, for troubleshooting)
        $results['_debug'] = [
            'original_query' => $query,
            'parsed_query' => $searchQuery,
            'from_filter' => $options['from_filter'] ?? null,
            'types_filter' => $options['types'] ?? null,
            'search_method' => $searchMethod,
            'results_count' => count($searchResults),
            'raw_result_types' => array_values(array_unique(array_column($searchResults, 'source_type'))),
        ];
        
        return $results;
    }
    
    /**
     * Parse natural language query for file type and location filters
     * Supports English and Hungarian
     */
    private function parseNaturalLanguageQuery(string $query, string $userEmail): array
    {
        $originalQuery = $query;
        $options = [];
        $filtersApplied = [];
        $queryLower = mb_strtolower($query);
        
        // ========================================
        // COLON-BASED FILTER OPERATORS
        // Parse filters like: from:miklos, in:folder, client:name, type:email, ext:pdf
        // ========================================
        
        // from:name - Filter by sender/creator
        // Only email and email_attachment have from/from_email fields
        if (preg_match('/\bfrom:([^\s]+)/i', $query, $matches)) {
            $fromName = $matches[1];
            $options['from_filter'] = $fromName;
            // Automatically limit to types that have sender info
            if (empty($options['types'])) {
                $options['types'] = ['email', 'email_attachment'];
            }
            $filtersApplied[] = ['type' => 'from', 'value' => $fromName];
            $query = preg_replace('/\bfrom:[^\s]+/i', '', $query);
        }
        
        // in:folder or folder:name - Filter by folder
        if (preg_match('/\b(?:in|folder):([^\s]+)/i', $query, $matches)) {
            $folderName = $matches[1];
            $folderId = $this->findFolderByName($userEmail, $folderName);
            if ($folderId) {
                $options['folder_id'] = $folderId;
                $filtersApplied[] = ['type' => 'folder', 'value' => $folderName, 'id' => $folderId];
            } else {
                // Also filter by folder_name in index for email folders
                $options['folder_name_filter'] = $folderName;
                $filtersApplied[] = ['type' => 'folder', 'value' => $folderName];
            }
            $query = preg_replace('/\b(?:in|folder):[^\s]+/i', '', $query);
        }
        
        // client:name - Filter by client
        if (preg_match('/\bclient:([^\s]+)/i', $query, $matches)) {
            $clientName = $matches[1];
            $clientId = $this->findClientByName($userEmail, $clientName);
            if ($clientId) {
                $options['client_id'] = $clientId;
                $filtersApplied[] = ['type' => 'client', 'value' => $clientName, 'id' => $clientId];
            } else {
                // Partial match by client_name
                $options['client_name_filter'] = $clientName;
                $filtersApplied[] = ['type' => 'client', 'value' => $clientName];
            }
            $query = preg_replace('/\bclient:[^\s]+/i', '', $query);
        }
        
        // type:email|file|card|todo|event|board - Filter by source type
        $typeMap = [
            'email' => ['email'],
            'emails' => ['email'],
            'mail' => ['email'],
            'attachment' => ['email_attachment'],
            'attachments' => ['email_attachment'],
            'file' => ['drive_file'],
            'files' => ['drive_file'],
            'drive' => ['drive_file', 'drive_folder'],
            'folder' => ['drive_folder'],
            'folders' => ['drive_folder'],
            'card' => ['card'],
            'cards' => ['card'],
            'board' => ['board'],
            'boards' => ['board'],
            'todo' => ['todo'],
            'todos' => ['todo'],
            'task' => ['todo'],
            'tasks' => ['todo'],
            'event' => ['calendar_event'],
            'events' => ['calendar_event'],
            'calendar' => ['calendar_event'],
            'client' => ['client'],
            'clients' => ['client'],
            'doc' => ['collab_doc'],
            'docs' => ['collab_doc'],
            'chat' => ['chat_message'],
            'chats' => ['chat_message'],
            'message' => ['chat_message'],
            'messages' => ['chat_message'],
            'dm' => ['chat_message'],
            'moodboard' => ['mood_board_item'],
            'moodboards' => ['mood_board_item'],
            'mood' => ['mood_board_item'],
        ];
        
        if (preg_match('/\btype:([^\s]+)/i', $query, $matches)) {
            $typeValue = strtolower($matches[1]);
            if (isset($typeMap[$typeValue])) {
                $options['types'] = $typeMap[$typeValue];
                $filtersApplied[] = ['type' => 'source_type', 'value' => $typeValue];
            }
            $query = preg_replace('/\btype:[^\s]+/i', '', $query);
        }
        
        // ext:pdf|docx|xlsx - Filter by file extension
        $extMimeMap = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'svg' => ['image/svg+xml'],
            'mp4' => ['video/mp4'],
            'mp3' => ['audio/mpeg'],
            'zip' => ['application/zip'],
            'rar' => ['application/x-rar-compressed'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv'],
            'json' => ['application/json'],
        ];
        
        if (preg_match('/\bext:([^\s]+)/i', $query, $matches)) {
            $extValue = strtolower($matches[1]);
            if (isset($extMimeMap[$extValue])) {
                $options['types'] = ['drive_file', 'email_attachment'];
                $options['mime_types'] = $extMimeMap[$extValue];
                $filtersApplied[] = ['type' => 'extension', 'value' => $extValue];
            } else {
                // Try to filter by title ending with extension
                $options['ext_filter'] = '.' . $extValue;
                $filtersApplied[] = ['type' => 'extension', 'value' => $extValue];
            }
            $query = preg_replace('/\bext:[^\s]+/i', '', $query);
        }
        
        // before:YYYY-MM-DD or before:YYYY-MM - Filter by date
        if (preg_match('/\bbefore:(\d{4}(?:-\d{2})?(?:-\d{2})?)/i', $query, $matches)) {
            $dateStr = $matches[1];
            // Pad with month/day if needed
            if (strlen($dateStr) === 7) $dateStr .= '-31';
            if (strlen($dateStr) === 4) $dateStr .= '-12-31';
            $options['date_before'] = $dateStr;
            $filtersApplied[] = ['type' => 'date_before', 'value' => $dateStr];
            $query = preg_replace('/\bbefore:[^\s]+/i', '', $query);
        }
        
        // after:YYYY-MM-DD or after:YYYY-MM - Filter by date
        if (preg_match('/\bafter:(\d{4}(?:-\d{2})?(?:-\d{2})?)/i', $query, $matches)) {
            $dateStr = $matches[1];
            // Pad with month/day if needed
            if (strlen($dateStr) === 7) $dateStr .= '-01';
            if (strlen($dateStr) === 4) $dateStr .= '-01-01';
            $options['date_after'] = $dateStr;
            $filtersApplied[] = ['type' => 'date_after', 'value' => $dateStr];
            $query = preg_replace('/\bafter:[^\s]+/i', '', $query);
        }
        
        // Clean up query after removing operators
        $query = trim(preg_replace('/\s+/', ' ', $query));
        $queryLower = mb_strtolower($query);
        
        // ========================================
        // NATURAL LANGUAGE PATTERNS (existing)
        // ========================================
        
        // File type patterns (English + Hungarian)
        $fileTypePatterns = [
            // PDF
            'pdf' => [
                'patterns' => ['/\b(pdf|pdf\s*files?|pdf\s*fájl(ok)?|összes\s*pdf|all\s*pdf|show\s*me\s*(all\s*)?pdf|mutasd?\s*(az\s*)?(összes\s*)?pdf)/iu'],
                'mime_types' => ['application/pdf'],
                'type' => 'drive_file',
            ],
            // Excel
            'excel' => [
                'patterns' => ['/\b(excel|xlsx?|spreadsheet|táblázat(ok)?|excel\s*fájl(ok)?|összes\s*excel|all\s*excel|show\s*me\s*(all\s*)?excel|mutasd?\s*(az\s*)?(összes\s*)?excel)/iu'],
                'mime_types' => [
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/csv',
                ],
                'type' => 'drive_file',
            ],
            // Word documents
            'word' => [
                'patterns' => ['/\b(word|docx?|dokumentum(ok)?|word\s*fájl(ok)?|összes\s*word|all\s*word|show\s*me\s*(all\s*)?word|mutasd?\s*(az\s*)?(összes\s*)?word)/iu'],
                'mime_types' => [
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
                'type' => 'drive_file',
            ],
            // Images
            'images' => [
                'patterns' => ['/\b(image|images|kép(ek)?|fotó(k)?|photo(s)?|picture(s)?|összes\s*kép|all\s*images?|show\s*me\s*(all\s*)?images?|mutasd?\s*(az\s*)?(összes\s*)?kép(ek)?|mutasd?\s*(az\s*)?(összes\s*)?fotó(k)?)/iu'],
                'mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp'],
                'type' => 'drive_file',
            ],
            // Videos
            'videos' => [
                'patterns' => ['/\b(video(s)?|videó(k)?|film(ek)?|összes\s*videó|all\s*videos?|show\s*me\s*(all\s*)?videos?|mutasd?\s*(az\s*)?(összes\s*)?videó(k)?)/iu'],
                'mime_types' => ['video/mp4', 'video/mpeg', 'video/webm', 'video/quicktime', 'video/x-msvideo'],
                'type' => 'drive_file',
            ],
            // Audio
            'audio' => [
                'patterns' => ['/\b(audio|music|zene|hang(ok)?|mp3|összes\s*zene|all\s*audio|show\s*me\s*(all\s*)?audio|mutasd?\s*(az\s*)?(összes\s*)?zené(k)?)/iu'],
                'mime_types' => ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp3'],
                'type' => 'drive_file',
            ],
            // Archives
            'archives' => [
                'patterns' => ['/\b(zip|rar|archive|archívum|tömörített|összes\s*zip|all\s*zip|show\s*me\s*(all\s*)?archives?)/iu'],
                'mime_types' => ['application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed', 'application/gzip'],
                'type' => 'drive_file',
            ],
        ];
        
        // Check for file type matches
        foreach ($fileTypePatterns as $typeName => $config) {
            foreach ($config['patterns'] as $pattern) {
                if (preg_match($pattern, $queryLower)) {
                    $options['types'] = [$config['type']];
                    $options['mime_types'] = $config['mime_types'];
                    $filtersApplied[] = ['type' => 'file_type', 'value' => $typeName];
                    // Remove the matched pattern from query
                    $query = preg_replace($pattern, '', $query);
                    break 2;
                }
            }
        }
        
        // Folder filter patterns (English + Hungarian)
        // "from XX folder", "in XX folder", "XX mappából", "XX mappában"
        $folderPatterns = [
            '/(?:from|in|inside)\s+(?:the\s+)?["\']?([^"\']+?)["\']?\s+folder/iu',
            '/(?:from|in)\s+folder\s+["\']?([^"\']+?)["\']?(?:\s|$)/iu',
            '/["\']?([^"\']+?)["\']?\s+(?:mappából|mappában|könyvtárból|könyvtárban)/iu',
            '/(?:mappából|mappában)\s+["\']?([^"\']+?)["\']?/iu',
        ];
        
        foreach ($folderPatterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                $folderName = trim($matches[1]);
                if (!empty($folderName) && strlen($folderName) > 1) {
                    $folderId = $this->findFolderByName($userEmail, $folderName);
                    if ($folderId) {
                        $options['folder_id'] = $folderId;
                        $filtersApplied[] = ['type' => 'folder', 'value' => $folderName, 'id' => $folderId];
                    }
                    $query = preg_replace($pattern, '', $query);
                }
            }
        }
        
        // Client filter patterns (English + Hungarian)
        // "from XX client", "for XX client", "XX ügyféltől", "XX ügyfél"
        $clientPatterns = [
            '/(?:from|for|of)\s+(?:the\s+)?["\']?([^"\']+?)["\']?\s+client/iu',
            '/(?:from|for)\s+client\s+["\']?([^"\']+?)["\']?(?:\s|$)/iu',
            '/["\']?([^"\']+?)["\']?\s+(?:ügyféltől|ügyfélt|ügyfél(?:nek|től)?)/iu',
            '/(?:ügyfél|ügyféltől)\s+["\']?([^"\']+?)["\']?/iu',
        ];
        
        foreach ($clientPatterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                $clientName = trim($matches[1]);
                if (!empty($clientName) && strlen($clientName) > 1) {
                    $clientId = $this->findClientByName($userEmail, $clientName);
                    if ($clientId) {
                        $options['client_id'] = $clientId;
                        $filtersApplied[] = ['type' => 'client', 'value' => $clientName, 'id' => $clientId];
                    }
                    $query = preg_replace($pattern, '', $query);
                }
            }
        }
        
        // Clean up common filler words from both languages
        $fillerWords = [
            '/\b(show\s*me|mutasd|mutass|kérem|please|all|összes|the|a|az|an)\b/iu',
            '/\b(files?|fájl(ok)?|documents?|dokumentum(ok)?)\b/iu',
        ];
        
        foreach ($fillerWords as $pattern) {
            $query = preg_replace($pattern, '', $query);
        }
        
        // Clean up extra whitespace
        $query = trim(preg_replace('/\s+/', ' ', $query));
        
        return [
            'search_query' => $query,
            'options' => $options,
            'filters_applied' => $filtersApplied,
            'original_query' => $originalQuery,
        ];
    }
    
    /**
     * Find folder by name for the user
     */
    private function findFolderByName(string $userEmail, string $folderName): ?int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM drive_folders 
                WHERE owner_email = ? 
                AND LOWER(name) LIKE LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$userEmail, '%' . $folderName . '%']);
            $result = $stmt->fetch();
            return $result ? (int)$result['id'] : null;
        } catch (\PDOException $e) {
            return null;
        }
    }
    
    /**
     * Find client by name for the user
     */
    private function findClientByName(string $userEmail, string $clientName): ?int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM clients 
                WHERE owner_email = ? 
                AND LOWER(name) LIKE LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$userEmail, '%' . $clientName . '%']);
            $result = $stmt->fetch();
            return $result ? (int)$result['id'] : null;
        } catch (\PDOException $e) {
            return null;
        }
    }
    
    /**
     * Search using Meilisearch (primary search engine)
     * Falls back gracefully if Meilisearch is unavailable
     */
    private function searchMeilisearch(string $userEmail, string $query, array $options = []): array
    {
        if (!$this->meilisearch) {
            return [];
        }
        
        $limit = min((int)($options['limit'] ?? 50), 100);
        $offset = (int)($options['offset'] ?? 0);
        
        // If query is empty but we have filters, do a browse
        $hasFilters = !empty($options['types']) || !empty($options['mime_types']) || 
                      !empty($options['folder_id']) || !empty($options['client_id']) || 
                      !empty($options['board_id']);
        $isEmptyQuery = empty($query);
        
        if ($isEmptyQuery && !$hasFilters) {
            return [];
        }
        
        try {
            // Build filter for Meilisearch
            $filter = MeilisearchService::buildFilter($userEmail, $options);
            
            // Add from_filter (sender search)
            if (!empty($options['from_filter'])) {
                $fromValue = addslashes($options['from_filter']);
                // Search in from_name and from_email fields
                $filter .= " AND (from_name = '$fromValue' OR from_email = '$fromValue')";
            }
            
            // Add folder_name filter
            if (!empty($options['folder_name_filter'])) {
                $folderValue = addslashes($options['folder_name_filter']);
                $filter .= " AND folder_name = '$folderValue'";
            }
            
            // Add client_name filter
            if (!empty($options['client_name_filter'])) {
                $clientValue = addslashes($options['client_name_filter']);
                $filter .= " AND client_name = '$clientValue'";
            }
            
            // Execute search
            $searchOptions = [
                'filter' => $filter,
                'limit' => $limit,
                'offset' => $offset,
                'sort' => ['source_date:desc'],
                'attributesToHighlight' => ['title', 'content'],
            ];
            
            $response = $this->meilisearch->search($query, $searchOptions);
            
            // Convert Meilisearch results to MySQL-compatible format
            $results = [];
            foreach ($response['hits'] ?? [] as $hit) {
                $results[] = [
                    'id' => $hit['id'] ?? null,
                    'user_email' => $hit['user_email'] ?? $userEmail,
                    'source_type' => $hit['source_type'] ?? '',
                    'source_id' => $hit['source_id'] ?? '',
                    'title' => $hit['title'] ?? '',
                    'content_text' => $hit['content'] ?? '',
                    'content_snippet' => $hit['snippet'] ?? '',
                    'client_id' => $hit['client_id'] ?? null,
                    'client_name' => $hit['client_name'] ?? null,
                    'board_id' => $hit['board_id'] ?? null,
                    'board_name' => $hit['board_name'] ?? null,
                    'folder_id' => $hit['folder_id'] ?? null,
                    'folder_name' => $hit['folder_name'] ?? null,
                    'list_id' => $hit['list_id'] ?? null,
                    'list_name' => $hit['list_name'] ?? null,
                    'source_date' => $hit['source_date'] ? date('Y-m-d H:i:s', $hit['source_date']) : null,
                    'mime_type' => $hit['mime_type'] ?? null,
                    'extra_data' => isset($hit['extra_data']) ? json_encode($hit['extra_data']) : null,
                    'relevance' => 1.0, // Meilisearch handles ranking internally
                    // Include highlighted results if available
                    '_formatted' => $hit['_formatted'] ?? null,
                ];
            }
            
            return $results;
        } catch (\Exception $e) {
            error_log("UniversalSearchService searchMeilisearch error: " . $e->getMessage());
            return []; // Fall back to MySQL
        }
    }
    
    /**
     * Full-text search using MySQL FULLTEXT index (fallback)
     */
    private function searchFullText(string $userEmail, string $query, array $options = []): array
    {
        $limit = min((int)($options['limit'] ?? 50), 100);
        $offset = (int)($options['offset'] ?? 0);
        $types = $options['types'] ?? null;
        $mimeTypes = $options['mime_types'] ?? null;
        $folderId = $options['folder_id'] ?? null;
        
        // If query is empty but we have filters, do a browse instead of search
        $hasFilters = !empty($types) || !empty($mimeTypes) || !empty($folderId) || 
                      !empty($options['client_id']) || !empty($options['board_id']);
        $isEmptyQuery = empty($query);
        
        if ($isEmptyQuery && !$hasFilters) {
            return [];
        }
        
        // Build the query
        if ($isEmptyQuery) {
            // Browse mode - no text search, just filters
            $sql = "
                SELECT *, 1.0 as relevance
                FROM universal_search_index
                WHERE user_email = ?
            ";
            $params = [$userEmail];
        } else {
            // Build boolean search: require ALL words, boost exact phrase
            $booleanQuery = $this->buildBooleanQuery($query);
            
            $sql = "
                SELECT *,
                       MATCH(title, content_text) AGAINST(? IN BOOLEAN MODE) as relevance
                FROM universal_search_index
                WHERE user_email = ?
                AND MATCH(title, content_text) AGAINST(? IN BOOLEAN MODE)
            ";
            $params = [$booleanQuery, $userEmail, $booleanQuery];
        }
        
        // Filter by types
        if (!empty($types) && is_array($types)) {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $sql .= " AND source_type IN ($placeholders)";
            $params = array_merge($params, $types);
            error_log("FULLTEXT DEBUG: Applied types filter: " . json_encode($types));
        } else {
            error_log("FULLTEXT DEBUG: NO types filter. types=" . json_encode($types));
        }
        
        // Filter by mime types (for file type filtering)
        if (!empty($mimeTypes) && is_array($mimeTypes)) {
            $mimeConditions = [];
            foreach ($mimeTypes as $mime) {
                $mimeConditions[] = "mime_type = ?";
                $params[] = $mime;
            }
            $sql .= " AND (" . implode(' OR ', $mimeConditions) . ")";
        }
        
        // Filter by folder
        if (!empty($folderId)) {
            $sql .= " AND folder_id = ?";
            $params[] = (int)$folderId;
        }
        
        // Filter by client
        if (!empty($options['client_id'])) {
            $sql .= " AND client_id = ?";
            $params[] = (int)$options['client_id'];
        }
        
        // Filter by board
        if (!empty($options['board_id'])) {
            $sql .= " AND board_id = ?";
            $params[] = (int)$options['board_id'];
        }
        
        // Filter by date range
        if (!empty($options['date_from'])) {
            $sql .= " AND source_date >= ?";
            $params[] = $options['date_from'];
        }
        if (!empty($options['date_to'])) {
            $sql .= " AND source_date <= ?";
            $params[] = $options['date_to'];
        }
        
        // Filter by from: (sender/creator name - search in extra_data JSON)
        if (!empty($options['from_filter'])) {
            $fromPattern = '%' . $this->escapeLike($options['from_filter']) . '%';
            // Use JSON_EXTRACT to search only in from and from_email fields
            // COALESCE handles NULL cases
            $sql .= " AND extra_data IS NOT NULL AND (
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(extra_data, '$.from')), '') LIKE ?
                OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(extra_data, '$.from_email')), '') LIKE ?
            )";
            $params[] = $fromPattern;
            $params[] = $fromPattern;
            error_log("FULLTEXT FROM FILTER: pattern=$fromPattern, types=" . json_encode($options['types'] ?? 'all'));
        }
        
        // Filter by folder_name: (for emails)
        if (!empty($options['folder_name_filter'])) {
            $folderPattern = '%' . $this->escapeLike($options['folder_name_filter']) . '%';
            $sql .= " AND folder_name COLLATE utf8mb4_general_ci LIKE ? COLLATE utf8mb4_general_ci";
            $params[] = $folderPattern;
        }
        
        // Filter by client_name: (partial match)
        if (!empty($options['client_name_filter'])) {
            $clientPattern = '%' . $this->escapeLike($options['client_name_filter']) . '%';
            $sql .= " AND client_name COLLATE utf8mb4_general_ci LIKE ? COLLATE utf8mb4_general_ci";
            $params[] = $clientPattern;
        }
        
        // Filter by extension
        if (!empty($options['ext_filter'])) {
            $extPattern = '%' . $this->escapeLike($options['ext_filter']);
            $sql .= " AND title LIKE ?";
            $params[] = $extPattern;
        }
        
        // Filter by date_before / date_after (from colon operators)
        if (!empty($options['date_before'])) {
            $sql .= " AND source_date <= ?";
            $params[] = $options['date_before'];
        }
        if (!empty($options['date_after'])) {
            $sql .= " AND source_date >= ?";
            $params[] = $options['date_after'];
        }
        
        $sql .= " ORDER BY relevance DESC, source_date DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        
        // Debug logging
        if (!empty($options['from_filter'])) {
            error_log("FULLTEXT SQL: " . preg_replace('/\s+/', ' ', $sql));
            error_log("FULLTEXT PARAMS: " . json_encode($params));
        }
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log("UniversalSearchService searchFullText error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * LIKE-based search as fallback when full-text doesn't match
     */
    private function searchLike(string $userEmail, string $query, array $options = []): array
    {
        $limit = min((int)($options['limit'] ?? 50), 100);
        $offset = (int)($options['offset'] ?? 0);
        $types = $options['types'] ?? null;
        $mimeTypes = $options['mime_types'] ?? null;
        $folderId = $options['folder_id'] ?? null;
        
        // If query is empty but we have filters, do a browse
        $hasFilters = !empty($types) || !empty($mimeTypes) || !empty($folderId) || 
                      !empty($options['client_id']) || !empty($options['board_id']);
        $isEmptyQuery = empty($query);
        
        if ($isEmptyQuery && !$hasFilters) {
            return [];
        }
        
        if ($isEmptyQuery) {
            // Browse mode - just filters, no text search
            $sql = "
                SELECT *, 0 as relevance
                FROM universal_search_index
                WHERE user_email = ?
            ";
            $params = [$userEmail];
        } else {
            // Prepare LIKE pattern - search both with accents and without
            $pattern = '%' . $this->escapeLike($query) . '%';
            
            // Use MySQL COLLATE for accent-insensitive search
            // utf8mb4_general_ci collation treats accented and non-accented as equal
            $sql = "
                SELECT *, 0 as relevance
                FROM universal_search_index
                WHERE user_email = ?
                AND (
                    title COLLATE utf8mb4_general_ci LIKE ? COLLATE utf8mb4_general_ci 
                    OR content_text COLLATE utf8mb4_general_ci LIKE ? COLLATE utf8mb4_general_ci
                )
            ";
            $params = [$userEmail, $pattern, $pattern];
        }
        
        // Filter by types
        if (!empty($types) && is_array($types)) {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $sql .= " AND source_type IN ($placeholders)";
            $params = array_merge($params, $types);
        }
        
        // Filter by mime types
        if (!empty($mimeTypes) && is_array($mimeTypes)) {
            $mimeConditions = [];
            foreach ($mimeTypes as $mime) {
                $mimeConditions[] = "mime_type = ?";
                $params[] = $mime;
            }
            $sql .= " AND (" . implode(' OR ', $mimeConditions) . ")";
        }
        
        // Filter by folder
        if (!empty($folderId)) {
            $sql .= " AND folder_id = ?";
            $params[] = (int)$folderId;
        }
        
        // Filter by client
        if (!empty($options['client_id'])) {
            $sql .= " AND client_id = ?";
            $params[] = (int)$options['client_id'];
        }
        
        // Filter by board
        if (!empty($options['board_id'])) {
            $sql .= " AND board_id = ?";
            $params[] = (int)$options['board_id'];
        }
        
        // Filter by from: (sender/creator name - search in extra_data JSON)
        if (!empty($options['from_filter'])) {
            $fromPattern = '%' . $this->escapeLike($options['from_filter']) . '%';
            // Use JSON_EXTRACT to search only in from and from_email fields
            // COALESCE handles NULL cases
            $sql .= " AND extra_data IS NOT NULL AND (
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(extra_data, '$.from')), '') LIKE ?
                OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(extra_data, '$.from_email')), '') LIKE ?
            )";
            $params[] = $fromPattern;
            $params[] = $fromPattern;
        }
        
        // Filter by folder_name: (for emails)
        if (!empty($options['folder_name_filter'])) {
            $folderPattern = '%' . $this->escapeLike($options['folder_name_filter']) . '%';
            $sql .= " AND folder_name COLLATE utf8mb4_general_ci LIKE ? COLLATE utf8mb4_general_ci";
            $params[] = $folderPattern;
        }
        
        // Filter by client_name: (partial match)
        if (!empty($options['client_name_filter'])) {
            $clientPattern = '%' . $this->escapeLike($options['client_name_filter']) . '%';
            $sql .= " AND client_name COLLATE utf8mb4_general_ci LIKE ? COLLATE utf8mb4_general_ci";
            $params[] = $clientPattern;
        }
        
        // Filter by extension (title ending)
        if (!empty($options['ext_filter'])) {
            $extPattern = '%' . $this->escapeLike($options['ext_filter']);
            $sql .= " AND title LIKE ?";
            $params[] = $extPattern;
        }
        
        // Filter by date_before
        if (!empty($options['date_before'])) {
            $sql .= " AND source_date <= ?";
            $params[] = $options['date_before'];
        }
        
        // Filter by date_after
        if (!empty($options['date_after'])) {
            $sql .= " AND source_date >= ?";
            $params[] = $options['date_after'];
        }
        
        $sql .= " ORDER BY source_date DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log("UniversalSearchService searchLike error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Quick search - returns minimal data for autocomplete
     * Also supports natural language queries
     */
    public function quickSearch(string $userEmail, string $query, int $limit = 10): array
    {
        $userEmail = strtolower($userEmail);
        $query = trim($query);
        
        if (empty($query) || strlen($query) < 2) {
            return [];
        }
        
        // Parse natural language query
        $parsed = $this->parseNaturalLanguageQuery($query, $userEmail);
        $searchQuery = $parsed['search_query'];
        $options = $parsed['options'];
        $hasFilters = !empty($parsed['filters_applied']);
        
        // Build dynamic query
        $sql = "SELECT source_type, source_id, title, content_snippet, client_name, board_name, folder_id, extra_data
                FROM universal_search_index WHERE user_email = ?";
        $params = [$userEmail];
        
        // Text search (if we have a search query)
        // Use COLLATE for accent-insensitive search (á = a, é = e, etc.)
        if (!empty($searchQuery)) {
            $pattern = '%' . $this->escapeLike($searchQuery) . '%';
            $sql .= " AND (
                title COLLATE utf8mb4_general_ci LIKE ? COLLATE utf8mb4_general_ci 
                OR content_text COLLATE utf8mb4_general_ci LIKE ? COLLATE utf8mb4_general_ci
            )";
            $params[] = $pattern;
            $params[] = $pattern;
        }
        
        // Apply type filter
        if (!empty($options['types']) && is_array($options['types'])) {
            $placeholders = implode(',', array_fill(0, count($options['types']), '?'));
            $sql .= " AND source_type IN ($placeholders)";
            $params = array_merge($params, $options['types']);
        }
        
        // Apply mime type filter
        if (!empty($options['mime_types']) && is_array($options['mime_types'])) {
            $mimeConditions = [];
            foreach ($options['mime_types'] as $mime) {
                $mimeConditions[] = "mime_type = ?";
                $params[] = $mime;
            }
            $sql .= " AND (" . implode(' OR ', $mimeConditions) . ")";
        }
        
        // Apply folder filter
        if (!empty($options['folder_id'])) {
            $sql .= " AND folder_id = ?";
            $params[] = (int)$options['folder_id'];
        }
        
        // Apply client filter
        if (!empty($options['client_id'])) {
            $sql .= " AND client_id = ?";
            $params[] = (int)$options['client_id'];
        }
        
        // Apply from filter (sender/creator)
        if (!empty($options['from_filter'])) {
            $fromPattern = '%' . $this->escapeLike($options['from_filter']) . '%';
            $sql .= " AND extra_data IS NOT NULL AND (
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(extra_data, '$.from')), '') LIKE ?
                OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(extra_data, '$.from_email')), '') LIKE ?
            )";
            $params[] = $fromPattern;
            $params[] = $fromPattern;
        }
        
        // Apply folder_name filter (for emails)
        if (!empty($options['folder_name_filter'])) {
            $folderPattern = '%' . $this->escapeLike($options['folder_name_filter']) . '%';
            $sql .= " AND folder_name LIKE ?";
            $params[] = $folderPattern;
        }
        
        // Apply client_name filter (partial match)
        if (!empty($options['client_name_filter'])) {
            $clientPattern = '%' . $this->escapeLike($options['client_name_filter']) . '%';
            $sql .= " AND client_name LIKE ?";
            $params[] = $clientPattern;
        }
        
        // Apply extension filter
        if (!empty($options['ext_filter'])) {
            $extPattern = '%' . $this->escapeLike($options['ext_filter']);
            $sql .= " AND title LIKE ?";
            $params[] = $extPattern;
        }
        
        // Apply date filters
        if (!empty($options['date_after'])) {
            $sql .= " AND source_date >= ?";
            $params[] = $options['date_after'];
        }
        if (!empty($options['date_before'])) {
            $sql .= " AND source_date <= ?";
            $params[] = $options['date_before'];
        }
        
        // Order by title match first, then date
        if (!empty($searchQuery)) {
            $titlePattern = $searchQuery . '%';
            $sql .= " ORDER BY CASE WHEN title LIKE ? THEN 0 ELSE 1 END, source_date DESC";
            $params[] = $titlePattern;
        } else {
            $sql .= " ORDER BY source_date DESC";
        }
        
        $sql .= " LIMIT " . (int)$limit;
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            while ($row = $stmt->fetch()) {
                $result = [
                    'type' => $row['source_type'],
                    'id' => $row['source_id'],
                    'title' => $row['title'],
                    'highlighted_title' => !empty($searchQuery) ? $this->highlightMatches($row['title'] ?? '', $searchQuery) : null,
                    'subtitle' => $this->buildSubtitle($row),
                    'link' => $this->generateLink($row['source_type'], $row['source_id'], $row),
                ];
                
                // Add filter info if filters were applied
                if ($hasFilters) {
                    $result['filters_applied'] = $parsed['filters_applied'];
                }
                
                $results[] = $result;
            }
            
            return $results;
        } catch (\PDOException $e) {
            error_log("UniversalSearchService quickSearch error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Format a search result with relationships
     */
    private function formatResult(array $row, string $searchQuery = ''): array
    {
        $context = [];
        
        // Build relationship breadcrumb
        if (!empty($row['client_name'])) {
            $context[] = [
                'type' => 'client',
                'id' => $row['client_id'],
                'name' => $row['client_name'],
            ];
        }
        
        if (!empty($row['board_name'])) {
            $context[] = [
                'type' => 'board',
                'id' => $row['board_id'],
                'name' => $row['board_name'],
            ];
        }
        
        if (!empty($row['list_name'])) {
            $context[] = [
                'type' => 'list',
                'id' => $row['list_id'],
                'name' => $row['list_name'],
            ];
        }
        
        if (!empty($row['folder_name'])) {
            $context[] = [
                'type' => 'folder',
                'id' => $row['folder_id'],
                'name' => $row['folder_name'],
            ];
        }
        
        // Parse extra data
        $extra = [];
        if (!empty($row['extra_data'])) {
            $extra = json_decode($row['extra_data'], true) ?? [];
        }
        
        // Use Meilisearch highlighted results if available
        $highlightedTitle = null;
        $highlightedSnippet = null;
        
        if (!empty($row['_formatted'])) {
            $highlightedTitle = $row['_formatted']['title'] ?? null;
            $highlightedSnippet = $row['_formatted']['content'] ?? null;
            // Meilisearch already wraps matches in <mark></mark> tags
        }
        
        // For MySQL results, generate highlights manually
        if (empty($highlightedTitle) && !empty($searchQuery)) {
            $highlightedTitle = $this->highlightMatches($row['title'] ?? '', $searchQuery);
            $highlightedSnippet = $this->highlightMatches($row['content_snippet'] ?? '', $searchQuery);
        }
        
        return [
            'source_type' => $row['source_type'],
            'source_id' => $row['source_id'],
            'title' => $row['title'],
            'snippet' => $row['content_snippet'],
            'highlighted_title' => $highlightedTitle,
            'highlighted_snippet' => $highlightedSnippet,
            'date' => $row['source_date'],
            'relevance' => round((float)($row['relevance'] ?? 0), 4),
            'mime_type' => $row['mime_type'],
            'context' => $context,
            'extra' => $extra,
            'link' => $this->generateLink($row['source_type'], $row['source_id'], $row),
            'icon' => $this->getIconForType($row['source_type'], $row['mime_type'] ?? null),
        ];
    }
    
    /**
     * Highlight search term matches in text using <mark> tags.
     * First tries exact phrase match, then individual words.
     * HTML-escapes the text first to prevent XSS.
     */
    private function highlightMatches(string $text, string $query): string
    {
        if (empty($text) || empty($query)) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        
        // HTML-escape the text first (security)
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        
        // Try exact phrase match first
        $pattern = '/' . preg_quote(htmlspecialchars($query, ENT_QUOTES, 'UTF-8'), '/') . '/iu';
        $highlighted = preg_replace($pattern, '<mark>$0</mark>', $escaped);
        
        // If exact phrase was found, return it
        if ($highlighted !== $escaped) {
            return $highlighted;
        }
        
        // Otherwise highlight individual words (all must be at least 2 chars)
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn($w) => mb_strlen($w) >= 2);
        
        if (empty($words)) {
            return $escaped;
        }
        
        // Sort words by length descending to avoid partial replacements
        usort($words, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        
        foreach ($words as $word) {
            $wordEscaped = htmlspecialchars($word, ENT_QUOTES, 'UTF-8');
            $wordPattern = '/' . preg_quote($wordEscaped, '/') . '/iu';
            $escaped = preg_replace($wordPattern, '<mark>$0</mark>', $escaped);
        }
        
        return $escaped;
    }
    
    /**
     * Generate a deep link to open the item
     */
    private function generateLink(string $sourceType, string $sourceId, array $row = []): string
    {
        $extra = [];
        if (!empty($row['extra_data'])) {
            $extra = json_decode($row['extra_data'], true) ?? [];
        }
        
        switch ($sourceType) {
            case 'email':
                // source_id is stored as "folder:uid", extract the uid
                $folder = $extra['folder'] ?? 'INBOX';
                $uid = $extra['uid'] ?? $sourceId;
                // If source_id contains folder:uid format, parse it
                if (strpos($sourceId, ':') !== false) {
                    $parts = explode(':', $sourceId, 2);
                    $folder = $parts[0] ?: 'INBOX';
                    $uid = $parts[1] ?? $sourceId;
                }
                return "/email/" . rawurlencode($folder) . "/message/" . $uid;
            
            case 'email_attachment':
                // source_id is "folder:uid:hash", link to the email
                $folder = $extra['folder'] ?? 'INBOX';
                $uid = $extra['uid'] ?? '';
                if (strpos($sourceId, ':') !== false) {
                    $parts = explode(':', $sourceId, 3);
                    $folder = $parts[0] ?: 'INBOX';
                    $uid = $parts[1] ?? '';
                }
                return "/email/" . rawurlencode($folder) . "/message/" . $uid;
            
            case 'calendar_event':
                return "/calendar?event=" . $sourceId;
                
            case 'drive_file':
                if ($row['folder_id'] ?? null) {
                    return "/drive?folder=" . $row['folder_id'] . "&file=" . $sourceId;
                }
                return "/drive?file=" . $sourceId;
                
            case 'drive_folder':
                return "/drive?folder=" . $sourceId;
                
            case 'card':
                $boardId = $row['board_id'] ?? null;
                if ($boardId) {
                    return "/boards/" . $boardId . "?card=" . $sourceId;
                }
                return "/boards?card=" . $sourceId;
                
            case 'board':
                return "/boards/" . $sourceId;
                
            case 'todo':
                return "/todos?highlight=" . $sourceId;
                
            case 'client':
                return "/clients/" . $sourceId;
                
            case 'collab_doc':
                $type = $extra['type'] ?? 'document';
                if ($type === 'presentation') {
                    return "/drive/presentation/" . $sourceId;
                }
                return "/drive/document/" . $sourceId;
            
            case 'chat_message':
                $convId = $extra['conversation_id'] ?? null;
                if ($convId) {
                    return "/chat?conversation=" . $convId . "&message=" . $sourceId;
                }
                return "/chat";
            
            case 'mood_board_item':
                $boardId = $extra['board_id'] ?? null;
                if ($boardId) {
                    return "/mood/" . $boardId . "?item=" . $sourceId;
                }
                return "/mood";
                
            default:
                return "#";
        }
    }
    
    /**
     * Get Google Material Symbol icon for source type
     */
    private function getIconForType(string $sourceType, ?string $mimeType = null): string
    {
        switch ($sourceType) {
            case 'email':
                return 'mail';
            case 'email_attachment':
                return 'attachment';
            case 'calendar_event':
                return 'event';
            case 'drive_file':
                return $this->getFileIcon($mimeType);
            case 'drive_folder':
                return 'folder';
            case 'card':
                return 'view_kanban';
            case 'board':
                return 'dashboard';
            case 'todo':
                return 'task_alt';
            case 'client':
                return 'business';
            case 'contact':
                return 'person';
            case 'collab_doc':
                return 'description';
            case 'chat_message':
                return 'chat';
            case 'mood_board_item':
                return 'dashboard_customize';
            default:
                return 'article';
        }
    }
    
    /**
     * Get icon for file based on mime type
     */
    private function getFileIcon(?string $mimeType): string
    {
        if (!$mimeType) return 'draft';
        
        if (str_starts_with($mimeType, 'image/')) return 'image';
        if (str_starts_with($mimeType, 'video/')) return 'movie';
        if (str_starts_with($mimeType, 'audio/')) return 'audio_file';
        if ($mimeType === 'application/pdf') return 'picture_as_pdf';
        if (str_contains($mimeType, 'spreadsheet') || str_contains($mimeType, 'excel')) return 'table_chart';
        if (str_contains($mimeType, 'presentation') || str_contains($mimeType, 'powerpoint')) return 'slideshow';
        if (str_contains($mimeType, 'document') || str_contains($mimeType, 'word')) return 'description';
        if (str_contains($mimeType, 'zip') || str_contains($mimeType, 'archive')) return 'folder_zip';
        if (str_starts_with($mimeType, 'text/')) return 'article';
        
        return 'draft';
    }
    
    /**
     * Build subtitle from context
     */
    private function buildSubtitle(array $row): string
    {
        $parts = [];
        
        if (!empty($row['client_name'])) {
            $parts[] = $row['client_name'];
        }
        if (!empty($row['board_name'])) {
            $parts[] = $row['board_name'];
        }
        
        if (empty($parts)) {
            // Use type as fallback
            return $this->getTypeLabel($row['source_type']);
        }
        
        return implode(' > ', $parts);
    }
    
    /**
     * Get human-readable label for source type
     */
    private function getTypeLabel(string $type): string
    {
        $labels = [
            'email' => 'Email',
            'drive_file' => 'File',
            'drive_folder' => 'Folder',
            'card' => 'Card',
            'board' => 'Board',
            'todo' => 'Todo',
            'client' => 'Client',
            'contact' => 'Contact',
            'collab_doc' => 'Document',
            'chat_message' => 'Chat',
            'mood_board_item' => 'MoodBoard',
        ];
        
        return $labels[$type] ?? ucfirst($type);
    }
    
    /**
     * AI-powered answer extraction from search results
     */
    private function extractAIAnswer(string $query, array $topResults, string $userEmail): ?array
    {
        if (!$this->aiService || !$this->aiService->isConfigured()) {
            return null;
        }
        
        if (empty($topResults)) {
            return null;
        }
        
        // Build context from top results
        $context = "";
        foreach ($topResults as $i => $result) {
            $typeLabel = $this->getTypeLabel($result['source_type']);
            $context .= "--- Result " . ($i + 1) . " ({$typeLabel}: {$result['title']}) ---\n";
            $context .= $result['snippet'] . "\n\n";
        }
        
        $prompt = <<<PROMPT
You are a helpful assistant searching through a user's emails, documents, and notes.

Based on the following search results, answer this question: "{$query}"

Search Results:
{$context}

Instructions:
1. If the answer can be found in the results, provide a clear, concise answer
2. Cite which result contains the answer (e.g., "Found in Result 1 - Server Config.docx")
3. If the information is not found in the results, say "I couldn't find this information in your search results"
4. Keep the answer brief - maximum 2-3 sentences
5. IMPORTANT: Answer in the same language as the search results. If results are in Hungarian, answer in Hungarian.

Answer:
PROMPT;

        try {
            // Use reflection or direct call to get raw response
            $response = $this->callAI($prompt);
            
            if ($response['success']) {
                return [
                    'answer' => trim($response['content']),
                    'source' => $topResults[0] ?? null,
                    'all_sources' => array_map(fn($r) => [
                        'type' => $r['source_type'],
                        'id' => $r['source_id'],
                        'title' => $r['title'],
                    ], $topResults),
                ];
            }
        } catch (\Exception $e) {
            error_log("UniversalSearchService extractAIAnswer error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Call AI service directly
     */
    private function callAI(string $prompt): array
    {
        $apiKey = $this->config['ai']['api_key'] ?? '';
        $model = $this->config['ai']['model'] ?? 'gpt-4.1-mini';
        
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'AI not configured'];
        }
        
        $url = 'https://api.openai.com/v1/chat/completions';
        
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_completion_tokens' => 500,
            'temperature' => 0.3, // Lower temperature for more factual answers
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => $data['error']['message'] ?? 'API error'];
        }
        
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        return [
            'success' => true,
            'content' => $content,
        ];
    }
    
    /**
     * Get search suggestions based on recent/popular searches
     */
    public function getSuggestions(string $userEmail, string $prefix = '', int $limit = 5): array
    {
        // For now, return recent items that match the prefix
        if (strlen($prefix) < 2) {
            return [];
        }
        
        return $this->quickSearch($userEmail, $prefix, $limit);
    }
    
    /**
     * Empty results structure
     */
    private function emptyResults(): array
    {
        return [
            'query' => '',
            'ai_answer' => null,
            'results' => [],
            'grouped' => [],
            'counts' => [
                'total' => 0,
                'email' => 0,
                'drive_file' => 0,
                'drive_folder' => 0,
                'board' => 0,
                'card' => 0,
                'todo' => 0,
                'client' => 0,
                'collab_doc' => 0,
            ],
        ];
    }
    
    /**
     * Escape special characters for LIKE queries
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }
    
    /**
     * Build a MySQL BOOLEAN MODE query that requires ALL words to be present.
     * "Product Feed Pro" => +Product +Feed +Pro
     * This prevents matching rows that only contain one of the words.
     * If query is already quoted (e.g. user typed "exact phrase"), keep as exact phrase match.
     */
    private function buildBooleanQuery(string $query): string
    {
        $query = trim($query);
        
        // If user wrapped query in quotes, use exact phrase matching
        if (preg_match('/^".*"$/', $query)) {
            return $query;
        }
        
        // Split into words, prefix each with + to require ALL
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        
        // Filter out very short words (MySQL FULLTEXT min word length is typically 3-4)
        // and boolean operators
        $booleanTerms = [];
        foreach ($words as $word) {
            // Skip MySQL boolean operators
            if (in_array(strtoupper($word), ['AND', 'OR', 'NOT'])) {
                continue;
            }
            // Escape special boolean mode characters
            $word = str_replace(['+', '-', '>', '<', '(', ')', '~', '*', '"'], '', $word);
            if (strlen($word) >= 2) {
                $booleanTerms[] = '+' . $word;
            }
        }
        
        if (empty($booleanTerms)) {
            return $query; // fallback to original
        }
        
        return implode(' ', $booleanTerms);
    }
    
    /**
     * Normalize accented characters for accent-insensitive search
     * Handles Hungarian and common European accented characters
     */
    private function normalizeAccents(string $text): string
    {
        $accents = [
            // Hungarian
            'á' => 'a', 'Á' => 'A',
            'é' => 'e', 'É' => 'E',
            'í' => 'i', 'Í' => 'I',
            'ó' => 'o', 'Ó' => 'O',
            'ö' => 'o', 'Ö' => 'O',
            'ő' => 'o', 'Ő' => 'O',
            'ú' => 'u', 'Ú' => 'U',
            'ü' => 'u', 'Ü' => 'U',
            'ű' => 'u', 'Ű' => 'U',
            // German/Other common
            'ä' => 'a', 'Ä' => 'A',
            'ß' => 'ss',
            'ñ' => 'n', 'Ñ' => 'N',
            'ç' => 'c', 'Ç' => 'C',
            // French
            'à' => 'a', 'À' => 'A',
            'â' => 'a', 'Â' => 'A',
            'è' => 'e', 'È' => 'E',
            'ê' => 'e', 'Ê' => 'E',
            'ë' => 'e', 'Ë' => 'E',
            'î' => 'i', 'Î' => 'I',
            'ï' => 'i', 'Ï' => 'I',
            'ô' => 'o', 'Ô' => 'O',
            'ù' => 'u', 'Ù' => 'U',
            'û' => 'u', 'Û' => 'U',
            'ÿ' => 'y', 'Ÿ' => 'Y',
        ];
        
        return strtr($text, $accents);
    }
    
    /**
     * Check if text contains accented characters
     */
    private function hasAccents(string $text): bool
    {
        $normalized = $this->normalizeAccents($text);
        return $normalized !== $text;
    }
}

