<?php

namespace ElektronNet\Payments\PayServer\Http;

/**
 * Minimal method+path router. `pay-server`'s API surface (section 5) is
 * small and stable enough that a full routing library is not worth the
 * dependency; every route is registered explicitly in public/index.php.
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, paramNames: array<int, string>, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $paramNames = [];
        $pattern = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function (array $m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => '#^' . $pattern . '$#',
            'paramNames' => $paramNames,
            'handler' => $handler,
        ];
    }

    /**
     * @throws ApiException 404 if no route matches
     */
    public function dispatch(Request $request): JsonResponse
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (!preg_match($route['pattern'], $request->path, $matches)) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['paramNames'] as $index => $name) {
                $params[$name] = $matches[$index] ?? '';
            }
            $request->params = $params;

            return ($route['handler'])($request);
        }

        throw ApiException::notFound("No route for {$request->method} {$request->path}.");
    }
}
