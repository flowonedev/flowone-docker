#!/usr/bin/env php
<?php
/**
 * News Reader addon — DB, parser, optional live fetch (no production data mutation without --allow-mutate).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/news-reader-test.php --verbose
 *
 * Options:
 *   --help
 *   --verbose
 *   --skip-fetch       Skip outbound HTTP
 *   --smoke            Extensions + DB only
 *   --json
 *   --only=preflight,schema,parser,fetch,extract,proxy,video,shorts
 */
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$longopts = ['help', 'verbose', 'skip-fetch', 'smoke', 'json', 'only:'];
$opts = getopt('', $longopts) ?: [];

if (isset($opts['help'])) {
    echo "news-reader-test.php [--verbose] [--skip-fetch] [--smoke] [--json] [--only=preflight,schema,parser,fetch,extract,proxy,video,shorts]\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$skipFetch = isset($opts['skip-fetch']);
$smoke = isset($opts['smoke']);
$jsonOut = isset($opts['json']);
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/news-reader-test-' . gmdate('Ymd-His') . '.log';

$passed = 0;
$failed = 0;
$warnings = 0;
$failMsgs = [];

function want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

function log_line(string $f, string $m): void
{
    @file_put_contents($f, '[' . gmdate('H:i:s') . '] ' . $m . "\n", FILE_APPEND);
    echo $m . "\n";
}

function color(string $s, string $c): string
{
    $codes = ['green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'reset' => "\033[0m"];
    if (!stream_isatty(STDOUT)) {
        return $s;
    }

    return ($codes[$c] ?? '') . $s . $codes['reset'];
}

function run_test(string $logFile, string $name, callable $fn, bool $verbose): string
{
    global $passed, $failed, $warnings, $failMsgs;
    set_time_limit(30);
    $t0 = microtime(true);
    try {
        $r = $fn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($r === 'warn') {
            $warnings++;
            log_line($logFile, color('[WARN]', 'yellow') . " {$name} ({$ms}ms)");

            return 'WARN';
        }
        $passed++;
        log_line($logFile, color('[PASS]', 'green') . " {$name} ({$ms}ms)");

        return 'PASS';
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $failed++;
        $msg = $e->getMessage();
        $failMsgs[] = "{$name}: {$msg}";
        log_line($logFile, color('[FAIL]', 'red') . " {$name} ({$ms}ms) {$msg}");
        if ($verbose) {
            log_line($logFile, $e->getTraceAsString());
        }

        return 'FAIL';
    }
}

foreach (['pdo', 'pdo_mysql', 'json', 'mbstring', 'curl', 'simplexml'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Pre-flight FAIL: missing extension {$ext}\n");
        exit(1);
    }
}

log_line($logFile, '--- News Reader test ---');

if ($smoke) {
    try {
        \Webmail\Core\Database::getConnection($config)->query('SELECT 1');
        log_line($logFile, '[SMOKE] DB OK');
    } catch (\Throwable $e) {
        log_line($logFile, '[SMOKE] DB FAIL: ' . $e->getMessage());
        exit(1);
    }
    exit(0);
}

if (want($only, 'preflight')) {
    run_test($logFile, 'Redis optional', function () use ($config) {
        $cfg = $config['redis'] ?? [];
        if (empty($cfg['host'])) {
            return 'warn';
        }
        $r = new \Redis();
        $r->connect($cfg['host'], (int) ($cfg['port'] ?? 6379), 2.0);
        if (!empty($cfg['password'])) {
            $r->auth($cfg['password']);
        }

        return true;
    }, $verbose);
}

if (want($only, 'schema')) {
    run_test($logFile, 'Tables news_reader_* exist', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        foreach (['news_reader_feeds', 'news_reader_subscriptions', 'news_reader_items', 'news_reader_reads'] as $t) {
            $st = $db->query("SHOW TABLES LIKE " . $db->quote($t));
            if (!$st || !$st->fetchColumn()) {
                throw new \RuntimeException("Missing table {$t}");
            }
        }

        return true;
    }, $verbose);
}

if (want($only, 'parser')) {
    run_test($logFile, 'FeedParser RSS sample', function () {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><link>http://x</link><item><title>A</title><link>http://a</link><guid>g1</guid><pubDate>Mon, 01 Jan 2024 00:00:00 GMT</pubDate><description><![CDATA[<p>Hi</p>]]></description></item></channel></rss>';
        $p = new \Webmail\Addons\NewsReader\Services\FeedParser();
        $out = $p->parse($xml);
        if (($out['type'] ?? '') !== 'rss' || count($out['items'] ?? []) !== 1) {
            throw new \RuntimeException('RSS parse mismatch');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'FeedParser Atom sample', function () {
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>AT</title><entry><id>e1</id><title>B</title><link href="http://b"/><updated>2024-01-02T00:00:00Z</updated><summary type="html">S</summary></entry></feed>';
        $p = new \Webmail\Addons\NewsReader\Services\FeedParser();
        $out = $p->parse($xml);
        if (($out['type'] ?? '') !== 'atom' || count($out['items'] ?? []) !== 1) {
            throw new \RuntimeException('Atom parse mismatch');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'UrlNormalizer', function () {
        $n = \Webmail\Addons\NewsReader\Services\UrlNormalizer::normalizeFeedUrl('HTTP://Example.com/path/?utm_source=x');
        if (strpos($n['canonical'], 'https://example.com/path') !== 0) {
            throw new \RuntimeException('Normalize failed: ' . $n['canonical']);
        }

        return true;
    }, $verbose);
}

if (want($only, 'fetch') && !$skipFetch) {
    run_test($logFile, 'HTTP fetch BBC RSS', function () {
        $f = new \Webmail\Addons\NewsReader\Services\RssFetcherService();
        $r = $f->fetchOne('https://feeds.bbci.co.uk/news/rss.xml');
        if (empty($r['ok']) && empty($r['not_modified'])) {
            throw new \RuntimeException($r['error'] ?? 'fetch failed');
        }
        if (empty($r['not_modified']) && strlen($r['body'] ?? '') < 200) {
            throw new \RuntimeException('Body too small');
        }

        return true;
    }, $verbose);
}

if (want($only, 'extract')) {
    run_test($logFile, 'Schema: full_content_html columns present', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $st = $db->query('SHOW COLUMNS FROM news_reader_items');
        $cols = array_column($st->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'Field');
        foreach (['full_content_html', 'full_extracted_at', 'full_extract_status', 'full_extract_error'] as $needed) {
            if (!in_array($needed, $cols, true)) {
                throw new \RuntimeException("Missing column {$needed} — run migration 158_news_reader_full_content.sql");
            }
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Extractor: JSON-LD articleBody parses', function () {
        $svc = new \Webmail\Addons\NewsReader\Services\ArticleExtractorService();
        // Use reflection to call the private extractFromJsonLd via the
        // higher-level extract() entry point isn't possible without HTTP.
        // Instead, run a small in-memory parse that exercises the same
        // detection path: we feed a synthetic page through DOMDocument.
        $html = '<html><head><script type="application/ld+json">'
              . json_encode([
                  '@context' => 'https://schema.org',
                  '@type' => 'NewsArticle',
                  'headline' => 'Synthetic',
                  'articleBody' => str_repeat('Lorem ipsum dolor sit amet consectetur. ', 30),
              ])
              . '</script><meta property="og:title" content="Synthetic"/></head><body><article>'
              . '<p>' . str_repeat('body paragraph here. ', 50) . '</p></article></body></html>';
        // Simulate the extract pipeline using reflection on the parser parts
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('extractFromJsonLd');
        $m->setAccessible(true);
        $body = $m->invoke($svc, $xpath);
        if (!is_string($body) || strlen($body) < 100) {
            throw new \RuntimeException('JSON-LD body extraction failed');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Extractor: heuristic falls back to <article>', function () {
        $svc = new \Webmail\Addons\NewsReader\Services\ArticleExtractorService();
        $html = '<html><head></head><body><nav><a href=#>x</a></nav><article>'
              . str_repeat('<p>This is a real article paragraph with some words in it.</p>', 12)
              . '</article></body></html>';
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('extractFromArticleTag');
        $m->setAccessible(true);
        $body = $m->invoke($svc, $xpath);
        if (!is_string($body) || strpos($body, 'real article paragraph') === false) {
            throw new \RuntimeException('Article tag extraction failed');
        }

        return true;
    }, $verbose);

    if (!$skipFetch) {
        run_test($logFile, 'Extractor: live fetch BBC News article', function () {
            $svc = new \Webmail\Addons\NewsReader\Services\ArticleExtractorService();
            // Pick a stable URL from BBC RSS
            $rss = (new \Webmail\Addons\NewsReader\Services\RssFetcherService())
                ->fetchOne('https://feeds.bbci.co.uk/news/rss.xml');
            if (empty($rss['body'])) {
                return 'warn'; // Network blocked or feed down
            }
            $items = (new \Webmail\Addons\NewsReader\Services\FeedParser())->parse($rss['body'])['items'] ?? [];
            if (!$items) {
                return 'warn';
            }
            $url = $items[0]['link'] ?? '';
            if (!$url) {
                return 'warn';
            }
            $out = $svc->extract($url);
            if (!$out || empty($out['content_html']) || ($out['word_count'] ?? 0) < 60) {
                throw new \RuntimeException('Could not extract BBC article: ' . $url);
            }

            return true;
        }, $verbose);
    }
}

if (want($only, 'proxy')) {
    run_test($logFile, 'Proxy URL signature roundtrip', function () use ($config) {
        $ctrl = new \Webmail\Addons\NewsReader\Controllers\NewsReaderController($config);
        $ref = new \ReflectionClass($ctrl);
        $m = $ref->getMethod('signProxyUrl');
        $m->setAccessible(true);
        $url = 'https://example.com/article';
        $exp = time() + 60;
        $a = $m->invoke($ctrl, $url, $exp);
        $b = $m->invoke($ctrl, $url, $exp);
        if (!is_string($a) || strlen($a) < 32 || !hash_equals($a, $b)) {
            throw new \RuntimeException('Signing not deterministic');
        }
        $c = $m->invoke($ctrl, $url, $exp + 1);
        if (hash_equals($a, $c)) {
            throw new \RuntimeException('Signature did not change with exp');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'Proxy URL rejects private host', function () use ($config) {
        $ctrl = new \Webmail\Addons\NewsReader\Controllers\NewsReaderController($config);
        $ref = new \ReflectionClass($ctrl);
        $m = $ref->getMethod('isBlockedHost');
        $m->setAccessible(true);
        foreach (['127.0.0.1', 'localhost', '10.0.0.5', '192.168.1.1', '169.254.169.254'] as $h) {
            if (!$m->invoke($ctrl, $h)) {
                throw new \RuntimeException("Should block: {$h}");
            }
        }
        if ($m->invoke($ctrl, 'flowone.pro')) {
            throw new \RuntimeException('Should not block public host');
        }

        return true;
    }, $verbose);
}

if (want($only, 'video')) {
    run_test($logFile, 'Schema: video columns present on news_reader_items', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $st = $db->query('SHOW COLUMNS FROM news_reader_items');
        $cols = array_column($st->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'Field');
        foreach (['is_video', 'video_id', 'video_thumbnail_url'] as $needed) {
            if (!in_array($needed, $cols, true)) {
                throw new \RuntimeException("Missing column {$needed} — run migration 159_news_reader_videos.sql");
            }
        }
        $st = $db->query('SHOW COLUMNS FROM news_reader_feeds');
        $cols = array_column($st->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'Field');
        if (!in_array('feed_kind', $cols, true)) {
            throw new \RuntimeException('Missing column feed_kind on news_reader_feeds');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'YouTubeFeedResolver: detects all URL forms', function () {
        $r = new \Webmail\Addons\NewsReader\Services\YouTubeFeedResolver();
        $shouldDetect = [
            '@mkbhd',
            'UCBJycsmduvYEL83R_U4JriQ',
            'https://www.youtube.com/@mkbhd',
            'https://www.youtube.com/channel/UCBJycsmduvYEL83R_U4JriQ',
            'https://www.youtube.com/c/MKBHD',
            'https://www.youtube.com/user/marquesbrownlee',
            'youtube.com/@mkbhd',
            'https://youtu.be/abc123',
            'https://www.youtube.com/feeds/videos.xml?channel_id=UCBJycsmduvYEL83R_U4JriQ',
        ];
        foreach ($shouldDetect as $u) {
            if (!$r->isYouTubeUrl($u)) {
                throw new \RuntimeException("Should detect YouTube URL: {$u}");
            }
        }
        $shouldNotDetect = [
            'https://example.com/feed.xml',
            'https://twitter.com/mkbhd',
            'random text',
            '',
        ];
        foreach ($shouldNotDetect as $u) {
            if ($r->isYouTubeUrl($u)) {
                throw new \RuntimeException("Should NOT detect non-YouTube: {$u}");
            }
        }

        return true;
    }, $verbose);

    run_test($logFile, 'YouTubeFeedResolver: builds feed URL from channel ID', function () {
        $r = new \Webmail\Addons\NewsReader\Services\YouTubeFeedResolver();
        $out = $r->resolve('UCBJycsmduvYEL83R_U4JriQ');
        if (!$out || empty($out['feed_url'])) {
            throw new \RuntimeException('Resolver returned nothing');
        }
        if ($out['feed_url'] !== 'https://www.youtube.com/feeds/videos.xml?channel_id=UCBJycsmduvYEL83R_U4JriQ') {
            throw new \RuntimeException('Unexpected feed URL: ' . $out['feed_url']);
        }
        if ($out['channel_id'] !== 'UCBJycsmduvYEL83R_U4JriQ') {
            throw new \RuntimeException('channel_id not surfaced');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'YouTubeFeedResolver: builds feed URL from playlist URL', function () {
        $r = new \Webmail\Addons\NewsReader\Services\YouTubeFeedResolver();
        $out = $r->resolve('https://www.youtube.com/playlist?list=PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf');
        if (!$out || empty($out['feed_url'])) {
            throw new \RuntimeException('Resolver returned nothing');
        }
        if (strpos($out['feed_url'], 'playlist_id=') === false) {
            throw new \RuntimeException('Expected playlist_id in feed URL: ' . $out['feed_url']);
        }

        return true;
    }, $verbose);

    run_test($logFile, 'FeedParser: extracts yt:videoId + thumbnail from Atom YouTube feed', function () {
        $atom = '<?xml version="1.0" encoding="UTF-8"?>'
              . '<feed xmlns="http://www.w3.org/2005/Atom"'
              . ' xmlns:yt="http://www.youtube.com/xml/schemas/2015"'
              . ' xmlns:media="http://search.yahoo.com/mrss/">'
              . '<title>Channel</title>'
              . '<entry>'
              . '<id>yt:video:dQw4w9WgXcQ</id>'
              . '<yt:videoId>dQw4w9WgXcQ</yt:videoId>'
              . '<yt:channelId>UCxxxx</yt:channelId>'
              . '<title>Test Video</title>'
              . '<link rel="alternate" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"/>'
              . '<author><name>Test Channel</name></author>'
              . '<published>2024-01-01T00:00:00+00:00</published>'
              . '<media:group>'
              . '<media:title>Test Video</media:title>'
              . '<media:thumbnail url="https://i3.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg" width="480" height="360"/>'
              . '<media:description>Synthetic video description for the parser test.</media:description>'
              . '</media:group>'
              . '</entry>'
              . '</feed>';
        $parsed = (new \Webmail\Addons\NewsReader\Services\FeedParser())->parse($atom);
        if (!$parsed || empty($parsed['items'])) {
            throw new \RuntimeException('Parser returned no items');
        }
        $it = $parsed['items'][0];
        if (empty($it['is_video'])) {
            throw new \RuntimeException('is_video flag not set');
        }
        if (($it['video_id'] ?? '') !== 'dQw4w9WgXcQ') {
            throw new \RuntimeException('video_id missing or wrong: ' . ($it['video_id'] ?? ''));
        }
        if (strpos($it['video_thumbnail_url'] ?? '', 'ytimg.com') === false) {
            throw new \RuntimeException('Thumbnail not extracted: ' . ($it['video_thumbnail_url'] ?? ''));
        }
        if (strpos($it['summary_html'] ?? '', 'Synthetic video description') === false) {
            throw new \RuntimeException('Description not surfaced as summary');
        }

        return true;
    }, $verbose);

    if (!$skipFetch) {
        run_test($logFile, 'YouTube live feed: NASA channel parses', function () {
            $url = 'https://www.youtube.com/feeds/videos.xml?channel_id=UCLA_DiR1FfKNvjuUpBHmylQ';
            $rss = (new \Webmail\Addons\NewsReader\Services\RssFetcherService())->fetchOne($url);
            if (empty($rss['body'])) {
                return 'warn'; // network blocked
            }
            $parsed = (new \Webmail\Addons\NewsReader\Services\FeedParser())->parse($rss['body']);
            if (empty($parsed['items'])) {
                throw new \RuntimeException('No items parsed from NASA feed');
            }
            $videoItems = array_filter($parsed['items'], fn($i) => !empty($i['is_video']));
            if (count($videoItems) < 1) {
                throw new \RuntimeException('No items flagged as video');
            }
            $first = array_values($videoItems)[0];
            if (empty($first['video_id'])) {
                throw new \RuntimeException('First item missing video_id');
            }

            return true;
        }, $verbose);
    }
}

if (want($only, 'shorts')) {
    run_test($logFile, 'Schema: is_short column present', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $st = $db->query('SHOW COLUMNS FROM news_reader_items');
        $cols = array_column($st->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'Field');
        if (!in_array('is_short', $cols, true)) {
            throw new \RuntimeException('Missing column is_short — run migration 161_news_reader_is_short.sql');
        }

        return true;
    }, $verbose);

    run_test($logFile, 'YouTubeFeedResolver::isYouTubeShort rejects bad input', function () {
        $r = new \Webmail\Addons\NewsReader\Services\YouTubeFeedResolver();
        // Invalid video IDs should return null (refusing to guess), not
        // accidentally call out to YouTube with garbage.
        foreach (['', '   ', '!!!', str_repeat('a', 60)] as $bad) {
            if ($r->isYouTubeShort($bad) !== null) {
                throw new \RuntimeException("Expected null for invalid ID: " . json_encode($bad));
            }
        }

        return true;
    }, $verbose);

    run_test($logFile, 'YouTubeFeedResolver: title heuristic catches self-tagged Shorts (no network)', function () {
        $r = new \Webmail\Addons\NewsReader\Services\YouTubeFeedResolver();
        // Anything that obviously self-identifies as a Short via title
        // must come back true WITHOUT any network round trip. We pass
        // a valid-looking ID so the regex guard passes, then rely on
        // the title to short-circuit.
        $shortyTitles = [
            'Quick clip #shorts',
            'Crazy moment #Shorts',
            'Best save (short)',
            'Funny bit [Short]',
            'Tiny clip 🩳',
        ];
        foreach ($shortyTitles as $t) {
            $res = $r->isYouTubeShort('dQw4w9WgXcQ', $t);
            if ($res !== true) {
                throw new \RuntimeException("Title heuristic missed: " . $t);
            }
        }
        // Negative controls — these MUST NOT be tagged via the title.
        // We may still get a network-driven answer for them, but if
        // it's `true` purely from the title that means the heuristic
        // is too greedy. We exercise the function in network-disabled
        // mode below in the canonical-URL probe test.
        $normalTitles = [
            'Why Forza Horizon 6 is a 10',
            'How #shortstory writing works',  // word-boundary guard
            'Recap: SHORTS is a great brand',
        ];
        $reflection = new \ReflectionClass($r);
        $method = $reflection->getMethod('titleLooksLikeShort');
        $method->setAccessible(true);
        foreach ($normalTitles as $t) {
            if ($method->invoke($r, $t) === true) {
                throw new \RuntimeException("Title heuristic false-positive: " . $t);
            }
        }

        return true;
    }, $verbose);

    if (!$skipFetch) {
        run_test($logFile, 'YouTubeFeedResolver: known full video is NOT a Short', function () {
            $r = new \Webmail\Addons\NewsReader\Services\YouTubeFeedResolver();
            // "Me at the zoo" — the first ever YouTube video, ~19s but
            // pre-Shorts and YouTube doesn't re-route it through /shorts/.
            // Predictable, won't be deleted.
            $res = $r->isYouTubeShort('jNQXAC9IVRw');
            if ($res === null) {
                return 'warn'; // network blocked
            }
            if ($res === true) {
                throw new \RuntimeException('Classic non-Short flagged as Short');
            }

            return true;
        }, $verbose);

        run_test($logFile, 'YouTubeFeedResolver: known YouTube Short IS detected (canonical-URL probe)', function () {
            $r = new \Webmail\Addons\NewsReader\Services\YouTubeFeedResolver();
            // 'I will never be the same' — used by Google in their own
            // /shorts/ docs as a canonical YouTube Short example. If
            // this ever goes private the test will warn instead of
            // failing so cron doesn't go red.
            $shortId = 'aqz-KE-bpKQ';
            $res = $r->isYouTubeShort($shortId);
            if ($res === null) {
                return 'warn'; // network blocked / consent wall / probe ambiguous
            }
            if ($res === false) {
                // Either YouTube removed the Short, or our canonical
                // probe is broken. Surface as a failure so we notice.
                throw new \RuntimeException(
                    'Canonical-URL probe says ' . $shortId . ' is NOT a Short — detector regression?'
                );
            }

            return true;
        }, $verbose);

        run_test($logFile, 'YouTubeFeedResolver: purgeYouTubeShorts callable', function () use ($config) {
            // Sanity check that the cleanup CLI hook is wired. We pass
            // limit=0 so no rows are touched even if there are shorts in
            // the DB; the test only verifies the method runs without
            // throwing on a real connection.
            $svc = new \Webmail\Addons\NewsReader\Services\NewsReaderService($config);
            $n = $svc->purgeYouTubeShorts(0, false);
            if (!is_int($n)) {
                throw new \RuntimeException('purgeYouTubeShorts must return int');
            }

            return true;
        }, $verbose);
    }
}

log_line($logFile, "Summary: passed={$passed} failed={$failed} warnings={$warnings}");
if ($failed > 0) {
    foreach ($failMsgs as $m) {
        log_line($logFile, 'FAILED: ' . $m);
    }
}

if ($jsonOut) {
    echo json_encode(['passed' => $passed, 'failed' => $failed, 'warnings' => $warnings, 'failures' => $failMsgs], JSON_UNESCAPED_SLASHES) . "\n";
}

exit($failed > 0 ? 1 : 0);
