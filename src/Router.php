<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;

/**
 * Minimal method + path router.
 *
 * Paths may contain {name} placeholders which match a single non-empty path
 * segment. An unknown path answers 404; a known path with an unsupported method
 * answers 405 with an Allow header.
 */
final class Router
{
    /**
     * @var list<array{method: string, path: string, regex: string, names: list<string>, handler: callable}>
     */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $names = [];
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $match) === 1) {
                $names[] = $match[1];
                $parts[] = '([^/]+)';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'regex' => '#^' . implode('/', $parts) . '$#',
            'names' => $names,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = strtoupper($request->method);
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['names'] as $index => $name) {
                $params[$name] = $matches[$index] ?? '';
            }

            return ($route['handler'])($request, $params);
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));
            $response = Response::error(405, 'method_not_allowed', 'Method Not Allowed');
            $response->headers['Allow'] = implode(', ', $allowed);

            return $response;
        }

        return Response::error(404, 'not_found', 'Not Found');
    }
}
