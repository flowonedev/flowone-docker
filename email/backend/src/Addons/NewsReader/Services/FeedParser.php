<?php

namespace Webmail\Addons\NewsReader\Services;

use SimpleXMLElement;

/**
 * Parse RSS 2.0 and Atom 1.0 into normalized item rows.
 * TODO: optional og:image fetch from article HTML (deferred).
 */
class FeedParser
{
    /**
     * @return array{type: string, title: ?string, link: ?string, description: ?string, items: list<array>}
     */
    public function parse(string $xml): array
    {
        libxml_use_internal_errors(true);
        $sx = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        if ($sx === false) {
            return ['type' => 'unknown', 'title' => null, 'link' => null, 'description' => null, 'items' => []];
        }

        $root = $sx->getName();
        if ($root === 'rss' || $root === 'Rss') {
            return $this->parseRss($sx);
        }
        if ($root === 'feed') {
            return $this->parseAtom($sx);
        }

        return ['type' => 'unknown', 'title' => null, 'link' => null, 'description' => null, 'items' => []];
    }

    private function parseRss(SimpleXMLElement $rss): array
    {
        $ch = $rss->channel ?? null;
        $title = $ch ? (string) $ch->title : null;
        $link = $ch ? (string) $ch->link : null;
        $desc = $ch ? (string) $ch->description : null;
        $items = [];
        if ($ch && isset($ch->item)) {
            foreach ($ch->item as $it) {
                $items[] = $this->normalizeRssItem($it);
            }
        }

        return ['type' => 'rss', 'title' => $title ?: null, 'link' => $link ?: null, 'description' => $desc ?: null, 'items' => $items];
    }

    private function normalizeRssItem(SimpleXMLElement $it): array
    {
        $namespaces = $it->getNamespaces(true);
        $guid = isset($it->guid) ? trim((string) $it->guid) : '';
        $title = $this->extractTitle($it, $namespaces);
        $link = trim((string) ($it->link ?? ''));
        $pub = $this->parseDate((string) ($it->pubDate ?? ''));
        if ($pub === null && isset($it->children('http://purl.org/dc/elements/1.1/')->date)) {
            $pub = $this->parseDate((string) $it->children('http://purl.org/dc/elements/1.1/')->date);
        }

        $descHtml = (string) ($it->description ?? '');
        $contentHtml = '';
        if (isset($namespaces['content'])) {
            $c = $it->children($namespaces['content']);
            if (isset($c->encoded)) {
                $contentHtml = (string) $c->encoded;
            }
        }
        if ($contentHtml === '' && isset($it->children('http://purl.org/rss/1.0/modules/content/')->encoded)) {
            $contentHtml = (string) $it->children('http://purl.org/rss/1.0/modules/content/')->encoded;
        }

        $summary = $descHtml !== '' ? $descHtml : $this->stripToSummary($contentHtml);
        $author = trim((string) ($it->author ?? ''));
        if ($author === '' && isset($it->children('http://purl.org/dc/elements/1.1/')->creator)) {
            $author = trim((string) $it->children('http://purl.org/dc/elements/1.1/')->creator);
        }

        // Last-resort title fallback: derive from the description if the
        // feed publisher shipped an empty <title>. Some podcast feeds and
        // microblog/Mastodon-style RSS exports do this routinely.
        if ($title === '') {
            $title = $this->titleFromHtml($descHtml !== '' ? $descHtml : $contentHtml);
        }

        $image = $this->extractImageRss($it, $contentHtml !== '' ? $contentHtml : $descHtml);

        return [
            'guid' => $guid !== '' ? $guid : null,
            'title' => $title,
            'link' => $link,
            'summary_html' => $summary,
            'content_html' => $contentHtml !== '' ? $contentHtml : null,
            'published_at' => $pub,
            'author' => $author !== '' ? $author : null,
            'image_url' => $image,
        ];
    }

    private function extractImageRss(SimpleXMLElement $it, string $htmlBlob): ?string
    {
        $namespaces = $it->getNamespaces(true);
        // media:content / media:thumbnail
        $mrss = 'http://search.yahoo.com/mrss/';
        if (isset($namespaces['media'])) {
            $m = $it->children($namespaces['media']);
            foreach ($m->content ?? [] as $mc) {
                $attrs = $mc->attributes();
                $medium = (string) ($attrs['medium'] ?? '');
                $url = (string) ($attrs['url'] ?? '');
                if ($url !== '' && ($medium === 'image' || $medium === '')) {
                    return $url;
                }
            }
            if (isset($m->thumbnail)) {
                $t = $m->thumbnail->attributes();
                $u = (string) ($t['url'] ?? '');
                if ($u !== '') {
                    return $u;
                }
            }
        }
        // enclosure
        if (isset($it->enclosure)) {
            $e = $it->enclosure->attributes();
            $type = (string) ($e['type'] ?? '');
            $u = (string) ($e['url'] ?? '');
            if ($u !== '' && str_starts_with($type, 'image/')) {
                return $u;
            }
        }

        return $this->firstImgFromHtml($htmlBlob);
    }

    private function parseAtom(SimpleXMLElement $feed): array
    {
        $title = (string) ($feed->title ?? '');
        $link = null;
        foreach ($feed->link ?? [] as $l) {
            $rel = (string) ($l->attributes()->rel ?? 'alternate');
            $href = (string) ($l->attributes()->href ?? '');
            if ($href !== '' && ($rel === 'alternate' || $rel === '')) {
                $link = $href;
                break;
            }
        }
        $subtitle = (string) ($feed->subtitle ?? '');
        $items = [];
        foreach ($feed->entry ?? [] as $entry) {
            $items[] = $this->normalizeAtomEntry($entry);
        }

        return ['type' => 'atom', 'title' => $title ?: null, 'link' => $link, 'description' => $subtitle ?: null, 'items' => $items];
    }

    private function normalizeAtomEntry(SimpleXMLElement $e): array
    {
        $id = trim((string) ($e->id ?? ''));
        $title = $this->extractTitle($e, $e->getNamespaces(true));
        $link = '';
        foreach ($e->link ?? [] as $l) {
            $rel = (string) ($l->attributes()->rel ?? 'alternate');
            $href = (string) ($l->attributes()->href ?? '');
            if ($href !== '' && $rel === 'alternate') {
                $link = $href;
                break;
            }
        }
        if ($link === '') {
            foreach ($e->link ?? [] as $l) {
                $href = (string) ($l->attributes()->href ?? '');
                if ($href !== '') {
                    $link = $href;
                    break;
                }
            }
        }

        $published = $this->parseDate((string) ($e->published ?? ''));
        if ($published === null) {
            $published = $this->parseDate((string) ($e->updated ?? ''));
        }

        $contentHtml = '';
        $summaryHtml = '';
        foreach ($e->content ?? [] as $c) {
            $type = (string) ($c->attributes()->type ?? '');
            $body = (string) $c;
            if (str_contains($type, 'html') || $body !== '') {
                $contentHtml = $body;
                break;
            }
        }
        foreach ($e->summary ?? [] as $s) {
            $summaryHtml = (string) $s;
            break;
        }
        if ($summaryHtml === '' && $contentHtml !== '') {
            $summaryHtml = $this->stripToSummary($contentHtml);
        }

        $author = '';
        if (isset($e->author->name)) {
            $author = trim((string) $e->author->name);
        }

        if ($title === '') {
            $title = $this->titleFromHtml($summaryHtml !== '' ? $summaryHtml : $contentHtml);
        }

        $image = $this->extractImageAtom($e, $contentHtml !== '' ? $contentHtml : $summaryHtml);

        // YouTube channel feeds are Atom 1.0 with custom yt: + media: namespaces
        $video = $this->extractYouTubeVideo($e, $link);
        if ($video !== null) {
            // Use the video description as summary when the entry didn't ship one
            if ($summaryHtml === '' && $video['description'] !== '') {
                $summaryHtml = $video['description'];
            }
            if ($image === null && $video['thumbnail'] !== null) {
                $image = $video['thumbnail'];
            }
        }

        return [
            'guid' => $id !== '' ? $id : null,
            'title' => $title,
            'link' => $link,
            'summary_html' => $summaryHtml,
            'content_html' => $contentHtml !== '' ? $contentHtml : null,
            'published_at' => $published,
            'author' => $author !== '' ? $author : null,
            'image_url' => $image,
            'is_video' => $video !== null,
            'video_id' => $video['video_id'] ?? null,
            'video_thumbnail_url' => $video['thumbnail'] ?? null,
        ];
    }

    /**
     * Extract YouTube video metadata from an Atom entry. Returns null if
     * the entry isn't a YouTube video (i.e. no `yt:videoId` and no
     * `youtube.com/watch?v=…` link).
     *
     * @return array{video_id:string, thumbnail:?string, description:string}|null
     */
    private function extractYouTubeVideo(SimpleXMLElement $e, string $link): ?array
    {
        $videoId = null;

        // Preferred: yt:videoId child (channel feeds always have this)
        try {
            $yt = $e->children('http://www.youtube.com/xml/schemas/2015');
            if (isset($yt->videoId)) {
                $candidate = trim((string) $yt->videoId);
                if ($candidate !== '') {
                    $videoId = $candidate;
                }
            }
        } catch (\Throwable $err) {
            // namespace not bound — try other strategies
        }

        // Fallback: parse from <id>yt:video:VIDEOID</id>
        if ($videoId === null) {
            $idStr = trim((string) ($e->id ?? ''));
            if (preg_match('/^yt:video:([A-Za-z0-9_-]{6,})$/', $idStr, $m)) {
                $videoId = $m[1];
            }
        }
        // Fallback: parse from the watch URL in the link
        if ($videoId === null && $link !== '') {
            if (preg_match('#youtube\.com/watch\?[^"\s]*v=([A-Za-z0-9_-]{6,})#', $link, $m)) {
                $videoId = $m[1];
            } elseif (preg_match('#youtu\.be/([A-Za-z0-9_-]{6,})#', $link, $m)) {
                $videoId = $m[1];
            }
        }

        if ($videoId === null) {
            return null;
        }

        $thumbnail = null;
        $description = '';
        try {
            $media = $e->children('http://search.yahoo.com/mrss/');
            // media:group wraps thumbnail/description on YouTube feeds
            if (isset($media->group)) {
                $g = $media->group->children('http://search.yahoo.com/mrss/');
                if (isset($g->thumbnail)) {
                    $url = (string) ($g->thumbnail->attributes()->url ?? '');
                    if ($url !== '') {
                        $thumbnail = $url;
                    }
                }
                if (isset($g->description)) {
                    $description = trim((string) $g->description);
                }
            }
            if ($thumbnail === null && isset($media->thumbnail)) {
                $url = (string) ($media->thumbnail->attributes()->url ?? '');
                if ($url !== '') {
                    $thumbnail = $url;
                }
            }
        } catch (\Throwable $err) {
            // namespace not bound — fall through with null thumbnail
        }
        // Fallback: i.ytimg.com always has predictable thumbnail URLs
        if ($thumbnail === null) {
            $thumbnail = 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
        }

        return [
            'video_id' => $videoId,
            'thumbnail' => $thumbnail,
            'description' => $description,
        ];
    }

    /**
     * Try several common title sources before giving up. Some feeds ship an
     * empty `<title/>` and put the real headline in `dc:title`,
     * `media:title`, or `itunes:title`.
     *
     * @param array<string,string> $namespaces Reserved for future use; SimpleXML's
     *   `children($uri)` works regardless of whether we discovered the prefix.
     */
    private function extractTitle(SimpleXMLElement $node, array $namespaces): string
    {
        unset($namespaces); // currently unused but kept for callsite symmetry
        $title = trim((string) ($node->title ?? ''));
        if ($title !== '') {
            return $this->cleanTitle($title);
        }

        $namespacesToTry = [
            'http://purl.org/dc/elements/1.1/',
            'http://search.yahoo.com/mrss/',
            'http://www.itunes.com/dtds/podcast-1.0.dtd',
        ];
        foreach ($namespacesToTry as $ns) {
            try {
                $children = $node->children($ns);
                if (isset($children->title)) {
                    $val = trim((string) $children->title);
                    if ($val !== '') {
                        return $this->cleanTitle($val);
                    }
                }
            } catch (\Throwable $e) {
                // namespace not bound on this node — skip
            }
        }

        return '';
    }

    /**
     * Strip HTML tags and entity-decode a title string. Some feeds wrap
     * titles in `<![CDATA[<b>Foo</b>]]>` or include `&hellip;`.
     */
    private function cleanTitle(string $title): string
    {
        $t = strip_tags($title);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = (string) preg_replace('/\s+/u', ' ', $t);

        return trim($t);
    }

    /**
     * Derive a usable title from a description/content HTML blob: take the
     * first sentence (or a sensible truncation) of the plain text.
     */
    private function titleFromHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $text = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
        if ($text === '') {
            return '';
        }
        $stop = preg_match('/[.!?](?=\s|$)/u', $text, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;
        if ($stop > 20 && $stop < 140) {
            return substr($text, 0, $stop + 1);
        }
        if (mb_strlen($text) <= 110) {
            return $text;
        }
        $cut = mb_substr($text, 0, 110);
        $lastSpace = mb_strrpos($cut, ' ');

        return ($lastSpace > 60 ? mb_substr($cut, 0, $lastSpace) : $cut) . '…';
    }

    private function extractImageAtom(SimpleXMLElement $e, string $htmlBlob): ?string
    {
        foreach ($e->link ?? [] as $l) {
            $rel = (string) ($l->attributes()->rel ?? '');
            if ($rel === 'enclosure') {
                $type = (string) ($l->attributes()->type ?? '');
                $href = (string) ($l->attributes()->href ?? '');
                if ($href !== '' && str_starts_with($type, 'image/')) {
                    return $href;
                }
            }
        }

        return $this->firstImgFromHtml($htmlBlob);
    }

    private function firstImgFromHtml(string $html): ?string
    {
        if ($html === '') {
            return null;
        }
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function stripToSummary(string $html, int $max = 400): string
    {
        $plain = strip_tags($html);
        if (mb_strlen($plain) <= $max) {
            return '<p>' . htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        }
        $cut = mb_substr($plain, 0, $max) . '…';

        return '<p>' . htmlspecialchars($cut, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
    }

    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
