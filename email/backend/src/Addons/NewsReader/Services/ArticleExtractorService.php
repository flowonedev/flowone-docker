<?php

namespace Webmail\Addons\NewsReader\Services;

/**
 * Server-side article extractor.
 *
 * Fetches an article URL and tries to extract the clean reading content
 * (title + body + lead image) the same way Firefox's Reader View, Safari's
 * Reader Mode, or Pocket do. The RSS feed typically only ships 1–2
 * sentences as the summary; this fills in the rest by visiting the
 * publisher's page.
 *
 * Extraction strategy (best evidence wins):
 *   1. JSON-LD `Article` / `NewsArticle` schema with `articleBody`
 *      (most modern publishers expose the full article text here)
 *   2. Open Graph + `<article>` element
 *   3. `[itemprop="articleBody"]` microdata
 *   4. `<main>` element
 *   5. Heuristic: largest paragraph-rich block in the document
 *
 * The extracted HTML is then:
 *   - Stripped of `<script>`, `<style>`, `<iframe>`, `<noscript>`,
 *     comment forms, share widgets, related-article rails, etc.
 *   - Image `src` rewritten from relative → absolute (so they load when
 *     embedded inside our app)
 *   - Sanitized through `HtmlSanitizer` for XSS safety
 *
 * Returns null when nothing usable can be found; never throws on parse
 * errors.
 */
class ArticleExtractorService
{
    private const MAX_FETCH_BYTES = 4 * 1024 * 1024;
    private const FETCH_TIMEOUT_S = 15;

    /**
     * @return array{
     *   title:?string,
     *   content_html:string,
     *   word_count:int,
     *   lead_image_url:?string,
     *   byline:?string,
     *   site_name:?string
     * }|null
     */
    public function extract(string $url): ?array
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $html = $this->fetch($url);
        if ($html === null || $html === '') {
            return null;
        }

        $finalUrl = $this->lastEffectiveUrl ?: $url;

        // Encoding normalization — many sites still serve windows-1252 or iso-8859-x
        $html = $this->normalizeEncoding($html);

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        if (!$loaded) {
            return null;
        }
        $xpath = new \DOMXPath($doc);

        $meta = $this->extractMeta($xpath, $finalUrl);

        $contentHtml = $this->extractFromJsonLd($xpath);
        if ($contentHtml === null) {
            $contentHtml = $this->extractFromArticleTag($xpath);
        }
        if ($contentHtml === null) {
            $contentHtml = $this->extractFromItemProp($xpath);
        }
        if ($contentHtml === null) {
            $contentHtml = $this->extractFromMainTag($xpath);
        }
        if ($contentHtml === null) {
            $contentHtml = $this->extractByHeuristic($xpath);
        }
        if ($contentHtml === null || trim(strip_tags($contentHtml)) === '') {
            return null;
        }

        $contentHtml = $this->cleanContent($contentHtml, $finalUrl);
        $contentHtml = HtmlSanitizer::sanitize($contentHtml) ?? '';
        if ($contentHtml === '') {
            return null;
        }

        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($contentHtml)));
        $wordCount = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain) ?: []);

        // Reject if too short to be a real article (likely a paywall or extraction failure)
        if ($wordCount < 60) {
            return null;
        }

        return [
            'title' => $meta['title'],
            'content_html' => $contentHtml,
            'word_count' => $wordCount,
            'lead_image_url' => $meta['image'],
            'byline' => $meta['author'],
            'site_name' => $meta['site_name'],
        ];
    }

    // ------------------------------------------------------------------
    // Fetch
    // ------------------------------------------------------------------

    private string $lastEffectiveUrl = '';

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.8',
                'Accept-Encoding: gzip, deflate',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $this->lastEffectiveUrl = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: '');
        curl_close($ch);

        if ($body === false || $code >= 400) {
            return null;
        }
        if (stripos($ctype, 'text/html') === false && stripos($ctype, 'application/xhtml') === false) {
            return null;
        }
        if (strlen($body) > self::MAX_FETCH_BYTES) {
            $body = substr($body, 0, self::MAX_FETCH_BYTES);
        }

        return $body;
    }

    private function normalizeEncoding(string $html): string
    {
        $charset = 'UTF-8';
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w\-]+)/i', $html, $m)) {
            $charset = strtoupper($m[1]);
        }
        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $html;
    }

    // ------------------------------------------------------------------
    // Metadata
    // ------------------------------------------------------------------

    /**
     * @return array{title:?string,image:?string,author:?string,site_name:?string}
     */
    private function extractMeta(\DOMXPath $xpath, string $finalUrl): array
    {
        $title = $this->metaContent($xpath, 'og:title')
            ?? $this->metaContent($xpath, 'twitter:title')
            ?? $this->firstNodeText($xpath, '//title');
        $image = $this->metaContent($xpath, 'og:image')
            ?? $this->metaContent($xpath, 'twitter:image');
        if ($image !== null) {
            $image = $this->absoluteUrl($image, $finalUrl);
        }
        $author = $this->metaContent($xpath, 'author')
            ?? $this->metaContent($xpath, 'article:author')
            ?? $this->firstNodeText($xpath, '//*[@rel="author"]');
        $siteName = $this->metaContent($xpath, 'og:site_name');

        return [
            'title' => $title,
            'image' => $image,
            'author' => $author,
            'site_name' => $siteName,
        ];
    }

    private function metaContent(\DOMXPath $xpath, string $name): ?string
    {
        $q = sprintf(
            '//meta[(@property=%1$s or @name=%1$s or @itemprop=%1$s)][@content][1]',
            $this->xpathStr($name)
        );
        $node = $xpath->query($q)->item(0);
        if (!$node instanceof \DOMElement) {
            return null;
        }
        $val = trim($node->getAttribute('content'));

        return $val === '' ? null : $val;
    }

    private function firstNodeText(\DOMXPath $xpath, string $q): ?string
    {
        $node = $xpath->query($q)->item(0);
        if (!$node) {
            return null;
        }
        $val = trim((string) $node->textContent);

        return $val === '' ? null : $val;
    }

    // ------------------------------------------------------------------
    // Extraction strategies
    // ------------------------------------------------------------------

    private function extractFromJsonLd(\DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//script[@type="application/ld+json"]');
        if ($nodes === false) {
            return null;
        }
        foreach ($nodes as $node) {
            $raw = trim((string) $node->textContent);
            if ($raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if ($data === null) {
                // Some publishers wrap JSON-LD in CDATA or have trailing junk
                $clean = (string) preg_replace('/^[^{\[]+|[^}\]]+$/', '', $raw);
                $data = json_decode($clean, true);
            }
            $body = $this->findArticleBody($data);
            if ($body !== null && $body !== '') {
                if ($this->looksLikePlainText($body)) {
                    $body = '<p>' . implode("</p><p>", array_map(
                        static fn($p) => htmlspecialchars(trim($p), ENT_QUOTES),
                        preg_split('/\n{2,}|\r\n{2,}/u', $body) ?: [$body]
                    )) . '</p>';
                }

                return $body;
            }
        }

        return null;
    }

    /**
     * Walk a JSON-LD payload (which may be a graph, an array, or a single
     * object) looking for an Article-like type with `articleBody`.
     */
    private function findArticleBody($data): ?string
    {
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $found = $this->findArticleBody($data['@graph']);
            if ($found !== null) {
                return $found;
            }
        }
        if (array_is_list($data)) {
            foreach ($data as $entry) {
                $found = $this->findArticleBody($entry);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }
        $type = $data['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        $isArticle = false;
        foreach ($types as $t) {
            if (is_string($t) && preg_match('/Article|Posting|BlogPosting|NewsArticle|Report/i', $t)) {
                $isArticle = true;
                break;
            }
        }
        if ($isArticle && isset($data['articleBody']) && is_string($data['articleBody'])) {
            return $data['articleBody'];
        }
        // Recurse into nested objects (some publishers nest the article)
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findArticleBody($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function looksLikePlainText(string $s): bool
    {
        return strpos($s, '<') === false && strpos($s, '>') === false;
    }

    private function extractFromArticleTag(\DOMXPath $xpath): ?string
    {
        // Pick the article tag with the most paragraph text
        return $this->bestNodeHtml($xpath, '//article');
    }

    private function extractFromItemProp(\DOMXPath $xpath): ?string
    {
        return $this->bestNodeHtml($xpath, '//*[@itemprop="articleBody" or @itemprop="text"]');
    }

    private function extractFromMainTag(\DOMXPath $xpath): ?string
    {
        return $this->bestNodeHtml($xpath, '//main');
    }

    private function extractByHeuristic(\DOMXPath $xpath): ?string
    {
        // Score each candidate block by paragraph density and total text length
        $candidates = $xpath->query('//div | //section');
        if ($candidates === false || $candidates->length === 0) {
            return null;
        }
        $bestScore = 0;
        $bestNode = null;
        foreach ($candidates as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $score = $this->scoreNode($xpath, $node);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestNode = $node;
            }
        }
        if ($bestNode === null || $bestScore < 200) {
            return null;
        }

        return $this->innerHtml($bestNode);
    }

    private function bestNodeHtml(\DOMXPath $xpath, string $q): ?string
    {
        $nodes = $xpath->query($q);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $bestScore = 0;
        $bestNode = null;
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $score = $this->scoreNode($xpath, $node);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestNode = $node;
            }
        }
        if ($bestNode === null || $bestScore < 100) {
            return null;
        }

        return $this->innerHtml($bestNode);
    }

    private function scoreNode(\DOMXPath $xpath, \DOMElement $node): int
    {
        $cls = strtolower($node->getAttribute('class') . ' ' . $node->getAttribute('id'));
        // Penalize obviously non-article containers
        if (preg_match('/comment|sidebar|footer|header|nav|share|related|recirc|newsletter|signup|paywall|promo/i', $cls)) {
            return 0;
        }
        $paragraphs = $xpath->query('.//p', $node);
        if ($paragraphs === false || $paragraphs->length === 0) {
            return 0;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        $textLen = mb_strlen($text);
        if ($textLen < 200) {
            return 0;
        }
        $linkText = '';
        $links = $xpath->query('.//a', $node);
        if ($links instanceof \DOMNodeList) {
            foreach ($links as $a) {
                $linkText .= ' ' . $a->textContent;
            }
        }
        $linkLen = mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $linkText)));
        // Skip "list of links" containers
        if ($textLen > 0 && $linkLen / $textLen > 0.5) {
            return 0;
        }

        return $paragraphs->length * 30 + $textLen;
    }

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------

    private function cleanContent(string $html, string $baseUrl): string
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        // Remove unwanted elements outright
        $kill = $xpath->query('//script | //style | //iframe | //noscript | //form | //button | //input | //textarea | //svg | //canvas');
        if ($kill instanceof \DOMNodeList) {
            foreach (iterator_to_array($kill) as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
        // Remove typical chrome by class/id pattern
        $junk = $xpath->query(
            '//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "share") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "social") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "newsletter") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "subscribe") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "related") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "recirc") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "promo") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "paywall") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "advert") '
            . 'or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "comment")]'
        );
        if ($junk instanceof \DOMNodeList) {
            foreach (iterator_to_array($junk) as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
        // Resolve relative img src/srcset against the article URL.
        // Also tag author headshots / byline avatars with a marker
        // class so the frontend renders them small (otherwise they
        // get the same hero-image treatment as a real article image,
        // which looks ridiculous for a portrait headshot).
        $imgs = $xpath->query('//img');
        if ($imgs instanceof \DOMNodeList) {
            foreach ($imgs as $img) {
                if (!$img instanceof \DOMElement) {
                    continue;
                }
                // Some lazy-load patterns put the URL in data-src/data-original
                foreach (['data-src', 'data-original', 'data-lazy-src', 'data-srcset'] as $alt) {
                    if (!$img->getAttribute('src') && $img->getAttribute($alt)) {
                        $img->setAttribute('src', $img->getAttribute($alt));
                    }
                }
                $src = $img->getAttribute('src');
                if ($src !== '') {
                    $img->setAttribute('src', $this->absoluteUrl($src, $baseUrl));
                }
                $img->removeAttribute('srcset'); // sanitizer drops it anyway, no need to keep relative srcset

                if ($this->looksLikeAuthorAvatar($img)) {
                    // Preserve whatever class the upstream had (HTMLPurifier
                    // strips unknown classes anyway, only `news-author-avatar`
                    // survives) and ensure our marker is present.
                    $existing = $img->getAttribute('class');
                    $img->setAttribute(
                        'class',
                        $existing === ''
                            ? 'news-author-avatar'
                            : $existing . ' news-author-avatar'
                    );
                }
            }
        }
        // Resolve relative links
        $as = $xpath->query('//a[@href]');
        if ($as instanceof \DOMNodeList) {
            foreach ($as as $a) {
                if (!$a instanceof \DOMElement) {
                    continue;
                }
                $href = $a->getAttribute('href');
                if ($href !== '' && strpos($href, '#') !== 0) {
                    $a->setAttribute('href', $this->absoluteUrl($href, $baseUrl));
                }
            }
        }
        $root = $doc->getElementById('__root__');

        return $root ? $this->innerHtml($root) : $html;
    }

    private function innerHtml(\DOMElement $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $node->ownerDocument->saveHTML($child);
        }

        return $out;
    }

    private function absoluteUrl(string $href, string $base): string
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) || str_starts_with($href, 'data:') || str_starts_with($href, 'mailto:')) {
            return $href;
        }
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return $href;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $path = $b['path'] ?? '/';
        $dir = substr($path, -1) === '/' ? $path : (string) preg_replace('#/[^/]*$#', '/', $path);

        return $origin . $dir . $href;
    }

    /**
     * Heuristic detector for author headshots / byline avatars. Returns
     * true when ANY of the following evidence is strong enough to be
     * pretty sure this isn't a real article image:
     *
     *   1. The `<img>` is wrapped in (or inside) an `<a>` that links
     *      to an obvious author profile path
     *      (`/author/`, `/staff/`, `/people/`, `/profile/`,
     *      `/contributors/`, `/writer/`, `/by/`).
     *   2. The element itself, or any ancestor up to 4 levels up, has a
     *      CSS class containing one of: `author`, `avatar`, `byline`,
     *      `bio`, `headshot`, `profile`, `staff`, `contributor`.
     *   3. The `src` URL contains any of those tokens in its path
     *      (covers `/wp-content/uploads/avatars/...`,
     *      `secure.gravatar.com/avatar/...`, etc.).
     *   4. The `<img>` has explicit `width` or `height` attributes ≤ 200
     *      AND the rendered aspect ratio is roughly square (within 15%
     *      of 1:1) — small + square is the universal signature of a
     *      byline thumbnail.
     *
     * False-positive risk is low: a real article photo almost never
     * lives under `/author/...`, almost never has the word `avatar` in
     * its src, and almost never declares itself ≤ 200px square in the
     * HTML.
     */
    private function looksLikeAuthorAvatar(\DOMElement $img): bool
    {
        $tokens = '/(author|avatar|byline|bio|headshot|profile|staff|contributor|writer|gravatar)/i';

        // 1) Wrapping anchor whose href points at an author page.
        $ancestor = $img->parentNode;
        $depth = 0;
        while ($ancestor instanceof \DOMElement && $depth < 4) {
            if ($ancestor->nodeName === 'a') {
                $href = $ancestor->getAttribute('href');
                if ($href !== ''
                    && preg_match('~/(author|staff|people|profile|contributors?|writers?|by)/[^/?#]+~i', $href)
                ) {
                    return true;
                }
            }
            // 2) Any ancestor (or the img itself) with a tell-tale class.
            $class = $ancestor->getAttribute('class');
            if ($class !== '' && preg_match($tokens, $class)) {
                return true;
            }
            $ancestor = $ancestor->parentNode;
            $depth++;
        }

        // The img element's own class.
        $imgClass = $img->getAttribute('class');
        if ($imgClass !== '' && preg_match($tokens, $imgClass)) {
            return true;
        }

        // 3) Tell-tale token in the src URL path.
        $src = $img->getAttribute('src');
        if ($src !== '') {
            // Match only against the path portion so a publisher domain
            // that happens to contain one of these words doesn't trip
            // false positives.
            $path = parse_url($src, PHP_URL_PATH) ?: $src;
            if (preg_match($tokens, $path)) {
                return true;
            }
            // Gravatar is hosted on a known domain — flag it regardless
            // of path tokens.
            $host = parse_url($src, PHP_URL_HOST) ?: '';
            if ($host !== '' && stripos($host, 'gravatar.com') !== false) {
                return true;
            }
        }

        // 4) Small + roughly square.
        $w = (int) $img->getAttribute('width');
        $h = (int) $img->getAttribute('height');
        if ($w > 0 && $h > 0 && $w <= 200 && $h <= 200) {
            $ratio = $w / max(1, $h);
            if ($ratio >= 0.85 && $ratio <= 1.15) {
                return true;
            }
        }

        return false;
    }

    private function xpathStr(string $value): string
    {
        if (strpos($value, "'") === false) {
            return "'" . $value . "'";
        }
        if (strpos($value, '"') === false) {
            return '"' . $value . '"';
        }
        // Mixed quotes — fall back to concat()
        $parts = explode("'", $value);
        $concat = "concat(";
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $concat .= ", \"'\", ";
            }
            $concat .= "'" . $p . "'";
        }

        return $concat . ')';
    }
}
