<?php

namespace Webmail\Addons\NewsReader\Services;

/**
 * Resolve any YouTube channel reference into the canonical RSS feed URL.
 *
 * Accepted inputs:
 *   - https://www.youtube.com/feeds/videos.xml?channel_id=UCxxxx   (passthrough)
 *   - https://www.youtube.com/channel/UCxxxx
 *   - https://www.youtube.com/@handle
 *   - https://www.youtube.com/c/CustomName
 *   - https://www.youtube.com/user/LegacyName
 *   - https://www.youtube.com/playlist?list=PLxxxx
 *   - @handle              (bare)
 *   - UCxxxx               (bare 24-char channel ID)
 *   - youtube.com/@handle  (no scheme)
 *
 * For URL forms that don't directly contain the channel ID we GET the
 * channel page and read the canonical channel ID from the
 * `<meta itemprop="channelId" content="UCxxx">` tag (this is YouTube's
 * own SSR'd metadata so it survives layout changes much better than
 * scraping JS).
 */
class YouTubeFeedResolver
{
    private const FETCH_TIMEOUT = 10;
    private const SHORT_CHECK_TIMEOUT = 5;
    private const CHANNEL_ID_RE = '/^UC[A-Za-z0-9_-]{22}$/';
    private const PLAYLIST_ID_RE = '/^PL[A-Za-z0-9_-]{16,}$/';
    private const VIDEO_ID_RE = '/^[A-Za-z0-9_-]{6,20}$/';

    public function isYouTubeUrl(string $input): bool
    {
        $s = trim($input);
        if ($s === '') {
            return false;
        }
        if (preg_match(self::CHANNEL_ID_RE, $s) || preg_match(self::PLAYLIST_ID_RE, $s)) {
            return true;
        }
        if ($s[0] === '@') {
            return true;
        }

        return (bool) preg_match('#^(https?://)?(www\.|m\.)?(youtube\.com|youtu\.be)#i', $s);
    }

    /**
     * @return array{
     *   feed_url: string,
     *   channel_id: ?string,
     *   playlist_id: ?string,
     *   title: ?string,
     *   site_url: ?string
     * }|null
     */
    public function resolve(string $input): ?array
    {
        $s = trim($input);
        if ($s === '') {
            return null;
        }

        // Bare channel ID
        if (preg_match(self::CHANNEL_ID_RE, $s)) {
            return $this->build(channelId: $s);
        }
        // Bare playlist ID
        if (preg_match(self::PLAYLIST_ID_RE, $s)) {
            return $this->build(playlistId: $s);
        }
        // Bare @handle
        if ($s[0] === '@') {
            return $this->resolveFromUrl('https://www.youtube.com/' . $s);
        }
        // Anything else: treat as URL (add scheme if missing)
        if (!preg_match('#^https?://#i', $s)) {
            $s = 'https://' . $s;
        }

        return $this->resolveFromUrl($s);
    }

    /**
     * @return array{
     *   feed_url: string,
     *   channel_id: ?string,
     *   playlist_id: ?string,
     *   title: ?string,
     *   site_url: ?string
     * }|null
     */
    private function resolveFromUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!preg_match('#(^|\.)youtube\.com$|(^|\.)youtu\.be$#', $host)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        // Direct feed URL: /feeds/videos.xml?channel_id=UCxx OR ?playlist_id=PLxx
        if (str_starts_with($path, '/feeds/videos.xml')) {
            if (!empty($query['channel_id'])) {
                return $this->build(channelId: (string) $query['channel_id']);
            }
            if (!empty($query['playlist_id'])) {
                return $this->build(playlistId: (string) $query['playlist_id']);
            }
            if (!empty($query['user'])) {
                // Legacy /user/Name form expressed as a feed query — we have
                // to translate it via the channel page.
                return $this->resolveFromUrl('https://www.youtube.com/user/' . rawurlencode((string) $query['user']));
            }

            return null;
        }

        // /channel/UCxxxx (direct)
        if (preg_match('#^/channel/(UC[A-Za-z0-9_-]{22})#', $path, $m)) {
            return $this->build(channelId: $m[1]);
        }

        // Playlist URL: /playlist?list=PLxx
        if ($path === '/playlist' && !empty($query['list'])) {
            return $this->build(playlistId: (string) $query['list']);
        }

        // Watch URL with playlist param: keep the playlist as the feed, the
        // single video isn't useful for subscribing.
        if ($path === '/watch' && !empty($query['list'])) {
            return $this->build(playlistId: (string) $query['list']);
        }

        // Forms that need scraping the channel page for itemprop="channelId"
        if (
            str_starts_with($path, '/@')
            || str_starts_with($path, '/c/')
            || str_starts_with($path, '/user/')
        ) {
            $channelPage = 'https://www.youtube.com' . $path;
            $resolved = $this->scrapeChannelId($channelPage);
            if ($resolved === null) {
                return null;
            }

            return $this->build(
                channelId: $resolved['channel_id'],
                title: $resolved['title'],
                siteUrl: $channelPage
            );
        }

        return null;
    }

    /**
     * @return array{channel_id:string, title:?string}|null
     */
    private function scrapeChannelId(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.8',
                'Accept-Encoding: gzip, deflate',
            ],
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400 || !is_string($body)) {
            return null;
        }

        // Cap to keep regex bounded
        if (strlen($body) > 1024 * 1024) {
            $body = substr($body, 0, 1024 * 1024);
        }

        $channelId = null;
        if (preg_match('/<meta\s+itemprop="(?:identifier|channelId)"\s+content="(UC[A-Za-z0-9_-]{22})"/', $body, $m)) {
            $channelId = $m[1];
        } elseif (preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $body, $m)) {
            $channelId = $m[1];
        } elseif (preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $body, $m)) {
            $channelId = $m[1];
        } elseif (preg_match('#https?://(?:www\.)?youtube\.com/channel/(UC[A-Za-z0-9_-]{22})#', $body, $m)) {
            $channelId = $m[1];
        }
        if ($channelId === null) {
            return null;
        }

        $title = null;
        if (preg_match('#<meta\s+name="title"\s+content="([^"]+)"#', $body, $m)) {
            $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('#<title>([^<]+)</title>#i', $body, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // YouTube appends " - YouTube" to page titles
            $title = (string) preg_replace('/\s*-\s*YouTube\s*$/u', '', $title);
        }

        return ['channel_id' => $channelId, 'title' => $title];
    }

    /**
     * Returns true if the given video ID is a YouTube Short, false if it's
     * a full-length video, and null when the check could not be completed
     * (network error, ambiguous response). Caller decides how to treat the
     * null case; we deliberately don't conflate "unknown" with "is short"
     * so we never accidentally drop a real video.
     *
     * Detection layers, cheapest first — we short-circuit as soon as one
     * gives a confident answer:
     *
     *   1. **Title heuristic** (no network). If `$title` contains "#shorts",
     *      "#short", "(short)", "[short]", "🩳" etc. it's overwhelmingly
     *      a Short — most channels self-tag them this way. Almost zero
     *      false positives on full videos.
     *   2. **Canonical-URL probe** (1 GET, ~64 KB). Fetch
     *      https://www.youtube.com/watch?v=ID, follow redirects, and
     *      read the `<link rel="canonical">` / `og:url` value out of
     *      the rendered HTML head. YouTube sets that to /shorts/ID for
     *      Shorts and /watch?v=ID for regular videos — i.e. YouTube
     *      itself tells us what it is. Most authoritative signal short
     *      of using the Data API, and works regardless of how YouTube
     *      changes its mobile/desktop redirect behaviour.
     *   3. **HEAD probe on /shorts/{id}** (legacy fallback). Kept as a
     *      tertiary check for the case where step 2 fetches succeed but
     *      the head parser couldn't find a canonical tag.
     *
     * Net cost per call: 0 network ops if the title gives it away,
     * otherwise 1 GET (~300-700 ms). Results should be cached at the
     * call site (e.g. on the `is_short` DB column) so we never re-check
     * the same video.
     */
    public function isYouTubeShort(string $videoId, ?string $title = null): ?bool
    {
        $vid = trim($videoId);
        if ($vid === '' || !preg_match(self::VIDEO_ID_RE, $vid)) {
            return null;
        }

        // 1) Title heuristic — free, deterministic, very high precision.
        if ($title !== null && $this->titleLooksLikeShort($title)) {
            return true;
        }

        // 2) Canonical-URL probe.
        $canonical = $this->fetchCanonicalUrlForVideo($vid);
        if ($canonical !== null) {
            if (stripos($canonical, '/shorts/') !== false) {
                return true;
            }
            if (stripos($canonical, '/watch') !== false) {
                return false;
            }
            // Canonical URL pointed somewhere unexpected (channel page,
            // playlist, error page) — fall through to the HEAD probe.
        }

        // 3) Legacy HEAD probe on /shorts/{id}.
        return $this->headProbeShortUrl($vid);
    }

    /**
     * Pure text heuristic — does this title clearly self-identify as a
     * Short? Designed for very high precision (zero false positives on
     * normal long-form videos) at the cost of recall: lots of shorts
     * won't be caught here, but the ones that are can be filtered
     * without any network round-trip.
     */
    private function titleLooksLikeShort(string $title): bool
    {
        $t = mb_strtolower($title, 'UTF-8');
        if ($t === '') {
            return false;
        }
        // Hashtag form — "#shorts" or "#short" (with or without trailing
        // punctuation / whitespace). Anchored on word boundary on the
        // left so an unrelated word like "#shortstory" doesn't trip.
        if (preg_match('/(?<![a-z0-9])#shorts?\b/u', $t)) {
            return true;
        }
        // Bracketed / parenthesised marker — "(short)", "[shorts]", etc.
        if (preg_match('/[\(\[]\s*shorts?\s*[\)\]]/u', $t)) {
            return true;
        }
        // The "shorts" emoji (U+1FA73 = 🩳). Used by some channels.
        if (mb_strpos($title, "\u{1FA73}") !== false) {
            return true;
        }

        return false;
    }

    /**
     * Fetch the YouTube watch page for `$videoId` and return the value
     * of its `<link rel="canonical">` (or `og:url`) tag. That's the
     * URL YouTube considers canonical for the video — it'll be
     * `https://www.youtube.com/shorts/ID` for a Short and
     * `https://www.youtube.com/watch?v=ID` for a full video.
     *
     * Returns null on any failure (timeout, non-2xx, no tag found).
     * Capped at ~96 KB of body so we never accidentally pull a 5 MB
     * watch page just to scrape one tag.
     */
    private function fetchCanonicalUrlForVideo(string $videoId): ?string
    {
        $url = 'https://www.youtube.com/watch?v=' . $videoId;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => self::SHORT_CHECK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            // Bypass YouTube's EU consent interstitial which would
            // otherwise hand us a meta-refresh / form page with no
            // canonical tag pointing at the actual video.
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.8',
                'Cookie: CONSENT=YES+srp.gws-20210420-0-RC2.en+FX+999',
            ],
        ]);
        // Stop reading once we've grabbed enough of the HTML head to
        // contain canonical/og:url tags. YouTube renders those in the
        // first ~30 KB; 96 KB gives plenty of slack without paying for
        // the full ~2-5 MB payload.
        $body = '';
        $limitBytes = 96 * 1024;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($_ch, string $chunk) use (&$body, $limitBytes) {
            $body .= $chunk;
            $len = strlen($chunk);
            if (strlen($body) >= $limitBytes) {
                // Returning < $len aborts the transfer with CURLE_WRITE_ERROR
                // — which is fine, we already have what we needed.
                return -1;
            }

            return $len;
        });
        curl_exec($ch);
        $info = curl_getinfo($ch);
        $effectiveUrl = (string) ($info['url'] ?? '');
        $code = (int) ($info['http_code'] ?? 0);
        curl_close($ch);

        // If cURL followed a redirect all the way to a /shorts/ URL we
        // already have our answer — no need to parse the body.
        if ($effectiveUrl !== '' && stripos($effectiveUrl, '/shorts/') !== false) {
            return $effectiveUrl;
        }

        if ($code < 200 || $code >= 400 || $body === '') {
            return null;
        }

        // <link rel="canonical" href="..."> (rel and href can be in any
        // order; YouTube currently emits href second).
        if (preg_match('~<link\b[^>]*\brel\s*=\s*["\']canonical["\'][^>]*\bhref\s*=\s*["\']([^"\']+)["\']~i', $body, $m)) {
            return $m[1];
        }
        if (preg_match('~<link\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*\brel\s*=\s*["\']canonical["\']~i', $body, $m)) {
            return $m[1];
        }
        // <meta property="og:url" content="...">.
        if (preg_match('~<meta\b[^>]*\bproperty\s*=\s*["\']og:url["\'][^>]*\bcontent\s*=\s*["\']([^"\']+)["\']~i', $body, $m)) {
            return $m[1];
        }
        if (preg_match('~<meta\b[^>]*\bcontent\s*=\s*["\']([^"\']+)["\'][^>]*\bproperty\s*=\s*["\']og:url["\']~i', $body, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Legacy HEAD probe — kept for callers and as a tertiary signal
     * when the canonical-URL parser can't find a tag. See the original
     * docblock above; in 2026 YouTube's response on /shorts/{id} is
     * less reliable than it once was, hence the demotion to fallback.
     */
    private function headProbeShortUrl(string $videoId): ?bool
    {
        $ch = curl_init('https://www.youtube.com/shorts/' . $videoId);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::SHORT_CHECK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.8',
                'Cookie: CONSENT=YES+srp.gws-20210420-0-RC2.en+FX+999',
            ],
        ]);
        curl_exec($ch);
        $info = curl_getinfo($ch);
        $code = (int) ($info['http_code'] ?? 0);
        $redirect = (string) ($info['redirect_url'] ?? '');
        curl_close($ch);

        if ($code === 0) {
            return null;
        }
        if ($code >= 300 && $code < 400 && $redirect !== '') {
            if (stripos($redirect, '/watch') !== false) {
                return false;
            }

            return null;
        }
        if ($code === 200) {
            return true;
        }
        if ($code === 404) {
            return false;
        }

        return null;
    }

    /**
     * @return array{
     *   feed_url: string,
     *   channel_id: ?string,
     *   playlist_id: ?string,
     *   title: ?string,
     *   site_url: ?string
     * }
     */
    private function build(
        ?string $channelId = null,
        ?string $playlistId = null,
        ?string $title = null,
        ?string $siteUrl = null
    ): array {
        $feedUrl = $channelId
            ? 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId
            : 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $playlistId;
        $defaultSite = $channelId
            ? 'https://www.youtube.com/channel/' . $channelId
            : 'https://www.youtube.com/playlist?list=' . $playlistId;

        return [
            'feed_url' => $feedUrl,
            'channel_id' => $channelId,
            'playlist_id' => $playlistId,
            'title' => $title,
            'site_url' => $siteUrl ?: $defaultSite,
        ];
    }
}
