<?php

namespace FleetManager\Api\Core;

/**
 * HTTP Response wrapper
 */
class Response
{
    private int $statusCode;
    private array $headers;
    private mixed $body;
    private ?string $filePath = null;
    private ?string $fileContent = null;

    public function __construct(mixed $body = null, int $statusCode = 200, array $headers = [])
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function json(mixed $data, int $statusCode = 200): self
    {
        return new self($data, $statusCode, ['Content-Type' => 'application/json']);
    }

    public static function success(mixed $data = null, string $message = 'Success'): self
    {
        return self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public static function error(string $message, int $statusCode = 400, array $errors = []): self
    {
        $body = [
            'success' => false,
            'error' => $message,
        ];

        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        return self::json($body, $statusCode);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::error($message, 403);
    }

    public static function validationError(array $errors): self
    {
        return self::error('Validation failed', 422, $errors);
    }

    /**
     * Return a file download response
     */
    public static function file(string $path, string $filename, string $mimeType = 'application/octet-stream'): self
    {
        if (!file_exists($path)) {
            return self::notFound('File not found');
        }

        $response = new self(null, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string)filesize($path),
            'Cache-Control' => 'no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
        
        $response->filePath = $path;
        return $response;
    }

    /**
     * Return a file download response from content string
     */
    public static function fileContent(string $content, string $filename, string $mimeType = 'application/octet-stream'): self
    {
        $response = new self(null, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string)strlen($content),
            'Cache-Control' => 'no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
        
        $response->fileContent = $content;
        return $response;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Handle file downloads from path
        if ($this->filePath !== null && file_exists($this->filePath)) {
            readfile($this->filePath);
            return;
        }

        // Handle file downloads from content
        if ($this->fileContent !== null) {
            echo $this->fileContent;
            return;
        }

        if ($this->body !== null) {
            if (($this->headers['Content-Type'] ?? '') === 'application/json') {
                echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                echo $this->body;
            }
        }
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }
}

