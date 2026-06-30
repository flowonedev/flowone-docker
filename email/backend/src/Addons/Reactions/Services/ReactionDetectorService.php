<?php

namespace Webmail\Addons\Reactions\Services;

/**
 * ReactionDetectorService - Detects incoming reaction emails from Gmail/Outlook
 * 
 * Uses language-agnostic structural patterns to identify reaction emails:
 * - Subject starts with emoji
 * - Short body content (excluding quoted original)
 * - Contains quoted/referenced original message
 * - Scoring system for confidence
 */
class ReactionDetectorService
{
    // Minimum score to consider email as reaction
    private const THRESHOLD_DEFINITE = 75;
    private const THRESHOLD_PROBABLE = 50;
    
    // Notification sender domains that should never be treated as reaction emails
    private const EXCLUDED_SENDER_DOMAINS = [
        'github.com',
        'gitlab.com',
        'bitbucket.org',
        'atlassian.com',
        'jira.com',
        'slack.com',
        'trello.com',
        'notion.so',
        'linear.app',
        'figma.com',
        'vercel.com',
        'netlify.com',
        'stripe.com',
        'paypal.com',
        'amazon.com',
        'apple.com',
        'facebook.com',
        'facebookmail.com',
        'twitter.com',
        'x.com',
        'linkedin.com',
        'instagram.com',
        'discord.com',
        'discordapp.com',
        'youtube.com',
        'reddit.com',
        'stackoverflow.com',
        'medium.com',
        'wordpress.com',
        'shopify.com',
        'sentry.io',
        'datadog.com',
        'pagerduty.com',
        'circleci.com',
        'travis-ci.com',
    ];
    
    // Common reaction emojis (extended set that Gmail/Outlook support)
    private const REACTION_EMOJIS = [
        '👍', '👎', '❤️', '💖', '💕', '😍', '🥰', '😘',
        '🎉', '🎊', '🥳', '✨', '🙌', '👏',
        '😂', '🤣', '😆', '😄', '😊', '🙂',
        '😲', '😮', '😯', '😳', '🤯',
        '😢', '😭', '😟', '😔', '😞',
        '😡', '😠', '🤬', '😈', '👿',
        '🤔', '🧐', '🤨',
        '👀', '💯', '🔥', '💪', '🙏',
        '✅', '❌', '⭐', '💡', '📌',
        '💙', '💚', '💛', '🧡', '💜', '🖤', '🤍', '💝',
    ];
    
    /**
     * Detect if an email is a reaction email
     * 
     * @param string $subject Email subject
     * @param string $bodyHtml HTML body content
     * @param string $bodyText Plain text body content
     * @param string|null $inReplyTo In-Reply-To header value
     * @param string|null $fromEmail Sender email address for exclusion filtering
     * @return array Detection result with is_reaction, confidence, emoji, etc.
     */
    public function detect(
        string $subject,
        string $bodyHtml,
        string $bodyText,
        ?string $inReplyTo = null,
        ?string $fromEmail = null
    ): array {
        $score = 0;
        $detectedEmoji = null;
        $reasons = [];
        
        // Verbose per-message scoring telemetry. Off by default; flip
        // FLOWONE_REACTION_DEBUG=1 in the environment to re-enable when
        // tuning the heuristic. Previously these lines fired on every
        // checked email and dominated php_errors.log without ever
        // surfacing a real issue.
        $debug = (bool)($_ENV['FLOWONE_REACTION_DEBUG'] ?? getenv('FLOWONE_REACTION_DEBUG') ?: false);

        if ($debug) {
            error_log("ReactionDetector: Checking subject='$subject' from='$fromEmail'");
        }

        // 0a. Bail out early for known notification senders - they are never reactions
        if ($fromEmail && $this->isExcludedSender($fromEmail)) {
            if ($debug) {
                error_log("ReactionDetector: Score=0, Emoji=none, Reasons=excluded_sender ($fromEmail)");
            }
            return [
                'is_reaction' => false,
                'confidence' => 'none',
                'score' => 0,
                'emoji' => null,
                'reasons' => ['excluded_sender'],
            ];
        }
        
        // 0b. Bail out early for forwarded emails - they are never reactions
        if ($this->isForwardedEmail($subject, $bodyHtml, $bodyText)) {
            if ($debug) {
                error_log("ReactionDetector: Score=0, Emoji=none, Reasons=forwarded_email_skipped");
            }
            return [
                'is_reaction' => false,
                'confidence' => 'none',
                'score' => 0,
                'emoji' => null,
                'reasons' => ['forwarded_email_skipped'],
            ];
        }
        
        // 1. Check if subject starts with emoji (+35 points)
        $subjectEmoji = $this->extractLeadingEmoji($subject);
        if ($subjectEmoji) {
            $score += 35;
            $detectedEmoji = $subjectEmoji;
            $reasons[] = 'subject_starts_with_emoji';
        }
        
        // 2. Check for "reacted" keyword in body (+40 points) - Gmail/Outlook pattern
        // Gmail: "Name reacted via Gmail"
        // Outlook: "Name reacted to your message:"
        $reactedPattern = $this->detectReactedPattern($bodyHtml, $bodyText);
        if ($reactedPattern['found']) {
            $score += 40;
            $reasons[] = 'reacted_keyword_in_body';
            // Extract emoji from body if not found in subject
            if (!$detectedEmoji && $reactedPattern['emoji']) {
                $detectedEmoji = $reactedPattern['emoji'];
                $reasons[] = 'emoji_extracted_from_body';
            }
        }
        
        // 3. Check body length - reaction emails are short (+20 points)
        $cleanBodyLength = $this->getCleanBodyLength($bodyHtml, $bodyText);
        if ($cleanBodyLength < 200) {
            $score += 20;
            $reasons[] = 'very_short_body';
        } elseif ($cleanBodyLength < 500) {
            $score += 10;
            $reasons[] = 'short_body';
        }
        
        // 4. Check if body contains quoted original message (+10 points)
        $hasQuote = $this->hasQuotedContent($bodyHtml, $bodyText);
        if ($hasQuote) {
            $score += 10;
            $reasons[] = 'has_quoted_content';
        }
        
        // 5. Check if emoji appears prominently in body (+15 points)
        $bodyEmoji = $this->extractProminentBodyEmoji($bodyHtml, $bodyText);
        if ($bodyEmoji) {
            $score += 15;
            $reasons[] = 'prominent_emoji_in_body';
            if (!$detectedEmoji) {
                $detectedEmoji = $bodyEmoji;
            }
        }
        
        // 6. Has In-Reply-To header (it's a reply) (+10 points)
        if ($inReplyTo) {
            $score += 10;
            $reasons[] = 'is_reply';
        }
        
        // 7. Check for Gmail/Outlook reaction HTML patterns (+15 points)
        $hasReactionPattern = $this->hasReactionHtmlPattern($bodyHtml);
        if ($hasReactionPattern) {
            $score += 15;
            $reasons[] = 'reaction_html_pattern';
        }
        
        // Log score
        if ($debug) {
            error_log("ReactionDetector: Score=$score, Emoji=" . ($detectedEmoji ?? 'none') . ", Reasons=" . implode(',', $reasons));
        }
        
        // Determine confidence level
        $isReaction = false;
        $confidence = 'none';
        
        if ($score >= self::THRESHOLD_DEFINITE) {
            $isReaction = true;
            $confidence = 'high';
        } elseif ($score >= self::THRESHOLD_PROBABLE) {
            $isReaction = true;
            $confidence = 'medium';
        }
        
        return [
            'is_reaction' => $isReaction,
            'confidence' => $confidence,
            'score' => $score,
            'emoji' => $detectedEmoji,
            'reasons' => $reasons,
        ];
    }
    
    /**
     * Detect "reacted" pattern in body (Gmail/Outlook format)
     */
    private function detectReactedPattern(string $bodyHtml, string $bodyText): array
    {
        $body = strip_tags($bodyHtml ?: $bodyText);
        $result = ['found' => false, 'emoji' => null];
        
        // Pattern: "reacted via Gmail", "reacted to your message", etc.
        // This works because Gmail/Outlook always use "reacted" in their notifications
        if (preg_match('/reacted\s+(via|to)/i', $body)) {
            $result['found'] = true;
            
            // Try to extract emoji from the body
            // Look for emoji near the start of the content
            $emoji = $this->extractProminentBodyEmoji($bodyHtml, $bodyText);
            if ($emoji) {
                $result['emoji'] = $emoji;
            }
        }
        
        return $result;
    }
    
    /**
     * Extract prominent emoji from body (usually displayed large at the top)
     */
    private function extractProminentBodyEmoji(string $bodyHtml, string $bodyText): ?string
    {
        $body = $bodyHtml ?: $bodyText;
        
        // Check for known reaction emojis anywhere in body
        foreach (self::REACTION_EMOJIS as $emoji) {
            if (mb_strpos($body, $emoji) !== false) {
                return $emoji;
            }
        }
        
        // Try to extract any emoji from the beginning of stripped content
        $stripped = trim(strip_tags($bodyHtml ?: $bodyText));
        if (preg_match('/^[\s\n]*([\x{1F300}-\x{1F9FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|\p{So})/u', $stripped, $matches)) {
            return $matches[1];
        }
        
        // Check for emoji in img alt text (Gmail sometimes uses images)
        if (preg_match('/<img[^>]*alt=["\']([^"\']*[\x{1F300}-\x{1F9FF}][^"\']*)["\'][^>]*>/u', $bodyHtml, $matches)) {
            if (preg_match('/([\x{1F300}-\x{1F9FF}])/u', $matches[1], $emojiMatch)) {
                return $emojiMatch[1];
            }
        }
        
        return null;
    }
    
    /**
     * Extract leading emoji from text
     */
    private function extractLeadingEmoji(string $text): ?string
    {
        $text = trim($text);
        
        // Remove common prefixes like "Re:", "Fwd:", etc.
        $text = preg_replace('/^(Re:|Fwd?:|Fw:)\s*/i', '', $text) ?? $text;
        $text = trim($text);
        
        if (empty($text)) {
            return null;
        }
        
        // Check for known reaction emojis first
        foreach (self::REACTION_EMOJIS as $emoji) {
            if (mb_strpos($text, $emoji) === 0) {
                return $emoji;
            }
        }
        
        // Generic emoji detection using Unicode ranges
        // Emoji ranges: \x{1F300}-\x{1F9FF} (Misc Symbols, Emoticons, etc.)
        // Also: \x{2600}-\x{26FF} (Misc symbols), \x{2700}-\x{27BF} (Dingbats)
        if (preg_match('/^(\p{So}|\p{Cs}|[\x{1F300}-\x{1F9FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}])/u', $text, $matches)) {
            return $matches[0];
        }
        
        // Check for emoji sequences (emoji + variation selector + ZWJ sequences)
        if (preg_match('/^([\x{1F1E0}-\x{1F1FF}]{2}|[\x{1F300}-\x{1F9FF}][\x{FE00}-\x{FE0F}]?[\x{200D}]?[\x{1F300}-\x{1F9FF}]?)/u', $text, $matches)) {
            return $matches[0];
        }
        
        return null;
    }
    
    /**
     * Get clean body length (excluding quotes and excessive whitespace)
     */
    private function getCleanBodyLength(string $bodyHtml, string $bodyText): int
    {
        // Try HTML first
        if (!empty($bodyHtml)) {
            // Remove blockquotes
            $clean = preg_replace('/<blockquote[^>]*>.*?<\/blockquote>/is', '', $bodyHtml) ?? $bodyHtml;
            // Remove gmail_quote divs
            $clean = preg_replace('/<div[^>]*class="[^"]*gmail_quote[^"]*"[^>]*>.*?<\/div>/is', '', $clean) ?? $clean;
            // Remove outlook quote patterns
            $clean = preg_replace('/<div[^>]*id="[^"]*appendonsend[^"]*"[^>]*>.*$/is', '', $clean) ?? $clean;
            // Remove "On ... wrote:" patterns
            $clean = preg_replace('/<div[^>]*>On\s+.{10,80}\s+wrote:.*$/is', '', $clean) ?? $clean;
            // Strip HTML tags
            $clean = strip_tags($clean ?? '');
            // Normalize whitespace
            $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? $clean;
            
            return mb_strlen($clean ?? '');
        }
        
        // Fallback to plain text
        if (!empty($bodyText)) {
            // Remove quoted lines (starting with >)
            $lines = explode("\n", $bodyText);
            $cleanLines = array_filter($lines, function($line) {
                return !preg_match('/^\s*>/', $line);
            });
            $clean = implode(' ', $cleanLines);
            $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? $clean;
            
            return mb_strlen($clean ?? '');
        }
        
        return 0;
    }
    
    /**
     * Check if body contains quoted content
     */
    private function hasQuotedContent(string $bodyHtml, string $bodyText): bool
    {
        // Check HTML for blockquote or gmail_quote
        if (!empty($bodyHtml)) {
            if (preg_match('/<blockquote/i', $bodyHtml)) {
                return true;
            }
            if (preg_match('/class="[^"]*gmail_quote/i', $bodyHtml)) {
                return true;
            }
            if (preg_match('/wrote:/i', $bodyHtml)) {
                return true;
            }
            // Outlook pattern - border-left style often indicates quote
            if (preg_match('/border-left:\s*[^;]*solid/i', $bodyHtml)) {
                return true;
            }
        }
        
        // Check plain text for quote markers
        if (!empty($bodyText)) {
            // Lines starting with >
            if (preg_match('/^>/m', $bodyText)) {
                return true;
            }
            // "On ... wrote:" pattern
            if (preg_match('/On\s+.{10,80}\s+wrote:/i', $bodyText)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check for Gmail/Outlook specific reaction HTML patterns
     */
    private function hasReactionHtmlPattern(string $bodyHtml): bool
    {
        if (empty($bodyHtml)) {
            return false;
        }
        
        // Gmail reaction pattern: Large emoji image or text at start
        // Gmail often uses a table with the emoji prominently displayed
        
        // Pattern 1: Very large font-size for emoji (Gmail style)
        if (preg_match('/font-size:\s*(2[4-9]|[3-9]\d|1\d\d)px/i', $bodyHtml)) {
            return true;
        }
        
        // Pattern 2: Gmail's specific reaction structure - emoji in a styled div at top
        if (preg_match('/<div[^>]*style="[^"]*font-size:\s*\d+px[^"]*"[^>]*>\s*[\x{1F300}-\x{1F9FF}]/iu', $bodyHtml)) {
            return true;
        }
        
        // Pattern 3: Outlook reaction - typically includes "reacted" in hidden/visible text
        // This catches various language patterns like "reacted", "reagiert", "réagi", etc.
        // But we're avoiding this for language independence
        
        // Pattern 4: Image of emoji (Gmail sometimes uses images)
        if (preg_match('/<img[^>]*emoji[^>]*>/i', $bodyHtml)) {
            return true;
        }
        
        // Pattern 5: Very short HTML with just styling wrapper and emoji
        $strippedLength = mb_strlen(strip_tags($bodyHtml));
        if ($strippedLength < 100) {
            // Check if what remains is mostly emoji
            $textContent = trim(strip_tags($bodyHtml));
            if (preg_match('/^[\p{So}\p{Cs}\s\x{1F300}-\x{1F9FF}\x{2600}-\x{27BF}]+$/u', $textContent)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect if this is a forwarded email (not a reaction)
     */
    private function isForwardedEmail(string $subject, string $bodyHtml, string $bodyText): bool
    {
        // Subject-based detection (multilingual forward prefixes)
        $forwardPrefixes = '/^(Fwd?:|Fw:|Továbbított:|Weitergeleitet:|Tr:|Doorgestuurd:|Inoltro:|Enc:|Reenv:)\s/i';
        if (preg_match($forwardPrefixes, trim($subject))) {
            return true;
        }
        
        // Body-based detection: forwarded message markers
        $body = $bodyHtml ?: $bodyText;
        if (!empty($body)) {
            // Gmail/generic: "---------- Forwarded message ----------"
            if (preg_match('/-{3,}\s*(Forwarded message|Továbbított üzenet|Weitergeleitet|Original Message|Eredeti üzenet)\s*-{3,}/i', $body)) {
                return true;
            }
            // Outlook: "From: ... Sent: ... To: ... Subject:" block pattern
            if (preg_match('/From:\s*.+\s*Sent:\s*.+\s*To:\s*.+\s*Subject:/i', $body)) {
                return true;
            }
            // Hungarian: "Feladó: ... Dátum: ... Címzett: ... Tárgy:"
            if (preg_match('/Feladó:\s*.+/i', $body) && preg_match('/Tárgy:\s*.+/i', $body)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if the sender is from a known notification service (not a real person reacting)
     */
    private function isExcludedSender(string $email): bool
    {
        $email = strtolower(trim($email));
        $domain = substr($email, strrpos($email, '@') + 1);
        
        foreach (self::EXCLUDED_SENDER_DOMAINS as $excluded) {
            if ($domain === $excluded || str_ends_with($domain, '.' . $excluded)) {
                return true;
            }
        }
        
        // Also exclude common noreply patterns from unknown domains
        // Real reaction emails come from actual user accounts via Gmail/Outlook relay
        $localPart = substr($email, 0, strrpos($email, '@'));
        $noreplyPatterns = ['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster'];
        foreach ($noreplyPatterns as $pattern) {
            if ($localPart === $pattern || str_starts_with($localPart, $pattern . '+')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract reactor name from reaction email body
     */
    public function extractReactorName(string $bodyHtml, string $bodyText, string $fromName): string
    {
        // The "from" name is usually the reactor
        if (!empty($fromName)) {
            return $fromName;
        }
        
        // Try to extract from body patterns
        // Gmail format often has: "Name reacted to your message"
        if (preg_match('/([^<>\n]+)\s+(?:reacted|reagiert|reagáltak|réagi)/ui', strip_tags($bodyHtml ?: $bodyText), $matches)) {
            return trim($matches[1]);
        }
        
        return 'Someone';
    }
    
    /**
     * Get list of known reaction emojis
     */
    public static function getKnownEmojis(): array
    {
        return self::REACTION_EMOJIS;
    }
}


