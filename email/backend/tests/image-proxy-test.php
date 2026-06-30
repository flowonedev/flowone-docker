#!/usr/bin/env php
<?php
/**
 * FlowOne Image Proxy Test.
 *
 * Covers the /api/mailbox/image-proxy chain after the 2026-06-11 fix for
 * the "Instagram email falls apart" bug:
 *
 *   1. The frontend used to bake HTML entities (`&amp;`) into proxied URLs
 *      (`%26amp%3B`), breaking CDN signature validation.
 *   2. imageProxy() used to urldecode() an already-decoded $_GET value
 *      (double decode), corrupting URLs containing %3D / + / %26.
 *   3. Failures used to return HTTP 502, so the browser rendered enormous
 *      <img alt> texts that shredded email layouts into vertical strips.
 *      Failures now return a placeholder SVG with 200 / no-store.
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   PHP extensions, autoloader, classes, placeholder SVG
 *   decode      query-param decode chain regression (no double decode)
 *   ssrf        URL validation guards (offline; no network used)
 *   network     live fetches: query-string integrity, 404, content-type
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/image-proxy-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no business logic, no network)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --skip-send            skip external network fetches
 *   --timeout=N            per-test timeout in seconds (default 30)
 *   --help                 show this message
 *
 * Non-destructive: performs read-only GET fetches of public images only;
 * creates no DB rows, writes no files besides its own log.
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/lib/test-runner.php';

$runner = new FlowOneTestRunner('image-proxy', $argv);

$backendRoot = realpath(__DIR__ . '/..');
$autoloader = $backendRoot . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php curl extension loaded', function () use ($runner) {
        $runner->assertTrue(extension_loaded('curl'), 'curl extension missing');
    });

    $runner->test('php openssl extension loaded', function () use ($runner) {
        $runner->assertTrue(extension_loaded('openssl'), 'openssl extension missing');
    });

    $runner->test('composer autoloader present', function () use ($runner, $autoloader) {
        $runner->assertTrue(file_exists($autoloader), 'vendor/autoload.php not found');
        require_once $autoloader;
    });

    $runner->test('RemoteImageProxyService class loads', function () use ($runner) {
        $runner->assertTrue(
            class_exists(\Webmail\Services\RemoteImageProxyService::class),
            'RemoteImageProxyService not autoloadable'
        );
    });

    $runner->test('placeholder SVG is valid and self-contained', function () use ($runner) {
        $svg = \Webmail\Services\RemoteImageProxyService::placeholderSvg();
        $runner->assertTrue(str_starts_with($svg, '<svg'), 'placeholder must start with <svg');
        $runner->assertTrue(str_contains($svg, 'xmlns="http://www.w3.org/2000/svg"'), 'placeholder missing xmlns');
        $runner->assertTrue(str_contains($svg, '</svg>'), 'placeholder not closed');
        $runner->assertTrue(!str_contains($svg, 'script'), 'placeholder must not contain script');
        // Must parse as XML so browsers actually render it.
        $prev = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($svg);
        libxml_use_internal_errors($prev);
        $runner->assertTrue($parsed !== false, 'placeholder SVG is not well-formed XML');
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

require_once $autoloader;

// ---------------------------------------------------------------------------
// 2. DECODE (double-decode regression)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('decode')) {
    $runner->section('2. DECODE');

    // The exact CDN URL shape that broke: query values containing encoded
    // '=' (%3D), '&' (%26) and literal '+'.
    $cdnUrl = 'https://scontent.cdninstagram.com/v/photo.jpg'
        . '?stp=dst-jpg_s206x206&efg=eyJ2ZW5jb2RlfQ%3D%3D&_nc_ohc=a+b%26c&oh=00_Af-sig&oe=6A301385';

    $runner->test('php query parsing round-trips an encodeURIComponent-ed URL', function () use ($runner, $cdnUrl) {
        // Simulate browser -> webserver -> PHP: the frontend sends
        // url=encodeURIComponent(cdnUrl); PHP percent-decodes ONCE into $_GET.
        parse_str('url=' . rawurlencode($cdnUrl), $get);
        $runner->assertEquals($cdnUrl, $get['url'], 'PHP query parsing corrupted the URL');
    });

    $runner->test('Request::getQuery returns the $_GET value untouched', function () use ($runner, $cdnUrl) {
        $backupGet = $_GET;
        $backupServer = $_SERVER;
        try {
            $_GET = ['url' => $cdnUrl];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/api/mailbox/image-proxy?url=' . rawurlencode($cdnUrl);
            $request = new \Webmail\Core\Request();
            $runner->assertEquals($cdnUrl, $request->getQuery('url'), 'Request mangled the url param');
        } finally {
            $_GET = $backupGet;
            $_SERVER = $backupServer;
        }
    });

    $runner->test('a second urldecode WOULD corrupt the URL (documents the old bug)', function () use ($runner, $cdnUrl) {
        $corrupted = urldecode($cdnUrl);
        $runner->assertTrue($corrupted !== $cdnUrl, 'expected double decode to corrupt this URL');
        $runner->assertTrue(str_contains($corrupted, 'eyJ2ZW5jb2RlfQ=='), 'expected %3D%3D to collapse to ==');
        $runner->assertTrue(str_contains($corrupted, 'a b&c'), 'expected + and %26 to collapse');
    });

    $runner->test('imageProxy() no longer double-decodes (source tripwire)', function () use ($runner, $backendRoot) {
        $source = file_get_contents($backendRoot . '/src/Controllers/MailboxController.php');
        $runner->assertTrue($source !== false, 'cannot read MailboxController.php');
        $runner->assertTrue(
            preg_match('/function imageProxy\(.*?\n    \}/s', $source, $m) === 1,
            'cannot locate imageProxy() method body'
        );
        // Match an actual call on a variable (urldecode($url)), not the
        // explanatory comment that mentions the function by name.
        $runner->assertTrue(
            !str_contains($m[0], 'urldecode($'),
            'imageProxy() calls urldecode() again -- double-decode regression'
        );
    });

    $runner->test('imageProxy() failure path serves the placeholder (source tripwire)', function () use ($runner, $backendRoot) {
        $source = file_get_contents($backendRoot . '/src/Controllers/MailboxController.php');
        preg_match('/function imageProxy\(.*?\n    \}/s', $source, $m);
        $runner->assertTrue(
            str_contains($m[0] ?? '', 'placeholderSvg'),
            'imageProxy() catch block does not serve placeholderSvg()'
        );
        $runner->assertTrue(
            str_contains($m[0] ?? '', 'no-store'),
            'placeholder response must be Cache-Control: no-store'
        );
    });

    $runner->test('frontend corruption signature %26amp%3B would break CDN params', function () use ($runner) {
        // What the OLD frontend produced: entity left in the captured src.
        $brokenParam = rawurldecode('photo.jpg%3Fstp%3Dx%26amp%3B_nc_cat%3D1');
        $runner->assertTrue(
            str_contains($brokenParam, '&amp;_nc_cat'),
            'expected the corruption to surface as &amp; separators'
        );
        parse_str((string) parse_url('https://h/' . $brokenParam, PHP_URL_QUERY), $params);
        $runner->assertTrue(
            isset($params['amp;_nc_cat']),
            'expected the CDN to see a mangled "amp;_nc_cat" param name'
        );
    });
}

// ---------------------------------------------------------------------------
// 3. SSRF GUARDS (offline -- validateUrl throws before any network I/O)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('ssrf')) {
    $runner->section('3. SSRF GUARDS');

    $expectReject = function (string $url, string $label) use ($runner) {
        $runner->test($label, function () use ($runner, $url) {
            $proxy = new \Webmail\Services\RemoteImageProxyService();
            try {
                $proxy->fetch($url);
            } catch (\RuntimeException $e) {
                return; // rejected as expected
            }
            throw new \RuntimeException('URL was NOT rejected: ' . $url);
        }, 10);
    };

    $expectReject('http://localhost/x.png', 'rejects localhost');
    $expectReject('http://127.0.0.1/x.png', 'rejects loopback IP');
    $expectReject('https://nas.internal/x.png', 'rejects .internal hosts');
    $expectReject('https://printer.local/x.png', 'rejects .local hosts');
    $expectReject('ftp://example.com/x.png', 'rejects non-http(s) schemes');
    $expectReject('https://example.com:6379/x.png', 'rejects blocked port (redis)');
    $expectReject('not a url at all', 'rejects malformed URLs');
}

// ---------------------------------------------------------------------------
// 4. NETWORK (live fetches; skipped with --skip-send)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('network')) {
    $runner->section('4. NETWORK');

    // A missing CA bundle (curl.cainfo) fails EVERY https fetch. On the
    // production server that means the image proxy is entirely broken, so
    // it must FAIL -- but say so explicitly instead of three cryptic errors.
    $fetchOrExplain = function (string $url): array {
        $proxy = new \Webmail\Services\RemoteImageProxyService();
        try {
            return $proxy->fetch($url);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'local issuer certificate')) {
                throw new \RuntimeException(
                    $e->getMessage()
                    . ' -- PHP curl has no CA bundle (set curl.cainfo / openssl.cafile).'
                    . ' If this happens on the server, the image proxy cannot fetch ANY https image.'
                );
            }
            throw $e;
        }
    };

    if ($runner->skipSend) {
        $runner->test('live fetches skipped (--skip-send)', fn() => 'skip');
    } else {
        $runner->test('fetches a real image whose URL has a multi-param query string', function () use ($runner, $fetchOrExplain) {
            // Gravatar identicon: public, stable, idempotent GET. The query
            // string (& separators, = values) must survive the whole chain.
            $url = 'https://www.gravatar.com/avatar/00000000000000000000000000000000?d=identicon&s=64';
            $result = $fetchOrExplain($url);
            $runner->assertTrue(strlen($result['data']) > 100, 'image payload suspiciously small');
            $runner->assertTrue(
                str_starts_with($result['content_type'], 'image/'),
                'unexpected content type: ' . $result['content_type']
            );
        }, 20);

        $runner->test('a 404 image throws (controller then serves placeholder)', function () use ($runner, $fetchOrExplain) {
            try {
                $fetchOrExplain('https://flowone.pro/flowone-test-definitely-missing-' . time() . '.png');
            } catch (\RuntimeException $e) {
                $runner->assertTrue(
                    str_contains($e->getMessage(), 'HTTP') || str_contains($e->getMessage(), 'content type'),
                    'unexpected error: ' . $e->getMessage()
                );
                return;
            }
            throw new \RuntimeException('404 fetch did not throw');
        }, 20);

        $runner->test('non-image content type is rejected', function () use ($runner, $fetchOrExplain) {
            try {
                $fetchOrExplain('https://www.google.com/');
            } catch (\RuntimeException $e) {
                $runner->assertTrue(
                    str_contains($e->getMessage(), 'content type') || str_contains($e->getMessage(), 'HTTP'),
                    'unexpected error: ' . $e->getMessage()
                );
                return;
            }
            throw new \RuntimeException('HTML response was not rejected');
        }, 20);
    }
}

exit($runner->finish());
