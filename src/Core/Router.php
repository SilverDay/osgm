<?php declare(strict_types=1);

namespace OGM\Core;

/**
 * Simple path-based front-controller router.
 *
 * Route registration:
 *   $router->get('/path',       [Controller::class, 'method']);
 *   $router->post('/path',      [Controller::class, 'method']);
 *   $router->get('/path/{id}',  [Controller::class, 'method']);
 *
 * Named segments are captured and passed as additional arguments to the handler.
 */
class Router
{
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function any(string $pattern, callable|array $handler): void
    {
        $this->addRoute('*', $pattern, $handler);
    }

    /**
     * Dispatch the current request.
     * Returns false if no route matched (caller should render 404).
     */
    public function dispatch(Request $request, Response $response): bool
    {
        $method = $request->getMethod();
        $path   = rtrim($request->getPath(), '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== '*' && $route['method'] !== $method) {
                continue;
            }

            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }

            $handler = $route['handler'];

            if (is_array($handler)) {
                [$class, $method] = $handler;
                $instance = new $class();
                $instance->$method($request, $response, ...$params);
            } else {
                $handler($request, $response, ...$params);
            }

            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------

    private function addRoute(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Match a URL path against a pattern. Returns a list of captured segment
     * values on match, an empty array if the pattern matches with no segments,
     * or null on no match.
     *
     * Pattern syntax: /users/{uuid}/edit
     */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        // Build a regex from the pattern
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn($m) => '(?P<' . $m[1] . '>[^/]+)',
            $pattern
        );
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        // Return only the named captures, in order
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[] = $value;
            }
        }

        return $params;
    }
}
