<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Service for AI Helper functionality
 * Manages conversations, OpenAI integration, and config/log analysis
 */
class AIHelperService
{
    private Container $container;
    private \PDO $db;
    private ?string $openaiApiKey = null;
    private string $openaiModel = 'gpt-4o';
    private int $maxTokens = 4000;
    private float $temperature = 0.3;
    private string $responseLanguage = 'en';

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
        $this->loadSettings();
    }

    /**
     * Load settings from database
     */
    private function loadSettings(): void
    {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM ai_helper_settings");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($rows as $row) {
                switch ($row['setting_key']) {
                    case 'openai_api_key':
                        $this->openaiApiKey = $row['setting_value'] ?: null;
                        break;
                    case 'openai_model':
                        $this->openaiModel = $row['setting_value'] ?: 'gpt-4o';
                        break;
                    case 'max_tokens':
                        $this->maxTokens = (int)($row['setting_value'] ?: 4000);
                        break;
                    case 'temperature':
                        $this->temperature = (float)($row['setting_value'] ?: 0.3);
                        break;
                    case 'response_language':
                        $this->responseLanguage = $row['setting_value'] ?: 'en';
                        break;
                }
            }
        } catch (\PDOException $e) {
            error_log('Failed to load AI Helper settings: ' . $e->getMessage());
        }
    }

    /**
     * Get current settings
     */
    public function getSettings(): array
    {
        $this->loadSettings();
        
        return [
            'openai_api_key' => $this->openaiApiKey ? '***' . substr($this->openaiApiKey, -4) : '',
            'openai_model' => $this->openaiModel,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'response_language' => $this->responseLanguage,
            'is_configured' => !empty($this->openaiApiKey),
        ];
    }

    /**
     * Update settings
     */
    public function updateSettings(array $settings): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_helper_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            $allowedKeys = ['openai_api_key', 'openai_model', 'max_tokens', 'temperature', 'response_language'];
            
            foreach ($settings as $key => $value) {
                if (in_array($key, $allowedKeys)) {
                    $stmt->execute([$key, (string)$value]);
                }
            }
            
            $this->loadSettings();
            return true;
        } catch (\Exception $e) {
            error_log('Failed to update AI Helper settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new conversation
     */
    public function createConversation(int $userId, ?string $title = null, string $contextType = 'general', ?array $contextData = null): array
    {
        if (!$title) {
            $title = 'New Conversation ' . date('Y-m-d H:i:s');
        }

        $stmt = $this->db->prepare("
            INSERT INTO ai_conversations (user_id, title, context_type, context_data)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId, 
            $title, 
            $contextType,
            $contextData ? json_encode($contextData) : null
        ]);

        $conversationId = (int)$this->db->lastInsertId();

        return [
            'id' => $conversationId,
            'user_id' => $userId,
            'title' => $title,
            'context_type' => $contextType,
            'messages' => [],
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get user's conversations
     */
    public function getUserConversations(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT id, title, context_type, created_at, updated_at
            FROM ai_conversations
            WHERE user_id = ?
            ORDER BY updated_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get conversation with messages
     */
    public function getConversation(int $conversationId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM ai_conversations WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$conversationId, $userId]);
        $conversation = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$conversation) {
            return null;
        }

        // Get messages
        $msgStmt = $this->db->prepare("
            SELECT id, role, content, metadata, created_at
            FROM ai_messages
            WHERE conversation_id = ?
            ORDER BY created_at ASC
        ");
        $msgStmt->execute([$conversationId]);
        $conversation['messages'] = $msgStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Decode metadata
        foreach ($conversation['messages'] as &$msg) {
            if ($msg['metadata']) {
                $msg['metadata'] = json_decode($msg['metadata'], true);
            }
        }

        return $conversation;
    }

    /**
     * Delete a conversation
     */
    public function deleteConversation(int $conversationId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM ai_conversations WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$conversationId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Add a message to conversation
     */
    public function addMessage(int $conversationId, string $role, string $content, ?array $metadata = null, int $tokensUsed = 0): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ai_messages (conversation_id, role, content, tokens_used, metadata)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $conversationId,
            $role,
            $content,
            $tokensUsed,
            $metadata ? json_encode($metadata) : null
        ]);

        // Update conversation timestamp
        $this->db->prepare("UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversationId]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get conversation history for AI context
     */
    public function getConversationHistory(int $conversationId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT role, content FROM ai_messages
            WHERE conversation_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$conversationId, $limit]);
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_reverse($messages);
    }

    /**
     * Send message to OpenAI and get response
     */
    public function sendToOpenAI(string $message, array $history = [], array $context = []): array
    {
        if (empty($this->openaiApiKey)) {
            throw new \Exception('OpenAI API key not configured. Go to Settings > AI Helper to configure.');
        }

        // Build system prompt based on context
        $systemPrompt = $this->buildSystemPrompt($context);

        // Build messages array
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Add history
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        // Add current message
        $messages[] = ['role' => 'user', 'content' => $message];

        // Call OpenAI API
        $response = $this->callOpenAI($messages);

        return $response;
    }

    /**
     * Build system prompt based on context
     */
    private function buildSystemPrompt(array $context): string
    {
        $type = $context['type'] ?? 'general';
        $service = $context['service'] ?? '';
        
        $basePrompt = "You are an expert server administrator and DevOps engineer specializing in Linux servers, web hosting, and system administration. ";
        $basePrompt .= "You help manage VPS servers running OpenLiteSpeed, MariaDB, Dovecot, Postfix, fail2ban, ModSecurity, and PHP 8.3. ";
        
        switch ($type) {
            case 'config_analysis':
            case 'config_question':
                $serviceNames = [
                    'ssh' => 'SSH (sshd_config)',
                    'mysql' => 'MySQL/MariaDB',
                    'postfix' => 'Postfix Mail Server',
                    'dovecot' => 'Dovecot IMAP/POP3',
                    'php' => 'PHP',
                    'ols' => 'OpenLiteSpeed',
                    'nginx' => 'Nginx',
                    'fail2ban' => 'Fail2ban',
                ];
                $serviceName = $serviceNames[$service] ?? $service;
                
                $basePrompt .= "\n\nYou are analyzing a {$serviceName} configuration file. ";
                $basePrompt .= "Provide concise, actionable feedback. Use exact values when suggesting changes. ";
                $basePrompt .= "\n\nResponse format:\n";
                $basePrompt .= "- For 'What does this do?' questions: Give 1-2 sentence explanation only.\n";
                $basePrompt .= "- For 'Is this correct?' questions: Answer 'Yes, correct.' or 'No. Change to `value` - reason'\n";
                $basePrompt .= "- For full analysis: Use **GOOD:** **ISSUES:** **RECOMMENDATIONS:** sections\n";
                $basePrompt .= "- Always provide EXACT values: 'Change to `encrypt`' not 'enable encryption'\n";
                break;
                
            case 'log_analysis':
                $basePrompt .= "\n\nYou are analyzing server logs. ";
                $basePrompt .= "Identify errors, explain their causes, and suggest fixes. ";
                $basePrompt .= "Group similar errors together. Prioritize by severity. ";
                $basePrompt .= "For each issue, provide:\n";
                $basePrompt .= "1. Brief explanation of what went wrong\n";
                $basePrompt .= "2. Likely cause\n";
                $basePrompt .= "3. Suggested fix command or config change\n";
                break;
                
            case 'deployment':
                $basePrompt .= "\n\nYou are helping with server deployment and provisioning. ";
                $basePrompt .= "Provide guidance on installation steps, configuration, and troubleshooting. ";
                break;
                
            default:
                $basePrompt .= "\n\nProvide helpful, accurate technical guidance. Be concise but thorough.";
        }
        
        // Add language preference
        if ($this->responseLanguage !== 'en') {
            $basePrompt .= "\n\nRespond in {$this->responseLanguage}.";
        }
        
        return $basePrompt;
    }

    /**
     * Call OpenAI API
     */
    private function callOpenAI(array $messages): array
    {
        $url = 'https://api.openai.com/v1/chat/completions';
        
        $data = [
            'model' => $this->openaiModel,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiApiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('OpenAI API request failed: ' . $error);
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $result['error']['message'] ?? 'Unknown error';
            throw new \Exception('OpenAI API error: ' . $errorMsg);
        }

        $content = $result['choices'][0]['message']['content'] ?? '';
        $tokensUsed = $result['usage']['total_tokens'] ?? 0;

        return [
            'message' => $content,
            'tokens_used' => $tokensUsed,
            'model' => $this->openaiModel,
        ];
    }

    /**
     * Analyze logs with AI
     */
    public function analyzeLogs(array $logEntries, string $service, int $userId): array
    {
        // Create a conversation for this analysis
        $conversation = $this->createConversation(
            $userId,
            "Log Analysis: {$service} - " . date('Y-m-d H:i'),
            'logs',
            ['service' => $service, 'entry_count' => count($logEntries)]
        );

        // Format logs for analysis
        $logsText = implode("\n", array_slice($logEntries, 0, 100)); // Limit to 100 entries
        
        $prompt = "Analyze these {$service} log entries and identify issues:\n\n```\n{$logsText}\n```\n\n";
        $prompt .= "Format your response as:\n";
        $prompt .= "1. **Summary**: Brief overview of what you found\n";
        $prompt .= "2. **Issues Found**: List each issue with explanation\n";
        $prompt .= "3. **Recommended Actions**: Specific commands or config changes to fix";

        // Store user message
        $this->addMessage($conversation['id'], 'user', $prompt);

        // Get AI response
        $response = $this->sendToOpenAI($prompt, [], [
            'type' => 'log_analysis',
            'service' => $service,
        ]);

        // Store AI response
        $this->addMessage(
            $conversation['id'],
            'assistant',
            $response['message'],
            ['tokens_used' => $response['tokens_used']],
            $response['tokens_used']
        );

        return [
            'conversation_id' => $conversation['id'],
            'message' => $response['message'],
            'tokens_used' => $response['tokens_used'],
        ];
    }

    /**
     * Analyze config with AI
     */
    public function analyzeConfig(string $configContent, string $service, int $userId, ?string $filePath = null): array
    {
        $title = "Config Analysis: {$service}";
        if ($filePath) {
            $title .= " - " . basename($filePath);
        }

        $conversation = $this->createConversation(
            $userId,
            $title,
            'config',
            ['service' => $service, 'file_path' => $filePath]
        );

        $prompt = "Analyze this {$service} configuration for security issues, misconfigurations, and optimization opportunities:\n\n";
        $prompt .= "```\n{$configContent}\n```\n\n";
        $prompt .= "Provide a concise analysis with:\n";
        $prompt .= "**GOOD:** What's correctly configured\n";
        $prompt .= "**ISSUES:** Problems found (with severity)\n";
        $prompt .= "**RECOMMENDATIONS:** Specific changes to make";

        $this->addMessage($conversation['id'], 'user', $prompt);

        $response = $this->sendToOpenAI($prompt, [], [
            'type' => 'config_analysis',
            'service' => $service,
        ]);

        $this->addMessage(
            $conversation['id'],
            'assistant',
            $response['message'],
            ['tokens_used' => $response['tokens_used']],
            $response['tokens_used']
        );

        return [
            'conversation_id' => $conversation['id'],
            'message' => $response['message'],
            'tokens_used' => $response['tokens_used'],
        ];
    }

    /**
     * Cache an identified issue
     */
    public function cacheIssue(
        ?int $serverId,
        string $service,
        string $issueType,
        string $title,
        string $description,
        ?string $suggestedFix = null,
        string $severity = 'medium'
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO ai_cached_issues (server_id, service, issue_type, title, description, suggested_fix, severity)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$serverId, $service, $issueType, $title, $description, $suggestedFix, $severity]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Get cached issues
     */
    public function getCachedIssues(?int $serverId = null, ?string $service = null, bool $includeResolved = false): array
    {
        $sql = "SELECT * FROM ai_cached_issues WHERE 1=1";
        $params = [];

        if ($serverId !== null) {
            $sql .= " AND server_id = ?";
            $params[] = $serverId;
        }

        if ($service !== null) {
            $sql .= " AND service = ?";
            $params[] = $service;
        }

        if (!$includeResolved) {
            $sql .= " AND resolved = 0";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Mark issue as resolved
     */
    public function resolveIssue(int $issueId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE ai_cached_issues SET resolved = 1, resolved_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$issueId]);
        return $stmt->rowCount() > 0;
    }
}

