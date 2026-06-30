<?php

namespace Webmail\Addons\NewsReader\Services;

use PDO;
use Webmail\Core\Database;

class NewsReaderService
{
    private PDO $db;
    private array $config;
    private RssFetcherService $fetcher;
    private FeedParser $parser;
    private ?\Redis $redis = null;
    private ?YouTubeFeedResolver $youtubeResolver = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = Database::getConnection($config);
        $this->fetcher = new RssFetcherService();
        $this->parser = new FeedParser();
        $this->initRedis();
    }

    /**
     * Lazy YouTubeFeedResolver — we only need it during ingest for the
     * shorts redirect check; building one eagerly in the constructor
     * would force every request that touches NewsReaderService (incl.
     * pure reads) to load it.
     */
    private function youtubeResolver(): YouTubeFeedResolver
    {
        if ($this->youtubeResolver === null) {
            $this->youtubeResolver = new YouTubeFeedResolver();
        }

        return $this->youtubeResolver;
    }

    private function initRedis(): void
    {
        $cfg = $this->config['redis'] ?? [];
        if (empty($cfg['host']) || !extension_loaded('redis')) {
            return;
        }
        try {
            $this->redis = new \Redis();
            $this->redis->connect($cfg['host'], (int) ($cfg['port'] ?? 6379), 2.0);
            if (!empty($cfg['password'])) {
                $this->redis->auth($cfg['password']);
            }
            if (!empty($cfg['database'])) {
                $this->redis->select((int) $cfg['database']);
            }
        } catch (\Throwable $e) {
            $this->redis = null;
            error_log('NewsReaderService Redis: ' . $e->getMessage());
        }
    }

    private function redisPrefix(): string
    {
        return ($this->config['redis']['prefix'] ?? 'webmail:') . 'news_reader:';
    }

    public function tryAcquireUserRefreshLock(string $email): bool
    {
        if (!$this->redis) {
            return true;
        }
        $key = $this->redisPrefix() . 'refresh:' . md5(strtolower($email));

        try {
            return (bool) $this->redis->set($key, '1', ['nx', 'ex' => 60]);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * @return array{canonical: string, hash: string}
     */
    public function normalizeUrl(string $url): array
    {
        $n = UrlNormalizer::normalizeFeedUrl($url);

        return ['canonical' => $n['canonical'], 'hash' => $n['hash']];
    }

    /**
     * Get or create feed row; returns feed id.
     *
     * If the input is a YouTube URL/handle/channel ID, we resolve it to
     * the canonical RSS feed URL and tag the feed as kind='video' so the
     * UI can surface video-specific affordances (play badge, embed, etc.).
     */
    public function getOrCreateFeed(string $feedUrl): int
    {
        $kind = 'news';
        $titleHint = null;
        $siteUrlHint = null;
        $youtubeResolver = new YouTubeFeedResolver();
        if ($youtubeResolver->isYouTubeUrl($feedUrl)) {
            $resolved = $youtubeResolver->resolve($feedUrl);
            if ($resolved === null) {
                throw new \InvalidArgumentException('Could not resolve YouTube channel');
            }
            $feedUrl = $resolved['feed_url'];
            $kind = 'video';
            $titleHint = $resolved['title'];
            $siteUrlHint = $resolved['site_url'];
        }

        $norm = UrlNormalizer::normalizeFeedUrl($feedUrl);
        $canonical = $norm['canonical'];
        $hash = $norm['hash'];
        if ($canonical === '') {
            throw new \InvalidArgumentException('Invalid feed URL');
        }

        $st = $this->db->prepare('SELECT id FROM news_reader_feeds WHERE canonical_url_hash = ? LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (int) $row['id'];
        }

        $hasFeedKind = $this->columnExists('news_reader_feeds', 'feed_kind');
        if ($hasFeedKind) {
            $ins = $this->db->prepare(
                'INSERT INTO news_reader_feeds
                    (feed_url, canonical_feed_url, canonical_url_hash, feed_type, feed_kind, title, site_url)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $params = [$feedUrl, $canonical, $hash, 'unknown', $kind, $titleHint, $siteUrlHint];
        } else {
            $ins = $this->db->prepare(
                'INSERT INTO news_reader_feeds
                    (feed_url, canonical_feed_url, canonical_url_hash, feed_type, title, site_url)
                 VALUES (?,?,?,?,?,?)'
            );
            $params = [$feedUrl, $canonical, $hash, 'unknown', $titleHint, $siteUrlHint];
        }

        try {
            $ins->execute($params);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
                $st->execute([$hash]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return (int) $row['id'];
                }
            }
            throw $e;
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Lightweight column-existence check, cached per-process. Used to keep the
     * service usable on environments where a recent migration (e.g. 159) has
     * not yet been applied.
     */
    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $stmt = $this->db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
            $stmt->execute([$column]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $cache[$key] = $row !== false && $row !== null;
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }

    /**
     * @return array{subscription: array, feed_id: int}
     */
    public function subscribe(string $userEmail, string $feedUrl, ?string $category = null): array
    {
        $feedId = $this->getOrCreateFeed($feedUrl);
        $st = $this->db->prepare(
            'SELECT id FROM news_reader_subscriptions WHERE user_email = ? AND feed_id = ? LIMIT 1'
        );
        $st->execute([$userEmail, $feedId]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $this->ingestFeed($feedId);

            return ['subscription' => $this->getSubscriptionById((int) $existing['id'], $userEmail), 'feed_id' => $feedId];
        }

        $ins = $this->db->prepare(
            'INSERT INTO news_reader_subscriptions (user_email, feed_id, is_enabled, category, sort_order)
             VALUES (?,?,1,?,0)'
        );
        $ins->execute([$userEmail, $feedId, $category]);
        $subId = (int) $this->db->lastInsertId();
        $this->ingestFeed($feedId);

        return ['subscription' => $this->getSubscriptionById($subId, $userEmail), 'feed_id' => $feedId];
    }

    public function deleteSubscription(string $userEmail, int $subscriptionId): bool
    {
        $st = $this->db->prepare('DELETE FROM news_reader_subscriptions WHERE id = ? AND user_email = ?');

        return $st->execute([$subscriptionId, $userEmail]) && $st->rowCount() > 0;
    }

    /**
     * @param array{is_enabled?: bool, category?: ?string, sort_order?: int} $patch
     */
    public function patchSubscription(string $userEmail, int $subscriptionId, array $patch): ?array
    {
        $fields = [];
        $vals = [];
        if (array_key_exists('is_enabled', $patch)) {
            $fields[] = 'is_enabled = ?';
            $vals[] = !empty($patch['is_enabled']) ? 1 : 0;
        }
        if (array_key_exists('category', $patch)) {
            $fields[] = 'category = ?';
            $vals[] = $patch['category'];
        }
        if (array_key_exists('sort_order', $patch)) {
            $fields[] = 'sort_order = ?';
            $vals[] = (int) $patch['sort_order'];
        }
        if ($fields === []) {
            return $this->getSubscriptionById($subscriptionId, $userEmail);
        }
        $vals[] = $subscriptionId;
        $vals[] = $userEmail;
        $sql = 'UPDATE news_reader_subscriptions SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_email = ?';
        $st = $this->db->prepare($sql);
        $st->execute($vals);

        return $this->getSubscriptionById($subscriptionId, $userEmail);
    }

    private function getSubscriptionById(int $id, string $userEmail): ?array
    {
        $st = $this->db->prepare(
            'SELECT s.*, f.title AS feed_title, f.canonical_feed_url, f.favicon_url, f.site_url
             FROM news_reader_subscriptions s
             JOIN news_reader_feeds f ON f.id = s.feed_id
             WHERE s.id = ? AND s.user_email = ? LIMIT 1'
        );
        $st->execute([$id, $userEmail]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFeedsWithUnread(string $userEmail): array
    {
        $kindSelect = $this->columnExists('news_reader_feeds', 'feed_kind')
            ? 'f.feed_kind'
            : "'news' AS feed_kind";
        $sql = "SELECT s.id, s.feed_id, s.is_enabled, s.category, s.sort_order, s.created_at,
                       f.title AS feed_title, f.canonical_feed_url, f.favicon_url, f.site_url, f.last_fetched_at, {$kindSelect},
                       (SELECT COUNT(*) FROM news_reader_items i
                         WHERE i.feed_id = s.feed_id
                           AND NOT EXISTS (
                             SELECT 1 FROM news_reader_reads r
                             WHERE r.item_id = i.id AND r.user_email = s.user_email
                           )
                       ) AS unread_count
                FROM news_reader_subscriptions s
                JOIN news_reader_feeds f ON f.id = s.feed_id
                WHERE s.user_email = ?
                ORDER BY s.sort_order ASC, s.id ASC";
        $st = $this->db->prepare($sql);
        $st->execute([$userEmail]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{items: list<array>, next_cursor: ?string, has_more: bool}
     */
    public function listItems(
        string $userEmail,
        int $limit = 50,
        ?string $cursor = null,
        ?int $feedId = null,
        bool $unreadOnly = false,
        ?string $category = null,
        ?string $kind = null,
        ?string $q = null
    ): array {
        $limit = max(1, min(100, $limit));
        $cursorPub = null;
        $cursorId = null;
        if ($cursor) {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            if (is_array($decoded) && isset($decoded['p'], $decoded['i'])) {
                $cursorPub = $decoded['p'];
                $cursorId = (int) $decoded['i'];
            }
        }

        $params = [$userEmail, $userEmail];
        $where = ['s.is_enabled = 1'];
        if ($feedId !== null) {
            $where[] = 'i.feed_id = ?';
            $params[] = $feedId;
        }
        if ($category !== null && $category !== '') {
            $where[] = 's.category = ?';
            $params[] = $category;
        }
        $hasFeedKind = $this->columnExists('news_reader_feeds', 'feed_kind');
        $hasVideoCols = $this->columnExists('news_reader_items', 'is_video');
        $hasShortCol = $this->columnExists('news_reader_items', 'is_short');
        if ($kind !== null && $kind !== '' && $hasFeedKind) {
            $where[] = 'f.feed_kind = ?';
            $params[] = $kind;
        }

        // Free-text search across the title, summary, plain-text body,
        // and the source feed's title. Plain LIKE is more than enough at
        // the article volumes we deal with (a few thousand rows per
        // user); a full-text index would be over-engineering. The
        // wildcards are added server-side so the wildcard chars (`%`,
        // `_`) in the user's query are escaped first.
        if ($q !== null) {
            $needle = trim($q);
            if ($needle !== '') {
                // MySQL LIKE wildcards: escape % and _ so a query like
                // "100%" doesn't match every row.
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
                $like = '%' . $escaped . '%';
                $where[] = '(i.title LIKE ? OR i.summary LIKE ? OR i.content_text LIKE ? OR f.title LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }
        if ($hasShortCol) {
            // Hide YouTube Shorts. Legacy rows from before this column
            // existed default to 0, so they only appear when the
            // --purge-shorts CLI hasn't run yet — they'll get cleaned up
            // separately.
            $where[] = 'i.is_short = 0';
        }
        if ($unreadOnly) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM news_reader_reads r2 WHERE r2.item_id = i.id AND r2.user_email = ?)';
            $params[] = $userEmail;
        }
        if ($cursorPub !== null && $cursorId !== null) {
            $where[] = '(COALESCE(i.published_at, i.created_at) < ? OR (COALESCE(i.published_at, i.created_at) = ? AND i.id < ?))';
            $params[] = $cursorPub;
            $params[] = $cursorPub;
            $params[] = $cursorId;
        }
        $whereSql = implode(' AND ', $where);
        $videoSelect = $hasVideoCols
            ? 'i.is_video, i.video_id, i.video_thumbnail_url'
            : '0 AS is_video, NULL AS video_id, NULL AS video_thumbnail_url';
        $kindSelect = $hasFeedKind ? 'f.feed_kind' : "'news' AS feed_kind";
        $sql = "SELECT i.id, i.feed_id, i.guid, i.item_hash, i.title, i.link, i.summary, i.content_html, i.content_text,
                       i.image_url, i.author, i.published_at, i.created_at,
                       {$videoSelect},
                       s.category AS feed_category,
                       f.title AS feed_title, f.favicon_url AS feed_favicon, f.site_url AS feed_site_url, {$kindSelect},
                       (r.item_id IS NOT NULL) AS is_read
                FROM news_reader_items i
                INNER JOIN news_reader_subscriptions s ON s.feed_id = i.feed_id AND s.user_email = ?
                INNER JOIN news_reader_feeds f ON f.id = i.feed_id
                LEFT JOIN news_reader_reads r ON r.item_id = i.id AND r.user_email = ?
                WHERE {$whereSql}
                ORDER BY COALESCE(i.published_at, i.created_at) DESC, i.id DESC
                LIMIT " . ($limit + 1);

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $next = null;
        if ($hasMore && $rows !== []) {
            $last = end($rows);
            $p = $last['published_at'] ?: $last['created_at'];
            $next = base64_encode(json_encode(['p' => $p, 'i' => (int) $last['id']]));
        }

        foreach ($rows as &$r) {
            $r['is_read'] = (bool) $r['is_read'];
            $r['is_video'] = (bool) ($r['is_video'] ?? false);
        }

        return ['items' => $rows, 'next_cursor' => $next, 'has_more' => $hasMore];
    }

    public function markRead(string $userEmail, int $itemId): bool
    {
        $ok = $this->userOwnsItem($userEmail, $itemId);
        if (!$ok) {
            return false;
        }
        $st = $this->db->prepare(
            'INSERT IGNORE INTO news_reader_reads (user_email, item_id, read_at) VALUES (?,?,NOW())'
        );

        return $st->execute([$userEmail, $itemId]);
    }

    public function markUnread(string $userEmail, int $itemId): bool
    {
        if (!$this->userOwnsItem($userEmail, $itemId)) {
            return false;
        }
        $st = $this->db->prepare('DELETE FROM news_reader_reads WHERE user_email = ? AND item_id = ?');

        return $st->execute([$userEmail, $itemId]);
    }

    /**
     * @param array{feed_id?: ?int, before?: ?string} $body
     */
    public function markAllRead(string $userEmail, array $body): int
    {
        $feedId = !empty($body['feed_id']) ? (int) $body['feed_id'] : null;
        $before = !empty($body['before']) ? (string) $body['before'] : null;
        $params = [$userEmail, $userEmail, $userEmail];
        $feedSql = '';
        if ($feedId) {
            $feedSql = ' AND i.feed_id = ?';
            $params[] = $feedId;
        }
        $beforeSql = '';
        if ($before) {
            $beforeSql = ' AND COALESCE(i.published_at, i.created_at) <= ?';
            $params[] = $before;
        }
        $sql = "INSERT IGNORE INTO news_reader_reads (user_email, item_id, read_at)
                SELECT ?, i.id, NOW()
                FROM news_reader_items i
                INNER JOIN news_reader_subscriptions s ON s.feed_id = i.feed_id AND s.user_email = ? AND s.is_enabled = 1
                WHERE NOT EXISTS (SELECT 1 FROM news_reader_reads r WHERE r.item_id = i.id AND r.user_email = ?)
                {$feedSql}{$beforeSql}";
        $st = $this->db->prepare($sql);
        $st->execute($params);

        return $st->rowCount();
    }

    /**
     * Fetch a fixed set of items by ID (used by the client-side bookmark
     * view). News articles are public content from publishers' RSS feeds,
     * so we don't gate on the user's current subscription state — that
     * way bookmarks survive even if the user unsubscribes from a feed.
     *
     * @param int[] $itemIds
     * @return array<int,array<string,mixed>>
     */
    public function listItemsByIds(string $userEmail, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn($n) => $n > 0)));
        if (!$itemIds) {
            return [];
        }
        // Hard cap to keep memory + query size bounded
        if (count($itemIds) > 500) {
            $itemIds = array_slice($itemIds, 0, 500);
        }
        $place = implode(',', array_fill(0, count($itemIds), '?'));
        $hasVideoCols = $this->columnExists('news_reader_items', 'is_video');
        $hasFeedKind = $this->columnExists('news_reader_feeds', 'feed_kind');
        $videoSelect = $hasVideoCols
            ? 'i.is_video, i.video_id, i.video_thumbnail_url'
            : '0 AS is_video, NULL AS video_id, NULL AS video_thumbnail_url';
        $kindSelect = $hasFeedKind ? 'f.feed_kind' : "'news' AS feed_kind";
        $sql = "SELECT i.id, i.feed_id, i.guid, i.item_hash, i.title, i.link, i.summary, i.content_html, i.content_text,
                       i.image_url, i.author, i.published_at, i.created_at,
                       {$videoSelect},
                       f.title AS feed_title, f.favicon_url AS feed_favicon, f.site_url AS feed_site_url, {$kindSelect},
                       COALESCE(s.category, '') AS feed_category,
                       (r.item_id IS NOT NULL) AS is_read
                FROM news_reader_items i
                INNER JOIN news_reader_feeds f ON f.id = i.feed_id
                LEFT JOIN news_reader_subscriptions s ON s.feed_id = i.feed_id AND s.user_email = ?
                LEFT JOIN news_reader_reads r ON r.item_id = i.id AND r.user_email = ?
                WHERE i.id IN ($place)
                ORDER BY COALESCE(i.published_at, i.created_at) DESC, i.id DESC";
        $params = array_merge([$userEmail, $userEmail], $itemIds);
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['is_read'] = (bool) $r['is_read'];
            $r['is_video'] = (bool) ($r['is_video'] ?? false);
        }

        return $rows;
    }

    /**
     * Return the cached, server-side-extracted full article HTML for an
     * item — fetching and caching it on first call. We re-use the cached
     * version forever once it succeeds (publishers rarely edit articles
     * after publishing), and retry every 24h on failure.
     *
     * @return array{
     *   item_id:int,
     *   status:string,
     *   content_html:?string,
     *   word_count:?int,
     *   lead_image_url:?string,
     *   byline:?string,
     *   site_name:?string,
     *   error:?string,
     *   cached:bool
     * }|null Returns null when the item doesn't exist or the user can't see it
     */
    public function getOrExtractFullContent(string $userEmail, int $itemId): ?array
    {
        if (!$this->userOwnsItem($userEmail, $itemId)) {
            return null;
        }
        $hasVideoCols = $this->columnExists('news_reader_items', 'is_video');
        $videoSelect = $hasVideoCols ? 'is_video' : '0 AS is_video';
        $st = $this->db->prepare(
            "SELECT id, link, {$videoSelect}, full_content_html, full_extracted_at, full_extract_status, full_extract_error
             FROM news_reader_items WHERE id = ? LIMIT 1"
        );
        $st->execute([$itemId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // Video items don't have an article body to extract — the reader
        // shows an embedded YouTube player instead, with the description
        // already in the `summary` field.
        if (!empty($row['is_video'])) {
            return [
                'item_id' => (int) $row['id'],
                'status' => 'skipped',
                'content_html' => null,
                'word_count' => null,
                'lead_image_url' => null,
                'byline' => null,
                'site_name' => null,
                'error' => null,
                'cached' => true,
            ];
        }

        $hasOk = ($row['full_extract_status'] ?? null) === 'ok' && !empty($row['full_content_html']);
        $lastTry = !empty($row['full_extracted_at'])
            ? strtotime((string) $row['full_extracted_at'])
            : 0;
        $retryAfter = 24 * 3600;
        $shouldRetry = !$hasOk && (time() - $lastTry) > $retryAfter;

        if ($hasOk || (!$shouldRetry && $lastTry > 0)) {
            return [
                'item_id' => (int) $row['id'],
                'status' => (string) ($row['full_extract_status'] ?? 'failed'),
                'content_html' => $hasOk ? (string) $row['full_content_html'] : null,
                'word_count' => null,
                'lead_image_url' => null,
                'byline' => null,
                'site_name' => null,
                'error' => $row['full_extract_error'] ?? null,
                'cached' => true,
            ];
        }

        $url = (string) ($row['link'] ?? '');
        if ($url === '') {
            return [
                'item_id' => (int) $row['id'],
                'status' => 'failed',
                'content_html' => null,
                'word_count' => null,
                'lead_image_url' => null,
                'byline' => null,
                'site_name' => null,
                'error' => 'No source URL',
                'cached' => false,
            ];
        }

        $extractor = new ArticleExtractorService();
        try {
            $result = $extractor->extract($url);
        } catch (\Throwable $e) {
            error_log('NewsReader extract: ' . $e->getMessage());
            $result = null;
            $extractorError = substr($e->getMessage(), 0, 480);
        }

        if ($result === null || empty($result['content_html'])) {
            $err = $extractorError ?? 'Could not extract article body';
            $upd = $this->db->prepare(
                'UPDATE news_reader_items
                    SET full_extracted_at = NOW(),
                        full_extract_status = "failed",
                        full_extract_error = ?
                  WHERE id = ?'
            );
            $upd->execute([$err, $itemId]);

            return [
                'item_id' => $itemId,
                'status' => 'failed',
                'content_html' => null,
                'word_count' => null,
                'lead_image_url' => null,
                'byline' => null,
                'site_name' => null,
                'error' => $err,
                'cached' => false,
            ];
        }

        $upd = $this->db->prepare(
            'UPDATE news_reader_items
                SET full_content_html = ?,
                    full_extracted_at = NOW(),
                    full_extract_status = "ok",
                    full_extract_error = NULL
              WHERE id = ?'
        );
        $upd->execute([$result['content_html'], $itemId]);

        return [
            'item_id' => $itemId,
            'status' => 'ok',
            'content_html' => $result['content_html'],
            'word_count' => $result['word_count'] ?? null,
            'lead_image_url' => $result['lead_image_url'] ?? null,
            'byline' => $result['byline'] ?? null,
            'site_name' => $result['site_name'] ?? null,
            'error' => null,
            'cached' => false,
        ];
    }

    private function userOwnsItem(string $userEmail, int $itemId): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM news_reader_items i
             INNER JOIN news_reader_subscriptions s ON s.feed_id = i.feed_id AND s.user_email = ? AND s.is_enabled = 1
             WHERE i.id = ? LIMIT 1'
        );
        $st->execute([$userEmail, $itemId]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Refresh all enabled feeds for user (respects lock outside).
     */
    public function refreshUserSubscriptions(string $userEmail): void
    {
        $st = $this->db->prepare(
            'SELECT DISTINCT f.id FROM news_reader_feeds f
             INNER JOIN news_reader_subscriptions s ON s.feed_id = f.id
             WHERE s.user_email = ? AND s.is_enabled = 1'
        );
        $st->execute([$userEmail]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $fid) {
            try {
                $this->ingestFeed((int) $fid);
            } catch (\Throwable $e) {
                error_log('NewsReaderService refresh feed ' . $fid . ': ' . $e->getMessage());
            }
        }
    }

    public function ingestFeed(int $feedId): void
    {
        $st = $this->db->prepare('SELECT * FROM news_reader_feeds WHERE id = ? LIMIT 1');
        $st->execute([$feedId]);
        $feed = $st->fetch(PDO::FETCH_ASSOC);
        if (!$feed) {
            return;
        }

        $url = $feed['feed_url'] ?: $feed['canonical_feed_url'];
        $res = $this->fetcher->fetchOne(
            $url,
            $feed['last_etag'] ?: null,
            $feed['last_modified'] ?: null
        );
        if (!empty($res['not_modified'])) {
            $up = $this->db->prepare('UPDATE news_reader_feeds SET last_fetched_at = NOW(), fetch_error_count = 0, last_fetch_error = NULL WHERE id = ?');
            $up->execute([$feedId]);

            return;
        }
        if (empty($res['ok']) || ($res['http_code'] ?? 0) >= 400) {
            $err = $res['error'] ?? 'fetch failed';
            $up = $this->db->prepare(
                'UPDATE news_reader_feeds SET fetch_error_count = LEAST(fetch_error_count + 1, 1000), last_fetch_error = ?, last_fetched_at = NOW() WHERE id = ?'
            );
            $up->execute([$err, $feedId]);

            return;
        }

        $body = $res['body'] ?? '';
        $finalUrl = $res['final_url'] ?? $url;
        $parsed = $this->parser->parse($body);
        $type = $parsed['type'] ?? 'unknown';
        $title = $parsed['title'] ?? null;
        $site = $parsed['link'] ?? null;
        $desc = $parsed['description'] ?? null;

        $norm = UrlNormalizer::normalizeFeedUrl($finalUrl);
        $upd = $this->db->prepare(
            'UPDATE news_reader_feeds SET
                feed_url = ?,
                canonical_feed_url = ?,
                canonical_url_hash = ?,
                feed_type = ?,
                title = COALESCE(?, title),
                site_url = COALESCE(?, site_url),
                description = COALESCE(?, description),
                last_fetched_at = NOW(),
                last_etag = ?,
                last_modified = ?,
                fetch_error_count = 0,
                last_fetch_error = NULL
             WHERE id = ?'
        );
        $upd->execute([
            $finalUrl,
            $norm['canonical'],
            $norm['hash'],
            $type,
            $title,
            $site,
            $desc,
            $res['etag'] ?? null,
            $res['last_modified'] ?? null,
            $feedId,
        ]);

        foreach ($parsed['items'] ?? [] as $it) {
            $this->upsertItem($feedId, $it);
        }
    }

    /**
     * @param array<string, mixed> $it
     */
    private function upsertItem(int $feedId, array $it): void
    {
        $link = (string) ($it['link'] ?? '');
        $title = (string) ($it['title'] ?? '');
        $pub = $it['published_at'] ?? null;
        $hash = sha1($link . "\0" . $title . "\0" . ($pub ?? ''));
        $guid = isset($it['guid']) && $it['guid'] !== '' ? (string) $it['guid'] : null;

        $summaryRaw = (string) ($it['summary_html'] ?? '');
        $contentRaw = $it['content_html'] ?? null;
        $summary = HtmlSanitizer::sanitize($summaryRaw) ?: '';
        $contentHtml = $contentRaw !== null && $contentRaw !== '' ? HtmlSanitizer::sanitize((string) $contentRaw) : null;
        $contentText = HtmlSanitizer::toPlainText($contentHtml ?: $summary);

        $img = $it['image_url'] ?? null;
        $author = $it['author'] ?? null;
        $isVideo = !empty($it['is_video']) ? 1 : 0;
        $videoId = isset($it['video_id']) && $it['video_id'] !== '' ? (string) $it['video_id'] : null;
        $videoThumb = isset($it['video_thumbnail_url']) && $it['video_thumbnail_url'] !== '' ? (string) $it['video_thumbnail_url'] : null;

        // YouTube Shorts filter: for any video item we recognise, ask
        // the resolver whether it's a Short (title heuristic +
        // canonical-URL probe + legacy HEAD fallback). When detection
        // is positive we still UPSERT the row but with is_short = 1 —
        // that way:
        //   * a row that was inserted yesterday under a flaky network
        //     (then detected null) gets re-classified the moment a
        //     refresh succeeds, and the list query starts hiding it
        //   * we don't pile up identical "video not yet known to be a
        //     Short" duplicates as YouTube re-emits the entry from
        //     /feeds/videos.xml
        // Network failures still return null and the row is treated as
        // a regular video so we never silently drop real content.
        $detectedShort = false;
        if ($isVideo && $videoId !== null) {
            $isShort = $this->youtubeResolver()->isYouTubeShort($videoId, $title);
            if ($isShort === true) {
                $detectedShort = true;
            }
        }

        $hasVideoCols = $this->columnExists('news_reader_items', 'is_video');
        $hasShortCol = $this->columnExists('news_reader_items', 'is_short');

        if ($hasVideoCols && $hasShortCol) {
            $sql = 'INSERT INTO news_reader_items
                (feed_id, guid, item_hash, title, link, summary, content_html, content_text,
                 image_url, author, published_at, is_video, is_short, video_id, video_thumbnail_url)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    link = VALUES(link),
                    summary = VALUES(summary),
                    content_html = VALUES(content_html),
                    content_text = VALUES(content_text),
                    image_url = VALUES(image_url),
                    author = VALUES(author),
                    published_at = VALUES(published_at),
                    is_video = VALUES(is_video),
                    is_short = VALUES(is_short),
                    video_id = VALUES(video_id),
                    video_thumbnail_url = VALUES(video_thumbnail_url)';
            $params = [
                $feedId,
                $guid,
                $hash,
                mb_substr($title, 0, 1024),
                mb_substr($link, 0, 2048),
                $summary,
                $contentHtml,
                $contentText,
                $img ? mb_substr((string) $img, 0, 2048) : null,
                $author ? mb_substr((string) $author, 0, 255) : null,
                $pub,
                $isVideo,
                $detectedShort ? 1 : 0,
                $videoId ? mb_substr($videoId, 0, 32) : null,
                $videoThumb ? mb_substr($videoThumb, 0, 2048) : null,
            ];
        } elseif ($hasVideoCols) {
            $sql = 'INSERT INTO news_reader_items
                (feed_id, guid, item_hash, title, link, summary, content_html, content_text,
                 image_url, author, published_at, is_video, video_id, video_thumbnail_url)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    link = VALUES(link),
                    summary = VALUES(summary),
                    content_html = VALUES(content_html),
                    content_text = VALUES(content_text),
                    image_url = VALUES(image_url),
                    author = VALUES(author),
                    published_at = VALUES(published_at),
                    is_video = VALUES(is_video),
                    video_id = VALUES(video_id),
                    video_thumbnail_url = VALUES(video_thumbnail_url)';
            $params = [
                $feedId,
                $guid,
                $hash,
                mb_substr($title, 0, 1024),
                mb_substr($link, 0, 2048),
                $summary,
                $contentHtml,
                $contentText,
                $img ? mb_substr((string) $img, 0, 2048) : null,
                $author ? mb_substr((string) $author, 0, 255) : null,
                $pub,
                $isVideo,
                $videoId ? mb_substr($videoId, 0, 32) : null,
                $videoThumb ? mb_substr($videoThumb, 0, 2048) : null,
            ];
        } else {
            $sql = 'INSERT INTO news_reader_items
                (feed_id, guid, item_hash, title, link, summary, content_html, content_text,
                 image_url, author, published_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    link = VALUES(link),
                    summary = VALUES(summary),
                    content_html = VALUES(content_html),
                    content_text = VALUES(content_text),
                    image_url = VALUES(image_url),
                    author = VALUES(author),
                    published_at = VALUES(published_at)';
            $params = [
                $feedId,
                $guid,
                $hash,
                mb_substr($title, 0, 1024),
                mb_substr($link, 0, 2048),
                $summary,
                $contentHtml,
                $contentText,
                $img ? mb_substr((string) $img, 0, 2048) : null,
                $author ? mb_substr((string) $author, 0, 255) : null,
                $pub,
            ];
        }

        $st = $this->db->prepare($sql);
        $st->execute($params);
    }

    /**
     * Re-check every video item against /shorts/{id} and delete the ones
     * that are Shorts. Used by the `--purge-shorts` CLI to clean up
     * legacy rows that were ingested before the column existed.
     *
     * Returns the number of rows deleted.
     */
    public function purgeYouTubeShorts(?int $limit = null, bool $verbose = false): int
    {
        if (!$this->columnExists('news_reader_items', 'video_id')) {
            return 0;
        }
        $sql = 'SELECT id, video_id, title FROM news_reader_items
                 WHERE is_video = 1 AND video_id IS NOT NULL AND video_id <> ""';
        if ($this->columnExists('news_reader_items', 'is_short')) {
            // We also want to scrub rows already flagged as shorts.
            $sql .= ' AND (is_short = 1 OR is_short = 0)';
        }
        $sql .= ' ORDER BY id DESC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $st = $this->db->query($sql);
        if ($st === false) {
            return 0;
        }
        $deleted = 0;
        $resolver = $this->youtubeResolver();
        $del = $this->db->prepare('DELETE FROM news_reader_items WHERE id = ?');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $vid = (string) ($row['video_id'] ?? '');
            if ($vid === '') {
                continue;
            }
            // Pass the title so the title heuristic can short-circuit
            // any video that openly self-tags as a Short — saves a
            // network round trip per purged row, which matters when
            // sweeping legacy backlogs of thousands of items.
            $title = (string) ($row['title'] ?? '');
            $isShort = $resolver->isYouTubeShort($vid, $title !== '' ? $title : null);
            if ($isShort === true) {
                $del->execute([(int) $row['id']]);
                $deleted++;
                if ($verbose) {
                    fwrite(STDOUT, "  purged short: id=" . $row['id'] . " video=" . $vid . "\n");
                }
            }
        }

        return $deleted;
    }

    /**
     * @return list<array{id: int, url: string, etag: ?string, modified: ?string}>
     */
    public function feedsDueForCron(int $minutesStale = 15, int $maxErrors = 5): array
    {
        $sql = "SELECT DISTINCT f.id, f.feed_url, f.last_etag, f.last_modified
                FROM news_reader_feeds f
                INNER JOIN news_reader_subscriptions s ON s.feed_id = f.id AND s.is_enabled = 1
                WHERE f.fetch_error_count < ?
                  AND (f.last_fetched_at IS NULL OR f.last_fetched_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))";
        $st = $this->db->prepare($sql);
        $st->execute([$maxErrors, $minutesStale]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int) $row['id'],
                'url' => (string) $row['feed_url'],
                'etag' => $row['last_etag'] ?: null,
                'modified' => $row['last_modified'] ?: null,
            ];
        }

        return $out;
    }

    public function processCronFetchResult(int $feedId, array $res): void
    {
        if (!empty($res['not_modified'])) {
            $up = $this->db->prepare('UPDATE news_reader_feeds SET last_fetched_at = NOW(), fetch_error_count = 0, last_fetch_error = NULL WHERE id = ?');
            $up->execute([$feedId]);

            return;
        }
        if (empty($res['ok']) || ($res['http_code'] ?? 0) >= 400) {
            $err = $res['error'] ?? 'fetch failed';
            $up = $this->db->prepare(
                'UPDATE news_reader_feeds SET fetch_error_count = LEAST(fetch_error_count + 1, 1000), last_fetch_error = ?, last_fetched_at = NOW() WHERE id = ?'
            );
            $up->execute([$err, $feedId]);

            return;
        }
        $body = $res['body'] ?? '';
        $finalUrl = $res['final_url'] ?? '';
        $parsed = $this->parser->parse($body);
        $type = $parsed['type'] ?? 'unknown';
        $norm = UrlNormalizer::normalizeFeedUrl($finalUrl);

        // If another feed row already owns the resolved canonical hash, do a
        // narrower UPDATE that preserves the row's existing URL identity to
        // avoid violating UNIQUE KEY uniq_canonical_hash on news_reader_feeds.
        // Items still ingest under their own feed_id; the duplicate-feed row
        // is left for a separate cleanup tool to merge.
        $collisionFeedId = null;
        if (($norm['hash'] ?? '') !== '') {
            $stmtCollide = $this->db->prepare(
                'SELECT id FROM news_reader_feeds WHERE canonical_url_hash = ? AND id <> ? LIMIT 1'
            );
            $stmtCollide->execute([$norm['hash'], $feedId]);
            $collisionRow = $stmtCollide->fetch(PDO::FETCH_ASSOC);
            if ($collisionRow) {
                $collisionFeedId = (int) $collisionRow['id'];
            }
        }

        if ($collisionFeedId !== null) {
            $this->logCanonicalHashCollisionOnce($feedId, $collisionFeedId, $norm['hash']);
            $upd = $this->db->prepare(
                'UPDATE news_reader_feeds SET
                    feed_type = ?,
                    title = COALESCE(?, title),
                    site_url = COALESCE(?, site_url),
                    description = COALESCE(?, description),
                    last_fetched_at = NOW(),
                    last_etag = ?,
                    last_modified = ?,
                    fetch_error_count = 0,
                    last_fetch_error = NULL
                 WHERE id = ?'
            );
            $upd->execute([
                $type,
                $parsed['title'] ?? null,
                $parsed['link'] ?? null,
                $parsed['description'] ?? null,
                $res['etag'] ?? null,
                $res['last_modified'] ?? null,
                $feedId,
            ]);
        } else {
            $upd = $this->db->prepare(
                'UPDATE news_reader_feeds SET
                    feed_url = ?,
                    canonical_feed_url = ?,
                    canonical_url_hash = ?,
                    feed_type = ?,
                    title = COALESCE(?, title),
                    site_url = COALESCE(?, site_url),
                    description = COALESCE(?, description),
                    last_fetched_at = NOW(),
                    last_etag = ?,
                    last_modified = ?,
                    fetch_error_count = 0,
                    last_fetch_error = NULL
                 WHERE id = ?'
            );
            $upd->execute([
                $finalUrl,
                $norm['canonical'],
                $norm['hash'],
                $type,
                $parsed['title'] ?? null,
                $parsed['link'] ?? null,
                $parsed['description'] ?? null,
                $res['etag'] ?? null,
                $res['last_modified'] ?? null,
                $feedId,
            ]);
        }
        foreach ($parsed['items'] ?? [] as $it) {
            $this->upsertItem($feedId, $it);
        }
    }

    /**
     * Emit a single INFO log per (feedId -> collidingFeedId) pair per process so
     * the news-refresh cron stops spamming the PHP error log every 15 minutes
     * with the same 1062 Duplicate entry violation. Redis-backed when available
     * so the suppression survives across cron runs; otherwise per-process only,
     * which still cuts the noise by the number of feeds processed per run.
     */
    private function logCanonicalHashCollisionOnce(int $feedId, int $collisionFeedId, string $hash): void
    {
        static $seen = [];
        $key = $feedId . '->' . $collisionFeedId;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        $shouldLog = true;
        if ($this->redis) {
            try {
                $redisKey = $this->redisPrefix() . 'feed_hash_collision:' . $key;
                $set = $this->redis->set($redisKey, '1', ['nx', 'ex' => 86400]);
                $shouldLog = (bool) $set;
            } catch (\Throwable $e) {
                $shouldLog = true;
            }
        }

        if ($shouldLog) {
            error_log(sprintf(
                '[news-refresh] feed %d resolves to same canonical_url_hash as feed %d (hash=%s); keeping existing URL on row to preserve uniqueness',
                $feedId,
                $collisionFeedId,
                $hash
            ));
        }
    }

    public function runRetention(int $days = 30): int
    {
        $env = getenv('NEWS_RETENTION_DAYS');
        if ($env !== false && (int) $env > 0) {
            $days = (int) $env;
        }
        $st = $this->db->prepare('DELETE FROM news_reader_items WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');

        return $st->execute([$days]) ? $st->rowCount() : 0;
    }

    public function tryCronLock(int $ttlSeconds = 840): bool
    {
        if (!$this->redis) {
            return true;
        }
        $key = $this->redisPrefix() . 'cron_lock';

        try {
            return (bool) $this->redis->set($key, '1', ['nx', 'ex' => $ttlSeconds]);
        } catch (\Throwable $e) {
            return true;
        }
    }
}
