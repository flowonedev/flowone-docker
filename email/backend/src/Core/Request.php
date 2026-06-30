<?php

namespace Webmail\Core;

class Request
{
    private array $query;
    private array $body;
    private array $headers;
    private array $params;
    private string $method;
    private string $path;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $this->query = $_GET;
        $this->headers = $this->parseHeaders();
        $this->body = $this->parseBody();
        $this->params = [];
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    private function parseBody(): array
    {
        // Check both CONTENT_TYPE and HTTP_CONTENT_TYPE (some servers use different keys)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        
        // Parse JSON from php://input for POST/PUT/PATCH/DELETE methods
        // DELETE with body is used by some APIs (e.g., clearing editing status)
        if (in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $rawInput = file_get_contents('php://input');
            
            // Try JSON decoding if content looks like JSON or content-type is JSON
            if (strpos($contentType, 'application/json') !== false || 
                (strlen($rawInput) > 0 && ($rawInput[0] === '{' || $rawInput[0] === '['))) {
                $decoded = json_decode($rawInput, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
            
            // Fall back to $_POST for form data
            if (!empty($_POST)) {
                return $_POST;
            }
        }
        
        return [];
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function input(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    /**
     * Get all request data merged: query string, body, and route params
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->params);
    }

    /**
     * Check if a key exists in the request body
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function getHeader(string $name, $default = null): ?string
    {
        // Headers are stored with dashes (e.g., USER-AGENT), so convert underscores to dashes
        $name = strtoupper(str_replace('_', '-', $name));
        return $this->headers[$name] ?? $default;
    }

    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('AUTHORIZATION');
        if ($auth && preg_match('/Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function setParam(string $key, $value): void
    {
        $this->params[$key] = $value;
    }

    public function getParam(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    public function getFile(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public function getFiles(string $key): array
    {
        if (!isset($_FILES[$key])) {
            return [];
        }
        
        $files = $_FILES[$key];
        if (!is_array($files['name'])) {
            return [$files];
        }
        
        $result = [];
        foreach ($files['name'] as $i => $name) {
            $result[] = [
                'name' => $name,
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ];
        }
        return $result;
    }
    
    /**
     * Get client IP address (handles proxies)
     */
    public function getClientIp(): string
    {
        // Check for proxy headers first
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy header
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_CLIENT_IP',            // Other proxies
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For can contain multiple IPs; take the first one
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        // Fall back to REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get route parameter (alias for getParam)
     */
    public function param(string $key, $default = null)
    {
        return $this->getParam($key, $default);
    }
}

