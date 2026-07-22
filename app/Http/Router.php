<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http;

final class Router
{
    /** @var array<string, array{0: class-string, 1: string}> */
    private array $routes = [];

    /**
     * @param class-string $controller
     */
    public function get(string $path, string $controller, string $method): void
    {
        $this->routes['GET ' . $path] = [$controller, $method];
    }

    /**
     * @param class-string $controller
     */
    public function post(string $path, string $controller, string $method): void
    {
        $this->routes['POST ' . $path] = [$controller, $method];
    }

    /**
     * @return array{0: class-string, 1: string, 2: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $path = $this->normalizePath($path);
        $key = strtoupper($method) . ' ' . $path;
        if (isset($this->routes[$key])) {
            return [$this->routes[$key][0], $this->routes[$key][1], []];
        }

        foreach ($this->routes as $routeKey => [$controller, $action]) {
            [$routeMethod, $routePath] = explode(' ', $routeKey, 2);
            if ($routeMethod !== strtoupper($method)) {
                continue;
            }
            $params = $this->matchPattern($routePath, $path);
            if ($params !== null) {
                return [$controller, $action, $params];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function matchPattern(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $k => $v) {
            if (is_string($k)) {
                $params[$k] = $v;
            }
        }

        return $params;
    }

    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }
}
