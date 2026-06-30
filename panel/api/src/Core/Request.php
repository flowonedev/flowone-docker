<?php

namespace VpsAdmin\Api\Core;

/**
 * HTTP Request wrapper
 */
class Request
{
    private string $method;
    private string $uri;
    private array $headers;
    private array $query;
    private array $body;
    private array $params = [];

    public function __construct(
        string $method,
        string $uri,
        array $headers = [],
        array $query = [],
        array $body = []
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->headers = $headers;
        $this->query = $query;
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Parse headers
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }
        
        // Content-Type header
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }

        // Parse body
        $body = [];
        $contentType = $headers['Content-Type'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = file_get_contents('php://input');
            if ($rawBody) {
                $body = json_decode($rawBody, true) ?? [];
            }
        } else {
            $body = $_POST;
        }

        return new self($method, $uri, $headers, $_GET, $body);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getQuery(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function getBody(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization');
        
        if ($auth && preg_match('/Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get client IP address.
     * Only trust X-Forwarded-For / X-Real-IP when behind a known reverse proxy.
     * The server must be configured to set REMOTE_ADDR correctly (e.g. via
     * OpenLiteSpeed useClientAddrForConnLimit or mod_remoteip).
     */
    public function getClientIp(): string
    {
        // Trust REMOTE_ADDR only — the reverse proxy (OLS / nginx) should
        // set this to the real client IP. X-Forwarded-For is trivially spoofable.
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->params);
    }

    /**
     * Check if a key exists in the request body or query
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }
}

