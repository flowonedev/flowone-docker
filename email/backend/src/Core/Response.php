<?php

namespace Webmail\Core;

class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private $body;
    private bool $isRaw = false;

    public function __construct($body = null, int $statusCode = 200)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'application/json';
    }

    public static function success($data = null, string $message = 'Success'): self
    {
        return new self([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], 200);
    }

    public static function error(string $message, int $statusCode = 400, $errors = null): self
    {
        $body = [
            'success' => false,
            'message' => $message,
        ];
        
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        
        return new self($body, $statusCode);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, 401);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return self::error($message, 404);
    }

    public static function serverError(string $message = 'Internal server error'): self
    {
        return self::error($message, 500);
    }

    public static function badRequest(string $message = 'Bad request'): self
    {
        return self::error($message, 400);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::error($message, 403);
    }

    public static function tooManyRequests(string $message = 'Too many requests'): self
    {
        return self::error($message, 429);
    }
    
    /**
     * Return a custom JSON response
     */
    public static function json(array $body, int $statusCode = 200): self
    {
        return new self($body, $statusCode);
    }
    
    /**
     * Return a raw (non-JSON) response with custom headers
     */
    public static function raw(string $body, int $statusCode = 200, array $headers = []): self
    {
        $response = new self(null, $statusCode);
        $response->body = $body;
        $response->isRaw = true;
        $response->headers = $headers;
        return $response;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Read accessors used by test harnesses + middleware. The HTTP
     * pipeline itself uses send() which writes directly to output.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContent(): string
    {
        if ($this->isRaw) {
            return is_string($this->body) ? $this->body : '';
        }
        if ($this->body === null) return '';
        return (string) json_encode(
            $this->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        // Raw responses bypass JSON encoding
        if ($this->isRaw) {
            if ($this->body !== null) {
                echo $this->body;
            }
            $this->closeClientConnection();
            return;
        }
        
        if ($this->body !== null) {
            $json = json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            
            if ($json === false) {
                error_log("JSON encode error: " . json_last_error_msg());
                // Fallback: try to sanitize and re-encode
                $sanitized = $this->sanitizeForJson($this->body);
                $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                
                if ($json === false) {
                    error_log("JSON encode still failed after sanitization");
                    echo json_encode(['success' => false, 'message' => 'JSON encoding error']);
                    $this->closeClientConnection();
                    return;
                }
            }
            
            echo $json;
        }

        $this->closeClientConnection();
    }

    /**
     * Flush the response body and close the client TCP connection so any
     * post-response work (deferred jobs, audit log flush, mention parser)
     * does not block the browser. Under PHP-FPM/LSAPI this is a single
     * sub-millisecond call. Falls back to a flush() pair on the rare
     * SAPI without fastcgi_finish_request (CLI tests, php-cgi).
     *
     * After this returns:
     *   - the client has the full response and the TCP connection is
     *     closed (or will be closed by the next idle check);
     *   - ignore_user_abort(true) keeps PHP running so deferred work
     *     finishes regardless;
     *   - any echo / header() call from here on is silently dropped.
     */
    private function closeClientConnection(): void
    {
        if (function_exists('ignore_user_abort')) {
            // Keep the worker running even though the client connection
            // is about to be torn down — otherwise deferred work gets
            // killed mid-flight when the browser drops the socket.
            ignore_user_abort(true);
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            return;
        }
        // CLI / php-cgi fallback — best effort, won't help under PHP-FPM
        // but at least pushes the body out of any output buffers.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
    
    /**
     * Recursively sanitize data for JSON encoding
     */
    private function sanitizeForJson($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeForJson'], $data);
        }
        
        if (is_string($data)) {
            // Remove invalid UTF-8 sequences
            $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            // Also try iconv as fallback
            if (!mb_check_encoding($data, 'UTF-8')) {
                $data = iconv('UTF-8', 'UTF-8//IGNORE', $data);
            }
            return $data;
        }
        
        return $data;
    }
}

