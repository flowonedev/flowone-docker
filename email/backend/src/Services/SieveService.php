<?php

namespace Webmail\Services;

/**
 * SieveService - Connects to Dovecot ManageSieve for server-side email filtering
 * 
 * ManageSieve runs on port 4190 by default
 * This allows filters to run 24/7, even when the webmail app is closed
 */
class SieveService
{
    private $socket = null;
    private string $host;
    private int $port;
    private bool $useTls;
    private ?string $lastError = null;
    
    // Script name used for our filters
    const SCRIPT_NAME = 'webmail_filters';
    
    public function __construct(array $config = [])
    {
        $this->host = $config['sieve_host'] ?? $config['host'] ?? 'localhost';
        $this->port = $config['sieve_port'] ?? 4190;
        $this->useTls = $config['sieve_tls'] ?? false;
    }
    
    /**
     * Connect and authenticate to ManageSieve server
     */
    public function connect(string $email, string $password): bool
    {
        $this->lastError = null;
        
        error_log("SieveService: Attempting connection to {$this->host}:{$this->port} for $email");
        
        try {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ]);
            
            $this->socket = @stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$this->socket) {
                $this->lastError = "Connection failed: $errstr ($errno)";
                error_log("SieveService: " . $this->lastError);
                return false;
            }
            
            error_log("SieveService: Socket connected, reading greeting...");
            
            // Set timeout
            stream_set_timeout($this->socket, 10);
            
            // Read greeting
            $greeting = $this->readResponse();
            if (!$greeting) {
                $this->lastError = "No greeting received";
                error_log("SieveService: " . $this->lastError);
                return false;
            }
            
            error_log("SieveService: Greeting received: " . substr($greeting, 0, 200) . "...");
            
            // Start TLS if configured
            if ($this->useTls) {
                error_log("SieveService: Starting TLS...");
                $this->sendCommand("STARTTLS");
                $response = $this->readResponse();
                if (!$this->isOk($response)) {
                    $this->lastError = "STARTTLS failed: $response";
                    error_log("SieveService: " . $this->lastError);
                    return false;
                }
                
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->lastError = "TLS negotiation failed";
                    error_log("SieveService: " . $this->lastError);
                    return false;
                }
                
                error_log("SieveService: TLS enabled, re-reading capabilities...");
                // Re-read capabilities after TLS
                $this->readResponse();
            }
            
            // Authenticate using PLAIN
            error_log("SieveService: Authenticating as $email...");
            $authString = base64_encode("\0$email\0$password");
            $this->sendCommand("AUTHENTICATE \"PLAIN\" \"$authString\"");
            
            $response = $this->readResponse();
            if (!$this->isOk($response)) {
                $this->lastError = "Authentication failed: $response";
                error_log("SieveService: " . $this->lastError);
                return false;
            }
            
            error_log("SieveService: Authentication successful!");
            return true;
            
        } catch (\Exception $e) {
            $this->lastError = "Exception: " . $e->getMessage();
            error_log("SieveService connect error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Disconnect from ManageSieve server
     */
    public function disconnect(): void
    {
        if ($this->socket) {
            $this->sendCommand("LOGOUT");
            fclose($this->socket);
            $this->socket = null;
        }
    }
    
    /**
     * List all Sieve scripts
     */
    public function listScripts(): array
    {
        if (!$this->socket) {
            return [];
        }
        
        $this->sendCommand("LISTSCRIPTS");
        $response = $this->readResponse();
        
        $scripts = [];
        foreach (explode("\n", $response) as $line) {
            if (preg_match('/^"([^"]+)"(\s+ACTIVE)?/i', $line, $matches)) {
                $scripts[] = [
                    'name' => $matches[1],
                    'active' => !empty($matches[2]),
                ];
            }
        }
        
        return $scripts;
    }
    
    /**
     * Get a Sieve script content
     */
    public function getScript(string $name): ?string
    {
        if (!$this->socket) {
            return null;
        }
        
        $this->sendCommand("GETSCRIPT \"$name\"");
        $response = $this->readResponse();
        
        if (!$this->isOk($response)) {
            return null;
        }
        
        // Extract script content (between {size} and OK)
        if (preg_match('/\{(\d+)\}\r?\n(.+?)(?=\r?\nOK)/s', $response, $matches)) {
            return $matches[2];
        }
        
        return null;
    }
    
    /**
     * Upload/update a Sieve script
     */
    public function putScript(string $name, string $content): bool
    {
        if (!$this->socket) {
            error_log("SieveService::putScript - No socket connection");
            return false;
        }
        
        $size = strlen($content);
        error_log("SieveService::putScript - Uploading script '$name' with size $size bytes");
        
        // Use synchronizing literal {size+} for non-synchronizing servers
        $this->sendCommand("PUTSCRIPT \"$name\" {" . $size . "+}");
        fwrite($this->socket, $content . "\r\n");
        
        $response = $this->readResponse();
        
        // Log the full response, replacing newlines for readability
        $logResponse = str_replace(["\r\n", "\r", "\n"], " | ", trim($response));
        error_log("SieveService::putScript - Server response: " . $logResponse);
        
        if (!$this->isOk($response)) {
            // Extract detailed error message if present
            $errorDetail = $this->extractErrorDetail($response);
            $this->lastError = "Script upload failed: " . $errorDetail;
            error_log("SieveService::putScript - FAILED: " . $this->lastError);
            return false;
        }
        
        error_log("SieveService::putScript - Script uploaded successfully");
        return true;
    }
    
    /**
     * Extract detailed error message from server response
     */
    private function extractErrorDetail(string $response): string
    {
        // Check for literal error message: NO {size}\r\n<message>
        if (preg_match('/^NO\s*\{(\d+)\}\r?\n(.+)/s', $response, $matches)) {
            $expectedSize = (int)$matches[1];
            $message = $matches[2];
            // Clean up the message
            $message = trim($message);
            return $message;
        }
        
        // Check for inline error message: NO "message" or NO (tag) "message"
        if (preg_match('/^NO\s+(?:\([^)]+\)\s+)?"([^"]+)"/m', $response, $matches)) {
            return $matches[1];
        }
        
        // Check for simple NO with text
        if (preg_match('/^NO\s+(.+)$/m', $response, $matches)) {
            return trim($matches[1]);
        }
        
        return trim($response);
    }
    
    /**
     * Delete a Sieve script
     */
    public function deleteScript(string $name): bool
    {
        if (!$this->socket) {
            return false;
        }
        
        $this->sendCommand("DELETESCRIPT \"$name\"");
        $response = $this->readResponse();
        return $this->isOk($response);
    }
    
    /**
     * Activate a Sieve script
     */
    public function activateScript(string $name): bool
    {
        if (!$this->socket) {
            return false;
        }
        
        $this->sendCommand("SETACTIVE \"$name\"");
        $response = $this->readResponse();
        return $this->isOk($response);
    }
    
    /**
     * Deactivate all scripts
     */
    public function deactivateScripts(): bool
    {
        if (!$this->socket) {
            return false;
        }
        
        $this->sendCommand("SETACTIVE \"\"");
        $response = $this->readResponse();
        return $this->isOk($response);
    }
    
    /**
     * Convert webmail filters to a standalone Sieve script (legacy helper).
     * Prefer generateFullScript() which includes blocked/safe senders and vacation.
     */
    public function filtersToSieve(array $filters): string
    {
        return $this->generateFullScript($filters);
    }
    
    /**
     * Generate a complete Sieve script with filters, blocked/safe senders, and vacation.
     * This is the single source of truth for all Sieve script generation.
     *
     * Script order:
     *   1. require statement
     *   2. Safe sender whitelist (keep + stop, bypass blocks/spam)
     *   3. Blocked sender rules (fileinto spam + stop)
     *   4. User-created filter rules
     *   5. Vacation auto-reply
     *
     * @param array $filters       User's email filters from DB
     * @param array|null $vacation Vacation config: ['enabled'=>bool, 'subject'=>str, 'message'=>str, 'from'=>str]
     * @param array $blockedSenders Rows from webmail_blocked_senders
     * @param array $safeSenders    Rows from webmail_safe_senders
     */
    public function generateFullScript(
        array $filters,
        ?array $vacation = null,
        array $blockedSenders = [],
        array $safeSenders = [],
        string $spamFolderName = 'INBOX.Spam'
    ): string {
        $extensions = $this->collectRequiredExtensions($filters);
        $hasVacation = $vacation && !empty($vacation['enabled']) && !empty($vacation['message']);
        $useSafeFlag = !empty($safeSenders) && !empty($blockedSenders);

        if ($hasVacation) {
            $extensions[] = 'vacation';
        }
        if ($useSafeFlag) {
            $extensions[] = 'variables';
        }

        $extensions = array_values(array_unique($extensions));

        $script = "# Webmail Filters - Auto-generated script\n";
        $script .= "# Do not edit manually - changes will be overwritten\n\n";
        $script .= 'require ["' . implode('", "', $extensions) . "\"];\n\n";

        // --- Trusted sender flag (used to skip blocked-sender rules) ---
        if ($useSafeFlag) {
            $script .= "set \"is_safe\" \"no\";\n\n";
        }

        // --- Trusted senders (whitelist) ---
        if (!empty($safeSenders)) {
            $script .= "# === TRUSTED SENDERS (whitelist) ===\n";
            foreach ($safeSenders as $safe) {
                $safeEmail = strtolower($safe['safe_email'] ?? '');
                $safeDomain = $safe['safe_domain'] ?? null;

                if ($safeDomain) {
                    $escaped = $this->escapeSieveString($safeDomain);
                    $script .= "# Trusted domain: @{$safeDomain}\n";
                    if ($useSafeFlag) {
                        $script .= "if address :domain :is \"from\" \"{$escaped}\" {\n    set \"is_safe\" \"yes\";\n}\n";
                    } else {
                        $script .= "if address :domain :is \"from\" \"{$escaped}\" {\n    keep;\n}\n";
                    }
                } elseif ($safeEmail) {
                    $escaped = $this->escapeSieveString($safeEmail);
                    if ($useSafeFlag) {
                        $script .= "if address :is \"from\" \"{$escaped}\" {\n    set \"is_safe\" \"yes\";\n}\n";
                    } else {
                        $script .= "if address :is \"from\" \"{$escaped}\" {\n    keep;\n}\n";
                    }
                }
            }
            $script .= "\n";
        }

        // --- Blocked senders ---
        if (!empty($blockedSenders)) {
            $spamFolder = $this->escapeSieveString($spamFolderName);
            $script .= "# === BLOCKED SENDERS ===\n";

            if ($useSafeFlag) {
                $script .= "if string :is \"\${is_safe}\" \"no\" {\n";
            }

            $indent = $useSafeFlag ? '    ' : '';

            foreach ($blockedSenders as $blocked) {
                $blockedEmail = strtolower($blocked['blocked_email'] ?? '');
                $blockedDomain = $blocked['blocked_domain'] ?? null;

                if ($blockedDomain) {
                    $escaped = $this->escapeSieveString($blockedDomain);
                    $script .= "{$indent}# Block domain: @{$blockedDomain}\n";
                    $script .= "{$indent}if address :domain :is \"from\" \"{$escaped}\" {\n{$indent}    fileinto \"{$spamFolder}\";\n{$indent}    stop;\n{$indent}}\n";
                } elseif ($blockedEmail) {
                    $escaped = $this->escapeSieveString($blockedEmail);
                    $script .= "{$indent}# Block: {$blockedEmail}\n";
                    $script .= "{$indent}if address :is \"from\" \"{$escaped}\" {\n{$indent}    fileinto \"{$spamFolder}\";\n{$indent}    stop;\n{$indent}}\n";
                }
            }

            if ($useSafeFlag) {
                $script .= "}\n";
            }
            $script .= "\n";
        }

        // --- User-created filter rules ---
        foreach ($filters as $filter) {
            if (!$filter['enabled']) continue;
            $script .= "# Filter: {$filter['name']}\n";
            $script .= $this->filterToSieveRule($filter);
            $script .= "\n";
        }

        // --- Vacation auto-reply ---
        if ($hasVacation) {
            $subject = $this->escapeSieveString($vacation['subject'] ?? 'Out of Office');
            $from = $this->escapeSieveString($vacation['from'] ?? '');

            $message = strip_tags($vacation['message']);
            $message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
            $message = trim($message);
            $message = substr($message, 0, 5000);
            $message = $this->escapeSieveString($message);

            if (empty($subject)) {
                $subject = 'Out of Office';
            }

            $script .= "# === VACATION AUTO-REPLY START ===\n";
            $script .= "vacation :days 1 :from \"{$from}\" :subject \"{$subject}\"\n";
            $script .= "\"{$message}\";\n";
            $script .= "# === VACATION AUTO-REPLY END ===\n";
        }

        return $script;
    }

    /**
     * Collect all required Sieve extensions from filters + defaults.
     */
    private function collectRequiredExtensions(array $filters): array
    {
        $extensions = ['fileinto'];
        $hasFlags = false;
        $hasBody = false;
        $hasRegex = false;

        foreach ($filters as $filter) {
            if (!$filter['enabled']) continue;

            foreach (($filter['conditions']['rules'] ?? []) as $rule) {
                if (($rule['field'] ?? '') === 'body') $hasBody = true;
                if (($rule['operator'] ?? '') === 'matches_regex') $hasRegex = true;
            }
            foreach (($filter['conditions']['groups'] ?? []) as $group) {
                foreach (($group['rules'] ?? []) as $rule) {
                    if (($rule['field'] ?? '') === 'body') $hasBody = true;
                    if (($rule['operator'] ?? '') === 'matches_regex') $hasRegex = true;
                }
            }

            foreach ($filter['actions'] ?? [] as $action) {
                if (in_array($action['action'] ?? '', ['star', 'unstar', 'mark_read', 'mark_unread'])) {
                    $hasFlags = true;
                }
            }
        }

        if ($hasFlags) $extensions[] = 'imap4flags';
        if ($hasBody) $extensions[] = 'body';
        if ($hasRegex) $extensions[] = 'regex';

        return $extensions;
    }
    
    /**
     * @deprecated Use SieveSyncService::getActiveVacationConfig() directly.
     */
    public static function getActiveVacationConfig(string $email, string $settingsDir = '/var/www/vps-email/data/settings'): ?array
    {
        return SieveSyncService::getActiveVacationConfig($email, $settingsDir);
    }
    
    /**
     * Convert a single filter to Sieve rule
     */
    private function filterToSieveRule(array $filter): string
    {
        $conditions = $filter['conditions'] ?? [];
        $actions = $filter['actions'] ?? [];
        
        // Build main conditions (supports both legacy and groups format)
        $mainConditionStr = $this->buildSieveConditions($conditions);
        
        if (!$mainConditionStr) {
            return "# (no conditions)\n";
        }
        
        // Build exception conditions
        $exceptions = $conditions['exceptions'] ?? null;
        $exceptionConditionStr = null;
        if ($exceptions && !empty($exceptions['rules'])) {
            $exceptionConditionStr = $this->buildSieveExceptions($exceptions);
        }
        
        // Combine main conditions with exceptions
        // Logic: match main conditions AND NOT any exception
        if ($exceptionConditionStr) {
            $conditionStr = "allof ($mainConditionStr,\n         $exceptionConditionStr)";
        } else {
            $conditionStr = $mainConditionStr;
        }
        
        // Build actions
        $sieveActions = [];
        foreach ($actions as $action) {
            $sieveAction = $this->actionToSieve($action);
            if ($sieveAction) {
                $sieveActions[] = $sieveAction;
            }
        }
        
        if (empty($sieveActions)) {
            return "# (no valid actions)\n";
        }
        
        // Generate if statement
        $result = "if $conditionStr {\n";
        foreach ($sieveActions as $action) {
            $result .= "    $action;\n";
        }
        
        if ($filter['stop_processing'] ?? false) {
            $result .= "    stop;\n";
        }
        
        $result .= "}\n";
        
        return $result;
    }
    
    /**
     * Build Sieve conditions from conditions array (supports legacy and groups format)
     */
    private function buildSieveConditions(array $conditions): ?string
    {
        // Check if using new groups format
        if (isset($conditions['groups']) && is_array($conditions['groups']) && !empty($conditions['groups'])) {
            return $this->buildSieveConditionsFromGroups($conditions);
        }
        
        // Legacy format with flat rules array
        $matchAll = ($conditions['match'] ?? 'all') === 'all';
        $rules = $conditions['rules'] ?? [];
        
        if (empty($rules)) {
            return null;
        }
        
        $sieveConditions = [];
        foreach ($rules as $rule) {
            $condition = $this->ruleToSieveCondition($rule);
            if ($condition) {
                $sieveConditions[] = $condition;
            }
        }
        
        if (empty($sieveConditions)) {
            return null;
        }
        
        if (count($sieveConditions) === 1) {
            return $sieveConditions[0];
        }
        
        $combiner = $matchAll ? 'allof' : 'anyof';
        return "$combiner (" . implode(",\n         ", $sieveConditions) . ")";
    }
    
    /**
     * Build Sieve conditions from groups format
     */
    private function buildSieveConditionsFromGroups(array $conditions): ?string
    {
        $groups = $conditions['groups'] ?? [];
        $groupsMatchType = $conditions['match'] ?? 'all'; // How to combine groups
        
        if (empty($groups)) {
            return null;
        }
        
        $groupConditions = [];
        
        foreach ($groups as $group) {
            $groupMatch = ($group['match'] ?? 'all') === 'all';
            $rules = $group['rules'] ?? [];
            
            if (empty($rules)) {
                continue;
            }
            
            $sieveConditions = [];
            foreach ($rules as $rule) {
                $condition = $this->ruleToSieveCondition($rule);
                if ($condition) {
                    $sieveConditions[] = $condition;
                }
            }
            
            if (empty($sieveConditions)) {
                continue;
            }
            
            if (count($sieveConditions) === 1) {
                $groupConditions[] = $sieveConditions[0];
            } else {
                $combiner = $groupMatch ? 'allof' : 'anyof';
                $groupConditions[] = "$combiner (" . implode(",\n             ", $sieveConditions) . ")";
            }
        }
        
        if (empty($groupConditions)) {
            return null;
        }
        
        if (count($groupConditions) === 1) {
            return $groupConditions[0];
        }
        
        $groupCombiner = $groupsMatchType === 'all' ? 'allof' : 'anyof';
        return "$groupCombiner (" . implode(",\n         ", $groupConditions) . ")";
    }
    
    /**
     * Build Sieve exception conditions (negated)
     */
    private function buildSieveExceptions(array $exceptions): ?string
    {
        $rules = $exceptions['rules'] ?? [];
        
        if (empty($rules)) {
            return null;
        }
        
        $sieveConditions = [];
        foreach ($rules as $rule) {
            $condition = $this->ruleToSieveCondition($rule);
            if ($condition) {
                $sieveConditions[] = $condition;
            }
        }
        
        if (empty($sieveConditions)) {
            return null;
        }
        
        // Exceptions use "any" match by default - exclude if ANY exception matches
        // So we negate: NOT (any exception matches) = NOT anyof(...)
        if (count($sieveConditions) === 1) {
            return "not " . $sieveConditions[0];
        }
        
        return "not anyof (" . implode(",\n             ", $sieveConditions) . ")";
    }
    
    /**
     * Convert a rule condition to Sieve syntax
     */
    private function ruleToSieveCondition(array $rule): ?string
    {
        $field = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? 'contains';
        $value = $this->escapeSieveString($rule['value'] ?? '');
        
        if (empty($value) && !in_array($operator, ['is_empty', 'is_not_empty'])) {
            return null;
        }
        
        // Map field to Sieve header
        $headerMap = [
            'from' => 'From',
            'to' => 'To',
            'subject' => 'Subject',
            'body' => ':text',
        ];
        
        $header = $headerMap[$field] ?? null;
        if (!$header && $field !== 'has_attachment') {
            return null;
        }
        
        // Map operator to Sieve match type
        switch ($operator) {
            case 'contains':
                if ($field === 'body') {
                    return "body :text :contains \"$value\"";
                }
                return "header :contains \"$header\" \"$value\"";
                
            case 'not_contains':
                if ($field === 'body') {
                    return "not body :text :contains \"$value\"";
                }
                return "not header :contains \"$header\" \"$value\"";
                
            case 'equals':
                return "header :is \"$header\" \"$value\"";
                
            case 'not_equals':
                return "not header :is \"$header\" \"$value\"";
                
            case 'starts_with':
                return "header :matches \"$header\" \"$value*\"";
                
            case 'ends_with':
                return "header :matches \"$header\" \"*$value\"";
                
            case 'matches_regex':
                return "header :regex \"$header\" \"$value\"";
                
            case 'is_empty':
                return "not exists \"$header\"";
                
            case 'is_not_empty':
                return "exists \"$header\"";
                
            default:
                return null;
        }
    }
    
    /**
     * Convert an action to Sieve syntax
     */
    private function actionToSieve(array $action): ?string
    {
        $type = $action['action'] ?? '';
        $value = $action['value'] ?? '';
        
        switch ($type) {
            case 'move':
                if (!$value) return null;
                // Ensure folder has INBOX. prefix for Dovecot if not already
                // Dovecot uses INBOX. namespace for all folders
                $folder = $value;
                if (stripos($folder, 'INBOX.') !== 0 && strtoupper($folder) !== 'INBOX') {
                    $folder = 'INBOX.' . $folder;
                }
                $folder = $this->escapeSieveString($folder);
                return "fileinto \"$folder\"";
                
            case 'delete':
                return 'discard';
                
            case 'mark_read':
                return 'addflag "\\\\Seen"';
                
            case 'mark_unread':
                return 'removeflag "\\\\Seen"';
                
            case 'star':
                return 'addflag "\\\\Flagged"';
                
            case 'unstar':
                return 'removeflag "\\\\Flagged"';
                
            case 'label':
                // Labels are webmail-specific, not supported in standard Sieve
                // We could use custom Dovecot extensions but skip for now
                return null;
                
            default:
                return null;
        }
    }
    
    /**
     * Escape a string for Sieve
     */
    private function escapeSieveString(string $str): string
    {
        // Remove newlines and carriage returns - they break Sieve syntax
        $str = str_replace(["\r\n", "\r", "\n"], ' ', $str);
        // Trim whitespace
        $str = trim($str);
        // Escape backslashes and quotes
        return addcslashes($str, '"\\');
    }
    
    /**
     * @deprecated Use SieveSyncService::sync() instead.
     */
    public function syncFilters(string $email, string $password, array $filters, ?array $vacation = null): array
    {
        $syncService = new SieveSyncService($this->getConfig());
        return $syncService->sync($email, $password);
    }

    private function getConfig(): array
    {
        return ['imap' => ['host' => $this->host, 'sieve_host' => $this->host, 'sieve_port' => $this->port, 'sieve_tls' => $this->useTls]];
    }
    
    /**
     * Get the last error message
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
    
    /**
     * Send a command to the server
     */
    private function sendCommand(string $command): void
    {
        fwrite($this->socket, $command . "\r\n");
    }
    
    /**
     * Read response from server
     */
    private function readResponse(): string
    {
        $response = '';
        $maxLines = 100; // Prevent infinite loops
        $lineCount = 0;
        
        while (!feof($this->socket) && $lineCount < $maxLines) {
            $line = fgets($this->socket, 8192);
            if ($line === false) break;
            $response .= $line;
            $lineCount++;
            
            // Check for response terminator (OK or NO at start of line)
            // NO responses may include {size} literal with error details
            if (preg_match('/^(OK|BYE)\s/m', $line)) {
                break;
            }
            
            // For NO responses, read the full error message
            if (preg_match('/^NO\s*\{(\d+)\}/m', $line, $matches)) {
                // Read the literal content
                $literalSize = (int)$matches[1];
                $literalContent = '';
                while (strlen($literalContent) < $literalSize && !feof($this->socket)) {
                    $chunk = fread($this->socket, $literalSize - strlen($literalContent));
                    if ($chunk === false) break;
                    $literalContent .= $chunk;
                }
                $response .= $literalContent;
                // Read the closing line
                $closingLine = fgets($this->socket, 4096);
                if ($closingLine) $response .= $closingLine;
                break;
            }
            
            // Simple NO without literal
            if (preg_match('/^NO\s/m', $line) && !preg_match('/\{(\d+)\}/', $line)) {
                break;
            }
        }
        return $response;
    }
    
    /**
     * Check if response is OK
     */
    private function isOk(string $response): bool
    {
        return (bool)preg_match('/^OK\s/m', $response);
    }
}

