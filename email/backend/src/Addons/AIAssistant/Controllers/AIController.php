<?php

namespace Webmail\Addons\AIAssistant\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\AIAssistant\Services\AIService;

/**
 * AIController - Handle AI-powered email features
 */
class AIController extends BaseController
{
    private string $globalSettingsDir = '/var/www/vps-email/data/global';
    
    /**
     * Get AI service instance for current user
     */
    private function getAIService(): ?AIService
    {
        $settings = $this->loadGlobalAISettings();
        
        if (empty($settings['ai_api_key_encrypted'])) {
            error_log("AIController::getAIService - No API key configured");
            return null;
        }
        
        $secret = $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
        $apiKey = AIService::decryptApiKey($settings['ai_api_key_encrypted'], $secret);
        
        if (!$apiKey) {
            error_log("AIController::getAIService - Failed to decrypt API key");
            return null;
        }
        
        return new AIService(
            $apiKey,
            $settings['ai_model'] ?? 'gpt-5-nano',
            [
                'temperature' => $settings['ai_temperature'] ?? 1.0,
                'prompts' => [
                    'summarize' => $settings['ai_prompt_summarize'] ?? null,
                    'rewrite' => $settings['ai_prompt_rewrite'] ?? null,
                    'draft_reply' => $settings['ai_prompt_draft_reply'] ?? null,
                ],
            ]
        );
    }
    
    /**
     * Load global AI settings (shared across all accounts)
     */
    private function loadGlobalAISettings(): array
    {
        // Use PRIMARY user email for global settings
        $hash = md5(strtolower($this->userEmail));
        $file = $this->globalSettingsDir . '/ai_' . $hash . '.json';
        
        error_log("AIController::loadGlobalAISettings - Path: $file");
        
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $settings = json_decode($content, true);
            if (is_array($settings)) {
                error_log("AIController::loadGlobalAISettings - Loaded, has API key: " . (!empty($settings['ai_api_key_encrypted']) ? 'yes' : 'no'));
                return $settings;
            }
        }
        
        error_log("AIController::loadGlobalAISettings - File not found or invalid");
        return [];
    }
    
    /**
     * Summarize email(s)
     * POST /ai/summarize
     */
    public function summarize(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $aiService = $this->getAIService();
        if (!$aiService) {
            return Response::error('AI not configured. Please add your OpenAI API key in Settings > AI Assistant.', 400);
        }
        
        $emailContent = $request->input('email_content');
        $userEmail = $request->input('user_email') ?? $this->getActiveEmail();
        
        error_log("AIController::summarize - Received email_content, length: " . strlen($emailContent ?? ''));
        error_log("AIController::summarize - User email (reader): " . $userEmail);
        
        if (empty($emailContent)) {
            error_log("AIController::summarize - email_content is EMPTY!");
            return Response::error('Email content is required', 400);
        }
        
        error_log("AIController::summarize - Content preview: " . substr($emailContent, 0, 300));
        
        $maxLength = 40000;
        if (strlen($emailContent) > $maxLength) {
            $avgEmailSize = 1500;
            $approxEmails = (int)floor($maxLength / $avgEmailSize);
            return Response::json([
                'success' => false,
                'message' => "This conversation is too lengthy for AI summarization. The maximum allowed length is {$maxLength} characters (roughly the last {$approxEmails} emails). Please try summarizing a shorter thread.",
                'data' => [
                    'too_long' => true,
                    'max_length' => $maxLength,
                    'current_length' => strlen($emailContent),
                    'approx_email_limit' => $approxEmails,
                ],
            ], 400);
        }
        
        $result = $aiService->summarize($emailContent, $userEmail);
        
        if (!$result['success']) {
            // Include debug info in error response
            return Response::json([
                'success' => false,
                'message' => $result['error'],
                'data' => [
                    '_debug' => array_merge(
                        $result['_debug'] ?? [],
                        ['content_received_length' => strlen($emailContent)]
                    ),
                ],
            ], 500);
        }
        
        return Response::success([
            'summary' => $result['summary'],
            '_debug' => [
                'content_received_length' => strlen($emailContent),
                'content_preview' => substr($emailContent, 0, 200),
            ],
            'usage' => $result['usage'],
        ]);
    }
    
    /**
     * Rewrite text
     * POST /ai/rewrite
     */
    public function rewrite(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $aiService = $this->getAIService();
        if (!$aiService) {
            return Response::error('AI not configured. Please add your OpenAI API key in Settings > AI Assistant.', 400);
        }
        
        $text = $request->input('text');
        error_log("AIController::rewrite - Received text, length: " . strlen($text ?? ''));
        
        if (empty($text)) {
            error_log("AIController::rewrite - text is EMPTY!");
            return Response::error('Text is required', 400);
        }
        
        error_log("AIController::rewrite - Text preview: " . substr($text, 0, 200));
        
        $style = $request->input('style', 'professional');
        
        // Validate style
        $validStyles = array_keys(AIService::getWritingStyles());
        if (!in_array($style, $validStyles)) {
            $style = 'professional';
        }
        
        // Limit text length
        $maxLength = 10000;
        if (strlen($text) > $maxLength) {
            return Response::error('Text is too long. Maximum ' . $maxLength . ' characters.', 400);
        }
        
        $result = $aiService->rewrite($text, $style);
        
        if (!$result['success']) {
            return Response::error($result['error'], 500);
        }
        
        return Response::success([
            'rewritten' => $result['rewritten'],
            'usage' => $result['usage'],
        ]);
    }
    
    /**
     * Generate draft reply
     * POST /ai/draft-reply
     */
    public function draftReply(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $aiService = $this->getAIService();
        if (!$aiService) {
            return Response::error('AI not configured. Please add your OpenAI API key in Settings > AI Assistant.', 400);
        }
        
        $emailContent = $request->input('email_content');
        if (empty($emailContent)) {
            return Response::error('Email content is required', 400);
        }
        
        $style = $request->input('style', 'professional');
        $instructions = $request->input('instructions', '');
        
        // Validate style
        $validStyles = array_keys(AIService::getWritingStyles());
        if (!in_array($style, $validStyles)) {
            $style = 'professional';
        }
        
        // Limit content length
        $maxLength = 20000;
        if (strlen($emailContent) > $maxLength) {
            $emailContent = substr($emailContent, 0, $maxLength) . "\n\n[Content truncated...]";
        }
        
        $result = $aiService->draftReply($emailContent, $style, $instructions);
        
        if (!$result['success']) {
            return Response::error($result['error'], 500);
        }
        
        return Response::success([
            'draft' => $result['draft'],
            'usage' => $result['usage'],
        ]);
    }
    
    /**
     * Get AI configuration status and options
     * GET /ai/config
     */
    public function getConfig(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $settings = $this->loadGlobalAISettings();
        $isConfigured = !empty($settings['ai_api_key_encrypted']);
        
        return Response::success([
            'configured' => $isConfigured,
            'model' => $settings['ai_model'] ?? 'gpt-5-nano',
            'style' => $settings['ai_writing_style'] ?? 'professional',
            'models' => AIService::getModels(),
            'styles' => AIService::getWritingStyles(),
            'default_prompts' => AIService::getDefaultPrompts(),
        ]);
    }
}


