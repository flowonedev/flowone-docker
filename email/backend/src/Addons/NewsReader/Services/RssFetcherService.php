<?php

namespace Webmail\Addons\NewsReader\Services;

/**
 * HTTP fetch for RSS/Atom with conditional GET, redirect follow, and curl_multi batching.
 */
class RssFetcherService
{
    private const UA = 'FlowOne-NewsReader/1.0';
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 10;
    private const MAX_REDIRECTS = 3;

    /**
     * @return array{ok: bool, body: string, final_url: string, http_code: int, etag: ?string, last_modified: ?string, error: ?string}
     */
    public function fetchOne(
        string $url,
        ?string $ifNoneMatch = null,
        ?string $ifModifiedSince = null
    ): array {
        $ch = curl_init($url);
        $headers = ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*'];
        if ($ifNoneMatch) {
            $headers[] = 'If-None-Match: ' . $ifNoneMatch;
        }
        if ($ifModifiedSince) {
            $headers[] = 'If-Modified-Since: ' . $ifModifiedSince;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'body' => '', 'final_url' => $url, 'http_code' => 0, 'etag' => null, 'last_modified' => null, 'error' => $err ?: 'curl_exec failed', 'not_modified' => false];
        }
        return $this->splitResponse($raw, $url, $finalUrl, $code, $err, $headerSize);
    }

    /**
     * @param list<array{url: string, etag: ?string, modified: ?string, id: int|string}> $jobs
     * @return list<array{job_id: int|string, ok: bool, body: string, final_url: string, http_code: int, etag: ?string, last_modified: ?string, error: ?string}>
     */
    public function fetchMulti(array $jobs, int $concurrency = 12): array
    {
        $results = [];
        $chunks = array_chunk($jobs, max(1, $concurrency));
        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $job) {
                $url = $job['url'];
                $ch = curl_init($url);
                $headers = ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*'];
                if (!empty($job['etag'])) {
                    $headers[] = 'If-None-Match: ' . $job['etag'];
                }
                if (!empty($job['modified'])) {
                    $headers[] = 'If-Modified-Since: ' . $job['modified'];
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
                    CURLOPT_TIMEOUT => self::TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_USERAGENT => self::UA,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_HEADER => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $jobId = $job['id'];
                curl_multi_add_handle($mh, $ch);
                $handles[(int) $ch] = ['ch' => $ch, 'job_id' => $jobId, 'url' => $url];
            }
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running > 0);

            foreach ($handles as $meta) {
                $ch = $meta['ch'];
                $raw = curl_multi_getcontent($ch);
                $err = curl_error($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                if ($raw === false) {
                    $results[] = ['job_id' => $meta['job_id'], 'ok' => false, 'body' => '', 'final_url' => $meta['url'], 'http_code' => 0, 'etag' => null, 'last_modified' => null, 'error' => $err ?: 'curl failed', 'not_modified' => false];
                    continue;
                }
                $header = substr($raw, 0, $headerSize);
                $body = substr($raw, $headerSize);
                $etag = $this->headerValue($header, 'etag');
                $lastMod = $this->headerValue($header, 'last-modified');
                $notModified = $code === 304;
                $ok = $code >= 200 && $code < 300;
                $results[] = [
                    'job_id' => $meta['job_id'],
                    'ok' => $ok || $notModified,
                    'body' => $notModified ? '' : $body,
                    'final_url' => $finalUrl,
                    'http_code' => $code,
                    'etag' => $etag,
                    'last_modified' => $lastMod,
                    'error' => ($ok || $notModified) ? null : ($err ?: "HTTP $code"),
                    'not_modified' => $notModified,
                ];
            }
            curl_multi_close($mh);
        }

        return $results;
    }

    private function splitResponse(string $raw, string $requestUrl, string $finalUrl, int $code, string $err, int $headerSize = 0): array
    {
        if ($headerSize <= 0 && preg_match('/\r\n\r\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $headerSize = $m[0][1] + strlen($m[0][0]);
        }
        $header = $headerSize > 0 ? substr($raw, 0, $headerSize) : '';
        $body = $headerSize > 0 ? substr($raw, $headerSize) : $raw;
        $etag = $this->headerValue($header, 'etag');
        $lastMod = $this->headerValue($header, 'last-modified');
        $ok = $code >= 200 && $code < 300;
        $notModified = $code === 304;

        return [
            'ok' => $ok || $notModified,
            'body' => $notModified ? '' : $body,
            'final_url' => $finalUrl ?: $requestUrl,
            'http_code' => $code,
            'etag' => $etag,
            'last_modified' => $lastMod,
            'error' => ($ok || $notModified) ? null : ($err ?: "HTTP $code"),
            'not_modified' => $notModified,
        ];
    }

    private function headerValue(string $headerBlock, string $name): ?string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/im', $headerBlock, $m)) {
            return trim($m[1], " \t\"'");
        }

        return null;
    }
}
