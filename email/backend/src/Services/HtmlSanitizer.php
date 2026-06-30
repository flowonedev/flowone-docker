<?php

namespace Webmail\Services;

class HtmlSanitizer
{
    private ?\HTMLPurifier $purifier = null;

    public function __construct()
    {
        if (class_exists('HTMLPurifier')) {
            $config = \HTMLPurifier_Config::createDefault();
            
            // Allow safe HTML elements with style attributes for rich email rendering
            $config->set('HTML.Allowed', 
                'p[style],br,b,i,u,strong,em,s,strike,' .
                'a[href|title|target|style],' .
                'ul[style],ol[style],li[style],' .
                'blockquote[style],pre[style],code[style],' .
                'h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],' .
                'table[style|width|cellpadding|cellspacing|border|bgcolor],' .
                'thead,tbody,tfoot,tr[style|bgcolor],th[style|width|bgcolor|colspan|rowspan],td[style|width|bgcolor|colspan|rowspan|valign|align],' .
                'img[src|alt|width|height|style],' .
                'span[style],div[style],' .
                'hr[style],sub,sup,' .
                'center,font[color|size|face]'
            );
            
            // Allow comprehensive inline styles for proper email rendering
            $config->set('CSS.AllowedProperties', 
                'color,background,background-color,background-image,' .
                'font,font-size,font-family,font-weight,font-style,' .
                'text-decoration,text-align,text-transform,line-height,letter-spacing,' .
                'margin,margin-top,margin-right,margin-bottom,margin-left,' .
                'padding,padding-top,padding-right,padding-bottom,padding-left,' .
                'border,border-top,border-right,border-bottom,border-left,' .
                'border-color,border-width,border-style,border-collapse,' .
                'width,height,max-width,max-height,min-width,min-height,' .
                'vertical-align,float,clear,' .
                'list-style,list-style-type'
            );
            
            // URL handling
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'cid' => true, 'data' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            
            // Security
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.Nofollow', true);
            
            // Allow data URIs for embedded images
            $config->set('URI.SafeIframeRegexp', '%^(https?:)?//(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%');
            
            // HTMLPurifier ships no validator for `overflow`, `display` or
            // `border-radius`, so they are dropped even if listed in
            // CSS.AllowedProperties (which also emits a noisy "not supported"
            // warning). Register them directly on the CSS definition instead.
            // Senders hide preheader/preview blocks with `overflow:hidden` + zero
            // dimensions or `display:none`; preserving these keeps those blocks
            // collapsed/hidden (e.g. the Apple "developer agreement" email, which
            // otherwise leaked a stray preheader line and a one-letter-per-line
            // strip). border-radius is purely cosmetic but stripping it loses
            // rounded buttons/cards in marketing email. getCSSDefinition()
            // finalizes the config, so this must run after every $config->set()
            // above and right before instantiation.
            $cssDefinition = $config->getCSSDefinition();
            $overflowEnum = new \HTMLPurifier_AttrDef_Enum(['visible', 'hidden', 'scroll', 'auto', 'clip', 'inherit']);
            $cssDefinition->info['overflow'] = $overflowEnum;
            $cssDefinition->info['overflow-x'] = $overflowEnum;
            $cssDefinition->info['overflow-y'] = $overflowEnum;
            $cssDefinition->info['display'] = new \HTMLPurifier_AttrDef_Enum([
                'none', 'block', 'inline', 'inline-block', 'list-item',
                'table', 'table-row', 'table-cell', 'table-column',
                'table-row-group', 'table-header-group', 'table-footer-group',
                'table-column-group', 'table-caption',
                'flex', 'inline-flex', 'grid', 'inline-grid', 'inherit',
            ]);
            // border-radius accepts 1-4 non-negative lengths/percentages (the
            // elliptical `/` syntax is uncommon in email and intentionally omitted).
            $borderRadiusValue = new \HTMLPurifier_AttrDef_CSS_Composite([
                new \HTMLPurifier_AttrDef_CSS_Length('0'),
                new \HTMLPurifier_AttrDef_CSS_Percentage(),
            ]);
            $cssDefinition->info['border-radius'] = new \HTMLPurifier_AttrDef_CSS_Multiple($borderRadiusValue, 4);
            
            $this->purifier = new \HTMLPurifier($config);
        }
    }

    /**
     * Sanitize HTML content for safe display
     */
    public function sanitize(?string $html): string
    {
        // Handle null/empty input
        if ($html === null || $html === '') {
            return '';
        }
        
        if ($this->purifier) {
            return $this->purifier->purify($html);
        }
        
        // Fallback if HTMLPurifier is not available
        return $this->basicSanitize($html);
    }

    /**
     * Basic sanitization fallback - keeps more content for proper rendering
     */
    private function basicSanitize(?string $html): string
    {
        // Handle null/empty input
        if ($html === null || $html === '') {
            return '';
        }
        
        // Remove script tags and their content (including noscript)
        // Use ?? $html to preserve value if preg_replace fails (returns null on error)
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', $html) ?? $html;
        
        // Remove only external style tags but keep inline styles on elements
        // Extract body content from full HTML documents
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $html = $matches[1];
        }
        
        // Remove style tags from body (they should be in head anyway)
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html) ?? $html;
        
        // Remove link tags (external stylesheets can contain expressions)
        $html = preg_replace('/<link\b[^>]*\/?>/is', '', $html) ?? $html;
        
        // Remove event handlers (onclick, onerror, etc.)
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/', '', $html) ?? $html;
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/', '', $html) ?? $html;
        
        // Remove javascript: and vbscript: URLs
        $html = preg_replace('/href\s*=\s*["\']?\s*(javascript|vbscript):[^"\'>\s]*/i', 'href="#"', $html) ?? $html;
        $html = preg_replace('/src\s*=\s*["\']?\s*(javascript|vbscript):[^"\'>\s]*/i', 'src=""', $html) ?? $html;
        
        // Remove data: URLs in src attributes (can contain scripts)
        $html = preg_replace('/src\s*=\s*["\']?\s*data:text\/html[^"\'>\s]*/i', 'src=""', $html) ?? $html;
        
        // Remove dangerous tags but preserve content structure
        $html = preg_replace('/<(iframe|object|embed|form|input|button|select|textarea|applet)\b[^>]*>(.*?)<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(iframe|object|embed|form|input|button|select|textarea|applet)\b[^>]*\/?>/is', '', $html) ?? $html;
        
        // Remove SVG tags (can contain scripts)
        $html = preg_replace('/<svg\b[^>]*>(.*?)<\/svg>/is', '', $html) ?? $html;
        
        // Remove base tags that could redirect resources
        $html = preg_replace('/<base\b[^>]*\/?>/is', '', $html) ?? $html;
        
        // Remove meta refresh tags
        $html = preg_replace('/<meta\b[^>]*http-equiv\s*=\s*["\']?refresh[^>]*>/is', '', $html) ?? $html;
        
        // Remove XML processing instructions and CDATA (can be used to bypass filters)
        $html = preg_replace('/<\?xml[^>]*\?>/i', '', $html) ?? $html;
        $html = preg_replace('/<!\[CDATA\[.*?\]\]>/is', '', $html) ?? $html;
        
        return trim($html ?? '');
    }

    /**
     * Convert plain text to HTML
     */
    public function textToHtml(?string $text): string
    {
        // Handle null/empty input
        if ($text === null || $text === '') {
            return '';
        }
        
        // Check if this "plain text" is actually HTML that wasn't properly identified
        if ($this->looksLikeHtml($text)) {
            // It's actually HTML, sanitize and return it
            return $this->sanitize($text);
        }
        
        // Escape HTML entities
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        
        // Convert URLs to links
        $html = preg_replace(
            '/\b(https?:\/\/[^\s<]+)/i',
            '<a href="$1" target="_blank" rel="nofollow">$1</a>',
            $html
        ) ?? $html;
        
        // Convert email addresses to mailto links
        $html = preg_replace(
            '/\b([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})\b/i',
            '<a href="mailto:$1">$1</a>',
            $html
        ) ?? $html;
        
        // Convert newlines to <br>
        $html = nl2br($html);
        
        return $html;
    }
    
    /**
     * Check if text looks like HTML content
     */
    private function looksLikeHtml(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }
        
        // Check for common HTML indicators
        $htmlPatterns = [
            '/<html[^>]*>/i',
            '/<body[^>]*>/i',
            '/<div[^>]*>/i',
            '/<table[^>]*>/i',
            '/<p[^>]*>/i',
            '/<!DOCTYPE/i',
        ];
        
        foreach ($htmlPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        // Check if it has multiple HTML tags
        $tagCount = preg_match_all('/<[a-z][a-z0-9]*[^>]*>/i', $text);
        return $tagCount >= 3;
    }

    /**
     * Extract plain text from HTML
     */
    public function htmlToText(?string $html): string
    {
        // Handle null/empty input
        if ($html === null || $html === '') {
            return '';
        }
        
        // Remove style and script content
        // Use ?? to preserve value if preg_replace fails (returns null on error)
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html) ?? $html;
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text) ?? $text;
        
        // Convert block elements to newlines
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        
        // Remove remaining tags
        $text = strip_tags($text ?? '');
        
        // Decode entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        
        return trim($text ?? '');
    }
}

