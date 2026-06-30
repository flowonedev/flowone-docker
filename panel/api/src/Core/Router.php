<?php

namespace VpsAdmin\Api\Core;

/**
 * Simple router with middleware support
 */
class Router
{
    private Container $container;
    private array $routes = [];
    private array $groupStack = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function get(string $path, array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, array $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, array $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function addRoute(string $method, string $path, array $handler): void
    {
        // Apply group middleware
        $middleware = [];
        foreach ($this->groupStack as $group) {
            if (isset($group['middleware'])) {
                $middleware[] = $group['middleware'];
            }
        }

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $uri = $request->getUri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $uri);
            
            if ($params !== false) {
                $request->setParams($params);

                // Run middleware
                foreach ($route['middleware'] as $middleware) {
                    $response = $this->runMiddleware($middleware, $request);
                    if ($response !== null) {
                        return $response;
                    }
                }

                // Run handler
                return $this->runHandler($route['handler'], $request);
            }
        }

        return Response::notFound('Route not found');
    }

    private function matchPath(string $routePath, string $requestUri): array|false
    {
        // Convert route pattern to regex
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $requestUri, $matches)) {
            // Extract named parameters and URL-decode them
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = urldecode($value);
                }
            }
            return $params;
        }

        return false;
    }

    private function runMiddleware(string $name, Request $request): ?Response
    {
        switch ($name) {
            case 'auth':
                $authService = $this->container->get(\VpsAdmin\Api\Services\AuthService::class);
                $token = $request->getBearerToken();
                
                if (!$token) {
                    return Response::unauthorized('No token provided');
                }

                $user = $authService->validateToken($token);
                
                if (!$user) {
                    return Response::unauthorized('Invalid or expired token');
                }

                // Check if user is suspended
                $db = $this->container->getDatabase();
                $stmt = $db->prepare("SELECT status FROM admin_users WHERE id = ?");
                $stmt->execute([$user['sub']]);
                $status = $stmt->fetchColumn();
                
                if ($status === 'suspended') {
                    return Response::error('Account suspended', 403);
                }

                // Store user in container for controllers
                $this->container->set('current_user', (object)$user);
                break;
                
            case 'role:super_admin':
                try {
                    $user = $this->container->get('current_user');
                    if (!$user || ($user->role ?? 'user') !== 'super_admin') {
                        return Response::error('Access denied. Super admin required.', 403);
                    }
                } catch (\Exception $e) {
                    return Response::error('Access denied', 403);
                }
                break;

            case 'role:admin':
                try {
                    $user = $this->container->get('current_user');
                    $role = $user->role ?? 'user';
                    if (!$user || !in_array($role, ['admin', 'super_admin'])) {
                        return Response::error('Access denied. Admin required.', 403);
                    }
                } catch (\Exception $e) {
                    return Response::error('Access denied', 403);
                }
                break;

            case 'rate_limit':
                $response = $this->checkRateLimit($request);
                if ($response !== null) {
                    return $response;
                }
                break;
        }

        return null;
    }

    /**
     * Redis-based API rate limiting (token bucket per IP).
     * Config: rate_limit.enabled, rate_limit.requests_per_minute
     */
    private function checkRateLimit(Request $request): ?Response
    {
        $config = $this->container->getConfig('rate_limit') ?? [];
        if (empty($config['enabled'])) {
            return null;
        }

        $maxRequests = (int) ($config['requests_per_minute'] ?? 60);
        $window = 60; // 1-minute sliding window
        $ip = $request->getClientIp();
        $key = 'vps:rl:' . $ip;

        try {
            if (!extension_loaded('redis') || !class_exists('\Redis')) {
                return null; // silently skip if Redis unavailable
            }

            $redisConfig = $this->container->getConfig('redis') ?? [];
            $redis = new \Redis();
            $connected = @$redis->connect(
                $redisConfig['host'] ?? '127.0.0.1',
                $redisConfig['port'] ?? 6379,
                $redisConfig['timeout'] ?? 2.0
            );
            if (!$connected) {
                return null;
            }
            if (!empty($redisConfig['password'])) {
                $redis->auth($redisConfig['password']);
            }
            if (isset($redisConfig['database'])) {
                $redis->select((int) $redisConfig['database']);
            }

            // Increment counter; set TTL on first hit
            $current = $redis->incr($key);
            if ($current === 1) {
                $redis->expire($key, $window);
            }

            $remaining = max(0, $maxRequests - $current);
            $ttl = $redis->ttl($key);

            // Attach rate-limit headers (handled after dispatch if needed)
            header("X-RateLimit-Limit: $maxRequests");
            header("X-RateLimit-Remaining: $remaining");
            header("X-RateLimit-Reset: " . (time() + max(0, $ttl)));

            if ($current > $maxRequests) {
                return Response::error('Too many requests. Try again in ' . max(1, $ttl) . 's.', 429);
            }
        } catch (\Exception $e) {
            // Fail open – don't block requests if Redis is down
        }

        return null;
    }

    private function runHandler(array $handler, Request $request): Response
    {
        [$controllerClass, $method] = $handler;

        $controller = new $controllerClass($this->container);
        
        if (!method_exists($controller, $method)) {
            return Response::error("Method {$method} not found", 500);
        }

        try {
            return $controller->$method($request);
        } catch (\Throwable $e) {
            // Always log controller errors to PHP error log
            error_log("Controller error [{$method}]: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            debug_log("Controller error: " . $e->getMessage());
            
            if ($this->container->getConfig('app.debug')) {
                return Response::error($e->getMessage(), 500);
            }
            
            return Response::error('Internal server error', 500);
        }
    }
}

