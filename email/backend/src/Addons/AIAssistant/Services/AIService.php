<?php

namespace Webmail\Addons\AIAssistant\Services;

/**
 * AIService - Handles OpenAI API integration for email AI features
 */
class AIService
{
    private string $apiKey;
    private string $model;
    private array $config;
    
    // Available models
    public const MODELS = [
        'gpt-5-nano' => ['name' => 'GPT-5 Nano', 'description' => 'Cheapest, good for basic tasks'],
        'gpt-5-mini' => ['name' => 'GPT-5 Mini', 'description' => 'Balanced price/performance'],
        'gpt-4.1-nano' => ['name' => 'GPT-4.1 Nano', 'description' => 'Budget option'],
        'gpt-4.1-mini' => ['name' => 'GPT-4.1 Mini', 'description' => 'Reliable and capable'],
    ];
    
    // Writing styles
    public const WRITING_STYLES = [
        'friendly' => 'Friendly and warm',
        'professional' => 'Professional and polished',
        'corporate' => 'Corporate and formal',
        'casual' => 'Casual and relaxed',
        'concise' => 'Brief and to the point',
    ];
    
    // Default prompts
    public const DEFAULT_PROMPTS = [
        'summarize' => 'Analyze the following email conversation and provide a structured summary.

CRITICAL CONTEXT - WHO IS THE READER:
The person reading this summary is: {{user_email}}
This is the email OWNER - the person who will see this summary and act on it.

When you see "To: {{user_email}}" or "Cc: {{user_email}}" - that message was sent TO the reader.
When you see "From: {{user_email}}" - the reader SENT that message.

ACTION ITEMS MUST BE:
- Tasks that the READER ({{user_email}}) needs to do
- NOT tasks for other people mentioned in the thread
- Written from first-person perspective ("Forward the license key to X", NOT "Ask Robert to forward the license key")
- If someone asks the reader to do something, THAT is an action item
- If the reader asks someone else to do something, that is NOT an action item for the reader

IMPORTANT: Your response MUST be in the SAME LANGUAGE as the email content. If the email is in Hungarian, respond in Hungarian. If in German, respond in German. Match the email language exactly.

Format your response as JSON with the following structure:
{
    "topic": "Brief topic description (max 10 words)",
    "main_points": ["Point 1", "Point 2", "Point 3"],
    "context": "Brief context about what this conversation is about",
    "action_items": ["Action item FOR THE READER to do", "Another task FOR THE READER"],
    "suggested_actions": [
        {"label": "Reply agreeing", "type": "reply", "prompt": "Draft a reply agreeing with the proposal"},
        {"label": "Request more info", "type": "reply", "prompt": "Draft a reply asking for more details"}
    ]
}

Email content:
{{email_content}}',

        'rewrite' => 'Rewrite the following text in a {{style}} tone. Keep the same meaning but adjust the style and word choice accordingly. Only return the rewritten text, no explanations.

IMPORTANT: Your response MUST be in the SAME LANGUAGE as the original text. Do not translate - preserve the original language.

Original text:
{{text}}',

        'draft_reply' => 'Write a SHORT, CONCISE {{style}} reply to the following email.

STRICT RULES:
1. ONLY respond to what was asked or discussed in the email - do NOT add extra topics, suggestions, or information
2. Keep it brief - 2-4 sentences maximum unless more detail is specifically needed
3. Do NOT include greetings like "Dear" or closings like "Best regards" - just the body text
4. Do NOT add your own ideas, offers, or suggestions that were not requested
5. Match the tone and formality of the original email
6. Your reply MUST be in the SAME LANGUAGE as the original email

Original email to reply to:
{{email_content}}

{{additional_instructions}}

Reply (body text only, no greeting, no signature, no subject):',
    ];
    
    public function __construct(string $apiKey, string $model = 'gpt-5-nano', array $config = [])
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->config = $config;
    }
    
    /**
     * Check if the service is configured (has API key)
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
    
    /**
     * Summarize an email or conversation
     * @param string $emailContent The email content to summarize
     * @param string $userEmail The email address of the user reading this (to understand who "I" am)
     * @param string|null $customPrompt Optional custom prompt template
     */
    public function summarize(string $emailContent, string $userEmail, ?string $customPrompt = null): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'AI service not configured. Please add your API key in Settings.'];
        }
        
        error_log("AIService::summarize - emailContent length: " . strlen($emailContent));
        error_log("AIService::summarize - userEmail: " . $userEmail);
        
        // Safety net: truncate if somehow content exceeds limit (controller should catch this first)
        $maxContentLength = 40000;
        if (strlen($emailContent) > $maxContentLength) {
            error_log("AIService::summarize - Truncating content from " . strlen($emailContent) . " to $maxContentLength");
            $emailContent = substr($emailContent, 0, $maxContentLength) . "\n\n[Content truncated due to length...]";
        }
        
        $promptTemplate = $customPrompt ?? ($this->config['prompts']['summarize'] ?? null);
        
        // If no custom prompt or it's empty, use default
        if (empty($promptTemplate)) {
            error_log("AIService::summarize - Using DEFAULT prompt (custom was empty)");
            $promptTemplate = self::DEFAULT_PROMPTS['summarize'];
        } else {
            error_log("AIService::summarize - Using custom prompt, length: " . strlen($promptTemplate));
        }
        
        // Make sure the prompt has the placeholder
        if (strpos($promptTemplate, '{{email_content}}') === false) {
            error_log("AIService::summarize - WARNING: Prompt missing {{email_content}} placeholder, appending email");
            $promptTemplate .= "\n\nEmail content:\n{{email_content}}";
        }
        
        $prompt = $this->substituteVariables($promptTemplate, [
            'email_content' => $emailContent,
            'user_email' => $userEmail,
        ]);
        
        error_log("AIService::summarize - Final prompt length: " . strlen($prompt));
        
        $response = $this->callOpenAI($prompt, [
            'response_format' => ['type' => 'json_object'],
        ]);
        
        if (!$response['success']) {
            $response['_debug'] = [
                'input_length' => strlen($emailContent),
                'prompt_length' => strlen($prompt),
                'prompt_preview' => substr($prompt, 0, 300),
            ];
            return $response;
        }
        
        // Parse JSON response
        $content = $response['content'];
        error_log("AIService::summarize - Raw content: " . substr($content, 0, 500));
        
        $parsed = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("AIService::summarize - JSON decode error: " . json_last_error_msg());
            // Try to extract JSON from the response
            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $parsed = json_decode($matches[0], true);
            }
        }
        
        if (!is_array($parsed)) {
            error_log("AIService::summarize - Failed to parse, not an array. Raw: " . substr($content, 0, 500));
            // If AI returned an error message, try to use it as is
            if (strpos($content, 'error') !== false || strpos($content, 'Error') !== false) {
                return [
                    'success' => false,
                    'error' => 'AI returned: ' . substr($content, 0, 200),
                    '_debug' => [
                        'input_length' => strlen($emailContent),
                        'prompt_length' => strlen($prompt),
                        'raw_response' => $content,
                    ],
                ];
            }
            return [
                'success' => false,
                'error' => 'Failed to parse AI response: ' . substr($content, 0, 100),
                '_debug' => [
                    'input_length' => strlen($emailContent),
                    'prompt_length' => strlen($prompt),
                    'raw_response' => $content,
                    'raw_response_length' => strlen($content),
                ],
            ];
        }
        
        error_log("AIService::summarize - Parsed keys: " . implode(', ', array_keys($parsed)));
        
        // Check if AI returned an error object instead of summary
        if (isset($parsed['error'])) {
            error_log("AIService::summarize - AI returned error: " . $parsed['error']);
            return [
                'success' => false,
                'error' => 'AI error: ' . $parsed['error'],
                '_debug' => [
                    'input_length' => strlen($emailContent),
                    'prompt_length' => strlen($prompt ?? ''),
                    'raw_response' => substr($content, 0, 300),
                ],
            ];
        }
        
        return [
            'success' => true,
            'summary' => $parsed,
            'usage' => $response['usage'] ?? null,
        ];
    }
    
    /**
     * Rewrite text with a specific style
     */
    public function rewrite(string $text, string $style = 'professional', ?string $customPrompt = null): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'AI service not configured. Please add your API key in Settings.'];
        }
        
        error_log("AIService::rewrite - text length: " . strlen($text));
        error_log("AIService::rewrite - text preview: " . substr($text, 0, 200));
        
        $styleName = self::WRITING_STYLES[$style] ?? $style;
        
        $promptTemplate = $customPrompt ?? ($this->config['prompts']['rewrite'] ?? null);
        
        // If no custom prompt or it's empty, use default
        if (empty($promptTemplate)) {
            error_log("AIService::rewrite - Using DEFAULT prompt (custom was empty)");
            $promptTemplate = self::DEFAULT_PROMPTS['rewrite'];
        } else {
            error_log("AIService::rewrite - Using custom prompt, length: " . strlen($promptTemplate));
        }
        
        // Make sure the prompt has the placeholder
        if (strpos($promptTemplate, '{{text}}') === false) {
            error_log("AIService::rewrite - WARNING: Prompt missing {{text}} placeholder, appending text");
            $promptTemplate .= "\n\nOriginal text:\n{{text}}";
        }
        
        $prompt = $this->substituteVariables($promptTemplate, [
            'text' => $text,
            'style' => $styleName,
        ]);
        
        error_log("AIService::rewrite - Final prompt length: " . strlen($prompt));
        
        // Dynamic token sizing based on input
        // Rough estimate: 4 chars = 1 token, give 3x space for response + overhead
        $inputTokens = (int)ceil(strlen($text) / 4);
        $maxTokens = max(1000, min(8000, $inputTokens * 4 + 500)); // Min 1000, max 8000
        error_log("AIService::rewrite - Input chars: " . strlen($text) . ", estimated tokens: $inputTokens, max_completion_tokens: $maxTokens");
        
        $response = $this->callOpenAI($prompt, ['max_completion_tokens' => $maxTokens]);
        
        // If gpt-5-nano fails with length issue, retry with a simpler prompt
        if (!$response['success'] && str_contains($this->model, 'gpt-5') && str_contains($response['error'] ?? '', 'tokens')) {
            error_log("AIService::rewrite - Retrying with simpler prompt");
            $simplePrompt = "Rewrite this text in a {$styleName} style. Same language, same meaning:\n\n{$text}";
            $response = $this->callOpenAI($simplePrompt, ['max_completion_tokens' => $maxTokens]);
        }
        
        if (!$response['success']) {
            return $response;
        }
        
        return [
            'success' => true,
            'rewritten' => trim($response['content']),
            'usage' => $response['usage'] ?? null,
        ];
    }
    
    /**
     * Generate a draft reply
     */
    public function draftReply(string $emailContent, string $style = 'professional', string $additionalInstructions = '', ?string $customPrompt = null): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'AI service not configured. Please add your API key in Settings.'];
        }
        
        error_log("AIService::draftReply - emailContent length: " . strlen($emailContent));
        
        $styleName = self::WRITING_STYLES[$style] ?? $style;
        
        $promptTemplate = $customPrompt ?? ($this->config['prompts']['draft_reply'] ?? null);
        
        // If no custom prompt or it's empty, use default
        if (empty($promptTemplate)) {
            error_log("AIService::draftReply - Using DEFAULT prompt (custom was empty)");
            $promptTemplate = self::DEFAULT_PROMPTS['draft_reply'];
        } else {
            error_log("AIService::draftReply - Using custom prompt, length: " . strlen($promptTemplate));
        }
        
        // Make sure the prompt has the placeholder
        if (strpos($promptTemplate, '{{email_content}}') === false) {
            error_log("AIService::draftReply - WARNING: Prompt missing {{email_content}} placeholder, appending email");
            $promptTemplate .= "\n\nEmail to reply to:\n{{email_content}}";
        }
        
        $prompt = $this->substituteVariables($promptTemplate, [
            'email_content' => $emailContent,
            'style' => $styleName,
            'additional_instructions' => $additionalInstructions ? "Additional instructions: $additionalInstructions" : '',
        ]);
        
        error_log("AIService::draftReply - Final prompt length: " . strlen($prompt));
        
        $response = $this->callOpenAI($prompt);
        
        if (!$response['success']) {
            return $response;
        }
        
        return [
            'success' => true,
            'draft' => trim($response['content']),
            'usage' => $response['usage'] ?? null,
        ];
    }
    
    /**
     * Generic chat completion with custom system prompt.
     * Used by Automation Hub AI nodes.
     *
     * @param string $systemPrompt  System-level instruction
     * @param string $userPrompt    User text
     * @param array  $options       max_completion_tokens, temperature, timeout, images (base64 strings)
     */
    public function chat(string $systemPrompt, string $userPrompt, array $options = []): array
    {
        $url = 'https://api.openai.com/v1/chat/completions';

        $images = $options['images'] ?? [];
        unset($options['images']);

        if (!empty($images)) {
            $contentParts = [['type' => 'text', 'text' => $userPrompt]];
            foreach ($images as $img) {
                $mime = $this->detectBase64Mime($img);
                $contentParts[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$mime};base64,{$img}",
                        'detail' => $options['image_detail'] ?? 'low',
                    ],
                ];
            }
            unset($options['image_detail']);
            $userContent = $contentParts;
        } else {
            $userContent = $userPrompt;
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'max_completion_tokens' => $options['max_completion_tokens'] ?? 8000,
        ];

        if (!str_starts_with($this->model, 'gpt-5')) {
            $payload['temperature'] = $options['temperature'] ?? ($this->config['temperature'] ?? 1);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $options['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => "cURL error: $error"];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => $data['error']['message'] ?? "HTTP $httpCode"];
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        return [
            'success' => true,
            'content' => trim($content),
            'model' => $data['model'] ?? $this->model,
            'usage' => $data['usage'] ?? [],
        ];
    }

    private function detectBase64Mime(string $base64): string
    {
        $header = base64_decode(substr($base64, 0, 16));
        if ($header === false) return 'image/png';

        if (str_starts_with($header, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($header, "\x89PNG")) return 'image/png';
        if (str_starts_with($header, "GIF8")) return 'image/gif';
        if (str_starts_with($header, "RIFF") && str_contains($header, 'WEBP')) return 'image/webp';

        return 'image/png';
    }

    /**
     * Sanitize string to valid UTF-8 (strip invalid byte sequences)
     */
    private function sanitizeUtf8(string $text): string
    {
        $cleaned = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned);
        return $cleaned;
    }
    
    /**
     * Make a call to the OpenAI API
     */
    private function callOpenAI(string $prompt, array $options = []): array
    {
        $url = 'https://api.openai.com/v1/chat/completions';
        
        $prompt = $this->sanitizeUtf8($prompt);
        
        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful AI assistant for email management. You help users summarize emails, rewrite text, and draft replies. When asked to summarize, respond in JSON format. CRITICAL: Always respond in the SAME LANGUAGE as the input content. If the email or text is in Hungarian, respond in Hungarian. If in Spanish, respond in Spanish. Never translate - match the original language.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_completion_tokens' => $options['max_completion_tokens'] ?? 8000,
        ];
        
        // Only add temperature if not using GPT-5 models (they only support default=1)
        $temperature = $options['temperature'] ?? ($this->config['temperature'] ?? 1);
        if (!str_starts_with($this->model, 'gpt-5')) {
            $payload['temperature'] = $temperature;
        }
        
        // Add response format if specified (for JSON responses)
        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }
        
        error_log("AIService::callOpenAI - Prompt length: " . strlen($prompt));
        error_log("AIService::callOpenAI - Prompt preview: " . substr($prompt, 0, 300));
        error_log("AIService::callOpenAI - Model: " . $this->model);
        
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonPayload === false) {
            error_log("AIService::callOpenAI - json_encode FAILED: " . json_last_error_msg());
            return ['success' => false, 'error' => 'Failed to encode request payload: ' . json_last_error_msg()];
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("AIService::callOpenAI - HTTP Code: $httpCode");
        error_log("AIService::callOpenAI - Response length: " . strlen($response));
        
        if ($error) {
            error_log("AIService cURL error: $error");
            return ['success' => false, 'error' => 'Failed to connect to AI service'];
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMessage = $data['error']['message'] ?? 'Unknown error from AI service';
            error_log("AIService API error ($httpCode): $errorMessage");
            return ['success' => false, 'error' => $errorMessage];
        }
        
        // Log finish_reason for debugging
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'unknown';
        error_log("AIService::callOpenAI - Finish reason: $finishReason");
        
        if (!isset($data['choices'][0]['message']['content'])) {
            error_log("AIService::callOpenAI - No content in response. Full response: " . substr($response, 0, 1000));
            return [
                'success' => false, 
                'error' => 'Invalid response from AI service (finish_reason: ' . $finishReason . ')',
                '_debug' => [
                    'finish_reason' => $finishReason,
                    'response_preview' => substr($response, 0, 500),
                ]
            ];
        }
        
        $content = $data['choices'][0]['message']['content'];
        
        // Check if content is empty
        if (empty(trim($content))) {
            error_log("AIService::callOpenAI - Content is empty. Finish reason: $finishReason. Full response: " . $response);
            
            // If finish_reason is length but content is empty, the model failed
            if ($finishReason === 'length') {
                return [
                    'success' => false,
                    'error' => 'AI model ran out of tokens. Please try with shorter text or switch to gpt-4.1-mini in Settings.',
                    '_debug' => [
                        'finish_reason' => $finishReason,
                        'model' => $this->model,
                    ]
                ];
            }
            
            return [
                'success' => false,
                'error' => 'AI returned empty response',
                '_debug' => [
                    'finish_reason' => $finishReason,
                    'refusal' => $data['choices'][0]['message']['refusal'] ?? null,
                ]
            ];
        }
        
        // If response was truncated but we have content, still return it with a warning
        if ($finishReason === 'length') {
            error_log("AIService::callOpenAI - Response truncated but has content, returning partial response");
        }
        
        return [
            'success' => true,
            'content' => $content,
            'usage' => $data['usage'] ?? null,
            'finish_reason' => $finishReason,
            'truncated' => $finishReason === 'length',
        ];
    }
    
    /**
     * Substitute variables in a prompt template
     */
    private function substituteVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        return $template;
    }
    
    /**
     * Get available models
     */
    public static function getModels(): array
    {
        return self::MODELS;
    }
    
    /**
     * Get available writing styles
     */
    public static function getWritingStyles(): array
    {
        return self::WRITING_STYLES;
    }
    
    /**
     * Get default prompts
     */
    public static function getDefaultPrompts(): array
    {
        return self::DEFAULT_PROMPTS;
    }
    
    /**
     * Encrypt API key for storage
     */
    public static function encryptApiKey(string $apiKey, string $secret): string
    {
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($apiKey, 'AES-256-CBC', $secret, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt API key from storage
     */
    public static function decryptApiKey(string $encryptedKey, string $secret): ?string
    {
        $data = base64_decode($encryptedKey);
        if ($data === false || strlen($data) < 17) {
            return null;
        }
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $secret, 0, $iv);
        return $decrypted !== false ? $decrypted : null;
    }
}


