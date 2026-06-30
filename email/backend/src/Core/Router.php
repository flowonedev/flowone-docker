<?php

namespace Webmail\Core;

class Router
{
    private array $routes = [];
    private array $middleware = [];

    public function get(string $path, callable $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function head(string $path, callable $handler): self
    {
        return $this->addRoute('HEAD', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    public function patch(string $path, callable $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): self
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'pattern' => $this->pathToPattern($path),
        ];
        return $this;
    }

    public function addMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    private function pathToPattern(string $path): string
    {
        // Convert {param:pattern} to named capture groups with custom pattern
        $pattern = preg_replace_callback('/\{([a-zA-Z_]+)(?::([^}]+))?\}/', function($matches) {
            $name = $matches[1];
            $regex = $matches[2] ?? '[^/]+';
            return '(?P<' . $name . '>' . $regex . ')';
        }, $path);
        return '#^' . $pattern . '$#';
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();
        
        // Remove /api prefix if present
        $path = preg_replace('#^/api#', '', $path);
        if (empty($path)) {
            $path = '/';
        }

        // Decode percent-encoded path segments so folder names like [Gmail]/Bin work
        $path = rawurldecode($path);

        // Handle OPTIONS for CORS
        if ($method === 'OPTIONS') {
            return new Response(null, 204);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches)) {
                // Extract named parameters
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $request->setParam($key, urldecode($value));
                    }
                }

                // Run middleware
                foreach ($this->middleware as $middleware) {
                    $result = $middleware($request);
                    if ($result instanceof Response) {
                        return $result;
                    }
                }

                try {
                    $result = $route['handler']($request);
                    // If handler returns void/null (binary downloads use exit), return null
                    // index.php will check for Response instance before calling send()
                    if ($result === null) {
                        return null;
                    }
                    return $result;
                } catch (\Throwable $e) {
                    error_log("Router error: " . $e->getMessage());
                    return Response::serverError($e->getMessage());
                }
            }
        }

        return Response::notFound('Route not found');
    }
}

