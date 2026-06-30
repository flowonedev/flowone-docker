<?php

namespace Webmail\Services;

/**
 * LinkPreviewService - Fetch Open Graph / meta data for URL previews in chat
 * 
 * Extracts:
 * - og:title, og:description, og:image
 * - Twitter card data as fallback
 * - Favicon
 * - Basic page title/description from meta tags
 */
class LinkPreviewService
{
    private \PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->db = \Webmail\Core\Database::getConnection($config);

        $this->ensureLinkPreviewTable();
    }

    /**
     * Self-healing: ensure link_previews cache table exists
     */
    private function ensureLinkPreviewTable(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'chat_link_previews'");
            if ($result->rowCount() === 0) {
                $this->db->exec("
                    CREATE TABLE chat_link_previews (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        url VARCHAR(2000) NOT NULL,
                        url_hash VARCHAR(64) NOT NULL UNIQUE,
                        title VARCHAR(500) DEFAULT NULL,
                        description TEXT DEFAULT NULL,
                        image_url VARCHAR(2000) DEFAULT NULL,
                        favicon_url VARCHAR(2000) DEFAULT NULL,
                        site_name VARCHAR(255) DEFAULT NULL,
                        fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_url_hash (url_hash)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                error_log("LinkPreviewService: Created chat_link_previews table");
            }
        } catch (\PDOException $e) {
            error_log("LinkPreviewService: ensureLinkPreviewTable failed: " . $e->getMessage());
        }
    }

    /**
     * Get preview data for a URL (cached or freshly fetched)
     */
    public function getPreview(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid URL'];
        }

        $urlHash = hash('sha256', $url);

        // Check cache (valid for 24 hours)
        try {
            $stmt = $this->db->prepare('
                SELECT * FROM chat_link_previews 
                WHERE url_hash = ? AND fetched_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ');
            $stmt->execute([$urlHash]);
            $cached = $stmt->fetch();

            if ($cached) {
                return [
                    'success' => true,
                    'preview' => [
                        'url' => $cached['url'],
                        'title' => $cached['title'],
                        'description' => $cached['description'],
                        'image' => $cached['image_url'],
                        'favicon' => $cached['favicon_url'],
                        'site_name' => $cached['site_name'],
                    ]
                ];
            }
        } catch (\PDOException $e) {
            // Continue to fetch
        }

        // Fetch fresh data
        $preview = $this->fetchPreview($url);
        if (!$preview) {
            return ['success' => false, 'error' => 'Could not fetch preview'];
        }

        // Cache the result
        try {
            $stmt = $this->db->prepare('
                INSERT INTO chat_link_previews (url, url_hash, title, description, image_url, favicon_url, site_name, fetched_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    description = VALUES(description),
                    image_url = VALUES(image_url),
                    favicon_url = VALUES(favicon_url),
                    site_name = VALUES(site_name),
                    fetched_at = NOW()
            ');
            $stmt->execute([
                $url,
                $urlHash,
                $preview['title'],
                $preview['description'],
                $preview['image'],
                $preview['favicon'],
                $preview['site_name'],
            ]);
        } catch (\PDOException $e) {
            // Non-fatal, preview still works
            error_log("LinkPreviewService: cache write failed: " . $e->getMessage());
        }

        return ['success' => true, 'preview' => $preview];
    }

    /**
     * Fetch Open Graph and meta data from a URL
     */
    private function fetchPreview(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: MailFlow/1.0 LinkPreview\r\nAccept: text/html\r\n",
                'timeout' => 5,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $html = @file_get_contents($url, false, $ctx, 0, 100000); // Max 100KB
        if (!$html) {
            return null;
        }

        // Parse meta tags
        $og = [];
        $twitter = [];
        $meta = [];

        // Extract <title>
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            $meta['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract meta tags
        if (preg_match_all('/<meta\s+([^>]+)\/?>/i', $html, $matches)) {
            foreach ($matches[1] as $attrs) {
                $property = '';
                $name = '';
                $content = '';

                if (preg_match('/property\s*=\s*["\']([^"\']+)["\']/i', $attrs, $m)) {
                    $property = strtolower($m[1]);
                }
                if (preg_match('/name\s*=\s*["\']([^"\']+)["\']/i', $attrs, $m)) {
                    $name = strtolower($m[1]);
                }
                if (preg_match('/content\s*=\s*["\']([^"\']*(?:[^"\'\\\\]|\\\\.)*)["\']/i', $attrs, $m)) {
                    $content = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                }

                // Open Graph
                if (str_starts_with($property, 'og:')) {
                    $og[str_replace('og:', '', $property)] = $content;
                }
                // Twitter Cards
                if (str_starts_with($name, 'twitter:') || str_starts_with($property, 'twitter:')) {
                    $key = str_replace('twitter:', '', $property ?: $name);
                    $twitter[$key] = $content;
                }
                // Standard meta
                if ($name === 'description') {
                    $meta['description'] = $content;
                }
            }
        }

        // Extract favicon
        $favicon = null;
        if (preg_match('/<link[^>]+rel\s*=\s*["\'](?:shortcut )?icon["\']\s+[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            $favicon = $m[1];
        } elseif (preg_match('/<link[^>]+href\s*=\s*["\']([^"\']+)["\']\s+[^>]*rel\s*=\s*["\'](?:shortcut )?icon["\']/i', $html, $m)) {
            $favicon = $m[1];
        }

        // Resolve relative URLs
        $parsed = parse_url($url);
        $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        $image = $og['image'] ?? $twitter['image'] ?? null;
        if ($image && !str_starts_with($image, 'http')) {
            $image = $baseUrl . '/' . ltrim($image, '/');
        }

        if ($favicon && !str_starts_with($favicon, 'http')) {
            $favicon = $baseUrl . '/' . ltrim($favicon, '/');
        }
        if (!$favicon) {
            $favicon = $baseUrl . '/favicon.ico';
        }

        $title = $og['title'] ?? $twitter['title'] ?? $meta['title'] ?? null;
        $description = $og['description'] ?? $twitter['description'] ?? $meta['description'] ?? null;

        if (!$title && !$description && !$image) {
            return null;
        }

        return [
            'url' => $url,
            'title' => $title ? mb_substr($title, 0, 500) : null,
            'description' => $description ? mb_substr($description, 0, 1000) : null,
            'image' => $image,
            'favicon' => $favicon,
            'site_name' => $og['site_name'] ?? ($parsed['host'] ?? null),
        ];
    }
}

