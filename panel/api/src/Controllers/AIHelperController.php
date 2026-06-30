<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;
use VpsAdmin\Api\Services\AIHelperService;

class AIHelperController extends BaseController
{
    private AIHelperService $aiHelper;

    public function __construct($container)
    {
        parent::__construct($container);
        $this->aiHelper = $container->get(AIHelperService::class);
    }

    /**
     * List user's conversations
     */
    public function listConversations(Request $request): Response
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                return Response::error('Unauthorized', 401);
            }

            $conversations = $this->aiHelper->getUserConversations($user->sub);

            return Response::success(['conversations' => $conversations]);
        } catch (\PDOException $e) {
            debug_log('AI Helper listConversations PDO error: ' . $e->getMessage());
            debug_log('SQL State: ' . $e->getCode());
            return Response::success(['conversations' => []]); // Return empty array on database error
        } catch (\Exception $e) {
            debug_log('AI Helper listConversations error: ' . $e->getMessage());
            debug_log('Stack trace: ' . $e->getTraceAsString());
            return Response::success(['conversations' => []]); // Return empty array on error
        }
    }

    /**
     * Create new conversation
     */
    public function createConversation(Request $request): Response
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                return Response::error('Unauthorized', 401);
            }

            $title = $request->input('title');

            $conversation = $this->aiHelper->createConversation($user->sub, $title);

            return Response::success(['conversation' => $conversation]);
        } catch (\PDOException $e) {
            debug_log('AI Helper createConversation PDO error: ' . $e->getMessage());
            debug_log('SQL State: ' . $e->getCode());
            return Response::error('Database error. Please ensure AI Helper tables are created. Run migration if needed.');
        } catch (\Exception $e) {
            debug_log('AI Helper createConversation error: ' . $e->getMessage());
            debug_log('Stack trace: ' . $e->getTraceAsString());
            return Response::error('Failed to create conversation: ' . $e->getMessage());
        }
    }

    /**
     * Get conversation with messages
     */
    public function getConversation(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $id = (int)$request->getParam('id');
        $conversation = $this->aiHelper->getConversation($id, $user->sub);

        if (!$conversation) {
            return Response::notFound('Conversation not found');
        }

        return Response::success(['conversation' => $conversation]);
    }

    /**
     * Delete a conversation
     */
    public function deleteConversation(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $id = (int)$request->getParam('id');
        $success = $this->aiHelper->deleteConversation($id, $user->sub);

        if (!$success) {
            return Response::notFound('Conversation not found or access denied');
        }

        return Response::success(['message' => 'Conversation deleted successfully']);
    }

    /**
     * Send message to AI
     */
    public function sendMessage(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }

        $validation = $this->validateRequired($request, ['message']);
        if ($validation) {
            return $validation;
        }

        $conversationId = (int)$request->getParam('id');
        $message = $request->input('message');
        $model = $request->input('model'); // Optional model override
        $context = $request->input('context', []);

        // Verify conversation belongs to user
        $conversation = $this->aiHelper->getConversation($conversationId, $user->sub);
        if (!$conversation) {
            return Response::notFound('Conversation not found');
        }

        // Add user message
        $this->aiHelper->addMessage($conversationId, 'user', $message, [
            'model' => $model,
            'context' => $context,
        ]);

        // Prepare context for AI
        $aiContext = array_merge($context, [
            'conversation_id' => $conversationId,
            'user_id' => $user->sub,
            'username' => $user->username,
        ]);

        // Get AI response
        $result = $this->aiHelper->analyzeIssue($message, $aiContext, $model);

        if (!$result['success']) {
            $this->aiHelper->addMessage($conversationId, 'assistant', 'Error: ' . $result['error'], [
                'error' => true,
            ]);

            return Response::error($result['error']);
        }

        // Add AI response
        $this->aiHelper->addMessage($conversationId, 'assistant', $result['content'], [
            'model' => $result['model'],
            'usage' => $result['usage'],
        ]);

        return Response::success([
            'message' => $result['content'],
            'model' => $result['model'],
            'usage' => $result['usage'],
        ]);
    }

    /**
     * Execute dry-run command
     */
    public function dryRunCommand(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['command']);
        if ($validation) {
            return $validation;
        }

        $command = $request->input('command');
        $cwd = $request->input('cwd');

        $result = $this->agent->execute('aihelper.dryRunCommand', [
            'command' => $command,
            'cwd' => $cwd,
        ], $this->getActor());

        if ($result['success']) {
            return Response::success($result['data']);
        }

        return Response::error($result['error'] ?? 'Command execution failed');
    }

    /**
     * Get cached issues
     */
    public function getCachedIssues(Request $request): Response
    {
        try {
            $service = $request->getQuery('service');
            $resolved = $request->getQuery('resolved') === 'true';

            $issues = $this->aiHelper->getCachedIssues($service, $resolved);

            return Response::success(['issues' => $issues]);
        } catch (\Exception $e) {
            debug_log('AI Helper getCachedIssues error: ' . $e->getMessage());
            debug_log('Stack trace: ' . $e->getTraceAsString());
            return Response::success(['issues' => []]); // Return empty array on error
        }
    }

    /**
     * Mark issue as resolved
     */
    public function resolveIssue(Request $request): Response
    {
        $id = (int)$request->getParam('id');

        $success = $this->aiHelper->markIssueResolved($id);

        if ($success) {
            return Response::success(['message' => 'Issue marked as resolved']);
        }

        return Response::error('Issue not found');
    }

    /**
     * Get available config files by service
     */
    public function getConfigFiles(Request $request): Response
    {
        try {
            $service = $request->getQuery('service');

            $configFiles = [
                'postfix' => [
                    ['path' => '/etc/postfix/main.cf', 'label' => 'Main Configuration'],
                    ['path' => '/etc/postfix/master.cf', 'label' => 'Master Configuration'],
                ],
                'dovecot' => [
                    ['path' => '/etc/dovecot/dovecot.conf', 'label' => 'Main Configuration'],
                    ['path' => '/etc/dovecot/conf.d/10-mail.conf', 'label' => 'Mail Configuration'],
                    ['path' => '/etc/dovecot/conf.d/10-ssl.conf', 'label' => 'SSL Configuration'],
                    ['path' => '/etc/dovecot/conf.d/10-auth.conf', 'label' => 'Auth Configuration'],
                ],
                'openlitespeed' => [
                    ['path' => '/usr/local/lsws/conf/httpd_config.conf', 'label' => 'Main Configuration'],
                    ['path' => '/usr/local/lsws/conf/vhosts', 'label' => 'Virtual Hosts Directory'],
                ],
                'email-ssl' => [
                    ['path' => '/etc/dovecot/conf.d/10-ssl.conf', 'label' => 'Dovecot SSL Configuration'],
                    ['path' => '/etc/postfix/main.cf', 'label' => 'Postfix TLS Settings'],
                ],
                'web-ssl' => [
                    ['path' => '/usr/local/lsws/conf/httpd_config.conf', 'label' => 'OpenLiteSpeed SSL Settings'],
                    ['path' => '/etc/letsencrypt', 'label' => 'Let\'s Encrypt Directory'],
                ],
                'ssh' => [
                    ['path' => '/etc/ssh/sshd_config', 'label' => 'SSH Configuration'],
                ],
                'fail2ban' => [
                    ['path' => '/etc/fail2ban/jail.local', 'label' => 'Jail Configuration'],
                    ['path' => '/etc/fail2ban/jail.conf', 'label' => 'Default Configuration'],
                ],
                'modsec' => [
                    ['path' => '/usr/local/lsws/conf/modsec.conf', 'label' => 'ModSecurity Configuration'],
                    ['path' => '/usr/local/lsws/conf/modsec/rules', 'label' => 'Rules Directory'],
                ],
                'firewall' => [
                    ['path' => '/etc/iptables/rules.v4', 'label' => 'IPv4 Rules'],
                    ['path' => '/etc/iptables/rules.v6', 'label' => 'IPv6 Rules'],
                    ['path' => '/etc/ufw/user.rules', 'label' => 'UFW User Rules'],
                ],
            ];

            if ($service && isset($configFiles[$service])) {
                return Response::success(['files' => $configFiles[$service]]);
            }

            return Response::success(['files' => $configFiles]);
        } catch (\Exception $e) {
            debug_log('AI Helper getConfigFiles error: ' . $e->getMessage());
            debug_log('Stack trace: ' . $e->getTraceAsString());
            return Response::error('Failed to get config files: ' . $e->getMessage());
        }
    }

    /**
     * Analyze config file for issues
     */
    public function analyzeConfig(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['service', 'path']);
        if ($validation) {
            return $validation;
        }

        $service = $request->input('service');
        $path = $request->input('path');
        $model = $request->input('model'); // Optional model override

        // Read config file
        $readResult = $this->agent->execute('aihelper.readConfigFile', [
            'path' => $path,
        ], $this->getActor());

        if (!$readResult['success']) {
            return Response::error($readResult['error'] ?? 'Failed to read config file');
        }

        $content = $readResult['data']['content'];

        // Check syntax
        $syntaxResult = $this->agent->execute('system.syntaxCheck', [
            'service' => $service,
            'content' => $content,
        ], $this->getActor());

        // Build prompt for AI
        $prompt = "Analyze this {$service} configuration file for issues:\n\n";
        $prompt .= "File: {$path}\n";
        $prompt .= "Permissions: {$readResult['data']['permissions']}\n";
        $prompt .= "Owner: {$readResult['data']['owner']}\n";
        $prompt .= "Group: {$readResult['data']['group']}\n\n";
        
        if (isset($syntaxResult['data'])) {
            $prompt .= "Syntax Check:\n";
            $prompt .= "Valid: " . ($syntaxResult['data']['valid'] ? 'Yes' : 'No') . "\n";
            if (!empty($syntaxResult['data']['errors'])) {
                $prompt .= "Errors: " . implode("\n", $syntaxResult['data']['errors']) . "\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "Configuration:\n```\n{$content}\n```\n\n";
        $prompt .= "Please identify any issues, misconfigurations, or security concerns.";

        $context = [
            'service' => $service,
            'path' => $path,
            'syntax_valid' => $syntaxResult['data']['valid'] ?? false,
            'syntax_errors' => $syntaxResult['data']['errors'] ?? [],
        ];

        $result = $this->aiHelper->analyzeIssue($prompt, $context, $model);

        if (!$result['success']) {
            return Response::error($result['error']);
        }

        return Response::success([
            'analysis' => $result['content'],
            'syntax' => $syntaxResult['data'] ?? null,
            'file_info' => $readResult['data'],
            'model' => $result['model'],
        ]);
    }

    /**
     * Analyze logs for issues
     */
    public function analyzeLogs(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['service']);
        if ($validation) {
            return $validation;
        }

        $service = $request->input('service');
        $logType = $request->input('type', 'journalctl');
        $lines = (int)$request->input('lines', 100);
        $model = $request->input('model'); // Optional model override

        // Read logs
        $logResult = $this->agent->execute('logs.read', [
            'service' => $service,
            'type' => $logType,
            'lines' => $lines,
        ], $this->getActor());

        if (!$logResult['success']) {
            return Response::error($logResult['error'] ?? 'Failed to read logs');
        }

        $logLines = $logResult['data']['lines'] ?? [];
        $logContent = implode("\n", $logLines);

        // Build prompt for AI
        $prompt = "Analyze these {$service} logs for issues, errors, or anomalies:\n\n";
        $prompt .= "Service: {$service}\n";
        $prompt .= "Log Type: {$logType}\n";
        $prompt .= "Lines: " . count($logLines) . "\n\n";
        $prompt .= "Logs:\n```\n{$logContent}\n```\n\n";
        $prompt .= "Please identify any errors, warnings, patterns, or issues that need attention.";

        $context = [
            'service' => $service,
            'log_type' => $logType,
            'line_count' => count($logLines),
        ];

        $result = $this->aiHelper->analyzeIssue($prompt, $context, $model);

        if (!$result['success']) {
            return Response::error($result['error']);
        }

        return Response::success([
            'analysis' => $result['content'],
            'logs' => $logLines,
            'log_type' => $logType,
            'model' => $result['model'],
        ]);
    }

    /**
     * Get AI Helper settings
     */
    public function getSettings(Request $request): Response
    {
        try {
            $roleCheck = $this->requireSuperAdmin();
            if ($roleCheck) return $roleCheck;

            $settings = $this->aiHelper->getSettings();
            return Response::success(['settings' => $settings]);
        } catch (\PDOException $e) {
            debug_log('AI Helper getSettings PDO error: ' . $e->getMessage());
            debug_log('SQL State: ' . $e->getCode());
            return Response::error('Database error. Please ensure AI Helper tables are created. Run migration if needed.');
        } catch (\Exception $e) {
            debug_log('AI Helper getSettings error: ' . $e->getMessage());
            debug_log('Stack trace: ' . $e->getTraceAsString());
            return Response::error('Failed to load settings: ' . $e->getMessage());
        }
    }

    /**
     * Update AI Helper settings
     */
    public function updateSettings(Request $request): Response
    {
        try {
            $roleCheck = $this->requireSuperAdmin();
            if ($roleCheck) return $roleCheck;

            $settings = $request->input('settings', []);
            
            if (empty($settings)) {
                return Response::error('No settings provided');
            }

            $success = $this->aiHelper->updateSettings($settings);

            if ($success) {
                $this->logAction('ai_helper.settings.update', 'ai_helper', 'success', $settings);
                return Response::success(['message' => 'Settings updated successfully']);
            }

            return Response::error('Failed to update settings');
        } catch (\Exception $e) {
            debug_log('AI Helper updateSettings error: ' . $e->getMessage());
            return Response::error('Failed to update settings: ' . $e->getMessage());
        }
    }
}

