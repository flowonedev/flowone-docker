<?php

namespace Webmail\Addons\NewsReader\Services;

/**
 * Sanitize HTML for stored summaries and full content using HTMLPurifier.
 */
class HtmlSanitizer
{
    private static ?\HTMLPurifier $purifier = null;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            // `class` is allowed on `<img>` only so ArticleExtractorService
            // can tag author headshots / byline thumbnails — the frontend
            // uses that class to render them as small circular avatars
            // instead of letting them fill the column at hero size.
            $config->set('HTML.Allowed', implode(',', [
                'p', 'br', 'a[href|title]', 'strong', 'em', 'b', 'i', 'u',
                'blockquote', 'ul', 'ol', 'li',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'img[src|alt|title|class|width|height]', 'figure', 'figcaption', 'pre', 'code', 'hr',
            ]));
            // Restrict the class allowlist to just our marker so an
            // upstream HTML that happens to set arbitrary CSS classes
            // can't leak into our DOM.
            $config->set('Attr.AllowedClasses', ['news-author-avatar']);
            $config->set('URI.AllowedSchemes', ['https' => true, 'http' => true, 'mailto' => true]);
            $config->set('AutoFormat.AutoParagraph', false);
            $config->set('Cache.SerializerPath', sys_get_temp_dir());
            self::$purifier = new \HTMLPurifier($config);
        }

        return self::$purifier->purify($html);
    }

    public static function toPlainText(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        $t = strip_tags($html);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t);

        return trim($t ?? '');
    }
}
