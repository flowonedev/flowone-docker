<?php

namespace Webmail\Addons\NewsReader\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\NewsReader\Services\CuratedCatalogService;
use Webmail\Addons\NewsReader\Services\NewsReaderService;

class NewsReaderController extends BaseController
{
    private ?NewsReaderService $service = null;
    private ?CuratedCatalogService $catalog = null;

    private function svc(): NewsReaderService
    {
        if ($this->service === null) {
            $this->service = new NewsReaderService($this->config);
        }

        return $this->service;
    }

    private function cat(): CuratedCatalogService
    {
        if ($this->catalog === null) {
            $this->catalog = new CuratedCatalogService();
        }

        return $this->catalog;
    }

    public function catalog(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        return Response::success(['catalog' => $this->cat()->getGrouped()]);
    }

    public function feeds(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }

        return Response::success(['feeds' => $this->svc()->listFeedsWithUnread($email)]);
    }

    public function items(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }

        $limit = (int) $request->getQuery('limit', 50);
        $cursor = $request->getQuery('cursor');
        $feedId = $request->getQuery('feed_id');
        $unreadOnly = $request->getQuery('unread_only', '0') === '1';
        $category = $request->getQuery('category');
        $kind = $request->getQuery('kind');
        $q = $request->getQuery('q');

        $data = $this->svc()->listItems(
            $email,
            $limit,
            is_string($cursor) ? $cursor : null,
            $feedId !== null && $feedId !== '' ? (int) $feedId : null,
            $unreadOnly,
            is_string($category) && $category !== '' ? $category : null,
            is_string($kind) && in_array($kind, ['news', 'video', 'social'], true) ? $kind : null,
            // Cap query length defensively so a 10MB query string doesn't
            // get LIKE-matched against every article body.
            is_string($q) && trim($q) !== '' ? mb_substr(trim($q), 0, 200) : null
        );

        return Response::success($data);
    }

    public function subscribe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        $feedUrl = (string) $request->input('feed_url', '');
        if ($feedUrl === '') {
            return Response::error('feed_url required', 400);
        }
        $category = $request->input('category');
        try {
            $out = $this->svc()->subscribe($email, $feedUrl, is_string($category) ? $category : null);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('NewsReader subscribe: ' . $e->getMessage());

            return Response::error('Subscription failed', 500);
        }

        return Response::success($out, 'Subscribed');
    }

    public function patchSubscription(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        $id = (int) $request->getParam('id', 0);
        if ($id < 1) {
            return Response::error('Invalid id', 400);
        }
        $patch = [];
        if ($request->has('is_enabled')) {
            $patch['is_enabled'] = (bool) $request->input('is_enabled');
        }
        if ($request->has('category')) {
            $patch['category'] = $request->input('category');
        }
        if ($request->has('sort_order')) {
            $patch['sort_order'] = (int) $request->input('sort_order');
        }
        $row = $this->svc()->patchSubscription($email, $id, $patch);
        if (!$row) {
            return Response::error('Not found', 404);
        }

        return Response::success(['subscription' => $row]);
    }

    public function deleteSubscription(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        $id = (int) $request->getParam('id', 0);
        if ($id < 1) {
            return Response::error('Invalid id', 400);
        }
        if (!$this->svc()->deleteSubscription($email, $id)) {
            return Response::error('Not found', 404);
        }

        return Response::success([], 'Removed');
    }

    public function markRead(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        $itemId = (int) $request->getParam('id', 0);
        if ($itemId < 1) {
            return Response::error('Invalid id', 400);
        }
        if (!$this->svc()->markRead($email, $itemId)) {
            return Response::error('Not found or not allowed', 404);
        }

        return Response::success([], 'Marked read');
    }

    public function markUnread(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        $itemId = (int) $request->getParam('id', 0);
        if ($itemId < 1) {
            return Response::error('Invalid id', 400);
        }
        if (!$this->svc()->markUnread($email, $itemId)) {
            return Response::error('Not found or not allowed', 404);
        }

        return Response::success([], 'Marked unread');
    }

    public function readAll(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        $body = $request->input() ?: [];
        $n = $this->svc()->markAllRead($email, is_array($body) ? $body : []);

        return Response::success(['updated' => $n]);
    }

    public function refresh(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        if (!$this->svc()->tryAcquireUserRefreshLock($email)) {
            return Response::success(['skipped' => true, 'reason' => 'rate_limited'], 'Refresh skipped');
        }
        try {
            $this->svc()->refreshUserSubscriptions($email);
        } catch (\Throwable $e) {
            error_log('NewsReader refresh: ' . $e->getMessage());

            return Response::error('Refresh failed', 500);
        }

        return Response::success([], 'Refreshed');
    }

    /**
     * Fetch a list of items by their IDs. Used by the client-side
     * bookmarks filter (bookmarks live in localStorage so the server
     * doesn't know which articles a user has saved).
     */
    public function itemsByIds(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        $idsRaw = (string) $request->getQuery('ids', '');
        if ($idsRaw === '') {
            return Response::success(['items' => []]);
        }
        $ids = array_filter(array_map('intval', explode(',', $idsRaw)), static fn($n) => $n > 0);
        if (!$ids) {
            return Response::success(['items' => []]);
        }

        return Response::success(['items' => $this->svc()->listItemsByIds($email, $ids)]);
    }

    /**
     * Server-side full article extraction.
     *
     * RSS feeds typically only ship a 1–2 sentence summary. This endpoint
     * fetches the publisher's article page and runs our Readability-style
     * extractor to return the full clean article body. Result is cached on
     * the item row (one extraction per article ever, retried daily on
     * failure).
     */
    public function fullArticle(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }
        $itemId = (int) $request->getParam('id', 0);
        if ($itemId < 1) {
            return Response::error('Invalid id', 400);
        }
        $result = $this->svc()->getOrExtractFullContent($email, $itemId);
        if ($result === null) {
            return Response::error('Not found', 404);
        }

        return Response::success($result);
    }

    /**
     * Generate a short-lived signed proxy URL for an article.
     *
     * The frontend calls this with the article URL (sending its bearer
     * token as usual). We return a same-origin URL that the iframe can
     * load without auth headers (iframes can't send Authorization:).
     * The signature is an HMAC of `url|exp` with the app secret, valid for
     * 30 minutes. This prevents anyone from using our proxy as an open
     * fetcher.
     */
    public function proxyUrl(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        $email = $this->getActiveEmail();
        if (!$email) {
            return Response::error('Unauthorized', 401);
        }

        $url = (string) $request->getQuery('url', '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return Response::error('Invalid url', 400);
        }
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return Response::error('Only http(s) allowed', 400);
        }

        $exp = time() + 1800;
        $sig = $this->signProxyUrl($url, $exp);
        $proxyPath = '/api/news/proxy?url=' . rawurlencode($url) . '&exp=' . $exp . '&sig=' . $sig;

        return Response::success(['proxy_url' => $proxyPath]);
    }

    /**
     * Server-side article proxy.
     *
     * Many publishers (NYT, BBC, etc.) send `X-Frame-Options: DENY` or
     * `Content-Security-Policy: frame-ancestors 'none'` so their pages
     * cannot be embedded in our reader's iframe. This endpoint fetches the
     * page server-side, strips the framing-blocker headers, injects a
     * `<base>` tag so relative asset URLs still resolve to the original
     * host, and streams the body back. The iframe then loads our same-origin
     * URL which the browser allows.
     *
     * Auth model: this endpoint is called from inside an `<iframe>` which
     * cannot send our Authorization bearer token, so we use a short-lived
     * HMAC-signed URL instead (issued by `proxyUrl()`).
     *
     * Security:
     *  - HMAC signature validated against url + exp
     *  - URL must be a valid http(s) URL
     *  - Hostname is resolved and rejected if it's a private/loopback IP (SSRF)
     *  - Response is restricted to text/html and capped at 4 MB
     */
    public function proxyArticle(Request $request): Response
    {
        $url = (string) $request->getQuery('url', '');
        $exp = (int) $request->getQuery('exp', 0);
        $sig = (string) $request->getQuery('sig', '');

        if ($url === '' || $exp <= 0 || $sig === '') {
            return Response::error('Missing params', 400);
        }
        if (time() > $exp) {
            return Response::error('Link expired', 403);
        }
        $expected = $this->signProxyUrl($url, $exp);
        if (!hash_equals($expected, $sig)) {
            return Response::error('Bad signature', 403);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return Response::error('Invalid url', 400);
        }
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host = strtolower($parsed['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return Response::error('Only http(s) allowed', 400);
        }
        if ($host === '' || $this->isBlockedHost($host)) {
            return Response::error('Forbidden host', 403);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.8,hu;q=0.6',
                'Accept-Encoding: gzip, deflate',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_BUFFERSIZE => 65536,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $finalUrl = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        $err = curl_error($ch);
        curl_close($ch);

        // Allow embedding from our own app on web AND from the Capacitor
        // shell on mobile (which uses capacitor://localhost as origin).
        // The HMAC signature already prevents arbitrary use.
        $frameAncestors = "frame-ancestors 'self' https://flowone.pro capacitor:";
        $errHeaders = [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => $frameAncestors,
        ];

        if ($body === false) {
            error_log('NewsReader proxy curl error: ' . $err);

            return Response::raw($this->frameError('Could not reach the publisher.', $url), 502, $errHeaders);
        }
        if ($code >= 400) {
            return Response::raw($this->frameError("Publisher returned HTTP $code.", $url), 200, $errHeaders);
        }
        if (stripos($ctype, 'text/html') === false) {
            return Response::raw($this->frameError('This URL is not an HTML page.', $url), 200, $errHeaders);
        }

        // Clamp size to 4 MB to keep memory bounded
        if (strlen($body) > 4 * 1024 * 1024) {
            $body = substr($body, 0, 4 * 1024 * 1024);
        }

        // Inject <base href> so relative URLs (CSS, JS, images, links) keep
        // resolving against the original publisher's host
        $base = '<base href="' . htmlspecialchars($finalUrl, ENT_QUOTES) . '">';
        $injected = false;
        if (preg_match('/<head[^>]*>/i', $body, $m)) {
            $body = (string) preg_replace('/<head[^>]*>/i', $m[0] . $base, $body, 1);
            $injected = true;
        }
        if (!$injected && preg_match('/<html[^>]*>/i', $body, $m)) {
            $body = (string) preg_replace(
                '/<html[^>]*>/i',
                $m[0] . '<head>' . $base . '</head>',
                $body,
                1
            );
            $injected = true;
        }
        if (!$injected) {
            $body = $base . $body;
        }

        // Strip CSP <meta> tags that could re-block embedding from inside
        $body = (string) preg_replace(
            '/<meta[^>]*http-equiv\s*=\s*["\']?Content-Security-Policy["\']?[^>]*>/i',
            '',
            $body
        );
        $body = (string) preg_replace(
            '/<meta[^>]*http-equiv\s*=\s*["\']?X-Frame-Options["\']?[^>]*>/i',
            '',
            $body
        );

        return Response::raw($body, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => $frameAncestors,
            'Cache-Control' => 'public, max-age=300',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function signProxyUrl(string $url, int $exp): string
    {
        $candidates = [
            $this->config['imap_encryption_key'] ?? null,
            $this->config['jwt']['secret'] ?? null,
            $this->config['app_secret'] ?? null,
            getenv('IMAP_ENCRYPTION_KEY') ?: null,
            getenv('JWT_SECRET') ?: null,
            getenv('APP_SECRET') ?: null,
        ];
        $secret = '';
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                $secret = $c;
                break;
            }
        }
        if ($secret === '') {
            // Last-resort deterministic fallback so signing still works on
            // a misconfigured env. Not security-grade, but the proxy URLs
            // it signs only expose public news articles.
            $secret = hash('sha256', __FILE__);
        }

        return hash_hmac('sha256', $url . '|' . $exp, $secret);
    }

    private function isBlockedHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPrivateIp($host);
        }
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return true;
        }

        return $this->isPrivateIp($ip);
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function frameError(string $message, string $url): string
    {
        $safeMsg = htmlspecialchars($message, ENT_QUOTES);
        $safeUrl = htmlspecialchars($url, ENT_QUOTES);

        return '<!doctype html><html><head><meta charset="utf-8"><title>Article unavailable</title>'
            . '<style>html,body{height:100%;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
            . 'background:#1c1c22;color:#e5e5e7;display:flex;align-items:center;justify-content:center;text-align:center;padding:2rem}'
            . '.box{max-width:480px}h1{font-size:1.1rem;margin:0 0 .5rem;font-weight:600}p{margin:.5rem 0;opacity:.75;font-size:.9rem}'
            . 'a{display:inline-block;margin-top:1rem;padding:.55rem 1rem;border-radius:9999px;background:#a855f7;color:white;text-decoration:none;font-weight:600;font-size:.85rem}'
            . 'a:hover{opacity:.9}</style></head><body><div class="box">'
            . '<h1>' . $safeMsg . '</h1><p>The publisher might block embedding or be temporarily unreachable.</p>'
            . '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">Open in new tab</a>'
            . '</div></body></html>';
    }
}
