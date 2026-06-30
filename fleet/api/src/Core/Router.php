<?php

namespace FleetManager\Api\Core;

use FleetManager\Api\Services\AuthService;

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
                $authService = $this->container->get(AuthService::class);
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
                
            case 'agent':
                // Authenticate fleet agent by token header
                $agentToken = $request->getHeader('X-Agent-Token');
                
                if (!$agentToken) {
                    return Response::unauthorized('No agent token provided');
                }
                
                $db = $this->container->getDatabase();
                $stmt = $db->prepare("SELECT id, name, status FROM servers WHERE agent_token = ?");
                $stmt->execute([$agentToken]);
                $server = $stmt->fetch();
                
                if (!$server) {
                    return Response::unauthorized('Invalid agent token');
                }
                
                // Store server info for controllers
                $this->container->set('current_server', (object)$server);
                break;
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
            // Pass URL params as additional arguments after $request
            $params = array_values($request->getParams());
            return $controller->$method($request, ...$params);
        } catch (\Throwable $e) {
            error_log("Controller error: " . $e->getMessage());
            
            if ($this->container->getConfig('app.debug')) {
                return Response::error($e->getMessage(), 500);
            }
            
            return Response::error('Internal server error', 500);
        }
    }
}

