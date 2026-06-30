<?php

namespace VpsAdmin\Api\Services;

use VpsAdmin\Api\Core\Container;
use VpsAdmin\Api\Core\Migration;

/**
 * Service for AI Helper functionality
 * Manages conversations, OpenAI integration, and issue caching
 */
class AIHelperService
{
    private Container $container;
    private \PDO $db;
    private ?string $openaiApiKey = null;
    private string $openaiModel = 'gpt-5';
    private int $maxTokens = 4000;
    private float $temperature = 0.3;
    private string $responseLanguage = 'en';
    private string $systemPrompt;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
        
        // Run migration on first use
        $this->ensureTablesExist();
        
        // Load settings from database first, then fall back to config
        $this->loadSettings();
        
        $config = $container->getConfig('ai_helper') ?? [];
        // Only use config if database settings are empty
        if (empty($this->openaiApiKey)) {
            $this->openaiApiKey = $config['openai_api_key'] ?? null;
        }
        if ($this->openaiModel === 'gpt-5' && isset($config['openai_model'])) {
            $this->openaiModel = $config['openai_model'];
        }
        if ($this->maxTokens === 4000 && isset($config['max_tokens'])) {
            $this->maxTokens = $config['max_tokens'];
        }
        if ($this->temperature === 0.3 && isset($config['temperature'])) {
            $this->temperature = $config['temperature'];
        }
        $this->systemPrompt = $config['system_prompt'] ?? $this->getDefaultSystemPrompt();
    }

    /**
     * Ensure database tables exist (auto-migration)
     */
    private function ensureTablesExist(): bool
    {
        try {
            $migration = new Migration($this->db);
            $result = $migration->migrateAIHelper();
            if (!$result) {
                debug_log('AI Helper migration returned false');
                return false;
            }
            return true;
        } catch (\Exception $e) {
            debug_log('Failed to ensure AI Helper tables exist: ' . $e->getMessage());
            debug_log('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Load settings from database
     */
    private function loadSettings(): void
    {
        try {
            $this->ensureTablesExist();
            
            // Check if table exists before querying
            $stmt = $this->db->query("SHOW TABLES LIKE 'ai_helper_settings'");
            if ($stmt->rowCount() === 0) {
                return; // Table doesn't exist yet, use defaults
            }
            
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM ai_helper_settings");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($rows as $row) {
                switch ($row['setting_key']) {
                    case 'openai_api_key':
                        $this->openaiApiKey = $row['setting_value'] ?: null;
                        break;
                    case 'openai_model':
                        $this->openaiModel = $row['setting_value'] ?: 'gpt-5';
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
            debug_log('Failed to load AI Helper settings: ' . $e->getMessage());
            // Use defaults if database query fails
        } catch (\Exception $e) {
            debug_log('Failed to load AI Helper settings: ' . $e->getMessage());
        }
    }

    /**
     * Get current settings
     */
    public function getSettings(): array
    {
        try {
            // Ensure tables exist first
            if (!$this->ensureTablesExist()) {
                debug_log('AI Helper tables do not exist, using defaults');
            }
            
            $this->loadSettings();
        } catch (\Exception $e) {
            debug_log('Error loading settings in getSettings: ' . $e->getMessage());
            debug_log('Stack trace: ' . $e->getTraceAsString());
            // Continue with defaults
        }
        
        return [
            'openai_api_key' => $this->openaiApiKey ? '***' . substr($this->openaiApiKey, -4) : '',
            'openai_model' => $this->openaiModel ?: 'gpt-5',
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'response_language' => $this->responseLanguage,
        ];
    }

    /**
     * Update settings
     */
    public function updateSettings(array $settings): bool
    {
        try {
            $this->ensureTablesExist();
            
            $db = $this->db;
            $stmt = $db->prepare("
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
            
            // Reload settings
            $this->loadSettings();
            
            return true;
        } catch (\Exception $e) {
            debug_log('Failed to update AI Helper settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new conversation
     */
    public function createConversation(int $userId, ?string $title = null): array
    {
        try {
            // Ensure tables exist before operation
            $this->ensureTablesExist();

            // Check if table exists
            $checkStmt = $this->db->query("SHOW TABLES LIKE 'ai_conversations'");
            if ($checkStmt->rowCount() === 0) {
                throw new \Exception('AI Helper tables not initialized. Please run migration.');
            }

            if (!$title) {
                $title = 'New Conversation ' . date('Y-m-d H:i:s');
            }

            $stmt = $this->db->prepare("
                INSERT INTO ai_conversations (user_id, title)
                VALUES (?, ?)
            ");
            $stmt->execute([$userId, $title]);

            $conversationId = (int)$this->db->lastInsertId();

            return [
                'id' => $conversationId,
                'user_id' => $userId,
                'title' => $title,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\PDOException $e) {
            debug_log('Database error in createConversation: ' . $e->getMessage());
            throw new \Exception('Failed to create conversation: ' . $e->getMessage());
        } catch (\Exception $e) {
            debug_log('Error in createConversation: ' . $e->getMessage());
            throw $e; // Re-throw to be handled by controller
        }
    }

    /**
     * Delete a conversation
     */
    public function deleteConversation(int $conversationId, int $userId): bool
    {
        // Verify conversation belongs to user
        $stmt = $this->db->prepare("
            SELECT id FROM ai_conversations 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$conversationId, $userId]);
        
        if (!$stmt->fetch()) {
            return false; // Conversation doesn't exist or doesn't belong to user
        }

        // Delete conversation (messages will be deleted via CASCADE)
        $stmt = $this->db->prepare("DELETE FROM ai_conversations WHERE id = ?");
        $stmt->execute([$conversationId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Add a message to a conversation
     */
    public function addMessage(int $conversationId, string $role, string $content, ?array $metadata = null): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO ai_messages (conversation_id, role, content, metadata)
            VALUES (?, ?, ?, ?)
        ");
        
        $metadataJson = $metadata ? json_encode($metadata) : null;
        $stmt->execute([$conversationId, $role, $content, $metadataJson]);

        $messageId = (int)$this->db->lastInsertId();

        // Update conversation updated_at
        $this->db->prepare("UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?")
            ->execute([$conversationId]);

        return [
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get conversation history
     */
    public function getConversationHistory(int $conversationId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, role, content, metadata, created_at
            FROM ai_messages
            WHERE conversation_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversationId]);

        $messages = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $messages[] = [
                'id' => (int)$row['id'],
                'role' => $row['role'],
                'content' => $row['content'],
                'metadata' => $row['metadata'] ? json_decode($row['metadata'], true) : null,
                'created_at' => $row['created_at'],
            ];
        }

        return $messages;
    }

    /**
     * Get user's conversations
     */
    public function getUserConversations(int $userId, int $limit = 50): array
    {
        try {
            // Ensure tables exist before operation
            $this->ensureTablesExist();

            // Check if table exists
            $checkStmt = $this->db->query("SHOW TABLES LIKE 'ai_conversations'");
            if ($checkStmt->rowCount() === 0) {
                return []; // Table doesn't exist yet, return empty array
            }

            $stmt = $this->db->prepare("
                SELECT c.id, c.title, c.created_at, c.updated_at,
                       (SELECT COUNT(*) FROM ai_messages WHERE conversation_id = c.id) as message_count
                FROM ai_conversations c
                WHERE c.user_id = ?
                ORDER BY c.updated_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);

            $conversations = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $conversations[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'message_count' => (int)$row['message_count'],
                ];
            }

            return $conversations;
        } catch (\PDOException $e) {
            debug_log('Database error in getUserConversations: ' . $e->getMessage());
            return []; // Return empty array on error
        } catch (\Exception $e) {
            debug_log('Error in getUserConversations: ' . $e->getMessage());
            return []; // Return empty array on error
        }
    }

    /**
     * Get a conversation with messages
     */
    public function getConversation(int $conversationId, int $userId): ?array
    {
        // Ensure tables exist before operation
        $this->ensureTablesExist();

        $stmt = $this->db->prepare("
            SELECT id, user_id, title, created_at, updated_at
            FROM ai_conversations
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$conversationId, $userId]);
        $conversation = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$conversation) {
            return null;
        }

        $messages = $this->getConversationHistory($conversationId);

        return [
            'id' => (int)$conversation['id'],
            'user_id' => (int)$conversation['user_id'],
            'title' => $conversation['title'],
            'created_at' => $conversation['created_at'],
            'updated_at' => $conversation['updated_at'],
            'messages' => $messages,
        ];
    }

    /**
     * Analyze issue with AI
     */
    public function analyzeIssue(string $prompt, array $context = [], ?string $model = null): array
    {
        // Reload settings to get latest values
        $this->loadSettings();
        
        if (!$this->openaiApiKey) {
            return [
                'success' => false,
                'error' => 'OpenAI API key not configured. Please set it in AI Helper Settings.',
            ];
        }

        // Select model if not provided
        if (!$model) {
            $model = $this->selectModelForTask($this->detectTaskType($prompt, $context));
        }

        // Build messages with language-aware system prompt
        $messages = [
            [
                'role' => 'system',
                'content' => $this->getEffectiveSystemPrompt(),
            ],
        ];

        // Add context if provided
        if (!empty($context)) {
            $contextText = "Context:\n";
            foreach ($context as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $contextText .= "{$key}: {$value}\n";
                } elseif (is_array($value)) {
                    $contextText .= "{$key}: " . json_encode($value, JSON_PRETTY_PRINT) . "\n";
                }
            }
            $messages[] = [
                'role' => 'system',
                'content' => $contextText,
            ];
        }

        // Add conversation history if available
        if (isset($context['conversation_id'])) {
            $history = $this->getConversationHistory($context['conversation_id']);
            foreach ($history as $msg) {
                if ($msg['role'] !== 'system') {
                    $messages[] = [
                        'role' => $msg['role'],
                        'content' => $msg['content'],
                    ];
                }
            }
        }

        // Add current prompt
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        // Before calling AI, check if user is asking about config files and read them
        $configFiles = $this->detectConfigFileRequests($prompt);
        debug_log("AIHelper: Detected config files to read: " . json_encode($configFiles));
        
        if (!empty($configFiles)) {
            $fileContents = $this->readConfigFiles($configFiles);
            debug_log("AIHelper: Successfully read " . count($fileContents) . " config files");
            
            if (!empty($fileContents)) {
                // Add file contents to context with clear instructions
                $fileCount = count($fileContents);
                $fileContext = "\n\n## CONFIGURATION FILES TO ANALYZE ({$fileCount} files)\n";
                $fileContext .= "IMPORTANT: You MUST analyze ALL {$fileCount} files below and include findings from EACH file in your response.\n\n";
                
                $fileNum = 1;
                foreach ($fileContents as $file => $content) {
                    $fileContext .= "### FILE {$fileNum} OF {$fileCount}: {$file}\n";
                    $fileContext .= "```\n{$content}\n```\n\n";
                    $fileNum++;
                }
                
                $fileContext .= "Remember: Include analysis from ALL {$fileCount} files above. Each finding must include [[filepath]] to show which file it's from.\n";
                
                $messages[] = [
                    'role' => 'system',
                    'content' => $fileContext,
                ];
            } else {
                // Add a note that files couldn't be read
                $messages[] = [
                    'role' => 'system',
                    'content' => "Note: Unable to read the requested configuration files. Please provide general best practice recommendations for the service configuration.",
                ];
            }
        }
        
        // Call OpenAI API
        $result = $this->callOpenAI($model, $messages);
        
        // If successful, enforce format and check for diagnostic commands
        if ($result['success'] && isset($result['content'])) {
            // Enforce GOOD/ISSUES format - extract only those sections
            $result['content'] = $this->enforceFormat($result['content']);
            
            // Check for diagnostic commands and execute them automatically
            $result = $this->executeDiagnosticCommands($result, $context);
        }
        
        return $result;
    }

    /**
     * Detect config file paths mentioned in the prompt
     */
    private function detectConfigFileRequests(string $prompt): array
    {
        $files = [];
        
        // Pattern to match file paths (absolute paths starting with /)
        if (preg_match_all('/(\/[\/\w\.\-]+(?:\.conf|\.cfg|\.ini|\.cnf|\.config))/i', $prompt, $matches)) {
            $files = array_unique($matches[1]);
        }
        
        // Service-based config file detection
        $serviceConfigs = [
            'postfix' => [
                '/etc/postfix/main.cf',
                '/etc/postfix/master.cf',
            ],
            'dovecot' => [
                '/etc/dovecot/dovecot.conf',
                '/etc/dovecot/conf.d/10-mail.conf',
                '/etc/dovecot/conf.d/10-ssl.conf',
                '/etc/dovecot/conf.d/10-auth.conf',
            ],
            'nginx' => [
                '/etc/nginx/nginx.conf',
            ],
            'apache' => [
                '/etc/apache2/apache2.conf',
            ],
            'openlitespeed' => [
                '/usr/local/lsws/conf/httpd_config.conf',
            ],
            'ssh' => [
                '/etc/ssh/sshd_config',
            ],
            'fail2ban' => [
                '/etc/fail2ban/jail.local',
            ],
        ];
        
        // Check if user mentions a service and add its config files
        foreach ($serviceConfigs as $service => $configFiles) {
            if (stripos($prompt, $service) !== false || 
                stripos($prompt, "config files for {$service}") !== false ||
                stripos($prompt, "{$service} configuration") !== false ||
                stripos($prompt, "analyze {$service}") !== false) {
                foreach ($configFiles as $config) {
                    if (!in_array($config, $files)) {
                        $files[] = $config;
                    }
                }
            }
        }
        
        // Also check for common config file mentions by name
        $commonConfigs = [
            '/etc/postfix/main.cf',
            '/etc/postfix/master.cf',
            '/etc/dovecot/dovecot.conf',
            '/etc/dovecot/conf.d/10-mail.conf',
            '/etc/dovecot/conf.d/10-ssl.conf',
            '/etc/nginx/nginx.conf',
            '/etc/apache2/apache2.conf',
            '/usr/local/lsws/conf/httpd_config.conf',
        ];
        
        foreach ($commonConfigs as $config) {
            if (stripos($prompt, basename($config)) !== false || stripos($prompt, $config) !== false) {
                if (!in_array($config, $files)) {
                    $files[] = $config;
                }
            }
        }
        
        return $files;
    }

    /**
     * Read config files using agent or direct file access
     */
    private function readConfigFiles(array $filePaths): array
    {
        $contents = [];
        $agentService = null;
        
        try {
            $agentService = $this->container->get(\VpsAdmin\Api\Services\AgentService::class);
        } catch (\Exception $e) {
            debug_log('AIHelper: AgentService unavailable: ' . $e->getMessage());
        }
        
        foreach ($filePaths as $filePath) {
            // Skip directories
            if (is_dir($filePath)) {
                continue;
            }
            
            $fileContent = null;
            
            // Try agent first
            if ($agentService) {
                try {
                    $result = $agentService->execute('aihelper.readConfigFile', [
                        'path' => $filePath,
                    ], 'system');
                    
                    if ($result['success'] && isset($result['data']['content'])) {
                        $fileContent = $result['data']['content'];
                        debug_log("AIHelper: Read {$filePath} via agent (" . strlen($fileContent) . " bytes)");
                    }
                } catch (\Exception $e) {
                    debug_log("AIHelper: Agent failed to read {$filePath}: " . $e->getMessage());
                }
            }
            
            // Fallback: try direct file read (for local development or when agent unavailable)
            if ($fileContent === null && file_exists($filePath) && is_readable($filePath)) {
                try {
                    $fileContent = file_get_contents($filePath);
                    if ($fileContent !== false) {
                        debug_log("AIHelper: Read {$filePath} directly (" . strlen($fileContent) . " bytes)");
                    }
                } catch (\Exception $e) {
                    debug_log("AIHelper: Direct read failed for {$filePath}: " . $e->getMessage());
                }
            }
            
            if ($fileContent !== null && $fileContent !== false) {
                // Truncate very large files to prevent token overflow
                if (strlen($fileContent) > 50000) {
                    $fileContent = substr($fileContent, 0, 50000) . "\n\n... [truncated - file too large] ...";
                }
                $contents[$filePath] = $fileContent;
            }
        }
        
        if (empty($contents)) {
            debug_log("AIHelper: Could not read any config files from: " . implode(', ', $filePaths));
        }
        
        return $contents;
    }

    /**
     * Detect and execute read-only diagnostic commands mentioned in AI response
     */
    private function executeDiagnosticCommands(array $result, array $context): array
    {
        $content = $result['content'];
        $commandsToExecute = [];
        
        // Patterns for common diagnostic commands
        $diagnosticPatterns = [
            '/postfix\s+check/i',
            '/postconf\s+-n/i',
            '/doveconf\s+-n/i',
            '/dovecot\s+-n/i',
            '/systemctl\s+status\s+(\S+)/i',
            '/journalctl\s+-u\s+(\S+)/i',
            '/sshd\s+-t/i',
            '/nginx\s+-t/i',
            '/apache2ctl\s+-t/i',
            '/httpd\s+-t/i',
            '/named-checkconf/i',
            '/named-checkzone/i',
        ];
        
        // Extract commands from response
        foreach ($diagnosticPatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $command = trim($matches[0]);
                // Normalize command (remove extra spaces, ensure proper format)
                $command = preg_replace('/\s+/', ' ', $command);
                if (!in_array($command, $commandsToExecute)) {
                    $commandsToExecute[] = $command;
                }
            }
        }
        
        // Execute commands and append results
        if (!empty($commandsToExecute)) {
            $commandResults = [];
            
            foreach ($commandsToExecute as $command) {
                try {
                    // Use agent service to execute command
                    $agentService = $this->container->get(\VpsAdmin\Api\Services\AgentService::class);
                    $cmdResult = $agentService->execute('aihelper.dryRunCommand', [
                        'command' => $command,
                    ], 'system');
                    
                    if ($cmdResult['success']) {
                        $output = $cmdResult['data']['output'] ?? [];
                        $error = $cmdResult['data']['error'] ?? [];
                        $exitCode = $cmdResult['data']['exit_code'] ?? 0;
                        
                        $commandResults[] = [
                            'command' => $command,
                            'exit_code' => $exitCode,
                            'output' => !empty($output) ? implode("\n", $output) : null,
                            'error' => !empty($error) ? implode("\n", $error) : null,
                            'success' => $exitCode === 0,
                        ];
                    }
                } catch (\Exception $e) {
                    debug_log('Failed to execute diagnostic command: ' . $e->getMessage());
                }
            }
            
            // Append command results to response
            if (!empty($commandResults)) {
                $resultsText = "\n\n## Command Execution Results\n\n";
                foreach ($commandResults as $cmdResult) {
                    $resultsText .= "**Command:** `{$cmdResult['command']}`\n";
                    $resultsText .= "**Exit Code:** {$cmdResult['exit_code']}\n";
                    
                    if ($cmdResult['output']) {
                        $resultsText .= "**Output:**\n```\n{$cmdResult['output']}\n```\n";
                    }
                    
                    if ($cmdResult['error']) {
                        $resultsText .= "**Errors:**\n```\n{$cmdResult['error']}\n```\n";
                    }
                    
                    if ($cmdResult['exit_code'] === 0 && !$cmdResult['output'] && !$cmdResult['error']) {
                        $resultsText .= "**Result:** ✓ No issues found (command completed successfully)\n";
                    } elseif ($cmdResult['exit_code'] !== 0) {
                        $resultsText .= "**Result:** ✗ Issues detected (exit code: {$cmdResult['exit_code']})\n";
                    }
                    
                    $resultsText .= "\n";
                }
                
                $result['content'] = $content . $resultsText;
            }
        }
        
        return $result;
    }

    /**
     * Map old/fictional model names to real OpenAI models
     */
    private function mapModelToReal(string $model): string
    {
        // Map fictional models to real ones
        $modelMap = [
            'gpt-5' => 'gpt-4o',
            'gpt-5-mini' => 'gpt-4o-mini',
            'gpt-5-nano' => 'gpt-4o-mini',
            'gpt-5.2' => 'gpt-4o',
            'gpt-5.2-pro' => 'gpt-4-turbo',
            'gpt-5.1-codex-max' => 'gpt-4o',
        ];

        return $modelMap[$model] ?? $model;
    }

    /**
     * Call OpenAI API
     */
    private function callOpenAI(string $model, array $messages): array
    {
        $url = 'https://api.openai.com/v1/chat/completions';

        // Map model to real OpenAI model if needed
        $realModel = $this->mapModelToReal($model);

        $data = [
            'model' => $realModel,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiApiKey,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'error' => 'OpenAI API error: ' . $error,
            ];
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            return [
                'success' => false,
                'error' => 'OpenAI API error: ' . ($errorData['error']['message'] ?? "HTTP {$httpCode}"),
            ];
        }

        $result = json_decode($response, true);

        if (!isset($result['choices'][0]['message']['content'])) {
            return [
                'success' => false,
                'error' => 'Invalid response from OpenAI API',
            ];
        }

        return [
            'success' => true,
            'content' => $result['choices'][0]['message']['content'],
            'model' => $realModel,
            'usage' => $result['usage'] ?? null,
        ];
    }

    /**
     * True if an OpenAI API key is configured.
     */
    public function isConfigured(): bool
    {
        $this->loadSettings();
        return !empty($this->openaiApiKey);
    }

    /**
     * Low-level chat completion for callers that need raw, unmodified model
     * output (e.g. the Mail Security phishing analyzer). Unlike analyzeIssue(),
     * this performs NO config-file reading, diagnostic-command execution or
     * GOOD/BAD reformatting on the prompt or the response - critical when the
     * input is untrusted email content.
     *
     * @param array       $messages   OpenAI chat messages: [{role, content}, ...]
     * @param string|null $model      Model override (defaults to configured model)
     * @param int|null    $maxTokens  Token cap override
     * @param float|null  $temperature Sampling temperature override
     */
    public function chat(array $messages, ?string $model = null, ?int $maxTokens = null, ?float $temperature = null): array
    {
        $this->loadSettings();
        if (!$this->openaiApiKey) {
            return [
                'success' => false,
                'error' => 'OpenAI API key not configured. Please set it in AI Helper Settings.',
            ];
        }
        if ($maxTokens !== null) {
            $this->maxTokens = $maxTokens;
        }
        if ($temperature !== null) {
            $this->temperature = $temperature;
        }
        return $this->callOpenAI($model ?: $this->openaiModel, $messages);
    }

    /**
     * Select model based on task type
     */
    public function selectModelForTask(string $taskType): string
    {
        // Map to real OpenAI chat models (GPT-5 models map to GPT-4o variants)
        return match ($taskType) {
            'complex_diagnostic', 'multi_step' => 'gpt-5',
            'difficult_problem' => 'gpt-5',
            'code_analysis', 'config_parsing' => 'gpt-5',
            'simple_query', 'quick_response' => 'gpt-5-mini',
            'classification', 'basic_task' => 'gpt-5-nano',
            default => $this->openaiModel,
        };
    }

    /**
     * Detect task type from prompt and context
     */
    private function detectTaskType(string $prompt, array $context): string
    {
        $promptLower = strtolower($prompt);
        
        // Check for complex diagnostics
        if (preg_match('/\b(why|diagnose|troubleshoot|analyze|investigate|complex|multiple|several)\b/i', $prompt)) {
            return 'complex_diagnostic';
        }

        // Check for code/config analysis
        if (preg_match('/\b(config|syntax|parse|code|script|file)\b/i', $prompt)) {
            return 'code_analysis';
        }

        // Check for simple queries
        if (preg_match('/\b(what|how|when|where|status|check|show|list)\b/i', $prompt) && 
            !preg_match('/\b(why|diagnose|troubleshoot)\b/i', $prompt)) {
            return 'simple_query';
        }

        // Check for classification
        if (preg_match('/\b(classify|category|type|severity|level)\b/i', $prompt)) {
            return 'classification';
        }

        return 'complex_diagnostic'; // Default to complex
    }

    /**
     * Cache an identified issue
     */
    public function cacheIssue(
        string $issueType,
        ?string $service,
        string $issueKey,
        string $severity,
        string $description,
        ?array $metadata = null
    ): array {
        $stmt = $this->db->prepare("
            INSERT INTO ai_cached_issues (issue_type, service, issue_key, severity, description, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                severity = VALUES(severity),
                description = VALUES(description),
                metadata = VALUES(metadata),
                detected_at = NOW(),
                resolved_at = NULL
        ");

        $metadataJson = $metadata ? json_encode($metadata) : null;
        $stmt->execute([$issueType, $service, $issueKey, $severity, $description, $metadataJson]);

        $issueId = (int)$this->db->lastInsertId();

        return [
            'id' => $issueId,
            'issue_type' => $issueType,
            'service' => $service,
            'issue_key' => $issueKey,
            'severity' => $severity,
            'description' => $description,
            'metadata' => $metadata,
        ];
    }

    /**
     * Get cached issues
     */
    public function getCachedIssues(?string $service = null, bool $resolved = false): array
    {
        try {
            // Ensure tables exist before operation
            $this->ensureTablesExist();

            // Check if table exists
            $checkStmt = $this->db->query("SHOW TABLES LIKE 'ai_cached_issues'");
            if ($checkStmt->rowCount() === 0) {
                return []; // Table doesn't exist yet, return empty array
            }

            $sql = "SELECT id, issue_type, service, issue_key, severity, description, detected_at, resolved_at, metadata
                    FROM ai_cached_issues
                    WHERE 1=1";
            
            $params = [];

            if ($service) {
                $sql .= " AND service = ?";
                $params[] = $service;
            }

            if ($resolved) {
                $sql .= " AND resolved_at IS NOT NULL";
            } else {
                $sql .= " AND resolved_at IS NULL";
            }

            $sql .= " ORDER BY severity DESC, detected_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $issues = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $issues[] = [
                    'id' => (int)$row['id'],
                    'issue_type' => $row['issue_type'],
                    'service' => $row['service'],
                    'issue_key' => $row['issue_key'],
                    'severity' => $row['severity'],
                    'description' => $row['description'],
                    'detected_at' => $row['detected_at'],
                    'resolved_at' => $row['resolved_at'],
                    'metadata' => $row['metadata'] ? json_decode($row['metadata'], true) : null,
                ];
            }

            return $issues;
        } catch (\PDOException $e) {
            debug_log('Database error in getCachedIssues: ' . $e->getMessage());
            return []; // Return empty array on error
        } catch (\Exception $e) {
            debug_log('Error in getCachedIssues: ' . $e->getMessage());
            return []; // Return empty array on error
        }
    }

    /**
     * Mark issue as resolved
     */
    public function markIssueResolved(int $issueId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE ai_cached_issues
            SET resolved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$issueId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Enforce GOOD/BAD/RECOMMENDATIONS format - pass through if correct, otherwise rebuild
     */
    private function enforceFormat(string $content): string
    {
        $content = trim($content);
        
        // Clean up common markdown artifacts
        $content = preg_replace('/\*\*\s*and\s*\*\*:?/i', '', $content); // Remove "** and **:"
        $content = preg_replace('/\*\*:/', ':', $content); // Fix "**:" to just ":"
        $content = preg_replace('/=\s*\*\*/', '= ', $content); // Fix "= **" 
        $content = preg_replace('/\*\*\s*=/', ' =', $content); // Fix "** ="
        $content = preg_replace('/`([^`]+)\*\*`/', '`$1`', $content); // Fix "`value**`" to "`value`"
        $content = preg_replace('/`\*\*([^`]+)`/', '`$1`', $content); // Fix "`**value`" to "`value`"
        
        // Remove "Observation**:" and similar labels
        $content = preg_replace('/\b(Observation|Note|Analysis|Current Setting)\*\*:?\s*/i', '', $content);
        
        // Convert old ISSUES format to BAD
        $content = preg_replace('/\*\*ISSUES:\*\*/i', '**BAD:**', $content);
        $content = preg_replace('/ISSUES_SECTION/i', '**BAD:**', $content);
        $content = preg_replace('/GOOD_SECTION/i', '**GOOD:**', $content);
        $content = preg_replace('/BAD_SECTION/i', '**BAD:**', $content);
        $content = preg_replace('/RECOMMENDATIONS_SECTION/i', '**RECOMMENDATIONS:**', $content);
        
        // Extract all config items from the content
        $allItems = $this->extractAllConfigItems($content);
        
        // If no config items found, return original content
        // This handles cases where AI couldn't analyze (no file access, etc.)
        if (empty($allItems)) {
            debug_log('AIHelper: No config items extracted from response. Returning original content.');
            return $content;
        }
        
        // Categorize items into GOOD, BAD, and RECOMMENDATIONS
        $goodItems = [];
        $badItems = [];
        $recItems = [];
        
        foreach ($allItems as $item) {
            $config = $item['config'];
            $explanation = $item['explanation'];
            $filePath = $item['filePath'] ?? '';
            $context = strtolower($item['context']);
            $originalSection = $item['originalSection'] ?? 'unknown';
            
            // Clean config - remove stray ** markdown
            $config = preg_replace('/\*\*/', '', $config);
            $config = trim($config);
            
            // Infer file path if missing
            if (empty($filePath)) {
                $filePath = $this->inferFilePath($config);
            }
            
            // Clean explanation - remove labels and markdown artifacts
            $cleanExplanation = preg_replace('/^(Observation|Note|Current Setting|Recommendation|Explanation|Analysis)[\s*:]+/i', '', $explanation);
            $cleanExplanation = preg_replace('/^(This|The|It|That|These|Those|This setting|This configuration|Ensure that the|Consider setting|Enforce)\s+/i', '', $cleanExplanation);
            $cleanExplanation = preg_replace('/\*\*/', '', $cleanExplanation); // Remove stray **
            $cleanExplanation = preg_replace('/^[-*•:.\s]+/', '', $cleanExplanation);
            $cleanExplanation = preg_replace('/\s+and\s+\*\*:?\s*$/', '', $cleanExplanation); // Remove trailing "and **:"
            $cleanExplanation = trim($cleanExplanation);
            
            // Skip items with empty or too short explanations that look like artifacts
            if (strlen($cleanExplanation) < 5 && preg_match('/^(and|or|\*\*|:)+$/i', $cleanExplanation)) {
                continue;
            }
            
            $itemData = [
                'config' => $config,
                'filePath' => $filePath,
                'explanation' => $cleanExplanation
            ];
            
            // FIRST: Respect the original section from AI response
            if ($originalSection === 'good') {
                $goodItems[] = $itemData;
            } elseif ($originalSection === 'bad') {
                $badItems[] = $itemData;
            } elseif ($originalSection === 'recommendations') {
                $recItems[] = $itemData;
            } else {
                // FALLBACK: Categorize based on keywords if original section unknown
                $textToCheck = $context . ' ' . $cleanExplanation;
                
                $isBad = preg_match('/\b(should be|must be|incorrect|wrong|vulnerability|risk|insecure|dangerous|problem|issue|error|missing|disabled when|allows unencrypted|too permissive|excessive|debug.*production|not recommended|weak|outdated)\b/i', $textToCheck);
                $isRecommendation = preg_match('/\b(consider|recommend|optional|could|might want|nice to have|for better|improvement|enhancement|if.*required|if.*needed|ensure)\b/i', $textToCheck);
                $isGood = preg_match('/\b(correct|proper|secure|secured|enabled|good|appropriate|configured correctly|working|valid|protects|prevents|restricts|essential|necessary|best practice|security practice|important|enabled for security)\b/i', $textToCheck);
                
                // Prioritize: BAD > GOOD > RECOMMENDATION
                if ($isBad && !$isGood) {
                    $badItems[] = $itemData;
                } elseif ($isGood && !$isBad) {
                    $goodItems[] = $itemData;
                } elseif ($isRecommendation) {
                    $recItems[] = $itemData;
                } else {
                    // Default to recommendations if unclear
                    $recItems[] = $itemData;
                }
            }
        }
        
        // Build the formatted response
        $result = "**GOOD:**\n";
        if (empty($goodItems)) {
            $result .= "- No significant good configurations found.\n\n";
        } else {
            foreach ($goodItems as $item) {
                $fileRef = !empty($item['filePath']) ? " [[{$item['filePath']}]]" : '';
                $result .= "- {$item['config']}{$fileRef}\n";
                if (!empty($item['explanation'])) {
                    $result .= "  {$item['explanation']}\n";
                }
                $result .= "\n";
            }
        }
        
        $result .= "**BAD:**\n";
        if (empty($badItems)) {
            $result .= "- No critical issues found.\n\n";
        } else {
            foreach ($badItems as $item) {
                $fileRef = !empty($item['filePath']) ? " [[{$item['filePath']}]]" : '';
                $result .= "- {$item['config']}{$fileRef}\n";
                if (!empty($item['explanation'])) {
                    $result .= "  {$item['explanation']}\n";
                }
                $result .= "\n";
            }
        }
        
        $result .= "**RECOMMENDATIONS:**\n";
        if (empty($recItems)) {
            $result .= "- No additional recommendations.\n";
        } else {
            foreach ($recItems as $item) {
                $fileRef = !empty($item['filePath']) ? " [[{$item['filePath']}]]" : '';
                $result .= "- {$item['config']}{$fileRef}\n";
                if (!empty($item['explanation'])) {
                    $result .= "  {$item['explanation']}\n";
                }
                $result .= "\n";
            }
        }
        
        return trim($result);
    }
    
    /**
     * Infer config file path from setting name
     */
    private function inferFilePath(string $config): string
    {
        // Extract the setting name from backticks
        if (preg_match('/`([^`=]+)/', $config, $matches)) {
            $setting = strtolower(trim($matches[1]));
        } else {
            $setting = strtolower($config);
        }
        
        // Postfix settings
        if (preg_match('/^(smtpd_|smtp_|mydestination|myhostname|mynetworks|inet_|message_size|mailbox_size|virtual_|relay|transport|alias|canonical|sender_|recipient_|header_|body_|milter)/', $setting)) {
            return '/etc/postfix/main.cf';
        }
        
        // Dovecot settings
        if (preg_match('/^(ssl_|auth_|mail_|login_|service |passdb|userdb|disable_plaintext|protocols)/', $setting)) {
            if (preg_match('/^ssl_/', $setting)) {
                return '/etc/dovecot/conf.d/10-ssl.conf';
            }
            if (preg_match('/^(auth_|passdb|userdb|disable_plaintext)/', $setting)) {
                return '/etc/dovecot/conf.d/10-auth.conf';
            }
            if (preg_match('/^mail_/', $setting)) {
                return '/etc/dovecot/conf.d/10-mail.conf';
            }
            return '/etc/dovecot/dovecot.conf';
        }
        
        // SSH settings
        if (preg_match('/^(permit|password|pubkey|challenge|use|x11|allow|deny|max|login|port|listen|host|protocol)/', $setting)) {
            return '/etc/ssh/sshd_config';
        }
        
        // Fail2ban
        if (preg_match('/^(bantime|findtime|maxretry|ignoreip|enabled|filter|action|logpath)/', $setting)) {
            return '/etc/fail2ban/jail.local';
        }
        
        return '';
    }
    
    /**
     * Add missing file paths to content that already has sections
     */
    private function addMissingFilePaths(string $content): string
    {
        // Find config lines without file paths and add them
        return preg_replace_callback(
            '/^(- `[^`]+`)\s*$/m',
            function ($matches) {
                $config = $matches[1];
                $filePath = $this->inferFilePath($config);
                if (!empty($filePath)) {
                    return $config . " [[{$filePath}]]";
                }
                return $config;
            },
            $content
        );
    }
    
    /**
     * Extract all config items from content
     */
    private function extractAllConfigItems(string $content): array
    {
        $items = [];
        $lines = explode("\n", $content);
        $currentItem = null;
        $currentSection = 'unknown'; // Track which section we're in
        
        foreach ($lines as $line) {
            $originalLine = $line;
            $line = trim($line);
            
            // Skip empty lines (but finish current item)
            if (empty($line)) {
                if ($currentItem !== null && !empty($currentItem['config'])) {
                    $items[] = $currentItem;
                    $currentItem = null;
                }
                continue;
            }
            
            // Track which section we're in
            if (preg_match('/^\*?\*?GOOD[_:]?\*?\*?/i', $line)) {
                $currentSection = 'good';
                continue;
            }
            if (preg_match('/^\*?\*?(BAD|ISSUES)[_:]?\*?\*?/i', $line)) {
                $currentSection = 'bad';
                continue;
            }
            if (preg_match('/^\*?\*?RECOMMENDATIONS?[_:]?\*?\*?/i', $line)) {
                $currentSection = 'recommendations';
                continue;
            }
            
            // Skip generic section headers
            if (preg_match('/^\*?\*?(GOOD|BAD|ISSUES|RECOMMENDATIONS)[_:]?\*?\*?/i', $line)) {
                continue;
            }
            
            // Skip numbered lists
            if (preg_match('/^\d+\.\s/', $line)) {
                continue;
            }
            
            // Skip labels
            if (preg_match('/^(Current Setting|Recommendation|Explanation|Observation|Note):/i', $line)) {
                continue;
            }
            
            // Look for config setting with backticks and optional [[filepath]]
            if (preg_match('/`([^`=]+=[^`]+)`/', $line, $matches)) {
                // Finish previous item
                if ($currentItem !== null && !empty($currentItem['config'])) {
                    $items[] = $currentItem;
                }
                
                // Start new item
                $config = '`' . $matches[1] . '`';
                
                // Extract file path if present [[/path/to/file]]
                $filePath = '';
                if (preg_match('/\[\[([^\]]+)\]\]/', $line, $pathMatches)) {
                    $filePath = $pathMatches[1];
                }
                
                // Extract explanation - preserve recommended values in backticks
                // First remove the config setting we already captured (the first backticked value)
                $explanation = preg_replace('/`' . preg_quote($matches[1], '/') . '`/', '', $line, 1);
                $explanation = preg_replace('/\[\[[^\]]+\]\]/', '', $explanation);
                $explanation = preg_replace('/^[-*•:.\s]+/', '', $explanation);
                $explanation = trim($explanation);
                
                $currentItem = [
                    'config' => $config,
                    'filePath' => $filePath,
                    'explanation' => $explanation,
                    'context' => $originalLine,
                    'originalSection' => $currentSection,
                    'collecting' => true
                ];
            }
            // Check if this is a continuation (indented line or bullet with colon)
            elseif ($currentItem !== null && ($currentItem['collecting'] ?? false)) {
                // Check for indented continuation
                if (preg_match('/^\s{2,}(.+)$/', $originalLine, $matches)) {
                    $additional = trim($matches[1]);
                    // Remove leading bullet or colon
                    $additional = preg_replace('/^[-*•:.\s]+/', '', $additional);
                    $additional = trim($additional);
                    if (!empty($additional)) {
                        $currentItem['explanation'] .= ' ' . $additional;
                        $currentItem['context'] .= ' ' . $originalLine;
                    }
                }
                // Check for bullet point continuation (same level)
                elseif (preg_match('/^[-*•]\s*[:.]?\s*(.+)$/', $line, $matches)) {
                    $additional = trim($matches[1]);
                    if (!empty($additional) && !preg_match('/`[^`]+`/', $additional)) {
                        // Only add if it doesn't look like a new config item
                        $currentItem['explanation'] .= ' ' . $additional;
                        $currentItem['context'] .= ' ' . $originalLine;
                    } else {
                        // Looks like a new item, finish current one
                        $currentItem['collecting'] = false;
                        if (!empty($currentItem['config'])) {
                            $items[] = $currentItem;
                        }
                        $currentItem = null;
                    }
                }
                // If line doesn't match continuation patterns, finish collecting
                elseif (!preg_match('/^\s*$/', $line)) {
                    $currentItem['collecting'] = false;
                }
            }
            // Check if line contains config without backticks
            elseif (preg_match('/([a-z_]+)\s*=\s*([^\s:\[\]]+)/i', $line, $matches)) {
                // Finish previous item
                if ($currentItem !== null && !empty($currentItem['config'])) {
                    $items[] = $currentItem;
                }
                
                $config = '`' . $matches[1] . ' = ' . $matches[2] . '`';
                
                // Extract file path if present
                $filePath = '';
                if (preg_match('/\[\[([^\]]+)\]\]/', $line, $pathMatches)) {
                    $filePath = $pathMatches[1];
                }
                
                $explanation = preg_replace('/[a-z_]+\s*=\s*[^\s:\[\]]+/i', '', $line);
                $explanation = preg_replace('/\[\[[^\]]+\]\]/', '', $explanation);
                $explanation = preg_replace('/^[-*:.\s]+/', '', $explanation);
                $explanation = trim($explanation);
                
                $currentItem = [
                    'config' => $config,
                    'filePath' => $filePath,
                    'explanation' => $explanation,
                    'context' => $originalLine,
                    'originalSection' => $currentSection
                ];
            }
        }
        
        // Add last item if exists
        if ($currentItem !== null && !empty($currentItem['config'])) {
            $items[] = $currentItem;
        }
        
        return $items;
    }
    
    /**
     * Clean section content - remove unwanted formatting
     */
    private function cleanSection(string $content): string
    {
        $lines = explode("\n", $content);
        $cleaned = [];
        $currentItem = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                // Empty line - finish current item if exists
                if ($currentItem !== null) {
                    $cleaned[] = "- {$currentItem['config']}";
                    if (!empty($currentItem['explanation'])) {
                        $cleaned[] = "  " . $currentItem['explanation'];
                    }
                    $currentItem = null;
                }
                continue;
            }
            
            // Skip numbered lists (1., 2., etc.)
            if (preg_match('/^\d+\.\s/', $line)) {
                continue;
            }
            
            // Skip labels like Current Setting, Recommendation, etc.
            if (preg_match('/^(Current Setting|Recommendation|Explanation|Observation|Note):/i', $line)) {
                continue;
            }
            
            // Check if this is a bullet point with config setting
            if (preg_match('/^[-*]\s*`([^`]+)`\s*(.*)$/', $line, $matches)) {
                // Finish previous item if exists
                if ($currentItem !== null) {
                    $cleaned[] = "- {$currentItem['config']}";
                    if (!empty($currentItem['explanation'])) {
                        $cleaned[] = "  " . $currentItem['explanation'];
                    }
                }
                
                // Start new item
                $config = '`' . $matches[1] . '`';
                $explanation = trim($matches[2]);
                // Remove leading colons, periods, bullets, etc.
                $explanation = preg_replace('/^[-*•:.\s]+/', '', $explanation);
                $explanation = trim($explanation);
                $currentItem = [
                    'config' => $config,
                    'explanation' => $explanation
                ];
            }
            // Check if this is an indented explanation line (starts with spaces)
            elseif ($currentItem !== null && preg_match('/^\s{2,}(.+)$/', $line, $matches)) {
                // Append to current explanation
                $additional = trim($matches[1]);
                // Remove leading colons, periods, bullets, etc.
                $additional = preg_replace('/^[-*•:.\s]+/', '', $additional);
                $additional = trim($additional);
                if (!empty($additional)) {
                    $currentItem['explanation'] = ($currentItem['explanation'] ? $currentItem['explanation'] . ' ' : '') . $additional;
                }
            }
            // Check if line contains a config setting without bullet point
            elseif (preg_match('/`([^`=]+=[^`]+)`/', $line, $matches)) {
                // Finish previous item if exists
                if ($currentItem !== null) {
                    $cleaned[] = "- {$currentItem['config']}";
                    if (!empty($currentItem['explanation'])) {
                        $cleaned[] = "  " . $currentItem['explanation'];
                    }
                }
                
                // Extract config and explanation
                $config = $matches[0];
                $explanation = preg_replace('/`[^`]+`/', '', $line);
                $explanation = trim($explanation);
                $explanation = preg_replace('/^[:.]\s*/', '', $explanation); // Remove leading colon or period
                
                $cleaned[] = "- {$config}";
                if (!empty($explanation)) {
                    // Remove leading colons, periods, bullets, etc.
                    $explanation = preg_replace('/^[-*•:.\s]+/', '', $explanation);
                    $explanation = trim($explanation);
                    $cleaned[] = "  " . $explanation;
                }
                $currentItem = null;
            }
        }
        
        // Finish last item if exists
        if ($currentItem !== null) {
            $cleaned[] = "- {$currentItem['config']}";
            if (!empty($currentItem['explanation'])) {
                $cleaned[] = "  " . $currentItem['explanation'];
            }
        }
        
        return implode("\n", $cleaned);
    }
    
    /**
     * Limit explanation to 5 words, ensure it's complete
     */
    private function limitExplanation(string $explanation): string
    {
        if (empty($explanation)) {
            return '';
        }
        
        // Remove common prefixes
        $explanation = preg_replace('/^(This|The|It|That|These|Those|This setting|This configuration|Ensure that the|Consider setting|Enforce)\s+/i', '', $explanation);
        $explanation = trim($explanation);
        
        // Remove leading colons, periods, bullets, etc.
        $explanation = preg_replace('/^[-*•:.\s]+/', '', $explanation);
        $explanation = trim($explanation);
        
        // Return the full explanation without word limits
        return trim($explanation);
    }
    
    /**
     * Parse content and categorize into GOOD/ISSUES when format is wrong
     */
    private function parseAndCategorize(string $content): string
    {
        $lines = explode("\n", $content);
        $goodItems = [];
        $issuesItems = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Look for config settings
            if (preg_match('/`([^`=]+=[^`]+)`/', $line, $matches)) {
                $config = $matches[0];
                $lowerLine = strtolower($line);
                
                // Categorize based on keywords
                if (preg_match('/\b(good|correct|proper|secure|enabled|yes|set correctly|appropriate)\b/', $lowerLine)) {
                    $explanation = $this->extractExplanation($line);
                    $goodItems[] = "- {$config}";
                    if ($explanation) {
                        $goodItems[] = "  " . $explanation;
                    }
                } elseif (preg_match('/\b(should|consider|recommend|issue|problem|missing|incorrect|wrong|may|might|improve|change)\b/', $lowerLine)) {
                    $explanation = $this->extractExplanation($line);
                    $issuesItems[] = "- {$config}";
                    if ($explanation) {
                        $issuesItems[] = "  " . $explanation;
                    }
                }
            }
        }
        
        $result = "**GOOD:**\n" . implode("\n", array_slice($goodItems, 0, 20));
        $result .= "\n\n**ISSUES:**\n" . implode("\n", array_slice($issuesItems, 0, 20));
        
        return $result;
    }
    
    /**
     * Extract explanation from a line - preserve recommended values
     */
    private function extractExplanation(string $line): string
    {
        // Remove only the first config setting (keep recommended values)
        $line = preg_replace('/`[^`]+`/', '', $line, 1);
        // Remove labels
        $line = preg_replace('/^(Current Setting|Recommendation|Explanation):\s*/i', '', $line);
        $line = trim($line);
        
        // Return full explanation - don't truncate recommended values
        return $line;
    }

    /**
     * Get the effective system prompt with language instruction
     */
    private function getEffectiveSystemPrompt(): string
    {
        $languageInstruction = '';
        
        if ($this->responseLanguage === 'hu') {
            $languageInstruction = "\n\nIMPORTANT: You MUST respond in Hungarian (Magyar nyelv). All explanations, recommendations, and descriptions must be in Hungarian. Keep technical terms like config names, commands, and file paths in English, but write all other text in Hungarian.";
        } else {
            $languageInstruction = "\n\nIMPORTANT: You MUST respond in English.";
        }
        
        return $this->systemPrompt . $languageInstruction;
    }

    /**
     * Get default system prompt
     */
    private function getDefaultSystemPrompt(): string
    {
        return "You are a server config expert. Be CONCISE. SHORT answers only.

RULES:
- NEVER use GOOD/BAD/ISSUES/RECOMMENDATIONS sections unless user explicitly asks for full analysis
- For 'is this correct?' → Answer YES or NO with one short reason
- For 'how to improve?' → Say 'Already optimal' or provide the EXACT recommended value (e.g., 'Change to 10' or 'Set value = 10')
- For 'what does this do?' → One sentence explanation only
- Keep ALL answers under 3 sentences unless user asks for more detail

CRITICAL: When recommending a change, ALWAYS specify the exact value to use. NEVER say 'Change it to .' without the actual recommended value. If you don't know the best value, explain what factors determine it.";
    }

}

